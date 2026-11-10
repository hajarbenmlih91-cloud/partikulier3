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

### Niveau 3 — branches, là où le risque est réel

| Motif | Règle | Effet |
|---|---|---|
| `refs/heads/*` | `deletion` | empêche la suppression accidentelle d'une branche portant du travail unique — n'entrave aucun `push` |
| `refs/heads/main` | déjà couvert | `deletion` + `non_fast_forward` + PR + checks (attention au bypass `RepositoryRole: always` de `main-2`) |
| `refs/heads/arena/*`, `refs/heads/feat/*` | `non_fast_forward` | **facultatif** : bloque le rebase sur ces branches ; à réserver à celles qui portent ≥ 100 commits |

**Ne pas** appliquer `non_fast_forward` à `refs/heads/*` : cela casserait toute réécriture d'historique
sur les branches de travail, y compris les rebases légitimes.

## 4. Ce qui mérite un tag, et ce qui n'en mérite pas

| Objet | Tag ? | Pourquoi |
|---|---|---|
| `c9e1413` (production `.214`) | **oui** — `archive/pre-release-2.10.16-6.20.214` | seule référence ; le tag doit être annoté avec le SHA du bundle |
| Les 5 branches à ≥ 100 commits | **non** | un tag sur un travail non fusionné le fige sans le rendre utilisable ; préférer `deletion` + bundle |
| `main` | non | déjà ancrée par une branche protégée |
| Les 212 tags ancrés par une branche | non | aucun besoin de protection contre la perte |

## 5. Correctif annexe

`deploy.yml` route un push sur `develop` vers l'environnement dev, mais la branche n'existe pas.
Soit la créer et la protéger, soit retirer la route. Une configuration qui décrit un chemin inexistant
finit par donner une fausse assurance.
