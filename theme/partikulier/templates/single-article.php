<?php
/**
 * Article du journal. Le texte vient de l'article WordPress.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

while ( have_posts() ) :
	the_post();
	$kicker    = (string) get_post_meta( get_the_ID(), '_pk_kicker', true );
	$chapo     = (string) get_post_meta( get_the_ID(), '_pk_chapo', true );
	$signature = (string) get_post_meta( get_the_ID(), '_pk_signature', true );
	$caption   = (string) get_post_meta( get_the_ID(), '_pk_caption', true );
	if ( '' === $signature ) {
		$signature = 'La rédaction';
	}
	?>
	<main id="main-content" class="pk-journal">
		<div class="pk-journal-band">
			<p class="pk-journal-word"><a href="<?php echo esc_url( function_exists( 'pk_page_url' ) ? pk_page_url( 'journal', '/journal/' ) : home_url( '/journal/' ) ); ?>">Journal</a></p>
		</div>
		<article class="pk-container pk-container-narrow pk-journal__article">
			<?php if ( $kicker ) : ?><p class="kicker"><?php echo esc_html( $kicker ); ?></p><?php endif; ?>
			<h1><?php the_title(); ?></h1>
			<?php if ( $chapo ) : ?><p class="deck"><?php echo esc_html( $chapo ); ?></p><?php endif; ?>
			<p class="by"><b><?php echo esc_html( $signature ); ?></b></p>
			<?php if ( has_post_thumbnail() ) : ?>
				<figure class="pk-journal__figure">
					<?php the_post_thumbnail( 'large', array( 'loading' => 'eager', 'decoding' => 'async', 'alt' => '' ) ); ?>
					<?php if ( $caption ) : ?><figcaption class="caption"><?php echo esc_html( $caption ); ?></figcaption><?php endif; ?>
				</figure>
			<?php endif; ?>
			<div class="pk-content pk-article-content">
				<?php the_content(); ?>
			</div>
		</article>
	</main>
	<?php
endwhile;

get_footer();
