<?php
/**
 * Installateur de démo : 30 annonces avec vraies photos, visibles immédiatement.
 *
 * But : permettre de voir TOUTES les erreurs d'affichage d'un coup :
 *   - cartes d'annonces (accueil, /annonces/, villes)
 *   - fiche bien (galerie, métriques, contact WhatsApp)
 *   - filtres (ville, type, transaction)
 *   - traduction FR/EN/AR + hreflang
 *   - sans passer par le formulaire ni la validation WhatsApp manuelle
 *
 * Le jeu est basé sur tests/seed-annonces-test.php (même pipeline que
 * class-form.php) mais avec de VRAIES photos du dossier demo/images/ et une
 * UI 1-clic dans l'admin (Outils > Démo Partikulier).
 *
 * - 30 annonces : 6 villes — 8 types — 15 ventes / 15 locations
 * - 3 photos par annonce, piochées dans demo/images/ (30 AVIF fournis)
 * - Titres/descriptions générés par Partikulier_Listing_I18n (comme un vrai dépôt)
 * - _pk_status = actif + Polylang + permaliens purgés → visibles dès l'install
 * - Marque _pk_seed_demo = 1 sur chaque post + attachment → purge propre en 1 clic
 * - Si Polylang FR/EN/AR est configuré, chaque annonce est traduite automatiquement
 *   (EN + AR via Partikulier_Listing_Translations::sync) → 30 FR + 30 EN + 30 AR = 90 posts
 *   Sinon, seules 30 annonces FR sont créées.
 *
 * Accès : administrateurs uniquement, nonce, pas de CLI requis.
 * Usage : Apparence > Démo Partikulier ou Outils > Démo Partikulier
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Demo_Installer {

	const MARK  = '_pk_seed_demo';
	const NONCE = 'pk_demo_install';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_pk_demo_install', array( __CLASS__, 'handle_install' ) );
		add_action( 'admin_post_pk_demo_purge', array( __CLASS__, 'handle_purge' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
	}

	public static function menu() {
		add_management_page(
			__( 'Démo Partikulier', 'partikulier' ),
			__( 'Démo Partikulier', 'partikulier' ),
			'manage_options',
			'pk-demo',
			array( __CLASS__, 'page' )
		);
		add_theme_page(
			__( 'Démo Partikulier', 'partikulier' ),
			__( 'Démo Partikulier', 'partikulier' ),
			'manage_options',
			'pk-demo',
			array( __CLASS__, 'page' )
		);
	}

	public static function notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$msg = get_transient( 'pk_demo_msg_' . get_current_user_id() );
		if ( ! $msg ) {
			return;
		}
		delete_transient( 'pk_demo_msg_' . get_current_user_id() );
		$class = ( false !== strpos( $msg, 'ERREUR' ) || false !== strpos( $msg, 'ÉCHEC' ) ) ? 'notice-error' : 'notice-success';
		printf( '<div class="notice %s is-dismissible"><p>%s</p></div>', esc_attr( $class ), wp_kses_post( nl2br( esc_html( $msg ) ) ) );
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'partikulier' ) );
		}
		$demo_count = count( get_posts( array(
			'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_key'         => self::MARK,
			'meta_value'       => '1',
			'lang'            => '',
			'suppress_filters' => true,
		) ) );
		$media_count = count( get_posts( array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_key'         => self::MARK,
			'meta_value'       => '1',
			'lang'            => '',
			'suppress_filters' => true,
		) ) );
		$has_estatik = class_exists( 'Estatik' ) || class_exists( 'Es_Main_Class' ) || defined( 'ES_VERSION' ) || function_exists( 'es_get_properties' );
		$has_i18n    = class_exists( 'Partikulier_Listing_I18n' ) && class_exists( 'Partikulier_Listing_Preview' );
		$has_translations = class_exists( 'Partikulier_Listing_Translations' ) && method_exists( 'Partikulier_Listing_Translations', 'available' ) && Partikulier_Listing_Translations::available();
		$demo_dir = PARTIKULIER_DIR . '/demo/images';
		$demo_images = is_dir( $demo_dir ) ? count( glob( $demo_dir . '/*.{jpg,jpeg,avif,webp}', GLOB_BRACE ) ) : 0;
		$languages = $has_translations ? Partikulier_Listing_Translations::active_languages() : array( 'fr' );
		?>
		<div class="wrap pk-demo-wrap" style="max-width:900px">
			<h1><?php esc_html_e( 'Démo Partikulier — 30 annonces avec photos', 'partikulier' ); ?></h1>
			<p style="font-size:14px;color:#444">
				<?php esc_html_e( 'Installe en 1 clic 30 annonces publiées avec vraies photos (15 ventes + 15 locations), visibles immédiatement sur l’accueil, /annonces/ et les fiches bien. Idéal pour repérer toutes les erreurs d’affichage sans passer par le formulaire.', 'partikulier' ); ?>
			</p>

			<div style="background:#fff;border:1px solid #ccd0d4;border-left:4px solid #2271b1;padding:16px 20px;margin:16px 0">
				<h2 style="margin:0 0 8px"><?php esc_html_e( 'État actuel', 'partikulier' ); ?></h2>
				<p style="margin:0">
					<?php printf( esc_html__( 'Annonces de démo : %d — Photos de démo : %d', 'partikulier' ), (int) $demo_count, (int) $media_count ); ?><br>
					<?php esc_html_e( 'Dossier demo/images :', 'partikulier' ); ?> <?php echo (int) $demo_images; ?> AVIF / JPG / WebP<br>
					Estatik : <?php echo $has_estatik ? '✅ actif' : '❌ manquant — installez/activiez Estatik d’abord'; ?><br>
					Moteur Partikulier : <?php echo $has_i18n ? '✅' : '❌ thème incomplet'; ?><br>
					Polylang : <?php echo $has_translations ? '✅ ' . esc_html( implode( '/', $languages ) ) . ' — chaque annonce sera traduite en ' . esc_html( implode( ' + ', $languages ) ) : '⚠️ FR seul (sans Polylang, pas de traductions EN/AR)'; ?>
				</p>
				<?php if ( $has_translations && count( $languages ) >= 2 ) : ?>
				<p style="margin:8px 0 0;font-size:13px;color:#50575e">
					<?php esc_html_e( 'Avec Polylang, 30 annonces FR génèrent automatiquement 30 EN + 30 AR = 90 fiches au total, avec hreflang et termes traduits.', 'partikulier' ); ?>
				</p>
				<?php else : ?>
				<p style="margin:8px 0 0;font-size:13px;color:#50575e">
					<?php esc_html_e( 'Sans Polylang, seules 30 annonces FR seront créées. Activez Polylang FR/EN/AR avant l’installation pour avoir les 3 langues.', 'partikulier' ); ?>
				</p>
				<?php endif; ?>
			</div>

			<div style="display:flex;gap:12px;flex-wrap:wrap;margin:16px 0">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="pk_demo_install">
					<?php submit_button( $demo_count ? __( 'Réinstaller la démo (purge + 30 annonces)', 'partikulier' ) : __( 'Installer la démo (30 annonces + photos)', 'partikulier' ), 'primary', 'submit', false, $has_estatik && $has_i18n ? array() : array( 'disabled' => 'disabled' ) ); ?>
				</form>
				<?php if ( $demo_count || $media_count ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('Supprimer toutes les annonces et photos de démo ?');">
					<?php wp_nonce_field( self::NONCE ); ?>
					<input type="hidden" name="action" value="pk_demo_purge">
					<?php submit_button( __( 'Purger la démo', 'partikulier' ), 'delete', 'submit', false ); ?>
				</form>
				<?php endif; ?>
			</div>

			<?php if ( $demo_count ) : ?>
			<h2><?php esc_html_e( 'Vérifications rapides', 'partikulier' ); ?></h2>
			<ul style="list-style:disc;margin-left:20px">
				<li><a href="<?php echo esc_url( home_url( '/' ) ); ?>" target="_blank"><?php esc_html_e( 'Accueil — cartes d’annonces + villes', 'partikulier' ); ?></a></li>
				<li><a href="<?php echo esc_url( pk_properties_archive_url() ); ?>" target="_blank"><?php esc_html_e( 'Catalogue /annonces/', 'partikulier' ); ?></a> — filtres : <code>?es_city=casablanca</code> · <code>?es_type=villa</code> · <code>?es_action=a-louer</code> (location) vs <code>?es_action=a-vendre</code> (vente)</li>
				<li><?php esc_html_e( 'Fiches bien : cliquez une carte → galerie (flèches + compteur), métriques, bloc WhatsApp', 'partikulier' ); ?></li>
			</ul>
			<p><strong>Contenu installé :</strong> 30 annonces — 6 villes — 8 types (appartement, villa, studio, maison, duplex, riad, terrain, immeuble) — 15 ventes / 15 locations — chaque annonce a 3 photos du dossier demo.</p>
			<?php endif; ?>

			<details style="margin-top:20px;background:#f6f7f7;padding:12px 16px;border:1px solid #dcdcde">
				<summary style="cursor:pointer;font-weight:600"><?php esc_html_e( 'Alternative WP-CLI', 'partikulier' ); ?></summary>
				<p style="margin:8px 0 0"><code>wp eval-file <?php echo esc_html( 'wp-content/themes/partikulier/tests/seed-annonces-test.php' ); ?></code> — simulation (10 annonces historiques)<br>
				<code>PK_APPLIQUER=1 wp eval-file wp-content/themes/partikulier/tests/seed-annonces-test.php</code> — applique 10<br>
				<?php esc_html_e( 'La démo 30 annonces est uniquement via l’interface 1-clic ci-dessus.', 'partikulier' ); ?></p>
			</details>
		</div>
		<?php
	}

	public static function handle_install() {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', self::NONCE ) ) {
			wp_die( esc_html__( 'Jeton invalide.', 'partikulier' ) );
		}
		$result = self::seed();
		set_transient( 'pk_demo_msg_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( admin_url( 'tools.php?page=pk-demo' ) );
		exit;
	}

	public static function handle_purge() {
		if ( ! current_user_can( 'manage_options' ) || ! wp_verify_nonce( $_POST['_wpnonce'] ?? '', self::NONCE ) ) {
			wp_die( esc_html__( 'Jeton invalide.', 'partikulier' ) );
		}
		$result = self::purge();
		set_transient( 'pk_demo_msg_' . get_current_user_id(), $result, 60 );
		wp_safe_redirect( admin_url( 'tools.php?page=pk-demo' ) );
		exit;
	}

	public static function purge( $echo = false ) {
		$posts = get_posts( array(
			'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_key'         => self::MARK,
			'meta_value'       => '1',
			'lang'            => '',
			'suppress_filters' => true,
		) );
		$media = get_posts( array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_key'         => self::MARK,
			'meta_value'       => '1',
			'lang'            => '',
			'suppress_filters' => true,
		) );
		foreach ( $posts as $id ) {
			wp_delete_post( (int) $id, true );
		}
		foreach ( $media as $id ) {
			wp_delete_attachment( (int) $id, true );
		}
		if ( class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
			Partikulier_Cache::purge_all();
		}
		flush_rewrite_rules( false );
		$msg = sprintf( __( 'Démo purgée : %d annonces, %d photos supprimées.', 'partikulier' ), count( $posts ), count( $media ) );
		if ( $echo ) {
			echo esc_html( $msg ) . "\n";
		}
		return $msg;
	}

	/**
	 * Crée le jeu de démo complet (30 annonces). Retourne un message pour l'admin.
	 */
	public static function seed() {
		if ( ! class_exists( 'Partikulier_Listing_Preview' ) || ! class_exists( 'Partikulier_Listing_I18n' ) ) {
			return 'ERREUR : thème Partikulier incomplet (moteur de rédaction manquant).';
		}
		$has_translations = class_exists( 'Partikulier_Listing_Translations' ) && method_exists( 'Partikulier_Listing_Translations', 'available' ) && Partikulier_Listing_Translations::available();
		$languages = $has_translations ? Partikulier_Listing_Translations::active_languages() : array( 'fr' );
		$default = in_array( 'fr', $languages, true ) ? 'fr' : ( $languages[0] ?? 'fr' );

		// 1) Purge ancien jeu (évite de réutiliser des IDs morts)
		$old_posts = get_posts( array(
			'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
			'post_status'      => 'any',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_key'         => self::MARK,
			'meta_value'       => '1',
			'lang'            => '',
			'suppress_filters' => true,
		) );
		$old_media = get_posts( array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'posts_per_page'   => -1,
			'fields'           => 'ids',
			'meta_key'         => self::MARK,
			'meta_value'       => '1',
			'lang'            => '',
			'suppress_filters' => true,
		) );
		foreach ( $old_posts as $id ) { wp_delete_post( (int) $id, true ); }
		foreach ( $old_media as $id ) { wp_delete_attachment( (int) $id, true ); }

		// 2) Pool de photos depuis demo/images/
		$pool = self::build_photo_pool();
		if ( ! $pool ) {
			return 'ERREUR : aucune photo disponible. Vérifiez les images AVIF/JPG/WebP dans demo/images/ et les droits du dossier uploads.';
		}

		// 3) Génération des 30 annonces dans six villes.
		$listings = self::generate_30_listings();

		// Prépare les termes canoniques (langue fr + liaisons Polylang si disponible)
		$prepared = array();
		foreach ( $listings as $l ) {
			$p = $l;
			$p['type_id']   = self::prepare_term( PARTIKULIER_ESTATIK_TYPE_TAXONOMY, $l['type'], $l['type_slug'] );
			$p['city_id']   = self::prepare_term( PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, $l['city'], $l['city_slug'] );
			$p['status_id'] = self::prepare_status_term( $l['action'] );
			$prepared[] = $p;
		}

		// Compte de test (comme un vrai dépôt)
		$email = 'demo-partikulier@partikulier.local';
		$user  = get_user_by( 'email', $email );
		if ( ! $user ) {
			$uid = wp_create_user( 'demo-partikulier', wp_generate_password( 20, true, false ), $email );
			if ( is_wp_error( $uid ) ) {
				return 'ERREUR : création du compte démo : ' . $uid->get_error_message();
			}
			$user = get_user_by( 'id', $uid );
			$user->set_role( 'contributor' );
			wp_update_user( array( 'ID' => $uid, 'display_name' => 'Démo Partikulier' ) );
		}

		$created = 0;
		$errors  = 0;
		foreach ( $prepared as $i => $p ) {
			$data = array(
				'pk_action_mode'     => $p['action'],
				'pk_role'            => 'proprietaire',
				'pk_type'            => (string) $p['type_id'],
				'pk_city_name'       => $p['city'],
				'pk_district_name'   => $p['district'],
				'pk_surface'         => (string) $p['surface'],
				'pk_price'           => (string) $p['price'],
				'pk_bedrooms'        => $p['bedrooms'],
				'pk_living_rooms'    => $p['living'],
				'pk_bathrooms'       => $p['bathrooms'],
				'pk_floor'           => $p['floor'],
				'pk_garage'          => $p['garage'],
				'pk_elevator'        => $p['elevator'],
				'pk_vis_a_vis'       => $p['vis_a_vis'],
				'pk_terrace'         => $p['terrace'],
				'pk_terrace_surface' => (string) $p['terrace_surface'],
				'pk_sunshine'        => '',
			);
			$norm  = Partikulier_Listing_Preview::normalize_input( $data );
			$title = Partikulier_Listing_I18n::title( $norm, $default );
			$desc  = Partikulier_Listing_I18n::description( $norm, $default );
			$bedrooms_num = '3+' === $p['bedrooms'] ? 3 : absint( $p['bedrooms'] );
			$living_num   = '3+' === $p['living'] ? 3 : absint( $p['living'] );
			$rooms = $bedrooms_num + $living_num;

			$post_id = wp_insert_post( array(
				'post_type'    => PARTIKULIER_ESTATIK_POST_TYPE,
				'post_status'  => 'publish',
				'post_author'  => (int) $user->ID,
				'post_title'   => $title,
				'post_content' => $desc,
				'post_excerpt' => wp_trim_words( $desc, 30 ),
			), true );
			if ( is_wp_error( $post_id ) || ! $post_id ) {
				$errors++;
				continue;
			}
			if ( function_exists( 'pll_set_post_language' ) ) {
				pll_set_post_language( $post_id, $default );
			}
			update_post_meta( $post_id, 'es_property_price', (int) $p['price'] );
			update_post_meta( $post_id, 'es_property_area', (int) $p['surface'] );
			if ( $rooms ) { update_post_meta( $post_id, 'es_property_total_rooms', $rooms ); }
			if ( '' !== $p['bedrooms'] ) {
				update_post_meta( $post_id, 'es_property_bedrooms', $bedrooms_num );
				update_post_meta( $post_id, '_pk_bedrooms_label', $p['bedrooms'] );
			}
			if ( '' !== $p['living'] ) {
				update_post_meta( $post_id, '_pk_living_rooms', $living_num );
				update_post_meta( $post_id, '_pk_living_rooms_label', $p['living'] );
			}
			if ( '' !== $p['bathrooms'] ) {
				update_post_meta( $post_id, 'es_property_bathrooms', absint( $p['bathrooms'] ) );
				update_post_meta( $post_id, '_pk_bathrooms_label', $p['bathrooms'] );
			}
			update_post_meta( $post_id, '_pk_terrace', $p['terrace'] );
			if ( $p['terrace_surface'] ) { update_post_meta( $post_id, '_pk_terrace_surface', (int) $p['terrace_surface'] ); }
			update_post_meta( $post_id, '_pk_vis_a_vis', $p['vis_a_vis'] );
			if ( '' !== $p['floor'] ) { update_post_meta( $post_id, '_pk_floor', $p['floor'] ); }
			update_post_meta( $post_id, '_pk_garage', $p['garage'] );
			update_post_meta( $post_id, '_pk_elevator', $p['elevator'] );
			update_post_meta( $post_id, '_pk_city_name', $p['city'] );
			update_post_meta( $post_id, '_pk_district_name', $p['district'] );
			update_post_meta( $post_id, '_pk_owner_name', 'Démo Partikulier' );
			update_post_meta( $post_id, '_pk_owner_email', $email );
			update_post_meta( $post_id, '_pk_owner_phone', '+212 600 000 ' . str_pad( (string) ( $i + 1 ), 2, '0', STR_PAD_LEFT ) );
			update_post_meta( $post_id, '_pk_owner_role', 'proprietaire' );
			update_post_meta( $post_id, '_pk_views', wp_rand( 12, 340 ) );
			update_post_meta( $post_id, '_pk_meta_description', Partikulier_Listing_I18n::meta_description( $norm, $default ) );
			if ( class_exists( 'Partikulier_WhatsApp_Verification' ) ) {
				update_post_meta( $post_id, '_pk_status', Partikulier_WhatsApp_Verification::STATUS_PENDING );
				if ( method_exists( 'Partikulier_WhatsApp_Verification', 'create_pending' ) ) {
					Partikulier_WhatsApp_Verification::create_pending( $post_id );
				}
			}
			if ( $p['type_id'] ) { wp_set_object_terms( $post_id, (int) $p['type_id'], PARTIKULIER_ESTATIK_TYPE_TAXONOMY ); }
			if ( $p['status_id'] ) { wp_set_object_terms( $post_id, (int) $p['status_id'], PARTIKULIER_ESTATIK_STATUS_TAXONOMY ); }
			if ( $p['city_id'] ) { wp_set_object_terms( $post_id, (int) $p['city_id'], PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ); }
			if ( defined( 'Partikulier_Listing_Translations::META_SOURCE_LANG' ) ) {
				update_post_meta( $post_id, Partikulier_Listing_Translations::META_SOURCE_LANG, $default );
			}

			// Galerie : 3 photos cycliques du pool
			$n = count( $pool );
			$gallery = array();
			for ( $k = 0; $k < min( 3, $n ); $k++ ) {
				$gallery[] = (int) $pool[ ( $i + $k ) % $n ];
			}
			if ( $gallery ) {
				update_post_meta( $post_id, 'es_property_gallery', $gallery );
				set_post_thumbnail( $post_id, $gallery[0] );
			}
			update_post_meta( $post_id, self::MARK, '1' );

			// Traductions EN/AR si Polylang actif
			if ( $has_translations && class_exists( 'Partikulier_Listing_Translations' ) ) {
				$map = Partikulier_Listing_Translations::sync( $post_id, $norm, $default, '' );
				if ( count( $map ) > 1 ) {
					Partikulier_Listing_Translations::sync_status( $post_id, 'publish' );
					foreach ( $map as $lang => $tid ) {
						update_post_meta( (int) $tid, self::MARK, '1' );
						update_post_meta( (int) $tid, 'es_latitude', (float) $p['lat'] );
						update_post_meta( (int) $tid, 'es_longitude', (float) $p['lng'] );
						update_post_meta( (int) $tid, '_pk_status', 'actif' );
						update_post_meta( (int) $tid, '_pk_whatsapp_verified_at', current_time( 'mysql', true ) );
						update_post_meta( (int) $tid, '_pk_whatsapp_verified_by', (int) get_current_user_id() ?: 1 );
					}
				} else {
					update_post_meta( $post_id, 'es_latitude', (float) $p['lat'] );
					update_post_meta( $post_id, 'es_longitude', (float) $p['lng'] );
					update_post_meta( $post_id, '_pk_status', 'actif' );
				}
			} else {
				update_post_meta( $post_id, 'es_latitude', (float) $p['lat'] );
				update_post_meta( $post_id, 'es_longitude', (float) $p['lng'] );
				update_post_meta( $post_id, '_pk_status', 'actif' );
			}
			$created++;
		}

		flush_rewrite_rules( false );
		if ( class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
			Partikulier_Cache::purge_all();
		}
		if ( $errors ) {
			return sprintf( __( 'Démo installée : %d annonces créées, %d échecs. Vérifiez les logs.', 'partikulier' ), $created, $errors );
		}
		$total_langs = count( $languages );
		if ( $total_langs > 1 ) {
			return sprintf( __( 'Démo installée : %d annonces FR (+ %d traductions EN/AR) soit %d fiches au total, avec photos, visibles sur l’accueil et /annonces/.', 'partikulier' ), $created, $created * ( $total_langs - 1 ), $created * $total_langs );
		}
		return sprintf( __( 'Démo installée : %d annonces publiées avec photos, visibles sur l’accueil et /annonces/. Activez Polylang FR/EN/AR puis réinstallez pour avoir les traductions.', 'partikulier' ), $created );
	}

	/**
	 * Génère les 30 annonces dans six villes : 15 ventes / 15 locations.
	 * Distribution équilibrée pour tester tous les filtres et cas d'affichage.
	 */
	private static function generate_30_listings() {
		// Base fixe des 10 premières (identité visuelle forte, déjà validée) + 20 générées par variation
		$base = array(
			array( 'type' => 'Appartement', 'type_slug' => 'appartement', 'city' => 'Casablanca', 'city_slug' => 'casablanca', 'district' => 'Maârif',     'action' => 'vendre', 'price' => 1450000, 'surface' => 120, 'bedrooms' => '3',  'living' => '1', 'bathrooms' => '2', 'floor' => '3',   'terrace' => 'Oui', 'terrace_surface' => 12, 'garage' => 'Non', 'elevator' => 'Oui', 'vis_a_vis' => 'Oui', 'lat' => 33.5883, 'lng' => -7.6320 ),
			array( 'type' => 'Appartement', 'type_slug' => 'appartement', 'city' => 'Casablanca', 'city_slug' => 'casablanca', 'district' => 'Ain Diab',   'action' => 'vendre', 'price' => 980000,  'surface' => 85,  'bedrooms' => '2',  'living' => '1', 'bathrooms' => '1', 'floor' => '5',   'terrace' => 'Non', 'terrace_surface' => 0,  'garage' => 'Non', 'elevator' => 'Oui', 'vis_a_vis' => 'Non', 'lat' => 33.5920, 'lng' => -7.6710 ),
			array( 'type' => 'Studio',      'type_slug' => 'studio',      'city' => 'Casablanca', 'city_slug' => 'casablanca', 'district' => 'Gauthier',   'action' => 'louer',  'price' => 5500,    'surface' => 40,  'bedrooms' => '0',  'living' => '1', 'bathrooms' => '1', 'floor' => '2',   'terrace' => 'Non', 'terrace_surface' => 0,  'garage' => 'Non', 'elevator' => 'Non', 'vis_a_vis' => 'Non', 'lat' => 33.5950, 'lng' => -7.6180 ),
			array( 'type' => 'Villa',       'type_slug' => 'villa',       'city' => 'Casablanca', 'city_slug' => 'casablanca', 'district' => 'Californie', 'action' => 'vendre', 'price' => 4900000, 'surface' => 320, 'bedrooms' => '3+', 'living' => '2', 'bathrooms' => '3', 'floor' => 'RDC', 'terrace' => 'Oui', 'terrace_surface' => 40, 'garage' => 'Oui', 'elevator' => 'Non', 'vis_a_vis' => 'Non', 'lat' => 33.5660, 'lng' => -7.6630 ),
			array( 'type' => 'Appartement', 'type_slug' => 'appartement', 'city' => 'Rabat',      'city_slug' => 'rabat',      'district' => 'Agdal',      'action' => 'louer',  'price' => 6500,    'surface' => 95,  'bedrooms' => '2',  'living' => '1', 'bathrooms' => '1', 'floor' => '4',   'terrace' => 'Non', 'terrace_surface' => 0,  'garage' => 'Non', 'elevator' => 'Oui', 'vis_a_vis' => 'Non', 'lat' => 34.0100, 'lng' => -6.8500 ),
			array( 'type' => 'Maison',      'type_slug' => 'maison',      'city' => 'Rabat',      'city_slug' => 'rabat',      'district' => 'Souissi',    'action' => 'vendre', 'price' => 3200000, 'surface' => 240, 'bedrooms' => '4',  'living' => '2', 'bathrooms' => '3', 'floor' => 'RDC', 'terrace' => 'Oui', 'terrace_surface' => 25, 'garage' => 'Oui', 'elevator' => 'Non', 'vis_a_vis' => 'Oui', 'lat' => 33.9700, 'lng' => -6.8600 ),
			array( 'type' => 'Duplex',      'type_slug' => 'duplex',      'city' => 'Rabat',      'city_slug' => 'rabat',      'district' => 'Hassan',     'action' => 'vendre', 'price' => 1750000, 'surface' => 140, 'bedrooms' => '3',  'living' => '1', 'bathrooms' => '2', 'floor' => '6',   'terrace' => 'Oui', 'terrace_surface' => 18, 'garage' => 'Non', 'elevator' => 'Oui', 'vis_a_vis' => 'Non', 'lat' => 34.0210, 'lng' => -6.8400 ),
			array( 'type' => 'Riad',        'type_slug' => 'riad',        'city' => 'Marrakech',  'city_slug' => 'marrakech',  'district' => 'Médina',     'action' => 'vendre', 'price' => 2800000, 'surface' => 180, 'bedrooms' => '4',  'living' => '2', 'bathrooms' => '3', 'floor' => 'RDC', 'terrace' => 'Oui', 'terrace_surface' => 30, 'garage' => 'Non', 'elevator' => 'Non', 'vis_a_vis' => 'Non', 'lat' => 31.6295, 'lng' => -7.9811 ),
			array( 'type' => 'Appartement', 'type_slug' => 'appartement', 'city' => 'Marrakech',  'city_slug' => 'marrakech',  'district' => 'Hivernage',  'action' => 'louer',  'price' => 7500,    'surface' => 105, 'bedrooms' => '2',  'living' => '1', 'bathrooms' => '2', 'floor' => '1',   'terrace' => 'Oui', 'terrace_surface' => 10, 'garage' => 'Non', 'elevator' => 'Oui', 'vis_a_vis' => 'Non', 'lat' => 31.6240, 'lng' => -8.0060 ),
			array( 'type' => 'Terrain',     'type_slug' => 'terrain',     'city' => 'Marrakech',  'city_slug' => 'marrakech',  'district' => 'Targa',      'action' => 'vendre', 'price' => 850000,  'surface' => 500, 'bedrooms' => '',   'living' => '',  'bathrooms' => '',  'floor' => '',   'terrace' => 'Non', 'terrace_surface' => 0,  'garage' => 'Non', 'elevator' => 'Non', 'vis_a_vis' => 'Non', 'lat' => 31.6640, 'lng' => -8.0500 ),
		);
		// 20 annonces supplémentaires pour atteindre 30 — variations déterministes
		$pool_cities = array(
			array( 'Casablanca', 'casablanca', 'Oasis',      33.5550, -7.6200 ),
			array( 'Casablanca', 'casablanca', 'Bourgogne',  33.5860, -7.6400 ),
			array( 'Casablanca', 'casablanca', 'Hay Riad',   33.5710, -7.6500 ),
			array( 'Casablanca', 'casablanca', 'Val d\'Anfa',33.5800, -7.6500 ),
			array( 'Casablanca', 'casablanca', 'Sidi Maârouf',33.5200,-7.6500 ),
			array( 'Rabat',      'rabat',      'Hay Riad',   34.0000, -6.8600 ),
			array( 'Rabat',      'rabat',      'Les Orangers',34.0200,-6.8300 ),
			array( 'Rabat',      'rabat',      'Mabella',    33.9900, -6.8400 ),
			array( 'Rabat',      'rabat',      'Océan',      34.0300, -6.8400 ),
			array( 'Rabat',      'rabat',      'Temara',     33.9300, -6.9100 ),
			array( 'Marrakech',  'marrakech',  'Gueliz',     31.6400, -8.0100 ),
			array( 'Marrakech',  'marrakech',  'Palmeraie',  31.6800, -7.9800 ),
			array( 'Tanger',     'tanger',     'Malabata',   35.7800, -5.8100 ),
			array( 'Tanger',     'tanger',     'Marshan',    35.7900, -5.8300 ),
			array( 'Agadir',     'agadir',     'Founty',     30.4200, -9.6000 ),
			array( 'Agadir',     'agadir',     'Hay Mohammadi',30.4300,-9.6000 ),
			array( 'Fès',        'fes',        'Ville Nouvelle',34.0400,-5.0000 ),
			array( 'Fès',        'fes',        'Saïss',      33.9900, -5.0000 ),
			array( 'Casablanca', 'casablanca', 'Anfa',       33.6000, -7.6600 ),
			array( 'Marrakech',  'marrakech',  'Agdal',      31.6000, -7.9900 ),
		);
		$pool_types = array(
			array( 'Appartement', 'appartement', 95,  850000,  '2', '1', '1' ),
			array( 'Villa',       'villa',       280, 3800000, '3+','2', '3' ),
			array( 'Studio',      'studio',      35,  4800,    '0', '1', '1' ),
			array( 'Maison',      'maison',      200, 2100000, '3', '1', '2' ),
			array( 'Terrain',     'terrain',     400, 720000,  '',  '',  ''  ),
			array( 'Riad',        'riad',        160, 2400000, '3', '2', '2' ),
			array( 'Duplex',      'duplex',      130, 1650000, '3', '1', '2' ),
			array( 'Immeuble',    'immeuble',    600, 7500000, '3+','2', '3' ),
		);
		for ( $i = 0; $i < 20; $i++ ) {
			$c = $pool_cities[ $i % count( $pool_cities ) ];
			$t = $pool_types[ $i % count( $pool_types ) ];
			$is_rent = ( 1 === $i % 2 || 0 === $i || 2 === $i );
			$price = $is_rent ? ( $t[3] > 100000 ? intval( $t[3] / 200 ) : $t[3] ) : $t[3];
			if ( $is_rent && $price < 3000 ) { $price = 3500 + ( $i * 137 ) % 4000; }
			if ( ! $is_rent && $price < 100000 ) { $price = 600000 + ( $i * 99000 ) % 800000; }
			// petite variation de prix/surface pour éviter les doublons exacts
			$price   = intval( $price * ( 0.92 + ( $i * 7 % 15 ) / 100 ) );
			$surface = intval( $t[2] * ( 0.85 + ( $i * 3 % 20 ) / 100 ) );
			$base[] = array(
				'type' => $t[0], 'type_slug' => $t[1], 'city' => $c[0], 'city_slug' => $c[1], 'district' => $c[2],
				'action' => $is_rent ? 'louer' : 'vendre', 'price' => $price, 'surface' => $surface,
				'bedrooms' => $t[4], 'living' => $t[5], 'bathrooms' => $t[6],
				'floor' => $is_rent ? (string) ( 1 + $i % 6 ) : ( 0 === $i % 3 ? 'RDC' : (string) ( 2 + $i % 5 ) ),
				'terrace' => ( 0 === $i % 3 ? 'Oui' : 'Non' ), 'terrace_surface' => ( 0 === $i % 3 ? 10 + $i % 20 : 0 ),
				'garage' => ( 0 === $i % 4 ? 'Oui' : 'Non' ), 'elevator' => ( 0 === $i % 2 ? 'Oui' : 'Non' ),
				'vis_a_vis' => ( 0 === $i % 3 ? 'Oui' : 'Non' ), 'lat' => $c[3] + ( $i * 0.001 ), 'lng' => $c[4] + ( $i * 0.001 ),
			);
		}
		return $base; // 30 au total
	}

	// --- Helpers : termes + photos ---

	private static function prepare_term( $taxonomy, $name, $slug ) {
		$term = get_term_by( 'slug', $slug, $taxonomy );
		if ( ! $term ) { $term = get_term_by( 'name', $name, $taxonomy ); }
		if ( ! $term ) {
			$res = wp_insert_term( $name, $taxonomy, array( 'slug' => $slug ) );
			if ( is_wp_error( $res ) ) { return 0; }
			$term = get_term( (int) $res['term_id'], $taxonomy );
		}
		$tid = (int) $term->term_id;
		if ( function_exists( 'pll_get_term_language' ) && function_exists( 'pll_set_term_language' ) && ! pll_get_term_language( $tid, 'slug' ) ) {
			pll_set_term_language( $tid, 'fr' );
		}
		if ( function_exists( 'pll_get_term' ) && function_exists( 'pll_save_term_translations' ) ) {
			$map = array( 'fr' => $tid );
			$changed = false;
			foreach ( array( 'en', 'ar' ) as $l ) {
				if ( pll_get_term( $tid, $l ) ) { continue; }
				$cand = get_term_by( 'slug', $slug . '-' . $l, $taxonomy );
				if ( ! $cand || is_wp_error( $cand ) ) { continue; }
				if ( function_exists( 'pll_get_term_language' ) && ! pll_get_term_language( (int) $cand->term_id, 'slug' ) && function_exists( 'pll_set_term_language' ) ) {
					pll_set_term_language( (int) $cand->term_id, $l );
				}
				$map[ $l ] = (int) $cand->term_id;
				$changed = true;
			}
			if ( $changed ) { pll_save_term_translations( $map ); }
		}
		return $tid;
	}

	private static function prepare_status_term( $mode ) {
		$is_rent = ( 'louer' === $mode );
		$slugs = $is_rent ? array( 'a-louer', 'louer', 'for-rent', 'rent' ) : array( 'a-vendre', 'vendre', 'for-sale', 'sale' );
		foreach ( $slugs as $slug ) {
			$term = get_term_by( 'slug', $slug, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
			if ( $term ) { return self::prepare_term( PARTIKULIER_ESTATIK_STATUS_TAXONOMY, $term->name, $term->slug ); }
		}
		$needles = $is_rent ? array( 'a louer', 'louer', 'location', 'for rent', 'rent' ) : array( 'a vendre', 'vendre', 'vente', 'for sale', 'sale' );
		$terms = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_STATUS_TAXONOMY, 'hide_empty' => false ) );
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$name = function_exists( 'mb_strtolower' ) ? mb_strtolower( remove_accents( $term->name ) ) : strtolower( remove_accents( $term->name ) );
				foreach ( $needles as $needle ) {
					if ( $name === $needle || false !== strpos( $name, $needle ) ) {
						return self::prepare_term( PARTIKULIER_ESTATIK_STATUS_TAXONOMY, $term->name, $term->slug );
					}
				}
			}
		}
		return self::prepare_term( PARTIKULIER_ESTATIK_STATUS_TAXONOMY, $is_rent ? 'A louer' : 'A vendre', $is_rent ? 'a-louer' : 'a-vendre' );
	}

	private static function build_photo_pool() {
		$dir = PARTIKULIER_DIR . '/demo/images';
		$files = is_dir( $dir ) ? glob( $dir . '/*.{jpg,jpeg,avif,webp}', GLOB_BRACE ) : array();
		if ( ! $files ) {
			$ids = get_posts( array(
				'post_type'        => 'attachment',
				'post_mime_type'   => 'image',
				'posts_per_page'   => 3,
				'fields'           => 'ids',
				'orderby'          => 'ID',
				'order'            => 'ASC',
				'suppress_filters' => true,
				'meta_query'       => array( array( 'key' => self::MARK, 'compare' => 'NOT EXISTS' ) ),
			) );
			return array_map( 'absint', is_wp_error( $ids ) ? array() : $ids );
		}
		$existing = get_posts( array(
			'post_type'        => 'attachment',
			'post_status'      => 'inherit',
			'posts_per_page'   => 20,
			'fields'           => 'ids',
			'meta_key'         => self::MARK,
			'meta_value'       => '1',
			'suppress_filters' => true,
		) );
		if ( count( $existing ) >= 3 ) {
			return array_map( 'absint', $existing );
		}
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';
		$pool = array();
		// Utilise jusqu'à 30 images max (au-delà, on tranche pour garder le zip léger et l'install rapide)
		foreach ( array_slice( $files, 0, 30 ) as $path ) {
			$bits = file_get_contents( $path );
			if ( ! $bits ) { continue; }
			$upload = wp_upload_bits( basename( $path ), null, $bits );
			if ( ! empty( $upload['error'] ) ) { continue; }
			$ext = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );
			$mime = ( 'avif' === $ext ? 'image/avif' : ( 'webp' === $ext ? 'image/webp' : 'image/jpeg' ) );
			$att = wp_insert_attachment( array(
				'post_mime_type' => $mime,
				'post_title'     => sanitize_file_name( pathinfo( $path, PATHINFO_FILENAME ) ),
				'post_status'    => 'inherit',
			), $upload['file'], 0 );
			if ( is_wp_error( $att ) || ! $att ) { continue; }
			wp_update_attachment_metadata( $att, wp_generate_attachment_metadata( $att, $upload['file'] ) );
			update_post_meta( $att, '_wp_attachment_image_alt', 'Démo Partikulier' );
			update_post_meta( $att, self::MARK, '1' );
			$pool[] = (int) $att;
		}
		return $pool;
	}
}

Partikulier_Demo_Installer::init();
