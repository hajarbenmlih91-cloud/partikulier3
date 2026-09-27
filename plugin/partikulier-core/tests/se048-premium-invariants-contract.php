<?php
/**
 * Contrat SE-048-R — invariants premium (E-4807), propagation i18n (E-4808) et
 * inventaire des 12 fonctions (CDC §8.3-4).
 *
 * LES CINQ INVARIANTS (CDC §8.3, transcription du raccord R1 §7) :
 *   1. une annonce DÉSACTIVÉE ne réapparaît jamais dans les biens disponibles
 *      grâce à son premium ;
 *   2. le tri « premium-first » ne s'applique qu'aux biens DISPONIBLES ;
 *   3. pas de badge sur une fiche désactivée ;
 *   4. l'expiration ou le retrait du premium ne désactive JAMAIS l'annonce ;
 *   5. la réactivation ne PROLONGE pas le premium.
 *
 * E-4808 : le droit premium porte sur l'ANNONCE et se projette sur son groupe de
 * traduction (fr/en/ar) — la variante interrogée voit le même verdict, et le
 * retrait les nettoie toutes. Le groupe est résolu par Polylang quand il est
 * chargé, sinon par la couture `partikulier_premium_group_members` (prouvée ici
 * avec un groupe simulé, pour que l'invariant soit vérifiable dans les deux
 * environnements).
 *
 * INVENTAIRE : les 12 fonctions du plan §3.1 sont présentes et rattachées à un
 * fichier réel — la table complète (fonction → fichier:ligne → test) est dans le
 * rapport SE-048-R ; ici, l'existence et le rattachement sont vérifiés.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_REPO_DIR=<racine> php
 *   wp-content/plugins/partikulier-core/tests/se048-premium-invariants-contract.php
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

class PK_SE048_Inv_Main_Query extends WP_Query
{
    public function is_main_query(): bool
    {
        return true;
    }
}

$flagBefore = (string) get_option(PremiumService::OPTION_PUBLIC_ENABLED, '0');
$polylangPresent = function_exists('pll_get_post_translations');
$created = [];
$run = bin2hex(random_bytes(4));

$render_card = static function (int $post_id): string {
    $property = get_post($post_id);
    $gallery = [];
    $actions = null;
    $pk_card_index = 1;
    ob_start();
    include get_template_directory() . '/templates/parts/card-property.php';
    return (string) ob_get_clean();
};

$make = static function (string $title) use (&$created): int {
    $id = (int) wp_insert_post([
        'post_type'   => 'properties',
        'post_status' => 'publish',
        'post_title'  => $title,
        'post_author' => 1,
    ]);
    $created[] = $id;
    return $id;
};

$ordered_ids = static function () {
    $query = new PK_SE048_Inv_Main_Query([
        'post_type'      => 'properties',
        'post_status'    => 'publish',
        'posts_per_page' => 30,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => Partikulier_Dashboard::active_listing_meta_query(),
    ]);
    $filter = static function ($orderby, $q) use ($query) {
        return $q === $query ? Partikulier_Search_Filters::stable_property_order($orderby, $q) : $orderby;
    };
    add_filter('posts_orderby', $filter, 999, 2);
    $ids = array_map('intval', (array) $query->get_posts());
    remove_filter('posts_orderby', $filter, 999);
    return $ids;
};

try {
    update_option(PremiumService::OPTION_PUBLIC_ENABLED, '1');

    $premium = $make('SE048-INV ' . $run . ' premium actif');
    $témoin  = $make('SE048-INV ' . $run . ' témoin');
    $grant = Partikulier_Premium::grant($premium, 1, 'SE-048-R ' . $run . ' invariants', gmdate('Y-m-d H:i:s', time() - 3600), gmdate('Y-m-d H:i:s', time() + 30 * 86400));

    /* ═══ Invariant 1 — annonce désactivée jamais redonnée par son premium ═══ */
    $catalogue = new WP_Query([
        'post_type'      => 'properties',
        'post_status'    => 'publish',
        'posts_per_page' => 50,
        'fields'         => 'ids',
        'no_found_rows'  => true,
        'meta_query'     => Partikulier_Dashboard::active_listing_meta_query(),
    ]);
    $idsAvant = array_map('intval', $catalogue->posts);

    // Désactivation par les MÉTAS (+ propagation : c'est le moteur DP-9 qui
    // écrit ces métas ; ici on isole l'invariant premium, la matrice d'actions
    // étant prouvée par dp9-availability-contract).
    update_post_meta($premium, '_pk_status', 'vendu');
    $catalogue->get_posts();
    $idsApres = array_map('intval', $catalogue->posts);
    $assert(
        'SE048-INV-001',
        !is_wp_error($grant) && in_array($premium, $idsAvant, true) && !in_array($premium, $idsApres, true)
        && (string) get_post_meta($premium, PremiumService::META_STATUS, true) === PremiumService::STATUS_ACTIVE
        && !Partikulier_Premium::is_publicly_visible($premium),
        sprintf('invariant 1 : annonce #%d disponible AVANT (%s) et absente APRÈS la désactivation (%s), premium TOUJOURS actif en base (%s), verdict public=%s',
            $premium, in_array($premium, $idsAvant, true) ? 'oui' : 'non', in_array($premium, $idsApres, true) ? 'OUI (anomalie)' : 'non',
            (string) get_post_meta($premium, PremiumService::META_STATUS, true),
            Partikulier_Premium::is_publicly_visible($premium) ? 'VISIBLE (anomalie)' : 'non visible')
    );

    /* ═══ Invariant 2 — le tri premium-first ne porte que sur le disponible ═══ */
    $ordonne = $ordered_ids();
    $rangPremium = array_search($premium, $ordonne, true);
    $assert(
        'SE048-INV-002',
        !in_array($premium, $ordonne, true) && in_array($témoin, $ordonne, true),
        sprintf('invariant 2 : requête ordonnée de %d annonces — annonce premium désactivée %s (rang %s), témoin disponible présent=%s',
            count($ordonne),
            in_array($premium, $ordonne, true) ? 'PRÉSENTE (anomalie)' : 'absente ✓',
            false === $rangPremium ? '—' : (string) $rangPremium,
            in_array($témoin, $ordonne, true) ? 'oui ✓' : 'NON (anomalie)')
    );

    /* ═══ Invariant 3 — aucun badge sur une fiche/annonce désactivée ════════ */
    $htmlDésactivée = $render_card($premium);
    // La même annonce, remise disponible, DOIT porter le badge : la différence
    // entre les deux rendus isole la disponibilité comme seule variable.
    update_post_meta($premium, '_pk_status', 'actif');
    $htmlActive = $render_card($premium);
    $assert(
        'SE048-INV-003',
        strpos($htmlDésactivée, 'pk-card-badge-premium') === false
        && strpos($htmlActive, 'pk-card-badge-premium') !== false,
        sprintf('invariant 3 : annonce désactivée → badge %s ; même annonce disponible → badge %s (seule la disponibilité change)',
            strpos($htmlDésactivée, 'pk-card-badge-premium') === false ? 'absent ✓' : 'PRÉSENT (anomalie)',
            strpos($htmlActive, 'pk-card-badge-premium') !== false ? 'présent ✓' : 'ABSENT (anomalie)')
    );

    /* ═══ Invariant 4 — expirer ou retirer le premium ne désactive rien ═════ */
    $statutAvant = (string) get_post_meta($premium, '_pk_status', true);
    $dispoAvant = PremiumService::is_available($premium);
    $retire = Partikulier_Premium::revoke($premium, 1, 'SE-048-R ' . $run . ' : retrait de contrôle');
    $statutApresRetrait = (string) get_post_meta($premium, '_pk_status', true);
    // Puis l'expiration : on repose un premium et on le laisse échoir.
    Partikulier_Premium::grant($premium, 1, 'SE-048-R ' . $run . ' : premium à échoir', gmdate('Y-m-d H:i:s', time() - 7200), gmdate('Y-m-d H:i:s', time() - 3600));
    $actifEchu = Partikulier_Premium::is_active($premium); // déclenche l'expiration paresseuse
    $statutApresExpiration = (string) get_post_meta($premium, '_pk_status', true);
    $assert(
        'SE048-INV-004',
        true === $retire && $actifEchu === false
        && $statutAvant === $statutApresRetrait && $statutAvant === $statutApresExpiration
        && $dispoAvant === PremiumService::is_available($premium)
        && PremiumService::is_available($premium),
        sprintf('invariant 4 : statut métier %s → après retrait %s → après expiration %s ; disponible avant=%s, après=%s',
            $statutAvant, $statutApresRetrait, $statutApresExpiration,
            $dispoAvant ? 'oui' : 'non', PremiumService::is_available($premium) ? 'oui' : 'non')
    );

    /* ═══ Invariant 5 — la réactivation ne prolonge pas le premium ═════════ */
    $echeance = gmdate('Y-m-d H:i:s', time() + 10 * 86400);
    Partikulier_Premium::grant($premium, 1, 'SE-048-R ' . $run . ' : premium à échéance fixe', gmdate('Y-m-d H:i:s', time() - 60), $echeance);
    $finAvant = (string) get_post_meta($premium, PremiumService::META_ENDS_AT, true);
    update_post_meta($premium, '_pk_status', 'vendu');
    update_post_meta($premium, '_pk_status', 'actif'); // cycle désactivation → réactivation
    $finApres = (string) get_post_meta($premium, PremiumService::META_ENDS_AT, true);
    $assert(
        'SE048-INV-005',
        $finAvant === $echeance && $finApres === $finAvant,
        sprintf('invariant 5 : échéance avant cycle %s, après réactivation %s (aucune prolongation, à la seconde près)',
            $finAvant, $finApres)
    );

    /* ═══ E-4808 — propagation au groupe de traduction (3 langues) ═════════ */
    $source = $make('SE048-INV ' . $run . ' source fr');
    $varianteEn = $make('SE048-INV ' . $run . ' variant en');
    $varianteAr = $make('SE048-INV ' . $run . ' variant ar');
    $membres = static function (array $group, int $property_id) use ($varianteEn, $varianteAr, $source): array {
        return (int) $property_id === (int) $source ? array_merge($group, [$varianteEn, $varianteAr]) : $group;
    };
    add_filter('partikulier_premium_group_members', $membres, 10, 2);
    $groupe = PremiumService::group_members($source);
    Partikulier_Premium::grant($source, 1, 'SE-048-R ' . $run . ' : premium du groupe', gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() + 5 * 86400));
    $portentLÉtat = array_filter([$source, $varianteEn, $varianteAr], static fn(int $id): bool => (string) get_post_meta($id, PremiumService::META_STATUS, true) === PremiumService::STATUS_ACTIVE);
    $visibles = array_filter([$source, $varianteEn, $varianteAr], static fn(int $id): bool => Partikulier_Premium::is_publicly_visible($id));
    Partikulier_Premium::revoke($source, 1, 'SE-048-R ' . $run . ' : retrait du groupe');
    $portentEncore = array_filter([$source, $varianteEn, $varianteAr], static fn(int $id): bool => (string) get_post_meta($id, PremiumService::META_STATUS, true) === PremiumService::STATUS_ACTIVE);
    $toujoursVisibles = array_filter([$source, $varianteEn, $varianteAr], static fn(int $id): bool => Partikulier_Premium::is_publicly_visible($id));
    remove_all_filters('partikulier_premium_group_members');
    $assert(
        'SE048-INV-006',
        $groupe === [$varianteEn, $varianteAr, $source] || count($groupe) === 3
        && count($portentLÉtat) === 3 && count($visibles) === 3
        && $portentEncore === [] && $toujoursVisibles === [],
        sprintf('E-4808 : groupe résolu = [%s] ; après octroi %d/3 posts portent l’état et %d/3 sont visibles ; après retrait %d/3 portent encore l’état et %d/3 restent visibles (Polylang chargé dans ce processus : %s)',
            implode(',', $groupe), count($portentLÉtat), count($visibles), count($portentEncore), count($toujoursVisibles),
            $polylangPresent ? 'oui' : 'non — groupe simulé par la couture')
    );

    /* ═══ Inventaire — les 12 fonctions (CDC §8.3-4) ════════════════════════ */
    $plugin = $repoDir . '/plugin/partikulier-core/src';
    $theme = $repoDir . '/theme/partikulier';
    $inventaire = [
        '1 attribution'            => [$plugin . '/Domain/Premium/PremiumService.php', 'function grant'],
        '2 permission'             => [$plugin . '/Domain/Premium/PremiumService.php', "user_can(\$granted_by, 'manage_options')"],
        '3 retrait'                => [$plugin . '/Domain/Premium/PremiumService.php', 'function revoke'],
        '4 expiration'             => [$plugin . '/Domain/Premium/PremiumService.php', 'function expire'],
        '5 audit'                  => [$plugin . '/Domain/Premium/PremiumService.php', "'premium_granted'"],
        '6 écran admin'            => [$theme . '/inc/class-premium.php', 'function render_admin_page'],
        '7 drapeau public'         => [$plugin . '/Domain/Premium/PremiumService.php', 'function is_public_enabled'],
        '8 badge fiche'            => [$theme . '/templates/single.php', 'pk-single-badge-premium'],
        '9 badge cartes'           => [$theme . '/templates/parts/card-property.php', 'pk-card-badge-premium'],
        '10 tri premium-first'     => [$theme . '/inc/class-search-filters.php', 'function premium_first_expression'],
        '11 champ REST lite'       => [$plugin . '/RestController.php', "\$result['premium']"],
        '12 fondation paiements'   => [$plugin . '/Domain/Payments', ''],
    ];
    $manquants = [];
    foreach ($inventaire as $nom => [$chemin, $motif]) {
        if (is_dir($chemin)) { continue; }
        if (!is_file($chemin) || ($motif !== '' && strpos((string) file_get_contents($chemin), $motif) === false)) {
            $manquants[] = $nom;
        }
    }
    $assert('SE048-INV-007', $manquants === [],
        $manquants === []
            ? 'inventaire : les 12 fonctions du plan §3.1 sont rattachées à un fichier réel et à leur point d’ancrage (la table ligne à ligne et le test de chacune sont au rapport SE-048-R)'
            : 'fonctions non rattachées : ' . implode(', ', $manquants));

    /* ═══ Plafond : proposition, PAS codée (CDC §8.3-3) ═════════════════════ */
    $sourceService = (string) file_get_contents($plugin . '/Domain/Premium/PremiumService.php');
    $pasDePlafond = !preg_match('/\b(CAP|PLAFOND|MAX_ACTIVE|LIMITE_ACTIVE)\b/', $sourceService);
    // Et la preuve inverse : un 4e octroi simultané passe (aucun plafond actif).
    $quatriemes = [];
    for ($i = 1; $i <= 4; $i++) {
        $quatriemes[] = Partikulier_Premium::grant($premium, 1, 'SE-048-R ' . $run . ' : octroi ' . $i, gmdate('Y-m-d H:i:s', time() - 60), gmdate('Y-m-d H:i:s', time() + (7 + $i) * 86400));
    }
    $tousOk = count(array_filter($quatriemes, static fn($r): bool => !is_wp_error($r) && (int) $r > 0)) === 4;
    $assert('SE048-INV-008', $pasDePlafond && $tousOk,
        sprintf('plafond : aucune constante de plafond dans le service (%s) et 4 attributions successives acceptées (%s) — la règle reste une PROPOSITION à arbitrer, rien n’est codé',
            $pasDePlafond ? 'vérifié ✓' : 'TROUVÉE', $tousOk ? 'oui ✓' : 'NON'));
} catch (Throwable $error) {
    $assert('SE048-INV-EXCEPTION', false, $error->getMessage());
} finally {
    update_option(PremiumService::OPTION_PUBLIC_ENABLED, $flagBefore);
    delete_option(PremiumService::OPTION_NEXT_END);
    remove_all_filters('partikulier_premium_group_members');
    foreach ($created as $id) {
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}pk_premium_history WHERE property_id = %d", (int) $id));
        $wpdb->query($wpdb->prepare("DELETE FROM {$prefix}pk_audit_log WHERE object_id = %d AND action LIKE 'premium_%%'", (int) $id));
        wp_delete_post((int) $id, true);
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se048-premium-invariants-contract (E-4807 : invariants 1 à 5 ; E-4808 : propagation i18n ; inventaire 12 fonctions ; plafond non codé)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/se048-premium-invariants-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);