<?php
/**
 * Ferme la recette. Pas la production.
 *
 * hostingersite.com seulement. 401 sans le mot de passe, y compris les robots.
 * Le fichier du mot de passe est hors de public_html. Le jour de l'ouverture,
 * le fichier pk-recette-ouverte, à côté, retire cette porte. Le bloc Auth du
 * .htaccess de recette doit partir le même jour.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * @return void
 */
function partikulier_recette_gate() {
	if ( ( defined( 'WP_CLI' ) && WP_CLI ) || PHP_SAPI === 'cli' ) {
		return;
	}
	if ( defined( 'DOING_CRON' ) && DOING_CRON ) {
		return;
	}
	$host = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( (string) $_SERVER['HTTP_HOST'] ) : '';
	if ( false === strpos( $host, 'hostingersite.com' ) ) {
		return;
	}
	$secret_dir = dirname( ABSPATH );
	if ( is_file( $secret_dir . '/pk-recette-ouverte' ) ) {
		return;
	}
	$user = 'tuk';
	$pass = '';
	if ( defined( 'PK_RECETTE_PASSWORD' ) && PK_RECETTE_PASSWORD ) {
		$pass = (string) PK_RECETTE_PASSWORD;
	} elseif ( is_readable( $secret_dir . '/pk-recette-gate.secret' ) ) {
		$pass = trim( (string) file_get_contents( $secret_dir . '/pk-recette-gate.secret' ) );
	}
	/* Pas de mot de passe posé : on ne ferme pas. Un déploiement ne doit pas
	 * bloquer le propriétaire sans issue. */
	if ( '' === $pass ) {
		return;
	}
	list( $given_user, $given_pass ) = partikulier_recette_given_credentials();
	if ( '' !== $pass && hash_equals( $user, $given_user ) && hash_equals( $pass, $given_pass ) ) {
		return;
	}
	header( 'WWW-Authenticate: Basic realm="Recette", charset="UTF-8"' );
	header( 'Cache-Control: no-store' );
	http_response_code( 401 );
	header( 'Content-Type: text/plain; charset=UTF-8' );
	echo "Recette fermée.\n";
	exit;
}

/**
 * Basic auth, y compris quand l'hébergeur ne remplit pas PHP_AUTH_*.
 *
 * @return array{0:string,1:string}
 */
function partikulier_recette_given_credentials() {
	$user = isset( $_SERVER['PHP_AUTH_USER'] ) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
	$pass = isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) $_SERVER['PHP_AUTH_PW'] : '';
	if ( '' !== $user || '' !== $pass ) {
		return array( $user, $pass );
	}
	$header = '';
	foreach ( array( 'HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION' ) as $key ) {
		if ( ! empty( $_SERVER[ $key ] ) ) {
			$header = (string) $_SERVER[ $key ];
			break;
		}
	}
	if ( 0 !== stripos( $header, 'Basic ' ) ) {
		return array( '', '' );
	}
	$decoded = base64_decode( substr( $header, 6 ), true );
	if ( ! is_string( $decoded ) || false === strpos( $decoded, ':' ) ) {
		return array( '', '' );
	}
	$parts = explode( ':', $decoded, 2 );
	return array( (string) $parts[0], (string) $parts[1] );
}

partikulier_recette_gate();
