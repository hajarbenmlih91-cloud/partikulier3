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
