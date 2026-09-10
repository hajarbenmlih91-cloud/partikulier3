# MANIFESTE — Preuves de recette lots B1→B6 + audit du monorepo (campagne partikulier-core)

Empaquetage : 11 septembre 2026. Origine : banc de recette sandbox WordPress
(PHP 8.3 + SQLite + Estatik, http://127.0.0.1:8091) — recettes exécutées du 9 au
10 septembre 2026. Référentiel : CDC v1.2 « Refactoring partikulier »
(CAHIER-DES-CHARGES-REFACTORY-PARTIKULIER.docx / .pdf, déjà remis au commanditaire).

## 1. Vue d'ensemble

| Lot | Domaine extrait vers partikulier-core | Plugin | Thème | Suites | Assertions | Verdict |
|-----|--------------------------------------|--------|-------|--------|-----------|---------|
| B1 | payments + premium | 2.1.0 @39e8058 | 6.18.2 @013dc66 | 9 | 76/76 | GO |
| B2 | leads + qualification + WhatsApp | 2.2.0 @9563774 | 6.18.3 @71daf74 | 10 | 92/92 | GO |
| B3 | alerts | 2.3.0 @32d9c72 | 6.18.4 | 11 | 107/107 | GO |
| B4 | automation (n8n) | 2.4.0 @4fde1f0 | 6.18.5 | 12 | 124/124 | GO |
| B5 | owner_stats (favoris) | 2.5.0 @94b9961 | 6.18.6 | 13 | 140/140 | GO |
| B6 | translation_variants | 2.6.0 @4b6b249 | 6.18.7 | 14 | 156/156 | GO (recette) |
| — | audit du monorepo GitHub | 2.8.0 @41d6e35 | 6.18.9 | 17 | 189/189 | CONFORME lots 0/A/B/C1/C2 |

Cumul recette B : 6 lots, 8/8 domaines côté plugin, 69 suites exécutées, 695/695
assertions finales PASS (chaque lot rejoué intégralement après correctif le cas échéant).

## 2. Contenu commun de chaque dossier `lot-Bx/`

- `journal-recette.txt` (B3→B6) ou `LISEZMOI.md` (B1-B2) : déroulé complet de la
  recette, commande de rejeu par preuve, incidents réels consignés ;
- `contrats/*.json` + `contrats/*.err` : sorties brutes des suites contractuelles
  (format : `test_id`, `candidate_version`, `source_commit`, `status`, `tests[]`,
  `total`, `passed`, `failed`, `limitations`) ;
- `T0-domaine-bx.json` + `T0-sauvegarde-base.sqlite` : état du domaine et de la base
  AVANT le lot (point de rollback) ;
- `T1-bx-apres-rollback-reapplique.sqlite` : état final réappliqué après la preuve
  de rollback ;
- `REG5-B-plugin-absent.json` : matrice repli — plugin désactivé, thème autonome ;
- `REG5-C-theme-61xx.json` : matrice repli — thème historique, plugin propriétaire ;
- `REG6-parite-structure-donnees-idempotence.json` : parité DDL/index T0→T1,
  idempotence du Migrator, intégrité des données réelles ligne à ligne ;
- `rollback-preuve.txt` : preuve d'aller-retour complet (restauration T0 puis
  réapplication T1, vérifications symétriques) ;
- `health.json` / `health-final.json` : endpoint `/wp-json/partikulier/v1/health`
  (status ok, owner des domaines, 0 fantôme, 0 collision) ;
- `pages-front.txt` : pages front 6 × HTTP 200 (fr/en/ar + contact + sitemap) ;
- `audit-jsonld-hreflang.txt` : audit JSON-LD/hreflang 9/9 ;
- `visual-1/2.txt` : QA visuelle 12/12 vues à 0,00 % (baseline) ;
- `reg2-latences.txt` + `reg2-sonde-bootstrap.txt` : REG-2 p50/p95 dans la
  tolérance ±10 % vs référence ;
- `rejeu-ci-theme.txt` / `rejeu-ci-plugin.txt` : rejeu local des étapes CI.

Spécifique B6 : `REG3-corpus-avant/apres-decoupe.json` (gel du corpus i18n,
150 chaînes registre / 136 chrome / 140 form / 72 runtime), `source-6.18.6-class-localization.php`
+ sha256 (source avant découpe), `REG3-decoupe-corpus-ordre.json`.

## 3. Dossier `audit-conformite-repo-2026-09/`

Audit d'entrée du monorepo GitHub partikulier3 (plugin 2.8.0 + thème 6.18.9,
commit 41d6e35) : journal-audit.txt (7 étapes), contrats 17 suites 189/189,
health avant déploiement / après déploiement / après rollback, scénario 9 (11/11),
scénario 10 (10/11 — S10.1 faux positif documenté : mots hmac/n8n = noms de tables
du manifeste health public, présents à l'identique dans le T0 B6 certifié),
REG-3 corpus (diff nul prouvé par parseur .mo dédié : 131 ar + 72 en identiques),
REG-5 front sans plugin, rollback sandbox vers B6 re-vérifié.

## 4. Dossier `rapports/`

RAPPORT-LOT-B1 → B5 (verdicts GO, arbitrages et incidents documentés).
Le rapport B6 n'a pas été produit localement — la recette B6 est intégralement
consignée dans `lot-B6/journal-recette.txt` (écart de finalisation connu).

## 5. Règles d'intégration sur le repo GitHub (pour l'agent qui implémente)

1. Dans git : preuves textuelles uniquement (json, txt, md, po/pot, sha256) —
   environ 5 Mo au total ;
2. Hors git (GitHub Release « preuves-b1-b6 », assets) : binaires lourds —
   `T0-sauvegarde-base.sqlite`, `T1-*.sqlite` (~2,9 Mo chacun), captures ;
3. Vérifier l'empreinte SHA-256 du zip avant extraction
   (`preuves-recette-b1-b6.zip.sha256`, livré avec l'archive) ;
4. Commit séparé du code : `docs(preuves): recette B1→B6 + audit repo` ;
5. Mettre à jour la section « Qualité et traçabilité » du README racine pour
   pointer vers `preuves/`.
