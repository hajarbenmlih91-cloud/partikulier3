# Démo Partikulier — 30 annonces avec photos

**But : voir toutes les erreurs d'affichage en 1 clic, sans passer par le formulaire.**

## Installation en 30 secondes (recommandé)

1. Installez dans l'ordre : **Estatik** (v4.3.x) → **Plugin Partikulier Core** `B-INSTALLER-PLUGIN-partikulier-core-2.10.10.zip` → **Thème Partikulier** `partikulier-theme-6.20.9.zip` (ou suivant). Activez le plugin avant le thème.
2. Allez dans **Outils > Démo Partikulier** (ou **Apparence > Démo Partikulier**).
3. Cliquez **Installer la démo (30 annonces + photos)**.
4. C'est en ligne : accueil, `/annonces/`, fiches bien, filtres, traductions FR/EN/AR.

Le bouton purifie d'abord l'ancienne démo (si elle existe), importe les 30 photos AVIF de `demo/images/` dans la médiathèque, crée les 30 annonces via le même pipeline que le formulaire (`Partikulier_Listing_Preview` + `Partikulier_Listing_I18n`), les publie en `actif` (pas de blocage WhatsApp), crée les traductions Polylang et purge le cache.

## Ce qui est créé

- **30 annonces publiées** : Casablanca, Rabat, Marrakech, Tanger, Agadir et Fès — 8 types (appartement, villa, studio, maison, duplex, riad, terrain, immeuble) — **15 ventes / 15 locations** — chaque annonce a **3 photos** piochées cycliquement dans le pool.
- **Photos** : 30 AVIF fournis dans `demo/images/` (chambres, salons, villas piscine), importés comme vrais attachments WordPress avec métadonnées, marqués `_pk_seed_demo=1`.
- **Taxonomies** : `es_type`, `es_status` (A vendre / A louer), `es_location` — créées en `fr` et liées à leurs traductions `-en`/`-ar` si elles existent (évite les doublons `appartement-en`).
- **Traductions** : **Si Polylang FR/EN/AR est configuré, chaque annonce est traduite automatiquement** via `Partikulier_Listing_Translations::sync()` → 30 FR + 30 EN + 30 AR = **90 fiches** au total, avec hreflang, termes traduits et `es_latitude/longitude`. **Sans Polylang, seules 30 annonces FR sont créées.**
- **Marque** : chaque post et chaque attachment porte `_pk_seed_demo=1` → suppression propre en 1 clic.

## Vérifications rapides après install

- **Accueil** `/` : cartes d'annonces (badge À vendre / Louer, prix fond blanc, pastille photo)
- **Catalogue** `/annonces/` + filtres : `?es_city=casablanca`, `?es_type=villa`, `?es_action=a-louer` (location) vs `?es_action=a-vendre` (vente)
- **Fiche bien** : galerie plein écran sans marges noires, flèches ← →, compteur `1/3`, fil d'Ariane 1 ligne, métriques sans coupure, bloc WhatsApp
- **Traductions** : `/en/annonces/`, `/ar/annonces/` → si Polylang actif, 30 fiches par langue

## Purge

- **Outils > Démo Partikulier > Purger la démo** — supprime les 30 annonces (toutes langues) + les photos importées.
- Alternative WP-CLI historique (10 annonces) : `PK_PURGER=1 PK_APPLIQUER=1 wp eval-file wp-content/themes/partikulier/tests/seed-annonces-test.php`

## Fichiers

- `demo/images/*.avif` — 30 photos de démo (incluses dans le thème, donc dans le zip)
- `inc/class-demo-installer.php` — UI admin + logique de seeding 30 annonces (1-clic)
- `tests/seed-annonces-test.php` — seeder historique CLI 10 annonces (conservé)

> N'installez pas la démo en production avec de vraies annonces : purgez-la avant l'ouverture (`Purger la démo`).
