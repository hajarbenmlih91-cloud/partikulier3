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
| Inspection hPanel (`CDN`, `Cache bypass`, mode dev, cache serveur, règles/exclusions, présence adresse officielle d'origine + Host/SNI) | **PARTIEL** | Confirmation fournie par la propriétaire que l'IP officielle d'origine et le `Host`/SNI ont été trouvés et conservés en espace privé (non recopiés) ; les valeurs du tableau hPanel (`CDN`, `Cache bypass`, mode dev, cache serveur, exclusions) n'ont pas encore été collées dans le message. |
| Inspection serveur UAT (`.htaccess`, indicateurs non secrets `wp-config.php`, plugins actifs, options `litespeed.*` non sensibles, `wp-content/uploads/partikulier-cache/`) | **EXÉCUTÉ** | Run GitHub Actions lecture seule [`42920928851`](https://github.com/hajarbenmlih91-cloud/partikulier3/actions/runs/42920928851) (`2026-11-10T23:10:15Z`) approuvé par `hajarbenmlih91-cloud` sur `uat` (`audit/cdn-ro-check`, politique temporaire `66251081` supprimée aussitôt après). |
| Sonde directe à l'origine | **`NON EXÉCUTÉ`** | Exige une autorisation explicite distincte, l'adresse officielle fournie par hPanel (non recopiée), un `Host`/SNI conforme, un certificat valide et `≤ 10` `GET` (aucune sonde locale `127.0.0.1` ou `--resolve` autorisée). |
| Vérification HTTP authentifiée | **`NON EXÉCUTÉ`** | Aucun test authentifié ni création de compte jetable / capture de cookie autorisé (arrêt en `403`). |
| Vérification réelle `hcdn` (`DYNAMIC` HTML / `HIT` statiques) sans `Cache bypass` | **`NON EXÉCUTÉ`** | Toute modification CDN est interdite à ce stade. |

---

## 3 bis. Résultats de l'inspection serveur lecture seule (run `42920928851`, `2026-11-10T23:10:15Z`)

*(Exécuté sur `audit/cdn-ro-check` (`f1b05aeec`), issu de `4f14acd9`, après approbation du reviewer `hajarbenmlih91-cloud` sur `uat` ; politique temporaire `66251081` retirée de `uat` après exécution ; zéro secret, zéro cookie et zéro ID compte exposé.)*

1. **Identité et PHP serveur :**
   - `home` = `siteurl` = `https://darkcyan-yak-151002.hostingersite.com` ; PHP CLI `8.2.35`.
2. **`.htaccess` (`2451` octets à la racine, `85` dans `wp-content/uploads/`, `1070` dans `wp-content/litespeed/`) :**
   - **Aucune règle `Header set` / `Header always set Cache-Control` sur les pages HTML ou PHP** à la racine.
   - Bloc `# BEGIN LSCACHE` actif : `CacheLookup on`, `CacheKeyModify -qs:fbclid/gclid/utm*/_ga`, exclusions `REST AUTH` (uniquement si `HTTP:Authorization` non vide sur `/wp-json` ou `rest_route=`) et `POSTPASS`.
   - Bloc `# BEGIN Partikulier hygiene` : `Header set Cache-Control "public, max-age=31536000, immutable"` sur `\.(avif|webp|jpe?g|png|gif|svg|woff2?)$` uniquement (`.css` et `.js` restent sous en-tête serveur LiteSpeed `public, max-age=604800`).
3. **`wp-config.php` (indicateurs booléens/préfixe non secrets uniquement) :**
   - `WP_CACHE=true`, `WP_CACHE_KEY_SALT` défini, `WP_DEBUG=false`.
   - **`DB_HOST_PERSISTENT_PREFIX=no`** (pas de préfixe `p:` sur la connexion MariaDB : chaque boot WordPress/PHP ouvre une nouvelle connexion vers le plafond Hostinger annoncé à `20` nouvelles connexions/s).
4. **Plugins actifs et drop-ins :**
   - **8 plugins actifs :** `litespeed-cache 7.9.3`, `partikulier-core 2.10.16`, `estatik 4.3.6`, `polylang 3.9.2`, `hostinger 3.0.80`, `hostinger-ai-assistant 3.1.7`, `hostinger-easy-onboarding 3.0.5`, `hostinger-reach 1.8.7`.
   - **0 drop-in** (`advanced-cache.php`, `object-cache.php`, `db.php` tous `ABSENT`).
5. **Options `litespeed.*` non sensibles (`wp_options`) — explication directe des anomalies observées :**
   - `litespeed.conf.cache = "1"` (cache LSCache actif), `cache-priv = "1"` (`cache-ttl_priv = "1800"`), `cache-browser = ""`, `cache-exc = "[]"`, `cache-exc_cookies = "[]"`, `cache-exc_roles = "[]"`, `cache-exc_qs = "[]"`.
   - **`litespeed.conf.cache-ttl_pub = "604800"` (7 jours)** et absence d'exclusion sur query strings : explique `x-litespeed-cache-control: public,max-age=594591` / `x-litespeed-cache: hit` observés sur `/fr/annonces/?es_city=casablanca` et `public,max-age=604800` sur `/fr/annonce/...`.
   - **`litespeed.conf.cache-rest = "1"` et `litespeed.conf.cache-ttl_rest = "604800"` :** explique factuellement l'anomalie apparente sur `/wp-json/partikulier/v1/health` (`x-litespeed-cache: hit` + `x-litespeed-cache-control: public,max-age=593505` malgré `Cache-Control: no-store` émis par `RestController.php:167`, car LSCache se pilote par `X-LiteSpeed-Cache-Control` / `DONOTCACHEPAGE`, pas par `Cache-Control: no-store`).
   - **`litespeed.conf.cache-ttl_status = ["404 3600","500 600"]` :** explique `x-litespeed-cache-control: public,max-age=3600` sur la `404`.
6. **Cache fichier (`wp-content/uploads/partikulier-cache/`) :**
   - `PRESENT` : `19` fichiers (`2.3M`), dont les `.html` / `.html.gz` de `/fr/`, `/en/`, `/ar/`, `/fr/annonces/` et la fiche `/fr/annonce/settat/...` générée lors de la sonde anonyme du `Nov 10 22:41` ; `wp-content/cache` et `.litespeed_conf.dat` absents.

### Impact sur la faisabilité « CDN pour fichiers statiques seulement »
- **État actuel sans modification :** incompatible avec la coupure de `Cache bypass`, car `.htaccess` ne force pas `Cache-Control: private, no-store` sur HTML/PHP, `Partikulier_Cache` (`4f14acd9`) émet `public, max-age=43200` avant `template_redirect`, et `litespeed-cache 7.9.3` émet `x-litespeed-cache-control: public,max-age=604800` (y compris sur `REST` et `404`) sans `Cache-Control: private, no-store` sur `?es_city=casablanca` ni `/fr/contact/`.
- **Piste `B` par configuration serveur/WP seule (sans toucher `4f14acd9`) vs `B` cycle produit :**
  - Même en combinant réglages WP-Admin `litespeed-cache` (`cache-rest = 0`, exclusions/TTL) + règle `.htaccess` `Header always set Cache-Control "private, no-store"` hors extensions statiques, trois vérifications décisives restent non démontrées : (a) précédence réelle d'une règle `.htaccess` sur les réponses `x-litespeed-cache: hit` sans neutraliser `LSCache` serveur (critique car `DB_HOST` est sans `p:` et `Partikulier_Cache` exécute PHP + `wp-settings.php` + connexion MariaDB), (b) comportement exact de `hcdn` face à `x-litespeed-cache-control: public` + `Cache-Control: private, no-store`, et (c) absence d'observation en session authentifiée et à l'origine directe.

---

## 4. Portée et conclusion

- **Portée et outcome :**
  - Les **13 sondes HTTP anonymes** et **l'inspection serveur lecture seule (run `42920928851`)** établissent que la configuration active actuelle (`.htaccess` sans règle `Cache-Control` sur HTML + `Partikulier_Cache` + `litespeed-cache 7.9.3` avec `cache-ttl_pub=604800`, `cache-rest=1`, `cache-exc=[]`, `cache-exc_qs=[]`) **ne permet pas** de désactiver `Cache bypass` en l'état pour du « statiques-seulement », et expliquent directement la cause serveur des anomalies observées (`/fr/annonces/?es_city=casablanca`, `/wp-json/partikulier/v1/health`, `404`).
  - La faisabilité en **`B` par configuration serveur/WP seule** (sans modifier `4f14acd9`) versus un **cycle produit ciblé** reste dépendante du relevé complet hPanel et des vérifications encore non autorisées/non exécutées (sonde directe à l'origine avec IP officielle + `Host`/SNI conformes et `≤ 10 GET`, session authentifiée, et mesure réelle post-bascule).
- **Conclusion : `C. non démontré avec les garanties et accès disponibles`.**
- **Décision conservatoire maintenue :** **`Cache bypass` reste activé** ; aucune purge, aucun déploiement et aucune modification du code audité (`4f14acd9` / `c9e1413` / `audit-2.10.16-6.20.214-3`) ne sont effectués.

