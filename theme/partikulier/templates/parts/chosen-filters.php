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

$pk_city_fr = '';
if ( '' !== $pk_city && class_exists( 'Partikulier_Morocco_Places' ) ) {
	$pk_city_term = get_term_by( 'slug', $pk_city, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
	$pk_city_try  = array( $pk_city );
	if ( $pk_city_term && ! is_wp_error( $pk_city_term ) ) {
		$pk_city_try[] = $pk_city_term->name;
	}
	$pk_city_try[] = str_replace( '-', ' ', preg_replace( '/-(?:ar|fr|en)$/', '', $pk_city ) );
	foreach ( Partikulier_Morocco_Places::reference() as $pk_ref_city => $pk_ref_districts ) {
		$pk_ref_norm = Partikulier_Morocco_Places::normalize( $pk_ref_city );
		foreach ( $pk_city_try as $pk_try ) {
			if ( Partikulier_Morocco_Places::normalize( $pk_try ) === $pk_ref_norm || sanitize_title( $pk_ref_city ) === sanitize_title( $pk_try ) ) {
				$pk_city_fr = $pk_ref_city;
				break 2;
			}
		}
	}
}
$pk_districts = ( '' !== $pk_city_fr && class_exists( 'Partikulier_Morocco_Places' ) ) ? Partikulier_Morocco_Places::districts_of( $pk_city_fr, '', 40 ) : array();
$pk_quartier  = $pk_get( 'pk_quartier' );
$pk_quartier_ok = '';
foreach ( $pk_districts as $pk_row ) {
	$pk_name = isset( $pk_row['district'] ) ? (string) $pk_row['district'] : '';
	if ( '' !== $pk_name && $pk_name === $pk_quartier ) {
		$pk_quartier_ok = $pk_name;
		break;
	}
}
if ( '' !== $pk_quartier_ok ) {
	$pk_q_label = class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::localized_place( $pk_quartier_ok, $pk_lang ) : $pk_quartier_ok;
	$pk_chips[] = array(
		'text' => $pk_q_label,
		'url'  => $pk_drop( 'pk_quartier' ),
	);
}

$pk_type_label = '';
if ( '' !== $pk_type && class_exists( 'Partikulier_Listing_I18n' ) ) {
	$pk_type_base  = preg_replace( '/-(?:fr|en|ar)$/', '', $pk_type );
	$pk_type_label = Partikulier_Listing_I18n::localized_type( str_replace( '-', ' ', $pk_type_base ), $pk_lang );
}
$pk_place = '';
if ( '' !== $pk_city_fr && class_exists( 'Partikulier_Listing_I18n' ) ) {
	$pk_place = Partikulier_Listing_I18n::localized_place( $pk_city_fr, $pk_lang );
}
$pk_tx_word = '';
if ( 'a-louer' === $pk_intent ) {
	$pk_tx_word = class_exists( 'Partikulier_Localization' )
		? Partikulier_Localization::translate_polylang_string( 'A louer', 'A louer', 'partikulier' )
		: 'à louer';
} elseif ( 'a-vendre' === $pk_intent ) {
	$pk_tx_word = ( 'fr' === $pk_lang ) ? 'à vendre' : $pk_say( 'Vente' );
} elseif ( 'saisonnier' === $pk_intent ) {
	$pk_tx_word = ( 'fr' === $pk_lang ) ? 'Location saisonnière' : $pk_say( 'Vacances' );
}
$pk_in = '';
if ( '' !== $pk_place ) {
	if ( 'ar' === $pk_lang ) {
		$pk_in = 'في ' . $pk_place;
	} elseif ( 'en' === $pk_lang ) {
		$pk_in = 'in ' . $pk_place;
	} else {
		$pk_in = 'à ' . $pk_place;
	}
}
$pk_beds_label = '';
if ( $pk_beds > 0 ) {
	if ( 'ar' === $pk_lang ) {
		$pk_beds_label = ( 1 === $pk_beds ) ? 'غرفة نوم واحدة' : sprintf( '%d غرف نوم', $pk_beds );
	} elseif ( 'en' === $pk_lang ) {
		$pk_beds_label = sprintf( 1 === $pk_beds ? '%d bedroom' : '%d bedrooms', $pk_beds );
	} else {
		$pk_beds_label = sprintf( 1 === $pk_beds ? '%d chambre' : '%d chambres', $pk_beds );
	}
}
$pk_title_bits = array();
foreach ( array( $pk_type_label, $pk_tx_word, $pk_in ) as $pk_bit ) {
	if ( '' !== $pk_bit ) {
		$pk_title_bits[] = $pk_bit;
	}
}
$pk_results_title = implode( ' ', $pk_title_bits );
$pk_sub_bits = array();
foreach ( array( $pk_type_label, $pk_beds_label, $pk_tx_word, $pk_in ) as $pk_bit ) {
	if ( '' !== $pk_bit ) {
		$pk_sub_bits[] = $pk_bit;
	}
}
$pk_results_sub = implode( ' ', $pk_sub_bits );
global $wp_query;
$pk_found = ( isset( $wp_query ) && $wp_query instanceof WP_Query ) ? (int) $wp_query->found_posts : 0;
$pk_found_txt = number_format( $pk_found, 0, ',', ' ' );
$pk_ads = $pk_say( 'annonces' );
$pk_quartier_kicker = ( 'fr' === $pk_lang ) ? 'Choisis ton quartier' : $pk_say( 'Choisissez un quartier' );
if ( ! isset( $pk_sort_button ) ) {
	$pk_sort_button = ( 'ar' === $pk_lang ) ? 'ترتيب' : $pk_say( 'Trier les annonces' );
}

$pk_clear = ( 'ar' === $pk_lang ) ? 'مسح الفلتر' : $pk_say( 'Réinitialiser' );
$pk_clear_url = $pk_drop( array( 's', 'es_city', 'es_type', 'es_action', 'pk_intent', 'pk_rent', 'es_price_min', 'es_price_max', 'es_size_min', 'es_size_max', 'es_bedrooms_min', 'pk_salons_min', 'pk_amenity', 'pk_furnished', 'pk_quartier' ) );
?>
<div class="pk-results-tools">
	<?php if ( '' !== $pk_results_title ) : ?>
	<div class="pk-results-copy">
		<h2 class="pk-results-title"><?php echo esc_html( $pk_results_title ); ?></h2>
		<p class="pk-results-meta">
			<?php if ( '' !== $pk_results_sub && $pk_results_sub !== $pk_results_title ) : ?>
				<span><?php echo esc_html( $pk_results_sub ); ?></span>
				<span aria-hidden="true"> • </span>
			<?php endif; ?>
			<span class="pk-results-count"><span dir="ltr"><?php echo esc_html( $pk_found_txt ); ?></span> <?php echo esc_html( $pk_ads ); ?></span>
		</p>
	</div>
	<?php endif; ?>
	<button type="button" class="pk-sort-inline" data-pk-open-sort>
		<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M8 7v10M8 7l-3 3M8 7l3 3M16 17V7M16 17l-3-3M16 17l3-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
		<span><?php echo esc_html( $pk_sort_button ); ?></span>
	</button>
</div>
<?php if ( $pk_chips ) : ?>
<div class="pk-chosen">
	<div class="pk-chosen-list">
		<?php foreach ( $pk_chips as $pk_chip ) : ?>
			<a class="pk-chosen-chip" href="<?php echo esc_url( $pk_chip['url'] ); ?>" aria-label="<?php echo esc_attr( $pk_chip['text'] . ' — ' . $pk_close ); ?>">
				<?php if ( ! empty( $pk_chip['html'] ) ) : ?>
					<?php echo $pk_chip['html']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- fragments échappés à la construction. ?>
				<?php else : ?>
					<span><?php echo esc_html( $pk_chip['text'] ); ?></span>
				<?php endif; ?>
				<span class="pk-chosen-x" aria-hidden="true">×</span>
			</a>
		<?php endforeach; ?>
	</div>
	<a class="pk-chosen-clear" href="<?php echo esc_url( $pk_clear_url ); ?>"><?php echo esc_html( $pk_clear ); ?></a>
</div>
<?php endif; ?>
<?php if ( $pk_districts ) : ?>
	<?php
	$pk_more = ( 'ar' === $pk_lang ) ? 'اعرض المزيد' : $pk_say( 'Tout voir' );
	$pk_less = ( 'ar' === $pk_lang ) ? 'اعرض أقل' : $pk_say( 'Fermer' );
	$pk_city_heading = '';
	if ( '' !== $pk_city_fr && class_exists( 'Partikulier_Listing_I18n' ) ) {
		$pk_city_heading = Partikulier_Listing_I18n::localized_place( $pk_city_fr, $pk_lang );
	} elseif ( '' !== $pk_city_fr ) {
		$pk_city_heading = $pk_city_fr;
	}
	$pk_quartier_links = array();
	foreach ( $pk_districts as $pk_row ) {
		$pk_name = isset( $pk_row['district'] ) ? (string) $pk_row['district'] : '';
		if ( '' === $pk_name ) {
			continue;
		}
		$pk_quartier_links[] = array(
			'label' => class_exists( 'Partikulier_Listing_I18n' ) ? Partikulier_Listing_I18n::localized_place( $pk_name, $pk_lang ) : $pk_name,
			'on'    => ( $pk_name === $pk_quartier_ok ),
			'href'  => ( $pk_name === $pk_quartier_ok ) ? $pk_drop( 'pk_quartier' ) : add_query_arg( 'pk_quartier', $pk_name, remove_query_arg( array( 'pk_quartier', 'paged' ) ) ),
		);
	}
	$pk_need_more = count( $pk_quartier_links ) > 4;
	?>
<p class="pk-quartiers-kicker"><?php echo esc_html( $pk_quartier_kicker ); ?></p>
<div class="pk-quartiers-box">
	<input class="pk-quartiers-open" type="checkbox" id="pk-quartiers-open"<?php echo $pk_need_more ? '' : ' disabled'; ?>>
	<nav class="pk-quartiers" aria-label="<?php echo esc_attr( $pk_city_heading ? $pk_city_heading : $pk_say( 'Ville' ) ); ?>">
		<?php foreach ( $pk_quartier_links as $pk_link ) : ?>
			<a class="pk-quartier<?php echo $pk_link['on'] ? ' is-on' : ''; ?>" href="<?php echo esc_url( $pk_link['href'] ); ?>"<?php echo $pk_link['on'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $pk_link['label'] ); ?></a>
		<?php endforeach; ?>
	</nav>
	<?php if ( $pk_need_more ) : ?>
		<label class="pk-quartiers-more" for="pk-quartiers-open">
			<span class="pk-more"><?php echo esc_html( $pk_more ); ?></span>
			<span class="pk-less"><?php echo esc_html( $pk_less ); ?></span>
		</label>
	<?php endif; ?>
	<?php if ( $pk_need_more ) : ?>
		<div class="pk-quartiers-sheet">
			<label class="pk-quartiers-backdrop" for="pk-quartiers-open" aria-label="<?php echo esc_attr( $pk_close ); ?>"></label>
			<div class="pk-quartiers-sheet-card" role="dialog" aria-modal="true" aria-label="<?php echo esc_attr( $pk_city_heading ? $pk_city_heading : $pk_more ); ?>">
				<div class="pk-quartiers-sheet-head">
					<?php if ( '' !== $pk_city_heading ) : ?>
						<h2><?php echo esc_html( $pk_city_heading ); ?></h2>
					<?php endif; ?>
					<label class="pk-quartiers-sheet-close" for="pk-quartiers-open" aria-label="<?php echo esc_attr( $pk_close ); ?>"><span aria-hidden="true">×</span></label>
				</div>
				<div class="pk-quartiers-sheet-list">
					<?php foreach ( $pk_quartier_links as $pk_link ) : ?>
						<a class="pk-quartier-row<?php echo $pk_link['on'] ? ' is-on' : ''; ?>" href="<?php echo esc_url( $pk_link['href'] ); ?>"<?php echo $pk_link['on'] ? ' aria-current="true"' : ''; ?>><?php echo esc_html( $pk_link['label'] ); ?></a>
					<?php endforeach; ?>
				</div>
				<label class="pk-quartiers-sheet-less" for="pk-quartiers-open"><?php echo esc_html( $pk_less ); ?></label>
			</div>
		</div>
	<?php endif; ?>
</div>
<?php endif; ?>
