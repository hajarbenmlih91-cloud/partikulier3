# Évaluation lecture seule — faisabilité d'un CDN « fichiers statiques seulement » (UAT, `2026-11-10`)

- **Cible :** `https://darkcyan-yak-151002.hostingersite.com` (UAT)
- **Référence de code audité :** tag `audit-2.10.16-6.20.214-3` → commit `4f14acd9` (base `c9e1413`, inchangés)
- **État CDN au moment des mesures :** `Cache bypass` actif (`date et auteur non établis` ; aucune modification ni purge)
- **Statut UAT / Release :** `SEUILS-VALIDATION-UAT.md` v1.0 inchangé — **Note NON CALCULABLE — Release NON VALIDÉE**
- **Conclusion :** **`C. non démontré avec les garanties et accès disponibles`** (`Cache bypass` maintenu actif)

---

## 0. Contexte et déclarations du support Hostinger *(non vérifiées indépendamment)*

*(Synthèse du retour support, non verbatim ; ces éléments ne sont pas tenus pour des faits prouvés tant qu'ils ne sont pas vérifiés par accès autorisé ou mesure réelle.)*

- Offre Cloud Startup (4 CPU, 4 Go RAM, 100 PHP workers, 200 processus, limite annoncée de `20` nouvelles connexions MariaDB/s — non assimilée à `20 req/s` HTTP et sans conclusion de capacité).
- CDN actif avec `Cache bypass` activé (`date et auteur non établis`), mode développement inactif, cache serveur actif, WAF inactif.
- Selon le support Hostinger : les fichiers statiques sont cachables (`2 h` par défaut ou selon en-têtes d'origine), le HTML n'est cachable que si l'origine l'autorise (`Cache-Control: private, no-store` → `DYNAMIC`), hPanel standard n'expose ni champ TTL HTML ni règle globale « query string = BYPASS », et ni `Set-Cookie`, ni `wordpress_logged_in_*`, ni `/fr/mes-annonces/`, ni les query strings ne garantissent à eux seuls une exclusion CDN.
- Architecture évoquée par le support : « CDN pour fichiers statiques seulement » (HTML en `Cache-Control: private, no-store` → `DYNAMIC` ; CSS/JS/images/polices en `public, max-age=604800` → `MISS`/`HIT`).

---

## 1. Mesures

### 1.1 Sondes HTTP anonymes en direct (`Cache bypass` actif, sans cookie, sans purge, sans suivi de redirection)

- **Lot initial (5 URLs), du `2026-11-10T22:23:56Z` au `2026-11-10T22:23:58Z` :**

| URL | Statut | `Content-Type` | `Cache-Control` | `Set-Cookie` | `x-hcdn-cache-status` | En-têtes notables observés |
|---|---:|---|---|---|---|---|
| `/fr/` | `200` | `text/html; charset=UTF-8` | `public, max-age=43200` | Absent | `BYPASS` | `x-partikulier-cache: HIT` · `x-hcdn-upstream-rt: 0.180` · `x-hcdn-request-id: 2c33319f52d647882ec6f9abcc692351-imm-edge9` |
| `/fr/annonces/` | `200` | `text/html; charset=UTF-8` | `public, max-age=43200` | Absent | `BYPASS` | `x-partikulier-cache: HIT` · `x-hcdn-upstream-rt: 0.130` · `x-hcdn-request-id: 337f89c1cb15ef508c14f4050b8218a7-imm-edge10` |
| `/fr/annonces/?es_city=casablanca` | `200` | `text/html; charset=UTF-8` | **Absent** | Absent | `BYPASS` | `x-litespeed-cache: hit` · `x-litespeed-cache-control: public,max-age=594591` · `etag: W/"11-1794339228;;;"` · `x-hcdn-upstream-rt: 0.108` |
| `/fr/mes-annonces/` | `302` | `text/html; charset=UTF-8` | `no-cache, no-store, must-revalidate, max-age=0` | Absent | `BYPASS` | `location: …/fr/connexion/?auth_item=login-form&redirect_url=…` · `x-litespeed-cache-control: no-cache` · `x-redirect-by: WordPress` · `x-hcdn-upstream-rt: 0.237` |
| `/wp-content/themes/partikulier/assets/css/style.css` | `200` | `text/css` | `public, max-age=604800` | Absent | `BYPASS` | `expires: Tue, 17 Nov 2026 22:23:58 GMT` · `x-turbo-charged-by: LiteSpeed` · `x-hcdn-upstream-rt: 0.106` |

- **Lot complémentaire (8 URLs), du `2026-11-10T22:41:46Z` au `2026-11-10T22:41:50Z` :**

| URL | Statut | `Content-Type` | `Cache-Control` | `Set-Cookie` | `x-hcdn-cache-status` | En-têtes notables observés |
|---|---:|---|---|---|---|---|
| `/` (racine) | `302` | `text/html; charset=UTF-8` | `no-cache, no-store, must-revalidate, max-age=0` | Absent | `BYPASS` | `location: …/fr/` · `x-redirect-by: Polylang` · `Vary: Accept-Language, Cookie` · `x-hcdn-upstream-rt: 0.201` |
| `/fr/connexion/` | `200` | `text/html; charset=UTF-8` | `private, no-store, max-age=0` | Absent | `BYPASS` | `x-litespeed-cache-control: no-cache` · `x-hcdn-upstream-rt: 0.361` |
| `/fr/deposer/` | `200` | `text/html; charset=UTF-8` | `private, no-store, max-age=0` | Absent | `BYPASS` | `x-litespeed-cache-control: no-cache` · `x-hcdn-upstream-rt: 0.241` |
| `/fr/favoris/` | `200` | `text/html; charset=UTF-8` | `private, no-store, max-age=0` | Absent | `BYPASS` | `x-litespeed-cache-control: no-cache` · `x-hcdn-upstream-rt: 0.239` |
| `/fr/contact/` | `200` | `text/html; charset=UTF-8` | **Absent** | Absent | `BYPASS` | `x-litespeed-cache-control: no-cache` · `x-hcdn-upstream-rt: 0.238` |
| `/fr/annonce/settat/centre-ville/usine-de-1-200-m%c2%b2-a-vendre-a-centre-ville-settat/` | `200` | `text/html; charset=UTF-8` | `public, max-age=43200` | Absent | `BYPASS` | `x-partikulier-cache: MISS` · `x-litespeed-cache: miss` · `x-litespeed-cache-control: public,max-age=604800` · `etag` présent |
| `/fr/page-inexistante-probe-ro/` (`404`) | `404` | `text/html; charset=UTF-8` | `no-cache, must-revalidate, max-age=0, no-store, private` | Absent | *(absent sur `404`)* | `expires: Wed, 11 Jan 1984 05:00:00 GMT` · `x-litespeed-cache: miss` · `x-litespeed-cache-control: public,max-age=3600` |
| `/wp-json/partikulier/v1/health` | `200` | `application/json; charset=UTF-8` | `no-store, no-cache, must-revalidate, max-age=0` | Absent | `BYPASS` | `x-litespeed-cache: hit` · `x-litespeed-cache-control: public,max-age=593505` · `etag` présent |

### 1.2 Faits techniques observés

1. **Non-uniformité de la politique de cache active :**
   - `/fr/`, `/fr/annonces/` et la fiche `/fr/annonce/...` émettent `Cache-Control: public, max-age=43200`.
   - `/fr/annonces/?es_city=casablanca` et `/fr/contact/` n'ont aucun en-tête HTTP `Cache-Control` observable ; la page filtrée porte `x-litespeed-cache: hit` et `x-litespeed-cache-control: public,max-age=594591`.
   - Ces constats concernent les réponses anonymes observées sous `Cache bypass` actif : ils ne prouvent pas une fuite de données privées, mais confirment qu'il est prudent de **ne pas désactiver `Cache bypass` dans l'état actuel**.
2. **Anomalie apparente à confirmer par une voie autorisée (sans purge ni modification) :**
   - `/wp-json/partikulier/v1/health` renvoie `Cache-Control: no-store, no-cache, must-revalidate, max-age=0` tout en portant `x-litespeed-cache: hit` et `x-litespeed-cache-control: public,max-age=593505`.
   - Ce signal est consigné pour clarification (sans purge ni modification) ; il ne prouve pas encore qu'une future erreur `503` serait masquée.

---

## 2. Contrôles exécutés

- **13 sondes HTTP `GET` anonymes** en direct sur UAT (`5` du `2026-11-10T22:23:56Z` au `2026-11-10T22:23:58Z` ; `8` du `2026-11-10T22:41:46Z` au `2026-11-10T22:41:50Z`), sans cookie, sans purge et avec `Cache bypass` actif.
- **Relecture du code audité (`4f14acd9`) :**
  - `theme/partikulier/inc/class-cache.php:130,227` (`Cache-Control: public, max-age=43200` sur HIT/MISS du cache fichier PHP), `:192-194,203-206` (`private, no-store, max-age=0` + `X-LiteSpeed-Cache-Control: no-cache` sur session connectée et slugs `deposer*`, `mes-annonces*`, `favoris*`, `connexion*` ; absence de `contact*`), `:479-480`.
  - `class-tunnel-preview.php:183-184`, `class-security.php:374`, `partikulier-early-seo.php:126` (`private, no-store, max-age=0`) ; `partikulier-recette-gate.php:52`, `RestController.php:167`, `class-listing-approval.php:718` (`no-store...`).
  - `class-listing-urls.php:41-51,611`, `class-sitemap.php:88`, `class-price-index.php:78,83`, `class-buyer-profile-preview.php:65`, `class-leads-admin.php:149`, `pk-diagnostic.php` (`nocache_headers()`).
  - `theme/partikulier/inc/class-site-hygiene.php:120-124,166` (règles `.htaccess` pour médias statiques `public, max-age=31536000, immutable`).
- **Relecture des artefacts du run `42886286352`** (`cache-ttfb.txt`, `security.txt`, `owner-journey.json`, `full-job.log`) et vérification des accès locaux de la session (`~/.ssh` absent, aucune variable/clé SSH, hPanel, WP-Admin ou Hostinger disponible).

---

## 3. NON EXÉCUTÉ / blocages et demande d'inspection en lecture seule

| Contrôle | Statut | Raison exacte et règle de gouvernance |
|---|---|---|
| Inspection hPanel (`CDN`, `Cache bypass`, mode dev, cache serveur, règles/exclusions, présence adresse officielle d'origine + Host/SNI) | **`NON EXÉCUTÉ`** | Aucun accès hPanel dans cette session. |
| Inspection serveur UAT (`.htaccess`, indicateurs non secrets `wp-config.php`, plugins actifs, options `litespeed.*` non sensibles, `wp-content/uploads/partikulier-cache/`) | **`NON EXÉCUTÉ`** | Aucun accès SSH/WP-CLI dans cette session ; aucun nouveau workflow `uat` autorisé. |
| Sonde directe à l'origine | **`NON EXÉCUTÉ`** | Exige une autorisation explicite distincte, l'adresse officielle fournie par hPanel (non recopiée), un `Host`/SNI conforme, un certificat valide et `≤ 10` `GET` (aucune sonde locale `127.0.0.1` ou `--resolve` autorisée). |
| Vérification HTTP authentifiée | **`NON EXÉCUTÉ`** | Aucun test authentifié ni création de compte jetable / capture de cookie autorisé (arrêt en `403`). |
| Vérification réelle `hcdn` (`DYNAMIC` HTML / `HIT` statiques) sans `Cache bypass` | **`NON EXÉCUTÉ`** | Toute modification CDN est interdite à ce stade. |

### Demande prête pour la session disposant des accès autorisés (strictement en lecture seule)

> **Cadre strict :** passer uniquement par un accès déjà autorisé, strictement en lecture seule — **sans nouveau workflow, sans modification, sans purge, sans création de compte, sans capture de cookie / test authentifié et sans sonde locale `127.0.0.1`**.
>
> 1. **hPanel (lecture seule) :**
>    - Relever l'état exact de : `CDN`, `Cache bypass`, mode dev, cache serveur, règles/exclusions éventuelles.
>    - Vérifier si l'adresse IP officielle d'origine et le `Host`/SNI associés sont disponibles ; **ne pas recopier l'adresse IP officielle d'origine dans la réponse, un rapport ou le chat** : confirmer uniquement si elle a été trouvée et la conserver dans l'espace privé autorisé. *(Toute sonde éventuelle vers l'origine reste une étape distincte soumise à autorisation explicite, avec `Host`/SNI conformes, certificat valide et maximum 10 `GET`, sinon arrêt.)*
> 2. **Serveur UAT (lecture seule, sans exposer aucun secret, mot de passe, sel ou clé API) :**
>    - Lire `.htaccess` à la racine WordPress et `wp-content/uploads/.htaccess` (notamment toute règle `mod_headers` / `LiteSpeed` / `Cache-Control`).
>    - Vérifier dans `wp-config.php` uniquement la présence/valeur booléenne de `WP_CACHE` et la forme du préfixe `DB_HOST` (`p:` ou non).
>    - Lister les plugins actifs (`wp plugin list --status=active`) et les options non sensibles `litespeed.*` (politique de cache, exclusions REST/`404`/query strings, émission `Cache-Control` / `X-LiteSpeed-Cache-Control`), afin notamment de clarifier l'anomalie apparente observée sur `/wp-json/partikulier/v1/health` (`x-litespeed-cache: hit` malgré `Cache-Control: no-store`).
>    - Inspecter en lecture seule l'inventaire (`ls -la`, dates/tailles, sans écriture ni purge) de `wp-content/uploads/partikulier-cache/`.
> 3. **Repli :** si ces accès ne sont pas disponibles dans cette autre session non plus, conserver l'état actuel (`Cache bypass` actif) et la conclusion **`C`**.

---

## 4. Portée et conclusion

- **Portée et outcome :** les 13 sondes anonymes réfutent que la configuration active actuelle émette uniformément `Cache-Control: private, no-store` sur l'ensemble des réponses HTML ; la possibilité de corriger ce comportement par configuration serveur seule sans modifier `4f14acd9` reste à examiner via une inspection autorisée de hPanel et de la configuration serveur.
- **Conclusion : `C. non démontré avec les garanties et accès disponibles`.**
- **Décision conservatoire maintenue :** **`Cache bypass` reste activé** ; aucune purge, aucun déploiement et aucune modification du code audité (`4f14acd9` / `c9e1413` / `audit-2.10.16-6.20.214-3`) ne sont effectués.
