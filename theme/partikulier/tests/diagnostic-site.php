<?php
/**
 * Diagnostic a executer SUR VOTRE SITE REEL.
 *
 * Repond a la question : pourquoi mon site ne ressemble pas au rendu attendu ?
 *
 * Utilisation (au choix) :
 *   wp eval-file wp-content/themes/partikulier/tests/diagnostic-site.php
 *   ou deposer le fichier a la racine WordPress et l'appeler une fois dans le
 *   navigateur en etant connecte en administrateur, puis LE SUPPRIMER.
 *
 * Ne modifie rien : lecture seule.
 */

if ( ! defined( 'ABSPATH' ) ) {
	// Le fichier doit fonctionner qu'il soit laisse dans le theme
	// (wp-content/themes/partikulier/tests/) ou copie a la racine du site.
	// On remonte donc les dossiers jusqu'a trouver wp-load.php.
	$pk_load = '';
	$pk_dir  = __DIR__;
	for ( $pk_i = 0; $pk_i < 8; $pk_i++ ) {
		if ( file_exists( $pk_dir . '/wp-load.php' ) ) {
			$pk_load = $pk_dir . '/wp-load.php';
			break;
		}
		$pk_parent = dirname( $pk_dir );
		if ( $pk_parent === $pk_dir ) {
			break;
		}
		$pk_dir = $pk_parent;
	}
	if ( ! $pk_load ) {
		header( 'Content-Type: text/plain; charset=utf-8' );
		exit( "wp-load.php introuvable.\nPlacez ce fichier a la racine de WordPress (a cote de wp-config.php)\nou laissez-le dans wp-content/themes/partikulier/tests/." );
	}
	require_once $pk_load;
}
if ( ! defined( 'WP_CLI' ) && ! current_user_can( 'manage_options' ) ) {
	exit( 'Reserve aux administrateurs.' );
}

$ligne = str_repeat( '-', 68 );
$out   = array();
$out[] = 'DIAGNOSTIC PARTIKULIER — ' . gmdate( 'Y-m-d H:i' ) . ' UTC';
$out[] = $ligne;

/* ------------------------------------------------ 1. Versions et theme actif */
$theme = wp_get_theme();
$out[] = 'WordPress          : ' . get_bloginfo( 'version' );
$out[] = 'PHP                : ' . PHP_VERSION;
$out[] = 'Theme actif        : ' . $theme->get( 'Name' ) . ' v' . $theme->get( 'Version' );
$out[] = 'Theme parent       : ' . ( $theme->parent() ? $theme->parent()->get( 'Name' ) : 'aucun' );
$out[] = 'Repertoire         : ' . get_stylesheet();

if ( 'partikulier' !== get_stylesheet() && ! $theme->parent() ) {
	$out[] = '  >> ATTENTION : le theme actif n est pas partikulier.';
}

/* ------------------------------------------------ 2. Version reellement servie */
$css = get_theme_file_path( 'assets/css/style.css' );
$out[] = 'style.css         : ' . ( file_exists( $css )
	? number_format( filesize( $css ) / 1024, 0 ) . ' Ko, modifie le ' . gmdate( 'Y-m-d', filemtime( $css ) )
	: 'INTROUVABLE' );
$out[] = 'Constante version  : ' . ( defined( 'PARTIKULIER_VERSION' ) ? PARTIKULIER_VERSION : 'non definie' );

/* ------------------------------------------------ 3. Extensions actives */
$out[] = '';
$out[] = 'EXTENSIONS ACTIVES';
$out[] = $ligne;
foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
	$d = get_plugin_data( WP_PLUGIN_DIR . '/' . $p, false, false );
	$out[] = sprintf( '  %-38s %s', substr( $d['Name'], 0, 38 ), $d['Version'] );
}

/* ------------------------------------------------ 4. Taxonomies : LE point critique */
$out[] = '';
$out[] = 'TAXONOMIES — verifier que chaque terme est au bon endroit';
$out[] = $ligne;

$attendus = array(
	'es_type'     => 'types de biens (Appartement, Maison, Villa...)',
	'es_location' => 'villes et quartiers',
	'es_category' => 'actions (A vendre, A louer)',
);

$villes_connues = array(
	'casablanca', 'rabat', 'marrakech', 'tanger', 'agadir', 'fes', 'fez',
	'meknes', 'oujda', 'kenitra', 'tetouan', 'safi', 'mohammedia', 'essaouira',
	'ouarzazate', 'beni mellal', 'el jadida', 'nador', 'temara', 'sale',
);
$types_connus = array(
	'appartement', 'maison', 'villa', 'terrain', 'studio', 'loft', 'duplex',
	'riad', 'bureau', 'local', 'immeuble', 'parking', 'chalet', 'ferme',
);

foreach ( $attendus as $tax => $desc ) {
	if ( ! taxonomy_exists( $tax ) ) {
		$out[] = sprintf( '%-14s : TAXONOMIE ABSENTE', $tax );
		continue;
	}
	$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => false ) );
	$terms = is_wp_error( $terms ) ? array() : $terms;
	$out[] = sprintf( '%-14s : %d termes  (%s)', $tax, count( $terms ), $desc );

	$suspects = array();
	foreach ( $terms as $t ) {
		$n = remove_accents( mb_strtolower( $t->name ) );
		if ( 'es_type' === $tax && in_array( $n, $villes_connues, true ) ) {
			$suspects[] = $t->name . ' (' . $t->count . ' annonces)';
		}
		if ( 'es_location' === $tax && in_array( $n, $types_connus, true ) ) {
			$suspects[] = $t->name . ' (' . $t->count . ' annonces)';
		}
	}
	if ( $suspects ) {
		$out[] = '  >> TERMES MAL CLASSES : ' . implode( ', ', $suspects );
		$out[] = '     Corrigez-les dans Annonces > ' . $tax . ' (renommer ou supprimer).';
	}
	foreach ( array_slice( $terms, 0, 12 ) as $t ) {
		$out[] = sprintf( '     - %-28s %d annonces', substr( $t->name, 0, 28 ), $t->count );
	}
	if ( count( $terms ) > 12 ) {
		$out[] = '     ... et ' . ( count( $terms ) - 12 ) . ' autres';
	}
}

/* ------------------------------------------------ 5. Contenu */
$out[] = '';
$out[] = 'CONTENU';
$out[] = $ligne;
$cpt = defined( 'PARTIKULIER_ESTATIK_POST_TYPE' ) ? PARTIKULIER_ESTATIK_POST_TYPE : 'properties';
$c   = wp_count_posts( $cpt );
$out[] = 'Annonces publiees  : ' . ( $c->publish ?? 0 );
$out[] = 'Brouillons         : ' . ( $c->draft ?? 0 );

/* Combien sont visibles sur la page d accueil ? */
$visibles = get_posts( array(
	'post_type'      => $cpt,
	'post_status'    => 'publish',
	'posts_per_page' => 5,
	'fields'         => 'ids',
) );
$out[] = 'Visibles en front  : ' . count( $visibles );
if ( ( $c->publish ?? 0 ) > 0 && 0 === count( $visibles ) ) {
	$out[] = '  >> Des annonces existent mais aucune n est retournee.';
	$out[] = '     Cause frequente : Polylang filtre les contenus sans langue.';
}

/* ------------------------------------------------ 6. Polylang */
$out[] = '';
$out[] = 'MULTILINGUE';
$out[] = $ligne;
if ( function_exists( 'pll_languages_list' ) ) {
	$out[] = 'Polylang actif     : oui — ' . implode( ', ', (array) pll_languages_list() );
	$sans = 0;
	foreach ( get_posts( array( 'post_type' => $cpt, 'posts_per_page' => 200, 'fields' => 'ids' ) ) as $id ) {
		if ( ! pll_get_post_language( $id ) ) {
			$sans++;
		}
	}
	$out[] = 'Annonces sans langue : ' . $sans;
	if ( $sans > 0 ) {
		$out[] = '  >> Ces annonces seront INVISIBLES en front.';
		$out[] = '     Langues > Reglages > cocher le type "annonces", puis assigner une langue.';
	}
} else {
	$out[] = 'Polylang actif     : non';
}

/* ------------------------------------------------ 7. Caches et surcharges */
$out[] = '';
$out[] = 'CACHES ET SURCHARGES';
$out[] = $ligne;
$cache_dir = WP_CONTENT_DIR . '/uploads/partikulier-cache';
$n = is_dir( $cache_dir ) ? count( glob( $cache_dir . '/*' ) ) : 0;
$out[] = 'Cache du theme     : ' . $n . ' fichiers';
$plugins_cache = array( 'wp-rocket', 'w3-total-cache', 'wp-super-cache', 'litespeed-cache', 'autoptimize', 'wp-fastest-cache' );
foreach ( (array) get_option( 'active_plugins', array() ) as $p ) {
	foreach ( $plugins_cache as $pc ) {
		if ( false !== strpos( $p, $pc ) ) {
			$out[] = '  >> Cache/optimisation actif : ' . $pc . ' — a vider apres mise a jour du theme.';
		}
	}
}
$out[] = 'CSS additionnel (Personnaliser) : ' . strlen( (string) wp_get_custom_css() ) . ' caracteres';
if ( strlen( (string) wp_get_custom_css() ) > 0 ) {
	$out[] = '  >> Du CSS personnalise peut ecraser le theme.';
}

/* ------------------------------------------------ 8. Fichiers du theme modifies */
$out[] = '';
$out[] = 'INTEGRITE DES FICHIERS';
$out[] = $ligne;
foreach ( array( 'assets/css/style.css', 'assets/js/main.js', 'templates/header.php', 'templates/front-page.php' ) as $f ) {
	$p = get_theme_file_path( $f );
	$out[] = sprintf( '  %-34s %s', $f, file_exists( $p )
		? number_format( filesize( $p ) / 1024, 1 ) . ' Ko  ' . gmdate( 'Y-m-d H:i', filemtime( $p ) )
		: 'ABSENT' );
}

$rapport = implode( "\n", $out ) . "\n";

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::log( $rapport );
} else {
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo $rapport; // phpcs:ignore
}
