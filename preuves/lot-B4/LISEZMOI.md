# preuves-lot-B4 — LISEZMOI

Lot B4 de la refonte (CDC v1.2) : extraction du domaine **automatisation n8n** vers partikulier-core 2.4.0 (thème 6.18.5 en coutures). Toutes les preuves ci-dessous ont été produites sur la sandbox réelle (WordPress + SQLite traduit, port 8091) le 10 septembre 2026, plugin au commit git `4fde1f0`.

## Contenu

| Entrée | Rôle |
|---|---|
| `T0-domaine-b4.json` + `T0-sauvegarde-base.sqlite` | État AVANT extraction : 2 tables (`pk_automation_events` = **2 événements réels « received »**, `pk_n8n_hmac_audit` = 0 ligne), DDL+index de référence, options du domaine (secret **masqué** dans le journal — incident n°1 du rapport, corrigé avant tout test), sauvegarde intégrale (sha 6b8184ae…) |
| `journal-recette.txt` | Journal intégral de la recette (7 étapes) — déploiement, migration, REG-6, contrats, hygiène, QA, front, REG-2, matrice, rollback (y compris les rejeux des correctifs — incidents documentés au rapport) |
| `contrats/` (12 suites) | Contrats JSON rejoués : 107 lots A/B1/B2/B3 + 17 B4 = **124/124** ; chaque JSON porte le commit `4fde1f0` |
| `REG6-parite-structure-donnees-idempotence.json` | Parité DDL + index T0→T1, **2 événements réels préservés (empreintes identiques)**, Migrator ×2 idempotent |
| `REG5-B-plugin-absent.json` / `REG5-C-theme-6184.json` | Sonde B 6/6 (chemin autonome, preuve par absence d'audit) / sonde C 7/7 (plugin propriétaire, thème 6.18.4 historique, **collision transitional /automation-event refusée et journalisée par INTEG-3** — détection conçue) |
| `matrice-reg5.txt` | Journal de la matrice (B et C, retour nominal) |
| `rollback-preuve.txt` | 10/10 : aller T0 + plugin 2.3.0 (automation revenu theme, réception d'événement par le chemin autonome du thème, 10 leads + 2 événements intacts), retour nominal 2.4.0 re-vérifié (0 collision) |
| `T1-b4-apres-rollback-reapplique.sqlite` | Snapshot de l'état B4 (wal intégré) utilisé par le retour arrière |
| `rapide-1/2.txt`, `visual-1/2.txt` | QA thème : rapide « ok » ×2, visuel **12/12 ×2 à 0,00 %** (baseline B2) |
| `audit-jsonld-hreflang.txt` | Audit JSON-LD/hreflang 9/9 |
| `pages-front.txt` | Front 200 ×6 (fr/en/ar, contact, sitemap) |
| `health.json`, `health-final.json` | Health HTTP après migration puis en fin de recette (ok, 2.4.0/2.4.0, 0 fantôme, 0 collision, **6/8 domaines plugin**) |
| `reg2-latences.txt`, `reg2-sonde-bootstrap.txt` | REG-2 : p50 220,4 / p95 229,0 ms (réf. B3 228,1, tolérance ±10 %) + sonde bootstrap |
| `rejeu-ci-theme.txt`, `rejeu-ci-plugin.txt` | CI locaux : thème **11/11**, plugin **8/8** (pin 2.4.0, structure étendue AutomationService + contrat) |
| `journal-build.txt` | Construction des livrables (zips + sha + intégrité zip↔source, deux builds → sha identiques) |

## Rejeu (sandbox vivante, serveur 8091)

```bash
# Sandbox : bash scripts/sbx-up.sh (idempotent) — origin 127.0.0.1 obligatoire (CSP)
/home/z/my-project/bench/php scripts/b4-t0-snapshot.php

# Recette COMPLÈTE (mono-processus, terminal humain) :
bash scripts/b4-recette.sh tout

# …ou PAR ÉTAPES (harnais agent : les processus d'arrière-plan sont fauchés
# entre invocations — chaque étape tient au premier plan sous 10 minutes) :
bash scripts/b4-recette.sh 1   # déploiement + T0 + migration + health + REG-6
bash scripts/b4-recette.sh 2   # 65 s d'attente limiteur + 12 contrats + hygiène
bash scripts/b4-recette.sh 3   # QA thème (rapide/visual ×2) + audit JSON-LD
bash scripts/b4-recette.sh 4   # front ×6 + health final + REG-2
bash scripts/b4-recette.sh 5   # matrice REG-5 (B puis C)
bash scripts/b4-recette.sh 6   # preuve de rollback 10/10
bash scripts/b4-recette.sh 7   # CI thème + plugin

# Construction des livrables :
bash scripts/build-b4.sh
```

Le smoke test HTTP réel de la route (`scripts/b4-smoke.php`) reste disponible pour un contrôle rapide du parcours complet (401 sans secret → 401 secret seul → 200 signé → 200 duplicate, nettoyage inclus).

## Vérification des livrables

```bash
sha256sum -c B-INSTALLER-PLUGIN-partikulier-core-2.4.0.zip.sha256 partikulier-theme-6.18.5.zip.sha256
unzip -l B-INSTALLER-PLUGIN-partikulier-core-2.4.0.zip | grep -E "Automation|automation-domain"   # domaine B4 embarqué
```

La sandbox nominale est vérifiable en continu : `curl http://127.0.0.1:8091/wp-json/partikulier/v1/health` → `ok`, core 2.4.0, schéma 2.4.0, domaines plugin **6/8** (listings, payments, premium, leads, alerts, automation), 0 collision.
