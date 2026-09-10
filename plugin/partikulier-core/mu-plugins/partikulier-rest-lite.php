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
    $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
    if ( 'GET' !== $method ) {
        return false;
    }
    $authorization = isset( $_SERVER['HTTP_AUTHORIZATION'] ) ? (string) $_SERVER['HTTP_AUTHORIZATION'] : '';
    $cookie        = isset( $_SERVER['HTTP_COOKIE'] ) ? (string) $_SERVER['HTTP_COOKIE'] : '';
    if ( '' !== $authorization || preg_match( '/(?:wordpress_logged_in|wordpress_sec)_[^=]*=/i', $cookie ) ) {
        return false;
    }

    $route = isset( $_GET['rest_route'] ) ? (string) wp_unslash( $_GET['rest_route'] ) : '';
    $uri   = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
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
