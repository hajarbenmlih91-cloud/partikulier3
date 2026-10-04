<?php
/**
 * Health check 2.0 (lot A) : au-delà de l'existence des tables, il signale
 * tout écart entre le catalogue servi et le catalogue réel (INTEG-1) et
 * toute collision de routes détectée (INTEG-3).
 */

declare(strict_types=1);

namespace Partikulier\Core;

use Partikulier\Core\Database\Migrator;
use Partikulier\Core\Domain\DomainRegistry;
use Partikulier\Core\Integration\ListingSynchronizer;
use Partikulier\Core\Rest\RouteRegistry;

final class HealthCheck
{
	public function __construct()
	{
		add_filter('site_status_tests', [$this, 'registerTests']);
	}

	public function get(): array
	{
		global $wpdb;
		// Chaos/DB-down : tente un ping DB léger avant toute lecture projection.
		$db_reachable = true;
		$db_error     = '';
		try {
			// @phpstan-ignore-next-line
			$ping = $wpdb->query('SELECT 1');
			if ( false === $ping ) {
				$db_reachable = false;
				$db_error     = (string) $wpdb->last_error;
			}
		} catch ( \Throwable $e ) {
			$db_reachable = false;
			$db_error     = $e->getMessage();
		}
		$table  = $wpdb->prefix . 'pk_listings';
		$exists = false;
		if ( $db_reachable ) {
			$exists = (bool) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table));
			if ( '' !== $wpdb->last_error ) {
				$db_reachable = false;
				$db_error     = (string) $wpdb->last_error;
				$exists       = false;
			}
		}

		$integrity = ['orphans' => 0, 'missing' => 0, 'served' => 0, 'live_posts' => 0, 'status' => 'ok'];
		if ( $exists && $db_reachable ) {
			$integrity           = DomainRegistry::listingIntegrity();
			$integrity['status'] = ( $integrity['orphans'] === 0 && $integrity['missing'] === 0 ) ? 'ok' : 'degraded';
		}

		$sync    = ( new ListingSynchronizer() )->stats();
		$rebuild = get_option('partikulier_core_last_rebuild', []);
		if ( ! $db_reachable ) {
			$status = 'critical';
		} else {
			$status = ( $exists && $integrity['status'] === 'ok' ) ? 'ok' : 'degraded';
		}

		return [
			'status'         => $status,
			'core_version'   => PARTIKULIER_CORE_VERSION,
			'schema_version' => ( new Migrator() )->currentVersion(),
			'database'       => ! $db_reachable ? 'unreachable' : ( $exists ? 'ready' : 'missing' ),
			'database_error' => $db_error ?: null,
			'integrity'      => $integrity,
			'sync'           => [
				'last_flush_at' => $sync['last_flush_at'] ?? null,
				'totals'        => $sync['totals'] ?? ['flushes' => 0, 'writes' => 0],
				'last_rebuild'  => is_array($rebuild) ? ( $rebuild['rebuilt_at'] ?? null ) : null,
			],
			'routes'         => [
				'namespace'  => RouteRegistry::NAMESPACE,
				'collisions' => RouteRegistry::collisionCount(),
			],
			'domains'        => DomainRegistry::health(),
			'translation_variants' => [ // SE-054 (E-5404) : variantes fantômes (publiées sans source publique)
				'ghosts'  => \Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService::count_ghost_variants(),
			],
			'locale'         => determine_locale(),
		];
	}

	private function databaseFailure( string $error ): array
	{
		error_log('[Partikulier health] Database unavailable: ' . $error);
		return [
			'status' => 'critical',
			'core_version' => PARTIKULIER_CORE_VERSION,
			'schema_version' => null,
			'database' => 'unreachable',
			'database_error' => $error,
			'integrity' => ['orphans' => null, 'missing' => null, 'served' => null, 'live_posts' => null, 'status' => 'unknown'],
			'sync' => null,
			'routes' => ['namespace' => RouteRegistry::NAMESPACE, 'collisions' => RouteRegistry::collisionCount()],
			'domains' => [],
			'translation_variants' => ['ghosts' => null],
			'locale' => null,
		];
	}

	public function registerTests( array $tests ): array
	{
		$tests['direct']['partikulier_core'] = [
			'label' => __('Partikulier Core', 'partikulier-core'),
			'test'  => static function (): array {
				$result    = ( new self() )->get();
				$integrity = $result['integrity'];
				$healthy   = $result['status'] === 'ok';
				$detail    = sprintf(
					/* translators: 1: orphelins, 2: manquants */
					__('Projection : %1$s fantômes, %2$s manquants.', 'partikulier-core'),
					(int) $integrity['orphans'],
					(int) $integrity['missing']
				);
				return [
					'label'       => __('Schéma Partikulier', 'partikulier-core'),
					'status'      => $healthy ? 'good' : 'critical',
					'badge'       => ['label' => 'Partikulier', 'color' => $healthy ? 'green' : 'red'],
					'description' => $healthy
						? __('Le catalogue servi correspond au catalogue réel.', 'partikulier-core')
						: $detail,
					'test'        => 'partikulier_core_schema',
				];
			},
		];
		return $tests;
	}
}
