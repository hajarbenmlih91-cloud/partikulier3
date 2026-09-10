<?php
/**
 * Contrat de synchronisation INTEG-1 (lot A) — projection pk_listings
 * maintenue en temps réel depuis les posts properties.
 *
 * Couvre : création/publication/dépublication/refus/suppression, propagation
 * du prix, écriture sur différence uniquement, garde anti-fantôme dans find()
 * et search(), réparation reconcile(), idempotence du rebuild (REG-6), double
 * exécution du Migrator, plafond anti-boucle (REG-1), déduplication du lot.
 *
 * Rejouable : PK_WP_DIR=... php partikulier-core/tests/sync-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Database\Migrator;
use Partikulier\Core\Domain\DomainRegistry;
use Partikulier\Core\Integration\ListingSynchronizer;
use Partikulier\Core\ListingRepository;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$sync = new ListingSynchronizer();
$sync->register();
$repo = new ListingRepository();
global $wpdb;
$prefix = $wpdb->prefix;

/* --- Fixture : un post properties de test --- */
$fixtureId = wp_insert_post([
    'post_type' => 'properties',
    'post_status' => 'draft',
    'post_title' => 'SYNC-FIXTURE contrat synchronisation',
    'post_content' => 'Fixture de contrat — description.',
    'post_author' => 1,
], true);
update_post_meta($fixtureId, 'es_property_price', 500000.0);
update_post_meta($fixtureId, 'es_property_area', 120.0);
$fixtureExternal = ListingSynchronizer::EXTERNAL_PREFIX . $fixtureId;
$rowId = 0;

try {
    // 1) Création : la projection suit le post.
    $sync->flush();
    $row = $repo->rowForExternalId($fixtureExternal);
    $assert('SYNC-001', is_array($row) && $row['status'] === 'draft' && (float) $row['price'] === 500000.0 && (float) $row['area'] === 120.0,
        'création → projection draft avec prix et surface');
    $rowId = (int) ($row['id'] ?? 0);

    // 2) Publication : servie (la locale suit le post — jamais en dur).
    wp_update_post(['ID' => $fixtureId, 'post_status' => 'publish']);
    $sync->flush();
    $row = $repo->rowForExternalId($fixtureExternal);
    $rowLocale = is_array($row) ? (string) $row['locale'] : 'fr';
    $served = $repo->search($rowLocale, 'newest', 1, 100);
    $assert('SYNC-002', is_array($row) && $row['status'] === 'published' && in_array((int) $row['id'], array_map(static fn($r) => (int) $r['id'], $served), true)
        && !is_wp_error($repo->find($rowId)), 'publication → statut published + servie en collection et en lecture');

    // 3) Changement de prix : propagation immédiate.
    update_post_meta($fixtureId, 'es_property_price', 400000.0);
    $sync->flush();
    $row = $repo->rowForExternalId($fixtureExternal);
    $assert('SYNC-003', is_array($row) && abs((float) $row['price'] - 400000.0) < 0.001, 'changement de prix propagé');

    // 4) Écriture sur différence uniquement : re-save sans changement → aucun update.
    $sync->onSavePost($fixtureId, get_post($fixtureId));
    $report = $sync->flush();
    $assert('SYNC-004', $report['upserts'] === 1 && $report['updates'] === 0 && $report['inserts'] === 0,
        'aucune écriture sans différence (upserts=1, updates=0)');

    // 5) Dépublication : plus servie, lecture 404.
    wp_update_post(['ID' => $fixtureId, 'post_status' => 'draft']);
    $sync->flush();
    $row = $repo->rowForExternalId($fixtureExternal);
    $find = $repo->find($rowId);
    $assert('SYNC-005', is_array($row) && $row['status'] === 'draft' && is_wp_error($find) && $find->get_error_code() === 'listing_not_found',
        'dépublication → draft, lecture 404');

    // 6) Refus de modération : statut rejected, non servie.
    wp_update_post(['ID' => $fixtureId, 'post_status' => 'publish']);
    update_post_meta($fixtureId, '_pk_status', 'refuse');
    $sync->flush();
    $row = $repo->rowForExternalId($fixtureExternal);
    $find = $repo->find($rowId);
    $assert('SYNC-006', is_array($row) && $row['status'] === 'rejected' && is_wp_error($find),
        'refus de modération → rejected, non servie');
    update_post_meta($fixtureId, '_pk_status', '');

    // 7) Suppression : la ligne disparaît, lecture 404.
    wp_delete_post($fixtureId, true);
    $fixtureId = 0;
    $sync->flush();
    $row = $repo->rowForExternalId($fixtureExternal);
    $assert('SYNC-007', $row === null && is_wp_error($repo->find($rowId)), 'suppression du post → ligne supprimée, 404');

    // 8) Scénario fantôme de référence (CDC 2.3.1) : ligne publiée sans post.
    $wpdb->insert($prefix . 'pk_listings', [
        'owner_user_id' => 1, 'external_id' => $fixtureExternal, 'status' => 'published', 'locale' => 'fr',
        'title' => 'FANTÔME test', 'description' => 'ligne sans post', 'price' => 1.0, 'area' => 1.0,
        'created_at' => gmdate('Y-m-d H:i:s'), 'updated_at' => gmdate('Y-m-d H:i:s'),
    ]);
    $ghostId = (int) $wpdb->insert_id;
    $integrity = DomainRegistry::listingIntegrity();
    $ghostFind = $repo->find($ghostId);
    $ghostServed = in_array($ghostId, array_map(static fn($r) => (int) $r['id'], $repo->search('fr', 'newest', 1, 100)), true);
    $assert('SYNC-008', $integrity['orphans'] >= 1 && is_wp_error($ghostFind) && ! $ghostServed,
        'fantôme : détecté (orphans ≥ 1), jamais servi (find 404 + search exclue)');
    $health = (new \Partikulier\Core\HealthCheck())->get();
    $assert('SYNC-008b', $health['status'] === 'degraded' && $health['integrity']['orphans'] >= 1, 'health 2.0 signale l’écart');
    $repair = $sync->reconcile();
    $integrityAfter = DomainRegistry::listingIntegrity();
    $assert('SYNC-009', $repair['ghosts_removed'] >= 1 && $integrityAfter['orphans'] === 0 && is_wp_error($repo->find($ghostId)),
        'reconcile() purge le fantôme, intégrité rétablie');

    // 9) Rebuild journalisé et idempotent (REG-6).
    $rebuildOne = $sync->rebuild();
    $rebuildTwo = $sync->rebuild();
    $assert('SYNC-010', $rebuildOne['after'] === $rebuildTwo['after'] && DomainRegistry::listingIntegrity()['orphans'] === 0,
        'rebuild rejoué → résultat identique, zéro orphelin');

    // 10) Migrator idempotent : double exécution sans effet de bord (REG-6).
    $beforeCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_listings");
    $migrator = new Migrator();
    $migrator->migrate();
    $migrator->migrate();
    $afterCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$prefix}pk_listings");
    $tables = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $prefix . 'pk_listings'));
    $assert('SYNC-011', $beforeCount === $afterCount && $tables === $prefix . 'pk_listings' && $migrator->currentVersion() === \Partikulier\Core\Database\Schema::VERSION,
        'Migrator ×2 : comptages inchangés, version stable');

    // 11) Plafond anti-boucle (REG-1) : écriture refusée au-delà du cap.
    $capId = wp_insert_post(['post_type' => 'properties', 'post_status' => 'draft', 'post_title' => 'SYNC-CAP', 'post_content' => 'x'], true);
    set_transient('pk_sync_w_' . $capId, ListingSynchronizer::MAX_WRITES_PER_MINUTE, 60);
    $sync->onSavePost($capId, get_post($capId));
    $report = $sync->flush();
    $capped = $repo->rowForExternalId(ListingSynchronizer::EXTERNAL_PREFIX . $capId) === null;
    $assert('SYNC-012', $report['skipped_loop'] === 1 && $capped, 'plafond anti-boucle : écriture bloquée et signalée');
    delete_transient('pk_sync_w_' . $capId);
    wp_delete_post($capId, true);
    $sync->flush();

    // 12) File dédupliquée : plusieurs événements, une écriture (REG-1).
    $batchId = wp_insert_post(['post_type' => 'properties', 'post_status' => 'draft', 'post_title' => 'SYNC-BATCH', 'post_content' => 'x'], true);
    $sync->flush(); // vidage de la création
    $statsBefore = $sync->stats();
    $sync->onSavePost($batchId, get_post($batchId));
    $sync->onSavePost($batchId, get_post($batchId));
    $sync->onTransitionStatus('draft', 'draft', get_post($batchId));
    $sync->onMetaChange(0, $batchId, 'es_property_price', 42);
    $report = $sync->flush();
    $assert('SYNC-013', $report['upserts'] === 1 && $report['updates'] === 0 && $report['deletes'] === 0,
        'quatre événements identiques → exactement une écriture dédupliquée');
    wp_delete_post($batchId, true);
    $sync->flush();
} finally {
    if ($fixtureId) {
        wp_delete_post($fixtureId, true);
    }
    $wpdb->delete($prefix . 'pk_listings', ['external_id' => $fixtureExternal], ['%s']);
    $sync->flush();
    delete_option('partikulier_core_route_collisions');
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
$payload = [
    'test_id' => 'CORE-SYNC-CONTRACT-001',
    'candidate_version' => getenv('PK_VERSION') ?: '2.0.0',
    'source_commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local',
    'started_at_utc' => $started,
    'finished_at_utc' => gmdate('c'),
    'command' => 'php partikulier-core/tests/sync-contract.php',
    'fixture' => 'WordPress runtime, post properties jetable',
    'status' => $failed ? 'FAIL' : 'PASS',
    'exit_code' => $failed ? 1 : 0,
    'tests' => $results,
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'limitations' => ['concurrence multi-process évaluée dans tests/load-contract.php', 'APCu absent du banc : invalidation de cache non couverte'],
];
printf("%s\n", wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit($failed ? 1 : 0);
