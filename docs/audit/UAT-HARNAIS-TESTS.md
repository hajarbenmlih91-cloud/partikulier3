# Tests du harnais d'audit UAT — correctif v4.2

Date : 2026-11-10. Objet : vérifier `docs/audit/fix-harness-audit-uat-c9e1413-v4.2.patch`
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

## C. Cache du thème — entrée exacte, contenu, portée, acceptation (10)

Nom attendu : `<hote>_fr_annonces.html`, avec l'hôte lu sur le serveur via `home_url()`.
Le début du fichier doit aussi être du HTML — critère que le thème applique lui-même
(`class-cache.php`, `entry_is_poisoned`).

| Scénario | Attendu | Résultat |
|---|---|---|
| C1 — répertoire vide | `NON VÉRIFIÉ` + `PORTÉE: … NON VÉRIFIÉ` | **PASS** |
| C2 — entrée réelle (page HTML, fraîche) | `CACHE-THEME: entrée valide` | **PASS** |
| C2b — **fichier de 800 `x`** au bon nom | **non vérifié** (pas d'en-tête HTML) | **PASS** |
| C3 — **même nom mais autre hôte** (`default_fr_annonces.html`) | refusée, « entrées proches » | **PASS** |
| C4 — autre page du bon hôte | refusée | **PASS** |
| C5 — entrée tronquée (200 o) | non vérifié | **PASS** |
| C6 — entrée de 2 h | non vérifié | **PASS** |
| C7 — SSH absent | non vérifié (hôte ou racine illisible) | **PASS** |
| C8 — `PK_THEME_CACHE_REQUIRED=true` + non vérifié | `php_rc=1` + `::error::` | **PASS** |
| C9 — `PK_THEME_CACHE_REQUIRED=true` + valide | `php_rc=0` | **PASS** |

C2b corrige le défaut relevé en relecture : un fichier de 800 caractères `x` satisfaisait
la v4 au seul motif de son nom, de sa taille et de sa date. Nom, taille et fraîcheur ne
prouvent qu'une **présence** ; le début de page HTML est désormais exigé.

## D. Contrat de cache — décisions exécutées en PHP 8.2 (5)

| Scénario | Attendu | Sortie réelle | Code | Résultat |
|---|---|---|---|---|
| `ls_hit_replay` — LiteSpeed `HIT`, en-tête thème `MISS` rejoué | `ATT` motivé | `ATT … NON PROBANT (PHP n a pas tourné)` | 0 | **PASS** |
| `ls_header_gone` — présent au 1er GET, absent au 2ᵉ | `ATT` « NON VÉRIFIÉ » | `ATT … échantillon NON VÉRIFIÉ` | 1 *(LiteSpeed)* | **PASS** |
| `ls_both_absent` — **aucun** en-tête LiteSpeed sur les deux réponses | `ATT` « NON VÉRIFIÉ » | `ATT … aucune des deux réponses ne porte x-litespeed-cache` | 0 | **PASS** |
| `php_two_render` — deux réponses PHP, 2ᵉ `HIT` | `OK` du thème | `OK … HIT au 2ᵉ GET` | 1 *(LiteSpeed)* | **PASS** |
| `php_two_render_bad` — deux réponses PHP, 2ᵉ `MISS` | `KO`, code 1 | `KO … MISS au 2ᵉ GET` | 1 | **PASS** |

Deux lignes portent le code 1 pour une raison qui **n'est pas le cache du thème** : quand
PHP rend les deux réponses, le contrôle LiteSpeed échoue à juste titre (aucun `HIT`
externe). Le contrat du thème, lui, conclut correctement — c'est ce que la colonne
« sortie réelle » vérifie.

`ls_both_absent` corrige le défaut relevé en relecture : l'absence des **deux** en-têtes
LiteSpeed n'est pas une preuve que PHP a servi la page (un proxy peut les retirer, ou
servir une réponse en cache). Le contrat ne conclut plus dans ce cas, et ne fait donc plus
échouer le contrôle sur l'en-tête du thème.

## Total

**24 tests, 24 PASS, 0 FAIL**, exécutés sur la copie patchée de `c9e1413`
(19 en bash, 5 en PHP 8.2).

## Ce que la sonde prouve — et ce qu'elle ne prouve pas

| Prouvé par la sonde | Non prouvé |
|---|---|
| l'entrée de cette page, pour cet hôte, existe | que la page soit réellement **servie** depuis ce cache |
| elle est fraîche (< 30 min) et fait plus de 500 o | que son contenu soit complet (seul le début est lu) |
| elle commence par `<!doctype html` ou `<html` | que la purge du thème purge correctement LiteSpeed |

Le rapport l'écrit désormais lui-même : même en cas de succès, la ligne `PORTÉE:` précise
que « le SERVICE de cette page par le cache n'est pas prouvé pour autant ». Tant que
`PK_THEME_CACHE_REQUIRED` reste à `'false'`, la portée annoncée est : **LiteSpeed et TTFB
validés, cache fichier du thème non validé**. Passer à `'true'` demanderait d'abord une
preuve de service, qui reste à construire.

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
