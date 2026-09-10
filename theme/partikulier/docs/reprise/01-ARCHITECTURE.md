# Architecture

## Organisation des fichiers

```
partikulier/
├── style.css              En-tête WordPress + version (source de vérité de la version)
├── functions.php          Constantes + chargement des 37 modules, dans l'ordre
├── index.php page.php     Gabarits racine (délèguent à templates/)
├── header.php footer.php
├── front-page.php
├── inc/                   37 modules, un par responsabilité
├── templates/             Gabarits de page et parts/
├── estatik4/front/        Surcharges des gabarits Estatik (convention du plugin)
├── assets/
│   ├── css/style.css      Feuille unique, pas de framework
│   ├── js/main.js         Vanilla, zéro jQuery
│   ├── js/submit-steps.js Parcours de dépôt en 3 étapes
│   └── fonts/             DM Sans auto-hébergée (CSP)
├── tests/                 Scripts de vérification (PHP + Playwright)
└── docs/                  Guides n8n et leads
```

## Constantes

Définies en tête de `functions.php`. **Ne jamais écrire les chaînes en dur ailleurs.**

```php
PARTIKULIER_VERSION                     '6.13.0'
PARTIKULIER_ESTATIK_POST_TYPE           'properties'
PARTIKULIER_ESTATIK_TYPE_TAXONOMY       'es_type'       // studio, appartement, villa…
PARTIKULIER_ESTATIK_STATUS_TAXONOMY     'es_status'     // à vendre / à louer
PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY   'es_category'   // villes/termes historiques à assainir
PARTIKULIER_ESTATIK_LOCATION_TAXONOMY   'es_location'   // villes ET quartiers (taxonomie plate)
```

Le CPT est `properties`, **pas** `estate_property`. Cette erreur a déjà coûté du temps.

## Chargement des modules

`functions.php` charge les modules dans un ordre qui compte : l'infrastructure d'abord (setup, scripts, cache, sécurité), puis les fonctionnalités, puis les écrans d'administration. Chaque module est une classe statique qui s'auto-initialise en fin de fichier :

```php
Partikulier_Mon_Module::init();
```

Pour ajouter un module : créer `inc/class-mon-module.php` et l'ajouter au tableau dans `functions.php`. Un fichier absent est ignoré silencieusement.

## Les modules par domaine

**Infrastructure**
`class-theme-setup` (supports, menus) · `class-scripts` (assets, `pkConfig`) · `class-optimization` (dégraissage WordPress) · `class-cache` (cache fichier HTML/gz/br) · `class-security` (en-têtes HTTP)

**SEO**
`class-seo` (title, meta, canonical, OG) · `class-jsonld` (schema.org) · `class-sitemap` (sitemap.xml + robots.txt virtuels) · `class-geo` (fil d'Ariane, maillage géographique) · **`class-listing-urls`** (URL ville/quartier + 301)

**Dépôt et cycle de vie de l'annonce**
`class-form` (formulaire, validation, upload) · `class-listing-preview` (titre et description auto) · `class-morocco-places` (référentiel villes/quartiers) · `class-place-requests` (modération des lieux proposés) · `class-whatsapp-verification` (validation avant publication) · **`class-listing-approval`** (écran admin + identifiants + n8n)

**Multilingue**
`class-listing-i18n` (gabarits FR/AR/EN) · `class-listing-translations` (création des versions liées) · `class-localization` (chaînes d'interface)

**Espace annonceur**
`class-dashboard` (mes annonces) · `class-owner-insights` (statistiques anonymes, favoris serveur)

**Acquéreurs et automatisation**
`class-buyer-qualification` · `class-lead-retention` · `class-leads-admin` · `class-automation-bridge` (n8n entrant)

**Administration**
`class-settings` (options) · `class-customization` (personnalisation sans code) · `class-page-doctor` (diagnostic) · `class-upgrade-wizard` (mise à niveau) · `class-required-pages` + `class-page-templates`

**Fondations non activées**
`class-premium`, `class-payment-foundation`, `class-saved-alerts` — structures posées, fonctionnalités pas encore ouvertes.

## Modèle de données

### Métadonnées d'annonce

Toutes préfixées `_pk_`. Les plus utilisées :

| Meta | Contenu |
| --- | --- |
| `_pk_status` | Statut métier : `actif`, `pending`, `refuse`, `vendu` |
| `_pk_owner_name` / `_pk_owner_phone` / `_pk_owner_email` | Contact annonceur |
| `_pk_owner_role` | Toujours `proprietaire` (les agents sont refusés) |
| `_pk_city_name` / `_pk_district_name` | Localisation en clair saisie au dépôt |
| `_pk_url_city` / `_pk_url_district` | **Slugs figés servant à construire l'URL** |
| `_pk_approved_at` | Date de validation admin (utilisée par la route n8n de rattrapage) |
| `_pk_n8n_sent` / `_pk_n8n_error` | Résultat du webhook sortant |
| `_pk_auto_translation` | Marque une traduction générée — **à exclure des listes admin** |
| `_pk_whatsapp_verification_code` | Code de validation WhatsApp |
| `_pk_meta_description` | Meta description calculée au dépôt |
| `_pk_views` | Compteur de vues |

Les champs Estatik natifs (`es_property_price`, `es_property_area`, `es_property_bedrooms`…) sont écrits en parallèle pour que le plugin reste cohérent.

### Métadonnées utilisateur

| Meta | Contenu |
| --- | --- |
| `_pk_credentials_sent` | Date du premier envoi d'identifiants. **Sa présence empêche la régénération automatique du mot de passe.** |

### Options

`pk_theme_options` (tableau unique de tous les réglages) · `pk_url_rules_version` (versionne les règles de réécriture) · `pk_*_db_version` (migrations de tables).

### Tables personnalisées

Préfixées `{prefix}pk_` : `pk_automation_events`, `pk_buyer_leads`, `pk_property_saves`, `pk_saved_alerts`, `pk_payment_orders`, etc. Créées par leurs modules respectifs via `dbDelta`, versionnées par une option.

## Points d'entrée HTTP

### REST — namespace `partikulier/v1`

| Route | Méthode | Auth | Rôle |
| --- | --- | --- | --- |
| `/approved-listings` | GET | En-tête `X-Partikulier-Automation` | Rattrapage n8n : validations des 72 dernières heures |
| `/owner/dashboard` | GET | Nonce | Statistiques propriétaire |
| `/owner/listings/(?P<id>\d+)/action` | POST | Nonce | Actions sur une annonce |
| `/erase-lead` | POST | Secret | Effacement RGPD d'un lead |
| (automation-bridge) | POST | Secret | Événements n8n entrants |

### AJAX

`pk_submit_listing` (dépôt, avec `nopriv`) · `pk_manage_listing` · `pk_sync_favorite` · `pk_favorites_list` · `pk_views_counter`.

## Conventions de code

- **Zéro jQuery** côté public. JavaScript vanilla uniquement.
- **Classes statiques** avec `init()` appelé en fin de fichier.
- **Préfixe `pk_` / `Partikulier_`** partout : métas, options, classes CSS, fonctions.
- **Échappement systématique** : `esc_html`, `esc_attr`, `esc_url` à la sortie.
- **`esc_html_e()` exige un littéral.** Pour une chaîne calculée : `echo esc_html( ma_fonction() );`
- **Commentaires en français**, expliquant le *pourquoi* et non le *quoi*.
- **CSS sans framework**, une seule feuille, préfixe `.pk-`.

## Sécurité

- Formulaire public : nonce + validation serveur + honeypot.
- Actions admin : `current_user_can( 'manage_options' )` + `check_admin_referer`.
- Routes automatisation : secret comparé en en-tête, jamais en paramètre d'URL.
- Mots de passe : WordPress ne stocke que l'empreinte. Le mot de passe en clair n'existe qu'en mémoire, le temps de l'envoi à n8n.
