<?php
/**
 * Contrat SE-048-U — audit premium par bien + espace premium découvrable et
 * honnête (E-4802/E-4803, micro-lot pré-prod 2.10.8/6.20.7, DP-8 = maintien
 * de la promesse « annonce premium », règlement hors ligne).
 *
 * R1 (pré-correctif, mesuré sur le code) : l'audit premium_granted portait
 * l'ID DE LIGNE du journal comme object_id (le bien vivait en metadata) ;
 * le sous-menu « Annonces premium » était rattaché au menu Estatik
 * (edit.php?post_type=properties — parent d'un plugin tiers), l'URL
 * canonique admin.php?page=pk-premium n'était pas servie, et le bandeau
 * ne disait rien du mode de règlement (DP-8).
 *
 * Ce contrat verrouille :
 *  - E48-001 (E-4802) : grant → ligne d'audit premium_granted avec
 *    object_id = property_id (l'annonce est l'objet), metadata.history_id
 *    = la ligne du journal, propriété/acteur/période cohérents ;
 *  - E48-002 (E-4803) : le sous-menu est rattaché au menu top-level
 *    « partikulier » du THÈME (parent réel, sans dépendance Estatik) ;
 *  - E48-003 (E-4803) : l'URL canonique du sous-menu est
 *    admin.php?page=pk-premium (l'entrée du menu la porte) ;
 *  - E48-004 (E-4803) : l'écran rendu porte le bandeau d'état honnête
 *    (octroi manuel après virement/transfert d'argent, paiement en ligne
 *    non activé, visibilité publique explicitement non encore activée) ;
 *  - E48-005 (E-4803) : les redirections internes (octroi/retrait) visent
 *    l'URL canonique admin.php?page=pk-premium — plus l'ancien parent ;
 *  - E48-006 : aucune promesse de paiement en ligne (absence de
 *    « carte bancaire », « paiement immédiat », « réglez en ligne »).
 *
 * Sortie propre : annonce, attribution et audits du run purgés.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_REPO_DIR=<racine> php
 *   partikulier-core/tests/se048-premium-honesty-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
$repoDir = getenv('PK_REPO_DIR') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
if ($repoDir === '' || !is_dir($repoDir)) { fwrite(STDERR, "PK_REPO_DIR doit pointer vers la racine du dépôt\n"); exit(2); }
$repoDir = rtrim($repoDir, '/');
require $wpDir . '/wp-load.php';

use Partikulier\Core\Domain\Premium\PremiumService;

/* Écran d'administration : fonctions de rendu du wp-admin (absentes en CLI). */
require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/template.php';

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

global $wpdb;
$prefix = $wpdb->prefix;
wp_set_current_user(1);
$run = bin2hex(random_bytes(4));

$propertyId = wp_insert_post([
    'post_type' => 'properties', 'post_status' => 'publish',
    'post_title' => 'SE048 ' . $run . ' annonce premium', 'post_author' => 1,
]);
$adminId = (int) get_current_user_id();

try {
    // 1) E-4802 : l'audit porte l'ANNONCE comme objet.
    $starts = gmdate('Y-m-d H:i:s', time() + 3600);
    $ends = gmdate('Y-m-d H:i:s', time() + 30 * 86400);
    $grantId = Partikulier_Premium::grant($propertyId, $adminId, 'SE-048-U ' . $run . ' : règlement reçu par virement', $starts, $ends);
    $audit = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$prefix}pk_audit_log WHERE action = %s AND object_type = %s ORDER BY id DESC LIMIT 1",
        'premium_granted', 'premium_grant'
    ));
    $meta = $audit ? json_decode((string) $audit->metadata_json, true) : null;
    $ok4802 = !is_wp_error($grantId) && (int) $grantId > 0
        && $audit !== null
        && (int) $audit->object_id === (int) $propertyId
        && is_array($meta) && (int) ($meta['history_id'] ?? 0) === (int) $grantId
        && (int) ($meta['property_id'] ?? 0) === (int) $propertyId;
    $assert('E48-001', $ok4802,
        sprintf('audit premium_granted : object_id = %s (attendu property_id %d), metadata.history_id = %s (attendu ligne %d)',
            $audit ? $audit->object_id : '—', $propertyId,
            is_array($meta) ? var_export($meta['history_id'] ?? null, true) : '—', (int) $grantId));

    // 2-3) E-4803 : parent réel + URL canonique.
    do_action('admin_menu');
    global $menu, $submenu;
    $topLevel = isset($menu) && is_array($menu) ? wp_list_pluck($menu, 2) : [];
    $hasTop = in_array('partikulier', (array) $topLevel, true);
    $entry = null;
    foreach ((array) ($submenu['partikulier'] ?? []) as $item) {
        if (isset($item[2]) && 'pk-premium' === $item[2]) { $entry = $item; break; }
    }
    // L'URL canonique d'un sous-menu de menu TOP-LEVEL est admin.php?page=<slug>
    // — menu_page_url() ne la produit QUE dans ce cas (un parent sous-URL
    // comme edit.php?post_type=properties donnerait edit.php?...&page=<slug>).
    $canonical = admin_url('admin.php?page=pk-premium');
    $menuUrl = function_exists('menu_page_url') ? (string) menu_page_url('pk-premium', false) : '';
    $assert('E48-002', $hasTop && $entry !== null && strpos((string) ($entry[2] ?? ''), 'edit.php') === false,
        sprintf('sous-menu « Annonces premium » : parent %s, entrée %s (menu top-level partikulier du thème, plus le menu Estatik)',
            $hasTop ? 'partikulier (top-level) ✓' : 'ABSENT', $entry ? 'présente ✓' : 'ABSENTE'));
    $assert('E48-003', $menuUrl !== '' && untrailingslashit($menuUrl) === untrailingslashit($canonical),
        sprintf('URL canonique : menu_page_url(pk-premium) = « %s » (attendu « %s » — servie, pas 403 : le hook est enregistré pour un parent top-level)',
            $menuUrl ?: '—', $canonical));

    // 4) E-4803 : bandeau d'état honnête (écran rendu).
    ob_start();
    Partikulier_Premium::render_admin_page();
    $html = (string) ob_get_clean();
    $honestPayment = strpos($html, 'virement bancaire ou transfert d’argent') !== false
        && strpos($html, 'paiement en ligne n’est pas activé') !== false;
    $honestVisibility = strpos($html, 'visibilité publique') !== false
        && strpos($html, 'n’est pas encore activée') !== false;
    $assert('E48-004', $honestPayment && $honestVisibility,
        sprintf('bandeau honnête : règlement hors ligne %s, paiement en ligne non activé %s, visibilité non encore activée %s',
            $honestPayment ? '✓' : 'ABSENT', $honestPayment ? '✓' : 'ABSENT', $honestVisibility ? '✓' : 'ABSENT'));

    // 5) E-4803 : redirections internes vers l'URL canonique.
    $source = (string) file_get_contents($repoDir . '/theme/partikulier/inc/class-premium.php');
    $redirectCanonical = strpos($source, "admin_url( 'admin.php?page=pk-premium' )") !== false;
    $redirectLegacy = strpos($source, 'edit.php?post_type=' . "' . PARTIKULIER_ESTATIK_POST_TYPE . '&page=pk-premium") !== false;
    $assert('E48-005', $redirectCanonical && !$redirectLegacy,
        sprintf('redirections : cible canonique admin.php?page=pk-premium %s, ancien parent Estatik %s',
            $redirectCanonical ? '✓' : 'ABSENT', $redirectLegacy ? 'ENCORE PRÉSENT' : 'retiré ✓'));

    // 6) Aucune promesse de paiement en ligne.
    $forbidden = ['carte bancaire', 'paiement immédiat', 'réglez en ligne', 'paiement sécurisé en ligne'];
    $found = array_values(array_filter($forbidden, static fn($p): bool => stripos($html, $p) !== false));
    $assert('E48-006', $found === [],
        $found === [] ? 'aucune promesse de paiement en ligne sur l’écran (DP-8 : octroi manuel après règlement hors ligne)'
            : 'promesses interdites trouvées : ' . implode(', ', $found));
} catch (Throwable $error) {
    $assert('E48-EXCEPTION', false, $error->getMessage());
} finally {
    if ($propertyId > 0) { wp_delete_post($propertyId, true); }
    $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}pk_premium_history WHERE property_id = %d", (int) $propertyId));
    $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}pk_audit_log WHERE action = %s AND object_id = %d", 'premium_granted', (int) $propertyId));
    delete_post_meta($propertyId, PremiumService::META_STATUS);
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se048-premium-honesty-contract (audit premium par bien + espace découvrable honnête, E-4802/E-4803)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/se048-premium-honesty-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
