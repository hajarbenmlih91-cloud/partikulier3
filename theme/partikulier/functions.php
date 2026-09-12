<?php
/**
 * Partikulier — Theme de portail immobilier.
 *
 * Zero jQuery. Cache de page integre. Conversion AVIF.
 * Schema.org JSON-LD. SEO/Geo/LLM-ready. Concu pour ESTATIK.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

define( 'PARTIKULIER_VERSION', '6.20.2' );

add_filter(
    'language_attributes',
    static function ( $output ) {
        $language = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : substr( get_locale(), 0, 2 );
        $direction = 'ar' === $language ? 'rtl' : 'ltr';
        if ( false === strpos( $output, ' dir=' ) ) {
            $output .= ' dir="' . esc_attr( $direction ) . '"';
        }
        return $output;
    },
    10,
    1
);
define( 'PARTIKULIER_DIR', get_template_directory() );
define( 'PARTIKULIER_URI', get_template_directory_uri() );
define( 'PARTIKULIER_ESTATIK_POST_TYPE', 'properties' );
define( 'PARTIKULIER_ESTATIK_TYPE_TAXONOMY', 'es_type' );
define( 'PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY', 'es_category' );
define( 'PARTIKULIER_ESTATIK_STATUS_TAXONOMY', 'es_status' );
define( 'PARTIKULIER_ESTATIK_LOCATION_TAXONOMY', 'es_location' );

/**
 * URL des annonces Estatik v4, avec un repli lisible avant l'initialisation du plugin.
 *
 * @return string
 */
function pk_properties_archive_url() {
        // L’archive publique est un contrat du portail, pas un détail du slug
        // retourné par Estatik. Construire cette URL explicitement évite les 404
        // lorsque le plugin expose encore son ancien `/property/`.
        if ( function_exists( 'pll_current_language' ) && function_exists( 'pll_home_url' ) ) {
                $language = sanitize_key( (string) pll_current_language( 'slug' ) );
                if ( $language ) {
                        return pk_localized_home_url( $language ) . 'annonces/';
                }
        }
        return home_url( '/annonces/' );
}

/**
 * Accueil localisé avec repli explicite si Polylang renvoie la racine.
 *
 * @param string $language Code de langue.
 * @return string
 */
function pk_localized_home_url( $language = '' ) {
        $language = sanitize_key( (string) $language );
        if ( ! $language && function_exists( 'pll_current_language' ) ) {
                $language = sanitize_key( (string) pll_current_language( 'slug' ) );
        }
        // Polylang peut ne pas encore exposer la langue pendant certains rendus
        // froids. La route publique reste alors la source de vérité fonctionnelle.
        if ( ! $language && isset( $_SERVER['REQUEST_URI'] ) ) {
                $request_path = sanitize_text_field( (string) wp_parse_url( wp_unslash( (string) $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- REQUEST_URI is unslashed and sanitized for route detection
                $first_segment = sanitize_key( (string) strtok( trim( $request_path, '/' ), '/' ) );
                if ( in_array( $first_segment, array( 'fr', 'en', 'ar' ), true ) ) {
                        $language = $first_segment;
                }
        }
        if ( ! $language && function_exists( 'determine_locale' ) ) {
                $language = sanitize_key( substr( (string) determine_locale(), 0, 2 ) );
        }
        if ( ! $language ) {
                return trailingslashit( home_url( '/' ) );
        }

        $url  = function_exists( 'pll_home_url' ) ? pll_home_url( $language ) : home_url( '/' . $language . '/' );
        $path = (string) wp_parse_url( $url, PHP_URL_PATH );
        if ( ! preg_match( '#(?:^|/)' . preg_quote( $language, '#' ) . '/?$#', untrailingslashit( $path ) ) ) {
                $url = home_url( '/' . $language . '/' );
        }
        return trailingslashit( $url );
}

/**
 * Force le slug public francisé de l’archive Estatik sans créer de page /annonces/.
 *
 * @param array  $args Arguments du type de contenu.
 * @param string $post_type Identifiant du type.
 * @return array
 */
function pk_properties_post_type_args( $args, $post_type ) {
        if ( PARTIKULIER_ESTATIK_POST_TYPE === $post_type ) {
                $args['has_archive'] = 'annonces';
        }
        return $args;
}
add_filter( 'register_post_type_args', 'pk_properties_post_type_args', 20, 2 );

/**
 * Résout l’URL d’une page structurelle dans la langue courante.
 * Polylang reçoit l’ID de la page source et renvoie sa traduction publiée.
 *
 * @param string $slug Slug de la page source.
 * @param string $fallback Chemin de secours uniquement si la page n’existe pas.
 * @return string
 */
function pk_page_url( $slug, $fallback = '/' ) {
        $slug = trim( (string) $slug, '/' );
        $page = get_page_by_path( $slug, OBJECT, 'page' );
        // Le provisioning du thème connaît les slugs canoniques et leurs alias
        // historiques (par exemple deposer-une-annonce). Utiliser ce résolveur
        // évite qu’un lien public retombe silencieusement sur une route obsolète.
        if ( ! $page && class_exists( 'Partikulier_Required_Pages' ) ) {
                $page = Partikulier_Required_Pages::find( $slug );
        }
        if ( $page && function_exists( 'pll_get_post' ) ) {
                $translated_id = pll_get_post( $page->ID );
                if ( $translated_id ) {
                        $page = get_post( $translated_id );
                }
        }
        if ( $page instanceof WP_Post ) {
                return get_permalink( $page );
        }
        return home_url( $fallback );
}

/**
 * URL de connexion PUBLIQUE : la page « Connexion » du thème si elle existe
 * (design du site, écran sélectionnable via ?auth_item=, retour à la page
 * d'origine via ?redirect_url=), sinon repli wp-login.php.
 *
 * Les internautes ne voient plus wp-login.php tant que la page du thème
 * est provisionnée ; l'administration WP conserve son écran natif.
 *
 * @param string $redirect URL de retour après connexion (facultative).
 * @return string
 */
function pk_login_page_url( $redirect = '' ) {
        $page = get_page_by_path( 'connexion', OBJECT, 'page' );
        if ( ! $page && class_exists( 'Partikulier_Required_Pages' ) ) {
                $page = Partikulier_Required_Pages::find( 'connexion' );
        }
        if ( $page instanceof WP_Post && function_exists( 'pll_get_post' ) ) {
                $translated_id = pll_get_post( $page->ID );
                if ( $translated_id ) {
                        $page = get_post( $translated_id );
                }
        }
        if ( ! $page instanceof WP_Post ) {
                return wp_login_url( $redirect );
        }
        $url = get_permalink( $page );
        if ( $redirect ) {
                // Encodage manuel déterministe : add_query_arg n'encode pas
                // les valeurs lui-même.
                $url .= ( false === strpos( $url, '?' ) ? '?' : '&' )
                        . 'auth_item=login-form&redirect_url=' . rawurlencode( $redirect );
        }
        return $url;
}

/**
 * Chargement modulaire des modules du theme.
 */
$partikulier_modules = array(
        '/inc/class-theme-setup.php',
        '/inc/class-scripts.php',
        '/inc/class-optimization.php',
        '/inc/class-seo.php',
        '/inc/class-jsonld.php',
        '/inc/class-sitemap.php',
        '/inc/class-cache.php',
        '/inc/class-security.php',
        /* Lot E (SECU-1) : passerelle unique des appels système — chargée
         * AVANT class-avif.php qui l'utilise pour avifenc/vips. */
        '/inc/class-exec-whitelist.php',
        '/inc/class-avif.php',
        '/inc/class-geo.php',
        '/inc/class-search-filters.php',
        '/inc/class-form.php',
        '/inc/class-dashboard.php',
        '/inc/class-owner-insights.php',
        '/inc/class-estatik.php',
        '/inc/class-n8n-security.php',
        '/inc/class-settings.php',
        '/inc/class-customization.php',
        '/inc/class-whatsapp-verification.php',
        '/inc/class-buyer-qualification.php',
        '/inc/class-lead-retention.php',
        '/inc/class-leads-admin.php',
        '/inc/class-premium.php',
        /* Lot B6 (découpe REG-3) : les quatre modules de localisation sont
         * chargés AVANT le shell class-localization.php — les traits qu'il
         * compose doivent exister à l'évaluation de sa définition de classe. */
        '/inc/class-localization-runtime.php',
        '/inc/class-localization-strings.php',
        '/inc/class-localization-chrome.php',
        '/inc/class-localization-forms.php',
        /* Lot C2 (découpe annexe C ≤ 300 l.) : repli variantes B6 déplacé
         * VERBATIM dans son trait dédié, chargé avant le shell. */
        '/inc/class-localization-variants.php',
        '/inc/class-localization.php',
        '/inc/class-saved-alerts.php',
        '/inc/class-automation-bridge.php',
        '/inc/class-payment-foundation.php',
        '/inc/class-page-templates.php',
        '/inc/class-required-pages.php',
        '/inc/class-morocco-places.php',
        '/inc/class-place-requests.php',
        '/inc/class-listing-preview.php',
        /* Lot C1 (découpe) : les cinq modules de rédaction multilingue sont
         * chargés AVANT le shell class-listing-i18n.php — les traits qu'il
         * compose doivent exister à l'évaluation de sa définition de classe. */
        '/inc/class-listing-i18n-lexicon.php',
        '/inc/class-listing-i18n-places.php',
        '/inc/class-listing-i18n-text.php',
        '/inc/class-listing-i18n-seo.php',
        '/inc/class-listing-i18n-post.php',
        '/inc/class-listing-i18n.php',
        '/inc/class-listing-translations.php',
        '/inc/class-upgrade-wizard.php',
        '/inc/class-page-doctor.php',
        '/inc/class-listing-approval.php',
        '/inc/class-listing-urls.php',
        '/inc/class-settings-customize.php',
        '/templates/parts/menu.php',
        '/templates/parts/helpers.php',
);

// La collection REST du core ne rend aucun HTML et n’utilise aucun module du
// thème. Éviter leur bootstrap sur cette route réduit le coût CPU sans
// modifier les réponses, les routes front ou les contrats de présentation.
        $partikulier_rest_route = isset( $_GET['rest_route'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['rest_route'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- route detection, not form processing
        $partikulier_request_uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( (string) wp_unslash( $_SERVER['REQUEST_URI'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- route detection, not form processing
$partikulier_core_listing_rest = str_contains( $partikulier_request_uri, '/wp-json/partikulier/v1/listings' )
        || str_starts_with( $partikulier_rest_route, '/partikulier/v1/listings' );
if ( $partikulier_core_listing_rest ) {
        $partikulier_modules = array();
}

foreach ( $partikulier_modules as $module ) {
        $file = PARTIKULIER_DIR . $module;
        if ( file_exists( $file ) ) {
                require_once $file;
        }
}

/*
 * LOT 2 — Isolation du diagnostic : 2 600 lignes de sonde ne doivent plus etre
 * parsees sur chaque GET public. Chargement limite a :
 *   1. l'ecran d'administration (is_admin() + manage_options) ;
 *   2. la ligne de commande (WP_CLI) ;
 *   3. l'entree URL signee d'un administrateur CONNECTE (?pk_diag=...&jeton=...) :
 *      sans ce 3e cas, les six liens « Transmettre le rapport / relancer un test »
 *      de la page d'admin mourraient — ils pointent vers des URLs front.
 * Un visiteur anonyme, un robot ou une page publique ne chargent JAMAIS la sonde.
 */
$partikulier_diagnostic_file = PARTIKULIER_DIR . '/pk-diagnostic.php';
$partikulier_diagnostic_load = ( defined( 'WP_CLI' ) && WP_CLI )
        || ( is_admin() && current_user_can( 'manage_options' ) )
        || ( isset( $_GET['pk_diag'] ) && current_user_can( 'manage_options' ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- detecteur de presence, la validation du jeton reste dans la sonde
if ( $partikulier_diagnostic_load && file_exists( $partikulier_diagnostic_file ) ) {
        require_once $partikulier_diagnostic_file;
}
