# preuves-lot-B5 — LISEZMOI

Lot B5 de la refonte (CDC v1.2) : extraction du domaine **statistiques propriétaire** (favoris visiteurs agrégés, 1 table `pk_property_saves`) vers partikulier-core 2.5.0 (thème 6.18.6 en coutures). Toutes les preuves ci-dessous ont été produites sur la sandbox réelle (WordPress + SQLite traduit, port 8091) le 10 septembre 2026, plugin au commit git `5a6507f`.

## Contenu

| Entrée | Rôle |
|---|---|
| `T0-domaine-b5.json` + `T0-sauvegarde-base.sqlite` | État AVANT extraction : 1 table (`pk_property_saves` = **4 favoris réels**, pseudonymes HMAC de 4 visiteurs distincts sur l'annonce #199), DDL+index de référence, options du domaine, sauvegarde intégrale (sha d5162d9e…) ; **correction du relevé cron documentée dans le JSON** (incident n°1 du rapport : hooks WP imbriqués sous clés d'horodatage — l'événement daily ÉTAIT planifié au T0, relevé initial corrigé avec traçabilité) |
| `journal-recette.txt` | Journal intégral de la recette (7 étapes) — déploiement, migration, REG-6, contrats, hygiène, QA, front, REG-2, matrice, rollback (y compris le rejeu du correctif de quoting — incident documenté au rapport) |
| `contrats/` (13 suites) | Contrats JSON rejoués : 124 lots A/B1/B2/B3/B4 + 16 B5 = **140/140** ; `owner-stats-domain-contract.json` porte le commit `5a6507f` (correctif du détail B5A-013 inclus) |
| `REG6-parite-structure-donnees-idempotence.json` | Parité DDL + index T0→T1, **4 favoris réels préservés (empreintes de lignes identiques)**, Migrator ×2 idempotent (0 étape rejouée) |
| `REG5-B-plugin-absent.json` / `REG5-C-theme-6185.json` | Sonde B 7/7 (chemin autonome, preuve par absence d'audit, cron relié côté thème) / sonde C 7/7 (plugin propriétaire au manifeste, thème 6.18.5 historique **sans couture alors que le service est chargé**, **0 collision** — aucune route déplacée au lot B5) |
| `rollback-preuve.txt` | 11/11 : aller T0 + plugin 2.4.0 (owner_stats revenu theme, **favori enregistré puis retiré par le chemin autonome du thème sans audit**, 10 leads + 2 événements + 4 favoris intacts), retour nominal 2.5.0 re-vérifié (0 fantôme, 0 collision) |
| `T1-b5-apres-rollback-reapplique.sqlite` | Snapshot de l'état B5 (wal intégré) utilisé par le retour arrière |
| `rapide-1/2.txt`, `visual-1/2.txt` | QA thème : rapide « ok » ×2, visuel **12/12 ×2 à 0,00 %** (baseline B2) |
| `audit-jsonld-hreflang.txt` | Audit JSON-LD/hreflang 9/9 |
| `pages-front.txt` | Front 200 ×6 (fr/en/ar, contact, sitemap) |
| `health.json`, `health-final.json` | Health HTTP après migration puis en fin de recette (ok, 2.5.0/2.5.0, 0 fantôme, 0 collision, **7/8 domaines plugin**) |
| `reg2-latences.txt`, `reg2-sonde-bootstrap.txt` | REG-2 : p50 223,1 / p95 231,7 ms (réf. B4 229,0, tolérance ±10 %) + sonde bootstrap |
| `rejeu-ci-theme.txt`, `rejeu-ci-plugin.txt` | CI locaux : thème **11/11**, plugin **8/8** (pin 2.5.0, structure étendue OwnerStatsService + contrat — rejeu local réaligné, incident n°3 du rapport) |
| `journal-build.txt` | Construction des livrables (zips + sha + intégrité zip↔source, deux builds → sha identiques) |

## Rejeu (sandbox vivante, serveur 8091)

```bash
# Sandbox : bash scripts/sbx-up.sh (idempotent) — origin 127.0.0.1 obligatoire (CSP)
/home/z/my-project/bench/php scripts/b5-t0-snapshot.php

# Recette COMPLÈTE (mono-processus, terminal humain) :
bash scripts/b5-recette.sh tout

# …ou PAR ÉTAPES (harnais agent : les processus d'arrière-plan sont fauchés
# entre invocations — chaque étape tient au premier plan sous 10 minutes) :
bash scripts/b5-recette.sh 1   # déploiement + T0 + migration + health + REG-6
bash scripts/b5-recette.sh 2   # 65 s d'attente limiteur + 13 contrats + hygiène
bash scripts/b5-recette.sh 3   # QA thème (rapide/visual ×2) + audit JSON-LD
bash scripts/b5-recette.sh 4   # front ×6 + health final + REG-2
bash scripts/b5-recette.sh 5   # matrice REG-5 (B puis C)
bash scripts/b5-recette.sh 6   # preuve de rollback (aller T0 + 2.4.0, retour nominal)
bash scripts/b5-recette.sh 7   # CI locaux (thème 11/11 + plugin 8/8)

# Contrat B5 seul (commit courant du repo plugin) :
cd /home/z/my-project/bench/sbx && \
  PK_WP_DIR=$PWD PK_COMMIT=$(git -C /home/z/my-project/lots/lot1/partikulier-core rev-parse HEAD) \
  /home/z/my-project/bench/php /home/z/my-project/lots/lot1/partikulier-core/tests/owner-stats-domain-contract.php

# Construction des livrables (zips + sha256 + intégrité) :
bash scripts/build-b5.sh
```

## Ordre de preuve (CDC CA-1/CA-2)

T0 restauré → migration 2.5.0 au premier hit → **REG-6 avant tout contrat** (identité de structure + préservation des 4 favoris réels + idempotence) → contrats 140/140 → hygiène du banc (4/2/0/10 — zéro fuite) → QA (visuel/rapide/JSON-LD) → front + health → REG-2 → matrice REG-5 → rollback aller-retour → CI. Le détail des 3 incidents réels du lot et des 5 arbitrages : `../RAPPORT-LOT-B5-REFACTORY-PARTIKULIER.md`.
