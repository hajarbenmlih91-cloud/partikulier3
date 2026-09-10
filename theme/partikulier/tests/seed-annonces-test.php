<?php
/**
 * Annonces de test : 10 biens, 3 villes, 7 types, photos dupliquees, 3 langues.
 *
 * But : disposer d'un jeu de donnees visible AVANT l'import des 800 annonces,
 * pour valider le catalogue, les filtres et la traduction automatique fr/en/ar
 * sur des pages reelles (titres, descriptions, hreflang, og:image).
 *
 * Ce que le script fait :
 *   1. purge l'eventuel jeu precedent (marque _pk_seed_test) ;
 *   2. installe 10 annonces FR PUBLIEES via le meme pipeline que le formulaire
 *      (memes metas, memes taxonomies, titre/description par
 *      Partikulier_Listing_I18n, comme class-form.php) ;
 *   3. duplique un petit pool de photos (mediatheque existante, sinon
 *      images generes GD marquees) : les memes IDs servent plusieurs annonces ;
 *   4. prepare les termes canoniques : langue fr + liaison Polylang des
 *      traductions existantes. Indispensable car le hook set_object_terms
 *      de Polylang traduit sinon les termes dans la langue du post et
 *      fabrique des copies suffixees (appartement-en, casablanca-fr...) ;
 *   5. simule la validation WhatsApp de l administrateur (_pk_status = actif)
 *      : sans elle, la porte de moderation du theme masque les annonces du
 *      catalogue (requete archive : _pk_status absent ou actif) ;
 *   6. cree les versions EN et AR avec Partikulier_Listing_Translations::sync(),
 *      donc les hreflang et les traductions de termes Polylang ;
 *   7. purge le cache du theme et regenere les permaliens.
 *
 * Termes utilises : les termes canoniques (slug sans suffixe -fr/-en/-ar).
 * Le jeu reste correct AVANT et APRES la fusion des doublons
 * (tests/reparer-taxonomies.php) car il n'ajoute aucun doublon.
 *
 * SIMULATION PAR DEFAUT : rien n'est modifie tant que vous n'ajoutez pas
 * ?appliquer=1 (web) ou PK_APPLIQUER=1 (ligne de commande).
 * Suppression seule : ?purger=1 (web) ou PK_PURGER=1 (CLI).
 * Note : wp eval-file refuse les parametres du type --appliquer,
 * passez donc PK_APPLIQUER=1 devant la commande wp.
 *
 * Usage web : /wp-content/themes/partikulier/tests/seed-annonces-test.php
 * Usage CLI : PK_APPLIQUER=1 wp eval-file tests/seed-annonces-test.php
 *
 * Prerequis : theme Partikulier >= 6.17.23 (filtres corriges), Polylang actif.
 * SAUVEGARDEZ VOTRE BASE AVANT D'APPLIQUER.
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

// --- Reserve aux administrateurs (CLI excepte, comme traduire-annonces.php).
if ( ! defined( 'WP_CLI' ) && ! current_user_can( 'manage_options' ) ) {
        wp_die( 'Acces refuse : connectez-vous en administrateur.', 'Acces refuse', array( 'response' => 403 ) );
}

$pk_is_cli = defined( 'WP_CLI' ) && WP_CLI;

// --- Modes : simulation par defaut ; --purger pour supprimer seulement.
$pk_apply = false;
$pk_purge = false;
if ( $pk_is_cli ) {
        $pk_argv  = isset( $GLOBALS['argv'] ) ? (array) $GLOBALS['argv'] : array();
        $pk_apply = ( '1' === getenv( 'PK_APPLIQUER' ) ) || in_array( '--appliquer', $pk_argv, true );
        $pk_purge = ( '1' === getenv( 'PK_PURGER' ) ) || in_array( '--purger', $pk_argv, true );
} else {
        $pk_apply = isset( $_GET['appliquer'] ) && '1' === $_GET['appliquer'];
        $pk_purge = isset( $_GET['purger'] ) && '1' === $_GET['purger'];
}

if ( ! $pk_is_cli ) {
        header( 'Content-Type: text/plain; charset=utf-8' );
}

/**
 * Affiche une ligne.
 *
 * @param string $line Texte.
 */
function pk_seed_say( $line = '' ) {
        echo $line . "\n";
}

/**
 * Marque tout ce que ce script cree : posts, traductions, photos.
 */
function pk_seed_mark() {
        return '_pk_seed_test';
}

pk_seed_say( '================================================' );
pk_seed_say( ' ANNONCES DE TEST : 10 biens, 3 villes, 3 langues' );
pk_seed_say( '================================================' );
pk_seed_say( 'MODE : ' . ( $pk_purge ? 'PURGE (suppression du jeu de test)' : ( $pk_apply ? 'APPLICATION (ecriture reelle)' : 'SIMULATION (rien n est modifie)' ) ) );
pk_seed_say();

// --- Verifications prealables.
if ( ! class_exists( 'Partikulier_Listing_Preview' ) || ! class_exists( 'Partikulier_Listing_I18n' ) ) {
        pk_seed_say( 'ERREUR : theme Partikulier (moteur de redaction) introuvable.' );
        exit;
}
if ( ! class_exists( 'Partikulier_Listing_Translations' ) || ! Partikulier_Listing_Translations::available() ) {
        pk_seed_say( 'ERREUR : Polylang est inactif ou incomplet. Activez-le avant de lancer ce script.' );
        exit;
}

$pk_languages = Partikulier_Listing_Translations::active_languages();
pk_seed_say( 'Langues actives : ' . implode( ', ', $pk_languages ) );
if ( count( $pk_languages ) < 2 ) {
        pk_seed_say( 'ERREUR : il faut au moins deux langues configurees dans Polylang.' );
        exit;
}
$pk_default = function_exists( 'pll_default_language' ) ? pll_default_language() : 'fr';
if ( ! in_array( $pk_default, $pk_languages, true ) ) {
        $pk_default = $pk_languages[0];
}
// Les depots reels du site se font en francais : la source est FR quand la
// langue existe, pour reproduire exactement le parcours de l import des 800.
if ( in_array( 'fr', $pk_languages, true ) ) {
        $pk_default = 'fr';
}
pk_seed_say( 'Langue source  : ' . $pk_default );
pk_seed_say();

/**
 * Jeu de 10 annonces : 4 Casablanca, 3 Rabat, 3 Marrakech ;
 * 7 types ; 8 ventes, 2 locations. Cles au format du formulaire.
 *
 * Coordonnees es_latitude/es_longitude (JSON-LD GeoCoordinates) : centres de
 * quartiers realistes. Elles sont posees sur les TROIS langues apres sync(),
 * car Partikulier_Listing_Translations::copy_data() ne transporte pas les
 * cles geographiques (metas factuelles dupliquees volontairement ici, cf.
 * completer-geo.php pour les jeux existants).
 */
$pk_seed_listings = array(
        array( 'type' => 'Appartement', 'type_slug' => 'appartement', 'city' => 'Casablanca', 'city_slug' => 'casablanca', 'district' => 'Maârif',     'action' => 'vendre', 'price' => 1450000, 'surface' => 120, 'bedrooms' => '3',  'living' => '1', 'bathrooms' => '2', 'floor' => '3',   'terrace' => 'Oui', 'terrace_surface' => 12, 'garage' => 'Non', 'elevator' => 'Oui', 'vis_a_vis' => 'Oui', 'lat' => 33.5883, 'lng' => -7.6320 ),
        array( 'type' => 'Appartement', 'type_slug' => 'appartement', 'city' => 'Casablanca', 'city_slug' => 'casablanca', 'district' => 'Ain Diab',   'action' => 'vendre', 'price' => 980000,  'surface' => 85,  'bedrooms' => '2',  'living' => '1', 'bathrooms' => '1', 'floor' => '5',   'terrace' => 'Non', 'terrace_surface' => 0,  'garage' => 'Non', 'elevator' => 'Oui', 'vis_a_vis' => 'Non', 'lat' => 33.5920, 'lng' => -7.6710 ),
        array( 'type' => 'Studio',      'type_slug' => 'studio',      'city' => 'Casablanca', 'city_slug' => 'casablanca', 'district' => 'Gauthier',   'action' => 'vendre', 'price' => 550000,  'surface' => 40,  'bedrooms' => '0',  'living' => '1', 'bathrooms' => '1', 'floor' => '2',   'terrace' => 'Non', 'terrace_surface' => 0,  'garage' => 'Non', 'elevator' => 'Non', 'vis_a_vis' => 'Non', 'lat' => 33.5950, 'lng' => -7.6180 ),
        array( 'type' => 'Villa',       'type_slug' => 'villa',       'city' => 'Casablanca', 'city_slug' => 'casablanca', 'district' => 'Californie', 'action' => 'vendre', 'price' => 4900000, 'surface' => 320, 'bedrooms' => '3+', 'living' => '2', 'bathrooms' => '3', 'floor' => 'RDC', 'terrace' => 'Oui', 'terrace_surface' => 40, 'garage' => 'Oui', 'elevator' => 'Non', 'vis_a_vis' => 'Non', 'lat' => 33.5660, 'lng' => -7.6630 ),
        array( 'type' => 'Appartement', 'type_slug' => 'appartement', 'city' => 'Rabat',      'city_slug' => 'rabat',      'district' => 'Agdal',      'action' => 'louer',  'price' => 6500,    'surface' => 95,  'bedrooms' => '2',  'living' => '1', 'bathrooms' => '1', 'floor' => '4',   'terrace' => 'Non', 'terrace_surface' => 0,  'garage' => 'Non', 'elevator' => 'Oui', 'vis_a_vis' => 'Non', 'lat' => 34.0100, 'lng' => -6.8500 ),
        array( 'type' => 'Maison',      'type_slug' => 'maison',      'city' => 'Rabat',      'city_slug' => 'rabat',      'district' => 'Souissi',    'action' => 'vendre', 'price' => 3200000, 'surface' => 240, 'bedrooms' => '4',  'living' => '2', 'bathrooms' => '3', 'floor' => 'RDC', 'terrace' => 'Oui', 'terrace_surface' => 25, 'garage' => 'Oui', 'elevator' => 'Non', 'vis_a_vis' => 'Oui', 'lat' => 33.9700, 'lng' => -6.8600 ),
        array( 'type' => 'Duplex',      'type_slug' => 'duplex',      'city' => 'Rabat',      'city_slug' => 'rabat',      'district' => 'Hassan',     'action' => 'vendre', 'price' => 1750000, 'surface' => 140, 'bedrooms' => '3',  'living' => '1', 'bathrooms' => '2', 'floor' => '6',   'terrace' => 'Oui', 'terrace_surface' => 18, 'garage' => 'Non', 'elevator' => 'Oui', 'vis_a_vis' => 'Non', 'lat' => 34.0210, 'lng' => -6.8400 ),
        array( 'type' => 'Riad',        'type_slug' => 'riad',        'city' => 'Marrakech',  'city_slug' => 'marrakech',  'district' => 'Médina',     'action' => 'vendre', 'price' => 2800000, 'surface' => 180, 'bedrooms' => '4',  'living' => '2', 'bathrooms' => '3', 'floor' => 'RDC', 'terrace' => 'Oui', 'terrace_surface' => 30, 'garage' => 'Non', 'elevator' => 'Non', 'vis_a_vis' => 'Non', 'lat' => 31.6295, 'lng' => -7.9811 ),
        array( 'type' => 'Appartement', 'type_slug' => 'appartement', 'city' => 'Marrakech',  'city_slug' => 'marrakech',  'district' => 'Hivernage',  'action' => 'louer',  'price' => 7500,    'surface' => 105, 'bedrooms' => '2',  'living' => '1', 'bathrooms' => '2', 'floor' => '1',   'terrace' => 'Oui', 'terrace_surface' => 10, 'garage' => 'Non', 'elevator' => 'Oui', 'vis_a_vis' => 'Non', 'lat' => 31.6240, 'lng' => -8.0060 ),
        array( 'type' => 'Terrain',     'type_slug' => 'terrain',     'city' => 'Marrakech',  'city_slug' => 'marrakech',  'district' => 'Targa',      'action' => 'vendre', 'price' => 850000,  'surface' => 500, 'bedrooms' => '',   'living' => '',  'bathrooms' => '',  'floor' => '',   'terrace' => 'Non', 'terrace_surface' => 0,  'garage' => 'Non', 'elevator' => 'Non', 'vis_a_vis' => 'Non', 'lat' => 31.6640, 'lng' => -8.0500 ),
);

/**
 * Retrouve ou cree un terme canonique (jamais de suffixe -fr/-en/-ar).
 * En simulation, la creation est seulement annoncee : rien n est ecrit.
 *
 * @param string $taxonomy Taxonomie.
 * @param string $name     Nom lisible.
 * @param string $slug     Slug attendu.
 * @param array  $created  Collecte des creations pour le rapport.
 * @param bool   $apply    Cree reellement le terme manquant.
 * @return int term_id, 0 si absent et non cree.
 */
function pk_seed_term( $taxonomy, $name, $slug, array &$created = null, $apply = true ) {
        $term = get_term_by( 'slug', $slug, $taxonomy );
        if ( ! $term ) {
                $term = get_term_by( 'name', $name, $taxonomy );
        }
        if ( ! $term ) {
                if ( ! $apply ) {
                        if ( is_array( $created ) ) {
                                $created[] = $name . ' (' . $taxonomy . ')';
                        }
                        return 0;
                }
                $res = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
                if ( is_wp_error( $res ) ) {
                        pk_seed_say( 'ERREUR : creation du terme ' . $name . ' (' . $taxonomy . ') : ' . $res->get_error_message() );
                        exit;
                }
                if ( is_array( $created ) ) {
                        $created[] = $name . ' (' . $taxonomy . ')';
                }
                $term = get_term( (int) $res['term_id'], $taxonomy );
        }
        return (int) $term->term_id;
}

/**
 * Prepare un terme canonique pour un site multilingue :
 * 1. langue fr si le terme n en a pas ;
 * 2. liaison Polylang des traductions deja existantes (par slug -en/-ar).
 *
 * Pourquoi c est indispensable : le hook set_object_terms de Polylang
 * traduit automatiquement les termes assignes dans la LANGUE DU POST et
 * fabrique des copies suffixees (appartement-en, casablanca-fr...) quand le
 * terme n a pas la bonne langue ou pas de traduction liee. C est ainsi que
 * les doublons -fr du site d origine sont nes. Un terme fr relie a ses
 * traductions evite toute creation parasite.
 *
 * @param int    $term_id   Terme canonique.
 * @param string $taxonomy  Taxonomie.
 * @param string $slug_hint Slug du terme (pour trouver -en/-ar).
 * @param array  $log       Collecte des actions pour le rapport.
 * @param bool   $apply     Applique les changements.
 * @return int term_id.
 */
function pk_seed_prepare_term_id( $term_id, $taxonomy, $slug_hint, array &$log = null, $apply = true ) {
        $term = get_term( (int) $term_id, $taxonomy );
        if ( ! $term || is_wp_error( $term ) ) {
                return (int) $term_id;
        }
        $tid = (int) $term->term_id;

        if ( ! function_exists( 'pll_get_term_language' ) || ! function_exists( 'pll_set_term_language' ) ) {
                return $tid;
        }

        // 1. Langue fr si absente (terme sans langue = copie automatique garantie).
        if ( ! pll_get_term_language( $tid, 'slug' ) ) {
                if ( $apply ) {
                        pll_set_term_language( $tid, 'fr' );
                        if ( is_array( $log ) ) {
                                $log[] = 'langue fr attribuee au terme ' . $term->name;
                        }
                } elseif ( is_array( $log ) ) {
                        $log[] = 'langue fr a attribuer au terme ' . $term->name;
                }
        }

        // 2. Traductions existantes non liees (ex. casablanca-en / casablanca-ar).
        if ( function_exists( 'pll_get_term' ) && function_exists( 'pll_save_term_translations' ) ) {
                $map     = array( 'fr' => $tid );
                $changed = false;
                foreach ( array( 'en', 'ar' ) as $l ) {
                        if ( pll_get_term( $tid, $l ) ) {
                                continue; // deja liee.
                        }
                        $cand = get_term_by( 'slug', $slug_hint . '-' . $l, $taxonomy );
                        if ( ! $cand || is_wp_error( $cand ) ) {
                                continue;
                        }
                        if ( ! pll_get_term_language( (int) $cand->term_id, 'slug' ) ) {
                                if ( $apply ) {
                                        pll_set_term_language( (int) $cand->term_id, $l );
                                }
                        }
                        $map[ $l ] = (int) $cand->term_id;
                        $changed   = true;
                        if ( is_array( $log ) ) {
                                $log[] = ( $apply ? '' : 'a faire : ' ) . $cand->name . ' relie comme traduction ' . $l . ' de ' . $term->name;
                        }
                }
                if ( $changed && $apply ) {
                        pll_save_term_translations( $map );
                }
        }
        return $tid;
}

/**
 * Retrouve, cree et prepare un terme canonique complet.
 *
 * @param string $taxonomy Taxonomie.
 * @param string $name     Nom lisible.
 * @param string $slug     Slug attendu.
 * @param array  $log      Collecte des actions.
 * @param bool   $apply    Ecrit reellement.
 * @return int term_id.
 */
function pk_seed_prepare_term( $taxonomy, $name, $slug, array &$log = null, $apply = true ) {
        $tid = pk_seed_term( $taxonomy, $name, $slug, $log, $apply );
        if ( ! $tid ) {
                return 0;
        }
        return pk_seed_prepare_term_id( $tid, $taxonomy, $slug, $log, $apply );
}

/**
 * Retrouve le terme es_status correspondant a vendre/louer (logique de
 * class-form::resolve_action_slug), en le creeant au besoin.
 *
 * @param string $mode vendre|louer.
 * @param array  $created Collecte des creations.
 * @param bool   $apply   Ecrit reellement.
 * @return int term_id, 0 si absent et non cree.
 */
function pk_seed_status_term( $mode, array &$created = null, $apply = true ) {
        $is_rent = ( 'louer' === $mode );
        $slugs   = $is_rent ? array( 'a-louer', 'louer', 'for-rent', 'rent' ) : array( 'a-vendre', 'vendre', 'for-sale', 'sale' );
        foreach ( $slugs as $slug ) {
                $term = get_term_by( 'slug', $slug, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
                if ( $term ) {
                        return (int) $term->term_id;
                }
        }
        $needles = $is_rent ? array( 'a louer', 'louer', 'location', 'for rent', 'rent' ) : array( 'a vendre', 'vendre', 'vente', 'for sale', 'sale' );
        $terms   = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_STATUS_TAXONOMY, 'hide_empty' => false ) );
        if ( ! is_wp_error( $terms ) ) {
                foreach ( $terms as $term ) {
                        $name = function_exists( 'mb_strtolower' ) ? mb_strtolower( remove_accents( $term->name ) ) : strtolower( remove_accents( $term->name ) );
                        foreach ( $needles as $needle ) {
                                if ( $name === $needle || false !== strpos( $name, $needle ) ) {
                                        return (int) $term->term_id;
                                }
                        }
                }
        }
        return pk_seed_term( PARTIKULIER_ESTATIK_STATUS_TAXONOMY, $is_rent ? 'A louer' : 'A vendre', $is_rent ? 'a-louer' : 'a-vendre', $created, $apply );
}

/**
 * Version preparee du statut : resolution + langue + liaisons Polylang.
 *
 * @param string $mode vendre|louer.
 * @param array  $log   Collecte des actions.
 * @param bool   $apply Ecrit reellement.
 * @return int term_id.
 */
function pk_seed_prepare_status_term( $mode, array &$log = null, $apply = true ) {
        $is_rent = ( 'louer' === $mode );
        $tid     = pk_seed_status_term( $mode, $log, $apply );
        if ( ! $tid ) {
                return 0;
        }
        return pk_seed_prepare_term_id( $tid, PARTIKULIER_ESTATIK_STATUS_TAXONOMY, $is_rent ? 'a-louer' : 'a-vendre', $log, $apply );
}

// --- Jeu precedent a purger ?
$pk_old = get_posts( array(
        'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
        'post_status'      => 'any',
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'meta_key'         => pk_seed_mark(),
        'meta_value'       => '1',
        'suppress_filters' => true,
) );
$pk_old_media = get_posts( array(
        'post_type'        => 'attachment',
        'post_status'      => 'inherit',
        'posts_per_page'   => -1,
        'fields'           => 'ids',
        'meta_key'         => pk_seed_mark(),
        'meta_value'       => '1',
        'suppress_filters' => true,
) );
pk_seed_say( 'Jeu de test deja present : ' . count( $pk_old ) . ' annonces, ' . count( $pk_old_media ) . ' photos marquees.' );
pk_seed_say();

/**
 * Supprime le jeu de test complet (annonces toutes langues + photos generees).
 *
 * @param bool $apply Appliquer reellement.
 */
function pk_seed_purge( $apply ) {
        $posts = get_posts( array(
                'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
                'post_status'      => 'any',
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'meta_key'         => pk_seed_mark(),
                'meta_value'       => '1',
                'suppress_filters' => true,
        ) );
        $media = get_posts( array(
                'post_type'        => 'attachment',
                'post_status'      => 'inherit',
                'posts_per_page'   => -1,
                'fields'           => 'ids',
                'meta_key'         => pk_seed_mark(),
                'meta_value'       => '1',
                'suppress_filters' => true,
        ) );
        pk_seed_say( 'Purge : ' . count( $posts ) . ' annonces, ' . count( $media ) . ' photos.' );
        if ( ! $apply ) {
                return;
        }
        // Les traductions liees portent la meme marque : tout part ensemble.
        foreach ( $posts as $id ) {
                wp_delete_post( (int) $id, true );
        }
        foreach ( $media as $id ) {
                wp_delete_attachment( (int) $id, true );
        }
        if ( class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
                Partikulier_Cache::purge_all();
        }
        pk_seed_say( 'Purge effectuee.' );
}

if ( $pk_purge ) {
        pk_seed_purge( $pk_apply );
        if ( $pk_apply ) {
                flush_rewrite_rules( false );
                pk_seed_say( 'Permaliens regeneres.' );
        } else {
                pk_seed_say( 'Rien n a ete modifie. Pour purger : PK_PURGER=1 PK_APPLIQUER=1 wp eval-file tests/seed-annonces-test.php' );
        }
        exit;
}

// --- Plan des termes : resolution + preparation (langue, liaisons).
$pk_term_log = array();
$pk_plan = array();
foreach ( $pk_seed_listings as $l ) {
        $plan = $l;
        $plan['type_id']   = pk_seed_prepare_term( PARTIKULIER_ESTATIK_TYPE_TAXONOMY, $l['type'], $l['type_slug'], $pk_term_log, $pk_apply );
        $plan['city_id']   = pk_seed_prepare_term( PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, $l['city'], $l['city_slug'], $pk_term_log, $pk_apply );
        $plan['status_id'] = pk_seed_prepare_status_term( $l['action'], $pk_term_log, $pk_apply );
        $pk_plan[] = $plan;
}

pk_seed_say( 'Termes canoniques utilises (aucun doublon -fr/-en/-ar cree) :' );
$pk_seen = array();
foreach ( $pk_plan as $p ) {
        foreach ( array( 'type', 'city' ) as $k ) {
                $key = $p[ $k ] . '|' . $p[ $k . '_id' ];
                if ( isset( $pk_seen[ $key ] ) ) {
                        continue;
                }
                $pk_seen[ $key ] = true;
                $tax = 'type' === $k ? 'es_type' : 'es_location';
                pk_seed_say( '  ' . str_pad( $tax, 12 ) . ': ' . $p[ $k ] . ' -> ' . ( $p[ $k . '_id' ] ? 'terme ' . $p[ $k . '_id' ] : 'a creer' ) );
        }
}
foreach ( array_unique( array_map( static function ( $p ) { return $p['action']; }, $pk_plan ) ) as $mode ) {
        $tid = pk_seed_status_term( $mode, $pk_term_log, $pk_apply );
        pk_seed_say( '  ' . str_pad( 'es_status', 12 ) . ': ' . ( 'louer' === $mode ? 'A louer' : 'A vendre' ) . ' -> ' . ( $tid ? 'terme ' . $tid : 'a creer' ) );
}
if ( $pk_term_log ) {
        pk_seed_say( 'Preparation des termes (langue + liaisons Polylang) :' );
        foreach ( array_unique( $pk_term_log ) as $line ) {
                pk_seed_say( '  - ' . $line );
        }
}
pk_seed_say();

// --- Photos : pool duplique.
/**
 * Constitue le pool de photos : jusqu'a 3 images de la mediatheque,
 * completees par des images GD marquees si la mediatheque est vide.
 * Les memes IDs servent ensuite plusieurs annonces (duplication reelle).
 *
 * @param bool $apply Creer les photos manquantes.
 * @return int[] IDs d'attachments.
 */
function pk_seed_photo_pool( $apply ) {
        $pool = array();
        $library = get_posts( array(
                'post_type'        => 'attachment',
                'post_status'      => 'inherit',
                'post_mime_type'   => 'image',
                'posts_per_page'   => 3,
                'fields'           => 'ids',
                'orderby'          => 'ID',
                'order'            => 'ASC',
                'suppress_filters' => true,
                // Jamais les photos du jeu de test precedent : elles partent a la purge.
                'meta_query'       => array(
                        array(
                                'key'     => pk_seed_mark(),
                                'compare' => 'NOT EXISTS',
                        ),
                ),
        ) );
        if ( ! is_wp_error( $library ) && $library ) {
                $pool = array_map( 'absint', $library );
                pk_seed_say( 'Photos reutilisees depuis la mediatheque : ' . count( $pool ) . ' (IDs ' . implode( ', ', $pool ) . ')' );
        }
        if ( count( $pool ) >= 3 ) {
                return $pool;
        }
        if ( ! function_exists( 'imagecreatetruecolor' ) ) {
                pk_seed_say( 'ATTENTION : GD indisponible et mediatheque vide -> annonces sans photos.' );
                return $pool;
        }
        $want = 3 - count( $pool );
        $palettes = array(
                array( 13, 74, 110 ),   // bleu profond.
                array( 109, 40, 103 ),  // pourpre.
                array( 217, 119, 6 ),   // ambre.
        );
        for ( $n = 0; $n < $want; $n++ ) {
                $rgb   = $palettes[ $n % 3 ];
                $img   = imagecreatetruecolor( 1280, 854 );
                $bg    = imagecolorallocate( $img, $rgb[0], $rgb[1], $rgb[2] );
                $white = imagecolorallocate( $img, 255, 255, 255 );
                $soft  = imagecolorallocate( $img, max( 0, $rgb[0] - 30 ), max( 0, $rgb[1] - 30 ), max( 0, $rgb[2] - 20 ) );
                imagefill( $img, 0, 0, $bg );
                // Silhouette d'immeubles : evite une image unie, SEO image plus honnete.
                for ( $b = 0; $b < 5; $b++ ) {
                        $w = 170 + ( $b * 53 ) % 120;
                        $h = 320 + ( $b * 137 ) % 300;
                        imagefilledrectangle( $img, 60 + $b * 230, 854 - $h, 60 + $b * 230 + $w, 854, $soft );
                }
                imagestring( $img, 5, 48, 44, 'PARTIKULIER - PHOTO DE TEST ' . ( count( $pool ) + $n + 1 ), $white );
                imagestring( $img, 5, 48, 76, 'ANNONCE DE TEST - NE PAS CONSERVER EN PRODUCTION', $white );
                imagestring( $img, 3, 48, 108, '1280 x 854 - image dupliquee entre les annonces de test', $white );
                ob_start();
                imagejpeg( $img, null, 88 );
                $data = ob_get_clean();
                imagedestroy( $img );

                if ( ! $apply ) {
                        pk_seed_say( 'Photo GD qui sera generee : pk-photo-test-' . ( $n + 1 ) . '.jpg' );
                        continue;
                }
                $upload = wp_upload_bits( 'pk-photo-test-' . ( $n + 1 ) . '.jpg', null, $data );
                if ( ! empty( $upload['error'] ) ) {
                        pk_seed_say( 'ATTENTION : upload photo impossible (' . $upload['error'] . ').' );
                        continue;
                }
                $att = wp_insert_attachment( array(
                        'post_mime_type' => 'image/jpeg',
                        'post_title'     => 'Photo de test ' . ( $n + 1 ),
                        'post_status'    => 'inherit',
                ), $upload['file'], 0 );
                if ( is_wp_error( $att ) || ! $att ) {
                        continue;
                }
                require_once ABSPATH . 'wp-admin/includes/image.php';
                wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $upload['file'] ) );
                update_post_meta( $att, '_wp_attachment_image_alt', 'Annonce de test Partikulier' );
                update_post_meta( $att, pk_seed_mark(), '1' );
                $pool[] = (int) $att;
        }
        if ( $apply ) {
                pk_seed_say( 'Pool de photos final : ' . count( $pool ) . ' image(s) (IDs ' . implode( ', ', $pool ) . '), dupliquees sur les 10 annonces.' );
        }
        return $pool;
}

pk_seed_photo_pool( false );
pk_seed_say();

// --- Apercu du plan.
pk_seed_say( 'Plan des 10 annonces (source ' . $pk_default . ', puis traductions ' . implode( '/', array_diff( $pk_languages, array( $pk_default ) ) ) . ') :' );
foreach ( $pk_plan as $i => $p ) {
        pk_seed_say( sprintf(
                '  %2d. %-11s %-10s %-11s %4d m2 %11s DH  %s',
                $i + 1,
                $p['type'],
                $p['city'] . '/' . $p['district'],
                ( 'louer' === $p['action'] ? 'LOCATION' : 'VENTE' ),
                $p['surface'],
                number_format_i18n( (float) $p['price'] ),
                ( 'louer' === $p['action'] ? '/ mois' : '' )
        ) );
}
pk_seed_say();

if ( ! $pk_apply ) {
        pk_seed_say( 'Rien n a ete modifie.' );
        pk_seed_say( 'Pour appliquer : PK_APPLIQUER=1 wp eval-file tests/seed-annonces-test.php' );
        pk_seed_say( 'Pour supprimer : PK_PURGER=1 PK_APPLIQUER=1 wp eval-file tests/seed-annonces-test.php' );
        pk_seed_say( 'SAUVEGARDEZ VOTRE BASE DE DONNEES AVANT.' );
        exit;
}

// --- Application : purge de l'ancien jeu AVANT de constituer le pool.
//     Sinon le pool reutilise les photos marquees du jeu precedent, puis la
//     purge les supprime : les annonces vivraient sur des IDs morts (mesure
//     sur banc : vignettes vides, og:image en secours hero, cartes grises).
if ( $pk_old || $pk_old_media ) {
        pk_seed_purge( true );
        pk_seed_say();
}

// --- Photos dupliquees : pool reel, APRES la purge.
$pk_pool = pk_seed_photo_pool( true );
pk_seed_say();

// --- Compte de test unique (role contributeur, comme un depot reel).
$pk_user_email = 'test-annonces@partikulier.local';
$pk_user       = get_user_by( 'email', $pk_user_email );
if ( ! $pk_user ) {
        $pk_user_id = wp_create_user( 'test-annonces', wp_generate_password( 20, true, false ), $pk_user_email );
        if ( is_wp_error( $pk_user_id ) ) {
                pk_seed_say( 'ERREUR : creation du compte de test : ' . $pk_user_id->get_error_message() );
                exit;
        }
        $pk_user = get_user_by( 'id', $pk_user_id );
        $pk_user->set_role( 'contributor' );
        wp_update_user( array( 'ID' => $pk_user_id, 'display_name' => 'Compte de test' ) );
        pk_seed_say( 'Compte de test cree : test-annonces (contributeur).' );
} else {
        pk_seed_say( 'Compte de test existant : ' . $pk_user->user_login . '.' );
}
pk_seed_say();

// --- Creation des annonces.
$pk_results = array();
$pk_errors  = 0;
foreach ( $pk_plan as $i => $p ) {

        // Donnees au format exact du formulaire : le meme moteur de redaction
        // (Partikulier_Listing_Preview::normalize_input) que les vrais depots.
        $data = array(
                'pk_action_mode'     => $p['action'],
                'pk_role'            => 'proprietaire',
                'pk_type'            => (string) $p['type_id'],
                'pk_city_name'       => $p['city'],
                'pk_district_name'   => $p['district'],
                'pk_surface'         => (string) $p['surface'],
                'pk_price'           => (string) $p['price'],
                'pk_bedrooms'        => $p['bedrooms'],
                'pk_living_rooms'    => $p['living'],
                'pk_bathrooms'       => $p['bathrooms'],
                'pk_floor'           => $p['floor'],
                'pk_garage'          => $p['garage'],
                'pk_elevator'        => $p['elevator'],
                'pk_vis_a_vis'       => $p['vis_a_vis'],
                'pk_terrace'         => $p['terrace'],
                'pk_terrace_surface' => (string) $p['terrace_surface'],
                'pk_sunshine'        => '',
        );
        $norm = Partikulier_Listing_Preview::normalize_input( $data );

        $title = Partikulier_Listing_I18n::title( $norm, $pk_default );
        $desc  = Partikulier_Listing_I18n::description( $norm, $pk_default );

        $bedrooms_num = '3+' === $p['bedrooms'] ? 3 : absint( $p['bedrooms'] );
        $living_num   = '3+' === $p['living'] ? 3 : absint( $p['living'] );
        $rooms        = $bedrooms_num + $living_num;

        $post_id = wp_insert_post( array(
                'post_type'    => PARTIKULIER_ESTATIK_POST_TYPE,
                'post_status'  => 'publish',
                'post_author'  => (int) $pk_user->ID,
                'post_title'   => $title,
                'post_content' => $desc,
                'post_excerpt' => wp_trim_words( $desc, 30 ),
        ), true );
        if ( is_wp_error( $post_id ) || ! $post_id ) {
                pk_seed_say( 'ECHEC annonce ' . ( $i + 1 ) . ' : ' . ( is_wp_error( $post_id ) ? $post_id->get_error_message() : 'insertion impossible' ) );
                $pk_errors++;
                continue;
        }

        // Langue AVANT tout le reste : wp_insert_post declenche save_post, ou Polylang
        // attribue la langue par defaut ; et surtout AVANT les termes, car le hook
        // set_object_terms de Polylang traduit les termes dans la langue du post et
        // fabrique des copies suffixees (appartement-en...) si la langue differe.
        if ( function_exists( 'pll_set_post_language' ) ) {
                pll_set_post_language( $post_id, $pk_default );
        }

        // Metas : la copie conforme de class-form.php::process().
        update_post_meta( $post_id, 'es_property_price', (int) $p['price'] );
        update_post_meta( $post_id, 'es_property_area', (int) $p['surface'] );
        if ( $rooms ) {
                update_post_meta( $post_id, 'es_property_total_rooms', $rooms );
        }
        if ( '' !== $p['bedrooms'] ) {
                update_post_meta( $post_id, 'es_property_bedrooms', $bedrooms_num );
                update_post_meta( $post_id, '_pk_bedrooms_label', $p['bedrooms'] );
        }
        if ( '' !== $p['living'] ) {
                update_post_meta( $post_id, '_pk_living_rooms', $living_num );
                update_post_meta( $post_id, '_pk_living_rooms_label', $p['living'] );
        }
        if ( '' !== $p['bathrooms'] ) {
                update_post_meta( $post_id, 'es_property_bathrooms', absint( $p['bathrooms'] ) );
                update_post_meta( $post_id, '_pk_bathrooms_label', $p['bathrooms'] );
        }
        update_post_meta( $post_id, '_pk_terrace', $p['terrace'] );
        if ( $p['terrace_surface'] ) {
                update_post_meta( $post_id, '_pk_terrace_surface', (int) $p['terrace_surface'] );
        }
        update_post_meta( $post_id, '_pk_vis_a_vis', $p['vis_a_vis'] );
        if ( '' !== $p['floor'] ) {
                update_post_meta( $post_id, '_pk_floor', $p['floor'] );
        }
        update_post_meta( $post_id, '_pk_garage', $p['garage'] );
        update_post_meta( $post_id, '_pk_elevator', $p['elevator'] );
        update_post_meta( $post_id, '_pk_city_name', $p['city'] );
        update_post_meta( $post_id, '_pk_district_name', $p['district'] );
        update_post_meta( $post_id, '_pk_owner_name', 'Compte de test' );
        update_post_meta( $post_id, '_pk_owner_email', $pk_user_email );
        update_post_meta( $post_id, '_pk_owner_phone', '+212 600 000 00' . str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ) );
        update_post_meta( $post_id, '_pk_owner_role', 'proprietaire' );
        update_post_meta( $post_id, '_pk_views', 0 );
        update_post_meta( $post_id, '_pk_meta_description', Partikulier_Listing_I18n::meta_description( $norm, $pk_default ) );
        if ( class_exists( 'Partikulier_WhatsApp_Verification' ) ) {
                update_post_meta( $post_id, '_pk_status', Partikulier_WhatsApp_Verification::STATUS_PENDING );
                if ( method_exists( 'Partikulier_WhatsApp_Verification', 'create_pending' ) ) {
                        Partikulier_WhatsApp_Verification::create_pending( $post_id );
                }
        }

        // Taxonomies canoniques (post deja en fr, termes en fr : aucun doublon cree).
        wp_set_object_terms( $post_id, (int) $p['type_id'], PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
        wp_set_object_terms( $post_id, (int) $p['status_id'], PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
        wp_set_object_terms( $post_id, (int) $p['city_id'], PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );

        update_post_meta( $post_id, Partikulier_Listing_Translations::META_SOURCE_LANG, $pk_default );

        // Photos dupliquees : 3 prises cycliques dans le pool (IDs partages).
        if ( $pk_pool ) {
                $gallery = array();
                $n       = count( $pk_pool );
                for ( $k = 0; $k < min( 3, $n ); $k++ ) {
                        $gallery[] = (int) $pk_pool[ ( $i + $k ) % $n ];
                }
                update_post_meta( $post_id, 'es_property_gallery', $gallery );
                set_post_thumbnail( $post_id, $gallery[0] );
        }

        // Marque de test (presente aussi sur les traductions ci-dessous).
        update_post_meta( $post_id, pk_seed_mark(), '1' );

        // Traductions EN/AR via le pipeline du theme (titre, description,
        // metas, photo, termes traduits, lien Polylang -> hreflang).
        $map = Partikulier_Listing_Translations::sync( $post_id, $norm, $pk_default, '' );
        if ( count( $map ) > 1 ) {
                Partikulier_Listing_Translations::sync_status( $post_id, 'publish' );
                foreach ( $map as $lang => $tid ) {
                        update_post_meta( (int) $tid, pk_seed_mark(), '1' );
                }
        } else {
                pk_seed_say( 'ATTENTION : aucune traduction creee pour l annonce ' . ( $i + 1 ) . '.' );
                $pk_errors++;
                $map = array( $pk_default => $post_id );
        }

        // Coordonnees geographiques (es_latitude/es_longitude, lues par
        // class-jsonld.php pour GeoCoordinates) : posees sur TOUTES les langues
        // ici car copy_data() ne transporte pas ces cles — cf. docblock du jeu.
        foreach ( $map as $lang => $tid ) {
                update_post_meta( (int) $tid, 'es_latitude', (float) $p['lat'] );
                update_post_meta( (int) $tid, 'es_longitude', (float) $p['lng'] );
        }

        // Validation WhatsApp simulee, comme le bouton admin « Valider et publier »
        // (class-whatsapp-verification.php) : sans _pk_status = actif, la requete du
        // catalogue masque l annonce (mesuree sur banc : 0 carte malgre 31 posts).
        foreach ( $map as $lang => $tid ) {
                update_post_meta( (int) $tid, '_pk_status', 'actif' );
                update_post_meta( (int) $tid, '_pk_whatsapp_verified_at', current_time( 'mysql', true ) );
                update_post_meta( (int) $tid, '_pk_whatsapp_verified_by', (int) get_current_user_id() ?: 1 );
        }

        $pk_results[] = array( 'index' => $i + 1, 'source' => $post_id, 'map' => $map, 'title' => $title );
        pk_seed_say( '[' . str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ) . '] ID ' . $post_id . ' : ' . $title );
}

// --- Permaliens + cache : les nouvelles URL existent immediatement.
flush_rewrite_rules( false );
if ( class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
        Partikulier_Cache::purge_all();
}

// --- Rapport final.
pk_seed_say();
pk_seed_say( str_repeat( '=', 48 ) );
pk_seed_say( ' RAPPORT' );
pk_seed_say( str_repeat( '=', 48 ) );
$pk_total = 0;
foreach ( $pk_languages as $lang ) {
        $count = count( get_posts( array(
                'post_type'      => PARTIKULIER_ESTATIK_POST_TYPE,
                'post_status'    => 'publish',
                'fields'         => 'ids',
                'posts_per_page' => -1,
                'lang'           => $lang,
                'meta_key'       => pk_seed_mark(),
                'meta_value'     => '1',
        ) ) );
        $pk_total += $count;
        pk_seed_say( 'Annonces de test publiees en ' . $lang . ' : ' . $count );
}
pk_seed_say( 'Echecs : ' . $pk_errors );
pk_seed_say();
pk_seed_say( 'URLs de verification (remplacer le domaine par le votre) :' );
$pk_archives = array();
foreach ( $pk_languages as $lang ) {
        $base = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url( $lang ) : home_url( '/' );
        $pk_archives[ $lang ] = $base . 'annonces/';
}
foreach ( $pk_archives as $lang => $url ) {
        pk_seed_say( '  catalogue ' . $lang . ' : ' . $url );
}
$pk_filter_base = isset( $pk_archives[ $pk_default ] ) ? $pk_archives[ $pk_default ] : home_url( '/annonces/' );
pk_seed_say( '  filtres    : ' . $pk_filter_base . '?es_city=casablanca' );
pk_seed_say( '               ' . $pk_filter_base . '?es_type=villa' );
pk_seed_say( '               ' . $pk_filter_base . '?es_action=a-louer' );
pk_seed_say();
pk_seed_say( 'Fiches par langue (source puis traductions) :' );
foreach ( $pk_results as $r ) {
        pk_seed_say( '  ' . str_pad( (string) $r['index'], 2, '0', STR_PAD_LEFT ) . '. ' . $r['title'] );
        foreach ( $r['map'] as $lang => $tid ) {
                pk_seed_say( '        ' . strtoupper( $lang ) . ' ' . get_permalink( (int) $tid ) );
        }
}
pk_seed_say();
pk_seed_say( 'Termine. Supprimez ce fichier du serveur une fois les tests faits,' );
pk_seed_say( 'ou relancez avec PK_PURGER=1 PK_APPLIQUER=1 pour retirer le jeu complet.' );
