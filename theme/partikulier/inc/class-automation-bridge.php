<?php
/**
 * Pont d’automatisation entrant.
 *
 * Ce point d’entrée reçoit seulement des événements normalisés par n8n après
 * vérification côté n8n du webhook fournisseur. Il n’appelle jamais Meta,
 * WhatsApp, un prestataire de paiement ou un autre service externe.
 *
 * Lot F de la refonte (CDC v1.2 — extinction finale) : le domaine
 * automatisation est propriété du plugin partikulier-core 2.4+ depuis le lot
 * B4 ; le VESTIGE autonome 6.17.x (installation du schéma pk_automation_events
 * et journalisation directe côté thème) est physiquement retiré — la
 * réception et la garde du secret délègent exclusivement à
 * \Partikulier\Core\Domain\Automation\AutomationService, qui est requis.
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

        const REST_NAMESPACE = 'partikulier/v1';
        const ROUTE = '/automation-event';

        public static function init() {
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        }

        /**
         * Lot F : le service du plugin est le dépositaire unique du domaine —
         * sa présence conditionne chaque délégation (sinon refus motivé 503).
         */
        private static function core_automation() {
                return class_exists( '\Partikulier\Core\Domain\Automation\AutomationService' )
                        ? '\Partikulier\Core\Domain\Automation\AutomationService'
                        : null;
        }

        /**
         * Nom canonique de la table des événements — délégation au service
         * propriétaire (probes contractuelles B4A-001 : l'égalité thème/plugin
         * fait foi que les deux côtés désignent la même table).
         */
        public static function events_table() {
                return self::core_automation() ? self::core_automation()::events_table() : '';
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
                return new WP_Error( 'pk_automation_auth', __( 'Requête non autorisée.', 'partikulier' ), array( 'status' => 401 ) );
        }

        /**
         * Journalise de manière idempotente un accusé d’événement : délégation
         * intégrale au service du plugin (même table, même logique, preuve par
         * registre d’audit). Sans le plugin, la requête est refusée — le
         * vestige de journalisation autonome est éteint au lot F.
         */
        public static function receive_event( WP_REST_Request $request ) {
                if ( self::core_automation() ) {
                        return call_user_func( array( self::core_automation(), 'receive_event' ), $request );
                }
                return new WP_Error( 'pk_core_required', __( 'Le plugin partikulier-core est requis pour la réception d’événements.', 'partikulier' ), array( 'status' => 503 ) );
        }
}

Partikulier_Automation_Bridge::init();
