<?php
/**
 * Fondations du paiement futur.
 *
 * Lot F de la refonte (CDC v1.2 — extinction finale) : le domaine paiements
 * (tables pk_payment_orders et pk_premium_subscriptions) est propriété du
 * plugin partikulier-core 2.1+ depuis le lot B1 ; le VESTIGE autonome
 * 6.17.x (installation des deux tables côté thème) est physiquement retiré.
 * Les accès aux tables et la création de commandes délèguent exclusivement à
 * \Partikulier\Core\Domain\Payments\PaymentService, qui est requis.
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

        const STATUS_DISABLED = 'disabled';

        /**
         * Lot B1 : le plugin est-il propriétaire du domaine paiements ?
         * (lot F : oui — conditionne chaque délégation).
         */
        private static function core_payments() {
                return class_exists( '\Partikulier\Core\Domain\Payments\PaymentService' )
                        ? '\Partikulier\Core\Domain\Payments\PaymentService'
                        : null;
        }

        /**
         * Noms canoniques des tables du domaine — délégation au service
         * propriétaire (probes contractuelles PAY-002 : l'égalité
         * thème/plugin fait foi que les deux côtés désignent les mêmes tables).
         */
        public static function orders_table() {
                return self::core_payments() ? self::core_payments()::orders_table() : '';
        }

        public static function subscriptions_table() {
                return self::core_payments() ? self::core_payments()::subscriptions_table() : '';
        }

        /** @return WP_Error Toujours désactivé tant que le gate paiement est fermé. */
        public static function create_order() {
                if ( self::core_payments() ) {
                        return self::core_payments()::create_order();
                }
                return new WP_Error( 'pk_payment_disabled', __( 'Le paiement est désactivé.', 'partikulier' ) );
        }
}
