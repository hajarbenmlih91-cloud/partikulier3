<?php
/**
 * Contrat SE-034 — idempotence du canal public de dépôt (E-3401→E-3404,
 * micro-lot pré-prod 2.10.8/6.20.7).
 *
 * R1 (pré-correctif, mesuré) : deux POST identiques au canal public
 * (admin-ajax pk_submit_listing, double-clic simulé) créaient DEUX annonces
 * avec deux codes de vérification distincts — aucune clé d'idempotence, la
 * table pk_idempotency du domaine listings restait inutilisée par le canal.
 *
 * Le correctif : champ caché pk_idempotency_key (unique à chaque affichage
 * du formulaire), rejeu AVANT le quota (rejouer ne consomme rien), réponse
 * stockée dans pk_idempotency (event_id « pkform: », TTL 24 h), conflit
 * refusé (même clé + charge différente = 400).
 *
 * Ce contrat verrouille (HTTP réel, infrastructure se043) :
 *  - E34-001 : première soumission → 200 success, replayed:false, +1 annonce ;
 *  - E34-002 : re-soumission IDENTIQUE (même clé) → réponse identique hors
 *              replayed, replayed:TRUE, zéro nouvelle annonce ;
 *  - E34-003 : charge distincte (clé distincte) → nouvelle annonce (les
 *              soumissions légitimes ne sont pas fusionnées) ;
 *  - E34-004 : clé expirée → la ligne ne rejoue plus, création normale ;
 *  - E34-005 : même clé + charge différente → refus 400, zéro annonce ;
 *  - E34-006 : sémantique REST du canal authentifié (E-3404, documentée
 *              par la mesure) : GET /listings 200 · POST complet sans auth
 *              401 · POST incomplet authentifié 400 ;
 *  - E34-007 : sortie propre — annonces, lignes pkform:*, ville, numéro
 *              WhatsApp et quota restaurés.
 *
 * Rejouable : PK_BASE=http://127.0.0.1:8099 PK_WP_DIR=<wp> PK_COMMIT=<sha>
 *   php plugin/partikulier-core/tests/se034-form-idempotency-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$base = getenv('PK_BASE') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if ($base === '' || !preg_match('#^https?://#', $base)) { fwrite(STDERR, "PK_BASE doit pointer vers le serveur démarré\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
wp_set_current_user(1);
$run = bin2hex(random_bytes(4));
$rateKey = 'pk_listing_rate_' . hash_hmac('sha256', '127.0.0.1', wp_salt('nonce'));

/* ── Préparation : WhatsApp configuré (piège docs/reprise/04-PIEGES.md), quota
 *    purgé, type existant, ville dédiée au run. ── */
$opts = get_option('pk_theme_options', []);
if (!is_array($opts)) { $opts = []; }
$optsBackup = $opts;
$opts['whatsapp_validation_number'] = '212600000000';
update_option('pk_theme_options', $opts);
delete_transient($rateKey);

$typeTerms = get_terms(['taxonomy' => 'es_type', 'hide_empty' => false, 'number' => 1]);
$typeId = is_array($typeTerms) && $typeTerms ? (int) $typeTerms[0]->term_id : 0;
$citySlug = 'se034ville-' . $run;
$cityTerm = wp_insert_term('SE034ville ' . $run, 'es_location', ['slug' => $citySlug]);
$cityId = is_wp_error($cityTerm) ? (int) get_term_by('slug', $citySlug, 'es_location')->term_id : (int) $cityTerm['term_id'];

/* Le nonce doit être créé en contexte ANONYME : les requêtes curl de la
 * batterie ne portent aucun cookie — un nonce d'administrateur serait refusé
 * (403) par check_ajax_referer côté serveur. */
wp_set_current_user(0);
$nonce = wp_create_nonce('pk_submit_listing');

$payloadOf = static fn(string $marker): array => [
    'action' => 'pk_submit_listing',
    'nonce' => $nonce,
    'pk_form_action' => 'pk_submit_listing',
    'pk_title' => 'Appartement SE034 ' . $marker,
    'pk_description' => 'Une description suffisamment longue pour passer la validation du formulaire de depot, cinquante caracteres au minimum.',
    'pk_price' => '750000',
    'pk_surface' => '90',
    'pk_type' => (string) $typeId,
    'pk_city' => (string) $cityId,
    'pk_name' => 'Test SE034',
    'pk_email' => 'test@example.com',
    'pk_phone' => '0600000000',
    'pk_role' => 'proprietaire',
];

/** POST admin-ajax avec la charge + la clé d'idempotence. */
$postForm = static function (array $payload, string $key) use ($base): array {
    $payload['pk_idempotency_key'] = $key;
    $ch = curl_init(rtrim($base, '/') . '/wp-admin/admin-ajax.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['X-Requested-With: XMLHttpRequest'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return ['status' => $code, 'json' => is_array($json) ? $json : [], 'raw' => is_string($body) ? $body : ''];
};

$countListings = static function (string $marker) use ($wpdb): int {
    return (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'properties' AND post_title LIKE %s",
        '%SE034 ' . $marker . '%'
    ));
};

$idemTable = $wpdb->prefix . 'pk_idempotency';
$k1 = str_pad('a1', 32, '1');   // 32 hex — clé du rejeu
$k2 = str_pad('b2', 32, '2');   // charge distincte
$k3 = str_pad('c3', 32, '3');   // clé expirée

try {
    // 1) Première soumission → création, replayed:false.
    $r1 = $postForm($payloadOf($run . '-alpha'), $k1);
    $c1 = $countListings($run . '-alpha');
    $assert('E34-001', $r1['status'] === 200 && ($r1['json']['success'] ?? false) === true
        && ($r1['json']['data']['replayed'] ?? null) === false && $c1 === 1,
        sprintf('1re soumission : HTTP %d, success %s, replayed %s, annonces %d (attendu 200/true/false/1)',
            $r1['status'], var_export($r1['json']['success'] ?? null, true), var_export($r1['json']['data']['replayed'] ?? null, true), $c1));

    // 2) Re-soumission IDENTIQUE → même réponse (hors replayed), replayed:true, zéro doublon.
    $r2 = $postForm($payloadOf($run . '-alpha'), $k1);
    $c2 = $countListings($run . '-alpha');
    $same = ($r1['json']['data'] ?? []) != [] && (
        ($r1['json']['data']['url'] ?? '') === ($r2['json']['data']['url'] ?? 'x')
        && ($r1['json']['data']['verification_code'] ?? '') === ($r2['json']['data']['verification_code'] ?? 'x')
    );
    $assert('E34-002', $r2['status'] === 200 && ($r2['json']['data']['replayed'] ?? null) === true && $same && $c2 === 1,
        sprintf('rejeu identique : HTTP %d, replayed %s, réponse identique %s, annonces toujours %d (attendu 200/true/true/1)',
            $r2['status'], var_export($r2['json']['data']['replayed'] ?? null, true), $same ? 'oui' : 'NON', $c2));

    // 3) Charge distincte (clé distincte) → nouvelle annonce.
    $r3 = $postForm($payloadOf($run . '-beta'), $k2);
    $c3 = $countListings($run . '-beta');
    $assert('E34-003', $r3['status'] === 200 && ($r3['json']['data']['replayed'] ?? null) === false && $c3 === 1,
        sprintf('charge distincte : HTTP %d, replayed %s, nouvelle annonce %d (attendu 200/false/1 — pas de fusion abusive)',
            $r3['status'], var_export($r3['json']['data']['replayed'] ?? null, true), $c3));

    // 4) Clé expirée → la ligne ne rejoue plus, création normale.
    $expiredPayload = $payloadOf($run . '-gamma');
    $expiredHash = hash('sha256', wp_json_encode(['pk_city' => $expiredPayload['pk_city'], 'pk_title' => $expiredPayload['pk_title']]));
    $wpdb->delete($idemTable, ['event_id' => 'pkform:' . $k3], ['%s']);
    $wpdb->insert($idemTable, [
        'event_id' => 'pkform:' . $k3, 'event_hash' => $expiredHash, 'response_json' => wp_json_encode(['url' => 'expired']),
        'created_at' => gmdate('Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS), 'expires_at' => gmdate('Y-m-d H:i:s', time() - DAY_IN_SECONDS),
    ], ['%s', '%s', '%s', '%s', '%s']);
    $r4 = $postForm($expiredPayload, $k3);
    $c4 = $countListings($run . '-gamma');
    $assert('E34-004', $r4['status'] === 200 && ($r4['json']['data']['replayed'] ?? null) === false && $c4 === 1,
        sprintf('clé expirée : HTTP %d, replayed %s, annonce créée %d (attendu 200/false/1 — la ligne expirée ne rejoue plus)',
            $r4['status'], var_export($r4['json']['data']['replayed'] ?? null, true), $c4));

    // 5) Même clé + charge différente → refus 400, zéro annonce.
    $before5 = $countListings($run . '-delta');
    $r5 = $postForm($payloadOf($run . '-delta'), $k1);
    $after5 = $countListings($run . '-delta');
    $assert('E34-005', $r5['status'] === 400 && ($r5['json']['success'] ?? true) === false && $after5 === $before5,
        sprintf('clé réutilisée avec charge différente : HTTP %d, success %s, annonces %d→%d (attendu 400/false/inchangé)',
            $r5['status'], var_export($r5['json']['success'] ?? null, true), $before5, $after5));

    // 6) Sémantique REST du canal authentifié (E-3404 — mesurée).
    $get = rest_do_request(new WP_REST_Request('GET', '/partikulier/v1/listings'));
    wp_set_current_user(0);
    $postReq = new WP_REST_Request('POST', '/partikulier/v1/listings');
    $postReq->set_header('Content-Type', 'application/json');
    $postReq->set_body(wp_json_encode(['title' => 'REST complet', 'description' => 'Description suffisamment longue pour la mesure', 'price' => 100, 'area' => 50]));
    $postFull = rest_do_request($postReq);
    wp_set_current_user(1);
    $badReq = new WP_REST_Request('POST', '/partikulier/v1/listings');
    $badReq->set_header('Content-Type', 'application/json');
    $badReq->set_body(wp_json_encode(['title' => 'Titre seul']));
    $postBad = rest_do_request($badReq);
    $assert('E34-006', $get->get_status() === 200 && $postFull->get_status() === 401 && $postBad->get_status() === 400,
        sprintf('sémantique REST : GET %d (200 attendu) · POST complet sans auth %d (401) · POST incomplet authentifié %d (400)',
            $get->get_status(), $postFull->get_status(), $postBad->get_status()));
} catch (Throwable $error) {
    $assert('E34-EXCEPTION', false, $error->getMessage());
} finally {
    /* Sortie propre : annonces, lignes pkform:*, ville, réglage WhatsApp, quota. */
    foreach ([$run . '-alpha', $run . '-beta', $run . '-gamma', $run . '-delta'] as $marker) {
        foreach ($wpdb->get_results($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'properties' AND post_title LIKE %s", '%SE034 ' . $marker . '%')) as $p) {
            wp_delete_post((int) $p->ID, true);
        }
    }
    $wpdb->query($wpdb->prepare("DELETE FROM {$idemTable} WHERE event_id LIKE %s", 'pkform:%'));
    wp_set_current_user(1);
    $term = get_term_by('slug', $citySlug, 'es_location');
    if ($term) { wp_delete_term((int) $term->term_id, 'es_location'); }
    update_option('pk_theme_options', $optsBackup);
    delete_transient($rateKey);
    $left = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$idemTable} WHERE event_id LIKE %s", 'pkform:%'));
    if ($left > 0) {
        $assert('E34-EXCEPTION', false, "sortie propre : $left ligne(s) pkform: résiduelle(s)");
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se034-form-idempotency-contract (idempotence du canal public de dépôt, E-3401→E-3404)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php plugin/partikulier-core/tests/se034-form-idempotency-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
