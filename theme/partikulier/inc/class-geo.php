<?php
/**
 * Module : SEO geographique (local SEO / geo).
 *
 * - Breadcrumbs HTML (avec markup schema injecte via Partikulier_JSONLD)
 * - URLs de villes propres dans la navigation et le sitemap
 * - Bloc "Villes" genere automatiquement sur la home (maillage geo)
 * - Donnees geo pour les LLM : fil d'Ariane lisible, titres geo
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Geo {

	public static function init() {
		// Rien de global a accrocher : classe utilitaire appelee par les templates.
	}

	/**
	 * Breadcrumbs HTML pour les templates.
	 */
	public static function breadcrumbs_html() {
		$crumbs = Partikulier_JSONLD::build_breadcrumbs();
		if ( empty( $crumbs ) ) {
			return '';
		}
		$html  = '<nav class="pk-breadcrumb" aria-label="' . esc_attr__( 'Fil d\'Ariane', 'partikulier' ) . '">';
		$html .= '<ol itemscope itemtype="https://schema.org/BreadcrumbList">';
		foreach ( $crumbs as $i => $crumb ) {
			$is_last = ( $i === count( $crumbs ) - 1 );
			$html .= '<li itemprop="itemListElement" itemscope itemtype="https://schema.org/ListItem">';
			if ( ! $is_last ) {
				$html .= '<a itemprop="item" href="' . esc_url( $crumb['url'] ) . '"><span itemprop="name">' . esc_html( $crumb['name'] ) . '</span></a>';
			} else {
				$html .= '<span aria-current="page" itemprop="name">' . esc_html( $crumb['name'] ) . '</span>';
			}
			$html .= '<meta itemprop="position" content="' . ( $i + 1 ) . '">';
			$html .= '</li>';
		}
		$html .= '</ol></nav>';
		return $html;
	}

	/**
	 * Liste des villes les plus actives (maillage geo de la home).
	 *
	 * @param int $limit Nombre de villes.
	 * @return array Liste de termes avec count.
	 */
	/**
	 * Options HTML "Type de bien" (label/value) pour select de recherche.
	 *
	 * @param bool $with_all Inclure l'option "Tous les biens".
	 * @return string HTML des <option>.
	 */
	public static function property_type_options( $with_all = true ) {
		$terms = get_terms( array(
			'taxonomy'   => PARTIKULIER_ESTATIK_TYPE_TAXONOMY,
			'hide_empty' => false,
			'orderby'    => 'name',
			'order'      => 'ASC',
		) );
		$types = is_wp_error( $terms ) ? array() : $terms;
		if ( empty( $types ) ) {
			$types = array_map( static function ( $label ) {
				return (object) array( 'slug' => sanitize_title( $label ), 'name' => $label );
			}, array( 'Appartement', 'Maison', 'Terrain', 'Immeuble', 'Parking', 'Local' ) );
		}
		$out = '';
		if ( $with_all ) {
			$out .= '<option value="">' . esc_html__( 'Tous les biens', 'partikulier' ) . '</option>';
		}
		$lang = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr';
		foreach ( $types as $t ) {
			$label = ( 'ar' === $lang && class_exists( 'Partikulier_Listing_I18n' ) )
				? Partikulier_Listing_I18n::localized_type( $t->name, $lang )
				: $t->name;
			$out .= '<option value="' . esc_attr( $t->slug ) . '">' . esc_html( $label ) . '</option>';
		}
		return $out;
	}

	public static function top_cities( $limit = 12 ) {
		$terms = get_terms( array(
			'taxonomy'   => PARTIKULIER_ESTATIK_LOCATION_TAXONOMY,
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => $limit,
		) );
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Regions les plus actives.
	 */
	public static function top_regions( $limit = 12 ) {
		$terms = get_terms( array(
			'taxonomy'   => PARTIKULIER_ESTATIK_LOCATION_TAXONOMY,
			'hide_empty' => true,
			'orderby'    => 'count',
			'order'      => 'DESC',
			'number'     => $limit,
		) );
		return is_wp_error( $terms ) ? array() : $terms;
	}

	/**
	 * Localisation lisible d'une annonce : "Rue X, Quartier Y, Ville, Region".
	 */
	public static function location_string( $post_id, $lang = '' ) {
		$parts = array();
		$lang  = $lang ? $lang : ( function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr' );
		// Optimisation senior : utiliser get_the_terms() pour beneficier du cache de WP_Query.
		$terms = get_the_terms( $post_id, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
		if ( ! is_wp_error( $terms ) && ! empty( $terms ) ) {
			foreach ( $terms as $term ) {
				$name = $term->name;
				if ( 'fr' !== $lang && function_exists( 'pll_get_term' ) ) {
					$translated_id = (int) pll_get_term( $term->term_id, $lang );
					$translated    = $translated_id ? get_term( $translated_id, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) : false;
					if ( $translated && ! is_wp_error( $translated ) ) {
						$name = $translated->name;
					}
				}
				if ( class_exists( 'Partikulier_Listing_I18n' ) ) {
					$name = Partikulier_Listing_I18n::localized_place( $name, $lang );
				}
				$parts[] = $name;
			}
		}
		return implode( 'ar' === $lang ? '، ' : ', ', array_unique( array_filter( $parts ) ) );
	}

	/**
	 * URL de la ville d'une annonce (pour le bouton "Voir les annonces de cette ville").
	 */
		public static function city_link( $post_id ) {
			$terms = get_the_terms( $post_id, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
			if ( $terms && ! is_wp_error( $terms ) ) {
				return function_exists( 'pk_term_url' ) ? pk_term_url( $terms[0] ) : get_term_link( $terms[0] );
			}
			return pk_properties_archive_url();
		}
}

Partikulier_Geo::init();