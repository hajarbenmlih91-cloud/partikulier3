<?php
/**
 * Pied de page du theme.
 *
 * Structure calquee sur le footer multi-colonnes du kit "Woo Shop" :
 * Aide / Le site / Types de biens / Contact — fond sombre, maillage SEO.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}
?>
</main>

<footer class="pk-site-footer" role="contentinfo">
		<div class="pk-footer-top">
				<div class="pk-footer-col">
								<h3 class="pk-footer-title"><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'À propos', 'À propos', 'partikulier' ) : __( 'À propos', 'partikulier' ) ); ?></h3>
<?php
/* 6.17.32 — le texte « À propos » du footer est un réglage : seule la valeur
 * PAR DÉFAUT passe par le dictionnaire de localisation (le texte personnalisé
 * du propriétaire est renvoyé inchangé, absent des dictionnaires). */
$pk_footer_about = Partikulier_Settings::get( 'footer_about_text' );
if ( class_exists( 'Partikulier_Localization' ) ) {
		$pk_footer_about = Partikulier_Localization::translate_polylang_string( $pk_footer_about, $pk_footer_about, 'partikulier' );
}
?>
						<p class="pk-footer-about"><?php echo esc_html( $pk_footer_about ); ?></p>
						<div class="pk-footer-socials">
								<a href="#" aria-label="<?php esc_attr_e( 'Facebook', 'partikulier' ); ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z"/></svg></a>
								<a href="#" aria-label="<?php esc_attr_e( 'Instagram', 'partikulier' ); ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="2" y="2" width="20" height="20" rx="5"/><path d="M16 11.37A4 4 0 1 1 12.63 8 4 4 0 0 1 16 11.37z"/><line x1="17.5" y1="6.5" x2="17.51" y2="6.5"/></svg></a>
								<a href="#" aria-label="<?php esc_attr_e( 'X (Twitter)', 'partikulier' ); ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 4l7.07 9.17L4 20h2.5l6.07-6.07L17.5 20H20l-7.4-9.61L19.5 4H17l-5.6 5.76L6.5 4z"/></svg></a>
								<a href="#" aria-label="<?php esc_attr_e( 'YouTube', 'partikulier' ); ?>"><svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M22.54 6.42a2.78 2.78 0 0 0-1.94-2C18.88 4 12 4 12 4s-6.88 0-8.6.46a2.78 2.78 0 0 0-1.94 2A29 29 0 0 0 1 11.75a29 29 0 0 0 .46 5.33A2.78 2.78 0 0 0 3.4 19c1.72.46 8.6.46 8.6.46s6.88 0 8.6-.46a2.78 2.78 0 0 0 1.94-2 29 29 0 0 0 .46-5.25 29 29 0 0 0-.46-5.33z"/><polygon points="9.75 15.02 15.5 11.75 9.75 8.48 9.75 15.02"/></svg></a>
						</div>
				</div>

				<div class="pk-footer-col">
								<h3 class="pk-footer-title"><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Aide', 'Aide', 'partikulier' ) : __( 'Aide', 'partikulier' ) ); ?></h3>
						<ul class="pk-footer-links">
<li><a href="<?php echo esc_url( pk_page_url( 'deposer', '/deposer/' ) ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Déposer une annonce', 'Déposer une annonce', 'partikulier' ) ); ?></a></li>
										<li><a href="<?php echo esc_url( pk_properties_archive_url() ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Toutes les annonces', 'Toutes les annonces', 'partikulier' ) ); ?></a></li>
										<li><a href="<?php echo esc_url( pk_page_url( 'faq', '/faq/' ) ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Questions fréquentes', 'Questions fréquentes', 'partikulier' ) ); ?></a></li>
										<li><a href="<?php echo esc_url( pk_page_url( 'contact', '/contact/' ) ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Contactez-nous', 'Contactez-nous', 'partikulier' ) ); ?></a></li>
						</ul>
				</div>

				<div class="pk-footer-col">
						<?php
						$types = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY, 'hide_empty' => true ) );
						if ( $types && ! is_wp_error( $types ) ) :
							?>
										<h3 class="pk-footer-title"><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Types de biens', 'Types de biens', 'partikulier' ) : __( 'Types de biens', 'partikulier' ) ); ?></h3>
								<ul class="pk-footer-links">
										<?php foreach ( $types as $term ) : ?>
												<li><a href="<?php echo esc_url( pk_term_url( $term ) ); ?>"><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $term->name ) : $term->name ); ?></a></li>
										<?php endforeach; ?>
								</ul>
						<?php endif; ?>
				</div>

				<div class="pk-footer-col">
								<h3 class="pk-footer-title"><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Contact', 'Contact', 'partikulier' ) : __( 'Contact', 'partikulier' ) ); ?></h3>
						<div class="pk-footer-contact">
								<?php
								$blog_email = get_option( 'admin_email' );
								if ( $blog_email ) {
										printf( '<p><a href="mailto:%1$s">%1$s</a></p>', esc_html( $blog_email ) );
								}
								?>
								<p><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Maroc', 'Maroc', 'partikulier' ) ); ?></p>
								<?php
								$cities = Partikulier_Geo::top_cities( 5 );
								if ( $cities ) {
										$names = wp_list_pluck( $cities, 'name' );
									if ( class_exists( 'Partikulier_Localization' ) ) {
											$names = array_map( array( 'Partikulier_Localization', 'translate_taxonomy_label' ), $names );
									}
										printf( '<p>%s</p>', esc_html( implode( ', ', $names ) ) );
								}
								?>
						</div>
				</div>
		</div>

		<div class="pk-footer-bottom">
				<div class="pk-container pk-footer-bottom-inner">
								<p class="pk-copyright">&copy; <?php echo esc_html( date_i18n( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. <?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Tous droits réservés.', 'Tous droits réservés.', 'partikulier' ) : __( 'Tous droits réservés.', 'partikulier' ) ); ?></p>
						<nav class="pk-footer-legal" aria-label="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Mentions légales', 'Mentions légales', 'partikulier' ) ); ?>">
								<?php
								wp_nav_menu( array(
										'theme_location' => 'footer',
										'container'      => false,
										'menu_class'     => 'pk-legal-links',
										'fallback_cb'    => false,
										'depth'          => 1,
								) );
								?>
						</nav>
				</div>
		</div>
</footer>

<?php if ( is_front_page() ) : ?>
<div class="pk-lang-gate" data-pk-lang-gate hidden>
	<div class="pk-lang-gate-card" role="dialog" aria-modal="true" aria-labelledby="pk-lang-gate-title">
		<button type="button" class="pk-lang-gate-close" data-pk-lang-dismiss aria-label="<?php echo esc_attr( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Fermer', 'Fermer', 'partikulier' ) : __( 'Fermer', 'partikulier' ) ); ?>">×</button>
		<p class="pk-lang-gate-kicker" aria-hidden="true">Partikulier.ma</p>
		<p id="pk-lang-gate-title" class="pk-lang-gate-title"><span lang="fr">Bienvenue</span> · <span lang="ar">مرحباً</span> · <span lang="en">Welcome</span></p>
		<p class="pk-lang-gate-sub"><span lang="fr">Choisissez votre langue</span> · <span lang="ar">اختر لغتك</span> · <span lang="en">Choose your language</span></p>
		<div class="pk-lang-gate-actions">
			<?php
			$pk_gate_langs = array(
				'fr' => array( 'name' => 'Français', 'tag' => 'Entre particuliers, sans commission' ),
				'ar' => array( 'name' => 'العربية', 'tag' => 'بين الأفراد، بدون عمولة' ),
				'en' => array( 'name' => 'English', 'tag' => 'Between individuals, no commission' ),
			);
			foreach ( $pk_gate_langs as $pk_gate_slug => $pk_gate_lang ) :
				$pk_gate_url = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url( $pk_gate_slug ) : home_url( '/' . $pk_gate_slug . '/' );
				?>
				<a class="pk-lang-option" data-pk-lang-pick="<?php echo esc_attr( $pk_gate_slug ); ?>" href="<?php echo esc_url( $pk_gate_url ); ?>" lang="<?php echo esc_attr( $pk_gate_slug ); ?>" hreflang="<?php echo esc_attr( $pk_gate_slug ); ?>">
					<?php if ( 'fr' === $pk_gate_slug ) : ?>
						<svg class="pk-lang-flag" viewBox="0 0 30 21" aria-hidden="true" focusable="false"><rect width="10" height="21" fill="#0055A4"/><rect x="10" width="10" height="21" fill="#FFFFFF"/><rect x="20" width="10" height="21" fill="#EF4135"/></svg>
					<?php elseif ( 'ar' === $pk_gate_slug ) : ?>
						<svg class="pk-lang-flag" viewBox="0 0 30 21" aria-hidden="true" focusable="false"><rect width="30" height="21" fill="#C1272D"/><path d="M15 4.5 18.53 15.35 9.29 8.65 20.71 8.65 11.47 15.35 Z" fill="none" stroke="#006233" stroke-width="1.1"/></svg>
					<?php else : ?>
						<svg class="pk-lang-flag" viewBox="0 0 30 21" aria-hidden="true" focusable="false"><rect width="30" height="21" fill="#012169"/><path d="M0 0 30 21 M30 0 0 21" stroke="#FFFFFF" stroke-width="4"/><path d="M0 0 30 21 M30 0 0 21" stroke="#C8102E" stroke-width="1.6"/><path d="M15 0 V21 M0 10.5 H30" stroke="#FFFFFF" stroke-width="7"/><path d="M15 0 V21 M0 10.5 H30" stroke="#C8102E" stroke-width="4"/></svg>
					<?php endif; ?>
					<span class="pk-lang-names">
						<strong><?php echo esc_html( $pk_gate_lang['name'] ); ?></strong>
						<small><?php echo esc_html( $pk_gate_lang['tag'] ); ?></small>
					</span>
					<span class="pk-lang-go" aria-hidden="true">→</span>
				</a>
			<?php endforeach; ?>
		</div>
		<p class="pk-lang-gate-note"><span lang="fr">Langue modifiable à tout moment</span> · <span lang="ar">يمكن تغيير اللغة لاحقاً</span> · <span lang="en">Change anytime</span></p>
	</div>
</div>
<?php endif; ?>
<?php wp_footer(); ?>
</body>
</html>