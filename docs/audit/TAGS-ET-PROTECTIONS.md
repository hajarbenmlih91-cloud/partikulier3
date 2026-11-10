# Quels objets protéger — tags et refs

**Date :** 10 novembre 2026
**Question :** quels objets méritent un tag et un ruleset pour ne pas être perdus lors d'une fusion,
d'un merge ou d'un déploiement ?

---

## 1. Une fusion ne supprime jamais un tag

Le déploiement et la fusion sont les mauvais suspects. Les vecteurs de perte réels sont :

1. suppression d'un tag ;
2. **déplacement** d'un tag vers un autre commit ;
3. suppression d'une branche portant le seul chemin vers des commits ;
4. force-push sur une telle branche ;
5. suppression ou transfert du dépôt.

Protéger les tags ne protège donc pas d'une fusion. Protéger les **branches**, oui.

## 2. État mesuré

| Objet | Nombre | Remarque |
|---|---:|---|
| Tags | 215 | dont 207 `pre-release-*`, 2 `audit-*`, 6 historiques `v2.10.x` |
| Tags **orphelins** (sur aucune branche) | **2** | `audit-…-1`, `audit-…-2` — voir la correction ci-dessous |
| Branches distantes | 223 | |
| Branches avec du travail absent de `main` | **123** | `arena/0e605e79` : 179 commits ; `essai/page-ouverte-whatsapp` : 116 ; `audit-uat-validation` : 115 ; `feat/profil-acheteur` : 114 ; `audit-workflow-fix` : 114 |
| Rulesets ciblant les tags | **0** | aucune protection |
| Rulesets ciblant les branches | 3 | tous sur `main` (dont un désactivé) |
| Branche `develop` | **inexistante** | alors que `deploy.yml` route « push develop → dev » |

### 2 bis — Correction de la mesure initiale (10/11, après contrôle)

La première mesure annonçait **3 tags orphelins**, dont le tag de production. **C'était faux.**
Le balayage ne testait que trois branches (`main`, `develop`, `arena/0e605e79`) et déclarait
« aucune branche » pour tout le reste sans avoir tout regardé.

Balayage complet des **223 branches** :

| Réf | Porté par une branche ? | Constat |
|---|---|---|
| `c9e1413` (production `.214`) | **oui** | **tip de `origin/feat/profil-acheteur`**, ancêtre de `essai/page-ouverte-whatsapp` |
| `4f4c354` (`audit-…-1`) | **non** | reconstituable depuis le patch documenté, mais aucune branche ne l'ancre |
| `5aa70f44` (`audit-…-2`) | **non** | idem |

Leçon retenue, et elle vaut au-delà de ce document : **un « aucune branche » n'a de valeur que si
toutes les branches ont été parcourues.** C'est exactement ce que demandait la consigne « recalculer
les refs avant toute opération » — la mesure a été refaite, et elle a changé la conclusion.

## 3. Niveaux de protection

### Niveau 1 — indispensable

Les ancres du code de production et des preuves :

```text
refs/tags/pre-release-2.10.16-6.20.214   → c9e1413   (aussi tip de feat/profil-acheteur)
refs/tags/archive/pre-release-2.10.16-6.20.214 → c9e1413 (créé le 10/11, annoté)
refs/tags/audit-2.10.16-6.20.214-1       → 4f4c354   (orphelin)
refs/tags/audit-2.10.16-6.20.214-2       → 5aa70f44  (orphelin)
```

La criticité du tag de production est **moindre** que mesurée d'abord : `c9e1413` survit à sa
suppression tant que `feat/profil-acheteur` existe. En revanche, **cette branche devient l'ancre
critique** — d'où la priorité différente au niveau 3.

### Niveau 2 — recommandé

| Motif | Règles | Justification |
|---|---|---|
| `refs/tags/pre-release-*` | `deletion`, `update` | le commit survit ailleurs, mais « quelle version était en production le 3 octobre » devient sans réponse |
| `refs/tags/release-*` | `deletion`, `update` | mêmes raisons, cible production |
| `refs/tags/audit-*` | `deletion`, `update` | un tag d'audit déplaçable invalide la preuve |
| `refs/tags/archive/*` | `deletion`, `update` | seconde ancre |

Bypass vide. Aucune règle `creation` : elle bloquerait les releases futures.

### Niveau 3 — branches, là où le risque est réel *(révisé le 10/11)*

| Motif | Règle | Effet |
|---|---|---|
| `refs/heads/feat/profil-acheteur` | `deletion` + `non_fast_forward` | **priorité** : seul porteur du code de production ; un force-push détacherait `.214` de toute branche |
| les 4 autres branches à ≥ 100 commits | `deletion` | empêche la suppression accidentelle, n'entrave aucun push |
| `refs/heads/main` | déjà couvert | `deletion` + `non_fast_forward` + PR + checks (attention au bypass `RepositoryRole: always` de `main-2`) |
| `refs/heads/*` | `deletion` — **différé** | voir la réserve ci-dessous |

**Correction du 10/11 — « dormantes » était faux.** Les cinq branches ont reçu des commits dans les
trois derniers jours (`arena/0e605e79` : 10/11 09:46 ; `essai/page-ouverte-whatsapp` : 09/11 15:06 ;
`feat/profil-acheteur` : 09/11 01:46 ; `audit-uat-validation` : 08/11 14:58 ; `audit-workflow-fix` :
08/11 14:24). Aucune dormance n'est établie, donc `non_fast_forward` ne peut pas être posé au motif
qu'elles sont inactives.

Il reste justifié sur **`feat/profil-acheteur` seul**, et pour une autre raison : c'est l'ancre du code
de production. Réécrire son histoire couperait `.214` de toute branche. Sur les quatre autres,
`deletion` seule, et décision différée après inventaire.

**Réserve sur `deletion` global** (acceptée) : une règle `deletion` sur `refs/heads/*` empêche aussi la
suppression après fusion, le nettoyage des branches de test et la suppression d'une branche créée par
erreur. Sur un dépôt à 223 branches, elle figerait le problème de nettoyage au lieu de le résoudre.
Séquence retenue : protéger d'abord les branches à travail unique, inventorier les 123 branches,
nettoyer les fusionnées, **puis** décider d'une règle globale — avec une date de fin et un critère de
sortie écrits si une protection provisoire est appliquée.

Facteur atténuant : GitHub permet la restauration d'une branche récemment supprimée, ce qui réduit
l'urgence d'une règle globale sans la supprimer.

**Ne pas** appliquer `non_fast_forward` à `refs/heads/*` : cela casserait toute réécriture d'historique
sur les branches de travail, y compris les rebases légitimes.

## 4. Ce qui mérite un tag, et ce qui n'en mérite pas

| Objet | Tag ? | Pourquoi |
|---|---|---|
| `c9e1413` (production `.214`) | **oui** — `archive/pre-release-2.10.16-6.20.214` | seule référence ; le tag doit être annoté avec le SHA du bundle |
| Les 5 branches à ≥ 100 commits | **facultatif** | un tag **est** exploitable (`git switch -c restauration <tag>`, worktree, cherry-pick) : c'est une ancre de restauration valable. Il n'intègre pas le travail et ne remplace pas une branche active — protection de branche + bundle restent la référence, le tag vient en seconde ancre |
| `main` | non | déjà ancrée par une branche protégée |
| Les 212 tags ancrés par une branche | non | aucun besoin de protection contre la perte |

**Correction du 10/11.** La formulation « un tag sur un travail non fusionné le fige sans le rendre
utilisable » était trop forte. Un tag est un **point de restauration exploitable** ; il est simplement
un mauvais **référentiel de travail** : invisible dans `git branch`, il ne reçoit ni PR ni commits, et
il est facile à oublier. C'est ce que l'annotation et la documentation doivent compenser.

## 5. Correctif annexe

`deploy.yml` route un push sur `develop` vers l'environnement dev, mais la branche n'existe pas.
Soit la créer et la protéger, soit retirer la route. Une configuration qui décrit un chemin inexistant
finit par donner une fausse assurance.

---

## 6. État d'exécution au 10 novembre 2026 (mis à jour)

| Action | État | Détail |
|---|---|---|
| Tag annoté `archive/pre-release-2.10.16-6.20.214` | **fait** | objet `7ec7287a…`, pèle sur `c9e1413` ; message vérifié dans un clone neuf |
| Ruleset **tags** | **refusé — HTTP 403** (tenté 2×) | `Resource not accessible by integration` ; permission **`Administration: write`** absente |
| Ruleset **branche** (`feat/profil-acheteur`) | **refusé — HTTP 403** | même permission, même message |
| **Contrôle négatif** | **fait** | `archive/_probe2` créée **puis supprimée avec succès** ⇒ les tags sont **effectivement non protégés** aujourd'hui ; aucun résidu sur `origin` |
| Test de refus d'une protection | **impossible** | sans ruleset, il n'y a rien à faire refuser ; `deletion` et `non_fast_forward` restent **non démontrés** |
| Bundles | **faits et déposés** | pré-tag `1c712f5a…` (drive `1gc8itUF…`), post-tag `b3e69e30…` (drive `1lMq-jAl…`), les deux **privés**, vérifiés et clonés |
| Revue | **fixée** | issue **#221** — à chaque `pre-release-*`, et au plus tard **10 février 2027** |
| Déploiement déclenché | **aucun** | seuls des runs CI, en `contents: read` |

**Ce qui reste, et qui n'appartient qu'au propriétaire :**

1. créer les deux rulesets dans l'interface (le jeton de l'agent est refusé en 403 — deux tentatives,
   deux refus, aucun contournement essayé) ;
2. **prouver le refus** : créer `archive/_probe3`, tenter de le supprimer, consigner le refus exact ;
3. revérifier le SHA-256 des bundles après téléchargement (voir `MANIFESTE-VERIFICATION.txt`) ;
4. rejouer la revue de l'issue #221 au premier des deux jalons.

Tant que le point 2 n'a pas été exécuté, **les règles sont absentes** — et le contrôle négatif le
démontre : une balise créée aujourd'hui est supprimable.
