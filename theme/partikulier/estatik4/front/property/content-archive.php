<?php
/**
 * Override ESTATIK : carte d'annonce dans les archives/listes Estatik.
 *
 * Les listings Estatik (shortcodes, recherche ajax) utilisent ce fichier.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post      = isset( $property ) ? $property : get_post();
if ( ! $post instanceof WP_Post ) {
	return;
}
$pk_language = function_exists( 'pll_current_language' ) ? sanitize_key( (string) pll_current_language( 'slug' ) ) : 'fr';
if ( function_exists( 'pll_get_post' ) ) {
	$pk_translated_id = $pk_language ? (int) pll_get_post( $post->ID, $pk_language ) : 0;
	if ( $pk_translated_id ) {
		$translated_post = get_post( $pk_translated_id );
		if ( $translated_post instanceof WP_Post ) {
			$post = $translated_post;
		}
	}
}
$pk_property_url = get_permalink( $post );
$pk_display_title = class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::title_from_post( $post, $pk_language ) : get_the_title( $post );
		$price     = get_post_meta( $post->ID, 'es_property_price', true ) ?: get_post_meta( $post->ID, 'es_price', true );
	$surface   = get_post_meta( $post->ID, 'es_property_area', true ) ?: get_post_meta( $post->ID, 'es_size', true );
		$bedrooms  = get_post_meta( $post->ID, '_pk_bedrooms_label', true );
		if ( '' === $bedrooms ) {
			$bedrooms = get_post_meta( $post->ID, 'es_property_bedrooms', true ) ?: get_post_meta( $post->ID, 'es_bedrooms', true );
		}
		$living_rooms = get_post_meta( $post->ID, '_pk_living_rooms_label', true );
		$bathrooms = get_post_meta( $post->ID, '_pk_bathrooms_label', true );
		if ( '' === $bathrooms ) {
			$bathrooms = get_post_meta( $post->ID, 'es_property_bathrooms', true ) ?: get_post_meta( $post->ID, 'es_bathrooms', true );
		}
	$terrace   = get_post_meta( $post->ID, '_pk_terrace', true );
	$terrace_surface = get_post_meta( $post->ID, '_pk_terrace_surface', true );
					if ( '' !== $bedrooms ) {
				if ( '0' === (string) $bedrooms ) {
					$bedrooms = __( 'Studio', 'partikulier' );
				} elseif ( '3+' === (string) $bedrooms ) {
					$bedrooms = __( '3 chambres ou plus', 'partikulier' );
				} else {
					$label = ( 1 === (int) $bedrooms ) ? '1 chambre' : '2 chambres';
					$bedrooms = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $label, $label, 'partikulier' ) : esc_html__( $label, 'partikulier' );
				}
			}

			if ( '0' === (string) $living_rooms ) {
				$living_rooms = __( 'Pièce principale', 'partikulier' );
			} elseif ( '3+' === (string) $living_rooms ) {
				$living_rooms = __( '3 salons ou plus', 'partikulier' );
			} elseif ( $living_rooms ) {
				$label = ( 1 === (int) $living_rooms ) ? '1 salon' : '2 salons';
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
			$pk_rooms_label = Partikulier_Listing_I18n::rooms_label_from_post( $post, $pk_language );
			if ( $pk_rooms_label ) {
				$composition = $pk_rooms_label;
			}
		}
		$terrace_label = 'Oui' === $terrace ? Partikulier_Localization::translate_polylang_string( 'Terrasse', 'Terrasse', 'partikulier' ) . ( $terrace_surface ? ' · ' . $terrace_surface . ' ' . Partikulier_Localization::translate_polylang_string( 'm²', 'm²', 'partikulier' ) : '' ) : '';
		$location  = Partikulier_Geo::location_string( $post->ID, $pk_language );
	// Optimisation senior : utiliser get_the_terms() pour beneficier du cache de WP_Query.
	$actions   = get_the_terms( $post->ID, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
	$action    = ( ! is_wp_error( $actions ) && $actions ) ? $actions[0]->name : '';

// Image : galerie Estatik en premier, puis featured.
$img_id = 0;
if ( function_exists( 'es_get_property_gallery' ) ) {
	$g = es_get_property_gallery( $post->ID );
	if ( is_array( $g ) ) {
		foreach ( $g as $entry ) {
			if ( is_array( $entry ) && ! empty( $entry['id'] ) ) {
				$img_id = (int) $entry['id'];
				break;
			}
			if ( is_numeric( $entry ) ) {
				$img_id = (int) $entry;
				break;
			}
		}
	}
}
if ( ! $img_id ) {
	$img_id = get_post_thumbnail_id( $post );
}

	$jpg  = $img_id ? Partikulier_AVIF::valid_image_url( $img_id, 'pk-card' ) : false;
	$avif = $jpg ? Partikulier_AVIF::avif_path_for_url( $jpg ) : false;
?>

<article class="pk-card pk-card-property pk-card-estatik">
	<a class="pk-card-media" href="<?php echo esc_url( $pk_property_url ); ?>" tabindex="-1" aria-hidden="true">
		<?php if ( $jpg ) : ?>
			<picture>
				<?php if ( $avif ) : ?>
					<source type="image/avif" srcset="<?php echo esc_attr( $avif ); ?>">
				<?php endif; ?>
						<img src="<?php echo esc_url( $jpg ); ?>" width="640" height="480" alt="<?php echo esc_attr( $pk_display_title ); ?>" loading="eager" decoding="async">
			</picture>
		<?php else : ?>
			<div class="pk-card-placeholder" aria-hidden="true"><span><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.6V21h14V9.6"/><path d="M9.5 21v-6h5v6"/></svg></span></div>
		<?php endif; ?>
			<span class="pk-card-price" itemprop="price"><?php echo $price ? esc_html( is_numeric( $price ) ? number_format_i18n( (float) $price ) . ' MAD' : $price ) : esc_html__( 'Prix sur demande', 'partikulier' ); ?></span>
			<?php if ( $action ) : ?>
				<span class="pk-card-badge pk-badge-<?php echo esc_attr( sanitize_title( $action ) ); ?>"><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $action ) : $action ); ?></span>
			<?php endif; ?>
	</a>

		<div class="pk-card-body">
			<h3 class="pk-card-title">
			<a href="<?php echo esc_url( $pk_property_url ); ?>">
					<?php echo esc_html( $pk_display_title ); ?>
			</a>
		</h3>
		<?php if ( $location ) : ?>
			<p class="pk-card-location"><?php echo esc_html( $location ); ?></p>
		<?php endif; ?>
		<dl class="pk-card-meta">
			<?php if ( $surface ) : ?>
				<div class="pk-card-meta-item"><dd><?php echo esc_html( number_format_i18n( (int) $surface ) ) . ' ' . esc_html( Partikulier_Localization::translate_polylang_string( 'm²', 'm²', 'partikulier' ) ); ?></dd></div>
			<?php endif; ?>
				<?php if ( $composition ) : ?>
					<div class="pk-card-meta-item"><dd><?php echo esc_html( $composition ); ?></dd></div>
				<?php endif; ?>
				<?php if ( $bathrooms ) : ?>
					<div class="pk-card-meta-item"><dd><?php echo esc_html( $bathrooms ); ?></dd></div>
				<?php endif; ?>
				<?php if ( $terrace_label ) : ?>
					<div class="pk-card-meta-item"><dd><?php echo esc_html( $terrace_label ); ?></dd></div>
				<?php endif; ?>
		</dl>
		<div class="pk-card-foot"><span class="pk-card-direct"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Contact direct', 'Contact direct', 'partikulier' ) ); ?></span><a class="pk-card-cta" href="<?php echo esc_url( $pk_property_url ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Voir l\'annonce', 'Voir l\'annonce', 'partikulier' ) ); ?> <span aria-hidden="true">↗</span></a></div>
	</div>
</article>