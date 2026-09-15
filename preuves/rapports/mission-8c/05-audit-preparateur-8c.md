# AUDIT PRÉPARATEUR — Lecture critique du dossier 8C (batterie dynamique + arbitrage chaîne outil)

**Auditeur** : Z/AI (préparateur des packs 13/14 — même discipline que les audits 8A/8B).
**Date** : 2026-09-15, ~03:20 UTC.
**Objet** : vérification indépendante des affirmations des 4 documents versés (01–04) avant scellé du pack 15.
**Méthode** : croisement de chaque affirmation vérifiable avec (a) le code source du commit publié `21498fd` (clone local intègre — worktree propre) et (b) les métadonnées publiques Packagist (source primaire, sortie brute en 06).

## A. Croisements code — 10/10 verts

| # | Affirmation du rapport | Preuve dans le code publié (21498fd) | Verdict |
|---|---|---|---|
| 1 | RequestCycle = WeakMap, verdict caché par cycle (recall/remember), effets protégés par first_run | `src/Rest/RequestCycle.php` : `final class RequestCycle` + WeakMap ; `first_run()` l.44, `remember()` l.61, `recall()` l.72 | ✅ |
| 2 | Audit d'échec HMAC unique en mode log : `RequestCycle::first_run($request,'hmac_audit_failure')` | `src/Domain/Automation/AutomationHmacTrait.php` l.146 : `if ( RequestCycle::first_run($request, 'hmac_audit_failure') )` — commentaire explicite « la ré-exécution Allow-header ne double pas le compteur » | ✅ |
| 3 | E-1604 nominale : seuil vérifié AVANT incrément → 401 ×10 puis 429 à la 11ᵉ | `LeadsEraseGuardTrait::erase_evaluate` : `if ( ! $exempt && $failures >= $threshold )` (429) est testé AVANT `erase_register_failure()` (incrément, appelé seulement sur échec d'auth) — cohérent strictement avec « 10 échecs tolérés, 11ᵉ rejetée » | ✅ |
| 4 | Audits uniques : 1 ligne `lead_erase_auth_failed` par échec, 1 `lead_erase_flood` par rejet, `lead_erase_secret_deprecated` ×1 (E-1606) | Les 3 appels `self::audit(...)` sont dans les branches mono-exécutables de `erase_evaluate` ; le verdict recall/remember garantit une seule évaluation par cycle | ✅ |
| 5 | Ordre des contrôles de la garde : limiteur → secret dédié → fenêtre de transition (E-1603) | Conforme à la docblock l.41-44 (« limiteur d'échecs d'abord […] secret dédié ensuite […] fenêtre de transition E-1603 en dernier ») et au corps lu intégralement | ✅ |
| 6 | Résidu OPTIONS (§3 du test dynamique) : la discovery ré-exécute la garde avec effets de bord, cache RequestCycle vide | **Structurellement confirmé** : `erase_evaluate()` (compteur + audits) s'exécute inconditionnellement dès l'appel de `check_erase_secret()` ; AUCUN marqueur de dispatch ni garde de méthode dans la chaîne — une passe discovery (OPTIONS) sur instance vierge déclenche l'évaluation complète une fois. Qualification P3/pré-existant/« plus strict que spécifié » : exacte | ✅ |
| 7 | Health : domaines à la RACINE du JSON (défaut de formulation de la mission, pas du produit) | `DomainRegistry::health()` : `$out[$key] = ['owner'=>…, 'lot'=>…, 'tables'=>…]` — les domaines sont bien des clés racine ; `routes` (namespace/collisions) est un bloc séparé | ✅ |
| 8 | REST /favorites = guardPrivate (`canReadPrivate()` = `is_user_logged_in()`) → 401 anonyme ; chemin anonyme réel = AJAX | `ListingPolicy.php` l.18-20 : `canReadPrivate(): bool { return is_user_logged_in(); }` ; `RestController` l.156 : `'permission_callback' => [$this,'guardPrivate']` → `rateLimiter()->guard($request,'private',$this->policy()->canReadPrivate(),60,60)` ; thème : `wp_ajax_pk_sync_favorite` + `wp_ajax_nopriv_pk_sync_favorite` (class-owner-insights.php l.34-35) | ✅ |
| 9 | Theme Check : 2 cibles SE-022 tombées (register_sidebar, casse WordPress) | `register_sidebar` : 0 appel actif dans le thème (uniquement le commentaire E-2204a dans class-theme-setup.php l.55) ; `pk-diagnostic.php` : 24 « WordPress » corrects, 0 occurrence de « Wordpress/wordPress » | ✅ |
| 10 | Empreintes étape 0 (fd1ad6f2… / 0fa2b738…, 1 119 448 / 163 273 o) | Ce sont exactement les empreintes publiées et auditées par le préparateur à la Task 48 (téléchargement indépendant + cmp byte-identique au pack 13) | ✅ |

## B. Nuances relevées par l'auditeur (sans impact sur les conclusions)

1. **visitor_hash** : le rapport écrit « visitor_hash=sha256 du visitor_id ». Le code
   (`OwnerStatsService.php` l.112) réalise `hash_hmac('sha256', 'favorite-v1|' . $visitor_id,
   wp_salt('auth'))` — un **HMAC-SHA256 salé**, plus fort qu'un SHA-256 nu. La conclusion
   « pas d'ID visiteur brut — privacy by design » reste exacte (et renforcée). Nuance de
   formulation seulement.
2. **Les tentatives REST échouées (401/403 cookie/nonce) n'ont laissé AUCUNE ligne en
   `pk_property_saves`** (brut : « lignes pk_property_saves : 0 » ×2 avant le test AJAX) —
   bon signe de robustesse non demandé par la mission : les rejets ne laissent pas d'effets.
3. **Cohérence interne du seed** : integrity served=3 / live_posts=3 = 2 annonces fr + 1
   traduction EN, conforme au contenu seedé déclaré ; `property_id=12` cohérent avec un
   banc réinstallé (IDs remontés).

## C. Ce que l'auditeur ne peut PAS rejouer (honnêteté d'audit)

- Les exécutions HTTP elles-mêmes : le banc du vérificateur a été détruit après tests ;
  je dispose des sorties brutes (02) mais pas d'un banc actif. Les croisements code (A)
  rendent les résultats crédibles : chaque comportement observé correspond à ce que le
  code publié fait structurellement.
- Le delta wp server (404 au lieu de 405+Allow) : artefact du routeur wp-cli, bien
  qualifié, et contredit par les contrats HTTP réels de la CI (run 34920185631) sur ce
  même commit.
- La fenêtre de transition n8n côté orchestrateur (limite déjà documentée depuis Train 1,
  flag OFF par défaut vérifié).

## D. Faits d'environnement du vérificateur — appréciation

La **reprise de base détectée en cours de batterie** (mot de passe admin divergent,
données résiduelles) corrigée par DROP DATABASE + réinstallation complète + **batterie
rejouée intégralement sur base vide** : c'est la bonne discipline — un défaut de banc
corrigé dans le harnais, pas compensé dans le produit. Les résultats versés sont ceux du
banc propre. À saluer, pas à pénaliser.

## E. Arbitrage chaîne outil — vérifié à la source primaire

Mon contrôle indépendant sur `repo.packagist.org` (sortie brute en 06) confirme
point par point les métadonnées citées par le vérificateur :

| Package | Version | Date | Contrainte phpcsutils | Contrainte phpcsextra |
|---|---|---|---|---|
| wpcs | 3.4.1 | 2026-07-27 | **^1.2.3** | ^1.5.1 |
| wpcs | 3.4.0 | 2026-07-16 | ^1.2.2 | ^1.5.0 |
| wpcs | 3.3.0 | 2025-11-25 | ^1.1.0 | ^1.5.0 |
| phpcsextra | 1.5.0 | 2025-11-12 | **^1.2.0** | — |
| phpcsutils | 1.1.1 | 2025-08-10 | — | — |
| phpcsutils | 1.2.3 | 2026-07-27 | — | — |

Conséquences vérifiées :
1. La combinaison alléguée « wpcs 3.4.1 + phpcsutils 1.1.1 » est **insoluble** (1.1.1 < 1.2.3).
2. La combinaison « phpcsextra 1.5.0 + phpcsutils 1.1.1 » est **elle aussi insoluble** (^1.2.0) — l'allégation est doublement auto-contradictoire.
3. « 1.1.1 » n'est cohérent qu'avec wpcs 3.3.0 (^1.1.0) — confusion de générations plausible dans l'enregistrement de la session de vérif SE-019 (le préparateur avait déjà qualifié cette hypothèse d'« erreur de transmission probable », registre du pack 14).
4. WPCS 3.4.1 et PHPCSUtils 1.2.3 sont sortis le même jour (2026-07-27) ; l'exécution SE-019 (13/09/2026) est postérieure → un `composer require` frais résolvait nécessairement vers ≥ cette génération.

**Appréciation de l'auditeur** : le retrait de la contre-allégation est fondé et vérifié
à la source. L'erratum du pack 14 (observation A, statut « INDÉTERMINÉ ») est mis à jour
par le présent dossier : **TRANCHÉ PAR MÉTADONNÉES** — les notes de Release
(1.2.3/1.5.1) sont très probablement exactes ; la certitude absolue reste la
re-dérivation SE-023 (la chaîne qui reproduit `53e2df5` octet pour octet depuis
`1a126e0` est la canonique — protocolo déjà gelé dans le pack 14, inchangé).

## F. Verdict de l'audit

- **Rapport 8C : CONFORME ET CRÉDIBLE** — critère 12 de l'étape 8 **satisfait par
  l'exécution** (batterie dynamique sur les assets publiés, WordPress frais à base
  vide, zips publics vérifiés par empreintes, aucun patch, aucune simulation, limites
  nommées).
- **Test dynamique Train 2 : CONFORME** — SE-022 prouvé en dynamique réel sur les deux
  gardes ; SE-019 sans régression front ; le résidu OPTIONS (P3, pré-existant) est
  confirmé par le code et correctement qualifié (backlog Train 3 #1).
- **Arbitrage chaîne outil : VÉRIFIÉ À LA SOURCE** — contre-allégation retirée, notes
  de Release confirmées comme combinaison minimale résoluble.
- **SE-019 est désormais fermée de bout en bout** : préparation → publication → audit
  statique (CI 277/277 sur le commit publié) → **audit dynamique (ce dossier)** →
  arbitrage des observations.

## G. Restes hors périmètre de ce dossier

1. Backlog Train 3 (ordre proposé par le vérificateur, approuvé par le préparateur) :
   #1 résidu OPTIONS (P3) ; #2 SE-023 fixers token-transformants (P3) ; #3 chaîne
   outil — **soldé au plan documentaire par le présent dossier**, reste la re-dérivation
   SE-023 pour la certitude absolue ; #4 bascule n8n à prouver avant tout flag ON
   (bloquant, inchangé).
2. Erratum de mission 8C (07) : à appliquer aux futurs rejeux de la mission.
