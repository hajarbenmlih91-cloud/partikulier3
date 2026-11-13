<?php
/**
 * Contrat de non-régression V1 préproduction (correctifs P0 -> P2).
 *
 * Vérifie :
 *   - FIX-REPLAY-01 : rejeu d'une annonce déjà divulguée (`replayed=true`,
 *     nouveau `provider_message_id`) ne consomme pas `max_24h` / `max_7d` et
 *     reste autorisé même après épuisement du quota sur d'autres biens.
 *   - FIX-REPLAY-02 : rejeu exact du même `provider_message_id` retourne
 *     `duplicate_message` avant toute écriture ou incrément.
 *   - FIX-HEALTH-03 : `/partikulier/v1/health` déclenche
 *     `litespeed_control_set_nocache` et émet `Cache-Control: no-store...` +
 *     `X-LiteSpeed-Cache-Control: no-cache`.
 *   - FIX-CACHE-04 : `Partikulier_Cache::is_private_path()` et
 *     `exclude_private_pages_from_host_cache()` couvrent `contact`,
 *     `contact-en`, `contact-ar` (et `connexion`).
 *   - FIX-SINGLE-05 : sans `buyer_whatsapp_number`, aucun lien `wa.me/?text=`
 *     n'est rendu dans `estatik4/front/property/single.php` ni
 *     `templates/single.php` ; avec numéro configuré, le lien numérique est
 *     rendu.
 *   - FIX-COOKIES-06 : la bannière cookies EN n'émet plus `Learn more`, utilise
 *     un libellé descriptif (`Read the cookie policy`) et résout la variante
 *     localisée via `pll_get_post`.
 *   - FIX-FAVICON-07 : les icônes SVG/ICO et la feuille `style.min.css`
 *     existent et `<link rel="icon"` est présent dans `templates/header.php`.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> php plugin/partikulier-core/tests/v1-preprod-fixes-contract.php
 */

declare(strict_types=1);

$wpDir  = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) {
    fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n");
    exit(2);
}
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) {
    fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n");
    exit(2);
}

require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\Leads\LeadService;
use Partikulier\Core\Domain\Leads\LeadSettings;

$started = gmdate('c');
$results = [];
$assert  = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$run              = bin2hex(random_bytes(4));
$origThemeOpts    = get_option('pk_theme_options', null);
$origLeadSettings = get_option(LeadSettings::OPTION, null);
$createdPosts     = [];
$createdUsers     = [];
$createdPhones    = [];
$origRequestUri   = $_SERVER['REQUEST_URI'] ?? null;

try {
    // 1. FIX-REPLAY-01 / 02 : 3 propriétaires & 3 annonces synthétiques.
    $props = [];
    for ($i = 1; $i <= 3; $i++) {
        $uid = wp_insert_user([
            'user_login' => "fix_owner_{$run}_{$i}",
            'user_pass'  => wp_generate_password(24),
            'user_email' => "fix_owner_{$run}_{$i}@example.invalid",
            'role'       => 'contributor',
        ]);
        if (is_wp_error($uid)) {
            throw new RuntimeException($uid->get_error_message());
        }
        $createdUsers[] = (int) $uid;
        $pid = wp_insert_post([
            'post_type'   => PARTIKULIER_ESTATIK_POST_TYPE,
            'post_status' => 'publish',
            'post_title'  => "Bien Contrat V1 {$run}-{$i}",
            'post_author' => (int) $uid,
        ], true);
        if (is_wp_error($pid)) {
            throw new RuntimeException($pid->get_error_message());
        }
        $createdPosts[] = (int) $pid;
        $ownerPhone = '+21260011000' . $i;
        update_post_meta((int) $pid, '_pk_owner_phone', class_exists('Partikulier_Crypto') ? Partikulier_Crypto::encrypt_phone($ownerPhone) : $ownerPhone);
        $props[] = (int) $pid;
    }
    if (class_exists('\Partikulier\Core\Integration\ListingSynchronizer')) {
        (new \Partikulier\Core\Integration\ListingSynchronizer())->flush();
    }

    $leadOpt = is_array($origLeadSettings) ? $origLeadSettings : [];
    $leadOpt['limits'] = array_merge((array) ($leadOpt['limits'] ?? []), [
        'max_24h'     => 2,
        'window_24h'  => 24,
        'max_7d'      => 5,
        'window_7d'   => 7,
        'daily_limit' => 2,
    ]);
    update_option(LeadSettings::OPTION, $leadOpt, false);

    $buyerPhone = '2126' . random_int(10000000, 99999999);
    $createdPhones[] = $buyerPhone;
    $pre = LeadService::authorize_contact($buyerPhone, $props[0], "fix-pre-{$run}");
    $preData = $pre instanceof WP_REST_Response ? (array) $pre->get_data() : [];
    $leadId = (int) ($preData['lead_id'] ?? 0);
    if ($leadId > 0) {
        $wpdb->update($wpdb->prefix . 'pk_buyer_leads', ['is_particulier' => 1], ['id' => $leadId], ['%d'], ['%d']);
    }

    $r1 = LeadService::authorize_contact($buyerPhone, $props[0], "fix-m1-{$run}");
    $d1 = $r1 instanceof WP_REST_Response ? (array) $r1->get_data() : [];
    $rDup = LeadService::authorize_contact($buyerPhone, $props[0], "fix-m1-{$run}");
    $dDup = $rDup instanceof WP_REST_Response ? (array) $rDup->get_data() : [];
    $rReplay1 = LeadService::authorize_contact($buyerPhone, $props[0], "fix-m2-{$run}");
    $dReplay1 = $rReplay1 instanceof WP_REST_Response ? (array) $rReplay1->get_data() : [];
    $r2 = LeadService::authorize_contact($buyerPhone, $props[1], "fix-m3-{$run}");
    $d2 = $r2 instanceof WP_REST_Response ? (array) $r2->get_data() : [];
    $r3 = LeadService::authorize_contact($buyerPhone, $props[2], "fix-m4-{$run}");
    $d3 = $r3 instanceof WP_REST_Response ? (array) $r3->get_data() : [];
    $rReplayAfterCap = LeadService::authorize_contact($buyerPhone, $props[0], "fix-m5-{$run}");
    $dReplayAfterCap = $rReplayAfterCap instanceof WP_REST_Response ? (array) $rReplayAfterCap->get_data() : [];

    $assert(
        'FIX-REPLAY-01',
        !empty($d1['allowed']) && ($d1['replayed'] ?? null) === false
        && !empty($d1['owner']['phone']) && str_starts_with((string) $d1['owner']['phone'], '+212')
        && !empty($dReplay1['allowed']) && ($dReplay1['replayed'] ?? null) === true
        && !empty($d2['allowed']) && ($d2['replayed'] ?? null) === false
        && empty($d3['allowed']) && ($d3['reason'] ?? '') === 'manual_review'
        && !empty($dReplayAfterCap['allowed']) && ($dReplayAfterCap['replayed'] ?? null) === true,
        sprintf(
            'r1=%s/%s phoneOk=%s replay1=%s/%s r2=%s/%s r3=%s replay_after_cap=%s/%s',
            var_export($d1['allowed'] ?? null, true),
            var_export($d1['replayed'] ?? null, true),
            !empty($d1['owner']['phone']) && str_starts_with((string) $d1['owner']['phone'], '+212') ? 'yes' : 'no',
            var_export($dReplay1['allowed'] ?? null, true),
            var_export($dReplay1['replayed'] ?? null, true),
            var_export($d2['allowed'] ?? null, true),
            var_export($d2['replayed'] ?? null, true),
            (string) ($d3['reason'] ?? '?'),
            var_export($dReplayAfterCap['allowed'] ?? null, true),
            var_export($dReplayAfterCap['replayed'] ?? null, true)
        )
    );

    $assert(
        'FIX-REPLAY-02',
        empty($dDup['allowed']) && ($dDup['reason'] ?? '') === 'duplicate_message',
        'Rejeu exact du même provider_message_id -> duplicate_message'
    );

    // 2. FIX-HEALTH-03 : /health no-cache + hook LiteSpeed.
    $litespeedFired = false;
    $hookListener = static function () use (&$litespeedFired): void {
        $litespeedFired = true;
    };
    add_action('litespeed_control_set_nocache', $hookListener);
    $healthResp = rest_do_request(new WP_REST_Request('GET', '/partikulier/v1/health'));
    remove_action('litespeed_control_set_nocache', $hookListener);
    $healthHeaders = array_change_key_case($healthResp->get_headers(), CASE_LOWER);
    $healthData = (array) $healthResp->get_data();
    $ccHeader = (string) ($healthHeaders['cache-control'] ?? '');
    $lsHeader = (string) ($healthHeaders['x-litespeed-cache-control'] ?? '');
    $assert(
        'FIX-HEALTH-03',
        in_array($healthResp->get_status(), [200, 503], true)
        && isset($healthData['status'], $healthData['database'])
        && !isset($healthData['domains'])
        && $litespeedFired
        && str_contains($ccHeader, 'no-store')
        && str_contains($ccHeader, 'no-cache')
        && $lsHeader === 'no-cache',
        sprintf('status=%d payload=%s ls_action=%s Cache-Control=%s X-LiteSpeed-Cache-Control=%s', $healthResp->get_status(), (string) ($healthData['status'] ?? '?'), $litespeedFired ? 'yes' : 'no', $ccHeader, $lsHeader)
    );

    // 3. FIX-CACHE-04 : contact(/fr/contact/, /en/contact/, /ar/contact/, /contact-en/) exclu du cache.
    $refClass = new ReflectionClass('Partikulier_Cache');
    $isPrivateMethod = $refClass->getMethod('is_private_path');
    $isPrivateMethod->setAccessible(true);
    $contactPathsOk = true;
    foreach (['fr/contact', 'en/contact', 'ar/contact', 'contact-en', 'contact-ar', 'fr/connexion'] as $p) {
        $_SERVER['REQUEST_URI'] = '/' . $p . '/';
        if (!$isPrivateMethod->invoke(null)) {
            $contactPathsOk = false;
        }
    }
    $lsContactFired = false;
    $contactHook = static function () use (&$lsContactFired): void {
        $lsContactFired = true;
    };
    $_SERVER['REQUEST_URI'] = '/fr/contact/';
    add_action('litespeed_control_set_nocache', $contactHook);
    Partikulier_Cache::exclude_private_pages_from_host_cache();
    remove_action('litespeed_control_set_nocache', $contactHook);
    $assert(
        'FIX-CACHE-04',
        $contactPathsOk && $lsContactFired,
        sprintf('is_private_path_contact=%s exclude_host_cache_hook=%s', $contactPathsOk ? 'yes' : 'no', $lsContactFired ? 'yes' : 'no')
    );

    // 4. FIX-SINGLE-05 : rendu template single sans numéro -> 0 wa.me/?text= ; avec numéro -> wa.me/<digits>.
    $themeRoot = get_template_directory();
    $estatikSingle = (string) file_get_contents($themeRoot . '/estatik4/front/property/single.php');
    $themeSingle   = (string) file_get_contents($themeRoot . '/templates/single.php');
    $noBareFallback = !str_contains($estatikSingle, 'wa.me/?text=') && !str_contains($themeSingle, 'wa.me/?text=');

    $optsNoNum = is_array($origThemeOpts) ? $origThemeOpts : [];
    $optsNoNum['buyer_whatsapp_number'] = '';
    update_option('pk_theme_options', $optsNoNum);
    $urlEmpty = Partikulier_Buyer_Qualification::contact_url($props[0]);

    $optsWithNum = $optsNoNum;
    $optsWithNum['buyer_whatsapp_number'] = '212600000099';
    update_option('pk_theme_options', $optsWithNum);
    $urlSet = Partikulier_Buyer_Qualification::contact_url($props[0]);

    $origQueriedObj   = $GLOBALS['wp_query']->queried_object ?? null;
    $origQueriedObjId = $GLOBALS['wp_query']->queried_object_id ?? null;
    $origIsSingle     = $GLOBALS['wp_query']->is_single ?? false;
    $origIsSingular   = $GLOBALS['wp_query']->is_singular ?? false;
    $GLOBALS['post'] = get_post($props[0]);
    $GLOBALS['wp_query']->queried_object = $GLOBALS['post'];
    $GLOBALS['wp_query']->queried_object_id = $props[0];
    $GLOBALS['wp_query']->is_single = true;
    $GLOBALS['wp_query']->is_singular = true;
    setup_postdata($GLOBALS['post']);
    update_option('pk_theme_options', $optsNoNum);
    ob_start();
    $pk_single_file = $themeRoot . '/estatik4/front/property/single.php';
    if (is_file($pk_single_file)) {
        include $pk_single_file;
    }
    $htmlUnset = (string) ob_get_clean();
    wp_reset_postdata();
    $GLOBALS['wp_query']->queried_object = $origQueriedObj;
    $GLOBALS['wp_query']->queried_object_id = $origQueriedObjId;
    $GLOBALS['wp_query']->is_single = $origIsSingle;
    $GLOBALS['wp_query']->is_singular = $origIsSingular;

    $assert(
        'FIX-SINGLE-05',
        $noBareFallback
        && $urlEmpty === ''
        && str_starts_with($urlSet, 'https://wa.me/212600000099?text=')
        && !str_contains($htmlUnset, 'https://wa.me/?text=')
        && str_contains($htmlUnset, 'pk-single-contact-unavailable'),
        sprintf('noBareCode=%s empty=%s setOk=%s htmlNoBare=%s', $noBareFallback ? 'yes' : 'no', var_export($urlEmpty, true), str_starts_with($urlSet, 'https://wa.me/212600000099?text=') ? 'yes' : 'no', !str_contains($htmlUnset, 'https://wa.me/?text=') ? 'yes' : 'no')
    );

    // 5. FIX-COOKIES-06 : bannière cookies EN sans "Learn more", texte descriptif.
    $vcCode = (string) file_get_contents($themeRoot . '/inc/class-visit-counter.php');
    $assert(
        'FIX-COOKIES-06',
        !str_contains($vcCode, "'Learn more'")
        && str_contains($vcCode, 'Read the cookie policy')
        && str_contains($vcCode, 'Consulter la politique de cookies')
        && str_contains($vcCode, 'pll_get_post( $cookies_page->ID, $lang )'),
        'Bannière cookies EN utilise Read the cookie policy et pll_get_post'
    );

    $assert(
        'FIX-CRYPTO-08',
        Partikulier_Crypto::decrypt_phone($encTest = Partikulier_Crypto::encrypt_phone('+212611223344')) === ''
        && LeadService::decrypt_phone_for_admin($encTest) === ''
        && Partikulier_Crypto::read_phone($encTest) === '+212611223344'
        && Partikulier_Crypto::read_phone(substr($encTest, 0, -1) . (substr($encTest, -1) === 'A' ? 'B' : 'A')) === ''
        && Partikulier_Crypto::read_phone('+212611223344') === '+212611223344',
        'decrypt_phone/decrypt_phone_for_admin=manage_options only; read_phone decrypts GCM & rejects tampered GCM'
    );

    // 7. FIX-FAVICON-07 : favicon.svg, favicon.ico, header link, site hygiene handler, style.min.css.
    $headerCode = (string) file_get_contents($themeRoot . '/templates/header.php');
    $searchFormCode = (string) file_get_contents($themeRoot . '/templates/parts/search-form.php');
    $rawCss = $themeRoot . '/assets/css/style.css';
    $minCss = $themeRoot . '/assets/css/style.min.css';
    $favSvg = $themeRoot . '/assets/img/favicon.svg';
    $favIco = $themeRoot . '/assets/img/favicon.ico';
    $assert(
        'FIX-FAVICON-07',
        is_file($favSvg) && is_file($favIco) && filesize($favIco) > 64
        && str_contains($headerCode, 'favicon.svg')
        && method_exists('Partikulier_Site_Hygiene', 'output_favicon_ico')
        && is_file($minCss) && filesize($minCss) < filesize($rawCss),
        sprintf('svg=%s ico=%dB min=%dB raw=%dB', is_file($favSvg) ? 'yes' : 'no', is_file($favIco) ? (int) filesize($favIco) : 0, is_file($minCss) ? (int) filesize($minCss) : 0, is_file($rawCss) ? (int) filesize($rawCss) : 0)
    );

    // 8. FIX-A11Y-LABEL-09 : label-content-name-mismatch (WCAG 2.5.3) sur .pk-lang-toggle et .pk-find-type-btn.
    $assert(
        'FIX-A11Y-LABEL-09',
        str_contains($headerCode, "strtoupper( \$pk_current_lang )")
        && preg_match('/class="pk-lang-toggle"[^>]*aria-label="[^"]*strtoupper/', $headerCode) === 1
        && str_contains($searchFormCode, 'aria-labelledby="pk-find-type-label pk-find-type-current"')
        && str_contains($searchFormCode, 'id="pk-find-type-current"'),
        'pk-lang-toggle aria-label includes lang code and pk-find-type-btn aria-labelledby includes pk-find-type-current'
    );

    // 9. FIX-WA-NEWLINE-10 : esc_wa_url() conserve %0A dans ?text= et single.php le rend intact.
    $waEscaped = Partikulier_Buyer_Qualification::esc_wa_url($urlSet);
    update_option('pk_theme_options', $optsWithNum);
    $GLOBALS['post'] = get_post($props[0]);
    $GLOBALS['wp_query']->queried_object = $GLOBALS['post'];
    $GLOBALS['wp_query']->queried_object_id = $props[0];
    $GLOBALS['wp_query']->is_single = true;
    $GLOBALS['wp_query']->is_singular = true;
    setup_postdata($GLOBALS['post']);
    ob_start();
    if (is_file($pk_single_file)) {
        include $pk_single_file;
    }
    $htmlSet = (string) ob_get_clean();
    wp_reset_postdata();
    $GLOBALS['wp_query']->queried_object = $origQueriedObj;
    $GLOBALS['wp_query']->queried_object_id = $origQueriedObjId;
    $GLOBALS['wp_query']->is_single = $origIsSingle;
    $GLOBALS['wp_query']->is_singular = $origIsSingular;

    $assert(
        'FIX-WA-NEWLINE-10',
        str_contains($waEscaped, '%0A') && str_contains($htmlSet, '%0A'),
        sprintf('waEscapedHas0A=%s singleHtmlHas0A=%s', str_contains($waEscaped, '%0A') ? 'yes' : 'no', str_contains($htmlSet, '%0A') ? 'yes' : 'no')
    );

    // 10. FIX-FOOTER-11 : footer.php ne contient plus aucun lien mort href="#".
    $footerCode = (string) file_get_contents($themeRoot . '/templates/footer.php');
    $assert(
        'FIX-FOOTER-11',
        !str_contains($footerCode, 'href="#"'),
        str_contains($footerCode, 'href="#"') ? 'footer.php still has href="#"' : '0 placeholder href="#" in footer.php'
    );

    // 11. FIX-CONTACT-AR-12 : Partikulier_Contact expose des libellés arabes (consent, submit, ui_lang).
    $contactCode = (string) file_get_contents($themeRoot . '/inc/class-contact.php');
    $assert(
        'FIX-CONTACT-AR-12',
        str_contains($contactCode, 'CONSENT_AR')
        && str_contains($contactCode, "'ar' === \$lang")
        && method_exists('Partikulier_Contact', 'submit_label')
        && method_exists('Partikulier_Contact', 'message_label'),
        'Partikulier_Contact supports fr/en/ar across consent, submit, message, errors and ui_lang'
    );

    // 12. FIX-DEMO-FACTS-13 : cli/demo-facts.php porte es_property_price et status_slug a-vendre sur les 4 exemples.
    $demoFactsCode = (string) file_get_contents($themeRoot . '/cli/demo-facts.php');
    $assert(
        'FIX-DEMO-FACTS-13',
        substr_count($demoFactsCode, "'es_property_price'") >= 4
        && substr_count($demoFactsCode, "'status_slug' => 'a-vendre'") >= 4
        && str_contains($demoFactsCode, 'pk_demo_status_term_id'),
        'demo-facts.php assigns sale prices and a-vendre status terms to all 4 examples'
    );

    // 13. FIX-HYGIENE-DOCTOR-14 : Options -Indexes dans ensure_htaccess, es_status dans Page Doctor et pk_localized_home_url dans pk-diagnostic.
    $hygieneCode = (string) file_get_contents($themeRoot . '/inc/class-site-hygiene.php');
    $doctorCode = (string) file_get_contents($themeRoot . '/inc/class-page-doctor.php');
    $diagCode = (string) file_get_contents($themeRoot . '/pk-diagnostic.php');
    $assert(
        'FIX-HYGIENE-DOCTOR-14',
        str_contains($hygieneCode, 'Options -Indexes')
        && str_contains($doctorCode, 'PARTIKULIER_ESTATIK_STATUS_TAXONOMY')
        && str_contains($diagCode, 'pk_localized_home_url'),
        'Site Hygiene emits Options -Indexes, Page Doctor checks es_status, and pk-diagnostic resolves localized home URLs'
    );
} catch (Throwable $e) {
    $assert('FIX-UNEXPECTED', false, $e->getMessage());
} finally {
    if ($origRequestUri === null) {
        unset($_SERVER['REQUEST_URI']);
    } else {
        $_SERVER['REQUEST_URI'] = $origRequestUri;
    }
    if ($origThemeOpts === null) {
        delete_option('pk_theme_options');
    } else {
        update_option('pk_theme_options', $origThemeOpts);
    }
    if ($origLeadSettings === null) {
        delete_option(LeadSettings::OPTION);
    } else {
        update_option(LeadSettings::OPTION, $origLeadSettings, false);
    }
    foreach ($createdPhones as $ph) {
        $lid = LeadService::lead_id_for_phone($ph);
        if ($lid > 0) {
            LeadService::erase_lead($lid);
        }
    }
    foreach ($createdPosts as $pid) {
        wp_delete_post($pid, true);
    }
    if (class_exists('\Partikulier\Core\Integration\ListingSynchronizer')) {
        (new \Partikulier\Core\Integration\ListingSynchronizer())->flush();
    }
    foreach ($createdUsers as $uid) {
        wp_delete_user($uid);
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite'       => 'v1-preprod-fixes-contract',
    'started_at'  => $started,
    'finished_at' => gmdate('c'),
    'commit'      => $commit,
    'results'     => $results,
    'status'      => $failed ? 'FAIL' : 'PASS',
    'total'       => count($results),
    'passed'      => count($results) - count($failed),
    'failed'      => count($failed),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), "\n";
exit($failed ? 1 : 0);
