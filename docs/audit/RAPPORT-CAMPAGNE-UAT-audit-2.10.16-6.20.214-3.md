# Rapport de campagne UAT — `audit-2.10.16-6.20.214-3` (Run de diagnostic `42886286352`)

**Date :** 2026-11-10  
**Cible :** environnement UAT uniquement (`https://darkcyan-yak-151002.hostingersite.com`) — aucune opération sur la production (`prd`).  
**Qualification du run `42886286352` :** **Run de DIAGNOSTIC** (les seuils `SEUILS-VALIDATION-UAT.md` v1.0 ayant été versionnés après la fin de ce run, celui-ci ne constitue pas un run de validation).  
**Verdict global :** **Note NON CALCULABLE — Release NON VALIDÉE** (seul échec machine du workflow : étape 13 `Charge 100 req/s` en `outcome=failure`, lu par l'étape 21 `Verdict final`).

---

## Fiche de traçabilité (Partie A)

| Élément | Valeur exacte vérifiée |
|---|---|
| Dépôt | `hajarbenmlih91-cloud/partikulier3` |
| Base applicative inchangée | `c9e141376179f1a47e567361c28e747b6e891cd0` (`feat/profil-acheteur`) |
| Commit audité (non modifié) | `4f14acd9b56c93a798e06ca5f23cabdb185b6674` (parent `5aa70f44…`) |
| Fichiers touchés vs `c9e1413` | `3` fichiers `ALLOWED` uniquement : `.github/workflows/audit-uat.yml`, `theme/partikulier/tests/audit-uat-login.mjs`, `theme/partikulier/tests/staging-cache.php` (**aucun `docs/`**) |
| SHA-256 de `git diff c9e1413 4f14acd9` | `3e5f98e8b7551efa1f7f2509a42a3191c7f53ab618b0c6aa1ea0940e24cb79e4` |
| Tag annoté poussé seul | `audit-2.10.16-6.20.214-3` (objet tag `1f9e41d5d1e8fe7225e12bb7f68c2adfb12d3f09`) |
| Politique environnement `uat` (`23156387495`) | `audit-2.10.16-6.20.214-*` (`66232029`) + `pre-release-*` (`61839753`) ; approbation propriétaire uniquement, `prd` et rulesets intacts |
| Commande de dispatch | `gh workflow run audit-uat.yml --ref audit-2.10.16-6.20.214-3 -f load_test=true` |
| Run GitHub Actions | `42886286352` (job `130075359635`, `2026-11-10T19:32:27Z` → `19:37:24Z`) |
| Artefact | `audit-uat-17` (ID `13795619235`, `35 242 945` octets décompressés, `35` fichiers) |
| Branche documentaire Partie B (hors tag) | `audit/harnais-serie-e-seuils` (`SEUILS-VALIDATION-UAT.md`, `harnais-tests/` séries A–E, `sonde-capacite-uat.py`, `DIAGNOSTIC-RUN-42886286352.md`) |

---

## Section 1 — Ce qui a été testé en simulation (et limites des simulations)

La **série E** a été ajoutée sur la branche dédiée `audit/harnais-serie-e-seuils` (sans modifier `4f14acd9`, ni le tag `audit-2.10.16-6.20.214-3`, ni introduire `docs/` dans `c9e1413..audit-2.10.16-6.20.214-3`), puis l'intégralité du harnais a été exécutée contre une extraction propre de `4f14acd9`.

### 1.1 Décompte exact des tests exécutés (sans arrondi)

**Total réel exécuté : `30` tests — `30 PASS · 0 FAIL`** :

| Série | Exécutant | Nb réel | Statut | Propriétés vérifiées |
|---|---|---:|---|---|
| **A** | `run.sh` (bash) | **4** | `4 PASS · 0 FAIL` | Garde de traçabilité sur `ALLOWED` (`audit-uat.yml`, `staging-cache.php`, `audit-uat-login.mjs`) ; rejet explicite si `deploy.yml`, `audit-uat.mjs` ou `functions.php` diffère |
| **B** | `run.sh` (bash) | **5** | `5 PASS · 0 FAIL` | Tentatives Lighthouse réellement exécutées (`1/2`, `2/2`, « après 2 tentative(s) », jamais « après 3 », aucun rejeu sur `--preset=desktop`) |
| **C** | `run.sh` (bash) | **10** | `10 PASS · 0 FAIL` | Sonde cache fichier : répertoire vide, entrée HTML fraîche, rejet fichier non HTML (`C2b`), rejet autre hôte (`C3`), rejet autre page (`C4`), troncature (`C5`), péremption (`C6`), SSH absent (`C7`), `PK_THEME_CACHE_REQUIRED` (`C8`/`C9`) |
| **D** | `run_php.mjs` (**PHP 8.2 réel** WASM) | **5** | `5 PASS · 0 FAIL` | Contrat `staging-cache.php` : `ls_hit_replay`, `ls_header_gone`, `ls_both_absent`, `php_two_render`, `php_two_render_bad` |
| **E** | `run_login.mjs` (**Node ESM** via `node:module` `register` sur `audit-uat-login.mjs` réel) | **6** | `6 PASS · 0 FAIL` | `E1` URL avec `?redirect_url` → préfixe `/mes-annonces` accepté ; `E2` URL sans paramètre (`/mes-annonces`) → accepté ; `E3` `.pk-dashboard` absent → échec explicite (`dashboard=false`, code 1) ; `E4` bandeau absent → échec explicite (`Consent banner absent`, code 1) ; `E5` `.pk-dashboard-empty` présent → validé (et absent → refusé, code 1) ; `E6` cookie `wordpress_logged_in_*` nommé mais `.value` jamais lu (`cookie_value_read=false` via `Proxy`, aucune fuite) |

*(Cinq mutations négatives ciblées en copie temporaire de `audit-uat-login.mjs` ont fait échouer respectivement E1/E2, E3, E4, E5 et E6. Le commentaire obsolète « 18 tests » de `harnais-tests/README.md` a été corrigé à `19 tests`.)*

### 1.2 Limites des simulations et limite connue non corrigée

1. Doublures locales `ssh`/`wp`/`lighthouse`, transport `get_http()` simulé en série D et navigateur Playwright simulé en série E (`page.evaluate` exécute toutefois réellement les sélecteurs et assertions de `audit-uat-login.mjs`).
2. **Limite connue déclarée dans `theme/partikulier/tests/audit-uat-login.mjs` (ligne 199, non corrigée dans `4f14acd9`) :** si `AUTHORIZED === false`, le script saute les 4 parcours mais émet `status: 'PASS'` dans `owner-journey.json` (`audit-uat.yml` atténue partiellement ce cas via `if: steps.seed.outcome == 'success'`).

---

## Section 2 — Ce qui a été vérifié sur l'UAT réelle (avec les chiffres mesurés)

### 2.1 SSH et cycle de vie du compte temporaire
- **SSH UAT** (`uat-initial`) : `success`.
- **Compte jetable** `pk_audit_42886286352_1` (`contributor`, `ID 15`, aucune annonce créée) créé puis supprimé (`Success: Removed user 15`), absence prouvée deux fois (`wp user get 15` échoue ; `wp user list --search=pk_audit_42886286352_1` vide).

### 2.2 Sécurité live (`security.txt`)
- **`22 OK · 0 KO · 0 à regarder · 0 sauté(s)`** (`outcome: success`, `conclusion: success`).

### 2.3 Cache et TTFB extérieur (`cache-ttfb.txt`) + en-têtes CDN (hors harnais)
- Étape 11 (`Cache/TTFB`) : `outcome: success`, `conclusion: success` — `6 OK · 0 KO · 3 à regarder`.
- `/fr/annonces/` : 1er GET HTTP `200` en `163 ms` (`24` cartes) ; 2ᵉ GET : `24` cartes ; fiche détail HTTP `200` en `148 ms` (`og:image` absolue) ; 20 GET consécutifs TTFB `p50 157 ms · p95 315 ms` (`≤ 800 ms` CDC LOT 3) ; `?pk_audit=1794339219` HTTP `200` (`TTFB 0.336807 s`).
- `cache-ttfb.txt` n'enregistre pas les en-têtes d'intermédiaire ; par requête `GET` directe hors workflow (`2026-11-10T20:08:34Z`), `darkcyan-yak-151002.hostingersite.com` résout vers `free.cdn.hstgr.net` et répond avec `Server: hcdn`, `platform: hostinger`, `panel: hpanel`, `x-hcdn-cache-status: BYPASS`, `x-hcdn-upstream-rt: 0.277`, `x-partikulier-cache: HIT` (sans `x-litespeed-cache`).

### 2.4 Charge 10 req/s, 100 req/s (`load-smoke.txt`, `load-full.txt`) et code exact des `5xx`
- **Charge 10 req/s (`load-smoke.txt`) :** `outcome: success` — `100/100` (`9.9 req/s`), `100` `2xx`, `0` `4xx`/`5xx`/erreur réseau, latence `p50 120 ms · p95 201 ms · p99 290 ms`.
- **Charge 100 req/s (`load-full.txt`) :** **`outcome: failure` (exit `4`)** — `3 000/3 000` (`99.7 req/s`), `1 599` `2xx`, **`1 401` `5xx`** (`46,7 %`), latence `p50 94 ms · p95 230 ms · p99 409 ms`.
- `load-full.txt` ne contient que les classes (`2xx`/`4xx`/`5xx`), `staging-charge.php` n'imprimant pas la ventilation par code HTTP exact.
- **Sonde hors harnais (`docs/audit/sonde-capacite-uat.py`, `2026-11-10T20:09:42Z`) :** `10 req/s` (`50/50` `200`, `0` erreur) ; `20 req/s` (`73` `200`, **`27` `HTTP 500`**) ; `50 req/s` (`207` `200`, **`43` `HTTP 500`**) ; `100 req/s` (`800` `200`, **`700` `HTTP 500`**) ; `0` `429`/`502`/`503`/`504`. Tous les `500` portent l'en-tête `nocache_headers()` et le HTML WordPress `<title>Database Error</title>` derrière `Server: hcdn`.

### 2.5 Parcours connecté, navigateur/Axe, interactions et Lighthouse
- **Parcours connecté (`owner-journey.json`) :** `54/54` checks `PASS` (`4/4` parcours `mobile`/`desktop` × `accept`/`refuse` sur `/fr/mes-annonces/`, bandeau fermé sans `force: true`, cookie nommé sans lecture de valeur, `.pk-dashboard` et `.pk-dashboard-empty` atteints).
- **Chromium/Axe (`browser-audit.json`) :** `PASS_WITH_FINDINGS`, `554` contrôles (`213` `PASS` error, `334` `PASS` warning, `7` `FAIL` warning : `6` liens WhatsApp sans destinataire sur annonces de démo, `1` `robots.txt` bloquant `Googlebot` sur UAT).
- **Interactions + Web Vitals lab (`interaction-web-vitals.md`) :** `PASS_WITH_FINDINGS; 0 échec(s), 0 avertissement(s)`, `8/8` interactions `PASS`, LCP lab `292–376 ms`, CLS `0` à `0.0001`, INP lab proxy max `32 ms`.
- **Lighthouse (`outcome: success`, `conclusion: success` à l'étape 18) :**
  - `ar-home-mobile` : Perf `97`, A11y `100`, BP `96`, SEO `100`, LCP `2 459 ms`, TBT `2 ms`
  - `en-home-mobile` : Perf `97`, A11y `100`, BP `96`, SEO `92` (`link-text`), LCP `2 455 ms`, TBT `2 ms`
  - `fr-home-desktop` : Perf `99`, A11y `97` (`color-contrast` `3.1:1`, `label-content-name-mismatch`), BP `96`, SEO `100`, LCP `779 ms`, TBT `0 ms`
  - `fr-home-mobile` : Perf `62`, A11y `100`, BP `96`, SEO `100`, LCP `3 130 ms`, TBT `2 588 ms`, main-thread `5,4 s`
  - `fr-listings-mobile` : Perf `98`, A11y `100`, BP `96`, SEO `100`, LCP `2 318 ms`, TBT `4 ms`
  - *(BP à `96` sur les 5 profils : `404` sur `/favicon.ico`.)*

---

## Section 3 — Ce qui a été sauté ou a échoué, et pour quelle cause

- **Séparation `outcome` vs `conclusion` :** l'étape 13 (`Charge 100 req/s`) possède `continue-on-error: true`, affichant `conclusion: success` dans le journal alors que son **`outcome` réel est `failure` (code `4`)** en raison des `1 401` `5xx` (identifiés hors harnais comme `HTTP 500 Database Error` WordPress/MySQL sous concurrence lorsque HCDN est en `BYPASS`). L'étape 21 (`Verdict final`) lit `FULL_LOAD_RESULT=failure` et termine le job en **`conclusion: failure` (code `1`)**.
- **Constat diagnostique sur `fr-home-mobile` (étape 18 `success`, non échec machine) :** l'analyse des JSON Lighthouse classe les suspects suivants par temps de blocage (`0` script tiers, `0` JS inutilisé, DOM `1 549` contre `1 214` sur `/en/`) :
  1. Document `/fr/` (`3 085,7 ms` dans `bootup-time`, `7` long tasks, `378,1 ms` de forced reflow à la ligne `1588:19` du script inline de consentement sur mobile uniquement) ;
  2. Tâches `Unattributable` (`1 737,3 ms`, `3` long tasks, liées à `Other` `2 529 ms` + `Style & Layout` `1 716 ms`, avec probable contention CPU ponctuelle du runner puisque `fr-listings-mobile` a `2 305` DOM pour `0,7 s` main-thread et `TBT 4 ms`) ;
  3. `assets/js/main.js?ver=1794188866` (`520,7 ms`, `1` long task `236 ms`, `18,9 ms` reflow aux lignes `:1492`, `:2063`, `:2068`) ;
  4. Images (`uses-responsive-images` `96 KiB` / `image-delivery-insight` `196 KiB` sur `pep-logo.png`, `pep-logo-white.png` et 3 `.avif`).
- **Critères d'acceptation (Étape 4) :** soumis au propriétaire sans choix unilatéral (`10–20 req/s` avec `0` `5xx` et `p95 ≤ 800 ms` vs `100 req/s` documenté comme limite de rupture connue `HTTP 500 Database Error`).
- **Aucune étape métier sautée** (`Post Installer Node, Playwright et Chromium` `skipped`) ; limite `AUTHORIZED === false` de `audit-uat-login.mjs:199` déclarée et non corrigée dans `4f14acd9`.

---

## Section 4 — Portée exacte du cache : ce qui est prouvé et ce qui ne l'est pas

- **Prouvé :** `/fr/annonces/` (`200`, `24` cartes aux 1er et 2ᵉ GET), fiche détail (`200`, `og:image` absolue), TTFB extérieur `p50 157 ms ≤ 800 ms` (CDC LOT 3), et présence sur `GET /fr/annonces/` de l'intermédiaire **`free.cdn.hstgr.net` (`Server: hcdn`, `x-hcdn-cache-status: BYPASS`)** devant WordPress (`x-partikulier-cache: HIT` au niveau plugin/thème).
- **Non prouvé (`NON VÉRIFIÉ`) :**
  1. **Module serveur LSCache (`x-litespeed-cache`) :** distinct du plugin WordPress (`LiteSpeed Cache` / `x-partikulier-cache`), cet en-tête est absent derrière le CDN `Server: hcdn`. **Constat d'architecture (non une anomalie du produit) : le cache serveur d'origine est NON VÉRIFIABLE depuis l'extérieur tant qu'un CDN est devant, sauf interrogation directe de l'origine.**
  2. **Cache fichier du thème (`CACHE-THEME`) :** `non vérifié` sur la sonde serveur (`?pk_audit=1794339219` HTTP `200`, aucune entrée fraîche `< 30 min` `darkcyan-yak-151002.hostingersite.com_fr_annonces.html` dans `uploads/pk-cache/`, seules des entrées antérieures présentes). Aucun `NON VÉRIFIÉ` converti en `OK`, `PK_THEME_CACHE_REQUIRED` reste à `'false'`.
