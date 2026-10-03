# Preuves du lot B2 — leads/qualification/WhatsApp (plugin 2.2.0 + thème 6.18.3)

Rejeu intégral possible depuis ce dossier. Toutes les commandes supposent :
- la sandbox vivante : `bash /home/z/my-project/scripts/sbx-up.sh`
- le code source : plugin `lots/lot1/partikulier-core` @ `9563774` (main), thème `lots/lot-scenario9/partikulier` @ `71daf74` (main)
- PHP du banc : `/home/z/my-project/bench/php`

## Ordre de rejeu recommandé (celui de la recette)

```bash
# 1. Recette complète (déploiement + retour à T0 + migration + REG-6 + contrats
#    + hygiène + QA + audit + front + health + REG-2 + CI) :
bash /home/z/my-project/scripts/b2-recette.sh

# 2. Matrice REG-5 (combinaisons B et C) :
bash /home/z/my-project/scripts/b2-reg5-matrice.sh

# 3. Preuve de rollback (aller T0+2.1.0, retour T1+2.2.0) :
bash /home/z/my-project/scripts/b2-rollback-preuve.sh
```

## Contenu

| Fichier | Preuve |
|---|---|
| `T0-domaine-b2.json` + `T0-sauvegarde-base.sqlite` | T0 verrouillé avant extraction : 8 tables existantes, 10 lignes réelles dans `pk_buyer_leads`, DDL + index de référence, option `pk_buyer_qualification_db_version = 1.1.0`, sauvegarde complète (WAL intégré, sha256 `41d55622…`) |
| `health.json` / `health-final.json` | Health 2.2.0 : `ok`, domaines plugin 4/8 (leads, listings, payments, premium), 0 orphelin, 0 collision, 30 servis |
| `REG6-parite-structure-donnees-idempotence.json` | DDL des 8 tables IDENTIQUE T0 → T1 ; mêmes noms d'index ; **les 10 leads identiques ligne à ligne** (empreintes MD5) ; second passage Migrator : 0 étape rejouée, structure et données stables |
| `contrats/*.json` (10 suites) | **92/92** assertions (76 des lots A/B1 + 16 B2), chaque JSON porte le commit source `9563774…` |
| `REG5-B-plugin-absent.json` | Combinaison B : 6/6 — thème 6.18.3 autonome (chemin 6.17.x), **preuve par absence de ligne d'audit** `lead_authorized` (seul le plugin écrit le registre) |
| `REG5-C-theme-6182.json` | Combinaison C : 7/7 — thème 6.18.2 + plugin 2.2.0 : health leads=plugin, chemin historique sans audit, route `/erase-lead` servie + seconde déclaration REFUSÉE par le registre INTEG-3 (détection conçue du décalage de versions) |
| `rollback-preuve.txt` | 9/9 — snapshot T1 (wal intégré) → restauration T0 + plugin 2.1.0 : health 2.1.0/2.1.0, leads revenu `theme`, 10 leads présents, front 200, pont POST /leads actif (garde 404 prouvée) → retour nominal 2.2.0 re-vérifié |
| `rapide-1.txt`, `rapide-2.txt` | QA rapide « ok » ×2 |
| `visual-1.txt`, `visual-2.txt` | Kit visuel **12/12 vues à 0,00 %** ×2 — baseline capturée sur l'état B1 (6.18.2 + 2.1.0) PUIS comparée à l'état B2 (méthode du lot B1) |
| `audit-jsonld-hreflang.txt` | Audit JSON-LD/hreflang : **9 PASS / 0 FAIL** |
| `pages-front.txt` | Pages front 200 ×6 (fr/en/ar, contact, sitemap) |
| `reg2-latences.txt` + `reg2-sonde-bootstrap.txt` | REG-2 : p95 mesuré par passe (232,5 puis 248,2 ms ; référence lot B1 244,0 ms, tolérance ±10 %) ; sonde bootstrap : court-circuit identique (2 plugins actifs) |
| `rejeu-ci-theme.txt` / `rejeu-ci-plugin.txt` | CI rejeu local : **11/11 steps** (thème) et **8/8 steps** (plugin, pin `Schema::VERSION` = 2.2.0, structure étendue aux fichiers B2) |
| `T1-b2-apres-rollback-reapplique.sqlite` | Snapshot de l'état B2 utilisé par la preuve de rollback (aller-retour) |

## Constats importés du lot B1 toujours valables

- La base tourne en `journal_mode=wal` : toute copie de fichier DOIT être précédée d'un checkpoint `PRAGMA wal_checkpoint(TRUNCATE)` et suivie de la suppression des `-wal`/`-shm` (sinon rejeu d'un wal obsolète).
- `framework.php:7` d'Estatik fait `require_once 'functions.php'` en chemin RELATIF : les scripts PHP-CLI doivent `chdir()` vers la racine WP **avant** `wp-load.php` (convention respectée par la sonde REG-5).
- Origine navigateur : `PK_BASE=127.0.0.1` obligatoire (CSP `'self'`).

## Découvertes propres au lot B2 (consignées au rapport)

1. **Hygiène de banc (contrats du lot A)** : `core-contract.php` et `leads-contract.php` laissaient fuiter une ligne `pk_buyer_leads` par rejeu (nettoyage par clé `lead_id` inexistante sur cette table) et `core-contract` utilisait un téléphone FIXE correspondant à un lead préexistant (mise à jour de `last_seen_at` à chaque rejeu). Corrigé dans les deux fichiers de test (assertions inchangées) : téléphone aléatoire + purge par la bonne clé ; la recette prouve « toujours 10 leads après les contrats ».
2. **Fenêtre du limiteur de débit (lot A)** : les 6 requêtes POST /leads d'une passe de recette tiennent dans la fenêtre 10 req/60 s ; des rejeux rapprochés de debug peuvent la saturer (429 parasite sur LEAD-006) — la recette attend 65 s avant la section contrats.
3. **Combinaison C (6.18.2 + 2.2.0)** : la reprise de `/erase-lead` par le plugin provoque une déclaration double ; le registre unique INTEG-3 refuse la seconde, la journalise et la compte — comportement conçu (détection bruyante du décalage), l'état nominal (6.18.3) n'a aucune collision.
