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
 * Phrase de compte deja traduite : 1, 2, ou la forme existante « 3+ ».
 * Au-dela, le nombre exact. Jamais « 6+ ».
 *
 * @param string $raw  Valeur enregistree.
 * @param string $kind bed|salon.
 * @param string $lang fr|en|ar.
 * @return string
 */
if ( ! function_exists( 'pk_card_count_phrase' ) ) {
	function pk_card_count_phrase( $raw, $kind, $lang ) {
		$raw  = trim( (string) $raw );
		$lang = in_array( $lang, array( 'en', 'ar' ), true ) ? $lang : 'fr';
		if ( '' === $raw || '0' === $raw ) {
			return '';
		}
		if ( '3+' === $raw ) {
			$key = ( 'salon' === $kind ) ? '3 salons ou plus' : '3 chambres ou plus';
			return class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $key, $key, 'partikulier' ) : $key;
		}
		if ( ! preg_match( '/^\d+$/', $raw ) ) {
			return '';
		}
		$n = (int) $raw;
		if ( $n < 1 ) {
			return '';
		}
		if ( 1 === $n || 2 === $n ) {
			$key = ( 'salon' === $kind )
				? ( 1 === $n ? '1 salon' : '2 salons' )
				: ( 1 === $n ? '1 chambre' : '2 chambres' );
			return class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $key, $key, 'partikulier' ) : $key;
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
 * Une seule ligne de faits, selon le type.
 * Residentiel : surface, chambres, salons.
 * Terrain : surface et autorisation.
 * Local : surface et niveaux.
 * Les champs vides sont omis.
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
			$unit = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'm²', 'm²', 'partikulier' ) : 'm²';
			$bits[] = trim( ( function_exists( 'pk_format_amount' ) ? pk_format_amount( $surface ) : (string) (int) $surface ) . ' ' . $unit );
		}
		$auth = pk_card_meta_first( $post_id, array( '_pk_autorisation', '_pk_authorization', '_pk_constructibilite' ) );
		$levels = pk_card_levels_phrase( pk_card_meta_first( $post_id, array( '_pk_niveaux', '_pk_levels', '_pk_floors' ) ), $lang );
		$residential = array( 'appartement', 'duplex', 'maison', 'villa', 'riad', 'studio' );
		if ( in_array( $slug, $residential, true ) ) {
			$beds = pk_card_meta_first( $post_id, array( '_pk_bedrooms_label', 'es_property_bedrooms', 'es_bedrooms' ) );
			$salons = pk_card_meta_first( $post_id, array( '_pk_living_rooms', '_pk_living_rooms_label' ) );
			$bed_label = pk_card_count_phrase( $beds, 'bed', $lang );
			$salon_label = pk_card_count_phrase( $salons, 'salon', $lang );
			if ( $bed_label ) {
				$bits[] = $bed_label;
			}
			if ( $salon_label ) {
				$bits[] = $salon_label;
			}
			return implode( ' · ', array_filter( $bits ) );
		}
		if ( 'terrain' === $slug ) {
			if ( $auth ) {
				$bits[] = $auth;
			}
			return implode( ' · ', array_filter( $bits ) );
		}
		$with_levels = array( 'immeuble', 'bureau', 'bureaux', 'hangar', 'local', 'local-commercial', 'commerce' );
		$with_auth = array( 'immeuble', 'terrain' );
		if ( in_array( $slug, $with_levels, true ) && $levels ) {
			$bits[] = $levels;
		}
		if ( in_array( $slug, $with_auth, true ) && $auth ) {
			$bits[] = $auth;
		}
		if ( 'ferme' === $slug ) {
			$exploit = pk_card_meta_first( $post_id, array( '_pk_exploitation' ) );
			if ( $exploit ) {
				$bits[] = $exploit;
			}
		}
		if ( ! in_array( $slug, $residential, true ) && ! in_array( $slug, $with_levels, true ) && ! in_array( $slug, $with_auth, true ) && 'ferme' !== $slug && 'terrain' !== $slug ) {
			if ( $auth ) {
				$bits[] = $auth;
			}
			if ( $levels ) {
				$bits[] = $levels;
			}
		}
		return implode( ' · ', array_filter( $bits ) );
	}
}
