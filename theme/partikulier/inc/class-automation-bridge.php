<?php
/**
 * Pont d’automatisation entrant.
 *
 * Ce point d’entrée reçoit seulement des événements normalisés par n8n après
 * vérification côté n8n du webhook fournisseur. Il n’appelle jamais Meta,
 * WhatsApp, un prestataire de paiement ou un autre service externe.
 *
 * Depuis le lot B4 de la refonte (CDC v1.2), ce module est une COUTURE : le
 * domaine automatisation n8n (deux tables : pk_automation_events,
 * pk_n8n_hmac_audit) est propriété du plugin partikulier-core 2.4+. La
 * réception, la garde du secret et la route /automation-event sont déléguées
 * à \Partikulier\Core\Domain\Automation\AutomationService quand la classe
 * existe ; sans le plugin, le chemin autonome historique 6.17.x est conservé
 * à l’identique (dégradation gracieuse REG-5). Le schéma n’est plus installé
 * par le thème quand le plugin est actif (formulations DDL à l’identique —
 * REG-6) et la route n’est plus déclarée par le thème (INTEG-3 : le plugin
 * en est propriétaire, aucune collision à l’état nominal).
 *
 * Les helpers de déclaration de routes (register_route, declare_rest_route)
 * RESTENT au thème : ils sont le point d’intégration INTEG-3 des autres
 * classes du thème (qualification, rétention, approbation, statistiques
 * propriétaire) — ils ne touchent aucune table du domaine.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

class Partikulier_Automation_Bridge {

        const DB_VERSION = '1.0.0';
        const OPTION_DB_VERSION = 'pk_automation_bridge_db_version';
        const REST_NAMESPACE = 'partikulier/v1';
        const ROUTE = '/automation-event';

        public static function init() {
                add_action( 'init', array( __CLASS__, 'maybe_install' ), 8 );
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        }

        private static function core_automation() {
                return class_exists( '\Partikulier\Core\Domain\Automation\AutomationService' )
                        ? '\Partikulier\Core\Domain\Automation\AutomationService'
                        : null;
        }

        public static function maybe_install() {
                if ( self::core_automation() ) {
                        // Lot B4 : le plugin détient le schéma (Schema 2.4.0,
                        // DDL à l’identique) — le thème cesse d’installer.
                        return;
                }
                if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
                        return;
                }
                global $wpdb;
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $charset = $wpdb->get_charset_collate();
                $table = self::events_table();
                dbDelta( "CREATE TABLE {$table} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        event_id varchar(191) NOT NULL,
                        event_type varchar(64) NOT NULL,
                        source varchar(32) NOT NULL,
                        payload_hash char(64) NOT NULL,
                        status varchar(16) NOT NULL DEFAULT 'received',
                        received_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY event_id (event_id),
                        KEY type_received (event_type,received_at)
                ) {$charset};" );
                update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
        }

        public static function events_table() {
                global $wpdb;
                return $wpdb->prefix . 'pk_automation_events';
        }

        public static function register_routes() {
                if ( self::core_automation() ) {
                        // Lot B4 : la route /automation-event est déclarée par
                        // le RestController du plugin (owner plugin) — le thème
                        // ne la redéclare pas (INTEG-3 : registre unique, zéro
                        // collision à l’état nominal).
                        return;
                }
                self::register_route(
                        self::ROUTE,
                        array(
                                'methods'  => 'POST',
                                'callback' => array( __CLASS__, 'receive_event' ),
                        )
                );
        }

        /**
         * Vérifie le secret partagé entre n8n et WordPress. L’hébergement injecte
         * PARTIKULIER_AUTOMATION_API_SECRET ; il ne doit jamais être présent dans le
         * JavaScript, dans une URL ni dans une capture de workflow.
         */
        public static function register_route( $route, $args ) {
                $args['permission_callback'] = array( __CLASS__, 'check_automation_secret' );
                self::declare_rest_route( $route, $args );
        }

        /**
         * INTEG-3 (lot A) : les routes du thème passent par le registre unique du
         * plugin partikulier-core 2.0 — l'espace de noms partikulier/v1 y est
         * déclaré une seule fois et toute collision route + méthode est refusée
         * et journalisée. Sans le plugin, le thème reste autonome : repli sur
         * register_rest_route avec la même constante de classe.
         *
         * @param string $route Route relative (ex. /consent).
         * @param array  $args  Arguments register_rest_route complets (permission incluse).
         */
        public static function declare_rest_route( $route, $args ) {
                if ( class_exists( '\Partikulier\Core\Rest\RouteRegistry' ) ) {
                        \Partikulier\Core\Rest\RouteRegistry::declare( $route, $args, 'theme' );
                        return;
                }
                register_rest_route( self::REST_NAMESPACE, $route, $args );
        }

        public static function check_automation_secret( WP_REST_Request $request ) {
                if ( self::core_automation() ) {
                        // Lot B4 : la couche de sécurité n8n (secret partagé,
                        // HMAC, rotation, audit des échecs) vit côté plugin.
                        return call_user_func( array( self::core_automation(), 'check_automation_secret' ), $request );
                }
                return Partikulier_N8n_Security::check_automation_secret( $request );
        }

        /**
         * Journalise de manière idempotente un accusé d’événement. Le payload est
         * haché et n’est pas persisté : les numéros, messages et autres données
         * personnelles restent dans les modules métier minimisés déjà existants.
         */
        public static function receive_event( WP_REST_Request $request ) {
                if ( self::core_automation() ) {
                        // Lot B4 : journalisation idempotente des accusés
                        // d’événements côté plugin (même table, même logique,
                        // preuve par registre d’audit).
                        return call_user_func( array( self::core_automation(), 'receive_event' ), $request );
                }
                $event_id = substr( sanitize_text_field( (string) $request->get_param( 'event_id' ) ), 0, 191 );
                $event_type = sanitize_key( (string) $request->get_param( 'event_type' ) );
                $source = sanitize_key( (string) $request->get_param( 'source' ) );
                $payload = $request->get_param( 'payload' );
                $allowed_types = array( 'whatsapp_inbound', 'whatsapp_status', 'payment_status' );
                $prefix = 'n8n' === $source ? 'n8n-' : ( 'payment_provider' === $source ? 'pay-' : '' );
                if ( ! $event_id || ! $prefix || 0 !== strpos( $event_id, $prefix ) || strlen( $event_id ) > 191 || ! in_array( $event_type, $allowed_types, true ) || ! in_array( $source, array( 'n8n', 'payment_provider' ), true ) ) {
                        return new WP_Error( 'pk_automation_payload', __( 'Événement d’automatisation invalide.', 'partikulier' ), array( 'status' => 400 ) );
                }

                global $wpdb;
                $table = self::events_table();
                $encoded_payload = wp_json_encode( is_array( $payload ) || is_object( $payload ) ? $payload : array( 'value' => (string) $payload ) );
                $stored = $wpdb->insert(
                        $table,
                        array(
                                'event_id' => $event_id,
                                'event_type' => $event_type,
                                'source' => $source,
                                'payload_hash' => hash_hmac( 'sha256', $encoded_payload, wp_salt( 'auth' ) ),
                                'status' => 'received',
                                'received_at' => current_time( 'mysql', true ),
                        ),
                        array( '%s', '%s', '%s', '%s', '%s', '%s' )
                );
                if ( false === $stored ) {
                        if ( false !== strpos( strtolower( (string) $wpdb->last_error ), 'duplicate' ) || false !== strpos( strtolower( (string) $wpdb->last_error ), 'unique' ) ) {
                                return new WP_REST_Response( array( 'accepted' => true, 'duplicate' => true, 'processing' => 'disabled' ), 200 );
                        }
                        return new WP_Error( 'pk_automation_storage', __( 'Impossible de journaliser l’événement.', 'partikulier' ), array( 'status' => 500 ) );
                }
                return new WP_REST_Response( array( 'accepted' => true, 'duplicate' => false, 'processing' => 'disabled' ), 200 );
        }
}

Partikulier_Automation_Bridge::init();