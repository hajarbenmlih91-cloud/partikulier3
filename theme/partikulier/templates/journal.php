<?php
/**
 * Accueil du journal. Les cinq articles, ensemble.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();

$query = class_exists( 'Partikulier_Journal' ) ? Partikulier_Journal::query() : null;
$posts = ( $query instanceof WP_Query ) ? $query->posts : array();
$lead  = $posts ? array_shift( $posts ) : null;
$lang  = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
?>
<main id="main-content" class="pk-journal">
	<div class="pk-journal-band">
		<p class="pk-journal-word">Journal</p>
		<?php if ( 'fr' !== $lang ) : ?>
			<p class="pk-journal-note"><?php echo 'en' === $lang ? 'These articles are in French.' : '<span lang="fr">Ces articles sont en français.</span>'; ?></p>
		<?php endif; ?>
	</div>
	<div class="pk-container">
		<?php if ( ! $lead ) : ?>
			<p class="pk-journal__empty">Aucun article publié.</p>
		<?php else : ?>
			<div class="pk-journal-home">
				<article class="pk-journal-lead">
					<a href="<?php echo esc_url( get_permalink( $lead ) ); ?>">
						<?php if ( has_post_thumbnail( $lead ) ) : ?>
							<figure><?php echo get_the_post_thumbnail( $lead, 'large', array( 'loading' => 'eager', 'decoding' => 'async', 'alt' => '' ) ); ?></figure>
						<?php endif; ?>
						<p class="kicker"><?php echo esc_html( (string) get_post_meta( $lead->ID, '_pk_kicker', true ) ); ?></p>
						<h1><?php echo esc_html( get_the_title( $lead ) ); ?></h1>
						<?php $chapo = (string) get_post_meta( $lead->ID, '_pk_chapo', true ); ?>
						<?php if ( $chapo ) : ?><p class="deck"><?php echo esc_html( $chapo ); ?></p><?php endif; ?>
						<p class="by"><b>La rédaction</b></p>
					</a>
				</article>
				<?php if ( $posts ) : ?>
					<aside class="pk-journal-rail">
						<?php foreach ( $posts as $item ) : ?>
							<article>
								<a href="<?php echo esc_url( get_permalink( $item ) ); ?>">
									<p class="kicker"><?php echo esc_html( (string) get_post_meta( $item->ID, '_pk_kicker', true ) ); ?></p>
									<h2><?php echo esc_html( get_the_title( $item ) ); ?></h2>
									<?php $deck = (string) get_post_meta( $item->ID, '_pk_chapo', true ); ?>
									<?php if ( $deck ) : ?><p><?php echo esc_html( $deck ); ?></p><?php endif; ?>
								</a>
							</article>
						<?php endforeach; ?>
					</aside>
				<?php endif; ?>
			</div>
		<?php endif; ?>
	</div>
</main>
<?php
if ( $query instanceof WP_Query ) {
	wp_reset_postdata();
}
get_footer();
