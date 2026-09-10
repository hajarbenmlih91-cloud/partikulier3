<?php
/**
 * Module : redaction des annonces en francais, anglais et arabe.
 *
 * Le texte d'une annonce n'est pas de la prose libre : il est compose a
 * partir de champs (type, surface, pieces, ville, prix, options). On peut
 * donc le REDIGER dans chaque langue au lieu de le traduire — le resultat
 * est naturel, gratuit et instantane.
 *
 * Chaque langue produit son propre titre, sa description et sa meta
 * description : trois pages distinctes, chacune avec son SEO.
 *
 * Lot C1 (decoupe, CDC v1.2 annexe C tableau 11) : le monolithe historique
 * de 955 lignes est decoupe en modules d'au plus 300 lignes : ce fichier est
 * le SHELL de composition (classe Partikulier_Listing_I18n inchangee, meme
 * API publique), les methodes sont deplacees VERBATIM dans cinq traits
 * charges avant lui :
 *   · Partikulier_Listing_I18n_Lexicon  — langues + lexique trilingue
 *   · Partikulier_Listing_I18n_Places   — vocabulaire (lieux arabes, types, pieces, etages)
 *   · Partikulier_Listing_I18n_Text     — normalisation + titre + description
 *   · Partikulier_Listing_I18n_Seo      — meta description + alt photo
 *   · Partikulier_Listing_I18n_Post     — API post-dependante legacy
 *
 * Lot C1 (absorption, CDC v1.2 §3.2 I18N-1) : la couche CONTENU de la
 * redaction multilingue (lexique, generateurs SEO) est propriete du plugin
 * partikulier-core 2.7+ — service \Partikulier\Core\Domain\I18n\I18nContentService
 * (bibliotheque PURE : zero hook, zero table, zero ecriture). Les neuf
 * methodes publiques deleguent quand la classe existe ; sans le plugin, le
 * chemin autonome historique 6.18.x est conserve a l'identique (degradation
 * gracieuse REG-5 — les deux chemins produisent le meme texte a partir du
 * meme lexique, preuve par contrat du lot C1). La consolidation des
 * catalogues accompagne ce lot : doublons partikulier-ar.mo /
 * partikulier-en_US.mo supprimes (copies byte pour byte des canoniques
 * <locale>.mo — le JIT de WordPress charge <locale>.mo pour un theme dont
 * languages/ est hors WP_LANG_DIR), ar.mo et en_US.mo recompiles sous forme
 * canonique (hash_addr de fin de table — lisible par les DEUX lecteurs
 * gettext de WordPress).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Listing_I18n {

        /* Lot C1 (decoupe) : composition des modules — les traits sont
         * charges AVANT ce shell (liste des modules de functions.php) : la
         * classe publique garde ses methodes et signatures. Les neuf
         * methodes publiques historiques sont renommees *_local dans les
         * traits (chemins de repli REG-5) et re-exposees ici par la couture
         * C1. Les methodes privees conservent leur nom : les appels croises
         * entre traits se resolvent au niveau de la classe composee. */
        use Partikulier_Listing_I18n_Lexicon;
        use Partikulier_Listing_I18n_Places;
        use Partikulier_Listing_I18n_Text;
        use Partikulier_Listing_I18n_Seo;
        use Partikulier_Listing_I18n_Post;

        /* ------------------------------------------------------------------
         * Couture du lot C1 : delegation a la couche contenu du plugin.
         * --------------------------------------------------------------- */

        /**
         * Langues prises en charge.
         *
         * @return string[]
         */
        public static function languages() {
                if ( null !== self::core_i18n() ) {
                        // Lot C1 : le lexique vit cote plugin (I18nContentService).
                        return call_user_func( array( self::core_i18n(), 'languages' ) );
                }
                return self::languages_local();
        }

        /**
         * Titre de l'annonce dans une langue donnee.
         *
         * @param array  $v    Donnees normalisees.
         * @param string $lang Langue.
         * @return string
         */
        public static function title( $v, $lang ) {
                if ( null !== self::core_i18n() ) {
                        return call_user_func( array( self::core_i18n(), 'title' ), $v, $lang );
                }
                return self::title_local( $v, $lang );
        }

        /**
         * Description complete dans une langue donnee.
         *
         * @param array  $v    Donnees normalisees.
         * @param string $lang Langue.
         * @return string
         */
        public static function description( $v, $lang ) {
                if ( null !== self::core_i18n() ) {
                        return call_user_func( array( self::core_i18n(), 'description' ), $v, $lang );
                }
                return self::description_local( $v, $lang );
        }

        /**
         * Meta description calibree (155 desktop, essentiel dans les 120 premiers).
         *
         * @param array  $v    Donnees normalisees.
         * @param string $lang Langue.
         * @return string
         */
        public static function meta_description( $v, $lang ) {
                if ( null !== self::core_i18n() ) {
                        return call_user_func( array( self::core_i18n(), 'meta_description' ), $v, $lang );
                }
                return self::meta_description_local( $v, $lang );
        }

        /**
         * Texte alternatif d'une photo, dans la langue voulue.
         *
         * @param array  $v     Donnees normalisees.
         * @param string $lang  Langue.
         * @param int    $index Rang de la photo.
         * @return string
         */
        public static function image_alt( $v, $lang, $index = 0 ) {
                if ( null !== self::core_i18n() ) {
                        return call_user_func( array( self::core_i18n(), 'image_alt' ), $v, $lang, $index );
                }
                return self::image_alt_local( $v, $lang, $index );
        }

        /**
         * Traduit un libelle de type dans la langue demandee.
         *
         * @param string $type Type source.
         * @param string $lang Langue cible.
         * @return string
         */
        public static function localized_type( $type, $lang = '' ) {
                if ( null !== self::core_i18n() ) {
                        return call_user_func( array( self::core_i18n(), 'localized_type' ), $type, $lang );
                }
                return self::localized_type_local( $type, $lang );
        }

        /**
         * Traduit un lieu libre en conservant les quartiers inconnus.
         *
         * @param string $place Lieu source.
         * @param string $lang  Langue cible.
         * @return string
         */
        public static function localized_place( $place, $lang = '' ) {
                if ( null !== self::core_i18n() ) {
                        return call_user_func( array( self::core_i18n(), 'localized_place' ), $place, $lang );
                }
                return self::localized_place_local( $place, $lang );
        }

        /**
         * Construit un titre localise pour une annonce legacy sans traduction liee.
         * Un titre arabe manuel existant est toujours prioritaire.
         *
         * @param WP_Post|int $post Annonce.
         * @param string      $lang Langue cible.
         * @return string
         */
        public static function title_from_post( $post, $lang = '' ) {
                if ( null !== self::core_i18n() ) {
                        return call_user_func( array( self::core_i18n(), 'title_from_post' ), $post, $lang );
                }
                return self::title_from_post_local( $post, $lang );
        }

        /**
         * Retourne la composition lisible des chambres/salons pour une carte.
         * Les annonces anciennes peuvent ne pas avoir les labels maison : on
         * reprend alors les metas Estatik standard copiees par le rattrapage.
         *
         * @param WP_Post|int $post Annonce.
         * @param string      $lang Langue cible.
         * @return string
         */
        public static function rooms_label_from_post( $post, $lang = '' ) {
                if ( null !== self::core_i18n() ) {
                        return call_user_func( array( self::core_i18n(), 'rooms_label_from_post' ), $post, $lang );
                }
                return self::rooms_label_from_post_local( $post, $lang );
        }

        /**
         * Couture du lot C1 : classe du service plugin quand il existe.
         *
         * @return class-string|null
         */
        private static function core_i18n() {
                return class_exists( '\Partikulier\Core\Domain\I18n\I18nContentService' )
                        ? '\Partikulier\Core\Domain\I18n\I18nContentService'
                        : null;
        }

        /**
         * Majuscule initiale sure (sans effet sur l'arabe).
         *
         * @param string $text Texte.
         * @return string
         */
        private static function ucfirst_safe( $text ) {
                if ( '' === $text || preg_match( '/^\p{Arabic}/u', $text ) ) {
                        return $text;
                }
                $first = function_exists( 'mb_strtoupper' ) ? mb_strtoupper( mb_substr( $text, 0, 1 ) ) : strtoupper( substr( $text, 0, 1 ) );

                return $first . mb_substr( $text, 1 );
        }

        /**
         * Minuscule sure : l'arabe n'a pas de casse, on ne le touche pas.
         *
         * @param string $text Texte.
         * @return string
         */
        private static function lower( $text ) {
                if ( preg_match( '/\p{Arabic}/u', $text ) ) {
                        return $text;
                }

                return function_exists( 'mb_strtolower' ) ? mb_strtolower( $text ) : strtolower( $text );
        }

        /**
         * Normalise les espaces.
         *
         * @param string $text Texte.
         * @return string
         */
        private static function squash( $text ) {
                return trim( preg_replace( '/\s+/u', ' ', $text ) );
        }

        /**
         * Coupe au dernier mot entier.
         *
         * @param string $text  Texte.
         * @param int    $limit Longueur maximale.
         * @return string
         */
        private static function trim_to( $text, $limit ) {
                if ( mb_strlen( $text ) <= $limit ) {
                        return $text;
                }
                $text = mb_substr( $text, 0, $limit );
                $cut  = mb_strrpos( $text, ' ' );
                if ( false !== $cut ) {
                        $text = mb_substr( $text, 0, $cut );
                }

                return rtrim( $text, " ,—-،" );
        }
}
