<?php
/**
 * Déposer une annonce : tunnel dans le thème, avec en-tête et pied de page.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

get_header();
Partikulier_Tunnel_Preview::render();
get_footer();
