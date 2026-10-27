<?php
/**
 * Aucun résultat : symbole, phrase, annonces proches des critères.
 *
 * @package Partikulier
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pk_lang = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
if ( 'ar' === $pk_lang ) {
	$pk_empty = 'لا يوجد عقارات تتوافق مع معايير بحثك في الوقت الحالي. يُرجى تعديل فلاتر البحث لإجراء بحثٍ آخر.';
	$pk_reco  = 'إعلانات ننصح بها';
} else {
	$pk_empty = class_exists( 'Partikulier_Localization' )
		? Partikulier_Localization::translate_polylang_string( 'Aucune annonce ne correspond à votre recherche.', 'Aucune annonce ne correspond à votre recherche.', 'partikulier' )
		: 'Aucune annonce ne correspond à votre recherche.';
	$pk_reco = class_exists( 'Partikulier_Localization' )
		? Partikulier_Localization::translate_polylang_string( 'Les dernières annonces', 'Les dernières annonces', 'partikulier' )
		: 'Les dernières annonces';
}
$pk_empty_more = ( 'ar' === $pk_lang ) ? '' : (
	class_exists( 'Partikulier_Localization' )
		? Partikulier_Localization::translate_polylang_string( 'Essayez d\'élargir vos critères ou consultez les villes populaires.', 'Essayez d\'élargir vos critères ou consultez les villes populaires.', 'partikulier' )
		: 'Essayez d\'élargir vos critères ou consultez les villes populaires.'
);

$pk_scalar = static function ( $key ) {
	if ( ! isset( $_GET[ $key ] ) || ! is_scalar( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return '';
	}
	return sanitize_title( wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
};
$pk_clause = static function ( $taxonomy, $slug ) {
	$slug = sanitize_title( (string) $slug );
	if ( '' === $slug || ! taxonomy_exists( $taxonomy ) ) {
		return null;
	}
	$term = get_term_by( 'slug', $slug, $taxonomy );
	if ( ! $term || is_wp_error( $term ) ) {
		return null;
	}
	return array(
		'taxonomy'         => $taxonomy,
		'field'            => 'term_id',
		'terms'            => array( (int) $term->term_id ),
		'include_children' => true,
	);
};
$pk_city_tax = $pk_clause( PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, $pk_scalar( 'es_city' ) );
$pk_type_tax = $pk_clause( PARTIKULIER_ESTATIK_TYPE_TAXONOMY, $pk_scalar( 'es_type' ) );
$pk_tries    = array();
if ( $pk_city_tax && $pk_type_tax ) {
	$pk_tries[] = array( $pk_city_tax, $pk_type_tax );
}
if ( $pk_city_tax ) {
	$pk_tries[] = array( $pk_city_tax );
}
if ( $pk_type_tax ) {
	$pk_tries[] = array( $pk_type_tax );
}
$pk_tries[] = array();

$pk_nearby = array();
foreach ( $pk_tries as $pk_tax ) {
	$pk_args = array(
		'post_type'              => PARTIKULIER_ESTATIK_POST_TYPE,
		'post_status'            => 'publish',
		'posts_per_page'         => 6,
		'no_found_rows'          => true,
		'suppress_filters'       => false,
		'orderby'                => array( 'date' => 'DESC', 'ID' => 'DESC' ),
		'update_post_term_cache' => true,
		'update_post_meta_cache' => true,
	);
	if ( class_exists( 'Partikulier_Dashboard' ) ) {
		$pk_args['meta_query'] = Partikulier_Dashboard::active_listing_meta_query();
	}
	if ( $pk_tax ) {
		$pk_args['tax_query'] = $pk_tax;
	}
	$pk_nearby = get_posts( $pk_args );
	if ( $pk_nearby ) {
		break;
	}
}
?>
<div class="pk-empty pk-empty-search">
	<div class="pk-empty-mark" aria-hidden="true">
		<svg viewBox="0 0 24 24" width="32" height="32" focusable="false">
			<circle cx="11" cy="11" r="6.5" fill="none" stroke="currentColor" stroke-width="1.8"></circle>
			<path d="M16 16.5L20 20.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"></path>
		</svg>
	</div>
	<h2><?php echo esc_html( $pk_empty ); ?></h2>
	<?php if ( '' !== $pk_empty_more ) : ?>
		<p><?php echo esc_html( $pk_empty_more ); ?></p>
	<?php endif; ?>
</div>
<?php if ( $pk_nearby ) : ?>
	<section class="pk-reco" aria-label="<?php echo esc_attr( $pk_reco ); ?>">
		<h2><?php echo esc_html( $pk_reco ); ?></h2>
		<div class="pk-grid pk-grid-cards">
			<?php
			$pk_card_index = 0;
			foreach ( $pk_nearby as $property ) :
				$pk_card_index++;
				require PARTIKULIER_DIR . '/templates/parts/card-property.php';
			endforeach;
			?>
		</div>
	</section>
<?php else : ?>
	<p class="pk-empty-fallback">
		<a class="pk-btn pk-btn-outline" href="<?php echo esc_url( pk_properties_archive_url() ); ?>">
			<?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Voir toutes les annonces', 'Voir toutes les annonces', 'partikulier' ) : 'Voir toutes les annonces' ); ?>
		</a>
	</p>
<?php endif; ?>
