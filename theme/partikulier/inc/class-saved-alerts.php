<?php
/**
 * Socle des alertes sauvegardées Partikulier.
 *
 * Les alertes ne sont créées qu'après consentement WhatsApp explicite. Ce module
 * ne contacte aucun fournisseur, ne planifie aucun envoi et n’expose aucune route
 * publique : l’adaptateur Meta/n8n sera ajouté après validation des accès externes.
 *
 * Depuis le lot B3 de la refonte (CDC v1.2), ce module est une COUTURE : le
 * domaine alertes (deux tables) est propriété du plugin partikulier-core
 * 2.3+. Chaque opération est déléguée à
 * \Partikulier\Core\Domain\Alerts\AlertService quand la classe existe ; sans
 * le plugin, le chemin autonome historique 6.17.x est conservé à l’identique
 * (dégradation gracieuse REG-5 — les deux chemins écrivent les mêmes tables
 * avec la même logique). Le schéma n'est plus installé par le thème quand le
 * plugin est actif (formulations DDL à l'identique — REG-6).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

class Partikulier_Saved_Alerts {

        const DB_VERSION = '1.0.0';
        const OPTION_DB_VERSION = 'pk_saved_alerts_db_version';
        const CONSENT_SCOPE = 'similar_listings';
        const STATUS_ACTIVE = 'active';
        const STATUS_PAUSED = 'paused';
        const STATUS_STOPPED = 'stopped';

        public static function init() {
                add_action( 'init', array( __CLASS__, 'maybe_install' ), 7 );
        }

        public static function maybe_install() {
                if ( self::core_alerts() ) {
                        // Lot B3 : le plugin détient le schéma (Schema 2.3.0,
                        // DDL à l'identique) — le thème cesse d'installer.
                        return;
                }
                if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
                        return;
                }
                global $wpdb;
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $charset = $wpdb->get_charset_collate();
                $alerts = self::alerts_table();
                $deliveries = self::deliveries_table();
                dbDelta( "CREATE TABLE {$alerts} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        lead_id bigint(20) unsigned NOT NULL,
                        criteria longtext NOT NULL,
                        criteria_signature char(64) NOT NULL,
                        locale varchar(8) NOT NULL DEFAULT 'fr',
                        frequency varchar(16) NOT NULL DEFAULT 'daily',
                        status varchar(16) NOT NULL DEFAULT 'active',
                        consent_message_id varchar(191) NOT NULL,
                        created_at datetime NOT NULL,
                        updated_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY lead_signature (lead_id,criteria_signature),
                        KEY status_updated (status,updated_at)
                ) {$charset};" );
                dbDelta( "CREATE TABLE {$deliveries} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        alert_id bigint(20) unsigned NOT NULL,
                        property_id bigint(20) unsigned NOT NULL,
                        status varchar(16) NOT NULL DEFAULT 'candidate',
                        created_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY alert_property (alert_id,property_id),
                        KEY status_created (status,created_at)
                ) {$charset};" );
                update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
        }

        public static function alerts_table() {
                global $wpdb;
                return $wpdb->prefix . 'pk_saved_alerts';
        }

        public static function deliveries_table() {
                global $wpdb;
                return $wpdb->prefix . 'pk_alert_deliveries';
        }

        /**
         * Crée ou actualise une alerte après preuve de consentement. Les critères sont
         * structurés afin que le futur orchestrateur ne déduise jamais de préférences
         * supplémentaires à partir des clics ou des messages libres.
         *
         * @return int|WP_Error Identifiant d’alerte.
         */
        public static function save_alert( $lead_id, array $criteria, $locale, $frequency, $consent_message_id ) {
                if ( self::core_alerts() ) {
                        return self::core_alerts()::save_alert( $lead_id, $criteria, $locale, $frequency, $consent_message_id );
                }
                $lead_id = absint( $lead_id );
                $locale = self::sanitize_locale( $locale );
                $frequency = in_array( $frequency, array( 'instant', 'daily', 'weekly' ), true ) ? $frequency : '';
                $consent_message_id = sanitize_text_field( $consent_message_id );
                $criteria = self::sanitize_criteria( $criteria );
                if ( ! $lead_id || ! $locale || ! $frequency || ! $consent_message_id || empty( $criteria ) ) {
                        return new WP_Error( 'pk_alert_payload', __( 'Alerte sauvegardée invalide.', 'partikulier' ) );
                }
                if ( ! self::has_active_consent( $lead_id ) ) {
                        return new WP_Error( 'pk_alert_consent', __( 'Consentement WhatsApp requis.', 'partikulier' ) );
                }

                global $wpdb;
                $signature = hash( 'sha256', wp_json_encode( $criteria ) );
                $now = current_time( 'mysql', true );
                $result = $wpdb->query(
                        $wpdb->prepare(
                                'INSERT INTO ' . self::alerts_table() . ' (lead_id, criteria, criteria_signature, locale, frequency, status, consent_message_id, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE locale = VALUES(locale), frequency = VALUES(frequency), status = VALUES(status), consent_message_id = VALUES(consent_message_id), updated_at = VALUES(updated_at)',
                                $lead_id,
                                wp_json_encode( $criteria ),
                                $signature,
                                $locale,
                                $frequency,
                                self::STATUS_ACTIVE,
                                $consent_message_id,
                                $now,
                                $now
                        )
                );
                if ( false === $result ) {
                        return new WP_Error( 'pk_alert_storage', __( 'Impossible d’enregistrer l’alerte.', 'partikulier' ) );
                }
                return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM ' . self::alerts_table() . ' WHERE lead_id = %d AND criteria_signature = %s', $lead_id, $signature ) );
        }

        /** @return bool|WP_Error */
        public static function change_status( $alert_id, $status ) {
                if ( self::core_alerts() ) {
                        return self::core_alerts()::change_status( $alert_id, $status );
                }
                $alert_id = absint( $alert_id );
                if ( ! $alert_id || ! in_array( $status, array( self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_STOPPED ), true ) ) {
                        return new WP_Error( 'pk_alert_status', __( 'Commande d’alerte invalide.', 'partikulier' ) );
                }
                global $wpdb;
                $result = $wpdb->update(
                        self::alerts_table(),
                        array( 'status' => $status, 'updated_at' => current_time( 'mysql', true ) ),
                        array( 'id' => $alert_id ),
                        array( '%s', '%s' ),
                        array( '%d' )
                );
                return false === $result ? new WP_Error( 'pk_alert_status_storage', __( 'Impossible de modifier l’alerte.', 'partikulier' ) ) : true;
        }

        private static function has_active_consent( $lead_id ) {
                // Lot B2 : la table pk_whatsapp_consents est désormais propriété du plugin
                // — lecture croisée déléguée au service quand il existe (critère de sortie
                // du lot B : tables lues/écrites côté plugin uniquement) ; sans le plugin,
                // le chemin autonome historique est conservé à l’identique (REG-5).
                if ( class_exists( '\Partikulier\Core\Domain\Leads\LeadService' ) ) {
                        return \Partikulier\Core\Domain\Leads\LeadService::has_active_consent( (int) $lead_id, self::CONSENT_SCOPE );
                }
                global $wpdb;
                $consents = $wpdb->prefix . 'pk_whatsapp_consents';
                return (bool) $wpdb->get_var(
                        $wpdb->prepare( "SELECT id FROM {$consents} WHERE lead_id = %d AND scope = %s AND granted_at IS NOT NULL AND revoked_at IS NULL", $lead_id, self::CONSENT_SCOPE )
                );
        }

        private static function core_alerts() {
                return class_exists( '\Partikulier\Core\Domain\Alerts\AlertService' )
                        ? '\Partikulier\Core\Domain\Alerts\AlertService'
                        : null;
        }

        private static function sanitize_locale( $locale ) {
                $locale = strtolower( sanitize_key( $locale ) );
                return in_array( $locale, array( 'fr', 'ar', 'en' ), true ) ? $locale : '';
        }

        private static function sanitize_criteria( array $criteria ) {
                $clean = array();
                if ( isset( $criteria['transaction'] ) ) {
                        $clean['transaction'] = sanitize_key( $criteria['transaction'] );
                }
                if ( isset( $criteria['type'] ) ) {
                        $clean['type'] = sanitize_text_field( $criteria['type'] );
                }
                if ( isset( $criteria['areas'] ) ) {
                        $clean['areas'] = array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) $criteria['areas'] ) ) ), 0, 3 );
                }
                if ( isset( $criteria['budget_max'] ) ) {
                        $clean['budget_max'] = absint( $criteria['budget_max'] );
                }
                if ( isset( $criteria['layout'] ) ) {
                        $clean['layout'] = sanitize_text_field( $criteria['layout'] );
                }
                ksort( $clean );
                return array_filter( $clean, static function( $value ) {
                        return is_array( $value ) ? ! empty( $value ) : '' !== $value && 0 !== $value;
                } );
        }
}

Partikulier_Saved_Alerts::init();
