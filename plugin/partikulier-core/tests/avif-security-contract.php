<?php
/**
 * Contrat de sécurité du point d'appel système (lot E, CDC v1.2 — SECU-1/CA-3).
 *
 * Le lot E cloisonne TOUT usage de exec()/shell_exec()/system()/passthru()/
 * popen()/proc_open() dans un module unique du thème, inc/class-exec-whitelist.php
 * (Partikulier_Exec_Whitelist), utilisé par class-avif.php (conversion AVIF :
 * avifenc, vips) et pk-diagnostic.php (sonde mono-ouvrier : ps). Le contrat
 * verrouille les garanties exigées par le CDC §3.4 :
 *
 * Assertions SE-001 à SE-012 :
 *  - SE-001 : le module unique est chargé et reste sous le plafond CA-4
 *    (<400 l., délibérément monolithique : le cloisonnement exige UN module) ;
 *  - SE-002 : AUCUN appel système hors du module — scan lexical
 *    (token_get_all, insensible à la casse) du périmètre runtime thème+plugin
 *    (hors tests) ; les commentaires ne comptent pas comme invocations ;
 *  - SE-003 : liste blanche avifenc/vips/ps (chemins absolus énumérés,
 *    structure d'empreinte), filtrable (hébergeur/contrats) ;
 *  - SE-004 : empreinte INVALIDE => refus (jamais exécuté) — doublon de test
 *    épinglé au mauvais sha256 ;
 *  - SE-005 : exécution RÉELLE du doublon épinglé (exit 0, copie conforme,
 *    durée mesurée) — la voie exec() fonctionne de bout en bout ;
 *  - SE-006 : validation stricte des entrées — hors uploads, extension
 *    interdite, inexistant, remontée « .. » => refus ;
 *  - SE-007 : injections classiques (point-virgule, substitution $(),
 *    backticks, &&, |, saut de ligne, quote) => refus sur les trois types
 *    d'arguments (fichier, cible, drapeau) ;
 *  - SE-008 : construction de commande — binaire, fichiers et cibles
 *    systématiquement échappés (escapeshellarg), drapeaux validés par
 *    charset sûr, suffixe vips [Q=75] analysé strictement ;
 *  - SE-009 : journalisation — invocations EXEC et refus REFUS tracés
 *    (uploads/partikulier/exec-journal.log) ;
 *  - SE-010 : mode dégradé — binaire absent => refus propre, aucune fatale
 *    (doublon à chemin inexistant : déterministe sur tout environnement) ;
 *  - SE-011 : conversion cohérente — média réel : si l'éditeur WP supporte
 *    AVIF, le .avif est écrit non vide (mode normal) ; sinon aucun .avif et
 *    aucune fatale (mode dégradé). Jamais l'inverse ;
 *  - SE-012 : périmètre diagnostic + versions (thème 6.20.2, plugin 2.10.3,
 *    src plugin inchangé) + hygiène class-avif (≤400 l., zéro exec direct) ;
 *  - SE-013 : cible-lien — un lien symbolique posé sur la cible (pointant
 *    hors uploads) est REFUSÉ (raison « cible-lien », journal REFUS:cible-lien),
 *    piège [Q=75] couvert : le test is_link porte sur le chemin physique,
 *    suffixe retiré AVANT (vips l'interprète, il n'existe pas sur disque),
 *    fichier hors uploads jamais écrit au-travers du lien ;
 *  - SE-014 : délai d'exécution — binaire endormi (sleep 60) tué au bout du
 *    délai (filtre partikulier_exec_timeout), raison « timeout », durée
 *    bornée, journal EXEC:timeout, proc_close()=-1 maîtrisé (drain avant
 *    fermeture : le test lui-même ne pend jamais).
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php partikulier-core/tests/avif-security-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$themeRoot = (string) get_template_directory();
$pluginRoot = dirname(__DIR__);
$moduleFile = $themeRoot . '/inc/class-exec-whitelist.php';
$up = wp_get_upload_dir();
$baseDir = wp_normalize_path((string) ($up['basedir'] ?? ''));

/* ---------- Scan lexical du périmètre runtime (hors tests) ---------- */
$systemFns = ['exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open'];
$scanSites = static function (string $file) use ($systemFns): array {
    $code = @file_get_contents($file);
    if (!is_string($code) || $code === '') { return []; }
    $tokens = @token_get_all($code);
    if (!is_array($tokens)) { return []; }
    $sites = [];
    $n = count($tokens);
    for ($i = 0; $i < $n; $i++) {
        $t = $tokens[$i];
        if (is_array($t) && $t[0] === T_STRING && in_array(strtolower((string) $t[1]), $systemFns, true)) {
            $j = $i + 1;
            while ($j < $n && is_array($tokens[$j]) && in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) { $j++; }
            if ($j < $n && $tokens[$j] === '(') {
                $sites[] = ['line' => (int) $t[2], 'fn' => strtolower((string) $t[1])];
            }
        }
    }
    return $sites;
};
$collect = null;
$collect = static function (string $dir, array $skip, array &$out) use (&$collect): void {
    foreach ((array) glob(rtrim($dir, '/') . '/*') as $p) {
        $name = basename((string) $p);
        if (is_dir((string) $p)) {
            if (!in_array($name, $skip, true)) { $collect((string) $p, $skip, $out); }
            continue;
        }
        if (substr((string) $p, -4) === '.php') { $out[] = (string) $p; }
    }
};
$runtime = [];
$collect($themeRoot, ['tests', '__baseline__', '__screens__', 'node_modules', 'languages'], $runtime);
$collect($pluginRoot, ['tests'], $runtime);

/* ---------- Fixtures : doublon de test épinglé + fichiers sous uploads ---------- */
$testDir = $baseDir . '/pk-avif-contrat';
if (!is_dir($testDir)) { @mkdir($testDir, 0755, true); }
@file_put_contents($testDir . '/.htaccess', "Require all denied\nDeny from all\n");
$double = $testDir . '/double.sh';
@file_put_contents($double, "#!/bin/sh\nexec cp \"\$1\" \"\$2\"\n");
@chmod($double, 0755);
$src = $testDir . '/entree.png';
$img = @imagecreatetruecolor(64, 48);
if (is_resource($img) || (is_object($img) && $img instanceof GdImage)) {
    @imagepng($img, $src);
    @imagedestroy($img);
}
$dst = $testDir . '/cible.avif';
$doubleSha = is_file($double) ? (string) hash_file('sha256', $double) : '';
$addDouble = static function ($entries) use ($double, $doubleSha) {
    if (is_array($entries)) { $entries['double'] = ['candidates' => [$double], 'sha256' => $doubleSha]; }
    return $entries;
};
add_filter('partikulier_exec_whitelist', $addDouble);

$fatal = null;
try {

    /* SE-001 — module unique chargé, sous le plafond CA-4. */
    $moduleLines = is_file($moduleFile) ? count((array) file($moduleFile)) : 0;
    $assert('SE-001', class_exists('Partikulier_Exec_Whitelist') && is_file($moduleFile) && $moduleLines > 0 && $moduleLines <= 400,
        sprintf('passerelle chargée : class-exec-whitelist.php, %d lignes (<400 CA-4 ; module délibérément monolithique — le cloisonnement exige un module unique)', $moduleLines));

    /* SE-002 — aucun appel système hors du module unique. */
    $violations = [];
    $sitesModule = 0;
    foreach ($runtime as $f) {
        $sites = $scanSites($f);
        if (!$sites) { continue; }
        if (wp_normalize_path($f) === wp_normalize_path($moduleFile)) { $sitesModule += count($sites); continue; }
        foreach ($sites as $s) { $violations[] = basename($f) . ':' . $s['line'] . ' ' . $s['fn']; }
    }
    $assert('SE-002', $violations === [] && $sitesModule >= 1,
        sprintf('scan lexical %d fichiers runtime : %d invocation(s), toutes dans inc/class-exec-whitelist.php (thème+plugin, hors tests, insensible à la casse)%s',
            count($runtime), $sitesModule, $violations ? ' — VIOLATIONS : ' . implode(', ', array_slice($violations, 0, 5)) : ''));

    /* SE-003 — liste blanche déclarée (chemins absolus + empreinte) et filtrable. */
    $wl = Partikulier_Exec_Whitelist::whitelist();
    $ok303 = isset($wl['avifenc']['candidates'], $wl['vips']['candidates'], $wl['ps']['candidates'])
        && array_key_exists('sha256', $wl['avifenc']) && array_key_exists('sha256', $wl['vips'])
        && is_array($wl['avifenc']['candidates']) && is_array($wl['vips']['candidates'])
        && 0 === strpos((string) $wl['avifenc']['candidates'][0], '/')
        && 0 === strpos((string) $wl['vips']['candidates'][0], '/');
    $filterVu = false;
    $sondeFiltre = static function ($entries) use (&$filterVu) {
        $filterVu = true;
        if (is_array($entries)) { $entries['sonde-filtre'] = ['candidates' => ['/nonexistent/sonde-filtre'], 'sha256' => null]; }
        return $entries;
    };
    add_filter('partikulier_exec_whitelist', $sondeFiltre);
    $wl2 = Partikulier_Exec_Whitelist::whitelist();
    remove_filter('partikulier_exec_whitelist', $sondeFiltre);
    $assert('SE-003', $ok303 && $filterVu && isset($wl2['sonde-filtre']),
        'liste blanche : avifenc/vips/ps (candidats chemins absolus + champ empreinte), filtre partikulier_exec_whitelist appliqué');

    /* SE-004 — empreinte invalide => refus, jamais exécuté. */
    Partikulier_Exec_Whitelist::reset_runtime_cache();
    $mauvaisPin = static function ($entries) {
        if (is_array($entries) && isset($entries['double'])) { $entries['double']['sha256'] = str_repeat('0', 64); }
        return $entries;
    };
    add_filter('partikulier_exec_whitelist', $mauvaisPin, 20);
    $refuse = Partikulier_Exec_Whitelist::run('double', [
        ['type' => 'file', 'value' => $src],
        ['type' => 'target', 'value' => $dst],
    ]);
    $resolveFaux = Partikulier_Exec_Whitelist::resolve('double');
    remove_filter('partikulier_exec_whitelist', $mauvaisPin, 20);
    Partikulier_Exec_Whitelist::reset_runtime_cache();
    $assert('SE-004', 'fingerprint' === (string) $resolveFaux['state'] && empty($refuse['ok']) && 'fingerprint' === (string) ($refuse['reason'] ?? '') && !is_file($dst),
        'empreinte épinglée incorrecte => état fingerprint, refus avant exécution, cible non écrite');

    /* SE-005 — exécution réelle du doublon épinglé (voie exec() de bout en bout). */
    @unlink($dst);
    $bon = Partikulier_Exec_Whitelist::run('double', [
        ['type' => 'file', 'value' => $src],
        ['type' => 'target', 'value' => $dst],
    ]);
    $assert('SE-005', !empty($bon['ok']) && 0 === (int) ($bon['exit_code'] ?? -1) && is_file($dst) && (int) filesize($dst) === (int) filesize($src) && (int) ($bon['duration_ms'] ?? -1) >= 0,
        sprintf('doublon épinglé (sha256 %s…) exécuté : exit %s, copie conforme (%d o), durée %s ms',
            substr($doubleSha, 0, 12), (string) ($bon['exit_code'] ?? '?'), (int) filesize($dst), (string) ($bon['duration_ms'] ?? '?')));

    /* SE-006 — validation stricte des entrées (localisation, extension, existence). */
    $tentatives = [
        ['type' => 'file', 'value' => $themeRoot . '/style.css'],
        ['type' => 'file', 'value' => ABSPATH . 'wp-config.php'],
        ['type' => 'file', 'value' => $testDir . '/inexistant.png'],
        ['type' => 'target', 'value' => $testDir . '/cible.exe'],
        ['type' => 'target', 'value' => $baseDir . '/../../wp-config.php'],
    ];
    $refusEntrees = 0;
    $detailsEntrees = [];
    foreach ($tentatives as $tentative) {
        $r = Partikulier_Exec_Whitelist::run('double', [$tentative]);
        if (empty($r['ok']) && in_array((string) ($r['reason'] ?? ''), ['fichier', 'cible'], true)) {
            $refusEntrees++;
            $detailsEntrees[] = basename((string) $tentative['value']) . '=>' . (string) ($r['reason'] ?? '');
        }
    }
    $assert('SE-006', $refusEntrees === count($tentatives),
        'entrées : ' . count($tentatives) . '/' . count($tentatives) . ' refusées (' . implode(', ', $detailsEntrees) . ') — strictement sous uploads, extensions autorisées');

    /* SE-007 — injections classiques refusées sur les trois types d'arguments. */
    $charges = ['x; rm -rf /', 'x$(touch pk-pwned)', 'x`id`', 'x && id', 'x | id', "x\nid", "x' ; id"];
    $refusInjection = 0;
    $totalInjection = 0;
    foreach ($charges as $charge) {
        foreach ([
            ['type' => 'file', 'value' => $testDir . '/' . $charge . '.png'],
            ['type' => 'target', 'value' => $testDir . '/' . $charge . '.avif'],
            ['type' => 'flag', 'value' => '--min 25 ' . $charge],
        ] as $tentative) {
            $totalInjection++;
            $r = Partikulier_Exec_Whitelist::run('double', [$tentative]);
            if (empty($r['ok'])) { $refusInjection++; }
        }
    }
    $assert('SE-007', $refusInjection === $totalInjection && !is_file($testDir . '/pk-pwned'),
        sprintf('injections : %d/%d tentatives refusées (point-virgule, $(), backticks, &&, |, saut de ligne, quote — fichier/cible/drapeau), aucun effet de bord',
            $refusInjection, $totalInjection));

    /* SE-008 — construction de commande systématiquement échappée. */
    $bA = Partikulier_Exec_Whitelist::build('double', [
        ['type' => 'file', 'value' => $src],
        ['type' => 'target', 'value' => $dst],
    ]);
    $bB = Partikulier_Exec_Whitelist::build('double', [
        ['type' => 'flag', 'value' => 'copy'],
        ['type' => 'file', 'value' => $src],
        ['type' => 'target', 'value' => $dst . '[Q=75]'],
    ]);
    $attenduA = "'" . $double . "' '" . $src . "' '" . $dst . "' 2>&1";
    $attenduB = "'" . $double . "' copy '" . $src . "' '" . $dst . "[Q=75]' 2>&1";
    $reste = (string) preg_replace("/'[^']*'/", '', (string) $bB['command']);
    $resteSur = 1 === preg_match('/^[A-Za-z0-9 =._&>-]*$/', $reste);
    $assert('SE-008', !empty($bA['ok']) && (string) $bA['command'] === $attenduA && !empty($bB['ok']) && (string) $bB['command'] === $attenduB && $resteSur,
        'commande : binaire/fichier/cible échappés par escapeshellarg, suffixe vips [Q=75] strictement analysé, hors quotes seuls les séparateurs et le littéral fixe 2>&1 subsistent');

    /* SE-009 — journalisation des invocations et des refus. */
    $journal = Partikulier_Exec_Whitelist::journal_path();
    $lignes = (is_string($journal) && is_file($journal)) ? (array) file($journal, FILE_IGNORE_NEW_LINES) : [];
    $lignesDouble = array_values(array_filter($lignes, static fn($l): bool => (bool) preg_match('/\tdouble\t/', (string) $l)));
    $execOk = (bool) array_filter($lignesDouble, static fn($l): bool => (bool) preg_match('/\tEXEC:0\t[0-9]+\t/', (string) $l));
    $refusTrace = (bool) array_filter($lignes, static fn($l): bool => (bool) preg_match('/\tREFUS:(fingerprint|fichier|cible|drapeau|absent)\t/', (string) $l));
    $assert('SE-009', is_string($journal) && $execOk && $refusTrace && count($lignesDouble) >= 2,
        sprintf('journal %s : %d lignes (invocations EXEC:0 tracées avec durée, refus REFUS:* tracés, rotation 256 Kio)',
            (string) $journal, count($lignes)));

    /* SE-010 — mode dégradé : binaire absent => refus propre, aucune fatale. */
    $ajouteAbsent = static function ($entries) {
        if (is_array($entries)) { $entries['absent-test'] = ['candidates' => ['/nonexistent/pk-absent-binaire'], 'sha256' => null]; }
        return $entries;
    };
    add_filter('partikulier_exec_whitelist', $ajouteAbsent);
    Partikulier_Exec_Whitelist::reset_runtime_cache();
    $dispoAbsent = Partikulier_Exec_Whitelist::is_available('absent-test');
    $rAbsent = Partikulier_Exec_Whitelist::run('absent-test', [
        ['type' => 'file', 'value' => $src],
        ['type' => 'target', 'value' => $dst],
    ]);
    remove_filter('partikulier_exec_whitelist', $ajouteAbsent);
    Partikulier_Exec_Whitelist::reset_runtime_cache();
    $avifencReel = Partikulier_Exec_Whitelist::is_available('avifenc');
    $assert('SE-010', false === $dispoAbsent && empty($rAbsent['ok']) && 'absent' === (string) ($rAbsent['reason'] ?? ''),
        sprintf('mode dégradé : binaire absent => is_available=false, run refusé (raison « absent »), aucune fatale ; avifenc réel sur cet hôte : %s',
            $avifencReel ? 'présent' : 'absent (conversion AVIF basculée sur l\'éditeur WP / désactivée)'));

    /* SE-011 — conversion cohérente avec la capacité (mode normal ou dégradé). */
    $meta = null;
    $fichierAvif = '';
    $fichierSource = '';
    $att = 0;
    $contenu = is_file($src) ? (string) file_get_contents($src) : '';
    if ($contenu !== '') {
        $upload = wp_upload_bits('pk-contrat-avif.png', null, $contenu);
        if (empty($upload['error'])) {
            $fichierSource = (string) $upload['file'];
            $att = (int) wp_insert_attachment([
                'post_mime_type' => 'image/png',
                'post_title' => 'pk contrat avif',
                'post_status' => 'inherit',
            ], $fichierSource);
            if ($att > 0) {
                $meta = wp_generate_attachment_metadata($att, $fichierSource);
                $fichierAvif = $fichierSource . '.avif';
            }
        }
    }
    $editeurOk = function_exists('wp_image_editor_supports') && (bool) wp_image_editor_supports(['mime_type' => 'image/avif']);
    $ecrit = is_file($fichierAvif) && (int) filesize($fichierAvif) > 0;
    $assert('SE-011', is_array($meta) && ($editeurOk ? $ecrit : (!$ecrit && is_file($fichierSource))),
        sprintf('conversion %s : média réel %s => %s (jamais de fatale ; repli avifenc/vips via la passerelle le cas échéant)',
            $editeurOk ? 'normale (éditeur AVIF)' : 'dégradée (éditeur sans AVIF)',
            basename($fichierSource) ?: 'n/a',
            $ecrit ? sprintf('.avif non vide (%d o)', (int) filesize($fichierAvif)) : 'aucun .avif'));
    if ($att > 0) { wp_delete_attachment($att, true); }
    foreach ((array) glob($testDir . '/*') ?: [] as $residue) { @unlink((string) $residue); }
    if ($fichierAvif !== '') { @unlink($fichierAvif); }

    /* SE-012 — périmètre diagnostic + versions + hygiène class-avif. */
    $fichierDiagnostic = $themeRoot . '/pk-diagnostic.php';
    $sitesDiagnostic = $scanSites($fichierDiagnostic);
    $diagPasseParPasserelle = false !== strpos((string) @file_get_contents($fichierDiagnostic), 'Partikulier_Exec_Whitelist');
    $fichierAvifModule = $themeRoot . '/inc/class-avif.php';
    $sitesAvif = $scanSites($fichierAvifModule);
    $avifLignes = count((array) file($fichierAvifModule));
    $versionTheme = (string) wp_get_theme()->get('Version');
    $assert('SE-012', $sitesDiagnostic === [] && $diagPasseParPasserelle && $sitesAvif === [] && $avifLignes <= 400
        && $versionTheme === '6.20.2' && PARTIKULIER_CORE_VERSION === '2.10.3',
        sprintf('pk-diagnostic : 0 appel direct (passe par la passerelle) ; class-avif %d l. sans exec direct ; thème %s, plugin %s (src inchangé, +contrat)',
            $avifLignes, $versionTheme, PARTIKULIER_CORE_VERSION));

    /* SE-013 — cible-lien : lien symbolique sur la cible refusé, [Q=n] retiré avant le test. */
    $secret = sys_get_temp_dir() . '/pk-secret-cible-lien.txt';
    @file_put_contents($secret, 'intact');
    $copie = $testDir . '/copie.sh';
    @file_put_contents($copie, "#!/bin/sh\nexec cp \"\$1\" \"\$2\"\n");
    @chmod($copie, 0755);
    $ajouteCopie = static function ($entries) use ($copie) {
        if (is_array($entries)) { $entries['copie'] = ['candidates' => [$copie], 'sha256' => (string) hash_file('sha256', $copie)]; }
        return $entries;
    };
    add_filter('partikulier_exec_whitelist', $ajouteCopie, 30);
    $lien  = $testDir . '/lien.avif';
    $lienQ = $testDir . '/lien-q.avif';
    $entree3 = $testDir . '/entree3.png';
    @file_put_contents($entree3, 'x');
    @symlink($secret, $lien);
    @symlink($secret, $lienQ);
    $rLien = Partikulier_Exec_Whitelist::run('copie', [
        ['type' => 'file', 'value' => $entree3],
        ['type' => 'target', 'value' => $lien],
    ]);
    $rLienQ = Partikulier_Exec_Whitelist::run('copie', [
        ['type' => 'file', 'value' => $entree3],
        ['type' => 'target', 'value' => $lienQ . '[Q=75]'],
    ]);
    $journal13 = Partikulier_Exec_Whitelist::journal_path();
    $lignes13 = (is_string($journal13) && is_file($journal13)) ? (array) file($journal13, FILE_IGNORE_NEW_LINES) : [];
    $traceLien = (bool) array_filter($lignes13, static fn($l): bool => (bool) preg_match('/\tREFUS:cible-lien\t/', (string) $l));
    $secretIntact = is_file($secret) && 'intact' === (string) @file_get_contents($secret) && 6 === (int) @filesize($secret);
    $assert('SE-013', empty($rLien['ok']) && 'cible-lien' === (string) ($rLien['reason'] ?? '')
        && empty($rLienQ['ok']) && 'cible-lien' === (string) ($rLienQ['reason'] ?? '')
        && $traceLien && $secretIntact && !is_link($testDir . '/copie.sh'),
        sprintf('cible-lien : lien symbolique refusé (raison « cible-lien », journal REFUS:cible-lien), piège [Q=75] couvert (suffixe retiré avant le test is_link), fichier hors uploads jamais écrit au-travers du lien (%s)',
            $secretIntact ? 'intact' : 'ÉCRASÉ'));
    remove_filter('partikulier_exec_whitelist', $ajouteCopie, 30);
    @unlink($lien); @unlink($lienQ); @unlink($copie); @unlink($secret); @unlink($entree3);

    /* SE-014 — délai d'exécution : binaire endormi tué, EXEC:timeout journalisé. */
    $dormeur = $testDir . '/dormeur.sh';
    @file_put_contents($dormeur, "#!/bin/sh\nexec sleep 60\n");
    @chmod($dormeur, 0755);
    $ajouteDormeur = static function ($entries) use ($dormeur) {
        if (is_array($entries)) { $entries['dormeur'] = ['candidates' => [$dormeur], 'sha256' => (string) hash_file('sha256', $dormeur)]; }
        return $entries;
    };
    add_filter('partikulier_exec_whitelist', $ajouteDormeur, 30);
    $filtreDelai = static function () { return 1.0; };
    add_filter('partikulier_exec_timeout', $filtreDelai);
    $avant = microtime(true);
    $rDormeur = Partikulier_Exec_Whitelist::run('dormeur', [
        ['type' => 'flag', 'value' => '60'],
    ]);
    $duree = (int) round((microtime(true) - $avant) * 1000);
    remove_filter('partikulier_exec_timeout', $filtreDelai);
    remove_filter('partikulier_exec_whitelist', $ajouteDormeur, 30);
    $journal14 = Partikulier_Exec_Whitelist::journal_path();
    $lignes14 = (is_string($journal14) && is_file($journal14)) ? (array) file($journal14, FILE_IGNORE_NEW_LINES) : [];
    $traceTimeout = (bool) array_filter($lignes14, static fn($l): bool => (bool) preg_match('/\tEXEC:timeout\t[0-9]+\t/', (string) $l));
    $msDormeur = (int) ($rDormeur['duration_ms'] ?? -1);
    $assert('SE-014', empty($rDormeur['ok']) && 'timeout' === (string) ($rDormeur['reason'] ?? '')
        && $msDormeur >= 900 && $msDormeur <= 8000 && $duree <= 8000
        && $traceTimeout && array_key_exists('exit_code', $rDormeur) && null === $rDormeur['exit_code'],
        sprintf('délai : dormeur (sleep 60, épinglé) tué au bout du délai (filtre à 1 s) — durée %d ms, test %d ms (aucune pendaison : drain avant proc_close, piège -1 maîtrisé), journal EXEC:timeout, exit_code null',
            $msDormeur, $duree));
    @unlink($dormeur);

} catch (Throwable $e) {
    $assert('SE-999', false, 'exception inattendue : ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
}

/* ---------- Nettoyage des fixtures ---------- */
remove_filter('partikulier_exec_whitelist', $addDouble);
@unlink($double);
@unlink($src);
@unlink($dst);
@rmdir($testDir);

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'avif-security-contract (lot E)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/avif-security-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
