<?php
/**
 * Partikulier 6.17 — garde SEO précoce.
 *
 * Polylang documente que pll_redirect_home peut être appelé avant le thème.
 * Ce module doit donc être chargé en mu-plugin afin que les robots ne soient
 * jamais redirigés selon Accept-Language. Les visiteurs humains conservent
 * la détection browser Polylang et la mémorisation pll_language.
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

add_filter(
    'pll_redirect_home',
    static function ( $redirect ) {
        if ( partikulier_early_seo_is_robot() ) {
            return false;
        }
        // SE-020 : chemins extraits de REQUEST_URI pour comparaison uniquement.
        $request_path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- wp_parse_url + comparaison (E-2002)
        $redirect_path = $redirect ? wp_parse_url( (string) $redirect, PHP_URL_PATH ) : '';
        if ( $request_path && $redirect_path && trailingslashit( $request_path ) === trailingslashit( $redirect_path ) ) {
            return false;
        }
        $accept_language = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- usage comparatif (E-2002)
        if ( '/' === trailingslashit( (string) $request_path ) && '/fr/' === trailingslashit( (string) $redirect_path ) && ! preg_match( '/(^|,)\s*(ar|en)(?:[-_][a-z]+)?(?:\s*;|\s*,|$)/i', $accept_language ) ) {
            return false;
        }
        return $redirect;
    },
    10,
    1
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
