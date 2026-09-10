<?php
/**
 * Module générateurs de texte des annonces (lot C1, découpe de
 * class-listing-i18n.php — 955 lignes historiques).
 *
 * Normalisation des données partielles (annonces anciennes sans champs
 * optionnels), titre et description complète par langue. Le code est déplacé
 * VERBATIM ; les deux générateurs publics (title, description) sont renommés
 * title_local/description_local — le chemin de repli autonome (REG-5) appelé
 * par la couture du shell quand le plugin partikulier-core 2.7+ est absent.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

trait Partikulier_Listing_I18n_Text {

        /**
         * Garantit les clés consommées par les générateurs, y compris pour les
         * anciennes annonces dont certains champs optionnels n'existent pas.
         *
         * @param array $values Données partiellement normalisées.
         * @return array
         */
        private static function normalize_values( $values ) {
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
         * Titre de l'annonce dans une langue donnee.
         *
         * @param array  $v    Donnees normalisees.
         * @param string $lang Langue.
         * @return string
         */
        public static function title_local( $v, $lang ) {
                $v     = self::normalize_values( $v );
                $lex   = self::lex( $lang );
                $type  = self::is_studio( $v ) ? $lex['studio'] : self::type_label( $v['type'], $lang );
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

                $place = self::place_in( Partikulier_Listing_Preview::place_label( $v ), $lang );
                if ( '' !== $place ) {
                        $title .= ' ' . sprintf( $lex['in_place'], $place );
                }

                return self::squash( $title );
        }

        /**
         * Description complete dans une langue donnee.
         *
         * @param array  $v    Donnees normalisees.
         * @param string $lang Langue.
         * @return string
         */
        public static function description_local( $v, $lang ) {
                $v     = self::normalize_values( $v );
                $lex   = self::lex( $lang );
                $type  = self::is_studio( $v ) ? $lex['studio'] : self::type_label( $v['type'], $lang );
                $place = self::place_in( Partikulier_Listing_Preview::place_label( $v ), $lang );

                // 1. Accroche : « Propriétaire vend studio de 72 m² à Hay Riad, Rabat. »
                $opener = '';
                if ( '' !== $place ) {
                        $opener = ( 'louer' === $v['action'] ? $lex['owner_rents'] : $lex['owner_sells'] )
                                . ' ' . self::lower( $type )
                                . ( $v['surface'] ? ' ' . sprintf( $lex['of_area'], $v['surface'] ) : '' )
                                . ' ' . sprintf( $lex['in_place'], $place ) . '.';
                }

                // 2. Phrase factuelle.
                $sentence = $type;
                $rooms    = array();
                if ( self::is_studio( $v ) ) {
                        $rooms[] = $lex['main_room'];
                } else {
                        $label = self::rooms( $v, $lang );
                        if ( '' !== $label ) {
                                $rooms[] = self::lower( $label );
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
                        $details[] = sprintf( $lex['price_at'], self::number( $v['price'] ) );
                }
                if ( '' !== $v['floor'] ) {
                        $floor     = 'RDC' === $v['floor'] ? $lex['ground_floor'] : sprintf( $lex['at_floor'], self::floor_label( $v['floor'], $lang ) );
                        $details[] = sprintf( $lex['located'], $floor );
                }
                $details[] = 'Oui' === $v['garage'] ? $lex['with_garage'] : $lex['no_garage'];
                $details[] = 'Oui' === $v['elevator'] ? $lex['with_lift'] : $lex['no_lift'];
                if ( 'Oui' === $v['vis_a_vis'] ) {
                        $details[] = $lex['no_facing'];
                }
                if ( '' !== $v['sunshine'] ) {
                        $details[] = isset( $lex['sun'][ $v['sunshine'] ] ) ? $lex['sun'][ $v['sunshine'] ] : self::lower( $v['sunshine'] );
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

                return self::squash( $text );
        }
}

