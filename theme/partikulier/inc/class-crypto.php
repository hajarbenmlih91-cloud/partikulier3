<?php
/**
 * Module : Cryptographie et protection des données personnelles (SE-028 & DP-2).
 *
 * Fournit les utilitaires de chiffrement authentifié AES-256-GCM, de déchiffrement
 * sécurisé avec contrôle d'habilitation (manage_options), de lecture rétrocompatible
 * et de masquage de confidentialité pour les numéros de téléphone.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Crypto {

	/**
	 * Chiffre un numéro en AES-256-GCM (SE-028, E-2801).
	 *
	 * @param string $phone Numéro en clair.
	 * @return string Chaîne chiffrée avec enveloppe gcm:v1:...
	 */
	public static function encrypt_phone( $phone ) {
		if ( class_exists( '\Partikulier\Core\Domain\Leads\LeadService' ) ) {
			return \Partikulier\Core\Domain\Leads\LeadService::encrypt_phone( (string) $phone );
		}
		if ( ! function_exists( 'openssl_encrypt' ) ) {
			return '';
		}
		$phone = trim( (string) $phone );
		if ( '' === $phone ) {
			return '';
		}
		$key        = hash( 'sha256', wp_salt( 'secure_auth' ), true );
		$iv         = random_bytes( 12 );
		$tag        = '';
		$ciphertext = openssl_encrypt( $phone, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16 );
		if ( false === $ciphertext || 16 !== strlen( $tag ) ) {
			return '';
		}
		return 'gcm:v1:' . base64_encode( $iv . $tag . $ciphertext );
	}

	/**
	 * Déchiffre un numéro de téléphone avec contrôle de permission administrateur (E-2802, E-2804).
	 *
	 * @param string $encrypted_phone Chaîne chiffrée (GCM ou legacy CBC).
	 * @return string Numéro en clair ou chaîne vide si refus/altération.
	 */
	public static function decrypt_phone( $encrypted_phone ) {
		if ( class_exists( '\Partikulier\Core\Domain\Leads\LeadService' ) ) {
			return \Partikulier\Core\Domain\Leads\LeadService::decrypt_phone_for_admin( (string) $encrypted_phone );
		}
		if ( ! current_user_can( 'manage_options' ) || ! function_exists( 'openssl_decrypt' ) ) {
			return '';
		}
		$encrypted_phone = trim( (string) $encrypted_phone );
		if ( '' === $encrypted_phone ) {
			return '';
		}
		$key = hash( 'sha256', wp_salt( 'secure_auth' ), true );

		if ( str_starts_with( $encrypted_phone, 'gcm:v1:' ) ) {
			$raw_payload = substr( $encrypted_phone, 7 );
			$binary      = base64_decode( $raw_payload, true );
			if ( false === $binary || strlen( $binary ) < 29 ) {
				return '';
			}
			$iv         = substr( $binary, 0, 12 );
			$tag        = substr( $binary, 12, 16 );
			$ciphertext = substr( $binary, 28 );
			$decrypted  = openssl_decrypt( $ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag );
			return false !== $decrypted ? (string) $decrypted : '';
		}

		// Repli legacy CBC
		$payload = base64_decode( $encrypted_phone, true );
		if ( false === $payload || strlen( $payload) <= 16 ) {
			return '';
		}
		$iv         = substr( $payload, 0, 16 );
		$ciphertext = substr( $payload, 16 );
		$decrypted  = openssl_decrypt( $ciphertext, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv );
		return false !== $decrypted ? (string) $decrypted : '';
	}

	/**
	 * Lit un numéro qui peut être stocké sous forme chiffrée ou en clair (zéro migration).
	 *
	 * @param string $raw_phone Valeur brute issue de la BDD.
	 * @return string Numéro exploitable en clair.
	 */
	public static function read_phone( $raw_phone ) {
		$raw_phone = trim( (string) $raw_phone );
		if ( '' === $raw_phone ) {
			return '';
		}
		if ( str_starts_with( $raw_phone, 'gcm:v1:' ) ) {
			return self::decrypt_phone( $raw_phone );
		}
		return $raw_phone;
	}

	/**
	 * Masque un numéro de téléphone pour un affichage semi-public conforme CNDP.
	 * Ex: +212 6 12 34 56 78 -> +212 6 •• •• 56 78
	 *
	 * @param string $phone Numéro brut.
	 * @return string Numéro masqué.
	 */
	public static function mask_phone( $phone ) {
		$clean = preg_replace( '/\D+/', '', (string) $phone );
		if ( strlen( $clean ) < 8 ) {
			return '••••••••';
		}
		$start = substr( $clean, 0, 4 );
		$end   = substr( $clean, -3 );
		return $start . ' ••• ' . $end;
	}
}
