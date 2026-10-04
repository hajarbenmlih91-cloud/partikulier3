# Rapport d’audit senior — Partikulier CDC v1.8 final22

**Date :** 25 août 2026
**Périmètre :** dépôt GitHub candidat, package final22, site WordPress de test Hostinger, public FR/AR, administration Estatik en lecture seule.
**Méthode :** inspections statiques, requêtes HTTP non destructives, contrôles navigateur Playwright Chromium/Firefox/WebKit, axe-core Chromium, simulations négatives sans création de contenu.
**Auteur :** Manus AI.

## Verdict exécutif

> **Décision actuelle : NO-GO pour une validation senior finale ou une passation “prête à publier”.**

Le thème actif sur le site de test est bien `partikulier-cdc-v18-final22`, et les corrections visibles principales sont présentes : archive à deux cartes par ligne sur desktop, une carte par ligne sur mobile, bouton de filtre mobile placé avant les cartes, popup fonctionnel, navigation sticky, autocomplétion et cartes arabes visibles. Ces éléments ont été vérifiés sur le site public, pas seulement dans le code [1] [2].

La validation reste bloquée par quatre points objectifs. Premièrement, l’accessibilité présente une violation axe-core **serious** : le panneau `#pk-filters-panel` est marqué `aria-hidden="true"` tout en conservant des éléments focalisables. Deuxièmement, la performance live est très insuffisante : le chargement navigateur complet de l’archive prend environ **33 à 50 secondes**, avec un TTFB HTML d’environ **3,6 à 6,4 secondes** dans les mesures répétées. Troisièmement, les termes Estatik `a-vendre` et `a-louer` existent mais ont chacun un total de **0** dans l’administration ; le filtre Type + Ville fonctionne, mais Vendre/Louer ne peut pas être déclaré fonctionnel sur une annonce réelle. Quatrièmement, la release GitHub reste non autoportante : `theme/style.css` est absent de la branche candidate, le CSS final comporte 19 lignes locales non poussées, le lockfile npm manque et le dernier workflow candidate échoue pendant l’installation froide [3] [4].

Aucune vulnérabilité critique n’a été confirmée par les contrôles réalisés. Cela ne constitue pas un pentest complet, une certification de sécurité ou une garantie de conformité.

## Tableau de synthèse

| Domaine | Test réel exécuté | Résultat | Statut |
|---|---|---:|---|
| État live | Body class, HTTP, FR/AR | `final22`, HTTP 200 | Pass |
| PHP | `php -l` sur 66 fichiers | 66/66 sans erreur | Pass |
| JavaScript | `node --check` sur 2 fichiers | 2/2 sans erreur | Pass |
| UI responsive | Chromium, Firefox, WebKit, 1440×900 et 390×844 | 21 cartes ; 2/ligne desktop ; 1/ligne mobile | Pass avec limites de réseau |
| Popup filtres | Ouverture, Fermer, Échap, retour focus | Fonctionnel | Pass |
| Autocomplétion | `m`, villes/quartiers, sélection Marrakech | 9 suggestions ; slug `marrakech` | Pass |
| Cumul Type + Ville | `es_type=appartement-fr&es_city=marrakech` | 1 carte ; contexte conservé | Pass |
| Transaction | `es_action=a-vendre` / `a-louer` | Termes réels à 0 annonce | **Bloqué données** |
| Arabe visible | Titres, villes, types, chambres | Cartes arabes sans titre latin détecté | Pass visuel |
| Arabe SEO | JSON-LD | `Annonces immobilières gratuites` et `Saïdia` latin encore présents | **À corriger** |
| Sécurité headers | HTTPS, HSTS, nosniff, frame, referrer | Présents sur pages testées | Pass partiel |
| Endpoints négatifs | Secret/nonce/auth absents | 401/403 attendus | Pass défensif |
| Accessibilité | axe-core, 4 vues FR/AR desktop/mobile | `aria-hidden-focus` serious partout | **Échec** |
| Performance | Playwright + curl répété | 33–50 s navigateur ; TTFB 3,6–6,4 s | **Échec** |
| PHPCS | PSR-12 générique | 33 315 erreurs, 1 269 warnings | Non interprétable sans WPCS |
| Semgrep | Règles PHP explicites | 1 finding probable faux positif sur `esc_url` | À qualifier |
| PHPStan | Stubs WordPress temporaires | OOM jusqu’à 768 Mo | Non validé |
| Harnais officiel | `npm ci` puis `npm test` | Lockfile absent ; test non démarré | **Échec release** |
| CI candidate | Dernier run `071bca9` | Échec installation froide | **Échec release** |

## 1. État de référence et couverture des prompts

Les vingt prompts ont été appliqués sous forme de contrôles regroupés par spécialité. L’audit architecture/version a vérifié la branche, l’arborescence, les workflows et le package. L’audit PHP/JavaScript a exécuté les lintings disponibles. Les audits sécurité et REST/AJAX ont inspecté les nonces, permissions, endpoints et headers, puis exécuté des requêtes négatives. Les audits Estatik, filtres et i18n ont confronté le rendu public aux termes réellement présents dans l’administration. Les audits UI, UX, responsive et accessibilité ont utilisé les trois moteurs installés et axe-core. L’audit performance a mesuré le navigateur et HTTP. Les simulations ont couvert entrées malformées, absence de nonce, absence de secret, popup, clavier, autocomplétion et conservation des paramètres.

La branche distante candidate et le checkout local étaient alignés sur `071bca9dd2c40bbb9d56a10f479563547f47021a`. Le thème actif live contenait `wp-theme-partikulier-cdc-v18-final22`. Le package final22 a un SHA-256 local `b43b0679428a26971a9eb1fdc96c9dd76e3269a01e0e3b698a717d5e47ae4e30`.

## 2. Résultats UI/UX et responsive

Sur l’archive publique, les trois moteurs disponibles ont rendu les pages FR et AR. Les cartes mesurées sont au nombre de 21. En desktop, les rangées comportent deux cartes, avec une dernière rangée incomplète. En mobile 390 px, chaque rangée comporte une seule carte. Le bouton `Affiner la recherche` précède la première carte ; sur Chromium FR, il a été mesuré à top 547 px contre top 622 px pour la première carte.

La navigation principale est réellement sticky. Sur Chromium, elle passe de top 126 à top 0 après `scrollY=1200` en desktop, et de top 91 à top 0 en mobile. Aucun candidat de barre de navigation fixe basse n’a été détecté dans les vues contrôlées. Le sélecteur de langue ne chevauche pas le cœur de carte dans les mesures de boîtes. Le popup mobile s’ouvre, le bouton Fermer existe, Échap ferme le panneau et le focus revient au bouton d’ouverture.

L’autocomplétion live a appelé l’endpoint AJAX avec un nonce public valide. Pour la lettre `m`, le serveur a renvoyé neuf propositions : Marrakech, Meknès, Mohammedia, Al Hoceima, Beni Mellal, Maarif, Sidi Maarouf, Mers Sultan et Yacoub El Mansour. La sélection de Marrakech a rempli le label visible et la valeur cachée `marrakech`. Sur une URL préfiltrée par Marrakech, le lien Type a conservé `es_city=marrakech` et ajouté `es_type=appartement-fr`.

## 3. Arabe, SEO et données Estatik

Les cartes AR visibles contrôlées affichent des titres, villes, types et chambres en arabe. Les cinq titres échantillonnés étaient par exemple `شقة بمساحة 101 م² للبيع في الرباط`, `شاليه بمساحة 150 م² للبيع في إفران` et `دوبلكس بمساحة 118 م² للبيع في المحمدية`. Aucun caractère latin n’a été détecté dans les titres arabes échantillonnés.

Le problème n’est pas entièrement résolu dans le JSON-LD. Dans la page AR, le nom de page `Annonces immobilières gratuites` reste français et un item contient encore `Saïdia` latin. Le fichier source concerné est `theme/inc/class-jsonld.php`, qui utilise encore `get_the_title($post)` dans plusieurs chemins. La correction est limitée au SEO structuré et n’a pas été appliquée dans cette campagne.

L’écran d’administration Estatik a été lu en session autorisée. La taxonomie `es_category` contient 56 éléments sur trois pages. Les termes `A louer` (`a-louer`) et `À vendre` (`a-vendre`) existent mais affichent chacun **0**. Des termes de ville comme Agadir et leurs traductions affichent des totaux non nuls. En conséquence, `?es_type=appartement-fr&es_city=marrakech` renvoie 1 carte, mais la même requête avec `es_action=a-vendre` renvoie 0. La cause démontrée est l’absence d’association des annonces de test aux termes d’action, et non une preuve de robustesse du filtre transaction.

## 4. Sécurité défensive

Les headers observés sur les pages publiques incluent HSTS, `X-Content-Type-Options: nosniff`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy: strict-origin-when-cross-origin` et une CSP limitée à `upgrade-insecure-requests`. `/xmlrpc.php` répond 405. `/wp-json/wp/v2/users` répond 404 ; les articles publics WordPress restent accessibles via REST, comportement cohérent avec un site d’articles publics mais à contrôler selon la politique éditoriale souhaitée.

Le pont d’automatisation exige un secret ; une requête POST sans secret vers `/wp-json/partikulier/v1/automation-event` a renvoyé 401. Les routes propriétaires sans authentification ont été rejetées en 401. Les requêtes AJAX d’autocomplétion sans nonce ou avec nonce invalide ont renvoyé 403. Aucune constante de secret, clé d’API ou header d’automatisation n’a été trouvé dans le HTML public inspecté.

Une soumission AJAX de dépôt sans nonce, contenant notamment un titre `<script>`, a été rejetée en 403 et n’a créé aucune annonce. Des paramètres GET contenant une ville scriptée, une chaîne SQL et un chemin `../../wp-config.php` n’ont pas produit de reflet littéral du script ; le type SQL a été routé vers 404. Ces essais sont des simulations défensives limitées. Aucun test d’upload, de compte authentifié ou de webhook valide n’a été lancé pour ne pas créer de données ni utiliser de secrets.

## 5. Accessibilité

Axe-core a été exécuté sur Chromium en FR/AR desktop et mobile, en désactivant uniquement la règle de contraste afin de ne pas transformer une palette dynamique en faux verdict. Les quatre vues présentent la violation `aria-hidden-focus` d’impact **serious** sur `#pk-filters-panel`. Le panneau fermé a `aria-hidden="true"`, mais conserve des liens et contrôles focalisables dans l’arbre DOM.

Les vues desktop ajoutent `landmark-no-duplicate-banner` et `landmark-unique`. Les vues mobiles ajoutent `heading-order` sur le premier titre de carte `h3`. La priorité immédiate est de rendre les descendants du panneau non focalisables et cohérents avec son état ouvert/fermé, puis de corriger la hiérarchie de titres et les landmarks. Le popup fonctionne visuellement et au clavier, mais le scan démontre que l’état fermé n’est pas encore propre pour les technologies d’assistance.

## 6. Performance

Les pages répondent 200, mais la réponse HTTP n’est pas synonyme de performance acceptable. Les quatre vues Chromium ont affiché 21 cartes avec un chargement complet compris entre environ 33,0 et 49,8 secondes. Les ressources transférées représentaient environ 2,06 à 2,21 Mo. Les appels curl répétés ont donné un TTFB HTML d’environ 3,6 à 6,4 secondes et un temps total HTML d’environ 7,7 à 15,0 secondes.

La mesure de ressources a observé des WebP de 302 à 329 Ko et des délais de ressource pouvant atteindre environ 45 à 60 secondes dans le navigateur de test. Les fichiers les plus lents doivent être recontrôlés depuis plusieurs régions et avec cache froid/chaud, mais le signal est suffisamment fort pour bloquer une validation performance. Les actions recommandées sont de mesurer côté serveur le temps de génération PHP/SQL, vérifier les traces Query Monitor, corriger les ressources qui restent en attente, et mettre en place un budget LCP/TTFB documenté.

## 7. Qualité statique et reproductibilité

Le lint PHP a passé 66 fichiers sur 66 et le contrôle JavaScript a passé 2 fichiers sur 2. `git diff --check` ne signale pas d’erreur d’espaces. Semgrep PHP a scanné 63 fichiers et produit un seul finding sur `echo esc_url($action_url)` dans `archive.php`; l’inspection montre que la valeur est déjà passée par `sanitize_text_field` puis `esc_url`, ce qui en fait un faux positif probable, mais la règle WordPress doit être confirmée.

PHPCS ne dispose pas du standard WordPress dans l’environnement : seuls MySource, PEAR, PSR1/2/12, Squiz et Zend sont installés. Le run PSR-12 brut renvoie 33 315 erreurs et 1 269 warnings ; ce nombre ne doit pas être interprété comme un score du thème. PHPStan n’est pas configuré dans le dépôt. Une installation temporaire avec stubs WordPress a dépassé 768 Mo et a été tuée ; aucun résultat PHPStan exploitable ne doit donc être annoncé.

Le harnais officiel décrit 12 vues avec références pixel, mais `theme/package-lock.json` est absent. `npm ci` échoue donc avant de lancer `npm test`. Les tests ad hoc ont été exécutés dans `/tmp/pw-run` avec Playwright et les trois moteurs, mais cette installation temporaire ne rend pas le dépôt reproductible.

## 8. Release GitHub et CI

La branche candidate contient 100 fichiers de thème et les documents de reprise. Elle ne contient toutefois pas `theme/style.css` à la racine. Le ZIP final22 possède ce fichier avec un header WordPress valide, mais ce fichier a été ajouté au package par copie manuelle et n’est pas présent dans la branche GitHub vérifiée. Le CSS réel `theme/assets/css/style.css` contient 19 lignes locales non committées : elles contrôlent l’ordre du bouton mobile et la visibilité du reset desktop. Le site live et les ZIP final19 à final22 les utilisent, mais la branche candidate ne les contient pas.

Quatre workflows versionnés coexistent : `cdc-v1.7.1-candidate.yml`, `cdc-v1.7.1-quality.yml`, `cdc-v6.17.15.yml` et `cdc-v6.17.16.yml`. Les workflows historiques ne sont pas nécessairement incorrects, mais ils augmentent le risque de gates obsolètes et de versions concurrentes. Le dernier run candidate observé sur `071bca9` est en échec dans le job `Cold WordPress MariaDB HTTP acceptance`, pendant l’appel à `scripts/install.sh`; les autres jobs de ce run ont été skip ou partiellement passés. Le log ne remonte pas le message racine de `install.sh` car il est redirigé dans un fichier interne ; la cause exacte reste donc à instrumenter, mais le statut CI est objectivement non vert [4].

## Priorités de correction avant nouvelle validation

| Priorité | Correction | Critère de sortie mesurable |
|---|---|---|
| P0 | Corriger `aria-hidden-focus` du panneau fermé et les landmarks/titres signalés | Axe-core : 0 violation serious sur FR/AR desktop/mobile, hors règle explicitement justifiée |
| P0 | Rendre Vendre/Louer cohérent avec les associations Estatik réelles | Un cas de vente et un cas de location connus passent chacun avec Type + Ville + Action |
| P0 | Réduire la latence publique | Budget proposé : TTFB médian < 800 ms, LCP < 2,5 s sur archive, mesure froide et chaude documentée |
| P0 | Rendre la branche autoportante | Ajouter `theme/style.css` valide et les 19 lignes CSS intentionnelles, sans staging global |
| P1 | Rendre les tests reproductibles | Ajouter `theme/package-lock.json`, config Playwright explicite et références maintenues |
| P1 | Corriger le JSON-LD AR | Tous les `name` et fallback de description utilisent la localisation active ; aucun `Saïdia`/titre FR résiduel dans JSON-LD AR |
| P1 | Instrumenter l’installation froide CI | Faire apparaître stdout/stderr du failing command et obtenir un run candidate vert |
| P2 | Installer/configurer WPCS et PHPStan | Configs versionnées, versions fixées, seuils et baseline explicitement documentés |
| P2 | Nettoyer la gouvernance des workflows/docs | Un workflow canonique de release, historique archivé ou désactivé, documentation dédoublonnée |

## Conclusion

Le travail visible final22 est substantiellement meilleur et plusieurs demandes de l’interface sont réellement appliquées sur le site test. Les résultats ne permettent toutefois pas de dire que le thème est certifié, conforme à 100 %, performant ou prêt pour une release. Le prochain cycle doit être court et mesurable : corriger l’accessibilité du popup, fiabiliser les termes de transaction, résoudre la latence, versionner les fichiers manquants et obtenir un CI candidate vert. Aucune de ces corrections n’a été poussée ou déployée pendant cette campagne.

## Références

[1]: https://blanchedalmond-reindeer-376379.hostingersite.com/fr/annonces/ "Archive publique Partikulier — français"

[2]: https://blanchedalmond-reindeer-376379.hostingersite.com/ar/annonces/ "Archive publique Partikulier — arabe"

[3]: https://github.com/hajarbenmlih91-cloud/partikulier2/tree/automation/release-approval-gate-v1.7.1 "Branche candidate GitHub"

[4]: https://github.com/hajarbenmlih91-cloud/partikulier2/actions/runs/32901346073 "Dernier run CI candidate audité"
