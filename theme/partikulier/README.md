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

---

## 2. Intégration CI & Tests Navigateurs Réels (WebKit / Chromium / Firefox)

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
