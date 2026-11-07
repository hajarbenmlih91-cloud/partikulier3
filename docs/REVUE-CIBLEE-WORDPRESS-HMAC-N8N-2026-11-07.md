# Revue ciblée WordPress / HMAC / n8n — 2026-11-07

> Revue de code et de tests ciblée, **pas un audit formel**. Elle porte sur le checkout de la branche `arena/0e605e79-partikulier3` et sur les preuves CI mentionnées ci-dessous. Elle ne constitue ni une validation du staging actuellement déployé, ni une validation de production.

## Périmètre et inventaire

| Composant | Points d'entrée / fichiers examinés | Rôle et preuve disponible |
|---|---|---|
| WordPress REST | `plugin/partikulier-core/src/RestController.php`, `src/Rest/RouteRegistry.php` | Registre des routes `partikulier/v1`, permissions par route, contrôles d'accès et limites de débit. Contrats de collisions/routes et suites WordPress exécutés. |
| Automatisation entrante | `src/Domain/Automation/AutomationService.php`, `AutomationHmacTrait.php`, `AutomationPolicyTrait.php` | Secret partagé, signature HMAC, horodatage de 300 s, rotation active/précédente, audit et réception idempotente. |
| Leads / qualification / effacement | `src/Domain/Leads/LeadsContactTrait.php`, `LeadsEraseGuardTrait.php`, `LeadsRestTrait.php` | Qualification, garde dédiée d'effacement, limites et chemins d'effacement. Un défaut de validation de la réponse de qualification a été corrigé dans ce cycle. |
| Stockage et audit | `src/Database/SchemaStatementsTrait.php`, `src/AuditLogger.php` | `pk_automation_events` possède une contrainte unique sur `event_id`; l'empreinte du payload est persistée, pas le payload brut. Les échecs HMAC sont agrégés dans `pk_n8n_hmac_audit`. |
| Thème et sorties HTTP | `theme/partikulier/inc/class-automation-bridge.php`, `class-n8n-security.php`, `class-listing-approval.php`, `class-security.php` | Le thème délègue les règles n8n au plugin; le webhook sortant est testé avec transport simulé, TLS et redirections contrôlées. La clé par défaut de la passerelle d'administration a été supprimée. |
| n8n et validation jetable | `.github/workflows/ephemeral-n8n-boundary.yml` | WordPress, MariaDB et n8n éphémères; rejouabilité/idempotence, erreurs simulées et limites de sortie testées sans appel à un webhook réel. |
| CI, recette et déploiement | `.github/workflows/ci.yml`, `contrats-recette.yml`, `audit-uat*.yml`, `deploy.yml`, `php-version.yml` | CI à permissions de contenu en lecture; recette sur environnement jetable; workflows UAT/deploiement distincts et protégés. Aucun workflow UAT ou déploiement n'a été lancé pendant cette revue. |

## Constats et corrections

### R1 — Réponse de qualification absente ou invalide convertie en « intermédiaire » — corrigé, priorité moyenne

**Preuve avant correction :** `rest_set_qualification()` utilisait `rest_sanitize_boolean()` puis castait le résultat en booléen. Une valeur absente, inconnue ou non scalaire pouvait ainsi devenir `false`; pour un lead existant, le handler pouvait enregistrer `is_particulier = 0` au lieu de rejeter la requête.

**Impact :** erreur de classification et décision métier erronée si une réponse n8n est malformée. La route exige néanmoins l'authentification HMAC ou un administrateur.

**Correction :** parsing fermé aux booléens et valeurs explicitement permises; `wa_id` non scalaire et réponse `is_particulier` absente/invalide sont refusés avant toute mutation. Voir `plugin/partikulier-core/src/Domain/Leads/LeadsContactTrait.php`.

**Test :** `AUTO-QUALIFICATION-INVALID-INPUT` vérifie l'absence, la valeur inconnue, le tableau et l'entier hors domaine; les quatre renvoient HTTP 400 et n'écrivent aucun lead. Passé dans le run éphémère `42404865108`.

### R2 — Rejeu HMAC strictement « une seule fois » non démontré hors de `/automation-event` — risque résiduel, priorité moyenne

**Preuve de code :** `AutomationHmacTrait::check_automation_secret()` valide un horodatage dans une fenêtre de ±300 secondes et signe méthode, route, timestamp et corps. Il n'y a pas de registre de nonce/id de requête consommé une seule fois dans cette garde.

**Atténuation observée :** `/automation-event` utilise une contrainte unique sur `event_id`; rejeu identique renvoie un doublon, et réutilisation de l'ID avec un autre type/source/payload renvoie 409. Les handlers d'effacement sont conçus pour être idempotents.

**Limite :** cette idempotence n'est pas automatiquement transposable à chaque route HMAC (par exemple `/qualification`). L'absence de nonce est un écart à traiter route par route; je ne qualifie pas un replay de vulnérabilité exploitable sans établir son effet métier précis. Proposition : ajouter une clé de requête unique et une contrainte atomique seulement aux opérations pour lesquelles un replay a un effet distinct, avec contrat de concurrence et compatibilité n8n avant activation.

### R3 — Rotation « sans coupure » non configurée par l'écran admin — risque opérationnel, priorité moyenne

**Preuve de code :** `secret_keys()` accepte une clé précédente avec `previous_key_id` et échéance; en revanche, `save_admin_settings()` remplace seulement `automation_api_secret` et l'écran n'expose pas de procédure de rotation à deux clés.

**Impact :** remplacer le secret depuis l'écran peut rendre les appels n8n signés avec l'ancien secret invalides immédiatement, sauf si la clé précédente a été préparée séparément. La capacité de validation d'une ancienne clé existe, mais le parcours de rotation opérable et vérifiable n'est pas couvert ici.

**Suite proposée :** documenter une procédure sûre pour l'environnement actuel ou ajouter une action explicite de rotation qui conserve l'ancienne clé pour une durée définie; contrat : ancienne et nouvelle acceptées pendant la fenêtre, puis ancienne refusée. Pas de rotation de secret d'hébergement effectuée.

### R4 — Mode HMAC `log` accepte volontairement une signature invalide — garde de configuration

Le code journalise une signature invalide puis accepte la requête en mode `log`; c'est le comportement de migration prévu. Le mode `off` avec un secret configuré est promu en `enforce`, mais la valeur explicite `log` reste permissive. **La configuration effective du staging et de la production n'a pas été vérifiée.** Le mode de production doit rester `enforce`; un test de dépôt ne peut pas prouver la configuration hébergée.

### R5 — Clé historique de passerelle exposée dans l'historique Git — mesure opérationnelle requise

Le code courant n'a plus de fallback de clé et la documentation ne contient plus sa valeur. La valeur avait toutefois été commitée auparavant; cet historique n'a pas été réécrit. Toute installation qui aurait configuré cette valeur doit la remplacer par un secret aléatoire privé. Le correctif de dépôt n'a pas été déployé au staging.

### R6 — Actions GitHub référencées par tags majeurs — risque supply-chain résiduel, faible

Les workflows observés utilisent des références du type `actions/checkout@v4`, `actions/upload-artifact@v4` et `shivammathur/setup-php@v2`, pas des SHA complets. Les permissions déclarées sont restreintes à `contents: read` dans les workflows consultés; les jobs connectés UAT/deploiement sont séparés. Aucun audit exhaustif de provenance/transitivité des actions ni pinning SHA n'a été réalisé.

## Couverture vérifiée

### HMAC / contrôle d'accès / idempotence

Les contrats existants comprennent notamment : absence de secret; secret seul refusé lorsque l'enforcement est effectif; signature valide; corps altéré; timestamp périmé; secret faible refusé à l'enregistrement; clé inconnue et clé précédente expirée rejetées; mauvaise signature journalisée en mode `log`; rejeu d'un `event_id` identique; conflit de payload/type avec le même ID; entrées d'événement mal formées. Les routes d'export et de statut exigent la capacité `manage_options`; `/erase-lead` a une garde dédiée. Ce sont des couvertures ciblées par contrat, pas une preuve exhaustive de chaque permission dans toute l'installation WordPress.

### Runtime / défaillance

- 12 requêtes signées concurrentes : 12 réponses HTTP 200, une acceptation initiale, 11 doublons et une seule ligne.
- Timeout n8n simulé puis retry : erreur enregistrée sans secret, reprise avec le même identifiant et payload sémantique stable.
- Destination loopback rejetée; aucun webhook externe réellement appelé.
- Entrées de qualification malformées refusées après le correctif.
- Import et replay du workflow n8n de test réussis. Le runner interne n8n a averti que Python 3 était absent; les chemins dépendant d'un runner Python natif ne sont donc pas couverts par ce succès.

### Résultats CI observés sur le commit `219bae3`

- `42404861398` — CI réussie : lint/package et contrats de routes statiques sur PHP 8.1, 8.2 et 8.3.
- `42404865108` — WordPress/MariaDB/n8n jetables réussis; le résumé inclut `AUTO-QUALIFICATION-INVALID-INPUT` ainsi que les contrats de concurrence, retry et sortie HTTP.
- `42404864824` — recette WordPress PHP 8.3 réussie : suites dynamiques, tests HTTP/browser et oracle annoncé 455/455.

## Non testés / limites de sortie

- Aucun déploiement, aucun audit connecté UAT, aucun appel au staging durant cette reprise.
- Aucun test de charge nouveau. Le rapport historique `42397561220` indiquait 10 req/s réussi et 100 req/s échoué; métriques détaillées indisponibles. Il faut définir objectif, environnement et diagnostic avant tout nouvel essai.
- Aucun test destructif ni test de production.
- Configuration des secrets effectifs, de la rotation, du mode HMAC, des journaux, du proxy, de la base et des workflows n8n réellement hébergés non vérifiée.
- Les constats UAT historiques restent ouverts : `X-Powered-By`, contrastes, titre arabe, en-têtes de sécurité, XML-RPC, liens sans nom; le dépôt corrigé n'est pas le staging actuellement déployé.

## État de sortie

- **Réussi :** les contrats cités ci-dessus, y compris la vérification ciblée du nouveau correctif.
- **Échoué historiquement :** charge à 100 req/s et constats UAT consignés ci-dessus; aucun nouvel échec produit par cette reprise.
- **Non testé :** déploiement/staging, charge nouvelle, vérification d'hébergement et rotation opérationnelle des secrets.
- **Conclusion :** revue de code ciblée et améliorations de sécurité livrées; pas de certification de production ni d'audit formel.
