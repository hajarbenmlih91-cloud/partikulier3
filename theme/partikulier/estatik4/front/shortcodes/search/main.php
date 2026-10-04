<?php
/**
 * Override ESTATIK : recherche principale (shortcode es_search_main utilise dans le hero).
 * Utilise notre barre de recherche maison optimisee SEO.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$variant = 'hero';
require PARTIKULIER_DIR . '/templates/parts/search-form.php';