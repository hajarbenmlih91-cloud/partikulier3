# Archive retour Hostinger (10/11) + PHASE 0 (inventaire lecture seule) + PHASE 1 (checklist hPanel consolidée)

**Horodatage création :** `2026-11-10T20:55:00Z` · **Mise à jour arbitrage propriétaire :** `2026-11-10T21:05:00Z`  
**Ref audité (inchangé) :** tag `audit-2.10.16-6.20.214-3` (`1f9e41d5…`) → commit `4f14acd9b56c93a798e06ca5f23cabdb185b6674` (base `c9e1413` ; zéro modification de code, de tag, de workflow ou de seuils).  
**Branche documentaire :** `audit/harnais-serie-e-seuils` (`docs/audit/` strictement hors du ref audité).  
**Statut global :** **Note NON CALCULABLE — Release NON VALIDÉE** (`SEUILS-VALIDATION-UAT.md` v1.0 inchangé).  
**Verrou d’exécution :** **AUCUNE mesure de cache (`PHASE 2a/2b/2c`), de replay CI (`PHASE 3`) ni de rampe (`PHASE 4`) n’est lancée** tant que la propriétaire n’a pas envoyé le message explicite **`« cache activé »`**.

---

## 1. Mesures, synthèse Hostinger archivée et constats lecture seule (PHASE 0)

### 1.1 Archive du retour support Hostinger (10/11/2026) — synthèse du retour support, non verbatim

- **Statut de l’archive :** **synthèse du retour support, non verbatim** (le texte brut mot pour mot du courriel d’origine est indisponible ; aucune reconstruction textuelle n’est effectuée).
- **Date et auteur de l’activation de `Cache bypass` :** **`date et auteur non établis`** (réglage **non** qualifié de « volontaire »).
- **Question à ajouter au ticket de suivi Hostinger :** *« À quelle date et par quel moyen / utilisateur le réglage `Cache bypass` a-t-il été activé sur cet environnement ? »* *(question ouverte, pas une conclusion)*.

Faits techniques établis côté hébergeur (traités comme socle acquis) :

| Élément | Valeur / constat Hostinger (10/11) | Portée technique |
|---|---|---|
| Réglage CDN `Cache bypass` | **Actif** (mode développeur inactif ; **date et auteur non établis**) | Explique le `x-hcdn-cache-status: BYPASS` généralisé mesuré en Étape 2a sur `/fr/annonces/` et `style.css` |
| Plugin WordPress | `LiteSpeed Cache` actif côté WP | En-tête `x-litespeed-cache` **non observable sur ces réponses publiques** derrière `hcdn` |
| Offre / ressources | **Cloud Startup** : `4` cœurs CPU, `4 Go` RAM, `100` workers PHP, `200` processus, base MariaDB `16 Mo / 6 Go` | **Aucune saturation** CPU, RAM, processus ou workers PHP, et **aucune suspension** |
| Plafond MariaDB hébergeur | Plans **Web** et **Cloud** limités à **`20` nouvelles connexions MariaDB par seconde** | Cause probable des réponses `500` (`Databas...`) quand PHP/AJAX ouvre des connexions DB |
| Top trafic observé côté serveur | `/fr/annonces/`, `/fr/`, `/fr/annonces/?es_city=casablanca`, `/wp-admin/admin-ajax.php` | Confirme que le trafic public et `admin-ajax.php` sollicitaient directement PHP + MariaDB |

**Conséquence arithmétique explicite :**  
Avec une requête PHP non cachée ouvrant `1` connexion MySQL, le plafond de l’origine sans cache est **`~20 req/s`** (même alors que les `4` cœurs CPU, les `4 Go` de RAM et les `100` workers PHP ne sont pas saturés). Seul le fait d’**empêcher les requêtes anonymes d’atteindre PHP** (mise en cache edge HCDN) lève ce plafond sur les URLs publiques cachables. À l’inverse, toute requête qui continue d’atteindre PHP et MariaDB (`POST /wp-admin/admin-ajax.php`, sessions connectées, pages privées, requêtes avec query string en `BYPASS`) reste bornée par le plafond hébergeur de `20` nouvelles connexions MariaDB/s (`~20 req/s`).

---

### 1.2 Inventaire exhaustif `wp_ajax_*` / `admin-ajax.php` sur `4f14acd9` (PHASE 0)

Recherche effectuée sur l’intégralité du commit `4f14acd9` (`theme/` et `plugin/`).  
Total recensé : **9 actions `wp_ajax_*`** (8 avec variante `wp_ajax_nopriv_*`, 1 réservée aux utilisateurs connectés) dans `theme/partikulier/inc/`, **0 action AJAX** dans `plugin/partikulier-core/`, plus **1 fetch XHR archive** hors `admin-ajax.php`.

| Action (`action=`) | Enregistrement (`4f14acd9`) | `nopriv` | Nonce vérifié | Appelants JS (`4f14acd9`) | Déclenchée au **chargement initial** de `/fr/` ou `/fr/annonces/` ? | Impact DB / effet |
|---|---|---|---|---|---|---|
| `pk_find_count` | `inc/class-search-filters.php:45-46` (`ajax_count` `:1268-1297`) | Oui | `pk_public` (`check_ajax_referer`) | `assets/js/main.js:1505` (`.pk-find`) → `:2022-2047` (`scheduleCount()` à la fin de l’IIFE, `220 ms` après init) | **OUI — AUTOMATIQUE sur `/fr/` ET `/fr/annonces/`** (et `/en/`, `/ar/`) : prouvé dans les **5/5 JSON Lighthouse** du run `42886286352` (`POST /wp-admin/admin-ajax.php → 200`, `656–657` o) | Exécute `matching_count()` (`WP_Query` + `$wpdb`) à chaque ouverture navigateur réelle (alors que PHP a déjà calculé `$pk_count_n` dans `search-form.php:577`) |
| `pk_visit_count` | `inc/class-visit-counter.php:25-26` (`count` `:31-49`) | Oui | Aucun (teste cookie `pk_measure === 'yes'`) | JS inline `wp_footer` (`inc/class-visit-counter.php:212-264`) | **Non au 1er hit anonyme sans cookie** ; **OUI au clic « Accepter »** (`:226-230`) puis **automatique au chargement** de `/fr/` et `/fr/annonces/` (toutes pages sauf `/deposer/`) dès que `pk_measure=yes` est présent et `sessionStorage["pk_counted:"+pathname]` absent (`:254`) | Lit + écrit `wp_options` (`pk_visit_daily`) via `update_option(..., false)` |
| `pk_views_counter` | `inc/class-dashboard.php:20-21` (`handle_views_counter` `:69-102`) | Oui | `pk_views_counter` | `estatik4/front/property/single.php:529-534` | **Non sur `/fr/` ni `/fr/annonces/`** (automatique au chargement d’une fiche annonce active si cookie `pk_v_<ID>` absent) | Pose cookie `pk_v_<ID>` + incrémente `_pk_views` |
| `pk_places_search` | `inc/class-morocco-places.php:69-70` (`handle_search` `:386`) | Oui | Aucun (supprimé en `6.17.29`, `:388-401`) | `main.js:765-782`, `submit-steps.js:292-293` | **Non** (sur saisie/focus dans `[data-pk-place-input='true']`, requête `GET`) | Fusion référentiel + termes `es_location` |
| `pk_sync_favorite` | `inc/class-owner-insights.php:34-35` (`handle_sync_favorite` `:217`) | Oui | `pk_public` | `main.js:242-256` | **Non** (au clic cœur `.pk-card-wishlist`) | Écriture table `pk_property_saves` |
| `pk_favorites_list` | `inc/class-owner-insights.php:36-37` (`handle_favorites_list` `:183`) | Oui | `pk_public` | `main.js:663-694` | **Non sur `/fr/` ni `/fr/annonces/`** (sur `/favoris/` uniquement si `localStorage.pk_wishlist` non vide) | Lecture annonces par IDs |
| `pk_submit_listing` | `inc/class-form.php:22-23` (`handle_ajax` `:29`) | Oui | `pk_submit_listing` | `main.js:394-400` (`page-deposer-annonce.php:86`) | **Non** (soumission `/deposer/` uniquement) | Création annonce + média + utilisateur |
| `pk_listing_preview` | `inc/class-listing-preview.php:26-27` (`handle_preview` `:607`) | Oui | `pk_submit_listing` | `submit-steps.js:623-632` | **Non** (aperçu sur `/deposer/` uniquement) | Génération texte sans écriture DB |
| `pk_manage_listing` | `inc/class-dashboard.php:19` (`handle_manage` `:103`) | **Non** (connecté seul) | `pk_manage_listing` | `main.js:559-566` | **Non** (action sur `/mes-annonces/` connecté uniquement) | Mise à jour statut / corbeille |

Fetch XHR complémentaire hors `admin-ajax.php` :
- `main.js:2574` : `fetch(url, { credentials: "same-origin", headers: { "X-Requested-With": "pk-results" } })` — uniquement lors d’un changement interactif de filtre, tri ou pagination sur l’archive `/fr/annonces/` (pas au chargement initial).

**Deux conséquences critiques et dette technique notée pour un cycle ultérieur (hors périmètre `4f14acd9`) :**
1. **Écart « sonde HTTP brute vs trafic navigateur réel » :** même lorsque les `GET /fr/` et `GET /fr/annonces/` sont servis en `HIT` edge par HCDN, chaque visiteur navigateur réel émet automatiquement **`1 × POST /wp-admin/admin-ajax.php` (`pk_find_count`)** à `t + 220 ms` (plus `pk_visit_count` si `pk_measure=yes`), atteignant PHP et MariaDB.
2. **Contrainte stricte de TTL liée au nonce `pk_public` :** `pk_find_count` (`class-search-filters.php:1269`) vérifie `check_ajax_referer('pk_public', 'nonce')` sur un nonce imprimé dans le HTML (`class-scripts.php:56`). Un nonce WordPress vivant `12–24 h`, si le TTL du cache edge dépasse cette durée, le widget de comptage renvoie **`403` silencieusement** (`main.js:2035` avale l’erreur) sur une page pourtant servie en `HIT` HTML, sans trace dans les scores Lighthouse. *(À corriger au prochain cycle produit — pas maintenant : un point d’entrée public en lecture seule n’a pas besoin de nonce CSRF, et le chargement initial dispose déjà de `$pk_count_n` calculé côté serveur dans `search-form.php:577`.)*

---

### 1.3 Accès base de données et vérification `DB_HOST` (`p:`)

- **Code versionné (`4f14acd9`, `theme/` + `plugin/`) :** `0` appel direct `mysqli_connect`, `new mysqli`, `new PDO` ou `new wpdb(...)` ; toute la couche SQL passe exclusivement par le `$wpdb` global de WordPress (`DB_HOST` défini dans `wp-config.php`).
- **`wp-config.php` :** hors dépôt Git — la présence du préfixe persistant `p:` sur `DB_HOST` est **`NON EXÉCUTÉ`** depuis le dépôt ou par HTTP anonyme (contrôle serveur requis côté propriétaire).

---

### 1.4 Pré-contrôle `/robots.txt` sur UAT (`2026-11-10T20:54:26Z`)

```text
HTTP/2 200
date: Tue, 10 Nov 2026 20:54:26 GMT
content-type: text/plain
content-length: 58
vary: Accept-Encoding
server: hcdn
x-hcdn-request-id: bf0e5df75f2595dfaaa767d0bb0b7c2b-imm-edge9

User-agent: Googlebot
Disallow: /

User-agent: *
Allow: /
```

Interprétation retenue : `Disallow: /` sur `User-agent: Googlebot` est normal sur ce domaine provisoire `*.hostingersite.com`. PSI naviguant avec un agent Chromium (et non Googlebot), il ne devrait pas être bloqué ; ce point n’est à réexaminer que si un appel PSI de `PHASE 2c` revient en `NO_FCP` ou `403`.

---

## 2. Contrôles exécutés et protocole consolidé `PHASE 1 → PHASE 2b`

### 2.1 Checklist `PHASE 1` (à appliquer par la propriétaire dans hPanel avant `« cache activé »`)

1. **Désactiver `Cache bypass`** dans hPanel → CDN / Performances *(propriétaire uniquement ; l’assistant n’effectue aucune modification CDN)*.
2. **TTL HTML impératif (ajout obligatoire nonce) :**
   - Configurer le **TTL HTML edge à `1 h`** (`3600 s`), **jamais au-delà de `12 h`**, et **ne jamais forcer un TTL supérieur au `Cache-Control: public, max-age=43200` déjà émis par l’origine** (`class-cache.php:227`), afin de prévenir toute expiration du nonce `pk_public` dans les pages HTML servies en `HIT`.
3. **Paramètres de recherche / query strings — DÉCISION ARRÊTÉE : OPTION B (`?*` → `BYPASS`) :**
   - **Toute URL portant une query string (`?*`) est en `BYPASS`** et passe à l’origine (jamais de cache par query string libre, et jamais d’option « Ignore Query String »).
   - **Motif :** l’Option A produit une cardinalité non bornée (`?es_city=...&tri=...&utm_source=...`), faisant de toute combinaison inédite un `MISS` qui atteint PHP et ouvre une connexion MariaDB — ce qui serait exploitable pour contourner le cache par construction.
   - **Conséquence assumée :** les pages filtrées (`/fr/annonces/?es_city=casablanca`, etc.) **restent plafonnées à `~20 req/s`** (limite hébergeur MariaDB). Le correctif durable — un filtrage canonique en chemin (`/fr/annonces/<ville>/`) — relève d’un prochain cycle produit, hors périmètre de `4f14acd9`.
4. **Contournement obligatoire (`BYPASS`) sur cookie de session WordPress / authentification :**
   - Cookie obligatoire : **`wordpress_logged_in_*`** (plus `wordpress_sec_*`, `wp-postpass_*`, `comment_author_*`, `pk_admin_access*`, `pk_v_*` cohérents avec `class-cache.php:727`).
   - Côté origine, `Partikulier_Cache::private_when_logged_in()` (`class-cache.php:188-195`) émet déjà `X-LiteSpeed-Cache-Control: no-cache` et `Cache-Control: private, no-store, max-age=0`.
5. **Interdiction absolue de mise en cache sur en-têtes privés ou cookies émis :**
   - Ne **jamais** cacher une réponse contenant `Cache-Control: private`, `no-store`, `no-cache` ou un en-tête **`Set-Cookie`**, ni les `3xx` contextuels (`/`, `/mes-annonces/` anonyme) ou `4xx`/`5xx`.
6. **Contournement explicite des routes privées, d’administration et de tunnel :**
   - `/wp-admin/*` (dont `/wp-admin/admin-ajax.php`), `/wp-login.php*`, `/wp-json/*`, méthodes hors `GET`/`HEAD`, racine `/` (`302` langue dans `functions.php:141-162`).
   - Parcours dans les 3 langues (`fr`, `en`, `ar`, slugs identiques confirmés dans `class-required-page-translations.php:14,26-31`) et alias (`class-cache.php:200,595,723`, `class-page-doctor.php:57-69`) :
     - `/*/mes-annonces*`, `/mes-annonces*` *(+ alias `mon-espace*`, `dashboard*`, `tableau-de-bord*`)*
     - `/*/connexion*`, `/connexion*`
     - `/*/deposer*`, `/deposer*` *(+ alias `deposer-une-annonce*`, `deposer-annonce*`, `publier-une-annonce*`)*
     - `/*/favoris*`, `/favoris*` *(+ alias `mes-favoris*`)*
     - `/*/contact*`, `/contact*`

### 2.2 Protocole bloquant `PHASE 2 → PHASE 3 → PHASE 4` (uniquement après message `« cache activé »`)

1. **`PHASE 2a` :** 3 `GET` exacts (`2 × /fr/annonces/`, `1 × style.css`) avec relevé brut complet (statut, `x-hcdn-*`, durée, taille, SHA-256). Si `BYPASS` persiste après désactivation : preuve pour ticket Hostinger, pas un échec de l’application.
2. **`PHASE 2b` (BLOQUANTE avant `2c` et avant toute charge) :**
   - **Contrôle de fuite (arrêt total immédiat si anomalie) :**
     - `/fr/mes-annonces/` anonyme doit rediriger vers la connexion et **JAMAIS** retourner `200` avec du contenu privé/tableau de bord ;
     - une requête avec cookie `wordpress_logged_in_*` factice doit recevoir `BYPASS` ;
     - aucune réponse portant `Set-Cookie` ne doit être marquée `HIT`.
     - *Si le contrôle de fuite montre un tableau de bord servi à un anonyme : arrêt immédiat, demande de désactivation du cache, et placement en tout premier paragraphe du rapport.*
   - **Contrôle nonce ajouté (arrêt avant mesure si `403`) :**
     - effectuer **deux chargements** de `/fr/annonces/`, extraire le nonce `pkConfig.nonce` du HTML servi, puis vérifier que **`POST /wp-admin/admin-ajax.php` (`action=pk_find_count`) répond toujours `HTTP 200`** (JSON `success: true`) et **non `403`**. Un `403` ici = cache trop long ou nonce non rafraîchi → **arrêt, aucune mesure suivante lancée**.
3. **`PHASE 2c` :** 3 appels API Google PageSpeed Insights (`strategy=mobile`, `category=performance`) sur `/fr/` avec les 3 valeurs brutes, la dispersion et le rappel que l’environnement Google n’est pas directement comparable au score `62` du run `42886286352`. *(Ne réexaminer `/robots.txt` que si PSI renvoie `NO_FCP` ou `403`.)*
4. **`PHASE 3` :** replay `gh workflow run audit-uat.yml --ref audit-2.10.16-6.20.214-3 -f load_test=false` (même ref `-3`, sans `-4`, sans note/seuil inventé).
5. **`PHASE 4` :** rampe ascendante bornée unique avec disjoncteurs (`> 50 %` de `5xx` sur fenêtre glissante `≥ 30` req ou `≥ 5 s`, `p95 > 5 s` pendant `10 s`, palier de contrôle `5–10 req/s` arrêté à `3` `5xx` consécutives), en consignant débits atteints, codes HTTP exacts et réutilisation ou non des connexions HTTP, et en distinguant explicitement les URLs sans query string (cachées) des URLs filtrées `?*` (volontairement en `BYPASS` Option B).

---

## 3. Non exécutés et échecs avec cause

| Élément | Statut | Cause exacte |
|---|---|---|
| Activation cache CDN hPanel (`PHASE 1`) | **`NON EXÉCUTÉ`** | Non activée par la propriétaire ; attente explicite du message `« cache activé »` |
| Vérification `DB_HOST` (`p:`) dans `wp-config.php` | **`NON EXÉCUTÉ`** | Fichier hors dépôt Git et inaccessible par HTTP anonyme |
| `PHASE 2` (`2a`, `2b` fuite + nonce `pk_find_count`, `2c` 3× PSI), `PHASE 3` (`audit-uat.yml` `load_test=false`), `PHASE 4` (rampe bornée) | **`NON EXÉCUTÉ`** | Verrou strict en attente du signal propriétaire `« cache activé »` |
| Texte brut mot pour mot du courriel Hostinger | **`NON EXÉCUTÉ` (indisponible)** | Message reçu sous forme de synthèse non verbatim uniquement |

---

## 4. Portée exacte

- **Portée couverte :** `PHASE 0` achevée en lecture seule sur `4f14acd9` + `GET /robots.txt` UAT ; décisions propriétaire intégrées (**Option B** `?*` → `BYPASS`, `date et auteur non établis`, `synthèse du retour support, non verbatim`, **TTL HTML `1 h` / max `12 h`**, contrôle bloquant **`pk_find_count` `200` vs `403` en `PHASE 2b`**).
- **Invariants intacts :** `audit-2.10.16-6.20.214-3` → `4f14acd9` (base `c9e1413`), aucun tag `-4`, aucun patch, `SEUILS-VALIDATION-UAT.md` v1.0 inchangé — **Note NON CALCULABLE — Release NON VALIDÉE**.
- **Déclencheur de la suite :** envoi par la propriétaire du message **`« cache activé »`** après configuration dans hPanel.
