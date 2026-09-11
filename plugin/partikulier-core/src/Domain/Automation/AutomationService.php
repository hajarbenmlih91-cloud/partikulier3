<?php
/**
 * Domaine automatisation n8n (lot B4) — pont entrant + sécurité HMAC.
 *
 * Port fidèle de Partikulier_Automation_Bridge (thème 6.17.x) et de la
 * couche de sécurité de Partikulier_N8n_Security côté plugin : mêmes
 * tables (deux, mêmes noms et index — REG-6), mêmes codes d'erreur
 * (pk_automation_payload, pk_automation_storage, pk_automation_auth,
 * pk_automation_signature, pk_n8n_secret_missing), mêmes en-têtes de
 * webhook sortant, même promotion « secret présent + mode off = enforce »
 * (LOT 2 — Isolation : un secret configuré ne doit jamais suffire seul),
 * même plafond d'échecs HMAC par heure (100) et même idempotence des
 * accusés d'événements (UNIQUE KEY event_id → duplicate = 200 accepted).
 *
 * Le pont ne reçoit QUE des événements normalisés par n8n après
 * vérification côté n8n du webhook fournisseur : ce service n'appelle
 * jamais Meta, WhatsApp, un prestataire de paiement ou un autre service
 * externe (le corps du payload est haché et jamais persisté).
 *
 * Réglages : l'option pk_n8n_settings (secret, webhook, mode HMAC, quota,
 * consentement) et sa migration unique depuis les options historiques du
 * thème (pk_theme_options, clés n8n*) vivent ici — sans dépendance de
 * classe : l'option est lue par son nom littéral. L'écran d'administration
 * (rendu, nonce, redirection) reste au thème (B4 arbitrage : UI au thème,
 * politique au plugin) ; la validation/enregistrement des réglages est
 * déléguée ici (save_admin_settings).
 *
 * Journal d'audit : les accusés d'événements reçus et les doublons sont
 * consignés au registre — preuve d'exécution par le plugin (seul le plugin
 * écrit ce registre ; le chemin autonome du thème n'y écrit jamais, cf.
 * matrice REG-5 des lots B1-B3). Les échecs HMAC restent consignés dans
 * la table du domaine (pk_n8n_hmac_audit) comme dans le thème.
 *
 * La route POST /automation-event est déclarée par le RestController du
 * plugin (owner plugin) ; le thème 6.18.5+ cesse de la déclarer quand ce
 * service existe (INTEG-3 : aucune collision à l'état nominal).
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Automation;

final class AutomationService
{
    /** Réglages n8n (option sérialisée). */
    public const SETTINGS_OPTION = 'pk_n8n_settings';
    private const MIGRATION_OPTION = '_pk_n8n_settings_migration_state';
    private const MIGRATED_AT_OPTION = '_pk_n8n_settings_migrated_at';

    /** Option historique du thème (Partikulier_Settings::OPTION) — lue par nom littéral, sans dépendance de classe. */
    private const LEGACY_SETTINGS_OPTION = 'pk_theme_options';

    public const MAX_FAILURES_PER_HOUR = 100;

    /** Clés migrées depuis les options historiques du thème. */
    private const MIGRATED_KEYS = ['n8n_webhook_url', 'automation_api_secret', 'hmac_mode', 'consent_text', 'channel_url', 'quota_per_day'];

    /* Lot D (CDC v1.2 annexe C, arbitrage « Référence + plugin ») :
     * méthodes déplacées VERBATIM dans des traits composés par la
     * présente classe shell — API publique, hooks et constants inchangés. */
    use AutomationPolicyTrait;
    use AutomationHmacTrait;

    /* ------------------------------------------------------------------ */
    /* Pont entrant (accusés d'événements normalisés n8n)                 */
    /* ------------------------------------------------------------------ */

    /**
     * Journalise de manière idempotente un accusé d'événement. Le payload
     * est haché et n'est pas persisté : les numéros, messages et autres
     * données personnelles restent dans les modules métier minimisés.
     */
    public static function receive_event(\WP_REST_Request $request)
    {
        $event_id = substr(sanitize_text_field((string) $request->get_param('event_id')), 0, 191);
        $event_type = sanitize_key((string) $request->get_param('event_type'));
        $source = sanitize_key((string) $request->get_param('source'));
        $payload = $request->get_param('payload');
        $allowed_types = ['whatsapp_inbound', 'whatsapp_status', 'payment_status'];
        $prefix = 'n8n' === $source ? 'n8n-' : ('payment_provider' === $source ? 'pay-' : '');
        if (!$event_id || !$prefix || 0 !== strpos($event_id, $prefix) || strlen($event_id) > 191 || !in_array($event_type, $allowed_types, true) || !in_array($source, ['n8n', 'payment_provider'], true)) {
            return new \WP_Error('pk_automation_payload', __('Événement d’automatisation invalide.', 'partikulier'), ['status' => 400]);
        }

        global $wpdb;
        $table = self::events_table();
        $encoded_payload = wp_json_encode(is_array($payload) || is_object($payload) ? $payload : ['value' => (string) $payload]);
        $stored = $wpdb->insert(
            $table,
            [
                'event_id' => $event_id,
                'event_type' => $event_type,
                'source' => $source,
                'payload_hash' => hash_hmac('sha256', (string) $encoded_payload, wp_salt('auth')),
                'status' => 'received',
                'received_at' => current_time('mysql', true),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%s']
        );
        if (false === $stored) {
            $error = strtolower((string) $wpdb->last_error);
            if (false !== strpos($error, 'duplicate') || false !== strpos($error, 'unique')) {
                self::audit()->record('automation_event_duplicate', 'automation', null, ['event_id' => $event_id, 'event_type' => $event_type, 'source' => $source]);
                return new \WP_REST_Response(['accepted' => true, 'duplicate' => true, 'processing' => 'disabled'], 200);
            }
            return new \WP_Error('pk_automation_storage', __('Impossible de journaliser l’événement.', 'partikulier'), ['status' => 500]);
        }
        self::audit()->record('automation_event_received', 'automation', null, ['event_id' => $event_id, 'event_type' => $event_type, 'source' => $source]);
        return new \WP_REST_Response(['accepted' => true, 'duplicate' => false, 'processing' => 'disabled'], 200);
    }

    /** Adaptateur statique pour le callback RouteRegistry (owner plugin). */
    public static function rest_receive_event(\WP_REST_Request $request)
    {
        return self::receive_event($request);
    }

    /** Registre d'audit : require déterministe (incident B1 — page publique). */
    private static function audit(): \Partikulier\Core\AuditLogger
    {
        require_once __DIR__ . '/../../AuditLogger.php';
        return new \Partikulier\Core\AuditLogger();
    }
}
