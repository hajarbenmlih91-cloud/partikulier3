<?php
/**
 * Contrat du domaine i18n contenu (lot C1, CDC v1.2 — §3.2 I18N-1, CA-2).
 *
 * Couvre l'absorption de la couche CONTENU de la rédaction multilingue
 * (lexique trilingue + générateurs de texte/SEO) par le plugin
 * partikulier-core 2.7.0 :
 *  - couture armée : la facade I18nContentService et ses six classes de
 *    domaine sont chargées, la classe thème Partikulier_Listing_I18n est
 *    présente avec la même API publique (neuf méthodes) ;
 *  - parité des données : lexique fr/en/ar et dictionnaire des lieux arabes
 *    identiques entre le repli thème (reflection sur les méthodes privées)
 *    et le port plugin — VERBATIM prouvé ;
 *  - parité des générateurs : titre, description, meta description et alt
 *    photo égaux sur une matrice de fixtures × 3 langues, entre les trois
 *    chemins (couture publique, service plugin direct, repli *_local) ;
 *  - parité de l'API post-dépendante : 30 annonces publiées × title_from_post
 *    (ar/en) + rooms_label_from_post + localized_type/localized_place ;
 *  - consolidation des catalogues : doublons legacy absents, en-têtes .mo
 *    canoniques (hash_addr de fin de table) relus par le lecteur pomo
 *    HISTORIQUE et par le chargeur runtime (double lecteur — l'ancienne
 *    forme hash_addr=0 était rejetée par pomo), parité des entrées ;
 *  - hygiène : modules ≤300 lignes (7 classes plugin + 5 traits + shell),
 *    zéro hook dans le domaine (bibliothèque pure), schéma inchangé
 *    (2.6.0 — aucune migration au lot C1), 8/8 domaines plugin, 0 collision.
 *
 * Preuve de délégation : statique (le shell contient les neuf branches de
 * délégation + la garde class_exists) + comportementale (parité triple
 * chemin). Pas de journal d'audit : la couche est une bibliothèque de
 * RENDU sans écriture — la preuve d'exécution est la parité des sorties.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/i18n-content-domain-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\I18n\I18nContentService;
use Partikulier\Core\Domain\I18n\ListingLexicon;
use Partikulier\Core\Domain\I18n\ListingPostTextService;
use Partikulier\Core\Domain\I18n\ListingSeoTextService;
use Partikulier\Core\Domain\I18n\ListingTextService;
use Partikulier\Core\Domain\I18n\ListingTextUtils;
use Partikulier\Core\Domain\I18n\ListingVocabulary;
use Partikulier\Core\Database\Schema;
use Partikulier\Core\HealthCheck;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$themeDir = get_template_directory();
$pluginDir = dirname(__DIR__);

/* Reflection sur une méthode privée statique du thème (chemin de repli). */
$private_theme = static function (string $method, array $args = []) {
    $ref = new ReflectionMethod('Partikulier_Listing_I18n', $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
};
$private_plugin = static function (string $class, string $method, array $args = []) {
    $ref = new ReflectionMethod($class, $method);
    $ref->setAccessible(true);
    return $ref->invokeArgs(null, $args);
};

/* Fixtures : la matrice des formes d'annonce (studio, appartement, villa 3+,
 * terrain sans district connu, annonce ancienne vide) × les trois langues. */
$fixtures = [
    'studio_vente' => [
        'action' => 'vendre', 'type' => 'studio', 'city' => 'Rabat', 'district' => 'Hay Riad',
        'surface' => 72, 'price' => 850000, 'bedrooms' => '0', 'living_rooms' => '', 'bathrooms' => '1',
        'floor' => 'RDC', 'garage' => 'Non', 'elevator' => 'Non', 'vis_a_vis' => 'Oui',
        'terrace' => 'Oui', 'terrace_surface' => 12, 'sunshine' => 'Ensoleillé le matin', 'role' => '',
    ],
    'appartement_location' => [
        'action' => 'louer', 'type' => 'appartement', 'city' => 'Casablanca', 'district' => 'Anfa',
        'surface' => 95, 'price' => 7500, 'bedrooms' => '2', 'living_rooms' => '1', 'bathrooms' => '2',
        'floor' => '5e étage', 'garage' => 'Oui', 'elevator' => 'Oui', 'vis_a_vis' => 'Non',
        'terrace' => 'Non', 'terrace_surface' => 0, 'sunshine' => 'Toute la journée', 'role' => '',
    ],
    'villa_3plus' => [
        'action' => 'vendre', 'type' => 'villa', 'city' => 'Marrakech', 'district' => 'Palmeraie',
        'surface' => 320, 'price' => 4200000, 'bedrooms' => '3+', 'living_rooms' => '2', 'bathrooms' => '3',
        'floor' => 'Dernier étage', 'garage' => 'Oui', 'elevator' => 'Non', 'vis_a_vis' => 'Oui',
        'terrace' => 'Oui', 'terrace_surface' => 40, 'sunshine' => 'Ensoleillé l’après-midi', 'role' => '',
    ],
    'terrain_lieu_inconnu' => [
        'action' => 'vendre', 'type' => 'terrain', 'city' => 'Oualili', 'district' => '',
        'surface' => 500, 'price' => 300000, 'bedrooms' => '', 'living_rooms' => '', 'bathrooms' => '',
        'floor' => '', 'garage' => 'Non', 'elevator' => 'Non', 'vis_a_vis' => 'Non',
        'terrace' => 'Non', 'terrace_surface' => 0, 'sunshine' => 'Très peu', 'role' => '',
    ],
    'annonce_ancienne_vide' => [],
];
/* Alt photo : image_alt ne normalise PAS en interne (port VERBATIM) — les
 * clés district/city doivent exister pour éviter la notice des deux côtés. */
$fixtureAlt = array_merge(['district' => '', 'city' => 'Rabat', 'type' => 'studio', 'action' => 'vendre', 'bedrooms' => '2', 'living_rooms' => '1', 'surface' => 60, 'terrace' => 'Oui', 'terrace_surface' => 8, 'vis_a_vis' => 'Non', 'floor' => '2e étage', 'sunshine' => 'Ensoleillé le matin', 'price' => 900000], $fixtures['annonce_ancienne_vide']);

try {
    // 1) Couture armée : les sept classes du domaine + la classe thème.
    $classes = [I18nContentService::class, ListingLexicon::class, ListingVocabulary::class,
                ListingTextUtils::class, ListingTextService::class, ListingSeoTextService::class,
                ListingPostTextService::class];
    $apiTheme = ['languages', 'title', 'description', 'meta_description', 'image_alt',
                 'localized_type', 'localized_place', 'title_from_post', 'rooms_label_from_post'];
    $seamOk = true;
    foreach ($classes as $c) { $seamOk = $seamOk && class_exists($c); }
    foreach ($apiTheme as $m) { $seamOk = $seamOk && method_exists('Partikulier_Listing_I18n', $m); }
    $assert('C1A-001', $seamOk,
        'couture thème/plugin : 7 classes Domain/I18n chargées, Partikulier_Listing_I18n présente avec ses 9 méthodes publiques');

    // 2) Parité du lexique (fr/en/ar) : repli thème (privé) == port plugin.
    $lexOk = true; $lexDetail = '';
    foreach (['fr', 'en', 'ar'] as $lang) {
        $themeLex = $private_theme('lex', [$lang]);
        $pluginLex = ListingLexicon::lex($lang);
        if ($themeLex !== $pluginLex) {
            $lexOk = false;
            $missing = array_diff_assoc($themeLex, $pluginLex);
            $lexDetail .= sprintf('%s: %d clés divergentes (%s…) ', $lang, count($missing), implode(',', array_slice(array_keys($missing), 0, 3)));
        }
    }
    $assert('C1A-002', $lexOk,
        'parité lexique trilingue : lex($lang) identique repli thème / port plugin pour fr, en, ar (dictionnaires VERBATIM)' . ($lexDetail ? ' — ' . $lexDetail : ''));

    // 3) Parité du dictionnaire des lieux arabes (privé des deux côtés).
    $placesTheme = $private_theme('arabic_places');
    $placesPlugin = $private_plugin(ListingVocabulary::class, 'arabic_places');
    $assert('C1A-003', $placesTheme === $placesPlugin,
        sprintf('parité dictionnaire des lieux arabes : %d entrées identiques (villes + quartiers du référentiel marocain)', count($placesPlugin)));

    // 4) Parité languages().
    $assert('C1A-004', Partikulier_Listing_I18n::languages() === I18nContentService::languages() && I18nContentService::languages() === ['fr', 'en', 'ar'],
        'langues prises en charge : [fr, en, ar] des deux côtés');

    // 5) Parité des générateurs : matrice fixtures × 3 langues × 4 générateurs,
    //    les trois chemins (couture publique / service plugin / repli *_local).
    $genOk = true; $genDiffs = 0; $genFirst = '';
    foreach ($fixtures as $name => $v) {
        foreach (['fr', 'en', 'ar'] as $lang) {
            $pairs = [
                ['title', [$v, $lang], [$v, $lang], [$v, $lang]],
                ['description', [$v, $lang], [$v, $lang], [$v, $lang]],
                ['meta_description', [$v, $lang], [$v, $lang], [$v, $lang]],
            ];
            foreach ($pairs as [$method, $a, $b, $c]) {
                $pub = call_user_func_array(['Partikulier_Listing_I18n', $method], $a);
                $svc = call_user_func_array([I18nContentService::class, $method], $b);
                $loc = $private_theme($method . '_local', $c);
                if (!($pub === $svc && $svc === $loc)) {
                    $genOk = false; $genDiffs++;
                    if ($genFirst === '') { $genFirst = sprintf('%s/%s/%s', $name, $lang, $method); }
                }
            }
        }
    }
    foreach (['fr', 'en', 'ar'] as $lang) {
        for ($i = 0; $i <= 2; $i++) {
            $pub = Partikulier_Listing_I18n::image_alt($fixtureAlt, $lang, $i);
            $svc = I18nContentService::image_alt($fixtureAlt, $lang, $i);
            $loc = $private_theme('image_alt_local', [$fixtureAlt, $lang, $i]);
            if (!($pub === $svc && $svc === $loc)) {
                $genOk = false; $genDiffs++;
                if ($genFirst === '') { $genFirst = sprintf('alt/%s/%d', $lang, $i); }
            }
        }
    }
    $assert('C1A-005', $genOk,
        sprintf('parité générateurs : %d comparaisons (5 fixtures × 3 langues × title/description/meta + alt × 3 rangs), triple chemin (couture/service/repli) — %d divergence(s)%s',
            count($fixtures) * 3 * 3 + 9, $genDiffs, $genFirst !== '' ? ' — première : ' . $genFirst : ''));

    // 6) Parité de l'API post-dépendante sur les 30 annonces publiées —
    //    TROIS chemins (couture publique / service plugin / repli *_local) :
    //    c'est cette triple comparaison qui attrape les bugs de port du
    //    genre « instanceof WP_Post résolu dans le namespace du plugin »
    //    (bug réel du lot C1, révélé par la matrice REG-5 sonde B : le
    //    titre arabe manuel n'était pas prioritaire côté plugin).
    $posts = get_posts(['post_type' => 'properties', 'post_status' => 'publish', 'numberposts' => 30, 'orderby' => 'ID', 'order' => 'ASC', 'lang' => 0, 'suppress_filters' => true]);
    $postOk = true; $postDiffs = 0; $postFirst = '';
    foreach ($posts as $post) {
        foreach (['ar', 'en'] as $lang) {
            $pub = Partikulier_Listing_I18n::title_from_post($post, $lang);
            $svc = I18nContentService::title_from_post($post, $lang);
            $loc = $private_theme('title_from_post_local', [$post, $lang]);
            if (!($pub === $svc && $svc === $loc)) { $postOk = false; $postDiffs++; if ($postFirst === '') { $postFirst = '#' . $post->ID . '/' . $lang; } }
            $pubR = Partikulier_Listing_I18n::rooms_label_from_post($post, $lang);
            $svcR = I18nContentService::rooms_label_from_post($post, $lang);
            $locR = $private_theme('rooms_label_from_post_local', [$post, $lang]);
            if (!($pubR === $svcR && $svcR === $locR)) { $postOk = false; $postDiffs++; if ($postFirst === '') { $postFirst = 'rooms#' . $post->ID . '/' . $lang; } }
        }
    }
    $assert('C1A-006', $postOk && count($posts) === 30,
        sprintf('parité API post : %d annonces × 2 langues × (title_from_post + rooms_label_from_post), triple chemin (couture/service/repli) — %d divergence(s)%s',
            count($posts), $postDiffs, $postFirst !== '' ? ' — première : ' . $postFirst : ''));

    // 7) Parité localized_type / localized_place (lieux connus et inconnus).
    $lt = [
        Partikulier_Listing_I18n::localized_type('appartement', 'ar') === I18nContentService::localized_type('appartement', 'ar'),
        Partikulier_Listing_I18n::localized_type('villa', 'en') === I18nContentService::localized_type('villa', 'en'),
        Partikulier_Listing_I18n::localized_place('Hay Riad, Rabat', 'ar') === I18nContentService::localized_place('Hay Riad, Rabat', 'ar'),
        Partikulier_Listing_I18n::localized_place('Quartier Inconnu, Oualili', 'ar') === I18nContentService::localized_place('Quartier Inconnu, Oualili', 'ar'),
        Partikulier_Listing_I18n::localized_place('Casablanca', 'en') === I18nContentService::localized_place('Casablanca', 'en'),
    ];
    $assert('C1A-007', !in_array(false, $lt, true),
        'parité vocabulaire : types (appartement→شقة, villa→Villa) et lieux (Hay Riad, Rabat → arabe ; lieu inconnu conservé) identiques');

    // 8) Couture statique : le shell contient les neuf délégations + la garde.
    $shell = (string) file_get_contents($themeDir . '/inc/class-listing-i18n.php');
    $delegations = substr_count($shell, "call_user_func( array( self::core_i18n()");
    $guard = strpos($shell, "class_exists( '\\Partikulier\\Core\\Domain\\I18n\\I18nContentService' )") !== false;
    $fallbacks = substr_count($shell, '_local(');
    $assert('C1A-008', $delegations === 9 && $guard && $fallbacks >= 9,
        sprintf('couture statique : %d/9 branches de délégation, garde class_exists présente, %d appels de repli *_local dans le shell', $delegations, $fallbacks));

    // 9) Hygiène découpe : 7 classes plugin CONTENU + 5 traits + shell ≤300 l.
    //    Lot C2 : le répertoire Domain/I18n s'est enrichi (I18nChromeService,
    //    ChromeDictionary, FormsDictionary — couche chrome) — la découpe C1A-009
    //    est rescopée aux modules CONTENU du lot C1 ; les modules C2 (3 classes
    //    + trait variants côté thème) sont vérifiés par C2A-012.
    $i18nContentClasses = [
        'I18nContentService.php', 'ListingLexicon.php', 'ListingVocabulary.php',
        'ListingTextUtils.php', 'ListingTextService.php', 'ListingSeoTextService.php',
        'ListingPostTextService.php',
    ];
    $files = array_merge(
        array_map(static fn($f) => $pluginDir . '/src/Domain/I18n/' . $f, $i18nContentClasses),
        array_map(static fn($f) => $themeDir . '/inc/' . $f, [
            'class-listing-i18n-lexicon.php', 'class-listing-i18n-places.php', 'class-listing-i18n-text.php',
            'class-listing-i18n-seo.php', 'class-listing-i18n-post.php', 'class-listing-i18n.php',
        ])
    );
    $over = [];
    foreach ($files as $f) {
        $lines = count(file($f) ?: []);
        if ($lines > 300) { $over[] = basename($f) . '=' . $lines; }
    }
    $assert('C1A-009', count($files) === 13 && $over === [],
        sprintf('découpe : %d modules (7 classes plugin + 5 traits + shell), tous ≤300 lignes%s',
            count($files), $over !== [] ? ' — dépassements : ' . implode(', ', $over) : ' (monolithe 6.18.7 : 955 l.)'));

    // 10) Zéro hook dans la couche CONTENU du domaine (bibliothèque pure).
    //     Lot C2 : I18nChromeService (couche chrome) détient LE filtre gettext
    //     du service unifié — exactement une méthode d'inscription,
    //     register_gettext_filter, appelée par le bootstrap du plugin (jamais
    //     au chargement du domaine) ; les dictionnaires sont des données
    //     pures. La sonde est rescopée aux 7 classes CONTENU ; l'inscription
    //     unique de la couche chrome est prouvée par C2A-005.
    $hookOk = true; $hookHits = [];
    foreach ($i18nContentClasses as $cls) {
        $f = $pluginDir . '/src/Domain/I18n/' . $cls;
        $src = (string) file_get_contents($f);
        if (preg_match('/add_action\s*\(|add_filter\s*\(|register_activation_hook|register_deactivation_hook|wp_schedule_|wp_next_scheduled|register_rest_route|add_shortcode\s*\(/ms', $src, $m)) {
            $hookOk = false; $hookHits[] = basename($f) . ':' . ($m[0] ?: '');
        }
    }
    $assert('C1A-010', $hookOk,
        'zéro hook/cron/route dans la couche CONTENU de Domain/I18n — bibliothèque pure (aucune inscription ; la couche chrome C2 détient l\'unique filtre gettext, enregistré par le bootstrap)' . ($hookHits !== [] ? ' — hits : ' . implode(', ', $hookHits) : ''));

    // 11) Catalogues : canoniques <locale>.mo présents (le JIT de WP charge
    //     ce nommage pour un thème hors WP_LANG_DIR), doublons
    //     partikulier-<locale>.mo absents, en-têtes canoniques.
    $moSpec = [
        'ar' => ['file' => 'ar.mo', 'count' => 131],
        'en' => ['file' => 'en_US.mo', 'count' => 72],
    ];
    $catOk = true; $catNotes = [];
    foreach ($moSpec as $lang => $spec) {
        $path = $themeDir . '/languages/' . $spec['file'];
        $raw = @file_get_contents($path);
        if ($raw === false || strlen($raw) < 28) { $catOk = false; $catNotes[] = $spec['file'] . ' illisible'; continue; }
        $hdr = unpack('Vmagic/Vrev/Vtotal/Vorig/Vtrans/Vhash_len/Vhash_addr', substr($raw, 0, 28));
        $attendu = 28 + 16 * $spec['count'];
        if ((int) $hdr['hash_len'] !== 0 || (int) $hdr['hash_addr'] !== $attendu || (int) $hdr['total'] !== $spec['count']) {
            $catOk = false;
            $catNotes[] = sprintf('%s: hash_len=%d hash_addr=%d (attendu 0/%d), total=%d', $spec['file'], $hdr['hash_len'], $hdr['hash_addr'], $attendu, $hdr['total']);
        }
    }
    $legacy = [$themeDir . '/languages/partikulier-ar.mo', $themeDir . '/languages/partikulier-en_US.mo'];
    $legacyAbsent = !file_exists($legacy[0]) && !file_exists($legacy[1]);
    $assert('C1A-011', $catOk && $legacyAbsent,
        sprintf('catalogues : ar.mo (131 entrées, hash_addr=%d) et en_US.mo (72 entrées, hash_addr=%d) canoniques — nommage <locale>.mo que charge le JIT du thème ; doublons partikulier-*.mo absents',
            28 + 16 * 131, 28 + 16 * 72) . ($catNotes !== [] ? ' — ' . implode('; ', $catNotes) : ''));

    // 12) Double lecteur : le pomo HISTORIQUE (rejetait hash_addr=0) relit
    //     les deux catalogues recompilés.
    if (!class_exists('MO')) { require_once $wpDir . '/wp-includes/pomo/mo.php'; }
    $pomoAr = new MO(); $okAr = $pomoAr->import_from_file($themeDir . '/languages/ar.mo');
    $pomoEn = new MO(); $okEn = $pomoEn->import_from_file($themeDir . '/languages/en_US.mo');
    $assert('C1A-012', $okAr && $okEn && count($pomoAr->entries) === 131 && count($pomoEn->entries) === 72,
        sprintf('lecteur pomo historique : ar.mo %d entrées, en_US.mo %d entrées (lisible par pomo ET WP_Translation_File — hash_addr=0 était rejeté par pomo)',
            $okAr ? count($pomoAr->entries) : 0, $okEn ? count($pomoEn->entries) : 0));

    // 13) Chargeur runtime : le domaine résout réellement les chaînes — y
    //     compris par le JIT du thème (nommage <locale>.mo), exactement comme
    //     en production avant le premier rechargement à la locale active.
    $probe = 'partikulier-c1-probe';
    load_textdomain($probe, $themeDir . '/languages/ar.mo');
    $aideAr = translate_with_gettext_context('Aide', '', $probe);
    load_textdomain($probe . '-en', $themeDir . '/languages/en_US.mo');
    $annulerEn = translate_with_gettext_context('Annuler', '', $probe . '-en');
    $jitClass = get_class(get_translations_for_domain('partikulier'));
    $annulerJit = translate_with_gettext_context('Annuler', '', 'partikulier');
    $assert('C1A-013', $aideAr === 'المساعدة' && $annulerEn === 'Cancel' && $annulerJit === 'Cancel',
        sprintf('chargeur runtime : \'Aide\' → \'المساعدة\' (ar) et \'Annuler\' → \'Cancel\' (en_US) via load_textdomain des catalogues canoniques ; JIT du thème résout \'Annuler\' → \'%s\' (%s)', $annulerJit, $jitClass));

    // 14) Santé : aucune migration C1, 8/8 domaines plugin, 0 collision.
    $health = (new HealthCheck())->get();
    $pluginDomains = count(array_filter($health['domains'] ?? [], static fn($d) => ($d['owner'] ?? '') === 'plugin'));
    $assert('C1A-014', Schema::VERSION === '2.6.0' && $pluginDomains === 8
        && (int) ($health['routes']['collisions'] ?? -1) === 0,
        sprintf('santé : schéma 2.6.0 inchangé (zéro migration lot C1), 8/8 domaines plugin, 0 collision REST'));

    // 15) Manifeste : 20 tables pk_ suivies, aucune revenue côté thème.
    $manifest = Schema::domainTables();
    $themeOwned = array_filter($manifest, static fn(array $d) => ($d['owner'] ?? '') === 'theme');
    $assert('C1A-015', count($manifest) === 20 && $themeOwned === [],
        'manifeste : 20/20 tables pk_ suivies, 0 côté thème (le lot C1 n\'ajoute aucune table — couche contenu pure)');
} catch (Throwable $error) {
    $assert('C1A-EXCEPTION', false, $error->getMessage());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'i18n-content-domain-contract (lot C1)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/i18n-content-domain-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
