<?php
/**
 * Sécurité et réglages n8n / WhatsApp pour Partikulier 6.17.
 *
 * Lot F de la refonte (CDC v1.2 — extinction finale) : le domaine
 * automatisation n8n (tables pk_automation_events, pk_n8n_hmac_audit et
 * option de réglages pk_n8n_settings) est propriété du plugin
 * partikulier-core 2.4+ depuis le lot B4 ; le VESTIGE autonome 6.17.x est
 * physiquement retiré — installation de la table d'audit, migration des
 * réglages, vérification HMAC et construction d'en-têtes signés sont
 * éteints côté thème. Chaque opération délègue exclusivement à
 * \Partikulier\Core\Domain\Automation\AutomationService, qui est requis.
 * L’ÉCRAN d'administration (rendu, nonce, redirection) reste au thème
 * (arbitrage B4 : UI au thème, politique au plugin).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_N8n_Security {
        const OPTION = 'pk_n8n_settings';

        public static function init() {
                add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
                add_action( 'admin_post_pk_save_n8n_settings', array( __CLASS__, 'save_admin' ) );
        }

        private static function core_automation() {
                return class_exists( '\Partikulier\Core\Domain\Automation\AutomationService' )
                        ? '\Partikulier\Core\Domain\Automation\AutomationService'
                        : null;
        }

        public static function env_secret() {
                if ( self::core_automation() ) {
                        return call_user_func( array( self::core_automation(), 'env_secret' ) );
                }
                return '';
        }

        public static function env_webhook() {
                if ( self::core_automation() ) {
                        return call_user_func( array( self::core_automation(), 'env_webhook' ) );
                }
                return '';
        }

        public static function settings() {
                if ( self::core_automation() ) {
                        return call_user_func( array( self::core_automation(), 'settings' ) );
                }
                return array();
        }

        public static function get( $key, $default = '' ) {
                if ( self::core_automation() ) {
                        // Lot B4 : lecture des réglages côté plugin (même
                        // priorité : environnement, puis option, puis héritage).
                        return call_user_func( array( self::core_automation(), 'get' ), $key, $default );
                }
                return $default;
        }

        public static function secret_keys() {
                if ( self::core_automation() ) {
                        return call_user_func( array( self::core_automation(), 'secret_keys' ) );
                }
                return array();
        }

        /**
         * Construit les headers d’un webhook sortant signé — délégation au
         * service du plugin (même canonique, même rotation de clés).
         *
         * @param string $method Méthode HTTP.
         * @param string $url URL complète du webhook.
         * @param string $body Corps JSON exact.
         * @return array|WP_Error
         */
        public static function outgoing_headers( $method, $url, $body ) {
                if ( self::core_automation() ) {
                        return call_user_func( array( self::core_automation(), 'outgoing_headers' ), $method, $url, $body );
                }
                return new WP_Error( 'pk_n8n_secret_missing', __( 'Secret n8n non configuré (plugin partikulier-core requis).', 'partikulier' ) );
        }

        public static function check_automation_secret( WP_REST_Request $request ) {
                if ( self::core_automation() ) {
                        // Lot B4 : la politique d’authentification (secret,
                        // HMAC, promotion enforce, mode log, audit des échecs)
                        // vit côté plugin.
                        return call_user_func( array( self::core_automation(), 'check_automation_secret' ), $request );
                }
                return new WP_Error( 'pk_automation_auth', __( 'Requête non autorisée.', 'partikulier' ), array( 'status' => 401 ) );
        }

        public static function audit_failure( $key_id, $reason ) {
                if ( self::core_automation() ) {
                        // Lot B4 : l’audit des échecs HMAC écrit la table du
                        // domaine côté plugin (même upsert, même plafond).
                        return call_user_func( array( self::core_automation(), 'audit_failure' ), $key_id, $reason );
                }
                return null;
        }

        public static function admin_menu() {
                add_management_page( __( 'Réglages n8n', 'partikulier' ), __( 'Réglages n8n', 'partikulier' ), 'manage_options', 'pk-n8n-settings', array( __CLASS__, 'render_admin' ) );
        }

        public static function save_admin() {
                if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( 'pk_save_n8n_settings' ) ) {
                        wp_die( esc_html__( 'Accès non autorisé.', 'partikulier' ), 403 );
                }
                $posted = wp_unslash( $_POST['pk_n8n'] ?? array() );
                if ( self::core_automation() ) {
                        // Lot B4 : validation et enregistrement délégués au
                        // service (même garde de robustesse du secret, même
                        // assainissement) — l’UI (nonce, redirection, wp_die)
                        // reste au thème.
                        $saved = call_user_func( array( self::core_automation(), 'save_admin_settings' ), is_array( $posted ) ? $posted : array() );
                        if ( is_wp_error( $saved ) ) {
                                wp_die( esc_html__( $saved->get_error_message(), 'partikulier' ), 400 );
                        }
                        wp_safe_redirect( add_query_arg( array( 'page' => 'pk-n8n-settings', 'updated' => 1 ), admin_url( 'tools.php' ) ) );
                        exit;
                }
                wp_die( esc_html__( 'Les réglages n8n exigent le plugin partikulier-core.', 'partikulier' ), 503 );
        }

        public static function render_admin() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'Accès non autorisé.', 'partikulier' ), 403 );
                }
                $s = self::settings();
                $has_env = (bool) ( self::env_secret() || self::env_webhook() );
                ?>
                        <div class="wrap"><h1><?php esc_html_e( 'Partikulier — Réglages n8n', 'partikulier' ); ?></h1>
                        <?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success"><p><?php esc_html_e( 'Réglages enregistrés.', 'partikulier' ); ?></p></div><?php endif; ?>
                        <p><?php echo $has_env ? esc_html__( 'Une partie de la configuration est fournie par l’environnement et n’est pas éditable ici.', 'partikulier' ) : esc_html__( 'Les secrets sont masqués et ne sont jamais réaffichés.', 'partikulier' ); ?></p>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>"><input type="hidden" name="action" value="pk_save_n8n_settings"><?php wp_nonce_field( 'pk_save_n8n_settings' ); ?>
                        <table class="form-table"><tr><th><?php esc_html_e( 'Webhook n8n', 'partikulier' ); ?></th><td><input class="regular-text" type="url" name="pk_n8n[n8n_webhook_url]" value="<?php echo $has_env ? '' : esc_attr( $s['n8n_webhook_url'] ?? '' ); ?>" <?php disabled( $has_env ); ?>></td></tr>
                        <tr><th><?php esc_html_e( 'Secret', 'partikulier' ); ?></th><td><code>••••••••••••••••</code> <input type="password" name="pk_n8n[automation_api_secret]" value="" autocomplete="new-password" placeholder="<?php esc_attr_e( 'Remplacer uniquement', 'partikulier' ); ?>"></td></tr>
                        <tr><th><?php esc_html_e( 'Mode HMAC', 'partikulier' ); ?></th><td><select name="pk_n8n[hmac_mode]"><?php foreach ( array( 'off', 'log', 'enforce' ) as $mode ) : ?><option value="<?php echo esc_attr( $mode ); ?>" <?php selected( $s['hmac_mode'] ?? 'off', $mode ); ?>><?php echo esc_html( $mode ); ?></option><?php endforeach; ?></select></td></tr>
                        <tr><th><?php esc_html_e( 'Quota / jour', 'partikulier' ); ?></th><td><input type="number" min="1" max="10" name="pk_n8n[quota_per_day]" value="<?php echo absint( $s['quota_per_day'] ?? 2 ); ?>"></td></tr>
                        <tr><th><?php esc_html_e( 'Consentement WhatsApp', 'partikulier' ); ?></th><td><textarea name="pk_n8n[consent_text]" rows="4" class="large-text"><?php echo esc_textarea( $s['consent_text'] ?? '' ); ?></textarea></td></tr>
                        <tr><th><?php esc_html_e( 'Canal WhatsApp', 'partikulier' ); ?></th><td><input class="regular-text" type="url" name="pk_n8n[channel_url]" value="<?php echo esc_attr( $s['channel_url'] ?? '' ); ?>"></td></tr></table><p><button class="button button-primary"><?php esc_html_e( 'Enregistrer', 'partikulier' ); ?></button></p></form></div>
                <?php
        }
}

Partikulier_N8n_Security::init();
