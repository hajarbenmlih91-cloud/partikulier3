<?php
/**
 * Côté projection de la synchronisation (INTEG-1, lot A) : vidage de
 * file en lot au shutdown, garde anti-réentrance, écriture sur
 * différence seulement, plafond anti-boucle par annonce.
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

trait SynchronizerProjectionTrait
{


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
}
