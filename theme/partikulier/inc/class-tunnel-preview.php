<?php
/**
 * Aperçu public du tunnel de dépôt, hors formulaire WordPress.
 *
 * Sert le HTML autonome sur /apercu-tunnel/ sans créer d’annonce et sans
 * remplacer /deposer/. La page n’a pas besoin d’exister dans l’admin :
 * la route répond dès le premier chargement après déploiement.
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
	 * Affiche le tunnel si l’URL demandée est la page d’aperçu.
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
		if ( ! preg_match( '#^/(?:(?:fr|en|ar)/)?apercu-tunnel/?$#', $path ) ) {
			return;
		}

		$file = get_theme_file_path( 'apercu-tunnel.html' );
		if ( ! is_readable( $file ) ) {
			status_header( 404 );
			exit;
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );

		$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier du thème, pas une entrée utilisateur.
		if ( ! is_string( $html ) || '' === $html ) {
			status_header( 404 );
			exit;
		}

		$banner = '<div style="position:sticky;top:0;z-index:80;background:#9b6a3d;color:#fff;text-align:center;padding:.6rem 1rem;font:600 14px/1.4 sans-serif;">Aperçu du tunnel. Cette page ne publie rien. Le dépôt réel reste /deposer/.</div>';
		$html   = preg_replace( '/<body([^>]*)>/', '<body$1>' . $banner, $html, 1 );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document HTML autonome du thème, déjà figé.
		exit;
	}
}

Partikulier_Tunnel_Preview::init();
