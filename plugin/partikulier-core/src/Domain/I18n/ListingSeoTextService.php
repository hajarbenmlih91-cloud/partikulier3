<?php
/**
 * Générateurs SEO des annonces (lot C1 — I18N-1).
 *
 * Port fidèle des générateurs SEO publics de Partikulier_Listing_I18n
 * (thème 6.18.x) : meta description calibrée (155 desktop, essentiel dans
 * les 120 premiers caractères, priorité aux arguments de conversion) et
 * texte alternatif des photos par langue (première photo factuelle, angles
 * variés ensuite, plafond 125). Code déplacé VERBATIM, recâblé sur les
 * classes du domaine — aucune dépendance au thème.
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\I18n;

final class ListingSeoTextService
{

        /**
         * Meta description calibree (155 desktop, essentiel dans les 120 premiers).
         *
         * @param array  $v    Donnees normalisees.
         * @param string $lang Langue.
         * @return string
         */
        public static function meta_description( $v, $lang ) {
                $v     = ListingTextUtils::normalize_values( $v );
                $lex   = ListingLexicon::lex( $lang );
                $type  = ListingVocabulary::is_studio( $v ) ? $lex['studio'] : ListingVocabulary::type_label( $v['type'], $lang );
                $place = ListingVocabulary::place_in( ListingTextUtils::place_label( $v ), $lang );
                $sep   = 'ar' === $lang ? '، ' : ', ';

                $core = ( 'louer' === $v['action'] ? $lex['owner_rents'] : $lex['owner_sells'] ) . ' ' . ListingTextUtils::lower( $type );
                if ( $v['surface'] ) {
                        $core .= ' ' . sprintf( $lex['of_area'], $v['surface'] );
                }
                $rooms = ListingVocabulary::rooms( $v, $lang );
                if ( '' !== $rooms && ! ListingVocabulary::is_studio( $v ) ) {
                        $core .= $sep . ListingTextUtils::lower( $rooms );
                }
                if ( '' !== $place ) {
                        $core .= ' ' . sprintf( $lex['in_place'], $place );
                }
                if ( $v['price'] ) {
                        // Debut de phrase : la premiere lettre doit etre capitalisee
                        // (sans effet en arabe, qui ignore la casse).
                        $core .= '. ' . ListingTextUtils::ucfirst_safe( sprintf( $lex['price_at'], ListingVocabulary::number( $v['price'] ) ) );
                }
                $core .= '.';

                $extras = array();
                if ( 'Oui' === $v['terrace'] ) {
                        $extras[] = $lex['terrace_short'];
                }
                if ( 'Oui' === $v['vis_a_vis'] ) {
                        $extras[] = $lex['no_facing'];
                }
                if ( 'Oui' === $v['garage'] ) {
                        $extras[] = $lex['garage'];
                }
                if ( 'Oui' === $v['elevator'] ) {
                        $extras[] = $lex['lift'];
                }

                $meta  = $core;
                $limit = 155;

                if ( $extras ) {
                        $candidate = $meta . ' ' . implode( $sep, $extras ) . '.';
                        if ( mb_strlen( $candidate ) <= $limit ) {
                                $meta = $candidate;
                        }
                }
                if ( mb_strlen( $meta . ' ' . $lex['contact'] ) <= $limit ) {
                        $meta .= ' ' . $lex['contact'];
                } elseif ( mb_strlen( $meta . ' ' . $lex['no_commission'] ) <= $limit ) {
                        $meta .= ' ' . $lex['no_commission'];
                }

                if ( mb_strlen( $meta ) > $limit + 3 ) {
                        $meta = mb_substr( $meta, 0, $limit );
                        $cut  = mb_strrpos( $meta, ' ' );
                        if ( false !== $cut ) {
                                $meta = mb_substr( $meta, 0, $cut );
                        }
                        $meta = rtrim( $meta, " ,.;:،" ) . '…';
                }

                return ListingTextUtils::squash( $meta );
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
                $lex   = ListingLexicon::lex( $lang );
                $type  = ListingVocabulary::is_studio( $v ) ? $lex['studio'] : ListingVocabulary::type_label( $v['type'], $lang );
                $place = ListingVocabulary::place_in( ListingTextUtils::place_label( $v ), $lang );
                $sep   = 'ar' === $lang ? '، ' : ', ';

                if ( 0 === $index ) {
                        $alt = $type;
                        if ( $v['surface'] ) {
                                $alt .= ' ' . sprintf( $lex['of_area'], $v['surface'] );
                        }
                        $rooms = ListingVocabulary::rooms( $v, $lang );
                        if ( '' !== $rooms && ! ListingVocabulary::is_studio( $v ) ) {
                                $alt .= $sep . ListingTextUtils::lower( $rooms );
                        }
                        $alt .= ' ' . ( 'louer' === $v['action'] ? $lex['for_rent'] : $lex['for_sale'] );
                        if ( '' !== $place ) {
                                $alt .= ' ' . sprintf( $lex['in_place'], $place );
                        }
                        $alt .= ' — ' . sprintf( $lex['photo_of'], $lex['owner'] );

                        return ListingTextUtils::trim_to( ListingTextUtils::squash( $alt ), 125 );
                }

                $angles = $lex['angles'];
                $alt    = $type;
                if ( '' !== $place ) {
                        $alt .= ' ' . sprintf( $lex['in_place'], $place );
                }
                $alt .= ' — ' . $angles[ ( $index - 1 ) % count( $angles ) ];

                $features = array();
                if ( 'Oui' === $v['terrace'] ) {
                        $features[] = $v['terrace_surface'] ? sprintf( $lex['terrace_area'], $v['terrace_surface'] ) : $lex['terrace'];
                }
                if ( 'Oui' === $v['vis_a_vis'] ) {
                        $features[] = $lex['no_facing'];
                }
                if ( '' !== $v['sunshine'] ) {
                        $features[] = isset( $lex['sun'][ $v['sunshine'] ] ) ? $lex['sun'][ $v['sunshine'] ] : ListingTextUtils::lower( $v['sunshine'] );
                }
                if ( '' !== $v['floor'] ) {
                        $features[] = 'RDC' === $v['floor'] ? $lex['at_ground'] : ListingTextUtils::lower( $v['floor'] );
                }
                if ( $features ) {
                        $alt .= $sep . $features[ ( $index - 1 ) % count( $features ) ];
                }
                $alt .= ' ' . sprintf( $lex['photo_n'], $index + 1 );

                return ListingTextUtils::trim_to( ListingTextUtils::squash( $alt ), 125 );
        }
}

