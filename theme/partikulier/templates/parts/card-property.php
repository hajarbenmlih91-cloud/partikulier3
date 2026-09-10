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
	$pk_language = pll_current_language( 'slug' );
	$pk_translated_id = $pk_language ? (int) pll_get_post( $property->ID, $pk_language ) : 0;
	if ( $pk_translated_id && $pk_translated_id !== (int) $property->ID ) {
		$pk_translated = get_post( $pk_translated_id );
		if ( $pk_translated instanceof WP_Post && PARTIKULIER_ESTATIK_POST_TYPE === $pk_translated->post_type ) {
			$property = $pk_translated;
		}
	}
}
	$pk_language = function_exists( 'pll_current_language' ) ? sanitize_key( (string) pll_current_language( 'slug' ) ) : 'fr';
	$pk_display_title = class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::title_from_post( $property, $pk_language ) : get_the_title( $property );
	$pk_card_heading_tag = ( is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) || is_tax() ) ? 'h2' : 'h3';
$pk_property_url = get_permalink( $property );
if ( class_exists( 'Partikulier_Listing_URLs' ) ) {
	$pk_property_url = Partikulier_Listing_URLs::filter_link( $pk_property_url, $property );
}

			$price    = get_post_meta( $property->ID, 'es_property_price', true );
		$surface  = get_post_meta( $property->ID, 'es_property_area', true );
		$bedrooms = get_post_meta( $property->ID, '_pk_bedrooms_label', true );
		if ( '' === $bedrooms ) {
			$bedrooms = get_post_meta( $property->ID, 'es_property_bedrooms', true ) ?: get_post_meta( $property->ID, 'es_bedrooms', true );
		}
		$living_rooms = get_post_meta( $property->ID, '_pk_living_rooms_label', true );
		$bathrooms = get_post_meta( $property->ID, '_pk_bathrooms_label', true );
		if ( '' === $bathrooms ) {
			$bathrooms = get_post_meta( $property->ID, 'es_property_bathrooms', true ) ?: get_post_meta( $property->ID, 'es_bathrooms', true );
		}
	$terrace = get_post_meta( $property->ID, '_pk_terrace', true );
	$terrace_surface = get_post_meta( $property->ID, '_pk_terrace_surface', true );
			if ( '' !== $bedrooms ) {
				if ( '0' === (string) $bedrooms ) {
					$bedrooms = __( 'Studio', 'partikulier' );
				} elseif ( '3+' === (string) $bedrooms ) {
					$bedrooms = __( '3 chambres ou plus', 'partikulier' );
				} else {
					$label = ( 1 === (int) $bedrooms ) ? Partikulier_Localization::translate_polylang_string( '1 chambre', '1 chambre', 'partikulier' ) : Partikulier_Localization::translate_polylang_string( '2 chambres', '2 chambres', 'partikulier' );
					$bedrooms = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $label, $label, 'partikulier' ) : esc_html__( $label, 'partikulier' );
				}
			}

			if ( '0' === (string) $living_rooms ) {
				$living_rooms = __( 'Pièce principale', 'partikulier' );
			} elseif ( '3+' === (string) $living_rooms ) {
				$living_rooms = __( '3 salons ou plus', 'partikulier' );
			} elseif ( $living_rooms ) {
				$label = ( 1 === (int) $living_rooms ) ? Partikulier_Localization::translate_polylang_string( '1 salon', '1 salon', 'partikulier' ) : Partikulier_Localization::translate_polylang_string( '2 salons', '2 salons', 'partikulier' );
				$living_rooms = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $label, $label, 'partikulier' ) : esc_html__( $label, 'partikulier' );
			}

			if ( '3+' === (string) $bathrooms ) {
				$bathrooms = __( '3 salles de bains ou plus', 'partikulier' );
			} elseif ( $bathrooms ) {
				$label = ( 1 === (int) $bathrooms ) ? Partikulier_Localization::translate_polylang_string( '1 salle de bains', '1 salle de bains', 'partikulier' ) : Partikulier_Localization::translate_polylang_string( '2 salles de bains', '2 salles de bains', 'partikulier' );
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
	$location = Partikulier_Geo::location_string( $property->ID, $pk_language );

	// Optimisation senior : utiliser get_the_terms() pour beneficier du cache de WP_Query amorce par update_post_term_cache.
	$types   = get_the_terms( $property->ID, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
	$actions = get_the_terms( $property->ID, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
	$type    = ( ! is_wp_error( $types ) && $types ) ? $types[0]->name : __( 'Bien', 'partikulier' );
	$type    = class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::localized_type( $type, $pk_language ) : $type;
	$action  = ( ! is_wp_error( $actions ) && $actions ) ? $actions[0]->name : '';

$thumb_id   = get_post_thumbnail_id( $property );
$gallery    = get_post_meta( $property->ID, 'es_property_gallery', true );
$gallery    = is_array( $gallery ) ? array_slice( array_unique( array_map( 'absint', $gallery ) ), 0, 8 ) : array();
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
	$label       = ( 'a-louer' === $action_slug || 'location' === $action_slug || 'rent' === $action_slug ) ? sprintf( ' <span class="pk-card-price-note">%s</span>', esc_html( Partikulier_Localization::translate_polylang_string( '/ mois', '/ mois', 'partikulier' ) ) ) : '';
		$formatted  = is_numeric( $price ) ? number_format_i18n( (float) $price ) : $price;
		/** Devise affichee ; filtrable pour les sites hors Maroc. */
		$currency    = apply_filters( 'partikulier_currency', 'MAD' );
		$price_html  = esc_html( trim( $formatted . ' ' . $currency ) ) . $label;
} else {
	$price_html = esc_html( Partikulier_Localization::translate_polylang_string( 'Prix sur demande', 'Prix sur demande', 'partikulier' ) );
}
?>
<article class="pk-card pk-card-property" itemscope itemtype="https://schema.org/RealEstateListing">
	<a class="pk-card-media" href="<?php echo esc_url( $pk_property_url ); ?>" tabindex="-1" aria-hidden="true">
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
						<?php $pk_is_first_archive_card = isset( $pk_card_index ) && 1 === (int) $pk_card_index; ?>
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
			<?php echo $price_html; ?>
		</span>
		<?php if ( $action ) : ?>
			<span class="pk-card-badge pk-badge-<?php echo esc_attr( sanitize_title( $action ) ); ?>"><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $action ) : $action ); ?></span>
		<?php endif; ?>
	</a>

	<div class="pk-card-actions">
		<a class="pk-card-action pk-card-peek" href="<?php echo esc_url( $pk_property_url ); ?>" aria-label="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Aperçu rapide', 'Aperçu rapide', 'partikulier' ) ); ?>">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
		</a>
		<button type="button" class="pk-card-action pk-card-wishlist" data-post-id="<?php echo esc_attr( $property->ID ); ?>" aria-label="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Ajouter aux favoris', 'Ajouter aux favoris', 'partikulier' ) ); ?>">
			<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
		</button>
	</div>

	<div class="pk-card-body">
			<<?php echo esc_html( $pk_card_heading_tag ); ?> class="pk-card-title">
			<a href="<?php echo esc_url( $pk_property_url ); ?>" itemprop="url">
				<span itemprop="name"><?php echo esc_html( $pk_display_title ); ?></span>
			</a>
			</<?php echo esc_html( $pk_card_heading_tag ); ?>>
		<?php
		$pk_role       = get_post_meta( $property->ID, '_pk_owner_role', true );
		$pk_role_label = Partikulier_Localization::translate_polylang_string( 'Propriétaire', 'Propriétaire', 'partikulier' );
		?>
		<p class="pk-card-topline">
			<?php if ( $location ) : ?>
				<span class="pk-card-location" itemprop="address" itemscope itemtype="https://schema.org/PostalAddress">
					<svg viewBox="0 0 24 24" width="12" height="12" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>
					<span itemprop="addressLocality"><?php echo esc_html( $location ); ?></span>
				</span>
			<?php endif; ?>
			<span class="pk-card-role"><?php echo esc_html( $pk_role_label ); ?></span>
		</p>
		<dl class="pk-card-meta">
			<?php if ( $surface ) : ?>
				<div class="pk-card-meta-item">
<dt class="screen-reader-text"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Surface', 'Surface', 'partikulier' ) ); ?></dt>
						<dd><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 20 20 4"/><path d="M15 4h5v5"/><path d="M9 20H4v-5"/></svg><span><?php echo esc_html( number_format_i18n( (int) $surface ) ) . ' ' . Partikulier_Localization::translate_polylang_string( 'm²', 'm²', 'partikulier' ); ?></span></dd>
				</div>
			<?php endif; ?>
			<?php if ( $composition ) : ?>
					<div class="pk-card-meta-item pk-card-meta-item--rooms">
						<dt class="screen-reader-text"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Composition', 'Composition', 'partikulier' ) ); ?></dt>
					<dd><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 18v-6a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v6"/><path d="M3 18h18"/><path d="M7 10V7a1 1 0 0 1 1-1h8a1 1 0 0 1 1 1v3"/></svg><span><?php echo esc_html( $composition ); ?></span></dd>
				</div>
			<?php endif; ?>
			<?php if ( $bathrooms ) : ?>
				<div class="pk-card-meta-item">
					<dt class="screen-reader-text"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Salles de bains', 'Salles de bains', 'partikulier' ) ); ?></dt>
					<dd><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z"/><path d="M7 12V6a2 2 0 0 1 4 0"/></svg><span><?php echo esc_html( $bathrooms ); ?></span></dd>
				</div>
			<?php endif; ?>
			<?php if ( $terrace_label ) : ?>
				<div class="pk-card-meta-item">
					<dt class="screen-reader-text"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Terrasse', 'Terrasse', 'partikulier' ) ); ?></dt>
					<dd><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 20h18"/><path d="M5 20V9l7-5 7 5v11"/></svg><span><?php echo esc_html( $terrace_label ); ?></span></dd>
				</div>
			<?php endif; ?>
			<div class="pk-card-meta-item">
				<dt class="screen-reader-text"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Type', 'Type', 'partikulier' ) ); ?></dt>
				<dd><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"/><path d="M4 10h16"/></svg><span><?php echo esc_html( $type ); ?></span></dd>
			</div>
		</dl>
		<div class="pk-card-foot"><span class="pk-card-direct"><svg viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Contact direct', 'Contact direct', 'partikulier' ) ); ?></span><a class="pk-card-cta" href="<?php echo esc_url( $pk_property_url ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Voir l\'annonce', 'Voir l\'annonce', 'partikulier' ) ); ?><span aria-hidden="true"> ↗</span></a></div>
	</div>
</article>