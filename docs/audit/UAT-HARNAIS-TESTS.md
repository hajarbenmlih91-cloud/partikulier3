# Tests du harnais d'audit UAT — correctif v3

Date : 2026-11-10. Objet : vérifier `docs/audit/fix-harness-audit-uat-c9e1413-v3.patch`
**après application sur une copie propre de `c9e1413`**, et non seulement sa syntaxe.

## Méthode

1. `git clone` d'une copie neuve, `git checkout c9e1413`, `git apply` du correctif.
2. Les blocs bash sont **extraits du fichier YAML produit par le patch** (pas recopiés),
   puis exécutés contre des doublures (`ssh`, `wp`, `lighthouse`) placées dans le `PATH`.
3. `staging-cache.php` est exécuté par **PHP 8.2 réel** (runtime WebAssembly `@php-wasm/node`) ;
   seul le transport `get_http()` est remplacé par un stub scénarisé, pour simuler les
   réponses de LiteSpeed et de PHP.

## A. Garde de traçabilité — liste blanche exacte

| Scénario | Attendu | Résultat |
|---|---|---|
| A1 — seuls `audit-uat.yml` et `staging-cache.php` diffèrent | accepté | **PASS** |
| A2 — `deploy.yml` diffère aussi | refusé, fichier nommé | **PASS** |
| A3 — un autre test (`audit-uat.mjs`) diffère | refusé, fichier nommé | **PASS** |
| A4 — `theme/partikulier/functions.php` diffère | refusé, fichier nommé | **PASS** |

## B. Lighthouse — tentatives réellement exécutées

| Scénario | Attendu | Résultat |
|---|---|---|
| B1 — succès direct | 1 tentative, `lh_failed=0` | **PASS** |
| B2 — échec transitoire puis succès | 2 tentatives, `lh_failed=0` | **PASS** |
| B3 — échec persistant | « après **2** tentative(s) réellement exécutée(s) », `lh_failed=1` | **PASS** |
| B3b — jamais « tentative 3/2 » ni « après 3 » | absent | **PASS** |
| B4 — erreur de configuration (`does not match formFactor`) | 1 seule tentative, pas de rejeu, `lh_failed=1` | **PASS** |

## C. Sonde serveur du cache du thème

Entrée attendue : `<hote>_fr_annonces.html` — et **rien d'autre**.

| Scénario | Attendu | Résultat |
|---|---|---|
| C1 — répertoire vide | `NON VÉRIFIÉ` explicite | **PASS** |
| C2 — entrée exacte fraîche de 800 o | `CACHE-THEME: vérifié` | **PASS** |
| C3 — autre page fraîche (`default_fr_home.html`) | **refusée**, non vérifié | **PASS** |
| C4 — entrée tronquée (200 o) | non vérifié | **PASS** |
| C5 — entrée de 2 h | non vérifié | **PASS** |
| C6 — SSH absent | non vérifié (racine illisible) | **PASS** |

C3 est le défaut relevé en v2 : le `find` acceptait n'importe quel `.html` frais. Il est
désormais refusé.

## D. Contrat de cache — décisions exécutées en PHP 8.2

| Scénario | Verdict attendu | Sortie réelle | Code | Résultat |
|---|---|---|---|---|
| `ls_hit_replay` — LiteSpeed `HIT`, en-tête thème `MISS` rejoué | `ATT` motivé, pas de KO | `ATT cache thème : … NON PROBANT (PHP n a pas tourné)` | 0 | **PASS** |
| `php_two_render` — deux réponses rendues par PHP, 2ᵉ `HIT` | `OK` | `OK cache thème : x-partikulier-cache = HIT au 2ᵉ GET (deux réponses rendues par PHP)` | 0 | **PASS** |
| `php_two_render_bad` — deux réponses PHP, 2ᵉ `MISS` | `KO`, code 1 | `KO cache thème : x-partikulier-cache = MISS au 2ᵉ GET (deux réponses rendues par PHP)` | 1 | **PASS** |

Le scénario `php_two_render_bad` prouve que le correctif **ne rend pas le test complaisant** :
une vraie absence de réchauffement, observée sur une réponse rendue par PHP, reste un KO.

## Total

**18 tests, 18 PASS, 0 FAIL** — exécutés sur la copie patchée de `c9e1413`.

## Ce que ces tests ne couvrent pas

- **L'UAT réelle** : aucune requête réseau, aucun compte créé, aucune commande SSH.
- **LiteSpeed et WordPress réels** : les réponses HTTP sont simulées ; `wp_get_upload_dir()`
  n'est pas interrogé sur le serveur.
- **Lighthouse contre un Chromium** : seule la logique de tentatives est testée, pas
  l'exécution de Lighthouse (le correctif `--preset=desktop` s'appuie sur le message
  d'erreur de `core/config/validation.js`, ligne 181, dans la version 12.8.2).
- **Le rendu GitHub Actions** : expressions `${{ }}`, résolution des `secrets`, politique
  de l'environnement. Ces éléments ne peuvent être validés que par un run réel, donc
  après création de l'environnement d'audit.

## Reproductibilité

Les scripts de test sont jetables (hors dépôt) : ils s'exécutent dans un répertoire de
travail temporaire, extraient les blocs du YAML patché et génèrent leurs doublures.
Étapes : cloner, `git checkout c9e1413`, `git apply` du correctif, extraire les blocs
`run:` des trois étapes concernées, exécuter avec `bash`, puis exécuter
`staging-cache.php` sous `@php-wasm/node` avec `get_http()` remplacé par le stub scénarisé.
