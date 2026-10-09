<?php
/**
 * Traductions Polylang des pages requises (favoris, connexion, etc.).
 *
 * Module separe de class-required-pages.php pour rester sous le seuil de
 * 400 lignes du contrat de perimetre (DA-009, baseline theme gelee).
 * Idempotent : ne touche jamais aux traductions existantes.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Page_Translations {

	/**
	 * Cree les traductions Polylang manquantes (en/ar) des pages requises.
	 * Idempotent : ne touche jamais aux traductions existantes. Le contenu des
	 * templates est deja i18n via gettext ; seul le rattachement de langue de la
	 * page manquait (sinon /ar/favoris/ servait la page FR).
	 */
	public static function ensure() {
		if ( ! function_exists( 'pll_set_post_language' ) || ! function_exists( 'pll_save_post_translations' ) ) {
			return array();
		}
		$titles = array(
			'deposer'      => array( 'en' => 'Post an ad', 'ar' => 'أضف إعلانك' ),
			'mes-annonces' => array( 'en' => 'My listings', 'ar' => 'إعلاناتي' ),
			'connexion'    => array( 'en' => 'Sign in', 'ar' => 'تسجيل الدخول' ),
			'favoris'      => array( 'en' => 'Favorites', 'ar' => 'المفضلة' ),
			'faq'          => array( 'en' => 'Frequently asked questions', 'ar' => 'الأسئلة الشائعة' ),
			'contact'      => array( 'en' => 'Contact us', 'ar' => 'اتصل بنا' ),
		);
		$created = array();
		foreach ( Partikulier_Required_Pages::pages() as $slug => $definition ) {
			$fr = get_posts(
				array(
					'post_type'        => 'page',
					'post_status'      => 'publish',
					'posts_per_page'   => 1,
					'fields'           => 'ids',
					'name'             => $slug,
					'suppress_filters' => true,
				)
			);
			if ( ! $fr ) {
				continue;
			}
			$fr_id    = (int) $fr[0];
			$group    = array( 'fr' => $fr_id );
			$template = get_page_template_slug( $fr_id ) ? get_page_template_slug( $fr_id ) : $definition['template'];
			foreach ( array( 'en', 'ar' ) as $lang ) {
				$existing = get_posts(
					array(
						'post_type'        => 'page',
						'post_status'      => 'publish',
						'posts_per_page'   => 1,
						'fields'           => 'ids',
						'name'             => $slug . '-' . $lang,
						'suppress_filters' => true,
					)
				);
				if ( $existing ) {
					$group[ $lang ] = (int) $existing[0];
					if ( function_exists( 'pll_set_post_language' ) ) {
						pll_set_post_language( (int) $existing[0], $lang );
					}
					continue;
				}
				$id = wp_insert_post(
					array(
						'post_type'    => 'page',
						'post_status'  => 'publish',
						'post_title'   => $titles[ $slug ][ $lang ] ?? $definition['title'],
						'post_name'    => $slug . '-' . $lang,
						'post_content' => $definition['content'],
					),
					true
				);
				if ( is_wp_error( $id ) || ! $id ) {
					continue;
				}
				update_post_meta( (int) $id, '_wp_page_template', $template );
				pll_set_post_language( (int) $id, $lang );
				$group[ $lang ] = (int) $id;
				$created[]      = $slug . ':' . $lang;
			}
			if ( count( $group ) > 1 ) {
				pll_save_post_translations( $group );
			}
		}
		if ( $created && class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
			Partikulier_Cache::purge_all();
		}
		return $created;
	}

}
