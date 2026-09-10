# Pièges connus

Chacun de ces points a coûté du temps. Les lire évite de les repayer.

---

## Environnement

### Le numéro WhatsApp bloque tous les dépôts

**Symptôme :** le formulaire refuse toute annonce avec « Le dépôt d'annonce est momentanément indisponible ».
**Cause :** aucun numéro WhatsApp configuré.
**Correctif :** *Apparence › Personnaliser › Validation WhatsApp*. Le message ne mentionne pas WhatsApp — d'où le temps perdu.

### Les URL d'annonces renvoient 404 après installation

Les règles de réécriture n'ont pas été régénérées. *Réglages › Permaliens › Enregistrer*, sans rien modifier. Le thème le fait à l'activation, mais pas toujours en mutualisé.

Même cause pour les URL de contenus traduits : vérifier aussi `pll_is_translated_post_type()`.

---

## JavaScript

### Une classe posée par le JS n'implique aucun effet visuel

`.pk-wish-active` était correctement basculée mais **n'avait aucune règle CSS**. Le cœur ne changeait pas de couleur et on a cherché un bug JavaScript qui n'existait pas.

**Réflexe :** `grep` la classe dans la feuille de style avant de suspecter le JavaScript.

### Deux gestionnaires sur le même élément s'annulent

Le vrai bug du cœur : deux `addEventListener('click')` sur le même bouton. Le premier ajoutait le favori, le second le retirait. Résultat net : rien, et `localStorage` vide.

**Réflexe :** quand une action semble ne rien faire, chercher un second gestionnaire sur le même élément avant tout le reste.

### Le champ fichier se vide tout seul

Même famille de bug : `main.js` faisait `fileInput.value = ""` en doublon de `submit-steps.js`, ce qui cassait l'upload de photos.

**Technique de diagnostic efficace :** créer un `<input type="file">` témoin à la volée et comparer son comportement à celui du champ suspect.

**Signal Playwright :** un `setInputFiles` qui laisse `files.length = 0` sans lever d'erreur signale toujours du JavaScript qui réinitialise le champ.

---

## PHP et WordPress

### `esc_html_e( $variable )` est une erreur

Les fonctions de traduction attendent un **littéral**, pas une variable. Pour une chaîne calculée :

```php
echo esc_html( ma_fonction() );   // correct
esc_html_e( $chaine, 'partikulier' );  // faux
```

### `wp_http_validate_url()` refuse les adresses locales

Un webhook vers `http://127.0.0.1:9099` est rejeté sans message clair. C'est pour cette raison que `notify_n8n()` valide la forme de l'URL à la main. Pense-y avant de « corriger » ce code en réintroduisant la fonction native.

### `get_allowed_mime_types()` ne dit pas si le format est gérable

WordPress 7.0 autorise HEIC/HEIF, mais sans Imagick la conversion échoue **silencieusement**. Tester la capacité réelle : `extension_loaded('imagick')` puis `Imagick::queryFormats()`.

### `wp eval` casse sur les guillemets échappés

En heredoc, les échappements sautent. Écrire un fichier `.php` et utiliser `wp eval-file`. Attention : `eval-file` **ne transmet pas les arguments positionnels** — passer par une variable d'environnement (`PK_APPLIQUER=1`).

### `php -S` au premier plan bloque le terminal

`nohup php -S 0.0.0.0:8090 router.php >/dev/null 2>&1 &` puis vérifier avec `ss -ltn | grep :8090`.

---

## Données et taxonomies

### Le CPT est `properties`

Pas `estate_property`. De même, `es_property_type` et `es_property_action` n'existent pas : les vraies taxonomies sont `es_type`, `es_category`, `es_location`.

### `es_location` mélange villes et quartiers

Taxonomie plate : « Casablanca » et « Maarif » sont au même niveau, sans lien parent-enfant. Pour retrouver la ville d'un quartier, passer par le référentiel de `class-morocco-places`.

### Les tests répétés créent des doublons de termes

On voit apparaître « Agadir · Agadir ». Appliquer `array_unique` à l'affichage. Il existe aussi des doublons historiques en base (`a-louer` et `a-louer-2`).

### `hide_empty => true` masque les termes sans annonce

Cause classique de listes déroulantes vides sur un site neuf.

### Les variables globales de gabarits se marchent dessus

`card-property.php` ligne 36 écrase `$types`. Les globales `$types` et `$cities` sont partagées entre gabarits.

### Rejouer un dépôt avec le même nom échoue

Erreur « e-mail déjà utilisée ». Randomiser `pk_name` et `pk_email` dans les scripts de test.

---

## Tests et diagnostic

### Le sandbox de développement est purgé sans préavis

`wp/`, `node_modules`, Playwright et le binaire PHP disparaissent entre les sessions. Avant tout test : relancer `setup-stack.sh`, recréer `wp/router.php`, refaire le `rsync`, réinstaller Playwright.

### Toute édition doit être resynchronisée

Le code source est dans `theme/`, le site de test lit dans `wp/wp-content/themes/partikulier/`. Après chaque modification :

```bash
rsync -a --delete --exclude '.git' --exclude 'node_modules' \
  /home/user/theme/ /home/user/wp/wp-content/themes/partikulier/
```

Oublier ce `rsync` fait tester l'ancienne version — et conclure à tort que le correctif ne marche pas.

### L'admin WordPress génère des erreurs JS en serveur de test

Le tableau de bord natif en produit une trentaine (`wp is not defined`, `setLocaleData`) avec `php -S`. **Ce n'est pas ton code.** Pour trancher, comparer avec `/wp-admin/index.php` : s'il en produit autant ou plus, l'origine est l'environnement.

### Un « titre absent » en curl peut être une 404

Toujours lire le code HTTP en même temps que le contenu.

### Compter les `hreflang` sur toute la page est trompeur

Isoler le `<head>` : les liens de langue du corps de page faussent le comptage.

### Un `debug.log` vide n'innocente pas le code

Beaucoup d'échecs sont silencieux (conversion d'image, webhook, permissions). Et `/tmp` ne survit pas aux reconstructions.

---

## CSS

### Conflits de spécificité sur le formulaire

`.pk-field label` (ligne 447) écrase les styles du parcours en 3 étapes. Préfixer avec `.pk-steps` et **placer les règles en fin de fichier**.

### `[hidden]` ne suffit pas à masquer

Le thème définit des `display` qui prennent le dessus. Utiliser `display: none !important` pour les étapes masquées.

---

## Divers

### Purge du cache

`Partikulier_Cache::purge_all()` après toute modification de contenu en masse.

### Ne pas supprimer les fichiers d'origine

Le dossier `uploads/` fourni par le client doit être conservé tel quel.
