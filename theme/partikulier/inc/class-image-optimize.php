<?php
/**
 * Allège les photos à l’upload, sans rien demander à l’annonceur.
 *
 * Le JPEG (chemin + mime) reste le fichier WordPress. On écrase le fichier
 * déjà écrit par wp_handle_upload : pas de nouvel attachement, pas d’unlink
 * tant que l’URL pointe vers ce fichier. Les tailles pk-hero / pk-card et
 * l’AVIF sidecar partent ensuite d’un original déjà petit.
 *
 * Pour les attachements AVIF (seed démo), un JPEG de partage est créé une
 * fois (OG / WhatsApp) — pas à chaque hit, pas pour la galerie.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Image_Optimize {

	const MAX_EDGE  = 1920;
	const QUALITY   = 82;
	const MAX_BYTES = 409600;
	const OG_EDGE   = 1200;

	public static function init() {
		add_filter( 'wp_handle_upload', array( __CLASS__, 'recompress_upload' ), 5, 2 );
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'maybe_og_jpeg' ), 20, 2 );
	}

	/**
	 * Image de partage (JPEG/PNG). Jamais d’AVIF dans og:image.
	 *
	 * @param int $attachment_id ID media.
	 * @return array{url:string,width:int,height:int,type:string}|false
	 */
	public static function og_image( $attachment_id ) {
		$attachment_id = (int) $attachment_id;
		if ( $attachment_id < 1 ) {
			return false;
		}
		$mime = (string) get_post_mime_type( $attachment_id );
		if ( in_array( $mime, array( 'image/jpeg', 'image/png' ), true ) ) {
			$src = wp_get_attachment_image_src( $attachment_id, 'large' );
			if ( ! is_array( $src ) || empty( $src[0] ) ) {
				return false;
			}
			return array(
				'url'    => (string) $src[0],
				'width'  => (int) $src[1],
				'height' => (int) $src[2],
				'type'   => $mime,
			);
		}
		return self::ensure_og_jpeg( $attachment_id );
	}

	/**
	 * @param array $metadata       Métadonnées WP.
	 * @param int   $attachment_id  ID media.
	 * @return array
	 */
	public static function maybe_og_jpeg( $metadata, $attachment_id ) {
		self::ensure_og_jpeg( (int) $attachment_id );
		return $metadata;
	}

	/**
	 * Recompresse le fichier déjà déplacé dans uploads. Échec = original intact.
	 * PNG : redimensionné seulement, mime inchangé (pas de fond noir).
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

		$bytes       = (int) filesize( $file );
		$need_resize = max( $w, $h ) > self::MAX_EDGE;
		$need_recode = ( 'image/jpeg' === $mime || 'image/webp' === $mime ) && $bytes > self::MAX_BYTES;
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

	/**
	 * JPEG de partage pour un attachement AVIF/WebP. Une fois, puis meta.
	 *
	 * @param int $attachment_id ID media.
	 * @return array{url:string,width:int,height:int,type:string}|false
	 */
	private static function ensure_og_jpeg( $attachment_id ) {
		if ( get_transient( 'pk_og_jpeg_fail_' . $attachment_id ) ) {
			return false;
		}
		$cached = self::og_from_meta( $attachment_id );
		if ( $cached ) {
			return $cached;
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! is_readable( $file ) ) {
			return false;
		}
		$dest = preg_replace( '/\.(avif|webp)$/i', '.jpg', $file );
		if ( ! is_string( $dest ) || $dest === $file ) {
			$dest = $file . '.jpg';
		}
		if ( is_readable( $dest ) && (int) filesize( $dest ) > 0 ) {
			return self::store_og_jpeg( $attachment_id, $dest );
		}

		$editor = wp_get_image_editor( $file );
		if ( is_wp_error( $editor ) ) {
			set_transient( 'pk_og_jpeg_fail_' . $attachment_id, 1, 12 * HOUR_IN_SECONDS );
			return false;
		}
		$size = $editor->get_size();
		$w    = isset( $size['width'] ) ? (int) $size['width'] : 0;
		$h    = isset( $size['height'] ) ? (int) $size['height'] : 0;
		if ( $w > self::OG_EDGE || $h > self::OG_EDGE ) {
			$editor->resize( self::OG_EDGE, self::OG_EDGE, false );
		}
		$editor->set_quality( self::QUALITY );
		$saved = $editor->save( $dest, 'image/jpeg' );
		if ( is_wp_error( $saved ) || ! is_readable( $dest ) || (int) filesize( $dest ) < 1 ) {
			if ( file_exists( $dest ) ) {
				wp_delete_file( $dest );
			}
			set_transient( 'pk_og_jpeg_fail_' . $attachment_id, 1, 12 * HOUR_IN_SECONDS );
			return false;
		}
		delete_transient( 'pk_og_jpeg_fail_' . $attachment_id );
		return self::store_og_jpeg( $attachment_id, $dest );
	}

	/**
	 * @param int $attachment_id ID media.
	 * @return array{url:string,width:int,height:int,type:string}|false
	 */
	private static function og_from_meta( $attachment_id ) {
		$rel = (string) get_post_meta( $attachment_id, '_pk_og_jpeg', true );
		if ( '' === $rel ) {
			return false;
		}
		$upload = wp_get_upload_dir();
		$path   = trailingslashit( (string) $upload['basedir'] ) . ltrim( $rel, '/' );
		if ( ! is_readable( $path ) || (int) filesize( $path ) < 1 ) {
			return false;
		}
		$url  = trailingslashit( (string) $upload['baseurl'] ) . ltrim( $rel, '/' );
		$dims = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return array(
			'url'    => $url,
			'width'  => ( is_array( $dims ) ? (int) $dims[0] : 0 ),
			'height' => ( is_array( $dims ) ? (int) $dims[1] : 0 ),
			'type'   => 'image/jpeg',
		);
	}

	/**
	 * @param int    $attachment_id ID media.
	 * @param string $path          Chemin JPEG.
	 * @return array{url:string,width:int,height:int,type:string}|false
	 */
	private static function store_og_jpeg( $attachment_id, $path ) {
		$upload = wp_get_upload_dir();
		$base   = trailingslashit( (string) $upload['basedir'] );
		if ( 0 !== strpos( $path, $base ) ) {
			return false;
		}
		$rel = ltrim( substr( $path, strlen( $base ) ), '/' );
		update_post_meta( $attachment_id, '_pk_og_jpeg', $rel );
		$url  = trailingslashit( (string) $upload['baseurl'] ) . $rel;
		$dims = @getimagesize( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return array(
			'url'    => $url,
			'width'  => ( is_array( $dims ) ? (int) $dims[0] : 0 ),
			'height' => ( is_array( $dims ) ? (int) $dims[1] : 0 ),
			'type'   => 'image/jpeg',
		);
	}
}

Partikulier_Image_Optimize::init();
