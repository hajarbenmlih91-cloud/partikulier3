<?php
declare(strict_types=1);

namespace Partikulier\Core;

final class AuditLogger
{
	public function record( string $action, string $objectType, ?int $objectId = null, array $metadata = [] ): string
	{
		global $wpdb;
		$correlation  = wp_generate_uuid4();
		$safeMetadata = $this->removeSecrets($metadata);
		$wpdb->insert(
			$wpdb->prefix . 'pk_audit_log',
			[
				'correlation_id' => $correlation,
				'actor_user_id'  => get_current_user_id() ?: null,
				'action'         => sanitize_key($action),
				'object_type'    => sanitize_key($objectType),
				'object_id'      => $objectId,
				'metadata_json'  => wp_json_encode($safeMetadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
				'created_at'     => gmdate('Y-m-d H:i:s'),
			],
			['%s', '%d', '%s', '%s', '%d', '%s', '%s']
		);
		return $correlation;
	}

	private function removeSecrets( array $metadata ): array
	{
		return $this->removeSecretsRecursively($metadata);
	}

	/** Remove sensitive fields at every nesting depth before JSON encoding. */
	private function removeSecretsRecursively( array $metadata ): array
	{
		foreach ( array_keys($metadata) as $key ) {
			if ( preg_match('/secret|token|signature|authorization|password|passwd|passphrase|nonce|credential|cookie|api.?key|private.?key/i', (string) $key) ) {
				unset($metadata[ $key ]);
				continue;
			}

			$value = $metadata[ $key ];
			if ( is_object($value) ) {
				$value = (array) $value;
			}
			if ( is_array($value) ) {
				$metadata[ $key ] = $this->removeSecretsRecursively($value);
			}
		}
		return $metadata;
	}
}
