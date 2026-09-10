# Partikulier Core — cœur métier contractuel

Plugin WordPress qui porte le socle de la refonte Partikulier (CDC v1.2, lot A livré) :
données, politiques et REST. Le thème [partikulier](../partikulier) lui délègue
l'espace de noms `partikulier/v1` et le pont des leads.

> [![CI](https://github.com/OWNER/REPO/actions/workflows/ci.yml/badge.svg)](https://github.com/OWNER/REPO/actions/workflows/ci.yml)
> **À personnaliser après le push :** remplacer `OWNER/REPO` par le dépôt réel.

## Ce que fait le CI à chaque push (`.github/workflows/ci.yml`)

| Job | Contenu | Limite assumée |
|---|---|---|
| `lint-php` (matrice 8.1→8.3) | `php -l` sur tous les fichiers | — |
| `routes-static` | **Contrat statique INTEG-3** : zéro `register_rest_route()` hors registre côté plugin, parité du mu-plugin sur les motifs exacts `RouteRegistry::FAST_PATH_PATTERNS`, cohérence namespace/préfixe | Contrôles côté thème `S-ROUTE-004b/005b` marqués SKIPPED (dépôt plugin isolé) — couverts par le rejeu local et `routes-collision.php` |
| `coherence` | en-tête = constante de version ; `Schema::VERSION` figée (2.0.0) tant qu'aucun lot ne touche la base ; structure obligatoire ; zéro artefact committé | — |
| `package` | zip reproductible (`B-INSTALLER-PLUGIN-partikulier-core-<version>.zip`, racine `partikulier-core/`, sans `.github`) + sha256 | — |

## Les contrats qui exigent un WordPress vivant (hors runner GitHub)

Toutes les suites de `tests/` s'exécutent sur l'hôte WordPress lui-même :

```bash
# Contrat complet du registre (inventaire 16 routes, refus de collision, parité) :
PK_WP_DIR=<racine WordPress> php partikulier-core/tests/routes-collision.php

# Autres suites du lot A (mêmes conventions d'invocation) :
#   core-contract.php, services-contract.php, sync-contract.php,
#   leads-contract.php, rest-lite-scope.php, load-contract.php
```

`tests/routes-static.php` est la seule suite transportable telle quelle sur un
runner sans WordPress (mode plugin seul) ; en mode complet sur le banc :

```bash
PK_PLUGIN_DIR=<plugin> PK_THEME_DIR=<thème> php partikulier-core/tests/routes-static.php
```

## Installation

1. Envoyer `B-INSTALLER-PLUGIN-partikulier-core-2.0.1.zip` via *Extensions → Ajouter → Téléverser* (ou décompresser dans `wp-content/plugins/`).
2. Activer. La migration 2.0 (reconstruction journalisée de `pk_listings`) se déclenche à l'activation si la version de schéma est antérieure.
3. Vérifier : `GET /wp-json/partikulier/v1/health` → `"status": "ok"`, `integrite` et `domaines` verts.

## Versions

- **2.0.1** — fermeture CI du lot A : contrat statique `tests/routes-static.php` (extraction sans WordPress de ROUTE-004/005), workflow GitHub (lint 8.1→8.3, contrat statique, cohérence, packaging), README de dépôt. **Zéro changement runtime** : ni route, ni schéma (2.0.0 figé), ni réponse.
- **2.0.0** — lot A : registre unique des routes (INTEG-3), synchroniseur d'annonces (INTEG-1), pont leads (INTEG-2), santé 2.0, migration 2.0.
