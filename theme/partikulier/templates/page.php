<?php
/**
 * Template de page generique.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
get_header();
?>

<section class="pk-page">
	<div class="pk-container pk-container-narrow">
		<?php
		while ( have_posts() ) :
			the_post();
			?>
			<?php echo Partikulier_Geo::breadcrumbs_html(); // phpcs:ignore ?>
			<article class="pk-article">
				<header class="pk-article-head">
					<h1 class="pk-article-title"><?php the_title(); ?></h1>
				</header>
				<div class="pk-content pk-article-content">
					<?php the_content(); ?>
				</div>
			</article>
		<?php endwhile; ?>
	</div>
</section>

<?php
get_footer();