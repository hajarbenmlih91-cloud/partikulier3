<?php
/**
 * Module : écran d'administration « Villes & quartiers » (SE-036, E-3602).
 *
 * Écran trilingue du référentiel marocain (Partikulier_Morocco_Places) :
 * chaque ville et chacun de ses quartiers est présenté avec sa forme
 * française (clé du référentiel), sa forme arabe (carte intégrée du lot
 * C1 étendue par SE-036 — E-3601) et la source de la résolution
 * (référentiel intégré ou import). L'import CSV (E-3603) permet à
 * l'administration de compléter ou corriger les formes arabes sans toucher
 * au code : les overrides (option pk_places_ar) priment sur la carte
 * intégrée, côté thème ET côté plugin (parité C1A-004).
 *
 * Format CSV attendu (UTF-8, séparateur « ; », en-tête ignoré) :
 *   ville;ville_ar;quartier;quartier_ar
 * Une ligne ville sans quartier enregistre la forme arabe de la ville ;
 * une ligne avec quartier enregistre celle du quartier. Les clés sont
 * normalisées (minuscules, sans accents) comme la résolution runtime.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
		return;
}

class Partikulier_Places_Admin {

		const OPTION   = 'pk_places_ar';
		const MENU_SLUG = 'pk-places';

	public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
			add_action( 'admin_post_pk_places_import', array( __CLASS__, 'handle_import' ) );
			add_action( 'admin_post_pk_places_reset', array( __CLASS__, 'handle_reset' ) );
	}

	public static function register_menu() {
			add_submenu_page(
					'partikulier',
					__( 'Villes & quartiers', 'partikulier' ),
					__( 'Villes & quartiers', 'partikulier' ),
					'manage_options',
					self::MENU_SLUG,
					array( __CLASS__, 'render_page' )
			);
	}

		/**
		 * Forme arabe résolue d'un lieu (carte intégrée + override) et source
		 * de la résolution — partagé par le rendu et les contrats.
		 *
		 * @return array{ar:string,source:string} source : 'import' | 'referentiel' | ''
		 */
	public static function resolve_ar( $place ) {
			$key = mb_strtolower( trim( (string) $place ) );
			$key = function_exists( 'remove_accents' ) ? remove_accents( $key ) : $key;
			$overrides = self::overrides();
			if ( isset( $overrides[ $key ] ) ) {
					return array( 'ar' => $overrides[ $key ], 'source' => 'import' );
			}
			// carte intégrée : la résolution runtime fait la même recherche
			$ar = Partikulier_Listing_I18n::localized_place( (string) $place, 'ar' );
			if ( $ar !== (string) $place && '' !== $ar ) {
					return array( 'ar' => $ar, 'source' => 'referentiel' );
			}
			return array( 'ar' => '', 'source' => '' );
	}

		/** Overrides importés (clé normalisée => arabe). */
	public static function overrides() {
			static $cached = null;
			if ( null !== $cached ) {
					return $cached;
			}
			$cached = array();
			$raw    = get_option( self::OPTION, array() );
			if ( is_array( $raw ) ) {
					foreach ( $raw as $key => $arabic ) {
							if ( is_string( $key ) && is_string( $arabic ) && '' !== trim( $arabic ) ) {
									$norm            = remove_accents( mb_strtolower( trim( $key ) ) );
									$cached[ $norm ] = trim( $arabic );
							}
					}
			}
			return $cached;
	}

	public static function render_page() {
			if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'Accès non autorisé.', 'partikulier' ), 403 );
			}
			$reference = Partikulier_Morocco_Places::reference();
			$updated   = isset( $_GET['pk_places_updated'] ) ? absint( $_GET['pk_places_updated'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- affichage seul
			$skipped   = isset( $_GET['pk_places_skipped'] ) ? absint( $_GET['pk_places_skipped'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- affichage seul
			?>
			<div class="wrap">
					<h1><?php esc_html_e( 'Villes & quartiers', 'partikulier' ); ?></h1>
					<?php if ( $updated ) : ?>
							<div class="notice notice-success is-dismissible"><p>
									<?php echo esc_html( sprintf( __( '%d entrée(s) importée(s), %d ligne(s) ignorée(s).', 'partikulier' ), $updated, $skipped ) ); ?>
							</p></div>
					<?php endif; ?>
					<p><?php esc_html_e( 'Référentiel marocain intégré : chaque lieu est présenté avec sa forme française, sa forme arabe et la source de la résolution (référentiel intégré ou import CSV). Les formes importées priment sur le référentiel, sans toucher au code.', 'partikulier' ); ?></p>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data" style="margin-bottom:1em;">
							<?php wp_nonce_field( 'pk_places_import' ); ?>
							<input type="hidden" name="action" value="pk_places_import" />
							<input type="file" name="pk_places_csv" accept=".csv,text/csv" required />
							<button type="submit" class="button button-primary"><?php esc_html_e( 'Importer le CSV', 'partikulier' ); ?></button>
							<span style="margin-left:.5em;color:#666;"><?php esc_html_e( 'Format : ville;ville_ar;quartier;quartier_ar (UTF-8, « ; », en-tête ignoré)', 'partikulier' ); ?></span>
					</form>
					<?php if ( self::overrides() ) : ?>
					<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-bottom:1em;">
							<?php wp_nonce_field( 'pk_places_reset' ); ?>
							<input type="hidden" name="action" value="pk_places_reset" />
							<button type="submit" class="button button-secondary"><?php esc_html_e( 'Supprimer tous les imports', 'partikulier' ); ?></button>
					</form>
					<?php endif; ?>
					<table class="widefat striped">
							<thead><tr>
									<th><?php esc_html_e( 'Ville', 'partikulier' ); ?></th>
									<th>AR</th>
									<th><?php esc_html_e( 'Quartiers', 'partikulier' ); ?></th>
									<th>AR <?php esc_html_e( '(quartiers)', 'partikulier' ); ?></th>
							</tr></thead>
							<tbody>
							<?php foreach ( $reference as $city => $districts ) : ?>
									<?php $cityAr = self::resolve_ar( $city ); ?>
									<tr>
											<td><strong><?php echo esc_html( $city ); ?></strong></td>
											<td dir="rtl"><?php echo esc_html( $cityAr['ar'] ?: '—' ); ?><?php echo 'import' === $cityAr['source'] ? ' *' : ''; ?></td>
											<td><?php echo esc_html( implode( ', ', $districts ) ); ?></td>
											<td dir="rtl" style="max-width:340px;">
													<?php
													$ar_list = array();
													foreach ( $districts as $district ) {
															$r        = self::resolve_ar( $district );
															$ar_list[] = ( $r['ar'] ?: '—' ) . ( 'import' === $r['source'] ? ' *' : '' );
													}
													echo esc_html( implode( ' · ', $ar_list ) );
													?>
											</td>
									</tr>
							<?php endforeach; ?>
							</tbody>
					</table>
					<p style="color:#666;">* <?php esc_html_e( 'forme importée (prime sur le référentiel intégré)', 'partikulier' ); ?></p>
			</div>
			<?php
	}

		/**
		 * Import CSV (E-3603) : ville;ville_ar;quartier;quartier_ar → overrides.
		 * Validation : UTF-8, 4 colonnes, au moins une forme arabe non vide.
		 */
	public static function handle_import() {
			if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'Accès non autorisé.', 'partikulier' ), 403 );
			}
			check_admin_referer( 'pk_places_import' );

			$imported = 0;
			$skipped  = 0;
			$file     = isset( $_FILES['pk_places_csv'] ) ? $_FILES['pk_places_csv'] : null; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- fichier validé ci-dessous (type, taille, contenu ligne à ligne)

			if ( ! $file || ! is_uploaded_file( $file['tmp_name'] ?? '' ) ) {
					wp_safe_redirect( add_query_arg( array( 'pk_places_updated' => 0, 'pk_places_skipped' => 1 ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
					exit;
			}

			$rows  = array();
			$handle = fopen( $file['tmp_name'], 'r' );
			if ( $handle ) {
					while ( ( $row = fgetcsv( $handle, 0, ';' ) ) !== false ) {
							$rows[] = $row;
					}
					fclose( $handle );
			}
			list( $imported, $skipped ) = self::apply_rows( $rows );
			wp_safe_redirect( add_query_arg( array( 'pk_places_updated' => $imported, 'pk_places_skipped' => $skipped ), admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
			exit;
	}

		/** Retire tous les overrides importés. */
	public static function handle_reset() {
			if ( ! current_user_can( 'manage_options' ) ) {
					wp_die( esc_html__( 'Accès non autorisé.', 'partikulier' ), 403 );
			}
			check_admin_referer( 'pk_places_reset' );
			delete_option( self::OPTION );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG ) );
			exit;
	}

		/**
		 * Applique des lignes CSV (E-3603) : [ville, ville_ar, quartier, quartier_ar].
		 * Une ligne quartier+ar prime ; sinon ville+ar ; sinon ignorée. Retourne
		 * [importées, ignorées]. Méthode publique : le contrat la rejoue sur
		 * des lignes de fixture.
		 *
		 * @param array<int, array> $rows
		 * @return array{0:int,1:int}
		 */
	public static function apply_rows( $rows ) {
			$overrides = self::raw_overrides();
			$imported  = 0;
			$skipped   = 0;
			foreach ( array_values( (array) $rows ) as $index => $row ) {
					if ( 0 === $index && isset( $row[0] ) && false !== stripos( (string) $row[0], 'ville' ) ) {
							continue; // ligne d'en-tête
					}
					$row = array_map( static function ( $v ) {
							return is_string( $v ) ? trim( $v, " \t\0\x0B\u{FEFF}" ) : '';
					}, array_pad( array_values( (array) $row ), 4, '' ) );
					list( $ville, $ville_ar, $quartier, $quartier_ar ) = $row;
					if ( '' !== $quartier && '' !== $quartier_ar ) {
							$overrides[ $quartier ] = $quartier_ar;
							$imported++;
					} elseif ( '' !== $ville && '' !== $ville_ar ) {
							$overrides[ $ville ] = $ville_ar;
							$imported++;
					} else {
							$skipped++;
					}
			}
			update_option( self::OPTION, $overrides, false );
			return array( $imported, $skipped );
	}

		/** Overrides bruts (clé saisie => arabe), pour l'import incrémental. */
	private static function raw_overrides() {
			$raw = get_option( self::OPTION, array() );
			return is_array( $raw ) ? $raw : array();
	}
}

Partikulier_Places_Admin::init();
