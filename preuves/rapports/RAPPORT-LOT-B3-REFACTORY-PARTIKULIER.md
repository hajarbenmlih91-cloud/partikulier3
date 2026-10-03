# Rapport de recette — Lot B3 (domaine alertes)

**Projet** : Refonte Partikulier (CDC v1.2) · **Lot** : B3 — alertes sauvegardées
**Livrables** : `B-INSTALLER-PLUGIN-partikulier-core-2.3.0.zip` (110 412 o, sha256 `682a329a20c2089b1205b7bffb5c9dee1028cd30df5be106942b4559257701ae`) + `partikulier-theme-6.18.4.zip` (927 216 o, sha256 `024de07297f14a336dbe14e44f4037263ea0a9a977fac2b71a74dc8201230daa`)
**Repos git** : plugin `5e0a8d3` (main, 2 commits B3 sur 9563774), thème `19cf39d` (main) — prêts à pousser
**Date** : 10 septembre 2026 · **Verdict : GO**

---

## 1. Périmètre livré

Le lot B3 extrait du thème le domaine **alertes sauvegardées** : les **deux tables** `pk_saved_alerts` et `pk_alert_deliveries` (annexe D du CDC ; le plus léger du lot B — 1 classe, aucune route, aucun cron, la fonctionnalité est dormante : « structures posées, fonctionnalités pas encore ouvertes », cf. `docs/reprise/01-ARCHITECTURE.md` ; estimation 6-8 jh).

Côté plugin (2.2.0 → **2.3.0**) :

- **`Schema` 2.3.0** : les deux DDL repris **à l'identique** de `class-saved-alerts.php` (REG-6 — mêmes colonnes, mêmes clés, mêmes noms d'index) ; manifeste : les deux entrées `alerts` passent de `theme` à `plugin`, lot B3. Le domaine `alerts` devient le **5/8** possédé par le plugin (listings, payments, premium, leads, alerts).
- **`Migrator`** : étape 2.3.0 versionnée `adopt_alerts_tables` — adoption journalisée (existence, comptages, empreinte de structure via `SHOW CREATE TABLE`), idempotente, **aucune donnée déplacée** (le T0 du banc portait les deux tables vides — P-INT-0 mesurera le volume réel du staging).
- **`src/Domain/Alerts/AlertService.php`** (port fidèle de `Partikulier_Saved_Alerts`) : `save_alert` (validation des codes d'erreur hérités `pk_alert_payload`/`pk_alert_consent`/`pk_alert_storage`, signature sha256 des critères assainis — clés connues, `ksort`, trois zones maximum —, upsert `ON DUPLICATE KEY` par `(lead_id, criteria_signature)`, statut re-activé à l'actualisation), `change_status` (`active`/`paused`/`stopped`, codes hérités), lecture croisée `has_active_consent` **déléguée au `LeadService` du lot B2** (le consentement vit dans le domaine leads — repli SQL direct pour les combinaisons croisées « plugin ancien »), journal d'audit des transitions (`alert_saved`, `alert_status_changed`). **Aucune route, aucun cron, aucune livraison écrite** : port fidèle du contrat du thème (l'adaptateur Meta/n8n sera ajouté après validation des accès externes) — `pk_alert_deliveries` reste vierge, vérifié par contrat.
- **`Contrat`** `tests/alerts-domain-contract.php` : **15 assertions** (couture, adoption, gate de consentement, création nominale, upsert, signature normalisée, assainissement, gardes ×5, statuts + gardes, couture thème → service, révocation → refus, livraisons vierges, health 2/2 tables + 0 collision).
- **Correctif CI** (découverte n° 1 ci-dessous) : `.github/workflows/ci.yml` réaligné (pin `Schema::VERSION` 2.1.0 → 2.3.0, structure étendue à `LeadService`, `AlertService`, `LeadBridge` et aux trois contrats de domaine).

Côté thème (6.18.3 → **6.18.4**, coutures seules) :

- `class-saved-alerts.php` : `maybe_install` éteint quand le plugin détient le schéma ; `save_alert` et `change_status` délèguent à `AlertService` ; sans le plugin, le chemin autonome 6.17.x est conservé à l'identique (REG-5). La lecture croisée du consentement reste déléguée au `LeadService` (couture B2 existante).
- Aucun autre fichier touché (ni gabarit, ni style, ni JavaScript) ; bump ×5 + changelog readme.

## 2. Critères de sortie du lot B (CDC, tableau 4) — tous atteints

| Critère | Preuve | Résultat |
|---|---|---|
| Tables lues/écrites côté plugin uniquement | Contrat 15/15 (audit `alert_saved`/`alert_status_changed` comme preuve d'exécution) + matrice REG-5 (absence d'audit = chemin thème) | **Atteint** |
| Migration journalisée, idempotente | `REG6-parite-structure-donnees-idempotence.json` : DDL identique, index identiques, tables préservées (0=0), 2ᵉ passage 0 étape | **Atteint** |
| Rollback prouvé en conditions réelles | `rollback-preuve.txt` : 9/9 (aller T0 + plugin 2.2.0, retour nominal re-vérifié — y compris sauvegarde d'alerte par le chemin autonome du thème) | **Atteint** |
| Suites QA aux scores maximaux | Contrats **107/107** (92 A/B1/B2 + 15 B3) · visuel **12/12 ×2 à 0,00 %** · rapide « ok » ×2 · audit JSON-LD **9/9** · front 200 ×6 · p95 dans la tolérance · CI thème **11/11** + plugin **8/8** | **Atteint** |

## 3. Non-régression mesurée

- **Visuel 12/12 ×2 à 0,00 %** contre la baseline du lot B2 (reprise telle quelle — le déploiement B3 n'exclut pas `__baseline__` du rsync, la référence pixel de B2 reste la référence du banc).
- **REG-2** : p50 219,4 ms / p95 **228,1 ms** (référence lot B2 : p95 232,5-248,2 ms, tolérance ±10 %) ; sonde bootstrap : court-circuit identique (`partikulier_core` chargé, 2 plugins actifs).
- **Health final** : `ok`, core 2.3.0, schéma 2.3.0, 0 orphelin, 0 manquant, 0 collision, domaines plugin **5/8**.
- **Hygiène du banc** : alertes 0, livraisons 0, leads 10/10 après les contrats — zéro fuite.

## 4. Incidents réels découverts et corrigés pendant le lot

1. **Le workflow CI GitHub du plugin était resté épinglé à 2.1.0** (état B1) : le rejeu local du lot B2 (`ci-rejeu-plugin-local.sh`, pin 2.2.0, structure étendue) avait été mis à jour mais **pas le fichier `.github/workflows/ci.yml` réellement commité** — son job « Version de schéma stable » exigeait `Schema::VERSION = 2.1.0` alors que la source portait 2.2.0, et sa liste de structure ignorait `LeadService` et les contrats leads : **le premier push GitHub du repo 9563774 aurait échoué**. Découvert en préparant B3 (lecture du workflow avant d'écrire le lot), corrigé dans 2.3.0 (pin 2.3.0, structure complète), re-prouvé par le rejeu local 8/8. Le rejeu local et le workflow réel partagent désormais la même liste de structure — l'écart ne pourra plus se reproduire silencieusement.
2. **Prémisse erronée du test B3A-007** : la première rédaction réordonnait aussi les *valeurs* de `areas` (`['Rabat','Casablanca']`) et attendait la même signature — mais le `ksort` du dispositif (hérité du thème, porté fidèlement) ne trie que les **clés** : l'ordre des zones est significatif dans la signature, et le dispositif créait légitimement une seconde alerte. Le **test** a été corrigé (réordonnancement des clés de premier niveau uniquement), le service est resté inchangé — c'est le comportement du thème 6.17.x qui fait foi.
3. **Fauchage des processus d'arrière-plan entre invocations du harnais d'exécution** : deux lancements de la recette en tâche de fond (`nohup`, puis `setsid nohup`) sont morts à la fin de l'invocation qui les avait créés (constat empirique horodaté : un processus témoin `setsid` meurt entre invocations, tandis que le serveur sandbox lancé au tour précédent survit). La recette est désormais **exécutable par étapes** (`b3-recette.sh <1-7>` ou `tout`), chaque étape au premier plan sous la barre des 10 minutes du harnais, journal concaténé — robustesse gagnée pour tous les lots suivants.
4. (Mineur, outillage) Un remplacement erroné pendant l'édition du `Migrator` a brièvement écrasé la méthode `adoptLeadsTables` du lot B2 — **rattrapé par le diff git avant toute exécution** (aucun livrable, aucune preuve n'a jamais porté l'état cassé), restauration à l'identique vérifiée par `git diff`. Consigné pour la transparence : le réflexe « diff avant test » a fait son office.

## 5. Arbitrages documentés

1. **Aucune route, aucun cron, aucune livraison déplacés** : le domaine alertes n'expose rien de public (le module du thème ne contacte aucun fournisseur, ne planifie aucun envoi, n'expose aucune route — l'adaptateur Meta/n8n viendra après validation des accès externes). Conséquence assumée : en combinaison C (thème 6.18.3 + plugin 2.3.0), **aucune collision transitional n'est possible** (contrairement au B2 et sa route `/erase-lead` déclarée des deux côtés) — le décalage de versions n'y est détectable que par le manifeste du health (`alerts=plugin` pendant que le thème historique écrit encore). Ce silence est le prix de la fidélité au contrat du thème ; il disparaîtra quand l'orchestrateur existera.
2. **Lecture croisée du consentement via le service du domaine propriétaire** : `AlertService::has_active_consent` appelle `LeadService::has_active_consent` (B2) quand il existe, avec repli SQL direct pour les combinaisons croisées — le critère « tables lues/écrites côté plugin uniquement » est respecté **y compris en lecture inter-domaines**. La couture du thème garde sa délégation B2 vers `LeadService` (la lecture croisée appartient au domaine leads, pas au domaine alertes).
3. **Journal d'audit étendu aux transitions d'alertes** : le thème historique n'écrivait rien au registre ; le port consigne `alert_saved`/`alert_status_changed` (mêmes écritures de données, même logique). C'est le mécanisme de preuve de chemin de la matrice REG-5, cohérent avec les lots B1/B2 — et la seule contre-preuve possible en combinaisons croisées (arbitrage 1).
4. **`maybe_install` éteint, option thème laissée en place** : quand le plugin détient le schéma, le thème cesse d'installer (convention B1/B2) mais l'option `pk_saved_alerts_db_version` reste telle quelle — LA référence de versionnement est le `Schema::VERSION` du plugin (2.3.0) et son journal Migrator ; gérer l'option thème aurait ajouté un état synchronisé sans valeur contractuelle.
5. **`pk_alert_deliveries` adoptée mais laissée vierge** : la table des livraisons appartient au domaine (annexe D) mais aucune écriture n'est portée — le service ne livre rien tant que l'adaptateur n'existe pas. Le contrat l'asserte explicitement (B3A-014) : si une livraison apparaît avant l'orchestrateur, c'est un bug.

## 6. Matrice REG-5 (dégradation gracieuse)

| Combinaison | État | Preuve |
|---|---|---|
| **A** — 6.18.4 + 2.3.0 | Délégation active (nominal) | Contrats 107/107, QA complète |
| **B** — 6.18.4 + plugin désactivé | Chemin autonome 6.17.x du thème | **6/6**, preuve par **absence** de ligne d'audit `alert_saved` |
| **C** — 6.18.3 + 2.3.0 | Plugin propriétaire, thème historique écrit direct | **7/7** (0 collision — aucune route ; décalage visible au seul manifeste health) |
| **D** — 6.18.3 + plugin absent | État T0 (reconstitué par la preuve de rollback) | Historique |

## 7. Livrables et preuves

- `download/preuves-lot-B3/` : T0 (2 tables vides, DDL+index, sauvegarde sha 85dd06a2…), **journal-recette.txt** (exécution intégrale par étapes), 11 contrats JSON (**107/107**, commit 5e0a8d3 porté), REG-6, sondes REG-5 B 6/6 + C 7/7, **rollback 9/9** (+ snapshot T1), QA (rapide/visual **12/12 ×2 à 0,00 %**, audit 9/9, front ×6), REG-2 (p95 228,1 ms + sonde bootstrap), CI ×2 (11/11 + 8/8), `LISEZMOI` rejouable.
- `RAPPORT-LOT-B3-REFACTORY-PARTIKULIER.md` (ce document) + `README.md` livrables mis à jour.
- Repos git : plugin `5e0a8d3` (32d9c72 + correctif contrat), thème `19cf39d` — arbre propre ×2, prêts à pousser.

**Verdict : GO** — les quatre critères de sortie du lot B sont atteints avec preuves. Prochaine étape : **lot B4 — automatisation n8n** (2 tables : `pk_automation_events`, `pk_n8n_hmac_audit`), puis B5 stats propriétaire, B6 variantes de traduction (le plus risqué, il conditionne le lot C).
