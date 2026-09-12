<?php
/**
 * Contrat d'extinction finale des vestiges (lot F, CDC v1.2 — tableau 4
 * lot F + CA-4/annexe C).
 *
 * Le lot F n'est pas un lot de développement : c'est le lot de preuve de fin
 * de chantier. Les 8 fichiers vestiges du thème (ceux qui portaient les 17
 * CREATE TABLE en sommeil — arbitrage « Référence + plugin » du lot D,
 * rapport E §prochaines étapes) sont physiquement éteints : plus aucune
 * installation de schéma, plus aucun chemin autonome 6.17.x, plus aucun
 * accès SQL direct — chaque opération délègue exclusivement au service du
 * plugin, qui est requis (INTEG-2 : un dispositif unique de capture de
 * leads). Les points d'intégration UI (écrans, AJAX, routes déclarées via
 * le registre INTEG-3) restent au thème avec leur API publique intacte.
 *
 * Assertions FE-001 à FE-012 :
 *  - FE-001 : extinction DDL — zéro dbDelta/CREATE TABLE dans le runtime
 *    thème (les 17 tables historiques sont créées côté plugin uniquement —
 *    métrique CA-4 « tables SQL créées côté thème = 0 ») ;
 *  - FE-002 : class-buyer-qualification.php ≤ 400 lignes (619 → mesuré) ;
 *  - FE-003 : class-n8n-security.php ≤ 400 lignes (421 → mesuré) ;
 *  - FE-004 : API publique des 8 vestiges intacte (matrice method_exists :
 *    aucun consommateur — écran, template, contrat ou pont plugin — ne casse) ;
 *  - FE-005 : méthodes d'installation/migration éteintes (maybe_install ×7,
 *    maybe_migrate, maybe_install_audit_table — method_exists faux) ;
 *  - FE-006 : zéro accès SQL direct dans les 8 fichiers sources (critère de
 *    sortie du lot B consolidé au lot F : tables lues/écrites côté plugin
 *    uniquement) ;
 *  - FE-007 : une seule déclaration de namespace REST côté thème (le repli
 *    du pont d'automatisation ; l'espace partikulier/v1 appartient au
 *    registre unique du plugin — INTEG-3) ;
 *  - FE-008 : surface de routes métier intacte au nominal (les 14 routes
 *    attendues sont déclarées, via le registre côté plugin) ;
 *  - FE-009 : délégations vivantes au nominal (daily_limit, favorite_count,
 *    get, is_public_enabled — égalité stricte thème ↔ service) ;
 *  - FE-010 : appels système — exactement 1 site exec() dans le runtime
 *    thème+plugin (la passerelle SECU-1 du lot E, scan lexical par tokens)
 *    et gardes ABSPATH 100 % dans inc/ ;
 *  - FE-011 : santé — plugin 2.10.3, thème 6.20.2, schéma figé 2.6.0,
 *    8/8 domaines plugin, 0 collision ;
 *  - FE-012 : hygiène du banc + INTEG-1 — leads=10, favoris=4,
 *    événements=2, variantes=0, annonces=30, zéro annonce fantôme
 *    (contrat de lecture seule : aucune écriture).
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/vestige-extinction-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\Leads\LeadService;
use Partikulier\Core\Domain\OwnerStats\OwnerStatsService;
use Partikulier\Core\Domain\Automation\AutomationService;
use Partikulier\Core\Domain\Premium\PremiumService;
use Partikulier\Core\HealthCheck;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$themeRoot = (string) get_template_directory();
$pluginRoot = WP_PLUGIN_DIR . '/partikulier-core';
$lineCount = static function (string $path): int {
    $content = (string) file_get_contents($path);
    return substr_count($content, "\n") + 1;
};

/* Les 8 fichiers vestiges du lot F (périaut ordre alpha) — ceux qui portaient
 * les 17 CREATE TABLE en sommeil à l'état 6.20.0. */
$vestiges = [
    'inc/class-automation-bridge.php',
    'inc/class-buyer-qualification.php',
    'inc/class-localization-variants.php',
    'inc/class-n8n-security.php',
    'inc/class-owner-insights.php',
    'inc/class-payment-foundation.php',
    'inc/class-premium.php',
    'inc/class-saved-alerts.php',
];

try {

    /* FE-001 — extinction DDL : zéro dbDelta/CREATE TABLE dans le runtime thème. */
    $ddlHits = [];
    $collect = static function (string $dir, array $exclude, array &$out) use (&$collect): void {
        if (!is_dir($dir)) { return; }
        foreach (new FilesystemIterator($dir, FilesystemIterator::SKIP_DOTS) as $f) {
            $p = (string) $f;
            if ($f->isDir()) {
                $base = basename($p);
                if (!in_array($base, $exclude, true)) { $collect($p, $exclude, $out); }
                continue;
            }
            if (substr($p, -4) === '.php') { $out[] = $p; }
        }
    };
    $themeRuntime = [];
    $collect($themeRoot, ['tests', '__baseline__', '__screens__', 'node_modules', 'languages'], $themeRuntime);
    foreach ($themeRuntime as $f) {
        $src = (string) file_get_contents($f);
        if (strpos($src, 'dbDelta') !== false || strpos($src, 'CREATE TABLE') !== false) {
            $ddlHits[] = basename($f);
        }
    }
    $assert('FE-001', $ddlHits === [],
        $ddlHits === []
            ? sprintf('extinction DDL : 0 dbDelta/CREATE TABLE dans les %d fichiers PHP runtime du thème — les 17 tables historiques sont créées côté plugin uniquement (CA-4 : tables SQL créées côté thème = 0)', count($themeRuntime))
            : 'DDL résiduel : ' . implode(', ', array_slice($ddlHits, 0, 8)));

    /* FE-002 / FE-003 — les deux vestiges >400 lignes repassent sous le seuil. */
    $bqLines = $lineCount($themeRoot . '/inc/class-buyer-qualification.php');
    $assert('FE-002', $bqLines > 0 && $bqLines <= 400,
        sprintf('class-buyer-qualification.php : %d lignes (619 à l\'état 6.20.0 → sous le seuil CA-4 de 400)', $bqLines));
    $n8nLines = $lineCount($themeRoot . '/inc/class-n8n-security.php');
    $assert('FE-003', $n8nLines > 0 && $n8nLines <= 400,
        sprintf('class-n8n-security.php : %d lignes (421 à l\'état 6.20.0 → sous le seuil CA-4 de 400)', $n8nLines));

    /* FE-004 — API publique des 8 vestiges intacte (aucun consommateur ne casse). */
    $apiMatrix = [
        ['Partikulier_Automation_Bridge', ['init', 'events_table', 'register_routes', 'register_route', 'declare_rest_route', 'check_automation_secret', 'receive_event']],
        ['Partikulier_Buyer_Qualification', ['daily_limit', 'init', 'register_routes', 'reference_for', 'contact_url', 'handle_contact_authorization', 'register_api_lead', 'handle_preferences', 'handle_consent', 'handle_opt_out', 'handle_stop', 'decrypt_phone_for_admin']],
        ['Partikulier_Saved_Alerts', ['alerts_table', 'deliveries_table', 'save_alert', 'change_status']],
        ['Partikulier_Payment_Foundation', ['orders_table', 'subscriptions_table', 'create_order']],
        ['Partikulier_Premium', ['init', 'register_menu', 'handle_grant', 'handle_revoke', 'table_name', 'is_public_enabled', 'grant', 'is_active', 'expire', 'revoke', 'render_admin_page']],
        ['Partikulier_N8n_Security', ['init', 'env_secret', 'env_webhook', 'settings', 'get', 'secret_keys', 'outgoing_headers', 'check_automation_secret', 'audit_failure', 'admin_menu', 'save_admin', 'render_admin']],
        ['Partikulier_Owner_Insights', ['init', 'register_routes', 'can_access_owner_dashboard', 'rest_dashboard', 'rest_manage_listing', 'favorite_count', 'purge_expired_saves', 'sync_favorite', 'handle_favorites_list', 'handle_sync_favorite']],
        ['Partikulier_Localization', ['prepare_variant', 'link_variant']],
    ];
    $missingApi = [];
    $apiTotal = 0;
    foreach ($apiMatrix as [$class, $methods]) {
        if (!class_exists($class) && !trait_exists($class)) { $missingApi[] = $class . ' (classe absente)'; continue; }
        foreach ($methods as $m) {
            $apiTotal++;
            if (!method_exists($class, $m)) { $missingApi[] = $class . '::' . $m; }
        }
    }
    $assert('FE-004', $missingApi === [],
        $missingApi === []
            ? sprintf('API publique intacte : %d/%d méthodes présentes sur les 8 classes (écrans, templates, AJAX et contrats B1-B6 continuent de résoudre)', $apiTotal, $apiTotal)
            : 'méthodes manquantes : ' . implode(', ', array_slice($missingApi, 0, 8)));

    /* FE-005 — méthodes d'installation/migration éteintes. */
    $etouffees = [];
    $extinctMethods = [
        ['Partikulier_Automation_Bridge', 'maybe_install'],
        ['Partikulier_Buyer_Qualification', 'maybe_install'],
        ['Partikulier_Saved_Alerts', 'maybe_install'],
        ['Partikulier_Payment_Foundation', 'maybe_install'],
        ['Partikulier_Premium', 'maybe_install'],
        ['Partikulier_Owner_Insights', 'maybe_install'],
        ['Partikulier_Localization', 'maybe_install'],
        ['Partikulier_N8n_Security', 'maybe_migrate'],
        ['Partikulier_N8n_Security', 'maybe_install_audit_table'],
    ];
    foreach ($extinctMethods as [$class, $m]) {
        if (method_exists($class, $m)) { $etouffees[] = $class . '::' . $m . ' (encore présente)'; }
    }
    $assert('FE-005', $etouffees === [],
        $etouffees === []
            ? 'extinction physique : maybe_install ×7 (dont le trait Variants du shell Localization), maybe_migrate et maybe_install_audit_table n\'existent plus — aucun accrochage résiduel'
            : 'méthodes résiduelles : ' . implode(', ', $etouffees));

    /* FE-006 — zéro accès SQL direct dans les 8 fichiers. */
    $sqlHits = [];
    foreach ($vestiges as $rel) {
        $src = (string) file_get_contents($themeRoot . '/' . $rel);
        if (strpos($src, 'wpdb') !== false) { $sqlHits[] = $rel; }
    }
    $assert('FE-006', $sqlHits === [],
        $sqlHits === []
            ? 'zéro accès SQL direct dans les 8 fichiers vestiges (critère de sortie du lot B consolidé au lot F : tables lues/écrites côté plugin uniquement)'
            : 'accès SQL résiduels : ' . implode(', ', $sqlHits));

    /* FE-007 — une seule déclaration de namespace REST côté thème. */
    $nsDeclarations = [];
    foreach ($themeRuntime as $f) {
        $src = (string) file_get_contents($f);
        if (preg_match('/const\s+REST_NAMESPACE\s*=/', $src)) { $nsDeclarations[] = basename($f); }
    }
    $assert('FE-007', $nsDeclarations === ['class-automation-bridge.php'],
        $nsDeclarations === ['class-automation-bridge.php']
            ? 'namespace partikulier/v1 : déclaré côté thème uniquement dans le repli du pont d\'automatisation (const REST_NAMESPACE) — le registre unique du plugin détient l\'espace (INTEG-3)'
            : 'déclarations : ' . implode(', ', $nsDeclarations));

    /* FE-008 — surface de routes métier intacte au nominal. */
    $routes = rest_get_server()->get_routes();
    $expected = [
        '/partikulier/v1/contact-authorization',
        '/partikulier/v1/preferences',
        '/partikulier/v1/consent',
        '/partikulier/v1/opt-out',
        '/partikulier/v1/credentials-resend-accepted',
        '/partikulier/v1/approved-listings',
        '/partikulier/v1/owner/dashboard',
        '/partikulier/v1/owner/listings/(?P<id>\d+)/action',
        '/partikulier/v1/automation-event',
        '/partikulier/v1/leads',
        '/partikulier/v1/erase-lead',
        '/partikulier/v1/favorites',
        '/partikulier/v1/listings',
        '/partikulier/v1/health',
    ];
    $routesAbsentes = array_values(array_filter($expected, static fn($r) => !isset($routes[$r])));
    $assert('FE-008', $routesAbsentes === [],
        $routesAbsentes === []
            ? sprintf('surface REST intacte : les %d routes métier attendues sont déclarées au nominal (thème via registre plugin, plugin via RestController — INTEG-3, 0 collision)', count($expected))
            : 'routes absentes : ' . implode(', ', $routesAbsentes));

    /* FE-009 — délégations vivantes : égalité stricte thème ↔ service. */
    $probeProperty = 0;
    $probeRow = $GLOBALS['wpdb']->get_row("SELECT id FROM {$GLOBALS['wpdb']->prefix}pk_listings LIMIT 1");
    if ($probeRow) { $probeProperty = (int) $probeRow->id; }
    $themeCount = Partikulier_Owner_Insights::favorite_count($probeProperty);
    $serviceCount = $probeProperty > 0 ? OwnerStatsService::favorite_count($probeProperty) : 0;
    $delegOk = Partikulier_Buyer_Qualification::daily_limit() === LeadService::daily_limit()
        && $themeCount === $serviceCount
        && Partikulier_N8n_Security::get('quota_per_day', 'x') === AutomationService::get('quota_per_day', 'x')
        && Partikulier_Premium::is_public_enabled() === PremiumService::is_public_enabled();
    $assert('FE-009', $delegOk,
        sprintf('délégations vivantes : daily_limit=%s, favorite_count=%d, get(quota) et is_public_enabled=%s identiques via la classe thème et le service plugin (lecture seule)',
            (string) Partikulier_Buyer_Qualification::daily_limit(), $themeCount, Partikulier_Premium::is_public_enabled() ? 'true' : 'false'));

    /* FE-010 — appels système : exactement 1 site exec() dans le runtime
     * (scan lexical par tokens, insensible à la casse — les commentaires ne
     * comptent pas) + gardes ABSPATH 100 % dans inc/. */
    $systemFns = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open'];
    $scanSites = static function (string $file) use ($systemFns): array {
        $code = @file_get_contents($file);
        if (!is_string($code) || $code === '') { return []; }
        $tokens = @token_get_all($code);
        if (!is_array($tokens)) { return []; }
        $sites = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $t = $tokens[$i];
            if (is_array($t) && $t[0] === T_STRING && in_array(strtolower((string) $t[1]), $systemFns, true)) {
                $j = $i + 1;
                while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; }
                if ($j < $n && $tokens[$j] === '(') {
                    $sites[] = ['line' => (int) $t[2], 'fn' => strtolower((string) $t[1])];
                }
            }
        }
        return $sites;
    };
    $pluginRuntime = [];
    $collect($pluginRoot, ['tests'], $pluginRuntime);
    $execViolations = [];
    $execSites = [];
    foreach (array_merge($themeRuntime, $pluginRuntime) as $f) {
        foreach ($scanSites($f) as $s) {
            $execSites[] = basename($f) . ':' . $s['line'];
            if (strpos(wp_normalize_path($f), 'class-exec-whitelist.php') === false) {
                $execViolations[] = basename($f) . ':' . $s['line'] . ' ' . $s['fn'];
            }
        }
    }
    $incFiles = glob($themeRoot . '/inc/*.php') ?: [];
    $incSansGarde = [];
    foreach ($incFiles as $f) {
        $src = (string) file_get_contents($f);
        if (strpos($src, "defined( 'ABSPATH' )") === false && strpos($src, "defined('ABSPATH')") === false) {
            $incSansGarde[] = basename($f);
        }
    }
    $assert('FE-010', $execViolations === [] && count($execSites) === 1 && $incSansGarde === [],
        sprintf('appels système : %d site(s) exec() dans les %d fichiers runtime thème+plugin — uniquement la passerelle SECU-1 du lot E (CA-4 : 0 appel hors module) ; gardes ABSPATH %d/%d dans inc/',
            count($execSites), count($themeRuntime) + count($pluginRuntime), count($incFiles) - count($incSansGarde), count($incFiles))
        . ($execViolations ? ' — VIOLATIONS : ' . implode(', ', array_slice($execViolations, 0, 5)) : ''));

    /* FE-011 — santé : versions du lot F, schéma figé, 8/8, 0 collision. */
    $themeVersion = wp_get_theme()->get('Version');
    $health = (new HealthCheck())->get();
    $pluginDomains = count(array_filter($health['domains'] ?? [], static fn($d): bool => ($d['owner'] ?? '') === 'plugin'));
    $assert('FE-011', PARTIKULIER_CORE_VERSION === '2.10.3' && $themeVersion === '6.20.2'
        && \Partikulier\Core\Database\Schema::VERSION === '2.6.0' && ($health['status'] ?? '') === 'ok'
        && $pluginDomains === 8 && (int) ($health['routes']['collisions'] ?? -1) === 0,
        sprintf('santé : %s, plugin %s, thème %s (lot F — extinction finale), schéma %s (zéro migration), %d/8 domaines, 0 collision',
            ($health['status'] ?? '?'), PARTIKULIER_CORE_VERSION, $themeVersion, \Partikulier\Core\Database\Schema::VERSION, $pluginDomains));

    /* FE-012 — hygiène du banc + INTEG-1 : zéro fantôme (lecture seule). */
    global $wpdb;
    $prefix = $wpdb->prefix;
    $hygiene = [
        'leads' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_buyer_leads"),
        'favoris' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_property_saves"),
        'evenements' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_automation_events"),
        'variantes' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_property_variants"),
        'annonces' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_listings"),
    ];
    $ghosts = (int) ($health['integrity']['orphans'] ?? -1);
    $assert('FE-012', $hygiene === ['leads' => 10, 'favoris' => 4, 'evenements' => 2, 'variantes' => 0, 'annonces' => 30] && $ghosts === 0,
        sprintf('hygiène : leads=%d favoris=%d événements=%d variantes=%d annonces=%d, fantômes=%d (invariants A/B intacts, INTEG-1 au vert — aucune écriture)',
            $hygiene['leads'], $hygiene['favoris'], $hygiene['evenements'], $hygiene['variantes'], $hygiene['annonces'], $ghosts));

} catch (Throwable $e) {
    $assert('FE-999', false, 'exception inattendue : ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'vestige-extinction-contract (lot F)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/vestige-extinction-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
