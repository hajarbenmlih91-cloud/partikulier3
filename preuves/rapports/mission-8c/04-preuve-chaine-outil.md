# PREUVE — Chaîne outil SE-019 : arbitrage par les métadonnées

> Document retransmis verbatim depuis le canal commanditaire (2026-09-15).
> Tableaux restitués en markdown ; contenu non modifié.

Généré le 2026-09-15 par le vérificateur indépendant

## 1. RÉINSTALLATION À L'IDENTIQUE DE LA CHAÎNE DES NOTES DE RELEASE

```
composer require squizlabs/php_codesniffer:3.13.6 \
  wp-coding-standards/wpcs:3.4.1 \
  phpcsstandards/phpcsutils:1.2.3 phpcsstandards/phpcsextra:1.5.1
```

--- composer.lock (versions exactes installées) ---

- dealerdirect/phpcodesniffer-composer-installer == v1.2.1
- phpcsstandards/phpcsextra == 1.5.1
- phpcsstandards/phpcsutils == 1.2.3
- squizlabs/php_codesniffer == 3.13.6
- wp-coding-standards/wpcs == 3.4.1

--- phpcs --version ---

PHP_CodeSniffer version 3.13.6 (stable) by Squiz and PHPCSStandards

--- phpcs -i ---

The installed coding standards are MySource, PEAR, PSR1, PSR2, PSR12, Squiz, Zend, WordPress, WordPress-Core, WordPress-Docs, WordPress-Extra, PHPCSUtils, Modernize, NormalizedArrays and Universal

## 2. LA CONTRE-ALLÉGATION « WPCS 3.4.1 + PHPCSUtils 1.1.1 » EST IRRÉSOLUBLE

(tentative `composer require phpcsutils:1.1.1 + wpcs:3.4.1` → échec)
Message composer :

- wp-coding-standards/wpcs 3.4.1 requires phpcsstandards/phpcsutils ^1.2.3
- phpcsstandards/phpcsextra 1.5.0 requires phpcsstandards/phpcsutils ^1.2.0
- phpcsutils 1.1.1 bloqué en plus par advisory PKSA-kh6k-gs3g-dgr6

## 3. MÉTADONNÉES PACKAGIST (publiques, immuables)

- WPCS 3.4.1 (2026-07-27) : phpcsutils ^1.2.3 / phpcsextra ^1.5.1
- WPCS 3.4.0 (2026-07-16) : phpcsutils ^1.2.2 / phpcsextra ^1.5.0
- WPCS 3.3.0 (2025-11-25) : phpcsutils ^1.1.0 / phpcsextra ^1.5.0
- PHPCSUtils 1.1.1 : 2025-08-10 | 1.2.0 : 2025-11-11 | 1.2.3 : 2026-07-27

## 4. CONSÉQUENCES

- La combinaison « 3.13.6 / WPCS 3.4.1 / PHPCSUtils 1.1.1 / Extra 1.5.0 »
  (allégation du vérificateur, session de vérif SE-019) **ne peut pas avoir
  été produite par composer** : WPCS 3.4.1 exige PHPCSUtils >= 1.2.3.
- La chaîne des notes de Release « 3.13.6 / WPCS 3.4.1 / PHPCSUtils 1.2.3 /
  PHPCSExtra 1.5.1 » est EXACTEMENT la combinaison minimale résoluble ;
  elle s'installe et fonctionne (ci-dessus).
- WPCS 3.4.1 et PHPCSUtils 1.2.3 sont sortis le même jour (2026-07-27) ;
  l'exécution SE-019 (13/09/2026) est postérieure : un composer require
  frais donnait nécessairement cette chaîne.
- « 1.1.1 » n'est cohérent qu'avec WPCS 3.3.0 (^1.1.0) : confusion de
  générations plausible dans l'enregistrement du vérificateur.

## 5. VERDICT

Le vérificateur RETIRE sa contre-allégation (1.1.1/1.5.0) : elle est
auto-contradictoire avec les métadonnées publiques de composer.
Les notes de Release (1.2.3/1.5.1) étaient très probablement exactes.
La certitude finale reste la re-dérivation SE-023 : la chaîne qui
reproduit 53e2df5 octet pour octet depuis 1a126e0 est la canonique.
