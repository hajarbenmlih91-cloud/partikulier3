<?php
/**
 * Module : reglages du Personnalisateur pour les options du theme.
 *
 * Charge apres class-settings.php : la classe etend WP_Customize_Setting, qui
 * n'existe que dans un contexte Personnalisateur. Le hook porte la priorite du
 * theme (celle a laquelle Partikulier_Settings::register est branche), pour que
 * cette definition soit toujours anterieure a l'enregistrement des reglages.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'customize_register', 'partikulier_settings_register_customize_setting_class', 4 );

/**
 * Definit la classe de reglage, si le coeur du Personnalisateur est charge.
 *
 * @return void
 */
function partikulier_settings_register_customize_setting_class() {
	if ( ! class_exists( 'WP_Customize_Setting' ) || class_exists( 'Partikulier_Settings_Customize_Setting' ) ) {
		return;
	}

	/**
	 * Reglage qui connait loption heritee « pk_opts ».
	 *
	 * Sans cela, le formulaire du Personnalisateur affiche un champ vide alors que
	 * la valeur est bien la (elle est lisible par le theme via le repli de
	 * Partikulier_Settings::get()) : l utilisateur resaisit, republie, et le voyant
	 * du diagnostic reste rouge. Lecture seule — aucune ecriture ici.
	 */
	class Partikulier_Settings_Customize_Setting extends WP_Customize_Setting {

		/**
		 * Valeur affichee dans le champ.
		 *
		 * @return mixed
		 */
		public function value() {
			$value = parent::value();
			if ( null !== $value && '' !== trim( (string) $value ) ) {
				return $value;
			}
			$cle = isset( $this->id_data['keys'][0] ) ? (string) $this->id_data['keys'][0] : '';
			if ( '' === $cle ) {
				return $value;
			}
			$legacy = get_option( 'pk_opts', array() );
			if ( is_array( $legacy ) && isset( $legacy[ $cle ] ) && is_scalar( $legacy[ $cle ] ) ) {
				return (string) $legacy[ $cle ];
			}
			return $value;
		}
	}
}
