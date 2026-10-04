<?php

declare(strict_types=1);

namespace Partikulier\Core\Domain\Leads;

trait LeadsIntakeTrait
{
	/**
	 * Pont REST du dispositif de leads (INTEG-2, appelé par LeadBridge).
	 *
	 * Mêmes contrôles, même plafonnement, même journalisation que la voie
	 * WhatsApp/n8n, sans distinction du canal. Le rattachement à une annonce
	 * est obligatoire ; le contexte déclaratif (nom, courriel, message, source)
	 * est conservé avec le suivi du lead (pk_lead_followups.note).
	 *
	 * @param array $input {phone, property_id|reference, message?, name?, email?}
	 * @return array|WP_Error {lead_id, property_id, reference, contact} ou refus motivé.
	 */
	public static function register_api_lead( array $input ): array|\WP_Error
	{
		$phone       = preg_replace('/\D+/', '', (string) ( $input['phone'] ?? '' ));
		$property_id = absint($input['property_id'] ?? 0);
		$reference   = sanitize_text_field( (string) ( $input['reference'] ?? '' ));
		if ( ! $phone || strlen($phone) < 8 ) {
			return new \WP_Error('invalid_lead_phone', __('Numéro de téléphone invalide.', 'partikulier-core'), ['status' => 422]);
		}
		if ( ! $property_id && $reference ) {
			$property_id = self::property_for_reference($reference);
		}
		$post = $property_id ? get_post($property_id) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error('lead_property_not_found', __('Annonce visée introuvable.', 'partikulier-core'), ['status' => 404]);
		}
		if ( ! self::is_contactable_property($property_id) ) {
			return new \WP_Error('lead_property_unavailable', __('Annonce visée non contactable.', 'partikulier-core'), ['status' => 409]);
		}

		$message_id = 'api-' . wp_generate_uuid4();
		$result     = self::authorize_contact($phone, $property_id, $message_id);
		if ( is_wp_error($result) ) {
			return $result;
		}
		$data = $result instanceof \WP_REST_Response ? (array) $result->get_data() : (array) $result;

		$reasons = [
			'property_unavailable' => ['lead_property_unavailable', 409],
			'owner_unavailable'    => ['lead_owner_unavailable', 409],
			'opted_out'            => ['lead_opted_out', 403],
			'duplicate_message'    => ['lead_duplicate', 409],
			'daily_limit'          => ['lead_daily_limit', 429],
		];
		if ( empty($data['allowed']) ) {
			$reason          = (string) ( $data['reason'] ?? 'unknown' );
			[$code, $status] = $reasons[ $reason ] ?? ['lead_refused', 409];
			return new \WP_Error($code, sprintf(__('Demande refusée : %s.', 'partikulier-core'), $reason), ['status' => $status, 'reason' => $reason]);
		}

		// Suivi du lead : contexte déclaratif du canal API, conservé avec
		// la demande (le dispositif n'a pas de colonne dédiée — pas de
		// seconde zone de stockage).
		$lead_id = (int) ( $data['lead_id'] ?? 0 );
		$context = [
			'source'      => 'rest_api',
			'name'        => sanitize_text_field( (string) ( $input['name'] ?? '' )),
			'email'       => sanitize_email( (string) ( $input['email'] ?? '' )),
			'message'     => sanitize_textarea_field( (string) ( $input['message'] ?? '' )),
			'property_id' => $property_id,
		];
		self::seed_followup($lead_id, $context);

		unset($data['lead_id']);
		return [
			'lead_id'     => $lead_id,
			'property_id' => $property_id,
			'reference'   => self::reference_for($property_id),
			'contact'     => $data,
		];
	}

	/**
	 * Crée ou met à jour la ligne de suivi d'un lead avec le contexte du
	 * canal d'entrée (pont REST) sans toucher au reste du dispositif.
	 */
	private static function seed_followup( int $lead_id, array $context ): void
	{
		if ( ! $lead_id ) {
			return;
		}
		global $wpdb;
		$followups = self::table('pk_lead_followups');
		$existing  = $wpdb->get_var($wpdb->prepare("SELECT lead_id FROM {$followups} WHERE lead_id = %d", $lead_id));
		$row       = [
			'status'     => 'new',
			'note'       => wp_json_encode($context, JSON_UNESCAPED_UNICODE),
			'updated_at' => current_time('mysql', true),
		];
		if ( $existing ) {
			$wpdb->update($followups, $row, ['lead_id' => $lead_id]);
		} else {
			$row['lead_id'] = $lead_id;
			$wpdb->insert($followups, $row);
		}
	}

	/**
	 * R2 : détecte la langue pour le message de qualification / manuel.
	 * Priorité : 1) Polylang pll_get_post_language, 2) meta _locale,
	 * 3) get_locale, 4) fr par défaut.
	 */
	private static function detect_lang_for_lead( string $wa_id, int $property_id, string $message_text = '' ): string
	{
		// 0) Contenu du message : si caractères arabes → ar (priorité, même si bien en fr)
		if ( '' !== $message_text && preg_match('/[\x{0600}-\x{06FF}]/u', $message_text) ) {
			return 'ar';
		}
		// 1) Polylang si présent
		if ( function_exists('pll_get_post_language') ) {
			$pll = (string) pll_get_post_language($property_id, 'slug');
			if ( $pll === 'ar' ) return 'ar';
			if ( $pll === 'en' ) return 'en';
			if ( $pll === 'fr' ) return 'fr';
		}
		// 2) meta _locale (test / R1)
		$locale = (string) get_post_meta($property_id, '_locale', true);
		if ( '' !== $locale ) {
			if ( str_starts_with($locale, 'ar') ) return 'ar';
			if ( str_starts_with($locale, 'en') ) return 'en';
			return 'fr';
		}
		$locale = (string) get_locale();
		if ( str_starts_with($locale, 'ar') ) return 'ar';
		if ( str_starts_with($locale, 'en') ) return 'en';
		return 'fr';
	}

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
		$wpdb->update($leads, ['is_particulier' => $is_particulier ? 1 : 0], ['id' => $lead_id]);
		// Log audit
		self::audit($is_particulier ? 'lead_qualified_particulier' : 'lead_qualified_intermediaire', 'lead', $lead_id, ['wa_id_hash' => substr($hash,0,8)]);
		return true;
	}

	/**
	 * R2 : handler REST pour n8n — POST /qualification {wa_id, is_particulier, provider_message_id}
	 */
	public static function rest_set_qualification( \WP_REST_Request $request )
	{
		$wa_id = self::normalize_phone((string) $request->get_param('wa_id'));
		$is_part = rest_sanitize_boolean($request->get_param('is_particulier'));
		// Accepte aussi "particulier"/"intermediaire" en string
		$raw = strtolower(trim((string) $request->get_param('is_particulier')));
		if ( in_array($raw, ['1','true','particulier','oui','yes'], true) ) $is_part = true;
		if ( in_array($raw, ['0','false','intermediaire','intermédiaire','agent','non'], true) ) $is_part = false;
		if ( ! $wa_id ) {
			return new \WP_Error('pk_missing_wa_id', 'wa_id requis', ['status'=>400]);
		}
		$ok = self::set_qualification($wa_id, (bool) $is_part);
		if ( ! $ok ) return new \WP_Error('pk_unknown_lead', 'lead introuvable', ['status'=>404]);
		return new \WP_REST_Response(['qualified' => $is_part ? 'particulier' : 'intermediaire'], 200);
	}
}
