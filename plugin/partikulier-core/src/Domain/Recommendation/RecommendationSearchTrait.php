<?php

declare(strict_types=1);

namespace Partikulier\Core\Domain\Recommendation;

trait RecommendationSearchTrait
{
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
}
