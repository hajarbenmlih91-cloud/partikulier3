<?php
/**
 * Registre de préparation des variantes localisées Partikulier.
 *
 * Ce module relie « une annonce métier × variantes SEO » à Polylang lorsqu’il
 * est actif. Le texte libre du propriétaire reste dans sa langue d’origine et
 * n’est jamais traité comme une traduction éditoriale officielle.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

class Partikulier_Localization {

        const DB_VERSION = '1.0.0';
        const OPTION_DB_VERSION = 'pk_localization_db_version';
        const OPTION_PUBLIC_ENABLED = 'pk_localization_public_enabled';
        const STATUS_PREPARED = 'prepared';
        const META_FREE_TEXT_LANGUAGE = '_pk_free_text_language';

                public static function init() {
                        add_action( 'init', array( __CLASS__, 'load_textdomain' ), 5 );
                        add_action( 'wp', array( __CLASS__, 'load_active_textdomain' ), 1 );
                        /* 6.17.31 : le popup d'authentification imprimé au wp_footer
                         * par Estatik restait anglais dans toutes les langues — le
                         * plugin charge son domaine à plugins_loaded avec la locale
                         * du SITE (en_US), ses catalogues es-fr_FR.mo (1420 chaînes)
                         * n'étaient jamais servis. Même mécanisme de rechargement
                         * que load_active_textdomain(), pour le domaine « es ». */
                        add_action( 'wp', array( __CLASS__, 'load_estatik_textdomain' ), 2 );
                        /* Appel anticipé (chargement du thème, avant l'init
                         * d'Estatik) : si Polylang connaît déjà la langue, les
                         * réglages se figent directement dans la bonne locale. */
                        self::load_estatik_textdomain();
                        add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_browser_language' ), 1 );
                        add_action( 'init', array( __CLASS__, 'maybe_install' ), 6 );
                        add_action( 'admin_init', array( __CLASS__, 'register_polylang_strings' ) );
                        add_filter( 'gettext', array( __CLASS__, 'translate_polylang_string' ), 10, 3 );
                        add_filter( 'pll_get_post_types', array( __CLASS__, 'register_polylang_post_type' ), 10, 2 );
                        add_filter( 'pll_get_taxonomies', array( __CLASS__, 'register_polylang_taxonomies' ), 10, 2 );
                        add_filter( 'pll_preferred_language', array( __CLASS__, 'filter_robot_preferred_language' ), 10, 2 );
                        // Priorité 20 pour passer APRES les filtres par défaut d'Estatik.
                        add_action( 'pre_get_posts', array( __CLASS__, 'optimize_property_queries' ), 20 );
                        // Neutraliser le réglage Estatik properties_per_page pour forcer 24.
                        add_filter( 'es_settings', array( __CLASS__, 'force_estatik_pagination' ), 999 );
                }

        /**
         * Optimise les requêtes de propriétés pour éviter les N+1 (terms/meta cache)
         * et ajuste la pagination pour une meilleure performance.
         */
                /**
                 * Force le réglage Estatik à 24 pour la cohérence globale.
                 */
                public static function force_estatik_pagination( $settings ) {
                        if ( is_array( $settings ) ) {
                                $settings['properties_per_page'] = 24;
                        }
                        return $settings;
                }

                public static function optimize_property_queries( $query ) {
                        if ( is_admin() || ! $query->is_main_query() ) {
                                return;
                        }

                                        if ( $query->get( 'post_type' ) === PARTIKULIER_ESTATIK_POST_TYPE || $query->is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) || $query->is_tax( array( PARTIKULIER_ESTATIK_TYPE_TAXONOMY, PARTIKULIER_ESTATIK_STATUS_TAXONOMY, PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) ) ) {
                                                // Forcer le cache des termes et meta pour éviter les N+1 dans les cartes.
                                                $query->set( 'cache_results', true );
                                                $query->set( 'update_post_term_cache', true );
                                                $query->set( 'update_post_meta_cache', true );
                                        
                                        // Optimisation senior : forcer 24 par page sur l'archive pour respecter la grille responsive et limiter les requêtes N+1.
                                        if ( ! is_admin() && ( $query->is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) || $query->is_tax() ) ) {
                                                $query->set( 'posts_per_page', (int) apply_filters( 'pk_properties_per_page', 24 ) );
                                        }
                                }
        }

                        /**
                 * Charge les fichiers gettext du thème avant tout dictionnaire de repli.
                 */
                public static function load_textdomain() {
                                load_theme_textdomain( 'partikulier', PARTIKULIER_DIR . '/languages' );
                        }

                        /**
                         * Polylang peut definir son slug actif apres le chargement initial de WP.
                         * On recharge alors le fichier theme correspondant au slug public.
                         */
                        public static function load_active_textdomain() {
                                $slug = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : '';
                                $locale = 'en' === $slug ? 'en_US' : ( 'ar' === $slug ? 'ar' : '' );
                                if ( ! $locale ) {
                                        return;
                                }
                                $file = trailingslashit( PARTIKULIER_DIR ) . 'languages/' . $locale . '.mo';
                                if ( is_readable( $file ) ) {
                                        load_textdomain( 'partikulier', $file );
                                }
                        }

                /**
                 * Recharge le domaine « es » (Estatik) avec la locale de la page.
                 *
                 * 6.17.31 : Estatik charge son textdomain à plugins_loaded avec la
                 * locale du SITE (en_US sur ce déploiement) — le popup
                 * d'authentification imprimé au wp_footer restait donc anglais
                 * dans les vues FR/AR alors que le plugin embarque un catalogue
                 * français complet. Le thème embarque en plus un petit catalogue
                 * arabe (languages/estatik/es-ar.mo) couvrant le chrome visible
                 * de ce popup. Sources consultées dans l'ordre : catalogue du
                 * plugin, catalogue embarqué par le thème, emplacement
                 * communautaire standard WP_LANG_DIR.
                 */
                public static function load_estatik_textdomain() {
                        if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
                                return;
                        }
                        $slug = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : '';
                        if ( '' === $slug ) {
                                return;
                        }
                        $locale = 'en' === $slug ? 'en_US' : ( 'fr' === $slug ? 'fr_FR' : ( 'ar' === $slug ? 'ar' : '' ) );
                        if ( ! $locale || ! function_exists( 'unload_textdomain' ) ) {
                                return;
                        }

                        $candidates = array();
                        if ( defined( 'WP_PLUGIN_DIR' ) ) {
                                $candidates[] = trailingslashit( WP_PLUGIN_DIR ) . 'estatik/languages/es-' . $locale . '.mo';
                        }
                        $candidates[] = trailingslashit( PARTIKULIER_DIR ) . 'languages/estatik/es-' . $locale . '.mo';
                        if ( defined( 'WP_LANG_DIR' ) ) {
                                $candidates[] = trailingslashit( WP_LANG_DIR ) . 'plugins/es-' . $locale . '.mo';
                        }

                        foreach ( $candidates as $file ) {
                                if ( is_readable( $file ) ) {
                                        unload_textdomain( 'es' );
                                        load_textdomain( 'es', $file );
                                        /* Le conteneur de réglages d'Estatik met en cache
                                         * statique les default_value résolus par __() à la
                                         * première lecture — on le force à se re-résoudre
                                         * avec le catalogue fraîchement chargé (les titres
                                         * du popup viennent de ces réglages). */
                                        if ( class_exists( 'Es_Settings_Container' ) && method_exists( 'Es_Settings_Container', 'get_available_settings' ) ) {
                                                Es_Settings_Container::get_available_settings( true );
                                        }
                                        return;
                                }
                        }
                }

                /**
                 * Redirige uniquement la première visite humaine de la racine selon
                 * Accept-Language lorsque Polylang browser detection est activée.
                 */
                public static function maybe_redirect_browser_language() {
                        if ( ! function_exists( 'pll_home_url' ) || ! self::is_root_request() || is_user_logged_in() ) {
                                return;
                        }
                        $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
                        if ( '/' !== trailingslashit( (string) $path ) || self::is_robot_request() ) {
                                return;
                        }
                        $browser_enabled = function_exists( 'pll_get_option' ) ? pll_get_option( 'browser' ) : false;
                        if ( ! $browser_enabled || ! empty( $_COOKIE['pll_language'] ) ) {
                                return;
                        }
                        $accept = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? strtolower( (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) : '';
                        $lang = false;
                        if ( preg_match( '/(?:^|,)\\s*ar(?:[-_][a-z]+)?(?:\\s*;|,|$)/i', $accept ) ) {
                                $lang = 'ar';
                        } elseif ( preg_match( '/(?:^|,)\\s*en(?:[-_][a-z]+)?(?:\\s*;|,|$)/i', $accept ) ) {
                                $lang = 'en';
                        }
                        if ( ! $lang ) {
                                return;
                        }
                        $languages = function_exists( 'pll_languages_list' ) ? pll_languages_list() : array();
                        if ( ! in_array( $lang, array_map( 'sanitize_key', (array) $languages ), true ) ) {
                                return;
                        }
                        wp_safe_redirect( pll_home_url( $lang ), 302 );
                        exit;
                }

                private static function is_root_request() {
                        $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH ) : '';
                        return '/' === trailingslashit( (string) $path );
                }

                /**
                 * Polylang exécute sa redirection browser avant template_redirect.
                 * Les robots doivent donc être neutralisés sur son filtre officiel, sinon
                 * l’exemption locale arrive trop tard et produit un cloaking par langue.
                 *
                 * @param string|false $language Langue préférée détectée.
                 * @param bool         $cookie   Préférence issue d’un cookie.
                 * @return string|false
                 */
                public static function filter_robot_preferred_language( $language, $cookie ) {
                        unset( $cookie );
                        if ( ! self::is_robot_request() ) {
                                return $language;
                        }
                        $default = function_exists( 'pll_default_language' ) ? pll_default_language( 'slug' ) : '';
                        return $default ? sanitize_key( $default ) : $language;
                }

                private static function is_robot_request() {
                        $ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? strtolower( (string) $_SERVER['HTTP_USER_AGENT'] ) : '';
                        return '' !== $ua && (bool) preg_match( '/bot|crawler|spider|slurp|bingpreview|facebookexternalhit|linkedinbot|whatsapp/i', $ua );
                }

                /**
                 * Registre minimal du chrome commun. Les templates continuent d’utiliser

         * gettext : Polylang gratuit fournit les traductions sans nouvelle UI.
         *
         * @return array<string,string>
         */
        public static function public_chrome_strings() {
                $strings = array(
                        'property'                 => 'Bien',
                        'features'                 => 'Caractéristiques',
                        'description'              => 'Description',
                        'contact_seller'           => 'Contacter le vendeur',
                        'free_private_listing'     => 'Annonce gratuite publiée par un particulier. Aucun frais d’agence.',
                        'owner'                    => 'Propriétaire',
                        'agent'                    => 'Propriétaire',
                        'whatsapp_request'         => 'Demander le contact sur WhatsApp',
                        'whatsapp_flow'            => 'Envoyez-nous cette annonce sur WhatsApp. Après contrôle de votre demande, nous vous transmettons les coordonnées du propriétaire.',
                        'city_listings'            => 'Voir les autres annonces dans cette ville',
                        'photos'                   => 'Photos du bien',
                        'photo_navigation'         => 'Navigation des photos',
                        'previous_photo'           => 'Photo précédente',
                        'next_photo'               => 'Photo suivante',
                        'surface'                  => 'Surface',
                        'bedrooms'                 => 'Chambres',
                        'living_rooms'             => 'Salons',
                        'bathrooms'                => 'Salles de bains',
                        'terrace'                  => 'Terrasse',
                        'view'                     => 'Vue',
                        'sunshine'                 => 'Ensoleillement',
                        'parking'                  => 'Parkings',
                                'floor'                    => 'Étage',
                                'first_floor'              => '1er étage',
                                'nth_floor'                => '%d%s étage',
                                'year_built'               => 'Année de construction',
                        'energy_class'             => 'Classe énergie',
                        'yes'                      => 'Oui',
                        'no'                       => 'Non',
                        'studio'                   => 'Studio',
                        'main_room'                => 'Pièce principale',
                        'three_bedrooms_plus'     => '3 chambres ou plus',
                        'three_living_rooms_plus' => '3 salons ou plus',
                        'three_bathrooms_plus'    => '3 salles de bains ou plus',
                        'contact_unavailable'      => 'Le contact WhatsApp sera bientôt disponible pour cette annonce.',
                        'closed_no_contact'        => 'Cette annonce ne reçoit plus de contacts.',
                        'features'                 => 'Caractéristiques',
                        'contact_via_whatsapp'     => 'Demander le contact sur WhatsApp',
                        'direct_contact'           => 'Contact direct',
                        'skip_to_content'          => 'Aller au contenu',
                        'post_listing'             => 'Déposer une annonce',
                        'all_listings'             => 'Toutes les annonces',
                        'my_space'                 => 'Mon espace',
                        'sign_in'                  => 'Se connecter',
                        'quick_search'             => 'Recherche rapide',
                        'property_type'            => 'Type de bien',
                        'city_postcode_area'       => 'Ville, code postal, quartier…',
                        'search_city'              => 'Rechercher une ville',
                        'search'                   => 'Rechercher',
                        'favorites'                => 'Favoris',
                        'open_menu'                => 'Ouvrir le menu',
                        'main_menu'                => 'Menu principal',
                        'about'                    => 'À propos',
                        'help'                     => 'Aide',
                        'faq'                      => 'Questions fréquentes',
                        'contact_us'               => 'Contactez-nous',
                        'property_types'           => 'Types de biens',
                        'contact'                  => 'Contact',
                        'country'                  => 'Maroc',
                        'all_rights_reserved'      => 'Tous droits réservés.',
                        'legal_notices'            => 'Mentions légales',
                        'bedroom'                  => 'chambre',
                        'bedrooms_plural'          => 'chambres',
                        'living_room'              => 'salon',
                        'living_rooms_plural'      => 'salons',
                        'bathroom'                 => 'salle de bains',
                        'bathrooms_plural'         => 'salles de bains',
                        'display'                  => 'Affichage',
                        'results'                  => 'résultats',
                        'latest'                   => 'Plus récentes',
                        'price_asc'                => 'Prix croissant',
                        'price_desc'               => 'Prix décroissant',
                        'surface_desc'             => 'Surface décroissante',
                        'filters'                  => 'Filtres',
                        'budget_max'               => 'Budget maximum',
                        'apply'                    => 'APPLIQUER',
                        'popular_cities'           => 'Villes populaires',
                        'back_to_home'             => 'Retour à l’accueil',
                        'property_merits'          => 'Votre bien mérite',
                        'real_estate_agent'        => 'Agent immobilier',
                                'i_wish'                   => 'Je souhaite',
                                'no_registration_required' => 'Aucune inscription obligatoire',
                                'annonces'                 => 'Annonces',
                                'listing_catalogue'        => 'Le catalogue direct',
                                'showing_x_y_of_z'         => 'Affichage %1$s–%2$s de %3$s résultats',
                                'search_results_for'       => 'Résultats pour « %s »',
                                'listings_in'              => 'Annonces immobilières à %s',
                                'type_for_sale_rent'       => '%s à vendre et à louer',
                                'grid_view'                => 'Vue grille',
                                'list_view'                => 'Vue liste',
                                'sort_by'                  => 'Trier les annonces',
                                'search_hint'              => 'Commencez par une ville, un quartier ou un code postal.',
                                'filter_budget_max'        => 'Budget maximum en euros',
                                'listings_appear_here'     => 'Les premières annonces apparaîtront ici.',
                                'post_free_listing'        => 'Déposez un bien gratuitement pour ouvrir cette sélection.',
                                'no_listings_yet'          => 'Aucune annonce publiée pour le moment.',
                                'post_for_free_arrow'      => 'Publier gratuitement',
                                'find_your_property'       => 'Trouvez votre bien',
                                'search_starts_here'       => 'Une recherche qui commence par le bon lieu.',
                                'browse_categories'        => 'Parcourez les catégories sans bruit, puis laissez les détails vous guider.',
                                'explorer'                 => 'Explorer',
                                'rent_directly'            => 'À louer directement',
                                'apt_with_view'            => 'Un appartement avec vue sur le large.',
                                'discover_properties'      => 'Découvrez les biens qui privilégient la lumière, l’espace et le contact direct.',
                                'search_property'          => 'Rechercher un bien',
                                'near_you'                 => 'Proche de chez vous',
                                'find_in_your_city'        => 'Trouvez un bien dans votre ville.',
                                'indexed_places'           => 'Les quartiers et villes sont indexés pour vous aider à trouver plus vite.',
                                'explore_listings'         => 'Explorer les annonces',
                                'cities_appear_here'       => 'Les villes apparaîtront dès les premières annonces.',
                                'by_region'                => 'Par région',
                                'everywhere_in_morocco'    => 'Partout au Maroc.',
                                'most_recent'              => 'Plus récentes',
                                'price_asc'                => 'Prix croissant',
                                'price_desc'               => 'Prix décroissant',
                                'surface_desc'             => 'Surface décroissante',
                                'owner_space'              => 'Espace propriétaire',
                                'connexion'                => 'Connexion',
                                'your_listings'            => 'Vos annonces',
                                'awaiting_you'             => 'vous attendent.',
                                'sign_in_manage_listings'  => 'Connectez-vous pour les gérer, suivre leurs vues et vos contacts directs.',
                                'auth_module_unavailable'  => 'Le module de connexion n’est pas disponible pour le moment.',
                                'partikulier_guarantees'   => 'Garanties Partikulier',
                                'browsing_without_account' => 'La consultation des annonces et le dépôt restent possibles sans compte.',
                                'browse_listings'          => 'Parcourir les annonces',
                        );

                if ( class_exists( 'Partikulier_Settings' ) ) {
                        foreach ( Partikulier_Settings::fields() as $group ) {
                                foreach ( $group['fields'] as $key => $field ) {
                                        if ( empty( $field['type'] ) || 'password' !== $field['type'] ) {
                                                $strings[ 'setting_' . $key ] = $field['default'];
                                        }
                                }
                        }
                }

                return $strings;
        }

        /**
         * Traduit une chaîne publique explicitement enregistrée, sinon conserve la
         * valeur d’origine. Cela protège les textes libres et les réglages personnalisés.
         */
                        public static function translate_taxonomy_label( $label ) {
                        $map = array(
                                        'A louer' => array( 'fr' => 'A louer', 'en' => 'For rent', 'ar' => 'للإيجار' ),
                                        'A vendre' => array( 'fr' => 'A vendre', 'en' => 'For sale', 'ar' => 'للبيع' ),
                                        'Appartement' => array( 'fr' => 'Appartement', 'en' => 'Apartment', 'ar' => 'شقة' ),
                                        'Appartements' => array( 'fr' => 'Appartements', 'en' => 'Apartments', 'ar' => 'شقق' ),
                                        'Maison' => array( 'fr' => 'Maison', 'en' => 'House', 'ar' => 'منزل' ),
                                        'Maisons' => array( 'fr' => 'Maisons', 'en' => 'Houses', 'ar' => 'منازل' ),
                                        'Villa' => array( 'fr' => 'Villa', 'en' => 'Villa', 'ar' => 'فيلا' ),
                                        'Terrain' => array( 'fr' => 'Terrain', 'en' => 'Land', 'ar' => 'أرض' ),
                                        'Parking' => array( 'fr' => 'Parking', 'en' => 'Parking', 'ar' => 'موقف سيارات' ),
                                        'Immeuble' => array( 'fr' => 'Immeuble', 'en' => 'Building', 'ar' => 'عمارة' ),
                                        'Local' => array( 'fr' => 'Local', 'en' => 'Commercial space', 'ar' => 'محل تجاري' ),
                                        'Bureau' => array( 'fr' => 'Bureau', 'en' => 'Office', 'ar' => 'مكتب' ),
                                        'Rabat' => array( 'fr' => 'Rabat', 'en' => 'Rabat', 'ar' => 'الرباط' ),
                                        'Casablanca' => array( 'fr' => 'Casablanca', 'en' => 'Casablanca', 'ar' => 'الدار البيضاء' ),
                                        'Marrakech' => array( 'fr' => 'Marrakech', 'en' => 'Marrakech', 'ar' => 'مراكش' ),
                                        'Tanger' => array( 'fr' => 'Tanger', 'en' => 'Tangier', 'ar' => 'طنجة' ),
                                        'Fès' => array( 'fr' => 'Fès', 'en' => 'Fez', 'ar' => 'فاس' ),
                                        'Agadir' => array( 'fr' => 'Agadir', 'en' => 'Agadir', 'ar' => 'أكادير' ),
                        );
                        $language = self::current_language();
                        if ( isset( $map[ $label ][ $language ] ) ) {
                                return $map[ $label ][ $language ];
                        }
                        if ( 'fr' !== $language && class_exists( 'Partikulier_Listing_I18n' ) ) {
                                $type_label = Partikulier_Listing_I18n::localized_type( $label, $language );
                                if ( $type_label !== $label ) {
                                        return $type_label;
                                }
                                $place_label = Partikulier_Listing_I18n::localized_place( $label, $language );
                                if ( $place_label !== $label ) {
                                        return $place_label;
                                }
                        }
                        return $label;
                }

                public static function translate_public_string( $string ) {

                if ( ! function_exists( 'pll__' ) || ! in_array( $string, self::public_chrome_strings(), true ) ) {
                        return $string;
                }

                return pll__( $string );
        }

        /**
         * Inscrit les chaînes dans l’outil natif de Polylang gratuit, côté
         * administration uniquement, conformément à son API publique.
         */
        public static function register_polylang_strings() {
                if ( ! function_exists( 'pll_register_string' ) ) {
                        return;
                }

                foreach ( self::public_chrome_strings() as $name => $string ) {
                        pll_register_string( 'Partikulier · ' . $name, $string, 'Partikulier' );
                }
        }

        /**
         * Traduit uniquement les chaînes explicitement enregistrées. Toute chaîne
         * non préparée et tout contenu propriétaire conserve son texte d’origine.
         */
        public static function translate_polylang_string( $translation, $text, $domain ) {
                if ( 'partikulier' !== $domain ) {
                        return $translation;
                }

                                // Une traduction gettext provenant d’un fichier .mo est canonique.
                                // Les dictionnaires internes ne servent qu’en repli.
                                if ( $translation !== $text && '' !== $translation ) {
                                        return $translation;
                                }

                                $form_translations = self::form_translations();
                                if ( isset( $form_translations[ $text ] ) ) {
                                $language = self::current_language();
                                return isset( $form_translations[ $text ][ $language ] ) ? $form_translations[ $text ][ $language ] : $text;
                        }

                        $chrome_translations = self::chrome_translations();
                        if ( isset( $chrome_translations[ $text ] ) ) {
                                $language = self::current_language();
                                return isset( $chrome_translations[ $text ][ $language ] ) ? $chrome_translations[ $text ][ $language ] : $text;
                        }

                        if ( ! function_exists( 'pll__' ) || ! in_array( $text, self::public_chrome_strings(), true ) ) {
                        return $translation;
                }

                return self::translate_public_string( $text );
        }

                /**
                 * Repli déterministe des chaînes du shell public lorsque Polylang n’a pas
                 * encore reçu leurs traductions dans l’administration.
                 *
                 * @return array<string,array<string,string>>
                 */
                public static function chrome_translations() {
                        return array(
                                'Aller au contenu' => array( 'fr' => 'Aller au contenu', 'en' => 'Skip to content', 'ar' => 'انتقل إلى المحتوى' ),
                                'Déposer une annonce' => array( 'fr' => 'Déposer une annonce', 'en' => 'Post an ad', 'ar' => 'أضف إعلانك' ),
                                'Toutes les annonces' => array( 'fr' => 'Toutes les annonces', 'en' => 'All listings', 'ar' => 'كل الإعلانات' ),
                                'Mon espace' => array( 'fr' => 'Mon espace', 'en' => 'My account', 'ar' => 'مساحتي' ),
                                'Se connecter' => array( 'fr' => 'Se connecter', 'en' => 'Sign in', 'ar' => 'تسجيل الدخول' ),
                                'Recherche rapide' => array( 'fr' => 'Recherche rapide', 'en' => 'Quick search', 'ar' => 'بحث سريع' ),
                                'Type de bien' => array( 'fr' => 'Type de bien', 'en' => 'Property type', 'ar' => 'نوع العقار' ),
                                'Ville, code postal, quartier…' => array( 'fr' => 'Ville, code postal, quartier…', 'en' => 'City, postcode, neighbourhood…', 'ar' => 'المدينة، الرمز البريدي، الحي…' ),
                                'Rechercher une ville' => array( 'fr' => 'Rechercher une ville', 'en' => 'Search for a city', 'ar' => 'ابحث عن مدينة' ),
                                'Rechercher' => array( 'fr' => 'Rechercher', 'en' => 'Search', 'ar' => 'بحث' ),
                                'Favoris' => array( 'fr' => 'Favoris', 'en' => 'Favorites', 'ar' => 'المفضلة' ),
                                'Ouvrir le menu' => array( 'fr' => 'Ouvrir le menu', 'en' => 'Open menu', 'ar' => 'فتح القائمة' ),
                                'Menu principal' => array( 'fr' => 'Menu principal', 'en' => 'Main menu', 'ar' => 'القائمة الرئيسية' ),
                                'Choisir la langue' => array( 'fr' => 'Choisir la langue', 'en' => 'Choose language', 'ar' => 'اختر اللغة' ),
                                        'À propos' => array( 'fr' => 'À propos', 'en' => 'About', 'ar' => 'عن الموقع' ),
                                        'Étapes de publication' => array( 'fr' => 'Étapes de publication', 'en' => 'Listing steps', 'ar' => 'خطوات نشر الإعلان' ),
                                        'Étape 1' => array( 'fr' => 'Étape 1', 'en' => 'Step 1', 'ar' => 'الخطوة 1' ),
                                        'Étape 2' => array( 'fr' => 'Étape 2', 'en' => 'Step 2', 'ar' => 'الخطوة 2' ),
                                        'Étape 3' => array( 'fr' => 'Étape 3', 'en' => 'Step 3', 'ar' => 'الخطوة 3' ),
                                        'Qui publie l’annonce ?' => array( 'fr' => 'Qui publie l’annonce ?', 'en' => 'Who is posting?', 'ar' => 'من ينشر الإعلان؟' ),
                                        'Les informations du bien' => array( 'fr' => 'Les informations du bien', 'en' => 'Property details', 'ar' => 'معلومات العقار' ),
                                        'Votre aperçu' => array( 'fr' => 'Votre aperçu', 'en' => 'Your preview', 'ar' => 'المعاينة' ),
                                'Aide' => array( 'fr' => 'Aide', 'en' => 'Help', 'ar' => 'مساعدة' ),
                                'Questions fréquentes' => array( 'fr' => 'Questions fréquentes', 'en' => 'Frequently asked questions', 'ar' => 'الأسئلة الشائعة' ),
                                'Contactez-nous' => array( 'fr' => 'Contactez-nous', 'en' => 'Contact us', 'ar' => 'اتصل بنا' ),
                                'Types de biens' => array( 'fr' => 'Types de biens', 'en' => 'Property types', 'ar' => 'أنواع العقارات' ),
                                'Contact' => array( 'fr' => 'Contact', 'en' => 'Contact', 'ar' => 'اتصل بنا' ),
                                'Maroc' => array( 'fr' => 'Maroc', 'en' => 'Morocco', 'ar' => 'المغرب' ),
                                'Tous droits réservés.' => array( 'fr' => 'Tous droits réservés.', 'en' => 'All rights reserved.', 'ar' => 'جميع الحقوق محفوظة.' ),
                                                                        'Mentions légales' => array( 'fr' => 'Mentions légales', 'en' => 'Legal notices', 'ar' => 'الإشعارات القانونية' ),
                                        'Accueil' => array( 'fr' => 'Accueil', 'en' => 'Home', 'ar' => 'الرئيسية' ),
                                        'Annonces' => array( 'fr' => 'Annonces', 'en' => 'Listings', 'ar' => 'الإعلانات' ),
                                        'Toutes les villes' => array( 'fr' => 'Toutes les villes', 'en' => 'All cities', 'ar' => 'كل المدن' ),
                                        'Vendeur identifié' => array( 'fr' => 'Vendeur identifié', 'en' => 'Verified seller', 'ar' => 'بائع موثوق' ),
                                        'Zéro commission' => array( 'fr' => 'Zéro commission', 'en' => 'No commission', 'ar' => 'بدون عمولة' ),
                                        'Contact direct' => array( 'fr' => 'Contact direct', 'en' => 'Direct contact', 'ar' => 'اتصال مباشر' ),
                                        'Commencez par une ville, un quartier ou un code postal.' => array( 'fr' => 'Commencez par une ville, un quartier ou un code postal.', 'en' => 'Start with a city, neighbourhood or postcode.', 'ar' => 'ابدأ بمدينة أو حي أو رمز بريدي.' ),
                                        'Vendez et louez entre particuliers.' => array( 'fr' => 'Vendez et louez entre particuliers.', 'en' => 'Buy and rent directly from private owners.', 'ar' => 'اشترِ واكترِ مباشرة من المالكين.' ),
                                        'Déposez votre annonce immobilière gratuitement, sans commission, sans intermédiaire. Directement aux acheteurs et locataires.' => array( 'fr' => 'Déposez votre annonce immobilière gratuitement, sans commission, sans intermédiaire. Directement aux acheteurs et locataires.', 'en' => 'Post your property for free, with no commission or middleman. Reach buyers and tenants directly.', 'ar' => 'أضف عقارك مجاناً، بدون عمولة أو وسيط. تواصل مباشرة مع المشترين والمستأجرين.' ),
                                        'Publier gratuitement' => array( 'fr' => 'Publier gratuitement', 'en' => 'Post for free', 'ar' => 'أضف إعلاناً مجاناً' ),
                                        'Chercher par ville' => array( 'fr' => 'Chercher par ville', 'en' => 'Search by city', 'ar' => 'ابحث حسب المدينة' ),
                                        'Achat ou location' => array( 'fr' => 'Achat ou location', 'en' => 'Buy or rent', 'ar' => 'شراء أو إيجار' ),
                                        'Tout' => array( 'fr' => 'Tout', 'en' => 'All', 'ar' => 'الكل' ),
                                        /* 6.17.30 — S9.4/S9.11 : libellés manquants (les options du
                                         * hero/filtres « Vendre / Louer / Tous » restaient françaises
                                         * en AR ; le dt screen-reader « Type » aussi). */
                                        'Tous' => array( 'fr' => 'Tous', 'en' => 'All', 'ar' => 'الكل' ),
                                        'Vendre' => array( 'fr' => 'Vendre', 'en' => 'Sell', 'ar' => 'بيع' ),
                                        'Louer' => array( 'fr' => 'Louer', 'en' => 'Rent', 'ar' => 'كراء' ),
                                        'Type' => array( 'fr' => 'Type', 'en' => 'Type', 'ar' => 'النوع' ),
                                        'A louer' => array( 'fr' => 'A louer', 'en' => 'For rent', 'ar' => 'للإيجار' ),
                                        'Budget max' => array( 'fr' => 'Budget max', 'en' => 'Maximum budget', 'ar' => 'الميزانية القصوى' ),
                                        'Illimité' => array( 'fr' => 'Illimité', 'en' => 'No limit', 'ar' => 'بدون حد' ),
                                        'Annonce à la une' => array( 'fr' => 'Annonce à la une', 'en' => 'Featured listing', 'ar' => 'إعلان مميز' ),
                                        'Des biens choisis pour leur lumière.' => array( 'fr' => 'Des biens choisis pour leur lumière.', 'en' => 'Properties selected for their light.', 'ar' => 'عقارات مختارة لإضاءتها.' ),
                                        'Un accès direct aux annonces publiées par leurs propriétaires.' => array( 'fr' => 'Un accès direct aux annonces publiées par leurs propriétaires.', 'en' => 'Direct access to listings published by their owners.', 'ar' => 'وصول مباشر إلى الإعلانات المنشورة من مالكيها.' ),
                                        'Fraîchement publiées' => array( 'fr' => 'Fraîchement publiées', 'en' => 'Recently published', 'ar' => 'أضيفت حديثاً' ),
                                        'Les dernières annonces' => array( 'fr' => 'Les dernières annonces', 'en' => 'Latest listings', 'ar' => 'أحدث الإعلانات' ),
                                        'Des biens ajoutés récemment par leurs propriétaires.' => array( 'fr' => 'Des biens ajoutés récemment par leurs propriétaires.', 'en' => 'Properties recently added by their owners.', 'ar' => 'عقارات أضافها مالكوها مؤخراً.' ),
                                        'Voir toutes les annonces' => array( 'fr' => 'Voir toutes les annonces', 'en' => 'View all listings', 'ar' => 'عرض كل الإعلانات' ),
                                        'Affichage' => array( 'fr' => 'Affichage', 'en' => 'Showing', 'ar' => 'عرض' ),
                                        'résultats' => array( 'fr' => 'résultats', 'en' => 'results', 'ar' => 'نتائج' ),
                                        'Plus récentes' => array( 'fr' => 'Plus récentes', 'en' => 'Latest', 'ar' => 'الأحدث' ),
                                        'Prix croissant' => array( 'fr' => 'Prix croissant', 'en' => 'Price: Low to High', 'ar' => 'السعر: من الأقل للأعلى' ),
                                        'Prix décroissant' => array( 'fr' => 'Prix décroissant', 'en' => 'Price: High to Low', 'ar' => 'السعر: من الأعلى للأقل' ),
                                        'Surface décroissante' => array( 'fr' => 'Surface décroissante', 'en' => 'Area: High to Low', 'ar' => 'المساحة: من الأعلى للأقل' ),
                                                'Filtres' => array( 'fr' => 'Filtres', 'en' => 'Filters', 'ar' => 'تصفية' ),
                                                'Affiner la recherche' => array( 'fr' => 'Affiner la recherche', 'en' => 'Refine search', 'ar' => 'تصفية البحث' ),
                                                'Filtres actifs' => array( 'fr' => 'filtres actifs', 'en' => 'active filters', 'ar' => 'مرشحات نشطة' ),
                                                'Fermer' => array( 'fr' => 'Fermer', 'en' => 'Close', 'ar' => 'إغلاق' ),
                                                'Réinitialiser' => array( 'fr' => 'Réinitialiser', 'en' => 'Reset', 'ar' => 'إعادة ضبط' ),
                                                'Appliquer' => array( 'fr' => 'Appliquer', 'en' => 'Apply', 'ar' => 'تطبيق' ),
                                                'Transaction' => array( 'fr' => 'Transaction', 'en' => 'Transaction', 'ar' => 'المعاملة' ),
                                                'Ville' => array( 'fr' => 'Ville', 'en' => 'City', 'ar' => 'المدينة' ),
                                        'Budget maximum' => array( 'fr' => 'Budget maximum', 'en' => 'Maximum budget', 'ar' => 'الميزانية القصوى' ),
                                        'APPLIQUER' => array( 'fr' => 'APPLIQUER', 'en' => 'APPLY', 'ar' => 'تطبيق' ),
                                        'Villes populaires' => array( 'fr' => 'Villes populaires', 'en' => 'Popular cities', 'ar' => 'مدن شعبية' ),
                                        'Retour à l’accueil' => array( 'fr' => 'Retour à l’accueil', 'en' => 'Back to home', 'ar' => 'العودة إلى الرئيسية' ),
                                        /* 6.17.32 — état vide de l'archive/recherche : le libellé
                                         * restait français sur les pages EN/AR (constaté sur la
                                         * capture « réflexion échappée » du scénario 10 : page EN
                                         * affichant « Rien à afficher pour le moment. »). */
                                        'Rien à afficher pour le moment.' => array( 'fr' => 'Rien à afficher pour le moment.', 'en' => 'Nothing to display at the moment.', 'ar' => 'لا يوجد شيء للعرض في الوقت الحالي.' ),
                                        /* 6.17.33 — page « Connexion » (authentification au design
                                         * du thème, remplace wp-login.php pour les visiteurs). */
                                        'Espace propriétaire' => array( 'fr' => 'Espace propriétaire', 'en' => 'Owner space', 'ar' => 'مساحة المالك' ),
                                        'Connexion' => array( 'fr' => 'Connexion', 'en' => 'Sign in', 'ar' => 'تسجيل الدخول' ),
                                        'Vos annonces' => array( 'fr' => 'Vos annonces', 'en' => 'Your listings', 'ar' => 'إعلاناتك' ),
                                        'vous attendent.' => array( 'fr' => 'vous attendent.', 'en' => 'are waiting.', 'ar' => 'في انتظارك.' ),
                                        'Connectez-vous pour les gérer, suivre leurs vues et vos contacts directs.' => array( 'fr' => 'Connectez-vous pour les gérer, suivre leurs vues et vos contacts directs.', 'en' => 'Sign in to manage them, follow their views and your direct contacts.', 'ar' => 'سجّل الدخول لإدارتها ومتابعة مشاهداتها وجهات الاتصال المباشرة.' ),
                                        'Le module de connexion n’est pas disponible pour le moment.' => array( 'fr' => 'Le module de connexion n’est pas disponible pour le moment.', 'en' => 'The sign-in module is currently unavailable.', 'ar' => 'وحدة تسجيل الدخول غير متاحة حالياً.' ),
                                        'Garanties Partikulier' => array( 'fr' => 'Garanties Partikulier', 'en' => 'Partikulier guarantees', 'ar' => 'ضمانات بارتيكيولييه' ),
                                        'La consultation des annonces et le dépôt restent possibles sans compte.' => array( 'fr' => 'La consultation des annonces et le dépôt restent possibles sans compte.', 'en' => 'Browsing and posting listings remains possible without an account.', 'ar' => 'يبقى تصفح الإعلانات ونشرها متاحاً دون حساب.' ),
                                        'Parcourir les annonces' => array( 'fr' => 'Parcourir les annonces', 'en' => 'Browse the listings', 'ar' => 'تصفح الإعلانات' ),
                                        /* 6.17.32 — valeur PAR DÉFAUT du réglage footer_about_text,
                                         * localisée uniquement lorsqu'elle est encore inchangée
                                         * (le texte personnalisé du propriétaire n'est jamais traduit :
                                         * translate_polylang_string laisse passer toute chaîne absente
                                         * des dictionnaires). */
                                        'Portail immobilier 100% gratuit entre particuliers. Publiez votre annonce sans commission ni intermédiaire.' => array( 'fr' => 'Portail immobilier 100% gratuit entre particuliers. Publiez votre annonce sans commission ni intermédiaire.', 'en' => 'A 100% free property portal between individuals. Post your listing with no commission or middleman.', 'ar' => 'بوابة عقارية مجانية بنسبة 100% بين الأفراد. انشر إعلانك بدون عمولة أو وسيط.' ),
                                        'Votre bien mérite' => array( 'fr' => 'Votre bien mérite', 'en' => 'Your property deserves', 'ar' => 'عقارك يستحق' ),
                                        'Agent immobilier' => array( 'fr' => 'Agent immobilier', 'en' => 'Real estate agent', 'ar' => 'وكيل عقاري' ),
                                        'Je souhaite' => array( 'fr' => 'Je souhaite', 'en' => 'I want to', 'ar' => 'أرغب في' ),
                                        'Aucune inscription obligatoire' => array( 'fr' => 'Aucune inscription obligatoire', 'en' => 'No registration required', 'ar' => 'لا يشترط التسجيل' ),
                                                'Appartement' => array( 'fr' => 'Appartement', 'en' => 'Apartment', 'ar' => 'شقة' ),
                                                'Maison' => array( 'fr' => 'Maison', 'en' => 'House', 'ar' => 'منزل' ),
                                                'Terrain' => array( 'fr' => 'Terrain', 'en' => 'Land', 'ar' => 'أرض' ),
                                                'Parking' => array( 'fr' => 'Parking', 'en' => 'Parking', 'ar' => 'موقف سيارات' ),
                                                'Immeuble' => array( 'fr' => 'Immeuble', 'en' => 'Building', 'ar' => 'عمارة' ),
                                                'Local' => array( 'fr' => 'Local', 'en' => 'Commercial space', 'ar' => 'محل تجاري' ),
                                                'Loft' => array( 'fr' => 'Loft', 'en' => 'Loft', 'ar' => 'لوفت' ),
                                                'Studio' => array( 'fr' => 'Studio', 'en' => 'Studio', 'ar' => 'ستوديو' ),
                                                'Le catalogue direct' => array( 'fr' => 'Le catalogue direct', 'en' => 'The direct catalog', 'ar' => 'الدليل المباشر' ),
                                                'Affichage %1$s–%2$s de %3$s résultats' => array( 'fr' => 'Affichage %1$s–%2$s de %3$s résultats', 'en' => 'Showing %1$s–%2$s of %3$s results', 'ar' => 'عرض %1$s–%2$s من %3$s نتائج' ),
                                                'Résultats pour « %s »' => array( 'fr' => 'Résultats pour « %s »', 'en' => 'Results for "%s"', 'ar' => 'نتائج البحث عن "%s"' ),
                                                'Annonces immobilières à %s' => array( 'fr' => 'Annonces immobilières à %s', 'en' => 'Real estate listings in %s', 'ar' => 'إعلانات عقارية في %s' ),
                                                '%s à vendre et à louer' => array( 'fr' => '%s à vendre et à louer', 'en' => '%s for sale and rent', 'ar' => '%s للبيع وللكراء' ),
                                                'Vue grille' => array( 'fr' => 'Vue grille', 'en' => 'Grid view', 'ar' => 'عرض الشبكة' ),
                                                'Vue liste' => array( 'fr' => 'Vue liste', 'en' => 'List view', 'ar' => 'عرض القائمة' ),
                                                'Trier les annonces' => array( 'fr' => 'Trier les annonces', 'en' => 'Sort listings', 'ar' => 'ترتيب الإعلانات' ),
                                                'Budget maximum en euros' => array( 'fr' => 'Budget maximum en euros', 'en' => 'Maximum budget in euros', 'ar' => 'الميزانية القصوى باليورو' ),
                                                'Les premières annonces apparaîtront ici.' => array( 'fr' => 'Les premières annonces apparaîtront ici.', 'en' => 'The first listings will appear here.', 'ar' => 'ستظهر الإعلانات الأولى هنا.' ),
                                                'Déposez un bien gratuitement pour ouvrir cette sélection.' => array( 'fr' => 'Déposez un bien gratuitement pour ouvrir cette sélection.', 'en' => 'Post a property for free to open this selection.', 'ar' => 'أضف عقاراً مجاناً لفتح هذه المجموعة.' ),
                                                'Aucune annonce publiée pour le moment.' => array( 'fr' => 'Aucune annonce publiée pour le moment.', 'en' => 'No listings published yet.', 'ar' => 'لا توجد إعلانات منشورة حالياً.' ),
                                                'Find your property' => array( 'fr' => 'Trouvez votre bien', 'en' => 'Find your property', 'ar' => 'ابحث عن عقارك' ),
                                                'Une recherche qui commence par le bon lieu.' => array( 'fr' => 'Une recherche qui commence par le bon lieu.', 'en' => 'A search that starts in the right place.', 'ar' => 'بحث يبدأ من المكان الصحيح.' ),
                                                'Parcourez les catégories sans bruit, puis laissez les détails vous guider.' => array( 'fr' => 'Parcourez les catégories sans bruit, puis laissez les détails vous guider.', 'en' => 'Browse categories without noise, then let the details guide you.', 'ar' => 'تصفح الفئات بهدوء، ثم دع التفاصيل ترشدك.' ),
                                                'Explorer' => array( 'fr' => 'Explorer', 'en' => 'Explore', 'ar' => 'استكشاف' ),
                                                'À louer directement' => array( 'fr' => 'À louer directement', 'en' => 'For rent directly', 'ar' => 'للكراء مباشرة' ),
                                                'Un appartement avec vue sur le large.' => array( 'fr' => 'Un appartement avec vue sur le large.', 'en' => 'An apartment with a view of the open sea.', 'ar' => 'شقة مع إطلالة على البحر.' ),
                                                'Découvrez les biens qui privilégient la lumière, l’espace et le contact direct.' => array( 'fr' => 'Découvrez les biens qui privilégient la lumière, l’espace et le contact direct.', 'en' => 'Discover properties that prioritize light, space and direct contact.', 'ar' => 'اكتشف العقارات التي تعطي الأولوية للضوء والمساحة والاتصال المباشر.' ),
                                                'Rechercher un bien' => array( 'fr' => 'Rechercher un bien', 'en' => 'Search for a property', 'ar' => 'ابحث عن عقار' ),
                                                'Proche de chez vous' => array( 'fr' => 'Proche de chez vous', 'en' => 'Near you', 'ar' => 'بالقرب منك' ),
                                                'Trouvez un bien dans votre ville.' => array( 'fr' => 'Trouvez un bien dans votre ville.', 'en' => 'Find a property in your city.', 'ar' => 'ابحث عن عقار في مدينتك.' ),
                                                'Les quartiers et villes sont indexés pour vous aider à trouver plus vite.' => array( 'fr' => 'Les quartiers et villes sont indexés pour vous aider à trouver plus vite.', 'en' => 'Neighbourhoods and cities are indexed to help you find faster.', 'ar' => 'الأحياء والمدن مفهرسة لمساعدتك في العثور بشكل أسرع.' ),
                                                'Explorer les annonces' => array( 'fr' => 'Explorer les annonces', 'en' => 'Explore listings', 'ar' => 'استكشاف الإعلانات' ),
                                                'Les villes apparaîtront dès les premières annonces.' => array( 'fr' => 'Les villes apparaîtront dès les premières annonces.', 'en' => 'Cities will appear with the first listings.', 'ar' => 'ستظهر المدن مع الإعلانات الأولى.' ),
                                                'Par région' => array( 'fr' => 'Par région', 'en' => 'By region', 'ar' => 'حسب الجهة' ),
                                                'Partout au Maroc.' => array( 'fr' => 'Partout au Maroc.', 'en' => 'Everywhere in Morocco.', 'ar' => 'في كل أنحاء المغرب.' ),
                                                'Réglages n8n' => array( 'fr' => 'Réglages n8n', 'en' => 'n8n Settings', 'ar' => 'إعدادات n8n' ),
                                                'Partikulier — Réglages n8n' => array( 'fr' => 'Partikulier — Réglages n8n', 'en' => 'Partikulier — n8n Settings', 'ar' => 'Partikulier — إعدادات n8n' ),
                                                'Réglages enregistrés.' => array( 'fr' => 'Réglages enregistrés.', 'en' => 'Settings saved.', 'ar' => 'تم حفظ الإعدادات.' ),
                                                'Webhook n8n' => array( 'fr' => 'Webhook n8n', 'en' => 'n8n Webhook', 'ar' => 'n8n Webhook' ),
                                                'Secret' => array( 'fr' => 'Secret', 'en' => 'Secret', 'ar' => 'السر' ),
                                                'Remplacer uniquement' => array( 'fr' => 'Remplacer uniquement', 'en' => 'Replace only', 'ar' => 'استبدال فقط' ),
                                                'Mode HMAC' => array( 'fr' => 'Mode HMAC', 'en' => 'HMAC Mode', 'ar' => 'وضع HMAC' ),
                                                'Quota / jour' => array( 'fr' => 'Quota / jour', 'en' => 'Daily quota', 'ar' => 'الحصة اليومية' ),
                                                'Consentement WhatsApp' => array( 'fr' => 'Consentement WhatsApp', 'en' => 'WhatsApp Consent', 'ar' => 'موافقة واتساب' ),
                                                'Canal WhatsApp' => array( 'fr' => 'Canal WhatsApp', 'en' => 'WhatsApp Channel', 'ar' => 'قناة واتساب' ),
                                                'Enregistrer' => array( 'fr' => 'Enregistrer', 'en' => 'Save', 'ar' => 'حفظ' ),
                                                'Plus récentes' => array( 'fr' => 'Plus récentes', 'en' => 'Most recent', 'ar' => 'الأحدث' ),
                                                'Prix croissant' => array( 'fr' => 'Prix croissant', 'en' => 'Price: Low to High', 'ar' => 'الثمن: من الأقل إلى الأعلى' ),
                                                'Prix décroissant' => array( 'fr' => 'Prix décroissant', 'en' => 'Price: High to Low', 'ar' => 'الثمن: من الأعلى إلى الأقل' ),
                                                'Surface décroissante' => array( 'fr' => 'Surface décroissante', 'en' => 'Surface: High to Low', 'ar' => 'المساحة: من الأعلى إلى الأقل' ),
                                        );
                        }

                /**
                 * Libellés du formulaire de dépôt utilisés comme repli déterministe lorsque
                 * Polylang n’a pas encore de traduction enregistrée pour une chaîne.
                 * Le texte libre saisi par le propriétaire n’est jamais traduit ici.
                 *
                 * @return array<string,array<string,string>>
                 */
                public static function form_translations() {
                return array(
                                '1er étage' => array( 'fr' => '1er étage', 'en' => '1st floor', 'ar' => 'الطابق الأول' ),
                                'e étage' => array( 'fr' => 'e étage', 'en' => 'th floor', 'ar' => 'الطابق' ),
                                '%d%s étage' => array( 'fr' => '%d%s étage', 'en' => '%d%s floor', 'ar' => 'الطابق %d' ),
                                '1 salon' => array( 'fr' => '1 salon', 'en' => '1 living room', 'ar' => 'صالون واحد' ),
                                '2 salons' => array( 'fr' => '2 salons', 'en' => '2 living rooms', 'ar' => 'صالونان' ),
                                '3 salons ou plus' => array( 'fr' => '3 salons ou plus', 'en' => '3 living rooms or more', 'ar' => '3 صالونات أو أكثر' ),
                                '1 salle de bains' => array( 'fr' => '1 salle de bains', 'en' => '1 bathroom', 'ar' => 'حمام واحد' ),
                                '2 salles de bains' => array( 'fr' => '2 salles de bains', 'en' => '2 bathrooms', 'ar' => 'حمامات' ),
                                '3 salles de bains ou plus' => array( 'fr' => '3 salles de bains ou plus', 'en' => '3 bathrooms or more', 'ar' => '3 حمامات أو أكثر' ),
                                'Recherche principale' => array( 'fr' => 'Recherche principale', 'en' => 'Main search', 'ar' => 'البحث الرئيسي' ),
                                'Annonce à la une' => array( 'fr' => 'Annonce à la une', 'en' => 'Featured listing', 'ar' => 'إعلان مميز' ),
                                'Des biens choisis pour leur lumière.' => array( 'fr' => 'Des biens choisis pour leur lumière.', 'en' => 'Properties chosen for their light.', 'ar' => 'عقارات مختارة لجمال إضاءتها.' ),
                                'Un accès direct aux annonces publiées par leurs propriétaires.' => array( 'fr' => 'Un accès direct aux annonces publiées par leurs propriétaires.', 'en' => 'Direct access to listings published by owners.', 'ar' => 'وصول مباشر للإعلانات المنشورة من قبل أصحابها.' ),
                                'Fraîchement publiées' => array( 'fr' => 'Fraîchement publiées', 'en' => 'Freshly published', 'ar' => 'نشرت حديثاً' ),
                                'Les dernières annonces' => array( 'fr' => 'Les dernières annonces', 'en' => 'Latest listings', 'ar' => 'آخر الإعلانات' ),
                                'Des biens ajoutés récemment par leurs propriétaires.' => array( 'fr' => 'Des biens ajoutés récemment par leurs propriétaires.', 'en' => 'Properties recently added by their owners.', 'ar' => 'عقارات أضيفت مؤخراً من قبل أصحابها.' ),
                                'Voir toutes les annonces' => array( 'fr' => 'Voir toutes les annonces', 'en' => 'View all listings', 'ar' => 'مشاهدة جميع الإعلانات' ),
                                'Trouvez votre bien' => array( 'fr' => 'Trouvez votre bien', 'en' => 'Find your property', 'ar' => 'جد عقارك' ),
                                'Une recherche qui commence par le bon lieu.' => array( 'fr' => 'Une recherche qui commence par le bon lieu.', 'en' => 'A search that starts with the right place.', 'ar' => 'بحث يبدأ بالمكان المناسب.' ),
                                'Parcourez les catégories sans bruit, puis laissez les détails vous guider.' => array( 'fr' => 'Parcourez les catégories sans bruit, puis laissez les détails vous guider.', 'en' => 'Browse categories without noise, then let details guide you.', 'ar' => 'تصفح الفئات بكل هدوء، واترك التفاصيل توجهك.' ),
                                'À louer directement' => array( 'fr' => 'À louer directement', 'en' => 'For rent directly', 'ar' => 'للكراء المباشر' ),
                                'Un appartement avec vue sur le large.' => array( 'fr' => 'Un appartement avec vue sur le large.', 'en' => 'An apartment with a view of the open sea.', 'ar' => 'شقة مطلة على البحر.' ),
                                'Découvrez les biens qui privilégient la lumière, l’espace et le contact direct.' => array( 'fr' => 'Découvrez les biens qui privilégient la lumière, l’espace et le contact direct.', 'en' => 'Discover properties that prioritize light, space and direct contact.', 'ar' => 'اكتشف العقارات التي تعطي الأولوية للإضاءة، المساحة والتواصل المباشر.' ),
                                'Rechercher un bien' => array( 'fr' => 'Rechercher un bien', 'en' => 'Search for a property', 'ar' => 'البحث عن عقار' ),
                                'Proche de chez vous' => array( 'fr' => 'Proche de chez vous', 'en' => 'Near you', 'ar' => 'بالقرب منك' ),
                                'Trouvez un bien dans votre ville.' => array( 'fr' => 'Trouvez un bien dans votre ville.', 'en' => 'Find a property in your city.', 'ar' => 'جد عقاراً في مدينتك.' ),
                                'Les quartiers et villes sont indexés pour vous aider à trouver plus vite.' => array( 'fr' => 'Les quartiers et villes sont indexés pour vous aider à trouver plus vite.', 'en' => 'Neighborhoods and cities are indexed to help you find faster.', 'ar' => 'الأحياء والمدن مفهرسة لمساعدتك في العثور بسرعة.' ),
                                'Explorer les annonces' => array( 'fr' => 'Explorer les annonces', 'en' => 'Explore listings', 'ar' => 'استكشاف الإعلانات' ),
                                'Par région' => array( 'fr' => 'Par région', 'en' => 'By region', 'ar' => 'حسب الجهة' ),
                                'Partout au Maroc.' => array( 'fr' => 'Partout au Maroc.', 'en' => 'Throughout Morocco.', 'ar' => 'في جميع أنحاء المغرب.' ),
                                'Aide' => array( 'fr' => 'Aide', 'en' => 'Help', 'ar' => 'مساعدة' ),
                                        'Maison lumineuse à vendre entre particuliers' => array( 'fr' => 'Maison lumineuse à vendre entre particuliers', 'en' => 'Bright house for sale between individuals', 'ar' => 'منزل مشرق للبيع بين الأفراد' ),
                                        'Choisissez un quartier' => array( 'fr' => 'Choisissez un quartier', 'en' => 'Choose a neighborhood', 'ar' => 'اختر الحي' ),
                                        'Le catalogue direct' => array( 'fr' => 'Le catalogue direct', 'en' => 'The direct catalog', 'ar' => 'كتالوج مباشر' ),
                                        'Des biens publiés directement par leurs propriétaires.' => array( 'fr' => 'Des biens publiés directement par leurs propriétaires.', 'en' => 'Properties published directly by their owners.', 'ar' => 'عقارات منشورة مباشرة من قبل أصحابها.' ),
                                        'Localisation d’abord' => array( 'fr' => 'Localisation d’abord', 'en' => 'Location first', 'ar' => 'الموقع أولاً' ),
                                        'Action' => array( 'fr' => 'Action', 'en' => 'Action', 'ar' => 'العملية' ),
                                        'Budget maximum' => array( 'fr' => 'Budget maximum', 'en' => 'Maximum budget', 'ar' => 'الميزانية القصوى' ),
                                        'Villes populaires' => array( 'fr' => 'Villes populaires', 'en' => 'Popular cities', 'ar' => 'المدن الأكثر شعبية' ),
                                        'MAD max' => array( 'fr' => 'MAD max', 'en' => 'MAD max', 'ar' => 'الحد الأقصى بالدرهم' ),
                                        'Budget maximum en MAD' => array( 'fr' => 'Budget maximum en MAD', 'en' => 'Maximum budget in MAD', 'ar' => 'الميزانية القصوى بالدرهم' ),
                                        'Aucune annonce ne correspond à votre recherche.' => array( 'fr' => 'Aucune annonce ne correspond à votre recherche.', 'en' => 'No listing matches your search.', 'ar' => 'لا يوجد إعلان يطابق بحثك.' ),
                                        'Essayez d\'élargir vos critères ou consultez les villes populaires.' => array( 'fr' => 'Essayez d\'élargir vos critères ou consultez les villes populaires.', 'en' => 'Try to broaden your criteria or check popular cities.', 'ar' => 'حاول توسيع معاييرك أو تحقق من المدن الأكثر شعبية.' ),
                                        'Publier gratuitement' => array( 'fr' => 'Publier gratuitement', 'en' => 'Publish for free', 'ar' => 'نشر مجاني' ),
                                        'Toutes les annonces' => array( 'fr' => 'Toutes les annonces', 'en' => 'All listings', 'ar' => 'جميع الإعلانات' ),
                                        'Propriétaire' => array( 'fr' => 'Propriétaire', 'en' => 'Owner', 'ar' => 'المالك' ),
                                        '/ mois' => array( 'fr' => '/ mois', 'en' => '/ month', 'ar' => '/ شهر' ),
                                        'Ajouter aux favoris' => array( 'fr' => 'Ajouter aux favoris', 'en' => 'Add to favorites', 'ar' => 'إضافة إلى المفضلة' ),
                                        'Partager cette annonce' => array( 'fr' => 'Partager cette annonce', 'en' => 'Share this listing', 'ar' => 'مشاركة هذا الإعلان' ),
                                        'Caractéristiques' => array( 'fr' => 'Caractéristiques', 'en' => 'Features', 'ar' => 'الخصائص' ),
                                        'Oui' => array( 'fr' => 'Oui', 'en' => 'Yes', 'ar' => 'نعم' ),
                                        'Non' => array( 'fr' => 'Non', 'en' => 'No', 'ar' => 'لا' ),
                                        'Surface' => array( 'fr' => 'Surface', 'en' => 'Area', 'ar' => 'المساحة' ),
                                        'Chambres' => array( 'fr' => 'Chambres', 'en' => 'Bedrooms', 'ar' => 'غرف النوم' ),
                                        'Salons' => array( 'fr' => 'Salons', 'en' => 'Living rooms', 'ar' => 'الصالات' ),
                                        'Salles de bains' => array( 'fr' => 'Salles de bains', 'en' => 'Bathrooms', 'ar' => 'حمامات' ),
                                        'Terrasse' => array( 'fr' => 'Terrasse', 'en' => 'Terrace', 'ar' => 'تراس' ),
                                        'Parkings' => array( 'fr' => 'Parkings', 'en' => 'Parking', 'ar' => 'مواقف سيارات' ),
                                        'Étage' => array( 'fr' => 'Étage', 'en' => 'Floor', 'ar' => 'الطابق' ),
                                        'Année de construction' => array( 'fr' => 'Année de construction', 'en' => 'Year built', 'ar' => 'سنة البناء' ),
                                        'Classe énergie' => array( 'fr' => 'Classe énergie', 'en' => 'Energy class', 'ar' => 'فئة الطاقة' ),
                                        'Description' => array( 'fr' => 'Description', 'en' => 'Description', 'ar' => 'الوصف' ),
                                        'Contact sécurisé' => array( 'fr' => 'Contact sécurisé', 'en' => 'Secure contact', 'ar' => 'اتصال آمن' ),
                                        'Intéressé par ce bien ?' => array( 'fr' => 'Intéressé par ce bien ?', 'en' => 'Interested in this property?', 'ar' => 'مهتم بهذا العقار؟' ),
                                        'Envoyez cette annonce sur WhatsApp. Après vérification de votre demande, nous vous transmettons les coordonnées du propriétaire.' => array( 'fr' => 'Envoyez cette annonce sur WhatsApp. Après vérification de votre demande, nous vous transmettons les coordonnées du propriétaire.', 'en' => 'Send this listing on WhatsApp. After verification of your request, we will send you the owner\'s contact details.', 'ar' => 'أرسل هذا الإعلان على واتساب. بعد التحقق من طلبك، سنقوم بتزويدك بمعلومات الاتصال الخاصة بالمالك.' ),
                                        'Contact transmis après contrôle' => array( 'fr' => 'Contact transmis après contrôle', 'en' => 'Contact transmitted after verification', 'ar' => 'يتم إرسال معلومات الاتصال بعد التحقق' ),
                                        'Demander sur WhatsApp' => array( 'fr' => 'Demander sur WhatsApp', 'en' => 'Request on WhatsApp', 'ar' => 'الطلب عبر واتساب' ),
                                        'Vos critères sont enregistrés pour vérifier la demande. Les annonces similaires ne sont envoyées qu’avec votre accord explicite.' => array( 'fr' => 'Vos critères sont enregistrés pour vérifier la demande. Les annonces similaires ne sont envoyées qu’avec votre accord explicite.', 'en' => 'Your criteria are recorded to verify the request. Similar listings are only sent with your explicit agreement.', 'ar' => 'يتم تسجيل معاييرك للتحقق من الطلب. لا يتم إرسال إعلانات مماثلة إلا بموافقتك الصريحة.' ),
                                        'Voir les autres annonces dans cette ville' => array( 'fr' => 'Voir les autres annonces dans cette ville', 'en' => 'See other listings in this city', 'ar' => 'مشاهدة الإعلانات الأخرى في هذه المدينة' ),
                                        'Dans la même ville' => array( 'fr' => 'Dans la même ville', 'en' => 'In the same city', 'ar' => 'في نفس المدينة' ),
                                        'Voir aussi à %s' => array( 'fr' => 'Voir aussi à %s', 'en' => 'See also in %s', 'ar' => 'شاهد أيضاً في %s' ),
                                        'Tout voir' => array( 'fr' => 'Tout voir', 'en' => 'See all', 'ar' => 'مشاهدة الكل' ),
                                        'Maison lumineuse à vendre entre particuliers' => array( 'fr' => 'Maison lumineuse à vendre entre particuliers', 'en' => 'Bright house for sale by owner', 'ar' => 'منزل مشرق للبيع من طرف صاحبه' ),
                                        'Explorer' => array( 'fr' => 'Explorer', 'en' => 'Explore', 'ar' => 'استكشاف' ),
                                        'Appartement' => array( 'fr' => 'Appartement', 'en' => 'Apartment', 'ar' => 'شقة' ),
                                        'Maison' => array( 'fr' => 'Maison', 'en' => 'House', 'ar' => 'منزل' ),
                                        'Terrain' => array( 'fr' => 'Terrain', 'en' => 'Land', 'ar' => 'أرض' ),
                                        'Parking' => array( 'fr' => 'Parking', 'en' => 'Parking', 'ar' => 'موقف سيارات' ),
                                        'Immeuble' => array( 'fr' => 'Immeuble', 'en' => 'Building', 'ar' => 'عمارة' ),
                                        'Local' => array( 'fr' => 'Local', 'en' => 'Commercial', 'ar' => 'محل تجاري' ),
                                        'Caractéristiques' => array( 'fr' => 'Caractéristiques', 'en' => 'Features', 'ar' => 'المميزات' ),
                                        'Description' => array( 'fr' => 'Description', 'en' => 'Description', 'ar' => 'الوصف' ),
                                        'Demander sur WhatsApp' => array( 'fr' => 'Demander sur WhatsApp', 'en' => 'Ask on WhatsApp', 'ar' => 'الطلب عبر واتساب' ),
                                        'Chambres' => array( 'fr' => 'Chambres', 'en' => 'Bedrooms', 'ar' => 'غرف النوم' ),
                                        'Salons' => array( 'fr' => 'Salons', 'en' => 'Living rooms', 'ar' => 'صالونات' ),
                                        'Salles de bains' => array( 'fr' => 'Salles de bains', 'en' => 'Bathrooms', 'ar' => 'حمامات' ),
                                        'Parkings' => array( 'fr' => 'Parkings', 'en' => 'Parking spaces', 'ar' => 'مواقف السيارات' ),
                                        'Étage' => array( 'fr' => 'Étage', 'en' => 'Floor', 'ar' => 'الطابق' ),
                                        'Année de construction' => array( 'fr' => 'Année de construction', 'en' => 'Year built', 'ar' => 'سنة البناء' ),
                                        'Classe énergie' => array( 'fr' => 'Classe énergie', 'en' => 'Energy class', 'ar' => 'فئة الطاقة' ),
                                        'Contact sécurisé' => array( 'fr' => 'Contact sécurisé', 'en' => 'Secure contact', 'ar' => 'اتصال آمن' ),
                                        'Intéressé par ce bien ?' => array( 'fr' => 'Intéressé par ce bien ?', 'en' => 'Interested in this property?', 'ar' => 'مهتم بهذا العقار؟' ),
                                        'Envoyez cette annonce sur WhatsApp. Après vérification de votre demande, nous vous transmettons les coordonnées du propriétaire.' => array( 'fr' => 'Envoyez cette annonce sur WhatsApp. Après vérification de votre demande, nous vous transmettons les coordonnées du propriétaire.', 'en' => 'Send this listing on WhatsApp. After verification of your request, we will send you the owner\'s contact details.', 'ar' => 'أرسل هذا الإعلان عبر واتساب. بعد التحقق من طلبك، سنزودك ببيانات اتصال المالك.' ),
                                        'Cette annonce ne reçoit plus de contacts.' => array( 'fr' => 'Cette annonce ne reçoit plus de contacts.', 'en' => 'This listing no longer accepts contacts.', 'ar' => 'هذا الإعلان لم يعد يستقبل اتصالات.' ),
                                        'Envoyez-nous cette annonce sur WhatsApp. Après contrôle de votre demande, nous vous transmettons les coordonnées du propriétaire.' => array( 'fr' => 'Envoyez-nous cette annonce sur WhatsApp. Après contrôle de votre demande, nous vous transmettons les coordonnées du propriétaire.', 'en' => 'Send us this listing on WhatsApp. After verification of your request, we will send you the owner\'s contact details.', 'ar' => 'أرسل لنا هذا الإعلان عبر واتساب. بعد التحقق من طلبك، سنزودك ببيانات اتصال المالك.' ),
                                        'Vos critères seront demandés sur WhatsApp. Les annonces similaires ne sont envoyées qu’avec votre accord explicite.' => array( 'fr' => 'Vos critères seront demandés sur WhatsApp. Les annonces similaires ne sont envoyées qu’avec votre accord explicite.', 'en' => 'Your criteria will be requested on WhatsApp. Similar listings are only sent with your explicit agreement.', 'ar' => 'سيتم طلب معاييرك عبر واتساب. لا يتم إرسال إعلانات مماثلة إلا بموافقتك الصريحة.' ),
                                        'Vos critères sont vérifiés avant transmission des coordonnées.' => array( 'fr' => 'Vos critères sont vérifiés avant transmission des coordonnées.', 'en' => 'Your criteria are verified before transmitting contact details.', 'ar' => 'يتم التحقق من معاييرك قبل إرسال بيانات الاتصال.' ),
                                        'Dans la même ville' => array( 'fr' => 'Dans la même ville', 'en' => 'In the same city', 'ar' => 'في نفس المدينة' ),
                                        'Voir aussi à %s' => array( 'fr' => 'Voir aussi à %s', 'en' => 'See also in %s', 'ar' => 'شاهد أيضاً في %s' ),
                                        'Prix sur demande' => array( 'fr' => 'Prix sur demande', 'en' => 'Price on request', 'ar' => 'السعر عند الطلب' ),
                                        'Aperçu rapide' => array( 'fr' => 'Aperçu rapide', 'en' => 'Quick view', 'ar' => 'عرض سريع' ),
                                        'Composition' => array( 'fr' => 'Composition', 'en' => 'Composition', 'ar' => 'التكوين' ),
                                        'Voir l\'annonce' => array( 'fr' => 'Voir l\'annonce', 'en' => 'View listing', 'ar' => 'عرض الإعلان' ),
                                        'Bien' => array( 'fr' => 'Bien', 'en' => 'Property', 'ar' => 'عقار' ),
                                        '1 chambre' => array( 'fr' => '1 chambre', 'en' => '1 bedroom', 'ar' => 'غرفة نوم واحدة' ),
                                        '2 chambres' => array( 'fr' => '2 chambres', 'en' => '2 bedrooms', 'ar' => 'غرفتا نوم' ),
                                        '1 salon' => array( 'fr' => '1 salon', 'en' => '1 living room', 'ar' => 'صالون واحد' ),
                                        '2 salons' => array( 'fr' => '2 salons', 'en' => '2 living rooms', 'ar' => 'صالونان' ),
                                        '1 salle de bains' => array( 'fr' => '1 salle de bains', 'en' => '1 bathroom', 'ar' => 'حمام واحد' ),
                                        '2 salles de bains' => array( 'fr' => '2 salles de bains', 'en' => '2 bathrooms', 'ar' => 'حمامات' ),
                                        '3 chambres ou plus' => array( 'fr' => '3 chambres ou plus', 'en' => '3 or more bedrooms', 'ar' => '3 غرف نوم أو أكثر' ),
                                        '3 salons ou plus' => array( 'fr' => '3 salons ou plus', 'en' => '3 or more living rooms', 'ar' => '3 صالونات أو أكثر' ),
                                        '3 salles de bains ou plus' => array( 'fr' => '3 salles de bains ou plus', 'en' => '3 or more bathrooms', 'ar' => '3 حمامات أو أكثر' ),
                                        'Studio' => array( 'fr' => 'Studio', 'en' => 'Studio', 'ar' => 'استوديو' ),
                                        'Pièce principale' => array( 'fr' => 'Pièce principale', 'en' => 'Main room', 'ar' => 'الغرفة الرئيسية' ),
                                        'Commencez par une ville, un quartier ou un code postal.' => array( 'fr' => 'Commencez par une ville, un quartier ou un code postal.', 'en' => 'Start with a city, neighborhood, or zip code.', 'ar' => 'ابدأ بمدينة أو حي أو رمز بريدي.' ),
                                        'annonces' => array( 'fr' => 'annonces', 'en' => 'listings', 'ar' => 'إعلانات' ),
                                        'Questions fréquentes' => array( 'fr' => 'Questions fréquentes', 'en' => 'FAQ', 'ar' => 'الأسئلة الشائعة' ),
                                        'Contactez-nous' => array( 'fr' => 'Contactez-nous', 'en' => 'Contact us', 'ar' => 'اتصل بنا' ),
                                        'Mentions légales' => array( 'fr' => 'Mentions légales', 'en' => 'Legal notice', 'ar' => 'إشعار قانوني' ),
                                        'Maroc' => array( 'fr' => 'Maroc', 'en' => 'Morocco', 'ar' => 'المغرب' ),
                                'Titre de l’annonce *' => array( 'fr' => 'Titre de l’annonce *', 'en' => 'Listing title *', 'ar' => 'عنوان الإعلان *' ),
                        'Ex : Appartement lumineux 3 pièces avec balcon' => array( 'fr' => 'Ex : Appartement lumineux 3 pièces avec balcon', 'en' => 'E.g. Bright 3-bedroom apartment with balcony', 'ar' => 'مثال: شقة مشرقة بثلاث غرف مع شرفة' ),
                        'Vous souhaitez' => array( 'fr' => 'Vous souhaitez', 'en' => 'You want to', 'ar' => 'أرغب في' ),
                        'Type de bien' => array( 'fr' => 'Type de bien', 'en' => 'Property type', 'ar' => 'نوع العقار' ),
                                'Prix (MAD)' => array( 'fr' => 'Prix (MAD)', 'en' => 'Price (MAD)', 'ar' => 'السعر (درهم)' ),
                        'Ex : 245000' => array( 'fr' => 'Ex : 245000', 'en' => 'E.g. 245000', 'ar' => 'مثال: 245000' ),
                        'Ville' => array( 'fr' => 'Ville', 'en' => 'City', 'ar' => 'المدينة' ),
                        'Choisir une ville…' => array( 'fr' => 'Choisir une ville…', 'en' => 'Choose a city…', 'ar' => 'اختر مدينة…' ),
                        'Surface (m²)' => array( 'fr' => 'Surface (m²)', 'en' => 'Area (m²)', 'ar' => 'المساحة (م²)' ),
                        'Nombre de chambres' => array( 'fr' => 'Nombre de chambres', 'en' => 'Bedrooms', 'ar' => 'عدد غرف النوم' ),
                        'Choisir…' => array( 'fr' => 'Choisir…', 'en' => 'Choose…', 'ar' => 'اختر…' ),
                        'Studio / 0 chambre' => array( 'fr' => 'Studio / 0 chambre', 'en' => 'Studio / 0 bedrooms', 'ar' => 'استوديو / دون غرفة نوم' ),
                        '1 chambre' => array( 'fr' => '1 chambre', 'en' => '1 bedroom', 'ar' => 'غرفة نوم واحدة' ),
                        '2 chambres' => array( 'fr' => '2 chambres', 'en' => '2 bedrooms', 'ar' => 'غرفتا نوم' ),
                        '3 chambres ou plus' => array( 'fr' => '3 chambres ou plus', 'en' => '3 bedrooms or more', 'ar' => '3 غرف نوم أو أكثر' ),
                        'Nombre de salons' => array( 'fr' => 'Nombre de salons', 'en' => 'Living rooms', 'ar' => 'عدد غرف الجلوس' ),
                        '0 salon — pièce principale' => array( 'fr' => '0 salon — pièce principale', 'en' => '0 living rooms — main room', 'ar' => 'دون غرفة جلوس — الغرفة الرئيسية' ),
                        '1 salon' => array( 'fr' => '1 salon', 'en' => '1 living room', 'ar' => 'غرفة جلوس واحدة' ),
                        '2 salons' => array( 'fr' => '2 salons', 'en' => '2 living rooms', 'ar' => 'غرفتا جلوس' ),
                        '3 salons ou plus' => array( 'fr' => '3 salons ou plus', 'en' => '3 living rooms or more', 'ar' => '3 غرف جلوس أو أكثر' ),
                        'Nombre de salles de bains' => array( 'fr' => 'Nombre de salles de bains', 'en' => 'Bathrooms', 'ar' => 'عدد الحمامات' ),
                        '1 salle de bains' => array( 'fr' => '1 salle de bains', 'en' => '1 bathroom', 'ar' => 'حمام واحد' ),
                        '2 salles de bains' => array( 'fr' => '2 salles de bains', 'en' => '2 bathrooms', 'ar' => 'حمامان' ),
                        '3 salles de bains ou plus' => array( 'fr' => '3 salles de bains ou plus', 'en' => '3 bathrooms or more', 'ar' => '3 حمامات أو أكثر' ),
                        'Terrasse' => array( 'fr' => 'Terrasse', 'en' => 'Terrace', 'ar' => 'شرفة' ),
                        'Non' => array( 'fr' => 'Non', 'en' => 'No', 'ar' => 'لا' ),
                        'Oui' => array( 'fr' => 'Oui', 'en' => 'Yes', 'ar' => 'نعم' ),
                        'Superficie de la terrasse (m²)' => array( 'fr' => 'Superficie de la terrasse (m²)', 'en' => 'Terrace area (m²)', 'ar' => 'مساحة الشرفة (م²)' ),
                        'Sans vis-à-vis' => array( 'fr' => 'Sans vis-à-vis', 'en' => 'No overlooking neighbours', 'ar' => 'بدون إطلالة مقابلة' ),
                        'Ensoleillement' => array( 'fr' => 'Ensoleillement', 'en' => 'Sunlight', 'ar' => 'التعرض للشمس' ),
                        'Ensoleillé le matin' => array( 'fr' => 'Ensoleillé le matin', 'en' => 'Morning sun', 'ar' => 'مشمس صباحاً' ),
                        'Ensoleillé l’après-midi' => array( 'fr' => 'Ensoleillé l’après-midi', 'en' => 'Afternoon sun', 'ar' => 'مشمس بعد الظهر' ),
                        'Toute la journée' => array( 'fr' => 'Toute la journée', 'en' => 'All day', 'ar' => 'طوال اليوم' ),
                        'Très peu' => array( 'fr' => 'Très peu', 'en' => 'Very little', 'ar' => 'قليل جداً' ),
                        'Description *' => array( 'fr' => 'Description *', 'en' => 'Description *', 'ar' => 'الوصف *' ),
                        'Décrivez votre bien : état, atouts, quartier, transports…' => array( 'fr' => 'Décrivez votre bien : état, atouts, quartier, transports…', 'en' => 'Describe your property: condition, features, neighbourhood, transport…', 'ar' => 'صف عقارك: حالته، مزاياه، الحي ووسائل النقل…' ),
                        'Vos photos' => array( 'fr' => 'Vos photos', 'en' => 'Your photos', 'ar' => 'صورك' ),
                        'Glissez vos photos ici' => array( 'fr' => 'Glissez vos photos ici', 'en' => 'Drag your photos here', 'ar' => 'اسحب صورك إلى هنا' ),
                        'ou cliquez pour parcourir (15 photos maximum, JPG/PNG)' => array( 'fr' => 'ou cliquez pour parcourir (15 photos maximum, JPG/PNG)', 'en' => 'or click to browse (15 photos maximum, JPG/PNG)', 'ar' => 'أو اضغط للتصفح (15 صورة كحد أقصى، JPG/PNG)' ),
                        'Vous (simple et rapide)' => array( 'fr' => 'Vous (simple et rapide)', 'en' => 'You (quick and easy)', 'ar' => 'بياناتك (بسهولة وسرعة)' ),
                        'Votre nom *' => array( 'fr' => 'Votre nom *', 'en' => 'Your name *', 'ar' => 'اسمك *' ),
                        'Jean Dupont' => array( 'fr' => 'Jean Dupont', 'en' => 'John Smith', 'ar' => 'محمد العلوي' ),
                        'E-mail' => array( 'fr' => 'E-mail', 'en' => 'Email', 'ar' => 'البريد الإلكتروني' ),
                        'vous@exemple.com' => array( 'fr' => 'vous@exemple.com', 'en' => 'you@example.com', 'ar' => 'you@example.com' ),
                        'Téléphone' => array( 'fr' => 'Téléphone', 'en' => 'Phone', 'ar' => 'الهاتف' ),
                        '06 12 34 56 78' => array( 'fr' => '06 12 34 56 78', 'en' => '+1 555 123 4567', 'ar' => '06 12 34 56 78' ),
                        'Votre téléphone est requis pour demander la validation via WhatsApp. Votre e-mail reste facultatif.' => array( 'fr' => 'Votre téléphone est requis pour demander la validation via WhatsApp. Votre e-mail reste facultatif.', 'en' => 'Your phone number is required for WhatsApp validation. Email is optional.', 'ar' => 'رقم الهاتف مطلوب لطلب التحقق عبر واتساب. البريد الإلكتروني اختياري.' ),
                        'Qui êtes-vous ?' => array( 'fr' => 'Qui êtes-vous ?', 'en' => 'Who are you?', 'ar' => 'من أنت؟' ),
                        'Vous êtes' => array( 'fr' => 'Vous êtes', 'en' => 'You are', 'ar' => 'أنت' ),
                        'Propriétaire' => array( 'fr' => 'Propriétaire', 'en' => 'Owner', 'ar' => 'مالك' ),
                        'Je vends ou loue mon bien' => array( 'fr' => 'Je vends ou loue mon bien', 'en' => 'I am selling or renting my property', 'ar' => 'أبيع أو أؤجر عقاري' ),
                        'Propriétaire' => array( 'fr' => 'Propriétaire', 'en' => 'Owner', 'ar' => 'المالك' ),
                        'Je gère pour quelqu’un' => array( 'fr' => 'Je gère pour quelqu’un', 'en' => 'I manage a property for someone else', 'ar' => 'أدير عقاراً لشخص آخر' ),
                        'Après l’enregistrement, vous enverrez un message WhatsApp prérempli. L’équipe vérifiera ce message avant de mettre l’annonce en ligne.' => array( 'fr' => 'Après l’enregistrement, vous enverrez un message WhatsApp prérempli. L’équipe vérifiera ce message avant de mettre l’annonce en ligne.', 'en' => 'After submission, you will send a pre-filled WhatsApp message. Our team will review it before publishing the listing.', 'ar' => 'بعد الإرسال، سترسل رسالة واتساب جاهزة. سيتحقق فريقنا منها قبل نشر الإعلان.' ),
                        'Demander la validation WhatsApp' => array( 'fr' => 'Demander la validation WhatsApp', 'en' => 'Request WhatsApp validation', 'ar' => 'طلب التحقق عبر واتساب' ),
                );
        }

        public static function current_language() {
                if ( function_exists( 'pll_current_language' ) ) {
                        $language = pll_current_language( 'slug' );
                        if ( in_array( $language, self::supported_locales(), true ) ) {
                                return $language;
                        }
                }
                return 'fr';
        }

        /**
         * Déclare le post type Estatik auprès de Polylang lorsqu’il est présent.
         * Le second appel du filtre avec $is_settings à false le maintient actif sans
         * dépendre d’un réglage administrateur mutable.
         */
        public static function register_polylang_post_type( $post_types, $is_settings ) {
                if ( ! defined( 'POLYLANG_VERSION' ) ) {
                        return $post_types;
                }

                $post_types[] = PARTIKULIER_ESTATIK_POST_TYPE;
                return array_values( array_unique( $post_types ) );
        }

        /**
         * Déclare uniquement les taxonomies Estatik utilisées par le thème.
         */
        public static function register_polylang_taxonomies( $taxonomies, $is_settings ) {
                if ( ! defined( 'POLYLANG_VERSION' ) ) {
                        return $taxonomies;
                }

                $taxonomies[] = PARTIKULIER_ESTATIK_TYPE_TAXONOMY;
                $taxonomies[] = PARTIKULIER_ESTATIK_STATUS_TAXONOMY;
                $taxonomies[] = PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY;
                $taxonomies[] = PARTIKULIER_ESTATIK_LOCATION_TAXONOMY;
                return array_values( array_unique( $taxonomies ) );
        }

        public static function supported_locales() {
                return array( 'fr', 'ar', 'en' );
        }

        public static function is_public_enabled() {
                return '1' === (string) get_option( self::OPTION_PUBLIC_ENABLED, '0' );
        }

        public static function maybe_install() {
                if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
                        return;
                }

                global $wpdb;
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $table = self::table_name();
                $charset = $wpdb->get_charset_collate();
                dbDelta( "CREATE TABLE {$table} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        source_property_id bigint(20) unsigned NOT NULL,
                        locale varchar(8) NOT NULL,
                        variant_property_id bigint(20) unsigned NOT NULL DEFAULT 0,
                        original_free_text_locale varchar(8) NOT NULL,
                        status varchar(16) NOT NULL,
                        created_at datetime NOT NULL,
                        updated_at datetime NOT NULL,
                        PRIMARY KEY  (id),
                        UNIQUE KEY source_locale (source_property_id,locale),
                        KEY variant_property (variant_property_id),
                        KEY status_locale (status,locale)
                ) {$charset};" );
                update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
                if ( false === get_option( self::OPTION_PUBLIC_ENABLED, false ) ) {
                        add_option( self::OPTION_PUBLIC_ENABLED, '0', '', false );
                }
        }

        public static function table_name() {
                global $wpdb;
                return $wpdb->prefix . 'pk_property_variants';
        }

        /**
         * Enregistre un emplacement de variante sans dupliquer de contenu. Une future
         * passerelle de traduction renseignera variant_property_id après création contrôlée.
         *
         * @return true|WP_Error
         */
        public static function prepare_variant( $source_property_id, $locale, $original_free_text_locale ) {
                $source_property_id = absint( $source_property_id );
                $locale = self::sanitize_locale( $locale );
                $original_free_text_locale = self::sanitize_locale( $original_free_text_locale );
                if ( ! $source_property_id || PARTIKULIER_ESTATIK_POST_TYPE !== get_post_type( $source_property_id ) ) {
                        return new WP_Error( 'pk_variant_property', __( 'Annonce source invalide.', 'partikulier' ) );
                }
                if ( ! $locale || ! $original_free_text_locale ) {
                        return new WP_Error( 'pk_variant_locale', __( 'Langue de variante invalide.', 'partikulier' ) );
                }

                global $wpdb;
                $now = current_time( 'mysql', true );
                $result = $wpdb->query(
                        $wpdb->prepare(
                                'INSERT INTO ' . self::table_name() . ' (source_property_id, locale, original_free_text_locale, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE original_free_text_locale = VALUES(original_free_text_locale), updated_at = VALUES(updated_at)',
                                $source_property_id,
                                $locale,
                                $original_free_text_locale,
                                self::STATUS_PREPARED,
                                $now,
                                $now
                        )
                );
                if ( false === $result ) {
                        return new WP_Error( 'pk_variant_storage', __( 'Impossible de préparer la variante localisée.', 'partikulier' ) );
                }
                update_post_meta( $source_property_id, self::META_FREE_TEXT_LANGUAGE, $original_free_text_locale );
                return true;
        }

        /**
         * Attache une variante Estatik déjà créée au registre métier, sans produire
         * aucun contenu et sans activer l’affichage public.
         *
         * @return true|WP_Error
         */
        public static function link_variant( $source_property_id, $variant_property_id, $locale, $original_free_text_locale ) {
                $source_property_id  = absint( $source_property_id );
                $variant_property_id = absint( $variant_property_id );
                $locale              = self::sanitize_locale( $locale );

                if ( ! $source_property_id || ! $variant_property_id || ! $locale ) {
                        return new WP_Error( 'pk_variant_link', __( 'Lien de variante invalide.', 'partikulier' ) );
                }
                if ( PARTIKULIER_ESTATIK_POST_TYPE !== get_post_type( $source_property_id ) || PARTIKULIER_ESTATIK_POST_TYPE !== get_post_type( $variant_property_id ) ) {
                        return new WP_Error( 'pk_variant_link_property', __( 'Les variantes doivent être des annonces Estatik.', 'partikulier' ) );
                }

                $prepared = self::prepare_variant( $source_property_id, $locale, $original_free_text_locale );
                if ( is_wp_error( $prepared ) ) {
                        return $prepared;
                }

                global $wpdb;
                $updated = $wpdb->update(
                        self::table_name(),
                        array(
                                'variant_property_id' => $variant_property_id,
                                'status'              => 'linked',
                                'updated_at'          => current_time( 'mysql', true ),
                        ),
                        array(
                                'source_property_id' => $source_property_id,
                                'locale'             => $locale,
                        ),
                        array( '%d', '%s', '%s' ),
                        array( '%d', '%s' )
                );

                if ( false === $updated ) {
                        return new WP_Error( 'pk_variant_link_storage', __( 'Impossible de lier la variante localisée.', 'partikulier' ) );
                }

                return true;
        }

        private static function sanitize_locale( $locale ) {
                $locale = strtolower( sanitize_key( $locale ) );
                return in_array( $locale, self::supported_locales(), true ) ? $locale : '';
        }
}

Partikulier_Localization::init();