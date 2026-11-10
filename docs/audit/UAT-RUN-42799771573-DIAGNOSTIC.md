# Diagnostic — audit UAT, run `42799771573` (tag `pre-release-2.10.16-6.20.214`)

**Date :** 10 novembre 2026
**Cible :** `https://darkcyan-yak-151002.hostingersite.com` (UAT uniquement)
**Run :** [#42799771573](https://github.com/hajarbenmlih91-cloud/partikulier3/actions/runs/42799771573) — `workflow_dispatch`, ref `pre-release-2.10.16-6.20.214`, commit `c9e141376179f1a47e567361c28e747b6e891cd0`
**Tentatives :** 2 (10:30:50 → 10:36:28, puis 10:37:26 → 10:41:18) — **toutes deux en échec**
**Verdict final :** ❌ `failure`

---

## 1. Cause racine du parcours propriétaire ignoré

L'étape 8 « Créer un compte propriétaire jetable » **échoue réellement** (exit 1) mais est **affichée en vert** parce qu'elle porte `continue-on-error: true`. GitHub expose alors deux valeurs distinctes :

| | valeur | effet |
|---|---|---|
| `steps.seed.outcome` | **`failure`** | résultat réel de la commande |
| `steps.seed.conclusion` | `success` | ce que l'interface affiche (à cause de `continue-on-error`) |

Or l'étape 15 est conditionnée par **`outcome`** :

```yaml
# .github/workflows/audit-uat.yml:236-238 (commit c9e1413)
- name: Parcours connecté du propriétaire temporaire
  id: owner_journey
  if: steps.seed.outcome == 'success'      # <-- outcome = failure => étape SKIPPED
```

D'où la chaîne observée : `seed` vert → `owner_journey` **skipped** → le verdict final compte `skipped` comme un échec → run rouge.

**La commande fautive** (`audit-uat.yml:173-175` au commit du tag) :

```bash
meta_input="{\"_pk_uat_audit_marker\":\"$marker\"}"
wp user create "$login" "$email" --role=contributor --user_pass="$password" \
              --meta_input="$meta_input" --porcelain
```

`wp user create` **n'accepte pas** `--meta_input`. La synopsis de la commande (`wp-cli/entity-command`, `src/User_Command.php`, méthode `create()`) énumère : `role`, `user_pass`, `user_registered`, `display_name`, `user_nicename`, `user_url`, `nickname`, `first_name`, `last_name`, `description`, `rich_editing`, `syntax_highlighting`, `send-email`, `porcelain`. **Aucun `meta_input`.** Seule `wp user update` le propose. WP-CLI valide la synopsis **avant** d'exécuter la commande : il rejette le paramètre inconnu, sort en code 1, et **aucun utilisateur n'est créé**.

Deux confirmations indépendantes :

1. **Historique Git** — le correctif existe déjà sur `main`, commit `0ae0ec8` (08/11/2026, « Corriger le workflow d'audit UAT : compte jetable et profil Lighthouse desktop »), qui remplace exactement ces deux lignes par `wp user create` + `wp user meta add` + vérification du marqueur. Ce commit **n'est pas un ancêtre** du commit du tag (`git merge-base --is-ancestor 0ae0ec8 c9e1413` → faux) : la branche `feat/profil-acheteur`, sur laquelle le tag a été posé, a divergé avant le correctif.
2. **Annotations du check run** — `check-runs/129777184802/annotations` renvoie 4 annotations de niveau `failure` (« Process completed with exit code 1 ») rattachées aux **étapes 8, 10, 17 et 20**. L'étape 8 est donc bien une étape en échec au niveau commande.

**Ce n'est donc pas un problème d'environnement, de réseau, de droits SSH ou de timing : c'est une commande invalide, déterministe, qui échoue à l'identique à chaque tentative.**

---

## 2. Deux autres échecs masqués (le verdict ne peut pas être vert même après le correctif)

Contrairement à l'hypothèse de départ, **le cache et Lighthouse ne sont pas passés**. Les annotations du check run désignent 4 étapes en échec :

| Étape (n°) | Nom | `conclusion` affichée | `outcome` réel |
|---|---|---|---|
| 8 | Créer un compte propriétaire jetable | success | **failure** |
| 10 | Contrat cache et TTFB extérieur | success | **failure** |
| 17 | Lighthouse mobile et desktop | success | **failure** |
| 20 | Verdict final | failure | failure |

Le verdict final (`audit-uat.yml:381-387`) exige `outcome == success` pour **SSH, SEED, CLEANUP, SECURITY, CACHE, BROWSER, OWNER, LIGHTHOUSE, INTERACTIONS**. Avec `seed`, `cache` et `lighthouse` en échec, **le run restera rouge même si le compte jetable est corrigé**.

- `staging-cache.php` sort en `exit($KO ? 1 : 0)` : le KO porte sur un critère de contenu (2ᵉ GET non `HIT`, cartes absentes du HTML, `og:image` non absolue, **médiane TTFB > 800 ms**). C'est un constat sur l'hébergement UAT, pas un bug du workflow.
- L'étape Lighthouse du tag exécute 5 profils sous `set -euo pipefail` : le moindre échec de `lighthouse` (chemin Chromium, réseau, page instable) fait tomber l'étape. La version de `main` (`0ae0ec8`) durcit ce point : elle supprime `continue-on-error`, ajoute des drapeaux d'émulation desktop et agrège les échecs dans `lh_failed` — mais elle rend l'étape **bloquante**.

**À retenir : « vert » exige de traiter aussi cache (côté UAT) et Lighthouse.** Le correctif du compte jetable est nécessaire mais pas suffisant.

---

## 3. Correctif appliqué

Patch : [`docs/audit/fix-seed-audit-uat-c9e1413.patch`](fix-seed-audit-uat-c9e1413.patch) — **30 insertions, 2 suppressions, un seul fichier**. Il reprend à l'identique le bloc déjà validé sur `main` (`0ae0ec8`) :

- création du compte avec `wp user create … --porcelain` (sans `--meta_input`) ;
- écriture du marqueur par `wp user meta add`, puis **relecture de contrôle** ;
- **rollback immédiat** (`delete_created`) si le marqueur ne peut pas être écrit ou relu, avec contrôle d'identité avant suppression ;
- `printf '%s\n' "$user_id"` pour que stdout ne contienne **que** l'ID (sinon « Success: Added custom field. » pollue la valeur capturée et casse le test `^[0-9]+$`).

### Validations effectuées (sans exécution distante)

| Contrôle | Résultat |
|---|---|
| `git apply --check` sur un clone propre à `c9e1413` | ✅ OK |
| Parsing YAML du workflow patché (`yaml.safe_load`) | ✅ 19 étapes, workflow valide |
| `bash -n` sur le script extrait de l'étape `seed` | ✅ OK |
| Occurrences de `meta_input` restantes | ✅ 0 |
| Forme des commandes WP-CLI | ✅ identique à la version déjà verte sur `main` |

### Ce que le correctif ne change pas

`continue-on-error: true` est **conservé** sur `seed`. C'est volontaire : le retirer ferait sauter toutes les étapes suivantes sans `if:` (sécurité, cache, navigateur, interactions, Lighthouse). L'échec reste visible via l'annotation GitHub. Seul le verdict final doit échouer, ce qu'il fait déjà.

---

## 4. Pourquoi je n'ai pas relancé l'audit (blocage structurel)

Ce n'est pas un manque de permission (`gh api … -q .permissions` → **admin** sur le dépôt), c'est une **impossibilité de configuration** :

1. `workflow_dispatch` exécute le fichier de workflow **du ref visé**. Sur le tag `pre-release-2.10.16-6.20.214`, ce fichier est immuable et **contient la commande fautive**. « Même tag, même commit » ⇒ même YAML ⇒ échec identique. **Relancer ne peut rien changer.**
2. Pour exécuter le YAML corrigé, il faut un **nouveau ref**.
3. Mais l'environnement `uat` a une *deployment branch policy* limitée à **`pre-release-*`** (`/environments/uat/deployment-branch-policies`). Une branche est donc refusée au démarrage du job.
4. Et tout tag `pre-release-*` déclenche **`deploy.yml`** (déploiement UAT automatique : l'environnement `uat` n'a pas de *required reviewers*, contrairement à `prd`).

**Conséquence : dans la configuration actuelle, on ne peut pas auditer un correctif du workflow d'audit sans déployer.** L'audit est couplé au déploiement par le motif de tag et par la politique d'environnement.

J'ai donc respecté les garde-fous : **pas de retag, pas de déploiement, aucune modification de protection GitHub, aucune manipulation d'identifiant.**

### Changement requis (au choix, décision du propriétaire)

**Option A — découpler l'audit du déploiement (recommandée, aucun déploiement).**
Autoriser un motif de tag dédié à l'audit dans l'environnement `uat`, motif qui **ne déclenche pas** `deploy.yml` :

```bash
gh api -X POST repos/hajarbenmlih91-cloud/partikulier3/environments/uat/deployment-branch-policies \
  -f name='audit-*' -f type=tag
```
puis, sur un commit = `c9e1413` + **uniquement** le patch ci-joint :

```bash
git switch feat/profil-acheteur && git apply docs/audit/fix-seed-audit-uat-c9e1413.patch
git commit -am "fix(audit-uat): compte jetable sans --meta_input (rejeté par wp user create)"
git tag audit-2.10.16-6.20.214-1
git push origin feat/profil-acheteur audit-2.10.16-6.20.214-1
gh workflow run audit-uat.yml --ref audit-2.10.16-6.20.214-1
```
Le tag `audit-*` ne correspond ni à `pre-release-*` ni à `release-*` : `deploy.yml` ne se déclenche pas.

**Option B — nouveau tag `pre-release-…215`.** Fonctionne sans toucher aux protections, mais **déclenche un déploiement UAT**. Les artefacts seraient identiques (le commit ne modifie que `.github/`), mais cela reste un déploiement : hors périmètre des garde-fous, donc non exécuté par mes soins.

**Option C — traiter aussi `cache` et `lighthouse`** avant toute relance, sinon le verdict restera rouge quel que soit le correctif.

---

## 5. État du compte temporaire

Le compte `pk_audit_42799771573_1` / `_2` **n'a jamais été créé** : WP-CLI valide la synopsis avant exécution, l'erreur survient donc avant l'insertion en base. L'étape 16 (nettoyage, `if: always()`) s'est exécutée en succès et a constaté l'absence de compte. **Aucun compte orphelin, aucune donnée à nettoyer sur l'UAT.**

---

## 6. Résumé des étapes du run (tentative 2)

| # | Étape | Conclusion | Lecture |
|---|---|---|---|
| 7 | Configurer SSH strict vers l'UAT | success | secrets et cible UAT validés |
| **8** | **Créer un compte propriétaire jetable** | **success** (affiché) / **failure** (réel) | **cause racine** |
| 9 | Contrats sécurité live | success | |
| **10** | **Contrat cache et TTFB** | **success** (affiché) / **failure** (réel) | à traiter côté UAT |
| 13 | Audit Chromium / Axe | success | |
| 14 | Interactions + Web Vitals | success | |
| **15** | **Parcours connecté propriétaire** | **skipped** | conséquence directe |
| 16 | Suppression du compte de test | success | rien à supprimer |
| **17** | **Lighthouse** | **success** (affiché) / **failure** (réel) | à traiter |
| **20** | **Verdict final** | **failure** | conséquence |

---

## 7. Limites de ce diagnostic

- Les **journaux bruts** des étapes sont hébergés sur `results-receiver.actions.githubusercontent.com` et l'artefact sur `productionresultssa11.blob.core.windows.net`. Les deux sont **inaccessibles depuis cet environnement** (sortie réseau restreinte) : `gh run view --log` et `gh run download` échouent en `EOF`. Le diagnostic s'appuie donc sur les **annotations du check run** (qui prouvent quelles étapes ont sorti en code 1), sur le **fichier de workflow du commit**, sur la **synopsis WP-CLI** et sur l'**historique Git** — pas sur le texte du log.
- Aucun test n'a été mené contre l'UAT (aucune requête, aucun compte créé, aucune commande SSH).
