<?php
/**
 * Assigne le contenu existant à fr (wizard Polylang) puis flush les rewrites.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'CLI only' );
}
$model = isset( $GLOBALS['polylang'] ) && $GLOBALS['polylang'] ? $GLOBALS['polylang']->model : null;
if ( ! $model ) {
	WP_CLI::error( "Polylang n'est pas actif" );
}
$fr = $model->get_language( 'fr' );
if ( ! $fr ) {
	WP_CLI::error( 'langue fr introuvable' );
}
$model->set_language_in_mass( $fr );
flush_rewrite_rules();
WP_CLI::success( 'contenu existant assigné à fr, rewrites flushed' );
