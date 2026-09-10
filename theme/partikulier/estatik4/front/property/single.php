<?php
/**
 * Override ESTATIK : fiche annonce unique (design Partikulier).
 *
 * Reprend les donnees Estatik (sections, galerie, metas) mais avec notre markup
 * SEO-first. Ce fichier est charge par Estatik via le filtre es_template_path.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$post = get_queried_object();

// Le routeur du thème n’appelle ce template que pour le CPT Estatik actif.
// Estatik 4.3.4 ne conserve pas les anciennes fonctions de template, mais les
// données WordPress (post, taxonomies et métadonnées) restent disponibles.
if ( ! $post || empty( $post->ID ) ) {
	return;
}

$pk_property_url = get_permalink( $post );
if ( class_exists( 'Partikulier_Listing_URLs' ) ) {
	$pk_property_url = Partikulier_Listing_URLs::filter_link( $pk_property_url, $post );
}
if ( function_exists( 'pll_current_language' ) && function_exists( 'pll_get_post' ) ) {
	$pk_language = pll_current_language( 'slug' );
	$pk_translated_id = $pk_language ? (int) pll_get_post( $post->ID, $pk_language ) : 0;
	if ( $pk_translated_id ) {
		$pk_property_url = get_permalink( $pk_translated_id );
	}
}

$price    = get_post_meta( $post->ID, 'es_property_price', true ) ?: get_post_meta( $post->ID, 'es_price', true );
$surface  = get_post_meta( $post->ID, 'es_property_area', true ) ?: get_post_meta( $post->ID, 'es_size', true );
	$rooms    = get_post_meta( $post->ID, 'es_property_total_rooms', true ) ?: get_post_meta( $post->ID, 'es_rooms', true );
	$bedrooms = get_post_meta( $post->ID, 'es_property_bedrooms', true ) ?: get_post_meta( $post->ID, 'es_bedrooms', true );
	$bedrooms_label = get_post_meta( $post->ID, '_pk_bedrooms_label', true ) ?: $bedrooms;
	$living_rooms = get_post_meta( $post->ID, '_pk_living_rooms_label', true ) ?: get_post_meta( $post->ID, '_pk_living_rooms', true );
	$bathrooms = get_post_meta( $post->ID, 'es_property_bathrooms', true ) ?: get_post_meta( $post->ID, 'es_bathrooms', true );
		$bathrooms_label = get_post_meta( $post->ID, '_pk_bathrooms_label', true ) ?: $bathrooms;
		$terrace = get_post_meta( $post->ID, '_pk_terrace', true );
		$terrace_surface = get_post_meta( $post->ID, '_pk_terrace_surface', true );
		$vis_a_vis = get_post_meta( $post->ID, '_pk_vis_a_vis', true );
		$sunshine = get_post_meta( $post->ID, '_pk_sunshine', true );
			$terrace_label = 'Oui' === $terrace ? Partikulier_Localization::translate_polylang_string( 'Oui', 'Oui', 'partikulier' ) . ( $terrace_surface ? ' · ' . $terrace_surface . ' ' . Partikulier_Localization::translate_polylang_string( 'm²', 'm²', 'partikulier' ) : '' ) : Partikulier_Localization::translate_polylang_string( 'Non', 'Non', 'partikulier' );
			if ( '0' === (string) $bedrooms_label ) {
				$bedrooms_display = __( 'Studio', 'partikulier' );
			} elseif ( '3+' === (string) $bedrooms_label ) {
				$bedrooms_display = __( '3 chambres ou plus', 'partikulier' );
			} elseif ( $bedrooms_label ) {
				$label = ( 1 === (int) $bedrooms_label ) ? '1 chambre' : '2 chambres';
				$bedrooms_display = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $label, $label, 'partikulier' ) : esc_html__( $label, 'partikulier' );
			} else {
				$bedrooms_display = '';
			}

			if ( '0' === (string) $living_rooms ) {
				$living_display = __( 'Pièce principale', 'partikulier' );
			} elseif ( '3+' === (string) $living_rooms ) {
				$living_display = __( '3 salons ou plus', 'partikulier' );
			} elseif ( $living_rooms ) {
				$label = ( 1 === (int) $living_rooms ) ? '1 salon' : '2 salons';
				$living_display = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $label, $label, 'partikulier' ) : esc_html__( $label, 'partikulier' );
			} else {
				$living_display = '';
			}

			if ( '3+' === (string) $bathrooms_label ) {
				$bathrooms_display = __( '3 salles de bains ou plus', 'partikulier' );
			} elseif ( $bathrooms_label ) {
				$label = ( 1 === (int) $bathrooms_label ) ? '1 salle de bains' : '2 salles de bains';
				$bathrooms_display = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $label, $label, 'partikulier' ) : esc_html__( $label, 'partikulier' );
			} else {
				$bathrooms_display = '';
			}
$location = Partikulier_Geo::location_string( $post->ID );

	// Optimisation senior : utiliser get_the_terms() pour beneficier du cache de WP_Query.
	$types   = get_the_terms( $post->ID, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
	$actions = get_the_terms( $post->ID, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
	$type    = ( ! is_wp_error( $types ) && $types ) ? $types[0]->name : __( 'Bien', 'partikulier' );
	$action  = ( ! is_wp_error( $actions ) && $actions ) ? $actions[0]->name : '';

// Statut proprietaire : vendu / loue / archive / actif.
	$pk_status  = get_post_meta( $post->ID, '_pk_status', true );
	$pk_status  = ( '' === $pk_status || 'actif' === $pk_status ) ? '' : $pk_status;
	$closed_statuses = array( 'vendu', 'loue', 'archive' );
	$is_closed = in_array( $pk_status, $closed_statuses, true );
	$closed_label = 'vendu' === $pk_status ? __( 'Vendu', 'partikulier' ) : ( 'loue' === $pk_status ? __( 'Loué', 'partikulier' ) : __( 'Annonce archivée', 'partikulier' ) );
$pk_views   = (int) get_post_meta( $post->ID, '_pk_views', true );
	$owner_name = get_post_meta( $post->ID, '_pk_owner_name', true );
	$owner_phone = get_post_meta( $post->ID, '_pk_owner_phone', true );
	$owner_role  = get_post_meta( $post->ID, '_pk_owner_role', true );
	$buyer_contact_url = ! $is_closed && class_exists( 'Partikulier_Buyer_Qualification' ) ? Partikulier_Buyer_Qualification::contact_url( $post->ID ) : '';
	$buyer_reference = class_exists( 'Partikulier_Buyer_Qualification' ) ? Partikulier_Buyer_Qualification::reference_for( $post->ID ) : '';

// Galerie : images Estatik + fallback featured.
$gallery_ids = array();
	if ( function_exists( 'es_get_property_gallery' ) ) {
		$gallery_data = es_get_property_gallery( $post->ID );
	if ( is_array( $gallery_data ) ) {
		foreach ( $gallery_data as $img ) {
			if ( is_array( $img ) && ! empty( $img['id'] ) ) {
				$gallery_ids[] = (int) $img['id'];
			} elseif ( is_numeric( $img ) ) {
				$gallery_ids[] = (int) $img;
			}
		}
		}
	}
	// Le formulaire Partikulier enregistre les images dans cette métadonnée, y compris
	// lorsque la version d’Estatik ne fournit pas de fonction de galerie publique.
	if ( ! $gallery_ids ) {
		$stored_gallery = get_post_meta( $post->ID, 'es_property_gallery', true );
		if ( is_string( $stored_gallery ) ) {
			$decoded_gallery = json_decode( $stored_gallery, true );
			$stored_gallery  = is_array( $decoded_gallery ) ? $decoded_gallery : array();
		}
		if ( is_array( $stored_gallery ) ) {
			$gallery_ids = array_map( 'absint', $stored_gallery );
		}
	}
	$gallery_ids = array_slice( array_unique( array_map( 'absint', $gallery_ids ) ), 0, 10 );
	$thumb       = get_post_thumbnail_id( $post );
	if ( $thumb && ! in_array( (int) $thumb, $gallery_ids, true ) ) {
		array_unshift( $gallery_ids, (int) $thumb );
	}
	$gallery_ids = array_values( array_filter( $gallery_ids, static function ( $attachment_id ) {
		return (bool) Partikulier_AVIF::valid_image_url( $attachment_id, 'pk-hero' );
	} ) );
?>

<article class="pk-single pk-single-estatik<?php echo $is_closed ? ' pk-single-closed' : ''; ?>" itemscope itemtype="https://schema.org/RealEstateListing">
	<div class="pk-container">
		<?php echo Partikulier_Geo::breadcrumbs_html(); // phpcs:ignore ?>

		<header class="pk-single-head">
			<div class="pk-single-title-block">
				<p class="pk-single-eyebrow">
					<?php if ( $type ) : ?>
						<span class="pk-single-cat"><?php echo esc_html( $type ); ?></span>
					<?php endif; ?>
					<?php
					$pk_role       = get_post_meta( $post->ID, '_pk_owner_role', true );
					$pk_role_label = __( 'Propriétaire', 'partikulier' );
					?>
					<span class="pk-single-role"><?php echo esc_html( $pk_role_label ); ?></span>
				</p>
				<h1 class="pk-single-title" itemprop="name"><?php echo esc_html( get_the_title( $post ) ); ?></h1>
				<p class="pk-single-location" itemprop="address" itemscope itemtype="https://schema.org/PostalAddress">
					<svg viewBox="0 0 24 24" width="15" height="15" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 21s7-6.2 7-11a7 7 0 1 0-14 0c0 4.8 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/></svg>
					<span itemprop="addressLocality"><?php echo esc_html( $location ); ?></span>
				</p>
				<?php if ( $price ) : ?>
					<div class="pk-single-price-wrap">
						<p class="pk-single-price" itemprop="price"><?php echo pk_price_html( $price ); ?></p>
						<?php if ( $action && in_array( sanitize_title( $action ), array( 'a-louer', 'location', 'rent' ), true ) ) : ?>
							<span class="pk-single-price-note"><?php esc_html_e( '/ mois', 'partikulier' ); ?></span>
						<?php endif; ?>
					</div>
				<?php endif; ?>
			</div>
			<div class="pk-single-actions">
				<button type="button" class="pk-single-action pk-card-wishlist" data-post-id="<?php echo esc_attr( $post->ID ); ?>" aria-label="<?php esc_attr_e( 'Ajouter aux favoris', 'partikulier' ); ?>" aria-pressed="false">
					<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
				</button>
				<button type="button" class="pk-single-action pk-single-share" data-url="<?php echo esc_url( $pk_property_url ); ?>" data-title="<?php echo esc_attr( get_the_title( $post ) ); ?>" aria-label="<?php esc_attr_e( 'Partager cette annonce', 'partikulier' ); ?>">
					<svg viewBox="0 0 24 24" width="17" height="17" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="M8.6 13.5l6.8 4M15.4 6.5l-6.8 4"/></svg>
				</button>
			</div>
			<?php if ( $is_closed ) : ?>
				<span class="pk-single-badge pk-badge-<?php echo esc_attr( sanitize_title( $pk_status ) ); ?>"><?php echo esc_html( $closed_label ); ?></span>
			<?php elseif ( $action ) : ?>
				<span class="pk-single-badge pk-badge-<?php echo esc_attr( sanitize_title( $action ) ); ?>"><?php echo esc_html( $action ); ?></span>
			<?php endif; ?>
		</header>

			<?php if ( $gallery_ids ) : ?>
				<?php $gallery_id = 'pk-gallery-' . (int) $post->ID; ?>
				<section class="pk-single-gallery" data-pk-carousel aria-roledescription="<?php esc_attr_e( 'carrousel', 'partikulier' ); ?>" aria-label="<?php esc_attr_e( 'Photos du bien', 'partikulier' ); ?>">
					<div class="pk-carousel-track" id="<?php echo esc_attr( $gallery_id ); ?>" tabindex="0">
						<?php foreach ( $gallery_ids as $i => $id ) : ?>
							<?php
							$img  = wp_get_attachment_image_url( $id, 'pk-hero' );
							$avif = $img ? Partikulier_AVIF::avif_path_for_url( $img ) : false;
							$alt  = get_post_meta( $id, '_wp_attachment_image_alt', true ) ?: get_the_title( $post );
							?>
							<?php if ( $img ) : ?>
								<figure class="pk-single-gallery-item pk-carousel-slide" aria-label="<?php echo esc_attr( sprintf( __( 'Photo %1$d sur %2$d', 'partikulier' ), $i + 1, count( $gallery_ids ) ) ); ?>">
									<picture>
										<?php if ( $avif ) : ?>
											<source type="image/avif" srcset="<?php echo esc_attr( $avif ); ?>">
										<?php endif; ?>
										<img src="<?php echo esc_url( $img ); ?>" alt="<?php echo esc_attr( $alt ); ?>" loading="eager" decoding="async" <?php echo 0 === $i ? 'fetchpriority="high"' : ''; ?>>
									</picture>
									<?php if ( $is_closed ) : ?>
										<span class="pk-photo-watermark" aria-hidden="true"><?php echo esc_html( $closed_label ); ?></span>
									<?php endif; ?>
								</figure>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
					<?php if ( count( $gallery_ids ) > 1 ) : ?>
						<nav class="pk-carousel-controls" aria-label="<?php esc_attr_e( 'Navigation des photos', 'partikulier' ); ?>">
							<button class="pk-carousel-button pk-carousel-prev" type="button" aria-controls="<?php echo esc_attr( $gallery_id ); ?>" aria-label="<?php esc_attr_e( 'Photo précédente', 'partikulier' ); ?>">‹</button>
							<span class="pk-carousel-count" aria-live="polite">1 / <?php echo (int) count( $gallery_ids ); ?></span>
							<button class="pk-carousel-button pk-carousel-next" type="button" aria-controls="<?php echo esc_attr( $gallery_id ); ?>" aria-label="<?php esc_attr_e( 'Photo suivante', 'partikulier' ); ?>">›</button>
						</nav>
						<div class="pk-carousel-dots" role="tablist" aria-label="<?php esc_attr_e( 'Choisir une photo', 'partikulier' ); ?>">
							<?php foreach ( $gallery_ids as $i => $id ) : ?>
								<button type="button" role="tab" class="pk-carousel-dot<?php echo 0 === $i ? ' is-active' : ''; ?>" aria-selected="<?php echo 0 === $i ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( sprintf( __( 'Afficher la photo %d', 'partikulier' ), $i + 1 ) ); ?>" data-pk-slide="<?php echo (int) $i; ?>"></button>
							<?php endforeach; ?>
						</div>
					<?php endif; ?>
				</section>
				<?php if ( count( $gallery_ids ) > 1 ) : ?>
					<script>
					(function () {
						var gallery = document.querySelector('[data-pk-carousel]');
						if (!gallery) return;
						var track = gallery.querySelector('.pk-carousel-track');
						var slides = gallery.querySelectorAll('.pk-carousel-slide');
						var dots = gallery.querySelectorAll('.pk-carousel-dot');
						var count = gallery.querySelector('.pk-carousel-count');
						var current = 0;
						function setCurrent(index, move) {
							current = Math.max(0, Math.min(index, slides.length - 1));
							if (move) track.scrollTo({ left: track.clientWidth * current, behavior: 'smooth' });
							if (count) count.textContent = (current + 1) + ' / ' + slides.length;
							dots.forEach(function (dot, i) { dot.classList.toggle('is-active', i === current); dot.setAttribute('aria-selected', i === current ? 'true' : 'false'); });
						}
						gallery.querySelector('.pk-carousel-prev').addEventListener('click', function () { setCurrent(current - 1, true); });
						gallery.querySelector('.pk-carousel-next').addEventListener('click', function () { setCurrent(current + 1, true); });
						dots.forEach(function (dot) { dot.addEventListener('click', function () { setCurrent(parseInt(dot.getAttribute('data-pk-slide'), 10), true); }); });
						track.addEventListener('scroll', function () { window.requestAnimationFrame(function () { setCurrent(Math.round(track.scrollLeft / Math.max(track.clientWidth, 1)), false); }); }, { passive: true });
						track.addEventListener('keydown', function (event) { if (event.key === 'ArrowLeft') { event.preventDefault(); setCurrent(current - 1, true); } if (event.key === 'ArrowRight') { event.preventDefault(); setCurrent(current + 1, true); } });
					}());
					</script>
				<?php endif; ?>
			<?php endif; ?>

		<div class="pk-single-grid">
			<div class="pk-single-main">
				<section class="pk-single-section" aria-label="<?php esc_attr_e( 'Caractéristiques', 'partikulier' ); ?>">
					<h2 class="pk-single-section-title"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Caractéristiques', 'Caractéristiques', 'partikulier' ) ); ?></h2>
					<dl class="pk-features">
						<?php
						$fields = array(
								'es_property_area' => array( $surface, Partikulier_Localization::translate_polylang_string( 'Surface', 'Surface', 'partikulier' ), Partikulier_Localization::translate_polylang_string( 'm²', 'm²', 'partikulier' ) ),
'es_property_bedrooms' => array( $bedrooms_display, Partikulier_Localization::translate_polylang_string( 'Chambres', 'Chambres', 'partikulier' ), '' ),
									'_pk_living_rooms' => array( $living_display, Partikulier_Localization::translate_polylang_string( 'Salons', 'Salons', 'partikulier' ), '' ),
									'es_property_bathrooms' => array( $bathrooms_display, Partikulier_Localization::translate_polylang_string( 'Salles de bains', 'Salles de bains', 'partikulier' ), '' ),
									'_pk_terrace'     => array( $terrace_label, Partikulier_Localization::translate_polylang_string( 'Terrasse', 'Terrasse', 'partikulier' ), '' ),
									'_pk_vis_a_vis'   => array( 'Oui' === $vis_a_vis ? Partikulier_Localization::translate_polylang_string( 'Sans vis-à-vis', 'Sans vis-à-vis', 'partikulier' ) : '', Partikulier_Localization::translate_polylang_string( 'Vue', 'Vue', 'partikulier' ), '' ),
									'_pk_sunshine'    => array( $sunshine, Partikulier_Localization::translate_polylang_string( 'Ensoleillement', 'Ensoleillement', 'partikulier' ), '' ),
								'es_garages'     => array( get_post_meta( $post->ID, 'es_garages', true ), Partikulier_Localization::translate_polylang_string( 'Parkings', 'Parkings', 'partikulier' ), '' ),
								'es_floor'       => array( get_post_meta( $post->ID, 'es_floor', true ), Partikulier_Localization::translate_polylang_string( 'Étage', 'Étage', 'partikulier' ), '' ),
								'es_year_built'  => array( get_post_meta( $post->ID, 'es_year_built', true ), Partikulier_Localization::translate_polylang_string( 'Année de construction', 'Année de construction', 'partikulier' ), '' ),
								'es_energy_class' => array( get_post_meta( $post->ID, 'es_energy_class', true ), Partikulier_Localization::translate_polylang_string( 'Classe énergie', 'Classe énergie', 'partikulier' ), '' ),
						);
						foreach ( $fields as $key => $f ) {
							if ( '' !== $f[0] && null !== $f[0] ) {
								printf(
									'<div class="pk-feature"><dt>%s</dt><dd>%s %s</dd></div>',
									esc_html( $f[1] ),
										esc_html( is_numeric( $f[0] ) ? number_format_i18n( (string) $f[0] ) : (string) $f[0] ),
									esc_html( $f[2] )
								);
							}
						}
						?>
					</dl>
				</section>

				<section class="pk-single-section pk-single-description" aria-label="<?php esc_attr_e( 'Description', 'partikulier' ); ?>">
					<h2 class="pk-single-section-title"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Description', 'Description', 'partikulier' ) ); ?></h2>
					<div class="pk-content">
						<?php
						if ( has_excerpt( $post ) ) {
							echo '<p class="pk-lead">' . esc_html( get_the_excerpt( $post ) ) . '</p>';
						}
						the_content();
						?>
					</div>
				</section>
			</div>

			<aside class="pk-single-sidebar">
				<div class="pk-contact-card pk-contact-card--dark" id="pk-contact-card">
<p class="pk-contact-kicker"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Contact sécurisé', 'Contact sécurisé', 'partikulier' ) ); ?></p>
						<h2 class="pk-contact-title"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Intéressé par ce bien ?', 'Intéressé par ce bien ?', 'partikulier' ) ); ?></h2>
						<p class="pk-contact-note"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Envoyez cette annonce sur WhatsApp. Après vérification de votre demande, nous vous transmettons les coordonnées du propriétaire.', 'Envoyez cette annonce sur WhatsApp. Après vérification de votre demande, nous vous transmettons les coordonnées du propriétaire.', 'partikulier' ) ); ?></p>
					<?php if ( $is_closed ) : ?>
						<div class="pk-contact-sold">
							<strong><?php echo esc_html( $closed_label ); ?></strong>
							<span><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Cette annonce ne reçoit plus de contacts.', 'Cette annonce ne reçoit plus de contacts.', 'partikulier' ) ); ?></span>
					</div>
				<?php endif; ?>
					<?php
					$author = get_userdata( $post->post_author );
					if ( $author ) :
						?>
				<div class="pk-contact-owner">
								<span class="pk-contact-avatar" aria-hidden="true"><?php echo esc_html( mb_substr( $owner_name ? $owner_name : $author->display_name, 0, 1 ) ); ?></span>
								<div>
									<strong><?php echo esc_html( $owner_name ? $owner_name : $author->display_name ); ?></strong>
									<span><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Propriétaire', 'Propriétaire', 'partikulier' ) ); ?></span>
								</div>
							</div>
						<?php endif; ?>
						<?php if ( $buyer_contact_url ) : ?>
							<div class="pk-buyer-contact-flow">
								<p><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Envoyez-nous cette annonce sur WhatsApp. Après contrôle de votre demande, nous vous transmettons les coordonnées du propriétaire.', 'Envoyez-nous cette annonce sur WhatsApp. Après contrôle de votre demande, nous vous transmettons les coordonnées du propriétaire.', 'partikulier' ) ); ?></p>
								<a class="pk-btn pk-btn-primary pk-btn-block pk-btn-whatsapp" href="<?php echo esc_url( $buyer_contact_url ); ?>" target="_blank" rel="noopener">
									<svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" aria-hidden="true"><path d="M12.04 2a9.9 9.9 0 0 0-8.5 14.95L2 22l5.2-1.5A9.9 9.9 0 1 0 12.04 2zm0 1.8a8.1 8.1 0 1 1-4.13 15.06l-.3-.18-3.08.89.9-3-.2-.31A8.1 8.1 0 0 1 12.04 3.8zm4.6 10.2c-.25-.13-1.46-.72-1.69-.8-.22-.09-.39-.13-.55.12s-.63.8-.77.96c-.14.17-.28.19-.53.06a6.6 6.6 0 0 1-3.3-2.88c-.25-.43.25-.4.71-1.33.08-.17.04-.31-.02-.44s-.55-1.33-.76-1.82c-.2-.48-.4-.41-.55-.42h-.47a.9.9 0 0 0-.65.3 2.75 2.75 0 0 0-.86 2.05c0 1.2.88 2.37 1 2.53.12.17 1.72 2.63 4.17 3.69 1.55.67 2.16.73 2.94.61.47-.07 1.46-.6 1.67-1.18.2-.58.2-1.07.14-1.18-.06-.1-.23-.17-.48-.29z"/></svg>
									<?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Demander sur WhatsApp', 'Demander sur WhatsApp', 'partikulier' ) ); ?>
								</a>
								<small><?php printf( esc_html__( 'Référence de la demande : %s', 'partikulier' ), esc_html( $buyer_reference ) ); ?></small>
								<small><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Vos critères seront demandés sur WhatsApp. Les annonces similaires ne sont envoyées qu’avec votre accord explicite.', 'Vos critères seront demandés sur WhatsApp. Les annonces similaires ne sont envoyées qu’avec votre accord explicite.', 'partikulier' ) ); ?></small>
							</div>
						<?php elseif ( ! $is_closed ) : ?>
							<?php
							// Repli quand aucun numero WhatsApp n'est encore configure :
							// on garde un bouton actif (wa.me sans destinataire) pour ne pas
							// laisser la carte de contact sans action, comme le preview.
							$pk_wa_text = rawurlencode( sprintf( __( 'Bonjour, je suis intéressé par « %1$s » — %2$s', 'partikulier' ), get_the_title( $post ), $pk_property_url ) );
							?>
							<div class="pk-buyer-contact-flow">
								<a class="pk-btn pk-btn-primary pk-btn-block pk-btn-whatsapp" href="https://wa.me/?text=<?php echo esc_attr( $pk_wa_text ); ?>" target="_blank" rel="noopener nofollow">
									<svg viewBox="0 0 24 24" width="17" height="17" fill="currentColor" aria-hidden="true"><path d="M12.04 2a9.9 9.9 0 0 0-8.5 14.95L2 22l5.2-1.5A9.9 9.9 0 1 0 12.04 2zm0 1.8a8.1 8.1 0 1 1-4.13 15.06l-.3-.18-3.08.89.9-3-.2-.31A8.1 8.1 0 0 1 12.04 3.8zm4.6 10.2c-.25-.13-1.46-.72-1.69-.8-.22-.09-.39-.13-.55.12s-.63.8-.77.96c-.14.17-.28.19-.53.06a6.6 6.6 0 0 1-3.3-2.88c-.25-.43.25-.4.71-1.33.08-.17.04-.31-.02-.44s-.55-1.33-.76-1.82c-.2-.48-.4-.41-.55-.42h-.47a.9.9 0 0 0-.65.3 2.75 2.75 0 0 0-.86 2.05c0 1.2.88 2.37 1 2.53.12.17 1.72 2.63 4.17 3.69 1.55.67 2.16.73 2.94.61.47-.07 1.46-.6 1.67-1.18.2-.58.2-1.07.14-1.18-.06-.1-.23-.17-.48-.29z"/></svg>
									<?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Demander sur WhatsApp', 'Demander sur WhatsApp', 'partikulier' ) ); ?>
								</a>
								<small><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Vos critères sont vérifiés avant transmission des coordonnées.', 'Vos critères sont vérifiés avant transmission des coordonnées.', 'partikulier' ) ); ?></small>
							</div>
						<?php endif; ?>
					<?php
					$city_link = Partikulier_Geo::city_link( $post->ID );
					if ( $city_link ) :
						?>
				<a class="pk-contact-city" href="<?php echo esc_url( $city_link ); ?>">
								<?php esc_html_e( 'Voir les autres annonces dans cette ville', 'partikulier' ); ?> →
							</a>
							<?php if ( $pk_views ) : ?>
								<span class="pk-contact-views" aria-label="<?php esc_attr_e( 'Cette annonce a été vue', 'partikulier' ); ?>">
									<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
									<?php printf( esc_html( _n( '%d vue', '%d vues', $pk_views, 'partikulier' ) ), absint( $pk_views ) ); ?>
								</span>
							<?php endif; ?>
					<?php endif; ?>
				</div>
			</aside>
		</div>
	</div>
</article>

<?php
// --- "Voir aussi a <ville>" : parite avec le preview React ---
$pk_city_ids = wp_get_object_terms( $post->ID, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, array( 'fields' => 'ids' ) );
$pk_city_name = '';
if ( ! is_wp_error( $pk_city_ids ) && $pk_city_ids ) {
	$pk_t = get_term( $pk_city_ids[0], PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
	$pk_city_name = ( $pk_t && ! is_wp_error( $pk_t ) ) ? $pk_t->name : '';
}
$pk_related = array();
if ( $pk_city_ids && ! is_wp_error( $pk_city_ids ) ) {
	$pk_related = get_posts( array(
		'post_type'      => PARTIKULIER_ESTATIK_POST_TYPE,
		'post_status'    => 'publish',
		'posts_per_page' => 3,
		'post__not_in'   => array( $post->ID ),
		'no_found_rows'  => true,
		'tax_query'      => array( array(
			'taxonomy' => PARTIKULIER_ESTATIK_LOCATION_TAXONOMY,
			'field'    => 'term_id',
			'terms'    => $pk_city_ids,
		) ),
		'meta_query'     => Partikulier_Dashboard::active_listing_meta_query(),
	) );
}
if ( $pk_related ) :
	$pk_current = $post;
	?>
	<section class="pk-editorial-section pk-editorial-section--tint pk-single-related">
		<div class="pk-container">
			<div class="pk-editorial-heading pk-editorial-heading--row">
				<div>
<p class="pk-editorial-kicker"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Dans la même ville', 'Dans la même ville', 'partikulier' ) ); ?></p>
						<h2><?php printf( esc_html( Partikulier_Localization::translate_polylang_string( 'Voir aussi à %s', 'Voir aussi à %s', 'partikulier' ) ), esc_html( $pk_city_name ) ); ?></h2>
				</div>
				<a class="pk-editorial-link" href="<?php echo esc_url( Partikulier_Geo::city_link( $pk_current->ID ) ?: pk_properties_archive_url() ); ?>">
					<?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Tout voir', 'Tout voir', 'partikulier' ) ); ?> <span aria-hidden="true">&rarr;</span>
				</a>
			</div>
			<div class="pk-editorial-cards">
				<?php foreach ( $pk_related as $property ) { require PARTIKULIER_DIR . '/templates/parts/card-property.php'; } ?>
			</div>
		</div>
	</section>
	<?php
	$post = $pk_current;
endif;
?>

	<script type="module">
		// Compteur de vues unique par visiteur (24 h).
				if (!<?php echo $is_closed ? 'true' : 'false'; ?> && document.cookie.indexOf("pk_v_<?php echo (int) $post->ID; ?>=") === -1) {
			fetch("<?php echo esc_url( admin_url( "admin-ajax.php" ) ); ?>", {
				method: "POST",
				body: (new URLSearchParams({ action: "pk_views_counter", post_id: "<?php echo (int) $post->ID; ?>", nonce: "<?php echo esc_js( wp_create_nonce( 'pk_views_counter' ) ); ?>" })),
				credentials: "same-origin"
			});
	}
</script>