<?php
/**
 * Config UAT — titres éditoriaux d'accueil (hero) validés par le propriétaire :
 * AR « اشترِي واكتري مباشرة من المالكين. » / EN « Buy and rent directly from owners. ».
 * Idempotent. Jamais en production : le CD ne l'appelle que si DEPLOY_ENVIRONMENT=uat.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'CLI only' );
}
$editorial = array(
	'ar' => 'اشترِي واكتري مباشرة من المالكين.',
	'en' => 'Buy and rent directly from owners.',
);
$opts      = get_option( 'pk_theme_options', array() );
$opts      = is_array( $opts ) ? $opts : array();
$opts['localized'] = isset( $opts['localized'] ) && is_array( $opts['localized'] ) ? $opts['localized'] : array();
$dirty = false;
foreach ( $editorial as $lang => $text ) {
	$current = isset( $opts['localized'][ $lang ]['site_tagline'] ) ? (string) $opts['localized'][ $lang ]['site_tagline'] : '';
	if ( $text !== $current ) {
		$opts['localized'][ $lang ]['site_tagline'] = $text;
		$dirty = true;
	}
}
if ( $dirty ) {
	update_option( 'pk_theme_options', $opts );
	WP_CLI::success( 'titres hero UAT mis à jour (AR + EN)' );
} else {
	WP_CLI::success( 'titres hero UAT déjà à jour, skip' );
}
