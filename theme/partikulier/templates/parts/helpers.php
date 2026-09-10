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
 * Prix formate + devise, utilise par les cartes ET la fiche.
 *
 * Constate au comparatif : la fiche affichait "850000" brut alors que les
 * cartes affichaient deja "850 000 MAD". Un helper unique evite la divergence.
 */
if ( ! function_exists( 'pk_price_html' ) ) {
	function pk_price_html( $price ) {
		if ( '' === $price || null === $price ) {
			return esc_html__( 'Prix sur demande', 'partikulier' );
		}
		$formatted = is_numeric( $price ) ? number_format_i18n( (float) $price ) : $price;
		$currency  = apply_filters( 'partikulier_currency', 'MAD' );
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
		$slug = sanitize_title( (string) $slug );
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
		$d = isset( $paths[ $slug ] ) ? $paths[ $slug ] : $paths['maison'];
		return '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $d . '</svg>';
	}
}
