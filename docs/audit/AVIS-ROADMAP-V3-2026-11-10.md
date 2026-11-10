# Avis sur la Roadmap V3 — qualité, sécurité, audit UAT

**Objet :** `ROADMAP-V3-QUALITE-SECURITE-2026-11-10.md` (26 297 o, Drive `1uYUvZ2eaAYT9u841op2SvyWQy3EMUyxJ`)
**Date :** 10 novembre 2026
**Méthode :** chaque affirmation vérifiable a été recoupée sur le dépôt et l'API GitHub, pas sur la foi du document.

> Ce fichier ne contient **aucune** valeur de secret. Le dépôt est public : la valeur de la clé par défaut y est
> désignée par son identifiant de code (`DEFAULT_ADMIN_KEY`), jamais recopiée.

---

## 1. Verdict

| Objet | Verdict |
|---|---|
| **Fond du document** (doctrine, séquence, phases 0–2, critères de sortie, note) | **Validé** |
| **Exhaustivité de la phase 1** (fermeture de l'exposition) | **Non validé** — défaut mesuré, §3 |
| **Diffusion du fichier** (`anyoneWithLink`) | **Non validé** — §5 |

La V3 intègre les douze objections formulées sur la V2 et **ne s'appuie sur aucune affirmation fausse** :
neuf affirmations testables, neuf vérifiées exactement (§2). Le désaccord ne porte pas sur la rigueur du
document mais sur **deux angles morts** qu'il laisse, et sur les conditions de sa diffusion.

---

## 2. Ce qui se vérifie exactement

| Affirmation du document | Contrôle | Résultat |
|---|---|---|
| 179 commits absents de `main` | `rev-list --count` après `fetch --unshallow` | **exact** |
| 77 fichiers différents | `diff --name-only … \| wc -l` | **exact** |
| SHA-256 du patch v4.2 `14a081cd…` | `sha256sum` de l'artefact local | **exact** |
| Tag `audit-2.10.16-6.20.214-1` → `4f4c354…` | `git ls-remote --tags` | **existe** |
| Parent direct du commit d'audit = `c9e1413` | `rev-parse 4f4c354^` | **exact** |
| Diff du commit d'audit limité à deux fichiers | `diff --stat c9e1413 4f4c354` | **exact** (212 +, 17 −) |
| CI déclenchée par le push du tag : succès | `gh run list` sur la ref du tag | **exact** (`CI — success`) |
| `uat-audit` : reviewer requis, policy au tag exact, `uat`/`prd` inchangés | `gh api environments` | **exact** |
| Conflit `modify/delete` au cherry-pick de `e9db9ca` | fichier absent de `main`, modifié par `e9db9ca` | **exact** |

Aucun écart. C'est le point le plus important de cet avis : **le document est adossé à des mesures réelles**,
et non à une narration. Les réserves qui suivent sont donc des compléments, pas des réfutations.

---

## 3. Défaut bloquant : la phase 1 ne ferme pas l'exposition

Le critère de sortie de la phase 1 est « **Aucune clé par défaut ou bypass falsifiable** ». Or le correctif
existant ne l'atteint pas, et c'est mesurable en une commande.

`e9db9ca` retire la valeur de `theme/partikulier/inc/class-security.php` et de deux fichiers de documentation.
Sur la branche qui porte ce correctif, elle **subsiste dans un quatrième fichier** :

```text
origin/arena/0e605e79-partikulier3:DOSSIER-2026-10-03-PARTIKULIER-v2.10.16-6.20.11/README.md
```

Conséquence : appliquer la phase 1 telle que rédigée (« cherry-picker `e9db9ca`, résoudre le conflit,
porter les assertions ») laisse la clé écrite en clair dans un README public, et le critère de sortie
serait déclaré satisfait à tort. La phase 1 reproduirait le défaut qu'elle corrige, dans un fichier
moins visible.

**Complément requis dans §1.1** — trois étapes, aucune facultative :

1. Recherche exhaustive de la valeur sur **tout l'arbre** (`git grep -F` sur chaque branche active, docs et
   dossiers datés compris), avec le résultat consigné fichier par fichier.
2. Un contrôle **en échec** sur présence de la valeur (extension de `check-hardcoded.sh`, qui est précisément
   l'objet de §2.1), plus une **fixture prouvant que ce contrôle sait échouer** — cohérent avec la doctrine 5
   et avec l'exigence §2.1.
3. Une phrase explicite : retirer la valeur de l'arbre courant **n'efface pas l'historique**, les clones, les
   caches ni les moteurs de recherche. Seule la rotation (§1.2) ferme l'exposition. Le retrait du code est
   une condition nécessaire, jamais suffisante.

---

## 4. Fait déterminant absent du document : le dépôt est public

```text
gh api repos/hajarbenmlih91-cloud/partikulier3  →  private=false, visibility=public
```

Le document ne mentionne jamais ce fait, alors qu'il commande quatre de ses chapitres :

- **§1.2, revue des journaux** : l'exposition n'est pas confinée à un cercle restreint depuis le 30 septembre.
  Compter les analyses automatisées comme trafic attendu, et chercher les **usages réussis** (connexions,
  créations de comptes, changements de privilèges), pas les tentatives en échec.
- **§0.1, archive bundle** : un bundle d'un dépôt public ne contient aucun secret d'archive. Le dire évite
  d'attribuer une valeur de confidentialité à une mesure qui n'en a pas.
- **Décision de gouvernance à trancher, et non à laisser implicite** : passer le dépôt en privé.
  Le document ne pose jamais la question. À noter : le passage en privé **ne dé-expose rien**
  rétroactivement — c'est une décision d'avenir, pas une remédiation.
- **§2.5, scan de secrets** : un scan d'historique sur un dépôt public doit tourner en continu, pas une fois.

Recommandation : ajouter une sous-section « visibilité du dépôt » en phase 0, avec la décision explicite
(maintenir public / passer privé) et son responsable.

---

## 5. Diffusion du fichier

Le document est partagé en **`anyoneWithLink` / reader**. Il contient la valeur de la clé, des SHA, les noms
d'environnement, la liste des contrôles défaillants et la séquence d'exploitation de la passerelle.

Le fait que la valeur soit **déjà** publique via GitHub ne rend pas la diffusion large anodine : le document
décrit *où* se trouve la faille et *comment* l'atteindre. Il abaisse le coût d'exploitation pour un lecteur
qui n'aurait pas su lire le code.

Recommandation, par ordre de préférence :

1. **Passage en privé** ou partage nominatif avec les relecteurs.
2. Si un lien large est réellement nécessaire : **version assainie** — valeur remplacée par le nom de la
   constante, SHA d'audit retirés, renvoi au présent document pour les détails.

Le document lui-même gagnerait à porter cet avertissement en tête : *ne pas diffuser hors cercle de
remediation tant que la rotation n'est pas terminée*.

---

## 6. Deux corrections de forme

**6.1 — La chaîne de traçabilité reste ouverte sur un maillon.**
Le document consigne le SHA-256 de l'**artefact** (`14a081cd…`) mais pas celui du **diff réellement commité**
dans le tag. Or le commit du tag diffère du patch par exactement une ligne délibérée :

```text
-      name: uat
+      name: uat-audit
```

Maillon manquant, à consigner à côté du premier :

```bash
git diff c9e1413 4f4c354 | sha256sum
# d638529a2665d9a5c68aa91d5785630d9cc172e68d4b86c7fe983bfcf6f0bc9a
```

Sans lui, « l'artefact v4.2 a été appliqué » reste une affirmation ; avec lui, c'est une preuve, et l'écart
d'une ligne est documenté au lieu d'être découvert plus tard.

**6.2 — §2.1 sur `check-hardcoded.sh`.**
Les chemins cassés sont affirmés, non datés. À vérifier au moment de l'exécution, avec la fixture d'échec —
la doctrine 5 s'applique aussi aux contrôles qui contrôlent.

---

## 7. Ce que le dépôt ne peut pas valider

Restent ouverts, et le document le dit correctement : les sept secrets d'environnement (action du
propriétaire), l'UAT réelle (jamais exécutée), l'accès aux journaux, les capacités de sauvegarde de
l'hébergeur, le RPO/RTO. Aucun de ces points ne peut être déclaré acquis depuis ce dépôt.

**Note de qualité : non calculable.** Deux conditions bloquantes restent ouvertes — investigation de
compromission non consignée, UAT réelle non exécutée. Le document retient ce principe (§ doctrine 10 et
tableau des conditions bloquantes) : il est correctement appliqué.

---

## 8. Conclusion

La V3 est un document de pilotage sérieux, mesuré, et honnête sur ses limites. Je la valide comme plan de
travail, **sous réserve** de trois modifications avant diffusion large :

1. compléter §1.1 par la recherche exhaustive de la valeur et le contrôle en échec (§3 ci-dessus) ;
2. ajouter la visibilité du dépôt en phase 0 et la décision qu'elle impose (§4) ;
3. restreindre la diffusion du fichier, ou en publier une version assainie (§5).

Sans le point 1, la phase 1 déclarerait fermée une exposition qui resterait ouverte dans un README public.
C'est le seul point de cet avis qui, à lui seul, justifie de ne pas entériner le document en l'état.

---

## 9. Suivi — V3.1 du 10 novembre 2026

**Verdict révisé : les trois réserves sont levées. V3.1 validée.**

| Réserve | Traitement | Contrôle effectué |
|---|---|---|
| §3 — phase 1 incomplète | recherche exhaustive + contrôle en échec + limites de l'historique ; mécanisme par empreinte, **sans réintroduire le littéral** | lu dans le document |
| §4 — dépôt public absent | §0.2 « Visibilité du dépôt et exposition publique » | lu ; `visibility=public` recoupé |
| §5 — diffusion large | fichier privé dès création, partages publics antérieurs retirés | `list_permissions` : **1 seul** utilisateur (propriétaire) sur V3.1 **et** sur V3 |
| §6.1 — maillon de traçabilité | SHA-256 du diff tagué consigné | `d638529a…` présent ; **identique** à `git diff c9e1413 4f4c354 \| sha256sum` |
| Assainissement | valeur compromise absente | 0 occurrence ; SHA-256 du fichier identique à celui annoncé (`40a9a125…`) |

**Faits préservés après réécriture** : 179 commits, 77 fichiers, `14a081cd…`, `4f4c354…`, `c9e1413`,
`989dcd1…`, 24 PASS, note non calculable. Tag revérifié : `audit-2.10.16-6.20.214-1` pointe toujours
sur `4f4c354…` — aucun déplacement.

**Correction de cet avis, due à la V3.1.** Le §4 ci-dessus affirmait le dépôt « public depuis le début ».
L'API ne permet pas de prouver l'historique de visibilité : seul l'état courant est établi. La V3.1
formule cette limite correctement (« public depuis sa création » n'est retenu qu'avec une preuve
d'historique). C'est la bonne formulation ; la mienne était trop forte. La fenêtre d'exposition de la
valeur reste de l'ordre de six semaines, mesurée depuis son introduction le 30 septembre, et non
depuis la création du dépôt.

**Point résiduel, non bloquant** : §2.1 affirme toujours des chemins cassés dans `check-hardcoded.sh`
sans mesure datée. À vérifier au moment de l'exécution.

**La validation porte sur le document, pas sur le programme** : les sept secrets d'environnement restent
absents, aucune UAT réelle n'a été exécutée, et la note demeure **non calculable** tant que les deux
conditions bloquantes sont ouvertes.
