<?php
/**
 * Page publique du prix au mètre carré.
 * Incluse seulement quand Partikulier_Price_Index::is_publicly_served() est vrai.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$pk_price_snapshot = isset( $pk_price_snapshot ) && is_array( $pk_price_snapshot ) ? $pk_price_snapshot : Partikulier_Price_Index::snapshot();
$pk_price_lang     = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
$pk_price_date     = isset( $pk_price_snapshot['computed_at'] ) ? (string) $pk_price_snapshot['computed_at'] : '';
$pk_from_3         = isset( $pk_price_snapshot['from_3'] ) ? (string) $pk_price_snapshot['from_3'] : '';
$pk_from_6         = isset( $pk_price_snapshot['from_6'] ) ? (string) $pk_price_snapshot['from_6'] : '';
$pk_has_seasonal   = false;

get_header();
?>
<main id="main-content" class="pk-price-index">
	<div class="pk-container pk-container-narrow">
		<p class="pk-editorial-kicker">Tuk</p>
		<h1>Prix au mètre carré</h1>
		<p class="pk-price-index__note">Moyenne des annonces Tuk publiées depuis le <?php echo esc_html( $pk_from_3 ); ?> et depuis le <?php echo esc_html( $pk_from_6 ); ?>. Vente, location et location saisonnière sont séparées. Ce n’est pas un prix officiel. Calcul du <?php echo esc_html( $pk_price_date ); ?>.</p>
		<?php if ( empty( $pk_price_snapshot['slices'] ) ) : ?>
			<p>Aucune annonce avec un prix et une surface sur les 6 derniers mois.</p>
		<?php else : ?>
			<table class="pk-price-index__table">
				<thead>
					<tr>
						<th>Ville</th>
						<th>Quartier</th>
						<th>Transaction</th>
						<th>Type</th>
						<th>3 mois</th>
						<th>Moyenne, 3 mois</th>
						<th>6 mois</th>
						<th>Moyenne, 6 mois</th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $pk_price_snapshot['slices'] as $pk_slice ) : ?>
						<?php
						if ( isset( $pk_slice['transaction'] ) && 'saisonnier' === $pk_slice['transaction'] ) {
							$pk_has_seasonal = true;
						}
						$pk_city     = $pk_slice['city'];
						$pk_district = $pk_slice['district'];
						$pk_type     = $pk_slice['type'];
						$pk_tx       = Partikulier_Price_Index::transaction_label( $pk_slice['transaction'] );
						if ( class_exists( 'Partikulier_Listing_I18n' ) ) {
							$pk_city     = Partikulier_Listing_I18n::localized_place( $pk_city, $pk_price_lang );
							$pk_district = '' !== $pk_district ? Partikulier_Listing_I18n::localized_place( $pk_district, $pk_price_lang ) : '';
							$pk_type     = Partikulier_Listing_I18n::localized_type( $pk_type, $pk_price_lang );
						}
						?>
						<tr>
							<td><?php echo esc_html( $pk_city ); ?></td>
							<td><?php echo esc_html( '' !== $pk_district ? $pk_district : '—' ); ?></td>
							<td><?php echo esc_html( $pk_tx ); ?></td>
							<td><?php echo esc_html( $pk_type ); ?></td>
							<td><?php echo (int) $pk_slice['count_3']; ?></td>
							<td><?php echo $pk_slice['count_3'] > 0 ? esc_html( Partikulier_Price_Index::format_amount( $pk_slice['avg_3'], $pk_slice['unit'] ) ) : '—'; ?></td>
							<td><?php echo (int) $pk_slice['count_6']; ?></td>
							<td><?php echo $pk_slice['count_6'] > 0 ? esc_html( Partikulier_Price_Index::format_amount( $pk_slice['avg_6'], $pk_slice['unit'] ) ) : '—'; ?></td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php if ( $pk_has_seasonal ) : ?>
				<p class="pk-price-index__note">Location saisonnière : moyenne du prix publié divisé par la surface. Ce prix n’est pas ramené au mois.</p>
			<?php endif; ?>
		<?php endif; ?>
	</div>
</main>
<?php
get_footer();
