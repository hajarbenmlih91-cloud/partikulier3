<?php
/**
 * Journal, cinq articles, quatre pages légales.
 *
 * Le texte vient du fichier content/journal.json. L'arabe n'est pas écrit.
 * Aucun numéro, aucun siège, aucun prix Tuk n'est ajouté ici.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Journal {

	const SEED = '1';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'seed' ), 20 );
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function bundle() {
		$file = PARTIKULIER_DIR . '/content/journal.json';
		if ( ! is_readable( $file ) ) {
			return array();
		}
		$data = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $data ) ? $data : array();
	}

	public static function seed() {
		$bundle = self::bundle();
		if ( ! $bundle ) {
			return;
		}
		self::seed_pages( isset( $bundle['legal'] ) && is_array( $bundle['legal'] ) ? $bundle['legal'] : array() );
		self::seed_articles( isset( $bundle['articles'] ) && is_array( $bundle['articles'] ) ? $bundle['articles'] : array() );
	}

	/**
	 * @param array<string,string> $pages
	 */
	private static function seed_pages( $pages ) {
		$defs = array(
			'journal'           => array( 'title' => 'Journal', 'template' => 'templates/page-journal.php' ),
			'mentions-legales'  => array( 'title' => 'Mentions légales', 'template' => 'templates/page-legal.php' ),
			'conditions'        => array( 'title' => 'Conditions générales', 'template' => 'templates/page-legal.php' ),
			'confidentialite'   => array( 'title' => 'Confidentialité', 'template' => 'templates/page-legal.php' ),
			'cookies'           => array( 'title' => 'Cookies', 'template' => 'templates/page-legal.php' ),
		);
		foreach ( $defs as $slug => $def ) {
			$content = isset( $pages[ $slug ] ) ? (string) $pages[ $slug ] : '';
			if ( 'journal' === $slug ) {
				$content = '';
			}
			$page = self::find_by_slug( $slug, 'page' );
			if ( ! $page instanceof WP_Post ) {
				$id = wp_insert_post(
					array(
						'post_type'      => 'page',
						'post_status'    => 'publish',
						'post_title'     => $def['title'],
						'post_name'      => $slug,
						'post_content'   => $content,
						'comment_status' => 'closed',
						'ping_status'    => 'closed',
					)
				);
				if ( ! $id || is_wp_error( $id ) ) {
					continue;
				}
				$page = get_post( $id );
			} elseif ( (string) get_post_meta( $page->ID, '_pk_journal_seed', true ) !== self::SEED && '' !== $content ) {
				wp_update_post(
					array(
						'ID'           => $page->ID,
						'post_content' => $content,
						'post_status'  => 'publish',
					)
				);
			}
			if ( ! $page instanceof WP_Post ) {
				continue;
			}
			update_post_meta( $page->ID, '_wp_page_template', $def['template'] );
			update_post_meta( $page->ID, '_pk_journal_seed', self::SEED );
			if ( function_exists( 'pll_set_post_language' ) && ! pll_get_post_language( $page->ID ) ) {
				pll_set_post_language( $page->ID, 'fr' );
			}
		}
	}

	/**
	 * @param array<int,array<string,string>> $articles
	 */
	private static function seed_articles( $articles ) {
		$order = 1;
		foreach ( $articles as $article ) {
			$slug = isset( $article['slug'] ) ? sanitize_title( (string) $article['slug'] ) : '';
			if ( '' === $slug ) {
				continue;
			}
			$existing = self::find_by_slug( $slug, 'post' );
			$fields   = array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'post_title'     => isset( $article['title'] ) ? (string) $article['title'] : $slug,
				'post_name'      => $slug,
				'post_content'   => isset( $article['body'] ) ? (string) $article['body'] : '',
				'post_excerpt'   => isset( $article['chapo'] ) ? (string) $article['chapo'] : '',
				'menu_order'     => $order,
				'comment_status' => 'closed',
				'ping_status'    => 'closed',
			);
			if ( $existing instanceof WP_Post && (string) get_post_meta( $existing->ID, '_pk_journal_seed', true ) === self::SEED ) {
				$id = (int) $existing->ID;
			} elseif ( $existing instanceof WP_Post ) {
				$fields['ID'] = (int) $existing->ID;
				$id           = wp_update_post( $fields );
			} else {
				$id = wp_insert_post( $fields );
			}
			if ( ! $id || is_wp_error( $id ) ) {
				continue;
			}
			update_post_meta( $id, '_pk_journal_seed', self::SEED );
			update_post_meta( $id, '_pk_kicker', isset( $article['kicker'] ) ? (string) $article['kicker'] : '' );
			update_post_meta( $id, '_pk_chapo', isset( $article['chapo'] ) ? (string) $article['chapo'] : '' );
			update_post_meta( $id, '_pk_signature', isset( $article['signature'] ) ? (string) $article['signature'] : 'La rédaction' );
			update_post_meta( $id, '_pk_caption', isset( $article['caption'] ) ? (string) $article['caption'] : '' );
			update_post_meta( $id, '_pk_journal_order', $order );
			if ( function_exists( 'pll_set_post_language' ) && ! pll_get_post_language( $id ) ) {
				pll_set_post_language( $id, 'fr' );
			}
			self::attach_photo( (int) $id, isset( $article['photo'] ) ? (string) $article['photo'] : '' );
			++$order;
		}
	}

	private static function find_by_slug( $slug, $type ) {
		$found = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => $type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
				'posts_per_page'   => 1,
				'suppress_filters' => true,
				'lang'             => '',
			)
		);
		return $found ? $found[0] : null;
	}

	private static function attach_photo( $post_id, $photo ) {
		$photo = basename( $photo );
		if ( '' === $photo || has_post_thumbnail( $post_id ) ) {
			return;
		}
		$file = PARTIKULIER_DIR . '/assets/journal/' . $photo;
		if ( ! is_readable( $file ) ) {
			return;
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$bits = wp_upload_bits( $photo, null, (string) file_get_contents( $file ) );
		if ( ! empty( $bits['error'] ) || empty( $bits['file'] ) ) {
			return;
		}
		$filetype = wp_check_filetype( $bits['file'] );
		$attach   = wp_insert_attachment(
			array(
				'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'image/jpeg',
				'post_title'     => sanitize_file_name( pathinfo( $photo, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			),
			$bits['file'],
			$post_id
		);
		if ( ! $attach || is_wp_error( $attach ) ) {
			return;
		}
		wp_update_attachment_metadata( $attach, wp_generate_attachment_metadata( $attach, $bits['file'] ) );
		set_post_thumbnail( $post_id, $attach );
	}

	/**
	 * @return WP_Query
	 */
	public static function query() {
		return new WP_Query(
			array(
				'post_type'           => 'post',
				'post_status'         => 'publish',
				'posts_per_page'      => 5,
				'no_found_rows'       => true,
				'ignore_sticky_posts' => true,
				'lang'                => 'fr',
				'meta_key'            => '_pk_journal_order',
				'orderby'             => 'meta_value_num',
				'order'               => 'ASC',
			)
		);
	}

	/**
	 * Rubriques déjà écrites, dans l'ordre de la maquette acceptée.
	 *
	 * @param array<int,WP_Post> $posts Articles du journal.
	 * @return array<int,array{label:string,url:string}>
	 */
	public static function home_nav( $posts ) {
		$wanted = array( 'Logement', 'Villes', 'Achat', 'Conseils' );
		$found  = array();
		foreach ( $posts as $post ) {
			if ( ! $post instanceof WP_Post ) {
				continue;
			}
			$kicker = (string) get_post_meta( $post->ID, '_pk_kicker', true );
			if ( '' === $kicker || isset( $found[ $kicker ] ) ) {
				continue;
			}
			$found[ $kicker ] = get_permalink( $post );
		}
		$nav = array();
		foreach ( $wanted as $label ) {
			if ( empty( $found[ $label ] ) ) {
				continue;
			}
			$nav[] = array(
				'label' => $label,
				'url'   => $found[ $label ],
			);
		}
		return $nav;
	}

	/**
	 * Date du premier article, en français. Pas une date inventée.
	 *
	 * @param WP_Post $post Article.
	 * @return string
	 */
	public static function french_date( $post ) {
		$raw = substr( (string) $post->post_date, 0, 10 );
		$bits = explode( '-', $raw );
		if ( 3 !== count( $bits ) || ! checkdate( (int) $bits[1], (int) $bits[2], (int) $bits[0] ) ) {
			return '';
		}
		$stamp  = gmmktime( 12, 0, 0, (int) $bits[1], (int) $bits[2], (int) $bits[0] );
		$days   = array( 'dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi' );
		$months = array( 1 => 'janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre' );
		$day    = $days[ (int) gmdate( 'w', $stamp ) ];
		$month  = $months[ (int) $bits[1] ];
		return ucfirst( $day ) . ' ' . (int) $bits[2] . ' ' . $month . ' ' . $bits[0];
	}

	public static function footer_links() {
		$items = array(
			'journal'          => array( 'fr' => 'Journal', 'en' => 'Journal' ),
			'mentions-legales' => array( 'fr' => 'Mentions légales', 'en' => 'Legal notice' ),
			'conditions'       => array( 'fr' => 'Conditions', 'en' => 'Terms' ),
			'confidentialite'  => array( 'fr' => 'Confidentialité', 'en' => 'Privacy' ),
			'cookies'          => array( 'fr' => 'Cookies', 'en' => 'Cookies' ),
		);
		$lang = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
		if ( 'en' !== $lang ) {
			$lang = 'fr';
		}
		echo '<ul class="pk-legal-links">';
		foreach ( $items as $slug => $labels ) {
			$url   = function_exists( 'pk_page_url' ) ? pk_page_url( $slug, '/' . $slug . '/' ) : home_url( '/' . $slug . '/' );
			$label = $labels[ $lang ];
			$attr  = 'en' === $lang ? '' : ' lang="fr"';
			printf( '<li><a href="%s"%s>%s</a></li>', esc_url( $url ), $attr, esc_html( $label ) );
		}
		echo '</ul>';
	}
}

Partikulier_Journal::init();
