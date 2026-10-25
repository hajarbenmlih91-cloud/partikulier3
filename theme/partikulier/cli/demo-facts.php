<?php
/**
 * Faits des annonces de démonstration. Idempotent. UAT seulement.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit( 'CLI only' );
}

if ( ! function_exists( 'pk_demo_apply_card_facts' ) ) {
	/**
	 * Enregistre les faits demandés sur les annonces de démonstration.
	 *
	 * @return void
	 */
	function pk_demo_apply_card_facts() {
		$cpt = defined( 'PARTIKULIER_ESTATIK_POST_TYPE' ) ? PARTIKULIER_ESTATIK_POST_TYPE : 'properties';
		pk_demo_patch_known( $cpt );
		pk_demo_ensure_examples( $cpt );
		if ( class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
			Partikulier_Cache::purge_all();
		}
	}
}

if ( ! function_exists( 'pk_demo_french_ids' ) ) {
	/**
	 * Annonces françaises, filtres Polylang ignorés.
	 *
	 * @param string $cpt Type.
	 * @return int[]
	 */
	function pk_demo_french_ids( $cpt ) {
		$ids = get_posts(
			array(
				'post_type'        => $cpt,
				'post_status'      => 'publish',
				'posts_per_page'   => 200,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'lang'             => '',
			)
		);
		$out = array();
		foreach ( $ids as $id ) {
			$id   = (int) $id;
			$lang = function_exists( 'pll_get_post_language' ) ? (string) pll_get_post_language( $id, 'slug' ) : 'fr';
			if ( '' === $lang || 'fr' === $lang ) {
				$out[] = $id;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'pk_demo_family_ids' ) ) {
	/**
	 * L'annonce et ses traductions.
	 *
	 * @param int $post_id Identifiant.
	 * @return int[]
	 */
	function pk_demo_family_ids( $post_id ) {
		$ids = array( (int) $post_id );
		if ( function_exists( 'pll_get_post_translations' ) ) {
			$map = pll_get_post_translations( $post_id );
			if ( is_array( $map ) ) {
				foreach ( $map as $id ) {
					$ids[] = (int) $id;
				}
			}
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}
}

if ( ! function_exists( 'pk_demo_write_meta' ) ) {
	/**
	 * Écrit les mêmes metas sur toute la famille de langues.
	 *
	 * @param int   $post_id Identifiant.
	 * @param array $meta    Clé => valeur.
	 * @return void
	 */
	function pk_demo_write_meta( $post_id, $meta ) {
		foreach ( pk_demo_family_ids( $post_id ) as $id ) {
			foreach ( $meta as $key => $value ) {
				update_post_meta( $id, $key, $value );
			}
		}
	}
}

if ( ! function_exists( 'pk_demo_patch_known' ) ) {
	/**
	 * Complète les annonces déjà affichées sur l'accueil.
	 *
	 * @param string $cpt Type.
	 * @return void
	 */
	function pk_demo_patch_known( $cpt ) {
		foreach ( pk_demo_french_ids( $cpt ) as $id ) {
			$title = remove_accents( html_entity_decode( get_the_title( $id ), ENT_QUOTES, 'UTF-8' ) );
			$low   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $title ) : strtolower( $title );
			if ( false !== strpos( $low, 'saiss' ) && false !== strpos( $low, 'villa' ) ) {
				pk_demo_write_meta(
					$id,
					array(
						'es_property_bedrooms' => 4,
						'_pk_bedrooms_label'   => '4',
					)
				);
				pk_demo_replace_bedroom_phrase( $id );
			}
			if ( false !== strpos( $low, '540' ) && false !== strpos( $low, 'immeuble' ) ) {
				pk_demo_write_meta( $id, array( '_pk_etages' => 'RDC+7' ) );
			}
			if ( false !== strpos( $low, '204' ) && false !== strpos( $low, 'maison' ) ) {
				pk_demo_write_meta( $id, array( '_pk_etages' => 'RDC+1' ) );
			}
			if ( false !== strpos( $low, '404' ) && false !== strpos( $low, 'terrain' ) && false !== strpos( $low, 'malabata' ) ) {
				pk_demo_write_meta( $id, array( '_pk_lot_nature' => 'résidentiel' ) );
			}
		}
	}
}

if ( ! function_exists( 'pk_demo_replace_bedroom_phrase' ) ) {
	/**
	 * Le texte de la villa disait encore « 3 et plus ».
	 *
	 * @param int $post_id Identifiant français.
	 * @return void
	 */
	function pk_demo_replace_bedroom_phrase( $post_id ) {
		$from = array( '3 chambres + salon ou plus', '3 chambres ou plus', '3+' );
		$to   = array( '4 chambres', '4 chambres', '4' );
		foreach ( pk_demo_family_ids( $post_id ) as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$content = str_replace( $from, $to, (string) $post->post_content );
			$excerpt = str_replace( $from, $to, (string) $post->post_excerpt );
			if ( $content !== $post->post_content || $excerpt !== $post->post_excerpt ) {
				wp_update_post(
					array(
						'ID'           => $id,
						'post_content' => $content,
						'post_excerpt' => $excerpt,
					)
				);
			}
		}
	}
}

if ( ! function_exists( 'pk_demo_ensure_examples' ) ) {
	/**
	 * Une annonce de démonstration par type encore absent.
	 *
	 * @param string $cpt Type.
	 * @return void
	 */
	function pk_demo_ensure_examples( $cpt ) {
		$examples = array(
			array(
				'key'      => 'bureau-tanger',
				'type'     => 'Bureau',
				'slug'     => 'bureau',
				'city'     => 'Tanger',
				'city_slug'=> 'tanger',
				'district' => 'Marshan',
				'surface'  => 100,
				'title'    => 'Bureau de 100 m² à vendre à Marshan, Tanger',
				'meta'     => array(
					'es_property_area'       => 100,
					'_pk_bureaux_fermes'     => '3',
					'es_property_bathrooms'  => 1,
					'_pk_bathrooms_label'    => '1',
					'_pk_amenagement'        => 'cloisonne',
				),
			),
			array(
				'key'      => 'depot-casablanca',
				'type'     => 'Dépôt',
				'slug'     => 'depot',
				'city'     => 'Casablanca',
				'city_slug'=> 'casablanca',
				'district' => 'Ain Sebaa',
				'surface'  => 404,
				'title'    => 'Dépôt de 404 m² à vendre à Ain Sebaa, Casablanca',
				'meta'     => array(
					'es_property_area' => 404,
				),
			),
			array(
				'key'      => 'hangar-rabat',
				'type'     => 'Hangar',
				'slug'     => 'hangar',
				'city'     => 'Rabat',
				'city_slug'=> 'rabat',
				'district' => 'Hay Riad',
				'surface'  => 1000,
				'title'    => 'Hangar de 1 000 m² à vendre à Hay Riad, Rabat',
				'meta'     => array(
					'es_property_area' => 1000,
				),
			),
			array(
				'key'      => 'usine-settat',
				'type'     => 'Usine',
				'slug'     => 'usine',
				'city'     => 'Settat',
				'city_slug'=> 'settat',
				'district' => 'Centre-ville',
				'surface'  => 1200,
				'title'    => 'Usine de 1 200 m² à vendre à Centre-ville, Settat',
				'meta'     => array(
					'es_property_area' => 1200,
				),
			),
		);
		foreach ( $examples as $example ) {
			pk_demo_ensure_one( $cpt, $example );
		}
	}
}

if ( ! function_exists( 'pk_demo_find_by_key' ) ) {
	/**
	 * @param string $cpt Type.
	 * @param string $key Clé.
	 * @return int
	 */
	function pk_demo_find_by_key( $cpt, $key ) {
		$ids = get_posts(
			array(
				'post_type'        => $cpt,
				'post_status'      => 'any',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'meta_key'         => '_pk_demo_fact_key',
				'meta_value'       => $key,
				'suppress_filters' => true,
				'lang'             => '',
			)
		);
		return $ids ? (int) $ids[0] : 0;
	}
}

if ( ! function_exists( 'pk_demo_term' ) ) {
	/**
	 * @param string $taxonomy Taxonomie.
	 * @param string $name     Nom.
	 * @param string $slug     Slug.
	 * @return int
	 */
	function pk_demo_term( $taxonomy, $name, $slug ) {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term ) {
			$term = get_term_by( 'name', $name, $taxonomy );
		}
		if ( ! $term ) {
			$res = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
			if ( is_wp_error( $res ) ) {
				return 0;
			}
			$term = get_term( (int) $res['term_id'], $taxonomy );
		}
		if ( ! $term || is_wp_error( $term ) ) {
			return 0;
		}
		$tid = (int) $term->term_id;
		if ( function_exists( 'pll_get_term_language' ) && function_exists( 'pll_set_term_language' ) && ! pll_get_term_language( $tid, 'slug' ) ) {
			pll_set_term_language( $tid, 'fr' );
		}
		return $tid;
	}
}

if ( ! function_exists( 'pk_demo_ensure_one' ) ) {
	/**
	 * @param string $cpt     Type.
	 * @param array  $example Fiche.
	 * @return void
	 */
	function pk_demo_ensure_one( $cpt, $example ) {
		$existing = pk_demo_find_by_key( $cpt, $example['key'] );
		if ( $existing ) {
			pk_demo_write_meta( $existing, $example['meta'] );
			return;
		}
		$template_ids = get_posts(
			array(
				'post_type'        => $cpt,
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'meta_key'         => '_thumbnail_id',
				'suppress_filters' => true,
				'lang'             => '',
			)
		);
		$template = $template_ids ? (int) $template_ids[0] : 0;
		$thumb    = $template ? (int) get_post_thumbnail_id( $template ) : 0;
		$gallery  = $template ? get_post_meta( $template, 'es_property_gallery', true ) : array();
		if ( ! is_array( $gallery ) ) {
			$gallery = array();
		}
		$type_id = pk_demo_term( PARTIKULIER_ESTATIK_TYPE_TAXONOMY, $example['type'], $example['slug'] );
		$city_id = pk_demo_term( PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, $example['city'], $example['city_slug'] );
		$status  = $template ? get_the_terms( $template, PARTIKULIER_ESTATIK_STATUS_TAXONOMY ) : array();
		$status_id = ( $status && ! is_wp_error( $status ) ) ? (int) $status[0]->term_id : 0;
		$author  = $template ? (int) get_post_field( 'post_author', $template ) : 1;
		$post_id = wp_insert_post(
			array(
				'post_type'    => $cpt,
				'post_status'  => 'publish',
				'post_author'  => $author ? $author : 1,
				'post_title'   => $example['title'],
				'post_content' => 'Annonce de démonstration. ' . $example['title'] . '.',
				'post_excerpt' => $example['title'],
			),
			true
		);
		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return;
		}
		if ( function_exists( 'pll_set_post_language' ) ) {
			pll_set_post_language( $post_id, 'fr' );
		}
		if ( $type_id ) {
			wp_set_object_terms( $post_id, $type_id, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
		}
		if ( $city_id ) {
			wp_set_object_terms( $post_id, $city_id, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
		}
		if ( $status_id ) {
			wp_set_object_terms( $post_id, $status_id, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
		}
		if ( $thumb ) {
			set_post_thumbnail( $post_id, $thumb );
		}
		if ( $gallery ) {
			update_post_meta( $post_id, 'es_property_gallery', array_values( array_map( 'absint', $gallery ) ) );
		}
		update_post_meta( $post_id, '_pk_demo_fact_key', $example['key'] );
		update_post_meta( $post_id, '_pk_seed_demo', '1' );
		update_post_meta( $post_id, '_pk_status', 'actif' );
		update_post_meta( $post_id, '_pk_city_name', $example['city'] );
		update_post_meta( $post_id, '_pk_district_name', $example['district'] );
		pk_demo_write_meta( $post_id, $example['meta'] );
	}
}
