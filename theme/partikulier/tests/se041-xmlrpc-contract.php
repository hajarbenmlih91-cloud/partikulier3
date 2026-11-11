<?php
/**
 * Contrat SE-041 — durcissement XML-RPC (E-4101, CDC Phase 1 lot B —
 * TRAIN3-CDC-PHASE1-EXECUTION v2/v2.1 §5).
 *
 * Lacune prouvée dans le cœur : le filtre xmlrpc_enabled n'est appliqué que
 * dans wp_xmlrpc_server::set_is_enabled() et testé uniquement dans login()
 * (class-wp-xmlrpc-server.php:189-223) — les méthodes SANS authentification
 * (system.listMethods, demo.sayHello, pingback.ping) restent exécutables.
 * Le correctif du lot B ajoute xmlrpc_methods → __return_empty_array : la
 * table des callbacks applicatifs devient vide au constructeur du serveur ;
 * IXR ajoute ensuite exactement trois méthodes d'introspection system.*.
 *
 * Ce contrat vit dans le workflow « Contrats de recette (WordPress) » APRÈS
 * l'étape SE-025 (contexte trilingue rétabli) et AVANT l'oracle, sur le même
 * serveur front que SE-025 (origine du site, port 8099 inclus).
 *
 * Démo négative (P-3 du CDC) : sur le code ORIGINAL (sans le filtre
 * xmlrpc_methods), ce contrat est ROUGE — E41-001 (filtre absent), E41-002
 * (la table applicative survit), E41-004 (la liste contient pingback.ping
 * et demo.sayHello), E41-005 (Hello! exécuté), E41-006 (pingback inscrit).
 * La démonstration a été faite sur le laboratoire : même contrat, code
 * original → 5 FAIL / 6, exit 1.
 *
 * Ce que la batterie verrouille (E-4101) :
 *  - E41-001 : le filtre xmlrpc_methods est enregistré sur
 *              __return_empty_array (has_filter) ;
 *  - E41-002 : apply_filters('xmlrpc_methods', …) renvoie un tableau VIDE ;
 *  - E41-003 : non-régression — xmlrpc_enabled renvoie false (ligne du
 *              train antérieur conservée, défense en profondeur) ;
 *  - E41-004 : HTTP réel — POST system.listMethods → 403 ou 405.
 *              Le corps ne liste aucune méthode applicative.
 *              pep.ma n'est pas visé par ce refus : la production n'est
 *              pas modifiée par ce contrat.
 *  - E41-005 : HTTP réel — POST demo.sayHello → 403 ou 405, aucun
 *              « Hello! » dans le corps ;
 *  - E41-006 : HTTP réel — POST pingback.ping (2 URL de recette
 *              .invalid, aucun tiers) → 403 ou 405, pas d'exécution.
 *
 * Les noms de méthodes sont encodés dans <string> (pas <name>) : la liste
 * est décodée structurellement (DOM), jamais par grep de balise.
 *
 * Rejouable :
 *   PK_BASE=http://127.0.0.1:8099 PK_WP_DIR=<wp> PK_COMMIT=<sha> \
 *     php theme/partikulier/tests/se041-xmlrpc-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$base = rtrim((string) (getenv('PK_BASE') ?: ''), '/');
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if ($base === '' || !preg_match('#^https?://#', $base)) { fwrite(STDERR, "PK_BASE doit pointer vers le serveur démarré\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

/* Requête XML-RPC réelle sur le serveur de recette (pattern se022-http-battery :
 * curl natif, statut ET corps contrôlés séparément). */
$call = static function (string $xml) use ($base): array {
    $ch = curl_init($base . '/xmlrpc.php');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $xml,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_HTTPHEADER => ['Content-Type: text/xml'],
    ]);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['status' => $code, 'body' => is_string($response) ? (string) $response : '', 'error' => $error];
};

/* Décodage structurel des réponses XML-RPC (aucun grep de balise). */
$decodeList = static function (string $xml): ?array {
    if (trim($xml) === '') { return null; }
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml) || $dom->documentElement === null || $dom->documentElement->nodeName !== 'methodResponse') { return null; }
    if ($dom->getElementsByTagName('fault')->length > 0) { return null; }
    $methods = [];
    foreach ($dom->getElementsByTagName('value') as $value) {
        $parent = $value->parentNode;
        if ($parent === null || $parent->nodeName !== 'data') { continue; }
        foreach ($value->childNodes as $child) {
            if ($child instanceof DOMElement && $child->nodeName === 'string' && $child->nodeValue !== null) {
                $methods[] = (string) $child->nodeValue;
            }
        }
    }
    return $methods;
};
$decodeFault = static function (string $xml): ?int {
    if (trim($xml) === '') { return null; }
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml) || $dom->documentElement === null || $dom->documentElement->nodeName !== 'methodResponse') { return null; }
    $faults = $dom->getElementsByTagName('fault');
    if ($faults->length !== 1) { return null; }
    $code = null;
    foreach ($faults->item(0)->getElementsByTagName('member') as $member) {
        $names = $member->getElementsByTagName('name');
        if ($names->length === 1 && (string) $names->item(0)->nodeValue === 'faultCode') {
            foreach ($member->getElementsByTagName('value') as $value) {
                foreach ($value->childNodes as $child) {
                    if ($child instanceof DOMElement && $child->nodeName === 'int' && is_numeric($child->nodeValue)) {
                        $code = (int) $child->nodeValue;
                    }
                }
            }
        }
    }
    return $code;
};

try {
    /* ── E41-001 : le filtre est enregistré sur __return_empty_array. ── */
    $registered = has_filter('xmlrpc_methods', '__return_empty_array') !== false;
    $assert('E41-001', $registered,
        $registered
            ? "filtre xmlrpc_methods enregistré sur __return_empty_array (priorité " . (string) has_filter('xmlrpc_methods', '__return_empty_array') . ")"
            : 'filtre xmlrpc_methods ABSENT — code original, le correctif E-4101 n\'est pas actif');

    /* ── E41-002 : la table applicative est vidée par le filtre. ── */
    $filtered = apply_filters('xmlrpc_methods', ['pingback.ping' => 'x', 'demo.sayHello' => 'y']);
    $assert('E41-002', $filtered === [],
        $filtered === []
            ? 'apply_filters(xmlrpc_methods, 2 méthodes) renvoie un tableau vide'
            : 'la table applicative survit au filtre : ' . implode(', ', array_keys((array) $filtered)));

    /* ── E41-003 : non-régression — xmlrpc_enabled reste à false. ── */
    $enabled = apply_filters('xmlrpc_enabled', true);
    $assert('E41-003', $enabled === false,
        $enabled === false
            ? 'apply_filters(xmlrpc_enabled, true) renvoie false (défense en profondeur conservée)'
            : 'xmlrpc_enabled ne renvoie plus false : régression de la ligne du train antérieur');

    /* ── E41-004 : HTTP réel — refus, aucune méthode applicative listée. ── */
    $rList = $call('<methodCall><methodName>system.listMethods</methodName></methodCall>');
    $refused = in_array($rList['status'], [403, 405], true);
    $listed = $rList['error'] === '' ? $decodeList($rList['body']) : null;
    $applicatives = is_array($listed)
        ? array_values(array_filter($listed, static fn(string $m): bool => strpos($m, 'system.') !== 0))
        : [];
    $bodyLeaks = strpos($rList['body'], 'pingback.ping') !== false || strpos($rList['body'], 'demo.sayHello') !== false;
    $assert('E41-004', $refused && $applicatives === [] && !$bodyLeaks,
        sprintf('POST system.listMethods → HTTP %d (attendu 403 ou 405), méthodes applicatives %s',
            $rList['status'],
            ($applicatives === [] && !$bodyLeaks) ? 'absentes du corps' : 'ENCORE PRÉSENTES'));

    /* ── E41-005 : HTTP réel — demo.sayHello refusé, aucun Hello!. ── */
    $rHello = $call('<methodCall><methodName>demo.sayHello</methodName></methodCall>');
    $assert('E41-005',
        in_array($rHello['status'], [403, 405], true) && strpos($rHello['body'], 'Hello!') === false,
        sprintf('POST demo.sayHello → HTTP %d (attendu 403 ou 405), « Hello! » %s',
            $rHello['status'],
            strpos($rHello['body'], 'Hello!') === false ? 'absent du corps' : 'ENCORE EXÉCUTÉ'));

    /* ── E41-006 : HTTP réel — pingback.ping refusé, pas d'exécution. ── */
    $rPing = $call('<methodCall><methodName>pingback.ping</methodName><params><param><value><string>http://source.invalid/</string></value></param><param><value><string>http://cible.invalid/</string></value></param></params></methodCall>');
    $pingExecuted = $rPing['error'] === '' && $decodeFault($rPing['body']) === null && strpos($rPing['body'], '<methodResponse>') !== false;
    $assert('E41-006',
        in_array($rPing['status'], [403, 405], true) && !$pingExecuted,
        sprintf('POST pingback.ping (2 URL .invalid de recette) → HTTP %d (attendu 403 ou 405), exécution %s',
            $rPing['status'],
            $pingExecuted ? 'ENCORE POSSIBLE' : 'absente'));
} catch (Throwable $error) {
    $assert('E41-EXCEPTION', false, $error->getMessage());
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se041-xmlrpc-contract (durcissement XML-RPC : filtre xmlrpc_methods, 3 system.* exactement, faults -32601 — E-4101)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php theme/partikulier/tests/se041-xmlrpc-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
