<?php
/**
 * Raccorde les traductions EN/AR des pages requises (même slug de base).
 * Sans ça, /en/favoris/ et /ar/favoris/ retombent sur la page FR.
 * Jamais silencieux : le déploiement affiche le résultat.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'CLI only' );
}

if ( ! class_exists( 'Partikulier_Required_Page_Translations' ) ) {
	echo "ensure-pages: classe absente\n";
	exit( 1 );
}

Partikulier_Required_Page_Translations::ensure();
echo "ensure-pages: ensure() exécuté\n";

$manque = array();
foreach ( array( 'deposer', 'faq', 'contact', 'favoris', 'connexion', 'mes-annonces' ) as $slug ) {
	$posts = get_posts(
		array(
			'post_type'        => 'page',
			'post_status'      => 'publish',
			'name'             => $slug,
			'posts_per_page'   => 10,
			'fields'           => 'ids',
			'suppress_filters' => true,
		)
	);
	$langs = array();
	foreach ( (array) $posts as $pid ) {
		$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( (int) $pid, 'slug' ) : '';
		if ( $lang ) {
			$langs[ $lang ] = (int) $pid;
		}
	}
	foreach ( array( 'fr', 'en', 'ar' ) as $lang ) {
		if ( empty( $langs[ $lang ] ) ) {
			$manque[] = $slug . ':' . $lang;
		}
	}
}
if ( $manque ) {
	echo 'ensure-pages: ATTENTION manquantes: ' . implode( ', ', $manque ) . "\n";
} else {
	echo "ensure-pages: vérification OK (6 pages × 3 langues, slug de base)\n";
}
