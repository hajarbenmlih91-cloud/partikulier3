<?php
/**
 * Module : application des filtres de recherche du hero.
 *
 * Le formulaire templates/parts/search-form.php envoyait es_action, es_type,
 * es_city et es_price_max en GET vers l'archive des annonces, mais AUCUN code
 * ne lisait ces parametres : le bouton "Rechercher" renvoyait donc l'archive
 * complete, non filtree.
 *
 * Ce module traduit ces parametres en tax_query / meta_query sur la requete
 * principale. Il accepte indifferemment un slug ou un ID de terme, car Estatik
 * attend des term IDs dans ses propres filtres alors que le formulaire du theme
 * envoie des slugs.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

class Partikulier_Search_Filters {

	const PRICE_SALE_MAX  = 30000000;
	const PRICE_RENT_MAX  = 50000;
	const PRICE_SALE_STEP = 100000;
	const PRICE_RENT_STEP = 500;
	const AREA_MAX        = 5000;
	const AREA_STEP       = 10;

	/**
	 * Correspondance parametre GET -> taxonomie.
	 */
	private static function taxonomy_map() {
			return array(
					'es_action' => PARTIKULIER_ESTATIK_STATUS_TAXONOMY,
					'es_type'   => PARTIKULIER_ESTATIK_TYPE_TAXONOMY,
					'es_city'   => PARTIKULIER_ESTATIK_LOCATION_TAXONOMY,
			);
	}

	public static function init() {
			add_action( 'init', array( __CLASS__, 'ensure_ferme_term' ), 30 );
			add_action( 'pre_get_posts', array( __CLASS__, 'apply_filters' ), 20 );
			add_action( 'wp_ajax_pk_find_count', array( __CLASS__, 'ajax_count' ) );
			add_action( 'wp_ajax_nopriv_pk_find_count', array( __CLASS__, 'ajax_count' ) );
			add_filter( 'posts_orderby', array( __CLASS__, 'stable_property_order' ), 999, 2 );
	}

		/**
		 * Le formulaire de depot a le type Ferme. Le filtre doit pouvoir le choisir
		 * meme si aucune annonce publiee ne le porte encore.
		 */
		public static function ensure_ferme_term() {
			if ( ! taxonomy_exists( PARTIKULIER_ESTATIK_TYPE_TAXONOMY ) ) {
				return;
			}
			$pk_types_assures = array(
			'ferme' => 'Ferme',
			'local' => 'Local commercial',
		);
		foreach ( $pk_types_assures as $pk_slug => $pk_nom ) {
			if ( get_term_by( 'slug', $pk_slug, PARTIKULIER_ESTATIK_TYPE_TAXONOMY ) ) {
				continue;
			}
			wp_insert_term( $pk_nom, PARTIKULIER_ESTATIK_TYPE_TAXONOMY, array( 'slug' => $pk_slug ) );
		}
		}

		/**
		 * SE-048-R (E-4804, 10e fonction) — le tri « premium-first » s'applique-t-il ?
		 *
		 * Cinq conditions, toutes nécessaires : contexte public, requête principale,
		 * requête d'annonces, drapeau d'exploitation allumé, et AUCUN tri explicite
		 * demandé par le visiteur (le choix du visiteur reste prioritaire — le tri
		 * premium ne réécrit jamais un tri demandé). Les listes publiques excluent
		 * déjà les annonces non disponibles (prédicat dp-9, pre_get_posts) : le tri
		 * ne peut donc porter que sur des biens disponibles (invariant E-4807 2).
		 *
		 * @param WP_Query $query Requête courante.
		 * @return bool
		 */
	private static function premium_first_applies( $query ) {
		if ( is_admin() || ! $query instanceof WP_Query || ! $query->is_main_query() || ! self::is_property_query( $query ) ) {
			return false;
		}
		if ( ! class_exists( 'Partikulier_Premium' ) || ! Partikulier_Premium::is_public_enabled() ) {
			return false;
		}
		$requested_order = isset( $_GET['pk_order'] ) ? sanitize_key( wp_unslash( $_GET['pk_order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture d'affichage, aucune écriture
		if ( in_array( $requested_order, array( 'price-asc', 'price-desc', 'surface-desc', 'date-asc' ), true ) ) {
			return false;
		}
		return true;
	}

		/**
		 * Expression SQL « premium en tête » : 0 pour une attribution active ET
		 * non échue, 1 sinon. Écrite en SOUS-REQUÊTE (et non en jointure) pour ne
		 * jamais changer la cardinalité du résultat — une jointure sur postmeta
		 * dupliquerait une annonce portant plusieurs lignes de méta. Le test
		 * d'échéance est fait en SQL (UTC_TIMESTAMP) afin qu'une attribution
		 * échue ne passe pas en tête sur la requête même qui déclenche son
		 * expiration paresseuse.
		 *
		 * @return string
		 */
	private static function premium_first_expression() {
		global $wpdb;
		$postmeta = $wpdb->postmeta;
		$posts    = $wpdb->posts;
		return 'CASE WHEN EXISTS ('
			. 'SELECT 1 FROM ' . $postmeta . ' pk_pm_status'
			. ' WHERE pk_pm_status.post_id = ' . $posts . '.ID'
			. " AND pk_pm_status.meta_key = '_pk_premium_status' AND pk_pm_status.meta_value = 'active'"
			. ' AND EXISTS ('
			. 'SELECT 1 FROM ' . $postmeta . ' pk_pm_end'
			. ' WHERE pk_pm_end.post_id = pk_pm_status.post_id'
			. " AND pk_pm_end.meta_key = '_pk_premium_ends_at' AND pk_pm_end.meta_value > UTC_TIMESTAMP()"
			. ' )'
			. ') THEN 0 ELSE 1 END ASC, ';
	}

		/**
		 * Ajoute un départage SQL déterministe après les réécritures Estatik/Polylang.
		 *
		 * @param string   $orderby Clause ORDER BY courante.
		 * @param WP_Query $query   Requête courante.
		 * @return string
		 */
	public static function stable_property_order( $orderby, $query ) {
		if ( is_admin() || ! $query->is_main_query() || ! self::is_property_query( $query ) ) {
				return $orderby;
		}
			// Les tris explicites du formulaire sont déjà traduits dans WP_Query.
			$requested_order = isset( $_GET['pk_order'] ) ? sanitize_key( wp_unslash( $_GET['pk_order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'date-asc' === $requested_order ) {
			global $wpdb;
			$posts = $wpdb->posts;
			return $posts . '.post_date ASC, ' . $posts . '.ID ASC';
		}
		if ( in_array( $requested_order, array( 'price-asc', 'price-desc', 'surface-desc' ), true ) ) {
			return $orderby;
		}
			global $wpdb;
			$posts = $wpdb->posts;
			// Estatik peut remplacer ORDER BY plus tôt dans la chaîne de hooks.
			// Sans tri explicite, date puis ID fournissent un ordre déterministe.
			$premium = self::premium_first_applies( $query ) ? self::premium_first_expression() : '';
			return $premium . $posts . '.post_date DESC, ' . $posts . '.ID DESC';
	}

		/**
		 * Ne garde que les clauses exploitables d'une tax_query, et son « relation ».
		 *
		 * @param mixed $tax_query Clause telle que recuperee de la requete.
		 * @return array
		 */
	private static function clean_tax_query( $tax_query ) {
			$out = array();
		foreach ( (array) $tax_query as $cle => $clause ) {
			if ( 'relation' === $cle ) {
				$relation = strtoupper( (string) $clause );
				if ( 'AND' === $relation || 'OR' === $relation ) {
						$out['relation'] = $relation;
				}
				continue;
			}
			if ( ! is_array( $clause ) || empty( $clause['taxonomy'] ) ) {
					continue; // chaine vide, entier, ou clause sans taxonomie : poison pour WP_Tax_Query
			}
				$out[] = $clause;
		}
			return $out;
	}

		/**
		 * Relit un terme directement en base, hors filtres de langue Polylang.
		 *
		 * @param string $value    Slug (ou ID numérique) recherche.
		 * @param string $taxonomy Taxonomie.
		 * @param bool   $is_id    La valeur est-elle un identifiant ?
		 * @return WP_Term|false
		 */
	private static function term_raw( $value, $taxonomy, $is_id ) {
			global $wpdb;
		if ( '' === (string) $value || ! taxonomy_exists( $taxonomy ) ) {
				return false;
		}
		if ( $is_id ) {
				$id    = (int) $value;
				$found = $wpdb->get_var( $wpdb->prepare(
						"SELECT t.term_id FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id WHERE t.term_id = %d AND tt.taxonomy = %s LIMIT 1",
						$id, $taxonomy ) );
		} else {
				$found = $wpdb->get_var( $wpdb->prepare(
						"SELECT t.term_id FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id WHERE t.slug = %s AND tt.taxonomy = %s LIMIT 1",
						(string) $value, $taxonomy ) );
		}
		if ( ! $found ) {
				return false;
		}
			$term = get_term( (int) $found, $taxonomy );
			return ( $term && ! is_wp_error( $term ) ) ? $term : false;
	}

		/**
		 * Retrouve un terme par son LIBELLE, en base, hors filtres de langue.
		 *
		 * @param string $name     Libelle (accentue ou non, casse libre).
		 * @param string $taxonomy Taxonomie.
		 * @return WP_Term|false
		 */
	private static function term_raw_by_name( $name, $taxonomy ) {
			global $wpdb;
			$name = trim( (string) $name );
		if ( '' === $name || ! taxonomy_exists( $taxonomy ) ) {
				return false;
		}
			$clean = function_exists( 'remove_accents' ) ? remove_accents( $name ) : $name;
			$id    = (int) $wpdb->get_var( $wpdb->prepare(
					"SELECT t.term_id FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id
                         WHERE tt.taxonomy = %s AND ( LOWER(t.name) = LOWER(%s) OR LOWER(t.name) = LOWER(%s) ) LIMIT 1",
					$taxonomy, $name, $clean ) );
		if ( ! $id ) {
				return false;
		}
			$term = get_term( $id, $taxonomy );
		if ( $term && ! is_wp_error( $term ) ) {
				return $term;
		}
			/* Polylang peut masquer le terme au point de rendre get_term() inutilisable : on
				fabrique l'objet strictement necessaire (term_id + taxonomy + slug) pour que la
				fusion des doubles fonctionne ne serait-ce qu'en slug. */
			$slug = (string) $GLOBALS['wpdb']->get_var( $GLOBALS['wpdb']->prepare( "SELECT slug FROM {$GLOBALS['wpdb']->terms} WHERE term_id = %d", $id ) );
			$obj  = (object) array( 'term_id' => $id, 'taxonomy' => $taxonomy, 'slug' => $slug );
			return $obj;
	}

		/**
		 * Un terme et, s'ils existent, ses doubles de traduction.
		 *
		 * Sur un import Polylang, un meme lieu peut exister en deux termes distincts dont
		 * un seul porte les annonces : « casablanca » (0 annonce, celui que proposent le
		 * menu et l'autocompletion) et « casablanca-fr » (les 9 fiches reelles). Filtrer
		 * sur le terme affiche donnait « 0 annonce » pour une ville pleine (reproduit en
		 * test : get_term_by('slug','casablanca') -> id=87, count=0). On regroupe donc le
		 * terme, ses traductions Polylang, puis a defaut ses variantes suffixees.
		 *
		 * @param WP_Term|false $term Terme resolu.
		 * @return int[] term_taxonomy_id a utiliser dans la clause (field = term_taxonomy_id)
		 */
	private static function term_with_translations( $term ) {
		if ( ! $term || is_wp_error( $term ) ) {
				return array( 0 );
		}
			/* LA CLAUSE DOIT PORTER DES term_taxonomy_id, PAS des term_id : mesure sur ce
				banc (Polylang actif, deux termes « casablanca » 0 fiche et « casablanca-fr »
				9 fiches) : field=term_id rend found_posts=0 MEME en coupant tous les hooks
				pre_get_posts, alors que field=term_taxonomy_id rend 9. Le theme passait des
				term_id : le filtre ville et le filtre type etaient donc structurellement
				morts sur un catalogue traduit. */
			$tt_of = static function ( $t ) {
					$id = (int) ( is_object( $t ) ? ( $t->term_taxonomy_id ?: 0 ) : 0 );
				if ( $id > 0 ) {
						return $id;
				}
					global $wpdb;
					return (int) $wpdb->get_var( $wpdb->prepare( "SELECT tt.term_taxonomy_id FROM $wpdb->term_taxonomy tt WHERE tt.term_id = %d AND tt.taxonomy = %s LIMIT 1", (int) ( is_object( $t ) ? $t->term_id : 0 ), (string) ( is_object( $t ) ? $t->taxonomy : '' ) ) );
			};
			/* Ordre des tt_ids : Polylang (PLL_Canonical) deduit la langue « attendue »
				de l URL a partir du PREMIER terme de la tax_query. Si un filtre visite en
				EN/AR est resolu sur le terme FR canonique (slug sans suffixe), la page est
				alors redirigee (301) vers la langue du terme - cible doublee du type
				/fr/en/annonces/ mesuree sur banc. On place donc en tete la traduction du
				terme dans la langue de la visite quand elle existe : l ensemble IN est
				strictement identique, seul l ordre change, et aucune redirection ne part. */
			$lead = $term;
		if ( function_exists( 'pll_current_language' ) && function_exists( 'pll_get_term' ) && $term && ! is_wp_error( $term ) ) {
				$curlang = pll_current_language( 'slug' );
			if ( $curlang ) {
					$cur_id = (int) pll_get_term( (int) $term->term_id, $curlang );
				if ( $cur_id ) {
					$cur = get_term( $cur_id, $term->taxonomy );
					if ( $cur && ! is_wp_error( $cur ) ) {
								$lead = $cur;
					}
				}
			}
		}
			$ids = array( $tt_of( $lead ), $tt_of( $term ) );
		if ( function_exists( 'pll_get_term' ) ) {
			foreach ( (array) ( function_exists( 'pll_languages_list' ) ? pll_languages_list( array( 'fields' => 'slug' ) ) : array() ) as $lang ) {
					$link = (int) pll_get_term( (int) $term->term_id, $lang );
				if ( $link > 0 ) {
					$tt = $tt_of( get_term( $link, $term->taxonomy ) ?: (object) array( 'term_id' => $link, 'taxonomy' => $term->taxonomy, 'term_taxonomy_id' => 0 ) );
					if ( $tt > 0 && ! in_array( $tt, $ids, true ) ) {
								$ids[] = $tt;
					}
				}
			}
		}
		if ( count( $ids ) < 2 ) {
				$base = preg_replace( '/-(?:fr|en|ar)(?:-[a-z]{2})?$/i', '', (string) $term->slug );
				/* get_terms() ET get_term_by() sont filtres par Polylang : sur un import qui a
					duplie les lieux, le terme qui porte les fiches est justement celui que ces
					APIs refusent de montrer au visiteur (mesure : get_term_by('slug','casablanca')
					-> faux, alors que le terme existe et que son double « casablanca-fr » porte
					les 9 fiches). Le rapprochement se fait donc en SQL, sans filtre de langue,
					et remonte les identifiants bruts. */
				global $wpdb;
				$trouves = (array) $wpdb->get_col( $wpdb->prepare(
						"SELECT t.term_id FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id
                                 WHERE tt.taxonomy = %s AND t.slug REGEXP %s LIMIT 200",
						$term->taxonomy, '^' . preg_quote( $base, '/' ) . '(-[a-z]{2}(-[a-z]{2})?)?$' ) );
			foreach ( $trouves as $tid ) {
				$tt = $tt_of( (object) array( 'term_id' => (int) $tid, 'taxonomy' => $term->taxonomy, 'term_taxonomy_id' => 0 ) );
				if ( $tt > 0 && ! in_array( $tt, $ids, true ) ) {
							$ids[] = $tt;
				}
			}
		}
			return array_values( array_unique( array_map( 'intval', $ids ) ) );
	}

		/**
		 * Applique les filtres sur la requete principale des annonces.
		 *
		 * @param WP_Query $query Requete WordPress.
		 */
	/**
	 * Le formulaire envoie pk_intent (vente, location, saisonnière).
	 * La saisonnière reste une location : es_action=a-louer et pk_rent=saisonnier.
	 *
	 * @return void
	 */
	private static function normalize_intent() {
		if ( ! isset( $_GET['pk_intent'] ) || ! is_scalar( $_GET['pk_intent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}
		$intent = sanitize_key( wp_unslash( $_GET['pk_intent'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'saisonnier' === $intent ) {
			$_GET['es_action'] = 'a-louer';
			$_GET['pk_rent']   = 'saisonnier';
		} elseif ( 'a-louer' === $intent ) {
			$_GET['es_action'] = 'a-louer';
			$_GET['pk_rent']   = '';
		} elseif ( 'a-vendre' === $intent ) {
			$_GET['es_action'] = 'a-vendre';
			unset( $_GET['pk_rent'] );
		}
	}

	public static function apply_filters( $query ) {
		self::normalize_intent();
		$pk_counting = (bool) $query->get( 'pk_count' );
		if ( $query->is_singular() ) {
			return;
		}
		if ( ! $pk_counting && ( is_admin() || ! $query->is_main_query() || ! self::is_property_query( $query ) ) ) {
			return;
		}

			// Les tris explicites du formulaire sont traduits en meta_key/orderby
			// afin d’être réellement appliqués par WP_Query.
			$order = isset( $_GET['pk_order'] ) ? sanitize_key( wp_unslash( $_GET['pk_order'] ) ) : 'recent'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		switch ( $order ) {
			case 'price-asc':
					$query->set( 'meta_key', 'es_property_price' );
					$query->set( 'orderby', array( 'meta_value_num' => 'ASC', 'ID' => 'DESC' ) );
						break;
			case 'price-desc':
					$query->set( 'meta_key', 'es_property_price' );
					$query->set( 'orderby', array( 'meta_value_num' => 'DESC', 'ID' => 'DESC' ) );
						break;
			case 'date-asc':
					$query->set( 'orderby', array( 'date' => 'ASC', 'ID' => 'ASC' ) );
						break;
			case 'surface-desc':
					$query->set( 'meta_key', 'es_property_area' );
					$query->set( 'orderby', array( 'meta_value_num' => 'DESC', 'ID' => 'DESC' ) );
						break;
			default:
				if ( ! $query->get( 'orderby' ) || 'date' === $query->get( 'orderby' ) ) {
						$query->set( 'orderby', array( 'date' => 'DESC', 'ID' => 'DESC' ) );
				}
						break;
		}

					// --- Filtres de taxonomie (achat/location, type de bien, ville) ---
					$existing_tax_query = $query->get( 'tax_query' );
		if ( $existing_tax_query instanceof WP_Tax_Query ) {
			$tax_query = $existing_tax_query->queries;
			if ( ! empty( $existing_tax_query->relation ) ) {
					$tax_query['relation'] = $existing_tax_query->relation;
			}
		} else {
				$tax_query = (array) $existing_tax_query;
		}
					/* La tax_query existante arrive parfois polluee d'une entree vide (mesure:
						[''] sur l'archive des annonces). Empile sur ce residu, WP_Tax_Query en fait
						une clause fausse et la requete devient « 0 = 1 » : la page d'annonces rend
						ZERO de biens, meme avec un filtre parfaitement resolus (verifie ici :
						terms=[87,94] corrects, found_posts=0 a cause du residu). On ne conserve
						donc que les clauses reellement constructibles. */
					$tax_query = self::clean_tax_query( $tax_query );

					$taxonomy_map             = self::taxonomy_map();
					$taxonomy_map['location'] = PARTIKULIER_ESTATIK_LOCATION_TAXONOMY;
					$unresolvable_action      = false;
					$unresolvable             = array();
		foreach ( $taxonomy_map as $param => $taxonomy ) {
			if ( empty( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
							continue;
			}
			if ( ! taxonomy_exists( $taxonomy ) ) {
				continue;
			}

				/* E-5105 : un paramètre non scalaire est un filtre NON RÉSOLU
				   (discipline S17 : « jamais tout le catalogue »), pas un filtre
				   absent — il rejoint le chemin « terme inconnu ». */
				if ( ! is_scalar( $_GET[ $param ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					$unresolvable[] = $param;
					continue;
				}

						$raw = sanitize_text_field( wp_unslash( $_GET[ $param ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( '' === $raw ) {
				continue;
			}

			if ( 'es_action' === $param && ! ctype_digit( $raw ) && ! in_array( $raw, array( 'a-vendre', 'a-louer' ), true ) ) {
				$unresolvable_action = true;
				continue;
			}

				// Le formulaire utilise des slugs stables pour les actions. Si Estatik a un
				// libelle different, retrouver son vrai terme avant de construire la tax_query.
			if ( 'es_action' === $param && in_array( $raw, array( 'a-vendre', 'a-louer' ), true ) ) {
							$needles      = 'a-louer' === $raw ? array( 'louer', 'location', 'rent' ) : array( 'vend', 'vente', 'sale' );
							$action_terms = get_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
				if ( ! is_wp_error( $action_terms ) ) {
					foreach ( $action_terms as $action_term ) {
						$action_name = function_exists( 'remove_accents' ) ? remove_accents( $action_term->name ) : $action_term->name;
						$action_name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $action_name ) : strtolower( $action_name );
						foreach ( $needles as $needle ) {
							if ( false !== strpos( $action_name, $needle ) ) {
								$raw = $action_term->slug;
								break 2;
							}
						}
					}
				}
			}

									// Le formulaire envoie un slug ; Estatik peut envoyer un term ID.
									$is_id = ctype_digit( $raw );
									$term  = $is_id
									? get_term( (int) $raw, $taxonomy )
									: get_term_by( 'slug', $raw, $taxonomy );
									/* Polylang retire de la vue les termes NON TRADUITS. Sur un import qui a
									duplique les lieux (« casablanca » 0 annonce, « casablanca-fr » 9), le
									terme propose par l'autocompletion peut etre exactement celui que
									get_term_by() refuse de renvoyer cote visiteur : le filtre partait donc
									a la poubelle. On relit le terme en base, sans filtre de langue, avant
									de conclure a l'inconnu. */
			if ( ! $term || is_wp_error( $term ) ) {
				$term = self::term_raw( $raw, $taxonomy, $is_id );
			}
			if ( ! $term || is_wp_error( $term ) ) {
					/* Deuxieme filet : le visiteur a pu taper/coller un LIBELLE (« Casablanca »)
						plutot qu'un slug. On matche le nom du terme en base, toujours sans
						filtre de langue, avant de declarer le filtre inconnu. */
					$term = self::term_raw_by_name( $raw, $taxonomy );
			}

			if ( ! $term || is_wp_error( $term ) ) {
					/* Un filtre affiche a l'ecran mais ignore parce que son terme
						n'existe pas est pire qu'une erreur : le visiteur croit avoir
						filtre. Mesure sur le staging, ?es_city=inexistant rendait
						l'integralite du catalogue (21 cartes comme sans filtre). Tout
						filtre transactionnel non resolu vide donc le resultat, comme
						l'action le faisait deja. */
					$unresolvable[] = $param;
					continue;
			}

									$tax_query[] = array(
									'taxonomy'         => $taxonomy,
									'field'            => 'term_taxonomy_id',
									'terms'            => self::term_with_translations( $term ),
									'include_children' => true,
									);
		}

					/* « s » est la recherche plein texte de WordPress : un visiteur qui tape
						« casablanca » dans la barre du haut ne cherche pas un bien dont le texte
						contient ce mot, il cherche la VILLE. On traduit donc le texte libre en
						terme es_location via le referentiel du theme (le meme service que
						l'autocompletion du formulaire de depot) ; aucun terme n'est jamais cree
						ici, la moderation garde la main. Si rien n'est trouve, on laisse « s »
						jouer son role de recherche de texte. */
		if ( ! empty( $_GET['es_city'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$query->set( 's', '' );
			$query->is_search = false;
	} elseif ( ! empty( $_GET['s'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$libre = trim( (string) wp_unslash( $_GET['s'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- trim + correspondance de termes uniquement (SE-020/E-2007)
			if ( '' !== $libre && class_exists( 'Partikulier_Morocco_Places' ) && taxonomy_exists( PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) ) {
							$parties  = array_map( 'trim', preg_split( '/[,;]/u', $libre ) );
							$ville    = count( $parties ) > 1 ? array_pop( $parties ) : $libre;
							$quartier = count( $parties ) > 1 ? implode( ', ', $parties ) : '';
							$trouve   = (int) Partikulier_Morocco_Places::find_existing_term( $ville, $quartier );
				if ( ! $trouve && '' !== $quartier ) {
					$trouve = (int) Partikulier_Morocco_Places::find_existing_term( $quartier, '' );
				}
				if ( $trouve ) {
					$tax_query[] = array(
					'taxonomy'         => PARTIKULIER_ESTATIK_LOCATION_TAXONOMY,
					'field'            => 'term_taxonomy_id',
					'terms'            => self::term_with_translations( get_term( $trouve, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) ),
					'include_children' => true,
						);
						$query->set( 's', '' );
						$query->is_search = false;
				}
			}
		}

					// Les routes /[lang]/location/{slug}/ transmettent une query var interne,
					// tandis que les liens de l’interface utilisent ?location={slug}.
					$city_slug = sanitize_title( (string) $query->get( 'pk_city_slug' ) );
	/* E-5105 (F-T17-2, lot sécurité 6.20.6) : garde scalaire — un tableau
	   est un filtre NON RÉSOLU (S17), jamais passé à sanitize_title()
	   ni ignoré (ce qui rendrait le catalogue entier). */
	if ( '' === $city_slug && ! empty( $_GET['location'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_scalar( $_GET['location'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$unresolvable[] = 'location';
		} else {
				$city_slug = sanitize_title( wp_unslash( $_GET['location'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}
		if ( '' !== $city_slug && taxonomy_exists( PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) ) {
				$city_term = get_term_by( 'slug', $city_slug, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
			if ( ! $city_term || is_wp_error( $city_term ) ) {
					$city_term = self::term_raw( $city_slug, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, false );
			}
			if ( $city_term ) {
							$tt = (array) self::term_with_translations( $city_term );
							/* Le slug propose par le menu, les tuiles de la home ou l'autocompletion
								peut etre celui de l'orphelin a zero fiche : on rattache d'abord tous les
								termes qui portent le MEMME libelle (mesure staging : « casablanca » 0
								fiche, « casablanca-fr » les 9 reelles). */
							global $wpdb;
							$nom_v = (string) $wpdb->get_var( $wpdb->prepare( "SELECT name FROM $wpdb->terms WHERE term_id = %d", (int) $city_term->term_id ) );
				if ( '' !== $nom_v ) {
					$pairs = (array) $wpdb->get_col( $wpdb->prepare(
					"SELECT tt2.term_id FROM $wpdb->terms t2 JOIN $wpdb->term_taxonomy tt2 ON tt2.term_id = t2.term_id
                                                         WHERE tt2.taxonomy = %s AND ( LOWER(t2.name) = LOWER(%s) OR LOWER(t2.name) = LOWER(%s) )",
					PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, $nom_v, function_exists( 'remove_accents' ) ? remove_accents( $nom_v ) : $nom_v ) );
					foreach ( $pairs as $pid ) {
									$pt = get_term( (int) $pid, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
									$pt = ( $pt && ! is_wp_error( $pt ) ) ? $pt : null;
						if ( $pt ) {
							$tt = array_merge( $tt, (array) self::term_with_translations( $pt ) );
						}
					}
				}
							$tt          = array_values( array_unique( array_filter( $tt ) ) );
							$tax_query[] = array(
									'taxonomy'         => PARTIKULIER_ESTATIK_LOCATION_TAXONOMY,
									'field'            => 'term_taxonomy_id',
									'terms'            => $tt,
									'include_children' => true,
							);
			}
		}

		if ( $unresolvable_action || $unresolvable ) {
				$query->set( 'post__in', array( 0 ) );
		}
		if ( count( $tax_query ) > 1 ) {
				$tax_query['relation'] = 'AND';
		}
		if ( ! empty( $tax_query ) ) {
				/* LA CAUSE REELLE DU « 0 ANNONCE » : cette ecriture ECRASAIT la
					tax_query deja posee par Polylang (le filtres de langue, clause
					{taxonomy: language, terms: 76, field: term_taxonomy_id}). Mesure
					dans le SQL final : language IN (78) au lieu de (76) — le 78 etait
					en realite l'ID du terme « Achat ou location » de l'import, la clause
					n'etait donc jamais satisfaite et produisait « 0 = 1 » :
					found_posts = 0 sur une ville qui a 9 fiches. On relit DONC la valeur
					au moment d'ecrire (les priorites 20..90 sont passees entre-temps) et
					on APPARTE notre clause au lieu de remplacer le bloc. */
				$present = $query->get( 'tax_query' );
			if ( $present instanceof WP_Tax_Query ) {
							$present = $present->queries;
			}
				$present  = self::clean_tax_query( $present );
				$relation = isset( $present['relation'] ) ? $present['relation'] : 'AND';
				unset( $present['relation'] );
				$miennes        = array();
				$mes_taxonomies = array();
			foreach ( $tax_query as $cle => $clause ) {
				if ( 'relation' === $cle || ! is_array( $clause ) || empty( $clause['taxonomy'] ) ) {
					continue;
				}
				if ( in_array( $clause, $present, true ) ) {
						continue; // deja pose (route /annonces/{ville}/ par exemple)
				}
								$miennes[]        = $clause;
								$mes_taxonomies[] = $clause['taxonomy'];
			}
									/* Estatik (es_add_sorting_and_taxonomy_to_archive_query, priorite 10)
									pose lui aussi une clause {taxonomy, field: slug} pour CHAQUE
									parametre GET qu'il reconnait. Reproduit sur banc (WordPress 7.1 +
									Polylang + Estatik) : la clause slug passe par WP_Tax_Query ->
									WP_Term_Query, que Polylang filtre par langue. Deux echecs mesures :
									- terme SANS langue (import duplique) : invisible -> termes vides
									-> WP_Tax_Query rend « 0 = 1 » et le catalogue entier tombe a zero ;
									- terme fr visible : clause IN (un seul term_id) qui EXCLUT le
									doublon porteur des fiches (« appartement » 0 fiche face a
									« appartement-fr » 21) alors que notre clause regroupe deja les
									homonymes et les traductions.
									Notre clause couvre le MEME parametre GET, completement : la clause
									slug etrangere est redondante et casseuse -> on la retire. Les
									clauses par term_id des routes geographiques restent (champ different). */
									$present = array_values( array_filter( $present, static function ( $c ) use ( $mes_taxonomies ) {
										if ( ! is_array( $c ) || empty( $c['taxonomy'] ) ) {
											return false;
										}
										$field     = isset( $c['field'] ) ? $c['field'] : 'term_id';
										$etrangere = in_array( $field, array( 'slug', 'name' ), true );
										return ! ( $etrangere && in_array( $c['taxonomy'], $mes_taxonomies, true ) );
									} ) );
				/* Desintinguer les clauses exactement identiques (un hook double,
					un module qui rejoue pre_get_posts : mesure sur banc, deux clauses
					slug identiques d'Estatik sur ?es_type=). */
				$uniques = array();
			foreach ( array_merge( $present, $miennes ) as $c ) {
				$k = md5( (string) wp_json_encode( $c ) );
				if ( ! isset( $uniques[ $k ] ) ) {
							$uniques[ $k ] = $c;
				}
			}
				$fusion = array_values( $uniques );
			if ( count( $fusion ) > 1 ) {
							$fusion['relation'] = 'AND';
			}
				$query->set( 'tax_query', $fusion );
		}

					/* WordPress core (WP_Query::parse_tax_query, apres TOUS les pre_get_posts)
						convertit tout parametre qui porte le query_var d'une taxonomie en clause
						{taxonomy, field: slug} : ?es_type=xx -> clause slug es_type. Reproduit sur
						banc (WP 7.1) : cette clause passe par WP_Term_Query, filtree par la langue
						Polylang — un terme sans langue devient invisible, WP_Tax_Query rend alors
						« 0 = 1 » et vide le catalogue ; un terme visible donne une clause un seul
						term_id qui exclut les doublons porteurs. Nos clauses (term_taxonomy_id,
						homonymes regroupes) couvrent deja ces parametres : on consomme la query var
						pour que core n'en fabrique pas une deuxieme, cassee. */
		foreach ( self::taxonomy_map() as $pk_param => $pk_taxo ) {
				$pk_obj = get_taxonomy( $pk_taxo );
			if ( $pk_obj && ! empty( $pk_obj->query_var ) ) {
							$query->set( $pk_obj->query_var, '' );
			}
		}
					/* La recherche « s » traduite en ville a deja ete remplacee plus haut par
						une clause ; on neutralise aussi la query var pour que core ne relance pas
						une recherche plein texte residuelle. */

			// --- Budget : un tableau GET n'est pas un nombre (E-5105). Le curseur
			// au plafond du formulaire veut dire « sans limite », pas un plafond. ---
			$price_max = self::query_int( 'es_price_max' );
			$price_min = self::query_amount( 'es_price_min' );
			$price_cap = ( 'a-louer' === self::query_key( 'es_action' ) ) ? self::PRICE_RENT_MAX : self::PRICE_SALE_MAX;
			if ( $price_max >= $price_cap ) {
				$price_max = 0;
			}
			if ( $price_min > 0 && $price_max > 0 && $price_min > $price_max ) {
				$pk_swap   = $price_min;
				$price_min = $price_max;
				$price_max = $pk_swap;
			}

		if ( $price_max > 0 || $price_min > 0 ) {
			$meta_query = (array) $query->get( 'meta_query' );

			if ( $price_max > 0 && $price_min > 0 ) {
					$meta_query[] = array(
							'key'     => 'es_property_price',
							'value'   => array( $price_min, $price_max ),
							'type'    => 'NUMERIC',
							'compare' => 'BETWEEN',
					);
			} elseif ( $price_max > 0 ) {
					$meta_query[] = array(
							'key'     => 'es_property_price',
							'value'   => $price_max,
							'type'    => 'NUMERIC',
							'compare' => '<=',
					);
			} else {
							$meta_query[] = array(
							'key'     => 'es_property_price',
							'value'   => $price_min,
							'type'    => 'NUMERIC',
							'compare' => '>=',
							);
			}

									$query->set( 'meta_query', $meta_query );
		}

		$pk_rent = '';
		if ( isset( $_GET['pk_rent'] ) && is_scalar( $_GET['pk_rent'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$pk_rent = sanitize_key( wp_unslash( $_GET['pk_rent'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$pk_action_now = isset( $_GET['es_action'] ) && is_scalar( $_GET['es_action'] ) ? sanitize_text_field( wp_unslash( $_GET['es_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'saisonnier' === $pk_rent || ( 'a-louer' === $pk_action_now && 'saisonnier' !== $pk_rent ) ) {
			$rent_meta = (array) $query->get( 'meta_query' );
			unset( $rent_meta['relation'] );
			if ( 'saisonnier' === $pk_rent ) {
				$rent_meta[] = array(
					'key'     => '_pk_rent',
					'value'   => 'saisonnier',
					'compare' => '=',
				);
			} else {
				$rent_meta[] = array(
					'relation' => 'OR',
					array(
						'key'     => '_pk_rent',
						'compare' => 'NOT EXISTS',
					),
					array(
						'key'     => '_pk_rent',
						'value'   => 'saisonnier',
						'compare' => '!=',
					),
				);
			}
			if ( count( $rent_meta ) > 1 ) {
				$rent_meta['relation'] = 'AND';
			}
			$query->set( 'meta_query', $rent_meta );
		}
		self::apply_detail_filters( $query );
	}

	/**
	 * Entier de query string, ou 0 si absent, vide, ou non scalaire.
	 *
	 * @param string $key Parametre GET.
	 * @return int
	 */
	private static function query_amount( $key ) {
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 0;
		}
		$raw = (string) wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw = str_replace( array( ' ', "\xc2\xa0" ), '', $raw );
		if ( '' === $raw || ! preg_match( '/^\d+$/', $raw ) ) {
			return 0;
		}
		return (int) $raw;
	}

	private static function query_int( $key ) {
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return 0;
		}
		$raw = wp_unslash( $_GET[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_scalar( $raw ) ) {
			return 0;
		}
		$raw = (string) $raw;
		if ( '' === $raw || ! preg_match( '/^\d+$/', $raw ) ) {
			return 0;
		}
		return (int) $raw;
	}

	/**
	 * Cle GET scalaire, sinon chaine vide.
	 *
	 * @param string $key Parametre GET.
	 * @return string
	 */
	private static function query_key( $key ) {
		if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return '';
		}
		return sanitize_key( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Surface, chambres, salles de bain, equipements. Le plafond du curseur
	 * n'est pas un filtre : il veut dire « sans limite ».
	 *
	 * @param WP_Query $query Requete.
	 */
	private static function apply_detail_filters( $query ) {
		$size_min = self::query_amount( 'es_size_min' );
		$size_max = self::query_amount( 'es_size_max' );
		if ( $size_max >= self::AREA_MAX ) {
			$size_max = 0;
		}
		if ( $size_min > 0 && $size_max > 0 && $size_min > $size_max ) {
			$swap     = $size_min;
			$size_min = $size_max;
			$size_max = $swap;
		}
		if ( $size_min > 0 || $size_max > 0 ) {
			self::push_meta( $query, self::range_clause( 'es_property_area', $size_min, $size_max ) );
		}

		$bedrooms = self::query_int( 'es_bedrooms_min' );
		if ( $bedrooms > 0 ) {
			self::push_meta( $query, array(
				'key'     => 'es_property_bedrooms',
				'value'   => $bedrooms,
				'type'    => 'NUMERIC',
				'compare' => '>=',
			) );
		}

		$salons = self::query_int( 'pk_salons_min' );
		if ( $salons > 0 && $salons <= 4 ) {
			self::push_meta( $query, array(
				'relation' => 'OR',
				array(
					'key'     => '_pk_living_rooms',
					'value'   => $salons,
					'type'    => 'NUMERIC',
					'compare' => '>=',
				),
				array(
					'key'     => '_pk_living_rooms_label',
					'value'   => $salons,
					'type'    => 'NUMERIC',
					'compare' => '>=',
				),
			) );
		}

		if ( 'meuble' === self::query_key( 'pk_furnished' ) ) {
			self::push_meta( $query, array(
				'key'     => '_pk_furnished',
				'value'   => 'meuble',
				'compare' => '=',
			) );
		}

		$pk_quartier = '';
		if ( isset( $_GET['pk_quartier'] ) && is_scalar( $_GET['pk_quartier'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$pk_quartier = sanitize_text_field( wp_unslash( $_GET['pk_quartier'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( '' !== $pk_quartier && class_exists( 'Partikulier_Morocco_Places' ) ) {
			$pk_known = false;
			foreach ( Partikulier_Morocco_Places::reference() as $pk_list ) {
				if ( in_array( $pk_quartier, $pk_list, true ) ) {
					$pk_known = true;
					break;
				}
			}
			if ( $pk_known ) {
				self::push_meta( $query, array(
					'key'     => '_pk_district_name',
					'value'   => $pk_quartier,
					'compare' => '=',
				) );
			}
		}

		$known = self::amenity_catalog();
		foreach ( self::amenity_request() as $id ) {
			if ( ! isset( $known[ $id ] ) ) {
				continue;
			}
			$clause = array(
				'relation' => 'OR',
				array(
					'key'     => '_pk_amenities',
					'value'   => '"' . self::like_id( $id ) . '"',
					'compare' => 'LIKE',
				),
			);
			$legacy = isset( $known[ $id ]['legacy'] ) ? sanitize_key( (string) $known[ $id ]['legacy'] ) : '';
			if ( '' !== $legacy ) {
				$clause[] = array(
					'key'     => '_' . $legacy,
					'value'   => 'Oui',
					'compare' => '=',
				);
			}
			self::push_meta( $query, $clause );
		}
	}

	/**
	 * @param string $key  Meta.
	 * @param int    $min  Minimum, 0 = ignore.
	 * @param int    $max  Maximum, 0 = ignore.
	 * @return array<string,mixed>
	 */
	private static function range_clause( $key, $min, $max ) {
		if ( $min > 0 && $max > 0 ) {
			return array(
				'key'     => $key,
				'value'   => array( $min, $max ),
				'type'    => 'NUMERIC',
				'compare' => 'BETWEEN',
			);
		}
		if ( $max > 0 ) {
			return array(
				'key'     => $key,
				'value'   => $max,
				'type'    => 'NUMERIC',
				'compare' => '<=',
			);
		}
		return array(
			'key'     => $key,
			'value'   => $min,
			'type'    => 'NUMERIC',
			'compare' => '>=',
		);
	}

	/**
	 * @param WP_Query         $query  Requete.
	 * @param array<string,mixed> $clause Clause meta.
	 */
	private static function push_meta( $query, array $clause ) {
		$meta = $query->get( 'meta_query' );
		if ( ! is_array( $meta ) ) {
			$meta = array();
		}
		unset( $meta['relation'] );
		$meta[] = $clause;
		if ( count( $meta ) > 1 ) {
			$meta['relation'] = 'AND';
		}
		$query->set( 'meta_query', $meta );
	}

	/**
	 * @return array<int,string>
	 */
	private static function amenity_request() {
		if ( ! isset( $_GET['pk_amenity'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return array();
		}
		$raw = wp_unslash( $_GET['pk_amenity'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list = is_array( $raw ) ? $raw : ( is_scalar( $raw ) ? array( $raw ) : array() );
		$ids  = array();
		foreach ( $list as $item ) {
			if ( ! is_scalar( $item ) ) {
				continue;
			}
			$id = sanitize_key( (string) $item );
			if ( '' !== $id ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * @return array<string,array<string,mixed>>
	 */
	private static function amenity_catalog() {
		if ( ! class_exists( 'Partikulier_Amenities' ) ) {
			return array();
		}
		$out = array();
		foreach ( Partikulier_Amenities::items() as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) ) {
				$out[ sanitize_key( (string) $item['id'] ) ] = $item;
			}
		}
		return $out;
	}

	/**
	 * @param string $id Identifiant deja passe par sanitize_key.
	 * @return string
	 */
	private static function like_id( $id ) {
		global $wpdb;
		return $wpdb->esc_like( $id );
	}

	/**
	 * La requete porte-t-elle sur les annonces ?
		 *
		 * @param WP_Query $query Requete.
		 * @return bool
		 */
	private static function is_property_query( $query ) {
			$post_type = $query->get( 'post_type' );

		if ( PARTIKULIER_ESTATIK_POST_TYPE === $post_type ) {
				return true;
		}
		if ( is_array( $post_type ) && in_array( PARTIKULIER_ESTATIK_POST_TYPE, $post_type, true ) ) {
				return true;
		}
		if ( $query->is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
				return true;
		}
		foreach ( self::taxonomy_map() as $taxonomy ) {
			if ( taxonomy_exists( $taxonomy ) && $query->is_tax( $taxonomy ) ) {
					return true;
			}
		}
			return false;
	}

	/**
	 * Compteurs de facettes « types de biens » dans le contexte actif
	 * (transaction, ville, budget max — le type exclu), revue user 08/10 :
	 * « quand on choisit vente, on voit les nombres de biens en vente ».
	 * Les clauses reproduisent la résolution de apply_filters (slugs stables
	 * a-vendre/a-louer, terme ville relus sans filtre de langue).
	 *
	 * @return array<int,int> term_id => nombre d'annonces publish.
	 */
	public static function facet_type_counts() {
		self::normalize_intent();
		$counts    = array();
		$tax_query = array();

		$action_raw = isset( $_GET['es_action'] ) && is_scalar( $_GET['es_action'] ) ? sanitize_text_field( wp_unslash( $_GET['es_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $action_raw && taxonomy_exists( PARTIKULIER_ESTATIK_STATUS_TAXONOMY ) ) {
			$slug = $action_raw;
			if ( in_array( $action_raw, array( 'a-vendre', 'a-louer' ), true ) ) {
				$needles      = 'a-louer' === $action_raw ? array( 'louer', 'location', 'rent' ) : array( 'vend', 'vente', 'sale' );
				$action_terms = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_STATUS_TAXONOMY, 'hide_empty' => false ) );
				if ( ! is_wp_error( $action_terms ) ) {
					foreach ( $action_terms as $action_term ) {
						$action_name = function_exists( 'remove_accents' ) ? remove_accents( $action_term->name ) : $action_term->name;
						$action_name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $action_name ) : strtolower( $action_name );
						foreach ( $needles as $needle ) {
							if ( false !== strpos( $action_name, $needle ) ) {
								$slug = $action_term->slug;
								break 2;
							}
						}
					}
				}
			}
			$tax_query[] = array( 'taxonomy' => PARTIKULIER_ESTATIK_STATUS_TAXONOMY, 'field' => 'slug', 'terms' => array( $slug ) );
		}

		$city_raw = isset( $_GET['es_city'] ) && is_scalar( $_GET['es_city'] ) ? sanitize_text_field( wp_unslash( $_GET['es_city'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( '' !== $city_raw && taxonomy_exists( PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) ) {
			$city_term = get_term_by( 'slug', $city_raw, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
			if ( ! $city_term || is_wp_error( $city_term ) ) {
				$city_term = self::term_raw( $city_raw, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, false );
			}
			if ( $city_term && ! is_wp_error( $city_term ) ) {
				$tax_query[] = array( 'taxonomy' => PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, 'field' => 'term_id', 'terms' => array( $city_term->term_id ) );
			}
		}

		$price_max = self::query_int( 'es_price_max' );
		$facet_cap = ( 'a-louer' === self::query_key( 'es_action' ) ) ? self::PRICE_RENT_MAX : self::PRICE_SALE_MAX;
		if ( $price_max >= $facet_cap ) {
			$price_max = 0;
		}
		$meta_query = $price_max > 0 ? array( array( 'key' => 'es_property_price', 'value' => $price_max, 'type' => 'NUMERIC', 'compare' => '<=' ) ) : array();

		$types = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY, 'hide_empty' => true ) );
		if ( ! $types || is_wp_error( $types ) ) {
			return $counts;
		}
		foreach ( $types as $type_term ) {
			$args = array(
				'post_type'      => PARTIKULIER_ESTATIK_POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => false,
				'tax_query'      => array_merge( $tax_query, array( array( 'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY, 'field' => 'term_id', 'terms' => array( $type_term->term_id ) ) ) ),
			);
			if ( $meta_query ) {
				$args['meta_query'] = $meta_query;
			}
			$facet_q                        = new WP_Query( $args );
			$counts[ $type_term->term_id ] = (int) $facet_q->found_posts;
		}
		return $counts;
	}

	/**
	 * Nombre d'annonces publiees pour les memes filtres que le formulaire.
	 *
	 * @param array<string,mixed>|null $params Parametres. Null conserve $_GET.
	 * @return int
	 */
	public static function matching_count( $params = null ) {
		$saved = $_GET;
		if ( is_array( $params ) ) {
			$_GET = $params;
		}
		$lang = '';
		if ( isset( $_POST['pk_language'] ) && is_scalar( $_POST['pk_language'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$lang = sanitize_key( wp_unslash( $_POST['pk_language'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( function_exists( 'pll_current_language' ) ) {
			$lang = (string) pll_current_language( 'slug' );
		}
		$args = array(
			'post_type'              => PARTIKULIER_ESTATIK_POST_TYPE,
			'post_status'            => 'publish',
			'posts_per_page'         => 1,
			'fields'                 => 'ids',
			'no_found_rows'          => false,
			'update_post_meta_cache' => false,
			'update_post_term_cache' => false,
			'ignore_sticky_posts'    => true,
			'pk_count'               => 1,
		);
		if ( in_array( $lang, array( 'fr', 'en', 'ar' ), true ) ) {
			$args['lang'] = $lang;
		}
		try {
			$query = new WP_Query( $args );
			$count = (int) $query->found_posts;
			wp_reset_postdata();
			return $count;
		} finally {
			$_GET = $saved;
		}
	}

	/**
	 * Compte du filtre, meme requete que la page d'annonces.
	 *
	 * @return void
	 */
	public static function ajax_count() {
		check_ajax_referer( 'pk_public', 'nonce' );
		$allowed = array(
			'pk_intent',
			'es_action',
			'pk_rent',
			'es_type',
			'es_city',
			'es_price_min',
			'es_price_max',
			'es_size_min',
			'es_size_max',
			'es_bedrooms_min',
			'es_bathrooms_min',
			'pk_salons_min',
			'pk_furnished',
		);
		$params  = array();
		foreach ( $allowed as $key ) {
			if ( ! isset( $_POST[ $key ] ) || ! is_scalar( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				continue;
			}
			$params[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		if ( isset( $_POST['pk_amenity'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$raw = wp_unslash( $_POST['pk_amenity'] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$params['pk_amenity'] = is_array( $raw ) ? $raw : ( is_scalar( $raw ) ? array( $raw ) : array() );
		}
		wp_send_json_success( array( 'count' => self::matching_count( $params ) ) );
	}

}

Partikulier_Search_Filters::init();
