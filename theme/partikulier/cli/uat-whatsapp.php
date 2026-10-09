<?php
/**
 * Config UAT — numéro de validation WhatsApp pour tester le parcours de
 * dépôt de bout en bout (garde « is_configured »). Idempotent.
 * Jamais en production : le CD ne l'appelle que si DEPLOY_ENVIRONMENT=uat.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'CLI only' );
}
$number = '212640424412';
$opts   = get_option( 'pk_theme_options', array() );
$opts   = is_array( $opts ) ? $opts : array();
if ( $number === (string) ( $opts['whatsapp_validation_number'] ?? '' ) ) {
	WP_CLI::success( 'numéro WhatsApp UAT déjà configuré, skip' );
	return;
}
$opts['whatsapp_validation_number'] = $number;
update_option( 'pk_theme_options', $opts );
if ( class_exists( 'Partikulier_Cache' ) ) {
	Partikulier_Cache::purge_all();
}
WP_CLI::success( 'numéro WhatsApp UAT configuré : ' . $number );
