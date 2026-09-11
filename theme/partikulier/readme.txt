=== Partikulier ===

Contributors: partikulier
Tags: real-estate, property, listings, immobilier, performance, avif
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 8.0
Stable tag: 6.19.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Portail immobilier de particuliers. Annonces gratuites, ultra-rapide, SEO et LLM-ready.

== Description ==

Partikulier est un theme WordPress dedie aux portails immobiliers entre particuliers.
Il est concu pour fonctionner avec un seul plugin : **Estatik** (gratuit, sur wordpress.org).

Caracteristiques :

* Deposez d'annonce gratuite en 2 minutes, sans inscription prealable
* Conversion automatique de toutes les images uploadees en AVIF (jusqu'a -50 % de poids)
* Cache de pages integre au theme (HTML + Gzip + Brotli) — pas besoin de plugin de cache
* Schema.org JSON-LD complet (RealEstateListing, ItemList, WebSite, SearchAction, BreadcrumbList, RealEstateAgent)
* Sitemap.xml et robots.txt generes automatiquement, sans plugin SEO
* SEO geographique : pages villes/regions auto-generees, fil d'Ariane, URLs propres
* Contenu lisible par les LLM (structure semantique, donnees structurees, pas de JS bloquant)
* Zero jQuery : ~3 ko de JavaScript vanilla
* Optimisations PageSpeed : preload, preconnect, lazy-loading natif, compression HTML
* Design moderne inspire des kits premium : degrade violet/orange, vague SVG, cartes epurees

== Installation ==

1. Installez et activez le plugin **Estatik** (un seul plugin requis).
2. Uploadez le theme dans `wp-content/themes/partikulier/` ou via Apparence > Themes > Ajouter.
3. Activez le theme Partikulier.
4. Reglages > Permaliens : choisissez "Nom de la publication" puis Sauvegarder.
5. Creez la page **Deposer une annonce** (slug `deposer`) avec le template "Deposer une annonce".
6. Reglages > Lecture : page d'accueil = "Vos dernieres annonces" (laissez la page d'accueil a vide pour que le theme utilise front-page.php).
7. Apparence > Menus : creez le menu "Menu principal" (Accueil, Annonces, Deposer une annonce, Mentions legales).

== Configuration ESTATIK ==

* Estatik > Settings : activez les types (appartement, maison, terrain...) et les actions (vendre, louer).
* Creez au moins une ville par annonce (Estatik > Addresses ou via le formulaire du theme).
* Le theme surcharge automatiquement les templates d'Estatik via le dossier `estatik4/front/`.

== Changelog ==

= 6.19.1 =

* Lot C4 de la refonte (CDC v1.2 §3.2 I18N-1 — « un seul mécanisme de traduction actif ») : RETRAIT PHYSIQUE des chargeurs et copies dormants côté thème, appliqué sur la base 6.19.0 livrée au lot C3. Les trois chargeurs marqués dormants au C3 (accrochages init@5, wp@1, wp@2 et appel anticipé du domaine « es ») sont supprimés du code du thème — plus aucune méthode de chargement de textdomain n'existe côté thème, aucun accrochage fantôme ne subsiste.
* Consolidation des catalogues : la copie de parité du kit traducteur (`languages/ar.mo`, `ar.po`, `en_US.mo`, `en_US.po`, `partikulier.pot` — identiques octet pour octet au kit canonique du plugin, empreintes SHA-256 du lot) est retirée du thème. Le kit canonique vit dans partikulier-core 2.9+ (`languages/`), le domaine « partikulier » est servi exclusivement par son chargeur unique. Le catalogue arabe du popup d'authentification d'Estatik reste servi depuis `languages/estatik/es-ar.mo` du thème — deuxième source candidate que le chargeur unique consulte (le plugin Estatik n'embarque aucun catalogue arabe).
* Dégradation sans plugin désormais documentée au contrat du lot (`partikulier-core/tests/i18n-unified-mechanism-contract.php`, mis à jour en 2.9.1 : extinction physique consolidée, kit consolidé côté plugin, versions épinglées 2.9.1/6.19.1) : sans le plugin, le site est servi en langue source française (msgids), le chrome conservant ses dictionnaires de repli. Aucune table, aucune écriture, le schéma reste 2.6.0.
* Aucun autre changement runtime : ni gabarit, ni style, ni JavaScript. Requiert partikulier-core 2.9+ ; les versions antérieures du thème (6.18.x) conservent leur chemin autonome historique.

= 6.19.0 =

* Lot C3 de la refonte (CDC v1.2 §3.2 I18N-1 — « un seul mécanisme de traduction actif ») : EXTINCTION du chargeur runtime des textdomains côté thème. Le chargement des domaines « partikulier » (catalogues canoniques `languages/ar.mo` et `languages/en_US.mo`, nommage `<locale>.mo`) et « es » d'Estatik (correctif 6.17.31 du popup d'authentification, trois sources candidates) est désormais servi par le chargeur unique de partikulier-core 2.9.0 (`Partikulier\Core\Domain\I18n\I18nDomainLoader`, inscrit au bootstrap : chargement canonique à la locale du site — correctif C1A-013 pour WP <= 6.6 — puis points runtime init@5 / after_setup_theme@1 / wp@1).
* Les trois chargeurs du trait `inc/class-localization-runtime.php` (`load_textdomain`, `load_active_textdomain`, `load_estatik_textdomain`) deviennent DORMANTS quand le chargeur unique du plugin est chargé — le thème ne charge plus AUCUN textdomain en présence du plugin. Sans plugin, le repli autonome historique 6.18.x est conservé à l'identique (REG-5 — preuve par le contrat du lot : `partikulier-core/tests/i18n-unified-mechanism-contract.php`).
* Mécanisme unique strict : avec le plugin actif, exactement UN chargeur de textdomains (plugin) et UN filtre gettext (plugin, lot C2) servent le site ; l'adaptateur Polylang (`class-listing-translations.php` / `class-listing-urls.php` + hooks `pll_*`) reste l'adaptateur unique côté thème, conformément au Tableau 10 du CDC. Aucune table, aucune écriture, le schéma reste 2.6.0 (aucune migration au lot C3). Le retrait physique des chargeurs dormants relève du lot C4.
* Aucun autre changement runtime : ni gabarit, ni style, ni JavaScript. Requiert partikulier-core 2.9.0 pour la délégation ; fonctionne sinon de manière autonome.

= 6.18.9 =

* Lot C2 de la refonte (CDC v1.2 §3.2 I18N-1) : EXTINCTION du filtre gettext et des dictionnaires internes du chrome — le mécanisme de résolution des chaînes publiques (ordre figé REG-3 : .mo canonique > dictionnaire form > dictionnaire chrome > Polylang pour les chaînes enregistrées) est désormais servi par le service unifié de partikulier-core 2.8.0 (`Partikulier\Core\Domain\I18n\I18nChromeService`, dictionnaires `ChromeDictionary` 136 entrées / `FormsDictionary` 140 entrées, ports VERBATIM prouvés par contrat). Quand le plugin est actif, le filtre gettext du plugin (enregistré au bootstrap) est LE mécanisme : le thème cesse d'enregistrer son propre filtre et fournit son registre chrome au service (`provide_registry` — inversion de dépendance, le registre est une donnée du thème : clés du shell public + valeurs par défaut des réglages).
* Couture de `inc/class-localization.php` : `translate_polylang_string` (environ 150 sites d'appel dans les templates) et `translate_public_string` délèguent au service unifié quand le plugin existe ; les chemins historiques sont renommés `translate_polylang_string_local` / `translate_public_string_local` dans le trait `class-localization-strings.php` (repli REG-5). Sans plugin, le repli autonome 6.18.x est conservé à l'identique — les deux chemins servent les mêmes chaînes (parité prouvée par le contrat du lot, empreinte du corpus gelé identique).
* Les catalogues `class-localization-chrome.php` et `class-localization-forms.php` deviennent DORMANTS côté thème (repli uniquement, retrait physique au lot C4) — la propriété des données passe au plugin. Aucune table, aucune écriture, le schéma reste 2.6.0 (aucune migration au lot C2).
* Aucun autre changement runtime : ni gabarit, ni style, ni JavaScript. Requiert partikulier-core 2.8.0 pour la délégation ; fonctionne sinon de manière autonome.

= 6.18.8 =

* Lot C1 de la refonte (CDC v1.2 §3.2 I18N-1) : la couche CONTENU de la rédaction multilingue passe côté plugin — le lexique trilingue (fr/en/ar) et les générateurs de texte des annonces (titre, description, meta description 155, texte alternatif des photos) sont désormais servis par partikulier-core 2.7.0 via `Partikulier\Core\Domain\I18n\I18nContentService` (bibliothèque pure : zéro hook, zéro table, zéro écriture — le schéma reste 2.6.0, aucune migration). `inc/class-listing-i18n.php` devient une couture : les neuf méthodes publiques de l'API (`languages`, `title`, `description`, `meta_description`, `image_alt`, `localized_type`, `localized_place`, `title_from_post`, `rooms_label_from_post`) délèguent quand le plugin est actif ; sans le plugin, le chemin autonome 6.18.x est conservé à l'identique (REG-5 — les deux chemins produisent le même texte à partir du même lexique, parité prouvée par le contrat du lot sur 30 annonces × 3 langues × 4 générateurs).
* Découpe du monolithe `inc/class-listing-i18n.php` (955 lignes) en modules d'au plus 300 lignes (part du lot D fusionnée au C, annexe C tableau 11) : le shell `class-listing-i18n.php` (261 lignes) compose cinq traits chargés avant lui — `class-listing-i18n-lexicon.php` (langues + lexique trilingue), `class-listing-i18n-places.php` (vocabulaire : lieux arabes, types, pièces, étages, nombres), `class-listing-i18n-text.php` (normalisation + titre + description), `class-listing-i18n-seo.php` (meta description + alt photo) et `class-listing-i18n-post.php` (API post-dépendante legacy). Code déplacé VERBATIM : les neuf méthodes publiques historiques sont renommées `*_local` dans les traits (chemins de repli REG-5) et ré-exposées par la couture du shell.
* Consolidation des catalogues de traduction : les doublons `languages/partikulier-ar.mo` et `languages/partikulier-en_US.mo` (copies byte pour byte de `ar.mo` / `en_US.mo`, preuve au T0 du lot C) sont supprimés — le JIT de WordPress (`_load_textdomain_just_in_time`) charge `<locale>.mo` pour un thème dont le répertoire `languages/` est hors `WP_LANG_DIR`, ce nommage est donc LE canonique du thème ; `ar.mo` et `en_US.mo` restent les deux seuls catalogues, recompilés sous forme canonique GNU gettext : l'en-tête porte `hash_addr` de fin de table (fin de la table des traductions, table de hachage absente) — lisible par les DEUX lecteurs gettext de WordPress (pomo historique ET `WP_Translation_File`), alors que l'ancienne forme `hash_addr=0` était rejetée par pomo. Parité des entrées prouvée avant/après recompilation (contrat du lot) ; l'empreinte du corpus gelé reste identique.
* Aucun autre changement runtime : ni gabarit, ni style, ni JavaScript. Requiert partikulier-core 2.7.0 pour la délégation ; fonctionne sinon de manière autonome.

= 6.18.7 =

* Lot B6 de la refonte (CDC v1.2) : le domaine variantes de traduction passe côté plugin — la table `pk_property_variants` (registre d'emplacements de variantes localisées, sans aucun contenu dupliqué) est désormais lue/écrite par partikulier-core 2.6.0 uniquement. `inc/class-localization.php` devient une couture : `prepare_variant` (emplacement sans duplication de contenu, upsert par annonce+locale, méta `_pk_free_text_language`) et `link_variant` (attachement d'une annonce Estatik déjà créée, statut prepared → linked) sont délégués à `Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService` quand le plugin est actif ; sans le plugin, le chemin autonome 6.17.x est conservé à l'identique (REG-5, matrice quatre combinaisons). Le schéma n'est plus installé par le thème quand le plugin est actif (DDL à l'identique — REG-6). Huitième et dernier domaine : les 8/8 domaines métier sont désormais propriété du plugin.
* Découpe REG-3 du monolithe `inc/class-localization.php` (990 lignes) en modules d'au plus 300 lignes (part du lot D fusionnée au B6, annexe C) : le shell `class-localization.php` (249 lignes) compose quatre traits chargés avant lui — `class-localization-runtime.php` (textdomains, requêtes, redirection), `class-localization-strings.php` (registre chrome, résolution), `class-localization-chrome.php` (catalogue de repli du chrome) et `class-localization-forms.php` (catalogue de repli des formulaires). Code déplacé VERBATIM, aucune modification de comportement : même classe publique, mêmes signatures, mêmes hooks — l'ordre de résolution des traductions est inchangé et l'empreinte du corpus gelé avant découpe est identique après le lot (diff nul, preuve au journal du lot). La migration de mécanisme relève du lot C.
* Aucun autre changement runtime : ni gabarit, ni style, ni JavaScript. Requiert partikulier-core 2.6.0 pour la délégation ; fonctionne sinon de manière autonome.

= 6.18.6 =

* Lot B5 de la refonte (CDC v1.2) : le domaine statistiques propriétaire passe côté plugin — la table `pk_property_saves` (favoris visiteurs agrégés, pseudonymisés par HMAC non réversible) est désormais lue/écrite par partikulier-core 2.5.0 uniquement. `inc/class-owner-insights.php` devient une couture : `sync_favorite` (gardes, pseudonymisation « favorite-v1|<visiteur> », plafonnement 60/heure par visiteur, upsert annonce+visiteur), `favorite_count` (agrégat propriétaire, fenêtre 90 jours) et `purge_expired_saves` (rétention) sont délégués à `Partikulier\Core\Domain\OwnerStats\OwnerStatsService` quand le plugin est actif ; sans le plugin, le chemin autonome 6.17.x est conservé à l'identique (REG-5, matrice quatre combinaisons).
* Le cron quotidien `pk_owner_insights_daily_purge` (purge des pseudonymes inactifs) est désormais planifié et géré par le plugin — le thème cesse de l'enregistrer quand le service existe (pattern rétention du lot B2). Le schéma n'est plus installé par le thème quand le plugin est actif (formulation DDL à l'identique — REG-6).
* Les écrans et routes d'intégration restent au thème : AJAX des favoris (`pk_sync_favorite`, `pk_favorites_list`, rendu des cartes), page Favoris, routes REST `/owner/dashboard` et `/owner/listings/<id>/action` (agrégation et gestion d'annonce) — ils ne touchent la table que par les primitives déléguées (arbitrage B5 : écran au thème, données au plugin). Aucun autre changement runtime : ni gabarit, ni style, ni JavaScript. Requiert partikulier-core 2.5.0 pour la délégation ; fonctionne sinon de manière autonome.

= 6.18.5 =

* Lot B4 de la refonte (CDC v1.2) : le domaine automatisation n8n passe côté plugin — les deux tables (`pk_automation_events`, `pk_n8n_hmac_audit`) sont désormais lues/écrites par partikulier-core 2.4.0 uniquement. `inc/class-automation-bridge.php` et `inc/class-n8n-security.php` deviennent des coutures : la réception idempotente des accusés d'événements (payload haché, jamais persisté), la vérification du secret partagé et de la signature HMAC (mode off/log/enforce, promotion « secret présent + mode off = enforce » — LOT 2), les en-têtes de webhooks sortants signés, la migration des réglages `pk_n8n_settings` et l'audit des échecs HMAC sont délégués à `Partikulier\Core\Domain\Automation\AutomationService` quand le plugin est actif ; sans le plugin, le chemin autonome 6.17.x est conservé à l'identique (REG-5, matrice quatre combinaisons).
* La route POST `/automation-event` est déclarée par le plugin (registre unique INTEG-3 — le thème ne la redéclare plus, zéro collision à l'état nominal). Le schéma des deux tables n'est plus installé par le thème quand le plugin est actif (formulations DDL à l'identique — REG-6). Les helpers de déclaration de routes du bridge restent au thème : ils servent les autres modules (qualification, rétention, approbation, statistiques propriétaire).
* L'écran « Réglages n8n » reste au thème (rendu, nonce, redirection) ; la validation et l'enregistrement des réglages sont délégués au service (arbitrage B4 : UI au thème, politique au plugin). Aucun autre changement runtime : ni gabarit, ni style, ni JavaScript. Requiert partikulier-core 2.4.0 pour la délégation ; fonctionne sinon de manière autonome.

= 6.18.4 =

* Lot B3 de la refonte (CDC v1.2) : le domaine alertes passe côté plugin — les deux tables (`pk_saved_alerts`, `pk_alert_deliveries`) sont désormais lues/écrites par partikulier-core 2.3.0 uniquement. `inc/class-saved-alerts.php` devient une couture : `save_alert` (création/actualisation après consentement, upsert par signature de critères) et `change_status` (active/paused/stopped) sont délégués à `Partikulier\Core\Domain\Alerts\AlertService` quand le plugin est actif ; sans le plugin, le chemin autonome 6.17.x est conservé à l'identique (REG-5, matrice quatre combinaisons).
* Le schéma n'est plus installé par le thème quand le plugin est actif (dbDelta du plugin, formulations à l'identique — REG-6). Aucune route, aucun cron, aucun changement visible : le module ne contacte aucun fournisseur et n'expose rien de public (port fidèle — l'adaptateur Meta/n8n sera ajouté après validation des accès externes) ; `pk_alert_deliveries` reste vierge.
* Aucun autre changement runtime : ni gabarit, ni style, ni JavaScript. Requiert partikulier-core 2.3.0 pour la délégation ; fonctionne sinon de manière autonome.

= 6.18.3 =

* Lot B2 de la refonte (CDC v1.2) : le domaine leads/qualification/WhatsApp passe côté plugin — les huit tables (`pk_buyer_leads`, `pk_interest_events`, `pk_contact_limits`, `pk_contact_disclosures`, `pk_whatsapp_consents`, `pk_whatsapp_messages`, `pk_buyer_preferences`, `pk_lead_followups`) sont désormais lues/écrites par partikulier-core 2.2.0 uniquement. `inc/class-buyer-qualification.php`, `inc/class-lead-retention.php` et les accès données de `inc/class-leads-admin.php` deviennent des coutures : qualification (contact-authorization, préférences, consentement, opt-out), pont REST `register_api_lead` (INTEG-2), rétention (cron `pk_buyer_privacy_purge` + route `/erase-lead`, désormais détenus par le plugin), KPI/lignes/export de l'écran « Leads WhatsApp » et mises à jour de suivi sont délégués à `Partikulier\Core\Domain\Leads\LeadService` quand le plugin est actif.
* Lecture croisée : `inc/class-saved-alerts.php` (domaine alertes, lot B3) consulte le consentement d'un lead via le service du plugin quand il existe. Le schéma n'est plus installé par le thème quand le plugin est actif (formulations DDL à l'identique — REG-6). Le workflow de validation WhatsApp avant publication (`inc/class-whatsapp-verification.php`) reste côté thème : il n'écrit que des méta de modération, aucune table du domaine.
* Aucun autre changement runtime : ni gabarit, ni style, ni JavaScript. Requiert partikulier-core 2.2.0 pour la délégation ; fonctionne sinon de manière autonome (chemin 6.17.x intact).

= 6.18.2 =

* Lot B1 de la refonte (CDC v1.2) : le domaine paiements/premium passe côté plugin — les trois tables (`pk_payment_orders`, `pk_premium_subscriptions`, `pk_premium_history`) sont désormais lues/écrites par partikulier-core 2.1.0 uniquement. `inc/class-premium.php` et `inc/class-payment-foundation.php` deviennent des coutures : chaque opération (attribution, retrait, expiration, journal, création de commande) est déléguée à `Partikulier\Core\Domain\Premium\PremiumService` / `Partikulier\Core\Domain\Payments\PaymentService` quand le plugin est actif ; sans le plugin, le chemin autonome 6.17.x est conservé à l'identique (REG-5, matrice quatre combinaisons).
* Le schéma n'est plus installé par le thème quand le plugin est actif (dbDelta du plugin, formulations à l'identique — REG-6) ; la gate paiement reste fermée (create_order → WP_Error `pk_payment_disabled`, inchangé) ; l'écran d'administration « Annonces premium » est inchangé et rend le journal via le service du plugin.
* Aucun autre changement runtime : ni gabarit, ni style, ni JavaScript. Requiert partikulier-core 2.1.0 pour la délégation ; fonctionne sinon de manière autonome.

= 6.18.1 =

* Fermetures post-lot A, zéro changement runtime : le seeder du kit QA (`tests/seed-annonces-test.php`) pose désormais les coordonnées `es_latitude`/`es_longitude` (centres de quartiers réalistes, 10 quartiers × 3 villes) sur les trois langues dès la création — l'entité GeoCoordinates du JSON-LD est donc servie sur le banc de test complet ; nouveau script `tests/completer-geo.php` (simulation par défaut, `PK_APPLIQUER=1` pour écrire) qui comble les jeux existants sans jamais écraser une valeur déjà présente, idempotent, journalisé par la méta `_pk_seed_geo`.
* Motif : les audits JSON-LD (CA du lot A) exigeaient GeoCoordinates ; le jeu de banc antérieur n'en portait pas (2 contrôles en échec), défaut de données et non de code — `class-jsonld.php` inchangé.
* Aucun autre changement : ni gabarit, ni style, ni JavaScript, ni PHP chargé par WordPress.

= 6.18.0 =

* Lot A de la refonte (CDC v1.2, INTEG-2 et INTEG-3) : le dispositif de leads expose le pont public `Partikulier_Buyer_Qualification::register_api_lead()` — la route POST /partikulier/v1/leads du plugin partikulier-core 2.0 alimente désormais le dispositif complet (huit tables, mêmes contrôles, même plafonnement, même journalisation) au lieu d'un stockage parallèle par commentaires ; le cœur transactionnel de la demande de contact est partagé par la voie n8n et la voie REST.
* Lot A (INTEG-3) : les dix routes REST du thème sont déclarées par le registre unique du plugin `Partikulier\Core\Rest\RouteRegistry` (espace de noms `partikulier/v1` déclaré une seule fois, collisions refusées et journalisées) via `Partikulier_Automation_Bridge::declare_rest_route()` ; sans le plugin, repli autonome sur `register_rest_route` — aucun changement de route, de permission ni de réponse.
* Ponts de performance inchangés et volontairement conservés (REG-2) : détection de route du mu-plugin `partikulier-rest-lite.php` et saut des modules du thème dans `functions.php` — un test de parité (kit CI du plugin, `tests/routes-collision.php`) les lie au registre pour tout renommage futur.
* Aucun autre changement runtime : ni gabarit, ni style, ni JavaScript. Requiert partikulier-core 2.0.0 pour le pont leads et le registre ; fonctionne sinon de manière autonome.


= 6.17.35 =

* Intégration du CI GitHub : workflow `ci.yml` (lint PHP en matrice 8.0-8.3, lint JS/shell/JSON, cohérence de version sur les 4 fichiers, structure de thème, zip reproductible vérifié et téléversé en artefact) et `qa-live.yml` (QA fonctionnelle manuelle contre le staging via le secret `PK_BASE`).
* `.gitignore` enrichi (artefats de capture `tests/__baseline__/`, journaux, fichiers d'OS) et `README.md` ajouté comme page d'accueil du dépôt.
* Zéro changement runtime : seules la constante de version (cache-busting) et des fichiers jamais chargés par WordPress bougent. Le zip du thème exclut `.github/` (plomberie du dépôt, pas du contenu de thème).


= 6.17.34 =

* Maintenance du harnais de non-régression embarqué (dossier tests/) — zéro changement runtime (seule la constante de version bouge pour le cache-busting).
* Captures déterministes : chaque vue est chargée deux fois (échauffement des caches), les images `loading="lazy"` sont forcées et attendues jusqu'à décodage, la page est déroulée puis ramenée en haut, et le lien d'évitement (position:fixed) est épinglé hors écran. Sans cela, la capture pleine page fige aléatoirement les placeholders d'images différées (5 à 10 % d'écart fantôme sur l'accueil selon les sessions — comportement Chromium documenté) : mesuré 12/12 vues à 0,00 % après correction, reproductible.
* Routes alignées sur l'existant : `/deposer-une-annonce/` (404 — harnais complet cassé en aval) remplacée par `/deposer/` ; `/property/` (redirigée) par `/annonces/` dans le test rapide.
* Invariant `menu_espace` aligné au design actuel : sur mobile la nav est une grille compacte 3 colonnes pleine largeur (sticky contextuel v1.8) — on vérifie que les entrées font >= 80 px au lieu de l'espacement desktop (l'ancienne garde « nav masquée sous 640 px » était morte depuis le redesign).
* tests/README.md : usage de PK_BASE (tests contre un vrai staging), documentation du protocole de capture, note sur les références non livrées dans le zip.
* readme.txt : titres de version dupliqués fusionnés (6.17.29 ×2, 6.17.7 ×2).


= 6.17.33 =

* Page d'authentification au design du thème : les visiteurs anonymes n'atterrissent plus sur wp-login.php (page WordPress brute, aucun rapport visuel avec le site). Nouvelle page « Connexion » (/connexion/) — gabarit templates/page-connexion.php : titre éditorial, carte d'authentification (shortcode [es_authentication] d'Estatik : connexion, inscription, réinitialisation), garanties, liens de sortie. Provisionnée automatiquement (class-required-pages) et rattachée à Estatik via login_page_id pour que TOUTES les passes d'authentification du plugin (erreurs, inscription, e-mails de réinitialisation) renvoient vers la page du thème.
* Redirections concernées : lien « Se connecter » du bandeau, page « Mon espace » (/mes-annonces/), édition d'une annonce (/deposer/?edit=…), identifiants envoyés par n8n (login_url) — l'URL de retour est conservée à travers les échecs de connexion (filtre es_get_auth_page_uri) et appliquée après une connexion réussie (redirect_url natif d'Estatik).
* La page est localisée FR/EN/AR (dictionnaire chrome), non cachable (nonces + messages flash), noindex et hors sitemap (même politique que les pages du tunnel).
* Comportement JS reproduit en JS natif dans main.js (bascule des écrans d'authentification, activation conditionnelle des boutons) : la feuille publique d'Estatik (public.min.js) reste volontairement non chargée par le thème — aucune dépendance ajoutée.
* Styles .pk-auth (carte, boutons pleine largeur, champs au sens de lecture, messages flash colorés, RTL juste, responsive mobile) dans la continuité du popup d'authentification corrigé en 6.17.31/32.


= 6.17.32 =

* Popup d'authentification : le bouton « Log in with email » est une ancre inline, son padding debordait de sa ligne et peignait le fond brun par-dessus le texte « S'inscrire » situe dessous (10 px mesurés en FR, 16 px en AR ou le libellé se replie sur deux lignes). Le bouton devient display:block avec une marge basse dédiée, le contenu du popup est recentré conformément au design natif d'Estatik (sa feuille publique n'est pas chargée), les champs et liens de retour restent alignés au sens de lecture (start), « Mot de passe oublié ? » a l'extrémité (end) — juste en RTL également.
* État vide de l'archive et des résultats de recherche : « Rien à afficher pour le moment. » restait en français sur les pages EN et AR (constaté sur la capture « réflexion échappée » du scénario 10, page EN servie par détection de langue navigateur). Le libellé entre au dictionnaire du chrome (EN « Nothing to display at the moment. », AR « لا يوجد شيء للعرض في الوقت الحالي. »).
* Footer : la valeur PAR DÉFAUT du texte « À propos » est localisée EN/AR (le texte personnalisé du propriétaire reste inchangé par construction — toute chaîne absente des dictionnaires passe telle quelle) ; les liens « Types de biens » passent par translate_taxonomy_label() comme toutes les autres vues (les noms de termes restaient français sur EN/AR) ; les villes du bloc contact également.


= 6.17.31 =

* Popup d'authentification Estatik (imprime au wp_footer de chaque page) traduit : le plugin charge son domaine a plugins_loaded avec la locale du SITE (en_US), ses catalogues n'etaient jamais servis sur les pages localisees. Le theme recharge le domaine « es » avec la locale de la page (plugin d'abord : es-fr_FR.mo, 1420 chaines ; puis catalogue arabe embarque languages/estatik/es-ar.mo couvrant le chrome visible du popup ; puis l'emplacement communautaire WP_LANG_DIR) et force la re-resolution du conteneur de reglages Estatik.
* Vue AR : « Sign in or register / to save your favourite homes… / Log in with email / Email / Password / Forgot password? » et la bascule d'inscription desormais en arabe. Vue FR : catalogue complet du plugin servi (« Connexion ou Inscription », « Mot de passe oublie ? »...).
* Limite documentee : le titre « Reset password » de l'ecran de reinitialisation est imprime SANS domaine de traduction par le template tiers (reset-form.php) — il reste anglais dans toutes les langues, sans vecteur propre au theme.


= 6.17.30 =

* Scenario 9 (rendu AR + donnees structurees) porte a 11/11 : la variation SEO des <title> de fiches est desormais redigee dans la langue de la page (geo_chain localisee EN/AR, plus de « A vendre Terrain a Marrakech » en vue arabe), et l'archive d'annonces n'herite plus du label Estatik « Property » comme <title>.
* Options « Vendre / Louer / Tous » du hero et du panneau de filtres traduites en arabe : gettext redevient le chemin canonique (le catalogue .mo contient Vendre -> بيع, Louer -> كراء) et le dictionnaire interne couvre desormais Tous/Vendre/Louer/Type en repli.
* Quartiers marocains courants (Targa, Hivernage, Medine, Gueliz, Agdal, Maarif, Ain Diab…) ajoutes au referentiel arabe : ils restaient latins dans les titres et donnees structurees AR des fiches.
* Entite litterale &hellip; eliminee a la source : wp_trim_words reçoit le vrai caractere « … » (depot + traductions automatiques), et le bloc schema.org duplique du plugin Estatik (@type House : titre brut, extrait non decode, adresse latine) est retire au profit du graphe complet du theme.
* Bug visuel AR desktop corrige : les regles RTL du tiroir de filtres vivaient hors media query et decalaient la sidebar de filtres de 273 px sur les cartes a toutes les tailles d'ecran ; elles retournent dans leur contexte mobile.
* Header RTL : le logo Partikulier reste a gauche dans les trois langues (grille fixee LTR, lecture RTL restauree sur la recherche et les actions).


= 6.17.29 =
* Recherche ville (hero + header) : la liste de suggestions heritait de la largeur du champ (127 px dans le hero — boite cramee, texte coupe). Elle fait desormais min(26rem, 92vw), alignee sur le bord du champ, comme le formulaire de depot.
* Autocompletion qui « ne donne rien » sur les pages en cache : pk_places_search ne requiert plus un nonce vivant. Les pages HTML publiques sont servies par LiteSpeed/HCDN jusqu'a 12 h alors qu'un nonce WordPress vit 12-24 h : marge nulle, et le JS avalait l'echec en silence (liste vide sans erreur). Le point d'entree ne sert que des donnees publiques en lecture seule (referentiel integre + termes es_location).
* CSS : selecteur corrompu « .pk-place-suggestionsidden] » repare en « .pk-place-suggestions[hidden] » ; la liste statique du tiroir mobile passe a width:100%.
* Suite d'outils de validation staging distants dans tests/ : charge open-loop (staging-charge.php, verdict TENU/DEGRADE/TOMBE), contrat de cache et TTFB (staging-cache.php), batterie de securite HMAC+portes (staging-securite.php), orchestrateur GO/NO-GO (staging-tout.sh) + LISEZMOI-STAGING.md.
* Zero impact runtime : dossier tests/ jamais charge par WordPress, garde CLI sur chaque outil (403 via HTTP), runtime public byte-identique a 6.17.27.


= 6.17.27 =
* LOT 2 — Isolation : la sonde (pk-diagnostic.php) quitte le tableau des modules ; chargee uniquement en admin (manage_options), en WP-CLI, ou via l'entree URL signee d'un administrateur connecte. Plus de 2 600 lignes parsees sur les GET publics.
* LOT 2 — HMAC : un secret configure + mode « off » = desormais enforce (POST sans signature valide => 401). Le mode « log » reste disponible pour un staging non signant (documente) ; prod = enforce.


= 6.17.26 =
* Hygiene de livraison uniquement : versions alignees (style.css, functions.php, readme.txt, package.json, DOC.md, plugin Core 1.2.0).
* Text Domain corrige : partikulier (ancien : partikulier-cdc-v18-final30).
* Fichiers .mo charges par WordPress : partikulier-ar.mo et partikulier-en_US.mo (copies, anciens fichiers conserves).
* Dependency jQuery retiree de main.js (le script n'utilise pas $).
* Support custom-logo retire : logo texte, jamais image.
* Commentaires corriges : CPT properties (pas estate_property), validation WhatsApp (pas e-mail), palette cognac (pas orange Woo).

= 6.17.17 =
* CDC v1.7.1 candidate : core M0, contrats d’intégration, preuves CI sidecar, package déterministe et baselines visuelles viewport-only.
* Les tests de qualification et la publication restent conditionnés aux gates CI et aux approbations requises.

= 6.17.16 =
* Revue senior : migration idempotente de l’ancien slug `deposer-une-annonce` vers `/deposer/` et exclusion des espaces privés du cache public.
* Reproductibilité renforcée : sorties JSON strictes, suivi des chaînes de redirection, WP-CLI vérifié par SHA-512, `npm ci` et contrôle Node 22.

= 6.17.10 =
* Livraison CDC fermée autosuffisante : scripts, contrat de routes, 30 baselines visuelles versionnées, preuves HMAC/SQL/Semgrep et recette froide.
* Polylang FR/EN/AR : routes préfixées, famille de traductions publiée, détection navigateur et direction RTL vérifiées.
* Cache : les réponses HTML vides ne sont plus persistées comme pages publiques.
* Compatibilité déclarée : PHP 8.0+ et WordPress 7.1 testé.

= 6.17.7 =
* Fix: Senior CDC v1.5 compliance (N+1, RTL, slugs, pagination, HMAC proofs)
* Fix: PHP 8.4 runtime support
* Fix: N+1 SQL optimization (106 -> 91 queries)
* Fix: Clean translated slugs (removed "إعلان مترجم:")
* Fix: Arabic meta descriptions localization
* Fix: RTL visual invariants in header and menu

* i18n trilingue : détection Polylang du navigateur, cookie prioritaire et exemption Googlebot/Bingbot.
* Traductions gettext `ar.mo` et `en_US.mo` prioritaires sur les dictionnaires internes, avec police Noto Sans Arabic locale et RTL mobile.
* Parcours de dépôt et champs libres annotés par langue, slugs AR et contrôles SEO `lang`, JSON-LD, Open Graph et hreflang.
* Racine protégée contre le cache partagé avec `private, no-store` ; URLs localisées conservées dans le cache public sous contrôle de langue.
* Hardening R6 : Scanner de littéraux renforcé (guillemets simples/doubles) et détection exhaustive des chaînes UI non traduites.
* Performance Senior : Optimisation SQL (prévention N+1 via priming cache meta/terms), passage de 180+ à ~30 requêtes sur l'archive.
* UX & Pagination : Correction du blocage à 40 annonces, support illimité et grilles responsive optimisées (24 annonces/page).
* Recettes AR/EN/FR, robots, cache chaud/froid, police, chaînes non traduites et non-régression visuelle prévues par le CDC 6.17.

= 6.16.0 =
* Sécurité n8n : secret dédié, migration reprenable hors `pk_theme_options`, HMAC SHA-256 canonique, timestamp anti-rejeu et rotation à double clé.
* Routes d’automatisation protégées par un wrapper REST commun, avec refus fail-closed des secrets absents ou invalides.
* Journalisation idempotente des événements avec préfixes `n8n-`/`pay-`, gestion des collisions UNIQUE et quota configurable `quota_per_day` sous transaction.
* Sept recettes JSON exécutées sur sandbox fraîche, plus non-régression H/K et Polylang ; aucun warning PHP 8.4 capturé.

= 6.15.0 =
* Lot H : textes éditoriaux FR/EN/AR avec fallback FR, validation d’alt hero et purge de cache sur création et mise à jour des options.
* Lot K : tableau Leads, filtres, KPI, export CSV protégé, statuts de suivi et route de renvoi d’identifiants corrélée par UUID.
* Recette dynamique : contrôles PHP, H/K, budget SQL à 1/20/100 lignes et neutralisation CSV injection.

= 6.13.1 =
* Documentation de reprise complete dans docs/reprise/ : architecture, decisions et leurs justifications, changelog, pieges connus, chantiers restants, procedures de test.
* DOC.md reecrit : l'ancienne version decrivait encore le theme 1.2.0 d'origine (palette orange, 11 modules, e-mail de confirmation) et induisait en erreur.
* guide-utilisation.md ne duplique plus DOC.md : il y renvoie, pour eviter que deux versions divergent.
* docs/whatsapp-n8n-setup.md complete : envoi des identifiants a la validation, role du champ send_credentials, route de rattrapage.

= 6.13.0 =
* URL des annonces incluant la ville et le quartier : /annonce/casablanca/maarif/mon-bien/ au lieu de /property/mon-bien/.
* Toutes les anciennes adresses sont redirigees en 301 : aucune position acquise n'est perdue.
* Une annonce accessible par plusieurs chemins est ramenee a son URL canonique, pour ne pas diviser le signal SEO.
* Sitemap, balise canonical et JSON-LD utilisent la nouvelle adresse.
* Identifiants : l'annonceur recoit desormais son mot de passe sur WhatsApp, consultable a tout moment dans sa messagerie, au lieu d'un lien a usage unique.
* Le mot de passe evite les caracteres ambigus (O/0, I/l/1) pour etre recopie sans erreur depuis un telephone.
* Une revalidation d'annonce ne reinitialise plus le mot de passe d'un annonceur deja actif.
* Nouveau bouton « Nouveau mot de passe » pour un annonceur ayant perdu son message WhatsApp.

= 6.12.0 =
* Favoris : le coeur devient rouge au clic, reste rouge apres rechargement, et redevient vide au retrait. Suppression d'un second gestionnaire de clic qui annulait le premier.
* Nouvel ecran d'administration « Valider les annonces » : publication en un clic, avec le nom, le telephone et le code WhatsApp de l'annonceur.
* A la validation, n8n recoit le nom d'utilisateur et un lien securise de definition du mot de passe (48 h, usage unique). Aucun mot de passe en clair n'est transmis ni stocke.
* Route de rattrapage GET /wp-json/partikulier/v1/approved-listings pour les validations des 72 dernieres heures si le webhook a echoue.

= 6.11.0 =
* Nouveau : page Favoris reelle (le coeur du header menait a l'archive)
* Nouveau : diagnostic de tout le site en un clic

= 6.10.1 =
* Versions alignees entre style.css, package.json et readme.txt
* Photos refusees : message explicite au lieu d'un echec silencieux
* HEIC propose uniquement si le serveur sait le convertir

= 6.10.0 =
* Correction : l'upload de photos etait annule par un ancien gestionnaire JavaScript
* Nouveau : ecran Partikulier > Diagnostic des pages (controle page par page)
* Nouveau : message WhatsApp personnalisable avec variables {code} {titre} {ville} {prix} {lien} {nom}
* Correction : bouton favoris — suppression de l'icone redondante qui captait le clic

= 6.9.0 =
* Nouveau : assistant Partikulier > Mise a niveau, trois etapes verrouillees dans l'ordre

= 6.8.0 =
* Nouveau : chaque annonce existe en francais, anglais et arabe, pages liees par hreflang
* Correction : les URL traduites renvoyaient une 404 (regles de reecriture)
* Correction : balises hreflang en double entre Polylang et le theme

= 6.7.0 =
* Le site est reserve aux proprietaires : les agents immobiliers sont refuses
* Suppression du role "mandataire"

= 6.6.0 =
* Lieux verrouilles : plus aucune ville creee sans validation administrateur
* SEO : meta description calibree, textes alternatifs varies, texte enrichi

= 6.5.0 =
* Parcours de depot en 3 etapes avec apercu automatique de l'annonce
* Autocompletion ville puis quartier (30 villes, environ 280 quartiers)

= 6.4.0 =
* Creation automatique des pages "Deposer une annonce" et "Mes annonces"
* Prix affiches en MAD

= 1.3.0 =
* Panneau "Partikulier — Textes du site" dans Apparence > Personnaliser
* Purge automatique du cache apres modification des textes

= 1.2.0 =
* Premiere version
