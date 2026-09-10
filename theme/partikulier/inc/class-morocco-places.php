<?php
/**
 * Module : referentiel des villes et quartiers du Maroc.
 *
 * Sert l'autocompletion du formulaire de depot :
 *  - on tape une lettre  -> suggestions de villes commencant par cette lettre ;
 *  - on choisit la ville -> la liste de ses quartiers devient disponible.
 *
 * Le referentiel integre est fusionne avec les termes deja presents dans la
 * taxonomie es_location du site, pour que les lieux crees par le client
 * remontent aussi dans les suggestions.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Morocco_Places {

        /**
         * Action AJAX publique de recherche de lieux.
         */
        const AJAX_ACTION = 'pk_places_search';

        /**
         * Referentiel : ville => quartiers.
         * Liste volontairement centree sur les villes ou le marche est actif.
         *
         * @return array<string, string[]>
         */
        public static function reference() {
                return array(
                        'Casablanca'    => array( 'Maarif', 'Gauthier', 'Anfa', 'Ain Diab', 'Bourgogne', 'Racine', 'Californie', 'Oasis', 'Sidi Maarouf', 'Ain Sebaa', 'Hay Hassani', 'Derb Sultan', 'Belvédère', 'CIL', 'Beauséjour', 'Sidi Bernoussi', 'Mers Sultan', 'Habous' ),
                        'Rabat'         => array( 'Agdal', 'Hay Riad', 'Hassan', 'Souissi', 'Les Orangers', 'Yacoub El Mansour', 'Médina', 'Océan', 'Aviation', 'Témara', 'Akkari', 'El Menzeh' ),
                        'Marrakech'     => array( 'Guéliz', 'Hivernage', 'Médina', 'Palmeraie', 'Targa', 'Semlalia', 'Massira', 'Route de Fès', 'Agdal', 'Amerchich', 'Sidi Ghanem', 'M’Hamid' ),
                        'Tanger'        => array( 'Malabata', 'Centre-ville', 'Marshan', 'Iberia', 'Achakar', 'Boubana', 'Branes', 'Souani', 'Cap Spartel', 'Val Fleuri', 'Mesnana' ),
                        'Fès'           => array( 'Médina', 'Ville Nouvelle', 'Atlas', 'Saiss', 'Narjiss', 'Montfleuri', 'Zouagha', 'Route d’Immouzer', 'Agdal', 'Jnan Adarissa' ),
                        'Agadir'        => array( 'Founty', 'Talborjt', 'Hay Mohammadi', 'Charaf', 'Dakhla', 'Anza', 'Sonaba', 'Cité Suisse', 'Illigh', 'Tikiouine' ),
                        'Meknès'        => array( 'Hamria', 'Médina', 'Marjane', 'Bassatine', 'Riad', 'Ville Nouvelle', 'Toulal', 'Sidi Bouzekri' ),
                        'Oujda'         => array( 'Centre-ville', 'Hay Al Qods', 'Sidi Yahya', 'Al Andalous', 'Lazaret', 'Hay Salam' ),
                        'Kénitra'       => array( 'Maamora', 'Bir Rami', 'Ouled Oujih', 'Val Fleuri', 'Mimosas', 'Saknia' ),
                        'Tétouan'       => array( 'Centre-ville', 'Martil', 'Cabo Negro', 'M’Diq', 'Touilaa', 'Sania Ramel' ),
                        'Salé'          => array( 'Hay Karima', 'Tabriquet', 'Bettana', 'Sala Al Jadida', 'Hay Salam', 'Laayayda' ),
                        'Mohammedia'    => array( 'Centre-ville', 'Alia', 'Kasbah', 'Parc', 'Hassania', 'El Wahda' ),
                        'El Jadida'     => array( 'Centre-ville', 'Cité Portugaise', 'Sidi Bouzid', 'Hay Salam', 'Essalam' ),
                        'Essaouira'     => array( 'Médina', 'Borj', 'Ghazoua', 'Diabat', 'Quartier des Dunes' ),
                        'Beni Mellal'   => array( 'Centre-ville', 'Ouled Hamdane', 'Hay Al Massira', 'Riad Salam' ),
                        'Nador'         => array( 'Centre-ville', 'Ihaddadene', 'Al Aroui', 'Selouane' ),
                        'Ifrane'        => array( 'Centre-ville', 'Hay Riad', 'Timdiqine', 'Zaouiat' ),
                        'Ouarzazate'    => array( 'Centre-ville', 'Tabounte', 'Hay El Wahda', 'Sidi Daoud' ),
                        'Safi'          => array( 'Centre-ville', 'Biada', 'Jerifat', 'Trab Lahjar' ),
                        'Dakhla'        => array( 'Centre-ville', 'Hay El Massira', 'Moulay Rachid' ),
                        'Laâyoune'      => array( 'Centre-ville', 'Hay Essalam', 'Colomina Nueva' ),
                        'Berrechid'     => array( 'Centre-ville', 'Hay Al Amal', 'Riad' ),
                        'Settat'        => array( 'Centre-ville', 'Hay Salam', 'Riad' ),
                        'Khouribga'     => array( 'Centre-ville', 'Hay Al Amal', 'Sidi Chennane' ),
                        'Taza'          => array( 'Centre-ville', 'Koucha', 'Hay Ennahda' ),
                        'Larache'       => array( 'Centre-ville', 'Ksar El Kebir', 'Hay Essalam' ),
                        'Al Hoceima'    => array( 'Centre-ville', 'Ajdir', 'Calabonita' ),
                        'Chefchaouen'   => array( 'Médina', 'Andalous', 'Sidi Bouzra' ),
                        'Bouznika'      => array( 'Centre-ville', 'Plage', 'Bouznika Bay' ),
                        'Skhirat'       => array( 'Centre-ville', 'Plage', 'Témara' ),
                );
        }

        public static function init() {
                add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_search' ) );
                add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'handle_search' ) );
        }

        /**
         * Normalise une chaine pour comparaison : minuscules, sans accents.
         *
         * @param string $value Chaine a normaliser.
         * @return string
         */
        public static function normalize( $value ) {
                $value = remove_accents( (string) $value );
                $value = function_exists( 'mb_strtolower' ) ? mb_strtolower( $value ) : strtolower( $value );

                return trim( $value );
        }

        /**
         * Villes du referentiel completees par les termes es_location du site.
         *
         * @return array<string, string[]>
         */
        public static function all_places() {
                $places = self::reference();

                $terms = get_terms( array(
                        'taxonomy'   => PARTIKULIER_ESTATIK_LOCATION_TAXONOMY,
                        'hide_empty' => false,
                ) );

                if ( is_wp_error( $terms ) || ! $terms ) {
                        return $places;
                }

                $known = array();
                foreach ( array_keys( $places ) as $city ) {
                        $known[ self::normalize( $city ) ] = $city;
                }

                foreach ( $terms as $term ) {
                        $key = self::normalize( $term->name );

                        // Terme deja connu comme ville : on ne duplique pas.
                        if ( isset( $known[ $key ] ) ) {
                                continue;
                        }

                        // Terme deja connu comme quartier d'une ville : on ne duplique pas.
                        $is_district = false;
                        foreach ( $places as $districts ) {
                                foreach ( $districts as $district ) {
                                        if ( self::normalize( $district ) === $key ) {
                                                $is_district = true;
                                                break 2;
                                        }
                                }
                        }

                        if ( ! $is_district ) {
                                $places[ $term->name ] = isset( $places[ $term->name ] ) ? $places[ $term->name ] : array();
                        }
                }

                return $places;
        }

        /**
         * Cherche des villes dont le nom commence par la saisie (puis contient).
         *
         * @param string $query Saisie utilisateur.
         * @param int    $limit Nombre maximum de resultats.
         * @return array<int, array{city:string,district:string,label:string}>
         */
        public static function search_cities( $query, $limit = 8 ) {
                $needle = self::normalize( $query );
                $places = self::all_places();
                $starts = array();
                $contains = array();

                foreach ( array_keys( $places ) as $city ) {
                        $haystack = self::normalize( $city );
                        if ( '' === $needle || 0 === strpos( $haystack, $needle ) ) {
                                $starts[] = $city;
                        } elseif ( false !== strpos( $haystack, $needle ) ) {
                                $contains[] = $city;
                        }
                }

                sort( $starts );
                sort( $contains );

                // Les villes qui COMMENCENT par la saisie priment toujours ; celles qui
                // la contiennent ne servent que de complement si la place le permet.
                $results = array_slice( $starts, 0, $limit );
                if ( count( $results ) < $limit ) {
                        $results = array_merge( $results, array_slice( $contains, 0, $limit - count( $results ) ) );
                }

                return array_map(
                        static function ( $city ) {
                                return array(
                                        'city'     => $city,
                                        'district' => '',
                                        'label'    => $city,
                                        'meta'     => __( 'Ville', 'partikulier' ),
                                );
                        },
                        $results
                );
        }

        /**
         * Cherche directement un quartier, toutes villes confondues.
         *
         * @param string $query Saisie utilisateur.
         * @param int    $limit Nombre maximum de resultats.
         * @return array
         */
        public static function search_districts( $query, $limit = 8 ) {
                $needle = self::normalize( $query );
                if ( '' === $needle ) {
                        return array();
                }

                $results = array();
                foreach ( self::all_places() as $city => $districts ) {
                        foreach ( $districts as $district ) {
                                $haystack = self::normalize( $district );
                                if ( 0 === strpos( $haystack, $needle ) || false !== strpos( $haystack, $needle ) ) {
                                        $results[] = array(
                                                'city'     => $city,
                                                'district' => $district,
                                                'label'    => $district,
                                                'meta'     => $city,
                                        );
                                }
                                if ( count( $results ) >= $limit ) {
                                        return $results;
                                }
                        }
                }

                return $results;
        }

        /**
         * Quartiers d'une ville donnee, filtres par une saisie optionnelle.
         *
         * @param string $city  Nom de la ville.
         * @param string $query Filtre optionnel.
         * @param int    $limit Nombre maximum de resultats.
         * @return array
         */
        public static function districts_of( $city, $query = '', $limit = 40 ) {
                $places = self::all_places();
                $target = self::normalize( $city );
                $found  = array();

                foreach ( $places as $name => $districts ) {
                        if ( self::normalize( $name ) === $target ) {
                                $found = $districts;
                                break;
                        }
                }

                $needle  = self::normalize( $query );
                $results = array();
                foreach ( $found as $district ) {
                        if ( '' !== $needle && false === strpos( self::normalize( $district ), $needle ) ) {
                                continue;
                        }
                        $results[] = array(
                                'city'     => $city,
                                'district' => $district,
                                'label'    => $district,
                                'meta'     => $city,
                        );
                        if ( count( $results ) >= $limit ) {
                                break;
                        }
                }

                return $results;
        }

        /**
         * Point d'entree AJAX : renvoie les suggestions au formulaire.
         */
        public static function handle_search() {
                /*
                 * 6.17.29 — plus de garde nonce qui tue la requete ici.
                 * Ce point d'entree ne sert que des donnees PUBLIQUES en lecture
                 * seule : le referentiel villes/quartiers integre au theme (livre
                 * dans ses sources) et les termes es_location existants. Aucune
                 * donnee utilisateur, aucune ecriture.
                 * L'ancienne garde (check_ajax_referer) cassait l'autocompletion
                 * en silence sur les pages mises en cache : LiteSpeed/HCDN sert
                 * le HTML public jusqu'a 12 h, or un nonce WordPress vit 12-24 h
                 * — marge nulle. Un visiteur arrivant sur une copie cachee dont
                 * le nonce a expire recevait [] sans aucune erreur (le JS avale
                 * tout), alors que /deposer/ (jamais cachee) marchait : le bug
                 * « la recherche du header ne donne rien » exactement.
                 * sanitize + liste blanche du scope restent en place.
                 */
                $query = isset( $_GET['q'] ) ? sanitize_text_field( wp_unslash( $_GET['q'] ) ) : '';
                $city  = isset( $_GET['city'] ) ? sanitize_text_field( wp_unslash( $_GET['city'] ) ) : '';
                $scope = isset( $_GET['scope'] ) ? sanitize_key( wp_unslash( $_GET['scope'] ) ) : 'city';
                $lang  = isset( $_GET['lang'] ) ? sanitize_key( wp_unslash( $_GET['lang'] ) ) : ( function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr' );

                if ( 'district' === $scope ) {
                        $results = self::districts_of( $city, $query );
                } else {
                        // On propose d'abord les villes, puis les quartiers correspondants.
                        $results = array_merge(
                                self::search_cities( $query, 6 ),
                                self::search_districts( $query, 4 )
                        );
                }

                $results = array_map(
                        static function ( $item ) use ( $lang ) {
                                $city     = isset( $item['city'] ) ? (string) $item['city'] : '';
                                $district = isset( $item['district'] ) ? (string) $item['district'] : '';
                                $term_id  = $city ? self::find_existing_term( $city, $district ) : 0;
                                if ( $term_id && function_exists( 'pll_get_term' ) ) {
                                        $translated_id = (int) pll_get_term( $term_id, $lang );
                                        $term_id       = $translated_id ?: $term_id;
                                }
                                $term = $term_id ? get_term( $term_id, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) : false;
                                $item['value'] = ( $term && ! is_wp_error( $term ) ) ? $term->slug : sanitize_title( $district ?: $city );
                                if ( 'ar' === $lang && class_exists( 'Partikulier_Listing_I18n' ) ) {
                                        $item['label'] = Partikulier_Listing_I18n::localized_place( $district ?: $city, $lang );
                                        $item['meta']  = $district ? Partikulier_Listing_I18n::localized_place( $city, $lang ) : __( 'Ville', 'partikulier' );
                                }
                                return $item;
                        },
                        array_values( $results )
                );
                wp_send_json_success( array( 'results' => $results ) );
        }

        /**
         * Retrouve un terme es_location EXISTANT, sans jamais en creer.
         * Utilise a la soumission : un lieu inconnu doit passer par la moderation.
         *
         * @param string $city     Nom de la ville.
         * @param string $district Nom du quartier (prioritaire s'il existe).
         * @return int ID du terme le plus precis trouve, 0 sinon.
         */
        public static function find_existing_term( $city, $district = '' ) {
                $taxonomy = PARTIKULIER_ESTATIK_LOCATION_TAXONOMY;
                $terms    = get_terms( array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                ) );

                if ( is_wp_error( $terms ) || ! $terms ) {
                        return 0;
                }

                $want_district = self::normalize( $district );
                $want_city     = self::normalize( $city );
                $city_id       = 0;

                foreach ( $terms as $term ) {
                        $name = self::normalize( $term->name );
                        if ( '' !== $want_district && $name === $want_district ) {
                                return (int) $term->term_id;
                        }
                        if ( '' !== $want_city && $name === $want_city ) {
                                $city_id = (int) $term->term_id;
                        }
                }

                return $city_id;
        }

        /**
         * Retourne (en le creant au besoin) le terme es_location du lieu choisi.
         * Le quartier est cree comme enfant de la ville quand la taxonomie le permet.
         *
         * @param string $city     Nom de la ville.
         * @param string $district Nom du quartier (optionnel).
         * @return int ID du terme le plus precis, 0 si echec.
         */
        public static function ensure_location_term( $city, $district = '' ) {
                $taxonomy = PARTIKULIER_ESTATIK_LOCATION_TAXONOMY;
                $city     = trim( (string) $city );
                $district = trim( (string) $district );

                if ( '' === $city && '' === $district ) {
                        return 0;
                }

                $city_id = 0;
                if ( '' !== $city ) {
                        $city_id = self::find_or_create_term( $city, $taxonomy, 0 );
                }

                if ( '' === $district ) {
                        return $city_id;
                }

                $parent = is_taxonomy_hierarchical( $taxonomy ) ? $city_id : 0;

                return self::find_or_create_term( $district, $taxonomy, $parent );
        }

        /**
         * Recherche un terme par nom (insensible aux accents) sinon le cree.
         *
         * @param string $name     Nom du terme.
         * @param string $taxonomy Taxonomie cible.
         * @param int    $parent   Terme parent eventuel.
         * @return int
         */
        private static function find_or_create_term( $name, $taxonomy, $parent = 0 ) {
                $needle   = self::normalize( $name );
                $existing = get_terms( array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                ) );

                if ( ! is_wp_error( $existing ) ) {
                        foreach ( $existing as $term ) {
                                if ( self::normalize( $term->name ) === $needle ) {
                                        return (int) $term->term_id;
                                }
                        }
                }

                $args = array();
                if ( $parent ) {
                        $args['parent'] = $parent;
                }

                $created = wp_insert_term( $name, $taxonomy, $args );
                if ( is_wp_error( $created ) ) {
                        // Course possible : le terme vient d'etre cree ailleurs.
                        $term = get_term_by( 'name', $name, $taxonomy );

                        return $term ? (int) $term->term_id : 0;
                }

                return (int) $created['term_id'];
        }
}

Partikulier_Morocco_Places::init();
