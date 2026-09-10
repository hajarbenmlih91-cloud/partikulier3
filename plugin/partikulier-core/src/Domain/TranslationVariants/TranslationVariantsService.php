<?php
/**
 * Domaine variantes de traduction (lot B6) — registre des emplacements
 * localisés, huitième et dernier domaine extrait vers le plugin.
 *
 * Port fidèle de Partikulier_Localization (thème 6.17.x, volet registre) :
 * même table (pk_property_variants, mêmes colonnes et index — REG-6), mêmes
 * gardes, mêmes codes d'erreur (pk_variant_property, pk_variant_locale,
 * pk_variant_storage, pk_variant_link, pk_variant_link_property,
 * pk_variant_link_storage), même upsert ON DUPLICATE KEY par
 * (source_property_id, locale), même méta _pk_free_text_language posée sur
 * l'annonce source, mêmes statuts (prepared, linked). Le registre ne stocke
 * AUCUN contenu : prepare_variant enregistre un emplacement sans dupliquer
 * de contenu ; link_variant attache une annonce Estatik déjà créée — une
 * future passerelle de traduction (lot C) renseignera variant_property_id
 * après création contrôlée.
 *
 * Le thème 6.18.7+ délègue ici via des coutures class_exists ; sans le
 * plugin, il conserve son chemin autonome — les deux écrivent la même table
 * avec la même logique (matrice REG-5). Le schéma n'est plus installé par le
 * thème quand le plugin est actif (DDL à l'identique — REG-6).
 *
 * Aucun cron, aucune route REST, aucun appelant runtime au jour du lot :
 * le domaine est dormant (aucune variante au T0, aucune écriture constatée).
 * Le service est chargé inconditionnellement (classe pure, aucun hook au
 * chargement — pattern alertes B3) : la couture du thème le trouve sur toute
 * requête. Les API publiques prepare_variant/link_variant seront branchées
 * par la passerelle de traduction du lot C.
 *
 * Journal d'audit : les écritures (variante préparée, variante liée) sont
 * consignées au registre — preuve d'exécution par le plugin (seul le plugin
 * écrit ce registre ; le chemin autonome du thème n'y écrit jamais, cf.
 * matrice REG-5 des lots B1 à B5).
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\TranslationVariants;

final class TranslationVariantsService
{
    /** Version du DDL héritée du thème (pk_localization_db_version). */
    public const DB_VERSION = '1.0.0';

    /** Option de versionnage du DDL (nom hérité du thème 6.17.x). */
    public const OPTION_DB_VERSION = 'pk_localization_db_version';

    /** Option de porte d'affichage public (nom hérité, créée par maybe_install du thème). */
    public const OPTION_PUBLIC_ENABLED = 'pk_localization_public_enabled';

    /** Statut d'un emplacement créé, sans variante attachée. */
    public const STATUS_PREPARED = 'prepared';

    /** Statut d'un emplacement auquel une annonce variante est attachée. */
    public const STATUS_LINKED = 'linked';

    /** Méta posée sur l'annonce source (langue du texte libre du propriétaire). */
    public const META_FREE_TEXT_LANGUAGE = '_pk_free_text_language';

    /** Locales supportées — héritées du thème (fr, ar, en). */
    public const SUPPORTED_LOCALES = ['fr', 'ar', 'en'];

    /** Type de contenu des annonces (littéral — aucune dépendance de constante thème). */
    public const POST_TYPE = 'properties';

    /* ------------------------------------------------------------------ */
    /* Nommage                                                            */
    /* ------------------------------------------------------------------ */

    public static function variants_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pk_property_variants';
    }

    /**
     * Locales supportées (port fidèle de supported_locales — liste triée
     * stable, utilisée par sanitize_locale).
     *
     * @return array<int, string>
     */
    public static function supported_locales(): array
    {
        return self::SUPPORTED_LOCALES;
    }

    /**
     * Porte d'affichage public (lecture seule — l'option appartient au
     * réglage public du registre, posée à '0' par le maybe_install du thème).
     */
    public static function is_public_enabled(): bool
    {
        return '1' === (string) get_option(self::OPTION_PUBLIC_ENABLED, '0');
    }

    /* ------------------------------------------------------------------ */
    /* Préparation d'un emplacement (port fidèle de prepare_variant)      */
    /* ------------------------------------------------------------------ */

    /**
     * Enregistre un emplacement de variante sans dupliquer de contenu. Une
     * future passerelle de traduction renseignera variant_property_id après
     * création contrôlée. Poser la méta _pk_free_text_language sur l'annonce
     * source fait partie du contrat hérité (la langue du texte libre du
     * propriétaire n'est jamais traitée comme une traduction éditoriale).
     *
     * @param int    $source_property_id        Annonce source (type properties).
     * @param string $locale                    Locale de l'emplacement (fr|ar|en).
     * @param string $original_free_text_locale Langue du texte libre d'origine.
     * @return true|\WP_Error
     */
    public static function prepare_variant($source_property_id, $locale, $original_free_text_locale)
    {
        $source_property_id = absint($source_property_id);
        $locale = self::sanitize_locale($locale);
        $original_free_text_locale = self::sanitize_locale($original_free_text_locale);
        if (!$source_property_id || self::POST_TYPE !== get_post_type($source_property_id)) {
            return new \WP_Error('pk_variant_property', __('Annonce source invalide.', 'partikulier'));
        }
        if (!$locale || !$original_free_text_locale) {
            return new \WP_Error('pk_variant_locale', __('Langue de variante invalide.', 'partikulier'));
        }

        global $wpdb;
        $now = current_time('mysql', true);
        $result = $wpdb->query(
            $wpdb->prepare(
                'INSERT INTO ' . self::variants_table() . ' (source_property_id, locale, original_free_text_locale, status, created_at, updated_at) VALUES (%d, %s, %s, %s, %s, %s) ON DUPLICATE KEY UPDATE original_free_text_locale = VALUES(original_free_text_locale), updated_at = VALUES(updated_at)',
                $source_property_id,
                $locale,
                $original_free_text_locale,
                self::STATUS_PREPARED,
                $now,
                $now
            )
        );
        if (false === $result) {
            return new \WP_Error('pk_variant_storage', __('Impossible de préparer la variante localisée.', 'partikulier'));
        }
        update_post_meta($source_property_id, self::META_FREE_TEXT_LANGUAGE, $original_free_text_locale);
        self::audit('variant_prepared', 'property', $source_property_id, ['locale' => $locale, 'original_free_text_locale' => $original_free_text_locale]);
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Liaison d'une variante (port fidèle de link_variant)               */
    /* ------------------------------------------------------------------ */

    /**
     * Attache une variante Estatik déjà créée au registre métier, sans
     * produire aucun contenu et sans activer l'affichage public.
     *
     * @param int    $source_property_id        Annonce source.
     * @param int    $variant_property_id       Annonce variante (type properties).
     * @param string $locale                    Locale de l'emplacement.
     * @param string $original_free_text_locale Langue du texte libre d'origine.
     * @return true|\WP_Error
     */
    public static function link_variant($source_property_id, $variant_property_id, $locale, $original_free_text_locale)
    {
        $source_property_id  = absint($source_property_id);
        $variant_property_id = absint($variant_property_id);
        $locale              = self::sanitize_locale($locale);

        if (!$source_property_id || !$variant_property_id || !$locale) {
            return new \WP_Error('pk_variant_link', __('Lien de variante invalide.', 'partikulier'));
        }
        if (self::POST_TYPE !== get_post_type($source_property_id) || self::POST_TYPE !== get_post_type($variant_property_id)) {
            return new \WP_Error('pk_variant_link_property', __('Les variantes doivent être des annonces Estatik.', 'partikulier'));
        }

        $prepared = self::prepare_variant($source_property_id, $locale, $original_free_text_locale);
        if (is_wp_error($prepared)) {
            return $prepared;
        }

        global $wpdb;
        $updated = $wpdb->update(
            self::variants_table(),
            [
                'variant_property_id' => $variant_property_id,
                'status'              => self::STATUS_LINKED,
                'updated_at'          => current_time('mysql', true),
            ],
            [
                'source_property_id' => $source_property_id,
                'locale'             => $locale,
            ],
            ['%d', '%s', '%s'],
            ['%d', '%s']
        );

        if (false === $updated) {
            return new \WP_Error('pk_variant_link_storage', __('Impossible de lier la variante localisée.', 'partikulier'));
        }

        self::audit('variant_linked', 'property', $source_property_id, ['locale' => $locale, 'variant_property_id' => $variant_property_id]);
        return true;
    }

    /* ------------------------------------------------------------------ */
    /* Lecture (agrégats de contrôle — la passerelle du lot C s'y branchera) */
    /* ------------------------------------------------------------------ */

    /**
     * Ligne d'emplacement d'une annonce pour une locale (null si absente) —
     * lecture de contrôle pour la passerelle de traduction et les contrats.
     *
     * @return array<string, mixed>|null
     */
    public static function get_variant($source_property_id, $locale): ?array
    {
        global $wpdb;
        $row = $wpdb->get_row(
            $wpdb->prepare(
                'SELECT * FROM ' . self::variants_table() . ' WHERE source_property_id = %d AND locale = %s',
                absint($source_property_id),
                self::sanitize_locale($locale)
            ),
            ARRAY_A
        );
        return is_array($row) ? $row : null;
    }

    /**
     * Nombre d'emplacements enregistrés pour une annonce (toutes locales) —
     * lecture de contrôle (AUCUNE consommation publique : l'affichage public
     * du multilingue reste la charge de Polylang et du lot C).
     */
    public static function count_variants($source_property_id): int
    {
        global $wpdb;
        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::variants_table() . ' WHERE source_property_id = %d',
                absint($source_property_id)
            )
        );
    }

    /* ------------------------------------------------------------------ */
    /* Locale (port fidèle)                                                */
    /* ------------------------------------------------------------------ */

    private static function sanitize_locale($locale): string
    {
        $locale = strtolower(sanitize_key($locale));
        return in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : '';
    }

    /** Consigne une écriture au registre d'audit — chargement déterministe. */
    private static function audit(string $action, string $object_type, ?int $object_id, array $metadata): void
    {
        require_once __DIR__ . '/../../AuditLogger.php';
        (new \Partikulier\Core\AuditLogger())->record($action, $object_type, $object_id, $metadata);
    }
}
