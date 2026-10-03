<?php
/**
 * Template 404 avec suggestions SEO.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>

<section class="pk-404">
	<div class="pk-container pk-container-narrow">
		<h1 class="pk-404-code">404</h1>
		<p class="pk-404-title"><?php esc_html_e( 'Cette page n’existe pas.', 'partikulier' ); ?></p>
		<p class="pk-404-desc">
			<?php esc_html_e( 'L’annonce que vous cherchez a peut-être été retirée ou déplacée. Retrouvez nos dernières annonces ou déposez la vôtre.', 'partikulier' ); ?>
		</p>
		<div class="pk-404-actions">
			<a class="pk-btn pk-btn-primary" href="<?php echo esc_url( pk_properties_archive_url() ); ?>">
				<?php esc_html_e( 'Voir les annonces', 'partikulier' ); ?>
			</a>
			<a class="pk-btn pk-btn-outline" href="<?php echo esc_url( home_url( '/' ) ); ?>">
				<?php esc_html_e( 'Retour à l’accueil', 'partikulier' ); ?>
			</a>
		</div>
		<?php
		$recent = get_posts( array(
			'post_type'      => PARTIKULIER_ESTATIK_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 4,
			'no_found_rows'  => true,
		) );
		if ( $recent ) :
			?>
			<div class="pk-grid pk-grid-cards pk-404-suggestions">
				<?php
				foreach ( $recent as $property ) {
					require PARTIKULIER_DIR . '/templates/parts/card-property.php';
				}
				?>
			</div>
		<?php endif; ?>
	</div>
</section>

<?php
get_footer();