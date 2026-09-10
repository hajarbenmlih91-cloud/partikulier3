<?php
/**
 * Synchronisation temps réel de la projection pk_listings (INTEG-1, lot A).
 *
 * Le type de contenu WordPress « properties » est la source de vérité ; la
 * table pk_listings est une projection maintenue à jour par les événements du
 * cycle de vie des posts — plus aucune tâche quotidienne.
 *
 * Gardes REG-1 obligatoires, toutes présentes :
 *  - filtrage du bruit : types autres que properties, révisions et
 *    autosaves ignorés ;
 *  - écriture uniquement sur différence : la ligne projetée est comparée à la
 *    ligne existante avant toute requête d'écriture ;
 *  - anti-réentrance : un indicateur d'exécution neutralise les hooks pendant
 *    le traitement de la file ;
 *  - plafond anti-boucle par annonce : au-delà de MAX_WRITES_PER_MINUTE
 *    écritures par minute sur un même post, alerte au registre d'audit et
 *    arrêt pour ce post ;
 *  - file d'attente : les hooks ne font qu'empiler (dédupliqué), jamais
 *    traiter ; la file est vidée en lot au shutdown — un import de N posts
 *    dans une même requête produit exactement N écritures, pas N×k.
 *
 * rebuild() : vidage puis reconstruction complète depuis les posts, journalisée
 * (comptages avant/après + entrée d'audit) et idempotente — rejouée deux fois,
 * le résultat est identique. Exécutée une fois par le Migrator au passage en
 * 2.0.0, rejouable via WP-CLI (wp partikulier rebuild).
 */

declare(strict_types=1);

namespace Partikulier\Core\Integration;

use Partikulier\Core\AuditLogger;

final class ListingSynchronizer
{
    public const POST_TYPE = 'properties';
    public const EXTERNAL_PREFIX = 'estatik:';
    public const MAX_WRITES_PER_MINUTE = 60;
    private const STATS_OPTION = 'partikulier_core_sync_stats';

    /** @var array<int, true> */
    private static array $upsertQueue = [];

    /** @var array<int, true> */
    private static array $deleteQueue = [];

    private static bool $registered = false;
    private static bool $flushing = false;

    public function register(): void
    {
        if (self::$registered) {
            return;
        }
        self::$registered = true;
        add_action('save_post', [$this, 'onSavePost'], 10, 2);
        add_action('transition_post_status', [$this, 'onTransitionStatus'], 10, 3);
        add_action('before_delete_post', [$this, 'onDeletePost'], 10, 1);
        add_action('added_post_meta', [$this, 'onMetaChange'], 10, 4);
        add_action('updated_post_meta', [$this, 'onMetaChange'], 10, 4);
        add_action('shutdown', [$this, 'flush']);
    }

    /**
     * save_post : empile tout post properties hors révision/autosave.
     * Les écritures de la synchronisation elle-même ne touchent que la table
     * de projection : elles ne redéclenchent jamais ces hooks.
     */
    public function onSavePost(int $postId, $post): void
    {
        if (self::$flushing) {
            return; // anti-réentrance
        }
        if (! $post instanceof \WP_Post || $post->post_type !== self::POST_TYPE) {
            return;
        }
        if (wp_is_post_revision($postId) || wp_is_post_autosave($postId)) {
            return;
        }
        $this->enqueueUpsert((int) $postId);
    }

    public function onTransitionStatus(string $newStatus, string $oldStatus, $post): void
    {
        if (self::$flushing) {
            return;
        }
        if (! $post instanceof \WP_Post || $post->post_type !== self::POST_TYPE) {
            return;
        }
        $this->enqueueUpsert((int) $post->ID);
    }

    public function onDeletePost(int $postId): void
    {
        if (self::$flushing) {
            return;
        }
        $post = get_post($postId);
        if (! $post instanceof \WP_Post || $post->post_type !== self::POST_TYPE) {
            return;
        }
        $this->enqueueDelete((int) $postId);
    }

    /**
     * Changements de prix et de surface (métadonnées Estatik) : empilement,
     * la comparaison avant écriture évite toute requête inutile.
     */
    public function onMetaChange(int $metaId, int $objectId, string $metaKey, $value): void
    {
        if (self::$flushing) {
            return;
        }
        if (! in_array($metaKey, ['es_property_price', 'es_property_area'], true)) {
            return;
        }
        $post = get_post($objectId);
        if (! $post instanceof \WP_Post || $post->post_type !== self::POST_TYPE) {
            return;
        }
        if (wp_is_post_revision($objectId) || wp_is_post_autosave($objectId)) {
            return;
        }
        $this->enqueueUpsert((int) $objectId);
    }

    private function enqueueUpsert(int $postId): void
    {
        unset(self::$deleteQueue[$postId]);
        self::$upsertQueue[$postId] = true;
    }

    private function enqueueDelete(int $postId): void
    {
        unset(self::$upsertQueue[$postId]);
        self::$deleteQueue[$postId] = true;
    }

    /**
     * Vide la file en lot : une lecture groupée, puis écritures uniquement sur
     * différence. Public : le test de contrat et la route de création appellent
     * flush() explicitement pour propager avant de répondre.
     */
    public function flush(): array
    {
        if (self::$flushing || (self::$upsertQueue === [] && self::$deleteQueue === [])) {
            return ['upserts' => 0, 'deletes' => 0, 'inserts' => 0, 'updates' => 0, 'skipped_loop' => 0];
        }
        self::$flushing = true;
        global $wpdb;
        $report = ['upserts' => 0, 'deletes' => 0, 'inserts' => 0, 'updates' => 0, 'skipped_loop' => 0];

        try {
            $deletes = array_keys(self::$deleteQueue);
            $upserts = array_keys(self::$upsertQueue);
            self::$deleteQueue = [];
            self::$upsertQueue = [];

            foreach ($deletes as $postId) {
                $wpdb->delete($wpdb->prefix . 'pk_listings', ['external_id' => self::EXTERNAL_PREFIX . $postId], ['%s']);
                $report['deletes']++;
            }

            foreach ($upserts as $postId) {
                $post = get_post($postId);
                if (! $post instanceof \WP_Post) {
                    // Le post a disparu entre l'empilement et le vidage : la ligne doit disparaître.
                    $wpdb->delete($wpdb->prefix . 'pk_listings', ['external_id' => self::EXTERNAL_PREFIX . $postId], ['%s']);
                    $report['deletes']++;
                    continue;
                }
                if (! $this->belowLoopCap($postId, $report)) {
                    continue;
                }
                $action = $this->upsertProjected($this->project($post));
                $report['upserts']++;
                if ($action === 'insert') {
                    $report['inserts']++;
                } elseif ($action === 'update') {
                    $report['updates']++;
                }
            }

            $this->invalidateSearchCache();
            if (($report['upserts'] + $report['deletes']) > 0) {
                $this->recordStats($report);
            }
        } finally {
            self::$flushing = false;
        }
        return $report;
    }

    /** Compteur anti-boucle par post : plafond d'écritures par fenêtre d'une minute. */
    private function belowLoopCap(int $postId, array &$report): bool
    {
        $key = 'pk_sync_w_' . $postId;
        $count = (int) get_transient($key);
        if ($count >= self::MAX_WRITES_PER_MINUTE) {
            $report['skipped_loop']++;
            (new AuditLogger())->record(
                'sync_loop_cap',
                'listing',
                $postId,
                ['writes_last_minute' => $count, 'cap' => self::MAX_WRITES_PER_MINUTE]
            );
            return false;
        }
        set_transient($key, $count + 1, MINUTE_IN_SECONDS);
        return true;
    }

    /**
     * Projection d'un post : valeurs que la table doit porter.
     *
     * @return array{external_id: string, owner_user_id: int, status: string, locale: string, title: string, description: string, price: float, area: float}
     */
    public function project(\WP_Post $post): array
    {
        $refused = get_post_meta($post->ID, '_pk_status', true) === 'refuse';
        $locale = function_exists('pll_get_post_language') ? (string) (pll_get_post_language((int) $post->ID, 'slug') ?: 'fr') : 'fr';
        return [
            'external_id' => self::EXTERNAL_PREFIX . $post->ID,
            'owner_user_id' => (int) $post->post_author,
            'status' => $this->projectedStatus($post, $refused),
            'locale' => in_array($locale, ['fr', 'en', 'ar'], true) ? $locale : 'fr',
            'title' => (string) $post->post_title,
            'description' => wp_strip_all_tags((string) $post->post_content),
            'price' => (float) get_post_meta($post->ID, 'es_property_price', true),
            'area' => (float) get_post_meta($post->ID, 'es_property_area', true),
        ];
    }

    /**
     * Statut projeté : publié seulement si le post est publié et non refusé
     * par la modération ; refusé si la modération a rejeté ; brouillon sinon
     * (brouillon, en attente, corbeille… tout ce qui n'est pas servi).
     */
    private function projectedStatus(\WP_Post $post, bool $refused): string
    {
        if ($refused) {
            return 'rejected';
        }
        return $post->post_status === 'publish' ? 'published' : 'draft';
    }

    /**
     * Écriture sur différence uniquement : retourne insert|update|noop.
     */
    public function upsertProjected(array $projected): string
    {
        global $wpdb;
        $table = $wpdb->prefix . 'pk_listings';
        $existing = $wpdb->get_row(
            $wpdb->prepare("SELECT owner_user_id, status, locale, title, description, price, area FROM {$table} WHERE external_id = %s", $projected['external_id']),
            ARRAY_A
        );
        $now = gmdate('Y-m-d H:i:s');
        $fields = [
            'owner_user_id' => $projected['owner_user_id'],
            'status' => $projected['status'],
            'locale' => $projected['locale'],
            'title' => $projected['title'],
            'description' => $projected['description'],
            'price' => $projected['price'],
            'area' => $projected['area'],
        ];
        if (is_array($existing)) {
            $diff = false;
            foreach ($fields as $name => $value) {
                $current = $existing[$name];
                if (is_float($value)) {
                    if (abs((float) $current - $value) > 0.001) {
                        $diff = true;
                    }
                } elseif ((string) $current !== (string) $value) {
                    $diff = true;
                }
            }
            if (! $diff) {
                return 'noop';
            }
            $fields['updated_at'] = $now;
            $wpdb->update($table, $fields, ['external_id' => $projected['external_id']], ['%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s'], ['%s']);
            return 'update';
        }
        // Colonnes et formats strictement alignés : external_id, owner_user_id,
        // status, locale, title, description, price, area, created_at, updated_at.
        $row = [
            'external_id' => $projected['external_id'],
            'owner_user_id' => $projected['owner_user_id'],
            'status' => $projected['status'],
            'locale' => $projected['locale'],
            'title' => $projected['title'],
            'description' => $projected['description'],
            'price' => $projected['price'],
            'area' => $projected['area'],
            'created_at' => $now,
            'updated_at' => $now,
        ];
        $wpdb->insert($table, $row, ['%s', '%d', '%s', '%s', '%s', '%s', '%f', '%f', '%s', '%s']);
        return 'insert';
    }

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
