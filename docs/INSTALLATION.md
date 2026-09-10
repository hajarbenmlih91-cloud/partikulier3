# Installation et exploitation

## Déploiement

Construire les archives depuis la racine du dépôt :

```bash
./scripts/package.sh dist
```

Installer `dist/B-INSTALLER-PLUGIN-partikulier-core-2.8.0.zip` dans WordPress, puis installer `dist/partikulier-theme-6.18.9.zip`. Le plugin doit être activé avant le thème afin que les services métier soient disponibles dès le premier chargement.

## Vérifications post-déploiement

Après activation, vérifier la route de santé :

```bash
curl -fsS https://example.test/wp-json/partikulier/v1/health
```

La réponse attendue est un JSON dont `status` vaut `ok`. Contrôler également le front en français, anglais et arabe, le sens RTL, la recherche géographique, le dépôt d’annonce, les favoris et la génération JSON-LD.

## Configuration sensible

Ne jamais committer de secrets. Les secrets d’automatisation doivent être injectés via les réglages protégés de WordPress ou `PARTIKULIER_AUTOMATION_API_SECRET` selon l’environnement. En production, utiliser le mode de sécurité signé/enforced décrit dans `docs/whatsapp-n8n-setup.md` et ne pas réutiliser de secret de staging.

## Catalogues i18n

Le plugin `partikulier-core` est la source canonique du domaine gettext `partikulier`. Le thème conserve une copie synchronisée pour garantir un repli traduit lorsque le plugin est désactivé. Le packaging et la CI refusent toute divergence entre les cinq fichiers partagés (`partikulier.pot`, `ar.po`, `ar.mo`, `en_US.po`, `en_US.mo`).

Pour personnaliser une traduction sans modifier les livrables, placer le catalogue dans l’emplacement WordPress prioritaire :

```text
wp-content/languages/plugins/partikulier-core-<locale>.mo
```

La personnalisation est ainsi conservée lors des mises à jour du plugin.

## Rollback

Conserver l’archive précédente et son checksum avant chaque mise à jour. En cas de régression, réinstaller les deux archives de la version précédente dans le même ordre, puis vérifier la route de santé et le parcours front. Les migrations du plugin sont journalisées ; ne pas supprimer manuellement les tables métier.

## QA complète

Les contrats PHP demandant WordPress se lancent depuis un hôte WordPress avec `PK_WP_DIR`. La QA navigateur du thème se lance depuis `theme/partikulier` avec `PK_BASE` pointant vers un staging :

```bash
npm ci
npx playwright install --with-deps chromium
PK_BASE=https://staging.example.test npm test
```

Les scripts de staging sont protégés par un garde CLI et ne sont pas chargés par le runtime WordPress public.
