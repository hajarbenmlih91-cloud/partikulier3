<?php
/**
 * Accueil Partikulier — structure éditoriale alignée sur le preview Manus.
 * Les annonces, villes et catégories restent issus d’Estatik ; les blocs vides
 * affichent un état éditorial honnête sans inventer de biens ou de témoignages.
 * @package Partikulier
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
get_header();

	$pk_home_query = array(
		'post_type'              => PARTIKULIER_ESTATIK_POST_TYPE,
		'post_status'            => 'publish',
		'no_found_rows'          => true,
		'orderby'                => array( 'post_date' => 'DESC', 'ID' => 'DESC' ),
		'meta_query'             => Partikulier_Dashboard::active_listing_meta_query(),
		'update_post_term_cache' => true,
		'update_post_meta_cache' => true,
	);
	$pk_latest = get_posts( array_merge( $pk_home_query, array( 'posts_per_page' => 1 ) ) );
	$featured  = ! empty( $pk_latest ) ? array( $pk_latest[0] ) : array();
	$pk_home_types_for_cards = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY, 'hide_empty' => true, 'orderby' => 'name', 'order' => 'ASC' ) );
	$pk_card_skip = array( 'apartments', 'condos', 'condo', 'houses', 'house', 'land', 'multifamily', 'townhouses', 'townhouse' );
	$pk_card_order = array( 'appartement', 'duplex', 'immeuble', 'maison', 'riad', 'studio', 'terrain', 'villa' );
	$pk_card_terms = array();
	if ( ! is_wp_error( $pk_home_types_for_cards ) ) {
		foreach ( $pk_home_types_for_cards as $pk_card_term ) {
			if ( in_array( strtolower( (string) $pk_card_term->name ), $pk_card_skip, true ) || (int) $pk_card_term->count < 1 ) {
				continue;
			}
			$pk_card_terms[ $pk_card_term->slug ] = $pk_card_term;
		}
	}
	$recent = array();
	$pk_card_slugs = array();
	foreach ( $pk_card_order as $pk_card_slug ) {
		if ( isset( $pk_card_terms[ $pk_card_slug ] ) ) {
			$pk_card_slugs[] = $pk_card_slug;
		}
	}
	foreach ( $pk_card_terms as $pk_card_slug => $pk_card_term ) {
		if ( ! in_array( $pk_card_slug, $pk_card_slugs, true ) ) {
			$pk_card_slugs[] = $pk_card_slug;
		}
	}
	foreach ( $pk_card_slugs as $pk_card_slug ) {
		$pk_one = get_posts( array_merge( $pk_home_query, array(
			'posts_per_page' => 1,
			'tax_query'      => array(
				array(
					'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY,
					'field'    => 'term_id',
					'terms'    => (int) $pk_card_terms[ $pk_card_slug ]->term_id,
				),
			),
		) ) );
		if ( $pk_one ) {
			$recent[] = $pk_one[0];
		}
	}
	// Prefixe pk_home_ : card-property.php et search-form.php ecrasent $types
	// (variables globales partagees par require). Bug constate au rendu :
	// une seule tuile de type s'affichait, vide, apres la boucle des cartes.
	$pk_home_types  = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY, 'hide_empty' => false, 'orderby' => 'name', 'order' => 'ASC' ) );
	$pk_home_cities = Partikulier_Geo::top_cities( 8 );
	$hero_url       = Partikulier_Customization::hero_url();
	$hero_alt       = Partikulier_Customization::hero_alt( Partikulier_Localization::translate_polylang_string( 'Maison lumineuse à vendre entre particuliers', 'Maison lumineuse à vendre entre particuliers', 'partikulier' ) );
	$home           = home_url( '/' );
	$archive        = pk_properties_archive_url();
	if ( is_wp_error( $archive ) || ! is_string( $archive ) ) { $archive = home_url( '/' ); }
	$deposit      = pk_page_url( 'deposer', '/deposer/' );
	$pk_home_lang = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
	$tagline      = Partikulier_Customization::editorial( 'home_title', Partikulier_Settings::get( 'site_tagline' ) );
	$intro        = Partikulier_Customization::editorial( 'home_intro', Partikulier_Settings::get( 'site_intro' ) );
		$badge_1  = Partikulier_Customization::editorial( 'badge_1', class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Zéro commission', 'Zéro commission', 'partikulier' ) : 'Zéro commission' );
		$badge_2  = Partikulier_Customization::editorial( 'badge_2', class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Vendeur identifié', 'Vendeur identifié', 'partikulier' ) : 'Vendeur identifié' );
		$badge_3  = Partikulier_Customization::editorial( 'badge_3', class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Contact direct', 'Contact direct', 'partikulier' ) : 'Contact direct' );
	?>

	<style>
	@media (max-width:767px){
		body.home .pk-header-search{display:none!important}
		body.home .pk-editorial-hero__inner{padding:1.1rem 20px 1.4rem!important;gap:1rem}
	}
	</style>
	<section class="pk-editorial-hero" aria-label="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Recherche principale', 'Recherche principale', 'partikulier' ) ); ?>">
	<div class="pk-container pk-editorial-hero__inner">
		<div class="pk-editorial-hero__copy">
			<p class="pk-editorial-kicker"><?php echo esc_html( Partikulier_Customization::editorial( 'home_kicker', 'La plateforme entre particuliers' ) ); ?></p>
			<h1><?php
			// Le titre se coupe sur le dernier segment. L'accent est en rouge sombre.
			$pk_parts = preg_split( '/\s+/', trim( (string) $tagline ) );
			if ( count( $pk_parts ) > 2 ) {
				$pk_tail = implode( ' ', array_slice( $pk_parts, -2 ) );
				$pk_head = implode( ' ', array_slice( $pk_parts, 0, -2 ) );
				echo esc_html( $pk_head ) . ' <span class="pk-hero-accent">' . esc_html( $pk_tail ) . '</span>';
			} else {
				echo esc_html( $tagline );
			}
			?></h1>
			<p class="pk-editorial-hero__intro"><?php echo wp_kses( $intro, array( 'a' => array( 'href' => true, 'title' => true ), 'strong' => array(), 'em' => array(), 'br' => array() ) ); ?></p>
		</div>
		<div class="pk-editorial-search"><?php $variant = 'hero'; require PARTIKULIER_DIR . '/templates/parts/search-form.php'; ?><ul class="pk-hero-trust"><li><?php echo esc_html( $badge_1 ); ?></li><li><?php echo esc_html( $badge_2 ); ?></li><li><?php echo esc_html( $badge_3 ); ?></li></ul></div>
	</div>
</section>

	<div class="pk-scroll-actions" data-pk-scroll-actions>
		<div class="pk-container pk-scroll-actions__inner">
			<a class="pk-btn pk-scroll-city" href="<?php echo esc_url( $archive ); ?>"><?php echo esc_html( Partikulier_Settings::get( 'btn_listings' ) ); ?></a>
		</div>
	</div>

	<section class="pk-editorial-section pk-editorial-section--tint"><div class="pk-container"><div class="pk-editorial-heading"><h2><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Les dernières annonces', 'Les dernières annonces', 'partikulier' ) ); ?></h2><p><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Des biens ajoutés récemment par leurs propriétaires.', 'Des biens ajoutés récemment par leurs propriétaires.', 'partikulier' ) ); ?></p></div><?php if ( $recent ) : ?><div class="pk-editorial-cards"><?php $pk_card_index = 0; foreach ( $recent as $property ) { $pk_card_index++; require PARTIKULIER_DIR . '/templates/parts/card-property.php'; } unset( $pk_card_index ); wp_reset_postdata(); ?></div><p class="pk-editorial-more"><a class="pk-editorial-link" href="<?php echo esc_url( $archive ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Voir toutes les annonces', 'Voir toutes les annonces', 'partikulier' ) ); ?> <span aria-hidden="true">→</span></a></p><?php else : ?><div class="pk-editorial-empty pk-editorial-empty--compact"><strong><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Aucune annonce publiée pour le moment.', 'Aucune annonce publiée pour le moment.', 'partikulier' ) ); ?></strong><a class="pk-editorial-link" href="<?php echo esc_url( $deposit ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Publier gratuitement', 'Publier gratuitement', 'partikulier' ) ); ?> <span aria-hidden="true">→</span></a></div><?php endif; ?></div></section>

	<section class="pk-editorial-section pk-editorial-section--featured"><div class="pk-container"><div class="pk-editorial-heading"><p class="pk-editorial-kicker"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Annonce à la une', 'Annonce à la une', 'partikulier' ) ); ?></p><h2><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Des biens choisis pour leur lumière.', 'Des biens choisis pour leur lumière.', 'partikulier' ) ); ?></h2></div>
<?php if ( $featured ) : $property = $featured[0]; $img = Partikulier_AVIF::first_image( $property->ID ); $pk_featured_title = class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::title_from_post( $property, $pk_home_lang ) : get_the_title( $property ); $pk_featured_location = Partikulier_Geo::location_string( $property->ID, $pk_home_lang ); $pk_featured_url = get_permalink( $property ); if ( class_exists( 'Partikulier_Listing_URLs' ) ) { $pk_featured_url = Partikulier_Listing_URLs::filter_link( $pk_featured_url, $property ); } $pk_featured_kicker = ''; $pk_featured_heading = $pk_featured_title; $pk_feat_actions = get_the_terms( $property->ID, PARTIKULIER_ESTATIK_STATUS_TAXONOMY ); $pk_feat_types = get_the_terms( $property->ID, PARTIKULIER_ESTATIK_TYPE_TAXONOMY ); $pk_feat_action = ( $pk_feat_actions && ! is_wp_error( $pk_feat_actions ) ) ? $pk_feat_actions[0]->name : ''; if ( $pk_feat_action && class_exists( 'Partikulier_Localization' ) ) { $pk_feat_action = Partikulier_Localization::translate_taxonomy_label( $pk_feat_action ); } $pk_feat_type = ( $pk_feat_types && ! is_wp_error( $pk_feat_types ) ) ? $pk_feat_types[0]->name : ''; if ( $pk_feat_type && class_exists( 'Partikulier_Listing_I18n' ) ) { $pk_feat_type = Partikulier_Listing_I18n::localized_type( $pk_feat_type, $pk_home_lang ); } $pk_featured_kicker = trim( implode( ' · ', array_filter( array( $pk_feat_action, $pk_feat_type ) ) ) ); if ( $pk_feat_type && $pk_featured_location ) { $pk_featured_heading = $pk_feat_type . ' · ' . $pk_featured_location; } $pk_feat_price_raw = get_post_meta( $property->ID, 'es_property_price', true ); $pk_featured_price = $pk_feat_price_raw ? trim( ( function_exists( 'pk_format_amount' ) ? pk_format_amount( $pk_feat_price_raw ) : $pk_feat_price_raw ) . ' ' . apply_filters( 'partikulier_currency', 'DH' ) ) : ''; ?><a class="pk-editorial-feature" href="<?php echo esc_url( $pk_featured_url ); ?>"><?php if ( $img ) : ?><img src="<?php echo esc_url( $img ); ?>" width="1600" height="900" alt="<?php echo esc_attr( $pk_featured_title ); ?>" loading="eager" fetchpriority="high" decoding="async"><?php endif; ?><span class="pk-editorial-feature__veil"></span><span class="pk-editorial-feature__content"><?php if ( $pk_featured_kicker ) : ?><p class="pk-editorial-feature__kicker"><?php echo esc_html( $pk_featured_kicker ); ?></p><?php endif; ?><strong><?php echo esc_html( $pk_featured_heading ); ?></strong><small><?php echo esc_html( $pk_featured_location ); ?></small><?php if ( $pk_featured_price ) : ?><span class="pk-editorial-feature__price"><?php echo esc_html( $pk_featured_price ); ?></span><?php endif; ?></span></a><?php else : ?><div class="pk-editorial-empty"><strong><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Les premières annonces apparaîtront ici.', 'Les premières annonces apparaîtront ici.', 'partikulier' ) ); ?></strong><span><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Déposez un bien gratuitement pour ouvrir cette sélection.', 'Déposez un bien gratuitement pour ouvrir cette sélection.', 'partikulier' ) ); ?></span><a class="pk-btn pk-btn-dark" href="<?php echo esc_url( $deposit ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Déposer une annonce', 'Déposer une annonce', 'partikulier' ) ); ?></a></div><?php endif; ?></div></section>

	<section class="pk-editorial-section"><div class="pk-container"><div class="pk-editorial-heading"><p class="pk-editorial-kicker"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Trouvez votre bien', 'Trouvez votre bien', 'partikulier' ) ); ?></p><h2><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Une recherche qui commence par le bon lieu.', 'Une recherche qui commence par le bon lieu.', 'partikulier' ) ); ?></h2><p><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Parcourez les catégories sans bruit, puis laissez les détails vous guider.', 'Parcourez les catégories sans bruit, puis laissez les détails vous guider.', 'partikulier' ) ); ?></p></div><div class="pk-editorial-types"><?php if ( ! is_wp_error( $pk_home_types ) && $pk_home_types ) : foreach ( $pk_home_types as $term ) : if ( in_array( strtolower( (string) $term->name ), array( 'apartments', 'condos', 'condo', 'houses', 'house', 'land', 'multifamily', 'townhouses', 'townhouse' ), true ) ) { continue; } ?><a href="<?php echo esc_url( pk_term_url( $term, $archive ) ); ?>" class="pk-editorial-type"><span class="pk-editorial-type__icon"><?php echo pk_type_icon( $term->slug ); // phpcs:ignore ?></span><strong><?php echo esc_html( class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::localized_type( $term->name, $pk_home_lang ) : $term->name ); ?></strong><small><?php echo esc_html( number_format_i18n( $term->count ) ); ?> <?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'annonces', 'annonces', 'partikulier' ) ); ?></small></a><?php endforeach; else : foreach ( array( 'Appartement', 'Maison', 'Terrain', 'Parking', 'Immeuble', 'Local' ) as $label ) : ?><a href="<?php echo esc_url( $archive ); ?>" class="pk-editorial-type"><span class="pk-editorial-type__icon"><?php echo pk_type_icon( sanitize_title( $label ) ); // phpcs:ignore ?></span><strong><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( $label, $label, 'partikulier' ) : esc_html__( $label, 'partikulier' ) ); ?></strong><small><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Explorer', 'Explorer', 'partikulier' ) : __( 'Explorer', 'partikulier' ) ); ?></small></a><?php endforeach; endif; ?></div></div></section>


	<section class="pk-editorial-section"><div class="pk-container pk-editorial-places"><div><p class="pk-editorial-kicker"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Proche de chez vous', 'Proche de chez vous', 'partikulier' ) ); ?></p><h2><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Trouvez un bien dans votre ville.', 'Trouvez un bien dans votre ville.', 'partikulier' ) ); ?></h2><p><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Les quartiers et villes sont indexés pour vous aider à trouver plus vite.', 'Les quartiers et villes sont indexés pour vous aider à trouver plus vite.', 'partikulier' ) ); ?></p><a class="pk-btn pk-btn-dark" href="<?php echo esc_url( $archive ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Explorer les annonces', 'Explorer les annonces', 'partikulier' ) ); ?></a></div><div class="pk-editorial-place-list"><?php if ( $pk_home_cities ) : foreach ( $pk_home_cities as $city ) : ?><a href="<?php echo esc_url( pk_term_url( $city, $archive ) ); ?>"><span><?php echo esc_html( class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::localized_place( $city->name, $pk_home_lang ) : $city->name ); ?></span><small><?php echo esc_html( number_format_i18n( $city->count ) ); ?></small><span aria-hidden="true">→</span></a><?php endforeach; else : ?><p class="pk-editorial-empty-text"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Les villes apparaîtront dès les premières annonces.', 'Les villes apparaîtront dès les premières annonces.', 'partikulier' ) ); ?></p><?php endif; ?></div></div></section>


<?php get_footer();