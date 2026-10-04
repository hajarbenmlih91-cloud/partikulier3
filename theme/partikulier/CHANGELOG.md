# Journal des Modifications (Changelog) — Thème Partikulier

Toutes les modifications notables apportées au thème Partikulier sont consignées dans ce document.
Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

---

## [6.20.11] - 2026-10-03 22:58 (Hotfix sécurité — chiffrement `_pk_owner_phone`)

### Sécurité
- **`inc/class-form.php:557`** : `update_post_meta($post_id,'_pk_owner_phone', Partikulier_Crypto::encrypt_phone($phone))` — tout nouveau bien chiffré `gcm:v1:` (AES-256-GCM, `wp_salt('secure_auth')`). Lecture via `Partikulier_Crypto::read_phone()` zéro migration.
- **`inc/class-crypto.php`** : `encrypt_phone()` / `decrypt_phone()` (GCM + fallback CBC) / `read_phone()` / `mask_phone()` (gate `manage_options`).
- Bumps `style.css 6.20.10→6.20.11`, `functions.php`, `scripts/package.sh`.

## [6.20.10] - 2026-10-03 (R3 — Messages & Limites éditables + prefill)

### Ajouté
- **`inc/class-buyer-qualification.php:22 l.`** : bouton WhatsApp pré-rempli via `LeadSettings::get_prefill($lang)` + `str_replace({reference},{lien})` + `urlencode`. Éditable WP sans toucher au thème.

## [6.20.9] - 2026-10-03 (Release Senior - Pack 1-4)

### Corrigé
- **Accessibilité WCAG 2.2 AA** : carte contact sombre contraste `kicker #9b6a3d→#b0a89e` (3.87→7.66), `legal`/`small` `rgba .45→.75` (4.49→9.67), `city` `#9b6a3d→#b0a89e`, `owner span` `.6→.75`; 0 violation axe, Lighthouse 96→98.
- **Perf SLO doc** : infra prod recommandée Litespeed `lsphp84` + opcache + Redis `object-cache.php` + CDN ( `php -S` mono-thread p95 896ms non représentatif).

### Technique
- Bump `6.20.8→6.20.9` aligné plugin `2.10.9`.

## [6.20.7-FINAL] - 2026-09-30 (Homologation Complète Sécurité, Invariants & Auth)

### Ajouté
- **Passerelle d'Administration Secrète (Stealth Admin Gateway)** : Masquage total de `wp-login.php` et `/wp-admin/` pour les robots, curieux et scanners externes (redirection HTTP 302 immédiate vers la page d'accueil). Accès réservé à l'administrateur via l'URL secrète `https://partikulier.ma/wp-login.php?pk_admin_key=direction2026` (ou paramètre `?pk_direction=direction2026`). Dépose un cookie sécurisé de 2 heures (`pk_admin_access`). Clé personnalisable via `define('PK_ADMIN_SECRET_KEY', '...')` dans `wp-config.php`. Compatibilité 100% assurée avec le bouton de connexion 1-clic Hostinger hPanel grâce à la liste blanche des paramètres SSO.
- **Authentification Propriétaire par Numéro de Téléphone Portable (Cas "Grand-mère")** : Possibilité de déposer une annonce sans renseigner d'adresse e-mail. Attribution automatique du numéro de téléphone comme identifiant (`user_login`), avec stockage de l'e-mail technique interne `{tel}@partikulier.local`. Sur la page `/connexion/`, le propriétaire peut se connecter indifféremment avec son numéro local (`06...`), international (`+212...`) ou formaté avec espaces.
- **Unification des Erreurs de Connexion (Anti-Énumération OWASP WSTG-INFO-04)** : Remplacement de tous les messages différentiés de WordPress par le libellé neutre *"Identifiant ou mot de passe incorrect. Mot de passe oublié ?"*, neutralisant le moissonnage d'adresses ou de numéros.
- **Rate Limiter Composite Spécial Réseaux Mobiles Marocains (CGNAT)** : Limitation des tentatives de mot de passe basée sur le couple `IP + Compte visé` (5 tentatives / 15 minutes). Protège contre le brute-force sans bloquer les autres utilisateurs partageant la même IP publique sur les antennes 4G/5G Maroc Telecom, Inwi ou Orange.
- **Option B (100% WhatsApp Strict / SIM-07)** : Remplacement complet du formulaire web par le contact direct propriétaire par clic WhatsApp sécurisé sur toutes les fiches publiques singulières.
- **Constructeur de Champs SE-042c** : Gestion dynamique des caractéristiques par type de bien avec plafonnement strict à 8 champs maximum, rejet côté serveur en cas de dépassement et alignement responsive mobile/tablette sur la maquette.
- **Statut Premium Public à 0 MAD (SE-048-R)** : Activation immédiate du statut Premium offert pour le lancement (`pk_premium_public_enabled = 1`), affichage du bandeau informatif en 3 langues et purge automatique du cache à l'expiration.
- **Cycle de Vie & SEO Fiches Clôturées (SE-054)** : Maintien en ligne des annonces vendues ou désactivées en HTTP 200 (préservation SEO Google), filigranes dédiés (*Vendu*, *Loué*, *Indisponible*), masquage des coordonnées et affichage systématique de 3 annonces similaires actives avec redirection WhatsApp sur toutes les annonces.
- **Moteur Toponymique Bilingue (SE-036 / DP-5)** : Normalisation `strip_alif_lam` (`صويرة` $\leftrightarrow$ `الصويرة`), réconciliation des requêtes franco-arabes, autocomplétion Hero dès la 1ère lettre et contraste strict vérifié (WCAG AA).
- **Routage Trilingue & RTL (SE-025 / SE-027)** : Prise en charge native du français, anglais et arabe littéraire avec inversion RTL automatique (`dir="rtl"`, `lang="ar"`), cluster hreflang 4 entrées et réécriture v5 sans double-préfixation.
- **Traçabilité CI WebKit** : Intégration documentée de `npx playwright install --with-deps webkit` pour la couverture complète Safari / iOS.

### Corrigé
- **Sécurité d'Exécution Système (SE-002)** : Suppression de l'appel direct `@exec` dans `inc/class-cache.php` au profit de suppressions récursives natives PHP du cache Nginx, rétablissant la conformité absolue avec la passerelle unique `class-exec-whitelist.php`.
- **Performance Front-Assets (DP-6 / SE-018)** : Application d'un déréférencement double (`wp_dequeue_script` + `wp_deregister_script`) sur 17 poignées d'assets lourds Estatik/jQuery sur toutes les pages publiques, avec exception documentée confinée aux pages propriétaires (`/deposer/`, `/wp-admin/`).
- **Couture des Transitions d'Annonces (DP-9)** : Sécurisation de l'appel conditionnel aux méthodes de variantes pour éliminer toute erreur 500 lors du passage d'une annonce en statut vendu ou désactivé.
- **Isolation des Fixtures de Test** : Exclusion des fixtures préfixées (`SE-`, `B3A-`, `banc-`) des blocs d'annonces similaires et de l'annuaire public.

---

## [6.20.7-r6] - 2026-09-27
- Refonte des règles de réécriture Polylang v5 sans collision de préfixe.
- Redirection 301 pérenne de l'ancien endpoint `/property/` vers `/fr/annonces/`.
- Durcissement XML-RPC (SE-041) et protection contre les injections de tableaux scalaires (E-5105).

## [6.20.7-r5] - 2026-09-27
- Intégration de la toponymie arabe et filtrage géographique avancé.
- Validation des contrastes sur la barre de recherche Hero.

## [6.20.7-r4] - 2026-09-27
- Déploiement du Statut Premium public à 0 MAD.
- Mise en conformité du bandeau de lancement en français, anglais et arabe.

## [6.20.7-r3] - 2026-09-26
- Intégration du constructeur de champs personnalisés SE-042c (plafond 8 champs max).
- Suppression du formulaire web sur fiches singulières (Option B SIM-07 100% WhatsApp).

## [6.20.7-r2] - 2026-09-26
- Correction de la couture `class-listing-transitions.php` avec Partikulier Core 2.10.8.
- Validation des 5 suites de cohérence et de peremption LiteSpeed Cache.

## [6.17.35] - 2026-08-25
- Zéro jQuery initial sur pages éditoriales.
- Cache de page intégré et conversion AVIF automatique des images.
