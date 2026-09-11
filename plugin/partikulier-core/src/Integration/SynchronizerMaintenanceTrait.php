<?php
/**
 * Maintenance de la projection (INTEG-1, lot A) : rebuild complet
 * idempotent (WP-CLI), réconciliation, invalidation du cache de
 * recherche et statistiques.
 *
 * Lot D (découpage, arbitrage commanditaire « Référence + plugin », CDC
 * v1.2 annexe C) : le service ListingSynchronizer (432 lignes, code porté par la campagne
 * au lot A) est découpé en shell + traits sur le précédent B6
 * (class-localization.php 990 → 199 l.) — les méthodes sont déplacées
 * VERBATIM, l'API publique et les hooks restent portés par la classe shell
 * (le trait compose la même classe : aucune délégation, aucun changement de
 * mécanisme actif). Preuve : contrat module-perimeter-contract.php + rejeu
 * intégral des suites du domaine (oracle inchangé).
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Integration;

use Partikulier\Core\AuditLogger;

trait SynchronizerMaintenanceTrait
{


    /**
     * Vidage puis reconstruction complète depuis les posts — journalisée et
     * idempotente. Retour : rapport de comptages avant/après (journalisé au
     * registre d'audit, conservé comme option pour le health check).
     */
    public function rebuild(): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pk_listings';
        $before = [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'published' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'published')),
        ];
        $wpdb->query("DELETE FROM {$table}");

        // Liste d'identifiants par SQL direct : get_posts() reste filtré par la
        // langue courante (Polylang, pre_get_posts) même avec suppress_filters,
        // et la projection doit couvrir les posts de toutes les langues.
        $ids = (array) $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'properties'"
            . " AND post_status NOT IN ('auto-draft', 'trash', 'inherit') ORDER BY ID ASC"
        );
        $inserts = 0;
        foreach ($ids as $postId) {
            $post = get_post((int) $postId);
            if (! $post instanceof \WP_Post) {
                continue;
            }
            $this->upsertProjected($this->project($post));
            $inserts++;
        }
        $after = [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}"),
            'published' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE status = %s", 'published')),
        ];
        $report = [
            'rebuilt_at' => gmdate('c'),
            'posts' => count($ids),
            'before' => $before,
            'after' => $after,
            'writes' => $inserts,
        ];
        $this->invalidateSearchCache();
        (new AuditLogger())->record('listing_projection_rebuilt', 'schema', null, $report);
        update_option('partikulier_core_last_rebuild', $report, false);
        return $report;
    }


    /**
     * Réparation ciblée (WP-CLI) : supprime les lignes publiées sans post
     * vivant et projette les posts publiés sans ligne. N'est PAS un cron :
     * le mécanisme unique est la synchronisation par hooks ; cet outil sert
     * de filet de sécurité manuel après un incident.
     */
    public function reconcile(): array
    {
        global $wpdb;
        $listings = $wpdb->prefix . 'pk_listings';
        $posts = $wpdb->posts;
        $ghosts = (array) $wpdb->get_col(
            "SELECT l.external_id FROM {$listings} l WHERE l.status = 'published'"
            . " AND NOT EXISTS (SELECT 1 FROM {$posts} p WHERE p.post_type = 'properties' AND p.post_status = 'publish' AND CONCAT('estatik:', p.ID) = l.external_id)"
        );
        $removed = 0;
        foreach ($ghosts as $externalId) {
            $wpdb->delete($listings, ['external_id' => (string) $externalId], ['%s']);
            $removed++;
        }
        $missing = (array) $wpdb->get_col(
            "SELECT p.ID FROM {$posts} p WHERE p.post_type = 'properties' AND p.post_status = 'publish'"
            . " AND NOT EXISTS (SELECT 1 FROM {$listings} l WHERE l.external_id = CONCAT('estatik:', p.ID))"
        );
        $added = 0;
        foreach ($missing as $postId) {
            $post = get_post((int) $postId);
            if ($post instanceof \WP_Post) {
                $this->upsertProjected($this->project($post));
                $added++;
            }
        }
        $report = ['ghosts_removed' => $removed, 'missing_projected' => $added, 'at' => gmdate('c')];
        $this->invalidateSearchCache();
        (new AuditLogger())->record('listing_projection_reconciled', 'schema', null, $report);
        return $report;
    }


    private function invalidateSearchCache(): void
    {
        if (function_exists('apcu_enabled') && apcu_enabled() && function_exists('apcu_inc')) {
            $next = apcu_inc('pk_listing_search_version');
            if (! is_int($next)) {
                apcu_store('pk_listing_search_version', 2, 0);
            }
        }
    }


    private function recordStats(array $report): void
    {
        $stats = [
            'last_flush_at' => gmdate('c'),
            'last_flush' => $report,
            'totals' => [
                'flushes' => (int) ($this->stats()['totals']['flushes'] ?? 0) + 1,
                'writes' => (int) ($this->stats()['totals']['writes'] ?? 0) + $report['inserts'] + $report['updates'] + $report['deletes'],
            ],
        ];
        update_option(self::STATS_OPTION, $stats, false);
    }


    public function stats(): array
    {
        $stats = get_option(self::STATS_OPTION, []);
        return is_array($stats) ? $stats : [];
    }


    /** Purge des événements cron obsolètes hérités de la synchronisation quotidienne. */
    public static function unscheduleLegacyCron(): int
    {
        $removed = 0;
        while ((int) wp_next_scheduled('partikulier_core_sync_estatik')) {
            wp_unschedule_event((int) wp_next_scheduled('partikulier_core_sync_estatik'), 'partikulier_core_sync_estatik');
            $removed++;
        }
        return $removed;
    }
}
