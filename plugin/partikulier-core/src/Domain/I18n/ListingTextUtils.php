<?php
/**
 * Utilitaires de texte de la rédaction multilingue (lot C1 — I18N-1).
 *
 * Port fidèle des utilitaires privés de Partikulier_Listing_I18n (thème
 * 6.18.x) — casse sûre (l'arabe n'a pas de casse : minuscule/majuscule ne
 * le touchent jamais), normalisation des espaces, coupe au dernier mot
 * entier, normalisation des données partielles des annonces anciennes —
 * et port LITTÉRAL de Partikulier_Listing_Preview::place_label (libellé du
 * lieu « Quartier, Ville ») : le plugin ne dépend d'aucune classe du thème
 * (pattern B6). Code déplacé VERBATIM, aucune dépendance au thème.
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\I18n;

final class ListingTextUtils
{

    /**
     * Libellé lisible du lieu : « Quartier, Ville » (port littéral de
     * Partikulier_Listing_Preview::place_label — aucune dépendance thème).
     *
     * @param array $v Données normalisées.
     * @return string
     */
    public static function place_label( $v ) {
                $parts = array_filter( array( $v['district'], $v['city'] ) );

                return implode( ', ', $parts );
        }

        /**
         * Garantit les clés consommées par les générateurs, y compris pour les
         * anciennes annonces dont certains champs optionnels n'existent pas.
         *
         * @param array $values Données partiellement normalisées.
         * @return array
         */
        public static function normalize_values( $values ) {
                $defaults = array(
                        'action'          => '',
                        'role'            => '',
                        'type'            => '',
                        'city'            => '',
                        'district'        => '',
                        'surface'         => 0,
                        'price'           => 0,
                        'bedrooms'        => '',
                        'living_rooms'    => '',
                        'bathrooms'       => '',
                        'floor'           => '',
                        'garage'          => 'Non',
                        'elevator'         => 'Non',
                        'vis_a_vis'       => 'Non',
                        'terrace'         => 'Non',
                        'terrace_surface' => 0,
                        'sunshine'        => '',
                );

                return wp_parse_args( is_array( $values ) ? $values : array(), $defaults );
        }

        /**
         * Majuscule initiale sure (sans effet sur l'arabe).
         *
         * @param string $text Texte.
         * @return string
         */
        public static function ucfirst_safe( $text ) {
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
        public static function lower( $text ) {
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
        public static function squash( $text ) {
                return trim( preg_replace( '/\s+/u', ' ', $text ) );
        }

        /**
         * Coupe au dernier mot entier.
         *
         * @param string $text  Texte.
         * @param int    $limit Longueur maximale.
         * @return string
         */
        public static function trim_to( $text, $limit ) {
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

