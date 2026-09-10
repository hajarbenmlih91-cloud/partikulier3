<?php
/**
 * Module chaînes publiques de la localisation (lot B6, découpe REG-3 de
 * class-localization.php — 990 lignes historiques).
 *
 * Registre minimal du chrome commun (public_chrome_strings), traduction des
 * libellés de taxonomie (translate_taxonomy_label, avec repli sur
 * Partikurier_Listing_I18n), traduction des chaînes publiques enregistrées
 * (translate_public_string_local via pll__), inscription à l'outil natif Polylang
 * (register_polylang_strings) et résolution gettext avec repli sur les
 * dictionnaires internes (translate_polylang_string_local — l'ordre de résolution
 * .mo > form > chrome > polylang est figé par le test REG-3 du lot B6).
 *
 * Lot C2 (extinction) : les résolutions historiques
 * (translate_polylang_string_local / translate_public_string_local —
 * renommées *_local, chemins de repli REG-5) sont ré-exposées par la
 * couture du shell class-localization.php — délégation au service unifié
 * \Partikulier\Core\Domain\I18n\I18nChromeService (plugin 2.8+) quand il
 * existe, repli local historique sinon. Le registre chrome
 * (public_chrome_strings) reste une donnée du THÈME (clés du shell public +
 * réglages) : il est fourni au service via provide_registry. Le filtre gettext
 * n'est plus enregistré par le thème quand le plugin est actif (extinction —
 * le filtre du service unifié est LE mécanisme).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

trait Partikulier_Localization_Strings {

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

                /* Lot C2 : chemin de repli local (REG-5) — la couture du shell
                 * class-localization.php délègue au service unifié du plugin
                 * (I18nChromeService::translate_public_string) quand il existe. */
                public static function translate_public_string_local( $string ) {

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
         *
         * Lot C2 : chemin de repli local (REG-5) — la couture du shell délègue
         * au service unifié du plugin (I18nChromeService::translate) quand il
         * existe ; le filtre gettext du thème n'est plus enregistré dans ce
         * cas (extinction, le mécanisme appartient au plugin).
         */
        public static function translate_polylang_string_local( $translation, $text, $domain ) {
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

                return self::translate_public_string_local( $text );
        }
}

