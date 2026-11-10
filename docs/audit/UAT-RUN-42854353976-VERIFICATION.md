# Vérification indépendante — UAT réelle, run 42854353976

**Date :** 10 novembre 2026
**Objet :** contrôle des affirmations du rapport `UAT-REELLE-42854353976-RAPPORT.md` (Drive, privé)
contre le dépôt et l'API GitHub. Aucune valeur de secret n'est reproduite ici.

---

## 1. Ce qui se vérifie exactement

| Affirmation | Contrôle | Résultat |
|---|---|---|
| Tag `audit-2.10.16-6.20.214-2` → `5aa70f44…` | `git ls-remote --tags` | **exact** |
| Parent direct = `c9e1413` | `git rev-parse 5aa70f44^` | **exact** |
| Diff = patch v4.2 original, sans bascule | `git diff c9e1413 5aa70f44 \| sha256sum` | **exact** — `14a081cd…` |
| Diff limité à deux fichiers | `diff --name-only` | **exact** |
| Job unique ciblant `uat` | lecture du workflow au tag | **exact** |
| Run `42854353976` sur ce tag et ce SHA | `actions/runs/42854353976` | **exact** |
| Conclusion `failure`, tentative 1, `workflow_dispatch` | API | **exact** (261 s au total) |
| Étapes 12 et 13 ignorées (`load_test=false`) | `jobs[].steps[]` | **exact** |
| Deux annotations « exit code 1 » | `check-runs/…/annotations` | **exact** — exactement deux |
| Seul `OWNER_RESULT` en échec | rapport + étape 21 `Verdict final` = `failure` | **cohérent** |
| Aucun déploiement lancé | `gh run list --workflow=deploy.yml` | **exact** — dernier déploiement le 09/11 sur `.214` |
| `prd` inchangée | `deployments-branch-policies` | **exact** — `release-*` seul |
| Aucun tag `pre-release-*` créé | `ls-remote` | **exact** |

Les étapes 1 à 20 sont vertes ou ignorées ; seule l'étape 21 « Verdict final » échoue. La lecture
étape par étape ne masque donc rien : **le rouge est visible au niveau du run**, et le rapport
n'assimile pas l'échec à un succès.

---

## 2. Deux points de gouvernance

**2.1 — Le run s'est exécuté sans aucune approbation.**
`environments/uat` : 1 règle de protection, **`reviewers = []`**. Aucune étape d'approbation n'existe
sur cet environnement. Le run a donc démarré seul. Ce n'est pas un contournement de GitHub — c'est la
configuration réelle — mais l'objectif de séparation à quatre yeux n'est pas atteint, et c'est
précisément ce que `uat-audit` (reviewer `MehdiHayanai`) avait été créé pour garantir. Toute
réexécution devrait passer par `uat-audit`, sauf décision explicite contraire.

**2.2 — La policy de `uat` a été élargie de façon durable.**
État constaté : `uat` accepte désormais `pre-release-*` **et** `audit-2.10.16-6.20.214-2`
(type `tag`). L'élargissement est permanent alors que le besoin était ponctuel. Le rapport affirme
une autorisation explicite ; je ne peux ni la vérifier ni la contester depuis ici, mais la portée de
l'autorisation (un audit) ne justifie pas une entrée permanente.

**Recommandation :** revenir à `pre-release-*` seul pour `uat` une fois la séquence d'audit terminée.
Toute modification de protection reste subordonnée à une autorisation explicite — je ne l'applique pas
de moi-même.

---

## 3. L'échec : deux hypothèses à ne pas confondre

Le rapport a raison de refuser la conclusion « identifiants invalides ». Il laisse en revanche deux
hypothèses ouvertes, et **elles n'ont pas la même gravité** :

- **(a) défaut du harnais** — Playwright clique avant que le bandeau ne soit fermé.
  Conséquence : aucun impact utilisateur.
- **(b) défaut produit** — le bandeau `pk-cookie-bar` recouvre réellement le bouton de soumission,
  y compris pour un visiteur humain. Conséquence : **la connexion serait impossible sur mobile et
  desktop pour de vrais utilisateurs**, ce qui serait un incident produit, pas un artefact de test.

Le bandeau intercepte les événements de pointeur pendant 30 s ; ce seul symptôme ne tranche pas.
Un contrôle court suffit à décider : sur la page de connexion UAT, évaluer `document.elementFromPoint`
au centre du bouton et vérifier si le nœud retourné appartient à `pk-cookie-bar`. À faire **avant**
d'adapter le harnais, sinon on corrige le test et on masque le défaut.

---

## 4. Précisions sur les résultats rapportés

- **Durée** : 4 min 13 s correspond au job ; le run complet fait 4 min 21 s.
- **Cache/TTFB** : l'étape est `success`, mais le contrat est **6 OK · 0 KO · 3 à regarder**.
  `x-litespeed-cache` absent des deux GET ⇒ **LiteSpeed non observable** ; cache fichier du thème
  **non vérifié**. La règle v4.2 est respectée : pas de vert présenté comme une preuve.
  `PK_THEME_CACHE_REQUIRED=false` borne la portée, il ne la démontre pas.
- **Chromium** : 547 PASS sur 554, 7 avertissements — 6 WhatsApp, 1 `robots.txt` propre à l'UAT.
  L'étape verte ne signifie pas « zéro constat », et le rapport le dit.
- **Lighthouse** : cinq profils réussis dès la première tentative, desktop inclus. Mais
  **SEO 61 (EN) et 69 (FR/AR)**, et **FR home mobile 80 avec TBT 601 ms** sur les autres profils à 97–98.
  Aucun seuil n'existe pour ces valeurs dans le harnais : « non bloquant » est donc une **décision à
  prendre explicitement**, pas une mesure. Le caractère isolé du cas FR mobile mérite un ticket.

---

## 5. Statut

L'UAT réelle a bien été exécutée, sur le ref prévu, avec le patch exact. Le résultat est **rouge** et
la release **n'est pas validée**. La condition de sortie de la phase 2 reste ouverte tant que le
parcours propriétaire échoue. **Note de qualité : non calculable.**

Prochaine action utile, dans l'ordre : trancher (a) ou (b) au §3 → corriger ce qui doit l'être →
réexécuter sur un environnement à approbation → alors seulement conclure.
