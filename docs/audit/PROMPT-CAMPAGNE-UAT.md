# Prompt à transmettre — campagne UAT jusqu'à validation

**Version 2 — 10 novembre 2026 (nuit).** À copier tel quel.
Remplace la version 1 : le diagnostic du consentement est clos et l'accès GitHub est rétabli,
donc le détour par un bundle de transmission n'a plus lieu d'être.

---

## PROMPT

```text
Contexte. Deux runs, deux rouges. Run 42799681392 (main) et 42854353976
(audit-2.10.16-6.20.214-2 → 5aa70f44, parent c9e1413) : failure, causé par le seul
OWNER_RESULT=failure. Hypothèse alors retenue : le bandeau pk-cookie-bar interceptait le clic
« Connexion » du harnais.

Le diagnostic du consentement a été exécuté depuis : 8 parcours sur 8 réussis,
Accepter/Refuser × mobile 390×844 / desktop 1440×900, au clavier et à la souris. Le bandeau
fonctionne. Il n'y a aucune preuve de défaut produit.

Conséquence, à ne pas rouvrir : le correctif est HARNAIS SEUL. Aucun fichier de thème déployé ne
change, .214 reste en place, aucun tag pre-release-* n'est nécessaire, aucun deploy.yml.

Ce qui reste non prouvé et que ce run doit trancher : après consentement, la connexion aboutit-elle
réellement, et le tableau de bord s'affiche-t-il pour ce compte ? Aucune preuve manuelle n'existe et
aucune ne doit être produite : la preuve doit venir du test versionné.

Gouvernance, déjà décidée : UAT uniquement ; approbation par le propriétaire seul, aucun autre
reviewer ; aucune production ; aucun retag, aucun déplacement d'un tag existant ; un échec par
permission manquante s'arrête et se nomme, sans contournement.

L'accès GitHub est rétabli pour cette conversation. Vérifie-le avant d'écrire quoi que ce soit :
  gh auth status

ÉTAPE 0 — État de départ, à confirmer avant d'agir. Les quatre points suivants ont été vérifiés le
10 novembre au soir ; si l'un a changé, arrête-toi et signale-le :
 - le commit 4f14acd9 n'est pas sur origin (l'ancienne tentative locale n'a jamais été publiée) ;
 - le tag audit-2.10.16-6.20.214-3 est absent d'origin ;
 - la policy de l'environnement uat contient encore audit-2.10.16-6.20.214-2 et pre-release-* ;
 - le dernier run audit-uat est 42854353976, aucun run n'a eu lieu depuis.

ÉTAPE 1 — Deux corrections dans le harnais, aucune autre.
Correction A, assertions tolérantes. L'URL de connexion porte
?auth_item=login-form&redirect_url=<valeur encodée>, et le retour peut ajouter un paramètre ou une
ancre. Toute égalité stricte sur l'URL doit devenir un test de préfixe : chemin /fr/mes-annonces,
en acceptant /mes-annonces et n'importe quel suffixe ?… ou #…
Même traitement pour le titre du tableau de bord : le texte réel est
« Bonjour {display_name}, vos annonces sont en ligne. » On vérifie la présence de .pk-dashboard-title,
son texte non vide, et la présence de .pk-dashboard-empty pour un compte neuf — jamais une égalité
sur une chaîne complète.

Correction B, les tests doivent couvrir le parcours. Les 24 tests actuels couvrent la garde de
traçabilité (série A), Lighthouse (B), la sonde de cache du thème (C) et LiteSpeed (D). Aucun
n'exerce audit-uat-login.mjs : ce fichier n'est couvert que par une validation de syntaxe. Ajoute une
série E qui échoue si les propriétés ci-dessus régressent, par exemple :
  E1 URL avec ?redirect_url → préfixe accepté
  E2 URL sans paramètre → préfixe accepté
  E3 .pk-dashboard absent → le parcours échoue explicitement
  E4 bandeau absent → le parcours échoue explicitement
  E5 .pk-dashboard-empty présent → tableau de bord validé
  E6 cookie de session nommé mais valeur non lue
Le journal doit annoncer le nombre réel de tests exécutés, sans arrondi de présentation.

Sur le parcours lui-même : les quatre contextes Accepter/Refuser × mobile/desktop restent,
sélection du consentement par rôle et nom accessible, clic ordinaire, fermeture du bandeau vérifiée,
absence de réapparition après rechargement et sur le tableau de bord.
Ne relâche aucune assertion existante. N'ajoute ni force:true, ni masquage du bandeau, ni cookie
posé pour sauter le consentement, ni augmentation de timeout pour contourner.

ÉTAPE 2 — Commit et tag, en local d'abord.
Commit dérivé de 5aa70f44 (base applicative c9e141376179f1a47e567361c28e747b6e891cd0).
Si tu reprends l'ancienne tentative locale 4f14acd9, supprime d'abord le tag local puis recrée-le
après amend — le tag distant n'a jamais existé, ce n'est pas un déplacement de ref publiée :
  git tag -d audit-2.10.16-6.20.214-3
  (amend)
  git tag -a audit-2.10.16-6.20.214-3 -m "..."
  git rev-parse audit-2.10.16-6.20.214-3^{commit}   # doit égaler le nouveau SHA
Publie : SHA du commit, parent, SHA de l'objet du tag, liste exacte des fichiers modifiés,
et `git diff c9e1413 <commit> | sha256sum`.

ÉTAPE 3 — Garde de traçabilité, à mettre à jour dans le même commit.
APP_BASE_COMMIT reste c9e141376179f1a47e567361c28e747b6e891cd0. La liste ALLOWED contient
aujourd'hui exactement deux entrées : .github/workflows/audit-uat.yml et
theme/partikulier/tests/staging-cache.php. Le parcours connecté n'y est pas : toute modification de
theme/partikulier/tests/audit-uat-login.mjs fait donc échouer l'audit à l'étape de garde, avant le
premier test. Le seul ajout autorisé est :
  theme/partikulier/tests/audit-uat-login.mjs
Justifie cet ajout dans le message de commit. Vérifie que la garde refuse encore deploy.yml, un autre
test et functions.php. Tout autre fichier hors liste doit faire échouer l'audit.

ÉTAPE 4 — Publication du tag seul.
  git ls-remote --tags origin 'audit-2.10.16-6.20.214-3'   # doit être vide
  git push origin refs/tags/audit-2.10.16-6.20.214-3
Aucune branche n'est poussée. Aucun autre ref. Ne touche ni à feat/profil-acheteur ni à main.
Vérifie après coup que le tag distant pointe bien sur le commit attendu.
La création de tags audit-* est permise par le ruleset 26966112 ; deletion et update sont refusés,
donc ce tag est définitif.

ÉTAPE 5 — Policy de l'environnement uat.
Remplace l'entrée exacte audit-2.10.16-6.20.214-2 par le motif audit-2.10.16-6.20.214-*, en
conservant pre-release-*. Supprime l'ancienne entrée puis crée la nouvelle, sans PUT. Vérifie
l'existence avant toute création. Ne touche pas à prd, ne retire aucun reviewer ni check requis.

ÉTAPE 6 — Un seul run.
  gh workflow run audit-uat.yml --ref audit-2.10.16-6.20.214-3 -f load_test=true
 - Charge : sonde 10 req/s (10 s) d'abord, 100 req/s (30 s) seulement si elle tient, arrêt immédiat
   sur 5xx. Ces étapes sont en continue-on-error : leur résultat se lit dans l'artefact, jamais dans
   la conclusion du run.
 - Cache du thème : relever la date de l'entrée, GET sur /fr/annonces/?pk_audit=<horodatage> pour
   forcer un rendu PHP, puis rejouer le find existant (-newermt '-30 minutes' -size +500c). Aucune
   suppression de fichier de cache. Aucun NON VÉRIFIÉ converti en OK.
 - SSH réel : création puis suppression du compte jetable, avec preuve de l'identifiant créé, preuve
   que `wp user get <ID>` échoue ensuite, et que `wp user list --search=<login>` est vide.
 - LiteSpeed : s'il n'y a pas d'en-tête x-litespeed-cache, écris NON VÉRIFIÉ.
 - Publie load-smoke.txt, load-full.txt, cache-ttfb.txt. Les conclusions d'étape ne prouvent pas
   l'exécution.

ÉTAPE 7 — Seuils, avant le run de validation.
Tableau versionné par type de page : performance, SEO, accessibilité, TBT et LCP maximaux,
avertissements acceptés vs bloquants. Chaque valeur justifiée par une référence externe ou par
l'historique du site, jamais par les résultats à venir. Ces seuils seront appliqués tels quels.

ÉTAPE 8 — Run de validation.
Seul run qui autorise à calculer la note. Tant qu'il n'est pas vert avec les seuils respectés, la
note reste non calculable et la release non validée.

Rapport attendu, en français, quatre sections distinctes :
 - tests simulés ;
 - contrôles réellement exécutés sur l'UAT, avec leurs chiffres ;
 - étapes ignorées ou en échec, avec la cause ;
 - portée exacte du cache : ce qui est prouvé, ce qui ne l'est pas.
Sépare toujours outcome et conclusion. Ne présente jamais une étape verte comme un succès de suite.

Interdits, sans exception : purger le cache pour forcer une preuve ; force:true ou masquer le
bandeau ; créer un tag pre-release-* ou release-* ; modifier ou déplacer archive/…, -1 ou -2 ;
PK_THEME_CACHE_REQUIRED=true avant preuve de service ; la charge avant la correction du consentement ;
toute relance automatique ; tout secret dans un rapport, un commit ou une conversation.
```

---

## Notes d'usage (hors prompt)

- **Le piège n'est pas la garde en elle-même, c'est l'oubli de son périmètre.** Un correctif de
  `audit-uat-login.mjs` fait échouer l'audit à l'étape de garde, avant le premier test, parce que ce
  fichier n'a jamais eu besoin d'y figurer tant qu'il n'était pas modifié. La bonne réponse est de
  l'ajouter explicitement et de le justifier — jamais de contourner.
- **« 24 PASS » ne dit rien du correctif.** Les séries A à D couvrent la garde, Lighthouse, la sonde
  de cache et LiteSpeed. Sans une série E, le parcours connecté n'est couvert que par une validation
  de syntaxe. C'est la différence entre *le harnais n'a pas régressé* et *le correctif fonctionne*.
- **Le tag `-3` est définitif** : le ruleset 26966112 refuse `deletion` et `update` sur `audit-*`.
  Une assertion d'URL trop stricte ne coûte donc pas un run, elle coûte un tag immortel de plus.
  D'où la correction A avant la publication.
- **Correctif harnais seul ⇒ aucun nouveau tag de release.** `.214` reste déployé ; l'audit conclut
  sur le code en place. Si le diagnostic avait désigné un défaut produit, il aurait fallu changer
  APP_BASE_COMMIT et auditer les fichiers de thème corrigés — ce n'est pas le cas.
