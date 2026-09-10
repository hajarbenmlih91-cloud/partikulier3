<?php
/**
 * Module API post-dépendante de la rédaction multilingue (lot C1, découpe de
 * class-listing-i18n.php — 955 lignes historiques).
 *
 * Les quatre points d'entrée qui partent du post WordPress : traduction
 * libre d'un type ou d'un lieu, titre localisé d'une annonce legacy sans
 * traduction liée (un titre arabe manuel existant reste prioritaire) et
 * composition lisible des chambres/salons pour une carte. Le code est déplacé
 * VERBATIM ; les quatre méthodes publiques sont renommées *_local — le
 * chemin de repli autonome (REG-5) de la couture du shell.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

trait Partikulier_Listing_I18n_Post {

        /**
         * Traduit un libelle de type dans la langue demandee.
         *
         * @param string $type Type source.
         * @param string $lang Langue cible.
         * @return string
         */
        public static function localized_type_local( $type, $lang = '' ) {
                $lang = $lang ? $lang : ( function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr' );
                return self::type_label( (string) $type, $lang );
        }

        /**
         * Traduit un lieu libre en conservant les quartiers inconnus.
         *
         * @param string $place Lieu source.
         * @param string $lang Langue cible.
         * @return string
         */
        public static function localized_place_local( $place, $lang = '' ) {
                $lang = $lang ? $lang : ( function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr' );
                return self::place_in( (string) $place, $lang );
        }

        /**
         * Construit un titre localise pour une annonce legacy sans traduction liee.
         * Un titre arabe manuel existant est toujours prioritaire.
         *
         * @param WP_Post|int $post Annonce.
         * @param string      $lang Langue cible.
         * @return string
         */
        public static function title_from_post_local( $post, $lang = '' ) {
                $post = $post instanceof WP_Post ? $post : get_post( $post );
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
                                if ( $translated_post instanceof WP_Post ) {
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
                $source    = $source instanceof WP_Post ? $source : $post;
                $type_terms = get_the_terms( $source->ID, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
                $type       = ( $type_terms && ! is_wp_error( $type_terms ) ) ? $type_terms[0]->name : 'Bien';
                $cat_terms  = get_the_terms( $source->ID, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
                $cat_name   = ( $cat_terms && ! is_wp_error( $cat_terms ) ) ? strtolower( remove_accents( $cat_terms[0]->name ) ) : '';
                $action     = ( false !== strpos( $cat_name, 'lou' ) || false !== strpos( $cat_name, 'rent' ) || false !== strpos( $cat_name, 'locat' ) ) ? 'louer' : 'vendre';
                $city       = '';
                $district   = '';
                $places     = get_the_terms( $source->ID, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
                if ( $places && ! is_wp_error( $places ) ) {
                        foreach ( $places as $term ) {
                                if ( $term->parent ) {
                                        $district = $term->name;
                                        $parent   = get_term( $term->parent, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
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
                return self::title( $values, $lang );
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
        public static function rooms_label_from_post_local( $post, $lang = '' ) {
                $post = $post instanceof WP_Post ? $post : get_post( $post );
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
                $types = get_the_terms( $post->ID, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
                $type  = ( $types && ! is_wp_error( $types ) ) ? $types[0]->name : '';
                return self::rooms(
                        array(
                                'type'         => $type,
                                'bedrooms'     => $bedrooms,
                                'living_rooms' => $living_rooms,
                        ),
                        $lang
                );
        }
}

