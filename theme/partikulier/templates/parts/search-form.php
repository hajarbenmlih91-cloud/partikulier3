<?php
/**
 * Barre de recherche : trois onglets (vente, location, saisonnière).
 * Le panneau Filtres reprend le prix, la surface, les chambres,
 * les salles de bains et les équipements du formulaire. Charte pep.ma.
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
		'Ferme'               => array( 'en' => 'Farm', 'ar' => 'ضيعة' ),
		'Local commercial'    => array( 'en' => 'Commercial premises', 'ar' => 'محل تجاري' ),
		'Minimum'             => array( 'en' => 'Minimum' ),
		'Salons'              => array( 'en' => 'Living rooms', 'ar' => 'غرف الجلوس' ),
		'Maximum'             => array( 'en' => 'Maximum' ),
		'Indifférent'         => array( 'en' => 'No preference / Not specified', 'ar' => 'لا تفضيل / غير محدد' ),
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
	$raw = str_replace( array( ' ', "\xc2\xa0" ), '', $pk_scalar( $key ) );
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
	// Pastilles du formulaire, par type. Studio et duplex reprennent la fiche appartement.
	$map = array(
		'terrace'     => 'appartement studio duplex maison',
		'balcony'     => 'appartement studio duplex maison',
		'garage'      => 'appartement studio duplex maison villa local',
		'garage_m'    => 'appartement studio duplex maison villa local',
		'elevator'    => 'appartement studio duplex maison villa',
		'visavis'     => 'appartement studio duplex maison villa',
		'garden'      => 'appartement studio duplex maison villa',
		'sea'         => 'appartement studio duplex villa',
		'gym'         => 'appartement studio duplex maison villa',
		'pool'        => 'appartement studio duplex villa',
		'concierge'   => 'appartement studio duplex',
		'salon_ma'    => 'appartement studio duplex maison',
		'salon_eu'    => 'appartement studio duplex maison',
		'satellite'   => 'appartement studio duplex maison',
		'clim'        => 'appartement studio duplex maison villa local',
		'door'        => 'appartement studio duplex maison villa',
		'kitchen_fit' => 'maison villa',
		'fridge'      => 'appartement studio duplex maison',
		'oven'        => 'appartement studio duplex maison',
		'tv'          => 'appartement studio duplex maison',
		'washer'      => 'appartement studio duplex maison',
		'wifi'        => 'appartement studio duplex maison',
		'microwave'   => 'appartement studio duplex maison',
		'glazing'     => 'appartement studio duplex maison villa',
		'pool_view'   => 'appartement studio duplex',
		'mountain'    => 'appartement studio duplex villa',
		'forest'      => 'appartement studio duplex',
		'nature'      => 'appartement studio duplex villa',
		'dishwasher'  => 'appartement studio duplex maison',
	);
	return isset( $map[ $id ] ) ? $map[ $id ] : '';
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
$pk_salons = $pk_int( 'pk_salons_min' );
$pk_salon_heading = ( 'ar' === $pk_find_lang ) ? 'غرف الجلوس' : $pk_t( 'Salons' );
if ( ! in_array( $pk_beds, array( 0, 1, 2, 3, 4, 5 ), true ) ) {
	$pk_beds = 0;
}
if ( ! in_array( $pk_salons, array( 0, 1, 2, 3, 4 ), true ) ) {
	$pk_salons = 0;
}
$pk_furnished = ( 'meuble' === $pk_scalar( 'pk_furnished' ) ) ? 'meuble' : '';

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
$pk_has_ferme = false;
foreach ( $pk_types as $pk_term ) {
	if ( $pk_term instanceof WP_Term && 'ferme' === preg_replace( '/-(fr|en|ar)$/', '', $pk_term->slug ) ) {
		$pk_has_ferme = true;
		break;
	}
}
$pk_extra_types = array(
	'ferme' => 'Ferme',
	'local' => 'Local commercial',
);
foreach ( $pk_extra_types as $pk_extra_slug => $pk_extra_label ) {
	$pk_has_extra = false;
	foreach ( $pk_types as $pk_term ) {
		if ( $pk_term instanceof WP_Term && $pk_extra_slug === preg_replace( '/-(fr|en|ar)$/', '', $pk_term->slug ) ) {
			$pk_has_extra = true;
			break;
		}
	}
	if ( $pk_has_extra ) {
		continue;
	}
	$pk_types[] = new WP_Term(
		(object) array(
			'term_id'  => 0,
			'name'     => $pk_t( $pk_extra_label ),
			'slug'     => $pk_extra_slug,
			'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY,
		)
	);
}
$pk_type_rank = array(
	'appartement' => 1,
	'maison'      => 2,
	'villa'       => 3,
	'riad'        => 4,
	'local'       => 5,
	'bureau'      => 6,
	'terrain'     => 7,
	'ferme'       => 8,
	'depot'       => 9,
	'hangar'      => 10,
	'usine'       => 11,
	'studio'      => 12,
	'duplex'      => 13,
	'immeuble'    => 14,
);
usort(
	$pk_types,
	static function ( $a, $b ) use ( $pk_type_rank ) {
		$sa = ( $a instanceof WP_Term ) ? preg_replace( '/-(fr|en|ar)$/', '', $a->slug ) : '';
		$sb = ( $b instanceof WP_Term ) ? preg_replace( '/-(fr|en|ar)$/', '', $b->slug ) : '';
		$ra = isset( $pk_type_rank[ $sa ] ) ? $pk_type_rank[ $sa ] : 50;
		$rb = isset( $pk_type_rank[ $sb ] ) ? $pk_type_rank[ $sb ] : 50;
		return $ra <=> $rb;
	}
);
$pk_tx_attr = static function ( $slug ) {
	$slug = preg_replace( '/-(fr|en|ar)$/', '', sanitize_title( (string) $slug ) );
	return in_array( $slug, array( 'appartement', 'maison', 'villa', 'riad', 'studio', 'duplex' ), true ) ? 'vente louer saisonnier' : 'vente louer';
};
$pk_room_slugs = array( 'appartement', 'maison', 'villa', 'riad', 'studio', 'duplex' );
$pk_type_base  = preg_replace( '/-(fr|en|ar)$/', '', (string) $pk_type );
$pk_rooms_on   = ( '' === $pk_type_base || in_array( $pk_type_base, $pk_room_slugs, true ) );
$pk_sale_prices = array( 100000, 150000, 200000, 250000, 300000, 400000, 500000, 600000, 800000, 1000000, 1500000, 2000000, 2500000, 3000000, 4000000, 5000000, 7000000, 10000000, 15000000, 20000000 );
$pk_rent_prices = array( 1000, 1500, 2000, 2500, 3000, 4000, 5000, 6000, 8000, 10000, 12000, 15000, 20000, 25000, 30000, 40000 );
$pk_area_choices = array( 30, 40, 50, 60, 70, 80, 100, 120, 150, 200, 250, 300, 400, 500, 700, 1000, 1500, 2000, 3000 );
$pk_ensure_choice = static function ( $list, $value, $cap ) {
	$value = (int) $value;
	if ( $value > 0 && $value < (int) $cap && ! in_array( $value, $list, true ) ) {
		$list[] = $value;
		sort( $list, SORT_NUMERIC );
	}
	return $list;
};
$pk_price_max_sel = ( $pk_price_max_raw > 0 && $pk_price_max_raw < $pk_price_cap ) ? $pk_price_max_raw : 0;
$pk_area_max_sel  = ( $pk_area_max_raw > 0 && $pk_area_max_raw < $pk_area_max ) ? $pk_area_max_raw : 0;
$pk_price_choices = $pk_is_rent ? $pk_rent_prices : $pk_sale_prices;
$pk_price_choices = $pk_ensure_choice( $pk_price_choices, $pk_price_min, $pk_price_cap );
$pk_price_choices = $pk_ensure_choice( $pk_price_choices, $pk_price_max_sel, $pk_price_cap );
$pk_area_choices  = $pk_ensure_choice( $pk_area_choices, $pk_area_min, $pk_area_max );
$pk_area_choices  = $pk_ensure_choice( $pk_area_choices, $pk_area_max_sel, $pk_area_max );
$pk_meuble_label  = class_exists( 'Partikulier_Amenities' ) ? Partikulier_Amenities::furnish_label( 'meuble' ) : 'Meublé';

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
$pk_type_now = preg_replace( '/-(fr|en|ar)$/', '', sanitize_title( (string) $pk_type ) );
$pk_type_has = static function ( $types ) use ( $pk_type_now ) {
	return '' !== $pk_type_now && '' !== (string) $types && false !== strpos( ' ' . $types . ' ', ' ' . $pk_type_now . ' ' );
};
$pk_meuble_types = 'appartement studio duplex maison villa';
$pk_meuble_on    = ( 'louer' === $pk_tx_now ) && $pk_type_has( $pk_meuble_types );
$pk_amenity_visible = $pk_meuble_on;
foreach ( $pk_amenities as $pk_item ) {
	if ( in_array( $pk_tx_now, (array) $pk_item['pk_tx'], true ) && $pk_type_has( $pk_item['pk_types'] ) ) {
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
					<option value="<?php echo esc_attr( $pk_term->slug ); ?>" data-family="<?php echo esc_attr( $pk_family_of( $pk_term->slug ) ); ?>" data-pk-tx="<?php echo esc_attr( $pk_tx_attr( $pk_term->slug ) ); ?>" <?php selected( $pk_type, $pk_term->slug ); ?>><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $pk_term->name ) : $pk_term->name ); ?></option>
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
						<button type="button" class="pk-find-type-opt" role="option" data-value="<?php echo esc_attr( $pk_term->slug ); ?>" data-family="<?php echo esc_attr( $pk_family_of( $pk_term->slug ) ); ?>" data-pk-tx="<?php echo esc_attr( $pk_tx_attr( $pk_term->slug ) ); ?>"><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $pk_term->name ) : $pk_term->name ); ?></button>
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
		<button type="button" class="pk-find-close" aria-label="<?php echo esc_attr( $pk_t( 'Fermer' ) ); ?>">
			<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" d="M6 6l12 12M18 6L6 18"/></svg>
		</button>
		<div class="pk-find-panel-scroll">
			<div class="pk-find-panel-bar">
				<h2 class="pk-find-pop-title" id="pk-find-pop-title">
					<svg viewBox="0 0 24 24" width="18" height="18" aria-hidden="true" focusable="false"><path fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" d="M4 7h16M4 12h16M4 17h16"/><circle cx="9" cy="7" r="2.2" fill="currentColor"/><circle cx="15" cy="12" r="2.2" fill="currentColor"/><circle cx="8" cy="17" r="2.2" fill="currentColor"/></svg>
					<span><?php echo esc_html( $pk_t( 'Filtres' ) ); ?></span>
				</h2>
			</div>


			<div class="pk-find-block pk-find-tx">
				<h3><?php echo esc_html( $pk_t( 'Type de transaction' ) ); ?></h3>
				<div class="pk-find-tx-row" role="group" aria-label="<?php echo esc_attr( $pk_t( 'Type de transaction' ) ); ?>">
					<?php foreach ( array( 'a-vendre' => 'Vente', 'a-louer' => 'Location', 'saisonnier' => 'Vacances' ) as $pk_tx_val => $pk_tx_label ) : ?>
						<button type="button" class="pk-find-tx-btn<?php echo $pk_intent === $pk_tx_val ? ' is-on' : ''; ?>" data-pk-intent="<?php echo esc_attr( $pk_tx_val ); ?>" aria-pressed="<?php echo $pk_intent === $pk_tx_val ? 'true' : 'false'; ?>"><?php echo esc_html( $pk_t( $pk_tx_label ) ); ?></button>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="pk-find-block pk-find-typegrid-wrap">
				<h3><?php echo esc_html( $pk_t( 'Type de bien' ) ); ?></h3>
				<div class="pk-find-typegrid" role="listbox" aria-label="<?php echo esc_attr( $pk_t( 'Type de bien' ) ); ?>">
					<?php foreach ( (array) $pk_types as $pk_term ) : ?>
						<?php if ( ! $pk_term instanceof WP_Term ) { continue; } ?>
						<button type="button" class="pk-find-type-opt pk-find-type-card" data-value="<?php echo esc_attr( $pk_term->slug ); ?>" data-pk-tx="<?php echo esc_attr( $pk_tx_attr( $pk_term->slug ) ); ?>" aria-selected="<?php echo selected( $pk_type, $pk_term->slug, false ) ? 'true' : 'false'; ?>">
							<span class="pk-find-type-ico" aria-hidden="true"><?php echo function_exists( 'pk_type_icon' ) ? pk_type_icon( $pk_term->slug ) : ''; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?></span>
							<span><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $pk_term->name ) : $pk_term->name ); ?></span>
						</button>
					<?php endforeach; ?>
				</div>
			</div>
			<div class="pk-find-block" data-pk-price data-sale-max="<?php echo esc_attr( $pk_sale_max ); ?>" data-rent-max="<?php echo esc_attr( $pk_rent_max ); ?>" data-sale-prices="<?php echo esc_attr( implode( ',', $pk_sale_prices ) ); ?>" data-rent-prices="<?php echo esc_attr( implode( ',', $pk_rent_prices ) ); ?>" data-sale-unit="<?php echo esc_attr( $pk_unit_sale ); ?>" data-rent-unit="<?php echo esc_attr( $pk_unit_rent ); ?>" data-season-unit="<?php echo esc_attr( $pk_unit_sale ); ?>" data-any="<?php echo esc_attr( $pk_t( 'Indifférent' ) ); ?>">
				<h3><?php echo esc_html( $pk_t( 'Prix' ) ); ?></h3>
				<div class="pk-find-duo">
					<label>
						<span><?php echo esc_html( $pk_t( 'Minimum' ) ); ?></span>
						<select class="pk-find-select" name="es_price_min" data-pk-min dir="ltr">
							<option value=""><?php echo esc_html( $pk_t( 'Indifférent' ) ); ?></option>
							<?php foreach ( $pk_price_choices as $pk_n ) : ?>
								<option value="<?php echo esc_attr( $pk_n ); ?>" <?php selected( $pk_price_min, $pk_n ); ?>><?php echo esc_html( $pk_fmt( $pk_n ) . ' ' . $pk_price_unit ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span><?php echo esc_html( $pk_t( 'Maximum' ) ); ?></span>
						<select class="pk-find-select" name="es_price_max" data-pk-max dir="ltr">
							<option value=""><?php echo esc_html( $pk_t( 'Indifférent' ) ); ?></option>
							<?php foreach ( $pk_price_choices as $pk_n ) : ?>
								<option value="<?php echo esc_attr( $pk_n ); ?>" <?php selected( $pk_price_max_sel, $pk_n ); ?>><?php echo esc_html( $pk_fmt( $pk_n ) . ' ' . $pk_price_unit ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
			</div>

			<div class="pk-find-block" data-pk-area>
				<h3><?php echo esc_html( $pk_t( 'Surface' ) ); ?></h3>
				<div class="pk-find-duo">
					<label>
						<span><?php echo esc_html( $pk_t( 'Minimum' ) ); ?></span>
						<select class="pk-find-select" name="es_size_min" data-pk-min dir="ltr">
							<option value=""><?php echo esc_html( $pk_t( 'Indifférent' ) ); ?></option>
							<?php foreach ( $pk_area_choices as $pk_n ) : ?>
								<option value="<?php echo esc_attr( $pk_n ); ?>" <?php selected( $pk_area_min, $pk_n ); ?>><?php echo esc_html( $pk_fmt( $pk_n ) . ' m²' ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
					<label>
						<span><?php echo esc_html( $pk_t( 'Maximum' ) ); ?></span>
						<select class="pk-find-select" name="es_size_max" dir="ltr">
							<option value=""><?php echo esc_html( $pk_t( 'Indifférent' ) ); ?></option>
							<?php foreach ( $pk_area_choices as $pk_n ) : ?>
								<option value="<?php echo esc_attr( $pk_n ); ?>" <?php selected( $pk_area_max_sel, $pk_n ); ?>><?php echo esc_html( $pk_fmt( $pk_n ) . ' m²' ); ?></option>
							<?php endforeach; ?>
						</select>
					</label>
				</div>
			</div>

			<div class="pk-find-block" data-pk-rooms data-housing="<?php echo esc_attr( implode( ' ', $pk_room_slugs ) ); ?>"<?php echo $pk_rooms_on ? '' : ' hidden'; ?>>
				<h3><?php echo esc_html( $pk_t( 'Chambres' ) ); ?></h3>
				<div class="pk-find-seg" role="radiogroup" aria-label="<?php echo esc_attr( $pk_t( 'Chambres' ) ); ?>">
					<?php foreach ( array( 1, 2, 3, 4, 5 ) as $pk_n ) : ?>
						<label>
							<input type="radio" name="es_bedrooms_min" value="<?php echo esc_attr( $pk_n ); ?>" <?php checked( $pk_beds, $pk_n ); ?><?php echo $pk_beds === $pk_n ? ' data-was="1"' : ''; ?>>
							<span dir="ltr"><?php echo esc_html( $pk_n . '+' ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
				<h3 class="pk-find-next"><?php echo esc_html( $pk_salon_heading ); ?></h3>
				<div class="pk-find-seg" role="radiogroup" aria-label="<?php echo esc_attr( $pk_salon_heading ); ?>">
					<?php foreach ( array( 1, 2, 3, 4 ) as $pk_n ) : ?>
						<label>
							<input type="radio" name="pk_salons_min" value="<?php echo esc_attr( $pk_n ); ?>" <?php checked( $pk_salons, $pk_n ); ?><?php echo $pk_salons === $pk_n ? ' data-was="1"' : ''; ?>>
							<span dir="ltr"><?php echo esc_html( $pk_n . '+' ); ?></span>
						</label>
					<?php endforeach; ?>
				</div>
			</div>

			<?php if ( $pk_amenities || class_exists( 'Partikulier_Amenities' ) ) : ?>
				<div class="pk-find-amenities"<?php echo $pk_amenity_visible ? '' : ' hidden'; ?>>
					<h3><?php echo esc_html( $pk_amenity_title ); ?></h3>
					<div class="pk-find-checks">
						<?php foreach ( $pk_amenities as $pk_item ) : ?>
							<?php
							$pk_id      = sanitize_key( $pk_item['id'] );
							$pk_chip_on = in_array( $pk_tx_now, (array) $pk_item['pk_tx'], true ) && $pk_type_has( $pk_item['pk_types'] );
							?>
							<label class="pk-find-chip pk-find-check" data-pk-tx="<?php echo esc_attr( implode( ' ', $pk_item['pk_tx'] ) ); ?>" data-pk-types="<?php echo esc_attr( $pk_item['pk_types'] ); ?>"<?php echo $pk_chip_on ? '' : ' hidden'; ?>>
								<?php
							$pk_default_checks = array( 'terrace', 'elevator' );
							$pk_box_checked    = in_array( $pk_id, $pk_selected_amenities, true );
							if ( ! isset( $_GET['pk_amenity'] ) && '' === $pk_scalar( 'es_type' ) && in_array( $pk_id, $pk_default_checks, true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
								$pk_box_checked = true;
							}
							?>
							<input type="checkbox" name="pk_amenity[]" value="<?php echo esc_attr( $pk_id ); ?>" <?php checked( $pk_box_checked ); ?><?php echo $pk_box_checked && in_array( $pk_id, $pk_default_checks, true ) ? ' data-pk-default="1"' : ''; ?><?php echo $pk_chip_on ? '' : ' disabled'; ?>>
								<span><?php echo esc_html( Partikulier_Amenities::label( $pk_item ) ); ?></span>
							</label>
						<?php endforeach; ?>
						<label class="pk-find-chip pk-find-check" data-pk-tx="louer" data-pk-types="<?php echo esc_attr( $pk_meuble_types ); ?>"<?php echo $pk_meuble_on ? '' : ' hidden'; ?>>
							<input type="checkbox" name="pk_furnished" value="meuble" <?php checked( $pk_meuble_on && 'meuble' === $pk_furnished ); ?>>
							<span><?php echo esc_html( $pk_meuble_label ); ?></span>
						</label>
					</div>
				</div>
			<?php endif; ?>

			<div class="pk-find-foot">
				<?php
				$pk_count_params = array();
				if ( '' !== $pk_intent ) {
					$pk_count_params['pk_intent'] = $pk_intent;
				}
				if ( '' !== $pk_action_value ) {
					$pk_count_params['es_action'] = $pk_action_value;
				}
				if ( '' !== $pk_rent_value ) {
					$pk_count_params['pk_rent'] = $pk_rent_value;
				}
				if ( '' !== $pk_city_slug ) {
					$pk_count_params['es_city'] = $pk_city_slug;
				}
				if ( '' !== $pk_type ) {
					$pk_count_params['es_type'] = $pk_type;
				}
				if ( $pk_price_min > 0 ) {
					$pk_count_params['es_price_min'] = (string) $pk_price_min;
				}
				if ( $pk_price_max_raw > 0 && $pk_price_max_raw < $pk_price_cap ) {
					$pk_count_params['es_price_max'] = (string) $pk_price_max_raw;
				}
				if ( $pk_area_min > 0 ) {
					$pk_count_params['es_size_min'] = (string) $pk_area_min;
				}
				if ( $pk_area_max_raw > 0 && $pk_area_max_raw < $pk_area_max ) {
					$pk_count_params['es_size_max'] = (string) $pk_area_max_raw;
				}
				if ( $pk_beds > 0 ) {
					$pk_count_params['es_bedrooms_min'] = (string) $pk_beds;
				}
				if ( $pk_salons > 0 ) {
					$pk_count_params['pk_salons_min'] = (string) $pk_salons;
				}
				if ( 'meuble' === $pk_furnished ) {
					$pk_count_params['pk_furnished'] = 'meuble';
				}
				if ( $pk_selected_amenities ) {
					$pk_count_params['pk_amenity'] = $pk_selected_amenities;
				}
				$pk_count_n = class_exists( 'Partikulier_Search_Filters' ) ? Partikulier_Search_Filters::matching_count( $pk_count_params ) : 0;
				$pk_count_verb = 'Afficher';
				$pk_count_one  = 'annonce';
				$pk_count_many = 'annonces';
				if ( 'fr' !== $pk_find_lang ) {
					$pk_count_verb = $pk_t( 'Voir les résultats' );
					$pk_count_one  = '';
					$pk_count_many = '';
				}
				$pk_count_noun = ( 1 === (int) $pk_count_n ) ? $pk_count_one : $pk_count_many;
				$pk_count_num  = $pk_fmt( $pk_count_n );
				?>
				<button type="submit" class="pk-find-results" data-pk-count="<?php echo esc_attr( (string) $pk_count_n ); ?>" data-verb="<?php echo esc_attr( $pk_count_verb ); ?>" data-one="<?php echo esc_attr( $pk_count_one ); ?>" data-many="<?php echo esc_attr( $pk_count_many ); ?>">
					<span class="pk-find-results-verb"><?php echo esc_html( $pk_count_verb ); ?></span>
					<span class="pk-find-results-num" dir="ltr"><?php echo esc_html( $pk_count_num ); ?></span>
					<span class="pk-find-results-noun"<?php echo '' === $pk_count_noun ? ' hidden' : ''; ?>><?php echo esc_html( $pk_count_noun ); ?></span>
				</button>
			</div>
		</div>
	</div>

	</form>
