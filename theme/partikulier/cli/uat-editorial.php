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
	WP_CLI::success( 'titres hero UAT mis à jour (AR + EN)' );
} else {
	WP_CLI::success( 'titres hero UAT déjà à jour, skip' );
}
