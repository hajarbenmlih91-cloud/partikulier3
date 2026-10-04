# Partikulier 3

Monorepo de livraison du portail immobilier **Partikulier** : thème WordPress et plugin cœur métier, maintenus et validés sur les lots C à F.

## Contenu

| Chemin | Rôle | Version |
|---|---|---:|
| `plugin/partikulier-core` | Plugin cœur : domaines métier, REST, santé, migrations et contrats | **2.10.16** (dossier 03/10/2026) |
| `theme/partikulier` | Thème immobilier : templates, front, i18n FR/EN/AR, Estatik et QA | **6.20.11** (dossier 03/10/2026) |
| `scripts/package.sh` | Packaging reproductible des deux livrables | `PLUGIN 2.10.16 / THEME 6.20.11` |
| `docs/` | Architecture, installation et release | — |
| `DOSSIER-2026-10-03-…` | Livraison consolidée du 3 octobre (zips + Sheets + n8n + PDF) | voir `DOSSIER-…/README.md` |

Le thème délègue au plugin les domaines métier disponibles et conserve un mode de repli compatible lorsque le plugin est désactivé. Les deux composants sont livrés ensemble pour garantir la compatibilité des contrats i18n et des lots C à F.

## Prérequis

- WordPress 6.2 ou supérieur ;
- PHP 8.1 ou supérieur ;
- Estatik ;
- Polylang est optionnel pour les variantes multilingues ;
- Node.js 18+ uniquement pour la QA Playwright du thème.

## Développement local

### Exemple Docker complet (PowerShell)

```powershell
powershell -ExecutionPolicy Bypass -File .\docker\setup-local.ps1
powershell -ExecutionPolicy Bypass -File .\docker\verify-local.ps1
```

Le premier script initialise les secrets manquants dans `.env` (ignoré par Git),
remplace les mots de passe locaux `change-me`, conserve les volumes existants et
migre l'instance n8n autonome `partikulier-n8n` vers Compose sans changer sa clé de
chiffrement ni son compte propriétaire. Sauvegarder les données avant une première
migration. Pour une instance n8n déjà initialisée, `N8N_EMAIL` et `N8N_PASSWORD`
doivent correspondre au compte existant.
Le volume `PK_N8N_VOLUME` est conservé ; une migration réutilise le volume nommé
du conteneur autonome et refuse un montage incompatible. La rotation MySQL
redémarre aussi une base arrêtée avec ses anciens identifiants avant de
modifier `.env`. Si seul le volume subsiste, les anciens mots de passe doivent
être présents dans `.env`. Les secrets et sauvegardes sont exclus du contexte
de construction Docker par `.dockerignore`.

Le site est accessible sur `http://localhost:8099`, n8n sur
`http://localhost:5678` et les emails capturés sur `http://localhost:8025`.
Les identifiants administrateur WordPress sont dans `PK_ADMIN_USER` /
`PK_ADMIN_PASSWORD`, ceux de n8n dans `N8N_EMAIL` / `N8N_PASSWORD`.
Le script configure Polylang FR/EN/AR, les outils AVIF, le jeu de démo avec photos,
une annonce d'exemple et deux workflows n8n publiés : validation d'annonce vers
Mailpit et appels acquéreur signés vers WordPress. Les contenus existants sont
conservés ; un jeu de démo déjà présent n'est pas réimporté.

Le proxy Caddy fournit un HTTPS privé entre WordPress et n8n. La confiance dans
son certificat est installée au démarrage du conteneur et limitée au proxy dans
le client HTTP WordPress. Le mode HMAC reste `enforce`. Le workflow de validation
vérifie la signature HMAC du corps brut avant tout envoi Mailpit ; il ne demande
pas l'ancien en-tête contenant le secret, retiré des appels sortants upstream.
Le webhook acquéreur conserve son authentification par en-tête.
L'accès aux variables
d'environnement et au module `crypto` des nœuds Code n8n est activé pour signer
les requêtes de cet exemple **local uniquement** ; les données d'exécution ne
sont pas conservées. Les messages contenant les accès du propriétaire restent
dans Mailpit. Aucun message WhatsApp réel n'est envoyé : Meta et un webhook
HTTPS public doivent être configurés séparément.

Après initialisation : `docker compose up -d` pour redémarrer et
`docker compose stop` pour arrêter sans perdre les données. Ne pas utiliser
`down -v` pour un simple redémarrage. Les mots de passe et la clé n8n ne doivent
jamais être committés. La vérification d'exemple n'est pas la campagne complète
de contrats CI.

Pour préparer le test WhatsApp réel, renseigner les champs `META_*`,
`WHATSAPP_*` et `N8N_PUBLIC_WEBHOOK_URL` de `.env`. Le script importe les deux
credentials dans n8n et crée **Partikulier WhatsApp - real phone test (WordPress)**
en brouillon. Il ne publie pas ce workflow et ne contacte pas Meta.
Publier manuellement dans n8n pour enregistrer le webhook Meta. Le test accepte
uniquement `WHATSAPP_TEST_RECIPIENT` : `TEST` affiche l'aide, une référence
`PK-...` demande un contact propriétaire et `STOP` enregistre l'opposition.
Configurer aussi le numéro Business dans **Apparence > Personnaliser >
Validation WhatsApp > Numéro WhatsApp Business des demandes acquéreurs**.
Le bouton **Demander sur WhatsApp** d'une fiche ouvre ce numéro avec sa référence
préremplie ; l'acquéreur doit envoyer le message pour déclencher n8n. Le test
demande d'abord la qualification du nouvel acquéreur. Répondre `PARTICULIER`
ou `INTERMEDIAIRE`, puis renvoyer la référence ; WordPress décide du contact,
du refus ou de la revue manuelle selon ses limites configurées.
Les liens `localhost` du site ne sont accessibles que sur le PC.
L'URL ngrok est utilisée pour les webhooks publics ; le pont HTTPS privé des
validations WordPress reste inchangé. Un workflow WhatsApp déjà publié doit être
dépublié manuellement avant de réimporter son brouillon.

Pour vérifier une intégration dans un autre worktree, utiliser des volumes,
ports, projet Compose et image WordPress distincts. Le seul changement de nom
du projet ne suffit pas : `PK_N8N_VOLUME` est un volume externe partagé.
Ne pas lancer `setup-local.ps1` sur une pile isolée : ses noms de conteneurs et
volumes ciblent la pile locale `partikulier`. `setup-n8n.ps1` accepte `-BaseUrl` ;
`verify-local.ps1` accepte `-EnvFile`, `-SiteUrl`, `-N8nUrl`, `-MailpitUrl` et
`-ComposeProject` pour viser explicitement la pile de test.

**Blocage d'intégration sur `origin/main` (`e1fdcb8`)** : le contact automatisé
d'un propriétaire dont le numéro est chiffré retourne `owner_unavailable`.
La lecture utilise le déchiffrement réservé à `manage_options`, alors que
l'appel HMAC n'est pas une session administrateur. Ne pas contourner cette
garde ni remplacer le stockage chiffré par du texte clair. Cette intégration
ne doit pas être publiée ou déployée avant correction et rejeu du parcours.

Les contrats `core-contract`, `leads-contract` et `leads-domain-contract`
vérifient désormais la qualification explicite avant acceptation et la revue
manuelle au troisième contact en 24 heures. Le contrat d'automatisation vérifie
les cinq en-têtes signés, sans transmission du secret brut. Ces ajustements du
banc ne corrigent pas le blocage des numéros propriétaire chiffrés.

```bash
make lint
make package
```

`make lint` exécute les contrôles disponibles dans l’environnement. Le lint PHP nécessite `php` ; la QA navigateur nécessite l’installation des dépendances du thème (`cd theme/partikulier && npm ci`).

## Packaging

```bash
./scripts/package.sh dist
sha256sum dist/*.zip
```

Le script exclut les dépôts Git, caches, secrets, artefacts de test et dossiers `.github` des archives WordPress. Il produit une racine d’archive conforme aux installateurs WordPress : `partikulier-core/` et `partikulier/`.

## Installation WordPress

1. Déployer le zip du plugin depuis **Extensions → Ajouter → Téléverser** et l’activer.
2. Déployer le zip du thème depuis **Apparence → Thèmes → Ajouter → Téléverser** et l’activer.
3. Activer Estatik et, si nécessaire, Polylang.
4. Vérifier `GET /wp-json/partikulier/v1/health` et contrôler que `status` vaut `ok`.
5. Configurer les pages requises et les secrets d’automatisation uniquement via les réglages WordPress ou les variables d’environnement documentées.

Le déploiement automatisé vers Hostinger par GitHub Actions est documenté dans [`docs/DEPLOIEMENT.md`](docs/DEPLOIEMENT.md).

## Qualité et traçabilité

Le lot source a été vérifié par somme SHA-256 avant intégration. Les preuves textuelles de recette B1→B6, les journaux, contrats JSON, rapports et l’audit du monorepo sont regroupés dans [`preuves/`](preuves/), avec le [manifeste de campagne](preuves/MANIFESTE.md). Les preuves historiques restent distinctes des contrats rejouables dans `plugin/partikulier-core/tests/` et `theme/partikulier/tests/`.

La campagne historique documente **695/695 assertions PASS** sur les lots B1→B6 et **189/189 assertions PASS** sur l’audit du monorepo. Les lots C à F sont traçables dans l’historique Git et couverts par les workflows [`CI`](.github/workflows/ci.yml) et [`Contrats de recette WordPress`](.github/workflows/contrats-recette.yml). L’état courant vise **20 suites dynamiques et 237/237 assertions**, dont les contrôles de sécurité AVIF SE-013, SE-014 et SE-015. Les snapshots SQLite et captures PNG sont publiés séparément dans la [Release `preuves-b1-b6`](https://github.com/hajarbenmlih91-cloud/partikulier3/releases/tag/preuves-b1-b6), conformément à [`preuves/ARTEFACTS-BINAIRES.md`](preuves/ARTEFACTS-BINAIRES.md). Les contrôles nécessitant WordPress vivant sont explicitement séparés des contrôles statiques.

L’historique des versions est tenu dans [`CHANGELOG.md`](CHANGELOG.md) (vue projet) ; le détail par composant reste dans les journaux du [plugin](plugin/partikulier-core/CHANGELOG.md) et du [thème](theme/partikulier/CHANGELOG.md). Les parcours utilisateurs et le cahier de recette sont décrits dans [`docs/user-stories/`](docs/user-stories/README.md).

Les versions, les checksums et le détail du périmètre des lots C à F sont documentés dans [`docs/RELEASE.md`](docs/RELEASE.md) et les rapports de [`preuves/rapports/`](preuves/rapports/). Pour l’exploitation, consulter [`docs/INSTALLATION.md`](docs/INSTALLATION.md).

> **Dossier 03/10/2026 — v2.10.16 / v6.20.11** : la livraison consolidée (zips `e3a0f243` / `3bc7bee6`, Sheets A/B, n8n, HSTS/2FA, PDF sécurisation) est dans [`DOSSIER-2026-10-03-PARTIKULIER-v2.10.16-6.20.11/`](../DOSSIER-2026-10-03-PARTIKULIER-v2.10.16-6.20.11/README.md) avec son [`CHANGELOG.md`](../DOSSIER-2026-10-03-PARTIKULIER-v2.10.16-6.20.11/CHANGELOG.md) et le [`PDF-SHEETS-SECURISATION-2026-10-03.pdf`](../DOSSIER-2026-10-03-PARTIKULIER-v2.10.16-6.20.11/PDF-SHEETS-SECURISATION-2026-10-03.pdf) (pourquoi 2 Sheets, méthode de sécurisation). Voir aussi `plugin/partikulier-core/CHANGELOG.md` et `theme/partikulier/CHANGELOG.md` pour le détail dev par fichier:ligne.

## Licence

Les composants sont distribués sous GPL v3 ou ultérieure, conformément aux en-têtes WordPress présents dans les fichiers livrés.
