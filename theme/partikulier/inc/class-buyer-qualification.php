<?php
/**
 * Qualification des acquéreurs via WhatsApp Business.
 *
 * Le thème ne dialogue pas directement avec WhatsApp : l’orchestrateur n8n
 * appelle ces endpoints après validation du webhook Meta. Les règles métier
 * et les données restent ainsi dans la base WordPress du portail.
 *
 * Lot F de la refonte (CDC v1.2 — extinction finale) : le dispositif de
 * leads (huit tables) est propriété du plugin partikulier-core 2.2+ depuis
 * le lot B2 ; le VESTIGE autonome 6.17.x est physiquement retiré —
 * installation des huit tables, cœur transactionnel, chemins de repli et
 * crypto sont éteints. Chaque opération délègue exclusivement à
 * \Partikulier\Core\Domain\Leads\LeadService, qui est requis. Les points
 * d’intégration UI (bouton WhatsApp, référence d’annonce, routes REST
 * déclarées via le registre du plugin) restent au thème.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

class Partikulier_Buyer_Qualification {

                const DAILY_LIMIT = 2;

                public static function daily_limit() {
                        if ( self::core_leads() ) {
                                return self::core_leads()::daily_limit();
                        }
                        return self::DAILY_LIMIT;
                }

                public static function init() {
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        }

        private static function core_leads() {
                return class_exists( '\Partikulier\Core\Domain\Leads\LeadService' )
                        ? '\Partikulier\Core\Domain\Leads\LeadService'
                        : null;
        }

        public static function register_routes() {
                foreach ( array( 'contact-authorization', 'preferences', 'consent', 'opt-out' ) as $route ) {
                                Partikulier_Automation_Bridge::register_route(
                                        '/' . $route,
                                        array(
                                                'methods'  => 'POST',
                                                'callback' => array( __CLASS__, 'handle_' . str_replace( '-', '_', $route ) ),
                                        )
                                );
                }
        }

        public static function reference_for( $post_id ) {
                $post_id = absint( $post_id );
                $reference = get_post_meta( $post_id, '_pk_buyer_reference', true );
                if ( ! $reference ) {
                        $reference = 'PK-' . $post_id . '-' . strtoupper( wp_generate_password( 4, false, false ) );
                        update_post_meta( $post_id, '_pk_buyer_reference', $reference );
                }
                return $reference;
        }

        public static function contact_url( $post_id ) {
                $number = preg_replace( '/\D+/', '', (string) Partikulier_Settings::get( 'buyer_whatsapp_number' ) );
                if ( ! $number ) {
                        return '';
                }
                $reference = self::reference_for( $post_id );
                $text = sprintf(
                        /* translators: 1: annonce référence, 2: URL de l'annonce */
                        __( "Bonjour Partikulier, je suis intéressé(e) par l’annonce %1\$s.\nLien : %2\$s", 'partikulier' ),
                        $reference,
                        get_permalink( $post_id )
                );
                return 'https://wa.me/' . rawurlencode( $number ) . '?text=' . rawurlencode( $text );
        }

        public static function handle_contact_authorization( WP_REST_Request $request ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::rest_contact_authorization( $request );
                }
                return self::core_required();
        }

        /**
         * Pont REST du dispositif de leads (INTEG-2, lot A).
         *
         * Appelé par le plugin partikulier-core (LeadBridge) pour la route
         * POST /partikulier/v1/leads : la demande alimente le dispositif du
         * plugin — mêmes contrôles, même plafonnement, même journalisation,
         * sans distinction du canal. Le vestige autonome est éteint au lot F :
         * le service est le dispositif unique.
         *
         * @param array $input {phone, property_id|reference, message?, name?, email?}
         * @return array|WP_Error {lead_id, property_id, reference, contact} ou refus motivé.
         */
        public static function register_api_lead( array $input ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::register_api_lead( $input );
                }
                return self::core_required();
        }

        public static function handle_preferences( WP_REST_Request $request ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::rest_preferences( $request );
                }
                return self::core_required();
        }

        public static function handle_consent( WP_REST_Request $request ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::rest_consent( $request );
                }
                return self::core_required();
        }

        /**
         * Traite STOP via l’API appelée par n8n — délégation idempotente au
         * service du plugin (un message STOP dupliqué ne peut ni rétablir le
         * consentement ni rouvrir un lead).
         */
        public static function handle_opt_out( WP_REST_Request $request ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::rest_opt_out( $request );
                }
                return self::core_required();
        }

        public static function handle_stop( $wa_id, $message_id ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::handle_stop( $wa_id, $message_id );
                }
                return false;
        }

        /**
         * Déchiffrement réservé au back-office administrateur. Cette fonction ne doit
         * jamais être utilisée dans une réponse REST, une page publique ou un log.
         * Sans le plugin, aucune clé n'est exposée : retour vide.
         */
        public static function decrypt_phone_for_admin( $encrypted_phone ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::decrypt_phone_for_admin( (string) $encrypted_phone );
                }
                return '';
        }

        /**
         * Refus uniforme du lot F : le dispositif vit côté plugin, son absence
         * est un état de service indisponible — jamais un repli silencieux.
         */
        private static function core_required() {
                return new WP_Error(
                        'pk_core_required',
                        __( 'Le dispositif de leads exige le plugin partikulier-core.', 'partikulier' ),
                        array( 'status' => 503 )
                );
        }
}

Partikulier_Buyer_Qualification::init();
