<?php
/**
 * Essai jetable : la page reste ouverte, WordPress note l'arrivée,
 * le sondage renvoie l'annonce, aucune réponse n'est envoyée.
 *
 * PK_WP_DIR=... PK_BASE=http://127.0.0.1:8099 php theme/partikulier/tests/page-ouverte-whatsapp-contract.php
 */
declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$base = rtrim(getenv('PK_BASE') ?: '', '/');
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n");
    exit(2);
}
if ($base === '') {
    fwrite(STDERR, "PK_BASE doit être l'origine de la page ouverte\n");
    exit(2);
}
require $wpDir . '/wp-load.php';

$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};
$finish = static function () use (&$results): void {
    $failed = 0;
    foreach ($results as $row) {
        if ($row['status'] !== 'PASS') {
            $failed++;
        }
    }
    $out = [
        'status' => $failed === 0 ? 'PASS' : 'FAIL',
        'passed' => count($results) - $failed,
        'failed' => $failed,
        'total' => count($results),
        'results' => $results,
    ];
    echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit($failed === 0 ? 0 : 1);
};

$run = bin2hex(random_bytes(4));
$code = 'PEP-' . $run;
$postId = 0;
try {
    if (!post_type_exists('properties')) {
        register_post_type('properties', ['public' => true, 'publicly_queryable' => true]);
    }
    $created = wp_insert_post([
        'post_type' => 'properties',
        'post_status' => 'pending',
        'post_title' => 'Essai page ouverte ' . $run,
        'post_content' => 'Essai',
        'post_author' => 1,
    ], true);
    $assert('PO-001', !is_wp_error($created) && (int) $created > 0, is_wp_error($created) ? $created->get_error_code() : 'annonce créée');
    if (is_wp_error($created) || (int) $created < 1) {
        $finish();
    }
    $postId = (int) $created;
    update_post_meta($postId, '_pk_whatsapp_verification_code', $code);

    $before = Partikulier_WhatsApp_Verification::status_for_code($code);
    $assert('PO-002', ($before['matched'] ?? null) === false && ($before['url'] ?? null) === '', 'avant le message, rien n\'est rapproché');

    $statusUrl = Partikulier_WhatsApp_Verification::status_url($code);
    $assert('PO-003', str_contains($statusUrl, 'pk_whatsapp_match_status') && str_contains($statusUrl, rawurlencode($code)) && !str_contains($statusUrl, '212640424412'), 'sondage sans numéro');

    [$codeBefore, $bodyBefore] = pk_essai_get($statusUrl);
    $jsonBefore = json_decode($bodyBefore, true);
    $assert('PO-004', $codeBefore === 200 && ($jsonBefore['data']['matched'] ?? null) === false, 'la page ouverte lit encore l\'attente');

    $mails = 0;
    $http = 0;
    add_filter('pre_wp_mail', static function () use (&$mails) {
        $mails++;
        return false;
    });
    add_filter('pre_http_request', static function () use (&$http) {
        $http++;
        return new WP_Error('pk_essai_bloque', 'aucune sortie');
    }, 10, 3);

    $noted = Partikulier_WhatsApp_Verification::mark_inbound_matched($code, 'wamid.' . $run);
    $assert('PO-005', $noted === true, 'arrivée notée');
    $assert('PO-006', (bool) get_post_meta($postId, '_pk_whatsapp_inbound_matched_at', true), 'horodatage enregistré');
    $assert('PO-007', get_post_status($postId) === 'pending', 'l\'annonce n\'est pas publiée');
    $assert('PO-008', $mails === 0 && $http === 0, 'aucune réponse et aucune sortie');

    $again = Partikulier_WhatsApp_Verification::mark_inbound_matched($code, 'wamid.' . $run);
    $assert('PO-009', $again === true && $mails === 0 && $http === 0, 'un doublon n\'envoie rien');
    $assert('PO-010', Partikulier_WhatsApp_Verification::mark_inbound_matched('AUTRE-' . $run, 'wamid.x') === false, 'un autre code n\'est pas noté');

    remove_all_filters('pre_wp_mail');
    remove_all_filters('pre_http_request');

    $after = Partikulier_WhatsApp_Verification::status_for_code($code);
    $permalink = (string) get_permalink($postId);
    $assert('PO-011', ($after['matched'] ?? false) === true && ($after['url'] ?? '') === $permalink && $permalink !== '', 'l\'écran peut ouvrir l\'annonce');

    [$codeAfter, $bodyAfter] = pk_essai_get($statusUrl);
    $jsonAfter = json_decode($bodyAfter, true);
    $assert('PO-012', $codeAfter === 200 && ($jsonAfter['data']['matched'] ?? null) === true && ($jsonAfter['data']['url'] ?? '') === $permalink, 'la page ouverte reçoit l\'adresse');
    $assert('PO-013', has_action('wp_ajax_nopriv_pk_whatsapp_match_status'), 'sondage disponible sans session');

    $theme = get_template_directory();
    $form = (string) file_get_contents($theme . '/inc/class-form.php');
    $page = (string) file_get_contents($theme . '/assets/js/main.js');
    $assert('PO-014', substr_count($form, 'status_url') === 4, 'les deux réponses du dépôt donnent le sondage');
    $assert('PO-015', str_contains($page, 'function watchInboundMatch') && str_contains($page, 'if ( ! body.matched || stopped) return'), 'la page ne change pas avant l\'arrivée');
} finally {
    if ($postId > 0) {
        wp_delete_post($postId, true);
    }
}
$finish();

/**
 * @return array{0:int,1:string}
 */
function pk_essai_get(string $url): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($handle);
    $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    return [$status, is_string($body) ? $body : ''];
}
