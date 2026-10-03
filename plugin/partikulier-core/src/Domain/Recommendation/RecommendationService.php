<?php
/**
 * Domaine recommandation data qualifiée (lot R1 — 03/10/2026).
 *
 * Deux tables neuves (2.8.0) : pk_search_events + pk_buyer_profiles.
 * Aucune donnée reprise, aucun numéro en clair, aucune route critique.
 *
 * - log_search_event() : appelé par le thème à chaque recherche filtrée
 *   (même anonyme : visitor_hash HMAC). filters_json canonique + signature
 *   SHA256(ksort) pour déduplication et agrégation rapide.
 * - rebuild_profile() : agrège pk_interest_events.snapshot (étendu R1) +
 *   pk_search_events + pk_buyer_preferences pour produire 1 ligne
 *   pk_buyer_profiles avec top_criteria (combinaisons qui se répètent).
 * - recommend_for_lead() : scoring SQL 0-100 (40% ville/quartier + 25% budget
 *   + 15% type + 10% étage + 10% ensoleillement) — baseline Senior DS,
 *   explicable, sans ML. À brancher sur pk_alert_deliveries si score>70
 *   ET consent similar_listings.
 *
 * Cron quotidien pk_recommendation_rebuild : rebuild tous les profils
 * modifiés depuis 24h + tous les nouveaux leads. Verrou 10 min.
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Recommendation;

final class RecommendationService
{
    public const CRON_HOOK = 'pk_recommendation_rebuild';
    private const LOCK_OPTION = 'pk_recommendation_rebuild_lock';

    public static function maybe_schedule(): void
    {
        if ( ! wp_next_scheduled(self::CRON_HOOK) ) {
            wp_schedule_event(time() + 3600, 'daily', self::CRON_HOOK);
        }
    }

    public static function unschedule(): void
    {
        $ts = wp_next_scheduled(self::CRON_HOOK);
        if ( $ts ) {
            wp_unschedule_event($ts, self::CRON_HOOK);
        }
    }

    /**
     * Log une recherche filtrée. Appelé par le thème (JS → REST /search-event)
     * ou directement via PHP. Idempotent par signature (même filtres = même ligne
     * dans la journée non dupliquée si même visitor).
     *
     * @param array<string, mixed> $filters ex: ['ville'=>'Casa','quartier'=>'Anfa','type'=>'Appartement','etage'=>'2','ensoleillement'=>'sud','budget_min'=>800000,'budget_max'=>1200000,'transaction'=>'vente']
     */
    public static function log_search_event( ?int $lead_id, string $visitor_hash, array $filters ): int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pk_search_events';
        $now   = gmdate('Y-m-d H:i:s');
        $visitor_hash = substr(preg_replace('/[^a-f0-9]/i', '', $visitor_hash) ?: hash('sha256', $visitor_hash), 0, 64);
        $visitor_hash = str_pad($visitor_hash, 64, '0');

        // Canonique : ksort + json + signature (même filtre, même hash)
        $canon = $filters;
        ksort($canon);
        // Normalise les strings (trim + lowercase ville/quartier/type pour l'agrégation)
        foreach ( ['ville','quartier','type','etage','ensoleillement','transaction'] as $k ) {
            if ( isset($canon[$k]) && is_string($canon[$k]) ) {
                $canon[$k] = trim((string) $canon[$k]);
            }
        }
        $json = wp_json_encode($canon, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ( ! is_string($json) ) {
            $json = '{}';
        }
        $sig  = hash('sha256', $json);

        // Déduplication douce : même visitor + même signature dans l'heure → on ne log pas
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM {$table} WHERE visitor_hash = %s AND filters_signature = %s AND created_at > DATE_SUB(%s, INTERVAL 1 HOUR) LIMIT 1",
            $visitor_hash, $sig, $now
        ));
        if ( $exists ) {
            return (int) $exists;
        }

        $res = $wpdb->insert($table, [
            'lead_id'           => $lead_id ? absint($lead_id) : null,
            'visitor_hash'      => $visitor_hash,
            'filters_json'      => $json,
            'filters_signature' => $sig,
            'created_at'        => $now,
        ], ['%d','%s','%s','%s','%s']);

        if ( false === $res ) {
            return 0;
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * Pseudonymise un identifiant visiteur (cookie, IP+UA) en HMAC 64.
     * Même technique que pk_buyer_leads.phone_hash (HMAC non réversible).
     */
    public static function visitor_hash_for( string $raw ): string
    {
        $salt = function_exists('wp_salt') ? (string) wp_salt('auth') : 'partikulier-visitor-salt';
        return hash_hmac('sha256', $raw, $salt);
    }

    /**
     * Reconstruit le profil qualifié d'un lead (1 ligne pk_buyer_profiles).
     * Agrège : interest_events (snapshot étendu R1) + search_events + buyer_preferences.
     * Top 3 combinaisons qui se répètent (ville/quartier/type/etage) avec COUNT.
     *
     * @return array{lead_id:int, repetitions:int, score:int, top:array}
     */
    public static function rebuild_profile( int $lead_id ): array
    {
        global $wpdb;
        $lead_id = absint($lead_id);
        if ( ! $lead_id ) {
            return ['lead_id'=>0,'repetitions'=>0,'score'=>0,'top'=>[]];
        }

        $interest_table = $wpdb->prefix . 'pk_interest_events';
        $search_table   = $wpdb->prefix . 'pk_search_events';
        $prefs_table    = $wpdb->prefix . 'pk_buyer_preferences';
        $profiles_table = $wpdb->prefix . 'pk_buyer_profiles';
        $now = gmdate('Y-m-d H:i:s');

        // 1) Récupère les snapshots (derniers 50 événements pour la fenêtre d'intérêt)
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT property_snapshot FROM {$interest_table} WHERE lead_id = %d ORDER BY created_at DESC LIMIT 50",
            $lead_id
        ), ARRAY_A);

        $prices = []; $villes = []; $quartiers = []; $types = []; $etages = []; $ensols = []; $areas = [];
        $combos = []; // "ville|quartier|type|etage" => count

        foreach ( (array) $rows as $r ) {
            $snap = json_decode((string) ($r['property_snapshot'] ?? ''), true);
            if ( ! is_array($snap) ) { continue; }
            $ville = trim((string) ($snap['ville'] ?? $snap['location'] ?? ''));
            // ville peut être "Casa, Anfa" — on prend le premier token
            if ( str_contains($ville, ',') ) { $ville = trim(explode(',', $ville)[0]); }
            $quartier = trim((string) ($snap['quartier'] ?? ''));
            $type = trim((string) ($snap['type'] ?? ''));
            $etage = trim((string) ($snap['etage'] ?? ''));
            $ensol = trim((string) ($snap['ensoleillement'] ?? ''));
            $price = (int) preg_replace('/[^0-9]/', '', (string) ($snap['price'] ?? '0'));
            $area  = (string) ($snap['area'] ?? '');

            if ( $ville ) { $villes[] = $ville; }
            if ( $quartier ) { $quartiers[] = $quartier; }
            if ( $type ) { $types[] = $type; }
            if ( $etage ) { $etages[] = $etage; }
            if ( $ensol ) { $ensols[] = $ensol; }
            if ( $area ) { $areas[] = $area; }
            if ( $price > 0 ) { $prices[] = $price; }

            $key = strtolower($ville.'|'.$quartier.'|'.$type.'|'.$etage);
            if ( $ville || $quartier || $type ) {
                $combos[$key] = ($combos[$key] ?? 0) + 1;
            }
        }

        // 2) Enrichit avec les recherches (même si pas encore de contact)
        $search_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT filters_json FROM {$search_table} WHERE lead_id = %d ORDER BY created_at DESC LIMIT 50",
            $lead_id
        ), ARRAY_A);
        // Si pas de lead_id, tente par visitor_hash lié aux interest_events? Non — on reste sur lead_id pour la V1.
        // Les recherches anonymes serviront plus tard pour le rattachement après identification WhatsApp.
        foreach ( (array) $search_rows as $r ) {
            $f = json_decode((string) ($r['filters_json'] ?? ''), true);
            if ( ! is_array($f) ) { continue; }
            if ( ! empty($f['ville']) ) { $villes[] = trim((string) $f['ville']); }
            if ( ! empty($f['quartier']) ) { $quartiers[] = trim((string) $f['quartier']); }
            if ( ! empty($f['type']) ) { $types[] = trim((string) $f['type']); }
            if ( ! empty($f['etage']) ) { $etages[] = trim((string) $f['etage']); }
            if ( ! empty($f['ensoleillement']) ) { $ensols[] = trim((string) $f['ensoleillement']); }
            if ( isset($f['budget_min']) && (int) $f['budget_min'] > 0 ) { $prices[] = (int) $f['budget_min']; }
            if ( isset($f['budget_max']) && (int) $f['budget_max'] > 0 ) { $prices[] = (int) $f['budget_max']; }
            $key = strtolower(trim((string)($f['ville']??'')).'|'.trim((string)($f['quartier']??'')).'|'.trim((string)($f['type']??'')).'|'.trim((string)($f['etage']??'')));
            if ( $key !== '|||' ) { $combos[$key] = ($combos[$key] ?? 0) + 0.5; } // poids 0.5 pour recherche vs 1 pour intérêt
        }

        // 3) Préférences déclarées (budget_max, areas, layout)
        $pref = $wpdb->get_row($wpdb->prepare("SELECT budget_max, areas, layout_value, transaction_value FROM {$prefs_table} WHERE lead_id = %d", $lead_id), ARRAY_A);
        if ( is_array($pref) && ! empty($pref['budget_max']) ) {
            $prices[] = (int) $pref['budget_max'];
        }

        // Déduplication et tri par fréquence
        $uniq = static function(array $arr): array {
            $lower = array_map(static fn($v) => trim((string) $v), $arr);
            $lower = array_filter($lower, static fn($v) => $v !== '');
            // Compte fréquence
            $counts = array_count_values(array_map('strtolower', $lower));
            arsort($counts);
            // Retourne les valeurs uniques triées par fréquence, en gardant la casse la plus fréquente
            $out = [];
            foreach ( $counts as $k => $c ) {
                // Retrouve la casse d'origine la plus fréquente
                foreach ( $lower as $orig ) {
                    if ( strtolower($orig) === $k ) { $out[] = $orig; break; }
                }
                if ( count($out) >= 5 ) break;
            }
            return array_values(array_unique($out));
        };

        $villes_u = $uniq($villes);
        $quartiers_u = $uniq($quartiers);
        $types_u = $uniq($types);
        $etages_u = $uniq($etages);
        $ensols_u = $uniq($ensols);
        $areas_u = $uniq($areas);

        // Budget
        $budget_min = null; $budget_max = null; $budget_median = null;
        if ( count($prices) > 0 ) {
            sort($prices);
            $budget_min = (int) $prices[0];
            $budget_max = (int) $prices[count($prices)-1];
            $mid = (int) floor(count($prices)/2);
            $budget_median = (int) $prices[$mid];
            // Si un seul budget déclaré très large, on garde ±15% autour de la médiane pour la reco
            if ( count($prices) < 3 && $budget_median > 0 ) {
                $budget_min = (int) ($budget_median * 0.85);
                $budget_max = (int) ($budget_median * 1.15);
            }
        }

        // Top 3 combinaisons qui se répètent
        arsort($combos);
        $top = [];
        $i = 0;
        foreach ( $combos as $key => $count ) {
            if ( $i >= 3 ) break;
            [$v,$q,$t,$e] = array_pad(explode('|', (string) $key), 4, '');
            $top[] = ['ville'=>$v,'quartier'=>$q,'type'=>$t,'etage'=>$e,'repetitions'=>(int) $count];
            $i++;
        }
        $repetitions = array_sum(array_map(static fn($t) => (int) $t['repetitions'], $top));
        // Score fidélité 0-100 : 2 répétitions = 40, 5 = 70, 10+ = 100
        $score = 0;
        if ( $repetitions >= 10 ) $score = 100;
        elseif ( $repetitions >= 5 ) $score = 70 + (int)(($repetitions-5)*6);
        elseif ( $repetitions >= 2 ) $score = 40 + (int)(($repetitions-2)*10);
        elseif ( $repetitions === 1 ) $score = 20;

        // last_interest_at
        $last_interest = $wpdb->get_var($wpdb->prepare("SELECT MAX(created_at) FROM {$interest_table} WHERE lead_id = %d", $lead_id));

        // Upsert profil
        $wpdb->replace($profiles_table, [
            'lead_id'           => $lead_id,
            'budget_min'        => $budget_min,
            'budget_max'        => $budget_max,
            'budget_median'     => $budget_median,
            'villes'            => wp_json_encode($villes_u, JSON_UNESCAPED_UNICODE),
            'quartiers'         => wp_json_encode($quartiers_u, JSON_UNESCAPED_UNICODE),
            'types'             => wp_json_encode($types_u, JSON_UNESCAPED_UNICODE),
            'etages'            => wp_json_encode($etages_u, JSON_UNESCAPED_UNICODE),
            'ensoleillements'   => wp_json_encode($ensols_u, JSON_UNESCAPED_UNICODE),
            'areas'             => wp_json_encode($areas_u, JSON_UNESCAPED_UNICODE),
            'top_criteria'      => wp_json_encode($top, JSON_UNESCAPED_UNICODE),
            'repetitions_count' => $repetitions,
            'score_fidelite'    => $score,
            'last_interest_at'  => $last_interest ?: null,
            'updated_at'        => $now,
        ], ['%d','%d','%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s']);

        return ['lead_id'=>$lead_id,'repetitions'=>$repetitions,'score'=>$score,'top'=>$top];
    }

    /**
     * Rebuild tous les profils modifiés depuis 24h (cron) + nouveaux leads.
     * Verrou 10 min pour éviter les runs parallèles (web + cron).
     *
     * @return array{rebuilt:int, skipped:int}
     */
    public static function rebuild_all_due(): array
    {
        if ( get_transient(self::LOCK_OPTION) ) {
            return ['rebuilt'=>0,'skipped'=>0];
        }
        set_transient(self::LOCK_OPTION, '1', 600);
        global $wpdb;
        $interest_table = $wpdb->prefix . 'pk_interest_events';
        $profiles_table = $wpdb->prefix . 'pk_buyer_profiles';
        // Leads ayant eu un intérêt dans les 7 jours OU profil manquant
        $leads = $wpdb->get_col(
            "SELECT DISTINCT lead_id FROM {$interest_table} WHERE created_at > DATE_SUB(NOW(), INTERVAL 7 DAY) LIMIT 200"
        );
        $rebuilt = 0;
        foreach ( (array) $leads as $lid ) {
            self::rebuild_profile((int) $lid);
            $rebuilt++;
            if ( $rebuilt >= 100 ) break; // batch max 100 / run
        }
        delete_transient(self::LOCK_OPTION);
        return ['rebuilt'=>$rebuilt,'skipped'=>0];
    }

    /**
     * Score 0-100 pour recommander des annonces à un lead.
     * Baseline Senior DS : 40% ville/quartier + 25% budget + 15% type + 10% étage + 10% ensoleillement.
     * Ne propose jamais un bien déjà disclosed.
     *
     * @return array<int, array{id:int, score:int, price:string, title:string}>
     */
    public static function recommend_for_lead( int $lead_id, int $limit = 5 ): array
    {
        global $wpdb;
        $lead_id = absint($lead_id);
        if ( ! $lead_id ) return [];
        $limit = max(1, min(10, $limit));
        $profiles_table = $wpdb->prefix . 'pk_buyer_profiles';
        $listings_table = $wpdb->prefix . 'pk_listings';
        $disclosures_table = $wpdb->prefix . 'pk_contact_disclosures';

        $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profiles_table} WHERE lead_id = %d", $lead_id), ARRAY_A);
        if ( ! is_array($profile) ) {
            // Pas de profil → rebuild à la volée
            self::rebuild_profile($lead_id);
            $profile = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$profiles_table} WHERE lead_id = %d", $lead_id), ARRAY_A);
            if ( ! is_array($profile) ) return [];
        }

        $villes = json_decode((string) ($profile['villes'] ?? '[]'), true) ?: [];
        $quartiers = json_decode((string) ($profile['quartiers'] ?? '[]'), true) ?: [];
        $types = json_decode((string) ($profile['types'] ?? '[]'), true) ?: [];
        $etages = json_decode((string) ($profile['etages'] ?? '[]'), true) ?: [];
        $budget_min = $profile['budget_min'] ? (int) $profile['budget_min'] : 0;
        $budget_max = $profile['budget_max'] ? (int) $profile['budget_max'] : 0;

        // Si profil vide (score 0), on ne recommande rien — on évite le spam.
        if ( (int) ($profile['score_fidelite'] ?? 0) < 20 ) {
            return [];
        }

        // Récupère les annonces publiées récentes (100 max) non déjà disclosed
        $candidates = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT l.id, l.title, l.price, l.external_id
                 FROM {$listings_table} l
                 WHERE l.status = 'publish'
                 AND l.id NOT IN (SELECT property_id FROM {$disclosures_table} WHERE lead_id = %d)
                 ORDER BY l.updated_at DESC LIMIT 100",
                $lead_id
            ),
            ARRAY_A
        );

        $scored = [];
        foreach ( (array) $candidates as $row ) {
            $pid = (int) $row['id'];
            // On relit le snapshot qualifié via le post_meta (même logique que property_snapshot)
            $ville = trim((string) get_post_meta($pid, '_pk_ville', true)) ?: self::ville_for_property($pid);
            $quartier = trim((string) get_post_meta($pid, '_pk_quartier', true)) ?: self::quartier_for_property($pid);
            $type = trim((string) get_post_meta($pid, '_pk_type', true)) ?: self::type_for_property($pid);
            $etage = trim((string) (get_post_meta($pid, '_pk_etage', true) ?: get_post_meta($pid, 'es_property_floor', true)));
            $price = (int) preg_replace('/[^0-9]/', '', (string) $row['price']);

            $score = 0;
            // 40% ville/quartier
            $ville_match = in_array(strtolower($ville), array_map('strtolower', (array) $villes), true);
            $quartier_match = in_array(strtolower($quartier), array_map('strtolower', (array) $quartiers), true);
            if ( $ville_match && $quartier_match ) $score += 40;
            elseif ( $ville_match ) $score += 20;
            elseif ( $quartier_match ) $score += 15;

            // 25% budget
            if ( $budget_min > 0 && $budget_max > 0 && $price > 0 ) {
                if ( $price >= $budget_min && $price <= $budget_max ) $score += 25;
                elseif ( $price >= $budget_min * 0.85 && $price <= $budget_max * 1.15 ) $score += 12; // proche
            } elseif ( $budget_max > 0 && $price > 0 && $price <= $budget_max * 1.15 ) {
                $score += 10;
            }

            // 15% type
            if ( $type && in_array(strtolower($type), array_map('strtolower', (array) $types), true) ) $score += 15;

            // 10% étage
            if ( $etage && in_array(strtolower($etage), array_map('strtolower', (array) $etages), true) ) $score += 10;

            // Bonus fidélité : si top_criteria contient cette combinaison exacte, +10
            $top = json_decode((string) ($profile['top_criteria'] ?? '[]'), true) ?: [];
            foreach ( $top as $t ) {
                if ( strtolower((string)($t['ville'] ?? '')) === strtolower($ville) && strtolower((string)($t['quartier'] ?? '')) === strtolower($quartier) ) {
                    $score = min(100, $score + 10);
                    break;
                }
            }

            if ( $score >= 50 ) { // seuil qualifié — en dessous on ne propose pas
                $scored[] = ['id'=>$pid,'score'=>$score,'price'=>(string) $row['price'],'title'=>(string) $row['title']];
            }
        }

        usort($scored, static fn($a,$b) => $b['score'] <=> $a['score']);
        return array_slice($scored, 0, $limit);
    }

    // Helpers pour le scoring sans LeadService (évite dépendance circulaire)
    private static function ville_for_property( int $pid ): string
    {
        $terms = get_the_terms($pid, 'es_location');
        if ( ! is_array($terms) || empty($terms) ) return '';
        usort($terms, static fn($a,$b) => (int)$a->parent <=> (int)$b->parent);
        return (string) ($terms[0]->name ?? '');
    }
    private static function quartier_for_property( int $pid ): string
    {
        $terms = get_the_terms($pid, 'es_location');
        if ( ! is_array($terms) || empty($terms) ) return '';
        usort($terms, static fn($a,$b) => (int)$a->parent <=> (int)$b->parent);
        $last = $terms[count($terms)-1];
        return (string) ($last->name ?? '');
    }
    private static function type_for_property( int $pid ): string
    {
        $terms = get_the_terms($pid, 'es_type');
        if ( is_array($terms) && ! empty($terms) ) return (string) ($terms[0]->name ?? '');
        $terms = get_the_terms($pid, 'es_property_type');
        if ( is_array($terms) && ! empty($terms) ) return (string) ($terms[0]->name ?? '');
        return '';
    }
}
