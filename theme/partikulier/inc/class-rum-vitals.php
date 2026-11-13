<?php
/**
 * Real User Monitoring (RUM) first-party des Core Web Vitals (INP, LCP, CLS, TTFB).
 *
 * Strictement conditionné au consentement existant (`pk_measure=yes`), sans
 * cookie supplémentaire, sans traceur tiers, sans IP, sans User-Agent ni URL
 * brute. Agrégation bornée (30 jours glissants) dans `pk_rum_daily`.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Rum_Vitals {

	const OPTION       = 'pk_rum_daily';
	const COOKIE       = 'pk_measure';
	const MAX_DAYS     = 30;
	const MAX_SAMPLES  = 150;
	const ALLOWED_LANG = array( 'fr', 'en', 'ar' );
	const ALLOWED_DEV  = array( 'm', 'd' );
	const ALLOWED_RTE  = array( 'home', 'archive', 'single', 'deposit', 'account', 'other' );

	public static function init() {
		add_action( 'wp_ajax_nopriv_pk_rum_vitals', array( __CLASS__, 'handle_ajax' ) );
		add_action( 'wp_ajax_pk_rum_vitals', array( __CLASS__, 'handle_ajax' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_collector' ), 100 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_CLI::add_command( 'pk rum', array( __CLASS__, 'cli_command' ) );
		}
	}

	public static function route_group() {
		if ( is_front_page() || is_home() ) {
			return 'home';
		}
		if ( defined( 'PARTIKULIER_ESTATIK_POST_TYPE' ) ) {
			if ( is_singular( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
				return 'single';
			}
			if ( is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) || is_tax() ) {
				return 'archive';
			}
		}
		if ( is_page_template( 'templates/page-deposer-annonce.php' ) ) {
			return 'deposit';
		}
		if ( is_page( array( 'mes-annonces', 'connexion', 'my-listings', 'login' ) ) ) {
			return 'account';
		}
		return 'other';
	}

	public static function handle_ajax() {
		$choice = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( 'yes' !== $choice ) {
			wp_send_json_success( array( 'recorded' => false ) );
		}
		$raw  = (string) file_get_contents( 'php://input' );
		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			$data = wp_unslash( $_POST );
		}
		$recorded = self::record_sample( is_array( $data ) ? $data : array() );
		wp_send_json_success( array( 'recorded' => $recorded ) );
	}

	public static function record_sample( array $data ) {
		$choice = isset( $_COOKIE[ self::COOKIE ] ) ? sanitize_key( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) ) : '';
		if ( 'yes' !== $choice ) {
			return false;
		}
		$lang   = isset( $data['lang'] ) && in_array( (string) $data['lang'], self::ALLOWED_LANG, true ) ? (string) $data['lang'] : 'fr';
		$device = isset( $data['device'] ) && in_array( (string) $data['device'], self::ALLOWED_DEV, true ) ? (string) $data['device'] : 'm';
		$route  = isset( $data['route'] ) && in_array( (string) $data['route'], self::ALLOWED_RTE, true ) ? (string) $data['route'] : 'other';

		$sample = array();
		foreach ( array( 'lcp' => 60000, 'inp' => 60000, 'ttfb' => 60000 ) as $k => $max ) {
			if ( isset( $data[ $k ] ) && is_numeric( $data[ $k ] ) ) {
				$val = (int) round( (float) $data[ $k ] );
				if ( $val >= 0 && $val <= $max ) {
					$sample[ $k ] = $val;
				}
			}
		}
		if ( isset( $data['cls'] ) && is_numeric( $data['cls'] ) ) {
			$cls = round( (float) $data['cls'], 4 );
			if ( $cls >= 0 && $cls <= 10.0 ) {
				$sample['cls'] = $cls;
			}
		}
		if ( empty( $sample ) ) {
			return false;
		}
		$sample['lang'] = $lang;

		$day = wp_date( 'Y-m-d' );
		$all = get_option( self::OPTION, array() );
		$all = is_array( $all ) ? $all : array();
		if ( count( $all ) > self::MAX_DAYS ) {
			ksort( $all );
			$all = array_slice( $all, -self::MAX_DAYS, null, true );
		}
		$bucket = $route . ':' . $device;
		if ( ! isset( $all[ $day ] ) || ! is_array( $all[ $day ] ) ) {
			$all[ $day ] = array();
		}
		if ( ! isset( $all[ $day ][ $bucket ] ) || ! is_array( $all[ $day ][ $bucket ] ) ) {
			$all[ $day ][ $bucket ] = array();
		}
		$list = &$all[ $day ][ $bucket ];
		if ( count( $list ) < self::MAX_SAMPLES ) {
			$list[] = $sample;
		} else {
			$list[ wp_rand( 0, self::MAX_SAMPLES - 1 ) ] = $sample;
		}
		update_option( self::OPTION, $all, false );
		return true;
	}

	public static function summary( $days = 7 ) {
		$days   = max( 1, min( self::MAX_DAYS, (int) $days ) );
		$cutoff = gmdate( 'Y-m-d', strtotime( '-' . ( $days - 1 ) . ' days' ) );
		$all    = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			return array();
		}
		$buckets = array();
		foreach ( $all as $day => $groups ) {
			if ( $day < $cutoff || ! is_array( $groups ) ) {
				continue;
			}
			foreach ( $groups as $bucket => $samples ) {
				if ( ! is_array( $samples ) ) {
					continue;
				}
				foreach ( $samples as $s ) {
					if ( ! is_array( $s ) ) {
						continue;
					}
					foreach ( array( 'lcp', 'inp', 'cls', 'ttfb' ) as $metric ) {
						if ( isset( $s[ $metric ] ) && is_numeric( $s[ $metric ] ) ) {
							$buckets[ $bucket ][ $metric ][] = (float) $s[ $metric ];
						}
					}
				}
			}
		}
		$out = array();
		foreach ( $buckets as $bucket => $series ) {
			$row = array( 'samples' => max( array_map( 'count', $series ) ) );
			foreach ( array( 'lcp', 'inp', 'cls', 'ttfb' ) as $metric ) {
				if ( empty( $series[ $metric ] ) ) {
					$row[ $metric . '_p75' ] = null;
					continue;
				}
				$vals = $series[ $metric ];
				sort( $vals, SORT_NUMERIC );
				$idx = (int) floor( 0.75 * ( count( $vals ) - 1 ) );
				$row[ $metric . '_p75' ] = 'cls' === $metric ? round( $vals[ $idx ], 4 ) : (int) round( $vals[ $idx ] );
			}
			$out[ $bucket ] = $row;
		}
		return $out;
	}

	public static function cli_command( $args ) {
		$days = isset( $args[0] ) && is_numeric( $args[0] ) ? (int) $args[0] : 7;
		WP_CLI::log( wp_json_encode( self::summary( $days ), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	public static function render_collector() {
		if ( is_admin() ) {
			return;
		}
		$cfg = wp_json_encode(
			array(
				'url'   => admin_url( 'admin-ajax.php?action=pk_rum_vitals' ),
				'route' => self::route_group(),
				'lang'  => class_exists( 'Partikulier_Settings' ) ? Partikulier_Settings::current_language() : 'fr',
			)
		);
		?>
		<script data-pk-rum>
		(function () {
			if (!("PerformanceObserver" in window)) return;
			var cfg = <?php echo $cfg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode structured allowlist ?>;
			var started = false, sent = false;
			var state = { lcp: null, cls: 0, inp: null, interactions: {} };
			function consented() {
				return /(?:^|; )pk_measure=yes(?:;|$)/.test(document.cookie);
			}
			function start() {
				if (started || !consented()) return;
				started = true;
				var sup = PerformanceObserver.supportedEntryTypes || [];
				try {
					if (sup.indexOf("largest-contentful-paint") !== -1) {
						new PerformanceObserver(function (list) {
							var entries = list.getEntries();
							var last = entries[entries.length - 1];
							if (last) state.lcp = Math.round(last.renderTime || last.loadTime || last.startTime);
						}).observe({ type: "largest-contentful-paint", buffered: true });
					}
				} catch (e) {}
				try {
					if (sup.indexOf("layout-shift") !== -1) {
						new PerformanceObserver(function (list) {
							list.getEntries().forEach(function (entry) {
								if (!entry.hadRecentInput) state.cls += entry.value;
							});
						}).observe({ type: "layout-shift", buffered: true });
					}
				} catch (e) {}
				try {
					if (sup.indexOf("event") !== -1) {
						new PerformanceObserver(function (list) {
							list.getEntries().forEach(function (entry) {
								if (!entry.interactionId) return;
								var k = String(entry.interactionId);
								state.interactions[k] = Math.max(state.interactions[k] || 0, entry.duration);
							});
						}).observe({ type: "event", buffered: true, durationThreshold: 40 });
					}
				} catch (e) {}
				function flush() {
					if (sent || !consented()) return;
					sent = true;
					var durs = Object.keys(state.interactions).map(function (k) { return state.interactions[k]; }).sort(function (a, b) { return a - b; });
					if (durs.length) {
						var idx = Math.min(durs.length - 1, Math.floor(0.98 * durs.length));
						state.inp = Math.round(durs[idx]);
					}
					var nav = performance.getEntriesByType && performance.getEntriesByType("navigation")[0];
					var payload = {
						route: cfg.route,
						lang: cfg.lang,
						device: window.innerWidth < 768 ? "m" : "d",
						cls: Number(state.cls.toFixed(4))
					};
					if (state.lcp !== null) payload.lcp = state.lcp;
					if (state.inp !== null) payload.inp = state.inp;
					if (nav && nav.responseStart > 0) payload.ttfb = Math.round(nav.responseStart);
					var body = JSON.stringify(payload);
					if (navigator.sendBeacon) {
						navigator.sendBeacon(cfg.url, new Blob([body], { type: "application/json" }));
					} else if (window.fetch) {
						fetch(cfg.url, { method: "POST", keepalive: true, credentials: "same-origin", headers: { "Content-Type": "application/json" }, body: body }).catch(function () {});
					}
				}
				document.addEventListener("visibilitychange", function () {
					if (document.visibilityState === "hidden") flush();
				});
				window.addEventListener("pagehide", flush, { once: true });
			}
			if (consented()) {
				start();
			} else {
				var yesBtn = document.querySelector("[data-pk-cookie] [data-yes]");
				if (yesBtn) yesBtn.addEventListener("click", function () { window.setTimeout(start, 0); }, { once: true });
			}
		}());
		</script>
		<?php
	}
}

Partikulier_Rum_Vitals::init();
