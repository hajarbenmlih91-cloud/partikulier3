<?php
/**
 * Module générateurs SEO des annonces (lot C1, découpe de
 * class-listing-i18n.php — 955 lignes historiques).
 *
 * Meta description calibrée (155 desktop, essentiel dans les 120 premiers
 * caractères) et texte alternatif des photos par langue. Le code est déplacé
 * VERBATIM ; les deux générateurs publics (meta_description, image_alt) sont
 * renommés meta_description_local/image_alt_local — le chemin de repli
 * autonome (REG-5) de la couture du shell.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

trait Partikulier_Listing_I18n_Seo {

        /**
         * Meta description calibree (155 desktop, essentiel dans les 120 premiers).
         *
         * @param array  $v    Donnees normalisees.
         * @param string $lang Langue.
         * @return string
         */
        public static function meta_description_local( $v, $lang ) {
                $v     = self::normalize_values( $v );
                $lex   = self::lex( $lang );
                $type  = self::is_studio( $v ) ? $lex['studio'] : self::type_label( $v['type'], $lang );
                $place = self::place_in( Partikulier_Listing_Preview::place_label( $v ), $lang );
                $sep   = 'ar' === $lang ? '، ' : ', ';

                $core = ( 'louer' === $v['action'] ? $lex['owner_rents'] : $lex['owner_sells'] ) . ' ' . self::lower( $type );
                if ( $v['surface'] ) {
                        $core .= ' ' . sprintf( $lex['of_area'], $v['surface'] );
                }
                $rooms = self::rooms( $v, $lang );
                if ( '' !== $rooms && ! self::is_studio( $v ) ) {
                        $core .= $sep . self::lower( $rooms );
                }
                if ( '' !== $place ) {
                        $core .= ' ' . sprintf( $lex['in_place'], $place );
                }
                if ( $v['price'] ) {
                        // Debut de phrase : la premiere lettre doit etre capitalisee
                        // (sans effet en arabe, qui ignore la casse).
                        $core .= '. ' . self::ucfirst_safe( sprintf( $lex['price_at'], self::number( $v['price'] ) ) );
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

                return self::squash( $meta );
        }

        /**
         * Texte alternatif d'une photo, dans la langue voulue.
         *
         * @param array  $v     Donnees normalisees.
         * @param string $lang  Langue.
         * @param int    $index Rang de la photo.
         * @return string
         */
        public static function image_alt_local( $v, $lang, $index = 0 ) {
                $lex   = self::lex( $lang );
                $type  = self::is_studio( $v ) ? $lex['studio'] : self::type_label( $v['type'], $lang );
                $place = self::place_in( Partikulier_Listing_Preview::place_label( $v ), $lang );
                $sep   = 'ar' === $lang ? '، ' : ', ';

                if ( 0 === $index ) {
                        $alt = $type;
                        if ( $v['surface'] ) {
                                $alt .= ' ' . sprintf( $lex['of_area'], $v['surface'] );
                        }
                        $rooms = self::rooms( $v, $lang );
                        if ( '' !== $rooms && ! self::is_studio( $v ) ) {
                                $alt .= $sep . self::lower( $rooms );
                        }
                        $alt .= ' ' . ( 'louer' === $v['action'] ? $lex['for_rent'] : $lex['for_sale'] );
                        if ( '' !== $place ) {
                                $alt .= ' ' . sprintf( $lex['in_place'], $place );
                        }
                        $alt .= ' — ' . sprintf( $lex['photo_of'], $lex['owner'] );

                        return self::trim_to( self::squash( $alt ), 125 );
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
                        $features[] = isset( $lex['sun'][ $v['sunshine'] ] ) ? $lex['sun'][ $v['sunshine'] ] : self::lower( $v['sunshine'] );
                }
                if ( '' !== $v['floor'] ) {
                        $features[] = 'RDC' === $v['floor'] ? $lex['at_ground'] : self::lower( $v['floor'] );
                }
                if ( $features ) {
                        $alt .= $sep . $features[ ( $index - 1 ) % count( $features ) ];
                }
                $alt .= ' ' . sprintf( $lex['photo_n'], $index + 1 );

                return self::trim_to( self::squash( $alt ), 125 );
        }
}

