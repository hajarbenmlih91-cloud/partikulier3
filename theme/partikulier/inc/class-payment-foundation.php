<?php
/**
 * Fondations du paiement futur.
 *
 * Depuis le lot B1 de la refonte (CDC v1.2), ce module est une COUTURE : le
 * domaine paiements (tables pk_payment_orders et pk_premium_subscriptions)
 * est propriété du plugin partikulier-core 2.1+. L’installation du schéma et
 * les accès aux tables sont délégués à \Partikulier\Core\Domain\Payments\PaymentService
 * quand la classe existe ; sans le plugin, le chemin autonome historique
 * 6.17.x est conservé à l’identique (REG-5).
 *
 * Aucun prestataire, lien de paiement, callback ni affichage public n’est
 * activé. Les statuts sont volontairement « disabled » jusqu’à validation du
 * prestataire marocain et des obligations légales.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

class Partikulier_Payment_Foundation {

        const DB_VERSION = '1.0.0';
        const OPTION_DB_VERSION = 'pk_payment_foundation_db_version';
        const STATUS_DISABLED = 'disabled';

        public static function init() {
                add_action( 'init', array( __CLASS__, 'maybe_install' ), 9 );
        }

        /**
         * Lot B1 : le plugin est-il propriétaire du domaine paiements ?
         */
        private static function core_payments() {
                return class_exists( '\Partikulier\Core\Domain\Payments\PaymentService' )
                        ? '\Partikulier\Core\Domain\Payments\PaymentService'
                        : null;
        }

        public static function maybe_install() {
                if ( self::core_payments() ) {
                        // Lot B1 : le schéma est détenu et administré par le plugin.
                        return;
                }
                if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
                        return;
                }
                global $wpdb;
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $charset = $wpdb->get_charset_collate();
                $orders = self::orders_table();
                $subscriptions = self::subscriptions_table();
                dbDelta( "CREATE TABLE {$orders} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        property_id bigint(20) unsigned NOT NULL,
                        owner_id bigint(20) unsigned NOT NULL,
                        provider varchar(64) NOT NULL DEFAULT 'unselected',
                        provider_order_ref varchar(191) NULL,
                        amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
                        currency char(3) NOT NULL DEFAULT 'MAD',
                        purpose varchar(64) NOT NULL DEFAULT 'premium_visibility',
                        status varchar(16) NOT NULL DEFAULT 'disabled',
                        metadata longtext NULL,
                        created_at datetime NOT NULL,
                        updated_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY provider_reference (provider,provider_order_ref),
                        KEY property_status (property_id,status),
                        KEY owner_status (owner_id,status)
                ) {$charset};" );
                dbDelta( "CREATE TABLE {$subscriptions} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        property_id bigint(20) unsigned NOT NULL,
                        owner_id bigint(20) unsigned NOT NULL,
                        payment_order_id bigint(20) unsigned NULL,
                        plan_key varchar(64) NOT NULL DEFAULT 'premium_visibility',
                        status varchar(16) NOT NULL DEFAULT 'disabled',
                        starts_at datetime NULL,
                        ends_at datetime NULL,
                        created_at datetime NOT NULL,
                        updated_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        KEY property_status (property_id,status),
                        KEY payment_order (payment_order_id)
                ) {$charset};" );
                update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
        }

        public static function orders_table() {
                if ( self::core_payments() ) {
                        return self::core_payments()::orders_table();
                }
                global $wpdb;
                return $wpdb->prefix . 'pk_payment_orders';
        }

        public static function subscriptions_table() {
                if ( self::core_payments() ) {
                        return self::core_payments()::subscriptions_table();
                }
                global $wpdb;
                return $wpdb->prefix . 'pk_premium_subscriptions';
        }

        /** @return WP_Error Toujours désactivé tant que le gate paiement est fermé. */
        public static function create_order() {
                if ( self::core_payments() ) {
                        return self::core_payments()::create_order();
                }
                return new WP_Error( 'pk_payment_disabled', __( 'Le paiement est désactivé.', 'partikulier' ) );
        }
}

Partikulier_Payment_Foundation::init();