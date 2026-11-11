<?php
/**
 * Config UAT — numéro WhatsApp de test pour validation et contact acquéreur.
 * Lu uniquement depuis la variable d'environnement masquée PK_TEST_WA_NUMBER
 * (aucun numéro en clair ni dans le dépôt ni dans les journaux).
 * Jamais exécuté en production : le CD ne l'appelle que si DEPLOY_ENVIRONMENT=uat.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'CLI only' );
}
$number = preg_replace( '/\D+/', '', (string) getenv( 'PK_TEST_WA_NUMBER' ) );
if ( ! preg_match( '/^\d{8,15}$/', $number ) ) {
	WP_CLI::log( 'PK_TEST_WA_NUMBER absent ou non numérique : options WhatsApp UAT inchangées.' );
	return;
}
$opts = get_option( 'pk_theme_options', array() );
$opts = is_array( $opts ) ? $opts : array();
$changed = false;
foreach ( array( 'whatsapp_validation_number', 'buyer_whatsapp_number' ) as $field ) {
	if ( $number !== (string) ( $opts[ $field ] ?? '' ) ) {
		$opts[ $field ] = $number;
		$changed        = true;
	}
}
if ( ! $changed ) {
	WP_CLI::success( 'numéros WhatsApp UAT déjà configurés (' . strlen( $number ) . ' chiffres), skip' );
	return;
}
update_option( 'pk_theme_options', $opts );
if ( class_exists( 'Partikulier_Cache' ) ) {
	Partikulier_Cache::purge_all();
}
WP_CLI::success( 'numéros WhatsApp UAT configurés (' . strlen( $number ) . ' chiffres)' );
