<?php
/**
 * Domaine leads/qualification/WhatsApp (lot B2) — dispositif complet.
 *
 * Port fidèle de Partikulier_Buyer_Qualification, Partikulier_Lead_Retention
 * et des accès données de Partikulier_Leads_Admin (thème 6.17.x) côté plugin :
 * mêmes tables (huit, mêmes noms et index — REG-6), mêmes contrôles, mêmes
 * codes d'erreur, mêmes plafonnements. Le thème 6.18.3+ délègue ici via des
 * coutures class_exists ; sans le plugin, il conserve son chemin autonome —
 * les deux écrivent les mêmes tables avec la même logique (matrice REG-5).
 *
 * Aucun numéro n'est stocké en clair dans les clés de recherche : un HMAC
 * (wp_salt auth) sert à retrouver et limiter un même demandeur ; le numéro
 * chiffré (AES-256-CBC, wp_salt secure_auth) n'est déchiffrable que par un
 * administrateur (decrypt_phone_for_admin), jamais exposé en REST ni en log.
 *
 * Journal d'audit : les transitions du dispositif (contact autorisé, opt-out,
 * effacement, suivi mis à jour, purge) sont consignées au registre — preuve
 * d'exécution par le plugin (seul le plugin écrit ce registre ; le chemin
 * autonome du thème n'y écrit jamais, cf. matrice REG-5 du lot B1).
 *
 * Le stockage de leads par commentaires WordPress reste éteint (INTEG-2,
 * lot A) : aucune écriture de ce dispositif ne crée de commentaire
 * partikulier_lead (REG-4).
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Leads;

final class LeadService
{
    public const REST_NAMESPACE = 'partikulier/v1';
    public const DAILY_LIMIT_DEFAULT = 2;
    public const STATUS_PENDING_WHATSAPP = 'en_attente_whatsapp';
    public const CONSENT_SCOPE_SIMILAR = 'similar_listings';
    public const CONSENT_POLICY_VERSION = '1.0';
    public const RETENTION_DAYS_DEFAULT = 365;
    public const CRON_HOOK = 'pk_buyer_privacy_purge';

    private const POST_TYPE = 'properties';

    /* ------------------------------------------------------------------ */
    /* Tableaux de nommage                                                */
    /* ------------------------------------------------------------------ */

    public static function table(string $suffix): string
    {
        global $wpdb;
        return $wpdb->prefix . $suffix;
    }

    public static function leads_table(): string
    {
        return self::table('pk_buyer_leads');
    }

    /* ------------------------------------------------------------------ */
    /* Plafonnement quotidien (port fidèle : réglage n8n du thème)         */
    /* ------------------------------------------------------------------ */

    public static function daily_limit(): int
    {
        $value = class_exists('Partikulier_N8n_Security')
            ? (int) \Partikulier_N8n_Security::get('quota_per_day', self::DAILY_LIMIT_DEFAULT)
            : self::DAILY_LIMIT_DEFAULT;
        return max(1, min(10, absint($value) ?: self::DAILY_LIMIT_DEFAULT));
    }

    /* ------------------------------------------------------------------ */
    /* Référence d'annonce (méta, hors tables du domaine — port fidèle)    */
    /* ------------------------------------------------------------------ */

    public static function reference_for(int $post_id): string
    {
        $post_id = absint($post_id);
        $reference = (string) get_post_meta($post_id, '_pk_buyer_reference', true);
        if (!$reference) {
            $reference = 'PK-' . $post_id . '-' . strtoupper(wp_generate_password(4, false, false));
            update_post_meta($post_id, '_pk_buyer_reference', $reference);
        }
        return $reference;
    }

    /* ------------------------------------------------------------------ */
    /* Cœur transactionnel (voie n8n et voie REST — même code)             */
    /* ------------------------------------------------------------------ */

    /**
     * @param string $wa_id Numéro normalisé du demandeur.
     * @param int    $property_id Annonce visée (rattachement obligatoire).
     * @param string $provider_message_id Identifiant unique de l'événement.
     * @return WP_REST_Response|WP_Error { allowed, replayed, property, owner, lead_id } ou motif de refus.
     */
    public static function authorize_contact(string $wa_id, int $property_id, string $provider_message_id)
    {
        global $wpdb;
        $leads = self::leads_table();
        $messages = self::table('pk_whatsapp_messages');
        $interests = self::table('pk_interest_events');
        $limits = self::table('pk_contact_limits');
        $disclosures = self::table('pk_contact_disclosures');
        $now = current_time('mysql', true);
        $day = current_time('Y-m-d');
        $hash = hash_hmac('sha256', $wa_id, wp_salt('auth'));
        $owner_id = (int) get_post_field('post_author', $property_id);
        $owner_phone = (string) get_post_meta($property_id, '_pk_owner_phone', true);

        if (!$owner_id || !$owner_phone) {
            return new \WP_REST_Response(['allowed' => false, 'reason' => 'owner_unavailable'], 200);
        }

        $wpdb->query('START TRANSACTION');
        try { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.Discarded

            $lead_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$leads} WHERE phone_hash = %s FOR UPDATE", $hash));
            if (!$lead_id) {
                $wpdb->insert($leads, [
                    'phone_hash' => $hash,
                    'phone_encrypted' => self::encrypt_phone($wa_id),
                    'first_seen_at' => $now,
                    'last_seen_at' => $now,
                ]);
                $lead_id = (int) $wpdb->insert_id;
            } else {
                $wpdb->update($leads, ['last_seen_at' => $now], ['id' => $lead_id]);
            }
            $lead = $wpdb->get_row($wpdb->prepare("SELECT opt_out_at FROM {$leads} WHERE id = %d FOR UPDATE", $lead_id));
            if ($lead && $lead->opt_out_at) {
                $wpdb->query('ROLLBACK');
                return new \WP_REST_Response(['allowed' => false, 'reason' => 'opted_out'], 200);
            }

            $seen = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$messages} WHERE provider_message_id = %s FOR UPDATE", $provider_message_id));
            if ($seen) {
                $wpdb->query('ROLLBACK');
                return new \WP_REST_Response(['allowed' => false, 'reason' => 'duplicate_message'], 200);
            }
            $wpdb->insert($messages, ['provider_message_id' => $provider_message_id, 'lead_id' => $lead_id, 'direction' => 'inbound', 'message_type' => 'property_interest', 'created_at' => $now]);
            $wpdb->insert($interests, [
                'lead_id' => $lead_id,
                'property_id' => $property_id,
                'reference_code' => self::reference_for($property_id),
                'property_snapshot' => wp_json_encode(self::property_snapshot($property_id)),
                'provider_message_id' => $provider_message_id,
                'created_at' => $now,
            ]);

            $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$disclosures} WHERE lead_id = %d AND property_id = %d FOR UPDATE", $lead_id, $property_id));
            if ($existing) {
                $wpdb->query('COMMIT');
                return new \WP_REST_Response(array_merge(self::contact_response($property_id, true), ['lead_id' => $lead_id]), 200);
            }

            // La limite porte sur des propriétaires distincts : deux annonces du même
            // propriétaire dans la journée ne consomment qu'un seul contact.
            $known_owner = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$disclosures} WHERE lead_id = %d AND owner_id = %d AND day_key = %s FOR UPDATE", $lead_id, $owner_id, $day));
            if (!$known_owner) {
                $wpdb->query($wpdb->prepare("INSERT INTO {$limits} (lead_id, day_key, contacts_count) VALUES (%d, %s, 0) ON DUPLICATE KEY UPDATE contacts_count = contacts_count", $lead_id, $day));
                $used = (int) $wpdb->get_var($wpdb->prepare("SELECT contacts_count FROM {$limits} WHERE lead_id = %d AND day_key = %s FOR UPDATE", $lead_id, $day));
                if ($used >= self::daily_limit()) {
                    $wpdb->query('COMMIT');
                    return new \WP_REST_Response(['allowed' => false, 'reason' => 'daily_limit', 'limit' => self::daily_limit()], 200);
                }
            }

            $wpdb->insert($disclosures, ['lead_id' => $lead_id, 'property_id' => $property_id, 'owner_id' => $owner_id, 'day_key' => $day, 'sent_at' => $now]);
            if (!$known_owner) {
                $wpdb->query($wpdb->prepare("UPDATE {$limits} SET contacts_count = contacts_count + 1 WHERE lead_id = %d AND day_key = %s", $lead_id, $day));
            }
            $wpdb->query('COMMIT');
            self::audit('lead_authorized', 'lead', $lead_id, [
                'property_id' => $property_id,
                'owner_id' => $owner_id,
                'replayed' => false,
            ]);
            return new \WP_REST_Response(array_merge(self::contact_response($property_id, false), ['lead_id' => $lead_id]), 200);
        } catch (\Throwable $error) {
            $wpdb->query('ROLLBACK');
            return new \WP_Error('pk_contact_transaction_failed', __('La demande de contact ne peut pas être traitée pour le moment.', 'partikulier-core'), ['status' => 500]);
        }
    }

    /**
     * Pont REST du dispositif de leads (INTEG-2, appelé par LeadBridge).
     *
     * Mêmes contrôles, même plafonnement, même journalisation que la voie
     * WhatsApp/n8n, sans distinction du canal. Le rattachement à une annonce
     * est obligatoire ; le contexte déclaratif (nom, courriel, message, source)
     * est conservé avec le suivi du lead (pk_lead_followups.note).
     *
     * @param array $input {phone, property_id|reference, message?, name?, email?}
     * @return array|WP_Error {lead_id, property_id, reference, contact} ou refus motivé.
     */
    public static function register_api_lead(array $input): array|\WP_Error
    {
        $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
        $property_id = absint($input['property_id'] ?? 0);
        $reference = sanitize_text_field((string) ($input['reference'] ?? ''));
        if (!$phone || strlen($phone) < 8) {
            return new \WP_Error('invalid_lead_phone', __('Numéro de téléphone invalide.', 'partikulier-core'), ['status' => 422]);
        }
        if (!$property_id && $reference) {
            $property_id = self::property_for_reference($reference);
        }
        $post = $property_id ? get_post($property_id) : null;
        if (!$post || self::POST_TYPE !== $post->post_type) {
            return new \WP_Error('lead_property_not_found', __('Annonce visée introuvable.', 'partikulier-core'), ['status' => 404]);
        }
        if (!self::is_contactable_property($property_id)) {
            return new \WP_Error('lead_property_unavailable', __('Annonce visée non contactable.', 'partikulier-core'), ['status' => 409]);
        }

        $message_id = 'api-' . wp_generate_uuid4();
        $result = self::authorize_contact($phone, $property_id, $message_id);
        if (is_wp_error($result)) {
            return $result;
        }
        $data = $result instanceof \WP_REST_Response ? (array) $result->get_data() : (array) $result;

        $reasons = [
            'property_unavailable' => ['lead_property_unavailable', 409],
            'owner_unavailable' => ['lead_owner_unavailable', 409],
            'opted_out' => ['lead_opted_out', 403],
            'duplicate_message' => ['lead_duplicate', 409],
            'daily_limit' => ['lead_daily_limit', 429],
        ];
        if (empty($data['allowed'])) {
            $reason = (string) ($data['reason'] ?? 'unknown');
            [$code, $status] = $reasons[$reason] ?? ['lead_refused', 409];
            return new \WP_Error($code, sprintf(__('Demande refusée : %s.', 'partikulier-core'), $reason), ['status' => $status, 'reason' => $reason]);
        }

        // Suivi du lead : contexte déclaratif du canal API, conservé avec
        // la demande (le dispositif n'a pas de colonne dédiée — pas de
        // seconde zone de stockage).
        $lead_id = (int) ($data['lead_id'] ?? 0);
        $context = [
            'source' => 'rest_api',
            'name' => sanitize_text_field((string) ($input['name'] ?? '')),
            'email' => sanitize_email((string) ($input['email'] ?? '')),
            'message' => sanitize_textarea_field((string) ($input['message'] ?? '')),
            'property_id' => $property_id,
        ];
        self::seed_followup($lead_id, $context);

        unset($data['lead_id']);
        return [
            'lead_id' => $lead_id,
            'property_id' => $property_id,
            'reference' => self::reference_for($property_id),
            'contact' => $data,
        ];
    }

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

    /* ------------------------------------------------------------------ */
    /* Rétention et effacement (port fidèle de Partikulier_Lead_Retention) */
    /* ------------------------------------------------------------------ */

    public static function retention_days(): int
    {
        $days = defined('PARTIKULIER_LEAD_RETENTION_DAYS') ? (int) PARTIKULIER_LEAD_RETENTION_DAYS : self::RETENTION_DAYS_DEFAULT;
        return max(30, (int) apply_filters('partikulier_lead_retention_days', $days));
    }

    public static function maybe_schedule_retention(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    public static function lead_id_for_phone(string $wa_id): int
    {
        global $wpdb;
        $hash = hash_hmac('sha256', preg_replace('/\D+/', '', $wa_id), wp_salt('auth'));
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::leads_table() . ' WHERE phone_hash = %s', $hash));
    }

    /**
     * Effacement complet d'un lead : les huit tables du domaine, en
     * transaction, journalisé. Retourne false si une table refuse.
     */
    public static function erase_lead(int $lead_id): bool
    {
        $lead_id = absint($lead_id);
        if (!$lead_id) {
            return false;
        }
        global $wpdb;
        $tables = [
            'pk_interest_events',
            'pk_contact_limits',
            'pk_contact_disclosures',
            'pk_whatsapp_consents',
            'pk_whatsapp_messages',
            'pk_buyer_preferences',
            'pk_lead_followups',
            'pk_buyer_leads',
        ];
        $wpdb->query('START TRANSACTION');
        foreach ($tables as $suffix) {
            $key = 'pk_buyer_leads' === $suffix ? 'id' : 'lead_id';
            $result = $wpdb->delete(self::table($suffix), [$key => $lead_id], ['%d']);
            if (false === $result) {
                $wpdb->query('ROLLBACK');
                return false;
            }
        }
        $wpdb->query('COMMIT');
        self::audit('lead_erased', 'lead', $lead_id, ['tables' => count($tables)]);
        return true;
    }

    /**
     * Purge quotidienne bornée (100 leads/jour) — port fidèle.
     */
    public static function purge_expired(): int
    {
        global $wpdb;
        $cutoff = gmdate('Y-m-d H:i:s', time() - (self::retention_days() * DAY_IN_SECONDS));
        $ids = $wpdb->get_col($wpdb->prepare('SELECT id FROM ' . self::leads_table() . ' WHERE last_seen_at < %s ORDER BY id ASC LIMIT 100', $cutoff));
        $count = 0;
        foreach ((array) $ids as $lead_id) {
            if (self::erase_lead((int) $lead_id)) {
                $count++;
            }
        }
        if ($count > 0) {
            self::audit('leads_purged', 'lead', null, ['count' => $count, 'cutoff' => $cutoff]);
        }
        return $count;
    }

    /* ------------------------------------------------------------------ */
    /* Consentement croisé (lectures du domaine alertes, lot B3)           */
    /* ------------------------------------------------------------------ */

    public static function has_active_consent(int $lead_id, string $scope): bool
    {
        global $wpdb;
        return (bool) $wpdb->get_var($wpdb->prepare(
            'SELECT id FROM ' . self::table('pk_whatsapp_consents') . ' WHERE lead_id = %d AND scope = %s AND granted_at IS NOT NULL AND revoked_at IS NULL',
            absint($lead_id),
            $scope
        ));
    }

    /* ------------------------------------------------------------------ */
    /* Accès données de l'écran d'administration (port fidèle des requêtes */
    /* de Partikulier_Leads_Admin — le rendu reste côté thème)             */
    /* ------------------------------------------------------------------ */

    /**
     * KPI de l'écran « Leads WhatsApp ».
     *
     * @return array{total: int, new: int, consented: int, today_contacts: int}
     */
    public static function admin_summary(): array
    {
        global $wpdb;
        $leads = self::leads_table();
        $followups = self::table('pk_lead_followups');
        $consents = self::table('pk_whatsapp_consents');
        $limits = self::table('pk_contact_limits');
        $day = current_time('Y-m-d');
        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$leads}"),
            'new' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$leads} l LEFT JOIN {$followups} f ON f.lead_id = l.id WHERE l.opt_out_at IS NULL AND (f.status IS NULL OR f.status = 'new')"),
            'consented' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$consents} c INNER JOIN {$leads} l ON l.id = c.lead_id WHERE c.scope = %s AND c.granted_at IS NOT NULL AND c.revoked_at IS NULL AND l.opt_out_at IS NULL", self::CONSENT_SCOPE_SIMILAR)),
            'today_contacts' => (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(contacts_count),0) FROM {$limits} WHERE day_key = %s", $day)),
        ];
    }

    /**
     * Lignes de l'écran : jointures, filtres et tri identiques au port d'origine.
     *
     * @param array{status: string, consent: string, search: string, page: int, orderby: string, order: string} $filters
     * @return array{rows: array<int, object>, total: int}
     */
    public static function admin_rows(array $filters, int $per_page = 20): array
    {
        global $wpdb;
        $leads = self::leads_table();
        $interests = self::table('pk_interest_events');
        $preferences = self::table('pk_buyer_preferences');
        $consents = self::table('pk_whatsapp_consents');
        $limits = self::table('pk_contact_limits');
        $followups = self::table('pk_lead_followups');
        $day = current_time('Y-m-d');
        $where = '1=1';
        $params = [];
        if ($filters['status'] && in_array($filters['status'], array_keys(self::followup_status_values()), true)) {
            $where .= " AND COALESCE(f.status, 'new') = %s";
            $params[] = $filters['status'];
        }
        if ('granted' === $filters['consent']) { $where .= ' AND c.granted_at IS NOT NULL AND c.revoked_at IS NULL AND l.opt_out_at IS NULL'; }
        if ('missing' === $filters['consent']) { $where .= ' AND (c.granted_at IS NULL OR c.revoked_at IS NOT NULL) AND l.opt_out_at IS NULL'; }
        if ('opted_out' === $filters['consent']) { $where .= ' AND l.opt_out_at IS NOT NULL'; }
        if ($filters['search']) {
            $where .= ' AND (i.reference_code LIKE %s OR i.property_snapshot LIKE %s)';
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $joins = " FROM {$leads} l
                LEFT JOIN {$interests} i ON i.id = (SELECT MAX(i2.id) FROM {$interests} i2 WHERE i2.lead_id = l.id)
                LEFT JOIN {$preferences} p ON p.lead_id = l.id
                LEFT JOIN {$consents} c ON c.lead_id = l.id AND c.scope = 'similar_listings'
                LEFT JOIN {$followups} f ON f.lead_id = l.id
                LEFT JOIN {$limits} lim ON lim.lead_id = l.id AND lim.day_key = %s";
        $join_params = [$day];
        $count_sql = "SELECT COUNT(l.id) {$joins} WHERE {$where}";
        $count_params = array_merge($join_params, $params);
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $count_params));
        $offset = (max(1, (int) $filters['page']) - 1) * $per_page;
        $sort_columns = [
            'first_seen_at' => 'l.first_seen_at',
            'last_seen_at' => 'l.last_seen_at',
            'status' => "COALESCE(f.status, 'new')",
            'consent' => 'c.granted_at',
        ];
        $sort_key = isset($sort_columns[$filters['orderby']]) ? $filters['orderby'] : 'last_seen_at';
        $sort_direction = in_array($filters['order'], ['ASC', 'DESC'], true) ? $filters['order'] : 'DESC';
        $order_sql = $sort_columns[$sort_key] . ' ' . $sort_direction . ', l.id DESC';
        $list_sql = "SELECT l.*, i.reference_code, i.property_snapshot, p.budget_max, p.areas, p.layout_value, p.transaction_value, c.granted_at, c.revoked_at, f.status AS followup_status, f.note, COALESCE(lim.contacts_count, 0) AS today_contacts {$joins} WHERE {$where} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
        $list_params = array_merge($join_params, $params, [$per_page, $offset]);
        return ['rows' => (array) $wpdb->get_results($wpdb->prepare($list_sql, $list_params)), 'total' => $total];
    }

    /** Valeurs brutes des statuts de suivi (les libellés traduits restent côté thème). */
    public static function followup_status_values(): array
    {
        return [
            'new' => 'new',
            'in_progress' => 'in_progress',
            'owner_shared' => 'owner_shared',
            'qualified' => 'qualified',
            'closed' => 'closed',
        ];
    }

    /**
     * Mise à jour du suivi d'un lead (écran admin) — écriture plugin,
     * journalisée. Remplacement idempotent de la ligne pk_lead_followups.
     */
    public static function update_followup(int $lead_id, string $status, string $note, int $updated_by): bool
    {
        $lead_id = absint($lead_id);
        $status = sanitize_key($status);
        if (!$lead_id || !isset(self::followup_status_values()[$status])) {
            return false;
        }
        global $wpdb;
        $replaced = $wpdb->replace(self::table('pk_lead_followups'), [
            'lead_id' => $lead_id,
            'status' => $status,
            'note' => $note,
            'updated_by' => absint($updated_by),
            'updated_at' => current_time('mysql', true),
        ]);
        if (false === $replaced) {
            return false;
        }
        self::audit('lead_followup_updated', 'lead', $lead_id, ['status' => $status, 'by' => absint($updated_by)]);
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Suivi contextuel (pont REST)                                        */
    /* ------------------------------------------------------------------ */

    /**
     * Crée ou met à jour la ligne de suivi d'un lead avec le contexte du
     * canal d'entrée (pont REST) sans toucher au reste du dispositif.
     */
    private static function seed_followup(int $lead_id, array $context): void
    {
        if (!$lead_id) {
            return;
        }
        global $wpdb;
        $followups = self::table('pk_lead_followups');
        $existing = $wpdb->get_var($wpdb->prepare("SELECT lead_id FROM {$followups} WHERE lead_id = %d", $lead_id));
        $row = [
            'status' => 'new',
            'note' => wp_json_encode($context, JSON_UNESCAPED_UNICODE),
            'updated_at' => current_time('mysql', true),
        ];
        if ($existing) {
            $wpdb->update($followups, $row, ['lead_id' => $lead_id]);
        } else {
            $row['lead_id'] = $lead_id;
            $wpdb->insert($followups, $row);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Utilitaires privés (port fidèle)                                    */
    /* ------------------------------------------------------------------ */

    private static function contact_response(int $property_id, bool $replayed): array
    {
        return [
            'allowed' => true,
            'replayed' => $replayed,
            'property' => self::property_snapshot($property_id),
            'owner' => [
                'name' => get_post_meta($property_id, '_pk_owner_name', true),
                'phone' => get_post_meta($property_id, '_pk_owner_phone', true),
            ],
        ];
    }

    private static function property_snapshot(int $property_id): array
    {
        return [
            'id' => absint($property_id),
            'reference' => self::reference_for($property_id),
            'title' => get_the_title($property_id),
            'url' => get_permalink($property_id),
            'price' => get_post_meta($property_id, 'es_property_price', true),
            'location' => self::location_string($property_id),
            'layout' => get_post_meta($property_id, '_pk_bedrooms_label', true),
            'transaction' => implode(', ', wp_get_object_terms($property_id, 'es_status', ['fields' => 'names'])),
        ];
    }

    /**
     * Localisation d'une annonce. Le thème expose Partikulier_Geo::location_string
     * (avec localisation Polylang des termes) : utilisé tel quel quand la classe
     * existe (cas nominal — le thème est actif) ; sinon repli sur les termes
     * bruts es_location, même séparateur. Le résultat est stocké dans
     * property_snapshot : la parité avec le chemin thème est vérifiée au contrat.
     */
    private static function location_string(int $property_id): string
    {
        if (class_exists('Partikulier_Geo')) {
            return (string) \Partikulier_Geo::location_string($property_id);
        }
        $terms = get_the_terms($property_id, 'es_location');
        if (!is_array($terms) || is_wp_error($terms)) {
            return '';
        }
        $names = array_filter(array_map(static fn($term): string => (string) $term->name, $terms));
        return implode(', ', array_unique($names));
    }

    private static function property_for_reference(string $reference): int
    {
        $posts = get_posts([
            'post_type' => self::POST_TYPE,
            'post_status' => 'any',
            'meta_key' => '_pk_buyer_reference',
            'meta_value' => $reference,
            'fields' => 'ids',
            'numberposts' => 1,
        ]);
        return $posts ? (int) $posts[0] : 0;
    }

    private static function is_contactable_property(int $post_id): bool
    {
        $status = get_post_meta($post_id, '_pk_status', true);
        return 'publish' === get_post_status($post_id) && !in_array($status, ['vendu', 'loue', 'archive', self::STATUS_PENDING_WHATSAPP], true);
    }

    public static function lead_id_for_wa_id(string $wa_id): int
    {
        $phone = self::normalize_phone($wa_id);
        if (!$phone) {
            return 0;
        }
        global $wpdb;
        $hash = hash_hmac('sha256', $phone, wp_salt('auth'));
        return (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::leads_table() . ' WHERE phone_hash = %s', $hash));
    }

    private static function normalize_phone(string $phone): string
    {
        $phone = preg_replace('/\D+/', '', $phone);
        return strlen($phone) >= 8 ? $phone : '';
    }

    private static function encrypt_phone(string $phone): string
    {
        if (!function_exists('openssl_encrypt')) {
            return '';
        }
        $key = hash('sha256', wp_salt('secure_auth'), true);
        $iv = random_bytes(16);
        $ciphertext = openssl_encrypt($phone, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return base64_encode($iv . $ciphertext);
    }

    /**
     * Déchiffrement réservé au back-office administrateur. Cette fonction ne doit
     * jamais être utilisée dans une réponse REST, une page publique ou un log.
     */
    public static function decrypt_phone_for_admin(string $encrypted_phone): string
    {
        if (!current_user_can('manage_options') || !function_exists('openssl_decrypt')) {
            return '';
        }
        $payload = base64_decode($encrypted_phone, true);
        if (false === $payload || strlen($payload) <= 16) {
            return '';
        }
        $key = hash('sha256', wp_salt('secure_auth'), true);
        $decrypted = openssl_decrypt(substr($payload, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($payload, 0, 16));
        if (false === $decrypted) {
            return '';
        }
        return (string) $decrypted;
    }

    /** Consigne une transition au registre d'audit — chargement déterministe. */
    private static function audit(string $action, string $object_type, ?int $object_id, array $metadata): void
    {
        require_once __DIR__ . '/../../AuditLogger.php';
        (new \Partikulier\Core\AuditLogger())->record($action, $object_type, $object_id, $metadata);
    }
}
