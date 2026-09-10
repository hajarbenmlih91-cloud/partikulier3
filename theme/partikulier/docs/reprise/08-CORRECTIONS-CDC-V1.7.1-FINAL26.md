# Corrections CDC v1.7.1 — candidat final26

**Date de préparation :** 2026-08-26 UTC
**Périmètre :** thème Partikulier, source locale avant déploiement final26
**Statut global :** **NO-GO provisoire** tant que les vérifications live, CI, performance, capacité, upgrade/rollback et revue humaine ne sont pas toutes passées.

## 1. Corrections incluses

Le candidat final26 reprend les corrections déjà validées sur final25 pour la popup de filtres, l’accessibilité, l’interface mobile, les titres de cartes, le JSON-LD localisé et le chargement différé des images. Il ajoute la migration transactionnelle Estatik vers `es_status`, taxonomie observée dans l’administration du site de test, tandis que `es_category` reste réservée aux termes historiques/villes à assainir.

| Domaine | Correction source | Preuve locale | Statut avant déploiement |
| --- | --- | --- | --- |
| Popup et clavier | Bootstrap précoce, `inert`, `aria-hidden`, retour du focus, handler principal idempotent | `templates/archive.php`, `assets/js/main.js` | PASS local, live final25 déjà PASS avec harnais robuste |
| Landmarks et titres | Topbar nommée `complementary`, niveaux `h2`/`h3` cohérents | `templates/header.php`, `templates/parts/card-property.php` | PASS local, axe final25 à 0 violation |
| JSON-LD arabe | Helpers de localisation pour titre, lieu, terme, phrase et breadcrumbs | `inc/class-jsonld.php` | PASS live final25 sur archive AR ; fiches individuelles à compléter |
| Images archive | Première image eager/high, suivantes lazy/low ; index de carte corrigé | `templates/archive.php`, `templates/parts/card-property.php` | PASS live final25 sur 320/360/375/390 ; performance AR encore FAIL |
| Transaction Estatik | `es_action` et les badges lisent `es_status`; dépôt, fiche, SEO, traduction, qualification et wizard alignés | `functions.php`, `inc/*`, `templates/*`, `estatik4/front/property/*` | À prouver live dans final26 |
| Traductions | `es_status` ajouté à la synchronisation des taxonomies Polylang | `inc/class-listing-translations.php`, `inc/class-localization.php` | À prouver live dans final26 |
| Fixture | Contrat vente/location Marrakech marqué test-only et non importable | `tests/fixtures/transaction-filters-v1.json` | PASS de présence ; aucun contenu live créé |
| Static quality | Scanner R6, PHP/JS syntaxe, Semgrep ciblé et projet | `scripts/check.sh`, `.semgrep/` | PASS local : 66 PHP, 19 JS vérifiés, Semgrep 0 finding |
| Reproductibilité | Lockfiles racine/thème, Playwright multi-navigateurs, installation CI plus visible | `package-lock.json`, `theme/package-lock.json`, `playwright.config.mjs`, `.github/workflows/` | PASS local dry-run ; CI distante à relancer |

## 2. Décision de taxonomie transactionnelle

L’administration Estatik du site de test a montré que le statut `À vendre` est porté par `es_status` avec le terme observé `a-vendre` et l’identifiant 108. La recherche native `entities_filter[es_status]=108` renvoie des propriétés. `es_category` contient des termes de villes dans l’état observé et ne doit donc plus servir à déterminer vendre/louer.

Le code conserve volontairement les usages de `es_category` dans le diagnostic de pollution et le wizard de nettoyage historique. Ces routines ne sont pas exécutées par cette correction. Aucun terme, annonce, média, traduction ou donnée métier n’a été créé, supprimé ou modifié par le travail local.

## 3. Contrôles locaux exécutés

| Contrôle | Résultat | Limite |
| --- | --- | --- |
| PHP lint | PASS — 66 fichiers | Ne remplace pas l’exécution WordPress réelle |
| JavaScript `node --check` | PASS — 19 fichiers détectés | Ne remplace pas les tests navigateur |
| `scripts/check.sh` | PASS — versions 6.17.18 concordantes, R6 à 0 | Contrôle projet, pas certification externe |
| `npm ci --dry-run` racine | PASS | Installation réelle et navigateurs multi-moteurs à vérifier en CI |
| `theme/npm ci --dry-run` | PASS | Même limite |
| Semgrep ruleset projet | PASS — 0 finding bloquant sur 95 cibles analysées | Ne couvre que les règles du ruleset présent |
| `git diff --check` | PASS | Ne vérifie pas le rendu visuel |

## 4. Contrôles non encore validés

Le déploiement final26 et la vérification publique correspondante restent nécessaires. Il faut notamment prouver le filtre `?es_action=a-vendre`, vérifier les JSON-LD FR/EN/AR d’archives et de fiches, rejouer axe et le harnais Chromium/Firefox/WebKit, puis tester le clic précoce avec chargement retardé de `main.js`.

La performance n’est pas déclarée conforme : final25 améliore fortement le FR, mais la version AR télécharge encore environ 2,2 Mo d’images et de polices avec un TTFB intermittent d’environ six secondes dans les mesures observées. Les objectifs contractuels TTFB cache chaud inférieur à 800 ms et LCP inférieur à 2,5 s restent **FAIL** jusqu’à preuve contraire.

Les tests de capacité 10 RPS/900 s, upgrade/rollback sur environnement froid, nettoyage staging, PHPStan/WPCS complet et les signoffs humains indépendants restent **NOT RUN** ou **BLOCKED**. Ils ne doivent pas être présentés comme réalisés par l’agent.

## Références

[1]: https://github.com/hajarbenmlih91-cloud/partikulier2/tree/automation/release-approval-gate-v1.7.1 "Branche GitHub candidate Partikulier"
[2]: https://blanchedalmond-reindeer-376379.hostingersite.com/ "Site WordPress de test Hostinger"
[3]: https://developer.wordpress.org/reference/functions/get_terms/ "WordPress — get_terms"
[4]: https://playwright.dev/docs/test-projects "Playwright — projets multi-navigateurs"
