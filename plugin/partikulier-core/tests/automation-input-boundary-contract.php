<?php
declare(strict_types=1);

/** Input boundary checks that must fail before parsing or persistence. */
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

use Partikulier\Core\Domain\Automation\AutomationService;

$request = new WP_REST_Request('POST', '/partikulier/v1/automation-event');
$request->set_body(str_repeat('x', AutomationService::MAX_HMAC_BODY_BYTES + 1));
$verdict = AutomationService::check_automation_secret($request);
$passed = is_wp_error($verdict)
    && $verdict->get_error_code() === 'pk_automation_body_too_large'
    && (int) ($verdict->get_error_data()['status'] ?? 0) === 413;

echo json_encode([
    'suite' => 'automation-input-boundary-contract',
    'commit' => $commit,
    'status' => $passed ? 'PASS' : 'FAIL',
    'total' => 1,
    'passed' => (int) $passed,
    'failed' => (int) !$passed,
    'results' => [[
        'test_id' => 'AUTO-INPUT-001',
        'status' => $passed ? 'PASS' : 'FAIL',
        'detail' => 'Oversized HMAC body rejected with HTTP 413 before key lookup or persistence',
    ]],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($passed ? 0 : 1);
