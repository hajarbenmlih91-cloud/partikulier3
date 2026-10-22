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
	$given_user = isset( $_SERVER['PHP_AUTH_USER'] ) ? (string) $_SERVER['PHP_AUTH_USER'] : '';
	$given_pass = isset( $_SERVER['PHP_AUTH_PW'] ) ? (string) $_SERVER['PHP_AUTH_PW'] : '';
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

partikulier_recette_gate();
