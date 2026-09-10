<?php
/**
 * Module variantes de la localisation (lot C2 — découpe annexe C ≤ 300 l.).
 *
 * Repli de stockage du registre des variantes localisées (table
 * pk_property_variants, primitives prepare_variant/link_variant) — volet
 * absorbé par le plugin partikulier-core 2.6+ au lot B6 (service
 * \Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService) :
 * les appels publics délègent quand la classe existe ; sans le plugin, le
 * chemin autonome historique 6.17.x est conservé à l'identique (dégradation
 * gracieuse REG-5 — les deux chemins écrivent la même table avec la même
 * logique ; REG-6 : DDL à l'identique).
 *
 * Code déplacé VERBATIM depuis class-localization.php au lot C2 (le shell
 * atteignait 344 lignes avec la couture C2 — annexe C tableau 11 : modules
 * d'au plus 300 lignes) : aucune modification de comportement, la classe
 * publique garde ses méthodes et signatures par composition du trait.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

trait Partikulier_Localization_Variants {

        public static function maybe_install() {
                if ( self::core_translation_variants() ) {
                        // Lot B6 : le plugin détient le schéma (Schema 2.6.0,
                        // DDL à l'identique — REG-6) — le thème cesse
                        // d'installer. La table et l'option de version sont
                        // déjà en place (mêmes noms, mêmes valeurs).
                        return;
                }
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
                if ( self::core_translation_variants() ) {
                        // Lot B6 : l'écriture (gardes, upsert, méta du texte
                        // libre) vit côté plugin — délégation, preuve
                        // d'exécution par le registre d'audit.
                        return call_user_func( array( self::core_translation_variants(), 'prepare_variant' ), $source_property_id, $locale, $original_free_text_locale );
                }
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
                if ( self::core_translation_variants() ) {
                        // Lot B6 : la liaison (préparation + attachement de la
                        // variante) vit côté plugin — délégation, preuve
                        // d'exécution par le registre d'audit.
                        return call_user_func( array( self::core_translation_variants(), 'link_variant' ), $source_property_id, $variant_property_id, $locale, $original_free_text_locale );
                }
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

        /**
         * Couture du lot B6 : classe du service plugin quand il existe.
         *
         * @return class-string|null
         */
        private static function core_translation_variants() {
                return class_exists( '\Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService' )
                        ? '\Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService'
                        : null;
        }
}
