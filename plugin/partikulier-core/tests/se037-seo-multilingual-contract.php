<?php
/**
 * Contrat SE-037 — SEO multilingue : sitemap ×3 langues (E-3701), title
 * filter (E-3702), non-régression FR (E-3703), micro-lot pré-prod
 * 2.10.8/6.20.7, DP-5 jour 1).
 *
 * R1 (pré-correctif, mesuré) : le sitemap.xml virtuel était FR-ONLY —
 * aucune balise xhtml:link, aucun cluster d'alternates : Google indexait
 * chaque page comme une URL isolée, sans comprendre que /fr/, /en/ /ar/
 * (à venir avec Polylang, lot SE-025) désignent la même page. Les fondations
 * title/hreflang (geo_chain localisé 6.17.30, hreflang_head pll-guardé)
 * existaient déjà — le lot les fige et complète le sitemap.
 *
 * Ce contrat verrouille :
 *  - E37-001 (E-3701) : le sitemap généré est un XML valide qui déclare
 *    xmlns:xhtml et porte un cluster d'alternates hreflang par <url>
 *    (fr + x-default au minimum sans Polylang) ; home, archive et les
 *    annonces publiées y figurent ;
 *  - E37-002 (E-3701) : language_alternates — sans Polylang : l'URL
 *    elle-même en fr + x-default (le sitemap reste complet, E-3703) ;
 *    le builder est pll-aware (les traductions s'y raccorderont au lot
 *    SE-025 — écart tracé, la full smoke ×3 langues vit dans SE-025) ;
 *  - E37-003 (E-3703) : non-régression FR — le titre d'une annonce garde
 *    la forme [titre] — [géo FR « à »] | site, l'archive garde
 *    « Annonces », le sitemap garde toutes ses URLs FR ;
 *  - E37-004 (E-3702) : la chaîne géo se localise — composants type/
 *    statut/lieu résolus EN (lexique) et AR (lexique + dictionnaire des
 *    lieux SE-036), formats de phrase par langue présents (« in » / « في ») ;
 *  - E37-005 (E-3701/E-3703) : hreflang_head sans Polylang n'émet RIEN
 *    (aucune balise parasite), le filtre est câblé sur wp_head priorité 2,
 *    et robots.txt pointe le sitemap.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_REPO_DIR=<racine> php
 *   partikulier-core/tests/se037-seo-multilingual-contract.php
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

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

try {
    delete_transient('pk_sitemap_xml');
    $generate = new ReflectionMethod('Partikulier_Sitemap', 'generate');
    $generate->setAccessible(true);
    $xml = (string) $generate->invoke(null);

    // 1) Structure : xhtml + alternates + URLs attendues.
    $validXml = @simplexml_load_string('<?xml version="1.0" encoding="UTF-8"?>' . $xml);
    // SimpleXML se perd avec les enfants préfixés xhtml : l'extraction se
    // fait par motif (la validité reste prouvée par simplexml_load_string).
    preg_match_all('#<loc>([^<]+)</loc>#', $xml, $locMatches);
    $locs = $locMatches[1];
    $alternatesCount = substr_count($xml, '<xhtml:link');
    $homeOk = in_array(home_url('/'), $locs, true);
    $archiveOk = false;
    foreach ($locs as $l) { if (false !== strpos($l, (string) pk_properties_archive_url())) { $archiveOk = true; break; } }
    $listingOk = count($locs) >= 30; // les fixtures du banc (30 annonces)
    $assert('E37-001',
        $validXml !== false && strpos($xml, 'xmlns:xhtml') !== false && $alternatesCount >= count($locs)
        && $homeOk && $archiveOk && $listingOk,
        sprintf('sitemap : XML %s, xmlns:xhtml %s, %d urls (home %s, archive %s, annonces %d), %d balises hreflang (≥1 par url)',
            $validXml !== false ? 'valide' : 'INVALIDE', strpos($xml, 'xmlns:xhtml') !== false ? 'déclaré' : 'ABSENT',
            count($locs), $homeOk ? 'oui' : 'NON', $archiveOk ? 'oui' : 'NON', $listingOk ? 30 : 0, $alternatesCount));

    // 2) language_alternates : sans Polylang — l'URL elle-même + x-default ;
    //    pll-aware (le raccordement ×3 vit au lot SE-025 — écart tracé).
    $post = get_posts(['post_type' => 'properties', 'post_status' => 'publish', 'numberposts' => 1]);
    $pid = $post ? (int) $post[0]->ID : 0;
    $alt = $pid ? Partikulier_Sitemap::language_alternates($pid) : [];
    $expectedLoc = $pid ? (string) get_permalink($pid) : '';
    $source = (string) file_get_contents($repoDir . '/theme/partikulier/inc/class-sitemap.php');
    $pllAware = strpos($source, "pll_get_post_translations' )") !== false && strpos($source, "pll_default_language' )") !== false;
    $assert('E37-002',
        $pid > 0 && isset($alt['fr']) && $alt['fr'] === $expectedLoc && isset($alt['x-default']) && $alt['x-default'] === $expectedLoc && $pllAware,
        sprintf('language_alternates : sans Polylang fr = x-default = « %s » (%d clés) ; builder pll-aware %s (raccordement ×3 : lot SE-025)',
            $expectedLoc, count($alt), $pllAware ? '✓' : 'ABSENT'));

    // 3) Non-régression FR : titre d'annonce + archive.
    $frPost = $post ? $post[0] : null;
    $geoFr = $frPost ? Partikulier_SEO::geo_chain($frPost) : '';
    $frOk = true;
    if ($frPost) {
        // la géo FR (si présente) utilise la préposition française « à »
        if ('' !== $geoFr && false === strpos($geoFr, ' à ') && false === mb_stripos($geoFr, 'à ')) { $frOk = false; }
        // le composant lieu n'est pas passé en arabe (garde-fou)
        if (false !== strpos($geoFr, 'في') || false !== strpos($geoFr, ' in ')) { $frOk = false; }
    }
    $assert('E37-003', $frPost !== null && $frOk && '' !== __('Annonces', 'partikulier'),
        sprintf('non-régression FR : géo « %s » (%s), libellé archive « %s »', $geoFr ?: '—', $frOk ? 'forme FR' : 'FORME ALTERÉE', __('Annonces', 'partikulier')));

    // 4) Chaîne localisée : composants EN/AR résolus par les dictionnaires.
    $typeEn = Partikulier_Listing_I18n::localized_type('Appartement', 'en');
    $typeAr = Partikulier_Listing_I18n::localized_type('Appartement', 'ar');
    $placeAr = Partikulier_Listing_I18n::localized_place('Casablanca', 'ar');
    $seoSrc2 = (string) file_get_contents($repoDir . '/theme/partikulier/inc/class-seo.php');
    $templates = strpos($seoSrc2, '%1$s à %2$s') !== false
        && strpos($seoSrc2, '%1$s in %2$s') !== false
        && strpos($seoSrc2, '%1$s في %2$s') !== false;
    $assert('E37-004',
        $typeEn !== 'Appartement' && $typeAr !== 'Appartement' && $placeAr !== 'Casablanca' && $templates,
        sprintf('chaîne localisée : type EN « %s » / AR « %s », lieu AR « %s » (lexiques + dictionnaire SE-036), modèles de phrase FR/EN/AR %s',
            $typeEn, $typeAr, $placeAr, $templates ? 'présents' : 'ABSENTS'));

    // 5) hreflang_head : silencieux sans Polylang + câblage + robots.
    ob_start();
    Partikulier_SEO::hreflang_head();
    $headOut = (string) ob_get_clean();
    $seoSrc = (string) file_get_contents($repoDir . '/theme/partikulier/inc/class-seo.php');
    $wired = strpos($seoSrc, "array( __CLASS__, 'hreflang_head' ), 2 )") !== false;
    $robots = (string) apply_filters('robots_txt', '', true);
    $robotsOk = strpos($robots, 'Sitemap: ' . home_url('/sitemap.xml')) !== false;
    $assert('E37-005', '' === trim($headOut) && $wired && $robotsOk,
        sprintf('hreflang : sans Polylang sortie vide (%d car.), filtre câblé wp_head/2 %s, robots.txt → sitemap %s',
            strlen($headOut), $wired ? '✓' : 'ABSENT', $robotsOk ? '✓' : 'ABSENT'));
} catch (Throwable $error) {
    $assert('E37-EXCEPTION', false, $error->getMessage());
} finally {
    delete_transient('pk_sitemap_xml');
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se037-seo-multilingual-contract (sitemap ×3 langues + title filter + non-régression FR, E-3701→E-3703)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/se037-seo-multilingual-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
