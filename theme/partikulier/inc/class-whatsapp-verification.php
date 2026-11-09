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
	const TEAM_WHATSAPP_NUMBER = '212640424412';
	const AUTH_PENDING_TTL = 900;

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'add_meta_box' ) );
		add_action( 'admin_post_pk_verify_whatsapp', array( __CLASS__, 'handle_admin_verify' ) );
		add_action( 'wp_ajax_pk_whatsapp_match_status', array( __CLASS__, 'ajax_match_status' ) );
		add_action( 'wp_ajax_nopriv_pk_whatsapp_match_status', array( __CLASS__, 'ajax_match_status' ) );
		add_action( 'wp_ajax_pk_whatsapp_auth_start', array( __CLASS__, 'ajax_start_auth' ) );
		add_action( 'wp_ajax_nopriv_pk_whatsapp_auth_start', array( __CLASS__, 'ajax_start_auth' ) );
	}

	/**
	 * Retourne le numéro WhatsApp de l’équipe, normalisé pour wa.me.
	 *
	 * @return string
	 */
	public static function validation_number() {
		return self::TEAM_WHATSAPP_NUMBER;
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
		$user_id = (int) get_post_field( 'post_author', $post_id );
		update_post_meta( $post_id, '_pk_whatsapp_user_id', $user_id );
		update_post_meta( $post_id, '_pk_whatsapp_auth_destination', 'listings' );

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
			$template  = isset( $templates[ $lang ] ) ? $templates[ $lang ] : $templates['fr'];
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
				'{code}'  => $code,
				'{titre}' => $post ? $post->post_title : '',
				'{ville}' => $city,
				'{prix}'  => $price ? ( function_exists( 'pk_format_amount' ) ? pk_format_amount( $price ) : (string) (int) $price ) . ' DH' : '',
				'{lien}'  => $post ? get_permalink( $post ) : '',
				'{nom}'   => $post ? (string) get_post_meta( $post->ID, '_pk_owner_name', true ) : '',
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

	/**
	 * URL interrogée par la page laissée ouverte. Ne contient pas le numéro.
	 *
	 * @param string $code Code de rapprochement déjà affiché sur cette page.
	 * @return string
	 */
	public static function status_url( $code ) {
		return add_query_arg(
			array(
				'action' => 'pk_whatsapp_match_status',
				'code'   => (string) $code,
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Note qu'un message prérempli est arrivé. N'envoie aucune réponse WhatsApp.
	 *
	 * @param string $code                Code présent dans le message.
	 * @param string $provider_message_id Identifiant du message reçu.
	 * @return bool
	 */
	/**
	 * Normalise un numéro mobile marocain sans enregistrer de numéro externe.
	 */
	public static function normalize_phone_number( $phone ) {
		$digits = preg_replace( '/\\D+/', '', (string) $phone );
		if ( 0 === strpos( $digits, '00' ) ) {
			$digits = substr( $digits, 2 );
		}
		if ( 0 === strpos( $digits, '0' ) ) {
			$digits = '212' . substr( $digits, 1 );
		}
		return preg_match( '/^212[5-7][0-9]{8}$/', $digits ) ? $digits : '';
	}

	private static function auth_transient_key( $code ) {
		return 'pk_wa_auth_' . hash( 'sha256', (string) $code );
	}

	/**
	 * Deux vues du même espace : l'accueil du profil, et les annonces du profil.
	 * Le dépôt n'ouvre pas l'accueil.
	 */
	public static function profile_destination_url( $screen ) {
		$base = function_exists( 'pk_page_url' ) ? pk_page_url( 'mes-annonces', '/mes-annonces/' ) : home_url( '/mes-annonces/' );
		$view = 'listings' === $screen ? 'annonces' : 'accueil';
		return add_query_arg( 'pk_ecran', $view, $base );
	}

	private static function auth_profile_url() {
		return self::profile_destination_url( 'profile' );
	}

	private static function listings_profile_url() {
		return self::profile_destination_url( 'listings' );
	}

	private static function find_user_by_phone( $phone ) {
		$phone = self::normalize_phone_number( $phone );
		if ( ! $phone ) {
			return false;
		}
		$local = '0' . substr( $phone, 3 );
		foreach ( array( $phone, $local ) as $login ) {
			$user = get_user_by( 'login', $login );
			if ( $user instanceof WP_User ) {
				return $user;
			}
			$user = get_user_by( 'email', $login . '@partikulier.local' );
			if ( $user instanceof WP_User ) {
				return $user;
			}
		}
		$users = get_users( array(
			'meta_query' => array(
				'relation' => 'OR',
				array( 'key' => '_pk_owner_phone_clean', 'value' => $phone, 'compare' => '=' ),
				array( 'key' => '_pk_owner_phone_clean', 'value' => $local, 'compare' => '=' ),
			),
			'number' => 1,
			'orderby' => 'ID',
			'order' => 'ASC',
		) );
		return ! empty( $users ) && $users[0] instanceof WP_User ? $users[0] : false;
	}

	/** Lance une demande de connexion ou de création, sans ouvrir de session. */
	public static function begin_auth( $phone ) {
		$phone = self::normalize_phone_number( $phone );
		if ( ! $phone ) {
			return new WP_Error( 'pk_whatsapp_phone_invalid', __( 'Saisissez un numéro mobile marocain valide.', 'partikulier' ) );
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : 'unknown'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- utilisé uniquement pour une clé hachée de quota
		$rate_key = 'pk_wa_auth_rate_' . hash( 'sha256', $phone . '|' . $ip );
		$attempts = (int) get_transient( $rate_key );
		if ( $attempts >= 6 ) {
			return new WP_Error( 'pk_whatsapp_auth_rate', __( 'Trop de demandes. Réessayez plus tard.', 'partikulier' ) );
		}
		set_transient( $rate_key, $attempts + 1, HOUR_IN_SECONDS );
		$code = 'PKA-' . strtoupper( bin2hex( random_bytes( 16 ) ) );
		$user = self::find_user_by_phone( $phone );
		$record = array(
			'kind' => 'auth',
			'phone' => $phone,
			'user_id' => $user ? (int) $user->ID : 0,
			'matched_at' => '',
			'provider_message_stamp' => '',
		);
		set_transient( self::auth_transient_key( $code ), $record, self::AUTH_PENDING_TTL );
		$message = 'Bonjour, je confirme mon numéro pour accéder à mon espace Partikulier. Code : ' . $code;
		return array(
			'code' => $code,
			'status_url' => self::status_url( $code ),
			'whatsapp_url' => 'https://wa.me/' . self::TEAM_WHATSAPP_NUMBER . '?text=' . rawurlencode( $message ),
			'profile_url' => self::auth_profile_url(),
		);
	}

	public static function auth_widget() {
		ob_start(); ?>
		<form id="pk-whatsapp-auth-form" class="pk-whatsapp-auth-form" method="post" action="#" data-ajax-url="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>">
			<label for="pk-whatsapp-auth-phone"><?php esc_html_e( 'Numéro mobile marocain', 'partikulier' ); ?></label>
			<input id="pk-whatsapp-auth-phone" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="06 40 42 44 12" required>
			<input type="hidden" name="nonce" value="<?php echo esc_attr( wp_create_nonce( 'pk_whatsapp_auth' ) ); ?>">
			<button class="pk-btn pk-btn-primary" type="submit"><?php esc_html_e( 'Continuer avec WhatsApp', 'partikulier' ); ?></button>
			<p class="pk-form-note" data-pk-auth-status aria-live="polite"><?php esc_html_e( 'Le même espace est utilisé pour acheter et déposer une annonce.', 'partikulier' ); ?></p>
		</form>
		<?php return ob_get_clean();
	}

	public static function ajax_start_auth() {
		check_ajax_referer( 'pk_whatsapp_auth', 'nonce' );
		$raw_phone = isset( $_POST['phone'] ) ? sanitize_text_field( wp_unslash( $_POST['phone'] ) ) : '';
		$result = self::begin_auth( $raw_phone );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Rapproche seulement un code signé dont le numéro émetteur correspond au
	 * numéro déclaré. L'événement ne crée ni session navigateur ni réponse WhatsApp.
	 */
	public static function mark_inbound_matched( $code, $provider_message_id, $sender_phone = '' ) {
		$sender_phone = self::normalize_phone_number( $sender_phone );
		if ( ! $sender_phone || '' === (string) $provider_message_id ) {
			return false;
		}
		$auth_key = self::auth_transient_key( $code );
		$auth = get_transient( $auth_key );
		if ( is_array( $auth ) && ( $auth['kind'] ?? '' ) === 'auth' ) {
			if ( ! hash_equals( (string) ( $auth['phone'] ?? '' ), $sender_phone ) ) {
				return false;
			}
			$user = ! empty( $auth['user_id'] ) ? get_user_by( 'id', (int) $auth['user_id'] ) : self::find_user_by_phone( $sender_phone );
			if ( ! $user ) {
				$user = self::create_shared_profile( $sender_phone );
			}
			if ( ! $user || is_wp_error( $user ) ) {
				return false;
			}
			$auth['user_id'] = (int) $user->ID;
			$auth['matched_at'] = current_time( 'mysql', true );
			$auth['provider_message_stamp'] = substr( hash( 'sha256', (string) $provider_message_id ), 0, 32 );
			set_transient( $auth_key, $auth, HOUR_IN_SECONDS );
			update_user_meta( $user->ID, '_pk_whatsapp_verified_at', $auth['matched_at'] );
			return true;
		}

		$post_id = self::post_id_for_code( $code );
		if ( ! $post_id ) {
			return false;
		}
		$user_id = (int) get_post_field( 'post_author', $post_id );
		$expected_phone = self::normalize_phone_number( get_user_meta( $user_id, '_pk_owner_phone_clean', true ) );
		if ( ! $expected_phone || ! hash_equals( $expected_phone, $sender_phone ) ) {
			return false;
		}
		if ( ! get_post_meta( $post_id, '_pk_whatsapp_inbound_matched_at', true ) ) {
			update_post_meta( $post_id, '_pk_whatsapp_inbound_matched_at', current_time( 'mysql', true ) );
		}
		update_post_meta( $post_id, '_pk_whatsapp_inbound_message_stamp', substr( hash( 'sha256', (string) $provider_message_id ), 0, 32 ) );
		return true;
	}

	private static function create_shared_profile( $phone ) {
		$phone = self::normalize_phone_number( $phone );
		if ( ! $phone ) return false;
		$existing = self::find_user_by_phone( $phone );
		if ( $existing ) return $existing;
		$email = $phone . '@partikulier.local';
		if ( email_exists( $email ) || username_exists( $phone ) ) return false;
		// Ces comptes à mot de passe aléatoire sont finalisés via WhatsApp; aucune
		// notification e-mail de création ne doit partir (ni vers l'admin ni l'utilisateur).
		$block_email = static function () { return false; };
		add_filter( 'wp_new_user_notification_email_admin', $block_email );
		add_filter( 'wp_new_user_notification_email', $block_email );
		try {
			$user_id = wp_create_user( $phone, wp_generate_password( 32, true, true ), $email );
		} finally {
			remove_filter( 'wp_new_user_notification_email_admin', $block_email );
			remove_filter( 'wp_new_user_notification_email', $block_email );
		}
		if ( is_wp_error( $user_id ) ) return false;
		$user = new WP_User( $user_id );
		$user->set_role( 'subscriber' );
		update_user_meta( $user_id, '_pk_owner_phone_clean', $phone );
		update_user_meta( $user_id, '_pk_owner_phone_raw', '+' . $phone );
		update_user_meta( $user_id, '_pk_whatsapp_verified_at', current_time( 'mysql', true ) );
		wp_update_user( array( 'ID' => $user_id, 'display_name' => '+' . $phone ) );
		return get_user_by( 'id', $user_id );
	}

	private static function post_id_for_code( $code ) {
		$code = sanitize_text_field( (string) $code );
		if ( '' === $code || ! defined( 'PARTIKULIER_ESTATIK_POST_TYPE' ) ) return 0;
		global $wpdb;
		if ( ! $wpdb instanceof wpdb ) return 0;
		$post_id = $wpdb->get_var( $wpdb->prepare(
			"SELECT p.ID FROM {$wpdb->posts} AS p INNER JOIN {$wpdb->postmeta} AS pm ON pm.post_id = p.ID WHERE p.post_type = %s AND p.post_status NOT IN ('trash', 'auto-draft') AND pm.meta_key = %s AND pm.meta_value = %s ORDER BY p.ID ASC LIMIT 1",
			PARTIKULIER_ESTATIK_POST_TYPE, '_pk_whatsapp_verification_code', $code
		) );
		return absint( $post_id );
	}

	public static function status_for_code( $code ) {
		$post_id = self::post_id_for_code( $code );
		if ( $post_id ) {
			$matched = (bool) get_post_meta( $post_id, '_pk_whatsapp_inbound_matched_at', true );
			return array( 'matched' => $matched, 'url' => $matched ? self::listings_profile_url() : '', 'screen' => 'listings' );
		}
		$auth = get_transient( self::auth_transient_key( $code ) );
		$matched = is_array( $auth ) && ! empty( $auth['matched_at'] ) && ! empty( $auth['user_id'] );
		return array( 'matched' => $matched, 'url' => $matched ? self::auth_profile_url() : '', 'screen' => 'profile' );
	}

	private static function establish_browser_session( $code ) {
		$post_id = self::post_id_for_code( $code );
		if ( $post_id ) {
			if ( ! get_post_meta( $post_id, '_pk_whatsapp_inbound_matched_at', true ) ) return false;
			$user_id = (int) get_post_field( 'post_author', $post_id );
		} else {
			$auth = get_transient( self::auth_transient_key( $code ) );
			if ( ! is_array( $auth ) || empty( $auth['matched_at'] ) ) return false;
			$user_id = (int) ( $auth['user_id'] ?? 0 );
		}
		$user = get_user_by( 'id', $user_id );
		if ( ! $user instanceof WP_User ) return false;
		wp_set_current_user( $user_id );
		wp_set_auth_cookie( $user_id, true, is_ssl() );
		do_action( 'wp_login', $user->user_login, $user );
		return true;
	}

	public static function ajax_match_status() {
		$code = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$status = self::status_for_code( $code );
		if ( ! empty( $status['matched'] ) && ! self::establish_browser_session( $code ) ) {
			$status['matched'] = false;
			$status['url'] = '';
		}
		nocache_headers();
		wp_send_json_success( $status );
	}

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
		$status       = get_post_meta( $post->ID, '_pk_status', true );
		$code         = get_post_meta( $post->ID, '_pk_whatsapp_verification_code', true );
		$requested_at = get_post_meta( $post->ID, '_pk_whatsapp_validation_requested_at', true );
		$verified_at  = get_post_meta( $post->ID, '_pk_whatsapp_verified_at', true );

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