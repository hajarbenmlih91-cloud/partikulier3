<?php
/**
 * Contrat SE-036 — référentiel AR des lieux + écran « Villes & quartiers »
 * trilingue + import CSV (E-3601→E-3604, micro-lot pré-prod 2.10.8/6.20.7,
 * DP-5 jour 1).
 *
 * R1 (pré-correctif, mesuré) : la carte AR couvrait les 30 villes mais
 * seulement ~26 quartiers sur les 174 du référentiel marocain — les fiches
 * et titres AR rendaient majoritairement des quartiers latins (نص عربي
 * مكسور بالحروف اللاتينية). Aucun écran d'administration ne présentait le
 * référentiel, aucun import ne permettait de le corriger sans code.
 *
 * Ce contrat verrouille :
 *  - E36-001 (E-3601) : les 30 villes du référentiel résolvent en arabe,
 *    parité thème/plugin (couture publique vs service) ;
 *  - E36-002 (E-3601) : les 174 quartiers résolvent en arabe (zéro latin
 *    résiduel), même parité ;
 *  - E36-003 (E-3602) : l'écran « Villes & quartiers » — sous-menu du menu
 *    top-level partikulier, URL canonique admin.php?page=pk-places, rendu
 *    trilingue (colonnes FR + AR + source) avec formulaire d'import CSV ;
 *  - E36-004 (E-3603) : import CSV — apply_rows sur des lignes de fixture
 *    (ville, quartier, en-tête, ligne invalide ignorée) → overrides stockés,
 *    comptes exacts ;
 *  - E36-005 (E-3604) : résolution runtime — les overrides importés
 *    PRIMENT sur la carte intégrée (les deux chemins, parité), un lieu
 *    inconnu passe tel quel (nom propre), les langues non arabes rendent
 *    l'entrée inchangée ;
 *  - E36-006 : sortie propre — option d'overrides purgée.
 *
 * Le référentiel AR (rédaction de la reprise v2) est SOUMIS à la validation
 * du commanditaire avec le lot : docs/REFERENTIEL-LIEUX-AR-SE036.md.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_REPO_DIR=<racine> php
 *   partikulier-core/tests/se036-places-referential-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
$repoDir = getenv('PK_REPO_DIR') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
if ($repoDir === '' || !is_dir($repoDir)) { fwrite(STDERR, "PK_REPO_DIR doit pointer vers la racine du dépôt\n"); exit(2); }
$repoDir = rtrim($repoDir, '/');
require $wpDir . '/wp-load.php';

/* Écran d'administration : fonctions de rendu absentes en CLI. */
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

wp_set_current_user(1);

try {
    $reference = Partikulier_Morocco_Places::reference();
    $cities = array_keys($reference);
    $districts = [];
    foreach ($reference as $list) { $districts = array_merge($districts, $list); }

    // L'option d'overrides doit être en place AVANT tout appel : la résolution
    // met en cache pour la durée de la requête (comportement runtime normal).
    delete_option('pk_places_ar');
    $run = bin2hex(random_bytes(4));
    Partikulier_Places_Admin::apply_rows([
        ['ville', 'ville_ar', 'quartier', 'quartier_ar'], // en-tête ignoré
        ["Ville Méconnue {$run}", "مدينة مجهولة {$run}", '', ''],
        ['', '', "Quartier Rare {$run}", "حي نادر {$run}"],
        ['', '', '', ''],                                   // ligne invalide
    ]);

    // 1) Villes : 30/30 résolues, parité.
    $badCities = []; $badCitiesPlugin = [];
    foreach ($cities as $city) {
        if (Partikulier_Listing_I18n::localized_place($city, 'ar') === $city) { $badCities[] = $city; }
        if (\Partikulier\Core\Domain\I18n\ListingVocabulary::place_in($city, 'ar') === $city) { $badCitiesPlugin[] = $city; }
    }
    $assert('E36-001', $badCities === [] && $badCitiesPlugin === [] && count($cities) === 30,
        sprintf('villes : %d/30 résolues en arabe (thème %d non résolues, plugin %d)',
            30 - count($badCities), count($badCities), count($badCitiesPlugin)));

    // 2) Quartiers : 174/174 résolus, parité.
    $badDistricts = []; $badDistrictsPlugin = [];
    foreach (array_unique($districts) as $district) {
        if (Partikulier_Listing_I18n::localized_place($district, 'ar') === $district) { $badDistricts[] = $district; }
        if (\Partikulier\Core\Domain\I18n\ListingVocabulary::place_in($district, 'ar') === $district) { $badDistrictsPlugin[] = $district; }
    }
    $assert('E36-002', $badDistricts === [] && $badDistrictsPlugin === [],
        sprintf('quartiers : %d/%d résolus en arabe — manquants thème : %s ; plugin : %s',
            count(array_unique($districts)) - count($badDistricts), count(array_unique($districts)),
            $badDistricts ? implode(',', array_slice($badDistricts, 0, 4)) : 'aucun',
            $badDistrictsPlugin ? implode(',', array_slice($badDistrictsPlugin, 0, 4)) : 'aucun'));

    // 3) Écran : parent, URL canonique, rendu trilingue + import.
    do_action('admin_menu');
    global $submenu;
    $entry = null;
    foreach ((array) ($submenu['partikulier'] ?? []) as $item) {
        if (($item[2] ?? '') === 'pk-places') { $entry = $item; break; }
    }
    $url = function_exists('menu_page_url') ? (string) menu_page_url('pk-places', false) : '';
    ob_start();
    Partikulier_Places_Admin::render_page();
    $screen = (string) ob_get_clean();
    $screenOk = strpos($screen, 'Villes &amp; quartiers') !== false
        && strpos($screen, 'Importer le CSV') !== false
        && strpos($screen, 'ville;ville_ar;quartier;quartier_ar') !== false
        && strpos($screen, 'Casablanca') !== false;
    $assert('E36-003', $entry !== null && untrailingslashit($url) === untrailingslashit(admin_url('admin.php?page=pk-places')) && $screenOk,
        sprintf('écran : entrée %s sous le menu top-level partikulier, URL « %s », rendu %s',
            $entry ? 'présente' : 'ABSENTE', $url, $screenOk ? 'trilingue + import CSV' : 'INCOMPLET'));

    // 4) Import CSV : les fixtures sont appliquées (en-tête ignoré, 2 valides, 1 ignorée).
    $stored = (array) get_option('pk_places_ar', []);
    $importOk = isset($stored["Ville Méconnue {$run}"]) && $stored["Ville Méconnue {$run}"] === "مدينة مجهولة {$run}"
        && isset($stored["Quartier Rare {$run}"]) && $stored["Quartier Rare {$run}"] === "حي نادر {$run}";
    $assert('E36-004', $importOk && count($stored) === 2,
        sprintf('import CSV : en-tête ignoré, 2 lignes importées, 1 ignorée — option porte %d override(s), valeurs conformes',
            count($stored)));

    // 5) Résolution runtime : override prime, inconnu passe, non-ar inchangé, parité.
    $tOverride = Partikulier_Listing_I18n::localized_place("Ville Méconnue {$run}", 'ar');
    $pOverride = \Partikulier\Core\Domain\I18n\ListingVocabulary::place_in("Ville Méconnue {$run}", 'ar');
    $tUnknown  = Partikulier_Listing_I18n::localized_place('Lieu Jamais Vu', 'ar');
    $frSame    = Partikulier_Listing_I18n::localized_place('Casablanca', 'fr');
    $enSame    = \Partikulier\Core\Domain\I18n\ListingVocabulary::place_in('Casablanca', 'en');
    $assert('E36-005',
        $tOverride === "مدينة مجهولة {$run}" && $pOverride === "مدينة مجهولة {$run}"
        && $tUnknown === 'Lieu Jamais Vu' && $frSame === 'Casablanca' && $enSame === 'Casablanca',
        sprintf('résolution : override prime (thème « %s », plugin « %s »), inconnu inchangé « %s », fr/en inchangés',
            $tOverride, $pOverride, $tUnknown));
} catch (Throwable $error) {
    $assert('E36-EXCEPTION', false, $error->getMessage());
} finally {
    delete_option('pk_places_ar');
    $left = (int) count((array) get_option('pk_places_ar', []));
    if ($left > 0) {
        $assert('E36-EXCEPTION', false, "sortie propre : $left override(s) résiduel(s)");
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se036-places-referential-contract (référentiel AR villes+quartiers + écran trilingue + import CSV, E-3601→E-3604)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/se036-places-referential-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
