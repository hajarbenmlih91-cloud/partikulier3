<?php
/**
 * Module runtime de la localisation (lot B6, découpe REG-3 de
 * class-localization.php — 990 lignes historiques).
 *
 * Charge les textdomains du thème (domaine « partikulier » puis rechargement
 * à la locale active ; domaine « es » d'Estatik avec ses trois sources
 * candidates — correctif 6.17.31 du popup d'authentification), optimise les
 * requêtes d'annonces (N+1, pagination 24) et redirige la première visite
 * humaine de la racine selon Accept-Language (robots neutralisés sur le
 * filtre officiel de Polylang — pas de cloaking par langue).
 *
 * Code déplacé VERBATIM depuis class-localization.php (aucune modification de
 * comportement — le mécanisme actif ne change pas au découpage, CDC 3.7
 * REG-3 ; la migration de mécanisme relève du lot C).
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

                /**
                 * Charge les fichiers gettext du thème avant tout dictionnaire de repli.
                 */
                public static function load_textdomain() {
                                if ( ! class_exists( 'Partikulier\\Core\\Domain\\DomainRegistry' ) ) {
                                        load_theme_textdomain( 'partikulier', PARTIKULIER_DIR . '/languages' );
                                }
                        }

                        /**
                         * Polylang peut definir son slug actif apres le chargement initial de WP.
                         * On recharge alors le fichier theme correspondant au slug public.
                         *
                         * Lot C1 (consolidation des catalogues) : le JIT de WP
                         * (_load_textdomain_just_in_time) charge <locale>.mo pour
                         * un theme dont le repertoire languages/ est hors
                         * WP_LANG_DIR — ce nommage legacy est donc LE nom
                         * canonique des catalogues du theme. Les doublons
                         * partikulier-<locale>.mo (copies byte pour byte,
                         * preuve T0 — jamais charges par le JIT) ont ete
                         * supprimes du lot ; ar.mo et en_US.mo restent les
                         * deux seuls catalogues, recompiles sous forme
                         * canonique (hash_addr de fin de table, lisible par
                         * les DEUX lecteurs gettext de WordPress).
                         */
                        public static function load_active_textdomain() {
                                if ( class_exists( 'Partikulier\\Core\\Domain\\DomainRegistry' ) ) {
                                        return;
                                }
                                $slug = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : '';
                                $locale = 'en' === $slug ? 'en_US' : ( 'ar' === $slug ? 'ar' : '' );
                                if ( ! $locale ) {
                                        return;
                                }
                                $file = trailingslashit( PARTIKULIER_DIR ) . 'languages/' . $locale . '.mo';
                                if ( is_readable( $file ) ) {
                                        load_textdomain( 'partikulier', $file );
                                }
                        }

                /**
                 * Recharge le domaine « es » (Estatik) avec la locale de la page.
                 *
                 * 6.17.31 : Estatik charge son textdomain à plugins_loaded avec la
                 * locale du SITE (en_US sur ce déploiement) — le popup
                 * d'authentification imprimé au wp_footer restait donc anglais
                 * dans les vues FR/AR alors que le plugin embarque un catalogue
                 * français complet. Le thème embarque en plus un petit catalogue
                 * arabe (languages/estatik/es-ar.mo) couvrant le chrome visible
                 * de ce popup. Sources consultées dans l'ordre : catalogue du
                 * plugin, catalogue embarqué par le thème, emplacement
                 * communautaire standard WP_LANG_DIR.
                 */
                public static function load_estatik_textdomain() {
                        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
                                return;
                        }
                        $slug = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : '';
                        if ( '' === $slug ) {
                                return;
                        }
                        $locale = 'en' === $slug ? 'en_US' : ( 'fr' === $slug ? 'fr_FR' : ( 'ar' === $slug ? 'ar' : '' ) );
                        if ( ! $locale || ! function_exists( 'unload_textdomain' ) ) {
                                return;
                        }

                        $candidates = array();
                        if ( defined( 'WP_PLUGIN_DIR' ) ) {
                                $candidates[] = trailingslashit( WP_PLUGIN_DIR ) . 'estatik/languages/es-' . $locale . '.mo';
                        }
                        $candidates[] = trailingslashit( PARTIKULIER_DIR ) . 'languages/estatik/es-' . $locale . '.mo';
                        if ( defined( 'WP_LANG_DIR' ) ) {
                                $candidates[] = trailingslashit( WP_LANG_DIR ) . 'plugins/es-' . $locale . '.mo';
                        }

                        foreach ( $candidates as $file ) {
                                if ( is_readable( $file ) ) {
                                        unload_textdomain( 'es' );
                                        load_textdomain( 'es', $file );
                                        /* Le conteneur de réglages d'Estatik met en cache
                                         * statique les default_value résolus par __() à la
                                         * première lecture — on le force à se re-résoudre
                                         * avec le catalogue fraîchement chargé (les titres
                                         * du popup viennent de ces réglages). */
                                        if ( class_exists( 'Es_Settings_Container' ) && method_exists( 'Es_Settings_Container', 'get_available_settings' ) ) {
                                                Es_Settings_Container::get_available_settings( true );
                                        }
                                        return;
                                }
                        }
                }

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
