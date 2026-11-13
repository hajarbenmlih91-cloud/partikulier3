<?php
/**
 * Module : scripts & styles front-end. Zero jQuery.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

class Partikulier_Scripts {

	public static function init() {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
			add_action( 'wp_head', array( __CLASS__, 'dns_prefetch' ), 1 );
			// Le preload doit etre accroche ici, pas depuis enqueue() : wp_enqueue_scripts
			// se declenche PENDANT wp_head, un add_action( 'wp_head', ..., 1 ) a ce
			// moment-la n'est jamais execute (priorite deja depassee).
			add_action( 'wp_head', array( __CLASS__, 'preload_assets' ), 2 );
			add_action( 'wp_head', array( __CLASS__, 'inline_critical_css' ), 3 );
			add_filter( 'style_loader_tag', array( __CLASS__, 'defer_main_stylesheet' ), 10, 4 );
	}

	public static function enqueue() {
			wp_enqueue_style( 'partikulier-style' );

			$main_js = PARTIKULIER_DIR . '/assets/js/main.js';
			wp_enqueue_script(
					'partikulier-main',
					PARTIKULIER_URI . '/assets/js/main.js',
					array(),
					is_readable( $main_js ) ? (string) filemtime( $main_js ) : PARTIKULIER_VERSION,
					array(
							'in_footer' => true,
							'strategy'  => 'defer',
					)
			);

			// Parcours de depot en 3 etapes : charge uniquement sur la page concernee.
		if ( is_page_template( 'templates/page-deposer-annonce.php' ) || is_page( array( 'deposer-une-annonce', 'deposer-annonce', 'deposer', 'publier-une-annonce' ) ) ) {
				$steps_js = PARTIKULIER_DIR . '/assets/js/submit-steps.js';
				wp_enqueue_script(
						'partikulier-submit-steps',
						PARTIKULIER_URI . '/assets/js/submit-steps.js',
						array( 'partikulier-main' ),
						is_readable( $steps_js ) ? (string) filemtime( $steps_js ) : PARTIKULIER_VERSION,
						array(
								'in_footer' => true,
								'strategy'  => 'defer',
						)
				);
		}

			// Donnees JS : admin ajax (formulaire annonce), endpoint REST maison.
			wp_localize_script( 'partikulier-main', 'pkConfig', array(
					'ajaxUrl'          => admin_url( 'admin-ajax.php', is_ssl() ? 'https' : 'http' ),
					'nonce'            => wp_create_nonce( 'pk_public' ),
					'manageNonce'      => is_user_logged_in() ? wp_create_nonce( 'pk_manage_listing' ) : '',
					'homeUrl'          => home_url( '/' ),
					'placesNonce'      => wp_create_nonce( 'pk_places' ),
													'submitNonce' => wp_create_nonce( 'pk_submit_listing' ),
							'language' => function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr',
							'i18n'     => array(
									'publishing'    => __( 'Publication en cours…', 'partikulier' ),
									'requiredFields' => __( 'Veuillez vérifier les champs obligatoires.', 'partikulier' ),
									'serverError'   => __( 'Erreur serveur', 'partikulier' ),
									'saved'         => __( 'Annonce enregistrée !', 'partikulier' ),
									'retry'         => __( 'réessayez ou contactez-nous.', 'partikulier' ),
									'whatsappTitle' => __( 'Une dernière étape : WhatsApp', 'partikulier' ),
									'whatsappIntro' => __( 'Votre annonce est enregistrée, mais reste invisible tant que l’équipe n’a pas rapproché votre message WhatsApp.', 'partikulier' ),
									'whatsappCode'  => __( 'Code de validation :', 'partikulier' ),
									'whatsappOpen'  => __( 'Ouvrir WhatsApp et envoyer le message', 'partikulier' ),
									'whatsappNote'  => __( 'Conservez ce code. L’annonce sera publiée seulement après vérification manuelle du message par l’équipe pep.ma.', 'partikulier' ),
										'photoError'    => __( "photo(s) n'ont pas pu être ajoutées :", 'partikulier' ),
										'photoHint'     => __( 'Vous pourrez les ajouter depuis « Mes annonces » après validation.', 'partikulier' ),
									/* SE-044 / DP-9 (revues E4 + CP5, D2) : messages de validation client du parcours
									 * de désactivation — msgids déjà portés par les catalogues EN/AR (SE-035) ;
									 * servis par pkConfig.i18n, sans repli français codé en dur côté JS. */
									'reasonRequired' => __( 'Merci de choisir un motif.', 'partikulier' ),
									'noteRequired'   => __( 'Merci de préciser le motif (texte privé).', 'partikulier' ),
							),

			) );

	}

		/**
		 * <link rel="preload"> pour la police locale (LCP) selon la langue active.
		 *
		 * Sur les pages RTL (arabe), on precharge Noto Sans Arabic ; sur FR/EN,
		 * uniquement DM Sans (et Instrument Serif sur l'accueil), sans precharger
		 * ni telecharger les fontes arabes inutilisees.
		 */
	public static function preload_assets() {
			$uri    = esc_url( PARTIKULIER_URI );
			$is_rtl = is_rtl()
				|| ( function_exists( 'pll_current_language' ) && 'ar' === pll_current_language( 'slug' ) )
				|| str_starts_with( (string) get_locale(), 'ar' );

		if ( $is_rtl ) {
				printf(
					'<link rel="preload" href="%s/assets/fonts/NotoSansArabic-Regular.woff2" as="font" type="font/woff2" crossorigin fetchpriority="high">%s',
					$uri,
					"\n"
				);
				printf(
					'<link rel="preload" href="%s/assets/fonts/NotoSansArabic-SemiBold.woff2" as="font" type="font/woff2" crossorigin>%s',
					$uri,
					"\n"
				);
				return;
		}

			printf(
				'<link rel="preload" href="%s/assets/fonts/dm-sans-latin.woff2" as="font" type="font/woff2" crossorigin fetchpriority="high">%s',
				$uri,
				"\n"
			);
		if ( is_front_page() ) {
				printf(
					'<link rel="preload" href="%s/assets/fonts/instrument-serif-italic.woff2" as="font" type="font/woff2" crossorigin>%s',
					$uri,
					"\n"
				);
		}
	}

		/**
		 * CSS critique inline sur l'accueil pour rendre le premier ecran sans
		 * requete bloquante, avant le chargement differe de style.min.css.
		 */
	public static function inline_critical_css() {
		if ( is_admin() || ! is_front_page() ) {
				return;
		}
			$file = PARTIKULIER_DIR . '/assets/css/critical.min.css';
		if ( ! is_readable( $file ) ) {
				return;
		}
			$css = file_get_contents( $file );
		if ( ! is_string( $css ) || '' === $css ) {
				return;
		}
			$fonts_uri = esc_url_raw( PARTIKULIER_URI . '/assets/fonts' );
			$css       = str_replace( '__PK_FONTS__', $fonts_uri, $css );
			printf( "<style id=\"pk-critical-css\">%s</style>\n", $css ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}

		/**
		 * Charge style.min.css sans bloquer le rendu sur l'accueil lorsque le CSS
		 * critique inline est actif (conserve l'ordre DOM avant #pk-desktop-nav-red).
		 */
	public static function defer_main_stylesheet( $html, $handle, $href, $media ) {
		if ( 'partikulier-style' !== $handle || is_admin() || ! is_front_page() ) {
				return $html;
		}
		if ( ! is_readable( PARTIKULIER_DIR . '/assets/css/critical.min.css' ) ) {
				return $html;
		}
			$safe_href = esc_url( $href );
			return sprintf(
				"<link rel=\"stylesheet\" id=\"partikulier-style-css\" href=\"%s\" media=\"print\" onload=\"this.onload=null;this.media='all'\">\n<noscript><link rel=\"stylesheet\" href=\"%s\"></noscript>\n",
				$safe_href,
				$safe_href
			);
	}

		/**
		 * Preconnexion DNS uniquement si necessaire (externe, ex : maps ESTATIK).
		 */
	public static function dns_prefetch() {
			$hosts = array();
		if ( class_exists( 'Estatik' ) && get_option( 'es_google_maps_api_key' ) ) {
				$hosts[] = 'maps.googleapis.com';
				$hosts[] = 'maps.gstatic.com';
		}
		foreach ( $hosts as $host ) {
				printf( '<link rel="dns-prefetch" href="https://%s">%s', esc_attr( $host ), "\n" );
		}
	}
}

Partikulier_Scripts::init();