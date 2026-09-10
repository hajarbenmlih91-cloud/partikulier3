# Release 2.8.0 / 6.18.9

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

## Politique de versionnement

Le plugin et le thème évoluent avec des versions indépendantes mais doivent être publiés comme une paire compatible lorsque le changement touche les domaines partagés ou l’i18n. Toute modification de schéma doit inclure une migration idempotente, une preuve de rollback et la mise à jour du contrat concerné.
