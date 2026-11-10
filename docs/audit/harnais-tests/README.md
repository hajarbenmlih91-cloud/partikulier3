# Harnais de test du correctif d'audit UAT

Tests d'intégration du patch `fix-harness-audit-uat-c9e1413-v4.2.patch`, exécutables
indépendamment, sans UAT, sans SSH et sans réseau.

## Principe

Les blocs bash ne sont **pas recopiés** : ils sont **extraits du workflow** par `gen.py`,
puis exécutés contre des doublures (`ssh`, `wp`, `lighthouse`) placées dans le `PATH`.
Le contrat de cache est exécuté par un **vrai PHP 8.2** (runtime WebAssembly) ; seul le
transport HTTP y est simulé.

## Exécution

```bash
# 1. copie de référence + correctif
git clone <dépôt> /tmp/audit-check && cd /tmp/audit-check
git checkout c9e1413
git apply /chemin/vers/fix-harness-audit-uat-c9e1413-v4.2.patch

# 2. séries A, B, C (bash)
cd /chemin/vers/harnais-tests
python3 gen.py /tmp/audit-check/.github/workflows/audit-uat.yml .
bash run.sh          # 18 tests

# 3. série D (PHP 8.2 en WebAssembly)
npm install @php-wasm/node
python3 gen_php.py /tmp/audit-check/theme/partikulier/tests/staging-cache.php .
node run_php.mjs .   # 5 tests
```

`run.sh` et `run_php.mjs` travaillent dans leur propre répertoire et y créent leurs
doublures : aucun effet hors de ce répertoire.

## Séries

| Série | Fichier | Objet |
|---|---|---|
| A (4) | `run.sh` | garde de traçabilité : liste blanche de deux chemins, tout autre écart refusé |
| B (5) | `run.sh` | Lighthouse : tentatives réellement exécutées, erreur de configuration non rejouée |
| C (9) | `run.sh` | cache du thème : entrée **exacte** de l'hôte UAT, portée explicite, contrôle d'acceptation |
| D (5) | `run_php.mjs` | contrat de cache : en-tête rejoué, en-tête ambigu, en-têtes absents, PHP rendu avec et sans réchauffement |

## Adaptations du fichier testé (série D)

`gen_php.py` modifie trois choses dans la copie de `staging-cache.php`, et rien d'autre :

1. `$argv` est fixé — le runtime WebAssembly n'a pas de ligne de commande ;
2. la garde `PHP_SAPI !== 'cli'` est neutralisée — exécution in-process ;
3. `get_http()` est remplacé par un stub scénarisé — transport simulé.

## Ce que ce harnais ne couvre pas

L'UAT réelle, LiteSpeed et WordPress réels, `wp_get_upload_dir()` et `home_url()` sur le
serveur, Lighthouse contre un Chromium, et le rendu GitHub Actions (expressions, secrets,
politique d'environnement). Ces points exigent un run réel.
