<?php
/**
 * Template : Deposer une annonce (parcours en 3 etapes).
 *
 * Etape 1 : Qui publie l'annonce (role, transaction, type, ville, quartier)
 * Etape 2 : Les informations du bien (surface, prix, pieces, options, photos)
 * Etape 3 : Votre apercu (titre et description rediges automatiquement)
 *
 * Sans jQuery : fetch + FormData.
 *
 * @package Partikulier
 *
 * Template Name: Déposer une annonce
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

$pk_i18n = static function ( $text ) {
	$text = (string) $text;
	if ( class_exists( 'Partikulier_Localization' ) ) {
		return Partikulier_Localization::translate_polylang_string( $text, $text, 'partikulier' );
	}
	return __( $text, 'partikulier' );
};

$edit_id      = isset( $_GET['edit'] ) ? absint( $_GET['edit'] ) : 0;
$editing_post = null;
if ( $edit_id ) {
	if ( ! is_user_logged_in() ) {
			wp_safe_redirect( pk_login_page_url( add_query_arg( 'edit', $edit_id, get_permalink() ) ) );
			exit;
	}
		$editing_post = get_post( $edit_id );
	if ( ! $editing_post || PARTIKULIER_ESTATIK_POST_TYPE !== $editing_post->post_type ) {
			wp_die( esc_html__( 'Cette annonce est introuvable.', 'partikulier' ), esc_html__( 'Annonce introuvable', 'partikulier' ), array( 'response' => 404 ) );
	}
	if ( (int) get_current_user_id() !== (int) $editing_post->post_author && ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Vous n’êtes pas autorisé à modifier cette annonce.', 'partikulier' ), esc_html__( 'Accès refusé', 'partikulier' ), array( 'response' => 403 ) );
	}
}

get_header();

$types = get_terms( array(
		'taxonomy'   => PARTIKULIER_ESTATIK_TYPE_TAXONOMY,
		'hide_empty' => false,
		'orderby'    => 'name',
) );
?>

<section class="pk-submit">

		<div class="pk-submit-hero">
				<div class="pk-container">
						<a class="pk-submit-back" href="<?php echo esc_url( function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' ) ); ?>">
								<span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Retour à l’accueil', 'partikulier' ); ?>
						</a>
						<p class="pk-editorial-kicker"><?php esc_html_e( 'Publication directe', 'partikulier' ); ?></p>
						<h1 class="pk-submit-title">
								<?php if ( $editing_post ) : ?>
										<?php esc_html_e( 'Modifier votre annonce', 'partikulier' ); ?>
								<?php else : ?>
										<?php esc_html_e( 'Votre bien mérite', 'partikulier' ); ?><span class="pk-hero-accent"><?php esc_html_e( 'une annonce claire.', 'partikulier' ); ?></span>
								<?php endif; ?>
						</h1>
						<p class="pk-submit-subtitle">
								<?php esc_html_e( 'Décrivez le bien, choisissez sa localisation et validez votre numéro par WhatsApp. La publication reste gratuite et sans commission.', 'partikulier' ); ?>
						</p>
				</div>
		</div>

		<div class="pk-container pk-submit-body">
				<form class="pk-form pk-steps" id="pk-submit-form" method="post" action="<?php echo esc_url( admin_url( 'admin-ajax.php' ) ); ?>" enctype="multipart/form-data" novalidate>
						<input type="hidden" name="action" value="pk_submit_listing">
														<input type="hidden" name="pk_form_action" value="pk_submit_listing">
								<input type="hidden" name="pk_language" value="<?php echo esc_attr( function_exists( 'pll_current_language' ) ? pll_current_language( 'slug' ) : 'fr' ); ?>">

						<?php if ( $editing_post ) : ?>
								<input type="hidden" name="pk_edit_id" value="<?php echo esc_attr( (string) $editing_post->ID ); ?>">
						<?php endif; ?>
								<?php wp_nonce_field( 'pk_submit_listing', 'nonce' ); ?>
						<?php // SE-034 : clé d'idempotence — unique à chaque affichage du formulaire. ?>
						<input type="hidden" name="pk_idempotency_key" value="<?php echo esc_attr( Partikulier_Form::idempotency_field_value() ); ?>">

								<nav class="pk-stepper" aria-label="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Étapes de publication', 'Étapes de publication', 'partikulier' ) ); ?>">
										<ol class="pk-stepper-list">
												<li class="pk-stepper-item is-current" data-step-indicator="1" aria-current="step"><span class="pk-stepper-number">1</span><span class="pk-stepper-label"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Qui publie l’annonce ?', 'Qui publie l’annonce ?', 'partikulier' ) ); ?></span></li>
												<li class="pk-stepper-item" data-step-indicator="2"><span class="pk-stepper-number">2</span><span class="pk-stepper-label"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Les informations du bien', 'Les informations du bien', 'partikulier' ) ); ?></span></li>
												<li class="pk-stepper-item" data-step-indicator="3"><span class="pk-stepper-number">3</span><span class="pk-stepper-label"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Votre aperçu', 'Votre aperçu', 'partikulier' ) ); ?></span></li>
										</ol>
										<p class="pk-stepper-status" id="pk-stepper-status" role="status" aria-live="polite"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Étape 1', 'Étape 1', 'partikulier' ) ); ?></p>
								</nav>

								<input type="hidden" name="pk_city_name" id="pk-city-name" value="">
						<input type="hidden" name="pk_district_name" id="pk-district-name" value="">
						<input type="hidden" name="pk_action_mode" id="pk-action-mode" value="vendre">

						<!-- ============ ETAPE 1 ============ -->
						<section class="pk-card pk-step" data-step="1">
								<h2 class="pk-card-title"><?php esc_html_e( 'Qui publie l’annonce ?', 'partikulier' ); ?></h2>
								<p class="pk-card-note"><?php esc_html_e( 'Un choix simple pour présenter le bon interlocuteur.', 'partikulier' ); ?></p>

								<div class="pk-field">
										<label class="pk-label"><?php esc_html_e( 'Vous êtes', 'partikulier' ); ?> <span class="pk-req">*</span></label>
										<div class="pk-choice-grid" role="group">
												<label class="pk-choice is-active">
														<input type="radio" name="pk_role" value="proprietaire" checked>
														<strong><?php esc_html_e( 'Propriétaire', 'partikulier' ); ?></strong>
														<span><?php esc_html_e( 'Je publie mon propre bien.', 'partikulier' ); ?></span>
												</label>
												<label class="pk-choice">
														<input type="radio" name="pk_role" value="agent">
														<strong><?php esc_html_e( 'Agent immobilier', 'partikulier' ); ?></strong>
														<span><?php esc_html_e( 'Je publie pour un client.', 'partikulier' ); ?></span>
												</label>
										</div>

										<div class="pk-agent-refusal" id="pk-agent-refusal" role="alert" hidden>
												<p class="pk-agent-refusal-title"><?php esc_html_e( 'Ce site est réservé aux propriétaires', 'partikulier' ); ?></p>
												<p>
														<?php esc_html_e( 'Partikulier met en relation directe les particuliers, sans intermédiaire ni commission. Les annonces déposées par des agences ou des agents immobiliers ne sont pas acceptées.', 'partikulier' ); ?>
												</p>
												<p>
														<?php esc_html_e( 'Si vous êtes le propriétaire du bien, sélectionnez « Propriétaire » pour continuer.', 'partikulier' ); ?>
												</p>
										</div>
								</div>

								<div class="pk-field">
										<label class="pk-label"><?php esc_html_e( 'Je souhaite', 'partikulier' ); ?> <span class="pk-req">*</span></label>
										<div class="pk-choice-grid" role="group">
												<label class="pk-choice is-active">
														<input type="radio" name="pk_transaction" value="vendre" checked>
														<strong><?php esc_html_e( 'Vendre', 'partikulier' ); ?></strong>
														<span><?php esc_html_e( 'Recevoir des contacts d’acheteurs.', 'partikulier' ); ?></span>
												</label>
												<label class="pk-choice">
														<input type="radio" name="pk_transaction" value="louer">
														<strong><?php esc_html_e( 'Louer', 'partikulier' ); ?></strong>
														<span><?php esc_html_e( 'Recevoir des demandes de visite.', 'partikulier' ); ?></span>
												</label>
										</div>
								</div>

								<div data-pk-field="pk_type" class="pk-field">
										<label class="pk-label" for="pk-type"><?php esc_html_e( 'Type de bien', 'partikulier' ); ?> <span class="pk-req">*</span></label>
										<select id="pk-type" name="pk_type" required>
												<?php
												$pk_morocco_types = array(
														'apartments'  => __( 'Appartement', 'partikulier' ),
														'apartment'   => __( 'Appartement', 'partikulier' ),
														'condos'      => __( 'Studio / Appartement', 'partikulier' ),
														'condo'       => __( 'Studio / Appartement', 'partikulier' ),
														'houses'      => __( 'Maison / Villa', 'partikulier' ),
														'house'       => __( 'Maison / Villa', 'partikulier' ),
														'land'        => __( 'Terrain', 'partikulier' ),
														'multifamily' => $pk_i18n( 'Immeuble' ),
														'townhouses'  => __( 'Maison de ville / Riad', 'partikulier' ),
														'townhouse'   => __( 'Maison de ville / Riad', 'partikulier' ),
														'villa'       => __( 'Villa', 'partikulier' ),
														'studio'      => __( 'Studio', 'partikulier' ),
														'riad'        => __( 'Riad', 'partikulier' ),
												);
												foreach ( (array) $types as $term ) :
														$slug_key = strtolower( (string) $term->slug );
														$display_name = $pk_morocco_types[ $slug_key ] ?? $term->name;
														if ( class_exists( 'Partikulier_Listing_I18n' ) && function_exists( 'pll_current_language' ) ) {
																$display_name = Partikulier_Listing_I18n::localized_type( $display_name, pll_current_language( 'slug' ) );
														}
												?>
														<option value="<?php echo esc_attr( $term->term_id ); ?>" data-pk-slug="<?php echo esc_attr( $term->slug ); ?>"><?php echo esc_html( $display_name ); ?></option>
												<?php endforeach; ?>
										</select>
								</div>

								<div data-pk-field="pk_city" class="pk-field pk-autocomplete" id="pk-city-wrap">
										<label class="pk-label" for="pk-city"><?php esc_html_e( 'Ville ou quartier de départ', 'partikulier' ); ?> <span class="pk-req">*</span></label>
										<div class="pk-input-icon">
												<span class="pk-input-pin" aria-hidden="true">
														<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M12 21s-6-5.2-6-10a6 6 0 1 1 12 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.2"/></svg>
												</span>
												<input type="text" id="pk-city" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="pk-city-list" placeholder="<?php esc_attr_e( 'Tapez une ville ou un quartier', 'partikulier' ); ?>">
										</div>
										<ul class="pk-suggest" id="pk-city-list" role="listbox" hidden></ul>
										<small class="pk-field-hint"><?php esc_html_e( 'Commencez par une lettre. Si vous choisissez une ville, un quartier vous sera demandé ensuite. Vous pouvez aussi choisir directement un quartier.', 'partikulier' ); ?></small>
								</div>

								<div data-pk-field="pk_district" class="pk-field pk-autocomplete" id="pk-district-wrap" hidden>
										<label class="pk-label" for="pk-district"><?php esc_html_e( 'Quartier', 'partikulier' ); ?> <span class="pk-req">*</span></label>
												<input type="text" id="pk-district" autocomplete="off" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="pk-district-list" placeholder="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Choisissez un quartier', 'Choisissez un quartier', 'partikulier' ) ); ?>">
										<ul class="pk-suggest" id="pk-district-list" role="listbox" hidden></ul>
								</div>

								<p class="pk-place-missing">
										<button type="button" class="pk-linklike" id="pk-place-missing-toggle">
												<?php esc_html_e( 'Je ne trouve pas ma ville ou mon quartier', 'partikulier' ); ?>
										</button>
								</p>

								<div class="pk-proposal" id="pk-proposal" hidden>
										<p class="pk-proposal-intro">
												<?php esc_html_e( 'Indiquez le lieu exact de votre bien. Il sera vérifié par notre équipe avant d’être ajouté.', 'partikulier' ); ?>
										</p>
										<div class="pk-grid-2">
												<div class="pk-field">
														<label class="pk-label" for="pk-proposed-city"><?php esc_html_e( 'Ville', 'partikulier' ); ?> <span class="pk-req">*</span></label>
														<input type="text" id="pk-proposed-city" name="pk_proposed_city" maxlength="60" placeholder="<?php esc_attr_e( 'Nom de la ville', 'partikulier' ); ?>">
												</div>
												<div class="pk-field">
														<label class="pk-label" for="pk-proposed-district"><?php esc_html_e( 'Quartier', 'partikulier' ); ?></label>
														<input type="text" id="pk-proposed-district" name="pk_proposed_district" maxlength="60" placeholder="<?php esc_attr_e( 'Nom du quartier (facultatif)', 'partikulier' ); ?>">
												</div>
										</div>
										<p class="pk-proposal-warning">
												<?php esc_html_e( 'Attention : votre annonce ne sera mise en ligne qu’après validation de ce lieu par notre équipe. Si vous trouvez votre ville dans la liste ci-dessus, préférez-la : la publication sera immédiate.', 'partikulier' ); ?>
										</p>
								</div>

								<div class="pk-step-actions pk-step-actions-end">
										<button type="button" class="pk-btn pk-btn-primary" data-goto="2">
												<?php esc_html_e( 'Continuer', 'partikulier' ); ?> <span aria-hidden="true">&rarr;</span>
										</button>
								</div>
								<p class="pk-step-legal"><?php esc_html_e( 'Aucune inscription obligatoire. Votre numéro sert uniquement à confirmer que l’annonce vient d’une vraie personne.', 'partikulier' ); ?></p>
						</section>

						<!-- ============ ETAPE 2 ============ -->
						<section class="pk-card pk-step" data-step="2" hidden>
								<h2 class="pk-card-title"><?php esc_html_e( 'Les informations du bien', 'partikulier' ); ?></h2>
								<p class="pk-card-note"><?php esc_html_e( 'Quelques chiffres suffisent pour créer le titre.', 'partikulier' ); ?></p>

								<div class="pk-grid-2">
										<div data-pk-field="pk_surface" class="pk-field">
												<label class="pk-label" for="pk-surface"><?php esc_html_e( 'Superficie (m²)', 'partikulier' ); ?> <span class="pk-req">*</span></label>
												<div class="pk-input-icon">
														<span class="pk-input-pin" aria-hidden="true">
																<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20.6 13.4 10.6 3.4a2 2 0 0 0-1.4-.6H4.8A1.8 1.8 0 0 0 3 4.6v4.4c0 .5.2 1 .6 1.4l10 10a2 2 0 0 0 2.8 0l4.2-4.2a2 2 0 0 0 0-2.8z"/><circle cx="7.5" cy="7.5" r="1.2"/></svg>
														</span>
														<input type="number" id="pk-surface" name="pk_surface" min="1" max="100000" inputmode="numeric" required placeholder="<?php esc_attr_e( 'Ex. 72', 'partikulier' ); ?>">
												</div>
										</div>
										<div data-pk-field="pk_bedrooms" class="pk-field">
												<label class="pk-label" for="pk-bedrooms"><?php esc_html_e( 'Nombre de chambres', 'partikulier' ); ?> <span class="pk-req">*</span></label>
												<div class="pk-input-icon">
														<span class="pk-input-pin" aria-hidden="true">
																<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M3 18v-6h18v6M3 12V7m18 5V9a2 2 0 0 0-2-2h-5v5"/><circle cx="7.5" cy="9.5" r="1.6"/></svg>
														</span>
														<select id="pk-bedrooms" name="pk_bedrooms" required>
																<option value=""><?php echo esc_html( $pk_i18n( 'Choisissez le nombre' ) ); ?></option>
																<option value="0"><?php esc_html_e( 'Studio / 0 chambre', 'partikulier' ); ?></option>
																<option value="1"><?php esc_html_e( '1 chambre', 'partikulier' ); ?></option>
																<option value="2"><?php esc_html_e( '2 chambres', 'partikulier' ); ?></option>
																<option value="3+"><?php esc_html_e( '3 chambres ou plus', 'partikulier' ); ?></option>
														</select>
												</div>
										</div>
								</div>

								<div data-pk-field="pk_price" class="pk-field">
										<label class="pk-label" for="pk-price"><?php esc_html_e( 'Prix demandé', 'partikulier' ); ?> <span class="pk-req">*</span></label>
										<input type="text" id="pk-price" name="pk_price" inputmode="numeric" required pattern="[0-9][0-9\s.,]*" title="<?php esc_attr_e( 'Le prix ne peut contenir que des chiffres.', 'partikulier' ); ?>" placeholder="<?php esc_attr_e( 'Ex. 389000', 'partikulier' ); ?>">
								</div>

								<div class="pk-grid-2">
										<div data-pk-field="pk_living_rooms" class="pk-field">
												<label class="pk-label" for="pk-living-rooms"><?php esc_html_e( 'Nombre de salons', 'partikulier' ); ?> <span class="pk-req">*</span></label>
												<select id="pk-living-rooms" name="pk_living_rooms" required>
														<option value=""><?php echo esc_html( $pk_i18n( 'Choisissez le nombre' ) ); ?></option>
														<option value="0"><?php esc_html_e( '0 salon — pièce principale', 'partikulier' ); ?></option>
																<option value="1"><?php esc_html_e( '1 salon', 'partikulier' ); ?></option>
																<option value="2"><?php esc_html_e( '2 salons', 'partikulier' ); ?></option>
																<option value="3+"><?php esc_html_e( '3 salons ou plus', 'partikulier' ); ?></option>
												</select>
										</div>
										<div data-pk-field="pk_bathrooms" class="pk-field">
												<label class="pk-label" for="pk-bathrooms"><?php esc_html_e( 'Nombre de salles de bains', 'partikulier' ); ?> <span class="pk-req">*</span></label>
												<div class="pk-input-icon">
														<span class="pk-input-pin" aria-hidden="true">
																<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M4 12h16v3a4 4 0 0 1-4 4H8a4 4 0 0 1-4-4z"/><path d="M7 12V6.5A2.5 2.5 0 0 1 9.5 4c1.2 0 2.1.8 2.4 1.9"/></svg>
														</span>
														<select id="pk-bathrooms" name="pk_bathrooms" required>
																<option value=""><?php echo esc_html( $pk_i18n( 'Choisissez le nombre' ) ); ?></option>
																		<option value="1"><?php esc_html_e( '1 salle de bains', 'partikulier' ); ?></option>
																		<option value="2"><?php esc_html_e( '2 salles de bains', 'partikulier' ); ?></option>
																		<option value="3+"><?php esc_html_e( '3 salles de bains ou plus', 'partikulier' ); ?></option>
														</select>
												</div>
										</div>
								</div>

								<div class="pk-grid-2">
										<div data-pk-field="pk_floor" class="pk-field">
												<label class="pk-label" for="pk-floor"><?php esc_html_e( 'Étage', 'partikulier' ); ?></label>
												<select id="pk-floor" name="pk_floor">
														<option value="RDC"><?php esc_html_e( 'RDC', 'partikulier' ); ?></option>
																<?php for ( $i = 1; $i <= 12; $i++ ) : ?>
																		<option value="<?php echo esc_attr( $i . ( 1 === $i ? 'er' : 'e' ) . ' étage' ); ?>"><?php printf( esc_html__( '%d%s étage', 'partikulier' ), (int) $i, ( 1 === $i ? 'er' : 'e' ) ); ?></option>
																<?php endfor; ?>
														<option value="Dernier étage"><?php esc_html_e( 'Dernier étage', 'partikulier' ); ?></option>
												</select>
										</div>
										<div data-pk-field="pk_garage" class="pk-field">
												<label class="pk-label"><?php esc_html_e( 'Garage ou sous-sol', 'partikulier' ); ?></label>
												<div class="pk-toggle" data-toggle="pk_garage">
														<button type="button" class="pk-toggle-btn" data-value="Oui"><?php esc_html_e( 'Oui', 'partikulier' ); ?></button>
														<button type="button" class="pk-toggle-btn is-on" data-value="Non"><?php esc_html_e( 'Non', 'partikulier' ); ?></button>
												</div>
												<input type="hidden" name="pk_garage" value="Non">
										</div>
								</div>

								<div class="pk-grid-2">
										<div data-pk-field="pk_elevator" class="pk-field">
												<label class="pk-label"><?php esc_html_e( 'Ascenseur', 'partikulier' ); ?></label>
												<div class="pk-toggle" data-toggle="pk_elevator">
														<button type="button" class="pk-toggle-btn" data-value="Oui"><?php esc_html_e( 'Oui', 'partikulier' ); ?></button>
														<button type="button" class="pk-toggle-btn is-on" data-value="Non"><?php esc_html_e( 'Non', 'partikulier' ); ?></button>
												</div>
												<input type="hidden" name="pk_elevator" value="Non">
										</div>
										<div data-pk-field="pk_vis_a_vis" class="pk-field">
												<label class="pk-label"><?php esc_html_e( 'Sans vis-à-vis', 'partikulier' ); ?></label>
												<div class="pk-toggle" data-toggle="pk_vis_a_vis">
														<button type="button" class="pk-toggle-btn" data-value="Oui"><?php esc_html_e( 'Oui', 'partikulier' ); ?></button>
														<button type="button" class="pk-toggle-btn is-on" data-value="Non"><?php esc_html_e( 'Non', 'partikulier' ); ?></button>
												</div>
												<input type="hidden" name="pk_vis_a_vis" value="Non">
										</div>
								</div>

								<div data-pk-field="pk_sunshine" class="pk-field">
										<label class="pk-label" for="pk-sunshine"><?php esc_html_e( 'Ensoleillement', 'partikulier' ); ?></label>
										<select id="pk-sunshine" name="pk_sunshine">
												<option value=""><?php esc_html_e( 'Indifférent / Non précisé', 'partikulier' ); ?></option>
												<option value="Ensoleillé le matin"><?php esc_html_e( 'Ensoleillé le matin', 'partikulier' ); ?></option>
												<option value="Ensoleillé l’après-midi"><?php esc_html_e( 'Ensoleillé l’après-midi', 'partikulier' ); ?></option>
												<option value="Toute la journée"><?php esc_html_e( 'Toute la journée', 'partikulier' ); ?></option>
												<option value="Très peu"><?php esc_html_e( 'Très peu', 'partikulier' ); ?></option>
										</select>
								</div>

								<div data-pk-field="pk_terrace" class="pk-field">
										<label class="pk-label"><?php esc_html_e( 'Terrasse', 'partikulier' ); ?></label>
										<div class="pk-toggle pk-toggle-half" data-toggle="pk_terrace">
												<button type="button" class="pk-toggle-btn" data-value="Oui"><?php esc_html_e( 'Oui', 'partikulier' ); ?></button>
												<button type="button" class="pk-toggle-btn is-on" data-value="Non"><?php esc_html_e( 'Non', 'partikulier' ); ?></button>
										</div>
										<input type="hidden" name="pk_terrace" value="Non">
								</div>

								<div data-pk-field="pk_terrace_surface" class="pk-field" id="pk-terrace-surface-field" hidden>
										<label class="pk-label" for="pk-terrace-surface"><?php esc_html_e( 'Superficie de la terrasse (m²)', 'partikulier' ); ?></label>
										<input type="number" id="pk-terrace-surface" name="pk_terrace_surface" min="1" inputmode="numeric">
								</div>
									<div data-pk-field="pk_charges" class="pk-field" hidden>
										<label class="pk-label" for="pk-charges"><?php echo esc_html( $pk_i18n( 'Charges (MAD / mois)' ) ); ?></label>
										<input type="number" id="pk-charges" name="pk_charges" min="0" max="100000" inputmode="numeric" placeholder="<?php esc_attr_e( 'Ex. 700', 'partikulier' ); ?>">
									</div>

									<div data-pk-field="pk_availability" class="pk-field" hidden>
										<label class="pk-label" for="pk-availability"><?php echo esc_html( $pk_i18n( 'Disponibilité' ) ); ?></label>
										<input type="text" id="pk-availability" name="pk_availability" placeholder="<?php esc_attr_e( 'Ex. Immédiate, sous 3 mois…', 'partikulier' ); ?>">
									</div>

									<div data-pk-field="pk_year_built" class="pk-field" hidden>
										<label class="pk-label" for="pk-year-built"><?php esc_html_e( 'Année de construction', 'partikulier' ); ?></label>
										<input type="number" id="pk-year-built" name="pk_year_built" min="1900" max="2100" inputmode="numeric" placeholder="<?php esc_attr_e( 'Ex. 2016', 'partikulier' ); ?>">
									</div>

									<div data-pk-field="pk_energy_class" class="pk-field" hidden>
										<label class="pk-label" for="pk-energy-class"><?php esc_html_e( 'Classe énergie', 'partikulier' ); ?></label>
										<select id="pk-energy-class" name="pk_energy_class"><option value=""><?php echo esc_html( $pk_i18n( 'Choisissez' ) ); ?></option><?php foreach ( array( 'A', 'B', 'C', 'D', 'E', 'F', 'G' ) as $pk_classe ) : ?><option value="<?php echo esc_attr( $pk_classe ); ?>"><?php echo esc_html( $pk_classe ); ?></option><?php endforeach; ?></select>
									</div>

									<div data-pk-field="pk_ges_class" class="pk-field" hidden>
										<label class="pk-label" for="pk-ges-class"><?php esc_html_e( 'GES', 'partikulier' ); ?></label>
										<select id="pk-ges-class" name="pk_ges_class"><option value=""><?php echo esc_html( $pk_i18n( 'Choisissez' ) ); ?></option><?php foreach ( array( 'A', 'B', 'C', 'D', 'E', 'F', 'G' ) as $pk_classe ) : ?><option value="<?php echo esc_attr( $pk_classe ); ?>"><?php echo esc_html( $pk_classe ); ?></option><?php endforeach; ?></select>
									</div>

									<div data-pk-field="pk_lot_size" class="pk-field" hidden>
										<label class="pk-label" for="pk-lot-size"><?php echo esc_html( $pk_i18n( 'Terrain (m²)' ) ); ?></label>
										<input type="number" id="pk-lot-size" name="pk_lot_size" min="1" max="1000000" inputmode="numeric" placeholder="<?php esc_attr_e( 'Ex. 300', 'partikulier' ); ?>">
									</div>

									<div data-pk-field="pk_half_baths" class="pk-field" hidden>
										<label class="pk-label" for="pk-half-baths"><?php echo esc_html( $pk_i18n( 'Demi-salles de bains' ) ); ?></label>
										<input type="number" id="pk-half-baths" name="pk_half_baths" min="0" max="10" inputmode="numeric">
									</div>

									<div data-pk-field="pk_total_rooms" class="pk-field" hidden>
										<label class="pk-label" for="pk-total-rooms"><?php esc_html_e( 'Nombre de pièces', 'partikulier' ); ?></label>
										<input type="number" id="pk-total-rooms" name="pk_total_rooms" min="0" max="50" inputmode="numeric">
									</div>


								<div class="pk-field">
										<label class="pk-label"><?php esc_html_e( 'Photos du bien', 'partikulier' ); ?></label>
										<p class="pk-optional"><?php esc_html_e( 'facultatif', 'partikulier' ); ?></p>
										<label class="pk-dropzone" id="pk-dropzone">
												<span class="pk-dropzone-icon" aria-hidden="true">
														<svg viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><path d="M4 8.5h3l1.4-2h7.2L17 8.5h3V19H4z"/><circle cx="12" cy="13.2" r="3.4"/></svg>
												</span>
												<strong><?php esc_html_e( 'Ajoutez vos photos', 'partikulier' ); ?></strong>
												<span class="pk-dropzone-hint"><?php echo esc_html( Partikulier_Form::upload_hint() ); ?></span>
												<input type="file" id="pk-photos" name="pk_photos[]" accept="<?php echo esc_attr( Partikulier_Form::accepted_upload_types() ); ?>" multiple class="pk-visually-hidden">
										</label>
										<ul class="pk-photo-preview" id="pk-photo-preview" aria-live="polite"></ul>
								</div>

								<div class="pk-step-actions">
										<button type="button" class="pk-btn pk-btn-ghost" data-goto="1"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Retour', 'partikulier' ); ?></button>
										<button type="button" class="pk-btn pk-btn-primary" data-goto="3"><?php esc_html_e( 'Continuer', 'partikulier' ); ?> <span aria-hidden="true">&rarr;</span></button>
								</div>
								<p class="pk-step-legal"><?php esc_html_e( 'Aucune inscription obligatoire. Votre numéro sert uniquement à confirmer que l’annonce vient d’une vraie personne.', 'partikulier' ); ?></p>

								<?php
								// Lot SE-042c : les réglages de l'écran « Formulaire de dépôt » s'appliquent ici.
								// Le HTML est rendu en entier ; l'affichage, l'astérisque et l'ordre suivent la configuration.
								// Un champ masqué reste refusé par le serveur, même renvoyé à la main.
								$pk_df_config = array( 'cap' => Partikulier_Deposit_Form::cap(), 'types' => array(), 'ordre' => array() );
								$pk_df_champs = Partikulier_Deposit_Form::fields();
								foreach ( Partikulier_Deposit_Form::types() as $pk_slug => $pk_libelle ) {
									foreach ( array_keys( $pk_df_champs ) as $pk_champ ) {
										$pk_etat = Partikulier_Deposit_Form::state( $pk_champ, $pk_slug );
										$pk_df_config['types'][ $pk_slug ][ $pk_champ ] = ( Partikulier_Deposit_Form::INHERITED === $pk_etat ) ? $pk_df_champs[ $pk_champ ]['actuel'] : $pk_etat;
										$pk_df_config['ordre'][ $pk_champ ]          = Partikulier_Deposit_Form::order( $pk_champ, $pk_slug );
									}
								}
								?>
								<script type="application/json" id="pk-deposit-config"><?php echo wp_json_encode( $pk_df_config ); ?></script>
								<script>
								( function () {
									var noeud = document.getElementById( 'pk-deposit-config' );
									var form  = document.getElementById( 'pk-submit-form' );
									if ( ! noeud || ! form ) { return; }
									var reglages = JSON.parse( noeud.textContent );

									function etat( champ, slug ) {
										if ( reglages.types[ slug ] && reglages.types[ slug ][ champ ] ) { return reglages.types[ slug ][ champ ]; }
										return 'herite';
									}

									function appliquer() {
										var select = document.getElementById( 'pk-type' );
										if ( ! select ) { return; }
										var option = select.options[ select.selectedIndex ];
										var slug   = option ? option.getAttribute( 'data-pk-slug' ) : '';
										if ( ! slug ) { return; }
										var blocs = form.querySelectorAll( '[data-pk-field]' );
										var parent = blocs.length ? blocs[0].parentNode : null;
										blocs.forEach( function ( bloc ) {
											var champ = bloc.getAttribute( 'data-pk-field' );
											var e     = etat( champ, slug );
											if ( 'herite' === e ) { return; }
											var cache = ( e === 'masque' );
											bloc.hidden = cache;
											bloc.setAttribute( 'data-pk-state', e );
											bloc.querySelectorAll( 'input, select, textarea' ).forEach( function ( c ) {
												// Les champs d'autocomplétion (ville, quartier) et les bascules gardent leur
												// valeur dans un champ invisible : y poser required bloquerait l'étape 1,
												// que le parcours d'origine valide déjà par lui-même.
												var natif = ( 'hidden' !== c.type ) && ! c.closest( '.pk-autocomplete' );
												if ( cache ) { c.removeAttribute( 'required' ); }
												else if ( e === 'requis' && natif ) { c.setAttribute( 'required', 'required' ); }
												else { c.removeAttribute( 'required' ); }
											} );
											var lab = bloc.querySelector( '.pk-label' );
											if ( lab ) {
												var star = lab.querySelector( '.pk-req' );
												if ( e === 'requis' && ! star ) {
													star = document.createElement( 'span' );
													star.className = 'pk-req';
													star.textContent = '*';
													lab.appendChild( document.createTextNode( ' ' ) );
													lab.appendChild( star );
												} else if ( e !== 'requis' && star ) { star.remove(); }
											}
										} );
										if ( parent ) {
											var liste = [];
											parent.querySelectorAll( '[data-pk-field]' ).forEach( function ( b, i ) {
												var o = parseInt( reglages.ordre[ b.getAttribute( 'data-pk-field' ) ] || ( 100 + i ), 10 );
												liste.push( { bloc: b, ordre: o } );
											} );
											liste.sort( function ( a, b ) { return a.ordre - b.ordre; } );
											liste.forEach( function ( item ) { parent.appendChild( item.bloc ); } );
										}

										// Garantir que les boutons de navigation (Continuer) et mentions restent STRICTEMENT en bas de l'étape
										form.querySelectorAll( '.pk-step' ).forEach( function ( step ) {
											var actions = step.querySelector( '.pk-step-actions' );
											var legal   = step.querySelector( '.pk-step-legal' );
											if ( actions ) { step.appendChild( actions ); }
											if ( legal ) { step.appendChild( legal ); }
										} );
									}

									document.addEventListener( 'change', function ( e ) {
										if ( e.target && e.target.id === 'pk-type' ) { appliquer(); }
									} );
									appliquer();
								}() );
								</script>
</section>

						<!-- ============ ETAPE 3 ============ -->
						<section class="pk-card pk-step" data-step="3" hidden>
								<h2 class="pk-card-title"><?php esc_html_e( 'Votre aperçu', 'partikulier' ); ?></h2>
								<p class="pk-card-note"><?php esc_html_e( 'Relisez l’annonce, puis validez votre numéro par WhatsApp.', 'partikulier' ); ?></p>

								<div class="pk-preview" id="pk-preview">
										<div class="pk-preview-media" id="pk-preview-media" aria-hidden="true"></div>
										<div class="pk-preview-body">
												<p class="pk-preview-kicker" id="pk-preview-kicker"></p>
												<h3 class="pk-preview-title" id="pk-preview-title"></h3>
												<p class="pk-preview-desc" id="pk-preview-desc"></p>
												<ul class="pk-preview-facts" id="pk-preview-facts"></ul>
												<p class="pk-preview-price"><span id="pk-preview-price"></span> <small><?php esc_html_e( 'prix affiché', 'partikulier' ); ?></small></p>
										</div>
								</div>

								<div class="pk-field">
										<label class="pk-label" for="pk-title">
												<?php esc_html_e( 'Titre de l’annonce', 'partikulier' ); ?> <em class="pk-editable"><?php esc_html_e( 'modifiable', 'partikulier' ); ?></em>
										</label>
										<input type="text" id="pk-title" name="pk_title" maxlength="160" lang="<?php echo esc_attr( function_exists( 'pll_current_language' ) ? pll_current_language( 'locale' ) : get_locale() ); ?>" data-pk-free-text="1">
										<small class="pk-field-hint"><?php esc_html_e( 'Le titre est créé automatiquement à partir de vos réponses. Vous pouvez le modifier avant la validation.', 'partikulier' ); ?></small>
								</div>

								<div class="pk-field">
										<label class="pk-label" for="pk-extra"><?php esc_html_e( 'Ajouter un mot personnel', 'partikulier' ); ?></label>
										<p class="pk-optional"><?php esc_html_e( 'facultatif', 'partikulier' ); ?></p>
										<textarea id="pk-extra" name="pk_extra" rows="4" lang="<?php echo esc_attr( function_exists( 'pll_current_language' ) ? pll_current_language( 'locale' ) : get_locale() ); ?>" data-pk-free-text="1" placeholder="<?php esc_attr_e( 'Un détail important, une précision sur le quartier ou vos conditions de visite…', 'partikulier' ); ?>"></textarea>
										<small class="pk-field-hint"><?php esc_html_e( 'La description principale est déjà créée à partir de vos réponses. Cet espace vous permet d’ajouter un complément si vous le souhaitez.', 'partikulier' ); ?></small>
								</div>

								<input type="hidden" name="pk_description" id="pk-description" lang="<?php echo esc_attr( function_exists( 'pll_current_language' ) ? pll_current_language( 'locale' ) : get_locale() ); ?>" data-pk-free-text="1">

								<div class="pk-field">
										<label class="pk-label" for="pk-name"><?php esc_html_e( 'Votre nom', 'partikulier' ); ?> <span class="pk-req">*</span></label>
										<input type="text" id="pk-name" name="pk_name" required minlength="2" autocomplete="name" placeholder="<?php esc_attr_e( 'Nom ou nom d’usage, sans prénom', 'partikulier' ); ?>">
								</div>

								<div class="pk-field">
										<label class="pk-label" for="pk-phone"><?php esc_html_e( 'Votre numéro de téléphone', 'partikulier' ); ?> <span class="pk-req">*</span></label>
										<div class="pk-input-icon">
												<span class="pk-input-pin" aria-hidden="true">
														<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3.1 19.5 19.5 0 0 1-6-6A19.8 19.8 0 0 1 2.1 4.2 2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1 1 .4 1.9.7 2.8a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.3-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.8.7a2 2 0 0 1 1.7 2z"/></svg>
												</span>
												<input type="tel" id="pk-phone" name="pk_phone" required autocomplete="tel" placeholder="<?php esc_attr_e( '06 00 00 00 00', 'partikulier' ); ?>">
										</div>
								</div>

								<div class="pk-field">
										<label class="pk-label" for="pk-email"><?php esc_html_e( 'E-mail', 'partikulier' ); ?></label>
										<p class="pk-optional"><?php esc_html_e( 'facultatif', 'partikulier' ); ?></p>
										<input type="email" id="pk-email" name="pk_email" autocomplete="email" placeholder="<?php esc_attr_e( 'vous@exemple.com', 'partikulier' ); ?>">
								</div>

								<div class="pk-step-actions">
										<button type="button" class="pk-btn pk-btn-ghost" data-goto="2"><span aria-hidden="true">&larr;</span> <?php esc_html_e( 'Retour', 'partikulier' ); ?></button>
										<button type="submit" class="pk-btn pk-btn-primary" id="pk-submit-btn">
												<?php esc_html_e( 'Valider avec WhatsApp', 'partikulier' ); ?> <span aria-hidden="true">&rarr;</span>
										</button>
								</div>
								<p class="pk-form-note" id="pk-form-status" aria-live="polite"></p>
								<p class="pk-step-legal"><?php esc_html_e( 'Aucune inscription obligatoire. Votre numéro sert uniquement à confirmer que l’annonce vient d’une vraie personne.', 'partikulier' ); ?></p>
						</section>
				</form>
		</div>
</section>

<?php
get_footer();
