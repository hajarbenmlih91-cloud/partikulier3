<?php
/**
 * Le tunnel remplace la page WordPress « Déposer une annonce ».
 *
 * Le formulaire préparé est servi seul : pas d'en-tête ni de pied du site.
 * Sa barre de langue change la version, et /en/ ou /ar/ ouvre cette version.
 * /apercu-tunnel/ redirige vers /deposer/.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Tunnel_Preview {

	/**
	 * Branche la redirection de l'ancien aperçu et le gabarit du dépôt.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'maybe_redirect' ), 0 );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_serve' ), 0 );
		add_filter( 'template_include', array( __CLASS__, 'load_template' ), 99 );
	}

	/**
	 * Vrai si l'adresse demandée est une page de dépôt.
	 *
	 * @return bool
	 */
	public static function is_deposit_request() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- chemin comparé à un motif fixe.
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		return (bool) preg_match( '#^/(?:(?:fr|en|ar)/)?(?:deposer(?:-\d+)?|deposer-une-annonce|deposer-annonce|publier-une-annonce)/?$#', $path );
	}

	/**
	 * Vrai si la page demandée est un dépôt, même avec un slug dupliqué.
	 *
	 * @return bool
	 */
	public static function is_deposit_page() {
		if ( ! is_page() ) {
			return false;
		}
		$post = get_queried_object();
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		if ( preg_match( '/^deposer(?:-\d+)?$/', (string) $post->post_name ) ) {
			return true;
		}
		$template = (string) get_page_template_slug( $post->ID );
		return in_array( $template, array( 'templates/page-deposer-annonce.php', 'templates/page-deposer-tunnel.php' ), true );
	}

	/**
	 * Sert le formulaire avant que le thème n'imprime son en-tête.
	 */
	public static function maybe_serve() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		if ( ! self::is_deposit_request() && ! self::is_deposit_page() ) {
			return;
		}
		self::serve_document();
	}

	/**
	 * Langue du document : l'adresse d'abord, sinon la langue de la page.
	 *
	 * @return string
	 */
	private static function document_lang() {
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- chemin comparé à un motif fixe.
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		if ( preg_match( '#^/(fr|en|ar)(?:/|$)#', $path, $match ) ) {
			return $match[1];
		}
		if ( function_exists( 'pll_current_language' ) ) {
			$lang = pll_current_language( 'slug' );
			if ( in_array( $lang, array( 'fr', 'en', 'ar' ), true ) ) {
				return $lang;
			}
		}
		return 'fr';
	}

	/**
	 * Adresse du dépôt dans une langue, pour rester sur le formulaire.
	 *
	 * @param string $lang Code fr, en ou ar.
	 * @return string
	 */
	public static function deposit_url( $lang ) {
		$lang = sanitize_key( (string) $lang );
		$page = class_exists( 'Partikulier_Required_Pages' ) ? Partikulier_Required_Pages::find( 'deposer' ) : null;
		if ( $page instanceof WP_Post && function_exists( 'pll_get_post' ) ) {
			$translated = pll_get_post( $page->ID, $lang );
			if ( $translated ) {
				$url = get_permalink( $translated );
				if ( $url ) {
					return $url;
				}
			}
		}
		if ( 'en' === $lang || 'ar' === $lang ) {
			return home_url( '/' . $lang . '/deposer/' );
		}
		return home_url( '/deposer/' );
	}

	/**
	 * L'ancien aperçu rejoint la page de dépôt.
	 */
	public static function maybe_redirect() {
		if ( is_admin() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			return;
		}
		$path = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- chemin comparé à un motif fixe.
		$path = (string) wp_parse_url( $path, PHP_URL_PATH );
		if ( ! preg_match( '#^/(?:(fr|en|ar)/)?apercu-tunnel/?$#', $path, $match ) ) {
			return;
		}
		$lang = isset( $match[1] ) ? $match[1] : '';
		wp_safe_redirect( self::deposit_url( $lang ), 302 );
		exit;
	}

	/**
	 * Affiche le tunnel à la place de l'ancien formulaire.
	 *
	 * @param string $template Gabarit choisi par WordPress.
	 * @return string
	 */
	public static function load_template( $template ) {
		if ( ! self::is_deposit_request() ) {
			return $template;
		}
		$file = get_theme_file_path( 'templates/page-deposer-tunnel.php' );
		if ( ! file_exists( $file ) ) {
			return $template;
		}
		status_header( 200 );
		return $file;
	}

	/**
	 * Sert le document préparé, sans en-tête ni pied du thème.
	 */
	public static function serve_document() {
		$file = get_theme_file_path( 'apercu-tunnel.html' );
		if ( ! is_readable( $file ) ) {
			status_header( 500 );
			exit;
		}
		$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier du thème, pas une entrée utilisateur.
		if ( ! is_string( $html ) || '' === $html ) {
			status_header( 500 );
			exit;
		}
		$doc_lang = self::document_lang();
		$doc_dir  = 'ar' === $doc_lang ? 'rtl' : 'ltr';
		$pk_html  = preg_replace(
			'#<html\b[^>]*>#i',
			'<html lang="' . esc_attr( $doc_lang ) . '" dir="' . esc_attr( $doc_dir ) . '">',
			$html,
			1
		);
		if ( is_string( $pk_html ) ) {
			$html = $pk_html;
		}
		if ( in_array( $doc_lang, array( 'ar', 'en' ), true )
			&& class_exists( '\Partikulier\Core\Domain\I18n\I18nDomainLoader' ) ) {
			\Partikulier\Core\Domain\I18n\I18nDomainLoader::reload_for_slug( $doc_lang );
		}
		$home_url = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url( $doc_lang ) : home_url( '/' );
		$pk_logo  = esc_url( get_theme_file_uri( 'assets/img/pep-logo-white.png' ) );
		$pk_html  = preg_replace(
			'#(<a class="pk-form-logo"[^>]*>\s*<img\s+src=")data:image/[^"]+(")#',
			'$1' . $pk_logo . '$2',
			$html,
			1
		);
		if ( is_string( $pk_html ) ) {
			$html = $pk_html;
		}
		$pk_html = preg_replace(
			'#(<a class="(?:pk-form-logo|pk-submit-back)" href=")https://darkcyan-yak-151002\.hostingersite\.com/(")#',
			'$1' . esc_url( $home_url ) . '$2',
			$html
		);
		if ( is_string( $pk_html ) ) {
			$html = $pk_html;
		}
		$type_terms = array();
		if ( taxonomy_exists( PARTIKULIER_ESTATIK_TYPE_TAXONOMY ) ) {
			$terms = get_terms(
				array(
					'taxonomy'   => PARTIKULIER_ESTATIK_TYPE_TAXONOMY,
					'hide_empty' => false,
					'lang'       => 'all',
				)
			);
			if ( ! is_wp_error( $terms ) && is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					$slug = sanitize_title( (string) $term->slug );
					$tid  = (int) $term->term_id;
					if ( '' === $slug || ! $tid ) {
						continue;
					}
					$tl     = function_exists( 'pll_get_term_language' ) ? (string) pll_get_term_language( $tid, 'slug' ) : '';
					$target = ( function_exists( 'pll_get_term' ) && $doc_lang ) ? (int) pll_get_term( $tid, $doc_lang ) : 0;
					$val    = $target ? $target : $tid;
					if ( ! isset( $type_terms[ $slug ] ) || $tl === $doc_lang ) {
						$type_terms[ $slug ] = $val;
					}
					$norm_name = strtolower( remove_accents( (string) $term->name ) );
					if ( '' !== $norm_name && ( ! isset( $type_terms[ $norm_name ] ) || $tl === $doc_lang ) ) {
						$type_terms[ $norm_name ] = $val;
					}
				}
			}
		}
		$edit_payload = self::edit_payload_for_request();
		$pk_config = array(
			'ajaxUrl'        => admin_url( 'admin-ajax.php', is_ssl() ? 'https' : 'http' ),
			'submitNonce'    => wp_create_nonce( 'pk_submit_listing' ),
			'placesNonce'    => wp_create_nonce( 'pk_places' ),
			'language'       => $doc_lang,
			'homeUrl'        => $home_url,
			'idempotencyKey' => class_exists( 'Partikulier_Form' ) ? Partikulier_Form::idempotency_field_value() : bin2hex( random_bytes( 16 ) ),
			'typeTerms'      => $type_terms,
			'i18n'           => array(
				'publishing'     => __( 'Publication en cours…', 'partikulier' ),
				'requiredFields' => __( 'Veuillez vérifier les champs obligatoires.', 'partikulier' ),
				'serverError'    => __( 'Erreur serveur', 'partikulier' ),
				'saved'          => __( 'Annonce enregistrée !', 'partikulier' ),
				'retry'          => __( 'réessayez ou contactez-nous.', 'partikulier' ),
				'whatsappTitle'  => __( 'Une dernière étape : WhatsApp', 'partikulier' ),
				'whatsappIntro'  => __( 'Votre annonce est enregistrée, mais reste invisible tant que l’équipe n’a pas rapproché votre message WhatsApp.', 'partikulier' ),
				'whatsappCode'   => __( 'Code de validation :', 'partikulier' ),
				'whatsappOpen'   => __( 'Ouvrir WhatsApp et envoyer le message', 'partikulier' ),
				'whatsappNote'   => __( 'Conservez ce code. L’annonce sera publiée seulement après vérification manuelle du message par l’équipe pep.ma.', 'partikulier' ),
				'photoError'     => __( "photo(s) n'ont pas pu être ajoutées :", 'partikulier' ),
				'photoHint'      => __( 'Vous pourrez les ajouter depuis « Mes annonces » après validation.', 'partikulier' ),
			),
			'edit'           => $edit_payload,
		);
		$boot      = '<script>window.PK_FORCE_LANG=' . wp_json_encode( $doc_lang ) . ';window.pkConfig=' . wp_json_encode( $pk_config ) . ';</script>';
		/* Le tunnel est un document autonome servi sans wp_head() : le SEO
		 * du theme (Partikulier_SEO) ne s'y applique pas. On injecte donc
		 * ici la meta description, dans la langue du document — elle etait
		 * absente de /deposer/ (constat UAT 14/11/2026). */
		$pk_meta_descriptions = array(
			'fr' => 'Déposez gratuitement votre annonce immobilière entre particuliers en quelques étapes simples et vérifiées.',
			'en' => 'List your property for free between private individuals in a few simple, verified steps.',
			'ar' => 'انشر إعلانك العقاري مجانا بين الأفراد في خطوات بسيطة وموثقة.',
		);
		$pk_meta = '<meta name="description" content="' . esc_attr( $pk_meta_descriptions[ $doc_lang ] ?? $pk_meta_descriptions['fr'] ) . '">';
		if ( false === strpos( $html, 'name="robots"' ) ) {
			$html = preg_replace( '#<head>#', "<head>\n<meta name=\"robots\" content=\"noindex, nofollow\">\n" . $pk_meta . "\n" . $boot, $html, 1 );
		} else {
			$html = preg_replace( '#<head>#', '<head>' . $pk_meta . $boot, $html, 1 );
		}
		status_header( 200 );
		if ( ! headers_sent() ) {
			header( 'Content-Type: text/html; charset=UTF-8' );
			header( 'Cache-Control: private, no-store, max-age=0' );
			header( 'X-LiteSpeed-Cache-Control: no-cache' );
		}
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document HTML autonome du thème, déjà figé.
		exit;
	}

	/**
	 * Données d'une annonce à modifier, pour pré-remplir le tunnel.
	 *
	 * Sans annonce demandée, visiteur anonyme, annonce d'un autre type ou
	 * annonce d'autrui, on ne renvoie rien : le tunnel reste un dépôt vierge
	 * et aucune donnée privée ne part dans le document. Le téléphone n'est
	 * transmis qu'à l'auteur (ou à un administrateur), depuis le numéro
	 * nettoyé rattaché à son compte, jamais depuis le champ chiffré seul.
	 *
	 * @return array
	 */
	private static function edit_payload_for_request() {
		if ( ! is_user_logged_in() ) {
			return array();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- lecture seule, propriété vérifiée ci-dessous avant toute donnée.
		$edit_id = isset( $_GET['edit'] ) ? absint( wp_unslash( $_GET['edit'] ) ) : 0;
		if ( ! $edit_id ) {
			return array();
		}
		$post = get_post( $edit_id );
		if ( ! $post || PARTIKULIER_ESTATIK_POST_TYPE !== $post->post_type ) {
			return array();
		}
		if ( (int) get_current_user_id() !== (int) $post->post_author && ! current_user_can( 'manage_options' ) ) {
			return array();
		}

		$meta = static function ( $key ) use ( $post ) {
			return (string) get_post_meta( $post->ID, $key, true );
		};

		// Type de bien : on repasse du terme Estatik à la famille du tunnel.
		$famille   = '';
		$sous_type = '';
		$type_map  = array(
			'studio'             => array( 'appartement', 'studio' ),
			'appartement'        => array( 'appartement', 'appartement' ),
			'duplex'             => array( 'appartement', 'duplex' ),
			'triplex'            => array( 'appartement', 'triplex' ),
			'maison'             => array( 'maison', '' ),
			'villa'              => array( 'villa', '' ),
			'riad'               => array( 'riad', '' ),
			'local'              => array( 'local', '' ),
			'local-commercial'   => array( 'local', '' ),
			'commerce'           => array( 'local', '' ),
			'bureau'             => array( 'bureau', '' ),
			'depot'              => array( 'entrepot', 'depot' ),
			'hangar'             => array( 'entrepot', 'depot' ),
			'entrepot'           => array( 'entrepot', 'depot' ),
			'frigo'              => array( 'entrepot', 'frigo' ),
			'plateforme'         => array( 'entrepot', 'plateforme' ),
			'usine'              => array( 'batiment', 'usine' ),
			'atelier'            => array( 'batiment', 'atelier' ),
			'immeuble'           => array( 'immeuble', '' ),
			'terrain-villa'      => array( 'lot', 'villa' ),
			'terrain-immeuble'   => array( 'lot', 'immeuble' ),
			'terrain-commercial' => array( 'lot', 'commercial' ),
			'terrain-groupement' => array( 'lot', 'groupement' ),
			'terrain-industriel' => array( 'lot', 'industriel' ),
			'terrain-agricole'   => array( 'lot', 'agricole' ),
			'terrain'            => array( 'lot', '' ),
			'ferme'              => array( 'ferme', '' ),
		);
		$type_terms = wp_get_object_terms( $post->ID, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
		if ( ! is_wp_error( $type_terms ) && $type_terms ) {
			foreach ( $type_terms as $type_term ) {
				$candidats = array(
					sanitize_title( (string) $type_term->slug ),
					sanitize_title( remove_accents( (string) $type_term->name ) ),
				);
				foreach ( $candidats as $candidat ) {
					if ( isset( $type_map[ $candidat ] ) ) {
						list( $famille, $sous_type ) = $type_map[ $candidat ];
						break 2;
					}
				}
			}
		}

		// Transaction : vente par défaut, location ou saisonnier selon le statut.
		$transaction   = 'vente';
		$status_terms  = wp_get_object_terms( $post->ID, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
		$status_texte  = '';
		if ( ! is_wp_error( $status_terms ) && $status_terms ) {
			foreach ( $status_terms as $status_term ) {
				$status_texte .= ' ' . strtolower( remove_accents( (string) $status_term->name . ' ' . (string) $status_term->slug ) );
			}
		}
		if ( false !== strpos( $status_texte, 'saisonn' ) ) {
			$transaction = 'saisonnier';
		} elseif ( preg_match( '/louer|location|rent/', $status_texte ) || '' !== $meta( '_pk_rent' ) ) {
			$transaction = 'location';
		}

		// Photos déjà en ligne : la galerie, plus la vignette si elle en sort.
		$photos   = array();
		$thumb_id = (int) get_post_thumbnail_id( $post->ID );
		$gallery  = get_post_meta( $post->ID, 'es_property_gallery', true );
		if ( is_array( $gallery ) ) {
			foreach ( $gallery as $attachment_id ) {
				$attachment_id = (int) $attachment_id;
				if ( ! $attachment_id ) {
					continue;
				}
				$url = wp_get_attachment_image_url( $attachment_id, 'large' );
				if ( ! $url ) {
					$url = wp_get_attachment_url( $attachment_id );
				}
				if ( ! $url ) {
					continue;
				}
				$photos[] = array(
					'id'    => $attachment_id,
					'url'   => $url,
					'cover' => $attachment_id === $thumb_id,
				);
			}
		}
		if ( $thumb_id && ! in_array( $thumb_id, array_column( $photos, 'id' ), true ) ) {
			$thumb_url = wp_get_attachment_image_url( $thumb_id, 'large' );
			if ( ! $thumb_url ) {
				$thumb_url = wp_get_attachment_url( $thumb_id );
			}
			if ( $thumb_url ) {
				array_unshift(
					$photos,
					array(
						'id'    => $thumb_id,
						'url'   => $thumb_url,
						'cover' => true,
					)
				);
			}
		}

		// Téléphone : le numéro nettoyé du compte auteur d'abord (lisible par
		// l'auteur lui-même), le champ chiffré ensuite (administrateurs).
		$phone = (string) get_user_meta( (int) $post->post_author, '_pk_owner_phone_clean', true );
		if ( '' === $phone && class_exists( 'Partikulier_Crypto' ) && '' !== $meta( '_pk_owner_phone' ) ) {
			$phone = (string) Partikulier_Crypto::decrypt_phone( $meta( '_pk_owner_phone' ) );
		}
		$name = $meta( '_pk_owner_name' );
		if ( '' === $name ) {
			$author = get_user_by( 'id', (int) $post->post_author );
			$name   = $author ? (string) $author->display_name : '';
		}

		$bedrooms = $meta( '_pk_bedrooms_label' );
		if ( '' === $bedrooms ) {
			$bedrooms = $meta( 'es_property_bedrooms' );
		}
		$living = $meta( '_pk_living_rooms_label' );
		if ( '' === $living ) {
			$living = $meta( '_pk_living_rooms' );
		}
		$bathrooms = $meta( '_pk_bathrooms_label' );
		if ( '' === $bathrooms ) {
			$bathrooms = $meta( 'es_property_bathrooms' );
		}

		return array(
			'id'             => (int) $post->ID,
			'role'           => 'agent' === $meta( '_pk_owner_role' ) ? 'agent' : 'proprietaire',
			'transaction'    => $transaction,
			'famille'        => $famille,
			'sousType'       => $sous_type,
			'city'           => $meta( '_pk_city_name' ),
			'district'       => $meta( '_pk_district_name' ),
			'title'          => (string) $post->post_title,
			'description'    => html_entity_decode( wp_strip_all_tags( (string) $post->post_content ), ENT_QUOTES, 'UTF-8' ),
			'extra'          => $meta( '_pk_extra_note' ),
			'address'        => $meta( '_pk_address_note' ),
			'name'           => $name,
			'phone'          => $phone,
			'surface'        => $meta( 'es_property_area' ),
			'price'          => $meta( 'es_property_price' ),
			'bedrooms'       => $bedrooms,
			'livingRooms'    => $living,
			'bathrooms'      => $bathrooms,
			'floor'          => $meta( '_pk_floor' ),
			'garage'         => $meta( '_pk_garage' ),
			'elevator'       => $meta( '_pk_elevator' ),
			'terrace'        => $meta( '_pk_terrace' ),
			'terraceSurface' => $meta( '_pk_terrace_surface' ),
			'visAVis'        => $meta( '_pk_vis_a_vis' ),
			'sunshine'       => $meta( '_pk_sunshine' ),
			'furnished'      => $meta( '_pk_furnished' ),
			'photos'         => $photos,
		);
	}

	/**
	 * Ancien rendu dans le thème. La page publique n'utilise plus cette voie.
	 */
	public static function render() {
		$file = get_theme_file_path( 'apercu-tunnel.html' );
		if ( ! is_readable( $file ) ) {
			return;
		}
		$html = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- fichier du thème, pas une entrée utilisateur.
		if ( ! is_string( $html ) || '' === $html ) {
			return;
		}
		$css  = '';
		$body = $html;
		if ( preg_match( '#<style>(.*)</style>#s', $html, $style ) ) {
			$css = self::scope_css( $style[1] );
		}
		if ( preg_match( '#<body[^>]*>(.*)</body>#s', $html, $inner ) ) {
			$body = $inner[1];
		}
		$home = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' );
		$body = preg_replace(
			'#(<a class="pk-submit-back" href=")[^"]*(">)#',
			'$1' . esc_url( $home ) . '$2',
			$body,
			1
		);
		echo '<div class="pk-tunnel">';
		if ( '' !== $css ) {
			echo '<style>' . $css . '</style>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSS du thème, déjà figé.
		}
		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- document HTML autonome du thème, déjà figé.
		echo '</div>';
	}

	/**
	 * Limite les styles du tunnel à son bloc, pour laisser l'en-tête intact.
	 *
	 * @param string $css Feuille du tunnel.
	 * @return string
	 */
	private static function scope_css( $css ) {
		$css = preg_replace( '#/\\*.*?\\*/#s', '', (string) $css );
		return self::scope_block( $css, '.pk-tunnel' );
	}

	/**
	 * Préfixe les sélecteurs d'un bloc, y compris dans les media queries.
	 *
	 * @param string $css   Bloc CSS.
	 * @param string $scope Préfixe.
	 * @return string
	 */
	private static function scope_block( $css, $scope ) {
		$out = '';
		$len = strlen( $css );
		$i   = 0;
		while ( $i < $len ) {
			while ( $i < $len && ctype_space( $css[ $i ] ) ) {
				++$i;
			}
			if ( $i >= $len || '}' === $css[ $i ] ) {
				break;
			}
			$brace = strpos( $css, '{', $i );
			if ( false === $brace ) {
				break;
			}
			$prelude = trim( substr( $css, $i, $brace - $i ) );
			$depth   = 1;
			$j       = $brace + 1;
			while ( $j < $len && $depth ) {
				if ( '{' === $css[ $j ] ) {
					++$depth;
				} elseif ( '}' === $css[ $j ] ) {
					--$depth;
				}
				++$j;
			}
			$body = substr( $css, $brace + 1, $j - $brace - 2 );
			if ( 0 === strpos( $prelude, '@media' ) || 0 === strpos( $prelude, '@supports' ) ) {
				$out .= $prelude . '{' . self::scope_block( $body, $scope ) . '}';
			} elseif ( 0 === strpos( $prelude, '@' ) ) {
				$out .= $prelude . '{' . $body . '}';
			} else {
				$scoped = array();
				foreach ( explode( ',', $prelude ) as $sel ) {
					$sel = trim( $sel );
					if ( '' === $sel ) {
						continue;
					}
					if ( ':root' === $sel || 0 === strpos( $sel, ':root' ) || 'html' === $sel || 'body' === $sel ) {
						$scoped[] = $scope;
					} else {
						$scoped[] = $scope . ' ' . $sel;
					}
				}
				if ( $scoped ) {
					$out .= implode( ',', $scoped ) . '{' . $body . '}';
				}
			}
			$i = $j;
		}
		return $out;
	}
}

Partikulier_Tunnel_Preview::init();
