<?php
/**
 * Jeu de démo UAT — 30 annonces (FR + traductions PLL). Idempotent.
 * Jamais en production : le CD ne l'appelle que si DEPLOY_ENVIRONMENT=uat.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'CLI only' );
}
if ( ! class_exists( 'Partikulier_Demo_Installer' ) ) {
	WP_CLI::error( 'installateur de démo absent' );
}
$cpt      = defined( 'PARTIKULIER_ESTATIK_POST_TYPE' ) ? PARTIKULIER_ESTATIK_POST_TYPE : 'properties';
$existing = get_posts(
	array(
		'post_type'        => $cpt,
		'post_status'      => 'any',
		'posts_per_page'   => 1,
		'fields'           => 'ids',
		'meta_key'         => '_pk_seed_demo',
		'meta_value'       => '1',
		'suppress_filters' => true,
		'lang'             => '',
	)
);
if ( $existing ) {
	require_once __DIR__ . '/demo-facts.php';
	if ( function_exists( 'pk_demo_apply_card_facts' ) ) {
		pk_demo_apply_card_facts();
	}
	WP_CLI::success( 'démo déjà présente, faits de cartes mis à jour' );
	return;
}
$msg = Partikulier_Demo_Installer::seed();
if ( false !== strpos( (string) $msg, 'ERREUR' ) ) {
	WP_CLI::error( $msg );
}
WP_CLI::success( $msg );
