<?php
/**
 * staging-securite.php — Batterie de vérifications sécurité d'un staging réel.
 *
 * Ce qu'un dev senior vérifie AVANT de mettre en prod :
 *   - LOT 2 : la route automation n8n refuse POST sans signature (401),
 *     refuse le secret seul (401), refuse un replay > 300 s (401),
 *     accepte un HMAC valide (200 : le métier fonctionne).
 *   - LOT 2 : la sonde pk-diagnostic est absente du front anonyme.
 *   - Les outils de test du thème refusent l'exécution par HTTP (garde CLI).
 *   - Pas d'énumération d'utilisateurs par REST, pas de fuite wp-config,
 *     pas de /.env ni debug.log accessibles, en-têtes de sécurité en place.
 *
 * Usage :
 *   php staging-securite.php https://staging.example.com
 *   PK_SECRET='<secret base64>' php staging-securite.php https://staging.example.com
 *   php staging-securite.php https://staging.example.com '<secret base64>'
 *
 * Le secret ne quitte pas cette machine : il sert uniquement à prouver que la
 * garde HMAC fonctionne (401 sans signature, 200 avec). Sans secret, les tests
 * qui en dépendent sont marqués SAUTÉS.
 *
 * Sécurité : CLI uniquement (403 si appelé par HTTP, ne fait rien).
 */

if ( PHP_SAPI !== 'cli' ) {
        http_response_code( 403 );
        exit( 'CLI uniquement : ce fichier ne fait rien quand on l’appelle par HTTP.' );
}

$base   = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : '';
$secret = getenv( 'PK_SECRET' ) ?: ( $argv[2] ?? '' );

if ( ! preg_match( '#^https?://[a-z0-9.-]+#i', $base ) ) {
        fwrite( STDERR, "Usage : php staging-securite.php <base-url> [secret-base64] (ou variable PK_SECRET)\n" );
        exit( 64 );
}

/**
 * Requête générique : GET ou POST, renvoie code/corps/entêtes.
 */
function httpq( string $url, string $method = 'GET', array $entetes = array(), string $body = null ): array {
        $ch = curl_init( $url );
        $h  = array();
        curl_setopt_array( $ch, array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_HEADERFUNCTION => function ( $ch, $ligne ) use ( &$h ) {
                        $t = trim( $ligne );
                        if ( strpos( $t, ':' ) !== false ) { $h[] = $t; }
                        return strlen( $ligne );
                },
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT        => 20,
                CURLOPT_ENCODING       => '',
                CURLOPT_USERAGENT      => 'Partikulier-Staging-Test/1.0 (+audit sécurité, lecture seule)',
                CURLOPT_SSL_VERIFYPEER => true,
        ) );
        if ( $entetes ) { curl_setopt( $ch, CURLOPT_HTTPHEADER, $entetes ); }
        if ( $body !== null ) { curl_setopt( $ch, CURLOPT_POSTFIELDS, $body ); }
        $corps = curl_exec( $ch );
        return array(
                'code'    => (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE ),
                'corps'   => is_string( $corps ) ? $corps : '',
                'entetes' => $h,
                'errno'   => curl_errno( $ch ),
        );
}

function entete( array $rep, string $nom ): ?string {
        $nom = strtolower( $nom );
        foreach ( $rep['entetes'] as $l ) {
                $p = strpos( $l, ':' );
                if ( $p !== false && strtolower( substr( $l, 0, $p ) ) === $nom ) { return trim( substr( $l, $p + 1 ) ); }
        }
        return null;
}

/**
 * Signature HMAC exacte du thème (cf. class-n8n-security.php) :
 * clef = base64_decode(secret) · canonical = "POST\n<route REST>\n<ts>\n<corps>"
 * (route REST relative SANS le préfixe /wp-json).
 */
function signer( string $secret, string $route, int $ts, string $body ): string {
        $cle = base64_decode( trim( $secret ), true );
        return 'sha256=' . hash_hmac( 'sha256', "POST\n$route\n$ts\n$body", $cle );
}

$OK = 0; $KO = 0; $ATT = 0; $SAUT = 0;
function v( ?bool $ok, string $msg ) {
        global $OK, $KO, $ATT, $SAUT;
        if ( $ok === null ) { $SAUT++; echo "  SAUTÉ $msg\n"; }
        elseif ( $ok ) { $OK++; echo "  OK    $msg\n"; }
        else { $KO++; echo "  KO    $msg\n"; }
}

$url_route = '/wp-json/partikulier/v1/automation-event';   // pour les requêtes HTTP
$route     = '/partikulier/v1/automation-event';            // pour la signature (canonical)
$corps   = '{"event_id":"n8n-staging-securite-001","event_type":"whatsapp_status","source":"n8n","payload":{"status":"read"}}';

echo "\n== SÉCURITÉ — $base ==\n";
echo "Les tests HMAC utilisent " . ( $secret ? "le secret fourni (il reste sur cette machine)" : "AUCUN secret (4 tests seront sautés)" ) . "\n\n";

// --- LOT 2 : HMAC sur la route automation ----------------------------------
echo "-- LOT 2 · HMAC de la route automation --\n";
$r = httpq( $base . $url_route, 'POST', array( 'Content-Type: application/json' ), $corps );
v( $r['code'] === 401, "POST sans rien : HTTP {$r['code']} (attendu 401)" );

$ok_secret = null;
if ( $secret ) {
        $r = httpq( $base . $url_route, 'POST', array( 'Content-Type: application/json', "X-Partikulier-Automation: $secret" ), $corps );
        v( $r['code'] === 401, "POST avec le SECRET SEUL (pas de signature) : HTTP {$r['code']} (attendu 401 — c'était le trou démontré en 6.17.26)" );

        $ts_rejoue = time() - 400;
        $sig = signer( $secret, $route, $ts_rejoue, $corps );
        $r = httpq( $base . $url_route, 'POST', array(
                'Content-Type: application/json',
                "X-Partikulier-Automation: $secret",
                "X-Partikulier-Timestamp: $ts_rejoue",
                "X-Partikulier-Key-Id: N",
                "X-Partikulier-Signature: $sig",
        ), $corps );
        v( $r['code'] === 401, "POST avec HMAC VALIDE mais ts = −400 s (replay) : HTTP {$r['code']} (attendu 401)" );

        $ts = time();
        $sig = signer( $secret, $route, $ts, $corps );
        $r = httpq( $base . $url_route, 'POST', array(
                'Content-Type: application/json',
                "X-Partikulier-Automation: $secret",
                "X-Partikulier-Timestamp: $ts",
                "X-Partikulier-Key-Id: N",
                "X-Partikulier-Signature: $sig",
        ), $corps );
        v( $r['code'] === 200, "POST avec secret + HMAC valide : HTTP {$r['code']} (attendu 200 = métier inchangé)" );
} else {
        v( null, "tests secret-seul / replay / HMAC-valide : fournissez PK_SECRET pour les activer" );
}

// --- LOT 2 : isolation de la sonde ------------------------------------------
echo "\n-- LOT 2 · sonde absente du front anonyme --\n";
$r = httpq( $base . '/?pk_diag=1' );
$fuite = strpos( $r['corps'], 'diagnostic reserve aux administrateurs' ) !== false;
v( $r['code'] === 200 && ! $fuite, "GET /?pk_diag=1 anonyme : HTTP {$r['code']}, page normale sans message de la sonde" );

$r = httpq( $base . '/wp-content/themes/partikulier/pk-diagnostic.php' );
$gere = ( $r['code'] === 200 && trim( $r['corps'] ) === '' ) || $r['code'] === 403 || stripos( $r['corps'], '403 - diagnostic reserve' ) !== false;
v( $gere, "accès direct au fichier sonde : HTTP {$r['code']}, aucune donnée administrative divulguée" );

// --- Garde CLI des outils de test -------------------------------------------
echo "\n-- Outils de test : exécution par HTTP refusée --\n";
foreach ( array( 'staging-charge.php', 'staging-cache.php', 'staging-securite.php' ) as $outil ) {
        $r = httpq( $base . '/wp-content/themes/partikulier/tests/' . $outil );
        $ecarte = $r['code'] === 403 || ( $r['code'] === 200 && strpos( $r['corps'], 'CLI uniquement' ) !== false );
        v( $ecarte, "tests/$outil appelé par HTTP : HTTP {$r['code']}, refuse et ne fait rien (anti auto-DDoS)" );
}

// --- Portes classiques -------------------------------------------------------
echo "\n-- Portes classiques (lecture seule) --\n";
$r = httpq( $base . '/wp-content/themes/partikulier/tests/seed-annonces-test.php' );
$execute = ( $r['code'] === 200 && ( strpos( $r['corps'], 'SIMULATION' ) !== false || strpos( $r['corps'], 'appliquer' ) !== false ) );
v( ! $execute && in_array( $r['code'], array( 200, 403, 404 ), true ), "seeder appelé par HTTP anonyme : HTTP {$r['code']}, pas d'exécution (garde admin)" );

$r = httpq( $base . '/wp-json/wp/v2/users' );
$users = json_decode( $r['corps'], true );
// Une VRAIE fuite = une LISTE d'objets utilisateurs (clé slug).
// Un objet d'erreur REST (rest_no_route, rest_forbidden…) n'est pas une liste :
// sur ce thème la route est d'ailleurs retirée par le mu-plugin rest-lite (404 = bien).
$fuite_users = is_array( $users ) && array_values( $users ) === $users && isset( $users[0]['slug'] );
v( ! $fuite_users, "REST /wp/v2/users : HTTP {$r['code']} — " . ( $fuite_users ? count( $users ) . ' utilisateur(s) divulgé(s) !' : 'aucune énumération' ) );

$r = httpq( $base . '/wp-config.php' );
v( strpos( $r['corps'], 'DB_PASSWORD' ) === false && strpos( $r['corps'], 'AUTH_KEY' ) === false, 'wp-config.php : aucun secret divulgué dans la réponse' );

foreach ( array( '/.env', '/wp-content/debug.log' ) as $f ) {
        $r = httpq( $base . $f );
        v( in_array( $r['code'], array( 403, 404 ), true ), "$f : HTTP {$r['code']} (attendu 403/404)" );
}

$r = httpq( $base . '/xmlrpc.php', 'POST', array( 'Content-Type: text/xml' ), '<methodCall><methodName>system.listMethods</methodName></methodCall>' );
$att = in_array( $r['code'], array( 403, 405 ), true );
if ( $att ) { $OK++; echo "  OK    xmlrpc.php : HTTP {$r['code']} (bloqué)\n"; }
else { $ATT++; echo "  ATT   xmlrpc.php : HTTP {$r['code']} (répond) — si tu ne l'utilises pas : le couper (hPanel ou réglages écriture XML-RPC)\n"; }

// --- En-têtes de sécurité ----------------------------------------------------
echo "\n-- En-têtes de sécurité sur la page d'accueil --\n";
$r = httpq( $base . '/' );
$attendus = array(
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options'        => 'SAMEORIGIN',
        'Referrer-Policy'        => null,
        'Permissions-Policy'     => null,
        'Content-Security-Policy' => null,
);
foreach ( $attendus as $nom => $val ) {
        $vu = entete( $r, $nom );
        if ( $vu === null ) { $ATT++; echo "  ATT   $nom : ABSENT (souvent fourni par LSCache/le thème — vérifier après purge)\n"; }
        elseif ( $val !== null && stripos( $vu, $val ) === false ) { $ATT++; echo "  ATT   $nom = $vu (valeur inattendue)\n"; }
        else { $OK++; echo "  OK    $nom : présent\n"; }
}
$powered = entete( $r, 'X-Powered-By' );
v( $powered === null, 'X-Powered-By absent (pas de version PHP annoncée aux robots)' );

// --- Santé du plugin Core ----------------------------------------------------
echo "\n-- Plugin Core (lecture seule) --\n";
$r = httpq( $base . '/wp-json/partikulier/v1/health' );
$d = json_decode( $r['corps'], true );
v( $r['code'] === 200 && is_array( $d ) && ( $d['status'] ?? '' ) === 'ok', 'health : HTTP ' . $r['code'] . ' · core ' . ( $d['core_version'] ?? '?' ) . ' · schéma ' . ( $d['schema_version'] ?? '?' ) . ' · BDD ' . ( $d['database'] ?? '?' ) );

echo "\n-- RÉSULTAT : $OK OK · $KO KO · $ATT à regarder · $SAUT sauté(s) --\n";
exit( $KO ? 1 : 0 );
