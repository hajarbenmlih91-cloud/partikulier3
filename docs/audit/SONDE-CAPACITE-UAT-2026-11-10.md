# Sonde de capacité UAT et inspection d'intermédiaire (hors harnais — 2026-11-10)

**Objet :** Compléter hors workflow (sans modifier `audit-uat.yml`, `audit-uat-login.mjs` ni le tag `audit-2.10.16-6.20.214-3`) les mesures que les scripts `staging-charge.php` et `staging-cache.php` n'exposent pas dans `audit-uat-17` :
1. Ventilation exacte par code HTTP (`500` vs `502` / `503` / `504` / `429`) lors des échecs sous charge ;
2. Jeu complet d'en-têtes HTTP et résolution DNS identifiant l'intermédiaire devant l'origine UAT.

---

## 1. Constat sur `load-full.txt` (`audit-uat-17`)

Dans l'artefact `audit-uat-17`, `load-full.txt` ne publie **que les classes agrégées** :

- `Requêtes tirées : 3000`
- `reçues (réponse) : 3000 · 2xx=1599 3xx=0 4xx=0 5xx=1401 erreurs réseau=0`
- `Latence totale : p50 94 ms · p95 230 ms · p99 409 ms`

Lecture de `theme/partikulier/tests/staging-charge.php` (commit `4f14acd9`) : le script enregistre les codes dans `$codes[$code]` en mémoire, mais les additionne ensuite dans `$n2xx`, `$n3xx`, `$n4xx`, `$n5xx` sans imprimer `$codes`. **Le script du harnais ne mesure donc pas la ventilation par code HTTP exact (`500`/`502`/`503`/`504`) demandée pour l'analyse.**

---

## 2. Exécution de la sonde reproductible (`docs/audit/sonde-capacite-uat.py`)

Commande exacte exécutée le `2026-11-10T20:09:42Z` (hors harnais d'audit, sans modification du dépôt audité) :

```bash
python3 docs/audit/sonde-capacite-uat.py https://darkcyan-yak-151002.hostingersite.com
```

Chemins interrogés en boucle ouverte (`PATHS`, identiques à `audit-uat.yml`) : `/fr/annonces/`, `/fr/`, `/fr/annonces/?es_city=casablanca`.

### Résultats mesurés par palier

| Palier cible | Reqs terminées | Débit réel | HTTP `200` | HTTP `500` | Autres codes (`429`, `502`, `503`, `504`) | Latence `200` (`p50` / `p95`) | Latence `500` (`p50` / `p95`) |
|---|---:|---:|---:|---:|---:|---|---|
| **10 req/s** (5 s) | 50 / 50 | 9,7 req/s | **50** (100 %) | **0** | 0 | `237,8 ms` / `453,8 ms` | — |
| **20 req/s** (5 s) | 100 / 100 | 13,7 req/s | **73** (73,0 %) | **27** (27,0 %) | 0 | `3 979,9 ms` / `6 030,7 ms` | `2 794,8 ms` / `4 030,7 ms` |
| **50 req/s** (5 s) | 250 / 250 | 35,7 req/s | **207** (82,8 %) | **43** (17,2 %) | 0 | `415,3 ms` / `2 622,8 ms` | `294,7 ms` / `538,9 ms` |
| **100 req/s** (15 s) | 1 500 / 1 500 | 96,0 req/s | **800** (53,3 %) | **700** (46,7 %) | 0 | `327,9 ms` / `1 063,0 ms` | `356,7 ms` / `1 201,9 ms` |

*(À noter : au palier 100 req/s, la répartition mesurée `53,3 %` `200` / `46,7 %` `500` reproduit exactement la proportion `1 599 / 1 401` (`53,3 %` / `46,7 %`) observée dans `load-full.txt`.)*

### En-têtes et extrait du corps des réponses `HTTP 500`

Sur chacun des paliers en erreur (`20`, `50`, `100` req/s), **100 % des échecs sont des `HTTP 500`** portant :
- `server: hcdn` (`platform: hostinger`, `panel: hpanel`, `x-hcdn-request-id: ...`)
- `cache-control: no-cache, must-revalidate, max-age=0, no-store, private` *(en-tête `nocache_headers()` émis par WordPress à l'origine)*
- Début du corps HTML : `<!DOCTYPE html> <html dir='ltr'> <head> <meta http-equiv="Content-Type" content="text/html; charset=UTF-8" /> <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>Databas` *(page `Database Error` / `dead_db()` de WordPress)*.

**Responsable établi :** les `5xx` ne sont ni des `429` anti-abus, ni des `502`/`503`/`504` générés par le frontal CDN HCDN. Ce sont des **`HTTP 500` `Database Error` renvoyés par l'origine WordPress/MySQL** (saturation du pool de connexions MySQL dès ~20 req/s lorsque les requêtes traversent HCDN en `BYPASS` jusqu'à PHP/MySQL ; une fois le plafond de connexions atteint, `mysqli` échoue vite, expliquant la latence `p50` réduite sur les `500` en rafale).

---

## 3. En-têtes d'intermédiaire et résolution DNS (Étape 2)

### Ce qui figure dans `cache-ttfb.txt` (`audit-uat-17`)
`staging-cache.php` n'enregistre que deux en-têtes (`x-litespeed-cache` et `x-partikulier-cache`). Les en-têtes bruts d'intermédiaire (`server`, `x-hcdn-cache-status`, `cf-ray`, `via`, `x-cache`, `x-turbo-charged-by`) ne sont pas journalisés dans `cache-ttfb.txt` ni dans l'enregistrement des 20 GET.

### Relevé direct complet sur un `GET` (`2026-11-10T20:08:34Z`)

**Résolution DNS :**
- `darkcyan-yak-151002.hostingersite.com` → CNAME `free.cdn.hstgr.net`
- IPv4 observées : `147.79.120.251`, `92.112.198.252` (et lors de la sonde `148.135.128.167`, `147.79.120.5`)
- IPv6 observées : `2a02:4780:4e:cd1c:fda6:4ff5:15a1:5302`, `2a02:4780:51:cbe1:6ea7:d73d:56ef:3360`

**Jeu complet d'en-têtes sur `GET https://darkcyan-yak-151002.hostingersite.com/fr/annonces/` (`HTTP 200`) :**

```text
Date: Tue, 10 Nov 2026 20:08:34 GMT
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
x-hcdn-request-id: c44639a170219d2726584a73d9ebf211-phx-edge14
x-hcdn-cache-status: BYPASS
x-hcdn-upstream-rt: 0.277
```

*(En-têtes `x-litespeed-cache`, `cf-ray`, `via`, `x-cache`, `x-turbo-charged-by` : absents.)*

**Constat d'architecture (pas une anomalie du produit) :**
Un CDN Hostinger (`free.cdn.hstgr.net`, `Server: hcdn`, `x-hcdn-cache-status: BYPASS`) se trouve en frontal devant l'origine. Le plugin WordPress (`LiteSpeed Cache` / `x-partikulier-cache`) et le module serveur (`LSCache` / `x-litespeed-cache`) sont deux couches distinctes. **Le cache serveur d'origine est donc NON VÉRIFIABLE depuis l'extérieur tant qu'un CDN est devant, sauf interrogation directe de l'origine.**
