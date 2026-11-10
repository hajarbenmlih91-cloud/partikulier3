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
- **Mise à jour du 10 novembre** : le propriétaire du dépôt a fourni les extraits de journal
  (erreur Lighthouse, bilan du contrat de cache). Les causes des trois échecs sont donc
  établies sur pièces au § 8, et cette limite ne porte plus que sur l'accès direct de
  l'agent aux fichiers de log.

---

## 8. Mise à jour du 10 novembre — preuves de log et correctif révisé (v2)

Les deux points laissés ouverts au § 2 sont désormais tranchés par les extraits de
journal fournis par le propriétaire du dépôt.

### 8.1 Lighthouse — cause établie, erreur déterministe

Les quatre profils mobiles s'exécutent, puis le profil desktop échoue **immédiatement**
sur un conflit de réglages : `mobile setting (true)` contre `formFactor (desktop)`.

Message exact dans Lighthouse 12.8.2 (paquet npm inspecté, fichier
`core/config/validation.js`, ligne 181) :

```
Screen emulation mobile setting (${settings.screenEmulation.mobile}) does not match
formFactor setting (${settings.formFactor}).
```

Cette validation s'exécute **avant** tout appel réseau. Ce n'est donc ni un délai, ni un
problème Chromium, ni une instabilité : relancer ne changerait rien. Le workflow passait
`--form-factor=desktop` sans fixer l'émulation d'écran, qui reste celle du mobile.

**Correctif retenu** : `--preset=desktop` (fixe ensemble écran, *form factor*, agent
utilisateur et *throttling*), et **au plus 2 tentatives**, désactivées dès que l'erreur
est reconnue comme une erreur de configuration.

### 8.2 Cache — un seul KO, et il n'est pas une panne de cache

Relevé du fichier `cache-ttfb.txt` : **8 OK, 1 KO, 1 à regarder**. Le seul KO est
`X-Partikulier-Cache: MISS` reçu sur une réponse que LiteSpeed annonce `HIT`.
Latences : **p50 268 ms**, **p95 361 ms** — le seuil de 800 ms n'est donc pas en cause
et **n'est pas modifié**.

Le dépôt documente déjà ce piège, dans son propre diagnostic
(`theme/partikulier/pk-diagnostic.php`, ligne 1974) :

> « LiteSpeed Cache actif : c'est lui qui sert le HTML aux visiteurs ; les en-têtes
> X-Partikulier-Cache ne prouvent plus rien pour un visiteur »

Quand LiteSpeed sert une réponse stockée, PHP ne s'exécute pas : l'en-tête du thème est
**rejoué** tel qu'il a été enregistré (au moment où le cache du thème était froid). Il
décrit le passé, pas l'état courant. Le test en faisait pourtant une preuve de `MISS`.

**Correctif retenu** :

- si la réponse du 2ᵉ GET a été rendue par PHP, l'en-tête reste une preuve et le
  réchauffement est jugé normalement (KO si `MISS`) ;
- si LiteSpeed a servi la réponse, l'en-tête n'est plus probant : **`ATT`**, avec la
  mention explicite, au lieu d'un faux KO ;
- **second point de mesure, indépendant de LiteSpeed** : le cache du thème est un cache
  de fichiers (`wp-content/uploads/partikulier-cache`). Le workflow vérifie désormais
  **sur le serveur** (SSH déjà configuré par l'étape 7) la présence d'entrées fraîches
  (`find -newermt '-30 minutes' -size +500c`), et les journalise dans `cache-ttfb.txt`.

Le seuil TTFB, les contrôles de cartes et d'`og:image` sont inchangés.

### 8.3 Nettoyage — message conditionné à la suppression réelle

Confirmé : l'`echo` « Compte temporaire supprimé… » était **hors** du bloc distant, donc
imprimé même lorsque le distant répondait `Compte temporaire absent; aucun nettoyage
requis.` Le message est déplacé **à l'intérieur** du bloc, après la suppression effective.

### 8.4 Liens WhatsApp — constat distinct, non bloquant

Vérifié dans le code du test (`theme/partikulier/tests/audit-uat.mjs`) :

| Ligne | Effet |
|---|---|
| 158 | `record('ux', …, 'warning')` — sévérité **avertissement** |
| 265 | `status = … 'error' ? 'FAIL' : 'PASS_WITH_FINDINGS'` |
| 272 | `process.exitCode = 1` seulement si `status === 'FAIL'` |

Un avertissement ne rougit donc jamais le run : rien n'était masqué, et aucun « vert »
n'a été acheté en l'ignorant. La PR **#220** est `CLOSED` (non fusionnée) depuis le
2026-11-10T10:17:42Z ; sa fermeture **ne tranche pas** la décision produit (faut-il un
numéro public, ou un point d'entrée centralisé ?). À traiter comme un sujet distinct.

### 8.5 Correctif révisé (v2 — remplacé par la v3, voir § 9)

Remplaçait `fix-seed-audit-uat-c9e1413.patch`, dont il reprenait le correctif de `seed`.
La version en vigueur est la **v3** (§ 9).
Deux fichiers, **aucune ligne de code applicatif** :

| Fichier | Changement |
|---|---|
| `.github/workflows/audit-uat.yml` | garde de traçabilité, `fetch-depth: 0`, `seed` corrigé, `cleanup` conditionné, Lighthouse `--preset=desktop` + 2 tentatives + échec non masqué, mesure serveur du cache, commits tracés dans le résumé |
| `theme/partikulier/tests/staging-cache.php` | en-tête rejoué par LiteSpeed traité en `ATT`, plus en KO |

**Validations** (sans exécution distante) :

- `git apply --check` : OK sur un clone vierge de `c9e1413` ;
- YAML : 20 étapes (19 + la garde), parse PyYAML sans erreur ;
- `bash -n` : tous les blocs `run` valides ;
- `staging-cache.php` : syntaxe validée par `php-parser` 3 ;
- `continue-on-error` **conservé** sur `seed` et `cache` (sinon les étapes suivantes,
  dépourvues de `if:`, seraient sautées) ; le verdict lit toujours `outcome`.

**Déjà présent, donc inchangé** : la concurrence est **déjà** définie sur le tag
(`concurrency: group: audit-uat-partikulier`, `cancel-in-progress: false`). Rien à ajouter.

**Non fait, volontairement** : aucun tag, aucune protection modifiée, aucun environnement
créé, aucun run relancé. L'`environment` reste `uat` ; basculer vers `uat-audit` exige que
cet environnement existe d'abord (tags `audit-*`, approbation, mêmes secrets), sans quoi
le job démarrerait sans secrets. Le tag `pre-release-2.10.16-6.20.214` reste intact, et
son YAML immuable ne peut pas porter ces corrections : le relancer tel quel reproduit
les trois échecs.

---

## 9. Correctif v3 — trois défauts de la v2 corrigés, et testé

La v2 (`ef225c4`) était un **livrable de patch**, pas une correction appliquée : elle
ajoutait le correctif et ce diagnostic dans `docs/audit/`, sans modifier le workflow ni le
test. C'était volontaire — aucun fichier exécuté n'a été changé, et c'est précisément le
point : la correction ne prend effet qu'au moment où l'on applique le patch sur un ref
d'audit.

Trois défauts de la v2 ont été relevés et corrigés.

### 9.1 La preuve du cache était trop large

La v2 acceptait **n'importe quel** `.html` de plus de 500 o modifié dans les 30 minutes,
ce qui ne prouve rien sur `/fr/annonces/` : une autre page, ou le passage d'un visiteur,
suffisait à produire un `OK`.

La v3 ne regarde plus que l'entrée de la page testée — `<hote>_fr_annonces.html`, schéma
documenté par `class-cache.php` (« mesure : fichier default_fr_annonces.html ») — et
n'accepte aucun autre fichier. Et si la mesure n'est pas possible, le rapport l'écrit
**`NON VÉRIFIÉ`** au lieu de laisser croire à un succès. Le statut est aussi repris dans le
résumé du run, sur une ligne dédiée :

| Situation | Ligne `cache-ttfb.txt` | Résumé |
|---|---|---|
| entrée exacte, fraîche, > 500 o | `CACHE-THEME: vérifié (<fichier>, <taille>)` | `vérifié (…)` |
| aucune entrée pour cette page | `CACHE-THEME: non vérifié (aucune entrée pour /fr/annonces/)` | `non vérifié (…)` |
| racine d'uploads illisible | `CACHE-THEME: non vérifié (racine uploads illisible)` | `non vérifié (…)` |

Le seuil de TTFB reste inchangé (p50 268 ms, p95 361 ms au dernier run).

### 9.2 La garde de traçabilité excluait trop

Exclure tout `.github` laissait passer une modification de `deploy.yml` ; exclure tout
`theme/partikulier/tests` laissait passer celle d'un autre test. La v3 remplace les
exclusions par une **liste blanche de deux chemins** :

```
.github/workflows/audit-uat.yml
theme/partikulier/tests/staging-cache.php
```

Tout autre fichier présent dans `git diff c9e1413..HEAD` est nommé et **fait échouer**
l'audit avant toute exécution.

### 9.3 Le compte des tentatives Lighthouse était faux

La v2 incrémentait `attempt` après le dernier essai : deux échecs s'annonçaient « après
3 tentatives ». La v3 compte les tentatives **réellement exécutées** (`attempts`,
incrémenté avant l'essai, borné à 2) : un échec de configuration s'annonce « après 1
tentative », deux échecs transitoires « après 2 ». Aucun cas ne transforme un échec
persistant en succès.

### 9.4 Tests d'intégration — 18/18

Le patch a été appliqué sur une **copie propre de `c9e1413`**, puis les blocs bash ont été
**extraits du YAML produit** et exécutés contre des doublures, et `staging-cache.php`
exécuté par **PHP 8.2** (runtime WebAssembly) avec un transport HTTP scénarisé :

| Série | Objet | Résultat |
|---|---|---|
| A (4) | garde de traçabilité : harnais accepté, `deploy.yml` / autre test / code applicatif refusés | 4/4 |
| B (5) | Lighthouse : 1 tentative, 2 tentatives, message « après 2 », pas de « après 3 », erreur de config non rejouée | 5/5 |
| C (6) | cache : entrée exacte acceptée, autre page / entrée tronquée / périmée / SSH absent refusés | 6/6 |
| D (3) | contrat : `HIT` LiteSpeed rejoué → `ATT` (code 0) ; deux réponses PHP → `OK` ; deux réponses PHP sans réchauffement → `KO` (code 1) | 3/3 |

Détail et limites : `docs/audit/UAT-HARNAIS-TESTS.md`. Le scénario `php_two_render_bad`
montre que le correctif **ne rend pas le test complaisant** : un défaut réel reste un KO.

### 9.5 Ce qui reste non couvert, et dans quel ordre avancer

1. **Non couvert** : l'UAT réelle, LiteSpeed et WordPress réels, `wp_get_upload_dir()` sur
   le serveur, Lighthouse contre un Chromium, et le rendu GitHub Actions (expressions,
   secrets, politique d'environnement). Ces éléments exigent un run réel.
2. **Ensuite seulement** : création de `uat-audit` par le responsable GitHub — motif de
   tag étroit (`audit-*`), approbation requise, secrets UAT dupliqués — puis bascule de la
   ligne `environment:` du workflow sur `uat-audit`.
3. **Enfin, sur autorisation explicite** : ref d'audit dont le code applicatif est
   **identique à `c9e1413`** (la garde le vérifie désormais elle-même), exécution de
   l'audit, sans déploiement.

Le tag `pre-release-2.10.16-6.20.214` reste intact. Rien n'a été exécuté : ni tag, ni
environnement, ni protection, ni run.

---

## 10. Correctif v4 — deux justesses du cache, critère d'acceptation, tests versionnés

### 10.1 Le nom d'entrée n'était pas celui de l'hôte

La v3 cherchait `*_fr_annonces.html` : n'importe quel hôte satisfaisait donc la preuve.
La v4 lit l'hôte **sur le serveur** (`wp eval … home_url()`), construit le nom exact
`<hote>_fr_annonces.html` et n'accepte que celui-là. Une entrée de même forme mais d'un
autre hôte (`default_fr_annonces.html`) est signalée « entrées proches » et **ne prouve
rien**. C'est le test **C3**.

### 10.2 Un en-tête absent ne vaut pas « PHP a rendu la page »

La v3 concluait que PHP avait rendu la réponse dès que l'en-tête LiteSpeed du 2ᵉ GET
n'était pas `HIT` — y compris quand il était **absent**, alors qu'il était présent au
1er GET. La v4 distingue trois cas seulement :

| 1er GET | 2ᵉ GET | Conclusion |
|---|---|---|
| présent | **absent** | `NON VÉRIFIÉ` — on ne sait pas qui a rendu la page |
| présent | `HIT` | en-tête rejoué : non probant (`ATT`) |
| présent/absent | présent et ≠ `HIT`, ou absent des deux | PHP a rendu : l'en-tête fait foi (`OK` / `KO`) |

C'est le scénario **D2** (`ls_header_gone`).

### 10.3 Le critère d'acceptation est explicite, plus implicite

Dire « non vérifié » ne suffit pas : il faut décider ce que vaut ce non-vérifié. La v4
introduit **`PK_THEME_CACHE_REQUIRED`** sur l'étape de cache :

| Valeur | Effet |
|---|---|
| `'false'` (défaut) | `NON VÉRIFIÉ` est journalisé, la suite reste verte **et le rapport écrit que sa portée s'arrête à LiteSpeed + TTFB** |
| `'true'` | l'absence de preuve fait échouer la suite (`php_rc=1`, `::error::`) |

Le rapport ne peut donc plus laisser croire à une validation complète du cache : chaque
exécution écrit une ligne `PORTÉE:` dans `cache-ttfb.txt`, et le résumé du run ajoute
« Statut « Cache/TTFB » : LiteSpeed et TTFB uniquement — le cache fichier du thème n'est
pas validé » tant que l'entrée exacte n'est pas trouvée. **Le choix reste au propriétaire :
c'est une ligne de configuration, pas une décision cachée dans le code.**

### 10.4 Les tests sont désormais dans le dépôt

`docs/audit/harnais-tests/` : `gen.py` (extrait les blocs du workflow), `run.sh`
(séries A, B, C), `gen_php.py` + `run_php.mjs` (série D en PHP 8.2 WebAssembly), et le
`README.md` de reproduction. Résultat sur la copie patchée de `c9e1413` :
**22 tests, 22 PASS, 0 FAIL** (18 bash, 4 PHP). Détail : `docs/audit/UAT-HARNAIS-TESTS.md`.

### 10.5 État inchangé

`a28742e` puis ce commit n'appliquent toujours rien au workflow exécuté : ce sont des
livrables de patch. Aucun tag, aucun environnement, aucune protection, aucun run, aucun
déploiement. Le tag `pre-release-2.10.16-6.20.214` reste intact.

Suite, dans cet ordre, sur autorisation : examen du patch → création et protection de
`uat-audit` (tags `audit-*`, approbation, secrets UAT) → bascule de la ligne
`environment:` → ref d'audit dont la garde vérifie elle-même l'identité du code applicatif
avec `c9e1413` → exécution, sans déploiement.

---

## 11. Correctif v4.1 — une présence n'est pas une preuve de cache

Relevé en relecture, et fondé : la v4 déclarait l'entrée « vérifiée » sur son **nom**, sa
**taille** et sa **date**. Son propre test C2 fabriquait un fichier de 800 caractères `x`
et attendait « vérifié ». Cela prouvait qu'un fichier récent portait le bon nom — rien de
plus. Ni page HTML valide, ni service de la page par le cache.

### 11.1 Le contenu est désormais contrôlé

La v4.1 exige en plus que le fichier **commence par `<!doctype html` ou `<html`** — c'est
exactement le critère que le thème applique pour refuser une entrée empoisonnée
(`class-cache.php`, `entry_is_poisoned`: taille < 500 o, ou absence de balise HTML).
Le libellé passe de « vérifié » à **`CACHE-THEME: entrée valide`**, et le contrôle
d'acceptation ainsi que le résumé du run sont alignés sur ce libellé.

Le test **C2b** reprend le cas critiqué : un fichier de 800 `x` au bon nom est désormais
**non vérifié**.

### 11.2 La portée est écrite noir sur blanc

Même en cas de succès, la sonde écrit :

> `PORTÉE: l entrée attendue existe, est fraîche et commence par du HTML. Le SERVICE de cette page par le cache n est pas prouvé pour autant`

et le résumé du run ajoute : « une entrée valide existe, ce qui ne prouve pas que la page
soit réellement servie depuis ce cache ». La distinction « entrée valide » / « page servie »
n'est plus implicite.

### 11.3 Décision d'acceptation pour l'audit `.214`

`PK_THEME_CACHE_REQUIRED` reste à **`'false'`**, avec la portée annoncée : **LiteSpeed et
TTFB validés, cache fichier du thème non validé**. Le passer à `'true'` supposerait
d'abord une preuve de service — à construire, et à discuter comme exigence de recette.

### 11.4 Application : viser le ref d'audit, jamais cette branche

Confirmé : ce patch suppose un ref dont le **code applicatif est identique à `c9e1413`**.
L'appliquer sur `arena/f15ac349-partikulier3` produirait un ref dont le code applicatif
vient de `main` : la garde de traçabilité **ferait échouer l'audit**, à juste titre.

Procédure, dans cet ordre, chaque étape restant soumise à autorisation :

1. **Examen** du patch `fix-harness-audit-uat-c9e1413-v4.1.patch` (sha256 `109e3fa5…`).
2. **Création et protection de `uat-audit`** par le responsable : tags `audit-*`,
   approbation requise, secrets UAT dupliqués. Aucune protection existante n'est élargie.
3. **Bascule** de la ligne `environment:` du workflow vers `uat-audit` (une ligne).
4. **Ref d'audit** : branche issue de `c9e1413`, patch appliqué, tag `audit-…` — jamais
   `pre-release-*`, pour ne déclencher aucun déploiement. La garde vérifie alors elle-même
   que seuls les deux fichiers autorisés diffèrent.
5. **Exécution** de l'audit, puis lecture des `outcome` et du verdict.

État à cet instant : **rien n'a été exécuté** — aucun tag, aucun environnement, aucune
protection, aucun run, aucun déploiement. Le tag `pre-release-2.10.16-6.20.214` reste
intact.

---

## 12. Correctif v4.2 — l'absence des deux en-têtes ne prouve pas PHP

Dernier cas de classification non prouvée, relevé en relecture. Quand **les deux** réponses
sont dépourvues de `x-litespeed-cache`, la v4.1 calculait `$php2 = true` : elle en déduisait
que PHP avait servi la page, puis jugeait `x-partikulier-cache: MISS` comme un échec réel.
Or l'absence d'en-tête ne prouve rien de tel — un proxy peut le retirer, une réponse peut
venir d'un cache intermédiaire, et l'hébergement peut être dépourvu de LSCache.

C'est la même erreur de raisonnement que celle corrigée au § 11, une couche plus bas.

### 12.1 Quatre cas, dont deux qui ne concluent pas

| En-tête `x-litespeed-cache` | Conclusion |
|---|---|
| absent sur les **deux** réponses | **`NON VÉRIFIÉ`** — on ne sait pas qui a servi la page |
| présent puis **absent** | **`NON VÉRIFIÉ`** — ambigu |
| `HIT` au 2ᵉ GET | en-tête du thème rejoué, non probant (`ATT`) |
| présent, ≠ `HIT` (ou absent des deux GET mais avec en-tête) | PHP a rendu : l'en-tête fait foi (`OK` / `KO`) |

Le contrat ne fait donc plus échouer le contrôle sur l'en-tête du thème quand la preuve
manque. Le scénario `ls_both_absent` est ajouté aux tests versionnés (série D, 5 cas).

### 12.2 Effet sur le verdict de l'audit

Aucun changement de comportement attendu sur l'UAT réelle : les deux GET y portent des
en-têtes LiteSpeed, et le second est un `HIT` — cas déjà classé `ATT` non probant depuis
la v2. La v4.2 ferme un trou de raisonnement dans un cas que l'UAT n'a pas présenté, mais
qui se présenterait sur un hébergement sans LSCache ou derrière un proxy filtrant.

### 12.3 État et suite

Correctif : `docs/audit/fix-harness-audit-uat-c9e1413-v4.2.patch` (sha256 `14a081cd…`),
`PK_THEME_CACHE_REQUIRED` maintenu à **`'false'`** avec portée annoncée : **LiteSpeed et
TTFB validés, cache fichier du thème non validé**.

Tests : **24 PASS, 0 FAIL** (19 bash, 5 PHP) sur la copie patchée de `c9e1413`.

Rien n'a été exécuté : aucun tag, aucun environnement, aucune protection, aucun run, aucun
déploiement. Le tag `pre-release-2.10.16-6.20.214` reste intact. L'étape suivante
(création de `uat-audit`) attend une confirmation explicite et se fait sans élargir la
politique de l'environnement `uat`.

---

## 13. Étape 2 — procédure d'environnement corrigée

Deux erreurs d'administration relevées en relecture, toutes deux fondées :

1. **L'API GitHub crée ou met à jour un environnement par `PUT`**
   (`/repos/{owner}/{repo}/environments/{environment_name}`), pas par `POST`. Un `POST`
   de création n'existe pas. Et comme un `PUT` **remplace** la configuration, il faut
   vérifier l'existence de l'environnement avant de l'appeler.
2. **`audit-*` est plus large que nécessaire.** La politique ne doit couvrir que le tag
   d'audit prévu, sinon tout futur tag d'audit hériterait des secrets UAT.

État vérifié en lecture seule le 2026-11-10 :

| Constat | Valeur |
|---|---|
| `uat-audit` | absent (`GET` → 404) |
| Reviewer de `prd` | `MehdiHayanai`, id **50800775** |
| Politique de `uat` | `pre-release-*` (tag) |
| `deploy.yml` | déclenché par `develop` et les tags `pre-release-*` / `release-*` uniquement |

Un tag `audit-…` ne déclenche donc **aucun** workflow de déploiement : il n'existe pas de
déclencheur « tous les tags ».

Procédure détaillée, commandes exactes, vérifications et retour arrière :
`docs/audit/PROCEDURE-ENV-AUDIT.md`. Les secrets restent à déposer par le responsable dans
GitHub — ils ne passent ni par une conversation, ni par un commit, ni par un patch.

**Aucun appel n'a été effectué** : l'environnement n'existe pas, aucune politique n'a été
créée, aucun tag n'a été posé.
