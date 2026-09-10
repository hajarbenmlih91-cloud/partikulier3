<?php
/**
 * Scénario de charge REG-1 (lot A) : import de 500 posts puis 50 validations.
 *
 * Garde exigée par le CDC : le nombre d'écritures pk_listings est exactement
 * égal au nombre attendu (ni plus ni moins), aucune boucle détectée au
 * compteur anti-boucle, aucune erreur fatale, latence mesurée et jointe.
 * La « concurrence » des 50 validations est simulée en série dans un même
 * processus PHP : chaque itération passe par les hooks réels (comme un clic
 * administratif), le vidage en lot dédupliqué s'applique, et SQLite sérialise
 * de toute façon les écritures entre processus.
 *
 * Rejouable : PK_WP_DIR=... php partikulier-core/tests/load-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\DomainRegistry;
use Partikulier\Core\Integration\ListingSynchronizer;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$sync = new ListingSynchronizer();
$sync->register();
global $wpdb;
$prefix = $wpdb->prefix;
$tag = 'PKLOAD-' . bin2hex(random_bytes(4));
$ids = [];

try {
    // --- Phase 1 : import de 500 posts en un seul processus ---
    $t0 = microtime(true);
    for ($i = 0; $i < 500; $i++) {
        $id = wp_insert_post([
            'post_type' => 'properties',
            'post_status' => 'pending',
            'post_title' => "{$tag} annonce {$i}",
            'post_content' => "Import en masse {$tag} {$i}.",
            'post_author' => 1,
        ], true);
        if (is_int($id) && $id > 0) {
            $ids[] = $id;
            update_post_meta($id, 'es_property_price', 100000.0 + $i);
            update_post_meta($id, 'es_property_area', 50.0 + $i);
        }
    }
    $importReport = $sync->flush();
    $importSeconds = microtime(true) - $t0;

    $inserted = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$prefix}pk_listings l WHERE l.title LIKE '" . $wpdb->esc_like($tag) . "%'"
    );
    $assert('LOAD-001', count($ids) === 500 && $importReport['inserts'] === 500 && $importReport['updates'] === 0 && $inserted === 500,
        "import 500 posts → exactement 500 écritures (inserts={$importReport['inserts']}, updates={$importReport['updates']}), aucune de plus");
    $assert('LOAD-001b', $importReport['skipped_loop'] === 0, 'aucune écriture bloquée par le plafond anti-boucle pendant l’import');

    // --- Phase 2 : 50 validations (publication) ---
    $t1 = microtime(true);
    $toPublish = array_slice($ids, 0, 50);
    foreach ($toPublish as $id) {
        wp_update_post(['ID' => $id, 'post_status' => 'publish']);
    }
    $validationReport = $sync->flush();
    $validationSeconds = microtime(true) - $t1;

    $published = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$prefix}pk_listings l WHERE l.title LIKE '" . $wpdb->esc_like($tag) . "%' AND l.status = 'published'"
    );
    $assert('LOAD-002', $validationReport['updates'] === 50 && $published === 50,
        "50 validations → exactement 50 mises à jour (updates={$validationReport['updates']}, published={$published}), aucune de plus");
    $assert('LOAD-002b', $validationReport['skipped_loop'] === 0, 'aucune boucle pendant les validations');

    // --- Phase 3 : intégrité et santé après charge ---
    $integrity = DomainRegistry::listingIntegrity();
    $taggedPosts = (int) $wpdb->get_var(
        $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'properties' AND post_title LIKE %s", $wpdb->esc_like($tag) . '%')
    );
    $missing = $taggedPosts - $inserted;
    $assert('LOAD-003', $integrity['orphans'] === 0 && $missing === 0,
        'intégrité après charge : zéro orphelin, aucun post de test sans projection');

    $assert('LOAD-004', true, sprintf(
        'latences mesurées : import 500 posts = %.1f s, 50 validations = %.1f s (banc SQLite, processus unique)',
        $importSeconds,
        $validationSeconds
    ));
} catch (Throwable $error) {
    $assert('LOAD-EXCEPTION', false, $error->getMessage());
} finally {
    // Nettoyage complet : suppression des 500 posts (la synchronisation
    // supprime les lignes par les hooks, exactement comme en production).
    $deleteStart = microtime(true);
    foreach ($ids as $id) {
        wp_delete_post($id, true);
    }
    $sync->flush();
    $remaining = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM {$prefix}pk_listings l WHERE l.title LIKE '" . $wpdb->esc_like($tag) . "%'"
    );
    if ($remaining > 0) {
        $wpdb->query(
            "DELETE FROM {$prefix}pk_listings WHERE title LIKE '" . $wpdb->esc_like($tag) . "%'"
        );
    }
    $results[] = [
        'test_id' => 'LOAD-CLEANUP',
        'status' => $remaining === 0 ? 'PASS' : 'FAIL',
        'detail' => sprintf('nettoyage : %d posts supprimés, %d lignes restantes (%.1f s)', count($ids), $remaining, microtime(true) - $deleteStart),
    ];
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
$payload = [
    'test_id' => 'CORE-LOAD-CONTRACT-001',
    'candidate_version' => getenv('PK_VERSION') ?: '2.0.0',
    'source_commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local',
    'started_at_utc' => $started,
    'finished_at_utc' => gmdate('c'),
    'command' => 'php partikulier-core/tests/load-contract.php',
    'fixture' => '500 posts properties importés en masse puis 50 validations (banc SQLite)',
    'status' => $failed ? 'FAIL' : 'PASS',
    'exit_code' => $failed ? 1 : 0,
    'tests' => $results,
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'limitations' => ['validations en série dans un processus (SQLite sérialise les écritures inter-processus de toute façon)', 'latences indicatives du banc de développement, non contractuelles'],
];
printf("%s\n", wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit($failed ? 1 : 0);
