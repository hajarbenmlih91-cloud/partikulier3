# Chantiers restants

Trois sujets identifiés, par ordre d'impact. Aucun n'est bloquant pour l'exploitation courante.

---

## 1. Requêtes N+1 côté Estatik

**Impact :** temps de chargement des pages de liste. C'est le prochain gain visible pour les visiteurs.

**Symptôme :** sur une archive de 40 annonces, chaque carte déclenche ses propres requêtes pour récupérer prix, surface, chambres et termes de taxonomie. On atteint plusieurs centaines de requêtes là où une dizaine suffirait.

**Piste :** précharger les métadonnées et les termes en une passe avant la boucle, avec `update_postmeta_cache()` et `update_object_term_cache()` sur l'ensemble des IDs de la page. Vérifier aussi les gabarits de `estatik4/front/` qui appellent `get_post_meta()` sans cache.

**Comment mesurer :** installer Query Monitor, comparer le nombre de requêtes sur `/annonces/` avant et après. Objectif : passer sous la barre des 50.

**Attention :** `card-property.php` ligne 36 écrase la globale `$types`. Toute optimisation qui préchargerait les termes doit tenir compte de ce comportement.

---

## 2. Polylang : 20 annonces publiées, 5 visibles

**Impact :** des annonces payées et validées n'apparaissent pas. Sujet client sensible.

**Symptôme :** 20 annonces sont en statut `publish`, mais l'archive n'en affiche que 5.

**Hypothèses à vérifier dans cet ordre :**

1. Les traductions générées portent `_pk_auto_translation` et sont filtrées à l'affichage — le compte de 20 inclut peut-être les versions AR et EN, auquel cas 5 originaux visibles serait cohérent. **À vérifier en premier**, c'est l'explication la plus probable et la moins grave.
2. Les annonces sans langue assignée sont invisibles dans une requête filtrée par langue. Vérifier `pll_get_post_language()` sur les annonces manquantes.
3. Les règles de réécriture des CPT traduits n'ont pas été régénérées.

**Rappel :** Polylang n'expose aucune commande WP-CLI `wp pll`. Pour manipuler les langues en script, passer par `PLL()->model->add_language([...])` via `wp eval`.

---

## 3. Les 40 annonces par page imposées

**Impact :** faible, mais c'est une contrainte subie plutôt que choisie.

**Symptôme :** la pagination est figée à 40 annonces par page, valeur imposée quelque part et non pilotable depuis les réglages.

**Piste :** chercher qui force `posts_per_page` — option WordPress, réglage Estatik ou `pre_get_posts` du thème. L'exposer ensuite dans les options du thème, avec 40 comme valeur par défaut pour ne rien changer aux sites existants.

**Lien avec le chantier 1 :** réduire ce nombre allégerait mécaniquement la charge des pages de liste. Traiter les deux ensemble a du sens.

---

## Idées non planifiées

Pas demandées par le client, mentionnées pour mémoire :

- **Changement de mot de passe à la première connexion**, pour limiter la durée de vie du mot de passe qui circule sur WhatsApp (voir `02-DECISIONS.md`).
- **Pages de destination par ville et quartier** (`/casablanca/maarif/`), qui capteraient les recherches géographiques génériques. L'infrastructure d'URL est déjà en place, ce serait une extension naturelle du travail SEO.
- **Purge différée des originaux après conversion AVIF**, pour récupérer de l'espace disque. Volontairement non activée : l'original sert de repli et permet la régénération des miniatures.
