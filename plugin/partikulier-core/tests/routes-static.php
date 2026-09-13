<?php
/**
 * Contrat STATIQUE du registre des routes INTEG-3 — rejouable sur un runner
 * GitHub SANS WordPress vivant.
 *
 * Complément de tests/routes-collision.php (contrat dynamique : inventaire
 * réel ROUTE-001, zéro collision ROUTE-002, refus effectif d'un doublon
 * ROUTE-003 — exigent un WordPress complet). La présente suite extrait les
 * contrôles qui ne dépendent d'aucun état runtime :
 *
 *   S-ROUTE-004 : aucun appel direct register_rest_route() dans les sources,
 *                 hors registre (plugin) et hors repli d'autonomie (thème) —
 *                 même logique que le ROUTE-004 dynamique, mêmes exclusions ;
 *   S-ROUTE-005 : parité des ponts de performance (REG-2) — le mu-plugin
 *                 partikulier-rest-lite.php porte TOUS les motifs exacts
 *                 publiés par RouteRegistry::FAST_PATH_PATTERNS, et le
 *                 functions.php du thème porte le préfixe
 *                 RouteRegistry::FAST_PATH_PREFIX.
 *   S-ROUTE-007 (SE-016, campagne post-audit) : tout appel de déclaration
 *                 de route — Partikulier_Automation_Bridge::declare_rest_route()
 *                 côté thème (la porte du pont qui ne force PAS la garde, à
 *                 la différence de register_route) ou RouteRegistry::declare()
 *                 côté plugin — doit porter un permission_callback explicite
 *                 dans ses arguments (extraction par appariement de
 *                 parenthèses, complément statique de l'inventaire dynamique
 *                 ROUTE-006 / E-1608).
 *
 * Le registre est chargé SANS WordPress : src/Rest/RouteRegistry.php est un
 * fichier de classe pur (declare + namespace + classe finale, aucun effet de
 * bord au chargement, aucun appel WordPress à portée fichier) — les constantes
 * sont donc lisibles telles quelles sur un runner.
 *
 * Modes :
 *   PK_PLUGIN_DIR=<plugin>                         mode runner (contrôles plugin)
 *   PK_PLUGIN_DIR=<plugin> PK_THEME_DIR=<thème>    mode complet (contrôles thème)
 * Sur un dépôt GitHub isolé, le thème n'est pas présent : les contrôles thème
 * sont alors explicitement signalés SKIPPED dans les limitations (jamais
 * silencieusement verts). Le rejeu local du kit CI couvre le mode complet.
 *
 * Rejouable : PK_PLUGIN_DIR=... [PK_THEME_DIR=...] php partikulier-core/tests/routes-static.php
 */

declare(strict_types=1);

$pluginDir = getenv('PK_PLUGIN_DIR') ?: '';
$themeDir = getenv('PK_THEME_DIR') ?: '';
$version = getenv('PK_VERSION') ?: '2.0.1';

if ($pluginDir === '' || !is_dir($pluginDir)) {
    fwrite(STDERR, "PK_PLUGIN_DIR doit pointer vers le dossier du plugin partikulier-core\n");
    exit(2);
}
$pluginDir = rtrim($pluginDir, '/');
$themeProvided = ($themeDir !== '');
$themeDir = rtrim($themeDir, '/');

$started = gmdate('c');
$results = [];
$limitations = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

try {
    // Le registre sans WordPress : classe pure, constantes publiques.
    require_once $pluginDir . '/src/Rest/RouteRegistry.php';
    $patterns = \Partikulier\Core\Rest\RouteRegistry::FAST_PATH_PATTERNS;
    $prefix = \Partikulier\Core\Rest\RouteRegistry::FAST_PATH_PREFIX;

    // 1) S-ROUTE-004a : purge des déclarations directes côté plugin.
    //     Autorisés : le registre lui-même (seul point de déclaration).
    $allowedPlugin = [
        $pluginDir . '/src/Rest/RouteRegistry.php',
    ];
    $pluginFiles = array_merge(
        glob($pluginDir . '/src/*.php') ?: [],
        glob($pluginDir . '/src/*/*.php') ?: [],
        glob($pluginDir . '/*.php') ?: []
    );
    $offenders = [];
    foreach ($pluginFiles as $file) {
        $file = (string) $file;
        if (in_array($file, $allowedPlugin, true)) {
            continue;
        }
        $content = (string) file_get_contents($file);
        if (strpos($content, 'register_rest_route(') !== false) {
            $offenders[] = str_replace($pluginDir, '<plugin>', $file);
        }
    }
    $assert('S-ROUTE-004a', $offenders === [],
        'aucune déclaration REST directe hors registre côté plugin (trouvés : '
        . ($offenders ? implode(', ', $offenders) : 'aucun') . ')');

    // 2) S-ROUTE-004b : même purge côté thème (repli d'autonomie autorisé).
    if ($themeProvided) {
        $allowedTheme = [
            $themeDir . '/inc/class-automation-bridge.php',
        ];
        $themeFiles = array_merge(
            glob($themeDir . '/inc/*.php') ?: [],
            [$themeDir . '/functions.php']
        );
        $offendersTheme = [];
        foreach ($themeFiles as $file) {
            $file = (string) $file;
            if (in_array($file, $allowedTheme, true)) {
                continue;
            }
            $content = (string) file_get_contents($file);
            if (strpos($content, 'register_rest_route(') !== false) {
                $offendersTheme[] = str_replace($themeDir, '<theme>', $file);
            }
        }
        $assert('S-ROUTE-004b', $offendersTheme === [],
            'aucune déclaration REST directe hors repli côté thème (trouvés : '
            . ($offendersTheme ? implode(', ', $offendersTheme) : 'aucun') . ')');
    } else {
        $limitations[] = 'S-ROUTE-004b (thème) non exécuté : PK_THEME_DIR absent — mode runner, dépôt plugin isolé';
    }

    // 3) S-ROUTE-005a : le mu-plugin porte tous les motifs exacts du registre.
    $muFile = $pluginDir . '/mu-plugins/partikulier-rest-lite.php';
    $muContent = is_readable($muFile) ? (string) file_get_contents($muFile) : '';
    $muAligned = ($muContent !== '');
    foreach ($patterns as $pattern) {
        if (strpos($muContent, $pattern) === false) {
            $muAligned = false;
        }
    }
    $assert('S-ROUTE-005a', $muAligned,
        'mu-plugin partikulier-rest-lite.php aligné sur les ' . count($patterns)
        . ' motifs exacts du registre (' . ($muAligned ? 'présents' : 'au moins un motif absent') . ')');

    // 4) S-ROUTE-005b : le functions.php du thème porte le préfixe du registre.
    if ($themeProvided) {
        $functionsContent = (string) file_get_contents($themeDir . '/functions.php');
        $themeAligned = strpos($functionsContent, $prefix) !== false;
        $assert('S-ROUTE-005b', $themeAligned,
            'functions.php du thème porte le préfixe « ' . $prefix . ' » (' . ($themeAligned ? 'oui' : 'NON') . ')');
    } else {
        $limitations[] = 'S-ROUTE-005b (thème) non exécuté : PK_THEME_DIR absent — mode runner, dépôt plugin isolé';
    }

    // 5) S-ROUTE-006 : le registre est bien l'unique point de déclaration du
    //    namespace — la constante NAMESPACE vaut partikulier/v1 et le préfixe
    //    du chemin rapide reste cohérent avec la route publiée.
    $nsOk = \Partikulier\Core\Rest\RouteRegistry::NAMESPACE === 'partikulier/v1';
    $prefixOk = strpos($prefix, '/wp-json/' . \Partikulier\Core\Rest\RouteRegistry::NAMESPACE . '/') === 0;
    $assert('S-ROUTE-006', $nsOk && $prefixOk,
        'namespace partikulier/v1 déclaré par la seule constante du registre ; préfixe du pont cohérent');

    // 6) S-ROUTE-007 (E-1608, complément v4) — extraction des blocs d'arguments
    //    de chaque appel de déclaration (appariement de parenthèses) : tout
    //    appel doit porter un permission_callback explicite.
    $extractCallSites = static function (string $content, string $token): array {
        $sites = [];
        $offset = 0;
        $tokenLength = strlen($token);
        while (($pos = strpos($content, $token, $offset)) !== false) {
            $open = $pos + $tokenLength - 1; // le « ( » final du token lui-même
            $depth = 0;
            $length = strlen($content);
            for ($i = $open; $i < $length; $i++) {
                $char = $content[$i];
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                    if ($depth === 0) {
                        break;
                    }
                }
            }
            $sites[] = substr($content, $open, $i - $open + 1);
            $offset = $i + 1;
        }
        return $sites;
    };
    $unguardedSites = static function (array $files, string $token, array $excluded, string $stripPrefix) use ($extractCallSites): array {
        $offenders = [];
        foreach ($files as $file) {
            $file = (string) $file;
            if (in_array($file, $excluded, true)) {
                continue;
            }
            $content = (string) file_get_contents($file);
            foreach ($extractCallSites($content, $token) as $site) {
                if (strpos($site, "'permission_callback'") === false) {
                    $offenders[] = str_replace($stripPrefix, '', $file);
                }
            }
        }
        return $offenders;
    };

    // 6a) S-ROUTE-007a — côté thème : la porte declare_rest_route du pont ne
    //     force pas la garde (asymétrie documentée) : chaque appel direct doit
    //     la fournir. Le pont lui-même (définition + register_route qui force)
    //     est exclu de la passe.
    if ($themeProvided) {
        $themeFiles = array_merge(
            glob($themeDir . '/inc/*.php') ?: [],
            [$themeDir . '/functions.php']
        );
        $offendersBridge = $unguardedSites(
            $themeFiles,
            'declare_rest_route(',
            [$themeDir . '/inc/class-automation-bridge.php'],
            $themeDir
        );
        $assert('S-ROUTE-007a', $offendersBridge === [],
            'thème : tout appel declare_rest_route porte un permission_callback explicite (trouvés sans garde : '
            . ($offendersBridge ? implode(', ', $offendersBridge) : 'aucun') . ')');
    } else {
        $limitations[] = 'S-ROUTE-007a (thème) non exécuté : PK_THEME_DIR absent — mode runner, dépôt plugin isolé';
    }

    // 6b) S-ROUTE-007b — côté plugin : toute déclaration RouteRegistry::declare
    //     doit porter la garde (le registre n'en force aucune — la porte
    //     /erase-lead du lot B2 y vivait sans garde).
    $pluginFiles = array_merge(
        glob($pluginDir . '/src/*.php') ?: [],
        glob($pluginDir . '/src/*/*.php') ?: [],
        glob($pluginDir . '/*.php') ?: []
    );
    $offendersRegistry = $unguardedSites(
        $pluginFiles,
        'RouteRegistry::declare(',
        [$pluginDir . '/src/Rest/RouteRegistry.php'],
        $pluginDir
    );
    $assert('S-ROUTE-007b', $offendersRegistry === [],
        'plugin : tout appel RouteRegistry::declare porte un permission_callback explicite (trouvés sans garde : '
        . ($offendersRegistry ? implode(', ', $offendersRegistry) : 'aucun') . ')');
} catch (Throwable $error) {
    $assert('S-ROUTE-EXCEPTION', false, $error->getMessage());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
$payload = [
    'test_id' => 'CORE-ROUTES-STATIC-001',
    'candidate_version' => $version,
    'run_id' => getenv('PK_RUN_ID') ?: 'local',
    'started_at_utc' => $started,
    'finished_at_utc' => gmdate('c'),
    'command' => 'php partikulier-core/tests/routes-static.php'
        . ($themeProvided ? ' (mode complet : plugin + thème)' : ' (mode runner : plugin seul)'),
    'fixture' => 'sources seules, aucun WordPress requis',
    'status' => $failed ? 'FAIL' : 'PASS',
    'exit_code' => $failed ? 1 : 0,
    'tests' => $results,
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'limitations' => array_merge(
        $limitations,
        ['contrôle dynamique complet (inventaire, collisions, refus) : tests/routes-collision.php sur un WordPress vivant']
    ),
];
printf("%s\n", json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
exit($failed ? 1 : 0);
