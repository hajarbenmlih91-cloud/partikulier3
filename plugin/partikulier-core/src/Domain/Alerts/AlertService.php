<?php
/**
 * Domaine alertes (lot B3) — alertes sauvegardées après consentement.
 *
 * Port fidèle de Partikulier_Saved_Alerts (thème 6.17.x) côté plugin :
 * mêmes tables (deux, mêmes noms et index — REG-6), mêmes contrôles, mêmes
 * codes d'erreur (pk_alert_payload, pk_alert_consent, pk_alert_storage,
 * pk_alert_status, pk_alert_status_storage), mêmes statuts
 * (active/paused/stopped), même signature de critères (sha256 du JSON
 * assaini, clés triées). Le thème 6.18.4+ délègue ici via des coutures
 * class_exists ; sans le plugin, il conserve son chemin autonome — les deux
 * écrivent les mêmes tables avec la même logique (matrice REG-5).
 *
 * Les alertes ne sont créées qu'après consentement WhatsApp explicite :
 * la lecture croisée du consentement similar_listings passe par le
 * LeadService du lot B2 (le domaine leads est propriété du plugin —
 * critère « tables lues/écrites côté plugin uniquement », y compris en
 * lecture inter-domaines).
 *
 * Ce module ne contacte aucun fournisseur, ne planifie aucun envoi et
 * n'expose aucune route publique (port fidèle du contrat du thème :
 * l'adaptateur Meta/n8n sera ajouté après validation des accès externes —
 * aucune livraison ne doit être écrite par ce service).
 *
 * Journal d'audit : les transitions (alerte créée/actualisée, statut
 * modifié) sont consignées au registre — preuve d'exécution par le plugin
 * (seul le plugin écrit ce registre ; le chemin autonome du thème n'y
 * écrit jamais, cf. matrice REG-5 des lots B1/B2).
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Alerts;

final class AlertService
{
    public const CONSENT_SCOPE = 'similar_listings';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_STOPPED = 'stopped';

    private const FREQUENCIES = ['instant', 'daily', 'weekly'];
    private const LOCALES = ['fr', 'ar', 'en'];

    /* ------------------------------------------------------------------ */
    /* Tableaux de nommage                                                */
    /* ------------------------------------------------------------------ */

    public static function alerts_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pk_saved_alerts';
    }

    public static function deliveries_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pk_alert_deliveries';
    }

    /* ------------------------------------------------------------------ */
    /* Création / actualisation (port fidèle de save_alert)               */
    /* ------------------------------------------------------------------ */

    /**
     * Crée ou actualise une alerte après preuve de consentement. Les critères
     * sont structurés (seules les clés connues survivent, trois zones au
     * maximum) afin que le futur orchestrateur ne déduise jamais de
     * préférences supplémentaires à partir des clics ou des messages libres.
     *
     * @param int    $lead_id Acquéreur consentant (dispositif leads, lot B2).
     * @param array  $criteria Critères structurés (transaction, type, areas, budget_max, layout).
     * @param string $locale Locale de l'alerte (fr, ar, en).
     * @param string $frequency instant, daily ou weekly.
     * @param string $consent_message_id Identifiant du message portant la preuve de consentement.
     * @return int|WP_Error Identifiant d'alerte.
     */
    public static function save_alert($lead_id, array $criteria, $locale, $frequency, $consent_message_id)
    {
        $lead_id = absint($lead_id);
        $locale = self::sanitize_locale($locale);
        $frequency = in_array($frequency, self::FREQUENCIES, true) ? $frequency : '';
        $consent_message_id = sanitize_text_field($consent_message_id);
        $criteria = self::sanitize_criteria($criteria);
        if (!$lead_id || !$locale || !$frequency || !$consent_message_id || empty($criteria)) {
            return new \WP_Error('pk_alert_payload', __('Alerte sauvegardée invalide.', 'partikulier'));
        }
        if (!self::has_active_consent($lead_id)) {
            return new \WP_Error('pk_alert_consent', __('Consentement WhatsApp requis.', 'partikulier'));
        }

        global $wpdb;
        $signature = hash('sha256', wp_json_encode($criteria));
        $now = current_time('mysql', true);
        $result = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . self::alerts_table() . ' (lead_id, criteria, criteria_signature, locale, frequency, status, consent_message_id, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE locale = VALUES(locale), frequency = VALUES(frequency), status = VALUES(status), consent_message_id = VALUES(consent_message_id), updated_at = VALUES(updated_at)',
                $lead_id,
                wp_json_encode($criteria),
                $signature,
                $locale,
                $frequency,
                self::STATUS_ACTIVE,
                $consent_message_id,
                $now,
                $now
            )
        );
        if (false === $result) {
            return new \WP_Error('pk_alert_storage', __('Impossible d’enregistrer l’alerte.', 'partikulier'));
        }
        $alert_id = (int) $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::alerts_table() . ' WHERE lead_id = %d AND criteria_signature = %s', $lead_id, $signature));
        if ($alert_id > 0) {
            self::audit('alert_saved', 'alert', $alert_id, [
                'lead_id' => $lead_id,
                'locale' => $locale,
                'frequency' => $frequency,
                'criteria_signature' => $signature,
                'consent_message_id' => $consent_message_id,
            ]);
        }
        return $alert_id;
    }

    /* ------------------------------------------------------------------ */
    /* Changement de statut (port fidèle de change_status)                */
    /* ------------------------------------------------------------------ */

    /** @return bool|WP_Error */
    public static function change_status($alert_id, $status)
    {
        $alert_id = absint($alert_id);
        if (!$alert_id || !in_array($status, [self::STATUS_ACTIVE, self::STATUS_PAUSED, self::STATUS_STOPPED], true)) {
            return new \WP_Error('pk_alert_status', __('Commande d’alerte invalide.', 'partikulier'));
        }
        global $wpdb;
        $result = $wpdb->update(
            self::alerts_table(),
            ['status' => $status, 'updated_at' => current_time('mysql', true)],
            ['id' => $alert_id],
            ['%s', '%s'],
            ['%d']
        );
        if (false === $result) {
            return new \WP_Error('pk_alert_status_storage', __('Impossible de modifier l’alerte.', 'partikulier'));
        }
        if ($result > 0) {
            self::audit('alert_status_changed', 'alert', $alert_id, ['status' => $status]);
        }
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Consentement croisé (lecture inter-domaines, lot B2)               */
    /* ------------------------------------------------------------------ */

    public static function has_active_consent(int $lead_id): bool
    {
        // La table pk_whatsapp_consents est propriété du domaine leads
        // (plugin depuis le lot B2) : la lecture passe par son service.
        if (class_exists(\Partikulier\Core\Domain\Leads\LeadService::class)) {
            return \Partikulier\Core\Domain\Leads\LeadService::has_active_consent($lead_id, self::CONSENT_SCOPE);
        }
        // Repli (combinaisons croisées « plugin ancien ») : lecture SQL
        // directe identique au chemin historique du thème.
        global $wpdb;
        return (bool) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT id FROM ' . $wpdb->prefix . 'pk_whatsapp_consents WHERE lead_id = %d AND scope = %s AND granted_at IS NOT NULL AND revoked_at IS NULL',
                absint($lead_id),
                self::CONSENT_SCOPE
            )
        );
    }

    /* ------------------------------------------------------------------ */
    /* Assainissement (port fidèle)                                       */
    /* ------------------------------------------------------------------ */

    private static function sanitize_locale($locale): string
    {
        $locale = strtolower(sanitize_key($locale));
        return in_array($locale, self::LOCALES, true) ? $locale : '';
    }

    private static function sanitize_criteria(array $criteria): array
    {
        $clean = [];
        if (isset($criteria['transaction'])) {
            $clean['transaction'] = sanitize_key($criteria['transaction']);
        }
        if (isset($criteria['type'])) {
            $clean['type'] = sanitize_text_field($criteria['type']);
        }
        if (isset($criteria['areas'])) {
            $clean['areas'] = array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) $criteria['areas']))), 0, 3);
        }
        if (isset($criteria['budget_max'])) {
            $clean['budget_max'] = absint($criteria['budget_max']);
        }
        if (isset($criteria['layout'])) {
            $clean['layout'] = sanitize_text_field($criteria['layout']);
        }
        ksort($clean);
        return array_filter($clean, static function ($value) {
            return is_array($value) ? !empty($value) : '' !== $value && 0 !== $value;
        });
    }

    /** Consigne une transition au registre d'audit — chargement déterministe. */
    private static function audit(string $action, string $object_type, ?int $object_id, array $metadata): void
    {
        require_once __DIR__ . '/../../AuditLogger.php';
        (new \Partikulier\Core\AuditLogger())->record($action, $object_type, $object_id, $metadata);
    }
}
