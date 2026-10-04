<?php
/**
 * Module : protections HTTP, sécurité de l'authentification et réduction de l’exposition WordPress.
 *
 * Implémente :
 *   1. Protection anti-énumération utilisateurs (REST /wp/v2/users, archives auteurs et erreurs de login unifiées).
 *   2. Rate Limiter anti-brute-force conforme CGNAT (clé composite IP + compte visé).
 *   3. Authentification transparente par numéro de téléphone portable marocain (+212, 06, 07).
 *   4. Masquage de wp-login.php et wp-admin pour le public avec passerelle secrète d'administration.
 *   5. En-têtes HTTP défensifs (CSP, HSTS, X-Frame-Options, Permissions-Policy).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Security {

	public const DEFAULT_ADMIN_KEY = 'direction2026';

	public static function init() {
		add_filter( 'rest_endpoints', array( __CLASS__, 'restrict_user_enumeration' ) );
		add_action( 'template_redirect', array( __CLASS__, 'block_public_author_enumeration' ), 0 );
		add_action( 'send_headers', array( __CLASS__, 'send_public_headers' ) );
		add_filter( 'xmlrpc_enabled', '__return_false' );
		add_filter( 'xmlrpc_methods', '__return_empty_array' );

		// --- Sécurité de l'authentification (OWASP ASVS / WSTG) ---
		// 1. Unification des erreurs de connexion (Anti-Énumération WSTG-INFO-04)
		add_filter( 'login_errors', array( __CLASS__, 'unify_login_errors' ) );

		// 2. Rate Limiting à la connexion (Anti-Brute Force composite CGNAT)
		add_filter( 'authenticate', array( __CLASS__, 'guard_login_brute_force' ), 5, 3 );
		add_filter( 'authenticate', array( __CLASS__, 'finalize_login_verdict' ), 100, 3 );
		add_action( 'wp_login_failed', array( __CLASS__, 'track_login_failure' ) );
		add_action( 'wp_login', array( __CLASS__, 'reset_login_attempts' ), 10, 2 );

		// 3. Authentification par numéro de téléphone portable (+212, 06, 07)
		add_filter( 'authenticate', array( __CLASS__, 'resolve_phone_login' ), 15, 3 );

		// 4. Masquage de wp-login.php et wp-admin pour le public (URL secrète d'administration)
		add_action( 'login_init', array( __CLASS__, 'protect_admin_gateway' ) );
	}

	/**
	 * Clé secrète d'accès à l'administration WordPress.
	 * Configurable via constante PK_ADMIN_SECRET_KEY ou option pk_admin_secret_key.
	 */
	public static function get_admin_secret_key() {
		if ( defined( 'PK_ADMIN_SECRET_KEY' ) && PK_ADMIN_SECRET_KEY ) {
			return (string) PK_ADMIN_SECRET_KEY;
		}
		$stored = (string) get_option( 'pk_admin_secret_key', '' );
		return $stored !== '' ? $stored : self::DEFAULT_ADMIN_KEY;
	}

	/**
	 * Masque wp-login.php et wp-admin pour les visiteurs et robots externes.
	 * Seuls les utilisateurs munis du jeton secret ou disposant d'une session admin active peuvent y accéder.
	 */
	public static function protect_admin_gateway() {
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return;
		}

		if ( is_user_logged_in() && current_user_can( 'manage_options' ) ) {
			return;
		}

		$action = isset( $_REQUEST['action'] ) ? sanitize_key( wp_unslash( $_REQUEST['action'] ) ) : '';
		if ( in_array( $action, array( 'logout', 'postpass', 'lostpassword', 'retrievepassword', 'resetpass', 'rp' ), true ) ) {
			return;
		}

		$secret_key = self::get_admin_secret_key();
		if ( '' === $secret_key || apply_filters( 'partikulier_admin_gateway_bypass', false ) ) {
			return;
		}

		// Compatibilité Hostinger hPanel & SSO : ne jamais bloquer la connexion 1-clic depuis l'hébergeur
		foreach ( array( 'token', 'hostinger_login', 'hpanel', 'sso_token', 'wp_sso', 'hostinger_sso' ) as $sso_key ) {
			if ( ! empty( $_GET[ $sso_key ] ) || ! empty( $_POST[ $sso_key ] ) ) {
				return;
			}
		}

		$cookie_name   = 'pk_admin_access';

		$provided_key = isset( $_GET['pk_admin_key'] ) && is_string( $_GET['pk_admin_key'] ) ? sanitize_text_field( wp_unslash( $_GET['pk_admin_key'] ) ) : '';
		if ( '' === $provided_key && isset( $_GET['pk_direction'] ) && is_string( $_GET['pk_direction'] ) ) {
			$provided_key = sanitize_text_field( wp_unslash( $_GET['pk_direction'] ) );
		}

		if ( '' !== $provided_key && hash_equals( $secret_key, $provided_key ) ) {
			$expiry = time() + 7200;
			$token  = $expiry . '.' . hash_hmac( 'sha256', $expiry . '|' . $secret_key, wp_salt( 'auth' ) );
			setcookie( $cookie_name, $token, array(
				'expires' => $expiry,
				'path' => COOKIEPATH ?: '/',
				'domain' => COOKIE_DOMAIN ?: '',
				'secure' => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			) );
			return;
		}

		if ( isset( $_COOKIE[ $cookie_name ] ) && self::valid_admin_access_token( $_COOKIE[ $cookie_name ], $secret_key ) ) {
			return;
		}

		error_log( '[Partikulier auth] Admin gateway access denied.' );

		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}

	public static function valid_admin_access_token( $token, $secret_key ) {
		if ( ! is_string( $token ) || ! preg_match( '/^([0-9]{10})\.([a-f0-9]{64})$/D', $token, $parts ) ) {
			return false;
		}
		$expiry = (int) $parts[1];
		$now    = time();
		if ( $expiry <= $now || $expiry > $now + 7200 ) {
			return false;
		}
		$expected = hash_hmac( 'sha256', $parts[1] . '|' . $secret_key, wp_salt( 'auth' ) );
		return hash_equals( $expected, $parts[2] );
	}

	/**
	 * Unification des messages d'erreur de connexion : neutralise l'énumération d'adresses e-mails.
	 */
	public static function unify_login_errors( $error ) {
		global $errors;
		if ( $errors instanceof WP_Error && array_intersect( $errors->get_error_codes(), array( 'pk_auth_rate_limited', 'pk_2fa_required', 'pk_2fa_invalid' ) ) ) {
			return $error;
		}
		return __( 'Identifiant ou mot de passe incorrect.', 'partikulier' ) . ' <a href="' . esc_url( wp_lostpassword_url() ) . '">' . __( 'Mot de passe oublié ?', 'partikulier' ) . '</a>';
	}

	public static function unify_authentication_error( $user, $username = null, $password = null ) {
		if ( $user instanceof WP_Error && array_intersect( $user->get_error_codes(), array( 'invalid_username', 'invalid_email', 'incorrect_password', 'authentication_failed' ) ) ) {
			return new WP_Error( 'pk_auth_invalid', __( 'Identifiant ou mot de passe incorrect.', 'partikulier' ) );
		}
		return $user;
	}

	/**
	 * Clé de Rate Limiting composite adaptée aux réseaux mobiles marocains (CGNAT).
	 * Isole le couple (IP, compte visé) pour ne pas bloquer les autres utilisateurs de la même antenne 4G/5G.
	 */
	public static function get_login_rate_key( $username ) {
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$phone   = self::normalize_phone( (string) $username );
		$user    = get_user_by( is_email( (string) $username ) ? 'email' : 'login', (string) $username );
		if ( ! $user && $phone ) {
			$user = self::find_phone_user( $phone );
		}
		$account = $user instanceof WP_User ? 'user:' . $user->ID : ( $phone ?: sanitize_user( (string) $username ) );
		return 'pk_auth_rl_' . hash_hmac( 'sha256', $ip . '|' . strtolower( $account ), wp_salt( 'auth' ) );
	}

	/**
	 * Bloque les tentatives de force brute sur un compte donné après 5 échecs consécutifs.
	 */
	public static function guard_login_brute_force( $user, $username, $password ) {
		if ( empty( $username ) || empty( $password ) ) {
			return $user;
		}
		$key      = self::get_login_rate_key( $username );
		$attempts = (int) get_transient( $key );
		if ( $attempts >= 5 ) {
			return new WP_Error(
				'pk_auth_rate_limited',
				__( 'Trop de tentatives échouées. Par mesure de sécurité, veuillez patienter 15 minutes avant de réessayer.', 'partikulier' )
			);
		}
		return $user;
	}

	public static function finalize_login_verdict( $user, $username, $password ) {
		// WordPress password filters may replace the earlier rate-limit error.
		$user = self::guard_login_brute_force( $user, $username, $password );
		return self::unify_authentication_error( $user, $username, $password );
	}

	public static function track_login_failure( $username ) {
		$key      = self::get_login_rate_key( $username );
		$attempts = (int) get_transient( $key );
		set_transient( $key, $attempts + 1, 15 * MINUTE_IN_SECONDS );
	}

	public static function reset_login_attempts( $user_login, $user ) {
		$key = self::get_login_rate_key( $user_login );
		delete_transient( $key );
	}

	public static function normalize_phone( $phone ) {
		$digits = preg_replace( '/\D+/', '', (string) $phone );
		if ( 0 === strpos( $digits, '00212' ) ) {
			$digits = substr( $digits, 2 );
		} elseif ( preg_match( '/^0[67][0-9]{8}$/', $digits ) ) {
			$digits = '212' . substr( $digits, 1 );
		}
		return preg_match( '/^212[67][0-9]{8}$/', $digits ) ? $digits : '';
	}

	public static function find_phone_user( $phone ) {
		$phone = self::normalize_phone( $phone );
		if ( ! $phone ) {
			return false;
		}
		foreach ( array( $phone, '0' . substr( $phone, 3 ) ) as $alias ) {
			$user = get_user_by( 'login', $alias );
			if ( ! $user ) {
				$users = get_users( array( 'meta_key' => '_pk_owner_phone_clean', 'meta_value' => $alias, 'number' => 1 ) );
				$user = $users[0] ?? false;
			}
			if ( ! $user ) {
				$user = get_user_by( 'email', $alias . '@partikulier.local' );
			}
			if ( $user instanceof WP_User ) {
				return $user;
			}
		}
		return false;
	}

	/**
	 * Permet à un propriétaire marocain de se connecter en saisissant directement
	 * son numéro de téléphone portable (+212..., 06..., 07...).
	 */
	public static function resolve_phone_login( $user, $username, $password ) {
		if ( is_wp_error( $user ) || $user instanceof WP_User || empty( $username ) || empty( $password ) ) {
			return $user;
		}
		$found = self::find_phone_user( $username );
		if ( $found instanceof WP_User ) {
			return wp_authenticate_username_password( null, $found->user_login, $password );
		}
		return $user;
	}

	/**
	 * L’API des utilisateurs n’est jamais utile aux visiteurs d’un portail immobilier.
	 */
	public static function restrict_user_enumeration( $endpoints ) {
		if ( current_user_can( 'list_users' ) ) {
			return $endpoints;
		}
		foreach ( array_keys( $endpoints ) as $route ) {
			if ( 0 === strpos( $route, '/wp/v2/users' ) ) {
				unset( $endpoints[ $route ] );
			}
		}
		return $endpoints;
	}

	/**
	 * Neutralise la découverte d’identifiants par ?author=ID et les archives auteur publiques.
	 */
	public static function block_public_author_enumeration() {
		if ( is_admin() || is_user_logged_in() || ! is_author() ) {
			return;
		}
		wp_safe_redirect( home_url( '/' ), 302 );
		exit;
	}

	/**
	 * En-têtes défensifs compatibles avec les ressources actuelles du thème.
	 */
	public static function send_public_headers() {
		if ( is_admin() ) {
			return;
		}
		$path    = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
		$is_root = '/' === trailingslashit( (string) $path );
		if ( $is_root ) {
			header( 'Cache-Control: private, no-store, max-age=0' );
			header( 'Vary: Accept-Language, Cookie', false );
		}
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: strict-origin-when-cross-origin' );
		header( 'Permissions-Policy: geolocation=(), microphone=(), camera=()' );
		header( "Content-Security-Policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; frame-src 'self' https://static.addtoany.com; form-action 'self'; img-src 'self' data: blob: https://static.addtoany.com; font-src 'self' data:; script-src 'self' 'unsafe-inline' https://static.addtoany.com; style-src 'self' 'unsafe-inline'; connect-src 'self'" );
		if ( is_ssl() ) {
			header( 'Strict-Transport-Security: max-age=31536000; includeSubDomains' );
		}
	}

	/**
	 * Limite les soumissions anonymes d'annonces.
	 */
	public static function allow_listing_submission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		$ip    = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$key   = 'pk_listing_rate_' . hash_hmac( 'sha256', $ip, wp_salt( 'nonce' ) );
		$limit = max( 1, (int) apply_filters( 'partikulier_listing_submission_limit', 5 ) );
		$count = (int) get_transient( $key );
		if ( $count >= $limit ) {
			return false;
		}
		set_transient( $key, $count + 1, HOUR_IN_SECONDS );
		return true;
	}
}

Partikulier_Security::init();
