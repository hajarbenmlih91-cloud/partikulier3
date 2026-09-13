<?php
/**
 * Routeur minimal du serveur PHP intégré pour le contrat front-assets (SE-018)
 * en CI : sert les fichiers statiques depuis le docroot (wp/), route tout le
 * reste vers WordPress (équivalent des rewrite rules). Version allégée du
 * routeur du banc de recette.
 */
$uri = urldecode( explode( '?', $_SERVER['REQUEST_URI'] )[0] );
$fichier = $_SERVER['DOCUMENT_ROOT'] . $uri;

if ( '/' !== $uri && is_file( $fichier ) ) {
        if ( preg_match( '#\.(php|phtml)$#i', $fichier ) ) {
                require $fichier;
                return true;
        }
        return false; // fichier statique : le serveur intégré le sert.
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
return true;
