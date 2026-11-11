<?php
/**
 * Qualification persistence and authenticated REST workflow.
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Leads;

trait LeadsQualificationTrait
{

	/**
	 * R2 : enregistre la réponse à "particulier ou intermédiaire ?"
	 * Appelé par n8n quand l'utilisateur répond. Une fois répondu, on ne
	 * redemande plus (is_particulier reste figé sauf maj manuelle WP/Sheets).
	 *
	 * @return bool
	 */
	public static function set_qualification( string $wa_id, bool $is_particulier ): bool
	{
		global $wpdb;
		$hash = hash_hmac('sha256', $wa_id, wp_salt('auth'));
		$leads = self::leads_table();
		$lead_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$leads} WHERE phone_hash = %s", $hash));
		if ( ! $lead_id ) return false;
		$updated = $wpdb->update($leads, ['is_particulier' => $is_particulier ? 1 : 0], ['id' => $lead_id]);
		if ( false === $updated ) return false;
		self::audit($is_particulier ? 'lead_qualified_particulier' : 'lead_qualified_intermediaire', 'lead', $lead_id, ['wa_id_hash' => substr($hash,0,8)]);
		return true;
	}


	/**
	 * R2 : handler REST pour n8n — POST /qualification {wa_id, is_particulier, provider_message_id}
	 */
	public static function rest_set_qualification( \WP_REST_Request $request )
	{
		$raw_wa_id = $request->get_param('wa_id');
		$wa_id = is_string($raw_wa_id) || is_int($raw_wa_id)
			? self::normalize_phone((string) $raw_wa_id)
			: '';
		if ( ! $wa_id ) {
			return new \WP_Error('pk_missing_wa_id', __('wa_id requis', 'partikulier-core'), ['status'=>400]);
		}

		$raw_qualification = $request->get_param('is_particulier');
		$is_part = self::parse_qualification_value($raw_qualification);
		if ( null === $is_part ) {
			return new \WP_Error('pk_invalid_qualification', __('Réponse de qualification invalide.', 'partikulier-core'), ['status'=>400]);
		}
		$message_id = self::provider_message_id($request);
		if ( '' === $message_id ) {
			return new \WP_Error('pk_missing_provider_message_id', __('provider_message_id requis.', 'partikulier-core'), ['status'=>400]);
		}

		global $wpdb;
		$lead_id = (int) self::lead_id_for_wa_id($wa_id);
		if ( ! $lead_id ) {
			return new \WP_Error('pk_unknown_lead', 'lead introuvable', ['status'=>404]);
		}
		if ( false === $wpdb->query('START TRANSACTION') ) {
			return new \WP_Error('pk_message_transaction', __('Impossible de démarrer la transaction.', 'partikulier-core'), ['status'=>500]);
		}
		$lead = $wpdb->get_row($wpdb->prepare('SELECT id, is_particulier FROM ' . self::leads_table() . ' WHERE id = %d FOR UPDATE', $lead_id));
		if ( ! $lead ) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('pk_unknown_lead', 'lead introuvable', ['status'=>404]);
		}
		$current_qualification = null === $lead->is_particulier ? null : (int) $lead->is_particulier;
		if ( null !== $current_qualification && $current_qualification !== ( $is_part ? 1 : 0 ) && ! current_user_can('manage_options') ) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('pk_qualification_already_set', __('La qualification est déjà définie; une modification nécessite une action administrateur.', 'partikulier-core'), ['status'=>409]);
		}
		$claimed = self::claim_provider_message($message_id, $lead_id, $is_part ? 'qualification_particulier' : 'qualification_intermediaire');
		if ( false === $claimed ) {
			$wpdb->query('ROLLBACK');
			return new \WP_REST_Response(['qualified' => $is_part ? 'particulier' : 'intermediaire', 'replayed' => true], 200);
		}
		if ( is_wp_error($claimed) ) {
			$wpdb->query('ROLLBACK');
			return $claimed;
		}
		if ( ! self::set_qualification($wa_id, $is_part) || false === $wpdb->query('COMMIT') ) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('pk_qualification_storage', __('Impossible de sauvegarder la qualification.', 'partikulier-core'), ['status'=>500]);
		}
		return new \WP_REST_Response(['qualified' => $is_part ? 'particulier' : 'intermediaire', 'replayed' => false], 200);
	}


	/**
	 * Parse only explicit boolean answers; unknown or absent input must never
	 * silently become "intermédiaire" (false).
	 */
	private static function parse_qualification_value( $value ): ?bool
	{
		if ( is_bool($value) ) {
			return $value;
		}
		if ( is_int($value) ) {
			return 1 === $value ? true : ( 0 === $value ? false : null );
		}
		if ( ! is_string($value) ) {
			return null;
		}

		$value = strtolower(trim($value));
		if ( in_array($value, ['1', 'true', 'on', 'yes', 'oui', 'particulier'], true) ) {
			return true;
		}
		if ( in_array($value, ['0', 'false', 'off', 'no', 'non', 'intermediaire', 'intermédiaire', 'agent'], true) ) {
			return false;
		}
		return null;
	}}
