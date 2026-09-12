<?php
/**
 * Contrat du domaine i18n chrome (lot C2, CDC v1.2 — §3.2 I18N-1, CA-2).
 *
 * Couvre l'extinction du dictionnaire interne chrome/form + du filtre
 * gettext au profit du service unifié du plugin partikulier-core 2.8.0 :
 *  - couture armée : la facade I18nChromeService et ses deux dictionnaires
 *    (ChromeDictionary 136 entrées, FormsDictionary 140 entrées) sont
 *    chargés, la classe thème Partikulier_Localization est présente avec
 *    l'API renforcée (couture + replis *_local) ;
 *  - parité des données : dictionnaires chrome et form identiques entre le
 *    repli dormant du thème et le port plugin — VERBATIM prouvé (égalité
 *    profonde entrée par entrée) ;
 *  - parité de résolution triple chemin sur les 150 chaînes du registre :
 *    couture publique (délégation), service plugin direct, repli local —
 *    les trois chemins servent la même chaîne ;
 *  - extinction armée : le filtre gettext du thème N'EST PLUS enregistré
 *    (has_filter négatif) alors que celui du service unifié EST actif ;
 *  - inversion de dépendance : le registre fourni par le thème au service
 *    (provide_registry à l'init) est identique au registre chrome du thème ;
 *  - chaîne figée REG-3 : la traduction .mo canonique passe prioritairement
 *    (filtre réel testé via load_textdomain du catalogue ar), les domaines
 *    étrangers ('es', 'default') sont intacts ;
 *  - hygiène : modules ≤300 lignes, versions 2.9.1/6.19.1 (lot C4), schéma figé
 *    2.6.0 (aucune migration C2), 8/8 domaines plugin, 0 collision, 20
 *    tables au manifeste.
 *
 * Le comportement dépendant de la langue (en/ar servis) est prouvé par la
 * recette du lot : gel de corpus (empreinte de service identique d71b57fa…)
 * + matrice REG-5 A/B/C sur pages HTTP réelles + QA visuelle A/B.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/i18n-chrome-domain-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\I18n\ChromeDictionary;
use Partikulier\Core\Domain\I18n\FormsDictionary;
use Partikulier\Core\Domain\I18n\I18nChromeService;
use Partikulier\Core\Database\Schema;
use Partikulier\Core\HealthCheck;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$themeDir = get_template_directory();
$pluginDir = dirname(__DIR__);

try {
    // 1) Couture armée : trois classes du domaine + l'API thème renforcée.
    $classes = [I18nChromeService::class, ChromeDictionary::class, FormsDictionary::class];
    $apiTheme = ['translate_polylang_string', 'translate_polylang_string_local',
                 'translate_public_string', 'translate_public_string_local',
                 'public_chrome_strings', 'chrome_translations', 'form_translations',
                 'register_polylang_strings', 'translate_taxonomy_label'];
    $seamOk = true;
    foreach ($classes as $c) { $seamOk = $seamOk && class_exists($c); }
    foreach ($apiTheme as $m) { $seamOk = $seamOk && method_exists('Partikulier_Localization', $m); }
    foreach (['translate', 'translate_public_string', 'chrome_translations', 'form_translations',
              'provide_registry', 'registry', 'register_gettext_filter'] as $m) {
        $seamOk = $seamOk && method_exists(I18nChromeService::class, $m);
    }
    $assert('C2A-001', $seamOk,
        'couture thème/plugin : 3 classes Domain/I18n chargées, Partikulier_Localization présente avec la couture + les replis *_local, service unifié complet');

    // 2) Parité du dictionnaire chrome (VERBATIM, égalité profonde).
    $themeChrome = Partikulier_Localization::chrome_translations();
    $pluginChrome = ChromeDictionary::translations();
    $chromeDiff = 0;
    foreach ($pluginChrome as $k => $v) {
        if (!isset($themeChrome[$k]) || $themeChrome[$k] !== $v) { $chromeDiff++; }
    }
    $assert('C2A-002', $chromeDiff === 0 && count($pluginChrome) === 136 && count($themeChrome) === 136,
        sprintf('dictionnaire chrome : %d entrées plugin == %d entrées thème (dormant), %d divergence — port VERBATIM prouvé',
            count($pluginChrome), count($themeChrome), $chromeDiff));

    // 3) Parité du dictionnaire form (VERBATIM, égalité profonde).
    $themeForms = Partikulier_Localization::form_translations();
    $pluginForms = FormsDictionary::translations();
    $formsDiff = 0;
    foreach ($pluginForms as $k => $v) {
        if (!isset($themeForms[$k]) || $themeForms[$k] !== $v) { $formsDiff++; }
    }
    $assert('C2A-003', $formsDiff === 0 && count($pluginForms) === 140 && count($themeForms) === 140,
        sprintf('dictionnaire form : %d entrées plugin == %d entrées thème (dormant), %d divergence — port VERBATIM prouvé',
            count($pluginForms), count($themeForms), $formsDiff));

    // 4) Parité triple chemin sur les 150 chaînes du registre (contexte CLI :
    //    langue fr — le comportement en/ar est prouvé par gel de corpus +
    //    matrice REG-5 HTTP de la recette).
    $registry = Partikulier_Localization::public_chrome_strings();
    $triple = 0; $tripleBad = [];
    foreach ($registry as $key => $fr) {
        $viaCouture = Partikulier_Localization::translate_polylang_string($fr, $fr, 'partikulier');
        $viaService = I18nChromeService::translate($fr, $fr, 'partikulier');
        $viaLocal = Partikulier_Localization::translate_polylang_string_local($fr, $fr, 'partikulier');
        if (!($viaCouture === $viaService && $viaService === $viaLocal)) {
            $tripleBad[] = (string) $key;
        }
        $triple++;
    }
    $assert('C2A-004', $triple === 150 && $tripleBad === [],
        sprintf('triple chemin : %d/%d chaînes du registre identiques (couture == service == repli local)%s',
            $triple - count($tripleBad), $triple, $tripleBad !== [] ? ' — écarts : ' . implode(',', array_slice($tripleBad, 0, 5)) : ''));

    // 5) Extinction armée : le filtre gettext du thème n'est PAS enregistré,
    //    celui du service unifié EST actif (après chargement complet CLI).
    $themeFilter = has_filter('gettext', ['Partikulier_Localization', 'translate_polylang_string']);
    $pluginFilter = has_filter('gettext', [I18nChromeService::class, 'translate']);
    $assert('C2A-005', false === $themeFilter && false !== $pluginFilter,
        sprintf('extinction : filtre gettext thème %s, filtre gettext service unifié %s — LE mécanisme appartient au plugin',
            false === $themeFilter ? 'ABSENT (éteint)' : 'ENCORE ENREGISTRÉ (défaut)',
            false !== $pluginFilter ? 'ACTIF' : 'ABSENT (défaut)'));

    // 6) Inversion de dépendance : registre fourni == registre chrome du thème.
    $provided = I18nChromeService::registry();
    $assert('C2A-006', $provided === $registry && count($provided) === 150,
        sprintf('provider : registre fourni au service == public_chrome_strings() du thème (%d entrées — donnée du thème, consommée par le plugin)',
            count($provided)));

    // 7) Chaîne figée REG-3 — priorité .mo canonique : une traduction
    //    pré-résolue passe prioritairement, qu'elle vienne d'un .mo ou non.
    $moPass = I18nChromeService::translate('MO-CANONIQUE', 'Chaîne quelconque', 'partikulier');
    $moPass2 = I18nChromeService::translate('traduit', 'autre', 'partikulier');
    $couturePass = Partikulier_Localization::translate_polylang_string('MO-CANONIQUE', 'Chaîne quelconque', 'partikulier');
    $assert('C2A-007', $moPass === 'MO-CANONIQUE' && $moPass2 === 'traduit' && $couturePass === 'MO-CANONIQUE',
        "priorité .mo canonique : le service et la couture laissent passer la traduction pré-résolue ({$moPass})");

    // 8) Garde de domaine : les domaines étrangers sont intacts.
    $es = I18nChromeService::translate('tel-quel-es', 'Texte es', 'es');
    $def = I18nChromeService::translate('tel-quel-default', 'Texte défaut', 'default');
    $assert('C2A-008', $es === 'tel-quel-es' && $def === 'tel-quel-default',
        'garde de domaine : \'es\' et \'default\' passent sans touch (résolution réservée au domaine partikulier)');

    // 9) Résolution réelle par le FILTRE (mécanisme plugin) : déchargement
    //     préalable du domaine (le JIT a pu charger en_US pour la locale du
    //     site — merge pomo : les entrées existantes gagneraient), chargement
    //    du catalogue ar sur le domaine réel : la chaîne passe par le filtre
    //    du service unifié et ressort avec la valeur .mo — parité stricte
    //    avec le repli local et le service direct sur la même entrée.
    if (function_exists('unload_textdomain')) { unload_textdomain('partikulier'); }
    $moAr = $pluginDir . '/languages/ar.mo';
    $loaded = load_textdomain('partikulier', $moAr);
    $aideViaFilter = translate_with_gettext_context('Aide', '', 'partikulier');
    $aideViaLocal = Partikulier_Localization::translate_polylang_string_local($aideViaFilter, 'Aide', 'partikulier');
    $aideViaService = I18nChromeService::translate($aideViaFilter, 'Aide', 'partikulier');
    $assert('C2A-009', $loaded && $aideViaFilter === 'المساعدة' && $aideViaLocal === $aideViaFilter && $aideViaService === $aideViaFilter,
        sprintf("filtre réel : __('Aide') → '%s' via le filtre du service unifié (catalogue ar chargé sur le domaine réel, domaine déchargé au préalable) — parité locale et service directe",
            $aideViaFilter));

    // 10) Versions et santé : 2.10.4 / 6.20.3 (lot F — extinction finale : plugin 2.10.4, thème 6.20.3), schéma figé 2.6.0, 8/8, 0 collision.
    $themeVersion = wp_get_theme()->get('Version');
    $health = (new HealthCheck())->get();
    $pluginDomains = count(array_filter($health['domains'] ?? [], static fn($d) => ($d['owner'] ?? '') === 'plugin'));
    $assert('C2A-010', PARTIKULIER_CORE_VERSION === '2.10.4' && $themeVersion === '6.20.3'
        && Schema::VERSION === '2.6.0' && $pluginDomains === 8
        && (int) ($health['routes']['collisions'] ?? -1) === 0,
        sprintf('versions : plugin %s, thème %s, schéma %s (figé — zéro migration C2), %d/8 domaines, 0 collision',
            PARTIKULIER_CORE_VERSION, $themeVersion, Schema::VERSION, $pluginDomains));

    // 11) Couture statique : le shell contient les branches d'extinction et
    //     de délégation (pattern C1A-008 — garde permanent en source).
    $shellSrc = (string) file_get_contents($themeDir . '/inc/class-localization.php');
    $staticOk = strpos($shellSrc, 'core_i18n_chrome()') !== false
        && strpos($shellSrc, "provide_registry' )") !== false
        && strpos($shellSrc, "null === self::core_i18n_chrome()") !== false
        && strpos($shellSrc, "add_filter( 'gettext'") !== false
        && strpos($shellSrc, 'translate_polylang_string_local( $translation, $text, $domain )') !== false
        && strpos($shellSrc, 'translate_public_string_local( $string )') !== false;
    $bootstrapSrc = (string) file_get_contents($pluginDir . '/partikulier-core.php');
    $staticOk = $staticOk && strpos($bootstrapSrc, 'I18nChromeService::register_gettext_filter()') !== false;
    $assert('C2A-011', $staticOk,
        'couture statique : shell (garde + extinction + provide_registry + replis *_local) et bootstrap plugin (enregistrement du filtre) vérifiés en source');

    // 12) Hygiène des modules : ≤300 lignes (3 classes plugin + shell + 4 traits thème).
    $files = [
        $pluginDir . '/src/Domain/I18n/I18nChromeService.php',
        $pluginDir . '/src/Domain/I18n/ChromeDictionary.php',
        $pluginDir . '/src/Domain/I18n/FormsDictionary.php',
        $themeDir . '/inc/class-localization.php',
        $themeDir . '/inc/class-localization-strings.php',
        $themeDir . '/inc/class-localization-chrome.php',
        $themeDir . '/inc/class-localization-forms.php',
        $themeDir . '/inc/class-localization-runtime.php',
        $themeDir . '/inc/class-localization-variants.php',
    ];
    $maxLines = 0; $oversize = [];
    foreach ($files as $f) {
        $n = count(file($f) ?: []);
        $maxLines = max($maxLines, $n);
        if ($n > 300) { $oversize[] = basename($f) . " ($n)"; }
    }
    $assert('C2A-012', $oversize === [],
        sprintf('modules ≤ 300 lignes : 9 fichiers du périmètre C2 conformes (max %d lignes)%s',
            $maxLines, $oversize !== [] ? ' — dépassements : ' . implode(', ', $oversize) : ''));

    // 13) Manifeste : 20 tables pk_ suivies, aucune côté thème (le lot C2
    //     n'ajoute aucune table — dictionnaires et chaîne de résolution purs).
    $manifest = Schema::domainTables();
    $themeOwned = array_filter($manifest, static fn(array $d) => ($d['owner'] ?? '') === 'theme');
    $assert('C2A-013', count($manifest) === 20 && $themeOwned === [],
        'manifeste : 20/20 tables pk_ suivies, 0 côté thème — la couche chrome est une bibliothèque de résolution sans stockage');
} catch (Throwable $error) {
    $assert('C2A-EXCEPTION', false, $error->getMessage());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'i18n-chrome-domain-contract (lot C2)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/i18n-chrome-domain-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
