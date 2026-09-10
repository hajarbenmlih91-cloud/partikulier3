<?php
declare(strict_types=1);

namespace Partikulier\Core;

final class SearchService
{
    public const ALLOWED_ORDERS = ['newest', 'price_asc', 'price_desc', 'area_asc', 'area_desc'];

    public function __construct(private ListingRepository $repository)
    {
    }

    /** @return array<int, array<string, mixed>> */
    public function search(array $query): array
    {
        $locale = sanitize_key((string) ($query['locale'] ?? 'fr'));
        $order = (string) ($query['order'] ?? 'newest');
        return $this->repository->search(
            in_array($locale, ['fr', 'en', 'ar'], true) ? $locale : 'fr',
            in_array($order, self::ALLOWED_ORDERS, true) ? $order : 'newest',
            max(1, (int) ($query['page'] ?? 1)),
            min(100, max(1, (int) ($query['per_page'] ?? 24)))
        );
    }
}
