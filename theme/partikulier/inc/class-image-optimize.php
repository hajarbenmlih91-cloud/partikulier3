<?php
/**
 * Allège les photos à l’upload, sans rien demander à l’annonceur.
 *
 * Le JPEG (chemin + mime) reste le fichier WordPress. On écrase le fichier
 * déjà écrit par wp_handle_upload : pas de nouvel attachement, pas d’unlink
 * tant que l’URL pointe vers ce fichier. Les tailles pk-hero / pk-card et
 * l’AVIF sidecar partent ensuite d’un original déjà petit.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Image_Optimize {

	/** Grand côté max de l’original stocké (px). */
	const MAX_EDGE = 1920;

	/** Qualité JPEG / WebP (équivalent visuel d’un export téléphone). */
	const QUALITY = 82;

	/** Au-delà, un JPEG déjà dans les clous en pixels est tout de même réencodé. */
	const MAX_BYTES = 409600;

	public static function init() {
		add_filter( 'wp_handle_upload', array( __CLASS__, 'recompress_upload' ), 5, 2 );
		add_filter( 'jpeg_quality', array( __CLASS__, 'quality' ) );
		add_filter( 'wp_editor_set_quality', array( __CLASS__, 'editor_quality' ), 10, 2 );
	}

	public static function quality() {
		return self::QUALITY;
	}

	/**
	 * @param int    $quality Qualité demandée par WP.
	 * @param string $mime    Mime de l’éditeur.
	 * @return int
	 */
	public static function editor_quality( $quality, $mime = '' ) {
		if ( in_array( $mime, array( 'image/jpeg', 'image/webp' ), true ) ) {
			return self::QUALITY;
		}
		return (int) $quality;
	}

	/**
	 * Recompresse le fichier déjà déplacé dans uploads. Échec = original intact.
	 *
	 * @param array  $upload  file / url / type.
	 * @param string $context upload|sideload.
	 * @return array
	 */
	public static function recompress_upload( $upload, $context = 'upload' ) {
		unset( $context );
		if ( ! is_array( $upload ) || ! empty( $upload['error'] ) ) {
			return $upload;
		}
		$file = isset( $upload['file'] ) ? (string) $upload['file'] : '';
		$mime = isset( $upload['type'] ) ? (string) $upload['type'] : '';
		if ( '' === $file || ! is_readable( $file ) ) {
			return $upload;
		}
		if ( ! in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true ) ) {
			return $upload;
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			return $upload;
		}

		$size = $editor->get_size();
		$w    = isset( $size['width'] ) ? (int) $size['width'] : 0;
		$h    = isset( $size['height'] ) ? (int) $size['height'] : 0;
		if ( $w < 1 || $h < 1 ) {
			return $upload;
		}

		$bytes        = (int) filesize( $file );
		$need_resize  = max( $w, $h ) > self::MAX_EDGE;
		$need_recode  = ( 'image/jpeg' === $mime || 'image/webp' === $mime ) && $bytes > self::MAX_BYTES;
		if ( ! $need_resize && ! $need_recode ) {
			return $upload;
		}

		if ( $need_resize ) {
			$resized = $editor->resize( self::MAX_EDGE, self::MAX_EDGE, false );
			if ( is_wp_error( $resized ) ) {
				return $upload;
			}
		}
		$editor->set_quality( self::QUALITY );

		$dest = $file . '.pk-opt';
		if ( file_exists( $dest ) ) {
			wp_delete_file( $dest );
		}
		$saved = $editor->save( $dest, $mime );
		if ( is_wp_error( $saved ) || ! is_readable( $dest ) || (int) filesize( $dest ) < 1 ) {
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
			return $upload;
		}

		$new_bytes = (int) filesize( $dest );
		if ( ! $need_resize && $new_bytes >= $bytes ) {
			wp_delete_file( $dest );
			return $upload;
		}

		$backup = $file . '.pk-bak';
		if ( ! rename( $file, $backup ) ) {
			wp_delete_file( $dest );
			return $upload;
		}
		if ( ! rename( $dest, $file ) ) {
			rename( $backup, $file );
			wp_delete_file( $dest );
			return $upload;
		}
		wp_delete_file( $backup );

		return $upload;
	}
}

Partikulier_Image_Optimize::init();
