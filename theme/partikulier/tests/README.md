# Tests de non-régression

## Utilisation

```bash
# 1. Monter le site de test (PHP, MariaDB, WordPress, Estatik, Polylang, 6 annonces)
bash setup-stack.sh
php -S 0.0.0.0:8090 -t wp wp/router.php &

# 2. Dépendances (une fois)
npm run setup

# 3. Vérifier
npm test                 # 12 vues + invariants
node tests/rapide.mjs    # 4 vues, ~30 s, pour itérer

# 4. Réenregistrer après une modification volontaire et validée
npm run test:baseline
node tests/rapide.mjs baseline
```

`npm test` sort en code 1 si quelque chose a bougé — utilisable tel quel en CI.

`PK_BASE` cible un autre site (défaut `http://localhost:8090`) :

```bash
PK_BASE=https://mon-staging node tests/rapide.mjs   # contre un vrai staging
```

## Captures déterministes (6.17.34)

Chaque vue est chargée **deux fois** (échauffement des caches serveur et
navigateur, le second chargement est capturé), les images `loading="lazy"`
sont forcées et attendues jusqu'à décodage, la page est déroulée puis
ramenée en haut, et le lien d'évitement (`position: fixed`) est épinglé hors
écran. Sans cela, la capture pleine page fige **aléatoirement** les
placeholders d'images différées (5 à 10 % d'écart fantôme sur l'accueil,
selon les sessions) et repositionne parfois les éléments fixes — deux
comportements documentés de Chromium. Résultat mesuré : 12/12 vues à 0,00 %
entre deux sessions fraîches.

## Couverture

**6 pages × 2 tailles = 12 vues** : accueil, annonces, annonces filtrées, déposer,
mes annonces, 404 — en 1440 px et 390 px.

1. **Comparaison pixel** de la page entière, seuil 0,2 %. En cas d'écart, la capture
   fautive est écrite sous `<nom>.actuel.png` à côté de la référence.

2. **Invariants structurels** — les 9 bugs corrigés, verrouillés :

   | Invariant | Vérifie |
   |---|---|
   | `logo_texte` | Marque en texte, aucune `<img>` dans le header |
   | `sans_emoji` | Aucun émoji dans le rendu |
   | `cta_visible` | Bouton « Déposer une annonce » présent en desktop |
   | `recherche_large` | Champ de recherche > 200 px |
   | `langue_compacte` | Sélecteur compact, pas l'ancienne liste |
   | `menu_espace` | Desktop : entrées espacées > 8 px ; mobile : grille compacte 3 colonnes, entrées ≥ 80 px |
   | `role_aligne` | Cartes de rôle alignées à gauche |
   | `role_contraste` | Texte lisible sur carte sélectionnée |

   Plus : HTTP 200 par page, ≥ 20 champs sur le formulaire de dépôt, 0 erreur JS.

## Points d'attention

- Écrans WordPress (login, admin) exclus des invariants : le thème n'y est pas responsable.
- Mobile (< 768 px) : la nav principale est une grille compacte 3 colonnes pleine largeur
  (design « sticky contextuel ») — l'invariant `menu_espace` vérifie la largeur des entrées.
- Les références (`tests/__baseline__/`, `tests/__screens__/`) ne sont **pas livrées** dans le
  zip : elles dépendent du contenu du site de test. Après installation, lancer `baseline`
  une fois, puis `npm test` en continu.
- Les URLs testées sont les routes actuelles (`/annonces/`, `/deposer/`). Les captures
  anonymes de `/mes-annonces/` montrent la redirection vers la page Connexion (6.17.33).

---

## Consolidation CSS — terminée

| | Départ | Arrivée |
|---|---|---|
| Sélecteurs dupliqués | **76** | **0** |
| Règles CSS | 720 | 635 |
| `!important` | 3 | **1** (impression, légitime) |
| Vues conformes | — | 12 / 12 |

### La clé : fusionner vers le haut

Ma première approche écrivait la règle fusionnée à la position de la **dernière**
occurrence. Elle a échoué sur 100 % des cas, pour deux raisons :

- la règle passait **après** les `@media` qui la surchargeaient ;
- une classe de base (`.pk-btn`) passait **après** ses variantes (`.pk-btn-sm`,
  `.pk-btn-text`…), écrasant leurs surcharges.

La bonne approche est l'inverse : écrire à la position de la **première** occurrence, en
concaténant les déclarations dans l'ordre d'origine sans les réinterpréter. La cascade
interne est préservée (la dernière valeur d'une même propriété gagne, comme avec deux
blocs séparés) et la cascade externe aussi (la règle reste avant les `@media` et les
variantes).

Résultat : **19 sélecteurs sur 19** fusionnés du premier coup avec cette méthode, contre
0 sur 19 avec la précédente.

### Méthode

Chaque fusion a été appliquée **individuellement**, validée par `tests/rapide.mjs`, puis
committée séparément — ou annulée par `git checkout` en cas d'écart. 40 commits au total.
Deux sélecteurs (`.pk-container`, `.pk-btn`) ont d'abord été rejetés avec la fusion vers
le bas, puis acceptés avec la fusion vers le haut.

### Les `!important` : diagnostic et suppression

Les 4 `!important` sur `.pk-role-btn` n'étaient pas une fatalité. Le blocage venait de mon
outillage : `document.styleSheets` ne révélait pas la règle gagnante.

Le protocole **CDP** (`CSS.getMatchedStylesForNode`) a donné la réponse en une exécution —
deux règles plus spécifiques imposaient `align-items: center` :

- `.pk-field label:has(input[type="radio"])` — le `:has()` gonfle la spécificité ;
- `.pk-field label` — impose aussi `display: block`.

Corrigé proprement avec `:not(.pk-role-btn)` sur les deux règles génériques, plus un
sélecteur `.pk-field .pk-role-btn` pour égaliser la spécificité du `display`. Aucun
`!important` n'a été nécessaire.

**Leçon d'outillage** : pour un conflit de cascade, `document.styleSheets` ne suffit pas.
Utiliser CDP ou le panneau *Styles* du navigateur.

## Outils de validation staging distant (6.17.29)

Pour tester un VRAI staging (Hostinger) depuis l'extérieur — charge 100 req/s,
contrat de cache/TTFB, sécurité HMAC, GO/NO-GO : voir
`tests/LISEZMOI-STAGING.md` (les outils refusent l'exécution par HTTP, garde
CLI ; le dossier `tests/` n'est jamais chargé par WordPress).
