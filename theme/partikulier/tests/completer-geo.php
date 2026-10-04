<?php
/**
 * Complétion géographique du jeu d'annonces (es_latitude / es_longitude).
 *
 * Pourquoi : class-jsonld.php n'émet l'entité GeoCoordinates que si les méta
 * es_latitude/es_longitude existent (émission conditionnelle, inc/class-jsonld.php
 * méthode get_geo). Le jeu semé avant le 6.18.0 n'en portait pas — les audits
 * JSON-LD échouaient donc sur 2 contrôles (types et geo). Ce script comble le
 * jeu EXISTANT sans le ressemer : le seeder (seed-annonces-test.php) pose
 * désormais les coordonnées dès la création.
 *
 * Ce que le script fait :
 *   1. recense les annonces (type properties) sans les deux méta geo ;
 *   2. résout les coordonnées par quartier (_pk_district_name) puis par ville
 *      (_pk_city_name) — centres réalistes, 10 quartiers + 3 villes ;
 *   3. écrit es_latitude/es_longitude uniquement sur les posts INCOMPLETS
 *      (jamais d'écrasement d'une valeur existante) ;
 *   4. marque chaque post complété (_pk_seed_geo = date) pour la traçabilité ;
 *   5. purge le cache du thème pour que les fiches rejouent le JSON-LD.
 *
 * Idempotent : un second passage ne modifie rien (les posts déjà complets
 * sont listés « déjà servi » et aucune écriture n'a lieu).
 *
 * SIMULATION PAR DÉFAUT : rien n'est modifié tant que vous n'ajoutez pas
 * ?appliquer=1 (web) ou PK_APPLIQUER=1 (ligne de commande). Le mode simulation
 * liste ce qui serait complété, poste par poste, avec la source retenue.
 *
 * Usage CLI : PK_APPLIQUER=1 wp eval-file tests/completer-geo.php
 * (ou depuis le dossier du thème : PK_APPLIQUER=1 php tests/completer-geo.php,
 * le script remonte les dossiers jusqu'à wp-load.php).
 *
 * Usage web : /wp-content/themes/partikulier/tests/completer-geo.php?appliquer=1
 * puis SUPPRIMEZ ce fichier du serveur (même discipline que le seeder).
 */

/*
 * --- Chargement de WordPress ---
 *
 * Convention : PK_WP_DIR=<racine wp> php tests/completer-geo.php (depuis n'importe
 * quel répertoire NEUTRE). Sans PK_WP_DIR, on remonte les dossiers jusqu'à
 * wp-load.php.
 *
 * PIÈGE BANC (découvert le 9 septembre 2026, consigné au worklog) : le framework
 * d'Estatik fait « require_once 'functions.php'; » en CHEMIN RELATIF
 * (plugins/estatik/includes/classes/framework/framework.php:7). En PHP-CLI, un
 * chemin relatif se résout d'abord contre le répertoire COURANT : lancé depuis
 * le dossier du thème, ce require charge le functions.php DU THÈME pendant la
 * phase plugins — AVANT la création de $wp_rewrite (wp-settings.php:671) — et
 * le fatal « add_rule() on null » (inc/class-sitemap.php:25) s'ensuit. On
 * chdir() donc TOUJOURS vers la racine WordPress avant d'amorcer : le require
 * relatif retombe alors sur le functions.php d'Estatik (répertoire de l'appelant),
 * et le functions.php du thème ne se charge qu'à la phase normale (setup_theme).
 * Règle : ne JAMAIS lancer ce script avec le CWD dans le dossier du thème.
 */
{
        $pk_dir = getenv( 'PK_WP_DIR' ) ?: __DIR__;
        if ( ! is_string( $pk_dir ) ) {
                $pk_dir = __DIR__;
        }
        if ( ! file_exists( $pk_dir . '/wp-load.php' ) ) {
                $pk_climb = (string) $pk_dir;
                $pk_dir = '';
                while ( $pk_climb !== dirname( $pk_climb ) ) {
                        if ( file_exists( $pk_climb . '/wp-load.php' ) ) {
                                $pk_dir = $pk_climb;
                                break;
                        }
                        $pk_climb = dirname( $pk_climb );
                }
        }
        if ( '' === $pk_dir || ! file_exists( $pk_dir . '/wp-load.php' ) ) {
                exit( 'wp-load.php introuvable : lancez avec PK_WP_DIR=<racine WordPress> ou placez ce fichier dans le dossier du thème.' );
        }
        // Le CWD doit être la racine WP (cf. piège banc ci-dessus).
        if ( ! @chdir( $pk_dir ) ) {
                exit( 'Impossible de changer de répertoire vers ' . $pk_dir );
        }
        require_once $pk_dir . '/wp-load.php';
}

// --- Exécution : simulation sauf ?appliquer=1 ou PK_APPLIQUER=1.
$pk_argv = isset( $argv ) ? $argv : array();
$pk_apply = ( '1' === getenv( 'PK_APPLIQUER' ) ) || in_array( '--appliquer', $pk_argv, true );
if ( ! $pk_apply && isset( $_GET['appliquer'] ) && '1' === $_GET['appliquer'] ) {
        $pk_apply = true;
}

function pk_geo_say( $line = '' ) {
        echo esc_html( $line ) . "\n";
}

if ( ! defined( 'PARTIKULIER_ESTATIK_POST_TYPE' ) ) {
        exit( 'Constante PARTIKULIER_ESTATIK_POST_TYPE absente : thème non actif.' );
}

/**
 * Centres réalistes : 10 quartiers du jeu de semis + repli par ville.
 * Rester cohérent avec tests/seed-annonces-test.php (clés lat/lng).
 */
$pk_geo_map = array(
        // Quartiers (priorité).
        'Maârif'     => array( 33.5883, -7.6320 ),
        'Ain Diab'   => array( 33.5920, -7.6710 ),
        'Gauthier'   => array( 33.5950, -7.6180 ),
        'Californie' => array( 33.5660, -7.6630 ),
        'Agdal'      => array( 34.0100, -6.8500 ),
        'Souissi'    => array( 33.9700, -6.8600 ),
        'Hassan'     => array( 34.0210, -6.8400 ),
        'Médina'     => array( 31.6295, -7.9811 ),
        'Hivernage'  => array( 31.6240, -8.0060 ),
        'Targa'      => array( 31.6640, -8.0500 ),
        // Repli par ville (district inconnu).
        'Casablanca' => array( 33.5731, -7.5898 ),
        'Rabat'      => array( 34.0209, -6.8416 ),
        'Marrakech'  => array( 31.6295, -7.9811 ),
);

pk_geo_say( '=== COMPLETION GEOGRAPHIQUE DU JEU D ANNONCES ===' );
pk_geo_say( 'Mode      : ' . ( $pk_apply ? 'APPLIQUER (écritures réelles)' : 'SIMULATION (aucune écriture) — ajoutez PK_APPLIQUER=1' ) );
pk_geo_say();

// --- Recensement : toutes les annonces publiées, quelque soit la langue
// (SQL direct : Polylang filtre get_posts même avec suppress_filters —
// découverte du lot A, cf. RAPPORT-LOT-A-REFACTORY-PARTIKULIER.md).
global $wpdb;
$pk_rows = $wpdb->get_results(
        "SELECT p.ID, p.post_title
           FROM {$wpdb->posts} p
          WHERE p.post_type = '" . esc_sql( PARTIKULIER_ESTATIK_POST_TYPE ) . "'
            AND p.post_status = 'publish'
          ORDER BY p.ID ASC"
);

$pk_done = 0;
$pk_filled = 0;
$pk_skipped = 0;
$pk_unresolved = 0;

foreach ( $pk_rows as $pk_row ) {
        $pk_id = (int) $pk_row->ID;
        $pk_lat = get_post_meta( $pk_id, 'es_latitude', true );
        $pk_lng = get_post_meta( $pk_id, 'es_longitude', true );

        // Complet : rien à faire (idempotence stricte).
        if ( '' !== $pk_lat && '' !== $pk_lng && is_numeric( $pk_lat ) && is_numeric( $pk_lng ) ) {
                $pk_done++;
                continue;
        }

        // Résolution : quartier d'abord, ville ensuite. Les traductions EN/AR ne
        // portent pas _pk_district_name/_pk_city_name (absents de la liste de
        // copie de Partikulier_Listing_Translations::copy_data()) : on remonte
        // alors au post source FR via _pk_translation_source (max 3 sauts, garde
        // anti-cycle).
        $pk_district = (string) get_post_meta( $pk_id, '_pk_district_name', true );
        $pk_city = (string) get_post_meta( $pk_id, '_pk_city_name', true );
        $pk_source_id = $pk_id;
        for ( $pk_hop = 0; ( '' === $pk_district || '' === $pk_city ) && $pk_hop < 3; $pk_hop++ ) {
                $pk_parent = (int) get_post_meta( $pk_source_id, '_pk_translation_source', true );
                if ( ! $pk_parent || $pk_parent === $pk_source_id ) {
                        break;
                }
                $pk_source_id = $pk_parent;
                if ( '' === $pk_district ) {
                        $pk_district = (string) get_post_meta( $pk_parent, '_pk_district_name', true );
                }
                if ( '' === $pk_city ) {
                        $pk_city = (string) get_post_meta( $pk_parent, '_pk_city_name', true );
                }
        }
        $pk_source = '';
        $pk_coord = null;
        if ( '' !== $pk_district && isset( $pk_geo_map[ $pk_district ] ) ) {
                $pk_coord = $pk_geo_map[ $pk_district ];
                $pk_source = 'quartier « ' . $pk_district . ' »';
        } elseif ( '' !== $pk_city && isset( $pk_geo_map[ $pk_city ] ) ) {
                $pk_coord = $pk_geo_map[ $pk_city ];
                $pk_source = 'ville « ' . $pk_city . ' » (district « ' . ( '' !== $pk_district ? $pk_district : 'inconnu' ) . ' » hors carte)';
        }

        if ( null === $pk_coord ) {
                $pk_unresolved++;
                pk_geo_say( '[NON RÉSOLU] ID ' . $pk_id . ' : ' . $pk_row->post_title . ' — ni district ni ville dans la carte' );
                continue;
        }

        $pk_label = 'ID ' . str_pad( (string) $pk_id, 4, ' ', STR_PAD_LEFT ) . ' → lat ' . $pk_coord[0] . ', lng ' . $pk_coord[1] . ' (' . $pk_source . ')';

        if ( $pk_apply ) {
                // Écriture : uniquement les clés manquantes, jamais d'écrasement.
                if ( '' === $pk_lat || ! is_numeric( $pk_lat ) ) {
                        update_post_meta( $pk_id, 'es_latitude', (float) $pk_coord[0] );
                }
                if ( '' === $pk_lng || ! is_numeric( $pk_lng ) ) {
                        update_post_meta( $pk_id, 'es_longitude', (float) $pk_coord[1] );
                }
                update_post_meta( $pk_id, '_pk_seed_geo', current_time( 'mysql' ) );
                $pk_filled++;
                pk_geo_say( '[COMPLÉTÉ] ' . $pk_label );
        } else {
                $pk_skipped++;
                pk_geo_say( '[SIMULATION] ' . $pk_label );
        }
}

// --- Cache : les fiches doivent rejouer le JSON-LD.
if ( $pk_apply && class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
        Partikulier_Cache::purge_all();
        pk_geo_say();
        pk_geo_say( 'Cache du thème purgé (les fiches regénèrent leur JSON-LD).' );
}

// --- Bilan.
pk_geo_say();
pk_geo_say( '=== BILAN ===' );
pk_geo_say( 'Annonces déjà complètes : ' . $pk_done );
pk_geo_say( ( $pk_apply ? 'Complétées ce passage  : ' : 'À compléter (simulation) : ' ) . ( $pk_apply ? $pk_filled : $pk_skipped ) );
pk_geo_say( 'Non résolues            : ' . $pk_unresolved );
$pk_total_geo = 0;
foreach ( $pk_rows as $pk_row ) {
        $pk_l = get_post_meta( (int) $pk_row->ID, 'es_latitude', true );
        $pk_g = get_post_meta( (int) $pk_row->ID, 'es_longitude', true );
        if ( '' !== $pk_l && '' !== $pk_g && is_numeric( $pk_l ) && is_numeric( $pk_g ) ) {
                $pk_total_geo++;
        }
}
pk_geo_say( 'Couverture geo finale   : ' . $pk_total_geo . ' / ' . count( $pk_rows ) . ' annonces' );
