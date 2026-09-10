# Décisions et justifications

**Lis ce document avant de modifier quoi que ce soit.** Chaque point ci-dessous est un choix délibéré. Plusieurs ressemblent à des erreurs quand on découvre le code. Si tu veux revenir sur l'un d'eux, tu sauras au moins ce que tu casses.

---

## Produit

### Le site est réservé aux propriétaires

Les agences et agents immobiliers sont refusés. C'est le positionnement du produit, pas une limitation technique. Le formulaire propose « Propriétaire » ou « Agent immobilier », et le second est bloqué à l'inscription. `_pk_owner_role` vaut donc toujours `proprietaire`.

### Le mot « mandataire » est banni

Un rôle « mandataire » a existé puis a été entièrement supprimé en v6.7.0. Le terme ne correspond pas au vocabulaire immobilier marocain. Ne le réintroduis pas, même dans un commentaire.

### Le logo est textuel

Consigne explicite et répétée du client : du texte, jamais une image, et sans symbole. Ce n'est pas un placeholder en attendant un vrai logo.

### Pas de compte à créer pour déposer

Le visiteur remplit le formulaire, le compte est créé en coulisse. Une inscription préalable ferait chuter le nombre de dépôts. Le compte ne devient utile qu'après validation, quand l'annonceur reçoit ses identifiants.

---

## URL et SEO

### Les URL contiennent la ville et le quartier

```
/annonce/casablanca/maarif/appartement-lumineux/
```

Les gens cherchent « appartement à vendre Maarif Casablanca ». Mettre la géographie dans le chemin concentre le signal au lieu de le disperser. Le préfixe est `annonce`, pas `property` : le site est francophone.

### Toutes les anciennes URL redirigent en 301

Changer d'URL sans redirections, c'est perdre son référencement acquis. `Partikulier_Listing_URLs::redirect_legacy()` compare le chemin demandé au chemin canonique et redirige en 301 si besoin.

**Effet de bord voulu :** une URL avec une mauvaise ville (`/annonce/nawak/mon-bien/`) redirige aussi. Sans cela, la même annonce serait accessible sous une infinité d'adresses répondant toutes 200 — du contenu dupliqué qui divise le signal SEO. Ce n'est pas de la paranoïa : les robots explorent ce genre de variantes.

### La géographie de l'URL est figée dans des métas

`_pk_url_city` et `_pk_url_district` sont écrites au moment de l'enregistrement. Si un administrateur renomme un terme six mois plus tard, **les URL déjà indexées ne bougent pas**. Un site dont les adresses changent toutes seules perd son référencement.

### `es_location` est une taxonomie plate

Estatik ne la déclare pas hiérarchique : « Casablanca » et « Maarif » y coexistent sans lien parent-enfant. Pour savoir de quelle ville dépend un quartier, le code interroge le référentiel de `class-morocco-places`, qui contient la table ville → quartiers. C'est le rôle de `city_of_district()`.

### La variation SEO est déterministe

Deux annonces identiques doivent produire exactement le même texte. Aucun `rand()` dans la génération de contenu. Un texte qui change à chaque affichage est un signal de mauvaise qualité et rend le débogage impossible.

### Les `alt` ne sont pas bourrés de mots-clés

La photo principale reçoit un `alt` riche et descriptif. Les suivantes reçoivent des variantes courtes. Répéter les mêmes mots-clés partout est contre-productif. Limite : 125 caractères.

---

## Identifiants et n8n

### Le mot de passe est envoyé en clair sur WhatsApp

**Ce point a changé d'avis en cours de route — lis-le en entier avant d'y toucher.**

La première implémentation envoyait un lien de définition de mot de passe à usage unique, valable 48 h, avec jeton haché en base. Techniquement plus sûr.

Le client l'a refusé pour une raison d'usage : il veut que l'annonceur **retrouve ses identifiants à tout moment dans sa conversation WhatsApp**, sans dépendre d'un lien expiré. La v6.13.0 envoie donc l'identifiant et le mot de passe en clair dans le message.

Le risque est assumé et documenté : le mot de passe reste lisible dans WhatsApp indéfiniment. **Côté site il n'y a pas de faille** — WordPress ne stocke que l'empreinte. Le risque porte sur le téléphone de l'annonceur.

Si le sujet revient, le compromis identifié est : envoyer le mot de passe **et** inviter à le changer à la première connexion.

### Le mot de passe évite les caractères ambigus

Alphabet sans `O`/`0`, sans `I`/`l`/`1`. Un mot de passe recopié depuis WhatsApp sur un clavier de téléphone doit passer du premier coup. Voir `readable_password()`.

### Une revalidation ne réinitialise pas le mot de passe

`prepare_credentials()` vérifie `_pk_credentials_sent`. Si l'annonceur a déjà reçu ses accès, le mot de passe **n'est pas régénéré** et le payload part avec `send_credentials: false`.

Sans cette garde, repasser sur une annonce déjà publiée enfermerait dehors un annonceur actif depuis des semaines — ou qui a changé son mot de passe lui-même. Pour forcer volontairement, il y a le bouton « Nouveau mot de passe » (`prepare_credentials( $id, true )`).

### n8n est notifié par webhook **et** par route de lecture

Les deux, pas l'un ou l'autre. Le webhook sortant part immédiatement à la validation. Si n8n était indisponible, la route `GET /wp-json/partikulier/v1/approved-listings` renvoie les validations des 72 dernières heures avec un drapeau `webhook_sent` permettant de ne traiter que ce qui a été manqué.

**La route ne renvoie jamais de mot de passe.** Un identifiant interrogeable pendant 72 h annulerait l'intérêt de la confidentialité. En cas d'échec, l'admin utilise le bouton de renvoi.

### `wp_http_validate_url()` a été contourné volontairement

Cette fonction WordPress refuse les adresses IP locales. C'est une bonne protection en production, mais elle bloque un n8n auto-hébergé sur le même serveur — cas réel chez ce client. Le code valide donc la forme de l'URL (schéma http/https + hôte présent) sans interdire les adresses locales.

---

## Formulaire et dépôt

### Le numéro WhatsApp est obligatoire

Sans numéro configuré, le formulaire **refuse tous les dépôts**. C'est volontaire : une annonce déposée sans circuit de validation resterait bloquée sans que personne ne le sache. Mieux vaut un refus explicite qu'un silence.

Piège de débogage : le message affiché est « dépôt momentanément indisponible », qui ne mentionne pas WhatsApp. C'est la première chose à vérifier quand les dépôts échouent.

### Les lieux inconnus ne sont pas créés automatiquement

Si un annonceur saisit une ville ou un quartier absent du référentiel, **rien n'est créé**. La proposition part en modération (`class-place-requests`) et l'annonce reste hors ligne en attendant. Sans cette règle, la taxonomie se remplirait de doublons et de fautes de frappe.

Conséquence visible dans l'écran de validation : le bouton « Publier » est remplacé par « Validez d'abord le lieu ».

### Le parcours en 3 étapes a son propre JavaScript

`submit-steps.js` gère la page de dépôt. `main.js` s'y neutralise volontairement :

```js
if (document.querySelector('.pk-steps')) return;
```

Les deux fichiers coexistent sur cette page. Sans cette garde, ils se marchent dessus — c'est exactement ce qui avait cassé l'upload de photos en v6.10.

---

## Multilingue

### Traduction par gabarit, pas par API

Pas d'API de traduction payante. Les versions arabe et anglaise sont composées à partir de gabarits et du vocabulaire structuré de l'annonce (type, ville, surface, pièces). Le résultat est prévisible et gratuit.

### Le texte libre de l'annonceur est recopié tel quel

Le paragraphe personnel n'est pas traduit automatiquement. Une traduction machine approximative ferait plus de mal que de bien. Il est copié à l'identique dans les versions AR et EN.

### Les trois langues sont publiées en même temps

Pas de publication décalée. `Partikulier_Listing_Translations::sync_status()` est appelée à la validation.

### Les traductions sont exclues des écrans d'administration

Les annonces générées portent `_pk_auto_translation`. Toutes les listes admin les filtrent, sinon chaque annonce apparaîtrait en triple. Pense à ce filtre si tu ajoutes une liste.

---

## Interface

### Le cœur des favoris utilise une classe CSS, pas des styles en ligne

L'état actif est porté par `.pk-wish-active`, stylée dans la feuille. Une version précédente écrivait `style.fill` en JavaScript, ce qui écrasait le CSS et rendait le thème immodifiable. Ne réintroduis pas de style en ligne ici.

### Les favoris sont dans le navigateur

`localStorage`, clé `pk_wishlist`. Aucun compte requis, donc **les favoris ne suivent pas d'un appareil à l'autre** — c'est une limite connue et acceptée. Un compteur serveur anonyme (`visitor_hash`, purge à 90 jours) existe uniquement pour les statistiques du propriétaire.

### Une page = une entrée en base

Pas de pages virtuelles. Les pages obligatoires sont créées à l'activation (`class-required-pages`) et leur absence est signalée par le diagnostic. Une approche « page virtuelle » avait été envisagée puis rejetée : invisible dans l'admin, impossible à personnaliser, déroutante pour le client.

### Les AVIF ne remplacent pas les originaux

La conversion crée `photo.jpg.avif` **à côté** de `photo.jpg`. L'original n'est jamais supprimé : il sert de repli `<picture>` pour les navigateurs sans AVIF et permet la régénération des miniatures. Cela consomme plus de disque, en échange de 50 à 70 % de poids transféré en moins.

---

## Design

- Accent **`#9b6a3d`** (cognac), encre `#161715`. L'orange `#f85525` de la version d'origine a été abandonné.
- **DM Sans auto-hébergée** dans `assets/fonts/`, pour satisfaire la politique de sécurité de contenu. Ne repasse pas par Google Fonts.
- Base éditoriale de `front-page.php` conservée ; 49 classes CSS mortes supprimées.
- Rouge des favoris : `#e0245e`, choisi pour trancher avec le cognac de la charte.
