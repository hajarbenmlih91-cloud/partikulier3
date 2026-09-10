<?php
/**
 * Module : rétention minimale et effacement sécurisé des leads WhatsApp.
 *
 * Depuis le lot B2 de la refonte (CDC v1.2), ce module est une COUTURE : le
 * domaine leads (huit tables, rétention incluse) est propriété du plugin
 * partikulier-core 2.2+. Quand le service du plugin existe, le thème cesse
 * d'enregistrer la planification du cron et la route d’effacement (le plugin
 * les détient) ; sans le plugin, le chemin autonome historique 6.17.x est
 * conservé à l’identique (dégradation gracieuse REG-5).
 *
 * Sans interface publique ni modification de template. Les opérations sont
 * réservées à l’automatisation déjà authentifiée pour la qualification.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Lead_Retention {

        const DEFAULT_RETENTION_DAYS = 365;
        const CRON_HOOK = 'pk_buyer_privacy_purge';

        private static function core_leads() {
                return class_exists( '\Partikulier\Core\Domain\Leads\LeadService' )
                        ? '\Partikulier\Core\Domain\Leads\LeadService'
                        : null;
        }

        public static function init() {
                if ( self::core_leads() ) {
                        // Lot B2 : le plugin détient le cron (pk_buyer_privacy_purge), son
                        // handler et la route /erase-lead — ne rien doubler ici.
                        return;
                }
                add_action( 'init', array( __CLASS__, 'maybe_schedule' ), 20 );
                add_action( self::CRON_HOOK, array( __CLASS__, 'purge_expired' ) );
                add_action( 'switch_theme', array( __CLASS__, 'unschedule' ) );
                add_action( 'rest_api_init', array( __CLASS__, 'register_route' ) );
        }

        public static function retention_days() {
                $days = defined( 'PARTIKULIER_LEAD_RETENTION_DAYS' ) ? (int) PARTIKULIER_LEAD_RETENTION_DAYS : self::DEFAULT_RETENTION_DAYS;
                return max( 30, (int) apply_filters( 'partikulier_lead_retention_days', $days ) );
        }

        public static function maybe_schedule() {
                if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
                        wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK );
                }
        }

        public static function unschedule() {
                $timestamp = wp_next_scheduled( self::CRON_HOOK );
                if ( $timestamp ) {
                        wp_unschedule_event( $timestamp, self::CRON_HOOK );
                }
        }

        public static function register_route() {
                Partikulier_Automation_Bridge::register_route(
                        '/erase-lead',
                        array(
                                'methods'  => 'POST',
                                'callback' => array( __CLASS__, 'handle_erase_request' ),
                        )
                );
        }

        /**
         * Effacement explicite demandé au canal WhatsApp/n8n. La réponse reste
         * idempotente et ne révèle pas si un numéro était connu du système.
         */
        public static function handle_erase_request( WP_REST_Request $request ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::rest_erase_request( $request );
                }
                $wa_id = preg_replace( '/\D+/', '', (string) $request->get_param( 'wa_id' ) );
                if ( ! $wa_id ) {
                        return new WP_Error( 'pk_invalid_erase_request', __( 'wa_id est requis.', 'partikulier' ), array( 'status' => 400 ) );
                }
                $lead_id = self::lead_id_for_phone( $wa_id );
                if ( $lead_id ) {
                        self::erase_lead( $lead_id );
                }
                return new WP_REST_Response( array( 'erased' => true ), 200 );
        }

        public static function lead_id_for_phone( $wa_id ) {
                global $wpdb;
                $hash = hash_hmac( 'sha256', preg_replace( '/\D+/', '', (string) $wa_id ), wp_salt( 'auth' ) );
                $leads = $wpdb->prefix . 'pk_buyer_leads';
                return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$leads} WHERE phone_hash = %s", $hash ) );
        }

        public static function erase_lead( $lead_id ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::erase_lead( absint( $lead_id ) );
                }
                $lead_id = absint( $lead_id );
                if ( ! $lead_id ) {
                        return false;
                }
                global $wpdb;
                $tables = array(
                        'pk_interest_events',
                        'pk_contact_limits',
                        'pk_contact_disclosures',
                        'pk_whatsapp_consents',
                        'pk_whatsapp_messages',
                        'pk_buyer_preferences',
                        'pk_lead_followups',
                        'pk_buyer_leads',
                );
                $wpdb->query( 'START TRANSACTION' );
                foreach ( $tables as $suffix ) {
                        $key = 'pk_buyer_leads' === $suffix ? 'id' : 'lead_id';
                        $result = $wpdb->delete( $wpdb->prefix . $suffix, array( $key => $lead_id ), array( '%d' ) );
                        if ( false === $result ) {
                                $wpdb->query( 'ROLLBACK' );
                                return false;
                        }
                }
                $wpdb->query( 'COMMIT' );
                return true;
        }

        /**
         * Purge quotidienne bornée pour éviter une opération coûteuse sur un gros
         * volume. Une planification serveur est recommandée sur Hostinger.
         */
        public static function purge_expired() {
                global $wpdb;
                $cutoff = gmdate( 'Y-m-d H:i:s', time() - ( self::retention_days() * DAY_IN_SECONDS ) );
                $leads = $wpdb->prefix . 'pk_buyer_leads';
                $ids = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$leads} WHERE last_seen_at < %s ORDER BY id ASC LIMIT 100", $cutoff ) );
                $count = 0;
                foreach ( (array) $ids as $lead_id ) {
                        if ( self::erase_lead( $lead_id ) ) {
                                $count++;
                        }
                }
                return $count;
        }
}

Partikulier_Lead_Retention::init();