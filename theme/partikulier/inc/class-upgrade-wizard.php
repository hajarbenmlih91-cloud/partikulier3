<?php
/**
 * Module : assistant de mise a niveau du site.
 *
 * Trois operations de maintenance doivent etre lancees dans un ordre precis :
 *   1. reparer les taxonomies (villes rangees dans es_category) ;
 *   2. reassigner l'action A vendre / A louer aux annonces ;
 *   3. creer les versions arabe et anglaise.
 *
 * Inverser cet ordre produit des traductions sans lieu, sans valeur SEO.
 * Cet ecran verrouille donc chaque etape tant que la precedente n'est pas
 * terminee, et affiche l'etat reel du site plutot qu'une case a cocher.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Upgrade_Wizard {

	const MENU_SLUG = 'pk-upgrade';
	const ACTION    = 'pk_upgrade_step';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 20 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	public static function add_menu() {
		$pending = self::pending_steps();
		$label   = __( 'Mise à niveau', 'partikulier' );
		if ( $pending ) {
			$label .= ' <span class="update-plugins count-' . (int) $pending . '"><span class="plugin-count">' . (int) $pending . '</span></span>';
		}

		add_submenu_page(
			'partikulier',
			__( 'Mise à niveau', 'partikulier' ),
			$label,
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/* ------------------------------------------------------------------ */
	/* Diagnostic : on mesure l'etat reel, on ne stocke pas de « fait ».   */
	/* ------------------------------------------------------------------ */

	/**
	 * Termes de es_category qui n'ont rien a y faire (villes mal classees).
	 *
	 * @return WP_Term[]
	 */
	public static function misplaced_terms() {
		$terms = get_terms( array(
			'taxonomy'   => PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY,
			'hide_empty' => false,
		) );
		if ( is_wp_error( $terms ) || ! $terms ) {
			return array();
		}

		$valid = array( 'a vendre', 'a louer', 'vendre', 'louer', 'vente', 'location', 'for sale', 'for rent', 'sale', 'rent' );
		$bad   = array();

		foreach ( $terms as $term ) {
			$name = remove_accents( $term->name );
			$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
			if ( ! in_array( trim( $name ), $valid, true ) ) {
				$bad[] = $term;
			}
		}

		return $bad;
	}

	/**
	 * Annonces publiees sans action commerciale.
	 *
	 * @return int
	 */
	public static function listings_without_action() {
		$posts = get_posts( array(
			'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
			'post_status'      => array( 'publish', 'pending', 'draft' ),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'lang'             => '',
		) );

		$count = 0;
		foreach ( $posts as $id ) {
			if ( get_post_meta( $id, '_pk_auto_translation', true ) ) {
				continue;
			}
			$terms = wp_get_object_terms( $id, PARTIKULIER_ESTATIK_STATUS_TAXONOMY, array( 'fields' => 'ids' ) );
			if ( is_wp_error( $terms ) || ! $terms ) {
				$count++;
			}
		}

		return $count;
	}

	/**
	 * Annonces sources auxquelles il manque au moins une traduction.
	 *
	 * @return int
	 */
	public static function listings_without_translation() {
		if ( ! class_exists( 'Partikulier_Listing_Translations' ) || ! Partikulier_Listing_Translations::available() ) {
			return 0;
		}

		$languages = Partikulier_Listing_Translations::active_languages();
		if ( count( $languages ) < 2 ) {
			return 0;
		}

		$default = function_exists( 'pll_default_language' ) ? pll_default_language() : 'fr';
		$posts   = get_posts( array(
			'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
			'post_status'      => array( 'publish', 'pending', 'draft' ),
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'suppress_filters' => true,
			'lang'             => '',
		) );

		$count = 0;
		foreach ( $posts as $id ) {
			if ( get_post_meta( $id, '_pk_auto_translation', true ) ) {
				continue;
			}
			$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $id ) : '';
			if ( $lang && $lang !== $default ) {
				continue;
			}
			$translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $id ) : array();
			foreach ( $languages as $l ) {
				if ( $l === $default ) {
					continue;
				}
				if ( empty( $translations[ $l ] ) || ! get_post( $translations[ $l ] ) ) {
					$count++;
					break;
				}
			}
		}

		return $count;
	}

	/**
	 * Nombre d'etapes encore a faire.
	 *
	 * @return int
	 */
	public static function pending_steps() {
		$n = 0;
		if ( self::misplaced_terms() ) {
			$n++;
		}
		if ( self::listings_without_action() ) {
			$n++;
		}
		if ( self::listings_without_translation() ) {
			$n++;
		}

		return $n;
	}

	/* ------------------------------------------------------------------ */
	/* Rendu                                                               */
	/* ------------------------------------------------------------------ */

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'partikulier' ), '', array( 'response' => 403 ) );
		}

		$misplaced   = self::misplaced_terms();
		$no_action   = self::listings_without_action();
		$no_translat = self::listings_without_translation();

		$step1_done = empty( $misplaced );
		$step2_done = $step1_done && 0 === $no_action;
		$step3_done = $step2_done && 0 === $no_translat;

		$done = isset( $_GET['pk_done'] ) ? sanitize_key( wp_unslash( $_GET['pk_done'] ) ) : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Mise à niveau du site', 'partikulier' ); ?></h1>
			<p class="description" style="max-width:46em">
				<?php esc_html_e( 'Trois opérations doivent être effectuées dans cet ordre. Chaque étape reste verrouillée tant que la précédente n’est pas terminée : traduire des annonces sans ville produirait des pages sans valeur pour Google.', 'partikulier' ); ?>
			</p>

			<?php if ( $done ) : ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php echo esc_html( self::done_message( $done ) ); ?>
				</p></div>
			<?php endif; ?>

			<div style="background:#fff8e5;border-left:4px solid #dba617;padding:12px 16px;margin:18px 0;max-width:46em">
				<strong><?php esc_html_e( 'Avant de commencer : sauvegardez votre base de données.', 'partikulier' ); ?></strong><br>
				<?php esc_html_e( 'Chez Hostinger : hPanel › Bases de données › phpMyAdmin › Exporter. C’est la seule marche arrière possible.', 'partikulier' ); ?>
			</div>

			<?php
			self::step_box(
				1,
				__( 'Remettre les villes dans la bonne taxonomie', 'partikulier' ),
				$step1_done,
				true,
				$step1_done
					? __( 'Aucun terme mal classé. La taxonomie des actions est propre.', 'partikulier' )
					: sprintf(
						/* translators: %d: nombre de termes. */
						_n( '%d terme est rangé dans « Achat ou location » alors qu’il n’y a pas sa place.', '%d termes sont rangés dans « Achat ou location » alors qu’ils n’y ont pas leur place.', count( $misplaced ), 'partikulier' ),
						count( $misplaced )
					),
				$misplaced ? array_unique( wp_list_pluck( array_slice( $misplaced, 0, 12 ), 'name' ) ) : array(),
				'repair',
				__( 'Déplacer ces villes vers « Ville ou quartier »', 'partikulier' )
			);

			self::step_box(
				2,
				__( 'Réassigner « À vendre » ou « À louer »', 'partikulier' ),
				$step2_done,
				$step1_done,
				$step2_done
					? __( 'Toutes les annonces ont une action commerciale.', 'partikulier' )
					: sprintf(
						/* translators: %d: nombre d'annonces. */
						_n( '%d annonce n’a pas d’action. Sans elle, pas de badge et le filtre ne la trouve pas.', '%d annonces n’ont pas d’action. Sans elle, pas de badge et le filtre ne les trouve pas.', $no_action, 'partikulier' ),
						$no_action
					),
				array(),
				'',
				''
			);

			self::step_box(
				3,
				__( 'Créer les versions arabe et anglaise', 'partikulier' ),
				$step3_done,
				$step2_done,
				$step3_done
					? __( 'Toutes les annonces existent dans les trois langues.', 'partikulier' )
					: sprintf(
						/* translators: %d: nombre d'annonces. */
						_n( '%d annonce n’a pas encore ses versions arabe et anglaise.', '%d annonces n’ont pas encore leurs versions arabe et anglaise.', $no_translat, 'partikulier' ),
						$no_translat
					),
				array(),
				'translate',
				__( 'Créer les traductions manquantes', 'partikulier' )
			);
			?>

			<?php if ( $step1_done && $step2_done && $step3_done ) : ?>
				<div style="background:#edfaef;border-left:4px solid #00a32a;padding:14px 18px;margin-top:22px;max-width:46em">
					<strong><?php esc_html_e( 'Tout est à jour.', 'partikulier' ); ?></strong><br>
					<?php esc_html_e( 'Vous pouvez maintenant supprimer les scripts du dossier tests/ de votre serveur : le thème fonctionne sans eux.', 'partikulier' ); ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Affiche une etape.
	 *
	 * @param int      $number   Numero.
	 * @param string   $title    Titre.
	 * @param bool     $done     Etape terminee.
	 * @param bool     $unlocked Etape accessible.
	 * @param string   $status   Phrase d'etat.
	 * @param string[] $samples  Exemples a lister.
	 * @param string   $action   Action a declencher (vide = manuelle).
	 * @param string   $button   Libelle du bouton.
	 */
	private static function step_box( $number, $title, $done, $unlocked, $status, $samples, $action, $button ) {
		$border = $done ? '#00a32a' : ( $unlocked ? '#2271b1' : '#dcdcde' );
		$opacity = $unlocked ? '1' : '.55';
		?>
		<div style="background:#fff;border:1px solid #dcdcde;border-left:4px solid <?php echo esc_attr( $border ); ?>;padding:16px 20px;margin:14px 0;max-width:46em;opacity:<?php echo esc_attr( $opacity ); ?>">
			<h2 style="margin:0 0 .3em;font-size:15px">
				<?php echo $done ? '✓ ' : ''; ?>
				<?php printf( esc_html__( 'Étape %1$d — %2$s', 'partikulier' ), (int) $number, esc_html( $title ) ); ?>
			</h2>
			<p style="margin:.2em 0 .8em"><?php echo esc_html( $status ); ?></p>

			<?php if ( $samples ) : ?>
				<p style="margin:.2em 0 .9em;color:#646970;font-size:13px">
					<?php echo esc_html( implode( ' · ', $samples ) ); ?>
				</p>
			<?php endif; ?>

			<?php if ( $done ) : ?>
				<span style="color:#00a32a;font-weight:600"><?php esc_html_e( 'Terminé', 'partikulier' ); ?></span>
			<?php elseif ( ! $unlocked ) : ?>
				<span style="color:#8c8f94"><?php esc_html_e( 'Verrouillé — terminez l’étape précédente.', 'partikulier' ); ?></span>
			<?php elseif ( $action ) : ?>
				<a class="button button-primary" href="<?php echo esc_url( self::action_url( $action ) ); ?>"
				   onclick="return confirm('<?php echo esc_js( __( 'Avez-vous sauvegardé votre base de données ? Cette opération modifie vos données.', 'partikulier' ) ); ?>');">
					<?php echo esc_html( $button ); ?>
				</a>
			<?php else : ?>
				<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . PARTIKULIER_ESTATIK_POST_TYPE ) ); ?>">
					<?php esc_html_e( 'Ouvrir la liste des annonces', 'partikulier' ); ?>
				</a>
				<p style="margin:.7em 0 0;color:#646970;font-size:13px">
					<?php esc_html_e( 'Cochez les annonces à vendre › Actions groupées › Modifier › Catégorie › À vendre. Puis recommencez pour celles à louer.', 'partikulier' ); ?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * URL signee d'une etape.
	 *
	 * @param string $step Etape.
	 * @return string
	 */
	private static function action_url( $step ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION . '&step=' . $step ),
			self::ACTION . '_' . $step
		);
	}

	/**
	 * Message de confirmation.
	 *
	 * @param string $step Etape terminee.
	 * @return string
	 */
	private static function done_message( $step ) {
		if ( 'repair' === $step ) {
			return __( 'Les villes ont été déplacées vers la bonne taxonomie.', 'partikulier' );
		}
		if ( 'translate' === $step ) {
			return __( 'Les versions arabe et anglaise ont été créées.', 'partikulier' );
		}

		return __( 'Opération terminée.', 'partikulier' );
	}

	/* ------------------------------------------------------------------ */
	/* Execution                                                           */
	/* ------------------------------------------------------------------ */

	public static function handle() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'partikulier' ), '', array( 'response' => 403 ) );
		}

		$step = isset( $_GET['step'] ) ? sanitize_key( wp_unslash( $_GET['step'] ) ) : '';
		check_admin_referer( self::ACTION . '_' . $step );

		if ( 'repair' === $step ) {
			self::run_repair();
		} elseif ( 'translate' === $step ) {
			// Verrou serveur : on ne traduit jamais avant la reparation.
			if ( self::misplaced_terms() ) {
				wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
				exit;
			}
			self::run_translations();
		}

		flush_rewrite_rules( false );
		if ( class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
			Partikulier_Cache::purge_all();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&pk_done=' . $step ) );
		exit;
	}

	/**
	 * Etape 1 : deplace les termes mal classes vers es_location.
	 */
	private static function run_repair() {
		foreach ( self::misplaced_terms() as $term ) {
			$posts = get_posts( array(
				'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
				'post_status'      => 'any',
				'posts_per_page'   => -1,
				'fields'           => 'ids',
				'suppress_filters' => true,
				'lang'             => '',
				'tax_query'        => array(
					array(
						'taxonomy' => PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY,
						'field'    => 'term_id',
						'terms'    => $term->term_id,
					),
				),
			) );

			$target = class_exists( 'Partikulier_Morocco_Places' )
				? Partikulier_Morocco_Places::ensure_location_term( $term->name )
				: 0;

			foreach ( $posts as $post_id ) {
				if ( $target ) {
					wp_set_object_terms( $post_id, (int) $target, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, true );
				}
				wp_remove_object_terms( $post_id, (int) $term->term_id, PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY );
			}

			wp_delete_term( $term->term_id, PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY );
		}
	}

	/**
	 * Etape 3 : cree les traductions manquantes.
	 */
	private static function run_translations() {
		if ( ! class_exists( 'Partikulier_Listing_Translations' ) || ! Partikulier_Listing_Translations::available() ) {
			return;
		}

		$default = function_exists( 'pll_default_language' ) ? pll_default_language() : 'fr';
		$posts   = get_posts( array(
			'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
			'post_status'      => array( 'publish', 'pending', 'draft' ),
			'posts_per_page'   => -1,
			'suppress_filters' => true,
			'lang'             => '',
			'orderby'          => 'ID',
			'order'            => 'ASC',
		) );

		foreach ( $posts as $post ) {
			if ( get_post_meta( $post->ID, '_pk_auto_translation', true ) ) {
				continue;
			}
			$lang = function_exists( 'pll_get_post_language' ) ? pll_get_post_language( $post->ID ) : '';
			if ( $lang && $lang !== $default ) {
				continue;
			}

			$values = self::rebuild_values( $post );
			$norm   = Partikulier_Listing_Preview::normalize_input( $values );

			Partikulier_Listing_Translations::sync( $post->ID, $norm, $default, '' );
			Partikulier_Listing_Translations::sync_status( $post->ID, $post->post_status );
		}
	}

	/**
	 * Reconstruit les donnees d'une annonce depuis la base.
	 *
	 * @param WP_Post $post Annonce.
	 * @return array
	 */
	private static function rebuild_values( $post ) {
		$id = $post->ID;

		$type_terms = wp_get_object_terms( $id, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
		$type_label = ( $type_terms && ! is_wp_error( $type_terms ) ) ? $type_terms[0]->name : 'Bien';

		$action = 'vendre';
		$cat    = wp_get_object_terms( $id, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
		if ( $cat && ! is_wp_error( $cat ) ) {
			$name = remove_accents( $cat[0]->name );
			$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( $name ) : strtolower( $name );
			if ( false !== strpos( $name, 'lou' ) || false !== strpos( $name, 'rent' ) ) {
				$action = 'louer';
			}
		}

		$city     = '';
		$district = '';
		$places   = wp_get_object_terms( $id, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
		if ( $places && ! is_wp_error( $places ) ) {
			foreach ( $places as $term ) {
				if ( $term->parent ) {
					$district = $term->name;
					$parent   = get_term( $term->parent, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
					if ( $parent && ! is_wp_error( $parent ) ) {
						$city = $parent->name;
					}
				} elseif ( '' === $city ) {
					$city = $term->name;
				}
			}
		}

		$bedrooms = get_post_meta( $id, '_pk_bedrooms_label', true );
		if ( '' === $bedrooms ) {
			$raw      = get_post_meta( $id, 'es_property_bedrooms', true );
			$bedrooms = ( '' !== $raw ) ? (string) (int) $raw : '';
		}

		return array(
			'pk_action_mode'     => $action,
			'pk_role'            => 'proprietaire',
			'pk_type_label'      => $type_label,
			'pk_city_name'       => $city,
			'pk_district_name'   => $district,
			'pk_surface'         => (string) get_post_meta( $id, 'es_property_area', true ),
			'pk_price'           => (string) get_post_meta( $id, 'es_property_price', true ),
			'pk_bedrooms'        => (string) $bedrooms,
			'pk_living_rooms'    => (string) get_post_meta( $id, '_pk_living_rooms_label', true ),
			'pk_bathrooms'       => (string) get_post_meta( $id, '_pk_bathrooms_label', true ),
			'pk_floor'           => (string) get_post_meta( $id, '_pk_floor', true ),
			'pk_garage'          => get_post_meta( $id, '_pk_garage', true ) ?: 'Non',
			'pk_elevator'        => get_post_meta( $id, '_pk_elevator', true ) ?: 'Non',
			'pk_vis_a_vis'       => get_post_meta( $id, '_pk_vis_a_vis', true ) ?: 'Non',
			'pk_terrace'         => get_post_meta( $id, '_pk_terrace', true ) ?: 'Non',
			'pk_terrace_surface' => (string) get_post_meta( $id, '_pk_terrace_surface', true ),
			'pk_sunshine'        => (string) get_post_meta( $id, '_pk_sunshine', true ),
		);
	}
}

Partikulier_Upgrade_Wizard::init();
