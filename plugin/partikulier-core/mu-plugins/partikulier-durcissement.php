<?php
/**
 * Partikulier — durcissement des reponses HTTP.
 *
 * Ce que le diagnostic a releve sur l'hebergement :
 *   - « X-Powered-By annonce la version de PHP aux robots » : ce que le site peut
 *     faire de son cote est retire ici ; le cache de l'hebergeur (LiteSpeed/HCDN)
 *     ajoute ses propres en-tetes et se regle cote serveur, pas ici.
 *   - les liens de diagnostic portent un jeton dans l'URL : ils sont marques
 *     « noindex » et « ne pas envoyer le referer », pour qu'un jeton ne se
 *     retrouve ni dans un moteur de recherche ni dans le journal d'un site tiers.
 *
 * Ce fichier ne reecrit aucune regle de serveur : un theme ne peut pas empecher
 * l'indexation automatique d'un repertoire qui n'est pas le sien
 * (wp-content/themes/ est liste par le serveur, pas par WordPress). Le rapport
 * le dit et donne le bloc a coller, au lieu de laisser croire que le theme s'en
 * charge.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action(
	'send_headers',
	static function (): void {
		if ( headers_sent() ) {
			return;
		}
		header_remove( 'X-Powered-By' );
		header_remove( 'X-Generator' );
	},
	20
);

/**
 * En-tetes specifiques aux pages de diagnostic (jeton dans l'URL).
 *
 * @return void
 */
function partikulier_diagnostic_headers() {
	if ( headers_sent() ) {
		return;
	}
	header( 'X-Robots-Tag: noindex, nofollow' );
	header( 'Referrer-Policy: no-referrer' );
}

/**
 * Le durcissement est-il bien en place ? (relu par le diagnostic, qui doit le
 * mesurer et pas le supposer).
 *
 * @return array<string,string>
 */
function partikulier_durcissement_etat() {
	return array(
		'send_headers' => has_action( 'send_headers' ) ? 'raccourci par ' . (int) has_action( 'send_headers' ) . ' action(s)' : 'AUCUN',
		'x_powered_by' => function_exists( 'header' ) ? 'retire si le cache hebergeur ne le recolle pas' : 'n/a',
		'note'         => "le cache de l'hebergeur sert sa propre copie : verifier X-Powered-By apres purge, sinon le reglage se fait cote serveur (hPanel > Cache, ou .htaccess)",
	);
}
