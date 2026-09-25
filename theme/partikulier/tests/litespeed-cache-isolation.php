<?php
/**
 * ACCEPTATION DU CORRECTIF — isolation des pages connectees quand le cache de pages
 * serveur (LiteSpeed Cache) est ACTIF (Arena, 25/09).
 *
 * Methode : chaque « personne » est une SESSION NAVIGATEUR REELLE (pot a cookies
 * persistant). C'est indispensable : le serveur pose lui-meme un cookie de variation
 * de cache au premier passage ; un test qui n'enverrait que le cookie de connexion
 * mesure un cas qui n'existe dans aucun navigateur (erreur commise puis corrigee le
 * 25/09 — voir RAPPORT-CORRECTIF-A.md, « faux positif »).
 *
 * Exigences :
 *   1. visiteur anonyme sur « mes annonces » : aucune annonce d'un proprietaire ;
 *   2. proprietaire : voit ses propres annonces ;
 *   3. proprietaire 2 : ne voit jamais les annonces du proprietaire 1 ;
 *   4. visiteur : ne recoit jamais une page « connectee » ;
 *   5. pages sensibles (depot, connexion) : jamais servies par le cache public ;
 *   6. apres le passage d'un visiteur, le proprietaire garde SA page (pas la copie
 *      publique mise en cache).
 */
declare(strict_types=1);
$wpDir = (string) getenv( 'PK_WP_DIR' );
$base  = rtrim( (string) getenv( 'PK_BASE' ), '/' );
require $wpDir . '/wp-load.php';
require_once __DIR__ . '/dp9-http.php';
require_once __DIR__ . '/fixtures/dp9-fixtures.php';

$hote = (string) parse_url( $base, PHP_URL_HOST );

/** Fabrique un pot a cookies Netscape pre-rempli (cookies de connexion). */
$pot = static function ( string $fichier, string $cookies ) use ( $hote ): string {
	$lignes = array( '# Netscape HTTP Cookie File' );
	foreach ( array_filter( array_map( 'trim', explode( ';', $cookies ) ) ) as $c ) {
		list( $n, $v ) = array_pad( explode( '=', $c, 2 ), 2, '' );
		$lignes[]      = implode( "\t", array( $hote, 'FALSE', '/', 'FALSE', (string) ( time() + 3600 ), $n, $v ) );
	}
	file_put_contents( $fichier, implode( "\n", $lignes ) . "\n" );
	return $fichier;
};
/** Lecture HTTP avec pot a cookies (le serveur peut y ajouter les siens). */
$lire = static function ( string $url, string $pot ) : array {
	$c = curl_init( $url );
	$h = array();
	curl_setopt_array(
		$c,
		array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_COOKIEFILE     => $pot,
			CURLOPT_COOKIEJAR      => $pot,
			CURLOPT_HEADERFUNCTION => static function ( $c, $l ) use ( &$h ): int {
				$p = strpos( $l, ':' );
				if ( $p ) {
					$h[ strtolower( trim( substr( $l, 0, $p ) ) ) ] = trim( substr( $l, $p + 1 ) );
				}
				return strlen( $l );
			},
			CURLOPT_TIMEOUT         => 60,
		)
	);
	$b = curl_exec( $c );
	curl_close( $c );
	return array( $h, (string) $b );
};

$carte = dp9_seed_fixtures();
$F     = $carte['fixtures'];
$owner = (int) $carte['owner'];
$autre = (int) $carte['other'];

/* Une annonce appartenant au SECOND proprietaire : indispensable pour verifier
   qu'un proprietaire ne voit jamais les annonces de l'autre. */
$slug_autre    = 'dp9-annonce-de-lautre-proprietaire';
$existante     = get_page_by_path( $slug_autre, OBJECT, 'annonce' );
$annonce_autre = $existante ? (int) $existante->ID : (int) wp_insert_post(
	array(
		'post_type'   => 'annonce',
		'post_status' => 'publish',
		'post_title'  => 'DP9 annonce du second proprietaire',
		'post_name'   => $slug_autre,
		'post_author' => $autre,
	)
);
update_post_meta( $annonce_autre, '_pk_status', 'actif' );

$S1 = dp9_session( $owner );
$S2 = dp9_session( $autre );

$chemin_mes = '/fr/mes-annonces/';
$chemin_fic = (string) parse_url( get_permalink( (int) $F['A3_actif'] ), PHP_URL_PATH );
$slug_owner = (string) get_post_field( 'post_name', (int) $F['A3_actif'] );
$tampon     = sys_get_temp_dir();
$p_owner    = $pot( $tampon . '/pk-iso-owner.txt', $S1['cookie'] );
$p_autre    = $pot( $tampon . '/pk-iso-autre.txt', $S2['cookie'] );
$p_visiteur = $tampon . '/pk-iso-visiteur.txt';
@unlink( $p_visiteur );

$res = array();
$t   = static function ( string $id, bool $ok, array $obs = array() ) use ( &$res ): void {
	$res[] = array( 'test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'observed' => $obs );
};

/* 1 — visiteur anonyme : rien de prive. */
list( $h1, $b1 )      = $lire( $base . $chemin_mes, $p_visiteur );
$voit_owner           = false !== strpos( $b1, $slug_owner );
$voit_autre           = false !== strpos( $b1, $slug_autre );
$connecte_visiteur    = false !== strpos( $b1, '>Mon espace<' );
$t(
	'ISO-01-visiteur-ne-voit-aucune-annonce-privee',
	! $voit_owner && ! $voit_autre && ! $connecte_visiteur,
	array(
		'voit_annonce_owner'  => $voit_owner,
		'voit_annonce_autre'  => $voit_autre,
		'page_connectee'      => $connecte_visiteur,
		'cache_serveur'       => $h1['x-litespeed-cache'] ?? '-',
	)
);

/* 2 — proprietaire 1 : voit son annonce ACTIVE. */
list( $h2, $b2 ) = $lire( $base . $chemin_mes, $p_owner );
$voit_sien       = false !== strpos( $b2, $slug_owner );
$t(
	'ISO-02-proprietaire-voit-ses-annonces',
	$voit_sien && false !== strpos( $b2, '>Mon espace<' ),
	array( 'contient_son_annonce' => $voit_sien, 'cache_serveur' => $h2['x-litespeed-cache'] ?? '-' )
);

/* 3 — proprietaire 2 : ne voit jamais l'annonce du proprietaire 1. */
list( , $b3 )        = $lire( $base . $chemin_mes, $p_autre );
$voit_autre_partagee = false !== strpos( $b3, $slug_owner );
$t(
	'ISO-03-proprietaire-2-ne-voit-pas-les-annonces-du-1',
	! $voit_autre_partagee,
	array( 'voit_annonce_du_proprietaire_1' => $voit_autre_partagee )
);

/* 4 — le visiteur ne recoit jamais une page connectee, meme apres le passage du proprietaire. */
list( , $b4 )        = $lire( $base . $chemin_mes, $p_visiteur );
$connecte_visiteur2  = false !== strpos( $b4, '>Mon espace<' );
$t(
	'ISO-04-pas-de-rejeu-de-page-connectee-au-visiteur',
	! $connecte_visiteur2 && false === strpos( $b4, $slug_owner ),
	array( 'page_connectee' => $connecte_visiteur2 )
);

/* 5 — pages sensibles : jamais servies par le cache public. */
$obs = array();
$ok  = true;
foreach ( array( '/fr/deposer-une-annonce/', '/fr/connexion/' ) as $chemin ) {
	list( $hs )     = $lire( $base . $chemin, $p_visiteur );
	$obs[ $chemin ] = array( 'etat' => $hs['x-litespeed-cache'] ?? '-', 'cache_control' => $hs['cache-control'] ?? '-' );
	$ok             = $ok && ( 'hit' !== ( $hs['x-litespeed-cache'] ?? '-' ) );
}
$t( 'ISO-05-pages-sensibles-jamais-en-cache-public', $ok, $obs );

/* 6 — l'epreuve centrale : le proprietaire garde SA page apres un passage visiteur. */
$lire( $base . $chemin_fic, $p_visiteur );              // le visiteur met la fiche en cache
list( , $b6 ) = $lire( $base . $chemin_fic, $p_owner ); // le proprietaire relit
$connecte6    = false !== strpos( $b6, '>Mon espace<' );
$t(
	'ISO-06-proprietaire-garde-sa-page-apres-un-visiteur',
	$connecte6,
	array( 'page_connectee' => $connecte6 )
);

$fail = array_values( array_filter( $res, static fn( $r ) => 'FAIL' === $r['status'] ) );
echo json_encode(
	array(
		'suite'   => 'litespeed-cache-isolation',
		'date'    => gmdate( 'c' ),
		'verdict' => $fail ? 'FAIL' : 'PASS',
		'passe'   => count( $res ) - count( $fail ),
		'total'   => count( $res ),
		'tests'   => $res,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
), "\n";
exit( $fail ? 1 : 0 );
