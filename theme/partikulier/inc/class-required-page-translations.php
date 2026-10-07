<?php
/**
 * Traductions Polylang des pages structurelles (deposer, faq, contact).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	return;
}

class Partikulier_Required_Page_Translations {

	/**
	 * Même slug par langue : /ar/deposer/ au lieu d’un 301 vers /fr/deposer/.
	 */
	public static function ensure() {
		if ( ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_save_post_translations' ) ) {
			return;
		}
		if ( class_exists( 'Partikulier_Required_Pages' ) ) {
			Partikulier_Required_Pages::create_missing();
		}
		$langs  = array( 'fr', 'en', 'ar' );
		$titles = array(
			'deposer' => array( 'fr' => 'Déposer une annonce', 'en' => 'Post a listing', 'ar' => 'إضافة إعلان' ),
			'faq'     => array( 'fr' => 'Questions fréquentes', 'en' => 'FAQ', 'ar' => 'الأسئلة الشائعة' ),
			'contact' => array( 'fr' => 'Contactez-nous', 'en' => 'Contact us', 'ar' => 'اتصل بنا' ),
		);
		$pages = class_exists( 'Partikulier_Required_Pages' ) ? Partikulier_Required_Pages::pages() : array();
		foreach ( array_keys( $titles ) as $slug ) {
			$def = $pages[ $slug ] ?? null;
			if ( ! $def ) {
				continue;
			}
			$source = Partikulier_Required_Pages::find( $slug );
			if ( ! $source instanceof WP_Post ) {
				continue;
			}
			$map = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $source->ID ) : array();
			if ( ! is_array( $map ) ) {
				$map = array();
			}
			$src_lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $source->ID ) : 'fr';
			if ( $src_lang ) {
				$map[ $src_lang ] = (int) $source->ID;
			}
			foreach ( $langs as $lang ) {
				if ( ! empty( $map[ $lang ] ) ) {
					$existing = get_post( (int) $map[ $lang ] );
					if ( $existing instanceof WP_Post && 'trash' !== $existing->post_status ) {
						if ( 'publish' !== $existing->post_status ) {
							wp_update_post( array( 'ID' => (int) $existing->ID, 'post_status' => 'publish' ) );
						}
						update_post_meta( (int) $existing->ID, '_wp_page_template', $def['template'] );
						pll_set_post_language( (int) $existing->ID, $lang );
						self::force_slug( (int) $existing->ID, $slug );
						$map[ $lang ] = (int) $existing->ID;
						continue;
					}
				}
				$id = wp_insert_post(
					array(
						'post_type'      => 'page',
						'post_status'    => 'publish',
						'post_title'     => $titles[ $slug ][ $lang ],
						'post_name'      => $slug,
						'post_content'   => $def['content'],
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					),
					true
				);
				if ( is_wp_error( $id ) || ! $id ) {
					continue;
				}
				pll_set_post_language( (int) $id, $lang );
				self::force_slug( (int) $id, $slug );
				update_post_meta( (int) $id, '_wp_page_template', $def['template'] );
				$map[ $lang ] = (int) $id;
			}
			if ( count( $map ) > 1 ) {
				pll_save_post_translations( $map );
			}
		}
		flush_rewrite_rules( false );
	}

	/**
	 * Polylang gratuit n’autorise pas le même slug via wp_update_post.
	 * L’URL publique est /{lang}/{slug}/ — le slug doit être identique.
	 */
	private static function force_slug( $id, $slug ) {
		global $wpdb;
		$wpdb->update(
			$wpdb->posts,
			array( 'post_name' => $slug ),
			array( 'ID' => (int) $id ),
			array( '%s' ),
			array( '%d' )
		);
		clean_post_cache( (int) $id );
	}

	/**
	 * WP résout le 1er post_name ; sans ça /ar/deposer/ = page FR + 301.
	 */
	public static function resolve_query( $q ) {
		if ( is_admin() || ! $q instanceof WP_Query || ! $q->is_main_query() ) {
			return;
		}
		$pagename = (string) $q->get( 'pagename' );
		if ( false !== strpos( $pagename, '/' ) ) {
			$pagename = basename( $pagename );
		}
		if ( ! in_array( $pagename, array( 'deposer', 'faq', 'contact' ), true ) ) {
			return;
		}
		if ( ! function_exists( 'pll_current_language' ) ) {
			return;
		}
		$lang = pll_current_language( 'slug' );
		if ( ! $lang ) {
			return;
		}
		$found = get_posts(
			array(
				'post_type'              => 'page',
				'post_status'            => 'publish',
				'name'                   => $pagename,
				'posts_per_page'         => 1,
				'fields'                 => 'ids',
				'lang'                   => $lang,
				'suppress_filters'       => false,
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		if ( empty( $found ) ) {
			return;
		}
		$q->set( 'page_id', (int) $found[0] );
		$q->set( 'pagename', '' );
		$q->set( 'name', '' );
		$q->is_page     = true;
		$q->is_singular = true;
	}

	public static function keep_canonical( $redirect_url, $language = null ) {
		if ( ! is_page() ) {
			return $redirect_url;
		}
		$slug = get_post_field( 'post_name', get_queried_object_id() );
		if ( in_array( $slug, array( 'deposer', 'faq', 'contact' ), true ) ) {
			return false;
		}
		return $redirect_url;
	}

	public static function init() {
		add_action( 'pre_get_posts', array( __CLASS__, 'resolve_query' ) );
		add_filter( 'pll_check_canonical_url', array( __CLASS__, 'keep_canonical' ), 10, 2 );
		add_filter(
			'redirect_canonical',
			static function ( $redirect, $request ) {
				if ( is_string( $request ) && preg_match( '#/(ar|en|fr)/(deposer|faq|contact)/?$#', $request ) ) {
					return false;
				}
				return $redirect;
			},
			10,
			2
		);
	}
}

Partikulier_Required_Page_Translations::init();
