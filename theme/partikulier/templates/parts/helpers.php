<?php
/**
 * Helpers globaux du theme : pagination numerotee SEO-friendly,
 * fonctions d'inclusion de templates maison.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pagination numerotee avec rel=prev/next pour le SEO.
 */
function pk_pagination() {
	global $wp_query;
	if ( $wp_query->max_num_pages < 2 ) {
		return;
	}

	$current = max( 1, get_query_var( 'paged' ) );
	$total   = $wp_query->max_num_pages;

	// Rel prev/next.
	if ( $current > 1 ) {
		printf(
			'<link rel="prev" href="%s">',
			esc_url( get_pagenum_link( $current - 1 ) )
		);
	}
	if ( $current < $total ) {
		printf(
			'<link rel="next" href="%s">',
			esc_url( get_pagenum_link( $current + 1 ) )
		);
	}

	echo '<nav class="pk-pagination" aria-label="' . esc_attr__( 'Pagination', 'partikulier' ) . '">';
	echo '<ol class="pk-pagination-list">';

	for ( $i = 1; $i <= $total; $i++ ) {
		// Fenetre de 5 pages autour de la page courante.
		if ( $i > 1 && $i < $total && abs( $i - $current ) > 2 ) {
			if ( abs( $i - $current ) === 3 ) {
				echo '<li class="pk-pagination-ellipsis" aria-hidden="true">…</li>';
			}
			continue;
		}
		if ( $i === $current ) {
			echo '<li><span aria-current="page" class="pk-pagination-current">' . esc_html( number_format_i18n( $i ) ) . '</span></li>';
		} else {
			printf(
				'<li><a href="%s">%s</a></li>',
				esc_url( get_pagenum_link( $i ) ),
				esc_html( number_format_i18n( $i ) )
			);
		}
	}

	echo '</ol>';
	echo '</nav>';
}

/**
 * Chargement d'un template part avec localisation du theme maison.
 */
function pk_get_part( $slug ) {
	$file = PARTIKULIER_DIR . '/templates/parts/' . $slug . '.php';
	if ( file_exists( $file ) ) {
		include $file;
	}
}

/**
 * Chargement des templates principaux du theme maison (contour header/footer inclus).
 */
function pk_get_template( $slug ) {
	$file = PARTIKULIER_DIR . '/templates/' . $slug . '.php';
	if ( file_exists( $file ) ) {
		include $file;
	}
}

/**
 * URL d'un terme, toujours une chaine (jamais un WP_Error).
 *
 * get_term_link() renvoie un WP_Error si la taxonomie n'est pas encore
 * enregistree ou si les regles de reecriture sont obsoletes (fenetre juste
 * apres l'activation du theme). Passe a esc_url(), cela declenche une erreur
 * fatale sous PHP 8 : ltrim() n'accepte pas un WP_Error.
 */
if ( ! function_exists( 'pk_term_url' ) ) {
	function pk_term_url( $term, $fallback = '' ) {
		if ( is_object( $term ) && isset( $term->taxonomy ) && PARTIKULIER_ESTATIK_LOCATION_TAXONOMY === $term->taxonomy ) {
			$archive = function_exists( 'pk_properties_archive_url' ) ? pk_properties_archive_url() : home_url( '/' );
			return add_query_arg( 'location', sanitize_title( (string) $term->slug ), $archive );
		}
		$link = get_term_link( $term );
		if ( is_wp_error( $link ) || ! is_string( $link ) ) {
				return $fallback ? $fallback : ( function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' ) );
		}

				// Estatik renvoie parfois une taxonomie sans le préfixe Polylang.
				// Reprendre le terme traduit puis préfixer explicitement le chemin
				// garantit que le crawl reste dans la langue de la page courante.
		if ( function_exists( 'pll_current_language' ) && function_exists( 'pll_home_url' ) ) {
			$language = sanitize_key( (string) pll_current_language( 'slug' ) );
			if ( $language ) {
				$term_id = is_object( $term ) && isset( $term->term_id ) ? (int) $term->term_id : 0;
				if ( $term_id && function_exists( 'pll_get_term' ) ) {
					$translated_id = (int) pll_get_term( $term_id, $language );
					if ( $translated_id ) {
						$translated_link = get_term_link( $translated_id, is_object( $term ) ? $term->taxonomy : '' );
						if ( ! is_wp_error( $translated_link ) && is_string( $translated_link ) ) {
							$link = $translated_link;
						}
					}
				}
				$path = (string) wp_parse_url( $link, PHP_URL_PATH );
				if ( ! preg_match( '#^/' . preg_quote( $language, '#' ) . '(?:/|$)#', $path ) ) {
					$link = pk_localized_home_url( $language ) . ltrim( $path, '/' );
				}
			}
		}

				return $link;
	}
}

/**
 * Milliers avec une espace. Jamais de virgule anglaise, jamais de chiffres arabes.
 *
 * @param mixed $price Montant.
 * @return string
 */
if ( ! function_exists( 'pk_format_amount' ) ) {
	function pk_format_amount( $price ) {
		if ( ! is_numeric( $price ) ) {
			return (string) $price;
		}
		$negative = (float) $price < 0;
		$digits   = preg_replace( '/\D/', '', (string) abs( (float) $price ) );
		$digits   = ltrim( (string) $digits, '0' );
		if ( '' === $digits ) {
			$digits = '0';
		}
		$grouped = preg_replace( '/\B(?=(\d{3})+(?!\d))/', ' ', $digits );
		return $negative ? '-' . $grouped : $grouped;
	}
}

/**
 * Unité complète. Location : DH / mois, DH/month, درهم / شهر.
 *
 * @param bool $rent Location.
 * @return string
 */
if ( ! function_exists( 'pk_price_unit' ) ) {
	function pk_price_unit( $rent = false ) {
		$currency = apply_filters( 'partikulier_currency', 'DH' );
		if ( ! $rent ) {
			return $currency;
		}
		$lang = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
		if ( 'ar' === $lang ) {
			return 'درهم / شهر';
		}
		if ( 'en' === $lang ) {
			return 'DH/month';
		}
		return 'DH / mois';
	}
}

if ( ! function_exists( 'pk_rent_suffix' ) ) {
	function pk_rent_suffix() {
		$lang = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
		if ( 'ar' === $lang ) {
			return '/ شهر';
		}
		if ( 'en' === $lang ) {
			return '/month';
		}
		return '/ mois';
	}
}

/**
 * Prix formate + devise, utilise par les cartes ET la fiche.
 *
 * DH, ou درهم en arabe. Jamais MAD.
 */
if ( ! function_exists( 'pk_price_html' ) ) {
	function pk_price_html( $price ) {
		if ( '' === $price || null === $price ) {
			return esc_html__( 'Prix sur demande', 'partikulier' );
		}
		$formatted = pk_format_amount( $price );
		$currency  = apply_filters( 'partikulier_currency', 'DH' );
		return esc_html( trim( $formatted . ' ' . $currency ) );
	}
}

/**
 * Icone SVG par type de bien, choisie sur le slug du terme.
 *
 * Le theme affichait le caractere "⌂" pour tous les types : le preview React
 * utilise une icone dessinee par categorie. Repli sur l'icone maison.
 */
if ( ! function_exists( 'pk_type_icon' ) ) {
	function pk_type_icon( $slug = '' ) {
		$slug  = sanitize_title( (string) $slug );
		$paths = array(
			'appartement' => '<path d="M4 21V5a1 1 0 0 1 1-1h9a1 1 0 0 1 1 1v16"/><path d="M15 21V9h4a1 1 0 0 1 1 1v11"/><path d="M2 21h20"/><path d="M7 8h2M7 12h2M7 16h2"/>',
			'maison'      => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M10 21v-6h4v6"/>',
			'villa'       => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.5V21h14V9.5"/><path d="M9 13h2v3H9zM13 13h2v3h-2z"/>',
			'terrain'     => '<path d="M12 3 3 8v8l9 5 9-5V8z"/><path d="M3 8l9 5 9-5"/><path d="M12 13v8"/>',
			'parking'     => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M10 16V8h3a2.5 2.5 0 0 1 0 5h-3"/>',
			'immeuble'    => '<rect x="4" y="3" width="16" height="18" rx="1"/><path d="M8 7h2M14 7h2M8 11h2M14 11h2M8 15h2M14 15h2"/>',
			'local'       => '<path d="M3 9l1.5-5h15L21 9"/><path d="M4 9v12h16V9"/><path d="M3 9h18"/><path d="M9 21v-6h6v6"/>',
			'loft'        => '<path d="M3 21V8l9-5 9 5v13"/><path d="M3 12h18M9 21V12M15 21V12"/>',
			'studio'      => '<rect x="3" y="5" width="18" height="14" rx="1"/><path d="M3 15h18"/><path d="M7 15V9h4v6"/>',
		);
		$d     = isset( $paths[ $slug ] ) ? $paths[ $slug ] : $paths['maison'];
		return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
	}
}

/**
 * Phrase de compte deja traduite. Nombre exact seulement.
 * Jamais « ou plus », jamais 3+, jamais 6+.
 *
 * @param string $raw  Valeur enregistree.
 * @param string $kind bed|salon|bath|suite|bureau|sdb.
 * @param string $lang fr|en|ar.
 * @return string
 */
if ( ! function_exists( 'pk_card_count_phrase' ) ) {
	function pk_card_count_phrase( $raw, $kind, $lang ) {
		$raw  = trim( (string) $raw );
		$lang = in_array( $lang, array( 'en', 'ar' ), true ) ? $lang : 'fr';
		if ( '' === $raw || '0' === $raw || false !== strpos( $raw, '+' ) ) {
			return '';
		}
		if ( ! preg_match( '/^\d+$/', $raw ) ) {
			return '';
		}
		$n = (int) $raw;
		if ( $n < 1 ) {
			return '';
		}
		if ( 'suite' === $kind ) {
			if ( 'en' === $lang ) {
				return $n . ' ' . ( 1 === $n ? 'suite' : 'suites' );
			}
			if ( 'ar' === $lang ) {
				return $n . ' ' . ( 1 === $n ? 'جناح' : 'أجنحة' );
			}
			return $n . ' ' . ( 1 === $n ? 'suite' : 'suites' );
		}
		if ( 'bureau' === $kind ) {
			if ( 'en' === $lang ) {
				return $n . ' ' . ( 1 === $n ? 'office' : 'offices' );
			}
			if ( 'ar' === $lang ) {
				return $n . ' ' . ( 1 === $n ? 'مكتب' : 'مكاتب' );
			}
			return $n . ' ' . ( 1 === $n ? 'bureau' : 'bureaux' );
		}
		if ( 'sdb' === $kind ) {
			if ( 'en' === $lang ) {
				return $n . ' ' . ( 1 === $n ? 'bathroom' : 'bathrooms' );
			}
			if ( 'ar' === $lang ) {
				return $n . ' ' . ( 1 === $n ? 'حمام' : 'حمامات' );
			}
			return $n . ' SDB';
		}
		if ( 1 === $n || 2 === $n ) {
			if ( 'bath' === $kind ) {
				$key = 1 === $n ? '1 salle de bains' : '2 salles de bains';
			} elseif ( 'salon' === $kind ) {
				$key = 1 === $n ? '1 salon' : '2 salons';
			} else {
				$key = 1 === $n ? '1 chambre' : '2 chambres';
			}
			return class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $key, $key, 'partikulier' ) : $key;
		}
		if ( 'bath' === $kind ) {
			if ( 'en' === $lang ) {
				return $n . ' bathrooms';
			}
			if ( 'ar' === $lang ) {
				return $n . ' حمامات';
			}
			return $n . ' salles de bains';
		}
		if ( 'salon' === $kind ) {
			if ( 'en' === $lang ) {
				return $n . ' living rooms';
			}
			if ( 'ar' === $lang ) {
				return $n . ' غرف جلوس';
			}
			return $n . ' salons';
		}
		if ( 'en' === $lang ) {
			return $n . ' bedrooms';
		}
		if ( 'ar' === $lang ) {
			return $n . ' غرف نوم';
		}
		return $n . ' chambres';
	}
}

/**
 * Nombre de niveaux, nombre exact. Les codes R+ restent tels quels.
 *
 * @param string $raw  Valeur enregistree.
 * @param string $lang Langue.
 * @return string
 */
if ( ! function_exists( 'pk_card_levels_phrase' ) ) {
	function pk_card_levels_phrase( $raw, $lang ) {
		$raw  = trim( (string) $raw );
		$lang = in_array( $lang, array( 'en', 'ar' ), true ) ? $lang : 'fr';
		if ( '' === $raw || false !== strpos( $raw, '+' ) ) {
			return '';
		}
		if ( preg_match( '/^R\+/i', $raw ) ) {
			return $raw;
		}
		if ( ! preg_match( '/^\d+$/', $raw ) ) {
			return class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $raw, $raw, 'partikulier' ) : $raw;
		}
		$n = (int) $raw;
		if ( $n < 1 ) {
			return '';
		}
		if ( 1 === $n ) {
			$key = '1 niveau';
		} elseif ( 2 === $n ) {
			$key = '2 niveaux';
		} elseif ( 3 === $n ) {
			$key = '3 niveaux';
		} else {
			$key = '';
		}
		if ( $key && class_exists( 'Partikulier_Localization' ) ) {
			$known = Partikulier_Localization::translate_polylang_string( $key, $key, 'partikulier' );
			if ( $known !== $key || 'fr' === $lang ) {
				return $known;
			}
		}
		if ( 'en' === $lang ) {
			return $n . ' levels';
		}
		if ( 'ar' === $lang ) {
			return $n . ' طوابق';
		}
		return $n . ' niveaux';
	}
}

/**
 * Premiere meta non vide.
 *
 * @param int   $post_id Identifiant.
 * @param array $keys    Cles.
 * @return string
 */
if ( ! function_exists( 'pk_card_meta_first' ) ) {
	function pk_card_meta_first( $post_id, $keys ) {
		foreach ( $keys as $key ) {
			$value = trim( (string) get_post_meta( $post_id, $key, true ) );
			if ( '' !== $value ) {
				return $value;
			}
		}
		return '';
	}
}

/**
 * Etages au format demande : RDC+N, sans le mot etage. Rien si le nombre n'est pas enregistre.
 *
 * @param string $raw  Valeur enregistree.
 * @param string $lang Langue.
 * @return string
 */
if ( ! function_exists( 'pk_card_etages_phrase' ) ) {
	function pk_card_etages_phrase( $raw, $lang ) {
		$raw  = trim( (string) $raw );
		$lang = in_array( $lang, array( 'en', 'ar' ), true ) ? $lang : 'fr';
		if ( '' === $raw ) {
			return '';
		}
		$plus = null;
		if ( preg_match( '/RDC\s*\+\s*(\d+)/iu', $raw, $m ) ) {
			$plus = (int) $m[1];
		} elseif ( preg_match( '/^R\+\s*(\d+)$/iu', $raw, $m ) ) {
			$plus = (int) $m[1];
		} elseif ( preg_match( '/rez(?:-de-chaussée|-de-chaussee)?\s*\+\s*(\d+)/iu', $raw, $m ) ) {
			$plus = (int) $m[1];
		} elseif ( preg_match( '/^rdc\s*(\d+)$/iu', $raw, $m ) ) {
			$plus = (int) $m[1];
		} elseif ( preg_match( '/(\d+)\s*niveaux/iu', $raw, $m ) && false === strpos( $raw, '+' ) ) {
			$plus = max( 0, (int) $m[1] - 1 );
		} elseif ( preg_match( '/^(\d+)$/', $raw, $m ) ) {
			$n = (int) $m[1];
			if ( $n < 1 ) {
				return '';
			}
			$plus = max( 0, $n - 1 );
		} elseif ( preg_match( '/^(rdc|rez|plain)/iu', $raw ) && false === strpos( $raw, '+' ) ) {
			$plus = 0;
		}
		if ( null === $plus ) {
			return '';
		}
		if ( 'ar' === $lang ) {
			return 0 === $plus ? 'الطابق الأرضي' : 'أرضي + ' . $plus;
		}
		return 0 === $plus ? 'RDC' : 'RDC+' . $plus;
	}
}

/**
 * Espace ouvert, formulation demandee. La traduction arabe est celle du formulaire.
 *
 * @param string $lang Langue.
 * @return string
 */
if ( ! function_exists( 'pk_card_open_space_phrase' ) ) {
	function pk_card_open_space_phrase( $lang ) {
		$lang = in_array( $lang, array( 'en', 'ar' ), true ) ? $lang : 'fr';
		if ( 'ar' === $lang ) {
			return 'مساحة مفتوحة';
		}
		if ( 'en' === $lang ) {
			return 'open space';
		}
		return 'espace ouvert';
	}
}

/**
 * Nature du lot, liste fermee. Champ vide : rien.
 *
 * @param int    $post_id Identifiant.
 * @param string $slug    Slug.
 * @return string
 */
if ( ! function_exists( 'pk_card_lot_nature' ) ) {
	function pk_card_lot_nature( $post_id, $slug ) {
		if ( 'ferme' === $slug ) {
			return 'ferme';
		}
		$raw = pk_card_meta_first(
			$post_id,
			array( '_pk_lot_nature', '_pk_nature_lot', '_pk_nature', '_pk_lot_type', '_pk_lot_kind' )
		);
		$rplus = pk_card_meta_first( $post_id, array( '_pk_constructibilite', '_pk_rplus', '_pk_autorisation' ) );
		$hay   = strtolower( $raw . ' ' . $rplus );
		if ( '' === trim( $hay ) ) {
			return '';
		}
		if ( false !== strpos( $hay, 'agric' ) ) {
			return 'agricole';
		}
		if ( false !== strpos( $hay, 'industriel' ) ) {
			return 'industriel';
		}
		if ( false !== strpos( $hay, 'depot' ) || false !== strpos( $hay, 'dépôt' ) || false !== strpos( $hay, 'entrepot' ) || false !== strpos( $hay, 'entrepôt' ) ) {
			return 'dépôt';
		}
		if ( false !== strpos( $hay, 'resident' ) || false !== strpos( $hay, 'résident' ) ) {
			return 'résidentiel';
		}
		if ( false !== strpos( $hay, 'commerc' ) ) {
			return 'commercial';
		}
		if ( false !== strpos( $hay, 'ferme' ) ) {
			return 'ferme';
		}
		return '';
	}
}

/**
 * Libelle court de la nature, pour le lien de type.
 *
 * @param string $nature Code ferme.
 * @param string $lang   Langue.
 * @return string
 */
if ( ! function_exists( 'pk_card_nature_phrase' ) ) {
	function pk_card_nature_phrase( $nature, $lang ) {
		$nature = (string) $nature;
		$lang   = in_array( $lang, array( 'en', 'ar' ), true ) ? $lang : 'fr';
		$phrases = array(
			'fr' => array(
				'agricole'     => 'agricole',
				'industriel'   => 'industriel',
				'dépôt'        => 'dépôt',
				'résidentiel'  => 'résidentiel',
				'commercial'   => 'commercial',
				'ferme'        => 'ferme',
			),
			'en' => array(
				'agricole'     => 'agricultural',
				'industriel'   => 'industrial',
				'dépôt'        => 'depot',
				'résidentiel'  => 'residential',
				'commercial'   => 'commercial',
				'ferme'        => 'ferme',
			),
			'ar' => array(
				'agricole'     => 'فلاحية',
				'industriel'   => 'صناعية',
				'dépôt'        => 'مستودع',
				'résidentiel'  => 'سكنية',
				'commercial'   => 'تجارية',
				'ferme'        => 'ferme',
			),
		);
		return isset( $phrases[ $lang ][ $nature ] ) ? $phrases[ $lang ][ $nature ] : '';
	}
}

/**
 * Texte du lien de type. Terrain : la nature suit le nom, si elle est enregistree.
 *
 * @param string $type_name Nom deja traduit.
 * @param string $slug      Slug.
 * @param int    $post_id   Identifiant.
 * @param string $lang      Langue.
 * @return string
 */
if ( ! function_exists( 'pk_card_type_link_label' ) ) {
	function pk_card_type_link_label( $type_name, $slug, $post_id, $lang ) {
		$name = trim( (string) $type_name );
		$slug = sanitize_title( (string) $slug );
		if ( ! in_array( $slug, array( 'terrain', 'lot' ), true ) ) {
			return $name;
		}
		$nature = pk_card_lot_nature( $post_id, $slug );
		if ( ! $nature || 'ferme' === $nature ) {
			return $name;
		}
		$phrase = pk_card_nature_phrase( $nature, $lang );
		if ( ! $phrase ) {
			return $name;
		}
		return trim( $name . ' ' . $phrase );
	}
}


if ( ! function_exists( 'pk_card_kind_label' ) ) {
	/**
	 * Type a afficher quand la deuxieme ligne n'a que la superficie.
	 *
	 * @param int    $post_id Identifiant.
	 * @param string $slug    Slug.
	 * @param string $lang    Langue.
	 * @return string
	 */
	function pk_card_kind_label( $post_id, $slug, $lang ) {
		$slug  = sanitize_title( (string) $slug );
		$names = array(
			'terrain'  => 'Terrain',
			'hangar'   => 'Hangar',
			'depot'    => 'Dépôt',
			'usine'    => 'Usine',
			'immeuble' => 'Immeuble',
			'ferme'    => 'Ferme',
			'lot'      => 'Lot',
		);
		if ( ! isset( $names[ $slug ] ) ) {
			return '';
		}
		$name = $names[ $slug ];
		if ( class_exists( 'Partikulier_Localization' ) ) {
			$name = Partikulier_Localization::translate_polylang_string( $name, $name, 'partikulier' );
		}
		if ( 'lot' === $slug ) {
			$raw = strtolower( pk_card_meta_first( $post_id, array( '_pk_lot_nature', '_pk_nature_lot', '_pk_nature', '_pk_lot_type', '_pk_lot_kind' ) ) );
			if ( false !== strpos( $raw, 'villa' ) ) {
				return trim( $name . ' villa' );
			}
		}
		if ( in_array( $slug, array( 'terrain', 'lot' ), true ) && function_exists( 'pk_card_type_link_label' ) ) {
			return pk_card_type_link_label( $name, $slug, $post_id, $lang );
		}
		return $name;
	}
}

if ( ! function_exists( 'pk_card_finish_facts' ) ) {
	/**
	 * Si la ligne n'a que la superficie, le type la precede.
	 *
	 * @param int    $post_id Identifiant.
	 * @param string $slug    Slug.
	 * @param string $lang    Langue.
	 * @param array  $bits    Morceaux deja construits.
	 * @return string
	 */
	function pk_card_finish_facts( $post_id, $slug, $lang, $bits ) {
		$bits = array_values( array_filter( (array) $bits ) );
		$only_surface = 1 === count( $bits ) && false !== strpos( (string) $bits[0], 'm²' );
		if ( $only_surface ) {
			$kind = pk_card_kind_label( $post_id, $slug, $lang );
			if ( $kind ) {
				array_unshift( $bits, $kind );
			}
		}
		return implode( ' · ', $bits );
	}
}

/**
 * Une seule ligne de faits, selon le type. Champ vide : omis. Rien n'est invente.
 *
 * @param int    $post_id Identifiant.
 * @param string $slug    Slug du type.
 * @param string $lang    Langue.
 * @return string
 */
if ( ! function_exists( 'pk_card_fact_line' ) ) {
	function pk_card_fact_line( $post_id, $slug, $lang ) {
		$slug = sanitize_title( (string) $slug );
		$lang = in_array( $lang, array( 'en', 'ar' ), true ) ? $lang : 'fr';
		$bits = array();
		$surface = get_post_meta( $post_id, 'es_property_area', true );
		if ( '' === (string) $surface ) {
			$surface = get_post_meta( $post_id, 'es_size', true );
		}
		if ( is_numeric( $surface ) && (int) $surface > 0 ) {
			$unit   = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'm²', 'm²', 'partikulier' ) : 'm²';
			$bits[] = trim( ( function_exists( 'pk_format_amount' ) ? pk_format_amount( $surface ) : (string) (int) $surface ) . ' ' . $unit );
		}
		$beds_raw   = pk_card_meta_first( $post_id, array( '_pk_bedrooms_label', 'es_property_bedrooms', 'es_bedrooms' ) );
		$salons_raw = pk_card_meta_first( $post_id, array( '_pk_living_rooms', '_pk_living_rooms_label' ) );
		$baths_raw  = pk_card_meta_first( $post_id, array( '_pk_bathrooms_label', 'es_property_bathrooms', 'es_bathrooms' ) );
		$levels_raw = pk_card_meta_first( $post_id, array( '_pk_niveaux', '_pk_niveaux_label', '_pk_etages', '_pk_etages_label', '_pk_levels', '_pk_floors' ) );
		$etages     = pk_card_etages_phrase( $levels_raw, $lang );

		if ( in_array( $slug, array( 'appartement', 'duplex', 'villa' ), true ) ) {
			$bed_label   = pk_card_count_phrase( $beds_raw, 'bed', $lang );
			$salon_label = pk_card_count_phrase( $salons_raw, 'salon', $lang );
			if ( $bed_label ) {
				$bits[] = $bed_label;
			}
			if ( $salon_label ) {
				$bits[] = $salon_label;
			}
			return pk_card_finish_facts( $post_id, $slug, $lang, $bits );
		}
		if ( 'studio' === $slug ) {
			if ( '' === $beds_raw || '0' === $beds_raw ) {
				$beds_raw = '1';
			}
			$bed_label   = pk_card_count_phrase( $beds_raw, 'bed', $lang );
			$salon_label = pk_card_count_phrase( $salons_raw, 'salon', $lang );
			if ( $bed_label ) {
				$bits[] = $bed_label;
			}
			if ( $salon_label ) {
				$bits[] = $salon_label;
			}
			return pk_card_finish_facts( $post_id, $slug, $lang, $bits );
		}
		if ( 'riad' === $slug ) {
			$bed_label = pk_card_count_phrase( $beds_raw, 'bed', $lang );
			if ( $bed_label ) {
				$bits[] = $bed_label;
			}
			$suite_raw = pk_card_meta_first( $post_id, array( '_pk_suites', '_pk_suites_label', '_pk_suite_count' ) );
			if ( '' === $suite_raw ) {
				$suite_raw = $salons_raw;
			}
			if ( '0' === $suite_raw ) {
				if ( $etages ) {
					$bits[] = $etages;
				} else {
					$levels = pk_card_levels_phrase( $levels_raw, $lang );
					if ( $levels ) {
						$bits[] = $levels;
					}
				}
			} else {
				$suite_label = pk_card_count_phrase( $suite_raw, 'suite', $lang );
				if ( $suite_label ) {
					$bits[] = $suite_label;
				}
			}
			return pk_card_finish_facts( $post_id, $slug, $lang, $bits );
		}
		if ( 'maison' === $slug ) {
			$bed_label = pk_card_count_phrase( $beds_raw, 'bed', $lang );
			if ( $bed_label ) {
				$bits[] = $bed_label;
			}
			if ( $etages ) {
				$bits[] = $etages;
			}
			return pk_card_finish_facts( $post_id, $slug, $lang, $bits );
		}
		if ( 'immeuble' === $slug ) {
			if ( $etages ) {
				$bits[] = $etages;
			}
			return pk_card_finish_facts( $post_id, $slug, $lang, $bits );
		}
		if ( in_array( $slug, array( 'bureau', 'bureaux' ), true ) ) {
			$layout = strtolower( pk_card_meta_first( $post_id, array( '_pk_amenagement', '_pk_bureau_layout', '_pk_open_space', '_pk_espace' ) ) );
			$bureaux_raw = pk_card_meta_first( $post_id, array( '_pk_bureaux_fermes', '_pk_bureaux', '_pk_offices', '_pk_bureaux_label' ) );
			$open = ( false !== strpos( $layout, 'ouvert' ) || false !== strpos( $layout, 'open' ) || '0' === $bureaux_raw );
			if ( $open ) {
				$bits[] = pk_card_open_space_phrase( $lang );
			} else {
				$bureaux = pk_card_count_phrase( $bureaux_raw, 'bureau', $lang );
				if ( $bureaux ) {
					$bits[] = $bureaux;
				}
			}
			$sdb = pk_card_count_phrase( $baths_raw, 'sdb', $lang );
			if ( $sdb ) {
				$bits[] = $sdb;
			}
			return pk_card_finish_facts( $post_id, $slug, $lang, $bits );
		}
		if ( in_array( $slug, array( 'local', 'local-commercial', 'commerce' ), true ) ) {
			$kind = pk_card_commercial_kind( $post_id, $slug );
			if ( $kind ) {
				array_unshift( $bits, $kind );
			}
			return pk_card_finish_facts( $post_id, $slug, $lang, $bits );
		}
		return pk_card_finish_facts( $post_id, $slug, $lang, $bits );
	}
}

/**
 * Local commercial. Depot, hangar et usine n'ont que la surface.
 *
 * @param int    $post_id Identifiant.
 * @param string $slug    Slug.
 * @return string
 */
if ( ! function_exists( 'pk_card_commercial_kind' ) ) {
	function pk_card_commercial_kind( $post_id, $slug ) {
		$bat  = strtolower( pk_card_meta_first( $post_id, array( '_pk_batiment', '_pk_local_kind', '_pk_sous_type' ) ) );
		$slug = sanitize_title( (string) $slug );
		if ( in_array( $slug, array( 'local', 'local-commercial', 'commerce' ), true ) ) {
			if ( false !== strpos( $bat, 'hangar' ) ) {
				return 'Hangar';
			}
			if ( false !== strpos( $bat, 'entrepot' ) || false !== strpos( $bat, 'entrepôt' ) || false !== strpos( $bat, 'depot' ) || false !== strpos( $bat, 'dépôt' ) ) {
				return 'Entrepôt';
			}
			if ( false !== strpos( $bat, 'bureau' ) ) {
				return 'Bureau';
			}
			return 'Local commercial';
		}
		return '';
	}
}

/**
 * Longueur et decoupe UTF-8, pour le titre de carte.
 *
 * @param string   $text  Texte.
 * @param int      $start Debut.
 * @param int|null $len   Longueur.
 * @return string
 */
if ( ! function_exists( 'pk_card_mb_sub' ) ) {
	function pk_card_mb_sub( $text, $start, $len = null ) {
		if ( function_exists( 'mb_substr' ) ) {
			return null === $len ? mb_substr( $text, $start, null, 'UTF-8' ) : mb_substr( $text, $start, $len, 'UTF-8' );
		}
		return null === $len ? substr( $text, $start ) : substr( $text, $start, $len );
	}
}

if ( ! function_exists( 'pk_card_mb_len' ) ) {
	function pk_card_mb_len( $text ) {
		return function_exists( 'mb_strlen' ) ? mb_strlen( $text, 'UTF-8' ) : strlen( $text );
	}
}

/**
 * Titre entier, avec le type et la ville cliquables quand ils bordent la phrase.
 *
 * @param string $title       Titre affiche.
 * @param string $type_match  Mot du type, tel qu'il ouvre le titre.
 * @param string $type_text   Texte du lien de type.
 * @param string $type_url    Archive du type.
 * @param string $city_label  Ville.
 * @param string $city_url    Annonces de la ville.
 * @param string $listing_url Annonce.
 * @return string
 */
if ( ! function_exists( 'pk_card_title_html' ) ) {
	function pk_card_title_html( $title, $type_match, $type_text, $type_url, $city_label, $city_url, $listing_url ) {
		$title      = trim( (string) $title );
		$type_match = trim( (string) $type_match );
		$type_text  = trim( (string) $type_text );
		$city_label = trim( (string) $city_label );
		$rest       = $title;
		$parts      = array();
		if ( $type_match && $type_url && $type_text && pk_card_mb_sub( $rest, 0, pk_card_mb_len( $type_match ) ) === $type_match ) {
			$parts[] = '<a class="pk-card-jump" href="' . esc_url( $type_url ) . '">' . esc_html( $type_text ) . '</a>';
			$rest    = pk_card_mb_sub( $rest, pk_card_mb_len( $type_match ) );
		}
		$city_hit = '';
		$cities   = array( $city_label );
		foreach ( array( ',', '،' ) as $comma ) {
			$pos = strrpos( $city_label, $comma );
			if ( false !== $pos ) {
				$cities[] = trim( substr( $city_label, $pos + strlen( $comma ) ) );
			}
		}
		foreach ( $cities as $city ) {
			if ( '' === $city || ! $city_url ) {
				continue;
			}
			$clen = pk_card_mb_len( $city );
			if ( $clen < 1 || pk_card_mb_len( $rest ) <= $clen ) {
				continue;
			}
			if ( pk_card_mb_sub( $rest, pk_card_mb_len( $rest ) - $clen, $clen ) === $city ) {
				$city_hit = $city;
				$rest     = pk_card_mb_sub( $rest, 0, pk_card_mb_len( $rest ) - $clen );
				break;
			}
		}
		if ( '' !== $rest ) {
			$parts[] = '<a href="' . esc_url( $listing_url ) . '" itemprop="url">' . esc_html( $rest ) . '</a>';
		}
		if ( '' !== $city_hit ) {
			$parts[] = '<a class="pk-card-jump" href="' . esc_url( $city_url ) . '">' . esc_html( $city_hit ) . '</a>';
		}
		if ( ! $parts ) {
			return '<a href="' . esc_url( $listing_url ) . '" itemprop="url">' . esc_html( $title ) . '</a>';
		}
		return implode( '', $parts );
	}
}

if ( ! function_exists( 'pk_card_fact_icon' ) ) {
	/**
	 * Petite icone de fait, mobile seulement. Le texte reste inchange.
	 *
	 * @param string $bit Morceau deja affiche.
	 * @return string
	 */
	function pk_card_fact_icon( $bit ) {
		$t = function_exists( 'mb_strtolower' ) ? mb_strtolower( (string) $bit ) : strtolower( (string) $bit );
		$kind = 'mark';
		if ( false !== strpos( $t, 'm²' ) || false !== strpos( $t, 'm2' ) ) {
			$kind = 'area';
		} elseif ( preg_match( '/salon|living|جلوس/u', $t ) ) {
			$kind = 'sofa';
		} elseif ( preg_match( '/chambre|bedroom|غرف نوم|غرفة نوم/u', $t ) ) {
			$kind = 'bed';
		} elseif ( preg_match( '/sdb|salle de bain|bathroom|حمام/u', $t ) ) {
			$kind = 'bath';
		} elseif ( preg_match( '/bureau|office|مكتب/u', $t ) ) {
			$kind = 'desk';
		} elseif ( preg_match( '/suite|جناح|أجنحة/u', $t ) ) {
			$kind = 'suite';
		} elseif ( preg_match( '/rdc|أرضي|الطابق|niveau|level|طوابق/u', $t ) ) {
			$kind = 'floors';
		} elseif ( preg_match( '/ouvert|open space|مفتوحة/u', $t ) ) {
			$kind = 'open';
		}
		$paths = array(
			'area'   => '<path d="M4 9V5h4"/><path d="M16 5h4v4"/><path d="M20 15v4h-4"/><path d="M8 20H4v-4"/><path d="M9 15.5l2.2-2.8 1.6 1.6L16 10.5"/>',
			'bed'    => '<path d="M3 18V9"/><path d="M21 18v-5"/><path d="M3 13h18"/><path d="M3 18h18"/><path d="M7 13V10a2 2 0 0 1 2-2h1"/>',
			'sofa'   => '<path d="M5 11V8a2 2 0 0 1 2-2h10a2 2 0 0 1 2 2v3"/><path d="M3 12h18v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4z"/><path d="M7 18v2"/><path d="M17 18v2"/>',
			'bath'   => '<path d="M5 12h14v2a5 5 0 0 1-5 5H10a5 5 0 0 1-5-5v-2z"/><path d="M7 12V8a2 2 0 0 1 2-2h1"/><path d="M8 6V4"/>',
			'desk'   => '<path d="M4 9h16v7H4z"/><path d="M8 16v3"/><path d="M16 16v3"/><path d="M9 9V6h6v3"/>',
			'suite'  => '<path d="M7 20V6a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v14"/><path d="M7 20h10"/><path d="M14.5 12h.01"/>',
			'floors' => '<path d="M4 20h5v-5h5v-5h6"/>',
			'open'   => '<rect x="3" y="6" width="18" height="12" rx="1.5"/><path d="M3 12h18"/>',
			'mark'   => '<path d="M5 7h9l5 5-5 5H5V7z"/><path d="M9 12h.01"/>',
		);
		if ( 'bed' === $kind ) {
			return '<svg class="pk-card-fact-icon pk-card-fact-icon--bed" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="currentColor" d="M4.2 5.1h6.1c.55 0 1 .45 1 1V11H3.2V6.1c0-.55.45-1 1-1zm9.5 0h6.1c.55 0 1 .45 1 1V11h-8.1V6.1c0-.55.45-1 1-1zM3 12.1h18v2.7H3zm-.8 3.5h19.6v2.3H2.2zM4.3 18.1h2v2.7h-2zm13.4 0h2v2.7h-2z"/></svg>';
		}
		$inner = isset( $paths[ $kind ] ) ? $paths[ $kind ] : $paths['mark'];
		return '<svg class="pk-card-fact-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $inner . '</svg>';
	}
}
