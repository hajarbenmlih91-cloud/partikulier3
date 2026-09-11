<?php
/**
 * Rétention et vie privée du dispositif de leads (port fidèle de
 * Partikulier_Lead_Retention, lot B2) : purge quotidienne bornée,
 * effacement transactionnel des huit tables, consentement croisé,
 * résolution et chiffrement du numéro (HMAC de recherche, AES-256-CBC
 * administrateur uniquement).
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

trait LeadsPrivacyTrait
{


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
}
