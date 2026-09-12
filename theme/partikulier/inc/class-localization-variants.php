<?php
/**
 * Module variantes de la localisation (lot C2 — découpe annexe C ≤ 300 l.).
 *
 * Volet du registre des variantes localisées (primitives
 * prepare_variant/link_variant) — absorbé par le plugin partikulier-core
 * 2.6+ au lot B6 (service
 * \Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService).
 *
 * Lot F de la refonte (CDC v1.2 — extinction finale) : le VESTIGE autonome
 * 6.17.x est physiquement retiré — installation de la table
 * pk_property_variants, écritures directes et assainissement local sont
 * éteints. Chaque appel délègue exclusivement au service du plugin, qui est
 * requis (le registre d'audit fait foi de l'exécution côté service).
 *
 * Code déplacé VERBATIM depuis class-localization.php au lot C2 : la classe
 * publique garde ses méthodes et signatures par composition du trait.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

trait Partikulier_Localization_Variants {

        /**
         * Enregistre un emplacement de variante sans dupliquer de contenu.
         * Une future passerelle de traduction renseignera
         * variant_property_id après création contrôlée.
         *
         * @return true|WP_Error
         */
        public static function prepare_variant( $source_property_id, $locale, $original_free_text_locale ) {
                if ( self::core_translation_variants() ) {
                        // Lot B6 : l'écriture (gardes, upsert, méta du texte
                        // libre) vit côté plugin — délégation, preuve
                        // d'exécution par le registre d'audit.
                        return call_user_func( array( self::core_translation_variants(), 'prepare_variant' ), $source_property_id, $locale, $original_free_text_locale );
                }
                return new WP_Error( 'pk_core_required', __( 'Le registre des variantes exige le plugin partikulier-core.', 'partikulier' ) );
        }

        /**
         * Attache une variante Estatik déjà créée au registre métier, sans produire
         * aucun contenu et sans activer l’affichage public.
         *
         * @return true|WP_Error
         */
        public static function link_variant( $source_property_id, $variant_property_id, $locale, $original_free_text_locale ) {
                if ( self::core_translation_variants() ) {
                        // Lot B6 : la liaison (préparation + attachement de la
                        // variante) vit côté plugin — délégation, preuve
                        // d'exécution par le registre d'audit.
                        return call_user_func( array( self::core_translation_variants(), 'link_variant' ), $source_property_id, $variant_property_id, $locale, $original_free_text_locale );
                }
                return new WP_Error( 'pk_core_required', __( 'Le registre des variantes exige le plugin partikulier-core.', 'partikulier' ) );
        }

        /**
         * Couture du lot B6, conservée au lot F : classe du service plugin
         * quand il existe — dépositaire unique du domaine.
         *
         * @return class-string|null
         */
        private static function core_translation_variants() {
                return class_exists( '\Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService' )
                        ? '\Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService'
                        : null;
        }
}
