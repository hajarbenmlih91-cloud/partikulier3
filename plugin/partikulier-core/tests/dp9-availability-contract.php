<?php
/**
 * Contrat dp9-availability-contract (SE-044 / DP-9 — v1.1 §5/§6/§10/§11 + R1).
 *
 * Famille : prédicat de disponibilité harmonisé et ses surfaces (T28/T29),
 * champ « available » (Q3), synchronisation des TROIS types d'écriture méta
 * (v1.1 §6.2.3), cache chaud (T30) et froid + initialisation (T31, R1 (h)),
 * invariants premium (E-4807), rattrapage n8n non filtré (T34, v1.1 §7) et
 * inertie par construction de la couture d'injection (exigence CP3 §3.1).
 *
 * Exécution IN-PROCESS (après wp-load, Polylang actif, harnais d'injection
 * ABSENT — la constante PK_DP9_TEST_INJECTION ne doit PAS être définie ici) :
 *   php -d apc.enable_cli=1 tests/dp9-availability-contract.php
 * avec PK_WP_DIR, PK_COMMIT, PK_REPO_DIR.
 *
 * Rejouable : idempotent via tests/fixtures/dp9-fixtures.php.
 */

declare(strict_types=1);

$wpDir   = getenv('PK_WP_DIR') ?: '';
$commit  = getenv('PK_COMMIT') ?: '';
$repoDir = getenv('PK_REPO_DIR') ?: '';
if ('' === $wpDir || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
if ('' === $repoDir || !is_dir($repoDir)) { fwrite(STDERR, "PK_REPO_DIR doit pointer vers la racine du dépôt\n"); exit(2); }
$repoDir = rtrim($repoDir, '/');

require $wpDir . '/wp-load.php';
require_once $repoDir . '/theme/partikulier/tests/fixtures/dp9-fixtures.php';

if (!function_exists('apcu_enabled') || !apcu_enabled()) {
        fwrite(STDERR, "APCu doit être actif dans ce processus (php -d apc.enable_cli=1) — T30/T31/init l'exigent\n");
        exit(2);
}
if (defined('PK_DP9_TEST_INJECTION')) {
        fwrite(STDERR, "PK_DP9_TEST_INJECTION ne doit PAS être défini ici : la suite disponibilité s'exécute SANS le harnais (preuve d'inertie)\n");
        exit(2);
}

use Partikulier\Core\ListingRepository;
use Partikulier\Core\Integration\ListingSynchronizer;

$started = gmdate('c');
$results = [];
$assert  = static function (string $id, bool $ok, array $observed = []) use (&$results): void {
        $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'observed' => $observed];
        fwrite(STDERR, ($ok ? 'PASS ' : 'FAIL ') . $id . "\n");
};

$carte    = dp9_seed_fixtures();
$F        = $carte['fixtures'];
$owner    = (int) $carte['owner'];
$repo     = new ListingRepository();
$fermes   = [$F['V1_vendu'], $F['L1_loue'], $F['I1_indisponible'], $F['AR1_archive'], $F['P1_pause'], $F['D1_draft_actif'], $F['W1_attente'], $F['R1_refuse'], $F['U1_inconnu'], $F['T1_corbeille'], $F['E2_variante_refuse']];
$ouverts  = [$F['A1_absent'], $F['A2_vide'], $F['A3_actif'], $F['O1_autrui'], $F['S1_source'], $F['E1_variante'], $F['S2_source']];

/* ═══ 1. Prédicat central (v1.1 §5.1) ═══ */

$assert('DP9-AV-001-predicat-absent-disponible',
        $repo::is_available((int) $F['A1_absent']) === true,
        ['id' => $F['A1_absent'], 'meta' => 'absente']
);
$assert('DP9-AV-002-predicat-meta-vide-disponible',
        $repo::is_available((int) $F['A2_vide']) === true,
        ['id' => $F['A2_vide'], 'meta' => 'vide (changement annoncé v1.1 §5.1)']
);
$assert('DP9-AV-003-predicat-actif-disponible',
        $repo::is_available((int) $F['A3_actif']) === true,
        ['id' => $F['A3_actif'], 'meta' => 'actif']
);
$indispo = [];
foreach ($fermes as $pid) {
        if ($repo::is_available((int) $pid) === true) { $indispo[] = $pid; }
}
$assert('DP9-AV-004-predicat-tout-ferme-indisponible', [] === $indispo,
        ['faux_disponibles' => $indispo, 'etats' => array_map(static fn($p) => get_post_meta((int) $p, '_pk_status', true), $fermes)]
);

/* ═══ 2. Équivalence des surfaces (T28/T29) ═══ */

wp_set_current_user(0);
$req_collection = new WP_REST_Request('GET', '/partikulier/v1/listings');
$req_collection->set_query_params(['locale' => 'fr', 'per_page' => 100]);
$collection = rest_do_request($req_collection);
$ids_rest = [];
if (200 === $collection->get_status()) {
        foreach ((array) $collection->get_data()['data'] as $row) {
                $ext = (string) ($row['external_id'] ?? '');
                if (0 === strpos($ext, 'estatik:')) { $ids_rest[(int) substr($ext, 8)] = true; }
        }
}
// Collection REST ≪ fr ≫ : la locale filtre les lignes — les attendus sont
// côtés fr ; les variantes EN sont couvertes par dp9-variant-resolution.
$fermes_fr  = [$F['V1_vendu'], $F['L1_loue'], $F['I1_indisponible'], $F['AR1_archive'], $F['P1_pause'], $F['D1_draft_actif'], $F['W1_attente'], $F['R1_refuse'], $F['U1_inconnu'], $F['T1_corbeille']];
$ouverts_fr = [$F['A1_absent'], $F['A2_vide'], $F['A3_actif'], $F['O1_autrui'], $F['S1_source'], $F['S2_source']];
$assert('DP9-AV-005-collection-rest-exclut-fermes-inclut-disponibles',
        200 === $collection->get_status()
        && !array_filter($fermes_fr, static fn($p) => isset($ids_rest[(int) $p]))
        && !array_filter($ouverts_fr, static fn($p) => !isset($ids_rest[(int) $p])),
        ['http' => $collection->get_status(), 'fermes_servis' => array_values(array_filter($fermes_fr, static fn($p) => isset($ids_rest[(int) $p]))), 'ouverts_absents' => array_values(array_filter($ouverts_fr, static fn($p) => !isset($ids_rest[(int) $p])))]
);

// Catalogue WP : requête neutre en langue (lang='') pour éprouver le prédicat
// de disponibilité du thème (pre_get_posts) sur TOUTES les langues.
$q = new WP_Query([
        'post_type'      => 'properties',
        'post_status'    => 'publish',
        'posts_per_page' => 100,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'lang'           => '',
        'meta_query'     => Partikulier_Dashboard::active_listing_meta_query(), // prédicat central du thème (pre_get_posts)
]);
$ids_cat = array_map('intval', $q->posts);
$assert('DP9-AV-006-catalogue-wp-meme-verdict',
        !array_filter($fermes, static fn($p) => in_array((int) $p, $ids_cat, true))
        && !array_filter($ouverts, static fn($p) => !in_array((int) $p, $ids_cat, true)),
        ['fermes_au_catalogue' => array_values(array_intersect(array_map('intval', $fermes), $ids_cat)), 'ouverts_absents' => array_values(array_diff(array_map('intval', $ouverts), $ids_cat))]
);

$similaires = class_exists('Partikulier_Listing_Closure')
        ? array_map('intval', wp_list_pluck(Partikulier_Listing_Closure::similar_listings((int) $F['V1_vendu'], 3), 'ID'))
        : [];
$assert('DP9-AV-007-similaires-actives-uniquement',
        [] !== $similaires
        && !array_filter($similaires, static fn($p) => in_array((int) $p, array_map('intval', $fermes), true)),
        ['similaires' => $similaires]
);

$assert('DP9-AV-008-t29-meta-vide-incluse-toutes-surfaces',
        isset($ids_rest[(int) $F['A2_vide']])
        && in_array((int) $F['A2_vide'], $ids_cat, true)
        && in_array((int) $F['A2_vide'], $similaires, true),
        ['a2' => $F['A2_vide'], 'dans_rest' => isset($ids_rest[(int) $F['A2_vide']]), 'dans_catalogue' => in_array((int) $F['A2_vide'], $ids_cat, true), 'dans_similaires' => in_array((int) $F['A2_vide'], $similaires, true)]
);
/* ═══ 3. Champ « available » (Q3, v1.1 §13.4) ═══ */

$row_a3 = $repo->rowForExternalId('estatik:' . $F['A3_actif']);
$row_v1 = $repo->rowForExternalId('estatik:' . $F['V1_vendu']);
$req_a3 = rest_do_request(new WP_REST_Request('GET', '/partikulier/v1/listings/' . ($row_a3['id'] ?? 0)));
$req_v1 = rest_do_request(new WP_REST_Request('GET', '/partikulier/v1/listings/' . ($row_v1['id'] ?? 0)));
$assert('DP9-AV-009-available-ouvert-vrai',
        200 === $req_a3->get_status() && ($req_a3->get_data()['data']['available'] ?? null) === true,
        ['http' => $req_a3->get_status(), 'available' => $req_a3->get_data()['data']['available'] ?? null]
);
$assert('DP9-AV-010-available-ferme-faux',
        200 === $req_v1->get_status() && ($req_v1->get_data()['data']['available'] ?? null) === false,
        ['http' => $req_v1->get_status(), 'available' => $req_v1->get_data()['data']['available'] ?? null]
);

/* ═══ 4. Synchronisation : les TROIS types d'écriture méta (v1.1 §6.2.3) ═══ */

$sync = new ListingSynchronizer();
$v0 = ListingRepository::currentSearchCacheVersion();
update_post_meta((int) $F['A1_absent'], '_pk_status', 'vendu');           // ajout
$r1 = $sync->flush();
$v1 = ListingRepository::currentSearchCacheVersion();
$assert('DP9-AV-011-sync-ajout-meta-reprojette-invalide',
        ($r1['upserts'] ?? 0) >= 1 && $v1 > $v0,
        ['version_avant' => $v0, 'version_apres' => $v1, 'upserts' => $r1['upserts'] ?? null]
);
update_post_meta((int) $F['A1_absent'], '_pk_status', 'loue');            // modification
$r2 = $sync->flush();
$v2 = ListingRepository::currentSearchCacheVersion();
$assert('DP9-AV-012-sync-modification-meta-reprojette-invalide',
        ($r2['upserts'] ?? 0) >= 1 && $v2 > $v1,
        ['version_avant' => $v1, 'version_apres' => $v2, 'upserts' => $r2['upserts'] ?? null]
);
delete_post_meta((int) $F['A1_absent'], '_pk_status');                    // suppression (deleted_post_meta)
$r3 = $sync->flush();
$v3 = ListingRepository::currentSearchCacheVersion();
$assert('DP9-AV-013-sync-suppression-meta-reprojette-invalide',
        ($r3['upserts'] ?? 0) >= 1 && $v3 > $v2,
        ['version_avant' => $v2, 'version_apres' => $v3, 'upserts' => $r3['upserts'] ?? null]
);

/* ═══ 5. Cache chaud (T30) — transition puis relecture immédiate ═══ */

$rap = Partikulier_Listing_Transitions::transition((int) $F['A3_actif'], 'deactivate', $owner, 'vendu', '');
$req_chaud = new WP_REST_Request('GET', '/partikulier/v1/listings');
$req_chaud->set_query_params(['locale' => 'fr', 'per_page' => 100]);
$coll_chaud = rest_do_request($req_chaud);
$a3_servi = false;
if (200 === $coll_chaud->get_status()) {
        foreach ((array) $coll_chaud->get_data()['data'] as $row) {
                if (isset($row['external_id']) && 'estatik:' . $F['A3_actif'] === (string) $row['external_id']) { $a3_servi = true; }
        }
}
$assert('DP9-AV-014-t30-cache-chaud-fermeture-immediate',
        is_array($rap) && !empty($rap['steps']) && !$a3_servi,
        ['transition_ok' => is_array($rap), 'a3_encore_servi' => $a3_servi]
);

/* ═══ 6. Rattrapage n8n non filtré (T34, v1.1 §7) — A3 approuvée < 72 h puis désactivée ═══ */

$secret = '';
if (class_exists('\Partikulier\Core\Domain\Automation\AutomationService') && method_exists('\Partikulier\Core\Domain\Automation\AutomationService', 'get')) {
        $secret = (string) \Partikulier\Core\Domain\Automation\AutomationService::get('automation_api_secret');
}
if ('' === $secret) {
        $o = get_option('pk_n8n_settings', []);
        $secret = is_array($o) ? (string) ($o['automation_api_secret'] ?? '') : '';
}
// La garde promote le mode « off » en enforce dès qu'un secret est présent
// (lot sécurité 2.10.7) : requête SIGNÉE (HMAC sha256 sur méthode+route+
// horodatage+corps, clé dérivée comme AutomationHmacTrait::hmac_key).
$cles = method_exists('\Partikulier\Core\Domain\Automation\AutomationService', 'secret_keys')
        ? (array) \Partikulier\Core\Domain\Automation\AutomationService::secret_keys() : [];
$key_id = (string) (array_key_first($cles) ?: '');
$cle_hmac = $secret;
$decode = base64_decode($secret, true);
if (is_string($decode) && strlen($decode) >= 32) {
        $cle_hmac = $decode;
} elseif (preg_match('/^[a-f0-9]{64,}$/i', $secret)) {
        $hex = @hex2bin(substr($secret, 0, strlen($secret) - (strlen($secret) % 2)));
        if (false !== $hex && strlen($hex) >= 32) { $cle_hmac = $hex; }
}
$ts = (string) time();
$canonique = "GET\n/partikulier/v1/approved-listings\n" . $ts . "\n";
$signature = 'sha256=' . hash_hmac('sha256', $canonique, $cle_hmac);
$req_rattrapage = new WP_REST_Request('GET', '/partikulier/v1/approved-listings');
$req_rattrapage->add_header('X-Partikulier-Automation', $secret);
$req_rattrapage->add_header('X-Partikulier-Timestamp', $ts);
$req_rattrapage->add_header('X-Partikulier-Key-Id', $key_id);
$req_rattrapage->add_header('X-Partikulier-Signature', $signature);
$rat = rest_do_request($req_rattrapage);
$listees = [];
if (200 === $rat->get_status()) {
        foreach ((array) ($rat->get_data()['listings'] ?? []) as $row) {
                $listees[] = (int) ($row['id'] ?? 0);
        }
}
$assert('DP9-AV-019-t34-rattrapage-n8n-preserve',
        200 === $rat->get_status() && in_array((int) $F['A3_actif'], $listees, true),
        ['http' => $rat->get_status(), 'a3_listee' => in_array((int) $F['A3_actif'], $listees, true), 'comportement' => 'annonce approuvée puis désactivée < 72 h : toujours livrée au rattrapage (v1.1 §7 — non filtré dans ce lot)']
);

$rap = Partikulier_Listing_Transitions::transition((int) $F['A3_actif'], 'reactivate', $owner, '', '');
$req_reouv = new WP_REST_Request('GET', '/partikulier/v1/listings');
$req_reouv->set_query_params(['locale' => 'fr', 'per_page' => 100]);
$coll_reouv = rest_do_request($req_reouv);
$a3_servi = false;
if (200 === $coll_reouv->get_status()) {
        foreach ((array) $coll_reouv->get_data()['data'] as $row) {
                if (isset($row['external_id']) && 'estatik:' . $F['A3_actif'] === (string) $row['external_id']) { $a3_servi = true; }
        }
}
$assert('DP9-AV-015-t30-cache-chaud-reouverture-immediate',
        is_array($rap) && $a3_servi,
        ['transition_ok' => is_array($rap), 'a3_reservi' => $a3_servi]
);

/* ═══ 7. Cache froid + initialisation (T31) et init atomique (R1 (h)) ═══ */

apcu_clear_cache();
$b1 = ListingRepository::ensureSearchCacheVersion();
$vf = ListingRepository::currentSearchCacheVersion();
$inc1 = ListingSynchronizer::invalidate_listing_search_cache_now();
$vf2 = ListingRepository::currentSearchCacheVersion();
$assert('DP9-AV-016-t31-froid-init-premier-flush-efficace',
        true === $b1 && 1 === $vf && 2 === $inc1 && 2 === $vf2,
        ['creee' => $b1, 'version_initiale' => $vf, 'premier_incr' => $inc1, 'version_apres' => $vf2]
);

apcu_delete('pk_dp9_test_version');
$radd1 = apcu_add('pk_dp9_test_version', 1, 0);
$radd2 = apcu_add('pk_dp9_test_version', 1, 0); // initialiseur concurrent : doit échouer
apcu_inc('pk_dp9_test_version');
apcu_inc('pk_dp9_test_version');
$radd3 = apcu_add('pk_dp9_test_version', 1, 0); // attardé : ne doit rien écraser
$vtest = apcu_fetch('pk_dp9_test_version');
$b2 = ListingRepository::ensureSearchCacheVersion(); // déjà présente : no-op, pas de retour à 1
$vsvc = ListingRepository::currentSearchCacheVersion();
apcu_delete('pk_dp9_test_version');
$assert('DP9-AV-017-init-atomique-get-or-create',
        true === $radd1 && false === $radd2 && false === $radd3 && 3 === $vtest && true === $b2 && $vsvc >= 2,
        ['primitive' => ['add1' => $radd1, 'add_concurrent' => $radd2, 'add_tardive' => $radd3, 'version' => $vtest], 'service' => ['ensure_noop' => $b2, 'version_service' => $vsvc]]
);

/* ═══ 8. Invariance premium (E-4807) ═══ */

update_post_meta((int) $F['A3_actif'], '_pk_premium_status', 'active');
update_post_meta((int) $F['A3_actif'], '_pk_premium_ends_at', '2027-06-30 00:00:00');
$r1 = Partikulier_Listing_Transitions::transition((int) $F['A3_actif'], 'deactivate', $owner, 'loue', '');
$r2 = Partikulier_Listing_Transitions::transition((int) $F['A3_actif'], 'reactivate', $owner, '', '');
$assert('DP9-AV-018-e4807-premium-survit-transitions',
        is_array($r1) && is_array($r2) && 'active' === (string) get_post_meta((int) $F['A3_actif'], '_pk_premium_status', true),
        ['premium' => get_post_meta((int) $F['A3_actif'], '_pk_premium_status', true), 'fin' => get_post_meta((int) $F['A3_actif'], '_pk_premium_ends_at', true)]
);

/* ═══ 9. Couture d'injection INERTE sans constante de test (exigence CP3 §3.1) ═══ */

$fuites = [];
$racines = [$repoDir . '/theme/partikulier/inc', $repoDir . '/plugin/partikulier-core/src'];
$racines[] = $repoDir . '/theme/partikulier/functions.php';
$racines[] = $repoDir . '/plugin/partikulier-core/partikulier-core.php';
foreach ($racines as $racine) {
        if (is_dir($racine)) {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($racine, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $f) {
                        if ($f->isFile() && 'php' === strtolower($f->getExtension())
                                && preg_match("#define\s*\(\s*['\"]PK_DP9_TEST_INJECTION#", (string) file_get_contents($f->getPathname()))) {
                                $fuites[] = $f->getPathname();
                        }
                }
        } elseif (is_file($racine) && preg_match("#define\s*\(\s*['\"]PK_DP9_TEST_INJECTION#", (string) file_get_contents($racine))) {
                $fuites[] = $racine;
        }
}
// Filtre hostile enregistré + option armée : SANS la constante, la couture reste inerte.
dp9_arme_agressive();
$rap = Partikulier_Listing_Transitions::transition((int) $F['A3_actif'], 'deactivate', $owner, 'avis', '');
$etapes = is_array($rap) ? $rap['steps'] : [];
$aucun_echec_injecte = is_array($rap) && [] === array_filter($etapes, static function ($e) {
        return isset($e['observed']['injected']);
});
remove_all_filters('pk_dp9_defect_injection');
delete_option('pk_dp9_injection_armee');
$assert('DP9-AV-020-couture-inerte-sans-constante',
        [] === $fuites && $aucun_echec_injecte && 'indisponible' === (string) get_post_meta((int) $F['A3_actif'], '_pk_status', true),
        ['definitions_dans_code_livre' => $fuites, 'filtre_hostile_bloque' => $aucun_echec_injecte, 'constante_definie' => defined('PK_DP9_TEST_INJECTION'), 'etat_a3' => get_post_meta((int) $F['A3_actif'], '_pk_status', true)]
);
Partikulier_Listing_Transitions::transition((int) $F['A3_actif'], 'reactivate', $owner, '', '');

/** Enregistre un filtre qui voudrait déclencher un défaut à CHAQUE point. */
function dp9_arme_agressive(): void {
        update_option('pk_dp9_injection_armee', ['point' => 'after_source_write'], true);
        add_filter('pk_dp9_defect_injection', static function () {
                return ['after_source_write' => true, 'during_propagation' => true, 'before_invalidation' => true];
        }, 10, 2);
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
        'suite' => 'dp9-availability-contract (prédicat §5.1, équivalence surfaces T28/T29, available Q3, sync 3 types §6.2.3, cache chaud/froid/init T30/T31, premium E-4807, rattrapage T34, inertie couture CP3 §3.1)',
        'started_at' => $started,
        'finished_at' => gmdate('c'),
        'commit' => $commit,
        'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
        'status' => $failed ? 'FAIL' : 'PASS',
        'total' => count($results),
        'passed' => count($results) - count($failed),
        'failed' => count($failed),
        'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
