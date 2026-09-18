<?php
/**
 * Batterie SE-043 E-4304 — routage géo des échecs sur HTTP réel (micro-lot
 * pré-prod 2.10.8/6.20.7). Infrastructure de la famille front-assets/
 * se022 : serveur PHP intégré sur le port de home_url() (8099 — requis :
 * redirect_canonical réécrirait toute autre origine), mutation des fixtures
 * par wp-load (CLI), sondes curl.
 *
 * R1 (pré-correctif, mesuré) : les échecs géo rendaient une ARCHIVE VIDE
 * en 200 (soft 404) AVEC Cache-Control: public et un fichier cache écrit ;
 * aucune redirection d'ancien slug en table (aucun câblage du cycle de
 * vie) ; l'annonce corbeillée rendait 200.
 *
 * Hérmeticité (leçons du développement, mesurées sur le banc) : les
 * fixtures portent un préfixe unique par run (wp_unique_post_slug ne doit
 * JAMAIS suffixer — sinon les slugs attendus n'existent pas) et les
 * suppressions du nettoyage exigent wp_set_current_user(1) (sans quoi
 * wp_delete_post() échoue en silence par manque de capability et pollue
 * les runs suivants).
 *
 * La batterie verrouille (post-correctif) :
 *  - E43T-001 : fiche valide → 200 direct (non-régression, pas de Location) ;
 *  - E43T-002 : slug aléatoire → 404 RÉEL, sans Cache-Control: public,
 *               sans fichier cache (E-4301/E-4302) ;
 *  - E43T-003 : slug corbeillé → 410 (l'URL a vécu, elle ne reviendra
 *               pas), sans Cache-Control: public, sans fichier cache ;
 *  - E43T-004 : ancien slug (après renommage) → 301 vers le permalink
 *               COURANT, qui répond 200 (E-4303) ;
 *  - E43T-005 : deux renommages successifs → les DEUX anciens slugs mènent
 *               au même permalink courant (jamais de chaîne A→B→C) ;
 *  - E43T-006 : slug repris par une autre annonce → 200 nouvelle fiche,
 *               0 redirection résiduelle pour ce slug ;
 *  - E43T-007 : suppression définitive → 0 ligne orpheline dans
 *               pk_slug_redirects (l'ancien slug rend 404) ;
 *  - E43T-008 : la gate ne sur-bloque pas : la fiche valide reste publique
 *               et cachable (Cache-Control: public + fichier cache écrit) ;
 *  - E43T-009 : archives valides inchangées : /annonces/ → 200 (FR).
 *
 * Écart tracé (pas de déviation silencieuse) : la part « lang/dir/hreflang »
 * de E-4304 (URLs valides ×3 langues) exige Polylang, que le harnais
 * n'installe pas à ce lot — elle est rejouée au lot SE-025 (workflow
 * trilingue + 9 URLs), même pattern que l'ajournement E-5405 (addendum 1).
 *
 * Sortie propre : fixtures supprimées (utilisateur admin), lignes de
 * redirection purgées, fichiers cache des URLs de sonde retirés.
 *
 * Rejouable : PK_BASE=http://127.0.0.1:8099 PK_WP_DIR=<wp> PK_COMMIT=<sha>
 *   php plugin/partikulier-core/tests/se043-geo-routing-battery.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$base = getenv('PK_BASE') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if ($base === '' || !preg_match('#^https?://#', $base)) { fwrite(STDERR, "PK_BASE doit pointer vers le serveur démarré\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\SlugRedirects\SlugRedirectsService;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
wp_set_current_user(1); // le nettoyage exige les capabilities de suppression
$cpt = PARTIKULIER_ESTATIK_POST_TYPE;
$run = bin2hex(random_bytes(4));          // hérmeticité : slugs uniques par run
$city = 'se043ville-' . $run;
$slugOf = static fn(string $key): string => 'se043-' . $run . '-' . $key;
$geo = static fn(string $slug): string => rtrim($base, '/') . '/annonce/' . $city . '/' . $slug . '/';

/** Sonde GET : code, Location, Cache-Control public ?, corps. */
$probe = static function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    $hsize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    $headers = is_string($raw) ? substr($raw, 0, $hsize) : '';
    $body = is_string($raw) ? substr($raw, $hsize) : '';
    $location = '';
    $public = false;
    foreach (preg_split('/\r?\n/', $headers) ?: [] as $line) {
        if (preg_match('#^Location:\s*(\S+)#i', $line, $m)) { $location = $m[1]; }
        if (preg_match('#^Cache-Control:\s*public#i', $line)) { $public = true; }
    }
    return ['status' => $code, 'location' => $location, 'public' => $public, 'body' => $body, 'error' => $error];
};

/** Fichiers cache (.html) écrits pour un slug géo donné. */
$cacheFiles = static function (string $slug) use ($city): array {
    $upload = wp_get_upload_dir();
    $dir = trailingslashit($upload['basedir']) . 'partikulier-cache';
    if (!is_dir($dir)) { return []; }
    $uri = 'annonce_' . $city . '_' . $slug;
    return array_values(array_filter((array) glob($dir . '/*' . $uri . '.html') ?: [], 'is_file'));
};

$upload = wp_get_upload_dir();
$cacheDir = trailingslashit($upload['basedir']) . 'partikulier-cache';
$purgeRunCache = static function () use ($cacheDir, $run): void {
    if (is_dir($cacheDir)) {
        foreach ((array) glob($cacheDir . '/*' . $run . '*') ?: [] as $cf) { @wp_delete_file($cf); }
    }
};

/* ── Préparation : fixtures dédiées (ville figée par méta, comme store_geo) ── */
$mk = static function (string $key) use ($cpt, $city, $slugOf): int {
    $slug = $slugOf($key);
    $id = wp_insert_post(['post_type' => $cpt, 'post_status' => 'publish', 'post_title' => 'SE043 ' . $slug, 'post_name' => $slug, 'post_author' => 1]);
    if ($id > 0) { update_post_meta($id, '_pk_url_city', $city); }
    return (int) $id;
};

$a = $mk('a');          // fiche vivante
$b = $mk('b');          // subira 2 renommages (→ b2 → b3)
$c = $mk('c');          // sera corbeillée
$d = $mk('d');          // son slug sera repris par e
$e = $mk('e');          // reprendra le slug de d
$f = $mk('f');          // renommée puis supprimée définitivement

wp_update_post(['ID' => $b, 'post_name' => $slugOf('b2')]);
wp_update_post(['ID' => $b, 'post_name' => $slugOf('b3')]);
wp_trash_post($c);
wp_update_post(['ID' => $d, 'post_name' => $slugOf('d2')]);
wp_update_post(['ID' => $e, 'post_name' => $slugOf('d')]);   // reprise du slug de d
wp_update_post(['ID' => $f, 'post_name' => $slugOf('f2')]);
wp_delete_post($f, true);

$expectedPermalinkB = (string) get_permalink($b);
$purgeRunCache();

try {
    // 1) Fiche valide → 200 direct.
    $r = $probe($geo($slugOf('a')));
    $assert('E43T-001', $r['status'] === 200 && '' === $r['location'],
        sprintf('fiche valide : HTTP %d, Location « %s » (attendu 200 sans redirection)', $r['status'], $r['location'] ?: '—'));

    // 2) Slug aléatoire → 404 réel, jamais public, jamais caché.
    $r404 = $probe($geo($slugOf('aleatoire')));
    $files404 = $cacheFiles($slugOf('aleatoire'));
    $assert('E43T-002', $r404['status'] === 404 && !$r404['public'] && $files404 === [],
        sprintf('slug aléatoire : HTTP %d, Cache-Control public %s, fichiers cache %d (attendu 404, aucun, zéro)',
            $r404['status'], $r404['public'] ? 'PRÉSENT' : 'absent', count($files404)));

    // 3) Slug corbeillé → 410, jamais public, jamais caché.
    $r410 = $probe($geo($slugOf('c')));
    $files410 = $cacheFiles($slugOf('c'));
    $assert('E43T-003', $r410['status'] === 410 && !$r410['public'] && $files410 === [],
        sprintf('slug corbeillé : HTTP %d, Cache-Control public %s, fichiers cache %d (attendu 410, aucun, zéro)',
            $r410['status'], $r410['public'] ? 'PRÉSENT' : 'absent', count($files410)));

    // 4) Ancien slug → 301 vers le permalink courant, qui répond 200.
    $r301 = $probe($geo($slugOf('b')));
    $location = $r301['location'];
    $rTarget = $location !== '' ? $probe($location) : ['status' => 0];
    $assert('E43T-004', $r301['status'] === 301 && untrailingslashit($location) === untrailingslashit($expectedPermalinkB) && $rTarget['status'] === 200,
        sprintf('ancien slug b : HTTP %d → « %s » (permalink courant %s), cible HTTP %d',
            $r301['status'], $location, $expectedPermalinkB, $rTarget['status']));

    // 5) Deux renommages : les DEUX anciens slugs mènent au même permalink courant.
    $rOld2 = $probe($geo($slugOf('b2')));
    $assert('E43T-005', $rOld2['status'] === 301 && untrailingslashit($rOld2['location']) === untrailingslashit($expectedPermalinkB),
        sprintf('ancien slug intermédiaire b2 : HTTP %d → « %s » (même permalink courant — jamais de chaîne A→B→C)',
            $rOld2['status'], $rOld2['location']));

    // 6) Slug repris par une autre annonce → 200 nouvelle fiche, 0 résidu.
    $rReuse = $probe($geo($slugOf('d')));
    $reuseBodyOk = strpos($rReuse['body'], $slugOf('e')) !== false;
    $residual = class_exists(SlugRedirectsService::class)
        ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'pk_slug_redirects WHERE slug = %s', $slugOf('d')))
        : -1;
    $assert('E43T-006', $rReuse['status'] === 200 && $reuseBodyOk && $residual === 0,
        sprintf('slug repris par l\'annonce e : HTTP %d, fiche e servie %s, redirections résiduelles %d',
            $rReuse['status'], $reuseBodyOk ? 'oui' : 'NON', $residual));

    // 7) Suppression définitive → 0 ligne orpheline, ancien slug en 404.
    $orphans = class_exists(SlugRedirectsService::class)
        ? (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . $wpdb->prefix . 'pk_slug_redirects WHERE property_id = %d', $f))
        : -1;
    $rDeleted = $probe($geo($slugOf('f')));
    $assert('E43T-007', $orphans === 0 && $rDeleted['status'] === 404,
        sprintf('suppression définitive : lignes orphelines %d, ancien slug HTTP %d (attendu 0 et 404)', $orphans, $rDeleted['status']));

    // 8) La gate ne sur-bloque pas : la fiche valide reste publique et cachable.
    $rValid = $probe($geo($slugOf('a')));
    $filesValid = $cacheFiles($slugOf('a'));
    $assert('E43T-008', $rValid['status'] === 200 && $rValid['public'] && $filesValid !== [],
        sprintf('fiche valide : HTTP %d, Cache-Control public %s, fichier cache %d (le cache des succès demeure)',
            $rValid['status'], $rValid['public'] ? 'présent' : 'ABSENT', count($filesValid)));

    // 9) Archives valides inchangées (FR — part lang/dir/hreflang rejouée à SE-025).
    $rArchive = $probe(rtrim($base, '/') . '/annonces/');
    $assert('E43T-009', $rArchive['status'] === 200,
        sprintf('archive /annonces/ : HTTP %d (attendu 200 — inchangé)', $rArchive['status']));
} catch (Throwable $error) {
    $assert('E43T-EXCEPTION', false, $error->getMessage());
} finally {
    /* Sortie propre : fixtures (utilisateur admin requis), redirections, cache. */
    foreach ([$a, $b, $c, $d, $e] as $fixId) {
        if ($fixId > 0) { wp_delete_post($fixId, true); }
    }
    if (class_exists(SlugRedirectsService::class)) {
        foreach (['a', 'b', 'b2', 'b3', 'c', 'd', 'd2', 'e', 'f', 'f2'] as $key) {
            SlugRedirectsService::clear_redirect($slugOf($key));
        }
        SlugRedirectsService::clear_redirect($slugOf('aleatoire'));
    }
    $purgeRunCache();
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se043-geo-routing-battery (routage géo 404/410/301 + jamais de cache des échecs, E-4301→E-4304)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php plugin/partikulier-core/tests/se043-geo-routing-battery.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
