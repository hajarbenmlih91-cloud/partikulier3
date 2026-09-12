<?php
/**
 * Socle des alertes sauvegardées Partikulier.
 *
 * Les alertes ne sont créées qu'après consentement WhatsApp explicite. Ce module
 * ne contacte aucun fournisseur, ne planifie aucun envoi et n’expose aucune route
 * publique : l’adaptateur Meta/n8n sera ajouté après validation des accès externes.
 *
 * Lot F de la refonte (CDC v1.2 — extinction finale) : le domaine alertes est
 * propriété du plugin partikulier-core 2.3+ depuis le lot B3 ; le VESTIGE
 * autonome 6.17.x est physiquement retiré — installation des deux tables,
 * contrôles de consentement et écritures directes sont éteints. Chaque
 * opération délègue exclusivement à
 * \Partikulier\Core\Domain\Alerts\AlertService, qui est requis.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

class Partikulier_Saved_Alerts {

        const STATUS_ACTIVE = 'active';
        const STATUS_PAUSED = 'paused';
        const STATUS_STOPPED = 'stopped';

        /**
         * Nom canonique de la table des alertes — délégation au service
         * propriétaire (probe contractuelle B3 : l'égalité thème/plugin fait
         * foi que les deux côtés désignent la même table).
         */
        public static function alerts_table() {
                return self::core_alerts() ? self::core_alerts()::alerts_table() : '';
        }

        public static function deliveries_table() {
                return self::core_alerts() ? self::core_alerts()::deliveries_table() : '';
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
                return new WP_Error( 'pk_core_required', __( 'Les alertes sauvegardées exigent le plugin partikulier-core.', 'partikulier' ) );
        }

        /** @return bool|WP_Error */
        public static function change_status( $alert_id, $status ) {
                if ( self::core_alerts() ) {
                        return self::core_alerts()::change_status( $alert_id, $status );
                }
                return new WP_Error( 'pk_core_required', __( 'Les alertes sauvegardées exigent le plugin partikulier-core.', 'partikulier' ) );
        }

        /**
         * Couture du lot B3, conservée au lot F : classe du service plugin
         * quand il existe — dépositaire unique du domaine.
         *
         * @return class-string|null
         */
        private static function core_alerts() {
                return class_exists( '\Partikulier\Core\Domain\Alerts\AlertService' )
                        ? '\Partikulier\Core\Domain\Alerts\AlertService'
                        : null;
        }
}
