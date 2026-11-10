# Prompt à transmettre — campagne UAT jusqu'à validation

**Version 3 — 10 novembre 2026 (nuit).** À copier tel quel.
Remplace la v2 : le commit `4f14acd9` est **récupérable depuis GitHub** et son contenu a été vérifié
(diff SHA-256 conforme, aucune assertion relâchée). Il n'y a donc plus rien à corriger dans le
harnais : la campagne reprend à la publication du tag.

---

## PROMPT

```text
Contexte. Deux runs, deux rouges. Run 42854353976 (audit-2.10.16-6.20.214-2 → 5aa70f44, parent
c9e1413) : failure, causé par le seul OWNER_RESULT. Le diagnostic du consentement est clos : 8
parcours sur 8 réussis, Accepter/Refuser × mobile 390×844 / desktop 1440×900, clavier et souris.
Le bandeau fonctionne ; il n'y a aucune preuve de défaut produit.

Le correctif est donc HARNAIS SEUL, et il existe déjà : le commit 4f14acd9, parent 5aa70f44. Il a
été vérifié le 10 novembre au soir :
  - git diff c9e1413 4f14acd9 | sha256sum = 3e5f98e8b7551efa1f7f2509a42a3191c7f53ab618b0c6aa1ea0940e24cb79e4
  - fichiers modifiés : .github/workflows/audit-uat.yml et theme/partikulier/tests/audit-uat-login.mjs
  - ALLOWED contient 3 entrées, un seul ajout : audit-uat-login.mjs ; APP_BASE_COMMIT inchangé
  - aucune des 7 assertions de -2 n'a disparu ; 8 assertions de consentement ont été ajoutées
  - l'assertion d'URL teste déjà /mes-annonces/ sur le pathname, donc insensible aux paramètres
Ne le modifie pas, ne l'amende pas, ne le reconstruis pas. Une modification invaliderait cette
vérification et imposerait un nouveau tag.

Gouvernance : UAT uniquement ; approbation par le propriétaire seul ; aucune production ; aucun
retag, aucun déplacement d'un tag existant ; un échec par permission manquante s'arrête et se nomme.

PARTIE A — À EXÉCUTER MAINTENANT

ÉTAPE 0 — État de départ. Confirme ces quatre points ; si l'un diffère, arrête-toi et signale :
 - 4f14acd9 existe sur GitHub comme objet NON RÉFÉRENCÉ (aucune branche, aucun tag ne pointe dessus,
   branches-where-head = 0, pulls = 0). Il est donc récupérable par SHA, mais n'est publié par aucun
   ref. La version antérieure du prompt disait « absent d'origin » : c'est faux, corrige ta note.
 - le tag audit-2.10.16-6.20.214-3 est absent d'origin ;
 - la policy uat contient encore audit-2.10.16-6.20.214-2 et pre-release-* ;
 - le dernier run audit-uat est 42854353976 ; aucun run depuis.

ÉTAPE 1 — Reprise du commit par SHA. Clone complet, pas superficiel.
  git ls-remote --tags origin 'audit-2.10.16-6.20.214-3'      # doit être vide
  git fetch origin 4f14acd9b56c93a798e06ca5f23cabdb185b6674
  git cat-file -t 4f14acd9b56c93a798e06ca5f23cabdb185b6674    # doit répondre : commit
  git rev-parse 4f14acd9b56c93a798e06ca5f23cabdb185b6674^      # doit être 5aa70f44…
  git diff c9e141376179f1a47e567361c28e747b6e891cd0 4f14acd9b56c93a798e06ca5f23cabdb185b6674 | sha256sum
Résultat attendu, sinon arrêt : 3e5f98e8b7551efa1f7f2509a42a3191c7f53ab618b0c6aa1ea0940e24cb79e4

ÉTAPE 2 — Tag annoté, local.
  git tag -a audit-2.10.16-6.20.214-3 4f14acd9b56c93a798e06ca5f23cabdb185b6674 -m "audit 2.10.16-6.20.214-3"
  git rev-parse audit-2.10.16-6.20.214-3^{commit}             # doit être 4f14acd9…
  git rev-parse audit-2.10.16-6.20.214-3                      # SHA d'objet du tag, à publier
L'ancien tag local 5db80d92 a disparu avec ton workspace : un tag annoté n'est pas reproductible à
l'identique. Le nouveau SHA d'objet est normal et doit figurer dans le rapport. Seul le commit est
invariant.

ÉTAPE 3 — Publication du tag seul.
  git push origin refs/tags/audit-2.10.16-6.20.214-3
Aucune branche, aucun autre ref. Ne touche ni à feat/profil-acheteur ni à main. Vérifie après coup
que le tag distant pointe sur 4f14acd9. La création d'un tag audit-* est permise par le ruleset
26966112 ; deletion et update y sont refusés, donc ce tag est définitif.

ÉTAPE 4 — Policy de l'environnement uat.
Remplace l'entrée exacte audit-2.10.16-6.20.214-2 par le motif audit-2.10.16-6.20.214-*, en
conservant pre-release-*. Supprime l'ancienne puis crée la nouvelle, sans PUT, existence vérifiée
avant création. Ne touche pas à prd, ne retire aucun reviewer ni check requis.

ÉTAPE 5 — Un seul run.
  gh workflow run audit-uat.yml --ref audit-2.10.16-6.20.214-3 -f load_test=true
 - Charge : 10 req/s (10 s) d'abord, 100 req/s (30 s) seulement si elle tient, arrêt immédiat sur 5xx.
   Ces étapes sont en continue-on-error : le résultat se lit dans l'artefact, jamais dans la
   conclusion du run.
 - Cache du thème : relever la date de l'entrée, GET /fr/annonces/?pk_audit=<horodatage> pour forcer
   un rendu PHP, puis rejouer le find existant. Aucune suppression de cache. Aucun NON VÉRIFIÉ
   converti en OK.
 - SSH réel : compte jetable créé puis supprimé, preuve de l'ID créé, preuve que `wp user get <ID>`
   échoue ensuite et que `wp user list --search=<login>` est vide.
 - LiteSpeed : sans en-tête x-litespeed-cache, écris NON VÉRIFIÉ.
 - Publie load-smoke.txt, load-full.txt, cache-ttfb.txt.
Lis les résultats étape par étape dans l'artefact, jamais depuis le résumé du run.

PARTIE B — APRÈS LE RUN, SANS RETOUCHER LE TAG

ÉTAPE 6 — Série E, hors du ref d'audit.
Les 24 tests existants couvrent la garde (A), Lighthouse (B), la sonde de cache (C) et LiteSpeed (D).
Aucun n'exerce audit-uat-login.mjs. Ajoute une série E qui échoue si les propriétés suivantes
régressent :
  E1 URL avec ?redirect_url → préfixe /mes-annonces accepté
  E2 URL sans paramètre → préfixe accepté
  E3 .pk-dashboard absent → le parcours échoue explicitement
  E4 bandeau absent → le parcours échoue explicitement
  E5 .pk-dashboard-empty présent → tableau de bord validé
  E6 cookie de session nommé mais valeur non lue
Emplacement : docs/audit/harnais-tests/, sur une branche dédiée, JAMAIS dans le tag d'audit.
Mettre ces tests dans le ref audité exigerait une quatrième entrée dans ALLOWED, c'est-à-dire élargir
définitivement la liste blanche pour un actif de test. Règle de contrôle : si docs/ apparaît dans le
diff c9e1413..tag, c'est une erreur.
Le journal doit annoncer le nombre réel de tests exécutés, sans arrondi de présentation.

ÉTAPE 7 — Seuils, avant tout run de validation.
Tableau versionné par type de page : performance, SEO, accessibilité, TBT et LCP maximaux,
avertissements acceptés vs bloquants. Chaque valeur justifiée par une référence externe ou par
l'historique du site, jamais par les résultats à venir.

ÉTAPE 8 — Run de validation. Seul run qui autorise à calculer la note.

Rapport attendu, en français, quatre sections distinctes :
 - tests simulés ;
 - contrôles réellement exécutés sur l'UAT, avec leurs chiffres ;
 - étapes ignorées ou en échec, avec la cause ;
 - portée exacte du cache : ce qui est prouvé, ce qui ne l'est pas.
Sépare outcome et conclusion. Ne présente jamais une étape verte comme un succès de suite.

Limite connue à déclarer, pas à corriger : dans audit-uat-login.mjs, si AUTHORIZED est faux, le
statut global peut être PASS alors que les quatre parcours ont été sautés. En pratique la graine SSH
doit avoir réussi pour arriver là, donc le trou est étroit — mais écris-le, et ne le répare pas dans
ce commit.

Interdits, sans exception : purger le cache pour forcer une preuve ; force:true ou masquer le
bandeau ; créer un tag pre-release-* ou release-* ; modifier ou déplacer archive/…, -1 ou -2 ;
amender le commit 4f14acd9 ; PK_THEME_CACHE_REQUIRED=true avant preuve de service ; la charge avant
la preuve de connexion ; toute relance automatique ; tout secret dans un rapport, un commit ou une
conversation.
```

---

## Notes d'usage (hors prompt)

- **Le commit est déjà bon.** La vérification du 10/11 au soir : SHA-256 du diff conforme à celui
  annoncé, une seule entrée ajoutée à `ALLOWED`, aucune des sept assertions de `-2` supprimée, huit
  assertions de consentement ajoutées, assertion d'URL déjà insensible aux paramètres. La « correction
  A » de la v2 était inutile : elle avait été écrite sans lire le code.
- **Un tag annoté n'est pas reproductible.** Le SHA d'objet `5db80d92` est perdu avec le workspace ;
  le nouveau diffère légitimement. Seul `4f14acd9` compte.
- **L'ordre a changé** : la série E passe après le run. Elle teste le harnais, pas le site, et
  l'insérer maintenant obligerait soit à amender un commit vérifié, soit à élargir `ALLOWED`.
- **Le tag `-3` est définitif** : le ruleset 26966112 refuse `deletion` et `update` sur `audit-*`.
  D'où la vérification du contenu *avant* publication, et non après.
