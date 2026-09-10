<?php
/**
 * Dépôt de lecture de la projection pk_listings.
 *
 * Depuis le lot A (INTEG-1), les lectures ne servent JAMAIS une ligne dont le
 * post source est absent, refusé ou dépublié :
 *  - find() : vérification directe du post pour les lignes estatik ;
 *  - search() : garde EXISTS portable MySQL/SQLite dans la requête même.
 * La projection étant maintenue en temps réel par ListingSynchronizer, ces
 * gardes sont un filet de sécurité : elles garantissent l'invariant « jamais
 * servi » même en cas de dérive accidentelle de la table.
 */

declare(strict_types=1);

namespace Partikulier\Core;

use Partikulier\Core\Integration\ListingSynchronizer;
use WP_Error;

final class ListingRepository
{
    public function find(int $id): array|WP_Error
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, owner_user_id, external_id, status, locale, title, description, price, area, created_at, updated_at FROM ' . $wpdb->prefix . 'pk_listings WHERE id = %d',
            $id
        ), ARRAY_A);
        if (! is_array($row)) {
            return new WP_Error('listing_not_found', __('Annonce introuvable.', 'partikulier-core'), ['status' => 404]);
        }
        if (! $this->postIsAlive((string) $row['external_id'])) {
            return new WP_Error('listing_not_found', __('Annonce introuvable.', 'partikulier-core'), ['status' => 404]);
        }
        return $row;
    }

    /**
     * Un post source vivant : présent, publié, non refusé par la modération.
     * Les lignes non-estatik (créées par la voie API d'origine) n'ont pas de
     * post source : elles ne sont plus produites depuis le lot A et les
     * historiques ont été purgés par la reconstruction — refusées ici.
     */
    private function postIsAlive(string $externalId): bool
    {
        if (! str_starts_with($externalId, ListingSynchronizer::EXTERNAL_PREFIX)) {
            return false;
        }
        $postId = (int) substr($externalId, strlen(ListingSynchronizer::EXTERNAL_PREFIX));
        $post = get_post($postId);
        if (! $post instanceof \WP_Post || $post->post_type !== ListingSynchronizer::POST_TYPE) {
            return false;
        }
        if ($post->post_status !== 'publish' || get_post_meta($postId, '_pk_status', true) === 'refuse') {
            return false;
        }
        return true;
    }

    /** @return array<int, array<string, mixed>> */
    public function search(string $locale = 'fr', string $order = 'newest', int $page = 1, int $perPage = 24): array
    {
        global $wpdb;
        $orders = [
            'newest' => 'l.created_at DESC, l.id DESC',
            'price_asc' => 'l.price ASC, l.id DESC',
            'price_desc' => 'l.price DESC, l.id DESC',
            'area_asc' => 'l.area ASC, l.id DESC',
            'area_desc' => 'l.area DESC, l.id DESC',
        ];
        $orderBy = $orders[$order] ?? $orders['newest'];
        $page = max(1, $page);
        $perPage = min(100, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        // APCu est strictement optionnel : en présence d'un cache partagé par
        // worker, il évite de refaire la même lecture publique pendant une
        // courte fenêtre. Sans l'extension, le chemin SQL reste inchangé.
        $cacheKey = $this->searchCacheKey($locale, $order, $page, $perPage);
        if (self::apcuAvailable()) {
            $found = false;
            $cached = apcu_fetch($cacheKey, $found);
            if ($found && is_array($cached)) {
                return $cached;
            }
        }

        // Garde INTEG-1 : la ligne n'est servie que si son post source est
        // publié. Formulation portable (fonctions hors jointure, CONCAT dans
        // la sous-requête corrélée — validée sur MySQL et sur le traducteur
        // SQLite du banc de développement).
        $sql = 'SELECT l.id, l.owner_user_id, l.external_id, l.status, l.locale, l.title, l.description, l.price, l.area, l.created_at, l.updated_at'
            . ' FROM ' . $wpdb->prefix . 'pk_listings l'
            . " WHERE l.status = 'published' AND l.locale = %s"
            . " AND (l.external_id NOT LIKE 'estatik:%'"
            . ' OR EXISTS (SELECT 1 FROM ' . $wpdb->posts . " p WHERE p.post_type = 'properties' AND p.post_status = 'publish' AND CONCAT('estatik:', p.ID) = l.external_id))"
            . ' ORDER BY ' . $orderBy . ' LIMIT %d OFFSET %d';
        $rows = $wpdb->get_results($wpdb->prepare($sql, sanitize_key($locale), $perPage, $offset), ARRAY_A) ?: [];
        if (self::apcuAvailable()) {
            apcu_store($cacheKey, $rows, 2);
        }
        return $rows;
    }

    private function searchCacheKey(string $locale, string $order, int $page, int $perPage): string
    {
        $version = self::apcuAvailable() ? (int) (apcu_fetch('pk_listing_search_version') ?: 1) : 1;
        return 'pk_listing_search_' . $version . '_' . md5(sanitize_key($locale) . '|' . $order . '|' . $page . '|' . $perPage);
    }

    private static function apcuAvailable(): bool
    {
        return function_exists('apcu_enabled') && apcu_enabled() && function_exists('apcu_fetch') && function_exists('apcu_store');
    }

    /** Ligne de projection pour une clé externe, ou null. */
    public function rowForExternalId(string $externalId): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare(
            'SELECT id, owner_user_id, external_id, status, locale, title, description, price, area, created_at, updated_at FROM ' . $wpdb->prefix . 'pk_listings WHERE external_id = %s',
            $externalId
        ), ARRAY_A);
        return is_array($row) ? $row : null;
    }
}
