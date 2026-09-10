# Scénario 10 — Défensif : secret / nonce / reflection XSS (SIM-10 rejouée)

**Site :** sandbox http://127.0.0.1:8091 (thème 6.17.31, 10 annonces × 3 langues) · **Date :** 2026-09-10T20:53:28
**Périmètre :** lecture seule — aucune donnée créée/modifiée, aucun correctif appliqué (règle absolue n°3). Les POST de contrôle portent des identifiants synthétiques rejetés par les gardes ; le contrôle positif nonce utilise post_id=0 (refusé par validation, zéro écriture).

| # | Contrôle | Verdict | Détail |
|---|---|---|---|
| S10.1 | Aucun matériau secret dans les couches publiques | **FAIL** | /wp-json/partikulier/v1/health contient « hmac » ; /wp-json/partikulier/v1/health contient « n8n » [^1] |
| S10.2 | pkConfig inline sans matériau secret | **PASS** | clés : ajaxUrl, nonce, manageNonce, homeUrl, placesNonce, submitNonce, language, i18n · 3 nonces au format WP (10 hex) · clés interdites : aucune |
| S10.3 | automation-event : garde HMAC (sans signature → 401, signature invalide → 401) | **PASS** | sans signature : 401 · signature « deadbeef » : 401 · GET sur route POST : 404 |
| S10.4 | Routes propriétaire inaccessibles anonymement | **PASS** | GET owner/dashboard : 401 · POST owner/listings/12/action : 401 (attendu 401/401) |
| S10.5 | API listings publique sans PII (email/téléphone) | **PASS** | 200 JSON · ? annonces · emails : 0 · téléphones : 0 |
| S10.6 | Mutations REST anonymes rejetées (favorites, leads) | **PASS** | POST favorites anonyme : 401 · POST leads anonyme : 400 (attendu 401 et 4xx) |
| S10.7 | pk_sync_favorite exige un nonce CSRF vivant | **PASS** | sans nonce : HTTP 403, corps « -1 » (coupé par check_ajax_referer) · avec nonce de page + post_id=0 : HTTP 400 (passe la porte nonce, refusé par validation — aucune écriture) |
| S10.8 | Requête de recherche réfléchie uniquement échappée | **PASS** | 9 combinaisons ?s= sondées (FR/AR) : aucun payload brut ; marqueur bénin réfléchi 9× (échappé/encodé en contexte attr/texte) |
| S10.9 | Paramètres de filtre sans réflexion de payload | **PASS** | 10 sondes (es_city/es_type/es_action/pk_order/es_price_max × FR/AR) : sanitize_title/absint neutralisent, aucun payload brut |
| S10.10 | Autocomplete : réponse JSON stricte, payload non exécutable | **PASS** | HTTP 200 · Content-Type application/json; charset=UTF-8 · JSON valide · payload <script> réfléchi : non |
| S10.11 | En-têtes de sécurité présents, pas de divulgation de bannière | **PASS** | CSP (frame-ancestors 'self'), X-Content-Type-Options: nosniff, X-Frame-Options: SAMEORIGIN, Referrer-Policy: strict-origin-when-cross-origin, Permissions-Policy (geo/mic/cam désactivés) · X-Powered-By/Server : absents |

[^1]: Fuites détectées : /wp-json/partikulier/v1/health contient « hmac » ; /wp-json/partikulier/v1/health contient « n8n »

**Bilan : 10/11 PASS.**