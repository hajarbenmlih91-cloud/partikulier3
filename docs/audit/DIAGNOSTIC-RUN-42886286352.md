# Diagnostic rectificatif du run `42886286352` — Missions 1 à 5

**Date :** 2026-11-10  
**Ref audité (inchangé) :** tag `audit-2.10.16-6.20.214-3` (`1f9e41d5…`) → commit `4f14acd9b56c93a798e06ca5f23cabdb185b6674` (diff `c9e1413..4f14acd9` = 3 fichiers `ALLOWED`, zéro `docs/`).  
**Qualification du run `42886286352` :** **Run de DIAGNOSTIC** (et **non** un run de validation, les seuils `SEUILS-VALIDATION-UAT.md` v1.0 ayant été versionnés après la fin du run).  
**Statut release :** **Note NON CALCULABLE — Release NON VALIDÉE.**

---

## Rectifications explicites par rapport à l'analyse initiale

1. **Qualification du run :** `42886286352` est un **run de diagnostic**, pas un run de validation.
2. **Seul échec machine du workflow :** l'étape 13 (`Charge 100 req/s`, `outcome=failure`, code `4`), propagée par l'étape 21 (`Verdict final`, code `1`). L'étape 18 (`Lighthouse`) s'est terminée en `conclusion=success` (`outcome=success`) : les constats sur `fr-home-mobile` ne constituent donc pas un échec machine du workflow.
3. **Code exact des `5xx` :** l'hypothèse initiale « saturation serveur » sans code HTTP est remplacée par la mesure réelle hors harnais (Étape 1 ci-dessous).
4. **Intermédiaire CDN et couches de cache :** `darkcyan-yak-151002.hostingersite.com` résout vers le CDN Hostinger (`CNAME free.cdn.hstgr.net`, `Server: hcdn`). Le plugin WordPress (`LiteSpeed Cache` / `x-partikulier-cache`) et le module serveur (`LSCache` / `x-litespeed-cache`) sont deux couches distinctes : derrière HCDN, le cache serveur d'origine est **non vérifiable depuis l'extérieur**.

---

## ÉTAPE 1 — Code exact des `5xx` (artefact existant + sonde hors harnais)

### 1.1 Contenu réel de `load-full.txt` (`audit-uat-17`)
`load-full.txt` ne publie **que les classes HTTP agrégées** : `2xx=1599 3xx=0 4xx=0 5xx=1401 erreurs réseau=0` (`p50 94 ms · p95 230 ms · p99 409 ms`).  
`theme/partikulier/tests/staging-charge.php` compte les codes individuels dans `$codes[$code]` mais n'affiche que `$n2xx`, `$n3xx`, `$n4xx`, `$n5xx` : **le script du harnais ne mesure pas la répartition par code HTTP exact (`500`/`502`/`503`/`504`)**. Aucune modification n'a été apportée au workflow ni à `audit-uat-login.mjs` (le tag `-3` reste définitif).

### 1.2 Mesure hors harnais (`docs/audit/sonde-capacite-uat.py`, `2026-11-10T20:09:42Z`)
La sonde reproductible déposée dans `docs/audit/` (cf. `SONDE-CAPACITE-UAT-2026-11-10.md`) a mesuré sur les mêmes chemins (`/fr/annonces/`, `/fr/`, `/fr/annonces/?es_city=casablanca`) :
- `10 req/s` (5 s) : `50 × HTTP 200` (`0` erreur, `p50=237,8 ms`, `p95=453,8 ms`)
- `20 req/s` (5 s) : `73 × HTTP 200` + **`27 × HTTP 500`** (`p50=2 794,8 ms` sur `500`)
- `50 req/s` (5 s) : `207 × HTTP 200` + **`43 × HTTP 500`** (`p50=294,7 ms` sur `500`)
- `100 req/s` (15 s) : `800 × HTTP 200` (53,3 %) + **`700 × HTTP 500`** (46,7 %, `p50=356,7 ms` sur `500`)
- **`0` réponse `429`, `502`, `503` ou `504`.**

Tous les échantillons `HTTP 500` portent `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private` et le HTML WordPress `<title>Database Error</title>` (`dead_db()`), tout en traversant HCDN (`Server: hcdn`). **Il s'agit d'un refus `HTTP 500 Database Error` de l'origine WordPress/MySQL sous concurrence lorsque HCDN laisse passer en `BYPASS`, et non d'un rejet `429`/`502`/`503` du CDN.**

---

## ÉTAPE 2 — En-têtes d'intermédiaire et constat d'architecture

### 2.1 Dans `cache-ttfb.txt` et les 20 GET (`audit-uat-17`)
`staging-cache.php` n'extrait que `x-litespeed-cache` et `x-partikulier-cache` ; les en-têtes `server`, `x-hcdn-*`, `cf-ray`, `via`, `x-cache`, `x-turbo-charged-by` ne sont pas présents dans `cache-ttfb.txt`.

### 2.2 Relevé complet d'un GET direct (`GET /fr/annonces/` le `2026-11-10T20:08:34Z`)
- **DNS :** `darkcyan-yak-151002.hostingersite.com` → CNAME `free.cdn.hstgr.net` (`147.79.120.251`, `92.112.198.252`, `2a02:4780:4e:…`, `2a02:4780:51:…`)
- **En-têtes intermédiaires et cache observés :**
  - `Server: hcdn`
  - `platform: hostinger`
  - `panel: hpanel`
  - `x-hcdn-request-id: c44639a170219d2726584a73d9ebf211-phx-edge14`
  - `x-hcdn-cache-status: BYPASS`
  - `x-hcdn-upstream-rt: 0.277`
  - `Cache-Control: public, max-age=43200`
  - `x-partikulier-cache: HIT` *(couche plugin/thème WordPress)*
  - `x-litespeed-cache`, `cf-ray`, `via`, `x-cache`, `x-turbo-charged-by` : **absents**

**Constat d'architecture :** **le cache serveur d'origine (module LSCache) est NON VÉRIFIABLE depuis l'extérieur tant qu'un CDN (`free.cdn.hstgr.net` / `Server: hcdn`) est devant, sauf interrogation directe de l'origine.** C'est un constat d'architecture, pas une anomalie du produit.

---

## ÉTAPE 3 — Diagnostic `fr-home-mobile` (aucune correction de thème)

Comparaison issue directement des 5 JSON Lighthouse de `audit-uat-17` :

| Profil | Perf | FCP (ms) | LCP (ms) | TBT (ms) | Main-thread (`mainthread-work-breakdown`) | `bootup-time` | Long tasks | DOM (`dom-size`) |
|---|---:|---:|---:|---:|---|---|---|---|
| **`fr-home-mobile`** | **62** | 1 585 | **3 130** | **2 588** | **5,4 s** (`5 363 ms`) | `0,5 s` scripting (`515 ms`) | **11** | **1 549** |
| `en-home-mobile` | 97 | 1 427 | 2 455 | 2 | `0,8 s` (`751 ms`) | `0,1 s` (`68 ms`) | 3 (`53–72 ms`) | 1 214 |
| `ar-home-mobile` | 97 | 1 429 | 2 459 | 2 | `0,9 s` (`871 ms`) | `0,1 s` (`68 ms`) | 3 (`55–94 ms`) | 1 211 |
| `fr-home-desktop` | 99 | 443 | 779 | 0 | `0,2 s` (`214 ms`) | `0,0 s` (`6 ms`) | 0 | 1 549 |
| `fr-listings-mobile` | 98 | 1 584 | 2 318 | 4 | `0,7 s` (`722 ms`) | `0,1 s` (`75 ms`) | 3 (`59–115 ms`) | 2 305 |

### Audit des 5 catégories demandées sur `lighthouse-fr-home-mobile.json`

1. **Scripts tiers (`third-party-summary`) :** **`0`** (aucun script tiers chargé).
2. **Poids JS inutilisé (`unused-javascript`) :** **`0 o`** (`score=1`). Un seul script externe (`assets/js/main.js?ver=1794188866`, `24 469 o` transférés / `104 250 o` décompressés, dont `5 901 o` non minifiés dans `unminified-javascript`).
3. **Taille du DOM (`dom-size`) :** **`1 549` éléments** (profondeur `14`, max `37` enfants dans `div#pk-find-panel .pk-find-checks`), soit **`+335` nœuds** par rapport à `/en/` (`1 214`) et `/ar/` (`1 211`) ; la page `/fr/` contient `12` cartes / `122` `<svg>` (`296 008 o` HTML décompressés) contre `8` cartes / `77` `<svg>` sur `/en/` (`205 291 o`).
4. **Images (`uses-responsive-images`, `image-delivery-insight`, `offscreen-images`) :** `unsized-images` = `score 1` (0 image sans dimensions) ; `uses-responsive-images` = **`96 KiB`** économisables (`image-delivery-insight` = **`196 KiB`**) sur :
   - `theme/partikulier/assets/img/pep-logo.png` (`36 128 o`, `1308×557` affiché en `99×42`, `35 497 o` superflus) ;
   - `uploads/2026/10/demo-riad-courtyard-14-640x480.avif` (`119 126 o`, `33 526 o` / `104 708 o` selon audit) ;
   - `uploads/2026/10/demo-rooftop-riad-23-640x480.avif` (`66 847 o`, `18 813 o` / `52 429 o`) ;
   - `theme/partikulier/assets/img/pep-logo-white.png` (`10 289 o`, `1308×557` en `85×36`, `10 157 o` superflus + `offscreen-images`) ;
   - `uploads/2026/10/modern-luxury-villa-architecture-pool-mo-2.avif` (`20 168 o`, `7 990 o`).
5. **Répartition `mainthread-work-breakdown` (`5 363,5 ms`) :**
   - `Other` : `2 529,3 ms`
   - `Style & Layout` : `1 715,8 ms`
   - `Script Evaluation` : `501,1 ms`
   - `Parse HTML & CSS` : `420,3 ms`
   - `Rendering` : `181,2 ms`
   - `Script Parsing & Compilation` : `15,7 ms`

### Liste classée des suspects par temps de blocage (`bootup-time`, `long-tasks`, `forced-reflow-insight`)

| Rang | Fichier / Source | Temps total (`bootup-time`) | Long tasks (`long-tasks`) | Reflow forcé (`forced-reflow-insight`) & mécanisme observé |
|---:|---|---:|---|---|
| **1** | **`https://darkcyan-yak-151002.hostingersite.com/fr/`** *(HTML + `<script>` inline du bandeau `data-pk-cookie` + `style.css`)* | **`3 085,7 ms`** *(dont `368,9 ms` scripting)* | **7 tâches** (`777 ms`, `326 ms`, `189 ms`, `146 ms`, `141 ms`, `132 ms`, `112 ms`) | **`378,1 ms`** à la ligne **`1588:19`** (`var yes = bar.querySelector("[data-yes]"); yes.focus();` juste après `bar.hidden=false` + `document.body.classList.add("pk-cookie-open")`, suivi de `placeInfo()`, sur mobile uniquement `mobileCookie()` ; absent sur desktop `0 ms`). Retarde la peinture LCP de `p.pk-editorial-hero__intro` (`Render Delay = 2 445,5 ms`, `78 %` des `3 130 ms`). |
| **2** | **`Unattributable`** *(tâches navigateur style/layout/composite sur le thread principal)* | **`1 737,3 ms`** *(dont `21,7 ms` scripting)* | **3 tâches** (`771 ms`, `656 ms`, `74 ms`) | Correspond à la poussée `Other` (`2 529 ms`) + `Style & Layout` (`1 716 ms`) sur le DOM `/fr/` (`1 549` nœuds, `302,5 Ko` `style.css`), probablement amplifiée par une contention CPU ponctuelle du runner CI lors de cette capture (à comparer à `fr-listings-mobile` : `2 305` nœuds DOM avec le même `main.js`, `0,7 s` main-thread et `TBT 4 ms`). |
| **3** | **`theme/partikulier/assets/js/main.js?ver=1794188866`** | **`520,7 ms`** *(dont `108,6 ms` scripting, `13,0 ms` parse/compile)* | **1 tâche** (`236 ms` à `t=4 265,7 ms`) | **`18,9 ms`** de reflow forcé sur mobile : ligne `1492:22` (`8,75 ms`, `focusTarget.focus()` sur modale `pk-lang-gate`), lignes `2063:16` (`3,01 ms`) & `2068:20` (`7,11 ms`, lecture `getBoundingClientRect()` → écriture `--pk-header-h` → relecture `getBoundingClientRect()` dans la barre sticky `< 768 px`). `5 901 o` non minifiés. |

*(Aucun correctif thème n'a été effectué afin de préserver `c9e1413` et la garde de traçabilité.)*

---

## ÉTAPE 4 — Décision soumise au propriétaire (non tranchée unilatéralement)

Les seuils `docs/audit/SEUILS-VALIDATION-UAT.md` v1.0 n'ont **pas** été réécrits après le run. Les éléments factuels suivants sont soumis au choix du propriétaire avant tout futur run :

1. **Tenue en charge HTTP :** `100 req/s` représente `8,64 millions` de requêtes/jour pour un site en UAT affichant 24 annonces, et la sonde `sonde-capacite-uat.py` montre que l'origine PHP/MySQL renvoie déjà `27 %` de `HTTP 500 Database Error` dès `20 req/s` (`0` erreur à `10 req/s`) tant que HCDN est en `BYPASS`.
   - **Proposition A (à trancher par le propriétaire) :** retenir **`10 à 20 req/s` avec `0` erreur `5xx` et `p95 ≤ 800 ms`** comme critère d'acceptation (en notant qu'à `20 req/s` sans cache CDN/LSCache actif devant PHP, le critère échoue aujourd'hui par `HTTP 500 Database Error`, tandis que `10 req/s` tient à `0` erreur dans le sondage du harnais et la sonde ponctuelle), et documenter **`100 req/s` (et le palier `20+ req/s` hors cache) comme limite de rupture connue** avec son code (`HTTP 500 Database Error`) et son responsable (pool de connexions MySQL WordPress derrière HCDN en `BYPASS`).
   - **Proposition B :** maintenir `100 req/s` comme exigence ; dans ce cas, le passage préalable de l'hébergement/cache frontal (`HCDN` / `LSCache`) ou du dimensionnement MySQL est requis avant validation.
2. **`fr-home-mobile` :** décider si les écarts constatés en diagnostic (`TBT 2 588 ms` / `LCP 3 130 ms` / reflow inline `:1588`) doivent être corrigés sur la branche applicative avant le prochain cycle commit → audit → validation.

---

## ÉTAPE 5 — Conditions cumulatives avant tout run de validation

Aucun nouveau run ne sera lancé tant que toutes les conditions suivantes ne sont pas réunies :
1. Arbitrage explicite du propriétaire sur les critères d'acceptation (Étape 4) et mise à jour/fige des seuils **avant** le run ;
2. Si des correctifs produit/thème sont décidés, nouveau commit applicatif propre puis nouvelle lignée de tag d'audit (sinon, aucune retouche de `4f14acd9` ni de `docs/` dans le ref audité) ;
3. Un seul run de validation autorisé explicitement une fois les seuils figés ;
4. En l'absence de ces conditions, **la note reste non calculable et la release non validée**.
