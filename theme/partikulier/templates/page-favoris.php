<?php
/**
 * Template : Mes favoris.
 *
 * Les favoris d'un visiteur non connecte vivent dans son navigateur
 * (localStorage). Cette page les lit cote client et demande au serveur les
 * annonces correspondantes : aucun compte n'est necessaire.
 *
 * @package Partikulier
 *
 * Template Name: Favoris
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/* Libellés trilingues hors dictionnaires figés (contrats C2A/E24) :
 * tableau local, langue courante via Polylang. */
$pk_fav_lang = 'fr';
if ( function_exists( 'pll_current_language' ) && pll_current_language() ) {
	$pk_fav_lang = sanitize_key( (string) pll_current_language() );
}
$pk_fav_i18n = array(
	'fr' => array(
		'kicker'  => 'Votre sélection',
		'title'   => 'Mes favoris',
		'sub'     => 'Les biens que vous avez enregistrés depuis cet appareil.',
		'empty_t' => 'Aucun favori pour le moment',
		'empty'   => 'Cliquez sur le cœur d’une annonce pour la retrouver ici. Vos favoris sont conservés dans ce navigateur, sans création de compte.',
		'browse'  => 'Parcourir les annonces',
		'note'    => 'Ces favoris sont enregistrés dans votre navigateur. Ils ne suivent pas d’un appareil à l’autre et disparaissent si vous effacez vos données de navigation.',
	),
	'en' => array(
		'kicker'  => 'Your selection',
		'title'   => 'My favorites',
		'sub'     => 'The properties you have saved from this device.',
		'empty_t' => 'No favorites yet',
		'empty'   => 'Click the heart on a listing to find it here. Your favorites are kept in this browser, no account needed.',
		'browse'  => 'Browse listings',
		'note'    => 'These favorites are stored in your browser. They do not follow you across devices and disappear if you clear your browsing data.',
	),
	'ar' => array(
		'kicker'  => 'اختيارك',
		'title'   => 'مفضلتي',
		'sub'     => 'العقارات التي حفظتها من هذا الجهاز.',
		'empty_t' => 'لا توجد مفضلات بعد',
		'empty'   => 'انقر على قلب الإعلان لتجده هنا. تُحفظ مفضلتك في هذا المتصفح، بدون إنشاء حساب.',
		'browse'  => 'تصفح الإعلانات',
		'note'    => 'تُحفظ هذه المفضلة في متصفحك. لا تنتقل بين الأجهزة وتختفي إذا مسحت بيانات التصفح.',
	),
);
$pk_fav_t = $pk_fav_i18n[ $pk_fav_lang ] ?? $pk_fav_i18n['fr'];

get_header();
?>

<section class="pk-favorites">
	<div class="pk-container">
		<?php echo Partikulier_Geo::breadcrumbs_html(); // phpcs:ignore ?>

		<header class="pk-archive-head">
			<p class="pk-editorial-kicker"><?php echo esc_html( $pk_fav_t['kicker'] ); ?></p>
			<h1 class="pk-archive-title"><?php echo esc_html( $pk_fav_t['title'] ); ?></h1>
			<p class="pk-archive-subtitle" id="pk-fav-count">
				<?php echo esc_html( $pk_fav_t['sub'] ); ?>
			</p>
		</header>

		<div id="pk-favorites-grid" class="pk-grid pk-grid-3" aria-live="polite"></div>

		<div id="pk-favorites-empty" class="pk-favorites-empty" hidden>
			<p class="pk-favorites-empty-title"><?php echo esc_html( $pk_fav_t['empty_t'] ); ?></p>
			<p>
				<?php echo esc_html( $pk_fav_t['empty'] ); ?>
			</p>
			<a class="pk-btn pk-btn-primary" href="<?php echo esc_url( pk_properties_archive_url() ); ?>">
				<?php echo esc_html( $pk_fav_t['browse'] ); ?>
			</a>
		</div>

		<p class="pk-favorites-note">
			<?php echo esc_html( $pk_fav_t['note'] ); ?>
		</p>
	</div>
</section>

<?php
get_footer();
