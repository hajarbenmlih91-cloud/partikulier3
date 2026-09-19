<?php
/**
 * Contrat du domaine listings — redirections d'anciens slugs (micro-lot
 * pré-prod 2.10.8/6.20.7, E-4303 — partie schéma + API du plugin).
 *
 * La table pk_slug_redirects (schéma 2.7.0) est NEUVE : aucune reprise du
 * thème, aucune migration de données. Ce contrat verrouille :
 *  - E43-001 : versions — schéma 2.7.0 partout (constante, option installée,
 *    health check), plugin 2.10.8 / thème 6.20.7 (bump de fin de train
 *    appliqué) ;
 *  - E43-002 : table présente avec la structure attendue (UNIQUE KEY slug,
 *    KEY property_id — colonnes slug/property_id/created_at/updated_at) ;
 *  - E43-003 : manifeste 21 tables pk_, pk_slug_redirects owner=plugin
 *    rattachée au domaine listings, 0 côté thème, 8/8 domaines plugin
 *    (aucun nouveau domaine), health listings 4/4 tables ;
 *  - E43-004 : migration journalisée (audit slug_redirects_table_created,
 *    lot ML) et idempotente (rejeu de migrate() : zéro étape, empreinte de
 *    structure inchangée — même canal dbDelta que les lots A/B, REG-6) ;
 *  - E43-005 : enregistrement + résolution (l'ancien slug mène à l'ID de
 *    l'annonce) + upsert (un slug redirigé vers une autre annonce remplace
 *    la ligne — une seule ligne par slug) ;
 *  - E43-006 : deux renommages successifs → les DEUX anciens slugs mènent à
 *    la même annonce (l'ID est stocké, jamais le slug suivant : pas de
 *    chaîne A→B→C, le permalink est calculé à la résolution) ;
 *  - E43-007 : slug (re)pris par une annonce → la redirection disparaît
 *    (200 nouvelle fiche, 0 résidu) ; suppression définitive → 0 ligne
 *    orpheline pour l'annonce ;
 *  - E43-008 : gardes (ID nul, slug vide → refus/nettoyé, aucune ligne
 *    écrite) + journal d'audit slug_redirect_recorded présent ;
 *  - E43-009 : sortie propre — le contrat purge ses lignes (table vide en
 *    sortie, aucune trace pour les suites suivantes).
 *
 * Périmètre : stockage + API uniquement. Le bout-en-bout HTTP (301/410/404,
 * gate cache E-4302) relève de la batterie E-4304 du lot SE-043 côté thème.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/slug-redirects-domain-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Database\Migrator;
use Partikulier\Core\Database\Schema;
use Partikulier\Core\Domain\DomainRegistry;
use Partikulier\Core\Domain\SlugRedirects\SlugRedirectsService;
use Partikulier\Core\HealthCheck;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

/** Empreinte de structure au sens du Migrator (SHOW CREATE TABLE normalisé). */
$structureHash = static function (string $table): string {
    global $wpdb;
    $create = $wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_N);
    $ddl = is_array($create) && isset($create[1]) && is_string($create[1]) ? $create[1] : '';
    return $ddl !== '' ? hash('sha256', preg_replace('/\s+/', ' ', $ddl)) : 'unavailable';
};

try {
    global $wpdb;
    $table = SlugRedirectsService::redirects_table();

    // 1) Versions : schéma 2.7.0 (constante, option, health), versions d'artefact figées hors bump.
    $health = (new HealthCheck())->get();
    $pluginDomains = count(array_filter($health['domains'] ?? [], static fn($d) => ($d['owner'] ?? '') === 'plugin'));
    $assert('E43-001', Schema::VERSION === '2.7.0'
        && (new Migrator())->currentVersion() === '2.7.0'
        && ($health['schema_version'] ?? '') === '2.7.0'
        && PARTIKULIER_CORE_VERSION === '2.10.8'
        && wp_get_theme()->get('Version') === '6.20.7',
        sprintf('versions : schéma %s (constante), %s installée, %s au health, plugin %s, thème %s (bump de fin de train appliqué)',
            Schema::VERSION, (new Migrator())->currentVersion(), $health['schema_version'] ?? '?',
            PARTIKULIER_CORE_VERSION, wp_get_theme()->get('Version')));

    // 2) Table présente + structure (UNIQUE slug, KEY property_id, colonnes attendues).
    $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    $ddl = '';
    if ($exists) {
        $create = $wpdb->get_row("SHOW CREATE TABLE {$table}", ARRAY_N);
        $ddl = is_array($create) && isset($create[1]) && is_string($create[1]) ? $create[1] : '';
    }
    $columnsOk = true;
    if ($exists) {
        foreach (['slug', 'property_id', 'created_at', 'updated_at'] as $column) {
            if (strpos($ddl, $column) === false) { $columnsOk = false; }
        }
    }
    // Clés : tolérantes aux backticks du DDL canonique MySQL retourné par le
    // traducteur SQLite du banc comme par MySQL (cf. Migrator::adoptTable).
    $uniqueSlug = (bool) preg_match('/UNIQUE\s+KEY\s+`?slug`?\s*\(/i', $ddl);
    $keyProperty = (bool) preg_match('/KEY\s+`?property_id`?\s*\(/i', $ddl);
    $assert('E43-002', $exists && $columnsOk && $uniqueSlug && $keyProperty,
        sprintf('table %s : %s, colonnes %s, UNIQUE KEY slug %s, KEY property_id %s',
            $table, $exists ? 'présente' : 'ABSENTE', $columnsOk ? 'ok' : 'incomplètes',
            $uniqueSlug ? 'ok' : 'absent', $keyProperty ? 'ok' : 'absent'));

    // 3) Manifeste : 21 tables, pk_slug_redirects plugin/listings/ML, 0 thème, 8/8 domaines.
    $manifest = Schema::domainTables();
    $themeOwned = array_filter($manifest, static fn(array $d) => ($d['owner'] ?? '') === 'theme');
    $entry = $manifest['pk_slug_redirects'] ?? [];
    $listingsHealth = $health['domains']['listings']['tables'] ?? [];
    $slugTableHealthy = ($listingsHealth['pk_slug_redirects'] ?? false) === true;
    $assert('E43-003', count($manifest) === 21 && $themeOwned === []
        && ($entry['owner'] ?? '') === 'plugin' && ($entry['domain'] ?? '') === 'listings' && ($entry['lot'] ?? '') === 'ML'
        && $pluginDomains === 8 && $slugTableHealthy,
        sprintf('manifeste : %d/21 tables pk_, 0 côté thème, pk_slug_redirects owner=%s domain=%s lot=%s, %d/8 domaines plugin, health listings %s',
            count($manifest), $entry['owner'] ?? '?', $entry['domain'] ?? '?', $entry['lot'] ?? '?',
            $pluginDomains, $slugTableHealthy ? '4/4 tables' : 'slug_redirects manquante'));

    // 4) Migration journalisée + idempotente (même canal dbDelta, REG-6).
    $auditRow = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS n FROM {$wpdb->prefix}pk_audit_log WHERE action = %s AND metadata_json LIKE %s",
        'slug_redirects_table_created',
        '%"lot":"ML"%'
    ));
    $auditOk = is_object($auditRow) && (int) $auditRow->n > 0;
    $hashBefore = $structureHash($table);
    $replay = (new Migrator())->migrate();
    $hashAfter = $structureHash($table);
    $assert('E43-004', $auditOk && ($replay['schema'] ?? '') === '2.7.0' && ($replay['steps'] ?? null) === []
        && $hashBefore !== 'unavailable' && $hashBefore === $hashAfter,
        sprintf('migration : audit slug_redirects_table_created %s, rejeu migrate() → schéma %s, %d étape(s), empreinte de structure %s',
            $auditOk ? 'présent (lot ML)' : 'ABSENT', $replay['schema'] ?? '?', count($replay['steps'] ?? []),
            $hashBefore === $hashAfter ? 'inchangée' : 'MODIFIÉE'));

    // 5) Enregistrement + résolution + upsert (une seule ligne par slug).
    SlugRedirectsService::clear_redirect('ml02-ancien-slug-a');
    SlugRedirectsService::clear_redirect('ml02-ancien-slug-b');
    SlugRedirectsService::clear_redirect('ml02-ancien-slug-c');
    SlugRedirectsService::delete_for_property(990001);
    SlugRedirectsService::delete_for_property(990002);
    $recordedA = SlugRedirectsService::record_redirect(990001, 'ml02-ancien-slug-a');
    $resolvedA = SlugRedirectsService::resolve_redirect('ml02-ancien-slug-a');
    $replaced = SlugRedirectsService::record_redirect(990002, 'ml02-ancien-slug-a');
    $resolvedReplaced = SlugRedirectsService::resolve_redirect('ml02-ancien-slug-a');
    $rowsForSlug = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE slug = %s", 'ml02-ancien-slug-a'));
    $assert('E43-005', $recordedA && $replaced && $resolvedA === 990001 && $resolvedReplaced === 990002 && $rowsForSlug === 1,
        sprintf('enregistrement/résolution : record(990001)=%s → resolve=%s ; upsert vers 990002=%s → resolve=%s, %d ligne pour le slug',
            $recordedA ? 'ok' : 'refusé', var_export($resolvedA, true), $replaced ? 'ok' : 'refusé',
            var_export($resolvedReplaced, true), $rowsForSlug));

    // 6) Deux renommages : les deux anciens slugs mènent à la même annonce (pas de chaîne A→B→C).
    SlugRedirectsService::record_redirect(990001, 'ml02-ancien-slug-b');
    SlugRedirectsService::record_redirect(990001, 'ml02-ancien-slug-c');
    $resolveB = SlugRedirectsService::resolve_redirect('ml02-ancien-slug-b');
    $resolveC = SlugRedirectsService::resolve_redirect('ml02-ancien-slug-c');
    $assert('E43-006', $resolveB === 990001 && $resolveC === 990001,
        sprintf('double renommage : ancien-slug-b → %s, ancien-slug-c → %s (les deux mènent à l\'annonce 990001 — ID stocké, jamais le slug suivant)',
            var_export($resolveB, true), var_export($resolveC, true)));

    // 7) Slug repris → 0 redirection résiduelle ; suppression définitive → 0 ligne orpheline.
    //    État hérité de E43-006 : a→990002, b→990001, c→990001.
    $cleared = SlugRedirectsService::clear_redirect('ml02-ancien-slug-b');
    $resolveAfterClear = SlugRedirectsService::resolve_redirect('ml02-ancien-slug-b');
    SlugRedirectsService::record_redirect(990001, 'ml02-ancien-slug-d');
    $purged = SlugRedirectsService::delete_for_property(990001); // retire c (E43-006) + d
    $orphans = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE property_id = %d", 990001));
    $resolveC = SlugRedirectsService::resolve_redirect('ml02-ancien-slug-c');
    $assert('E43-007', $cleared >= 1 && $resolveAfterClear === null && $purged >= 1 && $orphans === 0 && $resolveC === null,
        sprintf('slug repris : clear=%d ligne(s), resolve=%s ; suppression définitive : purge=%d ligne(s) (c + d), orphelins pour 990001=%d, resolve(c)=%s',
            $cleared, var_export($resolveAfterClear, true), $purged, $orphans, var_export($resolveC, true)));

    // 8) Gardes + audit des mutations.
    $beforeCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $guardId = SlugRedirectsService::record_redirect(0, 'ml02-garde');
    $guardSlug = SlugRedirectsService::record_redirect(990001, '');
    $guardResolve = SlugRedirectsService::resolve_redirect('');
    $afterCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $auditMutation = $wpdb->get_row($wpdb->prepare(
        "SELECT COUNT(*) AS n FROM {$wpdb->prefix}pk_audit_log WHERE action = %s AND object_id = %d",
        'slug_redirect_recorded', 990001
    ));
    $mutationAudited = is_object($auditMutation) && (int) $auditMutation->n > 0;
    $assert('E43-008', $guardId === false && $guardSlug === false && $guardResolve === null && $afterCount === $beforeCount && $mutationAudited,
        sprintf('gardes : record(0,slug)=%s, record(id,\'\')=%s, resolve(\'\')=%s, lignes %d→%d ; audit slug_redirect_recorded %s',
            var_export($guardId, true), var_export($guardSlug, true), var_export($guardResolve, true),
            $beforeCount, $afterCount, $mutationAudited ? 'présent' : 'ABSENT'));

    // 9) Sortie propre : le contrat ne laisse aucune ligne.
    SlugRedirectsService::delete_for_property(990001);
    SlugRedirectsService::delete_for_property(990002);
    foreach (['ml02-ancien-slug-a', 'ml02-ancien-slug-b', 'ml02-ancien-slug-c', 'ml02-ancien-slug-d'] as $slug) {
        SlugRedirectsService::clear_redirect($slug);
    }
    $finalCount = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $assert('E43-009', $finalCount === 0,
        sprintf('sortie propre : %d ligne(s) dans %s après purge du contrat', $finalCount, $table));
} catch (Throwable $error) {
    $assert('E43-EXCEPTION', false, $error->getMessage());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'slug-redirects-domain-contract (Schéma 2.7.0 + API SlugRedirects, E-4303)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/slug-redirects-domain-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
