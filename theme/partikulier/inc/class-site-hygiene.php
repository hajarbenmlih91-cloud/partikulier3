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
		add_action( 'init', array( __CLASS__, 'close_uat_robots' ), 1 );
		add_action( 'init', array( __CLASS__, 'replace_known_brand_sentences' ), 1 );
		add_action( 'init', array( __CLASS__, 'disable_browser_detection' ), 1 );
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
	 * Le fichier physique gagne sur le filtre WordPress. Sur la recette, il
	 * doit bloquer tout le monde. Ce n'est pas le mot de passe.
	 */
	public static function close_uat_robots() {
		if ( ! self::is_uat_host() ) {
			return;
		}
		$file = ABSPATH . 'robots.txt';
		$body = "User-agent: *\nDisallow: /\n";
		if ( is_file( $file ) && is_readable( $file ) && $body === (string) file_get_contents( $file ) ) {
			return;
		}
		if ( ( is_file( $file ) && is_writable( $file ) ) || ( ! is_file( $file ) && is_writable( ABSPATH ) ) ) {
			file_put_contents( $file, $body, LOCK_EX );
		}
	}

	/**
	 * Deux phrases déjà en base. Seul le nom public change. Pas un remplacement global.
	 */
	public static function replace_known_brand_sentences() {
		if ( get_transient( 'pk_pep_brand_sentences' ) ) {
			return;
		}
		global $wpdb;
		$map = array(
			'Oui. Partikulier met en relation les particuliers sans commission ni intermédiaire.' => 'Oui. pep.ma met en relation les particuliers sans commission ni intermédiaire.',
			'Oui. Tuk met en relation les particuliers sans commission ni intermédiaire.' => 'Oui. pep.ma met en relation les particuliers sans commission ni intermédiaire.',
			'fonctionnement de Partikulier, écrivez-nous' => 'fonctionnement de pep.ma, écrivez-nous',
			'fonctionnement de Tuk, écrivez-nous' => 'fonctionnement de pep.ma, écrivez-nous',
		);
		foreach ( $map as $old => $new ) {
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, %s, %s) WHERE post_type = 'page' AND post_content LIKE %s",
					$old,
					$new,
					'%' . $wpdb->esc_like( $old ) . '%'
				)
			);
			if ( ! empty( $wpdb->last_error ) ) {
				return;
			}
		}
		set_transient( 'pk_pep_brand_sentences', '1', DAY_IN_SECONDS );
	}

	/**
	 * @return bool
	 */
	public static function disable_browser_detection() {
		$options = get_option( 'polylang' );
		if ( ! is_array( $options ) || empty( $options['browser'] ) ) {
			return;
		}
		$options['browser'] = 0;
		update_option( 'polylang', $options );
	}

	private static function is_uat_host() {
		$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) ) : '';
		if ( false !== strpos( $host, 'hostingersite.com' ) ) {
			return true;
		}
		return false !== strpos( (string) home_url( '/' ), 'hostingersite.com' );
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
