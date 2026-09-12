<?php
/**
 * Contrat de périmètre des modules (lot D, CDC v1.2 — CA-4 / annexe C).
 *
 * Arbitrage du commanditaire « Référence + plugin » : le contenu nominal du
 * lot D (fractionnement des deux monolithes, fusionné aux lots B6/C2) est
 * livré depuis la 6.19.0 ; l'écart constaté à l'inventaire (15 fichiers thème
 * préexistants >400 lignes, identiques au T0) est documenté et gelé ci-dessous
 * — il relève de la dette préexistante, hors périmètre de la campagne. En
 * revanche, les QUATRE services du plugin dépassant 400 lignes sont du code
 * porté par la campagne (lots A/B1/B2/B4) : le lot D les découpe en shells +
 * traits d'au plus 300 lignes, sur le précédent B6 (class-localization.php
 * 990 → 199 l.) — méthodes déplacées VERBATIM, API publique, hooks et
 * constants portés par la classe shell inchangée.
 *
 * Assertions DA-001 à DA-011 :
 *  - DA-001 : métrique CA-4 côté plugin — zéro fichier PHP >400 lignes dans
 *    src/ (runtime, hors tests) ;
 *  - DA-002 : modules du lot D (4 shells + 11 traits) ≤300 lignes chacun ;
 *  - DA-003..006 : les quatre shells composent leurs traits et portent leur
 *    API publique intégrale (aucune méthode perdue au découpage) ;
 *  - DA-007 : VERBATIM — chaque méthode déplacée résout vers SON fichier de
 *    trait (ReflectionMethod : preuve que le shell ne la porte plus en
 *    double et que le trait la fournit) ;
 *  - DA-008 : ordre de chargement — les require_once des traits précèdent
 *    ceux des classes shells dans le bootstrap ;
 *  - DA-009 : baseline thème GELÉE, actualisée au lot F — exactement les 13
 *    fichiers préexistants énumérés (aucun nouveau) : l'extinction des 8
 *    vestiges (17 CREATE TABLE) fait repasser class-buyer-qualification.php
 *    (619 → 167 l.) et class-n8n-security.php (421 → 154 l.) sous le seuil
 *    de 400 lignes ; exclusions documentées : pk-diagnostic.php (outil de
 *    diagnostic, 2623 l., cloisonné au lot E) et
 *    estatik4/front/property/single.php (gabarit de substitution Estatik) ;
 *  - DA-010 : santé — plugin 2.10.3, thème 6.20.2 (lot F — extinction finale),
 *    schéma figé 2.6.0, 8/8 domaines, 0 collision ;
 *  - DA-011 : hygiène du banc — les invariants des lots A/B restent intacts
 *    (le contrat est de lecture seule : aucune écriture).
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/module-perimeter-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\Leads\LeadService;
use Partikulier\Core\Domain\Payments\PaymentService;
use Partikulier\Core\Domain\Automation\AutomationService;
use Partikulier\Core\Integration\ListingSynchronizer;
use Partikulier\Core\HealthCheck;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$pluginRoot = dirname(__DIR__);
$lineCount = static function (string $path): int {
    $content = (string) file_get_contents($path);
    return substr_count($content, "\n") + 1;
};

/* DA-001 — métrique CA-4 plugin : zéro fichier runtime >400 lignes dans src/. */
$oversizedPlugin = [];
$pluginFiles = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($pluginRoot . '/src', FilesystemIterator::SKIP_DOTS));
foreach ($pluginFiles as $file) {
    $path = (string) $file;
    if (substr($path, -4) !== '.php') { continue; }
    if ($lineCount($path) > 400) { $oversizedPlugin[] = basename($path) . ' (' . $lineCount($path) . ' l.)'; }
}
$assert('DA-001', $oversizedPlugin === [],
    $oversizedPlugin === [] ? 'plugin src/ : zéro fichier PHP >400 lignes (métrique CA-4 satisfaite côté plugin)' : 'fichiers >400 l. : ' . implode(', ', $oversizedPlugin));

/* DA-002 — modules du lot D : 4 shells + 11 traits, chacun ≤300 lignes. */
$lotDModules = [
    'src/Domain/Leads/LeadService.php',
    'src/Domain/Leads/LeadsContactTrait.php',
    'src/Domain/Leads/LeadsRestTrait.php',
    'src/Domain/Leads/LeadsPrivacyTrait.php',
    'src/Domain/Leads/LeadsAdminTrait.php',
    'src/Domain/Payments/PaymentService.php',
    'src/Domain/Payments/PaymentsOrdersTrait.php',
    'src/Domain/Payments/PaymentsSubscriptionsTrait.php',
    'src/Domain/Automation/AutomationService.php',
    'src/Domain/Automation/AutomationPolicyTrait.php',
    'src/Domain/Automation/AutomationHmacTrait.php',
    'src/Integration/ListingSynchronizer.php',
    'src/Integration/SynchronizerHooksTrait.php',
    'src/Integration/SynchronizerProjectionTrait.php',
    'src/Integration/SynchronizerMaintenanceTrait.php',
];
$moduleReport = [];
$modulesOk = true;
foreach ($lotDModules as $rel) {
    $n = $lineCount($pluginRoot . '/' . $rel);
    $moduleReport[] = basename($rel) . '=' . $n;
    if ($n > 300) { $modulesOk = false; }
}
$assert('DA-002', $modulesOk, 'modules lot D ≤300 l. : ' . implode(', ', $moduleReport));

/* DA-003..006 — shells : traits composés + API publique intégrale. */
$apiMatrix = [
    ['DA-003', LeadService::class, ['LeadsContactTrait', 'LeadsRestTrait', 'LeadsPrivacyTrait', 'LeadsAdminTrait'],
        ['table', 'leads_table', 'daily_limit', 'reference_for', 'authorize_contact', 'register_api_lead', 'rest_contact_authorization', 'rest_preferences', 'rest_consent', 'rest_opt_out', 'rest_erase_request', 'handle_stop', 'retention_days', 'maybe_schedule_retention', 'lead_id_for_phone', 'erase_lead', 'purge_expired', 'has_active_consent', 'admin_summary', 'admin_rows', 'followup_status_values', 'update_followup', 'lead_id_for_wa_id', 'decrypt_phone_for_admin']],
    ['DA-004', PaymentService::class, ['PaymentsOrdersTrait', 'PaymentsSubscriptionsTrait'],
        ['orders_table', 'subscriptions_table', 'is_gateway_enabled', 'create_order', 'record_order', 'get_order', 'update_order', 'mark_order_failed', 'mark_order_paid', 'delete_order', 'create_subscription', 'get_subscription', 'update_subscription', 'activate_subscription', 'revoke_subscription', 'delete_subscription']],
    ['DA-005', AutomationService::class, ['AutomationPolicyTrait', 'AutomationHmacTrait'],
        ['events_table', 'audit_table', 'env_secret', 'env_webhook', 'settings', 'get', 'secret_keys', 'maybe_migrate', 'save_admin_settings', 'outgoing_headers', 'check_automation_secret', 'audit_failure', 'receive_event', 'rest_receive_event', 'is_strong_secret', 'is_https_url']],
    ['DA-006', ListingSynchronizer::class, ['SynchronizerHooksTrait', 'SynchronizerProjectionTrait', 'SynchronizerMaintenanceTrait'],
        ['register', 'onSavePost', 'onTransitionStatus', 'onDeletePost', 'onMetaChange', 'flush', 'project', 'upsertProjected', 'rebuild', 'reconcile', 'stats', 'unscheduleLegacyCron']],
];
foreach ($apiMatrix as [$id, $class, $traits, $methods]) {
    $composed = array_map(static fn($t): string => substr($t, (int) strrpos($t, '\\') + 1) ?: $t, array_keys(class_uses($class)));
    $missing = array_values(array_filter($methods, static fn($m): bool => !method_exists($class, $m)));
    $short = substr($class, (int) strrpos($class, '\\') + 1);
    $assert($id, $missing === [] && $traits === $composed,
        sprintf('%s : traits %s composés, API publique %d/%d méthodes%s',
            $short, implode('+', $composed), count($methods) - count($missing), count($methods),
            $missing === [] ? '' : ' — MANQUANTES : ' . implode(', ', $missing)));
}

/* DA-007 — VERBATIM : chaque méthode déplacée résout vers SON fichier de trait. */
$movedMap = [
    LeadService::class => [
        'LeadsContactTrait.php' => ['authorize_contact', 'register_api_lead', 'seed_followup'],
        'LeadsRestTrait.php' => ['rest_contact_authorization', 'rest_preferences', 'rest_consent', 'rest_opt_out', 'rest_erase_request', 'handle_stop'],
        'LeadsPrivacyTrait.php' => ['retention_days', 'maybe_schedule_retention', 'lead_id_for_phone', 'erase_lead', 'purge_expired', 'has_active_consent', 'lead_id_for_wa_id', 'normalize_phone', 'encrypt_phone', 'decrypt_phone_for_admin'],
        'LeadsAdminTrait.php' => ['admin_summary', 'admin_rows', 'followup_status_values', 'update_followup'],
    ],
    PaymentService::class => [
        'PaymentsOrdersTrait.php' => ['create_order', 'record_order', 'get_order', 'update_order', 'mark_order_failed', 'mark_order_paid', 'delete_order'],
        'PaymentsSubscriptionsTrait.php' => ['create_subscription', 'get_subscription', 'update_subscription', 'activate_subscription', 'revoke_subscription', 'delete_subscription'],
    ],
    AutomationService::class => [
        'AutomationPolicyTrait.php' => ['events_table', 'audit_table', 'env_secret', 'env_webhook', 'settings', 'get', 'secret_keys', 'maybe_migrate', 'save_admin_settings'],
        'AutomationHmacTrait.php' => ['outgoing_headers', 'check_automation_secret', 'audit_failure', 'hmac_key', 'is_strong_secret', 'is_https_url', 'get_normalized_header'],
    ],
    ListingSynchronizer::class => [
        'SynchronizerHooksTrait.php' => ['register', 'onSavePost', 'onTransitionStatus', 'onDeletePost', 'onMetaChange', 'enqueueUpsert', 'enqueueDelete'],
        'SynchronizerProjectionTrait.php' => ['flush', 'belowLoopCap', 'project', 'projectedStatus', 'upsertProjected'],
        'SynchronizerMaintenanceTrait.php' => ['rebuild', 'reconcile', 'invalidateSearchCache', 'recordStats', 'stats', 'unscheduleLegacyCron'],
    ],
];
$verbatimErrors = [];
$verbatimCount = 0;
foreach ($movedMap as $class => $traitFiles) {
    foreach ($traitFiles as $traitFile => $methods) {
        foreach ($methods as $method) {
            $ref = new ReflectionMethod($class, $method);
            $definedIn = basename((string) $ref->getFileName());
            $verbatimCount++;
            if ($definedIn !== $traitFile) {
                $verbatimErrors[] = $class . '::' . $method . ' → ' . $definedIn . ' (attendu ' . $traitFile . ')';
            }
        }
    }
}
$assert('DA-007', $verbatimErrors === [],
    $verbatimErrors === []
        ? sprintf('%d méthodes déplacées : chacune résout vers son fichier de trait (VERBATIM, aucun double portage shell)', $verbatimCount)
        : 'résolutions inattendues : ' . implode(' ; ', array_slice($verbatimErrors, 0, 5)));

/* DA-008 — ordre de chargement : les traits précèdent leurs classes au bootstrap. */
$bootstrap = (string) file_get_contents($pluginRoot . '/partikulier-core.php');
$orderPairs = [
    ['SynchronizerHooksTrait.php', 'ListingSynchronizer.php'],
    ['SynchronizerProjectionTrait.php', 'ListingSynchronizer.php'],
    ['SynchronizerMaintenanceTrait.php', 'ListingSynchronizer.php'],
    ['PaymentsOrdersTrait.php', 'PaymentService.php'],
    ['PaymentsSubscriptionsTrait.php', 'PaymentService.php'],
    ['LeadsContactTrait.php', 'LeadService.php'],
    ['LeadsRestTrait.php', 'LeadService.php'],
    ['LeadsPrivacyTrait.php', 'LeadService.php'],
    ['LeadsAdminTrait.php', 'LeadService.php'],
    ['AutomationPolicyTrait.php', 'AutomationService.php'],
    ['AutomationHmacTrait.php', 'AutomationService.php'],
];
$orderErrors = [];
foreach ($orderPairs as [$traitFile, $classFile]) {
    $traitPos = strpos($bootstrap, $traitFile);
    $classPos = strpos($bootstrap, $classFile);
    if ($traitPos === false || $classPos === false || $traitPos > $classPos) {
        $orderErrors[] = $traitFile . ' ne précède pas ' . $classFile;
    }
}
$assert('DA-008', $orderErrors === [],
    $orderErrors === [] ? 'bootstrap : les 11 require_once de traits précèdent leurs 4 classes shells' : 'ordre incorrect : ' . implode(' ; ', $orderErrors));

/* DA-009 — baseline thème gelée, actualisée au lot F : exactement les 13 fichiers préexistants (extinction des 8 vestiges : buyer-qualification et n8n-security sous le seuil). */
$themeRoot = (string) get_template_directory();
$frozenBaseline = [
    'inc/class-listing-approval.php',
    'inc/class-form.php',
    'inc/class-page-doctor.php',
    'inc/class-search-filters.php',
    'inc/class-jsonld.php',
    'inc/class-listing-urls.php',
    'inc/class-upgrade-wizard.php',
    'inc/class-listing-preview.php',
    'inc/class-seo.php',
    'inc/class-cache.php',
    'inc/class-settings.php',
    'inc/class-morocco-places.php',
    'inc/class-leads-admin.php',
];
$documentedExclusions = [ // hors métrique : outil de diagnostic (traitement lot E) et gabarit de substitution Estatik
    'pk-diagnostic.php',
    'estatik4/front/property/single.php',
];
$themeOversized = [];
$themeIterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($themeRoot, FilesystemIterator::SKIP_DOTS));
foreach ($themeIterator as $file) {
    $path = (string) $file;
    if (substr($path, -4) !== '.php') { continue; }
    $rel = str_replace('\\', '/', substr($path, strlen($themeRoot) + 1));
    if (str_contains($rel, 'tests/') || str_contains($rel, 'templates/') || str_contains($rel, 'template-parts/')) { continue; }
    if (in_array($rel, $documentedExclusions, true)) { continue; }
    if ($lineCount($path) > 400) { $themeOversized[] = $rel; }
}
sort($themeOversized);
sort($frozenBaseline);
$assert('DA-009', $themeOversized === $frozenBaseline,
    $themeOversized === $frozenBaseline
        ? sprintf('baseline thème gelée (actualisée lot F) : %d fichiers préexistants >400 l. (extinction des 8 vestiges — buyer-qualification et n8n-security sous le seuil) — exclusions documentées : %s', count($frozenBaseline), implode(', ', $documentedExclusions))
        : 'écart à la baseline : +' . implode(', ', array_diff($themeOversized, $frozenBaseline)) . ' / -' . implode(', ', array_diff($frozenBaseline, $themeOversized)));

/* DA-010 — santé : plugin 2.10.3 (lot F), thème 6.20.2, schéma figé, 8/8, 0 collision. */
$themeVersion = wp_get_theme()->get('Version');
$health = (new HealthCheck())->get();
$pluginDomains = count(array_filter($health['domains'] ?? [], static fn($d): bool => ($d['owner'] ?? '') === 'plugin'));
$assert('DA-010', PARTIKULIER_CORE_VERSION === '2.10.3' && $themeVersion === '6.20.2'
    && \Partikulier\Core\Database\Schema::VERSION === '2.6.0' && ($health['status'] ?? '') === 'ok'
    && $pluginDomains === 8 && (int) ($health['routes']['collisions'] ?? -1) === 0,
    sprintf('santé : %s, plugin %s, thème %s (lot F — extinction finale), schéma %s (zéro migration), %d/8 domaines, 0 collision',
        ($health['status'] ?? '?'), PARTIKULIER_CORE_VERSION, $themeVersion, \Partikulier\Core\Database\Schema::VERSION, $pluginDomains));

/* DA-011 — hygiène du banc (contrat de lecture seule : aucune écriture). */
global $wpdb;
$prefix = $wpdb->prefix;
$hygiene = [
    'leads' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_buyer_leads"),
    'favoris' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_property_saves"),
    'evenements' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_automation_events"),
    'variantes' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_property_variants"),
    'annonces' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_listings"),
];
$assert('DA-011', $hygiene === ['leads' => 10, 'favoris' => 4, 'evenements' => 2, 'variantes' => 0, 'annonces' => 30],
    sprintf('hygiène : leads=%d favoris=%d événements=%d variantes=%d annonces=%d (invariants des lots A/B intacts)',
        $hygiene['leads'], $hygiene['favoris'], $hygiene['evenements'], $hygiene['variantes'], $hygiene['annonces']));

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'module-perimeter-contract (lot D)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/module-perimeter-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
