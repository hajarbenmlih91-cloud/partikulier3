<?php
/**
 * Contrat du pipeline de leads unique INTEG-2 (lot A).
 *
 * La route POST /partikulier/v1/leads alimente le dispositif complet du thème
 * (huit tables) : mêmes contrôles, même plafonnement quotidien, même
 * journalisation. Le stockage par commentaire est éteint ; les leads
 * historiques sont marqués et journalisés par la migration, sans doublon.
 *
 * Rejouable : PK_WP_DIR=... php partikulier-core/tests/leads-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Integration\LeadBridge;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
$bridge = new LeadBridge();
$run = bin2hex(random_bytes(4));
$phone = '2126' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
$phone2 = '2126' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);

/* --- Fixtures : trois annonces publiées à trois propriétaires distincts --- */
$owners = [1];
for ($i = 2; $i <= 3; $i++) {
    $existing = get_user_by('login', 'pk_lead_owner_' . $run . '_' . $i);
    $owners[] = $existing ? (int) $existing->ID : (int) wp_insert_user([
        'user_login' => 'pk_lead_owner_' . $run . '_' . $i,
        'user_pass' => wp_generate_password(24),
        'user_email' => "pk-lead-owner-{$run}-{$i}@example.test",
        'role' => 'author',
    ]);
}
$propertyIds = [];
foreach ($owners as $index => $ownerId) {
    $propertyIds[] = (int) wp_insert_post([
        'post_type' => 'properties',
        'post_status' => 'publish',
        'post_title' => "LEAD-FIXTURE {$run} #{$index}",
        'post_content' => 'Fixture de contrat leads.',
        'post_author' => $ownerId,
    ], true);
}
foreach ($propertyIds as $propertyId) {
    update_post_meta($propertyId, '_pk_owner_phone', '2126000000' . random_int(100, 999));
    update_post_meta($propertyId, '_pk_owner_name', 'Propriétaire fixture');
}
$primary = $propertyIds[0];

$leadIds = [];
try {
    // 1) Lead complet via la route REST.
    $request = new WP_REST_Request('POST', '/partikulier/v1/leads');
    $request->set_header('Content-Type', 'application/json');
    $request->set_body(wp_json_encode([
        'phone' => $phone,
        'property_id' => $primary,
        'message' => 'Contrat : intéressé par ce bien.',
        'name' => 'Test Contract',
        'email' => 'contract@example.test',
    ]));
    $response = rest_do_request($request);
    $data = (array) ($response->get_data()['data'] ?? []);
    $leadIds[] = (int) ($data['lead_id'] ?? 0);
    $hash = hash_hmac('sha256', $phone, wp_salt('auth'));
    $leadRow = $wpdb->get_row($wpdb->prepare("SELECT id, phone_hash FROM {$prefix}pk_buyer_leads WHERE phone_hash = %s", $hash), ARRAY_A);
    $interest = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_interest_events WHERE lead_id = %d AND property_id = %d", (int) ($data['lead_id'] ?? 0), $primary));
    $disclosure = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_contact_disclosures WHERE lead_id = %d AND property_id = %d", (int) ($data['lead_id'] ?? 0), $primary));
    $followup = $wpdb->get_row($wpdb->prepare("SELECT note FROM {$prefix}pk_lead_followups WHERE lead_id = %d", (int) ($data['lead_id'] ?? 0)), ARRAY_A);
    $note = $followup ? (array) json_decode((string) $followup['note'], true) : null;
    $assert('LEAD-001', $response->get_status() === 201 && (int) ($data['lead_id'] ?? 0) > 0
        && is_array($leadRow) && (int) $interest === 1 && (int) $disclosure === 1
        && is_array($note) && ($note['source'] ?? '') === 'rest_api' && ($note['email'] ?? '') === 'contract@example.test',
        'lead accepté → dispositif complet : buyer_leads + interest + disclosure + suivi contextuel');

    // 2) Contact avec le courriel initial absent de toute zone de stockage parallèle.
    $commentRows = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->comments} WHERE comment_type = %s AND comment_author_email = %s",
        'partikulier_lead',
        'contract@example.test'
    ));
    $assert('LEAD-002', $commentRows === 0, 'zéro lead en stockage commentaire (canal éteint)');

    // 3) Rattachement obligatoire.
    $noProperty = new WP_REST_Request('POST', '/partikulier/v1/leads');
    $noProperty->set_header('Content-Type', 'application/json');
    $noProperty->set_body(wp_json_encode(['phone' => $phone2, 'message' => 'sans annonce']));
    $noPropertyResult = rest_do_request($noProperty);
    $assert('LEAD-003', $noPropertyResult->get_status() === 422, 'lead sans rattachement → 422');

    // 4) Téléphone invalide.
    $badPhone = new WP_REST_Request('POST', '/partikulier/v1/leads');
    $badPhone->set_header('Content-Type', 'application/json');
    $badPhone->set_body(wp_json_encode(['phone' => '123', 'property_id' => $primary]));
    $badPhoneResult = rest_do_request($badPhone);
    $assert('LEAD-004', $badPhoneResult->get_status() === 422, 'téléphone invalide → 422');

    // 5) Annonce inconnue.
    $unknown = new WP_REST_Request('POST', '/partikulier/v1/leads');
    $unknown->set_header('Content-Type', 'application/json');
    $unknown->set_body(wp_json_encode(['phone' => $phone2, 'property_id' => 999999999]));
    $unknownResult = rest_do_request($unknown);
    $assert('LEAD-005', $unknownResult->get_status() === 404, 'annonce inconnue → 404');

    // 6) Plafonnement quotidien : deux propriétaires distincts puis refus.
    $second = rest_do_request((static function () use ($phone, $propertyIds): WP_REST_Request {
        $r = new WP_REST_Request('POST', '/partikulier/v1/leads');
        $r->set_header('Content-Type', 'application/json');
        $r->set_body(wp_json_encode(['phone' => $phone, 'property_id' => $propertyIds[1]]));
        return $r;
    })());
    $third = rest_do_request((static function () use ($phone, $propertyIds): WP_REST_Request {
        $r = new WP_REST_Request('POST', '/partikulier/v1/leads');
        $r->set_header('Content-Type', 'application/json');
        $r->set_body(wp_json_encode(['phone' => $phone, 'property_id' => $propertyIds[2]]));
        return $r;
    })());
    $thirdData = (array) $third->get_data();
    $assert('LEAD-006', $second->get_status() === 201 && $third->get_status() === 429
        && (isset($thirdData['code']) ? $thirdData['code'] === 'lead_daily_limit' : true),
        'plafonnement quotidien identique au parcours site : 2 contacts puis 429');

    // 7) Migration des leads-commentaires : idempotente et journalisée.
    $commentId = wp_insert_comment([
        'comment_author_email' => 'legacy@example.test',
        'comment_content' => 'legacy lead comment',
        'comment_type' => 'partikulier_lead',
        'comment_approved' => 0,
    ]);
    $first = $bridge->migrateLegacyComments();
    $again = $bridge->migrateLegacyComments();
    $marked = get_comment_meta($commentId, '_pk_lead_migrated', true);
    $auditRow = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d",
        'lead_comment_migration',
        $commentId
    ));
    $assert('LEAD-007', $first['marked'] === 1 && $again['marked'] === 0 && $again['already'] === 1 && $marked !== '' && $auditRow === 1,
        'migration des commentaires : marquée, journalisée, idempotente');
    wp_delete_comment($commentId, true);
} catch (Throwable $error) {
    $assert('LEAD-EXCEPTION', false, $error->getMessage());
} finally {
    // Nettoyage : lead du dispositif (huit tables), annonces et utilisateurs jetables.
    // Hygiène de banc (découverte de la recette B2) : pk_buyer_leads est clé par
    // « id », pas « lead_id » — l'ancienne formulation laissait fuiter une ligne
    // orpheline par rejeu.
    $purge_lead = static function (int $leadId) use ($wpdb, $prefix): void {
        foreach (['pk_interest_events', 'pk_contact_limits', 'pk_contact_disclosures',
                  'pk_whatsapp_consents', 'pk_whatsapp_messages', 'pk_buyer_preferences', 'pk_lead_followups'] as $table) {
            $wpdb->delete($prefix . $table, ['lead_id' => $leadId], ['%d']);
        }
        $wpdb->delete($prefix . 'pk_buyer_leads', ['id' => $leadId], ['%d']);
    };
    foreach (array_unique(array_filter($leadIds)) as $leadId) {
        $purge_lead((int) $leadId);
    }
    // Lead éventuel du 2e numéro (refus précoces n'écrivent rien ; ceinture et bretelles).
    $hash2 = hash_hmac('sha256', $phone2, wp_salt('auth'));
    $lead2 = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$prefix}pk_buyer_leads WHERE phone_hash = %s", $hash2));
    if ($lead2) {
        $purge_lead($lead2);
    }
    foreach ($propertyIds as $propertyId) {
        wp_delete_post($propertyId, true);
    }
    require_once ABSPATH . 'wp-admin/includes/user.php';
    for ($i = 2; $i <= 3; $i++) {
        $user = get_user_by('login', 'pk_lead_owner_' . $run . '_' . $i);
        if ($user) {
            wp_delete_user((int) $user->ID);
        }
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
$payload = [
    'test_id' => 'CORE-LEADS-CONTRACT-001',
    'candidate_version' => getenv('PK_VERSION') ?: '2.0.0',
    'source_commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local',
    'started_at_utc' => $started,
    'finished_at_utc' => gmdate('c'),
    'command' => 'php partikulier-core/tests/leads-contract.php',
    'fixture' => 'trois annonces publiées à propriétaires distincts, lead jetable',
    'status' => $failed ? 'FAIL' : 'PASS',
    'exit_code' => $failed ? 1 : 0,
    'tests' => $results,
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'limitations' => ['voie n8n du même cœur transactionnel couverte par les scénarios WhatsApp existants', 'opt-out testé via son contrat dédié côté thème'],
];
printf("%s\n", wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit($failed ? 1 : 0);
