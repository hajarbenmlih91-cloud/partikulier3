<?php
/**
 * Contrat d'hygiène de l'artefact (SE-017, campagne post-audit 2026-09) :
 * capture d'écran du thème, harmonisation de la licence, gardes du
 * packaging. Trois assertions :
 *
 *   AH-001 (E-1701) : screenshot.png présent à la racine du thème, PNG réel,
 *                     1200×900 (norme wp.org), ≤ 300 Ko — getimagesize ;
 *   AH-002 (E-1703) : une seule version de licence déclarée — GPLv3+ partout
 *                     (style.css, readme.txt, README.md du thème) et aucune
 *                     mention GPL-2/GPLv2 résiduelle dans les docs livrés ;
 *   AH-003 (E-1702) : le packaging porte les exclusions d'artefact propre
 *                     (tests/, __screens__/, __baseline__/, *.sh) — le job CI
 *                     rejoue le contrôle complet sur les zips construits.
 *
 * Modes : PK_THEME_DIR (repo ou banc — sinon repli sur le thème installé via
 * PK_WP_DIR) ; PK_REPO_DIR optionnel pour AH-003 (scripts/package.sh —
 * signalé SKIPPED dans les limitations si absent, jamais silencieusement
 * vert).
 *
 * Rejouable : PK_THEME_DIR=... [PK_REPO_DIR=...] php partikulier-core/tests/artifact-hygiene-contract.php
 */

declare(strict_types=1);

$themeDir = getenv('PK_THEME_DIR') ?: '';
$repoDir = getenv('PK_REPO_DIR') ?: '';
$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
$version = getenv('PK_VERSION') ?: '2.10.8';

if ($themeDir === '' && $wpDir !== '' && is_file($wpDir . '/wp-load.php')) {
    require $wpDir . '/wp-load.php';
    $themeDir = (string) get_template_directory();
}
if ($themeDir === '' || !is_dir($themeDir)) {
    fwrite(STDERR, "PK_THEME_DIR (ou PK_WP_DIR avec thème actif) requis\n");
    exit(2);
}
$themeDir = rtrim($themeDir, '/');
$limitations = [];

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

try {
    /* AH-001 — capture d'écran du thème (E-1701). */
    $shot = $themeDir . '/screenshot.png';
    $shotOk = false;
    $shotDetail = '';
    if (is_file($shot)) {
        $info = getimagesize($shot);
        $kio = round(filesize($shot) / 1024, 1);
        $shotOk = is_array($info)
            && $info[0] === 1200
            && $info[1] === 900
            && ($info['mime'] ?? '') === 'image/png'
            && $kio <= 300.0;
        $shotDetail = sprintf('screenshot.png : %s×%s, %s, %s Ko (exigé : PNG 1200×900 ≤ 300 Ko)',
            is_array($info) ? $info[0] : '?', is_array($info) ? $info[1] : '?',
            is_array($info) ? ($info['mime'] ?? '?') : '?', $kio);
    } else {
        $shotDetail = 'screenshot.png absent de la racine du thème';
    }
    $assert('AH-001', $shotOk, $shotDetail);

    /* AH-002 — licence unique (E-1703, DP-1 option a : GPLv3+ partout).
     * Un fichier en conflit = qui mentionne GPL-2/GPLv2 ou déclare une autre
     * version ; l'absence de mention n'est pas un conflit (E-1703 : « grep
     * unique des déclarations » — aucune autre version déclarée). Les deux
     * fichiers canoniques (style.css, readme.txt) doivent déclarer GPLv3+. */
    $licenceFiles = [
        'style.css' => (string) file_get_contents($themeDir . '/style.css'),
        'readme.txt' => (string) file_get_contents($themeDir . '/readme.txt'),
    ];
    foreach (['README.md', 'DOC.md', 'guide-utilisation.md'] as $extra) {
        if (is_file($themeDir . '/' . $extra)) {
            $licenceFiles[$extra] = (string) file_get_contents($themeDir . '/' . $extra);
        }
    }
    $licenceOk = true;
    $licenceDetail = [];
    $mentionsV2 = [];
    $mentionsAutres = [];
    foreach ($licenceFiles as $name => $content) {
        $v3 = preg_match('/GPL\s*v?3|gpl-3\.0|General Public License v3/i', $content) === 1;
        $v2 = preg_match('/GPL\s*v?2|gpl-2\.0|GPLv2/i', $content) === 1;
        $licenceDetail[] = sprintf('%s:%s', $name, $v3 ? ' GPLv3+' : ($v2 ? ' GPLv2+ !' : ' (aucune)'));
        if ($v2) {
            $licenceOk = false;
            $mentionsV2[] = $name;
        }
        $canonique = in_array($name, ['style.css', 'readme.txt'], true);
        if ($canonique && !$v3) {
            $licenceOk = false;
            $mentionsAutres[] = $name . ' (déclaration GPLv3+ manquante)';
        }
    }
    $assert('AH-002', $licenceOk,
        'déclarations de licence — ' . implode(' ; ', $licenceDetail)
        . ($mentionsV2 === [] && $mentionsAutres === [] ? '' : ' — CONFLITS : ' . implode(', ', array_merge($mentionsV2, $mentionsAutres))));

    /* AH-003 — gardes du packaging (E-1702). */
    if ($repoDir !== '' && is_file($repoDir . '/scripts/package.sh')) {
        $package = (string) file_get_contents($repoDir . '/scripts/package.sh');
        $guards = [
            'exclusion tests/' => strpos($package, '-name tests') !== false,
            'exclusion __screens__/' => strpos($package, '-name __screens__') !== false,
            'exclusion __baseline__/' => strpos($package, '-name __baseline__') !== false,
            'exclusion *.sh' => strpos($package, "name '*.sh'") !== false,
        ];
        $missing = array_keys(array_filter($guards, static fn(bool $ok): bool => !$ok));
        $assert('AH-003', $missing === [],
            'package.sh : exclusions d\'artefact propre présentes ('
            . ($missing === [] ? 'tests/, __screens__/, __baseline__/, *.sh' : 'MANQUENTES : ' . implode(', ', $missing)) . ')');
    } else {
        $limitations[] = 'AH-003 non exécuté : PK_REPO_DIR absent (scripts/package.sh hors du banc)';
    }
} catch (Throwable $error) {
    $assert('AH-EXCEPTION', false, $error->getMessage());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'artifact-hygiene-contract (SE-017 — campagne post-audit)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/artifact-hygiene-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'limitations' => $limitations,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
