<?php
/**
 * Réduit le bootstrap du GET public REST listings.
 *
 * Le core REST lit uniquement sa table pk_listings et ne dépend pas d'Estatik,
 * Polylang ou Query Monitor pour cette route. Les trois plugins restent actifs
 * pour toutes les autres routes, les écritures et les parcours front.
 *
 * @package Partikulier
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function partikulier_is_public_listings_get() {
	// SE-020 (E-2004) : verbe assaini (sanitize_key + repli GET conforme
	// RFC 3875 §4.1.2). Comparaison en minuscules : sanitize_key() minuscule
	// systématiquement — comparer à 'GET' rendrait le test toujours faux.
	$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- assaini par sanitize_key (E-2004)
	if ( 'get' !== $method ) {
		return false;
	}
	$authorization = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) wp_unslash( $_SERVER['HTTP_AUTHORIZATION'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- comparaison de présence seule (SE-020 : jamais émise)
	$cookie        = isset( $_SERVER['HTTP_COOKIE'] ) ? (string) wp_unslash( $_SERVER['HTTP_COOKIE'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- regex de session uniquement (SE-020 : jamais émise)
	if ( '' !== $authorization || preg_match( '/(?:wordpress_logged_in|wordpress_sec)_[^=]*=/i', $cookie ) ) {
		return false;
	}

	$route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- route publique pattern-matchée (E-2007), jamais émise
	$uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- wp_parse_url + pattern-match, jamais émis (SE-020)
	$path  = (string) wp_parse_url( $uri, PHP_URL_PATH );

	return (bool) preg_match(
		'#^/wp-json/partikulier/v1/listings/?$#',
		$path
	) || (bool) preg_match(
		'#^/?partikulier/v1/listings/?$#',
		$route
	);
}

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
