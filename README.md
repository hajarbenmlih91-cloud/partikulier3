# Partikulier 3

Monorepo de livraison du portail immobilier **Partikulier** : thème WordPress et plugin cœur métier, maintenus et validés sur les lots C à F.

## Contenu

| Chemin | Rôle | Version |
|---|---|---:|
| `plugin/partikulier-core` | Plugin cœur : domaines métier, REST, santé, migrations et contrats | **2.10.16** (dossier 03/10/2026) |
| `theme/partikulier` | Thème immobilier : templates, front, i18n FR/EN/AR, Estatik et QA | **6.20.11** (dossier 03/10/2026) |
| `scripts/package.sh` | Packaging reproductible des deux livrables | `PLUGIN 2.10.16 / THEME 6.20.11` |
| `docs/` | Architecture, installation et release | — |
| `DOSSIER-2026-10-03-…` | Livraison consolidée du 3 octobre (zips + Sheets + n8n + PDF) | voir `DOSSIER-…/README.md` |

Le thème délègue au plugin les domaines métier disponibles et conserve un mode de repli compatible lorsque le plugin est désactivé. Les deux composants sont livrés ensemble pour garantir la compatibilité des contrats i18n et des lots C à F.

## Prérequis

- WordPress 6.2 ou supérieur ;
- PHP 8.0 ou supérieur ;
- Estatik ;
- Polylang est optionnel pour les variantes multilingues ;
- Node.js 18+ uniquement pour la QA Playwright du thème.

## Développement local

```bash
make lint
make package
```

`make lint` exécute les contrôles disponibles dans l’environnement. Le lint PHP nécessite `php` ; la QA navigateur nécessite l’installation des dépendances du thème (`cd theme/partikulier && npm ci`).

## Packaging

```bash
./scripts/package.sh dist
sha256sum dist/*.zip
```

Le script exclut les dépôts Git, caches, secrets, artefacts de test et dossiers `.github` des archives WordPress. Il produit une racine d’archive conforme aux installateurs WordPress : `partikulier-core/` et `partikulier/`.

## Installation WordPress

1. Déployer le zip du plugin depuis **Extensions → Ajouter → Téléverser** et l’activer.
2. Déployer le zip du thème depuis **Apparence → Thèmes → Ajouter → Téléverser** et l’activer.
3. Activer Estatik et, si nécessaire, Polylang.
4. Vérifier `GET /wp-json/partikulier/v1/health` et contrôler que `status` vaut `ok`.
5. Configurer les pages requises et les secrets d’automatisation uniquement via les réglages WordPress ou les variables d’environnement documentées.

## Qualité et traçabilité

Le lot source a été vérifié par somme SHA-256 avant intégration. Les preuves textuelles de recette B1→B6, les journaux, contrats JSON, rapports et l’audit du monorepo sont regroupés dans [`preuves/`](preuves/), avec le [manifeste de campagne](preuves/MANIFESTE.md). Les preuves historiques restent distinctes des contrats rejouables dans `plugin/partikulier-core/tests/` et `theme/partikulier/tests/`.

La campagne historique documente **695/695 assertions PASS** sur les lots B1→B6 et **189/189 assertions PASS** sur l’audit du monorepo. Les lots C à F sont traçables dans l’historique Git et couverts par les workflows [`CI`](.github/workflows/ci.yml) et [`Contrats de recette WordPress`](.github/workflows/contrats-recette.yml). L’état courant vise **20 suites dynamiques et 237/237 assertions**, dont les contrôles de sécurité AVIF SE-013, SE-014 et SE-015. Les snapshots SQLite et captures PNG sont publiés séparément dans la [Release `preuves-b1-b6`](https://github.com/hajarbenmlih91-cloud/partikulier3/releases/tag/preuves-b1-b6), conformément à [`preuves/ARTEFACTS-BINAIRES.md`](preuves/ARTEFACTS-BINAIRES.md). Les contrôles nécessitant WordPress vivant sont explicitement séparés des contrôles statiques.

Les versions, les checksums et le détail du périmètre des lots C à F sont documentés dans [`docs/RELEASE.md`](docs/RELEASE.md) et les rapports de [`preuves/rapports/`](preuves/rapports/). Pour l’exploitation, consulter [`docs/INSTALLATION.md`](docs/INSTALLATION.md).

> **Dossier 03/10/2026 — v2.10.16 / v6.20.11** : la livraison consolidée (zips `e3a0f243` / `3bc7bee6`, Sheets A/B, n8n, HSTS/2FA, PDF sécurisation) est dans [`DOSSIER-2026-10-03-PARTIKULIER-v2.10.16-6.20.11/`](../DOSSIER-2026-10-03-PARTIKULIER-v2.10.16-6.20.11/README.md) avec son [`CHANGELOG.md`](../DOSSIER-2026-10-03-PARTIKULIER-v2.10.16-6.20.11/CHANGELOG.md) et le [`PDF-SHEETS-SECURISATION-2026-10-03.pdf`](../DOSSIER-2026-10-03-PARTIKULIER-v2.10.16-6.20.11/PDF-SHEETS-SECURISATION-2026-10-03.pdf) (pourquoi 2 Sheets, méthode de sécurisation). Voir aussi `plugin/partikulier-core/CHANGELOG.md` et `theme/partikulier/CHANGELOG.md` pour le détail dev par fichier:ligne.

## Licence

Les composants sont distribués sous GPL v3 ou ultérieure, conformément aux en-têtes WordPress présents dans les fichiers livrés.
