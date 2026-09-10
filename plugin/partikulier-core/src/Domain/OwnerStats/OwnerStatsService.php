<?php
/**
 * Domaine statistiques propriétaire (lot B5) — favoris visiteurs agrégés.
 *
 * Port fidèle de Partikulier_Owner_Insights (thème 6.17.x) côté plugin :
 * même table (pk_property_saves, mêmes colonnes et index — REG-6), mêmes
 * contrôles, mêmes codes d'erreur (pk_invalid_favorite,
 * pk_unknown_favorite_property, pk_favorite_rate_limited), même
 * pseudonymisation (HMAC sha256 « favorite-v1|<visitor_id> » avec wp_salt
 * auth — non réversible), même plafonnement (60 mises à jour par visiteur
 * et par heure, via transient), même upsert ON DUPLICATE KEY par
 * (property_id, visitor_hash), même rétention (90 jours glissants sur
 * updated_at). Le thème 6.18.6+ délègue ici via des coutures class_exists ;
 * sans le plugin, il conserve son chemin autonome — les deux écrivent la
 * même table avec la même logique (matrice REG-5).
 *
 * La planification quotidienne et le handler du cron
 * pk_owner_insights_daily_purge vivent côté plugin (port du bloc de
 * Partikulier_Owner_Insights::init/maybe_install — même nom d'événement,
 * même récurrence daily, même décalage d'une heure) : le thème 6.18.6+
 * cesse de les enregistrer quand le service existe (pattern rétention du
 * lot B2).
 *
 * Les deux routes REST du tableau de bord propriétaire (/owner/dashboard,
 * /owner/listings/<id>/action) restent déclarées par le thème via les
 * helpers du bridge : ce sont des points d'intégration UI (agrégation
 * get_posts + rendu), elles ne touchent la table que par favorite_count —
 * désormais délégué (arbitrage B5 : « écran et routes au thème,
 * primitives de table au plugin », comme l'écran leads du lot B2).
 *
 * Journal d'audit : les écritures (favori enregistré, favori retiré,
 * purge) sont consignées au registre — preuve d'exécution par le plugin
 * (seul le plugin écrit ce registre ; le chemin autonome du thème n'y
 * écrit jamais, cf. matrice REG-5 des lots B1 à B4).
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\OwnerStats;

final class OwnerStatsService
{
    /** Événement cron quotidien hérité du thème 6.17.x (port fidèle du nom). */
    public const CRON_HOOK = 'pk_owner_insights_daily_purge';

    /** Rétention glissante sur updated_at (jours) — héritée du thème. */
    public const RETENTION_DAYS = 90;

    /** Type de contenu des annonces (littéral — aucune dépendance de constante thème). */
    public const POST_TYPE = 'properties';

    /** Plafond hérité : 60 mises à jour de favoris par visiteur et par heure. */
    private const RATE_LIMIT = 60;

    private const STATES = ['save', 'remove'];

    /* ------------------------------------------------------------------ */
    /* Nommage                                                            */
    /* ------------------------------------------------------------------ */

    public static function saves_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pk_property_saves';
    }

    /* ------------------------------------------------------------------ */
    /* Cron quotidien (port de l'installation thème — pattern rétention B2)*/
    /* ------------------------------------------------------------------ */

    /**
     * Planifie la purge quotidienne si l'événement n'existe pas déjà — port
     * fidèle du bloc de scheduling de maybe_install (même événement, même
     * récurrence, même décalage d'une heure). Appelée sur init (priorité 20)
     * par le bootstrap du plugin.
     */
    public static function maybe_schedule_purge(): void
    {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_HOOK);
        }
    }

    /* ------------------------------------------------------------------ */
    /* Synchronisation d'un favori (port fidèle de sync_favorite)         */
    /* ------------------------------------------------------------------ */

    /**
     * Enregistre ou retire le favori d'un visiteur pseudonymisé. Une ligne
     * active par annonce et par navigateur : l'upsert actualise la date de
     * visite (rétention glissante), le retrait supprime la ligne. Le compteur
     * n'est jamais exposé aux visiteurs — seule l'agrégation propriétaire la
     * lit (favorite_count).
     *
     * @param int    $property_id Annonce visée.
     * @param string $visitor_id  Identifiant local du navigateur (16 à 128 caractères alphanumériques/-/_).
     * @param string $state       save ou remove.
     * @return array{saved: bool}|WP_Error
     */
    public static function sync_favorite($property_id, $visitor_id, $state)
    {
        $property_id = absint($property_id);
        $visitor_id = (string) $visitor_id;
        $state = sanitize_key($state);
        if (!$property_id || !preg_match('/^[A-Za-z0-9_-]{16,128}$/', $visitor_id) || !in_array($state, self::STATES, true)) {
            return new \WP_Error('pk_invalid_favorite', __('Favori invalide.', 'partikulier'), ['status' => 400]);
        }
        if (self::POST_TYPE !== get_post_type($property_id) || 'publish' !== get_post_status($property_id)) {
            return new \WP_Error('pk_unknown_favorite_property', __('Annonce introuvable.', 'partikulier'), ['status' => 404]);
        }

        $hash = hash_hmac('sha256', 'favorite-v1|' . $visitor_id, wp_salt('auth'));
        $rate_key = 'pk_favorite_rate_' . $hash;
        $rate_used = (int) get_transient($rate_key);
        if ($rate_used >= self::RATE_LIMIT) {
            return new \WP_Error('pk_favorite_rate_limited', __('Trop de mises à jour de favoris. Réessayez plus tard.', 'partikulier'), ['status' => 429]);
        }
        set_transient($rate_key, $rate_used + 1, HOUR_IN_SECONDS);

        global $wpdb;
        $table = self::saves_table();
        $now = current_time('mysql', true);
        if ('save' === $state) {
            $result = $wpdb->query(
                $wpdb->prepare(
                    "INSERT INTO {$table} (property_id, visitor_hash, created_at, updated_at) VALUES (%d, %s, %s, %s) ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)",
                    $property_id,
                    $hash,
                    $now,
                    $now
                )
            );
            if (false !== $result) {
                self::audit('owner_favorite_saved', 'property', $property_id, ['state' => 'save']);
            }
        } else {
            $result = $wpdb->delete($table, ['property_id' => $property_id, 'visitor_hash' => $hash], ['%d', '%s']);
            if (false !== $result && $result > 0) {
                self::audit('owner_favorite_removed', 'property', $property_id, ['state' => 'remove']);
            }
        }
        return ['saved' => 'save' === $state];
    }

    /* ------------------------------------------------------------------ */
    /* Agrégat propriétaire (port fidèle de favorite_count)               */
    /* ------------------------------------------------------------------ */

    /**
     * Nombre de favoris actifs d'une annonce (fenêtre de rétention 90 jours
     * sur updated_at) — alimente le tableau de bord propriétaire.
     */
    public static function favorite_count($property_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::saves_table() . ' WHERE property_id = %d AND updated_at >= %s',
                absint($property_id),
                self::retention_cutoff()
            )
        );
    }

    /* ------------------------------------------------------------------ */
    /* Purge de rétention (port fidèle, cron daily)                        */
    /* ------------------------------------------------------------------ */

    /**
     * Supprime les pseudonymes inactifs depuis plus de 90 jours : l'agrégat
     * reste utile au propriétaire sans constituer un historique durable de
     * navigation (RGPD — anonymisation par conception). Retourne le nombre
     * de lignes supprimées (le cron l'ignore ; les tests en font leur preuve).
     */
    public static function purge_expired_saves(): int
    {
        global $wpdb;
        $cutoff = self::retention_cutoff();
        $count = (int) $wpdb->query(
            $wpdb->prepare('DELETE FROM ' . self::saves_table() . ' WHERE updated_at < %s', $cutoff)
        );
        if ($count > 0) {
            self::audit('owner_saves_purged', 'property', null, ['count' => $count, 'cutoff' => $cutoff]);
        }
        return $count;
    }

    /* ------------------------------------------------------------------ */
    /* Fenêtre de rétention (port fidèle)                                  */
    /* ------------------------------------------------------------------ */

    private static function retention_cutoff(): string
    {
        return gmdate('Y-m-d H:i:s', time() - (self::RETENTION_DAYS * DAY_IN_SECONDS));
    }

    /** Consigne une écriture au registre d'audit — chargement déterministe. */
    private static function audit(string $action, string $object_type, ?int $object_id, array $metadata): void
    {
        require_once __DIR__ . '/../../AuditLogger.php';
        (new \Partikulier\Core\AuditLogger())->record($action, $object_type, $object_id, $metadata);
    }
}
