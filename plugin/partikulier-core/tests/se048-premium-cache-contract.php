<?php
/**
 * Contrat SE-048-R — expiration du premium et caches (E-4809).
 *
 * Exigence (CDC §8.3-4, premier test nommé) : « un premium expiré doit
 * RÉELLEMENT disparaître des pages servies en cache — purge des caches de
 * listes à chaque transition grant/revoke, bornage documenté de l'effet de
 * l'expiration paresseuse (TTL maximal pendant lequel une liste en cache peut
 * encore trier premium-first un premium échu) ».
 *
 * Ce que ce contrat prouve, sur les fichiers de cache RÉELS du thème :
 *   - toute transition (octroi, retrait, expiration paresseuse) purge ;
 *   - la garde d'échéance empêche de SERVIR une copie après l'échéance : la
 *     fenêtre résiduelle n'est donc pas le TTL (12 h) mais ZÉRO ;
 *   - le balayage d'échéance est borné et referme réellement la ligne du
 *     journal, puis l'option est recalculée (aucune purge à répétition) ;
 *   - hors premium, la garde ne coûte rien : aucune purge, aucune écriture.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_REPO_DIR=<racine> php
 *   wp-content/plugins/partikulier-core/tests/se048-premium-cache-contract.php
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

$flagBefore = (string) get_option(PremiumService::OPTION_PUBLIC_ENABLED, '0');
$nextEndBefore = get_option(PremiumService::OPTION_NEXT_END, null);
$created = [];
$run = bin2hex(random_bytes(4));

/** Fichier de cache réel du thème (même dossier que le module de cache). */
$cache_dir = static function (): string {
    $upload = wp_get_upload_dir();
    return trailingslashit($upload['basedir']) . 'partikulier-cache';
};
$pose_cache = static function (string $nom) use ($cache_dir): string {
    if (!is_dir($cache_dir())) { wp_mkdir_p($cache_dir()); }
    $fichier = $cache_dir() . '/' . $nom;
    file_put_contents($fichier, '<!doctype html><html><body>copie de liste SE-048-R</body></html>');
    return $fichier;
};
$cache_present = static fn(string $f): bool => is_file($f);

$make = static function (string $title) use (&$created): int {
    $id = (int) wp_insert_post(['post_type' => 'properties', 'post_status' => 'publish', 'post_title' => $title, 'post_author' => 1]);
    $created[] = $id;
    return $id;
};

try {
    update_option(PremiumService::OPTION_PUBLIC_ENABLED, '1');

    /* ═══ 1. L'octroi purge les copies en cache ═════════════════════════════ */
    $annonce = $make('SE048-CAC ' . $run . ' annonce');
    $copie = $pose_cache('se048-premium-' . $run . '-octroi.html');
    $purgeOctroi = false;
    $grant = Partikulier_Premium::grant($annonce, 1, 'SE-048-R ' . $run . ' : octroi', gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() + 30 * 86400));
    $purgeOctroi = !$cache_present($copie);
    $assert('SE048-CAC-001', !is_wp_error($grant) && $purgeOctroi,
        sprintf('octroi : copie de liste posée avant l’attribution (%s) → %s après (l’action pk_premium_state_changed purge le dossier réel %s)',
            'présente', $purgeOctroi ? 'supprimée ✓' : 'ENCORE PRÉSENTE', $cache_dir()));

    /* ═══ 2. Le retrait purge aussi ═════════════════════════════════════════ */
    $copie2 = $pose_cache('se048-premium-' . $run . '-retrait.html');
    $retire = Partikulier_Premium::revoke($annonce, 1, 'SE-048-R ' . $run . ' : retrait');
    $assert('SE048-CAC-002', true === $retire && !$cache_present($copie2),
        sprintf('retrait : copie posée avant le retrait (%s) → %s après', 'présente', $cache_present($copie2) ? 'ENCORE PRÉSENTE' : 'supprimée ✓'));

    /* ═══ 3. L'expiration paresseuse purge aussi (elle est une transition) ══ */
    Partikulier_Premium::grant($annonce, 1, 'SE-048-R ' . $run . ' : premium déjà échu', gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() - 3600));
    $copie3 = $pose_cache('se048-premium-' . $run . '-expiration.html');
    $actif = Partikulier_Premium::is_active($annonce); // déclenche l'expiration paresseuse
    $assert('SE048-CAC-003', false === $actif && !$cache_present($copie3)
        && (string) get_post_meta($annonce, PremiumService::META_STATUS, true) === PremiumService::STATUS_EXPIRED,
        sprintf('expiration paresseuse : is_active() = %s, statut méta = %s, copie %s',
            false === $actif ? 'false ✓' : 'TRUE (anomalie)',
            (string) get_post_meta($annonce, PremiumService::META_STATUS, true),
            $cache_present($copie3) ? 'ENCORE PRÉSENTE' : 'supprimée ✓'));

    /* ═══ 4. La garde d'échéance interdit de SERVIR une copie ══════════════ */
    // Reproduction exacte du cas dangereux : une copie existe, l'échéance la plus
    // proche est atteinte, aucun code n'a relu l'annonce (page servie en cache).
    Partikulier_Premium::grant($annonce, 1, 'SE-048-R ' . $run . ' : premium à échoir', gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() + 3600));
    update_option(PremiumService::OPTION_NEXT_END, gmdate('Y-m-d H:i:s', time() - 120)); // échéance atteinte
    $copie4 = $pose_cache('se048-premium-' . $run . '-garde.html');
    $garde = Partikulier_Cache::premium_expiry_reached();
    $assert('SE048-CAC-004', true === $garde && !$cache_present($copie4),
        sprintf('garde d’échéance : premium_expiry_reached() = %s (vrai = la copie n’est PAS servie), copie %s, option d’échéance était dans le passé de 120 s',
            true === $garde ? 'true ✓' : 'false (ANOMALIE : la copie serait servie)',
            $cache_present($copie4) ? 'ENCORE PRÉSENTE' : 'supprimée ✓'));

    /* ═══ 5. Balayage borné : la ligne est réellement close ════════════════ */
    $lignes = $wpdb->get_results($wpdb->prepare(
        "SELECT status, ends_at FROM {$prefix}pk_premium_history WHERE property_id = %d ORDER BY id DESC",
        $annonce
    ), ARRAY_A);
    $statuts = array_column((array) $lignes, 'status');
    $optionApres = (string) get_option(PremiumService::OPTION_NEXT_END, '');
    $plusDEcheancePassee = '' === $optionApres || strtotime($optionApres . ' UTC') > time();
    $assert('SE048-CAC-005', in_array('expired', $statuts, true) && $plusDEcheancePassee,
        sprintf('balayage borné : journal = [%s], option d’échéance après balayage = « %s » (%s — pas de purge à répétition)',
            implode(', ', $statuts), $optionApres,
            $plusDEcheancePassee ? 'recalculée ✓' : 'TOUJOURS DANS LE PASSÉ (purge en boucle)'));

    /* ═══ 6. Hors premium : la garde ne coûte rien ═════════════════════════ */
    delete_option(PremiumService::OPTION_NEXT_END);
    $copie6 = $pose_cache('se048-premium-' . $run . '-neutre.html');
    $gardeNeutre = Partikulier_Cache::premium_expiry_reached();
    $assert('SE048-CAC-006', false === $gardeNeutre && $cache_present($copie6),
        sprintf('hors premium (aucune échéance enregistrée) : garde = %s, copie %s — le module de cache ne paie rien quand aucun premium n’existe',
            false === $gardeNeutre ? 'false ✓' : 'TRUE (purge gratuite)', $cache_present($copie6) ? 'intacte ✓' : 'SUPPRIMÉE (anomalie)'));
    if ($cache_present($copie6)) { wp_delete_file($copie6); }

    /* ═══ 7. Ordre du code : la garde précède la lecture du fichier ════════ */
    $sourceCache = (string) file_get_contents($repoDir . '/theme/partikulier/inc/class-cache.php');
    $posGarde = strpos($sourceCache, 'if ( self::premium_expiry_reached() )');
    $posLecture = strpos($sourceCache, '( time() - filemtime( $cache_file ) ) < self::TTL');
    $posHook = strpos($sourceCache, "add_action( 'pk_premium_state_changed', array( __CLASS__, 'purge_all' ) );");
    $assert('SE048-CAC-007', false !== $posGarde && false !== $posLecture && false !== $posHook && $posGarde < $posLecture,
        sprintf('ordre et accroche du module de cache : accroche de purge à l’offset %s, garde à %s, lecture du fichier à %s — la garde est bien AVANT la lecture (l’ordre est la preuve, pas le commentaire)',
            false === $posHook ? 'ABSENTE' : (string) $posHook, false === $posGarde ? 'ABSENTE' : (string) $posGarde, false === $posLecture ? 'ABSENTE' : (string) $posLecture));

    /* ═══ 8. Séquence complète : la copie ne survit pas à l'échéance ═══════ */
    $sequence = $make('SE048-CAC ' . $run . ' séquence');
    // Cas dangereux RÉEL : l'échéance est DÉPASSÉE et la ligne du journal est encore
    // 'active' (expiration paresseuse : personne n'a relu l'annonce, la page est
    // servie depuis une copie). Aucune valeur n'est écrite à la main : c'est grant()
    // qui enregistre l'échéance réelle.
    Partikulier_Premium::grant($sequence, 1, 'SE-048-R ' . $run . ' : séquence', gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() - 60));
    $copie8 = $pose_cache('se048-premium-' . $run . '-sequence.html');
    $echu = (string) get_option(PremiumService::OPTION_NEXT_END, '');
    // On avance le temps : l'échéance enregistrée est dépassée.
    $ligneEncoreActive = (string) get_post_meta($sequence, PremiumService::META_STATUS, true) === PremiumService::STATUS_ACTIVE;
    $serviApresEcheance = !Partikulier_Cache::premium_expiry_reached();
    $visibleApres = Partikulier_Premium::is_publicly_visible($sequence);
    $assert('SE048-CAC-008', false === $serviApresEcheance && !$cache_present($copie8) && false === $visibleApres,
        sprintf('séquence complète : échéance « %s » dépassée avec la ligne encore « active » (%s, cas paresseux) → copie servie ? %s, premium encore visible ? %s (fenêtre résiduelle = 0, pas le TTL de 12 h)',
            $echu, $ligneEncoreActive ? 'oui' : 'non', $serviApresEcheance ? 'OUI (anomalie)' : 'non ✓', $visibleApres ? 'OUI (anomalie)' : 'non ✓'));
} catch (Throwable $error) {
    $assert('SE048-CAC-EXCEPTION', false, $error->getMessage());
} finally {
    update_option(PremiumService::OPTION_PUBLIC_ENABLED, $flagBefore);
    if (null === $nextEndBefore) { delete_option(PremiumService::OPTION_NEXT_END); }
    else { update_option(PremiumService::OPTION_NEXT_END, $nextEndBefore); }
    foreach ((array) glob($cache_dir() . '/se048-premium-*.html') as $reste) { wp_delete_file((string) $reste); }
    foreach ($created as $id) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}pk_premium_history WHERE property_id = %d", (int) $id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}pk_audit_log WHERE object_id = %d AND action LIKE 'premium_%%'", (int) $id));
        wp_delete_post((int) $id, true);
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se048-premium-cache-contract (E-4809 : purge à chaque transition, garde d’échéance, balayage borné, fenêtre résiduelle nulle)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/se048-premium-cache-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);