<?php
/**
 * Module : composition automatique du titre et de la description d'annonce.
 *
 * Reproduit l'etape « Votre apercu » du parcours de depot : a partir des
 * reponses du formulaire, on redige une phrase de titre et un paragraphe de
 * description que l'annonceur peut relire, puis modifier avant validation.
 *
 * Aucune ecriture en base ici : ce module ne fait que produire du texte.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Listing_Preview {

	/**
	 * Action AJAX de generation de l'apercu.
	 */
	const AJAX_ACTION = 'pk_listing_preview';

	public static function init() {
		add_action( 'wp_ajax_' . self::AJAX_ACTION, array( __CLASS__, 'handle_preview' ) );
		add_action( 'wp_ajax_nopriv_' . self::AJAX_ACTION, array( __CLASS__, 'handle_preview' ) );
	}

	/**
	 * Nettoie et normalise les donnees utiles a la redaction.
	 *
	 * @param array $data Donnees brutes du formulaire.
	 * @return array
	 */
	public static function normalize_input( $data ) {
		$get = static function ( $key, $default = '' ) use ( $data ) {
			return isset( $data[ $key ] ) ? sanitize_text_field( wp_unslash( $data[ $key ] ) ) : $default;
		};

		$type_id = absint( $get( 'pk_type', 0 ) );
		$type    = '';
		if ( $type_id ) {
			$term = get_term( $type_id, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
			$type = ( $term && ! is_wp_error( $term ) ) ? $term->name : '';
		}
		if ( '' === $type ) {
			$type = $get( 'pk_type_label', __( 'Bien', 'partikulier' ) );
		}

		// Un lieu propose (en attente de validation) alimente aussi l'apercu :
		// l'annonceur doit voir le texte exact que produira son annonce.
		$city     = $get( 'pk_city_name' );
		$district = $get( 'pk_district_name' );
		if ( '' === $city && '' !== $get( 'pk_proposed_city' ) ) {
			$city = $get( 'pk_proposed_city' );
		}
		if ( '' === $district && '' !== $get( 'pk_proposed_district' ) ) {
			$district = $get( 'pk_proposed_district' );
		}

		return array(
			'action'          => 'louer' === $get( 'pk_action_mode' ) ? 'louer' : 'vendre',
			'role'            => 'proprietaire',
			'type'            => $type,
			'city'            => $city,
			'district'        => $district,
			'surface'         => absint( $get( 'pk_surface', 0 ) ),
			'price'           => (int) preg_replace( '/\D+/', '', $get( 'pk_price', '' ) ),
			'bedrooms'        => $get( 'pk_bedrooms' ),
			'living_rooms'    => $get( 'pk_living_rooms' ),
			'bathrooms'       => $get( 'pk_bathrooms' ),
			'floor'           => $get( 'pk_floor' ),
			'garage'          => 'Oui' === $get( 'pk_garage' ) ? 'Oui' : 'Non',
			'elevator'        => 'Oui' === $get( 'pk_elevator' ) ? 'Oui' : 'Non',
			'vis_a_vis'       => 'Oui' === $get( 'pk_vis_a_vis' ) ? 'Oui' : 'Non',
			'terrace'         => 'Oui' === $get( 'pk_terrace' ) ? 'Oui' : 'Non',
			'terrace_surface' => absint( $get( 'pk_terrace_surface', 0 ) ),
			'sunshine'        => $get( 'pk_sunshine' ),
		);
	}

	/**
	 * Libelle lisible du lieu : « Quartier, Ville ».
	 *
	 * @param array $v Donnees normalisees.
	 * @return string
	 */
	public static function place_label( $v ) {
		$parts = array_filter( array( $v['district'], $v['city'] ) );

		return implode( ', ', $parts );
	}

	/**
	 * Libelle du couchage : « Studio », « 2 chambres + salon »...
	 *
	 * @param array $v Donnees normalisees.
	 * @return string
	 */
	private static function rooms_label( $v ) {
		if ( 'studio' === strtolower( $v['type'] ) || '0' === (string) $v['bedrooms'] ) {
			return __( 'Studio', 'partikulier' );
		}

		if ( '' === (string) $v['bedrooms'] ) {
			return '';
		}

		if ( '3+' === (string) $v['bedrooms'] ) {
			return __( '3 chambres + salon ou plus', 'partikulier' );
		}

		$label = sprintf(
			/* translators: %d: nombre de chambres. */
			_n( '%d chambre', '%d chambres', (int) $v['bedrooms'], 'partikulier' ),
			(int) $v['bedrooms']
		);

		if ( '' !== (string) $v['living_rooms'] && '0' !== (string) $v['living_rooms'] ) {
			$label .= __( ' + salon', 'partikulier' );
		}

		return $label;
	}

	/**
	 * Compose le titre de l'annonce.
	 *
	 * @param array $v Donnees normalisees.
	 * @return string
	 */
	public static function build_title( $v ) {
		$is_studio = 'studio' === strtolower( $v['type'] ) || '0' === (string) $v['bedrooms'];
		$title     = $is_studio ? __( 'Studio', 'partikulier' ) : $v['type'];

		if ( $v['surface'] ) {
			/* translators: %d: superficie en metres carres. */
			$title .= ' ' . sprintf( __( 'de %d m²', 'partikulier' ), $v['surface'] );
		}

		if ( 'Oui' === $v['vis_a_vis'] ) {
			$title .= __( ' sans vis-à-vis', 'partikulier' );
		}

		if ( 'Oui' === $v['terrace'] ) {
			$title .= __( ' avec terrasse', 'partikulier' );
			if ( $v['terrace_surface'] ) {
				/* translators: %d: superficie de la terrasse. */
				$title .= ' ' . sprintf( __( 'de %d m²', 'partikulier' ), $v['terrace_surface'] );
			}
		}

		$title .= 'louer' === $v['action'] ? __( ' à louer', 'partikulier' ) : __( ' à vendre', 'partikulier' );

		$place = self::place_label( $v );
		if ( '' !== $place ) {
			/* translators: %s: quartier et ville. */
			$title .= ' ' . sprintf( __( 'à %s', 'partikulier' ), $place );
		}

		return trim( preg_replace( '/\s+/u', ' ', $title ) );
	}

	/**
	 * Compose la description de l'annonce.
	 *
	 * @param array $v Donnees normalisees.
	 * @return string
	 */
	public static function build_description( $v ) {
		$is_studio = 'studio' === strtolower( $v['type'] ) || '0' === (string) $v['bedrooms'];
		$sentence  = $is_studio ? __( 'Studio', 'partikulier' ) : $v['type'];

		// Composition interieure.
		$rooms = array();
		if ( $is_studio ) {
			$rooms[] = __( 'une pièce principale', 'partikulier' );
		} else {
			$label = self::rooms_label( $v );
			if ( '' !== $label ) {
				$rooms[] = mb_strtolower( $label );
			}
		}

		if ( '' !== (string) $v['bathrooms'] ) {
			$count   = '3+' === (string) $v['bathrooms'] ? 3 : (int) $v['bathrooms'];
			$rooms[] = sprintf(
				/* translators: %d: nombre de salles de bains. */
				_n( '%d salle de bains', '%d salles de bains', $count, 'partikulier' ),
				$count
			);
		}

		if ( $rooms ) {
			/* translators: %s: composition du bien. */
			$sentence .= ' ' . sprintf( __( 'avec %s', 'partikulier' ), implode( __( ' et ', 'partikulier' ), $rooms ) );
		}

		if ( 'Oui' === $v['terrace'] ) {
			$sentence .= __( ' avec terrasse', 'partikulier' );
			if ( $v['terrace_surface'] ) {
				/* translators: %d: superficie de la terrasse. */
				$sentence .= ' ' . sprintf( __( 'de %d m²', 'partikulier' ), $v['terrace_surface'] );
			}
		}

		$sentence .= 'louer' === $v['action'] ? __( ' à louer', 'partikulier' ) : __( ' à vendre', 'partikulier' );

		$place = self::place_label( $v );
		if ( '' !== $place ) {
			/* translators: %s: quartier et ville. */
			$sentence .= ' ' . sprintf( __( 'à %s', 'partikulier' ), $place );
		}

		$details = array();
		if ( $v['surface'] ) {
			/* translators: %d: superficie. */
			$details[] = sprintf( __( 'd’une superficie de %d m²', 'partikulier' ), $v['surface'] );
		}
		if ( $v['price'] ) {
			/* translators: %s: prix formate. */
			$details[] = sprintf( __( 'au prix de %s MAD', 'partikulier' ), number_format_i18n( $v['price'] ) );
		}
		if ( '' !== $v['floor'] ) {
			$details[] = sprintf(
				/* translators: %s: etage. */
				__( 'situé %s', 'partikulier' ),
				'RDC' === $v['floor'] ? __( 'au rdc', 'partikulier' ) : sprintf( __( 'au %s', 'partikulier' ), mb_strtolower( $v['floor'] ) )
			);
		}
		$details[] = 'Oui' === $v['garage'] ? __( 'avec garage ou sous-sol', 'partikulier' ) : __( 'sans garage', 'partikulier' );
		$details[] = 'Oui' === $v['elevator'] ? __( 'avec ascenseur', 'partikulier' ) : __( 'sans ascenseur', 'partikulier' );
		if ( 'Oui' === $v['vis_a_vis'] ) {
			$details[] = __( 'sans vis-à-vis', 'partikulier' );
		}
		if ( '' !== $v['sunshine'] ) {
			$details[] = mb_strtolower( $v['sunshine'] );
		}

		// Phrase d'accroche placee EN TETE : « Propriétaire vend studio... ».
		// Formule deterministe liee au role reel de l'annonceur, jamais aleatoire.
		$opener = '';
		$place_text = self::place_label( $v );
		if ( '' !== $place_text ) {
			$who  = __( 'Propriétaire', 'partikulier' );
			$verb = 'louer' === $v['action'] ? __( 'loue', 'partikulier' ) : __( 'vend', 'partikulier' );
			$what = mb_strtolower( $is_studio ? __( 'studio', 'partikulier' ) : $v['type'] );

			$opener = sprintf(
				/* translators: 1: propriétaire, 2: vend ou loue, 3: type de bien, 4: superficie, 5: lieu. */
				__( '%1$s %2$s %3$s%4$s à %5$s.', 'partikulier' ),
				$who,
				$verb,
				$what,
				$v['surface'] ? ' ' . sprintf( __( 'de %d m²', 'partikulier' ), $v['surface'] ) : '',
				$place_text
			);
		}

		$description = ( '' !== $opener ? $opener . ' ' : '' ) . $sentence;
		if ( $details ) {
			$description .= ', ' . implode( ', ', $details );
		}
		$description .= '.';

		// Phrase de cloture : capte « particulier à particulier », sans mentir
		// (l'annonceur est toujours le proprietaire du bien).
		if ( '' !== $place_text ) {
			$transaction = 'louer' === $v['action'] ? __( 'à la location', 'partikulier' ) : __( 'à la vente', 'partikulier' );
			$description .= ' ' . sprintf(
				/* translators: 1: transaction, 2: lieu, 3: le propriétaire. */
				__( 'Bien de particulier à particulier proposé %1$s à %2$s, en contact direct avec %3$s, sans commission ni intermédiaire.', 'partikulier' ),
				$transaction,
				$place_text,
				__( 'le propriétaire', 'partikulier' )
			);
		}

		return trim( preg_replace( '/\s+/u', ' ', $description ) );
	}

	/**
	 * Meta description : version courte, calibree pour Google (<= 160 caracteres).
	 *
	 * @param array $v Donnees normalisees.
	 * @return string
	 */
	public static function build_meta_description( $v ) {
		$is_studio = 'studio' === strtolower( $v['type'] ) || '0' === (string) $v['bedrooms'];
		$type      = $is_studio ? __( 'Studio', 'partikulier' ) : $v['type'];
		$place     = self::place_label( $v );

		// --- Bloc prioritaire : doit tenir dans les 120 premiers caracteres,
		// c'est tout ce que le mobile affiche (~680 px).
		$who  = __( 'Propriétaire', 'partikulier' );
		$verb = 'louer' === $v['action'] ? __( 'loue', 'partikulier' ) : __( 'vend', 'partikulier' );

		$core = $who . ' ' . $verb . ' ' . mb_strtolower( $type );
		if ( $v['surface'] ) {
			$core .= ' ' . $v['surface'] . ' m²';
		}
		$rooms = self::rooms_label( $v );
		if ( '' !== $rooms && ! $is_studio ) {
			$core .= ', ' . mb_strtolower( $rooms );
		}
		if ( '' !== $place ) {
			/* translators: %s: lieu. */
			$core .= ' ' . sprintf( __( 'à %s', 'partikulier' ), $place );
		}
		if ( $v['price'] ) {
			/* translators: %s: prix. */
			$core .= '. ' . sprintf( __( '%s MAD', 'partikulier' ), number_format_i18n( $v['price'] ) );
		}
		$core .= '.';

		// --- Complements, ajoutes tant que le budget desktop le permet.
		$extras = array();
		if ( 'Oui' === $v['terrace'] ) {
			$extras[] = __( 'Terrasse', 'partikulier' );
		}
		if ( 'Oui' === $v['vis_a_vis'] ) {
			$extras[] = __( 'sans vis-à-vis', 'partikulier' );
		}
		if ( 'Oui' === $v['garage'] ) {
			$extras[] = __( 'garage', 'partikulier' );
		}
		if ( 'Oui' === $v['elevator'] ) {
			$extras[] = __( 'ascenseur', 'partikulier' );
		}

		$meta  = $core;
		$limit = 155;

		if ( $extras ) {
			$candidate = $meta . ' ' . ucfirst( implode( ', ', $extras ) ) . '.';
			if ( mb_strlen( $candidate ) <= $limit ) {
				$meta = $candidate;
			}
		}

		// Argument de conversion, seulement s'il reste de la place.
		$closing = __( 'Contact direct, sans commission.', 'partikulier' );
		if ( mb_strlen( $meta . ' ' . $closing ) <= $limit ) {
			$meta .= ' ' . $closing;
		} else {
			$short = __( 'Sans commission.', 'partikulier' );
			if ( mb_strlen( $meta . ' ' . $short ) <= $limit ) {
				$meta .= ' ' . $short;
			}
		}

		// Filet de securite : on coupe proprement au dernier mot entier.
		if ( mb_strlen( $meta ) > $limit + 3 ) {
			$meta = mb_substr( $meta, 0, $limit );
			$cut  = mb_strrpos( $meta, ' ' );
			if ( false !== $cut ) {
				$meta = mb_substr( $meta, 0, $cut );
			}
			$meta = rtrim( $meta, " ,.;:" ) . '…';
		}

		return $meta;
	}

	/**
	 * Texte alternatif d'une photo : decrit le bien et son emplacement.
	 *
	 * @param array $v     Donnees normalisees.
	 * @param int   $index Rang de la photo (0 = principale).
	 * @return string
	 */
	public static function build_image_alt( $v, $index = 0 ) {
		$is_studio  = 'studio' === strtolower( $v['type'] ) || '0' === (string) $v['bedrooms'];
		$type       = $is_studio ? __( 'Studio', 'partikulier' ) : $v['type'];
		$place      = self::place_label( $v );
		$transaction = 'louer' === $v['action'] ? __( 'à louer', 'partikulier' ) : __( 'à vendre', 'partikulier' );

		// --- Photo principale : la plus riche. C'est elle que Google Images
		// associe a l'annonce, elle merite le descriptif complet.
		if ( 0 === $index ) {
			$who = __( 'propriétaire', 'partikulier' );
			$alt = $type;
			if ( $v['surface'] ) {
				/* translators: %d: superficie. */
				$alt .= ' ' . sprintf( __( 'de %d m²', 'partikulier' ), $v['surface'] );
			}
			$rooms = self::rooms_label( $v );
			if ( '' !== $rooms && ! $is_studio ) {
				$alt .= ', ' . mb_strtolower( $rooms );
			}
			$alt .= ' ' . $transaction;
			if ( '' !== $place ) {
				/* translators: %s: lieu. */
				$alt .= ' ' . sprintf( __( 'à %s', 'partikulier' ), $place );
			}
			/* translators: %s: propriétaire. */
			$alt .= ' — ' . sprintf( __( 'annonce de %s, sans commission', 'partikulier' ), $who );

			// Un alt trop long est ignore par les lecteurs d'ecran et suspect
			// pour Google : on plafonne autour de 125 caracteres.
			return self::trim_alt( $alt, 125 );
		}

		// --- Photos suivantes : formulations VARIEES. Repeter la meme chaine
		// sur 15 images est lu comme de la sur-optimisation.
		$angles = array(
			__( 'vue intérieure', 'partikulier' ),
			__( 'pièce de vie', 'partikulier' ),
			__( 'espace intérieur', 'partikulier' ),
			__( 'vue depuis le séjour', 'partikulier' ),
			__( 'détail du logement', 'partikulier' ),
			__( 'seconde perspective', 'partikulier' ),
		);
		$angle = $angles[ ( $index - 1 ) % count( $angles ) ];

		$alt = $type;
		if ( '' !== $place ) {
			/* translators: %s: lieu. */
			$alt .= ' ' . sprintf( __( 'à %s', 'partikulier' ), $place );
		}
		$alt .= ' — ' . $angle;

		// Une caracteristique concrete tourne d'une photo a l'autre.
		$features = array();
		if ( 'Oui' === $v['terrace'] ) {
			$features[] = $v['terrace_surface']
				? sprintf( __( 'terrasse de %d m²', 'partikulier' ), $v['terrace_surface'] )
				: __( 'avec terrasse', 'partikulier' );
		}
		if ( 'Oui' === $v['vis_a_vis'] ) {
			$features[] = __( 'sans vis-à-vis', 'partikulier' );
		}
		if ( '' !== $v['sunshine'] ) {
			$features[] = mb_strtolower( $v['sunshine'] );
		}
		if ( '' !== $v['floor'] ) {
			$features[] = 'RDC' === $v['floor'] ? __( 'au rez-de-chaussée', 'partikulier' ) : mb_strtolower( $v['floor'] );
		}
		if ( $features ) {
			$alt .= ', ' . $features[ ( $index - 1 ) % count( $features ) ];
		}

		/* translators: %d: numero de la photo. */
		$alt .= ' ' . sprintf( __( '(photo %d)', 'partikulier' ), $index + 1 );

		return self::trim_alt( $alt, 125 );
	}

	/**
	 * Coupe un texte alternatif au dernier mot entier.
	 *
	 * @param string $alt   Texte a plafonner.
	 * @param int    $limit Longueur maximale.
	 * @return string
	 */
	private static function trim_alt( $alt, $limit ) {
		$alt = trim( preg_replace( '/\s+/u', ' ', $alt ) );
		if ( mb_strlen( $alt ) <= $limit ) {
			return $alt;
		}
		$alt = mb_substr( $alt, 0, $limit );
		$cut = mb_strrpos( $alt, ' ' );
		if ( false !== $cut ) {
			$alt = mb_substr( $alt, 0, $cut );
		}

		return rtrim( $alt, " ,—-" );
	}

	/**
	 * Produit l'ensemble titre + description + resume factuel.
	 *
	 * @param array $data Donnees brutes du formulaire.
	 * @return array
	 */
	public static function build( $data ) {
		$v = self::normalize_input( $data );

		$facts = array();
		if ( '' !== self::place_label( $v ) ) {
			$facts[] = self::place_label( $v );
		}
		if ( $v['surface'] ) {
			$facts[] = $v['surface'] . ' m²';
		}
		$rooms = self::rooms_label( $v );
		if ( '' !== $rooms ) {
			$facts[] = $rooms;
		}
		if ( '' !== (string) $v['bathrooms'] ) {
			$count   = '3+' === (string) $v['bathrooms'] ? 3 : (int) $v['bathrooms'];
			$facts[] = sprintf(
				/* translators: %d: nombre de salles de bains. */
				_n( '%d salle de bains', '%d salles de bains', $count, 'partikulier' ),
				$count
			);
		}
		if ( 'Oui' === $v['terrace'] ) {
			$facts[] = __( 'Terrasse', 'partikulier' ) . ( $v['terrace_surface'] ? ' · ' . $v['terrace_surface'] . ' m²' : '' );
		}

		return array(
			'title'       => self::build_title( $v ),
			'description' => self::build_description( $v ),
			'meta'        => self::build_meta_description( $v ),
			'alt'         => self::build_image_alt( $v ),
			'facts'       => $facts,
			'price'       => $v['price'] ? number_format_i18n( $v['price'] ) . ' MAD' : '',
			'kicker'      => trim(
				( 'louer' === $v['action'] ? __( 'LOUER', 'partikulier' ) : __( 'VENDRE', 'partikulier' ) )
				. ' · '
				. __( 'PROPRIÉTAIRE', 'partikulier' )
			),
		);
	}

	/**
	 * Point d'entree AJAX de l'apercu.
	 */
	public static function handle_preview() {
		check_ajax_referer( 'pk_submit_listing', 'nonce' );

		wp_send_json_success( self::build( $_POST ) );
	}
}

Partikulier_Listing_Preview::init();
