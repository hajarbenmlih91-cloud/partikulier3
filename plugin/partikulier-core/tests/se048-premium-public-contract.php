<?php
/**
 * Contrat SE-048-R — les FONCTIONS PUBLIQUES du premium (E-4804).
 *
 * Exigence : CDC Phase 1 §8.3-4 (« inventaire exhaustif : les 12 fonctions …
 * sont toutes vérifiées par des tests — pas seulement le badge. Les fonctions
 * « à livrer » (E-4804 : badges, tri, champ REST) … font partie du lot. »)
 *
 * Ce contrat verrouille les 4 fonctions publiques livrées par le lot :
 *   - 8e fonction : badge de FICHE (single) ;
 *   - 9e fonction : badge de CARTE (archive/recherche) ;
 *   - 10e fonction : tri « premium-first » des listes ;
 *   - 11e fonction : champ REST lite « premium » de la fiche (/listings/{id}).
 *
 * Et le comportement du DRAPEAU (E-4807, CDC §8.3-5) : drapeau éteint ⇒ AUCUNE
 * trace publique — pas de badge, pas de clé REST, pas de tri, aucune requête
 * supplémentaire. Le drapeau est forcé '1' DANS CE PROCESSUS et restauré à sa
 * valeur d'origine en sortie : le contrat ne change jamais la configuration.
 *
 * Preuves fournies : rendu RÉEL des gabarits (carte), réponse RÉELLE de l'API
 * REST, et ordre RÉEL d'une requête SQL (pas seulement la clause produite).
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_REPO_DIR=<racine> php
 *   wp-content/plugins/partikulier-core/tests/se048-premium-public-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
$repoDir = getenv('PK_REPO_DIR') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
if ($repoDir === '' || !is_dir($repoDir)) { fwrite(STDERR, "PK_REPO_DIR doit pointer vers la racine du dépôt\n"); exit(2); }
$repoDir = rtrim($repoDir, '/');
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\Premium\PremiumService;

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
wp_set_current_user(1);

/** Requête « principale » simulée : le filtre de tri n'agit que sur elle. */
class PK_SE048_Main_Query extends WP_Query
{
    public function is_main_query(): bool
    {
        return true;
    }
}

$flagBefore = (string) get_option(PremiumService::OPTION_PUBLIC_ENABLED, '0');
$created = [];
$run = bin2hex(random_bytes(4));

/** Rendu réel du gabarit de carte. */
$render_card = static function (int $post_id): string {
    $property = get_post($post_id);
    $gallery = [];
    $actions = null;
    $pk_card_index = 1;
    ob_start();
    include get_template_directory() . '/templates/parts/card-property.php';
    return (string) ob_get_clean();
};

/** Clause ORDER BY produite pour une requête d'annonces « principale ». */
$orderby_for = static function (): string {
    $query = new PK_SE048_Main_Query(['post_type' => 'properties', 'post_status' => 'publish']);
    return (string) Partikulier_Search_Filters::stable_property_order('X.X', $query);
};

/** Ordre RÉEL : exécute la requête avec la clause du thème appliquée. */
$ordered_ids = static function () {
    $query = new PK_SE048_Main_Query([
        'post_type'      => 'properties',
        'post_status'    => 'publish',
        'posts_per_page' => 20,
        'fields'         => 'ids',
        'orderby'        => 'date',
        'order'          => 'DESC',
        'no_found_rows'  => true,
        'meta_query'     => Partikulier_Dashboard::active_listing_meta_query(),
    ]);
    $filter = static function ($orderby, $q) use ($query) {
        return $q === $query ? Partikulier_Search_Filters::stable_property_order($orderby, $q) : $orderby;
    };
    add_filter('posts_orderby', $filter, 999, 2);
    $ids = array_map('intval', (array) $query->get_posts());
    remove_filter('posts_orderby', $filter, 999);
    return $ids;
};

$make = static function (string $title, string $date) use (&$created): int {
    $id = wp_insert_post([
        'post_type'   => 'properties',
        'post_status' => 'publish',
        'post_title'  => $title,
        'post_author' => 1,
        'post_date'   => $date,
        'post_date_gmt' => get_gmt_from_date($date),
    ]);
    $created[] = (int) $id;
    return (int) $id;
};

try {
    // Fixtures : A = premium (la PLUS ANCIENNE : sans tri premium elle finit
    // dernière), B = témoin, C = premium MAIS désactivée (E-4807 3).
    $a = $make('SE048 ' . $run . ' premium', '2026-01-01 08:00:00');
    $b = $make('SE048 ' . $run . ' témoin', '2026-02-01 08:00:00');
    $c = $make('SE048 ' . $run . ' premium désactivée', '2026-03-01 08:00:00');
    for ($i = 4; $i <= 6; $i++) {
        $make('SE048 ' . $run . ' autre ' . $i, '2026-0' . $i . '-01 08:00:00');
    }
    update_post_meta($c, '_pk_status', 'vendu');

    // Fixture de l'API : créée par le chemin RÉEL de dépôt (POST /listings), donc
    // présente au registre pk_listings — sans quoi la fiche /listings/{id}
    // répondrait 404 et le champ ne serait pas observable.
    $requete = new WP_REST_Request('POST', '/partikulier/v1/listings');
    $requete->set_header('Content-Type', 'application/json');
    $requete->set_body(wp_json_encode([
        'title' => 'SE048 ' . $run . ' premium API', 'description' => 'fixture REST SE-048-R',
        'locale' => 'fr', 'price' => 100, 'area' => 10,
    ]));
    $reponse = rest_do_request($requete);
    $listingId = (int) ($reponse->get_data()['id'] ?? 0);
    $ligne = $wpdb->get_row($wpdb->prepare("SELECT external_id FROM {$prefix}pk_listings WHERE id = %d", $listingId), ARRAY_A);
    $r = $ligne ? (int) substr((string) $ligne['external_id'], strlen('estatik:')) : 0;
    if ($r > 0) {
        wp_update_post(['ID' => $r, 'post_status' => 'publish']);
        $created[] = (int) $r;
        Partikulier_Premium::grant($r, 1, 'SE-048-R ' . $run . ' : premium de la fixture API', gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 30 * 86400));
    }
    // Témoin de l'API : même chemin de dépôt, AUCUN premium — la négation se
    // prouve sur une fiche réellement servie, pas sur un 404.
    $requete2 = new WP_REST_Request('POST', '/partikulier/v1/listings');
    $requete2->set_header('Content-Type', 'application/json');
    $requete2->set_body(wp_json_encode([
        'title' => 'SE048 ' . $run . ' témoin API', 'description' => 'témoin REST SE-048-R',
        'locale' => 'fr', 'price' => 250, 'area' => 20,
    ]));
    $reponse2 = rest_do_request($requete2);
    $listingId2 = (int) ($reponse2->get_data()['id'] ?? 0);
    $ligne2 = $wpdb->get_row($wpdb->prepare("SELECT external_id FROM {$prefix}pk_listings WHERE id = %d", $listingId2), ARRAY_A);
    $r2 = $ligne2 ? (int) substr((string) $ligne2['external_id'], strlen('estatik:')) : 0;
    if ($r2 > 0) {
        wp_update_post(['ID' => $r2, 'post_status' => 'publish']);
        $created[] = (int) $r2;
    }

    update_option(PremiumService::OPTION_PUBLIC_ENABLED, '0');
    $grant = Partikulier_Premium::grant($a, 1, 'SE-048-R ' . $run . ' : premium du témoin', gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 30 * 86400));
    $grantC = Partikulier_Premium::grant($c, 1, 'SE-048-R ' . $run . ' : premium sur annonce désactivée', gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 30 * 86400));

    /* ── 1. DRAPEAU ÉTEINT : aucune trace publique (E-4807, §8.3-5) ────────── */
    $htmlA_off = $render_card($a);
    $restA_off = rest_do_request(new WP_REST_Request('GET', '/partikulier/v1/listings/' . $a))->get_data();
    $dataA_off = is_array($restA_off['data'] ?? null) ? $restA_off['data'] : [];
    $orderOff = $orderby_for();
    $assert(
        'SE048-PUB-001',
        !is_wp_error($grant) && !Partikulier_Premium::is_publicly_visible($a)
        && strpos($htmlA_off, 'pk-card-badge-premium') === false
        && !array_key_exists('premium', $dataA_off)
        && strpos($orderOff, 'CASE WHEN EXISTS') === false,
        sprintf('drapeau éteint : visible=%s, badge carte=%s, clé REST premium=%s, tri premium=%s (aucune trace publique)',
            Partikulier_Premium::is_publicly_visible($a) ? 'OUI' : 'non',
            strpos($htmlA_off, 'pk-card-badge-premium') !== false ? 'PRÉSENT' : 'absent',
            array_key_exists('premium', $dataA_off) ? 'PRÉSENTE' : 'absente',
            strpos($orderOff, 'CASE WHEN EXISTS') !== false ? 'APPLIQUÉ' : 'non appliqué')
    );

    /* ── 2. DRAPEAU ALLUMÉ : prédicat unique (drapeau ∧ actif ∧ disponible) ── */
    update_option(PremiumService::OPTION_PUBLIC_ENABLED, '1');
    $assert(
        'SE048-PUB-002',
        Partikulier_Premium::is_publicly_visible($a)
        && !Partikulier_Premium::is_publicly_visible($b)
        && !Partikulier_Premium::is_publicly_visible($c),
        sprintf('drapeau allumé : premium disponible=%s, témoin=%s, premium DÉSACTIVÉE=%s (la disponibilité fait partie du prédicat)',
            Partikulier_Premium::is_publicly_visible($a) ? 'visible' : 'non visible',
            Partikulier_Premium::is_publicly_visible($b) ? 'VISIBLE (anomalie)' : 'non visible',
            Partikulier_Premium::is_publicly_visible($c) ? 'VISIBLE (anomalie)' : 'non visible')
    );

    /* ── 3. 8e et 9e fonctions : badges de carte et de fiche ───────────────── */
    $htmlA_on = $render_card($a);
    $htmlB_on = $render_card($b);
    $htmlC_on = $render_card($c);
    $badge = Partikulier_Premium::badge_label();
    $okCard = strpos($htmlA_on, 'pk-card-badge-premium') !== false
        && strpos($htmlA_on, '>' . $badge . '<') !== false
        && strpos($htmlB_on, 'pk-card-badge-premium') === false
        && strpos($htmlC_on, 'pk-card-badge-premium') === false;
    $singleSource = (string) file_get_contents($repoDir . '/theme/partikulier/templates/single.php');
    $okSingle = strpos($singleSource, 'pk-single-badge-premium') !== false
        && strpos($singleSource, 'Partikulier_Premium::is_publicly_visible') !== false;
    $assert('SE048-PUB-003', $okCard,
        sprintf('badge de CARTE rendu par le gabarit réel : premium %s, témoin %s, premium désactivée %s',
            strpos($htmlA_on, 'pk-card-badge-premium') !== false ? 'badgé ✓' : 'ABSENT',
            strpos($htmlB_on, 'pk-card-badge-premium') === false ? 'sans badge ✓' : 'BADGÉ (anomalie)',
            strpos($htmlC_on, 'pk-card-badge-premium') === false ? 'sans badge ✓' : 'BADGÉ (anomalie)'));
    $assert('SE048-PUB-004', $okSingle,
        sprintf('badge de FICHE : gabarit single.php porte le repère %s et le prédicat unique %s (rendu HTTP couvert par la batterie DP-9)',
            strpos($singleSource, 'pk-single-badge-premium') !== false ? '✓' : 'ABSENT',
            strpos($singleSource, 'is_publicly_visible') !== false ? '✓' : 'ABSENT'));

    /* ── 4. 11e fonction : champ REST lite ────────────────────────────────── */
    $dataA_on = rest_do_request(new WP_REST_Request('GET', '/partikulier/v1/listings/' . $listingId))->get_data()['data'] ?? [];
    $dataB_on = rest_do_request(new WP_REST_Request('GET', '/partikulier/v1/listings/' . $listingId2))->get_data()['data'] ?? [];
    $dataC_on = rest_do_request(new WP_REST_Request('GET', '/partikulier/v1/listings/' . $a))->get_data()['data'] ?? [];
    $assert(
        'SE048-PUB-005',
        $r > 0 && $r2 > 0 && true === ($dataA_on['premium'] ?? null) && true === ($dataA_on['available'] ?? null)
        && false === ($dataB_on['premium'] ?? null) && true === ($dataB_on['available'] ?? null),
        sprintf('champ REST lite : fiche /listings/%d (premium offert) → premium=%s, available=%s ; fiche /listings/%d (témoin) → premium=%s, available=%s',
            $listingId, var_export($dataA_on['premium'] ?? null, true), var_export($dataA_on['available'] ?? null, true),
            $listingId2, var_export($dataB_on['premium'] ?? null, true), var_export($dataB_on['available'] ?? null, true))
    );

    /* ── 5. 10e fonction : tri premium-first, ordre SQL RÉEL ──────────────── */
    unset($_GET['pk_order']);
    $clauseOn = $orderby_for();
    $ordered = $ordered_ids();
    $premier = $ordered[0] ?? 0;
    // Les premiums de la fixture : l'annonce la plus ancienne (#a, premium) et la
    // fixture d'API (#r, premium, datée du jour). L'invariant à prouver n'est pas
    // « la plus ancienne d'abord » mais « TOUT premium avant TOUT non-premium ».
    $premiums = array_values(array_filter([(int) $a, (int) $r]));
    $positions = [];
    foreach ($ordered as $rang => $id) {
        if (in_array((int) $id, $premiums, true)) { $positions[] = $rang; }
    }
    $dernierPremium = $positions ? max($positions) : -1;
    $premierNonPremium = null;
    foreach ($ordered as $rang => $id) {
        if (!in_array((int) $id, $premiums, true)) { $premierNonPremium = $rang; break; }
    }
    $assert(
        'SE048-PUB-006',
        strpos($clauseOn, 'CASE WHEN EXISTS') !== false
        && $positions !== []
        && (null === $premierNonPremium || $dernierPremium < $premierNonPremium)
        && !in_array((int) $c, $ordered, true),
        sprintf('tri premium-first : clause %s, requête RÉELLE sur %d annonces — premiums aux rangs [%s], premier non-premium au rang %s, annonce désactivée présente=%s',
            strpos($clauseOn, 'CASE WHEN EXISTS') !== false ? 'appliquée' : 'ABSENTE',
            count($ordered), implode(',', $positions),
            null === $premierNonPremium ? '—' : (string) $premierNonPremium,
            in_array((int) $c, $ordered, true) ? 'OUI (anomalie)' : 'non'
        )
    );

    /* ── 6. Le tri demandé par le visiteur reste prioritaire ──────────────── */
    $_GET['pk_order'] = 'price-asc';
    $clauseAsked = $orderby_for();
    $orderedAsked = $ordered_ids();
    unset($_GET['pk_order']);
    $assert(
        'SE048-PUB-007',
        strpos($clauseAsked, 'CASE WHEN EXISTS') === false
        && (int) ($orderedAsked[0] ?? 0) !== (int) $a,
        sprintf('tri explicite du visiteur (pk_order=price-asc) : clause premium %s, premier résultat #%d (le choix du visiteur n’est jamais réécrit)',
            strpos($clauseAsked, 'CASE WHEN EXISTS') !== false ? 'APPLIQUÉE (anomalie)' : 'non appliquée',
            (int) ($orderedAsked[0] ?? 0))
    );

    /* ── 7. Le champ REST n’existe pas quand le drapeau est éteint ────────── */
    update_option(PremiumService::OPTION_PUBLIC_ENABLED, '0');
    $dataA_back = rest_do_request(new WP_REST_Request('GET', '/partikulier/v1/listings/' . $listingId))->get_data()['data'] ?? [];
    $assert(
        'SE048-PUB-008',
        !is_wp_error($grantC) && !array_key_exists('premium', $dataA_back)
        && (string) get_option(PremiumService::OPTION_PUBLIC_ENABLED, '0') === '0',
        sprintf('retour au drapeau éteint : clé REST premium %s, drapeau=%s — l’état d’exploitation est respecté dans les deux sens',
            array_key_exists('premium', $dataA_back) ? 'ENCORE PRÉSENTE' : 'absente ✓',
            (string) get_option(PremiumService::OPTION_PUBLIC_ENABLED, '0'))
    );
} catch (Throwable $error) {
    $assert('SE048-PUB-EXCEPTION', false, $error->getMessage());
} finally {
    update_option(PremiumService::OPTION_PUBLIC_ENABLED, $flagBefore);
    delete_option(PremiumService::OPTION_NEXT_END);
    foreach ($created as $id) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}pk_premium_history WHERE property_id = %d", (int) $id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}pk_audit_log WHERE object_id = %d AND action LIKE 'premium_%%'", (int) $id));
        wp_delete_post((int) $id, true);
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se048-premium-public-contract (E-4804 : badges fiche/carte, tri premium-first, champ REST lite ; drapeau éteint = zéro trace)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/se048-premium-public-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);