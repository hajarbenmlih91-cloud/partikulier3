<?php
/**
 * Local-only: reproduces the two fixture steps of
 * .github/workflows/contrats-recette.yml so the contract suites can run
 * against the Docker stack. Run once:
 *   docker compose exec -u www-data wordpress wp eval-file /repo/docker/seed-ci-fixtures.php
 * Seeds a throw-away n8n secret: NEVER run against a real site.
 */

if ( 'local' !== wp_get_environment_type() ) {
	WP_CLI::error( 'Refusing to seed fixtures outside WP_ENVIRONMENT_TYPE=local.' );
}
if ( get_option( 'pk_local_ci_fixtures_seeded' ) ) {
	WP_CLI::success( 'CI fixtures already seeded.' );
	return;
}

global $wpdb;

// 1. Published listings (CI step « Amorcer une annonce de contrat »).
wp_set_current_user( 1 );
$request = new WP_REST_Request( 'POST', '/partikulier/v1/listings' );
$request->set_header( 'Content-Type', 'application/json' );
$request->set_body( wp_json_encode( array( 'title' => 'CI fixture', 'description' => 'CI contract fixture', 'locale' => 'fr', 'price' => 100, 'area' => 10 ) ) );
$response = rest_do_request( $request );
if ( 201 !== $response->get_status() ) {
	WP_CLI::error( 'fixture listing failed: ' . wp_json_encode( $response->get_data() ) );
}
$id      = (int) ( $response->get_data()['id'] ?? 0 );
$row     = $wpdb->get_row( $wpdb->prepare( "SELECT external_id FROM {$wpdb->prefix}pk_listings WHERE id = %d", $id ), ARRAY_A );
$post_id = $row ? (int) substr( (string) $row['external_id'], strlen( 'estatik:' ) ) : 0;
if ( $post_id < 1 ) {
	WP_CLI::error( 'fixture post missing' );
}
wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
wp_insert_post( array( 'post_type' => 'properties', 'post_status' => 'publish', 'post_title' => 'CI variant fixture', 'post_content' => 'CI variant contract fixture', 'post_author' => 1 ) );
for ( $i = 3; $i <= 30; $i++ ) {
	wp_insert_post( array( 'post_type' => 'properties', 'post_status' => 'publish', 'post_title' => "CI i18n fixture $i", 'post_content' => 'CI i18n contract fixture', 'post_author' => 1 ) );
}
( new \Partikulier\Core\Integration\ListingSynchronizer() )->flush();

// 2. Historical invariants B2→B6 (CI step « Amorcer les invariants historiques »).
$now = gmdate( 'Y-m-d H:i:s' );
update_option( 'pk_n8n_settings', array( 'automation_api_secret' => 'ci-contract-secret', 'active_key_id' => 'N', 'hmac_mode' => 'off' ), false );
$leads    = $wpdb->prefix . 'pk_buyer_leads';
$existing = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $leads" );
for ( $i = $existing; $i < 10; $i++ ) {
	$seed = "ci-historical-lead-$i";
	$wpdb->insert( $leads, array( 'phone_hash' => hash_hmac( 'sha256', $seed, wp_salt( 'auth' ) ), 'phone_encrypted' => base64_encode( $seed ), 'first_seen_at' => $now, 'last_seen_at' => $now ), array( '%s', '%s', '%s', '%s' ) );
}
$audit     = new \Partikulier\Core\AuditLogger();
$adoptions = (array) $wpdb->get_results( "SELECT metadata_json FROM {$wpdb->prefix}pk_audit_log" );
foreach ( array( array( 'B2', 'leads' ), array( 'B3', 'alerts' ), array( 'B4', 'automation' ), array( 'B5', 'owner_stats' ), array( 'B6', 'translation_variants' ) ) as list( $lot, $domain ) ) {
	$found = false;
	foreach ( $adoptions as $adoption ) {
		$meta = json_decode( (string) $adoption->metadata_json, true );
		if ( ( $meta['lot'] ?? '' ) === $lot && ( $meta['domain'] ?? '' ) === $domain ) {
			$found = true;
			break;
		}
	}
	if ( ! $found ) {
		$audit->record( 'domain_adopted', 'schema', null, array( 'lot' => $lot, 'domain' => $domain ) );
	}
}
$events = $wpdb->prefix . 'pk_automation_events';
for ( $i = 1; $i <= 2; $i++ ) {
	$wpdb->insert( $events, array( 'event_id' => "ci-historical-event-$i", 'event_type' => 'fixture', 'source' => 'ci', 'payload_hash' => hash( 'sha256', "fixture-$i" ), 'status' => 'received', 'received_at' => $now ), array( '%s', '%s', '%s', '%s', '%s', '%s' ) );
}
$saves = $wpdb->prefix . 'pk_property_saves';
for ( $i = 1; $i <= 4; $i++ ) {
	$wpdb->insert( $saves, array( 'property_id' => $i, 'visitor_hash' => hash( 'sha256', "ci-visitor-$i" ), 'created_at' => $now, 'updated_at' => $now ), array( '%d', '%s', '%s', '%s' ) );
}

update_option( 'pk_local_ci_fixtures_seeded', gmdate( 'c' ), false );
WP_CLI::success( 'CI fixtures seeded: 30 published listings + historical invariants.' );
