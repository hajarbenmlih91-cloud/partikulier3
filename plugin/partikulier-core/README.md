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

- **2.10.6** — (SE-022, lot 6 du train 2 — CDC v4.1 §8B) : idempotence par cycle de requête des gardes REST à effet de bord — constat de l'analyse dynamique du train 1 : `rest_send_allow_header()` (hook core sur `rest_post_dispatch`) ré-exécute le permission_callback pour l'en-tête Allow, chaque échec d'authentification était compté et audité deux fois (429 à la 6e requête au lieu de la 11e, transition 100/h → 50/h effectifs). Correctif : module `src/Rest/RequestCycle.php` (WeakMap par objet requête — marqueur d'effet + cache de verdict, restitué à l'identique sur la passe Allow) ; garde `/erase-lead` : compteur/audits une seule fois par cycle, verdict restitué ; garde HMAC : `audit_failure` (mode log) une fois par cycle. Nouveaux verrous : contrat `tests/se022-idempotence-contract.php` (9 assertions : restitution, franchissement de seuil, rejeu même instance, HMAC log/enforce, balayage statique des 18 déclarations avec classification obligatoire des gardes) + batterie `tests/se022-http-battery.php` en HTTP réel sur serveur démarré (E-2202 : 10 requêtes servies 401, 11e → 429, compteur +1/requête, audit unique). Packaging : contrôle E-2203 (toute entrée SHA256SUMS sans fichier bloque le build). Oracle 277/277. Zéro changement de schéma (2.6.0 figé).
- **2.10.5** — (SE-020, campagne post-audit — P1-6) : garde du mu-plugin REST léger — verbe HTTP assaini (`sanitize_key` + repli GET conforme RFC 3875, comparaison en minuscules) ; ruleset PHPCS du monorepo (`phpcs.xml.dist` à la racine : périmètre runtime, exclusion justifiée E-2007 des lectures `$_GET` publiques, sévérité ERROR `$_POST` active) ; contrat `tests/options-sanitizer-contract.php` (OS-001→008 : sanitiseur d'options du thème par liste blanche, verbe assaini, redirect same-site, wp_safe_redirect, JSON-LD hex-échappé). Oracle 264/264. Zéro changement de schéma (2.6.0 figé), zéro changement de réponse REST.
- **2.10.5** — BREAKING (SE-016, campagne post-audit) : authentification de la route `POST /erase-lead` — preuve de possession du secret dédié `lead_erase_api_secret` (en-tête `X-Partikulier-Lead-Erase` ou `Authorization: Bearer`), fenêtre de transition acceptant le secret n8n (usages journalisés comme dépréciés), limiteur d'échecs 10/heure/IP (429, relèvement 100/heure pendant la transition), audits `lead_erase_authorized` / `lead_erase_auth_failed` / `lead_erase_flood` / `lead_erase_secret_deprecated` / `lead_erase_transition_relaxed`. Nouveaux verrous : contrat `tests/lead-erase-security-contract.php` (8 assertions), ROUTE-006 (inventaire dynamique : zéro route d'écriture sans garde), S-ROUTE-007a/b (règle statique : toute déclaration porte un permission_callback explicite). Oracle 249/249 (+ artifact-hygiene-contract AH-001→003 : screenshot 1200×900 ≤300 Ko, licence unique GPLv3+, gardes de packaging). Note de migration : `docs/NOTE-MIGRATION-ERASE-LEAD.md`. Aucun changement de schéma (2.6.0 figé). Le zip du plugin n'embarque plus `tests/` (exclusion E-1702, contrôle CI « artefact propre »).

- **2.0.1** — fermeture CI du lot A : contrat statique `tests/routes-static.php` (extraction sans WordPress de ROUTE-004/005), workflow GitHub (lint 8.1→8.3, contrat statique, cohérence, packaging), README de dépôt. **Zéro changement runtime** : ni route, ni schéma (2.0.0 figé), ni réponse.
- **2.0.0** — lot A : registre unique des routes (INTEG-3), synchroniseur d'annonces (INTEG-1), pont leads (INTEG-2), santé 2.0, migration 2.0.
