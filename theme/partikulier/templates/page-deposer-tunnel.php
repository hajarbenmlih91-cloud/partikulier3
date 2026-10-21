<?php
/**
 * Déposer une annonce : le formulaire préparé, seul.
 *
 * Pas d'en-tête ni de pied du site. La barre de langue du formulaire
 * suffit, et la langue de l'adresse ouvre la bonne version.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

Partikulier_Tunnel_Preview::serve_document();
