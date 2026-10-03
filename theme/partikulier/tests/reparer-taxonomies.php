<?php
/**
 * Repare les termes mal classes entre les taxonomies Estatik.
 *
 * Probleme traite : des VILLES ont ete creees dans es_category, alors que la
 * taxonomie canonique des actions est es_status (A vendre / A louer). Resultat,
 * le menu "Achat ou location"
 * affiche des noms de villes, et le filtre par action ne fonctionne plus.
 *
 * Ce script :
 *   1. detecte les termes qui ressemblent a des villes dans es_category ;
 *      les actions ne sont jamais supprimees de es_status ;
 *   2. verifie que la ville existe bien dans es_location, sinon la cree ;
 *   3. rattache les annonces concernees a es_location ;
 *   4. detache la ville de es_category (le terme peut ensuite etre supprime).
 *
 * Il ne supprime AUCUNE annonce. Mode simulation par defaut.
 *
 * UTILISATION
 *   Simulation (n'ecrit rien) :
 *     .../tests/reparer-taxonomies.php
 *   Application reelle :
 *     .../tests/reparer-taxonomies.php?appliquer=1
 *
 * IMPORTANT : faites une sauvegarde de la base avant d'appliquer.
 */

if ( ! defined( 'ABSPATH' ) ) {
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
		exit( 'wp-load.php introuvable.' );
	}
	require_once $pk_load;
}

if ( ! defined( 'WP_CLI' ) && ! current_user_can( 'manage_options' ) ) {
	exit( 'Reserve aux administrateurs.' );
}

$appliquer = ! empty( $_GET['appliquer'] );
if ( ! $appliquer && defined( 'WP_CLI' ) ) {
	// en CLI : wp eval-file ... --appliquer  OU  PK_APPLIQUER=1 wp eval-file ...
	$appliquer = ( getenv( 'PK_APPLIQUER' ) === '1' )
		|| ( isset( $GLOBALS['argv'] ) && in_array( '--appliquer', (array) $GLOBALS['argv'], true ) );
}

$TAX_ACTION   = defined( 'PARTIKULIER_ESTATIK_STATUS_TAXONOMY' ) ? PARTIKULIER_ESTATIK_STATUS_TAXONOMY : 'es_status';
$TAX_VILLE    = defined( 'PARTIKULIER_ESTATIK_LOCATION_TAXONOMY' ) ? PARTIKULIER_ESTATIK_LOCATION_TAXONOMY : 'es_location';
$TAX_TYPE     = defined( 'PARTIKULIER_ESTATIK_TYPE_TAXONOMY' ) ? PARTIKULIER_ESTATIK_TYPE_TAXONOMY : 'es_type';

/* Les seuls termes legitimes dans la taxonomie des actions. */
$actions_valides = array( 'a vendre', 'a louer', 'vendre', 'louer', 'vente', 'location', 'for sale', 'for rent', 'sale', 'rent' );

$out   = array();
$ligne = str_repeat( '-', 68 );
$out[] = 'REPARATION DES TAXONOMIES — ' . gmdate( 'Y-m-d H:i' ) . ' UTC';
$out[] = $appliquer ? 'MODE : APPLICATION REELLE' : 'MODE : SIMULATION (rien n est modifie)';
$out[] = $ligne;

$termes = get_terms( array( 'taxonomy' => $TAX_ACTION, 'hide_empty' => false ) );
if ( is_wp_error( $termes ) ) {
	$out[] = 'Taxonomie ' . $TAX_ACTION . ' introuvable.';
	$termes = array();
}

$a_deplacer = array();
foreach ( $termes as $t ) {
	$normalise = remove_accents( mb_strtolower( trim( $t->name ) ) );
	if ( ! in_array( $normalise, $actions_valides, true ) ) {
		$a_deplacer[] = $t;
	}
}

$out[] = 'Termes dans ' . $TAX_ACTION . ' : ' . count( $termes );
$out[] = 'Termes qui n y ont pas leur place : ' . count( $a_deplacer );
$out[] = '';

$deplaces = 0;
$annonces_touchees = 0;

foreach ( $a_deplacer as $t ) {
	$posts = get_posts( array(
		'post_type'      => 'properties',
		'post_status'    => 'any',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => array( array( 'taxonomy' => $TAX_ACTION, 'field' => 'term_id', 'terms' => $t->term_id ) ),
	) );

	$out[] = sprintf( '  "%s" (%d annonce%s)', $t->name, count( $posts ), count( $posts ) > 1 ? 's' : '' );

	if ( ! $appliquer ) {
		$out[] = sprintf( '      -> serait deplace vers %s', $TAX_VILLE );
		$deplaces++;
		$annonces_touchees += count( $posts );
		continue;
	}

	/* 1. s assurer que la ville existe dans la bonne taxonomie */
	$ville = term_exists( $t->name, $TAX_VILLE );
	if ( ! $ville ) {
		$ville = wp_insert_term( $t->name, $TAX_VILLE );
		if ( is_wp_error( $ville ) ) {
			$out[] = '      ERREUR creation : ' . $ville->get_error_message();
			continue;
		}
		$out[] = sprintf( '      cree dans %s', $TAX_VILLE );
	}
	$ville_id = (int) ( is_array( $ville ) ? $ville['term_id'] : $ville );

	/* 2. rattacher les annonces a la ville, sans ecraser les villes existantes */
	foreach ( $posts as $pid ) {
		wp_set_object_terms( $pid, array( $ville_id ), $TAX_VILLE, true );
		wp_remove_object_terms( $pid, array( (int) $t->term_id ), $TAX_ACTION );
		$annonces_touchees++;
	}

	/* 3. supprimer le terme devenu vide dans la taxonomie des actions */
	wp_delete_term( (int) $t->term_id, $TAX_ACTION );
	$out[] = sprintf( '      deplace vers %s, terme supprime de %s', $TAX_VILLE, $TAX_ACTION );
	$deplaces++;
}

$out[] = '';
$out[] = $ligne;
$out[] = sprintf( '%s : %d terme(s), %d annonce(s)',
	$appliquer ? 'Traites' : 'Seraient traites', $deplaces, $annonces_touchees );

/* --- Verification de l etat final --- */
if ( $appliquer ) {
	clean_term_cache( array(), $TAX_ACTION );
	clean_term_cache( array(), $TAX_VILLE );

	$restants = get_terms( array( 'taxonomy' => $TAX_ACTION, 'hide_empty' => false ) );
	$restants = is_wp_error( $restants ) ? array() : $restants;
	$out[] = '';
	$out[] = 'ETAT FINAL de ' . $TAX_ACTION . ' :';
	foreach ( $restants as $t ) {
		$out[] = sprintf( '  - %-24s %d annonces', $t->name, $t->count );
	}

	$sans_action = get_posts( array(
		'post_type'      => 'properties',
		'post_status'    => 'publish',
		'posts_per_page' => -1,
		'fields'         => 'ids',
		'tax_query'      => array( array( 'taxonomy' => $TAX_ACTION, 'operator' => 'NOT EXISTS' ) ),
	) );
	if ( $sans_action ) {
		$out[] = '';
		$out[] = sprintf( 'ATTENTION : %d annonce(s) n ont plus d action (A vendre / A louer).', count( $sans_action ) );
		$out[] = 'Assignez-la depuis Annonces > Modification rapide, sinon elles resteront';
		$out[] = 'sans badge et le filtre "Achat ou location" ne les trouvera pas.';
	}

	/* vider le cache de pages du theme */
	$cache = WP_CONTENT_DIR . '/uploads/partikulier-cache';
	if ( is_dir( $cache ) ) {
		foreach ( (array) glob( $cache . '/*.html' ) as $f ) {
			@unlink( $f ); // phpcs:ignore
		}
		$out[] = '';
		$out[] = 'Cache du theme vide.';
	}
} else {
	$out[] = '';
	$out[] = 'Pour appliquer reellement : ajoutez ?appliquer=1 a l URL.';
	$out[] = 'SAUVEGARDEZ LA BASE avant.';
}

$rapport = implode( "\n", $out ) . "\n";

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	WP_CLI::log( $rapport );
} else {
	header( 'Content-Type: text/plain; charset=utf-8' );
	echo $rapport; // phpcs:ignore
}
