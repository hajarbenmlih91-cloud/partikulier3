<?php
/**
 * Module : demandes de creation de ville ou de quartier.
 *
 * Regle metier : l'annonceur ne choisit QUE des lieux existants. S'il ne
 * trouve pas le sien, il peut en proposer un — mais rien n'est cree tant
 * que l'administrateur n'a pas valide, et l'annonce reste hors ligne.
 *
 * Cycle de vie :
 *   1. proposition enregistree (statut « en attente »), annonce bloquee ;
 *   2. l'admin valide  -> le terme es_location est cree, l'annonce est
 *      rattachee puis liberee (elle repasse dans le circuit normal) ;
 *   3. l'admin refuse  -> aucun terme cree, l'annonce reste bloquee et
 *      son auteur est invite a choisir un lieu existant.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Place_Requests {

	/**
	 * Option stockant les demandes en attente.
	 */
	const OPTION = 'pk_place_requests';

	/**
	 * Meta posee sur l'annonce bloquee.
	 */
	const META_PENDING = '_pk_pending_place';

	/**
	 * Statut de moderation du lieu, porte par l'annonce.
	 */
	const META_STATUS = '_pk_place_status';

	const STATUS_PENDING  = 'pending';
	const STATUS_APPROVED = 'approved';
	const STATUS_REJECTED = 'rejected';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_pk_place_decision', array( __CLASS__, 'handle_decision' ) );
		add_action( 'admin_notices', array( __CLASS__, 'pending_notice' ) );

		// Filet de securite : une annonce dont le lieu attend validation ne
		// doit jamais apparaitre en front, meme si son statut passe a publie.
		add_action( 'transition_post_status', array( __CLASS__, 'guard_publication' ), 10, 3 );
	}

	/**
	 * Empeche la mise en ligne tant que le lieu n'est pas valide.
	 *
	 * @param string  $new    Nouveau statut.
	 * @param string  $old    Ancien statut.
	 * @param WP_Post $post   Publication concernee.
	 */
	public static function guard_publication( $new, $old, $post ) {
		if ( 'publish' !== $new || PARTIKULIER_ESTATIK_POST_TYPE !== $post->post_type ) {
			return;
		}
		if ( ! self::is_blocked( $post->ID ) ) {
			return;
		}

		// On repasse l'annonce en attente, sans boucler sur ce meme hook.
		remove_action( 'transition_post_status', array( __CLASS__, 'guard_publication' ), 10 );
		wp_update_post( array(
			'ID'          => $post->ID,
			'post_status' => 'pending',
		) );
		add_action( 'transition_post_status', array( __CLASS__, 'guard_publication' ), 10, 3 );
	}

	/**
	 * Toutes les demandes enregistrees.
	 *
	 * @return array
	 */
	public static function all() {
		$rows = get_option( self::OPTION, array() );

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Demandes encore en attente de decision.
	 *
	 * @return array
	 */
	public static function pending() {
		return array_filter(
			self::all(),
			static function ( $row ) {
				return isset( $row['status'] ) && self::STATUS_PENDING === $row['status'];
			}
		);
	}

	/**
	 * Enregistre une proposition de lieu et bloque l'annonce concernee.
	 *
	 * @param int    $post_id  Annonce concernee.
	 * @param string $city     Ville proposee (ou existante).
	 * @param string $district Quartier propose (optionnel).
	 * @param int    $city_id  Terme de ville existant, si connu.
	 * @return string Identifiant de la demande.
	 */
	public static function add( $post_id, $city, $district = '', $city_id = 0 ) {
		$rows = self::all();
		$key  = 'pk_' . $post_id . '_' . wp_generate_password( 6, false, false );

		$rows[ $key ] = array(
			'id'        => $key,
			'post_id'   => (int) $post_id,
			'city'      => sanitize_text_field( $city ),
			'district'  => sanitize_text_field( $district ),
			'city_id'   => (int) $city_id,
			'status'    => self::STATUS_PENDING,
			'created'   => current_time( 'mysql' ),
			'author'    => (int) get_post_field( 'post_author', $post_id ),
		);

		update_option( self::OPTION, $rows, false );

		update_post_meta( $post_id, self::META_PENDING, $key );
		update_post_meta( $post_id, self::META_STATUS, self::STATUS_PENDING );

		return $key;
	}

	/**
	 * Une annonce attend-elle la validation de son lieu ?
	 *
	 * @param int $post_id Annonce.
	 * @return bool
	 */
	public static function is_blocked( $post_id ) {
		$status = get_post_meta( $post_id, self::META_STATUS, true );

		// En attente ET refuse bloquent la mise en ligne : dans les deux cas
		// le lieu de l'annonce n'existe pas dans la taxonomie.
		return in_array( $status, array( self::STATUS_PENDING, self::STATUS_REJECTED ), true );
	}

	/**
	 * Menu d'administration.
	 */
	public static function add_menu() {
		$count = count( self::pending() );
		$label = __( 'Lieux proposés', 'partikulier' );
		if ( $count ) {
			$label .= ' <span class="update-plugins count-' . (int) $count . '"><span class="plugin-count">' . (int) $count . '</span></span>';
		}

		add_submenu_page(
			'partikulier',
			__( 'Lieux proposés', 'partikulier' ),
			$label,
			'manage_options',
			'pk-place-requests',
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Rappel visible tant que des demandes attendent.
	 */
	public static function pending_notice() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$pending = self::pending();
		if ( ! $pending ) {
			return;
		}
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( $screen && 'partikulier_page_pk-place-requests' === $screen->id ) {
			return;
		}
		?>
		<div class="notice notice-warning">
			<p>
				<strong><?php esc_html_e( 'Partikulier — lieux à valider', 'partikulier' ); ?></strong><br>
				<?php
				printf(
					/* translators: %d: nombre de demandes. */
					esc_html( _n( '%d annonce attend la validation d’une ville ou d’un quartier. Elle reste hors ligne d’ici là.', '%d annonces attendent la validation d’une ville ou d’un quartier. Elles restent hors ligne d’ici là.', count( $pending ), 'partikulier' ) ),
					count( $pending )
				);
				?>
			</p>
			<p>
				<a class="button button-primary" href="<?php echo esc_url( admin_url( 'admin.php?page=pk-place-requests' ) ); ?>">
					<?php esc_html_e( 'Examiner les demandes', 'partikulier' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Ecran de moderation.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'partikulier' ), '', array( 'response' => 403 ) );
		}

		$rows = array_reverse( self::all() );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Lieux proposés par les annonceurs', 'partikulier' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Un annonceur n’a pas trouvé sa ville ou son quartier dans la liste. Rien n’est créé tant que vous n’avez pas validé : l’annonce concernée reste hors ligne.', 'partikulier' ); ?>
			</p>

			<?php if ( ! $rows ) : ?>
				<p><em><?php esc_html_e( 'Aucune demande pour le moment.', 'partikulier' ); ?></em></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Lieu proposé', 'partikulier' ); ?></th>
							<th><?php esc_html_e( 'Annonce', 'partikulier' ); ?></th>
							<th><?php esc_html_e( 'Reçue le', 'partikulier' ); ?></th>
							<th><?php esc_html_e( 'Statut', 'partikulier' ); ?></th>
							<th><?php esc_html_e( 'Décision', 'partikulier' ); ?></th>
						</tr>
					</thead>
					<tbody>
					<?php foreach ( $rows as $row ) :
						$post   = get_post( $row['post_id'] );
						$place  = $row['district'] ? $row['district'] . ' — ' . $row['city'] : $row['city'];
						$labels = array(
							self::STATUS_PENDING  => __( 'En attente', 'partikulier' ),
							self::STATUS_APPROVED => __( 'Validé', 'partikulier' ),
							self::STATUS_REJECTED => __( 'Refusé', 'partikulier' ),
						);
						?>
						<tr>
							<td><strong><?php echo esc_html( $place ); ?></strong></td>
							<td>
								<?php if ( $post ) : ?>
									<a href="<?php echo esc_url( get_edit_post_link( $post->ID ) ); ?>"><?php echo esc_html( $post->post_title ); ?></a>
								<?php else : ?>
									<em><?php esc_html_e( 'annonce supprimée', 'partikulier' ); ?></em>
								<?php endif; ?>
							</td>
							<td><?php echo esc_html( $row['created'] ); ?></td>
							<td><?php echo esc_html( isset( $labels[ $row['status'] ] ) ? $labels[ $row['status'] ] : $row['status'] ); ?></td>
							<td>
								<?php if ( self::STATUS_PENDING === $row['status'] ) : ?>
									<a class="button button-primary" href="<?php echo esc_url( self::decision_url( $row['id'], 'approve' ) ); ?>"><?php esc_html_e( 'Créer le lieu et publier', 'partikulier' ); ?></a>
									<a class="button" href="<?php echo esc_url( self::decision_url( $row['id'], 'reject' ) ); ?>"><?php esc_html_e( 'Refuser', 'partikulier' ); ?></a>
								<?php else : ?>
									<span aria-hidden="true">—</span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * URL signee d'une decision.
	 *
	 * @param string $id     Identifiant de la demande.
	 * @param string $action approve | reject.
	 * @return string
	 */
	private static function decision_url( $id, $action ) {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=pk_place_decision&request=' . rawurlencode( $id ) . '&decision=' . $action ),
			'pk_place_decision_' . $id
		);
	}

	/**
	 * Applique la decision de l'administrateur.
	 */
	public static function handle_decision() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'partikulier' ), '', array( 'response' => 403 ) );
		}

		$id       = isset( $_GET['request'] ) ? sanitize_text_field( wp_unslash( $_GET['request'] ) ) : '';
		$decision = isset( $_GET['decision'] ) ? sanitize_key( wp_unslash( $_GET['decision'] ) ) : '';
		check_admin_referer( 'pk_place_decision_' . $id );

		$rows = self::all();
		if ( ! isset( $rows[ $id ] ) ) {
			wp_safe_redirect( admin_url( 'admin.php?page=pk-place-requests' ) );
			exit;
		}

		$row     = $rows[ $id ];
		$post_id = (int) $row['post_id'];

		if ( 'approve' === $decision ) {
			// Creation reelle du terme, puis rattachement de l'annonce.
			$term_id = Partikulier_Morocco_Places::ensure_location_term( $row['city'], $row['district'] );
			if ( $term_id && $post_id ) {
				wp_set_object_terms( $post_id, (int) $term_id, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, true );
				update_post_meta( $post_id, self::META_STATUS, self::STATUS_APPROVED );
				delete_post_meta( $post_id, self::META_PENDING );
			}
			$rows[ $id ]['status'] = self::STATUS_APPROVED;
		} elseif ( 'reject' === $decision ) {
			// Aucun terme cree : l'annonce reste bloquee.
			if ( $post_id ) {
				update_post_meta( $post_id, self::META_STATUS, self::STATUS_REJECTED );
			}
			$rows[ $id ]['status'] = self::STATUS_REJECTED;
		}

		update_option( self::OPTION, $rows, false );

		if ( class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
			Partikulier_Cache::purge_all();
		}

		wp_safe_redirect( admin_url( 'admin.php?page=pk-place-requests' ) );
		exit;
	}
}

Partikulier_Place_Requests::init();
