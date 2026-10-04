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
		if ( 'logout' === $action || 'postpass' === $action ) {
			return;
		}

		// Compatibilité Hostinger hPanel & SSO : ne jamais bloquer la connexion 1-clic depuis l'hébergeur
		foreach ( array( 'token', 'hostinger_login', 'hpanel', 'sso_token', 'wp_sso', 'hostinger_sso' ) as $sso_key ) {
			if ( ! empty( $_GET[ $sso_key ] ) || ! empty( $_POST[ $sso_key ] ) ) {
				return;
			}
		}

		$secret_key    = self::get_admin_secret_key();
		$cookie_name   = 'pk_admin_access';

		$provided_key = isset( $_GET['pk_admin_key'] ) ? sanitize_text_field( wp_unslash( $_GET['pk_admin_key'] ) ) : '';
		if ( '' === $provided_key && isset( $_GET['pk_direction'] ) ) {
			$provided_key = sanitize_text_field( wp_unslash( $_GET['pk_direction'] ) );
		}

		if ( '' !== $provided_key && hash_equals( $secret_key, $provided_key ) ) {
			$expiry = time() + 7200;
			$token  = $expiry . '.' . hash_hmac( 'sha256', $expiry . '|' . $secret_key, wp_salt( 'auth' ) );
			setcookie( $cookie_name, $token, $expiry, COOKIEPATH, COOKIE_DOMAIN, is_ssl(), true );
			return;
		}

		if ( isset( $_COOKIE[ $cookie_name ] ) && self::valid_admin_access_token( $_COOKIE[ $cookie_name ], $secret_key ) ) {
			return;
		}

		// Non autorisé : masquage complet vers l'accueil du site
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
		if ( false !== strpos( (string) $error, 'pk_auth_rate_limited' ) || false !== strpos( (string) $error, 'patienter 15 minutes' ) ) {
			return $error;
		}
		return __( 'Identifiant ou mot de passe incorrect.', 'partikulier' ) . ' <a href="' . esc_url( wp_lostpassword_url() ) . '">' . __( 'Mot de passe oublié ?', 'partikulier' ) . '</a>';
	}

	/**
	 * Clé de Rate Limiting composite adaptée aux réseaux mobiles marocains (CGNAT).
	 * Isole le couple (IP, compte visé) pour ne pas bloquer les autres utilisateurs de la même antenne 4G/5G.
	 */
	public static function get_login_rate_key( $username ) {
		$ip      = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : 'unknown';
		$digits  = preg_replace( '/\D+/', '', (string) $username );
		$account = self::normalize_login_phone( $username ) ?: ( strlen( $digits ) >= 9 ? $digits : sanitize_user( (string) $username ) );
		return 'pk_auth_rl_' . hash_hmac( 'sha256', $ip . '|' . strtolower( $account ), wp_salt( 'auth' ) );
	}

	private static function normalize_login_phone( $username ) {
		$digits = preg_replace( '/\D+/', '', (string) $username );
		return preg_match( '/^(?:0|212|00212)([67][0-9]{8})$/D', $digits, $match ) ? '212' . $match[1] : '';
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
		if ( is_wp_error( $user ) && array_intersect(
			$user->get_error_codes(),
			array( 'invalid_username', 'invalid_email', 'incorrect_password', 'authentication_failed' )
		) ) {
			return new WP_Error( 'pk_auth_failed', __( 'Identifiant ou mot de passe incorrect.', 'partikulier' ) );
		}
		return $user;
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

	/**
	 * Permet à un propriétaire marocain de se connecter en saisissant directement
	 * son numéro de téléphone portable (+212..., 06..., 07...).
	 */
	public static function resolve_phone_login( $user, $username, $password ) {
		if ( $user instanceof WP_User || ( is_wp_error( $user ) && 'pk_auth_rate_limited' === $user->get_error_code() )
			|| empty( $username ) || empty( $password ) ) {
			return $user;
		}

		$digits = self::normalize_login_phone( $username ) ?: preg_replace( '/\D+/', '', (string) $username );
		if ( strlen( $digits ) >= 9 ) {
			$found = null;

			// 1. Recherche par login direct
			$candidate = get_user_by( 'login', $digits );
			if ( $candidate instanceof WP_User ) {
				$found = $candidate;
			}

			// 2. Conversion format local 06... <-> international 2126...
			if ( ! $found && 0 === strpos( $digits, '212' ) ) {
				$local     = '0' . substr( $digits, 3 );
				$candidate = get_user_by( 'login', $local );
				if ( $candidate instanceof WP_User ) {
					$found = $candidate;
				}
			} elseif ( ! $found && 0 === strpos( $digits, '0' ) ) {
				$intl      = '212' . substr( $digits, 1 );
				$candidate = get_user_by( 'login', $intl );
				if ( $candidate instanceof WP_User ) {
					$found = $candidate;
				}
			}

			// 3. Recherche par user_meta _pk_owner_phone_clean
			if ( ! $found ) {
				$meta_users = get_users( array(
					'meta_key'   => '_pk_owner_phone_clean',
					'meta_value' => $digits,
					'number'     => 1,
				) );
				if ( ! empty( $meta_users ) && $meta_users[0] instanceof WP_User ) {
					$found = $meta_users[0];
				}
			}

			if ( ! $found && 0 === strpos( $digits, '212' ) ) {
				$local      = '0' . substr( $digits, 3 );
				$meta_users = get_users( array(
					'meta_key'   => '_pk_owner_phone_clean',
					'meta_value' => $local,
					'number'     => 1,
				) );
				if ( ! empty( $meta_users ) && $meta_users[0] instanceof WP_User ) {
					$found = $meta_users[0];
				}
			}

			// 4. Recherche par e-mail généré {digits}@partikulier.local
			if ( ! $found ) {
				$candidate = get_user_by( 'email', $digits . '@partikulier.local' );
				if ( $candidate instanceof WP_User ) {
					$found = $candidate;
				}
			}

			if ( $found instanceof WP_User ) {
				return wp_authenticate_username_password( null, $found->user_login, $password );
			}
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
