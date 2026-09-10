<?php
/**
 * Module : diagnostic page par page.
 *
 * On saisit le nom (ou l'URL) d'une page du site, et l'ecran liste tout ce
 * qui cloche dessus : gabarit manquant, page absente, SEO incomplet,
 * traductions manquantes, images sans texte alternatif, liens casses...
 *
 * L'objectif est de repondre a « cette page ne marche pas » sans avoir a
 * ouvrir le code.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Page_Doctor {

	const MENU_SLUG = 'pk-page-doctor';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ), 30 );
	}

	public static function add_menu() {
		add_submenu_page(
			'partikulier',
			__( 'Diagnostic des pages', 'partikulier' ),
			__( 'Diagnostic des pages', 'partikulier' ),
			'manage_options',
			self::MENU_SLUG,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Pages connues du thème : nom lisible => informations de controle.
	 *
	 * @return array
	 */
	public static function known_pages() {
		return array(
			'accueil' => array(
				'label' => __( 'Accueil', 'partikulier' ),
				'url'   => home_url( '/' ),
				'type'  => 'front',
			),
			'annonces' => array(
				'label' => __( 'Toutes les annonces', 'partikulier' ),
				'url'   => get_post_type_archive_link( PARTIKULIER_ESTATIK_POST_TYPE ),
				'type'  => 'archive',
			),
			'deposer' => array(
				'label'    => __( 'Déposer une annonce', 'partikulier' ),
				'slugs'    => array( 'deposer-une-annonce', 'deposer-annonce', 'deposer', 'publier-une-annonce' ),
				'template' => 'templates/page-deposer-annonce.php',
				'type'     => 'page',
			),
			'mes-annonces' => array(
				'label'    => __( 'Mes annonces', 'partikulier' ),
				'slugs'    => array( 'mes-annonces', 'mon-espace', 'dashboard', 'tableau-de-bord' ),
				'template' => 'templates/page-mes-annonces.php',
				'type'     => 'page',
			),
			'favoris' => array(
				'label'    => __( 'Favoris', 'partikulier' ),
				'slugs'    => array( 'favoris', 'mes-favoris' ),
				'template' => 'templates/page-favoris.php',
				'type'     => 'page',
			),
			'annonce' => array(
				'label' => __( 'Fiche annonce (la plus récente)', 'partikulier' ),
				'type'  => 'single',
			),
		);
	}

	/**
	 * Analyse une entree utilisateur et renvoie la liste des constats.
	 *
	 * @param string $query Nom de page saisi.
	 * @return array
	 */
	public static function diagnose( $query ) {
		$query = trim( (string) $query );
		if ( '' === $query ) {
			return array();
		}

		$key    = self::match_key( $query );
		$pages  = self::known_pages();
		$report = array();

		if ( ! $key ) {
			// Recherche libre : peut-etre une page WordPress quelconque.
			$page = get_page_by_path( sanitize_title( $query ), OBJECT, 'page' );
			if ( ! $page ) {
				$found = get_posts( array(
					'post_type'      => 'page',
					'posts_per_page' => 1,
					's'              => $query,
					'lang'           => '',
				) );
				$page = $found ? $found[0] : null;
			}
			if ( ! $page ) {
				return array(
					array( 'ko', sprintf( __( 'Aucune page trouvée pour « %s ».', 'partikulier' ), $query ), __( 'Essayez : accueil, annonces, déposer, mes annonces, annonce.', 'partikulier' ) ),
				);
			}

			return self::diagnose_post( $page );
		}

		$info = $pages[ $key ];

		switch ( $info['type'] ) {
			case 'front':
				$report = self::diagnose_front();
				break;
			case 'archive':
				$report = self::diagnose_archive();
				break;
			case 'single':
				$report = self::diagnose_single();
				break;
			default:
				$report = self::diagnose_theme_page( $info );
		}

		return $report;
	}

	/**
	 * Fait correspondre une saisie libre a une page connue.
	 *
	 * @param string $query Saisie.
	 * @return string Cle, ou chaine vide.
	 */
	private static function match_key( $query ) {
		$needle = remove_accents( function_exists( 'mb_strtolower' ) ? mb_strtolower( $query ) : strtolower( $query ) );
		$needle = trim( str_replace( array( '/', '_' ), array( '', '-' ), $needle ) );

		$aliases = array(
			'accueil'      => array( 'accueil', 'home', 'page d accueil', 'front', 'index' ),
			'annonces'     => array( 'annonces', 'toutes les annonces', 'archive', 'catalogue', 'property', 'properties', 'liste' ),
			'deposer'      => array( 'deposer', 'deposer une annonce', 'deposer-une-annonce', 'publier', 'publier une annonce', 'depot' ),
			'mes-annonces' => array( 'mes annonces', 'mes-annonces', 'mon espace', 'dashboard', 'tableau de bord', 'espace' ),
			'favoris'      => array( 'favoris', 'mes favoris', 'wishlist', 'coeur' ),
			'annonce'      => array( 'annonce', 'fiche', 'fiche annonce', 'single', 'bien', 'propriete' ),
		);

		foreach ( $aliases as $key => $words ) {
			foreach ( $words as $word ) {
				if ( $needle === $word || false !== strpos( $needle, $word ) ) {
					return $key;
				}
			}
		}

		return '';
	}

	/* ------------------------------------------------------------------ */
	/* Diagnostics                                                         */
	/* ------------------------------------------------------------------ */

	/**
	 * Page du thème (déposer, mes annonces).
	 *
	 * @param array $info Definition.
	 * @return array
	 */
	private static function diagnose_theme_page( $info ) {
		$out  = array();
		$page = null;

		foreach ( $info['slugs'] as $slug ) {
			$found = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $found ) {
				$page = $found;
				break;
			}
		}

		if ( ! $page ) {
			$out[] = array( 'ko', __( 'La page n’existe pas.', 'partikulier' ), __( 'Allez dans Partikulier › Mise à niveau, ou cliquez sur « Créer les pages manquantes » dans l’alerte d’administration.', 'partikulier' ) );

			return $out;
		}

		$out[] = array( 'ok', sprintf( __( 'Page trouvée : « %1$s » (%2$s).', 'partikulier' ), $page->post_title, $page->post_name ), get_permalink( $page ) );

		if ( 'publish' !== $page->post_status ) {
			$out[] = array( 'ko', sprintf( __( 'Statut « %s » : la page n’est pas visible du public.', 'partikulier' ), $page->post_status ), __( 'Ouvrez la page et cliquez sur Publier.', 'partikulier' ) );
		} else {
			$out[] = array( 'ok', __( 'Page publiée.', 'partikulier' ), '' );
		}

		$assigned = get_page_template_slug( $page->ID );
		if ( $assigned === $info['template'] ) {
			$out[] = array( 'ok', __( 'Modèle de page correctement assigné.', 'partikulier' ), $info['template'] );
		} elseif ( in_array( $page->post_name, $info['slugs'], true ) ) {
			$out[] = array( 'warn', __( 'Aucun modèle assigné, mais le slug est reconnu : le thème charge quand même le bon gabarit.', 'partikulier' ), __( 'Pour plus de sûreté : Attributs de page › Modèle.', 'partikulier' ) );
		} else {
			$out[] = array( 'ko', __( 'Le modèle de page n’est pas assigné.', 'partikulier' ), __( 'Ouvrez la page › Attributs de page › Modèle › choisissez le bon gabarit.', 'partikulier' ) );
		}

		if ( ! file_exists( get_theme_file_path( $info['template'] ) ) ) {
			$out[] = array( 'ko', __( 'Le fichier du gabarit est absent du thème.', 'partikulier' ), $info['template'] );
		}

		// Cas particulier du depot : la validation WhatsApp est bloquante.
		if ( 'templates/page-deposer-annonce.php' === $info['template'] ) {
			/* La notice nomme le champ lu et la valeur trouvee : « absent » ne doit plus
			   pouvoir vouloir dire « present, mais lu au mauvais endroit ».
			   (C etait le cas : le Personnalisateur ecrivait dans loption pk_opts.) */
			$pk_brut     = Partikulier_Settings::get( 'whatsapp_validation_number' );
			$pk_chiffres = preg_replace( '/[^0-9]/', '', (string) $pk_brut );
			$pk_trace    = Partikulier_Settings::has_legacy( 'whatsapp_validation_number' )
				? ' | valeur trouvee dans loption heritee pk_opts, en cours de reprise'
				: '';
			if ( class_exists( 'Partikulier_WhatsApp_Verification' ) && Partikulier_WhatsApp_Verification::is_configured() ) {
				$out[] = array( 'ok', __( 'Numéro WhatsApp de validation renseigné.', 'partikulier' ), 'pk_theme_options[whatsapp_validation_number] — ' . strlen( $pk_chiffres ) . ' chiffres' );
			} else {
				$out[] = array( 'ko', __( 'Numéro WhatsApp absent : aucune annonce ne peut être publiée.', 'partikulier' ), 'Champ lu : « Numéro WhatsApp de validation » (PAS celui des demandes acquéreurs). Valeur : ' . ( '' === $pk_brut ? 'vide' : strlen( $pk_chiffres ) . ' chiffres' ) . $pk_trace . '. Saisie : Apparence > Personnaliser > Validation WhatsApp, puis Publier.' );
			}

			$types = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY, 'hide_empty' => false ) );
			if ( is_wp_error( $types ) || ! $types ) {
				$out[] = array( 'ko', __( 'Aucun type de bien : le menu déroulant sera vide.', 'partikulier' ), __( 'Créez des termes dans la taxonomie des types.', 'partikulier' ) );
			} else {
				$out[] = array( 'ok', sprintf( __( '%d types de biens disponibles.', 'partikulier' ), count( $types ) ), '' );
			}

			$upload = wp_max_upload_size();
			$out[]  = array(
				$upload < 2 * MB_IN_BYTES ? 'warn' : 'ok',
				sprintf( __( 'Taille maximale d’envoi : %s.', 'partikulier' ), size_format( $upload ) ),
				$upload < 2 * MB_IN_BYTES ? __( 'Les photos de téléphone dépassent souvent cette limite. Demandez à votre hébergeur d’augmenter upload_max_filesize.', 'partikulier' ) : ''
			);
		}

		$out = array_merge( $out, self::diagnose_translations( $page->ID ) );

		return $out;
	}

	/**
	 * Accueil.
	 *
	 * @return array
	 */
	private static function diagnose_front() {
		$out = array();

		$out[] = array( 'ok', __( 'Page d’accueil servie par le thème.', 'partikulier' ), home_url( '/' ) );

		$count = wp_count_posts( PARTIKULIER_ESTATIK_POST_TYPE );
		$pub   = isset( $count->publish ) ? (int) $count->publish : 0;
		if ( $pub ) {
			$out[] = array( 'ok', sprintf( __( '%d annonces publiées.', 'partikulier' ), $pub ), '' );
		} else {
			$out[] = array( 'ko', __( 'Aucune annonce publiée : l’accueil sera vide.', 'partikulier' ), '' );
		}

		// Le formulaire de recherche depend de la taxonomie des actions.
		$actions = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_STATUS_TAXONOMY, 'hide_empty' => false ) );
		$bad     = class_exists( 'Partikulier_Upgrade_Wizard' ) ? Partikulier_Upgrade_Wizard::misplaced_terms() : array();
		if ( $bad ) {
			$names = wp_list_pluck( array_slice( $bad, 0, 6 ), 'name' );
			$out[] = array( 'ko', sprintf( __( '%d termes parasites dans « Achat ou location » : le menu affichera des villes.', 'partikulier' ), count( $bad ) ), implode( ' · ', $names ) );
		} elseif ( ! is_wp_error( $actions ) && $actions ) {
			$out[] = array( 'ok', __( 'Menu « Achat ou location » propre.', 'partikulier' ), '' );
		}

		return $out;
	}

	/**
	 * Archive des annonces.
	 *
	 * @return array
	 */
	private static function diagnose_archive() {
		$out = array();
		$url = get_post_type_archive_link( PARTIKULIER_ESTATIK_POST_TYPE );

		if ( $url ) {
			$out[] = array( 'ok', __( 'Archive des annonces accessible.', 'partikulier' ), $url );
		} else {
			$out[] = array( 'ko', __( 'Aucune archive déclarée pour le type de contenu des annonces.', 'partikulier' ), '' );
		}

		$count = wp_count_posts( PARTIKULIER_ESTATIK_POST_TYPE );
		$pub   = isset( $count->publish ) ? (int) $count->publish : 0;
		$pend  = isset( $count->pending ) ? (int) $count->pending : 0;

		$out[] = array( 'ok', sprintf( __( '%1$d publiées, %2$d en attente.', 'partikulier' ), $pub, $pend ), '' );

		if ( $pend ) {
			$out[] = array( 'warn', sprintf( __( '%d annonces attendent une validation.', 'partikulier' ), $pend ), __( 'Annonces › filtrer sur « En attente ».', 'partikulier' ) );
		}

		$per_page = (int) get_option( 'posts_per_page' );
		$out[]    = array( 'ok', sprintf( __( 'Réglage WordPress : %d éléments par page.', 'partikulier' ), $per_page ), __( 'Estatik peut imposer sa propre valeur.', 'partikulier' ) );

		return $out;
	}

	/**
	 * Fiche annonce la plus recente.
	 *
	 * @return array
	 */
	private static function diagnose_single() {
		$posts = get_posts( array(
			'post_type'      => PARTIKULIER_ESTATIK_POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 1,
			'orderby'        => 'date',
			'order'          => 'DESC',
			'lang'           => '',
		) );

		if ( ! $posts ) {
			return array( array( 'ko', __( 'Aucune annonce publiée à analyser.', 'partikulier' ), '' ) );
		}

		return self::diagnose_post( $posts[0] );
	}

	/**
	 * Analyse detaillee d'un contenu.
	 *
	 * @param WP_Post $post Contenu.
	 * @return array
	 */
	private static function diagnose_post( $post ) {
		$out = array();

		$out[] = array( 'ok', sprintf( __( 'Contenu analysé : « %s ».', 'partikulier' ), $post->post_title ), get_permalink( $post ) );

		if ( 'publish' !== $post->post_status ) {
			$out[] = array( 'warn', sprintf( __( 'Statut « %s ».', 'partikulier' ), $post->post_status ), '' );
		}

		// SEO.
		$meta = get_post_meta( $post->ID, '_pk_meta_description', true );
		if ( $meta ) {
			$len   = mb_strlen( $meta );
			$state = ( $len >= 70 && $len <= 158 ) ? 'ok' : 'warn';
			$out[] = array( $state, sprintf( __( 'Meta description : %d caractères.', 'partikulier' ), $len ), $meta );
		} else {
			$out[] = array( 'warn', __( 'Pas de meta description dédiée.', 'partikulier' ), __( 'Elle est générée automatiquement pour les annonces déposées depuis la v6.6.', 'partikulier' ) );
		}

		if ( mb_strlen( wp_strip_all_tags( $post->post_content ) ) < 120 ) {
			$out[] = array( 'warn', __( 'Description très courte : peu de matière pour Google.', 'partikulier' ), '' );
		}

		// Images.
		if ( PARTIKULIER_ESTATIK_POST_TYPE === $post->post_type ) {
			$gallery = get_post_meta( $post->ID, 'es_property_gallery', true );
			$gallery = is_array( $gallery ) ? $gallery : array();
			$thumb   = get_post_thumbnail_id( $post->ID );

			if ( ! $gallery && ! $thumb ) {
				$out[] = array( 'ko', __( 'Aucune photo : la carte affichera un visuel vide.', 'partikulier' ), '' );
			} else {
				$out[] = array( 'ok', sprintf( __( '%d photo(s) rattachée(s).', 'partikulier' ), max( count( $gallery ), $thumb ? 1 : 0 ) ), '' );

				$missing_alt = 0;
				foreach ( array_merge( $gallery, $thumb ? array( $thumb ) : array() ) as $img ) {
					if ( ! get_post_meta( $img, '_wp_attachment_image_alt', true ) ) {
						$missing_alt++;
					}
				}
				if ( $missing_alt ) {
					$out[] = array( 'warn', sprintf( __( '%d image(s) sans texte alternatif.', 'partikulier' ), $missing_alt ), __( 'Pénalise le référencement Google Images.', 'partikulier' ) );
				}
			}

			// Taxonomies indispensables.
			$action = wp_get_object_terms( $post->ID, PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY );
			if ( is_wp_error( $action ) || ! $action ) {
				$out[] = array( 'ko', __( 'Aucune action « À vendre » / « À louer ».', 'partikulier' ), __( 'Pas de badge, et le filtre ne trouvera pas cette annonce.', 'partikulier' ) );
			} else {
				$out[] = array( 'ok', sprintf( __( 'Action : %s.', 'partikulier' ), $action[0]->name ), '' );
			}

			$place = wp_get_object_terms( $post->ID, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
			if ( is_wp_error( $place ) || ! $place ) {
				$out[] = array( 'ko', __( 'Aucune ville rattachée.', 'partikulier' ), __( 'Le référencement local est fortement pénalisé.', 'partikulier' ) );
			} else {
				$out[] = array( 'ok', sprintf( __( 'Lieu : %s.', 'partikulier' ), implode( ', ', wp_list_pluck( $place, 'name' ) ) ), '' );
			}

			if ( ! get_post_meta( $post->ID, 'es_property_price', true ) ) {
				$out[] = array( 'warn', __( 'Aucun prix enregistré.', 'partikulier' ), '' );
			}
		}

		$out = array_merge( $out, self::diagnose_translations( $post->ID ) );

		return $out;
	}

	/**
	 * Etat des traductions d'un contenu.
	 *
	 * @param int $post_id Contenu.
	 * @return array
	 */
	private static function diagnose_translations( $post_id ) {
		if ( ! function_exists( 'pll_get_post_translations' ) ) {
			return array();
		}

		$languages = function_exists( 'pll_languages_list' ) ? (array) pll_languages_list( array( 'fields' => 'slug' ) ) : array();
		if ( count( $languages ) < 2 ) {
			return array();
		}

		$translations = pll_get_post_translations( $post_id );
		$missing      = array();
		foreach ( $languages as $lang ) {
			if ( empty( $translations[ $lang ] ) || ! get_post( $translations[ $lang ] ) ) {
				$missing[] = $lang;
			}
		}

		if ( $missing ) {
			return array( array( 'warn', sprintf( __( 'Traductions manquantes : %s.', 'partikulier' ), strtoupper( implode( ', ', $missing ) ) ), __( 'Partikulier › Mise à niveau › étape 3.', 'partikulier' ) ) );
		}

		return array( array( 'ok', sprintf( __( 'Disponible dans %d langues.', 'partikulier' ), count( $languages ) ), '' ) );
	}

	/* ------------------------------------------------------------------ */
	/* Rendu                                                               */
	/* ------------------------------------------------------------------ */

	/**
	 * Analyse toutes les pages connues et presente une synthese.
	 */
	public static function render_full_scan() {
		$rows = array();
		foreach ( self::known_pages() as $key => $info ) {
			$report = self::diagnose( $key );
			$ko     = 0;
			$warn   = 0;
			$first  = '';
			foreach ( $report as $line ) {
				if ( 'ko' === $line[0] ) {
					$ko++;
					if ( '' === $first ) {
						$first = $line[1];
					}
				} elseif ( 'warn' === $line[0] ) {
					$warn++;
					if ( '' === $first ) {
						$first = $line[1];
					}
				}
			}
			$rows[] = array(
				'key'   => $key,
				'label' => $info['label'],
				'ko'    => $ko,
				'warn'  => $warn,
				'first' => $first,
			);
		}

		$total_ko   = array_sum( wp_list_pluck( $rows, 'ko' ) );
		$total_warn = array_sum( wp_list_pluck( $rows, 'warn' ) );
		?>
		<div style="background:<?php echo $total_ko ? '#fcf0ef' : ( $total_warn ? '#fff8e5' : '#edfaef' ); ?>;border-left:4px solid <?php echo $total_ko ? '#d63638' : ( $total_warn ? '#dba617' : '#00a32a' ); ?>;padding:12px 16px;max-width:52em;margin-bottom:16px">
			<strong>
			<?php
			if ( $total_ko ) {
				printf( esc_html__( '%1$d problème(s) bloquant(s) et %2$d avertissement(s) sur l’ensemble du site.', 'partikulier' ), (int) $total_ko, (int) $total_warn );
			} elseif ( $total_warn ) {
				printf( esc_html__( 'Aucun blocage, %d point(s) à améliorer.', 'partikulier' ), (int) $total_warn );
			} else {
				esc_html_e( 'Tout le site est au vert.', 'partikulier' );
			}
			?>
			</strong>
		</div>

		<table class="widefat striped" style="max-width:52em">
			<thead>
				<tr>
					<th style="width:30px"></th>
					<th><?php esc_html_e( 'Page', 'partikulier' ); ?></th>
					<th style="width:190px"><?php esc_html_e( 'État', 'partikulier' ); ?></th>
					<th style="width:110px"></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rows as $row ) :
				$icon  = $row['ko'] ? '✕' : ( $row['warn'] ? '!' : '✓' );
				$color = $row['ko'] ? '#d63638' : ( $row['warn'] ? '#dba617' : '#00a32a' );
				?>
				<tr>
					<td style="color:<?php echo esc_attr( $color ); ?>;font-weight:700;font-size:15px"><?php echo esc_html( $icon ); ?></td>
					<td>
						<strong><?php echo esc_html( $row['label'] ); ?></strong>
						<?php if ( $row['first'] ) : ?>
							<br><span style="color:#646970;font-size:12px"><?php echo esc_html( $row['first'] ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $row['ko'] ) : ?>
							<span style="color:#d63638"><?php printf( esc_html__( '%d bloquant(s)', 'partikulier' ), (int) $row['ko'] ); ?></span>
						<?php endif; ?>
						<?php if ( $row['warn'] ) : ?>
							<span style="color:#dba617"><?php printf( esc_html__( '%d à améliorer', 'partikulier' ), (int) $row['warn'] ); ?></span>
						<?php endif; ?>
						<?php if ( ! $row['ko'] && ! $row['warn'] ) : ?>
							<span style="color:#00a32a"><?php esc_html_e( 'Conforme', 'partikulier' ); ?></span>
						<?php endif; ?>
					</td>
					<td>
						<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&pk_page=' . rawurlencode( $row['key'] ) ) ); ?>"><?php esc_html_e( 'Détail', 'partikulier' ); ?></a>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'partikulier' ), '', array( 'response' => 403 ) );
		}

		$query  = isset( $_GET['pk_page'] ) ? sanitize_text_field( wp_unslash( $_GET['pk_page'] ) ) : '';
		$report = ( $query && 'tout' !== $query ) ? self::diagnose( $query ) : array();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Diagnostic des pages', 'partikulier' ); ?></h1>
			<p class="description" style="max-width:44em">
				<?php esc_html_e( 'Saisissez le nom d’une page : le thème vérifie son existence, son gabarit, son référencement, ses images et ses traductions.', 'partikulier' ); ?>
			</p>

			<form method="get" style="margin:18px 0">
				<input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>">
				<input type="text" name="pk_page" value="<?php echo esc_attr( $query ); ?>"
					class="regular-text" list="pk-page-list"
					placeholder="<?php esc_attr_e( 'accueil, annonces, déposer, mes annonces, annonce…', 'partikulier' ); ?>">
				<datalist id="pk-page-list">
					<?php foreach ( self::known_pages() as $key => $info ) : ?>
						<option value="<?php echo esc_attr( $info['label'] ); ?>"></option>
					<?php endforeach; ?>
				</datalist>
				<button class="button button-primary"><?php esc_html_e( 'Analyser', 'partikulier' ); ?></button>
			</form>

			<p style="margin:-8px 0 18px">
				<a class="button button-primary button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&pk_page=tout' ) ); ?>"><?php esc_html_e( 'Analyser tout le site', 'partikulier' ); ?></a>
				<?php foreach ( self::known_pages() as $key => $info ) : ?>
					<a class="button button-small" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&pk_page=' . rawurlencode( $key ) ) ); ?>"><?php echo esc_html( $info['label'] ); ?></a>
				<?php endforeach; ?>
			</p>

			<?php if ( 'tout' === $query ) : ?>
				<?php self::render_full_scan(); ?>
			<?php endif; ?>

			<?php if ( $report ) : ?>
				<?php
				$ko   = count( array_filter( $report, static function ( $r ) { return 'ko' === $r[0]; } ) );
				$warn = count( array_filter( $report, static function ( $r ) { return 'warn' === $r[0]; } ) );
				?>
				<div style="background:<?php echo $ko ? '#fcf0ef' : ( $warn ? '#fff8e5' : '#edfaef' ); ?>;border-left:4px solid <?php echo $ko ? '#d63638' : ( $warn ? '#dba617' : '#00a32a' ); ?>;padding:12px 16px;max-width:52em;margin-bottom:16px">
					<strong>
						<?php if ( $ko ) : ?>
							<?php printf( esc_html__( '%d problème(s) bloquant(s) détecté(s).', 'partikulier' ), (int) $ko ); ?>
						<?php elseif ( $warn ) : ?>
							<?php printf( esc_html__( '%d point(s) à améliorer.', 'partikulier' ), (int) $warn ); ?>
						<?php else : ?>
							<?php esc_html_e( 'Aucun problème détecté sur cette page.', 'partikulier' ); ?>
						<?php endif; ?>
					</strong>
				</div>

				<table class="widefat striped" style="max-width:52em">
					<tbody>
					<?php foreach ( $report as $row ) :
						$icon  = 'ok' === $row[0] ? '✓' : ( 'warn' === $row[0] ? '!' : '✕' );
						$color = 'ok' === $row[0] ? '#00a32a' : ( 'warn' === $row[0] ? '#dba617' : '#d63638' );
						?>
						<tr>
							<td style="width:28px;color:<?php echo esc_attr( $color ); ?>;font-weight:700;font-size:15px"><?php echo esc_html( $icon ); ?></td>
							<td>
								<?php echo esc_html( $row[1] ); ?>
								<?php if ( ! empty( $row[2] ) ) : ?>
									<br><span style="color:#646970;font-size:12px"><?php echo esc_html( $row[2] ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}

Partikulier_Page_Doctor::init();
