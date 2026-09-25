<?php
/**
 * Contrat dp9-variant-resolution-contract (SE-044 / DP-9 — R1 §2.2/§2.3/§5.3, famille F5).
 *
 * Résolution canonique et préservation des marqueurs administratifs, HTTP réel :
 *  - ID de VARIANTE reçu directement (AJAX puis REST) → résolution vers la
 *    source, seules les métas du groupe source changent (R1 §2.2) ;
 *  - variante porteuse d'un marqueur : fermeture → ignore consigné ; réactivation
 *    → 409 pk_group_restrained AVANT toute écriture ; chaîne de survie du
 *    marqueur fermeture → réactivation (R1 §2.3-R1, test [TEST FUTUR] §4) ;
 *  - liens divergents (registre métier × Polylang), rôle ambigu (à la fois
 *    source et variante), identifiant en double, source pointée supprimée →
 *    409 pk_variant_resolution SANS écriture ;
 *  - EXIGENCE CP3 §3.2-a : modération posée DANS la fenêtre précontrôle→écriture
 *    (entrelacement injecté au point ① du harnais) → la propagation ignore la
 *    variante fraîchement restreinte et le consigne — le marqueur survit.
 *
 * Nécessite le mu-plugin de test tests/fixtures/dp9-injection-harness.php
 * (constante PK_DP9_TEST_INJECTION) installé pour la durée de la suite.
 *
 * Rejouable : PK_BASE/PK_WP_DIR/PK_COMMIT + serveur démarré. Idempotent.
 */

declare(strict_types=1);

$base   = getenv('PK_BASE') ?: '';
$wpDir  = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
if ('' === $wpDir || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if ('' === $base || !preg_match('#^https?://#', $base)) { fwrite(STDERR, "PK_BASE doit pointer vers le serveur démarré\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';
require_once __DIR__ . '/dp9-http.php';
require_once __DIR__ . '/fixtures/dp9-fixtures.php';

if (!defined('PK_DP9_TEST_INJECTION')) {
        fwrite(STDERR, "Le harnais de test (mu-plugin dp9-injection-harness.php) doit être installé : la couverture d'entrelacement l'exige\n");
        exit(2);
}

$started = gmdate('c');
$results = [];
$assert  = static function (string $id, bool $ok, array $observed = []) use (&$results): void {
        $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'observed' => $observed];
        fwrite(STDERR, ($ok ? 'PASS ' : 'FAIL ') . $id . "\n");
};

$carte = dp9_seed_fixtures();
$F     = $carte['fixtures'];
$owner = (int) $carte['owner'];
$S     = dp9_session($owner);
$S1 = (int) $F['S1_source']; $E1 = (int) $F['E1_variante'];
$S2 = (int) $F['S2_source']; $E2 = (int) $F['E2_variante_refuse'];
$S3 = (int) $F['S3_source']; $E3 = (int) $F['E3_variante'];

/** Ligne de contrôle du registre métier (wp_pk_property_variants — clé source × locale). */
global $wpdb;
$registre_insere = static function (int $source, string $locale, int $variant) use ($wpdb): void {
        $wpdb->insert(
                $wpdb->prefix . 'pk_property_variants',
                [
                        'source_property_id'        => $source,
                        'locale'                    => $locale,
                        'variant_property_id'       => $variant,
                        'original_free_text_locale' => 'fr',
                        'status'                    => 'linked',
                        'created_at'                => current_time('mysql', true),
                        'updated_at'                => current_time('mysql', true),
                ],
                ['%d', '%s', '%d', '%s', '%s', '%s', '%s']
        );
};

/* ═══ 1. ID de variante direct → résolution vers la source (deux entrées) ═══ */

list($s, $b) = dp9_ajax($base, $S, $E1, 'deactivate', 'vendu');
$st = dp9_state([$S1, $E1]);
$canon = dp9_canonical($b);
$assert('DP9-VR-001-ajax-id-variante-resolution-source',
        200 === $s && $canon === $S1 && 'vendu' === $st[$S1]['pk'] && 'vendu' === $st[$E1]['pk'],
        ['http' => $s, 'canonical_id' => $canon, 'source' => $st[$S1], 'variante' => $st[$E1], 'mecanisme' => 'lecture inverse du registre métier — seule l’entité résolue (source) pilote la transition, propagation ensuite']
);

list($s, $b) = dp9_rest($base, $S, $E1, 'reactivate');
$st = dp9_state([$S1, $E1]);
$assert('DP9-VR-002-rest-id-variante-reactivate-nettoyage-symetrique',
        200 === $s && 'actif' === $st[$S1]['pk'] && 'actif' === $st[$E1]['pk'] && '' === $st[$E1]['reason'] && '' === $st[$E1]['closed_at'],
        ['http' => $s, 'source' => $st[$S1], 'variante' => $st[$E1], 'nettoyage' => 'v1.1 §9 — suppression symétrique motif/note/date sur source ET variantes']
);

/* ═══ 2. Marqueur administratif : ignore consigné, 409 préalable, survie ═══ */

list($s, $b) = dp9_ajax($base, $S, $S2, 'deactivate', 'vendu');
$st = dp9_state([$S2, $E2]);
$etapes = dp9_etapes($b);
$propa = null;
foreach ($etapes as $e) {
        if ('propagation' === ($e['step'] ?? '')) { $propa = $e; }
}
$info_e2 = $propa['observed'][$E2] ?? ($propa['observed'][(string) $E2] ?? null);
$ignore_consigne = is_array($info_e2) && 'ignored' === ($info_e2['status'] ?? '');
$assert('DP9-VR-003-fermeture-variante-refuse-ignore-consigne',
        200 === $s && 'vendu' === $st[$S2]['pk'] && 'refuse' === $st[$E2]['pk'] && $ignore_consigne,
        ['http' => $s, 'source' => $st[$S2], 'variante' => $st[$E2], 'propagation_variante' => $info_e2, 'regle' => 'R1 §2.3-R1 règle 1 : aucune propagation n’écrase un marqueur administratif']
);

$avant = dp9_state([$S2, $E2]);
list($s, $b) = dp9_ajax($base, $S, $S2, 'reactivate');
$apres = dp9_state([$S2, $E2]);
$ok_409 = 409 === $s && 'pk_group_restrained' === dp9_code_erreur($b) && $apres[$S2] === $avant[$S2] && $apres[$E2] === $avant[$E2];
list($sr, $br) = dp9_rest($base, $S, $S2, 'reactivate');
$assert('DP9-VR-004-reactivation-groupe-restreint-409-deux-entrees-sans-ecriture',
        $ok_409 && 409 === $sr && 'pk_group_restrained' === dp9_code_erreur($br),
        ['ajax' => [$s, dp9_code_erreur($b)], 'rest' => [$sr, dp9_code_erreur($br)], 'source_inchangee' => $apres[$S2] === $avant[$S2], 'variante_inchangee' => $apres[$E2] === $avant[$E2]]
);

$st = dp9_state($E2);
$assert('DP9-VR-005-survie-marqueur-fermeture-reactivation',
        'refuse' === $st[$E2]['pk'] && 'draft' !== $st[$E2]['post_status'],
        ['variante' => $st[$E2], 'chaine' => 'fermeture (variante ignorée) → réactivation refusée 409 → le marqueur SURVIT aux deux étapes (R1 §2.3-R1 test §4)']
);

/* ═══ 3. Incohérences de liens → 409 sans écriture (R1 §2.2 point 3) ═══ */

// 3a. Registre × Polylang divergents : le registre lie S→E, Polylang lie S→F.
$fk = (int) wp_insert_post(['post_type' => 'properties', 'post_status' => 'publish', 'post_title' => 'DP9 F divergent Polylang', 'post_content' => 'FICTIF DP9', 'post_author' => $owner], true);
if (function_exists('pll_set_post_language')) {
        pll_set_post_language($fk, 'en');
        pll_save_post_translations(['fr' => $S1, 'en' => $fk]);
}
$avant = dp9_state([$S1, $E1, $fk]);
list($s, $b) = dp9_ajax($base, $S, $E1, 'deactivate', 'vendu');
$apres = dp9_state([$S1, $E1, $fk]);
$assert('DP9-VR-006-registre-polylang-divergents-409-sans-ecriture',
        409 === $s && 'pk_variant_resolution' === dp9_code_erreur($b) && $apres[$S1] === $avant[$S1] && $apres[$E1] === $avant[$E1] && $apres[$fk] === $avant[$fk],
        ['http' => $s, 'code' => dp9_code_erreur($b), 'inchanges' => true]
);
if (function_exists('pll_save_post_translations')) {
        pll_save_post_translations(['fr' => $S1, 'en' => $E1]); // rétablit le lien Polylang d'origine.
}

// 3b. Rôle ambigu : l'identifiant est à la fois variante (de S1) et source (de X).
$xv = (int) wp_insert_post(['post_type' => 'properties', 'post_status' => 'publish', 'post_title' => 'DP9 XV cible ambigue', 'post_content' => 'FICTIF DP9', 'post_author' => $owner], true);
$registre_insere($E1, 'en', $xv);
$avant = dp9_state([$E1, $xv]);
list($s, $b) = dp9_ajax($base, $S, $E1, 'deactivate', 'vendu');
$apres = dp9_state([$E1, $xv]);
$wpdb->delete($wpdb->prefix . 'pk_property_variants', ['source_property_id' => $E1, 'locale' => 'en'], ['%d', '%s']);
$assert('DP9-VR-007-role-ambigu-source-et-variante-409-sans-ecriture',
        409 === $s && 'pk_variant_resolution' === dp9_code_erreur($b) && $apres[$E1] === $avant[$E1] && $apres[$xv] === $avant[$xv],
        ['http' => $s, 'code' => dp9_code_erreur($b), 'inchanges' => true]
);

// 3c. Identifiant en double dans le registre (deux lignes, deux sources).
$sd = (int) wp_insert_post(['post_type' => 'properties', 'post_status' => 'publish', 'post_title' => 'DP9 SD seconde source', 'post_content' => 'FICTIF DP9', 'post_author' => $owner], true);
$registre_insere($sd, 'en', $E1);
$avant = dp9_state([$S1, $E1, $sd]);
list($s, $b) = dp9_ajax($base, $S, $E1, 'deactivate', 'vendu');
$apres = dp9_state([$S1, $E1, $sd]);
$wpdb->delete($wpdb->prefix . 'pk_property_variants', ['source_property_id' => $sd, 'locale' => 'en'], ['%d', '%s']);
$assert('DP9-VR-008-identifiant-dans-plusieurs-lignes-409-sans-ecriture',
        409 === $s && 'pk_variant_resolution' === dp9_code_erreur($b) && $apres[$S1] === $avant[$S1] && $apres[$E1] === $avant[$E1] && $apres[$sd] === $avant[$sd],
        ['http' => $s, 'code' => dp9_code_erreur($b), 'inchanges' => true]
);

// 3d. Source pointée inexistante : ligne du registre vers un post supprimé.
$sm = (int) wp_insert_post(['post_type' => 'properties', 'post_status' => 'publish', 'post_title' => 'DP9 SM source fantome', 'post_content' => 'FICTIF DP9', 'post_author' => $owner], true);
$xf = (int) wp_insert_post(['post_type' => 'properties', 'post_status' => 'publish', 'post_title' => 'DP9 XF variante orpheline', 'post_content' => 'FICTIF DP9', 'post_author' => $owner], true);
$registre_insere($sm, 'en', $xf);
wp_delete_post($sm, true); // la ligne du registre survit au post.
$avant = dp9_state([$S1, $E1, $xf]);
list($s, $b) = dp9_ajax($base, $S, $xf, 'deactivate', 'vendu');
$apres = dp9_state([$S1, $E1, $xf]);
$wpdb->delete($wpdb->prefix . 'pk_property_variants', ['source_property_id' => $sm, 'locale' => 'en'], ['%d', '%s']);
$assert('DP9-VR-009-source-pointee-inexistante-409-sans-ecriture',
        409 === $s && 'pk_variant_resolution' === dp9_code_erreur($b) && $apres[$S1] === $avant[$S1] && $apres[$E1] === $avant[$E1] && $apres[$xf] === $avant[$xf],
        ['http' => $s, 'code' => dp9_code_erreur($b), 'inchanges' => true]
);

/* ═══ 4. Exigence CP3 §3.2-a — modération posée DANS la fenêtre (entrelacement) ═══ */
/* Le précontrôle de groupe (③) est passé sur un groupe sain ; au point ① du
 * harnais — après l'écriture de la source, AVANT la boucle de propagation —
 * une modération pose « refuse » sur la variante. La propagation doit alors
 * relire la variante, l'IGNORER (marqueur préservé) et le consigner. */

dp9_arme('after_source_write', $S1, 0, ['pose_marqueur_sur' => $E1, 'marqueur' => 'refuse']);
list($s, $b) = dp9_ajax($base, $S, $S1, 'deactivate', 'vendu');
$st = dp9_state([$S1, $E1]);
$etapes = dp9_etapes($b);
$propa = null;
foreach ($etapes as $e) {
        if ('propagation' === ($e['step'] ?? '')) { $propa = $e; }
}
$info_e1 = $propa['observed'][$E1] ?? ($propa['observed'][(string) $E1] ?? null);
$ignore_fenetre = is_array($info_e1) && 'ignored' === ($info_e1['status'] ?? '');
$assert('DP9-VR-010-moderation-dans-fenetre-precontrole-ecriture-marqueur-preserve',
        200 === $s && 'vendu' === $st[$S1]['pk'] && 'refuse' === $st[$E1]['pk'] && $ignore_fenetre,
        ['http' => $s, 'source' => $st[$S1], 'variante' => $st[$E1], 'propagation_variante' => $info_e1,
         'entrelacement' => 'marqueur posé au point ① (après écriture source, avant propagation) : la propagation relit la variante et l’ignore — couverture séquentielle de l’entrelacement ; la simultanéité multi-processus réelle (TOCTOU intra-boucle) reste déclarée non couverte']
);
dp9_desarme();

// Remise en état : réouvrir S1, retirer le marqueur de E1.
wp_update_post(['ID' => $E1, 'post_status' => 'publish']);
update_post_meta($E1, '_pk_status', 'actif');
delete_post_meta($E1, '_pk_closed_reason');
delete_post_meta($E1, '_pk_closed_note');
delete_post_meta($E1, '_pk_closed_at');
dp9_ajax($base, $S, $S1, 'reactivate');

/* ═══ 5. Nettoyage ═══ */

foreach ([$fk, $xv, $sd, $xf] as $pid) {
        if ($pid > 0) { wp_delete_post($pid, true); }
}
(new \Partikulier\Core\Integration\ListingSynchronizer())->flush();

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
        'suite' => 'dp9-variant-resolution-contract (résolution canonique R1 §2.2, marqueurs préservés §2.3-R1, 409 liens incohérents sans écriture, entrelacement modération CP3 §3.2-a)',
        'started_at' => $started,
        'finished_at' => gmdate('c'),
        'commit' => $commit,
        'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
        'status' => $failed ? 'FAIL' : 'PASS',
        'total' => count($results),
        'passed' => count($results) - count($failed),
        'failed' => count($failed),
        'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
