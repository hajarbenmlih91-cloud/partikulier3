<?php
/**
 * Service de contenu multilingue des annonces (lot C1 — I18N-1, CDC v1.2
 * §3.2 : réduction des couches de traduction à un service unique côté
 * plugin).
 *
 * Facade de la couche CONTENU de la rédaction multilingue : les neuf
 * méthodes publiques de l'API historique Partikulier_Listing_I18n (thème
 * 6.18.x), déléguées aux classes du domaine (lexique, vocabulaire,
 * générateurs de texte, générateurs SEO, API post-dépendante). Le thème
 * 6.18.8+ délègue ici via sa couture class_exists ; sans le plugin, il
 * conserve son chemin autonome — les deux chemins produisent le même texte
 * à partir du même lexique (matrice REG-5, parité prouvée par le contrat
 * du lot C1 : 30 annonces × 3 langues × 4 générateurs, diff nul exigé).
 *
 * Bibliothèque PURE : zéro hook, zéro table, zéro écriture, aucun cron,
 * aucune route — chargée inconditionnellement (pattern lots B, coût de
 * bootstrap mesuré par REG-2). Fondation du lot C : l'unification
 * progressive des couches restantes (C2 dictionnaire interne, C3 Polylang,
 * C4 complétude) s'appuiera sur ce service.
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\I18n;

final class I18nContentService
{
    /**
     * Langues prises en charge.
     *
     * @return string[]
     */
    public static function languages()
    {
        return ListingLexicon::languages();
    }

    /**
     * Titre de l'annonce dans une langue donnée.
     *
     * @param array  $v    Données normalisées.
     * @param string $lang Langue.
     * @return string
     */
    public static function title( $v, $lang )
    {
        return ListingTextService::title( $v, $lang );
    }

    /**
     * Description complète dans une langue donnée.
     *
     * @param array  $v    Données normalisées.
     * @param string $lang Langue.
     * @return string
     */
    public static function description( $v, $lang )
    {
        return ListingTextService::description( $v, $lang );
    }

    /**
     * Meta description calibrée (155 desktop, essentiel dans les 120 premiers).
     *
     * @param array  $v    Données normalisées.
     * @param string $lang Langue.
     * @return string
     */
    public static function meta_description( $v, $lang )
    {
        return ListingSeoTextService::meta_description( $v, $lang );
    }

    /**
     * Texte alternatif d'une photo, dans la langue voulue.
     *
     * @param array  $v     Données normalisées.
     * @param string $lang  Langue.
     * @param int    $index Rang de la photo.
     * @return string
     */
    public static function image_alt( $v, $lang, $index = 0 )
    {
        return ListingSeoTextService::image_alt( $v, $lang, $index );
    }

    /**
     * Traduit un libellé de type dans la langue demandée.
     *
     * @param string $type Type source.
     * @param string $lang Langue cible.
     * @return string
     */
    public static function localized_type( $type, $lang = '' )
    {
        return ListingPostTextService::localized_type( $type, $lang );
    }

    /**
     * Traduit un lieu libre en conservant les quartiers inconnus.
     *
     * @param string $place Lieu source.
     * @param string $lang  Langue cible.
     * @return string
     */
    public static function localized_place( $place, $lang = '' )
    {
        return ListingPostTextService::localized_place( $place, $lang );
    }

    /**
     * Construit un titre localisé pour une annonce legacy sans traduction
     * liée. Un titre arabe manuel existant est toujours prioritaire.
     *
     * @param \WP_Post|int $post Annonce.
     * @param string       $lang Langue cible.
     * @return string
     */
    public static function title_from_post( $post, $lang = '' )
    {
        return ListingPostTextService::title_from_post( $post, $lang );
    }

    /**
     * Retourne la composition lisible des chambres/salons pour une carte.
     *
     * @param \WP_Post|int $post Annonce.
     * @param string       $lang Langue cible.
     * @return string
     */
    public static function rooms_label_from_post( $post, $lang = '' )
    {
        return ListingPostTextService::rooms_label_from_post( $post, $lang );
    }
}

