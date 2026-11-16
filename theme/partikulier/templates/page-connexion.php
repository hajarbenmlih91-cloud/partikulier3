<?php
/**
 * Template : Connexion (feuille d'authentification au design validé).
 *
 * Écran reproduit à l'identique de la maquette validée de l'espace
 * (espace-acheteur.html) : feuille autonome centrée (390 px), logo et
 * bouton fermer en haut, « Bienvenue », promesse grise, préfixe MA +212
 * et numéro, bouton « Continuer avec WhatsApp » rouge, mentions légales
 * sous le bouton, puis « ou avec », Google (désactivé « Bientôt ») et
 * E-mail qui déplie le formulaire réel.
 *
 * Pas d'en-tête ni de pied du site autour : la feuille EST l'écran, comme
 * dans la maquette. wp_head()/wp_footer() restent appelés (styles,
 * scripts, bannière cookies du site).
 *
 * WhatsApp : ouvre wa.me vers le numéro d'équipe du réglage du site
 * (Partikulier_WhatsApp_Verification, même source que le tunnel de
 * dépôt — jamais codé en dur), avec confirmation affichée dans la
 * feuille. Bloc masqué si le réglage est vide.
 *
 * E-mail : le shortcode [es_authentication] d'Estatik reste le moteur
 * réel (connexion, inscription, mot de passe oublié). Bloc déplié
 * d'emblée si ?auth_item est présent (liens profonds, contrats) ou sans
 * JavaScript ; sinon le bouton E-mail le déplie.
 *
 * Paramètres d'URL natifs du shortcode :
 *   ?auth_item=login-form|reset-form|buyer-register-buttons|buyer-register-form
 *   ?redirect_url=…  (retour à la page d'origine après connexion)
 *
 * Page non cachable (class-cache), noindex (class-seo), hors sitemap.
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

/* Libellés trilingues propres à cet écran (pattern de page-favoris.php). */
$pk_auth_lang = 'fr';
if ( function_exists( 'pll_current_language' ) && pll_current_language() ) {
	$pk_auth_lang = sanitize_key( (string) pll_current_language() );
}
$pk_auth_i18n = array(
	'fr' => array(
		'welcome'    => 'Bienvenue',
		'promise'    => 'Contacts, Favoris, Mes annonces',
		'prefix'     => 'Préfixe',
		'phone'      => 'Numéro de téléphone',
		'wa_continue' => 'Continuer avec WhatsApp',
		'wa_error'   => 'Numéro invalide : saisissez un mobile marocain (ex. 06 12 34 56 78).',
		'wa_message' => 'Bonjour, je souhaite créer mon compte pep.ma. Mon numéro : %s',
		'suite'      => 'Confirmation du compte',
		'or'         => 'ou avec',
		'google'     => 'Google',
		'soon'       => 'Bientôt',
		'email'      => 'E-mail',
		'legal_a'    => 'En créant un compte, vous acceptez automatiquement nos ',
		'legal_b'    => ', notre ',
		'legal_c'    => ' et notre ',
		'legal_d'    => '',
		'terms'      => 'Conditions d’utilisation',
		'privacy'    => 'Politique de confidentialité',
		'cookies'    => 'Politique en matière de cookies',
		'close'      => 'Fermer',
	),
	'en' => array(
		'welcome'    => 'Welcome',
		'promise'    => 'Contacts, Favorites, My listings',
		'prefix'     => 'Prefix',
		'phone'      => 'Phone number',
		'wa_continue' => 'Continue with WhatsApp',
		'wa_error'   => 'Invalid number: enter a Moroccan mobile number (e.g. 06 12 34 56 78).',
		'wa_message' => 'Hello, I would like to create my pep.ma account. My number: %s',
		'suite'      => 'Account confirmation',
		'or'         => 'or with',
		'google'     => 'Google',
		'soon'       => 'Soon',
		'email'      => 'Email',
		'legal_a'    => 'By creating an account, you automatically agree to our ',
		'legal_b'    => ', our ',
		'legal_c'    => ' and our ',
		'legal_d'    => '',
		'terms'      => 'Terms of use',
		'privacy'    => 'Privacy policy',
		'cookies'    => 'Cookie policy',
		'close'      => 'Close',
	),
	'ar' => array(
		'welcome'    => 'مرحباً بك',
		'promise'    => 'جهات الاتصال، المفضلة، إعلاناتي',
		'prefix'     => 'المقدمة',
		'phone'      => 'رقم الهاتف',
		'wa_continue' => 'المتابعة عبر واتساب',
		'wa_error'   => 'رقم غير صحيح: أدخل رقم هاتف مغربي (مثال: 06 12 34 56 78).',
		'wa_message' => 'مرحباً، أرغب في إنشاء حسابي على pep.ma. رقمي: %s',
		'suite'      => 'تأكيد الحساب',
		'or'         => 'أو باستخدام',
		'google'     => 'Google',
		'soon'       => 'قريباً',
		'email'      => 'البريد الإلكتروني',
		'legal_a'    => 'بإنشاء حساب، فأنت توافق تلقائياً على ',
		'legal_b'    => ' الخاصة بنا، و',
		'legal_c'    => ' الخاصة بنا، و',
		'legal_d'    => ' الخاصة بنا',
		'terms'      => 'شروط الاستخدام',
		'privacy'    => 'سياسة الخصوصية',
		'cookies'    => 'سياسة ملفات تعريف الارتباط',
		'close'      => 'إغلاق',
	),
);
$pk_auth_t = $pk_auth_i18n[ $pk_auth_lang ] ?? $pk_auth_i18n['fr'];

/* Numéro WhatsApp de l'équipe : réglage du site, même source que le
 * tunnel de dépôt. Si le réglage est vide, bloc WhatsApp non affiché. */
$pk_wa_number = '';
if ( class_exists( 'Partikulier_WhatsApp_Verification' ) ) {
	$pk_wa_number = Partikulier_WhatsApp_Verification::validation_number();
}

/* Bloc e-mail déplié d'emblée si un écran précis est demandé. */
$pk_auth_item  = isset( $_GET['auth_item'] ) ? sanitize_key( wp_unslash( (string) $_GET['auth_item'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple lecture d'affichage, aucune action.
$pk_email_open = '' !== $pk_auth_item;

/**
 * Résout l'URL publique d'une page légale parmi des slugs candidats.
 * Chaîne vide si aucune page publiée : aucun lien mort n'est affiché.
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
/* Séparateurs de la phrase légale, selon le nombre de liens résolus. */
$pk_legal_seps = array( $pk_auth_t['legal_b'], $pk_auth_t['legal_c'] );

$pk_home_url = home_url( '/' );
$pk_logo_url = get_theme_file_uri( 'assets/img/pep-logo-sm.png' );
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'pk-conn-body' ); ?>>

<main class="pk-conn-sheet pk-auth-card">
		<div class="pk-conn-top">
				<a class="pk-conn-logo" href="<?php echo esc_url( $pk_home_url ); ?>" rel="home" aria-label="pep.ma">
						<img src="<?php echo esc_url( $pk_logo_url ); ?>" alt="pep.ma" width="92" height="39" decoding="async">
				</a>
				<a class="pk-conn-close" href="<?php echo esc_url( $pk_home_url ); ?>" aria-label="<?php echo esc_attr( $pk_auth_t['close'] ); ?>">&times;</a>
		</div>

		<h1 class="pk-conn-title"><?php echo esc_html( $pk_auth_t['welcome'] ); ?></h1>
		<p class="pk-conn-sub"><?php echo esc_html( $pk_auth_t['promise'] ); ?></p>

		<?php if ( '' !== $pk_wa_number ) : ?>
		<div class="pk-conn-wa" data-wa-number="<?php echo esc_attr( $pk_wa_number ); ?>" data-wa-message="<?php echo esc_attr( $pk_auth_t['wa_message'] ); ?>" data-wa-error="<?php echo esc_attr( $pk_auth_t['wa_error'] ); ?>">
				<div class="pk-conn-row">
						<div class="pk-conn-field pk-conn-field--prefix">
								<span class="pk-conn-field-label" aria-hidden="true"><?php echo esc_html( $pk_auth_t['prefix'] ); ?></span>
								<span class="pk-conn-prefix-box">
										<svg class="pk-conn-flag" viewBox="0 0 16 11" aria-hidden="true"><rect width="16" height="11" fill="#C1272D"/><polygon points="8,2.1 8.7,4.3 11,4.3 9.1,5.6 9.8,7.8 8,6.5 6.2,7.8 6.9,5.6 5,4.3 7.3,4.3" fill="none" stroke="#006233" stroke-width=".7"/></svg>
										<span class="pk-conn-prefix-txt">MA<br>+212</span>
										<span class="pk-conn-caret" aria-hidden="true">&#9662;</span>
								</span>
						</div>
						<div class="pk-conn-field pk-conn-grow">
								<label class="pk-conn-field-label" for="pk-conn-numero"><?php echo esc_html( $pk_auth_t['phone'] ); ?></label>
								<input class="pk-conn-input" id="pk-conn-numero" name="pk_conn_numero" type="tel" inputmode="numeric" autocomplete="tel" placeholder="<?php echo esc_attr( $pk_auth_t['phone'] ); ?>">
						</div>
				</div>
				<button type="button" class="pk-conn-go" id="pk-conn-wa-go"><?php echo esc_html( $pk_auth_t['wa_continue'] ); ?></button>
				<p class="pk-conn-feedback" aria-live="polite"></p>
				<p class="pk-conn-suite" id="pk-conn-suite" hidden>
						<?php echo esc_html( $pk_auth_t['suite'] ); ?><br>
						<a id="pk-conn-suite-link" href="#" target="_blank" rel="noopener">wa.me</a>
				</p>
		</div>
		<?php endif; ?>

		<?php if ( $pk_legal_links ) : ?>
		<p class="pk-conn-legal">
				<?php
				echo esc_html( $pk_auth_t['legal_a'] );
				foreach ( $pk_legal_links as $pk_i => $pk_link ) {
					if ( $pk_i > 0 ) {
						echo esc_html( $pk_legal_seps[ $pk_i - 1 ] ?? $pk_legal_seps[0] );
					}
					echo '<a href="' . esc_url( $pk_link['url'] ) . '">' . esc_html( $pk_link['label'] ) . '</a>';
				}
				echo esc_html( $pk_auth_t['legal_d'] );
				?>
		</p>
		<?php endif; ?>

		<p class="pk-conn-or"><?php echo esc_html( $pk_auth_t['or'] ); ?></p>

		<button type="button" class="pk-conn-alt" disabled aria-disabled="true" title="<?php echo esc_attr( $pk_auth_t['soon'] ); ?>">
				<svg width="18" height="18" viewBox="0 0 18 18" aria-hidden="true"><path fill="#4285F4" d="M17.6 9.2c0-.6-.1-1.2-.2-1.8H9v3.4h4.8c-.2 1.1-.8 2-1.8 2.6v2.2h2.9c1.7-1.6 2.7-3.9 2.7-6.4z"/><path fill="#34A853" d="M9 18c2.4 0 4.5-.8 6-2.2l-2.9-2.2c-.8.6-1.9.9-3.1.9-2.4 0-4.4-1.6-5.1-3.8H.9v2.3C2.4 16 5.5 18 9 18z"/><path fill="#FBBC05" d="M3.9 10.7c-.2-.6-.3-1.2-.3-1.7s.1-1.2.3-1.7V5H.9C.3 6.2 0 7.6 0 9s.3 2.8.9 4l3-2.3z"/><path fill="#EA4335" d="M9 3.6c1.3 0 2.5.5 3.4 1.3l2.6-2.6C13.5.9 11.4 0 9 0 5.5 0 2.4 2 .9 5l3 2.3C4.6 5.2 6.6 3.6 9 3.6z"/></svg>
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
</main>

<?php wp_footer(); ?>
</body>
</html>
