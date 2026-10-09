<?php
/**
 * En-tete du theme.
 *
 * Structure calquee sur le kit e-commerce "Woo Shop" de Royal Elementor :
 * - Topbar sombre (promo + contact)
 * - Header blanc : logo, barre de recherche centrale, icones (favoris, compte)
 * - Nav uppercase avec dropdowns CSS + toggle JS vanilla (zero jQuery)
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}
?>
<!doctype html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo( 'charset' ); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="#111111">
<link rel="profile" href="https://gmpg.org/xfn/11">
<?php wp_head(); ?>
</head>

<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<a class="pk-skip-link" href="#main-content"><?php esc_html_e( 'Aller au contenu', 'partikulier' ); ?></a>

<!-- Topbar style Woo Shop -->
<div class="pk-topbar" role="complementary" aria-label="<?php esc_attr_e( 'Informations de contact', 'partikulier' ); ?>">
		<div class="pk-container pk-topbar-inner">
				<span class="pk-topbar-promo"><?php echo esc_html( Partikulier_Settings::get_localized( 'topbar_text' ) ); ?></span>
				<div class="pk-topbar-contact">
						<a href="<?php echo esc_url( pk_page_url( 'deposer', '/deposer/' ) ); ?>"><?php esc_html_e( 'Déposer une annonce', 'partikulier' ); ?></a>
						<a href="<?php echo esc_url( pk_properties_archive_url() ); ?>"><?php esc_html_e( 'Toutes les annonces', 'partikulier' ); ?></a>
						<?php if ( is_user_logged_in() ) : ?>
								<a href="<?php echo esc_url( pk_page_url( 'mes-annonces', '/mes-annonces/' ) ); ?>"><?php esc_html_e( 'Mon espace', 'partikulier' ); ?></a>
						<?php else : ?>
								<a href="<?php echo esc_url( pk_login_page_url( pk_page_url( 'mes-annonces', '/mes-annonces/' ) ) ); ?>"><?php esc_html_e( 'Se connecter', 'partikulier' ); ?></a>
						<?php endif; ?>
				</div>
		</div>
</div>

<header class="pk-site-header" id="site-header">
		<div class="pk-container pk-header-inner">
				<div class="pk-header-brand">
						<?php
						if ( function_exists( 'get_custom_logo' ) && has_custom_logo() ) {
								$pk_logo_html         = get_custom_logo();
								$pk_logo_home         = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' );
								$pk_logo_href_pattern = '/' . 'href' . '="[^"]*"' . '/';
								$pk_logo_html         = preg_replace( $pk_logo_href_pattern, 'href' . '="' . esc_url( $pk_logo_home ) . '"', (string) $pk_logo_html, 1 );
								echo $pk_logo_html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
						} else {
								$pk_home = esc_url( function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' ) );
								$pk_name = get_bloginfo( 'name' );
							?>
                        <a class="pk-logo-text" href="<?php echo $pk_home; // phpcs:ignore ?>" rel="home" aria-label="<?php echo esc_attr( sprintf( __( '%s, retour a l accueil', 'partikulier' ), $pk_name ) ); ?>">
								<span class="pk-logo-name">Partikulier<span class="pk-logo-tld">.ma</span></span>
						</a>
								<?php
						}
						?>
				</div>

				<div class="pk-header-search">
						<?php
						$pk_city_slug  = isset( $_GET['es_city'] ) && is_scalar( $_GET['es_city'] ) ? sanitize_title( wp_unslash( $_GET['es_city'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
						$pk_city_term  = '' !== $pk_city_slug ? get_term_by( 'slug', $pk_city_slug, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) : false;
						$pk_city_label = ( $pk_city_term && ! is_wp_error( $pk_city_term ) ) ? $pk_city_term->name : '';
						?>
						<form class="pk-search-bar pk-place-autocomplete" action="<?php echo esc_url( pk_properties_archive_url() ); ?>" method="get" role="search" aria-label="<?php esc_attr_e( 'Rechercher un bien par ville ou quartier', 'partikulier' ); ?>">
								<select name="es_type" aria-label="<?php esc_attr_e( 'Type de bien', 'partikulier' ); ?>">
										<option value=""><?php esc_html_e( 'Type de bien', 'partikulier' ); ?></option>
										<?php echo Partikulier_Geo::property_type_options( false ); // phpcs:ignore WordPress.Security.EscapeOutput ?>
								</select>
								<div class="pk-place-autocomplete-wrap pk-header-place">
										<input type="search" name="s" class="pk-place-input" id="pk-header-city" placeholder="<?php esc_attr_e( 'Ville, code postal, quartier…', 'partikulier' ); ?>" aria-label="<?php esc_attr_e( 'Rechercher une ville', 'partikulier' ); ?>" autocomplete="off" autocapitalize="off" spellcheck="false" role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="pk-header-city-list" data-pk-place-input="true" data-pk-place-value="pk-header-city-value" value="<?php echo esc_attr( $pk_city_label ); ?>">
										<ul class="pk-suggest pk-place-suggestions" id="pk-header-city-list" role="listbox" hidden></ul>
								</div>
								<input type="hidden" name="es_city" id="pk-header-city-value" value="<?php echo esc_attr( $pk_city_slug ); ?>">
								<?php
								$pk_header_action = isset( $_GET['es_action'] ) && is_scalar( $_GET['es_action'] ) ? sanitize_text_field( wp_unslash( $_GET['es_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
								if ( '' !== $pk_header_action ) :
									?>
								<input type="hidden" name="es_action" value="<?php echo esc_attr( $pk_header_action ); ?>">
								<?php endif; ?>
								<?php
								$pk_header_rent = isset( $_GET['pk_rent'] ) && is_scalar( $_GET['pk_rent'] ) ? sanitize_text_field( wp_unslash( $_GET['pk_rent'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
								if ( '' !== $pk_header_rent ) :
									?>
								<input type="hidden" name="pk_rent" value="<?php echo esc_attr( $pk_header_rent ); ?>">
								<?php endif; ?>
								<button type="submit" aria-label="<?php esc_attr_e( 'Rechercher', 'partikulier' ); ?>">
										<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
								</button>
						</form>
				</div>

				<div class="pk-header-actions">
						<?php
						// Sélecteur de langue interactif et universel (avec drapeaux et bascule mobile/desktop)
						$pk_current_lang = 'fr';
						if ( function_exists( 'pll_current_language' ) && pll_current_language() ) {
								$pk_current_lang = sanitize_key( (string) pll_current_language() );
						} elseif ( isset( $_GET['lang'] ) && in_array( $_GET['lang'], array( 'fr', 'ar', 'en' ), true ) ) {
								$pk_current_lang = sanitize_key( $_GET['lang'] );
						} elseif ( strpos( (string) get_locale(), 'ar' ) === 0 ) {
								$pk_current_lang = 'ar';
						} elseif ( strpos( (string) get_locale(), 'en' ) === 0 ) {
								$pk_current_lang = 'en';
						}

						$pk_flag_svg = static function( $slug ) {
								$slug = sanitize_key( $slug );
								if ( 'ar' === $slug ) {
										return '<svg class="pk-flag" viewBox="0 0 24 16" width="20" height="14" aria-hidden="true"><rect width="24" height="16" rx="2" fill="#c1272d"/><path d="M12 3.7l1.1 3.4h3.6l-2.9 2.1 1.1 3.4-2.9-2.1-2.9 2.1 1.1-3.4-2.9-2.1h3.6z" fill="none" stroke="#006233" stroke-width=".8"/></svg>';
								}
								if ( 'en' === $slug ) {
										return '<svg class="pk-flag" viewBox="0 0 24 16" width="20" height="14" aria-hidden="true"><rect width="24" height="16" rx="2" fill="#012169"/><path d="M0 0l24 16M24 0L0 16" stroke="#fff" stroke-width="2.5"/><path d="M0 0l24 16M24 0L0 16" stroke="#c8102e" stroke-width="1.2"/><path d="M12 0v16M0 8h24" stroke="#fff" stroke-width="4.5"/><path d="M12 0v16M0 8h24" stroke="#c8102e" stroke-width="2.5"/></svg>';
								}
								return '<svg class="pk-flag" viewBox="0 0 24 16" width="20" height="14" aria-hidden="true"><path d="M0 0h8v16H0z" fill="#002654"/><path d="M8 0h8v16H8z" fill="#fff"/><path d="M16 0h8v16h-8z" fill="#ce1126"/></svg>';
						};

						$pk_lang_names = array(
								'fr' => 'Français',
								'ar' => 'العربية',
								'en' => 'English',
						);
						?>
						<div class="pk-lang" data-pk-lang>
								<button type="button" class="pk-lang-toggle" aria-expanded="false" aria-haspopup="true" aria-label="<?php esc_attr_e( 'Choisir la langue', 'partikulier' ); ?>">
										<?php echo $pk_flag_svg( $pk_current_lang ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
										<span class="pk-lang-code"><?php echo esc_html( strtoupper( $pk_current_lang ) ); ?></span>
								</button>
								<ul class="pk-lang-menu" hidden>
										<?php
										$pk_raw_langs = function_exists( 'pll_the_languages' ) ? pll_the_languages( array( 'raw' => 1 ) ) : array();
										$pk_is_listings = defined( 'PARTIKULIER_ESTATIK_POST_TYPE' ) && is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE );
										foreach ( array( 'fr', 'ar', 'en' ) as $pk_slug ) :
												$pk_target_url = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url( $pk_slug ) : home_url( '/' . $pk_slug . '/' );
												if ( $pk_is_listings ) {
														$pk_target_url = trailingslashit( $pk_target_url ) . 'annonces/';
												} elseif ( ! empty( $pk_raw_langs[ $pk_slug ]['url'] ) && false === strpos( (string) $pk_raw_langs[ $pk_slug ]['url'], '/accueil-' ) ) {
														$pk_target_url = $pk_raw_langs[ $pk_slug ]['url'];
												}
										?>
												<li<?php echo ( $pk_slug === $pk_current_lang ) ? ' class="is-current"' : ''; ?>>
														<a href="<?php echo esc_url( $pk_target_url ); ?>" lang="<?php echo esc_attr( $pk_slug ); ?>" hreflang="<?php echo esc_attr( $pk_slug ); ?>">
																<?php echo $pk_flag_svg( $pk_slug ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
																<span class="pk-lang-abbr"><?php echo esc_html( strtoupper( $pk_slug ) ); ?></span>
																<span class="pk-lang-name"><?php echo esc_html( $pk_lang_names[ $pk_slug ] ); ?></span>
														</a>
												</li>
										<?php endforeach; ?>
								</ul>
						</div>
						<a class="pk-header-icon" href="<?php echo esc_url( pk_page_url( 'favoris', '/favoris/' ) ); ?>" aria-label="<?php esc_attr_e( 'Favoris', 'partikulier' ); ?>">
								<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.84 4.61a5.5 5.5 0 0 0-7.78 0L12 5.67l-1.06-1.06a5.5 5.5 0 0 0-7.78 7.78l1.06 1.06L12 21.23l7.78-7.78 1.06-1.06a5.5 5.5 0 0 0 0-7.78z"/></svg>
						</a>
						<?php if ( is_user_logged_in() ) : ?>
								<a class="pk-header-icon pk-header-cta pk-btn pk-btn-primary pk-btn-sm" href="<?php echo esc_url( pk_page_url( 'mes-annonces', '/mes-annonces/' ) ); ?>">
										<?php esc_html_e( 'Mon espace', 'partikulier' ); ?>
								</a>
						<?php else : ?>
								<a class="pk-header-icon pk-header-cta pk-btn pk-btn-primary pk-btn-sm" href="<?php echo esc_url( pk_page_url( 'deposer', '/deposer/' ) ); ?>">
										<?php esc_html_e( 'Déposer une annonce', 'partikulier' ); ?>
								</a>
						<?php endif; ?>
						<button class="pk-nav-toggle" type="button" aria-expanded="false" aria-controls="pk-mobile-menu" aria-label="<?php esc_attr_e( 'Ouvrir le menu', 'partikulier' ); ?>">
								<span class="pk-nav-toggle-bar" aria-hidden="true"></span>
								<span class="pk-nav-toggle-bar" aria-hidden="true"></span>
								<span class="pk-nav-toggle-bar" aria-hidden="true"></span>
						</button>
				</div>
		</div>




				<div class="pk-mobile-menu" id="pk-mobile-menu" hidden>
				<?php
				wp_nav_menu( array(
						'theme_location' => 'main',
						'container'      => false,
						'menu_class'     => 'pk-menu pk-menu-mobile',
						'depth'          => 1,
						'fallback_cb'    => array( 'Partikulier_Header', 'fallback_menu' ),
				) );
				?>
		</div>

		</header>

<nav class="pk-main-nav" aria-label="<?php esc_attr_e( 'Menu principal', 'partikulier' ); ?>">
		<div class="pk-container">
				<?php
				wp_nav_menu( array(
						'theme_location' => 'main',
						'container'      => false,
						'menu_class'     => 'pk-menu',
						'depth'          => 2,
						'fallback_cb'    => array( 'Partikulier_Header', 'fallback_menu' ),
						'walker'         => new Partikulier_Menu_Walker(),
				) );
				?>
		</div>
</nav>

<nav class="pk-mobile-sticky-cta" aria-label="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Actions rapides', 'Actions rapides', 'partikulier' ) ); ?>">
	<a class="pk-btn pk-btn-primary" href="<?php echo esc_url( pk_page_url( 'deposer', '/deposer/' ) ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Déposer une annonce', 'Déposer une annonce', 'partikulier' ) ); ?></a>
	<a class="pk-btn pk-btn-light" href="<?php echo esc_url( pk_properties_archive_url() ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Rechercher un bien', 'Rechercher un bien', 'partikulier' ) ); ?></a>
</nav>

<main id="main-content" class="pk-main">