<?php
/**
 * Module : configuration de base du theme (supports, menus, registres).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Setup {

        /**
         * Liste des taxonomies geographiques Estatik pour les permaliens propres.
         */
        const GEO_TAX = array( PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );

        /**
         * Taxonomies immobilières standard.
         */
        const PROPERTY_TAX = array( PARTIKULIER_ESTATIK_TYPE_TAXONOMY, PARTIKULIER_ESTATIK_STATUS_TAXONOMY, PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY );

        public static function init() {
                add_action( 'after_setup_theme', array( __CLASS__, 'setup' ) );
                add_action( 'widgets_init', array( __CLASS__, 'widgets' ) );
                add_action( 'init', array( __CLASS__, 'register_styles' ), 1 );
                add_action( 'init', array( __CLASS__, 'register_image_sizes' ) );
        }

        public static function setup() {
                load_theme_textdomain( 'partikulier', PARTIKULIER_DIR . '/languages' );

                add_theme_support( 'automatic-feed-links' );
                add_theme_support( 'title-tag' );
                add_theme_support( 'post-thumbnails' );
                add_theme_support( 'html5', array(
                        'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script', 'navigation-widgets',
                ) );
                add_theme_support( 'responsive-embeds' );
                add_theme_support( 'align-wide' );
                add_theme_support( 'wp-block-styles' );
                add_theme_support( 'editor-styles' );
                add_theme_support( 'customize-selective-refresh-widgets' );

                $GLOBALS['content_width'] = 1200;

                register_nav_menus( array(
                        'main'    => __( 'Menu principal', 'partikulier' ),
                        'footer'  => __( 'Menu pied de page', 'partikulier' ),
                ) );
        }

        public static function widgets() {
                register_sidebar( array(
                        'name'          => __( 'Sidebar annonce', 'partikulier' ),
                        'id'            => 'sidebar-property',
                        'description'   => __( 'Colonne de la page annonce unique.', 'partikulier' ),
                        'before_widget' => '<section id="%1$s" class="widget %2$s">',
                        'after_widget'  => '</section>',
                        'before_title'  => '<h3 class="widget-title">',
                        'after_title'   => '</h3>',
                ) );
        }

        /**
         * Fichier CSS principal enregistre (ne pas charger par defaut :
         * le CSS est charge via le filtre de Partikulier_Scripts pour controler l'ordre).
         */
        public static function register_styles() {
                wp_register_style(
                        'partikulier-style',
                        PARTIKULIER_URI . '/assets/css/style.css',
                        array(),
                        PARTIKULIER_VERSION
                );
        }

        /**
         * Tailles d'images dediees aux annonces (generent aussi les variantes AVIF via Partikulier_AVIF).
         */
        public static function register_image_sizes() {
                add_image_size( 'pk-card', 640, 480, true );
                add_image_size( 'pk-card-2x', 1280, 960, true );
                add_image_size( 'pk-hero', 1600, 900, true );
                add_image_size( 'pk-thumb', 160, 120, true );
        }
}

Partikulier_Setup::init();