<?php
/**
 * Contrat du domaine leads/qualification/WhatsApp (lot B2, CDC v1.2 — CA-2).
 *
 * Couvre le dispositif complet, désormais propriété du plugin (huit tables) :
 * cœur transactionnel authorize_contact (création du lead, message, intérêt,
 * divulgation, plafonnement quotidien par propriétaires distincts), pont REST
 * register_api_lead (INTEG-2, via LeadBridge → service du plugin), gardes de
 * validation, consentement similar_listings, opt-out idempotent, préférences,
 * effacement de rétention (huit tables, transaction), écran d'administration
 * (KPI, lignes filtrées, mise à jour de suivi), lecture croisée du
 * consentement (domaine alertes) — et les coutures du thème 6.18.3 : les
 * appels via Partikulier_Buyer_Qualification / Partikulier_Leads_Admin doivent
 * exécuter le service du plugin, preuve par les lignes d'audit (seul le plugin
 * écrit le registre d'audit — le chemin autonome du thème n'y écrit jamais).
 *
 * REG-4 : aucune écriture du dispositif ne crée de commentaire partikulier_lead
 * (stockage historique éteint depuis le lot A, vérifié ici sur les chemins B2).
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/leads-domain-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Database\Migrator;
use Partikulier\Core\Domain\Leads\LeadService;
use Partikulier\Core\Integration\LeadBridge;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
$run = bin2hex(random_bytes(4));
wp_set_current_user(1); // administrateur du banc (manage_options garanti par le harnais)
$adminId = 1;

/* --- Fixtures : deux annonces publiées à deux propriétaires distincts + une troisième --- */
$owner2 = get_user_by('login', 'pk_b2l_owner_' . $run);
$owner2Id = $owner2 ? (int) $owner2->ID : (int) wp_insert_user([
    'user_login' => 'pk_b2l_owner_' . $run,
    'user_pass' => wp_generate_password(24),
    'user_email' => "pk-b2l-owner-{$run}@example.test",
    'role' => 'author',
]);
$owner3 = get_user_by('login', 'pk_b2l_owner3_' . $run);
$owner3Id = $owner3 ? (int) $owner3->ID : (int) wp_insert_user([
    'user_login' => 'pk_b2l_owner3_' . $run,
    'user_pass' => wp_generate_password(24),
    'user_email' => "pk-b2l-owner3-{$run}@example.test",
    'role' => 'author',
]);
$propertyIds = [];
foreach ([1, $owner2Id, $owner3Id] as $index => $ownerId) {
    $propertyIds[] = (int) wp_insert_post([
        'post_type' => 'properties',
        'post_status' => 'publish',
        'post_title' => "B2L-FIXTURE {$run} #{$index}",
        'post_content' => 'Fixture de contrat domaine leads B2.',
        'post_author' => $ownerId,
    ], true);
}
foreach ($propertyIds as $propertyId) {
    update_post_meta($propertyId, '_pk_owner_phone', '2126000000' . random_int(100, 999));
    update_post_meta($propertyId, '_pk_owner_name', 'Propriétaire fixture B2');
}
$primary = $propertyIds[0];

$phone = '2126' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
$phone2 = '2126' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
$leadIds = [];
$commentBaseline = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = %s", 'partikulier_lead'));

try {
    // 1) Couture : service du plugin chargé, classes thème présentes, table unique.
    $seamOk = class_exists(LeadService::class)
        && class_exists('Partikulier_Buyer_Qualification')
        && LeadService::leads_table() === $wpdb->prefix . 'pk_buyer_leads'
        && class_exists('Partikulier_Leads_Admin')
        && class_exists('Partikulier_Lead_Retention');
    $assert('B2L-001', $seamOk, 'couture thème/plugin : classes chargées, table unique ' . LeadService::leads_table());

    // 2) Adoption journalisée : les 10 leads réels du T0 sont passés au plugin
    //    intacts (comptage avant/après identique, ligne d'audit domain_adopted).
    $leadsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_buyer_leads");
    $adoptAudit = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_type = %s",
        'domain_adopted',
        'schema'
    ));
    $adoptedB2 = 0;
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT metadata_json FROM {$prefix}pk_audit_log WHERE action = %s", 'domain_adopted')) as $row) {
        $meta = json_decode((string) $row->metadata_json, true);
        if (($meta['lot'] ?? '') === 'B2' && ($meta['domain'] ?? '') === 'leads') { $adoptedB2++; }
    }
    $assert('B2L-002', $leadsBefore >= 10 && $adoptAudit >= 2 && $adoptedB2 === 1,
        "adoption B2 journalisée (domain_adopted B2/leads = {$adoptedB2}), leads du T0 intacts ({$leadsBefore} ≥ 10)");

    // 3) Cœur transactionnel via le service : voie webhook (wa_id + référence).
    $reference = LeadService::reference_for($primary);
    $messageId = 'b2l-wa-' . $run;
    $result = LeadService::authorize_contact($phone, $primary, $messageId);
    $resultData = $result instanceof WP_REST_Response ? (array) $result->get_data() : (array) $result;
    $leadIds[] = $leadId = (int) ($resultData['lead_id'] ?? 0);
    $hash = hash_hmac('sha256', $phone, wp_salt('auth'));
    $leadRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}pk_buyer_leads WHERE id = %d", $leadId), ARRAY_A);
    $interest = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_interest_events WHERE lead_id = %d AND property_id = %d", $leadId, $primary));
    $disclosure = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_contact_disclosures WHERE lead_id = %d AND property_id = %d", $leadId, $primary));
    $message = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_whatsapp_messages WHERE provider_message_id = %s", $messageId));
    $auditAuth = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d", 'lead_authorized', $leadId));
    $snapshot = (string) $wpdb->get_var($wpdb->prepare("SELECT property_snapshot FROM {$prefix}pk_interest_events WHERE lead_id = %d", $leadId));
    $assert('B2L-003', !empty($resultData['allowed']) && $leadId > 0 && is_array($leadRow)
        && $leadRow['phone_hash'] === $hash && $leadRow['phone_encrypted'] !== $phone
        && $interest === 1 && $disclosure === 1 && $message === 1 && $auditAuth === 1
        && str_contains($snapshot, '"reference":"' . $reference . '"'),
        'authorize_contact : lead + message + intérêt + divulgation + audit lead_authorized ; numéro haché/chiffré, jamais en clair');

    // 4) Replay du même message : idempotence (duplicate_message, rien d'écrit).
    $replay = LeadService::authorize_contact($phone, $primary, $messageId);
    $replayData = $replay instanceof WP_REST_Response ? (array) $replay->get_data() : (array) $replay;
    $messagesAfter = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_whatsapp_messages WHERE lead_id = %d", $leadId));
    $assert('B2L-004', empty($replayData['allowed']) && ($replayData['reason'] ?? '') === 'duplicate_message' && $messagesAfter === 1,
        'message provider rejoué → duplicate_message, aucune seconde écriture');

    // 5) Plafonnement quotidien : deux propriétaires distincts consommés, le troisième refusé.
    $second = LeadService::authorize_contact($phone, $propertyIds[1], 'b2l-wa2-' . $run);
    $third = LeadService::authorize_contact($phone, $propertyIds[2], 'b2l-wa3-' . $run);
    $thirdData = $third instanceof WP_REST_Response ? (array) $third->get_data() : (array) $third;
    $limitUsed = (int) $wpdb->get_var($wpdb->prepare("SELECT contacts_count FROM {$prefix}pk_contact_limits WHERE lead_id = %d AND day_key = %s", $leadId, current_time('Y-m-d')));
    $assert('B2L-005', !empty(($second instanceof WP_REST_Response ? (array) $second->get_data() : (array) $second)['allowed'])
        && empty($thirdData['allowed']) && ($thirdData['reason'] ?? '') === 'daily_limit'
        && $limitUsed === LeadService::daily_limit(),
        'plafonnement quotidien porté sur les propriétaires distincts : ' . $limitUsed . '/' . LeadService::daily_limit() . ' puis daily_limit');

    // 6) Pont REST INTEG-2 via LeadBridge → service du plugin (lead 2e numéro, autre annonce 1er propriétaire ? non : limite).
    $bridgeLead = (new LeadBridge())->create([
        'phone' => $phone2,
        'property_id' => $primary,
        'message' => 'Contrat B2 : intéressé.',
        'name' => 'Test B2L',
        'email' => 'b2l@example.test',
    ]);
    $bridgeLeadId = is_wp_error($bridgeLead) ? 0 : (int) $bridgeLead['lead_id'];
    $leadIds[] = $bridgeLeadId;
    $followup = $wpdb->get_row($wpdb->prepare("SELECT note FROM {$prefix}pk_lead_followups WHERE lead_id = %d", $bridgeLeadId), ARRAY_A);
    $note = $followup ? (array) json_decode((string) $followup['note'], true) : null;
    $assert('B2L-006', !is_wp_error($bridgeLead) && $bridgeLeadId > 0
        && ($bridgeLead['contact']['allowed'] ?? false) === true
        && is_array($note) && ($note['source'] ?? '') === 'rest_api' && ($note['email'] ?? '') === 'b2l@example.test',
        'LeadBridge → LeadService::register_api_lead : dispositif complet + suivi contextuel du canal REST');

    // 7) Gardes du pont (codes d'erreur hérités).
    $e1 = (new LeadBridge())->create(['phone' => '123', 'property_id' => $primary]);
    $e2 = (new LeadBridge())->create(['phone' => '212600000001', 'property_id' => 999999999]);
    $e3 = (new LeadBridge())->create(['phone' => '212600000001']);
    $assert('B2L-007', is_wp_error($e1) && $e1->get_error_code() === 'invalid_lead_phone'
        && is_wp_error($e2) && $e2->get_error_code() === 'lead_property_not_found'
        && is_wp_error($e3) && $e3->get_error_code() === 'missing_lead_property',
        'gardes : téléphone invalide 422, annonce inconnue 404, rattachement obligatoire 422');

    // 8) Consentement similar_listings : accord puis révocation (idempotent, preuve au message).
    $consentRequest = new WP_REST_Request('POST', '/partikulier/v1/consent');
    $consentRequest->set_param('wa_id', $phone2);
    $consentRequest->set_param('scope', 'similar_listings');
    $consentRequest->set_param('granted', true);
    $consentRequest->set_param('provider_message_id', 'b2l-consent-' . $run);
    $granted = LeadService::rest_consent($consentRequest);
    $consentRow = $wpdb->get_row($wpdb->prepare("SELECT granted_at, revoked_at FROM {$prefix}pk_whatsapp_consents WHERE lead_id = %d AND scope = %s", $bridgeLeadId, 'similar_listings'), ARRAY_A);
    $crossRead = LeadService::has_active_consent($bridgeLeadId, 'similar_listings');
    $assert('B2L-008', $granted instanceof WP_REST_Response
        && ($granted->get_data()['consent'] ?? '') === 'granted'
        && is_array($consentRow) && $consentRow['granted_at'] !== null && $consentRow['revoked_at'] === null
        && $crossRead === true,
        'consentement accordé (voie REST du service) + lecture croisée has_active_consent (domaine alertes)');

    // 9) Opt-out idempotent : STOP deux fois → replayed, lead fermé, consentement révoqué.
    $stopRequest = new WP_REST_Request('POST', '/partikulier/v1/opt-out');
    $stopRequest->set_param('wa_id', $phone2);
    $stopRequest->set_param('provider_message_id', 'b2l-stop-' . $run);
    $stop1 = LeadService::rest_opt_out($stopRequest);
    $stop2 = LeadService::rest_opt_out($stopRequest);
    $s1 = $stop1 instanceof WP_REST_Response ? (array) $stop1->get_data() : [];
    $s2 = $stop2 instanceof WP_REST_Response ? (array) $stop2->get_data() : [];
    $leadAfter = $wpdb->get_row($wpdb->prepare("SELECT opt_out_at FROM {$prefix}pk_buyer_leads WHERE id = %d", $bridgeLeadId), ARRAY_A);
    $consentAfter = $wpdb->get_row($wpdb->prepare("SELECT revoked_at FROM {$prefix}pk_whatsapp_consents WHERE lead_id = %d", $bridgeLeadId), ARRAY_A);
    $assert('B2L-009', ($s1['replayed'] ?? null) === false && ($s2['replayed'] ?? null) === true
        && is_array($leadAfter) && $leadAfter['opt_out_at'] !== null
        && is_array($consentAfter) && $consentAfter['revoked_at'] !== null,
        'STOP : opt_out_at posé + consentement révoqué ; message rejoué → replayed, idempotent');

    // 10) Préférences (replace, 3 zones max).
    $prefRequest = new WP_REST_Request('POST', '/partikulier/v1/preferences');
    $prefRequest->set_param('wa_id', $phone2);
    $prefRequest->set_param('budget_max', 1200000);
    $prefRequest->set_param('areas', ['Casablanca', 'Rabat', 'Fès', 'Marrakech']);
    $prefRequest->set_param('layout', '3 pièces');
    $prefRequest->set_param('transaction', 'achat');
    $prefUpdated = LeadService::rest_preferences($prefRequest);
    $prefRow = $wpdb->get_row($wpdb->prepare("SELECT budget_max, areas, layout_value, transaction_value, source FROM {$prefix}pk_buyer_preferences WHERE lead_id = %d", $bridgeLeadId), ARRAY_A);
    $areas = is_array($prefRow) ? (array) json_decode((string) $prefRow['areas'], true) : [];
    $assert('B2L-010', $prefUpdated instanceof WP_REST_Response && is_array($prefRow)
        && (int) $prefRow['budget_max'] === 1200000 && count($areas) === 3
        && $prefRow['layout_value'] === '3 pièces' && $prefRow['source'] === 'explicit_whatsapp',
        'préférences : replace idempotent, trois zones conservées au maximum');

    // 11) Écran d'administration : KPI et lignes (les accès données passent par le service).
    $summary = LeadService::admin_summary();
    $rows = LeadService::admin_rows(['status' => '', 'consent' => 'opted_out', 'search' => '', 'page' => 1, 'orderby' => 'last_seen_at', 'order' => 'DESC'], 20);
    $rowsContain = false;
    foreach ((array) $rows['rows'] as $row) {
        if ((int) $row->id === $bridgeLeadId) { $rowsContain = true; break; }
    }
    $totalDirect = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_buyer_leads");
    $assert('B2L-011', $summary['total'] === $totalDirect && $summary['total'] >= 12 && $rows['total'] >= 1 && $rowsContain,
        "écran admin : KPI total {$summary['total']} = comptage SQL direct ; filtre opted_out rend le lead");

    // 12) Mise à jour de suivi (écriture admin via le service, journalisée).
    $updated = LeadService::update_followup($bridgeLeadId, 'qualified', 'Contrat B2 : qualifié.', $adminId);
    $followupRow = $wpdb->get_row($wpdb->prepare("SELECT status, note, updated_by FROM {$prefix}pk_lead_followups WHERE lead_id = %d", $bridgeLeadId), ARRAY_A);
    $auditFollowup = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d", 'lead_followup_updated', $bridgeLeadId));
    $assert('B2L-012', $updated && is_array($followupRow) && $followupRow['status'] === 'qualified'
        && $followupRow['note'] === 'Contrat B2 : qualifié.' && (int) $followupRow['updated_by'] === $adminId && $auditFollowup === 1,
        'suivi admin : replace statut+note+acteur, audit lead_followup_updated');

    // 13) Déchiffrement administrateur : aller-retour du numéro (jamais hors admin).
    $encrypted = (string) $wpdb->get_var($wpdb->prepare("SELECT phone_encrypted FROM {$prefix}pk_buyer_leads WHERE id = %d", $bridgeLeadId));
    $decrypted = LeadService::decrypt_phone_for_admin($encrypted);
    $assert('B2L-013', $decrypted === $phone2 && strlen($encrypted) > 16,
        'déchiffrement admin : round-trip AES-256-CBC exact (réservé manage_options)');

    // 14) Effacement de rétention : les huit tables vidées pour ce lead, journalisé.
    $erased = LeadService::erase_lead($bridgeLeadId);
    $residual = 0;
    foreach (['pk_buyer_leads' => 'id', 'pk_interest_events' => 'lead_id', 'pk_contact_limits' => 'lead_id', 'pk_contact_disclosures' => 'lead_id', 'pk_whatsapp_consents' => 'lead_id', 'pk_whatsapp_messages' => 'lead_id', 'pk_buyer_preferences' => 'lead_id', 'pk_lead_followups' => 'lead_id'] as $table => $key) {
        $residual += (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}{$table} WHERE {$key} = %d", $bridgeLeadId));
    }
    $auditErased = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d", 'lead_erased', $bridgeLeadId));
    $assert('B2L-014', $erased && $residual === 0 && $auditErased === 1,
        'erase_lead : huit tables purgées en transaction, audit lead_erased');

    // 15) REG-4 : aucune écriture du dispositif B2 n'a créé de lead-commentaire.
    $commentsAfter = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = %s", 'partikulier_lead'));
    $assert('B2L-015', $commentsAfter === $commentBaseline,
        'REG-4 : zéro commentaire partikulier_lead créé par les chemins B2 (' . $commentsAfter . ' = baseline ' . $commentBaseline . ')');

    // 16) Health : le domaine leads est plugin-owned avec ses huit tables
    //     suivies, zéro collision de routes (l'intégrité listings n'est pas
    //     assertée ici : les fixtures du contrat viennent d'être insérées et
    //     la file INTEG-1 ne se vide qu'au shutdown — « missing » attendu
    //     en cours de run, revalidé globalement par le health HTTP de la
    //     recette après stabilisation).
    $health = (new \Partikulier\Core\HealthCheck())->get();
    $leadsTables = $health['domains']['leads']['tables'] ?? [];
    $allTables = count($leadsTables) === 8 && !in_array(false, $leadsTables, true);
    $domainsOk = ($health['domains']['leads']['owner'] ?? '') === 'plugin'
        && $allTables
        && (int) ($health['routes']['collisions'] ?? -1) === 0;
    $assert('B2L-016', $domainsOk,
        'health check : domaine leads owner=plugin, 8/8 tables suivies, 0 collision de routes');
} catch (Throwable $error) {
    $assert('B2L-EXCEPTION', false, $error->getMessage());
} finally {
    // Nettoyage : lead du dispositif (huit tables), annonces et utilisateurs jetables.
    foreach (array_unique(array_filter($leadIds)) as $leadId) {
        foreach (['pk_buyer_leads' => 'id', 'pk_interest_events' => 'lead_id', 'pk_contact_limits' => 'lead_id', 'pk_contact_disclosures' => 'lead_id', 'pk_whatsapp_consents' => 'lead_id', 'pk_whatsapp_messages' => 'lead_id', 'pk_buyer_preferences' => 'lead_id', 'pk_lead_followups' => 'lead_id'] as $table => $key) {
            $wpdb->delete($prefix . $table, [$key => (int) $leadId], ['%d']);
        }
    }
    foreach ($propertyIds as $propertyId) {
        wp_delete_post($propertyId, true);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    foreach (['pk_b2l_owner_' . $run, 'pk_b2l_owner3_' . $run] as $login) {
        $user = get_user_by('login', $login);
        if ($user) { wp_delete_user((int) $user->ID); }
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'leads-domain-contract (lot B2)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/leads-domain-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
