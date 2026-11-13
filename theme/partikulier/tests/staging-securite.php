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
 * qui en dépendent sont marqués SAUTÉS. Le POST HMAC valide crée volontairement
 * un événement synthétique unique (traitement désactivé) et une ligne d'audit;
 * son identifiant est affiché pour permettre la corrélation, jamais le secret.
 *
 * Sécurité : CLI uniquement (403 si appelé par HTTP, ne fait rien).
 */

if ( PHP_SAPI !== 'cli' ) {
        http_response_code( 403 );
        exit( 'CLI uniquement : ce fichier ne fait rien quand on l’appelle par HTTP.' );
}

$base   = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : '';
$secret = getenv( 'PK_SECRET' ) ?: ( $argv[2] ?? '' );
$key_id = getenv( 'PK_KEY_ID' ) ?: 'N';
if ( ! preg_match( '/^[A-Za-z0-9_-]{1,64}$/', $key_id ) ) {
        fwrite( STDERR, "PK_KEY_ID invalide\n" );
        exit( 64 );
}

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
function cle_hmac( string $secret ): string {
        $secret = trim( $secret );
        $decoded = base64_decode( $secret, true );
        if ( is_string( $decoded ) && strlen( $decoded ) >= 32 ) { return $decoded; }
        if ( preg_match( '/^[a-f0-9]{64,}$/i', $secret ) ) {
                $hex = hex2bin( substr( $secret, 0, strlen( $secret ) - ( strlen( $secret ) % 2 ) ) );
                if ( is_string( $hex ) && strlen( $hex ) >= 32 ) { return $hex; }
        }
        return $secret;
}

function signer( string $secret, string $route, int $ts, string $body ): string {
        return 'sha256=' . hash_hmac( 'sha256', "POST\n$route\n$ts\n$body", cle_hmac( $secret ) );
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
$event_id  = 'n8n-staging-securite-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
$corps     = json_encode([
        'event_id' => $event_id,
        'event_type' => 'whatsapp_status',
        'source' => 'n8n',
        'payload' => ['status' => 'read'],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ( ! is_string($corps) ) { fwrite(STDERR, "Impossible d'encoder le corps JSON du test HMAC\n"); exit(2); }

echo "\n== SÉCURITÉ — $base ==\n";
echo "Les tests HMAC utilisent " . ( $secret ? "le secret fourni (il reste sur cette machine)" : "AUCUN secret (4 tests seront sautés)" ) . "\n";
if ( $secret ) { echo "Événement synthétique unique (traitement désactivé, ligne d'audit conservée) : $event_id\n"; }
echo "\n";

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
                "X-Partikulier-Key-Id: $key_id",
                "X-Partikulier-Signature: $sig",
        ), $corps );
        v( $r['code'] === 401, "POST avec HMAC VALIDE mais ts = −400 s (replay) : HTTP {$r['code']} (attendu 401)" );

        $ts = time();
        $sig = signer( $secret, $route, $ts, $corps );
        $r = httpq( $base . $url_route, 'POST', array(
                'Content-Type: application/json',
                "X-Partikulier-Automation: $secret",
                "X-Partikulier-Timestamp: $ts",
                "X-Partikulier-Key-Id: $key_id",
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
$redirect = entete( $r, 'Location' );
$redirect_parts = is_string( $redirect ) ? parse_url( $redirect ) : false;
$base_host = strtolower( (string) parse_url( $base, PHP_URL_HOST ) );
$redirect_host = is_array( $redirect_parts ) ? strtolower( (string) ( $redirect_parts['host'] ?? '' ) ) : '';
$redirect_scheme = is_array( $redirect_parts ) ? strtolower( (string) ( $redirect_parts['scheme'] ?? '' ) ) : '';
$redirect_same_origin = is_array( $redirect_parts ) && (
        ( '' === $redirect_host && '' === $redirect_scheme )
        || ( in_array( $redirect_scheme, array( 'http', 'https' ), true ) && $redirect_host === $base_host )
);
$diag_status_ok = 200 === $r['code'] || ( in_array( $r['code'], array( 301, 302, 303, 307, 308 ), true ) && $redirect_same_origin );
v( $diag_status_ok && ! $fuite, "GET /?pk_diag=1 anonyme : HTTP {$r['code']}, page normale ou redirection même origine sans message de la sonde" );

$r = httpq( $base . '/wp-content/themes/partikulier/pk-diagnostic.php' );
$gere = ( $r['code'] === 200 && trim( $r['corps'] ) === '' ) || in_array( $r['code'], array( 403, 404 ), true ) || stripos( $r['corps'], '403 - diagnostic reserve' ) !== false;
v( $gere, "accès direct au fichier sonde : HTTP {$r['code']}, aucune donnée administrative divulguée" );

// --- Garde CLI des outils de test -------------------------------------------
echo "\n-- Outils de test : exécution par HTTP refusée --\n";
foreach ( array( 'staging-charge.php', 'staging-cache.php', 'staging-securite.php' ) as $outil ) {
        $r = httpq( $base . '/wp-content/themes/partikulier/tests/' . $outil );
        $ecarte = in_array( $r['code'], array( 403, 404 ), true ) || ( $r['code'] === 200 && strpos( $r['corps'], 'CLI uniquement' ) !== false );
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

// --- Santé du plugin Core (lecture seule, 2 passages anti-cache) ---------------
echo "\n-- Plugin Core (lecture seule, non-cacheable 2 passes) --\n";
$health_passes = array();
for ( $pass = 1; $pass <= 2; $pass++ ) {
        $r = httpq( $base . '/wp-json/partikulier/v1/health' );
        if ( 0 === (int) $r['code'] ) {
                $r = httpq( $base . '/wp-json/partikulier/v1/health' );
        }
        $d    = json_decode( $r['corps'], true );
        $cc   = (string) ( entete( $r, 'Cache-Control' ) ?? '' );
        $lsc  = (string) ( entete( $r, 'X-LiteSpeed-Cache' ) ?? '' );
        $hcdn = (string) ( entete( $r, 'X-HCDN-Cache-Status' ) ?? '' );
        $ok_body = $r['code'] === 200 && is_array( $d ) && ( $d['status'] ?? '' ) === 'ok' && ( $d['database'] ?? '' ) === 'ready';
        v( $ok_body, "health passe $pass : HTTP {$r['code']} · status=" . ( $d['status'] ?? '?' ) . ' · BDD=' . ( $d['database'] ?? '?' ) );
        v( stripos( $cc, 'no-store' ) !== false, "health passe $pass Cache-Control contient no-store ($cc)" );
        v( stripos( $lsc, 'hit' ) === false, "health passe $pass X-LiteSpeed-Cache non-HIT (" . ( $lsc !== '' ? $lsc : 'absent' ) . ')' );
        v( stripos( $hcdn, 'hit' ) === false, "health passe $pass X-HCDN-Cache-Status non-HIT (" . ( $hcdn !== '' ? $hcdn : 'absent' ) . ')' );
        $health_passes[] = $r;
}

echo "\n-- RÉSULTAT : $OK OK · $KO KO · $ATT à regarder · $SAUT sauté(s) --\n";
exit( $KO ? 1 : 0 );
