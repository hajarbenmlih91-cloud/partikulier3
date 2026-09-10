<?php
/**
 * Contrat du domaine alertes (lot B3, CDC v1.2 — CA-2).
 *
 * Couvre le dispositif des alertes sauvegardées, désormais propriété du
 * plugin (deux tables : pk_saved_alerts, pk_alert_deliveries) : création
 * après consentement similar_listings (lecture croisée via le LeadService
 * du lot B2), gardes de validation (codes d'erreur hérités du thème),
 * signature de critères et upsert par (lead_id, criteria_signature),
 * assainissement des critères (clés connues, trois zones maximum), statuts
 * active/paused/stopped — et la couture du thème 6.18.4 : l'appel via
 * Partikulier_Saved_Alerts::save_alert doit exécuter le service du plugin,
 * preuve par les lignes d'audit (seul le plugin écrit le registre d'audit
 * — le chemin autonome du thème n'y écrit jamais).
 *
 * Aucune livraison (pk_alert_deliveries) ne doit être écrite par le
 * service : l'adaptateur Meta/n8n n'est pas encore ouvert (contrat du
 * thème porté fidèlement) — vérifié ici.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/alerts-domain-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Database\Migrator;
use Partikulier\Core\Domain\Alerts\AlertService;
use Partikulier\Core\Domain\Leads\LeadService;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
$run = bin2hex(random_bytes(4));
wp_set_current_user(1); // administrateur du banc (manage_options garanti par le harnais)

/* --- Fixtures : une annonce publiée + un lead consentant (dispositif B2) --- */
$propertyId = (int) wp_insert_post([
    'post_type' => 'properties',
    'post_status' => 'publish',
    'post_title' => "B3A-FIXTURE {$run}",
    'post_content' => 'Fixture de contrat domaine alertes B3.',
    'post_author' => 1,
], true);
update_post_meta($propertyId, '_pk_owner_phone', '2126000000' . random_int(100, 999));
update_post_meta($propertyId, '_pk_owner_name', 'Propriétaire fixture B3');

$phone = '2126' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
$criteria = ['transaction' => 'achat', 'type' => 'appartement', 'areas' => ['Casablanca', 'Rabat'], 'budget_max' => 1250000, 'layout' => '3 pièces'];
$alertIds = [];

try {
    // 1) Couture : service du plugin chargé, classe thème présente, tables uniques.
    $seamOk = class_exists(AlertService::class)
        && class_exists('Partikulier_Saved_Alerts')
        && AlertService::alerts_table() === $wpdb->prefix . 'pk_saved_alerts'
        && AlertService::deliveries_table() === $wpdb->prefix . 'pk_alert_deliveries'
        && Partikulier_Saved_Alerts::alerts_table() === AlertService::alerts_table();
    $assert('B3A-001', $seamOk, 'couture thème/plugin : classes chargées, tables uniques ' . AlertService::alerts_table() . ' / ' . AlertService::deliveries_table());

    // 2) Adoption journalisée : les deux tables sont passées au plugin
    //    (manifeste 2.3.0) et l'audit domain_adopted B3/alerts existe.
    $adoptedB3 = 0;
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT metadata_json FROM {$prefix}pk_audit_log WHERE action = %s", 'domain_adopted')) as $row) {
        $meta = json_decode((string) $row->metadata_json, true);
        if (($meta['lot'] ?? '') === 'B3' && ($meta['domain'] ?? '') === 'alerts') { $adoptedB3++; }
    }
    $tables = ['pk_saved_alerts', 'pk_alert_deliveries'];
    $allExist = true;
    foreach ($tables as $t) {
        $name = $prefix . $t;
        if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) !== $name) { $allExist = false; }
    }
    $assert('B3A-002', $allExist && $adoptedB3 === 1,
        "adoption B3 journalisée (domain_adopted B3/alerts = {$adoptedB3}), deux tables en place");

    // 3) Lead consentant via le dispositif B2 (voie webhook du service).
    $reference = LeadService::reference_for($propertyId);
    $result = LeadService::authorize_contact($phone, $propertyId, 'b3a-wa-' . $run);
    $resultData = $result instanceof WP_REST_Response ? (array) $result->get_data() : (array) $result;
    $leadId = (int) ($resultData['lead_id'] ?? 0);
    $consentRequest = new WP_REST_Request('POST', '/partikulier/v1/consent');
    $consentRequest->set_param('wa_id', $phone);
    $consentRequest->set_param('scope', 'similar_listings');
    $consentRequest->set_param('granted', true);
    $consentRequest->set_param('provider_message_id', 'b3a-consent-' . $run);
    $granted = LeadService::rest_consent($consentRequest);
    $assert('B3A-003', $leadId > 0 && !empty($resultData['allowed'])
        && $granted instanceof WP_REST_Response && ($granted->get_data()['consent'] ?? '') === 'granted',
        "fixture : lead {$leadId} créé via authorize_contact + consentement similar_listings accordé (voie REST B2)");

    // 4) Garde de consentement : un lead SANS consentement est refusé.
    $freshPhone = '2126' . str_pad((string) random_int(10000000, 99999999), 8, '0', STR_PAD_LEFT);
    $fresh = LeadService::authorize_contact($freshPhone, $propertyId, 'b3a-wa2-' . $run);
    $freshData = $fresh instanceof WP_REST_Response ? (array) $fresh->get_data() : (array) $fresh;
    $freshLeadId = (int) ($freshData['lead_id'] ?? 0);
    $refused = AlertService::save_alert($freshLeadId, $criteria, 'fr', 'daily', 'b3a-proof-' . $run);
    $rowsFresh = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_saved_alerts WHERE lead_id = %d", $freshLeadId));
    $assert('B3A-004', is_wp_error($refused) && $refused->get_error_code() === 'pk_alert_consent' && $rowsFresh === 0,
        'lead sans consentement → pk_alert_consent, aucune ligne écrite');

    // 5) Création nominale : ligne exacte + signature + audit plugin.
    $alertId = AlertService::save_alert($leadId, $criteria, 'fr', 'daily', 'b3a-msg-' . $run);
    $alertIds[] = is_wp_error($alertId) ? 0 : (int) $alertId;
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}pk_saved_alerts WHERE id = %d", (int) $alertId), ARRAY_A);
    $expectedSignature = hash('sha256', wp_json_encode(AlertServiceProbe::sanitize($criteria)));
    $auditSaved = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d", 'alert_saved', (int) $alertId));
    $assert('B3A-005', !is_wp_error($alertId) && (int) $alertId > 0 && is_array($row)
        && (int) $row['lead_id'] === $leadId
        && $row['criteria'] === wp_json_encode(AlertServiceProbe::sanitize($criteria))
        && $row['criteria_signature'] === $expectedSignature
        && $row['locale'] === 'fr' && $row['frequency'] === 'daily'
        && $row['status'] === 'active' && $row['consent_message_id'] === 'b3a-msg-' . $run
        && $row['created_at'] !== null && $row['updated_at'] !== null
        && $auditSaved === 1,
        'save_alert : ligne conforme (critères assainis, signature sha256, statut active) + audit alert_saved');

    // 6) Upsert idempotent : re-save même lead + mêmes critères, locale et
    //    fréquence différentes → même id, une seule ligne, champs actualisés.
    $resaved = AlertService::save_alert($leadId, $criteria, 'ar', 'weekly', 'b3a-msg2-' . $run);
    $rowsAfter = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_saved_alerts WHERE lead_id = %d", $leadId));
    $row2 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}pk_saved_alerts WHERE id = %d", (int) $alertId), ARRAY_A);
    $assert('B3A-006', !is_wp_error($resaved) && (int) $resaved === (int) $alertId && $rowsAfter === 1
        && $row2['locale'] === 'ar' && $row2['frequency'] === 'weekly' && $row2['consent_message_id'] === 'b3a-msg2-' . $run,
        'upsert (lead_id, criteria_signature) : même alerte actualisée, pas de doublon');

    // 7) Critères réordonnés (clés de premier niveau) → même signature
    //    (ksort) → même alerte. NB : l'ordre des VALEURS de « areas » est
    //    significatif dans la signature — comportement hérité du thème,
    //    porté fidèlement (le ksort ne trie que les clés).
    $reordered = ['layout' => '3 pièces', 'budget_max' => 1250000, 'areas' => ['Casablanca', 'Rabat'], 'type' => 'appartement', 'transaction' => 'achat'];
    $resaved2 = AlertService::save_alert($leadId, $reordered, 'ar', 'weekly', 'b3a-msg2-' . $run);
    $rowsStill = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_saved_alerts WHERE lead_id = %d", $leadId));
    $assert('B3A-007', !is_wp_error($resaved2) && (int) $resaved2 === (int) $alertId && $rowsStill === 1,
        'critères réordonnés (clés) → même signature (assainissement normalisé par ksort — l\'ordre des zones reste significatif, comportement hérité)');

    // 8) Assainissement : clés inconnues écartées, zones coupées à trois.
    $dirty = ['transaction' => 'achat', 'junk' => 'x', 'areas' => ['Casablanca', 'Rabat', 'Fès', 'Marrakech'], 'budget_max' => '1500000'];
    $dirtyId = AlertService::save_alert($leadId, $dirty, 'fr', 'daily', 'b3a-msg3-' . $run);
    $alertIds[] = is_wp_error($dirtyId) ? 0 : (int) $dirtyId;
    $dirtyRow = $wpdb->get_row($wpdb->prepare("SELECT criteria FROM {$prefix}pk_saved_alerts WHERE id = %d", (int) $dirtyId), ARRAY_A);
    $stored = is_array($dirtyRow) ? (array) json_decode((string) $dirtyRow['criteria'], true) : [];
    $assert('B3A-008', !is_wp_error($dirtyId) && (int) $dirtyId > 0 && (int) $dirtyId !== (int) $alertId
        && !array_key_exists('junk', $stored) && count($stored['areas'] ?? []) === 3
        && (int) ($stored['budget_max'] ?? 0) === 1500000,
        'assainissement : clé inconnue écartée, 3 zones maximum, budget entier');

    // 9) Gardes de validation (codes hérités du thème).
    $g1 = AlertService::save_alert(0, $criteria, 'fr', 'daily', 'x');
    $g2 = AlertService::save_alert($leadId, $criteria, 'zz', 'daily', 'x');
    $g3 = AlertService::save_alert($leadId, $criteria, 'fr', 'hourly', 'x');
    $g4 = AlertService::save_alert($leadId, $criteria, 'fr', 'daily', '');
    $g5 = AlertService::save_alert($leadId, [], 'fr', 'daily', 'x');
    $assert('B3A-009', is_wp_error($g1) && is_wp_error($g2) && is_wp_error($g3) && is_wp_error($g4) && is_wp_error($g5)
        && $g1->get_error_code() === 'pk_alert_payload' && $g2->get_error_code() === 'pk_alert_payload'
        && $g3->get_error_code() === 'pk_alert_payload' && $g4->get_error_code() === 'pk_alert_payload'
        && $g5->get_error_code() === 'pk_alert_payload',
        'gardes : lead 0, locale inconnue, fréquence inconnue, preuve vide, critères vides → pk_alert_payload');

    // 10) Changement de statut : pause puis arrêt, journalisés.
    $paused = AlertService::change_status((int) $alertId, AlertService::STATUS_PAUSED);
    $stopped = AlertService::change_status((int) $alertId, AlertService::STATUS_STOPPED);
    $statusRow = $wpdb->get_row($wpdb->prepare("SELECT status, updated_at FROM {$prefix}pk_saved_alerts WHERE id = %d", (int) $alertId), ARRAY_A);
    $auditStatus = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d", 'alert_status_changed', (int) $alertId));
    $assert('B3A-010', $paused === true && $stopped === true && is_array($statusRow)
        && $statusRow['status'] === 'stopped' && $auditStatus === 2,
        'change_status : active → paused → stopped, deux audits alert_status_changed');

    // 11) Gardes de statut (codes hérités).
    $s1 = AlertService::change_status(0, 'paused');
    $s2 = AlertService::change_status((int) $alertId, 'bidon');
    $assert('B3A-011', is_wp_error($s1) && is_wp_error($s2)
        && $s1->get_error_code() === 'pk_alert_status' && $s2->get_error_code() === 'pk_alert_status',
        'gardes statut : id nul et valeur inconnue → pk_alert_status');

    // 12) Couture du thème : l'appel via Partikulier_Saved_Alerts exécute le
    //     service du plugin (preuve : audit alert_saved écrit, ligne créée).
    $seamId = Partikulier_Saved_Alerts::save_alert($leadId, ['transaction' => 'location', 'type' => 'villa'], 'en', 'instant', 'b3a-seam-' . $run);
    $alertIds[] = is_wp_error($seamId) ? 0 : (int) $seamId;
    $seamRow = $wpdb->get_row($wpdb->prepare("SELECT status, locale, frequency FROM {$prefix}pk_saved_alerts WHERE id = %d", (int) $seamId), ARRAY_A);
    $seamAudit = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d", 'alert_saved', (int) $seamId));
    $seamStatus = Partikulier_Saved_Alerts::change_status((int) $seamId, Partikulier_Saved_Alerts::STATUS_PAUSED);
    $seamAudit2 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d", 'alert_status_changed', (int) $seamId));
    $assert('B3A-012', !is_wp_error($seamId) && (int) $seamId > 0 && is_array($seamRow)
        && $seamRow['locale'] === 'en' && $seamRow['frequency'] === 'instant'
        && $seamAudit === 1 && $seamStatus === true && $seamAudit2 === 1,
        'couture thème → service plugin : save_alert/change_status via Partikulier_Saved_Alerts exécutent AlertService (audits écrits)');

    // 13) Consentement révoqué → création refusée (pk_alert_consent).
    $revokeRequest = new WP_REST_Request('POST', '/partikulier/v1/consent');
    $revokeRequest->set_param('wa_id', $phone);
    $revokeRequest->set_param('scope', 'similar_listings');
    $revokeRequest->set_param('granted', false);
    $revokeRequest->set_param('provider_message_id', 'b3a-revoke-' . $run);
    $revoked = LeadService::rest_consent($revokeRequest);
    $postRevoke = AlertService::save_alert($leadId, ['transaction' => 'achat'], 'fr', 'daily', 'b3a-post-' . $run);
    $assert('B3A-013', $revoked instanceof WP_REST_Response && is_wp_error($postRevoke)
        && $postRevoke->get_error_code() === 'pk_alert_consent',
        'consentement révoqué → toute nouvelle alerte refusée (pk_alert_consent)');

    // 14) Aucune livraison écrite par le service (adaptateur Meta/n8n non ouvert).
    $deliveries = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_alert_deliveries");
    $assert('B3A-014', $deliveries === 0,
        'pk_alert_deliveries reste vide : aucune livraison écrite par le service (contrat du thème porté fidèlement)');

    // 15) Health : domaine alerts plugin-owned, deux tables suivies, 0 collision.
    $health = (new \Partikulier\Core\HealthCheck())->get();
    $alertsTables = $health['domains']['alerts']['tables'] ?? [];
    $domainsOk = ($health['domains']['alerts']['owner'] ?? '') === 'plugin'
        && count($alertsTables) === 2 && !in_array(false, $alertsTables, true)
        && (int) ($health['routes']['collisions'] ?? -1) === 0;
    $assert('B3A-015', $domainsOk,
        'health check : domaine alerts owner=plugin, 2/2 tables suivies, 0 collision de routes');
} catch (Throwable $error) {
    $assert('B3A-EXCEPTION', false, $error->getMessage());
} finally {
    // Nettoyage : alertes de la fixture, leads du dispositif (huit tables),
    // annonce jetable — le banc repart sans trace.
    $leadCleanup = [(int) ($leadId ?? 0), (int) ($freshLeadId ?? 0)];
    foreach (array_unique(array_filter($leadCleanup)) as $lid) {
        $wpdb->delete($prefix . 'pk_saved_alerts', ['lead_id' => $lid], ['%d']);
        LeadService::erase_lead((int) $lid);
    }
    foreach (array_unique(array_filter(array_map('intval', $alertIds))) as $aid) {
        $wpdb->delete($prefix . 'pk_alert_deliveries', ['alert_id' => $aid], ['%d']);
    }
    if (!empty($propertyId)) { wp_delete_post((int) $propertyId, true); }
}

/**
 * Sonde locale : reproduit l'assainissement des critères du thème/port pour
 * calculer la signature attendue SANS exposer la méthode privée du service.
 */
final class AlertServiceProbe
{
    public static function sanitize(array $criteria): array
    {
        $clean = [];
        if (isset($criteria['transaction'])) { $clean['transaction'] = sanitize_key($criteria['transaction']); }
        if (isset($criteria['type'])) { $clean['type'] = sanitize_text_field($criteria['type']); }
        if (isset($criteria['areas'])) { $clean['areas'] = array_slice(array_values(array_filter(array_map('sanitize_text_field', (array) $criteria['areas']))), 0, 3); }
        if (isset($criteria['budget_max'])) { $clean['budget_max'] = absint($criteria['budget_max']); }
        if (isset($criteria['layout'])) { $clean['layout'] = sanitize_text_field($criteria['layout']); }
        ksort($clean);
        return array_filter($clean, static function ($value) {
            return is_array($value) ? !empty($value) : '' !== $value && 0 !== $value;
        });
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'alerts-domain-contract (lot B3)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/alerts-domain-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
