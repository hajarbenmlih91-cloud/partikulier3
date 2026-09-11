<?php
/**
 * Sécurité HMAC du pont n8n (lot B4) : signature sortante, vérification
 * des requêtes entrantes (mode off/log/enforce, fenêtre 300 s,
 * rotation), limiteur d'échecs par heure — consignés dans
 * pk_n8n_hmac_audit.
 *
 * Lot D (découpage, arbitrage commanditaire « Référence + plugin », CDC
 * v1.2 annexe C) : le service AutomationService (467 lignes, code porté par la campagne
 * au lot B4) est découpé en shell + traits sur le précédent B6
 * (class-localization.php 990 → 199 l.) — les méthodes sont déplacées
 * VERBATIM, l'API publique et les hooks restent portés par la classe shell
 * (le trait compose la même classe : aucune délégation, aucun changement de
 * mécanisme actif). Preuve : contrat module-perimeter-contract.php + rejeu
 * intégral des suites du domaine (oracle inchangé).
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Automation;

trait AutomationHmacTrait
{


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
}
