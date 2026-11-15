<?php
/**
 * Template : Connexion (authentification au design du thème).
 *
 * Page d'authentification publique — même identité visuelle que le site, en
 * remplacement de wp-login.php pour les visiteurs du front.
 *
 * Écran aligné sur la maquette validée de l'espace connecté, dans l'ordre :
 * « Bienvenue », promesse « Contacts, Favoris, Mes annonces », ouverture de
 * compte par WhatsApp (numéro d'équipe du réglage du site, même source que
 * le tunnel de dépôt — jamais codé en dur), séparateur « ou avec », Google
 * (désactivé, annoncé « Bientôt » : aucune authentification Google n'est
 * branchée), puis E-mail qui déplie le formulaire réel.
 *
 * Le formulaire e-mail, l'inscription et la réinitialisation de mot de
 * passe sont rendus par le shortcode [es_authentication] d'Estatik ; le
 * thème reste seul responsable du style. Le bloc e-mail est déplié
 * d'emblée quand ?auth_item le demande (liens profonds et contrats) ou
 * sans JavaScript ; sinon le bouton E-mail le déplie.
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
		'continue'     => 'Continuer avec l’une des options suivantes',
		'prefix'       => 'Préfixe',
		'phone'        => 'Numéro de téléphone',
		'wa_continue'  => 'Continuer avec WhatsApp',
		'wa_note'      => 'Notre équipe confirme la création de votre compte après réception de votre message sur WhatsApp.',
		'wa_error'     => 'Numéro invalide : saisissez un mobile marocain (ex. 06 12 34 56 78).',
		'wa_message'   => 'Bonjour, je souhaite créer mon compte pep.ma. Mon numéro : %s',
		'or'           => 'ou avec',
		'google'       => 'Google',
		'soon'         => 'Bientôt',
		'email'        => 'E-mail',
		'legal_intro'  => 'En créant un compte, vous acceptez :',
		'terms'        => 'Conditions d’utilisation',
		'privacy'      => 'Politique de confidentialité',
		'cookies'      => 'Politique en matière de cookies',
		'connect_hint' => 'Déjà membre ? Connectez-vous. Nouveau ? Créez votre compte en une minute, gratuitement.',
	),
	'en' => array(
		'welcome'      => 'Welcome',
		'promise'      => 'Contacts, Favorites, My listings',
		'continue'     => 'Continue with one of the following options',
		'prefix'       => 'Prefix',
		'phone'        => 'Phone number',
		'wa_continue'  => 'Continue with WhatsApp',
		'wa_note'      => 'Our team confirms your account once your WhatsApp message is received.',
		'wa_error'     => 'Invalid number: enter a Moroccan mobile number (e.g. 06 12 34 56 78).',
		'wa_message'   => 'Hello, I would like to create my pep.ma account. My number: %s',
		'or'           => 'or with',
		'google'       => 'Google',
		'soon'         => 'Soon',
		'email'        => 'Email',
		'legal_intro'  => 'By creating an account, you agree to:',
		'terms'        => 'Terms of use',
		'privacy'      => 'Privacy policy',
		'cookies'      => 'Cookie policy',
		'connect_hint' => 'Already a member? Log in. New here? Create your free account in a minute.',
	),
	'ar' => array(
		'welcome'      => 'مرحباً بك',
		'promise'      => 'جهات الاتصال، المفضلة، إعلاناتي',
		'continue'     => 'تابع باستخدام أحد الخيارات التالية',
		'prefix'       => 'المقدمة',
		'phone'        => 'رقم الهاتف',
		'wa_continue'  => 'المتابعة عبر واتساب',
		'wa_note'      => 'يؤكد فريقنا إنشاء حسابك بعد استلام رسالتك على واتساب.',
		'wa_error'     => 'رقم غير صحيح: أدخل رقم هاتف مغربي (مثال: 06 12 34 56 78).',
		'wa_message'   => 'مرحباً، أرغب في إنشاء حسابي على pep.ma. رقمي: %s',
		'or'           => 'أو باستخدام',
		'google'       => 'Google',
		'soon'         => 'قريباً',
		'email'        => 'البريد الإلكتروني',
		'legal_intro'  => 'بإنشاء حساب، فأنت توافق على:',
		'terms'        => 'شروط الاستخدام',
		'privacy'      => 'سياسة الخصوصية',
		'cookies'      => 'سياسة ملفات تعريف الارتباط',
		'connect_hint' => 'هل لديك حساب؟ سجّل الدخول. جديد هنا؟ أنشئ حسابك المجاني في دقيقة واحدة.',
	),
);
$pk_auth_t = $pk_auth_i18n[ $pk_auth_lang ] ?? $pk_auth_i18n['fr'];

/* Numéro WhatsApp de l'équipe : réglage du site, même source que le tunnel
 * de dépôt (Partikulier_WhatsApp_Verification). Si le réglage est vide,
 * le bloc WhatsApp n'est simplement pas affiché. */
$pk_wa_number = '';
if ( class_exists( 'Partikulier_WhatsApp_Verification' ) ) {
	$pk_wa_number = Partikulier_WhatsApp_Verification::validation_number();
}

/* Bloc e-mail déplié d'emblée si un écran précis est demandé
 * (?auth_item=...) : liens profonds, retours de parcours, contrats. */
$pk_auth_item  = isset( $_GET['auth_item'] ) ? sanitize_key( wp_unslash( (string) $_GET['auth_item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture d'affichage, aucune action.
$pk_email_open = '' !== $pk_auth_item;

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

				<div class="pk-auth-card pk-espace-auth-card">
						<header class="pk-espace-auth-head">
								<h1 class="pk-espace-auth-title"><?php echo esc_html( $pk_auth_t['welcome'] ); ?></h1>
								<p class="pk-espace-auth-promise"><?php echo esc_html( $pk_auth_t['promise'] ); ?></p>
								<p class="pk-espace-auth-hint"><?php echo esc_html( $pk_auth_t['connect_hint'] ); ?></p>
						</header>

						<p class="pk-conn-label"><?php echo esc_html( $pk_auth_t['continue'] ); ?></p>

						<?php if ( '' !== $pk_wa_number ) : ?>
						<div class="pk-conn-wa" data-wa-number="<?php echo esc_attr( $pk_wa_number ); ?>" data-wa-message="<?php echo esc_attr( $pk_auth_t['wa_message'] ); ?>" data-wa-error="<?php echo esc_attr( $pk_auth_t['wa_error'] ); ?>">
								<div class="pk-conn-row">
										<div class="pk-conn-field pk-conn-prefix">
												<span class="pk-conn-field-label" aria-hidden="true"><?php echo esc_html( $pk_auth_t['prefix'] ); ?></span>
												<span class="pk-conn-prefix-box">
														<svg class="pk-conn-flag" viewBox="0 0 16 11" aria-hidden="true"><rect width="16" height="11" fill="#C1272D"/><polygon points="8,2.1 8.7,4.3 11,4.3 9.1,5.6 9.8,7.8 8,6.5 6.2,7.8 6.9,5.6 5,4.3 7.3,4.3" fill="none" stroke="#006233" stroke-width=".7"/></svg>
														<span>MA +212</span>
												</span>
										</div>
										<div class="pk-conn-field pk-conn-grow">
												<label class="pk-conn-field-label" for="pk-conn-numero"><?php echo esc_html( $pk_auth_t['phone'] ); ?></label>
												<input class="pk-conn-input" id="pk-conn-numero" name="pk_conn_numero" type="tel" inputmode="numeric" autocomplete="tel" placeholder="06 12 34 56 78">
										</div>
								</div>
								<button type="button" class="pk-conn-go" id="pk-conn-wa-go"><?php echo esc_html( $pk_auth_t['wa_continue'] ); ?></button>
								<p class="pk-conn-feedback" aria-live="polite"></p>
								<p class="pk-conn-note"><?php echo esc_html( $pk_auth_t['wa_note'] ); ?></p>
						</div>
						<?php endif; ?>

						<p class="pk-conn-or"><span><?php echo esc_html( $pk_auth_t['or'] ); ?></span></p>

						<button type="button" class="pk-conn-alt" disabled aria-disabled="true" title="<?php echo esc_attr( $pk_auth_t['soon'] ); ?>">
								<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><path fill="#4285F4" d="M17.6 9.2c0-.6-.1-1.2-.2-1.8H9v3.4h4.8c-.2 1.1-.8 2-1.8 2.6v2.2h2.9c1.7-1.6 2.7-3.9 2.7-6.4z"/><path fill="#34A853" d="M9 18c2.4 0 4.5-.8 6-2.2l-2.9-2.2c-.8.6-1.9.9-3.1.9-2.4 0-4.4-1.6-5.1-3.8H.9v2.3C2.4 16 5.5 18 9 18z"/><path fill="#FBBC05" d="M3.9 10.7c-.2-.6-.3-1.2-.3-1.7s.1-1.2.3-1.7V5H.9C.3 6.2 0 7.6 0 9s.3 2.8.9 4l3-2.3z"/><path fill="#EA4335" d="M9 3.6c1.3 0 2.5.5 3.4 1.3l2.6-2.6C13.5.9 11.4 0 9 0 5.5 0 2.4 2 0.9 5l3 2.3C4.6 5.2 6.6 3.6 9 3.6z"/></svg>
								<span><?php echo esc_html( $pk_auth_t['google'] ); ?></span>
								<span class="pk-conn-soon"><?php echo esc_html( $pk_auth_t['soon'] ); ?></span>
						</button>

						<button type="button" class="pk-conn-alt" id="pk-conn-email-toggle" aria-expanded="<?php echo $pk_email_open ? 'true' : 'false'; ?>" aria-controls="pk-conn-email">
								<svg width="18" height="18" viewBox="0 0 24 24" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M4 7l8 6 8-6" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>
								<span><?php echo esc_html( $pk_auth_t['email'] ); ?></span>
						</button>

						<div class="pk-espace-auth-form pk-conn-email" id="pk-conn-email"<?php echo $pk_email_open ? ' data-open="1"' : ''; ?>>
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
