# Prompt à transmettre — campagne UAT jusqu'à validation

**Version du 10 novembre 2026 (soir).** À copier tel quel. Contexte : règles de refs en place
(rulesets 26966112 et 26966115), tag d'archive créé, bundles vérifiés.

---

## PROMPT

```text
Contexte. L'UAT réelle a tourné : run 42854353976, tag audit-2.10.16-6.20.214-2, commit 5aa70f44,
parent c9e1413. Verdict failure, causé par le seul OWNER_RESULT=failure : le bandeau pk-cookie-bar a
intercepté le clic « Connexion » pendant 30 s. Le harnais tests/audit-uat-login.mjs ne contient aucune
gestion du consentement (zéro occurrence de cookie/consent/accept/dialog) et n'ouvre qu'un contexte
390×844, isMobile. Le desktop n'a jamais été testé. Objectif : tout tester et valider, sur l'UAT
uniquement, en audit SSH réel.

Décision de gouvernance, déjà prise, à ne pas rouvrir : on reste sur l'environnement uat ;
approbation par le propriétaire seul, aucun autre reviewer. Aucune production, aucun deploy.yml,
aucun tag pre-release-*, aucun retag, aucun déplacement de tag existant. Un échec par permission
manquante s'arrête et se nomme, sans contournement.

ÉTAPE 1 — Diagnostic du consentement, lecture seule, sans relancer l'audit.
Contextes : 390×844 isMobile, puis 1440×900 desktop. Sur la page de connexion, réponds à quatre
questions séparément, avec la trace :
 1. quels contrôles de consentement existent (visibles, rôle, nom accessible) ?
 2. sont-ils activables au clavier (Tab, Shift+Tab, Enter, focus visible) et à la souris ?
 3. après Refuser, puis dans un contexte neuf après Accepter, la connexion aboutit-elle réellement
    (marqueur de tableau de bord atteint) ?
 4. le comportement diffère-t-il entre mobile et desktop ?
Rapporte document.elementFromPoint au centre du bouton comme indice seulement.
Interdits : force:true, masquer ou retirer le bandeau, poser un cookie pour sauter le consentement,
augmenter le timeout pour contourner. Aucune écriture sur l'UAT à cette étape.

ÉTAPE 2 — Décision avant correction, à écrire explicitement.
Le correctif de consentement tombe dans l'un de ces trois cas ; dis lequel, avec preuve :
 (a) harnais seul → aucun fichier déployé ne change, la garde de traçabilité reste sur c9e1413 et
     les deux fichiers autorisés. Aucun nouveau tag de release ne sera nécessaire.
 (b) produit (thème : page-connexion.php, main.js, CSS) → la garde de traçabilité du workflow ÉCHOUERA :
     APP_BASE_COMMIT est codé en dur sur c9e1413 et seuls .github/workflows/audit-uat.yml et
     theme/partikulier/tests/staging-cache.php sont autorisés. Pour auditer ce correctif, il faut
     changer APP_BASE_COMMIT vers le nouveau commit ET ajouter les fichiers corrigés à la liste
     ALLOWED, en justifiant chaque ajout ligne par ligne dans le message de commit. Toute autre
     modification reste interdite. Si tu ne veux pas toucher à cette garde, dis-le : le correctif
     produit ne peut alors pas être audité par ce harnais.
 (c) consentement absent ou inefficace chez un vrai visiteur → c'est un défaut produit : ouvre le
     ticket, corrige, puis applique (b).

ÉTAPE 3 — Correction.
Harnais, dans tous les cas : sélection du consentement par rôle et nom accessible, les deux choix
testés, assertion que le dialogue est fermé, assertion que le bouton Connexion reçoit le clic,
assertion que la connexion aboutit. Si le dialogue réapparaît ou intercepte encore, le test reste
rouge. Ne relâche aucune assertion existante.

ÉTAPE 4 — Nouveau ref d'audit.
Commit dérivé de c9e1413, tag audit-2.10.16-6.20.214-3 (la création de tags audit-* reste permise par
le ruleset 26966112, qui ne bloque que deletion et update). Ne déplace aucun tag. Rejoue les 24 tests.
Publie : SHA du commit, liste exacte des fichiers modifiés, SHA-256 de `git diff c9e1413 <commit>`.

ÉTAPE 5 — Policy d'environnement.
L'environnement uat n'accepte aujourd'hui que pre-release-* et le tag exact
audit-2.10.16-6.20.214-2. Remplace cette entrée par le motif audit-2.10.16-6.20.214-*, en conservant
pre-release-*. Vérifie l'existence avant création, aucun PUT, ne touche pas à prd, ne retire aucun
reviewer ni check requis.

ÉTAPE 6 — Un seul run, tout ce qui n'a jamais tourné.
gh workflow run audit-uat.yml --ref audit-2.10.16-6.20.214-3 -f load_test=true
 - Charge : sonde 10 req/s (10 s) d'abord, 100 req/s (30 s) seulement si elle tient, arrêt immédiat
   sur 5xx, fenêtre explicitement autorisée. Ces étapes sont en continue-on-error : leur résultat se
   lit dans l'artefact, pas dans la conclusion du run.
 - Cache du thème : relever la date de l'entrée, GET sur /fr/annonces/?pk_audit=<horodatage> pour
   forcer un rendu PHP, puis rejouer le find existant (-newermt '-30 minutes' -size +500c). Aucune
   suppression de fichier de cache, aucun NON VÉRIFIÉ converti en OK.
 - SSH réel conservé : création puis suppression du compte jetable, avec preuve de l'identifiant créé
   et de sa disparition.
 - LiteSpeed : s'il n'y a pas d'en-tête x-litespeed-cache, écris NON VÉRIFIÉ. L'activation est une
   action propriétaire, hors de ton périmètre.
 - Publie load-smoke.txt, load-full.txt, cache-ttfb.txt : les conclusions d'étape ne prouvent pas
   l'exécution.

ÉTAPE 7 — Seuils, avant le run de validation.
Propose un tableau versionné par type de page : performance, SEO, accessibilité, TBT et LCP maximaux,
avertissements acceptés vs bloquants. Justifie chaque valeur par une référence externe ou par
l'historique du site, jamais par les résultats à venir. Ces seuils seront appliqués tels quels.

ÉTAPE 8 — Run de validation. Seul run qui autorise à calculer la note. Tant qu'il n'est pas vert avec
les seuils respectés, la note reste non calculable et la release non validée.

Rapport attendu, en français, avec quatre sections distinctes :
 - tests simulés (les 24 du harnais) ;
 - contrôles réellement exécutés sur l'UAT, avec leurs chiffres ;
 - étapes ignorées ou en échec, avec la cause ;
 - portée exacte du cache (ce qui est prouvé, ce qui ne l'est pas).
Sépare toujours outcome et conclusion. Ne présente jamais une étape verte comme un succès de suite.

Interdits, sans exception : purger le cache pour forcer une preuve ; force:true ou masquer le bandeau ;
créer un tag pre-release-* ou release-* ; PK_THEME_CACHE_REQUIRED=true avant preuve de service ;
la charge avant la correction du consentement ; toute relance automatique ; tout secret dans un
rapport, un commit ou une conversation.
```

---

## Notes d'usage (hors prompt)

- Le point le plus piégeux est l'étape 2 (b) : la garde de traçabilité est codée en dur. Un correctif
  de thème fait **échouer l'audit à la troisième étape**, avant tout test. Ce n'est pas un bug, c'est
  la garde qui fait son travail — mais elle doit être mise à jour explicitement, jamais contournée.
- La création du tag `-3` est permise : le ruleset 26966112 ne bloque que `deletion` et `update`.
- Si le correctif s'avère être harnais seul, **aucun nouveau tag de release n'est nécessaire** :
  `.214` reste déployé et l'audit conclut sur le code en place.
