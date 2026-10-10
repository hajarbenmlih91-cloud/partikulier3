<?php
/**
 * Pastilles d'équipements : libellés FR/EN/AR et visibilité par type de bien.
 * Une option, un bloc de formulaire. Les quatre pastilles d'origine écrivent
 * encore les metas Oui/Non lues par la fiche.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-amenities-context.php';

class Partikulier_Amenities {

	use Partikulier_Amenities_Context;

	const OPTION = 'pk_amenities';

	public static function defaults() {
		$base = array(
			array( 'id' => 'terrace', 'fr' => 'Terrasse', 'en' => 'Terrace', 'ar' => 'تراس', 'legacy' => 'pk_terrace', 'follow' => 'surface', 'ctx' => array( 'vente', 'vide' ) ),
			array( 'id' => 'garage', 'fr' => 'Garage ou sous-sol', 'en' => 'Garage or basement', 'ar' => 'مرآب أو قبو', 'legacy' => 'pk_garage', 'ctx' => array( 'vente', 'vide' ) ),
			array( 'id' => 'elevator', 'fr' => 'Ascenseur', 'en' => 'Lift', 'ar' => 'مصعد', 'legacy' => 'pk_elevator', 'ctx' => array( 'vente', 'vide', 'meuble' ) ),
			array( 'id' => 'visavis', 'fr' => 'Sans vis-à-vis', 'en' => 'No overlooking neighbours', 'ar' => 'بدون إطلالة مقابلة', 'legacy' => 'pk_vis_a_vis', 'ctx' => array( 'vente', 'vide' ) ),
			array( 'id' => 'balcony', 'fr' => 'Balcon', 'en' => 'Balcony', 'ar' => 'شرفة', 'ctx' => array( 'vente', 'vide' ) ),
			array( 'id' => 'kitchen', 'fr' => 'Équipements de cuisine', 'en' => 'Kitchen appliances', 'ar' => 'أجهزة المطبخ', 'ctx' => array( 'vente', 'vide' ) ),
			array( 'id' => 'garden', 'fr' => 'Jardin privatif', 'en' => 'Private garden', 'ar' => 'حديقة خاصة', 'ctx' => array( 'vente', 'vide' ) ),
			array( 'id' => 'hvac', 'fr' => 'Chauffage et climatisation centrale', 'en' => 'Central heating and air conditioning', 'ar' => 'تدفئة وتكييف مركزي', 'ctx' => array( 'vente', 'vide' ) ),
			array( 'id' => 'security', 'fr' => 'Sécurité', 'en' => 'Security', 'ar' => 'أمن', 'ctx' => array( 'vente', 'vide', 'meuble' ) ),
			array( 'id' => 'sea', 'fr' => 'Vue mer', 'en' => 'Sea view', 'ar' => 'إطلالة على البحر', 'ctx' => array( 'vente', 'vide' ) ),
			array( 'id' => 'gym', 'fr' => 'Salle de sport', 'en' => 'Gym', 'ar' => 'قاعة رياضة', 'ctx' => array( 'vente', 'vide' ) ),
			array( 'id' => 'pool', 'fr' => 'Piscine', 'en' => 'Swimming pool', 'ar' => 'مسبح', 'ctx' => array( 'vente', 'vide', 'meuble' ) ),
			array( 'id' => 'elec', 'fr' => 'Compteur électrique', 'en' => 'Electricity meter', 'ar' => 'عداد الكهرباء', 'ctx' => array( 'vente', 'vide' ) ),
			array( 'id' => 'water', 'fr' => 'Compteur d’eau', 'en' => 'Water meter', 'ar' => 'عداد الماء', 'ctx' => array( 'vente', 'vide' ) ),
		);
		return array_merge( $base, self::meuble_items() );
	}

	public static function default_copy() {
		return array(
			'group' => array( 'fr' => 'Équipements', 'en' => 'Amenities', 'ar' => 'التجهيزات' ),
			'hint'  => array(
				'fr' => 'Cochez ce que le bien possède. Le reste compte comme « non ».',
				'en' => 'Tick what the property has. The rest counts as “no”.',
				'ar' => 'حدّد ما يتوفر في العقار. الباقي يُحسب « لا ».',
			),
		);
	}

	public static function config() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$brut = get_option( self::OPTION, array() );
		if ( ! is_array( $brut ) || empty( $brut['items'] ) || ! is_array( $brut['items'] ) ) {
			$copy = self::default_copy();
			$cache = array( 'items' => self::defaults(), 'types' => array(), 'group' => $copy['group'], 'hint' => $copy['hint'] );
			return $cache;
		}
		$cache = $brut;
		return $cache;
	}

	public static function items() {
		$config = self::config();
		$items  = array();
		$vus    = array();
		foreach ( $config['items'] as $item ) {
			if ( is_array( $item ) && ! empty( $item['id'] ) ) {
				$item               = self::with_context( $item );
				$items[]            = $item;
				$vus[ $item['id'] ] = true;
			}
		}
		if ( empty( $config['ctx_ready'] ) ) {
			foreach ( self::meuble_items() as $item ) {
				if ( ! isset( $vus[ $item['id'] ] ) ) {
					$items[] = $item;
				}
			}
		}
		return $items;
	}

	public static function lang() {
		return class_exists( 'Partikulier_Settings' ) ? Partikulier_Settings::current_language() : 'fr';
	}

	public static function label( $item, $lang = '' ) {
		$lang = $lang ? $lang : self::lang();
		if ( ! in_array( $lang, array( 'fr', 'en', 'ar' ), true ) ) {
			$lang = 'fr';
		}
		$texte = isset( $item[ $lang ] ) ? trim( (string) $item[ $lang ] ) : '';
		return '' !== $texte ? $texte : (string) ( $item['fr'] ?? '' );
	}

	public static function copy( $cle ) {
		$config = self::config();
		$base   = self::default_copy();
		$lang   = self::lang();
		$bloc   = isset( $config[ $cle ] ) && is_array( $config[ $cle ] ) ? $config[ $cle ] : $base[ $cle ];
		$texte  = isset( $bloc[ $lang ] ) ? trim( (string) $bloc[ $lang ] ) : '';
		return '' !== $texte ? $texte : (string) $base[ $cle ]['fr'];
	}

	public static function type_groups() {
		static $cache = null;
		if ( null !== $cache ) {
			return $cache;
		}
		$groupes = array();
		if ( ! taxonomy_exists( 'es_type' ) ) {
			$cache = $groupes;
			return $cache;
		}
		$termes = get_terms( array( 'taxonomy' => 'es_type', 'hide_empty' => false, 'lang' => 'all' ) );
		if ( is_wp_error( $termes ) || ! is_array( $termes ) ) {
			$cache = $groupes;
			return $cache;
		}
		$vus = array();
		foreach ( $termes as $terme ) {
			$ids = array( (int) $terme->term_id );
			if ( function_exists( 'pll_get_term_translations' ) ) {
				$trad = pll_get_term_translations( $terme->term_id );
				if ( is_array( $trad ) && $trad ) {
					$ids = array_map( 'intval', $trad );
				}
			}
			sort( $ids );
			$cle = 't' . implode( '-', $ids );
			if ( isset( $vus[ $cle ] ) ) {
				continue;
			}
			$vus[ $cle ] = true;
			$slugs       = array();
			$libelle     = $terme->name;
			foreach ( $ids as $id ) {
				$t = get_term( $id, 'es_type' );
				if ( ! $t || is_wp_error( $t ) ) {
					continue;
				}
				$slugs[] = $t->slug;
				if ( function_exists( 'pll_get_term_language' ) && 'fr' === pll_get_term_language( $id ) ) {
					$libelle = $t->name;
				}
			}
			$groupes[] = array( 'key' => $cle, 'label' => $libelle, 'slugs' => array_values( array_unique( $slugs ) ) );
		}
		$cache = $groupes;
		return $cache;
	}

	public static function group_key_for_slug( $slug ) {
		foreach ( self::type_groups() as $groupe ) {
			if ( in_array( $slug, $groupe['slugs'], true ) ) {
				return $groupe['key'];
			}
		}
		return sanitize_title( (string) $slug );
	}

	public static function for_slug( $slug ) {
		$config = self::config();
		$cle    = self::group_key_for_slug( $slug );
		$regle  = isset( $config['types'] ) && is_array( $config['types'] ) && array_key_exists( $cle, $config['types'] );
		$liste  = $regle ? (array) $config['types'][ $cle ] : array();
		$sortie = array();
		foreach ( self::items() as $item ) {
			if ( $regle && ! in_array( $item['id'], $liste, true ) ) {
				continue;
			}
			if ( ! empty( $item['legacy'] ) && class_exists( 'Partikulier_Deposit_Form' ) && ! Partikulier_Deposit_Form::is_visible( $item['legacy'], $slug ) ) {
				continue;
			}
			$sortie[] = $item;
		}
		return $sortie;
	}

	public static function slugs_for( $item ) {
		$slugs = array();
		foreach ( self::type_groups() as $groupe ) {
			foreach ( $groupe['slugs'] as $slug ) {
				foreach ( self::for_slug( $slug ) as $visible ) {
					if ( $visible['id'] === $item['id'] ) {
						$slugs[] = $slug;
						break;
					}
				}
			}
		}
		return array_values( array_unique( $slugs ) );
	}

	public static function legacy_map() {
		$map = array();
		foreach ( self::defaults() as $item ) {
			if ( ! empty( $item['legacy'] ) ) {
				$map[ $item['id'] ] = $item['legacy'];
			}
		}
		return $map;
	}

	public static function slug_from_request( $data ) {
		$type_id = isset( $data['pk_type'] ) ? absint( $data['pk_type'] ) : 0;
		if ( $type_id && taxonomy_exists( 'es_type' ) ) {
			$terme = get_term( $type_id, 'es_type' );
			if ( $terme && ! is_wp_error( $terme ) ) {
				return (string) $terme->slug;
			}
		}
		return isset( $data['pk_type_slug'] ) ? sanitize_title( wp_unslash( $data['pk_type_slug'] ) ) : '';
	}

	public static function posted_ids( $data ) {
		if ( ! isset( $data['pk_amenities_present'] ) ) {
			return null;
		}
		$brut    = isset( $data['pk_amenities'] ) ? (array) $data['pk_amenities'] : array();
		$allowed = array();
		$ctx     = self::context_from_request( $data );
		foreach ( self::for_slug( self::slug_from_request( $data ) ) as $item ) {
			if ( self::item_allows( $item, $ctx ) ) {
				$allowed[ $item['id'] ] = true;
			}
		}
		$ids = array();
		foreach ( $brut as $id ) {
			$id = sanitize_key( is_array( $id ) ? '' : $id );
			if ( isset( $allowed[ $id ] ) ) {
				$ids[] = $id;
			}
		}
		return array_values( array_unique( $ids ) );
	}

	public static function apply_to_request( $data ) {
		$ids = self::posted_ids( $data );
		if ( null === $ids ) {
			return array( $data, null );
		}
		$slug   = self::slug_from_request( $data );
		$champs = array();
		foreach ( self::legacy_map() as $id => $champ ) {
			$champs[ $champ ] = ! empty( $champs[ $champ ] ) || in_array( $id, $ids, true );
		}
		foreach ( $champs as $champ => $oui ) {
			if ( $oui ) {
				$data[ $champ ] = 'Oui';
				continue;
			}
			$visible = ! class_exists( 'Partikulier_Deposit_Form' ) || Partikulier_Deposit_Form::is_visible( $champ, $slug );
			if ( $visible ) {
				$data[ $champ ] = 'Non';
			} else {
				unset( $data[ $champ ] );
			}
		}
		return array( $data, $ids );
	}

	public static function extra_labels( $post_id ) {
		$ids = get_post_meta( $post_id, '_pk_amenities', true );
		if ( ! is_array( $ids ) ) {
			$ids  = array();
			$deja = array();
			foreach ( self::legacy_map() as $id => $champ ) {
				if ( isset( $deja[ $champ ] ) ) {
					continue;
				}
				if ( 'Oui' === get_post_meta( $post_id, '_' . $champ, true ) ) {
					$ids[]          = $id;
					$deja[ $champ ] = true;
				}
			}
		}
		$sortie = array();
		foreach ( self::items() as $item ) {
			if ( in_array( $item['id'], array( 'terrace', 'visavis' ), true ) ) {
				continue;
			}
			if ( in_array( $item['id'], $ids, true ) ) {
				$sortie[] = self::label( $item );
			}
		}
		return $sortie;
	}

	public static function save( $lignes, $textes, $types, $connus ) {
		$items  = array();
		$vus    = array();
		$legacy = self::legacy_map();
		foreach ( (array) $lignes as $ligne ) {
			if ( ! is_array( $ligne ) || ! empty( $ligne['drop'] ) ) {
				continue;
			}
			$id = sanitize_key( isset( $ligne['id'] ) ? $ligne['id'] : '' );
			if ( '' === $id ) {
				$id = sanitize_title( isset( $ligne['fr'] ) ? $ligne['fr'] : '' );
			}
			$fr = sanitize_text_field( isset( $ligne['fr'] ) ? $ligne['fr'] : '' );
			if ( '' === $id || isset( $vus[ $id ] ) || '' === $fr ) {
				continue;
			}
			$item = array(
				'id' => $id,
				'fr' => mb_substr( $fr, 0, 80 ),
				'en' => mb_substr( sanitize_text_field( isset( $ligne['en'] ) ? $ligne['en'] : '' ), 0, 80 ),
				'ar' => mb_substr( sanitize_text_field( isset( $ligne['ar'] ) ? $ligne['ar'] : '' ), 0, 80 ),
			);
			if ( isset( $legacy[ $id ] ) ) {
				$item['legacy'] = $legacy[ $id ];
			}
			if ( 'terrace' === $id ) {
				$item['follow'] = 'surface';
			}
			$ctx = array();
			foreach ( (array) ( isset( $ligne['ctx'] ) ? $ligne['ctx'] : array() ) as $code ) {
				$code = sanitize_key( $code );
				if ( in_array( $code, self::context_keys(), true ) ) {
					$ctx[] = $code;
				}
			}
			$item['ctx'] = $ctx ? $ctx : array( 'vente', 'vide', 'meuble' );
			$vus[ $id ] = true;
			$items[]    = $item;
			if ( count( $items ) >= 40 ) {
				break;
			}
		}
		if ( ! $items ) {
			return new WP_Error( 'vide', __( 'Gardez au moins une pastille.', 'partikulier' ) );
		}
		$propre = array();
		foreach ( (array) $types as $cle => $liste ) {
			$cle = sanitize_key( $cle );
			if ( '' === $cle ) {
				continue;
			}
			$propre[ $cle ] = array();
			foreach ( (array) $liste as $id ) {
				$id = sanitize_key( $id );
				if ( isset( $vus[ $id ] ) ) {
					$propre[ $cle ][] = $id;
				}
			}
		}
		foreach ( $items as $item ) {
			if ( in_array( $item['id'], (array) $connus, true ) ) {
				continue;
			}
			foreach ( $propre as $cle => $liste ) {
				$propre[ $cle ][] = $item['id'];
			}
		}
		$copy = self::default_copy();
		foreach ( array( 'group', 'hint' ) as $cle ) {
			foreach ( array( 'fr', 'en', 'ar' ) as $lang ) {
				$val = isset( $textes[ $cle ][ $lang ] ) ? sanitize_text_field( $textes[ $cle ][ $lang ] ) : '';
				$copy[ $cle ][ $lang ] = '' !== $val ? mb_substr( $val, 0, 160 ) : $copy[ $cle ][ $lang ];
			}
		}
		$furnish = self::default_furnish();
		foreach ( $furnish as $cle => $langs ) {
			foreach ( array( 'fr', 'en', 'ar' ) as $lang ) {
				$val = isset( $textes['furnish'][ $cle ][ $lang ] ) ? sanitize_text_field( $textes['furnish'][ $cle ][ $lang ] ) : '';
				if ( '' !== $val ) {
					$furnish[ $cle ][ $lang ] = mb_substr( $val, 0, 160 );
				}
			}
		}
		update_option( self::OPTION, array( 'items' => $items, 'types' => $propre, 'group' => $copy['group'], 'hint' => $copy['hint'], 'furnish' => $furnish, 'ctx_ready' => 1, 'maj' => current_time( 'mysql' ) ) );
		return true;
	}

	public static function reset() {
		delete_option( self::OPTION );
	}

	public static function init() {
		if ( is_admin() ) {
			require_once __DIR__ . '/class-amenities-admin.php';
			Partikulier_Amenities_Admin::init();
		}
	}
}

Partikulier_Amenities::init();
