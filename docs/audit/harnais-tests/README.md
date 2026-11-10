# Harnais de test du correctif d'audit UAT

Tests d'intégration du harnais d'audit UAT (`4f14acd9`, dérivé de `5aa70f44` / base `c9e1413`), exécutables
indépendamment, sans UAT, sans SSH et sans réseau.

> **Règle de placement :** ce répertoire vit uniquement sur une branche documentaire dédiée, **jamais**
> dans un ref/tag d'audit (`audit-*`), afin que la garde `ALLOWED` reste strictement limitée aux fichiers du harnais.

## Principe

- **Séries A, B, C** : les blocs bash ne sont **pas recopiés** — ils sont **extraits du workflow** par `gen.py`,
  puis exécutés contre des doublures (`ssh`, `wp`, `lighthouse`) placées dans le `PATH`.
- **Série D** : le contrat de cache (`staging-cache.php`) est exécuté par un **vrai PHP 8.2** (runtime WebAssembly) ;
  seul le transport HTTP y est simulé.
- **Série E** : le parcours connecté réel (`theme/partikulier/tests/audit-uat-login.mjs`) est exécuté tel quel
  en Node via un hook ESM (`node:module` `register`) remplaçant `'playwright'` par un navigateur simulé scénarisé ;
  `page.evaluate()` exécute réellement les sélecteurs et assertions du script.

## Exécution

```bash
# 1. copie de référence au commit audité (ex. 4f14acd9)
git archive 4f14acd9b56c93a798e06ca5f23cabdb185b6674 | tar -x -C /tmp/audit-check

# 2. séries A, B, C (bash — 19 tests)
cd /chemin/vers/harnais-tests
python3 gen.py /tmp/audit-check/.github/workflows/audit-uat.yml .
bash run.sh          # 19 tests : A (4) + B (5) + C (10)

# 3. série D (PHP 8.2 en WebAssembly — 5 tests)
npm install @php-wasm/node
python3 gen_php.py /tmp/audit-check/theme/partikulier/tests/staging-cache.php .
node run_php.mjs .   # 5 tests

# 4. série E (Node ESM sur audit-uat-login.mjs réel — 6 tests)
node run_login.mjs /tmp/audit-check/theme/partikulier/tests/audit-uat-login.mjs   # 6 tests
```

`run.sh`, `run_php.mjs` et `run_login.mjs` travaillent dans des répertoires temporaires ou locaux isolés :
aucun effet sur le dépôt ni sur l'UAT.

## Séries (total : 30 tests réels)

| Série | Exécutant | Nb réel | Objet |
|---|---|---:|---|
| A | `run.sh` | 4 | garde de traçabilité : liste blanche exacte, tout fichier hors liste (`deploy.yml`, autre test, `functions.php`) refusé |
| B | `run.sh` | 5 | Lighthouse : tentatives réellement exécutées, erreur de configuration non rejouée |
| C | `run.sh` | 10 | cache du thème : entrée **exacte** de l'hôte UAT, début HTML, portée explicite, contrôle d'acceptation |
| D | `run_php.mjs` | 5 | contrat de cache : en-tête rejoué, en-tête ambigu, en-têtes absents, PHP rendu avec et sans réchauffement |
| E | `run_login.mjs` | 6 | parcours connecté (`audit-uat-login.mjs`) : URL `?redirect_url`, URL sans paramètre, absence `.pk-dashboard`, absence bandeau, `.pk-dashboard-empty`, cookie nommé sans lecture de sa valeur |

## Adaptations des fichiers testés

- **Série D (`gen_php.py`)** modifie trois choses dans la copie de `staging-cache.php`, et rien d'autre :
  1. `$argv` est fixé (le runtime WebAssembly n'a pas de ligne de commande) ;
  2. la garde `PHP_SAPI !== 'cli'` est neutralisée (exécution in-process) ;
  3. `get_http()` est remplacé par un stub scénarisé (transport simulé).
- **Série E (`run_login.mjs`)** ne modifie **rien** dans `audit-uat-login.mjs` : seul l'import `'playwright'` est
  intercepté en mémoire par `playwright_mock_register.mjs` / `playwright_mock_hooks.mjs` vers `playwright_mock.mjs`.

## Ce que ce harnais ne couvre pas

L'UAT réelle, LiteSpeed et WordPress réels, `wp_get_upload_dir()` et `home_url()` sur le serveur,
un vrai Chromium contre la page WordPress UAT (y compris le cas `AUTHORIZED=false`), Lighthouse contre
un Chromium réel, et le rendu GitHub Actions (expressions, secrets, politique d'environnement).
Ces points exigent un run réel.
