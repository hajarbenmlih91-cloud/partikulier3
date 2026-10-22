<?php
/**
 * Hygiène retenue : cache des images, chat Hostinger, readme.html.
 *
 * La liste publique des médias et la redirection de connexion sont dans
 * class-security.php. La sauvegarde n'est pas une modification du thème.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Site_Hygiene {

	const MARKER = 'Partikulier hygiene';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'remove_readme' ), 1 );
		add_action( 'init', array( __CLASS__, 'ensure_htaccess' ), 2 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_hostinger_chat' ), 100 );
		add_filter( 'script_loader_tag', array( __CLASS__, 'strip_hostinger_tag' ), 10, 3 );
		add_action( 'template_redirect', array( __CLASS__, 'strip_hostinger_buffer' ), 0 );
	}

	public static function remove_readme() {
		$file = ABSPATH . 'readme.html';
		if ( is_file( $file ) && is_writable( $file ) ) {
			unlink( $file );
		}
	}

	/**
	 * Cache long sur les médias, et 404 si readme.html est encore servi en fichier.
	 */
	public static function ensure_htaccess() {
		if ( ! function_exists( 'insert_with_markers' ) ) {
			require_once ABSPATH . 'wp-admin/includes/misc.php';
		}
		if ( ! function_exists( 'insert_with_markers' ) ) {
			return;
		}
		$htaccess = ABSPATH . '.htaccess';
		if ( ! file_exists( $htaccess ) || ! is_writable( $htaccess ) ) {
			return;
		}
		$rules = array(
			'<IfModule mod_headers.c>',
			'<FilesMatch "\\.(avif|webp|jpe?g|png|gif|svg|woff2?)$">',
			'Header set Cache-Control "public, max-age=31536000, immutable"',
			'</FilesMatch>',
			'</IfModule>',
			'<IfModule mod_rewrite.c>',
			'RewriteEngine On',
			'RewriteRule ^readme\\.html$ - [R=404,L]',
			'</IfModule>',
		);
		insert_with_markers( $htaccess, self::MARKER, $rules );
	}

	public static function dequeue_hostinger_chat() {
		foreach ( array( 'hostinger-reach', 'hostinger_reach', 'cdn-reach' ) as $handle ) {
			wp_dequeue_script( $handle );
			wp_deregister_script( $handle );
		}
	}

	public static function strip_hostinger_tag( $tag, $handle, $src ) {
		unset( $handle );
		if ( is_string( $src ) && false !== strpos( $src, 'cdn-reach.hostinger.com' ) ) {
			return '';
		}
		return $tag;
	}

	public static function strip_hostinger_buffer() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		ob_start( array( __CLASS__, 'strip_hostinger_html' ) );
	}

	public static function strip_hostinger_html( $html ) {
		return preg_replace( '#<script\b[^>]*cdn-reach\.hostinger\.com[^>]*>\s*</script>#i', '', (string) $html );
	}
}

Partikulier_Site_Hygiene::init();
