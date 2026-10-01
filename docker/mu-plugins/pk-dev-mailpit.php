<?php
/**
 * Local-only: route wp_mail() to Mailpit so no e-mail leaves the machine.
 * Mounted by docker-compose.yml; never packaged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'phpmailer_init',
	static function ( $phpmailer ) {
		$phpmailer->isSMTP();
		$phpmailer->Host     = getenv( 'PK_SMTP_HOST' ) ?: 'mailpit';
		$phpmailer->Port     = (int) ( getenv( 'PK_SMTP_PORT' ) ?: 1025 );
		$phpmailer->SMTPAuth = false;
	}
);
