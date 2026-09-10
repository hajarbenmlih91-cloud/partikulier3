<?php
/**
 * Module : protections HTTP et réduction de l’exposition WordPress.
 *
 * Correctifs invisibles : aucune modification de template, style ou parcours public.
 * Les réglages de serveur finaux restent documentés dans le guide Hostinger.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Security {

	public static function init() {
		add_filter( 'rest_endpoints', array( __CLASS__, 'restrict_user_enumeration' ) );
		add_action( 'template_redirect', array( __CLASS__, 'block_public_author_enumeration' ), 0 );
		add_action( 'send_headers', array( __CLASS__, 'send_public_headers' ) );
		add_filter( 'xmlrpc_enabled', '__return_false' );
	}

	/**
	 * L’API des utilisateurs n’est jamais utile aux visiteurs d’un portail
	 * immobilier. Les administrateurs conservent leur accès normal.
	 */
	public static function restrict_user_enumeration( $endpoints ) {
		if ( current_user_can( 'list_users' ) ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/**
	 * Neutralise la découverte d’identifiants par ?author=ID et les archives
	 * auteur publiques, sans intervenir dans l’administration.
	 */
	public static function block_public_author_enumeration() {
		if ( is_admin() || is_user_logged_in() || ! is_author() ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}

	/**
	 * En-têtes défensifs compatibles avec les ressources actuelles du thème.
	 * HSTS est envoyé seulement derrière HTTPS afin de rester sûr en sandbox.
	 */
		public static function send_public_headers() {
			if ( is_admin() ) {
				return;
			}
			$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
			$is_root = '/' === trailingslashit( (string) $path );
			if ( $is_root ) {
				header( 'Cache-Control: private, no-store, max-age=0' );
				header( 'Vary: Accept-Language, Cookie', false );
			}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
		header( "Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; frame-src 'self' https://static.addtoany.com; form-action 'self'; img-src 'self' data: blob: https://static.addtoany.com; font-src 'self' data:; script-src 'self' 'unsafe-inline' https://static.addtoany.com; style-src 'self' 'unsafe-inline'; connect-src 'self'" );
		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}
	}

	/**
	 * Limite les soumissions anonymes sans dépendre de JavaScript ni stocker
	 * l’adresse IP en clair. Les administrateurs restent exemptés en recette.
	 */
	public static function allow_listing_submission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : 'unknown';
		$key = 'pk_listing_rate_' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
		$limit = max( 1, (int) apply_filters( 'partikulier_listing_submission_limit', 5 ) );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}
}

Partikulier_Security::init();