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
					'Casablanca'  => array( 'Maarif', 'Gauthier', 'Anfa', 'Ain Diab', 'Bourgogne', 'Racine', 'Californie', 'Oasis', 'Sidi Maarouf', 'Ain Sebaa', 'Hay Hassani', 'Derb Sultan', 'Belvédère', 'CIL', 'Beauséjour', 'Sidi Bernoussi', 'Mers Sultan', 'Habous' ),
					'Rabat'       => array( 'Agdal', 'Hay Riad', 'Hassan', 'Souissi', 'Les Orangers', 'Yacoub El Mansour', 'Médina', 'Océan', 'Aviation', 'Témara', 'Akkari', 'El Menzeh' ),
					'Marrakech'   => array( 'Guéliz', 'Hivernage', 'Médina', 'Palmeraie', 'Targa', 'Semlalia', 'Massira', 'Route de Fès', 'Agdal', 'Amerchich', 'Sidi Ghanem', 'M’Hamid' ),
					'Tanger'      => array( 'Malabata', 'Centre-ville', 'Marshan', 'Iberia', 'Achakar', 'Boubana', 'Branes', 'Souani', 'Cap Spartel', 'Val Fleuri', 'Mesnana' ),
					'Fès'         => array( 'Médina', 'Ville Nouvelle', 'Atlas', 'Saiss', 'Narjiss', 'Montfleuri', 'Zouagha', 'Route d’Immouzer', 'Agdal', 'Jnan Adarissa' ),
					'Agadir'      => array( 'Founty', 'Talborjt', 'Hay Mohammadi', 'Charaf', 'Dakhla', 'Anza', 'Sonaba', 'Cité Suisse', 'Illigh', 'Tikiouine' ),
					'Meknès'      => array( 'Hamria', 'Médina', 'Marjane', 'Bassatine', 'Riad', 'Ville Nouvelle', 'Toulal', 'Sidi Bouzekri' ),
					'Oujda'       => array( 'Centre-ville', 'Hay Al Qods', 'Sidi Yahya', 'Al Andalous', 'Lazaret', 'Hay Salam' ),
					'Kénitra'     => array( 'Maamora', 'Bir Rami', 'Ouled Oujih', 'Val Fleuri', 'Mimosas', 'Saknia' ),
					'Tétouan'     => array( 'Centre-ville', 'Martil', 'Cabo Negro', 'M’Diq', 'Touilaa', 'Sania Ramel' ),
					'Salé'        => array( 'Hay Karima', 'Tabriquet', 'Bettana', 'Sala Al Jadida', 'Hay Salam', 'Laayayda' ),
					'Mohammedia'  => array( 'Centre-ville', 'Alia', 'Kasbah', 'Parc', 'Hassania', 'El Wahda' ),
					'El Jadida'   => array( 'Centre-ville', 'Cité Portugaise', 'Sidi Bouzid', 'Hay Salam', 'Essalam' ),
					'Essaouira'   => array( 'Médina', 'Borj', 'Ghazoua', 'Diabat', 'Quartier des Dunes' ),
					'Beni Mellal' => array( 'Centre-ville', 'Ouled Hamdane', 'Hay Al Massira', 'Riad Salam' ),
					'Nador'       => array( 'Centre-ville', 'Ihaddadene', 'Al Aroui', 'Selouane' ),
					'Ifrane'      => array( 'Centre-ville', 'Hay Riad', 'Timdiqine', 'Zaouiat' ),
					'Ouarzazate'  => array( 'Centre-ville', 'Tabounte', 'Hay El Wahda', 'Sidi Daoud' ),
					'Safi'        => array( 'Centre-ville', 'Biada', 'Jerifat', 'Trab Lahjar' ),
					'Dakhla'      => array( 'Centre-ville', 'Hay El Massira', 'Moulay Rachid' ),
					'Laâyoune'    => array( 'Centre-ville', 'Hay Essalam', 'Colomina Nueva' ),
					'Berrechid'   => array( 'Centre-ville', 'Hay Al Amal', 'Riad' ),
					'Settat'      => array( 'Centre-ville', 'Hay Salam', 'Riad' ),
					'Khouribga'   => array( 'Centre-ville', 'Hay Al Amal', 'Sidi Chennane' ),
					'Taza'        => array( 'Centre-ville', 'Koucha', 'Hay Ennahda' ),
					'Larache'     => array( 'Centre-ville', 'Ksar El Kebir', 'Hay Essalam' ),
					'Al Hoceima'  => array( 'Centre-ville', 'Ajdir', 'Calabonita' ),
					'Chefchaouen' => array( 'Médina', 'Andalous', 'Sidi Bouzra' ),
					'Bouznika'    => array( 'Centre-ville', 'Plage', 'Bouznika Bay' ),
					'Skhirat'     => array( 'Centre-ville', 'Plage', 'Témara' ),
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
					'lang'       => 'all',
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

				// Import duplique par Polylang (« Casablanca-fr ») : meme lieu, on ne le
				// propose pas deux fois dans la liste.
				$cle_sans_langue = preg_replace( '/-(?:fr|en|ar)$/', '', $key );

				// Terme deja connu comme ville : on ne duplique pas.
			if ( isset( $known[ $key ] ) || ( $cle_sans_langue !== $key && isset( $known[ $cle_sans_langue ] ) ) ) {
					continue;
			}

				// Terme deja connu comme quartier d'une ville : on ne duplique pas.
				$is_district = false;
			foreach ( $places as $districts ) {
				foreach ( $districts as $district ) {
					if ( self::normalize( $district ) === $key || ( $cle_sans_langue !== $key && self::normalize( $district ) === $cle_sans_langue ) ) {
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
		 * Normalise une chaîne arabe : unifie Alif, Ta Marbouta, Ya et retire Tashkeel.
		 *
		 * @param string $value Chaîne à normaliser.
		 * @return string
		 */
	public static function normalize_arabic( $value ) {
			$value = trim( (string) $value );
			// Alif : أ, إ, آ, ٱ -> ا
			$value = preg_replace( '/[أإآٱ]/u', 'ا', $value );
			// Ta Marbouta : ة -> ه
			$value = preg_replace( '/ة/u', 'ه', $value );
			// Alif Maqsura : ى -> ي
			$value = preg_replace( '/ى/u', 'ي', $value );
			// Diacritiques arabes / Tashkeel
			$value = preg_replace( '/[\x{064B}-\x{065F}\x{0670}]/u', '', $value );

			return $value;
	}

		/**
		 * Retire l'article défini arabe « ال » en début de mot.
		 *
		 * @param string $value Chaîne arabe.
		 * @return string
		 */
	public static function strip_alif_lam( $value ) {
			$norm = self::normalize_arabic( $value );
			if ( mb_substr( $norm, 0, 2 ) === 'ال' && mb_strlen( $norm ) > 2 ) {
					return mb_substr( $norm, 2 );
			}

			return $norm;
	}

		/**
		 * Détermine la qualité de correspondance entre une recherche et un lieu
		 * (en français et en arabe, avec tolérance à l'article « ال »).
		 *
		 * @param string $needle   Recherche saisie.
		 * @param string $place_fr Nom français du lieu.
		 * @return int 0 = aucun match, 1 = match sous-chaîne, 2 = match préfixe.
		 */
	public static function match_place( $needle, $place_fr ) {
			if ( '' === $needle ) {
					return 2;
			}
			$needle_clean = self::normalize( $needle );
			$fr_clean     = self::normalize( $place_fr );

			// 1. Alias usuels marocains
			$aliases = array(
					'casablanca' => array( 'casa', 'كازا' ),
					'marrakech'  => array( 'kech', 'مراكش' ),
					'hivernage'  => array( 'ليفيرناج', 'هيفيرناج', 'ايفرناج', 'شتوي', 'الشتوي' ),
					'gueliz'     => array( 'جليز', 'جيليز', 'كيليز' ),
					'gauthier'   => array( 'غوتييه', 'جوتيه' ),
					'racine'     => array( 'راسين' ),
					'bourgogne'  => array( 'بورغون', 'بوركون' ),
					'agdal'      => array( 'اكدال', 'أكدال' ),
					'palmeraie'  => array( 'النخيل', 'نخيل', 'بالميري' ),
			);
			$key = self::normalize( $place_fr );
			if ( isset( $aliases[ $key ] ) ) {
					foreach ( $aliases[ $key ] as $alias ) {
							$alias_norm = self::normalize_arabic( $alias );
							$needle_ar  = self::normalize_arabic( $needle );
							if ( $needle_clean === $alias || $needle_ar === $alias_norm || false !== strpos( $needle_clean, $alias ) || false !== mb_strpos( $needle_ar, $alias_norm ) ) {
									return 2;
							}
					}
			}

			// 2. Correspondance directe en alphabet latin
			if ( 0 === strpos( $fr_clean, $needle_clean ) ) {
					return 2;
			}
			if ( false !== strpos( $fr_clean, $needle_clean ) ) {
					return 1;
			}

			// 2b. Saisie latine approximative (fautes de frappe) : une fenêtre
			// du nom proche de la saisie suffit. Revue user 08/10 : « casabla »
			// doit proposer الدار البيضاء côté AR (le slug du terme suit).
			if ( strlen( $needle_clean ) >= 4 && function_exists( 'levenshtein' ) ) {
				$window = substr( $fr_clean, 0, strlen( $needle_clean ) + 1 );
				if ( levenshtein( $needle_clean, $window ) <= 2 ) {
					return 1;
				}
			}

			// 3. Correspondance en alphabet arabe (avec ou sans « ال »)
			$place_ar = class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::localized_place( $place_fr, 'ar' ) : '';
			if ( '' === $place_ar || $place_ar === $place_fr ) {
					return 0;
			}

			$needle_ar       = self::normalize_arabic( $needle );
			$needle_ar_no_al = self::strip_alif_lam( $needle );
			$target_ar       = self::normalize_arabic( $place_ar );
			$target_ar_no_al = self::strip_alif_lam( $place_ar );

			if ( 0 === mb_strpos( $target_ar, $needle_ar ) ) {
					return 2;
			}
			if ( false !== mb_strpos( $target_ar, $needle_ar ) ) {
					return 1;
			}

			if ( '' !== $needle_ar_no_al ) {
					if ( 0 === mb_strpos( $target_ar_no_al, $needle_ar_no_al ) ) {
							return 2;
					}
					if ( false !== mb_strpos( $target_ar_no_al, $needle_ar_no_al ) ) {
							return 1;
					}
					if ( false !== mb_strpos( $target_ar, $needle_ar_no_al ) ) {
							return 1;
					}
			}

			return 0;
	}

		/**
		 * Cherche des villes dont le nom commence par la saisie (puis contient).
		 *
		 * @param string $query Saisie utilisateur.
		 * @param int    $limit Nombre maximum de resultats.
		 * @return array<int, array{city:string,district:string,label:string}>
		 */
	public static function search_cities( $query, $limit = 8 ) {
			$needle   = trim( (string) $query );
			$places   = self::all_places();
			$starts   = array();
			$contains = array();

		foreach ( array_keys( $places ) as $city ) {
				$score = self::match_place( $needle, $city );
				if ( 2 === $score ) {
						$starts[] = $city;
				} elseif ( 1 === $score ) {
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
			$needle = trim( (string) $query );
		if ( '' === $needle ) {
				return array();
		}

			$results = array();
		foreach ( self::all_places() as $city => $districts ) {
			foreach ( $districts as $district ) {
					$score = self::match_place( $needle, $district );
					if ( $score > 0 ) {
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

			$needle  = trim( (string) $query );
			$results = array();
		foreach ( $found as $district ) {
			if ( '' !== $needle && 0 === self::match_place( $needle, $district ) ) {
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
							$term          = $term_id ? get_term( $term_id, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) : false;
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
		 * Execute un bloc de code en levant le filtre de langue de Polylang.
		 *
		 * WordPress valide chaque terme avec term_exists() AUSSI dans
		 * wp_set_object_terms() : un lieu sans langue est alors ignore en silence,
		 * l'annonce est creee sans ville ni type. Meme chose pour get_term_by() qui
		 * retrouve le statut (vente / location). On force donc « toutes les langues »
		 * le temps de l'operation, puis on rend la main a Polylang.
		 *
		 * @param callable $callback Code a executer.
		 * @return mixed Resultat du callback.
		 */
	public static function with_all_languages( $callback ) {
		$forcer_requete    = static function ( $args ) {
			$args['lang'] = 'all';
			return $args;
		};
		$forcer_existence  = static function ( $defaults ) {
			$defaults['lang'] = 'all';
			return $defaults;
		};

		add_filter( 'get_terms_args', $forcer_requete, 99 );
		add_filter( 'term_exists_default_query_args', $forcer_existence, 99 );
		$resultat = call_user_func( $callback );
		remove_filter( 'get_terms_args', $forcer_requete, 99 );
		remove_filter( 'term_exists_default_query_args', $forcer_existence, 99 );

		return $resultat;
	}

		/**
		 * Vrai si le terme existe dans la taxonomie, quelle que soit sa langue.
		 *
		 * Polylang filtre get_terms(), get_term_by() ET term_exists() sur la langue
		 * courante : un lieu importe sans langue (« Casablanca » #79) devient alors
		 * « inexistant » et le depot est refuse au message « Choisissez une ville ou
		 * un quartier dans la liste proposee. ». On interroge donc sans filtre de
		 * langue, puis en SQL brut en dernier recours (meme parade que les filtres
		 * de recherche, cf. class-search-filters.php).
		 *
		 * @param int|string $term     Identifiant ou nom du terme.
		 * @param string     $taxonomy Taxonomie cible.
		 * @return bool
		 */
	public static function term_exists_any_language( $term, $taxonomy ) {
		$term = is_string( $term ) ? trim( (string) $term ) : $term;

		if ( '' === $term || 0 === $term || '0' === $term ) {
			return false;
		}

		$args = array(
			'taxonomy'   => $taxonomy,
			'hide_empty' => false,
			'number'     => 1,
			'lang'       => 'all',
		);
		if ( is_numeric( $term ) ) {
			$args['include'] = array( (int) $term );
		} else {
			$args['name'] = $term;
		}

		$trouves = get_terms( $args );
		if ( ! is_wp_error( $trouves ) && $trouves ) {
			return true;
		}

		// Dernier recours : lecture SQL brute, sans aucun filtre de langue.
		global $wpdb;
		if ( is_numeric( $term ) ) {
			$ok = $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$wpdb->term_taxonomy} WHERE taxonomy = %s AND term_id = %d LIMIT 1",
				$taxonomy,
				(int) $term
			) );
		} else {
			$ok = $wpdb->get_var( $wpdb->prepare(
				"SELECT 1 FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				 WHERE tt.taxonomy = %s AND t.name = %s LIMIT 1",
				$taxonomy,
				$term
			) );
		}

		return (bool) $ok;
	}

		/**
		 * Donne au terme la langue par defaut du site s'il n'en a aucune.
		 * Sans langue, Polylang masque le lieu dans l'admin et dans les archives.
		 *
		 * @param int $term_id Identifiant du terme.
		 * @return void
		 */
	private static function assign_language( $term_id ) {
		$term_id = (int) $term_id;

		if ( ! $term_id || ! function_exists( 'pll_set_term_language' ) || ! function_exists( 'pll_default_language' ) ) {
			return;
		}

		$lang = pll_default_language( 'slug' );
		if ( ! $lang ) {
			return;
		}

		// Un terme deja langue ne doit pas bouger : le multilangue reste possible.
		if ( function_exists( 'pll_get_term_language' ) && '' !== (string) pll_get_term_language( $term_id ) ) {
			return;
		}

		pll_set_term_language( $term_id, $lang );
	}

		/**
		 * Relecture SQL brute des termes d'une taxonomie, sans filtre de langue.
		 *
		 * @param string $taxonomy Taxonomie cible.
		 * @return array Liste d'objets {term_id, name}.
		 */
	private static function terms_without_language_filter( $taxonomy ) {
		global $wpdb;

		$lignes = $wpdb->get_results( $wpdb->prepare(
			"SELECT t.term_id, t.name FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
			 WHERE tt.taxonomy = %s ORDER BY t.name ASC LIMIT 500",
			$taxonomy
		) );

		$sortie = array();
		foreach ( (array) $lignes as $ligne ) {
			$sortie[] = (object) array(
				'term_id' => (int) $ligne->term_id,
				'name'    => (string) $ligne->name,
			);
		}

		return $sortie;
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
					'lang'       => 'all',
			) );

		if ( is_wp_error( $terms ) || ! $terms ) {
			// Repli SQL : Polylang peut masquer un lieu importe sans langue.
			$terms = self::terms_without_language_filter( $taxonomy );
		}

		if ( ! $terms ) {
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
					'lang'       => 'all',
			) );

		if ( is_wp_error( $existing ) || ! $existing ) {
			// Repli SQL : Polylang peut masquer un lieu cree sans langue.
			$existing = self::terms_without_language_filter( $taxonomy );
		}

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
		if ( ! $term ) {
			global $wpdb;
			$id = (int) $wpdb->get_var( $wpdb->prepare(
				"SELECT t.term_id FROM {$wpdb->terms} t JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id
				 WHERE tt.taxonomy = %s AND t.name = %s LIMIT 1",
				$taxonomy,
				$name
			) );
			if ( $id ) {
				self::assign_language( $id );

				return $id;
			}

					return 0;
		}

		self::assign_language( (int) $term->term_id );

				return (int) $term->term_id;
		}

		self::assign_language( (int) $created['term_id'] );

			return (int) $created['term_id'];
	}
}

Partikulier_Morocco_Places::init();
