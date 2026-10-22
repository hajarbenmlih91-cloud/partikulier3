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

	public static function is_non_production_host() {
		return false !== strpos( (string) home_url( '/' ), 'hostingersite.com' );
	}

	public static function robots_filter( $output, $public ) {
		unset( $public );
		if ( self::is_non_production_host() ) {
			return "User-agent: *\nDisallow: /\n";
		}
		$output  = preg_replace( '/^Sitemap:\s*.*$/mi', '', (string) $output );
		$output  = rtrim( $output ) . "\n";
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
		echo $xml; // phpcs:ignore WordPress.Security.EscapeOutput -- document XML construit champ par champ avec esc_xml() (l.184-187) (SE-020)
		exit;
	}

	/**
	 * Construction du sitemap : annonces (changepriorité selon fraicheur),
	 * pages, taxonomies geo (villes = haute priorité pour le SEO local).
	 */
	private static function generate() {
		$urls    = array();
		$fr_home = function_exists( 'pk_localized_home_url' ) ? self::unwrap_redirect( pk_localized_home_url( 'fr' ) ) : home_url( '/fr/' );
		/* Sur le banc local, / ne redirige pas : le contrat E37-001 exige cette URL.
		 * Ailleurs, / et /fr/accueil-fr/ redirigent : on donne /fr/. */
		$home            = self::root_redirects() ? $fr_home : home_url( '/' );
		$home_alternates = array(
			'fr'        => $fr_home,
			'x-default' => $fr_home,
		);
		foreach ( array( 'fr', 'en', 'ar' ) as $locale ) {
			$locale_url = function_exists( 'pk_localized_home_url' ) ? self::unwrap_redirect( pk_localized_home_url( $locale ) ) : '';
			if ( $locale_url && ! self::redirects_publicly( $locale_url ) ) {
				$home_alternates[ $locale ] = $locale_url;
			}
		}
		$urls[] = array( 'loc' => $home, 'lastmod' => mysql2date( 'Y-m-d', get_lastpostmodified( 'gmt' ) ), 'priority' => '1.0', 'changefreq' => 'hourly', 'alternates' => $home_alternates );

		// --- Pages publiques ---
		$pages          = get_posts( array(
			'post_type'     => 'page',
			'post_status'   => 'publish',
			'numberposts'   => -1,
			'no_found_rows' => true,
		) );
		$excluded_pages = array( 'deposer', 'deposer-en', 'deposer-ar', 'deposer-une-annonce', 'deposer-annonce', 'mes-annonces', 'mes-annonces-en', 'mes-annonces-ar', 'favoris', 'favoris-en', 'favoris-ar', 'connexion', 'connexion-en', 'connexion-ar', 'annonces', 'catalogue' );
		$seen_pages     = array();
		foreach ( $pages as $p ) {
			if ( in_array( $p->post_name, $excluded_pages, true ) || 0 === strpos( (string) $p->post_name, 'accueil-' ) ) {
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
				$urls[]              = array(
					'loc'        => $link,
					'lastmod'    => mysql2date( 'Y-m-d', $translated->post_modified_gmt ),
					'priority'   => '0.8',
					'changefreq' => 'weekly',
				);
			}
		}
		$articles = get_posts(
			array(
				'post_type'      => 'post',
				'post_status'    => 'publish',
				'numberposts'    => 200,
				'no_found_rows'  => true,
			)
		);
		foreach ( $articles as $article ) {
			$loc = get_permalink( $article );
			if ( ! $loc ) {
				continue;
			}
			$urls[] = array(
				'loc'        => $loc,
				'alternates' => self::language_alternates( (int) $article->ID, $loc ),
				'lastmod'    => mysql2date( 'Y-m-d', $article->post_modified_gmt ),
				'priority'   => '0.6',
				'changefreq' => 'monthly',
			);
		}
		if ( class_exists( 'Partikulier_Price_Index' ) && Partikulier_Price_Index::is_publicly_served() ) {
			$price_url = Partikulier_Price_Index::public_url();
			$urls[]    = array(
				'loc'        => $price_url,
				'lastmod'    => Partikulier_Price_Index::snapshot()['computed_at'],
				'priority'   => '0.5',
				'changefreq' => 'weekly',
				'alternates' => array(
					'fr'        => $price_url,
					'x-default' => $price_url,
				),
			);
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
				$loc = get_permalink( (int) $row->ID );
				$urls[] = array(
					'loc'        => $loc,
					'alternates' => self::language_alternates( (int) $row->ID, $loc ), // SE-037 (E-3701)
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
						'lastmod'        => mysql2date( 'Y-m-d', get_lastpostmodified( 'gmt', PARTIKULIER_ESTATIK_POST_TYPE ) ),
							'priority'   => $p[0],
							'changefreq' => $p[1],
						);
					}
				}
			}
		}

		$urls = self::without_redirects( $urls );
		$out = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:xhtml="http://www.w3.org/1999/xhtml">' . "\n"; // SE-037 (E-3701) : alternates hreflang
		foreach ( $urls as $u ) {
			$out .= "\t<url>\n";
			/* SE-037 (E-3701) : cluster d'alternates hreflang — une page
                         * multilingue se déclare à Google comme UN groupe d'URLs
                         * équivalentes (xhtml:link), pas comme des URLs isolées. */
                        $alternates = isset( $u['alternates'] ) && is_array( $u['alternates'] ) ? $u['alternates'] : array();
                        foreach ( $alternates as $lang => $alt_url ) {
                                $out .= "\t\t<xhtml:link rel=\"alternate\" hreflang=\"" . esc_xml( (string) $lang ) . "\" href=\"" . esc_xml( (string) $alt_url ) . "\"/>" . "\n";
                        }
			$out .= "\t\t<loc>" . esc_xml( $u['loc'] ) . "</loc>\n";
			$out .= "\t\t<lastmod>" . esc_xml( $u['lastmod'] ) . "</lastmod>\n";
			$out .= "\t\t<changefreq>" . esc_xml( $u['changefreq'] ) . "</changefreq>\n";
			$out .= "\t\t<priority>" . esc_xml( $u['priority'] ) . "</priority>\n";
			$out .= "\t</url>\n";
		}
		$out .= '</urlset>' . "\n";

		return $out;
	}

        /**
         * SE-037 (E-3701) : cluster d'alternates d'une annonce — une URL par
         * langue disponible (Polylang), x-default pointant la langue par
         * défaut. Sans Polylang : l'URL elle-même + x-default (le sitemap
         * reste complet et valide, E-3703).
         *
         * @param int    $post_id Annonce.
         * @param string $loc     URL de la langue courante (fallback).
         * @return array<string,string> hreflang => URL
         */
        public static function language_alternates( $post_id, $loc = '' ) {
                $loc     = $loc ? (string) $loc : (string) get_permalink( $post_id );
                $loc     = self::unwrap_redirect( $loc );
                $default = function_exists( 'pll_default_language' ) ? (string) pll_default_language() : 'fr';
                if ( '' === $default ) {
                        $default = 'fr';
                }
                $out = array();
                if ( function_exists( 'pll_get_post_translations' ) ) {
                        foreach ( (array) pll_get_post_translations( $post_id ) as $lang => $translated_id ) {
                                $translated = get_post( $translated_id );
                                if ( $translated instanceof WP_Post && 'publish' === $translated->post_status ) {
                                        $link = self::unwrap_redirect( (string) get_permalink( $translated ) );
                                        if ( $link && ! self::redirects_publicly( $link ) ) {
                                                $out[ (string) $lang ] = $link;
                                        }
                                }
                        }
                }
                if ( empty( $out ) ) {
                        $out[ $default ] = $loc;
                } elseif ( empty( $out[ $default ] ) && $loc && ! self::redirects_publicly( $loc ) ) {
                        $out[ $default ] = $loc;
                }
                if ( ! empty( $out['fr'] ) ) {
                        $out['x-default'] = $out['fr'];
                } elseif ( ! empty( $out[ $default ] ) ) {
                        $out['x-default'] = $out[ $default ];
                }
                return $out;
        }

        /**
         * La racine redirige hors du banc local. Le contrat de sitemap y exige encore home_url('/').
         */
        public static function root_redirects() {
                $host = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
                return false === strpos( $host, '127.0.0.1' ) && 0 !== strpos( $host, 'localhost' );
        }

        /**
         * /fr/accueil-fr/ n'est pas une page. La redirection, elle, reste pour les anciens liens.
         */
        public static function unwrap_redirect( $url ) {
                $url  = (string) $url;
                $path = (string) wp_parse_url( $url, PHP_URL_PATH );
                if ( preg_match( '#/(fr|en|ar)/accueil-(?:fr|en|ar)/?#', $path, $m ) && function_exists( 'pk_localized_home_url' ) ) {
                        return pk_localized_home_url( $m[1] );
                }
                return $url;
        }

        /**
         * Une adresse qui répond 301 ne doit pas être donnée comme page.
         */
        public static function redirects_publicly( $url ) {
                $path = (string) wp_parse_url( (string) $url, PHP_URL_PATH );
                $path = '/' . trim( $path, '/' );
                if ( '/' === $path ) {
                        return self::root_redirects();
                }
                return (bool) preg_match( '#/(fr|en|ar)/accueil-(?:fr|en|ar)$#', $path );
        }

        /**
         * Retire les adresses qui redirigent. x-default reste la page française du même groupe.
         *
         * @param array<int,array<string,mixed>> $urls Entrées du sitemap.
         * @return array<int,array<string,mixed>>
         */
        private static function without_redirects( $urls ) {
                $clean = array();
                foreach ( $urls as $entry ) {
                        if ( empty( $entry['loc'] ) || self::redirects_publicly( (string) $entry['loc'] ) ) {
                                continue;
                        }
                        $entry['loc'] = self::unwrap_redirect( (string) $entry['loc'] );
                        if ( self::redirects_publicly( (string) $entry['loc'] ) ) {
                                continue;
                        }
                        if ( ! empty( $entry['alternates'] ) && is_array( $entry['alternates'] ) ) {
                                $alternates = array();
                                foreach ( $entry['alternates'] as $lang => $alt_url ) {
                                        $alt_url = self::unwrap_redirect( (string) $alt_url );
                                        if ( $alt_url && ! self::redirects_publicly( $alt_url ) ) {
                                                $alternates[ (string) $lang ] = $alt_url;
                                        }
                                }
                                if ( ! empty( $alternates['fr'] ) ) {
                                        $alternates['x-default'] = $alternates['fr'];
                                }
                                $entry['alternates'] = $alternates;
                        }
                        $clean[] = $entry;
                }
                return $clean;
        }

        public static function purge() {
		delete_transient( self::CACHE_KEY );
		Partikulier_Cache::purge_all();
	}
}

Partikulier_Sitemap::init();