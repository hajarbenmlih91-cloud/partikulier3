<?php
/**
 * Point d’entrée WordPress pour la recherche : même chrome que l’archive
 * (filtres à gauche). Sinon WP tombe sur index.php dès que name="s" est soumis.
 */
require PARTIKULIER_DIR . '/templates/search.php';
