<?php
/**
 * Helpers menu : walker sans jQuery + menu de secours SEO.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Menu de secours si aucun menu n'est configure :
 * liens SEO directs vers les pages essentielles.
 */
class Partikulier_Header {

	public static function init() {
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'localize_archive_items' ), 20, 2 );
		add_filter( 'wp_nav_menu_objects', array( __CLASS__, 'sync_account_item' ), 100, 2 );
	}

	public static function localize_archive_items( $items, $args ) {
		foreach ( $items as $item ) {
				$path = (string) wp_parse_url( $item->url, PHP_URL_PATH );
			if ( '/' === untrailingslashit( $path ) && function_exists( 'pk_localized_home_url' ) ) {
				$item->url = pk_localized_home_url();
				continue;
			}
			if ( preg_match( '#/(?:property|annonces)(?:/page/([0-9]+))?/?$#', $path, $match ) ) {
				$item->url = pk_properties_archive_url();
				if ( ! empty( $match[1] ) ) {
					$item->url = trailingslashit( $item->url ) . 'page/' . absint( $match[1] ) . '/';
				}
			}
		}
		return $items;
	}


	/**
	 * Mon espace seulement si la personne est connectée.
	 * Sinon : Se connecter, vers la page de connexion.
	 *
	 * @param array<int,object> $items Items de menu.
	 * @param object            $args  Arguments du menu.
	 * @return array<int,object>
	 */
	public static function sync_account_item( $items, $args ) {
		unset( $args );
		if ( ! is_array( $items ) ) {
			return $items;
		}
		$space = function_exists( 'pk_page_url' ) ? pk_page_url( 'mes-annonces', '/mes-annonces/' ) : home_url( '/mes-annonces/' );
		$login = function_exists( 'pk_login_page_url' ) ? pk_login_page_url( $space ) : wp_login_url( $space );
		foreach ( $items as $item ) {
			if ( ! is_object( $item ) || ! self::is_account_item( $item ) ) {
				continue;
			}
			if ( is_user_logged_in() ) {
				$item->url   = $space;
				$item->title = self::account_label( 'Mon espace' );
			} else {
				$item->url   = $login;
				$item->title = self::account_label( 'Se connecter' );
			}
		}
		return $items;
	}

	/**
	 * @param object $item Item de menu.
	 */
	private static function is_account_item( $item ) {
		$url   = isset( $item->url ) ? (string) $item->url : '';
		$path  = (string) wp_parse_url( $url, PHP_URL_PATH );
		$title = isset( $item->title ) ? trim( wp_strip_all_tags( (string) $item->title ) ) : '';
		if ( preg_match( '#/(?:mes-annonces|connexion|login|wp-login\.php)(?:/|$)#', $path ) ) {
			return true;
		}
		if ( false !== strpos( $url, 'auth_item=login' ) ) {
			return true;
		}
		return in_array( $title, array( 'Mon espace', 'Se connecter', 'Connexion', 'My account', 'Sign in', 'مساحتي', 'حسابي', 'تسجيل الدخول' ), true );
	}

	/**
	 * @param string $source Libellé français déjà enregistré.
	 */
	private static function account_label( $source ) {
		if ( 'Mon espace' === $source && function_exists( 'pk_account_space_label' ) ) {
			return pk_account_space_label();
		}
		if ( class_exists( 'Partikulier_Localization' ) ) {
			return Partikulier_Localization::translate_polylang_string( $source, $source, 'partikulier' );
		}
		return $source;
	}

	public static function fallback_menu( $args ) {
		$home_link = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' );
		$items = array(
			$home_link                            => __( 'Accueil', 'partikulier' ),
			pk_properties_archive_url()           => __( 'Annonces', 'partikulier' ),
			pk_page_url( 'deposer', '/deposer/' ) => __( 'Déposer une annonce', 'partikulier' ),
			pk_page_url( 'favoris', '/favoris/' ) => __( 'Favoris', 'partikulier' ),
		);
		if ( is_user_logged_in() ) {
			$items[ pk_page_url( 'mes-annonces', '/mes-annonces/' ) ] = __( 'Mon espace', 'partikulier' );
		} else {
			$items[ pk_login_page_url( pk_page_url( 'mes-annonces', '/mes-annonces/' ) ) ] = __( 'Se connecter', 'partikulier' );
		}
		$items = array_filter( $items );
		$menu_class = is_array( $args ) ? ( $args['menu_class'] ?? 'pk-menu' ) : ( is_object( $args ) && isset( $args->menu_class ) ? $args->menu_class : 'pk-menu' );
		echo '<ul class="' . esc_attr( $menu_class ) . '">';
		foreach ( $items as $url => $label ) {
				$is_current = class_exists( 'Partikulier_Menu_Walker' ) && Partikulier_Menu_Walker::url_is_current( $url );
			printf(
				'<li class="pk-menu-item%s"><a href="%s">%s</a></li>',
				$is_current ? ' pk-current' : '',
				esc_url( $url ),
				esc_html( $label )
			);
		}
		echo '</ul>';
	}
}

Partikulier_Header::init();

/**
 * Walker de menu legere : classes BEM, pas de JS requis (dropdowns en CSS).
 */
class Partikulier_Menu_Walker extends Walker_Nav_Menu {

	/**
		 * L'option choisie de la barre, y compris si WordPress ne pose pas current-menu-item.
		 *
		 * @param string $url Adresse de l'entrée.
		 * @return bool
		 */
		public static function url_is_current( $url ) {
			$url = is_scalar( $url ) ? (string) $url : '';
			if ( '' === $url ) {
				return false;
			}
			$item_path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
			$raw       = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
			$raw       = is_scalar( $raw ) ? (string) $raw : '';
			$req_path  = untrailingslashit( (string) wp_parse_url( $raw, PHP_URL_PATH ) );
			if ( '' === $item_path || '' === $req_path ) {
				return false;
			}
			$home      = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' );
			$home_path = untrailingslashit( (string) wp_parse_url( $home, PHP_URL_PATH ) );
			if ( $item_path === $home_path ) {
				return is_front_page() || $req_path === $home_path;
			}
			$item_slash = trailingslashit( $item_path );
			$req_slash  = trailingslashit( $req_path );
			return $req_slash === $item_slash || ( strlen( $item_slash ) > 1 && 0 === strpos( $req_slash, $item_slash ) );
		}

		public function start_lvl( &$output, $depth = 0, $args = null ) {
		$indent  = str_repeat( "\t", $depth );
		$output .= "\n$indent<ul class=\"pk-submenu\" role=\"menu\">\n";
	}

	public function start_el( &$output, $data_object, $depth = 0, $args = null, $id = 0 ) {
		if ( ! $data_object instanceof WP_Post ) {
			return;
		}
		$item      = $data_object;
		$indent    = ( $depth > 0 ) ? str_repeat( "\t", $depth ) : '';
		$classes   = empty( $item->classes ) ? array() : (array) $item->classes;
		$classes[] = 'pk-menu-item';
		if ( in_array( 'menu-item-has-children', $classes, true ) ) {
			$classes[] = 'pk-has-children';
		}
		if ( in_array( 'current-menu-item', $classes, true ) || in_array( 'current-menu-parent', $classes, true ) || in_array( 'current-menu-ancestor', $classes, true ) || self::url_is_current( $item->url ?? '' ) ) {
			$classes[] = 'pk-current';
		}

		$class_names = implode( ' ', array_filter( $classes ) );
		$title       = apply_filters( 'the_title', $item->title, $item->ID );
		$atts        = array(
			'title'  => ! empty( $item->attr_title ) ? $item->attr_title : '',
			'target' => ! empty( $item->target ) ? $item->target : '',
			'rel'    => ! empty( $item->xfn ) ? $item->xfn : '',
			'href'   => ! empty( $item->url ) ? $item->url : '',
		);
		$atts        = array_filter( $atts );

		$attributes = '';
		foreach ( $atts as $attr => $value ) {
			$attributes .= ' ' . $attr . '="' . esc_attr( $value ) . '"';
		}

		$output .= $indent . '<li class="' . esc_attr( $class_names ) . '">';
		$output .= '<a' . $attributes . '>';
		$output .= esc_html( $title );
		$output .= '</a>';
	}
}