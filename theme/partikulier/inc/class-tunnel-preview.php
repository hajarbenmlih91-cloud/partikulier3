<?php
/**
 * Le tunnel remplace la page WordPress « Déposer une annonce ».
 *
 * Le formulaire préparé est servi seul : pas d'en-tête ni de pied du site.
 * Sa barre de langue change la version, et /en/ ou /ar/ ouvre cette version.
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
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 0 );
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
		return (bool) preg_match( '#^/(?:(?:fr|en|ar)/)?(?:deposer(?:-\d+)?|deposer-une-annonce|deposer-annonce|publier-une-annonce)/?$#', $path );
	}

	/**
	 * Vrai si la page demandée est un dépôt, même avec un slug dupliqué.
	 *
	 * @return bool
	 */
	public static function is_deposit_page() {
		if ( ! is_page() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( preg_match( '/^deposer(?:-\d+)?$/', (string) $post->post_name ) ) {
			return true;
		}
		$template = (string) get_page_template_slug( $post->ID );
		return in_array( $template, array( 'templates/page-deposer-annonce.php', 'templates/page-deposer-tunnel.php' ), true );
	}

	/**
	 * Sert le formulaire avant que le thème n'imprime son en-tête.
	 */
	public static function maybe_serve() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! self::is_deposit_request() && ! self::is_deposit_page() ) {
			return;
		}
		self::serve_document();
	}

	/**
	 * Langue du document : l'adresse d'abord, sinon la langue de la page.
	 *
	 * @return string
	 */
	private static function document_lang() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- chemin comparé à un motif fixe.
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		if ( preg_match( '#^/(fr|en|ar)(?:/|$)#', $path, $match ) ) {
			return $match[1];
		}
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language( 'slug' );
			if ( in_array( $lang, array( 'fr', 'en', 'ar' ), true ) ) {
				return $lang;
			}
		}
		return 'fr';
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
	 * Sert le document préparé, sans en-tête ni pied du thème.
	 */
	public static function serve_document() {
		$file = get_theme_file_path( 'apercu-tunnel.html' );
		if ( ! is_readable( $file ) ) {
			status_header( 500 );
			exit;
		}
		$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier du thème, pas une entrée utilisateur.
		if ( ! is_string( $html ) || '' === $html ) {
			status_header( 500 );
			exit;
		}
		$pk_logo = esc_url( get_theme_file_uri( 'assets/img/pep-logo-white.png' ) );
		$pk_html = preg_replace(
			'#(<a class="pk-form-logo"[^>]*>\s*<img\s+src=")data:image/[^"]+(")#',
			'$1' . $pk_logo . '$2',
			$html,
			1
		);
		if ( is_string( $pk_html ) ) {
			$html = $pk_html;
		}
		$boot = '<script>window.PK_FORCE_LANG=' . wp_json_encode( self::document_lang() ) . ';</script>';
		if ( false === strpos( $html, 'name="robots"' ) ) {
			$html = preg_replace( '#<head>#', "<head>\n<meta name=\"robots\" content=\"noindex, nofollow\">\n" . $boot, $html, 1 );
		} else {
			$html = preg_replace( '#<head>#', '<head>' . $boot, $html, 1 );
		}
		status_header( 200 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'Cache-Control: private, no-store, max-age=0' );
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document HTML autonome du thème, déjà figé.
		exit;
	}

	/**
	 * Ancien rendu dans le thème. La page publique n'utilise plus cette voie.
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
		$home = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' );
		$body = preg_replace(
			'#(<a class="pk-submit-back" href=")[^"]*(">)#',
			'$1' . esc_url( $home ) . '$2',
			$body,
			1
		);
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
