<?php
/**
 * Le tunnel remplace la page WordPress « Déposer une annonce ».
 *
 * La page garde l'en-tête et le pied de page du thème, donc le choix de langue.
 * /apercu-tunnel/ redirige vers /deposer/.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Tunnel_Preview {

	/**
	 * Branche la redirection de l'ancien aperçu et le gabarit du dépôt.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_redirect' ), 0 );
		add_filter( 'template_include', array( __CLASS__, 'load_template' ), 99 );
	}

	/**
	 * Vrai si l'adresse demandée est une page de dépôt.
	 *
	 * @return bool
	 */
	public static function is_deposit_request() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- chemin comparé à un motif fixe.
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		return (bool) preg_match( '#^/(?:(?:fr|en|ar)/)?(?:deposer|deposer-une-annonce|deposer-annonce|publier-une-annonce)/?$#', $path );
	}

	/**
	 * Adresse du dépôt dans une langue, pour rester sur le formulaire.
	 *
	 * @param string $lang Code fr, en ou ar.
	 * @return string
	 */
	public static function deposit_url( $lang ) {
		$lang = sanitize_key( (string) $lang );
		$page = class_exists( 'Partikulier_Required_Pages' ) ? Partikulier_Required_Pages::find( 'deposer' ) : null;
		if ( $page instanceof WP_Post && function_exists( 'pll_get_post' ) ) {
			$translated = pll_get_post( $page->ID, $lang );
			if ( $translated ) {
				$url = get_permalink( $translated );
				if ( $url ) {
					return $url;
				}
			}
		}
		if ( 'en' === $lang || 'ar' === $lang ) {
			return home_url( '/' . $lang . '/deposer/' );
		}
		return home_url( '/deposer/' );
	}

	/**
	 * L'ancien aperçu rejoint la page de dépôt.
	 */
	public static function maybe_redirect() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- chemin comparé à un motif fixe.
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		if ( ! preg_match( '#^/(?:(fr|en|ar)/)?apercu-tunnel/?$#', $path, $match ) ) {
			return;
		}
		$lang = isset( $match[1] ) ? $match[1] : '';
		wp_safe_redirect( self::deposit_url( $lang ), 302 );
		exit;
	}

	/**
	 * Affiche le tunnel à la place de l'ancien formulaire.
	 *
	 * @param string $template Gabarit choisi par WordPress.
	 * @return string
	 */
	public static function load_template( $template ) {
		if ( ! self::is_deposit_request() ) {
			return $template;
		}
		$file = get_theme_file_path( 'templates/page-deposer-tunnel.php' );
		if ( ! file_exists( $file ) ) {
			return $template;
		}
		status_header( 200 );
		return $file;
	}

	/**
	 * Imprime le formulaire dans la zone principale, sans remplacer l'en-tête.
	 */
	public static function render() {
		$file = get_theme_file_path( 'apercu-tunnel.html' );
		if ( ! is_readable( $file ) ) {
			return;
		}
		$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier du thème, pas une entrée utilisateur.
		if ( ! is_string( $html ) || '' === $html ) {
			return;
		}
		$css  = '';
		$body = $html;
		if ( preg_match( '#<style>(.*)</style>#s', $html, $style ) ) {
			$css = self::scope_css( $style[1] );
		}
		if ( preg_match( '#<body[^>]*>(.*)</body>#s', $html, $inner ) ) {
			$body = $inner[1];
		}
		echo '<div class="pk-tunnel">';
		if ( '' !== $css ) {
			echo '<style>' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS du thème, déjà figé.
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document HTML autonome du thème, déjà figé.
		echo '</div>';
	}

	/**
	 * Limite les styles du tunnel à son bloc, pour laisser l'en-tête intact.
	 *
	 * @param string $css Feuille du tunnel.
	 * @return string
	 */
	private static function scope_css( $css ) {
		$css = preg_replace( '#/\\*.*?\\*/#s', '', (string) $css );
		return self::scope_block( $css, '.pk-tunnel' );
	}

	/**
	 * Préfixe les sélecteurs d'un bloc, y compris dans les media queries.
	 *
	 * @param string $css   Bloc CSS.
	 * @param string $scope Préfixe.
	 * @return string
	 */
	private static function scope_block( $css, $scope ) {
		$out = '';
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			while ( $i < $len && ctype_space( $css[ $i ] ) ) {
				++$i;
			}
			if ( $i >= $len || '}' === $css[ $i ] ) {
				break;
			}
			$brace = strpos( $css, '{', $i );
			if ( false === $brace ) {
				break;
			}
			$prelude = trim( substr( $css, $i, $brace - $i ) );
			$depth   = 1;
			$j       = $brace + 1;
			while ( $j < $len && $depth ) {
				if ( '{' === $css[ $j ] ) {
					++$depth;
				} elseif ( '}' === $css[ $j ] ) {
					--$depth;
				}
				++$j;
			}
			$body = substr( $css, $brace + 1, $j - $brace - 2 );
			if ( 0 === strpos( $prelude, '@media' ) || 0 === strpos( $prelude, '@supports' ) ) {
				$out .= $prelude . '{' . self::scope_block( $body, $scope ) . '}';
			} elseif ( 0 === strpos( $prelude, '@' ) ) {
				$out .= $prelude . '{' . $body . '}';
			} else {
				$scoped = array();
				foreach ( explode( ',', $prelude ) as $sel ) {
					$sel = trim( $sel );
					if ( '' === $sel ) {
						continue;
					}
					if ( ':root' === $sel || 0 === strpos( $sel, ':root' ) || 'html' === $sel || 'body' === $sel ) {
						$scoped[] = $scope;
					} else {
						$scoped[] = $scope . ' ' . $sel;
					}
				}
				if ( $scoped ) {
					$out .= implode( ',', $scoped ) . '{' . $body . '}';
				}
			}
			$i = $j;
		}
		return $out;
	}
}

Partikulier_Tunnel_Preview::init();
