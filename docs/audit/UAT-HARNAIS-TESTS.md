# Tests du harnais d'audit UAT — séries A à E

Date : 2026-11-10. Objet : vérifier le harnais d'audit UAT au commit `4f14acd9`
(parent `5aa70f44`, base applicative `c9e1413`).

Le code des tests est versionné dans `docs/audit/harnais-tests/` sur une branche dédiée
(**jamais** dans le ref/tag d'audit) : `gen.py`, `run.sh`, `gen_php.py`, `run_php.mjs`,
`run_login.mjs`, `playwright_mock_register.mjs`, `playwright_mock_hooks.mjs`,
`playwright_mock.mjs` (voir `docs/audit/harnais-tests/README.md`).

## Méthode

1. Extraction propre de `4f14acd9` sans modifier le commit ni le tag `audit-2.10.16-6.20.214-3`.
2. **Séries A, B, C (19 tests bash)** : blocs bash **extraits du YAML de `4f14acd9`** par `gen.py`,
   exécutés contre des doublures (`ssh`, `wp`, `lighthouse`) placées dans le `PATH`.
3. **Série D (5 tests PHP 8.2)** : `staging-cache.php` exécuté par **PHP 8.2 réel** (`@php-wasm/node`) ;
   seul le transport `get_http()` est simulé.
4. **Série E (6 tests Node ESM)** : `theme/partikulier/tests/audit-uat-login.mjs` de `4f14acd9` exécuté
   **tel quel** via un hook ESM Node interceptant `'playwright'` ; les callbacks `page.evaluate()` et les
   assertions de `audit-uat-login.mjs` s'exécutent réellement dans `vm` sur des scénarios DOM/URL/cookie.

## A. Garde de traçabilité — liste blanche exacte (4)

| Scénario | Attendu | Résultat |
|---|---|---|
| A1 — fichiers dans `ALLOWED` seuls | accepté | **PASS** |
| A2 — `deploy.yml` diffère aussi | refusé, fichier nommé | **PASS** |
| A3 — un autre test (`audit-uat.mjs`) diffère | refusé, fichier nommé | **PASS** |
| A4 — `theme/partikulier/functions.php` diffère | refusé, fichier nommé | **PASS** |

## B. Lighthouse — tentatives réellement exécutées (5)

| Scénario | Attendu | Résultat |
|---|---|---|
| B1 — succès direct | 1 tentative, `lh_failed=0` | **PASS** |
| B2 — échec transitoire puis succès | 2 tentatives, `lh_failed=0` | **PASS** |
| B3 — échec persistant | « après **2** tentative(s) réellement exécutée(s) », `lh_failed=1` | **PASS** |
| B3b — jamais « tentative 3/2 » ni « après 3 » | absent | **PASS** |
| B4 — erreur de configuration (`does not match formFactor`) | 1 seule tentative, pas de rejeu | **PASS** |

## C. Cache du thème — entrée exacte, contenu, portée, acceptation (10)

Nom attendu : `<hote>_fr_annonces.html`, avec l'hôte lu sur le serveur via `home_url()`.
Le début du fichier doit aussi être du HTML — critère que le thème applique lui-même
(`class-cache.php`, `entry_is_poisoned`).

| Scénario | Attendu | Résultat |
|---|---|---|
| C1 — répertoire vide | `NON VÉRIFIÉ` + `PORTÉE: … NON VÉRIFIÉ` | **PASS** |
| C2 — entrée réelle (page HTML, fraîche) | `CACHE-THEME: entrée valide` | **PASS** |
| C2b — fichier de 800 `x` au bon nom | **non vérifié** (pas d'en-tête HTML) | **PASS** |
| C3 — même nom mais autre hôte (`default_fr_annonces.html`) | refusée, « entrées proches » | **PASS** |
| C4 — autre page du bon hôte | refusée | **PASS** |
| C5 — entrée tronquée (200 o) | non vérifié | **PASS** |
| C6 — entrée de 2 h | non vérifié | **PASS** |
| C7 — SSH absent | non vérifié (hôte ou racine illisible) | **PASS** |
| C8 — `PK_THEME_CACHE_REQUIRED=true` + non vérifié | `php_rc=1` + `::error::` | **PASS** |
| C9 — `PK_THEME_CACHE_REQUIRED=true` + valide | `php_rc=0` | **PASS** |

## D. Contrat de cache — décisions exécutées en PHP 8.2 (5)

| Scénario | Attendu | Sortie réelle | Code | Résultat |
|---|---|---|---|---|
| `ls_hit_replay` — LiteSpeed `HIT`, en-tête thème `MISS` rejoué | `ATT` motivé | `ATT … NON PROBANT (PHP n a pas tourné)` | 0 | **PASS** |
| `ls_header_gone` — présent au 1er GET, absent au 2ᵉ | `ATT` « NON VÉRIFIÉ » | `ATT … échantillon NON VÉRIFIÉ` | 1 *(LiteSpeed)* | **PASS** |
| `ls_both_absent` — aucun en-tête LiteSpeed sur les deux réponses | `ATT` « NON VÉRIFIÉ » | `ATT … aucune des deux réponses ne porte x-litespeed-cache` | 0 | **PASS** |
| `php_two_render` — deux réponses PHP, 2ᵉ `HIT` | `OK` du thème | `OK … HIT au 2ᵉ GET` | 1 *(LiteSpeed)* | **PASS** |
| `php_two_render_bad` — deux réponses PHP, 2ᵉ `MISS` | `KO`, code 1 | `KO … MISS au 2ᵉ GET` | 1 | **PASS** |

## E. Parcours connecté et consentement — `audit-uat-login.mjs` réel (6)

Exécuté contre `theme/partikulier/tests/audit-uat-login.mjs` au commit `4f14acd9` (et vérifié par
mutations négatives : chaque régression ciblée fait échouer le test E correspondant).

| Scénario | Propriété vérifiée | Attendu | Résultat |
|---|---|---|---|
| E1 | URL de retour avec `?redirect_url=…` et `#…` (`pathname` `/fr/mes-annonces/`) | préfixe `/mes-annonces` accepté, 4 parcours `PASS`, exit 0 | **PASS** |
| E2 | URL sans paramètre (`/mes-annonces`) | préfixe `/mes-annonces` accepté, 4 parcours `PASS`, exit 0 | **PASS** |
| E3 | `.pk-dashboard` absent du DOM | parcours en `FAIL` explicite (`dashboard=false`), exit 1 | **PASS** |
| E4 | bandeau `.pk-cookie-bar` absent dans un contexte neuf | parcours en `FAIL` explicite (`Consent banner absent`), exit 1 | **PASS** |
| E5 | `.pk-dashboard-empty` présent (et contrôle négatif s'il manque) | validé si présent (exit 0), refusé si absent (exit 1) | **PASS** |
| E6 | cookie `wordpress_logged_in_*` intercepté par `Proxy` | `name` lu, `.value` **jamais** lu (`read=false`), aucune fuite | **PASS** |

## Total réel exécuté (sans arrondi)

**30 tests exécutés : 30 PASS · 0 FAIL**
- `19` tests bash (`run.sh` : A = 4, B = 5, C = 10)
- `5` tests PHP 8.2 (`run_php.mjs` : D = 5)
- `6` tests Node ESM (`run_login.mjs` : E = 6)

*(Correction documentaire : le commentaire de `README.md` indiquait précédemment « 18 tests » pour `run.sh` alors qu'il en exécute réellement 19 ; il est aligné sur le chiffre exact.)*

## Ce que ces tests simulés ne couvrent pas

- **L'UAT réelle** : aucune requête réseau, aucun compte WordPress créé, aucune commande SSH.
- **LiteSpeed et WordPress réels** : réponses simulées ; `wp_get_upload_dir()` et `home_url()` ne sont pas interrogés sur le serveur.
- **Un vrai Chromium contre UAT** : la série E simule les réponses Playwright ; de plus, dans `audit-uat-login.mjs`, si `AUTHORIZED` est faux, le statut global peut valoir `PASS` alors que les 4 parcours connectés ont été sautés (limite connue documentée, non corrigée dans `4f14acd9`).
- **Lighthouse contre un Chromium réel** et **rendu GitHub Actions** (`${{ }}`, secrets, politiques d'environnement).
