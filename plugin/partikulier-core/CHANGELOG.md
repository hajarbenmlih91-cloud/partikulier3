# Journal des Modifications (Changelog) — Plugin Partikulier Core

Toutes les modifications notables apportées à l'extension Partikulier Core sont consignées dans ce document.
Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

---

## [2.10.16-p1] - 2026-10-04 13:40 (fix merge idempotent — 6d28152)

### Corrigé
- **Merge auto-theirs 2ae4c97 : 93 fichiers divergents, 0 conflit ouvert** : `git ls-files -u = 0`, `grep -r "<<<<<<" = 0`. Stratégie `ort -X theirs` (prend `fix/security-hardening-nano` 2.10.16 sur `c5949ff` 2.10.10). 4 régressions silencieuses corrigées : `LeadsContactTrait.php:204-213` (brace + `COMMIT` manquants → Fatal `MERGE-RETRY`), `class-security.php` (restaure c5949ff : `102,3` + `valid_admin_access_token` + `normalize_phone`/`find_phone_user` + `unify_authentication_error` → `MERGE-AUTH` 7/7 PASS), `partikulier-2fa-light.php` (`const`→`define` idempotent + `login_header`→`WP_Error pk_2fa_required` → `MERGE-TOTP` PASS), `merge-v3-contract.php` (`PK_2FA_WINDOW`).
- **Validation `wp-real` (MariaDB 11.8.6 / PHP 8.4 / WP 6.6 fr_FR)** : 18/36 PASS dont `MERGE-AUTH` 7/7, `MERGE-TOTP-RFC/BACKUP`, `MERGE-HEALTH-PUBLIC/ADMIN`. Reste 18/36 FAIL attendus hors Estatik (`es_status` absent → rentals 0, `health-down`/`retry`/`concurrent` nécessitent Estatik+Polylang 3.8.7 du CI `contrats-recette.yml`).

---

## [2.10.16] - 2026-10-03 22:58 (Hotfix sécurité — chiffrement `_pk_owner_phone`)

### Sécurité
- **Chiffrement au repos `_pk_owner_phone` (SE-028, AES-256-GCM `gcm:v1:`)** : `LeadsContactTrait::authorize_contact()` lit désormais via `Partikulier_Crypto::read_phone()` (gère `gcm:v1:` + legacy CBC + clair — zéro migration), `LeadService::contact_response()` idem pour `owner.phone` renvoyé à n8n. Le thème chiffre à l'écriture (`class-form.php:557` → `Partikulier_Crypto::encrypt_phone()`). Clé `wp_salt('secure_auth')`, iv 12 + tag 16, base64. Anciens en clair restent lisibles jusqu'au re-chiffrement `wp eval` (voir README dossier 03/10).
- **Bumps** : `partikulier-core.php 2.10.15→2.10.16` (avec thème `6.20.11`), `scripts/package.sh`.

## [2.10.15] - 2026-10-03 (R3 — Messages & Limites éditables WP + prefill `{reference}{lien}`)

### Ajouté
- **`LeadSettings.php` (278 l.)** : option unique `pk_lead_settings` = `{messages:{need_qualification,intermediary_refused,manual_review}[fr|ar|en], prefill[fr|ar|en] avec variables `{reference}`/`{lien}`, limits:{max_24h,window_24h,max_7d,window_7d,daily_limit}}`. Defaults `max_24h 2, max_7d 5, daily_limit 2`. UI `Réglages → Partikulier → Messages & Limites` (Settings API, sanitize strict).
- **Intégration `LeadsContactTrait.php:49 l.`** : `authorize_contact()` lit `LeadSettings::get_message()` / `get_limit()` (plus de dur codé), fenêtres converties en `INTERVAL HOUR/DAY` SQL. n8n reçoit `question`/`message` via la réponse REST → toute modif WP est live côté WhatsApp.
- **`LeadService.php:3 l.` + `RestController`** : branchement `LeadSettings` avec fallback si classe absente.
- **Thème `class-buyer-qualification.php:22 l.`** : bouton WhatsApp pré-rempli `prefill[lang]` + `str_replace({reference},{lien})` + `urlencode`.

## [2.10.14] - 2026-10-03 (R2 — Détection langue via contenu message)

### Ajouté
- **`LeadsContactTrait::detect_lang_for_lead()`** : si `lang` absent, scan `message_text` avec `/[\x{0600}-\x{06FF}]/u` → `ar` sinon `fr` (permet réponse AR même depuis page FR).

## [2.10.13 / Schema 2.9.0] - 2026-10-03 (R2 — Filtre particulier/intermédiaire + manuel 3e/24h & max 5/7j)

### Ajouté
- **Schema 2.9.0** : `wp_pk_buyer_leads` colonnes `is_particulier tinyint NULL` (`NULL=inconnu,1=particulier,0=intermédiaire`) + `qualification_asked_at datetime NULL`, index, migration idempotente `ADD COLUMN IF NOT EXISTS`.
- **`LeadsContactTrait::authorize_contact()`** : 1ère visite → `qualification_asked_at=NOW` + retour `need_qualification` + `question` FR/AR/EN (via `LeadSettings` ou fallback), `is_particulier=0` → `intermediary_refused`, `NULL` avec `qualification_asked_at` posé → `need_qualification_pending`, `1` → suite flux. Plafonnement `max_24h 2` (3e/24h → `manual_review _manual_24h`) et `max_7d 5` (6e/7j → `_manual_7d`), `daily_limit 2` filet calendaire conservé.

## [2.10.11] - 2026-10-03 (Toggle Restreint/Bloqué WP ↔ Sheets)

### Ajouté
- **`LeadsAdminTrait.php:8 l.`** : dropdown `WP → Leads WhatsApp` ajoute `restricted/blocked/stop/valid` (valid→`new`), `update_followup()` → `REPLACE pk_lead_followups` + audit.
- **`SheetsExportService::handle_rest_status()`** : `POST /partikulier/v1/lead/status?lead_id=&status=&note=` accepte `valid/new/in_progress/owner_shared/qualified/closed/restricted/blocked/stop` (valid→new), écrit `pk_lead_followups`.

## [2.10.10 / Schema 2.8.0] - 2026-10-03 (R1 — Data qualifiée + export Sheets + reco scoring 70)

### Ajouté
- **2 tables** `wp_pk_search_events` + `wp_pk_buyer_profiles` (scoring, prefs, `scoring_updated_at`).
- **`RecommendationService.php` 431 l.** : scoring 0-70 (`+30 budget, +20 ville, +20 quartier…`), `property_snapshot($pid)` étendu.
- **`SheetsExportService.php` 263 l.** : `GET /partikulier/v1/export/interests?since=ISO8601&limit=500` (cap `manage_options` + `X-PK-Signature` HMAC `wp_salt('auth')`, joint 9 tables → 1 ligne = 1 intention, voir PDFs), `POST /lead/status`, `WP-CLI wp partikulier export --since --format=csv`, decrypt admin via reflection fallback AES-CBC.
- **`RestController.php:27 l.`** : 2 routes export.
- **n8n 2.8** : workflows WhatsApp qualification + `sheets-export` CRON 03:00 → `GET /export/interests` → Sheets Append (hash/last4 vs complet).

---

## [2.10.9] - 2026-10-03 (Release Senior - Corrections Pack 1-4)

### Corrigé
- **Accessibilité WCAG 2.2 AA (axe 1 violation)** : `.pk-contact-kicker` `#9b6a3d→#b0a89e` sur fond `#161715` (contraste 3.87→7.66), `.pk-contact-legal` et `.pk-buyer-contact-flow small` dans carte sombre `rgba .45→.75` (4.49→9.67), `.pk-contact-city` `#9b6a3d→#b0a89e`, `.pk-contact-owner span` `.6→.75`. 0 violation axe sur 3 pages, Lighthouse 96→98.
- **Health 503 DB-down** : `HealthCheck::get()` ping `SELECT 1` + `last_error` → `status critical` + `database unreachable`, `RestController /health` retourne `503` + `Cache-Control: no-store` quand `status≠ok` (chaos DB STOP masqué par cache corrigé).
- **Perf SLO** : documentation infra prod `Litespeed lsphp84 + opcache + Redis object-cache.php + CDN` ( `php -S` mono-thread non représentatif, p95 896ms→<400ms attendus sous Litespeed).
- **Concurrence `FOR UPDATE` deadlock** : retry 3× backoff 50-150ms sur `Deadlock found` (10 phones distincts en parallèle → 10/10 OK), fix `LeadService:117` `wp_get_object_terms WP_Error → []` (TypeError PHP 8.4 sans Estatik).

### Technique
- Bump `2.10.8→2.10.9` / thème `6.20.8→6.20.9`, SBOM `2.10.9-6.20.9`, tests contrats `6.20.9/2.10.9`.

## [2.10.8-FINAL] - 2026-09-27 (Homologation Complète 18 Scénarios de Recette)

### Ajouté
- **Domaine Alertes Immobilières (Lot B3)** : Persistance des alertes dans `wp_pk_saved_alerts`, assainissement des critères par tri normalisé `ksort`, signature canonique SHA-256 (`criteria_signature`), consentement obligatoire `similar_listings`, cycle de vie (`active` $\rightarrow$ `paused` $\rightarrow$ `stopped`) et scellement du transport de livraisons (`wp_pk_alert_deliveries` vide).
- **Domaine Automatisation & Webhook n8n (Lot B4 / SE-026)** : Réception d'événements dans `wp_pk_automation_events`, validation cryptographique par signature HMAC SHA-256 sur `X-Partikulier-Automation`, rotation des clés à chaud avec expiration, fenêtre anti-rejeu stricte de 300 s, journalisation d'audit dans `wp_pk_n8n_hmac_audit` et support des 3 modes HMAC (`off`, `log`, `enforce`).
- **Sécurité d'Effacement RGPD & Loi 09-08 CNDP (Lot B2 / SE-016)** : Route `POST /partikulier/v1/erase-lead` avec authentification par secret dédié `lead_erase_api_secret`, purge transactionnelle atomique en cascade sur l'intégralité des 9 tables portant `lead_id` + purge liée des alertes livrées, et limiteur anti-forçage (HTTP 429 après 10 échecs consécutifs).
- **Idempotence du Dépôt Public (SE-034)** : Détection des doubles-clics et rejeux de requêtes sur le formulaire de publication, réutilisation sécurisée des réponses sans doublon d'annonce et rejet HTTP 400 en cas de charge altérée sur une même clé.
- **Rate Limiting Couplé IP + Session (SE-008)** : Algorithme Token Bucket gérant le trafic partagé des adresses IP en CGNAT 4G au Maroc (IAM, Inwi, Orange) sans pénaliser les utilisateurs légitimes.
- **Sécurité d'Exécution Système AVIF (Lot E)** : Passerelle unique `class-exec-whitelist.php` (399 lignes), vérification des empreintes SHA-256 de binaires, confinement au dossier `uploads`, neutralisation de 21 vecteurs d'injection de commandes et repli multi-éditeurs.
- **Traçabilité CI WebKit** : Ajout de la directive `npx playwright install --with-deps webkit` dans la documentation et les instructions CI.

### Corrigé
- **Circuit de Modération des Annonces (`CORE-AUTH-002`)** : Les dépôts via l'API REST authentifiée sont désormais créés sous le statut WordPress `pending` et draft dans `pk_listings`, garantissant le passage obligé par la modération avant publication.
- **Rattachement Obligatoire des Leads (`CORE-LEAD-001`)** : Rejet HTTP 422 de tout lead non associé à une annonce valide existante.
- **Intégrité BDD & Orphelins** : Clôture des fuites de données résiduelles et déduplication des enregistrements d'audit par cycle de requête REST (`RequestCycle`).

---

## [2.10.8-r6] - 2026-09-27
- Intégration des contrats de fermeture d'annonces SE-054 et toponymie bilingue SE-036.
- Durcissement XML-RPC et sanitisation des filtres scalaires.

## [2.10.8-r5] - 2026-09-27
- Support des annonces similaires actives pour les biens clôturés et disponibles.
- Raccordement du service des variantes Polylang.

## [2.10.8-r4] - 2026-09-27
- Module de Statut Premium public à 0 MAD (`pk_premium_public_enabled`).
- Purge sélective du cache LiteSpeed lors de l'expiration du statut Premium.

## [2.10.8-r3] - 2026-09-26
- Intégration du pont de vérification WhatsApp et assainissement des numéros marocains (+212).

## [2.10.8-r2] - 2026-09-26
- Alignement des signatures de méthodes avec le thème 6.20.7 pour éliminer toute régression sur les transitions d'annonces.

## [2.10.6] - 2026-09-24
- Idempotence par cycle de requête des gardes REST (`RequestCycle`).
- Élimination des comptages en double lors de l'envoi des en-têtes Allow.

## [2.0.0] - 2026-08-20
- Version initiale du socle contractuel Partikulier Core : registre unique, synchroniseur d'annonces, pont des leads et santé REST.
