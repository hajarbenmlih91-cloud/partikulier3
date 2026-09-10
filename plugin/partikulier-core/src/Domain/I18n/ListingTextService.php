<?php
/**
 * Générateurs de texte des annonces (lot C1 — I18N-1).
 *
 * Port fidèle des générateurs publics de Partikulier_Listing_I18n (thème
 * 6.18.x) : titre (type + surface + vis-à-vis + terrasse + action + lieu) et
 * description complète (accroche « Propriétaire vend… », phrase factuelle,
 * détails, clôture particulier-à-particulier) dans les trois langues. Code
 * déplacé VERBATIM, recâblé sur les classes du domaine (lexique, vocabulaire,
 * utilitaires) — aucune dépendance au thème.
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\I18n;

final class ListingTextService
{

        /**
         * Titre de l'annonce dans une langue donnee.
         *
         * @param array  $v    Donnees normalisees.
         * @param string $lang Langue.
         * @return string
         */
        public static function title( $v, $lang ) {
                $v     = ListingTextUtils::normalize_values( $v );
                $lex   = ListingLexicon::lex( $lang );
                $type  = ListingVocabulary::is_studio( $v ) ? $lex['studio'] : ListingVocabulary::type_label( $v['type'], $lang );
                $title = $type;

                if ( $v['surface'] ) {
                        $title .= ' ' . sprintf( $lex['of_area'], $v['surface'] );
                }
                if ( 'Oui' === $v['vis_a_vis'] ) {
                        $title .= ' ' . $lex['no_facing'];
                }
                if ( 'Oui' === $v['terrace'] ) {
                        $title .= ' ' . ( $v['terrace_surface']
                                ? sprintf( $lex['with_terrace_area'], $v['terrace_surface'] )
                                : $lex['terrace'] );
                }

                $title .= ' ' . ( 'louer' === $v['action'] ? $lex['for_rent'] : $lex['for_sale'] );

                $place = ListingVocabulary::place_in( ListingTextUtils::place_label( $v ), $lang );
                if ( '' !== $place ) {
                        $title .= ' ' . sprintf( $lex['in_place'], $place );
                }

                return ListingTextUtils::squash( $title );
        }

        /**
         * Description complete dans une langue donnee.
         *
         * @param array  $v    Donnees normalisees.
         * @param string $lang Langue.
         * @return string
         */
        public static function description( $v, $lang ) {
                $v     = ListingTextUtils::normalize_values( $v );
                $lex   = ListingLexicon::lex( $lang );
                $type  = ListingVocabulary::is_studio( $v ) ? $lex['studio'] : ListingVocabulary::type_label( $v['type'], $lang );
                $place = ListingVocabulary::place_in( ListingTextUtils::place_label( $v ), $lang );

                // 1. Accroche : « Propriétaire vend studio de 72 m² à Hay Riad, Rabat. »
                $opener = '';
                if ( '' !== $place ) {
                        $opener = ( 'louer' === $v['action'] ? $lex['owner_rents'] : $lex['owner_sells'] )
                                . ' ' . ListingTextUtils::lower( $type )
                                . ( $v['surface'] ? ' ' . sprintf( $lex['of_area'], $v['surface'] ) : '' )
                                . ' ' . sprintf( $lex['in_place'], $place ) . '.';
                }

                // 2. Phrase factuelle.
                $sentence = $type;
                $rooms    = array();
                if ( ListingVocabulary::is_studio( $v ) ) {
                        $rooms[] = $lex['main_room'];
                } else {
                        $label = ListingVocabulary::rooms( $v, $lang );
                        if ( '' !== $label ) {
                                $rooms[] = ListingTextUtils::lower( $label );
                        }
                }
                if ( '' !== (string) $v['bathrooms'] ) {
                        $count   = '3+' === (string) $v['bathrooms'] ? 3 : (int) $v['bathrooms'];
                        $rooms[] = 1 === $count ? sprintf( $lex['bathroom'], $count ) : sprintf( $lex['bathrooms'], $count );
                }
                if ( $rooms ) {
                        $sentence .= ' ' . $lex['with'] . ' ' . implode( ' ' . $lex['and'] . ' ', $rooms );
                }
                if ( 'Oui' === $v['terrace'] ) {
                        // « avec terrasse de 15 m² » : la preposition evite « ...2 salles de bains terrasse ».
                        $sentence .= ' ' . ( $v['terrace_surface']
                                ? sprintf( $lex['with_terrace_area'], $v['terrace_surface'] )
                                : $lex['terrace'] );
                }
                $sentence .= ' ' . ( 'louer' === $v['action'] ? $lex['for_rent'] : $lex['for_sale'] );
                if ( '' !== $place ) {
                        $sentence .= ' ' . sprintf( $lex['in_place'], $place );
                }

                // 3. Details.
                $details = array();
                if ( $v['surface'] ) {
                        $details[] = sprintf( $lex['area_of'], $v['surface'] );
                }
                if ( $v['price'] ) {
                        $details[] = sprintf( $lex['price_at'], ListingVocabulary::number( $v['price'] ) );
                }
                if ( '' !== $v['floor'] ) {
                        $floor     = 'RDC' === $v['floor'] ? $lex['ground_floor'] : sprintf( $lex['at_floor'], ListingVocabulary::floor_label( $v['floor'], $lang ) );
                        $details[] = sprintf( $lex['located'], $floor );
                }
                $details[] = 'Oui' === $v['garage'] ? $lex['with_garage'] : $lex['no_garage'];
                $details[] = 'Oui' === $v['elevator'] ? $lex['with_lift'] : $lex['no_lift'];
                if ( 'Oui' === $v['vis_a_vis'] ) {
                        $details[] = $lex['no_facing'];
                }
                if ( '' !== $v['sunshine'] ) {
                        $details[] = isset( $lex['sun'][ $v['sunshine'] ] ) ? $lex['sun'][ $v['sunshine'] ] : ListingTextUtils::lower( $v['sunshine'] );
                }

                $text = ( '' !== $opener ? $opener . ' ' : '' ) . $sentence;
                if ( $details ) {
                        $text .= '، ' === '' ? '' : ( 'ar' === $lang ? '، ' : ', ' );
                        $text .= implode( 'ar' === $lang ? '، ' : ', ', $details );
                }
                $text .= '.';

                // 4. Cloture « particulier a particulier ».
                if ( '' !== $place ) {
                        $text .= ' ' . sprintf(
                                $lex['p2p'],
                                'louer' === $v['action'] ? $lex['rent_noun'] : $lex['sale_noun'],
                                $place,
                                $lex['the_owner']
                        );
                }

                return ListingTextUtils::squash( $text );
        }
}

