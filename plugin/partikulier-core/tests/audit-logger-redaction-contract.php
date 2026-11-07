<?php
declare(strict_types=1);

/**
 * Runtime regression for recursive audit metadata secret removal.
 * Run only against a disposable WordPress installation.
 */

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
$sentinel = 'audit-redaction-' . bin2hex(random_bytes(24));
$audit = new \Partikulier\Core\AuditLogger();
$correlation = $audit->record('audit_redaction_contract', 'test', null, [
    'safe_label' => 'preserved',
    'top_level' => ['password' => $sentinel, 'safe' => 'kept'],
    'nested' => [
        'integration' => (object) [
            'api_token' => $sentinel,
            'api_key' => $sentinel,
            'details' => ['authorization' => $sentinel, 'session_cookie' => $sentinel, 'safe' => 'also-kept'],
        ],
    ],
]);

$row = $wpdb->get_row($wpdb->prepare(
    'SELECT metadata_json FROM ' . $wpdb->prefix . 'pk_audit_log WHERE correlation_id = %s LIMIT 1',
    $correlation
), ARRAY_A);
$encoded = is_array($row) ? (string) ($row['metadata_json'] ?? '') : '';
$metadata = json_decode($encoded, true);
$safeFieldsPreserved = is_array($metadata)
    && ($metadata['safe_label'] ?? null) === 'preserved'
    && ($metadata['top_level']['safe'] ?? null) === 'kept'
    && ($metadata['nested']['integration']['details']['safe'] ?? null) === 'also-kept';
$noSecretValue = '' !== $encoded && !str_contains($encoded, $sentinel);
$noSensitiveKeys = is_array($metadata)
    && !array_key_exists('password', $metadata['top_level'] ?? [])
    && !array_key_exists('api_token', $metadata['nested']['integration'] ?? [])
    && !array_key_exists('api_key', $metadata['nested']['integration'] ?? [])
    && !array_key_exists('authorization', $metadata['nested']['integration']['details'] ?? [])
    && !array_key_exists('session_cookie', $metadata['nested']['integration']['details'] ?? []);

$wpdb->delete($wpdb->prefix . 'pk_audit_log', ['correlation_id' => $correlation], ['%s']);
$passed = (int) $safeFieldsPreserved + (int) $noSecretValue + (int) $noSensitiveKeys;
$total = 3;
echo json_encode([
    'suite' => 'audit-logger-redaction-contract',
    'commit' => $commit,
    'status' => $passed === $total ? 'PASS' : 'FAIL',
    'total' => $total,
    'passed' => $passed,
    'failed' => $total - $passed,
    'results' => [
        ['test_id' => 'AUDIT-REDACT-001', 'status' => $safeFieldsPreserved ? 'PASS' : 'FAIL'],
        ['test_id' => 'AUDIT-REDACT-002', 'status' => $noSecretValue ? 'PASS' : 'FAIL'],
        ['test_id' => 'AUDIT-REDACT-003', 'status' => $noSensitiveKeys ? 'PASS' : 'FAIL'],
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($passed === $total ? 0 : 1);
