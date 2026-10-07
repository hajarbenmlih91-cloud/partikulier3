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
						if ( 'publish' !== $existing->post_status || $slug !== $existing->post_name ) {
							wp_update_post( array( 'ID' => (int) $existing->ID, 'post_status' => 'publish', 'post_name' => $slug ) );
						}
						update_post_meta( (int) $existing->ID, '_wp_page_template', $def['template'] );
						pll_set_post_language( (int) $existing->ID, $lang );
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
				wp_update_post( array( 'ID' => (int) $id, 'post_name' => $slug ) );
				update_post_meta( (int) $id, '_wp_page_template', $def['template'] );
				$map[ $lang ] = (int) $id;
			}
			if ( count( $map ) > 1 ) {
				pll_save_post_translations( $map );
			}
		}
	}
}
