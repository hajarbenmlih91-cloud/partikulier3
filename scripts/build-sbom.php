<?php
/**
 * CycloneDX inventory of the two first-party WordPress packages.
 * Usage: php scripts/build-sbom.php <dist> [output.json]
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$dist = $argv[1] ?? $root . '/dist';
$output = $argv[2] ?? $dist . '/sbom.cyclonedx.json';
$plugin = (string) file_get_contents($root . '/plugin/partikulier-core/partikulier-core.php');
$theme = (string) file_get_contents($root . '/theme/partikulier/style.css');
if (!preg_match('/Version:\s*([0-9.]+)/', $plugin, $pluginMatch)
    || !preg_match('/^Version:\s*([0-9.]+)/m', $theme, $themeMatch)) {
    fwrite(STDERR, "Cannot read package versions\n");
    exit(1);
}
$pluginVersion = $pluginMatch[1];
$themeVersion = $themeMatch[1];
$pair = $pluginVersion . '-' . $themeVersion;
$components = [];
foreach ([
    ['partikulier-core', $pluginVersion, 'B-INSTALLER-PLUGIN-partikulier-core-' . $pluginVersion . '.zip'],
    ['partikulier-theme', $themeVersion, 'partikulier-theme-' . $themeVersion . '.zip'],
] as [$name, $version, $archive]) {
    $path = $dist . '/' . $archive;
    if (!is_file($path)) {
        fwrite(STDERR, "Missing package: {$path}\n");
        exit(1);
    }
    $ref = 'pkg:generic/' . $name . '@' . $version;
    $components[] = [
        'type' => 'application',
        'bom-ref' => $ref,
        'name' => $name,
        'version' => $version,
        'purl' => $ref,
        'licenses' => [['license' => ['id' => 'GPL-3.0-or-later']]],
        'hashes' => [['alg' => 'SHA-256', 'content' => hash_file('sha256', $path)]],
        'properties' => [['name' => 'partikulier:archive', 'value' => $archive]],
    ];
}
$rootRef = 'pkg:github/hajarbenmlih91-cloud/partikulier3@' . $pair;
$id = substr(hash('sha256', json_encode($components, JSON_THROW_ON_ERROR)), 0, 32);
$uuid = substr($id, 0, 8) . '-' . substr($id, 8, 4) . '-5' . substr($id, 13, 3) . '-8' . substr($id, 17, 3) . '-' . substr($id, 20);
$bom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'serialNumber' => 'urn:uuid:' . $uuid,
    'version' => 1,
    'metadata' => [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'tools' => ['components' => [['type' => 'application', 'name' => 'scripts/build-sbom.php', 'version' => '1']]],
        'component' => [
            'type' => 'application',
            'bom-ref' => $rootRef,
            'name' => 'partikulier3',
            'version' => $pair,
            'purl' => $rootRef,
            'licenses' => [['license' => ['id' => 'GPL-3.0-or-later']]],
            'properties' => [
                ['name' => 'partikulier:inventory-scope', 'value' => 'First-party ZIPs only; installed runtime and optional external plugins are not inventoried.'],
                ['name' => 'partikulier:requires-wordpress', 'value' => '>=6.2'],
                ['name' => 'partikulier:requires-php', 'value' => '>=8.1'],
            ],
        ],
    ],
    'components' => $components,
    'dependencies' => [['ref' => $rootRef, 'dependsOn' => array_column($components, 'bom-ref')]],
    'compositions' => [['aggregate' => 'incomplete_first_party_only', 'assemblies' => array_column($components, 'bom-ref')]],
];
if (file_put_contents($output, json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
    fwrite(STDERR, "Cannot write SBOM: {$output}\n");
    exit(1);
}
echo "SBOM written: {$output}\n";
