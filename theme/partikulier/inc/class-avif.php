<?php
/**
 * Module : conversion automatique des images uploadees en AVIF.
 *
 * - A l'upload : genere les variantes AVIF de chaque taille d'image (GD ou Imagick)
 * - A l'affichage : remplace les URLs par leurs equivalents .avif et ajoute
 *   <picture><source type="image/avif"> + <img> de secours
 * - Les fichiers AVIF sont stockes dans un dossier separe : uploads/avif/...
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_AVIF {

	/** Qualite AVIF (excellent rapport taille/qualite, plus leger que WebP ). */
	const QUALITY = 75;

	public static function init() {
		// Conversion a la generation des tailles d'images.
		add_filter( 'wp_generate_attachment_metadata', array( __CLASS__, 'generate_avif' ), 10, 2 );
		// Conversion pour l'image mise en avant (featured) hors boucle de sizes.
		add_action( 'add_attachment', array( __CLASS__, 'convert_original' ) );
		add_action( 'edit_attachment', array( __CLASS__, 'convert_original' ) );

		// Les balises image conservent leur JPEG/WebP de secours ; les templates
		// Partikulier ajoutent une source AVIF explicite dans une balise picture.

		// Balise <picture> avec <source type="image/avif">.
		add_filter( 'wp_content_img_tag', array( __CLASS__, 'picture_tag' ), 10, 3 );
		add_filter( 'post_thumbnail_html', array( __CLASS__, 'thumbnail_picture' ), 10, 5 );
	}

	/**
	 * Apres la generation des tailles WP, on cree les AVIF correspondants.
	 */
	public static function generate_avif( $metadata, $attachment_id ) {
		if ( ! self::is_image( $attachment_id ) ) {
			return $metadata;
		}
		$file = get_attached_file( $attachment_id );
		if ( ! $file || ! file_exists( $file ) ) {
			return $metadata;
		}

		// AVIF de l'image originale.
		self::convert_file( $file );

		// AVIF de chaque taille generee.
		if ( ! empty( $metadata['sizes'] ) ) {
			$dir = trailingslashit( dirname( $file ) );
			foreach ( $metadata['sizes'] as $size => $size_data ) {
				$path = $dir . $size_data['file'];
				if ( file_exists( $path ) ) {
					self::convert_file( $path );
				}
			}
		}
		return $metadata;
	}

	/**
	 * Conversion de l'image originale seule.
	 */
	public static function convert_original( $attachment_id ) {
		if ( ! self::is_image( $attachment_id ) ) {
			return;
		}
		$file = get_attached_file( $attachment_id );
		if ( $file && file_exists( $file ) ) {
			self::convert_file( $file );
		}
	}

	/**
	 * Convertit un fichier image en AVIF au meme endroit (+ .avif).
	 */
	private static function convert_file( $file ) {
		$avif = $file . '.avif';
		if ( file_exists( $avif ) && filesize( $avif ) > 0 && filemtime( $avif ) >= filemtime( $file ) ) {
			return true;
		}
		if ( file_exists( $avif ) && 0 === filesize( $avif ) ) {
			wp_delete_file( $avif ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations

		}
		if ( wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) ) {
			$editor = wp_get_image_editor( $file );
			if ( ! is_wp_error( $editor ) ) {
					$editor->set_quality( self::QUALITY );
					$result = $editor->save( $avif, 'image/avif' );
				if ( ! is_wp_error( $result ) && ! empty( $result['path'] ) && file_exists( $result['path'] ) && filesize( $result['path'] ) > 0 ) {
					return true;
				}
			}
		}

		if ( self::convert_with_avifenc( $file, $avif ) ) {
			return true;
		}

		$converted = self::convert_with_vips( $file, $avif );
		if ( ! $converted && file_exists( $avif ) && 0 === filesize( $avif ) ) {
			wp_delete_file( $avif ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations

		}
		return $converted;
	}

	/**
	 * Repli AVIF natif lorsqu’il est installé par l’hébergeur.
	 */
	private static function convert_with_avifenc( $file, $avif ) {
		$binary = '/usr/bin/avifenc';
		if ( ! is_executable( $binary ) || ! function_exists( 'exec' ) ) {
			return false;
		}

		$command = escapeshellarg( $binary ) . ' --min 25 --max 25 ' . escapeshellarg( $file ) . ' ' . escapeshellarg( $avif ) . ' 2>&1';
		$output = array();
		$code   = 1;
		@exec( $command, $output, $code ); // nosemgrep: php.lang.security.exec-use.exec-use -- binaire absolu, chemin média et cible protégés par escapeshellarg


		return 0 === $code && file_exists( $avif ) && filesize( $avif ) > 0;
	}

	/**
	 * Repli local lorsqu’un hébergeur ne compile pas GD/Imagick avec l’encodeur AVIF.
	 * Vips est utilisé seulement s’il est explicitement disponible sur le serveur.
	 */
	private static function convert_with_vips( $file, $avif ) {
		$binary = '/usr/bin/vips';
		if ( ! is_executable( $binary ) || ! function_exists( 'exec' ) ) {
			return false;
		}

		$target = $avif . '[Q=' . absint( self::QUALITY ) . ']';
		$command = escapeshellarg( $binary ) . ' copy ' . escapeshellarg( $file ) . ' ' . escapeshellarg( $target ) . ' 2>&1';
		$output = array();
		$code   = 1;
		@exec( $command, $output, $code ); // nosemgrep: php.lang.security.exec-use.exec-use -- binaire absolu, chemin média et cible protégés par escapeshellarg


		return 0 === $code && file_exists( $avif ) && filesize( $avif ) > 0;
	}

	/**
	 * Reecrit le srcset pour pointer vers les AVIF quand le navigateur le supporte
	 * (via le <picture> : on met les URLs AVIF dans le source, l'img garde jpg/png).
	 */
	public static function srcset_avif( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! self::is_image( $attachment_id ) ) {
			return $sources;
		}
		$base_url = trailingslashit( dirname( $image_src ) );
		foreach ( $sources as $w => &$source ) {
			$avif_file = self::avif_path_for_url( $source['url'] );
			if ( $avif_file ) {
				$source['url'] = $avif_file;
			}
		}
		return $sources;
	}

	/**
	 * Remplace l'URL de l'image principale par l'AVIF quand disponible.
	 * Combine avec wp_calculate_image_srcset pour le srcset AVIF complet.
	 */
	public static function img_src_avif( $image, $attachment_id, $size, $icon ) {
		if ( ! $image || ! self::is_image( $attachment_id ) ) {
			return $image;
		}
		$avif = self::avif_path_for_url( $image[0] );
		if ( $avif ) {
			$image[0] = $avif;
		}
		return $image;
	}

	/**
	 * Enveloppe l'image de contenu en <picture><source avif><img></picture>.
	 * Le src/srcset de l'img restent en jpg/png (secours), le <source> sert l'AVIF.
	 */
	public static function picture_tag( $html, $image, $context ) {
		// Uniquement dans le contenu des articles/pages (pas dans le contenu brut Estatik qui a sa propre galerie).
		if ( 'the_content' !== $context ) {
			return $html;
		}
		if ( false === strpos( $html, '<img' ) || false !== strpos( $html, '<picture' ) ) {
			return $html;
		}

		// Extraire l'URL principale.
		if ( preg_match( '#<img[^>]+src=["\']([^"\']+)["\']#', $html, $m ) ) {
			$img_url = $m[1];
			$avif    = self::avif_path_for_url( $img_url );
			if ( $avif ) {
				// Recreer le srcset AVIF pour le <source>.
				$srcset_attr = '';
				if ( preg_match( '#srcset=["\']([^"\']+)["\']#', $html, $sm ) ) {
					$srcset_attr = ' srcset="' . esc_attr( self::srcset_to_avif( $sm[1] ) ) . '"';
				}
				$new_img = preg_replace( '# srcset="[^"]+"#', '', $html );
				$picture = '<picture>'
					. '<source type="image/avif"' . $srcset_attr . ' srcset="' . esc_attr( $avif ) . '">'
					. $new_img
					. '</picture>';
				return $picture;
			}
		}
		return $html;
	}

	/**
	 * Enveloppe la vignette (featured image) en <picture>.
	 */
	public static function thumbnail_picture( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
		if ( false === strpos( $html, '<img' ) || false !== strpos( $html, '<picture' ) ) {
			return $html;
		}
		if ( preg_match( '#<img[^>]+src=["\']([^"\']+)["\']#', $html, $m ) ) {
			$avif = self::avif_path_for_url( $m[1] );
			if ( $avif ) {
				$srcset_attr = '';
				if ( preg_match( '#srcset=["\']([^"\']+)["\']#', $html, $sm ) ) {
					$srcset_attr = ' srcset="' . esc_attr( self::srcset_to_avif( $sm[1] ) ) . '"';
				}
				$new_img = preg_replace( '# srcset="[^"]+"#', '', $html );
				return '<picture><source type="image/avif"' . $srcset_attr . ' srcset="' . esc_attr( $avif ) . '">' . $new_img . '</picture>';
			}
		}
		return $html;
	}

	/* ================= Helpers ================= */

	/**
	 * URL du fichier AVIF correspondant a une URL d'image.
	 */
		/**
		 * URL de la premiere image d'un bien (vignette, sinon galerie Estatik).
		 *
		 * @param int $post_id ID de l'annonce.
		 * @return string|false
		 */
		public static function first_image( $post_id ) {
			$thumb = get_post_thumbnail_id( $post_id );
			if ( $thumb ) {
				$url = wp_get_attachment_image_url( $thumb, 'pk-hero' );
				if ( $url ) {
					return $url;
				}
			}
			$gallery = get_post_meta( $post_id, 'es_property_gallery', true );
			if ( is_array( $gallery ) && $gallery ) {
				$url = wp_get_attachment_image_url( (int) $gallery[0], 'pk-hero' );
				if ( $url ) {
					return $url;
				}
			}
			return false;
		}

		/**
		 * Retourne une URL d’image uniquement si la ressource locale est non vide
		 * et décodable. Les URLs externes restent soumises au contrôle navigateur.
		 *
		 * @param int    $attachment_id Identifiant media.
		 * @param string $size Taille WordPress.
		 * @return string|false
		 */
		public static function valid_image_url( $attachment_id, $size = 'thumbnail' ) {
			$image = wp_get_attachment_image_src( (int) $attachment_id, $size );
			if ( ! is_array( $image ) || empty( $image[0] ) ) {
				return false;
			}

			$upload = wp_get_upload_dir();
			if ( ! empty( $upload['baseurl'] ) && 0 === strpos( $image[0], $upload['baseurl'] ) ) {
				$path = str_replace( $upload['baseurl'], $upload['basedir'], $image[0] );
				if ( ! is_file( $path ) || filesize( $path ) <= 0 || ( function_exists( 'getimagesize' ) && false === @getimagesize( $path ) ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
					return false;
				}
			}

			return $image[0];
		}

		public static function avif_path_for_url( $url ) {
			// Certains hébergements servent les fichiers `.avif` avec `text/plain`.
			// Dans ce cas, le navigateur peut parfois décoder l’octet mais le contrat
			// image exige un MIME image valide. Le fallback WebP/JPEG reste donc la
			// livraison par défaut ; l’AVIF n’est activé qu’après vérification serveur.
			if ( ! defined( 'PARTIKULIER_ENABLE_AVIF_DELIVERY' ) || ! PARTIKULIER_ENABLE_AVIF_DELIVERY ) {
				return false;
			}
			$avif_url = $url . '.avif';
		// Verifier l'existence physique (upload dir local).
		$upload = wp_get_upload_dir();
		$base   = $upload['baseurl'];
		if ( 0 === strpos( $avif_url, $base ) ) {
			$path = str_replace( $base, $upload['basedir'], $avif_url );
			if ( file_exists( $path ) && filesize( $path ) > 0 ) {
				return $avif_url;
			}
		}
		return false;
	}

	/**
	 * Reecrit chaque URL d'un attribut srcset vers sa variante AVIF.
	 */
	private static function srcset_to_avif( $srcset ) {
		$entries = array_map( 'trim', explode( ',', $srcset ) );
		$out     = array();
		foreach ( $entries as $entry ) {
			$parts = preg_split( '/\s+/', $entry );
			$url   = $parts[0];
			$avif  = self::avif_path_for_url( $url );
			if ( $avif ) {
				$url = $avif;
			}
			$out[] = ( isset( $parts[1] ) ? $url . ' ' . $parts[1] : $url );
		}
		return implode( ', ', $out );
	}

	private static function is_image( $attachment_id ) {
		$mime = get_post_mime_type( $attachment_id );
		return $mime && 0 === strpos( $mime, 'image/' ) && ! in_array( $mime, array( 'image/svg+xml', 'image/avif', 'image/gif' ), true );
	}
}

Partikulier_AVIF::init();