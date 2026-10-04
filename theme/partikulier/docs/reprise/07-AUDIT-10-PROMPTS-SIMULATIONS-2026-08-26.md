# Rapport d’exécution — 10 audits et 10 simulations Partikulier

**Date de campagne :** 26 août 2026
**Commit audité :** `2f626a3d69cdd0f1b3ec0d6b9b81e9532abdc3b8`
**Branche :** `automation/release-approval-gate-v1.7.1`
**Site :** WordPress de test Hostinger, version active `partikulier-cdc-v18-final22`
**Périmètre :** dépôt public, site public FR/AR, endpoints publics sans authentification et tests navigateur non destructifs.

## Verdict

> **Verdict : NO-GO pour une certification finale.**

Les 10 audits et les 10 simulations ont bien été exécutés ou rejoués avec des preuves. Les corrections visuelles principales restent visibles sur le site : 21 cartes, deux cartes par rangée en desktop, une carte par rangée en mobile, RTL arabe, titres et villes arabes visibles, filtre Type + Ville, reset et autocomplétion. Cependant, l’accessibilité, la performance, le JSON-LD arabe, la robustesse du chargement JavaScript et le workflow CI restent insuffisants pour un Go final.

Le dépôt est maintenant plus complet qu’avant : `theme/style.css` est présent à la racine, le CSS final est synchronisé et le premier rapport d’audit est publié. Il manque encore `theme/package-lock.json`, et le dernier workflow candidate reste en échec.

## État de référence

| Élément | Observation |
|---|---|
| Commit local et distant | Alignés sur `2f626a3d69cdd0f1b3ec0d6b9b81e9532abdc3b8` |
| Fichiers thème suivis | 102 |
| `theme/style.css` | Présent, header WordPress valide |
| `theme/assets/css/style.css` | Présent avec les 19 lignes de placement final |
| `theme/package-lock.json` | Absent |
| Rapport précédent | Présent sur GitHub dans `theme/docs/reprise/05-AUDIT-SENIOR-2026-08-25.md` |
| Thème live | `wp-theme-partikulier-cdc-v18-final22` |
| Pages live | FR et AR répondent HTTP 200 |
| Données modifiées pendant la campagne | Aucune annonce créée, supprimée ou modifiée |

## Partie A — Résultats des 10 audits

### AUDIT-01 — Architecture, branche et packaging

Le commit local correspond au commit distant. La branche contient 102 fichiers sous `theme/`. Le fichier `theme/style.css` est désormais présent avec `Theme Name`, `Version` et `Text Domain`. Le CSS réellement riche reste `theme/assets/css/style.css`, qui contient les 19 lignes de correction du contrôle mobile et du reset desktop. Le package temporaire construit depuis le thème passe `unzip -t` et contient les fichiers WordPress obligatoires.

**Résultat : PASS partiel.** Le problème du fichier racine est corrigé. La reproductibilité npm reste bloquée par l’absence de lockfile.

### AUDIT-02 — Syntaxe PHP

`php -l` a été exécuté sur 66 fichiers PHP du thème. Résultat : **66 valides, 0 erreur**.

**Résultat : PASS.** Ce contrôle ne remplace pas une analyse statique WordPress complète ni un test fonctionnel.

### AUDIT-03 — Syntaxe JavaScript

`node --check` a été exécuté sur les deux fichiers JavaScript du thème. Résultat : **2 valides, 0 erreur**.

**Résultat : PASS syntaxique.** Le contrôle ne garantit pas que le JavaScript se charge suffisamment tôt ni que tous les handlers sont prêts au moment où l’utilisateur clique.

### AUDIT-04 — Analyse statique sécurité

Semgrep avec règles PHP a scanné le thème et produit un finding : `php.lang.security.injection.echoed-request.echoed-request`, dans `theme/templates/archive.php`, ligne 166. L’inspection du code montre `esc_url($action_url)` pour l’attribut et `esc_html($action_label)` pour le texte. Le finding est donc probablement un faux positif pour ce chemin, mais il doit être traité par une exception documentée ou par une règle WordPress mieux adaptée.

**Résultat : PASS sous réserve de qualification du finding.** Il ne faut pas désactiver Semgrep globalement.

### AUDIT-05 — Secrets dans le dépôt

Un scan ciblé du commit sur les motifs de clés OpenAI, AWS et clés privées n’a produit aucun résultat. Aucun secret, cookie, dump SQL ou fichier d’accès n’a été ajouté par cette campagne.

**Résultat : PASS sur le périmètre scanné.** Ce n’est pas un audit exhaustif de tous les secrets possibles.

### AUDIT-06 — Surfaces publiques REST/AJAX et headers

Les contrôles actuels ont donné les réponses suivantes :

| Requête | Réponse observée | Interprétation |
|---|---:|---|
| `/wp-json/wp/v2/users?per_page=5` | 404 | Route utilisateurs non exposée dans ce contexte |
| `POST /wp-json/partikulier/v1/owner/dashboard` sans auth | 401 | Protection présente |
| `GET /wp-json/partikulier/v1/automation-event` | 404 | Méthode GET non prévue ; le contrôle POST sans secret a déjà donné 401 dans la campagne précédente |
| AJAX places avec nonce invalide | 403 | Protection nonce présente |
| POST dépôt sans nonce | 403 | Protection présente |

Dans la capture headers actuelle, `Referrer-Policy: strict-origin-when-cross-origin` et `Content-Security-Policy: upgrade-insecure-requests` ont été observés. Les autres headers de sécurité doivent être revérifiés dans une capture complète et standardisée avant d’être déclarés présents.

**Résultat : PASS défensif partiel.** Le contrôle n’est pas un pentest et n’a pas utilisé d’identifiants ni de secret webhook.

### AUDIT-07 — Données Estatik et filtres

Les requêtes live ont produit : archive sans filtre : **21 cartes** ; type Appartement : **8 cartes** ; ville Marrakech : **1 carte** ; Appartement + Marrakech : **1 carte** ; Appartement + Marrakech + `a-vendre` : **0 carte**.

L’administration Estatik lue pendant la campagne précédente indiquait que les termes `a-vendre` et `a-louer` existaient mais avaient chacun un total de 0. Le résultat transactionnel ne permet donc pas de déclarer Vendre/Louer fonctionnel sur des annonces réelles. Il faut auditer la source canonique et prouver un cas vente et un cas location avant tout Go.

**Résultat : FAIL fonctionnel pour validation finale, cause de données/source de vérité à corriger.**

### AUDIT-08 — Localisation arabe et JSON-LD

La page AR expose `lang="ar"` et `dir="rtl"`. Les premiers titres sont arabes, par exemple `شقة بمساحة 101 م² للبيع في الرباط`, et les villes sont arabes, par exemple `الرباط`, `إفران` et `المحمدية`. Aucun caractère latin n’a été détecté dans les titres échantillonnés.

Le JSON-LD contient cependant encore `Saïdia` et `Annonces immobilières gratuites` en français. La correction des cartes visibles n’a donc pas corrigé toutes les données structurées.

**Résultat : PASS visuel AR, FAIL JSON-LD AR.**

### AUDIT-09 — UI, UX, responsive et cross-browser

Les pages FR et AR ont été testées avec Chromium, Firefox et WebKit en 1440×900 et 390×844. Les résultats stables après chargement des scripts sont : 21 cartes, deux par rangée en desktop, une par rangée en mobile, reset visible, navigation sticky et absence de barre basse non prévue. Le sélecteur de langue ne chevauche pas le cœur des cartes dans les mesures effectuées.

Une anomalie de robustesse a été mise en évidence : si le test clique immédiatement après l’apparition des cartes, avant la fin du chargement de `main.js`, Chromium et Firefox ne basculent pas toujours le popup. Après attente explicite de `document.readyState=complete`, de la réponse `main.js` et de six secondes de stabilisation, les trois moteurs ouvrent correctement le popup et le bouton Fermer. Cette course est cohérente avec la latence élevée et doit être corrigée ou explicitement traitée par progressive enhancement.

**Résultat : PASS visuel après stabilisation, FAIL de robustesse au clic précoce.**

### AUDIT-10 — Accessibilité automatisée

Axe-core Chromium a été exécuté sur FR/AR desktop/mobile. Les quatre vues présentent `aria-hidden-focus` avec impact **serious** : le panneau `#pk-filters-panel` est fermé pour l’arbre d’accessibilité tout en contenant des éléments focalisables. Les vues desktop ajoutent `landmark-no-duplicate-banner` et `landmark-unique`. Les vues mobiles ajoutent `heading-order`.

**Résultat : FAIL bloquant.** Le popup peut fonctionner visuellement et au clavier dans un scénario favorable tout en restant incorrect pour les technologies d’assistance.

## Partie B — Résultats des 10 simulations

| ID | Simulation exécutée | Résultat réel | Verdict |
|---|---|---|---|
| SIM-01 | Clic immédiat sur Affiner après apparition des cartes | Chromium/Firefox peuvent rester `aria-expanded=false` si `main.js` n’est pas prêt | **FAIL robustesse** |
| SIM-02 | Clic après chargement complet de `main.js` | Panneau ouvert, classe `is-open`, `aria-hidden=false` dans les trois moteurs | PASS conditionnel |
| SIM-03 | Fermeture par bouton Fermer | Panneau fermé et `aria-hidden=true` | PASS comportement |
| SIM-04 | Fermeture par Échap et retour du focus | Focus revenu sur `.pk-filter-toggle` après fermeture | PASS comportement |
| SIM-05 | Autocomplétion du champ Ville du filtre | Endpoint 200 et 9 suggestions ; sélection Marrakech → `marrakech` après attente réseau | PASS avec intermittence Chromium initiale |
| SIM-06 | Autocomplétion de la recherche principale | 9 suggestions ; sélection Marrakech visible | PASS |
| SIM-07 | Cumul Type + Ville | 1 carte ; href conserve `es_city=marrakech` et ajoute `es_type=appartement-fr` | PASS |
| SIM-08 | Reset après filtrage | href retourne à `/fr/annonces/` sans paramètres | PASS |
| SIM-09 | Rendu AR et données structurées | RTL et cartes arabes corrects ; résidus JSON-LD français confirmés | **FAIL JSON-LD** |
| SIM-10 | Absence de secret/nonce et entrée script | REST non authentifié 401 ; POST sans nonce 403 ; script non reflété | PASS défensif |

La simulation SIM-01 est importante : elle ne prouve pas seulement un défaut du harnais. Elle montre que l’interface dépend de la disponibilité du JavaScript, alors que la page affiche déjà des éléments interactifs et reste très lente à charger. Le développeur doit décider si le clic précoce doit être bloqué visuellement, rendu idempotent, ou géré par un état HTML initial cohérent.

## Partie C — Performance mesurée

La mesure navigateur actuelle a donné :

| Vue | Temps total | TTFB approximatif via `responseStart` | Poids ressources | Cartes |
|---|---:|---:|---:|---:|
| FR desktop | 12,0 s | 3,07 s | 2,06 Mo | 21 |
| FR mobile | 10,0 s | 3,80 s | 2,06 Mo | 21 |
| AR desktop | 10,2 s | 3,03 s | 2,21 Mo | 21 |
| AR mobile | 9,5 s | 2,69 s | 2,21 Mo | 21 |

Aucune réponse 4xx/5xx n’a été observée dans ces quatre chargements, mais cela ne rend pas la page performante. Les objectifs restent TTFB médian inférieur à 800 ms en cache chaud et LCP inférieur à 2,5 s dans un environnement stabilisé. Il faut d’abord identifier les requêtes PHP/SQL et les ressources lentes avant toute optimisation cosmétique.

**Verdict performance : FAIL P0.**

## Partie D — CI, tests et reproductibilité

Le workflow candidate le plus récent associé au commit `2f626a3` reste en échec dans `Cold WordPress MariaDB HTTP acceptance`, pendant l’installation froide. Le message racine de `scripts/install.sh` n’est pas suffisamment remonté, car la sortie est redirigée vers un fichier interne. Le dépôt ne contient toujours pas `theme/package-lock.json`, donc `npm ci` ne peut pas fournir une installation reproductible.

Le package ZIP local produit depuis le thème passe le test d’intégrité ZIP et contient le header WordPress racine. Cette validation locale ne remplace pas un run CI vert depuis un checkout propre.

**Verdict CI/reproductibilité : FAIL P0.**

## Partie E — Corrections obligatoires dans l’ordre

| Priorité | Correction | Critère de sortie |
|---|---|---|
| P0 | Corriger `aria-hidden-focus`, focusables, landmarks et heading order | Axe-core sans serious sur les 4 vues ; clavier validé trois moteurs |
| P0 | Corriger la course de chargement du popup | Clic avant et après chargement JavaScript produit un état cohérent |
| P0 | Déterminer la source de vérité Estatik transaction | Cas vente et location réels prouvés avec Type + Ville + Action |
| P0 | Diagnostiquer PHP/SQL/ressources lentes | TTFB/LCP mesurés avant/après avec budget documenté |
| P0 | Ajouter `package-lock.json` et config Playwright | `npm ci` puis tests cross-browser depuis clone propre |
| P0 | Faire remonter la vraie erreur CI | Log exploitable de `install.sh`, puis workflow candidate vert |
| P1 | Corriger `class-jsonld.php` | Aucun titre FR ou `Saïdia` latin non justifié dans JSON-LD AR |
| P1 | Qualifier le finding Semgrep | Correction ou exception locale documentée |
| P2 | Installer WPCS/PHPStan correctement | Configs et versions versionnées, mémoire maîtrisée |
| P2 | Nettoyer workflows et documentation historiques | Un chemin de release canonique |

## Conclusion finale

Les prompts d’audit et de simulation ont été appliqués sur le code et le WordPress de test. Le résultat est exploitable pour la développeuse : les acquis sont séparés des échecs, les faux positifs potentiels sont distingués des défauts confirmés et les critères de sortie sont mesurables.

La prochaine étape senior n’est pas d’ajouter des fonctionnalités. C’est de traiter les six blocages P0, de rejouer exactement les mêmes tests depuis un checkout propre et de publier un nouveau rapport avec un verdict Go/No-Go. Aucune certification, conformité à 100 % ou garantie de sécurité ne doit être annoncée avant cela.

## Références

[1]: https://blanchedalmond-reindeer-376379.hostingersite.com/fr/annonces/ "Archive publique française"

[2]: https://blanchedalmond-reindeer-376379.hostingersite.com/ar/annonces/ "Archive publique arabe"

[3]: https://github.com/hajarbenmlih91-cloud/partikulier2/tree/automation/release-approval-gate-v1.7.1 "Branche candidate publique"

[4]: https://github.com/hajarbenmlih91-cloud/partikulier2/commit/2f626a3d69cdd0f1b3ec0d6b9b81e9532abdc3b8 "Commit audité"

[5]: https://github.com/hajarbenmlih91-cloud/partikulier2/actions/runs/32908189867 "Dernier workflow candidate audité"

[6]: https://www.w3.org/WAI/ARIA/apg/patterns/dialog-modal/ "WAI-ARIA Authoring Practices — Dialog Modal Pattern"

[7]: https://dequeuniversity.com/rules/axe/4.10/aria-hidden-focus "Deque axe — aria-hidden-focus"
