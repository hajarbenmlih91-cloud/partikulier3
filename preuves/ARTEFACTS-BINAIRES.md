# Artefacts binaires des preuves B1→B6

Les bases SQLite et les captures PNG ne sont pas committées dans Git afin de garder le dépôt léger et de respecter la séparation entre preuves textuelles et snapshots lourds.

Les binaires sont publiés dans la Release GitHub dédiée :

**[Release `preuves-b1-b6`](https://github.com/hajarbenmlih91-cloud/partikulier3/releases/tag/preuves-b1-b6)**

GitHub impose des noms d’assets uniques dans une Release. Les fichiers individuels
utilisent donc le chemin source préfixé par `__` (par exemple
`lot-B1__T0-sauvegarde-base.sqlite`) ; l’archive
`preuves-b1-b6-binaires.tar.gz` conserve en plus l’arborescence originale.

## Snapshots SQLite

La Release conserve les fichiers avec leurs noms et chemins de campagne :

- `lot-B1/T0-sauvegarde-base.sqlite`
- `lot-B1/T1-b1-apres-rollback-reapplique.sqlite`
- `lot-B2/T0-sauvegarde-base.sqlite`
- `lot-B2/T1-b2-apres-rollback-reapplique.sqlite`
- `lot-B3/T0-sauvegarde-base.sqlite`
- `lot-B3/T1-b3-apres-rollback-reapplique.sqlite`
- `lot-B4/T0-sauvegarde-base.sqlite`
- `lot-B4/T1-b4-apres-rollback-reapplique.sqlite`
- `lot-B5/T0-sauvegarde-base.sqlite`
- `lot-B5/T1-b5-apres-rollback-reapplique.sqlite`
- `lot-B6/T0-sauvegarde-base.sqlite`
- `lot-B6/T1-b6-apres-rollback-reapplique.sqlite`
- `audit-conformite-repo-2026-09/T0-avant-audit.sqlite`

## Captures QA

Les sept captures PNG de `audit-conformite-repo-2026-09/scenario9/` et `scenario10/` sont également attachées à la Release. Les preuves textuelles correspondantes décrivent les scénarios, les verdicts et les éventuels faux positifs documentés.

## Vérification

L’archive source reçue a été vérifiée avant extraction :

```text
92836df8875b7c73c4ff4635a052698ef697eb633fd70dbf72b0a95a512f9332
```

Le dépôt ne contient aucun `.sqlite`, `.png` ou `.zip` dans `preuves/`. Le script `scripts/check-evidence.sh` vérifie automatiquement cette règle et les décomptes B1→B6.
