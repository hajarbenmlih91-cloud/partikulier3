<?php
/**
 * Formulaire de la page Contact.
 *
 * Nom, e-mail, message, case dont la phrase vient des pages légales.
 * L’arabe de cette phrase n’est pas écrit : sur une page arabe, elle reste
 * le français, marqué comme français. Le message part à contact@pep.ma.
 * Il n’est pas enregistré dans WordPress.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Contact {

	const ACTION = 'pk_site_contact';

	const CONSENT_FR = 'J’accepte que pep.ma utilise ce message pour me répondre.';

	const CONSENT_EN = 'I agree that pep.ma may use this message to reply to me.';

	const CONSENT_AR = 'أوافق على أن يستخدم موقع pep.ma هذه الرسالة للرد عليّ.';

	public static function init() {
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle' ) );
		add_action( 'admin_post_nopriv_' . self::ACTION, array( __CLASS__, 'handle' ) );
	}

	/**
	 * @return string fr|en|ar
	 */
	public static function language() {
		$lang = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
		if ( ! in_array( $lang, array( 'fr', 'en', 'ar' ), true ) ) {
			return 'fr';
		}
		if ( 'fr' === $lang && isset( $_POST['pk_language'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- lecture de secours pour redirection admin-post
			$req_lang = sanitize_key( wp_unslash( $_POST['pk_language'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
			if ( in_array( $req_lang, array( 'fr', 'en', 'ar' ), true ) ) {
				return $req_lang;
			}
		}
		return $lang;
	}

	/**
	 * Phrase de consentement traduite dans la langue active.
	 *
	 * @return string
	 */
	public static function consent_sentence() {
		$lang = self::language();
		if ( 'en' === $lang ) {
			return self::CONSENT_EN;
		}
		if ( 'ar' === $lang ) {
			return self::CONSENT_AR;
		}
		return self::CONSENT_FR;
	}

	/**
	 * @return string
	 */
	public static function consent_lang() {
		return self::language();
	}

	/**
	 * Libellé déjà dans le dictionnaire du thème.
	 *
	 * @param string $key Chaîne source déjà écrite.
	 * @return string
	 */
	public static function label( $key ) {
		if ( class_exists( 'Partikulier_Localization' ) ) {
			$translated = Partikulier_Localization::translate_polylang_string( $key, $key, 'partikulier' );
			if ( is_string( $translated ) && '' !== $translated ) {
				return $translated;
			}
		}
		return $key;
	}

	/**
	 * Libellé localisé du champ Message.
	 *
	 * @return string
	 */
	public static function message_label() {
		$lang = self::language();
		if ( 'ar' === $lang ) {
			return 'الرسالة';
		}
		return 'Message';
	}

	/**
	 * Libellé localisé du bouton d’envoi.
	 *
	 * @return string
	 */
	public static function submit_label() {
		$lang = self::language();
		if ( 'en' === $lang ) {
			return 'Send';
		}
		if ( 'ar' === $lang ) {
			return 'إرسال';
		}
		return 'Envoyer';
	}

	/**
	 * @param string $code nom|mail|message|limite|envoi
	 * @return string
	 */
	public static function error_text( $code ) {
		$lang = self::language();
		if ( 'nom' === $code ) {
			return 'en' === $lang ? 'Your name is required.' : ( 'ar' === $lang ? 'الاسم مطلوب.' : 'Votre nom est requis.' );
		}
		if ( 'mail' === $code ) {
			return 'en' === $lang ? 'Enter a valid email.' : ( 'ar' === $lang ? 'أدخل بريداً إلكترونياً صالحاً.' : 'Indiquez un e-mail valide.' );
		}
		if ( 'message' === $code ) {
			return 'en' === $lang ? 'The message is required.' : ( 'ar' === $lang ? 'الرسالة مطلوبة.' : 'Le message est requis.' );
		}
		if ( 'case' === $code ) {
			return '';
		}
		if ( 'limite' === $code ) {
			if ( 'en' === $lang ) {
				return 'Too many failed attempts. For security, please wait 15 minutes before trying again.';
			}
			if ( 'ar' === $lang ) {
				return 'عدد كبير جداً من المحاولات الفاشلة. لدواعٍ أمنية، يرجى الانتظار 15 دقيقة قبل المحاولة مجدداً.';
			}
			return 'Trop de tentatives échouées. Par mesure de sécurité, veuillez patienter 15 minutes avant de réessayer.';
		}
		return 'en' === $lang ? 'Please try again later.' : ( 'ar' === $lang ? 'يرجى المحاولة لاحقاً.' : 'Merci de réessayer plus tard.' );
	}

	/**
	 * @return string
	 */
	public static function success_text() {
		$lang = self::language();
		if ( 'en' === $lang ) {
			return 'Message sent.';
		}
		if ( 'ar' === $lang ) {
			return 'تم إرسال الرسالة.';
		}
		return 'Message envoyé.';
	}

	/**
	 * @return string
	 */
	public static function ui_lang() {
		return self::language();
	}

	public static function handle() {
		$back = self::back_url();
		if ( ! isset( $_POST['pk_contact_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['pk_contact_nonce'] ) ), self::ACTION ) ) {
			self::fail( $back, 'envoi', array() );
		}

		$honeypot = isset( $_POST['pk_company'] ) ? trim( (string) wp_unslash( $_POST['pk_company'] ) ) : '';
		if ( '' !== $honeypot ) {
			wp_safe_redirect( add_query_arg( 'envoye', '1', $back ) );
			exit;
		}

		if ( self::limited() ) {
			self::fail( $back, 'limite', array() );
		}
		self::count_attempt();

		$name    = isset( $_POST['pk_name'] ) ? sanitize_text_field( wp_unslash( $_POST['pk_name'] ) ) : '';
		$email   = isset( $_POST['pk_email'] ) ? sanitize_email( wp_unslash( $_POST['pk_email'] ) ) : '';
		$message = isset( $_POST['pk_message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['pk_message'] ) ) : '';
		$consent = isset( $_POST['pk_consent'] ) ? sanitize_text_field( wp_unslash( $_POST['pk_consent'] ) ) : '';
		$kept    = array(
			'name'    => $name,
			'email'   => $email,
			'message' => $message,
		);

		if ( '' === $name ) {
			self::fail( $back, 'nom', $kept );
		}
		if ( ! is_email( $email ) ) {
			self::fail( $back, 'mail', $kept );
		}
		if ( '' === trim( $message ) ) {
			self::fail( $back, 'message', $kept );
		}
		if ( '1' !== $consent ) {
			self::fail( $back, 'case', $kept );
		}
		$name_len    = function_exists( 'mb_strlen' ) ? mb_strlen( $name ) : strlen( $name );
		$message_len = function_exists( 'mb_strlen' ) ? mb_strlen( $message ) : strlen( $message );
		if ( $name_len > 120 || $message_len > 4000 ) {
			self::fail( $back, 'envoi', $kept );
		}

		$body = "Nom: {$name}\nE-mail: {$email}\n\n{$message}\n\n" . self::CONSENT_FR . "\n";
		$sent = wp_mail(
			'contact@pep.ma',
			'Message de contact',
			$body,
			array(
				'Content-Type: text/plain; charset=UTF-8',
				'Reply-To: ' . $email,
			)
		);
		if ( ! $sent ) {
			self::fail( $back, 'envoi', $kept );
		}

		wp_safe_redirect( add_query_arg( 'envoye', '1', $back ) );
		exit;
	}

	/**
	 * @param string               $back  URL de retour.
	 * @param string               $code  Code d’erreur.
	 * @param array<string,string> $kept  Champs à réafficher.
	 * @return void
	 */
	private static function fail( $back, $code, $kept ) {
		$token = wp_generate_password( 20, false, false );
		set_transient(
			'pk_contact_' . $token,
			array(
				'code'    => $code,
				'name'    => isset( $kept['name'] ) ? $kept['name'] : '',
				'email'   => isset( $kept['email'] ) ? $kept['email'] : '',
				'message' => isset( $kept['message'] ) ? $kept['message'] : '',
			),
			10 * MINUTE_IN_SECONDS
		);
		wp_safe_redirect( add_query_arg( 'pkc', $token, $back ) );
		exit;
	}

	/**
	 * @return array<string,string>
	 */
	public static function posted() {
		$token = isset( $_GET['pkc'] ) ? sanitize_key( wp_unslash( $_GET['pkc'] ) ) : '';
		if ( '' === $token ) {
			return array();
		}
		$data = get_transient( 'pk_contact_' . $token );
		delete_transient( 'pk_contact_' . $token );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * @return string
	 */
	private static function back_url() {
		$ref = wp_get_referer();
		$ref = $ref ? wp_validate_redirect( $ref, '' ) : '';
		if ( $ref && false !== strpos( (string) wp_parse_url( $ref, PHP_URL_PATH ), '/contact' ) ) {
			return remove_query_arg( array( 'envoye', 'pkc' ), $ref );
		}
		if ( function_exists( 'pk_page_url' ) ) {
			return pk_page_url( 'contact', '/fr/contact/' );
		}
		return home_url( '/fr/contact/' );
	}

	/**
	 * @return bool
	 */
	private static function limited() {
		return (int) get_transient( self::rate_key() ) >= 5;
	}

	/**
	 * @return void
	 */
	private static function count_attempt() {
		$key = self::rate_key();
		set_transient( $key, (int) get_transient( $key ) + 1, 15 * MINUTE_IN_SECONDS );
	}

	/**
	 * @return string
	 */
	private static function rate_key() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		return 'pk_contact_rl_' . hash_hmac( 'sha256', $ip, wp_salt( 'auth' ) );
	}
}

Partikulier_Contact::init();
