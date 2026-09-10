<?php
/**
 * Lexique trilingue de la rédaction des annonces (lot C1 — I18N-1).
 *
 * Port fidèle du lexique de Partikulier_Listing_I18n (thème 6.18.x, volet
 * données) : mêmes langues (fr/en/ar), mêmes clés, mêmes libellés — les
 * dictionnaires sont déplacés VERBATIM (aucun libellé réécrit, la parité
 * avec le chemin de repli du thème est prouvée par le contrat du lot C1).
 * Le lexique est la source unique de la couche CONTENU : les générateurs
 * (titre, description, meta, alt photo) composent à partir de ces clés —
 * chaque langue produit son propre texte, naturel, gratuit et instantané.
 *
 * Bibliothèque PURE : zéro hook, zéro table, zéro écriture, aucune
 * dépendance au thème (chargée inconditionnellement — pattern lots B,
 * coût de bootstrap mesuré par REG-2).
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\I18n;

final class ListingLexicon
{

        /**
         * Langues prises en charge.
         *
         * @return string[]
         */
        public static function languages() {
                return array( 'fr', 'en', 'ar' );
        }

        /**
         * Lexique par langue.
         *
         * @param string $lang Code langue.
         * @return array
         */
        public static function lex( $lang ) {
                $all = array(
                        'fr' => array(
                                'owner_sells'   => 'Propriétaire vend',
                                'owner_rents'   => 'Propriétaire loue',
                                'the_owner'     => 'le propriétaire',
                                'owner'         => 'propriétaire',
                                'for_sale'      => 'à vendre',
                                'for_rent'      => 'à louer',
                                'sale_noun'     => 'à la vente',
                                'rent_noun'     => 'à la location',
                                'studio'        => 'Studio',
                                'studio_low'    => 'studio',
                                'of_area'       => 'de %d m²',
                                'in_place'      => 'à %s',
                                'with'          => 'avec',
                                'and'           => 'et',
                                'main_room'     => 'une pièce principale',
                                'bedroom'       => '%d chambre',
                                'bedrooms'      => '%d chambres',
                                'plus_living'   => ' + salon',
                                'bed_3plus'     => '3 chambres + salon ou plus',
                                'bathroom'      => '%d salle de bains',
                                'bathrooms'     => '%d salles de bains',
                                'terrace'       => 'avec terrasse',
                                'terrace_area'  => 'terrasse de %d m²',
                                'with_terrace_area' => 'avec terrasse de %d m²',
                                'floors'        => array(),
                                'no_facing'     => 'sans vis-à-vis',
                                'area_of'       => 'd’une superficie de %d m²',
                                'price_at'      => 'au prix de %s MAD',
                                'located'       => 'situé %s',
                                'ground_floor'  => 'au rdc',
                                'at_floor'      => 'au %s',
                                'with_garage'   => 'avec garage ou sous-sol',
                                'no_garage'     => 'sans garage',
                                'with_lift'     => 'avec ascenseur',
                                'no_lift'       => 'sans ascenseur',
                                'p2p'           => 'Bien de particulier à particulier proposé %1$s à %2$s, en contact direct avec %3$s, sans commission ni intermédiaire.',
                                'contact'       => 'Contact direct, sans commission.',
                                'no_commission' => 'Sans commission.',
                                'garage'        => 'garage',
                                'lift'          => 'ascenseur',
                                'terrace_short' => 'Terrasse',
                                'photo_of'      => 'annonce de %s, sans commission',
                                'photo_n'       => '(photo %d)',
                                'angles'        => array( 'vue intérieure', 'pièce de vie', 'espace intérieur', 'vue depuis le séjour', 'détail du logement', 'seconde perspective' ),
                                'at_ground'     => 'au rez-de-chaussée',
                                'sun'           => array(
                                        'Ensoleillé le matin'      => 'ensoleillé le matin',
                                        'Ensoleillé l’après-midi'  => 'ensoleillé l’après-midi',
                                        "Ensoleillé l'après-midi"  => 'ensoleillé l’après-midi',
                                        'Toute la journée'         => 'ensoleillé toute la journée',
                                        'Très peu'                 => 'peu ensoleillé',
                                ),
                                'types'         => array(),
                        ),
                        'en' => array(
                                'owner_sells'   => 'Owner selling',
                                'owner_rents'   => 'Owner renting',
                                'the_owner'     => 'the owner',
                                'owner'         => 'owner',
                                'for_sale'      => 'for sale',
                                'for_rent'      => 'for rent',
                                'sale_noun'     => 'for sale',
                                'rent_noun'     => 'for rent',
                                'studio'        => 'Studio',
                                'studio_low'    => 'studio',
                                'of_area'       => 'of %d sqm',
                                'in_place'      => 'in %s',
                                'with'          => 'with',
                                'and'           => 'and',
                                'main_room'     => 'one main room',
                                'bedroom'       => '%d bedroom',
                                'bedrooms'      => '%d bedrooms',
                                'plus_living'   => ' + living room',
                                'bed_3plus'     => '3 bedrooms + living room or more',
                                'bathroom'      => '%d bathroom',
                                'bathrooms'     => '%d bathrooms',
                                'terrace'       => 'with a terrace',
                                'terrace_area'  => '%d sqm terrace',
                                'with_terrace_area' => 'with a %d sqm terrace',
                                'floors'        => array( 'RDC' => 'ground floor', 'Dernier étage' => 'top floor' ),
                                'no_facing'     => 'no facing neighbours',
                                'area_of'       => 'with a surface of %d sqm',
                                'price_at'      => 'priced at %s MAD',
                                'located'       => 'located %s',
                                'ground_floor'  => 'on the ground floor',
                                'at_floor'      => 'on the %s',
                                'with_garage'   => 'with garage or basement',
                                'no_garage'     => 'no garage',
                                'with_lift'     => 'with a lift',
                                'no_lift'       => 'no lift',
                                'p2p'           => 'Property offered directly by a private owner %1$s in %2$s, in direct contact with %3$s, with no agency fees.',
                                'contact'       => 'Direct contact, no commission.',
                                'no_commission' => 'No commission.',
                                'garage'        => 'garage',
                                'lift'          => 'lift',
                                'terrace_short' => 'Terrace',
                                'photo_of'      => 'listed by the %s, no commission',
                                'photo_n'       => '(photo %d)',
                                'angles'        => array( 'interior view', 'living area', 'indoor space', 'view from the living room', 'property detail', 'second perspective' ),
                                'at_ground'     => 'on the ground floor',
                                'sun'           => array(
                                        'Ensoleillé le matin'      => 'sunny in the morning',
                                        'Ensoleillé l’après-midi'  => 'sunny in the afternoon',
                                        "Ensoleillé l'après-midi"  => 'sunny in the afternoon',
                                        'Toute la journée'         => 'sunny all day',
                                        'Très peu'                 => 'little sunlight',
                                ),
                                'types'         => array(
                                        'appartement' => 'Apartment',
                                        'maison'      => 'House',
                                        'studio'      => 'Studio',
                                        'villa'       => 'Villa',
                                        'terrain'     => 'Land',
                                        'loft'        => 'Loft',
                                        'duplex'      => 'Duplex',
                                        'chalet'      => 'Chalet',
                                        'bureau'      => 'Office',
                                        'local commercial' => 'Commercial premises',
                                        'riad'        => 'Riad',
                                        'ferme'       => 'Farm',
                                ),
                        ),
                        'ar' => array(
                                'owner_sells'   => 'المالك يبيع',
                                'owner_rents'   => 'المالك يكري',
                                'the_owner'     => 'المالك',
                                'owner'         => 'المالك',
                                'for_sale'      => 'للبيع',
                                'for_rent'      => 'للكراء',
                                'sale_noun'     => 'للبيع',
                                'rent_noun'     => 'للكراء',
                                'studio'        => 'استوديو',
                                'studio_low'    => 'استوديو',
                                'of_area'       => 'بمساحة %d م²',
                                'in_place'      => 'في %s',
                                'with'          => 'يتوفر على',
                                'and'           => 'و',
                                'main_room'     => 'غرفة رئيسية',
                                'bedroom'       => 'غرفة نوم واحدة',
                                'bedrooms'      => '%d غرف نوم',
                                'plus_living'   => ' وصالون',
                                'bed_3plus'     => '3 غرف نوم وصالون أو أكثر',
                                'bathroom'      => 'حمام واحد',
                                'bathrooms'     => '%d حمامات',
                                'terrace'       => 'مع تراس',
                                'terrace_area'  => 'تراس بمساحة %d م²',
                                'with_terrace_area' => 'مع تراس بمساحة %d م²',
                                'floors'        => array( 'RDC' => 'الطابق الأرضي', 'Dernier étage' => 'الطابق الأخير' ),
                                'no_facing'     => 'بدون مقابل',
                                'area_of'       => 'بمساحة %d م²',
                                'price_at'      => 'بثمن %s درهم',
                                'located'       => 'يقع %s',
                                'ground_floor'  => 'في الطابق الأرضي',
                                'at_floor'      => 'في %s',
                                'with_garage'   => 'مع مرآب أو قبو',
                                'no_garage'     => 'بدون مرآب',
                                'with_lift'     => 'مع مصعد',
                                'no_lift'       => 'بدون مصعد',
                                'p2p'           => 'عقار معروض من مالك خاص %1$s في %2$s، اتصال مباشر مع %3$s، بدون عمولة ولا وسيط.',
                                'contact'       => 'اتصال مباشر، بدون عمولة.',
                                'no_commission' => 'بدون عمولة.',
                                'garage'        => 'مرآب',
                                'lift'          => 'مصعد',
                                'terrace_short' => 'تراس',
                                'photo_of'      => 'إعلان من %s، بدون عمولة',
                                'photo_n'       => '(صورة %d)',
                                'angles'        => array( 'منظر داخلي', 'فضاء المعيشة', 'فضاء داخلي', 'منظر من الصالون', 'تفصيل من العقار', 'منظر ثانٍ' ),
                                'at_ground'     => 'في الطابق الأرضي',
                                'sun'           => array(
                                        'Ensoleillé le matin'      => 'مشمس صباحاً',
                                        'Ensoleillé l’après-midi'  => 'مشمس بعد الزوال',
                                        "Ensoleillé l'après-midi"  => 'مشمس بعد الزوال',
                                        'Toute la journée'         => 'مشمس طوال اليوم',
                                        'Très peu'                 => 'قليل الشمس',
                                ),
                                'types'         => array(
                                        'appartement' => 'شقة',
                                        'appartements' => 'شقة',
                                        'maison'      => 'منزل',
                                        'maisons'     => 'منزل',
                                        'studio'      => 'استوديو',
                                        'villa'       => 'فيلا',
                                        'terrain'     => 'أرض',
                                        'loft'        => 'لوفت',
                                        'duplex'      => 'دوبلكس',
                                        'chalet'      => 'شاليه',
                                        'bureau'      => 'مكتب',
                                        'local commercial' => 'محل تجاري',
                                        'riad'        => 'رياض',
                                                'ferme'       => 'ضيعة',
                                                'bien'        => 'عقار',
                                                'property'    => 'عقار',
                                        ),
                        ),
                );

                return isset( $all[ $lang ] ) ? $all[ $lang ] : $all['fr'];
        }
}

