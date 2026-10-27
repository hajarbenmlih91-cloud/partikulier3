<?php
/**
 * Pastilles de filtre mobile. Chaque pastille ouvre la fenêtre déjà en place.
 *
 * @package Partikulier
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
$pk_chip_lang = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
$pk_chip      = static function ( $fr ) use ( $pk_chip_lang ) {
	if ( 'ar' === $pk_chip_lang ) {
		$ar = array(
			'Filtres'      => 'فلتر',
			'À vendre'     => 'للبيع',
			'Type de bien' => 'الكل في سكني',
			'Chambres'     => 'غرف نوم',
			'Prix'         => 'نطاق السعر',
		);
		if ( isset( $ar[ $fr ] ) ) {
			return $ar[ $fr ];
		}
	}
	$out = class_exists( 'Partikulier_Localization' )
		? Partikulier_Localization::translate_polylang_string( $fr, $fr, 'partikulier' )
		: $fr;
	if ( 'fr' === $pk_chip_lang || $out !== $fr ) {
		return $out;
	}
	$extra = array(
		'À vendre' => array( 'en' => 'For sale' ),
		'Prix'     => array( 'en' => 'Price' ),
	);
	return isset( $extra[ $fr ][ $pk_chip_lang ] ) ? $extra[ $fr ][ $pk_chip_lang ] : $fr;
};
$pk_chips = array(
	array( 'label' => $pk_chip( 'Filtres' ), 'icon' => 'filter' ),
	array( 'label' => $pk_chip( 'À vendre' ), 'icon' => '' ),
	array( 'label' => $pk_chip( 'Type de bien' ), 'icon' => '' ),
	array( 'label' => $pk_chip( 'Chambres' ), 'icon' => '' ),
	array( 'label' => $pk_chip( 'Prix' ), 'icon' => '' ),
);
?>
<div class="pk-filter-chips" role="group" aria-label="<?php echo esc_attr( $pk_chip( 'Filtres' ) ); ?>">
	<?php foreach ( $pk_chips as $pk_chip_row ) : ?>
		<button type="button" class="pk-filter-chip" data-pk-open-find>
			<?php if ( 'filter' === $pk_chip_row['icon'] ) : ?>
				<svg class="pk-filter-chip-icon" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"/><circle cx="8" cy="7" r="1.7" fill="currentColor"/><circle cx="15" cy="12" r="1.7" fill="currentColor"/><circle cx="10" cy="17" r="1.7" fill="currentColor"/></svg>
			<?php endif; ?>
			<span><?php echo esc_html( $pk_chip_row['label'] ); ?></span>
			<?php if ( 'filter' !== $pk_chip_row['icon'] ) : ?>
				<svg class="pk-filter-chip-chevron" viewBox="0 0 24 24" width="14" height="14" aria-hidden="true"><path d="M6 9l6 6 6-6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>
			<?php endif; ?>
		</button>
	<?php endforeach; ?>
</div>
