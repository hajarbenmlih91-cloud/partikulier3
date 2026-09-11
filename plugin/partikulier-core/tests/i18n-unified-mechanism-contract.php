<?php
/**
 * Contrat du mécanisme unique de traduction (lot C3, CDC v1.2 §3.2 I18N-1 —
 * lot C « Unification i18n », critère de sortie « un seul mécanisme de
 * traduction actif »).
 *
 * Couvre le transfert du DERNIER mécanisme encore côté thème au plugin
 * partikulier-core 2.9.0 : le chargeur runtime des textdomains.
 *  - chargeur unique : I18nDomainLoader chargé, classe pure (trois points
 *    d'inscription runtime appelés par le bootstrap UNIQUEMENT, zéro filtre),
 *    module ≤300 lignes ;
 *  - inscription du bootstrap : after_setup_theme@1 (es anticipé), init@5
 *    (rafraîchissement à la locale effective), wp@1 (réaffirmation par slug
 *    public + es) ;
 *  - filtre gettext : exactement UN côté partikulier-core (I18nChromeService,
 *    lot C2), ZÉRO côté thème ;
 *  - extinction source : les trois chargeurs du trait runtime du thème
 *    portent leurs gardes dormantes (plugin présent) ;
 *  - comportement runtime : matrice de rechargement en/ar/fr — le catalogue
 *    canonique <locale>.mo du plugin sert le domaine réel (« Aide » →
 *    « المساعدة », « Annuler » → « Cancel »), la langue source fr laisse les
 *    msgids ; le domaine « es » d'Estatik recharge (popup 6.17.31) ;
 *  - extinction comportementale : appeler les chargeurs dormants du thème ne
 *    change PAS l'état du domaine (preuve REG-5 inversée : avec le plugin, le
 *    mécanisme unique est bien unique) ;
 *  - parité des catalogues : copies plugin/languages ↔ thème/languages
 *    identiques octet par octet (discipline de consolidation du lot C) ;
 *  - transfert des variantes (état final B6) : TranslationVariantsService
 *    propriétaire, garde de délégation côté thème, domaine health owner=plugin ;
 *  - santé : 2.9.0 / 6.19.0, schéma 2.6.0 (zéro migration C3), 8/8
 *    domaines, 0 collision ; hygiène : tous les modules Domain/I18n ≤300 lignes.
 *
 * Environnement : la matrice de rechargement appelle reload_for_slug()
 * explicitement (couture de test) — le contrat ne dépend NI de Polylang NI
 * d'une requête : rejouable en CLI comme sur le runner CI. L'état bootstrap
 * suppose une locale de site en_US (même convention que C1A-013).
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/i18n-unified-mechanism-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "PK_WP_DIR doit pointer vers une installation WordPress\n");
    exit(2);
}
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) {
    fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n");
    exit(2);
}
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\I18n\I18nDomainLoader;
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
    // 1) Chargeur unique présent, classe pure, module ≤300 lignes.
    $loaderFile = $pluginDir . '/src/Domain/I18n/I18nDomainLoader.php';
    $loaderSrc = (string) file_get_contents($loaderFile);
    $loaderLines = count(file($loaderFile) ?: []);
    $hookCalls = substr_count($loaderSrc, "add_action('");
    $assert('C3A-001', class_exists(I18nDomainLoader::class) && is_file($loaderFile)
        && $loaderLines <= 300 && strpos($loaderSrc, 'add_filter(') === false && $hookCalls === 3,
        sprintf('chargeur unique : I18nDomainLoader chargé (%d lignes ≤300), classe pure — 3 inscriptions runtime (toutes dans register_runtime, appelée par le bootstrap), zéro filtre', $loaderLines));

    // 2) Inscription du bootstrap : les trois points runtime.
    $pEarly = has_action('after_setup_theme', 'Partikulier\Core\Domain\I18n\I18nDomainLoader::prime_estatik_domain');
    $pInit = has_action('init', 'Partikulier\Core\Domain\I18n\I18nDomainLoader::refresh_domain');
    $pWp = has_action('wp', 'Partikulier\Core\Domain\I18n\I18nDomainLoader::reload_active_domains');
    $assert('C3A-002', $pEarly === 1 && $pInit === 5 && $pWp === 1,
        sprintf('inscription bootstrap : after_setup_theme@%s (es anticipé), init@%s (locale effective), wp@%s (slug public + es)',
            $pEarly === false ? 'absent' : $pEarly, $pInit === false ? 'absent' : $pInit, $pWp === false ? 'absent' : $pWp));

    // 3) Filtre gettext : exactement UN côté partikulier-core, ZÉRO côté thème.
    $gettextCallbacks = [];
    if (isset($GLOBALS['wp_filter']['gettext']) && $GLOBALS['wp_filter']['gettext'] instanceof WP_Hook) {
        foreach ($GLOBALS['wp_filter']['gettext']->callbacks as $priority => $entries) {
            foreach ($entries as $entry) {
                $fn = $entry['function'] ?? null;
                $repr = is_array($fn) ? (is_string($fn[0] ?? null) ? $fn[0] . '::' . ($fn[1] ?? '') : get_class($fn[0]) . '::' . ($fn[1] ?? ''))
                    : (is_string($fn) ? $fn : 'closure');
                $gettextCallbacks[$priority . ':' . $repr] = $repr;
            }
        }
    }
    $pluginFilters = array_filter($gettextCallbacks, static fn($r) => strpos($r, 'I18nChromeService') !== false);
    $themeFilters = array_filter($gettextCallbacks, static fn($r) => strpos($r, 'Partikulier_Localization') !== false);
    $assert('C3A-003', count($pluginFilters) === 1 && count($themeFilters) === 0,
        sprintf('filtre gettext : %d inscription(s) partikulier-core (I18nChromeService, lot C2), %d côté thème — total enregistrées : %d',
            count($pluginFilters), count($themeFilters), count($gettextCallbacks)));

    // 4) Extinction en source : les gardes dormantes du trait runtime du thème.
    $runtimeSrc = (string) file_get_contents($themeDir . '/inc/class-localization-runtime.php');
    $guardCore = substr_count($runtimeSrc, 'DomainRegistry');
    $guardLoader = substr_count($runtimeSrc, 'I18nDomainLoader');
    $assert('C3A-004', $guardCore >= 2 && $guardLoader >= 1
        && strpos($runtimeSrc, 'load_theme_textdomain') !== false,
        sprintf('extinction source : les trois chargeurs du thème portent leurs gardes (DomainRegistry ×%d pour partikulier, I18nDomainLoader ×%d pour es) — le repli autonome reste en place (REG-5)',
            $guardCore, $guardLoader));

    // 5) Mapping des locales canoniques.
    $mapOk = I18nDomainLoader::locale_for_slug('en') === 'en_US'
        && I18nDomainLoader::locale_for_slug('ar') === 'ar'
        && I18nDomainLoader::locale_for_slug('fr') === ''
        && I18nDomainLoader::locale_for_slug('xx') === '';
    $assert('C3A-005', $mapOk, 'mapping des locales : en→en_US, ar→ar, fr/autre→source (msgids)');

    // 6) État du bootstrap en CLI (re-preuve C1A-013 par le chargeur unique).
    $annulerBoot = translate_with_gettext_context('Annuler', '', 'partikulier');
    $assert('C3A-006', $annulerBoot === 'Cancel',
        sprintf('bootstrap : « Annuler » → « %s » (catalogue canonique en_US.mo chargé par le chargeur unique — portabilité WP 6.2 → 7.x)', $annulerBoot));

    // 7) Rechargement runtime ar : catalogue canonique ar.mo sur le domaine réel.
    $reportAr = I18nDomainLoader::reload_for_slug('ar');
    $aideAr = translate_with_gettext_context('Aide', '', 'partikulier');
    $assert('C3A-007', $reportAr['partikulier'] === 'ar' && $aideAr === 'المساعدة',
        sprintf('runtime ar : rapport partikulier=%s, « Aide » → « %s »', $reportAr['partikulier'], $aideAr));

    // 8) Rechargement runtime en : idempotence + catalogue en_US.
    $reportEn = I18nDomainLoader::reload_for_slug('en');
    $annulerEn = translate_with_gettext_context('Annuler', '', 'partikulier');
    $reportEnBis = I18nDomainLoader::reload_for_slug('en');
    $assert('C3A-008', $reportEn['partikulier'] === 'en_US' && $annulerEn === 'Cancel' && $reportEnBis['partikulier'] === 'en_US(deja)',
        sprintf('runtime en : « Annuler » → « %s », second passage idempotent (%s)', $annulerEn, $reportEnBis['partikulier']));

    // 9) Langue source fr : domaine vide, les msgids SONT la forme française.
    $reportFr = I18nDomainLoader::reload_for_slug('fr');
    $aideFr = translate_with_gettext_context('Aide', '', 'partikulier');
    $refreshFr = I18nDomainLoader::refresh_domain('fr_FR');
    $assert('C3A-009', $reportFr['partikulier'] === 'source(msgid)' && $aideFr === 'Aide' && $refreshFr === 'source(msgid)',
        sprintf('runtime fr : domaine source (msgid), « Aide » → « %s », refresh fr_FR → %s', $aideFr, $refreshFr));

    // 10) Domaine « es » (Estatik) : rechargement popup 6.17.31 par le chargeur unique.
    I18nDomainLoader::reload_for_slug('ar');
    $esCatalogAr = I18nDomainLoader::estatik_catalog();
    $esPopupAr = translate_with_gettext_context('Sign in or register', '', 'es');
    $reportFr2 = I18nDomainLoader::reload_for_slug('fr');
    $esCatalogFr = I18nDomainLoader::estatik_catalog();
    $assert('C3A-010', is_string($esCatalogAr) && substr($esCatalogAr, -8) === 'es-ar.mo'
        && $esPopupAr === 'سجّل الدخول أو أنشئ حسابًا'
        && is_string($esCatalogFr) && substr($esCatalogFr, -11) === 'es-fr_FR.mo',
        sprintf('domaine es : ar → %s (« Sign in or register » → « %s »), fr → %s', (string) $esCatalogAr, $esPopupAr, (string) $esCatalogFr));

    // 11) Extinction comportementale : les chargeurs dormants du thème ne
    //     changent PAS l'état du domaine quand le plugin est actif.
    I18nDomainLoader::reload_for_slug('en');
    $localeBefore = I18nDomainLoader::loaded_locale();
    Partikulier_Localization::load_textdomain();
    Partikulier_Localization::load_active_textdomain();
    Partikulier_Localization::load_estatik_textdomain();
    $localeAfter = I18nDomainLoader::loaded_locale();
    $annulerAfter = translate_with_gettext_context('Annuler', '', 'partikulier');
    $assert('C3A-011', $localeBefore === 'en_US' && $localeAfter === 'en_US' && $annulerAfter === 'Cancel',
        sprintf('extinction comportementale : chargeurs thème appelés → locale %s → %s inchangée, « Annuler » → « %s »',
            (string) $localeBefore, (string) $localeAfter, $annulerAfter));

    // 12) Parité des catalogues : plugin ↔ thème identiques (discipline C).
    $pairs = ['ar.mo', 'en_US.mo'];
    $parity = true;
    $parityNotes = [];
    foreach ($pairs as $file) {
        $a = (string) @file_get_contents($pluginDir . '/languages/' . $file);
        $b = (string) @file_get_contents($themeDir . '/languages/' . $file);
        if ($a === '' || $a !== $b) {
            $parity = false;
            $parityNotes[] = $file;
        }
    }
    $potBoth = is_file($pluginDir . '/languages/partikulier.pot') && is_file($themeDir . '/languages/partikulier.pot');
    $assert('C3A-012', $parity && $potBoth,
        'parité catalogues : plugin/languages ↔ thème/languages identiques octet par octet (ar.mo, en_US.mo, partikulier.pot des deux côtés — source canonique plugin, copie de repli thème)' . ($parityNotes !== [] ? ' — écarts : ' . implode(', ', $parityNotes) : ''));

    // 13) Transfert des variantes (état final B6, consolidé au C3).
    $variantsSrc = (string) file_get_contents($themeDir . '/inc/class-localization-variants.php');
    $variantsGuard = strpos($variantsSrc, 'TranslationVariantsService') !== false;
    $variantsService = class_exists('\Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService');
    $health = (new HealthCheck())->get();
    $variantsOwner = ($health['domains']['translation_variants']['owner'] ?? '') === 'plugin';
    $assert('C3A-013', $variantsGuard && $variantsService && $variantsOwner,
        sprintf('variantes : service plugin %s, garde de délégation thème %s, owner health=%s',
            $variantsService ? 'présent' : 'absent', $variantsGuard ? 'présente' : 'absente', $health['domains']['translation_variants']['owner'] ?? '?'));

    // 14) Santé et versions : 2.9.0 / 6.19.0, schéma figé 2.6.0, 8/8, 0 collision.
    $themeVersion = wp_get_theme()->get('Version');
    $pluginDomains = count(array_filter($health['domains'] ?? [], static fn($d) => ($d['owner'] ?? '') === 'plugin'));
    $assert('C3A-014', PARTIKULIER_CORE_VERSION === '2.9.0' && $themeVersion === '6.19.0'
        && Schema::VERSION === '2.6.0' && $pluginDomains === 8
        && (int) ($health['routes']['collisions'] ?? -1) === 0,
        sprintf('santé : plugin %s, thème %s, schéma %s (zéro migration C3), %d/8 domaines, 0 collision',
            PARTIKULIER_CORE_VERSION, $themeVersion, Schema::VERSION, $pluginDomains));

    // 15) Hygiène : tous les modules Domain/I18n du plugin ≤300 lignes.
    $over = [];
    $i18nFiles = glob($pluginDir . '/src/Domain/I18n/*.php') ?: [];
    foreach ($i18nFiles as $f) {
        $lines = count(file($f) ?: []);
        if ($lines > 300) {
            $over[] = basename($f) . '=' . $lines;
        }
    }
    $assert('C3A-015', $over === [] && count($i18nFiles) >= 10,
        sprintf('hygiène : %d modules Domain/I18n, tous ≤300 lignes%s', count($i18nFiles), $over !== [] ? ' — dépassements : ' . implode(', ', $over) : ''));
} catch (Throwable $error) {
    $assert('C3A-EXCEPTION', false, $error->getMessage());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'i18n-unified-mechanism-contract (lot C3)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/i18n-unified-mechanism-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
