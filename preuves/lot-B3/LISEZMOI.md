# preuves-lot-B3 — LISEZMOI

Lot B3 de la refonte (CDC v1.2) : extraction du domaine **alertes** vers partikulier-core 2.3.0 (thème 6.18.4 en coutures). Toutes les preuves ci-dessous ont été produites sur la sandbox réelle (WordPress + SQLite traduit, port 8091) le 10 septembre 2026, plugin au commit git `5e0a8d3`.

## Contenu

| Entrée | Rôle |
|---|---|
| `T0-domaine-b3.json` + `T0-sauvegarde-base.sqlite` | État AVANT extraction : 2 tables vides, DDL+index de référence, option `pk_saved_alerts_db_version`=1.0.0, sauvegarde intégrale (sha 85dd06a2…) |
| `journal-recette.txt` | Journal intégral de la recette (7 étapes) — déploiement, migration, REG-6, contrats, hygiène, QA, front, REG-2, matrice, rollback, CI |
| `contrats/` (11 suites) | Contrats JSON rejoués : 92 lots A/B1/B2 + 15 B3 = **107/107** ; chaque JSON porte le commit `5e0a8d3` |
| `REG6-parite-structure-donnees-idempotence.json` | Parité DDL + index T0→T1, tables préservées, Migrator ×2 idempotent |
| `REG5-B-plugin-absent.json` / `REG5-C-theme-6183.json` | Sonde B 6/6 (chemin autonome, preuve par absence d'audit) / sonde C 7/7 (plugin propriétaire, thème 6.18.3 historique, 0 collision — aucune route) |
| `matrice-reg5.txt` | Journal de la matrice (B et C, retour nominal) |
| `rollback-preuve.txt` | 9/9 : aller T0 + plugin 2.2.0 (alerts revenu theme, sauvegarde d'alerte par le chemin du thème), retour nominal 2.3.0 re-vérifié |
| `T1-b3-apres-rollback-reapplique.sqlite` | Snapshot de l'état B3 (wal intégré) utilisé par le retour arrière |
| `rapide-1/2.txt`, `visual-1/2.txt` | QA thème : rapide « ok » ×2, visuel **12/12 ×2 à 0,00 %** (baseline B2) |
| `audit-jsonld-hreflang.txt` | Audit JSON-LD/hreflang 9/9 |
| `pages-front.txt` | Front 200 ×6 (fr/en/ar, contact, sitemap) |
| `health.json`, `health-final.json` | Health HTTP après migration puis en fin de recette (ok, 2.3.0/2.3.0, 0 fantôme, 0 collision, **5/8 domaines plugin**) |
| `reg2-latences.txt`, `reg2-sonde-bootstrap.txt` | REG-2 : p95 228,1 ms (réf. B2 232,5-248,2, tolérance ±10 %) + sonde bootstrap |
| `rejeu-ci-theme.txt`, `rejeu-ci-plugin.txt` | CI locaux : thème **11/11**, plugin **8/8** (pin 2.3.0, structure étendue — correctif du workflow réel) |
| `journal-build.txt` | Construction des livrables (zips + sha + intégrité zip↔source) |

## Rejeu (sandbox vivante, serveur 8091)

```bash
# Sandbox : bash scripts/sbx-up.sh (idempotent) — origin 127.0.0.1 obligatoire (CSP)
bash scripts/b3-t0-snapshot.php 2>/dev/null || /home/z/my-project/bench/php scripts/b3-t0-snapshot.php

# Recette COMPLÈTE (mono-processus, terminal humain) :
bash scripts/b3-recette.sh tout

# …ou PAR ÉTAPES (harnais agent : les processus d'arrière-plan sont fauchés
# entre invocations — chaque étape tient au premier plan sous 10 minutes) :
bash scripts/b3-recette.sh 1   # déploiement + T0 + migration + health + REG-6
bash scripts/b3-recette.sh 2   # 65 s d'attente limiteur + 11 contrats + hygiène
bash scripts/b3-recette.sh 3   # QA thème (rapide/visual ×2) + audit JSON-LD
bash scripts/b3-recette.sh 4   # front ×6 + health final + REG-2
bash scripts/b3-recette.sh 5   # matrice REG-5 (B puis C)
bash scripts/b3-recette.sh 6   # preuve de rollback 9/9
bash scripts/b3-recette.sh 7   # CI thème + plugin

# Construction des livrables :
bash scripts/build-b3.sh
```

Le smoke test unitaire du service (`scripts/b3-smoke.php`) reste disponible pour un contrôle rapide du chemin `save_alert` (lead + consentement + alerte + upsert + statut + nettoyage).

## Vérification des livrables

```bash
sha256sum B-INSTALLER-PLUGIN-partikulier-core-2.3.0.zip partikulier-theme-6.18.4.zip
# à comparer aux .sha256 joints (682a329a… / 024de072…)
```
