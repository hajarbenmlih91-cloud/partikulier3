<?php
/**
 * staging-bornes.php — vérifications live bornées du rate-limit HMAC et du corps maximal.
 *
 * CLI uniquement. Cible restreinte à l'hôte UAT. Aucun secret n'est envoyé.
 * Le test rate-limit envoie au plus 22 petits POST non authentifiés avec des
 * X-Forwarded-For/X-Real-IP de documentation différents; il attend le 429 WordPress
 * pk_rate_limited après les 20 erreurs autorisées. Le test payload envoie une seule
 * requête de 1 MiB + marge et attend HTTP 413. Aucun événement métier valide n'est soumis.
 */

if ( PHP_SAPI !== 'cli' ) {
    http_response_code( 403 );
    exit( 'CLI uniquement.' );
}

$base = isset( $argv[1] ) ? rtrim( $argv[1], '/' ) : '';
$parts = is_string( $base ) ? parse_url( $base ) : false;
if ( ! is_array( $parts ) || 'https' !== strtolower( (string) ( $parts['scheme'] ?? '' ) )
    || 'darkcyan-yak-151002.hostingersite.com' !== strtolower( (string) ( $parts['host'] ?? '' ) )
    || isset( $parts['user'] ) || isset( $parts['pass'] ) || isset( $parts['port'] ) ) {
    fwrite( STDERR, "Usage : php staging-bornes.php https://darkcyan-yak-151002.hostingersite.com\n" );
    exit( 64 );
}

function pk_probe( string $url, string $body, array $headers ): array {
    $responseHeaders = array();
    $handle = curl_init( $url );
    curl_setopt_array( $handle, array(
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_HEADERFUNCTION => static function ( $handle, $line ) use ( &$responseHeaders ) {
            $header = trim( $line );
            if ( false !== strpos( $header, ':' ) ) { $responseHeaders[] = $header; }
            return strlen( $line );
        },
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_ENCODING => '',
        CURLOPT_USERAGENT => 'Partikulier-UAT-Bounds-Test/1.0 (bounded, unauthenticated)',
    ) );
    $bodyOut = curl_exec( $handle );
    $result = array(
        'status' => (int) curl_getinfo( $handle, CURLINFO_RESPONSE_CODE ),
        'body' => is_string( $bodyOut ) ? $bodyOut : '',
        'headers' => $responseHeaders,
        'errno' => curl_errno( $handle ),
    );
    curl_close( $handle );
    return $result;
}

function pk_header( array $response, string $name ): ?string {
    foreach ( $response['headers'] as $header ) {
        $separator = strpos( $header, ':' );
        if ( false !== $separator && 0 === strcasecmp( trim( substr( $header, 0, $separator ) ), $name ) ) {
            return trim( substr( $header, $separator + 1 ) );
        }
    }
    return null;
}

function pk_error_code( array $response ): string {
    $json = json_decode( $response['body'], true );
    return is_array( $json ) && isset( $json['code'] ) && is_string( $json['code'] ) ? $json['code'] : '';
}

$endpoint = $base . '/wp-json/partikulier/v1/automation-event';
$body = '{"event_id":"n8n-uat-rate-probe","event_type":"whatsapp_status","source":"n8n","payload":{"status":"read"}}';
$got401 = false;
$limited = false;
$attempts = 0;
$retryAfter = null;
$limitCode = '';
for ( $i = 1; $i <= 22; $i++ ) {
    $attempts++;
    // TEST-NET-2/3 addresses are reserved for documentation, never real clients.
    $forwarded = ( $i <= 11 ? '198.51.100.' . $i : '203.0.113.' . ( $i - 11 ) );
    $response = pk_probe( $endpoint, $body, array(
        'Content-Type: application/json',
        'X-Forwarded-For: ' . $forwarded,
        'X-Real-IP: ' . $forwarded,
    ) );
    if ( 401 === $response['status'] ) { $got401 = true; }
    if ( 429 === $response['status'] ) {
        $limitCode = pk_error_code( $response );
        $retryAfter = pk_header( $response, 'Retry-After' );
        if ( 'pk_rate_limited' === $limitCode ) { $limited = true; }
        break;
    }
    if ( 401 !== $response['status'] ) {
        fwrite( STDERR, "FAIL rate-limit: tentative $i, HTTP {$response['status']}, code=" . pk_error_code( $response ) . "\n" );
        exit( 1 );
    }
}
$retryAfterValid = is_string( $retryAfter ) && ctype_digit( $retryAfter ) && (int) $retryAfter > 0;
if ( ! $got401 || ! $limited || ! $retryAfterValid ) {
    fwrite( STDERR, "FAIL rate-limit: $attempts tentative(s), 401 initial=" . ( $got401 ? 'oui' : 'non' )
        . ", 429 pk_rate_limited=" . ( $limited ? 'oui' : 'non' ) . ", Retry-After valide=" . ( $retryAfterValid ? 'oui' : 'non' ) . "\n" );
    exit( 1 );
}
echo "PASS rate-limit HMAC : $attempts POST au plus; 401 puis HTTP 429 pk_rate_limited malgré des X-Forwarded-For/X-Real-IP forgés; Retry-After=$retryAfter\n";

// The runtime default is 1 MiB; this body is deliberately just over that limit.
$paddingBytes = 1048576;
$largeBody = '{"event_id":"n8n-uat-payload-probe","event_type":"whatsapp_status","source":"n8n","payload":{"padding":"'
    . str_repeat( 'x', $paddingBytes ) . '"}}';
$payloadResponse = pk_probe( $endpoint, $largeBody, array( 'Content-Type: application/json' ) );
$payloadCode = pk_error_code( $payloadResponse );
if ( 413 !== $payloadResponse['status'] ) {
    fwrite( STDERR, 'FAIL payload > 1 MiB: HTTP ' . $payloadResponse['status'] . '; réponse non limitée à 413' . "\n" );
    exit( 1 );
}
$enforcer = 'pk_automation_body_too_large' === $payloadCode ? 'application' : 'edge/server (origine non démontrée)';
echo 'PASS payload > 1 MiB : HTTP 413; code=' . ( $payloadCode ?: 'non-JSON/non-WordPress' )
    . '; limiteur observé=' . $enforcer . '; octets=' . strlen( $largeBody ) . "\n";
