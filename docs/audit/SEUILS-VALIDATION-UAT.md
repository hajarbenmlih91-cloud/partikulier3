# Seuils d'acceptation et de validation UAT (versionnés avant tout run de validation)

**Version :** 1.0  
**Date :** 2026-11-10  
**Périmètre :** environnement UAT (`https://darkcyan-yak-151002.hostingersite.com`), workflows `.github/workflows/audit-uat.yml` et suites associées.

> **Règle de méthode :** chaque seuil ci-dessous est fixé à partir de **références externes publiques** (Google Core Web Vitals, Chrome Lighthouse 12, W3C WCAG 2.1 AA) et de **contrats ou historiques antérieurs du projet** (`CDC LOT 3`, `audit-uat-interactions.mjs`, `staging-cache.php`, runs antérieurs `42799771573` et `42854353976`). Aucun seuil n'est ajusté a posteriori sur un run à venir.

---

## 1. Tableau des seuils par type de page

| Type de page | Perf min (LH) | SEO min (LH) | Accessibilité min (LH + Axe) | TBT max (LH) | LCP max (LH & Lab) | CLS max | Avertissements acceptés (non bloquants en UAT) | Avertissements / échecs bloquants (refus de validation) |
|---|---:|---:|---:|---:|---:|---:|---|---|
| **Accueil mobile** (`/fr/`, `/en/`, `/ar/`, 390×844 / profil mobile) | **≥ 85** *(cible Good ≥ 90 ; tolérance ≥ 85 pour écart mobile simulé 4G)* | **≥ 90** | **≥ 95** et **0 violation Axe critique/sérieuse** | **≤ 200 ms** *(cible stricte)* ; **> 600 ms bloquant** | **≤ 2 500 ms** | **≤ 0,1** | `robots.txt` bloquant l'indexation **sur UAT uniquement** (`Disallow: /` sur Googlebot pour protéger le staging). | Tout score Perf < 85, TBT > 600 ms (rouge Lighthouse, cf. anomalie historique `fr-home-mobile` à 601 ms au run `42854353976`), LCP > 2 500 ms, SEO < 90, erreur HTTP ≥ 4xx/5xx, débordement horizontal, erreur JS non gérée (`pageerror`), violation Axe critique/sérieuse. |
| **Accueil desktop** (`/fr/`, 1440×900 / `--preset=desktop`) | **≥ 90** | **≥ 90** | **≥ 95** et **0 violation Axe critique/sérieuse** | **≤ 150 ms** | **≤ 2 500 ms** | **≤ 0,1** | `robots.txt` UAT ; `errors-in-console` isolé uniquement s'il provient d'un tiers bloqué sans impact fonctionnel (sinon à corriger). | TBT > 350 ms (zone rouge desktop Lighthouse), échec contraste texte (`color-contrast`) ou `label-content-name-mismatch` non justifié si A11y < 95, LCP > 2 500 ms, erreur JS. |
| **Archive / Catalogue** (`/fr/annonces/`, `/en/annonces/`, `/ar/annonces/`, mobile 390×844 & desktop) | **≥ 90** | **≥ 90** | **≥ 95** et **0 violation Axe critique/sérieuse** | **≤ 200 ms** | **≤ 2 500 ms** | **≤ 0,1** | `robots.txt` UAT ; `x-litespeed-cache` absent sur HCDN tant que `PK_THEME_CACHE_REQUIRED=false` (à condition de déclarer explicitement le cache fichier `NON VÉRIFIÉ`). | Catalogue vide (< cartes attendues), TTFB médian > 800 ms (contrat extérieur CDC LOT 3), Perf < 90, LCP > 2 500 ms, TBT > 600 ms, filtre mobile bloqué ou non fermable à `Escape`. |
| **Dépôt d'annonce** (`/fr/deposer/`, `/en/deposer/`, `/ar/deposer/`, pages `noindex`) | *(non couvert par LH ; suivi Lab Chromium)* | **N/A** *(exigence : `noindex` présent ; `canonical`/`hreflang` non requis)* | **100 % des contrôles Axe/libellés/alt** et 0 champ non étiqueté | **INP lab proxy ≤ 200 ms** | **≤ 2 500 ms** *(Lab Chromium)* | **≤ 0,1** | Absence de `canonical` et `hreflang` justifiée par `noindex` (règle déjà encodée dans `audit-uat.mjs`). | Absence de `noindex`, présence de texte prototype (*placeholder*), champs de formulaire < attendus, champs sans label programmatique, LCP lab > 2 500 ms, erreur JS. |
| **Fiche détail & Contact** (`/fr/annonce/...`, `/en/annonce/...`, `/ar/annonce/...`, `/contact/`) | *(suivi Chromium + contrat cache)* | **100 % métadonnées** (`title`, `canonical`, `hreflang`, `og:image` absolue sur fiche) | **100 % des contrôles DOM** (`alt`, libellés, boutons/liens nommés) | **INP lab proxy ≤ 200 ms** | **≤ 2 500 ms** | **≤ 0,1** | Lien `wa.me/?text=…` sans numéro destinataire **uniquement** sur les annonces de démonstration UAT dont le compte n'a pas de numéro configuré (constat historique sur les 6 fiches de démo aux runs `42799771573` et `42854353976`). | `og:image` absente ou relative sur fiche détail, HTTP ≠ 200, absence de `canonical` ou `hreflang`, lien WhatsApp sans destinataire sur une fiche pourvue d'un téléphone propriétaire, XSS réfléchi. |
| **Parcours connecté propriétaire** (`/fr/connexion/` → `/fr/mes-annonces/`, mobile 390×844 & desktop 1440×900, Accepter & Refuser) | *(parcours fonctionnel E2E)* | **N/A** | Consentement atteignable par **rôle + nom accessible** (clavier & souris) | **N/A** *(clic actionnable sans `force: true`)* | **N/A** | **N/A** | Aucun avertissement accepté sur l'authentification. | Échec REST anonyme (doit être 401/403), absence du bandeau en contexte neuf, fermeture inopérante ou réapparition après rechargement / sur tableau de bord, `trial` click intercepté, absence de cookie `wordpress_logged_in_*`, URL finale ne correspondant pas au préfixe `/mes-annonces`, absence de `.pk-dashboard` / `.pk-dashboard-title` / `.pk-dashboard-empty`, fuite de données d'un autre propriétaire, erreur JS, échec de suppression ou persistance du compte jetable (`cleanup`). |

---

## 2. Seuils transverses (infrastructure, charge, sécurité, cache)

| Domaine | Seuil exigé pour validation | Bloquant ? | Justification externe ou historique |
|---|---|---|---|
| **Sécurité live (`staging-securite.php`)** | **100 % OK (`0 KO`, `0` à regarder)** sur HMAC, anti-replay, sonde front, exposition fichiers sensibles, en-têtes CSP/HSTS/XFO/XCTO, health check. | **Oui** | Politique sécurité du projet (`SE-026`, `LOT 2`, runs antérieurs à `22 OK · 0 KO`). |
| **Charge 10 req/s (10 s, 100 req)** | `0` réponse `5xx`, `0` erreur réseau, `p95 ≤ 800 ms`. | **Oui** | Contrat extérieur CDC LOT 3 (`p95 ≤ 800 ms`) et stabilité minimale UAT (`staging-charge.php`). |
| **Charge 100 req/s (30 s, 3 000 req)** | `0` réponse `5xx`, `0` erreur réseau, `p95 ≤ 800 ms`. | **Oui si `load_test=true`** | Critère de tenue en charge défini par `theme/partikulier/tests/staging-charge.php` (tolérance zéro sur les `5xx` serveur). |
| **Cache & TTFB extérieur** | Médiane TTFB `≤ 800 ms` sur 20 GET (`< 200 ms` à l'origine) ; `0 KO` sur `staging-cache.php`. | **Oui** (`TTFB` / `0 KO`) ; cache fichier **non bloquant** tant que `PK_THEME_CACHE_REQUIRED=false` | CDC LOT 3 (`≤ 800 ms` extérieur) et historique `42799771573` / `42854353976` (HCDN masquant `x-litespeed-cache`). Passer à `PK_THEME_CACHE_REQUIRED=true` exige une preuve préalable de service effectif. |
| **Garde de traçabilité & SSH** | `0` fichier hors `ALLOWED` vs `c9e1413` ; création + suppression vérifiée du compte jetable (`wp user get <ID>` en échec + `wp user list` vide). | **Oui** | Gouvernance d'audit du projet et intégrité de la base UAT. |

---

## 3. Justifications détaillées des valeurs retenues

1. **LCP ≤ 2 500 ms et CLS ≤ 0,1** : seuils officiels *« Good »* des **Google Core Web Vitals** au 75ᵉ percentile ([web.dev/articles/vitals](https://web.dev/articles/vitals), [web.dev/articles/defining-core-web-vitals-thresholds](https://web.dev/articles/defining-core-web-vitals-thresholds)), déjà repris comme cibles dans `theme/partikulier/tests/audit-uat-interactions.mjs` (`target ≤ 2500 ms`, `target ≤ 0.1`).
2. **TBT maximal ≤ 200 ms (mobile) et ≤ 150 ms (desktop), blocage au-delà de 600 ms / 350 ms** : grille officielle **Chrome Lighthouse Total Blocking Time** ([developer.chrome.com/docs/lighthouse/performance/lighthouse-total-blocking-time](https://developer.chrome.com/docs/lighthouse/performance/lighthouse-total-blocking-time)) : `0–200 ms` = vert (*fast*), `200–600 ms` = orange (*moderate*), `> 600 ms` = rouge (*slow*) sur mobile ; `0–150 ms` vert / `> 350 ms` rouge sur desktop. Historiquement, le run `42854353976` a déjà mis en évidence un cas isolé sur `fr-home-mobile` (`TBT 601 ms`, `Perf 80`) alors que les autres profils étaient à `97–98` — le seuil ≤ 200 ms (et bloquant > 600 ms) impose de résoudre ce goulot d'étranglement JS avant validation.
3. **Performance Lighthouse ≥ 85 (mobile) / ≥ 90 (desktop)** : échelle officielle Lighthouse (`90–100` = *Good*, `50–89` = *Needs Improvement*). Sur l'historique du site (`42854353976`), les profils sains atteignent `97–99` (`ar-home-mobile`, `en-home-mobile`, `fr-listings-mobile`, `fr-home-desktop`). Une marge à `85` sur mobile absorbant le bruit réseau d'un environnement staging mutualisé est tolérée, mais tout score `< 85` reste bloquant.
4. **Accessibilité ≥ 95 et 0 violation Axe critique/sérieuse** : référence **W3C WCAG 2.1 niveau AA**, déjà auditée à 0 violation critique/sérieuse dans `audit-uat.mjs`. Le seuil `≥ 95` évite de masquer des régressions de contraste ou de nom accessible tout en distinguant les avertissements mineurs documentés.
5. **SEO ≥ 90 (hors `robots.txt` UAT)** : en préproduction UAT, `robots.txt` contient volontairement `User-agent: Googlebot / Disallow: /` pour empêcher l'indexation du staging (avertissement déjà relevé dans `42799771573` et `42854353976`). Cet avertissement est accepté en UAT uniquement et bloquant en production. Les autres règles SEO (titres, descriptions, `canonical`, `hreflang`, `link-text` descriptif, `og:image` absolue) restent exigées (`SEO ≥ 90`).
6. **INP lab proxy ≤ 200 ms** : seuil officiel *« Good »* Google Core Web Vitals (`≤ 200 ms`), déjà codé dans `theme/partikulier/tests/audit-uat-interactions.mjs`.

---

## 4. Règle de décision pour la note et la validation de release (Étape 8)

- **Seul un run complet de validation exécuté après le versionnement des présents seuils** et terminé **vert (`conclusion: success`)** avec **tous les seuils bloquants respectés** autorise le calcul d'une note de qualité et la validation de la release.
- Tant qu'un run est rouge ou qu'un seuil bloquant est dépassé, **la note reste non calculable et la release reste non validée**.
