<?php
/**
 * Écran « Équipements » : pastilles du dépôt, trois langues, par type de bien.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Amenities_Admin {

	const MENU   = 'pk-amenities';
	const ACTION = 'pk_save_amenities';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
	}

	public static function menu() {
		add_submenu_page(
			'partikulier',
			__( 'Équipements', 'partikulier' ),
			__( 'Équipements', 'partikulier' ),
			'manage_options',
			self::MENU,
			array( __CLASS__, 'render' )
		);
	}

	public static function handle_save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'partikulier' ) );
		}
		check_admin_referer( self::ACTION );
		if ( isset( $_POST['pk_amenity_reset'] ) ) {
			Partikulier_Amenities::reset();
			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'fait' => 'reset' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		$lignes = isset( $_POST['pk_amenity_row'] ) && is_array( $_POST['pk_amenity_row'] ) ? wp_unslash( $_POST['pk_amenity_row'] ) : array();
		$textes = array(
			'group'   => isset( $_POST['pk_amenity_group'] ) && is_array( $_POST['pk_amenity_group'] ) ? wp_unslash( $_POST['pk_amenity_group'] ) : array(),
			'hint'    => isset( $_POST['pk_amenity_hint'] ) && is_array( $_POST['pk_amenity_hint'] ) ? wp_unslash( $_POST['pk_amenity_hint'] ) : array(),
			'furnish' => isset( $_POST['pk_amenity_furnish'] ) && is_array( $_POST['pk_amenity_furnish'] ) ? wp_unslash( $_POST['pk_amenity_furnish'] ) : array(),
		);
		$types  = isset( $_POST['pk_amenity_type'] ) && is_array( $_POST['pk_amenity_type'] ) ? wp_unslash( $_POST['pk_amenity_type'] ) : array();
		$presents = isset( $_POST['pk_amenity_type_present'] ) && is_array( $_POST['pk_amenity_type_present'] ) ? wp_unslash( $_POST['pk_amenity_type_present'] ) : array();
		foreach ( $presents as $cle ) {
			$cle = sanitize_key( $cle );
			if ( '' !== $cle && ! isset( $types[ $cle ] ) ) {
				$types[ $cle ] = array();
			}
		}
		$connus = array();
		foreach ( Partikulier_Amenities::items() as $item ) {
			$connus[] = $item['id'];
		}
		$res = Partikulier_Amenities::save( $lignes, $textes, $types, $connus );
		$fait = is_wp_error( $res ) ? 'vide' : 'ok';
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'fait' => $fait ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$config  = Partikulier_Amenities::config();
		$items   = Partikulier_Amenities::items();
		$groupes = Partikulier_Amenities::type_groups();
		$fait    = isset( $_GET['fait'] ) ? sanitize_key( wp_unslash( $_GET['fait'] ) ) : '';
		$langues = array( 'fr' => 'FR', 'en' => 'EN', 'ar' => 'AR' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Équipements', 'partikulier' ); ?></h1>
			<?php if ( 'ok' === $fait ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Pastilles enregistrées.', 'partikulier' ); ?></p></div>
			<?php elseif ( 'reset' === $fait ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Retour à la liste d’origine.', 'partikulier' ); ?></p></div>
			<?php elseif ( 'vide' === $fait ) : ?>
				<div class="notice notice-error is-dismissible"><p><?php esc_html_e( 'Enregistrement refusé : gardez au moins une pastille avec un libellé français.', 'partikulier' ); ?></p></div>
			<?php endif; ?>
			<p><?php esc_html_e( 'Ces pastilles remplacent les Oui / Non du dépôt. Le libellé affiché suit la langue du visiteur. Cochez, pour chaque type de bien, celles qui doivent apparaître. Une pastille non cochée au dépôt reste un « non ». Terrasse, garage, ascenseur et vis-à-vis continuent d’alimenter la fiche. Masquer l’un de ces quatre champs dans « Formulaire de dépôt » masque aussi sa pastille.', 'partikulier' ); ?></p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">
				<h2><?php esc_html_e( 'Titre et phrase du bloc', 'partikulier' ); ?></h2>
				<table class="form-table"><tbody>
					<tr>
						<th scope="row"><?php esc_html_e( 'Titre', 'partikulier' ); ?></th>
						<td><?php foreach ( $langues as $code => $nom ) : ?>
							<label><?php echo esc_html( $nom ); ?> <input type="text" class="regular-text" name="pk_amenity_group[<?php echo esc_attr( $code ); ?>]" value="<?php echo esc_attr( $config['group'][ $code ] ?? '' ); ?>"></label><br>
						<?php endforeach; ?></td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'Phrase', 'partikulier' ); ?></th>
						<td><?php foreach ( $langues as $code => $nom ) : ?>
							<label><?php echo esc_html( $nom ); ?> <input type="text" class="large-text" name="pk_amenity_hint[<?php echo esc_attr( $code ); ?>]" value="<?php echo esc_attr( $config['hint'][ $code ] ?? '' ); ?>"></label><br>
						<?php endforeach; ?></td>
					</tr>
				</tbody></table>
				<h2><?php esc_html_e( 'Location : meublé ou vide', 'partikulier' ); ?></h2>
					<p class="description"><?php esc_html_e( 'La location saisonnière reprend les pastilles meublées, plus celles cochées seulement pour la saisonnière. Les pastilles de toutes les locations sont cochées pour le vide et le meublé.', 'partikulier' ); ?></p>
				<table class="form-table"><tbody>
					<?php
					$pk_furnish = isset( $config['furnish'] ) && is_array( $config['furnish'] ) ? $config['furnish'] : Partikulier_Amenities::default_furnish();
					$pk_furnish_rows = array(
						'title'  => __( 'Titre', 'partikulier' ),
						'meuble' => __( 'Meublé', 'partikulier' ),
						'vide'   => __( 'Vide', 'partikulier' ),
						'note'   => __( 'Phrase saisonnière', 'partikulier' ),
						'error'  => __( 'Message si le choix manque', 'partikulier' ),
					);
					foreach ( $pk_furnish_rows as $pk_cle => $pk_nom ) :
						$pk_vals = isset( $pk_furnish[ $pk_cle ] ) && is_array( $pk_furnish[ $pk_cle ] ) ? $pk_furnish[ $pk_cle ] : array();
						?>
					<tr>
						<th scope="row"><?php echo esc_html( $pk_nom ); ?></th>
						<td><?php foreach ( $langues as $code => $nom ) : ?>
							<label><?php echo esc_html( $nom ); ?> <input type="text" class="regular-text" name="pk_amenity_furnish[<?php echo esc_attr( $pk_cle ); ?>][<?php echo esc_attr( $code ); ?>]" value="<?php echo esc_attr( $pk_vals[ $code ] ?? '' ); ?>"></label><br>
						<?php endforeach; ?></td>
					</tr>
					<?php endforeach; ?>
				</tbody></table>
				<h2><?php esc_html_e( 'Pastilles', 'partikulier' ); ?></h2>
				<table class="widefat striped">
					<thead><tr>
						<th><?php esc_html_e( 'Français', 'partikulier' ); ?></th>
						<th><?php esc_html_e( 'Anglais', 'partikulier' ); ?></th>
						<th><?php esc_html_e( 'Arabe', 'partikulier' ); ?></th>
						<th><?php esc_html_e( 'Retirer', 'partikulier' ); ?></th>
					</tr></thead>
					<tbody id="pk-amenity-rows">
					<?php foreach ( $items as $i => $item ) : ?>
						<tr>
							<td>
								<input type="hidden" name="pk_amenity_row[<?php echo (int) $i; ?>][id]" value="<?php echo esc_attr( $item['id'] ); ?>">
								<input type="text" class="regular-text" name="pk_amenity_row[<?php echo (int) $i; ?>][fr]" value="<?php echo esc_attr( $item['fr'] ?? '' ); ?>" required>
								<?php
								$pk_ctx = isset( $item['ctx'] ) && is_array( $item['ctx'] ) && $item['ctx'] ? $item['ctx'] : array( 'vente', 'vide', 'meuble', 'saisonnier' );
								$pk_ctx_noms = array( 'vente' => __( 'Vente', 'partikulier' ), 'vide' => __( 'Location vide', 'partikulier' ), 'meuble' => __( 'Location meublée', 'partikulier' ), 'saisonnier' => __( 'Saisonnière', 'partikulier' ) );
								foreach ( $pk_ctx_noms as $pk_code => $pk_nom ) :
									?>
									<label style="display:inline-block;margin-right:.6rem;"><input type="checkbox" name="pk_amenity_row[<?php echo (int) $i; ?>][ctx][]" value="<?php echo esc_attr( $pk_code ); ?>" <?php checked( in_array( $pk_code, $pk_ctx, true ) ); ?>> <?php echo esc_html( $pk_nom ); ?></label>
								<?php endforeach; ?>
								<?php if ( ! empty( $item['follow'] ) ) : ?><p class="description"><?php esc_html_e( 'Ouvre le champ surface de la terrasse.', 'partikulier' ); ?></p><?php endif; ?>
							</td>
							<td><input type="text" class="regular-text" name="pk_amenity_row[<?php echo (int) $i; ?>][en]" value="<?php echo esc_attr( $item['en'] ?? '' ); ?>"></td>
							<td><input type="text" class="regular-text" dir="rtl" name="pk_amenity_row[<?php echo (int) $i; ?>][ar]" value="<?php echo esc_attr( $item['ar'] ?? '' ); ?>"></td>
							<td><label><input type="checkbox" name="pk_amenity_row[<?php echo (int) $i; ?>][drop]" value="1"> <?php esc_html_e( 'Retirer', 'partikulier' ); ?></label></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<p><button type="button" class="button" id="pk-add-amenity"><?php esc_html_e( 'Ajouter une pastille', 'partikulier' ); ?></button></p>
				<h2><?php esc_html_e( 'Par type de bien', 'partikulier' ); ?></h2>
				<?php if ( ! $groupes ) : ?>
					<p class="description"><?php esc_html_e( 'Aucun type de bien trouvé. Les pastilles s’afficheront pour tous les types dès qu’ils existeront.', 'partikulier' ); ?></p>
				<?php endif; ?>
				<?php foreach ( $groupes as $groupe ) : ?>
					<?php
					$cochees = array_key_exists( $groupe['key'], (array) $config['types'] ) ? (array) $config['types'][ $groupe['key'] ] : wp_list_pluck( $items, 'id' );
					if ( empty( $config['ctx_ready'] ) ) {
						$pk_cites = array();
						foreach ( (array) $config['types'] as $pk_liste ) {
							foreach ( (array) $pk_liste as $pk_id ) {
								$pk_cites[ $pk_id ] = true;
							}
						}
						foreach ( $items as $pk_item ) {
							if ( ! isset( $pk_cites[ $pk_item['id'] ] ) ) {
								$cochees[] = $pk_item['id'];
							}
						}
					}
					?>
					<input type="hidden" name="pk_amenity_type_present[]" value="<?php echo esc_attr( $groupe['key'] ); ?>">
					<h3><?php echo esc_html( $groupe['label'] ); ?></h3>
					<p>
						<?php foreach ( $items as $item ) : ?>
							<label style="display:inline-block;margin:0 .8rem .4rem 0;">
								<input type="checkbox" name="pk_amenity_type[<?php echo esc_attr( $groupe['key'] ); ?>][]" value="<?php echo esc_attr( $item['id'] ); ?>" <?php checked( in_array( $item['id'], $cochees, true ) ); ?>>
								<?php echo esc_html( $item['fr'] ?? $item['id'] ); ?>
							</label>
						<?php endforeach; ?>
					</p>
				<?php endforeach; ?>
				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Enregistrer les pastilles', 'partikulier' ); ?></button>
					<button type="submit" class="button" name="pk_amenity_reset" value="1" onclick="return confirm('Revenir à la liste d’origine ? Les libellés personnalisés seront effacés.');"><?php esc_html_e( 'Revenir à la liste d’origine', 'partikulier' ); ?></button>
				</p>
			</form>
			<script>
			document.getElementById('pk-add-amenity').addEventListener('click', function () {
				var body = document.getElementById('pk-amenity-rows');
				var i = body.querySelectorAll('tr').length;
				var tr = document.createElement('tr');
				tr.innerHTML = '<td><input type="hidden" name="pk_amenity_row[' + i + '][id]" value="">'
					+ '<input type="text" class="regular-text" name="pk_amenity_row[' + i + '][fr]" value=""></td>'
					+ '<td><input type="text" class="regular-text" name="pk_amenity_row[' + i + '][en]" value=""></td>'
					+ '<td><input type="text" class="regular-text" dir="rtl" name="pk_amenity_row[' + i + '][ar]" value=""></td>'
					+ '<td></td>';
				body.appendChild(tr);
			});
			</script>
		</div>
		<?php
	}
}
