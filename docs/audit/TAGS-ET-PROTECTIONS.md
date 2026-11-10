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
| Tags **orphelins** (sur aucune branche) | **3** | `pre-release-2.10.16-6.20.214`, `audit-…-1`, `audit-…-2` |
| Branches distantes | 223 | |
| Branches avec du travail absent de `main` | **123** | `arena/0e605e79` : 179 commits ; `essai/page-ouverte-whatsapp` : 116 ; `audit-uat-validation` : 115 ; `feat/profil-acheteur` : 114 ; `audit-workflow-fix` : 114 |
| Rulesets ciblant les tags | **0** | aucune protection |
| Rulesets ciblant les branches | 3 | tous sur `main` (dont un désactivé) |
| Branche `develop` | **inexistante** | alors que `deploy.yml` route « push develop → dev » |

## 3. Niveaux de protection

### Niveau 1 — indispensable

Les **trois tags orphelins** sont la seule référence vers les commits de production et de preuve.
Leur suppression perd le code déployé et la chaîne d'audit.

```text
refs/tags/pre-release-2.10.16-6.20.214   → c9e1413
refs/tags/audit-2.10.16-6.20.214-1       → 4f4c354
refs/tags/audit-2.10.16-6.20.214-2       → 5aa70f44
```

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
| les 5 branches à ≥ 100 commits | `deletion` | coût nul : personne n'a besoin de les supprimer |
| `refs/heads/main` | déjà couvert | `deletion` + `non_fast_forward` + PR + checks (attention au bypass `RepositoryRole: always` de `main-2`) |
| `refs/heads/*` | `deletion` — **différé** | voir la réserve ci-dessous |

**Réserve acceptée.** Une règle `deletion` sur `refs/heads/*` n'est **pas** sans nuisance : elle empêche
aussi la suppression après fusion, le nettoyage des branches de test et la suppression d'une branche
créée par erreur. Sur un dépôt à 223 branches, elle figerait le problème de nettoyage au lieu de le
résoudre.

Séquence retenue : protéger d'abord les branches à travail unique, inventorier les 123 branches
(travail réel / doublon / expérimentation), nettoyer les branches fusionnées, **puis** décider d'une
règle globale. Une protection globale provisoire reste possible pendant l'inventaire, à condition
d'avoir une date de fin et un critère de sortie écrits.

Facteur atténuant à intégrer : GitHub permet la restauration d'une branche récemment supprimée, ce qui
réduit l'urgence d'une règle globale sans la supprimer.

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
