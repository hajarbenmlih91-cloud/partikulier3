<?php
/**
 * Politique du domaine automatisation n8n (lot B4) : réglages (option
 * sérialisée, migration par nom littéral), clés et garde secret ≥ 32
 * octets, tables de nommage, migration idempotente au premier hit.
 *
 * Lot D (découpage, arbitrage commanditaire « Référence + plugin », CDC
 * v1.2 annexe C) : le service AutomationService (467 lignes, code porté par la campagne
 * au lot B4) est découpé en shell + traits sur le précédent B6
 * (class-localization.php 990 → 199 l.) — les méthodes sont déplacées
 * VERBATIM, l'API publique et les hooks restent portés par la classe shell
 * (le trait compose la même classe : aucune délégation, aucun changement de
 * mécanisme actif). Preuve : contrat module-perimeter-contract.php + rejeu
 * intégral des suites du domaine (oracle inchangé).
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Automation;

trait AutomationPolicyTrait
{


	/* ------------------------------------------------------------------ */
	/* Tableaux de nommage                                                */
	/* ------------------------------------------------------------------ */

	public static function events_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'pk_automation_events';
	}


	public static function audit_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'pk_n8n_hmac_audit';
	}


	/**
	 * Maximum accepted automation request body. The 1 MiB default leaves
	 * room for ordinary provider metadata; deployments can tune the filter
	 * after inspecting their real payloads, within a hard 64 KiB–10 MiB range.
	 */
	public static function max_hmac_body_bytes(): int
	{
		$configured = apply_filters('partikulier_automation_max_hmac_body_bytes', self::MAX_HMAC_BODY_BYTES);
		$limit = is_numeric($configured) ? (int) $configured : self::MAX_HMAC_BODY_BYTES;
		return max(65536, min(10485760, $limit));
	}


	/* ------------------------------------------------------------------ */
	/* Réglages (port fidèle de Partikulier_N8n_Security)                 */
	/* ------------------------------------------------------------------ */

	public static function env_secret(): string
	{
		if ( defined('PARTIKULIER_N8N_SECRET') && PARTIKULIER_N8N_SECRET ) {
			return (string) PARTIKULIER_N8N_SECRET;
		}
		$value = getenv('PARTIKULIER_N8N_SECRET');
		return false !== $value && '' !== $value ? (string) $value : '';
	}


	public static function env_key_id(): string
	{
		$value = defined('PARTIKULIER_N8N_KEY_ID') && PARTIKULIER_N8N_KEY_ID
			? (string) PARTIKULIER_N8N_KEY_ID
			: (string) (getenv('PARTIKULIER_N8N_KEY_ID') ?: 'env');
		return preg_match('/^[A-Za-z0-9_-]{1,64}$/', $value) ? $value : 'env';
	}


	public static function env_webhook(): string
	{
		if ( defined('PARTIKULIER_N8N_WEBHOOK_URL') && PARTIKULIER_N8N_WEBHOOK_URL ) {
			return (string) PARTIKULIER_N8N_WEBHOOK_URL;
		}
		$value = getenv('PARTIKULIER_N8N_WEBHOOK_URL');
		return false !== $value && '' !== $value ? (string) $value : '';
	}


	public static function settings(): array
	{
		$value = get_option(self::SETTINGS_OPTION, []);
		return is_array($value) ? $value : [];
	}


	public static function get( $key, $default = '' )
	{
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
		if ( array_key_exists($key, $settings) && '' !== (string) $settings[ $key ] ) {
			return $settings[ $key ];
		}
		if ( 'completed' !== get_option(self::MIGRATION_OPTION, '') ) {
			$legacy = get_option(self::LEGACY_SETTINGS_OPTION, []);
			if ( is_array($legacy) && array_key_exists($key, $legacy) ) {
				return $legacy[ $key ];
			}
		}
		return $default;
	}


	public static function secret_keys(): array
	{
		$settings  = self::settings();
		$active    = self::env_secret() ?: (string) ( $settings['automation_api_secret'] ?? '' );
		$active_id = self::env_secret() ? self::env_key_id() : (string) ( $settings['active_key_id'] ?? 'N' );
		$keys      = [$active_id => $active];
		$previous_expiry = strtotime((string) ($settings['previous_expires_at'] ?? ''));
		if ( ! empty($settings['previous_secret']) && ! empty($settings['previous_key_id']) && $previous_expiry > time() ) {
			$keys[ (string) $settings['previous_key_id'] ] = (string) $settings['previous_secret'];
		} elseif ( ! empty($settings['previous_secret']) || ! empty($settings['previous_key_id']) || ! empty($settings['previous_expires_at']) ) {
			// Expiration revokes and removes the old key, not just its acceptance path.
			unset($settings['previous_secret'], $settings['previous_key_id'], $settings['previous_expires_at'], $settings['pending_environment_key_id']);
			update_option(self::SETTINGS_OPTION, $settings, false);
		}
		return array_filter($keys);
	}


	/**
	 * Migration unique des réglages n8n depuis les options historiques du
	 * thème (port fidèle — état pending/verified/completed, retrait des
	 * clés migrées de l'option source une fois vérifié). L'exécution est
	 * idempotente : l'état « completed » court-circuite.
	 */
	public static function maybe_migrate(): void
	{
		$state = (string) get_option(self::MIGRATION_OPTION, '');
		if ( 'completed' === $state ) {
			return;
		}
		$current = get_option(self::SETTINGS_OPTION, null);
		if ( ! is_array($current) ) {
			$current = [];
		}
		$legacy = get_option(self::LEGACY_SETTINGS_OPTION, []);
		if ( ! is_array($legacy) ) {
			$legacy = [];
		}
		if ( 'pending' !== $state ) {
			update_option(self::MIGRATION_OPTION, 'pending', false);
		}
		foreach ( self::MIGRATED_KEYS as $key ) {
			if ( ! array_key_exists($key, $current) && array_key_exists($key, $legacy) ) {
				$current[ $key ] = $legacy[ $key ];
			}
		}
		$mode                     = $current['hmac_mode'] ?? 'off';
		$current['hmac_mode']     = in_array($mode, ['off', 'log', 'enforce'], true) ? $mode : 'off';
		$current['quota_per_day'] = max(1, min(10, absint($current['quota_per_day'] ?? 2) ?: 2));
		if ( ! empty($current['n8n_webhook_url']) && ! self::is_https_url( (string) $current['n8n_webhook_url']) ) {
			$current['n8n_webhook_url'] = '';
		}
		if ( ! empty($current['automation_api_secret']) ) {
			$current['automation_api_secret'] = (string) $current['automation_api_secret'];
		}
		update_option(self::SETTINGS_OPTION, $current, false);
		$verified = is_array(get_option(self::SETTINGS_OPTION, null)) && ( ! empty($current['automation_api_secret']) || self::env_secret() );
		if ( $verified ) {
			update_option(self::MIGRATION_OPTION, 'verified', false);
			foreach ( self::MIGRATED_KEYS as $key ) {
				if ( array_key_exists($key, $legacy) ) {
					unset($legacy[ $key ]);
				}
			}
			update_option(self::LEGACY_SETTINGS_OPTION, $legacy, false);
			update_option(self::MIGRATED_AT_OPTION, gmdate('c'), false);
			update_option(self::MIGRATION_OPTION, 'completed', false);
		}
	}


	/**
	 * Validation et enregistrement des réglages depuis l'écran
	 * d'administration (port fidèle de save_admin, sans l'UI : capability,
	 * nonce et redirection restent au thème). Retourne un WP_Error pour un
	 * secret trop faible (même message hérité) — l'appelant décide du
	 * rendu (wp_die).
	 *
	 * @param array $posted Champs pk_n8n déséchappés (wp_unslash déjà fait).
	 * @return true|\WP_Error
	 */
	public static function save_admin_settings( array $posted )
	{
		$current = self::settings();
		$url     = esc_url_raw( (string) ( $posted['n8n_webhook_url'] ?? '' ));
		if ( $url && ! self::is_https_url($url) ) {
			$url = '';
		}
		$current['n8n_webhook_url'] = $url;
		$mode                     = (string) ( $posted['hmac_mode'] ?? 'off' );
		$current['hmac_mode']     = in_array( $mode, ['off', 'log', 'enforce'], true) ? $mode : 'off';
		$current['quota_per_day']   = max(1, min(10, absint($posted['quota_per_day'] ?? 2) ?: 2));
		$current['consent_text']    = sanitize_textarea_field( (string) ( $posted['consent_text'] ?? '' ));
		$current['channel_url']     = esc_url_raw( (string) ( $posted['channel_url'] ?? '' ));
		$replacement                = trim( (string) ( $posted['automation_api_secret'] ?? '' ));
		if ( $replacement ) {
			if ( self::env_secret() ) {
				return new \WP_Error('pk_n8n_secret_env_managed', __('Le secret est géré par l’environnement; effectuez sa rotation dans la configuration d’hébergement.', 'partikulier-core'));
			}
			if ( ! self::is_strong_secret($replacement) ) {
				return new \WP_Error('pk_n8n_secret_weak', __('Le secret doit contenir au moins 32 octets aléatoires encodés.', 'partikulier-core'));
			}

			$active_secret = (string) self::get('automation_api_secret', '');
			if ( '' === $active_secret || ! hash_equals($active_secret, $replacement) ) {
				$previous_expiry = strtotime((string) ($current['previous_expires_at'] ?? ''));
				if ( $previous_expiry && $previous_expiry > time() ) {
					return new \WP_Error('pk_n8n_rotation_in_progress', __('Une rotation est déjà en cours; attendez l’expiration de la clé précédente avant d’en démarrer une autre.', 'partikulier-core'));
				}
				if ( '' !== $active_secret ) {
					$current['previous_secret']     = $active_secret;
					$current['previous_key_id']    = (string) ($current['active_key_id'] ?? 'N');
					$current['previous_expires_at'] = gmdate('c', time() + self::KEY_ROTATION_GRACE_SECONDS);
				}
				$current['active_key_id'] = 'k_' . bin2hex(random_bytes(8));
				$current['automation_api_secret'] = $replacement;
			}
		}

		if ( ! empty($posted['prepare_environment_rotation']) ) {
			$environment_secret = self::env_secret();
			if ( '' === $environment_secret ) {
				return new \WP_Error('pk_n8n_environment_secret_missing', __('Aucun secret d’environnement actif à préparer.', 'partikulier-core'));
			}
			$previous_expiry = strtotime((string) ($current['previous_expires_at'] ?? ''));
			if ( $previous_expiry && $previous_expiry > time() ) {
				return new \WP_Error('pk_n8n_rotation_in_progress', __('Une rotation est déjà en cours; attendez son expiration avant d’en préparer une autre.', 'partikulier-core'));
			}
			$current['previous_secret'] = $environment_secret;
			$current['previous_key_id'] = self::env_key_id();
			$current['previous_expires_at'] = gmdate('c', time() + self::KEY_ROTATION_GRACE_SECONDS);
			$current['pending_environment_key_id'] = 'env_' . bin2hex(random_bytes(8));
		}
		update_option(self::SETTINGS_OPTION, $current, false);
		return true;
	}
}
