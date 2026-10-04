<?php

if ( ! defined( 'ABSPATH' ) || 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Local WordPress only.' );
}
$admin = get_user_by( 'login', getenv( 'PK_ADMIN_USER' ) );
wp_set_current_user( $admin->ID );
if ( ! wp_check_password( getenv( 'PK_ADMIN_PASSWORD' ), $admin->user_pass, $admin->ID ) ) {
	WP_CLI::error( 'Local administrator password is not synchronized.' );
}
if ( 'enforce' !== \Partikulier\Core\Domain\Automation\AutomationService::get( 'hmac_mode' ) ) {
	WP_CLI::error( 'HMAC must be enforced.' );
}
$listing = (int) get_option( 'pk_local_example_listing_id' );
if ( ! $listing || '1' !== get_post_meta( $listing, '_pk_local_example', true ) ) {
	WP_CLI::error( 'Local example fixture is missing.' );
}
$owner = get_user_by( 'id', get_post_field( 'post_author', $listing ) );
$old_hash = $owner->user_pass;
$credentials = Partikulier_Listing_Approval::approve( $listing );
if ( 'sent' !== get_post_meta( $listing, '_pk_n8n_status', true ) ) {
	WP_CLI::error( 'Approval delivery failed: ' . get_post_meta( $listing, '_pk_n8n_error', true ) );
}
$owner = get_user_by( 'id', $owner->ID );
if ( $credentials['password'] && ! wp_check_password( $credentials['password'], $owner->user_pass, $owner->ID ) ) {
	WP_CLI::error( 'Issued owner credentials are invalid.' );
}
if ( ! $credentials['password'] && $old_hash !== $owner->user_pass ) {
	WP_CLI::error( 'Repeat approval changed the owner password.' );
}
echo wp_json_encode( array(
	'listing_id' => $listing,
	'url' => get_permalink( $listing ),
	'reference' => \Partikulier\Core\Domain\Leads\LeadService::reference_for( $listing ),
	'send_credentials' => ! empty( $credentials['password'] ),
	'delivery' => 'sent',
	'owner_login' => $owner->user_login,
) ) . PHP_EOL;
