# Reprise Phase 1 — dépôt pour revue uniquement

Cette branche archive l’artefact reçu sans appliquer son contenu.

## Identité de l’archive

- Nom reçu : `REPRISE-PHASE1-EXPURGEE-V3.zip`
- Taille : 450736 octets
- SHA-256 : `a467ea0d35f73eff5d327cfcfcd62d83125d41b2825457d41c55b6ddd619b0c7`
- Empreinte recalculée dans cette branche : identique à l’original.
- Fichier inclus : `REPRISE-PHASE1.zip.sha256`

## Base de la branche

- Dépôt : `hajarbenmlih91-cloud/partikulier3`
- SHA de `main` utilisé comme base : `4b41bd65f2414ea9ce0fd847f144c46fccbab521`
- Branche de revue précédente conservée : [review/train3-phase1-20260920-0156](https://github.com/hajarbenmlih91-cloud/partikulier3/tree/review/train3-phase1-20260920-0156)
- Branche courante : `review/train3-phase1-reprise-20260920-2335`

## Contrôles de l’archive

- Entrées inventoriées : 349 entrées ZIP, dont 348 fichiers et le manifeste interne.
- Manifeste interne : `SHA256SUMS-REPRISE.txt`, couvrant 348/348 fichiers hors manifeste ; aucune entrée manquante et aucun fichier non couvert.
- Chemins absolus, traversées `../`, liens symboliques et collisions dangereuses : aucun constat.
- Base logicielle annoncée par le fournisseur : reprise Phase 1 du train 3, avec les lots et preuves décrits dans l’archive. Cette annonce n’est pas une validation indépendante.

## Contenu et limites

Les principaux livrables comprennent les notes de reprise, les différences de lots, les preuves R1/RA-1, les journaux, les contrats JSON, les scripts rejouables, les fixtures et les résultats d’expurgation. Le ZIP et son manifeste sont les seuls éléments déposés ; le contenu n’est pas décompressé dans le dépôt et aucun historique Git de l’archive n’est importé. Aucun fichier attendu pour l’archivage n’était manquant.

L’inspection de sécurité n’a relevé aucune valeur de cookie, session, nonce ou jeton réel non expurgée dans les preuves capturées. Les seuls motifs non-placeholder restants sont dans la documentation, le script d’expurgation et des fixtures explicitement fictives destinées au test négatif ; ils ne sont pas des identifiants de session fournis comme preuves réelles. Les limites annoncées par le fournisseur ne sont pas rejouées ici.

## Statut de revue

> Reprise déposée pour analyse uniquement. Correctifs non appliqués. Tests applicatifs et contre-tests du fournisseur non rejoués par Manus. Résultats annoncés non validés indépendamment. Aucune fusion ni aucun déploiement autorisés.

Le compte rendu fournisseur décrit des commits locaux pendant le travail, puis conclut « aucun commit/push ». Cette contradiction est conservée comme point à clarifier ; elle n’est pas résolue par supposition dans cette revue.

## Automatisations

La branche de revue n’a pas de déclenchement de déploiement identifié. Le workflow `ci.yml` se déclenche sur un push ; `contrats-recette.yml` est limité aux pushes sur `main` et aux pull requests. Aucun workflow ni réglage n’a été modifié.
