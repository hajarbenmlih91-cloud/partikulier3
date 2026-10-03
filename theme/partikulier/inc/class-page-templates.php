<?php
/**
 * Enregistrement des gabarits de page rangés dans /templates/.
 *
 * WordPress ne détecte les « Template Name » qu'à la racine du thème (ou dans les
 * dossiers déclarés par le thème). Les gabarits de Partikulier vivant dans
 * /templates/, ils n'apparaissaient pas dans la liste « Attributs de page » et les
 * pages « Déposer une annonce » / « Mes annonces » retombaient sur page.php :
 * seul le titre s'affichait, sans le formulaire.
 *
 * Ce module :
 *  1. déclare les gabarits pour qu'ils soient sélectionnables dans l'admin ;
 *  2. les charge réellement quand ils sont sélectionnés ;
 *  3. rattrape par slug si aucun gabarit n'a été assigné à la main.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Page_Templates {

	/**
	 * Gabarits : fichier relatif au thème => libellé affiché dans l'admin.
	 */
	public static function templates() {
		return array(
			'templates/page-deposer-annonce.php' => __( 'Déposer une annonce', 'partikulier' ),
			'templates/page-mes-annonces.php'    => __( 'Mes annonces', 'partikulier' ),
			'templates/page-favoris.php'         => __( 'Favoris', 'partikulier' ),
		);
	}

	/**
	 * Rattrapage : slug de page => fichier de gabarit.
	 * Couvre les variantes de slug les plus courantes.
	 */
	public static function slug_map() {
		return array(
			'deposer-une-annonce' => 'templates/page-deposer-annonce.php',
			'deposer-annonce'     => 'templates/page-deposer-annonce.php',
			'deposer'             => 'templates/page-deposer-annonce.php',
			'publier-une-annonce' => 'templates/page-deposer-annonce.php',
			'mes-annonces'        => 'templates/page-mes-annonces.php',
			'mon-espace'          => 'templates/page-mes-annonces.php',
			'dashboard'           => 'templates/page-mes-annonces.php',
			'tableau-de-bord'     => 'templates/page-mes-annonces.php',
			'favoris'             => 'templates/page-favoris.php',
			'mes-favoris'         => 'templates/page-favoris.php',
		);
	}

	public static function init() {
		add_filter( 'theme_page_templates', array( __CLASS__, 'register' ) );
		add_filter( 'template_include', array( __CLASS__, 'load' ), 20 );
	}

	/**
	 * Rend les gabarits sélectionnables dans « Attributs de page ».
	 */
	public static function register( $templates ) {
		foreach ( self::templates() as $file => $label ) {
			$templates[ $file ] = $label;
		}

		return $templates;
	}

	/**
	 * Charge le bon fichier : d'abord le gabarit choisi, sinon le slug.
	 */
	public static function load( $template ) {
		if ( ! is_page() ) {
			return $template;
		}

		$page_id = get_queried_object_id();
		if ( ! $page_id ) {
			return $template;
		}

		// 1. Un gabarit a été assigné dans l'admin.
		$assigned = get_page_template_slug( $page_id );
		if ( $assigned && isset( self::templates()[ $assigned ] ) ) {
			$file = get_theme_file_path( $assigned );
			if ( file_exists( $file ) ) {
				return $file;
			}
		}

		// 2. Rattrapage par slug, seulement si aucun gabarit n'est assigné.
		if ( ! $assigned ) {
			$slug = get_post_field( 'post_name', $page_id );
			$map  = self::slug_map();
			if ( isset( $map[ $slug ] ) ) {
				$file = get_theme_file_path( $map[ $slug ] );
				if ( file_exists( $file ) ) {
					return $file;
				}
			}
		}

		return $template;
	}
}

Partikulier_Page_Templates::init();
