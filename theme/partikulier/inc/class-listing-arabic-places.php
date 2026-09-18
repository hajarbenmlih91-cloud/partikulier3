<?php
/**
 * Dictionnaire des lieux en arabe (SE-036, E-3601 — données pures, côté thème).
 *
 * Repli autonome du thème (REG-5) : la MÊME carte que le dictionnaire du
 * plugin (parité C1A-004) — les 180 clés couvrent les 30 villes et tous
 * les quartiers du référentiel marocain. Découpe CA-4 : le trait
 * Partikulier_Listing_I18n_Places reste ≤300 lignes (C1A-009).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
		return;
}

final class Partikulier_Listing_Arabic_Places {

		/**
		 * Carte intégrée : clé normalisée => forme arabe.
		 *
		 * @return array<string,string>
		 */
	public static function map() {
			return array(
			'casablanca'   => 'الدار البيضاء',
			'rabat'        => 'الرباط',
			'marrakech'    => 'مراكش',
			'tanger'       => 'طنجة',
			'fes'          => 'فاس',
			'fès'          => 'فاس',
			'agadir'       => 'أكادير',
			'saidia'       => 'السعيدية',
			'saïdia'       => 'السعيدية',
			'meknes'       => 'مكناس',
			'meknès'       => 'مكناس',
			'oujda'        => 'وجدة',
			'kenitra'      => 'القنيطرة',
			'kénitra'      => 'القنيطرة',
			'tetouan'      => 'تطوان',
			'tétouan'      => 'تطوان',
			'sale'         => 'سلا',
			'salé'         => 'سلا',
			'mohammedia'   => 'المحمدية',
			'el jadida'    => 'الجديدة',
			'essaouira'    => 'الصويرة',
			'beni mellal'  => 'بني ملال',
			'nador'        => 'الناظور',
			'ifrane'       => 'إفران',
			'ouarzazate'   => 'ورزازات',
			'safi'         => 'آسفي',
			'dakhla'       => 'الداخلة',
			'laayoune'     => 'العيون',
			'laâyoune'     => 'العيون',
			'berrechid'    => 'برشيد',
			'settat'       => 'سطات',
			'khouribga'    => 'خريبكة',
			'taza'         => 'تازة',
			'larache'      => 'العرائش',
			'al hoceima'   => 'الحسيمة',
			'chefchaouen'  => 'شفشاون',
			'bouznika'     => 'بوزنيقة',
			'skhirat'      => 'الصخيرات',
			'temara'       => 'تمارة',
			'témara'       => 'تمارة',
			'berkane'      => 'بركان',
			/* 6.17.30 — S9.8 : quartiers courants du référentiel marocain.
			* Sans ces entrées, les quartiers référencés en français
			* restaient latins dans les titres/données AR des fiches
			* (Targa, Hivernage, Médina, Agdal…) et dans les futurs
			* dépôts réels. */
			'targa'        => 'تارغة',
			'hivernage'    => 'هيفيرناژ',
			'medina'       => 'المدينة القديمة',
			'médina'       => 'المدينة القديمة',
			'gueliz'       => 'جيليز',
			'guéliz'       => 'جيليز',
			'palmeraie'    => 'النخيل',
			'agdal'        => 'أكدال',
			'souissi'      => 'السويسي',
			'hassan'       => 'حسان',
			'hay riad'     => 'حي الرياض',
			'maarif'       => 'المعاريف',
			'maârif'       => 'المعاريف',
			'ain diab'     => 'عين دياب',
			'gauthier'     => 'غوتييه',
			'californie'   => 'كاليفورنيا',
			'anfa'         => 'أنفا',
			'oca'          => 'الأوكا',
			'sidi maarouf' => 'سيدي معروف',
			/* SE-036 (E-3601) — référentiel AR complet : les 148 quartiers
			* du référentiel marocain (ville => quartiers) désormais couverts
			* — rédaction de la reprise v2, aucune reprise de la tentative
			* perdue. Les libellés partagés (Centre-ville, Hay Salam…)
			* dédupliqués par clé normalisée. */
			'achakar' => 'أشكار', 'ain sebaa' => 'عين السبع', 'ajdir' => 'أجدير',
			'akkari' => 'القري', 'al andalous' => 'الأندلس', 'al aroui' => 'العروي',
			'alia' => 'عالية', 'amerchich' => 'أمزشيش', 'andalous' => 'الأندلس',
			'anza' => 'أنزة', 'atlas' => 'أطلس', 'aviation' => 'الأفياسيون',
			'bassatine' => 'البساتين', 'beauséjour' => 'بو سيجور', 'belvédère' => 'بلفيدار',
			'bettana' => 'بطانة', 'biada' => 'البياضة', 'bir rami' => 'بير رامي',
			'borj' => 'البرج', 'boubana' => 'بوبانا', 'bourgogne' => 'بورگون',
			'bouznika bay' => 'بوزنيقة باي', 'branes' => 'برانش', 'cabo negro' => 'كابو نيغرو',
			'calabonita' => 'كالابونيتا', 'cap spartel' => 'رأس سبارطيل', 'centre-ville' => 'وسط المدينة',
			'charaf' => 'الشرف', 'cil' => 'السيل', 'cité portugaise' => 'المدينة البرتغالية',
			'cité suisse' => 'المدينة السويسرية', 'colomina nueva' => 'كولومينا نويفا', 'derb sultan' => 'درب السلطان',
			'diabat' => 'ديابات', 'el menzeh' => 'المنزه', 'el wahda' => 'الوحدة',
			'essalam' => 'السلام', 'founty' => 'فونتي', 'ghazoua' => 'غزوة',
			'habous' => 'الحبوس', 'hamria' => 'الحمرية', 'hassania' => 'الحسنية',
			'hay el massira' => 'حي المسيرة', 'hay essalam' => 'حي السلام',
			'hay al amal' => 'حي الأمل', 'hay al massira' => 'حي المسيرة', 'hay al qods' => 'حي القدس',
			'hay el wahda' => 'حي الوحدة', 'hay ennahda' => 'حي النهضة', 'hay hassani' => 'حي الحسني',
			'hay karima' => 'حي كريمة', 'hay mohammadi' => 'حي المحمدي', 'hay salam' => 'حي السلام',
			'iberia' => 'إيبيريا', 'ihaddadene' => 'إحدادن', 'illigh' => 'إيليغ',
			'jerifat' => 'جريفات', 'jnan adarissa' => 'جنان أدريسة', 'kasbah' => 'القصبة',
			'koucha' => 'كوشة', 'ksar el kebir' => 'القصر الكبير', 'laayayda' => 'العيايدة',
			'lazaret' => 'اللازاريت', 'les orangers' => 'الأورانجيه', 'maamora' => 'المعمورة',
			'malabata' => 'مالاباطا', 'marjane' => 'مرجان', 'marshan' => 'مرشان',
			'martil' => 'مرتيل', 'massira' => 'المسيرة', 'mers sultan' => 'مرس السلطان',
			'mesnana' => 'مسنانة', 'mimosas' => 'الميموزا', 'montfleuri' => 'مونفلوري',
			'moulay rachid' => 'مولاي رشيد', 'm’diq' => 'المضيق', 'm’hamid' => 'المحاميد',
			'narjiss' => 'النرجس', 'oasis' => 'الواحة', 'océan' => 'أوشان',
			'ouled hamdane' => 'أولاد حمدان', 'ouled oujih' => 'أولاد أوجيه', 'parc' => 'الحديقة',
			'plage' => 'الشاطئ', 'quartier des dunes' => 'حي الكثبان', 'racine' => 'راسين',
			'riad' => 'رياض', 'riad salam' => 'رياض السلام', 'route de fès' => 'طريق فاس',
			'route d’immouzer' => 'طريق إيموزار', 'saiss' => 'سايس', 'saknia' => 'ساكنية',
			'sala al jadida' => 'سلا الجديدة', 'sania ramel' => 'سانية رمل', 'selouane' => 'سلوان',
			'semlalia' => 'سملالة', 'sidi bernoussi' => 'سيدي برنوصي', 'sidi bouzekri' => 'سيدي بوزكري',
			'sidi bouzid' => 'سيدي بوزيد', 'sidi bouzra' => 'سيدي بوزرة', 'sidi chennane' => 'سيدي الشنون',
			'sidi daoud' => 'سيدي داود', 'sidi ghanem' => 'سيدي غانم', 'sidi yahya' => 'سيدي يحيى',
			'sonaba' => 'صونابا', 'souani' => 'السواني', 'tabounte' => 'تابونت',
			'tabriquet' => 'تابريكت', 'talborjt' => 'تلبرجت', 'tikiouine' => 'تيكيوين',
			'timdiqine' => 'تيمديقين', 'touilaa' => 'الطويعة', 'toulal' => 'تولال',
			'trab lahjar' => 'تراب الحجر', 'val fleuri' => 'فال فلوري', 'ville nouvelle' => 'المدينة الجديدة',
			'yacoub el mansour' => 'يعقوب المنصور', 'zaouiat' => 'زاوية', 'zouagha' => 'زواغة',
			);
	}
}

