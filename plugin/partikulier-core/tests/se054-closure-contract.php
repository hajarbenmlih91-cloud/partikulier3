<?php
/**
 * Contrat SE-054 — expérience « annonce vendue » + filet de sécurité
 * variantes (E-5401→E-5403 + E-5404, micro-lot pré-prod 2.10.8/6.20.7,
 * DP-5 jour 1 — addendum : SE-054 livré avant l'activation EN/AR).
 *
 * R1 (pré-correctif, mesuré) : la fiche clôturée affichait badge et
 * filigrane mais la visite était PERDUE — aucune annonce similaire, aucun
 * moyen de demander des biens équivalents ; et le défaut des variantes
 * fantômes était vif : trash de la source → variantes EN/AR publiées
 * (pages étrangères d'une annonce retirée toujours en ligne), aucun statut
 * propagé (une annonce vendue restait « active » en EN/AR).
 *
 * Ce contrat verrouille :
 *  - E54-001 (E-5401) : filigrane ×3 langues — « Vendu »/« Sold »/« مباع »
 *    et « Loué »/« Rented »/« مؤجّر » portés par les catalogues (pomo),
 *    gabarit porteur du filigrane et du libellé de clôture ;
 *  - E54-002 (E-5402) : jusqu'à 3 annonces similaires ACTIVES — même ville,
 *    même type en priorité (complétion sans type), jamais l'annonce
 *    elle-même, jamais une clôturée ;
 *  - E54-003 (E-5403) : WhatsApp PRÉREMPLI — wa.me/<numéro du site> avec
 *    message localisé contenant le titre (annonce vendue → demande de
 *    biens similaires) ;
 *  - E54-004 (filet) : statut cohérent ×3 — propagation aux variantes
 *    liées (_pk_status), traduction manuelle préservée, corbeille de la
 *    source → variantes emmenées (ZÉRO fantôme) ;
 *  - E54-005 (E-5404) : sonde santé — count_ghost_variants + health
 *    check : orphans > 0 dès qu'un fantôme est injecté, 0 après purge.
 *
 * Écart tracé (addendum 1) : les lignes d'audit SE-045 par transition
 * (E-5405) restent au Train 3 — « pas de double instrumentation ».
 *
 * Sortie propre : posts, termes et lignes du registre purgés.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_REPO_DIR=<racine> php
 *   partikulier-core/tests/se054-closure-contract.php
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

use Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService;

if (!class_exists('MO')) { require_once $wpDir . '/wp-includes/pomo/mo.php'; }

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

wp_set_current_user(1);
$run = bin2hex(random_bytes(4));

/* ── Préparation : ville/type dédiés, annonce clôturée + concurrentes, variante liée ── */
$cityTerm = wp_insert_term('SE054ville ' . $run, 'es_location', ['slug' => 'se054ville-' . $run]);
$cityId = is_wp_error($cityTerm) ? (int) get_term_by('slug', 'se054ville-' . $run, 'es_location')->term_id : (int) $cityTerm['term_id'];
$typeTerms = get_terms(['taxonomy' => 'es_type', 'hide_empty' => false, 'number' => 1]);
$typeId = is_array($typeTerms) && $typeTerms ? (int) $typeTerms[0]->term_id : 0;

$mk = static function (string $key, array $extra = []) use ($run, $cityId, $typeId): int {
    $id = wp_insert_post(array_merge([
        'post_type' => 'properties', 'post_status' => 'publish',
        'post_title' => 'SE054 ' . $run . ' ' . $key, 'post_author' => 1,
    ], $extra));
    wp_set_object_terms($id, [(int) $cityId], 'es_location', false);
    if ($typeId) { wp_set_object_terms($id, [$typeId], 'es_type', false); }
    return (int) $id;
};

$sold = $mk('source-vendue');
update_post_meta($sold, '_pk_status', 'vendu');
update_post_meta($sold, '_pk_closed_reason', 'vendu');

$competitorA = $mk('active-a');
$competitorB = $mk('active-b');
$competitorC = $mk('active-c');
$closedCompetitor = $mk('fermee');
update_post_meta($closedCompetitor, '_pk_status', 'vendu');

/* Variante EN liée à la source (traduction manuelle = titre distinct). */
$variant = wp_insert_post([
    'post_type' => 'properties', 'post_status' => 'publish',
    'post_title' => 'SE054 ' . $run . ' translation manuelle EN', 'post_author' => 1,
]);
$linkResult = TranslationVariantsService::link_variant($sold, $variant, 'en', 'fr');
$indispo = 0;

try {
    // 1) Filigrane ×3 langues (E-5401) — catalogues + gabarit porteur.
    $moAr = new MO(); $moAr->import_from_file($repoDir . '/plugin/partikulier-core/languages/ar.mo');
    $moEn = new MO(); $moEn->import_from_file($repoDir . '/plugin/partikulier-core/languages/en_US.mo');
    $tpl = (string) file_get_contents($repoDir . '/theme/partikulier/estatik4/front/property/single.php');
    $closureSrc = (string) file_get_contents($repoDir . '/theme/partikulier/inc/class-listing-closure.php');
    $labelsOk = $moAr->translate('Vendu') === 'مباع' && $moEn->translate('Vendu') === 'Sold'
        && $moAr->translate('Loué') === 'مؤجّر' && $moEn->translate('Loué') === 'Rented';
    $tplOk = strpos($tpl, 'pk-photo-watermark') !== false
        && strpos($tpl, 'similar_block_html') !== false        // le gabarit appelle le bloc
        && strpos($closureSrc, 'pk-sold-similar') !== false;   // le bloc vit dans la classe closure
    $assert('E54-001', $labelsOk && $tplOk && Partikulier_Listing_Closure::closure_label($sold) !== '',
        sprintf('filigrane ×3 : Vendu %s/%s, Loué %s/%s ; gabarit : filigrane %s, bloc similaires %s ; libellé clôture « %s »',
            $moEn->translate('Vendu'), $moAr->translate('Vendu'), $moEn->translate('Loué'), $moAr->translate('Loué'),
            strpos($tpl, 'pk-photo-watermark') !== false ? '✓' : 'ABSENT',
            (strpos($tpl, 'similar_block_html') !== false && strpos($closureSrc, 'pk-sold-similar') !== false) ? '✓' : 'ABSENT',
            Partikulier_Listing_Closure::closure_label($sold)));

    // 2) Annonces similaires (E-5402) — 3 actives max, jamais self, jamais fermée.
    $similar = Partikulier_Listing_Closure::similar_listings($sold, 3);
    $similarIds = wp_list_pluck($similar, 'ID');
    $allActive = true;
    foreach ($similar as $s) {
        $st = (string) get_post_meta($s->ID, '_pk_status', true);
        if ('' !== $st && 'actif' !== $st) { $allActive = false; }
    }
    $assert('E54-002',
        count($similar) === 3 && $allActive
        && !in_array($sold, $similarIds, true) && !in_array($closedCompetitor, $similarIds, true)
        && in_array($competitorA, $similarIds, true) && in_array($competitorB, $similarIds, true) && in_array($competitorC, $similarIds, true),
        sprintf('similaires : %d retournées (attendu 3), toutes actives %s, self exclu %s, fermée exclue %s, concurrentes incluses %s',
            count($similar), $allActive ? '✓' : 'NON', !in_array($sold, $similarIds, true) ? '✓' : 'NON',
            !in_array($closedCompetitor, $similarIds, true) ? '✓' : 'NON',
            (in_array($competitorA, $similarIds, true) && in_array($competitorC, $similarIds, true)) ? '✓' : 'NON'));

    // 3) WhatsApp prérempli (E-5403).
    $opts = get_option('pk_theme_options', []);
    $optsBackup = is_array($opts) ? $opts : [];
    $opts['whatsapp_validation_number'] = '212600000000';
    update_option('pk_theme_options', $opts);
    $waUrl = Partikulier_Listing_Closure::similar_request_url($sold);
    $waOk = strpos($waUrl, 'https://wa.me/212600000000?text=') === 0
        && false !== strpos($waUrl, rawurlencode(sprintf(__('Bonjour, l’annonce « %s » est vendue. Pouvez-vous me proposer des biens similaires ?', 'partikulier'), get_the_title($sold))));
    update_option('pk_theme_options', $optsBackup);
    $assert('E54-003', $waOk,
        sprintf('WhatsApp prérempli : %s (%d caractères de message encodé)', $waOk ? 'wa.me + message localisé avec titre' : 'FORME INVALIDE', strlen($waUrl)));

    // 4) Filet : statut ×3 + corbeille source → variantes emmenées (zéro fantôme).
    $propOk = false; $manualPreserved = false; $ghostsAfterTrash = -1;
    if (!is_wp_error($linkResult)) {
        TranslationVariantsService::propagate_status($sold, 'vendu', 'vendu');
        $propOk = get_post_meta($variant, '_pk_status', true) === 'vendu';
        $manualPreserved = get_the_title($variant) === 'SE054 ' . $run . ' translation manuelle EN';
        wp_trash_post($sold);
        $ghostsAfterTrash = TranslationVariantsService::count_ghost_variants();
        $variantTrashed = get_post_status($variant) === 'trash';
    } else {
        $variantTrashed = false;
    }
    $assert('E54-004',
        !is_wp_error($linkResult) && $propOk && $manualPreserved && $variantTrashed && $ghostsAfterTrash === 0,
        sprintf('filet variantes : statut propagé %s, traduction manuelle préservée %s, source corbeillée → variante %s, fantômes %d',
            $propOk ? '✓' : 'NON', $manualPreserved ? '✓' : 'NON', !empty($variantTrashed) && $variantTrashed ? 'emmenée ✓' : 'PUBLIÉE (fantôme!)', $ghostsAfterTrash));

    // 5) Sonde santé (E-5404) : fantôme injecté → orphans > 0.
    $healthBefore = (new \Partikulier\Core\HealthCheck())->get();
    $ghostsBefore = (int) ($healthBefore['translation_variants']['ghosts'] ?? -1);
    wp_update_post(['ID' => $variant, 'post_status' => 'publish']); // fantôme injecté : variante publiée, source corbeillée
    $ghostsInjected = TranslationVariantsService::count_ghost_variants();
    $healthAfter = (new \Partikulier\Core\HealthCheck())->get();
    $ghostsReported = (int) ($healthAfter['translation_variants']['ghosts'] ?? -1);
    $assert('E54-005',
        $ghostsBefore === 0 && $ghostsInjected > 0 && $ghostsReported === $ghostsInjected,
        sprintf('sonde santé : avant %d, fantôme injecté %d, health check rapporte %d (attendu = injecté > 0)',
            $ghostsBefore, $ghostsInjected, $ghostsReported));
    // 6) SE-044 / DP-9 (v1.1 §11 — extension +2) : « Indisponible » (avis/autre)
    //    rejoint les statuts de clôture — libellé ×3 langues, contact coupé.
    $indispo = $mk('indisponible-avis');
    update_post_meta($indispo, '_pk_status', 'indisponible');
    update_post_meta($indispo, '_pk_closed_reason', 'avis');
    $labelsIndispo = $moAr->translate('Indisponible') === 'غير متوفر' && $moEn->translate('Indisponible') === 'Unavailable';
    $assert('E54-006',
        Partikulier_Listing_Closure::closure_status($indispo) === 'indisponible'
        && $labelsIndispo
        && Partikulier_Listing_Closure::closure_label($indispo) !== ''
        && strpos($tpl, '! $is_closed') !== false,
        sprintf('clôture « indisponible » : statut %s, libellé « %s », catalogues EN/AR %s, contact coupé par la garde is_closed du gabarit %s',
            Partikulier_Listing_Closure::closure_status($indispo),
            Partikulier_Listing_Closure::closure_label($indispo),
            $labelsIndispo ? '✓' : 'ABSENTS',
            strpos($tpl, '! $is_closed') !== false ? '✓' : 'ABSENTE'));

    // 7) SE-044 / DP-9 (v1.1 §11 — extension +2) : exclusion — l'annonce
    //    désactivée (indisponible) est absente du catalogue public et des
    //    similaires ; l'active concurrente y reste.
    $catalogue = new WP_Query([
        'post_type' => 'properties', 'post_status' => 'publish', 'posts_per_page' => 100,
        'fields' => 'ids', 'no_found_rows' => true,
        'meta_query' => Partikulier_Dashboard::active_listing_meta_query(), // prédicat central du thème (pre_get_posts)
    ]);
    $catIds = array_map('intval', $catalogue->posts);
    $similarIds2 = array_map('intval', wp_list_pluck(Partikulier_Listing_Closure::similar_listings($sold, 3), 'ID'));
    $assert('E54-007',
        !in_array($indispo, $catIds, true) && !in_array($closedCompetitor, $catIds, true)
        && in_array($competitorA, $catIds, true)
        && !in_array($indispo, $similarIds2, true),
        sprintf('exclusion : indisponible %s au catalogue, fermée %s au catalogue, active %s, indisponible %s des similaires',
            in_array($indispo, $catIds, true) ? 'PRÉSENTE' : 'absente',
            in_array($closedCompetitor, $catIds, true) ? 'PRÉSENTE' : 'absente',
            in_array($competitorA, $catIds, true) ? 'présente' : 'ABSENTE',
            in_array($indispo, $similarIds2, true) ? 'PRÉSENTE' : 'absente'));
} catch (Throwable $error) {
    $assert('E54-EXCEPTION', false, $error->getMessage());
} finally {
    foreach ([$sold, $competitorA, $competitorB, $competitorC, $closedCompetitor, $variant, $indispo] as $fixId) {
        if ($fixId > 0) { wp_delete_post($fixId, true); }
    }
    global $wpdb;
    $wpdb->query($wpdb->prepare("DELETE FROM {$wpdb->prefix}pk_translation_variants WHERE source_property_id = %d", $sold));
    $term = get_term_by('slug', 'se054ville-' . $run, 'es_location');
    if ($term) { wp_delete_term((int) $term->term_id, 'es_location'); }
    $left = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pk_translation_variants WHERE source_property_id IN (" . implode(',', array_map('intval', [$sold])) . ')');
    if ($left > 0) {
        $assert('E54-EXCEPTION', false, "sortie propre : $left ligne(s) du registre résiduelle(s)");
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se054-closure-contract (expérience annonce vendue + filet variantes fantômes, E-5401→E-5403 + E-5404)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/se054-closure-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
