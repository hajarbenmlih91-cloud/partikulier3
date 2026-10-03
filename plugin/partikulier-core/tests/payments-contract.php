<?php
/**
 * Contrat du domaine paiements (lot B1, CDC v1.2 — CA-2).
 *
 * Couvre les entités « commande de paiement » (pk_payment_orders) et
 * « abonnement premium » (pk_premium_subscriptions), propriété du plugin
 * depuis le lot B1 : création, lecture, mise à jour, suppression, et les
 * transitions d'état critiques — paiement échoué, paiement abouti,
 * abonnement activé puis révoqué. La passerelle publique reste fermée :
 * create_order doit continuer de refuser (gate prestataire, WP_Error
 * pk_payment_disabled), par le service ET par la couture du thème.
 *
 * Les transitions sont journalisées au registre d'audit (pk_audit_log) —
 * la présence des entrées payment_order_failed / payment_order_paid /
 * payment_subscription_activated / payment_subscription_revoked fait partie
 * du contrat.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/payments-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\Payments\PaymentService;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
$run = bin2hex(random_bytes(4));
$adminId = 1;
$ref = 'B1-' . strtoupper($run);

/* --- Fixtures : une annonce properties publiée --- */
$propertyId = (int) wp_insert_post([
    'post_type' => 'properties',
    'post_status' => 'publish',
    'post_title' => "PAYMENT-FIXTURE {$run}",
    'post_content' => 'Fixture de contrat paiements.',
    'post_author' => $adminId,
], true);

try {
    // 1) Gate publique préservée : refus côté service ET côté couture thème.
    $gateService = PaymentService::create_order();
    $gateTheme = Partikulier_Payment_Foundation::create_order();
    $assert('PAY-001', is_wp_error($gateService) && $gateService->get_error_code() === 'pk_payment_disabled'
        && is_wp_error($gateTheme) && $gateTheme->get_error_code() === 'pk_payment_disabled'
        && PaymentService::is_gateway_enabled() === false,
        'gate paiement fermée : create_order refuse par le service ET par la couture du thème (contrat hérité)');

    // 2) Couture : mêmes tables des deux côtés.
    $assert('PAY-002', Partikulier_Payment_Foundation::orders_table() === PaymentService::orders_table()
        && Partikulier_Payment_Foundation::subscriptions_table() === PaymentService::subscriptions_table(),
        'couture thème/plugin : mêmes noms de tables orders et subscriptions');

    // 3) Création (commande) : defaults, colonnes, NULL de la référence.
    $orderId = PaymentService::record_order([
        'property_id' => $propertyId,
        'owner_id' => $adminId,
        'amount_minor' => 25000,
        'provider' => 'cmi',
        'provider_order_ref' => $ref,
        'purpose' => 'premium_visibility',
        'metadata' => ['origin' => 'contract'],
    ]);
    $order = is_wp_error($orderId) ? null : PaymentService::get_order((int) $orderId);
    $assert('PAY-003', !is_wp_error($orderId) && (int) $orderId > 0 && $order !== null
        && $order->status === 'pending' && (int) $order->amount_minor === 25000
        && $order->provider === 'cmi' && $order->provider_order_ref === $ref
        && $order->currency === 'MAD' && (string) $order->metadata !== ''
        && trim((string) $order->created_at) !== '' && trim((string) $order->updated_at) !== '',
        'record_order : commande pending avec defaults MAD/premium_visibility, montants et métadonnées persistés');

    // 4) Gardes de validation (commande).
    $e1 = PaymentService::record_order(['property_id' => 999999999, 'owner_id' => $adminId]);
    $e2 = PaymentService::record_order(['property_id' => $propertyId, 'owner_id' => 424242]);
    $e3 = PaymentService::record_order(['property_id' => $propertyId, 'owner_id' => $adminId, 'status' => 'whatever']);
    $e4 = PaymentService::record_order(['property_id' => $propertyId, 'owner_id' => $adminId, 'provider' => 'cmi', 'provider_order_ref' => $ref]);
    $assert('PAY-004', is_wp_error($e1) && $e1->get_error_code() === 'pk_payment_property'
        && is_wp_error($e2) && $e2->get_error_code() === 'pk_payment_owner'
        && is_wp_error($e3) && $e3->get_error_code() === 'pk_payment_status'
        && is_wp_error($e4) && $e4->get_error_code() === 'pk_payment_duplicate_ref',
        'gardes commande : annonce inconnue / propriétaire inconnu / statut invalide / référence dupliquée');

    // 5) Mise à jour (commande) : liste blanche + horodatage + statut contrôlé.
    $before = (string) $order->updated_at;
    sleep(1);
    $updated = PaymentService::update_order((int) $orderId, ['amount_minor' => 30000, 'purpose' => 'boost_7j']);
    $orderAfter = PaymentService::get_order((int) $orderId);
    $badStatus = PaymentService::update_order((int) $orderId, ['status' => 'nope']);
    $noop = PaymentService::update_order((int) $orderId, ['ignored_column' => 'x']);
    $assert('PAY-005', $updated === true && (int) $orderAfter->amount_minor === 30000
        && $orderAfter->purpose === 'boost_7j' && (string) $orderAfter->updated_at > $before
        && is_wp_error($badStatus) && is_wp_error($noop),
        'update_order : champs en liste blanche, updated_at avancé, statut invalide et no-op refusés');

    // 6) Transition critique « paiement échoué ».
    $failed = PaymentService::mark_order_failed((int) $orderId, 'Prestataire : 3DS refusé');
    $failedOrder = PaymentService::get_order((int) $orderId);
    $failedMeta = (array) json_decode((string) $failedOrder->metadata, true);
    $failedAudit = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d",
        'payment_order_failed',
        (int) $orderId
    ));
    $failedToPaid = PaymentService::mark_order_paid((int) $orderId);
    $missing = PaymentService::mark_order_failed(999999999, 'x');
    $assert('PAY-006', $failed === true && $failedOrder->status === 'failed'
        && ($failedMeta['failure_reason'] ?? '') === 'Prestataire : 3DS refusé'
        && $failedAudit === 1
        && is_wp_error($failedToPaid) && $failedToPaid->get_error_code() === 'pk_payment_transition'
        && is_wp_error($missing) && $missing->get_error_code() === 'pk_payment_not_found',
        'paiement échoué : statut failed, motif en métadonnées, audit journalisé, remontée aboutie interdite');

    // 7) Transition « paiement abouti » (sur une seconde commande).
    $order2Id = PaymentService::record_order([
        'property_id' => $propertyId,
        'owner_id' => $adminId,
        'provider_order_ref' => $ref . '-B',
    ]);
    $paid = PaymentService::mark_order_paid((int) $order2Id, $ref . '-B-PAID');
    $paidOrder = PaymentService::get_order((int) $order2Id);
    $paidAudit = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d",
        'payment_order_paid',
        (int) $order2Id
    ));
    $paidToFailed = PaymentService::mark_order_failed((int) $order2Id, 'trop tard');
    $assert('PAY-007', $paid === true && $paidOrder->status === 'paid'
        && $paidOrder->provider_order_ref === $ref . '-B-PAID' && $paidAudit === 1
        && is_wp_error($paidToFailed) && $paidToFailed->get_error_code() === 'pk_payment_transition',
        'paiement abouti : statut paid, référence consolidée, audit journalisé, retour en échec interdit');

    // 8) Abonnement : création rattachée + activation.
    $subId = PaymentService::create_subscription([
        'property_id' => $propertyId,
        'owner_id' => $adminId,
        'payment_order_id' => (int) $order2Id,
    ]);
    $sub = is_wp_error($subId) ? null : PaymentService::get_subscription((int) $subId);
    $starts = gmdate('Y-m-d H:i:s', time() + 3600);
    $ends = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
    $activated = is_wp_error($subId) ? null : PaymentService::activate_subscription((int) $subId, $starts, $ends);
    $subActive = is_wp_error($subId) ? null : PaymentService::get_subscription((int) $subId);
    $activatedAudit = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d",
        'payment_subscription_activated',
        (int) $subId
    ));
    $assert('PAY-008', !is_wp_error($subId) && (int) $subId > 0 && $sub !== null
        && $sub->status === 'disabled' && $sub->plan_key === 'premium_visibility'
        && (int) $sub->payment_order_id === (int) $order2Id && $sub->starts_at === null
        && $activated === true && $subActive->status === 'active'
        && (string) $subActive->starts_at === $starts && (string) $subActive->ends_at === $ends
        && $activatedAudit === 1,
        'abonnement : création disabled rattachée à la commande payée, activation avec période, audit journalisé');

    // 9) Gardes + mise à jour d'abonnement.
    $sub2 = PaymentService::create_subscription([
        'property_id' => 999999999, 'owner_id' => $adminId,
    ]);
    $sub3 = PaymentService::create_subscription([
        'property_id' => $propertyId, 'owner_id' => $adminId, 'payment_order_id' => 999999999,
    ]);
    $sub4 = PaymentService::create_subscription([
        'property_id' => $propertyId, 'owner_id' => $adminId,
        'starts_at' => $ends, 'ends_at' => $starts,
    ]);
    $badSubStatus = is_wp_error($subId) ? null : PaymentService::update_subscription((int) $subId, ['status' => 'nope']);
    $planUpdated = is_wp_error($subId) ? null : PaymentService::update_subscription((int) $subId, ['plan_key' => 'boost_30j']);
    $subPlan = is_wp_error($subId) ? null : PaymentService::get_subscription((int) $subId);
    $assert('PAY-009', is_wp_error($sub2) && $sub2->get_error_code() === 'pk_premium_property'
        && is_wp_error($sub3) && $sub3->get_error_code() === 'pk_payment_not_found'
        && is_wp_error($sub4) && $sub4->get_error_code() === 'pk_premium_dates'
        && is_wp_error($badSubStatus) && $planUpdated === true && $subPlan->plan_key === 'boost_30j',
        'gardes abonnement : annonce inconnue, commande de rattachement inconnue, période inversée ; mise à jour plan_key');

    // 10) Transition critique « révocation premium » d'abonnement + suppression
    //     protégée puis effective (CRUD complet des deux entités).
    $noReason = PaymentService::revoke_subscription((int) $subId, '');
    $revoked = PaymentService::revoke_subscription((int) $subId, 'Contrat B1 : remboursement');
    $subRevoked = PaymentService::get_subscription((int) $subId);
    $revokeAudit = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d",
        'payment_subscription_revoked',
        (int) $subId
    ));
    $deleteLinked = PaymentService::delete_order((int) $order2Id);
    $deleteSub = PaymentService::delete_subscription((int) $subId);
    $deleteOrder2 = PaymentService::delete_order((int) $order2Id);
    $deleteOrder1 = PaymentService::delete_order((int) $orderId);
    $gone = PaymentService::get_order((int) $orderId) === null && PaymentService::get_subscription((int) $subId) === null;
    $assert('PAY-010', is_wp_error($noReason) && $revoked === true && $subRevoked->status === 'revoked'
        && $revokeAudit === 1
        && is_wp_error($deleteLinked) && $deleteLinked->get_error_code() === 'pk_payment_order_in_use'
        && $deleteSub === true && $deleteOrder2 === true && $deleteOrder1 === true && $gone,
        'révocation abonnement (motif obligatoire, audit) ; suppression : commande liée protégée, puis abonnement et commandes supprimés — CRUD complet');
} catch (Throwable $error) {
    $assert('PAY-EXCEPTION', false, $error->getMessage());
} finally {
    // Nettoyage ceinture+bretelles : commandes/abonnements de la fixture + audit du lot.
    $wpdb->delete($prefix . 'pk_premium_subscriptions', ['property_id' => $propertyId], ['%d']);
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$prefix}pk_payment_orders WHERE property_id = %d OR provider_order_ref LIKE %s",
        $propertyId,
        $ref . '%'
    ));
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$prefix}pk_audit_log WHERE action IN (%s, %s, %s, %s) AND object_id IN (%d, %d, %d)",
        'payment_order_failed',
        'payment_order_paid',
        'payment_subscription_activated',
        'payment_subscription_revoked',
        (int) ($orderId ?? 0),
        (int) ($order2Id ?? 0),
        (int) ($subId ?? 0)
    ));
    if ($propertyId > 0) { wp_delete_post($propertyId, true); }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
$payload = [
    'test_id' => 'CORE-PAYMENTS-CONTRACT-001',
    'candidate_version' => getenv('PK_VERSION') ?: '2.1.0',
    'source_commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local',
    'started_at_utc' => $started,
    'finished_at_utc' => gmdate('c'),
    'command' => 'php partikulier-core/tests/payments-contract.php',
    'fixture' => 'une annonce properties jetable, deux commandes, un abonnement',
    'status' => $failed ? 'FAIL' : 'PASS',
    'exit_code' => $failed ? 1 : 0,
    'tests' => $results,
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'limitations' => [
        'la passerelle publique (create_order) reste fermée : aucune voie prestataire n\'est activée au lot B1 (gate G-paiement)',
        'l\'ingestion record_order est la primitive interne des futures voies callback ; ici exercée par le contrat',
    ],
];
printf("%s\n", wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit($failed ? 1 : 0);
