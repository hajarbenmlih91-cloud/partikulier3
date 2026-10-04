<?php
/**
 * Template de dernier recours (fallback).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>

<section class="pk-archive">
	<div class="pk-container">
		<?php if ( have_posts() ) : ?>
			<div class="pk-grid pk-grid-cards">
				<?php
				while ( have_posts() ) :
					the_post();
					$property = get_post();
					require PARTIKULIER_DIR . '/templates/parts/card-property.php';
				endwhile;
				?>
			</div>
			<?php pk_pagination(); ?>
		<?php else : ?>
			<div class="pk-empty">
				<h2><?php esc_html_e( 'Rien à afficher pour le moment.', 'partikulier' ); ?></h2>
				<a class="pk-btn pk-btn-outline" href="<?php echo esc_url( home_url( '/' ) ); ?>">
					<?php esc_html_e( 'Retour à l’accueil', 'partikulier' ); ?>
				</a>
			</div>
		<?php endif; ?>
	</div>
</section>

<?php
get_footer();