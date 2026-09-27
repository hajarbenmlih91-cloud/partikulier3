<?php
/**
 * Fondations internes des annonces premium Partikulier.
 *
 * Lot F de la refonte (CDC v1.2 — extinction finale) : le domaine premium
 * (journal pk_premium_history, méta et transitions) est propriété du plugin
 * partikulier-core 2.1+ depuis le lot B1 ; le VESTIGE autonome 6.17.x est
 * physiquement retiré — installation du journal et écritures directes sont
 * éteints. Chaque opération délègue exclusivement à
 * \Partikulier\Core\Domain\Premium\PremiumService, qui est requis. L'ÉCRAN
 * d'administration (rendu, nonce, redirection) reste au thème (arbitrage B1 :
 * UI au thème, politique au plugin).
 *
 * Aucun affichage public ni tri n’est activé par ce module. Les décisions
 * métier G3 (durée, rôles, plafond et procédure de retrait) restent requises
 * avant l’activation de la visibilité premium dans les recherches.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
		return;
}

class Partikulier_Premium {

		const OPTION_PUBLIC_ENABLED = 'pk_premium_public_enabled';
		const META_STATUS           = '_pk_premium_status';
		const META_STARTS_AT        = '_pk_premium_starts_at';
		const META_ENDS_AT          = '_pk_premium_ends_at';
		const STATUS_ACTIVE         = 'active';
		const STATUS_EXPIRED        = 'expired';
		const STATUS_REVOKED        = 'revoked';

	/**
	 * SE-048-R (E-4806/E-4808) : les libellés du module existent dans les TROIS
	 * langues du site, dans le code. Ils ne passent PAS par les dictionnaires
	 * trilingues du lot C2 (ChromeDictionary/FormsDictionary) : leurs comptes
	 * d'entrées sont figés par les contrats gelés C2A-002 (136) et C2A-003 (140),
	 * et le registre Polylang par C2A-006 (150) — y ajouter une chaîne
	 * d'ADMINISTRATION rouvrirait trois lots fermés. Conséquence assumée,
	 * documentée au rapport SE-048-R : ces libellés ne sont pas éditables depuis
	 * l'administration ; leur migration vers Polylang sera un micro-lot dédié si
	 * le besoin apparaît.
	 */
	const LANGUAGES = array( 'fr', 'en', 'ar' );

	/** Langue de rendu de l'interface premium (Polylang, filtre de test). */
	public static function ui_language() {
		$language = '';
		if ( function_exists( 'pll_current_language' ) ) {
			$language = (string) pll_current_language( 'slug' );
		}
		$language = (string) apply_filters( 'partikulier_premium_ui_language', $language );
		return in_array( $language, self::LANGUAGES, true ) ? $language : 'fr';
	}

	/**
	 * Rend un libellé dans la langue courante. Les trois variantes sont
	 * fournies au même endroit : aucun appelant ne peut en oublier une.
	 *
	 * @param array<string,string> $labels fr/en/ar.
	 * @return string
	 */
	public static function label( $labels ) {
		foreach ( self::LANGUAGES as $language ) {
			if ( ! isset( $labels[ $language ] ) || '' === (string) $labels[ $language ] ) {
				return (string) ( $labels['fr'] ?? '' );
			}
		}
		return (string) $labels[ self::ui_language() ];
	}

	/** Libellé public du badge premium (E-4804) — trois langues, dans le code. */
	public static function badge_label() {
		return self::label(
			array(
				'fr' => 'Premium',
				'en' => 'Premium',
				'ar' => 'بريميوم',
			)
		);
	}

	/**
	 * Les trois textes du bandeau d'administration (E-4806), dans la langue
	 * courante : (1) l'octroi gratuit par l'administration, (2) l'état RÉEL de
	 * la visibilité publique — qui suit le drapeau, (3) le rappel du motif et
	 * de la réversibilité. Aucune promesse de paiement, aucune passerelle.
	 *
	 * @return array{offer:string, visibility:string, reminder:string, visibility_enabled:bool}
	 */
	public static function banner_texts() {
		$enabled = self::is_public_enabled();
		return array(
			'offer'              => self::label(
				array(
					'fr' => 'Premium offert par Partikulier : l’administration attribue le statut gratuitement, sans aucun paiement demandé au propriétaire — ni par le site, ni par un intermédiaire.',
					'en' => 'Premium offered by Partikulier: the administration grants the status free of charge, with no payment requested from the owner — neither by the site nor by any third party.',
					'ar' => 'بريميوم مقدَّم من بارتيكيولييه: تمنح الإدارة هذه الصفة مجانًا، دون أي دفع يُطلب من المالك — لا عبر الموقع ولا عبر أي وسيط.',
				)
			),
			'visibility'         => $enabled
				? self::label(
					array(
						'fr' => 'Visibilité publique ACTIVE : le badge et le tri des résultats sont en service depuis l’activation du réglage d’exploitation.',
						'en' => 'Public visibility ACTIVE: the badge and the ranking of results have been live since the operational setting was switched on.',
						'ar' => 'الظهور العلني مُفعَّل: الشارة وترتيب النتائج مُفعَّلان منذ تشغيل الإعداد التشغيلي.',
					)
				)
				: self::label(
					array(
						'fr' => 'Visibilité publique pas encore activée : le badge et le tri sont LIVRÉS et prêts, mais le réglage d’exploitation est encore éteint. L’allumer est une action d’exploitation, jamais un changement de code.',
						'en' => 'Public visibility not switched on yet: the badge and the ranking are DELIVERED and ready, but the operational setting is still off. Switching it on is an operational action, never a code change.',
						'ar' => 'الظهور العلني غير مُفعَّل بعد: الشارة والترتيب مُنجَزان وجاهزان، لكن الإعداد التشغيلي لا يزال مُطفأً. تشغيله إجراء تشغيلي، وليس تغييرًا في البرمجة.',
					)
				),
			'reminder'           => self::label(
				array(
					'fr' => 'Une attribution impose un motif et une date de début comme de fin. Elle est tracée et peut être retirée immédiatement.',
					'en' => 'A grant requires a reason and both a start and an end date. It is logged and can be withdrawn immediately.',
					'ar' => 'يتطلّب المنح سببًا وتاريخ بداية وتاريخ نهاية. وهو مُسجَّل ويمكن سحبه فورًا.',
				)
			),
			'visibility_enabled' => $enabled,
		);
	}

	/**
	 * SE-048-R (E-4807) : prédicat de disponibilité côté thème — délègue au
	 * prédicat central dp-9 du plugin ; repli strictement identique (publié +
	 * statut métier disponible) si le plugin n'est pas chargé.
	 */
	public static function is_available( $property_id ) {
		$property_id = (int) $property_id;
		if ( $property_id < 1 ) {
			return false;
		}
		if ( class_exists( '\\Partikulier\\Core\\ListingRepository' ) ) {
			return (bool) \Partikulier\Core\ListingRepository::is_available( $property_id );
		}
		$post = get_post( $property_id );
		if ( ! $post instanceof WP_Post ) {
			return false;
		}
		return 'publish' === $post->post_status
			&& in_array( (string) get_post_meta( $property_id, '_pk_status', true ), array( '', 'actif' ), true );
	}

	/**
	 * Le droit premium est-il VISIBLE publiquement pour cette annonce ?
	 * Délégation exclusive au service du plugin quand il est chargé : une seule
	 * vérité (drapeau ∧ droit actif ∧ annonce disponible). Sans le plugin, le
	 * thème répond faux — aucune visibilité premium sans le domaine propriétaire.
	 */
	public static function is_publicly_visible( $property_id ) {
		if ( self::core_premium() ) {
			return (bool) self::core_premium()::is_publicly_visible( (int) $property_id );
		}
		return false;
	}


	public static function init() {
			add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
			add_action( 'admin_post_pk_grant_premium', array( __CLASS__, 'handle_grant' ) );
			add_action( 'admin_post_pk_revoke_premium', array( __CLASS__, 'handle_revoke' ) );
	}

	public static function register_menu() {
			// SE-048-U (E-4803) : parent réel = menu top-level « partikulier » du
				// thème (le CDC offre « menu Estatik ou top-level » ; le menu du
				// thème est un parent premier, sans dépendance au plugin Estatik
				// — et l'URL canonique devient admin.php?page=pk-premium).
				add_submenu_page(
						'partikulier',
					__( 'Annonces premium', 'partikulier' ),
					__( 'Annonces premium', 'partikulier' ),
					'manage_options',
					'pk-premium',
					array( __CLASS__, 'render_admin_page' )
			);
	}

	public static function handle_grant() {
			self::require_admin();
			check_admin_referer( 'pk_grant_premium' );
			$result = self::grant(
					absint( $_POST['property_id'] ?? 0 ),
					get_current_user_id(),
					wp_unslash( $_POST['selection_reason'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- garde cap+nonce, casts + persistance préparée côté service (SE-020)
					wp_unslash( $_POST['starts_at'] ?? '' ), // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- garde cap+nonce, casts + persistance préparée côté service (SE-020)
					wp_unslash( $_POST['ends_at'] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- garde cap+nonce, casts + persistance préparée côté service (SE-020)
			);
			self::redirect_after_update( $result, 'granted' );
	}

	public static function handle_revoke() {
			self::require_admin();
			check_admin_referer( 'pk_revoke_premium' );
			$result = self::revoke(
					absint( $_POST['property_id'] ?? 0 ),
					get_current_user_id(),
					wp_unslash( $_POST['revocation_reason'] ?? '' ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- garde cap+nonce, casts + persistance préparée côté service (SE-020)
			);
			self::redirect_after_update( $result, 'revoked' );
	}

		/**
		 * Lot B1 : le plugin est-il propriétaire du domaine premium ?
		 * (lot F : oui — conditionne chaque délégation).
		 */
	private static function core_premium() {
			return class_exists( '\Partikulier\Core\Domain\Premium\PremiumService' )
					? '\Partikulier\Core\Domain\Premium\PremiumService'
					: null;
	}

		/**
		 * Nom canonique du journal premium — délégation au service propriétaire
		 * (probe contractuelle PREM : l'égalité thème/plugin fait foi).
		 */
	public static function table_name() {
			return self::core_premium() ? self::core_premium()::table_name() : '';
	}

	private static function recent_rows() {
		if ( self::core_premium() ) {
				return self::core_premium()::recent_rows();
		}
			return array();
	}

	private static function require_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
				wp_die( esc_html__( 'Accès non autorisé.', 'partikulier' ), 403 );
		}
	}

	private static function redirect_after_update( $result, $status ) {
			$redirect = admin_url( 'admin.php?page=pk-premium' ); // SE-048-U : URL canonique du nouveau parent
		if ( is_wp_error( $result ) ) {
				$redirect = add_query_arg( 'pk_premium_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
				$redirect = add_query_arg( 'pk_premium_updated', $status, $redirect );
		}
			wp_safe_redirect( $redirect );
			exit;
	}

		/**
		 * Le drapeau protège les listes publiques tant que la gate G3 n’est pas
		 * formellement validée dans l’administration du projet.
		 */
	public static function is_public_enabled() {
		if ( self::core_premium() ) {
				return self::core_premium()::is_public_enabled();
		}
			return false;
	}

		/**
		 * Attribue un créneau premium dans le journal — délégation au service
		 * du plugin (mêmes gardes, même traçabilité). Cette méthode n’est pas
		 * raccordée à une interface tant que les règles G3 ne sont pas validées.
		 *
		 * @param int    $property_id Identifiant Estatik.
		 * @param int    $granted_by  Administrateur ayant décidé l’attribution.
		 * @param string $reason      Motif traçable obligatoire.
		 * @param string $starts_at   Date UTC MySQL.
		 * @param string $ends_at     Date UTC MySQL.
		 * @return int|WP_Error
		 */
	public static function grant( $property_id, $granted_by, $reason, $starts_at, $ends_at ) {
		if ( self::core_premium() ) {
				return self::core_premium()::grant( (int) $property_id, (int) $granted_by, (string) $reason, (string) $starts_at, (string) $ends_at );
		}
			return new WP_Error( 'pk_core_required', __( 'Le journal premium exige le plugin partikulier-core.', 'partikulier' ) );
	}

		/**
		 * Vérifie l’état courant et bascule une attribution échue — délégation
		 * au service du plugin (première lecture après échéance inactive).
		 */
	public static function is_active( $property_id ) {
		if ( self::core_premium() ) {
				return self::core_premium()::is_active( (int) $property_id );
		}
			return false;
	}

	public static function expire( $property_id ) {
		if ( self::core_premium() ) {
				self::core_premium()::expire( (int) $property_id );
				return;
		}
			return;
	}

	public static function revoke( $property_id, $revoked_by, $reason ) {
		if ( self::core_premium() ) {
				return self::core_premium()::revoke( (int) $property_id, (int) $revoked_by, (string) $reason );
		}
			return new WP_Error( 'pk_core_required', __( 'Le journal premium exige le plugin partikulier-core.', 'partikulier' ) );
	}

	public static function render_admin_page() {
			self::require_admin();
			$rows = self::recent_rows();
		?>
				<div class="wrap">
						<h1><?php esc_html_e( 'Annonces premium', 'partikulier' ); ?></h1>
					<?php if ( isset( $_GET['pk_premium_updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Le journal premium a été mis à jour.', 'partikulier' ); ?></p></div><?php endif; ?>
					<?php if ( isset( $_GET['pk_premium_error'] ) ) : ?><div class="notice notice-error"><p><?php echo esc_html( sanitize_text_field( wp_unslash( $_GET['pk_premium_error'] ) ) ); ?></p></div><?php endif; ?>
								<?php
								// SE-048-R (E-4806) : le modèle de lancement est l'OCTROI GRATUIT par
								// l'administration ; le règlement hors ligne décrit par le bandeau
								// précédent n'est pas le modèle du lancement et disparaît donc.
								// Le second bandeau suit le DRAPEAU réel : il annonce la visibilité
								// active quand elle l'est, et l'état exact sinon — jamais l'inverse.
								$pk_premium_texts = self::banner_texts();
								?>
									<div class="notice notice-success inline"><p><?php echo esc_html( $pk_premium_texts['offer'] ); ?></p></div>
									<div class="notice <?php echo $pk_premium_texts['visibility_enabled'] ? 'notice-info' : 'notice-warning'; ?> inline"><p><?php echo esc_html( $pk_premium_texts['visibility'] ); ?></p></div>
						<p><?php echo esc_html( $pk_premium_texts['reminder'] ); ?></p>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
							<?php wp_nonce_field( 'pk_grant_premium' ); ?>
								<input type="hidden" name="action" value="pk_grant_premium" />
								<table class="form-table" role="presentation"><tbody>
										<tr><th scope="row"><label for="pk-premium-property"><?php esc_html_e( 'ID de l’annonce Estatik', 'partikulier' ); ?></label></th><td><input required min="1" type="number" id="pk-premium-property" name="property_id" /></td></tr>
										<tr><th scope="row"><label for="pk-premium-start"><?php esc_html_e( 'Début (UTC)', 'partikulier' ); ?></label></th><td><input required type="datetime-local" id="pk-premium-start" name="starts_at" /></td></tr>
										<tr><th scope="row"><label for="pk-premium-end"><?php esc_html_e( 'Fin (UTC)', 'partikulier' ); ?></label></th><td><input required type="datetime-local" id="pk-premium-end" name="ends_at" /></td></tr>
										<tr><th scope="row"><label for="pk-premium-reason"><?php esc_html_e( 'Motif de sélection', 'partikulier' ); ?></label></th><td><textarea required id="pk-premium-reason" name="selection_reason" rows="3" class="large-text"></textarea></td></tr>
								</tbody></table>
							<?php submit_button( __( 'Enregistrer l’attribution interne', 'partikulier' ) ); ?>
						</form>
						<h2><?php esc_html_e( 'Journal récent', 'partikulier' ); ?></h2>
						<table class="widefat striped"><thead><tr><th><?php esc_html_e( 'Annonce', 'partikulier' ); ?></th><th><?php esc_html_e( 'Statut', 'partikulier' ); ?></th><th><?php esc_html_e( 'Période UTC', 'partikulier' ); ?></th><th><?php esc_html_e( 'Motif', 'partikulier' ); ?></th><th><?php esc_html_e( 'Action', 'partikulier' ); ?></th></tr></thead><tbody>
							<?php if ( ! $rows ) : ?><tr><td colspan="5"><?php esc_html_e( 'Aucune attribution premium enregistrée.', 'partikulier' ); ?></td></tr><?php endif; ?>
							<?php foreach ( $rows as $row ) : ?><tr><td><?php echo esc_html( '#' . $row->property_id . ' — ' . get_the_title( $row->property_id ) ); ?></td><td><?php echo esc_html( $row->status ); ?></td><td><?php echo esc_html( $row->starts_at . ' → ' . $row->ends_at ); ?></td><td><?php echo esc_html( $row->selection_reason ); ?></td><td><?php if ( self::STATUS_ACTIVE === $row->status ) : ?><form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="pk_revoke_premium" /><input type="hidden" name="property_id" value="<?php echo esc_attr( $row->property_id ); ?>" /><?php wp_nonce_field( 'pk_revoke_premium' ); ?><input required type="text" name="revocation_reason" placeholder="<?php esc_attr_e( 'Motif de retrait', 'partikulier' ); ?>" /><button type="submit" class="button button-secondary"><?php esc_html_e( 'Retirer', 'partikulier' ); ?></button></form><?php endif; ?></td></tr><?php endforeach; ?>
						</tbody></table>
				</div>
				<?php
	}
}

Partikulier_Premium::init();
