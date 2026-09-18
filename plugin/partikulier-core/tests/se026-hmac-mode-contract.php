<?php
declare(strict_types=1);

/**
 * Contrat SE-026 — fix du warning hmac_mode (E-2601/E-2602, micro-lot pré-prod).
 *
 * E-2602 : sauvegarde sans clé hmac_mode → 0 warning + 'off' stocké ;
 * clé invalide → 'off' ; clé valide conservée.
 */

$wpDir = getenv('PK_WP_DIR') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "PK_WP_DIR doit pointer vers une installation WordPress\n");
    exit(2);
}
require $wpDir . '/wp-load.php';

$started = gmdate('c');
$commit = getenv('PK_COMMIT') ?: '';
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) {
    fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n");
    exit(2);
}
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$service = '\Partikulier\Core\Domain\Automation\AutomationService';

/**
 * Sauvegarde en comptant les warnings PHP émis pendant l'appel.
 * Retourne [warnings, hmac_mode_stocké].
 */
$saveCountingWarnings = static function (array $posted) use ($service): array {
    $warnings = [];
    set_error_handler(static function (int $no, string $str) use (&$warnings): bool {
        if ($no === E_WARNING) {
            $warnings[] = $str;
        }
        return true;
    });
    try {
        $service::save_admin_settings($posted);
    } finally {
        restore_error_handler();
    }
    $stored = $service::settings();
    return [$warnings, (string) ($stored['hmac_mode'] ?? '')];
};

// E26-001 — sans clé hmac_mode : zéro warning + 'off' stocké (le défaut d'origine
// stockait NULL avec « Undefined array key "hmac_mode" »).
[$w1, $m1] = $saveCountingWarnings([
    'n8n_webhook_url' => 'https://n8n.example.local/hook',
    'quota_per_day' => 2,
    'consent_text' => '',
    'channel_url' => '',
]);
$assert('E26-001', $w1 === [] && $m1 === 'off',
    sprintf('sans clé : %d warning(s)%s, hmac_mode stocké = %s',
        count($w1), $w1 ? ' (' . implode('; ', $w1) . ')' : '', var_export($m1, true)));

// E26-002 — clé invalide : repli 'off', zéro warning.
[$w2, $m2] = $saveCountingWarnings(['hmac_mode' => 'bogus']);
$assert('E26-002', $w2 === [] && $m2 === 'off',
    sprintf('valeur invalide : hmac_mode stocké = %s', var_export($m2, true)));

// E26-003 — clé valide ('enforce') : conservée telle quelle.
[$w3, $m3] = $saveCountingWarnings(['hmac_mode' => 'enforce']);
$assert('E26-003', $w3 === [] && $m3 === 'enforce',
    sprintf("valeur valide : hmac_mode stocké = %s", var_export($m3, true)));

// E26-004 — clé valide ('log') : conservée elle aussi (couverture de la
// troisième valeur autorisée, la plage off/log/enforce étant fermée).
[$w4, $m4] = $saveCountingWarnings(['hmac_mode' => 'log']);
$assert('E26-004', $w4 === [] && $m4 === 'log',
    sprintf("valeur valide : hmac_mode stocké = %s", var_export($m4, true)));

// État final propre pour les suites suivantes : retour au défaut.
$service::save_admin_settings(['hmac_mode' => 'off']);

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se026-hmac-mode-contract (SE-026)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/se026-hmac-mode-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
