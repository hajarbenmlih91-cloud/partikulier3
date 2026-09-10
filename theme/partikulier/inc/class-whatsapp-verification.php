<?php
/**
 * Workflow de validation WhatsApp avant publication.
 *
 * Un lien wa.me ne peut pas confirmer automatiquement qu’un message a été
 * envoyé ni qu’un numéro appartient à une personne. Cette classe conserve donc
 * une annonce en attente, génère un code de rapprochement et exige une action
 * explicite de l’équipe après réception du message WhatsApp.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_WhatsApp_Verification {

	const STATUS_PENDING = 'en_attente_whatsapp';

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_pk_verify_whatsapp', array( __CLASS__, 'handle_admin_verify' ) );
	}

	/**
	 * Retourne le numéro WhatsApp de l’équipe, normalisé pour wa.me.
	 *
	 * @return string
	 */
	public static function validation_number() {
		$raw = Partikulier_Settings::get( 'whatsapp_validation_number' );
		return preg_replace( '/\D+/', '', (string) $raw );
	}

	/**
	 * La publication par validation n’est disponible qu’après paramétrage du numéro.
	 *
	 * @return bool
	 */
	public static function is_configured() {
		return (bool) self::validation_number();
	}

	/**
	 * Crée le code de rapprochement à communiquer dans WhatsApp.
	 *
	 * @param int $post_id Identifiant de l’annonce.
	 * @return array{code:string,url:string}
	 */
	public static function create_pending( $post_id ) {
		$post_id = absint( $post_id );
		$code    = 'PK-' . $post_id . '-' . strtoupper( wp_generate_password( 7, false, false ) );

		update_post_meta( $post_id, '_pk_whatsapp_verification_code', $code );
		update_post_meta( $post_id, '_pk_whatsapp_validation_requested_at', current_time( 'mysql', true ) );
		update_post_meta( $post_id, '_pk_status', self::STATUS_PENDING );

		return array(
			'code' => $code,
			'url'  => self::verification_url( $post_id, $code ),
		);
	}

	/**
	 * Construit le lien wa.me avec un texte de validation sans donnée sensible.
	 *
	 * @param int    $post_id Identifiant de l’annonce.
	 * @param string $code    Code de rapprochement.
	 * @return string
	 */
	public static function verification_url( $post_id, $code = '' ) {
		$number = self::validation_number();
		if ( ! $number ) {
			return '';
		}

		$code = $code ? $code : get_post_meta( absint( $post_id ), '_pk_whatsapp_verification_code', true );
		$post = get_post( absint( $post_id ) );

		// Message personnalisable depuis Apparence › Personnaliser.
			$template = class_exists( 'Partikulier_Settings' )
				? (string) Partikulier_Settings::get( 'whatsapp_message' )
				: '';

			$default_template = 'Bonjour, je souhaite valider ma demande de publication Partikulier. Mon code est : {code}';
			if ( '' === trim( $template ) || $default_template === $template ) {
				$lang = function_exists( 'pll_get_post_language' ) ? sanitize_key( (string) pll_get_post_language( $post_id, 'slug' ) ) : '';
				if ( ! $lang ) {
					$lang = function_exists( 'pll_current_language' ) ? sanitize_key( (string) pll_current_language( 'slug' ) ) : 'fr';
				}
				$templates = array(
					'fr' => $default_template,
					'en' => 'Hello, I would like to validate my Partikulier listing publication request. My code is: {code}',
					'ar' => 'مرحباً، أريد التحقق من طلب نشر إعلاني على بارتكولييه. الرمز الخاص بي هو: {code}',
				);
				$template = isset( $templates[ $lang ] ) ? $templates[ $lang ] : $templates['fr'];
			}

		$city = '';
		if ( $post ) {
			$terms = wp_get_object_terms( $post->ID, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
			if ( $terms && ! is_wp_error( $terms ) ) {
				$city = $terms[0]->name;
			}
		}

		$price = $post ? get_post_meta( $post->ID, 'es_property_price', true ) : '';

		$text = strtr(
			$template,
			array(
				'{code}'   => $code,
				'{titre}'  => $post ? $post->post_title : '',
				'{ville}'  => $city,
				'{prix}'   => $price ? number_format_i18n( (int) $price ) . ' MAD' : '',
				'{lien}'   => $post ? get_permalink( $post ) : '',
				'{nom}'    => $post ? (string) get_post_meta( $post->ID, '_pk_owner_name', true ) : '',
			)
		);

		return 'https://wa.me/' . rawurlencode( $number ) . '?text=' . rawurlencode( $text );
	}

	/**
	 * Fournit au navigateur les données minimales du parcours de validation.
	 *
	 * @param int $post_id Identifiant de l’annonce.
	 * @return array{code:string,url:string}
	 */
	public static function pending_payload( $post_id ) {
		$code = get_post_meta( absint( $post_id ), '_pk_whatsapp_verification_code', true );
		return array(
			'code' => $code,
			'url'  => self::verification_url( $post_id, $code ),
		);
	}

	/**
	 * Affiche l’action réservée à l’équipe dans l’éditeur WordPress.
	 */
	public static function add_meta_box() {
		add_meta_box(
			'pk_whatsapp_verification',
			__( 'Validation WhatsApp Partikulier', 'partikulier' ),
			array( __CLASS__, 'render_meta_box' ),
			PARTIKULIER_ESTATIK_POST_TYPE,
			'side',
			'high'
		);
	}

	/**
	 * @param WP_Post $post Annonce courante.
	 */
	public static function render_meta_box( $post ) {
		$status      = get_post_meta( $post->ID, '_pk_status', true );
		$code        = get_post_meta( $post->ID, '_pk_whatsapp_verification_code', true );
		$requested_at = get_post_meta( $post->ID, '_pk_whatsapp_validation_requested_at', true );
		$verified_at = get_post_meta( $post->ID, '_pk_whatsapp_verified_at', true );

		if ( self::STATUS_PENDING === $status ) {
			echo '<p><strong>' . esc_html__( 'En attente de rapprochement manuel.', 'partikulier' ) . '</strong></p>';
			echo '<p>' . esc_html__( 'Vérifiez dans WhatsApp que le message reçu contient le code ci-dessous avant de publier.', 'partikulier' ) . '</p>';
			echo '<p><code>' . esc_html( $code ) . '</code></p>';
			if ( $requested_at ) {
				echo '<p><small>' . esc_html( sprintf( __( 'Demandé le %s', 'partikulier' ), get_date_from_gmt( $requested_at, 'd/m/Y H:i' ) ) ) . '</small></p>';
			}
			$url = wp_nonce_url( admin_url( 'admin-post.php?action=pk_verify_whatsapp&post_id=' . $post->ID ), 'pk_verify_whatsapp_' . $post->ID );
			echo '<p><a class="button button-primary" href="' . esc_url( $url ) . '">' . esc_html__( 'Valider et publier', 'partikulier' ) . '</a></p>';
			return;
		}

		if ( $verified_at ) {
			echo '<p><strong>' . esc_html__( 'Validation WhatsApp enregistrée.', 'partikulier' ) . '</strong></p>';
			echo '<p><small>' . esc_html( get_date_from_gmt( $verified_at, 'd/m/Y H:i' ) ) . '</small></p>';
			return;
		}

		echo '<p>' . esc_html__( 'Aucune validation WhatsApp en attente pour cette annonce.', 'partikulier' ) . '</p>';
	}

	/**
	 * Publie l’annonce uniquement après rapprochement manuel par un administrateur.
	 */
	public static function handle_admin_verify() {
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
			wp_die( esc_html__( 'Vous n’êtes pas autorisé à valider cette annonce.', 'partikulier' ), 403 );
		}

		check_admin_referer( 'pk_verify_whatsapp_' . $post_id );
		if ( self::STATUS_PENDING !== get_post_meta( $post_id, '_pk_status', true ) ) {
			wp_safe_redirect( get_edit_post_link( $post_id, 'url' ) );
			exit;
		}

		update_post_meta( $post_id, '_pk_status', 'actif' );
		update_post_meta( $post_id, '_pk_whatsapp_verified_at', current_time( 'mysql', true ) );
		update_post_meta( $post_id, '_pk_whatsapp_verified_by', get_current_user_id() );
		wp_update_post( array( 'ID' => $post_id, 'post_status' => 'publish' ) );

		// Les versions arabe et anglaise partent en ligne en meme temps.
		if ( class_exists( 'Partikulier_Listing_Translations' ) ) {
			Partikulier_Listing_Translations::sync_status( $post_id, 'publish' );
		}

		wp_safe_redirect( add_query_arg( 'pk_whatsapp_verified', '1', get_edit_post_link( $post_id, 'url' ) ) );
		exit;
	}
}

Partikulier_WhatsApp_Verification::init();