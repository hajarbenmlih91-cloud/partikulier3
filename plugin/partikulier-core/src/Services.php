<?php
/**
 * Services applicatifs du plugin.
 *
 * Lot A : LeadService est le pont vers le dispositif de leads unique (INTEG-2) ;
 * JobRunner ne porte plus que le nettoyage d'idempotence — la synchronisation
 * quotidienne des annonces a disparu au profit des hooks temps réel.
 */

declare(strict_types=1);

namespace Partikulier\Core;

use Partikulier\Core\Integration\LeadBridge;
use Partikulier\Core\Integration\ListingSynchronizer;
use WP_Error;

final class ListingMediaService
{
    private const MIME = ['image/jpeg', 'image/png', 'image/webp'];

    public function validate(array $file): bool|WP_Error
    {
        $mime = (string) ($file['type'] ?? '');
        $size = (int) ($file['size'] ?? 0);
        if (!in_array($mime, self::MIME, true) || $size <= 0 || $size > 10 * 1024 * 1024) {
            return new WP_Error('invalid_media', __('Média refusé.', 'partikulier-core'), ['status' => 422]);
        }
        return true;
    }
}

final class LeadService
{
    /**
     * Pont transitoire INTEG-2 : la route alimente le dispositif complet du
     * domaine leads (thème 6.18.0, puis plugin au lot B2). Le stockage par
     * commentaire est éteint — aucun repli silencieux.
     */
    public function create(array $input): array|WP_Error
    {
        return (new LeadBridge())->create($input);
    }
}

final class FavoriteService
{
    public function toggle(int $listingId, int $userId): array|WP_Error
    {
        if ($listingId < 1 || $userId < 1) return new WP_Error('invalid_favorite', __('Favori invalide.', 'partikulier-core'), ['status' => 422]);
        $key = 'partikulier_favorites';
        $items = array_map('intval', (array) get_user_meta($userId, $key, true));
        if (in_array($listingId, $items, true)) {
            $items = array_values(array_diff($items, [$listingId]));
            $active = false;
        } else {
            $items[] = $listingId;
            $items = array_values(array_unique($items));
            $active = true;
        }
        update_user_meta($userId, $key, $items);
        return ['listing_id' => $listingId, 'active' => $active, 'count' => count($items)];
    }
}

final class SavedSearchService
{
    public function save(array $query, int $userId): bool|WP_Error
    {
        if ($userId < 1 || empty($query)) return new WP_Error('invalid_saved_search', __('Recherche invalide.', 'partikulier-core'), ['status' => 422]);
        $saved = (array) get_user_meta($userId, 'partikulier_saved_searches', true);
        $saved[] = array_intersect_key($query, array_flip(['locale', 'order', 'page', 'per_page']));
        return update_user_meta($userId, 'partikulier_saved_searches', array_slice($saved, -20));
    }
}

final class ModerationService
{
    public function setStatus(int $listingId, string $status): bool|WP_Error
    {
        if (!current_user_can('manage_options') || !in_array($status, ['draft', 'published', 'rejected'], true)) {
            return new WP_Error('forbidden_moderation', __('Action de modération interdite.', 'partikulier-core'), ['status' => 403]);
        }
        // La modération passe par le post (source de vérité) : la projection
        // suit par les hooks, le UPDATE direct de la table est proscrit (INTEG-1).
        $row = (new ListingRepository())->find($listingId);
        if (is_wp_error($row)) {
            return $row;
        }
        $postId = (int) substr((string) $row['external_id'], strlen(ListingSynchronizer::EXTERNAL_PREFIX));
        $postStatus = 'published' === $status ? 'publish' : 'draft';
        $result = wp_update_post(['ID' => $postId, 'post_status' => $postStatus], true);
        if (is_wp_error($result)) {
            return new WP_Error('moderation_failed', __('Action de modération impossible.', 'partikulier-core'), ['status' => 500]);
        }
        if ('rejected' === $status) {
            update_post_meta($postId, '_pk_status', 'refuse');
        }
        (new ListingSynchronizer())->flush();
        return true;
    }
}

final class PrivacyService
{
    public function redact(array $data): array
    {
        foreach (array_keys($data) as $key) {
            if (preg_match('/email|phone|address|secret|token|password|authorization/i', (string) $key)) unset($data[$key]);
        }
        return $data;
    }
}

final class JobRunner
{
    public function register(): void
    {
        add_action('partikulier_core_cleanup', [$this, 'cleanup']);
        if (!wp_next_scheduled('partikulier_core_cleanup')) wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'partikulier_core_cleanup');
        // Tâche quotidienne de synchronisation supprimée au lot A (INTEG-1) :
        // la projection est maintenue par les hooks du cycle de vie des posts.
        ListingSynchronizer::unscheduleLegacyCron();
    }

    public function cleanup(): void
    {
        global $wpdb;
        $wpdb->query($wpdb->prepare('DELETE FROM ' . $wpdb->prefix . 'pk_idempotency WHERE expires_at < %s', gmdate('Y-m-d H:i:s')));
    }
}
