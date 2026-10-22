<?php
/**
 * Article WordPress. Les annonces restent sur templates/single.php.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( is_singular( 'post' ) ) {
	require PARTIKULIER_DIR . '/templates/single-article.php';
	return;
}

require PARTIKULIER_DIR . '/index.php';
