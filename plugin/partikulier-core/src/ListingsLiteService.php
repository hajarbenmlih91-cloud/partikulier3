<?php

declare(strict_types=1);

namespace Partikulier\Core;

use WP_REST_Request;
use WP_REST_Response;

final class ListingsLiteService
{
    private const CACHE_TTL = 30; // 30 seconds transient micro-cache
    private const CACHE_PREFIX = 'pk_lite_v1:';

    public function handle(WP_REST_Request $request): WP_REST_Response
    {
        global $wpdb;

        // 1. Sanitize input parameters
        $city       = sanitize_key((string) ($request->get_param('city') ?? ''));
        $type       = sanitize_key((string) ($request->get_param('type') ?? ''));
        $offer_type = sanitize_key((string) ($request->get_param('offer_type') ?? ''));
        $price_min  = $request->get_param('price_min') !== null ? (float) $request->get_param('price_min') : null;
        $price_max  = $request->get_param('price_max') !== null ? (float) $request->get_param('price_max') : null;
        $page       = max(1, (int) ($request->get_param('page') ?: 1));
        $per_page   = min(50, max(1, (int) ($request->get_param('per_page') ?: 12)));
        $locale     = sanitize_key((string) ($request->get_param('locale') ?: 'fr'));

        // Normalize offer_type: 'vente' -> 'a-vendre', 'location' -> 'a-louer'
        if ($offer_type === 'vente') {
            $offer_type = 'a-vendre';
        } elseif ($offer_type === 'location') {
            $offer_type = 'a-louer';
        }

        // 2. Deterministic cache key
        $cache_params = [
            'city'       => $city,
            'type'       => $type,
            'offer_type' => $offer_type,
            'price_min'  => $price_min,
            'price_max'  => $price_max,
            'page'       => $page,
            'per_page'   => $per_page,
            'locale'     => $locale,
        ];
        ksort($cache_params);
        $cache_key = self::CACHE_PREFIX . md5((string) wp_json_encode($cache_params));

        // 3. Check Redis Object Cache
        $redis = $this->getRedis();
        if ($redis) {
            try {
                $cached_raw = $redis->get($cache_key);
                if ($cached_raw) {
                    $cached = json_decode($cached_raw, true);
                    if (is_array($cached)) {
                        $response = new WP_REST_Response($cached, 200);
                        $response->header('X-MicroCache', 'REDIS-HIT');
                        $response->header('X-Cache', 'HIT');
                        $response->header('Cache-Control', 'public, max-age=' . self::CACHE_TTL . ', stale-while-revalidate=60');
                        return $response;
                    }
                }
            } catch (\Throwable $e) {}
        }

        // 4. Build optimized SQL query strictly using composite index (post_status, post_type, ID)
        $joins = [];
        $wheres = [
            "p.post_status = 'publish'",
            "p.post_type = 'properties'",
        ];

        // Taxonomy filter: Location (city)
        if ($city !== '') {
            $tt_city = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'es_location' AND t.slug = %s LIMIT 1",
                $city
            ));
            if ($tt_city > 0) {
                $joins[] = "INNER JOIN {$wpdb->term_relationships} tr_city ON tr_city.object_id = p.ID AND tr_city.term_taxonomy_id = {$tt_city}";
            } else {
                // Unknown city => empty result
                $wheres[] = "1 = 0";
            }
        }

        // Taxonomy filter: Type (appartement, villa, etc.)
        if ($type !== '') {
            $tt_type = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'es_type' AND t.slug = %s LIMIT 1",
                $type
            ));
            if ($tt_type > 0) {
                $joins[] = "INNER JOIN {$wpdb->term_relationships} tr_type ON tr_type.object_id = p.ID AND tr_type.term_taxonomy_id = {$tt_type}";
            } else {
                $wheres[] = "1 = 0";
            }
        }

        // Taxonomy filter: Status (a-vendre, a-louer)
        if ($offer_type !== '') {
            $tt_status = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT tt.term_taxonomy_id FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->terms} t ON t.term_id = tt.term_id WHERE tt.taxonomy = 'es_status' AND t.slug = %s LIMIT 1",
                $offer_type
            ));
            if ($tt_status > 0) {
                $joins[] = "INNER JOIN {$wpdb->term_relationships} tr_status ON tr_status.object_id = p.ID AND tr_status.term_taxonomy_id = {$tt_status}";
            } else {
                $wheres[] = "1 = 0";
            }
        }

        // Meta filter: Price range
        if ($price_min !== null || $price_max !== null) {
            $joins[] = "INNER JOIN {$wpdb->postmeta} pm_price ON pm_price.post_id = p.ID AND pm_price.meta_key = 'es_property_price'";
            if ($price_min !== null) {
                $wheres[] = "CAST(pm_price.meta_value AS DECIMAL(14,2)) >= " . (float) $price_min;
            }
            if ($price_max !== null) {
                $wheres[] = "CAST(pm_price.meta_value AS DECIMAL(14,2)) <= " . (float) $price_max;
            }
        }

        $join_sql = !empty($joins) ? ' ' . implode(' ', $joins) : '';
        $where_sql = ' WHERE ' . implode(' AND ', $wheres);

        // Count total matching listings
        $count_sql = "SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p{$join_sql}{$where_sql}";
        $total = (int) $wpdb->get_var($count_sql);

        $offset = ($page - 1) * $per_page;
        $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 0;

        // Fetch page items using post_status_type_id covering index
        $data_sql = "SELECT p.ID, p.post_title, p.post_name FROM {$wpdb->posts} p{$join_sql}{$where_sql} ORDER BY p.ID DESC LIMIT {$per_page} OFFSET {$offset}";
        $rows = $wpdb->get_results($data_sql, ARRAY_A) ?: [];

        $items = [];
        foreach ($rows as $row) {
            $id = (int) $row['ID'];
            $price = (float) get_post_meta($id, 'es_property_price', true);
            $area = (float) get_post_meta($id, 'es_property_area', true);
            $rooms = (int) get_post_meta($id, 'es_property_rooms', true);
            
            $terms_loc = wp_get_object_terms($id, 'es_location');
            $terms_type = wp_get_object_terms($id, 'es_type');
            $terms_status = wp_get_object_terms($id, 'es_status');

            $loc_slug = !empty($terms_loc) && !is_wp_error($terms_loc) ? $terms_loc[0]->slug : 'casablanca';
            $type_slug = !empty($terms_type) && !is_wp_error($terms_type) ? $terms_type[0]->slug : 'appartement';
            $status_slug = !empty($terms_status) && !is_wp_error($terms_status) ? $terms_status[0]->slug : 'a-vendre';

            $offer_label = $status_slug === 'a-louer' ? 'location' : 'vente';

            $items[] = [
                'id'         => $id,
                'title'      => (string) $row['post_title'],
                'slug'       => (string) $row['post_name'],
                'city'       => $loc_slug,
                'type'       => $type_slug,
                'offer_type' => $offer_label,
                'price'      => $price,
                'price_fmt'  => number_format($price, 0, ',', ' ') . ' DH',
                'area'       => $area,
                'rooms'      => $rooms,
                'thumb'      => '/wp-content/uploads/sample.webp',
            ];
        }

        $payload = [
            'status'      => 'success',
            'page'        => $page,
            'per_page'    => $per_page,
            'total'       => $total,
            'total_pages' => $total_pages,
            'count'       => count($items),
            'data'        => $items,
        ];

        $json = (string) wp_json_encode($payload);

        // 5. Store in Redis Transient Cache with 30s TTL
        if ($redis) {
            try {
                $redis->setex($cache_key, self::CACHE_TTL, $json);
            } catch (\Throwable $e) {}
        }

        $response = new WP_REST_Response($payload, 200);
        $response->header('X-MicroCache', 'MISS');
        $response->header('X-Cache', 'MISS');
        $response->header('Cache-Control', 'public, max-age=' . self::CACHE_TTL . ', stale-while-revalidate=60');
        return $response;
    }

    private function getRedis(): ?\Redis
    {
        if (!class_exists('\Redis')) {
            return null;
        }
        try {
            $redis = new \Redis();
            if (@$redis->connect('127.0.0.1', 6379, 0.05)) {
                return $redis;
            }
        } catch (\Throwable $e) {}
        return null;
    }
}
