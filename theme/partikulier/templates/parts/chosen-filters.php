<?php
/**
 * Critères choisis, retirables, avant les annonces.
 *
 * @package Partikulier
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pk_lang = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
$pk_say  = static function ( $fr ) use ( $pk_lang ) {
	if ( class_exists( 'Partikulier_Localization' ) && method_exists( 'Partikulier_Localization', 'form_translations' ) ) {
		$map = Partikulier_Localization::form_translations();
		if ( isset( $map[ $fr ][ $pk_lang ] ) && '' !== (string) $map[ $fr ][ $pk_lang ] ) {
			return (string) $map[ $fr ][ $pk_lang ];
		}
	}
	$extra = array(
		'Vente'     => array( 'en' => 'For sale', 'ar' => 'للبيع' ),
		'Location'  => array( 'en' => 'For rent', 'ar' => 'للكراء' ),
		'Vacances'  => array( 'en' => 'Seasonal', 'ar' => 'موسمية' ),
		'Prix'      => array( 'en' => 'Price', 'ar' => 'السعر' ),
		'Minimum'   => array( 'en' => 'Minimum' ),
		'Maximum'   => array( 'en' => 'Maximum' ),
		'Réinitialiser' => array( 'en' => 'Reset', 'ar' => 'إعادة ضبط' ),
	);
	if ( isset( $extra[ $fr ][ $pk_lang ] ) ) {
		return $extra[ $fr ][ $pk_lang ];
	}
	if ( class_exists( 'Partikulier_Localization' ) ) {
		return Partikulier_Localization::translate_polylang_string( $fr, $fr, 'partikulier' );
	}
	return $fr;
};
$pk_get = static function ( $key ) {
	if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '';
	}
	return sanitize_text_field( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
};
$pk_int = static function ( $key ) use ( $pk_get ) {
	$raw = str_replace( array( ' ', "\xc2\xa0" ), '', $pk_get( $key ) );
	return ( '' !== $raw && preg_match( '/^\d+$/', $raw ) ) ? (int) $raw : 0;
};
$pk_drop = static function ( $keys ) {
	$keys   = array_values( array_unique( array_merge( (array) $keys, array( 'paged' ) ) ) );
	return remove_query_arg( $keys );
};
$pk_money = static function ( $n ) {
	return number_format( (int) $n, 0, ',', ' ' );
};
$pk_unit = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::currency_for_language( '' ) : 'DH';
$pk_area = ( 'ar' === $pk_lang ) ? 'م²' : 'm²';
$pk_close = class_exists( 'Partikulier_Localization' )
	? Partikulier_Localization::translate_polylang_string( 'Fermer', 'Fermer', 'partikulier' )
	: 'Fermer';

$pk_chips = array();

$pk_city = sanitize_title( $pk_get( 'es_city' ) );
if ( '' !== $pk_city ) {
	$pk_term = get_term_by( 'slug', $pk_city, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
	$pk_name = ( $pk_term && ! is_wp_error( $pk_term ) ) ? $pk_term->name : $pk_city;
	if ( class_exists( 'Partikulier_Localization' ) ) {
		$pk_name = Partikulier_Localization::translate_taxonomy_label( $pk_name );
	}
	$pk_chips[] = array(
		'text' => $pk_name,
		'url'  => $pk_drop( array( 'es_city', 's' ) ),
	);
} elseif ( '' !== $pk_get( 's' ) ) {
	$pk_chips[] = array(
		'text' => $pk_get( 's' ),
		'url'  => $pk_drop( 's' ),
	);
}

$pk_type = sanitize_title( $pk_get( 'es_type' ) );
if ( '' !== $pk_type ) {
	$pk_term = get_term_by( 'slug', $pk_type, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
	$pk_name = ( $pk_term && ! is_wp_error( $pk_term ) ) ? $pk_term->name : $pk_type;
	if ( class_exists( 'Partikulier_Localization' ) ) {
		$pk_name = Partikulier_Localization::translate_taxonomy_label( $pk_name );
	}
	$pk_chips[] = array(
		'text' => $pk_name,
		'url'  => $pk_drop( 'es_type' ),
	);
}

$pk_intent = '';
if ( '' !== $pk_get( 'pk_intent' ) ) {
	$pk_intent = sanitize_key( $pk_get( 'pk_intent' ) );
} elseif ( 'saisonnier' === sanitize_key( $pk_get( 'pk_rent' ) ) ) {
	$pk_intent = 'saisonnier';
} elseif ( '' !== $pk_get( 'es_action' ) ) {
	$pk_intent = sanitize_key( $pk_get( 'es_action' ) );
}
$pk_intent_labels = array(
	'a-vendre'   => 'Vente',
	'a-louer'    => 'Location',
	'saisonnier' => 'Vacances',
);
if ( isset( $pk_intent_labels[ $pk_intent ] ) && ( isset( $_GET['pk_intent'] ) || isset( $_GET['es_action'] ) || isset( $_GET['pk_rent'] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	$pk_chips[] = array(
		'text' => $pk_say( $pk_intent_labels[ $pk_intent ] ),
		'url'  => $pk_drop( array( 'pk_intent', 'es_action', 'pk_rent' ) ),
	);
}

$pk_price_min = $pk_int( 'es_price_min' );
if ( $pk_price_min > 0 ) {
	$pk_chips[] = array(
		'html' => ( 'ar' === $pk_lang )
			? '<span>' . esc_html( $pk_say( 'Prix' ) ) . '</span> <span dir="ltr">≥ ' . esc_html( $pk_money( $pk_price_min ) ) . ' ' . esc_html( $pk_unit ) . '</span>'
			: '<span>' . esc_html( $pk_say( 'Minimum' ) ) . '</span> <span dir="ltr">' . esc_html( $pk_money( $pk_price_min ) ) . ' ' . esc_html( $pk_unit ) . '</span>',
		'text' => $pk_say( 'Prix' ),
		'url'  => $pk_drop( 'es_price_min' ),
	);
}
$pk_price_max = $pk_int( 'es_price_max' );
if ( $pk_price_max > 0 ) {
	$pk_chips[] = array(
		'html' => ( 'ar' === $pk_lang )
			? '<span>' . esc_html( $pk_say( 'Prix' ) ) . '</span> <span dir="ltr">≤ ' . esc_html( $pk_money( $pk_price_max ) ) . ' ' . esc_html( $pk_unit ) . '</span>'
			: '<span>' . esc_html( $pk_say( 'Maximum' ) ) . '</span> <span dir="ltr">' . esc_html( $pk_money( $pk_price_max ) ) . ' ' . esc_html( $pk_unit ) . '</span>',
		'text' => $pk_say( 'Prix' ),
		'url'  => $pk_drop( 'es_price_max' ),
	);
}

$pk_size_min = $pk_int( 'es_size_min' );
if ( $pk_size_min > 0 ) {
	$pk_chips[] = array(
		'html' => '<span>' . esc_html( $pk_say( 'Surface' ) ) . '</span> <span dir="ltr">≥ ' . esc_html( $pk_money( $pk_size_min ) ) . ' ' . esc_html( $pk_area ) . '</span>',
		'text' => $pk_say( 'Surface' ),
		'url'  => $pk_drop( 'es_size_min' ),
	);
}
$pk_size_max = $pk_int( 'es_size_max' );
if ( $pk_size_max > 0 ) {
	$pk_chips[] = array(
		'html' => '<span>' . esc_html( $pk_say( 'Surface' ) ) . '</span> <span dir="ltr">≤ ' . esc_html( $pk_money( $pk_size_max ) ) . ' ' . esc_html( $pk_area ) . '</span>',
		'text' => $pk_say( 'Surface' ),
		'url'  => $pk_drop( 'es_size_max' ),
	);
}

$pk_beds = $pk_int( 'es_bedrooms_min' );
if ( $pk_beds > 0 ) {
	$pk_chips[] = array(
		'html' => '<span>' . esc_html( $pk_say( 'Chambres' ) ) . '</span> <span dir="ltr">' . esc_html( (string) $pk_beds ) . '+</span>',
		'text' => $pk_say( 'Chambres' ),
		'url'  => $pk_drop( 'es_bedrooms_min' ),
	);
}
$pk_salons = $pk_int( 'pk_salons_min' );
if ( $pk_salons > 0 ) {
	$pk_salon_word = ( 'ar' === $pk_lang ) ? 'غرف الجلوس' : $pk_say( 'Salons' );
	$pk_chips[] = array(
		'html' => '<span>' . esc_html( $pk_salon_word ) . '</span> <span dir="ltr">' . esc_html( (string) $pk_salons ) . '+</span>',
		'text' => $pk_salon_word,
		'url'  => $pk_drop( 'pk_salons_min' ),
	);
}

$pk_amenity_ids = array();
if ( isset( $_GET['pk_amenity'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	foreach ( (array) wp_unslash( $_GET['pk_amenity'] ) as $pk_raw ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$pk_id = sanitize_key( is_scalar( $pk_raw ) ? (string) $pk_raw : '' );
		if ( '' !== $pk_id ) {
			$pk_amenity_ids[] = $pk_id;
		}
	}
}
if ( $pk_amenity_ids && class_exists( 'Partikulier_Amenities' ) ) {
	$pk_by_id = array();
	foreach ( (array) Partikulier_Amenities::items() as $pk_item ) {
		if ( is_array( $pk_item ) && ! empty( $pk_item['id'] ) ) {
			$pk_by_id[ $pk_item['id'] ] = $pk_item;
		}
	}
	foreach ( $pk_amenity_ids as $pk_id ) {
		if ( ! isset( $pk_by_id[ $pk_id ] ) ) {
			continue;
		}
		$pk_keep = array();
		foreach ( $pk_amenity_ids as $pk_other ) {
			if ( $pk_other !== $pk_id ) {
				$pk_keep[] = $pk_other;
			}
		}
		$pk_url = remove_query_arg( array( 'pk_amenity', 'paged' ) );
		if ( $pk_keep ) {
			$pk_url = add_query_arg( 'pk_amenity', $pk_keep, $pk_url );
		}
		$pk_chips[] = array(
			'text' => Partikulier_Amenities::label( $pk_by_id[ $pk_id ] ),
			'url'  => $pk_url,
		);
	}
}

if ( 'meuble' === sanitize_key( $pk_get( 'pk_furnished' ) ) ) {
	$pk_furnished = 'Meublé';
	if ( class_exists( 'Partikulier_Amenities' ) && method_exists( 'Partikulier_Amenities', 'furnish_label' ) ) {
		$pk_furnished = Partikulier_Amenities::furnish_label( 'meuble' );
	}
	$pk_chips[] = array(
		'text' => $pk_furnished,
		'url'  => $pk_drop( 'pk_furnished' ),
	);
}

if ( ! $pk_chips ) {
	return;
}

$pk_clear = ( 'ar' === $pk_lang ) ? 'مسح الفلتر' : $pk_say( 'Réinitialiser' );
$pk_clear_url = $pk_drop( array( 's', 'es_city', 'es_type', 'es_action', 'pk_intent', 'pk_rent', 'es_price_min', 'es_price_max', 'es_size_min', 'es_size_max', 'es_bedrooms_min', 'pk_salons_min', 'pk_amenity', 'pk_furnished' ) );
?>
<div class="pk-chosen">
	<div class="pk-chosen-list">
		<?php foreach ( $pk_chips as $pk_chip ) : ?>
			<a class="pk-chosen-chip" href="<?php echo esc_url( $pk_chip['url'] ); ?>">
				<?php if ( ! empty( $pk_chip['html'] ) ) : ?>
					<?php echo $pk_chip['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fragments échappés à la construction. ?>
				<?php else : ?>
					<span><?php echo esc_html( $pk_chip['text'] ); ?></span>
				<?php endif; ?>
				<span class="pk-chosen-x" aria-hidden="true">×</span>
				<span class="screen-reader-text"><?php echo esc_html( $pk_close ); ?></span>
			</a>
		<?php endforeach; ?>
	</div>
	<a class="pk-chosen-clear" href="<?php echo esc_url( $pk_clear_url ); ?>"><?php echo esc_html( $pk_clear ); ?></a>
</div>
