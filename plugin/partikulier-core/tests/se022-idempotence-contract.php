<?php
/**
 * Contrat SE-022 — idempotence par cycle de requête des gardes REST à effet
 * de bord (campagne post-audit 2026-09, CDC v4.1 §8B, lot 6 du train 2).
 *
 * Verrou du correctif E-1609/E-2201 : le core WordPress ré-exécute le
 * permission_callback de la route appariée via rest_send_allow_header()
 * (accroché sur rest_post_dispatch, wp-includes/rest-api.php) pour construire
 * l'en-tête Allow — la même instance de WP_REST_Request traverse les deux
 * passes. La garde /erase-lead (compteur d'échecs + audits) et la garde
 * HMAC en mode log (audit_failure) ne doivent compter et journaliser
 * qu'UNE fois par cycle ; le verdict de la passe réelle est restitué à
 * l'identique sur la passe Allow (un 401 reste un 401, un 429 reste un
 * 429). Comportements attendus : compteur +1 par requête échouée (identique
 * HTTP et rest_do_request), 429 à la 11e requête (10 servies — formulation
 * E-1610 amendée), audit lead_erase_auth_failed unique par échec.
 *
 * Neuf assertions :
 *   SE22-001 : ré-exécution Allow simulée (2e appel direct de la garde sur
 *              la même instance) → compteur +0, audit +0, verdict identique
 *   SE22-002 : compteur +1 par requête échouée via rest_do_request (requêtes
 *              fraîches — sémantique identique HTTP / CLI)
 *   SE22-003 : 10 requêtes servies → la 11e refuse (429 pk_erase_rate_limited)
 *   SE22-004 : audit lead_erase_auth_failed unique par échec (delta = requêtes)
 *   SE22-005 : rejeu rest_do_request de la MÊME instance → compté exactement
 *              1 fois (clé par objet requête, pas de booléen statique nu)
 *   SE22-006 : franchissement de seuil — la 10e requête servie 401, la passe
 *              Allow restitue 401 (ni 429 ni audit flood prématuré) ; le 429
 *              et l'audit flood n'arrivent qu'à la 11e requête
 *   SE22-007 : HMAC mode log — audit_failure écrit 1 fois par requête sous
 *              ré-exécution (pas ×2)
 *   SE22-008 : HMAC mode enforce (promotion off+secret) — ré-exécution pure :
 *              verdict identique, zéro écriture d'audit
 *   SE22-009 : balayage statique des 18 déclarations (Annexe A — 8 plugin +
 *              8 thème register_route + 2 thème declare_rest_route) : chaque
 *              garde est classée (RateLimiter idempotent / RequestCycle /
 *              pure) et les marqueurs structurels sont présents — toute
 *              future garde non classée échoue (règle E-1610)
 *
 * La batterie HTTP réel (E-2202, serveur démarré) vit dans
 * tests/se022-http-battery.php — la présente suite en est le complément CLI
 * (rest_do_request ne déclenche jamais rest_post_dispatch : la passe Allow y
 * est simulée par appel direct, conformément à E-1610).
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_REPO_DIR=... php partikulier-core/tests/se022-idempotence-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
$repoDir = getenv('PK_REPO_DIR') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
if ($repoDir === '' || !is_dir($repoDir)) { fwrite(STDERR, "PK_REPO_DIR doit pointer vers le dépôt (balayage statique des 18 routes)\n"); exit(2); }
$repoDir = rtrim($repoDir, '/');
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\Automation\AutomationService;
use Partikulier\Core\Domain\Leads\LeadService;

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
$run = bin2hex(random_bytes(4));
$auditTable = $prefix . 'pk_audit_log';
$hmacTable = $prefix . 'pk_n8n_hmac_audit';

/* IP CLI (REMOTE_ADDR absent → « unknown », même logique que la garde) ;
 * lecture directe du transitoire du compteur d'échecs. */
$counterValue = static function (): int {
    $ip = isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REMOTE_ADDR'])) : 'unknown';
    $ip = filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    $state = get_transient('pk_erase_fail_' . md5($ip));
    return is_array($state) && isset($state['count']) ? (int) $state['count'] : 0;
};

$auditCount = static function (string $action) use ($wpdb, $auditTable): int {
    return (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$auditTable} WHERE action = %s", $action));
};

$verdictInfo = static function ($verdict): array {
    if (is_wp_error($verdict)) {
        $data = $verdict->get_error_data();
        return ['code' => (string) $verdict->get_error_code(), 'status' => is_array($data) ? (int) ($data['status'] ?? 0) : 0];
    }
    return ['code' => 'ok', 'status' => 200];
};

/* Requête fraîche par tentative (sémantique HTTP) via rest_do_request. */
$erase = static function (array $headers = []) use (&$results): array {
    $request = new WP_REST_Request('POST', '/partikulier/v1/erase-lead');
    $request->set_header('Content-Type', 'application/json');
    foreach ($headers as $name => $value) {
        $request->set_header($name, $value);
    }
    $request->set_body(wp_json_encode(['wa_id' => '999999999999']));
    $response = rest_do_request($request);
    $data = $response->get_data();
    $code = '';
    if (is_wp_error($data)) {
        $code = (string) $data->get_error_code();
    } elseif (is_array($data) && isset($data['code'])) {
        $code = (string) $data['code'];
    }
    return ['status' => $response->get_status(), 'code' => $code];
};

/* --- Préparation : secret dédié unique au run, transition OFF, compteur vide --- */
$secret = 'pk-se022-' . $run . '-a1b2c3d4e5f6g7h8';
$settingsBackup = get_option('pk_n8n_settings', '');
$hmacKey = 'N';
update_option('lead_erase_api_secret', $secret, false);
delete_option('lead_erase_transition_active');
LeadService::erase_reset_failures();

try {
    /* (1) Ré-exécution Allow simulée : 2e appel direct de la garde sur la
     *     même instance → aucun effet de bord supplémentaire, verdict
     *     restitué à l'identique. */
    $req1 = new WP_REST_Request('POST', '/partikulier/v1/erase-lead');
    $req1->set_header('Content-Type', 'application/json');
    $req1->set_body(wp_json_encode(['wa_id' => '999999999999']));
    $authBefore = $auditCount('lead_erase_auth_failed');
    $countBefore = $counterValue();
    $v1 = LeadService::check_erase_secret($req1);
    $v2 = LeadService::check_erase_secret($req1); // passe Allow-header
    $i1 = $verdictInfo($v1);
    $i2 = $verdictInfo($v2);
    $assert('SE22-001',
        $i1 === $i2 && $i1['code'] === 'pk_erase_auth' && $i1['status'] === 401
        && $counterValue() === $countBefore + 1
        && $auditCount('lead_erase_auth_failed') === $authBefore + 1,
        sprintf('passe Allow restituée : verdict %s/%d identique, compteur %d→%d (+1), audit +1 (pas +2)',
            $i2['code'], $i2['status'], $countBefore, $counterValue()));

    /* Compteur remis à zéro pour la séquence de forçage. */
    LeadService::erase_reset_failures();

    /* (2)+(3)+(4)+(6) Forçage nominal : 10 requêtes servies (401, compteur
     *     +1, audit unique chacune), franchissement de seuil restitué, la
     *     11e refusée (429 + audit flood). */
    $authBefore = $auditCount('lead_erase_auth_failed');
    $floodBefore = $auditCount('lead_erase_flood');
    $statuses = [];
    for ($i = 1; $i <= 9; $i++) {
        $statuses[] = $erase(['X-Partikulier-Lead-Erase' => 'se22-essai-' . $i])['status'];
    }
    /* 10e requête : appel direct double (passe réelle + passe Allow) — la
     * passe Allow ne doit NI compter, NI auditer, NI changer le verdict. */
    $req10 = new WP_REST_Request('POST', '/partikulier/v1/erase-lead');
    $req10->set_header('Content-Type', 'application/json');
    $req10->set_header('X-Partikulier-Lead-Erase', 'se22-essai-10');
    $req10->set_body(wp_json_encode(['wa_id' => '999999999999']));
    $g1 = $verdictInfo(LeadService::check_erase_secret($req10));
    $g2 = $verdictInfo(LeadService::check_erase_secret($req10));
    $assert('SE22-002',
        $counterValue() === 10 && !in_array(0, $statuses, true) && min($statuses) === 401 && $g1['status'] === 401,
        sprintf('compteur +1 par requête échouée : %d après 10 requêtes (9×rest_do_request 401 + appel direct 401)', $counterValue()));
    $assert('SE22-004',
        $auditCount('lead_erase_auth_failed') === $authBefore + 10,
        sprintf('audit lead_erase_auth_failed unique par échec : delta %d (attendu 10, pas 20)',
            $auditCount('lead_erase_auth_failed') - $authBefore));
    $assert('SE22-006',
        $g1 === $g2 && $g1['code'] === 'pk_erase_auth' && $auditCount('lead_erase_flood') === $floodBefore,
        sprintf('franchissement de seuil : 10e requête servie 401, passe Allow restitue 401 (pas de 429 ni d\'audit flood prématuré — %d/%d)',
            $g2['code'], $g2['status']));
    /* 11e requête fraîche : refus de flux. */
    $r11 = $erase();
    $assert('SE22-003',
        $r11['status'] === 429 && $r11['code'] === 'pk_erase_rate_limited'
        && $auditCount('lead_erase_flood') === $floodBefore + 1,
        sprintf('10 requêtes servies, la 11e → 429 pk_erase_rate_limited + audit flood unique (HTTP %d, %s)',
            $r11['status'], $r11['code']));

    /* (5) Rejeu rest_do_request de la MÊME instance : compté exactement
     *     une fois — clé par objet requête (pas de booléen statique nu :
     *     une requête fraîche compte normalement). */
    LeadService::erase_reset_failures();
    $authBefore = $auditCount('lead_erase_auth_failed');
    $reqReplay = new WP_REST_Request('POST', '/partikulier/v1/erase-lead');
    $reqReplay->set_header('Content-Type', 'application/json');
    $reqReplay->set_body(wp_json_encode(['wa_id' => '999999999999']));
    $ra = rest_do_request($reqReplay)->get_status();
    $rb = rest_do_request($reqReplay)->get_status();
    $assert('SE22-005',
        $ra === 401 && $rb === 401 && $counterValue() === 1
        && $auditCount('lead_erase_auth_failed') === $authBefore + 1,
        sprintf('rejeu de la même instance : %d/%d, compteur %d (exactly 1), audit +1',
            $ra, $rb, $counterValue()));

    /* (7) HMAC mode log : audit_failure écrit une seule fois par cycle. */
    update_option('pk_n8n_settings', [
        'automation_api_secret' => 'ci-se022-hmac-' . $run . '-0123456789abcdef',
        'active_key_id' => 'N',
        'hmac_mode' => 'log',
    ], false);
    $sharedSecret = (string) AutomationService::get('automation_api_secret');
    $hmacBefore = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(failure_count, 0) FROM {$hmacTable} WHERE key_id = %s AND hour_key = %s",
        'N', gmdate('Y-m-d H:00:00')
    ));
    $hreq = new WP_REST_Request('POST', '/partikulier/v1/automation-event');
    $hreq->set_header('Content-Type', 'application/json');
    $hreq->set_header('X-Partikulier-Automation', $sharedSecret);
    $hreq->set_header('X-Partikulier-Key-Id', 'N');
    $hreq->set_body(wp_json_encode(['event_id' => 'se022-' . $run, 'event_type' => 'fixture', 'source' => 'ci']));
    $hmacRowExisted = (bool) $wpdb->get_var($wpdb->prepare(
        "SELECT 1 FROM {$hmacTable} WHERE key_id = %s AND hour_key = %s",
        'N', gmdate('Y-m-d H:00:00')
    ));
    $h1 = AutomationService::check_automation_secret($hreq);
    $h2 = AutomationService::check_automation_secret($hreq); // passe Allow-header
    $hmacAfter = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(failure_count, 0) FROM {$hmacTable} WHERE key_id = %s AND hour_key = %s",
        'N', gmdate('Y-m-d H:00:00')
    ));
    $assert('SE22-007',
        $h1 === true && $h2 === true && $hmacAfter === $hmacBefore + 1,
        sprintf('HMAC mode log : requête acceptée (log) ×2 passes, audit_failure +1 (delta %d, pas +2)',
            $hmacAfter - $hmacBefore));
    /* Restauration de l'état d'audit HMAC du banc (ligne créée → supprimée,
     * ligne préexistante → compteur restauré). */
    if ($hmacRowExisted) {
        $wpdb->query($wpdb->prepare(
            "UPDATE {$hmacTable} SET failure_count = %d WHERE key_id = %s AND hour_key = %s",
            $hmacBefore, 'N', gmdate('Y-m-d H:00:00')
        ));
    } else {
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$hmacTable} WHERE key_id = %s AND hour_key = %s",
            'N', gmdate('Y-m-d H:00:00')
        ));
    }

    /* (8) HMAC mode enforce (promotion off+secret) : ré-exécution pure. */
    update_option('pk_n8n_settings', [
        'automation_api_secret' => $sharedSecret,
        'active_key_id' => 'N',
        'hmac_mode' => 'off',
    ], false);
    $hmacBefore = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(failure_count, 0) FROM {$hmacTable} WHERE key_id = %s AND hour_key = %s",
        'N', gmdate('Y-m-d H:00:00')
    ));
    $ereq = new WP_REST_Request('POST', '/partikulier/v1/automation-event');
    $ereq->set_header('Content-Type', 'application/json');
    $ereq->set_header('X-Partikulier-Automation', $sharedSecret);
    $ereq->set_body(wp_json_encode(['event_id' => 'se022-' . $run, 'event_type' => 'fixture', 'source' => 'ci']));
    $e1 = $verdictInfo(AutomationService::check_automation_secret($ereq));
    $e2 = $verdictInfo(AutomationService::check_automation_secret($ereq)); // passe Allow
    $hmacAfter = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COALESCE(failure_count, 0) FROM {$hmacTable} WHERE key_id = %s AND hour_key = %s",
        'N', gmdate('Y-m-d H:00:00')
    ));
    $assert('SE22-008',
        $e1 === $e2 && $e1['code'] === 'pk_automation_signature' && $e1['status'] === 401
        && $hmacAfter === $hmacBefore,
        sprintf('HMC enforce : verdict %s/%d identique sur les deux passes, zéro écriture d\'audit',
            $e2['code'], $e2['status']));

    /* (9) Balayage statique des 18 déclarations (Annexe A) — classification
     *     des gardes + marqueurs structurels. Toute garde non classée
     *     échoue (règle E-1610 : toute future garde à effet de bord est
     *     soumise à la même exigence). */
    $sweep = static function () use ($repoDir): array {
        $pluginSrc = $repoDir . '/plugin/partikulier-core/src';
        $themeInc = $repoDir . '/theme/partikulier/inc';
        $findings = [];
        $ok = true;

        /* (9a) Plugin : 8 déclarations RouteRegistry::declare, chaque
         *      permission_callback classée. */
        $controller = (string) file_get_contents($pluginSrc . '/RestController.php');
        $chunks = array_slice(explode('RouteRegistry::declare(', $controller), 1);
        $pluginGuards = [];
        foreach ($chunks as $chunk) {
            if (!preg_match("/'permission_callback'\s*=>\s*([^\n]+),?\n/", $chunk, $m)) {
                $findings[] = 'déclaration plugin sans permission_callback lisible';
                $ok = false;
                continue;
            }
            $expr = $m[1];
            if (strpos($expr, 'guardPublic') !== false || strpos($expr, 'guardWrite') !== false
                || strpos($expr, 'guardLead') !== false || strpos($expr, 'guardPrivate') !== false) {
                $pluginGuards[] = 'rate-limiter';
            } elseif (strpos($expr, 'check_erase_secret') !== false) {
                $pluginGuards[] = 'request-cycle';
            } elseif (strpos($expr, 'check_automation_secret') !== false) {
                $pluginGuards[] = 'request-cycle';
            } else {
                $pluginGuards[] = 'NON-CLASSÉE : ' . trim($expr);
                $ok = false;
            }
        }
        if (count($pluginGuards) !== 8) {
            $findings[] = sprintf('plugin : %d déclarations (attendu 8)', count($pluginGuards));
            $ok = false;
        }

        /* (9b) Thème : register_route (8 au runtime) — la garde est forcée
         *      structurellement par le pont. */
        $bridge = (string) file_get_contents($themeInc . '/class-automation-bridge.php');
        if (strpos($bridge, "\$args['permission_callback'] = array( __CLASS__, 'check_automation_secret' );") === false) {
            $findings[] = 'le pont ne force plus check_automation_secret sur register_route';
            $ok = false;
        }
        $buyer = (string) file_get_contents($themeInc . '/class-buyer-qualification.php');
        $buyerRoutes = 0;
        if (preg_match("/foreach\s*\(\s*array\(\s*([^)]+)\s*\)\s*as\s*\\\$route\s*\)/", $buyer, $m)) {
            $buyerRoutes = (int) preg_match_all("/'[^']+'/", $m[1]);
        }
        $callSites = [];
        foreach (['class-lead-retention.php', 'class-listing-approval.php'] as $f) {
            $callSites[$f] = (int) preg_match_all("/::register_route\(/", (string) file_get_contents($themeInc . '/' . $f));
        }
        $selfCall = (int) preg_match_all("/self::register_route\(/", $bridge);
        $themeRuntime = $buyerRoutes + array_sum($callSites) + $selfCall;
        if ($themeRuntime !== 8) {
            $findings[] = sprintf('thème register_route : %d au runtime (attendu 8 : qualification %d + rétention %d + approbation %d + pont %d)',
                $themeRuntime, $buyerRoutes, $callSites['class-lead-retention.php'], $callSites['class-listing-approval.php'], $selfCall);
            $ok = false;
        }

        /* (9c) Thème : declare_rest_route (2, /owner/*) — garde explicite
         *      pure (can_access_owner_dashboard). */
        $owner = (string) file_get_contents($themeInc . '/class-owner-insights.php');
        $ownerDeclares = (int) preg_match_all("/Partikulier_Automation_Bridge::declare_rest_route\(/", $owner);
        $ownerGuarded = (int) preg_match_all("/'permission_callback'\s*=>\s*array\(\s*__CLASS__,\s*'can_access_owner_dashboard'\s*\)/", $owner);
        $pure = false;
        if (preg_match("/public static function can_access_owner_dashboard\(\)\s*\{(.*?)\n        \}/s", $owner, $m)) {
            $body = $m[1];
            $pure = strpos($body, 'is_user_logged_in') !== false
                && !preg_match('/set_transient|get_transient|update_option|delete_option|->insert|->query|->update|->delete|wp_insert|wp_delete|wp_mail/', $body);
        }
        if ($ownerDeclares !== 2 || $ownerGuarded !== 2 || !$pure) {
            $findings[] = sprintf('/owner/* : %d déclarations, %d gardées, garde pure=%s', $ownerDeclares, $ownerGuarded, $pure ? 'oui' : 'NON');
            $ok = false;
        }

        /* (9d) Marqueurs structurels de la classification. */
        $rateLimiter = (string) file_get_contents($pluginSrc . '/RateLimiter.php');
        if (strpos($rateLimiter, 'spl_object_id($request)') === false || strpos($rateLimiter, '$this->seen') === false) {
            $findings[] = 'RateLimiter : marqueur par cycle absent';
            $ok = false;
        }
        $eraseGuard = (string) file_get_contents($pluginSrc . '/Domain/Leads/LeadsEraseGuardTrait.php');
        if (strpos($eraseGuard, 'RequestCycle::recall') === false || strpos($eraseGuard, 'RequestCycle::remember') === false) {
            $findings[] = 'garde /erase-lead : restitution RequestCycle absente';
            $ok = false;
        }
        $hmacGuard = (string) file_get_contents($pluginSrc . '/Domain/Automation/AutomationHmacTrait.php');
        if (strpos($hmacGuard, 'RequestCycle::first_run') === false) {
            $findings[] = 'garde HMAC : first_run RequestCycle absent';
            $ok = false;
        }

        return [$ok, array_merge([
            sprintf('18 déclarations : plugin %d (rate-limiter %d, request-cycle %d), thème register_route %d (garde forcée par le pont), /owner/* %d (pure)',
                count($pluginGuards),
                count(array_keys($pluginGuards, 'rate-limiter', true)),
                count(array_keys($pluginGuards, 'request-cycle', true)),
                $themeRuntime, $ownerDeclares),
        ], $findings)];
    };
    [$sweepOk, $sweepFindings] = $sweep();
    $assert('SE22-009', $sweepOk, implode(' ; ', $sweepFindings));
} catch (Throwable $error) {
    $assert('SE22-EXCEPTION', false, $error->getMessage());
} finally {
    /* --- Nettoyage : état du banc restauré --- */
    LeadService::erase_reset_failures();
    delete_option('lead_erase_transition_active');
    delete_option('lead_erase_api_secret');
    if ($settingsBackup !== '') {
        update_option('pk_n8n_settings', $settingsBackup, false);
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se022-idempotence-contract (SE-022 — campagne post-audit, lot 6 train 2)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/se022-idempotence-contract.php',
    'commit' => $commit,
    'results' => $results,
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
