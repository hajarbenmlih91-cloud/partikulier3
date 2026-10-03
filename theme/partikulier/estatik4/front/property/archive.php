<?php
/**
 * Override ESTATIK : archive de proprietes (remplace l'archive.php d'Estatik).
 * Utilise le template maison (contour header/footer + cartes).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// On delegue au template maison archive.php du theme.
$home_archive = PARTIKULIER_DIR . '/templates/archive.php';
if ( file_exists( $home_archive ) ) {
	include $home_archive;
}