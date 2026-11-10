# Demandes à transmettre — campagne UAT jusqu'à validation

**Version 3 — 10 novembre 2026.** Remplace les versions 1 et 2.
**Décision enregistrée :** on reste sur `uat`, approbation par le propriétaire uniquement, aucun autre
reviewer. Conséquence assumée : pas de validation à quatre yeux. La valeur probante repose donc
entièrement sur les preuves publiées (SHA, hash de diff, artefacts), pas sur un clic d'approbation.

**Cible :** `uat` uniquement. Aucune production, aucun `deploy.yml`, aucun tag `pre-release-*`
pendant la campagne, aucun retag, aucun déplacement de tag, aucun secret transmis.

---

## 1. Gouvernance — une fois, avant tout

> Remplace l'entrée `audit-2.10.16-6.20.214-2` de la policy de `uat` par le motif
> `audit-2.10.16-6.20.214-*`, en conservant `pre-release-*`. Vérifie l'existence avant création ;
> aucun `PUT` si l'environnement existe ; ne touche pas à `prd` ; ne retire aucun check requis ;
> ne modifie aucun reviewer.

Motif et non `audit-*` : couvre toutes les itérations de cette release, sans élargir aux futures.

**À publier à chaque run, puisque l'approbation ne prouve plus rien :** le SHA du tag, le SHA-256 du
diff, la commande exacte de dispatch, l'identifiant du run, et l'identifiant de l'artefact.

---

## 2. Diagnostic du consentement — lecture seule

> Contrôle Playwright en lecture seule, sans relancer l'audit. Contextes : `390×844, isMobile` puis
> `1440×900`. Sur la page de connexion, réponds séparément, avec la trace :
>
> 1. Quels contrôles de consentement existent — visibles, rôle et nom accessible ?
> 2. Sont-ils activables au clavier (`Tab`, `Shift+Tab`, `Enter`, focus visible) et à la souris ?
> 3. Après **Refuser**, puis dans un contexte neuf après **Accepter**, la connexion aboutit-elle
>    réellement (marqueur de tableau de bord atteint) ?
> 4. Le comportement diffère-t-il entre mobile et desktop ?
>
> `document.elementFromPoint` au centre du bouton, comme indice seulement : il dit qui reçoit le clic,
> pas si le blocage est légitime.
>
> Interdits : `force: true`, masquer ou retirer le bandeau, poser un cookie pour sauter le consentement,
> augmenter le timeout pour contourner.

| Constat | Verdict |
|---|---|
| Consentement accessible, les deux choix marchent, connexion OK ensuite | défaut du **harnais** seul |
| Consentement absent, inatteignable ou inopérant | défaut **produit** |
| Blocage subsiste après consentement | défaut **produit** |
| Problème sur une seule taille d'écran | défaut **produit responsive** |

---

## 3. Correction aux deux niveaux

> **Harnais, dans tous les cas** : sélection du consentement par rôle et nom accessible, les deux choix
> testés, assertion que le dialogue est fermé, assertion que le bouton Connexion reçoit le clic,
> assertion que la connexion aboutit. Si le dialogue réapparaît, le test reste rouge.
>
> **Produit, si le §2 le conclut** : corriger accessibilité, `z-index`, `pointer-events` ; garantir que
> les deux choix débloquent la connexion ; ajouter des tests mobile et desktop.

Le harnais ne tente aujourd'hui aucun consentement (`audit-uat-login.mjs` : zéro occurrence de
`cookie`, `consent`, `accept`, `dialog`). Ce défaut est acquis quoi qu'il arrive.

---

## 4. Nouveau ref d'audit

> Commit dérivé de `c9e1413`, tag `audit-2.10.16-6.20.214-3`. Ne déplace aucun tag existant.
> Rejoue les 24 tests. Publie le SHA du commit, la liste exacte des fichiers touchés, et le SHA-256
> de `git diff c9e1413 <commit>`.

---

## 5. Comble le trou Docker — aujourd'hui, rien ne le teste

`docker/Dockerfile` et `docker-compose.yml` existent (mysql 8.0, WordPress, mailpit), mais **aucun
workflow ne les utilise**. « Tester Docker » n'existe pas encore.

> Ajoute un job CI qui construit l'image et démarre la pile sans publication :
> `docker compose build`, puis `docker compose up -d --wait`, un contrôle de santé HTTP sur WordPress,
> puis `docker compose down -v`. Épingler les images par digest. Ne rien pousser vers un registre.
> Si le job ne peut pas être vert immédiatement, ouvre-le en `continue-on-error` **et** documente
> pourquoi, sans le faire passer pour un succès.

---

## 6. Le run unique — tout ce qui n'a jamais tourné

> Sur le tag `-3`, avec `load_test=true` :
>
> - **Charge** : sonde 10 req/s (10 s), puis 100 req/s (30 s) seulement si la sonde tient ; arrêt
>   immédiat sur 5xx ; fenêtre explicitement autorisée. Ces étapes sont en `continue-on-error` :
>   le résultat se lit dans l'artefact, pas dans la conclusion du run.
> - **Cache du thème** : relever la date de l'entrée, GET sur `/fr/annonces/?pk_audit=<horodatage>`
>   pour forcer un rendu PHP, puis rejouer le `find` existant. Aucune suppression de fichier.
> - **SSH réel** : création puis suppression du compte jetable, avec preuve de l'identifiant créé et
>   de sa disparition.
> - **Publier** `load-smoke.txt`, `load-full.txt`, `cache-ttfb.txt` — les conclusions d'étape ne
>   prouvent pas l'exécution.

**LiteSpeed reste une action propriétaire** (hPanel), sinon la ligne restera `NON VÉRIFIÉ` à vie :

> Produis la procédure exacte à exécuter dans hPanel pour activer LiteSpeed Cache sur l'UAT et exposer
> `x-litespeed-cache`. Où l'activer, quel réglage expose l'en-tête, comment vérifier, comment revenir
> en arrière. Ne modifie rien.

---

## 7. Seuils de release — à figer avant le run final

> Propose un tableau versionné par type de page : performance, SEO, accessibilité, TBT et LCP maximaux,
> avertissements acceptés vs bloquants. Justifie chaque valeur par une référence externe ou par
> l'historique du site, **pas par les résultats à venir**. Validé ensuite par le responsable produit.

---

## 8. Tickets séparés

FR home mobile (TBT 601 ms contre 3 ms en EN/AR — l'anomalie est la page) ; six avertissements
WhatsApp ; SEO 61/69 ; cache non prouvé.

---

## 9. Run de validation finale

Sur `uat`, approbation par le propriétaire, seuils du §7 appliqués. Tant qu'il n'a pas réussi,
la note reste **non calculable** et la release **non validée**.

---

## 10. Déploiement — la question à trancher **avant** de taguer

Deux constats mesurés qui concernent directement l'étape « après on déploie » :

**10.1 — Le commit de production `.214` n'est sur aucune branche.**
`c9e1413` n'est ancêtre ni de `main`, ni de `origin/arena/0e605e79-partikulier3` (tip `2783e6e`).
Il n'est atteignable que par des tags. Les releases `.212` et `.213`, elles, sont bien sur la branche.
Conséquence : le code en production n'est pas récupérable depuis une branche, et la branche n'est pas
un sur-ensemble de la production. La préservation de la phase 0 de la V3 (`git bundle` + restauration
testée) n'est donc pas théorique.

**10.2 — Taguer la branche déploierait 179 commits jamais fusionnés.**
`deploy.yml` ne publie que `build/deploy-artifacts/{partikulier-core,partikulier,polylang}`, donc pas
les dossiers de documentation. Mais le contenu déployé est celui du commit tagué : taguer le tip de la
branche envoie en production l'intégralité du travail non fusionné (179 commits, 77 fichiers), sans
revue ni passage par `main`.

**À trancher :** depuis quel commit le prochain tag `pre-release-*` sera-t-il créé ? La réponse doit
être explicite, écrite, et précéder la création du tag — pas la suivre.

### 10.3 Mesure de l'écart entre les deux candidats

Comparaison de l'arbre de production `c9e1413` avec le tip de branche `2783e6e`, **restreinte aux
chemins réellement déployés** (`theme/partikulier`, `plugin/`) :

```text
12 fichiers — 604 insertions, 3485 suppressions
```

| État | Fichier déployé |
|---|---|
| **D** | `theme/partikulier/apercu/profil-acheteur-{ar,en,fr}.html` |
| **D** | `theme/partikulier/inc/class-buyer-profile-preview.php` |
| **M** | `theme/partikulier/inc/class-whatsapp-verification.php` (+286) |
| **M** | `theme/partikulier/templates/page-connexion.php`, `page-mes-annonces.php` |
| **M** | `theme/partikulier/assets/js/main.js` (+71), `inc/class-form.php`, `functions.php` |
| **M** | `plugin/partikulier-core/src/Domain/Automation/AutomationService.php` |
| **A** | `theme/partikulier/tests/whatsapp-inbound-n8n-page-open.mjs` |

Autres faits : `2783e6e` porte **66 commits** absents de la lignée `.214` ; les releases `.212` et `.213`
sont des ancêtres de la branche, `.214` non.

### 10.4 Recommandation

**Créer le prochain `pre-release-*` depuis la lignée d'audit — `c9e1413` plus le correctif de
consentement — jamais depuis le tip de la branche.**

Motifs :

1. L'UAT valide le code de `c9e1413` ; le contrôle de traçabilité du workflow refuse explicitement un
   commit applicatif différent. Taguer `2783e6e` déploierait du code qui n'a pas été audité.
2. Le tip **supprime l'aperçu du profil acheteur** — trois pages et sa classe PHP — alors que `.214`
   venait de le publier. Suppression volontaire ou régression, la question doit être tranchée
   explicitement, pas absorbée par un tag de release.
3. `class-whatsapp-verification.php` gagne 286 lignes sur un chemin sensible, sans UAT correspondante.

**Corollaire, à vérifier avant de taguer :** si le correctif de consentement est **uniquement** dans le
harnais, alors la release déployée ne change pas et **aucun nouveau tag n'est nécessaire** — la
conclusion porte sur `.214` tel quel. Un nouveau `pre-release-*` ne se justifie que si un fichier
déployé change.

**Préserver `c9e1413` sans attendre :** ce commit n'est atteignable que par un tag. Un `git bundle`
vérifié et restauré (phase 0 de la V3) est la seule protection contre la disparition du code de
production.

---

## 11. Ce qu'il ne faut pas demander

Purger le cache pour forcer la preuve ; `force: true` ou masquer le bandeau ; créer un tag pour changer
un input ; `PK_THEME_CACHE_REQUIRED=true` avant preuve ; la charge avant la correction du consentement ;
un `pre-release-*` pendant la campagne ; toucher `prd`, déplacer un tag, retirer un check requis.
