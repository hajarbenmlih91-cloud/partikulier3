# Étape 2a — Comparatif edge HCDN HTML vs statique (lecture seule — 2026-11-10)

**Horodatage début :** `2026-11-10T20:33:54.807359+00:00`  
**Horodatage fin :** `2026-11-10T20:33:57.450576+00:00` *(contrôle complémentaire UA navigateur/gzip : `20:34:06`–`20:34:08 UTC`)*  
**Ref audité (inchangé) :** tag `audit-2.10.16-6.20.214-3` (`1f9e41d5…`) → commit `4f14acd9b56c93a798e06ca5f23cabdb185b6674` (zéro modification de code ou de seuils).  
**Statut global :** **Note NON CALCULABLE — Release NON VALIDÉE** (`SEUILS-VALIDATION-UAT.md` v1.0 inchangé).  
**Réponse en 3 lignes (question unique) :**  
Non : le fichier statique `style.css` (`Cache-Control: public, max-age=604800`) reçoit lui aussi `x-hcdn-cache-status: BYPASS` et un `x-hcdn-upstream-rt` non nul (`0,143 s`–`0,190 s`), tout comme les deux `GET /fr/annonces/` (`BYPASS`, `0,221 s` puis `0,190 s` sur le même nœud `phx-edge12`).  
Le comportement observé est un **`BYPASS` edge généralisé** (HTML et statique) sur ce domaine provisoire `*.hostingersite.com` derrière `free.cdn.hstgr.net`, et non une exclusion du seul HTML.

---

## Rappel Étape 0 (déjà tranchée — non réouverte)

- `theme/partikulier/tests/staging-charge.php` (ligne 5) : `« Répond à la question du chef : “si j’envoie 100 requêtes par seconde, …” »` → `100 req/s` est un scénario oral, **pas** une exigence écrite.
- Seule cible attribuée au CDC dans le dépôt : `p95 ≤ 800 ms` (`staging-charge.php` ligne 194) ; précédent documenté : `DOSSIER-2026-10-03-PARTIKULIER-v2.10.16-6.20.11/README.md` (`k6 50VU p95<800ms`).
- Conclusion conservée telle quelle : le critère est `p95 ≤ 800 ms` à zéro erreur notable, à un débit qui reste à choisir et à documenter par le propriétaire.

---

## 1. Mesures brutes (Étape 2a — lecture seule, anonyme, sans cookie ni purge)

**Résolution DNS observée au début de la mesure :**  
`darkcyan-yak-151002.hostingersite.com` → `148.135.128.225`, `92.112.198.145`, `2a02:4780:1e:871c:9af7:ccf6:64a:9ffe`, `2a02:4780:21:96bb:8dc1:9c76:4127:7592`.

### Synthèse des métriques demandées

| Requête | Horodatage (UTC) | Statut | `x-hcdn-cache-status` | `x-hcdn-upstream-rt` | `x-hcdn-request-id` (PoP) | Durée totale | Taille | SHA-256 du corps |
|---|---|---:|---|---|---|---|---:|---|
| `GET 1 /fr/annonces/` | `20:33:54.865Z` | `200` | `BYPASS` | `0.221` | `bc91598f1cc03b7f2ae230d361f5cacb-phx-edge12` | `0,684760 s` | `301 164` o | `b2389e7df8ca61e71cdc12018be63f10f5bfaf37cbe943d18516951938c8cf01` |
| `GET 2 /fr/annonces/` | `20:33:55.856Z` | `200` | `BYPASS` | `0.190` | `845e483546ac9992f3e10b191a4fae27-phx-edge12` | `0,521798 s` | `301 164` o | `b2389e7df8ca61e71cdc12018be63f10f5bfaf37cbe943d18516951938c8cf01` |
| `GET /wp-content/themes/partikulier/assets/css/style.css` | `20:33:56.679Z` | `200` | `BYPASS` | `0.143` | `412aee16bc06ec5406b163ef8b67210b-phx-edge14` | `0,470828 s` | `302 532` o | `e67eab1817cc86afc4ed3f92d86ecddd79970690f82559a427e76487729c2bb0` |

### Réponses complètes brutes (tous les en-têtes)

#### 1.1 `GET 1` — `https://darkcyan-yak-151002.hostingersite.com/fr/annonces/`
```text
Status: 200 OK
Timestamp_UTC: 2026-11-10T20:33:54.865266+00:00
TTFB_s: 0.519846
Total_s: 0.684760
Body_bytes: 301164
Body_SHA256: b2389e7df8ca61e71cdc12018be63f10f5bfaf37cbe943d18516951938c8cf01

Date: Tue, 10 Nov 2026 20:33:55 GMT
Content-Type: text/html; charset=UTF-8
Transfer-Encoding: chunked
Connection: close
Vary: Accept-Encoding
x-content-type-options: nosniff
x-frame-options: SAMEORIGIN
referrer-policy: strict-origin-when-cross-origin
permissions-policy: geolocation=(), microphone=(), camera=()
content-security-policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; frame-src 'self' https://static.addtoany.com; form-action 'self'; img-src 'self' data: blob: https://static.addtoany.com; font-src 'self' data:; script-src 'self' 'unsafe-inline' https://static.addtoany.com; style-src 'self' 'unsafe-inline'; connect-src 'self'
content-security-policy: upgrade-insecure-requests
strict-transport-security: max-age=31536000; includeSubDomains
x-partikulier-cache: HIT
Cache-Control: public, max-age=43200
platform: hostinger
panel: hpanel
Server: hcdn
x-hcdn-request-id: bc91598f1cc03b7f2ae230d361f5cacb-phx-edge12
x-hcdn-cache-status: BYPASS
x-hcdn-upstream-rt: 0.221
```

#### 1.2 `GET 2` — `https://darkcyan-yak-151002.hostingersite.com/fr/annonces/`
```text
Status: 200 OK
Timestamp_UTC: 2026-11-10T20:33:55.856787+00:00
TTFB_s: 0.359365
Total_s: 0.521798
Body_bytes: 301164
Body_SHA256: b2389e7df8ca61e71cdc12018be63f10f5bfaf37cbe943d18516951938c8cf01

Date: Tue, 10 Nov 2026 20:33:56 GMT
Content-Type: text/html; charset=UTF-8
Transfer-Encoding: chunked
Connection: close
Vary: Accept-Encoding
x-content-type-options: nosniff
x-frame-options: SAMEORIGIN
referrer-policy: strict-origin-when-cross-origin
permissions-policy: geolocation=(), microphone=(), camera=()
content-security-policy: default-src 'self'; base-uri 'self'; object-src 'none'; frame-ancestors 'self'; frame-src 'self' https://static.addtoany.com; form-action 'self'; img-src 'self' data: blob: https://static.addtoany.com; font-src 'self' data:; script-src 'self' 'unsafe-inline' https://static.addtoany.com; style-src 'self' 'unsafe-inline'; connect-src 'self'
content-security-policy: upgrade-insecure-requests
strict-transport-security: max-age=31536000; includeSubDomains
x-partikulier-cache: HIT
Cache-Control: public, max-age=43200
platform: hostinger
panel: hpanel
Server: hcdn
x-hcdn-request-id: 845e483546ac9992f3e10b191a4fae27-phx-edge12
x-hcdn-cache-status: BYPASS
x-hcdn-upstream-rt: 0.190
```

#### 1.3 `GET` — `https://darkcyan-yak-151002.hostingersite.com/wp-content/themes/partikulier/assets/css/style.css`
```text
Status: 200 OK
Timestamp_UTC: 2026-11-10T20:33:56.679258+00:00
TTFB_s: 0.309639
Total_s: 0.470828
Body_bytes: 302532
Body_SHA256: e67eab1817cc86afc4ed3f92d86ecddd79970690f82559a427e76487729c2bb0

Date: Tue, 10 Nov 2026 20:33:56 GMT
Content-Type: text/css
Transfer-Encoding: chunked
Connection: close
Vary: Accept-Encoding
Cache-Control: public, max-age=604800
expires: Tue, 17 Nov 2026 20:33:56 GMT
platform: hostinger
panel: hpanel
content-security-policy: upgrade-insecure-requests
x-turbo-charged-by: LiteSpeed
Server: hcdn
x-hcdn-request-id: 412aee16bc06ec5406b163ef8b67210b-phx-edge14
x-hcdn-cache-status: BYPASS
x-hcdn-upstream-rt: 0.143
```

*(Vérification complémentaire de non-artefact sur `style.css` à `20:34:06`–`20:34:08 UTC` : un 2ᵉ GET avec le même UA + `identity` puis 2 GET avec UA navigateur Chrome et `Accept-Encoding: gzip, deflate` ont tous trois renvoyé `x-hcdn-cache-status: BYPASS`, `x-hcdn-upstream-rt: 0.152`, `0.149` et `0.190` sur `phx-edge11`, `phx-edge13` et `phx-edge14`, avec `Cache-Control: public, max-age=604800` et `x-turbo-charged-by: LiteSpeed`.)*

---

## 2. Contrôles exécutés

| Contrôle | Statut (`outcome` / `conclusion`) | Observations |
|---|---|---|
| **Étape 0** (vérification CDC / charge dans le dépôt) | **Tranchée** | `100 req/s` = scénario oral (`staging-charge.php:5`), seule cible CDC écrite = `p95 ≤ 800 ms`, précédent `k6 50VU p95<800ms`. |
| **Étape 2a** (`2 × GET /fr/annonces/` + `GET style.css`, anonyme, sans cookie, sans purge) | **Exécuté (`200 OK`)** | Aucun `Set-Cookie`, `Vary: Accept-Encoding` seul ; `BYPASS` sur les 2 GET HTML (`phx-edge12`, `0.221 s` puis `0.190 s`) et sur `style.css` (`phx-edge14`, `0.143 s`, puis répétitions `0.149–0.190 s`). |
| Aucune modification de ref, purge cache, réglage ou workflow | **Respecté** | `audit-2.10.16-6.20.214-3` (`4f14acd9`) intact ; aucun run lancé. |

---

## 3. Contrôles NON EXÉCUTÉS et échecs avec cause

*(Distinction systématique : `NON EXÉCUTÉ` pour défaut d'accès ou d'outil ≠ `ÉCHEC` pour mesure défavorable.)*

| Élément | Statut | Cause exacte |
|---|---|---|
| **Étape 2b** (règles CDN / mode dev côté Hostinger hPanel) | **`NON EXÉCUTÉ FAUTE D'ACCÈS`** | Aucun accès hPanel Hostinger disponible dans la session ; aucun contournement tenté. |
| **Étape 2c** (sonde origine directe avec IP officielle hPanel + SNI TLS) | **`NON EXÉCUTÉ FAUTE D'ACCÈS`** | Aucune adresse d'origine officielle fournie par hPanel ; arrêt conforme à la consigne. |
| **Étape 1** (3 mesures Lighthouse `fr-home-mobile` sur cette machine) | **`NON EXÉCUTÉ`** | Ni Chromium ni Lighthouse CLI disponibles dans l'environnement ; aucune installation improvisée. |
| **Étape 3** (rampe de charge ascendante avec coupe-circuit) | **`NON EXÉCUTÉ` (non autorisé à ce stade)** | Fenêtre de charge explicitement non autorisée avant lecture de 2a par le propriétaire. |
| **Run antérieur `42886286352`** (rappel `outcome` vs `conclusion`) | **Échec machine étape 13** (`outcome=failure` lu par étape 21) | Seul échec machine du run (charge `100 req/s`, `1 401` `5xx`) ; l'étape 18 (`Lighthouse`) était en `conclusion=success`. |

---

## 4. Portée exacte de ce qui est prouvé

1. **Prouvé sur l'edge (`free.cdn.hstgr.net` / `Server: hcdn`) :**
   - Sur `/fr/annonces/`, les deux `GET` anonymes (sans `Cookie`, sans `Set-Cookie` en réponse, avec `Cache-Control: public, max-age=43200`) ont frappé **le même nœud edge `phx-edge12`** à 1 seconde d'intervalle et ont tous deux renvoyé `x-hcdn-cache-status: BYPASS` avec des `x-hcdn-upstream-rt` proches (`0.221 s` puis `0.190 s`) et le même SHA-256.
   - Sur `/wp-content/themes/partikulier/assets/css/style.css` (`Cache-Control: public, max-age=604800`, `expires` à +7 jours, `x-turbo-charged-by: LiteSpeed`), HCDN renvoie **également `x-hcdn-cache-status: BYPASS`** (`x-hcdn-upstream-rt` `0.143 s` à `0.190 s`), y compris sur requêtes répétées et avec en-têtes navigateur Chrome + `gzip`.
   - Conclusion directe du comparatif : sur ce domaine provisoire `*.hostingersite.com`, **`free.cdn.hstgr.net` ne met en cache ni le HTML ni cet asset statique (`BYPASS` partout)** ; l'ensemble des requêtes traverse vers l'origine.
2. **Non prouvé / non observable :**
   - **Cache serveur LSCache (`x-litespeed-cache`) :** **non observable sur ces réponses publiques** (absent sur `/fr/annonces/` comme sur `style.css`, malgré `x-turbo-charged-by: LiteSpeed` présent sur `style.css`).
   - **`x-partikulier-cache: HIT` :** présent sur les deux `GET /fr/annonces/`, indique le chemin applicatif WordPress sans prouver l'absence d'exécution PHP ou de requêtes amont sur l'origine.
   - **Cause côté configuration Hostinger (offre / domaine provisoire `*.hostingersite.com` / mode dev / règle hPanel) :** non observable sans hPanel (`2b` / `2c` `NON EXÉCUTÉS FAUTE D'ACCÈS`).
   - **Note et release :** **Note NON CALCULABLE — Release NON VALIDÉE** ; seuils `v1.0` inchangés.
