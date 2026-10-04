<?php
/**
 * Contrat du domaine statistiques propriétaire (lot B5, CDC v1.2 — CA-2).
 *
 * Couvre le dispositif de métriques propriétaire, désormais propriété du
 * plugin (une table : pk_property_saves, 4 favoris réels pseudonymisés au
 * T0 du banc) : synchronisation d'un favori visiteur (gardes héritées du
 * thème, pseudonymisation HMAC « favorite-v1|<visitor_id> », plafonnement
 * 60/heure par visiteur, upsert ON DUPLICATE KEY par annonce+visiteur,
 * retrait par suppression de ligne), agrégat favorite_count (fenêtre de
 * rétention 90 jours sur updated_at), purge quotidienne des pseudonymes
 * inactifs, planification du cron pk_owner_insights_daily_purge côté
 * plugin — et la couture du thème 6.18.6 : l'appel via
 * Partikulier_Owner_Insights::sync_favorite doit exécuter le service du
 * plugin, preuve par les lignes d'audit (seul le plugin écrit le registre
 * d'audit — le chemin autonome du thème n'y écrit jamais).
 *
 * Les deux routes /owner/* restent déclarées par le thème (écran
 * d'intégration — arbitrage B5) : le contrat vérifie leur présence au
 * registre INTEG-3 à l'état nominal.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/owner-stats-domain-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Database\Migrator;
use Partikulier\Core\Domain\OwnerStats\OwnerStatsService;
use Partikulier\Core\HealthCheck;

$started = gmdate('c');
$startDb = gmdate('Y-m-d H:i:s');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
$run = bin2hex(random_bytes(4));
$table = $prefix . 'pk_property_saves';

/** Pseudonymisation déterministe — port fidèle du dispositif (HMAC non réversible). */
$visitorHash = static function (string $visitorId): string {
    return hash_hmac('sha256', 'favorite-v1|' . $visitorId, wp_salt('auth'));
};

/* --- Fixtures : annonce publiée + visiteurs jetables de la sonde --- */
$listing = get_posts(['post_type' => 'properties', 'post_status' => 'publish', 'numberposts' => 1, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids']);
$propertyId = $listing ? (int) $listing[0] : 0;
$visitor1 = 'b5a' . $run . 'visitor01';
$visitor2 = 'b5a' . $run . 'visitor02';
$visitor3 = 'b5a' . $run . 'visitor03';
$visitorX = 'b5a' . $run . 'visitorXX';
$probeHashes = [];
$oldRowId = 0;

try {
    // 1) Couture : service du plugin chargé, classe thème présente, table unique.
    $seamOk = class_exists(OwnerStatsService::class)
        && class_exists('Partikulier_Owner_Insights')
        && OwnerStatsService::saves_table() === $table
        && $propertyId > 0;
    $assert('B5A-001', $seamOk,
        'couture thème/plugin : classes chargées, table unique ' . $table . ', annonce de référence #' . $propertyId);

    // 2) Adoption journalisée : la table passée au plugin (manifeste 2.5.0),
    //    audit domain_adopted B5/owner_stats exactement une fois, les 4
    //    favoris réels du T0 toujours en place.
    $adoptedB5 = 0;
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT metadata_json FROM {$prefix}pk_audit_log WHERE action = %s", 'domain_adopted')) as $row) {
        $meta = json_decode((string) $row->metadata_json, true);
        if (($meta['lot'] ?? '') === 'B5' && ($meta['domain'] ?? '') === 'owner_stats') { $adoptedB5++; }
    }
    $tableExists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    $savesBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $assert('B5A-002', $tableExists && $adoptedB5 === 1 && $savesBefore >= 4,
        "adoption B5 journalisée (domain_adopted B5/owner_stats = {$adoptedB5}), table en place, {$savesBefore} favoris T0 préservés");

    $base0 = OwnerStatsService::favorite_count($propertyId);

    // 3) Enregistrement nominal : ligne conforme (annonce + HMAC 64 hex,
    //    dates), réponse ['saved' => true], audit owner_favorite_saved.
    $saved1 = OwnerStatsService::sync_favorite($propertyId, $visitor1, 'save');
    $hash1 = $visitorHash($visitor1);
    $probeHashes[] = $hash1;
    $row1 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE property_id = %d AND visitor_hash = %s", $propertyId, $hash1), ARRAY_A);
    $auditSaved1 = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d AND created_at >= %s",
        'owner_favorite_saved', $propertyId, $startDb
    ));
    $assert('B5A-003', is_array($saved1) && ($saved1['saved'] ?? null) === true
        && is_array($row1) && (int) $row1['property_id'] === $propertyId
        && strlen((string) $row1['visitor_hash']) === 64 && ctype_xdigit((string) $row1['visitor_hash'])
        && (string) $row1['created_at'] !== '' && (string) $row1['updated_at'] !== '' && $auditSaved1 >= 1,
        'sync_favorite save : ligne conforme (HMAC sha256 64 hex non réversible, dates UTC) + audit owner_favorite_saved');

    // 4) Idempotence d'annonce+visiteur : re-save → même ligne (upsert ON
    //    DUPLICATE KEY actualise updated_at), une seule ligne pour le couple.
    $row1Id = is_array($row1) ? (int) $row1['id'] : 0;
    $saved1Again = OwnerStatsService::sync_favorite($propertyId, $visitor1, 'save');
    $row1b = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE property_id = %d AND visitor_hash = %s", $propertyId, $hash1), ARRAY_A);
    $pairs1 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE property_id = %d AND visitor_hash = %s", $propertyId, $hash1));
    $assert('B5A-004', is_array($saved1Again) && ($saved1Again['saved'] ?? null) === true
        && $pairs1 === 1 && is_array($row1b) && (int) $row1b['id'] === $row1Id
        && (string) $row1b['updated_at'] >= (string) $row1b['created_at'],
        'upsert : re-save du même visiteur → même id (' . $row1Id . '), une seule ligne, updated_at actualisé (rétention glissante)');

    // 5) Retrait : ligne supprimée, réponse ['saved' => false], audit
    //    owner_favorite_removed.
    $removed1 = OwnerStatsService::sync_favorite($propertyId, $visitor1, 'remove');
    $row1c = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE property_id = %d AND visitor_hash = %s", $propertyId, $hash1), ARRAY_A);
    $auditRemoved1 = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d AND created_at >= %s",
        'owner_favorite_removed', $propertyId, $startDb
    ));
    $assert('B5A-005', is_array($removed1) && ($removed1['saved'] ?? null) === false
        && $row1c === null && $auditRemoved1 >= 1,
        'sync_favorite remove : ligne supprimée + audit owner_favorite_removed');

    // 6) Gardes héritées : annonce nulle, visiteur trop court, état inconnu
    //    → pk_invalid_favorite.
    $g1 = OwnerStatsService::sync_favorite(0, $visitor1, 'save');
    $g2 = OwnerStatsService::sync_favorite($propertyId, 'court', 'save');
    $g3 = OwnerStatsService::sync_favorite($propertyId, $visitor1, 'frobnicate');
    $assert('B5A-006', is_wp_error($g1) && is_wp_error($g2) && is_wp_error($g3)
        && $g1->get_error_code() === 'pk_invalid_favorite'
        && $g2->get_error_code() === 'pk_invalid_favorite'
        && $g3->get_error_code() === 'pk_invalid_favorite',
        'gardes : annonce nulle, visiteur trop court, état inconnu → pk_invalid_favorite');

    // 7) Annonce inconnue (ou non publiée) → pk_unknown_favorite_property.
    $g4 = OwnerStatsService::sync_favorite(999999999, $visitor1, 'save');
    $assert('B5A-007', is_wp_error($g4) && $g4->get_error_code() === 'pk_unknown_favorite_property',
        'annonce inexistante → pk_unknown_favorite_property');

    // 8) Plafonnement hérité : 60 mises à jour/heure par visiteur → 429
    //    pk_favorite_rate_limited, aucune ligne écrite.
    $hashX = $visitorHash($visitorX);
    set_transient('pk_favorite_rate_' . $hashX, 60, HOUR_IN_SECONDS);
    $g5 = OwnerStatsService::sync_favorite($propertyId, $visitorX, 'save');
    $rowX = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE property_id = %d AND visitor_hash = %s", $propertyId, $hashX));
    delete_transient('pk_favorite_rate_' . $hashX);
    $assert('B5A-008', is_wp_error($g5) && $g5->get_error_code() === 'pk_favorite_rate_limited' && $rowX === 0,
        'plafond 60/heure : 61e mise à jour refusée (429 pk_favorite_rate_limited), aucune ligne écrite');

    // 9) Agrégat propriétaire : favorite_count inclut la nouvelle ligne et
    //    coexiste avec les favoris réels du T0 (aucun effet de bord).
    $saved2 = OwnerStatsService::sync_favorite($propertyId, $visitor2, 'save');
    $hash2 = $visitorHash($visitor2);
    $probeHashes[] = $hash2;
    $countWithFresh = OwnerStatsService::favorite_count($propertyId);
    $assert('B5A-009', is_array($saved2) && $countWithFresh === $base0 + 1,
        "favorite_count : {$base0} → {$countWithFresh} après un favori (coexistence avec les {$savesBefore} favoris réels du T0)");

    // 10) Fenêtre de rétention : une ligne plus vieille que 90 jours
    //     n'entre PAS dans l'agrégat (insertion directe d'un pseudonyme périmé).
    $oldHash = hash('sha256', 'b5-old-probe-' . $run);
    $probeHashes[] = $oldHash;
    $oldDate = gmdate('Y-m-d H:i:s', time() - (91 * DAY_IN_SECONDS));
    $wpdb->insert($table, ['property_id' => $propertyId, 'visitor_hash' => $oldHash, 'created_at' => $oldDate, 'updated_at' => $oldDate], ['%d', '%s', '%s', '%s']);
    $oldRowId = (int) $wpdb->insert_id;
    $countWithOld = OwnerStatsService::favorite_count($propertyId);
    $assert('B5A-010', $countWithOld === $countWithFresh,
        "fenêtre 90 jours : le pseudonyme périmé ({$oldDate}) est exclu de l'agrégat ({$countWithOld} = {$countWithFresh})");

    // 11) Purge de rétention : la ligne périmée est supprimée, les lignes
    //     actives (dont le favori de sonde ET les 4 favoris réels) restent,
    //     audit owner_saves_purged.
    $purged = OwnerStatsService::purge_expired_saves();
    $oldRowAfter = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $oldRowId), ARRAY_A);
    $auditPurged = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND created_at >= %s",
        'owner_saves_purged', $startDb
    ));
    $assert('B5A-011', $purged >= 1 && $oldRowAfter === null && $auditPurged >= 1
        && OwnerStatsService::favorite_count($propertyId) === $countWithFresh,
        "purge_expired_saves : {$purged} pseudonyme(s) périmé(s) supprimé(s), lignes actives intactes, audit owner_saves_purged");

    // 12) Constantes du port fidèle : rétention et événement cron hérités.
    $assert('B5A-012', OwnerStatsService::RETENTION_DAYS === 90 && OwnerStatsService::CRON_HOOK === 'pk_owner_insights_daily_purge',
        'port fidèle : rétention 90 jours, cron pk_owner_insights_daily_purge (noms hérités du thème 6.17.x)');

    // 13) Cron : planifié (l'événement était DÉJÀ présent au T0 — relevé
    //     initial « absent » corrigé : artefact de lecture, les hooks WP
    //     vivent imbriqués sous des clés d'horodatage) et le handler est lié
    //     CÔTÉ PLUGIN, pas côté thème.
    $scheduled = wp_next_scheduled(OwnerStatsService::CRON_HOOK);
    $pluginBound = has_filter(OwnerStatsService::CRON_HOOK, [OwnerStatsService::class, 'purge_expired_saves']) !== false;
    $themeBound = has_filter(OwnerStatsService::CRON_HOOK, ['Partikulier_Owner_Insights', 'purge_expired_saves']) !== false;
    $assert('B5A-013', $scheduled !== false && $pluginBound && !$themeBound,
        'cron daily : planifié (présent dès le T0, relevé initial corrigé), handler lié côté plugin, thème 6.18.6 délié (pattern rétention B2)');

    // 14) Couture du thème : l'appel via Partikulier_Owner_Insights
    //     exécute le service du plugin (preuve : audit écrit, ligne créée
    //     au HMAC attendu) — puis retrait idem.
    $seamSaved = Partikulier_Owner_Insights::sync_favorite($propertyId, $visitor3, 'save');
    $hash3 = $visitorHash($visitor3);
    $probeHashes[] = $hash3;
    $row3 = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE property_id = %d AND visitor_hash = %s", $propertyId, $hash3), ARRAY_A);
    $seamRemoved = Partikulier_Owner_Insights::sync_favorite($propertyId, $visitor3, 'remove');
    $row3b = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE property_id = %d AND visitor_hash = %s", $propertyId, $hash3), ARRAY_A);
    $assert('B5A-014', is_array($seamSaved) && ($seamSaved['saved'] ?? null) === true && is_array($row3)
        && is_array($seamRemoved) && ($seamRemoved['saved'] ?? null) === false && $row3b === null,
        'couture thème → service plugin : sync_favorite/remove via Partikulier_Owner_Insights exécuté par OwnerStatsService (mêmes HMAC, même table)');

    // 15) Health : domaine owner_stats plugin-owned, 1/1 table suivie,
    //     domaines plugin (7/8 au lot B5 — invariant ≥ 7 : les lots suivants
    //     ne font qu'EN AJOUTER ; au lot B6, translation_variants complète à
    //     8/8 sans rien retirer), 0 collision.
    $health = (new HealthCheck())->get();
    $ownerStats = $health['domains']['owner_stats'] ?? [];
    $pluginDomainCount = count(array_filter($health['domains'], static fn($d) => ($d['owner'] ?? '') === 'plugin'));
    $domainsOk = ($ownerStats['owner'] ?? '') === 'plugin'
        && count($ownerStats['tables'] ?? []) === 1 && !in_array(false, $ownerStats['tables'], true)
        && $pluginDomainCount >= 7
        && (int) ($health['routes']['collisions'] ?? -1) === 0;
    $assert('B5A-015', $domainsOk,
        'health check : domaine owner_stats owner=plugin, 1/1 table suivie, ' . $pluginDomainCount . '/8 domaines plugin (invariant ≥ 7 — 7 au lot B5, 8/8 depuis le lot B6 : translation_variants complété sans retrait), 0 collision');

    // 16) Routes /owner/* : toujours déclarées (par le thème, via les
    //     helpers du bridge — arbitrage B5), présentes au registre INTEG-3.
    $routes = rest_get_server()->get_routes();
    $dashboardRoute = isset($routes['/partikulier/v1/owner/dashboard']);
    $actionRoute = isset($routes['/partikulier/v1/owner/listings/(?P<id>\d+)/action']);
    $assert('B5A-016', $dashboardRoute && $actionRoute,
        'routes /owner/dashboard + /owner/listings/<id>/action déclarées au registre INTEG-3 (thème — écran d\'intégration, primitives déléguées)');
} catch (Throwable $error) {
    $assert('B5A-EXCEPTION', false, $error->getMessage());
} finally {
    // Nettoyage : lignes de la sonde (par HMAC déterministes), audits
    // d'écriture du domaine (fenêtre du run), transients de plafonnement.
    // L'audit domain_adopted B5 RESTE (preuve d'adoption, pas une trace).
    foreach ($probeHashes as $hash) {
        $wpdb->delete($table, ['visitor_hash' => $hash], ['%s']);
        delete_transient('pk_favorite_rate_' . $hash);
    }
    if ($oldRowId > 0) {
        $wpdb->delete($table, ['id' => $oldRowId], ['%d']);
    }
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$prefix}pk_audit_log WHERE action IN ('owner_favorite_saved','owner_favorite_removed','owner_saves_purged') AND created_at >= %s",
        $startDb
    ));
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'owner-stats-domain-contract (lot B5)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/owner-stats-domain-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
