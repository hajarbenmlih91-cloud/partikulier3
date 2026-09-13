<?php
/**
 * Batterie SE-022 E-2202 — rejeu du verrou rate-limit en HTTP RÉEL sur un
 * serveur démarré (campagne post-audit 2026-09, CDC v4.1 §8B, lot 6 train 2).
 *
 * Le défaut E-1609 n'est observable que sur un cycle HTTP complet : le core
 * accroche rest_send_allow_header() sur rest_post_dispatch et RÉ-EXÉCUTE le
 * permission_callback pour l'en-tête Allow — rest_do_request() (CLI) ne
 * déclenche jamais cette phase et reste aveugle à la classe de défaut (le
 * double comptage du train 1 : 429 à la 6e requête, audit ×2). La présente
 * batterie prouve le comportement post-fix sur serveur réel (php -S,
 * infrastructure de la famille front-assets SE-018) :
 *
 *   SE22H-001 : 10 requêtes servies → 401 chacune (comptées +1 par requête)
 *   SE22H-002 : la 11e requête → 429 pk_erase_rate_limited (formulation
 *               E-1610 amendée : N requêtes servies, N+1 refusée)
 *   SE22H-003 : compteur d'échecs == 10 après la rafale (pas 20 — la phase
 *               rest_post_dispatch n'a rien doublé)
 *   SE22H-004 : audit lead_erase_auth_failed unique par échec (delta 10)
 *               + audit lead_erase_flood unique (delta 1)
 *
 * L'URL utilise ?rest_route= (insensible aux permaliens). Préparation et
 * vérification par wp-load (CLI) autour de la rafale HTTP ; l'état du banc
 * est restauré en sortie (secret, transition, transitoire du compteur,
 * audits de la fenêtre).
 *
 * Rejouable : PK_BASE=http://127.0.0.1:8101 PK_WP_DIR=<wp> PK_COMMIT=<sha> \
 *   php plugin/partikulier-core/tests/se022-http-battery.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$base = getenv('PK_BASE') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if ($base === '' || !preg_match('#^https?://#', $base)) { fwrite(STDERR, "PK_BASE doit pointer vers le serveur démarré\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
$run = bin2hex(random_bytes(4));
$auditTable = $prefix . 'pk_audit_log';
$endpoint = rtrim($base, '/') . '/?rest_route=/partikulier/v1/erase-lead';
/* IP du banc HTTP : le serveur intégré voit 127.0.0.1 — même clé que la garde. */
$transientKey = 'pk_erase_fail_' . md5('127.0.0.1');

$call = static function (array $headers) use ($endpoint): array {
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => wp_json_encode(['wa_id' => '999999999999']),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers),
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['status' => $code, 'body' => is_string($response) ? (string) $response : '', 'error' => $error];
};

$auditCount = static function (string $action) use ($wpdb, $auditTable): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$auditTable} WHERE action = %s", $action));
};

/* --- Préparation : secret dédié unique au run, transition OFF, compteur vide --- */
$secret = 'pk-se022-http-' . $run . '-a1b2c3d4e5f6';
update_option('lead_erase_api_secret', $secret, false);
delete_option('lead_erase_transition_active');
delete_transient($transientKey);
$authBefore = $auditCount('lead_erase_auth_failed');
$floodBefore = $auditCount('lead_erase_flood');

try {
    /* Rafale HTTP : 10 requêtes servies (secret invalide), puis la 11e (sans secret). */
    $statuses = [];
    for ($i = 1; $i <= 10; $i++) {
        $r = $call(['X-Partikulier-Lead-Erase: se022-http-essai-' . $i]);
        $statuses[] = $r['status'];
        if ($r['error'] !== '') { break; }
    }
    $r11 = $call([]);

    $assert('SE22H-001',
        count($statuses) === 10 && !in_array(0, $statuses, true) && array_sum($statuses) === 10 * 401,
        sprintf('10 requêtes servies 401 (reçues : %s)', implode(',', $statuses ?: ['aucune'])));
    $assert('SE22H-002',
        $r11['status'] === 429 && strpos($r11['body'], 'pk_erase_rate_limited') !== false,
        sprintf('11e requête → HTTP %d (corps %s)', $r11['status'],
            $r11['status'] === 429 ? 'pk_erase_rate_limited' : substr($r11['body'], 0, 120)));

    /* Vérification wp-load : compteur et audits (la phase rest_post_dispatch
     * ne doit rien avoir doublé — pré-fix, le compteur valait 20 et le 429
     * tombait à la 6e requête). */
    $raw = $wpdb->get_var($wpdb->prepare(
        "SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
        '_transient_' . $transientKey
    ));
    $state = is_string($raw) ? maybe_unserialize($raw) : null;
    $counter = is_array($state) && isset($state['count']) ? (int) $state['count'] : 0;
    $assert('SE22H-003', $counter === 10,
        sprintf('compteur d\'échecs = %d après 10 requêtes HTTP (attendu 10, pas 20 — rest_post_dispatch sans double comptage)', $counter));
    $authDelta = $auditCount('lead_erase_auth_failed') - $authBefore;
    $floodDelta = $auditCount('lead_erase_flood') - $floodBefore;
    $assert('SE22H-004', $authDelta === 10 && $floodDelta === 1,
        sprintf('audit lead_erase_auth_failed delta %d (unique par échec), lead_erase_flood delta %d', $authDelta, $floodDelta));
} catch (Throwable $error) {
    $assert('SE22H-EXCEPTION', false, $error->getMessage());
} finally {
    /* --- Nettoyage : état du banc restauré (audits de la fenêtre compris) --- */
    delete_transient($transientKey);
    delete_option('lead_erase_transition_active');
    delete_option('lead_erase_api_secret');
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$auditTable} WHERE action IN ('lead_erase_auth_failed', 'lead_erase_flood') AND created_at >= %s AND metadata_json LIKE %s",
        gmdate('Y-m-d H:i:s', strtotime($started) - 60),
        '%"ip":"127.0.0.1"%'
    ));
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se022-http-battery (SE-022 E-2202 — HTTP réel sur serveur démarré, lot 6 train 2)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'endpoint' => $endpoint,
    'command' => 'php partikulier-core/tests/se022-http-battery.php (serveur php -S, PK_BASE)',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
