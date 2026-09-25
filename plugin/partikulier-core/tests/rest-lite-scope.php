<?php
/**
 * Contrat d’intégration pour le périmètre du MU-plugin REST léger.
 *
 * Ce test ne crée ni annonce ni utilisateur. Il vérifie que seuls les GET
 * anonymes de la collection listings retirent les plugins optionnels du
 * bootstrap; toute écriture, authentification, autre route ou sous-route
 * conserve la liste complète.
 */
declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "PK_WP_DIR doit pointer vers une installation WordPress\n");
    exit(2);
}
require $wpDir . '/wp-load.php';

$commit = getenv('PK_COMMIT') ?: '';
$runId = getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\\THis\\Z');
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) {
    fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n");
    exit(2);
}
if (!function_exists('partikulier_is_public_listings_get')) {
    fwrite(STDERR, "MU-plugin REST léger non chargé\n");
    exit(1);
}

$plugins = [
    'partikulier/partikulier.php',
    'estatik/estatik.php',
    'polylang/polylang.php',
    'query-monitor/query-monitor.php',
];
$optional = ['estatik/estatik.php', 'polylang/polylang.php', 'query-monitor/query-monitor.php'];
$originalServer = $_SERVER;
$originalGet = $_GET;
$results = [];

$cases = [
    'pretty_anonymous_get' => ['GET', '/wp-json/partikulier/v1/listings?locale=fr&per_page=24', [], true],
    'rest_route_anonymous_get' => ['GET', '/', ['rest_route' => '/partikulier/v1/listings', 'locale' => 'fr'], true],
    'anonymous_post_denied_scope' => ['POST', '/wp-json/partikulier/v1/listings', [], false],
    'anonymous_put_denied_scope' => ['PUT', '/wp-json/partikulier/v1/listings', [], false],
    'anonymous_patch_denied_scope' => ['PATCH', '/wp-json/partikulier/v1/listings', [], false],
    'anonymous_delete_denied_scope' => ['DELETE', '/wp-json/partikulier/v1/listings', [], false],
    'authorization_header_preserves_bootstrap' => ['GET', '/wp-json/partikulier/v1/listings', [], false, 'Bearer test-only'],
    'wordpress_logged_in_cookie_preserves_bootstrap' => ['GET', '/wp-json/partikulier/v1/listings', [], false, '', 'wordpress_logged_in_hash=test-only'],
    'other_rest_route_preserves_bootstrap' => ['GET', '/wp-json/partikulier/v1/health', [], false],
    'listing_detail_subpath_preserves_bootstrap' => ['GET', '/wp-json/partikulier/v1/listings/42', [], false],
];

foreach ($cases as $id => $case) {
    [$method, $uri, $query, $expected] = array_pad($case, 4, '');
    $authorization = $case[4] ?? '';
    $cookie = $case[5] ?? '';
    $_SERVER = $originalServer;
    $_GET = $query;
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI'] = $uri;
    $_SERVER['HTTP_AUTHORIZATION'] = $authorization;
    $_SERVER['HTTP_COOKIE'] = $cookie;
    $detected = partikulier_is_public_listings_get();
    $filtered = apply_filters('option_active_plugins', $plugins);
    $optionalRemoved = array_values(array_intersect($optional, array_diff($plugins, $filtered)));
    $expectedRemoved = $expected ? $optional : [];
    $ok = $detected === $expected && $optionalRemoved === $expectedRemoved;
    $results[] = [
        'test_id' => 'REST-LITE-SCOPE-' . strtoupper(str_replace('_', '-', $id)),
        'status' => $ok ? 'PASS' : 'FAIL',
        'method' => $method,
        'uri' => $uri,
        'expected_public_collection_get' => $expected,
        'detected_public_collection_get' => $detected,
        'optional_plugins_removed' => $optionalRemoved,
        'expected_removed' => $expectedRemoved,
    ];
}

$_SERVER = $originalServer;
$_GET = $originalGet;

// SE-044 / DP-9 (v1.1 §11 — extension +1) : la collection publique des
// DISPONIBLES — sur le périmètre léger lui-même (GET anonyme de la collection,
// plugins optionnels retirés du bootstrap), le prédicat de disponibilité
// central (v1.1 §5.1) exclut les annonces fermées et sert les ouvertes.
$rlOuvert = wp_insert_post(['post_type' => 'properties', 'post_status' => 'publish', 'post_title' => 'REST-LITE-DP9 ouvert', 'post_content' => 'x'], true);
$rlFerme = wp_insert_post(['post_type' => 'properties', 'post_status' => 'publish', 'post_title' => 'REST-LITE-DP9 fermé', 'post_content' => 'x'], true);
update_post_meta($rlFerme, '_pk_status', 'vendu');
update_post_meta($rlOuvert, '_pk_status', 'actif');
if (class_exists('\Partikulier\Core\Integration\ListingSynchronizer')) {
    (new \Partikulier\Core\Integration\ListingSynchronizer())->flush();
}
wp_set_current_user(0);
$rlReq = new WP_REST_Request('GET', '/partikulier/v1/listings');
$rlReq->set_query_params(['locale' => 'fr', 'per_page' => 100]);
$rlResp = rest_do_request($rlReq);
$rlIds = [];
if (200 === $rlResp->get_status()) {
    foreach ((array) ($rlResp->get_data()['data'] ?? []) as $rlRow) {
        $rlExt = (string) ($rlRow['external_id'] ?? '');
        if (0 === strpos($rlExt, 'estatik:')) { $rlIds[(int) substr($rlExt, 8)] = true; }
    }
}
$results[] = [
    'test_id' => 'REST-LITE-SCOPE-AVAILABLE-COLLECTION-FILTERED',
    'status' => (200 === $rlResp->get_status() && isset($rlIds[$rlOuvert]) && !isset($rlIds[$rlFerme])) ? 'PASS' : 'FAIL',
    'detail' => sprintf('collection GET anonyme (périmètre léger) : ouverte servie %s, fermée servie %s, http %d',
        isset($rlIds[$rlOuvert]) ? 'oui' : 'NON', isset($rlIds[$rlFerme]) ? 'OUI (défaut!)' : 'non', $rlResp->get_status()),
];
wp_delete_post($rlOuvert, true);
wp_delete_post($rlFerme, true);
if (class_exists('\Partikulier\Core\Integration\ListingSynchronizer')) {
    (new \Partikulier\Core\Integration\ListingSynchronizer())->flush();
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
$payload = [
    'test_id' => 'REST-LITE-SCOPE-CONTRACT-001',
    'candidate_version' => getenv('PK_VERSION') ?: '1.7.1',
    'source_commit' => $commit,
    'run_id' => $runId,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'cases' => $results,
    'security_scope' => [
        'anonymous_get_collection_only' => true,
        'writes_preserve_bootstrap' => true,
        'authorization_preserves_bootstrap' => true,
        'wordpress_auth_cookies_preserve_bootstrap' => true,
        'other_routes_preserve_bootstrap' => true,
        'detail_subpaths_preserve_bootstrap' => true,
    ],
];
printf("%s\n", wp_json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
exit($failed ? 1 : 0);
