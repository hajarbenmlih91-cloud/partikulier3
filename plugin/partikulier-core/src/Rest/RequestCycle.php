<?php
/**
 * SE-022 (E-1609/E-2201, campagne post-audit 2026-09, CDC v4.1 §8B + amendements
 * de l'avis de revue du 13/09) — idempotence des effets de bord des gardes REST
 * par cycle de requête.
 *
 * Constat prouvé (analyse dynamique du train 1, backtraces) : le core
 * WordPress accroche rest_send_allow_header() sur rest_post_dispatch
 * (wp-includes/rest-api.php) et, pour construire l'en-tête Allow, la
 * fonction RÉ-EXÉCUTE le permission_callback de chaque handler de la route
 * appariée — la même instance de WP_REST_Request traverse les deux passes.
 * Toute garde à effet de bord (compteur d'échecs, journal d'audit) voyait
 * donc ses effets doublés par requête HTTP (429 à la 6e requête au lieu de
 * la 11e — E-1604).
 *
 * Le remède (CDC v4.1 §8B E-2201, précisé par l'avis de revue) : les effets de bord s'exécutent au
 * plus une fois par cycle, clé sur l'OBJET requête via WeakMap (PHP >= 8.1
 * exigé par le plugin : l'entrée disparaît avec l'objet, aucun recyclage
 * d'identifiant au contraire de spl_object_id seul, aucun booléen statique
 * nu qui fausserait les rejeux rest_do_request en boucle). Le VERDICT, lui,
 * est recalculé ou restitué : la ré-exécution Allow-header ne doit jamais
 * changer la réponse observée (un 429 reste un 429, un refus reste un
 * refus — l'en-tête Allow reste exact).
 *
 * Module dédié au sens CA-4 ; aucune dépendance (primitives pures).
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Rest;

final class RequestCycle
{
	/** @var \WeakMap<\WP_REST_Request, array<string, mixed>>|null */
	private static ?\WeakMap $cycles = null;

	/**
	 * Marque l'effet $effect comme exécuté pour ce cycle de requête et
	 * retourne true s'il n'avait PAS encore été exécuté (première passe) —
	 * false sinon (ré-exécution Allow-header : effet à sauter).
	 */
	public static function first_run( \WP_REST_Request $request, string $effect ): bool
	{
		self::$cycles ??= new \WeakMap();
		$state          = self::$cycles->offsetExists($request) ? self::$cycles[ $request ] : [];
		if ( isset($state[ $effect ]) ) {
			return false;
		}
		$state[ $effect ]         = true;
		self::$cycles[ $request ] = $state;
		return true;
	}

	/**
	 * Met en cache une valeur (verdict, comptage) pour ce cycle — la passe
	 * Allow-header restitue le verdict de la passe réelle au lieu de le
	 * deviner.
	 */
	public static function remember( \WP_REST_Request $request, string $key, mixed $value ): void
	{
		self::$cycles           ??= new \WeakMap();
		$state                    = self::$cycles->offsetExists($request) ? self::$cycles[ $request ] : [];
		$state[ $key ]            = $value;
		self::$cycles[ $request ] = $state;
	}

	/**
	 * Restitue la valeur mise en cache pour ce cycle, null si absente.
	 */
	public static function recall( \WP_REST_Request $request, string $key ): mixed
	{
		if ( self::$cycles === null || ! self::$cycles->offsetExists($request) ) {
			return null;
		}
		$state = self::$cycles[ $request ];
		return $state[ $key ] ?? null;
	}
}
