<?php
/**
 * Barre de recherche : trois onglets (vente, location, saisonnière).
 * Le panneau Filtres ne s'ouvre qu'après un type de bien. Son contenu
 * suit la transaction et le type. Charte pep.ma. Libellés déjà validés.
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
		'Saisonnière'         => array( 'en' => 'Seasonal', 'ar' => 'موسمية' ),
		'Veuillez vérifier les champs obligatoires.' => array( 'en' => 'Please check the required fields.', 'ar' => 'يرجى التحقق من الحقول المطلوبة.' ),
		'Fermer'              => array( 'en' => 'Close', 'ar' => 'إغلاق' ),
		'Prix'                => array( 'en' => 'Price', 'ar' => 'السعر' ),
		'Voir les résultats'  => array( 'en' => 'Show results', 'ar' => 'عرض النتائج' ),
		'Équipements'         => array( 'en' => 'Amenities', 'ar' => 'التجهيزات' ),
		'Type de transaction' => array( 'en' => 'Transaction', 'ar' => 'المعاملة' ),
		'Vente'               => array( 'en' => 'For sale', 'ar' => 'للبيع' ),
		'Location'            => array( 'en' => 'For rent', 'ar' => 'للكراء' ),
		'Vacances'            => array( 'en' => 'Seasonal', 'ar' => 'موسمية' ),
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

$pk_action_raw = $pk_scalar( 'es_action' );
$pk_intent_raw = $pk_scalar( 'pk_intent' );
$pk_intent     = '';
if ( in_array( $pk_intent_raw, array( 'a-vendre', 'a-louer', 'saisonnier' ), true ) ) {
	$pk_intent = $pk_intent_raw;
} elseif ( 'saisonnier' === $pk_scalar( 'pk_rent' ) ) {
	$pk_intent = 'saisonnier';
} elseif ( in_array( $pk_action_raw, array( 'a-vendre', 'a-louer' ), true ) ) {
	$pk_intent = $pk_action_raw;
}
if ( '' === $pk_intent && isset( $variant ) && in_array( $variant, array( 'hero', 'archive' ), true ) ) {
	$pk_intent = 'a-vendre';
}
$pk_is_rent      = in_array( $pk_intent, array( 'a-louer', 'saisonnier' ), true );
$pk_action_value = 'saisonnier' === $pk_intent || 'a-louer' === $pk_intent ? 'a-louer' : ( 'a-vendre' === $pk_intent ? 'a-vendre' : '' );
$pk_rent_value   = ( 'saisonnier' === $pk_intent ) ? 'saisonnier' : '';
$pk_family_of    = static function ( $slug ) {
	$slug = sanitize_title( (string) $slug );
	$slug = preg_replace( '/-(fr|en|ar)$/', '', $slug );
	if ( 'terrain' === $slug ) {
		return 'terrain';
	}
	if ( 'studio' === $slug ) {
		return 'studio';
	}
	if ( 'immeuble' === $slug ) {
		return 'immeuble';
	}
	return '' === $slug ? '' : 'logement';
};
$pk_tx_codes = static function ( $item ) {
	$liste = isset( $item['ctx'] ) && is_array( $item['ctx'] ) ? $item['ctx'] : array();
	$codes = array();
	if ( in_array( 'vente', $liste, true ) ) {
		$codes[] = 'vente';
	}
	if ( array_intersect( $liste, array( 'vide', 'meuble' ) ) ) {
		$codes[] = 'louer';
	}
	if ( in_array( 'saisonnier', $liste, true ) || in_array( 'meuble', $liste, true ) ) {
		$codes[] = 'saisonnier';
	}
	return $codes;
};
$pk_chip_types = static function ( $id ) {
	$building = array( 'elevator', 'garage', 'garage_m', 'security', 'pool', 'gym', 'concierge', 'facade', 'pets' );
	if ( in_array( $id, $building, true ) ) {
		return 'logement studio immeuble';
	}
	if ( 'garden' === $id ) {
		return 'logement';
	}
	return 'logement studio';
};
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
$pk_price_unit = ( 'saisonnier' === $pk_intent ) ? $pk_unit_sale : ( $pk_is_rent ? $pk_unit_rent : $pk_unit_sale );
$pk_fill = static function ( $min, $max, $cap ) {
	$span = max( 1, (int) $cap );
	$start = max( 0, min( 100, ( $min / $span ) * 100 ) );
	$width = max( 0, min( 100 - $start, ( ( $max - $min ) / $span ) * 100 ) );
	return 'left:' . round( $start, 2 ) . '%;width:' . round( $width, 2 ) . '%';
};

$pk_types = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY, 'hide_empty' => true ) );
$pk_types = is_wp_error( $pk_types ) ? array() : $pk_types;

$pk_amenities = array();
$pk_tx_now    = ( 'saisonnier' === $pk_intent ) ? 'saisonnier' : ( 'a-louer' === $pk_intent ? 'louer' : 'vente' );
$pk_family_now = $pk_family_of( $pk_type );
if ( class_exists( 'Partikulier_Amenities' ) ) {
	foreach ( Partikulier_Amenities::items() as $pk_item ) {
		if ( ! is_array( $pk_item ) || empty( $pk_item['id'] ) ) {
			continue;
		}
		$pk_item['pk_tx']    = $pk_tx_codes( $pk_item );
		$pk_item['pk_types'] = $pk_chip_types( $pk_item['id'] );
		$pk_amenities[]      = $pk_item;
	}
}
$pk_amenity_title = class_exists( 'Partikulier_Amenities' ) ? Partikulier_Amenities::copy( 'group' ) : $pk_t( 'Équipements' );
$pk_amenity_visible = false;
foreach ( $pk_amenities as $pk_item ) {
	if ( $pk_filter_ready && in_array( $pk_tx_now, (array) $pk_item['pk_tx'], true ) && false !== strpos( ' ' . $pk_item['pk_types'] . ' ', ' ' . $pk_family_now . ' ' ) ) {
		$pk_amenity_visible = true;
		break;
	}
}

$pk_filter_ready = ( '' !== $pk_type );
$pk_panel_open = $pk_filter_ready && ( $pk_price_min > 0 || $pk_price_max_val < $pk_price_cap || $pk_area_min > 0 || $pk_area_max_val < $pk_area_max || $pk_beds > 0 || $pk_baths > 0 || ! empty( $pk_selected_amenities ) );
$pk_clear = '' !== $pk_intent ? add_query_arg( 'pk_intent', $pk_intent, $archive ) : $archive;

$pk_city_placeholder = class_exists( 'Partikulier_Localization' )
	? Partikulier_Localization::translate_polylang_string( 'Ville, quartier…', 'Ville, quartier…', 'partikulier' )
	: 'Ville, quartier…';
$pk_season_label = ( 'fr' === $pk_find_lang ) ? 'Location saisonnière' : $pk_t( 'Saisonnière' );
?>
<form class="pk-search pk-search-<?php echo esc_attr( $variant ); ?> pk-find" action="<?php echo esc_url( $archive ); ?>" method="get" role="search" data-pk-live="<?php echo esc_attr( $variant ); ?>" aria-label="<?php echo esc_attr( $pk_t( 'Rechercher' ) ); ?>">
	<div class="pk-find-tabs" role="radiogroup" aria-label="<?php echo esc_attr( $pk_t( 'À vendre' ) . ' / ' . $pk_t( 'À louer' ) . ' / ' . $pk_season_label ); ?>">
		<input class="screen-reader-text pk-find-tab-input" type="radio" name="pk_intent" id="pk-find-sale" value="a-vendre" <?php checked( $pk_intent, 'a-vendre' ); ?>>
		<label class="pk-find-tab" for="pk-find-sale"><?php echo esc_html( $pk_t( 'À vendre' ) ); ?></label>
		<input class="screen-reader-text pk-find-tab-input" type="radio" name="pk_intent" id="pk-find-rent" value="a-louer" <?php checked( $pk_intent, 'a-louer' ); ?>>
		<label class="pk-find-tab" for="pk-find-rent"><?php echo esc_html( $pk_t( 'À louer' ) ); ?></label>
		<input class="screen-reader-text pk-find-tab-input" type="radio" name="pk_intent" id="pk-find-season" value="saisonnier" <?php checked( $pk_intent, 'saisonnier' ); ?>>
		<label class="pk-find-tab" for="pk-find-season"><?php echo esc_html( $pk_season_label ); ?></label>
	</div>
	<input type="hidden" name="es_action" value="<?php echo esc_attr( $pk_action_value ); ?>">
	<input type="hidden" name="pk_rent" value="<?php echo esc_attr( $pk_rent_value ); ?>">

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
					<option value="<?php echo esc_attr( $pk_term->slug ); ?>" data-family="<?php echo esc_attr( $pk_family_of( $pk_term->slug ) ); ?>" <?php selected( $pk_type, $pk_term->slug ); ?>><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $pk_term->name ) : $pk_term->name ); ?></option>
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
						<button type="button" class="pk-find-type-opt" role="option" data-value="<?php echo esc_attr( $pk_term->slug ); ?>" data-family="<?php echo esc_attr( $pk_family_of( $pk_term->slug ) ); ?>"><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $pk_term->name ) : $pk_term->name ); ?></button>
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

	<p class="pk-find-need" id="pk-find-need" role="alert" aria-live="assertive" hidden><?php echo esc_html( $pk_t( 'Veuillez vérifier les champs obligatoires.' ) ); ?></p>

	<div class="pk-find-panel" id="pk-find-panel" role="dialog" aria-modal="true" aria-labelledby="pk-find-pop-title">
		<div class="pk-find-panel-bar">
			<h2 class="pk-find-pop-title" id="pk-find-pop-title"><?php echo esc_html( $pk_t( 'Filtres' ) ); ?></h2>
			<button type="button" class="pk-find-close"><?php echo esc_html( $pk_t( 'Fermer' ) ); ?></button>
		</div>
		<div class="pk-find-tx" role="group" aria-label="<?php echo esc_attr( $pk_t( 'Type de transaction' ) ); ?>">
			<p class="pk-find-label"><?php echo esc_html( $pk_t( 'Type de transaction' ) ); ?></p>
			<div class="pk-find-tx-row">
				<?php foreach ( array( 'a-vendre' => 'Vente', 'a-louer' => 'Location', 'saisonnier' => 'Vacances' ) as $pk_tx_val => $pk_tx_label ) : ?>
					<button type="button" class="pk-find-tx-btn<?php echo $pk_intent === $pk_tx_val ? ' is-on' : ''; ?>" data-pk-intent="<?php echo esc_attr( $pk_tx_val ); ?>" aria-pressed="<?php echo $pk_intent === $pk_tx_val ? 'true' : 'false'; ?>"><?php echo esc_html( $pk_t( $pk_tx_label ) ); ?></button>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="pk-find-typegrid-wrap">
			<p class="pk-find-label"><?php echo esc_html( $pk_t( 'Type de bien' ) ); ?></p>
			<div class="pk-find-typegrid" role="listbox" aria-label="<?php echo esc_attr( $pk_t( 'Type de bien' ) ); ?>">
				<button type="button" class="pk-find-type-opt pk-find-type-card" data-value="" aria-selected="<?php echo '' === $pk_type ? 'true' : 'false'; ?>">
					<span class="pk-find-type-ico" aria-hidden="true"><svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" focusable="false"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg></span>
					<span><?php echo esc_html( $pk_t( 'Tous' ) ); ?></span>
				</button>
				<?php $pk_icon_rank = array(
					'appartement' => 1,
					'maison'      => 2,
					'villa'       => 3,
					'riad'        => 4,
					'depot'       => 5,
					'bureau'      => 6,
					'terrain'     => 7,
					'hangar'      => 8,
					'usine'       => 9,
					'studio'      => 10,
					'duplex'      => 11,
					'immeuble'    => 12,
				);
				$pk_type_cards = (array) $pk_types;
				usort(
					$pk_type_cards,
					static function ( $a, $b ) use ( $pk_icon_rank ) {
						$ra = ( $a instanceof WP_Term && isset( $pk_icon_rank[ $a->slug ] ) ) ? $pk_icon_rank[ $a->slug ] : 50;
						$rb = ( $b instanceof WP_Term && isset( $pk_icon_rank[ $b->slug ] ) ) ? $pk_icon_rank[ $b->slug ] : 50;
						return $ra <=> $rb;
					}
				);
				foreach ( $pk_type_cards as $pk_term ) : ?>
					<?php if ( ! $pk_term instanceof WP_Term ) { continue; } ?>
					<button type="button" class="pk-find-type-opt pk-find-type-card" data-value="<?php echo esc_attr( $pk_term->slug ); ?>" aria-selected="<?php echo selected( $pk_type, $pk_term->slug, false ) ? 'true' : 'false'; ?>">
						<span class="pk-find-type-ico" aria-hidden="true"><?php echo function_exists( 'pk_type_icon' ) ? pk_type_icon( $pk_term->slug ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
						<span><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $pk_term->name ) : $pk_term->name ); ?></span>
					</button>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="pk-find-split">
			<div class="pk-find-range" data-pk-range data-pk-price data-unit="<?php echo esc_attr( $pk_price_unit ); ?>" data-sale-max="<?php echo esc_attr( $pk_sale_max ); ?>" data-rent-max="<?php echo esc_attr( $pk_rent_max ); ?>" data-sale-step="<?php echo esc_attr( $pk_sale_step ); ?>" data-rent-step="<?php echo esc_attr( $pk_rent_step ); ?>" data-sale-unit="<?php echo esc_attr( $pk_unit_sale ); ?>" data-rent-unit="<?php echo esc_attr( $pk_unit_rent ); ?>" data-season-max="<?php echo esc_attr( $pk_rent_max ); ?>" data-season-step="<?php echo esc_attr( $pk_rent_step ); ?>" data-season-unit="<?php echo esc_attr( $pk_unit_sale ); ?>">
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

		<div class="pk-find-split pk-find-rooms"<?php echo ( $pk_filter_ready && in_array( $pk_family_now, array( 'logement', 'studio', 'immeuble' ), true ) ) ? '' : ' hidden'; ?>>
			<fieldset class="pk-find-pills" data-pk-field="bedrooms" data-pk-types="logement immeuble"<?php echo ( $pk_filter_ready && false !== strpos( ' logement immeuble ', ' ' . $pk_family_now . ' ' ) ) ? '' : ' hidden'; ?>>
				<legend><?php echo esc_html( $pk_t( 'Chambres' ) ); ?></legend>
				<input class="screen-reader-text" type="radio" name="es_bedrooms_min" value="" <?php checked( $pk_beds, 0 ); ?>>
				<?php foreach ( array( 1, 2, 3, 4 ) as $pk_n ) : ?>
					<label class="pk-find-pill">
						<input type="radio" name="es_bedrooms_min" value="<?php echo esc_attr( $pk_n ); ?>" <?php checked( $pk_beds, $pk_n ); ?>>
<span dir="ltr"><?php echo esc_html( 4 === $pk_n ? '+4' : (string) $pk_n ); ?></span>
						</label>
					<?php endforeach; ?>
				</fieldset>
			<fieldset class="pk-find-pills" data-pk-field="bathrooms" data-pk-types="logement studio immeuble"<?php echo ( $pk_filter_ready && false !== strpos( ' logement studio immeuble ', ' ' . $pk_family_now . ' ' ) ) ? '' : ' hidden'; ?>>
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
			<div class="pk-find-amenities"<?php echo $pk_amenity_visible ? '' : ' hidden'; ?>>
				<p class="pk-find-label"><?php echo esc_html( $pk_amenity_title ); ?></p>
				<div class="pk-find-chips">
					<?php foreach ( $pk_amenities as $pk_item ) : ?>
						<?php
						$pk_id = sanitize_key( $pk_item['id'] );
						$pk_chip_on = $pk_filter_ready && in_array( $pk_tx_now, (array) $pk_item['pk_tx'], true ) && false !== strpos( ' ' . $pk_item['pk_types'] . ' ', ' ' . $pk_family_now . ' ' );
						?>
						<label class="pk-find-chip" data-pk-tx="<?php echo esc_attr( implode( ' ', $pk_item['pk_tx'] ) ); ?>" data-pk-types="<?php echo esc_attr( $pk_item['pk_types'] ); ?>"<?php echo $pk_chip_on ? '' : ' hidden'; ?>>
							<input type="checkbox" name="pk_amenity[]" value="<?php echo esc_attr( $pk_id ); ?>" <?php checked( $pk_chip_on && in_array( $pk_id, $pk_selected_amenities, true ) ); ?>>
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

	</form>
