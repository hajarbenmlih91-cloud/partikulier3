# Diagnostic V1 Préproduction UAT (lecture seule) & Simulation éphémère n8n / WhatsApp

- **Date :** 2026-11-11
- **Base applicative auditée :** `c9e1413` (`4f14acd9` pour le harnais d'audit ; aucune modification de code applicatif, de `c9e1413`, `4f14acd9`, `main`, `develop`, `Cache bypass`, hPanel, CDN ou WAF)
- **Verdict global V1 préproduction :** **NON VALIDÉ** (conforme au référentiel `SEUILS-VALIDATION-UAT.md` v1.0 — **Note NON CALCULABLE — Release NON VALIDÉE**)
- **Preuves CI associées :**
  - Diagnostic UAT lecture seule (`audit/uat-diag-v1`, commit `12ad1b3`, environnement `uat` approuvé manuellement puis politique temporaire `66301849` supprimée aussitôt) : run [`43002274553`](https://github.com/hajarbenmlih91-cloud/partikulier3/actions/runs/43002274553) (**`success`**) — artefact local `/home/user/uat-diag-v1-report/` (`wp-diag.txt`, `lighthouse-analysis.md`, 4 JSON Lighthouse `12.8.2`).
  - Simulation éphémère Docker `MariaDB + WordPress c9e1413 + n8n 2.46.6` (`audit/ephemeral-wa-n8n-sim`, commit `3b50c36`, 0 secret, egress isolé par `iptables`, 0 appel Meta/Google/UAT) : run [`43004334278`](https://github.com/hajarbenmlih91-cloud/partikulier3/actions/runs/43004334278) (**`success`**, **25/25 PASS**) — artefact local `/home/user/ephemeral-wa-sim-artifacts/ephemeral-wa-n8n-sim-report/`.

---

## 1. Synthèse des résultats du diagnostic UAT lecture seule (`43002274553`)

### 1.1 Parcours WhatsApp & lien `wa.me/?text=...` sur les annonces UAT
- **Constat mesuré (`WP-CLI` lecture seule, sans exposition de numéro) :**
  - `Partikulier_Settings::buyer_whatsapp_number` : **`set=no` (`digits_len=0`)**.
  - `pk_lead_settings_present=no`.
  - `pk_n8n_settings` : `hmac_mode=off`, `active_key_id=unset`, `webhook_set=no`.
  - Sur l'annonce exemple `post_id=191` : `Partikulier_Buyer_Qualification::contact_url(191)` retourne bien une chaîne vide (`contact_url_present=no`, `recipient_digits_len=0`), et le gabarit actif est `theme/partikulier/estatik4/front/property/single.php`.
- **Cause racine exacte des 6 échecs `browser-audit.json` (`fr`, `en`, `ar`) :**
  - Dans `theme/partikulier/estatik4/front/property/single.php` (lignes 83–88 et 435–447), lorsque `buyer_whatsapp_number` n'est pas configuré (`$buyer_contact_url === ''`), un repli explicite génère `https://wa.me/?text=...` (sans numéro destinataire) au lieu de masquer le bouton WhatsApp.
- **Correctif attendu hors gel :** renseigner le numéro WhatsApp professionnel attendu depuis la source de configuration canonique en préproduction/production **et** masquer le CTA `wa.me` lorsqu'aucun destinataire valide n'est configuré plutôt que de rendre `wa.me/?text=...`.

### 1.2 Anomalie cache LiteSpeed sur `/wp-json/partikulier/v1/health` et `/fr/contact/`
- **Constat mesuré (`WP-CLI` + `curl -s -D -`) :**
  - Options actives sur UAT : `litespeed.conf.cache=1`, **`litespeed.conf.cache-rest=1`**, **`litespeed.conf.cache-ttl_rest=604800`** (7 jours), `litespeed.conf.cache-priv=1`, **`litespeed.conf.cache-exc=[]`** (aucune URI exclue).
  - Règles `.htaccess` LSCache : l'exclusion `/wp-json` ne s'applique que si `HTTP:Authorization` est non vide (`RewriteCond %{HTTP:Authorization} !^$`), pas aux appels anonymes sur `/wp-json/partikulier/v1/health`.
  - Sur `GET /wp-json/partikulier/v1/health` (#1 et #2) : PHP émet `cache-control: no-store, no-cache, must-revalidate, max-age=0`, **mais LiteSpeed Cache surcharge avec `x-litespeed-cache-control: public,max-age=550275` (`x-litespeed-tag: 70b_default,...,70b_REST,70b_`) et sert `x-litespeed-cache: hit`**, tandis que HCDN est bien en `x-hcdn-cache-status: BYPASS` (prouvant que le `HIT` provient uniquement de LiteSpeed à l'origine).
  - Sur `GET /fr/contact/` (#1 et #2) : réponse `x-litespeed-cache-control: no-cache`, `x-hcdn-cache-status: BYPASS`, **sans** en-tête HTTP standard `Cache-Control: no-store, no-cache` visible.
- **Note complémentaire :** `WP_CACHE=true`, `object-persistent=1` (Memcached `localhost:11211`), et `wp-content/uploads/partikulier-cache/` contient des fichiers HTML/gz actifs pour `/fr/`, `/en/`, `/ar/`, `/fr/annonces/`, etc.

### 1.3 Reproduction Lighthouse `12.8.2` (3× `fr-home-mobile` + 1× `en-home-mobile`)

| Mesure | Run | Perf | A11y | BP | SEO | `benchmarkIndex` | LCP (ms) | TBT (ms) | FCP (ms) | TTFB (ms) | CLS |
|---|---|---:|---:|---:|---:|---:|---:|---:|---:|---:|---:|
| Baseline `42886286352` | `fr-home-mobile` | **62** | — | — | **100** | 2533 | 3130 | **2588** | — | 261 | — |
| Diag `43002274553` | `fr-home-mobile-1` | **78** | 100 | 96 | 69 | 1918 | 3711 | **347** | 2421 | 263 | 0 |
| Diag `43002274553` | `fr-home-mobile-2` | **97** | 100 | 96 | 69 | 2467 | 2366 | **14** | 1613 | 412 | 0 |
| Diag `43002274553` | `fr-home-mobile-3` | **96** | 100 | 96 | 69 | 2476 | 2643 | **15** | 1588 | 99 | 0 |
| Baseline `42886286352` | `en-home-mobile` | 97 | — | — | **92** | 2865 | 2455 | 2 | — | 201 | — |
| Diag `43002274553` | `en-home-mobile` | **97** | 100 | 96 | **61** | 2428 | 2482 | **14** | 1428 | 583 | 0 |

- **Explication prouvée de `Perf 62 / TBT 2588 ms` (`42886286352`) :**
  - Le poids réseau et le nombre de requêtes de `/fr/` sont quasiment identiques (`657 405 o` / 20 req en baseline vs `657 175 o` / 19 req en diag : 1 script `main.js`, 1 feuille CSS `style.css` bloquante ~450 ms, 4 polices).
  - En baseline `42886286352` (lancement à froid juste après la charge `load-full`, `browser-audit`, `interactions` et `owner-journey`), la répartition thread principal était dominée par `Other: 2 529 ms` et `Style & Layout: 1 716 ms` (`bootup-time` `/fr/`: `3 086 ms` total dont `369 ms` JS ; `Unattributable`: `1 737 ms` dont `22 ms` JS ; `main.js`: `521 ms` dont `109 ms` JS).
  - Sur nos 3 exécutions consécutives à l'identique (`lighthouse@12.8.2`) : le 1er tir (`benchmarkIndex 1918`) retombe à `Perf 78 / TBT 347 ms` (`Other: 1 340 ms`, tâches longues `Unattributable` 360 ms & 254 ms), puis les tirs #2 et #3 se stabilisent immédiatement à **`Perf 97 / TBT 14 ms`** et **`Perf 96 / TBT 15 ms`**.
  - **Conclusion :** `62` / `TBT 2588 ms` n'est pas dû à une surcharge JS applicative mais à une **contention CPU / démarrage à froid de Chromium sur le runner CI** (à surveiller toutefois : `style.css` reste bloquant au rendu ~450 ms et `/favicon.ico` retourne `404`, causant `Best Practices: 96`).
- **Explication prouvée des scores SEO (`92` puis `69`/`61`) :**
  - **`link-text` (`0`, responsable du `92` en baseline et d'un second échec sur `en-home-mobile`) :** dans `theme/partikulier/inc/class-visit-counter.php` (lignes 57 et 78–100), la bannière cookies anglaise émet `<a href="https://darkcyan-yak-151002.hostingersite.com/fr/cookies/">Learn more</a>` (libellé générique `Learn more` **et** lien pointant vers `/fr/cookies/` sur la page `/en/`).
  - **`is-crawlable` (`0`, nouvel échec dans `43002274553` ramenant FR `100 -> 69` et EN `92 -> 61`) :** l'en-tête HTTP **`x-robots-tag: noindex, nofollow`** est désormais renvoyé sur UAT (`curl -sI /fr/` le confirme en direct). Normal pour un domaine de préproduction non indexable, ce qui confirme que la validation SEO finale doit impérativement être conduite sur le domaine HTTPS de lancement avec `x-robots-tag` retiré, `robots.txt` ouvert, sitemap propre, canoniques et `hreflang` vérifiés.

### 1.4 WP-Cron, e-mail et inventaire / intégrité des sauvegardes SSH
- **WP-Cron :** `DISABLE_WP_CRON=UNDEF`, `ALTERNATE_WP_CRON=UNDEF` (dépend du trafic HTTP sauf cron système externe). Tâches actives : `pk_recommendation_rebuild` (`1 day`), `partikulier_core_cleanup` (`1 day`), `pk_buyer_privacy_purge` (`1 day`), `pk_owner_insights_daily_purge` (`1 day`). **`pk_sheets_export_daily` est absente de `wp cron event list`.**
- **E-mail :** `admin_email_domain=@gmail.com`, `mailserver_url_set=yes`, aucun plugin SMTP (`wp_mail_smtp`, `fluentmail-settings`, `post_smtp_options`) configuré (`NON VALIDÉ` tant qu'un envoi/réception sur boîte test capturée + SPF/DKIM/DMARC n'a pas été exécuté).
- **Sauvegardes SSH (`~/backups/partikulier/`) :**
  - Espace disque `/home/u987613820` : `21T` total, `15T` utilisé, `6.6T` dispo (`69%`). Tailles live : `uploads` = `33M`, `partikulier-core` = `1.4M`, `partikulier` = `10M`.
  - **5 sauvegardes présentes** (`20261108T030709Z` → `20261109T014750Z-c9e141376179`).
  - Vérification d'intégrité de la plus récente (`20261109T014750Z-c9e141376179`, `5.5M`) :
    - `database.sql.gz` (`226K`) : **`gzip -t` OK**, `54` instructions `CREATE TABLE`.
    - `partikulier-core.tar.gz` (`240K`) : **`tar -tzf` OK**, `96` entrées.
    - `partikulier.tar.gz` (`5.1M`) : **`tar -tzf` OK**, `224` entrées.
  - **`wp-content/uploads/` (`33M`) est absent de `~/backups/partikulier/`** (comportement attendu de `deploy.yml`) : la sauvegarde des médias dépend donc entièrement des **sauvegardes Hostinger hPanel / externes**, dont la vérification et le test de restauration isolé restent **`NON EXÉCUTÉ`** à date.

---

## 2. Synthèse de la simulation éphémère n8n + parcours WhatsApp (`43004334278`)

La simulation complète (`25/25 PASS`, `MariaDB 10.11 + WordPress 6.6 + n8n 2.46.6`, zéro secret UAT/prod, egress `n8n` bloqué par `iptables` hors TCP/80 du WordPress jetable) a validé de bout en bout les contrats WordPress REST ↔ n8n :

| Étape | Scénario | HTTP | Résultat |
|---|---|---:|---|
| `S01_approved_listings` | `GET /approved-listings` retourne 3 annonces synthétiques | 200 | **PASS** (`count: 3`) |
| `S02_auth_need_qual` | 1er message acheteur A -> demande qualification | 200 | **PASS** (`allowed: false`, `reason: need_qualification`) |
| `S03_qual_particulier` | Enregistrement qualification `particulier` | 200 | **PASS** (`qualified: particulier`) |
| `S04_auth_owner1_ok` | Contact propriétaire 1 autorisé (1/2) + contact retourné | 200 | **PASS** (`allowed: true`, `replayed: false`) |
| `S05_auth_msg_replay` | Rejeu exact même `provider_message_id` | 200 | **PASS** (`reason: duplicate_message`) |
| `S06_preferences` | Enregistrement préférences acheteur A | 200 | **PASS** (`updated: true`) |
| `S07_consent` | Enregistrement consentement `similar_listings` | 200 | **PASS** (`consent: granted`) |
| `S08_auth_owner2_ok` | Contact propriétaire 2 autorisé (2/2) | 200 | **PASS** (`allowed: true`, `replayed: false`) |
| `S09_auth_3rd_manual` | 3e contact en 24 h basculé en revue manuelle | 200 | **PASS** (`reason: manual_review`, `limit: 24h_3contacts`) |
| `S10_opt_out_stop` | Opt-out `STOP` enregistré | 200 | **PASS** (`processed: true`, `known_lead: true`) |
| `S11_auth_after_stop` | Blocage effectif après `STOP` | 200 | **PASS** (`reason: opted_out`) |
| `S12`–`S15` | Acheteur B : `need_qualification` -> `intermediaire` -> `intermediary_refused` -> changement bloqué `409` | 200 / 409 | **PASS** (`pk_qualification_already_set`) |
| `S15b`–`S18` | Acheteur C : `404 pk_unknown_lead` avant init -> `need_qualification` -> `need_qualification_pending` -> `particulier` -> proprio 1 -> redemande même annonce nouveau `wamid` | 404 / 200 | **PASS** (`allowed: true`, `replayed: true`) |
| `S19`–`S20` | `POST /automation-event` (`event_id` préfixé `n8n-`) accepté puis rejeu idempotent | 200 | **PASS** (`duplicate: false` puis `true`) |
| `S21a` | Rejet `POST /erase-lead` sans secret dédié `lead_erase_api_secret` | 401 | **PASS** |
| `S21b` | Effacement RGPD acheteur A (`X-Partikulier-Lead-Erase`) + maintien B et C | 200 | **PASS** (`buyerA_leads_left: 0`, `pk_buyer_leads: 3 -> 2`, B/C conservés) |

**Observations métier vérifiées en conditions réelles :**
1. Une redemande idempotente sur une **même annonce** avec un **nouveau `provider_message_id`** (`replayed: true`, sans incrémenter `pk_contact_limits`) insère néanmoins une ligne dans `pk_interest_events`, décomptée du plafond 24 h (`max_24h=2`) dans `LeadsContactAuthorizationTrait`.
2. `POST /automation-event` avec `source: "n8n"` impose un `event_id` commençant par `n8n-` (sinon HTTP 400 `pk_automation_payload`).
3. `POST /erase-lead` exige le secret dédié `lead_erase_api_secret` (`X-Partikulier-Lead-Erase`), distinct du secret HMAC n8n (HTTP 401 sinon).

---

## 3. État des critères de validation V1 préproduction (règles corrigées)

- **Verdict global :** **NON VALIDÉ** (respect strict de `NON EXÉCUTÉ` ≠ `ÉCHEC` ; aucun seuil non approuvé `Perf >= 85/90`, `SEO >= 90/100`, `5–15 req/s`, `< 0,5 %` n'est adopté ; objectif charge maintenu à **`p95 <= 800 ms`** au débit V1 réaliste choisi par le propriétaire avec taux d'erreurs et codes HTTP rapportés conjointement, **`100 req/s` hors défaut** ; **`Cache bypass` maintenu** pendant toute la validation V1, cache HTML HCDN différé post-V1).

| Domaine | Statut actuel | Points restant requis hors gel / avant lancement |
|---|---|---|
| Parcours WhatsApp / `wa.me` | **ÉCHEC sur UAT** (`wa.me/?text=...`) / **PASS en simu n8n isolée** (`25/25`) | Configurer le numéro attendu côté UAT/prod et masquer le CTA si absent ; rejouer E2E après mise en place des workflows n8n owner (inventaire + exports sans secrets obtenus au préalable). |
| Cache LiteSpeed & En-têtes | **ANOMALIE PROUVÉE** | Exclure `/wp-json/partikulier/v1/health` (`cache-rest=0` ou exclusion URI dédiée), ajouter `Cache-Control: no-store, no-cache` explicite sur `/fr/contact/` et maintenir `Cache bypass` HCDN. |
| Perf mobile & SEO | **Expliqué / À revalider sur domaine final** | `fr-home-mobile` 96–97 à chaud (TBT 14–15 ms), `62` lié au démarrage à froid CI ; corriger `'Learn more'` + lien `/fr/cookies/` sur `/en/` (`class-visit-counter.php:57,79`), `/favicon.ico` 404, puis revalider SEO sous le domaine HTTPS final (`x-robots-tag` retiré au lancement). |
| Dépôt d'annonce & Contact E2E | **NON EXÉCUTÉ** (bout-en-bout complet) | Exécuter en préprod isolée avec données de test, boîte mail capturée dédiée (jamais de clients réels), mécanisme d'authentification approuvé et transport SMTP vérifié. |
| Sauvegardes & reprise | **Code/BDD SSH OK** / **Médias & Restauration isolée `NON EXÉCUTÉ`** | Vérifier les sauvegardes Hostinger hPanel / externes pour `wp-content/uploads/` (33 Mo) et prouver une restauration **sur cible isolée** (jamais de simulation de panne BDD en prod ni de restauration par-dessus la prod). |
| Gouvernance `uat` | **Restaurée partiellement (revue requise)** | Règle temporaire `66301849` supprimée (`66232029` et `61839753` seules actives) ; vérifier si `prevent_self_review` / `required_reviewers` sur `uat` doivent rester `[hajarbenmlih91-cloud]` (`prevent_self_review: false`) ou être ajustés. |
