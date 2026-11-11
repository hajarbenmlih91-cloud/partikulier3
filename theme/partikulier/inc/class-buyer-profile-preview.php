<?php
/**
 * Aperçu validé du profil acheteur, en français, anglais et arabe.
 *
 * Ce n'est pas la connexion réelle. La page montre le profil accepté.
 * Le logo reste à gauche et le compte à droite, comme l'en-tête du site.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Buyer_Profile_Preview {

	/**
	 * Sert la page avant le modèle 404.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 0 );
	}

	/**
	 * Langue demandée, ou chaîne vide si ce n'est pas cette page.
	 *
	 * @return string
	 */
	private static function requested_lang() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- chemin comparé à un motif fixe.
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		if ( ! preg_match( '#^/(?:(fr|en|ar)/)?profil-acheteur/?$#', $path, $match ) ) {
			return '';
		}
		return isset( $match[1] ) && '' !== $match[1] ? $match[1] : 'fr';
	}

	/**
	 * Envoie le document de la langue demandée.
	 */
	public static function maybe_serve() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$lang = self::requested_lang();
		if ( '' === $lang ) {
			return;
		}
		$file = get_theme_file_path( 'apercu/profil-acheteur-' . $lang . '.html' );
		if ( ! is_readable( $file ) ) {
			return;
		}
		$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier du thème, pas une entrée utilisateur.
		if ( ! is_string( $html ) || '' === $html ) {
			return;
		}
		$urls = array(
			'Français' => home_url( '/fr/profil-acheteur/' ),
			'English'  => home_url( '/en/profil-acheteur/' ),
			'العربية'  => home_url( '/ar/profil-acheteur/' ),
		);
		$script = '<script>document.getElementById("langue")&&document.getElementById("langue").addEventListener("change",function(){var map=' . wp_json_encode( $urls ) . ';if(map[this.value]){window.location.href=map[this.value];}});</script>';
		$html   = str_replace( '</body>', $script . '</body>', $html );
		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: text/html; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document du thème déjà figé.
		exit;
	}
}

Partikulier_Buyer_Profile_Preview::init();
