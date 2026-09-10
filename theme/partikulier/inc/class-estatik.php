<?php
/**
 * Module : integration ESTATIK.
 *
 * - Verifie la presence du plugin et affiche un message admin si absent
 * - Overide les templates Estatik via filtre es_template_path / dossier estatik/ du theme
 * - Normalise les URLs d'images Estatik vers les AVIF
 * - Fallback : si ESTATIK est absent, les templates maison affichent un CTA d'installation
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Estatik {

        public static function init() {
                // Garantit les dépendances Estatik avant tout enqueue du framework.
                add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_dependency_fallbacks' ), 0 );

// Desactiver les styles dynamiques Estatik qui causent des requetes N+1.
add_action( 'wp_enqueue_scripts', function() { remove_action( 'wp_enqueue_scripts', array( 'Es_Assets', 'frontend_assets' ) ); }, 1 );
                if ( ! self::plugin_active() ) {
                        add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
                        // Si le plugin est installe mais non active, tenter activation.
                        add_action( 'admin_init', array( __CLASS__, 'maybe_activate' ) );
                        return;
                }

                // --- Template overrides ---
                // Estatik v4 charge les overrides depuis {theme}/estatik4/front/...
                // (doc officielle : template-overriding-example). On renforce avec le
                        // filtre es_template_path si le loader du plugin l'applique.
                        add_filter( 'es_template_path', array( __CLASS__, 'template_path' ) );
                        add_filter( 'es_template_file', array( __CLASS__, 'template_file' ) );
                        add_filter( 'template_include', array( __CLASS__, 'property_single_template' ), 999 );

                // --- Normalisation images Estatik vers AVIF ---
                add_filter( 'es_listing_gallery_img', array( __CLASS__, 'avif_image' ) );
                add_filter( 'es_archive_image', array( __CLASS__, 'avif_image' ) );
                add_filter( 'es_single_image', array( __CLASS__, 'avif_image' ) );

                // --- Suppression des assets Estatik lourds : on garde le CSS minimal,
                //     le JS de recherche ajax est conserve car il alimente la recherche hero. ---
                add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_heavy' ), 100 );

                // --- Nettoyage du <head> : Estatik ajoute des meta generator ---
                add_action( 'init', array( __CLASS__, 'remove_estatik_head' ), 999 );

                // --- 6.17.33 — Authentification au design du thème :
                // après une erreur de connexion (mauvais mot de passe), Estatik
                // renvoie vers la page de connexion SANS l'URL de retour : le
                // propriétaire qui retape son mot de passe atterrirait ensuite
                // sur l'accueil au lieu de son tableau de bord. On réinjecte
                // redirect_url (présent dans le POST) dans la redirection. ---
                add_filter( 'es_get_auth_page_uri', array( __CLASS__, 'preserve_auth_redirect_url' ) );
        }

        /**
         * Conserve l'URL de retour (?redirect_url=) à travers les échecs
         * de connexion : le filtre d'Estatik reconstruit l'URI de la page
         * d'authentification sans lui. Ne s'applique qu'au POST de connexion.
         *
         * @param string $uri URI de la page d'authentification.
         * @return string
         */
        public static function preserve_auth_redirect_url( $uri ) {
                if ( ! is_string( $uri ) || '' === $uri ) {
                        return $uri;
                }
                $redirect = isset( $_POST['redirect_url'] ) ? wp_unslash( $_POST['redirect_url'] ) : '';
                if ( ! $redirect || ! is_string( $redirect ) ) {
                        return $uri;
                }
                $redirect = esc_url_raw( $redirect );
                if ( ! $redirect ) {
                        return $uri;
                }
                return $uri . ( false === strpos( $uri, '?' ) ? '?' : '&' ) . 'redirect_url=' . rawurlencode( $redirect );
        }

        public static function plugin_active() {
                return class_exists( 'Estatik' ) || class_exists( 'Es_Main_Class' ) || defined( 'ES_VERSION' ) || function_exists( 'es_get_properties' );
        }

        /**
         * Chemin du dossier de templates overrides dans le theme (dossier estatik4,
         * convention officielle d'Estatik v4).
         */
        public static function template_path() {
                return PARTIKULIER_DIR . '/estatik4/front/';
        }

        /**
         * Resout le chemin absolu d'un template Estatik : on cherche d'abord dans
         * notre dossier estatik4, sinon on laisse le plugin utiliser son template.
         */
                public static function template_file( $file ) {
                if ( ! $file || ! is_string( $file ) ) {
                        return $file;
                }

                // {plugin}/templates/front/property/single.php -> {theme}/estatik4/front/property/single.php
                $relative = wp_normalize_path( str_replace( wp_normalize_path( trailingslashit( dirname( WP_PLUGIN_DIR ) ) ), '', wp_normalize_path( $file ) ) );
                $relative = ltrim( str_replace( 'templates/front/', 'estatik4/front/', $relative ), '/' );
                $override = trailingslashit( PARTIKULIER_DIR ) . $relative;
                if ( file_exists( $override ) ) {
                        return $override;
                }
                return $file;
        }

        /**
         * Estatik 4.3.4 enregistre son propre template pour le CPT "properties".
         * On conserve ses données mais on rend la fiche via le template du thème.
         *
         * @param string $template Template proposé par WordPress/Estatik.
         * @return string
         */
        public static function property_single_template( $template ) {
                if ( ! is_singular( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
                        return $template;
                }

                $override = PARTIKULIER_DIR . '/templates/single.php';
                return file_exists( $override ) ? $override : $template;
        }

        /**
         * Admin notice si ESTATIK n'est pas actif.
         */
        public static function admin_notice() {
                if ( self::plugin_installed() ) {
                        $url = wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=estatik/estatik.php' ), 'activate-plugin_estatik/estatik.php' );
                        $msg = sprintf(
                                /* translators: %s: lien d'activation */
                                __( 'Le theme Partikulier nécessite le plugin <strong>Estatik</strong>. %s', 'partikulier' ),
                                '<a href="' . esc_url( $url ) . '">' . __( 'Activer Estatik maintenant', 'partikulier' ) . '</a>'
                        );
                } else {
                        $msg = sprintf(
                                /* translators: %s: lien d'installation */
                                __( 'Le theme Partikulier nécessite le plugin <strong>Estatik</strong>. %s', 'partikulier' ),
                                '<a href="' . esc_url( admin_url( 'plugin-install.php?s=estatik&tab=search&type=term' ) ) . '">' . __( 'Installer Estatik', 'partikulier' ) . '</a>'
                        );
                }
                printf( '<div class="notice notice-warning"><p>%s</p></div>', $msg ); // phpcs:ignore
        }

        /**
         * Active automatiquement Estatik s'il est installe (gain de temps pour l'utilisateur).
         */
        public static function maybe_activate() {
                if ( self::plugin_active() || ! self::plugin_installed() ) {
                        return;
                }
                if ( current_user_can( 'activate_plugins' ) ) {
                        activate_plugin( 'estatik/estatik.php' );
                }
        }

        private static function plugin_installed() {
                $plugins = get_plugins();
                return isset( $plugins['estatik/estatik.php'] );
        }

        /**
         * Enregistre les dépendances Estatik si un autre composant les a retirées trop tôt.
         * Aucun script n'est forcé : les handles sont seulement rendus disponibles pour
         * les scripts Estatik qui les déclarent comme dépendances.
         *
         * @return void
         */
        public static function register_dependency_fallbacks() {
                if ( ! defined( 'ES_PLUGIN_URL' ) ) {
                        return;
                }

                $version = defined( 'ES_VERSION' ) ? ES_VERSION : null;
                if ( ! wp_script_is( 'es-select2', 'registered' ) ) {
                        wp_register_script( 'es-select2', ES_PLUGIN_URL . 'common/select2/select2.full.min.js', array( 'jquery' ), $version );
                }
                if ( ! wp_script_is( 'es-datetime-picker', 'registered' ) ) {
                        wp_register_script( 'es-datetime-picker', ES_PLUGIN_URL . 'includes/classes/framework/assets/js/jquery.datetimepicker.full.min.js', array( 'jquery' ), $version );
                }
        }

        /**
         * Retire le CSS par defaut d'Estatik (le theme fournit le sien, plus leger).
         * On garde les styles de la carte interactive si Google Maps est active.
         */
                public static function dequeue_heavy() {
                        wp_dequeue_style( 'es-styles' );
                        wp_dequeue_style( 'estatik-style' );
                        wp_dequeue_style( 'estatik-front' );
                        wp_dequeue_style( 'estatik-public' );

                        // Ces bibliothèques ne sont pas nécessaires sur les pages éditoriales.
                        // Les pages annonces, dépôt, favoris et tableau de bord les conservent
                        // pour ne pas casser galerie, filtres, sélection ou upload.
                        $property_context = is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE )
                                || is_singular( PARTIKULIER_ESTATIK_POST_TYPE )
                                || is_page( array( 'deposer', 'deposer-en', 'deposer-ar', 'deposer-une-annonce', 'deposer-annonce', 'mes-annonces', 'mes-annonces-en', 'mes-annonces-ar', 'favoris', 'favoris-en', 'favoris-ar' ) );
                        if ( $property_context ) {
                                return;
                        }

                        foreach ( array( 'es-select2', 'select2', 'select2-js', 'es-slick', 'slick', 'slick-js', 'es-magnific', 'magnific-popup', 'es-datetime-picker', 'datetimepicker', 'jquery-ui-core', 'jquery-ui-datepicker', 'clipboard' ) as $handle ) {
                                wp_dequeue_script( $handle );
                        }
                }

        /**
         * Retire les meta generator d'Estatik.
         */
        public static function remove_estatik_head() {
                $hooks = array( 'wp_head' );
                foreach ( $hooks as $hook ) {
                        $callbacks = isset( $GLOBALS['wp_filter'][ $hook ] ) ? $GLOBALS['wp_filter'][ $hook ] : array();
                        foreach ( $callbacks as $priority => $callback_list ) {
                                foreach ( $callback_list as $id => $callback ) {
                                        $fn = $callback['function'];
                                        if ( is_array( $fn ) && isset( $fn[0] ) && is_string( $fn[0] ) && false !== strpos( strtolower( $fn[0] ), 'estatik' ) ) {
                                                remove_action( $hook, $fn, $priority );
                                        }
                                }
                        }
                }
        }

        /**
         * Reecriture des URLs d'images Estatik vers leurs variantes AVIF.
         */
        public static function avif_image( $img ) {
                if ( ! $img || ! is_string( $img ) ) {
                        return $img;
                }
                // Cas URL simple.
                if ( preg_match( '#https?://[^"\')\s]+#', $img, $m ) ) {
                        $avif = Partikulier_AVIF::avif_path_for_url( $m[0] );
                        if ( $avif ) {
                                $img = str_replace( $m[0], $avif, $img );
                        }
                }
                return $img;
        }
}

Partikulier_Estatik::init();