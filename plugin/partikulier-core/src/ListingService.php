<?php
/**
 * Service de création d'annonces (INTEG-1, lot A).
 *
 * La création passe désormais par le post WordPress — la source de vérité :
 * un post properties au statut « pending » entre dans le circuit de
 * modération existant (file d'attente de validation du thème), et la
 * projection pk_listings est créée par la synchronisation immédiatement
 * vidée avant la réponse. Aucune insertion directe en table sans post.
 */

declare(strict_types=1);

namespace Partikulier\Core;

use Partikulier\Core\Integration\ListingSynchronizer;
use WP_Error;

final class ListingService
{
    public function __construct(private ListingRepository $repository, private AuditLogger $audit)
    {
    }

    /** @return int|WP_Error identifiant de la ligne de projection (contrat REST inchangé) */
    public function create(array $input, int $ownerId): int|WP_Error
    {
        $title = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $locale = sanitize_key((string) ($input['locale'] ?? 'fr'));
        $price = (float) ($input['price'] ?? 0);
        $area = (float) ($input['area'] ?? 0);
        if ($title === '' || $description === '') {
            return new WP_Error('invalid_listing', __('Titre et description obligatoires.', 'partikulier-core'), ['status' => 422]);
        }
        if (!in_array($locale, ['fr', 'en', 'ar'], true) || $price < 0 || $area <= 0) {
            return new WP_Error('invalid_listing', __('Données d’annonce invalides.', 'partikulier-core'), ['status' => 422]);
        }

        // Post WordPress : la voie unique de création, soumise au circuit de
        // modération (statut « pending » lu par la file d'attente de validation).
        $postId = wp_insert_post([
            'post_type' => ListingSynchronizer::POST_TYPE,
            'post_status' => 'pending',
            'post_title' => $title,
            'post_content' => $description,
            'post_author' => $ownerId,
        ], true);
        if (is_wp_error($postId)) {
            return new WP_Error('listing_insert_failed', __('Création impossible.', 'partikulier-core'), ['status' => 500]);
        }
        $postId = (int) $postId;

        update_post_meta($postId, 'es_property_price', $price);
        update_post_meta($postId, 'es_property_area', $area);
        if (function_exists('pll_set_post_language')) {
            pll_set_post_language($postId, $locale);
        }

        // Propagation immédiate : la projection doit exister avant la réponse.
        $sync = new ListingSynchronizer();
        $sync->register();
        $sync->flush();

        $row = $this->repository->rowForExternalId(ListingSynchronizer::EXTERNAL_PREFIX . $postId);
        if (! is_array($row)) {
            wp_delete_post($postId, true);
            return new WP_Error('listing_projection_failed', __('Création impossible.', 'partikulier-core'), ['status' => 500]);
        }
        $this->audit->record('listing_created', 'listing', (int) $row['id'], [
            'post_id' => $postId,
            'locale' => $locale,
            'price' => $price,
            'area' => $area,
        ]);
        return (int) $row['id'];
    }
}
