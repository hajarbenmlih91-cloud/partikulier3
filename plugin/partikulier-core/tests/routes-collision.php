<?php
/**
 * Contrat du registre unique des routes INTEG-3 (lot A) + parité des ponts
 * de performance REG-2.
 *
 * Vérifie : inventaire complet plugin + thème dans partikulier/v1, absence de
 * collision, refus effectif d'une déclaration en double, absence de tout autre
 * appel direct register_rest_route dans les codebases (hors repli d'autonomie
 * du thème et du registre lui-même), alignement exact des deux ponts de
 * performance sur les motifs publiés par le registre.
 *
 * Rejouable : PK_WP_DIR=... php partikulier-core/tests/routes-collision.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Rest\RouteRegistry;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$pluginDir = dirname(__DIR__);
$themeDir = (string) get_template_directory();

try {
    // 1) Inventaire réel après rest_api_init (le serveur REST s'amorce à la demande).
    $inventory = RouteRegistry::restServerInventory();
    $flat = [];
    foreach ($inventory as $route) {
        // L'index de namespace « /partikulier/v1 » est exposé automatiquement
        // par le serveur REST : ce n'est pas une route déclarée, on l'exclut.
        if ($route['route'] === '/' . \Partikulier\Core\Rest\RouteRegistry::NAMESPACE) {
            continue;
        }
        foreach ($route['methods'] as $method) {
            if (in_array($method, ['OPTIONS', 'HEAD'], true)) {
                continue;
            }
            $flat[] = $method . ' ' . str_replace('/partikulier/v1', '', $route['route']);
        }
    }
    sort($flat);
    $expected = [
        'GET /listings',
        'POST /listings',
        'GET /listings/(?P<id>[0-9]+)',
        'POST /leads',
        'POST /favorites',
        'GET /health',
        'POST /automation-event',
        'POST /contact-authorization',
        'POST /preferences',
        'POST /consent',
        'POST /opt-out',
        'POST /erase-lead',
        'POST /credentials-resend-accepted',
        'GET /approved-listings',
        'GET /owner/dashboard',
        'POST /owner/listings/(?P<id>\d+)/action',
    ];
    $missing = array_diff($expected, $flat);
    $assert('ROUTE-001', count($flat) === 16 && $missing === [],
        'inventaire partikulier/v1 : 16 routes attendues, ' . count($flat) . ' déclarées (7 plugin + 9 thème — /automation-event côté plugin depuis B4 ; les 2 routes /owner/* restent côté thème depuis B5 : écran d\'intégration, primitives de table déléguées au service)');

    // 2) Aucune collision : ni refus en mémoire, ni persisté.
    $assert('ROUTE-002', RouteRegistry::collisionCount() === 0 && RouteRegistry::refusedDeclarations() === [],
        'zéro collision déclarée (registre refusant les doublons)');

    // 3) Refus effectif d'une déclaration en double : la route d'origine reste intacte.
    $refused = RouteRegistry::declare('/listings', [
        'methods' => 'GET',
        'callback' => static fn() => new WP_REST_Response(['hijacked' => true], 200),
        'permission_callback' => '__return_true',
    ], 'intruder');
    $probe = rest_do_request(new WP_REST_Request('GET', '/partikulier/v1/listings'));
    $probeData = (array) $probe->get_data();
    $assert('ROUTE-003', $refused === false && RouteRegistry::collisionCount() >= 1
        && ! array_key_exists('hijacked', $probeData) && array_key_exists('data', $probeData),
        'déclaration en double refusée, route d’origine non détournée');
    delete_option('partikulier_core_route_collisions');

    // 4) Purge des déclarations directes : aucun register_rest_route hors du
    // registre (plugin) et hors du repli d'autonomie du thème.
    $allowed = [
        $pluginDir . '/src/Rest/RouteRegistry.php',
        $themeDir . '/inc/class-automation-bridge.php',
    ];
    $offenders = [];
    foreach (array_merge(
        glob($pluginDir . '/src/*.php') ?: [],
        glob($pluginDir . '/src/**/*.php') ?: [],
        glob($pluginDir . '/*.php') ?: [],
        glob($themeDir . '/inc/*.php') ?: [],
        [$themeDir . '/functions.php']
    ) as $file) {
        $file = (string) $file;
        if (in_array($file, $allowed, true)) {
            continue;
        }
        $content = (string) file_get_contents($file);
        if (strpos($content, 'register_rest_route(') !== false) {
            $offenders[] = str_replace('/home/z/my-project/', '', $file);
        }
    }
    $assert('ROUTE-004', $offenders === [], 'aucune déclaration REST directe hors registre (trouvés : ' . ($offenders ? implode(', ', $offenders) : 'aucun') . ')');

    // 5) Parité REG-2 : le mu-plugin et functions.php restent alignés sur le registre.
    $muCandidate = (defined('WPMU_PLUGIN_DIR') ? WPMU_PLUGIN_DIR . '/partikulier-rest-lite.php' : '');
    if (!is_readable((string) $muCandidate)) {
        $muCandidate = $pluginDir . '/mu-plugins/partikulier-rest-lite.php';
    }
    $muContent = (string) file_get_contents((string) $muCandidate);
    $functionsContent = (string) file_get_contents($themeDir . '/functions.php');
    $muAligned = true;
    foreach (RouteRegistry::FAST_PATH_PATTERNS as $pattern) {
        if (strpos($muContent, $pattern) === false) {
            $muAligned = false;
        }
    }
    $themeAligned = strpos($functionsContent, RouteRegistry::FAST_PATH_PREFIX) !== false;
    $assert('ROUTE-005', $muAligned && $themeAligned,
        'ponts de performance alignés sur le registre (mu-plugin motifs exacts, functions.php préfixe)');
} catch (Throwable $error) {
    $assert('ROUTE-EXCEPTION', false, $error->getMessage());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
$payload = [
    'test_id' => 'CORE-ROUTES-CONTRACT-001',
    'candidate_version' => getenv('PK_VERSION') ?: '2.0.0',
    'source_commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local',
    'started_at_utc' => $started,
    'finished_at_utc' => gmdate('c'),
    'command' => 'php partikulier-core/tests/routes-collision.php',
    'fixture' => 'thème + plugin actifs, serveur REST complet',
    'routes_inventory' => $flat ?? [],
    'status' => $failed ? 'FAIL' : 'PASS',
    'exit_code' => $failed ? 1 : 0,
    'tests' => $results,
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'limitations' => ['les routes de modération du thème sont recensées dynamiquement (aucun doublon de nomenclature fragile)'],
];
printf("%s\n", wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit($failed ? 1 : 0);
