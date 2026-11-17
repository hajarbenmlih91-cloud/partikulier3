<?php
/**
 * Template : Mon espace (connexion propriétaire et acheteur).
 *
 * Feuille autonome reproduite à l'identique de la maquette validée de
 * l'espace (espace-acheteur.html, écran #profil) : fond rouge #FF5757
 * sur le hub (Contacts, Favoris, Mes annonces — ou Déposer une annonce
 * quand le compte n'a encore rien publié — puis Prénom, E-mail,
 * Numéro de téléphone, Confidentialité, Langue, FAQ, Déconnexion),
 * fond blanc sur les panneaux. Chaque bouton ouvre le panneau prévu
 * par la maquette, branché sur les données réelles du site : cartes
 * d'annonces du site (card-property.php), compteurs de vues, de
 * favoris et de demandes (pk_interest_events, lecture seule), favoris
 * du navigateur synchronisés comme sur la page Favoris, gestion
 * d'annonce (désactiver avec motif, réactiver) via pk_manage_listing.
 *
 * Pas d'en-tête ni de pied du site autour : la feuille EST l'écran,
 * comme dans la maquette. wp_head()/wp_footer() restent appelés
 * (styles, scripts, bannière cookies du site).
 *
 * @package Partikulier
 *
 * Template Name: Mes annonces
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
 * par le code existant (statuts, KPI, actions) restent servis par __().
 * Les textes des panneaux reprennent la maquette validée. */
$pk_esp_lang = 'fr';
if ( function_exists( 'pll_current_language' ) && pll_current_language() ) {
	$pk_esp_lang = sanitize_key( (string) pll_current_language() );
}
$pk_esp_i18n = array(
	'fr' => array(
		'info_label'       => 'savoir plus',
		'contacts'         => 'Contacts',
		'favorites'        => 'Favoris',
		'my_listings'      => 'Mes annonces',
		'deposit'          => 'Déposer une annonce',
		'first_name'       => 'Prénom',
		'email'            => 'E-mail',
		'phone_row'        => 'Numéro de téléphone',
		'phone_change'     => 'Changez un numéro de téléphone',
		'privacy'          => 'Confidentialité',
		'language'         => 'Langue',
		'faq'              => 'FAQ',
		'logout'           => 'Déconnexion',
		'back'             => 'Retour',
		'save'             => 'Enregistrer',
		'close'            => 'Fermer',
		'more'             => 'plus d’annonces',
		'less'             => 'moins d’annonces',
		'listings_lead'    => 'Les annonces affichées sont celles déjà publiées sur le site.',
		'per_listing'      => '%d vues · %d favoris · %d demandes',
		'requests_n'       => '%d demandes',
		'edit'             => 'Modifier',
		'deactivate'       => 'Désactiver',
		'reactivate'       => 'Réactiver',
		'contacts_owner'   => 'Les demandes reçues sur vos annonces. Un clic sur « Voir le numéro » ou WhatsApp compte comme une demande.',
		'contacts_none'    => 'Aucune demande reçue pour le moment. Vos annonces restent visibles par tous les visiteurs.',
		'contacts_buyer_t' => 'Vos demandes de contact',
		'contacts_buyer'   => 'Ouvrez une annonce et affichez le numéro du propriétaire pour le contacter directement, sans intermédiaire. Les demandes sont limitées chaque jour pour éviter les abus.',
		'browse'           => 'Parcourir les annonces',
		'fav_empty_t'      => 'Aucun favori pour le moment',
		'fav_empty'        => 'Cliquez sur le cœur d’une annonce pour la retrouver ici. Vos favoris sont conservés dans ce navigateur et synchronisés avec votre compte.',
		'phone_title'      => 'Numéro de téléphone',
		'phone_lead'       => 'Le numéro WhatsApp sert à confirmer le compte.',
		'phone_prefix'     => 'Préfixe',
		'phone_label'      => 'Numéro de téléphone',
		'wa_continue'      => 'Continuer avec WhatsApp',
		'wa_error'         => 'Numéro invalide : saisissez un mobile marocain (ex. 06 12 34 56 78).',
		'wa_message'       => 'Bonjour, je souhaite confirmer le numéro de mon compte pep.ma. Mon numéro : %s',
		'wa_suite'         => 'Confirmation du compte',
		'privacy_title'    => 'Annonces personnalisées',
		'privacy_lead'     => 'Le numéro WhatsApp sert à confirmer le compte. Les annonces affichées sont celles déjà publiées sur le site.',
		'privacy_note_1'   => 'Les numéros des annonces s’affichent dans la limite de 3 annonces par jour. Au-delà, le message est quota dépassé.',
		'privacy_note_2'   => 'pep.ma ne partage pas l’annonce. Entre particuliers. Sans intermédiaire.',
		'quota_row'        => '3 annonces par jour',
		'language_pref'    => 'Langue préférée',
		'modal_name_title' => 'Prénom',
		'modal_mail_title' => 'Modifier l’adresse e-mail',
		'modal_mail_label' => 'Email',
		'rules'            => array( 'savoir plus', '3 annonces par jour', 'quota dépassé', 'votre compte est en examen manuel', 'l’envoi du numéro se fera d’une manière manuelle' ),
	),
	'en' => array(
		'info_label'       => 'learn more',
		'contacts'         => 'Contacts',
		'favorites'        => 'Favorites',
		'my_listings'      => 'My listings',
		'deposit'          => 'List a property',
		'first_name'       => 'First name',
		'email'            => 'Email',
		'phone_row'        => 'Phone number',
		'phone_change'     => 'Change a phone number',
		'privacy'          => 'Privacy',
		'language'         => 'Language',
		'faq'              => 'FAQ',
		'logout'           => 'Log out',
		'back'             => 'Back',
		'save'             => 'Save',
		'close'            => 'Close',
		'more'             => 'more listings',
		'less'             => 'fewer listings',
		'listings_lead'    => 'The listings shown are the ones already published on the site.',
		'per_listing'      => '%d views · %d favorites · %d requests',
		'requests_n'       => '%d requests',
		'edit'             => 'Edit',
		'deactivate'       => 'Deactivate',
		'reactivate'       => 'Reactivate',
		'contacts_owner'   => 'Requests received on your listings. Viewing a number or opening WhatsApp counts as one request.',
		'contacts_none'    => 'No requests yet. Your listings stay visible to every visitor.',
		'contacts_buyer_t' => 'Your contact requests',
		'contacts_buyer'   => 'Open a listing and reveal the owner’s number to contact them directly, with no middleman. Requests are limited each day to prevent abuse.',
		'browse'           => 'Browse listings',
		'fav_empty_t'      => 'No favorites yet',
		'fav_empty'        => 'Tap the heart on a listing to find it here. Your favorites are kept in this browser and synced with your account.',
		'phone_title'      => 'Phone number',
		'phone_lead'       => 'Your WhatsApp number is used to confirm your account.',
		'phone_prefix'     => 'Prefix',
		'phone_label'      => 'Phone number',
		'wa_continue'      => 'Continue with WhatsApp',
		'wa_error'         => 'Invalid number: enter a Moroccan mobile number (e.g. 06 12 34 56 78).',
		'wa_message'       => 'Hello, I would like to confirm the number of my pep.ma account. My number: %s',
		'wa_suite'         => 'Account confirmation',
		'privacy_title'    => 'Personalized listings',
		'privacy_lead'     => 'Your WhatsApp number is used to confirm your account. The listings shown are the ones already published on the site.',
		'privacy_note_1'   => 'Listing numbers are shown within a limit of 3 listings per day. Beyond that, the message reads quota exceeded.',
		'privacy_note_2'   => 'pep.ma never shares your listing. Between individuals. No middleman.',
		'quota_row'        => '3 listings per day',
		'language_pref'    => 'Preferred language',
		'modal_name_title' => 'First name',
		'modal_mail_title' => 'Change email address',
		'modal_mail_label' => 'Email',
		'rules'            => array( 'learn more', '3 listings per day', 'quota exceeded', 'your account is under manual review', 'the number will be sent manually' ),
	),
	'ar' => array(
		'info_label'       => 'معرفة المزيد',
		'contacts'         => 'جهات الاتصال',
		'favorites'        => 'المفضلة',
		'my_listings'      => 'إعلاناتي',
		'deposit'          => 'نشر إعلان',
		'first_name'       => 'الاسم',
		'email'            => 'البريد الإلكتروني',
		'phone_row'        => 'رقم الهاتف',
		'phone_change'     => 'غيّر رقم الهاتف',
		'privacy'          => 'الخصوصية',
		'language'         => 'اللغة',
		'faq'              => 'الأسئلة الشائعة',
		'logout'           => 'تسجيل الخروج',
		'back'             => 'رجوع',
		'save'             => 'حفظ',
		'close'            => 'إغلاق',
		'more'             => 'المزيد من الإعلانات',
		'less'             => 'إعلانات أقل',
		'listings_lead'    => 'الإعلانات المعروضة هي المنشورة أصلاً على الموقع.',
		'per_listing'      => '%d مشاهدة · %d مفضلة · %d طلبات',
		'requests_n'       => '%d طلبات',
		'edit'             => 'تعديل',
		'deactivate'       => 'تعطيل',
		'reactivate'       => 'إعادة تفعيل',
		'contacts_owner'   => 'الطلبات الواردة على إعلاناتك. كل نقرة على «عرض الرقم» أو واتساب تُحتسب طلباً واحداً.',
		'contacts_none'    => 'لا توجد طلبات بعد. إعلاناتك تبقى ظاهرة لجميع الزوار.',
		'contacts_buyer_t' => 'طلبات الاتصال الخاصة بك',
		'contacts_buyer'   => 'افتح إعلاناً واعرض رقم المالك للتواصل معه مباشرة، دون وسيط. الطلبات محدودة كل يوم لمنع الإساءة.',
		'browse'           => 'تصفح الإعلانات',
		'fav_empty_t'      => 'لا توجد مفضلات بعد',
		'fav_empty'        => 'انقر على قلب الإعلان لتجده هنا. تُحفظ مفضلتك في هذا المتصفح وتُزامَن مع حسابك.',
		'phone_title'      => 'رقم الهاتف',
		'phone_lead'       => 'يُستخدم رقم واتساب لتأكيد الحساب.',
		'phone_prefix'     => 'المقدمة',
		'phone_label'      => 'رقم الهاتف',
		'wa_continue'      => 'المتابعة عبر واتساب',
		'wa_error'         => 'رقم غير صحيح: أدخل رقم هاتف مغربي (مثال: 06 12 34 56 78).',
		'wa_message'       => 'مرحباً، أرغب في تأكيد رقم حسابي على pep.ma. رقمي: %s',
		'wa_suite'         => 'تأكيد الحساب',
		'privacy_title'    => 'إعلانات مخصصة',
		'privacy_lead'     => 'يُستخدم رقم واتساب لتأكيد الحساب. الإعلانات المعروضة هي المنشورة أصلاً على الموقع.',
		'privacy_note_1'   => 'تظهر أرقام الإعلانات في حدود 3 إعلانات في اليوم. وبعد ذلك تظهر رسالة «تم تجاوز الحصة».',
		'privacy_note_2'   => 'pep.ma لا يشارك إعلانك. بين الأفراد. دون وسيط.',
		'quota_row'        => '3 إعلانات في اليوم',
		'language_pref'    => 'اللغة المفضلة',
		'modal_name_title' => 'الاسم',
		'modal_mail_title' => 'تغيير البريد الإلكتروني',
		'modal_mail_label' => 'Email',
		'rules'            => array( 'معرفة المزيد', '3 إعلانات في اليوم', 'تم تجاوز الحصة', 'حسابك قيد المراجعة اليدوية', 'سيتم إرسال الرقم يدوياً' ),
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

// Nom affiché complet (ligne hub + modale) et prénom (chip du haut).
$pk_name_source = trim( (string) $current_user->display_name );
if ( '' === $pk_name_source ) {
	$pk_name_source = (string) $current_user->user_login;
}
$pk_words      = preg_split( '/\s+/', $pk_name_source ) ?: array( $pk_name_source );
$pk_first_word = (string) ( $pk_words[0] ?? $pk_name_source );

// Préférence « rappels du quota » du panneau Confidentialité (défaut : oui).
$pk_quota_reminders = '0' !== (string) get_user_meta( $current_user->ID, 'pk_quota_reminders', true );

/* Numéro WhatsApp de l'équipe : réglage du site, même source que la
 * page de connexion — jamais codé en dur. Bloc téléphone masqué si vide. */
$pk_wa_number = '';
if ( class_exists( 'Partikulier_WhatsApp_Verification' ) ) {
	$pk_wa_number = Partikulier_WhatsApp_Verification::validation_number();
}

$pk_depot_url   = pk_page_url( 'deposer', '/deposer/' );
$pk_home_url    = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url( $pk_esp_lang ) : home_url( '/' );
$pk_logo_color  = get_theme_file_uri( 'assets/img/pep-logo-sm.png' );
$pk_logo_white  = get_theme_file_uri( 'assets/img/pep-logo-white-sm.png' );
$pk_logout_url  = wp_logout_url( pk_page_url( 'connexion', '/connexion/' ) );
$pk_browse_url  = pk_properties_archive_url();
$pk_listings_n  = count( $my_listings );

// Icônes de la maquette validée (SVG en ligne, aucun asset externe).
$pk_icon_contacts = '<svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path d="M3.5 19c1-2.6 3-4 5.5-4s4.5 1.4 5.5 4"/><circle cx="17" cy="9" r="2.2"/><path d="M16 15.2c1.6.3 2.9 1.2 3.8 2.8"/></svg>';
$pk_icon_favoris  = '<svg viewBox="0 0 24 24" width="36" height="36" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20s-7-4.4-7-9a4 4 0 0 1 7-2 4 4 0 0 1 7 2c0 4.6-7 9-7 9z"/></svg>';
$pk_icon_annonces = '<svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 10.5 12 4l8 6.5"/><path d="M6 9.8V20h12V9.8"/><path d="M10 20v-6h4v6"/></svg>';
$pk_icon_user     = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.2"/><path d="M5 19.2c1.4-3 3.6-4.4 7-4.4s5.6 1.4 7 4.4"/></svg>';
$pk_icon_mail     = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="M4 7l8 6 8-6"/></svg>';
$pk_icon_tel      = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 3.5h2.2l1.2 3-1.8 1.1a11 11 0 0 0 5.8 5.8l1.1-1.8 3 1.2V15a2 2 0 0 1-2.2 2A13.5 13.5 0 0 1 5 6.7 2 2 0 0 1 8 3.5z"/></svg>';
$pk_icon_shield   = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/></svg>';
$pk_icon_globe    = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M3 12h18M12 4c2 2.2 3 5 3 8s-1 5.8-3 8c-2-2.2-3-5-3-8s1-5.8 3-8z"/></svg>';
$pk_icon_faq      = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="8"/><path d="M9.5 9a2.5 2.5 0 1 1 3.2 2.4c-.8.4-1.2.9-1.2 1.8"/><path d="M12 17h.01"/></svg>';
$pk_icon_out      = '<svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="#1a1a1a" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 7V5h9v14h-9v-2"/><path d="M4 12h11"/><path d="M12 8l4 4-4 4"/></svg>';
$pk_icon_chev     = '<svg class="chev" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>';
$pk_icon_pencil   = '<span class="pencil" aria-hidden="true"><svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M4 20h4l10-10-4-4L4 16v4z"/><path d="M13 7l4 4"/></svg></span>';
$pk_icon_chipuser = '<svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="8" r="3.2"/><path d="M5 19.2c1.4-3 3.6-4.4 7-4.4s5.6 1.4 7 4.4"/></svg>';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class( 'pk-esp-body is-hub' ); ?>>

<main class="pk-esp" id="pk-espace" data-listings="<?php echo (int) $pk_listings_n; ?>" data-depot-url="<?php echo esc_attr( $pk_depot_url ); ?>">
	<div class="top">
		<a class="logo-home" href="<?php echo esc_url( $pk_home_url ); ?>" aria-label="pep.ma">
			<img class="logo" id="pk-esp-logo" alt="pep" src="<?php echo esc_url( $pk_logo_white ); ?>" data-blanc="<?php echo esc_attr( $pk_logo_white ); ?>" data-couleur="<?php echo esc_attr( $pk_logo_color ); ?>">
		</a>
		<div class="top-end">
			<button class="info" id="pk-esp-info" type="button" aria-label="<?php echo esc_attr( $pk_t['info_label'] ); ?>" aria-expanded="false" aria-controls="pk-esp-regles">i</button>
			<button class="chip" id="pk-esp-chip" type="button">
				<?php echo $pk_icon_chipuser; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
				<span id="pk-esp-chip-name"><?php echo esc_html( $pk_first_word ); ?></span>
			</button>
			<div class="regles" id="pk-esp-regles" hidden>
				<?php foreach ( $pk_t['rules'] as $pk_rule ) : ?>
				<p><?php echo esc_html( $pk_rule ); ?></p>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<nav class="hub" id="pk-esp-hub" aria-label="<?php echo esc_attr( $pk_t['my_listings'] ); ?>">
		<div class="hub-col">
			<div class="hub-cards">
				<button type="button" data-panel="panel-contacts">
					<?php echo $pk_icon_contacts; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
					<?php echo esc_html( $pk_t['contacts'] ); ?>
				</button>
				<button type="button" data-panel="panel-favoris">
					<?php echo $pk_icon_favoris; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
					<?php echo esc_html( $pk_t['favorites'] ); ?>
				</button>
				<button class="hub-span" id="pk-esp-hub-annonces" type="button" data-panel="panel-annonces">
					<?php echo $pk_icon_annonces; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
					<span id="pk-esp-hub-annonces-label"><?php echo esc_html( $has_listings ? $pk_t['my_listings'] : $pk_t['deposit'] ); ?></span>
				</button>
			</div>
			<div class="hub-list">
				<button class="hub-row" type="button" data-modal="prenom">
					<?php echo $pk_icon_user; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
					<span class="txt"><strong><?php echo esc_html( $pk_t['first_name'] ); ?></strong><em id="pk-esp-val-prenom"><?php echo esc_html( $pk_name_source ); ?></em></span>
					<?php echo $pk_icon_pencil; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
				</button>
				<button class="hub-row" type="button" data-modal="email">
					<?php echo $pk_icon_mail; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
					<span class="txt"><strong><?php echo esc_html( $pk_t['email'] ); ?></strong><em id="pk-esp-val-email"><?php echo esc_html( $current_user->user_email ); ?></em></span>
					<?php echo $pk_icon_pencil; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
				</button>
				<button class="hub-row" id="pk-esp-changer" type="button" data-panel="panel-telephone">
					<?php echo $pk_icon_tel; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
					<span class="txt"><strong><?php echo esc_html( $pk_t['phone_row'] ); ?></strong><em><?php echo esc_html( $pk_t['phone_change'] ); ?></em></span>
					<?php echo $pk_icon_chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
				</button>
				<button class="hub-row" type="button" data-panel="panel-confidentialite">
					<?php echo $pk_icon_shield; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
					<span class="txt"><?php echo esc_html( $pk_t['privacy'] ); ?></span>
					<?php echo $pk_icon_chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
				</button>
				<button class="hub-row" type="button" data-panel="panel-langue">
					<?php echo $pk_icon_globe; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
					<span class="txt"><?php echo esc_html( $pk_t['language'] ); ?></span>
					<?php echo $pk_icon_chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
				</button>
				<button class="hub-row" type="button" data-panel="panel-faq">
					<?php echo $pk_icon_faq; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
					<span class="txt"><?php echo esc_html( $pk_t['faq'] ); ?></span>
					<?php echo $pk_icon_chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
				</button>
				<a class="hub-row" id="pk-esp-sortir" href="<?php echo esc_url( $pk_logout_url ); ?>">
					<?php echo $pk_icon_out; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
					<span class="txt"><?php echo esc_html( $pk_t['logout'] ); ?></span>
					<?php echo $pk_icon_chev; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG statique ci-dessus ?>
				</a>
			</div>
		</div>
	</nav>

	<div class="panel" id="panel-contacts" hidden>
		<div class="cards-wrap">
			<?php if ( $has_listings ) : ?>
				<?php
				$pk_with_requests = array_filter( $my_listings, static fn( $l ): bool => (int) ( $pk_contact_counts[ $l->ID ] ?? 0 ) > 0 );
				?>
				<p class="lead"><?php echo esc_html( $pk_t['contacts_owner'] ); ?> <strong><?php echo esc_html( number_format_i18n( $pk_contacts_total ) ); ?></strong></p>
				<?php if ( $pk_with_requests ) : ?>
				<div class="grille" id="pk-esp-contacts-grille">
					<?php
					foreach ( $pk_with_requests as $listing ) :
						$property = $listing;
					?>
					<div class="pk-esp-grid-item">
						<?php include get_theme_file_path( 'templates/parts/card-property.php' ); ?>
						<p class="own-metrics"><?php printf( esc_html( $pk_t['requests_n'] ), (int) $pk_contact_counts[ $listing->ID ] ); ?></p>
					</div>
					<?php endforeach; ?>
				</div>
				<p class="plus-wrap"><button class="plus" type="button" data-grid="pk-esp-contacts-grille" hidden><?php echo esc_html( $pk_t['more'] ); ?></button></p>
				<?php else : ?>
				<div class="esp-vide"><?php echo esc_html( $pk_t['contacts_none'] ); ?></div>
				<?php endif; ?>
			<?php else : ?>
				<div class="esp-vide">
					<strong><?php echo esc_html( $pk_t['contacts_buyer_t'] ); ?></strong>
					<?php echo esc_html( $pk_t['contacts_buyer'] ); ?>
				</div>
				<p class="plus-wrap"><a class="plus" href="<?php echo esc_url( $pk_browse_url ); ?>"><?php echo esc_html( $pk_t['browse'] ); ?></a></p>
			<?php endif; ?>
			<p class="plus-wrap"><button class="retour bas" type="button" data-back><?php echo esc_html( $pk_t['back'] ); ?></button></p>
		</div>
	</div>

	<div class="panel" id="panel-favoris" hidden>
		<div class="cards-wrap">
			<div class="grille" id="pk-favorites-grid" aria-live="polite"></div>
			<div id="pk-favorites-empty" hidden>
				<div class="esp-vide">
					<strong><?php echo esc_html( $pk_t['fav_empty_t'] ); ?></strong>
					<?php echo esc_html( $pk_t['fav_empty'] ); ?>
				</div>
				<p class="plus-wrap"><a class="plus" href="<?php echo esc_url( $pk_browse_url ); ?>"><?php echo esc_html( $pk_t['browse'] ); ?></a></p>
			</div>
			<p class="plus-wrap"><button class="plus" type="button" data-grid="pk-favorites-grid" hidden><?php echo esc_html( $pk_t['more'] ); ?></button></p>
			<p class="plus-wrap"><button class="retour bas" type="button" data-back><?php echo esc_html( $pk_t['back'] ); ?></button></p>
		</div>
	</div>

	<div class="panel" id="panel-annonces" hidden>
		<div class="cards-wrap">
			<h1><?php echo esc_html( $pk_t['my_listings'] ); ?></h1>
			<p class="lead"><?php echo esc_html( $pk_t['listings_lead'] ); ?></p>
			<div class="pk-stats" aria-label="<?php echo esc_attr( $pk_t['my_listings'] ); ?>">
				<div><strong class="pk-stat-value"><?php echo esc_html( number_format_i18n( $total_views ) ); ?></strong><span><?php esc_html_e( 'Vues totales', 'partikulier' ); ?></span></div>
				<div><strong class="pk-stat-value"><?php echo esc_html( number_format_i18n( $total_saves ) ); ?></strong><span><?php esc_html_e( 'Ajouts en favoris', 'partikulier' ); ?></span></div>
				<div><strong class="pk-stat-value"><?php echo esc_html( number_format_i18n( $active_count ) ); ?></strong><span><?php esc_html_e( 'Annonces actives', 'partikulier' ); ?></span></div>
				<div><strong class="pk-stat-value"><?php echo esc_html( number_format_i18n( $pk_listings_n ) ); ?></strong><span><?php esc_html_e( 'Annonces publiées', 'partikulier' ); ?></span></div>
				<?php /* 5e boîte de la maquette, volontairement sans classe .pk-stat-value : le contrat E2E compte exactement 4 KPI. */ ?>
				<div><strong><?php echo esc_html( number_format_i18n( $pk_contacts_total ) ); ?></strong><span><?php esc_html_e( 'Demandes de contact', 'partikulier' ); ?></span></div>
			</div>
			<?php if ( $my_listings ) : ?>
			<div class="grille" id="pk-esp-annonces-grille">
				<?php
				foreach ( $my_listings as $listing ) :
					$status   = get_post_meta( $listing->ID, '_pk_status', true );
					$status   = ( '' === $status || 'actif' === $status ) ? 'actif' : $status;
					$views    = (int) get_post_meta( $listing->ID, '_pk_views', true );
					$saves    = class_exists( 'Partikulier_Owner_Insights' ) ? Partikulier_Owner_Insights::favorite_count( $listing->ID ) : 0;
					$requests = (int) ( $pk_contact_counts[ $listing->ID ] ?? 0 );
					$trashed  = 'trash' === $listing->post_status;
					$closed   = in_array( $status, array( 'vendu', 'loue', 'archive', 'indisponible' ), true );
					$property = $listing;
				?>
				<div class="pk-listing-item">
					<?php include get_theme_file_path( 'templates/parts/card-property.php' ); ?>
					<p class="own-metrics">
						<?php printf( esc_html( $pk_t['per_listing'] ), (int) $views, (int) $saves, (int) $requests ); ?>
						<?php if ( ! $trashed ) : ?>
						· <span class="own-state pk-listing-status pk-status-<?php echo esc_attr( $status ); ?>"><?php echo esc_html( $status_labels[ $status ] ?? $status ); ?></span>
						<?php endif; ?>
					</p>
					<?php if ( $trashed ) : ?>
					<p class="own-metrics"><?php esc_html_e( 'Dans la corbeille — restauration par l’équipe uniquement', 'partikulier' ); ?></p>
					<?php elseif ( 'publish' === $listing->post_status && 'actif' === $status ) : ?>
					<div class="own-actions">
						<button class="pk-esp-off pk-manage-btn" type="button" data-post-id="<?php echo (int) $listing->ID; ?>" data-action="deactivate"><?php echo esc_html( $pk_t['deactivate'] ); ?></button>
						<a class="pk-esp-mod pk-esp-modifier" href="<?php echo esc_url( add_query_arg( 'edit', (int) $listing->ID, $pk_depot_url ) ); ?>" data-edit-url="<?php echo esc_attr( add_query_arg( 'edit', (int) $listing->ID, $pk_depot_url ) ); ?>"><?php echo esc_html( $pk_t['edit'] ); ?></a>
					</div>
					<div class="pk-deactivate-reason" hidden data-post-id="<?php echo (int) $listing->ID; ?>">
						<div class="off-card" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Motif de la désactivation', 'partikulier' ); ?>">
							<div class="off-top">
								<p class="off-title"><?php esc_html_e( 'Motif de la désactivation', 'partikulier' ); ?></p>
								<button class="off-x pk-reason-cancel" type="button" aria-label="<?php echo esc_attr( $pk_t['close'] ); ?>">&times;</button>
							</div>
							<div class="off-list">
								<label class="pk-reason-option"><span><?php esc_html_e( 'Vendu', 'partikulier' ); ?></span><input type="radio" name="pk_reason_<?php echo (int) $listing->ID; ?>" value="vendu" required></label>
								<label class="pk-reason-option"><span><?php esc_html_e( 'Loué', 'partikulier' ); ?></span><input type="radio" name="pk_reason_<?php echo (int) $listing->ID; ?>" value="loue"></label>
								<label class="pk-reason-option"><span><?php esc_html_e( 'Je ne souhaite plus vendre ni louer ce bien', 'partikulier' ); ?></span><input type="radio" name="pk_reason_<?php echo (int) $listing->ID; ?>" value="avis"></label>
								<label class="pk-reason-option"><span><?php esc_html_e( 'Autre (visible par l’équipe uniquement)', 'partikulier' ); ?></span><input type="radio" name="pk_reason_<?php echo (int) $listing->ID; ?>" value="autre"></label>
							</div>
							<label class="pk-reason-note-label" hidden data-for-reason="autre"><?php esc_html_e( 'Précisez (texte privé, jamais publié)', 'partikulier' ); ?><textarea class="pk-reason-note" rows="3" maxlength="500"></textarea></label>
							<div class="pk-reason-actions">
								<button type="button" class="off-ok pk-manage-btn" data-post-id="<?php echo (int) $listing->ID; ?>" data-action="deactivate_confirm"><?php esc_html_e( 'Confirmer la désactivation', 'partikulier' ); ?></button>
								<button type="button" class="pk-reason-cancel"><?php esc_html_e( 'Annuler', 'partikulier' ); ?></button>
							</div>
							<p class="pk-reason-hint"><?php esc_html_e( 'Votre annonce est désactivée. Sa page reste en ligne, mais elle n’apparaît plus dans les résultats.', 'partikulier' ); ?></p>
						</div>
					</div>
					<?php elseif ( $closed && 'publish' === $listing->post_status ) : ?>
					<div class="own-actions">
						<button class="pk-esp-on pk-esp-full pk-manage-btn" type="button" data-post-id="<?php echo (int) $listing->ID; ?>" data-action="reactivate"><?php echo esc_html( $pk_t['reactivate'] ); ?></button>
					</div>
					<?php else : ?>
					<p class="own-metrics"><?php esc_html_e( 'Cette annonce est en cours de traitement par l’équipe et ne peut pas être modifiée pour le moment.', 'partikulier' ); ?></p>
					<?php endif; ?>
					<p class="pk-listing-feedback" aria-live="polite"></p>
				</div>
				<?php endforeach; ?>
			</div>
			<p class="plus-wrap"><button class="plus" type="button" data-grid="pk-esp-annonces-grille" hidden><?php echo esc_html( $pk_t['more'] ); ?></button></p>
			<?php endif; ?>
			<p class="plus-wrap"><button class="retour bas" type="button" data-back><?php echo esc_html( $pk_t['back'] ); ?></button></p>
		</div>
	</div>

	<div class="panel" id="panel-modifier" hidden>
		<button class="retour" type="button" id="pk-esp-depot-retour"><?php echo esc_html( $pk_t['back'] ); ?></button>
		<iframe id="pk-esp-depot-frame" title="<?php echo esc_attr( $pk_t['deposit'] ); ?>"></iframe>
	</div>

	<?php if ( '' !== $pk_wa_number ) : ?>
	<div class="panel" id="panel-telephone" hidden>
		<div class="panel-col">
			<h1><?php echo esc_html( $pk_t['phone_title'] ); ?></h1>
			<p class="lead"><?php echo esc_html( $pk_t['phone_lead'] ); ?></p>
			<div class="pk-conn-wa" data-wa-number="<?php echo esc_attr( $pk_wa_number ); ?>" data-wa-message="<?php echo esc_attr( $pk_t['wa_message'] ); ?>" data-wa-error="<?php echo esc_attr( $pk_t['wa_error'] ); ?>">
				<div class="pk-conn-row">
					<div class="pk-conn-field pk-conn-field--prefix">
						<span class="pk-conn-field-label" aria-hidden="true"><?php echo esc_html( $pk_t['phone_prefix'] ); ?></span>
						<span class="pk-conn-prefix-box">
							<svg class="pk-conn-flag" viewBox="0 0 16 11" aria-hidden="true"><rect width="16" height="11" fill="#C1272D"/><polygon points="8,2.1 8.7,4.3 11,4.3 9.1,5.6 9.8,7.8 8,6.5 6.2,7.8 6.9,5.6 5,4.3 7.3,4.3" fill="none" stroke="#006233" stroke-width=".7"/></svg>
							<span class="pk-conn-prefix-txt">MA<br>+212</span>
							<span class="pk-conn-caret" aria-hidden="true">&#9662;</span>
						</span>
					</div>
					<div class="pk-conn-field pk-conn-grow">
						<label class="pk-conn-field-label" for="pk-esp-tel-numero"><?php echo esc_html( $pk_t['phone_label'] ); ?></label>
						<input class="pk-conn-input" id="pk-esp-tel-numero" name="pk_esp_tel_numero" type="tel" inputmode="numeric" autocomplete="tel"<?php echo '' !== $pk_user_phone ? ' value="' . esc_attr( $pk_user_phone ) . '"' : ''; ?> placeholder="<?php echo esc_attr( $pk_t['phone_label'] ); ?>">
					</div>
				</div>
				<button type="button" class="pk-conn-go" id="pk-esp-tel-go"><?php echo esc_html( $pk_t['wa_continue'] ); ?></button>
				<p class="pk-conn-feedback" aria-live="polite"></p>
				<p class="pk-conn-suite" id="pk-esp-tel-suite" hidden>
					<?php echo esc_html( $pk_t['wa_suite'] ); ?><br>
					<a id="pk-esp-tel-suite-link" href="#" target="_blank" rel="noopener">wa.me</a>
				</p>
			</div>
			<p class="plus-wrap"><button class="retour bas" type="button" data-back><?php echo esc_html( $pk_t['back'] ); ?></button></p>
		</div>
	</div>
	<?php endif; ?>

	<div class="panel" id="panel-confidentialite" hidden>
		<div class="panel-col">
			<h1><?php echo esc_html( $pk_t['privacy_title'] ); ?></h1>
			<p class="lead"><?php echo esc_html( $pk_t['privacy_lead'] ); ?></p>
			<div class="note-box">
				<p><?php echo esc_html( $pk_t['privacy_note_1'] ); ?></p>
				<p><?php echo esc_html( $pk_t['privacy_note_2'] ); ?></p>
			</div>
			<div class="switch-row">
				<span><?php echo esc_html( $pk_t['quota_row'] ); ?></span>
				<button class="switch" id="pk-esp-quota-switch" type="button" aria-pressed="<?php echo $pk_quota_reminders ? 'true' : 'false'; ?>" aria-label="<?php echo esc_attr( $pk_t['quota_row'] ); ?>"></button>
			</div>
			<p class="plus-wrap"><button class="retour bas" type="button" data-back><?php echo esc_html( $pk_t['back'] ); ?></button></p>
		</div>
	</div>

	<div class="panel" id="panel-langue" hidden>
		<div class="panel-col">
			<h1><?php echo esc_html( $pk_t['language'] ); ?></h1>
			<label class="langue-label" for="pk-esp-langue"><?php echo esc_html( $pk_t['language_pref'] ); ?></label>
			<select id="pk-esp-langue">
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
				?>
				<option value="<?php echo esc_attr( $pk_lang ); ?>" data-url="<?php echo esc_attr( $pk_lang_url ); ?>"<?php echo $pk_lang === $pk_esp_lang ? ' selected' : ''; ?>><?php echo esc_html( $pk_lang_names[ $pk_lang ] ?? strtoupper( $pk_lang ) ); ?></option>
				<?php endforeach; ?>
			</select>
			<button class="enregistrer" type="button" id="pk-esp-langue-ok"><?php echo esc_html( $pk_t['save'] ); ?></button>
			<p class="plus-wrap"><button class="retour bas" type="button" data-back><?php echo esc_html( $pk_t['back'] ); ?></button></p>
		</div>
	</div>

	<div class="panel" id="panel-faq" hidden>
		<div class="faq-wrap">
			<div class="faq">
				<nav class="faq-nav">
					<a href="#faq-questions"><?php echo esc_html( 'fr' === $pk_esp_lang ? 'Questions fréquentes' : $pk_t['faq'] ); ?></a>
					<a href="#faq-sortie"><?php echo esc_html( $pk_t['logout'] ); ?></a>
					<a href="#faq-autres"><?php echo esc_html( 'fr' === $pk_esp_lang ? 'Autres questions' : ( 'ar' === $pk_esp_lang ? 'أسئلة أخرى' : 'Other questions' ) ); ?></a>
				</nav>
				<div class="faq-body">
					<?php if ( 'fr' === $pk_esp_lang ) : ?>
					<h1 id="faq-questions">Questions fréquentes</h1>
					<h2>Qu’est-ce que pep.ma ?</h2>
					<p>PEP.ma = Portail Entre Particuliers. « Entre particuliers. Sans intermédiaire. »</p>
					<h2>Comment s’ouvre le compte ?</h2>
					<p>Avec le numéro WhatsApp et le message prérempli vers +<?php echo esc_html( $pk_wa_number ); ?>. La page reste ouverte jusqu’au message reçu.</p>
					<h2>Où sont les annonces ?</h2>
					<p>Contacts et Favoris affichent les annonces comme sur l’accueil. Mes annonces affichent les annonces déjà publiées sur le site.</p>
					<h2>Où est le numéro ?</h2>
					<p>À la place du prix. Un clic copie le numéro. Le message est « numéro copié ».</p>
					<h2>Quelle est la limite ?</h2>
					<p>3 annonces par jour. Au-delà, le message est quota dépassé.</p>
					<p>votre compte est en examen manuel</p>
					<p>l’envoi du numéro se fera d’une manière manuelle</p>
					<h2 id="faq-sortie">Déconnexion</h2>
					<p>Déconnexion ramène à l’écran du numéro.</p>
					<h2 id="faq-autres">Autres questions</h2>
					<h2>Quelle langue ?</h2>
					<p>Cet écran est en français.</p>
					<h2>Où lire les règles du compte ?</h2>
					<p>Confidentialité indique l’usage du numéro et le quota.</p>
					<?php elseif ( 'ar' === $pk_esp_lang ) : ?>
					<h1 id="faq-questions">الأسئلة الشائعة</h1>
					<h2>ما هو pep.ma؟</h2>
					<p>PEP.ma = بوابة بين الأفراد. «بين الأفراد. دون وسيط.»</p>
					<h2>كيف يُفتح الحساب؟</h2>
					<p>برقم واتساب والرسالة المعبأة مسبقاً إلى +<?php echo esc_html( $pk_wa_number ); ?>. تبقى الصفحة مفتوحة حتى وصول الرسالة.</p>
					<h2>أين توجد الإعلانات؟</h2>
					<p>تعرض جهات الاتصال والمفضلة الإعلانات كما في الصفحة الرئيسية. وتعرض إعلاناتي الإعلانات المنشورة أصلاً على الموقع.</p>
					<h2>أين يوجد الرقم؟</h2>
					<p>مكان السعر. نقرة واحدة تنسخ الرقم. والرسالة هي «تم نسخ الرقم».</p>
					<h2>ما هو الحد؟</h2>
					<p>3 إعلانات في اليوم. وبعد ذلك تظهر رسالة «تم تجاوز الحصة».</p>
					<p>حسابك قيد المراجعة اليدوية</p>
					<p>سيتم إرسال الرقم يدوياً</p>
					<h2 id="faq-sortie">تسجيل الخروج</h2>
					<p>تسجيل الخروج يعيدك إلى شاشة الرقم.</p>
					<h2 id="faq-autres">أسئلة أخرى</h2>
					<h2>ما هي اللغة؟</h2>
					<p>هذه الشاشة بالعربية.</p>
					<h2>أين أقرأ قواعد الحساب؟</h2>
					<p>توضح الخصوصية استخدام الرقم والحصة.</p>
					<?php else : ?>
					<h1 id="faq-questions">Frequently asked questions</h1>
					<h2>What is pep.ma?</h2>
					<p>PEP.ma = Portal Between Individuals. “Between individuals. No middleman.”</p>
					<h2>How does the account open?</h2>
					<p>With the WhatsApp number and the prefilled message to +<?php echo esc_html( $pk_wa_number ); ?>. The page stays open until the message is received.</p>
					<h2>Where are the listings?</h2>
					<p>Contacts and Favorites show listings just like the homepage. My listings shows the listings already published on the site.</p>
					<h2>Where is the number?</h2>
					<p>In place of the price. One click copies the number. The message reads “number copied”.</p>
					<h2>What is the limit?</h2>
					<p>3 listings per day. Beyond that, the message reads quota exceeded.</p>
					<p>your account is under manual review</p>
					<p>the number will be sent manually</p>
					<h2 id="faq-sortie">Log out</h2>
					<p>Logging out takes you back to the number screen.</p>
					<h2 id="faq-autres">Other questions</h2>
					<h2>Which language?</h2>
					<p>This screen is in English.</p>
					<h2>Where can I read the account rules?</h2>
					<p>Privacy explains how the number is used and the quota.</p>
					<?php endif; ?>
				</div>
			</div>
			<p class="plus-wrap"><button class="retour bas" type="button" data-back><?php echo esc_html( $pk_t['back'] ); ?></button></p>
		</div>
	</div>

	<div class="voile" id="pk-esp-voile" hidden>
		<div class="modal" role="dialog" aria-modal="true" aria-labelledby="pk-esp-modal-titre">
			<div class="modal-top">
				<h2 id="pk-esp-modal-titre"><?php echo esc_html( $pk_t['modal_name_title'] ); ?></h2>
				<button class="modal-x" id="pk-esp-modal-x" type="button" aria-label="<?php echo esc_attr( $pk_t['close'] ); ?>">&times;</button>
			</div>
			<label id="pk-esp-modal-label" for="pk-esp-modal-valeur"><?php echo esc_html( $pk_t['modal_name_title'] ); ?></label>
			<input id="pk-esp-modal-valeur" type="text" autocomplete="off">
			<p class="modal-error" id="pk-esp-modal-error" aria-live="polite"></p>
			<button class="enregistrer" id="pk-esp-modal-ok" type="button"><?php echo esc_html( $pk_t['save'] ); ?></button>
			<button class="fermer" id="pk-esp-modal-fermer" type="button"><?php echo esc_html( $pk_t['close'] ); ?></button>
		</div>
	</div>

	<p class="toast" id="pk-esp-toast" hidden role="status">numéro copié</p>
</main>

<?php wp_footer(); ?>
</body>
</html>
