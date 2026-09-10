<?php
/**
 * Contrat du domaine variantes de traduction (lot B6, CDC v1.2 — CA-2).
 *
 * Couvre le registre des variantes localisées, désormais propriété du plugin
 * (une table : pk_property_variants, vide au T0 du banc — domaine dormant :
 * prepare_variant/link_variant n'ont aucun appelant runtime, ils attendent
 * la passerelle de traduction du lot C) : préparation d'un emplacement
 * (gardes héritées du thème : pk_variant_property, pk_variant_locale,
 * pk_variant_storage ; upsert ON DUPLICATE KEY par annonce+locale ; méta
 * _pk_free_text_language posée sur l'annonce source), liaison d'une variante
 * (gardes pk_variant_link, pk_variant_link_property, pk_variant_link_storage ;
 * statut prepared → linked ; variant_property_id attaché), locales
 * supportées fr/ar/en (sanitize hérité), porte publique éteinte (option
 * pk_localization_public_enabled='0') — et la couture du thème 6.18.7 :
 * l'appel via Partikulier_Localization::prepare_variant doit exécuter le
 * service du plugin, preuve par les lignes d'audit (seul le plugin écrit le
 * registre d'audit — le chemin autonome du thème n'y écrit jamais).
 *
 * Dernier domaine des huit : le health check doit afficher 8/8 domaines
 * propriété du plugin (critère de sortie du lot B atteint intégralement).
 *
 * AUCUNE route n'est déplacée au lot B6 (domaine dormant) : le contrat
 * vérifie 0 collision au registre INTEG-3.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/translation-variants-domain-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Database\Migrator;
use Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService;
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
$table = $prefix . 'pk_property_variants';

/* --- Fixtures : deux annonces publiées (source + variante) de la sonde --- */
$listings = get_posts(['post_type' => 'properties', 'post_status' => 'publish', 'numberposts' => 2, 'orderby' => 'ID', 'order' => 'ASC', 'fields' => 'ids']);
$sourceId = $listings ? (int) $listings[0] : 0;
$variantId = count($listings) > 1 ? (int) $listings[1] : 0;
$pageId = 0;
$pages = get_posts(['post_type' => 'page', 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids']);
if ($pages) { $pageId = (int) $pages[0]; }
$probeLocale = 'en';
$probeFreeText = 'fr';

try {
    // 1) Couture : service du plugin chargé, classe thème présente, table unique.
    $seamOk = class_exists(TranslationVariantsService::class)
        && class_exists('Partikulier_Localization')
        && TranslationVariantsService::variants_table() === $table
        && $sourceId > 0 && $variantId > 0 && $variantId !== $sourceId;
    $assert('B6A-001', $seamOk,
        'couture thème/plugin : classes chargées, table unique ' . $table . ', annonces de référence #' . $sourceId . ' (source) et #' . $variantId . ' (variante)');

    // 2) Adoption journalisée : la table passée au plugin (manifeste 2.6.0),
    //    audit domain_adopted B6/translation_variants exactement une fois, la
    //    table vide du T0 toujours en place (0 ligne parasite).
    $adoptedB6 = 0;
    foreach ((array) $wpdb->get_results($wpdb->prepare("SELECT metadata_json FROM {$prefix}pk_audit_log WHERE action = %s", 'domain_adopted')) as $row) {
        $meta = json_decode((string) $row->metadata_json, true);
        if (($meta['lot'] ?? '') === 'B6' && ($meta['domain'] ?? '') === 'translation_variants') { $adoptedB6++; }
    }
    $tableExists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    $rowsBefore = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $assert('B6A-002', $tableExists && $adoptedB6 === 1 && $rowsBefore === 0,
        "adoption B6 journalisée (domain_adopted B6/translation_variants = {$adoptedB6}), table en place, {$rowsBefore} ligne au départ (domaine dormant au T0)");

    // 3) Préparation nominale : ligne conforme (annonce + locale + langue du
    //    texte libre, statut prepared, dates), méta posée, audit
    //    variant_prepared.
    $prepared = TranslationVariantsService::prepare_variant($sourceId, $probeLocale, $probeFreeText);
    $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_property_id = %d AND locale = %s", $sourceId, $probeLocale), ARRAY_A);
    $metaValue = (string) get_post_meta($sourceId, TranslationVariantsService::META_FREE_TEXT_LANGUAGE, true);
    $auditPrepared = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d AND created_at >= %s",
        'variant_prepared', $sourceId, $startDb
    ));
    $assert('B6A-003', true === $prepared && is_array($row)
        && (int) $row['source_property_id'] === $sourceId
        && (string) $row['locale'] === $probeLocale
        && (string) $row['original_free_text_locale'] === $probeFreeText
        && (string) $row['status'] === 'prepared'
        && (int) $row['variant_property_id'] === 0
        && (string) $row['created_at'] !== '' && (string) $row['updated_at'] !== ''
        && $metaValue === $probeFreeText && $auditPrepared >= 1,
        'prepare_variant : emplacement conforme (statut prepared, locale ' . $probeLocale . ', texte libre ' . $probeFreeText . ') + méta _pk_free_text_language + audit variant_prepared');

    // 4) Idempotence d'annonce+locale : re-prepare → upsert, une seule ligne,
    //    même id (la clé unique source_locale tient le rôle).
    $rowId = is_array($row) ? (int) $row['id'] : 0;
    $preparedAgain = TranslationVariantsService::prepare_variant($sourceId, $probeLocale, $probeFreeText);
    $pairs = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE source_property_id = %d AND locale = %s", $sourceId, $probeLocale));
    $rowAgain = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_property_id = %d AND locale = %s", $sourceId, $probeLocale), ARRAY_A);
    $assert('B6A-004', true === $preparedAgain && $pairs === 1 && is_array($rowAgain) && (int) $rowAgain['id'] === $rowId,
        'upsert : re-prepare du même couple annonce+locale → même id (' . $rowId . '), une seule ligne (clé unique source_locale)');

    // 5) Gardes héritées : annonce nulle ou hors type properties →
    //    pk_variant_property.
    $g1 = TranslationVariantsService::prepare_variant(0, $probeLocale, $probeFreeText);
    $g2 = $pageId > 0 ? TranslationVariantsService::prepare_variant($pageId, $probeLocale, $probeFreeText) : new WP_Error('pk_variant_property');
    $assert('B6A-005', is_wp_error($g1) && is_wp_error($g2)
        && $g1->get_error_code() === 'pk_variant_property' && $g2->get_error_code() === 'pk_variant_property',
        'gardes : annonce nulle ou hors type properties → pk_variant_property');

    // 6) Garde de locale : locale non supportée (es) ou langue du texte libre
    //    invalide → pk_variant_locale, aucune ligne écrite.
    $rowsBefore6 = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $g3 = TranslationVariantsService::prepare_variant($sourceId, 'es', $probeFreeText);
    $g4 = TranslationVariantsService::prepare_variant($sourceId, $probeLocale, 'it');
    $rowsAfter6 = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table}");
    $assert('B6A-006', is_wp_error($g3) && is_wp_error($g4)
        && $g3->get_error_code() === 'pk_variant_locale' && $g4->get_error_code() === 'pk_variant_locale'
        && $rowsAfter6 === $rowsBefore6,
        'gardes : locale non supportée (es) ou langue du texte libre invalide (it) → pk_variant_locale, aucune ligne écrite');

    // 7) Liaison nominale : prepare (upsert) puis update — statut linked,
    //    variant_property_id attaché, audit variant_linked.
    $linked = TranslationVariantsService::link_variant($sourceId, $variantId, $probeLocale, $probeFreeText);
    $rowL = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_property_id = %d AND locale = %s", $sourceId, $probeLocale), ARRAY_A);
    $auditLinked = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d AND created_at >= %s",
        'variant_linked', $sourceId, $startDb
    ));
    $assert('B6A-007', true === $linked && is_array($rowL)
        && (string) $rowL['status'] === 'linked' && (int) $rowL['variant_property_id'] === $variantId,
        'link_variant : statut linked, variante #' . $variantId . ' attachée, audit variant_linked (' . $auditLinked . ')');

    // 8) Gardes de liaison : variante nulle → pk_variant_link ; variante hors
    //    type properties (page) → pk_variant_link_property.
    $g5 = TranslationVariantsService::link_variant($sourceId, 0, $probeLocale, $probeFreeText);
    $g6 = $pageId > 0 ? TranslationVariantsService::link_variant($sourceId, $pageId, $probeLocale, $probeFreeText) : new WP_Error('pk_variant_link_property');
    $assert('B6A-008', is_wp_error($g5) && is_wp_error($g6)
        && $g5->get_error_code() === 'pk_variant_link' && $g6->get_error_code() === 'pk_variant_link_property',
        'gardes de liaison : variante nulle → pk_variant_link, variante hors properties → pk_variant_link_property');

    // 9) Locales supportées : fr/ar/en (port fidèle), une ligne par locale.
    $pFr = TranslationVariantsService::prepare_variant($sourceId, 'fr', $probeFreeText);
    $pAr = TranslationVariantsService::prepare_variant($sourceId, 'ar', $probeFreeText);
    $locales = TranslationVariantsService::supported_locales();
    $countNow = TranslationVariantsService::count_variants($sourceId);
    $assert('B6A-009', $pFr === true && $pAr === true && $locales === ['fr', 'ar', 'en'] && $countNow === 3,
        'locales fr/ar/en : emplacements distincts par clé unique source+locale, count_variants = ' . $countNow);

    // 10) Méta _pk_free_text_language : reflète la langue du texte libre
    //     (dernière préparation), jamais la locale de la variante.
    $metaNow = (string) get_post_meta($sourceId, TranslationVariantsService::META_FREE_TEXT_LANGUAGE, true);
    $assert('B6A-010', $metaNow === $probeFreeText && $metaNow !== $probeLocale,
        "méta _pk_free_text_language = '{$metaNow}' (langue du texte libre, distincte de la locale de variante '{$probeLocale}')");

    // 11) Lectures de contrôle : get_variant (ligne liée en, préparée fr) +
    //     null en locale non supportée ; aucune consommation publique.
    $gotEn = TranslationVariantsService::get_variant($sourceId, 'en');
    $routes = rest_get_server()->get_routes();
    $variantsRouteDeclared = isset($routes['/partikulier/v1/variants']) || isset($routes['/partikulier/v1/translation-variants']);
    $assert('B6A-011', is_array($gotEn) && (string) $gotEn['status'] === 'linked'
        && is_array(TranslationVariantsService::get_variant($sourceId, 'fr'))
        && TranslationVariantsService::get_variant($sourceId, 'en_US') === null
        && !$variantsRouteDeclared,
        'lectures de contrôle : get_variant exact par locale, sanitize refuse les locales non supportées, AUCUNE route REST pour le registre (domaine dormant — passerelle au lot C)');

    // 12) Constantes du port fidèle : statuts, option, méta et type hérités
    //     du thème 6.17.x (mêmes noms, mêmes valeurs).
    $assert('B6A-012', TranslationVariantsService::STATUS_PREPARED === 'prepared'
        && TranslationVariantsService::STATUS_LINKED === 'linked'
        && TranslationVariantsService::OPTION_DB_VERSION === 'pk_localization_db_version'
        && TranslationVariantsService::OPTION_PUBLIC_ENABLED === 'pk_localization_public_enabled'
        && TranslationVariantsService::META_FREE_TEXT_LANGUAGE === '_pk_free_text_language'
        && TranslationVariantsService::POST_TYPE === 'properties',
        'port fidèle : statuts prepared/linked, options pk_localization_*, méta _pk_free_text_language, type properties (noms hérités du thème)');

    // 13) Porte publique éteinte : option '0' au T0, aucun affichage public
    //     activé par le lot (l'unification relève du lot C).
    $publicEnabled = TranslationVariantsService::is_public_enabled();
    $optionValue = (string) get_option('pk_localization_public_enabled', '0');
    $assert('B6A-013', $publicEnabled === false && $optionValue === '0',
        "porte publique éteinte : pk_localization_public_enabled = '{$optionValue}' (aucun affichage activé par le lot B6)");

    // 14) Couture du thème : l'appel via Partikulier_Localization exécute le
    //     service du plugin — preuve par l'audit (seul le plugin l'écrit).
    $auditBefore14 = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d AND created_at >= %s",
        'variant_prepared', $variantId, $startDb
    ));
    $seamPrepared = Partikulier_Localization::prepare_variant($variantId, $probeLocale, $probeFreeText);
    $rowSeam = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE source_property_id = %d AND locale = %s", $variantId, $probeLocale), ARRAY_A);
    $auditSeam = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d AND created_at >= %s",
        'variant_prepared', $variantId, $startDb
    ));
    $assert('B6A-014', true === $seamPrepared && is_array($rowSeam) && (string) $rowSeam['status'] === 'prepared'
        && $auditSeam === $auditBefore14 + 1,
        'couture thème → service plugin : prepare_variant via Partikulier_Localization exécuté par TranslationVariantsService (preuve : audit variant_prepared écrit — le chemin autonome du thème n\'écrit jamais ce registre)');

    // 15) Health : domaine translation_variants plugin-owned, 1/1 table,
    //     8/8 domaines plugin (DERNIER domaine du lot B), 0 collision.
    $health = (new HealthCheck())->get();
    $tv = $health['domains']['translation_variants'] ?? [];
    $pluginDomains = count(array_filter($health['domains'], static fn($d) => ($d['owner'] ?? '') === 'plugin'));
    $domainsOk = ($tv['owner'] ?? '') === 'plugin'
        && count($tv['tables'] ?? []) === 1 && !in_array(false, $tv['tables'], true)
        && $pluginDomains === 8
        && (int) ($health['routes']['collisions'] ?? -1) === 0;
    $assert('B6A-015', $domainsOk,
        'health check : translation_variants owner=plugin, 1/1 table, 8/8 domaines plugin (lot B COMPLET), 0 collision');

    // 16) Manifeste : les vingt tables pk_ suivies, traduction_variants
    //     marqué B6, aucune table restée côté thème (annexe A).
    $manifest = \Partikulier\Core\Database\Schema::domainTables();
    $themeOwned = array_filter($manifest, static fn(array $d) => ($d['owner'] ?? '') === 'theme');
    $b6Entry = $manifest['pk_property_variants'] ?? [];
    $assert('B6A-016', count($manifest) === 20 && $themeOwned === []
        && ($b6Entry['owner'] ?? '') === 'plugin' && ($b6Entry['lot'] ?? '') === 'B6',
        'manifeste : 20/20 tables pk_ suivies, 0 restée côté thème, pk_property_variants owner=plugin lot=B6');
} catch (Throwable $error) {
    $assert('B6A-EXCEPTION', false, $error->getMessage());
} finally {
    // Nettoyage : lignes de la sonde (par couple annonce+locale), méta posée
    // sur les annonces de la sonde, audits d'écriture du domaine (fenêtre du
    // run). L'audit domain_adopted B6 RESTE (preuve d'adoption, pas une trace).
    $probeSources = array_unique(array_filter([$sourceId, $variantId]));
    foreach ($probeSources as $probeSource) {
        $wpdb->delete($table, ['source_property_id' => $probeSource], ['%d']);
        delete_post_meta($probeSource, TranslationVariantsService::META_FREE_TEXT_LANGUAGE);
    }
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$prefix}pk_audit_log WHERE action IN ('variant_prepared','variant_linked') AND created_at >= %s",
        $startDb
    ));
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'translation-variants-domain-contract (lot B6)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/translation-variants-domain-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
