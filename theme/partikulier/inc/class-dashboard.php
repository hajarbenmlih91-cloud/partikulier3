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
         * SE-044 / DP-9 (v1.1 §5.1) : harmonisation du prédicat — la méta vide ('')
         * est désormais DISPONIBLE (absente OU vide OU actif), alignée sur les
         * similaires et le prédicat central du plugin (changement annoncé du
         * comportement du thème, couvert par T29).
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
                                'value' => array( '', 'actif' ),
                                'compare' => 'IN',
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
         * SE-044 / DP-9 : parcours unique « Désactiver (motif) » / « Réactiver » —
         * les anciennes actions reçoivent 400 pk_listing_action_retired.
	 */

	public static function handle_manage() {
		check_ajax_referer( 'pk_manage_listing', 'nonce' );
		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'Connectez-vous pour gérer vos annonces.', 'partikulier' ) ), 401 );
		}

		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		$action  = isset( $_POST['manage_action'] ) ? sanitize_text_field( wp_unslash( $_POST['manage_action'] ) ) : '';
                $reason  = isset( $_POST['reason'] ) ? sanitize_text_field( wp_unslash( $_POST['reason'] ) ) : '';
                $note    = isset( $_POST['note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['note'] ) ) : '';
                $result  = self::manage_listing( $post_id, $action, get_current_user_id(), $reason, $note );
		if ( is_wp_error( $result ) ) {
			$pk_err_data = $result->get_error_data();
			$pk_status   = is_array( $pk_err_data ) && isset( $pk_err_data['status'] ) ? (int) $pk_err_data['status'] : 400;
                        wp_send_json_error( array(
                                'code'         => $result->get_error_code(),
                                'message'      => $result->get_error_message(),
                                'failed_step'  => is_array( $pk_err_data ) && isset( $pk_err_data['failed_step'] ) ? $pk_err_data['failed_step'] : null,
                                'report'       => is_array( $pk_err_data ) && isset( $pk_err_data['report'] ) ? $pk_err_data['report'] : null,
                        ), $pk_status );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Action métier réutilisable par AJAX et l’API REST propriétaire.
         * SE-044 / DP-9 : le moteur de transitions (matrice, résolution canonique,
         * précontrôle de groupe, compare–write–verify) est porté par
         * Partikulier_Listing_Transitions — cette méthode reste le point d'entrée
         * partagé des DEUX entrées serveur (AJAX et REST owner-insights).
	 */
        public static function manage_listing( $post_id, $action, $user_id = 0, $reason = '', $note = '' ) {
                if ( ! class_exists( 'Partikulier_Listing_Transitions' ) ) {
                        return new WP_Error( 'pk_transitions_unavailable', __( 'Le parcours de gestion d’annonce est indisponible.', 'partikulier' ), array( 'status' => 500 ) );
                }
                return Partikulier_Listing_Transitions::transition( $post_id, $action, $user_id, $reason, $note );
	}

}

Partikulier_Dashboard::init();