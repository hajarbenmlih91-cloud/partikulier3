<?php
/**
 * Partikulier 6.17 — garde SEO précoce.
 *
 * Polylang documente que pll_redirect_home peut être appelé avant le thème.
 * Ce module doit donc être chargé en mu-plugin afin que les robots ne soient
 * jamais négociés selon Accept-Language (pas de cloaking). Les humains :
 * cookie, sinon langue du navigateur, un seul saut vers /fr/ /en/ /ar/.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

function partikulier_early_seo_is_robot() {
	// SE-020 : lecture de navigation à usage exclusivement comparatif (regex)
	// — jamais persistée ni émise ; le filtre s'exécute après wp_magic_quotes.
	$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- usage comparatif (E-2002)
	return '' !== $user_agent && (bool) preg_match( '/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|linkedinbot|whatsapp/i', $user_agent );
}

function partikulier_early_language_home( $lang ) {
	$lang = in_array( $lang, array( 'fr', 'en', 'ar' ), true ) ? $lang : 'fr';
	return trailingslashit( home_url( '/' . $lang . '/' ) );
}

function partikulier_early_set_language_cookie( $lang ) {
	$lang = in_array( $lang, array( 'fr', 'en', 'ar' ), true ) ? $lang : 'fr';
	$GLOBALS['partikulier_root_lang'] = $lang;
	$_COOKIE['pll_language']          = $lang; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
}

function partikulier_early_preferred_language() {
	if ( ! empty( $_COOKIE['pll_language'] ) ) { // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$cookie = sanitize_key( wp_unslash( $_COOKIE['pll_language'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		if ( in_array( $cookie, array( 'fr', 'en', 'ar' ), true ) ) {
			return $cookie;
		}
	}
	$accept = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- usage comparatif (E-2002)
	if ( preg_match( '/(?:^|,)\s*ar(?:[-_][a-z]+)?(?:\s*;|,|$)/i', $accept ) ) {
		return 'ar';
	}
	if ( preg_match( '/(?:^|,)\s*en(?:[-_][a-z]+)?(?:\s*;|,|$)/i', $accept ) ) {
		return 'en';
	}
	return 'fr';
}

add_filter(
	'pll_redirect_home',
	static function ( $redirect ) {
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- wp_parse_url + comparaison (E-2002)
		$host         = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- usage comparatif (E-2002)
		$ci           = ( false !== strpos( $host, '127.0.0.1' ) || 0 === strpos( $host, 'localhost' ) );
		if ( '/' === trailingslashit( (string) $request_path ) ) {
			if ( $ci ) {
				return false;
			}
			$lang = partikulier_early_seo_is_robot() ? 'fr' : partikulier_early_preferred_language();
			partikulier_early_set_language_cookie( $lang );
			return partikulier_early_language_home( $lang );
		}
		$redirect_path = $redirect ? wp_parse_url( (string) $redirect, PHP_URL_PATH ) : '';
		if ( $request_path && $redirect_path && trailingslashit( $request_path ) === trailingslashit( $redirect_path ) ) {
			return false;
		}
		if ( $redirect_path && preg_match( '#/(fr|en|ar)/accueil-#', (string) $redirect_path, $m ) ) {
			return partikulier_early_language_home( $m[1] );
		}
		return $redirect;
	},
	10,
	1
);

add_action(
	'send_headers',
	static function () {
		$lang = isset( $GLOBALS['partikulier_root_lang'] ) ? (string) $GLOBALS['partikulier_root_lang'] : '';
		if ( ! $lang || headers_sent() ) {
			return;
		}
		setcookie(
			'pll_language',
			$lang,
			array(
				'expires'  => time() + YEAR_IN_SECONDS,
				'path'     => '/',
				'secure'   => is_ssl(),
				'httponly' => false,
				'samesite' => 'Lax',
			)
		);
	},
	99
);

add_filter(
	'wp_redirect',
	static function ( $location, $status ) {
		// SE-020 : idem — chemin comparé, jamais émis.
		$request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- wp_parse_url + comparaison (E-2002)
		if ( 302 === (int) $status && '/' === trailingslashit( (string) $request_path ) ) {
			header( 'Cache-Control: private, no-store, max-age=0' );
			header( 'Vary: Accept-Language, Cookie', false );
		}
		return $location;
	},
	10,
	2
);
