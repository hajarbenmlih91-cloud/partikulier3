<?php
/**
 * Bootstrap Polylang FR/EN/AR — même recette que la CI (SE-025, E-2501).
 * Jamais update_option('polylang') : merge() puis shutdown.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'CLI only' );
}
$model = isset( $GLOBALS['polylang'] ) && $GLOBALS['polylang'] ? $GLOBALS['polylang']->model : null;
if ( ! $model ) {
	WP_CLI::error( "Polylang n'est pas actif" );
}
$langues = array(
	array( 'name' => 'Français', 'slug' => 'fr', 'locale' => 'fr_FR', 'rtl' => 0, 'flag' => 'fr', 'term_group' => 1 ),
	array( 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'rtl' => 0, 'flag' => 'us', 'term_group' => 0 ),
	array( 'name' => 'العربية', 'slug' => 'ar', 'locale' => 'ar', 'rtl' => 1, 'flag' => 'ma', 'term_group' => 2 ),
);
foreach ( $langues as $l ) {
	if ( ! $model->get_language( $l['slug'] ) ) {
		$res = $model->add_language( $l );
		if ( is_wp_error( $res ) ) {
			WP_CLI::error( 'langue ' . $l['slug'] . ' : ' . $res->get_error_message() );
		}
	}
}
$erreurs = $GLOBALS['polylang']->options->merge(
	array(
		'force_lang'    => 1,
		'hide_default'  => 0,
		'browser'       => 0,
		'redirect_lang' => 0,
		'default_lang'  => 'fr',
		'taxonomies'    => array( 'es_type', 'es_status', 'es_location', 'es_category' ),
		'post_types'    => array( 'post', 'page', 'properties' ),
	)
);
if ( is_wp_error( $erreurs ) && $erreurs->has_errors() ) {
	WP_CLI::error( 'réglages polylang : ' . $erreurs->get_error_message() );
}
wp_cache_flush();
if ( false !== strpos( (string) home_url( '/' ), 'hostingersite.com' ) ) {
	$robots = "User-agent: *\nDisallow: /\n";
	$wrote  = file_put_contents( ABSPATH . 'robots.txt', $robots );
	if ( false === $wrote ) {
		WP_CLI::warning( 'robots.txt UAT non écrit' );
	} else {
		WP_CLI::success( 'robots.txt UAT : User-agent: * / Disallow: /' );
	}
}
WP_CLI::success( 'langues fr (défaut) / en / ar (rtl=1) créées, réglages de référence posés' );
