<?php
/**
 * Pose le journal, les cinq articles et les quatre pages légales.
 * L'arabe n'est pas écrit. L'interrupteur du prix au mètre carré n'est pas ouvert.
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit( 'CLI only' );
}

if ( ! class_exists( 'Partikulier_Journal' ) ) {
	echo "seed-journal: classe absente\n";
	exit( 1 );
}

Partikulier_Journal::seed();
echo "seed-journal: fait\n";
if ( class_exists( 'Partikulier_Price_Index' ) ) {
	echo 'prix: interrupteur ' . ( Partikulier_Price_Index::switch_is_on() ? 'ouvert' : 'fermé' ) . "\n";
}
