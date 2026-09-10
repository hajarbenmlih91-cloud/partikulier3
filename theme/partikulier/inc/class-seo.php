<?php
/**
 * Module : SEO on-page (title, meta description, canonical, robots, OG, Twitter).
 * Remplace Rank Math : tout est generé depuis le theme.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_SEO {

        public static function init() {
                add_filter( 'document_title_parts', array( __CLASS__, 'title' ), 10, 1 );
                remove_action( 'wp_head', 'rel_canonical' );
                /* La balise <meta name="generator"> imprime la version exacte du core.
                 * Mesuree par la sonde : « balise generator (version exposee) : oui ».
                 * Le theme l'imprimait lui-meme (meta_head) ET le core la reimprimait via
                 * wp_generator : on retire celle du core, et celle du theme part avec ce
                 * correctif. La version de WordPress ne regarde personne cote public.
                 */
                remove_action( 'wp_head', 'wp_generator' );
                add_action( 'wp_head', array( __CLASS__, 'meta_head' ), 1 );
                add_action( 'wp_head', array( __CLASS__, 'hreflang_head' ), 2 );
        }

        /**
         * Titre SEO : [Contenu] — [Ville] | Partikulier.com
         */
        public static function title( $parts ) {
                $site = get_bloginfo( 'name' );

                if ( is_singular( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
                        $post     = get_queried_object();
                        $location = self::geo_chain( $post );
                        $parts['title'] = wp_strip_all_tags( get_the_title( $post ) );
                        $parts['site']  = $location ? $location . ' | ' . $site : $site;
                } elseif ( is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
                        /* 6.17.30 : l'archive heritait du label Estatik « Property »
                         * (non traduit) comme <title> dans les trois langues.
                         * On impose le libelle localise de l'archive d'annonces.
                         */
                        $parts['title'] = __( 'Annonces', 'partikulier' );
                        $parts['site']  = $site;
                } elseif ( is_tax() ) {
                        $term = get_queried_object();
                        if ( $term instanceof WP_Term && in_array( $term->taxonomy, Partikulier_Setup::GEO_TAX, true ) ) {
                                $parts['title'] = sprintf(
                                        /* translators: %s: type de bien, %s: geo */
                                        __( 'Annonces immobilières à %s', 'partikulier' ),
                                        wp_strip_all_tags( $term->name )
                                );
                                $parts['site'] = $site;
                        }
                }

                return $parts;
        }

        /**
         * Chaine geographique : "Vente appartement Paris 15e, Île-de-France".
         *
         * 6.17.30 : la variation SEO deterministe (regle client n°5) est
         * desormais redigee dans la langue de la page — « A vendre Terrain à
         * Marrakech » ne fuit plus dans les <title> EN/AR (onglet + SERP).
         */
        public static function geo_chain( $post ) {
                $location = self::term_name( $post, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
                $category = self::term_name( $post, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
                $type     = self::term_name( $post, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );

                $locale = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr';
                if ( 'ar' === $locale || 'en' === $locale ) {
                        if ( class_exists( 'Partikulier_Listing_I18n' ) ) {
                                if ( $category ) {
                                        $category = self::localized_status( $category, $locale );
                                }
                                if ( $type ) {
                                        $type = Partikulier_Listing_I18n::localized_type( $type, $locale );
                                }
                                if ( $location ) {
                                        $location = Partikulier_Listing_I18n::localized_place( $location, $locale );
                                }
                        }
                        $context = trim( implode( ' ', array_filter( array( $category, $type ) ) ) );
                        if ( $context && $location ) {
                                return sprintf( 'ar' === $locale ? '%1$s في %2$s' : '%1$s in %2$s', $context, $location );
                        }
                        return $context ?: $location;
                }

                $context  = trim( implode( ' ', array_filter( array( $category, $type ) ) ) );

                if ( $context && $location ) {
                        return sprintf( '%1$s à %2$s', $context, $location );
                }

                return $context ?: $location;
        }

        /**
         * Libelle du statut (transaction) dans la langue de la page.
         *
         * @param string $status Terme source (« A vendre », « A louer »…).
         * @param string $locale Langue cible.
         * @return string
         */
        private static function localized_status( $status, $locale ) {
                $key = trim( (string) $status );
                $map = array(
                        'A vendre' => array( 'en' => 'For sale', 'ar' => 'للبيع' ),
                        'A louer'  => array( 'en' => 'For rent', 'ar' => 'للإيجار' ),
                        'Vente'    => array( 'en' => 'Sale',     'ar' => 'للبيع' ),
                        'Location' => array( 'en' => 'Rental',   'ar' => 'للإيجار' ),
                );
                return isset( $map[ $key ] ) && isset( $map[ $key ][ $locale ] ) ? $map[ $key ][ $locale ] : $key;
        }

        /**
         * Meta description + canonical + robots + OG + Twitter.
         */
        public static function meta_head() {
                $queried = get_queried_object();

                $url = self::canonical_url();
                printf( '<link rel="canonical" href="%s">%s', esc_url( $url ), "\n" );

                // --- Meta description ---
                $desc = self::description( $queried );
                if ( $desc ) {
                        printf(
                                '<meta name="description" content="%s">%s',
                                esc_attr( $desc ),
                                "\n"
                        );
                        printf(
                                '<meta property="og:description" content="%s">%s',
                                esc_attr( $desc ),
                                "\n"
                        );
                        printf(
                                '<meta name="twitter:description" content="%s">%s',
                                esc_attr( $desc ),
                                "\n"
                        );
                }

                // --- Robots ---
                $robots = self::robots_directive();
                if ( $robots ) {
                        printf( '<meta name="robots" content="%s">%s', esc_attr( $robots ), "\n" );
                }

                // --- OG generiques ---
                printf( '<meta property="og:url" content="%s">%s', esc_url( $url ), "\n" );
                printf( '<meta property="og:site_name" content="%s">%s', esc_attr( get_bloginfo( 'name' ) ), "\n" );
                printf( '<meta property="og:locale" content="%s">%s', esc_attr( get_locale() ), "\n" );

                if ( is_singular( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
                        self::og_property( $queried );
                } else {
                        /* Mesure sonde (400 URLs) : 354 pages sans og:image, dont l'accueil et
                 * toutes les archives. Or le trafic du portail vit du partage WhatsApp et
                 * Facebook : un lien sans image n'a pas d'apercu dans le fil. Le hero du
                 * site (image d'accueil administrable) devient l'image de secours de toutes
                 * les pages qui n'ont pas la leur — le pattern standard d'un og:image par
                 * defaut. La fiche annonce garde sa photo quand elle existe (og_property). */
                        $pk_og_img = self::og_image_fallback();
                        printf( '<meta property="og:type" content="website">%s', "\n" );
                        printf( '<meta property="og:title" content="%s">%s', esc_attr( is_front_page() ? get_bloginfo( 'name' ) : wp_get_document_title() ), "\n" );
                        if ( $pk_og_img[0] ) {
                                printf( '<meta property="og:image" content="%s">%s', esc_url( $pk_og_img[0] ), "\n" );
                                printf( '<meta property="og:image:alt" content="%s">%s', esc_attr( $pk_og_img[1] ), "\n" );
                        }
                        printf( '<meta name="twitter:card" content="%s">%s', ( $pk_og_img[0] ? 'summary_large_image' : 'summary' ), "\n" );
                }
        }

        /**
         * Variantes linguistiques réciproques pour les pages traduites par Polylang.
         * Le contenu libre des propriétaires n’est pas modifié ici.
         */
        public static function hreflang_head() {
                if ( ! function_exists( 'pll_the_languages' ) ) {
                        return;
                }

                        // Polylang emet deja les trois alternates sur les contenus relies.
                        // Le theme complete uniquement x-default pour eviter les doublons
                        // contradictoires (fr/en/ar et fr-FR/en-US/ar-MA).
                        if ( is_singular() && function_exists( 'pll_get_post_translations' ) ) {
                                $existing = pll_get_post_translations( get_queried_object_id() );
                                if ( count( $existing ) > 1 ) {
                                        $default = function_exists( 'pll_default_language' ) ? pll_default_language() : 'fr';
                                        if ( ! empty( $existing[ $default ] ) && 'publish' === get_post_status( $existing[ $default ] ) ) {
                                                printf( '<link rel="alternate" hreflang="x-default" href="%s">%s', esc_url( get_permalink( $existing[ $default ] ) ), "\n" );
                                        }
                                        return;
                                }
                        }

                $translations = array();
                $published    = array();

                        if ( is_singular() ) {
                                $post_id = get_queried_object_id();
                                if ( $post_id ) {
                                        $translations = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $post_id ) : array();
                                }
                        }

                        // L’accueil peut être rendu par le thème sans page statique sélectionnée.
                        // Dans ce cas, construire les URLs depuis les home URLs Polylang.
                        if ( empty( $translations ) && is_front_page() ) {
                                foreach ( array( 'fr', 'ar', 'en' ) as $locale ) {
                                        $published[ $locale ] = function_exists( 'pll_home_url' )
                                                ? pll_home_url( $locale )
                                                : home_url( '/' );
                                }
                        }

                        // Estatik expose une archive de type de contenu, pas une page traduisible.
                        // Les trois archives gardent le même chemin fonctionnel sous chaque langue.
                        if ( empty( $published ) && is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
                                foreach ( array( 'fr', 'ar', 'en' ) as $locale ) {
                                        $base = function_exists( 'pll_home_url' ) ? pll_home_url( $locale ) : home_url( '/' );
                                        $published[ $locale ] = trailingslashit( $base ) . 'annonces/';
                                }
                        }

                        $map = array( 'fr' => 'fr-FR', 'ar' => 'ar-MA', 'en' => 'en-US' );
                        foreach ( $translations as $locale => $translated_id ) {
                                $translated = get_post( $translated_id );
                                if ( $translated instanceof WP_Post && 'publish' === $translated->post_status ) {
                                        $published[ $locale ] = get_permalink( $translated );
                                }
                        }

                        $known_page = self::known_page_hreflang();
                        foreach ( $known_page as $locale => $url ) {
                                if ( empty( $published[ $locale ] ) ) {
                                        $published[ $locale ] = $url;
                                }
                        }

                        if ( empty( $published ) ) {
                                return;
                        }

                foreach ( $published as $locale => $url ) {
                        $hreflang = isset( $map[ $locale ] ) ? $map[ $locale ] : $locale;
                        printf( '<link rel="alternate" hreflang="%s" href="%s">%s', esc_attr( $hreflang ), esc_url( $url ), "\n" );
                }
                if ( isset( $published['fr'] ) ) {
                        printf( '<link rel="alternate" hreflang="x-default" href="%s">%s', esc_url( $published['fr'] ), "\n" );
                }
        }

                /**
                 * Repli pour les pages dont une association Polylang historique peut être
                 * incomplète. Les URLs sont des routes publiques déjà validées sur le live.
                 *
                 * @return array<string,string>
                 */
                private static function known_page_hreflang() {
                        $request = isset( $_SERVER['REQUEST_URI'] ) ? rawurldecode( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
                        $home    = home_url( '/' );
                        $faq     = false;
                        $contact = false;

                        if ( false !== strpos( $request, 'faq' ) || false !== strpos( $request, 'questions-frequentes' ) || false !== strpos( $request, 'frequently-asked-questions' ) || false !== strpos( $request, 'الأسئلة-الشائعة' ) ) {
                                $faq = true;
                        }
                        if ( false !== strpos( $request, 'contact' ) || false !== strpos( $request, 'اتصل-بنا' ) ) {
                                $contact = true;
                        }

                        if ( $faq ) {
                                return array(
                                        'fr' => trailingslashit( $home . 'fr/questions-frequentes' ),
                                        'ar' => trailingslashit( $home . 'ar/الأسئلة-الشائعة' ),
                                        'en' => trailingslashit( $home . 'frequently-asked-questions' ),
                                );
                        }

                        if ( $contact ) {
                                return array(
                                        'fr' => trailingslashit( $home . 'fr/contact' ),
                                        'ar' => trailingslashit( $home . 'ar/اتصل-بنا' ),
                                        'en' => trailingslashit( $home . 'contact-us' ),
                                );
                        }

                        return array();
                }

                /**
                 * Description SEO automatique selon le contexte.
                 */
                        private static function normalize_description( $description ) {
                                $description = trim( preg_replace( '/(?:\\s*[.·,]\\s*){2,}/u', '. ', wp_strip_all_tags( (string) $description ) ) );
                                return trim( preg_replace( '/\\s{2,}/u', ' ', $description ) );
                        }

                        public static function description( $queried ) {
                if ( is_singular( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
                        $post = get_queried_object();

                                // Le texte libre stocké est conservé en FR ; EN/AR utilisent un
                                // gabarit localisé afin de ne jamais injecter une meta française.
                                $locale = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr';
                                $stored = get_post_meta( $post->ID, '_pk_meta_description', true );
                                if ( $stored && 'fr' === $locale ) {
                                        return self::normalize_description( $stored );
                                }
                                if ( 'en' === $locale || 'ar' === $locale ) {
                                        return self::localized_listing_description( $post, $locale );
                                }

                                $desc = trim( wp_strip_all_tags( get_the_excerpt( $post ) ) );
                        if ( ! $desc ) {
                                $desc = trim( wp_strip_all_tags( $post->post_content ) );
                        }
                        $geo = self::geo_chain( $post );
                        $pieces = array_filter( array( $desc, $geo ) );
                        $full = implode( '. ', $pieces );
                        if ( ! $full ) {
                                $full = sprintf(
                                        /* translators: %s: titre de l'annonce */
                                        __( 'Découvrez %s, une annonce immobilière publiée gratuitement entre particuliers sur Partikulier.', 'partikulier' ),
                                        get_the_title( $post )
                                );
                        }
                                                        return self::limit( self::normalize_description( $full ), 155 );
                        }
                        if ( is_tax() && $queried instanceof WP_Term ) {
                        $desc = trim( term_description( $queried ) );
                        if ( ! $desc ) {
                                $desc = sprintf(
                                        /* translators: %s: nom du terme geo */
                                        __( 'Toutes les annonces immobilières gratuites à %s : appartements, maisons, terrains. Publiez votre annonce gratuitement sur Partikulier.com.', 'partikulier' ),
                                        $queried->name
                                );
                        }
                        return self::limit( wp_strip_all_tags( $desc ), 155 );
                }
                if ( is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) ) {
                        return self::limit(
                                __( 'Parcourez les annonces immobilières publiées directement par des particuliers : achat et location sans commission.', 'partikulier' ),
                                155
                        );
                }
                if ( is_page( array( 'deposer', 'deposer-en', 'deposer-ar', 'deposer-une-annonce', 'deposer-annonce' ) ) ) {
                        return self::limit(
                                __( 'Déposez gratuitement votre annonce immobilière entre particuliers en quelques étapes simples et vérifiées.', 'partikulier' ),
                                155
                        );
                }
                if ( is_front_page() ) {
                        $description = get_bloginfo( 'description' );
                        if ( ! $description ) {
                                $description = __( 'Annonces immobilières gratuites entre particuliers : achat et location sans commission.', 'partikulier' );
                        }
                        return self::limit( $description, 155 );
                }
                if ( is_author() && $queried instanceof WP_User ) {
                        return self::limit( get_the_author_meta( 'description', $queried->ID ), 155 );
                }
                return '';
        }

                /** Description localisée des fiches EN/AR sans fuite du texte libre FR. */
                private static function localized_listing_description( $post, $locale ) {
                        /* 6.17.30 : lexique canonique complet de Listing_I18n (riad,
                         * duplex, loft…) — la carte réduite laissait « Riad » latin
                         * dans la meta description AR des fiches de riads. */
                        $type_name = self::term_name( $post, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
                        if ( class_exists( 'Partikulier_Listing_I18n' ) ) {
                                $type = Partikulier_Listing_I18n::localized_type( (string) $type_name, $locale );
                        } else {
                                $type = self::localized_property_type( $type_name, $locale );
                        }
                        $location = self::term_name( $post, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
                        if ( 'ar' === $locale ) {
                                $type     = preg_replace( '/^ترجمة\\s+/u', '', (string) $type );
                                $location = preg_replace( '/^ترجمة\\s+/u', '', (string) $location );
                                $ar_places = array(
                                        'Casablanca' => 'الدار البيضاء',
                                        'Rabat'      => 'الرباط',
                                        'Marrakech'  => 'مراكش',
                                        'Agadir'     => 'أكادير',
                                        'Tanger'     => 'طنجة',
                                        'Maroc'      => 'المغرب',
                                );
                                /* 6.17.30 : référentiel partagé villes+quartiers en priorité,
                                 * la carte locale ne sert que si Listing_I18n est absent. */
                                if ( class_exists( 'Partikulier_Listing_I18n' ) ) {
                                        $location = Partikulier_Listing_I18n::localized_place( (string) $location, 'ar' );
                                } else {
                                        $location = $ar_places[ $location ] ?? $location;
                                }
                        }
                        $type     = $type ? $type : ( 'ar' === $locale ? 'عقار' : 'Property' );
                        $location = $location ? $location : ( 'ar' === $locale ? 'المغرب' : 'Morocco' );

                        if ( 'ar' === $locale ) {
                                return self::limit( sprintf( 'عقار مقترح مباشرة من مالكه، بدون عمولة وكالة. %s في %s', $type, $location ), 155 );
                        }
                        return self::limit( sprintf( 'Property offered directly by its owner, without agency commission. %s in %s', $type, $location ), 155 );
                }

                private static function localized_property_type( $type, $locale ) {
                        $map = array(
                                'Appartement'          => array( 'en' => 'Apartment', 'ar' => 'شقة' ),
                                'ترجمة Appartement'   => array( 'en' => 'Apartment', 'ar' => 'شقة' ),
                                'Villa'       => array( 'en' => 'Villa', 'ar' => 'فيلا' ),
                                'Maison'      => array( 'en' => 'House', 'ar' => 'منزل' ),
                                'Terrain'     => array( 'en' => 'Land', 'ar' => 'أرض' ),
                                'Studio'      => array( 'en' => 'Studio', 'ar' => 'استوديو' ),
                        );
                        return isset( $map[ $type ][ $locale ] ) ? $map[ $type ][ $locale ] : $type;
                }

                /**
                 * Directive robots : index,follow sauf pages inutiles.
                 */
        public static function robots_directive() {
                if ( is_search() || is_404() || is_attachment() || is_page( array( 'deposer', 'deposer-en', 'deposer-ar', 'deposer-une-annonce', 'deposer-annonce', 'mes-annonces', 'mes-annonces-en', 'mes-annonces-ar', 'connexion', 'connexion-en', 'connexion-ar' ) ) ) {
                        return 'noindex,follow';
                }
                if ( is_paged() ) {
                        return 'noindex,follow';
                }
                if ( is_singular( PARTIKULIER_ESTATIK_POST_TYPE ) && 'publish' !== get_post_status( get_queried_object_id() ) ) {
                        return 'noindex,noarchive';
                }
                return '';
        }

        /**
         * URL canonique propre (sans parametres de tracking, sans pagination).
         */
        public static function canonical_url() {
                $request = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
                $path    = wp_parse_url( $request, PHP_URL_PATH );
                return set_url_scheme( home_url( $path ?: '/' ) );
        }

        /**
         * Balises OG dediees aux annonces (article + image).
         */
        private static function og_property( $post ) {
                printf( '<meta property="og:type" content="article">%s', "\n" );
                printf( '<meta property="og:title" content="%s">%s', esc_attr( get_the_title( $post ) ), "\n" );
                $pk_og_marque = false;
                if ( has_post_thumbnail( $post ) ) {
                        $img = wp_get_attachment_image_src( get_post_thumbnail_id( $post ), 'large' );
                        if ( $img ) {
                                printf( '<meta property="og:image" content="%s">%s', esc_url( $img[0] ), "\n" );
                                printf( '<meta property="og:image:width" content="%d">%s', intval( $img[1] ), "\n" );
                                printf( '<meta property="og:image:height" content="%d">%s', intval( $img[2] ), "\n" );
                                printf( '<meta property="og:image:alt" content="%s">%s', esc_attr( get_the_title( $post ) ), "\n" );
                                $pk_og_marque = true;
                        }
                }
                if ( ! $pk_og_marque ) {
                        /* Une fiche importee sans image a la une partage un lien sans apercu
                 * (mesure : 354 pages sans og:image). Le hero du site sert de secours,
                 * comme pour l'accueil : mieux vaut une image generique qu'un fil vide. */
                        $pk_og_img = self::og_image_fallback();
                        if ( $pk_og_img[0] ) {
                                printf( '<meta property="og:image" content="%s">%s', esc_url( $pk_og_img[0] ), "\n" );
                                printf( '<meta property="og:image:alt" content="%s">%s', esc_attr( $pk_og_img[1] ), "\n" );
                        }
                }
                printf( '<meta name="article:published_time" content="%s">%s', esc_attr( mysql2date( 'c', $post->post_date_gmt ) ), "\n" );
                printf( '<meta name="article:modified_time" content="%s">%s', esc_attr( mysql2date( 'c', $post->post_modified_gmt ) ), "\n" );
                printf( '<meta name="twitter:card" content="summary_large_image">%s', "\n" );
        }

        /**
         * Image de secours pour l'og:image des pages sans image propre.
         *
         * @return array{0:string,1:string} URL absolue + texte alternatif, ou array( '', '' ).
         */
        private static function og_image_fallback() {
                if ( ! class_exists( 'Partikulier_Customization' ) ) {
                        return array( '', '' );
                }
                $url = Partikulier_Customization::hero_url();
                if ( ! is_string( $url ) || '' === $url ) {
                        return array( '', '' );
                }
                $alt = Partikulier_Customization::hero_alt( '' );
                return array( $url, ( is_string( $alt ) ? $alt : '' ) );
        }

        /**
         * Lit le premier terme Estatik actif sans dépendre des anciens identifiants v3.
         */
        private static function term_name( $post, $taxonomy ) {
                $terms = wp_get_object_terms( $post->ID, $taxonomy, array( 'number' => 1, 'fields' => 'names' ) );
                return ( ! is_wp_error( $terms ) && ! empty( $terms ) ) ? $terms[0] : '';
        }

        /**
         * Limite une chaine a N caracteres sur un mot entier.
         */
        private static function limit( $text, $max ) {
                $text = wp_strip_all_tags( (string) $text );
                $text = preg_replace( '/\s+/', ' ', $text );
                if ( mb_strlen( $text ) <= $max ) {
                        return $text;
                }
                return trim( mb_substr( $text, 0, mb_strrpos( mb_substr( $text, 0, $max ), ' ' ) ) ) . '…';
        }
}

Partikulier_SEO::init();