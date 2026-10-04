<?php

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Local WordPress only.' );
}

wp_set_current_user( get_user_by( 'login', getenv( 'PK_ADMIN_USER' ) )->ID );
if ( function_exists( 'pll_get_post_language' ) ) {
	$model = $GLOBALS['polylang']->model;
	$model->set_language_in_mass( $model->get_language( 'fr' ) );
}

$demo = get_posts( array(
	'post_type' => 'properties', 'numberposts' => 1,
	'meta_key' => '_pk_seed_demo', 'meta_value' => '1',
	'lang' => '', 'suppress_filters' => true,
) );
if ( ! $demo ) {
	$message = Partikulier_Demo_Installer::seed();
	if ( str_starts_with( $message, 'ERREUR' ) ) {
		WP_CLI::error( $message );
	}
	WP_CLI::log( $message );
}

$owner = get_user_by( 'login', 'docker-demo-owner' );
if ( ! $owner ) {
	$id = wp_insert_user( array(
		'user_login' => 'docker-demo-owner',
		'user_pass' => wp_generate_password( 32, true, true ),
		'user_email' => 'docker-demo-owner@example.test',
		'display_name' => 'Docker Demo Owner',
		'role' => 'contributor',
	) );
	if ( is_wp_error( $id ) ) {
		WP_CLI::error( $id->get_error_message() );
	}
	$owner = get_user_by( 'id', $id );
}
if ( ! Partikulier_Listing_Approval::may_issue_credentials( $owner ) ) {
	WP_CLI::error( 'The example owner must be an unprivileged deposit account.' );
}

$listing = (int) get_option( 'pk_local_example_listing_id' );
if ( ! $listing || ! get_post( $listing ) ) {
	$listing = wp_insert_post( array(
		'post_type' => 'properties', 'post_status' => 'pending',
		'post_author' => $owner->ID,
		'post_title' => 'Docker example - apartment in Casablanca',
		'post_content' => 'Local integration example: a two-bedroom apartment with a living room in Maarif.',
	), true );
	if ( is_wp_error( $listing ) ) {
		WP_CLI::error( $listing->get_error_message() );
	}
	$owner_phone = Partikulier_Crypto::encrypt_phone( '212600000000' );
	if ( '' === $owner_phone ) {
		WP_CLI::error( 'Cannot encrypt the local example owner phone.' );
	}
	foreach ( array(
		'_pk_local_example' => '1', '_pk_status' => 'pending',
		'_pk_owner_phone' => $owner_phone, '_pk_owner_name' => 'Docker Demo Owner',
		'_pk_city_name' => 'Casablanca', '_pk_district_name' => 'Maarif',
		'es_property_price' => 1200000, 'es_property_area' => 90,
		'es_property_bedrooms' => 2, 'es_property_total_rooms' => 3,
	) as $key => $value ) {
		update_post_meta( $listing, $key, $value );
	}
	if ( function_exists( 'pll_set_post_language' ) ) {
		pll_set_post_language( $listing, 'fr' );
	}
	$photos = get_posts( array(
		'post_type' => 'attachment', 'post_status' => 'inherit', 'numberposts' => 3,
		'meta_key' => '_pk_seed_demo', 'meta_value' => '1', 'fields' => 'ids',
		'lang' => '', 'suppress_filters' => true,
	) );
	if ( $photos ) {
		set_post_thumbnail( $listing, $photos[0] );
		update_post_meta( $listing, 'es_property_gallery', $photos );
	}
	update_option( 'pk_local_example_listing_id', $listing, false );
}

( new \Partikulier\Core\Integration\ListingSynchronizer() )->flush();
flush_rewrite_rules();
if ( class_exists( 'Partikulier_Cache' ) ) {
	Partikulier_Cache::purge_all();
}
WP_CLI::success( "Local example listing: $listing (existing content preserved)." );
