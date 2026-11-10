# Préservation du code de production — archive vérifiée et restaurée

**Date :** 10 novembre 2026
**Contexte :** `c9e1413` (release `pre-release-2.10.16-6.20.214`) n'est ancêtre d'aucune branche — ni
`main`, ni `origin/arena/0e605e79-partikulier3`. Il n'est atteignable que par des tags. Un tag supprimé
rendrait le code de production introuvable.

---

## 1. Archive créée

| Élément | Valeur |
|---|---|
| Fichier | `/home/user/bundles/partikulier3-2026-11-10.bundle` |
| Taille | 24 Mo |
| SHA-256 | `1c712f5aa7fe875f99a814fe0017c9a574e60c932c146bd313f1fc1c4889e876` |
| Refs embarquées | 222 |
| Méthode | `git bundle create … --all` (historique complet, tous refs) |

**Emplacement :** hors du dépôt Git, dans le workspace. Aucun binaire volumineux n'est versionné.

Références critiques contenues :

```text
0682f645  refs/heads/main
2783e6e9  refs/remotes/origin/arena/0e605e79-partikulier3
c9e14137  refs/tags/pre-release-2.10.16-6.20.214
4f4c3546  refs/tags/audit-2.10.16-6.20.214-1
5aa70f44  refs/tags/audit-2.10.16-6.20.214-2
```

## 2. Vérification

```text
$ git bundle verify …bundle
The bundle records a complete history.
The bundle uses this hash algorithm: sha1
```

## 3. Restauration réellement testée

Un bundle non restauré n'est pas une preuve de préservation. Test effectué par clone depuis le bundle
seul, sans accès au dépôt d'origine :

| Contrôle | Résultat |
|---|---|
| `git clone` depuis le bundle | **OK** |
| `rev-parse c9e1413` | `c9e141376179f1a47e567361c28e747b6e891cd0` — **identique** |
| Arbre de `c9e1413` | `b1da8781bb76dc46d4bd288b3aa72e4cebb6a66a` — **identique** à la source |
| `pre-release-2.10.16-6.20.214^{commit}` | `c9e1413…` — **résout correctement** |
| `theme/partikulier/inc/class-security.php` dans `c9e1413` | clé par défaut : **0 occurrence** |

## 4. Limites, à ne pas masquer

- **Ce n'est pas une sauvegarde hors site.** Le bundle est dans le workspace, qui n'est pas un support
  de conservation durable. Il doit être copié par le propriétaire vers un support maîtrisé (hPanel,
  disque local, coffre), **et le SHA-256 ci-dessus vérifié après copie**.
- **Aucun tag d'archive n'a été créé.** Une règle de travail interdit toute création ou modification de
  tag sans autorisation explicite ; le bundle remplit la même fonction sans toucher aux refs.
- **Aucune valeur de confidentialité.** Le bundle contient l'historique complet, donc les valeurs déjà
  exposées publiquement. Il doit être traité comme une donnée publique, pas comme un secret — ce qui
  n'autorise pas à le diffuser pour autant.
- **Le bundle ne protège de rien d'autre.** Il conserve le code, pas la configuration, pas les secrets,
  pas la base de données.

## 5. Contrôle à refaire

À chaque nouveau tag de release : régénérer un bundle, consigner le SHA-256, refaire le test de
restauration. Un bundle non rejoué après un changement de ref ne protège pas le nouveau ref.

---

## 6. Suite du 10 novembre — tag d'archive et second bundle

### Tag annoté créé

```text
archive/pre-release-2.10.16-6.20.214
  objet tag : 7ec7287ac1f9967966d5ce689a89fbda414a0380
  pelé      : c9e141376179f1a47e567361c28e747b6e891cd0
```

Le message de l'annotation cite le bundle **pré-tag** (`1c712f5a…`), et non un bundle postérieur
qui contiendrait la référence elle-même : une annotation ne doit jamais désigner un artefact qui
dépend de son existence.

Découvrabilité vérifiée dans un clone neuf :

```bash
git tag -l -n99 'archive/*'
# archive/pre-release-2.10.16-6.20.214 Ancre de conservation — code de production de la release .214
```

`git ls-remote` renvoie l'objet tag, pas le commit ; le pelage explicite est nécessaire :

```bash
git rev-parse 'archive/pre-release-2.10.16-6.20.214^{commit}'
```

### Deux bundles conservés

| Bundle | Rôle | Taille | SHA-256 |
|---|---|---:|---|
| `partikulier3-2026-11-10.bundle` | **pré-tag** — cité dans l'annotation | 24 Mo | `1c712f5aa7fe875f99a814fe0017c9a574e60c932c146bd313f1fc1c4889e876` |
| `partikulier3-2026-11-10-post-tag.bundle` | post-tag — atteste l'état après création | 25 Mo | `b3e69e30bd9c513d87796d438a29239d2c6060436959aa64eb028edd6c75eea8` |

Les deux ont été vérifiés (`complete history`) **et clonés** : le tag d'archive se résout sur `c9e1413`
et l'arbre restauré est `b1da8781bb76dc46d4bd288b3aa72e4cebb6a66a`, identique à la source.

### Effets mesurés

- **Aucun déploiement** déclenché par la création du tag : `deploy.yml` ne réagit qu'aux motifs
  `pre-release-*` et `release-*`, que `archive/…` ne satisfait pas. Deux runs CI seulement, en
  `contents: read`.
- **Aucune protection** : la création du ruleset a été refusée en HTTP 403 (`Administration: write`
  manquante). Le tag existe donc **non protégé** à ce stade.

### Ce qui reste à faire par le propriétaire

1. copier les **deux** bundles hors du workspace, revérifier leurs SHA-256 après copie ;
2. créer les rulesets (tags puis branches) — l'agent n'a pas la permission ;
3. tester le refus d'une protection avant de la considérer acquise.
