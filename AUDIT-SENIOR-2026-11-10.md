# Audit technique senior — `hajarbenmlih91-cloud/partikulier3`

**Date :** 10 novembre 2026
**Périmètre :** dépôt complet (branche `main` @ `0682f64`), historique Git, workflows, artefacts, code runtime
**Méthode :** clone complet (426 commits), analyse statique manuelle (pas de PHP dans l'environnement d'audit → pas de lint dynamique), lecture des workflows et de l'API GitHub, exécution des scripts de garde du dépôt
**Auditeur :** revue de code niveau senior (WordPress / PHP 8.x / CI-CD / sécurité applicative)

---

## 1. Réponse directe aux deux questions

### « Tu as accès à mon repo ? »

Oui. Dépôt `hajarbenmlih91-cloud/partikulier3`, **public**, 25 Mo, créé le 10/09/2026, dernier push le 10/11/2026 à 09:46 UTC.

| Élément | Constat |
|---|---|
| Visibilité | **Public** (`isPrivate: false`) — point important, voir P0-1 |
| Branche par défaut | `main`, **protégée** |
| Commits | **426** (280 en first-parent), du 10/09/2026 au 08/11/2026 |
| Fichiers suivis | **667**, dont 255 fichiers PHP / **62 011 lignes** de PHP |
| Pull requests | **217 fusionnées**, 1 fermée, **0 revue** (voir P1-4) |
| Releases / tags | 7 releases, 25 tags (`pre-release-2.10.16-6.20.213` … `.214`) |
| Workflows | **8** (`CI`, `Contrats de recette`, `Déploiement Hostinger`, `Audit UAT`, `n8n`, `PHP version`, 2 essais) |
| Secrets / SAST | non lisibles via mon jeton (403), mais **aucun SAST, aucun Dependabot, aucun `composer.json`** dans le dépôt |

### « J'ai accès à toutes les branches ou juste `main` ? »

**À toutes.** J'ai listé l'intégralité des branches distantes via l'API : **222 branches** au total (dont `main`).

Et c'est en soi un constat d'audit :

- **101 branches** sont fusionnées dans `main` mais **jamais supprimées** ;
- **122 branches ne sont PAS fusionnées** dans `main` ;
- parmi elles, **5 branches portent du travail substantiel non intégré** :

| Branche | Commits d'avance sur `main` | Dernier commit |
|---|---:|---|
| `arena/0e605e79-partikulier3` | **178** | **10/11/2026** (aujourd'hui) |
| `essai/page-ouverte-whatsapp` | 116 | 04/10/2026 |
| `audit-uat-validation` | 115 | 04/10/2026 |
| `feat/profil-acheteur` | 114 | 04/10/2026 |
| `audit-workflow-fix` | 114 | 04/10/2026 |
| `fix/2.10.16-ci` | 13 | 04/10/2026 |
| `n8n-setup` | 12 | 04/10/2026 |

`arena/0e605e79-partikulier3` contient **le travail le plus récent du projet** (9–10 novembre, correctifs tunnel WhatsApp, profil, workflow) qui **n'existe nulle part dans `main`**. Autrement dit : la branche « officielle » n'est pas la plus avancée. C'est le premier risque de perte de travail du dépôt.

---

## 2. Verdict global

> **Travail sérieux, très au-dessus du freelance WordPress moyen sur la traçabilité et l'outillage — mais pas au niveau « senior » sur la rigueur sécurité et la maîtrise de la dette. Deux failles critiques sont à corriger cette semaine, et l'appareil de test, qui est impressionnant en volume, ne protège pas autant qu'il le prétend.**

### Note : **11,5 / 20** — « bon artisan, pas encore senior »

| Axe | Note | Commentaire court |
|---|---:|---|
| Sécurité applicative | **7 / 20** | Bonnes pratiques réelles (prepared statements, nonces, capacités, chiffrement des téléphones) **mais** une clé d'admin par défaut publiée et un secret TOTP envoyé à un tiers |
| Architecture / découpage | **12 / 20** | Plugin propre (namespaces, `strict_types`, domaines) ; thème legacy monolithique, duplication plugin↔thème, aucun autoloader |
| Qualité & lisibilité du code | **10 / 20** | Commentaires exemplaires ; un fichier de 2 656 lignes avec une fonction de 76 000 caractères, 0 namespace côté thème |
| Tests | **11 / 20** | Volume rare (40+ suites, assertions épinglées) mais harnais maison, pas de PHPUnit, oracle instable, suites rouges fusionnées |
| CI/CD & outillage | **11 / 20** | Pipelines riches, actions épinglées en SHA, zips reproductibles, SBOM — **mais** des garde-fous factices et 50 % de runs rouges |
| Documentation | **15 / 20** | Densité et traçabilité remarquables ; dérive de versions et affirmations contredites par le code |
| Git / process | **8 / 20** | 222 branches, 0 revue, commits dupliqués, 3 identités Git, travail récent hors `main` |
| i18n / SEO / perf | **13 / 20** | Vrai travail (trilingue, JSON-LD, AVIF, cache) ; ~25 % des chaînes absentes des catalogues AR/EN |
| Exploitation / déploiement | **10 / 20** | Déploiement automatisé avec sauvegarde et mode maintenance, mais `rsync --delete` direct en prod, pas de rollback automatique |

---

## 3. Ce qui est réellement bien fait (à conserver)

Ce ne sont pas des évidences, et beaucoup de prestataires ne les font pas :

1. **Traçabilité contractuelle.** 40+ suites de « contrats » avec `test_id`, totaux **épinglés** (l'oracle refuse un `total` inattendu, un `test_id` dupliqué ou un `FAIL` caché sous un `status: PASS` — `.github/workflows/contrats-recette.yml:476-591`). C'est une idée de senior.
2. **Chaîne d'approvisionnement.** Actions GitHub épinglées par SHA **valides** (`actions/checkout@3d3c42e5…` = v7.0.1, `shivammathur/setup-php@eb7c497e…` = 2.40.0, vérifiés un par un), `permissions: contents: read`, `concurrency`, `timeout-minutes`.
3. **Artefacts reproductibles.** `scripts/package.sh` normalise les mtimes (`touch -d 1980-01-01`), `zip -X`, `SHA256SUMS`, SBOM CycloneDX, et refuse les entrées fantômes du manifeste.
4. **Hygiène d'artefact.** La CI rejette `tests/`, `*.sh`, `.git/`, `node_modules/` dans les zips livrés (`ci.yml:36-58`) — un classique que 90 % des projets WordPress ratent.
5. **Sécurité de base bien tenue.** `$wpdb->prepare` (106 occurrences), 1 071 appels `esc_*`/`kses`, 30 vérifications de nonce, 63 `current_user_can`, 38 `permission_callback`, aucun `echo $_GET/$_POST` brut, XML-RPC désactivé, énumération d'utilisateurs bloquée, en-têtes CSP/HSTS/`X-Frame-Options`.
6. **Chiffrement au repos.** Téléphones propriétaires en AES-256-GCM (`class-crypto.php`, `LeadsPrivacyTrait.php`), pseudonymisation HMAC des visiteurs.
7. **Exploitation.** Endpoint `/wp-json/partikulier/v1/health` (503 si dégradé, sans détail pour les anonymes), audit log corrélé, migrations `dbDelta` versionnées, sauvegarde BDD avant déploiement, mode maintenance automatique.
8. **Aucun secret réel dans l'historique.** J'ai scanné tous les blobs de toutes les branches : seuls des `.env.example` (valeurs `change-me`), aucun `.pem`, aucune clé, aucun `wp-config.php`. C'est propre.
9. **Documentation.** `docs/` (user stories, cahier de tests 47 cas, diagrammes, OpenAPI), `CHANGELOG` par composant — rare et utile.

---

## 4. Failles critiques (P0) — à traiter immédiatement

### P0-1 · Clé d'administration par défaut **publiquement documentée**, dans un dépôt **public**

`theme/partikulier/inc/class-security.php:21`

```php
public const DEFAULT_ADMIN_KEY = 'direction2026';
```

`get_admin_secret_key()` (`:52-59`) retourne cette constante **si `PK_ADMIN_SECRET_KEY` n'est pas définie** et si l'option `pk_admin_secret_key` est vide. Or la clé est **écrite en clair** dans :

- `theme/partikulier/README.md:21`, `:33`, `:38`
- `theme/partikulier/CHANGELOG.md:33`
- `DOSSIER-2026-10-03-…/README.md:158`

et le dépôt est **public**. Conséquences :

1. N'importe qui peut ouvrir la porte dérobée `?pk_admin_key=direction2026` si la prod n'a pas surchargé la constante — cette passerelle est présentée comme la protection anti-scanners de `wp-login.php` ; elle ne protège plus rien.
2. **Contradiction documentaire** : `CHANGELOG.md:46` affirme « *Optional admin gateway has no built-in access key* ». Le code dit l'inverse.

**Aggravant au même endroit** (`class-security.php:85-89`) : la liste blanche SSO Hostinger sort de la fonction sur **n'importe quelle** requête contenant `?token=`, `?hpanel=`, `?sso_token=`… Un simple `wp-login.php?token=1` désactive le masquage. La liste blanche est trop large (n'importe quel `token` non vide).

**Correctif (30 min) :**
```php
// 1. supprimer la constante et rendre la clé obligatoire
public static function get_admin_secret_key(): string {
    $k = defined('PK_ADMIN_SECRET_KEY') ? (string) PK_ADMIN_SECRET_KEY : (string) get_option('pk_admin_secret_key', '');
    return $k;                                   // '' => passerelle inactive, jamais de clé par défaut
}
// 2. resserrer la liste blanche SSO : exiger la présence conjointe d'un paramètre
//    spécifique Hostinger (ex. hpanel=1) ET d'un referer *.hostinger.com, et
//    documenter la exception dans une option désactivée par défaut.
```
Puis : `wp option update pk_admin_secret_key "$(openssl rand -hex 24)"`, purge du cache, et **réécriture de l'historique public** (ou rotation) — la clé était publique, elle est brûlée.

---

### P0-2 · Le secret TOTP 2FA part chez un tiers (`api.qrserver.com`)

`plugin/partikulier-core/mu-plugins/partikulier-2fa-light.php:38` puis `:51`

```php
$qr_url = "https://api.qrserver.com/v1/create-qr-code/?size=200x200&data="
        . rawurlencode("otpauth://totp/{$issuer}:{$label}?secret={$secret}&issuer={$issuer}");
…
<img src="<?php echo esc_url($qr_url); ?>" …>
```

Chaque affichage du profil admin **envoie la graine TOTP de l'administrateur dans l'URL d'un service tiers**, en clair, et la laisse dans les logs de ce tiers. Un TOTP volé = 2FA définitivement contournée.

**Et la documentation affirme le contraire**, deux fois :
- `docs/INSTALLATION.md:33` : « *Aucun secret n'est envoyé à un prestataire QR* »
- `CHANGELOG.md:46` : « *2FA setup never sends seeds to an external QR provider* »

Ce n'est plus un bug, c'est un **écart documentaire sur un contrôle de sécurité** — exactement ce qu'un auditeur considère comme rédhibitoire.

**Correctif :** rendre le QR **local** (lib PHP sans réseau, ex. `bacon/bacon-qr-code`, ou afficher uniquement la clé base32 + `otpauth://` en texte). C'est 20 lignes. À défaut, retirer la fonction QR et corriger la doc.

---

### P0-3 · Le dépôt **public** expose l'infrastructure et le contexte client

Le dépôt est public alors qu'il contient :

- l'hôte UAT réel (`darkcyan-yak-151002.hostingersite.com`) dans `audit-uat.yml`, avec le **chemin WordPress absolu** (`WP_PATH`) et la topologie SSH (`.github/workflows/audit-uat.yml`, `deploy.yml`) ;
- les procédures internes (`docs/DEPLOIEMENT.md`, `docs/SECURITY-AUDIT-2026-09-30.html`, `DOSSIER-2026-10-03-…/`) ;
- des données de recette (hashes visiteurs, fixtures) dans `preuves/`.

Rien de « secret » au sens credential, mais c'est de la **reconnaissance gratuite** pour un attaquant, et un sujet contractuel avec le client.

**Correctif :** passer le dépôt en privé (ou scinder : dépôt public « produit » + dépôt privé « exploitation »), et purger les hôtes/chemins des docs publiques.

---

### P0-4 · Les garde-fous de qualité sont **factices**

C'est le point le plus dommageable pour la crédibilité de l'ensemble, parce qu'il donne une fausse assurance.

| Garde-fou annoncé | Réalité |
|---|---|
| `scripts/check-hardcoded.sh` (« Senior gate : détecte les chaînes dures hors `__()` ») | Le chemin analysé est **`plugin/theme`**, qui **n'existe pas** (les vrais chemins sont `plugin/partikulier-core` et `theme/partikulier`). `grep` échoue, l'erreur est redirigée vers `/dev/null`, `$bad` est vide → **PASS systématique**. Vérifié : `bash scripts/check-hardcoded.sh` → `Hardcoded strings: PASS (0 hors __())`, `exit=0`, alors que des dizaines de chaînes FR en dur existent (voir P2-2). |
| `scripts/check-complexity.sh` (« Fail si >15 ») | Ne fait **jamais** `exit 1` ; sort en `0` même si `lizard` est absent (« `lizard manquant … exit 0` ») ; **jamais appelé** par `lint.sh` ni par la CI. |
| `phpcs.xml.dist` (standard WordPress complet, 80 lignes de doctrine) | **Jamais exécuté** : aucun `composer.json`, aucun `vendor/`, aucune étape `phpcs` dans les 8 workflows. Le fichier est de la documentation, pas un contrôle. |
| Analyse statique | **Aucun** PHPStan/Psalm/ESLint/Stylelint, aucun `.editorconfig`, aucun Dependabot. |
| `PK_VERSION` de la CI | Figé à **`2.10.10`** (`contrats-recette.yml:10,463`) alors que le plugin est en `2.10.16`. |

**Correctif :** réparer les chemins, faire échouer réellement, et brancher `phpcs` + PHPStan niveau 5 minimum dans `ci.yml` (`shivammathur/setup-php` + `composer global require`). Un gate qui ne peut pas échouer est pire que pas de gate.

---

## 5. Constats majeurs (P1)

### P1-1 · Le workflow de test le plus important est rouge **une fois sur deux**, et on fusionne quand même

- Sur `main` : **50 runs verts / 49 rouges** pour `Contrats de recette (WordPress)`.
- Les 12 dernières PR fusionnées l'ont toutes été **avec 1 check en échec**, systématiquement le même : `contrats WordPress / PHP 8.3`.
- Étapes en échec identifiées : « Exécuter les 32 suites dynamiques » et « Contrats de fusion et navigateurs FR/EN/AR ».

Donc soit ce check **n'est pas requis** par la protection de branche, soit il est **contourné** (`--admin`). Dans les deux cas, la valeur de l'oracle 454/454 est nulle en pratique : il ne bloque rien.

**Correctif :** rendre `CI` **et** `contrats` obligatoires sur `main`, `enforce_admins: true`, `require linear history`, et **stabiliser** les deux étapes flaky avant de réactiver le merge.

### P1-2 · Dérive de version généralisée (le « numéro de version » ne veut plus rien dire)

| Source | Version annoncée |
|---|---|
| `plugin/partikulier-core/partikulier-core.php` | **2.10.16** |
| `theme/partikulier/style.css`, `functions.php` | **6.20.11** |
| `theme/partikulier/CHANGELOG.md` | 6.20.11 |
| `theme/partikulier/package.json` | **6.20.9** |
| `theme/partikulier/readme.txt` (`Stable tag`) | **6.20.9** |
| `plugin/partikulier-core/README.md` | **2.10.9** |
| `theme/partikulier/DOC.md` | **6.19.1** |
| CI (`PK_VERSION`) | **2.10.10** |
| README racine | « 20 suites / **237/237** assertions » |
| CI (oracle) | « **454/454** — 44 suites » ; le nom de l'étape dit « **32** suites » |
| `LICENSE` (thème **et** plugin) | texte **GPL v2** |
| En-têtes `style.css` / `readme.txt` / README | **GPL v3 ou ultérieure** |

Un audit de conformité qui s'appuie sur ces numéros ne peut pas conclure. **Correctif :** une seule source de vérité (`VERSION` racine lue par `package.sh`, la CI et les en-têtes), et un test qui échoue si les 6 fichiers divergent.

### P1-3 · Le travail le plus récent n'est pas dans `main`

`arena/0e605e79-partikulier3` : **178 commits** absents de `main`, dont **5 commits du 10/11/2026**. Aucune PR ouverte ne les vise. Si cette branche est supprimée (nettoyage « 222 branches »), **le travail est perdu**.

**Correctif :** ouvrir une PR de cette branche vers `main`, ou la taguer `archive/` avant tout nettoyage.

### P1-4 · Zéro revue, zéro issue, zéro garde-fou humain

217 PR fusionnées, **0 revue**, **0 commentaire**. 1 seule issue ouverte (`#17`, *« Encrypted owner phones break HMAC-authenticated n8n contact authorization »*, ouverte le 04/10 et toujours ouverte **5 semaines plus tard** — c'est précisément la zone la plus critique du système, le pont n8n/leads).

Pour un développeur solo, l'auto-fusion est normale. Le problème n'est pas là : c'est qu'**aucune barrière ne subsiste** entre une erreur et la production. Recommandation : PR obligatoire + 1 check vert requis + un second humain (ou un agent de revue) sur les diffs touchant `class-security.php`, `class-crypto.php`, `Leads*` et `.github/workflows/deploy.yml`.

### P1-5 · Duplication plugin ↔ thème (le thème garde des copies « de repli »)

Le découpage a été fait à moitié : les domaines ont été **copiés** dans le plugin, pas **retirés** du thème.

| Fichier plugin | Fichier thème (copie de repli) | Lignes |
|---|---|---:|
| `src/Domain/I18n/ListingLexicon.php` | `inc/class-listing-i18n-lexicon.php` | 248 / 249 |
| `src/Domain/I18n/ArabicPlacesDictionary.php` | `inc/class-listing-arabic-places.php` | 143 / 139 |
| `src/Domain/Leads/LeadsPrivacyTrait.php` | `inc/class-crypto.php` | 248 / 121 |
| `src/Domain/I18n/ChromeDictionary.php` (+ `FormsDictionary`) | `inc/class-localization-strings.php`, `-forms.php` | 136+140 chaînes |

Chaque évolution doit être faite **deux fois**, et rien ne vérifie que les deux chemins restent d'accord (`module-perimeter-contract.php` contrôle le périmètre, pas l'égalité). C'est la source de bug n°1 des 40 prochains commits — et ça se voit déjà dans l'historique : `se024-i18n-dedup-contract.php`, `vestige-extinction-contract.php`, `fix/de4141-vers-a13ff4`, `fix/merged-2.10.16-onto-main`…

---

## 6. Constats moyens (P2)

### P2-1 · Monolithe de diagnostic embarqué en production

`theme/partikulier/pk-diagnostic.php` : **2 656 lignes**, une fonction `partikulier_pk_diag_run()` de **76 192 caractères** (~2 000 lignes), un `// phpcs:ignoreFile` en tête, inclus inconditionnellement par `functions.php:374`, et **livré dans le zip de production** (`package.sh` n'exclut que `tests/`, `*.sh`, `.git`, `node_modules`).

Le fichier est bien gardé (`ABSPATH`, `manage_options` + nonce, jeton aléatoire en CLI) — mais c'est un outil de développeur, non testé, non analysé statiquement, qui pèse dans chaque installation. **Correctif :** sortir dans un plugin d'outillage, ou derrière un `define('PK_DIAGNOSTIC', true)`, et exclure du zip.

Autres gros fichiers : `class-search-filters.php` (1 301 l.), `class-form.php` (1 048 l.), `templates/parts/helpers.php` (908 l.), `class-cache.php` (778 l.).

### P2-2 · i18n annoncée complète, réellement à ~75 %

| Catalogue | msgid | msgid du `.pot` **absents** |
|---|---:|---:|
| `ar.po` | 638 | **171** (25 %) |
| `en_US.po` | 587 | **175** (26 %) |

Et 135 chaînes de `ar.po` ne correspondent plus à aucune entrée du `.pot` (catalogue stale). S'y ajoutent des chaînes **en dur**, jamais traduites :

- `templates/journal.php:29` (`Journal`), `:47` (`En ce moment`)
- `templates/price-index.php:25` (`Prix au mètre carré`), `:34-35` (`Quartier`, `Transaction`)
- `templates/footer.php:106-134` (porte de langue, FR/AR/EN en dur — acceptable ici, c'est le sélecteur)

Or le produit vend « trois langues simultanément » (`DOC.md`). **Correctif :** `wp i18n make-pot` + `msgmerge` en CI, échec si `msgstr` vide > 2 %, et un vrai lint « chaîne en dur » (cf. P0-4).

### P2-3 · Déploiement : `rsync --delete` direct sur la production, sans rollback automatique

`.github/workflows/deploy.yml` : backup (files + `mysqldump`) → maintenance → `rsync -az --delete` **directement dans `wp-content` de prod** → `wp` CLI → smoke tests → nettoyage.

Ce qui manque : **release atomique** (déployer dans `releases/<sha>` puis basculer un symlink) et **rollback automatique** si les smoke tests échouent. Aujourd'hui, un smoke test rouge laisse la prod dans l'état déployé, avec la maintenance désactivée. Les sauvegardes limitent les dégâts, mais la restauration est **manuelle**.

### P2-4 · Pas d'autoloader, chargement par 60 `require_once` ordonnés

`plugin/partikulier-core/partikulier-core.php` : **60 `require_once`** dont l'ordre est **significatif** (les traits doivent précéder les classes qui les utilisent — c'est écrit en commentaire). Aucun `composer.json`, aucun PSR-4, aucun `spl_autoload_register`.

Conséquence : toute nouvelle classe = 2 endroits à modifier, et un oubli produit une erreur fatale silencieuse en prod (pas en dev, si l'ordre diffère). **Correctif :** `composer.json` avec `"psr-4": {"Partikulier\\Core\\": "src/"}` + un `autoload.php` (sans dépendances, juste l'autoloader Composer généré).

### P2-5 · CSP affaiblie par `'unsafe-inline'`

`class-security.php:318` : `script-src 'self' 'unsafe-inline' https://static.addtoany.com`.

Avec `'unsafe-inline'`, la CSP ne protège plus contre l'injection de script (XSS) — elle ne garde que `object-src`, `base-uri`, `frame-ancestors`. Sur un site qui manipule des annonces déposées par des tiers, c'est la protection qui compte le plus. **Correctif :** nonces (`wp_script_add_data`/`wp_add_inline_script` avec nonce) et suppression de `unsafe-inline`.

### P2-6 · Hygiène Git

- **222 branches** dont 101 déjà fusionnées, jamais supprimées → impossible de distinguer le travail vivant du bruit.
- **Commits dupliqués** sur `main` : ex. `Attendre le second message réel du tunnel` **et** `…tunnel.` (rebases/amends empilés), idem `fix(i18n & perimeter)…` ×2, `fix(tests): recalibrer se048…` ×2.
- **3 identités Git** : `hajarbenmlih91-cloud` (411), `Hajar Benmlih` (284), **`Z User` (26)** — plus `Arena`, `Partikulier Security Team`, `partikulier-ci`, `Partikulier Integrator`. Un `git config user.name` correct + `.mailmap` réglerait ça.
- Messages de commits majoritairement bons (impératif, français, portée claire) : à conserver.

### P2-7 · Binaires dans Git

`docs/diagrams/*.png` : 4 fichiers de **0,9 à 1,2 Mo** ; `theme/partikulier/apercu-tunnel.html` : **737 Ko** avec **au moins 6 révisions** dans l'historique ; `theme/partikulier/demo/` 2,9 Mo. Résultat : `.git` = **26 Mo** pour 667 fichiers de code. Pas de Git LFS, pas d'`.gitattributes` pour les binaires. Un clone restera lent et l'historique restera lourd. **Correctif :** `docs/diagrams` en SVG (×50 plus léger), ou LFS.

---

## 7. Constats mineurs (P3)

- `theme/partikulier/readme.txt` : `Tested up to: 7.1` — WordPress 7.1 n'existe pas (au 10/11/2026, la branche courante est 6.x). Déclaration invérifiable en production.
- `theme/partikulier/style.css` : la description du thème fait 1 200 caractères en une seule ligne (limite pratique de l'écran d'admin) et décrit encore « design rouge **#A13FF4** » (un violet) alors que la charte actuelle est `#c8242b`.
- `scripts/check-catalogues.sh` et `ci.yml` dupliquent le même contrôle (i18n) avec deux formulations : deux endroits à maintenir.
- `theme/partikulier/CHANGELOG.md` et `plugin/.../CHANGELOG.md` citent des **`fichier:ligne`** (`inc/class-form.php:557`, `crypto.php`) : excellent pour l'audit, mais ces numéros périment au commit suivant. Un lien vers le commit SHA serait durable.
- `docs/` : 12 documents, mais **aucun `CONTRIBUTING.md`, `SECURITY.md` ni `CODEOWNERS`**.
- Le dépôt n'a **aucune licence à la racine** (`licenseInfo: null` côté API), alors que les composants sont GPL — un `LICENSE` racine + la mention dans le README éviteraient l'ambiguïté juridique.
- 26 commits par une identité `Z User` (probable machine mal configurée) : sans impact fonctionnel, mais nuit à la traçabilité des responsabilités.

---

## 8. Lecture du travail : « est-ce que mon dev a fait du bon travail ? »

**Oui, sur le fond — non, sur la finition et sur la sécurité.**

Ce qui distingue ce dépôt de la production habituelle d'un freelance WordPress :

- il y a une **doctrine** (contrats, lots, critères de sortie, preuves versionnées, numérotation `SE-0xx` des constats d'audit) ;
- il y a une **chaîne CI/CD réelle**, pas un `git push` en FTP ;
- il y a un **souci de sécurité authentique** (chiffrement des données personnelles, rate limiting CGNAT, anti-énumération, audit log) ;
- les **arbitrages sont documentés** (ex. `phpcs.xml.dist` explique pourquoi `NonceVerification.Recommended` est exclu, avec le fichier de tri des 53 occurrences — c'est un raisonnement de senior).

Ce qui l'empêche d'être « senior » :

1. **La sécurité est appliquée par endroits, pas partout.** Deux secrets critiques (clé d'admin, graine TOTP) sont exposés, dont un dans un dépôt **public**, et la documentation affirme l'inverse de ce que fait le code. Un senior ne publie pas une clé par défaut, et ne laisse jamais un `CHANGELOG` mentir sur un contrôle de sécurité.
2. **Les garde-fous sont décoratifs.** `check-hardcoded.sh` et `check-complexity.sh` ne peuvent pas échouer ; `phpcs.xml.dist` n'est jamais lancé ; aucune analyse statique. C'est le symptôme classique : on ajoute le rituel (« senior gate ») sans vérifier qu'il mord.
3. **Le volume de tests masque leur fragilité.** 454 assertions épinglées, mais un workflow rouge 50 % du temps et des fusions par-dessus. Un test qui ne bloque pas n'est pas un test, c'est un rapport.
4. **Le découpage est à mi-chemin.** Le plugin est propre, le thème reste un monolithe de 62 classes statiques sans namespace, et les domaines « migrés » existent en double des deux côtés. La dette est identifiée (lots B1→B6, SE-004…) mais pas soldée.
5. **La discipline Git n'est pas tenue.** 122 branches non fusionnées, du travail récent hors `main`, des commits dupliqués : à trois, ce dépôt devient ingérable.

**Traduction en risque business :** le produit fonctionne et l'exploitation est outillée ; en revanche, le jour où un scanner tombe sur `?pk_admin_key=direction2026` (documenté dans un dépôt public et indexé) ou sur le QR 2FA, l'incident sera **entièrement à votre charge**, et la documentation fournie au client constituera la preuve que le risque était connu.

---

## 9. Plan d'action

### Cette semaine (bloquant, ~1 jour)

| # | Action | Fichier |
|---|---|---|
| 1 | Supprimer `DEFAULT_ADMIN_KEY`, rendre `PK_ADMIN_SECRET_KEY` obligatoire, **rotationner** la clé en prod et en UAT | `theme/partikulier/inc/class-security.php:21,52-59` |
| 2 | Retirer la clé des 4 documents (README, CHANGELOG ×2, DOSSIER) et **purger l'historique public** ou passer le dépôt en privé | `theme/partikulier/README.md`, `CHANGELOG.md`, `DOSSIER-…/README.md` |
| 3 | Supprimer l'appel `api.qrserver.com` (QR local ou clé en texte) + corriger la doc | `plugin/.../mu-plugins/partikulier-2fa-light.php:38,51`, `docs/INSTALLATION.md:33`, `CHANGELOG.md:46` |
| 4 | Resserrer la liste blanche SSO (`?token=` seul ne doit plus suffire) | `class-security.php:85-89` |
| 5 | Réparer ou supprimer `check-hardcoded.sh` / `check-complexity.sh` ; brancher **phpcs + PHPStan 5** dans `ci.yml` | `scripts/`, `.github/workflows/ci.yml` |
| 6 | Rendre `CI` **et** `Contrats de recette` **requis** sur `main` (`enforce_admins: true`) | Réglages GitHub |

### Sous 30 jours

7. **Réconcilier les versions** (une source unique ; test CI qui échoue si `style.css` ≠ `package.json` ≠ `readme.txt` ≠ `functions.php` ≠ `PK_VERSION`).
8. **Sauver `arena/0e605e79-partikulier3`** : PR vers `main` ou tag d'archive, puis supprimer les **101 branches déjà fusionnées** ; figer une convention (`feat/`, `fix/`, `chore/` + suppression auto à la fusion).
9. **Stabiliser les 2 étapes flaky** (« 32 suites dynamiques », « fusion et navigateurs ») ; un run rouge doit être un incident, pas une routine.
10. **Traiter l'issue #17** (téléphones chiffrés vs autorisation HMAC n8n) — c'est un bug fonctionnel ouvert sur le chemin critique.
11. **i18n** : `wp i18n make-pot` + `msgmerge` en CI, échec si > 2 % de `msgstr` vides ; compléter les 171/175 chaînes manquantes.

### Sous 90 jours

12. **Autoload PSR-4** (`composer.json` + `spl_autoload_register`) et suppression des 60 `require_once` ordonnés.
13. **Sortir `pk-diagnostic.php`** du thème livré (plugin d'outillage ou flag) ; découper `partikulier_pk_diag_run()`.
14. **Dédupliquer** plugin ↔ thème : une seule implémentation du lexique, du dictionnaire de lieux et de la crypto ; un contrat qui compare les deux tant que la transition dure.
15. **Déploiement atomique** (`releases/<sha>` + symlink) avec rollback automatique sur smoke test rouge.
16. **CSP à nonces** (suppression de `unsafe-inline`), et `SRI` sur les ressources externes restantes.
17. **Nettoyer l'historique lourd** (SVG au lieu de PNG, LFS) ; `CONTRIBUTING.md`, `SECURITY.md`, `CODEOWNERS`, `LICENSE` racine.

---

## 10. Annexe — métriques et méthodologie

**Volumétrie**

| Mesure | Valeur |
|---|---:|
| Fichiers suivis | 667 |
| Fichiers PHP | 255 |
| Lignes de PHP | 62 011 |
| Fichier PHP le plus long | `pk-diagnostic.php` — 2 656 l. |
| Fonction la plus longue | `partikulier_pk_diag_run()` — 76 192 caractères |
| Fonctions (approx.) | 215 |
| Classes du thème | 58 (0 namespace, 0 `strict_types`) |
| Classes du plugin | ~60 (namespaces + `strict_types`) |
| `require_once` au bootstrap plugin | 60 |
| `$wpdb` (query/get_*) | 153 |
| `$wpdb->prepare` | 106 |
| `esc_*` / `wp_kses` | 1 071 |
| `sanitize_*` | 348 |
| `current_user_can` | 63 |
| `permission_callback` | 38 |
| Nonces vérifiés | 30 |
| TODO/FIXME | 2 |
| Suites de tests (.php) | 43 plugin + 40 thème |
| Workflows | 8 |

**Méthode et limites**

- Clone complet (`/tmp/audit`) et lecture exhaustive de l'historique (426 commits, toutes branches).
- **PHP n'est pas installé dans l'environnement d'audit** : `php -l` n'a pas pu être exécuté sur les 255 fichiers, et aucune suite n'a été rejouée. Les constats de code sont issus de la lecture, pas de l'exécution. Les affirmations « cela passe/échoue » portent uniquement sur ce que j'ai pu exécuter : `scripts/check-hardcoded.sh` (vérifié, `exit 0` sur un dépôt qui contient des chaînes en dur), l'API GitHub, et les scripts shell.
- Les protections de branche, les secrets et les alertes de sécurité du dépôt **ne sont pas lisibles avec mon jeton** (HTTP 403). Les conclusions sur les checks requis sont donc déduites de l'état des PR au moment de la fusion, pas lues dans la configuration.
- Aucun test offensif n'a été mené contre le site en production ou en UAT (pas d'essai de `?pk_admin_key=direction2026` sur le site live). Le constat P0-1 découle de la **lecture du code** et de la **publication de la clé**, pas d'une exploitation.

---

*Rapport généré le 10/11/2026 — branche `arena/f15ac349-partikulier3`.*
