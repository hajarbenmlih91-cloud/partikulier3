<?php
declare(strict_types=1);

namespace Partikulier\Core;

use WP_Error;
use WP_REST_Request;

final class RateLimiter
{
    /** @var array<string, true> Requests already charged during this PHP request. */
    private array $seen = [];

    public function guard(WP_REST_Request $request, string $bucket, bool $authorized, int $limit, int $window = 60): bool|WP_Error
    {
        if (!$authorized) {
            return false;
        }
        $identity = is_user_logged_in() ? 'user:' . (string) get_current_user_id() : 'ip:' . $this->clientIp();
        $requestMarker = spl_object_id($request) . '|' . $bucket . '|' . $identity;
        if (isset($this->seen[$requestMarker])) return true;
        $this->seen[$requestMarker] = true;
        $route = preg_replace('/[^a-z0-9_-]/i', '_', $request->get_route()) ?: 'route';
        $key = 'pk_rl_' . md5($bucket . '|' . $route . '|' . $identity);
        $now = time();
        if (function_exists('apcu_enabled') && apcu_enabled() && function_exists('apcu_fetch') && function_exists('apcu_store') && function_exists('apcu_inc')) {
            $startedKey = $key . '_started';
            $countKey = $key . '_count';
            $started = apcu_fetch($startedKey, $startedFound);
            if (!$startedFound || ($now - (int) $started) >= $window) {
                if (function_exists('apcu_delete')) {
                    apcu_delete($startedKey);
                    apcu_delete($countKey);
                }
                apcu_add($startedKey, $now, $window);
                apcu_store($countKey, 0, $window);
                $started = $now;
            }
            $count = apcu_inc($countKey, 1, $countFound, $window);
            if (!$countFound || !is_int($count)) {
                $count = 1;
                apcu_store($countKey, $count, $window);
            }
            $state = ['started' => (int) $started, 'count' => (int) $count];
        } else {
            $state = get_transient($key);
            if (!is_array($state) || !isset($state['started'], $state['count']) || ($now - (int) $state['started']) >= $window) {
                $state = ['started' => $now, 'count' => 0];
            }
            $state['count']++;
            set_transient($key, $state, $window);
        }
        if ((int) $state['count'] > $limit) {
            $retryAfter = max(1, $window - ($now - (int) $state['started']));
            return new WP_Error(
                'pk_rate_limited',
                'Trop de requêtes; réessayez plus tard.',
                ['status' => 429, 'retry_after' => $retryAfter, 'bucket' => $bucket]
            );
        }
        return true;
    }

    private function clientIp(): string
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : 'unknown';
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }
}
