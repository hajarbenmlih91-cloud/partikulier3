# Thème Partikulier

Thème WordPress de portail immobilier pour **Partikulier.ma** : annonces immobilières entre particuliers au Maroc.
Zéro jQuery sur le parcours public (exception documentée DP-6 option a sur les parcours propriétaires : galerie, filtres, sélection, upload), cache de page intégré (LiteSpeed / Nginx), conversion AVIF automatique des images, métadonnées Schema.org JSON-LD complètes (*RealEstateListing*, *Place*, *Offer*, *GeoCoordinates*), sitemap XML virtuel, permaliens géographiques canoniques (ville, quartier, région) et optimisation PageSpeed mobile cible 95-100/100.
Conçu pour **Estatik 4.3.x**, avec intégration complète **Polylang** trilingue (FR / AR / EN) et support RTL natif.

**Version : 6.20.7 (Release Finale)** · Licence GPL v3 ou ultérieure · Requiert WordPress 6.2+ et PHP 8.1 à 8.4+.

---

## 1. Fonctionnalités Majeures & Invariants Homologués

- **100% WhatsApp Strict (Arbitrage SIM-07 Option B)** : Suppression définitive du formulaire email web sur les fiches singulières ; contact propriétaire direct et exclusif via clic WhatsApp sécurisé pour capter des numéros qualifiés.
- **Constructeur de Champs Personnalisés (SE-042c)** : Plafonnement strict à **8 caractéristiques maximum** par type de bien afin de préserver l'harmonie visuelle ; contrôle strict côté serveur et adaptation responsive mobile et tablette identique à la maquette.
- **Statut Premium Public Actif à 0 MAD (SE-048-R)** : Offre de lancement gratuite pour maximiser l'adoption, bandeau d'information public en 3 langues et purge automatique du cache à l'expiration.
- **Cycle de Vie & Conservation SEO des Annonces Clôturées (SE-054)** : Les annonces vendues, louées ou désactivées restent en ligne en code HTTP 200 (URL pérenne pour Google SEO) avec filigrane dédié (*Vendu*, *Loué*, *Indisponible*), coordonnées masquées et 3 annonces similaires actives avec bouton WhatsApp.
- **Référentiel Toponymique Marocain Bilingue (SE-036 / DP-5)** : Normalisation orthographique `strip_alif_lam` (`صويرة` $\leftrightarrow$ `الصويرة`, `saouira` $\leftrightarrow$ `essaouira`), options de quartiers hybrides dans l'admin, autocomplétion Hero dès la 1ère lettre et contraste élevé garanti.
- **Architecture Multilingue Trilingue & RTL (SE-025 / SE-027)** : Prise en charge intégrale du français, anglais et arabe littéraire avec inversion RTL native (`dir="rtl"` et `lang="ar"`), cluster hreflang 4 entrées (`fr`, `en`, `ar`, `x-default`), et règles de réécriture v5 sans double-préfixation (`/fr/annonces/`).
- **Performance Front-Assets « Zéro jQuery » Public (DP-6 / SE-018)** : Élimination absolue de tout script jQuery ou composant lourd Estatik sur l'ensemble des pages publiques (`/`, `/annonces/`, `/location/casablanca/`, `/faq/`, `/contact/`, `/connexion/`).
- **Sécurité d'Exécution Système (Lot E / SE-002)** : Zéro appel `exec()` direct hors de la passerelle unique `class-exec-whitelist.php` (vérifié par analyse lexicale sur 141 fichiers runtime).
- **Passerelle d'Administration Secrète (Stealth Admin)** : Masquage total de `wp-login.php` et `/wp-admin` aux visiteurs non autorisés (redirection 302 vers l'accueil). Lien secret officiel : `https://partikulier.ma/wp-login.php?pk_admin_key=direction2026`.
- **Authentification Propriétaire par Numéro Mobile (Cas "Grand-mère")** : Dépôt sans e-mail possible, connexion directe par numéro de téléphone portable (`06...` ou `+212...`).
- **Protection Anti-Énumération & Rate Limiting CGNAT** : Unification des messages d'erreur et protection anti-brute force composite sans blocage collatéral sur les réseaux 4G/5G marocains.

---

## 2. Accès Administrateur & Passerelle Secrète (Stealth Admin Gateway)

Pour protéger le site contre les attaques de force brute et les scanners de vulnérabilités WordPress, l'accès direct aux URLs standards `/wp-admin/` et `/wp-login.php` est **strictement bloqué** pour le public (redirection automatique vers l'accueil `/`).

### Comment accéder à l'administration WordPress ?
1. **URL Secrète Officielle pour l'Administrateur** :  
   👉 `https://partikulier.ma/wp-login.php?pk_admin_key=direction2026`  
   *(Alternative acceptée : `https://partikulier.ma/wp-login.php?pk_direction=direction2026`)*
2. **Fonctionnement du jeton** :  
   Dès la saisie de ce lien, un cookie sécurisé temporaire (`pk_admin_access`, durée 2h) est déposé sur votre navigateur. L'écran de connexion s'affiche et vous pouvez vous connecter avec vos identifiants administrateur.
3. **Personnalisation de la clé secrète** :  
   Pour modifier la clé par défaut (`direction2026`), ajoutez simplement cette ligne dans votre fichier `wp-config.php` :
   ```php
   define( 'PK_ADMIN_SECRET_KEY', 'votre_nouvelle_cle_secrete' );
   ```
4. **Accès via Hostinger hPanel (Bouton "Admin Panel" 1-clic)** :  
   Le système embarque une liste blanche automatique des paramètres d'authentification SSO Hostinger (`token`, `hostinger_login`, `hpanel`, `sso_token`). Le bouton d'accès direct depuis votre espace client Hostinger reste **100% fonctionnel** et n'est pas bloqué.

---

## 3. Authentification Propriétaire par Numéro de Téléphone (Cas "Grand-mère")

Les propriétaires n'ont pas besoin de disposer ou de se souvenir d'une adresse e-mail :
- **Au dépôt** : Le champ e-mail est facultatif. Le système associe le compte au numéro de téléphone portable (`pk_phone`) et configure son identifiant principal sur ce numéro.
- **À la connexion (`/connexion/`)** : Le propriétaire saisit son numéro de téléphone (`06...`, `+212...`, ou formaté avec espaces) et son mot de passe. Le résolveur d'authentification normalise le numéro et connecte l'utilisateur instantanément.

---

## 4. Intégration CI & Tests Navigateurs Réels (WebKit / Chromium / Firefox)

Pour la recette multi-navigateurs E2E Playwright et la conformité Safari / iOS, le runner CI doit installer les dépendances WebKit :

```bash
# Installation des navigateurs pour les tests E2E multi-moteurs (Chromium, Firefox, WebKit/Safari) :
npx playwright install --with-deps webkit
npx playwright install chromium firefox
```

### Exécution des Suites de Tests Front-End
```bash
# Contrat de performance et conformité front-assets (zéro jQuery) :
PK_BASE=http://127.0.0.1:8080 node tests/front-assets.mjs

# Tests de régression visuelle et responsive (12 vues) :
npm test
```

---

## 3. Installation & Déploiement

1. Téléverser l'archive `THEME-PARTIKULIER-6.20.7-FINAL.zip` dans **Apparence $\rightarrow$ Thèmes $\rightarrow$ Ajouter $\rightarrow$ Téléverser**.
2. Activer le thème.
3. Se rendre dans **Réglages $\rightarrow$ Permaliens** et cliquer sur **Enregistrer les modifications** pour régénérer la table de réécriture Polylang v5.
4. Purger le cache LiteSpeed / Nginx.
