<?php
/**
 * Registre unique des routes REST de l'espace de noms partikulier/v1 (INTEG-3).
 *
 * L'espace de noms est déclaré ICI et nulle part ailleurs côté plugin ; le thème
 * y déclare ses routes via Partikulier\Core\Rest\RouteRegistry::declare().
 * Toute déclaration en double (même route + même méthode HTTP) est refusée,
 * journalisée dans le registre d'audit et comptabilisée pour le health check.
 *
 * Les ponts de performance (détection de route du mu-plugin partikulier-rest-lite
 * et de functions.php du thème) restent en dur par conception (REG-2 : ils
 * s'exécutent avant le chargement du plugin) ; les constantes FAST_PATH ci-dessous
 * exposent les motifs exacts attendus afin qu'un test de parité les lie au registre
 * — tout renommage sans mise à jour simultanée fait échouer ce test.
 */

declare(strict_types=1);

namespace Partikulier\Core\Rest;

final class RouteRegistry
{
    /** Déclaration unique de l'espace de noms REST partagé. */
    public const NAMESPACE = 'partikulier/v1';

    /** Route servie par le chemin rapide (mu-plugin + functions.php). */
    public const FAST_PATH_ROUTE = '/listings';

    /**
     * Motifs exacts des deux ponts de performance existants.
     * partikulier-rest-lite.php (mu-plugin) et functions.php du thème doivent
     * rester alignés sur ces motifs : tests/routes-collision.php le vérifie.
     */
    public const FAST_PATH_PATTERNS = [
        '#^/wp-json/partikulier/v1/listings/?$#',
        '#^/?partikulier/v1/listings/?$#',
    ];

    /** Préfixe littéral utilisé par la détection de functions.php (str_contains). */
    public const FAST_PATH_PREFIX = '/wp-json/partikulier/v1/listings';

    private const OPTION_COLLISIONS = 'partikulier_core_route_collisions';

    /** @var array<string, array{owner: string, method: string, route: string}> */
    private static array $declarations = [];

    /** @var array<int, array<string, string>> */
    private static array $refused = [];

    public static function namespace(): string
    {
        return self::NAMESPACE;
    }

    /**
     * Déclare une route dans l'espace de noms unique. Retourne false si la
     * combinaison route + méthode est déjà déclarée (collision refusée).
     */
    public static function declare(string $route, array $args, string $owner): bool
    {
        $methods = array_map('strtoupper', (array) ($args['methods'] ?? 'GET'));
        $collision = false;
        foreach ($methods as $method) {
            $key = $method . ' ' . $route;
            if (isset(self::$declarations[$key])) {
                $collision = true;
                self::$refused[] = [
                    'route' => $route,
                    'method' => $method,
                    'owner' => $owner,
                    'conflicts_with' => self::$declarations[$key]['owner'],
                ];
                continue;
            }
            self::$declarations[$key] = ['owner' => $owner, 'method' => $method, 'route' => $route];
        }
        if ($collision) {
            self::persistCollisions();
            // Journal d'audit : une collision refusée doit être visible, jamais silencieuse.
            if (class_exists('\Partikulier\Core\AuditLogger')) {
                (new \Partikulier\Core\AuditLogger())->record(
                    'route_collision_refused',
                    'rest_route',
                    null,
                    ['route' => $route, 'methods' => $methods, 'owner' => $owner]
                );
            }
            return false;
        }
        register_rest_route(self::NAMESPACE, $route, $args);
        return true;
    }

    /** @return array<string, array{owner: string, method: string, route: string}> */
    public static function inventory(): array
    {
        return self::$declarations;
    }

    /**
     * Inventaire REST réel : parcourt le serveur REST après rest_api_init.
     * Complète l'inventaire des déclarations (couvre les routes enregistrées
     * directement par des tiers) pour le health check et les tests.
     *
     * @return array<int, array{route: string, methods: array<int, string>}>
     */
    public static function restServerInventory(): array
    {
        if (!function_exists('rest_get_server')) {
            return [];
        }
        $server = rest_get_server();
        $routes = $server->get_routes();
        $prefix = '/' . self::NAMESPACE;
        $out = [];
        foreach ($routes as $route => $endpoints) {
            if (!str_starts_with((string) $route, $prefix)) {
                continue;
            }
            $methods = [];
            foreach ((array) $endpoints as $endpoint) {
                // methods est un tableau associatif {MÉTHODE => true} : les clés
                // font foi, pas les valeurs.
                foreach (array_keys((array) ($endpoint['methods'] ?? [])) as $method) {
                    $methods[] = strtoupper((string) $method);
                }
            }
            $out[] = ['route' => (string) $route, 'methods' => array_values(array_unique($methods))];
        }
        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public static function refusedDeclarations(): array
    {
        return self::$refused;
    }

    public static function collisionCount(): int
    {
        $persisted = get_option(self::OPTION_COLLISIONS, []);
        return count(self::$refused) + (is_array($persisted) ? count($persisted) : 0);
    }

    private static function persistCollisions(): void
    {
        $persisted = get_option(self::OPTION_COLLISIONS, []);
        if (!is_array($persisted)) {
            $persisted = [];
        }
        $persisted = array_merge($persisted, self::$refused);
        update_option(self::OPTION_COLLISIONS, array_slice($persisted, -50), false);
    }
}
