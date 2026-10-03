<?php
/**
 * Point d'entrée WordPress pour les pages classiques.
 *
 * Le gabarit éditorial est conservé dans templates/page.php afin de partager
 * la structure du thème sans modifier la charte visuelle.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// La route historique /annonces/ doit conserver un point d’entrée stable tout en
// déléguant l’affichage au catalogue Estatik canonique, sans modifier la charte.
if ( is_page( 'annonces' ) ) {
	$archive_url = function_exists( 'pk_properties_archive_url' ) ? pk_properties_archive_url() : home_url( '/property/' );
	wp_safe_redirect( $archive_url, 301 );
	exit;
}

$template = PARTIKULIER_DIR . '/templates/page.php';
if ( file_exists( $template ) ) {
	include $template;
	return;
}

get_header();
while ( have_posts() ) :
	the_post();
	the_title( '<h1>', '</h1>' );
	the_content();
endwhile;
get_footer();