<?php
/**
 * Contrat du domaine premium (lot B1, CDC v1.2 — CA-2).
 *
 * Couvre le journal des attributions pk_premium_history, désormais
 * propriété du plugin : création (grant), lecture (journal récent,
 * comptages), transitions d'état critiques (révocation premium,
 * expiration paresseuse), gardes de validation (annonce, permission,
 * motif, période) — et la couture du thème : l'appel via
 * Partikulier_Premium (thème 6.18.2) doit exécuter le service du plugin,
 * preuve par la ligne d'audit premium_granted (seul le plugin écrit le
 * registre d'audit — le chemin autonome du thème n'y écrit jamais).
 *
 * Le journal est un registre d'audit : la suppression d'une ligne n'est
 * pas une opération métier (décision documentée au rapport du lot) ;
 * l'entité « abonnement premium » (pk_premium_subscriptions) a son CRUD
 * complet dans payments-contract.php.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/premium-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\Premium\PremiumService;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
$run = bin2hex(random_bytes(4));
$adminId = 1; // administrateur du banc (manage_options garanti par le harnais)

/* --- Fixture : une annonce properties publiée --- */
$propertyId = (int) wp_insert_post([
    'post_type' => 'properties',
    'post_status' => 'publish',
    'post_title' => "PREMIUM-FIXTURE {$run}",
    'post_content' => 'Fixture de contrat premium.',
    'post_author' => $adminId,
], true);

try {
    // 1) Couture : le service du plugin est chargé, les deux côtés parlent
    //    de la même table, et le thème voit le service.
    $seamOk = class_exists(PremiumService::class)
        && class_exists('Partikulier_Premium')
        && Partikulier_Premium::table_name() === PremiumService::table_name();
    $assert('PREM-001', $seamOk, 'couture thème/plugin : classes chargées, table unique ' . PremiumService::table_name());

    // 2) Création via la COUTURE DU THÈME → exécutée par le plugin
    //    (preuve : ligne d'audit premium_granted, écrite par le seul plugin).
    $starts = gmdate('Y-m-d H:i:s', time() + 3600);
    $ends = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
    $grantId = Partikulier_Premium::grant($propertyId, $adminId, 'Contrat B1 : sélection éditoriale', $starts, $ends);
    $row = is_wp_error($grantId) ? null : $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}pk_premium_history WHERE id = %d", (int) $grantId));
    $metaStatus = (string) get_post_meta($propertyId, PremiumService::META_STATUS, true);
    $auditRows = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_type = %s AND object_id = %d",
        'premium_granted',
        'premium_grant',
        (int) $grantId
    ));
    $assert('PREM-002', !is_wp_error($grantId) && (int) $grantId > 0 && $row !== null && $row->status === 'active'
        && (string) $row->selection_reason === 'Contrat B1 : sélection éditoriale'
        && $metaStatus === 'active' && $auditRows === 1,
        'grant via la couture du thème → service plugin (audit premium_granted = preuve d\'exécution plugin), ligne + méta actives');

    // 3) Lecture : journal récent et comptages cohérents.
    $recent = PremiumService::recent_rows();
    $counts = PremiumService::history_counts();
    $recentHasRow = false;
    foreach ($recent as $recentRow) {
        if ((int) $recentRow->id === (int) $grantId) { $recentHasRow = true; break; }
    }
    $assert('PREM-003', $recentHasRow && ($counts['active'] ?? 0) >= 1
        && array_sum($counts) === (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_premium_history"),
        'lecture : journal récent contient l\'attribution, comptages = comptage SQL direct');

    // 4) Gardes de validation du service (codes d'erreur hérités du thème).
    $e1 = PremiumService::grant(999999999, $adminId, 'motif', $starts, $ends);
    $e2 = PremiumService::grant($propertyId, $adminId, '', $starts, $ends);
    $e3 = PremiumService::grant($propertyId, $adminId, 'motif', $ends, $starts);
    $subscriber = get_user_by('login', 'pk_prem_sub_' . $run);
    if (!$subscriber) {
        $subscriberId = (int) wp_insert_user([
            'user_login' => 'pk_prem_sub_' . $run,
            'user_pass' => wp_generate_password(24),
            'user_email' => "pk-prem-sub-{$run}@example.test",
            'role' => 'subscriber',
        ]);
    } else {
        $subscriberId = (int) $subscriber->ID;
    }
    $e4 = PremiumService::grant($propertyId, $subscriberId, 'motif', $starts, $ends);
    $assert('PREM-004', is_wp_error($e1) && $e1->get_error_code() === 'pk_premium_property'
        && is_wp_error($e2) && $e2->get_error_code() === 'pk_premium_reason'
        && is_wp_error($e3) && $e3->get_error_code() === 'pk_premium_dates'
        && is_wp_error($e4) && $e4->get_error_code() === 'pk_premium_permission',
        'gardes : annonce invalide / motif manquant / période inversée / permission insuffisante → codes hérités');

    // 5) Lecture d'état : attribution active pendant sa période.
    $assert('PREM-005', Partikulier_Premium::is_active($propertyId) === true && PremiumService::is_active($propertyId) === true,
        'is_active vrai par la couture ET par le service pendant la période');

    // 6) Transition critique « révocation premium ».
    $noReason = PremiumService::revoke($propertyId, $adminId, '');
    $revoked = PremiumService::revoke($propertyId, $adminId, 'Contrat B1 : retrait éditorial');
    $revokedRow = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}pk_premium_history WHERE id = %d", (int) $grantId));
    $revokedAudit = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d",
        'premium_revoked',
        $propertyId
    ));
    $assert('PREM-006', is_wp_error($noReason) && $noReason->get_error_code() === 'pk_premium_reason'
        && $revoked === true
        && $revokedRow !== null && (string) $revokedRow->status === 'revoked'
        && (int) $revokedRow->revoked_by === $adminId
        && (string) get_post_meta($propertyId, PremiumService::META_STATUS, true) === 'revoked'
        && $revokedAudit === 1,
        'révocation premium : refus sans motif, ligne fermée (statut, acteur), méta synchronisée, audit journalisé');

    // 7) Transition « expiration paresseuse » : re-grant avec période déjà
    //    échue (starts < ends < maintenant — valide au grant, échue à la lecture).
    $pastStarts = gmdate('Y-m-d H:i:s', time() - 7200);
    $pastEnds = gmdate('Y-m-d H:i:s', time() - 3600);
    $grant2 = PremiumService::grant($propertyId, $adminId, 'Contrat B1 : période échue', $pastStarts, $pastEnds);
    $activeAfterExpiry = is_wp_error($grant2) ? null : Partikulier_Premium::is_active($propertyId);
    $expiredRow = is_wp_error($grant2) ? null : $wpdb->get_row($wpdb->prepare("SELECT * FROM {$prefix}pk_premium_history WHERE id = %d", (int) $grant2));
    $assert('PREM-007', !is_wp_error($grant2) && (int) $grant2 > 0 && $activeAfterExpiry === false
        && $expiredRow !== null && (string) $expiredRow->status === 'expired'
        && (string) $expiredRow->revocation_reason === 'Expiration automatique.'
        && (string) get_post_meta($propertyId, PremiumService::META_STATUS, true) === 'expired',
        'expiration paresseuse : première lecture après échéance → inactive, ligne expirée, méta synchronisée');

    // 8) Propriété du domaine côté plugin : santé du registre.
    $counts2 = PremiumService::history_counts();
    $assert('PREM-008', ($counts2['revoked'] ?? 0) >= 1 && ($counts2['expired'] ?? 0) >= 1,
        'comptages après transitions : revoked ≥ 1, expired ≥ 1 (service = unique écriture)');
} catch (Throwable $error) {
    $assert('PREM-EXCEPTION', false, $error->getMessage());
} finally {
    // Nettoyage : journal, méta, utilisateur jetable, annonce jetable.
    $wpdb->delete($prefix . 'pk_premium_history', ['property_id' => $propertyId], ['%d']);
    $wpdb->delete($prefix . 'pk_audit_log', ['object_type' => 'premium_grant'], ['%s']);
    foreach ([PremiumService::META_STATUS, PremiumService::META_STARTS_AT, PremiumService::META_ENDS_AT] as $metaKey) {
        delete_post_meta($propertyId, $metaKey);
    }
    if (isset($subscriberId) && $subscriberId > 0) {
        require_once ABSPATH . 'wp-admin/includes/user.php';
        $subscriber = get_user_by('id', $subscriberId);
        if ($subscriber) { wp_delete_user($subscriberId); }
    }
    if ($propertyId > 0) { wp_delete_post($propertyId, true); }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
$payload = [
    'test_id' => 'CORE-PREMIUM-CONTRACT-001',
    'candidate_version' => getenv('PK_VERSION') ?: '2.1.0',
    'source_commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local',
    'started_at_utc' => $started,
    'finished_at_utc' => gmdate('c'),
    'command' => 'php partikulier-core/tests/premium-contract.php',
    'fixture' => 'une annonce properties jetable, administrateur du banc',
    'status' => $failed ? 'FAIL' : 'PASS',
    'exit_code' => $failed ? 1 : 0,
    'tests' => $results,
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'limitations' => [
        'la suppression d\'une ligne du journal n\'est pas une opération métier : registre d\'audit, non exposée (décision documentée)',
        'le CRUD de l\'entité abonnement premium (pk_premium_subscriptions) est couvert par payments-contract.php',
    ],
];
printf("%s\n", wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit($failed ? 1 : 0);
