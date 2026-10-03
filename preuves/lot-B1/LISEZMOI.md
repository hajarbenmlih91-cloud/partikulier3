# Preuves du lot B1 — paiements/premium extraits vers le plugin (9 septembre 2026)

Rejeu sur banc réel (WordPress + SQLite, 127.0.0.1:8091). Plugin **2.1.0** (git
`39e8058`), thème **6.18.2** (git `013dc66`). Toutes les commandes sont
rejouables ; les chemins sont donnés depuis la racine du projet.

| Fichier | Contenu | Commande de rejeu |
|---|---|---|
| `T0-domaine-b1.json` | État du domaine AVANT lot B1 : 3 tables existantes et **vides** (0 ligne), options de version thème 1.0.0, drapeau public 0, 0 méta premium, DDL SQLite de référence | `php scripts/b1-t0-snapshot.php` |
| `T0-sauvegarde-base.sqlite` | Sauvegarde complète de la base au T0 (rollback possible) | copie vers `wp-content/database/.ht.sqlite` **après** `PRAGMA wal_checkpoint(TRUNCATE)` et suppression des `-wal`/`-shm` |
| `T1-b1-apres-rollback-reapplique.sqlite` | Snapshot de l'état B1 final (réappliqué après la preuve de rollback, wal intégré) | idem |
| `contrats/*.json` | Les **9 suites** contractuelles rejouées (76/76 PASS) : 7 du lot A (58) + premium-contract (8) + payments-contract (10) | `PK_WP_DIR=<wp> PK_COMMIT=<sha> php partikulier-core/tests/<suite>.php` |
| `REG6-parite-structure-idempotence.json` | Preuve REG-6 : DDL des 3 tables identique T0→T1, index en parité, second passage du Migrator sans étape rejouée, structure stable après rejeu dbDelta | `php scripts/b1-reg6-preuve.php` (depuis `bench/sbx`) |
| `REG5-B-plugin-absent.json` | Matrice REG-5, combinaison B : thème 6.18.2 + plugin désactivé → chemin autonome 6.17.x (6/6) | orchestrateur `bash scripts/b1-reg5-matrice.sh` |
| `REG5-C-theme-61718.json` | Matrice REG-5, combinaison C : thème 6.18.1 + plugin 2.1.0 → plugin propriétaire, thème historique (6/6) | idem |
| `rollback-preuve.txt` | Preuve de rollback : T0 restauré + plugin 2.0.1 → vérifications 8/8, retour à l'état B1 → vérifications 8/8 | `bash scripts/b1-rollback-preuve.sh` |
| `health.json` | Health check 2.1.0 : status ok, domaines payments+premium owner=plugin, 0 fantôme, 0 collision | `curl http://127.0.0.1:8091/wp-json/partikulier/v1/health` |
| `pages-front.txt` | Pages front 200 ×6 (fr, annonces fr/en/ar, contact, sitemap) | `curl -o /dev/null -w "%{http_code}" <url>` |
| `rapide-1/2.txt`, `visual-1/2.txt`, `visual-final.txt` | QA thème : rapide « ok » ×2, visuel 12/12 vues à 0,00 % ×2 (baseline antérieure au lot B1) + run final après rollback | `PK_BASE=http://127.0.0.1:8091 node tests/rapide.mjs check` / `node tests/visual.mjs check` |
| `audit-jsonld-hreflang.txt`, `audit-jsonld-hreflang-final.txt` | Audit JSON-LD/hreflang : 9/9 (fermeture geo du lot A conservée) | `node scripts/audit-jsonld-hreflang.mjs` |
| `reg2-latences.txt` + `reg2-sonde-bootstrap.txt` | REG-2 : 100 × `GET /listings?locale=fr` → p50 234,7 ms / **p95 244,0 ms** (référence lot A 244,8 ms — écart -0,3 %, tolérance ±10 %) ; sonde bootstrap : 2 plugins chargés sur /listings, inchangé | voir `scripts/b1-recette.sh` |
| `rejeu-ci-theme.txt` | CI thème rejeu local : **11/11 VERT** | `bash scripts/ci-rejeu-local.sh` |
| `rejeu-ci-plugin.txt` | CI plugin rejeu local : **8/8 VERT** (pin Schema::VERSION porté à 2.1.0) | `bash scripts/ci-rejeu-plugin-local.sh` |

## Incidents réels découverts et corrigés pendant le lot (tous consignés)

1. **`insert_id` écrasé** : le port initial de `PremiumService::grant()` lisait
   `$wpdb->insert_id` APRÈS les `update_post_meta` (qui écrasent la valeur avec
   un identifiant de méta) — attrapé par le contrat PREM-002 ; corrigé en
   capturant l'identifiant immédiatement après l'insert. Le même bug existe en
   LATENCE dans le chemin historique du thème 6.17.x (jamais consommé, jamais
   vu) — consigné, non corrigé hors périmètre.
2. **`AuditLogger` non chargé sur page publique** : la migration déclenchée sur
   une page front fatalait (classe chargée paresseusement) — le lot A ne l'avait
   jamais déclenchée hors REST/CLI. Corrigé : `require_once` déterministe dans
   le Migrator et les deux services.
3. **`sqlite_master` refusé par le traducteur SQLite** (sonde) : l'empreinte de
   structure passe par `SHOW CREATE TABLE`, supporté par MySQL ET le traducteur.
4. **WAL et copies de fichiers** : la base tourne en `journal_mode=wal` ; les
   restaurations de fichiers DOIVENT faire un checkpoint avant copie et
   supprimer les `-wal`/`-shm` après (sinon rejeu de wal obsolète — incident
   réel pendant la recette, corrigé dans le script de preuve).
5. **Origine navigateur** : toute QA navigateur doit visiter `127.0.0.1:8091`
   (pas `localhost`) — les URL absolues des assets portent 127.0.0.1 (WP_HOME)
   et la CSP `'self'` bloque toute origine différente.

Voir `../RAPPORT-LOT-B1-REFACTORY-PARTIKULIER.md` pour la lecture complète.
