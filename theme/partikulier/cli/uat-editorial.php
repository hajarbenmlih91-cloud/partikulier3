<?php
/**
 * Config UAT — titres éditoriaux d'accueil (hero) validés par le propriétaire :
 * AR « اشترِي واكتري مباشرة من المالكين. » / EN « Buy and rent directly from owners. ».
 * Écrit dans pk_customization_options (source réelle du hero), idempotent.
 * Jamais en production : le CD ne l'appelle que si DEPLOY_ENVIRONMENT=uat.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'CLI only' );
}
ob_start();
phpinfo( INFO_GENERAL );
$pk_phpinfo = ob_get_clean();
$pk_build   = '';
if ( preg_match( '/Build Date => ([^\n]+)/', $pk_phpinfo, $pk_match ) ) {
	$pk_build = trim( $pk_match[1] );
}
WP_CLI::log( 'PHP_VERSION=' . PHP_VERSION );
WP_CLI::log( 'BUILD_DATE=' . $pk_build );
unset( $pk_phpinfo, $pk_match );
$editorial = array(
	'ar' => 'اشترِي واكتري مباشرة من المالكين.',
	'en' => 'Buy and rent directly from owners.',
);
$opts      = get_option( 'pk_customization_options', array() );
$opts      = is_array( $opts ) ? $opts : array();
$opts['editorial'] = isset( $opts['editorial'] ) && is_array( $opts['editorial'] ) ? $opts['editorial'] : array();
$opts['editorial']['home_title'] = isset( $opts['editorial']['home_title'] ) && is_array( $opts['editorial']['home_title'] ) ? $opts['editorial']['home_title'] : array();
$dirty = false;
foreach ( $editorial as $lang => $text ) {
	$current = isset( $opts['editorial']['home_title'][ $lang ] ) ? (string) $opts['editorial']['home_title'][ $lang ] : '';
	if ( $text !== $current ) {
		$opts['editorial']['home_title'][ $lang ] = $text;
		$dirty = true;
	}
}
if ( $dirty ) {
	update_option( 'pk_customization_options', $opts );
	if ( class_exists( 'Partikulier_Cache' ) ) {
		Partikulier_Cache::purge_all();
	}
	WP_CLI::success( 'titres hero UAT mis à jour (AR + EN)' );
} else {
	WP_CLI::success( 'titres hero UAT déjà à jour, skip' );
}
