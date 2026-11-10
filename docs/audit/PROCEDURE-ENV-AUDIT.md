# Procédure — environnement `uat-audit` (audit sans déploiement)

État vérifié le 2026-11-10, en lecture seule, sur `hajarbenmlih91-cloud/partikulier3` :

| Constat | Valeur |
|---|---|
| `uat-audit` | **inexistant** (`GET` → 404) |
| Environnements existants | `dev` (0 règle), `prd` (2 règles), `uat` (1 règle) |
| Politique de `uat` | `pre-release-*` (tag), `custom_branch_policies: true` |
| Reviewer de `prd` | `MehdiHayanai`, **id 50800775** (type `User`) |
| Déclencheurs de `deploy.yml` | `on.push.branches: [develop]`, `tags: ['pre-release-*', 'release-*']` |

Conséquence : un tag `audit-…` **ne déclenche aucun workflow de déploiement** — `deploy.yml`
n'écoute que `develop` et les tags `pre-release-*` / `release-*`.

**Mais il déclenche `ci.yml`.** Ce workflow porte `on: push` **sans aucun filtre** : tout
push, branche comme tag, lance la CI (lint, packaging, contrôle d'artefact, matrice
PHP 8.1/8.2/8.3). Il ne contient ni `secrets.`, ni `environment:`, ni étape de déploiement —
c'est vérifié — mais il faut s'y attendre : « un tag d'audit ne déclenche que l'audit » est
**faux**, il déclenche aussi la CI. Ne pas confondre ces runs avec l'audit.

Les rulesets du dépôt (`main-1`, `main-2`) ciblent uniquement `refs/heads/main` : un tag
ou une branche d'audit n'y est pas soumis.

## Corrections apportées au projet initial

1. **`PUT`, pas `POST`.** L'API GitHub crée **ou** met à jour un environnement par
   `PUT /repos/{owner}/{repo}/environments/{environment_name}`. Il n'existe pas de `POST`
   de création. Un `PUT` **remplace** la configuration : d'où la vérification préalable.
2. **Un seul tag, pas `audit-*`.** La règle porte sur le tag exact prévu, pour ne pas
   ouvrir les secrets UAT à tout futur tag d'audit. Un second audit exigera d'ajouter
   explicitement une seconde règle — acte visible et révisable.
3. **Reviewer par identifiant réel** (50800775), relevé sur `prd`, jamais inventé.

## Étapes

### 0. Vérification préalable (déjà faite, à refaire juste avant)

```bash
gh api repos/hajarbenmlih91-cloud/partikulier3/environments/uat-audit   # doit répondre 404
gh api repos/hajarbenmlih91-cloud/partikulier3/environments/uat/deployment-branch-policies \
  -q '.branch_policies[].name'                                          # doit afficher pre-release-*
```

### 1. Créer l'environnement protégé (`PUT`)

```bash
gh api -X PUT repos/hajarbenmlih91-cloud/partikulier3/environments/uat-audit \
  -H 'Accept: application/vnd.github+json' \
  --input - <<'JSON'
{
  "wait_timer": 0,
  "prevent_self_review": false,
  "reviewers": [ { "type": "User", "id": 50800775 } ],
  "deployment_branch_policy": { "protected_branches": false, "custom_branch_policies": true }
}
JSON
```

`prevent_self_review: false` est nécessaire si le responsable lance lui-même l'audit :
à `true`, il ne pourrait pas approuver son propre run.

### 2. Restreindre la règle au tag d'audit prévu (`POST`)

```bash
gh api -X POST \
  repos/hajarbenmlih91-cloud/partikulier3/environments/uat-audit/deployment-branch-policies \
  -f name='audit-2.10.16-6.20.214-1' -f type='tag'
```

Ce `POST` est correct : les politiques de branche d'un environnement sont bien créées par
`POST` sur le sous-chemin `deployment-branch-policies`. La politique de `uat` n'est pas
touchée.

### 3. Secrets — **au responsable uniquement, dans GitHub**

`SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_PRIVATE_KEY`, `SSH_KNOWN_HOSTS`, `WP_PATH`,
`PARTIKULIER_N8N_SECRET`, déposés dans `uat-audit`.

Ces valeurs ne doivent **jamais** passer par une conversation, un commit ou un patch.
Tant qu'elles manquent, un job sur cet environnement démarre sans secrets et échoue
proprement — il ne déploie rien.

## Vérification après coup

```bash
gh api repos/hajarbenmlih91-cloud/partikulier3/environments/uat-audit \
  -q '{name, rules: [.protection_rules[].type], policy: .deployment_branch_policy}'
gh api repos/hajarbenmlih91-cloud/partikulier3/environments/uat-audit/deployment-branch-policies \
  -q '.branch_policies[] | "\(.name) (\(.type))"'
gh api repos/hajarbenmlih91-cloud/partikulier3/environments/uat/deployment-branch-policies \
  -q '.branch_policies[].name'      # doit toujours être pre-release-* : uat intact
```

## Retour arrière

```bash
gh api -X DELETE repos/hajarbenmlih91-cloud/partikulier3/environments/uat-audit
```

Supprime l'environnement, ses règles et ses secrets. Aucun effet sur `uat`, `prd`, `dev`,
ni sur les tags.

## Ce que cette procédure ne fait pas

- Elle ne crée aucun tag, ne lance aucun run, ne déploie rien.
- Elle ne modifie pas la politique de `uat` ni celle de `prd`.
- Elle ne touche pas au tag `pre-release-2.10.16-6.20.214`.

Après ces étapes seulement : bascule de la ligne `environment:` du workflow vers
`uat-audit` **dans le ref d'audit** (pas sur cette branche), création du tag
`audit-2.10.16-6.20.214-1` sur un commit dont le code applicatif est identique à
`c9e1413`, puis exécution.

## Pièges constatés à l'exécution

### Le patch ne bascule pas `environment:`

Le correctif v4.2 laisse `name: uat` (avec un commentaire d'activation). La bascule vers
`uat-audit` est une **ligne supplémentaire**, appliquée sur le ref d'audit seulement. Le
ref exécuté n'est donc pas *exactement* l'application du patch :

- conserver le SHA-256 de l'artefact **et** le SHA du commit d'audit ;
- conserver `git diff c9e1413 <commit-audit>` en preuve : il doit contenir **deux**
  fichiers, rien d'autre ;
- noter la ligne ajoutée hors patch dans le rapport.

Sans cela, la chaîne « hash → patch → ce qui a tourné » est rompue.

### Le dispatch exige `load_test`

`audit-uat.yml` déclare `workflow_dispatch.inputs.load_test` comme **obligatoire**. Sans
`-f load_test=false`, le dispatch n'aboutit pas.

```bash
gh workflow run audit-uat.yml --ref audit-2.10.16-6.20.214-1 -f load_test=false
```

### `prevent_self_review: false`

Le propriétaire peut déclencher **et** approuver. C'est le réglage demandé, mais il faut le
dire : l'approbation n'est alors pas un contrôle indépendant, juste un point d'arrêt
manuel.

### Lire les résultats sans accès aux artefacts

Si la session d'exécution subit le même filtrage réseau que celle qui a rédigé ce dossier
(`results-receiver.actions.githubusercontent.com` et `*.blob.core.windows.net` injoignables),
`gh run view --log` et `gh run download` échoueront en `EOF`. Repli : annotations du check
run (`gh api .../check-runs/<id>/annotations`) et le workflow `audit-uat-report.yml`, qui
retrouve un run et son artefact côté serveur.

## État constaté le 2026-11-10 (après création par le propriétaire)

`uat-audit` a été créé hors de cette session. Relevé par `GET` :

| Élément | Valeur constatée | Conforme ? |
|---|---|---|
| `required_reviewers` | `MehdiHayanai` | oui |
| `deployment_branch_policy` | `custom_branch_policies: true`, `protected_branches: false` | oui |
| Politiques | une seule : `audit-2.10.16-6.20.214-1` (tag) | oui |
| Politique de `uat` | `pre-release-*`, inchangée | oui |
| Politique de `prd` | inchangée | oui |

Aucun `PUT` n'a été nécessaire : l'environnement existait déjà au moment de l'appel, et la
règle « pas de `PUT` si l'existant » a été respectée (l'appel a d'ailleurs été refusé, voir
ci-dessous).

### Limite d'autorisation constatée

Le jeton utilisé par cette session est une **intégration** (`hajarbenmlih91-cloud`), et les
points d'entrée d'administration des environnements et des secrets répondent :

```
403 Resource not accessible by integration
```

Permissions manquantes, nommément :

- `Environments: write` (création/modification d'environnement et de politiques) ;
- `Secrets: write` (et même lecture des **noms** de secrets).

Conséquence : cette session **ne peut ni créer, ni lister, ni écrire les secrets** de
`uat-audit`. Les sept secrets sont donc à déposer par le propriétaire, dans GitHub. Aucune
valeur ne doit transiter par une conversation, un commit ou un patch.

## Pourquoi `uat-audit` et pas `uat`, où les secrets existent déjà

Question posée à juste titre. Deux faits vérifiés le 2026-11-10 tranchent :

| Environnement | Approbation requise | Politique de ref |
|---|---|---|
| `uat` | **aucune** (0 reviewer) | `pre-release-*` |
| `uat-audit` | `MehdiHayanai` | `audit-2.10.16-6.20.214-1` |

1. **La politique de `uat` refuse un tag d'audit.** Elle n'admet que `pre-release-*`.
   Un run sur `audit-…` avec `environment: uat` serait refusé au démarrage. Et le ref
   patché ne peut pas être un `pre-release-*` : tout tag de cette forme déclenche
   `deploy.yml`, donc un déploiement — interdit.
2. **`uat` n'exige aucune approbation.** Y faire tourner l'audit reviendrait à consommer
   des secrets donnant un accès shell **sans aucun point d'arrêt humain**. `uat-audit`
   impose l'approbation de MehdiHayanai.

Coût assumé de la séparation : les sept secrets sont dupliqués, et la rotation devra
couvrir les deux environnements.

Alternative, si la duplication paraît excessive : élargir la politique de `uat` au tag
d'audit exact, et y ajouter un reviewer. Prix : on modifie la protection de
l'environnement qui **déploie** (interdit par le mode opératoire en vigueur) et chaque
déploiement UAT devient soumis à approbation. Décision du propriétaire, hors du périmètre
de cette session.
