<?php
/**
 * Module : creation automatique des versions arabe et anglaise d'une annonce.
 *
 * Une annonce deposee en francais devient TROIS pages distinctes, une par
 * langue, chacune avec sa propre URL, son titre, sa description et sa meta
 * description. Polylang les relie entre elles, ce qui alimente les balises
 * hreflang deja produites par class-seo.php.
 *
 * Google voit alors trois pages legitimes et non du contenu duplique.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Listing_Translations {

	/**
	 * Marque une annonce generee automatiquement.
	 */
	const META_GENERATED = '_pk_auto_translation';

	/**
	 * Memorise la langue source.
	 */
	const META_SOURCE = '_pk_translation_source';

	/**
	 * Langue du dépôt de l’annonce source, séparée de son ID source.
	 */
	const META_SOURCE_LANG = '_pk_source_lang';

	/**
	 * Polylang est-il disponible et configure ?
	 *
	 * @return bool
	 */
	public static function available() {
		return function_exists( 'pll_set_post_language' )
			&& function_exists( 'pll_save_post_translations' )
			&& function_exists( 'pll_languages_list' )
			&& function_exists( 'pll_get_post_translations' )
			&& function_exists( 'pll_get_post_language' );
	}

	/**
	 * Branche la réconciliation après les écritures de contenu.
	 *
	 * Polylang éjecte une ancienne traduction lorsqu'un éditeur lie un nouvel
	 * ID dans la même langue. L'ancienne auto-traduction reste alors publiée,
	 * mais n'appartient plus au groupe source. On la met en brouillon, sans
	 * suppression, dès que le groupe source révèle un remplacement manuel.
	 */
	public static function init_lifecycle_reconciliation() {
		add_action( 'save_post_' . PARTIKULIER_ESTATIK_POST_TYPE, array( __CLASS__, 'schedule_reconciliation' ), 99, 3 );
	}

	public static function schedule_reconciliation( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( ! self::available() || did_action( 'pk_translation_reconciliation_scheduled' ) ) {
			return;
		}
		do_action( 'pk_translation_reconciliation_scheduled' );
		add_action( 'shutdown', static function () {
			// Application automatique ciblée : seules les autos dont le groupe
			// Polylang révèle une remplaçante manuelle sont mises en brouillon.
			// Les autos seules restent publiées ; aucune suppression n'est faite.
			self::reconcile_orphans( true );
		}, 99 );
	}

	/**
	 * Réconcilie les autos orphelines. Une auto est obsolète si sa source FR
	 * possède désormais un autre ID dans sa langue. Elle passe en draft ; elle
	 * reste publiée lorsqu'elle est la seule version disponible.
	 *
	 * @param bool $apply false pour dry-run, true pour appliquer.
	 * @return array<int,array<string,mixed>>
	 */
	public static function reconcile_orphans( $apply = false ) {
		static $running = false;
		if ( $running || ! self::available() ) {
			return array();
		}
		$running = true;
		$results = array();
		$autos   = get_posts( array(
			'post_type'      => PARTIKULIER_ESTATIK_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'meta_key'       => self::META_GENERATED,
			'meta_value'     => '1',
		) );
		foreach ( $autos as $auto_id ) {
			$raw_source = get_post_meta( $auto_id, self::META_SOURCE, true );
			$lang       = pll_get_post_language( $auto_id, 'slug' );
			if ( ! ctype_digit( (string) $raw_source ) || (int) $raw_source <= 0 ) {
					$results[] = array(
						'auto_id' => (int) $auto_id,
						'action'  => 'invalid_source_meta',
						'value'   => (string) $raw_source,
					);
					continue;
				}
			$source_id = (int) $raw_source;
			$source    = $source_id ? pll_get_post_translations( $source_id ) : array();
			$replacement = $lang && ! empty( $source[ $lang ] ) ? (int) $source[ $lang ] : 0;
			if ( ! $source_id || ! $lang || ! $replacement || $replacement === (int) $auto_id ) {
				continue;
			}
			if ( get_post_meta( $replacement, self::META_GENERATED, true ) ) {
				continue;
			}
			$row = array( 'auto_id' => (int) $auto_id, 'source_id' => $source_id, 'language' => $lang, 'replacement_id' => $replacement, 'action' => 'draft' );
			if ( $apply ) {
				wp_update_post( array( 'ID' => (int) $auto_id, 'post_status' => 'draft' ) );
				$row['applied'] = true;
			} else {
				$row['applied'] = false;
			}
			$results[] = $row;
		}
		$running = false;
		return $results;
	}

	/**
	 * Vérifie l’état d’une auto-traduction sans appliquer de mutation.
	 */
	public static function orphan_report() {
		return self::reconcile_orphans( false );
	}

	/**
	 * Les URL traduites d'un CPT n'existent qu'apres regeneration des regles
	 * de reecriture. Sans cela, /en/property/... et /ar/property/... renvoient
	 * une 404 alors que les pages existent bien en base.
	 *
	 * On planifie la regeneration une seule fois, en fin de requete.
	 */
	public static function schedule_rewrite_flush() {
		if ( did_action( 'pk_rewrite_flush_scheduled' ) ) {
			return;
		}
		do_action( 'pk_rewrite_flush_scheduled' );
		add_action( 'shutdown', static function () {
			flush_rewrite_rules( false );
		} );
	}

	/**
	 * Langues actives du site, limitees a celles que le thème sait rediger.
	 *
	 * @return string[]
	 */
	public static function active_languages() {
		if ( ! function_exists( 'pll_languages_list' ) ) {
			return array();
		}
		$site = pll_languages_list( array( 'fields' => 'slug' ) );

		return array_values( array_intersect( Partikulier_Listing_I18n::languages(), (array) $site ) );
	}

	/**
	 * Cree (ou met a jour) les versions traduites d'une annonce.
	 *
	 * @param int    $post_id     Annonce source.
	 * @param array  $values      Donnees normalisees du formulaire.
	 * @param string $source_lang Langue de depot.
	 * @param string $extra       Mot personnel de l'annonceur, recopie tel quel.
	 * @return array Langue => ID de l'annonce.
	 */
	public static function sync( $post_id, $values, $source_lang = 'fr', $extra = '' ) {
		if ( ! self::available() ) {
			return array();
		}

		$languages = self::active_languages();
		if ( count( $languages ) < 2 ) {
			return array();
		}

		$source = get_post( $post_id );
		if ( ! $source ) {
			return array();
		}

		// La source porte la langue de depot.
		pll_set_post_language( $post_id, $source_lang );
		update_post_meta( $post_id, self::META_SOURCE_LANG, $source_lang );

		$map = array( $source_lang => $post_id );

		foreach ( $languages as $lang ) {
			if ( $lang === $source_lang ) {
				continue;
			}

			$title       = Partikulier_Listing_I18n::title( $values, $lang );
			$description = Partikulier_Listing_I18n::description( $values, $lang );
				if ( '' !== $extra ) {
					// Le mot personnel est recopie tel quel : c'est la voix de
					// l'annonceur, on ne la reecrit pas. Son bloc declare sa
					// langue francaise pour eviter de le faire passer pour une
					// traduction AR/EN.
					$note_label = 'ar' === $lang ? 'كلمة المالك (بالفرنسية)' : "Owner's note (in French)";
					$description .= sprintf(
						"\n\n<p class=\"pk-owner-note\"><strong>%s</strong><br><span lang=\"fr\">%s</span></p>",
						esc_html( $note_label ),
						esc_html( $extra )
					);
				}

			$existing = self::find_existing( $post_id, $lang );

			$payload = array(
				'post_type'    => PARTIKULIER_ESTATIK_POST_TYPE,
				'post_status'  => $source->post_status,
				'post_author'  => $source->post_author,
				'post_title'   => $title,
				'post_content' => $description,
				'post_excerpt' => wp_trim_words( $description, 30, '…' ), /* 6.17.30 : sans 3e arg, wp_trim_words colle l'entité littérale et celle-ci finissait en base + JSON-LD */
			);

			if ( $existing ) {
				$payload['ID'] = $existing;
				$translated_id = wp_update_post( $payload, true );
			} else {
				$translated_id = wp_insert_post( $payload, true );
			}

			if ( is_wp_error( $translated_id ) || ! $translated_id ) {
				continue;
			}

			pll_set_post_language( $translated_id, $lang );
			update_post_meta( $translated_id, self::META_GENERATED, '1' );
			update_post_meta( $translated_id, self::META_SOURCE, (string) $post_id );
			update_post_meta( $translated_id, self::META_SOURCE_LANG, $source_lang );
			update_post_meta( $translated_id, '_pk_meta_description', Partikulier_Listing_I18n::meta_description( $values, $lang ) );

			self::copy_data( $post_id, $translated_id, $values, $lang );

			$map[ $lang ] = $translated_id;
		}

		// Le lien Polylang : c'est lui qui produit les hreflang.
		pll_save_post_translations( $map );

		// Sans cela les URL /en/ et /ar/ renvoient une 404 au premier depot.
		if ( count( $map ) > 1 ) {
			self::schedule_rewrite_flush();
		}

		return $map;
	}

	/**
	 * Retrouve une traduction deja creee.
	 *
	 * @param int    $post_id Annonce source.
	 * @param string $lang    Langue cible.
	 * @return int
	 */
	private static function find_existing( $post_id, $lang ) {
		if ( ! function_exists( 'pll_get_post' ) ) {
			return 0;
		}
		$found = pll_get_post( $post_id, $lang );

		return ( $found && get_post( $found ) ) ? (int) $found : 0;
	}

	/**
	 * Recopie metas, taxonomies et images vers la traduction.
	 *
	 * @param int    $source_id     Annonce source.
	 * @param int    $translated_id Annonce traduite.
	 * @param array  $values        Donnees normalisees.
	 * @param string $lang          Langue cible.
	 */
	private static function copy_data( $source_id, $translated_id, $values, $lang ) {
		// Metas factuelles : identiques dans toutes les langues.
		$metas = array(
			'es_property_price', 'es_property_area', 'es_property_total_rooms',
			'es_property_bedrooms', 'es_property_bathrooms', 'es_property_gallery',
			'_pk_bedrooms_label', '_pk_living_rooms', '_pk_living_rooms_label',
			'_pk_bathrooms_label', '_pk_terrace', '_pk_terrace_surface',
			'_pk_vis_a_vis', '_pk_sunshine', '_pk_floor', '_pk_garage', '_pk_elevator',
			'_pk_owner_name', '_pk_owner_email', '_pk_owner_phone', '_pk_owner_role',
			'_pk_status', '_pk_place_status',
		);
		foreach ( $metas as $key ) {
			$value = get_post_meta( $source_id, $key, true );
			if ( '' !== $value && null !== $value ) {
				update_post_meta( $translated_id, $key, $value );
			}
		}

		// Image mise en avant + alt traduit.
		$thumb = get_post_thumbnail_id( $source_id );
		if ( $thumb ) {
			set_post_thumbnail( $translated_id, $thumb );
		}

		// Taxonomies : Polylang peut avoir des termes par langue, on tente la
		// traduction du terme et on retombe sur le terme source sinon.
		$taxonomies = array(
PARTIKULIER_ESTATIK_TYPE_TAXONOMY,
				PARTIKULIER_ESTATIK_STATUS_TAXONOMY,
				PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY,
			PARTIKULIER_ESTATIK_LOCATION_TAXONOMY,
		);
		foreach ( $taxonomies as $taxonomy ) {
			$terms = wp_get_object_terms( $source_id, $taxonomy, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) || ! $terms ) {
				continue;
			}
			$targets = array();
			foreach ( $terms as $term_id ) {
				$translated_term = function_exists( 'pll_get_term' ) ? pll_get_term( $term_id, $lang ) : 0;
				$targets[]       = $translated_term ? (int) $translated_term : (int) $term_id;
			}
			wp_set_object_terms( $translated_id, $targets, $taxonomy );
		}
	}

	/**
	 * Propage un changement de statut a toutes les traductions.
	 *
	 * @param int    $post_id Annonce source.
	 * @param string $status  Statut a appliquer.
	 */
	public static function sync_status( $post_id, $status ) {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return;
		}
		foreach ( pll_get_post_translations( $post_id ) as $translated_id ) {
			if ( (int) $translated_id === (int) $post_id ) {
				continue;
			}
			if ( get_post_status( $translated_id ) === $status ) {
				continue;
			}
			wp_update_post( array(
				'ID'          => (int) $translated_id,
				'post_status' => $status,
			) );
		}
	}
}


Partikulier_Listing_Translations::init_lifecycle_reconciliation();
