<?php
/**
 * check-literal-unicode-escapes.php — Gate LiteralUnicodeEscape (SE-044 / DP-9).
 *
 * Garde permanente demandée par la revue CP3 (§2.1, 23/09/2026) : échoue si un
 * littéral de chaîne du CODE LIVRÉ (plugin + thème, hors tests) contient une
 * séquence d'échappement littérale \uXXXX (ex. \u2019 au lieu de l'apostrophe
 * U+2019). En PHP, \uXXXX sans accolades n'est PAS une séquence d'échappement :
 * dans une chaîne simple comme doublement quotée, la séquence s'affiche telle
 * quelle au propriétaire ET ne correspond à aucun msgid des catalogues — la
 * chaîne devient intraduisible (rupture i18n, cf. critère T36 « UI ×3 langues »).
 * Défaut constaté en CP3 sur 4 chaînes de templates/page-mes-annonces.php ;
 * cette gate l'empêche de réapparaître.
 *
 * Périmètre : code de production (plugin + thème, y compris surcharges estatik4),
 * fichiers .php uniquement. Exclusions assumées, motivées :
 *  - tests/ : les suites peuvent légitimement sonder ces séquences en sortie
 *    (ex. options-sanitizer OS-008 attend \u003C dans le JSON_HEX_TAG servi) ;
 *  - .js : en JavaScript \uXXXX est une séquence valide interprétée par le
 *    moteur (aucun défaut d'affichage), la signaler créerait des faux positifs ;
 *  - vendor/, node_modules/ : code vendu hors périmètre de revue.
 *
 * Appelé par scripts/lint.sh (CI lint-and-package). Sortie : une ligne
 * « fichier:ligne » par occurrence, exit 1 ; « Literal unicode escape lint:
 * PASS » et exit 0 sinon.  Exécution : php scripts/check-literal-unicode-escapes.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$targets = array(
    $root . '/plugin/partikulier-core',
    $root . '/theme/partikulier',
);
$skip = array(
    'vendor' => true, 'node_modules' => true,
    'tests' => true, '__screens__' => true, '__baseline__' => true,
);

$files = array();
foreach ($targets as $target) {
    if (!is_dir($target)) { continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $info) {
        /** @var SplFileInfo $info */
        if ($info->getExtension() !== 'php') { continue; }
        $parts = explode(DIRECTORY_SEPARATOR, $info->getPathname());
        if (array_intersect($parts, array_keys($skip))) { continue; }
        $files[] = $info->getPathname();
    }
}

$failures = array();
foreach ($files as $file) {
    $lines = file($file, FILE_IGNORE_NEW_LINES);
    if ($lines === false) { continue; }
    foreach ($lines as $n => $line) {
        // \uXXXX sans accolades : échappement littéral, jamais interprété par PHP.
        if (preg_match('/\\\\u[0-9A-Fa-f]{4}/', $line, $m)) {
            $failures[] = sprintf(
                '%s:%d  %s  [%s]',
                substr($file, strlen($root) + 1), $n + 1, $m[0], trim($line)
            );
        }
    }
}

if ($failures) {
    fwrite(STDERR, "Literal unicode escape lint: FAIL — " . count($failures) . " occurrence(s) \\uXXXX dans le code livré :\n");
    foreach ($failures as $failure) {
        fwrite(STDERR, "  " . $failure . "\n");
    }
    fwrite(STDERR, "Remplacer la séquence par le caractère réel (ex. \\u2019 par l'apostrophe U+2019).\n");
    exit(1);
}

echo "LiteralUnicodeEscape: 0 (" . count($files) . " fichiers scannés)\n";
