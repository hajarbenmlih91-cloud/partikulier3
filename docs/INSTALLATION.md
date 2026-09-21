# Installation et exploitation

## Déploiement

Construire les archives depuis la racine du dépôt :

```bash
./scripts/package.sh dist
```

Installer `dist/B-INSTALLER-PLUGIN-partikulier-core-2.8.0.zip` dans WordPress, puis installer `dist/partikulier-theme-6.18.9.zip`. Le plugin doit être activé avant le thème afin que les services métier soient disponibles dès le premier chargement.

## Vérifications post-déploiement

Après activation, vérifier la route de santé :

```bash
curl -fsS https://example.test/wp-json/partikulier/v1/health
```

La réponse attendue est un JSON dont `status` vaut `ok`. Contrôler également le front en français, anglais et arabe, le sens RTL, la recherche géographique, le dépôt d’annonce, les favoris et la génération JSON-LD.

## Configuration Polylang de référence (SE-025, E-2503)

Le front trilingue FR/EN/AR est qualifié avec la configuration suivante — c’est
exactement celle que le workflow « Contrats de recette (WordPress) » installe
et teste en CI (SE-025, E-2501→E-2504) :

| Réglage | Valeur de référence |
|---|---|
| Extension | Polylang **3.8.7** (wordpress.org — version épinglée en CI) |
| Langues | `fr` (locale `fr_FR`, défaut, drapeau fr) · `en` (locale `en_US`, drapeau us) · `ar` (locale `ar`, **RTL**, drapeau ma) |
| URL des langues | `force_lang = 1` — préfixe répertoire : `/fr/`, `/en/`, `/ar/` |
| Langue par défaut | `fr` — créée en premier (la première langue devient le défaut) et `default_lang = fr` |
| Préfixe du défaut | `hide_default = 0` — le français garde son `/fr/`, jamais de racine ambiguë |
| Détection navigateur | `browser = 0`, `redirect_lang = 0` |
| Contenus traduits | `post_types` : `post`, `page`, `properties` ; `taxonomies` : `es_type`, `es_status`, `es_location`, `es_category` |
| Contenu préexistant | assigné en masse à `fr` (`PLL()->model->set_language_in_mass`) — sinon `/fr/annonces/` serait vide |

Chemin CLI de référence (celui de la CI) : créer les trois langues par
`PLL()->model->add_language()` dans un contexte WP-CLI (`wp eval-file`),
puis poser les réglages par `PLL()->options->merge( array( … ) )` —
l’API des écrans Réglages. Ne JAMAIS passer par `update_option( 'polylang' )`
direct : Polylang 3.7+ réécrit l’option au `shutdown` en fusionnant base ×
mémoire (`Options::save_all()`), ce qui écraserait la valeur au sortir du
processus. Puis — dans un **second processus**, afin que Polylang amorce
ses types traduits à jour — appeler `PLL()->model->set_language_in_mass()`
et purger les rewrites (`wp rewrite flush`).

Chemin HTTP de repli documenté : si la création CLI des langues est
impossible sur votre hôte, créer les trois langues dans
Réglages → Langues (slugs `fr`/`en`/`ar`, `ar` coché « right to left »),
régler « URL modifications » sur *The language is set from the directory*,
NE PAS cocher « Hide URL language information for default language »,
choisir Français comme langue par défaut, activer `properties` dans
« Custom post types and Taxonomies », puis passer l’étape du wizard
« Assign default language to all contents without language ». Le résultat
fonctionnel est identique : ce sont ces réglages, pas l’outil qui les pose,
qui font le contrat trilingue (9 URLs, RTL, hreflang).

## Configuration sensible

Ne jamais committer de secrets. Les secrets d’automatisation doivent être injectés via les réglages protégés de WordPress ou `PARTIKULIER_AUTOMATION_API_SECRET` selon l’environnement. En production, utiliser le mode de sécurité signé/enforced décrit dans `docs/whatsapp-n8n-setup.md` et ne pas réutiliser de secret de staging.

### Limiteurs derrière le CDN (DP-4 — statut ouvert)

**Constat.** Les trois limiteurs du projet identifient le visiteur par `REMOTE_ADDR` : le limiteur REST (`plugin/partikulier-core/src/RateLimiter.php:19` pour l’identité du quota, `RateLimiter.php:64-69` pour la lecture de l’IP), la garde d’effacement des leads (`plugin/partikulier-core/src/Domain/Leads/LeadsEraseGuardTrait.php:223`) et le limiteur de dépôt du thème (`theme/partikulier/inc/class-security.php:84` — IP hachée en clef de transient). Toute l’identité « par visiteur » repose sur cette valeur.

**Risque CDN.** Le retour du support identifie le CDN Hostinger (nom `*.cdn.hstgr.net`) ; une observation DNS indépendante allant dans ce sens a été rapportée, sans pour autant mesurer l’IP reçue par PHP. Si `REMOTE_ADDR` est l’IP du proxy et non celle du visiteur, tous les visiteurs anonymes partagent une même identité de quota : la protection « quota par visiteur » est dégradée (la protection anti-charge globale, elle, reste).

**Statut DP-4 : OUVERT.** La valeur de `REMOTE_ADDR` derrière ce CDN n’est pas confirmée. Aucune décision n’est prise tant que la vérification côté hébergeur n’est pas faite (message de suivi : `MESSAGE-HOSTINGER-DP4-SUIVI.md`, transmis par le commanditaire uniquement).

**Règle d’ingénierie (interdiction).** Ne jamais lire `X-Forwarded-For` / `X-Real-IP` ni installer un résolveur d’IP de confiance sans chaîne de confiance vérifiée : un en-tête arbitraire laissé au contrôle du client permettrait à n’importe quel visiteur de choisir son identité de quota (usurpation).

**Critère de décision.** IP réelle restaurée et non usurpable → conserver `REMOTE_ADDR` et le documenter. IP de proxy commune → correction côté hébergeur en priorité, sinon résolveur commun avec chaîne de confiance documentée. Identité usurpable ou chaîne inconnue → ne pas valider la protection « quota par visiteur ».

**Périmètre applicatif concerné à l’identique :** `RateLimiter.php`, `LeadsEraseGuardTrait.php`, `class-security.php`.

### Playbook production (E-4102)

**Épinglage des exécutables.** En production, définir la constante PHP `PARTIKULIER_EXEC_REQUIRE_PIN` dans `wp-config.php` (une variable d’environnement seule ne suffit pas sans code explicite qui la transforme en constante) et fournir la liste épinglée via le filtre `partikulier_exec_whitelist` (`theme/partikulier/inc/class-exec-whitelist.php:93`). Chaque entrée conserve le schéma réel `candidates` (chemins absolus) et `sha256` (empreinte attendue de 64 caractères hexadécimaux), à relire dans la classe avant tout collage ; tout écart (binaire remplacé, absent) est refusé et journalisé. Le principe : un chemin exécutable autorisé n’atteste pas le contenu du binaire — l’épingle permet de refuser un binaire modifié. Après chaque maintenance d’un binaire listé, mettre à jour son empreinte de façon contrôlée et documentée. Ne pas confondre épinglage (ce dispositif), validation d’images (AVIF, voir plus bas) et configuration HTTP (nginx/LiteSpeed, voir plus bas).

**Consigne nginx/LiteSpeed « polyglotte ».** Scoper PHP-FPM hors `/wp-content/uploads/` : un fichier `.php` déguisé en image ne doit jamais être interprété. S’assurer que `X-Content-Type-Options: nosniff` est bien actif au niveau serveur — le thème l’envoie déjà côté PHP (`inc/class-security.php::send_public_headers()`), la consigne serveur couvre les fichiers statiques servis directement.

**Cache des assets.** `expires 1y + immutable` sur les assets fingerprintés, en réservant cette règle aux URL dont l’invalidation par contenu est effectivement vérifiée : un fichier modifié doit obtenir une URL distincte, que le cache/CDN distingue réellement (à vérifier sur l’hébergement, pas à déduire de la seule mention E-3102). gzip/brotli actif sur les réponses texte, à mesurer sur l’hébergement. Ne pas appliquer un an/immutable aux pages HTML, aux réponses REST, au diagnostic ni aux espaces privés.

**Livraison AVIF.** Après déploiement : vérifier le MIME `image/avif` réellement servi par l’hébergeur, PUIS définir `PARTIKULIER_ENABLE_AVIF_DELIVERY` (la conversion existe, la livraison est gardée par cette constante, éteinte par défaut — `inc/class-avif.php:347`). Si le serveur sert un mauvais MIME, laisser la constante éteinte et le consigner. Vérifier aussi le décodage réel dans les navigateurs et le repli : MIME, décodage et autorisation d’exécution sont des contrôles différents.

**Statut.** Ces consignes relèvent de l’exploitation Hostinger ; elles seront vérifiées dans la recette d’exploitation Hostinger, distincte du contrôle d’identité IP DP-4 qui reste ouvert. Aucune mesure d’exploitation citée ici n’est déjà exécutée.

## Catalogues i18n

Le plugin `partikulier-core` est la source canonique du domaine gettext `partikulier`. Le thème conserve une copie synchronisée pour garantir un repli traduit lorsque le plugin est désactivé. Le packaging et la CI refusent toute divergence entre les cinq fichiers partagés (`partikulier.pot`, `ar.po`, `ar.mo`, `en_US.po`, `en_US.mo`).

Pour personnaliser une traduction sans modifier les livrables, placer le catalogue dans l’emplacement WordPress prioritaire :

```text
wp-content/languages/plugins/partikulier-core-<locale>.mo
```

La personnalisation est ainsi conservée lors des mises à jour du plugin.

## Rollback

Conserver l’archive précédente et son checksum avant chaque mise à jour. En cas de régression, réinstaller les deux archives de la version précédente dans le même ordre, puis vérifier la route de santé et le parcours front. Les migrations du plugin sont journalisées ; ne pas supprimer manuellement les tables métier.

## QA complète

Les contrats PHP demandant WordPress se lancent depuis un hôte WordPress avec `PK_WP_DIR`. La QA navigateur du thème se lance depuis `theme/partikulier` avec `PK_BASE` pointant vers un staging :

```bash
npm ci
npx playwright install --with-deps chromium
PK_BASE=https://staging.example.test npm test
```

Les scripts de staging sont protégés par un garde CLI et ne sont pas chargés par le runtime WordPress public.
