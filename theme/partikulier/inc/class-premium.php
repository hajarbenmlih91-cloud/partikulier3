<?php
/**
 * Fondations internes des annonces premium Partikulier.
 *
 * Depuis le lot B1 de la refonte (CDC v1.2), ce module est une COUTURE : le
 * domaine premium (journal pk_premium_history, méta et transitions) est
 * propriété du plugin partikulier-core 2.1+. Chaque opération est déléguée
 * à \Partikulier\Core\Domain\Premium\PremiumService quand la classe existe;
 * sans le plugin, le chemin autonome historique 6.17.x est conservé à
 * l'identique (dégradation gracieuse REG-5 — les deux chemins écrivent les
 * mêmes tables avec la même logique).
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

        const DB_VERSION = '1.0.0';
        const OPTION_DB_VERSION = 'pk_premium_db_version';
        const OPTION_PUBLIC_ENABLED = 'pk_premium_public_enabled';
        const META_STATUS = '_pk_premium_status';
        const META_STARTS_AT = '_pk_premium_starts_at';
        const META_ENDS_AT = '_pk_premium_ends_at';
        const STATUS_ACTIVE = 'active';
        const STATUS_EXPIRED = 'expired';
        const STATUS_REVOKED = 'revoked';

        public static function init() {
                add_action( 'init', array( __CLASS__, 'maybe_install' ), 5 );
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
         */
        private static function core_premium() {
                return class_exists( '\Partikulier\Core\Domain\Premium\PremiumService' )
                        ? '\Partikulier\Core\Domain\Premium\PremiumService'
                        : null;
        }

        /**
         * Crée le journal des attributions premium. Les données d’un propriétaire
         * sont référencées par son identifiant WordPress, jamais dupliquées en clair.
         * Lot B1 : sans délégation possible, le chemin historique reste (le plugin
         * installe et administre seul sinon).
         */
        public static function maybe_install() {
                if ( self::core_premium() ) {
                        return;
                }
                if ( self::DB_VERSION === get_option( self::OPTION_DB_VERSION ) ) {
                        return;
                }

                global $wpdb;
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $table = self::table_name();
                $charset = $wpdb->get_charset_collate();

                dbDelta( "CREATE TABLE {$table} (
                        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                        property_id bigint(20) unsigned NOT NULL,
                        owner_id bigint(20) unsigned NOT NULL,
                        status varchar(16) NOT NULL,
                        selection_reason text NOT NULL,
                        granted_by bigint(20) unsigned NOT NULL,
                        granted_at datetime NOT NULL,
                        starts_at datetime NOT NULL,
                        ends_at datetime NOT NULL,
                        revoked_by bigint(20) unsigned NOT NULL DEFAULT 0,
                        revoked_at datetime NULL,
                        revocation_reason text NULL,
                        PRIMARY KEY  (id),
                        KEY property_status (property_id,status),
                        KEY status_ends_at (status,ends_at),
                        KEY owner_status (owner_id,status)
                ) {$charset};" );

                update_option( self::OPTION_DB_VERSION, self::DB_VERSION, false );
                if ( false === get_option( self::OPTION_PUBLIC_ENABLED, false ) ) {
                        add_option( self::OPTION_PUBLIC_ENABLED, '0', '', false );
                }
        }

        public static function table_name() {
                if ( self::core_premium() ) {
                        return self::core_premium()::table_name();
                }
                global $wpdb;
                return $wpdb->prefix . 'pk_premium_history';
        }

        private static function recent_rows() {
                if ( self::core_premium() ) {
                        return self::core_premium()::recent_rows();
                }
                global $wpdb;
                return $wpdb->get_results( 'SELECT * FROM ' . self::table_name() . ' ORDER BY granted_at DESC, id DESC LIMIT 50' );
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
                return '1' === (string) get_option( self::OPTION_PUBLIC_ENABLED, '0' );
        }

        /**
         * Attribue un créneau premium dans le journal. Cette méthode n’est pas
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
                $property_id = absint( $property_id );
                $granted_by = absint( $granted_by );
                $reason = sanitize_textarea_field( $reason );
                $starts_at = self::normalize_datetime( $starts_at );
                $ends_at = self::normalize_datetime( $ends_at );

                if ( ! $property_id || PARTIKULIER_ESTATIK_POST_TYPE !== get_post_type( $property_id ) ) {
                        return new WP_Error( 'pk_premium_property', __( 'Annonce premium invalide.', 'partikulier' ) );
                }
                if ( ! $granted_by || ! user_can( $granted_by, 'manage_options' ) ) {
                        return new WP_Error( 'pk_premium_permission', __( 'Autorisation premium insuffisante.', 'partikulier' ) );
                }
                if ( ! $reason ) {
                        return new WP_Error( 'pk_premium_reason', __( 'Un motif de sélection est obligatoire.', 'partikulier' ) );
                }
                if ( ! $starts_at || ! $ends_at || strtotime( $ends_at . ' UTC' ) <= strtotime( $starts_at . ' UTC' ) ) {
                        return new WP_Error( 'pk_premium_dates', __( 'La période premium est invalide.', 'partikulier' ) );
                }

                global $wpdb;
                $owner_id = (int) get_post_field( 'post_author', $property_id );
                $now = current_time( 'mysql', true );
                $inserted = $wpdb->insert(
                        self::table_name(),
                        array(
                                'property_id' => $property_id,
                                'owner_id' => $owner_id,
                                'status' => self::STATUS_ACTIVE,
                                'selection_reason' => $reason,
                                'granted_by' => $granted_by,
                                'granted_at' => $now,
                                'starts_at' => $starts_at,
                                'ends_at' => $ends_at,
                        ),
                        array( '%d', '%d', '%s', '%s', '%d', '%s', '%s', '%s' )
                );
                if ( false === $inserted ) {
                        return new WP_Error( 'pk_premium_storage', __( 'Impossible d’enregistrer l’attribution premium.', 'partikulier' ) );
                }

                update_post_meta( $property_id, self::META_STATUS, self::STATUS_ACTIVE );
                update_post_meta( $property_id, self::META_STARTS_AT, $starts_at );
                update_post_meta( $property_id, self::META_ENDS_AT, $ends_at );

                return (int) $wpdb->insert_id;
        }

        /**
         * Vérifie l’état courant et bascule une attribution échue sans dépendre de
         * WP-Cron : la première lecture après échéance la rend immédiatement inactive.
         */
        public static function is_active( $property_id ) {
                if ( self::core_premium() ) {
                        return self::core_premium()::is_active( (int) $property_id );
                }
                $property_id = absint( $property_id );
                if ( self::STATUS_ACTIVE !== get_post_meta( $property_id, self::META_STATUS, true ) ) {
                        return false;
                }
                $ends_at = (string) get_post_meta( $property_id, self::META_ENDS_AT, true );
                if ( ! $ends_at || strtotime( $ends_at . ' UTC' ) <= time() ) {
                        self::expire( $property_id );
                        return false;
                }
                return true;
        }

        public static function expire( $property_id ) {
                if ( self::core_premium() ) {
                        self::core_premium()::expire( (int) $property_id );
                        return;
                }
                self::close_current( $property_id, self::STATUS_EXPIRED, 0, __( 'Expiration automatique.', 'partikulier' ) );
        }

        public static function revoke( $property_id, $revoked_by, $reason ) {
                if ( self::core_premium() ) {
                        return self::core_premium()::revoke( (int) $property_id, (int) $revoked_by, (string) $reason );
                }
                $revoked_by = absint( $revoked_by );
                if ( ! $revoked_by || ! user_can( $revoked_by, 'manage_options' ) ) {
                        return new WP_Error( 'pk_premium_permission', __( 'Autorisation premium insuffisante.', 'partikulier' ) );
                }
                if ( ! sanitize_textarea_field( $reason ) ) {
                        return new WP_Error( 'pk_premium_reason', __( 'Un motif de retrait est obligatoire.', 'partikulier' ) );
                }
                self::close_current( $property_id, self::STATUS_REVOKED, $revoked_by, $reason );
                return true;
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

        private static function close_current( $property_id, $status, $actor_id, $reason ) {
                global $wpdb;
                $property_id = absint( $property_id );
                $wpdb->query(
                        $wpdb->prepare(
                                'UPDATE ' . self::table_name() . ' SET status = %s, revoked_by = %d, revoked_at = %s, revocation_reason = %s WHERE property_id = %d AND status = %s',
                                $status,
                                absint( $actor_id ),
                                current_time( 'mysql', true ),
                                sanitize_textarea_field( $reason ),
                                $property_id,
                                self::STATUS_ACTIVE
                        )
                );
                update_post_meta( $property_id, self::META_STATUS, $status );
        }

        private static function normalize_datetime( $value ) {
                $value = trim( (string) $value );
                $timestamp = strtotime( $value . ' UTC' );
                return $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) : '';
        }
}

Partikulier_Premium::init();