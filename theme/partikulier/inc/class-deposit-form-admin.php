<?php
/**
 * Administration : écran de réglage du formulaire de dépôt (SE-042c).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Deposit_Form_Admin extends Partikulier_Deposit_Form {

	/**
	 * Accrochages.
	 *
	 * @return void
	 */
	public static function init() {
		// Priorité 30 : le menu parent « Partikulier » est enregistré en priorité 10
		// (class-customization). Sans cet ordre, WordPress rattache le sous-menu au
		// mauvais parent et l'écran devient inaccessible (erreur 403).
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 30 );
		add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_save' ) );
	}

	/**
	 * Entrée de menu sous « Partikulier ».
	 *
	 * @return void
	 */
	public static function menu() {
		add_submenu_page(
			'partikulier',
			__( 'Formulaire de dépôt', 'partikulier' ),
			__( 'Formulaire de dépôt', 'partikulier' ),
			self::CAPABILITY,
			self::MENU,
			array( __CLASS__, 'render' )
		);
	}

	/**
	 * Enregistrement depuis l'écran d'administration.
	 *
	 * @return void
	 */
	public static function handle_save() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'Accès refusé.', 'partikulier' ) );
		}
		check_admin_referer( self::ACTION );

		$demande = isset( $_POST['pk_deposit'] ) && is_array( $_POST['pk_deposit'] ) ? wp_unslash( $_POST['pk_deposit'] ) : array();
		$ordres  = isset( $_POST['pk_deposit_ordre'] ) && is_array( $_POST['pk_deposit_ordre'] ) ? wp_unslash( $_POST['pk_deposit_ordre'] ) : array();
		$preset  = isset( $_POST['pk_preset'] ) ? sanitize_key( wp_unslash( $_POST['pk_preset'] ) ) : '';

		if ( '8' === $preset ) {
			self::save( self::preset_8(), array() );
			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'fait' => 'preset8' ), admin_url( 'admin.php' ) ) );
			exit;
		}
		if ( 'herite' === $preset ) {
			self::reset();
			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'fait' => 'herite' ), admin_url( 'admin.php' ) ) );
			exit;
		}

		// Enregistrement : le plafond est vérifié dans save(), jamais dans le navigateur seul.
		$res = self::save( $demande, $ordres );
		if ( is_wp_error( $res ) ) {
			wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'fait' => 'trop', 'message' => rawurlencode( $res->get_error_message() ) ), admin_url( 'admin.php' ) ) );
			exit;
		}

		self::save( $demande, $ordres );
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU, 'fait' => 'ok' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Écran d'administration.
	 *
	 * @return void
	 */
	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			return;
		}
		$config   = self::config();
		$configured = self::is_configured();
		$etats    = array(
			self::HIDDEN   => __( 'Masqué', 'partikulier' ),
			self::OPTIONAL => __( 'Optionnel', 'partikulier' ),
			self::REQUIRED => __( 'Obligatoire', 'partikulier' ),
		);
		$types    = self::types();
		$champs   = self::fields();
		$plafond  = self::cap();
		$fait     = isset( $_GET['fait'] ) ? sanitize_key( wp_unslash( $_GET['fait'] ) ) : '';
		?>
		<div class="wrap pk-deposit-admin">
			<h1><?php esc_html_e( 'Formulaire de dépôt', 'partikulier' ); ?></h1>
			<style>
				.pk-deposit-admin .pk-etat { max-width: 190px; }
				.pk-deposit-admin table.widefat { margin-top: .8rem; }
				.pk-deposit-admin .pk-compteur { font-weight: 700; }
				.pk-deposit-admin .pk-compteur.est-bon { color: #166534; }
				.pk-deposit-admin .pk-compteur.est-trop { color: #b32d2e; }
				.pk-deposit-admin .pk-note { max-width: 900px; }
				.pk-deposit-admin .pk-preset { margin: 1rem 0; padding: .9rem 1rem; background: #fff; border: 1px solid #dcdcde; border-left: 4px solid #2271b1; }
				.pk-deposit-admin .pk-rang { color: #646970; }
				@media screen and (max-width: 782px) {
					.pk-deposit-admin .pk-etat { max-width: none; width: 100%; }
					.pk-deposit-admin .pk-col-nouveau { display: none; }
					.pk-deposit-admin .pk-col-ordre input { width: 4.5rem; }
					.pk-deposit-admin table.widefat th, .pk-deposit-admin table.widefat td { padding: 8px 6px; }
				}
			</style>

			<?php if ( 'ok' === $fait ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Réglages enregistrés.', 'partikulier' ); ?></p></div>
			<?php elseif ( 'preset8' === $fait ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Configuration « 8 champs » appliquée : le plafond est respecté pour chaque type de bien.', 'partikulier' ); ?></p></div>
			<?php elseif ( 'herite' === $fait ) : ?>
				<div class="notice notice-warning is-dismissible"><p><?php esc_html_e( 'Retour au comportement d’origine du thème : tous les champs actuels sont demandés.', 'partikulier' ); ?></p></div>
			<?php elseif ( 'trop' === $fait ) : ?>
				<div class="notice notice-error is-dismissible"><p>
					<?php
					printf(
						/* translators: 1: plafond, 2: liste des types en dépassement */
						esc_html__( 'Enregistrement refusé : le plafond de %1$d champs est dépassé pour %2$s. Décochez des champs puis réessayez.', 'partikulier' ),
						(int) $plafond,
						esc_html( isset( $_GET['types'] ) ? rawurldecode( wp_unslash( $_GET['types'] ) ) : '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
					);
					?>
				</p></div>
			<?php endif; ?>

			<p class="pk-note">
				<?php
				printf(
					/* translators: 1: nombre de champs affichés, 2: plafond */
					esc_html__( 'Pour chaque type de bien, choisissez les champs demandés au dépôt d’annonce. Plafond conseillé : %2$d champs affichés par type, pour ne pas casser la mise en page du formulaire. Réglage actuel : %1$s.', 'partikulier' ),
					esc_html( $configured ? __( 'vos choix enregistrés', 'partikulier' ) : __( 'comportement d’origine du thème', 'partikulier' ) ),
					(int) $plafond
				);
				?>
			</p>

			<?php if ( ! $configured ) : ?>
				<div class="notice notice-info inline"><p>
					<strong><?php esc_html_e( 'Rien n’est encore réglé.', 'partikulier' ); ?></strong>
					<?php esc_html_e( 'Le formulaire se comporte exactement comme aujourd’hui. Choisissez ci-dessous, ou partez de la configuration conseillée.', 'partikulier' ); ?>
				</p></div>
			<?php endif; ?>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( self::ACTION ); ?>
				<input type="hidden" name="action" value="<?php echo esc_attr( self::ACTION ); ?>">

				<div class="pk-preset">
					<strong><?php esc_html_e( 'Configurations prêtes', 'partikulier' ); ?></strong>
					<p>
						<button type="submit" name="pk_preset" value="8" class="button button-primary"><?php esc_html_e( 'Appliquer « 8 champs » (conseillé)', 'partikulier' ); ?></button>
						<button type="submit" name="pk_preset" value="herite" class="button"><?php esc_html_e( 'Revenir au comportement d’origine', 'partikulier' ); ?></button>
					</p>
					<p class="description"><?php esc_html_e( '« 8 champs » : type, ville, quartier, superficie, chambres, prix, salons, salles de bains — et, pour un terrain, terrain + ensoleillement + disponibilité à la place des pièces.', 'partikulier' ); ?></p>
				</div>

				<?php foreach ( $types as $slug => $libelle ) : ?>
					<?php
					$n = 0;
					foreach ( array_keys( $champs ) as $c ) {
						$etat = self::state( $c, $slug );
						if ( self::INHERITED === $etat ) {
							$etat = $champs[ $c ]['actuel'];
						}
						if ( self::HIDDEN !== $etat ) {
							$n++;
						}
					}
					$regle  = false;
					foreach ( array_keys( $champs ) as $c ) {
						if ( self::INHERITED !== self::state( $c, $slug ) ) {
							$regle = true;
							break;
						}
					}
					$classe = ( $n > $plafond ) ? 'est-trop' : 'est-bon';
					?>
					<h2 id="type-<?php echo esc_attr( $slug ); ?>"><?php echo esc_html( $libelle ); ?></h2>
					<p class="pk-compteur <?php echo esc_attr( $classe ); ?>" data-pk-counter="<?php echo esc_attr( $slug ); ?>" data-pk-configured="<?php echo $regle ? 'oui' : 'non'; ?>">
						<?php
						if ( ! $regle ) {
							// Rien de réglé : on décrit le formulaire d'aujourd'hui, sans ton d'alerte.
							printf(
								/* translators: 1: nombre actuel, 2: plafond */
								esc_html__( 'Aujourd’hui : %1$d champs affichés (formulaire d’origine) — plafond conseillé : %2$d. Appliquez une configuration ou réglez ci-dessous.', 'partikulier' ),
								(int) $n,
								(int) $plafond
							);
						} else {
							printf(
								/* translators: 1: nombre, 2: plafond */
								esc_html__( 'Champs affichés : %1$d sur %2$d', 'partikulier' ),
								(int) $n,
								(int) $plafond
							);
						}
						?>
					</p>
					<table class="widefat striped pk-tableau" data-pk-table="<?php echo esc_attr( $slug ); ?>">
						<thead>
							<tr>
								<th scope="col"><?php esc_html_e( 'Champ', 'partikulier' ); ?></th>
								<th scope="col" class="pk-col-nouveau"><?php esc_html_e( 'Nouveau', 'partikulier' ); ?></th>
								<th scope="col"><?php esc_html_e( 'État dans le formulaire', 'partikulier' ); ?></th>
							<th scope="col" class="pk-col-ordre"><?php esc_html_e( 'Ordre', 'partikulier' ); ?></th>
							</tr>
						</thead>
						<tbody>
						<?php foreach ( $champs as $champ => $info ) : ?>
							<?php
							$etat = self::state( $champ, $slug );
							if ( self::INHERITED === $etat ) {
								$etat = $info['actuel'];
							}
							?>
							<tr>
								<td><label for="f-<?php echo esc_attr( $slug . '-' . $champ ); ?>"><?php echo esc_html( $info['label'] ); ?></label></td>
								<td class="pk-col-nouveau"><?php echo $info['ajoute'] ? '<span aria-hidden="true">＋</span>' : ''; ?></td>
								<td>
									<select class="pk-etat" id="f-<?php echo esc_attr( $slug . '-' . $champ ); ?>"
										name="pk_deposit[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $champ ); ?>]"
										data-pk-field="<?php echo esc_attr( $champ ); ?>" data-pk-type="<?php echo esc_attr( $slug ); ?>">
										<?php foreach ( $etats as $valeur => $texte ) : ?>
											<option value="<?php echo esc_attr( $valeur ); ?>" <?php selected( $etat, $valeur ); ?>><?php echo esc_html( $texte ); ?></option>
										<?php endforeach; ?>
									</select>
								</td>
									<td class="pk-col-ordre">
										<input type="number" min="1" max="99" step="1" class="small-text" value="<?php echo esc_attr( self::order( $champ, $slug ) ); ?>"
											name="pk_deposit_ordre[<?php echo esc_attr( $slug ); ?>][<?php echo esc_attr( $champ ); ?>]"
											aria-label="<?php echo esc_attr( sprintf( __( 'Ordre du champ %s', 'partikulier' ), $info['label'] ) ); ?>">
									</td>
								</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				<?php endforeach; ?>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Enregistrer les réglages', 'partikulier' ); ?></button>
				</p>
			</form>

			<script>
			( function () {
				// Compteur vivant : confort d'usage seulement, le serveur revérifie.
				function maj( table ) {
					var type = table.getAttribute( 'data-pk-table' );
					var n = 0;
					table.querySelectorAll( '.pk-etat' ).forEach( function ( s ) {
						if ( s.value !== '<?php echo esc_js( self::HIDDEN ); ?>' ) { n++; }
					} );
					var c = document.querySelector( '[data-pk-counter="' + type + '"]' );
					if ( ! c ) { return; }
					var max = <?php echo (int) $plafond; ?>;
					c.textContent = c.textContent.replace( /\d+ sur \d+/, n + ' sur ' + max );
					var regle = c.getAttribute( 'data-pk-configured' ) === 'oui';
					c.className = 'pk-compteur ' + ( n > max && regle ? 'est-trop' : 'est-bon' );
				}
				document.querySelectorAll( '[data-pk-table]' ).forEach( function ( t ) {
					t.addEventListener( 'change', function ( e ) {
						if ( e.target.classList.contains( 'pk-etat' ) ) { maj( t ); }
					} );
				} );
			}() );
			</script>
		</div>
		<?php
	}
}
