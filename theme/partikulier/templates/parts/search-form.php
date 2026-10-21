<?php
/**
 * Barre de recherche : onglets vente / location au-dessus, bouton Filtres
 * qui ouvre le panneau (surface, prix, chambres, salles de bain, équipements).
 * Charte tuk.ma. Les libellés passent par le dictionnaire déjà validé.
 *
 * @package Partikulier
 * @var string $variant 'hero'|'archive'|'compact'
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$variant = isset( $variant ) ? $variant : 'hero';
$archive = pk_properties_archive_url();
if ( ! $archive ) {
	$archive = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' );
}

$pk_find_lang = 'fr';
if ( function_exists( 'pll_current_language' ) ) {
	$pk_lang_slug = pll_current_language( 'slug' );
	if ( in_array( $pk_lang_slug, array( 'fr', 'en', 'ar' ), true ) ) {
		$pk_find_lang = $pk_lang_slug;
	}
} elseif ( class_exists( 'Partikulier_Settings' ) && method_exists( 'Partikulier_Settings', 'current_language' ) ) {
	$pk_lang_slug = Partikulier_Settings::current_language();
	if ( in_array( $pk_lang_slug, array( 'fr', 'en', 'ar' ), true ) ) {
		$pk_find_lang = $pk_lang_slug;
	}
}

$pk_t = static function ( $fr ) use ( $pk_find_lang ) {
	$out = class_exists( 'Partikulier_Localization' )
		? Partikulier_Localization::translate_polylang_string( $fr, $fr, 'partikulier' )
		: $fr;
	if ( 'fr' === $pk_find_lang || $out !== $fr ) {
		return $out;
	}
	$extra = array(
		'À vendre'            => array( 'en' => 'For sale', 'ar' => 'للبيع' ),
		'À louer'             => array( 'en' => 'For rent', 'ar' => 'للكراء' ),
		'Prix'                => array( 'en' => 'Price', 'ar' => 'السعر' ),
		'Voir les résultats'  => array( 'en' => 'Show results', 'ar' => 'عرض النتائج' ),
		'Équipements'         => array( 'en' => 'Amenities', 'ar' => 'التجهيزات' ),
	);
	return isset( $extra[ $fr ][ $pk_find_lang ] ) ? $extra[ $fr ][ $pk_find_lang ] : $fr;
};

$pk_scalar = static function ( $key ) {
	if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '';
	}
	return sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
};

$pk_int = static function ( $key ) use ( $pk_scalar ) {
	$raw = $pk_scalar( $key );
	return ( '' !== $raw && preg_match( '/^\d+$/', $raw ) ) ? (int) $raw : 0;
};

$pk_sale_max  = class_exists( 'Partikulier_Search_Filters' ) ? Partikulier_Search_Filters::PRICE_SALE_MAX : 30000000;
$pk_rent_max  = class_exists( 'Partikulier_Search_Filters' ) ? Partikulier_Search_Filters::PRICE_RENT_MAX : 50000;
$pk_sale_step = class_exists( 'Partikulier_Search_Filters' ) ? Partikulier_Search_Filters::PRICE_SALE_STEP : 100000;
$pk_rent_step = class_exists( 'Partikulier_Search_Filters' ) ? Partikulier_Search_Filters::PRICE_RENT_STEP : 500;
$pk_area_max  = class_exists( 'Partikulier_Search_Filters' ) ? Partikulier_Search_Filters::AREA_MAX : 5000;
$pk_area_step = class_exists( 'Partikulier_Search_Filters' ) ? Partikulier_Search_Filters::AREA_STEP : 10;

$pk_action = $pk_scalar( 'es_action' );
if ( ! in_array( $pk_action, array( 'a-vendre', 'a-louer' ), true ) ) {
	$pk_action = 'a-vendre';
}
$pk_is_rent   = ( 'a-louer' === $pk_action );
$pk_price_cap = $pk_is_rent ? $pk_rent_max : $pk_sale_max;
$pk_price_step = $pk_is_rent ? $pk_rent_step : $pk_sale_step;

$pk_type = $pk_scalar( 'es_type' );
$pk_city_slug = isset( $_GET['es_city'] ) && is_scalar( $_GET['es_city'] ) ? sanitize_title( wp_unslash( $_GET['es_city'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
$pk_city_term = $pk_city_slug ? get_term_by( 'slug', $pk_city_slug, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) : false;
$pk_city_label = ( $pk_city_term && ! is_wp_error( $pk_city_term ) ) ? $pk_city_term->name : '';

$pk_price_min = min( $pk_price_cap, $pk_int( 'es_price_min' ) );
$pk_price_max_raw = $pk_int( 'es_price_max' );
$pk_price_max_val = ( $pk_price_max_raw > 0 && $pk_price_max_raw < $pk_price_cap ) ? $pk_price_max_raw : $pk_price_cap;
if ( $pk_price_min > $pk_price_max_val ) {
	$pk_price_min = 0;
}

$pk_area_min = min( $pk_area_max, $pk_int( 'es_size_min' ) );
$pk_area_max_raw = $pk_int( 'es_size_max' );
$pk_area_max_val = ( $pk_area_max_raw > 0 && $pk_area_max_raw < $pk_area_max ) ? $pk_area_max_raw : $pk_area_max;
if ( $pk_area_min > $pk_area_max_val ) {
	$pk_area_min = 0;
}

$pk_beds = $pk_int( 'es_bedrooms_min' );
$pk_baths = $pk_int( 'es_bathrooms_min' );
if ( ! in_array( $pk_beds, array( 0, 1, 2, 3, 4 ), true ) ) {
	$pk_beds = 0;
}
if ( ! in_array( $pk_baths, array( 0, 1, 2, 3, 4 ), true ) ) {
	$pk_baths = 0;
}

$pk_selected_amenities = array();
if ( isset( $_GET['pk_amenity'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$pk_amenity_raw = wp_unslash( $_GET['pk_amenity'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$pk_amenity_list = is_array( $pk_amenity_raw ) ? $pk_amenity_raw : ( is_scalar( $pk_amenity_raw ) ? array( $pk_amenity_raw ) : array() );
	foreach ( $pk_amenity_list as $pk_amenity_item ) {
		if ( is_scalar( $pk_amenity_item ) ) {
			$pk_selected_amenities[] = sanitize_key( (string) $pk_amenity_item );
		}
	}
}

$pk_rent = $pk_scalar( 'pk_rent' );
$pk_fmt = static function ( $n ) {
	return number_format( (int) $n, 0, ',', ' ' );
};
$pk_unit_sale = ( 'ar' === $pk_find_lang ) ? 'درهم' : 'DH';
$pk_unit_rent = ( 'ar' === $pk_find_lang ) ? 'درهم / شهر' : ( 'en' === $pk_find_lang ? 'DH/month' : 'DH / mois' );
$pk_price_unit = $pk_is_rent ? $pk_unit_rent : $pk_unit_sale;
$pk_fill = static function ( $min, $max, $cap ) {
	$span = max( 1, (int) $cap );
	$start = max( 0, min( 100, ( $min / $span ) * 100 ) );
	$width = max( 0, min( 100 - $start, ( ( $max - $min ) / $span ) * 100 ) );
	return 'left:' . round( $start, 2 ) . '%;width:' . round( $width, 2 ) . '%';
};

$pk_types = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY, 'hide_empty' => true ) );
$pk_types = is_wp_error( $pk_types ) ? array() : $pk_types;

$pk_amenities = array();
if ( class_exists( 'Partikulier_Amenities' ) ) {
	foreach ( Partikulier_Amenities::items() as $pk_item ) {
		if ( ! is_array( $pk_item ) || empty( $pk_item['id'] ) ) {
			continue;
		}
		$pk_ctx = isset( $pk_item['ctx'] ) && is_array( $pk_item['ctx'] ) ? $pk_item['ctx'] : array();
		if ( $pk_ctx && ! array_intersect( $pk_ctx, array( 'vente', 'vide' ) ) ) {
			continue;
		}
		$pk_amenities[] = $pk_item;
	}
}
$pk_amenity_title = class_exists( 'Partikulier_Amenities' ) ? Partikulier_Amenities::copy( 'group' ) : $pk_t( 'Équipements' );

$pk_panel_open = ( $pk_price_min > 0 || $pk_price_max_val < $pk_price_cap || $pk_area_min > 0 || $pk_area_max_val < $pk_area_max || $pk_beds > 0 || $pk_baths > 0 || ! empty( $pk_selected_amenities ) );
$pk_clear = add_query_arg( 'es_action', $pk_action, $archive );
if ( '' !== $pk_rent ) {
	$pk_clear = add_query_arg( 'pk_rent', $pk_rent, $pk_clear );
}

$pk_city_placeholder = class_exists( 'Partikulier_Localization' )
	? Partikulier_Localization::translate_polylang_string( 'Ville, code postal, quartier…', 'Ville, code postal, quartier…', 'partikulier' )
	: 'Ville, code postal, quartier…';
?>
<form class="pk-search pk-search-<?php echo esc_attr( $variant ); ?> pk-find" action="<?php echo esc_url( $archive ); ?>" method="get" role="search" data-pk-live="<?php echo esc_attr( $variant ); ?>" aria-label="<?php echo esc_attr( $pk_t( 'Rechercher' ) ); ?>">
	<div class="pk-find-tabs" role="radiogroup" aria-label="<?php echo esc_attr( $pk_t( 'À vendre' ) . ' / ' . $pk_t( 'À louer' ) ); ?>">
		<input class="screen-reader-text pk-find-tab-input" type="radio" name="es_action" id="pk-find-sale" value="a-vendre" <?php checked( $pk_action, 'a-vendre' ); ?>>
		<label class="pk-find-tab" for="pk-find-sale"><?php echo esc_html( $pk_t( 'À vendre' ) ); ?></label>
		<input class="screen-reader-text pk-find-tab-input" type="radio" name="es_action" id="pk-find-rent" value="a-louer" <?php checked( $pk_action, 'a-louer' ); ?>>
		<label class="pk-find-tab" for="pk-find-rent"><?php echo esc_html( $pk_t( 'À louer' ) ); ?></label>
	</div>

	<input class="pk-find-open" type="checkbox" id="pk-find-open" aria-controls="pk-find-panel" <?php checked( $pk_panel_open ); ?>>
	<label class="pk-find-backdrop" for="pk-find-open"></label>

	<div class="pk-find-card">
		<div class="pk-find-field pk-find-city pk-place-autocomplete">
			<label class="pk-find-label" for="pk-s-city-input"><?php echo esc_html( $pk_t( 'Ville' ) ); ?></label>
			<div class="pk-place-autocomplete-wrap">
				<input type="search" name="s" id="pk-s-city-input" class="pk-place-input" value="<?php echo esc_attr( $pk_city_label ); ?>" placeholder="<?php echo esc_attr( $pk_city_placeholder ); ?>" autocomplete="off" data-pk-place-input="true" data-pk-place-value="pk-s-city-value" aria-controls="pk-s-city-suggestions" aria-autocomplete="list">
				<input type="hidden" name="es_city" id="pk-s-city-value" value="<?php echo esc_attr( $pk_city_slug ); ?>">
				<ul id="pk-s-city-suggestions" class="pk-suggest pk-place-suggestions" role="listbox" hidden></ul>
			</div>
		</div>

		<div class="pk-find-field pk-find-typefield">
			<span class="pk-find-label" id="pk-find-type-label"><?php echo esc_html( $pk_t( 'Type de bien' ) ); ?></span>
			<select class="pk-find-type-native" name="es_type" id="pk-find-type" aria-labelledby="pk-find-type-label">
				<option value=""><?php echo esc_html( $pk_t( 'Tous' ) ); ?></option>
				<?php foreach ( (array) $pk_types as $pk_term ) : ?>
					<?php if ( ! $pk_term instanceof WP_Term ) { continue; } ?>
					<option value="<?php echo esc_attr( $pk_term->slug ); ?>" <?php selected( $pk_type, $pk_term->slug ); ?>><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $pk_term->name ) : $pk_term->name ); ?></option>
				<?php endforeach; ?>
			</select>
			<div class="pk-find-type-ui">
				<button type="button" class="pk-find-type-btn" aria-haspopup="listbox" aria-expanded="false" aria-controls="pk-find-type-menu" aria-labelledby="pk-find-type-label">
					<span class="pk-find-type-current"><?php echo esc_html( $pk_t( 'Tous' ) ); ?></span>
				</button>
				<div class="pk-find-type-backdrop" hidden></div>
				<div class="pk-find-type-menu" id="pk-find-type-menu" role="listbox" aria-labelledby="pk-find-type-label" hidden>
					<p class="pk-find-type-kicker"><?php echo esc_html( $pk_t( 'Type de bien' ) ); ?></p>
					<button type="button" class="pk-find-type-opt" role="option" data-value=""><?php echo esc_html( $pk_t( 'Tous' ) ); ?></button>
					<?php foreach ( (array) $pk_types as $pk_term ) : ?>
						<?php if ( ! $pk_term instanceof WP_Term ) { continue; } ?>
						<button type="button" class="pk-find-type-opt" role="option" data-value="<?php echo esc_attr( $pk_term->slug ); ?>"><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $pk_term->name ) : $pk_term->name ); ?></button>
					<?php endforeach; ?>
				</div>
			</div>
		</div>

		<label class="pk-find-filter" for="pk-find-open">
			<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2.2" fill="currentColor"/><circle cx="15" cy="12" r="2.2" fill="currentColor"/><circle cx="8" cy="17" r="2.2" fill="currentColor"/></svg>
			<span><?php echo esc_html( $pk_t( 'Filtres' ) ); ?></span>
		</label>

		<button type="submit" class="pk-find-submit"><?php echo esc_html( $pk_t( 'Rechercher' ) ); ?></button>
	</div>

	<div class="pk-find-panel" id="pk-find-panel">
		<div class="pk-find-split">
			<div class="pk-find-range" data-pk-range data-pk-price data-unit="<?php echo esc_attr( $pk_price_unit ); ?>" data-sale-max="<?php echo esc_attr( $pk_sale_max ); ?>" data-rent-max="<?php echo esc_attr( $pk_rent_max ); ?>" data-sale-step="<?php echo esc_attr( $pk_sale_step ); ?>" data-rent-step="<?php echo esc_attr( $pk_rent_step ); ?>" data-sale-unit="<?php echo esc_attr( $pk_unit_sale ); ?>" data-rent-unit="<?php echo esc_attr( $pk_unit_rent ); ?>">
				<div class="pk-find-range-head">
					<span class="pk-find-range-name"><?php echo esc_html( $pk_t( 'Prix' ) ); ?></span>
					<span class="pk-find-readout" dir="ltr"><?php echo esc_html( $pk_fmt( $pk_price_min ) . ' – ' . $pk_fmt( $pk_price_max_val ) . ' ' . $pk_price_unit ); ?></span>
				</div>
				<div class="pk-find-slider">
					<span class="pk-find-track"></span>
					<span class="pk-find-fill" style="<?php echo esc_attr( $pk_fill( $pk_price_min, $pk_price_max_val, $pk_price_cap ) ); ?>"></span>
					<input class="pk-find-range-min" type="range" name="es_price_min" min="0" max="<?php echo esc_attr( $pk_price_cap ); ?>" step="<?php echo esc_attr( $pk_price_step ); ?>" value="<?php echo esc_attr( $pk_price_min ); ?>" aria-label="<?php echo esc_attr( $pk_t( 'Prix' ) ); ?>">
					<input class="pk-find-range-max" type="range" name="es_price_max" min="0" max="<?php echo esc_attr( $pk_price_cap ); ?>" step="<?php echo esc_attr( $pk_price_step ); ?>" value="<?php echo esc_attr( $pk_price_max_val ); ?>" aria-label="<?php echo esc_attr( $pk_t( 'Prix' ) ); ?>">
				</div>
			</div>

			<div class="pk-find-range" data-pk-range data-pk-area data-unit="m²">
				<div class="pk-find-range-head">
					<span class="pk-find-range-name"><?php echo esc_html( $pk_t( 'Surface' ) ); ?></span>
					<span class="pk-find-readout" dir="ltr"><?php echo esc_html( $pk_fmt( $pk_area_min ) . ' – ' . $pk_fmt( $pk_area_max_val ) . ' m²' ); ?></span>
				</div>
				<div class="pk-find-slider">
					<span class="pk-find-track"></span>
					<span class="pk-find-fill" style="<?php echo esc_attr( $pk_fill( $pk_area_min, $pk_area_max_val, $pk_area_max ) ); ?>"></span>
					<input class="pk-find-range-min" type="range" name="es_size_min" min="0" max="<?php echo esc_attr( $pk_area_max ); ?>" step="<?php echo esc_attr( $pk_area_step ); ?>" value="<?php echo esc_attr( $pk_area_min ); ?>" aria-label="<?php echo esc_attr( $pk_t( 'Surface' ) ); ?>">
					<input class="pk-find-range-max" type="range" name="es_size_max" min="0" max="<?php echo esc_attr( $pk_area_max ); ?>" step="<?php echo esc_attr( $pk_area_step ); ?>" value="<?php echo esc_attr( $pk_area_max_val ); ?>" aria-label="<?php echo esc_attr( $pk_t( 'Surface' ) ); ?>">
				</div>
			</div>
		</div>

		<div class="pk-find-split pk-find-rooms">
			<fieldset class="pk-find-pills">
				<legend><?php echo esc_html( $pk_t( 'Chambres' ) ); ?></legend>
				<input class="screen-reader-text" type="radio" name="es_bedrooms_min" value="" <?php checked( $pk_beds, 0 ); ?>>
				<?php foreach ( array( 1, 2, 3, 4 ) as $pk_n ) : ?>
					<label class="pk-find-pill">
						<input type="radio" name="es_bedrooms_min" value="<?php echo esc_attr( $pk_n ); ?>" <?php checked( $pk_beds, $pk_n ); ?>>
<span dir="ltr"><?php echo esc_html( 4 === $pk_n ? '+4' : (string) $pk_n ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<fieldset class="pk-find-pills">
				<legend><?php echo esc_html( $pk_t( 'Salles de bains' ) ); ?></legend>
				<input class="screen-reader-text" type="radio" name="es_bathrooms_min" value="" <?php checked( $pk_baths, 0 ); ?>>
				<?php foreach ( array( 1, 2, 3, 4 ) as $pk_n ) : ?>
					<label class="pk-find-pill">
						<input type="radio" name="es_bathrooms_min" value="<?php echo esc_attr( $pk_n ); ?>" <?php checked( $pk_baths, $pk_n ); ?>>
						<span dir="ltr"><?php echo esc_html( 4 === $pk_n ? '+4' : (string) $pk_n ); ?></span>
					</label>
				<?php endforeach; ?>
			</fieldset>
		</div>

		<?php if ( $pk_amenities ) : ?>
			<div class="pk-find-amenities">
				<p class="pk-find-label"><?php echo esc_html( $pk_amenity_title ); ?></p>
				<div class="pk-find-chips">
					<?php foreach ( $pk_amenities as $pk_item ) : ?>
						<?php $pk_id = sanitize_key( $pk_item['id'] ); ?>
						<label class="pk-find-chip">
							<input type="checkbox" name="pk_amenity[]" value="<?php echo esc_attr( $pk_id ); ?>" <?php checked( in_array( $pk_id, $pk_selected_amenities, true ) ); ?>>
							<span><?php echo esc_html( Partikulier_Amenities::label( $pk_item ) ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>
		<?php endif; ?>

		<div class="pk-find-foot">
			<a class="pk-find-clear" href="<?php echo esc_url( $pk_clear ); ?>"><?php echo esc_html( $pk_t( 'Réinitialiser' ) ); ?></a>
			<button type="submit" class="pk-find-results"><?php echo esc_html( $pk_t( 'Voir les résultats' ) ); ?></button>
		</div>
	</div>

	<?php if ( '' !== $pk_rent ) : ?>
		<input type="hidden" name="pk_rent" value="<?php echo esc_attr( $pk_rent ); ?>">
	<?php endif; ?>
</form>
