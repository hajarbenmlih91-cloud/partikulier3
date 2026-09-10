<?php
/**
 * Module : optimisation PageSpeed 100.
 *
 * Supprime le "bruit" de WordPress : emojis, dashicons, oEmbed, RSD,
 * wlwmanifest, shortlink, jquery par defaut du front, oEmbed discovery.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Optimization {

	public static function init() {
		// --- Desactiver les emojis (script + CSS) ---
		remove_action( 'wp_head', 'print_emoji_detection_script', 7 );
		remove_action( 'admin_print_scripts', 'print_emoji_detection_script' );
		remove_action( 'wp_print_styles', 'print_emoji_styles' );
		remove_action( 'admin_print_styles', 'print_emoji_styles' );
		remove_filter( 'the_content_feed', 'wp_staticize_emoji' );
		remove_filter( 'comment_text_rss', 'wp_staticize_emoji' );
		remove_filter( 'wp_mail', 'wp_staticize_emoji_for_email' );
		add_filter( 'tiny_mce_plugins', array( __CLASS__, 'disable_emoji_tinymce' ) );
		add_filter( 'emoji_svg_url', '__return_false' );

		// --- Nettoyage du <head> ---
		remove_action( 'wp_head', 'rsd_link' );
		remove_action( 'wp_head', 'wlwmanifest_link' );
		remove_action( 'wp_head', 'wp_shortlink_wp_head', 10 );
		remove_action( 'wp_head', 'rest_output_link_wp_head', 10 );
		remove_action( 'template_redirect', 'rest_output_link_header', 11 );
		remove_action( 'wp_head', 'wp_generator' );
		remove_action( 'wp_head', 'wp_oembed_add_discovery_links', 10 );

		// --- Desactiver la decouverte oEmbed (requete HTTP externe en plus) ---
		add_filter( 'embed_oembed_discover', '__return_false' );
		remove_action( 'wp_head', 'wp_oembed_add_host_js' );
		add_filter( 'pre_oembed_result', '__return_empty_string', 999 );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'disable_oembed_rest' ), 10, 3 );

		// --- Desactiver dashicons sur le front (sauf si admin bar) ---
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'dequeue_dashicons' ), 100 );

		// --- jQuery : NE PLUS le deregistrer ---
		// Estatik (recherche AJAX, carte, galerie) et la plupart des plugins tiers
		// dependent de jQuery. Le deregistrer provoquait un
		// "Uncaught ReferenceError: jQuery is not defined" qui tuait tout leur JS.
		// WordPress ne charge de toute facon jQuery que si un script le declare
		// en dependance : sur une page sans plugin, il n'est pas envoye.
		// add_action( 'wp_default_scripts', array( __CLASS__, 'remove_jquery_from_front' ) );

		// --- Query strings sur les assets : CONSERVEES ---
		// Retirer ?ver=X supprimait le cache-busting : apres chaque modification de
		// style.css, les navigateurs (et les CDN) continuaient a servir l'ancienne
		// feuille indefiniment. Le "gain" est un mythe SEO ; la version est
		// indispensable des qu'on itere sur le design.
		// add_filter( 'style_loader_src', array( __CLASS__, 'strip_query_string' ), 10, 2 );
		// add_filter( 'script_loader_src', array( __CLASS__, 'strip_query_string' ), 10, 2 );

		// --- Compression HTML : DESACTIVEE ---
		// Les regex de compress_html() supprimaient les espaces autour de : , < >
		// dans le TEXTE aussi, pas seulement dans le balisage. Effets constates :
		//   "Prix : 300 000 €"                  -> "Prix:300 000 €"
		//   "annonce <strong>gratuite</strong> " -> "annonce<strong>gratuite</strong>"
		//   "sizes=\"(max-width: 640px) 100vw\"" -> "sizes=\"(max-width:640px) 100vw\""
		// Le gain reel est nul sur une reponse deja gzip/brotli. Ne pas reactiver.
		// add_action( 'template_redirect', array( __CLASS__, 'start_html_compression' ), 1 );

		// --- Preconnect vers le domaine lui-meme (self) pour assets ---
		add_action( 'wp_head', array( __CLASS__, 'self_preconnect' ), 1 );

		// --- Lazy loading natif des iframes/oEmbed ---
		add_filter( 'the_content', array( __CLASS__, 'native_lazy_iframes' ), 99 );
	}

	public static function disable_emoji_tinymce( $plugins ) {
		if ( is_array( $plugins ) ) {
			return array_diff( $plugins, array( 'wpemoji' ) );
		}
		return array();
	}

	public static function disable_oembed_rest( $result, $server, $request ) {
		if ( '/oembed/1.0/proxy' === $request->get_route() ) {
			return new WP_Error( 'oembed_disabled', __( 'oEmbed desactive.', 'partikulier' ), array( 'status' => 404 ) );
		}
		return $result;
	}

	public static function dequeue_dashicons() {
		if ( ! is_admin_bar_showing() ) {
			wp_dequeue_style( 'dashicons' );
		}
	}

	/**
	 * Retire le script jquery-core du paquet par defaut du front,
	 * mais seulement si aucun plugin n'en a explicitement besoin via wp_enqueue.
	 */
	public static function remove_jquery_from_front( $scripts ) {
		if ( is_admin() ) {
			return;
		}
		// On deregistre jquery : les scripts enregistrés par le theme n'en dépendent pas.
		// Si un plugin force jQuery via dépendance explicite, WordPress affichera une erreur JS :
		// c'est voulu, le theme est "sans jQuery" comme demande.
		$scripts->remove( 'jquery' );
		$scripts->remove( 'jquery-core' );
		$scripts->remove( 'jquery-migrate' );
	}

	public static function strip_query_string( $src ) {
		if ( strpos( $src, 'ver=' ) ) {
			$src = remove_query_arg( 'ver', $src );
		}
		return $src;
	}

	/**
	 * Minification HTML legere : supprime les espaces/commentaires triviaux.
	 * Sans risque pour le rendu, compatible cache de page.
	 */
	public static function start_html_compression() {
		if ( defined( 'WP_CLI' ) || ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) ) {
			return;
		}
		if ( is_admin() || wp_doing_ajax() ) {
			return;
		}
		ob_start( array( __CLASS__, 'compress_html' ) );
	}

	public static function compress_html( $html ) {
		if ( is_admin_bar_showing() ) {
			return $html;
		}
		// Ne jamais toucher aux <pre>, <textarea>, <script>, <style>.
		$protected = array();
		$html = preg_replace_callback( '#(<pre.*?</pre>|<textarea.*?</textarea>|<script.*?</script>|<style.*?</style>)#is', function ( $m ) use ( &$protected ) {
			$key = "\x00PK" . count( $protected ) . "\x00";
			$protected[ $key ] = $m[0];
			return $key;
		}, $html );

		// Suppression des commentaires HTML (garde les commentaires de condition IE n'existant plus).
		$html = preg_replace( '#<!--(?!\s*?(?:\[if [^\]]+\]|<!|>))(?:(?!-->).)*-->#s', '', $html );

		// Suppression des espaces triviaux autour des balises block.
		$html = preg_replace( '#\s+([</{}:;,>])#u', '$1', $html );
		$html = preg_replace( '#([<{:,>])\s+#u', '$1', $html );

		// Remise en place des blocs proteges.
		return str_replace( array_keys( $protected ), array_values( $protected ), $html );
	}

	public static function self_preconnect() {
		if ( ! is_ssl() ) {
			return;
		}
		$host = wp_parse_url( home_url(), PHP_URL_HOST );
		if ( $host ) {
			printf( '<link rel="preconnect" href="https://%s">%s', esc_attr( $host ), "\n" );
		}
	}

	public static function native_lazy_iframes( $content ) {
		return preg_replace( '#<iframe#i', '<iframe loading="lazy"', $content );
	}
}

Partikulier_Optimization::init();