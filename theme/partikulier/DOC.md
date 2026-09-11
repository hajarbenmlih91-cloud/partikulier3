# Partikulier — documentation du thème

**Version 6.19.1 · septembre 2026**

> Ce fichier remplace une documentation qui décrivait la version 1.2.0 d'origine et était devenue trompeuse (palette orange, 11 modules, envoi d'e-mail de confirmation — plus rien de tout cela n'est exact).

---

## Ce que fait le thème

Partikulier est un portail immobilier marocain **réservé aux propriétaires particuliers**. Les agences et agents immobiliers sont refusés : c'est le positionnement du produit.

Le parcours complet :

1. Un propriétaire dépose son annonce depuis le site, en 3 étapes, **sans créer de compte**.
2. L'annonce part en attente de validation. Si la ville ou le quartier n'existent pas au référentiel, la proposition part aussi en modération.
3. Un administrateur valide depuis **Partikulier › Valider les annonces**.
4. L'annonce est publiée **dans les trois langues simultanément** (français, arabe, anglais).
5. n8n reçoit l'identifiant et le mot de passe de l'annonceur et les lui envoie sur WhatsApp.

Le thème dépend d'**Estatik** et s'appuie sur **partikulier-core** (plugin métier du projet) quand il est actif : depuis la version 6.18.8, la rédaction multilingue des annonces (lexique fr/en/ar, titres, descriptions, metas SEO) lui est déléguée — et depuis 6.18.9, la résolution des chaînes du chrome et des formulaires (filtre gettext + dictionnaires) est servie par son service unifié `I18nChromeService` — le thème conserve son chemin autonome intégral sinon. Tout le reste est intégré : SEO, cache, conversion AVIF, données structurées, traductions, formulaire, tableaux de bord.

---

## Installation

1. Uploader le zip dans *Apparence › Thèmes › Ajouter*, puis activer. Les pages obligatoires (`deposer`, `mes-annonces`, `favoris`) sont créées automatiquement.
2. Installer et activer **Estatik**.
3. *Réglages › Permaliens › Enregistrer*, sans rien modifier. **Étape obligatoire** : sans elle, les URL d'annonces peuvent renvoyer des 404.
4. *Apparence › Personnaliser › Validation WhatsApp* : renseigner le numéro. **Sans numéro, le formulaire refuse tous les dépôts.**
5. Optionnel : URL du webhook n8n et secret d'automatisation, sur le même écran.

Pour vérifier l'installation : **Partikulier › Diagnostic des pages › Analyser tout le site**.

---

## Les écrans d'administration

| Menu | Rôle |
| --- | --- |
| **Partikulier** | Accueil du thème |
| **Personnalisation du site** | Textes et libellés sans toucher au code |
| **Lieux proposés** | Modération des villes et quartiers proposés par les annonceurs |
| **Valider les annonces** | Publication des dépôts, génération et envoi des identifiants |
| **Mise à niveau** | Assistant de migration pour les sites déjà en production |
| **Diagnostic des pages** | Vérification page par page ou du site entier |
| **Leads WhatsApp** | Demandes acquéreurs qualifiées |

---

## Structure des URL

```
/annonce/casablanca/maarif/appartement-lumineux/     annonce avec quartier
/annonce/rabat/studio-calme/                          annonce sans quartier
```

Les anciennes adresses `/property/…` redirigent en **301**. Une annonce atteinte par un chemin non canonique est également redirigée, ce qui évite qu'elle existe sous plusieurs adresses (contenu dupliqué).

---

## Fonctionnement du SEO

- Accroche déterministe en tête de description, mention « particulier à particulier » en clôture.
- Meta description calibrée entre 140 et 155 caractères.
- `alt` riche sur la photo principale, variantes courtes sur les suivantes (≤ 125 caractères).
- JSON-LD `RealEstateListing`, `ItemList`, `BreadcrumbList`, `WebSite` + `SearchAction`.
- `sitemap.xml` et `robots.txt` générés par le thème.
- **Aucune variation aléatoire** : deux annonces identiques produisent le même texte.

---

## Multilingue

Les versions arabe et anglaise sont composées **par gabarit**, sans API de traduction payante. Le texte libre écrit par l'annonceur est recopié tel quel — une traduction machine approximative ferait plus de mal que de bien. Les trois versions sont publiées ensemble. Depuis 6.18.8, le lexique et les générateurs sont servis par le service `I18nContentService` de partikulier-core 2.7+ (couture `class_exists`, repli autonome à l'identique) et le monolithe `class-listing-i18n.php` est découpé en cinq traits de 300 lignes maximum. Depuis 6.18.9 (lot C2), la résolution des chaînes du chrome et des formulaires (ordre figé : .mo > dictionnaire form > dictionnaire chrome > Polylang) est servie par le service unifié `I18nChromeService` de partikulier-core 2.8+ : le filtre gettext du plugin devient LE mécanisme actif, le thème éteint le sien et lui fournit son registre chrome (donnée du thème) ; les dictionnaires thème sont dormants (repli sans plugin, retrait physique au lot C4).

Les catalogues gettext du thème sont `languages/ar.mo` et `languages/en_US.mo` (le JIT de WordPress charge ce nommage pour un thème) ; les doublons `partikulier-*.mo` ont été supprimés au lot C1 et les en-têtes recompilés sous forme canonique lisible par les deux lecteurs gettext de WordPress.

Les traductions générées portent la méta `_pk_auto_translation` et sont exclues des listes d'administration.

---

## Performance

- Conversion AVIF automatique à l'envoi. L'original est **conservé** : il sert de repli `<picture>` et permet la régénération des miniatures.
- Cache de page fichier (HTML, Gzip, Brotli) géré par le thème.
- JavaScript vanilla, **zéro jQuery**.
- DM Sans auto-hébergée dans `assets/fonts/`.

---

## Documentation destinée aux développeurs

La documentation technique complète (architecture, décisions de conception, pièges connus, chantiers restants, procédures de test) est fournie séparément dans le **dossier de reprise `dev-partikulier/`**. Elle est indispensable avant toute modification du code : plusieurs choix du thème paraissent être des erreurs quand on ne connaît pas leur raison d'être.

Voir aussi `docs/whatsapp-n8n-setup.md` pour le détail du pont n8n et `docs/leads-admin-guide.md` pour les leads acquéreurs.
