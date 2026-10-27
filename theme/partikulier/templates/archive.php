<?php
/**
 * Archive : annonces, taxonomies (type, action, ville...), recherche.
 *
 * Layout calque sur la page Shop du kit "Woo Shop" :
 * sidebar gauche "Filtres" (prix, type, action, villes) + zone contenu
 * avec compteur "Affichage 1-X de N resultats" et tri a droite.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}
get_header();

// Contexte geo pour le titre SEO.
$queried = get_queried_object();
$is_geo  = $queried instanceof WP_Term && in_array( $queried->taxonomy, Partikulier_Setup::GEO_TAX, true );
$is_type = $queried instanceof WP_Term && PARTIKULIER_ESTATIK_TYPE_TAXONOMY === $queried->taxonomy;
?>

<section class="pk-archive">
		<style>
		@media (max-width:767px){
			.pk-chosen{flex-wrap:nowrap!important}
			.pk-chosen-list,.pk-quartiers{display:flex!important;flex-wrap:nowrap!important;overflow-x:auto!important;scrollbar-width:none}
			.pk-chosen-chip,.pk-chosen-clear,.pk-quartier,.pk-quartiers-more{flex:0 0 auto!important}
			.pk-quartiers-open:checked ~ .pk-quartiers-sheet{display:flex!important;position:fixed!important;inset:0!important;z-index:1600!important;align-items:flex-end!important;justify-content:center!important}
			.pk-quartiers-backdrop{position:absolute;inset:0;background:rgba(22,23,21,.48)}
			.pk-quartiers-sheet-card{position:relative;z-index:1;display:flex;flex-direction:column;width:min(560px,100%);max-height:min(78vh,640px);background:#fff;border-radius:16px 16px 0 0;overflow:hidden}
			.pk-quartiers-sheet-head{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:.9rem 1rem;border-bottom:1px solid #eceae4}
			.pk-quartiers-sheet-head h2{margin:0;font-size:1.05rem}
			.pk-quartiers-sheet-list{overflow-y:auto!important;max-height:58vh;-webkit-overflow-scrolling:touch}
			.pk-quartier-row{display:flex;align-items:center;min-height:48px;padding:.75rem 1rem;border-bottom:1px solid #eceae4;color:#161715;text-decoration:none;font-weight:700}
			.pk-quartiers-sheet-less{display:block;padding:.9rem 1rem;color:#FF5757;font-weight:700;text-align:center}
			.pk-quartiers-more{color:#FF5757;font-weight:700}
			.pk-quartiers-more .pk-less{display:none}
			.pk-quartiers-open:checked ~ .pk-quartiers-more .pk-more{display:none}
			.pk-quartiers-open:checked ~ .pk-quartiers-more .pk-less{display:inline}

			.pk-header-search,.pk-archive>.pk-container>.pk-breadcrumb,.pk-intent-bar,.pk-intent-sentinel,.pk-archive-toolbar{display:none!important}
			.pk-archive-head,.pk-archive-search,.pk-archive-search .pk-search,.pk-archive-search .pk-archive-trust{display:block!important}
			.pk-archive-search .pk-archive-trust{display:flex!important}
			.pk-filter-toggle{display:none!important}
			.pk-archive>.pk-container{padding-inline:16px}
			.pk-archive-layout{display:block!important;width:100%}
			.pk-archive-content,.pk-archive-content .pk-grid.pk-grid-cards{width:100%!important;max-width:100%!important;margin-inline:auto!important}
			.pk-archive-content .pk-grid.pk-grid-cards{display:grid!important;grid-template-columns:minmax(0,1fr)!important}
			.pk-archive-content .pk-card{width:100%!important;max-width:100%!important;margin-inline:auto!important}
		}
		@media (min-width:768px){
			.pk-quartiers{display:flex;flex-wrap:nowrap;overflow:hidden;max-height:40px}
			.pk-quartiers-open:checked ~ .pk-quartiers{flex-wrap:wrap!important;overflow:visible!important;max-height:none!important}
			.pk-quartiers-sheet{display:none!important}
			.pk-quartiers-more{color:#FF5757;font-weight:700;cursor:pointer}
			.pk-quartiers-more .pk-less{display:none}
			.pk-quartiers-open:checked ~ .pk-quartiers-more .pk-more{display:none}
			.pk-quartiers-open:checked ~ .pk-quartiers-more .pk-less{display:inline}
			.pk-intent-bar,.pk-intent-sentinel,.pk-filter-toggle,.pk-filter-close,.pk-archive .pk-filters{display:none!important}
			.pk-archive-layout{display:block!important;width:100%!important;max-width:100%!important;grid-template-columns:minmax(0,1fr)!important}
			.pk-archive-content,.pk-archive-content .pk-grid.pk-grid-cards{width:100%!important;max-width:100%!important}
		}
		@media (min-width:901px){
			.pk-archive-content .pk-grid.pk-grid-cards{display:grid!important;grid-template-columns:repeat(3,minmax(0,1fr))!important}
		}
		@media (min-width:768px) and (max-width:900px){
			.pk-archive-content .pk-grid.pk-grid-cards{display:grid!important;grid-template-columns:repeat(2,minmax(0,1fr))!important}
		}
		</style>
		<div class="pk-container">
                <?php echo Partikulier_Geo::breadcrumbs_html(); // phpcs:ignore ?>

				<?php
				$pk_intent_action = isset( $_GET['es_action'] ) && is_scalar( $_GET['es_action'] ) ? sanitize_text_field( wp_unslash( $_GET['es_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$pk_intent_rent   = isset( $_GET['pk_rent'] ) && is_scalar( $_GET['pk_rent'] ) ? sanitize_text_field( wp_unslash( $_GET['pk_rent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				$pk_intent_keep   = array();
				foreach ( array( 'es_type', 'es_city', 'es_price_max', 'pk_order' ) as $pk_intent_key ) {
					if ( isset( $_GET[ $pk_intent_key ] ) && ! is_array( $_GET[ $pk_intent_key ] ) && '' !== (string) $_GET[ $pk_intent_key ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput
						$pk_intent_keep[ $pk_intent_key ] = sanitize_text_field( wp_unslash( $_GET[ $pk_intent_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
					}
				}
				$pk_intent_base = pk_properties_archive_url();
				if ( 'a-vendre' !== $pk_intent_action ) :
					$pk_intent_items = ( 'a-louer' === $pk_intent_action )
						? array(
							array(
								'href'   => add_query_arg( array_merge( $pk_intent_keep, array( 'es_action' => 'a-louer' ) ), $pk_intent_base ),
								'label'  => 'Longue durée',
								'btn'    => 'pk-btn-primary',
								'active' => ( 'saisonnier' !== $pk_intent_rent ),
							),
							array(
								'href'   => add_query_arg( array_merge( $pk_intent_keep, array( 'es_action' => 'a-louer', 'pk_rent' => 'saisonnier' ) ), $pk_intent_base ),
								'label'  => 'Saisonnière',
								'btn'    => 'pk-btn-light',
								'active' => ( 'saisonnier' === $pk_intent_rent ),
							),
						)
						: array(
							array(
								'href'   => add_query_arg( array_merge( $pk_intent_keep, array( 'es_action' => 'a-vendre' ) ), $pk_intent_base ),
								'label'  => 'Vente',
								'btn'    => 'pk-btn-primary',
								'active' => false,
							),
							array(
								'href'   => add_query_arg( array_merge( $pk_intent_keep, array( 'es_action' => 'a-louer' ) ), $pk_intent_base ),
								'label'  => 'Location',
								'btn'    => 'pk-btn-light',
								'active' => false,
							),
						);
					?>
				<div class="pk-intent-sentinel" aria-hidden="true"></div>
				<nav class="pk-intent-bar" aria-label="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Type de transaction', 'Type de transaction', 'partikulier' ) ); ?>">
					<?php foreach ( $pk_intent_items as $pk_intent_item ) : ?>
						<a class="pk-btn <?php echo esc_attr( ! empty( $pk_intent_item['btn'] ) ? $pk_intent_item['btn'] : 'pk-btn-primary' ); ?>" href="<?php echo esc_url( $pk_intent_item['href'] ); ?>"<?php echo $pk_intent_item['active'] ? ' aria-current="page"' : ''; ?>><?php echo esc_html( __( $pk_intent_item['label'], 'partikulier' ) ); ?></a>
					<?php endforeach; ?>
				</nav>
				<?php endif; ?>

				<header class="pk-archive-head">
						<p class="pk-editorial-kicker"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Le catalogue direct', 'Le catalogue direct', 'partikulier' ) ); ?></p>
						<h1 class="pk-archive-title"><?php
				if ( is_search() ) {
					printf( esc_html__( 'Résultats pour « %s »', 'partikulier' ), esc_html( get_search_query() ) );
				} elseif ( $is_geo ) {
					printf( esc_html__( 'Annonces immobilières à %s', 'partikulier' ), esc_html( $queried->name ) );
				} elseif ( $is_type ) {
					printf( esc_html__( '%s à vendre et à louer', 'partikulier' ), esc_html( $queried->name ) );
				} elseif ( is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) || ( is_page() && 'annonces' === get_post_field( 'post_name' ) ) ) {
					esc_html_e( 'Toutes les annonces', 'partikulier' );
				} else {
					single_term_title();
				}
				?></h1>
						<p class="pk-archive-subtitle"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Des biens publiés par leurs propriétaires identifiés.', 'Des biens publiés par leurs propriétaires identifiés.', 'partikulier' ) ); ?></p>
				</header>
				<div class="pk-archive-search">
						<?php $variant = 'archive'; require PARTIKULIER_DIR . '/templates/parts/search-form.php'; ?>
						<ul class="pk-archive-trust"><li><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Localisation d’abord', 'Localisation d’abord', 'partikulier' ) ); ?></li><li><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Vendeur identifié', 'Vendeur identifié', 'partikulier' ) ); ?></li><li><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Contact direct', 'Contact direct', 'partikulier' ) ); ?></li></ul>
				</div>





						<?php
						$pk_active_filters = 0;
						foreach ( array( 'es_type', 'es_city', 'es_price_max' ) as $pk_filter_key ) {
							if ( isset( $_GET[ $pk_filter_key ] ) && '' !== trim( (string) wp_unslash( $_GET[ $pk_filter_key ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- présence/cast, valeur assainie (SE-020/E-2007)
									$pk_active_filters++;
							}
						}
						$pk_filters_label = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Filtres', 'Filtres', 'partikulier' ) : __( 'Filtres', 'partikulier' );
						?>
										<div class="pk-archive-layout">
														<button type="button" class="pk-filter-toggle" aria-expanded="false" aria-controls="pk-filters-panel">
																<span class="pk-filter-toggle-label"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Affiner la recherche', 'Affiner la recherche', 'partikulier' ) ); ?></span>
																<span class="pk-filter-toggle-meta"><span class="pk-filter-count"><?php echo esc_html( $pk_active_filters ); ?></span> <?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Filtres actifs', 'Filtres actifs', 'partikulier' ) ); ?></span>
																<span class="pk-filter-toggle-icon" aria-hidden="true">+</span>
														</button>
														<aside class="pk-filters" aria-label="<?php echo esc_attr( $pk_filters_label ); ?>">
														<div class="pk-filters-heading">
																<h2><?php echo esc_html( $pk_filters_label ); ?></h2>
																<span><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Affiner la recherche', 'Affiner la recherche', 'partikulier' ) ); ?></span>
														</div>
										<div class="pk-filters-backdrop" data-pk-filter-close="true" hidden></div>
												<div class="pk-filters-panel" id="pk-filters-panel" aria-hidden="false">
												<div class="pk-filter-sheet-head">
					<h2><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Affiner la recherche', 'Affiner la recherche', 'partikulier' ) ); ?></h2>
					<button type="button" class="pk-filter-close" data-pk-filter-close="true" aria-label="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Fermer', 'Fermer', 'partikulier' ) ); ?>"><span aria-hidden="true">×</span></button>
				</div>

														<?php
														/* E-5105 (F-T17-2, lot sécurité 6.20.6) : garde scalaire. */
					$pk_filter_city_slug  = isset( $_GET['es_city'] ) && is_scalar( $_GET['es_city'] ) ? sanitize_title( wp_unslash( $_GET['es_city'] ) ) : '';
														$pk_filter_city_term  = $pk_filter_city_slug ? get_term_by( 'slug', $pk_filter_city_slug, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) : false;
														$pk_filter_city_label = ( $pk_filter_city_term && ! is_wp_error( $pk_filter_city_term ) ) ? $pk_filter_city_term->name : '';
														?>
														<div class="pk-filter pk-filter-city">
																<h3 class="pk-filter-title"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Ville ou quartier', 'Ville ou quartier', 'partikulier' ) ); ?></h3>
														<form action="<?php echo esc_url( pk_properties_archive_url() ); ?>" method="get" id="pk-filter-city-form" class="pk-filter-city-form pk-place-autocomplete">
																<label class="screen-reader-text" for="pk-filter-city-input"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Ville ou quartier', 'Ville ou quartier', 'partikulier' ) ); ?></label>
																<?php foreach ( array( 'es_action', 'es_type', 'es_price_max', 'pk_order' ) as $pk_preserve_key ) : ?>
																		<?php if ( isset( $_GET[ $pk_preserve_key ] ) && ! is_array( $_GET[ $pk_preserve_key ] ) ) : ?>
																				<input type="hidden" name="<?php echo esc_attr( $pk_preserve_key ); ?>" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET[ $pk_preserve_key ] ) ) ); ?>">
																		<?php endif; ?>
																<?php endforeach; ?>
																<div class="pk-place-autocomplete-wrap">
																		<?php /* name="s" : secours sans JavaScript, desactive par le JS quand es_city est fixe. */ ?>
																		<input id="pk-filter-city-input" name="s" class="pk-place-input" type="search" data-pk-place-input="true" data-pk-place-value="pk-filter-city-value" autocomplete="off" aria-controls="pk-filter-city-suggestions" aria-autocomplete="list" placeholder="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Ville ou quartier', 'Ville ou quartier', 'partikulier' ) ); ?>" value="<?php echo esc_attr( $pk_filter_city_label ); ?>">
																				<input type="hidden" name="es_city" id="pk-filter-city-value" value="<?php echo esc_attr( $pk_filter_city_slug ); ?>">
																		<ul id="pk-filter-city-suggestions" class="pk-suggest pk-place-suggestions" role="listbox" hidden></ul>
																</div>
																<button type="submit" class="pk-filter-apply"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Appliquer', 'Appliquer', 'partikulier' ) ); ?></button>
														</form>
														</div>

														<div class="pk-filter pk-filter-actions">
														<h3 class="pk-filter-title"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Transaction', 'Transaction', 'partikulier' ) ); ?></h3>
														<ul class="pk-filter-list pk-filter-action-list">
																<?php
															/* Revue user 08/10 : pas d'entrée « Tout / الكل » —
															   l'absence de paramètre es_action EST l'état « tout ». */
															$action_items       = array(
																array( 'value' => 'a-vendre', 'label' => 'Vendre' ),
																array( 'value' => 'a-louer', 'label' => 'Louer' ),
															);
																$pk_cumulative_args = array();
																foreach ( array( 'es_action', 'es_type', 'es_city', 'es_price_max', 'pk_order' ) as $pk_query_key ) {
																	if ( isset( $_GET[ $pk_query_key ] ) && ! is_array( $_GET[ $pk_query_key ] ) && '' !== (string) $_GET[ $pk_query_key ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput -- présence/cast, valeur assainie (SE-020/E-2007)
																			$pk_cumulative_args[ $pk_query_key ] = sanitize_text_field( wp_unslash( $_GET[ $pk_query_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
																	}
																}
																foreach ( $action_items as $action_item ) :
																		$action_args = $pk_cumulative_args;
																	if ( $action_item['value'] ) {
																			$action_args['es_action'] = $action_item['value'];
																	} else {
																			unset( $action_args['es_action'] );
																	}
																	$pk_prev_action = isset( $pk_cumulative_args['es_action'] ) ? (string) $pk_cumulative_args['es_action'] : '';
																	if ( (string) $action_item['value'] !== $pk_prev_action ) {
																			unset( $action_args['es_price_max'] );
																	}
																		$action_url = add_query_arg( $action_args, pk_properties_archive_url() );
																		/* 6.17.30 : laisser passer gettext — l'appel direct
																		 * translate_polylang_string( $label, $label ) court-circuitait
																		 * le catalogue .mo (Vendre → بيع, Louer → كراء). */
																		$action_label = __( $action_item['label'], 'partikulier' );
																	?>
																				<li><a href="<?php echo esc_url( $action_url ); ?>"><?php echo esc_html( $action_label ); // nosemgrep: php.lang.security.injection.echoed-request.echoed-request ?></a></li>
																<?php endforeach; ?>
														</ul>
										</div>

										<div class="pk-filter pk-filter-types">
												<h3 class="pk-filter-title"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Types de biens', 'Types de biens', 'partikulier' ) ); ?></h3>
										<?php
										$types = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY, 'hide_empty' => true ) );
										if ( $types && ! is_wp_error( $types ) ) {
												echo '<ul class="pk-filter-list">';
											/* Revue user 08/10 : lien « Tous » retiré des facettes types. */
											$pk_facet_counts = class_exists( 'Partikulier_Search_Filters' ) ? Partikulier_Search_Filters::facet_type_counts() : array();
											foreach ( $types as $term ) {
													$active                       = $queried instanceof WP_Term && $queried->term_id === $term->term_id ? ' aria-current="true"' : '';
															$type_args            = isset( $pk_cumulative_args ) ? $pk_cumulative_args : array();
															$type_args['es_type'] = $term->slug;
															printf(
																	'<li><a href="%1$s"%3$s>%2$s <span class="pk-filter-count">(%4$s)</span></a></li>',
																	esc_url( add_query_arg( $type_args, pk_properties_archive_url() ) ),
													esc_html( Partikulier_Localization::translate_taxonomy_label( $term->name ) ),
													$active, /* phpcs:ignore WordPress.Security.EscapeOutput -- ' aria-current="true"' ou '' : constante interne (SE-020) */
													esc_html( number_format_i18n( isset( $pk_facet_counts[ $term->term_id ] ) ? (int) $pk_facet_counts[ $term->term_id ] : 0 ) )
															);
											}
												echo '</ul>';
										}
										?>
								</div>


<?php
$pk_budget_action = isset( $pk_cumulative_args['es_action'] ) ? (string) $pk_cumulative_args['es_action'] : '';
if ( in_array( $pk_budget_action, array( 'a-vendre', 'a-louer' ), true ) ) :
	$pk_budget_sale    = array( 100000, 200000, 300000, 400000, 500000, 750000, 1000000, 1500000 );
	$pk_budget_rent    = array( 3000, 4000, 5000, 7000, 10000, 15000, 20000, 30000 );
	$pk_budget_renting = ( 'a-louer' === $pk_budget_action );
	$pk_budget_values  = $pk_budget_renting ? $pk_budget_rent : $pk_budget_sale;
	/* Revue user 08/10 : devise via le filtre partikulier_currency (درهم en AR)
	   et unité par langue ; le nombre est isolé en LTR pour le bidi. */
	$pk_budget_lang    = function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr';
	$pk_budget_cur     = apply_filters( 'partikulier_currency', 'DH' );
	$pk_budget_suffix  = ' ' . $pk_budget_cur . ( $pk_budget_renting ? ( 'ar' === $pk_budget_lang ? ' / شهر' : ( 'en' === $pk_budget_lang ? '/month' : ' / mois' ) ) : '' );
	$pk_budget_current = isset( $pk_cumulative_args['es_price_max'] ) ? (string) $pk_cumulative_args['es_price_max'] : '';
	$pk_budget_clear   = $pk_cumulative_args;
	unset( $pk_budget_clear['es_price_max'] );
	?>
						<div class="pk-filter pk-filter-budget">
								<h3 class="pk-filter-title"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Budget max', 'Budget max', 'partikulier' ) ); ?></h3>
								<ul class="pk-filter-list">
									<li><a href="<?php echo esc_url( add_query_arg( $pk_budget_clear, pk_properties_archive_url() ) ); ?>"<?php echo '' === $pk_budget_current ? ' aria-current="true"' : ''; ?>><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Illimité', 'Illimité', 'partikulier' ) ); ?></a></li>
<?php
foreach ( $pk_budget_values as $pk_budget_n ) :
	$pk_budget_args                 = $pk_cumulative_args;
	$pk_budget_args['es_price_max'] = (string) $pk_budget_n;
	$pk_budget_active               = ( (string) $pk_budget_n === $pk_budget_current ) ? ' aria-current="true"' : '';
	printf(
		'<li><a href="%1$s"%3$s><span dir="ltr">%4$s</span>%5$s</a></li>',
		esc_url( add_query_arg( $pk_budget_args, pk_properties_archive_url() ) ),
		'',
		$pk_budget_active, /* phpcs:ignore WordPress.Security.EscapeOutput -- constante interne aria-current */
		esc_html( function_exists( 'pk_format_amount' ) ? pk_format_amount( $pk_budget_n ) : (string) $pk_budget_n ),
		esc_html( $pk_budget_suffix )
	);
endforeach;
?>
								</ul>
						</div>
<?php endif; ?>

<div class="pk-filter-footer">
															<button type="submit" form="pk-filter-city-form" class="pk-filter-apply"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Appliquer', 'Appliquer', 'partikulier' ) ); ?></button>
														</div>
														<script>
														(function () {
																var toggle = document.querySelector('.pk-filter-toggle');
																var panel = document.getElementById('pk-filters-panel');
																var backdrop = document.querySelector('.pk-filters-backdrop');
																if (!toggle || !panel) return;
																function isMobile() { return window.matchMedia && window.matchMedia('(max-width: 767px)').matches; }
																function apply(open) {
																		var mobile = isMobile();
																		var visible = mobile ? !!open : true;
																		panel.classList.toggle('is-open', mobile && visible);
																		panel.setAttribute('aria-hidden', (mobile && !visible) ? 'true' : 'false');
																		if ('inert' in panel) panel.inert = mobile && !visible;
																		else if (mobile && !visible) panel.setAttribute('inert', '');
																		else panel.removeAttribute('inert');
																		toggle.setAttribute('aria-expanded', mobile && visible ? 'true' : 'false');
																		if (backdrop) { backdrop.hidden = !(mobile && visible); backdrop.classList.toggle('is-open', mobile && visible); }
																		document.body.classList.toggle('pk-filters-open', mobile && visible);
																		
																}
																apply(isMobile() && panel.classList.contains('is-open'));
																window.addEventListener('resize', function () { apply(panel.classList.contains('is-open')); });
																window.pkEarlyFilterApply = apply;
																window.pkEarlyFilterClick = function (event) {
																		var target = event.target.closest ? event.target.closest('.pk-filter-toggle, [data-pk-filter-close]') : null;
																		if (!target) return;
																		event.preventDefault();
																		apply(target.matches('.pk-filter-toggle') ? !panel.classList.contains('is-open') : false);
																};
																window.pkEarlyFilterKeydown = function (event) {
																		if (event.key === 'Escape' && panel.classList.contains('is-open')) { event.preventDefault(); apply(false); }
																};
																document.addEventListener('click', window.pkEarlyFilterClick, true);
																document.addEventListener('keydown', window.pkEarlyFilterKeydown, true);
														}());
														</script>
														</div>
								</aside>

								<div class="pk-archive-content">
								<?php require PARTIKULIER_DIR . '/templates/parts/chosen-filters.php'; ?>
								<?php if ( have_posts() ) : ?>
										<div class="pk-grid pk-grid-cards">
												<?php
												$pk_card_index = 0;
												while ( have_posts() ) :
														the_post();
														$pk_card_index++;
														$property = get_post();
														require PARTIKULIER_DIR . '/templates/parts/card-property.php';
												endwhile;
												?>
										</div>
										<?php pk_pagination(); ?>
								<?php else : ?>
										<?php require PARTIKULIER_DIR . '/templates/parts/empty-results.php'; ?>
								<?php endif; ?>
						</div>
				</div>
		</div>
</section>

<?php
$pk_sort_now = isset( $_GET['pk_order'] ) && is_scalar( $_GET['pk_order'] ) ? sanitize_key( wp_unslash( $_GET['pk_order'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
if ( ! in_array( $pk_sort_now, array( 'price-asc', 'price-desc' ), true ) ) {
	$pk_sort_now = 'recent';
}
$pk_sort_label = static function ( $fr ) {
	return class_exists( 'Partikulier_Localization' )
		? Partikulier_Localization::translate_polylang_string( $fr, $fr, 'partikulier' )
		: $fr;
};
$pk_sort_rows = array(
	'recent'     => 'Plus récentes',
	'price-asc'  => 'Prix croissant',
	'price-desc' => 'Prix décroissant',
);
$pk_sort_base = remove_query_arg( array( 'pk_order', 'paged' ) );
?>
<div class="pk-scroll-actions pk-archive-actions" data-pk-archive-actions hidden>
	<?php require PARTIKULIER_DIR . '/templates/parts/filter-chips.php'; ?>
</div>
<?php
$pk_chip_lang  = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
$pk_sort_float = ( 'ar' === $pk_chip_lang ) ? 'ترتيب' : $pk_sort_label( 'Trier les annonces' );
?>
<button type="button" class="pk-sort-float" data-pk-open-sort>
	<svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M8 7v10M8 7l-3 3M8 7l3 3M16 17V7M16 17l-3-3M16 17l3-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
	<span><?php echo esc_html( $pk_sort_float ); ?></span>
</button>
<div class="pk-sort" data-pk-sort hidden>
	<button type="button" class="pk-sort-backdrop" data-pk-sort-close aria-label="<?php echo esc_attr( $pk_sort_label( 'Fermer' ) ); ?>"></button>
	<div class="pk-sort-card" role="dialog" aria-modal="true" aria-labelledby="pk-sort-title">
		<div class="pk-sort-head">
			<h2 id="pk-sort-title"><?php echo esc_html( $pk_sort_label( 'Trier les annonces' ) ); ?></h2>
			<button type="button" class="pk-sort-close" data-pk-sort-close aria-label="<?php echo esc_attr( $pk_sort_label( 'Fermer' ) ); ?>">×</button>
		</div>
		<ul class="pk-sort-list">
			<?php foreach ( $pk_sort_rows as $pk_sort_key => $pk_sort_fr ) : ?>
				<?php
				$pk_sort_href = '' === $pk_sort_key ? $pk_sort_base : add_query_arg( 'pk_order', $pk_sort_key, $pk_sort_base );
				$pk_sort_on   = $pk_sort_now === $pk_sort_key;
				?>
			<li>
				<a href="<?php echo esc_url( $pk_sort_href ); ?>"<?php echo $pk_sort_on ? ' class="is-current" aria-current="true"' : ''; ?>>
					<span><?php echo esc_html( $pk_sort_label( $pk_sort_fr ) ); ?></span>
					<svg class="pk-sort-chevron" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path d="M9 6l6 6-6 6" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
				</a>
			</li>
			<?php endforeach; ?>
		</ul>
	</div>
</div>

<?php
get_footer();
