<?php
/**
 * Création idempotente des traductions EN/AR des pages requises (favoris,
 * connexion, mes-annonces, faq, contact, deposer).
 *
 * Constat visite 3 langues 10/10 : /ar/favoris/ (et /en/favoris/) servaient
 * la page FR — les traductions Polylang n'existaient pas (create_missing ne
 * crée que la langue par défaut). Appelé à chaque déploiement, loggue ce
 * qu'il crée. Le contenu des templates est i18n via gettext/trilingue inline ;
 * seule la page traduite manquait.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'CLI only' );
}

if ( ! class_exists( 'Partikulier_Required_Pages' ) || ! class_exists( 'Partikulier_Page_Translations' ) ) {
	echo "ensure-pages: classes absentes\n";
	exit( 1 );
}

$created = Partikulier_Page_Translations::ensure();
if ( $created ) {
	echo 'ensure-pages: créé ' . implode( ', ', $created ) . "\n";
} else {
	echo "ensure-pages: rien à créer (traductions déjà présentes)\n";
}

/* Vérification : chaque page requise doit avoir un ID par langue. */
$manque = array();
foreach ( array( 'fr', 'en', 'ar' ) as $lang ) {
	foreach ( Partikulier_Required_Pages::pages() as $slug => $definition ) {
		$ids = get_posts(
			array(
				'post_type'        => 'page',
				'post_status'      => 'publish',
				'posts_per_page'   => 1,
				'fields'           => 'ids',
				'suppress_filters' => false,
				'lang'             => $lang,
				'meta_query'       => array(
					array(
						'key'   => '_wp_page_template',
						'value' => $definition['template'],
					),
				),
			)
		);
		if ( ! $ids ) {
			$manque[] = $slug . ':' . $lang;
		}
	}
}
if ( $manque ) {
	echo 'ensure-pages: ATTENTION manquantes: ' . implode( ', ', $manque ) . "\n";
} else {
	echo "ensure-pages: vérification OK (6 pages × 3 langues présentes)\n";
}
