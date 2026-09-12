<?php
/**
 * Registre de préparation des variantes localisées Partikulier.
 *
 * Ce module relie « une annonce métier × variantes SEO » à Polylang lorsqu’il
 * est actif. Le texte libre du propriétaire reste dans sa langue d’origine et
 * n’est jamais traité comme une traduction éditoriale officielle.
 *
 * Lot B6 (découpe REG-3, CDC v1.2) : le monolithe historique de 990 lignes
 * est découpé en modules d’au plus 300 lignes (annexe C tableau 11 — la part
 * du lot D fusionnée au B6) : ce fichier est le SHELL de composition (classe
 * Partikulier_Localization inchangée, même API publique, mêmes hooks), les
 * méthodes sont déplacées VERBATIM dans quatre traits chargés avant lui —
 * le mécanisme actif ne change pas au découpage (REG-3 : la migration de
 * mécanisme relève du lot C) :
 *   · Partikulier_Localization_Runtime  — textdomains, requêtes, redirection
 *   · Partikulier_Localization_Strings  — registre chrome + résolution
 *   · Partikulier_Localization_Chrome   — catalogue de repli du chrome
 *   · Partikulier_Localization_Forms    — catalogue de repli des formulaires
 *
 * Lot B6 (extraction) : le volet stockage du registre de variantes (table
 * pk_property_variants, primitives prepare_variant/link_variant) est
 * propriété du plugin partikulier-core 2.6+ (service
 * \Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService).
 * Les appels publics délègent quand la classe existe ; sans le plugin, le
 * chemin autonome historique 6.17.x est conservé à l’identique (dégradation
 * gracieuse REG-5 — les deux chemins écrivent la même table avec la même
 * logique). Le schéma n’est plus installé par le thème quand le plugin est
 * actif (DDL à l’identique — REG-6).
 *
 * Lot C2 (extinction, CDC v1.2 §3.2 I18N-1) : le filtre gettext
 * (translate_polylang_string) et les dictionnaires de repli chrome/form
 * cessent d'être le mécanisme ACTIF quand le service unifié du plugin
 * partikulier-core 2.8+ est chargé (\Partikulier\Core\Domain\I18n\
 * I18nChromeService) — ce service détient le filtre et les catalogues
 * canoniques (ports VERBATIM) ; le thème lui fournit son registre chrome
 * (provide_registry — donnée du thème : clés du shell + réglages) et cesse
 * d'enregistrer son propre filtre. Sans le plugin, le repli autonome
 * historique est conservé (dégradation gracieuse REG-5 — les deux chemins
 * servent les mêmes chaînes, preuve par contrat du lot C2). Les diction-
 * naires de repli restent la voie de résolution documentée sans plugin.
 *
 * Lot C4 (extinction FINALE — retrait physique sur la base 6.19.0) : les
 * accrochages de chargement de textdomains (init@5, wp@1, wp@2 et l'appel
 * anticipé du domaine « es ») sont retirés de init(), et le trait Runtime
 * ne porte plus AUCUNE méthode de chargement — le chargeur unique du plugin
 * (\Partikulier\Core\Domain\I18n\I18nDomainLoader, 2.9+) est le seul
 * mécanisme de chargement, le kit traducteur du domaine vit côté plugin
 * (copie de parité du thème retirée). Sans plugin : langue source française
 * (msgids) pour le domaine, dictionnaires de repli pour le chrome —
 * dégradation documentée au contrat du lot (tests/i18n-unified-mechanism-
 * contract.php, mis à jour en 2.9.1 : extinction physique, versions
 * épinglées 2.9.1/6.19.1).
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

        /* Lot B6 (découpe REG-3) : composition des modules — les traits sont
         * chargés AVANT ce shell (liste des modules de functions.php) : la
         * classe publique garde ses méthodes, signatures et hooks. */
        use Partikulier_Localization_Runtime;
        use Partikulier_Localization_Strings;
        use Partikulier_Localization_Chrome;
        use Partikulier_Localization_Forms;
        /* Lot C2 (découpe ≤ 300 l.) : repli variantes B6 déplacé
         * VERBATIM dans son trait (inc/class-localization-variants.php). */
        use Partikulier_Localization_Variants;

                public static function init() {
                        /* Lot C4 — RETRAIT PHYSIQUE : les accrochages de chargement
                         * de textdomains (init@5, wp@1, wp@2 et l'appel anticipé du
                         * domaine « es ») sont retirés — le chargeur unique du plugin
                         * partikulier-core 2.9+ (I18nDomainLoader, inscrit à son
                         * bootstrap) détient l'ensemble du mécanisme. */
                        add_action( 'template_redirect', array( __CLASS__, 'maybe_redirect_browser_language' ), 1 );
                        /* Lot F — EXTINCTION : l'accrochage maybe_install@6 est
                         * retiré — l'installation du schéma pk_property_variants
                         * est propriété du plugin partikulier-core 2.6+ (lot B6) ;
                         * le vestige autonome du trait Variants est éteint. */
                        add_action( 'admin_init', array( __CLASS__, 'register_polylang_strings' ) );
                        /* Lot C2 — EXTINCTION du filtre gettext : quand le service
                         * unifié du plugin (I18nChromeService) est chargé, LE
                         * mécanisme de résolution (dictionnaires + filtre) lui
                         * appartient — le plugin enregistre son propre filtre au
                         * bootstrap (plugins_loaded), le thème cesse d'enregistrer
                         * le sien et fournit le registre chrome au service
                         * (inversion de dépendance : le registre est une donnée
                         * du thème — clés du shell + réglages). Sans plugin, le
                         * repli local historique reste actif (REG-5). */
                        if ( null === self::core_i18n_chrome() ) {
                                add_filter( 'gettext', array( __CLASS__, 'translate_polylang_string' ), 10, 3 );
                        } else {
                                call_user_func( array( self::core_i18n_chrome(), 'provide_registry' ), array( __CLASS__, 'public_chrome_strings' ) );
                        }
                        add_filter( 'pll_get_post_types', array( __CLASS__, 'register_polylang_post_type' ), 10, 2 );
                        add_filter( 'pll_get_taxonomies', array( __CLASS__, 'register_polylang_taxonomies' ), 10, 2 );
                        add_filter( 'pll_preferred_language', array( __CLASS__, 'filter_robot_preferred_language' ), 10, 2 );
                        // Priorité 20 pour passer APRES les filtres par défaut d'Estatik.
                        add_action( 'pre_get_posts', array( __CLASS__, 'optimize_property_queries' ), 20 );
                        // Neutraliser le réglage Estatik properties_per_page pour forcer 24.
                        add_filter( 'es_settings', array( __CLASS__, 'force_estatik_pagination' ), 999 );
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

        /**
         * Couture du lot C2 : classe du service unifié plugin quand il existe.
         *
         * @return class-string|null
         */
        private static function core_i18n_chrome() {
                return class_exists( '\Partikulier\Core\Domain\I18n\I18nChromeService' )
                        ? '\Partikulier\Core\Domain\I18n\I18nChromeService'
                        : null;
        }

        /**
         * Couture du lot C2 : résolution gettext déléguée au service unifié
         * (dictionnaires + chaîne figée .mo > form > chrome > polylang côté
         * plugin 2.8+) ; repli local historique sinon (REG-5). Les quelque
         * 150 sites d'appel des templates passent par ici — même API, même
         * signature, même comportement (preuve par contrat C2A-004/005).
         */
        public static function translate_polylang_string( $translation, $text, $domain ) {
                if ( null !== self::core_i18n_chrome() ) {
                        return call_user_func( array( self::core_i18n_chrome(), 'translate' ), $translation, $text, $domain );
                }
                return self::translate_polylang_string_local( $translation, $text, $domain );
        }

        /**
         * Couture du lot C2 : traduction d'une chaîne enregistrée déléguée au
         * service unifié (registre fourni via provide_registry) ; repli local
         * historique sinon (REG-5).
         */
        public static function translate_public_string( $string ) {
                if ( null !== self::core_i18n_chrome() ) {
                        return call_user_func( array( self::core_i18n_chrome(), 'translate_public_string' ), $string );
                }
                return self::translate_public_string_local( $string );
        }


}

Partikulier_Localization::init();