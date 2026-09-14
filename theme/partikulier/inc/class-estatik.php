<?php
/**
 * Module : integration ESTATIK.
 *
 * - Verifie la presence du plugin et affiche un message admin si absent
 * - Overide les templates Estatik via filtre es_template_path / dossier estatik/ du theme
 * - Normalise les URLs d'images Estatik vers les AVIF
 * - Fallback : si ESTATIK est absent, les templates maison affichent un CTA d'installation
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

class Partikulier_Estatik {

	public static function init() {
			/* SE-018 (E-1801, campagne post-audit — P1-4) : Estatik 4.3.x branche
			 * DEUX callbacks publics sur wp_enqueue_scripts —
			 * register_global_assets (class-assets-init.php:14, qui appelle
			 * es_framework_instance()->load_scripts() : es-datetime-picker
			 * [dep jquery], es-framework [deps jquery + es-select2 +
			 * jquery-ui-sortable] + le style es-select2) et frontend_assets
			 * (:20). Le retrait d'origine ne couvrait que le second — la
			 * moitié du problème, source mesurée du jQuery frontal par
			 * l'audit. Retrait des DEUX, priorité 1 (avant l'exécution des
			 * callbacks Estatik à priorité 10). Le back-office reste
			 * intouché (aucun retrait sur admin_enqueue_scripts). */
			add_action( 'wp_enqueue_scripts', function() {
					remove_action( 'wp_enqueue_scripts', array( 'Es_Assets', 'register_global_assets' ) );
					remove_action( 'wp_enqueue_scripts', array( 'Es_Assets', 'frontend_assets' ) );
			}, 1 );

			/* SE-018 (E-1806) : repli de dépendances maintenu mais borné —
			 * la méthode vérifie désormais le contexte propriétaire elle-même
			 * (voir register_dependency_fallbacks). */
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register_dependency_fallbacks' ), 0 );

		if ( ! self::plugin_active() ) {
				add_action( 'admin_notices', array( __CLASS__, 'admin_notice' ) );
				// Si le plugin est installe mais non active, tenter activation.
				add_action( 'admin_init', array( __CLASS__, 'maybe_activate' ) );
				return;
		}

			// --- Template overrides ---
			// Estatik v4 charge les overrides depuis {theme}/estatik4/front/...
			// (doc officielle : template-overriding-example). On renforce avec le
					// filtre es_template_path si le loader du plugin l'applique.
					add_filter( 'es_template_path', array( __CLASS__, 'template_path' ) );
					add_filter( 'es_template_file', array( __CLASS__, 'template_file' ) );
					add_filter( 'template_include', array( __CLASS__, 'property_single_template' ), 999 );

			// --- Normalisation images Estatik vers AVIF ---
			add_filter( 'es_listing_gallery_img', array( __CLASS__, 'avif_image' ) );
			add_filter( 'es_archive_image', array( __CLASS__, 'avif_image' ) );
			add_filter( 'es_single_image', array( __CLASS__, 'avif_image' ) );

			// --- Suppression des assets Estatik lourds : on garde le CSS minimal,
			//     le JS de recherche ajax est conserve car il alimente la recherche hero. ---
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_heavy' ), 100 );

			// --- Nettoyage du <head> : Estatik ajoute des meta generator ---
			add_action( 'init', array( __CLASS__, 'remove_estatik_head' ), 999 );

			// --- 6.17.33 — Authentification au design du thème :
			// après une erreur de connexion (mauvais mot de passe), Estatik
			// renvoie vers la page de connexion SANS l'URL de retour : le
			// propriétaire qui retape son mot de passe atterrirait ensuite
			// sur l'accueil au lieu de son tableau de bord. On réinjecte
			// redirect_url (présent dans le POST) dans la redirection. ---
			add_filter( 'es_get_auth_page_uri', array( __CLASS__, 'preserve_auth_redirect_url' ) );
	}

		/**
		 * Conserve l'URL de retour (?redirect_url=) à travers les échecs
		 * de connexion : le filtre d'Estatik reconstruit l'URI de la page
		 * d'authentification sans lui. Ne s'applique qu'au POST de connexion.
		 *
		 * SE-020 (E-2005) : l'URL transportée est désormais validée
		 * wp_validate_redirect (same-site) — une URL externe est abandonnée
		 * (vecteur d'hameçonnage après connexion, reproduit sur banc). Le
		 * nonce recommandé par l'analyse statique est inapplicable ici : le
		 * formulaire de connexion est rendu par le shortcode vendu
		 * [es_authentication] d'Estatik (périmètre SE-021, code non modifié) ;
		 * la valeur n'est jamais persistée ni émise brute (rawurlencode).
		 *
		 * @param string $uri URI de la page d'authentification.
		 * @return string
		 */
	public static function preserve_auth_redirect_url( $uri ) {
		if ( ! is_string( $uri ) || '' === $uri ) {
				return $uri;
		}
			$redirect = isset( $_POST['redirect_url'] ) ? wp_unslash( $_POST['redirect_url'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification,WordPress.Security.ValidatedSanitizedInput -- E-2005 : formulaire vendu Estatik (nonce inapplicable), valeur esc_url_raw + wp_validate_redirect ci-dessous
		if ( ! $redirect || ! is_string( $redirect ) ) {
				return $uri;
		}
			$redirect = wp_validate_redirect( esc_url_raw( $redirect ), '' );
		if ( ! $redirect ) {
				return $uri;
		}
			return $uri . ( false === strpos( $uri, '?' ) ? '?' : '&' ) . 'redirect_url=' . rawurlencode( $redirect );
	}

	public static function plugin_active() {
			return class_exists( 'Estatik' ) || class_exists( 'Es_Main_Class' ) || defined( 'ES_VERSION' ) || function_exists( 'es_get_properties' );
	}

		/**
		 * Chemin du dossier de templates overrides dans le theme (dossier estatik4,
		 * convention officielle d'Estatik v4).
		 */
	public static function template_path() {
			return PARTIKULIER_DIR . '/estatik4/front/';
	}

		/**
		 * Resout le chemin absolu d'un template Estatik : on cherche d'abord dans
		 * notre dossier estatik4, sinon on laisse le plugin utiliser son template.
		 */
	public static function template_file( $file ) {
		if ( ! $file || ! is_string( $file ) ) {
						return $file;
		}

					// {plugin}/templates/front/property/single.php -> {theme}/estatik4/front/property/single.php
					$relative = wp_normalize_path( str_replace( wp_normalize_path( trailingslashit( dirname( WP_PLUGIN_DIR ) ) ), '', wp_normalize_path( $file ) ) );
					$relative = ltrim( str_replace( 'templates/front/', 'estatik4/front/', $relative ), '/' );
					$override = trailingslashit( PARTIKULIER_DIR ) . $relative;
		if ( file_exists( $override ) ) {
			return $override;
		}
					return $file;
	}

		/**
		 * Estatik 4.3.4 enregistre son propre template pour le CPT "properties".
		 * On conserve ses données mais on rend la fiche via le template du thème.
		 *
		 * @param string $template Template proposé par WordPress/Estatik.
		 * @return string
		 */
	public static function property_single_template( $template ) {
		if ( ! is_singular( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
				return $template;
		}

			$override = PARTIKULIER_DIR . '/templates/single.php';
			return file_exists( $override ) ? $override : $template;
	}

		/**
		 * Admin notice si ESTATIK n'est pas actif.
		 */
	public static function admin_notice() {
		if ( self::plugin_installed() ) {
				$url = wp_nonce_url( admin_url( 'plugins.php?action=activate&plugin=estatik/estatik.php' ), 'activate-plugin_estatik/estatik.php' );
				$msg = sprintf(
						/* translators: %s: lien d'activation */
						__( 'Le theme Partikulier nécessite le plugin <strong>Estatik</strong>. %s', 'partikulier' ),
						'<a href="' . esc_url( $url ) . '">' . __( 'Activer Estatik maintenant', 'partikulier' ) . '</a>'
				);
		} else {
				$msg = sprintf(
						/* translators: %s: lien d'installation */
						__( 'Le theme Partikulier nécessite le plugin <strong>Estatik</strong>. %s', 'partikulier' ),
						'<a href="' . esc_url( admin_url( 'plugin-install.php?s=estatik&tab=search&type=term' ) ) . '">' . __( 'Installer Estatik', 'partikulier' ) . '</a>'
				);
		}
			printf( '<div class="notice notice-warning"><p>%s</p></div>', $msg ); // phpcs:ignore
	}

		/**
		 * Active automatiquement Estatik s'il est installe (gain de temps pour l'utilisateur).
		 */
	public static function maybe_activate() {
		if ( self::plugin_active() || ! self::plugin_installed() ) {
				return;
		}
		if ( current_user_can( 'activate_plugins' ) ) {
				activate_plugin( 'estatik/estatik.php' );
		}
	}

	private static function plugin_installed() {
			$plugins = get_plugins();
			return isset( $plugins['estatik/estatik.php'] );
	}

		/**
		 * Enregistre les dépendances Estatik si un autre composant les a retirées
		 * trop tôt. Aucun script n'est forcé : les handles sont seulement rendus
		 * disponibles pour les scripts Estatik qui les déclarent comme
		 * dépendances.
		 *
		 * SE-018 (E-1806) : le repli ne peut plus ressusciter es-select2 /
		 * es-datetime-picker (tous deux à dépendance jQuery) hors des pages
		 * propriétaires — il est conditionné au même contexte que l'exemption
		 * du filet dequeue_heavy (DP-6 option a : exception propriétaire
		 * documentée — galerie, filtres, sélection, upload). Sur les pages
		 * éditoriales, plus AUCUN chemin ne re-enregistre de poignée
		 * jQuery-dépendante (invariant E-1806).
		 *
		 * @return void
		 */
	public static function register_dependency_fallbacks() {
		if ( ! defined( 'ES_PLUGIN_URL' ) || self::is_property_context() ) {
				return;
		}

			$version = defined( 'ES_VERSION' ) ? ES_VERSION : null;
		if ( ! wp_script_is( 'es-select2', 'registered' ) ) {
				wp_register_script( 'es-select2', ES_PLUGIN_URL . 'common/select2/select2.full.min.js', array( 'jquery' ), $version );
		}
		if ( ! wp_script_is( 'es-datetime-picker', 'registered' ) ) {
				wp_register_script( 'es-datetime-picker', ES_PLUGIN_URL . 'includes/classes/framework/assets/js/jquery.datetimepicker.full.min.js', array( 'jquery' ), $version );
		}
	}

		/**
		 * Retire le CSS par defaut d'Estatik (le theme fournit le sien, plus leger).
		 * On garde les styles de la carte interactive si Google Maps est active.
		 *
		 * SE-018 (E-1802, filet consolidé) : la liste s'étend à es-framework (le
		 * script enfilé qui tire jQuery + jquery-ui-sortable par résolution de
		 * dépendances), es-frontend, es-properties et wp-color-picker (handle du
		 * cœur WP enfilé par le framework Estatik côté public — remarque 2 de la
		 * revue croisée) ; chaque poignée passe par wp_dequeue_script PUIS
		 * wp_deregister_script — le dequeue seul est vain contre la résolution
		 * de dépendances (un handle encore enregistré est réactivé dès qu'un
		 * script enfilé le déclare). Priorité 100 : après tous les enqueues.
		 */
	public static function dequeue_heavy() {
			wp_dequeue_style( 'es-styles' );
			wp_dequeue_style( 'estatik-style' );
			wp_dequeue_style( 'estatik-front' );
			wp_dequeue_style( 'estatik-public' );

			// Ces bibliothèques ne sont pas nécessaires sur les pages éditoriales.
			// Les pages annonces, dépôt, favoris et tableau de bord les conservent
			// pour ne pas casser galerie, filtres, sélection ou upload
			// (DP-6 option a : exception propriétaire documentée).
		if ( self::is_property_context() ) {
						return;
		}

		foreach ( array( 'es-select2', 'select2', 'select2-js', 'es-slick', 'slick', 'slick-js', 'es-magnific', 'magnific-popup', 'es-datetime-picker', 'datetimepicker', 'jquery-ui-core', 'jquery-ui-datepicker', 'es-framework', 'es-frontend', 'es-properties', 'wp-color-picker', 'clipboard' ) as $handle ) {
							wp_dequeue_script( $handle );
							wp_deregister_script( $handle );
		}
	}

		/**
		 * Contexte propriétaire (DP-6) : archives et fiches du CPT Estatik,
		 * pages de dépôt, favoris et tableau de bord — les parcours dont
		 * galerie, filtres, sélection et upload reposent sur les scripts
		 * jQuery-dépendants. Exception assumée du périmètre « zéro jQuery »
		 * (E-1805), partagée par le filet E-1802 et le repli E-1806.
		 *
		 * @return bool
		 */
	public static function is_property_context() {
			return is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE )
					|| is_singular( PARTIKULIER_ESTATIK_POST_TYPE )
					|| is_page( array( 'deposer', 'deposer-en', 'deposer-ar', 'deposer-une-annonce', 'deposer-annonce', 'mes-annonces', 'mes-annonces-en', 'mes-annonces-ar', 'favoris', 'favoris-en', 'favoris-ar' ) );
	}

		/**
		 * Retire les meta generator d'Estatik.
		 */
	public static function remove_estatik_head() {
			$hooks = array( 'wp_head' );
		foreach ( $hooks as $hook ) {
				$callbacks = isset( $GLOBALS['wp_filter'][ $hook ] ) ? $GLOBALS['wp_filter'][ $hook ] : array();
			foreach ( $callbacks as $priority => $callback_list ) {
				foreach ( $callback_list as $id => $callback ) {
						$fn = $callback['function'];
					if ( is_array( $fn ) && isset( $fn[0] ) && is_string( $fn[0] ) && false !== strpos( strtolower( $fn[0] ), 'estatik' ) ) {
						remove_action( $hook, $fn, $priority );
					}
				}
			}
		}
	}

		/**
		 * Reecriture des URLs d'images Estatik vers leurs variantes AVIF.
		 */
	public static function avif_image( $img ) {
		if ( ! $img || ! is_string( $img ) ) {
				return $img;
		}
			// Cas URL simple.
		if ( preg_match( '#https?://[^"\')\s]+#', $img, $m ) ) {
				$avif = Partikulier_AVIF::avif_path_for_url( $m[0] );
			if ( $avif ) {
					$img = str_replace( $m[0], $avif, $img );
			}
		}
			return $img;
	}
}

Partikulier_Estatik::init();