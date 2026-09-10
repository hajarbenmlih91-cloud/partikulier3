<?php
/**
 * Module : espace du proprietaire (mes annonces).
 *
 * - Actions AJAX : marquer vendu / loue / reactiver / supprimer (mise a la corbeille)
 * - Seul le proprietaire de l'annonce (ou un admin) peut agir
 * - Statistiques : nombre d'annonces, vues totales
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Dashboard {

	public static function init() {
		add_action( 'wp_ajax_pk_manage_listing', array( __CLASS__, 'handle_manage' ) );
		add_action( 'wp_ajax_pk_views_counter', array( __CLASS__, 'handle_views_counter' ) );
		add_action( 'wp_ajax_nopriv_pk_views_counter', array( __CLASS__, 'handle_views_counter' ) );
		add_action( 'pre_get_posts', array( __CLASS__, 'exclude_closed_from_public_lists' ) );
	}

	/**
	 * Les URLs d'annonces closes restent publiques, mais n'apparaissent plus dans les listes de biens disponibles.
	 */
	public static function active_listing_meta_query() {
		return array(
			'relation' => 'OR',
			array(
				'key'     => '_pk_status',
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => '_pk_status',
				'value' => 'actif',
			),
		);
	}

	/**
	 * Ajoute le filtre de disponibilite aux archives et taxonomies Estatik publiques.
	 */
	public static function exclude_closed_from_public_lists( $query ) {
		if ( is_admin() || ! $query->is_main_query() || $query->is_singular() ) {
			return;
		}

		$post_type     = $query->get( 'post_type' );
		$is_properties = PARTIKULIER_ESTATIK_POST_TYPE === $post_type || ( is_array( $post_type ) && in_array( PARTIKULIER_ESTATIK_POST_TYPE, $post_type, true ) ) || $query->is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE );
		if ( ! $is_properties ) {
			return;
		}

		$meta_query   = (array) $query->get( 'meta_query' );
		$meta_query[] = self::active_listing_meta_query();
		$query->set( 'meta_query', $meta_query );
	}

	/**
	 * Compteur de vues : increment unique par visiteur (cookie 24h).
	 */
	public static function handle_views_counter() {
		check_ajax_referer( 'pk_views_counter', 'nonce' );
		if ( empty( $_POST['post_id'] ) ) {
			wp_send_json_error( array( 'message' => 'ID manquant.' ), 400 );
		}
		$post_id = absint( $_POST['post_id'] );
		if ( get_post_type( $post_id ) !== PARTIKULIER_ESTATIK_POST_TYPE || 'publish' !== get_post_status( $post_id ) ) {
			wp_send_json_error( array( 'message' => 'Annonce introuvable.' ), 404 );
		}

		// Cookie unique par visiteur (24 h) pour eviter le matraquage.
		$cookie_key = 'pk_v_' . $post_id;
			if ( ! isset( $_COOKIE[ $cookie_key ] ) ) {
				$views = (int) get_post_meta( $post_id, '_pk_views', true );
				update_post_meta( $post_id, '_pk_views', $views + 1 );
				setcookie( $cookie_key, '1', array(
					'expires'  => time() + DAY_IN_SECONDS,
					'path'     => COOKIEPATH ? COOKIEPATH : '/',
					'domain'   => COOKIE_DOMAIN,
					'secure'   => is_ssl(),
					'httponly' => true,
					'samesite' => 'Lax',
				) );
			}

		wp_send_json_success( array( 'views' => (int) get_post_meta( $post_id, '_pk_views', true ) ) );
	}

	/**
	 * Gestion des annonces par le proprietaire (AJAX).
	 * Actions : mark_sold, mark_rented, reactivate, delete.
	 */
	public static function handle_manage() {
		check_ajax_referer( 'pk_manage_listing', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Connectez-vous pour gérer vos annonces.', 'partikulier' ) ), 401 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$action  = isset( $_POST['manage_action'] ) ? sanitize_text_field( wp_unslash( $_POST['manage_action'] ) ) : '';
		$result  = self::manage_listing( $post_id, $action, get_current_user_id() );
		if ( is_wp_error( $result ) ) {
			$pk_err_data = $result->get_error_data();
			$pk_status   = is_array( $pk_err_data ) && isset( $pk_err_data['status'] ) ? (int) $pk_err_data['status'] : 400;
			wp_send_json_error( array( 'message' => $result->get_error_message() ), $pk_status );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Action métier réutilisable par AJAX et l’API REST propriétaire.
	 */
	public static function manage_listing( $post_id, $action, $user_id = 0 ) {
		$post_id = absint( $post_id );
		$action  = sanitize_key( $action );
		$user_id = absint( $user_id );

		if ( ! $post_id || get_post_type( $post_id ) !== PARTIKULIER_ESTATIK_POST_TYPE ) {
			return new WP_Error( 'pk_listing_missing', __( 'Annonce introuvable.', 'partikulier' ), array( 'status' => 404 ) );
		}

		// Seul le proprietaire ou un admin peut agir.
		$allowed_actions = array( 'mark_sold', 'mark_rented', 'pause', 'reactivate', 'archive', 'delete' );
		if ( ! in_array( $action, $allowed_actions, true ) ) {
			return new WP_Error( 'pk_listing_action_invalid', __( 'Action invalide.', 'partikulier' ), array( 'status' => 400 ) );
		}
		$is_owner = $user_id === (int) get_post_field( 'post_author', $post_id );
		if ( ! $is_owner && ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'pk_listing_forbidden', __( 'Vous n’êtes pas autorisé à modifier cette annonce.', 'partikulier' ), array( 'status' => 403 ) );
		}

		$current_status = get_post_meta( $post_id, '_pk_status', true );
		if ( class_exists( 'Partikulier_WhatsApp_Verification' ) && Partikulier_WhatsApp_Verification::STATUS_PENDING === $current_status && ! in_array( $action, array( 'archive', 'delete' ), true ) ) {
			return new WP_Error( 'pk_listing_pending_whatsapp', __( 'Cette annonce attend encore la validation WhatsApp de l’équipe.', 'partikulier' ), array( 'status' => 409 ) );
		}

		switch ( $action ) {
			case 'mark_sold':
				update_post_meta( $post_id, '_pk_status', 'vendu' );
				update_post_meta( $post_id, '_pk_closed_reason', 'vendu' );
				update_post_meta( $post_id, '_pk_closed_at', current_time( 'mysql', true ) );
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
				$message = __( 'Annonce marquée comme vendue.', 'partikulier' );
				break;
			case 'mark_rented':
				update_post_meta( $post_id, '_pk_status', 'loue' );
				update_post_meta( $post_id, '_pk_closed_reason', 'loue' );
				update_post_meta( $post_id, '_pk_closed_at', current_time( 'mysql', true ) );
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
				$message = __( 'Annonce marquée comme louée.', 'partikulier' );
				break;
			case 'pause':
				update_post_meta( $post_id, '_pk_status', 'pause' );
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'draft' ) );
				$message = __( 'Annonce mise en pause.', 'partikulier' );
				break;
			case 'reactivate':
				if ( 'trash' === get_post_status( $post_id ) ) {
					wp_untrash_post( $post_id );
				} else {
					wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
				}
				update_post_meta( $post_id, '_pk_status', 'actif' );
				delete_post_meta( $post_id, '_pk_closed_reason' );
				delete_post_meta( $post_id, '_pk_closed_at' );
				$message = __( 'Annonce réactivée.', 'partikulier' );
				break;
			case 'archive':
			case 'delete':
				update_post_meta( $post_id, '_pk_status', 'archive' );
				update_post_meta( $post_id, '_pk_closed_reason', 'archive' );
				update_post_meta( $post_id, '_pk_closed_at', current_time( 'mysql', true ) );
				wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );
				$message = __( 'Annonce retirée des résultats et conservée comme archive publique.', 'partikulier' );
				break;
		}

		// Purge du cache (page d'annonce modifiee).
		if ( class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
			Partikulier_Cache::purge_all();
		}

		return array( 'message' => $message, 'status' => get_post_meta( $post_id, '_pk_status', true ) );
	}

}

Partikulier_Dashboard::init();