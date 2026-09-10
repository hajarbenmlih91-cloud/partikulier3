# Thème Partikulier

Thème WordPress de portail immobilier pour **partikulier.com** : annonces de biens
immobilières gratuites déposées par les particuliers. Zéro jQuery, cache de page intégré,
conversion AVIF automatique des images, JSON-LD complet (RealEstateListing, Place, Offer),
sitemap XML virtuel, permaliens géographiques (ville, quartier, région, département).
Conçu pour **Estatik**, compatibilité optionnelle **Polylang** (FR/AR/EN).

**Version : 6.17.35** · Licence GPL v3 ou ultérieure · Requiert WordPress 6.2+ et PHP 8.0+.

## CI GitHub

![CI](https://github.com/OWNER/REPO/actions/workflows/ci.yml/badge.svg)
_(remplacer `OWNER/REPO` par votre dépôt)_

À chaque push / pull request, [`.github/workflows/ci.yml`](.github/workflows/ci.yml) exécute :

- **lint PHP** en matrice **8.0 → 8.3** (`php -l` sur tous les fichiers),
- **lint JS / shell / JSON** (`node --check`, `bash -n`, parsing JSON),
- **cohérence & structure** : version identique dans `style.css`, `functions.php`,
  `package.json`, `readme.txt` ; en-tête de thème complet ; aucun artefact committé,
- **packaging reproductible** : `partikulier-theme-<version>.zip` reconstruit, vérifié
  (racine `partikulier/`, 0 entrée interdite) et téléversé en artefact de run.

La QA fonctionnelle « live » contre un staging est dans
[`.github/workflows/qa-live.yml`](.github/workflows/qa-live.yml) : déclenchement **manuel**
(onglet Actions), exige le secret `PK_BASE` (URL publique du staging). La QA visuelle
(comparaison pixel) se rejoue en local : elle dépend de références par environnement.

## Kit QA embarqué (`tests/`)

Jamais chargé par WordPress. Voir [`tests/README.md`](tests/README.md) pour le protocole complet.

```bash
npm run setup                        # Node 18+ : Playwright + Chromium
npm run test:baseline                # capture les références (1 fois, par environnement)
npm test                             # 12 vues : 6 pages × desktop/mobile, comparaison pixel
node tests/rapide.mjs                # 4 vues rapides (PK_BASE=http://127.0.0.1:8091 par défaut)
PK_BASE=https://votre-staging npm test   # contre un vrai site
```

## Arborescence

| Dossier | Rôle |
|---|---|
| `inc/`, `templates/`, `estatik4/` | Runtime : fonctions, gabarits, surcouche Estatik |
| `assets/` | CSS, JS (sans dépendance), polices |
| `tests/` | Kit QA embarqué (visuel, parcours, sécurité, charge, staging) |
| `docs/` | Guides (reprise, leads, WhatsApp/n8n) |
| `.github/workflows/` | CI GitHub (lint, cohérence, packaging, QA live manuelle) |

Historique des versions : [`readme.txt`](readme.txt) (changelog complet depuis 1.2.0).
Guide d'utilisation : [`guide-utilisation.md`](guide-utilisation.md).
