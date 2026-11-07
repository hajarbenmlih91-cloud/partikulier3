<?php
/**
 * Gestionnaires REST de la voie n8n/WhatsApp (lot B2) : autorisation de
 * contact, préférences, consentement similar_listings, opt-out idempotent,
 * demande d'effacement — et handle_stop partagé avec la purge.
 *
 * Lot D (découpage, arbitrage commanditaire « Référence + plugin », CDC
 * v1.2 annexe C) : le service LeadService (778 lignes, code porté par la campagne
 * au lot B2) est découpé en shell + traits sur le précédent B6
 * (class-localization.php 990 → 199 l.) — les méthodes sont déplacées
 * VERBATIM, l'API publique et les hooks restent portés par la classe shell
 * (le trait compose la même classe : aucune délégation, aucun changement de
 * mécanisme actif). Preuve : contrat module-perimeter-contract.php + rejeu
 * intégral des suites du domaine (oracle inchangé).
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Leads;

trait LeadsRestTrait
{


	/* ------------------------------------------------------------------ */
	/* Gestionnaires REST (voie n8n — les routes restent déclarées par le  */
	/* thème via le registre unique ; les coutures y délèguent ici)        */
	/* ------------------------------------------------------------------ */

	/**
	 * Port fidèle de handle_contact_authorization (voie webhook WhatsApp/n8n).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_contact_authorization( \WP_REST_Request $request )
	{
		$raw_wa_id = $request->get_param('wa_id');
		$wa_id = is_string($raw_wa_id) || is_int($raw_wa_id)
			? self::normalize_phone((string) $raw_wa_id)
			: '';
		$raw_reference = $request->get_param('reference');
		$reference = is_string($raw_reference) ? sanitize_text_field($raw_reference) : '';
		$message_id = self::provider_message_id($request);
		if ( ! $wa_id || ! $reference || ! $message_id ) {
			return new \WP_Error('pk_missing_payload', __('wa_id, reference et provider_message_id sont requis.', 'partikulier-core'), ['status' => 400]);
		}

		$property_id = self::property_for_reference($reference);
		if ( ! $property_id || ! self::is_contactable_property($property_id) ) {
			return new \WP_REST_Response(['allowed' => false, 'reason' => 'property_unavailable'], 200);
		}

		// R2 : texte du message pour détection langue ar (regex [\x{0600}-\x{06FF}])
		$text = $request->get_param('text');
		$text = is_string($text) ? $text : '';
		if ( '' === $text ) { $message = $request->get_param('message'); $text = is_string($message) ? $message : ''; }
		if ( '' === $text ) { $body = $request->get_param('body'); $text = is_string($body) ? $body : ''; }
		return self::authorize_contact($wa_id, $property_id, $message_id, $text);
	}


	/**
	 * Port fidèle de handle_preferences.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_preferences( \WP_REST_Request $request )
	{
		$raw_wa_id = $request->get_param('wa_id');
		$wa_id = is_string($raw_wa_id) || is_int($raw_wa_id)
			? self::normalize_phone((string) $raw_wa_id)
			: '';
		$message_id = self::provider_message_id($request);
		if ( '' === $wa_id || '' === $message_id ) {
			return new \WP_Error('pk_invalid_preferences', __('wa_id et provider_message_id sont requis.', 'partikulier-core'), ['status' => 400]);
		}
		$lead_id = self::lead_id_for_wa_id($wa_id);
		if ( ! $lead_id ) {
			return new \WP_Error('pk_unknown_lead', __('Acquéreur introuvable.', 'partikulier-core'), ['status' => 404]);
		}
		global $wpdb;
		$areas = $request->get_param('areas');
		$areas = is_array($areas)
			? array_slice(array_values(array_filter(array_map(static fn( $area ): string => is_scalar($area) ? sanitize_text_field((string) $area) : '', $areas))), 0, 3)
			: [];
		$text = static function ( $value ): string {
			return is_scalar($value) ? sanitize_text_field((string) $value) : '';
		};
		$preferences = [
			'lead_id'           => $lead_id,
			'budget_max'        => is_scalar($request->get_param('budget_max')) ? absint($request->get_param('budget_max')) : 0,
			'areas'             => wp_json_encode($areas),
			'layout_value'      => $text($request->get_param('layout')),
			'transaction_value' => $text($request->get_param('transaction')),
			'source'            => 'explicit_whatsapp',
			'updated_at'        => current_time('mysql', true),
		];
		$message_type = 'preferences_' . substr(hash('sha256', (string) wp_json_encode([
			$preferences['budget_max'], $areas, $preferences['layout_value'], $preferences['transaction_value'],
		])), 0, 48);
		if ( false === $wpdb->query('START TRANSACTION') ) {
			return new \WP_Error('pk_message_transaction', __('Impossible de démarrer la transaction.', 'partikulier-core'), ['status'=>500]);
		}
		$locked = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::leads_table() . ' WHERE id = %d FOR UPDATE', $lead_id));
		if ( ! $locked ) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('pk_unknown_lead', __('Acquéreur introuvable.', 'partikulier-core'), ['status' => 404]);
		}
		$claimed = self::claim_provider_message($message_id, $lead_id, $message_type);
		if ( false === $claimed ) {
			$wpdb->query('ROLLBACK');
			return new \WP_REST_Response(['updated' => true, 'replayed' => true], 200);
		}
		if ( is_wp_error($claimed) ) {
			$wpdb->query('ROLLBACK');
			return $claimed;
		}
		if ( false === $wpdb->replace(self::table('pk_buyer_preferences'), $preferences) || false === $wpdb->query('COMMIT') ) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('pk_preferences_storage', __('Impossible de sauvegarder les préférences.', 'partikulier-core'), ['status'=>500]);
		}
		return new \WP_REST_Response(['updated' => true, 'replayed' => false], 200);
	}

	/**
	 * Port fidèle de handle_consent (scope unique similar_listings, idempotent).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_consent( \WP_REST_Request $request )
	{
		$raw_wa_id = $request->get_param('wa_id');
		$wa_id = is_string($raw_wa_id) || is_int($raw_wa_id)
			? self::normalize_phone((string) $raw_wa_id)
			: '';
		$lead_id = $wa_id ? self::lead_id_for_wa_id($wa_id) : 0;
		$raw_scope = $request->get_param('scope');
		$scope = is_string($raw_scope) ? sanitize_key($raw_scope) : '';
		$message_id = self::provider_message_id($request);
		$granted = self::parse_qualification_value($request->get_param('granted'));
		if ( ! $lead_id || self::CONSENT_SCOPE_SIMILAR !== $scope || '' === $message_id || null === $granted ) {
			return new \WP_Error('pk_invalid_consent', __('Consentement invalide.', 'partikulier-core'), ['status' => 400]);
		}
		global $wpdb;
		if ( false === $wpdb->query('START TRANSACTION') ) {
			return new \WP_Error('pk_message_transaction', __('Impossible de démarrer la transaction.', 'partikulier-core'), ['status'=>500]);
		}
		$locked = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::leads_table() . ' WHERE id = %d FOR UPDATE', $lead_id));
		if ( ! $locked ) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('pk_unknown_lead', __('Acquéreur introuvable.', 'partikulier-core'), ['status' => 404]);
		}
		$message_type = $granted ? 'consent_granted' : 'consent_revoked';
		$claimed = self::claim_provider_message($message_id, $lead_id, $message_type);
		if ( false === $claimed ) {
			$wpdb->query('ROLLBACK');
			return new \WP_REST_Response(['consent' => $granted ? 'granted' : 'revoked', 'replayed' => true], 200);
		}
		if ( is_wp_error($claimed) ) {
			$wpdb->query('ROLLBACK');
			return $claimed;
		}
		$now = current_time('mysql', true);
		$saved = $wpdb->replace(self::table('pk_whatsapp_consents'), [
			'lead_id'          => $lead_id,
			'scope'            => $scope,
			'granted_at'       => $granted ? $now : null,
			'revoked_at'       => $granted ? null : $now,
			'policy_version'   => self::CONSENT_POLICY_VERSION,
			'proof_message_id' => $message_id,
		]);
		if ( false === $saved || false === $wpdb->query('COMMIT') ) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('pk_consent_storage', __('Impossible de sauvegarder le consentement.', 'partikulier-core'), ['status'=>500]);
		}
		return new \WP_REST_Response(['consent' => $granted ? 'granted' : 'revoked', 'replayed' => false], 200);
	}

	/**
	 * Port fidèle de handle_opt_out : STOP via l'API appelée par n8n.
	 * Idempotent : un message STOP dupliqué ne peut ni rétablir le
	 * consentement ni rouvrir un lead.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_opt_out( \WP_REST_Request $request )
	{
		$raw_wa_id = $request->get_param('wa_id');
		$wa_id = is_string($raw_wa_id) || is_int($raw_wa_id)
			? self::normalize_phone((string) $raw_wa_id)
			: '';
		$message_id = self::provider_message_id($request);
		if ( '' === $wa_id || '' === $message_id ) {
			return new \WP_Error('pk_invalid_opt_out', __('wa_id et provider_message_id sont requis.', 'partikulier-core'), ['status' => 400]);
		}
		$lead_id = self::lead_id_for_wa_id($wa_id);
		if ( ! $lead_id ) {
			return new \WP_REST_Response(['processed' => true, 'known_lead' => false, 'replayed' => false], 200);
		}
		global $wpdb;
		if ( false === $wpdb->query('START TRANSACTION') ) {
			return new \WP_Error('pk_message_transaction', __('Impossible de démarrer la transaction.', 'partikulier-core'), ['status'=>500]);
		}
		$locked = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . self::leads_table() . ' WHERE id = %d FOR UPDATE', $lead_id));
		if ( ! $locked ) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('pk_unknown_lead', __('Acquéreur introuvable.', 'partikulier-core'), ['status' => 404]);
		}
		$claimed = self::claim_provider_message($message_id, $lead_id, 'opt_out');
		if ( false === $claimed ) {
			$wpdb->query('ROLLBACK');
			return new \WP_REST_Response(['processed' => true, 'known_lead' => true, 'replayed' => true], 200);
		}
		if ( is_wp_error($claimed) ) {
			$wpdb->query('ROLLBACK');
			return $claimed;
		}
		if ( ! self::handle_stop($wa_id, $message_id) || false === $wpdb->query('COMMIT') ) {
			$wpdb->query('ROLLBACK');
			return new \WP_Error('pk_opt_out_storage', __('Impossible d’enregistrer le retrait du consentement.', 'partikulier-core'), ['status'=>500]);
		}
		return new \WP_REST_Response(['processed' => true, 'known_lead' => true, 'replayed' => false], 200);
	}

	/**
	 * Port fidèle de handle_erase_request (rétention — effacement explicite
	 * demandé au canal WhatsApp/n8n). La réponse reste idempotente et ne
	 * révèle pas si un numéro était connu du système.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_erase_request( \WP_REST_Request $request )
	{
		$wa_id = preg_replace('/\D+/', '', (string) $request->get_param('wa_id'));
		if ( ! $wa_id ) {
			return new \WP_Error('pk_invalid_erase_request', __('wa_id est requis.', 'partikulier-core'), ['status' => 400]);
		}
		$lead_id = self::lead_id_for_phone($wa_id);
		if ( $lead_id ) {
			self::erase_lead($lead_id);
		}
		return new \WP_REST_Response(['erased' => true], 200);
	}


	/**
	 * Port fidèle de handle_stop (aussi appelé par la purge de rétention).
	 */
	public static function handle_stop( string $wa_id, string $message_id ): bool
	{
		$lead_id = self::lead_id_for_wa_id($wa_id);
		if ( ! $lead_id ) {
			return false;
		}
		global $wpdb;
		$now = current_time('mysql', true);
		$wpdb->update(self::leads_table(), ['opt_out_at' => $now], ['id' => $lead_id]);
		$wpdb->update(self::table('pk_whatsapp_consents'), ['revoked_at' => $now], ['lead_id' => $lead_id]);
		self::audit('lead_opted_out', 'lead', $lead_id, ['via' => 'stop_message']);
		return true;
	}
}
