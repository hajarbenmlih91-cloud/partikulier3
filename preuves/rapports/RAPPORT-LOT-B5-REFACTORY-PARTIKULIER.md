# Rapport de recette — Lot B5 (domaine statistiques propriétaire)

**Projet** : Refonte Partikulier (CDC v1.2) · **Lot** : B5 — statistiques propriétaire (favoris visiteurs agrégés, 1 table)
**Livrables** : `B-INSTALLER-PLUGIN-partikulier-core-2.5.0.zip` (134 332 o, sha256 `0b2243c346b32ba54072586fed2d728c0a45c4e25069cb7f0806b2e830170ecf`) + `partikulier-theme-6.18.6.zip` (930 741 o, sha256 `5842d80468420e2803e477a798d830d6e7bbf0b502007cc90fbd9d8b13ab5382`)
**Repos git** : plugin `5a6507f` (main, 2 commits B5 sur `4fde1f0`), thème `c4dec3d` (main) — prêts à pousser
**Date** : 10 septembre 2026 · **Verdict : GO**

---

## 1. Périmètre livré

Le lot B5 extrait du thème le domaine **statistiques propriétaire** : la table `pk_property_saves` (une ligne active par annonce et navigateur pseudonymisé — HMAC sha256 non réversible, jamais d'identité, d'IP ni de donnée WhatsApp). C'est la **deuxième adoption du lot B avec de la donnée réelle côté lignes de visite** : le T0 du banc portait **4 favoris réels** (4 visiteurs distincts sur l'annonce #199, 7–8 septembre), préservés byte pour byte (REG-6, empreintes de lignes identiques).

Côté plugin (2.4.0 → **2.5.0**) :

- **`Schema` 2.5.0** : le DDL repris **à l'identique** de `class-owner-insights.php` (REG-6 — mêmes colonnes, mêmes clés, mêmes noms d'index, y compris le double espace historique après `PRIMARY KEY`) ; manifeste : l'entrée `owner_stats` passe de `theme` à `plugin`, lot B5. Le domaine devient le **7/8** possédé par le plugin (listings, payments, premium, leads, alerts, automation, owner_stats) — ne reste au thème que `translation_variants` (B6).
- **`Migrator`** : étape 2.5.0 versionnée `adopt_owner_stats_tables` — adoption journalisée (existence, comptage 4, empreinte de structure), idempotente, **aucune donnée déplacée** (refacteur commun `adoptTable()` du lot B4).
- **`src/Domain/OwnerStats/OwnerStatsService.php`** (port fidèle de `Partikulier_Owner_Insights`) : `sync_favorite` (gardes `pk_invalid_favorite`/`pk_unknown_favorite_property` héritées, pseudonymisation HMAC `favorite-v1|<visiteur>` avec `wp_salt('auth')`, plafonnement **60 mises à jour/heure par visiteur** via transient — `pk_favorite_rate_limited` 429, upsert `ON DUPLICATE KEY` par (annonce, visiteur) — rétention glissante, retrait par suppression de ligne), `favorite_count` (agrégat propriétaire, fenêtre 90 jours sur `updated_at`), `purge_expired_saves` (DELETE de rétention, retourne le comptage), `maybe_schedule_purge` (port du bloc de planification : même événement, même récurrence daily, même décalage d'une heure) ; type de contenu en **littéral** `'properties'` (aucune dépendance de constante thème, même discipline que l'option littérale B4) ; audits `owner_favorite_saved`/`owner_favorite_removed`/`owner_saves_purged` comme preuve d'exécution (la purge n'écrit que si elle supprime, pattern `leads_purged` B2).
- **Bootstrap** : chargement inconditionnel + `plugins_loaded` → `init` 20 `maybe_schedule_purge` + liaison du handler `pk_owner_insights_daily_purge` → `purge_expired_saves` (pattern rétention B2 : le thème 6.18.6+ cesse de les enregistrer quand le service existe).
- **`Contrat`** `tests/owner-stats-domain-contract.php` : **16 assertions** (couture, adoption + 4 favoris T0 préservés, save nominal + HMAC 64 hex + audit, upsert idempotent même id, remove + audit, gardes ×3, annonce inconnue, plafond 429 sans écriture, agrégat coexistant avec les données réelles, fenêtre 90 jours excluant un pseudonyme périmé, purge ciblée + lignes actives intactes, constantes héritées, cron planifié + **handler plugin lié / thème délié**, couture thème, health 7/8 + 0 collision, routes /owner/* toujours déclarées).
- **CI** : workflow épinglé 2.4.0 → **2.5.0**, structure étendue (`OwnerStatsService`, `owner-stats-domain-contract`) — rejeu local **8/8 VERT**.

Côté thème (6.18.5 → **6.18.6**, coutures seules) :

- `class-owner-insights.php` : `maybe_install` éteint quand le plugin détient le schéma (ET la planification du cron) ; `sync_favorite`, `favorite_count`, `purge_expired_saves` délèguent à `OwnerStatsService` ; la liaison du handler cron devient conditionnelle (thème délié quand le service existe).
- **Restent au thème** (arbitrage B5) : les écrans et points d'intégration — handlers AJAX `pk_sync_favorite`/`pk_favorites_list` (nonce + rendu des cartes de la page Favoris), routes REST `/owner/dashboard` et `/owner/listings/<id>/action` (agrégation `get_posts` + permaliens + gestion d'annonce via `Partikulier_Dashboard`), écran « Réglages » — ils ne touchent la table que par les primitives désormais déléguées. Sans le plugin, le chemin autonome 6.17.x est conservé à l'identique (REG-5).
- Aucun autre fichier runtime touché (ni gabarit, ni style, ni JavaScript) ; bump ×5 + changelog readme.

## 2. Critères de sortie du lot B (CDC, tableau 4) — tous atteints

| Critère | Preuve | Résultat |
|---|---|---|
| Tables lues/écrites côté plugin uniquement | Contrat 16/16 (audits `owner_favorite_saved`/`owner_favorite_removed` comme preuve d'exécution) + matrice REG-5 (absence d'audit = chemin thème) + `favorite_count` délégué (lecture) | **Atteint** |
| Migration journalisée, idempotente | `REG6-parite-structure-donnees-idempotence.json` : DDL identique, index identiques, **4 favoris réels préservés (4=4, empreintes de lignes identiques)**, 2ᵉ passage 0 étape | **Atteint** |
| Rollback prouvé en conditions réelles | `rollback-preuve.txt` : **11/11** (aller T0 + plugin 2.4.0 — favori enregistré puis retiré par le chemin autonome du thème sans audit — retour nominal re-vérifié) | **Atteint** |
| Suites QA aux scores maximaux | Contrats **140/140** (124 A/B1/B2/B3/B4 + 16 B5) · visuel **12/12 ×2 à 0,00 %** · rapide « ok » ×2 · audit JSON-LD **9/9** · front 200 ×6 · p95 dans la tolérance · CI thème **11/11** + plugin **8/8** | **Atteint** |

## 3. Non-régression mesurée

- **Visuel 12/12 ×2 à 0,00 %** contre la baseline du lot B2 (référence pixel inchangée).
- **REG-2** : p50 223,1 ms / p95 **231,7 ms** (référence lot B4 : p95 229,0 ms, tolérance ±10 %) ; sonde bootstrap : court-circuit identique (`partikulier_core` chargé, 2 plugins actifs).
- **Health final** : `ok`, core 2.5.0, schéma 2.5.0, 0 orphelin, 0 manquant, 0 collision, domaines plugin **7/8**.
- **Hygiène du banc** après contrats : favoris **4/4** (les données réelles du T0, aucune fuite), événements 2/2, audit HMAC 0, leads 10/10.

## 4. Incidents réels découverts et corrigés pendant le lot

1. **Sonde cron du T0 erronée (corrigée, avec traçabilité)** : le relevé initial annonçait le cron daily « non planifié » au T0 — **artefact de lecture** : dans l'option `cron` de WordPress, les hooks vivent imbriqués sous des clés d'horodatage (`[ts => [hook => args]]`), un `isset` racine sur le nom du hook retourne toujours faux. La sonde de rollback (« cron-present » en état T0 restauré) contredisait le relevé : c'est **la sonde qui disait vrai**. Corrigé dans `b5-t0-snapshot.php` (parsing imbriqué) + journal de preuve corrigé de manière traçable (`b5-t0-cron-corrige.php` : sha256 de la sauvegarde T0 re-vérifié avant correction, relevé initial conservé dans le JSON) + détail du contrat B5A-013 corrigé et suite re-prouvée 16/16 — l'assertion elle-même (planifié + handler plugin lié + thème délié) était et reste exacte.
2. **Défaut de quoting bash dans le contrôle final du rollback (corrigé avant archivage)** : un guillemet non échappé dans la chaîne `eval` terminait la chaîne externe → erreur de parse bash après le 10ᵉ contrôle (10/10 vérifiés mais script avorté). Réécriture avec **variables précalculées** (pattern robuste) → re-jeu complet **11/11**.
3. **Rejeu local CI plugin resté épinglé à 2.4.0** (même famille que la découverte B3 — le workflow GitHub réel était, lui, correctement passé à 2.5.0) : pin + liste de structure du rejeu local réalignés (2.5.0 + OwnerStatsService + contrat B5) → 8/8 VERT. Le rejeu local et le workflow partagent la même structure : les deux sont désormais vérifiés alignés à chaque lot.

## 5. Arbitrages documentés

1. **Écrans et routes d'intégration au thème, primitives de table au plugin** (pattern « écran leads » B2) : les handlers AJAX (nonce, rendu des cartes), la page Favoris et les routes `/owner/dashboard` + `/owner/listings/<id>/action` restent au thème — agrégation `get_posts`, permaliens, gestion d'annonce via `Partikulier_Dashboard` : rien de tout cela ne lit la table autrement que par `favorite_count` (délégué). Le lot B5 ne déplace **aucune route** → zéro collision, y compris transitional (contrairement au lot B4 qui déplaçait `/automation-event`).
2. **Cron daily propriété du plugin** (pattern rétention B2) : planification + handler liés par le bootstrap du plugin quand le service existe ; le thème cesse de les enregistrer. En combinaison croisée C (thème 6.18.5 sans garde + plugin 2.5.0), les deux handlers sont temporairement liés — double purge idempotente, aucun effet observé, bruit transitional documenté.
3. **Type de contenu en littéral** : le service référence `'properties'` en constante propre (comme `PremiumService`, `PaymentService`, `LeadService`, `ListingSynchronizer`) — aucune dépendance de constante thème.
4. **`purge_expired_saves` retourne le nombre de lignes supprimées** (le thème historique retournait void) : comportement identique, l'information de comptage sert de preuve au contrat et à l'audit (`owner_saves_purged`, écrit seulement si > 0, pattern `leads_purged` B2) ; la couture thème ignore la valeur de retour.
5. **Comportement « clé de plafonnement » porté fidèlement** : 60 mises à jour/heure par visiteur via transient, exactement l'héritage du thème (assertion 429 sans écriture).

## 6. Matrice REG-5 (dégradation gracieuse)

| Combo | Thème | Plugin | Verdict | Preuve clé |
|---|---|---|---|---|
| A | 6.18.6 | 2.5.0 | **PASS** | Recette principale : contrats 140/140, health owner_stats=plugin, 0 collision |
| B | 6.18.6 | absent | **PASS** (7/7) | `sync_favorite` par le chemin autonome : ligne conforme + upsert idempotent, **aucune ligne d'audit** (preuve du chemin), `favorite_count` lu en direct, handler cron **relié côté thème** |
| C | 6.18.5 | 2.5.0 | **PASS** (7/7) | Health owner_stats=plugin (manifeste), chemin historique du thème sans audit **alors même que le service est chargé**, `/owner/dashboard` servie par le thème via le registre INTEG-3, **0 collision** (aucune route déplacée) |
| D | 6.18.5 | absent | État T0 | Preuve de rollback (11/11) |

## 7. Livrables et preuves

- **Zips** : `download/B-INSTALLER-PLUGIN-partikulier-core-2.5.0.zip` (sha `0b2243c3…`) + `download/partikulier-theme-6.18.6.zip` (sha `5842d804…`) — intégrité zip ↔ source **vide** ×2, 0 entrée `.github`, **construction reproductible** (deux builds successifs → sha identiques).
- **Preuves** : `download/preuves-lot-B5/` — T0 (domaine + sauvegarde complète sha `d5162d9e…` + correction cron documentée), `journal-recette.txt` (7 étapes), `journal-build.txt`, 13 contrats JSON (dont `owner-stats-domain-contract.json` 16/16 @ commit `5a6507ff`), `REG6-parite-structure-donnees-idempotence.json`, `REG5-B-plugin-absent.json` (7/7) + `REG5-C-theme-6185.json` (7/7), `rollback-preuve.txt` (11/11) + `T1-b5-apres-rollback-reapplique.sqlite`, `health.json` + `health-final.json`, `rapide-1/2.txt`, `visual-1/2.txt` (12/12, 0,00 %), `audit-jsonld-hreflang.txt` (9/9), `pages-front.txt` (200 ×6), `reg2-latences.txt` + `reg2-sonde-bootstrap.txt`, `rejeu-ci-theme.txt` (11/11) + `rejeu-ci-plugin.txt` (8/8).
- **Repos** : plugin `5a6507f` (2 commits B5 : port + correctif de détail du contrat), thème `c4dec3d` — arbres propres. Restes côté commanditaire inchangés : **P-INT-0** (T0 contractuelle sur le staging réel), **push GitHub des 2 repos** (le CI plugin s'exécutera au premier push).
- **Prochaine étape** : lot B6 — variantes de traduction (`pk_property_variants`, 1 table, `class-localization.php` 990 lignes à découper — le plus lourd des sous-lots B restants, il conditionne le lot C d'unification i18n).
