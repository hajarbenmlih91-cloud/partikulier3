<?php
/**
 * staging-charge.php — Test de charge HTTP (open-loop, sans dépendance).
 *
 * Répond à la question du chef : « si j'envoie 100 requêtes par seconde,
 * est-ce que le site chez Hostinger tombe ou pas ? »
 *
 * Principe (méthode senior) : open-loop = on TIRE au rythme demandé quoi qu'il
 * arrive. Si le serveur ralentit, les requêtes s'empilent, la latence explose,
 * les erreurs apparaissent : c'est exactement ce qu'on veut OBSERVER (un test
 * en boucle fermée cacherait la saturation). Connexions HTTP réutilisées pour
 * ne pas fausser la latence avec 100 handshakes TLS par seconde.
 *
 * Usage (depuis ta machine ou celle du dev, PHP CLI 7.4+ et curl) :
 *   php staging-charge.php https://staging.example.com            # 100 rps, 30 s
 *   php staging-charge.php https://staging.example.com 50 20      # 50 rps, 20 s
 *   php staging-charge.php https://staging.example.com 100 30 /annonces/ /
 *
 * Règles d'or (voir LISEZMOI-STAGING.md) :
 *   1. Commencer par : php staging-charge.php <url> 10 10   (sondage)
 *   2. Une seule session de test à la fois.
 *   3. Hors heures de pointe si c'est un site public.
 *
 * Sécurité : CLI uniquement — appelé par HTTP le fichier refuse (403) et ne
 * fait RIEN (testable : curl https://…/wp-content/themes/partikulier/tests/staging-charge.php).
 * Plafonds internes : 200 rps, 120 s, 20 s par requête.
 */

if ( PHP_SAPI !== 'cli' ) {
        http_response_code( 403 );
        exit( 'CLI uniquement : ce fichier ne fait rien quand on l’appelle par HTTP.' );
}

// ---------------------------------------------------------------------------
// Arguments et plafonds de sécurité
// ---------------------------------------------------------------------------
$base   = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : '';
$rps    = isset( $argv[2] ) ? max( 1, (int) $argv[2] ) : 100;
$duree  = isset( $argv[3] ) ? max( 1, (int) $argv[3] ) : 30;
$paths  = array_slice( $argv, 4 );
if ( ! $paths ) {
        $paths = array( '/annonces/', '/', '/annonces/?es_city=casablanca-fr' );
}

if ( ! preg_match( '#^https?://[a-z0-9.-]+#i', $base ) ) {
        fwrite( STDERR, "Usage : php staging-charge.php <base-url> [rps=100] [durée s=30] [chemins...]\n" );
        exit( 64 );
}

$rps   = min( $rps, 200 );
$duree = min( $duree, 120 );

$concurrence_max = min( 256, max( 8, (int) ceil( $rps * 5 ) ) );
$intervalle      = 1 / $rps;
$nb_prevu        = (int) floor( $rps * $duree );

printf(
        "\n== CHARGE %d req/s pendant %d s sur %s ==\nChemins : %s\nConnexions réutilisées · concurrence max %d · %d requêtes prévues\n\n",
        $rps, $duree, $base, implode( ', ', $paths ), $concurrence_max, $nb_prevu
);

// ---------------------------------------------------------------------------
// Open-loop : pool de handles curl réutilisés, cadence pilotée par l'horloge
// ---------------------------------------------------------------------------
$mh = curl_multi_init();

$libres = array(); // handles disponibles (ajoutés au multi UNIQUEMENT au tir)
$occupe = array(); // h => array( idx, t_prevu )
for ( $i = 0; $i < $concurrence_max; $i++ ) {
        $ch = curl_init();
        curl_setopt_array( $ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_ENCODING       => '',            // gzip/brotli comme un vrai navigateur
                CURLOPT_USERAGENT      => 'Partikulier-Staging-Test/1.0 (+test de charge contractuel)',
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_HTTPHEADER     => array( 'Accept: text/html,application/json' ),
        ) );
        $libres[ (int) $ch ] = $ch;
}

$tirées = 0; $saturées = 0;
$latences = array(); $ttfb = array();
$codes = array(); $err_curl = 0; $octets = 0;
$t0 = microtime( true );
$t_fin = $t0 + $duree;
$prochaine = $t0;

while ( true ) {
        $now = microtime( true );

        // Tirer dans la fenêtre de cadence tant qu'on a des handles libres.
        while ( $tirées < $nb_prevu && $now >= $prochaine && $libres ) {
                if ( $prochaine < $now - 5 ) {
                        // Cadence non tenue côté client (aucun handle libre depuis > 5 s) :
                        // discipline open-loop — on saute le créneau au lieu d'envoyer un burst.
                        $saturées++;
                        $prochaine += $intervalle;
                        continue;
                }
                $h   = array_key_first( $libres );
                $ch  = $libres[ $h ];
                unset( $libres[ $h ] );
                $url = $base . $paths[ $tirées % count( $paths ) ];
                curl_setopt( $ch, CURLOPT_URL, $url );
                curl_multi_add_handle( $mh, $ch );
                $occupe[ $h ] = $ch;
                $tirées++;
                $prochaine += $intervalle;
        }

        curl_multi_exec( $mh, $actif );

        while ( $info = curl_multi_info_read( $mh ) ) {
                $ch  = $info['handle'];
                $h   = (int) $ch;
                $errno = $info['result'];
                $code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
                $total = curl_getinfo( $ch, CURLINFO_TOTAL_TIME );
                $start = curl_getinfo( $ch, CURLINFO_STARTTRANSFER_TIME );
                $octets += (int) curl_getinfo( $ch, CURLINFO_SIZE_DOWNLOAD );
                if ( $errno !== 0 ) { $err_curl++; $codes['curl-err'] = ( $codes['curl-err'] ?? 0 ) + 1; }
                else {
                        $codes[ $code ] = ( $codes[ $code ] ?? 0 ) + 1;
                        $latences[] = $total * 1000;
                        if ( $start > 0 ) { $ttfb[] = $start * 1000; }
                }
                curl_multi_remove_handle( $mh, $ch );
                $libres[ $h ] = $ch;
                unset( $occupe[ $h ] );
        }

        if ( $tirées >= $nb_prevu && ! $occupe ) { break; }   // tout est rentré
        if ( $now > $t_fin + 25 ) { break; }                  // garde-fou drain
        usleep( 400 );
}

$ecoule = microtime( true ) - $t0;

// Nettoyage.
foreach ( $libres as $ch ) { curl_multi_remove_handle( $mh, $ch ); curl_close( $ch ); }
curl_multi_close( $mh );

// ---------------------------------------------------------------------------
// Statistiques et verdict
// ---------------------------------------------------------------------------
sort( $latences ); sort( $ttfb );
$recues = count( $latences );
function pct( array $v, float $p ): float {
        if ( ! $v ) { return 0.0; }
        $i = max( 0, min( count( $v ) - 1, (int) floor( $p / 100 * count( $v ) ) ) );
        return $v[ $i ];
}
function fmt( float $ms ): string { return number_format( $ms, 0, ',', ' ' ) . ' ms'; }

$n2xx = 0; $n3xx = 0; $n4xx = 0; $n5xx = 0;
foreach ( $codes as $c => $n ) {
        if ( $c === 'curl-err' ) { continue; }
        if ( $c >= 200 && $c < 300 ) { $n2xx += $n; }
        elseif ( $c < 400 ) { $n3xx += $n; }
        elseif ( $c < 500 ) { $n4xx += $n; }
        else { $n5xx += $n; }
}
$erreurs = $recues + $err_curl > 0 ? ( $n5xx + $err_curl ) / ( $recues + $err_curl ) : 1;

printf( "Requêtes tirées     : %d\n", $tirées );
printf( "  reçues (réponse)  : %d · 2xx=%d 3xx=%d 4xx=%d 5xx=%d erreurs réseau=%d\n", $recues, $n2xx, $n3xx, $n4xx, $n5xx, $err_curl );
printf( "  créneaux sautés   : %d (cadence non tenue côté client)\n", $saturées );
printf( "Débit réel          : %.1f req/s sur %.1f s\n", $recues / max( 0.001, $ecoule ), $ecoule );
printf( "Volume              : %.1f Mo téléchargés\n\n", $octets / 1048576 );

if ( $recues ) {
        printf( "Latence totale (réseau inclus) : p50 %s · p95 %s · p99 %s\n", fmt( pct( $latences, 50 ) ), fmt( pct( $latences, 95 ) ), fmt( pct( $latences, 99 ) ) );
        if ( $ttfb ) {
                printf( "TTFB observé depuis ICI        : p50 %s · p95 %s\n", fmt( pct( $ttfb, 50 ) ), fmt( pct( $ttfb, 95 ) ) );
        }
}
if ( $err_curl && isset( $codes['curl-err'] ) ) {
        echo "⚠ erreurs réseau/timeout : " . $err_curl . " requête(s) sans réponse (connexion refusée, timeout 20 s, TLS…)\n";
}
if ( $n4xx ) {
        echo "ℹ " . $n4xx . " réponse(s) 4xx : la protection anti-abus de l'hébergeur (ou un WAF) a pu réagir au débit — vérifier la cause exacte avant de conclure.\n";
}

echo "\n-- VERDICT --\n";
if ( ! $recues || $erreurs >= 0.01 || ( $recues + $err_curl ) < $tirées * 0.5 ) {
        echo "TOMBÉ : " . ( $n5xx + $err_curl ) . " requête(s) en échec sur " . ( $recues + $err_curl ) . " — le site ne tient pas ce rythme.\n";
        exit( 4 );
}
$p95 = pct( $latences, 95 );
if ( $p95 <= 800 ) {
        echo "TENU : 0 erreur notable et p95 ≤ 800 ms (cible extérieure du CDC) — le site encaisse $rps req/s.\n";
        exit( 0 );
}
echo "TENU-DÉGRADÉ : pas d'erreurs mais p95 = " . fmt( $p95 ) . " > 800 ms — le site répond encore, la latence s'envole (cache froid ? route non cachée ? à creuser).\n";
exit( 3 );
