<?php
/**
 * Contrat SE-025 — front trilingue ×3 langues (E-2501→E-2504, micro-lot
 * pré-prod 2.10.8/6.20.7 — CDC v5.7.1 §5, revue A-2 : « le seul risque
 * vert en CI, cassé en prod »).
 *
 * Ce contrat vit dans le workflow « Contrats de recette (WordPress) »
 * APRÈS l'étape d'installation de Polylang (E-2501). Les contrats front
 * précédents (front-assets, se022, se043, se034, search-arrays) ont tous
 * couru sur le site monolingue FR ; SE-025 est la seule étape qui
 * verrouille le site AUGMENTÉ par Polylang, config de référence épinglée
 * dans docs/INSTALLATION.md (E-2503) : fr (défaut) / en / ar (RTL),
 * force_lang=1, hide_default=0, CPT properties traduisible.
 *
 * Démo négative (E-2504) : E25-001 échoue structurellement si Polylang
 * est absent ou si la config a dérivé — le run est rouge, l'oracle ne
 * peut pas être vert « par accident » sur l'exigence trilingue. La
 * démonstration a été faite sur le banc : même contrat, sans Polylang →
 * E25-001 FAIL, exit 1.
 *
 * Ce que la batterie verrouille (E-2502) :
 *  - E25-001 : Polylang actif + config de référence (3 langues fr/en/ar,
 *              fr défaut, ar RTL, force_lang=1, hide_default=0, CPT
 *              properties traduisible) ;
 *  - E25-002→004 : accueil /fr/ /en/ /ar/ → 200 direct, dont /ar/ porte
 *              dir="rtl" + lang="ar" ;
 *  - E25-005→007 : archive des annonces ×3 langues → 200 direct ;
 *  - E25-008→010 : fiche traduite ×3 langues → 200 direct, dont /ar/
 *              porte dir="rtl" + lang="ar" ;
 *  - E25-011 : cluster hreflang fr/en/ar + x-default sur chaque fiche
 *              traduite, les 3 URLs distinctes (Polylang émet les
 *              alternates de langues, le thème complète x-default —
 *              inc/class-seo.php::hreflang_head) ;
 *  - E25-012 : zéro marqueur d'erreur PHP sur les 9 corps rendus.
 *
 * Fixtures auto-contenues (hérmeticité se043) : trio d'annonces fr/en/ar
 * liées par pll_set_post_language + pll_save_post_translations, ville
 * unique par run (_pk_url_city), suppression et purge du cache du run
 * en finally.
 *
 * Rejouable :
 *   PK_BASE=http://127.0.0.1:8099 PK_WP_DIR=<wp> PK_COMMIT=<sha> \
 *     php theme/partikulier/tests/se025-trilingual-routes-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$base = rtrim((string) (getenv('PK_BASE') ?: ''), '/');
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if ($base === '' || !preg_match('#^https?://#', $base)) { fwrite(STDERR, "PK_BASE doit pointer vers le serveur démarré\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

/* ── E25-001 : Polylang actif + config de référence (la démo négative E-2504) ── */
$configOk = false;
$configBits = [];
$langs = [];
if (defined('POLYLANG_VERSION') && function_exists('pll_default_language') && isset($GLOBALS['polylang'])) {
    foreach ((array) PLL()->model->get_languages_list() as $lang) {
        $langs[(string) $lang->slug] = $lang;
    }
    $options = (array) get_option('polylang', []);
    $translatedTypes = (array) ($options['post_types'] ?? []);
    $configBits = [
        'trois langues' => isset($langs['fr'], $langs['en'], $langs['ar']),
        'ar rtl' => isset($langs['ar']) && (int) $langs['ar']->is_rtl === 1,
        'force_lang=1' => (int) ($options['force_lang'] ?? 0) === 1,
        // hide_default est stocké booléen par Polylang 3.7+ (false = le
        // défaut garde son préfixe /fr/) : le contrôle accepte 0 ET false.
        'hide_default=0' => array_key_exists('hide_default', $options) && empty($options['hide_default']),
        'fr défaut' => pll_default_language() === 'fr',
        'properties traduisible' => in_array(PARTIKULIER_ESTATIK_POST_TYPE, $translatedTypes, true),
    ];
    $configOk = !in_array(false, $configBits, true);
} else {
    $configBits = ['polylang actif' => false];
}
$assert('E25-001', $configOk,
    sprintf('config de référence : %s (%s)',
        $configOk ? 'conforme' : 'NON CONFORME',
        implode(', ', array_keys(array_filter($configBits, static fn(bool $v): bool => !$v)) ?: ['tout conforme'])) .
    (!$configOk ? ' — sans Polylang ou avec une config divergente, ce contrat est rouge (démo négative E-2504)' : ''));

/* ── Fixtures : trio fr/en/ar lié, ville unique par run (pattern se043) ── */
wp_set_current_user(1); // le nettoyage exige les capabilities de suppression
$cpt = PARTIKULIER_ESTATIK_POST_TYPE;
$run = bin2hex(random_bytes(4));          // hérmeticité : slugs uniques par run
$city = 'se025ville-' . $run;
$mk = static function (string $lang) use ($cpt, $city, $run): int {
    $slug = 'se025-' . $run . '-' . $lang;
    $id = wp_insert_post([
        'post_type' => $cpt, 'post_status' => 'publish',
        'post_title' => 'SE-025 ' . strtoupper($lang) . ' ' . $run,
        'post_name' => $slug, 'post_author' => 1,
    ]);
    if ($id > 0) { update_post_meta($id, '_pk_url_city', $city); }
    return (int) $id;
};
$fr = $mk('fr');
$en = $mk('en');
$ar = $mk('ar');
/* Sans Polylang (démo négative E-2504), ces appels sont sautés : les posts
   existent mais ne sont ni langés ni liés — toutes les assertions suivantes
   échouent PROPREMENT (jamais de fatal « undefined function »). */
$pllPret = function_exists('pll_set_post_language') && function_exists('pll_save_post_translations');
if ($pllPret) {
    pll_set_post_language($fr, 'fr');
    pll_set_post_language($en, 'en');
    pll_set_post_language($ar, 'ar');
    pll_save_post_translations(['fr' => $fr, 'en' => $en, 'ar' => $ar]);
}

/* ── URLs attendues : les helpers DU THÈME sont la source de vérité produit ── */
$homes = [
    'fr' => (string) pk_localized_home_url('fr'),
    'en' => (string) pk_localized_home_url('en'),
    'ar' => (string) pk_localized_home_url('ar'),
];
$archives = [
    'fr' => $homes['fr'] . 'annonces/',
    'en' => $homes['en'] . 'annonces/',
    'ar' => $homes['ar'] . 'annonces/',
];
$fiches = [
    'fr' => (string) get_permalink($fr),
    'en' => (string) get_permalink($en),
    'ar' => (string) get_permalink($ar),
];

/** Sonde GET : code + corps (sans suivre les redirections — 200 direct exigé). */
$probe = static function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = (string) curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'body' => is_string($raw) ? $raw : '', 'error' => $error];
};

$rtlDir = static fn(string $body): bool => (bool) preg_match('#<html[^>]*\bdir="rtl"#i', $body);
$langAr = static fn(string $body): bool => (bool) preg_match('#<html[^>]*\blang="ar"#i', $body);

/** hreflang d'un corps : [code => href], attributs dans n'importe quel ordre. */
$hreflangs = static function (string $body): array {
    $out = [];
    if ((bool) preg_match_all('#<link\b[^>]*rel="alternate"[^>]*>#i', $body, $tags)) {
        foreach ($tags[0] as $tag) {
            if ((bool) preg_match('#hreflang="([^"]+)"#i', $tag, $hl) && (bool) preg_match('#href="([^"]+)"#i', $tag, $href)) {
                $out[strtolower($hl[1])] = html_entity_decode($href[1], ENT_QUOTES);
            }
        }
    }
    return $out;
};

/** Purge des fichiers cache (.html) écrits pour ce run. */
$upload = wp_get_upload_dir();
$cacheDir = trailingslashit($upload['basedir']) . 'partikulier-cache';
$purgeRunCache = static function () use ($cacheDir, $run): void {
    if (is_dir($cacheDir)) {
        foreach ((array) glob($cacheDir . '/*' . $run . '*') ?: [] as $cf) { @wp_delete_file($cf); }
    }
};

$bodies = [];   // les 9 corps, pour E25-012

try {
    /* E25-002→004 : accueil ×3 langues → 200 direct ; /ar/ porte dir=rtl + lang=ar. */
    foreach (['fr', 'en', 'ar'] as $i => $lang) {
        $r = $probe($homes[$lang]);
        $bodies['home-' . $lang] = $r['body'];
        $id = sprintf('E25-%03d', 2 + $i);
        if ('ar' === $lang) {
            $assert($id, $r['status'] === 200 && $rtlDir($r['body']) && $langAr($r['body']),
                sprintf('accueil AR %s : HTTP %d, dir="rtl" %s, lang="ar" %s',
                    $homes[$lang], $r['status'], $rtlDir($r['body']) ? '✓' : 'ABSENT', $langAr($r['body']) ? '✓' : 'ABSENT'));
        } else {
            $assert($id, $r['status'] === 200,
                sprintf('accueil %s %s : HTTP %d (attendu 200 direct)%s', strtoupper($lang), $homes[$lang], $r['status'],
                    $r['error'] !== '' ? ' — ' . $r['error'] : ''));
        }
    }

    /* E25-005→007 : archive des annonces ×3 langues → 200 direct. */
    foreach (['fr', 'en', 'ar'] as $i => $lang) {
        $r = $probe($archives[$lang]);
        $bodies['archive-' . $lang] = $r['body'];
        $id = sprintf('E25-%03d', 5 + $i);
        if ('ar' === $lang) {
            $assert($id, $r['status'] === 200 && $rtlDir($r['body']) && $langAr($r['body']),
                sprintf('archive AR %s : HTTP %d, dir="rtl" %s, lang="ar" %s',
                    $archives[$lang], $r['status'], $rtlDir($r['body']) ? '✓' : 'ABSENT', $langAr($r['body']) ? '✓' : 'ABSENT'));
        } else {
            $assert($id, $r['status'] === 200,
                sprintf('archive %s %s : HTTP %d (attendu 200 direct)', strtoupper($lang), $archives[$lang], $r['status']));
        }
    }

    /* E25-008→010 : fiche traduite ×3 langues → 200 direct ; /ar/ RTL. */
    foreach (['fr', 'en', 'ar'] as $i => $lang) {
        $r = $probe($fiches[$lang]);
        $bodies['fiche-' . $lang] = $r['body'];
        $id = sprintf('E25-%03d', 8 + $i);
        if ('ar' === $lang) {
            $assert($id, $r['status'] === 200 && $rtlDir($r['body']) && $langAr($r['body']),
                sprintf('fiche AR %s : HTTP %d, dir="rtl" %s, lang="ar" %s',
                    $fiches[$lang], $r['status'], $rtlDir($r['body']) ? '✓' : 'ABSENT', $langAr($r['body']) ? '✓' : 'ABSENT'));
        } else {
            $assert($id, $r['status'] === 200,
                sprintf('fiche %s %s : HTTP %d (attendu 200 direct)', strtoupper($lang), $fiches[$lang], $r['status']));
        }
    }

    /* E25-011 : cluster hreflang fr/en/ar + x-default sur CHAQUE fiche
       traduite, les 3 URLs distinctes (Polylang émet fr/en/ar, le thème
       complète x-default — jamais de doublon contradictoire). */
    $clusterOk = true;
    $clusterDetail = [];
    foreach (['fr', 'en', 'ar'] as $lang) {
        $alt = $hreflangs($bodies['fiche-' . $lang]);
        $has = isset($alt['fr'], $alt['en'], $alt['ar'], $alt['x-default']);
        $distinct = $has
            && untrailingslashit($alt['fr']) === untrailingslashit($fiches['fr'])
            && untrailingslashit($alt['en']) === untrailingslashit($fiches['en'])
            && untrailingslashit($alt['ar']) === untrailingslashit($fiches['ar']);
        if (!$has || !$distinct) { $clusterOk = false; }
        $clusterDetail[] = sprintf('%s : %s', $lang, implode('/', array_keys($alt)) ?: 'aucun alternate');
    }
    $assert('E25-011', $clusterOk,
        sprintf('hreflang fiches traduites : %s (attendu fr+en+ar+x-default, hrefs distinctes = les 3 fiches)',
            implode(' ; ', $clusterDetail)));

    /* E25-012 : zéro marqueur d'erreur PHP sur les 9 corps rendus. */
    $markers = '#(Fatal error|Parse error|PHP Warning|PHP Notice|PHP Deprecated|PHP Fatal)#i';
    $dirty = [];
    foreach ($bodies as $cle => $body) {
        if ((bool) preg_match($markers, $body)) { $dirty[] = $cle; }
    }
    $assert('E25-012', $dirty === [],
        sprintf('marqueurs d\'erreur PHP sur les 9 corps : %s', $dirty === [] ? 'aucun' : implode(', ', $dirty)));
} catch (Throwable $error) {
    $assert('E25-EXCEPTION', false, $error->getMessage());
} finally {
    /* Sortie propre : fixtures du trio (utilisateur admin requis), cache du run. */
    foreach ([$fr, $en, $ar] as $fixId) {
        if ($fixId > 0) { wp_delete_post($fixId, true); }
    }
    $purgeRunCache();
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se025-trilingual-routes-contract (front trilingue ×3 langues : 9 URLs, RTL, hreflang — E-2501→E-2504)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php theme/partikulier/tests/se025-trilingual-routes-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
