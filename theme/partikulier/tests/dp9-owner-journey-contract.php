<?php
/**
 * Contrat dp9-owner-journey-contract (SE-044 / DP-9 — v1.1 §3/§4/§10 + R1 §3/§5).
 *
 * Parcours unique « Désactiver (motif) » / « Réactiver » sur les DEUX entrées
 * serveur (AJAX pk_manage_listing + REST /owner/listings/{id}/action), HTTP réel :
 *  - F2 : retrait des anciens noms (400 pk_listing_action_retired, état inchangé) ;
 *  - F3 : parcours positif (motifs vendu/loue/avis/autre + note privée jamais
 *    publique, T05) ;
 *  - épinglage T02/T03 (cellules positives historiques, +2 R1 §5.3) ;
 *  - F4 : matrice default-deny (v1.1 §3.1) — états restreints, non publiés,
 *    incohérents (publish × refuse / publish × en_attente, extension R1),
 *    retrait de l'exception draft × pause (R1 §3.2-1) ;
 *  - T25 : chaînes de contournement de la revue (chaque étape refusée, jamais
 *    de publication par détour, état final inchangé) ;
 *  - R1 (e) : reprise idempotente (rien réécrit, date jamais réinitialisée) ;
 *  - T09 : bien d'autrui ; CSRF (nonce) sur les deux entrées ;
 *  - T33 : JSON-LD par état ; T19 : fiche fermée publique, indexable, sitemap ;
 *  - T36 : page « Mes annonces » ×3 langues (boutons, formulaire de motif,
 *    champ note privé, aucun message trompeur, RTL).
 *
 * Rejouable : PK_BASE/PK_WP_DIR/PK_COMMIT + serveur démarré sur l'origine du
 * site. Idempotent via tests/fixtures/dp9-fixtures.php.
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
$A3 = (int) $F['A3_actif']; $A1 = (int) $F['A1_absent']; $A2 = (int) $F['A2_vide'];
$W1 = (int) $F['W1_attente']; $R1 = (int) $F['R1_refuse']; $D1 = (int) $F['D1_draft_actif'];
$T1 = (int) $F['T1_corbeille']; $U1 = (int) $F['U1_inconnu']; $P1 = (int) $F['P1_pause'];
$O1 = (int) $F['O1_autrui'];

/* ═══ 1. F2 — retrait des anciens noms ═══ */

list($s, $b) = dp9_ajax($base, $S, $A3, 'mark_sold');
$st = dp9_state($A3);
$assert('DP9-OJ-001-f2-mark-sold-retire-ajax',
        400 === $s && 'pk_listing_action_retired' === dp9_code_erreur($b) && 'actif' === $st[$A3]['pk'] && 'publish' === $st[$A3]['post_status'],
        ['http' => $s, 'code' => dp9_code_erreur($b), 'etat' => $st[$A3]]
);

$noms_ok = true;
$details = [];
foreach (array('mark_rented', 'pause', 'archive', 'delete') as $act) {
        list($sa, $ba) = dp9_ajax($base, $S, $A3, $act);
        $ok_a = 400 === $sa && 'pk_listing_action_retired' === dp9_code_erreur($ba);
        list($sr, $br) = dp9_rest($base, $S, $A3, $act);
        $ok_r = 400 === $sr && 'pk_listing_action_retired' === dp9_code_erreur($br);
        $details[$act] = ['ajax' => [$sa, dp9_code_erreur($ba)], 'rest' => [$sr, dp9_code_erreur($br)]];
        if (!$ok_a || !$ok_r) { $noms_ok = false; }
}
$st = dp9_state($A3);
$assert('DP9-OJ-002-f2-quatre-noms-retires-deux-entrees',
        $noms_ok && 'actif' === $st[$A3]['pk'],
        ['noms' => $details, 'etat' => $st[$A3]]
);

list($sa, $ba) = dp9_ajax($base, $S, $A3, 'foo_inconnu');
list($sr, $br) = dp9_rest($base, $S, $A3, 'foo_inconnu');
$assert('DP9-OJ-003-f2-action-invalide-400-deux-entrees',
        400 === $sa && 'pk_listing_action_invalid' === dp9_code_erreur($ba)
        && 400 === $sr && 'pk_listing_action_invalid' === dp9_code_erreur($br),
        ['ajax' => [$sa, dp9_code_erreur($ba)], 'rest' => [$sr, dp9_code_erreur($br)]]
);

/* ═══ 2. F3 — parcours positif (deux entrées) ═══ */

list($s, $b) = dp9_ajax($base, $S, $A3, 'deactivate', 'vendu');
$st = dp9_state($A3);
$assert('DP9-OJ-004-f3-deactivate-vendu-ajax',
        200 === $s && 'vendu' === $st[$A3]['pk'] && 'vendu' === $st[$A3]['reason'] && '' !== $st[$A3]['closed_at'] && 'publish' === $st[$A3]['post_status'],
        ['http' => $s, 'etat' => $st[$A3]]
);

list($s, $b) = dp9_ajax($base, $S, $A3, 'reactivate');
$st = dp9_state($A3);
$assert('DP9-OJ-005-f3-reactivate-nettoyage-complet-ajax',
        200 === $s && 'actif' === $st[$A3]['pk'] && '' === $st[$A3]['reason'] && '' === $st[$A3]['note'] && '' === $st[$A3]['closed_at'] && 'publish' === $st[$A3]['post_status'],
        ['http' => $s, 'etat' => $st[$A3]]
);

list($s, $b) = dp9_rest($base, $S, $A3, 'deactivate', 'avis');
$st = dp9_state($A3);
$assert('DP9-OJ-006-f3-deactivate-avis-rest',
        200 === $s && 'indisponible' === $st[$A3]['pk'] && 'avis' === $st[$A3]['reason'],
        ['http' => $s, 'etat' => $st[$A3]]
);
dp9_rest($base, $S, $A3, 'reactivate');

list($s, $b) = dp9_ajax($base, $S, $A1, 'deactivate', 'autre', 'note privée fictive DP9');
$st = dp9_state($A1);
list($sf, $html) = dp9_get($base, $S, parse_url(get_permalink($A1), PHP_URL_PATH));
$assert('DP9-OJ-007-f3-autre-note-privee-jamais-publique',
        200 === $s && 'indisponible' === $st[$A1]['pk'] && 'note privée fictive DP9' === $st[$A1]['note']
        && 200 === $sf && false === strpos($html, 'note privée fictive DP9'),
        ['http_action' => $s, 'http_fiche' => $sf, 'note_stockee' => true, 'note_dans_html' => (bool) strpos($html, 'note privée fictive DP9')]
);
dp9_ajax($base, $S, $A1, 'reactivate');

/* ═══ 3. Épinglage T02/T03 (R1 §5.3, +2) — cellules positives historiques ═══ */

list($s, $b) = dp9_rest($base, $S, $A1, 'deactivate', 'vendu');
$st = dp9_state($A1);
$assert('DP9-OJ-008-t02-deactivate-sans-meta-rest',
        200 === $s && 'vendu' === $st[$A1]['pk'] && 'publish' === $st[$A1]['post_status'],
        ['http' => $s, 'etat' => $st[$A1], 'cellule' => 'publish × absent × deactivate — équivalent positif de T02']
);
dp9_rest($base, $S, $A1, 'reactivate');

list($s, $b) = dp9_ajax($base, $S, $A2, 'deactivate', 'loue');
$st1 = dp9_state($A2);
list($s2, $b2) = dp9_ajax($base, $S, $A2, 'reactivate');
$st2 = dp9_state($A2);
$assert('DP9-OJ-009-t03-meta-vide-deactivate-reactivate-ajax',
        200 === $s && 'loue' === $st1[$A2]['pk'] && 200 === $s2 && 'actif' === $st2[$A2]['pk'],
        ['http1' => $s, 'etat_ferme' => $st1[$A2], 'http2' => $s2, 'etat_reouvert' => $st2[$A2], 'cellule' => 'publish × vide — équivalent positif de T03 (méta vide disponible, §5.1)']
);

/* ═══ 4. F4 — matrice default-deny (v1.1 §3.1 + R1) ═══ */

$cas_matrice = [
        ['W1-en-attente-deactivate', $W1, 'deactivate'],
        ['W1-en-attente-reactivate', $W1, 'reactivate'],
        ['R1-refuse-deactivate', $R1, 'deactivate'],
        ['R1-refuse-reactivate', $R1, 'reactivate'],
        ['D1-draft-actif-deactivate', $D1, 'deactivate'],
        ['D1-draft-actif-reactivate', $D1, 'reactivate'],
        ['T1-corbeille-deactivate', $T1, 'deactivate'],
        ['T1-corbeille-reactivate', $T1, 'reactivate'],
        ['U1-inconnu-deactivate', $U1, 'deactivate'],
        ['U1-inconnu-reactivate', $U1, 'reactivate'],
];
$avant = dp9_state(array_column($cas_matrice, 1));
$ok_m = true;
$obs_m = [];
foreach ($cas_matrice as $casse) {
        list($s, $b) = dp9_ajax($base, $S, $casse[1], $casse[2]);
        $apres = dp9_state($casse[1]);
        $ok = 403 === $s
                && in_array(dp9_code_erreur($b), array('pk_listing_forbidden', 'pk_republication_refused'), true) // republication_refused : reactivate sur un post non publié (v1.1 §3.1)
                && $apres[$casse[1]] === $avant[$casse[1]];
        $obs_m[$casse[0]] = ['http' => $s, 'code' => dp9_code_erreur($b), 'inchangé' => $apres[$casse[1]] === $avant[$casse[1]]];
        if (!$ok) { $ok_m = false; }
}
// Seconde entrée (T27) sur les cas clés.
foreach (array(array($W1, 'deactivate'), array($R1, 'reactivate'), array($D1, 'reactivate'), array($T1, 'reactivate')) as $c) {
        list($s, $b) = dp9_rest($base, $S, $c[0], $c[1]);
        $obs_m['rest-' . $c[0] . '-' . $c[1]] = ['http' => $s, 'code' => dp9_code_erreur($b)];
        if (403 !== $s) { $ok_m = false; }
}
$assert('DP9-OJ-010-f4-matrice-etats-non-actionnables-403', $ok_m, $obs_m);

// Combinaisons incohérentes publish × marqueur (default-deny, extension R1 §5.3).
$x1 = (int) wp_insert_post(['post_type' => 'properties', 'post_status' => 'publish', 'post_title' => 'DP9 X1 publish refuse incohérent', 'post_content' => 'FICTIF DP9', 'post_author' => $owner], true);
$x2 = (int) wp_insert_post(['post_type' => 'properties', 'post_status' => 'publish', 'post_title' => 'DP9 X2 publish en-attente incohérent', 'post_content' => 'FICTIF DP9', 'post_author' => $owner], true);
update_post_meta($x1, '_pk_status', 'refuse');
update_post_meta($x2, '_pk_status', 'en_attente_whatsapp');
if (function_exists('pll_set_post_language')) {
        pll_set_post_language($x1, 'fr');
        pll_set_post_language($x2, 'fr');
}
$avant_x = dp9_state([$x1, $x2]);
list($s1a, $b1a) = dp9_ajax($base, $S, $x1, 'deactivate', 'vendu');
list($s1r, $b1r) = dp9_ajax($base, $S, $x1, 'reactivate');
list($s2a, $b2a) = dp9_ajax($base, $S, $x2, 'deactivate', 'vendu');
list($s2r, $b2r) = dp9_ajax($base, $S, $x2, 'reactivate');
$apres_x = dp9_state([$x1, $x2]);
$assert('DP9-OJ-011-f4-publish-refuse-default-deny',
        403 === $s1a && 403 === $s1r && 'pk_listing_forbidden' === dp9_code_erreur($b1a)
        && $apres_x[$x1] === $avant_x[$x1],
        ['deactivate' => [$s1a, dp9_code_erreur($b1a)], 'reactivate' => [$s1r, dp9_code_erreur($b1r)], 'inchangé' => $apres_x[$x1] === $avant_x[$x1], 'case' => 'publish × refuse (incohérente) — refuse matriciel (R1 §5.3, default-deny)']
);
$assert('DP9-OJ-012-f4-publish-en-attente-default-deny',
        403 === $s2a && 403 === $s2r && 'pk_listing_forbidden' === dp9_code_erreur($b2a)
        && $apres_x[$x2] === $avant_x[$x2],
        ['deactivate' => [$s2a, dp9_code_erreur($b2a)], 'reactivate' => [$s2r, dp9_code_erreur($b2r)], 'inchangé' => $apres_x[$x2] === $avant_x[$x2], 'case' => 'publish × en_attente_whatsapp (incohérente) — refuse matriciel (R1 §5.3, default-deny)']
);

// Retrait de l'exception draft × pause (R1 §3.2-1) : reactivate → 403, état inchangé.
$avant_p1 = dp9_state($P1);
list($sp, $bp) = dp9_ajax($base, $S, $P1, 'reactivate');
list($spr, $bpr) = dp9_rest($base, $S, $P1, 'reactivate');
$apres_p1 = dp9_state($P1);
$assert('DP9-OJ-013-f4-draft-pause-reactivate-refuse-r1',
        403 === $sp && 'pk_republication_refused' === dp9_code_erreur($bp) && 403 === $spr
        && $apres_p1[$P1] === $avant_p1[$P1],
        ['ajax' => [$sp, dp9_code_erreur($bp)], 'rest' => [$spr, dp9_code_erreur($bpr)], 'inchangé' => $apres_p1[$P1] === $avant_p1[$P1], 'case' => 'draft × pause — exception RETIRÉE par R1 §3.2-1 : aucune republication propriétaire depuis un état non publié']
);

// Motif manquant / note manquante : 400 normalisés, les deux entrées.
list($sa, $ba) = dp9_ajax($base, $S, $A3, 'deactivate');
list($sr, $br) = dp9_rest($base, $S, $A3, 'deactivate');
$assert('DP9-OJ-014-f4-motif-obligatoire-400-deux-entrees',
        400 === $sa && 'pk_deactivation_reason_required' === dp9_code_erreur($ba)
        && 400 === $sr && 'pk_deactivation_reason_required' === dp9_code_erreur($br),
        ['ajax' => [$sa, dp9_code_erreur($ba)], 'rest' => [$sr, dp9_code_erreur($br)]]
);
list($sa, $ba) = dp9_ajax($base, $S, $A3, 'deactivate', 'autre');
list($sr, $br) = dp9_rest($base, $S, $A3, 'deactivate', 'autre');
$assert('DP9-OJ-015-f4-autre-note-obligatoire-400-deux-entrees',
        400 === $sa && 'pk_deactivation_note_required' === dp9_code_erreur($ba)
        && 400 === $sr && 'pk_deactivation_note_required' === dp9_code_erreur($br),
        ['ajax' => [$sa, dp9_code_erreur($ba)], 'rest' => [$sr, dp9_code_erreur($br)]]
);

/* ═══ 5. T25 — chaînes de contournement (essais réels de la revue) ═══ */

$avant_c = dp9_state([$R1, $W1, $D1, $T1]);
$etapes_chaine = [];
list($s1, $b1) = dp9_ajax($base, $S, $R1, 'mark_sold');            // 1. nom retiré
list($s2, $b2) = dp9_ajax($base, $S, $R1, 'reactivate');            // 2. republication refusée
$etapes_chaine['refuse->mark_sold->reactivate'] = [[$s1, dp9_code_erreur($b1)], [$s2, dp9_code_erreur($b2)]];
$apres_r1 = dp9_state($R1);
$assert('DP9-OJ-016-t25-chaine-refuse-mark-sold-reactivate',
        400 === $s1 && 'pk_listing_action_retired' === dp9_code_erreur($b1) && 403 === $s2
        && 'draft' === $apres_r1[$R1]['post_status'] && 'refuse' === $apres_r1[$R1]['pk']
        && $apres_r1[$R1] === $avant_c[$R1],
        ['etapes' => $etapes_chaine['refuse->mark_sold->reactivate'], 'etat_final' => $apres_r1[$R1], 'jamais_publie' => 'draft' === $apres_r1[$R1]['post_status']]
);

$ok_ch = true;
$obs_ch = [];
list($s1, $b1) = dp9_rest($base, $S, $W1, 'archive');
$obs_ch['en_attente->archive'] = [$s1, dp9_code_erreur($b1)];
if (!(400 === $s1 && 'pk_listing_action_retired' === dp9_code_erreur($b1))) { $ok_ch = false; }
list($s2, $b2) = dp9_rest($base, $S, $W1, 'reactivate');
$obs_ch['en_attente->reactivate'] = [$s2, dp9_code_erreur($b2)];
if (403 !== $s2) { $ok_ch = false; }
list($s3, $b3) = dp9_rest($base, $S, $D1, 'reactivate');
$obs_ch['draft-actif->reactivate'] = [$s3, dp9_code_erreur($b3)];
if (403 !== $s3) { $ok_ch = false; }
list($s4, $b4) = dp9_rest($base, $S, $T1, 'reactivate');
list($s5, $b5) = dp9_rest($base, $S, $T1, 'reactivate');
$obs_ch['trash->reactivate-x2'] = [[$s4, dp9_code_erreur($b4)], [$s5, dp9_code_erreur($b5)]];
if (403 !== $s4 || 403 !== $s5) { $ok_ch = false; }
$apres_c = dp9_state([$W1, $D1, $T1]);
foreach (array($W1, $D1, $T1) as $pid) {
        if ($apres_c[$pid] !== $avant_c[$pid]) { $ok_ch = false; }
}
$assert('DP9-OJ-017-t25-chaines-en-attente-draft-trash-rest', $ok_ch,
        ['etapes' => $obs_ch, 'etats_inchanges' => true, 'jamais_publie' => 'pending' === $apres_c[$W1]['post_status'] && 'draft' === $apres_c[$D1]['post_status'] && 'trash' === $apres_c[$T1]['post_status']]
);

/* ═══ 6. R1 (e) — reprise idempotente ═══ */

list($s, $b) = dp9_ajax($base, $S, $A3, 'reactivate');
$st = dp9_state($A3);
$canon = dp9_canonical($b);
$assert('DP9-OJ-018-r1e-reactivate-source-active-reprise-200',
        200 === $s && 'actif' === $st[$A3]['pk'] && $canon === $A3 && '' === $st[$A3]['closed_at'],
        ['http' => $s, 'canonical_id' => $canon, 'etat' => $st[$A3], 'case' => 'case matricielle amendée R1 (e) : 200 reprise, rien réécrit']
);

list($s1, $b1) = dp9_ajax($base, $S, $A3, 'deactivate', 'vendu');
$st1 = dp9_state($A3);
sleep(2);
list($s2, $b2) = dp9_ajax($base, $S, $A3, 'deactivate', 'vendu');
$st2 = dp9_state($A3);
$assert('DP9-OJ-019-r1e-double-deactivate-date-jamais-reinitialisee',
        200 === $s1 && 200 === $s2 && '' !== $st1[$A3]['closed_at'] && $st1[$A3]['closed_at'] === $st2[$A3]['closed_at'],
        ['http1' => $s1, 'http2' => $s2, 'closed_at_1' => $st1[$A3]['closed_at'], 'closed_at_2' => $st2[$A3]['closed_at']]
);

list($s1, $b1) = dp9_ajax($base, $S, $A3, 'reactivate');
list($s2, $b2) = dp9_ajax($base, $S, $A3, 'reactivate');
list($s3, $b3) = dp9_rest($base, $S, $A3, 'reactivate');
$st = dp9_state($A3);
$assert('DP9-OJ-020-r1e-double-reactivate-200-deux-entrees',
        200 === $s1 && 200 === $s2 && 200 === $s3 && 'actif' === $st[$A3]['pk'],
        ['http' => [$s1, $s2, $s3], 'etat' => $st[$A3]]
);

/* ═══ 7. T09 bien d'autrui + CSRF ═══ */

list($sa, $ba) = dp9_ajax($base, $S, $O1, 'deactivate', 'vendu');
list($sr, $br) = dp9_rest($base, $S, $O1, 'deactivate', 'vendu');
$st = dp9_state($O1);
$assert('DP9-OJ-021-t09-bien-autrui-403-deux-entrees',
        403 === $sa && 'pk_listing_forbidden' === dp9_code_erreur($ba) && 403 === $sr && 'actif' === $st[$O1]['pk'],
        ['ajax' => [$sa, dp9_code_erreur($ba)], 'rest' => [$sr, dp9_code_erreur($br)], 'etat' => $st[$O1]]
);

$avant_csrf = dp9_state($A3);
list($sr, $br) = dp9_rest($base, $S, $A3, 'deactivate', 'vendu', '', false); // cookie sans X-WP-Nonce
list($sa, $ba) = dp9_ajax($base, $S, $A3, 'deactivate', 'vendu', '', 'nonce-faux-dp9'); // nonce invalide
$apres_csrf = dp9_state($A3);
$corps_ajax = isset($ba['raw']) ? (string) $ba['raw'] : wp_json_encode($ba);
$assert('DP9-OJ-022-csrf-rejete-deux-entrees-etat-inchange',
        401 === $sr && $apres_csrf[$A3] === $avant_csrf[$A3]
        && ('-1' === trim($corps_ajax) || 403 === $sa),
        ['rest_sans_nonce' => [$sr, dp9_code_erreur($br)], 'ajax_nonce_invalide' => [$sa, $corps_ajax], 'etat' => $apres_csrf[$A3], 'note' => 'REST cookie sans nonce → 401 rest_cookie_invalid_nonce ; AJAX nonce invalide → rejet wp_die(-1) sans exécution']
);

/* ═══ 8. T33 — JSON-LD par état ; T19 — fiche publique, indexable, sitemap ═══ */

$dispo_jsonld = static function (int $post_id) use ($base, $S): array {
        list($s, $html) = dp9_get($base, $S, parse_url(get_permalink($post_id), PHP_URL_PATH));
        if (200 !== $s) { return [$s, null]; }
        if (preg_match_all('#<script[^>]*application/ld\+json[^>]*>(.*?)</script>#s', $html, $m)) {
                foreach ($m[1] as $blob) {
                        if (false !== strpos($blob, 'schema.org') && false !== strpos($blob, 'availability')) {
                                if (preg_match('#"availability"\s*:\s*"https://schema\.org/(\w+)"#', $blob, $mm)) {
                                        return [$s, $mm[1]];
                                }
                        }
                }
        }
        return [$s, null];
};
list($sv, $av_v) = $dispo_jsonld((int) $F['V1_vendu']);
list($sar, $av_ar) = $dispo_jsonld((int) $F['AR1_archive']);
list($si, $av_i) = $dispo_jsonld((int) $F['I1_indisponible']);
list($sa3, $av_a3) = $dispo_jsonld($A3);
$assert('DP9-OJ-023-t33-jsonld-par-etat',
        'SoldOut' === $av_v && 'OutOfStock' === $av_ar && 'OutOfStock' === $av_i && 'InStock' === $av_a3,
        ['vendu' => $av_v, 'archive' => $av_ar, 'indisponible' => $av_i, 'actif' => $av_a3, 'http' => [$sv, $sar, $si, $sa3], 'mapping' => 'v1.1 §8 — archive corrigé de InStock vers OutOfStock']
);

$url_v1 = parse_url(get_permalink((int) $F['V1_vendu']), PHP_URL_PATH);
$slug_v1 = (string) get_post_field('post_name', (int) $F['V1_vendu']);
list($sf, $html) = dp9_get($base, $S, $url_v1);
list($ss, $sitemap) = dp9_get($base, $S, '/sitemap.xml');
$assert('DP9-OJ-024-t19-fiche-fermee-publique-indexable-sitemap',
        200 === $sf && false === strpos(strtolower($html), 'noindex') && 200 === $ss && false !== strpos($sitemap, $slug_v1),
        ['http_fiche' => $sf, 'noindex' => (bool) strpos(strtolower($html), 'noindex'), 'http_sitemap' => $ss, 'dans_sitemap' => (bool) strpos($sitemap, $slug_v1), 'slug' => $slug_v1]
);

/* ═══ 9. T36 — page « Mes annonces » ×3 langues ═══ */

$obs_pages = [];
$ok_pages = true;
foreach (array('fr', 'en', 'ar') as $lang) {
        $url = get_permalink((int) $carte['pages'][$lang]);
        list($s, $html) = dp9_get($base, $S, parse_url($url, PHP_URL_PATH));
        /* CP5 (revue navigateurs, D1) : le main.js réellement référencé par la
         * page doit piloter le champ de note du motif « autre » — vérification
         * statique du couplage gabarit/JavaScript sur l'actif servi. */
        $js_couplage = false;
        if (preg_match('#src="([^"]*assets/js/main\.js[^"]*)"#', $html, $m_src)) {
                $chemin_js = preg_replace('#^https?://[^/]+#', '', html_entity_decode($m_src[1], ENT_QUOTES));
                list($sj, $js) = dp9_get($base, $S, $chemin_js);
                $js_couplage = 200 === $sj
                        && false !== strpos($js, 'data-for-reason="autre"')
                        && false !== strpos($js, 'pk-reason-note');
        }
        $checks = [
                'http_200'        => 200 === $s,
                'bouton_desactiver' => false !== strpos($html, 'data-action="deactivate"'),
                'formulaire_motif'  => false !== strpos($html, 'pk-deactivate-reason'),
                'quatre_motifs'     => false !== strpos($html, 'value="vendu"') && false !== strpos($html, 'value="loue"') && false !== strpos($html, 'value="avis"') && false !== strpos($html, 'value="autre"'),
                'note_privee'       => false !== strpos($html, 'pk-reason-note'),
                'pas_de_message_trompeur' => false === strpos($html, 'Annonce supprimée'),
                /* CP5 (revue navigateurs, D1/D2) : D1 — le champ « Précisez » est déclaré
                 * pour le motif « autre » (data-for-reason) ET piloté par le main.js servi
                 * (échoue si le champ reste masqué faute de couplage) ; D2 — les deux
                 * messages de validation sont servis dans pkConfig.i18n. */
                'note_liee_autre'   => false !== strpos($html, 'data-for-reason="autre"'),
                'couplage_js_note_autre' => $js_couplage,
                'messages_validation_i18n' => false !== strpos($html, 'reasonRequired') && false !== strpos($html, 'noteRequired'),
        ];
        if ('ar' === $lang) {
                $checks['rtl'] = false !== strpos($html, 'dir="rtl"');
        }
        $obs_pages[$lang] = ['url' => parse_url($url, PHP_URL_PATH), 'checks' => $checks];
        if (in_array(false, $checks, true)) { $ok_pages = false; }
}
$assert('DP9-OJ-025-t36-ui-mes-annonces-trois-langues', $ok_pages, $obs_pages);

/* ═══ 10. Nettoyage ═══ */

wp_delete_post($x1, true);
wp_delete_post($x2, true);
(new \Partikulier\Core\Integration\ListingSynchronizer())->flush();

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
        'suite' => 'dp9-owner-journey-contract (parcours unique 2 entrées, matrice default-deny v1.1 §3.1 + R1, chaînes T25, reprise R1 (e), autrui/CSRF, JSON-LD T33, fiche T19, UI T36 ×3 langues)',
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
