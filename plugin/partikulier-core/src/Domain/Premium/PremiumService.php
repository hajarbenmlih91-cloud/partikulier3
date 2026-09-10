<?php
/**
 * Domaine premium (lot B1) — journal des attributions premium.
 *
 * Port fidèle de Partikulier_Premium (thème 6.17.x) côté plugin : mêmes
 * validations, mêmes codes d'erreur, mêmes colonnes et méta-clés, mêmes
 * transitions (active → expired | revoked). Le thème 6.18.2+ délègue ici
 * via une couture class_exists ; sans le plugin, il conserve son chemin
 * autonome — les deux écrivent les mêmes tables avec la même logique
 * (matrice REG-5).
 *
 * Le journal est un registre d'audit : la création (grant), la lecture
 * (recent/counts) et les transitions d'état (expire, revoke) sont couvertes
 * par le contrat ; la suppression d'une ligne n'est pas une opération métier
 * et n'est volontairement pas exposée.
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Premium;

final class PremiumService
{
    public const POST_TYPE = 'properties';
    public const OPTION_PUBLIC_ENABLED = 'pk_premium_public_enabled';
    public const META_STATUS = '_pk_premium_status';
    public const META_STARTS_AT = '_pk_premium_starts_at';
    public const META_ENDS_AT = '_pk_premium_ends_at';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_REVOKED = 'revoked';

    public static function table_name(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pk_premium_history';
    }

    /**
     * Le drapeau protège les listes publiques tant que la gate G3 n'est pas
     * formellement validée dans l'administration du projet (inchangé).
     */
    public static function is_public_enabled(): bool
    {
        return '1' === (string) get_option(self::OPTION_PUBLIC_ENABLED, '0');
    }

    /**
     * Attribue un créneau premium dans le journal.
     *
     * @param int    $property_id Identifiant du post properties.
     * @param int    $granted_by  Administrateur ayant décidé l'attribution.
     * @param string $reason      Motif traçable obligatoire.
     * @param string $starts_at   Date UTC MySQL.
     * @param string $ends_at     Date UTC MySQL.
     * @return int|\WP_Error
     */
    public static function grant(int $property_id, int $granted_by, string $reason, string $starts_at, string $ends_at)
    {
        $property_id = absint($property_id);
        $granted_by = absint($granted_by);
        $reason = sanitize_textarea_field($reason);
        $starts_at = self::normalize_datetime($starts_at);
        $ends_at = self::normalize_datetime($ends_at);

        if (!$property_id || get_post_type($property_id) !== self::POST_TYPE) {
            return new \WP_Error('pk_premium_property', __('Annonce premium invalide.', 'partikulier-core'));
        }
        if (!$granted_by || !user_can($granted_by, 'manage_options')) {
            return new \WP_Error('pk_premium_permission', __('Autorisation premium insuffisante.', 'partikulier-core'));
        }
        if (!$reason) {
            return new \WP_Error('pk_premium_reason', __('Un motif de sélection est obligatoire.', 'partikulier-core'));
        }
        if (!$starts_at || !$ends_at || strtotime($ends_at . ' UTC') <= strtotime($starts_at . ' UTC')) {
            return new \WP_Error('pk_premium_dates', __('La période premium est invalide.', 'partikulier-core'));
        }

        global $wpdb;
        $owner_id = (int) get_post_field('post_author', $property_id);
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            self::table_name(),
            [
                'property_id' => $property_id,
                'owner_id' => $owner_id,
                'status' => self::STATUS_ACTIVE,
                'selection_reason' => $reason,
                'granted_by' => $granted_by,
                'granted_at' => $now,
                'starts_at' => $starts_at,
                'ends_at' => $ends_at,
            ],
            ['%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s']
        );
        if (false === $inserted) {
            return new \WP_Error('pk_premium_storage', __('Impossible d’enregistrer l’attribution premium.', 'partikulier-core'));
        }
        // insert_id doit être capturé IMMÉDIATEMENT : update_post_meta écrit dans
        // wp_postmeta via wpdb et écrase insert_id (bug de port attrapé par le
        // contrat PREM-002 — le thème 6.17.x lisait juste après son insert).
        $historyId = (int) $wpdb->insert_id;

        update_post_meta($property_id, self::META_STATUS, self::STATUS_ACTIVE);
        update_post_meta($property_id, self::META_STARTS_AT, $starts_at);
        update_post_meta($property_id, self::META_ENDS_AT, $ends_at);

        self::audit('premium_granted', 'premium_grant', $historyId, [
            'property_id' => $property_id,
            'owner_id' => $owner_id,
            'granted_by' => $granted_by,
            'ends_at' => $ends_at,
        ]);

        return $historyId;
    }

    /**
     * Vérifie l'état courant et bascule une attribution échue sans dépendre de
     * WP-Cron : la première lecture après échéance la rend immédiatement inactive.
     */
    public static function is_active(int $property_id): bool
    {
        $property_id = absint($property_id);
        if (self::STATUS_ACTIVE !== (string) get_post_meta($property_id, self::META_STATUS, true)) {
            return false;
        }
        $ends_at = (string) get_post_meta($property_id, self::META_ENDS_AT, true);
        if (!$ends_at || strtotime($ends_at . ' UTC') <= time()) {
            self::expire($property_id);
            return false;
        }
        return true;
    }

    public static function expire(int $property_id): void
    {
        self::close_current($property_id, self::STATUS_EXPIRED, 0, __('Expiration automatique.', 'partikulier-core'));
        self::audit('premium_expired', 'premium_grant', $property_id, ['lazy' => true]);
    }

    /**
     * @return true|\WP_Error
     */
    public static function revoke(int $property_id, int $revoked_by, string $reason)
    {
        $revoked_by = absint($revoked_by);
        if (!$revoked_by || !user_can($revoked_by, 'manage_options')) {
            return new \WP_Error('pk_premium_permission', __('Autorisation premium insuffisante.', 'partikulier-core'));
        }
        if (!sanitize_textarea_field($reason)) {
            return new \WP_Error('pk_premium_reason', __('Un motif de retrait est obligatoire.', 'partikulier-core'));
        }
        self::close_current($property_id, self::STATUS_REVOKED, $revoked_by, $reason);
        self::audit('premium_revoked', 'premium_grant', $property_id, ['revoked_by' => $revoked_by, 'reason' => sanitize_text_field($reason)]);
        return true;
    }

    /**
     * Journal récent (même tri que l'écran d'administration du thème).
     *
     * @return array<int, object>
     */
    public static function recent_rows(int $limit = 50): array
    {
        global $wpdb;
        $limit = max(1, min(200, $limit));
        return (array) $wpdb->get_results($wpdb->prepare(
            'SELECT * FROM ' . self::table_name() . ' ORDER BY granted_at DESC, id DESC LIMIT %d',
            $limit
        ));
    }

    /**
     * Comptages par statut — sonde de contrat et future sonde de santé.
     *
     * @return array<string, int>
     */
    public static function history_counts(): array
    {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS n FROM ' . self::table_name() . ' GROUP BY status', ARRAY_A);
        $counts = [self::STATUS_ACTIVE => 0, self::STATUS_EXPIRED => 0, self::STATUS_REVOKED => 0];
        foreach ((array) $rows as $row) {
            $counts[(string) $row['status']] = (int) $row['n'];
        }
        return $counts;
    }

    private static function close_current(int $property_id, string $status, int $actor_id, string $reason): void
    {
        global $wpdb;
        $property_id = absint($property_id);
        $wpdb->query(
            $wpdb->prepare(
                'UPDATE ' . self::table_name() . ' SET status = %s, revoked_by = %d, revoked_at = %s, revocation_reason = %s WHERE property_id = %d AND status = %s',
                $status,
                absint($actor_id),
                current_time('mysql', true),
                sanitize_textarea_field($reason),
                $property_id,
                self::STATUS_ACTIVE
            )
        );
        update_post_meta($property_id, self::META_STATUS, $status);
    }

    private static function normalize_datetime(string $value): string
    {
        $value = trim($value);
        $timestamp = strtotime($value . ' UTC');
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }

    /** Consigne une transition au registre d'audit — chargement déterministe. */
    private static function audit(string $action, string $object_type, ?int $object_id, array $metadata): void
    {
        require_once __DIR__ . '/../../AuditLogger.php';
        (new \Partikulier\Core\AuditLogger())->record($action, $object_type, $object_id, $metadata);
    }
}
