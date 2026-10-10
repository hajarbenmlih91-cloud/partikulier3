<?php
/**
 * Pastilles propres à la location meublée et à la location saisonnière.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

trait Partikulier_Amenities_Context {

	public static function default_furnish() {
		return array(
			'title'  => array( 'fr' => 'Ameublement', 'en' => 'Furnishing', 'ar' => 'التأثيث' ),
			'meuble' => array( 'fr' => 'Meublé', 'en' => 'Furnished', 'ar' => 'مفروش' ),
			'vide'   => array( 'fr' => 'Vide', 'en' => 'Unfurnished', 'ar' => 'غير مفروش' ),
			'note'   => array(
				'fr' => 'Une location saisonnière est toujours meublée.',
				'en' => 'A seasonal rental is always furnished.',
				'ar' => 'الإيجار الموسمي يكون مفروشاً دائماً.',
			),
			'error'  => array(
				'fr' => 'Indiquez si le bien est meublé ou vide.',
				'en' => 'Say whether the property is furnished or unfurnished.',
				'ar' => 'حدّد إن كان العقار مفروشاً أو فارغاً.',
			),
		);
	}

	public static function furnish_label( $key ) {
		$base = self::default_furnish();
		if ( ! isset( $base[ $key ] ) ) {
			return '';
		}
		$config = self::config();
		$bloc   = isset( $config['furnish'][ $key ] ) && is_array( $config['furnish'][ $key ] ) ? $config['furnish'][ $key ] : $base[ $key ];
		$lang   = self::lang();
		$texte  = isset( $bloc[ $lang ] ) ? trim( (string) $bloc[ $lang ] ) : '';
		return '' !== $texte ? $texte : (string) $base[ $key ]['fr'];
	}

	public static function meuble_items() {
		return array(
			array( 'id' => 'garage_m', 'fr' => 'Garage', 'en' => 'Garage', 'ar' => 'مرآب', 'legacy' => 'pk_garage', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'concierge', 'fr' => 'Concierge', 'en' => 'Concierge', 'ar' => 'حارس العقار', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'facade', 'fr' => 'Façade extérieure', 'en' => 'Exterior façade', 'ar' => 'واجهة خارجية', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'salon_ma', 'fr' => 'Salon Marocain', 'en' => 'Moroccan lounge', 'ar' => 'صالون مغربي', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'salon_eu', 'fr' => 'Salon européen', 'en' => 'European lounge', 'ar' => 'صالون أوروبي', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'satellite', 'fr' => 'Antenne parabolique', 'en' => 'Satellite dish', 'ar' => 'طبق لاقط', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'clim', 'fr' => 'Climatisation', 'en' => 'Air conditioning', 'ar' => 'تكييف', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'door', 'fr' => 'Porte blindée', 'en' => 'Security door', 'ar' => 'باب مصفح', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'kitchen_fit', 'fr' => 'Cuisine équipée', 'en' => 'Fitted kitchen', 'ar' => 'مطبخ مجهز', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'fridge', 'fr' => 'Réfrigérateur', 'en' => 'Refrigerator', 'ar' => 'ثلاجة', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'oven', 'fr' => 'Four', 'en' => 'Oven', 'ar' => 'فرن', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'tv', 'fr' => 'TV', 'en' => 'TV', 'ar' => 'تلفاز', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'washer', 'fr' => 'Machine à laver', 'en' => 'Washing machine', 'ar' => 'غسالة', 'ctx' => array( 'meuble' ) ),
			array( 'id' => 'wifi', 'fr' => 'WIFI', 'en' => 'Wi-Fi', 'ar' => 'واي فاي', 'ctx' => array( 'meuble' ) ),
		);
	}

	public static function meuble_order() {
		return array(
			'garage_m' => 10, 'elevator' => 20, 'pool' => 30, 'concierge' => 40, 'facade' => 50,
			'salon_ma' => 60, 'salon_eu' => 70, 'satellite' => 80, 'clim' => 90, 'security' => 100,
			'door' => 110, 'kitchen_fit' => 120, 'fridge' => 130, 'oven' => 140, 'tv' => 150,
			'washer' => 160, 'wifi' => 170,
		);
	}

	public static function with_context( $item ) {
		if ( ! empty( $item['ctx'] ) || empty( $item['id'] ) ) {
			return $item;
		}
		foreach ( self::defaults() as $def ) {
			if ( $def['id'] === $item['id'] && ! empty( $def['ctx'] ) ) {
				$item['ctx'] = $def['ctx'];
				return $item;
			}
		}
		return $item;
	}

	public static function context_keys() {
		return array( 'vente', 'vide', 'meuble' );
	}

	public static function context_from_request( $data ) {
		if ( ! isset( $data['pk_rent_present'] ) ) {
			return '';
		}
		$tx = isset( $data['pk_transaction'] ) ? sanitize_key( wp_unslash( $data['pk_transaction'] ) ) : '';
		if ( '' === $tx && isset( $data['pk_action_mode'] ) ) {
			$tx = sanitize_key( wp_unslash( $data['pk_action_mode'] ) );
		}
		if ( 'louer' !== $tx ) {
			return 'vente';
		}
		$kind = isset( $data['pk_rent_kind'] ) ? sanitize_key( wp_unslash( $data['pk_rent_kind'] ) ) : 'longue';
		if ( 'saisonnier' === $kind ) {
			return 'meuble';
		}
		$furn = isset( $data['pk_furnished'] ) ? sanitize_key( wp_unslash( $data['pk_furnished'] ) ) : '';
		return 'meuble' === $furn ? 'meuble' : 'vide';
	}

	public static function item_allows( $item, $ctx ) {
		if ( '' === $ctx ) {
			return true;
		}
		$liste = isset( $item['ctx'] ) ? (array) $item['ctx'] : array();
		return ! $liste || in_array( $ctx, $liste, true );
	}
}
