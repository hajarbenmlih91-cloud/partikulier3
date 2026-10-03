# Déploiement Hostinger par GitHub Actions

Ce dépôt fournit le workflow `.github/workflows/deploy.yml` pour publier la
paire WordPress `partikulier-core` + `partikulier` sur un hébergement mutualisé
Hostinger Premium/Business. Le déploiement ne synchronise que :

- `wp-content/plugins/partikulier-core/` ;
- `wp-content/themes/partikulier/`.

Il ne touche jamais `wp-config.php`, `uploads/`, ni les autres extensions ou
thèmes.

## Préparation unique côté Hostinger

1. Dans hPanel, activer l’accès SSH du domaine. Le port usuel Hostinger est
   `65002`.
2. Générer une clé dédiée au déploiement depuis une machine d’administration :

   ```bash
   ssh-keygen -t ed25519 -C "github-actions-partikulier" -f ./partikulier_deploy_ed25519
   ```

3. Ajouter le contenu de `partikulier_deploy_ed25519.pub` dans hPanel
   (clé SSH autorisée). Ne jamais déposer la clé privée sur le serveur.
4. Relever l’empreinte stricte du serveur :

   ```bash
   ssh-keyscan -p 65002 host.example.com
   ```

   Copier la ligne obtenue dans le secret GitHub `SSH_KNOWN_HOSTS`. Ne pas
   désactiver `StrictHostKeyChecking`.
5. Vérifier le chemin absolu de WordPress, par exemple :
   `/home/u123/domains/example.com/public_html`.

Hostinger fournit généralement `wp` (WP-CLI). Le workflow l’utilise pour les
sauvegardes base de données, le mode maintenance, l’activation du plugin, la
purge du cache et les rewrites.

## Environnements GitHub

Créer trois environnements GitHub : `dev`, `uat` et `prd`.

Configurer les mêmes noms de secrets dans les trois environnements, avec des
valeurs adaptées à la cible :

| Nom | Type | Exemple | Rôle |
|---|---|---|---|
| `SSH_HOST` | Secret | `host.example.com` | Hôte SSH Hostinger |
| `SSH_PORT` | Secret | `65002` | Port SSH |
| `SSH_USER` | Secret | `u123456789` | Utilisateur SSH |
| `SSH_PRIVATE_KEY` | Secret | clé privée ed25519 | Clé dédiée au déploiement |
| `SSH_KNOWN_HOSTS` | Secret | sortie `ssh-keyscan -p 65002 host` | Vérification stricte de l’hôte |
| `WP_PATH` | Secret | `/home/u123/domains/example.com/public_html` | Racine WordPress absolue |

Pour préparer ces valeurs localement, copier `deploy/secrets.env.example` vers
`deploy/secrets/<env>.env` (`dev`, `uat`, `prd` — dossier ignoré par Git), le
compléter, puis le téléverser avec `gh secret set --env <env> -f deploy/secrets/<env>.env`.
La clé privée et `SITE_URL` se téléversent séparément (commandes en tête du fichier).

Ajouter aussi une variable d’environnement GitHub, non secrète :

| Nom | Type | Exemple | Rôle |
|---|---|---|---|
| `SITE_URL` | Variable | `https://uat.example.com` | Smoke check HTTP + URL affichée dans les logs |

### Protection des environnements (Settings → Environments)

| Environnement | Required reviewers | Deployment branches and tags |
|---|---|---|
| `dev` | non | branche `develop` |
| `uat` | **oui** | tag `pre-release-*` |
| `prd` | **oui** | tag `release-*` |

Les règles GitHub utilisent des motifs *glob* (fnmatch, ancrés), pas des
regex : `release-*` ne correspond pas à `pre-release-…`. Pour être plus
strict : `pre-release-[0-9]*.[0-9]*.[0-9]*` et `release-[0-9]*.[0-9]*.[0-9]*`.
Le workflow valide en plus le tag avec les regex suivantes (job `resolve`) :

```text
uat : ^pre-release-([0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?)$
prd : ^release-([0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.]+)?)$
```

Un tag non conforme fait échouer le workflow avant toute publication. Avec
**Required reviewers**, le job `deploy` reste en attente (« Waiting for
review ») jusqu’à l’approbation, avant tout accès aux secrets. Il est
conseillé de limiter la création des tags `release-*` / `pre-release-*`
via un *ruleset* de tags (Settings → Rules → Rulesets).

## Déclenchements

- `push` sur `develop` : déploiement vers `dev` ;
- tag `pre-release-<version>` : déploiement vers `uat` (approbation requise) ;
- tag `release-<version>` : déploiement vers `prd` (approbation requise) ;
- `workflow_dispatch` : choix manuel `dev`, `uat` ou `prd` ; pour `uat`/`prd`,
  sélectionner le tag correspondant dans « Use workflow from ».

Un `push` sur `main` ne déclenche plus de déploiement.

```bash
git tag pre-release-2.10.9-6.20.8 && git push origin pre-release-2.10.9-6.20.8   # uat
git tag release-2.10.9-6.20.8     && git push origin release-2.10.9-6.20.8       # prd
```

À la fin du déploiement, l’URL du site est affichée dans les logs (annotation
`Déployé sur <env>`), dans le résumé du run et sur l’environnement GitHub.

Le job de déploiement réutilise d’abord le workflow `CI` via `workflow_call`.
La publication ne démarre que si les contrôles existants réussissent.

## Déroulé technique

1. Construction des archives avec `scripts/package.sh dist`.
2. Affichage des SHA-256 et extraction des zips dans `build/deploy-artifacts/`.
3. Connexion SSH stricte avec `SSH_KNOWN_HOSTS`.
4. Sauvegarde serveur dans
   `~/backups/partikulier/<timestamp>-<sha>/` :
   archives `tar.gz` des répertoires plugin/thème existants et dump
   `mysqldump` de la base (identifiants lus via `wp config get` ; Hostinger
   désactive `proc_open()`, requis par `wp db export`). Les cinq dernières sauvegardes
   sont conservées.
5. Activation du mode maintenance si WP-CLI est disponible.
6. `rsync -az --delete` du plugin et du thème uniquement.
7. `wp plugin activate partikulier-core`, `wp cache flush`, puis
   `wp rewrite flush`.
8. Désactivation du mode maintenance, même en cas d’échec.
9. Smoke checks : HTTP 200 sur `SITE_URL`, puis
   `/wp-json/partikulier/v1/health` avec `status = ok`.

La concurrence est sérialisée par environnement, sans annuler un déploiement
déjà en cours.

## Rollback depuis une sauvegarde

Se connecter en SSH puis choisir la sauvegarde à restaurer :

```bash
cd ~/backups/partikulier
ls -1
```

Depuis la racine WordPress :

```bash
WP_PATH=/home/u123/domains/example.com/public_html
BACKUP=$HOME/backups/partikulier/20260101T120000Z-abcdef123456

wp maintenance-mode activate
rm -rf "$WP_PATH/wp-content/plugins/partikulier-core" "$WP_PATH/wp-content/themes/partikulier"
tar -xzf "$BACKUP/partikulier-core.tar.gz" -C "$WP_PATH/wp-content/plugins"
tar -xzf "$BACKUP/partikulier.tar.gz" -C "$WP_PATH/wp-content/themes"

# À n’utiliser que si le rollback applicatif exige aussi le retour base.
# (`wp db import` est inutilisable sur Hostinger : proc_open() désactivé.)
cd "$WP_PATH"
gunzip -c "$BACKUP/database.sql.gz" | MYSQL_PWD="$(wp config get DB_PASSWORD)" \
  mysql -h "$(wp config get DB_HOST)" -u "$(wp config get DB_USER)" "$(wp config get DB_NAME)"

wp plugin activate partikulier-core
wp cache flush
wp rewrite flush
wp maintenance-mode deactivate
```

Vérifier ensuite la page publique et la route :

```bash
curl -fsS https://example.com/wp-json/partikulier/v1/health
```
