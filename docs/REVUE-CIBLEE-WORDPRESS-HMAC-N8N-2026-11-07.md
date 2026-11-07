# Revue ciblée WordPress / HMAC / n8n — 2026-11-07

> Revue de code et de tests ciblée, **pas un audit formel**. Elle porte sur le checkout de la branche `arena/0e605e79-partikulier3` et sur les preuves CI mentionnées ci-dessous. Elle ne constitue ni une validation du staging actuellement déployé, ni une validation de production.

## Périmètre et inventaire

| Composant | Points d'entrée / fichiers examinés | Rôle et preuve disponible |
|---|---|---|
| WordPress REST | `plugin/partikulier-core/src/RestController.php`, `src/Rest/RouteRegistry.php` | Registre des routes `partikulier/v1`, permissions par route, contrôles d'accès et limites de débit. Contrats de collisions/routes et suites WordPress exécutés. |
| Automatisation entrante | `src/Domain/Automation/AutomationService.php`, `AutomationHmacTrait.php`, `AutomationPolicyTrait.php` | Secret partagé, signature HMAC, horodatage de 300 s, rotation active/précédente, audit et réception idempotente. |
| Leads / qualification / effacement | `src/Domain/Leads/LeadsContactAuthorizationTrait.php`, `LeadsContactTrait.php`, `LeadsQualificationTrait.php`, `LeadsIdempotencyTrait.php`, `LeadsEraseGuardTrait.php`, `LeadsRestTrait.php` | Qualification, garde dédiée d'effacement, limites et chemins d'effacement. Un défaut de validation de la réponse de qualification a été corrigé dans ce cycle. |
| Stockage et audit | `src/Database/SchemaStatementsTrait.php`, `src/AuditLogger.php` | `pk_automation_events` possède une contrainte unique sur `event_id`; l'empreinte du payload est persistée, pas le payload brut. Les échecs HMAC sont agrégés dans `pk_n8n_hmac_audit`. |
| Thème et sorties HTTP | `theme/partikulier/inc/class-automation-bridge.php`, `class-n8n-security.php`, `class-listing-approval.php`, `class-security.php` | Le thème délègue les règles n8n au plugin; le webhook sortant est testé avec transport simulé, TLS et redirections contrôlées. La clé par défaut de la passerelle d'administration a été supprimée. |
| n8n et validation jetable | `.github/workflows/ephemeral-n8n-boundary.yml` | WordPress, MariaDB et n8n éphémères; rejouabilité/idempotence, erreurs simulées et limites de sortie testées sans appel à un webhook réel. |
| CI, recette et déploiement | `.github/workflows/ci.yml`, `contrats-recette.yml`, `audit-uat*.yml`, `deploy.yml`, `php-version.yml` | CI à permissions de contenu en lecture; recette sur environnement jetable; workflows UAT/deploiement distincts et protégés. Aucun workflow UAT ou déploiement n'a été lancé pendant cette revue. |

## Constats et corrections

### R1 — Réponse de qualification absente ou invalide convertie en « intermédiaire » — corrigé, priorité moyenne

**Preuve avant correction :** `rest_set_qualification()` utilisait `rest_sanitize_boolean()` puis castait le résultat en booléen. Une valeur absente, inconnue ou non scalaire pouvait ainsi devenir `false`; pour un lead existant, le handler pouvait enregistrer `is_particulier = 0` au lieu de rejeter la requête.

**Impact :** erreur de classification et décision métier erronée si une réponse n8n est malformée. La route exige néanmoins l'authentification HMAC ou un administrateur.

**Correction :** parsing fermé aux booléens et valeurs explicitement permises; `wa_id` non scalaire et réponse `is_particulier` absente/invalide sont refusés avant toute mutation. Le handler exige maintenant aussi un `provider_message_id` stable et journalise la consommation du message dans la transaction de mutation. Voir `plugin/partikulier-core/src/Domain/Leads/LeadsQualificationTrait.php`; la garde de consommation atomique se trouve dans `LeadsIdempotencyTrait.php`.

**Tests historiques :** `AUTO-QUALIFICATION-INVALID-INPUT` dans le run éphémère `42404865108` vérifiait l'absence, la valeur inconnue, le tableau et l'entier hors domaine; les quatre renvoyaient HTTP 400 et n'écrivaient aucun lead. Les nouveaux contrats de rejeu après ce rapport sont listés dans le suivi ci-dessous.

### R2 — HMAC authentifie l'appel, mais ne consomme pas globalement un nonce — traitement ciblé, résiduel documenté

`AutomationHmacTrait::check_automation_secret()` vérifie la signature de méthode, route, horodatage et corps dans une fenêtre de ±300 secondes. La garde ne rend pas chaque signature « une seule fois » : l'effet métier doit donc être protégé par endpoint.

**Corrections dépôt :**
- `/preferences` exige maintenant le `provider_message_id` Meta stable; l'ID, le remplacement des préférences et l'acquittement sont atomiques. Rejeu identique → `replayed: true` sans mutation; même ID avec payload différent → HTTP 409.
- `/consent` consomme l'ID dans la même transaction que l'état de consentement; un ancien accord déjà consommé puis rejoué après `STOP` ne réactive pas le consentement. Valeur `granted` malformée → HTTP 400 au lieu d'une révocation implicite.
- `/opt-out` consomme son ID et écrit la révocation dans une transaction, sérialisée sur la ligne lead; le doublon et la course ne réexécutent pas l'opération.
- `/qualification` exige désormais un ID stable et consomme l'ID avec la qualification et l'audit; rejeu exact reconnu sans nouvel audit.
- `/contact-authorization` conserve son contrat existant avec ID provider et déduplication atomique au moment de l'autorisation; son parcours de qualification préalable n'est pas modifié pour éviter de casser un éventuel flux de continuation. Le contrat exact des workflows hébergés reste inconnu.

**Routes connexes :** `/automation-event` a l'unicité `event_id`; `/credentials-resend-accepted` vérifie le `resend_request_id` courant et répond idempotemment; `/erase-lead` est un effacement idempotent avec secret dédié (ce n'est pas le même contrôle HMAC); `/approved-listings` est un GET sans mutation, mais retourne des coordonnées propriétaire et reste répétable dans la fenêtre HMAC — réponse désormais `Cache-Control: no-store`, sans prétendre empêcher une seconde lecture authentifiée. Les routes d'export/statut exigent `manage_options`, pas la garde HMAC n8n.

**Compatibilité :** l'ajout d'ID obligatoire à `/preferences` et `/qualification` est un changement de contrat pour les anciens callers. Le guide n8n du dépôt a été mis à jour; les workflows réellement hébergés n'ont pas été consultés et doivent être adaptés/vérifiés avant déploiement. Une couverture de concurrence dédiée existe maintenant pour `/preferences`; les autres nouveaux handlers ont des contrats de rejeu séquentiels, pas de charge ni d'essai concurrent live.

### R3 — Rotation « sans coupure » — parcours implémenté, exécution hébergée non vérifiée

**Correction dépôt :** pour un secret WordPress, l'écran admin active une nouvelle clé avec un Key ID aléatoire, conserve l'ancienne comme clé précédente pendant 24 heures et refuse une seconde rotation pendant le chevauchement. Le même secret saisi ne provoque pas de changement de Key ID. Pour un secret d'environnement, l'écran propose une préparation explicite : snapshot de la clé courante sous son Key ID, échéance 24 h et génération d'un prochain Key ID; l'opérateur bascule ensuite `PARTIKULIER_N8N_SECRET` et `PARTIKULIER_N8N_KEY_ID` dans l'hébergement. Les clés ne sont jamais réaffichées.

**Tests ajoutés :** contrats runtime jetables vérifient la rotation DB (ancienne + nouvelle clé acceptées, durée, second démarrage refusé, même secret sans rotation) et la préparation/basculement d'une clé d'environnement. Dans le run `42410958329`, l'étape `Run versioned PHP runtime contracts in disposable WordPress` s'est terminée sans erreur de processus, mais l'étape précédente d'initialisation a échoué. Le workflow peut alors court-circuiter les contrats en les marquant « non mesuré — WordPress setup incomplete »; faute d'accès à l'artifact/log détaillé, ce run ne prouve donc pas leur exécution.

**Limite :** aucune valeur de secret, procédure opérateur, permission d'hébergement, rotation effective ou réussite UAT n'a été vérifiée. Voir `theme/partikulier/docs/n8n-key-rotation.md`; ne pas lancer cette procédure en production sans coordination des deux sens n8n/WordPress.

### R4 — Mode HMAC `log` accepte volontairement une signature invalide — garde de configuration

Le code journalise une signature invalide puis accepte la requête en mode `log`; c'est le comportement de migration prévu. Le mode `off` avec un secret configuré est promu en `enforce`, mais la valeur explicite `log` reste permissive. **La configuration effective du staging et de la production n'a pas été vérifiée.** Le mode de production doit rester `enforce`; un test de dépôt ne peut pas prouver la configuration hébergée.

### R5 — Clé historique de passerelle exposée dans l'historique Git — mesure opérationnelle requise

Le code courant n'a plus de fallback de clé et la documentation ne contient plus sa valeur. La valeur avait toutefois été commitée auparavant; cet historique n'a pas été réécrit. Toute installation qui aurait configuré cette valeur doit la remplacer par un secret aléatoire privé. Le correctif de dépôt n'a pas été déployé au staging.

### R6 — Actions GitHub référencées par tags majeurs — risque supply-chain résiduel, faible

Les workflows observés utilisent des références du type `actions/checkout@v4`, `actions/upload-artifact@v4` et `shivammathur/setup-php@v2`, pas des SHA complets. Les permissions déclarées sont restreintes à `contents: read` dans les workflows consultés; les jobs connectés UAT/deploiement sont séparés. Aucun audit exhaustif de provenance/transitivité des actions ni pinning SHA n'a été réalisé.

## Couverture vérifiée

### HMAC / contrôle d'accès / idempotence

Les contrats couvrent notamment : absence de secret; signature valide; corps altéré; timestamp périmé; clé inconnue/précédente expirée; mode `log`; rejeu/conflit d'`event_id`; rejeu ciblé des routes leads (consentement, STOP, préférences, qualification); rotation des clés DB et environnement; `no-store` sur la récupération de coordonnées. `/export/interests` et `/lead/status` exigent `manage_options`; `/erase-lead` a une garde dédiée. Couverture ciblée seulement, pas preuve exhaustive des permissions ou du comportement des workflows hébergés.

### Couverture endpoint par endpoint et compatibilité

Le code protège les effets mutatifs par IDs métier spécifiques plutôt que par un cache global de signatures. Le cache global n'est pas activé car il casserait les retries et replays idempotents documentés d'`/automation-event`. Les tests déposés comprennent un replay concurrent de 12 appels signés vers `/preferences` dans WordPress jetable; les contrats leads couvrent les replays de consentement, STOP, preferences, qualification et conflit de payload. La présence de `provider_message_id` dans les workflows n8n hébergés n'est pas prouvée. Le handler `/contact-authorization` a un cas de continuation de qualification existant qui réutilise l'ID; il n'a donc pas été modifié pour consommer les demandes `manual_review` avant validation du workflow live.

### Runtime / défaillance

- 12 requêtes signées concurrentes : 12 réponses HTTP 200, une acceptation initiale, 11 doublons et une seule ligne.
- Timeout n8n simulé puis retry : erreur enregistrée sans secret, reprise avec le même identifiant et payload sémantique stable.
- Destination loopback rejetée; aucun webhook externe réellement appelé.
- Entrées de qualification malformées refusées après le correctif.
- Le run historique `42410660188` (avant le découpage modulaire) a passé l'import/replay n8n de test. Son runner interne a averti que Python 3 était absent; les chemins dépendant d'un runner Python natif ne sont donc pas couverts. Sur `42410958329`, le job global a échoué avant la fin de l'initialisation; le statut vert de l'étape de contrats ne prouve pas leur exécution, car elle peut sortir sans tester lorsque WordPress n'est pas prêt.

### Résultats récents et correction DA-002

- Le premier essai `42410660277` avait échoué sur DA-002 : `LeadsContactTrait.php` comptait 524 lignes, au-delà du plafond d'exception 450 alors configuré.
- Le découpage conserve les méthodes et leur composition dans `LeadService`, mais sépare désormais l'autorisation (`LeadsContactAuthorizationTrait`), le transport lead (`LeadsContactTrait`), la qualification (`LeadsQualificationTrait`) et les helpers de rejeu (`LeadsIdempotencyTrait`). Le contrat DA-002 a été resserré : **chaque module listé doit maintenant être ≤300 lignes**, sans exception. Comptages locaux : 161 (`LeadsContactTrait`), 231 (`LeadsContactAuthorizationTrait`), 118 (`LeadsQualificationTrait`) et 61 (`LeadsIdempotencyTrait`) lignes; `LeadsRestTrait.php` en fait 264. La vérification locale de la liste DA-002 compte 19 modules, tous ≤300 lignes.
- CI `42410946794` et CI après découpage strict `42411317147` : **réussies**, lint/package et contrats statiques sur PHP 8.1, 8.2, 8.3.
- Recettes `42410958277`, `42411132245` puis `42411322140` (sur `608d519`) : **échec pendant « Installer WordPress et les livrables »**, avant les suites dynamiques; le contrat DA-002 n'a donc pas été réexécuté dans ces runs. Les logs détaillés n'ont pas pu être récupérés (`gh run view --log-failed` reçoit EOF). Le correctif structurel est prêt, mais la recette complète et l'oracle 456/456 restent **non validés** après ce découpage.
- Run jetable `42410958329` : lint PHP exécuté/réussi; job global en échec pendant l'initialisation. L'étape runtime a un code de sortie réussi, mais les contrats ne sont pas validés faute de confirmation que le marqueur de disponibilité WordPress ait été créé.

### Résultats CI historiques sur le commit `219bae3`

- `42404861398` — CI réussie : lint/package et contrats de routes statiques sur PHP 8.1, 8.2 et 8.3.
- `42404865108` — WordPress/MariaDB/n8n jetables réussis; le résumé inclut `AUTO-QUALIFICATION-INVALID-INPUT` ainsi que les contrats de concurrence, retry et sortie HTTP.
- `42404864824` — recette WordPress PHP 8.3 réussie : suites dynamiques, tests HTTP/browser et oracle annoncé 455/455.

## Non testés / limites de sortie

- Aucun déploiement, aucun audit connecté UAT, aucun appel au staging durant cette reprise.
- Aucun test de charge nouveau. Le rapport historique `42397561220` indiquait 10 req/s réussi et 100 req/s échoué; métriques détaillées indisponibles. Il faut définir objectif, environnement et diagnostic avant tout nouvel essai.
- Aucun test destructif ni test de production.
- Configuration effective des secrets, de la rotation, du mode HMAC, des journaux, du proxy, de la base et des workflows n8n réellement hébergés non vérifiée; les résultats CI du dépôt ne lèvent pas cette limite.
- Les constats UAT historiques restent ouverts : `X-Powered-By`, contrastes, titre arabe, en-têtes de sécurité, XML-RPC, liens sans nom; le dépôt corrigé n'est pas le staging actuellement déployé.

## État de sortie historique au 2026-11-07

Les résultats ci-dessous décrivent l'état antérieur à la reprise du 8 novembre; voir l'addendum daté qui suit, lequel les complète et remplace les conclusions devenues obsolètes.

- **Réussi à cette date :** CI `42411317147` sur `608d519`; lint PHP du workflow `42410958329`; syntaxe PHP via parser local sur 59 fichiers source/contrat, comptage local des 19 modules DA-002, et hygiène/lints Shell, JavaScript, JSON.
- **Échoué / incomplet à cette date :** `42410660277` a exposé le dépassement DA-002, corrigé structurellement; les relances `42410958277`, `42411132245` et `42411322140` n'ont pas atteint les tests car l'installation des livrables échouait. Le run `42410958329` avait échoué à l'initialisation WordPress.
- **Non testé à cette date :** recette complète après le découpage, déploiement/staging, nouvelle charge, vérification d'hébergement et rotation opérationnelle des secrets.

## Addendum — revue et preuves au 2026-11-08

Cet addendum est la référence pour l'état courant de la branche `arena/0e605e79-partikulier3`; il ne transforme pas cette revue ciblée en audit formel.

### Preuves déjà obtenues

- Recette `42422020565` : **réussie**, oracle **456/456**. CI push `42422018033` : **réussie**.
- Environnement jetable `42423301329` : **réussi** pour les vérifications alors présentes. Workflow n8n à destinations autorisées seulement; première requête HMAC acceptée; rejeu exact reconnu; 12 événements concurrents → 1 acceptation, 11 doublons et une seule ligne; 12 retries `/preferences` → 1 mutation et 11 replays; lint PHP/runtime indiqué réussi par ce run.
- Ces preuves confirment le dépôt testé à ces SHA et ne prouvent ni secrets/paramètres hébergés, ni disponibilité/performance d'UAT ou production.

### Corrections supplémentaires préparées dans le checkout courant

- `AuditLogger::record()` expurge désormais récursivement les champs dont les noms désignent secrets, tokens, signatures, authorization, mots de passe, credentials, cookies, clés API/privées ou nonces, également dans les objets/tableaux imbriqués. Le contrat `audit-logger-redaction-contract.php` vérifie conservation des champs non sensibles et suppression des valeurs sentinelles; **PASS dans le run éphémère `42424765237`**.
- `AutomationService` et la garde HMAC refusent les corps entrants supérieurs à **65 536 octets** avec HTTP 413 avant validation métier/écriture; le contrat `automation-input-boundary-contract.php` couvre la réponse de garde; **PASS dans `42424765237`**. Le premier run `42424538438` avait échoué sur `strlen(null)` dans un contrat legacy qui ne fournissait aucun body; cast défensif ajouté en `a378edc`, puis le contrat complet a réussi. Les limites HTTP/proxy en amont ne sont pas vérifiées.
- Le compteur HMAC limité à 100 événements par heure est un **compteur d'audit plafonné, pas un limiteur de débit**. La formulation du code a été corrigée; des requêtes invalides peuvent toujours atteindre la garde et provoquer du travail. Une protection/rate limit effective doit être confirmée ou ajoutée selon l'exposition hébergée.
- La limite applicative de 64 KiB est un nouveau contrat et peut rejeter un payload légitime plus volumineux. Vérifier la taille maximale des événements produits par les workflows n8n réellement hébergés avant tout déploiement; la limite du proxy/webserver doit être alignée.
- Les références SSH/`WP_PATH` du workflow `deploy.yml` ont été retirées de l'environnement global du job et sont injectées seulement dans les étapes qui en ont besoin; l'étape de build précède l'accès à SSH. Un test statique a été ajouté à `scripts/lint.sh`. Cela réduit la portée d'exposition des secrets côté runner mais ne constitue pas un essai de déploiement.
- Une caractérisation de charge CI jetable est câblée à `ephemeral-n8n-boundary.yml`: 300 événements synthétiques uniques à 10 RPS pendant 30 s, concurrence plafonnée à 10, cible verrouillée sur `127.0.0.1:8080`, p95 ≤1 000 ms, débit ≥9,5 RPS et exactement 300 lignes. Le script refuse les autres hôtes/ports, ne journalise ni corps, ni signature, ni réponse brute. **Run `42424765237` réussi** : 300 événements acceptés, débit mesuré 10,0 RPS, p95 29,09 ms, 300 lignes en base; l'environnement était le WordPress/MariaDB jetable du job. Aucune charge UAT ou production n'a été lancée.

### Vérifications locales de cette modification (pas CI)

- `git diff --check` : **réussi**.
- `bash scripts/lint.sh` : **réussi** pour shell, JavaScript, JSON, catalogues, hard-coded strings, hygiène d'artefacts et test statique de portée SSH. Le script a explicitement **sauté le lint PHP** car l'exécutable PHP n'est pas installé localement.
- Parseur YAML PyYAML : **réussi** sur `deploy.yml` et `ephemeral-n8n-boundary.yml`.
- Parseur PHP installé temporairement hors du dépôt : syntaxe analysée sans erreur pour les trois fichiers PHP runtime modifiés et les deux contrats ajoutés; c'est un contrôle syntaxique local secondaire. `php -l` sur tous les fichiers PHP du thème et du plugin a également **réussi dans le job PHP 8.3 du run `42424765237`**.
- `py_compile` et test de portée des secrets : **réussis**. La garde du générateur rejette une cible publique/non autorisée. Un stub HTTP loopback, hors WordPress, a vérifié deux requêtes HMAC correctement signées et espacées sans rafale; ce stub **ne valide pas** l'acceptation WordPress ni la charge de 10 RPS.
- Les nouveaux contrats PHP et l'essai 10 RPS réel dans WordPress éphémère sont ensuite **passés** dans `42424765237`; cette preuve dynamique complète le parseur syntaxique local. Le run antérieur `42424538438` a échoué sur body `null`, défaut corrigé avant le run de succès. L'avertissement CI sur `actions/checkout@v4` et `actions/upload-artifact@v4` exécutées sous Node.js 24 reste à traiter par la mise à jour/pinning des actions.

### Risques et limites restant ouverts

- Mode HMAC explicite `log` accepte encore les signatures invalides; la valeur effective en staging/production n'est pas vérifiée. La production doit utiliser `enforce`.
- Workflows n8n réellement hébergés, valeurs/qualité/rotation des secrets, permissions DB, journaux, reverse proxy, limites de taille et contrôles de débit hébergés restent inconnus sans preuve d'environnement.
- Actions GitHub toujours référencées par tags majeurs plutôt que SHA complets; aucun audit exhaustif de provenance/transitivité. Aucun scan complet Gitleaks/Trivy ni `actionlint` n'a été exécuté dans ce checkout.
- La valeur historique de la clé de passerelle demeure dans l'historique Git; rotation requise si elle a jamais été utilisée.
- Aucun déploiement, test UAT externe, essai destructif, charge de staging/production ni audit formel n'a été effectué.

### État courant

- **Réussi :** recette `42422020565` (oracle 456/456), CI `42422018033`, runtime n8n/WordPress `42423301329`; CI push `42424535875` et `42424761754`; workflow jetable `42424765237` (runtime contracts + 300/300 charge synthétique; p95 29,09 ms); contrôles locaux listés ci-dessus.
- **Échoué puis corrigé :** le premier run des nouvelles modifications, `42424538438`, a exécuté avec succès intégration et charge (10,0 RPS, p95 31,44 ms, 300 lignes), mais le contrat legacy sans corps REST a exposé `strlen(null)` dans le nouveau contrôle de taille. Le cast défensif a été ajouté en `a378edc`; le run complet suivant `42424765237` a réussi.
- **Non testé / en attente :** workflows réellement hébergés, paramètres/secrets d'environnement, compatibilité des payloads actuels avec 64 KiB, déploiement, UAT, production et toute charge externe.
- **Conclusion :** les tests négatifs/concurrents, deux nouveaux contrats runtime et la charge bornée sont réussis dans l'environnement jetable du dépôt. Les limites hébergées demeurent; rien ici ne certifie UAT/production ni ne constitue un audit formel.
