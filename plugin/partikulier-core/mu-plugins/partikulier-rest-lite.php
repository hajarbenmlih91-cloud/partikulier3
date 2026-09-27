<?php
/**
 * Réduit le bootstrap du GET public REST listings & listings-lite.
 *
 * Le core REST lit uniquement sa table pk_listings / index wp_posts et ne dépend pas
 * d'Estatik, Polylang ou Query Monitor pour cette route. Les trois plugins restent actifs
 * pour toutes les autres routes, les écritures et les parcours front.
 *
 * Intègre un micro-caching Redis haute performance (10s à 60s) pour servir les
 * requêtes de recherche interactives populaires en sub-5ms sans réévaluation WordPress.
 *
 * @package Partikulier
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function partikulier_is_public_listings_get() {
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get';
	if ( 'get' !== $method ) {
		return false;
	}
	$authorization = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) : '';
	$cookie        = isset( $_SERVER['HTTP_COOKIE'] ) ? (string) wp_unslash( $_SERVER['HTTP_COOKIE'] ) : '';
	if ( '' !== $authorization || preg_match( '/(?:wordpress_logged_in|wordpress_sec)_[^=]*=/i', $cookie ) ) {
		return false;
	}

	$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : '';
	$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );

	return (bool) preg_match(
		'#^/wp-json/partikulier/v1/listings(-lite)?/?$#',
		$path
	) || (bool) preg_match(
		'#^/?partikulier/v1/listings(-lite)?/?$#',
		$route
	);
}

// Décharge les plugins lourds pour listings et listings-lite
add_filter(
	'option_active_plugins',
	static function ( $plugins ) {
		if ( ! is_array( $plugins ) || ! partikulier_is_public_listings_get() ) {
			return $plugins;
		}

		$optional_plugins = array(
			'estatik/estatik.php',
			'polylang/polylang.php',
			'query-monitor/query-monitor.php',
		);

		return array_values( array_diff( $plugins, $optional_plugins ) );
	},
	1
);

// Micro-cache Redis ultra-rapide au niveau MU-Plugin pour /listings-lite
add_action(
	'muplugins_loaded',
	static function () {
		if ( ! partikulier_is_public_listings_get() ) {
			return;
		}
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : '';

		if ( ! str_contains( $path, '/listings-lite' ) && ! str_contains( $route, '/listings-lite' ) ) {
			return;
		}

		if ( ! class_exists( 'Redis' ) ) {
			return;
		}

		// Paramètres de recherche normalisés
		$city       = isset( $_GET['city'] ) ? sanitize_key( wp_unslash( $_GET['city'] ) ) : '';
		$type       = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : '';
		$offer_type = isset( $_GET['offer_type'] ) ? sanitize_key( wp_unslash( $_GET['offer_type'] ) ) : '';
		if ( 'vente' === $offer_type ) {
			$offer_type = 'a-vendre';
		} elseif ( 'location' === $offer_type ) {
			$offer_type = 'a-louer';
		}
		$price_min  = isset( $_GET['price_min'] ) ? (float) $_GET['price_min'] : null;
		$price_max  = isset( $_GET['price_max'] ) ? (float) $_GET['price_max'] : null;
		$page       = isset( $_GET['page'] ) ? max( 1, (int) $_GET['page'] ) : 1;
		$per_page   = isset( $_GET['per_page'] ) ? min( 50, max( 1, (int) $_GET['per_page'] ) ) : 12;
		$locale     = isset( $_GET['locale'] ) ? sanitize_key( wp_unslash( $_GET['locale'] ) ) : 'fr';

		$cache_params = array(
			'city'       => $city,
			'type'       => $type,
			'offer_type' => $offer_type,
			'price_min'  => $price_min,
			'price_max'  => $price_max,
			'page'       => $page,
			'per_page'   => $per_page,
			'locale'     => $locale,
		);
		ksort( $cache_params );
		$cache_key = 'pk_lite_v1:' . md5( (string) wp_json_encode( $cache_params ) );

		try {
			$redis = new Redis();
			if ( @$redis->connect( '127.0.0.1', 6379, 0.05 ) ) {
				$cached = $redis->get( $cache_key );
				if ( $cached ) {
					header( 'Content-Type: application/json; charset=UTF-8' );
					header( 'X-MicroCache: REDIS-HIT' );
					header( 'X-Cache: HIT' );
					header( 'Cache-Control: public, max-age=30, stale-while-revalidate=60' );
					echo $cached;
					exit;
				}
			}
		} catch ( \Throwable $e ) {
			// Repli transparent sur le bootstrap normal
		}
	},
	2
);
