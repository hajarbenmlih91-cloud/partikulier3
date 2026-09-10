<?php
/**
 * API post-dépendante de la rédaction multilingue (lot C1 — I18N-1).
 *
 * Port fidèle des quatre points d'entrée partant du post WordPress
 * (Partikulier_Listing_I18n, thème 6.18.x) : traduction libre d'un type ou
 * d'un lieu, titre localisé d'une annonce legacy sans traduction liée (un
 * titre arabe manuel existant reste prioritaire) et composition lisible des
 * chambres/salons pour une carte. Les constantes de taxonomie du thème
 * deviennent des littéraux ('es_type', 'es_status', 'es_location' — pattern
 * B6 : aucune dépendance de constante thème) ; les fonctions Polylang
 * restent gardées par function_exists.
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\I18n;

final class ListingPostTextService
{

        /**
         * Traduit un libelle de type dans la langue demandee.
         *
         * @param string $type Type source.
         * @param string $lang Langue cible.
         * @return string
         */
        public static function localized_type( $type, $lang = '' ) {
                $lang = $lang ? $lang : ( function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr' );
                return ListingVocabulary::type_label( (string) $type, $lang );
        }

        /**
         * Traduit un lieu libre en conservant les quartiers inconnus.
         *
         * @param string $place Lieu source.
         * @param string $lang Langue cible.
         * @return string
         */
        public static function localized_place( $place, $lang = '' ) {
                $lang = $lang ? $lang : ( function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr' );
                return ListingVocabulary::place_in( (string) $place, $lang );
        }

        /**
         * Construit un titre localise pour une annonce legacy sans traduction liee.
         * Un titre arabe manuel existant est toujours prioritaire.
         *
         * @param WP_Post|int $post Annonce.
         * @param string      $lang Langue cible.
         * @return string
         */
        public static function title_from_post( $post, $lang = '' ) {
                $post = $post instanceof \WP_Post ? $post : get_post( $post );
                if ( ! $post ) {
                        return '';
                }
                $lang = $lang ? $lang : ( function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr' );
                if ( 'fr' === $lang ) {
                        return get_the_title( $post );
                }

                $display_post = $post;
                if ( function_exists( 'pll_get_post' ) ) {
                        $translated_id = (int) pll_get_post( $post->ID, $lang );
                        if ( $translated_id && $translated_id !== (int) $post->ID ) {
                                $translated_post = get_post( $translated_id );
                                if ( $translated_post instanceof \WP_Post ) {
                                        $display_post = $translated_post;
                                }
                        }
                }
                $candidate = trim( (string) get_the_title( $display_post ) );
                        if ( 'ar' === $lang && preg_match( '/\p{Arabic}/u', $candidate ) && ! preg_match( '/[A-Za-zÀ-ÿ]/u', $candidate ) ) {
                                return $candidate;
                        }

                $source_id = (int) get_post_meta( $display_post->ID, '_pk_translation_source', true );
                $source    = $source_id ? get_post( $source_id ) : $post;
                $source    = $source instanceof \WP_Post ? $source : $post;
                $type_terms = get_the_terms( $source->ID, 'es_type' );
                $type       = ( $type_terms && ! is_wp_error( $type_terms ) ) ? $type_terms[0]->name : 'Bien';
                $cat_terms  = get_the_terms( $source->ID, 'es_status' );
                $cat_name   = ( $cat_terms && ! is_wp_error( $cat_terms ) ) ? strtolower( remove_accents( $cat_terms[0]->name ) ) : '';
                $action     = ( false !== strpos( $cat_name, 'lou' ) || false !== strpos( $cat_name, 'rent' ) || false !== strpos( $cat_name, 'locat' ) ) ? 'louer' : 'vendre';
                $city       = '';
                $district   = '';
                $places     = get_the_terms( $source->ID, 'es_location' );
                if ( $places && ! is_wp_error( $places ) ) {
                        foreach ( $places as $term ) {
                                if ( $term->parent ) {
                                        $district = $term->name;
                                        $parent   = get_term( $term->parent, 'es_location' );
                                        if ( $parent && ! is_wp_error( $parent ) ) {
                                                $city = $parent->name;
                                        }
                                } elseif ( '' === $city ) {
                                        $city = $term->name;
                                }
                        }
                }
                $bedrooms = (string) get_post_meta( $source->ID, '_pk_bedrooms_label', true );
                if ( '' === $bedrooms ) {
                        $bedrooms = (string) get_post_meta( $source->ID, 'es_property_bedrooms', true );
                }
                if ( '' === $bedrooms ) {
                        $bedrooms = (string) get_post_meta( $source->ID, 'es_bedrooms', true );
                }
                $living_rooms = (string) get_post_meta( $source->ID, '_pk_living_rooms_label', true );
                if ( '' === $living_rooms ) {
                        $living_rooms = (string) get_post_meta( $source->ID, '_pk_living_rooms', true );
                }
                $values = array(
                                'action'          => $action,
                                'type'            => $type,
                                'city'            => $city,
                                'district'        => $district,
                                'surface'         => (int) get_post_meta( $source->ID, 'es_property_area', true ),
                                'bedrooms'        => $bedrooms,
                                'living_rooms'    => $living_rooms,
                                'bathrooms'       => (string) get_post_meta( $source->ID, '_pk_bathrooms_label', true ),
                        'floor'           => (string) get_post_meta( $source->ID, '_pk_floor', true ),
                        'garage'          => (string) get_post_meta( $source->ID, '_pk_garage', true ),
                        'elevator'        => (string) get_post_meta( $source->ID, '_pk_elevator', true ),
                        'vis_a_vis'       => (string) get_post_meta( $source->ID, '_pk_vis_a_vis', true ),
                        'terrace'         => (string) get_post_meta( $source->ID, '_pk_terrace', true ),
                        'terrace_surface' => (string) get_post_meta( $source->ID, '_pk_terrace_surface', true ),
                        'sunshine'        => (string) get_post_meta( $source->ID, '_pk_sunshine', true ),
                );
                return ListingTextService::title( $values, $lang );
        }

        /**
         * Retourne la composition lisible des chambres/salons pour une carte.
         * Les annonces anciennes peuvent ne pas avoir les labels maison : on
         * reprend alors les metas Estatik standard copiees par le rattrapage.
         *
         * @param WP_Post|int $post Annonce.
         * @param string      $lang Langue cible.
         * @return string
         */
        public static function rooms_label_from_post( $post, $lang = '' ) {
                $post = $post instanceof \WP_Post ? $post : get_post( $post );
                if ( ! $post ) {
                        return '';
                }
                $lang = $lang ? $lang : ( function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr' );
                $bedrooms = (string) get_post_meta( $post->ID, '_pk_bedrooms_label', true );
                if ( '' === $bedrooms ) {
                        $bedrooms = (string) get_post_meta( $post->ID, 'es_property_bedrooms', true );
                }
                if ( '' === $bedrooms ) {
                        $bedrooms = (string) get_post_meta( $post->ID, 'es_bedrooms', true );
                }
                $living_rooms = (string) get_post_meta( $post->ID, '_pk_living_rooms_label', true );
                if ( '' === $living_rooms ) {
                        $living_rooms = (string) get_post_meta( $post->ID, '_pk_living_rooms', true );
                }
                if ( '' === $bedrooms ) {
                        return '';
                }
                $types = get_the_terms( $post->ID, 'es_type' );
                $type  = ( $types && ! is_wp_error( $types ) ) ? $types[0]->name : '';
                return ListingVocabulary::rooms(
                        array(
                                'type'         => $type,
                                'bedrooms'     => $bedrooms,
                                'living_rooms' => $living_rooms,
                        ),
                        $lang
                );
        }
}

