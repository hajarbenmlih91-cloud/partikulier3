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
	 * Administration
	 * ------------------------------------------------------------------ */

	/**
	 * Accrochages.
	 *
	 * @return void
	 */
	public static function init() {
		// Priorité 30 : le menu parent « Partikulier » est enregistré en priorité 10
		// (class-customization). Sans cet ordre, WordPress rattache le sous-menu au
		// mauvais parent et l'écran devient inaccessible (erreur 403).
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Entrée de menu sous « Partikulier ».
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page(
			'partikulier',
			__( 'Formulaire de dépôt', 'partikulier' ),
			__( 'Formulaire de dépôt', 'partikulier' ),
			self::CAPABILITY,
			self::MENU,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Enregistrement depuis l'écran d'administration.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'partikulier' ) );
		}
		check_admin_referer( self::ACTION );

		$demande = isset( $_POST['pk_deposit'] ) && is_array( $_POST['pk_deposit'] ) ? wp_unslash( $_POST['pk_deposit'] ) : array();
		$ordres  = isset( $_POST['pk_deposit_ordre'] ) && is_array( $_POST['pk_deposit_ordre'] ) ? wp_unslash( $_POST['pk_deposit_ordre'] ) : array();
		$preset  = isset( $_POST['pk_preset'] ) ? sanitize_key( wp_unslash( $_POST['pk_preset'] ) ) : '';

		if ( '8' === $preset ) {
			self::save( self::preset_8(), array() );
			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'fait' => 'preset8' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		if ( 'herite' === $preset ) {
			self::reset();
			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'fait' => 'herite' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Enregistrement : le plafond est vérifié dans save(), jamais dans le navigateur seul.
		$res = self::save( $demande, $ordres );
		if ( is_wp_error( $res ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'fait' => 'trop', 'message' => rawurlencode( $res->get_error_message() ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		self::save( $demande, $ordres );
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'fait' => 'ok' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Écran d'administration.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$config   = self::config();
		$configured = self::is_configured();
		$etats    = array(
			self::HIDDEN   => __( 'Masqué', 'partikulier' ),
			self::OPTIONAL => __( 'Optionnel', 'partikulier' ),
			self::REQUIRED => __( 'Obligatoire', 'partikulier' ),
		);
		$types    = self::types();
		$champs   = self::fields();
		$plafond  = self::cap();
		$fait     = isset( $_GET['fait'] ) ? sanitize_key( wp_unslash( $_GET['fait'] ) ) : '';
		?>
		<div class="wrap pk-deposit-admin">
			<h1><?php esc_html_e( 'Formulaire de dépôt', 'partikulier' ); ?></h1>
			<style>
				.pk-deposit-admin .pk-etat { max-width: 190px; }
				.pk-deposit-admin table.widefat { margin-top: .8rem; }
				.pk-deposit-admin .pk-compteur { font-weight: 700; }
				.pk-deposit-admin .pk-compteur.est-bon { color: #166534; }
				.pk-deposit-admin .pk-compteur.est-trop { color: #b32d2e; }
				.pk-deposit-admin .pk-note { max-width: 900px; }
				.pk-deposit-admin .pk-preset { margin: 1rem 0; padding: .9rem 1rem; background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #2271b1; }
				.pk-deposit-admin .pk-rang { color: #646970; }
				@media screen and (max-width: 782px) {
					.pk-deposit-admin .pk-etat { max-width: none; width: 100%; }
					.pk-deposit-admin .pk-col-nouveau { display: none; }
					.pk-deposit-admin .pk-col-ordre input { width: 4.5rem; }
					.pk-deposit-admin table.widefat th, .pk-deposit-admin table.widefat td { padding: 8px 6px; }
				}
			</style>

			<?php if ( 'ok' === $fait ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Réglages enregistrés.', 'partikulier' ); ?></p></div>
			<?php elseif ( 'preset8' === $fait ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuration « 8 champs » appliquée : le plafond est respecté pour chaque type de bien.', 'partikulier' ); ?></p></div>
			<?php elseif ( 'herite' === $fait ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Retour au comportement d’origine du thème : tous les champs actuels sont demandés.', 'partikulier' ); ?></p></div>
			<?php elseif ( 'trop' === $fait ) : ?>
				<div class="notice notice-error is-dismissible"><p>
					<?php
					printf(
						/* translators: 1: plafond, 2: liste des types en dépassement */
						esc_html__( 'Enregistrement refusé : le plafond de %1$d champs est dépassé pour %2$s. Décochez des champs puis réessayez.', 'partikulier' ),
						(int) $plafond,
						esc_html( isset( $_GET['types'] ) ? rawurldecode( wp_unslash( $_GET['types'] ) ) : '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					);
					?>
				</p></div>
			<?php endif; ?>

			<p class="pk-note">
				<?php
				printf(
					/* translators: 1: nombre de champs affichés, 2: plafond */
					esc_html__( 'Pour chaque type de bien, choisissez les champs demandés au dépôt d’annonce. Plafond conseillé : %2$d champs affichés par type, pour ne pas casser la mise en page du formulaire. Réglage actuel : %1$s.', 'partikulier' ),
					esc_html( $configured ? __( 'vos choix enregistrés', 'partikulier' ) : __( 'comportement d’origine du thème', 'partikulier' ) ),
					(int) $plafond
				);
				?>
			</p>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-info inline"><p>
					<strong><?php esc_html_e( 'Rien n’est encore réglé.', 'partikulier' ); ?></strong>
					<?php esc_html_e( 'Le formulaire se comporte exactement comme aujourd’hui. Choisissez ci-dessous, ou partez de la configuration conseillée.', 'partikulier' ); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">

				<div class="pk-preset">
					<strong><?php esc_html_e( 'Configurations prêtes', 'partikulier' ); ?></strong>
					<p>
						<button type="submit" name="pk_preset" value="8" class="button button-primary"><?php esc_html_e( 'Appliquer « 8 champs » (conseillé)', 'partikulier' ); ?></button>
						<button type="submit" name="pk_preset" value="herite" class="button"><?php esc_html_e( 'Revenir au comportement d’origine', 'partikulier' ); ?></button>
					</p>
					<p class="description"><?php esc_html_e( '« 8 champs » : type, ville, quartier, superficie, chambres, prix, salons, salles de bains — et, pour un terrain, terrain + ensoleillement + disponibilité à la place des pièces.', 'partikulier' ); ?></p>
				</div>

				<?php foreach ( $types as $slug => $libelle ) : ?>
					<?php
					$n = 0;
					foreach ( array_keys( $champs ) as $c ) {
						$etat = self::state( $c, $slug );
						if ( self::INHERITED === $etat ) {
							$etat = $champs[ $c ]['actuel'];
						}
						if ( self::HIDDEN !== $etat ) {
							$n++;
						}
					}
					$regle  = false;
					foreach ( array_keys( $champs ) as $c ) {
						if ( self::INHERITED !== self::state( $c, $slug ) ) {
							$regle = true;
							break;
						}
					}
					$classe = ( $n > $plafond ) ? 'est-trop' : 'est-bon';
					?>
					<h2 id="type-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $libelle ); ?></h2>
					<p class="pk-compteur <?php echo esc_attr( $classe ); ?>" data-pk-counter="<?php echo esc_attr( $slug ); ?>" data-pk-configured="<?php echo $regle ? 'oui' : 'non'; ?>">
						<?php
						if ( ! $regle ) {
							// Rien de réglé : on décrit le formulaire d'aujourd'hui, sans ton d'alerte.
							printf(
								/* translators: 1: nombre actuel, 2: plafond */
								esc_html__( 'Aujourd’hui : %1$d champs affichés (formulaire d’origine) — plafond conseillé : %2$d. Appliquez une configuration ou réglez ci-dessous.', 'partikulier' ),
								(int) $n,
								(int) $plafond
							);
						} else {
							printf(
								/* translators: 1: nombre, 2: plafond */
								esc_html__( 'Champs affichés : %1$d sur %2$d', 'partikulier' ),
								(int) $n,
								(int) $plafond
							);
						}
						?>
					</p>
					<table class="widefat striped pk-tableau" data-pk-table="<?php echo esc_attr( $slug ); ?>">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Champ', 'partikulier' ); ?></th>
								<th scope="col" class="pk-col-nouveau"><?php esc_html_e( 'Nouveau', 'partikulier' ); ?></th>
								<th scope="col"><?php esc_html_e( 'État dans le formulaire', 'partikulier' ); ?></th>
							<th scope="col" class="pk-col-ordre"><?php esc_html_e( 'Ordre', 'partikulier' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $champs as $champ => $info ) : ?>
							<?php
							$etat = self::state( $champ, $slug );
							if ( self::INHERITED === $etat ) {
								$etat = $info['actuel'];
							}
							?>
							<tr>
								<td><label for="f-<?php echo esc_attr( $slug . '-' . $champ ); ?>"><?php echo esc_html( $info['label'] ); ?></label></td>
								<td class="pk-col-nouveau"><?php echo $info['ajoute'] ? '<span aria-hidden="true">＋</span>' : ''; ?></td>
								<td>
									<select class="pk-etat" id="f-<?php echo esc_attr( $slug . '-' . $champ ); ?>"
										name="pk_deposit[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $champ ); ?>]"
										data-pk-field="<?php echo esc_attr( $champ ); ?>" data-pk-type="<?php echo esc_attr( $slug ); ?>">
										<?php foreach ( $etats as $valeur => $texte ) : ?>
											<option value="<?php echo esc_attr( $valeur ); ?>" <?php selected( $etat, $valeur ); ?>><?php echo esc_html( $texte ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
									<td class="pk-col-ordre">
										<input type="number" min="1" max="99" step="1" class="small-text" value="<?php echo esc_attr( self::order( $champ, $slug ) ); ?>"
											name="pk_deposit_ordre[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $champ ); ?>]"
											aria-label="<?php echo esc_attr( sprintf( __( 'Ordre du champ %s', 'partikulier' ), $info['label'] ) ); ?>">
									</td>
								</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Enregistrer les réglages', 'partikulier' ); ?></button>
				</p>
			</form>

			<script>
			( function () {
				// Compteur vivant : confort d'usage seulement, le serveur revérifie.
				function maj( table ) {
					var type = table.getAttribute( 'data-pk-table' );
					var n = 0;
					table.querySelectorAll( '.pk-etat' ).forEach( function ( s ) {
						if ( s.value !== '<?php echo esc_js( self::HIDDEN ); ?>' ) { n++; }
					} );
					var c = document.querySelector( '[data-pk-counter="' + type + '"]' );
					if ( ! c ) { return; }
					var max = <?php echo (int) $plafond; ?>;
					c.textContent = c.textContent.replace( /\d+ sur \d+/, n + ' sur ' + max );
					var regle = c.getAttribute( 'data-pk-configured' ) === 'oui';
					c.className = 'pk-compteur ' + ( n > max && regle ? 'est-trop' : 'est-bon' );
				}
				document.querySelectorAll( '[data-pk-table]' ).forEach( function ( t ) {
					t.addEventListener( 'change', function ( e ) {
						if ( e.target.classList.contains( 'pk-etat' ) ) { maj( t ); }
					} );
				} );
			}() );
			</script>
		</div>
		<?php
	}
}

Partikulier_Deposit_Form::init();
