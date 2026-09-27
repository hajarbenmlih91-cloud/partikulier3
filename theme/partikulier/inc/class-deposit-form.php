<?php
/**
 * Module : écran « Formulaire de dépôt » du thème Partikulier.
 *
 * Permet de régler, PAR TYPE DE BIEN, ce que le formulaire de dépôt demande :
 *   - masqué    : le champ n'apparaît pas et toute valeur envoyée est refusée ;
 *   - optionnel : le champ apparaît, on peut le laisser vide ;
 *   - obligatoire : le champ apparaît et doit être rempli.
 *
 * Règles de sécurité et de design tenues par ce module :
 *   - plafond de champs affichés par type (constante CAP, filtre pk_deposit_max_fields) ;
 *   - repli intégral sur le comportement actuel tant que rien n'est enregistré
 *     (état « herite ») : aucun risque de régression sur un site en production ;
 *   - la vérification serveur est faite par Partikulier_Form (méthode verify_deposit_fields).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Deposit_Form {

	/** Nom de l'option (un seul tableau, sérialisé par WordPress). */
	const OPTION = 'pk_deposit_form';

	/** Slug de l'écran d'administration. */
	const MENU = 'pk-deposit-form';

	/** Capacité requise. */
	const CAPABILITY = 'manage_options';

	/** Action du formulaire d'administration. */
	const ACTION = 'pk_save_deposit_form';

	/** Plafond de champs affichés par type de bien (calibrage de la page). */
	const CAP = 8;

	/** États possibles d'un champ. */
	const HIDDEN    = 'masque';
	const OPTIONAL  = 'optionnel';
	const REQUIRED  = 'requis';
	const INHERITED = 'herite';

	/**
	 * Catalogue des champs réglables de l'étape « Les informations du bien ».
	 *
	 * @return array
	 */
	public static function fields() {
		return array(
			// Déjà présents dans le formulaire actuel.
			'pk_type'           => array( 'label' => __( 'Type de bien', 'partikulier' ), 'actuel' => self::REQUIRED, 'ajoute' => false ),
			'pk_city'           => array( 'label' => __( 'Ville', 'partikulier' ), 'actuel' => self::REQUIRED, 'ajoute' => false ),
			'pk_district'       => array( 'label' => __( 'Quartier', 'partikulier' ), 'actuel' => self::REQUIRED, 'ajoute' => false ),
			'pk_surface'        => array( 'label' => __( 'Superficie (m²)', 'partikulier' ), 'actuel' => self::REQUIRED, 'ajoute' => false ),
			'pk_bedrooms'       => array( 'label' => __( 'Nombre de chambres', 'partikulier' ), 'actuel' => self::REQUIRED, 'ajoute' => false ),
			'pk_price'          => array( 'label' => __( 'Prix demandé', 'partikulier' ), 'actuel' => self::REQUIRED, 'ajoute' => false ),
			'pk_living_rooms'   => array( 'label' => __( 'Nombre de salons', 'partikulier' ), 'actuel' => self::REQUIRED, 'ajoute' => false ),
			'pk_bathrooms'      => array( 'label' => __( 'Nombre de salles de bains', 'partikulier' ), 'actuel' => self::REQUIRED, 'ajoute' => false ),
			'pk_sunshine'       => array( 'label' => __( 'Ensoleillement', 'partikulier' ), 'actuel' => self::REQUIRED, 'ajoute' => false ),
			'pk_terrace'        => array( 'label' => __( 'Terrasse', 'partikulier' ), 'actuel' => self::REQUIRED, 'ajoute' => false ),
			'pk_terrace_surface'=> array( 'label' => __( 'Superficie de la terrasse (m²)', 'partikulier' ), 'actuel' => self::OPTIONAL, 'ajoute' => false ),
			'pk_floor'          => array( 'label' => __( 'Étage', 'partikulier' ), 'actuel' => self::OPTIONAL, 'ajoute' => false ),
			'pk_garage'         => array( 'label' => __( 'Garage ou sous-sol', 'partikulier' ), 'actuel' => self::OPTIONAL, 'ajoute' => false ),
			'pk_elevator'       => array( 'label' => __( 'Ascenseur', 'partikulier' ), 'actuel' => self::OPTIONAL, 'ajoute' => false ),
			'pk_vis_a_vis'      => array( 'label' => __( 'Sans vis-à-vis', 'partikulier' ), 'actuel' => self::OPTIONAL, 'ajoute' => false ),

			// Nouveaux : les données que la fiche sait afficher depuis le lot SE-042b.
			'pk_charges'        => array( 'label' => __( 'Charges (MAD / mois)', 'partikulier' ), 'actuel' => self::HIDDEN, 'ajoute' => true ),
			'pk_availability'   => array( 'label' => __( 'Disponibilité', 'partikulier' ), 'actuel' => self::HIDDEN, 'ajoute' => true ),
			'pk_year_built'     => array( 'label' => __( 'Année de construction', 'partikulier' ), 'actuel' => self::HIDDEN, 'ajoute' => true ),
			'pk_energy_class'   => array( 'label' => __( 'Classe énergie', 'partikulier' ), 'actuel' => self::HIDDEN, 'ajoute' => true ),
			'pk_ges_class'      => array( 'label' => __( 'GES', 'partikulier' ), 'actuel' => self::HIDDEN, 'ajoute' => true ),
			'pk_lot_size'       => array( 'label' => __( 'Terrain (m²)', 'partikulier' ), 'actuel' => self::HIDDEN, 'ajoute' => true ),
			'pk_half_baths'     => array( 'label' => __( 'Demi-salles de bains', 'partikulier' ), 'actuel' => self::HIDDEN, 'ajoute' => true ),
			'pk_total_rooms'    => array( 'label' => __( 'Nombre de pièces', 'partikulier' ), 'actuel' => self::HIDDEN, 'ajoute' => true ),
		);
	}

	/**
	 * Types de biens proposés (taxonomie Estatik, repli sur une liste fixe).
	 *
	 * @return array slug => libellé
	 */
	public static function types() {
		$sortie = array();
		if ( taxonomy_exists( 'es_type' ) ) {
			$termes = get_terms( array( 'taxonomy' => 'es_type', 'hide_empty' => false, 'lang' => 'all' ) );
			if ( ! is_wp_error( $termes ) ) {
				foreach ( $termes as $terme ) {
					$sortie[ $terme->slug ] = $terme->name;
				}
			}
		}
		if ( empty( $sortie ) ) {
			$sortie = array( 'appartement' => 'Appartement', 'maison' => 'Maison', 'terrain' => 'Terrain', 'loft' => 'Loft', 'studio' => 'Studio' );
		}
		return $sortie;
	}

	/**
	 * Configuration enregistrée.
	 *
	 * @return array
	 */
	public static function config() {
		$brut = get_option( self::OPTION, array() );
		return is_array( $brut ) ? $brut : array();
	}

	/**
	 * La configuration est-elle posée ?
	 *
	 * @return bool
	 */
	public static function is_configured() {
		$config = self::config();
		return ! empty( $config['types'] ) && is_array( $config['types'] );
	}

	/**
	 * État d'un champ pour un type donné.
	 *
	 * @param string $champ Clé du champ (ex. pk_surface).
	 * @param string $type  Slug du type de bien.
	 * @return string herite|masque|optionnel|requis
	 */
	public static function state( $champ, $type = '' ) {
		$catalogue = self::fields();
		$defaut    = isset( $catalogue[ $champ ] ) ? $catalogue[ $champ ]['actuel'] : self::INHERITED;
		$config    = self::config();

		if ( empty( $config['types'] ) || ! is_array( $config['types'] ) ) {
			return self::INHERITED; // Rien n'a été réglé : le gabarit garde la main.
		}

		$pour_type = isset( $config['types'][ $type ] ) ? $config['types'][ $type ] : null;
		if ( null === $pour_type && isset( $config['types']['defaut'] ) ) {
			$pour_type = $config['types']['defaut'];
		}
		if ( ! is_array( $pour_type ) || ! isset( $pour_type[ $champ ] ) ) {
			return self::INHERITED;
		}

		$etat = (string) $pour_type[ $champ ];
		return in_array( $etat, array( self::HIDDEN, self::OPTIONAL, self::REQUIRED ), true ) ? $etat : $defaut;
	}

	/**
	 * Le champ doit-il s'afficher ?
	 *
	 * @param string $champ Champ.
	 * @param string $type  Type de bien.
	 * @return bool
	 */
	public static function is_visible( $champ, $type = '' ) {
		$etat = self::state( $champ, $type );
		if ( self::INHERITED === $etat ) {
			$catalogue = self::fields();
			return isset( $catalogue[ $champ ] ) ? self::HIDDEN !== $catalogue[ $champ ]['actuel'] : true;
		}
		return self::HIDDEN !== $etat;
	}

	/**
	 * Le champ est-il obligatoire ?
	 *
	 * @param string $champ Champ.
	 * @param string $type  Type de bien.
	 * @return bool
	 */
	public static function is_required( $champ, $type = '' ) {
		return self::REQUIRED === self::state( $champ, $type );
	}

	/**
	 * Remet le formulaire dans son état d'origine (bouton « hériter ») :
	 * l'option disparaît, le gabarit actuel reprend la main sans aucune modification.
	 *
	 * @return void
	 */
	public static function reset() {
		delete_option( self::OPTION );
	}

	/**
	 * Position d'un champ dans le formulaire (1 = premier).
	 *
	 * @param string $champ Champ.
	 * @param string $type  Type de bien.
	 * @return int
	 */
	public static function order( $champ, $type = '' ) {
		$config = self::config();
		if ( isset( $config['ordre'][ $type ][ $champ ] ) ) {
			return (int) $config['ordre'][ $type ][ $champ ];
		}
		$cles = array_keys( self::fields() );
		$rang = array_search( $champ, $cles, true );
		return ( false === $rang ) ? 99 : ( $rang + 1 );
	}

	/**
	 * Nombre de champs affichés pour un type (plafond de calibrage).
	 *
	 * @param string $type Type de bien.
	 * @return int
	 */
	public static function visible_count( $type = '' ) {
		$n = 0;
		foreach ( array_keys( self::fields() ) as $champ ) {
			if ( self::is_visible( $champ, $type ) ) {
				$n++;
			}
		}
		return $n;
	}

	/**
	 * Plafond courant.
	 *
	 * @return int
	 */
	public static function cap() {
		return (int) apply_filters( 'pk_deposit_max_fields', self::CAP );
	}

	/** Préfixe des clés POST du formulaire de dépôt. */
	public static function is_field_key( $cle ) {
		return 0 === strpos( (string) $cle, 'pk_' );
	}

	/**
	 * Enregistre une configuration (déjà validée par l'appelant).
	 *
	 * @param array $types slug => ( champ => état ).
	 * @return void
	 */
	public static function save( $types, $ordres = array() ) {
		// Plafond vérifié ICI : aucun appelant ne peut l'éviter (pas seulement l'écran d'admin).
		$trop = array();
		foreach ( self::types() as $slug => $libelle ) {
			if ( ! isset( $types[ $slug ] ) || ! is_array( $types[ $slug ] ) ) {
				continue;
			}
			$n = 0;
			foreach ( array_keys( self::fields() ) as $champ ) {
				$etat = isset( $types[ $slug ][ $champ ] ) ? sanitize_key( $types[ $slug ][ $champ ] ) : self::HIDDEN;
				if ( in_array( $etat, array( self::OPTIONAL, self::REQUIRED ), true ) ) {
					$n++;
				}
			}
			if ( $n > self::cap() ) {
				$trop[ $slug ] = $n;
			}
		}
		if ( $trop ) {
			$details = array();
			foreach ( $trop as $slug => $n ) {
				$libelles = self::types();
				$details[] = sprintf( '%s (%d)', isset( $libelles[ $slug ] ) ? $libelles[ $slug ] : $slug, $n );
			}
			return new WP_Error(
				'trop_de_champs',
				sprintf(
					/* translators: 1: plafond, 2: liste des types en excès */
					__( 'Le formulaire affiche au maximum %1$d champs par type de bien. À corriger : %2$s.', 'partikulier' ),
					self::cap(),
					implode( ', ', $details )
				),
				array( 'types' => array_keys( $trop ) )
			);
		}

		$propre = array();
		foreach ( self::types() as $slug => $libelle ) {
			if ( ! isset( $types[ $slug ] ) || ! is_array( $types[ $slug ] ) ) {
				continue;
			}
			foreach ( self::fields() as $champ => $info ) {
				if ( ! isset( $types[ $slug ][ $champ ] ) ) {
					continue;
				}
				$etat = sanitize_key( $types[ $slug ][ $champ ] );
				if ( in_array( $etat, array( self::HIDDEN, self::OPTIONAL, self::REQUIRED ), true ) ) {
					$propre[ $slug ][ $champ ] = $etat;
				}
			}
		}
		$ordre_propre = array();
		foreach ( self::types() as $slug => $libelle ) {
			if ( ! isset( $ordres[ $slug ] ) || ! is_array( $ordres[ $slug ] ) ) {
				continue;
			}
			foreach ( self::fields() as $champ => $info ) {
				if ( isset( $ordres[ $slug ][ $champ ] ) ) {
					$ordre_propre[ $slug ][ $champ ] = max( 1, min( 99, (int) $ordres[ $slug ][ $champ ] ) );
				}
			}
		}
		$config           = self::config();
		$config['types']  = $propre;
		$config['ordre']  = $ordre_propre;
		$config['maj']    = current_time( 'mysql' );
		update_option( self::OPTION, $config );

		return true;
	}

	/**
	 * Construit la configuration « 8 champs » conseillée (plafond respecté).
	 *
	 * @return array slug => ( champ => état )
	 */
	public static function preset_8() {
		$coeur = array( 'pk_type', 'pk_city', 'pk_district', 'pk_surface', 'pk_bedrooms', 'pk_price', 'pk_living_rooms', 'pk_bathrooms' );
		$types = array();
		foreach ( self::types() as $slug => $libelle ) {
			foreach ( array_keys( self::fields() ) as $champ ) {
				$types[ $slug ][ $champ ] = self::HIDDEN;
			}
			foreach ( $coeur as $champ ) {
				$types[ $slug ][ $champ ] = self::REQUIRED;
			}
			// Un terrain ne se décrit pas en chambres ni en salles de bains.
			if ( 'terrain' === $slug ) {
				foreach ( array( 'pk_bedrooms', 'pk_living_rooms', 'pk_bathrooms' ) as $champ ) {
					$types[ $slug ][ $champ ] = self::HIDDEN;
				}
				$types[ $slug ]['pk_lot_size']     = self::REQUIRED;
				$types[ $slug ]['pk_sunshine']     = self::OPTIONAL;
				$types[ $slug ]['pk_availability'] = self::OPTIONAL;
			}
		}
		return $types;
	}

	/* ---------------------------------------------------------------------
	 * Administration (délégation vers class-deposit-form-admin.php)
	 * ------------------------------------------------------------------ */

	/**
	 * Initialisation des accrochages d'administration.
	 *
	 * @return void
	 */
	public static function init() {
		require_once __DIR__ . '/class-deposit-form-admin.php';
		Partikulier_Deposit_Form_Admin::init();
	}

	/**
	 * Rendu de l'écran d'administration.
	 *
	 * @return void
	 */
	public static function render() {
		require_once __DIR__ . '/class-deposit-form-admin.php';
		Partikulier_Deposit_Form_Admin::render();
	}
}

Partikulier_Deposit_Form::init();
