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

    /* ------------------------------------------------------------------ */
    /* Tableaux de nommage                                                */
    /* ------------------------------------------------------------------ */

    public static function events_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pk_automation_events';
    }

    public static function audit_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pk_n8n_hmac_audit';
    }

    /* ------------------------------------------------------------------ */
    /* Réglages (port fidèle de Partikulier_N8n_Security)                 */
    /* ------------------------------------------------------------------ */

    public static function env_secret(): string
    {
        if (defined('PARTIKULIER_N8N_SECRET') && PARTIKULIER_N8N_SECRET) {
            return (string) PARTIKULIER_N8N_SECRET;
        }
        $value = getenv('PARTIKULIER_N8N_SECRET');
        return false !== $value && '' !== $value ? (string) $value : '';
    }

    public static function env_webhook(): string
    {
        if (defined('PARTIKULIER_N8N_WEBHOOK_URL') && PARTIKULIER_N8N_WEBHOOK_URL) {
            return (string) PARTIKULIER_N8N_WEBHOOK_URL;
        }
        $value = getenv('PARTIKULIER_N8N_WEBHOOK_URL');
        return false !== $value && '' !== $value ? (string) $value : '';
    }

    public static function settings(): array
    {
        $value = get_option(self::SETTINGS_OPTION, []);
        return is_array($value) ? $value : [];
    }

    public static function get($key, $default = '')
    {
        $env_secret = self::env_secret();
        if ('automation_api_secret' === $key && $env_secret) {
            return $env_secret;
        }
        if ('n8n_webhook_url' === $key) {
            $env_webhook = self::env_webhook();
            if ($env_webhook) {
                return $env_webhook;
            }
        }
        $settings = self::settings();
        if (array_key_exists($key, $settings) && '' !== (string) $settings[$key]) {
            return $settings[$key];
        }
        if ('completed' !== get_option(self::MIGRATION_OPTION, '')) {
            $legacy = get_option(self::LEGACY_SETTINGS_OPTION, []);
            if (is_array($legacy) && array_key_exists($key, $legacy)) {
                return $legacy[$key];
            }
        }
        return $default;
    }

    public static function secret_keys(): array
    {
        $settings = self::settings();
        $active = self::env_secret() ?: (string) ($settings['automation_api_secret'] ?? '');
        $active_id = self::env_secret() ? 'env' : (string) ($settings['active_key_id'] ?? 'N');
        $keys = [$active_id => $active];
        if (!empty($settings['previous_secret']) && !empty($settings['previous_key_id']) && strtotime((string) ($settings['previous_expires_at'] ?? '')) > time()) {
            $keys[(string) $settings['previous_key_id']] = (string) $settings['previous_secret'];
        }
        return array_filter($keys);
    }

    /**
     * Migration unique des réglages n8n depuis les options historiques du
     * thème (port fidèle — état pending/verified/completed, retrait des
     * clés migrées de l'option source une fois vérifié). L'exécution est
     * idempotente : l'état « completed » court-circuite.
     */
    public static function maybe_migrate(): void
    {
        $state = (string) get_option(self::MIGRATION_OPTION, '');
        if ('completed' === $state) {
            return;
        }
        $current = get_option(self::SETTINGS_OPTION, null);
        if (!is_array($current)) {
            $current = [];
        }
        $legacy = get_option(self::LEGACY_SETTINGS_OPTION, []);
        if (!is_array($legacy)) {
            $legacy = [];
        }
        if ('pending' !== $state) {
            update_option(self::MIGRATION_OPTION, 'pending', false);
        }
        foreach (self::MIGRATED_KEYS as $key) {
            if (!array_key_exists($key, $current) && array_key_exists($key, $legacy)) {
                $current[$key] = $legacy[$key];
            }
        }
        $mode = $current['hmac_mode'] ?? 'off';
        $current['hmac_mode'] = in_array($mode, ['off', 'log', 'enforce'], true) ? $mode : 'off';
        $current['quota_per_day'] = max(1, min(10, absint($current['quota_per_day'] ?? 2) ?: 2));
        if (!empty($current['n8n_webhook_url']) && !self::is_https_url((string) $current['n8n_webhook_url'])) {
            $current['n8n_webhook_url'] = '';
        }
        if (!empty($current['automation_api_secret'])) {
            $current['automation_api_secret'] = (string) $current['automation_api_secret'];
        }
        update_option(self::SETTINGS_OPTION, $current, false);
        $verified = is_array(get_option(self::SETTINGS_OPTION, null)) && (!empty($current['automation_api_secret']) || self::env_secret());
        if ($verified) {
            update_option(self::MIGRATION_OPTION, 'verified', false);
            foreach (self::MIGRATED_KEYS as $key) {
                if (array_key_exists($key, $legacy)) {
                    unset($legacy[$key]);
                }
            }
            update_option(self::LEGACY_SETTINGS_OPTION, $legacy, false);
            update_option(self::MIGRATED_AT_OPTION, gmdate('c'), false);
            update_option(self::MIGRATION_OPTION, 'completed', false);
        }
    }

    /**
     * Validation et enregistrement des réglages depuis l'écran
     * d'administration (port fidèle de save_admin, sans l'UI : capability,
     * nonce et redirection restent au thème). Retourne un WP_Error pour un
     * secret trop faible (même message hérité) — l'appelant décide du
     * rendu (wp_die).
     *
     * @param array $posted Champs pk_n8n déséchappés (wp_unslash déjà fait).
     * @return true|\WP_Error
     */
    public static function save_admin_settings(array $posted)
    {
        $current = self::settings();
        $url = esc_url_raw((string) ($posted['n8n_webhook_url'] ?? ''));
        if ($url && !self::is_https_url($url)) {
            $url = '';
        }
        $current['n8n_webhook_url'] = $url;
        $current['hmac_mode'] = in_array($posted['hmac_mode'] ?? 'off', ['off', 'log', 'enforce'], true) ? $posted['hmac_mode'] : 'off';
        $current['quota_per_day'] = max(1, min(10, absint($posted['quota_per_day'] ?? 2) ?: 2));
        $current['consent_text'] = sanitize_textarea_field((string) ($posted['consent_text'] ?? ''));
        $current['channel_url'] = esc_url_raw((string) ($posted['channel_url'] ?? ''));
        $replacement = trim((string) ($posted['automation_api_secret'] ?? ''));
        if ($replacement) {
            if (!self::is_strong_secret($replacement)) {
                return new \WP_Error('pk_n8n_secret_weak', __('Le secret doit contenir au moins 32 octets aléatoires encodés.', 'partikulier'));
            }
            $current['automation_api_secret'] = $replacement;
        }
        update_option(self::SETTINGS_OPTION, $current, false);
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Webhooks sortants signés                                           */
    /* ------------------------------------------------------------------ */

    /**
     * Construit les en-têtes d'un webhook sortant signé. Le corps exact et
     * le chemin du webhook entrent dans la signature afin qu'un nœud n8n
     * puisse rejeter une requête rejouée ou altérée.
     *
     * @param string $method Méthode HTTP.
     * @param string $url URL complète du webhook.
     * @param string $body Corps JSON exact.
     * @return array|\WP_Error
     */
    public static function outgoing_headers($method, $url, $body)
    {
        $keys = self::secret_keys();
        if (empty($keys)) {
            return new \WP_Error('pk_n8n_secret_missing', __('Secret n8n non configuré.', 'partikulier'));
        }
        $key_id = (string) array_key_first($keys);
        $secret = (string) $keys[$key_id];
        $parts = wp_parse_url($url);
        $path = (string) ($parts['path'] ?? '/');
        if (!empty($parts['query'])) {
            $path .= '?' . $parts['query'];
        }
        $timestamp = (string) time();
        $canonical = strtoupper((string) $method) . "\n" . $path . "\n" . $timestamp . "\n" . (string) $body;
        return [
            'Content-Type' => 'application/json',
            'X-Partikulier-Automation' => $secret,
            'X-Partikulier-Timestamp' => $timestamp,
            'X-Partikulier-Key-Id' => $key_id,
            'X-Partikulier-Signature' => 'sha256=' . hash_hmac('sha256', $canonical, self::hmac_key($secret)),
        ];
    }

    /* ------------------------------------------------------------------ */
    /* Authentification n8n (route entrante)                              */
    /* ------------------------------------------------------------------ */

    /**
     * Vérifie le secret partagé n8n/WordPress puis, selon le mode HMAC, la
     * signature horodatée (port fidèle : secret présent + mode off =
     * enforce — LOT 2, Isolation ; mode log = échecs journalisés et requête
     * acceptée ; fenêtre de 300 secondes ; rotation par previous_key_id).
     */
    public static function check_automation_secret(\WP_REST_Request $request)
    {
        $keys = self::secret_keys();
        $secret = self::get('automation_api_secret');

        $provided = self::get_normalized_header($request, 'X-Partikulier-Automation');
        if (!$provided) {
            $authorization = self::get_normalized_header($request, 'Authorization');
            if (0 === stripos($authorization, 'bearer ')) {
                $provided = trim(substr($authorization, 7));
            }
        }

        $shared_valid = false;
        foreach ($keys as $candidate) {
            if ($provided && hash_equals(trim((string) $candidate, '='), trim((string) $provided, '='))) {
                $shared_valid = true;
                break;
            }
        }

        if (!$secret || !$provided || !$shared_valid) {
            return new \WP_Error('pk_automation_auth', __('Requête non autorisée.', 'partikulier'), ['status' => 401]);
        }

        $mode = self::get('hmac_mode', 'off');
        if ('off' === $mode && '' !== (string) $secret) {
            /*
             * LOT 2 — Isolation : un secret configuré ne doit jamais suffire
             * seul. Avant : mode « off » + secret => l'en-tête ouvrait la route
             * SANS signature. Désormais : secret présent + mode « off » =
             * enforce. Le mode « log » reste disponible pour un staging où n8n
             * ne signe pas encore ; la prod doit être en « enforce ».
             */
            $mode = 'enforce';
        }
        if ('off' === $mode) {
            return true;
        }

        $timestamp = self::get_normalized_header($request, 'X-Partikulier-Timestamp');
        $key_id = self::get_normalized_header($request, 'X-Partikulier-Key-Id');
        $signature = self::get_normalized_header($request, 'X-Partikulier-Signature');

        $valid = ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300 && preg_match('/^sha256=[a-f0-9]{64}$/', $signature);
        $secret_for_key = $keys[$key_id] ?? '';

        if ($valid && $secret_for_key) {
            $path = (string) $request->get_route();
            $canonical = strtoupper($request->get_method()) . "\n" . $path . "\n" . $timestamp . "\n" . $request->get_body();
            $expected = 'sha256=' . hash_hmac('sha256', $canonical, self::hmac_key($secret_for_key));
            $valid = hash_equals($expected, $signature);
        }

        if (!$valid) {
            if ('log' === $mode) {
                self::audit_failure($key_id ?: 'missing', 'invalid_signature');
                return true;
            }
            return new \WP_Error('pk_automation_signature', __('Requête non autorisée.', 'partikulier'), ['status' => 401]);
        }
        return true;
    }

    /**
     * Journalise un échec d'authentification HMAC : compteur par clé et par
     * heure (UNIQUE KEY key_hour), plafonné à MAX_FAILURES_PER_HOUR — port
     * fidèle de la formulation INSERT ... ON DUPLICATE KEY UPDATE.
     */
    public static function audit_failure($key_id, $reason): void
    {
        global $wpdb;
        $table = self::audit_table();
        $hour = gmdate('Y-m-d H:00:00');
        $wpdb->query($wpdb->prepare(
            "INSERT INTO {$table} (key_id,hour_key,failure_count,last_reason) VALUES (%s,%s,1,%s) ON DUPLICATE KEY UPDATE failure_count=LEAST(failure_count+1,%d),last_reason=VALUES(last_reason)",
            sanitize_key($key_id),
            $hour,
            sanitize_key($reason),
            self::MAX_FAILURES_PER_HOUR
        ));
    }

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

    /* ------------------------------------------------------------------ */
    /* Helpers privés (port fidèle)                                       */
    /* ------------------------------------------------------------------ */

    private static function hmac_key($secret)
    {
        $secret = trim((string) $secret);
        $decoded = base64_decode($secret, true);
        if (is_string($decoded) && strlen($decoded) >= 32) {
            return $decoded;
        }
        if (preg_match('/^[a-f0-9]{64,}$/i', $secret)) {
            $hex = hex2bin(substr($secret, 0, strlen($secret) - (strlen($secret) % 2)));
            if (false !== $hex && strlen($hex) >= 32) {
                return $hex;
            }
        }
        return $secret;
    }

    public static function is_strong_secret($secret): bool
    {
        $secret = trim((string) $secret);
        $decoded = base64_decode($secret, true);
        $bytes = is_string($decoded) ? strlen($decoded) : 0;
        if ($bytes < 32 && preg_match('/^[a-f0-9]{64,}$/i', $secret)) {
            $bytes = (int) (strlen($secret) / 2);
        }
        if ($bytes < 32 || preg_match('/^(.)\1+$/', $secret)) {
            return false;
        }
        return true;
    }

    public static function is_https_url($url): bool
    {
        return 'https' === strtolower((string) wp_parse_url($url, PHP_URL_SCHEME));
    }

    /**
     * Récupère un en-tête de manière normalisée (insensible à la casse et
     * aux séparateurs).
     */
    private static function get_normalized_header(\WP_REST_Request $request, $name): string
    {
        $value = $request->get_header(strtolower(str_replace('-', '_', $name)));
        if (empty($value)) {
            $value = $request->get_header(strtolower($name));
        }
        return (string) $value;
    }

    /** Registre d'audit : require déterministe (incident B1 — page publique). */
    private static function audit(): \Partikulier\Core\AuditLogger
    {
        require_once __DIR__ . '/../../AuditLogger.php';
        return new \Partikulier\Core\AuditLogger();
    }
}
