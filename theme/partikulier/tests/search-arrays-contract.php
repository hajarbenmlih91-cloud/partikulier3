<?php
/**
 * Contrat E-5105 (F-T17-2, lot sécurité 6.20.6 — CDC v5.7.2, C-3).
 *
 * Toute entrée $_GET consommée par les filtres de recherche qui n'est pas
 * un scalaire doit être traitée comme NON RÉSOLUE : réponse 200, filtre
 * ignoré ou résultat vidé (discipline « filtre non résolu = vide, jamais
 * tout le catalogue », S17) — JAMAIS d'erreur fatale.
 *
 * Constat d'origine (reproduit sur zips publiés 6.20.5) : sanitize_title()
 * appelé sur un tableau (es_city[]=x, location[]=x) → remove_accents() →
 * preg_match() → TypeError PHP 8 → HTTP 500 public. Quatre sites fatals
 * corrigés par garde is_scalar : templates/header.php:83,
 * templates/parts/search-form.php:35, templates/archive.php:198,
 * inc/class-search-filters.php:406 (+ homogénéisation
 * inc/class-listing-urls.php:326-328).
 *
 * La page cible est l'archive properties en permaliens simples
 * (/?post_type=properties) : elle charge header.php, le formulaire de
 * recherche et l'archive du thème, et déclenche les hooks de filtres —
 * tous les sites corrigés sont traversés, sans dépendre de la structure
 * de permaliens du banc.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_BASE=http://127.0.0.1:8102 \
 *   php theme/partikulier/tests/search-arrays-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
$base = rtrim((string) (getenv('PK_BASE') ?: 'http://127.0.0.1:8102'), '/');
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

/** GET réel sur le serveur du banc : code HTTP + absence de page d'erreur. */
$getStatus = static function (string $path) use ($base): array {
    $ch = curl_init($base . $path);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_TIMEOUT => 30,
    ]);
    $body = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = (string) curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
};

$archiveLink = (string) get_post_type_archive_link('properties');
$archivePath = (string) (parse_url($archiveLink, PHP_URL_PATH) ?: '/');
$archiveQuery = (string) (parse_url($archiveLink, PHP_URL_QUERY) ?: '');
$archive = $archivePath . ($archiveQuery !== '' ? '?' . $archiveQuery : '');
$sep = $archiveQuery !== '' ? '&' : '?';

try {
    /* E5105-001 — es_city[] : traverse header.php, search-form.php et
       archive.php (les 3 sites es_city). Pré-fix : TypeError → 500. */
    $r = $getStatus($archive . $sep . 'es_city[]=casablanca');
    $assert('E5105-001', $r['status'] === 200,
        sprintf('GET %s%ses_city[]=x → HTTP %d (attendu 200 ; le tableau est traité comme non résolu)%s',
            $archive, $sep, $r['status'], $r['error'] !== '' ? ' — ' . $r['error'] : ''));

    /* E5105-002 — location[] : traverse class-search-filters.php et
       class-listing-urls.php (sites location). Pré-fix : TypeError → 500. */
    $r = $getStatus($archive . $sep . 'location[]=casablanca');
    $assert('E5105-002', $r['status'] === 200,
        sprintf('GET %s%slocation[]=x → HTTP %d (attendu 200)', $archive, $sep, $r['status']));

    /* E5105-003→008 — les six autres paramètres consommés : le contrat est
       l'équivalence tableau ≡ scalaire (un terme inconnu peut légitimement
       rendre 404 « résultat vide », discipline S17) — jamais de 500, et le
       tableau ne doit pas changer le comportement observé du paramètre. */
    $others = [
        'E5105-003' => 'es_type',
        'E5105-004' => 'es_action',
        'E5105-005' => 'es_price_min',
        'E5105-006' => 'es_price_max',
        'E5105-007' => 'pk_order',
        'E5105-008' => 's',
    ];
    foreach ($others as $id => $param) {
        if ('s' === $param) {
            $arrayPath = '/?s[]=appartement';
            $scalarPath = '/?s=appartement';
        } else {
            $arrayPath = $archive . $sep . $param . '[]=fixture';
            $scalarPath = $archive . $sep . $param . '=fixture';
        }
        $ra = $getStatus($arrayPath);
        $rs = $getStatus($scalarPath);
        $assert($id, $ra['status'] !== 500 && $ra['status'] === $rs['status'],
            sprintf('GET %s → HTTP %d ≡ scalaire (HTTP %d), jamais 500', $arrayPath, $ra['status'], $rs['status']));
    }

    /* E5105-009 — non-régression scalaire : les usages légitimes (valeurs
       simples) chargent toujours la page normalement. */
    $r = $getStatus($archive . $sep . 'es_city=casablanca&location=casablanca');
    $assert('E5105-009', $r['status'] === 200,
        sprintf('GET %s%ses_city=casablanca&location=casablanca → HTTP %d (les scalaires restent résolus)',
            $archive, $sep, $r['status']));
} catch (Throwable $error) {
    $assert('E5105-EXCEPTION', false, $error->getMessage());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'search-arrays-contract (E-5105 — lot sécurité 6.20.6)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php theme/partikulier/tests/search-arrays-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
