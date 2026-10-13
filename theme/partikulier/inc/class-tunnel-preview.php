<?php
/**
 * Le tunnel remplace la page WordPress « Déposer une annonce ».
 *
 * /deposer/ et ses variantes de langue servent le HTML autonome.
 * /apercu-tunnel/ redirige vers /deposer/. L’ancien gabarit PHP reste
 * dans le thème, mais il n’est plus affiché sur ces adresses.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Tunnel_Preview {

	/**
	 * Branche la route avant les redirections de langue.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_render' ), 0 );
	}

	/**
	 * Affiche le tunnel sur la page de dépôt, ou redirige l’ancien aperçu.
	 */
	public static function maybe_render() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			return;
		}

		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- chemin comparé à un motif fixe.
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		$lang = '';
		if ( preg_match( '#^/(fr|en|ar)/apercu-tunnel/?$#', $path, $match ) ) {
			$lang = $match[1];
		} elseif ( preg_match( '#^/apercu-tunnel/?$#', $path ) ) {
			$lang = '';
		} elseif ( ! preg_match( '#^/(?:(fr|en|ar)/)?(?:deposer|deposer-une-annonce|deposer-annonce|publier-une-annonce)/?$#', $path, $match ) ) {
			return;
		} else {
			$lang = isset( $match[1] ) ? $match[1] : '';
		}

		if ( preg_match( '#apercu-tunnel/?$#', $path ) ) {
			$target = ( '' !== $lang ? '/' . $lang : '' ) . '/deposer/';
			wp_safe_redirect( home_url( $target ), 302 );
			exit;
		}

		$file = get_theme_file_path( 'apercu-tunnel.html' );
		if ( ! is_readable( $file ) ) {
			return;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier du thème, pas une entrée utilisateur.
		if ( ! is_string( $html ) || '' === $html ) {
			return;
		}

		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document HTML autonome du thème, déjà figé.
		exit;
	}
}

Partikulier_Tunnel_Preview::init();
