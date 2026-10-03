# Rapport de recette — Lot B4 (domaine automatisation n8n)

**Projet** : Refonte Partikulier (CDC v1.2) · **Lot** : B4 — automatisation n8n (pont entrant + sécurité HMAC)
**Livrables** : `B-INSTALLER-PLUGIN-partikulier-core-2.4.0.zip` (124 397 o, sha256 `49d6155639bd7bb80fab6394b42f2509dc0fbdb8cb0d0ddd5c5a31ba87b1441e`) + `partikulier-theme-6.18.5.zip` (929 333 o, sha256 `5d95459dd7ac6da53c4adc7f6d795476aadd57437c29531142c4042606522898`)
**Repos git** : plugin `4fde1f0` (main, 3 commits B4 sur 5e0a8d3), thème `199abfb` (main) — prêts à pousser
**Date** : 10 septembre 2026 · **Verdict : GO**

---

## 1. Périmètre livré

Le lot B4 extrait du thème le domaine **automatisation n8n** : les **deux tables** `pk_automation_events` (accusés d'événements normalisés, payload haché jamais persisté) et `pk_n8n_hmac_audit` (compteur d'échecs de signature par clé et par heure) — annexe D du CDC. C'est le premier sous-lot du lot B dont le T0 du banc porte de la **donnée réelle côté événements** (2 accusés « received ») : l'adoption les a préservés byte pour byte (REG-6).

Côté plugin (2.3.0 → **2.4.0**) :

- **`Schema` 2.4.0** : les deux DDL repris **à l'identique** de `class-automation-bridge.php` et `class-n8n-security.php` (REG-6 — mêmes colonnes, mêmes clés, mêmes noms d'index, y compris la formulation compacte historique de la table d'audit) ; manifeste : les deux entrées `automation` passent de `theme` à `plugin`, lot B4. Le domaine devient le **6/8** possédé par le plugin (listings, payments, premium, leads, alerts, automation).
- **`Migrator`** : étape 2.4.0 versionnée `adopt_automation_tables` — adoption journalisée (existence, comptages, empreinte de structure), idempotente, **aucune donnée déplacée**. Refacteur commun `adoptTable()` introduit pour les adoptions B3/B4 et suivantes (même discipline, liste de tables seule variable — la duplication B3/B4 devenait trois occurrences).
- **`src/Domain/Automation/AutomationService.php`** (port fidèle de `Partikulier_Automation_Bridge` + couche sécurité de `Partikulier_N8n_Security`) : `receive_event` (gardes `pk_automation_payload`/`pk_automation_storage` héritées, préfixes `n8n-`/`pay-` par source, types whitelistés, hash HMAC du payload — jamais persisté en clair, idempotence par `UNIQUE KEY event_id` → duplicate = 200 `accepted`), `check_automation_secret` (secret partagé `X-Partikulier-Automation`/Bearer, promotion **« secret présent + mode off = enforce »** — LOT 2, Isolation —, signature `sha256=` HMAC du canonique `MÉTHODE\nroute\nhorodatage\ncorps`, fenêtre ±300 s, rotation `previous_key_id`), `audit_failure` (upsert `key_hour`, plafond 100/heure, `LEAST` hérité), `outgoing_headers` (webhooks sortants signés : chemin + requête dans le canonique), réglages `pk_n8n_settings` (lecture, migration unique depuis l'option historique `pk_theme_options` **par nom littéral** — aucune dépendance de classe thème, validation `save_admin_settings` avec garde de robustesse du secret ≥ 32 octets) ; journal d'audit plugin `automation_event_received`/`automation_event_duplicate` comme preuve d'exécution (REG-5).
- **Route** `POST /automation-event` déclarée par le `RestController` (owner plugin, `permission_callback` = `check_automation_secret`, port fidèle) — le thème 6.18.5 cesse de la déclarer quand le service existe (INTEG-3 : 0 collision à l'état nominal).
- **`Contrat`** `tests/automation-domain-contract.php` : **17 assertions** (couture, adoption, réception nominale + ligne conforme + payload jamais en clair, idempotence, gardes ×4, 401 sans secret, promotion enforce LOT 2, signature valide, corps altéré, horodatage périmé, mode log + échec journalisé, plafond d'échecs, en-têtes sortants, secret faible refusé, couture thème, health 2/2 + route inscrite).
- **CI** : workflow épinglé 2.3.0 → **2.4.0**, structure étendue (`AutomationService`, `automation-domain-contract`) — rejeu local **8/8 VERT**.

Côté thème (6.18.4 → **6.18.5**, coutures seules) :

- `class-automation-bridge.php` : `maybe_install` éteint quand le plugin détient le schéma ; `register_routes` cède la route au plugin ; `receive_event` et `check_automation_secret` délèguent à `AutomationService`. **Les helpers de déclaration de routes (`register_route`, `declare_rest_route`) restent au thème** : ils sont le point d'intégration INTEG-3 de six autres classes (qualification B2, rétention B2, approbation, statistiques propriétaire B5) — ils ne touchent aucune table du domaine.
- `class-n8n-security.php` : `maybe_migrate` et `maybe_install_audit_table` éteints quand le service existe ; `env_secret`, `env_webhook`, `settings`, `get`, `secret_keys`, `outgoing_headers`, `check_automation_secret`, `audit_failure` délèguent ; **l'écran « Réglages n8n » reste au thème** (rendu, nonce, redirection — arbitrage B4) avec validation/enregistrement délégués (`save_admin_settings`). Sans le plugin, le chemin autonome 6.17.x est conservé à l'identique (REG-5).
- Aucun autre fichier touché (ni gabarit, ni style, ni JavaScript) ; bump ×5 + changelog readme.

## 2. Critères de sortie du lot B (CDC, tableau 4) — tous atteints

| Critère | Preuve | Résultat |
|---|---|---|
| Tables lues/écrites côté plugin uniquement | Contrat 17/17 (audits `automation_event_received`/`automation_event_duplicate` comme preuve d'exécution) + matrice REG-5 (absence d'audit = chemin thème) | **Atteint** |
| Migration journalisée, idempotente | `REG6-parite-structure-donnees-idempotence.json` : DDL identique, index identiques, **2 événements réels préservés (2=2, empreintes identiques)**, 2ᵉ passage 0 étape | **Atteint** |
| Rollback prouvé en conditions réelles | `rollback-preuve.txt` : **10/10** (aller T0 + plugin 2.3.0, retour nominal re-vérifié — y compris réception d'événement par le chemin autonome du thème) | **Atteint** |
| Suites QA aux scores maximaux | Contrats **124/124** (107 A/B1/B2/B3 + 17 B4) · visuel **12/12 ×2 à 0,00 %** · rapide « ok » ×2 · audit JSON-LD **9/9** · front 200 ×6 · p95 dans la tolérance · CI thème **11/11** + plugin **8/8** | **Atteint** |

## 3. Non-régression mesurée

- **Visuel 12/12 ×2 à 0,00 %** contre la baseline du lot B2 (reprise telle quelle — le déploiement B4 n'exclut pas `__baseline__` du rsync, la référence pixel reste celle du banc).
- **REG-2** : p50 220,4 ms / p95 **229,0 ms** (référence lot B3 : p95 228,1 ms, tolérance ±10 %) ; sonde bootstrap : court-circuit identique (`partikulier_core` chargé, 2 plugins actifs).
- **Health final** : `ok`, core 2.4.0, schéma 2.4.0, 0 orphelin, 0 manquant, 0 collision, domaines plugin **6/8**.
- **Hygiène du banc** après contrats : événements **2/2** (les accusés réels du T0, aucune fuite), audit HMAC **0** (aucune trace des sondes), leads **10/10**.

## 4. Incidents réels découverts et corrigés pendant le lot

1. **Fuite de secret dans le journal T0 (corrigée avant tout test)** : l'option `pk_n8n_settings` est une chaîne **sérialisée PHP** (pas du JSON) — le masquage initialement prévu pour un JSON décodé n'a pas déclenché et le secret du banc est passé en clair dans le premier `T0-domaine-b4.json`. Corrigé dans `b4-t0-snapshot.php` (désérialisation → masquage des clés secrètes → resérialisation) et rejeu : l'archive ne contient plus aucune valeur de secret (vérifié par grep). La sauvegarde complète `T0-sauvegarde-base.sqlite` contient naturellement le secret (c'est son rôle de copie intégrale).
2. **Faux signalement de corruption du workflow CI (écart B3, même famille)** : lecture apparente de `branches: ain]` dans `ci.yml` — **artefact d'affichage du terminal** (absorption de `[m` comme séquence ANSI). Vérification par octets bruts (`od -c` + `git show | od -c`) : le fichier et le commit B2 (9563774) ont toujours contenu `branches: [main]`. Quasi-incident consigné : la vérification au niveau octet a évité un « correctif » d'un défaut inexistant.
3. **Défauts du harnais de test, pas du service (3 échecs initiaux du contrat, corrigés)** : (a) la sonde « mode log » utilisait un `key_id` inconnu — or le port fidèle **saute la vérification de signature pour une clé inconnue** (comportement hérité du thème : `$keys[$key_id] ?? ''` vide → bloc non exécuté → requête acceptée sans échec journalisé) → la sonde utilise désormais la clé active réelle ; (b) le scénario « couture thème » mélangeait un event_id `n8n-…` avec la source `payment_provider` → garde `pk_automation_payload` (le service avait raison, le scénario était incohérent) → préfixe `pay-` aligné. **Le service n'a pas été modifié** : les deux comportements constatés sont le port fidèle du thème.
4. **Chaînage fatal évité** : `WP_REST_Request::set_body()` retourne `void` (pas fluent) — un chaînage `->set_body()` aurait fait un fatal ; le harnais du contrat a été corrigé avant exécution.
5. **Résidu de collision transitional dans le contrôle final du rollback (corrigé)** : le snapshot T1 était pris **après** la matrice REG-5 et héritait de l'entrée de collision `/automation-event` du combo C (détection conçue INTEG-3, persistée dans l'option) → le contrôle final « 0 collision » échouait (9/10). Le « retour avant » retire explicitement l'entrée (la preuve de la collision reste dans `REG5-C-theme-6184.json`) → **10/10**. Incident de quoting bash (apostrophe dans une chaîne PHP entre quotes simples) corrigé au passage.

## 5. Arbitrages documentés

1. **Écran « Réglages n8n » au thème, politique au plugin** : le rendu, le nonce et la redirection restent au thème (pattern B2 « écran leads ») ; la validation et l'enregistrement des réglages vivent dans `AutomationService::save_admin_settings` (même garde de robustesse du secret, même assainissement, WP_Error pour l'UI `wp_die`).
2. **Helpers de déclaration de routes conservés au thème** : `register_route`/`declare_rest_route` servent six classes transversales (qualification, rétention, approbation, statistiques propriétaire B5) — infrastructure INTEG-3 du thème, hors périmètre table. Seule la route du domaine (`/automation-event`) migre.
3. **Migration des réglages par nom littéral** : le service lit l'option historique `pk_theme_options` (valeur de `Partikulier_Settings::OPTION`) en constante de chaîne — aucune dépendance de classe vers le thème ; l'exécution est détenue par le plugin (thème éteint), idempotente, état `completed` inchangé.
4. **Comportement « clé inconnue acceptée » porté fidèlement** : une signature bien formée avec un `key_id` absent de la rotation passe la vérification (comportement hérité du thème, constaté par le contrat B4A-011 initial). Non modifié : discipline du port fidèle — consigné ici pour le futur durcissement (recommandation : exiger un `key_id` connu dès que n8n signe en production).
5. **Fenêtre du limiteur de débit** : héritée du lot A (10 req/60 s sur POST /leads) — la recette attend 65 s avant les suites, inchangée depuis B2.

## 6. Matrice REG-5 (dégradation gracieuse)

| Combo | Thème | Plugin | Verdict | Preuve clé |
|---|---|---|---|---|
| A | 6.18.5 | 2.4.0 | **PASS** | Recette principale : contrats 124/124, health automation=plugin, 0 collision |
| B | 6.18.5 | absent | **PASS** (6/6) | `receive_event` par le chemin autonome : ligne conforme, **aucune ligne d'audit** (preuve du chemin), réglages lus en direct, idempotence |
| C | 6.18.4 | 2.4.0 | **PASS** (7/7) | Health automation=plugin (manifeste), chemin historique du thème (pas d'audit), **collision transitional /automation-event refusée et journalisée par INTEG-3** (détection conçue du décalage — bruit transitional, retiré au retour nominal) |
| D | 6.18.4 | absent | État T0 | Preuve de rollback (10/10) |

## 7. Livrables et preuves

- **Zips** : `download/B-INSTALLER-PLUGIN-partikulier-core-2.4.0.zip` (sha `49d61556…`) + `download/partikulier-theme-6.18.5.zip` (sha `5d95459d…`) — intégrité zip ↔ source **vide** ×2, 0 entrée `.github`, construction reproductible (deux builds successifs → sha identiques).
- **Preuves** : `download/preuves-lot-B4/` — T0 (domaine + sauvegarde complète sha `6b8184ae…`, secret masqué), `journal-recette.txt` (7 étapes), `journal-build.txt`, 12 contrats JSON (dont `automation-domain-contract.json` 17/17 @ commit `4fde1f09`), `REG6-parite-structure-donnees-idempotence.json`, `REG5-B-plugin-absent.json` + `REG5-C-theme-6184.json` + `matrice-reg5.txt`, `rollback-preuve.txt` (10/10) + `T1-b4-apres-rollback-reapplique.sqlite`, `health.json` + `health-final.json`, `rapide-1/2.txt`, `visual-1/2.txt` (12/12, 0,00 %), `audit-jsonld-hreflang.txt` (9/9), `pages-front.txt` (200 ×6), `reg2-latences.txt` + `reg2-sonde-bootstrap.txt`, `rejeu-ci-theme.txt` (11/11) + `rejeu-ci-plugin.txt` (8/8), `LISEZMOI.md` (commandes de rejeu).
- **Repos** : plugin `4fde1f0` (arbres propres), thème `199abfb` — restes côté commanditaire inchangés : **P-INT-0** (T0 contractuelle sur le staging réel), **push GitHub des 2 repos** (le CI plugin s'exécutera au premier push).
- **Prochaine étape** : lot B5 — statistiques propriétaire (`pk_property_saves`, 1 table, `class-owner-insights.php` — ses 2 routes passent déjà par les helpers du bridge).

