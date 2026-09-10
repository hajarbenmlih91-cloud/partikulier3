<?php
/**
 * Module vocabulaire de la rédaction multilingue (lot C1, découpe de
 * class-listing-i18n.php — 955 lignes historiques).
 *
 * Traduction des vocabulaires contrôlés : noms de villes et quartiers en
 * arabe (un nom translittéré casse la lecture et le référencement local),
 * libellés de types de bien, étages, couchages (chambres/salons) et nombres
 * formatés. Le contenu des entrées est déplacé VERBATIM.
 *
 * Lot C1 (extraction) : chemin de repli autonome du thème (REG-5) — le
 * service actif vit côté plugin (\Partikulier\Core\Domain\I18n\I18nContentService).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

trait Partikulier_Listing_I18n_Places {

        /**
         * Noms de villes en arabe. Un nom de ville translittere en caracteres
         * latins au milieu d'une phrase arabe casse la lecture et le referencement
         * local : « في أكادير » est la forme que tapent les internautes marocains.
         *
         * @return array
         */
        private static function arabic_places() {
                return array(
                        'casablanca' => 'الدار البيضاء',
                        'rabat'      => 'الرباط',
                        'marrakech'  => 'مراكش',
                        'tanger'     => 'طنجة',
                        'fes'        => 'فاس',
                        'fès'        => 'فاس',
                        'agadir'     => 'أكادير',
                        'saidia'     => 'السعيدية',
                        'saïdia'     => 'السعيدية',
                        'meknes'     => 'مكناس',
                        'meknès'     => 'مكناس',
                        'oujda'      => 'وجدة',
                        'kenitra'    => 'القنيطرة',
                        'kénitra'    => 'القنيطرة',
                        'tetouan'    => 'تطوان',
                        'tétouan'    => 'تطوان',
                        'sale'       => 'سلا',
                        'salé'       => 'سلا',
                        'mohammedia' => 'المحمدية',
                        'el jadida'  => 'الجديدة',
                        'essaouira'  => 'الصويرة',
                        'beni mellal' => 'بني ملال',
                        'nador'      => 'الناظور',
                        'ifrane'     => 'إفران',
                        'ouarzazate' => 'ورزازات',
                        'safi'       => 'آسفي',
                        'dakhla'     => 'الداخلة',
                        'laayoune'   => 'العيون',
                        'laâyoune'   => 'العيون',
                        'berrechid'  => 'برشيد',
                        'settat'     => 'سطات',
                        'khouribga'  => 'خريبكة',
                        'taza'       => 'تازة',
                        'larache'    => 'العرائش',
                        'al hoceima' => 'الحسيمة',
                        'chefchaouen' => 'شفشاون',
                        'bouznika'   => 'بوزنيقة',
                        'skhirat'    => 'الصخيرات',
                        'temara'     => 'تمارة',
                        'témara'     => 'تمارة',
                        'berkane'    => 'بركان',
                        /* 6.17.30 — S9.8 : quartiers courants du référentiel marocain.
                         * Sans ces entrées, les quartiers référencés en français
                         * restaient latins dans les titres/données AR des fiches
                         * (Targa, Hivernage, Médina, Agdal…) et dans les futurs
                         * dépôts réels. */
                        'targa'       => 'تارغة',
                        'hivernage'   => 'هيفيرناژ',
                        'medina'      => 'المدينة القديمة',
                        'médina'      => 'المدينة القديمة',
                        'gueliz'      => 'جيليز',
                        'guéliz'      => 'جيليز',
                        'palmeraie'   => 'النخيل',
                        'agdal'       => 'أكدال',
                        'souissi'     => 'السويسي',
                        'hassan'      => 'حسان',
                        'hay riad'    => 'حي الرياض',
                        'maarif'      => 'المعاريف',
                        'maârif'      => 'المعاريف',
                        'ain diab'    => 'عين دياب',
                        'gauthier'    => 'غوتييه',
                        'californie'  => 'كاليفورنيا',
                        'anfa'        => 'أنفا',
                        'oca'         => 'الأوكا',
                        'sidi maarouf' => 'سيدي معروف',
                );
        }

        /**
         * Traduit un lieu (« Quartier, Ville ») dans la langue voulue.
         * Un quartier inconnu reste tel quel : c'est un nom propre.
         *
         * @param string $place Lieu en francais.
         * @param string $lang  Langue cible.
         * @return string
         */
        private static function place_in( $place, $lang ) {
                if ( 'ar' !== $lang || '' === $place ) {
                        return $place;
                }

                $map    = self::arabic_places();
                $pieces = array_map( 'trim', explode( ',', $place ) );
                $out    = array();

                foreach ( $pieces as $piece ) {
                        $key   = function_exists( 'mb_strtolower' ) ? mb_strtolower( $piece ) : strtolower( $piece );
                        $key   = function_exists( 'remove_accents' ) ? remove_accents( $key ) : $key;
                        $found = '';
                        foreach ( $map as $needle => $arabic ) {
                                $needle_key = function_exists( 'remove_accents' ) ? remove_accents( $needle ) : $needle;
                                if ( $needle_key === $key ) {
                                        $found = $arabic;
                                        break;
                                }
                        }
                        $out[] = $found ? $found : $piece;
                }

                return implode( '، ', $out );
        }

        /**
         * Traduit le libelle d'un type de bien.
         *
         * @param string $type Libelle francais.
         * @param string $lang Langue cible.
         * @return string
         */
        private static function type_label( $type, $lang ) {
                $lex = self::lex( $lang );
                $key = function_exists( 'mb_strtolower' ) ? mb_strtolower( trim( $type ) ) : strtolower( trim( $type ) );

                return isset( $lex['types'][ $key ] ) ? $lex['types'][ $key ] : $type;
        }

        /**
         * Nombre formate selon la langue (chiffres arabes occidentaux partout,
         * separateur adapte : l'arabe marocain lit tres bien 124 098).
         *
         * @param int $number Nombre.
         * @return string
         */
        private static function number( $number ) {
                return number_format( (int) $number, 0, ',', ' ' );
        }

        /**
         * Libelle du couchage dans la langue voulue.
         *
         * @param array  $v    Donnees normalisees.
         * @param string $lang Langue.
         * @return string
         */
        private static function rooms( $v, $lang ) {
                $lex = self::lex( $lang );

                if ( self::is_studio( $v ) ) {
                        return $lex['studio'];
                }
                if ( '' === (string) $v['bedrooms'] ) {
                        return '';
                }
                if ( '3+' === (string) $v['bedrooms'] ) {
                        return $lex['bed_3plus'];
                }

                $count = (int) $v['bedrooms'];
                $label = 1 === $count ? sprintf( $lex['bedroom'], $count ) : sprintf( $lex['bedrooms'], $count );

                if ( '' !== (string) $v['living_rooms'] && '0' !== (string) $v['living_rooms'] ) {
                        $label .= $lex['plus_living'];
                }

                return $label;
        }

        /**
         * Le bien est-il un studio ?
         *
         * @param array $v Donnees normalisees.
         * @return bool
         */
        private static function is_studio( $v ) {
                return 'studio' === strtolower( $v['type'] ) || '0' === (string) $v['bedrooms'];
        }

        /**
         * Traduit un libelle d'etage (« 5e étage » -> « 5th floor » / « الطابق 5 »).
         *
         * @param string $floor Libelle francais.
         * @param string $lang  Langue cible.
         * @return string
         */
        private static function floor_label( $floor, $lang ) {
                $lex = self::lex( $lang );
                if ( isset( $lex['floors'][ $floor ] ) ) {
                        return $lex['floors'][ $floor ];
                }
                if ( 'fr' === $lang ) {
                        return self::lower( $floor );
                }

                // « 5e étage » -> on isole le nombre, seul element porteur de sens.
                if ( preg_match( '/(\d+)/', $floor, $m ) ) {
                        $n = (int) $m[1];
                        if ( 'en' === $lang ) {
                                $suffix = 'th';
                                if ( 1 === $n % 10 && 11 !== $n ) {
                                        $suffix = 'st';
                                } elseif ( 2 === $n % 10 && 12 !== $n ) {
                                        $suffix = 'nd';
                                } elseif ( 3 === $n % 10 && 13 !== $n ) {
                                        $suffix = 'rd';
                                }

                                return $n . $suffix . ' floor';
                        }

                        return 'الطابق ' . $n;
                }

                return self::lower( $floor );
        }
}

