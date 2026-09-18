<?php
/**
 * Contrat SE-024 — dédoublonnage des dictionnaires i18n + inventaire DP-1
 * (micro-lot pré-prod 2.10.8/6.20.7, E-2401→E-2403).
 *
 * Les dictionnaires trilingues (FormsDictionary / ChromeDictionary côté
 * plugin, miroirs thème, registre chrome public) contenaient 37 clés
 * déclarées plusieurs fois dans un même littéral. En PHP la dernière
 * occurrence écrase silencieusement les précédentes : le dédoublonnage
 * (E-2401) conserve donc la DERNIÈRE occurrence — la valeur déjà servie
 * (défaut DP-1). Aucune valeur servie ne change (dump runtime avant/après
 * identique : 276 entrées, 0 divergence).
 *
 * Ce contrat verrouille :
 *  - E24-001 : les 5 fichiers sources ne déclarent plus AUCUNE clé
 *    dupliquée (scan tokenizer, même logique que la gate
 *    scripts/check-duplicate-keys.php) ;
 *  - E24-002 : dictionnaire forms FIGÉ — 140 entrées, empreinte des clés
 *    et des entrées épinglées (toute évolution = mise à jour consciente
 *    du contrat, E-2403 « entrées uniques figées ») ;
 *  - E24-003 : dictionnaire chrome FIGÉ — 136 entrées, empreintes épinglées ;
 *  - E24-004 : registre chrome public FIGÉ — 150 entrées en contexte WP
 *    (121 littérales + 29 clés setting_* ajoutées par le shell), empreintes
 *    épinglées ;
 *  - E24-005 : DP-1 — les 16 clés divergentes servent la valeur conservée
 *    (dernière occurrence, inventaire docs/INVENTAIRE-DP1-SE024.md) ;
 *  - E24-006 : parité plugin ↔ thème des dictionnaire forms + chrome
 *    (port VERBATIM re-prouvé après dédoublonnage, complément C2A-002/003) ;
 *  - E24-007 : la gate DuplicateArrayKey (E-2402) est câblée au lint du
 *    dépôt (scripts/check-duplicate-keys.php appelé par scripts/lint.sh).
 *
 * Contrat en lecture seule : aucune écriture, aucune table, sortie propre
 * par construction.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_REPO_DIR=<racine> php
 * partikulier-core/tests/se024-i18n-dedup-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
$repoDir = getenv('PK_REPO_DIR') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
if ($repoDir === '' || !is_dir($repoDir)) { fwrite(STDERR, "PK_REPO_DIR doit pointer vers la racine du dépôt\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\I18n\ChromeDictionary;
use Partikulier\Core\Domain\I18n\FormsDictionary;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

/** Empreintes de figeage (clés triées — indépendantes de l'ordre d'itération). */
$fingerprint = static function (array $entries): array {
    ksort($entries, SORT_STRING);
    return array(
        'count' => count($entries),
        'keys_md5' => md5(implode("\x1f", array_keys($entries))),
        'entries_md5' => md5(json_encode($entries, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
    );
};

/** Scan tokenizer d'un fichier : clés dupliquées (le code n'est pas exécuté). */
$scanDuplicates = static function (string $file): array {
    $src = @file_get_contents($file);
    if ($src === false) { return null; }
    $frames = array(); $pendingKey = null; $pendingArray = false; $dups = array();
    foreach (token_get_all($src) as $tok) {
        if (is_array($tok)) {
            list($id, $text) = $tok;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) { continue; }
            if ($id === T_ARRAY) { $pendingArray = true; continue; }
            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_LNUMBER) {
                $pendingKey = $id === T_CONSTANT_ENCAPSED_STRING ? stripcslashes(substr($text, 1, -1)) : $text;
                continue;
            }
            if ($id === T_DOUBLE_ARROW) {
                if ($pendingKey !== null && !empty($frames)) { $frames[count($frames) - 1]['keys'][$pendingKey][] = 1; }
                $pendingKey = null; continue;
            }
            $pendingKey = null;
            continue;
        }
        if ($tok === '(') {
            $frames[] = array('literal' => $pendingArray, 'keys' => array()); $pendingArray = false; $pendingKey = null;
        } elseif ($tok === '[') {
            $frames[] = array('literal' => true, 'keys' => array()); $pendingKey = null;
        } elseif ($tok === ')' || $tok === ']') {
            $f = array_pop($frames);
            if ($f !== null && $f['literal']) {
                foreach ($f['keys'] as $k => $marks) { if (count($marks) > 1) { $dups[$k] = count($marks); } }
            }
            $pendingKey = null;
        } else {
            $pendingKey = null;
        }
    }
    return $dups;
};

try {
    $dictFiles = array(
        'FormsDictionary (plugin)' => $repoDir . '/plugin/partikulier-core/src/Domain/I18n/FormsDictionary.php',
        'ChromeDictionary (plugin)' => $repoDir . '/plugin/partikulier-core/src/Domain/I18n/ChromeDictionary.php',
        'class-localization-forms (thème)' => $repoDir . '/theme/partikulier/inc/class-localization-forms.php',
        'class-localization-chrome (thème)' => $repoDir . '/theme/partikulier/inc/class-localization-chrome.php',
        'class-localization-strings (thème)' => $repoDir . '/theme/partikulier/inc/class-localization-strings.php',
    );

    // 1) E24-001 — sources : zéro clé dupliquée dans les 5 dictionnaires.
    $dupDetail = array(); $dupCount = 0;
    foreach ($dictFiles as $label => $path) {
        $dups = $scanDuplicates($path);
        if ($dups === null) { $dupDetail[] = "$label : illisible"; $dupCount += 999; continue; }
        foreach ($dups as $key => $n) { $dupDetail[] = sprintf('%s : %s ×%d', $label, $key, $n); $dupCount++; }
    }
    $assert('E24-001', $dupCount === 0,
        $dupCount === 0
            ? 'gate DuplicateArrayKey sur les 5 dictionnaires : 0 clé dupliquée (E-2401/E-2402)'
            : 'clés dupliquées résiduelles : ' . implode(' ; ', array_slice($dupDetail, 0, 5)));

    // 2) E24-002 — dictionnaire forms figé (140 entrées, empreintes épinglées).
    $fp = $fingerprint(FormsDictionary::translations());
    $assert('E24-002', $fp['count'] === 140 && $fp['keys_md5'] === 'e48c2757a6fa5c42e499e951a7435482'
        && $fp['entries_md5'] === 'b218ff994ce2b3db0d3cdb157b279845',
        sprintf('forms figé : %d entrées, clés %s, entrées %s', $fp['count'], $fp['keys_md5'], $fp['entries_md5']));

    // 3) E24-003 — dictionnaire chrome figé (136 entrées, empreintes épinglées).
    $fp = $fingerprint(ChromeDictionary::translations());
    $assert('E24-003', $fp['count'] === 136 && $fp['keys_md5'] === '9172ae3b274b0747115ec20c263d2418'
        && $fp['entries_md5'] === '2624aadd91b3561c242078d6d0820b64',
        sprintf('chrome figé : %d entrées, clés %s, entrées %s', $fp['count'], $fp['keys_md5'], $fp['entries_md5']));

    // 4) E24-004 — registre chrome public figé (150 entrées en contexte WP).
    $fp = $fingerprint(Partikulier_Localization::public_chrome_strings());
    $assert('E24-004', $fp['count'] === 150 && $fp['keys_md5'] === 'bf1a2ec6781df39d7f5ce90c13691a20'
        && $fp['entries_md5'] === '56880689f3e9f0c4c34749d369f5eebf',
        sprintf('registre public figé : %d entrées (121 littérales + 29 setting_*), clés %s, entrées %s',
            $fp['count'], $fp['keys_md5'], $fp['entries_md5']));

    // 5) E24-005 — DP-1 : les 16 clés divergentes servent la valeur conservée
    //    (dernière occurrence — déjà servie avant dédoublonnage).
    $dp1Expected = array(
        '1 salon' => array('ar' => 'غرفة جلوس واحدة'),
        '2 salons' => array('ar' => 'غرفتا جلوس'),
        '3 salons ou plus' => array('ar' => '3 غرف جلوس أو أكثر'),
        '2 salles de bains' => array('ar' => 'حمامان'),
        '3 chambres ou plus' => array('en' => '3 bedrooms or more'),
        'Maison lumineuse à vendre entre particuliers' => array('en' => 'Bright house for sale by owner', 'ar' => 'منزل مشرق للبيع من طرف صاحبه'),
        'Caractéristiques' => array('ar' => 'المميزات'),
        'Salons' => array('ar' => 'صالونات'),
        'Terrasse' => array('ar' => 'شرفة'),
        'Parkings' => array('en' => 'Parking spaces', 'ar' => 'مواقف السيارات'),
        'Demander sur WhatsApp' => array('en' => 'Ask on WhatsApp'),
        'Envoyez cette annonce sur WhatsApp. Après vérification de votre demande, nous vous transmettons les coordonnées du propriétaire.' => array('ar' => 'أرسل هذا الإعلان عبر واتساب. بعد التحقق من طلبك، سنزودك ببيانات اتصال المالك.'),
        'Plus récentes' => array('en' => 'Most recent'),
        'Prix croissant' => array('ar' => 'الثمن: من الأقل إلى الأعلى'),
        'Prix décroissant' => array('ar' => 'الثمن: من الأعلى إلى الأقل'),
        'Surface décroissante' => array('en' => 'Surface: High to Low', 'ar' => 'المساحة: من الأعلى إلى الأقل'),
    );
    $forms = FormsDictionary::translations();
    $chrome = ChromeDictionary::translations();
    $bad = array();
    foreach ($dp1Expected as $key => $langs) {
        $dict = isset($chrome[$key]) ? $chrome : $forms;
        foreach ($langs as $lang => $expected) {
            $served = $dict[$key][$lang] ?? null;
            if ($served !== $expected) { $bad[] = sprintf('%s[%s] : attendu %s, servi %s', $key, $lang, $expected, (string) $served); }
        }
    }
    $assert('E24-005', $bad === [],
        $bad === [] ? 'DP-1 : les 16 clés divergentes servent la valeur conservée (dernière occurrence, inventaire docs/INVENTAIRE-DP1-SE024.md)'
            : 'écarts DP-1 : ' . implode(' ; ', array_slice($bad, 0, 3)));

    // 6) E24-006 — parité plugin ↔ thème re-prouvée après dédoublonnage.
    $themeForms = Partikulier_Localization::form_translations();
    $themeChrome = Partikulier_Localization::chrome_translations();
    $assert('E24-006', FormsDictionary::translations() === $themeForms && ChromeDictionary::translations() === $themeChrome,
        sprintf('port VERBATIM re-prouvé : forms %d == %d, chrome %d == %d (égalité profonde)',
            count($forms), count($themeForms), count($chrome), count($themeChrome)));

    // 7) E24-007 — la gate E-2402 est câblée au lint du dépôt.
    $gateScript = $repoDir . '/scripts/check-duplicate-keys.php';
    $lint = is_file($repoDir . '/scripts/lint.sh') ? (string) file_get_contents($repoDir . '/scripts/lint.sh') : '';
    $assert('E24-007', is_file($gateScript) && strpos($lint, 'check-duplicate-keys.php') !== false,
        'gate DuplicateArrayKey : scripts/check-duplicate-keys.php présent et appelé par scripts/lint.sh (CI lint-and-package ×3 PHP)');
} catch (Throwable $error) {
    $assert('E24-EXCEPTION', false, $error->getMessage());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se024-i18n-dedup-contract (dédoublonnage i18n + DP-1, E-2401→E-2403)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/se024-i18n-dedup-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
