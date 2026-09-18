<?php
/**
 * check-duplicate-keys.php — Gate DuplicateArrayKey (SE-024, E-2402).
 *
 * Scanne le code runtime du monorepo (plugin src/ + bootstrap + thème,
 * hors code vendu estatik4, vendor, node_modules et scripts de recette)
 * et échoue si un littéral de tableau PHP déclare la même clé plusieurs
 * fois. En PHP, la dernière occurrence écrase silencieusement les
 * précédentes : une clé dupliquée divergente fait vivre dans la source
 * une valeur que le runtime ne sert jamais — faute de lisibilité et
 * piège à régression. Le dédoublonnage SE-024 (DP-1 : défaut « dernière
 * occurrence ») a ramené le monorepo à zéro ; cette gate l'y maintient.
 *
 * Appelé par scripts/lint.sh (CI lint-and-package, matrice PHP 8.1-8.3).
 * Exécution : php scripts/check-duplicate-keys.php  (depuis la racine)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$targets = array(
    $root . '/plugin/partikulier-core/src',
    $root . '/plugin/partikulier-core/partikulier-core.php',
    $root . '/theme/partikulier',
);
$skip = array(
    'estatik4' => true, 'vendor' => true, 'node_modules' => true,
    'tests' => true, '__screens__' => true, '__baseline__' => true,
);

$files = array();
foreach ($targets as $target) {
    if (!is_dir($target) && is_file($target)) { $files[] = $target; continue; }
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($target, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $info) {
        /** @var SplFileInfo $info */
        if ($info->getExtension() !== 'php') { continue; }
        $parts = explode(DIRECTORY_SEPARATOR, $info->getPathname());
        if (array_intersect($parts, array_keys($skip))) { continue; }
        $files[] = $info->getPathname();
    }
}
sort($files);

$found = 0;
foreach ($files as $file) {
    $dups = scan_duplicate_keys($file);
    if ($dups === null) { fwrite(STDERR, "lecture impossible : $file\n"); exit(2); }
    foreach ($dups as $key => $lines) {
        $found++;
        fwrite(STDERR, sprintf("DuplicateArrayKey: %s ligne %s — clé %s déclarée %d fois\n",
            substr($file, strlen($root) + 1), implode(',', $lines), var_export($key, true), count($lines)));
    }
}

if ($found > 0) {
    echo "DuplicateArrayKey: {$found} clé(s) dupliquée(s) — corriger avant commit (SE-024, E-2402)\n";
    exit(1);
}
echo 'DuplicateArrayKey: 0 (' . count($files) . " fichiers scannés)\n";
exit(0);

/**
 * Clés dupliquées d'un fichier (tokenizer — le code n'est jamais exécuté).
 *
 * @return array<string,int[]>|null  clé => numéros de ligne, null si illisible
 */
function scan_duplicate_keys(string $file): ?array {
    $src = @file_get_contents($file);
    if ($src === false) { return null; }
    $toks = token_get_all($src);
    $frames = array();
    $pendingKey = null;
    $pendingArray = false;
    $dups = array();
    foreach ($toks as $tok) {
        if (is_array($tok)) {
            list($id, $text, $line) = $tok;
            if ($id === T_WHITESPACE || $id === T_COMMENT || $id === T_DOC_COMMENT) { continue; }
            if ($id === T_ARRAY) { $pendingArray = true; continue; }
            if ($id === T_CONSTANT_ENCAPSED_STRING || $id === T_LNUMBER) {
                $pendingKey = array($id === T_CONSTANT_ENCAPSED_STRING ? stripcslashes(substr($text, 1, -1)) : $text, $line);
                continue;
            }
            if ($id === T_DOUBLE_ARROW) {
                if ($pendingKey !== null && !empty($frames)) {
                    $frames[count($frames) - 1]['keys'][$pendingKey[0]][] = $pendingKey[1];
                }
                $pendingKey = null;
                continue;
            }
            $pendingKey = null;
            continue;
        }
        if ($tok === '(') {
            // tout '(' pousse un cadre ; seul celui précédé de T_ARRAY est un
            // littéral (les appels de fonction ferment sinon les cadres à tort)
            $frames[] = array('literal' => $pendingArray, 'keys' => array());
            $pendingArray = false;
            $pendingKey = null;
        } elseif ($tok === '[') {
            $frames[] = array('literal' => true, 'keys' => array());
            $pendingKey = null;
        } elseif ($tok === ')' || $tok === ']') {
            $f = array_pop($frames);
            if ($f !== null && $f['literal']) {
                foreach ($f['keys'] as $k => $lines) {
                    if (count($lines) > 1) { $dups[$k] = $lines; }
                }
            }
            $pendingKey = null;
        } else {
            $pendingKey = null;
        }
    }
    return $dups;
}
