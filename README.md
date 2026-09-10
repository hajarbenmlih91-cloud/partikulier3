# Partikulier 3

Monorepo de livraison du portail immobilier **Partikulier** : thème WordPress et plugin cœur métier, issus du lot C2 certifié fourni avec ce projet.

## Contenu

| Chemin | Rôle | Version |
|---|---|---:|
| `plugin/partikulier-core` | Plugin cœur : domaines métier, REST, santé, migrations et contrats | 2.8.0 |
| `theme/partikulier` | Thème immobilier : templates, front, i18n FR/EN/AR, Estatik et QA | 6.18.9 |
| `scripts/package.sh` | Packaging reproductible des deux livrables | — |
| `docs/` | Architecture, installation et release | — |

Le thème délègue au plugin les domaines métier disponibles et conserve un mode de repli compatible lorsque le plugin est désactivé. Les deux composants sont livrés ensemble pour garantir la compatibilité des contrats i18n C1/C2.

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

Le lot source a été vérifié par somme SHA-256 avant intégration. Les preuves et contrats de recette historiques sont conservés dans `plugin/partikulier-core/tests/` et `theme/partikulier/tests/`. Les contrôles nécessitant WordPress vivant sont explicitement séparés des contrôles statiques.

Les versions, les checksums et le détail du périmètre sont documentés dans [`docs/RELEASE.md`](docs/RELEASE.md). Pour l’exploitation, consulter [`docs/INSTALLATION.md`](docs/INSTALLATION.md).

## Licence

Les composants sont distribués sous GPL v3 ou ultérieure, conformément aux en-têtes WordPress présents dans les fichiers livrés.
