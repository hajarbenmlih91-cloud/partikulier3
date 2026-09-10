<?php
/**
 * Métriques propriétaire anonymisées et contrat REST authentifié.
 *
 * Les favoris visiteurs restent locaux ; seuls des HMAC non réversibles sont
 * agrégés par annonce. Aucune identité, adresse IP ou donnée WhatsApp n’est
 * enregistrée dans ce module.
 *
 * Depuis le lot B5 de la refonte (CDC v1.2), ce module est une COUTURE : le
 * domaine statistiques propriétaire (table pk_property_saves) est propriété
 * du plugin partikulier-core 2.5+. Les primitives de table (sync_favorite,
 * favorite_count, purge_expired_saves) et le cron daily
 * pk_owner_insights_daily_purge sont délégués à
 * \Partikulier\Core\Domain\OwnerStats\OwnerStatsService quand la classe
 * existe ; sans le plugin, le chemin autonome historique 6.17.x est conservé
 * à l’identique (dégradation gracieuse REG-5 — les deux chemins écrivent la
 * même table avec la même logique). Le schéma n’est plus installé par le
 * thème quand le plugin est actif (DDL à l’identique — REG-6).
 *
 * Les écrans et routes REST du tableau de bord (AJAX favoris, page Favoris,
 * /owner/dashboard, /owner/listings/<id>/action) restent au thème : points
 * d’intégration UI (rendu de cartes, agrégation get_posts, gestion d’annonce
 * via Partikulier_Dashboard) — ils ne touchent la table que par les
 * primitives désormais déléguées (arbitrage B5, pattern écran leads B2).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Owner_Insights {

        const DB_VERSION     = '1.0.1';
        const REST_NAMESPACE = 'partikulier/v1';
        const RETENTION_DAYS = 90;

        public static function init() {
                add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
                if ( ! self::core_owner_stats() ) {
                        // Lot B5 : la planification et le handler du cron daily
                        // vivent côté plugin quand le service existe (pattern
                        // rétention du lot B2) — le thème ne les enregistre plus.
                        add_action( 'pk_owner_insights_daily_purge', array( __CLASS__, 'purge_expired_saves' ) );
                }
                add_action( 'wp_ajax_pk_sync_favorite', array( __CLASS__, 'handle_sync_favorite' ) );
                add_action( 'wp_ajax_nopriv_pk_sync_favorite', array( __CLASS__, 'handle_sync_favorite' ) );
                add_action( 'wp_ajax_pk_favorites_list', array( __CLASS__, 'handle_favorites_list' ) );
                add_action( 'wp_ajax_nopriv_pk_favorites_list', array( __CLASS__, 'handle_favorites_list' ) );
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        }

        /**
         * Table isolée : une ligne active par annonce et navigateur pseudonymisé.
         */
        public static function maybe_install() {
                if ( self::core_owner_stats() ) {
                        // Lot B5 : le plugin détient le schéma (Schema 2.5.0,
                        // DDL à l'identique) ET la planification du cron — le
                        // thème cesse d'installer et de planifier.
                        return;
                }
                if ( self::DB_VERSION === get_option( 'pk_owner_insights_db_version' ) ) {
                        return;
                }

                global $wpdb;
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $table   = $wpdb->prefix . 'pk_property_saves';
                $charset = $wpdb->get_charset_collate();
                dbDelta( "CREATE TABLE {$table} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        property_id bigint(20) unsigned NOT NULL,
                        visitor_hash char(64) NOT NULL,
                        created_at datetime NOT NULL,
                        updated_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY property_visitor (property_id, visitor_hash),
                        KEY property_id (property_id),
                        KEY updated_at (updated_at)
                ) {$charset};" );

                update_option( 'pk_owner_insights_db_version', self::DB_VERSION, false );
                if ( ! wp_next_scheduled( 'pk_owner_insights_daily_purge' ) ) {
                        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'pk_owner_insights_daily_purge' );
                }
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
                        // uniquement) ; sans le plugin, chemin autonome identique.
                        return call_user_func( array( self::core_owner_stats(), 'favorite_count' ), $property_id );
                }
                global $wpdb;
                return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}pk_property_saves WHERE property_id = %d AND updated_at >= %s", absint( $property_id ), self::retention_cutoff() ) );
        }

        /**
         * Supprime les pseudonymes inactifs : l’agrégat reste utile au propriétaire
         * sans constituer un historique durable de navigation.
         */
        public static function purge_expired_saves() {
                if ( self::core_owner_stats() ) {
                        // Lot B5 : la purge de rétention (90 jours) vit côté
                        // plugin — délégation (le handler cron est lié par le
                        // bootstrap du plugin quand le service existe).
                        return call_user_func( array( self::core_owner_stats(), 'purge_expired_saves' ) );
                }
                global $wpdb;
                $wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->prefix}pk_property_saves WHERE updated_at < %s", self::retention_cutoff() ) );
        }

        private static function retention_cutoff() {
                return gmdate( 'Y-m-d H:i:s', time() - ( self::RETENTION_DAYS * DAY_IN_SECONDS ) );
        }

        /**
         * Couture du lot B5 : classe du service plugin quand il existe.
         *
         * @return class-string|null
         */
        private static function core_owner_stats() {
                return class_exists( '\Partikulier\Core\Domain\OwnerStats\OwnerStatsService' )
                        ? '\Partikulier\Core\Domain\OwnerStats\OwnerStatsService'
                        : null;
        }

        /**
         * Synchronise un état local sans jamais exposer le compteur aux visiteurs.
         */
        public static function sync_favorite( $property_id, $visitor_id, $state ) {
                if ( self::core_owner_stats() ) {
                        // Lot B5 : l'écriture (gardes, pseudonymisation HMAC,
                        // plafonnement, upsert) vit côté plugin — délégation,
                        // preuve d'exécution par le registre d'audit.
                        return call_user_func( array( self::core_owner_stats(), 'sync_favorite' ), $property_id, $visitor_id, $state );
                }
                $property_id = absint( $property_id );
                $visitor_id  = (string) $visitor_id;
                $state       = sanitize_key( $state );
                if ( ! $property_id || ! preg_match( '/^[A-Za-z0-9_-]{16,128}$/', $visitor_id ) || ! in_array( $state, array( 'save', 'remove' ), true ) ) {
                        return new WP_Error( 'pk_invalid_favorite', __( 'Favori invalide.', 'partikulier' ), array( 'status' => 400 ) );
                }
                if ( PARTIKULIER_ESTATIK_POST_TYPE !== get_post_type( $property_id ) || 'publish' !== get_post_status( $property_id ) ) {
                        return new WP_Error( 'pk_unknown_favorite_property', __( 'Annonce introuvable.', 'partikulier' ), array( 'status' => 404 ) );
                }

                $hash      = hash_hmac( 'sha256', 'favorite-v1|' . $visitor_id, wp_salt( 'auth' ) );
                $rate_key  = 'pk_favorite_rate_' . $hash;
                $rate_used = (int) get_transient( $rate_key );
                if ( $rate_used >= 60 ) {
                        return new WP_Error( 'pk_favorite_rate_limited', __( 'Trop de mises à jour de favoris. Réessayez plus tard.', 'partikulier' ), array( 'status' => 429 ) );
                }
                set_transient( $rate_key, $rate_used + 1, HOUR_IN_SECONDS );

                global $wpdb;
                $table = $wpdb->prefix . 'pk_property_saves';
                $now   = current_time( 'mysql', true );
                if ( 'save' === $state ) {
                        $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (property_id, visitor_hash, created_at, updated_at) VALUES (%d, %s, %s, %s) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)", $property_id, $hash, $now, $now ) );
                } else {
                        $wpdb->delete( $table, array( 'property_id' => $property_id, 'visitor_hash' => $hash ), array( '%d', '%s' ) );
                }
                return array( 'saved' => 'save' === $state );
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