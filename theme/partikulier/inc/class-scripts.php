<?php
/**
 * Module : scripts & styles front-end. Zero jQuery.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Scripts {

        public static function init() {
                add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
                add_action( 'wp_head', array( __CLASS__, 'dns_prefetch' ), 1 );
                // Le preload doit etre accroche ici, pas depuis enqueue() : wp_enqueue_scripts
                // se declenche PENDANT wp_head, un add_action( 'wp_head', ..., 1 ) a ce
                // moment-la n'est jamais execute (priorite deja depassee).
                add_action( 'wp_head', array( __CLASS__, 'preload_assets' ), 2 );
        }

        public static function enqueue() {
                wp_enqueue_style( 'partikulier-style' );

                wp_enqueue_script(
                        'partikulier-main',
                        PARTIKULIER_URI . '/assets/js/main.js',
                        array(),
                        PARTIKULIER_VERSION,
                        array(
                                'in_footer'  => true,
                                'strategy'   => 'defer',
                        )
                );

                // Parcours de depot en 3 etapes : charge uniquement sur la page concernee.
                if ( is_page_template( 'templates/page-deposer-annonce.php' ) || is_page( array( 'deposer-une-annonce', 'deposer-annonce', 'deposer', 'publier-une-annonce' ) ) ) {
                        wp_enqueue_script(
                                'partikulier-submit-steps',
                                PARTIKULIER_URI . '/assets/js/submit-steps.js',
                                array( 'partikulier-main' ),
                                PARTIKULIER_VERSION,
                                array(
                                        'in_footer' => true,
                                        'strategy'  => 'defer',
                                )
                        );
                }

                // Donnees JS : admin ajax (formulaire annonce), endpoint REST maison.
                wp_localize_script( 'partikulier-main', 'pkConfig', array(
                        'ajaxUrl'    => admin_url( 'admin-ajax.php', is_ssl() ? 'https' : 'http' ),
                        'nonce'      => wp_create_nonce( 'pk_public' ),
                        'manageNonce' => is_user_logged_in() ? wp_create_nonce( 'pk_manage_listing' ) : '',
                        'homeUrl'    => home_url( '/' ),
                        'placesNonce' => wp_create_nonce( 'pk_places' ),
                                                        'submitNonce' => wp_create_nonce( 'pk_submit_listing' ),
                                'language'    => function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr',
                                'i18n'       => array(
                                        'publishing' => __( 'Publication en cours…', 'partikulier' ),
                                        'serverError' => __( 'Erreur serveur', 'partikulier' ),
                                        'saved' => __( 'Annonce enregistrée !', 'partikulier' ),
                                        'retry' => __( 'réessayez ou contactez-nous.', 'partikulier' ),
                                        'whatsappTitle' => __( 'Une dernière étape : WhatsApp', 'partikulier' ),
                                        'whatsappIntro' => __( 'Votre annonce est enregistrée, mais reste invisible tant que l’équipe n’a pas rapproché votre message WhatsApp.', 'partikulier' ),
                                        'whatsappCode' => __( 'Code de validation :', 'partikulier' ),
                                        'whatsappOpen' => __( 'Ouvrir WhatsApp et envoyer le message', 'partikulier' ),
                                        'whatsappNote' => __( 'Conservez ce code. L’annonce sera publiée seulement après vérification manuelle du message par l’équipe Partikulier.', 'partikulier' ),
                                        'photoError' => __( "photo(s) n'ont pas pu être ajoutées :", 'partikulier' ),
                                        'photoHint' => __( 'Vous pourrez les ajouter depuis « Mes annonces » après validation.', 'partikulier' ),
                                ),

                ) );

        }

        /**
         * <link rel="preload"> pour la police locale (LCP).
         *
         * La feuille de style n'est PAS preloadee : elle est deja injectee par
         * wp_enqueue_style en <link rel="stylesheet"> dans le meme <head>, un
         * preload ferait un second telechargement. La police, elle, n'est
         * decouverte qu'apres parsing du CSS : la precharger evite le FOUT.
         */
        public static function preload_assets() {
                // PARTIKULIER_URI (get_template_directory_uri) et non
                // get_stylesheet_directory_uri : sur un theme enfant les deux different,
                // ce qui provoquait un preload vers une URL inexistante.
                printf(
                        '<link rel="preload" href="%s/assets/fonts/dm-sans-latin.woff2" as="font" type="font/woff2" crossorigin fetchpriority="high">%s',
                        esc_url( PARTIKULIER_URI ),
                        "\n"
                );
        }

        /**
         * Preconnexion DNS uniquement si necessaire (externe, ex : maps ESTATIK).
         */
        public static function dns_prefetch() {
                $hosts = array();
                if ( class_exists( 'Estatik' ) && get_option( 'es_google_maps_api_key' ) ) {
                        $hosts[] = 'maps.googleapis.com';
                        $hosts[] = 'maps.gstatic.com';
                }
                foreach ( $hosts as $host ) {
                        printf( '<link rel="dns-prefetch" href="https://%s">%s', esc_attr( $host ), "\n" );
                }
        }
}

Partikulier_Scripts::init();