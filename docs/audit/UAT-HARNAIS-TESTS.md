# Tests du harnais d'audit UAT — correctif v4

Date : 2026-11-10. Objet : vérifier `docs/audit/fix-harness-audit-uat-c9e1413-v4.patch`
**après application sur une copie propre de `c9e1413`**.

Le code des tests est versionné dans `docs/audit/harnais-tests/` et rejouable :
`gen.py`, `run.sh`, `gen_php.py`, `run_php.mjs` (voir son `README.md`).

## Méthode

1. `git clone` d'une copie neuve, `git checkout c9e1413`, `git apply` du correctif.
2. Les blocs bash sont **extraits du YAML produit par le patch** (jamais recopiés), puis
   exécutés contre des doublures (`ssh`, `wp`, `lighthouse`) placées dans le `PATH`.
3. `staging-cache.php` est exécuté par **PHP 8.2 réel** (`@php-wasm/node`) ; seul le
   transport `get_http()` est simulé.

## A. Garde de traçabilité — liste blanche exacte (4)

| Scénario | Attendu | Résultat |
|---|---|---|
| A1 — seuls `audit-uat.yml` et `staging-cache.php` diffèrent | accepté | **PASS** |
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

## C. Cache du thème — entrée exacte, portée, acceptation (9)

Nom attendu : `<hote>_fr_annonces.html`, avec l'hôte lu sur le serveur via `home_url()`.

| Scénario | Attendu | Résultat |
|---|---|---|
| C1 — répertoire vide | `NON VÉRIFIÉ` + `PORTÉE: … NON VÉRIFIÉ` | **PASS** |
| C2 — entrée exacte de l'hôte UAT, 800 o | `CACHE-THEME: vérifié` + portée élargie | **PASS** |
| C3 — **même nom mais autre hôte** (`default_fr_annonces.html`) | **refusée**, signalée « entrées proches » | **PASS** |
| C4 — autre page du bon hôte | refusée | **PASS** |
| C5 — entrée tronquée (200 o) | non vérifié | **PASS** |
| C6 — entrée de 2 h | non vérifié | **PASS** |
| C7 — SSH absent | non vérifié (hôte ou racine illisible) | **PASS** |
| C8 — `PK_THEME_CACHE_REQUIRED=true` + non vérifié | `php_rc=1` + `::error::` | **PASS** |
| C9 — `PK_THEME_CACHE_REQUIRED=true` + vérifié | `php_rc=0` | **PASS** |

C3 corrige le défaut v3 (motif `*_fr_annonces.html`, donc n'importe quel hôte).

## D. Contrat de cache — décisions exécutées en PHP 8.2 (4)

| Scénario | Attendu | Sortie réelle | Code | Résultat |
|---|---|---|---|---|
| `ls_hit_replay` — LiteSpeed `HIT`, en-tête thème `MISS` rejoué | `ATT` motivé | `ATT cache thème : … NON PROBANT (PHP n a pas tourné)` | 0 | **PASS** |
| `ls_header_gone` — en-tête présent au 1er GET, absent au 2ᵉ | `ATT` « échantillon NON VÉRIFIÉ » | `ATT cache thème : échantillon NON VÉRIFIÉ …` | 1 *(contrôle LiteSpeed)* | **PASS** |
| `php_two_render` — deux réponses PHP, 2ᵉ `HIT` | `OK` | `OK cache thème : … HIT au 2ᵉ GET` | 0 | **PASS** |
| `php_two_render_bad` — deux réponses PHP, 2ᵉ `MISS` | `KO`, code 1 | `KO cache thème : … MISS au 2ᵉ GET` | 1 | **PASS** |

`ls_header_gone` corrige le défaut v3 : un en-tête LiteSpeed absent au 2ᵉ GET n'est plus
interprété comme « PHP a rendu la page ». Le code 1 vient du contrôle LiteSpeed lui-même
(en-tête disparu), pas du cache du thème, qui est correctement déclaré non vérifié.

`php_two_render_bad` montre que le correctif **ne rend pas le test complaisant** : une
absence réelle de réchauffement, observée sur une réponse rendue par PHP, reste un KO.

## Total

**22 tests, 22 PASS, 0 FAIL**, exécutés sur la copie patchée de `c9e1413`
(18 en bash, 4 en PHP 8.2).

## Ce que ces tests ne couvrent pas

- **L'UAT réelle** : aucune requête réseau, aucun compte créé, aucune commande SSH.
- **LiteSpeed et WordPress réels** : réponses simulées ; `wp_get_upload_dir()` et
  `home_url()` ne sont pas interrogés sur le serveur.
- **Lighthouse contre un Chromium** : seule la logique de tentatives est testée. Le
  correctif `--preset=desktop` s'appuie sur le message d'erreur de
  `core/config/validation.js`, ligne 181, dans Lighthouse 12.8.2.
- **Le rendu GitHub Actions** : expressions `${{ }}`, résolution des `secrets`, politique
  de l'environnement. Validables seulement par un run réel, donc après création de
  l'environnement d'audit.
