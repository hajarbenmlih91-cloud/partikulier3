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

## Configuration Polylang de référence (SE-025, E-2503)

Le front trilingue FR/EN/AR est qualifié avec la configuration suivante — c’est
exactement celle que le workflow « Contrats de recette (WordPress) » installe
et teste en CI (SE-025, E-2501→E-2504) :

| Réglage | Valeur de référence |
|---|---|
| Extension | Polylang **3.8.7** (wordpress.org — version épinglée en CI) |
| Langues | `fr` (locale `fr_FR`, défaut, drapeau fr) · `en` (locale `en_US`, drapeau us) · `ar` (locale `ar`, **RTL**, drapeau ma) |
| URL des langues | `force_lang = 1` — préfixe répertoire : `/fr/`, `/en/`, `/ar/` |
| Langue par défaut | `fr` — créée en premier (la première langue devient le défaut) et `default_lang = fr` |
| Préfixe du défaut | `hide_default = 0` — le français garde son `/fr/`, jamais de racine ambiguë |
| Détection navigateur | `browser = 0`, `redirect_lang = 0` |
| Contenus traduits | `post_types` : `post`, `page`, `properties` ; `taxonomies` : `es_type`, `es_status`, `es_location`, `es_category` |
| Contenu préexistant | assigné en masse à `fr` (`PLL()->model->set_language_in_mass`) — sinon `/fr/annonces/` serait vide |

Chemin CLI de référence (celui de la CI) : créer les trois langues par
`PLL()->model->add_language()` dans un contexte WP-CLI (`wp eval-file`),
puis poser les réglages par `PLL()->options->merge( array( … ) )` —
l’API des écrans Réglages. Ne JAMAIS passer par `update_option( 'polylang' )`
direct : Polylang 3.7+ réécrit l’option au `shutdown` en fusionnant base ×
mémoire (`Options::save_all()`), ce qui écraserait la valeur au sortir du
processus. Puis — dans un **second processus**, afin que Polylang amorce
ses types traduits à jour — appeler `PLL()->model->set_language_in_mass()`
et purger les rewrites (`wp rewrite flush`).

Chemin HTTP de repli documenté : si la création CLI des langues est
impossible sur votre hôte, créer les trois langues dans
Réglages → Langues (slugs `fr`/`en`/`ar`, `ar` coché « right to left »),
régler « URL modifications » sur *The language is set from the directory*,
NE PAS cocher « Hide URL language information for default language »,
choisir Français comme langue par défaut, activer `properties` dans
« Custom post types and Taxonomies », puis passer l’étape du wizard
« Assign default language to all contents without language ». Le résultat
fonctionnel est identique : ce sont ces réglages, pas l’outil qui les pose,
qui font le contrat trilingue (9 URLs, RTL, hreflang).

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
