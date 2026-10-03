<?php
/**
 * Template : Mes annonces (espace proprietaire).
 *
 * Connexion obligatoire. Affiche les annonces de l'utilisateur,
 * leurs vues, et les actions : vendre / louer / reactiver / supprimer.
 *
 * @package Partikulier
 *
 * Template Name: Mes annonces
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

get_header();

// --- Connexion obligatoire (login + retour dashboard) ---
if ( ! is_user_logged_in() ) {
		wp_safe_redirect( pk_login_page_url( get_permalink() ) );
		exit;
}

$current_user = wp_get_current_user();
$my_listings  = get_posts( array(
		'post_type'      => PARTIKULIER_ESTATIK_POST_TYPE,
		'post_status'    => array( 'publish', 'pending', 'draft', 'trash' ),
		'author'         => $current_user->ID,
		'posts_per_page' => -1,
		'orderby'        => 'date',
		'order'          => 'DESC',
		'lang'           => '', // T36 : le tableau de bord propriétaire liste ses annonces TOUTES langues — jamais filtré par la langue de l'interface.
) );

$total_views  = 0;
$total_saves  = 0;
$active_count = 0;
foreach ( $my_listings as $l ) {
		$total_views += (int) get_post_meta( $l->ID, '_pk_views', true );
		$total_saves += class_exists( 'Partikulier_Owner_Insights' ) ? Partikulier_Owner_Insights::favorite_count( $l->ID ) : 0;
		$status       = get_post_meta( $l->ID, '_pk_status', true );
	if ( 'publish' === $l->post_status && ( '' === $status || 'actif' === $status ) ) {
		$active_count++;
	}
}

$status_labels = array(
		'actif'                       => __( 'En ligne', 'partikulier' ),
		'vendu'                       => __( 'Vendu', 'partikulier' ),
		'loue'                        => __( 'Loué', 'partikulier' ),
				'archive'             => __( 'Archivé', 'partikulier' ),
				'pause'               => __( 'En pause', 'partikulier' ),
				'indisponible'        => __( 'Indisponible', 'partikulier' ),
				'refuse'              => __( 'Refusée', 'partikulier' ),
				'en_attente_whatsapp' => __( 'En attente de validation WhatsApp', 'partikulier' ),
);
?>

<section class="pk-dashboard">
		<div class="pk-container">
                <?php echo Partikulier_Geo::breadcrumbs_html(); // phpcs:ignore ?>

				<header class="pk-dashboard-head">
						<p class="pk-editorial-kicker"><?php esc_html_e( 'Espace propriétaire', 'partikulier' ); ?></p>
						<h1 class="pk-dashboard-title">
								<?php
								printf(
										/* translators: %s : nom de l'utilisateur */
										esc_html__( 'Bonjour %s,', 'partikulier' ),
										esc_html( $current_user->display_name )
								);
								?><span class="pk-hero-accent"><?php esc_html_e( 'vos annonces sont en ligne.', 'partikulier' ); ?></span>
						</h1>
						<p class="pk-dashboard-subtitle"><?php esc_html_e( 'Suivez les vues, modifiez les informations essentielles et gardez la main sur vos contacts directs.', 'partikulier' ); ?></p>
						<div class="pk-dashboard-actions">
								<a class="pk-btn pk-btn-outline" href="<?php echo esc_url( pk_properties_archive_url() ); ?>"><?php esc_html_e( 'Rechercher un bien', 'partikulier' ); ?></a>
								<a class="pk-btn pk-btn-primary" href="<?php echo esc_url( pk_page_url( 'deposer', '/deposer/' ) ); ?>">
										<span aria-hidden="true">+</span> <?php esc_html_e( 'Nouvelle annonce', 'partikulier' ); ?>
								</a>
						</div>
				</header>

				<?php
				// Tuiles KPI : icone + valeur + libelle, alignees sur le preview React.
				$pk_kpis = array(
						array(
								'value' => (int) $total_views,
								'label' => __( 'Vues totales', 'partikulier' ),
								'icon'  => '<path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>',
						),
						array(
								'value' => (int) $total_saves,
								'label' => __( 'Ajouts en favoris', 'partikulier' ),
								'icon'  => '<path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/>',
						),
						array(
								'value' => (int) $active_count,
								'label' => __( 'Annonces actives', 'partikulier' ),
								'icon'  => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/>',
						),
						array(
								'value' => (int) count( $my_listings ),
								'label' => __( 'Annonces publiées', 'partikulier' ),
								'icon'  => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
						),
				);
				?>
				<div class="pk-stats-row">
						<?php foreach ( $pk_kpis as $pk_kpi ) : ?>
								<div class="pk-stat">
										<span class="pk-stat-icon" aria-hidden="true">
                                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?php echo $pk_kpi['icon']; // phpcs:ignore ?></svg>
										</span>
										<span class="pk-stat-value"><?php echo esc_html( number_format_i18n( $pk_kpi['value'] ) ); ?></span>
										<span class="pk-stat-label"><?php echo esc_html( $pk_kpi['label'] ); ?></span>
								</div>
						<?php endforeach; ?>
				</div>

				<?php if ( ! $my_listings ) : ?>
						<div class="pk-dashboard-empty">
								<p><?php esc_html_e( 'Vous n’avez pas encore d’annonce. C’est gratuit et ça prend 2 minutes !', 'partikulier' ); ?></p>
								<a class="pk-btn pk-btn-primary" href="<?php echo esc_url( pk_page_url( 'deposer', '/deposer/' ) ); ?>"><?php esc_html_e( 'Déposer ma première annonce', 'partikulier' ); ?></a>
						</div>
				<?php else : ?>
						<div class="pk-listing-table-wrap">
								<ul class="pk-listing-list">
										<?php foreach ( $my_listings as $listing ) :
												$status        = get_post_meta( $listing->ID, '_pk_status', true );
												$status        = ( '' === $status || 'actif' === $status ) ? 'actif' : $status;
														$views = (int) get_post_meta( $listing->ID, '_pk_views', true );
														$saves = class_exists( 'Partikulier_Owner_Insights' ) ? Partikulier_Owner_Insights::favorite_count( $listing->ID ) : 0;
												$trashed       = 'trash' === $listing->post_status;
												$closed        = in_array( $status, array( 'vendu', 'loue', 'archive', 'indisponible' ), true );
											?>
										<li class="pk-listing-item<?php echo $trashed ? ' pk-listing-trashed' : ''; ?>">
												<div class="pk-listing-media">
														<?php
																$thumb = get_the_post_thumbnail( $listing->ID, 'pk-card', array( 'loading' => 'lazy', 'decoding' => 'async' ) );
                                                                echo $thumb ? $thumb : '<span class="pk-card-placeholder" aria-hidden="true"><svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.6V21h14V9.6"/><path d="M9.5 21v-6h5v6"/></svg></span>'; // phpcs:ignore
														if ( $closed ) {
																$watermark = 'vendu' === $status ? __( 'Vendu', 'partikulier' ) : ( 'loue' === $status ? __( 'Loué', 'partikulier' ) : ( 'indisponible' === $status ? __( 'Indisponible', 'partikulier' ) : __( 'Archivé', 'partikulier' ) ) );
																echo '<span class="pk-listing-watermark" aria-hidden="true">' . esc_html( $watermark ) . '</span>';
														}
														?>
												</div>
												<div class="pk-listing-info">
														<h2 class="pk-listing-name">
																<?php if ( $trashed ) : ?>
																		<?php echo esc_html( $listing->post_title ); ?>
																<?php else : ?>
																		<a href="<?php echo esc_url( get_permalink( $listing->ID ) ); ?>"><?php echo esc_html( $listing->post_title ); ?></a>
																<?php endif; ?>
														</h2>
														<p class="pk-listing-meta">
																<span class="pk-listing-date"><?php echo esc_html( date_i18n( 'd/m/Y', strtotime( $listing->post_date ) ) ); ?></span>
																		<span class="pk-listing-views" aria-label="<?php esc_attr_e( 'vues', 'partikulier' ); ?>">
																		<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
																				<?php echo (int) $views; ?>
																		</span>
																		<span class="pk-listing-views" aria-label="<?php esc_attr_e( 'favoris anonymisés', 'partikulier' ); ?>">
																				<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M20.8 4.6a5.5 5.5 0 0 0-7.8 0L12 5.7l-1.1-1.1a5.5 5.5 0 0 0-7.8 7.8L12 21l8.9-8.6a5.5 5.5 0 0 0-.1-7.8z"/></svg>
																				<?php echo (int) $saves; ?>
																		</span>
														</p>
														<?php if ( ! $trashed ) : ?>
														<span class="pk-listing-status pk-status-<?php echo esc_attr( $status ); ?>">
																<?php echo esc_html( isset( $status_labels[ $status ] ) ? $status_labels[ $status ] : $status ); ?>
														</span>
														<?php endif; ?>
												</div>
												<div class="pk-listing-actions">
															<?php
															/*
															 * SE-044 / DP-9 — parcours unique (v1.1 §4.1) :
															 * « Désactiver mon annonce » (motif obligatoire) puis « Réactiver ».
															 * Corbeille et états restreints : aucune action propriétaire (matrice §3.1).
															 */
															if ( $trashed ) : ?>
																<span class="pk-listing-trashed-note"><?php esc_html_e( 'Dans la corbeille — restauration par l’équipe uniquement', 'partikulier' ); ?></span>
															<?php elseif ( 'publish' === $listing->post_status && 'actif' === $status ) : ?>
																<a class="pk-btn pk-btn-outline pk-btn-sm" href="<?php echo esc_url( add_query_arg( array( 'edit' => (int) $listing->ID ), pk_page_url( 'deposer', '/deposer/' ) ) ); ?>"><?php esc_html_e( 'Modifier', 'partikulier' ); ?></a>
																<button type="button" class="pk-btn pk-btn-dark pk-btn-sm pk-manage-btn" data-post-id="<?php echo (int) $listing->ID; ?>" data-action="deactivate"><?php esc_html_e( 'Désactiver mon annonce', 'partikulier' ); ?></button>
																<div class="pk-deactivate-reason" hidden data-post-id="<?php echo (int) $listing->ID; ?>">
																	<p class="pk-deactivate-title"><?php esc_html_e( 'Motif de la désactivation', 'partikulier' ); ?></p>
																	<label class="pk-reason-option"><input type="radio" name="pk_reason_<?php echo (int) $listing->ID; ?>" value="vendu" required> <?php esc_html_e( 'Vendu', 'partikulier' ); ?></label>
																	<label class="pk-reason-option"><input type="radio" name="pk_reason_<?php echo (int) $listing->ID; ?>" value="loue"> <?php esc_html_e( 'Loué', 'partikulier' ); ?></label>
																	<label class="pk-reason-option"><input type="radio" name="pk_reason_<?php echo (int) $listing->ID; ?>" value="avis"> <?php esc_html_e( 'Je ne souhaite plus vendre ni louer ce bien', 'partikulier' ); ?></label>
																	<label class="pk-reason-option"><input type="radio" name="pk_reason_<?php echo (int) $listing->ID; ?>" value="autre"> <?php esc_html_e( 'Autre (visible par l’équipe uniquement)', 'partikulier' ); ?></label>
																	<label class="pk-reason-note-label" hidden data-for-reason="autre"><?php esc_html_e( 'Précisez (texte privé, jamais publié)', 'partikulier' ); ?><textarea class="pk-reason-note" rows="3" maxlength="500"></textarea></label>
																	<div class="pk-reason-actions">
																		<button type="button" class="pk-btn pk-btn-primary pk-btn-sm pk-manage-btn" data-post-id="<?php echo (int) $listing->ID; ?>" data-action="deactivate_confirm"><?php esc_html_e( 'Confirmer la désactivation', 'partikulier' ); ?></button>
																		<button type="button" class="pk-btn-text pk-reason-cancel"><?php esc_html_e( 'Annuler', 'partikulier' ); ?></button>
																	</div>
																	<p class="pk-reason-hint"><?php esc_html_e( 'Votre annonce est désactivée. Sa page reste en ligne, mais elle n’apparaît plus dans les résultats.', 'partikulier' ); ?></p>
																</div>
															<?php elseif ( $closed && 'publish' === $listing->post_status ) : ?>
																<button type="button" class="pk-btn pk-btn-primary pk-btn-sm pk-manage-btn" data-post-id="<?php echo (int) $listing->ID; ?>" data-action="reactivate"><?php esc_html_e( 'Réactiver mon annonce', 'partikulier' ); ?></button>
															<?php /* R1 § 3.2-1 : l'ancienne branche « draft × pause → Réactiver » est retirée
															 * avec l'exception correspondante de la matrice : aucune republication
															 * propriétaire depuis un état non publié (le bouton conduisait à un
															 * refus 403 systématique depuis le retrait de l'exception). */ ?>
															<?php else : ?>
																<span class="pk-listing-trashed-note"><?php esc_html_e( 'Cette annonce est en cours de traitement par l’équipe et ne peut pas être modifiée pour le moment.', 'partikulier' ); ?></span>
															<?php endif; ?>
												</div>
												<p class="pk-listing-feedback" aria-live="polite"></p>
										</li>
										<?php endforeach; ?>
								</ul>
						</div>
				<?php endif; ?>
		</div>
</section>

<?php
get_footer();