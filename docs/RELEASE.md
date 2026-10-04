# Releases

## Release 2.10.10 / 6.20.9

Fusion de `fixes-v3` avec le commit de livraison `f502d438b70bd39582a8ad8a72e570b79c82c42c`. Les deux historiques sont conservés ; les scripts Docker, les approbations UAT/production, les sauvegardes et les règles de tags du dépôt restent la référence.

Les archives `B-INSTALLER-PLUGIN-partikulier-core-2.10.10.zip` et `partikulier-theme-6.20.9.zip` doivent être reconstruites avec `scripts/package.sh`. Ce script produit `SHA256SUMS` et `sbom.cyclonedx.json` à partir des ZIP réels. Le SBOM inventorie uniquement ces deux composants de premier niveau ; il ne certifie ni les dépendances d'un WordPress installé, ni l'absence de CVE. Les archives de livraison et leurs mesures de performance ne constituent pas des preuves pour le code fusionné.

La recette ajoute le contrat `merge-v3-contract.php` (authentification, 2FA, santé, atomicité/concurrence des contacts et démo) et `npm run test:merge` dans le thème (Chromium, Firefox, WebKit ; desktop/mobile ; FR/EN/AR). Le second utilise une fixture créée puis supprimée par `tests/merge-v3-browser-fixture.php` ; `PK_MERGE_FIXTURE` désigne son JSON et `PK_BASE` le WordPress de recette. L'oracle historique reste indépendant. La baseline de périmètre du thème accepte explicitement le nouvel installateur de démo (15 modules dépassant 400 lignes, contre 14 auparavant), sans déplacer les huit domaines métier hors du plugin.

Installation et activation explicite de la passerelle admin, de la 2FA et des données de démo : voir [`INSTALLATION.md`](INSTALLATION.md). La fusion locale ne publie ni tag ni déploiement.

## Historique : release 2.8.0 / 6.18.9

## Provenance

Cette release est issue de l’archive `partikulier.zip` fournie pour le projet. Elle contient le lot C2 : unification i18n du chrome et des formulaires, service `I18nChromeService`, catalogues canoniques et compatibilité thème/plugin.

| Livrable | Version | SHA-256 source |
|---|---:|---|
| `B-INSTALLER-PLUGIN-partikulier-core-2.8.0.zip` | 2.8.0 | `7a442ba1ef1730453171123780df4800af2ee1d04ef422d17727f90174379dcc` |
| `partikulier-theme-6.18.9.zip` | 6.18.9 | `cd7b60e3b75e43c91a6d78cc5f035b4c44244fb091b7f79da7c79087dfc2f9da` |

Les checksums ci-dessus correspondent aux archives reçues et sont conservés dans `provenance/`.

## Contrôles appliqués dans ce dépôt

- extraction propre des deux archives dans des répertoires source dédiés ;
- absence de fichiers `.env`, bases de sauvegarde et clés privées ;
- contrôle de structure et d’extensions interdites dans le packaging ;
- lint PHP si PHP est disponible ;
- contrôle syntaxique shell et JSON ;
- génération des checksums des archives produites.

Les tests d’intégration WordPress et les tests visuels ne sont pas simulés par la CI statique : ils doivent être rejoués sur un WordPress de staging avec Estatik et, si activé, Polylang.

Pour publier les archives sur Hostinger via GitHub Actions, suivre le playbook [`DEPLOIEMENT.md`](DEPLOIEMENT.md). La production doit être protégée par l’approbation de l’environnement GitHub `prd`.

## Politique de versionnement

Le plugin et le thème évoluent avec des versions indépendantes mais doivent être publiés comme une paire compatible lorsque le changement touche les domaines partagés ou l’i18n. Toute modification de schéma doit inclure une migration idempotente, une preuve de rollback et la mise à jour du contrat concerné.
