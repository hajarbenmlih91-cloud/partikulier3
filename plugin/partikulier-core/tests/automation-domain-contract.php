<?php
/**
 * Contrat du domaine automatisation n8n (lot B4, CDC v1.2 — CA-2).
 *
 * Couvre le dispositif d'automatisation, désormais propriété du plugin
 * (deux tables : pk_automation_events, pk_n8n_hmac_audit) : réception
 * idempotente des accusés d'événements normalisés (payload haché, jamais
 * persisté), gardes de validation (codes hérités du thème), secret
 * partagé + promotion « secret présent + mode off = enforce » (LOT 2 —
 * Isolation), signature HMAC horodatée (fenêtre 300 s), mode log (échecs
 * journalisés, requête acceptée), plafond d'échecs par heure, en-têtes de
 * webhook sortant signés — et la couture du thème 6.18.5 : l'appel via
 * Partikulier_Automation_Bridge::receive_event doit exécuter le service
 * du plugin, preuve par les lignes d'audit (seul le plugin écrit le
 * registre d'audit — le chemin autonome du thème n'y écrit jamais).
 *
 * Les secrets lus ne sont JAMAIS imprimés (assertions par comparaison).
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/automation-domain-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Database\Migrator;
use Partikulier\Core\Domain\Automation\AutomationService;
use Partikulier\Core\HealthCheck;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
$run = bin2hex(random_bytes(4));
wp_set_current_user(1); // administrateur du banc (manage_options garanti par le harnais)

/* --- État initial : mode HMAC du banc sauvegardé (restauré en finally) --- */
$settingsBackup = AutomationService::settings();
$hmacModeBackup = (string) ($settingsBackup['hmac_mode'] ?? 'off');
$eventIds = [];
$hmacKeyIds = [];

/** Construit une requête REST POST /automation-event signée (ou non). */
$makeRequest = static function (array $params, array $headers = []): WP_REST_Request {
    $request = new WP_REST_Request('POST', '/partikulier/v1/automation-event');
    foreach ($params as $key => $value) {
        $request->set_param($key, $value);
    }
    foreach ($headers as $name => $value) {
        $request->set_header($name, (string) $value);
    }
    return $request;
};

try {
    // 1) Couture : service du plugin chargé, classe thème présente, tables uniques.
    $seamOk = class_exists(AutomationService::class)
        && class_exists('Partikulier_Automation_Bridge')
        && AutomationService::events_table() === $wpdb->prefix . 'pk_automation_events'
        && AutomationService::audit_table() === $wpdb->prefix . 'pk_n8n_hmac_audit'
        && Partikulier_Automation_Bridge::events_table() === AutomationService::events_table();
    $assert('B4A-001', $seamOk, 'couture thème/plugin : classes chargées, tables uniques ' . AutomationService::events_table() . ' / ' . AutomationService::audit_table());

    // 2) Adoption journalisée : les deux tables passées au plugin (manifeste
    //    2.4.0) et l'audit domain_adopted B4/automation existe exactement
    //    une fois ; les 2 événements réels du T0 sont toujours en place.
    $adoptedB4 = 0;
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT metadata_json FROM {$prefix}pk_audit_log WHERE action = %s", 'domain_adopted')) as $row) {
        $meta = json_decode((string) $row->metadata_json, true);
        if (($meta['lot'] ?? '') === 'B4' && ($meta['domain'] ?? '') === 'automation') { $adoptedB4++; }
    }
    $tables = ['pk_automation_events', 'pk_n8n_hmac_audit'];
    $allExist = true;
    foreach ($tables as $t) {
        $name = $prefix . $t;
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) { $allExist = false; }
    }
    $eventsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_automation_events");
    $assert('B4A-002', $allExist && $adoptedB4 === 1 && $eventsBefore >= 2,
        "adoption B4 journalisée (domain_adopted B4/automation = {$adoptedB4}), 2 tables en place, {$eventsBefore} événements T0 préservés");

    // 3) Réception nominale : accusé reçu, payload haché (jamais persisté),
    //    statut received, audit automation_event_received côté plugin.
    $eventId = 'n8n-b4a-' . $run . '-1';
    $eventIds[] = $eventId;
    $payload = ['phone' => '212600000099', 'status' => 'delivered'];
    $received = AutomationService::receive_event($makeRequest([
        'event_id' => $eventId,
        'event_type' => 'whatsapp_status',
        'source' => 'n8n',
        'payload' => $payload,
    ], ['X-Partikulier-Automation' => 'non pertinent ici (garde court-circuitée par l\'appel direct)']));
    $receivedData = $received instanceof WP_REST_Response ? (array) $received->get_data() : [];
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}pk_automation_events WHERE event_id = %s", $eventId), ARRAY_A);
    $auditReceived = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND metadata_json LIKE %s",
        'automation_event_received',
        '%' . $eventId . '%'
    ));
    $payloadLeak = is_array($row) && (false !== strpos((string) $row['payload_hash'], '212600000099'));
    $assert('B4A-003', $received instanceof WP_REST_Response && !empty($receivedData['accepted'])
        && ($receivedData['duplicate'] ?? null) === false && ($receivedData['processing'] ?? '') === 'disabled'
        && is_array($row) && $row['event_type'] === 'whatsapp_status' && $row['source'] === 'n8n'
        && $row['status'] === 'received' && strlen((string) $row['payload_hash']) === 64 && !$payloadLeak
        && $auditReceived === 1,
        'receive_event : ligne conforme (statut received, hash sha256 64, payload jamais en clair) + audit automation_event_received');

    // 4) Idempotence : même event_id → duplicate=true (200), pas de seconde
    //    ligne, audit automation_event_duplicate.
    $duplicate = AutomationService::receive_event($makeRequest([
        'event_id' => $eventId,
        'event_type' => 'whatsapp_status',
        'source' => 'n8n',
        'payload' => $payload,
    ]));
    $duplicateData = $duplicate instanceof WP_REST_Response ? (array) $duplicate->get_data() : [];
    $rowsSameId = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_automation_events WHERE event_id = %s", $eventId));
    $auditDuplicate = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND metadata_json LIKE %s",
        'automation_event_duplicate',
        '%' . $eventId . '%'
    ));
    $assert('B4A-004', $duplicate instanceof WP_REST_Response && !empty($duplicateData['duplicate'])
        && $rowsSameId === 1 && $auditDuplicate === 1,
        'idempotence : rejeu du même event_id → duplicate=true, une seule ligne, audit automation_event_duplicate');

    // 5) Gardes de payload (codes hérités du thème) : préfixe d'event_id
    //    incompatible, type inconnu, source inconnue → pk_automation_payload 400.
    $g1 = AutomationService::receive_event($makeRequest(['event_id' => 'pay-' . $run, 'event_type' => 'whatsapp_status', 'source' => 'n8n']));
    $g2 = AutomationService::receive_event($makeRequest(['event_id' => 'n8n-b4a-' . $run . '-x', 'event_type' => 'unknown_type', 'source' => 'n8n']));
    $g3 = AutomationService::receive_event($makeRequest(['event_id' => 'n8n-b4a-' . $run . '-y', 'event_type' => 'whatsapp_status', 'source' => 'unknown_source']));
    $g4 = AutomationService::receive_event($makeRequest(['event_id' => '', 'event_type' => 'whatsapp_status', 'source' => 'n8n']));
    $assert('B4A-005', is_wp_error($g1) && is_wp_error($g2) && is_wp_error($g3) && is_wp_error($g4)
        && $g1->get_error_code() === 'pk_automation_payload' && $g2->get_error_code() === 'pk_automation_payload'
        && $g3->get_error_code() === 'pk_automation_payload' && $g4->get_error_code() === 'pk_automation_payload',
        'gardes : préfixe, type, source et event_id vide → pk_automation_payload');

    // 6) Authentification : sans secret fourni → pk_automation_auth 401.
    $noSecret = AutomationService::check_automation_secret($makeRequest(['event_id' => 'n8n-b4a-' . $run . '-2']));
    $assert('B4A-006', is_wp_error($noSecret) && $noSecret->get_error_code() === 'pk_automation_auth',
        'sans en-tête partagé → pk_automation_auth (401)');

    // 7) LOT 2 — Isolation : secret présent + mode off = enforce : le secret
    //    seul (sans signature) est refusé → pk_automation_signature.
    $secret = (string) AutomationService::get('automation_api_secret');
    $assert('B4A-007-pre', $secret !== '', 'fixture : secret partagé présent sur le banc (valeur non imprimée)');
    if ($secret === '') {
        $assert('B4A-007', false, 'secret absent du banc : test impossible (configurer pk_n8n_settings)');
    } else {
        $secretAlone = AutomationService::check_automation_secret($makeRequest(
            ['event_id' => 'n8n-b4a-' . $run . '-3'],
            ['X-Partikulier-Automation' => $secret]
        ));
        $assert('B4A-007', is_wp_error($secretAlone) && $secretAlone->get_error_code() === 'pk_automation_signature',
            'secret présent + mode off → promotion enforce (LOT 2) : secret sans signature refusé');
    }

    // 8) Signature HMAC valide : acceptée (mode promote enforce par défaut).
    if ($secret !== '') {
        $keys = AutomationService::secret_keys();
        $keyId = (string) array_key_first($keys);
        $ts = (string) time();
        $body = wp_json_encode(['event_id' => 'n8n-b4a-' . $run . '-4', 'probe' => 'signature']);
        $canonical = "POST\n/partikulier/v1/automation-event\n" . $ts . "\n" . $body;
        $signature = 'sha256=' . hash_hmac('sha256', $canonical, AutomationProbe::hmac_key((string) $keys[$keyId]));
        $signedRequest = $makeRequest(
            ['event_id' => 'n8n-b4a-' . $run . '-4'],
            [
                'X-Partikulier-Automation' => $secret,
                'X-Partikulier-Timestamp' => $ts,
                'X-Partikulier-Key-Id' => $keyId,
                'X-Partikulier-Signature' => $signature,
            ]
        );
        $signedRequest->set_body($body);
        $signed = AutomationService::check_automation_secret($signedRequest);
        $assert('B4A-008', $signed === true,
            'signature HMAC valide (POST + route + horodatage + corps) → requête acceptée');

        // 8b) Requête altérée : corps modifié après signature → refus.
        $tamperedRequest = $makeRequest(
            ['event_id' => 'n8n-b4a-' . $run . '-5'],
            [
                'X-Partikulier-Automation' => $secret,
                'X-Partikulier-Timestamp' => $ts,
                'X-Partikulier-Key-Id' => $keyId,
                'X-Partikulier-Signature' => $signature,
            ]
        );
        $tamperedRequest->set_body($body . ' altéré');
        $tampered = AutomationService::check_automation_secret($tamperedRequest);
        $assert('B4A-009', is_wp_error($tampered) && $tampered->get_error_code() === 'pk_automation_signature',
            'corps altéré après signature → refus (la signature ne correspond plus au corps envoyé)');

        // 8c) Horodatage périmé (> 300 s) → refus.
        $stale = (string) (time() - 400);
        $staleCanonical = "POST\n/partikulier/v1/automation-event\n" . $stale . "\n" . $body;
        $staleSignature = 'sha256=' . hash_hmac('sha256', $staleCanonical, AutomationProbe::hmac_key((string) $keys[$keyId]));
        $expiredRequest = $makeRequest(
            ['event_id' => 'n8n-b4a-' . $run . '-6'],
            [
                'X-Partikulier-Automation' => $secret,
                'X-Partikulier-Timestamp' => $stale,
                'X-Partikulier-Key-Id' => $keyId,
                'X-Partikulier-Signature' => $staleSignature,
            ]
        );
        $expiredRequest->set_body($body);
        $expired = AutomationService::check_automation_secret($expiredRequest);
        $assert('B4A-010', is_wp_error($expired) && $expired->get_error_code() === 'pk_automation_signature',
            'horodatage périmé (−400 s, fenêtre 300 s) → refus');

        // 9) Mode log : mauvaise signature sur une clé CONNUE → requête
        //    acceptée + échec journalisé dans pk_n8n_hmac_audit (upsert par
        //    clé/heure). NB : une clé inconnue saute la vérification (comportement
        //    hérité du thème, porté fidèlement) — la sonde utilise donc la clé
        //    active réelle. Mode restauré en finally.
        $settings = AutomationService::settings();
        $settings['hmac_mode'] = 'log';
        update_option(AutomationService::SETTINGS_OPTION, $settings, false);
        $knownKeyId = 'N';
        $hmacKeyIds[] = $knownKeyId;
        $logModeRequest = $makeRequest(
            ['event_id' => 'n8n-b4a-' . $run . '-7'],
            [
                'X-Partikulier-Automation' => $secret,
                'X-Partikulier-Timestamp' => (string) time(),
                'X-Partikulier-Key-Id' => $knownKeyId,
                'X-Partikulier-Signature' => 'sha256=' . str_repeat('a', 64),
            ]
        );
        $logModeRequest->set_body($body);
        $badSig = AutomationService::check_automation_secret($logModeRequest);
        $auditRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}pk_n8n_hmac_audit WHERE key_id = %s ORDER BY id DESC LIMIT 1", $knownKeyId), ARRAY_A);
        $assert('B4A-011', $badSig === true && is_array($auditRow)
            && (string) $auditRow['last_reason'] === 'invalid_signature' && (int) $auditRow['failure_count'] === 1,
            'mode log : mauvaise signature (clé connue) acceptée, échec journalisé (key_id/heure, raison invalid_signature)');

        // 10) Plafond d'échecs par heure : LEAST(failure_count+1, 100) — sonde
        //    autonome sur une clé dédiée (trois échecs consécutifs).
        $auditKeyId = 'b4aprobe' . $run;
        $hmacKeyIds[] = $auditKeyId;
        AutomationService::audit_failure($auditKeyId, 'invalid_signature');
        AutomationService::audit_failure($auditKeyId, 'second_reason');
        AutomationService::audit_failure($auditKeyId, 'third_reason');
        $auditRow2 = $wpdb->get_row($wpdb->prepare("SELECT failure_count, last_reason FROM {$prefix}pk_n8n_hmac_audit WHERE key_id = %s", $auditKeyId), ARRAY_A);
        $assert('B4A-012', is_array($auditRow2) && (int) $auditRow2['failure_count'] === 3 && (string) $auditRow2['last_reason'] === 'third_reason',
            'audit_failure : compteur par heure incrémenté (1→3), dernière raison actualisée (upsert key_hour)');

        // 11) En-têtes sortants signés : structure complète et signature
        //     vérifiable par le même canonique.
        $outgoing = AutomationService::outgoing_headers('POST', 'https://n8n.example.test/hook/abc?x=1', $body);
        $outgoingOk = false;
        $outgoingDetail = 'outgoing_headers a échoué';
        if (is_array($outgoing)) {
            $expectedCanonical = "POST\n/hook/abc?x=1\n" . $outgoing['X-Partikulier-Timestamp'] . "\n" . $body;
            $expectedSig = 'sha256=' . hash_hmac('sha256', $expectedCanonical, AutomationProbe::hmac_key((string) $keys[$keyId]));
            $outgoingOk = count($outgoing) === 5
                && ($outgoing['Content-Type'] ?? '') === 'application/json'
                && ($outgoing['X-Partikulier-Key-Id'] ?? '') === $keyId
                && hash_equals($expectedSig, (string) ($outgoing['X-Partikulier-Signature'] ?? ''));
            $outgoingDetail = '5 en-têtes, clé ' . $keyId . ', signature re-calculable (chemin + requête entrant dans le canonique)';
        }
        $assert('B4A-013', $outgoingOk, $outgoingDetail);

        // 12) Secret faible refusé à l'enregistrement (garde héritée) —
        //     aucun effet de bord : l'option n'est pas modifiée.
        $before = AutomationService::settings();
        $weak = AutomationService::save_admin_settings(['automation_api_secret' => 'AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA']);
        $after = AutomationService::settings();
        $assert('B4A-014', is_wp_error($weak) && $weak->get_error_code() === 'pk_n8n_secret_weak'
            && ($after['automation_api_secret'] ?? null) === ($before['automation_api_secret'] ?? null),
            'save_admin_settings : secret mono-caractère répété → pk_n8n_secret_weak, réglages inchangés');
    }

    // 13) Couture du thème : l'appel via Partikulier_Automation_Bridge
    //     exécute le service du plugin (preuve : audit écrit, ligne créée).
    //     Source payment_provider → préfixe d'event_id « pay- » (garde héritée).
    $seamEventId = 'pay-b4a-' . $run . '-seam';
    $eventIds[] = $seamEventId;
    $seamReceived = Partikulier_Automation_Bridge::receive_event($makeRequest([
        'event_id' => $seamEventId,
        'event_type' => 'payment_status',
        'source' => 'payment_provider',
        'payload' => ['order' => 'REF-1', 'state' => 'paid'],
    ]));
    $seamAudit = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND metadata_json LIKE %s",
        'automation_event_received',
        '%' . $seamEventId . '%'
    ));
    $seamData = $seamReceived instanceof WP_REST_Response ? (array) $seamReceived->get_data() : [];
    $assert('B4A-015', $seamReceived instanceof WP_REST_Response && !empty($seamData['accepted']) && $seamAudit === 1,
        'couture thème → service plugin : receive_event via Partikulier_Automation_Bridge exécute AutomationService (audit écrit)');

    // 14) Health : domaine automation plugin-owned, 2/2 tables suivies,
    //     0 collision ; route /automation-event inscrite par le plugin
    //     (registre INTEG-3 — le thème 6.18.5 ne la redéclare plus).
    $health = (new HealthCheck())->get();
    $automationTables = $health['domains']['automation']['tables'] ?? [];
    $routes = rest_get_server()->get_routes();
    $routeRegistered = isset($routes['/partikulier/v1/automation-event']);
    $domainsOk = ($health['domains']['automation']['owner'] ?? '') === 'plugin'
        && count($automationTables) === 2 && !in_array(false, $automationTables, true)
        && (int) ($health['routes']['collisions'] ?? -1) === 0 && $routeRegistered;
    $assert('B4A-016', $domainsOk,
        'health check : domaine automation owner=plugin, 2/2 tables suivies, 0 collision, route /automation-event inscrite');
} catch (Throwable $error) {
    $assert('B4A-EXCEPTION', false, $error->getMessage());
} finally {
    // Nettoyage : événements de la fixture + audits correspondants + lignes
    // d'audit HMAC de la sonde ; mode HMAC restauré à son état d'origine.
    foreach ($eventIds as $eid) {
        $wpdb->delete($prefix . 'pk_automation_events', ['event_id' => $eid], ['%s']);
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$prefix}pk_audit_log WHERE action IN ('automation_event_received','automation_event_duplicate') AND metadata_json LIKE %s",
            '%' . $eid . '%'
        ));
    }
    foreach ($hmacKeyIds as $kid) {
        $wpdb->delete($prefix . 'pk_n8n_hmac_audit', ['key_id' => $kid], ['%s']);
    }
    if (is_array($settingsBackup)) {
        $settingsBackup['hmac_mode'] = $hmacModeBackup;
        update_option(AutomationService::SETTINGS_OPTION, $settingsBackup, false);
    }
}

/**
 * Sonde locale : reproduit la dérivation de clé HMAC du port (base64 ≥ 32
 * octets, puis hexadécimal ≥ 64, sinon brut) SANS exposer la méthode
 * privée du service.
 */
final class AutomationProbe
{
    public static function hmac_key(string $secret): string
    {
        $secret = trim($secret);
        $decoded = base64_decode($secret, true);
        if (is_string($decoded) && strlen($decoded) >= 32) {
            return $decoded;
        }
        if (preg_match('/^[a-f0-9]{64,}$/i', $secret)) {
            $hex = hex2bin(substr($secret, 0, strlen($secret) - (strlen($secret) % 2)));
            if (false !== $hex && strlen($hex) >= 32) {
                return $hex;
            }
        }
        return $secret;
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'automation-domain-contract (lot B4)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/automation-domain-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
