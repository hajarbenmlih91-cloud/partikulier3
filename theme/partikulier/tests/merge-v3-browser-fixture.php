<?php
/** CLI-only fixture for the cross-browser merge contract. */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') exit(2);
$wpDir = getenv('PK_WP_DIR') ?: '';
if (!is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "PK_WP_DIR must point to WordPress\n");
    exit(2);
}
require $wpDir . '/wp-load.php';

$mode = $argv[1] ?? 'setup';
$mark = '_pk_merge_browser_fixture';
if ($mode === 'cleanup') {
    foreach (get_posts(['post_type' => ['properties', 'attachment', 'page'], 'post_status' => 'any', 'numberposts' => -1, 'meta_key' => $mark, 'fields' => 'ids', 'lang' => '', 'suppress_filters' => true]) as $id) {
        if (get_post_type($id) === 'attachment') wp_delete_attachment($id, true);
        else wp_delete_post($id, true);
    }
    (new \Partikulier\Core\Integration\ListingSynchronizer())->flush();
    echo "Browser fixtures removed\n";
    exit(0);
}
if ($mode !== 'setup') {
    fwrite(STDERR, "Expected setup or cleanup\n");
    exit(2);
}
require_once $wpDir . '/wp-admin/includes/image.php';
$run = bin2hex(random_bytes(4));
$upload = wp_upload_bits('merge-browser-' . $run . '.jpg', null, file_get_contents(get_template_directory() . '/assets/img/hero.jpg'));
if ($upload['error']) throw new RuntimeException($upload['error']);
$attachment = wp_insert_attachment(['post_title' => 'Merge browser image', 'post_mime_type' => 'image/jpeg', 'post_status' => 'inherit'], $upload['file'], 0, true);
if (is_wp_error($attachment)) throw new RuntimeException($attachment->get_error_message());
update_post_meta($attachment, $mark, $run);
wp_update_attachment_metadata($attachment, wp_generate_attachment_metadata($attachment, $upload['file']));
$admin = get_users(['role' => 'administrator', 'number' => 1])[0];
$languages = function_exists('pll_languages_list') ? pll_languages_list(['fields' => 'slug']) : ['fr'];
$urls = [];
$listingIds = [];
$deposits = [];
foreach ($languages as $language) {
    $id = wp_insert_post([
        'post_type' => 'properties',
        'post_status' => 'publish',
        'post_author' => $admin->ID,
        'post_title' => 'MERGE-BROWSER-' . $run . '-' . $language,
        'post_content' => 'Browser regression fixture.',
    ], true);
    if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
    update_post_meta($id, $mark, $run);
    foreach ([
        '_pk_status' => 'actif',
        '_pk_owner_phone' => '212600000000',
        '_pk_owner_name' => 'Fixture',
        '_pk_city_name' => 'Casablanca',
        '_pk_district_name' => 'Maarif',
        'es_property_price' => 1200000,
        'es_property_area' => 120,
        'es_property_gallery' => [$attachment, $attachment],
    ] as $key => $value) update_post_meta($id, $key, $value);
    set_post_thumbnail($id, $attachment);
    if (function_exists('pll_set_post_language')) pll_set_post_language($id, $language);
    $listingIds[$language] = $id;
    $urls[$language] = get_permalink($id);
}
if (function_exists('pll_save_post_translations')) {
    pll_save_post_translations($listingIds);
    $pages = get_posts(['post_type' => 'page', 'name' => 'deposer', 'lang' => 'fr', 'numberposts' => 1]);
    if (!$pages) throw new RuntimeException('The French deposit page must be provisioned');
    $translations = pll_get_post_translations($pages[0]->ID);
    foreach ($languages as $language) {
        if (!empty($translations[$language])) continue;
        $id = wp_insert_post(['post_type' => 'page', 'post_status' => 'publish', 'post_name' => 'deposer', 'post_title' => 'Deposit fixture ' . $language], true);
        if (is_wp_error($id)) throw new RuntimeException($id->get_error_message());
        update_post_meta($id, $mark, $run);
        update_post_meta($id, '_wp_page_template', 'templates/page-deposer-annonce.php');
        pll_set_post_language($id, $language);
        $translations[$language] = $id;
    }
    pll_save_post_translations($translations);
    foreach ($languages as $language) $deposits[$language] = get_permalink($translations[$language]);
}
(new \Partikulier\Core\Integration\ListingSynchronizer())->flush();
flush_rewrite_rules(false);
if (class_exists('Partikulier_Cache')) Partikulier_Cache::purge_all();
echo wp_json_encode(['listings' => $urls, 'deposits' => $deposits], JSON_PRETTY_PRINT) . "\n";
