# Archive retour Hostinger (10/11) + PHASE 0 (inventaire lecture seule) + PHASE 1 (checklist hPanel)

**Horodatage :** `2026-11-10T20:55:00Z`  
**Ref audité (inchangé) :** tag `audit-2.10.16-6.20.214-3` (`1f9e41d5…`) → commit `4f14acd9b56c93a798e06ca5f23cabdb185b6674` (base `c9e1413` ; zéro modification de code, de tag, de workflow ou de seuils).  
**Branche documentaire :** `audit/harnais-serie-e-seuils` (`docs/audit/` strictement hors du ref audité).  
**Statut global :** **Note NON CALCULABLE — Release NON VALIDÉE** (`SEUILS-VALIDATION-UAT.md` v1.0 inchangé).

---

## 1. Mesures, faits Hostinger établis et constats lecture seule (PHASE 0)

### 1.1 Archive du retour support Hostinger (10/11/2026)

*Note de traçabilité : les faits techniques ci-dessous reprennent exactement les éléments établis transmis par le support Hostinger le 10/11 et intégrés à la session. Le texte brut mot pour mot du courriel d’origine (antérieur à la compaction de session) n’est pas présent en fichier dans le dépôt ; si un miroir textuel intégral mot à mot est souhaité en annexe, il pourra être collé tel quel sans réécriture.*

Faits établis côté hébergeur (traités comme socle acquis) :

| Élément | Valeur / constat Hostinger (10/11) | Portée technique |
|---|---|---|
| Réglage CDN `Cache bypass` | **Actif** (mode développeur inactif) | Explique le `x-hcdn-cache-status: BYPASS` généralisé mesuré en Étape 2a sur `/fr/annonces/` et `style.css` |
| Plugin WordPress | `LiteSpeed Cache` actif côté WP | En-tête `x-litespeed-cache` **non observable sur ces réponses publiques** derrière `hcdn` |
| Offre / ressources | **Cloud Startup** : `4` cœurs CPU, `4 Go` RAM, `100` workers PHP, `200` processus, base MariaDB `16 Mo / 6 Go` | **Aucune saturation** CPU, RAM, processus ou workers PHP, et **aucune suspension** |
| Plafond MariaDB hébergeur | Plans **Web** et **Cloud** limités à **`20` nouvelles connexions MariaDB par seconde** | Cause probable des réponses `500` (`Databas...`) quand PHP/AJAX ouvre des connexions DB |
| Top trafic observé côté serveur | `/fr/annonces/`, `/fr/`, `/fr/annonces/?es_city=casablanca`, `/wp-admin/admin-ajax.php` | Confirme que le trafic public et `admin-ajax.php` sollicitaient directement PHP + MariaDB |

**Conséquence arithmétique explicite :**  
Avec une requête PHP non cachée ouvrant `1` connexion MySQL, le plafond de l’origine sans cache est **`~20 req/s`** (même alors que les `4` cœurs CPU, les `4 Go` de RAM et les `100` workers PHP ne sont pas saturés). Seul le fait d’**empêcher les requêtes anonymes d’atteindre PHP** (mise en cache edge HCDN) lève ce plafond sur les URLs publiques cachables. À l’inverse, toute requête qui continue d’atteindre PHP et MariaDB (`POST /wp-admin/admin-ajax.php`, sessions connectées, pages privées, requêtes non cachées) reste bornée par le plafond hébergeur de `20` nouvelles connexions MariaDB/s.

**Question ouverte à la propriétaire (obligatoire avant toute qualification) :**  
`Cache bypass` n’est **pas** qualifié de « volontaire » : **à quelle date et par quel auteur ce réglage `Cache bypass` a-t-il été activé dans hPanel ?**

---

### 1.2 Inventaire exhaustif `wp_ajax_*` / `admin-ajax.php` sur `4f14acd9` (PHASE 0)

Recherche effectuée sur l’intégralité du commit `4f14acd9` (`theme/` et `plugin/`).  
Total recensé : **9 actions `wp_ajax_*`** (8 avec variante `wp_ajax_nopriv_*`, 1 réservée aux utilisateurs connectés) dans `theme/partikulier/inc/`, **0 action AJAX** enregistrée dans `plugin/partikulier-core/`, plus **1 fetch XHR archive** hors `admin-ajax.php`.

| Action (`action=`) | Enregistrement (`4f14acd9`) | `nopriv` | Nonce vérifié | Appelants JS (`4f14acd9`) | Déclenchée au **chargement initial** de `/fr/` ou `/fr/annonces/` ? | Impact DB / effet |
|---|---|---|---|---|---|---|
| `pk_find_count` | `inc/class-search-filters.php:45-46` (`ajax_count` `:1268-1297`) | Oui | `pk_public` (`check_ajax_referer`) | `assets/js/main.js:1505` (`.pk-find`) → `:2022-2047` (`scheduleCount()` à la fin de l’IIFE, `220 ms` après init) | **OUI — AUTOMATIQUE sur `/fr/` ET `/fr/annonces/`** (et `/en/`, `/ar/`) : prouvé dans les **5/5 JSON Lighthouse** du run `42886286352` (`POST /wp-admin/admin-ajax.php → 200`, `656–657` o) | Exécute `matching_count()` (`WP_Query` + `$wpdb`) à chaque ouverture réelle de page navigateur |
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

**Deux conséquences critiques de l’inventaire AJAX pour l’activation du CDN :**
1. **Écart « sonde HTTP brute vs trafic navigateur réel » :** même lorsque les `GET /fr/` et `GET /fr/annonces/` sont servis en `HIT` edge par HCDN, chaque visiteur navigateur réel continue d’émettre automatiquement **`1 × POST /wp-admin/admin-ajax.php` (`pk_find_count`)** à `t + 220 ms` (plus `pk_visit_count` s’il a déjà `pk_measure=yes`), atteignant PHP et MariaDB.
2. **Contrainte stricte de TTL sur les pages HTML cachées :** `pk_find_count` (`class-search-filters.php:1269`) et `pk_sync_favorite` / `pk_favorites_list` exigent `check_ajax_referer('pk_public', 'nonce')` avec `pkConfig.nonce` injecté dans le HTML (`class-scripts.php:56`). Un nonce WordPress vivant `12–24 h` et `Partikulier_Cache::TTL` étant fixé à `43 200 s` (`12 h`, `class-cache.php:20,227`), HCDN **ne doit pas forcer de TTL HTML > 12 h** sous peine de casser silencieusement `pk_find_count` (erreur `403`, même mécanisme que celui documenté dans `class-morocco-places.php:393-399`).

---

### 1.3 Accès base de données et vérification `DB_HOST` (`p:`) (PHASE 0)

- **Code versionné (`4f14acd9`, `theme/` + `plugin/`) :** `0` appel direct `mysqli_connect`, `new mysqli`, `new PDO` ou `new wpdb(...)`. Toutes les requêtes SQL passent exclusivement par le `$wpdb` global de WordPress (`DB_HOST` défini dans `wp-config.php`).
- **`wp-config.php` :** fichier hors dépôt Git. La présence ou non du préfixe de connexion persistante `p:` dans `DB_HOST` est **`NON EXÉCUTÉ`** depuis le dépôt Git ou en HTTP anonyme (vérification lecture seule à effectuer côté serveur par la propriétaire via SSH ou Gestionnaire de fichiers hPanel ; remarque : en présence de `100` workers PHP et d’un plafond de `20` nouvelles connexions/s, la mise en cache des requêtes anonymes à l’edge HCDN reste la priorité avant toute expérimentation sur `p:` qui dépend du paramétrage exact de `max_user_connections` MariaDB).

---

### 1.4 Pré-contrôle `/robots.txt` sur UAT (pour préparation PHASE 2c)

Sonde lecture seule effectuée le **`2026-11-10T20:54:26Z`** sur `https://darkcyan-yak-151002.hostingersite.com/robots.txt` :

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

Constat factuel : cette réponse vient directement de `hcdn` (environnement temporaire `*.hostingersite.com`) et bloque explicitement `User-agent: Googlebot` (`Disallow: /`), ce qui est à documenter d’emblée avant les 3 appels **Google PageSpeed Insights API (`PHASE 2c`)**.

---

## 2. Contrôles exécutés et checklist pré-activation hPanel (PHASE 1 — à appliquer par la propriétaire)

**Périmètre :** lecture seule dépôt Git (`4f14acd9`) + lecture seule `GET /robots.txt` sur UAT. **Zéro** modification de code, de tag, de workflow ou de réglage CDN par l’assistant.

### Checklist exacte PHASE 1 (actions propriétaire dans hPanel avant `PHASE 2`)

1. **Activer la mise en cache CDN en désactivant `Cache bypass` dans hPanel** *(action propriétaire uniquement)* :
   - Désactiver `Cache bypass` dans hPanel → CDN / Performances.
   - Ne pas appliquer d’override TTL HTML supérieur à `12 h` (`max-age=43200` déjà émis par `class-cache.php:227` pour préserver la validité des nonces `pk_public`).
2. **Contournement obligatoire (`BYPASS`) sur cookie de session WordPress / authentification :**
   - Cookie obligatoire : **`wordpress_logged_in_*`** (toute requête portant un cookie commençant par `wordpress_logged_in_` doit contourner le cache CDN).
   - Par cohérence avec `class-cache.php:727`, contourner également les cookies `wordpress_sec_*`, `wp-postpass_*`, `comment_author_*`, `pk_admin_access*`, `pk_v_*`.
   - Côté origine, `Partikulier_Cache::private_when_logged_in()` (`class-cache.php:188-195`) émet déjà `X-LiteSpeed-Cache-Control: no-cache` et `Cache-Control: private, no-store, max-age=0` dès que `is_user_logged_in()` est vrai.
3. **Interdiction absolue de mise en cache sur en-têtes privés ou cookies émis :**
   - Ne **jamais** cacher une réponse contenant `Cache-Control: private`, `Cache-Control: no-store` ou `no-cache`.
   - Ne **jamais** cacher ni rejouer une réponse contenant un en-tête **`Set-Cookie`**.
   - Ne **jamais** cacher les codes `3xx` sensibles au contexte ni les `4xx` / `5xx`.
4. **Contournement explicite des routes d’administration, d’authentification et des parcours privés/tunnel :**
   - Endpoints techniques WordPress/API : `/wp-admin/*` (dont `/wp-admin/admin-ajax.php`), `/wp-login.php*`, `/wp-json/*` (et toute méthode hors `GET`/`HEAD`).
   - Pages privées et tunnel dans les 3 langues (`fr`, `en`, `ar`, slugs identiques confirmés dans `class-required-page-translations.php:14,26-31`) et alias historiques (`class-cache.php:200,595,723`, `class-page-doctor.php:57-69`) :
     - `/fr/mes-annonces*`, `/en/mes-annonces*`, `/ar/mes-annonces*`, `/mes-annonces*` *(+ alias `mon-espace*`, `dashboard*`, `tableau-de-bord*`)*
     - `/fr/connexion*`, `/en/connexion*`, `/ar/connexion*`, `/connexion*`
     - `/fr/deposer*`, `/en/deposer*`, `/ar/deposer*`, `/deposer*` *(+ alias `deposer-une-annonce*`, `deposer-annonce*`, `publier-une-annonce*`)*
     - `/fr/favoris*`, `/en/favoris*`, `/ar/favoris*`, `/favoris*` *(+ alias `mes-favoris*`)*
     - `/fr/contact*`, `/en/contact*`, `/ar/contact*`, `/contact*`
   - Racine `/` : en `4f14acd9`, `functions.php:141-162` émet un `302` (`301` pour bots) selon le cookie `pll_language` ou `Accept-Language` → conserver `/` hors cache public (ou ne jamais cacher le `302` vers `/fr/` pour les visiteurs `en`/`ar`).
   - Nota : côté thème, `class-cache.php:200` émet déjà `Cache-Control: private, no-store, max-age=0` + `X-LiteSpeed-Cache-Control: no-cache` sur `(fr|en|ar)/(deposer|mes-annonces|favoris|connexion)` ; en revanche les alias `mon-espace`, `dashboard`, `tableau-de-bord`, `publier-une-annonce`, `mes-favoris` ne sont pas dans la regex `:200` et doivent donc impérativement être couverts par les règles de contournement hPanel / le respect strict des en-têtes.
5. **Décision explicite requise de la propriétaire sur les query strings de filtrage (`/fr/annonces/?...`) :**
   - Exemples réels du trafic et des tests : `/fr/annonces/?es_city=casablanca`, `/fr/annonces/?es_action=...&es_type=...&pk_order=...`, `/fr/annonces/?pk_audit=<val>` (`audit-uat.mjs:48`, `audit-uat-interactions.mjs:76`, `audit-uat.yml`, `main.js:2574`).
   - **Option A :** Inclure la query string complète dans la clé de cache edge (*Cache by full URL / query string* — et **jamais** « Ignore Query String », qui servirait `/fr/annonces/` sans filtre à la place de `?es_city=casablanca`).
   - **Option B :** Contourner (`BYPASS`) toute URL comportant une query string (`?*`) : `/fr/` et `/fr/annonces/` nus seront mis en cache (`HIT`), tandis que `/fr/annonces/?es_city=casablanca` passera à l’origine (avec impact direct sur le plafond MariaDB `20/s` si cette URL est fortement sollicitée).
   - **Arbitrage demandé à la propriétaire avant `PHASE 2` :** indiquer Option A ou Option B (ou la granularité exacte disponible dans hPanel pour les query strings).

---

## 3. Éléments non exécutés et échecs avec cause

| Élément | Statut | Cause exacte |
|---|---|---|
| Vérification du préfixe persistant `p:` sur `DB_HOST` dans `wp-config.php` | **`NON EXÉCUTÉ`** | `wp-config.php` est hors du dépôt Git et inaccessible par HTTP anonyme ; requiert un contrôle serveur propriétaire (SSH / File Manager) |
| Désactivation de `Cache bypass` et paramétrage des règles CDN hPanel (`PHASE 1`) | **`NON EXÉCUTÉ`** | Périmètre strictement réservé à la propriétaire dans hPanel (l’assistant ne modifie pas la config CDN) |
| `PHASE 2` (`2a` re-sonde HCDN, `2b` contrôles bloquants de fuite, `2c` 3× PSI API mobile), `PHASE 3` (`audit-uat.yml` `load_test=false`) et `PHASE 4` (rampe bornée unique) | **`NON EXÉCUTÉ`** | Bloqués en attente de la confirmation explicite de la propriétaire après application de `PHASE 1` dans hPanel |
| Vérification du texte brut mot pour mot original du courriel Hostinger | **`NON EXÉCUTÉ` (archive partielle structurée)** | Compaction de session antérieure ; seuls les faits techniques établis figurant dans le contexte de session ont été archivés sans reconstruction |

---

## 4. Portée exacte et prochaine étape

- **Périmètre exact :** `PHASE 0` terminée en lecture seule sur `4f14acd9` (`audit-2.10.16-6.20.214-3`, inchangé) + `GET /robots.txt` sur `https://darkcyan-yak-151002.hostingersite.com` (`2026-11-10T20:54:26Z`). Checklist `PHASE 1` livrée pour application propriétaire dans hPanel.
- **Décision / validation / score inchangés :** `SEUILS-VALIDATION-UAT.md` v1.0 inchangé — **Note NON CALCULABLE — Release NON VALIDÉE**.
- **Pour débloquer `PHASE 2 → PHASE 3 → PHASE 4`, la propriétaire doit confirmer :**
  1. La **date** et l’**auteur** de l’activation initiale du réglage `Cache bypass` (plus, si souhaité, le texte brut du message Hostinger à annexer tel quel).
  2. La **désactivation effective de `Cache bypass`** dans hPanel avec les règles de contournement ci-dessus (`wordpress_logged_in_*`, `Set-Cookie`/`private`/`no-store`, `/wp-admin/`, `wp-login.php`, `/wp-json/`, routes `deposer`/`mes-annonces`/`favoris`/`connexion`) et le **choix retenu (Option A ou Option B) sur les query strings** (`/fr/annonces/?es_city=casablanca`).
