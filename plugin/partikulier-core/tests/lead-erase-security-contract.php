<?php
/**
 * Contrat de sécurité SE-016 — garde de la route /erase-lead (campagne
 * post-audit 2026-09, P0-1 de l'audit indépendant du 12/09/2026).
 *
 * Verrou du correctif : la route d'effacement exige la preuve de possession
 * du secret dédié lead_erase_api_secret (header X-Partikulier-Lead-Erase ou
 * Bearer), avec fenêtre de transition E-1603 acceptant le secret n8n (usage
 * déprécié journalisé), limiteur d'échecs anti-forçage E-1604 (10/heure/IP,
 * 429 au-delà, relèvement à 100/heure tant que lead_erase_transition_active
 * est ON — chaque assouplissement journalisé), sémantique HTTP E-1605
 * préservée (401/400/200 idempotent) et journal d'audit E-1606 de chaque
 * tentative. Le défaut revenu (route sans garde) ferait échouer (1) et (2)
 * immédiatement ; le forçage non borné ferait échouer (3)/(8).
 *
 * Huit assertions (CDC §4.4) : sans header → 401 ; secret invalide → 401 ;
 * 10 échecs consécutifs → 429 ; secret valide + wa_id existant → 200 + lead
 * effacé + audit ; secret valide + wa_id inconnu → 200 idempotent ; wa_id
 * absent → 400 ; secret de transition accepté + dépréciation journalisée ;
 * garde-fou de transition (rafale exemptée sans 429, échecs journalisés,
 * transition close → seuil nominal effectif).
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/lead-erase-security-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\Automation\AutomationService;
use Partikulier\Core\Domain\Leads\LeadService;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
$run = bin2hex(random_bytes(4));
$leadsTable = $prefix . 'pk_buyer_leads';
$auditTable = $prefix . 'pk_audit_log';

/* --- Fixture : lead victime au téléphone numérique unique au run --- */
$secret = 'pk-erase-secret-' . $run . '-a1b2c3d4e5f6g7h8';
$waId = '336' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
$hash = hash_hmac('sha256', $waId, wp_salt('auth'));
$wpdb->insert($leadsTable, [
    'phone_hash' => $hash,
    'phone_encrypted' => base64_encode($waId),
    'first_seen_at' => gmdate('Y-m-d H:i:s'),
    'last_seen_at' => gmdate('Y-m-d H:i:s'),
], ['%s', '%s', '%s', '%s']);
$victimId = (int) $wpdb->insert_id;

/* --- E-5501/E-5502/E-5503 (lot sécurité 2.10.7, F-T16-4) : complétude de la
   cascade d'effacement. Lead dédié au run, doté d'une ligne dans CHACUNE des
   tables portant lead_id — y compris pk_saved_alerts, la table que la cascade
   publiée 2.10.6 oubliait. L'effacement passe par le même chemin de service
   que la route /erase-lead (LeadService utilise LeadsPrivacyTrait). --- */
$e55Phone = '337' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
$wpdb->insert($leadsTable, [
    'phone_hash' => hash_hmac('sha256', $e55Phone, wp_salt('auth')),
    'phone_encrypted' => base64_encode($e55Phone),
    'first_seen_at' => gmdate('Y-m-d H:i:s'),
    'last_seen_at' => gmdate('Y-m-d H:i:s'),
], ['%s', '%s', '%s', '%s']);
$e55LeadId = (int) $wpdb->insert_id;
$e55Now = gmdate('Y-m-d H:i:s');
$e55Day = gmdate('Y-m-d');
$e55Fixtures = [
    'pk_interest_events' => ['lead_id' => $e55LeadId, 'property_id' => 1, 'reference_code' => 'E55-' . $run,
        'property_snapshot' => '{}', 'provider_message_id' => 'e55-' . $run, 'created_at' => $e55Now],
    'pk_contact_limits' => ['lead_id' => $e55LeadId, 'day_key' => $e55Day],
    'pk_contact_disclosures' => ['lead_id' => $e55LeadId, 'property_id' => 1, 'owner_id' => 1,
        'day_key' => $e55Day, 'sent_at' => $e55Now],
    'pk_buyer_preferences' => ['lead_id' => $e55LeadId, 'areas' => '[]', 'layout_value' => 'apartment',
        'transaction_value' => 'buy', 'source' => 'e55', 'updated_at' => $e55Now],
    'pk_whatsapp_consents' => ['lead_id' => $e55LeadId, 'scope' => 'contact', 'policy_version' => 'e55',
        'proof_message_id' => 'e55-' . $run],
    'pk_whatsapp_messages' => ['provider_message_id' => 'e55-' . $run, 'lead_id' => $e55LeadId,
        'direction' => 'in', 'message_type' => 'text', 'created_at' => $e55Now],
    'pk_lead_followups' => ['lead_id' => $e55LeadId, 'updated_at' => $e55Now],
    'pk_saved_alerts' => ['lead_id' => $e55LeadId, 'criteria' => '{"city":"e55"}',
        'criteria_signature' => hash('sha256', 'e55-' . $run), 'consent_message_id' => 'e55-' . $run,
        'created_at' => $e55Now, 'updated_at' => $e55Now],
];
foreach ($e55Fixtures as $suffix => $row) {
    $wpdb->insert($prefix . $suffix, $row);
}
/* Livraisons de l'alerte (pk_alert_deliveries, clé alert_id sans lead_id) :
   condition R6 du vérificateur croisé — purgées avec l'alerte. */
$e55AlertId = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT id FROM {$prefix}pk_saved_alerts WHERE lead_id = %d LIMIT 1", $e55LeadId));
$wpdb->insert($prefix . 'pk_alert_deliveries',
    ['alert_id' => $e55AlertId, 'property_id' => 1, 'status' => 'candidate', 'created_at' => $e55Now],
    ['%d', '%d', '%s', '%s']);
$e55Erased = LeadService::erase_lead($e55LeadId);
$e55AlertsLeft = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$prefix}pk_saved_alerts WHERE lead_id = %d", $e55LeadId));
$assert('E55-001', $e55Erased === true && $e55AlertsLeft === 0,
    'E-5501 : l\'alerte sauvegardée suit l\'effacement du lead (0 ligne pk_saved_alerts restante — régression F-T16-4 fermée)');
$e55Residue = [];
foreach (array_keys($e55Fixtures) as $suffix) {
    $left = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}{$suffix} WHERE lead_id = %d", $e55LeadId));
    if ($left > 0) {
        $e55Residue[] = $suffix . '=' . $left;
    }
}
$e55LeadRowLeft = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$leadsTable} WHERE id = %d", $e55LeadId));
if ($e55LeadRowLeft > 0) {
    $e55Residue[] = 'pk_buyer_leads=' . $e55LeadRowLeft;
}
$assert('E55-002', $e55Residue === [],
    'E-5501 : zéro rémanence sur les 9 tables portant lead_id' . ($e55Residue ? ' (résidus : ' . implode(', ', $e55Residue) . ')' : ''));
/* Invariant E-5502 (structurel) : toute table pk_* du schéma portant une
   colonne lead_id figure dans la liste codée en dur de erase_lead() — le
   test échouera pour toute table domaine future non couverte. */
$e55TraitSource = (string) file_get_contents(WP_PLUGIN_DIR . '/partikulier-core/src/Domain/Leads/LeadsPrivacyTrait.php');
$e55CascadeList = [];
if (preg_match('/\$tables\s*=\s*\[(.*?)\];/s', $e55TraitSource, $e55Match)) {
    preg_match_all("/'(pk_[a-z_]+)'/", $e55Match[1], $e55ListMatch);
    $e55CascadeList = $e55ListMatch[1];
}
$e55SchemaTables = (array) $wpdb->get_col($wpdb->prepare(
    "SELECT TABLE_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = %s AND COLUMN_NAME = 'lead_id' AND TABLE_NAME LIKE %s",
    DB_NAME, $wpdb->prefix . 'pk\\_%'));
$e55Missing = array_values(array_diff(
    array_map(static fn(string $t): string => substr($t, strlen($wpdb->prefix)), $e55SchemaTables),
    $e55CascadeList
));
$assert('E55-003', $e55CascadeList !== [] && $e55Missing === [],
    'E-5502 : invariant de complétude — toute table pk_* à colonne lead_id est dans la cascade'
    . ($e55Missing ? ' (manquantes : ' . implode(', ', $e55Missing) . ')' : ' (' . count($e55SchemaTables) . ' tables couvertes)'));
$e55AuditRow = $wpdb->get_row($wpdb->prepare(
    "SELECT metadata_json FROM {$auditTable} WHERE action = 'lead_erased' AND object_id = %d ORDER BY id DESC LIMIT 1",
    $e55LeadId), ARRAY_A);
$e55AuditMeta = is_array($e55AuditRow) ? json_decode((string) $e55AuditRow['metadata_json'], true) : null;
/* NB : « tables = 9 » = les 9 tables portant lead_id (invariant E-5502).
 * La 10e table touchée par erase, pk_alert_deliveries, est purgée par
 * sous-requête alert_id et validée séparément par E55-005 ci-dessous. */
$assert('E55-004', is_array($e55AuditMeta) && (int) ($e55AuditMeta['tables'] ?? 0) === 9,
    'E-5501 : l\'audit lead_erased est écrit avec le nouveau compte de la cascade (tables = 9, tables lead_id)');
$e55DelivLeft = (int) $wpdb->get_var($wpdb->prepare(
    "SELECT COUNT(*) FROM {$prefix}pk_alert_deliveries WHERE alert_id = %d", $e55AlertId));
$assert('E55-005', $e55DelivLeft === 0,
    'E-5501 : les livraisons d\'alertes (pk_alert_deliveries, clé alert_id sans lead_id) suivent l\'effacement — 0 ligne pendante (condition R6 du vérificateur croisé)');
$automationSecret = (string) AutomationService::get('automation_api_secret');

$erase = static function (array $body = [], array $headers = []) use (&$results): array {
    $request = new WP_REST_Request('POST', '/partikulier/v1/erase-lead');
    $request->set_header('Content-Type', 'application/json');
    foreach ($headers as $name => $value) {
        $request->set_header($name, $value);
    }
    $request->set_body(wp_json_encode($body));
    $response = rest_do_request($request);
    $data = $response->get_data();
    /* Le serveur REST convertit l'erreur de permission_callback en tableau
     * {code, message, data} — l'in-process direct renvoie un WP_Error :
     * extraire le code des deux formes. */
    if (is_wp_error($data)) {
        $code = (string) $data->get_error_code();
    } elseif (is_array($data) && isset($data['code'])) {
        $code = (string) $data['code'];
    } else {
        $code = '';
    }
    return ['status' => $response->get_status(), 'body' => (array) $data, 'code' => $code];
};

$auditCount = static function (string $action) use ($wpdb, $auditTable): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$auditTable} WHERE action = %s", $action));
};

/* Compteur d'échecs : remise à zéro par la méthode publique de la garde
 * (même clef transitoire que le service — l'IP de la voie CLI résout en
 * « unknown », pas « cli »). */
$resetFailures = static function () use ($wpdb): void {
    LeadService::erase_reset_failures();
};

/* --- Préparation : secret dédié posé, transition OFF, compteur vide --- */
update_option('lead_erase_api_secret', $secret, false);
delete_option('lead_erase_transition_active');
$resetFailures();

try {
    /* (1) POST sans header → 401. */
    $r = $erase(['wa_id' => $waId]);
    $assert('SE16-001', $r['status'] === 401 && $r['code'] === 'pk_erase_auth',
        'sans secret : HTTP ' . $r['status'] . ' (pk_erase_auth attendu, obtenu ' . $r['code'] . ')');

    /* (2) Secret invalide → 401. */
    $r = $erase(['wa_id' => $waId], ['X-Partikulier-Lead-Erase' => 'mauvais-secret-de-test']);
    $assert('SE16-002', $r['status'] === 401 && $r['code'] === 'pk_erase_auth',
        'secret invalide : HTTP ' . $r['status'] . ' — la victime reste en base');

    /* (3) 10 échecs consécutifs → 429 (E-1604 : anti-forçage). */
    $floodBefore = $auditCount('lead_erase_flood');
    for ($i = 3; $i <= 10; $i++) {
        $r = $erase(['wa_id' => $waId], ['X-Partikulier-Lead-Erase' => 'essai-' . $i]);
        if ($r['status'] !== 401) { break; }
    }
    $r = $erase(['wa_id' => $waId]); // 11e tentative : compteur à 10 → refus de flux
    $assert('SE16-003', $r['status'] === 429 && $r['code'] === 'pk_erase_rate_limited'
        && $auditCount('lead_erase_flood') > $floodBefore,
        '10 échecs consécutifs → 429 pk_erase_rate_limited + audit lead_erase_flood (HTTP ' . $r['status'] . ', ' . $r['code'] . ')');

    /* Le succès remet le compteur à zéro : l'orchestrateur légitime n'est jamais pénalisé. */
    $resetFailures();

    /* (4) Secret valide + wa_id existant → 200 + lead effacé + entrées d'audit. */
    $erasedAuditBefore = $auditCount('lead_erased');
    $authorizedBefore = $auditCount('lead_erase_authorized');
    $r = $erase(['wa_id' => $waId], ['X-Partikulier-Lead-Erase' => $secret]);
    $stillThere = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$leadsTable} WHERE id = %d", $victimId));
    $assert('SE16-004', $r['status'] === 200 && ($r['body']['erased'] ?? false) === true && $stillThere === 0
        && $auditCount('lead_erased') > $erasedAuditBefore && $auditCount('lead_erase_authorized') > $authorizedBefore,
        'secret valide : HTTP 200, victime effacée (huit tables), audits lead_erase_authorized + lead_erased');

    /* (5) Secret valide + wa_id inconnu → 200 idempotent (RGPD conservé). */
    $leadsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$leadsTable}");
    $r = $erase(['wa_id' => '999999999999'], ['X-Partikulier-Lead-Erase' => $secret]);
    $leadsAfter = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$leadsTable}");
    $assert('SE16-005', $r['status'] === 200 && ($r['body']['erased'] ?? false) === true && $leadsBefore === $leadsAfter,
        'wa_id inconnu : HTTP 200 {erased:true} idempotent, aucune ligne touchée');

    /* (6) wa_id absent → 400. */
    $r = $erase([], ['X-Partikulier-Lead-Erase' => $secret]);
    $assert('SE16-006', $r['status'] === 400 && $r['code'] === 'pk_invalid_erase_request',
        'wa_id absent : HTTP 400 (obtenu ' . $r['status'] . ' / ' . $r['code'] . ')');

    /* (7) Secret de transition automation_api_secret accepté + dépréciation journalisée. */
    update_option('lead_erase_transition_active', 1, false);
    $deprecatedBefore = $auditCount('lead_erase_secret_deprecated');
    $r = $erase(['wa_id' => '999999999998'], ['X-Partikulier-Lead-Erase' => $automationSecret]);
    $assert('SE16-007', $r['status'] === 200 && $auditCount('lead_erase_secret_deprecated') > $deprecatedBefore,
        'fenêtre E-1603 : secret n8n accepté (HTTP ' . $r['status'] . ') + usage déprécié journalisé (lead_erase_secret_deprecated)');

    /* (8) Garde-fou de transition : rafale d'échecs sans 429 + assouplissement
     *     journalisé ; transition close → le seuil nominal (3) redevient effectif. */
    $resetFailures();
    $relaxedBefore = $auditCount('lead_erase_transition_relaxed');
    $burstStatuses = [];
    for ($i = 1; $i <= 60; $i++) { // 60 échecs : au-dessus du seuil nominal, sous le relèvement (100)
        $burstStatuses[] = $erase(['wa_id' => $waId], ['X-Partikulier-Lead-Erase' => 'orchestrateur-essai-' . $i])['status'];
        if (end($burstStatuses) === 429) { break; }
    }
    $noFlood = !in_array(429, $burstStatuses, true);
    $relaxedLogged = $auditCount('lead_erase_transition_relaxed') > $relaxedBefore;
    delete_option('lead_erase_transition_active'); // fin de transition : rejet strict + seuil nominal
    $r = $erase(['wa_id' => $waId]); // compteur à 60 ≥ 10 → 429 effectif
    $assert('SE16-008', $noFlood && $relaxedLogged && $r['status'] === 429 && $r['code'] === 'pk_erase_rate_limited',
        sprintf('transition active : rafale de %d échecs sans 429, assouplissements journalisés ; transition close → 429 effectif (HTTP %d)',
            count($burstStatuses), $r['status']));
} catch (Throwable $error) {
    $assert('SE16-EXCEPTION', false, $error->getMessage());
} finally {
    /* --- Nettoyage : état du banc restauré (DA-011 : leads=10 en fin de suite) --- */
    $resetFailures();
    delete_option('lead_erase_transition_active');
    delete_option('lead_erase_api_secret');
    $wpdb->delete($leadsTable, ['id' => $victimId], ['%d']);
    /* E55 (lot sécurité 2.10.7) : le lead dédié est effacé par la cascade
       elle-même ; ne reste que sa ligne d'audit, retirée ici. */
    if (isset($e55LeadId)) {
        $wpdb->delete($auditTable, ['action' => 'lead_erased', 'object_id' => $e55LeadId], ['%s', '%d']);
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'lead-erase-security-contract (SE-016 — campagne post-audit)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/lead-erase-security-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
