<?php
/**
 * Qualification des acquéreurs via WhatsApp Business.
 *
 * Le thème ne dialogue pas directement avec WhatsApp : l’orchestrateur n8n
 * appelle ces endpoints après validation du webhook Meta. Les règles métier
 * et les données restent ainsi dans la base WordPress du portail.
 *
 * Depuis le lot B2 de la refonte (CDC v1.2), ce module est une COUTURE : le
 * dispositif de leads (huit tables) est propriété du plugin partikulier-core
 * 2.2+. Chaque opération est déléguée à
 * \Partikulier\Core\Domain\Leads\LeadService quand la classe existe ; sans
 * le plugin, le chemin autonome historique 6.17.x est conservé à l’identique
 * (dégradation gracieuse REG-5 — les deux chemins écrivent les mêmes tables
 * avec la même logique).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

class Partikulier_Buyer_Qualification {

        const DB_VERSION = '1.1.0';
        const REST_NAMESPACE = 'partikulier/v1';
                const DAILY_LIMIT = 2;

                public static function daily_limit() {
                        if ( self::core_leads() ) {
                                return self::core_leads()::daily_limit();
                        }
                        $value = class_exists( 'Partikulier_N8n_Security' ) ? Partikulier_N8n_Security::get( 'quota_per_day', self::DAILY_LIMIT ) : self::DAILY_LIMIT;
                        return max( 1, min( 10, absint( $value ) ?: self::DAILY_LIMIT ) );
                }

                public static function init() {
                add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
                add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
        }

        /**
         * Crée les tables métier. Aucun numéro n’est stocké en clair dans les clés
         * de recherche : un HMAC sert à retrouver et limiter un même demandeur.
         */
        public static function maybe_install() {
                if ( self::core_leads() ) {
                        return;
                }
                if ( self::DB_VERSION === get_option( 'pk_buyer_qualification_db_version' ) ) {
                        return;
                }

                global $wpdb;
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $charset = $wpdb->get_charset_collate();
                $leads = $wpdb->prefix . 'pk_buyer_leads';
                $interests = $wpdb->prefix . 'pk_interest_events';
                $limits = $wpdb->prefix . 'pk_contact_limits';
                $disclosures = $wpdb->prefix . 'pk_contact_disclosures';
                $consents = $wpdb->prefix . 'pk_whatsapp_consents';
                $messages = $wpdb->prefix . 'pk_whatsapp_messages';
                $preferences = $wpdb->prefix . 'pk_buyer_preferences';
                $followups = $wpdb->prefix . 'pk_lead_followups';

                dbDelta( "CREATE TABLE {$leads} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        phone_hash char(64) NOT NULL,
                        phone_encrypted longtext NOT NULL,
                        first_seen_at datetime NOT NULL,
                        last_seen_at datetime NOT NULL,
                        opt_out_at datetime NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY phone_hash (phone_hash)
                ) {$charset};" );
                dbDelta( "CREATE TABLE {$interests} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        lead_id bigint(20) unsigned NOT NULL,
                        property_id bigint(20) unsigned NOT NULL,
                        reference_code varchar(32) NOT NULL,
                        property_snapshot longtext NOT NULL,
                        provider_message_id varchar(191) NOT NULL,
                        created_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY provider_message_id (provider_message_id),
                        KEY lead_property (lead_id,property_id)
                ) {$charset};" );
                dbDelta( "CREATE TABLE {$limits} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        lead_id bigint(20) unsigned NOT NULL,
                        day_key date NOT NULL,
                        contacts_count tinyint(3) unsigned NOT NULL DEFAULT 0,
                        PRIMARY KEY  (id),
                        UNIQUE KEY lead_day (lead_id,day_key)
                ) {$charset};" );
                dbDelta( "CREATE TABLE {$disclosures} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        lead_id bigint(20) unsigned NOT NULL,
                        property_id bigint(20) unsigned NOT NULL,
                        owner_id bigint(20) unsigned NOT NULL,
                        day_key date NOT NULL,
                        sent_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY lead_property (lead_id,property_id),
                        KEY lead_day (lead_id,day_key),
                        KEY lead_owner_day (lead_id,owner_id,day_key)
                ) {$charset};" );
                dbDelta( "CREATE TABLE {$preferences} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        lead_id bigint(20) unsigned NOT NULL,
                        budget_max bigint(20) unsigned NULL,
                        areas longtext NOT NULL,
                        layout_value varchar(64) NOT NULL,
                        transaction_value varchar(64) NOT NULL,
                        source varchar(64) NOT NULL,
                        updated_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY lead_id (lead_id)
                ) {$charset};" );
                dbDelta( "CREATE TABLE {$consents} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        lead_id bigint(20) unsigned NOT NULL,
                        scope varchar(64) NOT NULL,
                        granted_at datetime NULL,
                        revoked_at datetime NULL,
                        policy_version varchar(32) NOT NULL,
                        proof_message_id varchar(191) NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY lead_scope (lead_id,scope)
                ) {$charset};" );
                dbDelta( "CREATE TABLE {$messages} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        provider_message_id varchar(191) NOT NULL,
                        lead_id bigint(20) unsigned NOT NULL,
                        direction varchar(16) NOT NULL,
                        message_type varchar(64) NOT NULL,
                        created_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY provider_message_id (provider_message_id)
                ) {$charset};" );
                dbDelta( "CREATE TABLE {$followups} (
                        lead_id bigint(20) unsigned NOT NULL,
                        status varchar(32) NOT NULL DEFAULT 'new',
                        note text NULL,
                        updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
                        updated_at datetime NOT NULL,
                        PRIMARY KEY  (lead_id),
                        KEY status_updated (status,updated_at)
                ) {$charset};" );

                update_option( 'pk_buyer_qualification_db_version', self::DB_VERSION, false );
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

        /**
         * Authentifie n8n avec un secret dédié (Authorization: Bearer ou en-tête
         * X-Partikulier-Automation). Ne jamais exposer cette valeur au navigateur.
         */
        public static function check_automation_secret( WP_REST_Request $request ) {
                $secret = Partikulier_Settings::automation_api_secret();
                $provided = (string) $request->get_header( 'x_partikulier_automation' );
                if ( ! $provided ) {
                        $authorization = (string) $request->get_header( 'authorization' );
                        if ( 0 === stripos( $authorization, 'bearer ' ) ) {
                                $provided = trim( substr( $authorization, 7 ) );
                        }
                }
                return $secret && $provided && hash_equals( $secret, $provided );
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
                $wa_id = self::normalize_phone( $request->get_param( 'wa_id' ) );
                $reference = sanitize_text_field( (string) $request->get_param( 'reference' ) );
                $message_id = sanitize_text_field( (string) $request->get_param( 'provider_message_id' ) );
                if ( ! $wa_id || ! $reference || ! $message_id ) {
                        return new WP_Error( 'pk_missing_payload', __( 'wa_id, reference et provider_message_id sont requis.', 'partikulier' ), array( 'status' => 400 ) );
                }

                $property_id = self::property_for_reference( $reference );
                if ( ! $property_id || ! self::is_contactable_property( $property_id ) ) {
                        return new WP_REST_Response( array( 'allowed' => false, 'reason' => 'property_unavailable' ), 200 );
                }

                return self::authorize_contact( $wa_id, $property_id, $message_id );
        }

        /**
         * Cœur transactionnel partagé par la voie n8n (webhook WhatsApp) et par
         * le pont REST du plugin (register_api_lead, INTEG-2) : mêmes contrôles,
         * même plafonnement quotidien, même journalisation — sans distinction
         * du canal d'entrée.
         *
         * @param string $wa_id Numéro normalisé du demandeur.
         * @param int    $property_id Annonce visée (rattachement obligatoire).
         * @param string $provider_message_id Identifiant unique de l'événement.
         * @return array|WP_Error Réponse { allowed, replayed, property, owner } ou motif de refus.
         */
        private static function authorize_contact( $wa_id, $property_id, $provider_message_id ) {
                global $wpdb;
                $leads = $wpdb->prefix . 'pk_buyer_leads';
                $messages = $wpdb->prefix . 'pk_whatsapp_messages';
                $interests = $wpdb->prefix . 'pk_interest_events';
                $limits = $wpdb->prefix . 'pk_contact_limits';
                $disclosures = $wpdb->prefix . 'pk_contact_disclosures';
                $now = current_time( 'mysql', true );
                $day = current_time( 'Y-m-d' );
                $hash = hash_hmac( 'sha256', $wa_id, wp_salt( 'auth' ) );
                $owner_id = (int) get_post_field( 'post_author', $property_id );
                $owner_phone = (string) get_post_meta( $property_id, '_pk_owner_phone', true );
                $owner_name = (string) get_post_meta( $property_id, '_pk_owner_name', true );

                if ( ! $owner_id || ! $owner_phone ) {
                        return new WP_REST_Response( array( 'allowed' => false, 'reason' => 'owner_unavailable' ), 200 );
                }

                $wpdb->query( 'START TRANSACTION' );
                try { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.Discarded

                        $lead_id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$leads} WHERE phone_hash = %s FOR UPDATE", $hash ) );
                        if ( ! $lead_id ) {
                                $wpdb->insert( $leads, array(
                                        'phone_hash' => $hash,
                                        'phone_encrypted' => self::encrypt_phone( $wa_id ),
                                        'first_seen_at' => $now,
                                        'last_seen_at' => $now,
                                ) );
                                $lead_id = (int) $wpdb->insert_id;
                        } else {
                                $wpdb->update( $leads, array( 'last_seen_at' => $now ), array( 'id' => $lead_id ) );
                        }
                        $lead = $wpdb->get_row( $wpdb->prepare( "SELECT opt_out_at FROM {$leads} WHERE id = %d FOR UPDATE", $lead_id ) );
                        if ( $lead && $lead->opt_out_at ) {
                                $wpdb->query( 'ROLLBACK' );
                                return new WP_REST_Response( array( 'allowed' => false, 'reason' => 'opted_out' ), 200 );
                        }

                        $seen = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$messages} WHERE provider_message_id = %s FOR UPDATE", $provider_message_id ) );
                        if ( $seen ) {
                                $wpdb->query( 'ROLLBACK' );
                                return new WP_REST_Response( array( 'allowed' => false, 'reason' => 'duplicate_message' ), 200 );
                        }
                        $wpdb->insert( $messages, array( 'provider_message_id' => $provider_message_id, 'lead_id' => $lead_id, 'direction' => 'inbound', 'message_type' => 'property_interest', 'created_at' => $now ) );
                        $wpdb->insert( $interests, array(
                                'lead_id' => $lead_id,
                                'property_id' => $property_id,
                                'reference_code' => self::reference_for( $property_id ),
                                'property_snapshot' => wp_json_encode( self::property_snapshot( $property_id ) ),
                                'provider_message_id' => $provider_message_id,
                                'created_at' => $now,
                        ) );

                        $existing = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$disclosures} WHERE lead_id = %d AND property_id = %d FOR UPDATE", $lead_id, $property_id ) );
                        if ( $existing ) {
                                $wpdb->query( 'COMMIT' );
                                return new WP_REST_Response( array_merge( self::contact_response( $property_id, true ), array( 'lead_id' => $lead_id ) ), 200 );
                        }

                        // La limite porte sur des propriétaires distincts : deux annonces du même
                        // propriétaire dans la journée ne consomment qu’un seul contact.
                        $known_owner = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$disclosures} WHERE lead_id = %d AND owner_id = %d AND day_key = %s FOR UPDATE", $lead_id, $owner_id, $day ) );
                        if ( ! $known_owner ) {
                                $wpdb->query( $wpdb->prepare( "INSERT INTO {$limits} (lead_id, day_key, contacts_count) VALUES (%d, %s, 0) ON DUPLICATE KEY UPDATE contacts_count = contacts_count", $lead_id, $day ) );
                                $used = (int) $wpdb->get_var( $wpdb->prepare( "SELECT contacts_count FROM {$limits} WHERE lead_id = %d AND day_key = %s FOR UPDATE", $lead_id, $day ) );
                                if ( $used >= self::daily_limit() ) {
                                        $wpdb->query( 'COMMIT' );
                                        return new WP_REST_Response( array( 'allowed' => false, 'reason' => 'daily_limit', 'limit' => self::daily_limit() ), 200 );
                                }
                        }

                        $wpdb->insert( $disclosures, array( 'lead_id' => $lead_id, 'property_id' => $property_id, 'owner_id' => $owner_id, 'day_key' => $day, 'sent_at' => $now ) );
                        if ( ! $known_owner ) {
                                $wpdb->query( $wpdb->prepare( "UPDATE {$limits} SET contacts_count = contacts_count + 1 WHERE lead_id = %d AND day_key = %s", $lead_id, $day ) );
                        }
                        $wpdb->query( 'COMMIT' );
                        return new WP_REST_Response( array_merge( self::contact_response( $property_id, false ), array( 'lead_id' => $lead_id ) ), 200 );
                } catch ( Throwable $error ) {
                        $wpdb->query( 'ROLLBACK' );
                        return new WP_Error( 'pk_contact_transaction_failed', __( 'La demande de contact ne peut pas être traitée pour le moment.', 'partikulier' ), array( 'status' => 500 ) );
                }
        }

        /**
         * Pont REST du dispositif de leads (INTEG-2, lot A).
         *
         * Appelé par le plugin partikulier-core 2.0 (LeadBridge) pour la route
         * POST /partikulier/v1/leads : la demande alimente le dispositif complet
         * — mêmes contrôles de validité, même plafonnement quotidien, même
         * journalisation que la voie WhatsApp/n8n, sans distinction du canal.
         * Le rattachement à une annonce est obligatoire (property_id ou
         * référence). Le contexte déclaratif (nom, courriel, message, source)
         * est conservé avec le suivi du lead (pk_lead_followups.note).
         *
         * @param array $input {phone, property_id|reference, message?, name?, email?}
         * @return array|WP_Error {lead_id, property_id, reference, contact} ou refus motivé.
         */
        public static function register_api_lead( array $input ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::register_api_lead( $input );
                }
                $phone = preg_replace( '/\D+/', '', (string) ( $input['phone'] ?? '' ) );
                $property_id = absint( $input['property_id'] ?? 0 );
                $reference = sanitize_text_field( (string) ( $input['reference'] ?? '' ) );
                if ( ! $phone || strlen( $phone ) < 8 ) {
                        return new WP_Error( 'invalid_lead_phone', __( 'Numéro de téléphone invalide.', 'partikulier' ), array( 'status' => 422 ) );
                }
                if ( ! $property_id && $reference ) {
                        $property_id = self::property_for_reference( $reference );
                }
                $post = $property_id ? get_post( $property_id ) : null;
                if ( ! $post || PARTIKULIER_ESTATIK_POST_TYPE !== $post->post_type ) {
                        return new WP_Error( 'lead_property_not_found', __( 'Annonce visée introuvable.', 'partikulier' ), array( 'status' => 404 ) );
                }
                if ( ! self::is_contactable_property( $property_id ) ) {
                        return new WP_Error( 'lead_property_unavailable', __( 'Annonce visée non contactable.', 'partikulier' ), array( 'status' => 409 ) );
                }

                $message_id = 'api-' . wp_generate_uuid4();
                $result = self::authorize_contact( $phone, $property_id, $message_id );
                if ( is_wp_error( $result ) ) {
                        return $result;
                }
                $data = $result instanceof WP_REST_Response ? (array) $result->get_data() : (array) $result;

                $reasons = array(
                        'property_unavailable' => array( 'lead_property_unavailable', 409 ),
                        'owner_unavailable' => array( 'lead_owner_unavailable', 409 ),
                        'opted_out' => array( 'lead_opted_out', 403 ),
                        'duplicate_message' => array( 'lead_duplicate', 409 ),
                        'daily_limit' => array( 'lead_daily_limit', 429 ),
                );
                if ( empty( $data['allowed'] ) ) {
                        $reason = (string) ( $data['reason'] ?? 'unknown' );
                        list( $code, $status ) = $reasons[ $reason ] ?? array( 'lead_refused', 409 );
                        return new WP_Error( $code, sprintf( __( 'Demande refusée : %s.', 'partikulier' ), $reason ), array( 'status' => $status, 'reason' => $reason ) );
                }

                // Suivi du lead : contexte déclaratif du canal API, conservé avec
                // la demande (le dispositif n'a pas de colonne dédiée — pas de
                // seconde zone de stockage).
                $lead_id = (int) ( $data['lead_id'] ?? 0 );
                $context = array(
                        'source' => 'rest_api',
                        'name' => sanitize_text_field( (string) ( $input['name'] ?? '' ) ),
                        'email' => sanitize_email( (string) ( $input['email'] ?? '' ) ),
                        'message' => sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ),
                        'property_id' => $property_id,
                );
                self::seed_followup( $lead_id, $context );

                unset( $data['lead_id'] );
                return array(
                        'lead_id' => $lead_id,
                        'property_id' => $property_id,
                        'reference' => self::reference_for( $property_id ),
                        'contact' => $data,
                );
        }

        /**
         * Crée ou met à jour la ligne de suivi d'un lead avec le contexte du
         * canal d'entrée (pont REST) sans toucher au reste du dispositif.
         */
        private static function seed_followup( $lead_id, array $context ) {
                if ( ! $lead_id ) {
                        return;
                }
                global $wpdb;
                $followups = $wpdb->prefix . 'pk_lead_followups';
                $existing = $wpdb->get_var( $wpdb->prepare( "SELECT lead_id FROM {$followups} WHERE lead_id = %d", $lead_id ) );
                $row = array(
                        'status' => 'new',
                        'note' => wp_json_encode( $context, JSON_UNESCAPED_UNICODE ),
                        'updated_at' => current_time( 'mysql', true ),
                );
                if ( $existing ) {
                        $wpdb->update( $followups, $row, array( 'lead_id' => $lead_id ) );
                } else {
                        $row['lead_id'] = $lead_id;
                        $wpdb->insert( $followups, $row );
                }
        }

        public static function handle_preferences( WP_REST_Request $request ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::rest_preferences( $request );
                }
                $lead_id = self::lead_id_for_wa_id( $request->get_param( 'wa_id' ) );
                if ( ! $lead_id ) {
                        return new WP_Error( 'pk_unknown_lead', __( 'Acquéreur introuvable.', 'partikulier' ), array( 'status' => 404 ) );
                }
                global $wpdb;
                $preferences = array(
                        'lead_id' => $lead_id,
                        'budget_max' => absint( $request->get_param( 'budget_max' ) ),
                        'areas' => wp_json_encode( array_slice( array_values( array_filter( array_map( 'sanitize_text_field', (array) $request->get_param( 'areas' ) ) ) ), 0, 3 ) ),
                        'layout_value' => sanitize_text_field( (string) $request->get_param( 'layout' ) ),
                        'transaction_value' => sanitize_text_field( (string) $request->get_param( 'transaction' ) ),
                        'source' => 'explicit_whatsapp',
                        'updated_at' => current_time( 'mysql', true ),
                );
                $wpdb->replace( $wpdb->prefix . 'pk_buyer_preferences', $preferences );
                return new WP_REST_Response( array( 'updated' => true ), 200 );
        }

        public static function handle_consent( WP_REST_Request $request ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::rest_consent( $request );
                }
                $wa_id = self::normalize_phone( $request->get_param( 'wa_id' ) );
                $lead_id = self::lead_id_for_wa_id( $wa_id );
                $scope = sanitize_key( (string) $request->get_param( 'scope' ) );
                $message_id = sanitize_text_field( (string) $request->get_param( 'provider_message_id' ) );
                $granted = rest_sanitize_boolean( $request->get_param( 'granted' ) );
                if ( ! $lead_id || 'similar_listings' !== $scope || ! $message_id ) {
                        return new WP_Error( 'pk_invalid_consent', __( 'Consentement invalide.', 'partikulier' ), array( 'status' => 400 ) );
                }
                global $wpdb;
                $table = $wpdb->prefix . 'pk_whatsapp_consents';
                $now = current_time( 'mysql', true );
                $wpdb->replace( $table, array(
                        'lead_id' => $lead_id,
                        'scope' => $scope,
                        'granted_at' => $granted ? $now : null,
                        'revoked_at' => $granted ? null : $now,
                        'policy_version' => '1.0',
                        'proof_message_id' => $message_id,
                ) );
                return new WP_REST_Response( array( 'consent' => $granted ? 'granted' : 'revoked' ), 200 );
        }

        /**
         * Traite STOP via l’API appelée par n8n. Le résultat reste idempotent :
         * un message STOP dupliqué ne peut ni rétablir le consentement ni rouvrir un lead.
         */
        public static function handle_opt_out( WP_REST_Request $request ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::rest_opt_out( $request );
                }
                $wa_id = self::normalize_phone( $request->get_param( 'wa_id' ) );
                $message_id = sanitize_text_field( (string) $request->get_param( 'provider_message_id' ) );
                if ( ! $wa_id || ! $message_id ) {
                        return new WP_Error( 'pk_invalid_opt_out', __( 'wa_id et provider_message_id sont requis.', 'partikulier' ), array( 'status' => 400 ) );
                }

                $lead_id = self::lead_id_for_wa_id( $wa_id );
                if ( ! $lead_id ) {
                        return new WP_REST_Response( array( 'processed' => true, 'known_lead' => false, 'replayed' => false ), 200 );
                }

                global $wpdb;
                $messages = $wpdb->prefix . 'pk_whatsapp_messages';
                $seen = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$messages} WHERE provider_message_id = %s", $message_id ) );
                if ( $seen ) {
                        return new WP_REST_Response( array( 'processed' => true, 'known_lead' => true, 'replayed' => true ), 200 );
                }

                self::handle_stop( $wa_id, $message_id );
                $wpdb->insert( $messages, array(
                        'provider_message_id' => $message_id,
                        'lead_id'             => $lead_id,
                        'direction'           => 'inbound',
                        'message_type'        => 'opt_out',
                        'created_at'          => current_time( 'mysql', true ),
                ) );
                return new WP_REST_Response( array( 'processed' => true, 'known_lead' => true, 'replayed' => false ), 200 );
        }

        public static function handle_stop( $wa_id, $message_id ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::handle_stop( $wa_id, $message_id );
                }
                $lead_id = self::lead_id_for_wa_id( $wa_id );
                if ( ! $lead_id ) {
                        return false;
                }
                global $wpdb;
                $now = current_time( 'mysql', true );
                $wpdb->update( $wpdb->prefix . 'pk_buyer_leads', array( 'opt_out_at' => $now ), array( 'id' => $lead_id ) );
                $wpdb->update( $wpdb->prefix . 'pk_whatsapp_consents', array( 'revoked_at' => $now ), array( 'lead_id' => $lead_id ) );
                return true;
        }

        private static function contact_response( $property_id, $replayed ) {
                return array(
                        'allowed' => true,
                        'replayed' => (bool) $replayed,
                        'property' => self::property_snapshot( $property_id ),
                        'owner' => array(
                                'name' => get_post_meta( $property_id, '_pk_owner_name', true ),
                                'phone' => get_post_meta( $property_id, '_pk_owner_phone', true ),
                        ),
                );
        }

        private static function property_snapshot( $property_id ) {
                return array(
                        'id' => absint( $property_id ),
                        'reference' => self::reference_for( $property_id ),
                        'title' => get_the_title( $property_id ),
                        'url' => get_permalink( $property_id ),
                        'price' => get_post_meta( $property_id, 'es_property_price', true ),
                        'location' => Partikulier_Geo::location_string( $property_id ),
                        'layout' => get_post_meta( $property_id, '_pk_bedrooms_label', true ),
                        'transaction' => implode( ', ', wp_get_object_terms( $property_id, PARTIKULIER_ESTATIK_STATUS_TAXONOMY, array( 'fields' => 'names' ) ) ),
                );
        }

        private static function property_for_reference( $reference ) {
                $posts = get_posts( array(
                        'post_type' => PARTIKULIER_ESTATIK_POST_TYPE,
                        'post_status' => 'any',
                        'meta_key' => '_pk_buyer_reference',
                        'meta_value' => $reference,
                        'fields' => 'ids',
                        'numberposts' => 1,
                ) );
                return $posts ? (int) $posts[0] : 0;
        }

        private static function is_contactable_property( $post_id ) {
                $status = get_post_meta( $post_id, '_pk_status', true );
                return 'publish' === get_post_status( $post_id ) && ! in_array( $status, array( 'vendu', 'loue', 'archive', Partikulier_WhatsApp_Verification::STATUS_PENDING ), true );
        }

        private static function lead_id_for_wa_id( $wa_id ) {
                $phone = self::normalize_phone( $wa_id );
                if ( ! $phone ) {
                        return 0;
                }
                global $wpdb;
                $hash = hash_hmac( 'sha256', $phone, wp_salt( 'auth' ) );
                return (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$wpdb->prefix}pk_buyer_leads WHERE phone_hash = %s", $hash ) );
        }

        private static function normalize_phone( $phone ) {
                $phone = preg_replace( '/\D+/', '', (string) $phone );
                return strlen( $phone ) >= 8 ? $phone : '';
        }

        private static function encrypt_phone( $phone ) {
                if ( ! function_exists( 'openssl_encrypt' ) ) {
                        return '';
                }
                $key = hash( 'sha256', wp_salt( 'secure_auth' ), true );
                $iv = random_bytes( 16 );
                $ciphertext = openssl_encrypt( $phone, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
                return base64_encode( $iv . $ciphertext );
        }

        /**
         * Déchiffrement réservé au back-office administrateur. Cette fonction ne doit
         * jamais être utilisée dans une réponse REST, une page publique ou un log.
         */
        public static function decrypt_phone_for_admin( $encrypted_phone ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::decrypt_phone_for_admin( (string) $encrypted_phone );
                }
                if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'openssl_decrypt' ) ) {
                        return '';
                }
                $payload = base64_decode( (string) $encrypted_phone, true );
                if ( false === $payload || strlen( $payload ) <= 16 ) {
                        return '';
                }
                $key = hash( 'sha256', wp_salt( 'secure_auth' ), true );
                $decrypted = openssl_decrypt( substr( $payload, 16 ), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr( $payload, 0, 16 ) );
                if ( false === $decrypted ) {
                        return '';
                }
                return (string) $decrypted;
        }
}

Partikulier_Buyer_Qualification::init();