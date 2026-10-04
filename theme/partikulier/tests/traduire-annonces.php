<?php
/**
 * Rattrapage : cree les versions arabe et anglaise des annonces existantes.
 *
 * Les annonces publiees avant la v6.8.0 n'ont qu'une seule version. Ce script
 * reconstruit leurs donnees a partir des metas et taxonomies deja en base,
 * puis genere les traductions manquantes et les relie via Polylang.
 *
 * SIMULATION PAR DEFAUT : rien n'est modifie tant que vous n'ajoutez pas
 * ?appliquer=1 (web) ou PK_APPLIQUER=1 (ligne de commande).
 *
 * Usage web  : /wp-content/themes/partikulier/tests/traduire-annonces.php
 * Usage CLI  : PK_APPLIQUER=1 wp eval-file tests/traduire-annonces.php
 *
 * @package Partikulier
 */

// --- Chargement de WordPress : on remonte les dossiers jusqu'a wp-load.php.
if ( ! defined( 'ABSPATH' ) ) {
	$pk_dir  = __DIR__;
	$pk_load = '';
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
		exit( 'wp-load.php introuvable. Placez ce fichier dans le dossier du theme.' );
	}
	require_once $pk_load;
}

// --- Reserve aux administrateurs.
if ( ! defined( 'WP_CLI' ) && ! current_user_can( 'manage_options' ) ) {
	wp_die( 'Acces refuse : connectez-vous en administrateur.', 'Acces refuse', array( 'response' => 403 ) );
}

$pk_is_cli = defined( 'WP_CLI' ) && WP_CLI;

// --- Mode : simulation par defaut.
$pk_apply = false;
if ( $pk_is_cli ) {
	$pk_apply = ( '1' === getenv( 'PK_APPLIQUER' ) )
		|| ( isset( $GLOBALS['argv'] ) && in_array( '--appliquer', (array) $GLOBALS['argv'], true ) );
} else {
	$pk_apply = isset( $_GET['appliquer'] ) && '1' === $_GET['appliquer'];
}

if ( ! $pk_is_cli ) {
	header( 'Content-Type: text/plain; charset=utf-8' );
}

/**
 * Affiche une ligne.
 *
 * @param string $line Texte.
 */
function pk_say( $line = '' ) {
	echo $line . "\n";
}

pk_say( '========================================' );
pk_say( ' TRADUCTION DES ANNONCES EXISTANTES' );
pk_say( '========================================' );
pk_say( 'MODE : ' . ( $pk_apply ? 'APPLICATION (ecriture reelle)' : 'SIMULATION (rien n est modifie)' ) );
pk_say();

// --- Verifications prealables.
if ( ! class_exists( 'Partikulier_Listing_Translations' ) ) {
	pk_say( 'ERREUR : theme Partikulier v6.8.0 ou superieur requis.' );
	exit;
}
if ( ! Partikulier_Listing_Translations::available() ) {
	pk_say( 'ERREUR : Polylang est inactif ou incomplet. Activez-le avant de lancer ce script.' );
	exit;
}

$pk_languages = Partikulier_Listing_Translations::active_languages();
pk_say( 'Langues actives : ' . implode( ', ', $pk_languages ) );
if ( count( $pk_languages ) < 2 ) {
	pk_say( 'ERREUR : il faut au moins deux langues configurees dans Polylang.' );
	exit;
}

$pk_default = function_exists( 'pll_default_language' ) ? pll_default_language() : 'fr';
pk_say( 'Langue par defaut : ' . $pk_default );
pk_say();

/**
 * Reconstruit les donnees d'une annonce a partir de la base.
 *
 * @param WP_Post $post Annonce source.
 * @return array Donnees au format attendu par Partikulier_Listing_Preview.
 */
function pk_rebuild_values( $post ) {
	$id = $post->ID;

	// Type de bien.
	$type_terms = wp_get_object_terms( $id, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
	$type_label = ( $type_terms && ! is_wp_error( $type_terms ) ) ? $type_terms[0]->name : 'Bien';

	// Action : le nom du terme es_status dit vendre ou louer.
	$action = 'vendre';
	$cat    = wp_get_object_terms( $id, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
	if ( $cat && ! is_wp_error( $cat ) ) {
		$name = function_exists( 'remove_accents' ) ? remove_accents( $cat[0]->name ) : $cat[0]->name;
		$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
		if ( false !== strpos( $name, 'lou' ) || false !== strpos( $name, 'rent' ) ) {
			$action = 'louer';
		}
	}

	// Lieu : le terme le plus precis est le quartier, son parent la ville.
	$city     = '';
	$district = '';
	$places   = wp_get_object_terms( $id, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
	if ( $places && ! is_wp_error( $places ) ) {
		if ( count( $places ) === 1 ) {
			$term = $places[0];
			if ( $term->parent ) {
				$parent   = get_term( $term->parent, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
				$district = $term->name;
				$city     = ( $parent && ! is_wp_error( $parent ) ) ? $parent->name : '';
			} else {
				$city = $term->name;
			}
		} else {
			// Plusieurs termes : celui qui a un parent est le quartier.
			foreach ( $places as $term ) {
				if ( $term->parent ) {
					$district = $term->name;
				} else {
					$city = $term->name;
				}
			}
			if ( '' === $city && $places ) {
				$city = $places[0]->name;
			}
		}
	}

	$bedrooms_label = get_post_meta( $id, '_pk_bedrooms_label', true );
	if ( '' === $bedrooms_label ) {
		$raw            = get_post_meta( $id, 'es_property_bedrooms', true );
		$bedrooms_label = ( '' !== $raw ) ? (string) (int) $raw : '';
	}

	return array(
		'pk_action_mode'       => $action,
		'pk_role'              => 'proprietaire',
		'pk_type_label'        => $type_label,
		'pk_city_name'         => $city,
		'pk_district_name'     => $district,
		'pk_surface'           => (string) get_post_meta( $id, 'es_property_area', true ),
		'pk_price'             => (string) get_post_meta( $id, 'es_property_price', true ),
		'pk_bedrooms'          => (string) $bedrooms_label,
		'pk_living_rooms'      => (string) get_post_meta( $id, '_pk_living_rooms_label', true ),
		'pk_bathrooms'         => (string) get_post_meta( $id, '_pk_bathrooms_label', true ),
		'pk_floor'             => (string) get_post_meta( $id, '_pk_floor', true ),
		'pk_garage'            => get_post_meta( $id, '_pk_garage', true ) ? get_post_meta( $id, '_pk_garage', true ) : 'Non',
		'pk_elevator'          => get_post_meta( $id, '_pk_elevator', true ) ? get_post_meta( $id, '_pk_elevator', true ) : 'Non',
		'pk_vis_a_vis'         => get_post_meta( $id, '_pk_vis_a_vis', true ) ? get_post_meta( $id, '_pk_vis_a_vis', true ) : 'Non',
		'pk_terrace'           => get_post_meta( $id, '_pk_terrace', true ) ? get_post_meta( $id, '_pk_terrace', true ) : 'Non',
		'pk_terrace_surface'   => (string) get_post_meta( $id, '_pk_terrace_surface', true ),
		'pk_sunshine'          => (string) get_post_meta( $id, '_pk_sunshine', true ),
	);
}

// --- Annonces a traiter : uniquement celles de la langue par defaut,
//     ou sans langue assignee (cas frequent avant Polylang).
$pk_posts = get_posts( array(
	'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
	'post_status'      => array( 'publish', 'pending', 'draft' ),
	'posts_per_page'   => -1,
	'orderby'          => 'ID',
	'order'            => 'ASC',
	'suppress_filters' => true,
	'lang'             => '',
) );

pk_say( 'Annonces trouvees : ' . count( $pk_posts ) );
pk_say( str_repeat( '-', 40 ) );

$pk_done    = 0;
$pk_skipped = 0;
$pk_created = 0;
$pk_errors  = 0;

foreach ( $pk_posts as $pk_post ) {
	$pk_id = $pk_post->ID;

	// Cette annonce est-elle deja une traduction generee ?
	if ( get_post_meta( $pk_id, Partikulier_Listing_Translations::META_GENERATED, true ) ) {
		continue;
	}

	$pk_lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $pk_id ) : '';

	// On ne traite que la langue source (ou les annonces sans langue).
	if ( $pk_lang && $pk_lang !== $pk_default ) {
		continue;
	}

	$pk_existing = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $pk_id ) : array();
	$pk_missing  = array();
	foreach ( $pk_languages as $pk_l ) {
		if ( $pk_l === $pk_default ) {
			continue;
		}
		if ( empty( $pk_existing[ $pk_l ] ) || ! get_post( $pk_existing[ $pk_l ] ) ) {
			$pk_missing[] = $pk_l;
		}
	}

	if ( ! $pk_missing ) {
		$pk_skipped++;
		continue;
	}

	$pk_values = pk_rebuild_values( $pk_post );
	$pk_norm   = Partikulier_Listing_Preview::normalize_input( $pk_values );

	pk_say();
	pk_say( '[' . $pk_id . '] ' . $pk_post->post_title );
	pk_say( '      lieu    : ' . ( Partikulier_Listing_Preview::place_label( $pk_norm ) ?: '(aucun)' ) );
	pk_say( '      manque  : ' . implode( ', ', $pk_missing ) );

	// Une annonce sans lieu produirait un texte bancal : on la signale.
	if ( '' === Partikulier_Listing_Preview::place_label( $pk_norm ) ) {
		pk_say( '      ATTENTION : aucune ville rattachee, texte moins riche.' );
	}

	foreach ( $pk_missing as $pk_l ) {
		pk_say( '      ' . $pk_l . ' -> ' . Partikulier_Listing_I18n::title( $pk_norm, $pk_l ) );
	}

	if ( ! $pk_apply ) {
		$pk_done++;
		continue;
	}

	$pk_map = Partikulier_Listing_Translations::sync( $pk_id, $pk_norm, $pk_default, '' );

	if ( count( $pk_map ) > 1 ) {
		// Les traductions doivent suivre le statut de la source.
		Partikulier_Listing_Translations::sync_status( $pk_id, $pk_post->post_status );

		foreach ( $pk_missing as $pk_l ) {
			if ( ! empty( $pk_map[ $pk_l ] ) ) {
				pk_say( '      cree   : ' . $pk_l . ' -> ID ' . $pk_map[ $pk_l ] . ' (' . get_permalink( $pk_map[ $pk_l ] ) . ')' );
				$pk_created++;
			}
		}
		$pk_done++;
	} else {
		pk_say( '      ECHEC : aucune traduction creee.' );
		$pk_errors++;
	}
}

pk_say();
pk_say( str_repeat( '=', 40 ) );
pk_say( ' RESUME' );
pk_say( str_repeat( '=', 40 ) );
pk_say( 'Annonces traitees        : ' . $pk_done );
pk_say( 'Deja completes (ignores) : ' . $pk_skipped );
if ( $pk_apply ) {
	pk_say( 'Traductions creees       : ' . $pk_created );
	pk_say( 'Echecs                   : ' . $pk_errors );
}
pk_say();

if ( ! $pk_apply ) {
	pk_say( 'Rien n a ete modifie.' );
	pk_say( 'Pour appliquer : ajoutez ?appliquer=1 a l URL de ce script.' );
	pk_say( 'SAUVEGARDEZ VOTRE BASE DE DONNEES AVANT.' );
} else {
	// Les nouvelles URL n existent qu apres regeneration des permaliens.
	flush_rewrite_rules( false );
	pk_say( 'Permaliens regeneres.' );

	if ( class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
		Partikulier_Cache::purge_all();
		pk_say( 'Cache du theme vide.' );
	}
	pk_say();
	pk_say( 'Termine. Verifiez quelques annonces en arabe et en anglais,' );
	pk_say( 'puis supprimez ce fichier du serveur.' );
}
