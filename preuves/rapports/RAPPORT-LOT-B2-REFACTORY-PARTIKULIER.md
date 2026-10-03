# Rapport de recette — Lot B2 (domaine leads/qualification/WhatsApp)

**Projet** : Refonte Partikulier (CDC v1.2) · **Lot** : B2 — leads/qualification/WhatsApp
**Livrables** : `B-INSTALLER-PLUGIN-partikulier-core-2.2.0.zip` (100 563 o, sha256 `45a8657a01b15015f5dbd7da598f31ede23144f3036982ae9a1beea88727bc31`) + `partikulier-theme-6.18.3.zip` (926 623 o, sha256 `0703636dec832173d85d6fed61691fa7554c9c9e463db3212ebe2a38756d4d3e`)
**Repos git** : plugin `9563774` (main, 39 fichiers), thème `71daf74` (main, 123 fichiers) — prêts à pousser
**Date** : 9-10 septembre 2026 · **Verdict : GO**

---

## 1. Périmètre livré

Le lot B2 extrait du thème le domaine le plus lourd du lotissement : **leads/qualification/WhatsApp**, soit les **huit tables** `pk_buyer_leads`, `pk_interest_events`, `pk_contact_limits`, `pk_contact_disclosures`, `pk_whatsapp_consents`, `pk_whatsapp_messages`, `pk_buyer_preferences`, `pk_lead_followups` (annexe D du CDC ; migration REG-4 ; estimation 10-14 jh).

Côté plugin (2.0.0 → **2.2.0**) :

- **`Schema` 2.2.0** : les huit DDL repris **à l'identique** de `class-buyer-qualification.php` (REG-6 — mêmes colonnes, mêmes clés, mêmes noms d'index) ; manifeste : les huit entrées `leads` passent de `theme` à `plugin`, lot B2. Le domaine `leads` devient le **4/8** possédé par le plugin (listings, payments, premium, leads).
- **`Migrator`** : étape 2.2.0 versionnée `adopt_leads_tables` — adoption journalisée (existence, comptages, empreinte de structure via `SHOW CREATE TABLE`), idempotente, **aucune donnée déplacée** (le T0 du banc portait 10 lignes réelles, contrairement au B1 dont les tables étaient vides — le contrôle est ici ligne à ligne).
- **`src/Domain/Leads/LeadService.php`** (port fidèle du dispositif) : cœur transactionnel `authorize_contact` (HMAC de recherche, plafonnement quotidien par propriétaires distincts, idempotence par `provider_message_id`, transaction), `register_api_lead` (INTEG-2), gestionnaires REST de qualification (contact-authorization, préférences, consentement, opt-out idempotent), rétention (effacement transactionnel des huit tables, purge quotidienne bornée, `retention_days`), accès données de l'écran d'administration (KPI, lignes filtrées, mise à jour de suivi), lecture croisée `has_active_consent` pour le domaine alertes (B3). Chiffrement AES-256-CBC du numéro (déchiffrement réservé `manage_options`), journal d'audit des transitions (`lead_authorized`, `lead_opted_out`, `lead_erased`, `lead_followup_updated`, `leads_purged`) — preuve d'exécution plugin.
- **`LeadBridge`** (INTEG-2) : appelle directement `LeadService::register_api_lead` ; le repli vers le dispositif du thème reste pour les combinaisons croisées.
- **Route `/erase-lead`** reprise côté plugin (registre unique, owner `plugin`) ; **cron `pk_buyer_privacy_purge`** (planification + handler) désormais détenu par le plugin.
- **Contrat** `tests/leads-domain-contract.php` : **16 assertions** (couture, adoption, cœur transactionnel, idempotence, plafonnement, pont REST, gardes, consentement, opt-out, préférences, écran admin, suivi, déchiffrement, effacement, REG-4, health).

Côté thème (6.18.2 → **6.18.3**, coutures seules) :

- `class-buyer-qualification.php` : `maybe_install` éteint quand le plugin détient le schéma ; `daily_limit`, les quatre gestionnaires REST, `register_api_lead`, `handle_stop`, `decrypt_phone_for_admin` délèguent à `LeadService` ; `contact_url`/`reference_for` restent au thème (front, aucune table).
- `class-lead-retention.php` : cesse d'enregistrer cron + route quand le plugin existe (sinon chemin autonome intact).
- `class-leads-admin.php` : KPI, lignes, export et écritures de suivi délégués ; **le rendu, les libellés et les filtres restent au thème** (l'écran « Leads WhatsApp » est inchangé visuellement).
- `class-saved-alerts.php` (domaine B3) : lecture du consentement déléguée au service — critère « tables lues/écrites côté plugin uniquement » respecté y compris en lecture croisée.
- `class-whatsapp-verification.php` **reste intégralement côté thème** (arbitrage n° 2 ci-dessous).

## 2. Critères de sortie du lot B (CDC, tableau 4) — tous atteints

| Critère | Preuve | Résultat |
|---|---|---|
| Tables lues/écrites côté plugin uniquement | Contrat 16/16 (audit comme preuve d'exécution) + matrice REG-5 (absence d'audit = chemin thème) | **Atteint** |
| Migration journalisée, idempotente | `REG6-parite-structure-donnees-idempotence.json` : DDL identique, 10/10 leads identiques ligne à ligne, 2ᵉ passage 0 étape | **Atteint** |
| Rollback prouvé en conditions réelles | `rollback-preuve.txt` : 9/9 (aller T0 + plugin 2.1.0, retour nominal re-vérifié) | **Atteint** |
| Suites QA aux scores maximaux | Contrats **92/92** (76 A/B1 + 16 B2) · visuel **12/12 ×2 à 0,00 %** · rapide « ok » ×2 · audit JSON-LD **9/9** · front 200 ×6 · p95 dans la tolérance · CI thème **11/11** + plugin **8/8** | **Atteint** |

## 3. Non-régression mesurée

- **Visuel 12/12 ×2 à 0,00 %** contre une baseline capturée sur l'état **B1** (6.18.2 + 2.1.0) puis comparée à l'état B2 — la baseline de l'environnement avait disparu avec la réinitialisation du banc, elle a été reconstituée selon la méthodologie du lot B1 (capture avant, comparaison après).
- **REG-2** : p95 mesuré 232,5 ms puis 248,2 ms sur deux passes (référence lot B1 244,0 ms, tolérance ±10 %) ; sonde bootstrap : court-circuit identique (2 plugins actifs, `partikulier_core` chargé).
- **Health final** : `ok`, core 2.2.0, schéma 2.2.0, 0 orphelin, 0 manquant, 0 collision, domaines plugin 4/8.
- **Hygiène du banc** : 10 leads avant et après les contrats — zéro fuite (voir incident 1).

## 4. Incidents réels découverts et corrigés pendant la recette

1. **Fuite d'hygiène des contrats du lot A** : `core-contract.php` et `leads-contract.php` nettoyaient `pk_buyer_leads` par une clé `lead_id` inexistante (la table est clé par `id`) — chaque rejeu laissait une ligne orpheline ; de surcroît `core-contract` utilisait un téléphone **fixe** (`212600000099`) correspondant à un lead préexistant du banc, dont `last_seen_at` était écrasé à chaque passe. Corrigé dans les deux fichiers de test (téléphone aléatoire, purge par la bonne clé, `pk_buyer_leads` par `id`) — **assertions inchangées**, la recette re-prouve 8/8 et 7/7. Découverte possible uniquement parce que le T0 de B2 portait de la donnée réelle.
2. **Fenêtre du limiteur de débit (lot A) saturable en debug** : des rejeux rapprochés de `leads-contract` empilent le compteur transitoire POST /leads (10 req/60 s par identité) et faussent LEAD-006 (429 parasite). La recette attend 65 s avant la section contrats ; consigné pour les rejeus manuels.
3. **Collision transitional /erase-lead (combinaison C)** : le thème 6.18.2 (sans couture) déclare toujours la route que le plugin 2.2.0 reprend — le registre unique INTEG-3 **refuse** la seconde déclaration, la journalise et la compte (health `collisions: 1` dans cet état croisalisé uniquement). Comportement **conçu** : c'est la détection bruyante du décalage de versions ; l'état nominal (6.18.3 + 2.2.0) reste à 0 collision (prouvé par `routes-collision` 5/5 et le health final).
4. **Baseline visuelle absente de l'environnement** : disparue avec la réinitialisation du banc (comme les repos git imbriqués). Reconstituée par capture sur état B1 puis comparaison B2 — la preuve pixel reste une vraie preuve avant/après.
5. (Mineur, outillage) Un échec de quoting bash dans la sonde de rollback a été corrigé avant archivage ; les empreintes SHA-256 des livrables et le SHA git (`9563774`) sont portés par chaque contrat archivé.

## 5. Arbitrages documentés

1. **Rétention = registre non suppressible + primitive d'ingestion inchangée** : l'effacement explicite (route `/erase-lead`) et la purge quotidienne bornée (100 leads) conservent exactement les sémantiques du thème ; les transitions sont en plus journalisées au registre d'audit (non exposées en REST).
2. **`class-whatsapp-verification.php` reste côté thème** : le workflow de validation WhatsApp avant publication n'écrit **aucune table du domaine** (uniquement des méta de modération `_pk_status`, `_pk_whatsapp_verification_code`, …). Le CDC définit B2 par les huit tables : le déplacer aurait élargi le périmètre sans gain de propriété. Sa constante `STATUS_PENDING` est référencée par le service (valeur identique, portée en constante).
3. **Route `/erase-lead` et cron de rétention déplacés côté plugin** (et non laissés au thème) : le lot F exige l'extinction des vestiges métier du thème — la rétention EST du métier leads. Le coût transitional est la collision détectée ci-dessus (arbitrage assumé : bruit signalé plutôt que double déclaration silencieuse).
4. **Les quatre routes de qualification restent déclarées par le thème** (via le pont `Automation_Bridge` → registre unique, owner `theme`) : leurs gestionnaires délèguent la logique au service. Le critère du lot B porte sur les tables, pas sur la localisation des déclarations ; déplacer ces routes aurait ajouté du risque sans bénéfice de propriété (elles le seront au lot F avec le reste du pont).
5. **Snapshot de l'annonce (`property_snapshot`)** : le service appelle `Partikulier_Geo::location_string` quand la classe du thème existe (cas nominal) avec repli sur les termes bruts `es_location` — parité vérifiée par le contrat (B2L-003, empreinte de la référence dans le snapshot).

## 6. Matrice REG-5 (dégradation gracieuse)

| Combinaison | État | Preuve |
|---|---|---|
| **A** — 6.18.3 + 2.2.0 | Délégation active (nominal) | Contrats 92/92, QA complète |
| **B** — 6.18.3 + plugin désactivé | Chemin autonome 6.17.x du thème | 6/6, preuve par **absence** de ligne d'audit |
| **C** — 6.18.2 + 2.2.0 | Plugin propriétaire, thème historique écrit direct | 7/7 (collision /erase-lead détectée et journalisée) |
| **D** — 6.18.2 + plugin absent | État T0 (reconstitué par la preuve de rollback) | Historique |

## 7. Restes et découvertes à remonter au commanditaire

- **P-INT-0 inchangé** (T0 contractuelle sur le staging réel, volume réel des tables leads — le T0 du banc porte 10 lignes) ; **push GitHub des deux repos** (B1 : `013dc66`/`39e8058` reconstruits en `71daf74`/`9563774` après la perte des `.git` imbriqués par réinitialisation de l'environnement — les sources étant intègres, seuls les métadonnées d'historique ont été recréées).
- WAL du banc : les sauvegardes staging devront utiliser un dump complet (rapporté dès le B1, toujours d'actualité).
- Bug latent `insert_id` du thème 6.17.x (consigné au B1, sans consommateur) : toujours non corrigé hors périmètre.

## 8. Verdict

**GO.** Les quatre critères de sortie du lot B sont atteints avec preuves archivées et rejouables (`preuves-lot-B2/LISEZMOI.md`). Le domaine leads est le premier du lot B migré **avec de la donnée réelle** — la preuve d'intégrité est ligne à ligne, pas seulement structurelle. Prochaine étape : **lot B3 — alertes** (2 tables, 6-8 jh), pendant que le commanditaire réalise P-INT-0 et pousse les dépôts.
