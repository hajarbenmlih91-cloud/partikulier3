<?php
/**
 * Module runtime de la localisation (lot B6, découpe REG-3 de
 * class-localization.php — 990 lignes historiques).
 *
 * Optimise les requêtes d'annonces (N+1, pagination 24) et redirige la
 * première visite humaine de la racine selon Accept-Language (robots
 * neutralisés sur le filtre officiel de Polylang — pas de cloaking par
 * langue).
 *
 * Code déplacé VERBATIM depuis class-localization.php (aucune modification de
 * comportement — le mécanisme actif ne change pas au découpage, CDC 3.7
 * REG-3 ; la migration de mécanisme relève du lot C).
 *
 * Lot C4 (extinction FINALE, CDC v1.2 §3.2 I18N-1 — retrait physique, base
 * 6.19.0) : les trois chargeurs de textdomains marqués dormants au lot C3
 * (init@5, wp@1 et wp@2 + l'appel anticipé) sont RETIRÉS du code du thème —
 * leurs gardes et leurs accrochages ont disparu avec eux. Le chargement des
 * domaines « partikulier » et « es » d'Estatik appartient désormais au
 * chargeur unique du plugin (\Partikulier\Core\Domain\I18n\I18nDomainLoader,
 * plugin 2.9+, inscrit au bootstrap) et les catalogues du domaine vivent
 * côté plugin (kit canonique languages/ du plugin, copie de parité retirée
 * du thème au même lot) ; le catalogue arabe du popup d'authentification
 * reste servi depuis languages/estatik/ du thème, source que le chargeur
 * unique consulte (deuxième source candidate). Sans le plugin, le site est
 * servi en langue source française (msgids) — dégradation documentée au
 * contrat du lot (partikulier-core/tests/i18n-unified-mechanism-contract,
 * assertions C3A-011/012).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

trait Partikulier_Localization_Runtime {

        /**
         * Optimise les requêtes de propriétés pour éviter les N+1 (terms/meta cache)
         * et ajuste la pagination pour une meilleure performance.
         */
                /**
                 * Force le réglage Estatik à 24 pour la cohérence globale.
                 */
                public static function force_estatik_pagination( $settings ) {
                        if ( is_array( $settings ) ) {
                                $settings['properties_per_page'] = 24;
                        }
                        return $settings;
                }

                public static function optimize_property_queries( $query ) {
                        if ( is_admin() || ! $query->is_main_query() ) {
                                return;
                        }

                                        if ( $query->get( 'post_type' ) === PARTIKULIER_ESTATIK_POST_TYPE || $query->is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) || $query->is_tax( array( PARTIKULIER_ESTATIK_TYPE_TAXONOMY, PARTIKULIER_ESTATIK_STATUS_TAXONOMY, PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) ) ) {
                                                // Forcer le cache des termes et meta pour éviter les N+1 dans les cartes.
                                                $query->set( 'cache_results', true );
                                                $query->set( 'update_post_term_cache', true );
                                                $query->set( 'update_post_meta_cache', true );
                                        
                                        // Optimisation senior : forcer 24 par page sur l'archive pour respecter la grille responsive et limiter les requêtes N+1.
                                        if ( ! is_admin() && ( $query->is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) || $query->is_tax() ) ) {
                                                $query->set( 'posts_per_page', (int) apply_filters( 'pk_properties_per_page', 24 ) );
                                        }
                                }
        }

        /* Lot C4 — RETRAIT PHYSIQUE des chargeurs de textdomains : les trois
         * méthodes qui vivaient ici (init@5, wp@1 et wp@2, plus l'appel
         * anticipé du domaine « es ») sont supprimées du code, gardes et
         * accrochages compris — voir l'en-tête du module. Le chargement
         * runtime des domaines « partikulier » et « es » appartient au
         * chargeur unique du plugin (partikulier-core 2.9+). Les méthodes
         * conservées ci-dessous ne touchent à aucun textdomain. */

                /**
                 * Redirige uniquement la première visite humaine de la racine selon
                 * Accept-Language lorsque Polylang browser detection est activée.
                 */
                public static function maybe_redirect_browser_language() {
                        if ( ! function_exists( 'pll_home_url' ) || ! self::is_root_request() || is_user_logged_in() ) {
                                return;
                        }
                        $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
                        if ( '/' !== trailingslashit( (string) $path ) || self::is_robot_request() ) {
                                return;
                        }
                        $browser_enabled = function_exists( 'pll_get_option' ) ? pll_get_option( 'browser' ) : false;
                        if ( ! $browser_enabled || ! empty( $_COOKIE['pll_language'] ) ) {
                                return;
                        }
                        $accept = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? strtolower( (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) : '';
                        $lang = false;
                        if ( preg_match( '/(?:^|,)\\s*ar(?:[-_][a-z]+)?(?:\\s*;|,|$)/i', $accept ) ) {
                                $lang = 'ar';
                        } elseif ( preg_match( '/(?:^|,)\\s*en(?:[-_][a-z]+)?(?:\\s*;|,|$)/i', $accept ) ) {
                                $lang = 'en';
                        }
                        if ( ! $lang ) {
                                return;
                        }
                        $languages = function_exists( 'pll_languages_list' ) ? pll_languages_list() : array();
                        if ( ! in_array( $lang, array_map( 'sanitize_key', (array) $languages ), true ) ) {
                                return;
                        }
                        wp_safe_redirect( pll_home_url( $lang ), 302 );
                        exit;
                }

                private static function is_root_request() {
                        $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
                        return '/' === trailingslashit( (string) $path );
                }

                /**
                 * Polylang exécute sa redirection browser avant template_redirect.
                 * Les robots doivent donc être neutralisés sur son filtre officiel, sinon
                 * l’exemption locale arrive trop tard et produit un cloaking par langue.
                 *
                 * @param string|false $language Langue préférée détectée.
                 * @param bool         $cookie   Préférence issue d’un cookie.
                 * @return string|false
                 */
                public static function filter_robot_preferred_language( $language, $cookie ) {
                        unset( $cookie );
                        if ( ! self::is_robot_request() ) {
                                return $language;
                        }
                        $default = function_exists( 'pll_default_language' ) ? pll_default_language( 'slug' ) : '';
                        return $default ? sanitize_key( $default ) : $language;
                }

                private static function is_robot_request() {
                        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
                        return '' !== $ua && (bool) preg_match( '/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|linkedinbot|whatsapp/i', $ua );
                }
}
