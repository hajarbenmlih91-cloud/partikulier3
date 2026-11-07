<?php
/**
 * Runtime security regressions for automation delivery.
 *
 * Run only against a disposable WordPress installation with its plugin and
 * theme active. Covers simultaneous event replays and outbound HTTP safety;
 * it does not contact a real n8n service or use production data.
 *
 * Required: PK_WP_DIR, PK_BASE, PK_COMMIT.
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$base = rtrim((string) (getenv('PK_BASE') ?: ''), '/');
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "PK_WP_DIR must point to disposable WordPress\n");
    exit(2);
}
if (!preg_match('#^https?://#', $base)) {
    fwrite(STDERR, "PK_BASE must point to the disposable WordPress HTTP service\n");
    exit(2);
}
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) {
    fwrite(STDERR, "PK_COMMIT must be a full git SHA\n");
    exit(2);
}
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\Automation\AutomationService;

$results = [];
$assert = static function (string $id, bool $ok, string $detail) use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

function pk_runtime_hmac_key(string $secret): string
{
    $secret = trim($secret);
    $decoded = base64_decode($secret, true);
    if (is_string($decoded) && strlen($decoded) >= 32) {
        return $decoded;
    }
    if (preg_match('/^[a-f0-9]{64,}$/i', $secret)) {
        $hex = hex2bin(substr($secret, 0, strlen($secret) - (strlen($secret) % 2)));
        if (is_string($hex) && strlen($hex) >= 32) {
            return $hex;
        }
    }
    return $secret;
}

function pk_runtime_http_response(int $status, string $message): array
{
    return [
        'headers' => [],
        'body' => '',
        'response' => ['code' => $status, 'message' => $message],
        'cookies' => [],
        'filename' => null,
    ];
}

global $wpdb;
$run = bin2hex(random_bytes(8));
$eventId = 'n8n-runtime-concurrent-' . $run;
$settingsBackup = get_option(AutomationService::SETTINGS_OPTION, null);
$createdPostId = 0;
$retryPostId = 0;
$httpCalls = [];
$retryCallCount = 0;
$mockHttp = static function ($preempt, array $args, string $url) use (&$httpCalls, &$retryCallCount) {
    $httpCalls[] = ['args' => $args, 'url' => $url];
    if ('https://8.8.8.8/n8n-test-hook' === $url) {
        return pk_runtime_http_response(204, 'No Content');
    }
    if ('https://8.8.8.8/n8n-retry-hook' === $url) {
        $retryCallCount++;
        if (1 === $retryCallCount) {
            return new WP_Error('http_request_failed', 'Synthetic n8n timeout');
        }
        return pk_runtime_http_response(204, 'No Content');
    }
    // Never let this test make a real network request, even if the HTTP API
    // invokes the pre-request filter before rejecting an unsafe destination.
    return new WP_Error('pk_runtime_http_intercept', 'Synthetic outbound request intercepted by contract');
};

try {
    if (!function_exists('curl_multi_init')) {
        $assert('AUTO-CONCURRENCY', false, 'PHP cURL multi extension is unavailable');
    } else {
        $secret = (string) AutomationService::get('automation_api_secret');
        $keys = AutomationService::secret_keys();
        $keyId = (string) array_key_first($keys);
        if ($secret === '' || $keyId === '' || !isset($keys[$keyId])) {
            $assert('AUTO-CONCURRENCY', false, 'disposable HMAC key is not configured');
        } else {
            $timestamp = (string) time();
            $body = wp_json_encode([
                'event_id' => $eventId,
                'event_type' => 'whatsapp_status',
                'source' => 'n8n',
                'payload' => ['runtime_probe' => $run],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($body)) {
                throw new RuntimeException('Could not encode the synthetic event');
            }
            $canonical = "POST\n/partikulier/v1/automation-event\n{$timestamp}\n{$body}";
            $signature = 'sha256=' . hash_hmac('sha256', $canonical, pk_runtime_hmac_key((string) $keys[$keyId]));
            $headers = [
                'Content-Type: application/json',
                'Host: wordpress',
                'X-Partikulier-Automation: ' . $secret,
                'X-Partikulier-Timestamp: ' . $timestamp,
                'X-Partikulier-Key-Id: ' . $keyId,
                'X-Partikulier-Signature: ' . $signature,
            ];

            $multi = curl_multi_init();
            $handles = [];
            $requests = 12;
            for ($i = 0; $i < $requests; $i++) {
                $handle = curl_init($base . '/wp-json/partikulier/v1/automation-event');
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $body,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                curl_multi_add_handle($multi, $handle);
                $handles[] = $handle;
            }
            do {
                $multiStatus = curl_multi_exec($multi, $running);
                if ($multiStatus > CURLM_OK) {
                    break;
                }
                if ($running > 0 && curl_multi_select($multi, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($running > 0);

            $codes = [];
            $duplicates = [];
            $allAccepted = true;
            foreach ($handles as $handle) {
                $codes[] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $raw = curl_multi_getcontent($handle);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($decoded) || ($decoded['accepted'] ?? false) !== true
                    || !array_key_exists('duplicate', $decoded)) {
                    $allAccepted = false;
                } else {
                    $duplicates[] = (bool) $decoded['duplicate'];
                }
                curl_multi_remove_handle($multi, $handle);
                curl_close($handle);
            }
            curl_multi_close($multi);

            $table = AutomationService::events_table();
            $storedRows = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE event_id = %s",
                $eventId
            ));
            $oneFirst = count(array_filter($duplicates, static fn(bool $duplicate): bool => !$duplicate)) === 1;
            $allRemainingDuplicates = count($duplicates) === $requests
                && count(array_filter($duplicates, static fn(bool $duplicate): bool => $duplicate)) === $requests - 1;
            $assert('AUTO-CONCURRENCY', $multiStatus === CURLM_OK
                && count($codes) === $requests
                && count(array_filter($codes, static fn(int $code): bool => $code === 200)) === $requests
                && $allAccepted && $oneFirst && $allRemainingDuplicates && $storedRows === 1,
                sprintf('%d simultaneous signed requests: HTTP 200 count=%d; one initial accept, %d duplicates; stored rows=%d',
                    $requests,
                    count(array_filter($codes, static fn(int $code): bool => $code === 200)),
                    count(array_filter($duplicates, static fn(bool $duplicate): bool => $duplicate)),
                    $storedRows));
        }
    }

    // Exercise the real outgoing sender with an intercepted public HTTPS URL,
    // then prove a loopback URL is rejected before the HTTP transport runs.
    if (class_exists('Partikulier_Listing_Approval') && (string) AutomationService::get('automation_api_secret') !== '') {
        $settings = AutomationService::settings();
        $settings['n8n_webhook_url'] = 'https://8.8.8.8/n8n-test-hook';
        update_option(AutomationService::SETTINGS_OPTION, $settings, false);
        $createdPostId = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'private',
            'post_title' => 'Disposable n8n outbound boundary test',
        ], true);
        if (is_wp_error($createdPostId) || !$createdPostId) {
            throw new RuntimeException('Could not create disposable post');
        }
        $createdPostId = (int) $createdPostId;
        add_filter('pre_http_request', $mockHttp, 10, 3);
        $credentials = [
            'display_name' => 'Synthetic owner',
            'phone' => '0000000000',
            'email' => 'owner@example.invalid',
            'login' => 'synthetic-user',
            'password' => 'synthetic-one-time-password',
            'login_url' => 'https://partikulier.invalid/login',
        ];
        $sent = Partikulier_Listing_Approval::notify_n8n($createdPostId, $credentials, 'runtime-safe-destination');
        $call = $httpCalls[0] ?? null;
        $safeOptions = is_array($call)
            && ($call['url'] ?? '') === 'https://8.8.8.8/n8n-test-hook'
            && (int) ($call['args']['redirection'] ?? -1) === 0
            && ($call['args']['reject_unsafe_urls'] ?? false) === true
            && ($call['args']['sslverify'] ?? false) === true
            && (int) ($call['args']['timeout'] ?? 0) <= 8;
        $assert('AUTO-OUTBOUND-SAFE', $sent && $safeOptions
            && get_post_meta($createdPostId, '_pk_n8n_status', true) === 'sent',
            'public HTTPS destination intercepted; unsafe-URL rejection and TLS verification enabled; redirects disabled');

        // Simulate an n8n timeout, then an operator retry with the same request
        // id. The request body must remain semantically identical and errors
        // must not persist credentials or the HMAC secret.
        $settings['n8n_webhook_url'] = 'https://8.8.8.8/n8n-retry-hook';
        update_option(AutomationService::SETTINGS_OPTION, $settings, false);
        $retryPostId = wp_insert_post([
            'post_type' => 'post',
            'post_status' => 'private',
            'post_title' => 'Disposable n8n retry boundary test',
        ], true);
        if (is_wp_error($retryPostId) || !$retryPostId) {
            throw new RuntimeException('Could not create disposable retry post');
        }
        $retryPostId = (int) $retryPostId;
        $retryRequestId = 'stable-runtime-retry-' . $run;
        $callsBeforeRetry = count($httpCalls);
        $firstAttempt = Partikulier_Listing_Approval::notify_n8n($retryPostId, $credentials, $retryRequestId);
        $firstFailure = get_post_meta($retryPostId, '_pk_n8n_error', true);
        $firstAttemptSafe = !$firstAttempt
            && get_post_meta($retryPostId, '_pk_n8n_status', true) === 'error'
            && !metadata_exists('post', $retryPostId, '_pk_n8n_sent')
            && is_string($firstFailure)
            && false === strpos($firstFailure, (string) $credentials['password'])
            && false === strpos($firstFailure, $secret);
        $secondAttempt = Partikulier_Listing_Approval::notify_n8n($retryPostId, $credentials, $retryRequestId);
        $retryCalls = array_slice($httpCalls, $callsBeforeRetry);
        $firstPayload = isset($retryCalls[0]['args']['body']) ? json_decode((string) $retryCalls[0]['args']['body'], true) : null;
        $secondPayload = isset($retryCalls[1]['args']['body']) ? json_decode((string) $retryCalls[1]['args']['body'], true) : null;
        if (is_array($firstPayload)) {
            unset($firstPayload['sent_at']);
        }
        if (is_array($secondPayload)) {
            unset($secondPayload['sent_at']);
        }
        $retryPayloadStable = is_array($firstPayload) && is_array($secondPayload)
            && $firstPayload === $secondPayload
            && ($secondPayload['resend_request_id'] ?? '') === $retryRequestId;
        $assert('AUTO-OUTBOUND-RETRY', $firstAttemptSafe && $secondAttempt && $retryCallCount === 2
            && count($retryCalls) === 2 && $retryPayloadStable
            && get_post_meta($retryPostId, '_pk_n8n_status', true) === 'sent'
            && metadata_exists('post', $retryPostId, '_pk_n8n_sent')
            && !metadata_exists('post', $retryPostId, '_pk_n8n_error'),
            'synthetic timeout records failure without secrets; explicit retry succeeds with same request id and semantic payload');

        $privateUrl = 'https://127.0.0.1/private-hook';
        $settings['n8n_webhook_url'] = $privateUrl;
        update_option(AutomationService::SETTINGS_OPTION, $settings, false);
        $callsBeforePrivate = count($httpCalls);
        $validatorRejected = false === wp_http_validate_url($privateUrl);
        $blocked = Partikulier_Listing_Approval::notify_n8n($createdPostId, $credentials, 'runtime-private-destination');
        $privateCalls = array_slice($httpCalls, $callsBeforePrivate);
        $privateCallIntercepted = [] === $privateCalls || (
            count($privateCalls) === 1
            && ($privateCalls[0]['url'] ?? '') === $privateUrl
            && ($privateCalls[0]['args']['reject_unsafe_urls'] ?? false) === true
        );
        $assert('AUTO-OUTBOUND-PRIVATE-BLOCK', !$blocked && $validatorRejected && $privateCallIntercepted
            && get_post_meta($createdPostId, '_pk_n8n_status', true) === 'error',
            'WordPress safe-URL validator rejects loopback; any pre-request hook is intercepted and marked unsafe');
    } else {
        $assert('AUTO-OUTBOUND-SAFE', false, 'theme sender or disposable HMAC key unavailable');
        $assert('AUTO-OUTBOUND-PRIVATE-BLOCK', false, 'theme sender or disposable HMAC key unavailable');
    }
} catch (Throwable $error) {
    $assert('AUTO-RUNTIME-EXCEPTION', false, 'runtime boundary contract raised ' . get_class($error));
} finally {
    remove_filter('pre_http_request', $mockHttp, 10);
    if ($createdPostId > 0) {
        wp_delete_post($createdPostId, true);
    }
    if ($retryPostId > 0) {
        wp_delete_post($retryPostId, true);
    }
    if (null === $settingsBackup) {
        delete_option(AutomationService::SETTINGS_OPTION);
    } else {
        update_option(AutomationService::SETTINGS_OPTION, $settingsBackup, false);
    }
    $wpdb->delete(AutomationService::events_table(), ['event_id' => $eventId], ['%s']);
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}pk_audit_log WHERE action IN ('automation_event_received','automation_event_duplicate') AND metadata_json LIKE %s",
        '%' . $eventId . '%'
    ));
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'automation-runtime-boundary-contract',
    'commit' => $commit,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
