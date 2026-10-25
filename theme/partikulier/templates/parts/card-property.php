<?php
/**
 * Carte d'annonce immobiliere.
 *
 * Calquee sur la carte produit du kit "Woo Shop" : badge orange en haut
 * a droite, actions favoris/comparer a droite de l'image, prix au-dessus
 * du titre, bouton "Voir l'annonce" au survol.
 *
 * @package Partikulier
 * @var WP_Post $property Annonce.
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

$property = isset( $property ) ? $property : get_post();
if ( ! $property ) {
		return;
}

// Les cartes liées peuvent être construites hors de la requête de langue active.
// Revenir à la traduction Polylang évite les liens génériques /annonces/.
if ( function_exists( 'pll_current_language' ) && function_exists( 'pll_get_post' ) ) {
		$pk_language      = pll_current_language( 'slug' );
		$pk_translated_id = $pk_language ? (int) pll_get_post( $property->ID, $pk_language ) : 0;
	if ( $pk_translated_id && $pk_translated_id !== (int) $property->ID ) {
			$pk_translated = get_post( $pk_translated_id );
		if ( $pk_translated instanceof WP_Post && PARTIKULIER_ESTATIK_POST_TYPE === $pk_translated->post_type ) {
				$property = $pk_translated;
		}
	}
}
		$pk_language         = function_exists( 'pll_current_language' ) ? sanitize_key( (string) pll_current_language( 'slug' ) ) : 'fr';
		$pk_display_title    = class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::title_from_post( $property, $pk_language ) : get_the_title( $property );
		$pk_card_heading_tag = ( is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) || is_tax() ) ? 'h2' : 'h3';
$pk_property_url             = get_permalink( $property );
if ( class_exists( 'Partikulier_Listing_URLs' ) ) {
		$pk_property_url = Partikulier_Listing_URLs::filter_link( $pk_property_url, $property );
}

						$price = get_post_meta( $property->ID, 'es_property_price', true );
				$surface       = get_post_meta( $property->ID, 'es_property_area', true );
				$bedrooms      = get_post_meta( $property->ID, '_pk_bedrooms_label', true );
if ( '' === $bedrooms ) {
		$bedrooms = get_post_meta( $property->ID, 'es_property_bedrooms', true ) ?: get_post_meta( $property->ID, 'es_bedrooms', true );
}
				$living_rooms = get_post_meta( $property->ID, '_pk_living_rooms_label', true );
				$bathrooms    = get_post_meta( $property->ID, '_pk_bathrooms_label', true );
if ( '' === $bathrooms ) {
		$bathrooms = get_post_meta( $property->ID, 'es_property_bathrooms', true ) ?: get_post_meta( $property->ID, 'es_bathrooms', true );
}
		$terrace         = get_post_meta( $property->ID, '_pk_terrace', true );
		$terrace_surface = get_post_meta( $property->ID, '_pk_terrace_surface', true );
if ( '' !== $bedrooms ) {
	if ( '0' === (string) $bedrooms ) {
								$bedrooms = __( 'Studio', 'partikulier' );
	} elseif ( '3+' === (string) $bedrooms ) {
									$bedrooms = __( '3 chambres ou plus', 'partikulier' );
	} else {
			$label    = ( 1 === (int) $bedrooms ) ? Partikulier_Localization::translate_polylang_string( '1 chambre', '1 chambre', 'partikulier' ) : Partikulier_Localization::translate_polylang_string( '2 chambres', '2 chambres', 'partikulier' );
			$bedrooms = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $label, $label, 'partikulier' ) : esc_html__( $label, 'partikulier' );
	}
}

if ( '0' === (string) $living_rooms ) {
		$living_rooms = __( 'Pièce principale', 'partikulier' );
} elseif ( '3+' === (string) $living_rooms ) {
		$living_rooms = __( '3 salons ou plus', 'partikulier' );
} elseif ( $living_rooms ) {
		$label        = ( 1 === (int) $living_rooms ) ? Partikulier_Localization::translate_polylang_string( '1 salon', '1 salon', 'partikulier' ) : Partikulier_Localization::translate_polylang_string( '2 salons', '2 salons', 'partikulier' );
		$living_rooms = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $label, $label, 'partikulier' ) : esc_html__( $label, 'partikulier' );
}

if ( '3+' === (string) $bathrooms ) {
		$bathrooms = __( '3 salles de bains ou plus', 'partikulier' );
} elseif ( $bathrooms ) {
		$label     = ( 1 === (int) $bathrooms ) ? Partikulier_Localization::translate_polylang_string( '1 salle de bains', '1 salle de bains', 'partikulier' ) : Partikulier_Localization::translate_polylang_string( '2 salles de bains', '2 salles de bains', 'partikulier' );
		$bathrooms = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $label, $label, 'partikulier' ) : esc_html__( $label, 'partikulier' );
}
										$composition = implode( ' · ', array_filter( array( $bedrooms, $living_rooms ) ) );
if ( class_exists( 'Partikulier_Listing_I18n' ) ) {
		$pk_rooms_label = Partikulier_Listing_I18n::rooms_label_from_post( $property, $pk_language );
	if ( $pk_rooms_label ) {
								$composition = $pk_rooms_label;
	}
}
						$terrace_label = 'Oui' === $terrace ? Partikulier_Localization::translate_polylang_string( 'Terrasse', 'Terrasse', 'partikulier' ) . ( $terrace_surface ? ' · ' . $terrace_surface . ' ' . Partikulier_Localization::translate_polylang_string( 'm²', 'm²', 'partikulier' ) : '' ) : '';
		$location                      = Partikulier_Geo::location_string( $property->ID, $pk_language );

		// Optimisation senior : utiliser get_the_terms() pour beneficier du cache de WP_Query amorce par update_post_term_cache.
		$types   = get_the_terms( $property->ID, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
		$actions = get_the_terms( $property->ID, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
		$type    = ( ! is_wp_error( $types ) && $types ) ? $types[0]->name : __( 'Bien', 'partikulier' );
		$type    = class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::localized_type( $type, $pk_language ) : $type;
		$action  = ( ! is_wp_error( $actions ) && $actions ) ? $actions[0]->name : '';

$thumb_id = get_post_thumbnail_id( $property );
$gallery  = get_post_meta( $property->ID, 'es_property_gallery', true );
$gallery  = is_array( $gallery ) ? array_slice( array_unique( array_map( 'absint', $gallery ) ), 0, 8 ) : array();
if ( $thumb_id && ! in_array( (int) $thumb_id, $gallery, true ) ) {
		array_unshift( $gallery, (int) $thumb_id );
}
$gallery = array_values( array_filter( $gallery, static function ( $attachment_id ) {
		return (bool) Partikulier_AVIF::valid_image_url( $attachment_id, 'pk-card' );
} ) );

/** Texte de prix avec unite mensuelle pour la location. */
$price_html = '';
if ( $price ) {
		$action_slug = ( $actions && ! is_wp_error( $actions ) ) ? sanitize_title( $actions[0]->name ) : '';
	$label           = ( 'a-louer' === $action_slug || 'location' === $action_slug || 'rent' === $action_slug ) ? sprintf( ' <span class="pk-card-price-note">%s</span>', esc_html( function_exists( 'pk_rent_suffix' ) ? pk_rent_suffix() : '/ mois' ) ) : '';
		$formatted   = function_exists( 'pk_format_amount' ) ? pk_format_amount( $price ) : $price;
		$currency   = apply_filters( 'partikulier_currency', 'DH' );
		$price_html = esc_html( trim( $formatted . ' ' . $currency ) ) . $label;
} else {
		$price_html = esc_html( Partikulier_Localization::translate_polylang_string( 'Prix sur demande', 'Prix sur demande', 'partikulier' ) );
}
?>
<article class="pk-card pk-card-property" itemscope itemtype="https://schema.org/RealEstateListing">
		<div class="pk-card-media">
		<a class="pk-card-media-link" href="<?php echo esc_url( $pk_property_url ); ?>" tabindex="-1" aria-hidden="true">
				<?php
				if ( $gallery ) {
								$jpg       = Partikulier_AVIF::valid_image_url( (int) $gallery[0], 'pk-card' );
								$avif      = $jpg ? Partikulier_AVIF::avif_path_for_url( $jpg ) : false;
								$pk_srcset = function_exists( 'wp_get_attachment_image_srcset' ) ? wp_get_attachment_image_srcset( (int) $gallery[0], 'pk-card' ) : false;
								$pk_sizes  = '(max-width: 767px) 300px, (max-width: 1199px) 50vw, 640px';
					if ( $jpg ) {
						?>
								<picture>
									<?php if ( $avif ) : ?>
												<source type="image/avif" srcset="<?php echo esc_attr( $avif ); ?>">
										<?php endif; ?>
											<?php $pk_is_first_archive_card = isset( $pk_card_index ) && (int) $pk_card_index <= 2; // LCP : 2 premières cartes en eager (archive =1, home =2 visibles au-dessus du pli) ?>
												<img src="<?php echo esc_url( $jpg ); ?>"<?php echo $pk_srcset ? ' srcset="' . esc_attr( $pk_srcset ) . '" sizes="' . esc_attr( $pk_sizes ) . '"' : ''; ?> width="640" height="480" alt="<?php echo esc_attr( $pk_display_title ); ?>" loading="<?php echo $pk_is_first_archive_card ? 'eager' : 'lazy'; ?>" decoding="async" fetchpriority="<?php echo $pk_is_first_archive_card ? 'high' : 'low'; ?>">
								</picture>
								<?php
					}
				} else {
					?>
						<div class="pk-card-placeholder" aria-hidden="true">
								<span><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.6V21h14V9.6"/><path d="M9.5 21v-6h5v6"/></svg></span>
						</div>
						<?php
				}
				?>
				<span class="pk-card-price" itemprop="price">
						<?php echo $price_html; // phpcs:ignore WordPress.Security.EscapeOutput -- construit exclusivement de morceaux esc_html() (l.116-118) (SE-020) ?>
				</span>
				<?php if ( $action ) : ?>
						<span class="pk-card-badge pk-badge-<?php echo esc_attr( sanitize_title( $action ) ); ?>"><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $action ) : $action ); ?></span>
				<?php endif; ?>
				<?php
				// SE-048-R (E-4804, 9e fonction) : badge premium des cartes. Un seul prédicat
				// décide (drapeau ∧ droit actif ∧ annonce disponible) ; drapeau éteint =
				// AUCUNE balise, pas même un conteneur vide.
				$pk_premium_badge = ( class_exists( 'Partikulier_Premium' ) && Partikulier_Premium::is_publicly_visible( (int) $property->ID ) )
					? Partikulier_Premium::badge_label()
					: '';
				if ( '' !== $pk_premium_badge ) :
					?>
					<span class="pk-card-badge pk-card-badge-premium"><?php echo esc_html( $pk_premium_badge ); ?></span>
					<?php
				endif;
				?>
		</a>
		<div class="pk-card-actions">
				<a class="pk-card-action pk-card-peek" href="<?php echo esc_url( $pk_property_url ); ?>" aria-label="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Aperçu rapide', 'Aperçu rapide', 'partikulier' ) ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
				</a>
				<button type="button" class="pk-card-action pk-card-wishlist" data-post-id="<?php echo esc_attr( $property->ID ); ?>" aria-label="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Ajouter aux favoris', 'Ajouter aux favoris', 'partikulier' ) ); ?>">
						<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
				</button>
		</div>
		</div>

		<div class="pk-card-body">
				<?php
				$pk_type_slug = ( ! is_wp_error( $types ) && $types ) ? $types[0]->slug : '';
				$pk_type_term = ( ! is_wp_error( $types ) && $types ) ? $types[0] : null;
				if ( $pk_type_term && $pk_language && function_exists( 'pll_get_term' ) ) {
					$pk_type_translated_id = (int) pll_get_term( $pk_type_term->term_id, $pk_language );
					if ( $pk_type_translated_id ) {
						$pk_type_translated = get_term( $pk_type_translated_id, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
						if ( $pk_type_translated && ! is_wp_error( $pk_type_translated ) ) {
							$pk_type_term = $pk_type_translated;
							$pk_type_slug = $pk_type_translated->slug;
						}
					}
				}
				$pk_type_url = '';
				if ( $pk_type_term && isset( $pk_type_term->slug ) ) {
					$pk_type_archive = function_exists( 'pk_properties_archive_url' ) ? pk_properties_archive_url() : home_url( '/' );
					if ( is_wp_error( $pk_type_archive ) || ! is_string( $pk_type_archive ) ) {
						$pk_type_archive = home_url( '/' );
					}
					$pk_type_url = add_query_arg( 'es_type', $pk_type_term->slug, $pk_type_archive );
				}
				$pk_type_text = function_exists( 'pk_card_type_link_label' ) ? pk_card_type_link_label( $type, $pk_type_slug, $property->ID, $pk_language ) : $type;
				$pk_fact_line = function_exists( 'pk_card_fact_line' ) ? pk_card_fact_line( $property->ID, $pk_type_slug, $pk_language ) : '';
				$pk_city_label = $location;
				$pk_city_url   = '';
				$pk_city_terms = get_the_terms( $property->ID, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
				if ( $pk_city_terms && ! is_wp_error( $pk_city_terms ) ) {
					$pk_city_term = $pk_city_terms[0];
					if ( $pk_language && function_exists( 'pll_get_term' ) ) {
						$pk_city_translated_id = (int) pll_get_term( $pk_city_term->term_id, $pk_language );
						if ( $pk_city_translated_id ) {
							$pk_city_translated = get_term( $pk_city_translated_id, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
							if ( $pk_city_translated && ! is_wp_error( $pk_city_translated ) ) {
								$pk_city_term = $pk_city_translated;
							}
						}
					}
					$pk_city_url = function_exists( 'pk_term_url' ) ? pk_term_url( $pk_city_term ) : get_term_link( $pk_city_term );
					if ( is_wp_error( $pk_city_url ) ) {
						$pk_city_url = '';
					}
					if ( ! $pk_city_label && isset( $pk_city_term->name ) ) {
						$pk_city_label = class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::localized_place( $pk_city_term->name, $pk_language ) : $pk_city_term->name;
					}
				}
				?>
				<<?php echo esc_html( $pk_card_heading_tag ); ?> class="pk-card-title" itemprop="name">
					<?php echo esc_html( $pk_display_title ); ?>
				</<?php echo esc_html( $pk_card_heading_tag ); ?>>
				<p class="pk-card-facts">
					<?php if ( $pk_fact_line ) : ?>
						<span class="pk-card-fact-rest"><?php echo esc_html( $pk_fact_line ); ?></span>
					<?php endif; ?>
				</p>
				<div class="pk-card-foot">
					<?php if ( $pk_city_label && $pk_city_url ) : ?>
						<a class="pk-card-city" href="<?php echo esc_url( $pk_city_url ); ?>">
							<svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>
							<span itemprop="address" itemscope itemtype="https://schema.org/PostalAddress"><span itemprop="addressLocality"><?php echo esc_html( $pk_city_label ); ?></span></span>
						</a>
					<?php endif; ?>
					<a class="pk-card-cta" href="<?php echo esc_url( $pk_property_url ); ?>" itemprop="url"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( "Voir l'annonce", "Voir l'annonce", 'partikulier' ) ); ?><span aria-hidden="true"> ↗</span></a>
				</div>
		</div>
</article>