<?php
/**
 * Template : Connexion (authentification au design du thème).
 *
 * Page d'authentification publique — même identité visuelle que le site, en
 * remplacement de wp-login.php pour les visiteurs du front. Le formulaire,
 * l'inscription et la réinitialisation de mot de passe sont rendus par le
 * shortcode [es_authentication] d'Estatik ; le thème reste seul responsable
 * du style (la feuille publique d'Estatik n'est pas chargée sur le front).
 *
 * Paramètres d'URL natifs du shortcode :
 *   ?auth_item=login-form|reset-form|buyer-register-buttons|buyer-register-form
 *   ?redirect_url=…  (champ caché : ramène l'utilisateur à sa page d'origine
 *                     après une connexion réussie)
 *
 * La page est déclarée non cachable (class-cache) : nonces CSRF et messages
 * flash. Référencement : noindex (class-seo), exclue du sitemap.
 *
 * @package Partikulier
 *
 * Template Name: Connexion
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

// Déjà connecté : aucune raison de rester sur la page de connexion.
if ( is_user_logged_in() ) {
		wp_safe_redirect( pk_page_url( 'mes-annonces', '/mes-annonces/' ) );
		exit;
}

get_header();
?>

<section class="pk-auth">
		<div class="pk-container pk-auth-container">
                <?php echo Partikulier_Geo::breadcrumbs_html(); // phpcs:ignore ?>

				<header class="pk-auth-head">
						<p class="pk-editorial-kicker"><?php esc_html_e( 'Votre espace Partikulier', 'partikulier' ); ?></p>
						<h1 class="pk-auth-title">
								<?php esc_html_e( 'Acheter et déposer', 'partikulier' ); ?>
								<span class="pk-hero-accent"><?php esc_html_e( 'avec le même profil.', 'partikulier' ); ?></span>
						</h1>
						<p class="pk-auth-subtitle">
								<?php esc_html_e( 'Confirmez votre numéro depuis WhatsApp. La page restera ouverte et accédera à votre espace après validation.', 'partikulier' ); ?>
						</p>
				</header>

				<div class="pk-auth-card">
						<?php echo Partikulier_WhatsApp_Verification::auth_widget(); // Le même parcours WhatsApp sert à toute personne, sans rôle « vendeur ». ?>
				</div>

				<ul class="pk-auth-trust" aria-label="<?php esc_attr_e( 'Garanties Partikulier', 'partikulier' ); ?>">
						<li><?php esc_html_e( 'Zéro commission', 'partikulier' ); ?></li>
						<li><?php esc_html_e( 'Contact direct', 'partikulier' ); ?></li>
						<li><?php esc_html_e( 'Vendeur identifié', 'partikulier' ); ?></li>
				</ul>

				<p class="pk-auth-note">
						<?php esc_html_e( 'La consultation des annonces et le dépôt restent possibles sans compte.', 'partikulier' ); ?>
						<a href="<?php echo esc_url( pk_properties_archive_url() ); ?>"><?php esc_html_e( 'Parcourir les annonces', 'partikulier' ); ?></a>
				</p>
		</div>
</section>

<?php
get_footer();
