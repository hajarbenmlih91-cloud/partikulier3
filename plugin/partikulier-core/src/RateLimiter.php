<?php
declare(strict_types=1);

namespace Partikulier\Core;

use Partikulier\Core\Rest\RequestCycle;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

final class RateLimiter
{
	private const CACHE_GROUP = 'partikulier_rate_limits';
	private const DB_KEY_PREFIX = 'pkrl-v2-';
	private const TRANSIENT_TTL_MULTIPLIER = 2;

	private static bool $hooksRegistered = false;

	public function __construct()
	{
		if ( ! self::$hooksRegistered ) {
			add_filter('rest_post_dispatch', [self::class, 'attach_retry_after_header'], 10, 3);
			self::$hooksRegistered = true;
		}
	}

	public function guard( WP_REST_Request $request, string $bucket, bool $authorized, int $limit, int $window = 60 ): bool|WP_Error
	{
		if ( ! $authorized ) {
			return false;
		}
		if ( $window < 1 ) {
			return new WP_Error('pk_rate_limit_invalid_window', 'Rate-limit window must be positive.', ['status' => 500]);
		}

		$identity = is_user_logged_in() ? 'user:' . (string) get_current_user_id() : 'ip:' . $this->clientIp();
		$cycleKey = 'rate_limit_verdict_' . $bucket;
		$cachedVerdict = RequestCycle::recall($request, $cycleKey);
		if ( null !== $cachedVerdict ) {
			return $cachedVerdict;
		}

		$rawRoute = preg_replace('#/\d+(/|$)#', '/{id}$1', $request->get_route());
		$route    = preg_replace('/[^a-z0-9_-]/i', '_', (string) $rawRoute) ?: 'route';
		$counterId = md5($bucket . '|' . $route . '|' . $identity);
		$now = time();
		$windowStart = intdiv($now, $window) * $window;
		$count = $this->increment_counter($counterId, $window, $windowStart, $now);
		if ( is_wp_error($count) ) {
			RequestCycle::remember($request, $cycleKey, $count);
			return $count;
		}

		if ( $count > $limit ) {
			$verdict = new WP_Error(
				'pk_rate_limited',
				'Trop de requêtes; réessayez plus tard.',
				[
					'status'      => 429,
					'retry_after' => max(1, $windowStart + $window - $now),
					'bucket'      => $bucket,
				]
			);
			RequestCycle::remember($request, $cycleKey, $verdict);
			return $verdict;
		}

		RequestCycle::remember($request, $cycleKey, true);
		return true;
	}

	/**
	 * Add the standard Retry-After header to REST responses created from a
	 * rate-limit WP_Error. The JSON error data remains backward-compatible.
	 */
	public static function attach_retry_after_header( $response, $server = null, $request = null )
	{
		if ( ! $response instanceof WP_REST_Response || 429 !== $response->get_status() ) {
			return $response;
		}

		$data = $response->get_data();
		$retryAfter = is_array($data)
			? ( $data['data']['retry_after'] ?? $data['retry_after'] ?? null )
			: null;
		if ( is_numeric($retryAfter) && (int) $retryAfter > 0 ) {
			$response->header('Retry-After', (string) max(1, (int) ceil((float) $retryAfter)));
		}
		return $response;
	}

	/** Increment with an atomic shared backend; never silently fall back to a racy read/modify/write. */
	private function increment_counter( string $counterId, int $window, int $windowStart, int $now ): int|WP_Error
	{
		$cacheKey = self::CACHE_GROUP . ':' . $window . ':' . $counterId . ':' . $windowStart;
		$expiresAt = $windowStart + ( self::TRANSIENT_TTL_MULTIPLIER * $window );
		$ttl = max(1, $expiresAt - $now);
		$backend = (string) apply_filters('partikulier_rate_limiter_backend', 'database');

		if ( 'shared-object-cache' === $backend ) {
			$cacheSupportsAtomicIncrement = ! function_exists('wp_cache_supports')
				|| wp_cache_supports('incr');
			if ( ! function_exists('wp_using_ext_object_cache') || ! wp_using_ext_object_cache()
				|| ! $cacheSupportsAtomicIncrement ) {
				return $this->unavailable();
			}
			$count = $this->increment_object_cache($cacheKey, $ttl);
			return null === $count ? $this->unavailable() : $count;
		}

		if ( 'single-node-apcu' === $backend ) {
			if ( ! function_exists('apcu_enabled') || ! apcu_enabled()
				|| ! function_exists('apcu_inc') || ! function_exists('apcu_add') ) {
				return $this->unavailable();
			}
			$count = $this->increment_apcu($cacheKey, $ttl);
			return null === $count ? $this->unavailable() : $count;
		}

		if ( 'database' !== $backend ) {
			return new WP_Error('pk_rate_limiter_invalid_backend', 'Unsupported rate-limit backend.', ['status' => 500]);
		}
		return $this->increment_database($counterId, $window, $windowStart, $expiresAt);
	}

	private function increment_object_cache( string $key, int $ttl ): ?int
	{
		$count = wp_cache_incr($key, 1, self::CACHE_GROUP);
		if ( false !== $count && is_numeric($count) ) {
			return (int) $count;
		}
		if ( wp_cache_add($key, 1, self::CACHE_GROUP, $ttl) ) {
			return 1;
		}
		$count = wp_cache_incr($key, 1, self::CACHE_GROUP);
		return false !== $count && is_numeric($count) ? (int) $count : null;
	}

	private function increment_apcu( string $key, int $ttl ): ?int
	{
		$found = false;
		$count = apcu_inc($key, 1, $found, $ttl);
		if ( $found && is_int($count) ) {
			return $count;
		}
		if ( apcu_add($key, 1, $ttl) ) {
			return 1;
		}
		$found = false;
		$count = apcu_inc($key, 1, $found, $ttl);
		return $found && is_int($count) ? $count : null;
	}

	/**
	 * Use a unique fixed-window transient row and an atomic SQL upsert when
	 * neither a persistent object cache nor APCu is available. WordPress's
	 * transient cleanup removes the expired counter/timeout pair.
	 */
	private function increment_database( string $counterId, int $window, int $windowStart, int $expiresAt ): int|WP_Error
	{
		global $wpdb;
		if ( ! isset($wpdb->options) ) {
			return $this->unavailable();
		}

		$transient = self::DB_KEY_PREFIX . $window . '-' . $counterId . '-' . $windowStart;
		$counterOption = '_transient_' . $transient;
		$timeoutOption = '_transient_timeout_' . $transient;

		/*
		 * Atomic two-row upsert creates/updates the counter and its WordPress
		 * transient expiry together. The following read may observe a later
		 * concurrent count and conservatively reject early, never undercount.
		 */
		$increment = $wpdb->query($wpdb->prepare(
			"INSERT INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, '1', 'no'), (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = CASE WHEN option_name = %s THEN CAST(option_value AS UNSIGNED) + 1 ELSE GREATEST(CAST(option_value AS UNSIGNED), CAST(%s AS UNSIGNED)) END",
			$counterOption,
			$timeoutOption,
			(string) $expiresAt,
			$counterOption,
			(string) $expiresAt
		));
		if ( false === $increment ) {
			return $this->unavailable();
		}
		$count = $wpdb->get_var($wpdb->prepare(
			"SELECT CAST(option_value AS UNSIGNED) FROM {$wpdb->options} WHERE option_name = %s",
			$counterOption
		));
		return is_numeric($count) && (int) $count > 0 ? (int) $count : $this->unavailable();
	}

	private function unavailable(): WP_Error
	{
		return new WP_Error(
			'pk_rate_limiter_unavailable',
			'Le contrôle de débit est temporairement indisponible.',
			['status' => 503]
		);
	}

	private function clientIp(): string
	{
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash( (string) $_SERVER['REMOTE_ADDR'])) : 'unknown';
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
	}
}
