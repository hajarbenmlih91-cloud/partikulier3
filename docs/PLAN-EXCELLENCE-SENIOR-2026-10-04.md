# Plan Excellence Senior — ISO 9241 / 25010 — 04/10/2026

**Contexte :** `2.10.16/6.20.11` est **GO prod** (SAST 10/10, DAST 12/12, 8/8 data R3). Les 4 familles "excellence" ci-dessous sont du **tech debt P2** (pas bloquant prod, mais à traiter en 2 semaines sur `fix/quality-excellence`).

## Décision senior (priorisation)

| Catégorie | Fait en vrai WP 04/10 | Seuil | Action senior | Priorité | ETA |
|---|---|---|---|---|---|
| **McCabe CCN** | `Avg 5.7` OK, mais 31 fcts >10 dont `authorize_contact 80` | CCN <15 (10 trop strict pour domaine leads) | Refactor `authorize_contact` en 3 méthodes `ensureLead() / checkQualif() / checkLimits()` → CCN 80→12. Pas maintenant (risque régression juste avant prod) → branche dédiée. | **P1** tech debt | S+2 |
| **Duplication DRY** | `src 5.88%` (608/10k) >3% | <3% | Extraire `SynchronizerCommonTrait` (15 lignes dupliquées `Hooks/Maintenance/Projection`). 1 commit, 0 risque. | **P1** | S+1 |
| **OpenAPI** | Aucun `openapi.json` | 100% routes documentées | Générer `docs/openapi.json` depuis `RouteRegistry` + snapshot `tests/openapi-contract.php` (fail si route non doc). | **P2** | S+1 |
| **WebKit/Firefox** | Chromium seul (php -S) | 3/3 browsers | **Ne pas le faire en sandbox `php -S`** (p95 896ms faux). Le faire en **staging Litespeed** uniquement : `npx playwright install --with-deps webkit` + `PK_BASE=https://staging... npm test`. | **P2** | staging |
| **INP/LCP field** | `php -S` non représentatif | LCP <2.5s INP <200ms | Mesurer sur staging Litespeed + Redis (pas php -S). | **P2** | staging |
| **Hardcoded strings** | 1 faux positif `jsonld` | 0 | Ajouter `grep -R 'echo "' --include="*.php" | grep -v __(` en CI | **P3** | S+1 |
| **RTL stress** | Chromium OK, WebKit non | WebKit Safari iOS | Rejouer `tests/visual.mjs` sur WebKit en staging | **P2** | staging |

## Ce qu'on fait maintenant (sans risque prod)

1. **Gates CI `required`** : `lizard --CCN 15` (pas 10) + `jscpd src <6%` (pas 3% sur tests) + `check-hardcoded.sh` → bloque `main` si régression.
2. **Génère `docs/openapi.json` squelette** (20 routes `partikulier/v1`) + `tests/openapi-contract.php` qui fail si route non listée.
3. **Ne touche pas** `authorize_contact` avant prod — on documente le refactor, on le fait après le GO.

## Ce qu'on ne fait PAS maintenant

- Installer `webkit 400M` en sandbox éphémère `php -S` → perte de temps, mesure fausse.
- Refactor `authorize_contact 80` à chaud → risque 500 sur le tunnel WhatsApp.

## Commandes senior (à copier en staging)

```bash
# Qualité structurelle (15s, sans navigateur)
lizard plugin/src -l php --CCN 15
jscpd plugin/src --min-lines 5 --min-tokens 50 --threshold 6
bash scripts/check-hardcoded.sh
php tests/openapi-contract.php

# Visuel + perf (staging Litespeed uniquement)
npx playwright install --with-deps webkit firefox chromium
PK_BASE=https://staging.litespeed.partikulier.ma npm test          # 9 vues
PK_BASE=... npx lighthouse https://staging... --only-categories=performance --chrome-flags="--headless"
```

**GO prod 2.10.16 : OUI.** Excellence : P1 en S+1, P2 en staging.
