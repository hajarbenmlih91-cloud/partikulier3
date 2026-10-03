# Partikulier — documentation utilisateurs et recette

FR — Ce dossier contient trois pages HTML autonomes. Elles reprennent la charte du thème : palette cognac, police DM Sans chargée depuis `theme/partikulier/assets/fonts/`, angles droits, en-tête éditorial et pied de page sombre. Elles ne dépendent d’aucune ressource externe.

| Fichier | Public | Contenu | Langues |
|---|---|---|---|
| `user-stories.html` | Produit, développement | Récits utilisateur par rôle (visiteur, déposant, propriétaire, agent refusé, administrateur, acteurs système), critères d’acceptation, liens vers les diagrammes de `../diagrams/` | FR / EN |
| `guide-utilisateurs-simple.html` | Propriétaires, acheteurs, partenaires métier | Guide non technique : parcours, FAQ (photos, WhatsApp, STOP, limite de contacts) | FR / EN |
| `cahier-de-tests.html` | QA, développement, agents de test | 47 cas de recette reliés aux deux pages ci-dessus : type d’utilisateur, priorité, prompt d’exécution, résultat attendu, vérification SQL et squelette Playwright | FR |

## Utilisation

- Ouvrir les fichiers directement dans un navigateur. Les chemins des polices et des diagrammes sont relatifs : garder l’arborescence `docs/` et `theme/` intacte.
- Pages bilingues : bouton FR/EN (le choix est mémorisé dans le navigateur), puis **Imprimer → Enregistrer en PDF** pour exporter la langue affichée.
- Cahier de tests : la section « Socle de test » décrit la pile Docker locale (`http://localhost:8099`), les conventions SQL (préfixe `wp_`) et les helpers Playwright supposés (`wp`, `sql`, `meta`, `seedListing`, `seedOwner`, `manage`, `automation` signé HMAC, `fillDeposit`). La matrice de traçabilité relie chaque cas (ACH, DEP, AGT, PRO, ADM, SYS) à sa user story.

> Le dépôt ne contient pas encore de suite Playwright : les extraits du cahier sont des squelettes à transposer dans `tests/e2e/`. Certains sélecteurs proposent deux variantes (`[data-id]` / `[data-post-id]`) à confirmer au premier lancement. Ne jamais exécuter ces tests contre la production.

## Maintenance

Quand un parcours change (statuts `_pk_status`, routes REST, sélecteurs du formulaire de dépôt), mettre à jour dans le même lot la user story, le guide si la promesse utilisateur change, et le cas de test correspondant.

---

EN — This folder contains three self-contained HTML pages styled with the theme’s design system (cognac palette, DM Sans, square corners, editorial hero, dark footer), with no external dependency.

- `user-stories.html`: detailed stories by role with acceptance criteria and diagram links (FR/EN).
- `guide-utilisateurs-simple.html`: plain-language guide for owners, buyers and business stakeholders (FR/EN).
- `cahier-de-tests.html`: 47 acceptance test cases (French only), each with user type, priority, step-by-step prompt, expected result, SQL check and a Playwright skeleton.

Open the files in a browser and keep the `docs/` and `theme/` folders side by side (fonts and diagrams use relative paths). Use the FR/EN button, then **Print → Save as PDF** to export. No Playwright suite exists yet: the test snippets are skeletons to adapt.
