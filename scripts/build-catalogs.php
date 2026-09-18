<?php
/**
 * build-catalogs.php — Génération déterministe des catalogues compilés
 * (SE-035, E-3502).
 *
 * Compile les sources .po du kit canonique (plugin/partikulier-core/
 * languages/) en .mo au format GNU (rev 0, table de hachage vide en fin
 * de fichier — la forme relue par pomo et épinglée par le contrat C1A-011).
 * Aucune dépendance externe (msgfmt absent des runners) : le compilateur
 * est autonome et REPRODUCTIBLE — recompiler une source .po redonne
 * exactement les mêmes octets.
 *
 * L'entrée d'en-tête (msgid "") n'est pas embarquée : la convention du kit
 * (et le compte épinglé par C1A-011) ne comptent que les traductions
 * réelles. Les entrées sont triées par octets de msgid (ordre de recherche
 * binaire du format).
 *
 * Usage :
 *   php scripts/build-catalogs.php           # compile ar.po et en_US.po
 *   php scripts/build-catalogs.php --check   # vérifie que les .mo livrés
 *                                           # correspondent byte à byte
 *                                           # à leurs sources .po
 * Exit 0 si tout va bien, 1 sinon.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$sources = [
    $root . '/plugin/partikulier-core/languages/ar.po',
    $root . '/plugin/partikulier-core/languages/en_US.po',
];
$check = in_array('--check', $argv, true);
$failures = 0;

foreach ($sources as $poPath) {
    $moPath = preg_replace('/\.po$/', '.mo', $poPath);
    $entries = parse_po($poPath);
    if ($entries === null) {
        fwrite(STDERR, "ILLISIBLE : {$poPath}\n");
        $failures++;
        continue;
    }
    ksort($entries, SORT_STRING);
    $binary = compile_mo($entries);
    if ($check) {
        $shipped = (string) @file_get_contents($moPath);
        if ($shipped === $binary) {
            echo 'OK      ' . basename($poPath) . ' → ' . basename($moPath) . ' (' . count($entries) . " entrées, octets identiques)\n";
        } else {
            echo 'ÉCART   ' . basename($moPath) . ' : les octets livrés ne correspondent pas à la source .po (' . strlen($shipped) . ' vs ' . strlen($binary) . ") — recompiler\n";
            $failures++;
        }
    } else {
        file_put_contents($moPath, $binary);
        echo 'COMPILÉ ' . basename($poPath) . ' → ' . basename($moPath) . ' (' . count($entries) . " entrées)\n";
    }
}
exit($failures > 0 ? 1 : 0);

/**
 * Parse un fichier .po : msgid/msgstr mono- et multi-lignes, échappements
 * \" \n \t \\. Retourne [msgid => msgstr] SANS l'entrée d'en-tête, ou null
 * si le fichier est illisible.
 *
 * @return array<string,string>|null
 */
function parse_po(string $path): ?array
{
    $raw = @file_get_contents($path);
    if ($raw === false) {
        return null;
    }
    $entries = [];
    $blocks = preg_split('/\n\n/', $raw) ?: [];
    foreach ($blocks as $block) {
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), static fn($l): bool => (string) $l !== ''));
        if ($lines === []) {
            continue;
        }
        $field = null;
        $idParts = [];
        $strParts = [];
        foreach ($lines as $line) {
            if (strpos($line, 'msgid ') === 0) {
                $field = 'id';
                $idParts = [po_inner(substr($line, 6))];
            } elseif (strpos($line, 'msgstr ') === 0) {
                $field = 'str';
                $strParts = [po_inner(substr($line, 7))];
            } elseif ($line[0] === '"' && substr($line, -1) === '"') {
                if ($field === 'id') {
                    $idParts[] = po_inner($line);
                } elseif ($field === 'str') {
                    $strParts[] = po_inner($line);
                }
            }
        }
        if ($idParts === []) {
            continue;
        }
        $msgid = po_unescape(implode('', $idParts));
        if ($msgid === '') {
            continue; // en-tête : non embarqué (convention du kit, C1A-011)
        }
        $entries[$msgid] = po_unescape(implode('', $strParts));
    }
    return $entries;
}

/** Extrait le contenu entre guillemets d'un fragment de ligne PO. */
function po_inner(string $s): string
{
    $s = trim($s);
    if ($s === '' || $s[0] !== '"') {
        return '';
    }
    $end = strrpos($s, '"');
    return $end > 0 ? substr($s, 1, $end - 1) : '';
}

/** Dé-séquence les échappements PO. */
function po_unescape(string $s): string
{
    $out = '';
    for ($i = 0, $n = strlen($s); $i < $n; $i++) {
        $c = $s[$i];
        if ($c === '\\' && $i + 1 < $n) {
            $next = $s[$i + 1];
            if ($next === 'n') { $out .= "\n"; $i++; continue; }
            if ($next === 't') { $out .= "\t"; $i++; continue; }
            if ($next === '"') { $out .= '"'; $i++; continue; }
            if ($next === '\\') { $out .= '\\'; $i++; continue; }
        }
        $out .= $c;
    }
    return $out;
}

/**
 * Compile les entrées en .mo GNU (rev 0) : en-tête 7 mots, table des
 * originaux, table des traductions, chaînes NUL-terminées, table de
 * hachage VIDE en fin de fichier (hash_len=0, hash_addr = fin des tables
 * — la forme attendue par pomo et le contrat C1A-011).
 *
 * @param array<string,string> $entries (déjà triées)
 */
function compile_mo(array $entries): string
{
    $count = count($entries);
    $header = pack(
        'V7',
        0x950412de,      // magic
        0,               // revision
        $count,          // total
        28,              // table des originaux
        28 + 8 * $count, // table des traductions
        0,               // taille de la table de hachage (vide)
        28 + 16 * $count // position de la table de hachage (fin des tables)
    );
    $offset = 28 + 16 * $count;
    $origTable = '';
    $origStrings = '';
    // Passe 1 : les originaux d'abord — la zone des traductions ne commence
    // qu'après la TOTALITÉ des chaînes originales.
    $pairs = [];
    foreach ($entries as $msgid => $msgstr) {
        $pairs[] = [$msgid, $msgstr];
        $origTable .= pack('V2', strlen($msgid), $offset + strlen($origStrings));
        $origStrings .= $msgid . "\0";
    }
    $transBase = $offset + strlen($origStrings);
    $transTable = '';
    $transStrings = '';
    // Passe 2 : les traductions, à partir de la fin des originaux.
    foreach ($pairs as [$msgid, $msgstr]) {
        $transTable .= pack('V2', strlen($msgstr), $transBase + strlen($transStrings));
        $transStrings .= $msgstr . "\0";
    }
    return $header . $origTable . $transTable . $origStrings . $transStrings;
}
