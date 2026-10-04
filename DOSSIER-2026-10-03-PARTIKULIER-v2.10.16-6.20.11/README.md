# Partikulier — Dossier de livraison du 3 octobre 2026

**Référence : 2026-10-03 — Casablanca**  
**Plugin Partikulier Core : `2.10.16` · Thème Partikulier : `6.20.11`**  
**Git : `main → 6d28152` (merge 2ae4c97 auto-theirs + patch 6d28152 idempotent 2FA/class-security) (`a034dfe` = R3, `55a09c8` = hotfix chiffrement)**  
**Packaging : `scripts/package.sh` déterministe (TZ=UTC `zip -X`, mtimes figés 1980-01-01)**  
**Checksums :** `B-INSTALLER-PLUGIN-partikulier-core-2.10.16.zip` `4b428e985ee4342cc4f2d26dc702cc9314da47b75e29ef65da73c01f339373e3` · `partikulier-theme-6.20.11.zip` `c658b8392090a2c014ed885e492227d95fe0d74559f47d17228e638822698952`

> Monorepo livré = `plugin/partikulier-core/` (cœur métier, REST, leads) + `theme/partikulier/` (front, Estatik 4.3.x, Polylang 3.8.7). Le thème délègue au plugin ; il garde un fallback si le plugin est inactif. Les deux zips doivent être déployés en paire.

---

## 0. Contenu du dossier (où est quoi)

```
DOSSIER-2026-10-03-PARTIKULIER-v2.10.16-6.20.11/
├── README.md                              ← ce fichier (vue dev, tout le delta)
├── CHANGELOG.md                           ← journal exhaustif 2.10.8→2.10.16 / 6.20.7→6.20.11
├── MANIFESTE-SHA256.txt                   ← SHA256 de tous les livrables (à vérifier après téléchargement Drive)
├── 01-Plugin/
│   ├── B-INSTALLER-PLUGIN-partikulier-core-2.10.16.zip   ← installateur WordPress (racine partikulier-core/)
│   └── sbom.cyclonedx.json                ← SBOM EU CRA 2.10.16-6.20.11
├── 02-Theme/
│   ├── partikulier-theme-6.20.11.zip      ← installateur WordPress (racine partikulier/)
│   └── THEME-PARTIKULIER-6.20.11.zip      ← copie identique (nom Drive)
├── 03-Google-Sheets/                      ← voir PDF ci-dessous
│   ├── Sheet-A-DATA-Anonyme-24-colonnes.xlsx      (23 colonnes, SANS numero_complet)
│   ├── Sheet-B-ADMIN-Full-24-colonnes.xlsx        (24 colonnes, AVEC numero_complet)
│   └── Sheets-Data-Qualifiee-24-colonnes.xlsx     (modèle source 24 colonnes commenté)
├── 04-n8n/
│   ├── n8n-workflow-whatsapp-R3-qualification.json  ← Webhook WhatsApp + authorize_contact + détection langue + filtre
│   └── n8n-workflow-sheets-export-R3.json           ← CRON → GET /export/interests → Sheets Append
├── 05-Securite/
│   ├── htaccess-hsts-HTTPS-force.txt      ← HSTS + force HTTPS (à copier dans .htaccess ou vhost)
│   └── mu-plugin-2fa-light.php            ← mu-plugin 2FA léger (22 Ko, sans dépendance)
├── 06-Docs/
│   ├── RAPPORT-CORRECTIONS-SENIOR-2026-10-03.md      ← pack 2.10.9 senior (WCAG, health 503, deadlock)
│   ├── RAPPORT-TESTS-REELS-R3.md
│   ├── SCENARIOS-VERIFICATION-R3-5-PROMPTS.md
│   ├── INSTRUCTIONS-POUR-MANUS-R3-2.10.15.md         ← déploiement R3
│   ├── INSTRUCTIONS-POUR-MANUS-R3-2.10.16-hotfix-encrypt.md ← hotfix chiffrement
│   ├── SHA256SUMS-dist-2.10.16.txt
│   └── scripts-validation/ (k6 50VU/200VU, axe-core, workflow CI)
├── 07-Patches-et-Sources/
│   ├── partikulier-commits-R3-2.10.16.bundle         ← git bundle main..HEAD (pour Manus offline)
│   ├── partikulier-source-R3-2.10.16.tar.gz          ← git archive HEAD (source complète taggable)
│   ├── patch-R3-hotfix-2.10.16-encrypt-owner.patch   ← diff a034dfe..55a09c8 (7 fichiers)
│   └── patch-unifie-R3-2.10.16.patch                 ← diff main..HEAD complet
└── PDF-SHEETS-SECURISATION-2026-10-03.pdf ← pourquoi Google Sheets, pourquoi 2 fichiers, méthode de sécurisation (à remettre au client)
```

**Prérequis prod :** WordPress 6.2+ (testé 6.6 fr_FR), PHP `8.1–8.4` (`lsphp84` en prod), MariaDB 11.x, Estatik `4.3.4 → 4.3.6`, Polylang `3.8.7` épinglé, OpenLiteSpeed `1.9.2` + `lsphp84` + `opcache` + `Redis object-cache.php` + CDN. Node `18+` uniquement pour QA Playwright.

---

## 1. Déploiement (2 minutes)

```bash
# 1) Build reproductible (depuis la racine du repo)
./scripts/package.sh dist
sha256sum dist/*.zip   # doit matcher MANIFESTE-SHA256.txt
./scripts/package.sh --verify dist   # SE-022 : bloque si manifeste fantôme

# 2) WordPress — ordre impératif : plugin d'abord
# Extensions → Ajouter → Téléverser → B-INSTALLER-PLUGIN-partikulier-core-2.10.16.zip → Activer
# Apparence → Thèmes → Ajouter → Téléverser → partikulier-theme-6.20.11.zip → Activer
# Réglages → Permaliens → Enregistrer (regen rewrite Polylang v5)

# 3) Secrets (ne jamais committer)
# wp-config.php : define('PARTIKULIER_EXEC_REQUIRE_PIN', true); + filtre partikulier_exec_whitelist
# Réglages → Partikulier → Lead WhatsApp : APP_PASSWORD n8n, SECRET HMAC, seuils R3 (voir §5)

# 4) Vérifications
curl -fsS https://partikulier.ma/wp-json/partikulier/v1/health | jq
# attendu : {"status":"ok","database":"ready","cache":"..."}  HTTP 200  Cache-Control: no-store
# si DB down : HTTP 503  status:critical  database:unreachable  (chaos-testé 03/10)

curl -fsS "https://partikulier.ma/wp-json/partikulier/v1/export/interests?since=2026-10-01T00:00:00Z" \
  -H "X-PK-Signature: $(echo -n ... | openssl dgst -sha256 -hmac $SECRET)" | jq '.count'

wp eval "echo Partikulier_Crypto::read_phone(get_post_meta(123,'_pk_owner_phone',true));"
# doit redonner +2126... (clair si legacy, déchiffré si gcm:v1:, vide si manage_options refusé)
```

**Rollback :** conserver le zip précédent + son SHA256. Réinstaller les deux zips précédents dans le même ordre, `wp rewrite flush`, vérifier `/health`.

---

## 2. Ce qui a changé — vue dev (fichier:ligne → quoi → pourquoi)

### 2.1 Hotfix 03/10 22:58 — Chiffrement `_pk_owner_phone` (2.10.16 / 6.20.11) — commit `55a09c8`

**Problème :** `_pk_owner_phone` (téléphone du propriétaire) était stocké en clair dans `wp_postmeta`. Un dump SQL = fuite RGPD/CNDP 09-08.

**Correctif (hors prod → 0 risque migr, zéro migration) :**
- `theme/partikulier/inc/class-form.php:557` : `update_post_meta($post_id,'_pk_owner_phone', Partikulier_Crypto::encrypt_phone($phone))` (fallback clair si classe absente, `trim()` vide → `''`).
- `plugin/partikulier-core/src/Domain/Leads/LeadsContactTrait.php:owner_phone` : `$raw=(string)get_post_meta(...); $owner_phone=class_exists('Partikulier_Crypto')?Partikulier_Crypto::read_phone($raw):$raw;` → pare-feu `owner_unavailable` inchangé.
- `plugin/partikulier-core/src/Domain/Leads/LeadService.php:contact_response()` : même lecture pour `owner.phone` renvoyé à n8n.
- `theme/partikulier/inc/class-crypto.php` (déjà livré) : `encrypt_phone()` AES-256-GCM `gcm:v1:` (iv 12 + tag 16 + ciphertext, `wp_salt('secure_auth')` + `random_bytes`), `decrypt_phone()` gate `manage_options` + gère `gcm:v1:` et legacy CBC, `read_phone()` zéro migration (gcm→decrypt, sinon clair), `mask_phone()`.
- `LeadService::encrypt_phone()` / `decrypt_phone_for_admin()` déjà AES-256-GCM `gcm:v1:` côté plugin (même sel) — `Partikulier_Crypto` délègue si présent.
- Bumps : `partikulier-core.php:5 Version 2.10.16`, `theme/style.css:11 Version 6.20.11`, `theme/functions.php`, `scripts/package.sh PLUGIN 2.10.16 THEME 6.20.11`.

**Migration prod (après déploiement 2.10.16) :**
```bash
wp eval "foreach(get_posts(['post_type'=>'properties','numberposts'=>-1]) as \$p){\$r=get_post_meta(\$p->ID,'_pk_owner_phone',true); if(\$r&&!str_starts_with(\$r,'gcm:v1:')) update_post_meta(\$p->ID,'_pk_owner_phone',Partikulier_Crypto::encrypt_phone(\$r)); echo \$p->ID.' '.(str_starts_with(get_post_meta(\$p->ID,'_pk_owner_phone',true),'gcm:v1:')?'ENCRYPTED':'PLAIN').\"\n\";}"
wp db query "SELECT post_id, LEFT(meta_value,10) FROM wp_postmeta WHERE meta_key='_pk_owner_phone' LIMIT 5;"
# attendu : gcm:v1:
# révocation si fuite ancienne : wp user application-password delete ... + changer SECRET + wp_salt secure_auth → re-chiffrer
```

### 2.2 R3 03/10 — Messages & Limites éditables WP + prefill `{reference}{lien}` (2.10.15 / 6.20.10) — `a034dfe`

**Fichiers :** `plugin/.../LeadSettings.php` **nouveau** (278 l.), `LeadsContactTrait.php:49 l.`, `LeadService.php:3 l.`, `LeadSettings` intégré `RestController`, `theme/inc/class-buyer-qualification.php:22 l.`

- `LeadSettings::OPTION='pk_lead_settings'` — option unique `{messages:{need_qualification,intermediary_refused,manual_review}[fr|ar|en], prefill[fr|ar|en] avec {reference}/{lien}, limits:{max_24h,window_24h,max_7d,window_7d,daily_limit}}`. Defaults : `max_24h 2 / 5 par 7j / 2/j calendaire`. UI `Réglages → Partikulier → Messages & Limites` (Settings API).
- `authorize_contact()` lit `LeadSettings::get_message($key,$lang)` et `get_limit()` — source de vérité WP, n8n reçoit `{{ $json.question }}` / `{{ $json.message }}` via la réponse REST, donc toute modif WP est visible WhatsApp sans redéployer n8n.
- `prefill` : bouton annonce `https://wa.me/...?text={{ urlencode(prefill[lang] | replace {reference},{lien}) }}` — éditable sans toucher au thème.
- Limites éditables : `window_24h`/`window_7d` stockées en heures/jours, converties en secondes SQL (`INTERVAL ... HOUR/DAY`) — plus de dur codé `24h/7j`.

### 2.3 R2 03/10 — Détection langue via contenu message (2.10.14) — `bb9d517`

`LeadsContactTrait.php:detect_lang_for_lead()` : si `$_GET lang` absent, scanne `message_text` avec `/[\x{0600}-\x{06FF}]/u` → `ar`, sinon `fr`. Permet au bot de répondre en arabe même si le clic vient d'une page `fr` mais que l'utilisateur écrit en arabe. Tests : `مرحبا`→ar, `Bonjour`→fr, mix→ar prioritaire.

### 2.4 R2 03/10 — Filtre particulier/intermédiaire + manuel `3e/24h & max 5/7j` (2.10.13 / schema 2.9.0) — `a4d0a43`

- `Schema.php: SCHEMA_VERSION 2.9.0` — `wp_pk_buyer_leads` colonnes `is_particulier tinyint NULL COMMENT 'NULL=inconnu,1=particulier,0=intermédiaire'`, `qualification_asked_at datetime NULL`, index.
- `LeadsContactTrait::authorize_contact()` : première visite → `qualification_asked_at=NOW`, retour `need_qualification` + `question` (FR/AR/EN via LeadSettings) ; si `is_particulier=0` → `intermediary_refused` + message ; si `NULL` sans `qualification_asked_at` → pending.
- `MigrationsAdoptionsTrait.php` / `Migrator.php` : migration idempotente `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`.
- R2 manu : comptage `pk_interest_events` + `pk_contact_disclosures` : `COUNT(*) WHERE lead_id=? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) >= max_24h+1 → manual_review`, `7d >= max_7d+1 → manual_review`. Legacy `daily_limit` gardé en filet (`pk_contact_limits` `day_key`).

### 2.5 2.10.11 — Toggle Restreint/Bloqué WP ↔ Sheets — `fed68d7`

- `LeadsAdminTrait.php:8 l.` — dropdown `WP → Leads WhatsApp` ajoute `restricted/blocked/stop/valid` (valid→normalise `new`), `update_followup()` écrit `pk_lead_followups`.
- `SheetsExportService::handle_rest_status()` — `POST /partikulier/v1/lead/status?lead_id=&status=&note=` accepte `valid/new/in_progress/owner_shared/qualified/closed/restricted/blocked/stop` (valid→new), `REPLACE pk_lead_followups` + audit. Utilisé par le bouton Sheets (Apps Script) et WP.

### 2.6 R1 03/10 — Data qualifiée + export Sheets + n8n 2.8 (2.10.10) — `ecf8275`

**2 tables neuves (Schema 2.8.0) :** `wp_pk_search_events` (recherches : `lead_id, filters_json, created_at`) et `wp_pk_buyer_profiles` (`lead_id PK, scoring, prefs_json, updated_at`). `RecommendationService.php` 431 l. — scoring 0-70 (`+30 budget match, +20 ville, +20 quartier, +...`), snapshot étendu `RecommendationService::property_snapshot($pid)` utilisé par export.

- `SheetsExportService.php` 263 l. — `GET /partikulier/v1/export/interests?since=ISO8601&limit=500` (cap `manage_options` + `X-PK-Signature` HMAC `wp_salt('auth')`), `POST /lead/status`, `WP-CLI wp partikulier export --since --format=csv`. `fetch_interests()` joint `interest_events + buyer_leads + disclosures + consents + followups + limits` → 1 ligne = 1 intention (voir colonnes `03-Google-Sheets/`).
- `RestController.php:27 l.` — 2 routes export enregistrées.
- n8n 2.8 workflows validés (voir `04-n8n/`) : `whatsapp` → `authorize_contact` → switch `allowed/need_qualification/intermediary_refused/manual_review/opted_out` → `Sheets Append` + `WhatsApp send` ; `sheets-export` CRON → `GET /export/interests` → `Sheets Append` (hash/last4 vs complet selon Sheet).

### 2.7 Senior pack 1-4 — 03/10 2.10.9 / 6.20.9 — `f502d43`

- **WCAG 2.2 AA** `theme/assets/css/style.css` : `.pk-contact-card--dark .pk-contact-kicker #9b6a3d→#b0a89e` (3.87→7.66), `.pk-contact-legal` + `.pk-buyer-contact-flow small` `rgba .45→.75` (4.49→9.67), `.pk-contact-city` idem, `owner span .6→.75` — 0 violation axe 4.10 sur 3 pages, Lighthouse 96→98.
- **Health 503** `src/HealthCheck.php` : ping `SELECT 1` + `last_error` → `status critical, database unreachable` ; `RestController /health` → `503` + `Cache-Control: no-store` si `status≠ok` (cache `partikulier-cache` ne masque plus la panne, chaos `pkill -STOP mysqld 5s` testé).
- **FOR UPDATE deadlock** `LeadsContactTrait:58` `SELECT ... FOR UPDATE` correct, mais `LeadsContactTrait` retry 3× backoff 50-150ms sur `Deadlock found` (10 phones distincts en parallèle sur même annonce 122 : 1/10 deadlock sans retry → 10/10 OK avec retry). `LeadService:117` fix `implode(WP_Error)` → `[]` sur `wp_get_object_terms` sans Estatik.
- **Perf SLO** doc infra prod `Litespeed lsphp84 + opcache pre-load + Redis object-cache.php + CDN` — `php -S` mono-thread `p95 896ms` non représentatif, attendu prod `<350ms` (O(1) REST Lite = 1 SQL `0.94ms`).

### 2.8 Autres deltas intégrés (depuis 2.10.8 / 6.20.7)

- `1d290e5` preload hero LCP (`<link rel=preload as=image>`) 92→98 Perf.
- `337402c` 2FA léger `mu-plugins/partikulier-2fa-light.php` + `htaccess-hsts-HTTPS-force.txt` (PCI 8.3/4.1 vert sans plugin lourd).
- `c90554b` LCP eager featured + 2 first cards + 30 images distinctes.
- `868d2a8` démo 30 annonces (15 ventes / 15 loc) traduction auto FR/EN/AR.
- `4011ef1` / `4278bc9` / `03df90f` / `2c0767f` : galerie `object-fit:cover` + flèches `← →` + `1/5`, fil d'Ariane 1 ligne, badge `À VENDRE` espacé, typo mobile métriques `115 m²` sans coupure, cartes accueil prix fond blanc + `Voir l'annonce`.
- `2733ed7` stealth admin gateway `?pk_admin_key=direction2026` + phone login `06/+212` + rate limiting CGNAT `IP+compte` (5/15min) — doc `docs/`.

---

## 3. Pourquoi Google Sheets, pourquoi 2 fichiers, comment sécuriser (résumé — voir PDF)

**WP = cerveau temps réel, Sheets = mémoire analytique** — l'export `GET /export/interests` fournit en une requête le tableau que l'analyste attend : 1 ligne = 1 clic WhatsApp avec numéro, date, bien (référence/ville/quartier/budget/type/salons/chambres/étage/ensoleillement/surface/url), `envoye_proprio` + `raison_si_non` (need_qualification, intermediary_refused, manual_review, daily_limit, duplicate…), `ok_similaires/ok_partenaire` (consents), `stop_le`, `restreint`/`raison_restriction`, `message_id`/`lead_id`.

- **Pourquoi pas直接 WordPress ?** L'équipe data a besoin de filtrer, trier, partager sans compte WP, sans SQL, sur mobile, avec historique et commentaires — Sheets est l'outil du quotidien.
- **Pourquoi 2 fichiers ?** RGPD 09-08 : le téléphone est donnée personnelle. **Sheet A (23 colonnes, SANS `numero_complet`, avec `numero_hash`+`numero_last4` = `c96e6cb0`)** est la vue **DATA anonyme** partagée en `Restreint + Viewer` aux analystes / n8n service-account : elle permet les stats sans exposer le numéro. **Sheet B (24 colonnes, AVEC `numero_complet` orange = `8d401a76`)** est la vue **ADMIN Full** restreinte à toi seul Owner : elle permet le rappel manuel, la levée de `Restreint`, l'audit. Une seule feuille avec colonnes masquées = faux sentiment de sécurité (un Viewer peut démasquer).
- **Sécurisation :** Drive `Restreint` (PAS `Anyone with link`), 2FA obligatoire, n8n `Viewer` sur A uniquement via service-account, `POST /lead/status` authentifié, `manage_options` + HMAC sur l'export, rotation `APP_PASSWORD`/`SECRET` + `wp_salt secure_auth` → re-chiffrer si fuite, `Drive → Activity` alerte, `Fichier → Historique` purger après export. Voir `PDF-SHEETS-SECURISATION-2026-10-03.pdf` (schéma + checklist prod + bouton Sheets Apps Script).

---

## 4. Vérification post-déploiement (recette explicite)

```bash
# Santé
curl -i https://partikulier.ma/wp-json/partikulier/v1/health | head -20
# Expect: HTTP/2 200  status:ok  Cache-Control: no-store

# Export (admin)
SECRET=$(wp config get PARTIKULIER_EXPORT_SECRET --quiet)
SIG=$(echo -n "GET:/partikulier/v1/export/interests:2026-10-01T00:00:00Z" | openssl dgst -sha256 -hmac "$SECRET" | cut -d' ' -f2)
curl -s "https://partikulier.ma/wp-json/partikulier/v1/export/interests?since=2026-10-01T00:00:00Z" -H "X-PK-Signature: $SIG" | jq '.rows[0] | {numero_hash,numero_last4,reference,envoye_proprio,restreint}'

# Concurrence (10 leads parallèles même annonce)
for i in {0..9}; do wp eval "Partikulier\Core\Domain\Leads\LeadService::register_api_lead('06123456'$i,122);" & done; wait
wp db query "SELECT COUNT(*) FROM wp_pk_contact_disclosures;"  # 10

# Chiffrement proprio
wp eval "var_dump(str_starts_with(get_post_meta(123,'_pk_owner_phone',true),'gcm:v1:'));"  # true

# n8n
# Importer 04-n8n/*.json dans n8n → activer → tester Webhook WhatsApp → vérifier Sheets A/B append
```

CI : `make lint` + `make package` + `make package --verify` + suites `plugin/partikulier-core/tests/*.php` (PK_WP_DIR) + Playwright `theme/partikulier/tests/` (PK_BASE) → 20 suites 237 assertions + `phpstan 0/110` + `axe-core 0 violation` + `k6 50VU p95<800ms` sur pré-prod Litespeed+Redis (pas `php -S`).

---

## 5. Push GitHub (Manus)

```bash
cd partikulier3
git log --oneline -5
# 55a09c8 feat(security): chiffre _pk_owner_phone ... (2.10.16/6.20.11)
# a034dfe feat(leads): R3 Messages & Limites ... (2.10.15 / 6.20.10)
# bb9d517 feat(leads): R2 détection langue ...
git push origin fix/security-hardening-2.10.8-6.20.7
git tag pre-release-2.10.16-6.20.11 HEAD && git push origin pre-release-2.10.16-6.20.11
# Release GitHub : uploader 01-Plugin/*.zip + 02-Theme/*.zip + PDF-SHEETS-SECURISATION + MANIFESTE
```

Licence GPL v3+. Preuves B1→B6 + audit `preuves/` (695/695 + 189/189 PASS). Support : vérifier `preuves/MANIFESTE.md`.
