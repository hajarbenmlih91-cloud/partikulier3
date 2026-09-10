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

get_header();
?>

<section class="pk-favorites">
	<div class="pk-container">
		<?php echo Partikulier_Geo::breadcrumbs_html(); // phpcs:ignore ?>

		<header class="pk-archive-head">
			<p class="pk-editorial-kicker"><?php esc_html_e( 'Votre sélection', 'partikulier' ); ?></p>
			<h1 class="pk-archive-title"><?php esc_html_e( 'Mes favoris', 'partikulier' ); ?></h1>
			<p class="pk-archive-subtitle" id="pk-fav-count">
				<?php esc_html_e( 'Les biens que vous avez enregistrés depuis cet appareil.', 'partikulier' ); ?>
			</p>
		</header>

		<div id="pk-favorites-grid" class="pk-grid pk-grid-3" aria-live="polite"></div>

		<div id="pk-favorites-empty" class="pk-favorites-empty" hidden>
			<p class="pk-favorites-empty-title"><?php esc_html_e( 'Aucun favori pour le moment', 'partikulier' ); ?></p>
			<p>
				<?php esc_html_e( 'Cliquez sur le cœur d’une annonce pour la retrouver ici. Vos favoris sont conservés dans ce navigateur, sans création de compte.', 'partikulier' ); ?>
			</p>
			<a class="pk-btn pk-btn-primary" href="<?php echo esc_url( pk_properties_archive_url() ); ?>">
				<?php esc_html_e( 'Parcourir les annonces', 'partikulier' ); ?>
			</a>
		</div>

		<p class="pk-favorites-note">
			<?php esc_html_e( 'Ces favoris sont enregistrés dans votre navigateur. Ils ne suivent pas d’un appareil à l’autre et disparaissent si vous effacez vos données de navigation.', 'partikulier' ); ?>
		</p>
	</div>
</section>

<?php
get_footer();
