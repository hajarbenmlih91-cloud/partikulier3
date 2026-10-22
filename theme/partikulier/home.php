<?php
/**
 * Index des articles quand une page des articles est réglée.
 * N'affecte pas l'accueil : front-page.php reste prioritaire.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require PARTIKULIER_DIR . '/templates/journal.php';
