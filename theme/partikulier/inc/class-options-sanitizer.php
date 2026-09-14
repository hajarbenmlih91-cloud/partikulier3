<?php
/**
 * SE-020 (E-2003) — Sanitiseur d'options : liste blanche clé -> callback.
 *
 * Les formulaires de personnalisation écrivent deux tableaux POST
 * (pk_opts / pk_theme_options). Ce module applique la même liste blanche aux
 * deux : seules les clés connues ressortent, chacune passée par SON callback
 * (absint, sanitize_text_field, sanitize_textarea_field, wp_kses pour
 * home_intro). Les clés inconnues sont ignorées silencieusement : une charge
 * parasite (<script>, clé forgée) ne ressort jamais stockée telle quelle —
 * ni dans les options, ni dans le transient de validation d'hero_alt.
 *
 * Contrat : tests/options-sanitizer-contract.php (verrou SE-020).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
		exit;
}

class Partikulier_Options_Sanitizer {

		/**
		 * Sanitise un tableau pk_opts / pk_theme_options posté.
		 *
		 * La sémantique de présence est préservée : une clé absente du POST
		 * reste absente du tableau assaini (les replis `??` des consommateurs
		 * demeurent exacts). Les valeurs déjà assainies par les consommateurs
		 * aval le sont de façon idempotente.
		 *
		 * @param array $posted Tableau POST (déjà wp_unslash).
		 * @return array Tableau assaini, clés connues uniquement.
		 */
	public static function sanitize_customization( array $posted ) {
			$clean = array();

			/* Champs simples de personnalisation. */
		if ( array_key_exists( 'logo_attachment_id', $posted ) ) {
				$clean['logo_attachment_id'] = absint( $posted['logo_attachment_id'] );
		}
		if ( array_key_exists( 'hero_attachment_id', $posted ) ) {
				$clean['hero_attachment_id'] = absint( $posted['hero_attachment_id'] );
		}
		if ( array_key_exists( 'hero_image_alt', $posted ) ) {
				$clean['hero_image_alt'] = sanitize_text_field( $posted['hero_image_alt'] );
		}
		if ( array_key_exists( 'home_section_order', $posted ) ) {
				$clean['home_section_order'] = implode( ',', array_map( 'sanitize_key', explode( ',', (string) $posted['home_section_order'] ) ) );
		}

			/* Bloc i18n de l'alt hero (langues déclarées uniquement). */
		if ( isset( $posted['hero_image_alt_i18n'] ) && is_array( $posted['hero_image_alt_i18n'] ) ) {
				$clean['hero_image_alt_i18n'] = array();
			foreach ( Partikulier_Settings::editorial_languages() as $language => $label ) {
				if ( array_key_exists( $language, $posted['hero_image_alt_i18n'] ) ) {
					$clean['hero_image_alt_i18n'][ $language ] = sanitize_text_field( $posted['hero_image_alt_i18n'][ $language ] );
				}
			}
		}

			/* Bloc éditorial : home_intro conserve un sous-ensemble kses ; le
				reste est du texte plein. Les clés proviennent de defaults(). */
			$defaults = Partikulier_Customization::defaults();
		if ( isset( $posted['editorial'] ) && is_array( $posted['editorial'] ) ) {
				$clean['editorial'] = array();
			foreach ( $defaults as $key => $languages ) {
				if ( isset( $posted['editorial'][ $key ] ) && is_array( $posted['editorial'][ $key ] ) ) {
					foreach ( Partikulier_Settings::editorial_languages() as $language => $label ) {
						if ( array_key_exists( $language, $posted['editorial'][ $key ] ) ) {
							$raw                                     = $posted['editorial'][ $key ][ $language ];
							$clean['editorial'][ $key ][ $language ] = 'home_intro' === $key
							? wp_kses( $raw, array( 'a' => array( 'href' => true, 'title' => true ), 'strong' => array(), 'em' => array(), 'br' => array() ) )
							: sanitize_text_field( $raw );
						}
					}
				}
			}
		}

			/* Bloc localisé du thème (localized_fields() fait foi). */
		if ( isset( $posted['localized'] ) && is_array( $posted['localized'] ) ) {
				$clean['localized'] = array();
			foreach ( Partikulier_Settings::editorial_languages() as $language => $label ) {
				if ( ! isset( $posted['localized'][ $language ] ) || ! is_array( $posted['localized'][ $language ] ) ) {
					continue;
				}
				foreach ( Partikulier_Customization::localized_fields() as $key => $field ) {
					if ( array_key_exists( $key, $posted['localized'][ $language ] ) ) {
						$clean['localized'][ $language ][ $key ] = 'textarea' === ( $field['type'] ?? '' )
							? sanitize_textarea_field( $posted['localized'][ $language ][ $key ] )
							: sanitize_text_field( $posted['localized'][ $language ][ $key ] );
					}
				}
			}
		}

			/* Réglages génériques du thème : champs déclarés, secret
				d'environnement exclu (jamais éditable depuis l'écran). */
		foreach ( Partikulier_Settings::fields() as $group ) {
			foreach ( $group['fields'] as $key => $field ) {
				if ( 'automation_api_secret' === $key || ! array_key_exists( $key, $posted ) ) {
					continue;
				}
					$clean[ $key ] = 'textarea' === ( $field['type'] ?? '' )
							? sanitize_textarea_field( $posted[ $key ] )
							: sanitize_text_field( $posted[ $key ] );
			}
		}

			return $clean;
	}
}
