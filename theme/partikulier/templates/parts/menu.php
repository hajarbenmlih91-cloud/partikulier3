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

	public static function fallback_menu( $args ) {
		$items = array(
			( function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' ) ) => __( 'Accueil', 'partikulier' ),
			pk_properties_archive_url()                     => __( 'Annonces', 'partikulier' ),
				pk_page_url( 'deposer', '/deposer/' ) => __( 'Déposer une annonce', 'partikulier' ),
		);
		$items = array_filter( $items );
		echo '<ul class="' . esc_attr( $args['menu_class'] ) . '">';
		foreach ( $items as $url => $label ) {
				$current_home = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' );
				$is_current = ( untrailingslashit( $url ) === untrailingslashit( $current_home ) );
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

	public function start_lvl( &$output, $depth = 0, $args = null ) {
		$indent  = str_repeat( "\t", $depth );
		$output .= "\n$indent<ul class=\"pk-submenu\" role=\"menu\">\n";
	}

	public function start_el( &$output, $data_object, $depth = 0, $args = null, $id = 0 ) {
		if ( ! $data_object instanceof WP_Post ) {
			return;
		}
		$item       = $data_object;
		$indent     = ( $depth > 0 ) ? str_repeat( "\t", $depth ) : '';
		$classes    = empty( $item->classes ) ? array() : (array) $item->classes;
		$classes[]  = 'pk-menu-item';
		if ( in_array( 'menu-item-has-children', $classes, true ) ) {
			$classes[] = 'pk-has-children';
		}
		if ( in_array( 'current-menu-item', $classes, true ) ) {
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
		$atts = array_filter( $atts );

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