<?php
declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\AuditLogger;
use Partikulier\Core\ListingPolicy;
use Partikulier\Core\ListingRepository;
use Partikulier\Core\ListingService;
use Partikulier\Core\PrivacyService;
use Partikulier\Core\TranslationService;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void { $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail]; };

$repo = new ListingRepository();
$audit = new AuditLogger();
$service = new ListingService($repo, $audit);
$invalidEmpty = $service->create(['title' => '', 'description' => '', 'locale' => 'fr', 'price' => 1, 'area' => 1], 1);
$assert('CORE-SERVICE-001', is_wp_error($invalidEmpty) && $invalidEmpty->get_error_code() === 'invalid_listing' && $invalidEmpty->get_error_data()['status'] === 422, 'empty listing rejected');
$invalidLocale = $service->create(['title' => 'x', 'description' => 'y', 'locale' => 'xx', 'price' => 1, 'area' => 1], 1);
$assert('CORE-SERVICE-002', is_wp_error($invalidLocale) && $invalidLocale->get_error_data()['status'] === 422, 'unsupported locale rejected');
$createdId = $service->create(['title' => 'service contract fixture', 'description' => 'temporary fixture', 'locale' => 'fr', 'price' => 10, 'area' => 5], 1);
$assert('CORE-SERVICE-003', is_int($createdId) && $createdId > 0, 'valid listing created');

$policy = new ListingPolicy();
wp_set_current_user(0);
$anonymousCreate = $policy->canCreate();
wp_set_current_user(1);
$authenticatedCreate = $policy->canCreate();
$assert('CORE-POLICY-001', $policy->canReadPublic() === true && $anonymousCreate === false && $authenticatedCreate === true, 'public and authenticated permission matrix');

$translation = new TranslationService();
$assert('CORE-TRANSLATION-001', $translation->normalizeLocale('fr_FR') === 'fr' && $translation->normalizeLocale('en-US') === 'en' && $translation->normalizeLocale('ar-MA') === 'ar' && $translation->normalizeLocale('xx') === 'fr', 'locale normalization');

$privacy = new PrivacyService();
$redacted = $privacy->redact(['title' => 'visible', 'email' => 'private@example.test', 'phone' => '000', 'api_token' => 'secret', 'safe' => 'kept']);
$assert('CORE-PRIVACY-001', isset($redacted['title'], $redacted['safe']) && !isset($redacted['email'], $redacted['phone'], $redacted['api_token']), 'secret-like public fields redacted');

$correlation = $audit->record('service_contract', 'test', $createdId ?: null, ['safe' => 'kept', 'api_token' => 'must-not-persist']);
global $wpdb;
$row = $wpdb->get_row($wpdb->prepare('SELECT metadata_json FROM ' . $wpdb->prefix . 'pk_audit_log WHERE correlation_id = %s', $correlation), ARRAY_A);
$metadata = $row ? json_decode((string) $row['metadata_json'], true) : null;
$assert('CORE-AUDIT-001', is_array($metadata) && ($metadata['safe'] ?? '') === 'kept' && !array_key_exists('api_token', $metadata), 'audit metadata redacted');

if (is_int($createdId) && $createdId > 0) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT external_id FROM ' . $wpdb->prefix . 'pk_listings WHERE id = %d', $createdId), ARRAY_A);
    $wpdb->delete($wpdb->prefix . 'pk_listings', ['id' => $createdId], ['%d']);
    if ($row) {
        $postId = (int) substr((string) $row['external_id'], strlen('estatik:'));
        if ($postId > 0) {
            wp_delete_post($postId, true);
        }
    }
    (new \Partikulier\Core\Integration\ListingSynchronizer())->flush();
}
if ($correlation !== '') $wpdb->delete($wpdb->prefix . 'pk_audit_log', ['correlation_id' => $correlation], ['%s']);
wp_set_current_user(0);

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
$payload = [
    'test_id' => 'CORE-SERVICES-CONTRACT-001',
    'candidate_version' => getenv('PK_VERSION') ?: '1.7.1',
    'source_commit' => $commit,
    'source_ref' => getenv('GITHUB_REF') ?: 'local',
    'run_id' => getenv('PK_RUN_ID') ?: 'local',
    'started_at_utc' => $started,
    'finished_at_utc' => gmdate('c'),
    'command' => 'php partikulier-core/tests/services-contract.php',
    'fixture' => 'temporary core service records',
    'status' => $failed ? 'FAIL' : 'PASS',
    'exit_code' => $failed ? 1 : 0,
    'tests' => $results,
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'limitations' => ['theme bootstrap/display and production cache proxy behavior are separate gates'],
];
echo wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($failed ? 1 : 0);
