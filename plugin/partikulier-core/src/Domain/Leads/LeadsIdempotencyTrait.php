<?php
/**
 * Atomic provider-message ID registry shared by lead REST endpoints.
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Leads;

trait LeadsIdempotencyTrait
{

	/** Read a provider event ID without coercing arrays/objects or truncating it. */
	private static function provider_message_id( \WP_REST_Request $request ): string
	{
		$value = $request->get_param('provider_message_id');
		if ( ! is_string($value) ) {
			return '';
		}
		$value = trim(sanitize_text_field($value));
		return '' !== $value && strlen($value) <= 191 ? $value : '';
	}

	/**
	 * Atomically claim a provider message inside the caller's transaction.
	 * false means an identical message was already consumed; a reused ID with
	 * different lead/action is a 409 conflict.
	 *
	 * @return bool|\WP_Error
	 */
	private static function claim_provider_message( string $message_id, int $lead_id, string $message_type )
	{
		global $wpdb;
		$table = self::table('pk_whatsapp_messages');
		if ( false !== $wpdb->insert($table, [
			'provider_message_id' => $message_id,
			'lead_id'             => $lead_id,
			'direction'           => 'inbound',
			'message_type'        => $message_type,
			'created_at'          => current_time('mysql', true),
		], ['%s', '%d', '%s', '%s', '%s']) ) {
			return true;
		}

		$existing = $wpdb->get_row($wpdb->prepare(
			"SELECT lead_id, message_type FROM {$table} WHERE provider_message_id = %s LIMIT 1 FOR UPDATE",
			$message_id
		), ARRAY_A);
		if ( is_array($existing) ) {
			if ( (int) $existing['lead_id'] === $lead_id && hash_equals((string) $existing['message_type'], $message_type) ) {
				return false;
			}
			return new \WP_Error('pk_provider_message_conflict', __('Identifiant de message déjà utilisé pour une autre opération.', 'partikulier-core'), ['status' => 409]);
		}
		return new \WP_Error('pk_provider_message_storage', __('Impossible de journaliser le message.', 'partikulier-core'), ['status' => 500]);
	}


}
