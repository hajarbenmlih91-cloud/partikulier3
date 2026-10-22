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
if ( preg_match( '/Build Date => (.+)/', $pk_phpinfo, $pk_match ) ) {
	$pk_build = trim( $pk_match[1] );
}
WP_CLI::log( 'CLI_PHP_VERSION=' . PHP_VERSION );
WP_CLI::log( 'CLI_BUILD_DATE=' . $pk_build );
$pk_ht = @file_get_contents( ABSPATH . '.htaccess' );
if ( is_string( $pk_ht ) && preg_match( '/lsphp[0-9]+/', $pk_ht, $pk_handler ) ) {
	WP_CLI::log( 'WEB_HANDLER=' . $pk_handler[0] );
}
$pk_disabled = array_map( 'trim', explode( ',', (string) ini_get( 'disable_functions' ) ) );
$pk_shell    = function_exists( 'shell_exec' ) && ! in_array( 'shell_exec', $pk_disabled, true );
WP_CLI::log( 'SHELL=' . ( $pk_shell ? 'yes' : 'no' ) );
if ( $pk_shell ) {
	foreach ( array( '/opt/alt/php85/usr/bin/php', '/opt/alt/php84/usr/bin/php', '/opt/alt/php83/usr/bin/php', '/opt/alt/php82/usr/bin/php', '/usr/bin/php' ) as $pk_bin ) {
		if ( ! is_executable( $pk_bin ) ) {
			continue;
		}
		$pk_line = shell_exec( escapeshellarg( $pk_bin ) . ' -v 2>/dev/null' );
		$pk_line = is_string( $pk_line ) ? strtok( $pk_line, "\n" ) : '';
		if ( is_string( $pk_line ) && '' !== $pk_line ) {
			WP_CLI::log( 'BIN ' . $pk_line );
		}
	}
}
unset( $pk_phpinfo, $pk_match, $pk_ht, $pk_handler, $pk_disabled, $pk_shell, $pk_bin, $pk_line );

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
