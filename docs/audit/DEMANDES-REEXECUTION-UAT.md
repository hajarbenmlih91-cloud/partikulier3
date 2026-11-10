# Demandes de réexécution UAT — formulations à transmettre

**Date :** 10 novembre 2026
**Contexte :** run 42854353976 (`audit-2.10.16-6.20.214-2`, commit `5aa70f44…`) — verdict `failure`,
causé uniquement par `OWNER_RESULT`. Cinq lignes du bilan sont « non faites » pour trois raisons
différentes. Chaque bloc ci-dessous est rédigé pour être copié tel quel.

**Rappels valables pour les cinq blocs :** aucune production, aucun `deploy.yml`, aucun tag
`pre-release-*`, aucun retag ni déplacement de tag existant, aucun secret transmis ou affiché.
Un échec par permission manquante s'arrête et se nomme, sans contournement.

---

## A. Tests de charge (10 puis 100 req/s)

**Demande à transmettre :**

> Réexécute l'audit sur **le même tag `audit-2.10.16-6.20.214-2`**, sans créer de nouveau tag ni
> modifier aucune protection, avec l'input de charge activé :
>
> ```bash
> gh workflow run audit-uat.yml --ref audit-2.10.16-6.20.214-2 -f load_test=true
> ```
>
> Contraintes : sonde 10 req/s (10 s) d'abord, charge 100 req/s (30 s) seulement si la sonde tient ;
> arrêt immédiat si des 5xx apparaissent ; pas de relance automatique. Rapport attendu : latences
> p50/p95, débit réel, taux d'erreur, et statut des deux étapes séparément. Précise que ces étapes
> sont en `continue-on-error: true` — leur échec ne rougit pas le run, donc leur résultat doit être
> lu dans l'artefact, pas dans la conclusion.

**Prérequis :** fenêtre creuse sur l'UAT ; accord explicite sur le risque hébergeur (mutualisé).
**Coût :** ~40 s de charge + réexécution complète du job (~4 min).
**Pourquoi sur le même tag :** une valeur d'input n'est pas une raison de créer un tag. Zéro
modification de protection, zéro approbation supplémentaire.

---

## B. Cache LiteSpeed

**Ce n'est pas une action de l'agent.** Le serveur n'émet pas `x-litespeed-cache` : rien ne peut être
prouvé depuis l'extérieur. C'est un réglage d'hébergement.

**Demande à transmettre :**

> Produis la procédure exacte à exécuter dans hPanel pour que LiteSpeed Cache soit actif sur le site
> UAT **et** que l'en-tête `x-litespeed-cache` apparaisse dans les réponses. Indique précisément :
> où l'activer, quel réglage expose ou masque l'en-tête, comment vérifier sans modifier le contenu,
> et comment revenir en arrière si l'activation change le comportement du site. Ne modifie rien
> toi-même : je ferai le changement avec mon accès propriétaire.

**Preuve attendue après activation :** deux GET consécutifs portant `x-litespeed-cache: hit`/`miss`,
puis relecture du contrat cache dans un nouveau run.

**Ne pas faire :** passer `PK_THEME_CACHE_REQUIRED=true` avant d'avoir cette preuve.

---

## C. Cache fichier du thème

La sonde a tourné, mais l'entrée existe sans être fraîche : PHP n'a pas été exécuté pendant l'audit.

**Demande à transmettre :**

> Ajoute une **sonde ponctuelle**, sans suppression ni purge, qui force un rendu PHP puis revérifie
> l'entrée. Concrètement : relever la date de modification de `${hote}_fr_annonces.html`, faire un
> GET sur `/fr/annonces/` avec une chaîne de requête unique (par exemple `?pk_audit=<horodatage>`)
> pour éviter une réponse servie par LiteSpeed, puis rejouer la recherche `find` existante
> (`-newermt '-30 minutes' -size +500c`) et le contrôle « les 2048 premiers octets commencent par du
> HTML ». Ne supprime aucun fichier de cache et ne modifie aucun réglage. Rapporte le résultat même
> s'il est négatif, et ne convertis pas un `NON VÉRIFIÉ` en `OK`.

**Prérequis :** autorisation d'émettre des requêtes supplémentaires contre l'UAT (lecture seule).
**Interdit :** effacer des entrées de cache pour « forcer » la preuve — cela détruirait la preuve au
lieu de la produire.

---

## D. Parcours de connexion — trancher avant de corriger

C'est le seul échec réel du run. Deux hypothèses de gravité très différente.

**Demande à transmettre :**

> Avant toute adaptation du harnais, détermine si `pk-cookie-bar` recouvre réellement le bouton de
> soumission de connexion. Méthode : sur la page de connexion UAT, en Chromium, évaluer
> `document.elementFromPoint` au centre du bouton et rapporter le nœud retourné (tag, classes,
> identifiant) ; répéter en mobile 375 px et en desktop 1440 px. Conclusion attendue, en une phrase :
> — nœud retourné **hors** du bandeau ⇒ défaut du **harnais** (clic trop tôt) ;
> — nœud retourné **dans** `pk-cookie-bar` ⇒ défaut **produit** : la connexion est impossible pour de
> vrais visiteurs, donc incident, pas artefact de test.
>
> Ne modifie pas le harnais avant d'avoir tranché, et ne conclus jamais « identifiants invalides »
> à partir de ce symptôme. Aucune écriture sur l'UAT à cette étape.

**Suite selon le verdict :**
- **harnais** → fermer/accepter le bandeau avant le clic, rejouer les 24 tests, produire un nouveau
  commit d'audit dérivé de `c9e1413` et un **nouveau** tag `audit-…-3` (le tag `-2` reste intact).
- **produit** → ticket produit, correctif de superposition, puis réexécution du parcours.

---

## E. Déploiement

**Rien à demander.** `deploy.yml` se déclenche sur les tags `pre-release-*` ; seuls des tags `audit-*`
ont été créés. Aucun déploiement n'était possible ni souhaitable. Ne pas inclure cette ligne dans les
demandes : la formuler comme une action à faire serait une erreur de cadrage.

---

## Points de gouvernance à joindre à l'envoi

1. **Policy `uat`** : elle contient encore `audit-2.10.16-6.20.214-2` en plus de `pre-release-*`.
   Demander le retour à `pre-release-*` seul, sauf si une réexécution sur ce tag est encore prévue —
   dans ce cas différer le retrait jusqu'à la fin, puis le faire.
2. **Approbation** : `uat` n'a aucun reviewer. Une réexécution sur le même tag n'ajoute donc pas de
   barrière. Pour le run de **validation finale** (celui qui doit faire foi), passer par `uat-audit`,
   qui porte un reviewer et une policy au tag exact.
3. **Artefact** : demander l'extraction de `load-smoke.txt`, `load-full.txt` et `cache-ttfb.txt` de
   l'artefact `audit-uat-16` — les conclusions d'étape ne prouvent pas l'exécution, comme l'a montré
   le run 42799771573.
