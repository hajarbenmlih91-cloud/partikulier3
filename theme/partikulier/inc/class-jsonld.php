<?php
/**
 * Module : schema.org JSON-LD.
 *
 * Optimise la lisibilité pour Google ET les LLM (ChatGPT, Perplexity, Gemini...) :
 * - RealEstateListing / House / Apartment + Offer + Place + GeoCoordinates
 * - ItemList sur les pages d'archive (liste d'annonces)
 * - WebSite + SearchAction (sitelinks search box)
 * - RealEstateAgent (le portail)
 * - BreadcrumbList sur toutes les pages
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_JSONLD {

        /** Prefixe des taxonomies Estatik. */
        const ES_PREFIX = 'es_property_';

        public static function init() {
                add_action( 'wp_head', array( __CLASS__, 'emit' ), 2 );
                /* 6.17.30 — S9.8 : le plugin Estatik imprime son propre bloc
                 * schema.org sur les fiches (@type House : titre brut, extrait
                 * non décodé, adresse latine) en doublon du graphe complet du
                 * thème. Sources d'entités contradictoires pour Google et voie
                 * de fuite des résidus français dans les vues AR — on le retire
                 * au profit du graphe canonique ci-dessous. Le thème est chargé
                 * après les plugins : le hook est déjà enregistré, pas encore
                 * déclenché (wp_head se tire au rendu). */
                remove_action( 'wp_head', 'es_schema_org_script' );
        }

        public static function emit() {
                $graphs = array();

                // --- Page annonce unique : fiche complète ---
                if ( is_singular( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
                        $graphs[] = self::property_graph( get_queried_object() );
                        $graphs[] = self::breadcrumb_graph();
                        $graphs[] = self::website_graph();
                } elseif ( is_front_page() ) {
                        $graphs[] = self::website_graph();
                        $graphs[] = self::organization_graph();
                } elseif ( is_tax() ) {
                        $graphs[] = self::itemlist_graph_tax( get_queried_object() );
                        $graphs[] = self::breadcrumb_graph();
                        $graphs[] = self::website_graph();
                } elseif ( is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
                        $graphs[] = self::itemlist_graph_archive();
                        $graphs[] = self::breadcrumb_graph();
                        $graphs[] = self::website_graph();
                } else {
                        $graphs[] = self::website_graph();
                        $graphs[] = self::breadcrumb_graph();
                }

                $graphs = array_filter( $graphs );
                if ( empty( $graphs ) ) {
                        return;
                }

                $json = wp_json_encode( array( '@context' => 'https://schema.org', '@graph' => array_values( $graphs ) ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
                echo "\n" . '<script type="application/ld+json">' . $json . '</script>' . "\n";
        }

        /**
         * Graphe complet pour une annonce immobilière.
         */
        private static function property_graph( $post ) {
                $url   = get_permalink( $post );
                $image = self::get_images( $post );
                $price = self::get_price( $post );
                $geo   = self::get_geo( $post );
                $addr  = self::get_address( $post );

                $type   = self::get_property_type( $post ); // House, Apartment, ...
                $action = self::get_action( $post );        // Sell, Lease

                $title       = self::localized_title( $post );
                $description = Partikulier_SEO::description( $post );
                if ( ! $description ) {
                        $description = sprintf(
                                /* translators: %s: titre de l'annonce */
                                __( 'Découvrez %s, une annonce immobilière publiée gratuitement entre particuliers sur Partikulier.', 'partikulier' ),
                                $title
                        );
                }

                $listing = array(
                        '@id'            => $url . '#listing',
                        '@type'          => 'RealEstateListing',
                        'url'            => $url,
                        'name'           => $title,
                        'description'    => $description,
                        'datePosted'     => mysql2date( 'Y-m-d\TH:i:sP', $post->post_date_gmt ),
                        'dateModified'   => mysql2date( 'Y-m-d\TH:i:sP', $post->post_modified_gmt ),
                        'isAccessibleForFree' => true,
                );
                if ( $image ) {
                        $listing['image'] = $image;
                }

                $item = array(
                        '@id'   => $url . '#item',
                        '@type' => in_array( $type, array( 'House', 'Apartment', 'SingleFamilyResidence' ), true ) ? $type : 'RealEstateListing',
                        'name'  => $title,
                        'url'   => $url,
                );
                $rooms = self::get_meta( $post, 'es_property_total_rooms' ) ?: self::get_meta( $post, 'es_rooms' );
                $bedrooms = self::get_meta( $post, 'es_property_bedrooms' ) ?: self::get_meta( $post, 'es_bedrooms' );
                $living_rooms = self::get_meta( $post, '_pk_living_rooms' );
                $bathrooms = self::get_meta( $post, 'es_property_bathrooms' ) ?: self::get_meta( $post, 'es_bathrooms' );
                $area = self::get_meta( $post, 'es_property_area' ) ?: self::get_meta( $post, 'es_area' );
                $terrace = self::get_meta( $post, '_pk_terrace' );
                $terrace_surface = self::get_meta( $post, '_pk_terrace_surface' );
                if ( is_numeric( $rooms ) && (int) $rooms > 0 ) {
                        $item['numberOfRooms'] = (int) $rooms;
                }
                if ( is_numeric( $bedrooms ) ) {
                        $item['numberOfBedrooms'] = (int) $bedrooms;
                }
                if ( is_numeric( $bathrooms ) ) {
                        $item['numberOfBathroomsTotal'] = (int) $bathrooms;
                }
                if ( is_numeric( $area ) && (float) $area > 0 ) {
                        $item['floorSize'] = array(
                                '@type'    => 'QuantitativeValue',
                                'value'    => (float) $area,
                                'unitCode' => 'MTK',
                        );
                }
                $additional = array();
                if ( is_numeric( $living_rooms ) ) {
                        $additional[] = array( '@type' => 'PropertyValue', 'name' => __( 'Nombre de salons', 'partikulier' ), 'value' => (int) $living_rooms );
                }
                if ( 'Oui' === $terrace ) {
                        $additional[] = array( '@type' => 'PropertyValue', 'name' => __( 'Terrasse', 'partikulier' ), 'value' => true );
                        if ( is_numeric( $terrace_surface ) && (int) $terrace_surface > 0 ) {
                                $additional[] = array( '@type' => 'PropertyValue', 'name' => __( 'Superficie de la terrasse', 'partikulier' ), 'value' => (int) $terrace_surface, 'unitText' => 'm²' );
                        }
                }
                if ( $additional ) {
                        $item['additionalProperty'] = $additional;
                }

                $closure_status = (string) self::get_meta( $post, '_pk_closure_status' );
                $owner_status   = (string) self::get_meta( $post, '_pk_status' );
                $is_closed      = in_array( $closure_status ?: $owner_status, array( 'vendu', 'loue', 'loué' ), true );
                $offer = array(
                        '@type'          => 'Offer',
                        'url'            => $url,
                        'availability'   => $is_closed ? 'https://schema.org/SoldOut' : 'https://schema.org/InStock',
                        'priceCurrency'  => 'MAD',
                );
                if ( $price ) {
                        $offer['price']           = self::clean_price( $price );
                        $offer['priceSpecification'] = array(
                                '@type'         => 'UnitPriceSpecification',
                                'price'         => self::clean_price( $price ),
                                'priceCurrency' => 'MAD',
                        );
                        if ( 'Location' === $action ) {
                                $offer['priceSpecification']['unitText'] = 'MONTH';
                        }
                }

                $place = array(
                        '@type'   => 'Place',
                        'name'    => self::place_name( $post ),
                        'address' => $addr,
                );
                if ( $geo ) {
                        $place['geo'] = array(
                                '@type'     => 'GeoCoordinates',
                                'latitude'  => $geo['lat'],
                                'longitude' => $geo['lng'],
                        );
                        $place['address']['geo'] = $place['geo'];
                }
                if ( count( $addr ) > 1 ) {
                        $item['address'] = $addr;
                }
                if ( $image ) {
                        $item['image'] = $image;
                }
                $offer['itemOffered'] = $item;
                $listing['offers'] = $offer;
                if ( count( $addr ) > 1 || $geo ) {
                        $listing['contentLocation'] = $place;
                }
                $listing['publisher'] = array(
                        '@type' => 'Organization',
                        'name'  => get_bloginfo( 'name' ),
                        'url'   => home_url( '/' ),
                );

                return $listing;
        }

        /**
         * ItemList pour les archives (SEO des pages de résultats ville/type).
         */
        private static function itemlist_graph_tax( $term ) {
                if ( ! ( $term instanceof WP_Term ) ) {
                        return null;
                }
                $posts = get_posts( array(
                        'post_type'      => PARTIKULIER_ESTATIK_POST_TYPE,
                        'post_status'    => 'publish',
                        'posts_per_page' => 12,
                        'tax_query'      => array( array(
                                'taxonomy' => $term->taxonomy,
                                'field'    => 'term_id',
                                'terms'    => $term->term_id,
                        ) ),
                        'no_found_rows'  => true,
                ) );

                        $term_name = self::localized_place( $term->name );
                        return self::itemlist_from_posts(
                                self::localized_phrase( 'listings_in', $term_name ),
                                        function_exists( 'pk_term_url' ) ? pk_term_url( $term ) : get_term_link( $term ),
                                        $posts
                        );
        }

        private static function itemlist_graph_archive() {
                global $wp_query;
                $posts = isset( $wp_query->posts ) ? $wp_query->posts : array();
                        return self::itemlist_from_posts(
                                self::localized_phrase( 'archive' ),
                                pk_properties_archive_url(),
                                $posts
                        );
        }

        private static function itemlist_from_posts( $name, $url, $posts ) {
                $items = array();
                foreach ( $posts as $i => $post ) {
                        $items[] = array(
                                '@type'    => 'ListItem',
                                'position' => $i + 1,
                                'url'      => get_permalink( $post ),
                                        'name'     => self::localized_title( $post ),
                        );
                }
                return array(
                        '@type'     => 'ItemList',
                        'url'       => $url,
                        'name'      => $name,
                        'numberOfItems' => count( $posts ),
                        'itemListElement' => $items,
                );
        }

        /**
         * WebSite + SearchAction (Sitelinks Search Box).
         */
        private static function website_graph() {
                $home = home_url( '/' );
                return array(
                        '@type'   => 'WebSite',
                        '@id'     => $home . '#website',
                        'url'     => $home,
                        'name'    => get_bloginfo( 'name' ),
                        'description' => get_bloginfo( 'description' ),
                        'inLanguage' => get_locale(),
                        'potentialAction' => array(
                                '@type'       => 'SearchAction',
                                'target'      => array(
                                        '@type'       => 'EntryPoint',
                                        'urlTemplate' => $home . '?s={search_term_string}&post_type=' . PARTIKULIER_ESTATIK_POST_TYPE,
                                ),
                                'query-input' => 'required name=search_term_string',
                        ),
                );
        }

        /**
         * Organisation (le portail Partikulier.com).
         */
        private static function organization_graph() {
                $home = home_url( '/' );
                $data = array(
                        '@type'   => 'RealEstateAgent',
                        '@id'     => $home . '#organization',
                        'url'     => $home,
                        'name'    => get_bloginfo( 'name' ),
                        'sameAs'  => array(),
                        'contactPoint' => array(
                                '@type'             => 'ContactPoint',
                                'contactType'       => 'customer service',
                                'areaServed'        => 'FR',
                                'availableLanguage' => array( 'French' ),
                        ),
                );

                // Schema.org exige une URL valide : on n'ajoute 'logo' que s'il en existe une.
                $pk_logo = self::site_logo_url();
                if ( $pk_logo ) {
                        $data['logo'] = $pk_logo;
                }

                return $data;
        }

        /**
         * Fil d'Ariane (BreadcrumbList).
         */
        private static function breadcrumb_graph() {
                $crumbs = self::build_breadcrumbs();
                if ( empty( $crumbs ) ) {
                        return null;
                }
                return array(
                        '@type'           => 'BreadcrumbList',
                        'itemListElement' => array_map( function ( $crumb, $i ) {
                                return array(
                                        '@type'    => 'ListItem',
                                        'position' => $i + 1,
                                        'name'     => $crumb['name'],
                                        'item'     => $crumb['url'],
                                );
                        }, $crumbs, array_keys( $crumbs ) ),
                );
        }

        /**
         * Fil d'Ariane logique (pas de HTML).
         */
        public static function build_breadcrumbs() {
                $home  = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' );
                $crumbs = array( array( 'name' => get_bloginfo( 'name' ), 'url' => $home ) );

                if ( is_singular( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
                        $post = get_queried_object();
                        $locations = wp_get_object_terms( $post->ID, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, array( 'number' => 1 ) );
                        if ( $locations && ! is_wp_error( $locations ) ) {
                                $location = $locations[0];
                                        $crumbs[] = array( 'name' => self::localized_place( $location->name ), 'url' => function_exists( 'pk_term_url' ) ? pk_term_url( $location ) : get_term_link( $location ) );
                        }
                        $crumbs[] = array( 'name' => self::localized_title( $post ), 'url' => get_permalink( $post ) );
                } elseif ( is_tax() ) {
                        $term = get_queried_object();
                        $crumbs[] = array( 'name' => self::localized_phrase( 'annonces' ), 'url' => pk_properties_archive_url() );
                        if ( $term instanceof WP_Term ) {
                                        $crumbs[] = array( 'name' => self::localized_place( $term->name ), 'url' => function_exists( 'pk_term_url' ) ? pk_term_url( $term ) : get_term_link( $term ) );
                        }
                } elseif ( is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
                        $crumbs[] = array( 'name' => self::localized_phrase( 'listings' ), 'url' => pk_properties_archive_url() );
                } elseif ( is_page() ) {
                        $page = get_queried_object();
                        if ( $page->post_parent ) {
                                $parent = get_post( $page->post_parent );
                                $crumbs[] = array( 'name' => self::localized_title( $parent ), 'url' => get_permalink( $parent ) );
                        }
                        $crumbs[] = array( 'name' => self::localized_title( $page ), 'url' => get_permalink( $page ) );
                }

                return $crumbs;
        }

        /* =========================================================
           Helpers données Estatik
           ======================================================= */

        private static function get_meta( $post, $key ) {
                $value = get_post_meta( $post->ID, $key, true );
                return ( '' !== $value && null !== $value ) ? $value : null;
        }

        private static function get_price( $post ) {
                return self::get_meta( $post, 'es_property_price' ) ?: self::get_meta( $post, 'es_price' ) ?: self::get_meta( $post, '_es_price' );
        }

        /** Nettoie "1 234 567 €" → 1234567. */
        private static function clean_price( $price ) {
                $clean = preg_replace( '/[^0-9.,]/', '', (string) $price );
                $clean = str_replace( ',', '.', $clean );
                // Supprime les séparateurs de milliers s'il y a plus d'un point.
                if ( substr_count( $clean, '.' ) > 1 ) {
                        $clean = str_replace( '.', '', $clean );
                        $clean = preg_replace( '/(\d+)$/', '.$1', $clean );
                }
                $val = (float) $clean;
                return $val > 0 ? round( $val, 2 ) : null;
        }

        private static function get_geo( $post ) {
                $lat = self::get_meta( $post, 'es_latitude' ) ?: self::get_meta( $post, 'es_lat' );
                $lng = self::get_meta( $post, 'es_longitude' ) ?: self::get_meta( $post, 'es_lng' ) ?: self::get_meta( $post, 'es_lon' );
                if ( $lat && $lng && is_numeric( $lat ) && is_numeric( $lng ) ) {
                        return array( 'lat' => (float) $lat, 'lng' => (float) $lng );
                }
                return null;
        }

        private static function current_language() {
                $lang = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr';
                return in_array( $lang, array( 'fr', 'en', 'ar' ), true ) ? $lang : 'fr';
        }

        private static function localized_title( $post ) {
                $lang = self::current_language();
                if ( class_exists( 'Partikulier_Listing_I18n' ) && method_exists( 'Partikulier_Listing_I18n', 'title_from_post' ) ) {
                        $title = Partikulier_Listing_I18n::title_from_post( $post, $lang );
                        if ( '' !== trim( (string) $title ) ) {
                                return $title;
                        }
                }
                return get_the_title( $post );
        }

        private static function localized_place( $name ) {
                $lang = self::current_language();
                if ( class_exists( 'Partikulier_Listing_I18n' ) && method_exists( 'Partikulier_Listing_I18n', 'localized_place' ) ) {
                        return Partikulier_Listing_I18n::localized_place( $name, $lang );
                }
                return $name;
        }

        private static function localized_term_name( $post, $tax ) {
                $terms = get_the_terms( $post->ID, $tax );
                if ( ! $terms || is_wp_error( $terms ) ) {
                        return null;
                }
                $term = reset( $terms );
                $lang = self::current_language();
                if ( 'fr' !== $lang && function_exists( 'pll_get_term' ) ) {
                        $translated_id = (int) pll_get_term( $term->term_id, $lang );
                        $translated    = $translated_id ? get_term( $translated_id, $tax ) : false;
                        if ( $translated && ! is_wp_error( $translated ) ) {
                                $term = $translated;
                        }
                }
                return self::localized_place( $term->name );
        }

        private static function localized_phrase( $key, $argument = '' ) {
                $lang = self::current_language();
                $phrases = array(
                        'archive'      => array( 'fr' => 'Annonces immobilières gratuites', 'en' => 'Free real estate listings', 'ar' => 'إعلانات عقارية مجانية' ),
                        'listings_in'  => array( 'fr' => 'Annonces immobilières à %s', 'en' => 'Real estate listings in %s', 'ar' => 'إعلانات عقارية في %s' ),
                        'listings'     => array( 'fr' => 'Annonces immobilières', 'en' => 'Real estate listings', 'ar' => 'الإعلانات العقارية' ),
                        'annonces'     => array( 'fr' => 'Annonces', 'en' => 'Listings', 'ar' => 'الإعلانات' ),
                );
                $text = $phrases[ $key ][ $lang ] ?? $phrases[ $key ]['fr'];
                return '' !== $argument ? sprintf( $text, $argument ) : $text;
        }

        private static function get_address( $post ) {
                        $city     = self::localized_term_name( $post, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
                $zip      = self::get_meta( $post, 'es_zip' ) ?: self::get_meta( $post, 'es_zip_code' );
                $addr = array( '@type' => 'PostalAddress' );
                if ( $zip ) { $addr['postalCode'] = $zip; }
                if ( $city ) { $addr['addressLocality'] = $city; }
                        $addr['addressCountry'] = 'MA';
                return $addr;
        }

        private static function place_name( $post ) {
                $parts = array_filter( array(
                                self::localized_term_name( $post, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ),
                        ) );
                        return $parts ? implode( ', ', $parts ) : self::localized_title( $post );
        }

        private static function term_name( $post, $tax ) {
                $terms = wp_get_object_terms( $post->ID, $tax, array( 'number' => 1, 'fields' => 'names' ) );
                return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : null;
        }

        private static function get_property_type( $post ) {
                $name = self::term_name( $post, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
                if ( ! $name ) {
                        return 'RealEstateListing';
                }
                $map = array(
                        'maison'        => 'House',
                        'house'         => 'House',
                        'appartement'   => 'Apartment',
                        'appartements'  => 'Apartment',
                        'apartment'     => 'Apartment',
                        'maisons'       => 'House',
                        'terre'         => 'LandOrAccommodation',
                        'terrain'       => 'LandOrAccommodation',
                        'land'          => 'LandOrAccommodation',
                        'parking'       => 'ParkingFacility',
                        'immeuble'      => 'ApartmentComplex',
                        'local'         => 'Store',
                );
                $key = mb_strtolower( $name );
                return $map[ $key ] ?? 'RealEstateListing';
        }

        private static function get_action( $post ) {
                $name = self::term_name( $post, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
                if ( ! $name ) {
                        return 'Sell';
                }
                $key = mb_strtolower( $name );
                if ( false !== strpos( $key, 'locat' ) || false !== strpos( $key, 'rent' ) || false !== strpos( $key, 'louer' ) ) {
                        return 'Location';
                }
                return 'Sell';
        }

        /**
         * Galerie d'images (max 8) pour les graphes.
         */
        private static function get_images( $post ) {
                $ids = get_post_meta( $post->ID, 'es_property_gallery', true );
                if ( ! is_array( $ids ) ) {
                        $ids = array();
                }
                $thumb = get_post_thumbnail_id( $post );
                if ( $thumb && ! in_array( $thumb, $ids, true ) ) {
                        array_unshift( $ids, $thumb );
                }
                $urls = array();
                foreach ( array_slice( array_unique( $ids ), 0, 8 ) as $id ) {
                        $src = wp_get_attachment_image_url( (int) $id, 'large' );
                        if ( $src ) {
                                $urls[] = $src;
                        }
                }
                return $urls ?: null;
        }

        private static function site_logo_url() {
                if ( function_exists( 'get_custom_logo' ) ) {
                        $logo_id = get_theme_mod( 'custom_logo' );
                        if ( $logo_id ) {
                                $src = wp_get_attachment_image_url( $logo_id, 'full' );
                                if ( $src ) {
                                        return $src;
                                }
                        }
                }
                // Repli sur le logo choisi dans les reglages du theme, sinon aucun logo :
                // mieux vaut omettre la propriete que pointer un fichier inexistant.
                if ( class_exists( 'Partikulier_Customization' ) ) {
                        $url = (string) Partikulier_Customization::logo_url();
                        if ( $url ) {
                                return $url;
                        }
                }
                return '';
        }
}

Partikulier_JSONLD::init();