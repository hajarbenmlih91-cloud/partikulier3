<?php
/**
 * staging-cache.php — Contrat de cache et TTFB, mesurés depuis l'extérieur.
 *
 * Vérifie le LOT 3 du CDC sur un VRAI Hostinger :
 *   1. GET /annonces/ deux fois → le 2ᵉ doit être HIT (x-litespeed-cache: hit)
 *      ET contenir les mêmes cartes (un HIT sur un catalogue VIDE est le bug
 *      6.17.24 : on vérifie donc les cartes, pas seulement l'en-tête).
 *   2. Une fiche détail : HTTP 200, og:image absolue, 2ᵉ GET = HIT.
 *   3. Médiane/p95 TTFB sur 20 GET (cible CDC : < 800 ms extérieur ;
 *      le < 200 ms « origine » se mesure sur le serveur lui-même).
 *
 * Usage :
 *   php staging-cache.php https://staging.example.com
 *   php staging-cache.php https://staging.example.com /annonces/ 20
 *
 * Sécurité : CLI uniquement (403 si appelé par HTTP, ne fait rien).
 * Lecture seule : aucune écriture, aucun purge (la purge se fait dans hPanel).
 */

if ( PHP_SAPI !== 'cli' ) {
        http_response_code( 403 );
        exit( 'CLI uniquement : ce fichier ne fait rien quand on l’appelle par HTTP.' );
}

$base  = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : '';
$chemin = isset( $argv[2] ) ? $argv[2] : '/annonces/';
$n     = isset( $argv[3] ) ? max( 5, min( 60, (int) $argv[3] ) ) : 20;

if ( ! preg_match( '#^https?://[a-z0-9.-]+#i', $base ) ) {
        fwrite( STDERR, "Usage : php staging-cache.php <base-url> [chemin=/annonces/] [n=20]\n" );
        exit( 64 );
}

/**
 * Une requête GET : renvoie code, en-têtes intéressants, corps, temps.
 */
function get_http( string $url ): array {
        $ch = curl_init( $url );
        $entetes = array();
        curl_setopt_array( $ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HEADERFUNCTION  => function ( $ch, $ligne ) use ( &$entetes ) {
                        $t = trim( $ligne );
                        if ( strpos( $t, ':' ) !== false ) { $entetes[] = $t; }
                        return strlen( $ligne );
                },
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => 'Partikulier-Staging-Test/1.0 (+contrat cache)',
                CURLOPT_SSL_VERIFYPEER => true,
        ) );
        $corps  = curl_exec( $ch );
        $rep = array(
                'code'  => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ),
                'entetes' => $entetes,
                'corps' => is_string( $corps ) ? $corps : '',
                'total' => curl_getinfo( $ch, CURLINFO_TOTAL_TIME ) * 1000,
                'ttfb'  => curl_getinfo( $ch, CURLINFO_STARTTRANSFER_TIME ) * 1000,
                'errno' => curl_errno( $ch ),
        );
        curl_close( $ch );
        return $rep;
}

function entete( array $rep, string $nom ): ?string {
        $nom = strtolower( $nom );
        foreach ( $rep['entetes'] as $l ) {
                $p = strpos( $l, ':' );
                if ( $p !== false && strtolower( substr( $l, 0, $p ) ) === $nom ) {
                        return trim( substr( $l, $p + 1 ) );
                }
        }
        return null;
}

function cartes( string $html ): int {
        return preg_match_all( '#class="pk-card-title"#', $html );
}

function fmt( float $ms ): string { return number_format( $ms, 0, ',', ' ' ) . ' ms'; }

$OK = 0; $KO = 0; $ATT = 0; // OK / KO / attention
function v( bool $ok, string $msg, bool $att = false ) {
        global $OK, $KO, $ATT;
        if ( $ok ) { $OK++; echo "  OK   $msg\n"; }
        elseif ( $att ) { $ATT++; echo "  ATT  $msg\n"; }
        else { $KO++; echo "  KO   $msg\n"; }
}

echo "\n== CONTRAT DE CACHE — $base$chemin ==\n\n";

// --- 1) MISS -> HIT + cartes présentes dans le HIT -------------------------
$url = $base . $chemin;
$g1 = get_http( $url );
$g2 = get_http( $url );

$c1 = entete( $g1, 'x-litespeed-cache' );
$c2 = entete( $g2, 'x-litespeed-cache' );
// LiteSpeed observable quelque part ? (sinon : hébergement sans LSCache visible — on ne peut
// pas exiger un HIT serveur qu'on ne peut pas observer ; les autres contrôles restent actifs)
$ls_observable = ( $c1 !== null || $c2 !== null );
$k1 = entete( $g1, 'x-partikulier-cache' );
$k2 = entete( $g2, 'x-partikulier-cache' );
$n1 = cartes( $g1['corps'] );
$n2 = cartes( $g2['corps'] );

v( $g1['code'] === 200, "1er GET : HTTP {$g1['code']} en " . fmt( $g1['total'] ) );
if ( $ls_observable ) {
        v( $g2['code'] === 200 && ( stripos( (string) $c2, 'hit' ) !== false ), "2ᵉ GET : x-litespeed-cache = " . ( $c2 ?? 'absent' ) . " (l'ancien HEAD disait : " . ( $c1 ?? 'absent' ) . ")" );
        if ( stripos( (string) $c1, 'hit' ) !== false && stripos( (string) $c2, 'hit' ) !== false ) {
                echo "  ATT  le 1er GET était déjà HIT (cache chaud) : purger d'abord dans hPanel si tu veux observer le MISS.\n";
                $ATT++;
        }
} else {
        v( false, "en-tête x-litespeed-cache absent des deux GET — cache serveur LiteSpeed non observable (HCDN devant ? purge hPanel puis relancer)", true );
}
v( $n1 > 0, "1er GET : $n1 carte(s) annonce(s) dans le HTML" );
v( $n2 > 0 && $n2 === $n1, "2ᵉ GET (HIT) : $n2 cartes — pas le catalogue vide du bug 6.17.24" );
if ( $k1 !== null || $k2 !== null ) {
        v( stripos( (string) $k2, 'hit' ) !== false, "cache thème : x-partikulier-cache = " . ( $k2 ?? 'absent' ) . " au 2ᵉ GET" );
}

// --- 2) Une fiche détail ----------------------------------------------------
preg_match( '#href="(https?://[^"]+/annonce/[^"]+)"#', $g2['corps'], $m );
if ( $m ) {
        $fiche = html_entity_decode( $m[1] );
        $f1 = get_http( $fiche );
        $f2 = get_http( $fiche );
        echo "\n-- Fiche : " . substr( $fiche, strlen( $base ) ) . " --\n";
        v( $f1['code'] === 200, "1er GET fiche : HTTP {$f1['code']} en " . fmt( $f1['total'] ) );
        v( (bool) preg_match( '#property="og:image"\s+content="https?://#', $f1['corps'] ), 'og:image présente et absolue' );
        $cf = entete( $f2, 'x-litespeed-cache' );
        if ( $ls_observable ) {
                v( $cf !== null && stripos( (string) $cf, 'hit' ) !== false, "2ᵉ GET fiche : x-litespeed-cache = " . ( $cf ?? 'absent' ) );
        } else {
                v( false, "2ᵉ GET fiche : x-litespeed-cache non observable sur cet hébergement (OK en sandbox ; sur Hostinger : HIT attendu)", true );
        }
} else {
        echo "\n  ATT  aucune carte dans le 2ᵉ GET : test fiche sauté (lancer le seeder / vérifier LOT 0 d'abord)\n";
        $ATT++;
}

// --- 3) TTFB en série -------------------------------------------------------
echo "\n-- TTFB sur $n GET consécutifs --\n";
$ttfbs = array();
for ( $i = 0; $i < $n; $i++ ) {
        $g = get_http( $url );
        if ( $g['errno'] === 0 && $g['ttfb'] > 0 ) { $ttfbs[] = $g['ttfb']; }
}
sort( $ttfbs );
if ( $ttfbs ) {
        $p50 = $ttfbs[ (int) floor( 0.50 * count( $ttfbs ) ) ];
        $p95 = $ttfbs[ (int) floor( 0.95 * count( $ttfbs ) ) ];
        printf( "  p50 %s · p95 %s (n=%d, réseau inclus, mesuré depuis CETTE machine)\n", fmt( $p50 ), fmt( $p95 ), count( $ttfbs ) );
        v( $p50 <= 800, "médiane TTFB ≤ 800 ms (contrat extérieur CDC LOT 3) : " . fmt( $p50 ) );
        echo "  ℹ la cible < 200 ms du CDC est la mesure ORIGINE (sur le serveur) : en SSH, curl -w '%{time_starttransfer}' -o /dev/null " . $url . "\n";
} else {
        v( false, "aucun TTFB mesurable (requêtes en échec ?)", true );
}

echo "\n-- RÉSULTAT : $OK OK · $KO KO · $ATT à regarder --\n";
exit( $KO ? 1 : 0 );
