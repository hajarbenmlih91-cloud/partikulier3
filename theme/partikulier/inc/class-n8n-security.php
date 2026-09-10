<?php
/**
 * Sécurité et réglages n8n / WhatsApp pour Partikulier 6.17.
 *
 * Depuis le lot B4 de la refonte (CDC v1.2), ce module est une COUTURE : le
 * domaine automatisation n8n (deux tables : pk_automation_events,
 * pk_n8n_hmac_audit, plus l’option de réglages pk_n8n_settings) est propriété
 * du plugin partikulier-core 2.4+. La vérification du secret/HMAC, les en-têtes
 * signés, la migration des réglages et l’audit des échecs sont délégués à
 * \Partikulier\Core\Domain\Automation\AutomationService quand la classe
 * existe ; sans le plugin, le chemin autonome historique 6.17.x est conservé
 * à l’identique (dégradation gracieuse REG-5). L’ÉCRAN d’administration
 * (rendu, nonce, redirection) reste au thème — la validation et
 * l’enregistrement des réglages sont délégués au service (arbitrage B4 :
 * UI au thème, politique au plugin).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_N8n_Security {
        const OPTION = 'pk_n8n_settings';
        const MIGRATION_OPTION = '_pk_n8n_settings_migration_state';
        const MIGRATED_AT_OPTION = '_pk_n8n_settings_migrated_at';
        const AUDIT_DB_VERSION = '1.0.0';
        const AUDIT_DB_OPTION = 'pk_n8n_hmac_audit_db_version';
        const MAX_FAILURES_PER_HOUR = 100;

        public static function init() {
                add_action( 'init', array( __CLASS__, 'maybe_migrate' ), 5 );
                add_action( 'init', array( __CLASS__, 'maybe_install_audit_table' ), 6 );
                add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
                add_action( 'admin_post_pk_save_n8n_settings', array( __CLASS__, 'save_admin' ) );
        }

        private static function core_automation() {
                return class_exists( '\Partikulier\Core\Domain\Automation\AutomationService' )
                        ? '\Partikulier\Core\Domain\Automation\AutomationService'
                        : null;
        }

        public static function maybe_migrate() {
                if ( self::core_automation() ) {
                        // Lot B4 : la migration unique des réglages vit côté
                        // plugin (même option, même état) — le thème cesse de
                        // l’exécuter pour éviter tout double parcours.
                        return;
                }
                $state = (string) get_option( self::MIGRATION_OPTION, '' );
                if ( 'completed' === $state ) {
                        return;
                }
                $current = get_option( self::OPTION, null );
                if ( ! is_array( $current ) ) {
                        $current = array();
                }
                $legacy = get_option( Partikulier_Settings::OPTION, array() );
                if ( ! is_array( $legacy ) ) {
                        $legacy = array();
                }
                $keys = array( 'n8n_webhook_url', 'automation_api_secret', 'hmac_mode', 'consent_text', 'channel_url', 'quota_per_day' );
                if ( 'pending' !== $state ) {
                        update_option( self::MIGRATION_OPTION, 'pending', false );
                }
                foreach ( $keys as $key ) {
                        if ( ! array_key_exists( $key, $current ) && array_key_exists( $key, $legacy ) ) {
                                $current[ $key ] = $legacy[ $key ];
                        }
                }
                $mode = $current['hmac_mode'] ?? 'off';
                $current['hmac_mode'] = in_array( $mode, array( 'off', 'log', 'enforce' ), true ) ? $mode : 'off';
                $current['quota_per_day'] = max( 1, min( 10, absint( $current['quota_per_day'] ?? 2 ) ?: 2 ) );
                if ( ! empty( $current['n8n_webhook_url'] ) && ! self::is_https_url( $current['n8n_webhook_url'] ) ) {
                        $current['n8n_webhook_url'] = '';
                }
                if ( ! empty( $current['automation_api_secret'] ) ) {
                        $current['automation_api_secret'] = (string) $current['automation_api_secret'];
                }
                update_option( self::OPTION, $current, false );
                $verified = is_array( get_option( self::OPTION, null ) ) && ( ! empty( $current['automation_api_secret'] ) || self::env_secret() );
                if ( $verified ) {
                        update_option( self::MIGRATION_OPTION, 'verified', false );
                        foreach ( $keys as $key ) {
                                if ( array_key_exists( $key, $legacy ) ) {
                                        unset( $legacy[ $key ] );
                                }
                        }
                        update_option( Partikulier_Settings::OPTION, $legacy, false );
                        update_option( self::MIGRATED_AT_OPTION, gmdate( 'c' ), false );
                        update_option( self::MIGRATION_OPTION, 'completed', false );
                }
        }

        public static function env_secret() {
                if ( self::core_automation() ) {
                        return call_user_func( array( self::core_automation(), 'env_secret' ) );
                }
                if ( defined( 'PARTIKULIER_N8N_SECRET' ) && PARTIKULIER_N8N_SECRET ) {
                        return (string) PARTIKULIER_N8N_SECRET;
                }
                $value = getenv( 'PARTIKULIER_N8N_SECRET' );
                return false !== $value && '' !== $value ? (string) $value : '';
        }

        public static function env_webhook() {
                if ( self::core_automation() ) {
                        return call_user_func( array( self::core_automation(), 'env_webhook' ) );
                }
                if ( defined( 'PARTIKULIER_N8N_WEBHOOK_URL' ) && PARTIKULIER_N8N_WEBHOOK_URL ) {
                        return (string) PARTIKULIER_N8N_WEBHOOK_URL;
                }
                $value = getenv( 'PARTIKULIER_N8N_WEBHOOK_URL' );
                return false !== $value && '' !== $value ? (string) $value : '';
        }

        public static function settings() {
                if ( self::core_automation() ) {
                        return call_user_func( array( self::core_automation(), 'settings' ) );
                }
                $value = get_option( self::OPTION, array() );
                return is_array( $value ) ? $value : array();
        }

        public static function get( $key, $default = '' ) {
                if ( self::core_automation() ) {
                        // Lot B4 : lecture des réglages côté plugin (même
                        // priorité : environnement, puis option, puis héritage).
                        return call_user_func( array( self::core_automation(), 'get' ), $key, $default );
                }
                $env_secret = self::env_secret();
                if ( 'automation_api_secret' === $key && $env_secret ) {
                        return $env_secret;
                }
                if ( 'n8n_webhook_url' === $key ) {
                        $env_webhook = self::env_webhook();
                        if ( $env_webhook ) {
                                return $env_webhook;
                        }
                }
                $settings = self::settings();
                if ( array_key_exists( $key, $settings ) && '' !== (string) $settings[ $key ] ) {
                        return $settings[ $key ];
                }
                if ( 'completed' !== get_option( self::MIGRATION_OPTION, '' ) ) {
                        $legacy = get_option( Partikulier_Settings::OPTION, array() );
                        if ( is_array( $legacy ) && array_key_exists( $key, $legacy ) ) {
                                return $legacy[ $key ];
                        }
                }
                return $default;
        }

        public static function secret_keys() {
                if ( self::core_automation() ) {
                        return call_user_func( array( self::core_automation(), 'secret_keys' ) );
                }
                $settings = self::settings();
                $active = self::env_secret() ?: (string) ( $settings['automation_api_secret'] ?? '' );
                $active_id = self::env_secret() ? 'env' : (string) ( $settings['active_key_id'] ?? 'N' );
                $keys = array( $active_id => $active );
                if ( ! empty( $settings['previous_secret'] ) && ! empty( $settings['previous_key_id'] ) && strtotime( (string) ( $settings['previous_expires_at'] ?? '' ) ) > time() ) {
                        $keys[ (string) $settings['previous_key_id'] ] = (string) $settings['previous_secret'];
                }
                return array_filter( $keys );
        }

        /**
         * Construit les headers d’un webhook sortant signé.
         * Le corps exact et le chemin du webhook entrent dans la signature afin
         * qu’un nœud n8n puisse rejeter une requête rejouée ou altérée.
         *
         * @param string $method Méthode HTTP.
         * @param string $url URL complète du webhook.
         * @param string $body Corps JSON exact.
         * @return array|WP_Error
         */
        public static function outgoing_headers( $method, $url, $body ) {
                if ( self::core_automation() ) {
                        // Lot B4 : construction des en-têtes signés côté
                        // plugin (même canonique, même rotation de clés).
                        return call_user_func( array( self::core_automation(), 'outgoing_headers' ), $method, $url, $body );
                }
                $keys = self::secret_keys();
                if ( empty( $keys ) ) {
                        return new WP_Error( 'pk_n8n_secret_missing', __( 'Secret n8n non configuré.', 'partikulier' ) );
                }
                $key_id = (string) array_key_first( $keys );
                $secret = (string) $keys[ $key_id ];
                $parts = wp_parse_url( $url );
                $path = (string) ( $parts['path'] ?? '/' );
                if ( ! empty( $parts['query'] ) ) {
                        $path .= '?' . $parts['query'];
                }
                $timestamp = (string) time();
                $canonical = strtoupper( (string) $method ) . "\n" . $path . "\n" . $timestamp . "\n" . (string) $body;
                return array(
                        'Content-Type'             => 'application/json',
                        'X-Partikulier-Automation' => $secret,
                        'X-Partikulier-Timestamp'  => $timestamp,
                        'X-Partikulier-Key-Id'     => $key_id,
                        'X-Partikulier-Signature'  => 'sha256=' . hash_hmac( 'sha256', $canonical, self::hmac_key( $secret ) ),
                );
        }

        public static function check_automation_secret( WP_REST_Request $request ) {
                if ( self::core_automation() ) {
                        // Lot B4 : la politique d’authentification (secret,
                        // HMAC, promotion enforce, mode log, audit des échecs)
                        // vit côté plugin.
                        return call_user_func( array( self::core_automation(), 'check_automation_secret' ), $request );
                }
                $keys = self::secret_keys();
                $secret = self::get( 'automation_api_secret' );
                
                $provided = self::get_normalized_header( $request, 'X-Partikulier-Automation' );
                if ( ! $provided ) {
                        $authorization = self::get_normalized_header( $request, 'Authorization' );
                        if ( 0 === stripos( $authorization, 'bearer ' ) ) {
                                $provided = trim( substr( $authorization, 7 ) );
                        }
                }
                
                $shared_valid = false;
                foreach ( $keys as $candidate ) {
                        if ( $provided && hash_equals( trim((string) $candidate, '='), trim($provided, '=') ) ) {
                                $shared_valid = true;
                                break;
                        }
                }
                
                if ( ! $secret || ! $provided || ! $shared_valid ) {
                        return new WP_Error( 'pk_automation_auth', __( 'Requête non autorisée.', 'partikulier' ), array( 'status' => 401 ) );
                }
                
                $mode = self::get( 'hmac_mode', 'off' );
                if ( 'off' === $mode && '' !== (string) $secret ) {
                        /*
                         * LOT 2 — Isolation : un secret configure ne doit jamais suffire seul.
                         * Avant : mode « off » + secret => l'en-tete X-Partikulier-Automation
                         * (ou Bearer) ouvrait la route automation SANS signature. Le secret
                         * voyage dans chaque requete n8n ; le mode « off » exposait donc la
                         * route a quiconque capture le secret (journal, proxy, workflow).
                         * Desormais : secret present + mode « off » = enforce. Le mode
                         * « log » reste disponible pour un staging ou n8n ne signe pas
                         * encore (echecs journalises, requetes acceptees) ; la prod doit
                         * etre en « enforce » (GO staging != GO prod, CDC LOT 2).
                         */
                        $mode = 'enforce';
                }
                if ( 'off' === $mode ) {
                        return true;
                }
                
                $timestamp = self::get_normalized_header( $request, 'X-Partikulier-Timestamp' );
                $key_id = self::get_normalized_header( $request, 'X-Partikulier-Key-Id' );
                $signature = self::get_normalized_header( $request, 'X-Partikulier-Signature' );
                
                $valid = ctype_digit( $timestamp ) && abs( time() - (int) $timestamp ) <= 300 && preg_match( '/^sha256=[a-f0-9]{64}$/', $signature );
                $secret_for_key = $keys[ $key_id ] ?? '';
                
                if ( $valid && $secret_for_key ) {
                        $path = (string) $request->get_route();
                        $canonical = strtoupper( $request->get_method() ) . "\n" . $path . "\n" . $timestamp . "\n" . $request->get_body();
                                $expected = 'sha256=' . hash_hmac( 'sha256', $canonical, self::hmac_key( $secret_for_key ) );
                        $valid = hash_equals( $expected, $signature );
                }
                
                if ( ! $valid ) {
                        if ( 'log' === $mode ) {
                                self::audit_failure( $key_id ?: 'missing', 'invalid_signature' );
                                return true;
                        }
                        return new WP_Error( 'pk_automation_signature', __( 'Requête non autorisée.', 'partikulier' ), array( 'status' => 401 ) );
                }
                return true;
        }

        public static function audit_failure( $key_id, $reason ) {
                if ( self::core_automation() ) {
                        // Lot B4 : l’audit des échecs HMAC écrit la table du
                        // domaine côté plugin (même upsert, même plafond).
                        return call_user_func( array( self::core_automation(), 'audit_failure' ), $key_id, $reason );
                }
                global $wpdb;
                $table = self::audit_table();
                $hour = gmdate( 'Y-m-d H:00:00' );
                $wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (key_id,hour_key,failure_count,last_reason) VALUES (%s,%s,1,%s) ON DUPLICATE KEY UPDATE failure_count=LEAST(failure_count+1,%d),last_reason=VALUES(last_reason)", sanitize_key( $key_id ), $hour, sanitize_key( $reason ), self::MAX_FAILURES_PER_HOUR ) );
        }

        public static function audit_table() {
                global $wpdb;
                return $wpdb->prefix . 'pk_n8n_hmac_audit';
        }

        public static function maybe_install_audit_table() {
                if ( self::core_automation() ) {
                        // Lot B4 : le plugin détient le schéma (Schema 2.4.0,
                        // DDL à l’identique) — le thème cesse d’installer.
                        return;
                }
                if ( self::AUDIT_DB_VERSION === get_option( self::AUDIT_DB_OPTION ) ) {
                        return;
                }
                global $wpdb;
                require_once ABSPATH . 'wp-admin/includes/upgrade.php';
                $charset = $wpdb->get_charset_collate();
                dbDelta( "CREATE TABLE {$wpdb->prefix}pk_n8n_hmac_audit (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,key_id varchar(64) NOT NULL,hour_key datetime NOT NULL,failure_count int unsigned NOT NULL DEFAULT 0,last_reason varchar(64) NOT NULL,PRIMARY KEY(id),UNIQUE KEY key_hour (key_id,hour_key)) {$charset};" );
                update_option( self::AUDIT_DB_OPTION, self::AUDIT_DB_VERSION, false );
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
                $current = self::settings();
                $url = esc_url_raw( (string) ( $posted['n8n_webhook_url'] ?? '' ) );
                if ( $url && ! self::is_https_url( $url ) ) {
                        $url = '';
                }
                $current['n8n_webhook_url'] = $url;
                $current['hmac_mode'] = in_array( $posted['hmac_mode'] ?? 'off', array( 'off', 'log', 'enforce' ), true ) ? $posted['hmac_mode'] : 'off';
                $current['quota_per_day'] = max( 1, min( 10, absint( $posted['quota_per_day'] ?? 2 ) ?: 2 ) );
                $current['consent_text'] = sanitize_textarea_field( (string) ( $posted['consent_text'] ?? '' ) );
                $current['channel_url'] = esc_url_raw( (string) ( $posted['channel_url'] ?? '' ) );
                $replacement = trim( (string) ( $posted['automation_api_secret'] ?? '' ) );
                if ( $replacement ) {
                        if ( ! self::is_strong_secret( $replacement ) ) {
                                wp_die( esc_html__( 'Le secret doit contenir au moins 32 octets aléatoires encodés.', 'partikulier' ), 400 );
                        }
                        $current['automation_api_secret'] = $replacement;
                }
                update_option( self::OPTION, $current, false );
                wp_safe_redirect( add_query_arg( array( 'page' => 'pk-n8n-settings', 'updated' => 1 ), admin_url( 'tools.php' ) ) );
                exit;
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

        private static function hmac_key( $secret ) {
                $secret = trim( (string) $secret );
                $decoded = base64_decode( $secret, true );
                if ( is_string( $decoded ) && strlen( $decoded ) >= 32 ) {
                        return $decoded;
                }
                if ( preg_match( '/^[a-f0-9]{64,}$/i', $secret ) ) {
                        $hex = hex2bin( substr( $secret, 0, strlen( $secret ) - ( strlen( $secret ) % 2 ) ) );
                        if ( false !== $hex && strlen( $hex ) >= 32 ) {
                                return $hex;
                        }
                }
                return $secret;
        }

        private static function is_strong_secret( $secret ) {
                $secret = trim( (string) $secret );
                $decoded = base64_decode( $secret, true );
                $bytes = is_string( $decoded ) ? strlen( $decoded ) : 0;
                if ( $bytes < 32 && preg_match( '/^[a-f0-9]{64,}$/i', $secret ) ) {
                        $bytes = (int) ( strlen( $secret ) / 2 );
                }
                if ( $bytes < 32 || preg_match( '/^(.)\\1+$/', $secret ) ) {
                        return false;
                }
                return true;
        }

        private static function is_https_url( $url ) {
                return 'https' === strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
        }

        /**
         * Récupère un header de manière normalisée (insensible à la casse et aux séparateurs).
         */
        private static function get_normalized_header( $request, $name ) {
                $value = $request->get_header( strtolower( str_replace( '-', '_', $name ) ) );
                if ( empty( $value ) ) {
                        $value = $request->get_header( strtolower( $name ) );
                }
                return (string) $value;
        }
}

Partikulier_N8n_Security::init();
