<?php
/**
 * Contrat SE-035 — catalogues EN/AR complétés + génération + pkConfig
 * (E-3501→E-3503, micro-lot pré-prod 2.10.8/6.20.7, DP-5 = production
 * trilingue FR/EN/AR dès le jour 1).
 *
 * R1 (pré-correctif, mesuré) : les catalogues du kit couvraient 131 (AR) et
 * 72 (EN) entrées — la surface front du thème dépasse 500 chaînes gettext
 * (templates, modules, formulaire, i18n JS de pkConfig, courriels) : les
 * pages EN/AR rendaient majoritairement en français.
 *
 * Ce contrat verrouille :
 *  - E35-001 (E-3501, AR) : ar.mo = 596 entrées au format canonique
 *    (rev 0, hash vide en fin — forme C1A-011), lisible pomo, AUCUNE
 *    traduction vide, échantillon front représentatif traduit ;
 *  - E35-002 (E-3501, EN) : en_US.mo = 544 entrées, mêmes exigences ;
 *  - E35-003 (E-3502) : GÉNÉRATION — scripts/build-catalogs.php (compilateur
 *    .po → .mo autonome, déterministe) reproduit byte à byte les .mo
 *    livrés depuis leurs sources .po (--check) ;
 *  - E35-004 (E-3503) : pkConfig localisé — les chaînes i18n du pont JS
 *    (class-scripts.php) se résolvent par le catalogue : domaine chargé
 *    sur en_US.mo → « Publication en cours… » rend « Publishing… »,
 *    et pomo lit « جارٍ النشر… » dans ar.mo ;
 *  - E35-005 (E-3501, échantillon front) : 20 chaînes front représentatives
 *    (menus, boutons, messages, fragments de titres générés, courriel de
 *    validation) présentes et traduites dans les DEUX langues.
 *
 * Écart assumé et documenté : les écrans d'administration restent en
 * français (la promesse trilingue DP-5 couvre le site public — plan §DP-5,
 * SE-025 couvre les 9 URLs front ×3 langues).
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_REPO_DIR=<racine> php
 *   partikulier-core/tests/se035-catalogs-contract.php
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

if (!class_exists('MO')) { require_once $wpDir . '/wp-includes/pomo/mo.php'; }

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$langDir = $repoDir . '/plugin/partikulier-core/languages';

/** En-tête .mo au format GNU (rev 0, hash vide en fin). */
$moHeader = static function (string $file): array {
    $raw = (string) file_get_contents($file);
    return unpack('Vmagic/Vrev/Vtotal/Vorig/Vtrans/Vhash_len/Vhash_addr', substr($raw, 0, 28)) ?: [];
};

/** Entrées d'un .po : [msgid => msgstr] (en-tête exclu). */
$poEntries = static function (string $file): array {
    $out = [];
    $blocks = preg_split('/\n\n/', (string) file_get_contents($file)) ?: [];
    foreach ($blocks as $block) {
        $lines = array_values(array_filter(array_map('rtrim', explode("\n", $block)), static fn($l): bool => (string) $l !== ''));
        $field = null; $id = []; $str = [];
        foreach ($lines as $line) {
            if (strpos($line, 'msgid ') === 0) { $field = 'id'; $id = [trim(substr($line, 6), '"')]; }
            elseif (strpos($line, 'msgstr ') === 0) { $field = 'str'; $str = [trim(substr($line, 7), '"')]; }
            elseif ($line[0] === '"' && substr($line, -1) === '"') {
                if ($field === 'id') { $id[] = substr($line, 1, -1); } elseif ($field === 'str') { $str[] = substr($line, 1, -1); }
            }
        }
        $msgid = str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], implode('', $id));
        if ($msgid === '') { continue; }
        $out[$msgid] = str_replace(['\\n', '\\t', '\\"', '\\\\'], ["\n", "\t", '"', '\\'], implode('', $str));
    }
    return $out;
};

/** Échantillon front représentatif (E35-005) — menus, boutons, messages, fragments, courriel. */
$sample = [
    'Voir les annonces', 'Tous les biens', 'Rechercher un bien immobilier', 'Mes annonces',
    'Annonce enregistrée !', 'Erreur serveur', 'Publication en cours…', 'Ouvrir WhatsApp et envoyer le message',
    'Votre nom est requis.', 'Trop de tentatives ont été effectuées. Réessayez dans une heure.',
    ' à vendre', ' à louer', ' avec terrasse', ' sans vis-à-vis', 'situé %s', 'de %d m²',
    'À vendre', 'À louer', 'Vendu', 'Sans commission.',
];

try {
    // 1) AR — 596 entrées, format canonique, lisible pomo, aucune vide.
    $hdrAr = $moHeader($langDir . '/ar.mo');
    $pomoAr = new MO();
    $readableAr = $pomoAr->import_from_file($langDir . '/ar.mo');
    $poAr = $poEntries($langDir . '/ar.po');
    $emptyAr = array_filter($poAr, static fn($t): bool => trim((string) $t) === '');
    $sampleArOk = [];
    foreach ($sample as $s) {
        $t = $pomoAr->translate($s);
        $sampleArOk[$s] = ($t !== $s && trim($t) !== '');
    }
    $sampleArBad = array_keys(array_filter($sampleArOk, static fn($ok): bool => !$ok));
    $assert('E35-001',
        (int) $hdrAr['total'] === 596 && (int) $hdrAr['hash_len'] === 0 && (int) $hdrAr['hash_addr'] === 28 + 16 * 596
        && $readableAr && count($pomoAr->entries) === 596 && $emptyAr === [] && $sampleArBad === [],
        sprintf('ar.mo : %d entrées (format canonique hash_addr=%d), pomo %s, traductions vides %d, échantillon front non traduit %d',
            (int) $hdrAr['total'], (int) $hdrAr['hash_addr'], $readableAr ? 'OK' : 'ÉCHEC', count($emptyAr), count($sampleArBad))
            . ($sampleArBad !== [] ? ' — manquantes : ' . implode(' ; ', array_slice($sampleArBad, 0, 3)) : ''));

    // 2) EN — 544 entrées, mêmes exigences.
    $hdrEn = $moHeader($langDir . '/en_US.mo');
    $pomoEn = new MO();
    $readableEn = $pomoEn->import_from_file($langDir . '/en_US.mo');
    $poEn = $poEntries($langDir . '/en_US.po');
    $emptyEn = array_filter($poEn, static fn($t): bool => trim((string) $t) === '');
    $sampleEnOk = [];
    foreach ($sample as $s) {
        $t = $pomoEn->translate($s);
        $sampleEnOk[$s] = ($t !== $s && trim($t) !== '');
    }
    $sampleEnBad = array_keys(array_filter($sampleEnOk, static fn($ok): bool => !$ok));
    $assert('E35-002',
        (int) $hdrEn['total'] === 544 && (int) $hdrEn['hash_len'] === 0 && (int) $hdrEn['hash_addr'] === 28 + 16 * 544
        && $readableEn && count($pomoEn->entries) === 544 && $emptyEn === [] && $sampleEnBad === [],
        sprintf('en_US.mo : %d entrées (format canonique hash_addr=%d), pomo %s, traductions vides %d, échantillon front non traduit %d',
            (int) $hdrEn['total'], (int) $hdrEn['hash_addr'], $readableEn ? 'OK' : 'ÉCHEC', count($emptyEn), count($sampleEnBad))
            . ($sampleEnBad !== [] ? ' — manquantes : ' . implode(' ; ', array_slice($sampleEnBad, 0, 3)) : ''));

    // 3) E-3502 : génération déterministe — --check reproduit les .mo livrés.
    $builder = $repoDir . '/scripts/build-catalogs.php';
    $checkOk = false; $checkOut = '';
    if (is_file($builder) && function_exists('exec')) {
        exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($builder) . ' --check 2>&1', $outLines, $code);
        $checkOut = implode(' | ', $outLines);
        $checkOk = $code === 0 && strpos($checkOut, 'octets identiques') !== false;
    }
    $assert('E35-003', $checkOk,
        $checkOk ? 'génération : build-catalogs.php --check → les .mo livrés sont la compilation byte-exacte de leurs .po (compilateur autonome, déterministe)'
            : 'génération : échec — ' . substr($checkOut, 0, 160));

    // 4) E-3503 : pkConfig localisé via le catalogue.
    //    Le pont JS (class-scripts.php) rend son tableau i18n par __() sur le
    //    domaine partikulier : chargé sur en_US.mo, « Publication en cours… »
    //    doit rendre « Publishing… » — et ar.mo porte « جارٍ النشر… ».
    unload_textdomain('partikulier', true);
    $loadedEn = load_textdomain('partikulier', $langDir . '/en_US.mo');
    $publishingEn = $loadedEn ? __('Publication en cours…', 'partikulier') : '';
    $whatsappEn = $loadedEn ? __('Ouvrir WhatsApp et envoyer le message', 'partikulier') : '';
    $publishingAr = $pomoAr->translate('Publication en cours…');
    unload_textdomain('partikulier', true);
    $assert('E35-004',
        $loadedEn && $publishingEn === 'Publishing…' && $whatsappEn === 'Open WhatsApp and send the message'
        && $publishingAr === 'جارٍ النشر…',
        sprintf('pkConfig i18n : domaine en_US chargé → « %s » / « %s » ; ar.mo → « %s »',
            $publishingEn, $whatsappEn, $publishingAr));

    // 5) Échantillon front × 20 — déjà calculé, assertion dédiée pour le
    //    détail lisible (les deux langues ensemble).
    $assert('E35-005', $sampleArBad === [] && $sampleEnBad === [],
        sprintf('échantillon front ×20 (menus, boutons, messages, fragments de titres) : AR %d/20, EN %d/20 traduites',
            20 - count($sampleArBad), 20 - count($sampleEnBad)));
} catch (Throwable $error) {
    $assert('E35-EXCEPTION', false, $error->getMessage());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se035-catalogs-contract (catalogues EN/AR complétés + génération + pkConfig, E-3501→E-3503)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/se035-catalogs-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
