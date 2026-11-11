<?php
/**
 * CLI-only fixture for full unmocked browser E2E suite in Docker CI.
 *
 * @package Partikulier
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit(2);
}

$wpDir = getenv('PK_WP_DIR') ?: '';
if (!$wpDir || !is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "PK_WP_DIR must point to WordPress\n");
    exit(2);
}

require $wpDir . '/wp-load.php';

$mode = $argv[1] ?? 'setup';
$mark = '_pk_e2e_full_fixture';
$muPlugin = rtrim($wpDir, '/') . '/wp-content/mu-plugins/pk-e2e-mail-sink.php';
$mailLog = sys_get_temp_dir() . '/pk-e2e-mails.jsonl';

if ($mode === 'cleanup') {
    foreach (get_posts([
        'post_type' => ['properties', 'attachment', 'page'],
        'post_status' => 'any',
        'numberposts' => -1,
        'meta_key' => $mark,
        'fields' => 'ids',
        'lang' => '',
        'suppress_filters' => true,
    ]) as $id) {
        if (get_post_type($id) === 'attachment') {
            wp_delete_attachment((int) $id, true);
        } else {
            wp_delete_post((int) $id, true);
        }
    }

    // Remove any listings created via pk_submit_listing during the E2E run.
    global $wpdb;
    $e2eTitles = $wpdb->get_col(
        $wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_title LIKE %s",
            'properties',
            '%E2E-%'
        )
    );
    foreach ((array) $e2eTitles as $pid) {
        wp_delete_post((int) $pid, true);
    }

    require_once $wpDir . '/wp-admin/includes/user.php';
    $admin = get_users(['role' => 'administrator', 'number' => 1]);
    $reassign = !empty($admin) ? (int) $admin[0]->ID : null;
    foreach (get_users(['search' => 'e2e-owner-*', 'search_columns' => ['user_login', 'user_email']]) as $u) {
        wp_delete_user((int) $u->ID, $reassign);
    }
    foreach (get_users(['meta_key' => $mark]) as $u) {
        wp_delete_user((int) $u->ID, $reassign);
    }
    $phoneSubmitUsers = get_users(['search' => '*212699887766*', 'search_columns' => ['user_login', 'user_email', 'display_name']]);
    foreach ($phoneSubmitUsers as $u) {
        if (!user_can((int) $u->ID, 'manage_options')) {
            wp_delete_user((int) $u->ID, $reassign);
        }
    }

    if (is_file($muPlugin)) {
        @unlink($muPlugin);
    }
    if (is_file($mailLog)) {
        @unlink($mailLog);
    }

    $prevThemeOpt = get_option('_pk_e2e_prev_theme_options', null);
    if (is_array($prevThemeOpt)) {
        update_option('pk_theme_options', $prevThemeOpt, false);
        delete_option('_pk_e2e_prev_theme_options');
    }

    if (class_exists('\Partikulier\Core\Integration\ListingSynchronizer')) {
        (new \Partikulier\Core\Integration\ListingSynchronizer())->flush();
    }
    if (class_exists('Partikulier_Cache')) {
        Partikulier_Cache::purge_all();
    }
    echo "E2E fixtures cleaned\n";
    exit(0);
}

if ($mode !== 'setup') {
    fwrite(STDERR, "Expected setup or cleanup\n");
    exit(2);
}

// 1. Install non-intrusive wp_mail capture sink for CI (container has no MTA).
$muDir = dirname($muPlugin);
if (!is_dir($muDir)) {
    mkdir($muDir, 0755, true);
}
@unlink($mailLog);
$sinkCode = <<<'PHP'
<?php
/**
 * Temporary CI E2E mail sink (captures wp_mail without modifying app code).
 */
add_filter('pre_wp_mail', static function ($null, $atts) {
    $file = sys_get_temp_dir() . '/pk-e2e-mails.jsonl';
    $entry = wp_json_encode([
        'ts' => gmdate('c'),
        'to' => $atts['to'] ?? '',
        'subject' => $atts['subject'] ?? '',
        'message' => $atts['message'] ?? '',
        'headers' => $atts['headers'] ?? [],
    ]);
    if (is_string($entry)) {
        file_put_contents($file, $entry . "\n", FILE_APPEND | LOCK_EX);
    }
    return true;
}, 10, 2);
PHP;
file_put_contents($muPlugin, $sinkCode);

// Clear contact form rate-limit transients so test starts fresh.
global $wpdb;
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_pk_contact_rl_%' OR option_name LIKE '_transient_timeout_pk_contact_rl_%'");

// Ensure WhatsApp validation & buyer qualification numbers are set cleanly for unmocked E2E (restored on cleanup).
$themeOpts = get_option('pk_theme_options', []);
if (!is_array($themeOpts)) {
    $themeOpts = [];
}
update_option('_pk_e2e_prev_theme_options', $themeOpts, false);
if (empty($themeOpts['whatsapp_validation_number'])) {
    $themeOpts['whatsapp_validation_number'] = '212612345678';
}
if (empty($themeOpts['buyer_whatsapp_number'])) {
    $themeOpts['buyer_whatsapp_number'] = '212612345678';
}
update_option('pk_theme_options', $themeOpts, false);

// 2. Ensure required pages in FR/EN/AR and rewrite rules.
if (class_exists('Partikulier_Required_Page_Translations')) {
    Partikulier_Required_Page_Translations::ensure();
} elseif (class_exists('Partikulier_Required_Pages')) {
    Partikulier_Required_Pages::create_missing();
}

$run = bin2hex(random_bytes(4));

// 3. Ensure taxonomy terms (es_location, es_type, es_status) in French.
$ensureTerm = static function (string $taxonomy, string $slug, string $name, int $parentId = 0): int {
    $existing = get_term_by('slug', $slug, $taxonomy);
    if ($existing instanceof WP_Term) {
        $tid = (int) $existing->term_id;
    } else {
        $res = wp_insert_term($name, $taxonomy, [
            'slug' => $slug,
            'parent' => $parentId,
        ]);
        if (is_wp_error($res)) {
            $tid = (int) ($res->get_error_data('term_exists') ?: 0);
        } else {
            $tid = (int) $res['term_id'];
        }
    }
    if ($tid > 0 && function_exists('pll_set_term_language')) {
        pll_set_term_language($tid, 'fr');
    }
    return $tid;
};

$locTax = defined('PARTIKULIER_ESTATIK_LOCATION_TAXONOMY') ? PARTIKULIER_ESTATIK_LOCATION_TAXONOMY : 'es_location';
$typeTax = defined('PARTIKULIER_ESTATIK_TYPE_TAXONOMY') ? PARTIKULIER_ESTATIK_TYPE_TAXONOMY : 'es_type';
$statusTax = defined('PARTIKULIER_ESTATIK_STATUS_TAXONOMY') ? PARTIKULIER_ESTATIK_STATUS_TAXONOMY : 'es_status';

$casaId = $ensureTerm($locTax, 'casablanca', 'Casablanca', 0);
$maarifId = $ensureTerm($locTax, 'maarif', 'Maarif', $casaId);
$appartId = $ensureTerm($typeTax, 'appartement', 'Appartement');
$villaId = $ensureTerm($typeTax, 'villa', 'Villa');
$ensureTerm($typeTax, 'bureau', 'Bureau');
$vendreId = $ensureTerm($statusTax, 'a-vendre', 'À vendre');
$ensureTerm($statusTax, 'a-louer', 'À louer');

// 4. Create two distinct attachments from hero.jpg for gallery carousel & lightbox tests.
require_once $wpDir . '/wp-admin/includes/image.php';
$heroFile = get_template_directory() . '/assets/img/hero.jpg';
if (!is_readable($heroFile)) {
    throw new RuntimeException('Missing fixture source image hero.jpg');
}
$heroBytes = file_get_contents($heroFile);
$attachments = [];
foreach ([1, 2] as $idx) {
    $upload = wp_upload_bits("e2e-photo-{$run}-{$idx}.jpg", null, $heroBytes);
    if (!empty($upload['error'])) {
        throw new RuntimeException((string) $upload['error']);
    }
    $attId = wp_insert_attachment([
        'post_title' => "E2E Gallery Photo {$idx} {$run}",
        'post_mime_type' => 'image/jpeg',
        'post_status' => 'inherit',
    ], $upload['file'], 0, true);
    if (is_wp_error($attId)) {
        throw new RuntimeException($attId->get_error_message());
    }
    update_post_meta($attId, $mark, $run);
    wp_update_attachment_metadata($attId, wp_generate_attachment_metadata($attId, $upload['file']));
    $attachments[] = (int) $attId;
}

// 5. Create disposable contributor owner user for /connexion/ -> /mes-annonces/ E2E.
$ownerLogin = 'e2e-owner-' . $run;
$ownerEmail = $ownerLogin . '@example.test';
$ownerPass = 'PkE2e!' . bin2hex(random_bytes(8));
$ownerId = wp_insert_user([
    'user_login' => $ownerLogin,
    'user_pass' => $ownerPass,
    'user_email' => $ownerEmail,
    'display_name' => 'Proprietaire E2E',
    'role' => 'contributor',
]);
if (is_wp_error($ownerId)) {
    throw new RuntimeException($ownerId->get_error_message());
}
update_user_meta((int) $ownerId, $mark, $run);

$adminUsers = get_users(['role' => 'administrator', 'number' => 1]);
$adminId = !empty($adminUsers) ? (int) $adminUsers[0]->ID : (int) $ownerId;

// 6. Seed active listing #1 (owned by test contributor), active listing #2 (higher price), closed listing #3.
$seedProperty = static function (array $cfg) use ($mark, $run, $attachments, $locTax, $typeTax, $statusTax): array {
    $id = wp_insert_post([
        'post_type' => 'properties',
        'post_status' => 'publish',
        'post_author' => $cfg['author'],
        'post_title' => $cfg['title'],
        'post_content' => $cfg['content'],
    ], true);
    if (is_wp_error($id)) {
        throw new RuntimeException($id->get_error_message());
    }
    update_post_meta($id, $mark, $run);
    foreach ([
        '_pk_status' => $cfg['status'],
        '_pk_owner_phone' => $cfg['phone'],
        '_pk_owner_name' => $cfg['owner_name'],
        '_pk_city_name' => 'Casablanca',
        '_pk_district_name' => $cfg['district'],
        '_pk_living_rooms' => 1,
        '_pk_terrace' => 'Oui',
        '_pk_terrace_surface' => 12,
        'es_property_price' => $cfg['price'],
        'es_property_area' => $cfg['area'],
        'es_property_bedrooms' => $cfg['bedrooms'],
        'es_property_bathrooms' => 1,
        'es_property_gallery' => $attachments,
    ] as $k => $v) {
        update_post_meta($id, $k, $v);
    }
    set_post_thumbnail($id, $attachments[0]);
    wp_set_object_terms($id, $cfg['locations'], $locTax, false);
    wp_set_object_terms($id, [$cfg['type']], $typeTax, false);
    wp_set_object_terms($id, [$cfg['action']], $statusTax, false);
    if (function_exists('pll_set_post_language')) {
        pll_set_post_language($id, 'fr');
    }
    return [
        'id' => (int) $id,
        'title' => $cfg['title'],
        'url' => get_permalink($id),
    ];
};

$activeOwner = $seedProperty([
    'author' => (int) $ownerId,
    'title' => "Appartement E2E-{$run} Maarif",
    'content' => 'Bel appartement lumineux avec terrasse au Maarif.',
    'status' => 'actif',
    'phone' => '212612345678',
    'owner_name' => 'Proprietaire E2E',
    'district' => 'Maarif',
    'price' => 1250000,
    'area' => 95,
    'bedrooms' => 2,
    'locations' => array_values(array_filter([$casaId, $maarifId])),
    'type' => $appartId,
    'action' => $vendreId,
]);

$activeSecond = $seedProperty([
    'author' => $adminId,
    'title' => "Villa E2E-{$run} Casablanca",
    'content' => 'Grande villa avec jardin à Casablanca.',
    'status' => 'actif',
    'phone' => '212612345679',
    'owner_name' => 'Vendeur E2E 2',
    'district' => 'Maarif',
    'price' => 3500000,
    'area' => 260,
    'bedrooms' => 4,
    'locations' => array_values(array_filter([$casaId, $maarifId])),
    'type' => $villaId,
    'action' => $vendreId,
]);

$closedListing = $seedProperty([
    'author' => $adminId,
    'title' => "Appartement vendu E2E-{$run}",
    'content' => 'Annonce cloturee pour test E2E.',
    'status' => 'vendu',
    'phone' => '212612345680',
    'owner_name' => 'Vendeur Clos E2E',
    'district' => 'Maarif',
    'price' => 980000,
    'area' => 80,
    'bedrooms' => 2,
    'locations' => array_values(array_filter([$casaId])),
    'type' => $appartId,
    'action' => $vendreId,
]);

if (class_exists('\Partikulier\Core\Integration\ListingSynchronizer')) {
    (new \Partikulier\Core\Integration\ListingSynchronizer())->flush();
}
flush_rewrite_rules(false);
if (class_exists('Partikulier_Cache')) {
    Partikulier_Cache::purge_all();
}

$pageUrl = static function (string $slug, string $lang): string {
    $pages = get_posts([
        'post_type' => 'page',
        'post_status' => 'publish',
        'name' => $slug,
        'posts_per_page' => 10,
        'suppress_filters' => true,
    ]);
    foreach ($pages as $p) {
        if (!function_exists('pll_get_post_language') || pll_get_post_language((int) $p->ID, 'slug') === $lang) {
            return (string) get_permalink($p->ID);
        }
    }
    return home_url("/{$lang}/{$slug}/");
};

echo wp_json_encode([
    'run' => $run,
    'mailLog' => $mailLog,
    'heroJpg' => $heroFile,
    'terms' => [
        'cityCasaId' => (int) $casaId,
        'typeAppartId' => (int) $appartId,
    ],
    'owner' => [
        'id' => (int) $ownerId,
        'login' => $ownerLogin,
        'email' => $ownerEmail,
        'password' => $ownerPass,
    ],
    'listings' => [
        'activeOwner' => $activeOwner,
        'activeSecond' => $activeSecond,
        'closed' => $closedListing,
    ],
    'pages' => [
        'homeFr' => home_url('/fr/'),
        'archiveFr' => function_exists('pk_properties_archive_url') ? pk_properties_archive_url() : home_url('/fr/annonces/'),
        'contactFr' => $pageUrl('contact', 'fr'),
        'contactAr' => $pageUrl('contact', 'ar'),
        'favorisFr' => $pageUrl('favoris', 'fr'),
        'favorisEn' => $pageUrl('favoris', 'en'),
        'connexionFr' => $pageUrl('connexion', 'fr'),
        'mesAnnoncesFr' => $pageUrl('mes-annonces', 'fr'),
        'deposerFr' => $pageUrl('deposer', 'fr'),
    ],
], JSON_PRETTY_PRINT) . "\n";
