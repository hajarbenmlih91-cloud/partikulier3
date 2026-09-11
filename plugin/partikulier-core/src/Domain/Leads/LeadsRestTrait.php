<?php
/**
 * Gestionnaires REST de la voie n8n/WhatsApp (lot B2) : autorisation de
 * contact, préférences, consentement similar_listings, opt-out idempotent,
 * demande d'effacement — et handle_stop partagé avec la purge.
 *
 * Lot D (découpage, arbitrage commanditaire « Référence + plugin », CDC
 * v1.2 annexe C) : le service LeadService (778 lignes, code porté par la campagne
 * au lot B2) est découpé en shell + traits sur le précédent B6
 * (class-localization.php 990 → 199 l.) — les méthodes sont déplacées
 * VERBATIM, l'API publique et les hooks restent portés par la classe shell
 * (le trait compose la même classe : aucune délégation, aucun changement de
 * mécanisme actif). Preuve : contrat module-perimeter-contract.php + rejeu
 * intégral des suites du domaine (oracle inchangé).
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Leads;

trait LeadsRestTrait
{


    /* ------------------------------------------------------------------ */
    /* Gestionnaires REST (voie n8n — les routes restent déclarées par le  */
    /* thème via le registre unique ; les coutures y délèguent ici)        */
    /* ------------------------------------------------------------------ */

    /**
     * Port fidèle de handle_contact_authorization (voie webhook WhatsApp/n8n).
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_contact_authorization(\WP_REST_Request $request)
    {
        $wa_id = self::normalize_phone((string) $request->get_param('wa_id'));
        $reference = sanitize_text_field((string) $request->get_param('reference'));
        $message_id = sanitize_text_field((string) $request->get_param('provider_message_id'));
        if (!$wa_id || !$reference || !$message_id) {
            return new \WP_Error('pk_missing_payload', __('wa_id, reference et provider_message_id sont requis.', 'partikulier-core'), ['status' => 400]);
        }

        $property_id = self::property_for_reference($reference);
        if (!$property_id || !self::is_contactable_property($property_id)) {
            return new \WP_REST_Response(['allowed' => false, 'reason' => 'property_unavailable'], 200);
        }

        return self::authorize_contact($wa_id, $property_id, $message_id);
    }


    /**
     * Port fidèle de handle_preferences.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_preferences(\WP_REST_Request $request)
    {
        $lead_id = self::lead_id_for_wa_id((string) $request->get_param('wa_id'));
        if (!$lead_id) {
            return new \WP_Error('pk_unknown_lead', __('Acquéreur introuvable.', 'partikulier-core'), ['status' => 404]);
        }
        global $wpdb;
        $preferences = [
            'lead_id' => $lead_id,
            'budget_max' => absint($request->get_param('budget_max')),
            'areas' => wp_json_encode(array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) $request->get_param('areas')))), 0, 3)),
            'layout_value' => sanitize_text_field((string) $request->get_param('layout')),
            'transaction_value' => sanitize_text_field((string) $request->get_param('transaction')),
            'source' => 'explicit_whatsapp',
            'updated_at' => current_time('mysql', true),
        ];
        $wpdb->replace(self::table('pk_buyer_preferences'), $preferences);
        return new \WP_REST_Response(['updated' => true], 200);
    }


    /**
     * Port fidèle de handle_consent (scope unique similar_listings, idempotent).
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_consent(\WP_REST_Request $request)
    {
        $wa_id = self::normalize_phone((string) $request->get_param('wa_id'));
        $lead_id = self::lead_id_for_wa_id($wa_id);
        $scope = sanitize_key((string) $request->get_param('scope'));
        $message_id = sanitize_text_field((string) $request->get_param('provider_message_id'));
        $granted = rest_sanitize_boolean($request->get_param('granted'));
        if (!$lead_id || self::CONSENT_SCOPE_SIMILAR !== $scope || !$message_id) {
            return new \WP_Error('pk_invalid_consent', __('Consentement invalide.', 'partikulier-core'), ['status' => 400]);
        }
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->replace(self::table('pk_whatsapp_consents'), [
            'lead_id' => $lead_id,
            'scope' => $scope,
            'granted_at' => $granted ? $now : null,
            'revoked_at' => $granted ? null : $now,
            'policy_version' => self::CONSENT_POLICY_VERSION,
            'proof_message_id' => $message_id,
        ]);
        return new \WP_REST_Response(['consent' => $granted ? 'granted' : 'revoked'], 200);
    }


    /**
     * Port fidèle de handle_opt_out : STOP via l'API appelée par n8n.
     * Idempotent : un message STOP dupliqué ne peut ni rétablir le
     * consentement ni rouvrir un lead.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_opt_out(\WP_REST_Request $request)
    {
        $wa_id = self::normalize_phone((string) $request->get_param('wa_id'));
        $message_id = sanitize_text_field((string) $request->get_param('provider_message_id'));
        if (!$wa_id || !$message_id) {
            return new \WP_Error('pk_invalid_opt_out', __('wa_id et provider_message_id sont requis.', 'partikulier-core'), ['status' => 400]);
        }

        $lead_id = self::lead_id_for_wa_id($wa_id);
        if (!$lead_id) {
            return new \WP_REST_Response(['processed' => true, 'known_lead' => false, 'replayed' => false], 200);
        }

        global $wpdb;
        $messages = self::table('pk_whatsapp_messages');
        $seen = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$messages} WHERE provider_message_id = %s", $message_id));
        if ($seen) {
            return new \WP_REST_Response(['processed' => true, 'known_lead' => true, 'replayed' => true], 200);
        }

        self::handle_stop($wa_id, $message_id);
        $wpdb->insert($messages, [
            'provider_message_id' => $message_id,
            'lead_id' => $lead_id,
            'direction' => 'inbound',
            'message_type' => 'opt_out',
            'created_at' => current_time('mysql', true),
        ]);
        return new \WP_REST_Response(['processed' => true, 'known_lead' => true, 'replayed' => false], 200);
    }


    /**
     * Port fidèle de handle_erase_request (rétention — effacement explicite
     * demandé au canal WhatsApp/n8n). La réponse reste idempotente et ne
     * révèle pas si un numéro était connu du système.
     *
     * @return WP_REST_Response|WP_Error
     */
    public static function rest_erase_request(\WP_REST_Request $request)
    {
        $wa_id = preg_replace('/\D+/', '', (string) $request->get_param('wa_id'));
        if (!$wa_id) {
            return new \WP_Error('pk_invalid_erase_request', __('wa_id est requis.', 'partikulier-core'), ['status' => 400]);
        }
        $lead_id = self::lead_id_for_phone($wa_id);
        if ($lead_id) {
            self::erase_lead($lead_id);
        }
        return new \WP_REST_Response(['erased' => true], 200);
    }


    /**
     * Port fidèle de handle_stop (aussi appelé par la purge de rétention).
     */
    public static function handle_stop(string $wa_id, string $message_id): bool
    {
        $lead_id = self::lead_id_for_wa_id($wa_id);
        if (!$lead_id) {
            return false;
        }
        global $wpdb;
        $now = current_time('mysql', true);
        $wpdb->update(self::leads_table(), ['opt_out_at' => $now], ['id' => $lead_id]);
        $wpdb->update(self::table('pk_whatsapp_consents'), ['revoked_at' => $now], ['lead_id' => $lead_id]);
        self::audit('lead_opted_out', 'lead', $lead_id, ['via' => 'stop_message']);
        return true;
    }
}
