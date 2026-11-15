<?php
/**
 * Template : Mon espace (connexion propriétaire et acheteur).
 *
 * Connexion obligatoire. Un seul tableau de bord pour les deux profils,
 * aligné sur la maquette validée de l'espace connecté : tuiles Contacts,
 * Favoris, Mes annonces (ou Déposer une annonce quand le compte n'a
 * encore rien publié), puis Confidentialité, Langue, FAQ, Déconnexion.
 * Chaque panneau est branché sur les données réelles du site : annonces
 * Estatik de l'utilisateur, compteurs de vues et de favoris, demandes de
 * contact enregistrées par le plugin, favoris du navigateur synchronisés
 * comme sur la page Favoris. Phase 1 (authentification e-mail) : le
 * panneau Contacts d'un compte sans annonce reste pédagogique — le
 * rattachement des demandes au numéro du compte arrivera avec la
 * connexion WhatsApp (phase 2).
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

// --- Demandes de contact par annonce (plugin : pk_interest_events) ---
// Lecture seule, aucun numéro n'est lu ni affiché : seul le compteur
// par annonce du propriétaire connecté est calculé.
$pk_contact_counts = array();
$pk_contacts_total = 0;
if ( $my_listings ) {
	global $wpdb;
	$pk_events_table = $wpdb->prefix . 'pk_interest_events';
	$pk_table_ok     = (string) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $pk_events_table ) ) === $pk_events_table; // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	if ( $pk_table_ok ) {
		$pk_ids          = array_map( static fn( $l ): int => (int) $l->ID, $my_listings );
		$pk_placeholders = implode( ',', array_fill( 0, count( $pk_ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table du plugin vérifiée ci-dessus, identifiants entiers liés par prepare.
		$pk_rows = $wpdb->get_results( $wpdb->prepare( "SELECT property_id, COUNT(*) AS total FROM {$pk_events_table} WHERE property_id IN ({$pk_placeholders}) GROUP BY property_id", $pk_ids ) );
		foreach ( (array) $pk_rows as $pk_row ) {
			$pk_contact_counts[ (int) $pk_row->property_id ] = (int) $pk_row->total;
			$pk_contacts_total += (int) $pk_row->total;
		}
	}
}

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
$has_listings = ! empty( $my_listings );

$status_labels = array(
	'actif'               => __( 'En ligne', 'partikulier' ),
	'vendu'               => __( 'Vendu', 'partikulier' ),
	'loue'                => __( 'Loué', 'partikulier' ),
			'archive'             => __( 'Archivé', 'partikulier' ),
			'pause'               => __( 'En pause', 'partikulier' ),
	'indisponible'        => __( 'Indisponible', 'partikulier' ),
	'refuse'              => __( 'Refusée', 'partikulier' ),
	'en_attente_whatsapp' => __( 'En attente de validation WhatsApp', 'partikulier' ),
);

/* Libellés trilingues propres à l'espace (pattern de page-favoris.php :
 * tableau local, langue courante via Polylang). Les libellés déjà portés
 * par le code existant (statuts, KPI, actions) restent servis par __(). */
$pk_esp_lang = 'fr';
if ( function_exists( 'pll_current_language' ) && pll_current_language() ) {
	$pk_esp_lang = sanitize_key( (string) pll_current_language() );
}
$pk_esp_i18n = array(
	'fr' => array(
		'space_label'      => 'Mon espace',
		'hello_accent'     => 'voici votre espace.',
		'hub_label'        => 'Que voulez-vous faire ?',
		'contacts'         => 'Contacts',
		'favorites'        => 'Favoris',
		'my_listings'      => 'Mes annonces',
		'deposit'          => 'Déposer une annonce',
		'privacy'          => 'Confidentialité',
		'language'         => 'Langue',
		'faq'              => 'FAQ',
		'logout'           => 'Déconnexion',
		'first_name'       => 'Prénom et nom',
		'email'            => 'E-mail',
		'phone'            => 'Numéro de téléphone',
		'requests'         => 'Demandes de contact',
		'contacts_owner'   => 'Les demandes reçues sur vos annonces. Un clic sur « Voir le numéro » ou WhatsApp compte comme une demande.',
		'contacts_none'    => 'Aucune demande reçue pour le moment. Vos annonces restent visibles par tous les visiteurs.',
		'contacts_buyer_t' => 'Vos demandes de contact',
		'contacts_buyer'   => 'Ouvrez une annonce et affichez le numéro du propriétaire pour le contacter directement, sans intermédiaire. Les demandes sont limitées chaque jour pour éviter les abus.',
		'browse'           => 'Parcourir les annonces',
		'fav_empty_t'      => 'Aucun favori pour le moment',
		'fav_empty'        => 'Cliquez sur le cœur d’une annonce pour la retrouver ici. Vos favoris sont conservés dans ce navigateur et synchronisés avec votre compte.',
		'fav_note'         => 'Ces favoris sont enregistrés dans votre navigateur. Ils ne suivent pas d’un appareil à l’autre et disparaissent si vous effacez vos données de navigation.',
		'privacy_intro'    => 'Vos informations de compte et vos droits. pep.ma ne partage pas vos annonces : les échanges se font entre particuliers, sans intermédiaire.',
		'change_password'  => 'Changer de mot de passe',
		'rights'           => 'Vos droits : accès, rectification et suppression de vos données depuis la politique de confidentialité.',
		'open_privacy'     => 'Lire la politique de confidentialité',
		'language_pref'    => 'Langue préférée',
		'language_note'    => 'Le site et vos annonces s’affichent dans la langue choisie. Vos annonces restent visibles dans toutes les langues.',
		'faq_intro'        => 'Une question sur votre compte, vos annonces ou les demandes de contact ? La réponse est probablement dans la FAQ.',
		'open_faq'         => 'Ouvrir la FAQ',
		'contact_us'       => 'Nous contacter',
		'per_listing'      => '%d vues · %d favoris · %d demandes',
		'panel_default'    => 'contacts',
	),
	'en' => array(
		'space_label'      => 'My space',
		'hello_accent'     => 'welcome to your space.',
		'hub_label'        => 'What would you like to do?',
		'contacts'         => 'Contacts',
		'favorites'        => 'Favorites',
		'my_listings'      => 'My listings',
		'deposit'          => 'List a property',
		'privacy'          => 'Privacy',
		'language'         => 'Language',
		'faq'              => 'FAQ',
		'logout'           => 'Log out',
		'first_name'       => 'First and last name',
		'email'            => 'Email',
		'phone'            => 'Phone number',
		'requests'         => 'Contact requests',
		'contacts_owner'   => 'Requests received on your listings. Viewing a number or opening WhatsApp counts as one request.',
		'contacts_none'    => 'No requests yet. Your listings stay visible to every visitor.',
		'contacts_buyer_t' => 'Your contact requests',
		'contacts_buyer'   => 'Open a listing and reveal the owner’s number to contact them directly, with no middleman. Requests are limited each day to prevent abuse.',
		'browse'           => 'Browse listings',
		'fav_empty_t'      => 'No favorites yet',
		'fav_empty'        => 'Tap the heart on a listing to find it here. Your favorites are kept in this browser and synced with your account.',
		'fav_note'         => 'These favorites are stored in your browser. They do not follow you across devices and disappear if you clear your browsing data.',
		'privacy_intro'    => 'Your account details and your rights. pep.ma never shares your listings: everything happens between individuals, with no middleman.',
		'change_password'  => 'Change password',
		'rights'           => 'Your rights: access, rectify and delete your data from the privacy policy.',
		'open_privacy'     => 'Read the privacy policy',
		'language_pref'    => 'Preferred language',
		'language_note'    => 'The site and your listings are shown in the selected language. Your listings stay visible in every language.',
		'faq_intro'        => 'A question about your account, your listings or contact requests? The answer is most likely in the FAQ.',
		'open_faq'         => 'Open the FAQ',
		'contact_us'       => 'Contact us',
		'per_listing'      => '%d views · %d favorites · %d requests',
		'panel_default'    => 'contacts',
	),
	'ar' => array(
		'space_label'      => 'مساحتي',
		'hello_accent'     => 'هذه مساحتك.',
		'hub_label'        => 'ماذا تريد أن تفعل؟',
		'contacts'         => 'جهات الاتصال',
		'favorites'        => 'المفضلة',
		'my_listings'      => 'إعلاناتي',
		'deposit'          => 'نشر إعلان',
		'privacy'          => 'الخصوصية',
		'language'         => 'اللغة',
		'faq'              => 'الأسئلة الشائعة',
		'logout'           => 'تسجيل الخروج',
		'first_name'       => 'الاسم واللقب',
		'email'            => 'البريد الإلكتروني',
		'phone'            => 'رقم الهاتف',
		'requests'         => 'طلبات الاتصال',
		'contacts_owner'   => 'الطلبات الواردة على إعلاناتك. كل نقرة على «عرض الرقم» أو واتساب تُحتسب طلباً واحداً.',
		'contacts_none'    => 'لا توجد طلبات بعد. إعلاناتك تبقى ظاهرة لجميع الزوار.',
		'contacts_buyer_t' => 'طلبات الاتصال الخاصة بك',
		'contacts_buyer'   => 'افتح إعلاناً واعرض رقم المالك للتواصل معه مباشرة، دون وسيط. الطلبات محدودة كل يوم لمنع الإساءة.',
		'browse'           => 'تصفح الإعلانات',
		'fav_empty_t'      => 'لا توجد مفضلات بعد',
		'fav_empty'        => 'انقر على قلب الإعلان لتجده هنا. تُحفظ مفضلتك في هذا المتصفح وتُزامَن مع حسابك.',
		'fav_note'         => 'تُحفظ هذه المفضلة في متصفحك. لا تنتقل بين الأجهزة وتختفي إذا مسحت بيانات التصفح.',
		'privacy_intro'    => 'معلومات حسابك وحقوقك. pep.ma لا يشارك إعلاناتك: كل شيء يتم بين الأفراد، دون وسيط.',
		'change_password'  => 'تغيير كلمة المرور',
		'rights'           => 'حقوقك: الاطلاع على بياناتك وتصحيحها وحذفها انطلاقاً من سياسة الخصوصية.',
		'open_privacy'     => 'قراءة سياسة الخصوصية',
		'language_pref'    => 'اللغة المفضلة',
		'language_note'    => 'يظهر الموقع وإعلاناتك باللغة المختارة. تبقى إعلاناتك ظاهرة بجميع اللغات.',
		'faq_intro'        => 'سؤال حول حسابك أو إعلاناتك أو طلبات الاتصال؟ الجواب غالباً في الأسئلة الشائعة.',
		'open_faq'         => 'فتح الأسئلة الشائعة',
		'contact_us'       => 'اتصل بنا',
		'per_listing'      => '%d مشاهدة · %d مفضلة · %d طلبات',
		'panel_default'    => 'contacts',
	),
);
$pk_t = $pk_esp_i18n[ $pk_esp_lang ] ?? $pk_esp_i18n['fr'];

// Téléphone du compte, uniquement s'il est réellement renseigné.
$pk_user_phone = '';
foreach ( array( '_pk_phone', 'phone', 'billing_phone', 'es_phone' ) as $pk_phone_key ) {
	$pk_candidate = trim( (string) get_user_meta( $current_user->ID, $pk_phone_key, true ) );
	if ( '' !== $pk_candidate ) {
		$pk_user_phone = $pk_candidate;
		break;
	}
}

// Initiales pour l'avatar (deux premiers mots du nom affiché).
$pk_name_source = trim( (string) $current_user->display_name );
if ( '' === $pk_name_source ) {
	$pk_name_source = (string) $current_user->user_login;
}
$pk_words     = preg_split( '/\s+/', $pk_name_source ) ?: array( $pk_name_source );
$pk_initials  = '';
foreach ( array_slice( $pk_words, 0, 2 ) as $pk_word ) {
	$pk_initials .= mb_strtoupper( mb_substr( $pk_word, 0, 1 ) );
}

$pk_logout_url  = wp_logout_url( pk_localized_home_url( $pk_esp_lang ) );
$pk_default_tab = $has_listings ? 'panel-annonces' : 'panel-contacts';
?>

<section class="pk-dashboard pk-espace">
		<div class="pk-container">
                <?php echo Partikulier_Geo::breadcrumbs_html(); // phpcs:ignore ?>

				<header class="pk-espace-head">
						<div class="pk-espace-identity">
								<span class="pk-espace-avatar" aria-hidden="true"><?php echo esc_html( $pk_initials ); ?></span>
								<div class="pk-espace-identity-text">
										<p class="pk-editorial-kicker"><?php echo esc_html( $pk_t['space_label'] ); ?></p>
										<h1 class="pk-dashboard-title">
												<?php
												printf(
														/* translators: %s : nom de l'utilisateur */
														esc_html__( 'Bonjour %s,', 'partikulier' ),
														esc_html( $current_user->display_name )
												);
												?><span class="pk-hero-accent"><?php echo esc_html( $pk_t['hello_accent'] ); ?></span>
										</h1>
										<p class="pk-espace-email"><?php echo esc_html( $current_user->user_email ); ?></p>
								</div>
						</div>
						<div class="pk-dashboard-actions">
								<a class="pk-btn pk-btn-outline" href="<?php echo esc_url( pk_properties_archive_url() ); ?>"><?php esc_html_e( 'Rechercher un bien', 'partikulier' ); ?></a>
								<a class="pk-btn pk-btn-primary" href="<?php echo esc_url( pk_page_url( 'deposer', '/deposer/' ) ); ?>">
										<span aria-hidden="true">+</span> <?php esc_html_e( 'Nouvelle annonce', 'partikulier' ); ?>
								</a>
						</div>
				</header>

				<nav class="pk-hub" aria-label="<?php echo esc_attr( $pk_t['space_label'] ); ?>">
						<p class="pk-hub-title"><?php echo esc_html( $pk_t['hub_label'] ); ?></p>
						<div class="pk-hub-grid">
								<button type="button" class="pk-hub-tile" data-panel="panel-contacts" aria-controls="panel-contacts">
										<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
										<span class="pk-hub-tile-label"><?php echo esc_html( $pk_t['contacts'] ); ?></span>
										<?php if ( $pk_contacts_total > 0 ) : ?>
										<span class="pk-hub-badge"><?php echo esc_html( number_format_i18n( $pk_contacts_total ) ); ?></span>
										<?php endif; ?>
								</button>
								<button type="button" class="pk-hub-tile" data-panel="panel-favoris" aria-controls="panel-favoris">
										<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
										<span class="pk-hub-tile-label"><?php echo esc_html( $pk_t['favorites'] ); ?></span>
								</button>
								<?php if ( $has_listings ) : ?>
								<button type="button" class="pk-hub-tile" data-panel="panel-annonces" aria-controls="panel-annonces">
										<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 10.5 12 3l9 7.5"/><path d="M5 9.6V21h14V9.6"/><path d="M9.5 21v-6h5v6"/></svg>
										<span class="pk-hub-tile-label"><?php echo esc_html( $pk_t['my_listings'] ); ?></span>
										<span class="pk-hub-badge"><?php echo esc_html( number_format_i18n( count( $my_listings ) ) ); ?></span>
								</button>
								<?php else : ?>
								<a class="pk-hub-tile pk-hub-tile-cta" href="<?php echo esc_url( pk_page_url( 'deposer', '/deposer/' ) ); ?>">
										<svg viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 5v14"/><path d="M5 12h14"/></svg>
										<span class="pk-hub-tile-label"><?php echo esc_html( $pk_t['deposit'] ); ?></span>
								</a>
								<?php endif; ?>
						</div>
						<div class="pk-hub-rows">
								<button type="button" class="pk-hub-row" data-panel="panel-confidentialite" aria-controls="panel-confidentialite">
										<span><?php echo esc_html( $pk_t['privacy'] ); ?></span>
										<span class="pk-hub-chevron" aria-hidden="true">›</span>
								</button>
								<button type="button" class="pk-hub-row" data-panel="panel-langue" aria-controls="panel-langue">
										<span><?php echo esc_html( $pk_t['language'] ); ?></span>
										<span class="pk-hub-row-value"><?php echo esc_html( array( 'fr' => 'Français', 'en' => 'English', 'ar' => 'العربية' )[ $pk_esp_lang ] ?? 'Français' ); ?></span>
										<span class="pk-hub-chevron" aria-hidden="true">›</span>
								</button>
								<button type="button" class="pk-hub-row" data-panel="panel-faq" aria-controls="panel-faq">
										<span><?php echo esc_html( $pk_t['faq'] ); ?></span>
										<span class="pk-hub-chevron" aria-hidden="true">›</span>
								</button>
								<a class="pk-hub-row pk-hub-row-logout" href="<?php echo esc_url( $pk_logout_url ); ?>">
										<span><?php echo esc_html( $pk_t['logout'] ); ?></span>
										<span class="pk-hub-chevron" aria-hidden="true">›</span>
								</a>
						</div>
				</nav>

				<div class="pk-espace-panels" data-default-panel="<?php echo esc_attr( $pk_default_tab ); ?>">

						<section class="pk-espace-panel" id="panel-contacts" aria-label="<?php echo esc_attr( $pk_t['contacts'] ); ?>">
								<h2 class="pk-espace-panel-title"><?php echo esc_html( $pk_t['contacts'] ); ?></h2>
								<?php if ( $has_listings ) : ?>
										<p class="pk-espace-panel-text">
												<?php echo esc_html( $pk_t['contacts_owner'] ); ?>
												<strong><?php echo esc_html( number_format_i18n( $pk_contacts_total ) ); ?> <?php echo esc_html( mb_strtolower( $pk_t['requests'] ) ); ?></strong>
										</p>
										<?php if ( $pk_contacts_total > 0 ) : ?>
										<ul class="pk-espace-contact-list">
												<?php foreach ( $my_listings as $listing ) : ?>
														<?php $pk_req = (int) ( $pk_contact_counts[ $listing->ID ] ?? 0 ); ?>
														<?php if ( $pk_req > 0 ) : ?>
														<li>
																<a href="<?php echo esc_url( get_permalink( $listing->ID ) ); ?>"><?php echo esc_html( get_the_title( $listing->ID ) ); ?></a>
																<span><?php printf( esc_html( $pk_t['per_listing'] ), (int) get_post_meta( $listing->ID, '_pk_views', true ), (int) ( class_exists( 'Partikulier_Owner_Insights' ) ? Partikulier_Owner_Insights::favorite_count( $listing->ID ) : 0 ), (int) $pk_req ); ?></span>
														</li>
														<?php endif; ?>
												<?php endforeach; ?>
										</ul>
										<?php else : ?>
										<p class="pk-espace-panel-text"><?php echo esc_html( $pk_t['contacts_none'] ); ?></p>
										<?php endif; ?>
								<?php else : ?>
										<h3 class="pk-espace-panel-subtitle"><?php echo esc_html( $pk_t['contacts_buyer_t'] ); ?></h3>
										<p class="pk-espace-panel-text"><?php echo esc_html( $pk_t['contacts_buyer'] ); ?></p>
										<a class="pk-btn pk-btn-primary" href="<?php echo esc_url( pk_properties_archive_url() ); ?>"><?php echo esc_html( $pk_t['browse'] ); ?></a>
								<?php endif; ?>
						</section>

						<section class="pk-espace-panel" id="panel-favoris" aria-label="<?php echo esc_attr( $pk_t['favorites'] ); ?>">
								<h2 class="pk-espace-panel-title"><?php echo esc_html( $pk_t['favorites'] ); ?></h2>
								<div id="pk-favorites-grid" class="pk-grid pk-grid-3" aria-live="polite"></div>
								<div id="pk-favorites-empty" class="pk-favorites-empty" hidden>
										<p class="pk-favorites-empty-title"><?php echo esc_html( $pk_t['fav_empty_t'] ); ?></p>
										<p><?php echo esc_html( $pk_t['fav_empty'] ); ?></p>
										<a class="pk-btn pk-btn-primary" href="<?php echo esc_url( pk_properties_archive_url() ); ?>"><?php echo esc_html( $pk_t['browse'] ); ?></a>
								</div>
								<p class="pk-favorites-note"><?php echo esc_html( $pk_t['fav_note'] ); ?></p>
						</section>

						<section class="pk-espace-panel" id="panel-annonces" aria-label="<?php echo esc_attr( $pk_t['my_listings'] ); ?>">
								<h2 class="pk-espace-panel-title"><?php echo esc_html( $pk_t['my_listings'] ); ?></h2>

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
										array(
												'value' => (int) $pk_contacts_total,
												'label' => $pk_t['requests'],
												'icon'  => '<path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/>',
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
																$views         = (int) get_post_meta( $listing->ID, '_pk_views', true );
																$saves         = class_exists( 'Partikulier_Owner_Insights' ) ? Partikulier_Owner_Insights::favorite_count( $listing->ID ) : 0;
																$requests      = (int) ( $pk_contact_counts[ $listing->ID ] ?? 0 );
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
																		<h3 class="pk-listing-name">
																				<?php if ( $trashed ) : ?>
																						<?php echo esc_html( $listing->post_title ); ?>
																				<?php else : ?>
																						<a href="<?php echo esc_url( get_permalink( $listing->ID ) ); ?>"><?php echo esc_html( $listing->post_title ); ?></a>
																				<?php endif; ?>
																		</h3>
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
																				<span class="pk-listing-views" aria-label="<?php echo esc_attr( $pk_t['requests'] ); ?>">
																					<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="13" height="13" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.92v3a2 2 0 0 1-2.18 2 19.79 19.79 0 0 1-8.63-3.07 19.5 19.5 0 0 1-6-6 19.79 19.79 0 0 1-3.07-8.67A2 2 0 0 1 4.11 2h3a2 2 0 0 1 2 1.72c.127.96.361 1.903.7 2.81a2 2 0 0 1-.45 2.11L8.09 9.91a16 16 0 0 0 6 6l1.27-1.27a2 2 0 0 1 2.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0 1 22 16.92z"/></svg>
																						<?php echo (int) $requests; ?>
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
						</section>

						<section class="pk-espace-panel" id="panel-confidentialite" aria-label="<?php echo esc_attr( $pk_t['privacy'] ); ?>">
								<h2 class="pk-espace-panel-title"><?php echo esc_html( $pk_t['privacy'] ); ?></h2>
								<p class="pk-espace-panel-text"><?php echo esc_html( $pk_t['privacy_intro'] ); ?></p>
								<dl class="pk-espace-info">
										<div class="pk-espace-info-row">
												<dt><?php echo esc_html( $pk_t['first_name'] ); ?></dt>
												<dd><?php echo esc_html( $current_user->display_name ); ?></dd>
										</div>
										<div class="pk-espace-info-row">
												<dt><?php echo esc_html( $pk_t['email'] ); ?></dt>
												<dd><?php echo esc_html( $current_user->user_email ); ?></dd>
										</div>
										<?php if ( '' !== $pk_user_phone ) : ?>
										<div class="pk-espace-info-row">
												<dt><?php echo esc_html( $pk_t['phone'] ); ?></dt>
												<dd><?php echo esc_html( $pk_user_phone ); ?></dd>
										</div>
										<?php endif; ?>
								</dl>
								<p class="pk-espace-panel-text"><?php echo esc_html( $pk_t['rights'] ); ?></p>
								<div class="pk-espace-panel-actions">
										<a class="pk-btn pk-btn-outline" href="<?php echo esc_url( add_query_arg( 'auth_item', 'reset-form', pk_page_url( 'connexion', '/connexion/' ) ) ); ?>"><?php echo esc_html( $pk_t['change_password'] ); ?></a>
										<?php
										$pk_privacy_page = get_page_by_path( 'politique-de-confidentialite', OBJECT, 'page' );
										if ( ! $pk_privacy_page && class_exists( 'Partikulier_Required_Pages' ) ) {
											$pk_privacy_page = Partikulier_Required_Pages::find( 'confidentialite' );
										}
										if ( $pk_privacy_page instanceof WP_Post && 'publish' === $pk_privacy_page->post_status ) :
										?>
										<a class="pk-btn pk-btn-primary" href="<?php echo esc_url( pk_page_url( 'politique-de-confidentialite', '' ) ); ?>"><?php echo esc_html( $pk_t['open_privacy'] ); ?></a>
										<?php endif; ?>
								</div>
						</section>

						<section class="pk-espace-panel" id="panel-langue" aria-label="<?php echo esc_attr( $pk_t['language'] ); ?>">
								<h2 class="pk-espace-panel-title"><?php echo esc_html( $pk_t['language'] ); ?></h2>
								<p class="pk-espace-panel-text"><?php echo esc_html( $pk_t['language_note'] ); ?></p>
								<div class="pk-espace-lang-list" role="list">
										<?php
										$pk_lang_names = array( 'fr' => 'Français', 'en' => 'English', 'ar' => 'العربية' );
										$pk_page_id    = (int) get_queried_object_id();
										$pk_langs      = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( array( 'fields' => 'slug' ) ) : array( $pk_esp_lang );
										foreach ( $pk_langs as $pk_lang ) :
												$pk_lang   = sanitize_key( (string) $pk_lang );
												$pk_target = $pk_page_id;
												if ( function_exists( 'pll_get_post' ) ) {
													$pk_translated = (int) pll_get_post( $pk_page_id, $pk_lang );
													if ( $pk_translated ) {
														$pk_target = $pk_translated;
													}
												}
												$pk_lang_url = $pk_target ? (string) get_permalink( $pk_target ) : pk_localized_home_url( $pk_lang );
												$is_current  = $pk_lang === $pk_esp_lang;
										?>
										<a class="pk-espace-lang-item<?php echo $is_current ? ' is-current' : ''; ?>" role="listitem" href="<?php echo esc_url( $pk_lang_url ); ?>"<?php echo $is_current ? ' aria-current="true"' : ''; ?> hreflang="<?php echo esc_attr( $pk_lang ); ?>">
												<span><?php echo esc_html( $pk_lang_names[ $pk_lang ] ?? strtoupper( $pk_lang ) ); ?></span>
												<?php if ( $is_current ) : ?><span class="pk-espace-lang-check" aria-hidden="true">✓</span><?php endif; ?>
										</a>
										<?php endforeach; ?>
								</div>
						</section>

						<section class="pk-espace-panel" id="panel-faq" aria-label="<?php echo esc_attr( $pk_t['faq'] ); ?>">
								<h2 class="pk-espace-panel-title"><?php echo esc_html( $pk_t['faq'] ); ?></h2>
								<p class="pk-espace-panel-text"><?php echo esc_html( $pk_t['faq_intro'] ); ?></p>
								<div class="pk-espace-panel-actions">
										<a class="pk-btn pk-btn-primary" href="<?php echo esc_url( pk_page_url( 'faq', '/faq/' ) ); ?>"><?php echo esc_html( $pk_t['open_faq'] ); ?></a>
										<a class="pk-btn pk-btn-outline" href="<?php echo esc_url( pk_page_url( 'contact', '/contact/' ) ); ?>"><?php echo esc_html( $pk_t['contact_us'] ); ?></a>
								</div>
						</section>

				</div>
		</div>
</section>

<?php
get_footer();
