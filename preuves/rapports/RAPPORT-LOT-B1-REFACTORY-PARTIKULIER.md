# RAPPORT DE LOT B1 — Extraction du domaine paiements/premium vers le plugin

**Chantier** : Refonte Partikulier (CDC v1.2, accepté — tâche 30 du registre)
**Lot** : B1 — premier des six sous-lots B (« paiements d'abord parce que le
volume de données de staging y est faible et la logique isolable »)
**Date** : 9 septembre 2026 · **Banc** : sandbox locale (WordPress + SQLite,
127.0.0.1:8091) · **Environnement commanditaire** : staging Hostinger non
disponible ce jour (P-INT-0 différé par le commanditaire, ne bloque pas le lot)

| Artefact | Version | Git | SHA-256 du livrable |
|---|---|---|---|
| Plugin partikulier-core | **2.1.0** | `39e8058` | `3b2971a9242e3daa1faf24017bc1cede687bd8c3b8fb857cd8dd3fa97874ee6d` |
| Thème partikulier | **6.18.2** | `013dc66` | `4c11ecef50ac6e574ac542d5f2f8b333c69aeba15dbdc828826f49979c208d68` |

---

## 1. Périmètre livré

Le domaine **paiements/premium** migre du thème vers le plugin. Conformément à
l'annexe D du CDC, il couvre trois tables et deux modules du thème 6.17.x :

| Table | Module d'origine | Domaine | Propriétaire après B1 |
|---|---|---|---|
| `pk_payment_orders` | `class-payment-foundation.php` | payments | **plugin** |
| `pk_premium_subscriptions` | `class-payment-foundation.php` | payments | **plugin** |
| `pk_premium_history` | `class-premium.php` | premium | **plugin** |

**Côté plugin (2.1.0)** :

- `Schema` 2.1.0 : les trois DDL rejoignent les instructions dbDelta du plugin,
  **reproduits à l'identique** du thème (REG-6) ; le manifeste des vingt tables
  passe les trois entrées de `owner: theme` à `owner: plugin`.
- `Migrator` : étapes versionnées (les étapes 2.0.0 ne rejouent pas au passage
  2.0.0 → 2.1.0) + étape d'adoption `adopt_payment_premium_tables` —
  existence, comptages, empreinte de structure, consignée au registre d'audit
  (`domain_adopted`) et au journal de migration.
- `src/Domain/Premium/PremiumService.php` : port fidèle du journal premium —
  mêmes validations, mêmes codes d'erreur, mêmes méta-clés, mêmes transitions
  (grant, révocation, expiration paresseuse) ; transitions journalisées au
  registre d'audit (`premium_granted` / `premium_revoked` / `premium_expired`).
- `src/Domain/Payments/PaymentService.php` : commandes et abonnements — CRUD
  complet, transitions d'état (échec, abouti, activation, révocation), gate
  publique préservée (`create_order` → `WP_Error pk_payment_disabled`, le
  prestataire marocain reste non validé) ; transitions journalisées
  (`payment_order_failed`, `payment_order_paid`,
  `payment_subscription_activated`, `payment_subscription_revoked`).
- Chargement inconditionnel des deux services (couture `class_exists` du thème
  fiable sur toute requête) — coût mesuré par REG-2 : négligeable.
- Deux suites contractuelles nouvelles : `tests/premium-contract.php` (8),
  `tests/payments-contract.php` (10).

**Côté thème (6.18.2)** — coutures seules, zéro changement runtime visible :

- `inc/class-premium.php` et `inc/class-payment-foundation.php` deviennent des
  coutures : chaque opération est déléguée au service du plugin quand il est
  actif (`class_exists`), sinon le chemin autonome 6.17.x est conservé à
  l'identique (REG-5) ; l'installation du schéma par le thème est éteinte
  quand le plugin est présent.
- L'écran d'administration « Annonces premium » est inchangé et rend son
  journal via le service du plugin ; la gate paiement est inchangée.

## 2. Critères de sortie du lot B (CDC ch. 5)

| Critère | Exigence | Constat | Preuve |
|---|---|---|---|
| Tables lues/écrites côté plugin uniquement | les trois tables | **Atteint** — quand le plugin est actif, le thème ne touche plus les tables ; la délégation est prouvée par le registre d'audit (seul le plugin y écrit) | contrats PREM-002/PAY-001-002, `health.json` |
| Script de migration journalisé | idempotent, comptages | **Atteint** — adoption journalisée (`domain_adopted` + rapport d'option), comptages 0/0/0 conformes au T0, Migrator ×2 sans rejeu | `REG6-parite-structure-idempotence.json` |
| Rollback prouvé | journal d'exécution réelle | **Atteint** — T0 restauré + plugin 2.0.1 : 8/8 vérifications (health 2.0.0, domaines revenus thème, tables vides, front 200, repli legacy), retour avant : 8/8 | `rollback-preuve.txt` |
| Suites QA aux scores maximaux | CA-1 | **Atteint** — voir §3 | `contrats/*.json`, QA complète |

## 3. Recette (CA-1 / CA-2)

- **Contrats CA-2 : 76/76** sur 9 suites — 58 du lot A (core 8, services 7,
  rest-lite-scope 10, sync 14, leads 7, routes-collision 5, load 7) + **18
  nouvelles** (premium 8 : couture, CRUD journal, gardes, révocation,
  expiration paresseuse ; payments 10 : gate, CRUD commandes/abonnements,
  paiement échoué, paiement abouti, révocation d'abonnement, suppressions
  protégées). Transitions critiques du CDC couvertes : **paiement échoué**,
  **révocation premium** (journal ET abonnement), expiration.
- **QA thème** : rapide « ok » ×2 ; **visuel 12/12 vues à 0,00 %** ×2 contre la
  baseline antérieure au lot B1 (+ run final après rollback) ; **audit
  JSON-LD/hreflang 9/9** (fermeture geo du lot A conservée).
- **Front** : 200 ×6 (accueil, annonces fr/en/ar, contact, sitemap).
- **Health 2.1.0** : status ok, 0 fantôme, 0 manquant, 0 collision, domaines
  payments+premium `owner: plugin`.
- **CI** : thème 11/11 VERT, plugin 8/8 VERT (pin `Schema::VERSION` porté à
  2.1.0 — montée délibérée ; structure étendue aux 4 fichiers B1).
- **REG-2** : p95 244,0 ms sur 100 × `GET /listings?locale=fr` (référence lot A
  244,8 ms — **écart -0,3 %**, tolérance ±10 %) ; sonde bootstrap inchangée.
- **REG-6** : DDL et index des trois tables identiques T0 → T1 (dbDelta du
  plugin n'a rien modifié), Migrator idempotent.
- **REG-5** : matrice quatre combinaisons — A (6.18.2 + 2.1.0 : recette
  principale), B (6.18.2 sans plugin : chemin autonome, 6/6), C (6.18.1 +
  2.1.0 : plugin propriétaire + thème historique, 6/6), D (T0 historique) ;
  combinaison bonus « thème récent + plugin ancien » prouvée au rollback.

## 4. Arbitrages d'exécution documentés

1. **Journal premium = registre d'audit, pas d'entité suppressible** : le CDC
   exige le CRUD sur les « entités principales » — commandes et abonnements
   l'ont intégralement (PAY-003→010) ; la suppression d'une ligne du journal
   des attributions n'est pas une opération métier (traçabilité des décisions
   éditoriales), elle n'est pas exposée. Consigné dans les limitations du
   contrat premium.
2. **`record_order` comme primitive d'ingestion** : la passerelle publique
   reste fermée (gate prestataire G-paiement, inchangée) ; le CDC exigeant la
   transition « paiement échoué », l'ingestion interne est la voie exercée par
   le contrat — aucune route, aucun écran, aucun changement runtime.
3. **Fidélité plutôt que correction** : le chemin historique du thème
   retourne un identifiant de méta (`insert_id` lu après les
   `update_post_meta` — bug latent 6.17.x sans consommateur) ; il est conservé
   à l'identique dans le repli REG-5 et le **service du plugin retourne le bon
   identifiant** (corrigé au port, attrapé par PREM-002).

## 5. Incidents réels découverts et corrigés (tous consignés)

1. `insert_id` écrasé par les méta-écritures dans le port initial — corrigé,
   couvert par PREM-002.
2. `AuditLogger` non chargé sur page publique : la migration déclenchée en
   contexte front fatalait (le lot A ne l'avait jamais déclenchée hors
   REST/CLI) — `require_once` déterministe dans le Migrator et les services.
3. `sqlite_master` refusé par le traducteur SQLite : empreinte de structure
   via `SHOW CREATE TABLE` (supporté MySQL + traducteur, DDL canonique).
4. **WAL** : la base tourne en `journal_mode=wal` — toute restauration de
   fichier exige checkpoint avant copie et suppression des `-wal`/`-shm`
   après ; incident réel pendant la recette (rejeu de wal obsolète), corrigé
   dans les scripts de preuve.
5. **Origine navigateur** : les QA navigateur doivent viser `127.0.0.1:8091`
   (WP_HOME) — via `localhost`, la CSP `'self'` bloque toutes les
   sous-ressources (constat false-positive de régression visuelle).

## 6. Découvertes à remonter au commanditaire

- **T0 du domaine = 0 ligne sur les trois tables** (confirmé au snapshot) :
  l'adoption B1 est une reprise de propriété sans données à migrer — sur le
  staging réel, P-INT-0 devra mesurer le volume réel avant l'installation du
  lot B1 (si des lignes existent, l'adoption les journalise et les conserve
  telles quelles).
- La base du banc est en WAL : **les procédures de sauvegarde/restauration du
  staging Hostinger doivent être vérifiées pour l'équivalent** (mysqldump
  complet, pas de copie de fichiers chauds) — la même classe d'incident
  s'y produirait.
- P-INT-0 (T0 contractuelle sur staging réel) reste **l'action commanditaire
  en attente** ; l'installation du lot B1 sur le staging peut se faire sans
  risque avant ou après P-INT-0 (l'adoption est idempotente), mais la ligne
  de base contractuelle du CDC exige P-INT-0 avant recette finale.

## 7. Verdict

**Lot B1 : GO.** Les quatre critères de sortie du lot B sont atteints avec
preuves jointes ; aucune régression détectée (76/76 contrats, visuel 12/12 à
0,00 %, p95 -0,3 %, 0 fantôme, CI vert ×2) ; rollback prouvé en conditions
réelles ; matrice de compatibilité complète. Le socle et le premier domaine
métier sont désormais côté plugin — le lot B2 (leads/qualification/WhatsApp,
huit tables, migration REG-4, le plus lourd : 10-14 jh) peut démarrer.

*Livrables : `B-INSTALLER-PLUGIN-partikulier-core-2.1.0.zip` (82 631 o) +
`partikulier-theme-6.18.2.zip` (924 347 o), empreintes ci-dessus, intégrité
zip ↔ source vide ×2, 0 entrée `.github/`. Preuves complètes dans
`preuves-lot-B1/` (LISEZMOI avec commandes de rejeu).*
