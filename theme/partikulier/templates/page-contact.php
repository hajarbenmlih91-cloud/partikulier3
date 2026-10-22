<?php
/**
 * Template Name: Contact
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'DONOTCACHEPAGE' ) ) {
	define( 'DONOTCACHEPAGE', true );
}

$pk_contact_posted = class_exists( 'Partikulier_Contact' ) ? Partikulier_Contact::posted() : array();
$pk_contact_error  = isset( $pk_contact_posted['code'] ) ? (string) $pk_contact_posted['code'] : '';
$pk_contact_sent   = isset( $_GET['envoye'] ) && '1' === sanitize_text_field( wp_unslash( $_GET['envoye'] ) );
$pk_contact_lang   = class_exists( 'Partikulier_Contact' ) ? Partikulier_Contact::language() : 'fr';
$pk_ui_lang        = class_exists( 'Partikulier_Contact' ) ? Partikulier_Contact::ui_lang() : 'fr';
$pk_name           = isset( $pk_contact_posted['name'] ) ? (string) $pk_contact_posted['name'] : '';
$pk_email          = isset( $pk_contact_posted['email'] ) ? (string) $pk_contact_posted['email'] : '';
$pk_message        = isset( $pk_contact_posted['message'] ) ? (string) $pk_contact_posted['message'] : '';

get_header();
?>

<section class="pk-page">
	<div class="pk-container pk-container-narrow">
		<?php echo Partikulier_Geo::breadcrumbs_html(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
		<article class="pk-article">
			<header class="pk-article-head">
				<h1 class="pk-article-title"><?php the_title(); ?></h1>
			</header>
			<?php if ( $pk_contact_sent ) : ?>
				<p class="pk-contact-status" lang="<?php echo esc_attr( $pk_ui_lang ); ?>"><?php echo esc_html( Partikulier_Contact::success_text() ); ?></p>
			<?php else : ?>
				<?php if ( '' !== $pk_contact_error && '' !== Partikulier_Contact::error_text( $pk_contact_error ) ) : ?>
					<p class="pk-contact-status" lang="<?php echo esc_attr( $pk_ui_lang ); ?>"><?php echo esc_html( Partikulier_Contact::error_text( $pk_contact_error ) ); ?></p>
				<?php endif; ?>
				<form class="pk-contact-form" method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" lang="<?php echo esc_attr( $pk_contact_lang ); ?>">
					<?php wp_nonce_field( Partikulier_Contact::ACTION, 'pk_contact_nonce' ); ?>
					<input type="hidden" name="action" value="<?php echo esc_attr( Partikulier_Contact::ACTION ); ?>">
					<p class="pk-hp" aria-hidden="true">
						<label for="pk-company">&#8203;</label>
						<input type="text" name="pk_company" id="pk-company" tabindex="-1" autocomplete="off" value="">
					</p>
					<p class="pk-field">
						<label class="pk-label" for="pk-contact-name"><?php echo esc_html( Partikulier_Contact::label( 'Votre nom *' ) ); ?></label>
						<input class="pk-input" type="text" name="pk_name" id="pk-contact-name" required maxlength="120" value="<?php echo esc_attr( $pk_name ); ?>" autocomplete="name">
					</p>
					<p class="pk-field">
						<label class="pk-label" for="pk-contact-email"><?php echo esc_html( Partikulier_Contact::label( 'E-mail' ) ); ?> <span class="pk-req">*</span></label>
						<input class="pk-input" type="email" name="pk_email" id="pk-contact-email" required maxlength="180" value="<?php echo esc_attr( $pk_email ); ?>" autocomplete="email">
					</p>
					<p class="pk-field">
						<label class="pk-label" for="pk-contact-message"<?php echo 'ar' === $pk_contact_lang ? ' lang="fr"' : ''; ?>>Message <span class="pk-req">*</span></label>
						<textarea class="pk-input" name="pk_message" id="pk-contact-message" required maxlength="4000" rows="7"><?php echo esc_textarea( $pk_message ); ?></textarea>
					</p>
					<p class="pk-contact-consent">
						<input type="checkbox" name="pk_consent" id="pk-contact-consent" value="1" required<?php echo in_array( $pk_contact_error, array( 'case', 'envoi' ), true ) ? ' aria-invalid="true"' : ''; ?>>
						<label for="pk-contact-consent" lang="<?php echo esc_attr( Partikulier_Contact::consent_lang() ); ?>"><?php echo esc_html( Partikulier_Contact::consent_sentence() ); ?> <span class="pk-req">*</span></label>
					</p>
					<button type="submit" class="pk-btn pk-btn-primary" lang="<?php echo esc_attr( $pk_ui_lang ); ?>"><?php echo 'en' === $pk_ui_lang ? 'Send' : 'Envoyer'; ?></button>
				</form>
			<?php endif; ?>
		</article>
	</div>
</section>
<style>
	.pk-contact-form { max-width: 36rem; }
	.pk-contact-form .pk-input, .pk-contact-form textarea { width: 100%; }
	.pk-contact-form textarea { min-height: 8rem; }
	.pk-contact-consent { display: flex; gap: .6rem; align-items: flex-start; margin: 0 0 1rem; }
	.pk-contact-consent input { margin-top: .25rem; }
	.pk-contact-status { font-weight: 700; }
	.pk-hp { position: absolute; left: -9999px; height: 0; width: 0; overflow: hidden; }
</style>
<?php
get_footer();
