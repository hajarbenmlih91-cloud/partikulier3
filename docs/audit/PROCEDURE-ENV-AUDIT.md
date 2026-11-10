# Procédure — environnement `uat-audit` (audit sans déploiement)

État vérifié le 2026-11-10, en lecture seule, sur `hajarbenmlih91-cloud/partikulier3` :

| Constat | Valeur |
|---|---|
| `uat-audit` | **inexistant** (`GET` → 404) |
| Environnements existants | `dev` (0 règle), `prd` (2 règles), `uat` (1 règle) |
| Politique de `uat` | `pre-release-*` (tag), `custom_branch_policies: true` |
| Reviewer de `prd` | `MehdiHayanai`, **id 50800775** (type `User`) |
| Déclencheurs de `deploy.yml` | `on.push.branches: [develop]`, `tags: ['pre-release-*', 'release-*']` |

Conséquence : un tag `audit-…` **ne déclenche aucun workflow de déploiement**. Il n'existe
pas de déclencheur générique sur « tous les tags ».

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
