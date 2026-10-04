<?php
/**
 * Merge regression contract: real WordPress auth, health, transactions and demo isolation.
 * PK_WP_DIR=... PK_COMMIT=<sha> php tests/merge-v3-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
if (!is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "PK_WP_DIR must point to WordPress\n");
    exit(2);
}
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\Leads\LeadService;
use Partikulier\Core\Domain\Automation\AutomationService;
use Partikulier\Core\HealthCheck;
use Partikulier\Core\Integration\ListingSynchronizer;

if (($argv[1] ?? '') === '--contact-worker') {
    $result = LeadService::authorize_contact($argv[3], (int) $argv[2], 'merge-' . wp_generate_uuid4());
    echo wp_json_encode(is_wp_error($result) ? ['error' => $result->get_error_code()] : $result->get_data());
    exit(is_wp_error($result) ? 1 : 0);
}

class MergeV3DatabaseProxy {
    public string $last_error = '';
    public int $calls = 0;
    public int $failures = 0;
    public int $transactions = 0;

    public function __construct(
        private wpdb $db,
        private string $method,
        private string $needle,
        private int $remaining,
        private string $error = 'Deadlock found when trying to get lock'
    ) {}

    public function __get(string $name): mixed {
        return $this->db->$name;
    }

    public function __call(string $name, array $args): mixed {
        $this->calls++;
        if ($name === 'query' && $args[0] === 'START TRANSACTION') $this->transactions++;
        if ($this->remaining > 0 && $name === $this->method && str_contains((string) ($args[0] ?? ''), $this->needle)) {
            $this->remaining--;
            $this->failures++;
            $this->last_error = $this->error;
            return in_array($name, ['get_var', 'get_row'], true) ? null : false;
        }
        $result = $this->db->$name(...$args);
        $this->last_error = $this->db->last_error;
        return $result;
    }
}

$results = [];
$assert = static function (string $id, bool $ok, string $detail) use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};
$run = bin2hex(random_bytes(4));
$password = wp_generate_password(24);
$phone = '2126' . random_int(10000000, 99999999);
$local = '0' . substr($phone, 3);
$users = [];
$properties = [];
$phones = [];
$rateKey = '';
$demoInstalled = false;
$demoOwnerBefore = get_user_by('login', 'demo-partikulier');
$originalUser = get_current_user_id();
$originalIp = $_SERVER['REMOTE_ADDR'] ?? null;
$originalPost = $_POST;
$originalSettings = get_option('pk_n8n_settings', null);
global $wpdb;
$realDb = $wpdb;
$admin = get_users(['role' => 'administrator', 'number' => 1])[0] ?? null;
$sync = new ListingSynchronizer();

try {
    $owner = wp_insert_user(['user_login' => $local, 'user_pass' => $password, 'user_email' => "merge-{$run}@example.test", 'role' => 'contributor']);
    if (is_wp_error($owner)) throw new RuntimeException($owner->get_error_message());
    $users[] = $owner;
    update_user_meta($owner, '_pk_owner_phone_clean', $local);
    update_user_meta($owner, '_pk_deposit_account', 1);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.42';
    wp_set_current_user(0);
    $aliases = [$local, '+' . $phone, '00' . $phone, substr($local, 0, 2) . ' ' . substr($local, 2)];
    foreach ($aliases as $i => $alias) {
        $user = wp_authenticate($alias, $password);
        $assert('MERGE-AUTH-' . $i, $user instanceof WP_User && $user->ID === $owner, 'Phone alias authenticates the same existing owner');
    }
    $keys = array_map([Partikulier_Security::class, 'get_login_rate_key'], $aliases);
    $rateKey = $keys[0];
    $assert('MERGE-AUTH-ALIASES', count(array_unique($keys)) === 1, 'Phone formats share a single throttling bucket');
    for ($i = 0; $i < 5; $i++) wp_authenticate($local, 'invalid-' . $i);
    $blocked = wp_authenticate('+' . $phone, $password);
    $assert('MERGE-AUTH-LIMIT', is_wp_error($blocked) && $blocked->get_error_code() === 'pk_auth_rate_limited', 'Correct password cannot override lockout');
    delete_transient($rateKey);
    $unknown = wp_authenticate('merge-unknown-' . $run, $password);
    $wrong = wp_authenticate($local, 'invalid');
    $assert('MERGE-AUTH-GENERIC', is_wp_error($unknown) && is_wp_error($wrong) && $unknown->get_error_message() === $wrong->get_error_message(), 'Unknown account and wrong password have identical messages');
    delete_transient($rateKey);

    $ensure = new ReflectionMethod(Partikulier_Form::class, 'ensure_user');
    $existing = $ensure->invoke(null, "merge-{$run}@example.test", 'Owner', $phone);
    $assert('MERGE-ACCOUNT-ANON', is_wp_error($existing), 'Anonymous deposit cannot appropriate an existing account');
    wp_set_current_user($owner);
    $own = $ensure->invoke(null, "merge-{$run}@example.test", 'Owner', $phone);
    $assert('MERGE-ACCOUNT-OWNER', $own instanceof WP_User && $own->ID === $owner, 'Authenticated owner can reuse their account');
    $assert('MERGE-ACCOUNT-STAFF', $admin instanceof WP_User && !Partikulier_Listing_Approval::may_issue_credentials($admin), 'Approval never issues staff credentials');
    $expiry = time() + 600;
    $token = $expiry . '.' . hash_hmac('sha256', $expiry . '|fixture-key', wp_salt('auth'));
    $expired = time() - 1;
    $expiredToken = $expired . '.' . hash_hmac('sha256', $expired . '|fixture-key', wp_salt('auth'));
    $assert('MERGE-GATEWAY', Partikulier_Security::valid_admin_access_token($token, 'fixture-key')
        && !Partikulier_Security::valid_admin_access_token($token, 'wrong-key')
        && !Partikulier_Security::valid_admin_access_token($expiredToken, 'fixture-key')
        && !Partikulier_Security::valid_admin_access_token('1.' . str_repeat('0', 64), 'fixture-key'), 'Gateway validates signature and server-side expiry');

    if (!function_exists('pk_2fa_authenticate')) {
        require_once dirname(__DIR__) . '/mu-plugins/partikulier-2fa-light.php';
    }
    $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';
    $assert('MERGE-TOTP-RFC', pk_2fa_totp_at($secret, 1) === '287082', 'RFC 6238 test vector (six digits)');
    update_user_meta($owner, PK_2FA_META_SECRET, $secret);
    $_POST = [];
    $user = get_user_by('id', $owner);
    $needed = pk_2fa_authenticate($user, $local, $password);
    $_POST['pk_2fa_code'] = pk_2fa_totp_at($secret, (int) floor(time() / 30));
    $accepted = pk_2fa_authenticate($user, $local, $password);
    $assert('MERGE-TOTP', is_wp_error($needed) && $needed->get_error_code() === 'pk_2fa_required' && $accepted instanceof WP_User, '2FA requires a code and accepts a current TOTP');
    $backup = bin2hex(random_bytes(8));
    update_user_meta($owner, PK_2FA_META_BACKUPS, [wp_hash_password($backup)]);
    $_POST['pk_2fa_code'] = $backup;
    $once = pk_2fa_authenticate($user, $local, $password);
    $twice = pk_2fa_authenticate($user, $local, $password);
    $assert('MERGE-TOTP-BACKUP', $once instanceof WP_User && is_wp_error($twice), 'Recovery code is consumed exactly once');
    delete_user_meta($owner, PK_2FA_META_SECRET);

    $pid = wp_insert_post(['post_type' => 'properties', 'post_status' => 'publish', 'post_author' => $owner, 'post_title' => 'MERGE-' . $run], true);
    if (is_wp_error($pid)) throw new RuntimeException($pid->get_error_message());
    $properties[] = $pid;
    update_post_meta($pid, '_pk_owner_phone', $phone);
    update_post_meta($pid, '_pk_status', 'actif');
    $sync->flush();
    wp_set_current_user(0);
    $callback = rest_get_server()->get_routes()['/partikulier/v1/health'][0]['callback'];
    $public = $callback();
    $publicData = $public->get_data();
    $assert('MERGE-HEALTH-PUBLIC', array_keys($publicData) === ['status', 'database']
        && str_contains($public->get_headers()['Cache-Control'], 'no-store'), 'Public health is minimal and uncached');
    wp_set_current_user($admin->ID);
    $private = $callback();
    $assert('MERGE-HEALTH-ADMIN', isset($private->get_data()['core_version'], $private->get_data()['domains']), 'Admin health retains operational diagnostics');
    $wpdb = new MergeV3DatabaseProxy($realDb, 'query', 'SELECT 1', 1, 'Fixture database unavailable');
    $failedHealth = $callback();
    $assert('MERGE-HEALTH-DOWN', $failedHealth->get_status() === 503
        && $failedHealth->get_data()['database'] === 'unreachable'
        && $wpdb->calls === 1, 'DB-down health stops after the failed ping');
    $wpdb = $realDb;

    $snapshot = new ReflectionMethod(LeadService::class, 'property_snapshot');
    $taxonomy = $GLOBALS['wp_taxonomies']['es_status'] ?? null;
    unset($GLOBALS['wp_taxonomies']['es_status']);
    try {
        $data = $snapshot->invoke(null, $pid);
        $assert('MERGE-TAXONOMY', $data['transaction'] === '', 'Missing Estatik taxonomy does not crash lead snapshots');
    } finally {
        if ($taxonomy !== null) $GLOBALS['wp_taxonomies']['es_status'] = $taxonomy;
    }

    $qualify = static function (string $number) use ($pid): int {
        $initial = LeadService::authorize_contact($number, $pid, 'merge-qualify-' . wp_generate_uuid4());
        if (is_wp_error($initial) || ($initial->get_data()['reason'] ?? '') !== 'need_qualification'
            || !empty($initial->get_data()['allowed']) || isset($initial->get_data()['owner'])) {
            throw new RuntimeException('Cannot seed qualification without disclosing owner contact');
        }
        $request = new WP_REST_Request('POST', '/partikulier/v1/qualification');
        $request->set_param('wa_id', $number);
        $request->set_param('is_particulier', true);
        $qualified = LeadService::rest_set_qualification($request);
        if (!$qualified instanceof WP_REST_Response || ($qualified->get_data()['qualified'] ?? '') !== 'particulier') {
            throw new RuntimeException('Cannot qualify transaction fixture');
        }
        return (int) $initial->get_data()['lead_id'];
    };
    foreach ([
        ['get_var', 'phone_hash'],
        ['get_row', 'opt_out_at'],
        ['insert', 'pk_buyer_leads'],
        ['insert', 'pk_whatsapp_messages'],
        ['insert', 'pk_interest_events'],
        ['get_var', 'property_id'],
        ['query', 'INSERT INTO ' . $wpdb->prefix . 'pk_contact_limits'],
        ['insert', 'pk_contact_disclosures'],
        ['query', 'UPDATE ' . $wpdb->prefix . 'pk_contact_limits'],
        ['query', 'COMMIT'],
    ] as $i => [$method, $needle]) {
        $number = '2126' . random_int(10000000, 99999999);
        $phones[] = $number;
        $creating = $method === 'insert' && $needle === 'pk_buyer_leads';
        if (!$creating) $qualify($number);
        $wpdb = new MergeV3DatabaseProxy($realDb, $method, $needle, 1);
        $result = LeadService::authorize_contact($number, $pid, 'merge-fault-' . $run . '-' . $i);
        $expectedOutcome = !is_wp_error($result) && ($creating
            ? empty($result->get_data()['allowed']) && ($result->get_data()['reason'] ?? '') === 'need_qualification'
                && !isset($result->get_data()['owner'])
            : !empty($result->get_data()['allowed']));
        $assert('MERGE-RETRY-' . $i, $expectedOutcome && $wpdb->failures === 1 && $wpdb->transactions === 2,
            'One injected SQL deadlock rolls back and retries without bypassing qualification: ' . $needle);
        $wpdb = $realDb;
    }
    $number = '2126' . random_int(10000000, 99999999);
    $phones[] = $number;
    $wpdb = new MergeV3DatabaseProxy($realDb, 'get_var', 'phone_hash', 3);
    $exhausted = LeadService::authorize_contact($number, $pid, 'merge-exhausted-' . $run);
    $assert('MERGE-RETRY-BOUNDED', is_wp_error($exhausted) && $wpdb->transactions === 3 && $wpdb->failures === 3, 'Persistent deadlock stops after three attempts');
    $wpdb = $realDb;
    $qualifiedId = $qualify($number);
    $leadBeforeFailure = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pk_buyer_leads WHERE id = %d", $qualifiedId), ARRAY_A);
    $wpdb = new MergeV3DatabaseProxy($realDb, 'insert', 'pk_interest_events', 1, 'Fixture non-retryable insert error');
    $failure = LeadService::authorize_contact($number, $pid, 'merge-failed-' . $run);
    $assert('MERGE-RETRY-NONDEADLOCK', is_wp_error($failure) && $wpdb->transactions === 1, 'Non-deadlock failure is not returned as success or retried');
    $wpdb = $realDb;
    $residue = 0;
    foreach (['pk_whatsapp_messages', 'pk_interest_events', 'pk_contact_disclosures', 'pk_contact_limits'] as $table) {
        $residue += (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->prefix}{$table} WHERE lead_id = %d", $qualifiedId));
    }
    $leadAfterFailure = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}pk_buyer_leads WHERE id = %d", $qualifiedId), ARRAY_A);
    $assert('MERGE-RETRY-ATOMIC', $residue === 0 && $leadBeforeFailure === $leadAfterFailure,
        'Failed contact leaves no partial message, interest, disclosure or quota; qualified lead is unchanged');

    foreach (['same', 'distinct'] as $kind) {
        $jobs = [];
        $sharedPhone = '2126' . random_int(10000000, 99999999);
        for ($i = 0; $i < 10; $i++) {
            $number = $kind === 'same' ? $sharedPhone : '2126' . random_int(10000000, 99999999);
            $phones[] = $number;
            if ($kind === 'distinct' || $i === 0) $qualify($number);
            $process = proc_open([PHP_BINARY, __FILE__, '--contact-worker', (string) $pid, $number], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
            if (!is_resource($process)) throw new RuntimeException('Cannot start concurrency worker');
            $jobs[] = [$process, $pipes];
        }
        $ids = [];
        $ok = true;
        $allowedCount = 0;
        $manualCount = 0;
        foreach ($jobs as [$process, $pipes]) {
            $out = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]); fclose($pipes[2]);
            $code = proc_close($process);
            $data = json_decode($out, true);
            $allowed = !empty($data['allowed']);
            $manual = ($data['reason'] ?? '') === 'manual_review';
            $ok = $ok && $code === 0 && ($allowed || $manual);
            $allowedCount += (int) $allowed;
            $manualCount += (int) $manual;
            if (!$ok) fwrite(STDERR, $out . $err);
            $ids[] = $data['lead_id'] ?? 0;
        }
        $uniqueIds = array_unique(array_map('intval', $ids));
        $idList = implode(',', $uniqueIds);
        $expected = $kind === 'same' ? 1 : 10;
        $disclosures = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pk_contact_disclosures WHERE lead_id IN ({$idList})");
        $messages = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pk_whatsapp_messages WHERE lead_id IN ({$idList})");
        $interests = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}pk_interest_events WHERE lead_id IN ({$idList})");
        $quota = (int) $wpdb->get_var("SELECT SUM(contacts_count) FROM {$wpdb->prefix}pk_contact_limits WHERE lead_id IN ({$idList})");
        $expectedAllowed = $kind === 'same' ? 2 : 10;
        $assert('MERGE-CONCURRENT-' . $kind, $ok && count($uniqueIds) === $expected
            && $allowedCount === $expectedAllowed && $manualCount === 10 - $expectedAllowed
            && $disclosures === $expected && $quota === $expected && $messages === $expectedAllowed && $interests === 10,
            'Ten qualified parallel contacts preserve exact counts and third-contact manual review: ' . $kind);
    }

    $settings = is_array($originalSettings) ? $originalSettings : [];
    $settings['automation_api_secret'] = wp_generate_password(48);
    $settings['active_key_id'] = 'N';
    update_option('pk_n8n_settings', $settings, false);
    $headers = AutomationService::outgoing_headers('POST', 'https://example.test/webhook', '{}');
    $assert('MERGE-WEBHOOK', !is_wp_error($headers) && count($headers) === 5
        && !array_key_exists('X-Partikulier-Automation', $headers)
        && $headers['X-Partikulier-Algorithm'] === 'sha256' && preg_match('/^sha256=[a-f0-9]{64}$/', $headers['X-Partikulier-Signature']) === 1,
        'Outgoing n8n preserves five signed headers without transmitting the shared secret');

    wp_set_current_user($admin->ID);
    $demos = get_posts(['post_type' => 'properties', 'post_status' => 'any', 'meta_key' => '_pk_seed_demo', 'meta_value' => '1', 'numberposts' => -1, 'fields' => 'ids', 'lang' => '', 'suppress_filters' => true]);
    if ($demos !== []) throw new RuntimeException('Run demo contract on a site without pre-existing demo listings');
    $demoInstalled = true;
    $seed = Partikulier_Demo_Installer::seed();
    $demos = get_posts(['post_type' => 'properties', 'post_status' => 'publish', 'meta_key' => '_pk_seed_demo', 'meta_value' => '1', 'numberposts' => -1, 'fields' => 'ids', 'lang' => '', 'suppress_filters' => true]);
    $sources = array_filter($demos, static fn($id) => !get_post_meta($id, '_pk_translation_source', true));
    $galleryCount = count(array_filter($demos, static fn($id) => count((array) get_post_meta($id, 'es_property_gallery', true)) === 3));
    $rentCount = count(array_filter($sources, static fn($id) => has_term(['a-louer', 'louer', 'for-rent', 'rent'], PARTIKULIER_ESTATIK_STATUS_TAXONOMY, $id)));
    $languageCount = class_exists('Partikulier_Listing_Translations') && Partikulier_Listing_Translations::available()
        ? count(Partikulier_Listing_Translations::active_languages()) : 1;
    $assert('MERGE-DEMO-INSTALL', count($sources) === 30 && count($demos) === 30 * $languageCount
        && $galleryCount === count($demos) && $rentCount === 15 && !str_contains($seed, 'ERREUR') && !str_contains($seed, 'ÉCHEC'),
        sprintf('Demo requires 30 sources, 15 rentals, galleries on every variant: sources=%d total=%d languages=%d galleries=%d rentals=%d; %s', count($sources), count($demos), $languageCount, $galleryCount, $rentCount, $seed));
    Partikulier_Demo_Installer::purge();
    $left = get_posts(['post_type' => ['properties', 'attachment'], 'post_status' => 'any', 'meta_key' => '_pk_seed_demo', 'meta_value' => '1', 'numberposts' => -1, 'fields' => 'ids', 'lang' => '', 'suppress_filters' => true]);
    $assert('MERGE-DEMO-PURGE', $left === [] && get_post($pid) instanceof WP_Post, 'Demo purge removes marked data and preserves a real listing');
} catch (Throwable $error) {
    $assert('MERGE-UNEXPECTED', false, $error->getMessage());
} finally {
    $wpdb = $realDb;
    if ($demoInstalled) {
        Partikulier_Demo_Installer::purge();
        $demoOwner = get_user_by('login', 'demo-partikulier');
        if (!$demoOwnerBefore && $demoOwner instanceof WP_User) $users[] = $demoOwner->ID;
    }
    if ($rateKey) delete_transient($rateKey);
    foreach (array_unique($phones) as $number) {
        $hash = hash_hmac('sha256', $number, wp_salt('auth'));
        $id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$wpdb->prefix}pk_buyer_leads WHERE phone_hash = %s", $hash));
        if (!$id) continue;
        foreach (array_diff(\Partikulier\Core\Database\Migrator::LEADS_TABLES, ['pk_buyer_leads']) as $table) {
            $wpdb->delete($wpdb->prefix . $table, ['lead_id' => $id]);
        }
        $wpdb->delete($wpdb->prefix . 'pk_buyer_leads', ['id' => $id]);
    }
    foreach ($properties as $id) wp_delete_post($id, true);
    require_once $wpDir . '/wp-admin/includes/user.php';
    foreach ($users as $id) wp_delete_user($id);
    if ($originalSettings === null) delete_option('pk_n8n_settings');
    else update_option('pk_n8n_settings', $originalSettings, false);
    $sync->flush();
    wp_set_current_user($originalUser);
    if ($originalIp === null) unset($_SERVER['REMOTE_ADDR']);
    else $_SERVER['REMOTE_ADDR'] = $originalIp;
    $_POST = $originalPost;
}
$failed = array_values(array_filter($results, static fn($row) => $row['status'] !== 'PASS'));
echo wp_json_encode(['status' => $failed === [] ? 'PASS' : 'FAIL', 'total' => count($results), 'passed' => count($results) - count($failed), 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed === [] ? 0 : 1);
