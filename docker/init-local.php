<?php

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Local WordPress only.' );
}

$admin = get_user_by( 'login', getenv( 'PK_ADMIN_USER' ) );
if ( ! $admin || ! user_can( $admin, 'manage_options' ) ) {
	WP_CLI::error( 'Configured local administrator does not exist.' );
}
if ( ! wp_check_password( getenv( 'PK_ADMIN_PASSWORD' ), $admin->user_pass, $admin->ID ) ) {
	wp_set_password( getenv( 'PK_ADMIN_PASSWORD' ), $admin->ID );
}

$settings = \Partikulier\Core\Domain\Automation\AutomationService::settings();
$settings['hmac_mode'] = 'enforce';
update_option( 'pk_n8n_settings', $settings, false );

if ( '1' === getenv( 'PK_WITH_POLYLANG' ) ) {
	$polylang = $GLOBALS['polylang'] ?? null;
	if ( ! $polylang ) {
		WP_CLI::error( 'Polylang is not available in WP-CLI.' );
	}
	foreach ( array(
		array( 'name' => 'French', 'slug' => 'fr', 'locale' => 'fr_FR', 'rtl' => 0, 'flag' => 'fr', 'term_group' => 1 ),
		array( 'name' => 'English', 'slug' => 'en', 'locale' => 'en_US', 'rtl' => 0, 'flag' => 'us', 'term_group' => 0 ),
		array( 'name' => 'Arabic', 'slug' => 'ar', 'locale' => 'ar', 'rtl' => 1, 'flag' => 'ma', 'term_group' => 2 ),
	) as $language ) {
		if ( ! $polylang->model->get_language( $language['slug'] ) ) {
			$result = $polylang->model->add_language( $language );
			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}
		}
	}
	$result = $polylang->options->merge( array(
		'force_lang' => 1, 'hide_default' => 0, 'browser' => 0, 'redirect_lang' => 0,
		'default_lang' => 'fr', 'post_types' => array( 'post', 'page', 'properties' ),
		'taxonomies' => array( 'es_type', 'es_status', 'es_location', 'es_category' ),
	) );
	if ( is_wp_error( $result ) && $result->has_errors() ) {
		WP_CLI::error( $result->get_error_message() );
	}
}
WP_CLI::success( 'Local administrator synchronized, HMAC enforced, languages initialized.' );
