# Rapport de campagne UAT — `audit-2.10.16-6.20.214-3` (Run `42886286352`)

**Date :** 2026-11-10  
**Cible :** environnement UAT uniquement (`https://darkcyan-yak-151002.hostingersite.com`) — aucune opération sur la production (`prd`).  
**Verdict global :** **Release NON VALIDÉE — Note NON CALCULABLE** (`conclusion: failure` sur le run `42886286352`, seuils versionnés postérieurement au run, écarts de performance/charge bloquants).

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
| Branche documentaire Partie B (hors tag) | `audit/harnais-serie-e-seuils` (`SEUILS-VALIDATION-UAT.md`, `harnais-tests/` séries A–E) |

---

## Section 1 — Ce qui a été testé en simulation (et limites des simulations)

Conformément à l'Étape 6, la **série E** a été ajoutée sur la branche dédiée `audit/harnais-serie-e-seuils` (sans modifier `4f14acd9`, ni le tag `audit-2.10.16-6.20.214-3`, ni introduire `docs/` dans `c9e1413..audit-2.10.16-6.20.214-3`), puis l'intégralité du harnais a été rejouée contre une extraction propre de `4f14acd9`.

### 1.1 Décompte exact des tests exécutés (sans arrondi)

**Total réel exécuté : `30` tests — `30 PASS · 0 FAIL`** :

| Série | Exécutant | Nb réel | Statut | Propriétés vérifiées |
|---|---|---:|---|---|
| **A** | `run.sh` (bash) | **4** | `4 PASS · 0 FAIL` | Garde de traçabilité sur `ALLOWED` (`audit-uat.yml`, `staging-cache.php`, `audit-uat-login.mjs`) ; rejet explicite si `deploy.yml`, `audit-uat.mjs` ou `functions.php` diffère |
| **B** | `run.sh` (bash) | **5** | `5 PASS · 0 FAIL` | Tentatives Lighthouse réellement exécutées (`1/2`, `2/2`, « après 2 tentative(s) », jamais « après 3 », pas de rejeu sur erreur de configuration déterministe `--preset=desktop`) |
| **C** | `run.sh` (bash) | **10** | `10 PASS · 0 FAIL` | Sonde cache fichier : répertoire vide, entrée HTML fraîche, rejet fichier non-HTML (`C2b`), rejet autre hôte (`C3`), rejet autre page (`C4`), troncature (`C5`), péremption (`C6`), SSH absent (`C7`), `PK_THEME_CACHE_REQUIRED` (`C8`/`C9`) |
| **D** | `run_php.mjs` (**PHP 8.2 réel** WASM) | **5** | `5 PASS · 0 FAIL` | Contrat `staging-cache.php` : `ls_hit_replay`, `ls_header_gone`, `ls_both_absent`, `php_two_render`, `php_two_render_bad` |
| **E** | `run_login.mjs` (**Node ESM** via `node:module` `register` sur `audit-uat-login.mjs` réel) | **6** | `6 PASS · 0 FAIL` | `E1` URL avec `?redirect_url` → préfixe `/mes-annonces` accepté ; `E2` URL sans paramètre (`/mes-annonces`) → accepté ; `E3` `.pk-dashboard` absent → échec explicite (`dashboard=false`, code 1) ; `E4` bandeau absent → échec explicite (`Consent banner absent`, code 1) ; `E5` `.pk-dashboard-empty` présent → validé (et absent → refusé, code 1) ; `E6` cookie `wordpress_logged_in_*` nommé mais `.value` jamais lu (prouvé par `Proxy` : `cookie_value_read=false`, aucune fuite) |

*(Vérification par mutations négatives : 5 mutations ciblées appliquées en copie temporaire sur `audit-uat-login.mjs` ont fait échouer respectivement E1/E2, E3, E4, E5 et E6. Le commentaire obsolète « 18 tests » de `harnais-tests/README.md` a été corrigé à `19 tests`.)*

### 1.2 Limites explicites des simulations et limite connue non corrigée

1. **Doublures locales et transport simulé** : les séries A–E n'interrogent pas l'UAT réelle ; `ssh`/`wp`/`lighthouse` sont doublés en A–C, `get_http()` est simulé en D, et Playwright est simulé par hook ESM en E (`page.evaluate` exécute cependant réellement les sélecteurs et assertions de `audit-uat-login.mjs`).
2. **Limite connue déclarée dans `theme/partikulier/tests/audit-uat-login.mjs` (ligne 199, non corrigée dans `4f14acd9`)** : si `AUTHORIZED` est faux (par ex. en cas d'échec ou de défaut d'exécution de la création du compte temporaire si l'invocation hors workflow ne l'a pas bloqué), le script saute les 4 parcours mais conclut néanmoins `status: 'PASS'` dans `owner-journey.json`. Dans `audit-uat.yml`, l'étape `Parcours connecté` est toutefois conditionnée à `steps.seed.outcome == 'success'`.

---

## Section 2 — Ce qui a été vérifié sur l'UAT réelle (avec les chiffres mesurés)

Toutes les données ci-dessous proviennent des fichiers de l'artefact **`audit-uat-17`** (`13795619235`) et du journal complet du run **`42886286352`** (jamais du résumé GitHub seul).

### 2.1 SSH et cycle de vie du compte propriétaire jetable
- **SSH UAT** (`uat-initial`) : `outcome: success`, `conclusion: success`.
- **Création** : compte `contributor` `pk_audit_42886286352_1` créé (`ID 15`, marqueur `_pk_uat_audit_marker` vérifié, aucune annonce créée) → `success`.
- **Suppression et preuve post-suppression** : `Success: Removed user 15 from https://darkcyan-yak-151002.hostingersite.com.` ; `wp user get 15` échoue (compte absent) et `wp user list --search=pk_audit_42886286352_1` est vide → `success`.

### 2.2 Sécurité live (`security.txt`)
- **`22 OK · 0 KO · 0 à regarder · 0 sauté(s)`** (`outcome: success`, `conclusion: success`).
- Contrôles HMAC validés (`POST` sans signature `401`, secret seul `401`, replay `-400 s` `401`, HMAC valide `200`), sonde front anonyme `/?pk_diag=1` (`HTTP 302` sans fuite), fichiers de test bloqués par HTTP (`404`), `/wp/v2/users` (`404`), `/.env` et `debug.log` et `xmlrpc.php` (`403`), en-têtes CSP / XFO / XCTO / Referrer-Policy / Permissions-Policy présents, `X-Powered-By` absent.

### 2.3 Cache et TTFB extérieur (`cache-ttfb.txt`)
- Statut étape : `outcome: success`, `conclusion: success` — **`6 OK · 0 KO · 3 à regarder`** *(portée : LiteSpeed et TTFB uniquement — cache fichier du thème NON VÉRIFIÉ)*.
- `/fr/annonces/` : 1er GET HTTP `200` en **`163 ms`** (`24` cartes annonces) ; 2ᵉ GET : `24` cartes.
- Fiche détail (`/fr/annonce/settat/...`) : 1er GET HTTP `200` en **`148 ms`**, `og:image` présente et absolue.
- 20 GET consécutifs : TTFB **`p50 157 ms · p95 315 ms`** (médiane `≤ 800 ms` CDC LOT 3 respectée).
- Requête PHP forcée (`?pk_audit=1794339219`) : HTTP `200`, TTFB **`0.336807 s`** (avertissement mineur `curl: unknown --write-out variable: 'time_local'`).
- Sonde fichier serveur (`uploads/pk-cache/`) : `CACHE-THEME: non vérifié` (aucun `darkcyan-yak-151002.hostingersite.com_fr_annonces.html` > `500 o` modifié dans les 30 dernières minutes ; seules des entrées antérieures `.html` et `.html.gz` étaient présentes).

### 2.4 Charge 10 req/s et 100 req/s (`load-smoke.txt` et `load-full.txt`)
- **Charge 10 req/s (10 s)** : `outcome: success`, `conclusion: success` — `100/100` requêtes reçues (`9.9 req/s`, `4.5 Mo`), `100` `2xx`, `0` `3xx`/`4xx`/`5xx`/erreurs réseau, `0` créneau sauté ; latence `p50 120 ms · p95 201 ms · p99 290 ms`, TTFB `p50 116 ms · p95 196 ms`.
- **Charge 100 req/s (30 s)** : **`outcome: failure` (exit `4`)**, masqué en `conclusion: success` par `continue-on-error: true`, sanctionné au verdict final — `3 000/3 000` requêtes reçues (`99.7 req/s`, `72.3 Mo`), `1 599` `2xx`, **`1 401` `5xx` (46,7 %)**, `0` `4xx`, `0` erreur réseau, `0` créneau sauté ; latence `p50 94 ms · p95 230 ms · p99 409 ms`, TTFB `p50 94 ms · p95 222 ms`.

### 2.5 Parcours connecté propriétaire (`owner-journey.json`)
- `outcome: success`, `conclusion: success` — **`54/54` contrôles `PASS`**.
- `4/4` parcours (`mobile` `390×844` et `desktop` `1440×900` × choix `Accepter` et `Refuser`) en `PASS` : bandeau présent en contexte neuf, fermé au clic ordinaire sans `force: true` ni réapparition au rechargement ou sur `/fr/mes-annonces/`, `loggedIn: true` (cookie `wordpress_logged_in_*` identifié par son nom sans lecture de valeur), `.pk-dashboard` et `.pk-dashboard-empty` visibles (`Bonjour pk_audit_42886286352_1, vos annonces sont en ligne.`), aucune erreur console/JS.

### 2.6 Audit navigateur Chromium / Axe (`browser-audit.json`)
- `outcome: success`, `conclusion: success` — `PASS_WITH_FINDINGS`.
- **`554` contrôles** au total : **`213` `PASS` (severity `error`), `334` `PASS` (`warning`), `7` `FAIL` (`warning`), `0` échec `error`** :
  - `6` avertissements `WhatsApp recipient` : liens `wa.me/?text=…` sans numéro destinataire sur les fiches de démonstration `fr`, `en` et `ar` (390 px et 1440 px).
  - `1` avertissement `UAT robots.txt blocks all crawlers` (`User-agent: Googlebot / Disallow: /`).

### 2.7 Interactions JavaScript et Web Vitals laboratoire (`interaction-web-vitals.md`)
- `outcome: success`, `conclusion: success` — `PASS_WITH_FINDINGS; 0 échec(s), 0 avertissement(s)`.
- **`8/8` interactions `PASS`** (ouverture/fermeture menu mobile, menu langue, filtres archive mobile, sélecteur de type de recherche, touche `Escape`).
- **Mesures labo (6 vues)** : LCP `292–376 ms` (`fr-deposit-mobile-390` `292 ms`, `fr-listings-mobile-390` `316 ms`, `fr-home-mobile-390` `320 ms`, `en-home-mobile-390` `324 ms`, `ar-home-mobile-390` `352 ms`, `fr-home-desktop-1440` `376 ms`) ; CLS `0` (mobile) à `0.0001` (desktop) ; **INP lab proxy max : `32 ms`**.

### 2.8 Lighthouse (`lighthouse-summary.md` et 5 rapports JSON)
- `outcome: success`, `conclusion: success` (`--preset=desktop` utilisé sur desktop) :

| Profil | Perf | A11y | Best Practices | SEO | LCP (ms) | TBT (ms) | CLS | Écarts relevés dans le JSON |
|---|---:|---:|---:|---:|---:|---:|---:|---|
| `ar-home-mobile` | **97** | **100** | **96** | **100** | 2 459 | 2 | 0 | `errors-in-console` (`404 /favicon.ico`) |
| `en-home-mobile` | **97** | **100** | **96** | **92** | 2 455 | 2 | 0 | `link-text` (« Learn more » → `/fr/cookies/`), `errors-in-console` |
| `fr-home-desktop` | **99** | **97** | **96** | **100** | 779 | 0 | 0 | `color-contrast` (3.1:1 `#ffffff` sur `#ff5757` pour CTA et Rechercher), `label-content-name-mismatch` (`.pk-lang-toggle`, `.pk-find-type-btn`), `errors-in-console` |
| **`fr-home-mobile`** | **62** | **100** | **96** | **100** | **3 130** | **2 588** | 0 | **Main-thread `5.4 s`** (`Other 2.53 s`, `Style & Layout 1.72 s`, `Script Eval 0.50 s`), `errors-in-console` |
| `fr-listings-mobile` | **98** | **100** | **96** | **100** | 2 318 | 4 | 0 | `errors-in-console` (`404 /favicon.ico`) |

---

## Section 3 — Ce qui a été sauté ou a échoué, et pour quelle cause

### 3.1 Séparation stricte `outcome` vs `conclusion`

Dans `.github/workflows/audit-uat.yml`, l'étape 13 (`Charge complète 100 req/s`) est configurée avec `continue-on-error: true`. En conséquence :
- Son **`conclusion`** GitHub Actions apparaît comme **`success`**, uniquement pour permettre l'exécution des suites navigateur et le nettoyage du compte jetable en aval ;
- Son **`outcome`** réel est **`failure` (code de sortie `4`)**, provoqué par **`1 401` réponses HTTP `5xx` sur `3 000`** (`46,7 %` d'erreurs serveur sous 100 req/s) ;
- L'étape 21 (`Verdict final`) évalue la variable `FULL_LOAD_RESULT=failure` (issue de `outcome`) et termine le job en **`conclusion: failure` (code de sortie `1`)**.

### 3.2 Échecs, sauts et dérives constatées

| Élément | Statut réel | Cause constatée |
|---|---|---|
| `Charge 100 rps` (`load-full.txt`) | **Échec (`outcome: failure`, exit 4)** | `1 401` réponses `5xx` sur `3 000` à `99.7 req/s` (saturation serveur/PHP workers en rafale sur UAT, sans preuve de hit cache externe). |
| `fr-home-mobile` Lighthouse | **Hors seuils versionnés (`Perf 62`, `LCP 3 130 ms`, `TBT 2 588 ms`)** | Travail principal navigateur à `5.4 s` (`Other 2 529 ms`, `Style & Layout 1 716 ms`, `Script Evaluation 501 ms`). |
| `en-home-mobile` SEO & `fr-home-desktop` A11y | **Points d'amélioration mineurs (`SEO 92`, `A11y 97`)** | Lien générique `« Learn more »` vers `/fr/cookies/` ; contraste `3.1:1` (`#ff5757`) sur 2 boutons et écart `aria-label` / texte visible sur `.pk-lang-toggle` et `.pk-find-type-btn`. |
| Best Practices (`96` sur les 5 profils) | **Avertissement systématique** | Requête `/favicon.ico` en `HTTP 404` (`errors-in-console`). |
| Cache fichier thème & LiteSpeed | **`NON VÉRIFIÉ` (non bloquant tant que `PK_THEME_CACHE_REQUIRED=false`)** | En-tête `x-litespeed-cache` absent des réponses et aucune entrée `<hote>_fr_annonces.html` fraîche (< 30 min) après `?pk_audit=1794339219`. |
| `Post Installer Node, Playwright et Chromium` | **`skipped`** | Post-action de cache GitHub sans entrée à restaurer/sauver. Aucune suite métier n'a été sautée. |
| `audit-uat-login.mjs` si `AUTHORIZED=false` | **Limite déclarée, non corrigée dans `4f14acd9`** | Un appel direct avec `AUTHORIZED=false` retourne `PASS` malgré le saut des parcours (atténué dans `audit-uat.yml` par `steps.seed.outcome == 'success'`). |

### 3.3 Verdict Étape 8 (sans relance automatique)

- Aucune relance automatique n'a été déclenchée.
- Les seuils de validation (`docs/audit/SEUILS-VALIDATION-UAT.md` v1.0) ayant été versionnés après ce run, et le run `42886286352` ayant échoué sur `Charge 100 rps` tout en présentant un dépassement bloquant sur `fr-home-mobile` (`Perf 62`, `TBT 2 588 ms`, `LCP 3 130 ms`), **la note de qualité est déclarée non calculable et la release reste non validée**.

---

## Section 4 — Portée exacte du cache : ce qui est prouvé et ce qui ne l'est pas

| Aspect | Statut | Détail exact |
|---|---|---|
| Disponibilité & intégrité du catalogue | **Prouvé** | `/fr/annonces/` HTTP `200` (`163 ms`), `24` cartes annonces au 1er et au 2ᵉ GET ; fiche détail HTTP `200` (`148 ms`) avec `og:image` absolue. |
| TTFB extérieur (contrat CDC LOT 3) | **Prouvé** | Sur `20` GET consécutifs : médiane **`p50 = 157 ms`** (`≤ 800 ms`), `p95 = 315 ms` (mesure réseau inclus depuis le runner). |
| En-tête LiteSpeed (`x-litespeed-cache`) | **Non prouvé (`NON VÉRIFIÉ`)** | Absent des deux GET sur `/fr/annonces/` et sur la fiche détail (couche proxy/HCDN probable devant l'origine). |
| Sonde PHP forcée `?pk_audit=1794339219` | **Rendu HTTP 200 observé, écriture fraîche non prouvée** | Réponse HTTP `200` (`TTFB 0.336807 s`), mais aucune entrée `darkcyan-yak-151002.hostingersite.com_fr_annonces.html` de `> 500 o` modifiée depuis `< 30 min` n'a été trouvée dans `uploads/pk-cache/` (seules des entrées plus anciennes `.html` / `.html.gz` existaient). |
| Service effectif depuis le cache fichier du thème | **Non prouvé (`NON VÉRIFIÉ`)** | Même lorsqu'un fichier valide existe, `find` ne prouve pas qu'une requête ultérieure est servie depuis ce cache, ni que la purge applicative purge LiteSpeed. `PK_THEME_CACHE_REQUIRED` reste à `'false'` et aucun `NON VÉRIFIÉ` n'a été converti en `OK`. |
