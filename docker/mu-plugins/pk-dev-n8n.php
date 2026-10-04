<?php
/**
 * Local-only trust for the Docker n8n proxy. Never packaged.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_filter(
	'http_request_args',
	static function ( $args, $url ) {
		if ( 'local' === wp_get_environment_type() && 'n8n-proxy' === wp_parse_url( $url, PHP_URL_HOST ) ) {
			$args['sslcertificates'] = '/usr/local/share/ca-certificates/partikulier-local.crt';
		}
		return $args;
	},
	10,
	2
);
