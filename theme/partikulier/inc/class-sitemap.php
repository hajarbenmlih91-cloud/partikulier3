<?php
/**
 * Module : sitemap.xml et robots.txt virtuels, generes par le theme.
 * Pas besoin de Rank Math ni de Yoast.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Sitemap {

	const CACHE_KEY = 'pk_sitemap_xml';
	const TTL       = 86400; // 24 h

	public static function init() {
		add_filter( 'wp_sitemaps_enabled', '__return_false' );
		// robots.txt virtuel (uniquement si le fichier physique n'existe pas).
		add_action( 'do_robotstxt', array( __CLASS__, 'robots_content' ) );
		add_filter( 'robots_txt', array( __CLASS__, 'robots_filter' ), 10, 2 );

		// sitemap.xml virtuel.
		add_rewrite_rule( '^sitemap\.xml/?$', 'index.php?pk_sitemap=1', 'top' );
		add_filter( 'query_vars', function ( $vars ) {
			$vars[] = 'pk_sitemap';
			return $vars;
		} );
		add_action( 'template_redirect', array( __CLASS__, 'serve_sitemap' ) );
		add_filter( 'redirect_canonical', array( __CLASS__, 'keep_sitemap_canonical' ), 10, 2 );

		// Purge a chaque modification d'une annonce ou d'un terme geo.
		foreach ( array( 'save_post', 'delete_post', 'edited_term', 'create_term', 'delete_term' ) as $hook ) {
			add_action( $hook, array( __CLASS__, 'purge' ) );
		}
	}

	/**
	 * Ne pas ajouter de slash au sitemap.xml virtuel : le slash casse l’URL XML attendue par les robots.
	 */
	public static function keep_sitemap_canonical( $redirect_url, $requested_url ) {
		if ( false !== strpos( (string) $requested_url, '/sitemap.xml' ) ) {
			return false;
		}
		return $redirect_url;
	}

	/**
	 * Contenu robots.txt enrichi (geo + LLM).
	 */
	public static function robots_content() {
		// Le filtre robots_txt ci-dessous produit l’unique directive Sitemap.
	}

	public static function robots_filter( $output, $public ) {
		$output = preg_replace( '/^Sitemap:\s*.*$/mi', '', (string) $output );
		$output = rtrim( $output ) . "\n";
		$output .= "Disallow: /?s=\n";
		$output .= "Disallow: /search/\n";
		$output .= "Sitemap: " . home_url( '/sitemap.xml' ) . "\n";
		return $output;
	}

	/**
	 * Génère et sert le sitemap.xml (cache 24 h en transient objet).
	 */
	public static function serve_sitemap() {
		if ( ! get_query_var( 'pk_sitemap' ) ) {
			return;
		}

		$xml = get_transient( self::CACHE_KEY );
		if ( false === $xml ) {
			$xml = self::generate();
			set_transient( self::CACHE_KEY, $xml, self::TTL );
		}

		status_header( 200 );
		nocache_headers();
		header( 'Content-Type: application/xml; charset=UTF-8' );
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo $xml;
		exit;
	}

	/**
	 * Construction du sitemap : annonces (changepriorité selon fraicheur),
	 * pages, taxonomies geo (villes = haute priorité pour le SEO local).
	 */
	private static function generate() {
		$urls   = array();
		$home   = home_url( '/' );
		$urls[] = array( 'loc' => $home, 'lastmod' => mysql2date( 'Y-m-d', get_lastpostmodified( 'gmt' ) ), 'priority' => '1.0', 'changefreq' => 'hourly' );

		// --- Pages publiques ---
		$pages = get_posts( array(
			'post_type'      => 'page',
			'post_status'    => 'publish',
			'numberposts'    => -1,
			'no_found_rows'  => true,
		) );
		$excluded_pages = array( 'deposer', 'deposer-en', 'deposer-ar', 'deposer-une-annonce', 'deposer-annonce', 'mes-annonces', 'mes-annonces-en', 'mes-annonces-ar', 'favoris', 'favoris-en', 'favoris-ar', 'connexion', 'connexion-en', 'connexion-ar', 'annonces', 'catalogue' );
		$seen_pages = array();
		foreach ( $pages as $p ) {
			if ( in_array( $p->post_name, $excluded_pages, true ) ) {
				continue;
			}
			$translated_pages = function_exists( 'pll_get_post_translations' ) ? pll_get_post_translations( $p->ID ) : array( 'fr' => $p->ID );
			foreach ( $translated_pages as $translated_id ) {
				$translated = get_post( $translated_id );
				if ( ! $translated instanceof WP_Post || 'publish' !== $translated->post_status ) {
					continue;
				}
				$link = get_permalink( $translated );
				if ( isset( $seen_pages[ $link ] ) ) {
					continue;
				}
				$seen_pages[ $link ] = true;
				$urls[] = array(
					'loc'        => $link,
					'lastmod'    => mysql2date( 'Y-m-d', $translated->post_modified_gmt ),
					'priority'   => '0.8',
					'changefreq' => 'weekly',
				);
			}
		}
		$archive = pk_properties_archive_url();
		if ( $archive ) {
			$urls[] = array(
				'loc'        => $archive,
				'lastmod'    => mysql2date( 'Y-m-d', get_lastpostmodified( 'gmt', PARTIKULIER_ESTATIK_POST_TYPE ) ),
				'priority'   => '0.9',
				'changefreq' => 'hourly',
			);
		}

		// --- Annonces ---
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_modified_gmt FROM {$wpdb->posts}
					 WHERE post_type = %s AND post_status = 'publish'
					 ORDER BY post_modified_gmt DESC LIMIT 5000",
				PARTIKULIER_ESTATIK_POST_TYPE
			),
			OBJECT
		);
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$urls[] = array(
					'loc'        => get_permalink( (int) $row->ID ),
					'lastmod'    => mysql2date( 'Y-m-d', $row->post_modified_gmt ),
					'priority'   => '0.9',
					'changefreq' => 'weekly',
				);
			}
		}

		// --- Taxonomie géographique Estatik 4 (villes et quartiers publiés). ---
		$priorities = array(
			PARTIKULIER_ESTATIK_LOCATION_TAXONOMY => array( '0.9', 'daily' ),
		);
		foreach ( $priorities as $tax => $p ) {
			$terms = get_terms( array( 'taxonomy' => $tax, 'hide_empty' => true, 'number' => 2000 ) );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$link = get_term_link( $term );
					if ( ! is_wp_error( $link ) ) {
						$urls[] = array(
							'loc'        => $link,
						'lastmod'    => mysql2date( 'Y-m-d', get_lastpostmodified( 'gmt', PARTIKULIER_ESTATIK_POST_TYPE ) ),
							'priority'   => $p[0],
							'changefreq' => $p[1],
						);
					}
				}
			}
		}

		$out = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $urls as $u ) {
			$out .= "\t<url>\n";
			$out .= "\t\t<loc>" . esc_xml( $u['loc'] ) . "</loc>\n";
			$out .= "\t\t<lastmod>" . esc_xml( $u['lastmod'] ) . "</lastmod>\n";
			$out .= "\t\t<changefreq>" . esc_xml( $u['changefreq'] ) . "</changefreq>\n";
			$out .= "\t\t<priority>" . esc_xml( $u['priority'] ) . "</priority>\n";
			$out .= "\t</url>\n";
		}
		$out .= '</urlset>' . "\n";

		return $out;
	}

	public static function purge() {
		delete_transient( self::CACHE_KEY );
		Partikulier_Cache::purge_all();
	}
}

Partikulier_Sitemap::init();