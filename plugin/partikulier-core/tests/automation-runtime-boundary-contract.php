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
use Partikulier\Core\Domain\Leads\LeadService;

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
$adminKeyBackup = get_option('pk_admin_secret_key', null);
$createdPostId = 0;
$retryPostId = 0;
$runtimeLeadId = 0;
$runtimePhone = '';
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

            // Exercise route-level atomic idempotency with simultaneous retries
            // against a lead created only in this disposable WordPress database.
            $runtimePhone = '2126' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
            $runtimeNow = current_time('mysql', true);
            $wpdb->insert(LeadService::leads_table(), [
                'phone_hash' => hash_hmac('sha256', $runtimePhone, wp_salt('auth')),
                'phone_encrypted' => base64_encode($runtimePhone),
                'first_seen_at' => $runtimeNow,
                'last_seen_at' => $runtimeNow,
            ], ['%s', '%s', '%s', '%s']);
            $runtimeLeadId = (int) $wpdb->insert_id;
            if ($runtimeLeadId <= 0) {
                throw new RuntimeException('Could not create disposable route-idempotency lead');
            }
            $preferenceMessageId = 'runtime-preferences-' . $run;
            $preferenceBody = wp_json_encode([
                'wa_id' => $runtimePhone,
                'provider_message_id' => $preferenceMessageId,
                'budget_max' => 640000,
                'areas' => ['Casablanca', 'Rabat'],
                'layout' => '2 pièces',
                'transaction' => 'achat',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($preferenceBody)) {
                throw new RuntimeException('Could not encode disposable preferences');
            }
            $preferenceTimestamp = (string) time();
            $preferenceCanonical = "POST\n/partikulier/v1/preferences\n" . $preferenceTimestamp . "\n" . $preferenceBody;
            $preferenceSignature = 'sha256=' . hash_hmac('sha256', $preferenceCanonical, pk_runtime_hmac_key((string) $keys[$keyId]));
            $preferenceHeaders = [
                'Content-Type: application/json',
                'Host: wordpress',
                'X-Partikulier-Automation: ' . $secret,
                'X-Partikulier-Timestamp: ' . $preferenceTimestamp,
                'X-Partikulier-Key-Id: ' . $keyId,
                'X-Partikulier-Signature: ' . $preferenceSignature,
            ];
            $preferenceMulti = curl_multi_init();
            $preferenceHandles = [];
            for ($i = 0; $i < $requests; $i++) {
                $handle = curl_init($base . '/wp-json/partikulier/v1/preferences');
                curl_setopt_array($handle, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => $preferenceBody,
                    CURLOPT_HTTPHEADER => $preferenceHeaders,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => 5,
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_FOLLOWLOCATION => false,
                ]);
                curl_multi_add_handle($preferenceMulti, $handle);
                $preferenceHandles[] = $handle;
            }
            do {
                $preferenceMultiStatus = curl_multi_exec($preferenceMulti, $preferenceRunning);
                if ($preferenceMultiStatus > CURLM_OK) {
                    break;
                }
                if ($preferenceRunning > 0 && curl_multi_select($preferenceMulti, 1.0) === -1) {
                    usleep(10000);
                }
            } while ($preferenceRunning > 0);
            $preferenceCodes = [];
            $initialPreferenceAccepts = 0;
            $preferenceReplays = 0;
            foreach ($preferenceHandles as $handle) {
                $preferenceCodes[] = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
                $raw = curl_multi_getcontent($handle);
                $decoded = is_string($raw) ? json_decode($raw, true) : null;
                if (($decoded['replayed'] ?? null) === false) {
                    $initialPreferenceAccepts++;
                } elseif (($decoded['replayed'] ?? null) === true) {
                    $preferenceReplays++;
                }
                curl_multi_remove_handle($preferenceMulti, $handle);
                curl_close($handle);
            }
            curl_multi_close($preferenceMulti);
            $preferenceMessageRows = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . LeadService::table('pk_whatsapp_messages') . ' WHERE provider_message_id = %s',
                $preferenceMessageId
            ));
            $preferenceRow = $wpdb->get_row($wpdb->prepare(
                'SELECT budget_max FROM ' . LeadService::table('pk_buyer_preferences') . ' WHERE lead_id = %d',
                $runtimeLeadId
            ), ARRAY_A);
            $assert('AUTO-PREFERENCES-CONCURRENCY', $preferenceMultiStatus === CURLM_OK
                && count($preferenceCodes) === $requests
                && count(array_filter($preferenceCodes, static fn(int $code): bool => 200 === $code)) === $requests
                && 1 === $initialPreferenceAccepts && $requests - 1 === $preferenceReplays
                && 1 === $preferenceMessageRows && is_array($preferenceRow) && 640000 === (int) $preferenceRow['budget_max'],
                sprintf('%d simultaneous signed /preferences retries: one mutation, %d acknowledged replays, one message row', $requests, $preferenceReplays));
        }
    }

    // No fallback login-gateway key may be shipped in code. Verify that an
    // unconfigured site keeps native login available, and an explicit option
    // still enables the configured key (unless wp-config defines one).
    if (class_exists('Partikulier_Security')) {
        $configuredConstant = defined('PK_ADMIN_SECRET_KEY') ? (string) constant('PK_ADMIN_SECRET_KEY') : '';
        if ('' === $configuredConstant) {
            delete_option('pk_admin_secret_key');
            $unconfiguredKey = Partikulier_Security::get_admin_secret_key();
            update_option('pk_admin_secret_key', 'runtime-only-private-gateway-key', false);
            $optionKey = Partikulier_Security::get_admin_secret_key();
            $assert('AUTO-ADMIN-GATEWAY-DEFAULT', '' === $unconfiguredKey
                && 'runtime-only-private-gateway-key' === $optionKey,
                'no configured key means no shipped fallback; an explicit option key is honored');
        } else {
            $assert('AUTO-ADMIN-GATEWAY-DEFAULT', $configuredConstant === Partikulier_Security::get_admin_secret_key(),
                'explicit wp-config key takes precedence over the option');
        }
    } else {
        $assert('AUTO-ADMIN-GATEWAY-DEFAULT', false, 'theme security gateway is not loaded');
    }

    // Admin-managed secret replacement must keep the previous key for a
    // bounded grace period and refuse overlapping rotations.
    if ('' === AutomationService::env_secret()) {
        $rotationOld = base64_encode(random_bytes(48));
        $rotationNew = base64_encode(random_bytes(48));
        $rotationNext = base64_encode(random_bytes(48));
        $rotationSettings = AutomationService::settings();
        $rotationSettings['automation_api_secret'] = $rotationOld;
        $rotationSettings['active_key_id'] = 'runtime-old';
        unset($rotationSettings['previous_secret'], $rotationSettings['previous_key_id'], $rotationSettings['previous_expires_at']);
        update_option(AutomationService::SETTINGS_OPTION, $rotationSettings, false);
        $rotationResult = AutomationService::save_admin_settings(['automation_api_secret' => $rotationNew]);
        $rotatedSettings = AutomationService::settings();
        $rotatedKeys = AutomationService::secret_keys();
        $expiry = strtotime((string) ($rotatedSettings['previous_expires_at'] ?? ''));
        $activeIdAfterRotation = (string) ($rotatedSettings['active_key_id'] ?? '');
        $sameSecretSave = AutomationService::save_admin_settings(['automation_api_secret' => $rotationNew]);
        $afterSameSecret = AutomationService::settings();
        $secondRotation = AutomationService::save_admin_settings(['automation_api_secret' => $rotationNext]);
        $expiredSettings = AutomationService::settings();
        $expiredSettings['previous_expires_at'] = gmdate('Y-m-d H:i:s', time() - 10);
        update_option(AutomationService::SETTINGS_OPTION, $expiredSettings, false);
        $keysAfterExpiry = AutomationService::secret_keys();
        $settingsAfterExpiry = AutomationService::settings();
        $assert('AUTO-HMAC-KEY-ROTATION', true === $rotationResult
            && true === $sameSecretSave && ($afterSameSecret['active_key_id'] ?? '') === $activeIdAfterRotation
            && ($rotatedSettings['automation_api_secret'] ?? '') === $rotationNew
            && ($rotatedSettings['previous_secret'] ?? '') === $rotationOld
            && ($rotatedSettings['previous_key_id'] ?? '') === 'runtime-old'
            && (string) ($rotatedSettings['active_key_id'] ?? '') !== 'runtime-old'
            && ($rotatedKeys[$activeIdAfterRotation] ?? '') === $rotationNew
            && ($rotatedKeys['runtime-old'] ?? '') === $rotationOld
            && $expiry > time() && $expiry <= time() + AutomationService::KEY_ROTATION_GRACE_SECONDS + 2
            && is_wp_error($secondRotation) && 'pk_n8n_rotation_in_progress' === $secondRotation->get_error_code()
            && !isset($keysAfterExpiry['runtime-old'])
            && !isset($settingsAfterExpiry['previous_secret'], $settingsAfterExpiry['previous_key_id'], $settingsAfterExpiry['previous_expires_at'])
            && (AutomationService::settings()['automation_api_secret'] ?? '') === $rotationNew,
            'admin secret replacement retains prior key for 24 hours, changes key ID, and prevents overlapping rotation');
    } else {
        $assert('AUTO-HMAC-KEY-ROTATION', false, 'runtime fixture requires DB-managed secret, not an environment-managed secret');
    }

    // Malformed qualification replies must fail closed instead of being
    // coerced to false ("intermédiaire") by WordPress boolean sanitization.
    $qualificationErrors = [];
    foreach ([null, 'maybe', [], 2] as $invalidQualification) {
        $qualificationRequest = new WP_REST_Request('POST', '/partikulier/v1/qualification');
        $qualificationRequest->set_param('wa_id', '212600000001');
        if (null !== $invalidQualification) {
            $qualificationRequest->set_param('is_particulier', $invalidQualification);
        }
        $qualificationResult = LeadService::rest_set_qualification($qualificationRequest);
        $qualificationErrors[] = is_wp_error($qualificationResult)
            && 'pk_invalid_qualification' === $qualificationResult->get_error_code()
            && 400 === (int) ($qualificationResult->get_error_data()['status'] ?? 0);
    }
    $assert('AUTO-QUALIFICATION-INVALID-INPUT', count(array_filter($qualificationErrors)) === 4,
        'missing, unknown, non-scalar, and out-of-range qualification replies return 400 without changing a lead');

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
    // Environment-managed rotations snapshot the current host key, then accept
    // the new host key ID while retaining the old ID only until the grace expiry.
    if (!defined('PARTIKULIER_N8N_SECRET') && !defined('PARTIKULIER_N8N_KEY_ID')) {
        $oldEnvSecret = getenv('PARTIKULIER_N8N_SECRET');
        $oldEnvKeyId = getenv('PARTIKULIER_N8N_KEY_ID');
        $envSettingsBackup = AutomationService::settings();
        try {
            $envOldSecret = base64_encode(random_bytes(48));
            $envOldKeyId = 'runtime-env-old';
            putenv('PARTIKULIER_N8N_SECRET=' . $envOldSecret);
            putenv('PARTIKULIER_N8N_KEY_ID=' . $envOldKeyId);
            $cleanEnvSettings = AutomationService::settings();
            unset($cleanEnvSettings['previous_secret'], $cleanEnvSettings['previous_key_id'], $cleanEnvSettings['previous_expires_at']);
            update_option(AutomationService::SETTINGS_OPTION, $cleanEnvSettings, false);
            $prepareEnvRotation = AutomationService::save_admin_settings(['prepare_environment_rotation' => '1']);
            $preparedEnvSettings = AutomationService::settings();
            $pendingEnvKeyId = (string) ($preparedEnvSettings['pending_environment_key_id'] ?? '');
            $envExpiry = strtotime((string) ($preparedEnvSettings['previous_expires_at'] ?? ''));
            $envSnapshotOk = ($preparedEnvSettings['previous_secret'] ?? '') === $envOldSecret
                && ($preparedEnvSettings['previous_key_id'] ?? '') === $envOldKeyId
                && $pendingEnvKeyId !== '' && $pendingEnvKeyId !== $envOldKeyId
                && $envExpiry > time() && $envExpiry <= time() + AutomationService::KEY_ROTATION_GRACE_SECONDS + 2;
            $envNewSecret = base64_encode(random_bytes(48));
            putenv('PARTIKULIER_N8N_SECRET=' . $envNewSecret);
            putenv('PARTIKULIER_N8N_KEY_ID=' . $pendingEnvKeyId);
            $envKeysAfterSwitch = AutomationService::secret_keys();
            $assert('AUTO-HMAC-ENV-ROTATION', true === $prepareEnvRotation && $envSnapshotOk
                && ($envKeysAfterSwitch[$pendingEnvKeyId] ?? '') === $envNewSecret
                && ($envKeysAfterSwitch[$envOldKeyId] ?? '') === $envOldSecret,
                'environment rotation stages prior secret and ID, then accepts the switched host key and prior key during grace');
        } finally {
            false === $oldEnvSecret ? putenv('PARTIKULIER_N8N_SECRET') : putenv('PARTIKULIER_N8N_SECRET=' . $oldEnvSecret);
            false === $oldEnvKeyId ? putenv('PARTIKULIER_N8N_KEY_ID') : putenv('PARTIKULIER_N8N_KEY_ID=' . $oldEnvKeyId);
            update_option(AutomationService::SETTINGS_OPTION, $envSettingsBackup, false);
        }
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
    if ($runtimeLeadId > 0) {
        $wpdb->delete(LeadService::table('pk_buyer_preferences'), ['lead_id' => $runtimeLeadId], ['%d']);
        $wpdb->delete(LeadService::table('pk_whatsapp_consents'), ['lead_id' => $runtimeLeadId], ['%d']);
        $wpdb->delete(LeadService::table('pk_whatsapp_messages'), ['lead_id' => $runtimeLeadId], ['%d']);
        $wpdb->delete(LeadService::leads_table(), ['id' => $runtimeLeadId], ['%d']);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->prefix}pk_audit_log WHERE object_type = %s AND object_id = %d AND action IN (%s, %s)",
            'lead', $runtimeLeadId, 'lead_qualified_particulier', 'lead_qualified_intermediaire'
        ));
    }
    if (null === $adminKeyBackup) {
        delete_option('pk_admin_secret_key');
    } else {
        update_option('pk_admin_secret_key', $adminKeyBackup, false);
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
