<?php
/**
 * Module : options du theme.
 *
 * Panneau « Partikulier » dans Apparence → Personnaliser pour modifier
 * les textes du theme (titres, bandeaux, sections) sans toucher au code.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Settings {

	/**
	 * Prefix des options en base (wp_options).
	 */
	const OPTION = 'pk_theme_options';

	/**
	 * Champs de texte modifiables, groupes par onglet du personnaliseur.
	 * Chaque champ : cle => array( 'label', 'default', 'section' ).
	 */
	public static function fields() {
		return array(
			'general' => array(
				'label' => 'Textes principaux',
				'fields' => array(
					'site_tagline' => array(
						'label'   => 'Phrase d\'accroche du hero (accueil)',
						'default' => 'Vendez et louez entre particuliers.',
					),
					'site_intro' => array(
						'label'   => 'Description du hero (accueil)',
						'default' => 'Déposez votre annonce immobilière gratuitement, sans commission, sans intermédiaire. Directement aux acheteurs et locataires.',
					),
					'btn_deposit' => array(
						'label'   => 'Bouton du hero « Déposer une annonce »',
						'default' => 'Publier gratuitement',
					),
					'btn_listings' => array(
						'label'   => 'Bouton du hero « Voir les annonces »',
						'default' => 'Chercher par ville',
					),
					'topbar_text' => array(
						'label'   => 'Texte du bandeau du haut (topbar)',
						'default' => 'Annonces 100% gratuites — Publiez en 2 minutes sans commission',
					),
				),
			),
			'services' => array(
				'label' => 'Bande des 4 services (accueil)',
				'fields' => array(
					'service1_name' => array(
						'label'   => 'Service 1 — titre',
						'default' => 'Annonces gratuites',
					),
					'service1_desc' => array(
						'label'   => 'Service 1 — description',
						'default' => 'Publiez sans rien payer',
					),
					'service2_name' => array(
						'label'   => 'Service 2 — titre',
						'default' => 'Zéro commission',
					),
					'service2_desc' => array(
						'label'   => 'Service 2 — description',
						'default' => 'Sans intermédiaire',
					),
					'service3_name' => array(
						'label'   => 'Service 3 — titre',
						'default' => 'Visio disponible',
					),
					'service3_desc' => array(
						'label'   => 'Service 3 — description',
						'default' => 'Visitez à distance',
					),
					'service4_name' => array(
						'label'   => 'Service 4 — titre',
						'default' => 'En ligne en 2 min',
					),
					'service4_desc' => array(
						'label'   => 'Service 4 — description',
						'default' => 'Sans inscription',
					),
				),
			),
			'sections' => array(
				'label' => 'Titres des sections (accueil)',
				'fields' => array(
					'section_types_kicker' => array(
						'label'   => 'Types de biens — petit titre au-dessus',
						'default' => 'Trouvez votre bien',
					),
					'section_types_title' => array(
						'label'   => 'Types de biens — titre',
						'default' => 'Types de biens',
					),
					'section_types_desc' => array(
						'label'   => 'Types de biens — description',
						'default' => 'Explorez les annonces par catégorie de bien immobilier.',
					),
					'section_recent_kicker' => array(
						'label'   => 'Dernières annonces — petit titre au-dessus',
						'default' => 'Fraîchement publiées',
					),
					'section_recent_title' => array(
						'label'   => 'Dernières annonces — titre',
						'default' => 'Dernières annonces',
					),
					'section_recent_desc' => array(
						'label'   => 'Dernières annonces — description',
						'default' => 'Les annonces immobilières les plus récentes de tous les particuliers.',
					),
					'section_cities_title' => array(
						'label'   => 'Villes populaires — titre',
						'default' => 'Villes populaires',
					),
					'section_cities_desc' => array(
						'label'   => 'Villes populaires — description',
						'default' => 'Découvrez les annonces des villes les plus actives.',
					),
				),
			),
			'verification' => array(
				'label' => 'Validation WhatsApp',
				'fields' => array(
					'whatsapp_validation_number' => array(
						'label'   => 'Numéro WhatsApp de validation (format international, sans espaces)',
						'default' => '',
					),
					'whatsapp_message' => array(
						'label'   => 'Message WhatsApp prérempli — balises : {code} {titre} {ville} {prix} {lien} {nom}',
						'default' => 'Bonjour, je souhaite valider ma demande de publication Partikulier. Mon code est : {code}',
						'type'    => 'textarea',
					),
					'buyer_whatsapp_number' => array(
						'label'   => 'Numéro WhatsApp Business des demandes acquéreurs (format international, sans espaces)',
						'default' => '',
					),
					'n8n_webhook_url' => array(
						'label'   => 'URL du webhook n8n (recoit les annonces validees)',
						'default' => '',
					),
					'automation_api_secret' => array(
						'label'   => 'Secret API n8n / WhatsApp Business (ne jamais partager)',
						'default' => '',
						'type'    => 'password',
					),
				),
			),
			'footer' => array(
				'label' => 'Pied de page',
				'fields' => array(
					'footer_about_text' => array(
						'label'   => 'Colonne « À propos » — texte',
						'default' => 'Portail immobilier 100% gratuit entre particuliers. Publiez votre annonce sans commission ni intermédiaire.',
					),
					'footer_cta_title' => array(
						'label'   => 'Bandeau d\'appel à l\'action — titre',
						'default' => 'Votre bien mérite d\'être vu',
					),
					'footer_cta_text' => array(
						'label'   => 'Bandeau d\'appel à l\'action — texte',
						'default' => 'Déposez votre annonce en 2 minutes, sans inscription préalable.',
					),
					'footer_cta_btn' => array(
						'label'   => 'Bandeau d\'appel à l\'action — bouton',
						'default' => 'Déposer mon annonce',
					),
				),
			),
		);
	}

	public static function init() {
		add_action( 'customize_register', array( __CLASS__, 'register' ) );
	}

	/**
	 * Langues éditoriales prises en charge par le panneau dédié.
	 */
	public static function editorial_languages() {
		return array(
			'fr' => 'Français',
			'ar' => 'العربية',
			'en' => 'English',
		);
	}

	/**
	 * Langue active du site, avec repli français hors Polylang.
	 */
	public static function current_language() {
		if ( function_exists( 'pll_current_language' ) ) {
			try {
				$language = pll_current_language( 'slug' );
				if ( isset( self::editorial_languages()[ $language ] ) ) {
					return $language;
				}
			} catch ( Throwable $exception ) {
				// Certains contextes CLI chargent Polylang sans langue courante exploitable.
			}
		}
		$locale = function_exists( 'get_locale' ) ? strtolower( (string) get_locale() ) : 'fr_FR';
		return 0 === strpos( $locale, 'ar' ) ? 'ar' : ( 0 === strpos( $locale, 'en' ) ? 'en' : 'fr' );
	}

	/**
	 * Valeur éditoriale par langue, utilisée par les templates publics.
	 */
	public static function get_localized( $key, $fallback = '' ) {
		$opts      = get_option( self::OPTION, array() );
		$localized = is_array( $opts ) && isset( $opts['localized'] ) && is_array( $opts['localized'] ) ? $opts['localized'] : array();
		$current   = self::current_language();
		$default = 'fr';
		if ( function_exists( 'pll_default_language' ) ) {
			try {
				$polylang_default = (string) pll_default_language( 'slug' );
				$default = isset( self::editorial_languages()[ $polylang_default ] ) ? $polylang_default : 'fr';
			} catch ( Throwable $exception ) {
				// Le français reste le repli sûr si Polylang n’est pas initialisé.
			}
		}

		foreach ( array_unique( array( $current, $default ) ) as $language ) {
			if ( isset( $localized[ $language ][ $key ] ) && '' !== trim( (string) $localized[ $language ][ $key ] ) ) {
				return (string) $localized[ $language ][ $key ];
			}
		}

		if ( isset( $opts[ $key ] ) && '' !== (string) $opts[ $key ] ) {
				$value = (string) $opts[ $key ];
				$translated = self::localized_default( $key, $value, $current );
				return '' !== $translated ? $translated : $value;
			}
		$translated = self::localized_default( $key, $fallback, $current );
		return '' !== $translated ? $translated : $fallback;
	}

	/**
	 * Traductions natives des valeurs par défaut du personnalisateur.
	 * Une valeur localisée enregistrée garde toujours la priorité.
	 */
	private static function localized_default( $key, $value, $language ) {
		$map = array(
			'site_tagline' => array( 'en' => 'Buy and rent directly from private owners.', 'ar' => 'اشترِ واكترِ مباشرة من المالكين' ),
			'site_intro' => array( 'en' => 'Post your property for free, with no commission or middleman. Reach buyers and tenants directly.', 'ar' => 'أضف عقارك مجاناً، بدون عمولة أو وسيط. تواصل مباشرة مع المشترين والمستأجرين.' ),
			'btn_deposit' => array( 'en' => 'Post for free', 'ar' => 'أضف إعلاناً مجاناً' ),
			'btn_listings' => array( 'en' => 'Search by city', 'ar' => 'ابحث حسب المدينة' ),
			'topbar_text' => array( 'en' => '100% free listings — Publish in 2 minutes with no commission', 'ar' => 'إعلانات مجانية 100٪ — أضف إعلانك خلال دقيقتين بدون عمولة' ),
			'service1_name' => array( 'en' => 'Free listings', 'ar' => 'إعلانات مجانية' ), 'service1_desc' => array( 'en' => 'Publish at no cost', 'ar' => 'انشر بدون تكلفة' ),
			'service2_name' => array( 'en' => 'No commission', 'ar' => 'بدون عمولة' ), 'service2_desc' => array( 'en' => 'No middleman', 'ar' => 'بدون وسيط' ),
			'service3_name' => array( 'en' => 'Video visits available', 'ar' => 'زيارات عبر الفيديو' ), 'service3_desc' => array( 'en' => 'Visit remotely', 'ar' => 'زر عن بُعد' ),
			'service4_name' => array( 'en' => 'Online in 2 minutes', 'ar' => 'متاح خلال دقيقتين' ), 'service4_desc' => array( 'en' => 'No registration required', 'ar' => 'بدون تسجيل' ),
			'section_types_kicker' => array( 'en' => 'Find your property', 'ar' => 'اعثر على عقارك' ), 'section_types_title' => array( 'en' => 'Property types', 'ar' => 'أنواع العقارات' ),
			'section_types_desc' => array( 'en' => 'Explore listings by property category.', 'ar' => 'استكشف الإعلانات حسب فئة العقار.' ),
			'section_recent_kicker' => array( 'en' => 'Recently published', 'ar' => 'أضيفت حديثاً' ), 'section_recent_title' => array( 'en' => 'Latest listings', 'ar' => 'أحدث الإعلانات' ),
		);
		$defaults = array();
		foreach ( self::fields() as $group ) {
			foreach ( $group['fields'] as $field_key => $field ) {
				$defaults[ $field_key ] = $field['default'];
			}
		}
		if ( isset( $map[ $key ][ $language ], $defaults[ $key ] ) && (string) $value === (string) $defaults[ $key ] ) {
			return $map[ $key ][ $language ];
		}
		return '';
	}

	/**
	 * Valeur d'un champ (option sauvegardee ou defaut).
	 */
	public static function get( $key ) {
		$opts = get_option( self::OPTION, array() );
		if ( ! is_array( $opts ) ) {
			$opts = array();
		}
		/* Reprise a la lecture : la valeur a pu rester dans loption heritee « pk_opts ».
		   On lit seulement — la consolidation (deplacer + supprimer) se fait dans
		   migrate_legacy_pk_opts(), cote admin. Sans ce repli, tout un chacun voyait
		   « champ vide » alors que le reglage etait fonctionnel. */
		if ( ( ! isset( $opts[ $key ] ) || '' === trim( (string) $opts[ $key ] ) ) && self::has_legacy( $key ) ) {
			$leg = get_option( 'pk_opts', array() );
			if ( is_array( $leg ) && isset( $leg[ $key ] ) && is_scalar( $leg[ $key ] ) ) {
				$opts[ $key ] = sanitize_text_field( (string) $leg[ $key ] );
			}
		}
		$value = '';
		$is_editorial = false;
		foreach ( self::fields() as $group_key => $group ) {
			if ( 'verification' !== $group_key && isset( $group['fields'][ $key ] ) ) {
				$is_editorial = true;
				break;
			}
		}
		if ( $is_editorial ) {
			$value = self::get_localized( $key, '' );
		} elseif ( isset( $opts[ $key ] ) && '' !== $opts[ $key ] ) {
			$value = $opts[ $key ];
		}
		foreach ( self::fields() as $group ) {
			if ( '' === $value && isset( $group['fields'][ $key ] ) ) {
				$value = $group['fields'][ $key ]['default'];
				break;
			}
		}

		if ( $is_editorial && 'fr' !== self::current_language() ) {
			$localized_default = self::localized_default( $key, $value, self::current_language() );
			if ( '' !== $localized_default ) {
				$value = $localized_default;
			}
		}
		if ( $is_editorial && isset( $opts['localized'] ) && is_array( $opts['localized'] ) ) {
			return $value;
		}
		return class_exists( 'Partikulier_Localization' )
			? Partikulier_Localization::translate_public_string( $value )
			: $value;
	}

	/**
	 * Les secrets d’automatisation peuvent être injectés par la configuration
	 * d’hébergement. La valeur d’environnement prévaut sur l’ancien réglage
	 * du personnaliseur afin qu’aucun secret de production ne dépende de l’UI.
	 */
	public static function automation_api_secret() {
		if ( class_exists( 'Partikulier_N8n_Security' ) ) {
			return (string) Partikulier_N8n_Security::get( 'automation_api_secret' );
		}
		if ( defined( 'PARTIKULIER_AUTOMATION_API_SECRET' ) && PARTIKULIER_AUTOMATION_API_SECRET ) {
			return (string) PARTIKULIER_AUTOMATION_API_SECRET;
		}
		$environment_secret = getenv( 'PARTIKULIER_AUTOMATION_API_SECRET' );
		if ( false !== $environment_secret && '' !== $environment_secret ) {
			return (string) $environment_secret;
		}
		return (string) self::get( 'automation_api_secret' );
	}

	/**
	 * Enregistre le panneau + sections + champs dans le personnaliseur.
	 */
	public static function register( $wp_customize ) {
		$wp_customize->add_panel( 'pk_theme_panel', array(
			'title'       => __( 'Partikulier — Textes du site', 'partikulier' ),
			'description' => __( 'Modifiez ici les textes du site (titres, bandeaux, sections) sans toucher au code du thème. Pour changer le nom des onglets du menu, utilisez Apparence → Menus.', 'partikulier' ),
			'priority'    => 30,
		) );

		$priority = 10;
		foreach ( self::fields() as $group_key => $group ) {
			$wp_customize->add_section( 'pk_' . $group_key, array(
				'title'    => $group['label'],
				'panel'    => 'pk_theme_panel',
				'priority' => $priority++,
			) );
			foreach ( $group['fields'] as $field_key => $field ) {
				// Un textarea doit conserver ses retours a la ligne.
				$sanitize = ( isset( $field['type'] ) && 'textarea' === $field['type'] )
					? 'sanitize_textarea_field'
					: 'sanitize_text_field';

				/* L'identifiant d'un reglage de type « option » EST le nom de l'option,
				   suivi de [touche] pour ecrire dans un tableau : WordPress ecrit dans
				   get_option( id_base ). Avec l'ancien « pk_opts[cle] », les valeurs saisies
				   dans le Personnalisateur partaient dans une option fantome « pk_opts »
				   que le theme ne lit jamais (prouve sur WP 7.1 : Settings::get() relisait
				   '' apres publication, d'ou le voyant « WhatsApp absent » permanent, meme
				   apres re-saisie). L'argument « option » etait ignore par le coeur.
				   Partikulier_Settings_Customize_Setting (fichier charge apres celui-ci)
				   connait en plus loption heritee, pour que le champ affiche la valeur
				   deja saisie au lieu d'un champ vide. */
				$classe = class_exists( 'Partikulier_Settings_Customize_Setting' ) ? 'Partikulier_Settings_Customize_Setting' : 'WP_Customize_Setting';
				$wp_customize->add_setting( new $classe(
					$wp_customize,
					self::OPTION . '[' . $field_key . ']',
					array(
						'type'              => 'option',
						'default'           => $field['default'],
						'sanitize_callback' => $sanitize,
						'autoload'          => false,
					)
				) );
				$wp_customize->add_control( 'pk_opts_' . $field_key, array(
					'label'   => $field['label'],
					'section' => 'pk_' . $group_key,
					'settings' => self::OPTION . '[' . $field_key . ']',
						'type'    => isset( $field['type'] ) ? $field['type'] : 'text',
					) );
			}
		}
	}
	/**
	 * La cle a-t'elle une valeur en reserve dans loption heritee « pk_opts » ?
	 *
	 * @param string $key Nom du champ.
	 * @return bool
	 */
	public static function has_legacy( $key ) {
		static $leg = null;
		if ( null === $leg ) {
			$l   = get_option( 'pk_opts', array() );
			$leg = is_array( $l ) ? $l : array();
		}
		return isset( $leg[ $key ] ) && '' !== trim( (string) $leg[ $key ] );
	}

	/**
	 * Reprise definitive : deplace les valeurs de loption heritee vers
	 * pk_theme_options, puis supprime l heritiere. Une fois par requete, et
	 * seulement pour un utilisateur qui peut gerer les reglages : la lecture d une
	 * page publique ne doit jamais ecrire en base.
	 *
	 * Accroche : admin_init (pas init) — a l instant ou init se declenche,
	 * l identifie n est pas encore resolu pour les requetes admin, et le garde
	 * « une fois » etait consomme sans rien deplacer (mesure en test).
	 *
	 * @return void
	 */
	public static function migrate_legacy_pk_opts() {
		static $fait = false;
		if ( $fait ) {
			return;
		}
		$fait = true;
		$legacy = get_option( 'pk_opts', array() );
		if ( ! is_array( $legacy ) || ! $legacy || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$theme = get_option( self::OPTION, array() );
		if ( ! is_array( $theme ) ) {
			$theme = array();
		}
		$deplaces = 0;
		foreach ( $legacy as $cle => $valeur ) {
			if ( ! is_scalar( $valeur ) || '' === trim( (string) $valeur ) ) {
				continue;
			}
			if ( isset( $theme[ $cle ] ) && '' !== trim( (string) $theme[ $cle ] ) ) {
				continue; // la valeur deja en place gagne
			}
			$theme[ $cle ] = sanitize_text_field( (string) $valeur );
			++$deplaces;
		}
		if ( $deplaces > 0 ) {
			update_option( self::OPTION, $theme );
			delete_option( 'pk_opts' );
		}
	}

}

add_action( 'admin_init', array( 'Partikulier_Settings', 'migrate_legacy_pk_opts' ), 2 );

Partikulier_Settings::init();