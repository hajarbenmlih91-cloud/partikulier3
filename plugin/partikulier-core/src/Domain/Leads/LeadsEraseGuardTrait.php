<?php
/**
 * SE-016 (E-1601→E-1606, campagne post-audit 2026-09) — garde de la route
 * /erase-lead : preuve de possession du secret dédié, fenêtre de transition
 * sur le secret n8n, limiteur d'échecs anti-forçage, journal d'audit.
 *
 * Contexte (P0-1, audit indépendant du 12/09/2026) : la déclaration plugin de
 * /erase-lead (RestController) vivait sans permission_callback — « confiance
 * réseau » héritée du port thème→plugin du lot B2, alors que le repli du
 * thème, lui, passait par le pont d'automatisation qui force la garde. Le
 * présent trait répare l'asymétrie : miroir de la mécanique de
 * AutomationHmacTrait::check_automation_secret (secret dédié
 * lead_erase_api_secret, en-tête X-Partikulier-Lead-Erase puis Bearer,
 * hash_equals par candidat, 401 sinon), complétée du limiteur d'échecs par
 * IP (10/heure, 429 au-delà, E-1604) et du journal de chaque tentative
 * (E-1606). Les constantes vivent dans le shell LeadService (PHP 8.1 —
 * pas de constante de trait avant 8.2) ; module dédié au sens CA-4.
 *
 * SE-022 (E-1609/E-2201, CDC v4.1 §8B) : la garde ne compte et n'audite
 * qu'une fois par cycle de requête HTTP — le core ré-exécute le
 * permission_callback via rest_send_allow_header() (rest_post_dispatch)
 * pour construire l'en-tête Allow ; le verdict de la passe réelle est
 * restitué (clé par objet requête, RequestCycle), les effets de bord ne
 * rejouent pas. 429 à la 11e requête (10 servies), audit unique par échec.
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Leads;

use Partikulier\Core\Rest\RequestCycle;

trait LeadsEraseGuardTrait
{


	/**
	 * Vérifie la preuve de possession du secret d'effacement (permission_callback
	 * de la route plugin /erase-lead). Ordre des contrôles : limiteur d'échecs
	 * d'abord (le forçage est bloqué avant même la comparaison), secret dédié
	 * ensuite, fenêtre de transition E-1603 en dernier (chaque usage du secret
	 * n8n y est journalisé comme déprécié).
	 *
	 * SE-022 (E-1609/E-2201) : idempotence par cycle de requête. La passe
	 * Allow-header (rest_send_allow_header, rest_post_dispatch) ré-exécute le
	 * permission_callback sur la MÊME instance de WP_REST_Request — le verdict
	 * de la première passe est restitué tel quel (un 401 reste un 401, un 429
	 * reste un 429 : l'en-tête Allow reflète la passe réelle) et les effets de
	 * bord (compteur d'échecs, entrées d'audit, remise à zéro) ne s'exécutent
	 * qu'une fois. rest_do_request ne déclenche jamais rest_post_dispatch :
	 * l'identité par objet fait qu'une requête fraîche compte normalement,
	 * qu'un rejeu de la même instance ne double rien (E-1610, complément).
	 *
	 * @return true|\WP_Error
	 */
	public static function check_erase_secret( \WP_REST_Request $request )
	{
		$verdict = RequestCycle::recall($request, 'erase_verdict');
		if ( $verdict !== null ) {
			return $verdict;
		}
		$verdict = self::erase_evaluate($request);
		RequestCycle::remember($request, 'erase_verdict', $verdict);
		return $verdict;
	}

	/**
	 * Évaluation réelle de la garde — une seule fois par cycle de requête
	 * (les effets de bord vivent ici : compteur, audits, remise à zéro).
	 *
	 * @return true|\WP_Error
	 */
	private static function erase_evaluate( \WP_REST_Request $request )
	{
		$ip         = self::erase_client_ip();
		$policy     = self::erase_rate_policy();
		$transition = self::erase_transition_active();
		$threshold  = $transition
			? max($policy['threshold'], self::ERASE_TRANSITION_THRESHOLD)
			: $policy['threshold'];
		$exempt     = in_array($ip, $policy['exempt_ips'], true);
		$failures   = $exempt ? 0 : self::erase_failure_count($policy['window']);

		if ( ! $exempt && $failures >= $threshold ) {
			self::audit('lead_erase_flood', 'lead', null, [
				'ip' => $ip, 'failures' => $failures, 'threshold' => $threshold, 'transition' => $transition,
			]);
			return new \WP_Error(
				'pk_erase_rate_limited',
				__('Trop de tentatives; réessayez plus tard.', 'partikulier-core'),
				['status' => 429]
			);
		}

		$provided = self::erase_provided_secret($request);
		$valid    = false;
		$via      = '';

		$dedicated = (string) get_option('lead_erase_api_secret', '');
		if ( $provided && '' !== $dedicated && hash_equals(trim($dedicated, '='), trim( (string) $provided, '=')) ) {
			$valid = true;
			$via   = 'dedicated';
		}
		if ( ! $valid && $transition ) {
			foreach ( self::erase_transition_keys() as $candidate ) {
				if ( $provided && $candidate && hash_equals(trim( (string) $candidate, '='), trim( (string) $provided, '=')) ) {
					$valid = true;
					$via   = 'automation_transition';
					self::audit('lead_erase_secret_deprecated', 'lead', null, ['ip' => $ip]);
					break;
				}
			}
		}

		if ( ! $valid ) {
			$count = $exempt ? 0 : self::erase_register_failure($policy['window']);
			if ( $transition && ! $exempt && $count > $policy['threshold'] ) {
				self::audit('lead_erase_transition_relaxed', 'lead', null, [
					'ip' => $ip, 'failures' => $count, 'outcome' => 'auth_failed',
				]);
			}
			self::audit('lead_erase_auth_failed', 'lead', null, [
				'ip' => $ip, 'header' => ( '' !== $provided ? 'present' : 'missing' ), 'transition' => $transition,
			]);
			return new \WP_Error('pk_erase_auth', __('Requête non autorisée.', 'partikulier-core'), ['status' => 401]);
		}

		if ( $transition && ! $exempt && $failures >= $policy['threshold'] ) {
			self::audit('lead_erase_transition_relaxed', 'lead', null, [
				'ip' => $ip, 'failures' => $failures, 'outcome' => 'authorized',
			]);
		}
		if ( ! $exempt ) {
			self::erase_reset_failures();
		}
		self::audit('lead_erase_authorized', 'lead', null, ['ip' => $ip, 'via' => $via, 'transition' => $transition]);
		return true;
	}


	/* ------------------------------------------------------------------ */
	/* Helpers privés                                                     */
	/* ------------------------------------------------------------------ */

	/**
	 * Secret fourni par la requête : en-tête dédié X-Partikulier-Lead-Erase,
	 * puis repli Authorization: Bearer (E-1602 — même extraction que le pont
	 * d'automatisation : en-tête dédié d'abord, Bearer ensuite).
	 */
	private static function erase_provided_secret( \WP_REST_Request $request ): string
	{
		$value = $request->get_header('x_partikulier_lead_erase');
		if ( empty($value) ) {
			$value = $request->get_header('x-partikulier-lead-erase');
		}
		$provided = (string) $value;
		if ( '' === $provided ) {
			$authorization = (string) $request->get_header('authorization');
			if ( 0 === stripos($authorization, 'bearer ') ) {
				$provided = trim(substr($authorization, 7));
			}
		}
		return $provided;
	}

	/**
	 * Clés n8n acceptées pendant la fenêtre de transition (rotation par
	 * previous_key_id comprise — l'orchestrateur peut être en cours de
	 * bascule quand la garde arrive).
	 *
	 * @return array<int, string>
	 */
	private static function erase_transition_keys(): array
	{
		if ( class_exists('\Partikulier\Core\Domain\Automation\AutomationService') ) {
			return array_values(array_map('strval', \Partikulier\Core\Domain\Automation\AutomationService::secret_keys()));
		}
		return [];
	}

	/**
	 * Politique du limiteur d'échecs, filtrable via
	 * partikulier_lead_erase_rate_limit (pattern partikulier_exec_whitelist,
	 * E-1604) : threshold, window (secondes), exempt_ips — ajustable sans
	 * nouvelle version, notamment derrière un reverse proxy où l'IP source
	 * vue par WordPress est souvent celle du proxy.
	 *
	 * @return array{threshold: int, window: int, exempt_ips: string[]}
	 */
	private static function erase_rate_policy(): array
	{
		$policy   = [
			'threshold' => self::ERASE_FAILURES_PER_HOUR,
			'window' => HOUR_IN_SECONDS,
			'exempt_ips' => [],
		];
		$filtered = apply_filters('partikulier_lead_erase_rate_limit', $policy);
		if ( is_array($filtered) ) {
			$policy = array_merge($policy, $filtered);
		}
		$policy['threshold']  = max(1, (int) $policy['threshold']);
		$policy['window']     = max(60, (int) $policy['window']);
		$policy['exempt_ips'] = array_values(array_filter( (array) $policy['exempt_ips'], 'is_string'));
		return $policy;
	}

	/**
	 * Fenêtre de transition explicite (E-1604, v4) : l'assouplissement du
	 * limiteur n'est actif que si l'hébergement a posé l'option
	 * lead_erase_transition_active — défaut OFF, le seuil nominal de 10
	 * échecs/heure s'applique alors sans aucune exemption. Filtre
	 * partikulier_lead_erase_transition_active pour les intégrateurs.
	 */
	private static function erase_transition_active(): bool
	{
		return (bool) apply_filters('partikulier_lead_erase_transition_active', (bool) get_option('lead_erase_transition_active', false));
	}

	private static function erase_client_ip(): string
	{
		$ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash( (string) $_SERVER['REMOTE_ADDR'])) : 'unknown';
		return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
	}

	private static function erase_failure_transient_key(): string
	{
		return 'pk_erase_fail_' . md5(self::erase_client_ip());
	}

	private static function erase_failure_count( int $window ): int
	{
		$state = get_transient(self::erase_failure_transient_key());
		if ( ! is_array($state) || ! isset($state['started'], $state['count']) || ( time() - (int) $state['started'] ) >= $window ) {
			return 0;
		}
		return (int) $state['count'];
	}

	private static function erase_register_failure( int $window ): int
	{
		$key   = self::erase_failure_transient_key();
		$state = get_transient($key);
		if ( ! is_array($state) || ! isset($state['started'], $state['count']) || ( time() - (int) $state['started'] ) >= $window ) {
			$state = ['started' => time(), 'count' => 0];
		}
		$state['count'] = (int) $state['count'] + 1;
		set_transient($key, $state, $window);
		return (int) $state['count'];
	}

	/**
	 * Remet à zéro le compteur d'échecs de l'appelant (public : utilisé par le
	 * contrat de sécurité pour repartir d'un état contrôlé, et par l'outillage
	 * d'hébergement si besoin).
	 */
	public static function erase_reset_failures(): void
	{
		delete_transient(self::erase_failure_transient_key());
	}
}
