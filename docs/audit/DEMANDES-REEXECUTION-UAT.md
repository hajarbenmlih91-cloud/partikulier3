# Demandes à transmettre — campagne UAT jusqu'à validation

**Version 2 — 10 novembre 2026.** Remplace la version 1 du même jour.
**Motif du remplacement :** la version 1 répondait à « comment faire exister les cinq lignes du bilan »,
la version 2 répond à « comment tout tester jusqu'à validation ». Le bloc « charge » de la v1 entrait en
conflit avec l'ordre de la campagne : il a été fusionné dans l'étape 4 (§6 de ce document).

**Cible :** `uat` uniquement. Aucune production, aucun `deploy.yml`, aucun tag `pre-release-*`,
aucun retag, aucun déplacement de tag, aucun secret transmis. Un échec par permission manquante
s'arrête et se nomme, sans contournement.

---

## 1. Gouvernance — à faire une fois, avant tout le reste

> Remplace l'entrée `audit-2.10.16-6.20.214-2` de la policy de l'environnement `uat` par le motif
> `audit-2.10.16-6.20.214-*`, en conservant `pre-release-*`. Vérifie l'existence avant création ;
> aucun `PUT` si l'environnement existe ; ne touche pas à `prd` ; ne retire aucun check requis ;
> ne modifie aucun reviewer.

Motif et non `audit-*` : couvre toutes les itérations de **cette** release sans élargir aux futures.

**Décision à prendre maintenant :** `uat` n'a aucun reviewer, donc aucune validation à quatre yeux.
Pour l'itération, c'est acceptable. Pour le run qui fera foi, il faut une barrière : reviewer ajouté
à `uat`, ou retour à `uat-audit`. Trancher avant, pas après.

---

## 2. Diagnostic du consentement — lecture seule, avant toute correction

> Exécute un contrôle Playwright en lecture seule, sans relancer l'audit complet. Contextes :
> `390×844, isMobile` puis `1440×900`. Sur la page de connexion, réponds à quatre questions
> séparément, avec la trace :
>
> 1. Quels contrôles de consentement existent, visibles, avec rôle et nom accessible ?
> 2. Sont-ils atteignables et activables au clavier (`Tab`, `Shift+Tab`, `Enter`) et à la souris ?
> 3. Après **Refuser**, puis dans un contexte neuf après **Accepter**, la connexion aboutit-elle
>    réellement (marqueur de tableau de bord atteint) ?
> 4. Le comportement diffère-t-il entre mobile et desktop ?
>
> Rapporte aussi `document.elementFromPoint` au centre du bouton, mais comme indice, pas comme
> verdict : il indique qui reçoit le clic, pas si le blocage est légitime.
>
> Interdits : `force: true`, masquer ou retirer le bandeau, poser un cookie pour sauter le
> consentement, augmenter le timeout pour contourner.

**Grille de lecture :**

| Constat | Verdict |
|---|---|
| Consentement accessible, les deux choix fonctionnent, connexion OK ensuite | défaut du **harnais** seul |
| Consentement absent, inatteignable ou inopérant | défaut **produit** |
| Blocage après consentement donné | défaut **produit** |
| Problème seulement sur une taille d'écran | défaut **produit responsive** |

---

## 3. Correction aux deux niveaux

> **Harnais, dans tous les cas** : sélection du consentement par rôle et nom accessible, les deux
> choix testés, assertion que le dialogue est fermé, assertion que le bouton Connexion reçoit
> effectivement le clic, assertion que la connexion aboutit. Si le dialogue réapparaît ou intercepte
> encore, le test reste rouge.
>
> **Produit, si la grille du §2 le conclut** : corriger accessibilité, `z-index` et `pointer-events`
> du bandeau, garantir que les deux choix débloquent la connexion, ajouter des tests mobile et desktop.

Le harnais ne fait aujourd'hui **aucune** tentative de consentement (`audit-uat-login.mjs` : zéro
occurrence de `cookie`, `consent`, `accept`, `dialog`). Ce défaut est acquis indépendamment du verdict
produit.

---

## 4. Nouveau ref d'audit

> Nouveau commit dérivé de `c9e1413`, nouveau tag `audit-2.10.16-6.20.214-3`. Ne déplace aucun tag
> existant. Rejoue les 24 tests du harnais. Publie le SHA du commit, la liste exacte des fichiers
> touchés, et le SHA-256 de `git diff c9e1413 <commit>`.

---

## 5. — voir §6, tout part dans le même run

---

## 6. Le run unique : tout ce qui n'a jamais tourné

> Un seul run sur le tag `-3`, avec `load_test=true` :
>
> - **Charge** : sonde 10 req/s (10 s) d'abord, 100 req/s (30 s) seulement si la sonde tient, arrêt
>   immédiat sur 5xx, fenêtre explicitement autorisée. Rapporte p50/p95, débit réel et taux d'erreur.
>   Ces étapes sont en `continue-on-error` : leur résultat se lit dans l'artefact, pas dans la
>   conclusion du run.
> - **Cache du thème** : relever la date de l'entrée, GET sur `/fr/annonces/?pk_audit=<horodatage>`
>   pour forcer un rendu PHP, puis rejouer le `find` existant. Aucune suppression de fichier,
>   aucun `NON VÉRIFIÉ` converti en `OK`.
> - **SSH réel** : création puis suppression du compte jetable, avec preuve de l'identifiant créé et
>   de sa disparition.
> - **LiteSpeed** : reste `NON VÉRIFIÉ` tant que l'en-tête n'est pas émis côté hébergeur.

**LiteSpeed n'est pas une action de l'agent.** Demander la procédure, pas l'action :

> Produis la procédure exacte à exécuter dans hPanel pour que LiteSpeed Cache soit actif sur l'UAT et
> que `x-litespeed-cache` apparaisse. Où l'activer, quel réglage expose l'en-tête, comment vérifier,
> comment revenir en arrière. Ne modifie rien.

Le changement se fait avec l'accès propriétaire, **avant** ce run, sinon la ligne reste indéfiniment
non vérifiée.

---

## 7. Seuils de release — à figer avant le run de validation

> Propose un tableau de seuils versionné, par type de page : performance, SEO, accessibilité, TBT et
> LCP maximaux, avertissements acceptés vs bloquants. Justifie chaque valeur par une référence externe
> ou par l'historique du site, **pas par les résultats à venir**. Ces seuils seront validés par le
> responsable produit puis appliqués tels quels.

Sans cela, « non bloquant » reste une impression et le prochain run se juge à l'œil.

---

## 8. Tickets séparés

- FR home mobile : TBT 601 ms contre 3 ms en EN/AR — l'anomalie est la **page**, pas le mobile.
- Six avertissements de destinataire WhatsApp.
- SEO Lighthouse 61 (EN) / 69 (FR, AR) : examiner les audits responsables avant de fixer un seuil.
- Cache non prouvé, charge non concluante tant que non exécutée.

---

## 9. Run de validation finale — le seul qui autorise à calculer la note

Sur `uat-audit` (reviewer) ou sur `uat` avec reviewer ajouté. Même patch, `load_test=true`.
Tant qu'il n'a pas réussi **et** que les seuils du §7 ne sont pas satisfaits, la note reste
**non calculable** et la release **non validée**.

---

## 10. Ce qu'il ne faut pas demander

- **Purger le cache** pour forcer la preuve — cela détruit la preuve.
- **`force: true` ou masquer le bandeau** — cela transforme un test rouge en test menteur.
- **Créer un tag pour changer un input** — même tag, nouveau run.
- **`PK_THEME_CACHE_REQUIRED=true`** avant preuve de service.
- **La charge avant la correction du consentement** — on mesurerait un site dont un parcours est cassé.
- **Déploiement** : `deploy.yml` ne part que sur `pre-release-*` ; il n'y a rien à faire et le formuler
  comme une action serait une erreur de cadrage.
- Toucher `prd`, déplacer un tag, retirer un check requis.

---

## 11. Ce qui change par rapport à la version 1

| Bloc v1 | Devient | Raison |
|---|---|---|
| A — charge sur le tag `-2`, même tag | **fusionné au §6**, sur le tag `-3` | Deux runs de charge sur un hébergement mutualisé, dont un avant correction : risque doublé, mesure bruitée |
| B — LiteSpeed | §6, inchangé | action propriétaire |
| C — sonde cache | §6, inchangé | aucune suppression |
| D — verdict binaire via `elementFromPoint` | **remplacé par §2**, quatre questions | `elementFromPoint` seul ne distingue pas un blocage légitime d'un défaut |
| E — déploiement | §10 | inchangé |
