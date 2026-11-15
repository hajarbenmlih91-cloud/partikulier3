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
 * Présentation alignée sur l'espace connecté (maquette validée du profil
 * acheteur) : carte étroite « Bienvenue », promesse « Contacts, Favoris,
 * Mes annonces », mention légale liée aux pages réelles. Phase 1 : seule
 * l'authentification e-mail + mot de passe existante est branchée ; la
 * connexion WhatsApp de la maquette arrivera en phase 2, sans changer
 * cette présentation.
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

/* Libellés trilingues propres à cet écran (pattern de page-favoris.php :
 * tableau local, langue courante via Polylang, jamais de dictionnaires
 * figés partagés). Les libellés déjà portés par le catalogue du site
 * restent servis par __(). */
$pk_auth_lang = 'fr';
if ( function_exists( 'pll_current_language' ) && pll_current_language() ) {
	$pk_auth_lang = sanitize_key( (string) pll_current_language() );
}
$pk_auth_i18n = array(
	'fr' => array(
		'welcome'      => 'Bienvenue',
		'promise'      => 'Contacts, Favoris, Mes annonces',
		'legal_intro'  => 'En créant un compte, vous acceptez :',
		'terms'        => 'Conditions d’utilisation',
		'privacy'      => 'Politique de confidentialité',
		'cookies'      => 'Politique en matière de cookies',
		'connect_hint' => 'Déjà membre ? Connectez-vous. Nouveau ? Créez votre compte en une minute, gratuitement.',
	),
	'en' => array(
		'welcome'      => 'Welcome',
		'promise'      => 'Contacts, Favorites, My listings',
		'legal_intro'  => 'By creating an account, you agree to:',
		'terms'        => 'Terms of use',
		'privacy'      => 'Privacy policy',
		'cookies'      => 'Cookie policy',
		'connect_hint' => 'Already a member? Log in. New here? Create your free account in a minute.',
	),
	'ar' => array(
		'welcome'      => 'مرحباً بك',
		'promise'      => 'جهات الاتصال، المفضلة، إعلاناتي',
		'legal_intro'  => 'بإنشاء حساب، فأنت توافق على:',
		'terms'        => 'شروط الاستخدام',
		'privacy'      => 'سياسة الخصوصية',
		'cookies'      => 'سياسة ملفات تعريف الارتباط',
		'connect_hint' => 'هل لديك حساب؟ سجّل الدخول. جديد هنا؟ أنشئ حسابك المجاني في دقيقة واحدة.',
	),
);
$pk_auth_t = $pk_auth_i18n[ $pk_auth_lang ] ?? $pk_auth_i18n['fr'];

/**
 * Résout l'URL publique d'une page légale parmi des slugs candidats,
 * dans la langue courante. Retourne une chaîne vide si aucune page
 * publiée ne correspond : aucun lien mort n'est jamais affiché.
 *
 * @param string[] $slugs Slugs candidats, par ordre de préférence.
 * @return string
 */
$pk_espace_legal_url = static function ( array $slugs ): string {
	foreach ( $slugs as $slug ) {
		$page = get_page_by_path( $slug, OBJECT, 'page' );
		if ( ! $page && class_exists( 'Partikulier_Required_Pages' ) ) {
			$page = Partikulier_Required_Pages::find( $slug );
		}
		if ( $page instanceof WP_Post && 'publish' === $page->post_status ) {
			return pk_page_url( $slug, '' );
		}
	}
	return '';
};

$pk_legal_links = array();
$pk_terms_url   = $pk_espace_legal_url( array( 'conditions-d-utilisation', 'conditions-generales', 'conditions', 'cgu' ) );
if ( '' !== $pk_terms_url ) {
	$pk_legal_links[] = array( 'url' => $pk_terms_url, 'label' => $pk_auth_t['terms'] );
}
$pk_privacy_url = $pk_espace_legal_url( array( 'politique-de-confidentialite', 'confidentialite', 'privacy-policy' ) );
if ( '' !== $pk_privacy_url ) {
	$pk_legal_links[] = array( 'url' => $pk_privacy_url, 'label' => $pk_auth_t['privacy'] );
}
$pk_cookies_url = $pk_espace_legal_url( array( 'politique-cookies', 'politique-en-matiere-de-cookies', 'cookies' ) );
if ( '' !== $pk_cookies_url ) {
	$pk_legal_links[] = array( 'url' => $pk_cookies_url, 'label' => $pk_auth_t['cookies'] );
}

get_header();
?>

<section class="pk-auth pk-espace-auth">
		<div class="pk-container pk-auth-container">
                <?php echo Partikulier_Geo::breadcrumbs_html(); // phpcs:ignore ?>

				<div class="pk-espace-auth-card">
						<header class="pk-espace-auth-head">
								<h1 class="pk-espace-auth-title"><?php echo esc_html( $pk_auth_t['welcome'] ); ?></h1>
								<p class="pk-espace-auth-promise"><?php echo esc_html( $pk_auth_t['promise'] ); ?></p>
								<p class="pk-espace-auth-hint"><?php echo esc_html( $pk_auth_t['connect_hint'] ); ?></p>
						</header>

						<div class="pk-espace-auth-form">
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

						<?php if ( $pk_legal_links ) : ?>
						<p class="pk-espace-auth-legal">
								<?php echo esc_html( $pk_auth_t['legal_intro'] ); ?>
								<?php foreach ( $pk_legal_links as $pk_i => $pk_link ) : ?>
										<?php echo $pk_i > 0 ? ' · ' : ' '; ?><a href="<?php echo esc_url( $pk_link['url'] ); ?>"><?php echo esc_html( $pk_link['label'] ); ?></a>
								<?php endforeach; ?>
						</p>
						<?php endif; ?>
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
