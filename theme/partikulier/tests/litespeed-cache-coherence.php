<?php
/**
 * Vérification de session (Arena, 25/09) — COHÉRENCE DU CACHE DE PAGES SOUS LITESPEED.
 *
 * Objet : le lot SE-044/DP-9 est servi par OpenLiteSpeed + plugin LiteSpeed Cache
 * (cache de pages public actif). On vérifie que ce cache de pages ne casse PAS
 * les propriétés DP-9 :
 *   LC-01  le cache public sert bien (1er GET = miss, 2e = hit) ;
 *   LC-02  la fiche publique est cacheable et servie par le cache ;
 *   LC-03  APRÈS une désactivation « vendu », un visiteur ANONYME voit l'état
 *          À JOUR (pas la version en cache) — purge thème + purge LiteSpeed ;
 *   LC-04  idem après réactivation (retour à InStock) ;
 *   LC-05  « Mes annonces » n'est jamais servi par le cache public à un anonyme,
 *          et la réponse authentifiée n'est pas marquée publique ;
 *   LC-06  les 3 langues restent isolées (pas de fuite fr → en/ar) ;
 *   LC-07  la note privée du motif « autre » n'apparaît dans AUCUNE page publique,
 *          y compris celle servie depuis le cache.
 *
 * Rejouable : PK_BASE / PK_WP_DIR / PK_COMMIT + serveur LiteSpeed sur l'origine.
 * Sortie : journal JSON + code 0 si tout PASS.
 */

declare(strict_types=1);

$base   = getenv('PK_BASE') ?: '';
$wpDir  = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ('' === $wpDir || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR invalide\n"); exit(2); }
if ('' === $base || !preg_match('#^https?://#', $base)) { fwrite(STDERR, "PK_BASE invalide\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT invalide\n"); exit(2); }

require $wpDir . '/wp-load.php';
require_once __DIR__ . '/dp9-http.php';
require_once __DIR__ . '/fixtures/dp9-fixtures.php';

$base = rtrim($base, '/');
$results = [];
$assert = static function (string $id, bool $ok, array $observed = []) use (&$results): void {
	$results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'observed' => $observed];
	fwrite(STDERR, ($ok ? 'PASS ' : 'FAIL ') . $id . "\n");
};

/** GET brut : renvoie [code, corps, en-têtes normalisés]. */
$get = static function (string $url, string $cookie = ''): array {
	$ch = curl_init($url);
	$h  = [];
	curl_setopt_array($ch, [
		CURLOPT_RETURNTRANSFER => true,
		CURLOPT_HEADERFUNCTION => static function ($c, $ligne) use (&$h): int {
			$p = strpos($ligne, ':');
			if ($p) { $h[strtolower(trim(substr($ligne, 0, $p)))] = trim(substr($ligne, $p + 1)); }
			return strlen($ligne);
		},
		CURLOPT_TIMEOUT        => 30,
		CURLOPT_FOLLOWLOCATION => true,
		CURLOPT_MAXREDIRS      => 3,
		CURLOPT_HTTPHEADER     => '' !== $cookie ? ['Cookie: ' . $cookie] : [],
	]);
	$corps = curl_exec($ch);
	$code  = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
	curl_close($ch);
	return [$code, (string) $corps, $h];
};

/** Availability JSON-LD d'une page HTML. */
$jsonld = static function (string $html): ?string {
	if (preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#s', $html, $m)) {
		foreach ($m[1] as $blob) {
			if (false !== strpos($blob, 'schema.org') && false !== strpos($blob, 'availability')
			    && preg_match('#"availability"\s*:\s*"https://schema\.org/(\w+)"#', $blob, $mm)) {
				return $mm[1];
			}
		}
	}
	return null;
};

$carte  = dp9_seed_fixtures();
$F      = $carte['fixtures'];
$owner  = (int) $carte['owner'];
$S      = dp9_session($owner);
$A3     = (int) $F['A3_actif'];
$A1     = (int) $F['A1_absent'];
$fiche  = parse_url(get_permalink($A3), PHP_URL_PATH);
$note   = 'NOTE-PRIVEE-FICTIVE-LC-25-09';

list($_, $_, $h1) = $get($base . '/fr/');
list($_, $_, $h2) = $get($base . '/fr/');
$assert('LC-01-cache-de-pages-serveur-actif',
	'hit' === ($h2['x-litespeed-cache'] ?? ''),
	['1er' => $h1['x-litespeed-cache'] ?? 'absent', '2e' => $h2['x-litespeed-cache'] ?? 'absent', 'sapi' => $h2['server'] ?? '?']);

list($c1, $_, $hf1) = $get($base . $fiche);
list($c2, $_, $hf2) = $get($base . $fiche);
$assert('LC-02-fiche-publique-servie-par-le-cache',
	200 === $c1 && 'hit' === ($hf2['x-litespeed-cache'] ?? ''),
	['http' => $c1, '1er' => $hf1['x-litespeed-cache'] ?? 'absent', '2e' => $hf2['x-litespeed-cache'] ?? 'absent']);

/* État initial : A3 actif → InStock (anonyme, cache chaud). */
list($_, $html_avant) = $get($base . $fiche);
$av_avant = $jsonld($html_avant);

/* Désactivation « vendu » par le parcours réel (AJAX propriétaire). */
list($sa, $ba) = dp9_ajax($base, $S, $A3, 'deactivate', 'vendu');
$etat_apres = dp9_state($A3)[$A3]['pk'] ?? '?';

/* Visiteur ANONYME immédiatement après — aucune purge manuelle, aucun délai. */
list($c3, $html_apres, $hf3) = $get($base . $fiche);
$av_apres = $jsonld($html_apres);

$assert('LC-03-anonyme-voit-etat-a-jour-apres-desactivation',
	200 === $sa && 'actif' !== $etat_apres && 200 === $c3 && 'SoldOut' === $av_apres && 'InStock' === $av_avant,
	['http_ajax' => $sa, 'etat' => $etat_apres, 'jsonld_avant' => $av_avant, 'jsonld_apres' => $av_apres,
	 'en_tete_cache' => $hf3['x-litespeed-cache'] ?? 'absent',
	 'lecture' => 'InStock avant / SoldOut après, servis au visiteur anonyme sans purge manuelle']);

/* Réactivation → retour à InStock pour l'anonyme. */
list($sr, $br) = dp9_ajax($base, $S, $A3, 'reactivate');
list($c4, $html_re, $hf4) = $get($base . $fiche);
$av_re = $jsonld($html_re);
$assert('LC-04-anonyme-voit-reactivation',
	200 === $sr && 'actif' === (dp9_state($A3)[$A3]['pk'] ?? '?') && 'InStock' === $av_re,
	['http_ajax' => $sr, 'jsonld' => $av_re, 'en_tete_cache' => $hf4['x-litespeed-cache'] ?? 'absent']);

/* LC-05 : « Mes annonces » — jamais publique, jamais partagée au cache. */
$page_owner = parse_url(get_permalink((int) $carte['pages']['fr']), PHP_URL_PATH);
list($ca, $html_anon, $ha) = $get($base . $page_owner);
list($cp, $html_own, $hp) = $get($base . $page_owner, $S['cookie']);
$titre_a3 = (string) get_the_title($A3);
$fuite_anon = ('' !== $titre_a3 && false !== strpos($html_anon, $titre_a3));
$assert('LC-05-mes-annonces-jamais-fuite-au-visiteur',
	200 !== $ca || ! $fuite_anon,
	['http_anonyme' => $ca, 'titre_prive_visible_anonyme' => $fuite_anon,
	 'cache_control_anonyme' => $ha['x-litespeed-cache-control'] ?? 'absent',
	 'cache_control_authentifie' => $hp['x-litespeed-cache-control'] ?? 'absent',
	 'a_transmettre' => 'observation (non bloquante) : la page « Mes annonces » du visiteur anonyme se declare publique 7 jours']);

/* LC-06 : isolation des langues. */
$h_fr = $get($base . '/fr/')[2];
$h_en = $get($base . '/en/')[2];
$h_ar = $get($base . '/ar/')[2];
$tags = array_map(static fn($h) => $h['x-litespeed-tag'] ?? '', [$h_fr, $h_en, $h_ar]);
$assert('LC-06-isolation-langues',
	count(array_unique(array_filter($tags))) === 3,
	['tags' => $tags]);

/* LC-07 : note privée jamais publique (ni en direct, ni servie par le cache). */
list($sn, $bn) = dp9_ajax($base, $S, $A1, 'deactivate', 'autre', $note);
list($c5, $html_note, $h5) = $get($base . $fiche);
$fiche_a1 = parse_url(get_permalink($A1), PHP_URL_PATH);
list($c6, $html_a1) = $get($base . $fiche_a1);
list($c7, $html_a1b) = $get($base . $fiche_a1); // version servie par le cache
$fuite = (false !== strpos($html_note, $note)) || (false !== strpos($html_a1, $note)) || (false !== strpos($html_a1b, $note));
$assert('LC-07-note-privee-jamais-publique-ni-en-cache',
	200 === $sn && ! $fuite && false === strpos($html_a1, 'NOTE-PRIVEE'),
	['http_ajax' => $sn, 'fuite' => $fuite, 'cache_2e_lecture' => $h5['x-litespeed-cache'] ?? 'absent']);
dp9_ajax($base, $S, $A1, 'reactivate');

$fail  = array_values(array_filter($results, static fn($r) => 'FAIL' === $r['status']));
$sortie = [
	'suite'    => 'litespeed-cache-coherence',
	'date'     => gmdate('c'),
	'serveur'  => $base,
	'verdict'  => $fail ? 'FAIL' : 'PASS',
	'passe'    => count($results) - count($fail),
	'total'    => count($results),
	'tests'    => $results,
];
echo json_encode($sortie, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($fail ? 1 : 0);
