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
        const META_STATUS = '_pk_premium_status';
        const META_STARTS_AT = '_pk_premium_starts_at';
        const META_ENDS_AT = '_pk_premium_ends_at';
        const STATUS_ACTIVE = 'active';
        const STATUS_EXPIRED = 'expired';
        const STATUS_REVOKED = 'revoked';

        public static function init() {
                add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
                add_action( 'admin_post_pk_grant_premium', array( __CLASS__, 'handle_grant' ) );
                add_action( 'admin_post_pk_revoke_premium', array( __CLASS__, 'handle_revoke' ) );
        }

        public static function register_menu() {
                add_submenu_page(
                        'edit.php?post_type=' . PARTIKULIER_ESTATIK_POST_TYPE,
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
                        wp_unslash( $_POST['selection_reason'] ?? '' ),
                        wp_unslash( $_POST['starts_at'] ?? '' ),
                        wp_unslash( $_POST['ends_at'] ?? '' )
                );
                self::redirect_after_update( $result, 'granted' );
        }

        public static function handle_revoke() {
                self::require_admin();
                check_admin_referer( 'pk_revoke_premium' );
                $result = self::revoke(
                        absint( $_POST['property_id'] ?? 0 ),
                        get_current_user_id(),
                        wp_unslash( $_POST['revocation_reason'] ?? '' )
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
                $redirect = admin_url( 'edit.php?post_type=' . PARTIKULIER_ESTATIK_POST_TYPE . '&page=pk-premium' );
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
                        <div class="notice notice-warning inline"><p><?php esc_html_e( 'La visibilité publique est désactivée. L’attribution reste interne tant que les règles de durée, de plafonnement et d’activation ne sont pas validées.', 'partikulier' ); ?></p></div>
                        <p><?php esc_html_e( 'Une attribution impose un motif et une date de début comme de fin. Elle est tracée et peut être retirée immédiatement.', 'partikulier' ); ?></p>
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
