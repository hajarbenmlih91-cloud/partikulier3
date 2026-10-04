<?php
/**
 * Resultats de recherche (moteur WP natif limite aux annonces).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Limiter la recherche aux annonces.
add_action(
	'pre_get_posts',
	function ( $query ) {
		if ( $query->is_main_query() && $query->is_search() ) {
			$query->set( 'post_type', array( PARTIKULIER_ESTATIK_POST_TYPE, 'page' ) );
		}
	}
);

get_template_part( 'templates/archive' );