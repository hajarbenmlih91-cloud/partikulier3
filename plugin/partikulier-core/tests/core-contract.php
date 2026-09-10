<?php
declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "PK_WP_DIR doit pointer vers une installation WordPress\n");
    exit(2);
}
require $wpDir . '/wp-load.php';

$started = gmdate('c');
$version = getenv('PK_VERSION') ?: '1.7.1';
$commit = getenv('PK_COMMIT') ?: '';
$runId = getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z');
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) {
    fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n");
    exit(2);
}
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$health = rest_do_request(new WP_REST_Request('GET', '/partikulier/v1/health'));
$assert('CORE-HEALTH-001', $health->get_status() === 200 && ($health->get_data()['status'] ?? '') === 'ok', 'health status');

$rateLimiter = new \Partikulier\Core\RateLimiter();
$rateBucket = 'contract-' . bin2hex(random_bytes(8));
$rateRequestOne = new WP_REST_Request('GET', '/partikulier/v1/rate-limit-contract');
$rateRequestTwo = new WP_REST_Request('GET', '/partikulier/v1/rate-limit-contract');
$rateRequestThree = new WP_REST_Request('GET', '/partikulier/v1/rate-limit-contract');
$rateOne = $rateLimiter->guard($rateRequestOne, $rateBucket, true, 2, 60);
$rateTwo = $rateLimiter->guard($rateRequestTwo, $rateBucket, true, 2, 60);
$rateThree = $rateLimiter->guard($rateRequestThree, $rateBucket, true, 2, 60);
$assert('CORE-RATE-001', $rateOne === true && $rateTwo === true && is_wp_error($rateThree) && $rateThree->get_error_code() === 'pk_rate_limited' && $rateThree->get_error_data()['status'] === 429, 'rate limiter allows two calls then returns 429');

$request = new WP_REST_Request('GET', '/partikulier/v1/listings');
$request->set_param('locale', 'fr');
$request->set_param('order', 'price_asc');
$request->set_param('per_page', 5);
$list = rest_do_request($request);
$data = $list->get_data()['data'] ?? [];
$prices = array_map(static fn(array $row): float => (float) $row['price'], $data);
$sorted = $prices;
sort($sorted, SORT_NUMERIC);
$assert('CORE-LIST-001', $list->get_status() === 200 && count($data) > 0, 'public collection');
$assert('CORE-LIST-002', $prices === $sorted, 'price whitelist ordering');

$write = new WP_REST_Request('POST', '/partikulier/v1/listings');
$write->set_header('Content-Type', 'application/json');
$write->set_body(wp_json_encode(['title' => 'anonymous', 'description' => 'blocked', 'locale' => 'fr', 'price' => 1, 'area' => 2]));
$writeResult = rest_do_request($write);
$assert('CORE-AUTH-001', in_array($writeResult->get_status(), [401, 403], true), 'anonymous write denied');

wp_set_current_user(1);
$ownerWrite = new WP_REST_Request('POST', '/partikulier/v1/listings');
$ownerWrite->set_header('Content-Type', 'application/json');
$ownerWrite->set_body(wp_json_encode(['title' => 'contract listing', 'description' => 'contract fixture', 'locale' => 'fr', 'price' => 100, 'area' => 10]));
$ownerResult = rest_do_request($ownerWrite);
$createdId = (int) (($ownerResult->get_data()['id'] ?? 0));
$createdPostId = 0;
if ($createdId > 0) {
    global $wpdb;
    $row = $wpdb->get_row($wpdb->prepare('SELECT external_id, status FROM ' . $wpdb->prefix . 'pk_listings WHERE id = %d', $createdId), ARRAY_A);
    $createdPostId = $row ? (int) substr((string) $row['external_id'], strlen('estatik:')) : 0;
    $createdPost = $createdPostId ? get_post($createdPostId) : null;
} else {
    $createdPost = null;
}
$assert('CORE-AUTH-002', $ownerResult->get_status() === 201 && $createdId > 0
    && $createdPost instanceof WP_Post && $createdPost->post_type === 'properties' && $createdPost->post_status === 'pending'
    && (string) ($row['status'] ?? '') === 'draft',
    'authenticated write allowed: post properties pending (circuit de modération) + projection draft');

$fixtureProperty = 0;
if ($createdPost instanceof WP_Post) {
    wp_update_post(['ID' => $createdPostId, 'post_status' => 'publish']);
    update_post_meta($createdPostId, '_pk_owner_phone', '212600000001');
    update_post_meta($createdPostId, '_pk_owner_name', 'Owner Contract');
    (new \Partikulier\Core\Integration\ListingSynchronizer())->flush();
    $fixtureProperty = $createdPostId;
}

$leadRequest = new WP_REST_Request('POST', '/partikulier/v1/leads');
$leadRequest->set_header('Content-Type', 'application/json');
// Hygiène de banc (découverte de la recette B2) : téléphone ALEATOIRE — l'ancien
// numéro fixe 212600000099 correspondait à un lead préexistant du banc : chaque
// rejeu mettait à jour sa ligne (last_seen_at), puis avec le nettoyage corrigé
// l'aurait supprimée. Un numéro aléatoire ne touche jamais les données réelles.
$leadRequest->set_body(wp_json_encode(['phone' => '2126' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT), 'property_id' => $fixtureProperty, 'message' => 'contract lead', 'email' => 'contract@example.test']));
$leadResult = rest_do_request($leadRequest);
$leadId = (int) (($leadResult->get_data()['data']['lead_id'] ?? 0));
$assert('CORE-LEAD-001', $leadResult->get_status() === 201 && $leadId > 0, 'lead accepted into the unified lead device (rattachement obligatoire)');

$favoriteRequest = new WP_REST_Request('POST', '/partikulier/v1/favorites');
$favoriteRequest->set_param('listing_id', (int) ($data[0]['id'] ?? $createdId));
$favoriteResult = rest_do_request($favoriteRequest);
$assert('CORE-FAVORITE-001', $favoriteResult->get_status() === 200, 'authenticated favorite toggle');

if ($createdId > 0) {
    global $wpdb;
    $wpdb->delete($wpdb->prefix . 'pk_listings', ['id' => $createdId], ['%d']);
}
if ($createdPostId > 0) {
    wp_delete_post($createdPostId, true);
    (new \Partikulier\Core\Integration\ListingSynchronizer())->flush();
}
if ($leadId > 0) {
    global $wpdb;
    // Hygiène de banc (découverte de la recette B2) : pk_buyer_leads est clé par
    // « id », pas « lead_id » — l'ancienne formulation laissait fuiter une ligne
    // orpheline par rejeu (le T0 du lot B2 en portait la trace).
    foreach (['pk_interest_events', 'pk_contact_limits', 'pk_contact_disclosures',
              'pk_whatsapp_consents', 'pk_whatsapp_messages', 'pk_buyer_preferences', 'pk_lead_followups'] as $t) {
        $wpdb->delete($wpdb->prefix . $t, ['lead_id' => $leadId], ['%d']);
    }
    $wpdb->delete($wpdb->prefix . 'pk_buyer_leads', ['id' => $leadId], ['%d']);
}
delete_user_meta(1, 'partikulier_favorites');
wp_set_current_user(0);

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
$finished = gmdate('c');
$payload = [
    'test_id' => 'CORE-CONTRACT-001',
    'candidate_version' => $version,
    'source_commit' => $commit,
    'source_ref' => getenv('GITHUB_REF') ?: 'local',
    'run_id' => $runId,
    'started_at_utc' => $started,
    'finished_at_utc' => $finished,
    'command' => 'php partikulier-core/tests/core-contract.php',
    'fixture' => 'WordPress cold runtime with Estatik properties',
    'status' => $failed ? 'FAIL' : 'PASS',
    'exit_code' => $failed ? 1 : 0,
    'tests' => $results,
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'artifacts' => ['partikulier-core/src', 'documentation/data-contract.json'],
    'limitations' => ['authenticated owner matrix and full load fixture are separate gates', 'rate limiter uses WordPress transients and requires shared object-cache verification in production'],
];
printf("%s\n", wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit($failed ? 1 : 0);
