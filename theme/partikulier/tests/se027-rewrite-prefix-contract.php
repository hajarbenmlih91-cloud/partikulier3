<?php
/**
 * Contrat SE-027 — double-préfixation rewrite avec Polylang (E-2701→E-2703,
 * CDC Phase 1 lot C — TRAIN3-CDC-PHASE1-EXECUTION v2/v2.1 §6) —
 * VERSION 3 (reprise ciblée des tests du 21/09/2026, revue commanditaire
 * RAPPORT-REVUE-REPRISE-PHASE1.md §9 — la v2 répondait à
 * RAPPORT-ANALYSE-LIVRAISON-PHASE1.md §4/§5).
 *
 * Défaut : dans la configuration Polylang de référence (fr défaut, en, ar,
 * force_lang=1, hide_default=0, CPT properties traduisible), les CINQ règles
 * déjà préfixées du thème reçoivent un second groupe de langue — cinq règles
 * double-préfixées mortes, l'ordre des groupes variant selon l'ordre réel des
 * langues configurées.
 *
 * Correctif du lot C (INCHANGÉ v2) : RULES_VERSION '4'→'5' (migration au
 * prochain maybe_flush(), JAMAIS de flush sur init) + déplacement des cinq
 * règles préfixées sous `! defined( 'POLYLANG_VERSION' )`. Les règles sans
 * préfixe restent déclarées inconditionnellement.
 *
 * DURCISSEMENTS v2 (les 6 identifiants d'assertion et le total 6 sont
 * conservés — l'oracle 382/40 demeure) :
 *  - E27-001 : flush de MISE EN ÉTAT avant la table de référence — une table
 *              préexistante périmée est régénérée au warm-up, la stabilité
 *              compare ensuite trois flush à la table saine (reprise §5 :
 *              « table préexistante différant de la table régénérée ») ;
 *  - E27-002 : la fiche « sans quartier » ne reçoit PLUS _pk_url_district
 *              (reprise §5, l.134 v1) ; les DEUX permaliens réels sont
 *              attaqués en GET : 200, la BONNE fiche servie (marqueur du run
 *              présent, l'autre fiche absente), langue fr vérifiée ;
 *  - E27-003 : les CIBLES des cinq règles préfixées sont vérifiées par
 *              substitution $matches + parse_str en processus neuf (reprise
 *              F1 : la mutation des cibles vers un mauvais type de contenu
 *              doit être ROUGE) ; les chemins non préfixés idem ; les vrais
 *              contenus sont servis en GET (archives, pagination, ville,
 *              fiches) ; la restauration Polylang est contrôlée dans un
 *              PROCESSUS NEUF (plugin actif, constante, langues, table saine
 *              après flush) et les codes de sortie des sous-processus sont
 *              intégrés au verdict (reprise §5) ;
 *  - E27-006 : requêtes GET réelles, plus AUCUNE requête HEAD (v1 l.279/291
 *              utilisaient CURLOPT_NOBODY — reprise F2) ; la pagination est
 *              prouvée par des assertions POSITIVES : fixtures dédiées du
 *              run (25 annonces récentes + 1 ancienne datée d'un an) rendent
 *              le contrat AUTONOME vis-à-vis du stock (reprise §5 :
 *              « 5/6, page 2 en 404 » sur installation pauvre) ; page 1
 *              distincte de la dernière page par croisement de marqueurs,
 *              langue vérifiée, page au-delà = 404, cible canonique GET 200
 *              sans boucle ; le critère négatif « ne contient pas se027 »
 *              n'est plus une preuve (une page factice HTTP 200 sans annonce
 *              doit être ROUGE — reprise F2) ;
 *  - E27-004/E27-005 : inchangés (contrat enfant SE-025, migration 4→5).
 *
 * DURCISSEMENTS v3 (reprise des tests du 21/09/2026 — les 6 identifiants
 * d'assertion et le total 6 restent inchangés, l'oracle 382/40 demeure) :
 *  - E27-006 (revue §9.1) : TOUTES les pages de pagination sont vérifiées
 *      POSITIVEMENT, plus seulement la première et la dernière — en v2, avec
 *      trois pages, remplacer uniquement la page 2 par un texte factice
 *      restait PASS 6/6 (seules la page 1 et la dernière étaient contrôlées).
 *      v3 : chaque page 200 doit être en français et contenir au moins un
 *      lien de fiche (/annonce/) ; l'ancienne du run ne peut apparaître que
 *      sur la dernière page ; les 25 récentes du run doivent toutes être
 *      servies sur l'ensemble des pages (complétude) ; page au-delà = 404.
 *      La mutation « page 2 seule factice » est désormais ROUGE ;
 *  - E27-003 (revue §9.3) : le CODE DE SORTIE du sous-processus de
 *      vérification sans Polylang est intégré au verdict — en v2, un
 *      sous-processus terminant en erreur 42 tout en émettant un JSON ok
 *      laissait le contrôle vert. v3 : exit != 0 ⇒ E27-003 FAIL ; la
 *      pagination nue vérifie aussi toutes ses pages positivement ;
 *  - E27-004 (revue §9.3) : le CODE DE SORTIE du contrat enfant SE-025 est
 *      intégré au verdict (en v2 il n'était qu'affiché) — un enfant qui
 *      rapporte PASS 12/12 mais sort en erreur est désormais ROUGE.
 *
 * Démo négative (les mutations des revues doivent toutes rester rouges) :
 *   F1  — muter les cibles des cinq règles préfixées (ex. post_type=post) :
 *         E27-003 FAIL (query substituée ≠ attendue) ;
 *   F2  — faire répondre /fr/annonces/page/N/ par 200 « texte factice » :
 *         E27-006 FAIL (marqueurs absents, page 1 = page factice) ;
 *   F2' — (revue §9.1) avec trois pages, remplacer SEULEMENT la page 2
 *         (intermédiaire) par un texte factice : E27-006 FAIL (page 2 sans
 *         lien de fiche, récentes du run non servies en complétude) ;
 *   X42 — (revue §9.3) faire sortir le sous-processus de vérification sans
 *         Polylang en erreur 42 (JSON ok néanmoins émis) : E27-003 FAIL ;
 *   S25 — (revue §9.3) faire sortir le contrat enfant SE-025 en erreur 42
 *         (PASS 12/12 néanmoins émis) : E27-004 FAIL.
 *
 * Rejouable :
 *   PK_BASE=http://127.0.0.1:8099 PK_WP_DIR=<wp> PK_COMMIT=<sha> \
 *   PK_WP_CLI="wp" php theme/partikulier/tests/se027-rewrite-prefix-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$base = rtrim((string) (getenv('PK_BASE') ?: ''), '/');
$commit = getenv('PK_COMMIT') ?: '';
$wpCli = (string) (getenv('PK_WP_CLI') ?: 'wp');
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if ($base === '' || !preg_match('#^https?://#', $base)) { fwrite(STDERR, "PK_BASE doit pointer vers le serveur démarré\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n"); exit(2); }
require $wpDir . '/wp-load.php';

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

/* ── Détecteur de doubles (copie conforme de la sonde v2, ordre-indépendant) ── */
$language_group = static function (string $group, array $languages): bool {
    $parts = explode('|', $group);
    return $parts !== [] && count(array_unique($parts)) === count($parts)
        && array_diff($parts, $languages) === [];
};
$double_rules = static function (array $rules, array $languages) use ($language_group): array {
    $found = [];
    foreach ($rules as $regex => $target) {
        if (preg_match('~^\^?\((?:\?:)?([^()]+)\)/\((?:\?:)?([^()]+)\)/~', (string) $regex, $m)
            && $language_group($m[1], $languages)
            && $language_group($m[2], $languages)) {
            $found[$regex] = $target;
        }
    }
    return $found;
};
$languesConfigurees = static function (): array {
    return function_exists('pll_languages_list') ? (array) pll_languages_list(['fields' => 'slug']) : ['fr', 'en', 'ar'];
};
$tableRules = static function (): array {
    $rules = get_option('rewrite_rules', null);
    return is_array($rules) ? $rules : [];
};
/* Première règle (ordre de la table) qui matche le path → [regex, query parsée].
 * La cible stockée est « index.php?<query avec $matches[N]> » : extraire la
 * query, substituer les captures réelles, PUIS parser — parse_str direct sur
 * la cible entière fabrique des clés « index_php?lang » illisibles. */
$matchRule = static function (string $path, array $rules): ?array {
    foreach ($rules as $regex => $target) {
        if (preg_match('#^' . (string) $regex . '#', $path, $m)) {
            $cible = (string) $target;
            $queryStr = str_starts_with($cible, 'index.php?') ? substr($cible, strlen('index.php?')) : $cible;
            $queryStr = preg_replace_callback(
                '#\$matches\[(\d+)\]#',
                static function (array $mm) use ($m): string {
                    $idx = (int) $mm[1];
                    return isset($m[$idx]) ? rawurlencode((string) $m[$idx]) : '';
                },
                $queryStr
            );
            $query = [];
            parse_str($queryStr, $query);
            return ['regex' => (string) $regex, 'query' => $query];
        }
    }
    return null;
};
/* GET réel (jamais HEAD — reprise F2) : corps + code + Location éventuelle. */
$getHttp = static function (string $url): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
    $corps = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $location = (string) (curl_getinfo($ch, CURLINFO_REDIRECT_URL) ?: '');
    curl_close($ch);
    return ['code' => $code, 'corps' => $corps, 'location' => $location];
};
/* v3 (revue §9.1) : une page d'archive RÉELLE comporte des cartes d'annonces
 * dont les liens pointent vers les fiches legacy Estatik (/property/<slug> —
 * forme observée sur le rendu réel du thème ; le pluriel /annonces/ des liens
 * de pagination et les liens de navigation ne matche pas). Une page factice
 * sans aucune annonce n'en contient aucun : preuve POSITIVE de contenu. */
$lienAnnonce = static function (string $corps): bool {
    return preg_match('#href="[^"]*/property/[^"]+"#', $corps) === 1;
};

/* ── Fixtures du run (hérmeticité, suppression en finally) ──
 * v2 : la fiche « sans quartier » ne reçoit PAS _pk_url_district (reprise §5),
 * et le run embarque sa propre pagination : 25 annonces récentes + 1 ancienne
 * datée d'un an garantissent au moins deux pages quel que soit le stock. */
wp_set_current_user(1);
$cpt = PARTIKULIER_ESTATIK_POST_TYPE;
$run = bin2hex(random_bytes(4));
$ville = 'se027ville-' . $run;
$quartier = 'se027quartier-' . $run;
$mkFix = static function (string $slug, bool $avecQuartier) use ($cpt, $ville, $quartier, $run): int {
    $id = wp_insert_post([
        'post_type' => $cpt,
        'post_status' => 'publish',
        'post_title' => 'SE-027 ' . $run . ' ' . $slug,
        'post_name' => $slug,
        'post_author' => 1,
    ]);
    update_post_meta($id, '_pk_url_city', $ville);
    if ($avecQuartier) { update_post_meta($id, '_pk_url_district', $quartier); }
    return (int) $id;
};
$slugQ = 'se027-fiche-quartier-' . $run;
$slugS = 'se027-fiche-simple-' . $run;
$ficheQ = $mkFix($slugQ, true);    // fiche AVEC quartier  → 4 segments
$ficheS = $mkFix($slugS, false);   // fiche SANS quartier  → 3 segments (v2 : vraiment sans)
$fixtures = [$ficheQ, $ficheS];

/* Pagination embarquée du run : 25 récentes + 1 ancienne (-365 j). */
$mkPag = static function (string $slug, bool $ancienne) use ($cpt, $run): int {
    $quand = $ancienne ? gmdate('Y-m-d H:i:s', time() - 365 * 86400) : gmdate('Y-m-d H:i:s');
    $id = wp_insert_post([
        'post_type' => $cpt,
        'post_status' => 'publish',
        'post_title' => 'SE-027 ' . $run . ' pagination ' . ($ancienne ? 'ancienne-' : 'recente-') . $slug,
        'post_name' => $slug,
        'post_author' => 1,
        'post_date' => $quand,
        'post_date_gmt' => $quand,
    ]);
    return (int) $id;
};
for ($i = 1; $i <= 25; $i++) {
    $id = $mkPag('se027-pag-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '-' . $run, false);
    if ($id > 0) { $fixtures[] = $id; }
}
$ancienneId = $mkPag('se027-ancienne-' . $run, true);
if ($ancienneId > 0) { $fixtures[] = $ancienneId; }
$marqueurRun = 'SE-027 ' . $run . ' ';
$marqueurAncienne = $marqueurRun . 'pagination ancienne-';

/* Sous-processus WP-CLI (processus neufs exigés par le CDC). */
$runWp = static function (string $args) use ($wpCli, $wpDir): array {
    $cmd = $wpCli . ' ' . $args . ' --path=' . escapeshellarg($wpDir) . ' 2>&1';
    exec($cmd, $out, $code);
    return ['code' => $code, 'out' => implode("\n", $out)];
};

$polylangEtaitActif = is_plugin_active('polylang/polylang.php');
$restaure = static function () use ($runWp, $polylangEtaitActif): void {
    if ($polylangEtaitActif) {
        $runWp('plugin activate polylang');
        $runWp('eval ' . escapeshellarg('Partikulier_Listing_Urls::flush();'));
    }
};

try {
    $langues = $languesConfigurees();

    /* ── E27-001 : contrôle positif du détecteur, puis zéro double sur 3 flush.
     * v2 : flush de MISE EN ÉTAT avant la table de référence — une table
     * préexistante périmée (rejeu après d'autres scénarios) est régénérée
     * ici, la stabilité compare les trois flush suivants à la table saine. ── */
    $fixturesDetecteur = [
        // Vraies doubles (permutations réelles observées, ^ présent ou absent) :
        '(en|fr|ar)/(fr|en|ar)/annonces/?$' => 'cible',
        '^(ar|en|fr)/(fr|ar|en)/annonce/[^/]+/([^/]+)/?$' => 'cible',
        '(fr|en|ar)/(en|fr|ar)/location/([^/]+)/?$' => 'cible',
        // Groupes non capturants : doubles aussi :
        '^(?:fr|en|ar)/(?:fr|en|ar)/annonces/page/([0-9]+)/?$' => 'cible',
        // Non-doubles : groupe non-langue, préfixe simple, règle simple :
        '(fr|en|ar)/(ville|quartier)/x/?$' => 'cible',
        '^(fr|en|ar)/annonces/?$' => 'cible',
        '^annonces/?$' => 'cible',
    ];
    $detecte = $double_rules($fixturesDetecteur, ['fr', 'en', 'ar']);
    $attendu = [
        '(en|fr|ar)/(fr|en|ar)/annonces/?$',
        '^(ar|en|fr)/(fr|ar|en)/annonce/[^/]+/([^/]+)/?$',
        '(fr|en|ar)/(en|fr|ar)/location/([^/]+)/?$',
        '^(?:fr|en|ar)/(?:fr|en|ar)/annonces/page/([0-9]+)/?$',
    ];
    sort($attendu);
    $detecteCles = array_keys($detecte);
    sort($detecteCles);
    $positif = $detecteCles === $attendu;

    Partikulier_Listing_Urls::flush(); // warm-up : régénère une table éventuellement périmée
    $tableRef = $tableRules();
    $stables = true;
    $zeroDouble = true;
    for ($i = 1; $i <= 3; $i++) {
        Partikulier_Listing_Urls::flush();
        $table = $tableRules();
        $doubles = $double_rules($table, $langues);
        if (count($doubles) !== 0) { $zeroDouble = false; }
        if ($table !== $tableRef) { $stables = false; }
    }
    $assert('E27-001', $positif && $zeroDouble && $stables,
        sprintf('détecteur positif : %d/%d fixtures détectées (permutations, ^, (?:), non-langue) ; warm-up puis 3 flush : %d double(s), tables %s (ordre et cibles)',
            count($detecteCles), count($attendu),
            count($double_rules($tableRules(), $langues)),
            $stables ? 'strictement identiques' : 'DIVERGENTES'));

    /* ── E27-002 : routes simples par langue configurée, bonnes cibles,
     * + GET réels des deux fiches (v2 : la sans-quartier est vraiment sans). ── */
    $routesOk = true;
    $routesDetail = [];
    $table = $tableRules();
    $collisionVille = null;
    foreach ($langues as $L) {
        $cas = [
            'accueil'   => [$L . '/', ['lang' => $L]],
            'archive'   => [$L . '/annonces/', ['post_type' => $cpt, 'lang' => $L]],
            'page'      => [$L . '/annonces/page/2/', ['post_type' => $cpt, 'paged' => '2', 'lang' => $L]],
            'ficheQ'    => [$L . '/annonce/' . $ville . '/' . $quartier . '/' . $slugQ, ['post_type' => $cpt, 'pk_listing_slug' => $slugQ, 'lang' => $L]],
            'ficheS'    => [$L . '/annonce/' . $ville . '/' . $slugS, ['post_type' => $cpt, 'pk_listing_slug' => $slugS, 'lang' => $L]],
        ];
        foreach ($cas as $nom => [$path, $attenduQuery]) {
            $hit = $matchRule($path, $table);
            $ok = $hit !== null;
            if ($ok) {
                foreach ($attenduQuery as $k => $v) {
                    if ((string) ($hit['query'][$k] ?? '') !== (string) $v) { $ok = false; }
                }
            }
            if (!$ok) { $routesOk = false; }
            $routesDetail[] = sprintf('%s/%s : %s', $L, $nom, $ok ? 'cible conforme' : ($hit === null ? 'AUCUNE règle ne matche' : 'cible ' . json_encode($hit['query'], JSON_UNESCAPED_SLASHES)));
        }
        /* Route ville : informative — la règle effective est la taxonomie
         * es_location d'Estatik (collision PRÉEXISTANTE, présente avant C,
         * rapportée séparément, jamais corrigée hors périmètre). */
        $hitVille = $matchRule($L . '/location/' . $ville, $table);
        if ($hitVille !== null && $collisionVille === null) {
            $collisionVille = sprintf('%s/location/… : règle effective %s', $L, $hitVille['regex']);
        }
    }

    /* GET réels des deux fiches (langue fr) : la BONNE fiche servie. */
    $permalinkQ = (string) get_permalink($ficheQ);
    $permalinkS = (string) get_permalink($ficheS);
    $cheminQ = (string) wp_parse_url($permalinkQ, PHP_URL_PATH);
    $cheminS = (string) wp_parse_url($permalinkS, PHP_URL_PATH);
    $fichesGetOk = str_contains($cheminQ, '/fr/') && str_contains($cheminS, '/fr/');
    $fichesDetail = [];
    if ($fichesGetOk) {
        $rQ = $getHttp($base . $cheminQ);
        $okQ = $rQ['code'] === 200
            && strpos($rQ['corps'], $marqueurRun . $slugQ) !== false       // la fiche Q est servie
            && strpos($rQ['corps'], $marqueurRun . $slugS) === false       // pas l'autre fiche
            && strpos($rQ['corps'], '<html lang="fr') !== false;           // langue fr
        $rS = $getHttp($base . $cheminS);
        $okS = $rS['code'] === 200
            && strpos($rS['corps'], $marqueurRun . $slugS) !== false
            && strpos($rS['corps'], $marqueurRun . $slugQ) === false
            && strpos($rS['corps'], '<html lang="fr') !== false;
        $fichesGetOk = $okQ && $okS;
        $fichesDetail = [
            sprintf('GET ficheQ (4 segments) : HTTP %d, fiche Q servie %s, langue fr %s', $rQ['code'], $okQ ? 'oui' : 'NON', strpos($rQ['corps'], '<html lang="fr') !== false ? 'oui' : 'NON'),
            sprintf('GET ficheS (3 segments, SANS quartier) : HTTP %d, fiche S servie %s, langue fr %s', $rS['code'], $okS ? 'oui' : 'NON', strpos($rS['corps'], '<html lang="fr') !== false ? 'oui' : 'NON'),
        ];
    } else {
        $fichesDetail = ['permaliens de fixtures hors contexte /fr/ : Q=' . $permalinkQ . ' S=' . $permalinkS];
    }
    $assert('E27-002', $routesOk && $fichesGetOk,
        sprintf('routes effectives par langue configurée (%s) : %s ; GET réels : %s ; note ville : %s (collision taxonomie es_location préexistante, rapportée — arbitrage demandé, hors périmètre C)',
            implode('/', $langues), implode(' ; ', $routesDetail), implode(' ; ', $fichesDetail), $collisionVille ?: 'non matchée'));

    /* ── E27-005 : version 5 + migration contrôlée 4→5 + idempotence. ── */
    $versionCode = (string) (new ReflectionClassConstant('Partikulier_Listing_Urls', 'RULES_VERSION'))->getValue();
    $optionAvant = (string) get_option('pk_url_rules_version', '');
    $migrationOk = false;
    $migrationDetail = '';
    if ($versionCode === '5') {
        // Simuler l'état stocké antérieur (installation réelle en version 4),
        // puis déclencher le mécanisme réel de migration.
        update_option('pk_url_rules_version', '4', false);
        $tableAvantMigration = $tableRules();
        Partikulier_Listing_Urls::maybe_flush();
        $optionApres = (string) get_option('pk_url_rules_version', '');
        $tableApresMigration = $tableRules();
        Partikulier_Listing_Urls::maybe_flush(); // second appel : idempotence
        $optionSecond = (string) get_option('pk_url_rules_version', '');
        $tableSecond = $tableRules();
        $migrationOk = $optionAvant === '5' && $optionApres === '5' && $optionSecond === '5'
            && $tableApresMigration === $tableAvantMigration && $tableSecond === $tableAvantMigration;
        $migrationDetail = sprintf('code=%s ; option avant contrat=%s (migration déjà effective sur cette installation), repositionnée à 4 puis maybe_flush() → %s, second appel → %s, table stable=%s',
            $versionCode, $optionAvant, $optionApres, $optionSecond,
            ($tableApresMigration === $tableAvantMigration && $tableSecond === $tableAvantMigration) ? 'oui' : 'NON');
    } else {
        $migrationDetail = 'RULES_VERSION du code = ' . $versionCode . ' (attendu 5) — correctif C non actif';
    }
    $assert('E27-005', $migrationOk, $migrationDetail);

    /* ── E27-006 : legacy propriété + pagination POSITIVE (v2, reprise F2).
     * GET réels uniquement (aucun HEAD) ; pagination prouvée par marqueurs :
     * page 1 contient une récente du run, la dernière page contient
     * l'ancienne du run, l'ancienne est absente de la page 1, la page
     * au-delà de la dernière est 404, la 301 legacy est suivie en GET vers
     * une cible finale 200 différente de l'origine (pas de boucle). ── */
    $table = $tableRules();
    $legacyPage = $matchRule('fr/property/page/2/', $table);
    $legacyNue = $matchRule('fr/property/', $table);
    $legacyOk = $legacyPage !== null && ($legacyPage['query']['post_type'] ?? '') === $cpt && ($legacyPage['query']['paged'] ?? '') === '2' && ($legacyPage['query']['lang'] ?? '') === 'fr'
        && $legacyNue !== null && ($legacyNue['query']['post_type'] ?? '') === $cpt;
    $httpDetail = [];
    if ($legacyOk) {
        /* /property/ : GET réel avec corps (plus de CURLOPT_NOBODY — v2). */
        $rPropre = $getHttp($base . '/property/');
        $cibleOk = false;
        if ($rPropre['code'] === 200 && $rPropre['corps'] !== '') {
            $cibleOk = true;
            $httpDetail[] = 'GET /property/ → 200 direct (corps ' . strlen($rPropre['corps']) . ' o)';
        } elseif ($rPropre['code'] === 301 && $rPropre['location'] !== '') {
            $rFinal = $getHttp($rPropre['location']);
            $pasBoucle = rtrim($rPropre['location'], '/') !== rtrim($base . '/property/', '/');
            $cibleOk = $rFinal['code'] === 200 && $rFinal['corps'] !== '' && $pasBoucle;
            $httpDetail[] = sprintf('GET /property/ → 301 → GET %s → HTTP %d (corps %d o, boucle %s)',
                $rPropre['location'], $rFinal['code'], strlen($rFinal['corps']), $pasBoucle ? 'non' : 'OUI');
        } else {
            $httpDetail[] = sprintf('GET /property/ → HTTP %d inattendu', $rPropre['code']);
        }

        /* Pagination v3 (revue §9.1) : scan des pages /fr/annonces/ jusqu'au
         * premier 404, puis vérification POSITIVE de TOUTES les pages — en
         * v2 seules la page 1 et la dernière étaient contrôlées : remplacer
         * uniquement une page INTERMÉDIAIRE par un texte factice restait
         * vert. v3 exige de chaque page 200 : français, au moins un lien de
         * fiche (/annonce/), ancienne du run absente sauf sur la dernière ;
         * et complétude : les 25 récentes du run servies sur l'ensemble. */
        $p1 = $getHttp($base . '/fr/annonces/');
        $pages200 = [];
        for ($n = 2; $n <= 15; $n++) {
            $r = $getHttp($base . '/fr/annonces/page/' . $n . '/');
            if ($r['code'] === 200) { $pages200[$n] = $r; } else { break; }
        }
        $apresDerniere = null;
        $derniereNum = $pages200 !== [] ? max(array_keys($pages200)) : 0;
        $derniere = $derniereNum > 0 ? $pages200[$derniereNum] : null;
        if ($derniereNum > 0) {
            $apresDerniere = $getHttp($base . '/fr/annonces/page/' . ($derniereNum + 1) . '/');
        }
        $toutesPages = [1 => $p1] + $pages200;
        $p1ContientRecente = $p1['code'] === 200 && strpos($p1['corps'], $marqueurRun . 'pagination recente-') !== false;
        $p1Francais = strpos($p1['corps'], '<html lang="fr') !== false;
        $derniereContientAncienne = $derniere !== null && strpos($derniere['corps'], $marqueurAncienne) !== false;
        $p1SansAncienne = $p1['code'] === 200 && strpos($p1['corps'], $marqueurAncienne) === false;
        $derniereFrancais = $derniere !== null && strpos($derniere['corps'], '<html lang="fr') !== false;
        $auDela404 = $apresDerniere !== null && $apresDerniere['code'] === 404;
        /* v3 : contrôle positif page par page (langue + lien de fiche + place
         * de l'ancienne) sur TOUTES les pages y compris intermédiaires. */
        $pagesPositives = true;
        $pagesNegatives = [];
        foreach ($toutesPages as $num => $rPage) {
            $francais = strpos($rPage['corps'], '<html lang="fr') !== false;
            $avecLien = $lienAnnonce($rPage['corps']);
            $ancienneIci = strpos($rPage['corps'], $marqueurAncienne) !== false;
            $placeAncienneOk = $num === $derniereNum ? $ancienneIci : !$ancienneIci;
            if (!$francais || !$avecLien || !$placeAncienneOk) {
                $pagesPositives = false;
                $pagesNegatives[] = sprintf('page %d : langue fr %s, lien fiche %s, ancienne %s%s',
                    $num, $francais ? 'oui' : 'NON', $avecLien ? 'présent' : 'ABSENT',
                    $ancienneIci ? 'présente' : 'absente', $placeAncienneOk ? '' : ' (à tort)');
            }
        }
        /* v3 : complétude — les 25 récentes du run doivent toutes être
         * servies quelque part sur les pages 200 (une page intermédiaire
         * factice les fait disparaître du corpus servi). */
        $corpsToutesPages = implode("\n", array_map(static fn($r) => $r['corps'], $toutesPages));
        $recentesServies = 0;
        $recentesManquantes = [];
        for ($i = 1; $i <= 25; $i++) {
            $marqueurRecente = $marqueurRun . 'pagination recente-se027-pag-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '-' . $run;
            if (strpos($corpsToutesPages, $marqueurRecente) !== false) { $recentesServies++; }
            else { $recentesManquantes[] = 'se027-pag-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT); }
        }
        $completudeRecentes = $recentesServies === 25;
        $paginationOk = $p1ContientRecente && $p1Francais && $pages200 !== []
            && $derniereContientAncienne && $p1SansAncienne && $derniereFrancais && $auDela404
            && $pagesPositives && $completudeRecentes;
        $httpDetail[] = sprintf('GET /fr/annonces/ → %d (récente du run %s, langue fr %s, ancienne absente %s) ; %d page(s) paginée(s) 200, dernière page n°%d : ancienne du run %s, langue fr %s ; page suivante → HTTP %d ; pages vérifiées positivement (langue, lien de fiche, place de l\'ancienne) : %s ; complétude des 25 récentes du run : %d/25%s',
            $p1['code'],
            $p1ContientRecente ? 'présente' : 'ABSENTE',
            $p1Francais ? 'oui' : 'NON',
            $p1SansAncienne ? 'oui' : 'NON',
            count($pages200),
            $derniereNum,
            $derniereContientAncienne ? 'présente' : 'ABSENTE',
            $derniereFrancais ? 'oui' : 'NON',
            ($apresDerniere !== null ? $apresDerniere['code'] : 0),
            $pagesPositives ? 'TOUTES' : ('NON — ' . implode(', ', $pagesNegatives)),
            $recentesServies,
            $completudeRecentes ? '' : (' — manquantes : ' . implode(',', array_slice($recentesManquantes, 0, 5)) . (count($recentesManquantes) > 5 ? '…' : '')));
        $legacyOk = $cibleOk && $paginationOk;
    }
    $assert('E27-006', $legacyOk,
        sprintf('règles legacy property/ et property/page/N présentes ; %s', implode(' ; ', $httpDetail) ?: 'règles legacy absentes'));

    /* ── E27-003 : branche SANS Polylang — processus neufs + CIBLES vérifiées
     * (v2, reprise F1) + GET réels + restauration contrôlée en PROCESSUS NEUF
     * avec codes de sortie intégrés au verdict (v2, reprise §5). ── */
    $sansDetail = [];
    $sansOk = false;
    if (!$polylangEtaitActif) {
        $assert('E27-003', false, 'Polylang inactif au démarrage du contrat — contexte inattendu à cette étape du workflow');
    } else {
        $desactive = $runWp('plugin deactivate polylang');
        try {
            /* Heredoc processus neuf n°1 : la table est RÉGÉNÉRÉE par un flush
             * dans CE processus, puis chaque chemin est résolu par la PREMIÈRE
             * règle qui matche et sa CIBLE est substituée ($matches) puis
             * comparée à la query attendue COMPLÈTE (clés et valeurs).
             * Les valeurs du run sont injectées en tête de fichier généré
             * (wp eval-file ne transmet pas $argv de façon fiable). */
            $verif = <<<'PHPEOT'
if (defined('POLYLANG_VERSION')) { echo json_encode(['ok' => false, 'cause' => 'POLYLANG_VERSION encore definie']); exit(1); }
$langueDetectees = function_exists('pll_languages_list') ? (array) pll_languages_list(['fields' => 'slug']) : [];
if ($langueDetectees !== []) { echo json_encode(['ok' => false, 'cause' => 'langues encore exposees']); exit(1); }
Partikulier_Listing_Urls::flush();
$table = get_option('rewrite_rules', []);
$cpt = PARTIKULIER_ESTATIK_POST_TYPE;
/* Substitution $matches + parse_str (même logique que le contrat parent). */
$resous = static function (string $path, array $rules): ?array {
    foreach ($rules as $regex => $target) {
        if (preg_match('#^' . (string) $regex . '#', $path, $m)) {
            $cible = (string) $target;
            $q = str_starts_with($cible, 'index.php?') ? substr($cible, strlen('index.php?')) : $cible;
            $q = preg_replace_callback('#\$matches\[(\d+)\]#',
                static function (array $mm) use ($m): string {
                    $idx = (int) $mm[1];
                    return isset($m[$idx]) ? rawurlencode((string) $m[$idx]) : '';
                }, $q);
            $query = [];
            parse_str($q, $query);
            return ['regex' => (string) $regex, 'query' => $query];
        }
    }
    return null;
};
$attendues = [
    '^(fr|en|ar)/annonces/page/([0-9]+)/?$',
    '^(fr|en|ar)/annonces/?$',
    '^(fr|en|ar)/location/([^/]+)/?$',
    '^(fr|en|ar)/annonce/[^/]+/[^/]+/([^/]+)/?$',
    '^(fr|en|ar)/annonce/[^/]+/([^/]+)/?$',
];
$manquantes = [];
foreach ($attendues as $regex) { if (!isset($table[$regex])) { $manquantes[] = $regex; } }
/* Cibles substituées : chemins préfixés (règles du thème conditionnées au
 * sans-Polylang) ET chemins nus (CPT/thème inconditionnels). Query attendue
 * COMPLÈTE : toute clé parasite (ex. mutation) ou valeur erronée (ex.
 * post_type=post) fait échouer — reprise F1. */
$cas = [
    'fr/annonces/' => ['post_type' => $cpt, 'lang' => 'fr'],
    'en/annonces/page/2/' => ['post_type' => $cpt, 'paged' => '2', 'lang' => 'en'],
    'ar/location/' . $villeRun => ['post_type' => $cpt, 'pk_city_slug' => $villeRun, 'lang' => 'ar'],
    'fr/annonce/' . $villeRun . '/' . $quartierRun . '/' . $slugQRun => ['post_type' => $cpt, 'pk_listing_slug' => $slugQRun, 'lang' => 'fr'],
    'fr/annonce/' . $villeRun . '/' . $slugSRun => ['post_type' => $cpt, 'pk_listing_slug' => $slugSRun, 'lang' => 'fr'],
    'annonces/' => ['post_type' => $cpt],
    'annonces/page/2/' => ['post_type' => $cpt, 'paged' => '2'],
    'annonce/' . $villeRun . '/' . $quartierRun . '/' . $slugQRun => ['post_type' => $cpt, 'pk_listing_slug' => $slugQRun],
    'annonce/' . $villeRun . '/' . $slugSRun => ['post_type' => $cpt, 'pk_listing_slug' => $slugSRun],
    'location/' . $villeRun => ['post_type' => $cpt, 'pk_city_slug' => $villeRun],
];
$cibles = [];
foreach ($cas as $path => $attenduQuery) {
    $hit = $resous($path, $table);
    $ok = $hit !== null && $hit['query'] == $attenduQuery && $hit['query'] !== [] && count($hit['query']) === count($attenduQuery);
    $cibles[$path] = $ok ? true : ($hit === null ? 'aucune-regle' : $hit['query']);
}
/* Verdict STRICT : toutes les valeurs doivent etre === true (les echecs sont
 * stockes comme tableaux/chaines — un in_array(false) ne les verrait jamais,
 * controle passant a tort evite de justesse — reprise F1). */
$conformes = count(array_filter($cibles, static fn($v) => $v === true));
$ok = $manquantes === [] && $conformes === count($cas);
echo json_encode(['ok' => $ok, 'manquantes' => $manquantes, 'cibles' => $cibles, 'conformes' => $conformes, 'total_cas' => count($cas), 'total' => count($table)], JSON_UNESCAPED_SLASHES);
PHPEOT;
            $entete = "<?php\n"
                . '$villeRun = ' . var_export($ville, true) . ";\n"
                . '$quartierRun = ' . var_export($quartier, true) . ";\n"
                . '$slugQRun = ' . var_export($slugQ, true) . ";\n"
                . '$slugSRun = ' . var_export($slugS, true) . ";\n";
            $fichierVerif = sys_get_temp_dir() . '/se027-sans-polylang-' . $run . '.php';
            file_put_contents($fichierVerif, $entete . $verif);
            /* v3 (revue §9.3) : le CODE DE SORTIE du sous-processus fait partie
             * du verdict — en v2, un sous-processus terminant en erreur 42
             * tout en émettant un JSON ok laissait le contrôle vert. */
            $sortieSans = $runWp('eval-file ' . escapeshellarg($fichierVerif));
            unlink($fichierVerif);
            $json = json_decode($sortieSans['out'] ?? '', true);
            $verifExit = (int) $sortieSans['code'];
            $ciblesOk = $verifExit === 0 && is_array($json) && ($json['ok'] ?? false) === true;

            /* GET réels sans Polylang (le serveur recharge WP à chaque requête :
             * l'état désactivé est visible — vrais contenus servis). */
            $gArch = $getHttp($base . '/annonces/');
            $gArchOk = $gArch['code'] === 200 && strpos($gArch['corps'], $marqueurRun) !== false;
            /* Pagination nue v3 (revue §9.1) : TOUTES les pages 200 sont
             * conservées et vérifiées POSITIVEMENT (lien de fiche présent,
             * ancienne du run seulement sur la dernière), plus complétude des
             * 25 récentes sur l'ensemble des pages nues. */
            $pagesNues = [1 => $gArch];
            for ($n = 2; $n <= 15; $n++) {
                $r = $getHttp($base . '/annonces/page/' . $n . '/');
                if ($r['code'] === 200) { $pagesNues[$n] = $r; } else { break; }
            }
            $derniereNueNum = max(array_keys($pagesNues));
            $derniereNue = $pagesNues[$derniereNueNum];
            $pagesNuesPositives = true;
            $pagesNuesNegatives = [];
            foreach ($pagesNues as $num => $rPage) {
                $avecLien = $lienAnnonce($rPage['corps']);
                $ancienneIci = strpos($rPage['corps'], $marqueurAncienne) !== false;
                $placeAncienneOk = $num === $derniereNueNum ? $ancienneIci : !$ancienneIci;
                if (!$avecLien || !$placeAncienneOk) {
                    $pagesNuesPositives = false;
                    $pagesNuesNegatives[] = sprintf('page %d : lien fiche %s, ancienne %s%s',
                        $num, $avecLien ? 'présent' : 'ABSENT', $ancienneIci ? 'présente' : 'absente',
                        $placeAncienneOk ? '' : ' (à tort)');
                }
            }
            $corpsToutesPagesNues = implode("\n", array_map(static fn($r) => $r['corps'], $pagesNues));
            $recentesNuesServies = 0;
            for ($i = 1; $i <= 25; $i++) {
                $marqueurRecente = $marqueurRun . 'pagination recente-se027-pag-' . str_pad((string) $i, 2, '0', STR_PAD_LEFT) . '-' . $run;
                if (strpos($corpsToutesPagesNues, $marqueurRecente) !== false) { $recentesNuesServies++; }
            }
            $gPagOk = $derniereNue !== null && strpos($derniereNue['corps'], $marqueurAncienne) !== false
                && $pagesNuesPositives && $recentesNuesServies === 25;
            $gFicheQ = $getHttp($base . '/annonce/' . $ville . '/' . $quartier . '/' . $slugQ . '/');
            $gFicheQOk = $gFicheQ['code'] === 200 && strpos($gFicheQ['corps'], $marqueurRun . $slugQ) !== false;
            $gFicheS = $getHttp($base . '/annonce/' . $ville . '/' . $slugS . '/');
            $gFicheSOk = $gFicheS['code'] === 200 && strpos($gFicheS['corps'], $marqueurRun . $slugS) !== false;
            $gVille = $getHttp($base . '/location/' . $ville . '/');
            $gVilleOk = $gVille['code'] === 200 && strpos($gVille['corps'], $marqueurRun) !== false;

            $sansOk = $desactive['code'] === 0 && $verifExit === 0 && $ciblesOk && $gArchOk && $gPagOk && $gFicheQOk && $gFicheSOk && $gVilleOk;
            $sansDetail = [
                'deactivation exit=' . $desactive['code'],
                'verif sans-Polylang exit=' . $verifExit . ($verifExit === 0 ? '' : ' (SOUS-PROCESSUS EN ERREUR — v3 intègre ce code au verdict)'),
                'regles: ' . (is_array($json) ? sprintf('%d/%d préfixées explicites présentes, %d cibles substituées conformes sur %d (attendu %d), total %d',
                    5 - count($json['manquantes'] ?? []), 5,
                    (int) ($json['conformes'] ?? -1), (int) ($json['total_cas'] ?? -1), (int) ($json['total_cas'] ?? -1), $json['total'] ?? -1) : 'sortie invalide'),
                sprintf('GET /annonces/ : %d (annonces du run %s)', $gArch['code'], $gArchOk ? 'servies' : 'ABSENTES'),
                sprintf('GET /annonces/page/ : %d page(s) 200, dernière n°%d (ancienne du run %s) ; pages vérifiées positivement (lien de fiche, place de l\'ancienne) : %s ; complétude 25 récentes : %d/25',
                    count($pagesNues) - 1, $derniereNueNum,
                    (strpos(($derniereNue['corps'] ?? ''), $marqueurAncienne) !== false) ? 'servie' : 'ABSENTE',
                    $pagesNuesPositives ? 'TOUTES' : ('NON — ' . implode(', ', $pagesNuesNegatives)),
                    $recentesNuesServies),
                sprintf('GET ficheQ nue : %d (%s)', $gFicheQ['code'], $gFicheQOk ? 'fiche servie' : 'NON'),
                sprintf('GET ficheS nue : %d (%s)', $gFicheS['code'], $gFicheSOk ? 'fiche servie' : 'NON'),
                sprintf('GET /location/%s/ : %d (%s)', $ville, $gVille['code'], $gVilleOk ? 'annonces de la ville servies' : 'NON'),
            ];
        } finally {
            /* Restauration trilingue OBLIGATOIRE, même en cas d'échec.
             * v2 : le verdict de restauration est établi dans un PROCESSUS
             * NEUF (plus de lecture get_option du parent — cache possible),
             * et les codes de sortie des sous-processus sont intégrés. */
            $reactive = $runWp('plugin activate polylang');
            /* md5 de la table trilingue de référence du run (capturée après le
             * warm-up de E27-001) : le processus neuf doit la REPRODUIRE. */
            $md5Ref = md5(serialize($tableRef));
            $verifRetour = <<<'PHPEOT'
if (!defined('POLYLANG_VERSION')) { echo json_encode(['ok' => false, 'cause' => 'POLYLANG_VERSION absente']); exit(1); }
if (!is_plugin_active('polylang/polylang.php')) { echo json_encode(['ok' => false, 'cause' => 'plugin inactif']); exit(1); }
$langues = function_exists('pll_languages_list') ? (array) pll_languages_list(['fields' => 'slug']) : [];
$triees = $langues; sort($triees);
$attendues = ['ar', 'en', 'fr']; $triAttendues = $attendues; sort($triAttendues);
if ($triees !== $triAttendues) { echo json_encode(['ok' => false, 'cause' => 'langues ' . implode('/', $langues)]); exit(1); }
/* La table restaurée : flush dans CE processus neuf, puis idempotence par
 * second flush — et comparaison à la référence trilingue du run (md5). */
Partikulier_Listing_Urls::flush();
$t1 = get_option('rewrite_rules', []);
Partikulier_Listing_Urls::flush();
$t2 = get_option('rewrite_rules', []);
$languesRef = $langues;
$doubles = 0;
foreach ($t1 as $regex => $t) {
    if (preg_match('~^\^?\((?:\?:)?([^()]+)\)/\((?:\?:)?([^()]+)\)/~', (string) $regex, $m)) {
        $g1 = explode('|', $m[1]); $g2 = explode('|', $m[2]);
        $ok1 = $g1 !== [] && count(array_unique($g1)) === count($g1) && array_diff($g1, $languesRef) === [];
        $ok2 = $g2 !== [] && count(array_unique($g2)) === count($g2) && array_diff($g2, $languesRef) === [];
        if ($ok1 && $ok2) { $doubles++; }
    }
}
$version = (string) get_option('pk_url_rules_version', '');
$identique = md5(serialize($t1)) === $md5RefRun;
$stable = $t1 === $t2;
echo json_encode(['ok' => $doubles === 0 && $stable && $version === '5' && $identique, 'doubles' => $doubles, 'stable' => $stable, 'identique_ref' => $identique, 'version' => $version, 'total' => count($t1)], JSON_UNESCAPED_SLASHES);
PHPEOT;
            $enteteRetour = "<?php\n" . '$md5RefRun = ' . var_export($md5Ref, true) . ";\n";
            $fichierRetour = sys_get_temp_dir() . '/se027-retour-' . $run . '.php';
            file_put_contents($fichierRetour, $enteteRetour . $verifRetour);
            $reflush = $runWp('eval-file ' . escapeshellarg($fichierRetour));
            unlink($fichierRetour);
            $jsonRetour = json_decode($reflush['out'] ?? '', true);
            $retourOk = $reactive['code'] === 0 && $reflush['code'] === 0
                && is_array($jsonRetour) && ($jsonRetour['ok'] ?? false) === true;
            $sansDetail[] = sprintf('restauration (processus neuf) : reactiver exit=%d, controle exit=%d, plugin actif, langues %s, %d double(s), table trilingue identique à la reference du run=%s, second flush identique=%s, version=%s',
                $reactive['code'], $reflush['code'],
                is_array($jsonRetour) ? 'rétablies' : 'réponse invalide',
                (int) ($jsonRetour['doubles'] ?? -1),
                (is_array($jsonRetour) && ($jsonRetour['identique_ref'] ?? false)) ? 'oui' : 'NON',
                (is_array($jsonRetour) && ($jsonRetour['stable'] ?? false)) ? 'oui' : 'NON',
                (string) ($jsonRetour['version'] ?? '?'));
            if (!$retourOk) { $sansOk = false; }
        }
        $assert('E27-003', $sansOk,
            sprintf('sans Polylang (processus neufs) : %s', implode(' ; ', $sansDetail)));
    }

    /* ── E27-004 : les neuf URLs SE-025 (contrat enfant, jamais recomptées). ── */
    $cheminSe025 = dirname(__DIR__, 1) . '/tests/se025-trilingual-routes-contract.php';
    if (!is_file($cheminSe025)) { $cheminSe025 = __DIR__ . '/se025-trilingual-routes-contract.php'; }
    $cmd = 'PK_BASE=' . escapeshellarg($base) . ' PK_WP_DIR=' . escapeshellarg($wpDir)
        . ' PK_COMMIT=' . escapeshellarg($commit) . ' ' . escapeshellarg(PHP_BINARY)
        . ' ' . escapeshellarg($cheminSe025) . ' 2>&1';
    exec($cmd, $sortieSe025, $codeSe025);
    $jsonSe025 = json_decode(implode("\n", $sortieSe025) ?: '', true);
    /* v3 (revue §9.3) : le CODE DE SORTIE de l'enfant fait partie du verdict —
     * en v2 il n'était qu'affiché : un enfant rapportant PASS 12/12 mais
     * sortant en erreur (ex. 42) laissait E27-004 vert. */
    $se025Ok = $codeSe025 === 0
        && is_array($jsonSe025)
        && ($jsonSe025['status'] ?? '') === 'PASS'
        && (int) ($jsonSe025['total'] ?? 0) === 12
        && (int) ($jsonSe025['passed'] ?? 0) === 12;
    $assert('E27-004', $se025Ok,
        sprintf('contrat enfant SE-025 : %s/12 (exit %d%s) — ses 12 assertions ne sont PAS comptées dans ce contrat',
            is_array($jsonSe025) ? (string) $jsonSe025['passed'] : '?', $codeSe025,
            $codeSe025 === 0 ? '' : ' — ENFANT EN ERREUR, code intégré au verdict (v3)'));
} catch (Throwable $error) {
    $restaure();
    $assert('E27-EXCEPTION', false, $error->getMessage());
} finally {
    foreach ($fixtures as $fixId) {
        if ($fixId > 0) { wp_delete_post($fixId, true); }
    }
}

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'se027-rewrite-prefix-contract (double-préfixation rewrite : migration 4→5, zéro double sur 3 flush, sans-Polylang en processus neufs — E-2701→E-2703)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php theme/partikulier/tests/se027-rewrite-prefix-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
