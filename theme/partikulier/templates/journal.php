<?php
/**
 * Accueil du journal. Une une, deux articles à côté, deux cartes en dessous.
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
$rail  = array_slice( $posts, 0, 2 );
$cards = array_slice( $posts, 2 );
$lang  = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
$home  = function_exists( 'pk_page_url' ) ? pk_page_url( 'journal', '/journal/' ) : home_url( '/journal/' );
$nav   = class_exists( 'Partikulier_Journal' ) ? Partikulier_Journal::home_nav( $lead ? array_merge( array( $lead ), $posts ) : array() ) : array();
$date  = ( $lead && class_exists( 'Partikulier_Journal' ) ) ? Partikulier_Journal::french_date( $lead ) : '';
?>
<main id="main-content" class="pk-journal" lang="fr">
	<header class="pk-journal-mast">
		<div class="pk-journal-brandline">
			<a class="pk-journal-brand" href="<?php echo esc_url( $home ); ?>">
				<img class="pk-logo-img" src="<?php echo esc_url( get_theme_file_uri( 'assets/img/tuk-ma.png' ) ); ?>" alt="tuk.ma" width="97" height="48">
				<span class="pk-journal-word">Journal</span>
			</a>
			<?php if ( $date ) : ?>
				<span class="pk-journal-date"><?php echo esc_html( $date ); ?></span>
			<?php endif; ?>
		</div>
		<nav class="pk-journal-nav" aria-label="Journal">
			<a href="<?php echo esc_url( $home ); ?>" aria-current="page">Accueil</a>
			<?php foreach ( $nav as $item ) : ?>
				<a href="<?php echo esc_url( $item['url'] ); ?>"><?php echo esc_html( $item['label'] ); ?></a>
			<?php endforeach; ?>
		</nav>
	</header>
	<?php if ( 'fr' !== $lang ) : ?>
		<p class="pk-journal-note"><?php echo 'en' === $lang ? 'These articles are in French.' : '<span lang="fr">Ces articles sont en français.</span>'; ?></p>
	<?php endif; ?>
	<?php if ( $lead ) : ?>
		<div class="pk-journal-ticker">
			<strong>En ce moment</strong>
			<span><?php echo esc_html( get_the_title( $lead ) ); ?></span>
		</div>
	<?php endif; ?>
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
						<?php $kicker = (string) get_post_meta( $lead->ID, '_pk_kicker', true ); ?>
						<?php if ( $kicker ) : ?><p class="kicker"><?php echo esc_html( $kicker ); ?></p><?php endif; ?>
						<h1><?php echo esc_html( get_the_title( $lead ) ); ?></h1>
						<?php $chapo = (string) get_post_meta( $lead->ID, '_pk_chapo', true ); ?>
						<?php if ( $chapo ) : ?><p class="deck"><?php echo esc_html( $chapo ); ?></p><?php endif; ?>
						<?php $signature = (string) get_post_meta( $lead->ID, '_pk_signature', true ); ?>
						<p class="by"><b><?php echo esc_html( '' !== $signature ? $signature : 'La rédaction' ); ?></b></p>
					</a>
				</article>
				<?php if ( $rail ) : ?>
					<aside class="pk-journal-rail">
						<?php foreach ( $rail as $item ) : ?>
							<article>
								<a href="<?php echo esc_url( get_permalink( $item ) ); ?>">
									<?php $item_kicker = (string) get_post_meta( $item->ID, '_pk_kicker', true ); ?>
									<?php if ( $item_kicker ) : ?><p class="kicker"><?php echo esc_html( $item_kicker ); ?></p><?php endif; ?>
									<h2><?php echo esc_html( get_the_title( $item ) ); ?></h2>
									<?php $deck = (string) get_post_meta( $item->ID, '_pk_chapo', true ); ?>
									<?php if ( $deck ) : ?><p><?php echo esc_html( $deck ); ?></p><?php endif; ?>
								</a>
							</article>
						<?php endforeach; ?>
					</aside>
				<?php endif; ?>
			</div>
			<?php if ( $cards ) : ?>
				<div class="pk-journal-row">
					<?php foreach ( $cards as $item ) : ?>
						<article class="pk-journal-card">
							<a href="<?php echo esc_url( get_permalink( $item ) ); ?>">
								<?php if ( has_post_thumbnail( $item ) ) : ?>
									<figure><?php echo get_the_post_thumbnail( $item, 'large', array( 'loading' => 'lazy', 'decoding' => 'async', 'alt' => '' ) ); ?></figure>
								<?php endif; ?>
								<?php $card_kicker = (string) get_post_meta( $item->ID, '_pk_kicker', true ); ?>
								<?php if ( $card_kicker ) : ?><p class="kicker"><?php echo esc_html( $card_kicker ); ?></p><?php endif; ?>
								<h2><?php echo esc_html( get_the_title( $item ) ); ?></h2>
								<?php $card_deck = (string) get_post_meta( $item->ID, '_pk_chapo', true ); ?>
								<?php if ( $card_deck ) : ?><p><?php echo esc_html( $card_deck ); ?></p><?php endif; ?>
							</a>
						</article>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</main>
<?php
if ( $query instanceof WP_Query ) {
	wp_reset_postdata();
}
get_footer();
