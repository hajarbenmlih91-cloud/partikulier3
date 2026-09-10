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
                        <p class="pk-editorial-kicker"><?php esc_html_e( 'Espace propriétaire', 'partikulier' ); ?></p>
                        <h1 class="pk-auth-title">
                                <?php esc_html_e( 'Vos annonces', 'partikulier' ); ?>
                                <span class="pk-hero-accent"><?php esc_html_e( 'vous attendent.', 'partikulier' ); ?></span>
                        </h1>
                        <p class="pk-auth-subtitle">
                                <?php esc_html_e( 'Connectez-vous pour les gérer, suivre leurs vues et vos contacts directs.', 'partikulier' ); ?>
                        </p>
                </header>

                <div class="pk-auth-card">
                        <?php
                        if ( shortcode_exists( 'es_authentication' ) ) {
                                // Le shortcode lit lui-même ?auth_item et ?redirect_url.
                                echo do_shortcode( '[es_authentication]' );
                        } else {
                                // Estatik absent : repli documenté vers wp-login.
                                echo '<p class="pk-auth-fallback">' . esc_html__( 'Le module de connexion n’est pas disponible pour le moment.', 'partikulier' ) . '</p>';
                                echo '<a class="pk-btn pk-btn-primary" href="' . esc_url( wp_login_url() ) . '">' . esc_html__( 'Se connecter', 'partikulier' ) . '</a>';
                        }
                        ?>
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
