<?php
/**
 * Template Name: Page légale
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
?>
<main id="main-content" class="pk-journal">
	<div class="pk-journal-band">
		<p class="pk-journal-word">Journal</p>
	</div>
	<?php
	while ( have_posts() ) :
		the_post();
		?>
		<article class="pk-container pk-container-narrow pk-journal__article">
			<h1><?php the_title(); ?></h1>
			<div class="pk-content pk-article-content">
				<?php the_content(); ?>
			</div>
		</article>
		<?php
	endwhile;
	?>
</main>
<?php
get_footer();
