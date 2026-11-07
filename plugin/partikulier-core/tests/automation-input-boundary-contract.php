<?php
declare(strict_types=1);

/** Runtime contracts for bounded automation input and HMAC fail-closed behavior. */
$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "PK_WP_DIR must point to disposable WordPress\n");
    exit(2);
}
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) {
    fwrite(STDERR, "PK_COMMIT must be a full git SHA\n");
    exit(2);
}
require $wpDir . '/wp-load.php';

global $wpdb;
use Partikulier\Core\Domain\Automation\AutomationService;

$results = [];
$large = new WP_REST_Request('POST', '/partikulier/v1/automation-event');
$large->set_body(str_repeat('x', AutomationService::max_hmac_body_bytes() + 1));
$verdict = AutomationService::check_automation_secret($large);
$results[] = [
    'test_id' => 'AUTO-INPUT-001',
    'passed' => is_wp_error($verdict)
        && $verdict->get_error_code() === 'pk_automation_body_too_large'
        && (int) ($verdict->get_error_data()['status'] ?? 0) === 413,
    'detail' => 'Oversized HMAC body rejected with HTTP 413 before key lookup or persistence',
];
$minimumFilter = static fn($current) => 1;
add_filter('partikulier_automation_max_hmac_body_bytes', $minimumFilter, 10, 1);
$minimumLimit = AutomationService::max_hmac_body_bytes();
remove_filter('partikulier_automation_max_hmac_body_bytes', $minimumFilter, 10);
$maximumFilter = static fn($current) => PHP_INT_MAX;
add_filter('partikulier_automation_max_hmac_body_bytes', $maximumFilter, 10, 1);
$maximumLimit = AutomationService::max_hmac_body_bytes();
remove_filter('partikulier_automation_max_hmac_body_bytes', $maximumFilter, 10);
$results[] = [
    'test_id' => 'AUTO-INPUT-LIMIT-CLAMP-001',
    'passed' => $minimumLimit === 65536 && $maximumLimit === 10485760,
    'detail' => 'Body-limit filter is clamped between 64 KiB and 10 MiB',
];

$environment = function_exists('wp_get_environment_type') ? wp_get_environment_type() : '';
$activeKeys = AutomationService::secret_keys();
$activeKeyId = (string) array_key_first($activeKeys);
$sharedSecret = (string) ($activeKeys[$activeKeyId] ?? '');
$originalSettings = AutomationService::settings();
$logSettings = $originalSettings;
$logSettings['hmac_mode'] = 'log';
update_option('pk_n8n_settings', $logSettings, false);
$unknownKeyId = 'production-mode-' . bin2hex(random_bytes(4));
$productionRequest = new WP_REST_Request('POST', '/partikulier/v1/automation-event');
$productionRequest->set_body('{}');
$productionRequest->set_header('X-Partikulier-Automation', $sharedSecret);
$productionRequest->set_header('X-Partikulier-Timestamp', (string) time());
$productionRequest->set_header('X-Partikulier-Key-Id', $unknownKeyId);
$productionRequest->set_header('X-Partikulier-Signature', 'sha256=' . str_repeat('0', 64));
$productionVerdict = AutomationService::check_automation_secret($productionRequest);
update_option('pk_n8n_settings', $originalSettings, false);
$results[] = [
    'test_id' => 'AUTO-HMAC-PROD-LOG-001',
    'passed' => $environment === 'production'
        && $sharedSecret !== ''
        && is_wp_error($productionVerdict)
        && $productionVerdict->get_error_code() === 'pk_automation_signature'
        && (int) ($productionVerdict->get_error_data()['status'] ?? 0) === 401,
    'detail' => 'Production environment forces enforce even when persisted HMAC mode is log',
];

$originalRemoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
$testIp = '198.51.100.77';
$_SERVER['REMOTE_ADDR'] = $testIp;
$rateKeyId = 'rate-test-' . bin2hex(random_bytes(6));
$firstRequestsDenied = true;
$lastWasLimited = false;
$badSecretFirstDenied = true;
$badSecretLastLimited = false;
try {
    for ($attempt = 1; $attempt <= AutomationService::MAX_HMAC_FAILURES_PER_MINUTE + 1; $attempt++) {
        $request = new WP_REST_Request('POST', '/partikulier/v1/automation-event');
        $request->set_body('{}');
        $request->set_header('X-Partikulier-Automation', $sharedSecret);
        $request->set_header('X-Partikulier-Timestamp', (string) time());
        $request->set_header('X-Partikulier-Key-Id', $rateKeyId);
        $request->set_header('X-Partikulier-Signature', 'sha256=' . str_repeat('0', 64));
        $response = AutomationService::check_automation_secret($request);
        $status = is_wp_error($response) ? (int) ($response->get_error_data()['status'] ?? 0) : 200;
        if ($attempt <= AutomationService::MAX_HMAC_FAILURES_PER_MINUTE) {
            $firstRequestsDenied = $firstRequestsDenied && $status === 401;
        } else {
            $lastWasLimited = is_wp_error($response)
                && $response->get_error_code() === 'pk_rate_limited'
                && $status === 429;
        }
    }
    for ($attempt = 1; $attempt <= AutomationService::MAX_HMAC_FAILURES_PER_MINUTE + 1; $attempt++) {
        $request = new WP_REST_Request('POST', '/partikulier/v1/automation-event');
        $request->set_header('X-Partikulier-Automation', 'synthetic-invalid-shared-secret');
        $response = AutomationService::check_automation_secret($request);
        $status = is_wp_error($response) ? (int) ($response->get_error_data()['status'] ?? 0) : 200;
        if ($attempt <= AutomationService::MAX_HMAC_FAILURES_PER_MINUTE) {
            $badSecretFirstDenied = $badSecretFirstDenied && $status === 401;
        } else {
            $badSecretLastLimited = is_wp_error($response)
                && $response->get_error_code() === 'pk_rate_limited'
                && $status === 429;
        }
    }
} finally {
    if ($originalRemoteAddr === null) {
        unset($_SERVER['REMOTE_ADDR']);
    } else {
        $_SERVER['REMOTE_ADDR'] = $originalRemoteAddr;
    }
    $route = preg_replace('/[^a-z0-9_-]/i', '_', '/partikulier/v1/automation-event') ?: 'route';
    foreach (['automation-hmac-signature-invalid', 'automation-hmac-auth-invalid'] as $bucket) {
        $limiterKey = 'pk_rl_' . md5($bucket . '|' . $route . '|ip:' . $testIp);
        delete_transient($limiterKey);
        if (function_exists('apcu_delete')) {
            apcu_delete($limiterKey . '_started');
            apcu_delete($limiterKey . '_count');
        }
    }
    $wpdb->delete(AutomationService::audit_table(), [
        'key_id' => $rateKeyId,
        'hour_key' => gmdate('Y-m-d H:00:00'),
    ], ['%s', '%s']);
}
$results[] = [
    'test_id' => 'AUTO-HMAC-RATE-001',
    'passed' => $sharedSecret !== '' && $firstRequestsDenied && $lastWasLimited && $badSecretFirstDenied && $badSecretLastLimited,
    'detail' => 'Per-IP limiter returns HTTP 429 after the budget for bad shared secrets and invalid signatures',
];

$passed = count(array_filter($results, static fn(array $row): bool => $row['passed']));
$total = count($results);
$publicResults = array_map(static fn(array $row): array => [
    'test_id' => $row['test_id'],
    'status' => $row['passed'] ? 'PASS' : 'FAIL',
    'detail' => $row['detail'],
], $results);
echo json_encode([
    'suite' => 'automation-input-boundary-contract',
    'commit' => $commit,
    'status' => $passed === $total ? 'PASS' : 'FAIL',
    'total' => $total,
    'passed' => $passed,
    'failed' => $total - $passed,
    'results' => $publicResults,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($passed === $total ? 0 : 1);
