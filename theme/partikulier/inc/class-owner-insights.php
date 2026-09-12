<?php
/**
 * Métriques propriétaire anonymisées et contrat REST authentifié.
 *
 * Les favoris visiteurs restent locaux ; seuls des HMAC non réversibles sont
 * agrégés par annonce. Aucune identité, adresse IP ou donnée WhatsApp n’est
 * enregistrée dans ce module.
 *
 * Lot F de la refonte (CDC v1.2 — extinction finale) : le domaine
 * statistiques propriétaire (table pk_property_saves) est propriété du
 * plugin partikulier-core 2.5+ depuis le lot B5 ; le VESTIGE autonome
 * 6.17.x est physiquement retiré — installation de la table, planification
 * du cron et primitives d'écriture directes sont éteintes. Les primitives
 * (sync_favorite, favorite_count, purge_expired_saves) délèguent
 * exclusivement à \Partikulier\Core\Domain\OwnerStats\OwnerStatsService,
 * qui détient également le cron daily pk_owner_insights_daily_purge.
 *
 * Les écrans et routes REST du tableau de bord (AJAX favoris, page Favoris,
 * /owner/dashboard, /owner/listings/<id>/action) restent au thème : points
 * d’intégration UI (rendu de cartes, agrégation get_posts, gestion d’annonce
 * via Partikulier_Dashboard) — ils ne touchent la table que par les
 * primitives déléguées (arbitrage B5, pattern écran leads B2).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Owner_Insights {

        public static function init() {
                add_action( 'wp_ajax_pk_sync_favorite', array( __CLASS__, 'handle_sync_favorite' ) );
                add_action( 'wp_ajax_nopriv_pk_sync_favorite', array( __CLASS__, 'handle_sync_favorite' ) );
                add_action( 'wp_ajax_pk_favorites_list', array( __CLASS__, 'handle_favorites_list' ) );
                add_action( 'wp_ajax_nopriv_pk_favorites_list', array( __CLASS__, 'handle_favorites_list' ) );
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        }

        public static function register_routes() {
                Partikulier_Automation_Bridge::declare_rest_route(
                        '/owner/dashboard',
                        array(
                                'methods'             => WP_REST_Server::READABLE,
                                'callback'            => array( __CLASS__, 'rest_dashboard' ),
                                'permission_callback' => array( __CLASS__, 'can_access_owner_dashboard' ),
                        )
                );

                Partikulier_Automation_Bridge::declare_rest_route(
                        '/owner/listings/(?P<id>\d+)/action',
                        array(
                                'methods'             => WP_REST_Server::CREATABLE,
                                'callback'            => array( __CLASS__, 'rest_manage_listing' ),
                                'permission_callback' => array( __CLASS__, 'can_access_owner_dashboard' ),
                        )
                );
        }

        /**
         * L’authentification cookie WordPress + X-WP-Nonce est vérifiée par le
         * noyau REST avant cet appel. Aucun secret n’est utilisable côté navigateur.
         */
        public static function can_access_owner_dashboard() {
                if ( ! is_user_logged_in() ) {
                        return new WP_Error( 'pk_owner_auth_required', __( 'Connexion propriétaire requise.', 'partikulier' ), array( 'status' => 401 ) );
                }
                return true;
        }

        public static function rest_dashboard() {
                $user_id = get_current_user_id();
                $listings = get_posts( array(
                        'post_type'      => PARTIKULIER_ESTATIK_POST_TYPE,
                        'post_status'    => array( 'publish', 'pending', 'draft', 'trash' ),
                        'author'         => $user_id,
                        'posts_per_page' => -1,
                        'orderby'        => 'date',
                        'order'          => 'DESC',
                ) );

                $total_views = 0;
                $total_saves = 0;
                $active      = 0;
                $rows        = array();
                foreach ( $listings as $listing ) {
                        $status = get_post_meta( $listing->ID, '_pk_status', true );
                        $status = ( '' === $status || 'actif' === $status ) ? 'actif' : $status;
                        $views  = (int) get_post_meta( $listing->ID, '_pk_views', true );
                        $saves  = self::favorite_count( $listing->ID );
                        $total_views += $views;
                        $total_saves += $saves;
                        if ( 'publish' === $listing->post_status && 'actif' === $status ) {
                                $active++;
                        }
                        $rows[] = array(
                                'id'        => (int) $listing->ID,
                                'title'     => get_the_title( $listing->ID ),
                                'permalink' => get_permalink( $listing->ID ),
                                'status'    => $status,
                                'views'     => $views,
                                'favorites' => $saves,
                        );
                }

                return new WP_REST_Response( array(
                        'summary'  => array(
                                'listings'  => count( $listings ),
                                'active'    => $active,
                                'views'     => $total_views,
                                'favorites' => $total_saves,
                        ),
                        'listings' => $rows,
                ), 200 );
        }

        public static function rest_manage_listing( WP_REST_Request $request ) {
                $result = Partikulier_Dashboard::manage_listing(
                        absint( $request['id'] ),
                        sanitize_key( (string) $request->get_param( 'action' ) ),
                        get_current_user_id()
                );
                if ( is_wp_error( $result ) ) {
                        return $result;
                }
                return new WP_REST_Response( $result, 200 );
        }

        public static function favorite_count( $property_id ) {
                if ( self::core_owner_stats() ) {
                        // Lot B5 : la table pk_property_saves est propriété du
                        // plugin — lecture déléguée au service (critère de
                        // sortie du lot B : tables lues/écrites côté plugin
                        // uniquement). Sans le plugin : zéro (prédicat sûr).
                        return call_user_func( array( self::core_owner_stats(), 'favorite_count' ), $property_id );
                }
                return 0;
        }

        /**
         * Supprime les pseudonymes inactifs — délégation au service du plugin,
         * qui détient la rétention (90 jours) et son cron daily.
         */
        public static function purge_expired_saves() {
                if ( self::core_owner_stats() ) {
                        return call_user_func( array( self::core_owner_stats(), 'purge_expired_saves' ) );
                }
                return null;
        }

        /**
         * Couture du lot B5, conservée au lot F : classe du service plugin
         * quand il existe — dépositaire unique du domaine.
         *
         * @return class-string|null
         */
        private static function core_owner_stats() {
                return class_exists( '\Partikulier\Core\Domain\OwnerStats\OwnerStatsService' )
                        ? '\Partikulier\Core\Domain\OwnerStats\OwnerStatsService'
                        : null;
        }

        /**
         * Synchronise un état local sans jamais exposer le compteur aux visiteurs
         * — délégation intégrale au service du plugin (gardes,
         * pseudonymisation HMAC, plafonnement, upsert).
         */
        public static function sync_favorite( $property_id, $visitor_id, $state ) {
                if ( self::core_owner_stats() ) {
                        return call_user_func( array( self::core_owner_stats(), 'sync_favorite' ), $property_id, $visitor_id, $state );
                }
                return new WP_Error( 'pk_core_required', __( 'Les favoris exigent le plugin partikulier-core.', 'partikulier' ), array( 'status' => 503 ) );
        }

        /**
         * Renvoie les annonces correspondant a une liste d'identifiants.
         * Sert la page Favoris : le navigateur envoie ses IDs, le serveur
         * repond avec les cartes rendues. Aucun compte n'est necessaire.
         */
        public static function handle_favorites_list() {
                check_ajax_referer( 'pk_public', 'nonce' );

                $raw = isset( $_POST['ids'] ) ? wp_unslash( $_POST['ids'] ) : '';
                $ids = array_filter( array_map( 'absint', explode( ',', (string) $raw ) ) );
                $ids = array_slice( array_unique( $ids ), 0, 60 );

                if ( ! $ids ) {
                        wp_send_json_success( array( 'html' => '', 'count' => 0 ) );
                }

                $query = new WP_Query( array(
                        'post_type'           => PARTIKULIER_ESTATIK_POST_TYPE,
                        'post_status'         => 'publish',
                        'post__in'            => $ids,
                        'orderby'             => 'post__in',
                        'posts_per_page'      => count( $ids ),
                        'ignore_sticky_posts' => true,
                ) );

                ob_start();
                while ( $query->have_posts() ) {
                        $query->the_post();
                        $property = get_post();
                        include get_theme_file_path( 'templates/parts/card-property.php' );
                }
                wp_reset_postdata();

                wp_send_json_success( array(
                        'html'  => ob_get_clean(),
                        'count' => (int) $query->post_count,
                ) );
        }

        public static function handle_sync_favorite() {
                check_ajax_referer( 'pk_public', 'nonce' );
                $result = self::sync_favorite(
                        isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0,
                isset( $_POST['visitor_id'] ) ? sanitize_text_field( wp_unslash( $_POST['visitor_id'] ) ) : '',
                isset( $_POST['state'] ) ? sanitize_key( wp_unslash( $_POST['state'] ) ) : ''
                );
                if ( is_wp_error( $result ) ) {
                        $pk_err_data = $result->get_error_data();
                        $pk_status   = is_array( $pk_err_data ) && isset( $pk_err_data['status'] ) ? (int) $pk_err_data['status'] : 400;
                        wp_send_json_error( array( 'message' => $result->get_error_message() ), $pk_status );
                }
                wp_send_json_success( $result );
        }
}

Partikulier_Owner_Insights::init();
