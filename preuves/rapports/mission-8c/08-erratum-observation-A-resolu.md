# MISE À JOUR DE L'ERRATUM — Observation A (chaîne outil SE-019) : TRANCHÉE PAR MÉTADONNÉES

**Référence** : pack 14, `01-erratum-et-observations.md`, observation 1 (statut au
scellé : « INDÉTERMINÉ, à trancher par re-dérivation »).
**Ce document** : état après l'arbitrage du vérificateur (04) ET sa vérification
indépendante à la source primaire Packagist par le préparateur (05 §E, 06).

## Rappel du désaccord

- **Notes de Release Train 2** : PHPCSUtils **1.2.3** / PHPCSExtra **1.5.1**.
- **Contre-allégation** (session de vérification SE-019) : PHPCSUtils **1.1.1** /
  PHPCSExtra **1.5.0**.
- Au scellé du pack 14 : aucune preuve survivante (pack-12, scripts et installation de
  la chaîne détruits par les remises à zéro) → statut INDÉTERMINÉ, hypothèse principale
  = erreur de transmission, protocole de tranchage = re-dérivation SE-023.

## Ce qui a changé (2026-09-15)

1. **Réinstallation à l'identique** de la chaîne des notes par le vérificateur :
   `composer require squizlabs/php_codesniffer:3.13.6 wp-coding-standards/wpcs:3.4.1
   phpcsstandards/phpcsutils:1.2.3 phpcsstandards/phpcsextra:1.5.1` → **s'installe et
   fonctionne** ; composer.lock exact.
2. **La combinaison alléguée est insoluble** : tentative d'installation
   `wpcs:3.4.1 + phpcsutils:1.1.1` rejetée par composer (wpcs 3.4.1 exige `^1.2.3`) ;
   et `phpcsextra:1.5.0 + phpcsutils:1.1.1` rejetée aussi (^1.2.0).
3. **Le vérificateur retire sa contre-allégation** : auto-contradictoire avec les
   métadonnées publiques.
4. **Vérification indépendante du préparateur sur repo.packagist.org** (sortie brute
   en 06) : les contraintes et dates citées sont exactes (wpcs 3.4.1 du 2026-07-27
   exige `phpcsutils ^1.2.3` + `phpcsextra ^1.5.1` ; wpcs 3.3.0 exige `^1.1.0` ;
   phpcsutils 1.1.1 = 2025-08-10).

## Verdict consigné

- La combinaison « wpcs 3.4.1 + phpcsutils 1.1.1 + extra 1.5.0 » **ne peut pas avoir
  existé sur une installation composer** — l'allégation relevait d'une confusion de
  générations (1.1.1 n'est cohérent qu'avec wpcs 3.3.0).
- La chaîne des notes de Release (3.13.6 / wpcs 3.4.1 / phpcsutils 1.2.3 /
  phpcsextra 1.5.1) est **la combinaison minimale résoluble** au 13/09/2026 (toutes
  ses bornes sont sorties au plus tard le 2026-07-27) → un `composer require` frais
  à la date d'exécution SE-019 donnait nécessairement cette chaîne ou une postérieure.
- **Statut : TRANCHÉ PAR MÉTADONNÉES — notes de Release confirmées (probabilité
  très élevée)**. La re-dérivation SE-023 (reproduire `53e2df5` depuis `1a126e0`
  octet pour octet) reste la voie de certitude absolue et le protocole d'ouverture
  de SE-023 (gel de chaîne : composer.json/lock versionnés + `phpcs -i` brut dans
  chaque rapport) demeure inchangé et TOUJOURS requis — ce tranchage ne l'annule pas,
  il l'anticipe.

## Registre consolidé des écarts de la chaîne Train 2 (mise à jour)

| # | Écart | Statut |
|---|---|---|
| 1 | Divergence d'empreinte du conteneur zip du pack 13 au transfert (1 304 024 o vs 1 316 008 o) | LEVÉE (Task 47) — preuve cryptographique : manifeste interne 12/12 + SHA du commit ; canal re-compressant, empreinte du conteneur déclassée |
| 2 | Chaîne outil PHPCSUtils/PHPCSExtra (notes 1.2.3/1.5.1 vs allégation 1.1.1/1.5.0) | **TRANCHÉE PAR MÉTADONNÉES (ce dossier)** — contre-allégation retirée par son auteur, vérifiée insoluble à la source Packagist ; notes confirmées ; re-dérivation SE-023 pour la certitude absolue |
| 3 | Ligne +1 changelog non mentionnée dans les notes (le bullet SE-019 du readme.txt) | COSMÉTIQUE — aucune action (changelog canonique = readme.txt public) |
