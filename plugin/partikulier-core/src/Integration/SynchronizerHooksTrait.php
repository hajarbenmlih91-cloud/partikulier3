<?php
/**
 * Côté événements de la synchronisation pk_listings (INTEG-1, lot A) :
 * accrochages du cycle de vie des posts, filtrage du bruit
 * (révisions/autosaves) et empilement dédupliqué — les hooks ne
 * traitent jamais, la file est vidée au shutdown.
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

trait SynchronizerHooksTrait
{


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
}
