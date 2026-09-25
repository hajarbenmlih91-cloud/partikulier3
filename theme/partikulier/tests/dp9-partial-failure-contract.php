<?php
/**
 * Contrat dp9-partial-failure-contract (SE-044 / DP-9 — R1 §4.2/§4.4/§5.3, famille F5).
 *
 * Contrat d'échec partiel R1, HTTP réel, trois points d'injection :
 *  - point ① défaut APRÈS l'écriture de la source : 500 pk_partial_failure
 *    + rapport, source fermée, variante intacte, reprise 200 convergente,
 *    date de fermeture jamais réinitialisée ;
 *  - point ② défaut PENDANT la propagation (entrée REST) : seule la variante
 *    visée échoue ; reprise 200 sur l'autre entrée ;
 *  - point ③ défaut AVANT l'invalidation (cache public AMORCÉ) : 500 sans
 *    faux 200, écritures faites, droit premium intact ; reprise 200 avec
 *    invalidation exécutée/confirmée et fraîcheur immédiate ;
 *  - faux négatif update_post_meta (valeur identique) : already_done, pas un échec ;
 *  - cache inactif (APCu coupé dans un sous-processus dédié) : chemin
 *    équivalent explicite et consigné, état cohérent ;
 *  - lecture d'autorité (owner) ≠ lecture publique potentiellement chaude ;
 *  - initialisation atomique de génération (get-or-create, R1 §4.2 h) ;
 *  - EXIGENCE CP3 §3.2-b, classe « échec persistant » (R1 §4.2 f) : même étape
 *    échoue à nouveau au rejeu (mode persistant du harnais), aucune
 *    récupération automatique, aucune dérive d'état ;
 *  - EXIGENCE CP3 §3.2-b, restriction posée PENDANT la fenêtre d'échec : la
 *    reprise ne « répare » jamais une restriction administrative — 409.
 *
 * Nécessite le mu-plugin de test tests/fixtures/dp9-injection-harness.php.
 * Exécution : php -d apc.enable_cli=1 (les assertions d'init atomique
 * l'exigent dans ce processus ; les points d'injection passent par HTTP).
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
        fwrite(STDERR, "Le harnais de test (mu-plugin dp9-injection-harness.php) doit être installé : les points d'injection l'exigent\n");
        exit(2);
}
if (!function_exists('apcu_enabled') || !apcu_enabled()) {
        fwrite(STDERR, "APCu doit être actif dans ce processus (php -d apc.enable_cli=1) — l'initialisation atomique l'exige\n");
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
$S1 = (int) $F['S1_source']; $E1 = (int) $F['E1_variante']; $A3 = (int) $F['A3_actif'];

/** Ids de posts d'une collection publique (HTTP réel). */
$ids_collection = static function () use ($base, $S): array {
        list($s, $b) = dp9_get($base, $S, '/wp-json/partikulier/v1/listings?locale=fr&per_page=100');
        $ids = [];
        if (200 === $s) {
                $j = json_decode($b, true);
                foreach ((array) ($j['data'] ?? []) as $row) {
                        $ext = (string) ($row['external_id'] ?? '');
                        if (0 === strpos($ext, 'estatik:')) { $ids[(int) substr($ext, 8)] = true; }
                }
        }
        return $ids;
};

/* ═══ 1. Point ① — défaut APRÈS l'écriture de la source (AJAX) ═══ */

dp9_arme('after_source_write', $S1);
list($s, $b) = dp9_ajax($base, $S, $S1, 'deactivate', 'vendu');
list($failed_step, $rap) = dp9_corps_500($b);
$st = dp9_state([$S1, $E1]);
$assert('DP9-PF-001-point1-apres-ecriture-source-500-rapport-etat',
        500 === $s && 'pk_partial_failure' === dp9_code_erreur($b) && 'propagation' === $failed_step
        && is_array($rap) && ($rap['canonical_id'] ?? 0) === $S1
        && 'vendu' === $st[$S1]['pk'] && 'vendu' === $st[$S1]['reason'] && '' !== $st[$S1]['closed_at']
        && 'actif' === $st[$E1]['pk'],
        ['http' => $s, 'code' => dp9_code_erreur($b), 'failed_step' => $failed_step, 'source' => $st[$S1], 'variante' => $st[$E1], 'aucun_faux_200' => true]
);
$date_apres_defaut = $st[$S1]['closed_at'];

sleep(2);
list($s2, $b2) = dp9_ajax($base, $S, $S1, 'deactivate', 'vendu');
$st2 = dp9_state([$S1, $E1]);
$assert('DP9-PF-002-point1-reprise-200-convergence-date-gardee',
        200 === $s2 && 'vendu' === $st2[$S1]['pk'] && 'vendu' === $st2[$E1]['pk'] && 'vendu' === $st2[$E1]['reason']
        && $st2[$S1]['closed_at'] === $date_apres_defaut,
        ['http' => $s2, 'source' => $st2[$S1], 'variante' => $st2[$E1], 'date_avant' => $date_apres_defaut, 'date_apres' => $st2[$S1]['closed_at']]
);
dp9_ajax($base, $S, $S1, 'reactivate'); // récupération complète du groupe.

/* ═══ 2. Point ② — défaut PENDANT la propagation (entrée REST) ═══ */

dp9_arme('during_propagation', $S1, $E1);
list($s, $b) = dp9_rest($base, $S, $S1, 'deactivate', 'vendu');
list($failed_step, $rap) = dp9_corps_500($b);
$st = dp9_state([$S1, $E1]);
$assert('DP9-PF-003-point2-pendant-propagation-rest-500-variante-seule',
        500 === $s && 'pk_partial_failure' === dp9_code_erreur($b) && 'propagation' === $failed_step
        && 'vendu' === $st[$S1]['pk'] && 'actif' === $st[$E1]['pk'],
        ['http' => $s, 'code' => dp9_code_erreur($b), 'failed_step' => $failed_step, 'source' => $st[$S1], 'variante' => $st[$E1]]
);

list($s2, $b2) = dp9_ajax($base, $S, $S1, 'deactivate', 'vendu'); // reprise sur l'AUTRE entrée.
$st2 = dp9_state([$S1, $E1]);
$assert('DP9-PF-004-point2-reprise-200-autre-entree-convergence',
        200 === $s2 && 'vendu' === $st2[$S1]['pk'] && 'vendu' === $st2[$E1]['pk'],
        ['http' => $s2, 'source' => $st2[$S1], 'variante' => $st2[$E1]]
);
dp9_ajax($base, $S, $S1, 'reactivate');

/* ═══ 3. Point ③ — défaut AVANT l'invalidation (cache AMORCÉ) ═══ */

update_post_meta($S1, '_pk_premium_status', 'active');
update_post_meta($S1, '_pk_premium_ends_at', '2027-06-30 00:00:00');
$ids_avant = $ids_collection();
$cache_amorce = isset($ids_avant[$S1]);
dp9_arme('before_invalidation', $S1);
list($s, $b) = dp9_ajax($base, $S, $S1, 'deactivate', 'vendu');
list($failed_step, $rap) = dp9_corps_500($b);
$st = dp9_state([$S1, $E1]);
$assert('DP9-PF-005-point3-avant-invalidation-cache-amorce-500-sans-faux-200',
        $cache_amorce && 500 === $s && 'pk_partial_failure' === dp9_code_erreur($b) && 'invalidation' === $failed_step
        && 'vendu' === $st[$S1]['pk'] && 'vendu' === $st[$E1]['pk'] && 'active' === $st[$S1]['premium'],
        ['cache_amorce' => $cache_amorce, 'http' => $s, 'code' => dp9_code_erreur($b), 'failed_step' => $failed_step, 'source' => $st[$S1], 'variante' => $st[$E1], 'premium' => $st[$S1]['premium']]
);

list($s2, $b2) = dp9_ajax($base, $S, $S1, 'deactivate', 'vendu'); // reprise : invalidation exécutée.
$st2 = dp9_state([$S1, $E1]);
$ids_apres = $ids_collection();
$etape_inv = null;
foreach (dp9_etapes($b2) as $e) {
        if ('invalidation' === ($e['step'] ?? '')) { $etape_inv = $e; }
}
$assert('DP9-PF-006-point3-reprise-200-invalidation-confirmee-fraicheur-premium',
        200 === $s2 && 'vendu' === $st2[$S1]['pk']
        && !isset($ids_apres[$S1])
        && is_array($etape_inv) && in_array($etape_inv['status'] ?? '', ['done', 'inactive_equivalent_path'], true)
        && 'active' === $st2[$S1]['premium'],
        ['http' => $s2, 'etape_invalidation' => $etape_inv, 's1_dans_collection' => isset($ids_apres[$S1]), 'premium' => $st2[$S1]['premium']]
);

/* ═══ 4. Faux négatif update_post_meta (R1 §4.2 a) ═══ */

list($s1, $b1) = dp9_ajax($base, $S, $A3, 'deactivate', 'loue');
list($s2, $b2) = dp9_ajax($base, $S, $A3, 'deactivate', 'loue'); // rejeu : update_post_meta → false sur valeurs identiques.
$etape_src = null;
foreach (dp9_etapes($b2) as $e) {
        if ('source_write' === ($e['step'] ?? '')) { $etape_src = $e; }
}
$stA = dp9_state($A3);
$assert('DP9-PF-007-faux-negatif-update-post-meta-non-echec',
        200 === $s1 && 200 === $s2 && 'already_done' === ($etape_src['status'] ?? '') && 'loue' === $stA[$A3]['pk'],
        ['http1' => $s1, 'http2' => $s2, 'etape_source_write' => $etape_src, 'etat' => $stA[$A3]]
);
dp9_ajax($base, $S, $A3, 'reactivate');

/* ═══ 5. Cache inactif — chemin équivalent EXPLICITE (R1 §4.2 c) ═══ */

$script = tempnam(sys_get_temp_dir(), 'dp9noapcu_') . '.php';
file_put_contents($script, '<?php
require ' . var_export($wpDir . '/wp-load.php', true) . ';
wp_set_current_user(' . (int) $owner . ');
$r = Partikulier_Listing_Transitions::transition(' . $A3 . ', "deactivate", ' . (int) $owner . ', "avis", "");
if ( is_wp_error( $r ) ) { echo wp_json_encode(array("erreur" => $r->get_error_code())); exit(0); }
$inv = null;
foreach ( $r["steps"] as $e ) { if ( "invalidation" === $e["step"] ) { $inv = $e; } }
echo wp_json_encode(array("status" => "ok", "invalidation" => $inv, "apcu" => function_exists("apcu_enabled") ? apcu_enabled() : null));
');
$php_bin = getenv('PK_PHP') ?: PHP_BINARY;
$proc = proc_open([$php_bin, '-d', 'apc.enable_cli=0', $script], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $tuyaux, dirname($script));
$sortie = stream_get_contents($tuyaux[1]);
fclose($tuyaux[1]);
fclose($tuyaux[2]);
proc_close($proc);
unlink($script);
$j = json_decode(trim((string) $sortie), true);
$inv = is_array($j) ? ($j['invalidation'] ?? null) : null;
$obs_inv = is_array($inv) ? $inv : [];
$stA = dp9_state($A3);
$assert('DP9-PF-008-cache-inactif-chemin-equivalent-consigne',
        is_array($j) && 'ok' === ($j['status'] ?? '') && false === ($j['apcu'] ?? true)
        && 'inactive_equivalent_path' === ($obs_inv['status'] ?? '')
        && false === ($obs_inv['observed']['cache_active'] ?? true)
        && 'indisponible' === $stA[$A3]['pk'],
        ['reponse' => $j, 'etat' => $stA[$A3], 'note' => 'transition exécutée dans un sous-processus sans APCu : toute lecture va à la base — l’étape est trivialement satisfaite et consignée']
);
Partikulier_Listing_Transitions::transition($A3, 'reactivate', $owner, '', '');

/* ═══ 6. Lecture d'autorité (R1 §4.2 d) — owner ≠ lecture publique chaude ═══ */

$ids_collection(); // amorce le cache public (S1 réactivé → présent).
update_post_meta($S1, '_pk_status', 'vendu');   // écriture directe, SANS transition ni invalidation serveur.
update_post_meta($S1, '_pk_closed_reason', 'vendu');
list($sd, $dash) = dp9_http(rtrim($base, '/') . '/wp-json/partikulier/v1/owner/dashboard', null, ['Cookie: ' . $S['cookie'], 'X-WP-Nonce: ' . $S['rest_nonce']]);
$lignes = [];
if (200 === $sd) {
        $j = json_decode($dash, true);
        foreach ((array) ($j['listings'] ?? []) as $row) { $lignes[(int) ($row['id'] ?? 0)] = (string) ($row['status'] ?? ''); }
}
$stS1 = dp9_state($S1);
$assert('DP9-PF-009-lecture-autorite-owner-reflete-etat-reel',
        200 === $sd && 'vendu' === ($lignes[$S1] ?? '') && 'vendu' === $stS1[$S1]['pk'],
        ['http' => $sd, 'dashboard_status' => $lignes[$S1] ?? null, 'etat_reel' => $stS1[$S1], 'note' => 'le tableau de bord propriétaire lit la méta stockée, hors cache de disponibilité (celui-ci ne s’applique qu’aux lectures publiques de collection)']
);
update_post_meta($S1, '_pk_status', 'actif');
dp9_ajax($base, $S, $S1, 'reactivate');

/* ═══ 7. Initialisation atomique de génération (R1 §4.2 h) ═══ */

apcu_delete('pk_dp9_test_version');
$radd1 = apcu_add('pk_dp9_test_version', 1, 0);
apcu_inc('pk_dp9_test_version');
$vprim1 = apcu_fetch('pk_dp9_test_version');
$radd2 = apcu_add('pk_dp9_test_version', 1, 0); // initialiseur attardé : ne doit rien écraser.
$vprim2 = apcu_fetch('pk_dp9_test_version');
apcu_delete('pk_dp9_test_version');

apcu_delete('pk_listing_search_version');
$b1 = \Partikulier\Core\ListingRepository::ensureSearchCacheVersion();
$v1 = \Partikulier\Core\ListingRepository::currentSearchCacheVersion();
$b2 = \Partikulier\Core\ListingRepository::ensureSearchCacheVersion(); // second assurant : no-op.
$v2 = \Partikulier\Core\ListingRepository::currentSearchCacheVersion();
\Partikulier\Core\Integration\ListingSynchronizer::invalidate_listing_search_cache_now();
$v3 = \Partikulier\Core\ListingRepository::currentSearchCacheVersion();
\Partikulier\Core\ListingRepository::ensureSearchCacheVersion(); // attardé après incrément.
$v4 = \Partikulier\Core\ListingRepository::currentSearchCacheVersion();
$assert('DP9-PF-010-initialisation-atomique-get-or-create',
        true === $radd1 && false === $radd2 && 2 === $vprim1 && 2 === $vprim2
        && true === $b1 && 1 === $v1 && true === $b2 && 1 === $v2 && 2 === $v3 && 2 === $v4,
        ['primitive' => ['add' => $radd1, 'add_apres_inc' => $radd2, 'version' => [$vprim1, $vprim2]],
         'service' => ['ensure1' => $b1, 'v1' => $v1, 'ensure2' => $b2, 'v2' => $v2, 'apres_inc' => $v3, 'attarde' => $v4],
         'proprieté' => 'une initialisation concurrente n’écrase JAMAIS une génération plus récente']
);

/* ═══ 8. Classe « échec persistant » (R1 §4.2 f — exigence CP3 §3.2-b) ═══ */

dp9_arme('after_source_write', $S1, 0, ['persistent' => true]);
list($s1, $b1) = dp9_ajax($base, $S, $S1, 'deactivate', 'avis');
list($fs1, $rap1) = dp9_corps_500($b1);
$st1 = dp9_state([$S1, $E1]);
list($s2, $b2) = dp9_ajax($base, $S, $S1, 'deactivate', 'avis'); // rejeu : MÊME étape doit échouer à nouveau.
list($fs2, $rap2) = dp9_corps_500($b2);
$st2 = dp9_state([$S1, $E1]);
dp9_desarme();
list($s3, $b3) = dp9_ajax($base, $S, $S1, 'deactivate', 'avis'); // la panne cessée, la reprise converge.
$st3 = dp9_state([$S1, $E1]);
$assert('DP9-PF-011-echec-persistant-meme-etape-aucune-recuperation-automatique',
        500 === $s1 && 500 === $s2 && 'propagation' === $fs1 && $fs1 === $fs2
        && $st2[$S1] === $st1[$S1] && 'actif' === $st2[$E1]['pk']
        && 200 === $s3 && 'indisponible' === $st3[$S1]['pk'] && 'indisponible' === $st3[$E1]['pk'],
        ['http' => [$s1, $s2, $s3], 'failed_step' => [$fs1, $fs2], 'etat_entre_deux_500' => $st1[$S1] === $st2[$S1],
         'classe' => 'échec persistant (R1 §4.2 f) : aucune récupération automatique promise — 500 identiques, aucune dérive d’état, aucune compensation ; convergence seulement quand la panne cesse (désarmement explicite)']
);
dp9_ajax($base, $S, $S1, 'reactivate');

/* ═══ 9. Restriction posée pendant la fenêtre d'échec — la reprise ne répare jamais (R1 §4.2 e/g) ═══ */

dp9_arme('before_invalidation', $S1);
list($s1, $b1) = dp9_ajax($base, $S, $S1, 'deactivate', 'vendu'); // 500 : écritures faites, invalidation manquante.
$st1 = dp9_state([$S1, $E1]);
update_post_meta($E1, '_pk_status', 'refuse'); // modération posée PENDANT la fenêtre d'échec.
$avant = dp9_state([$S1, $E1]);
list($s2, $b2) = dp9_ajax($base, $S, $S1, 'reactivate'); // reprise de la reprise : refusée.
$apres = dp9_state([$S1, $E1]);
$assert('DP9-PF-012-restriction-dans-fenetre-echec-reprise-409',
        500 === $s1 && 409 === $s2 && 'pk_group_restrained' === dp9_code_erreur($b2)
        && $apres[$S1] === $avant[$S1] && $apres[$E1] === $avant[$E1],
        ['http_defaut' => $s1, 'http_reprise' => $s2, 'code' => dp9_code_erreur($b2), 'source' => $apres[$S1], 'variante' => $apres[$E1],
         'regle' => 'une reprise ne « répare » jamais une restriction administrative (R1 §4.2 e/g) — le précontrôle s’applique à CHAQUE renvoi ; la levée relève du circuit administratif']
);
dp9_desarme();

/* ═══ 10. Remise en état ═══ */

update_post_meta($E1, '_pk_status', 'actif');
dp9_ajax($base, $S, $S1, 'reactivate');
delete_post_meta($S1, '_pk_premium_status');
delete_post_meta($S1, '_pk_premium_ends_at');
(new \Partikulier\Core\Integration\ListingSynchronizer())->flush();

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
        'suite' => 'dp9-partial-failure-contract (R1 §4.2 : trois points d’injection + reprises idempotentes, faux négatif, cache inactif consigné, lecture d’autorité, init atomique, échec persistant et restriction hors reprise — classes f)',
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
