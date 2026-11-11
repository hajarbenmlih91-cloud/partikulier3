# Gate de mise en production — WordPress / n8n / proxy

**Statut actuel : NO-GO / BLOCKED.** Ce document est un gate opérationnel à compléter avec des preuves d'UAT et d'hébergement. Les succès CI du dépôt ne prouvent pas la configuration réellement servie. Aucun workflow n8n hébergé, secret, proxy, UAT ni production n'a été inspecté ou validé dans le cadre de cette revue.

Ce gate complète le playbook de déploiement [`DEPLOIEMENT.md`](DEPLOIEMENT.md), la note sur l'identité IP derrière CDN dans [`INSTALLATION.md`](INSTALLATION.md) et le guide de rotation [`n8n-key-rotation.md`](../theme/partikulier/docs/n8n-key-rotation.md). Il ne les remplace pas.

## Règles de sécurité

- Ne jamais joindre un secret, une clé privée, un export de credentials n8n ou une signature HMAC à ce dossier, un ticket ou un chat. Demander des **métadonnées redigées** et des résultats de test.
- Ne pas créer de route publique de diagnostic d'adresse IP. Utiliser une vérification contrôlée fournie par l'hébergeur ou un mécanisme temporaire authentifié, journalisant seulement le minimum et supprimé après le test.
- Ne jamais faire confiance à `X-Forwarded-For` / `X-Real-IP` directement depuis le client. Aucune modification du résolveur d'IP ne doit être faite avant documentation et test de la chaîne proxy de confiance.
- Ne pas lancer `prd` pour découvrir la configuration. Le déploiement `uat` est une étape de validation, mais seulement après revue du candidat et avec l'approbation UAT requise; il ne vaut pas preuve de production.
- Les échecs ci-dessous sont des bloqueurs de publication, pas des exceptions à accepter oralement.
- Le limiteur applicatif du dépôt utilise par défaut un upsert SQL atomique et des compteurs de fenêtre fixe dans les transients WordPress; les compteurs expirent via le nettoyage WordPress. APCu n'est accepté que sur un hôte explicitement configuré `single-node-apcu`; un objet-cache doit être explicitement configuré `shared-object-cache` et être partagé/atomique entre toutes les origines. Backend inconnu → HTTP 500; backend sélectionné mais indisponible → HTTP 503 (fail-closed), jamais de repli read-modify-write. Une réponse de quota 429 porte `Retry-After`. Ces protections restent applicatives : l'edge doit limiter avant PHP et les tests CI ne certifient pas sa configuration.

## Gate P0 — preuves obligatoires avant un tag de production

Renseigner le tableau avec un responsable, une date UTC et une référence vers une preuve expurgée. « PASS » signifie que la preuve est observée et relue, pas seulement demandée.

| Contrôle | État | Preuve minimale / critère d'acceptation |
|---|---|---|
| Identité IP derrière CDN/proxy (DP-4) | **BLOCKED** | Réponse écrite de l'hébergeur sur le pair TCP vu par PHP, la terminaison TLS, les en-têtes supprimés/réécrits et les plages de proxys de confiance; puis test contrôlé depuis deux origines et avec un `X-Forwarded-For` forgé. L'identité calculée doit rester non usurpable. Voir `INSTALLATION.md`, section « Limiteurs derrière le CDN ». |
| Limitation edge et taille HTTP | **BLOCKED** | Configuration redigée de la règle CDN/WAF/proxy : limite de débit avant PHP, politique pour les routes d'automatisation, burst/fenêtre, taille maximale, délai et réponse 429/413. Vérifier en staging que les requêtes légitimes passent et que l'abus est limité. Les limites applicatives seules ne sont pas un WAF. |
| Workflows n8n actifs | **BLOCKED** | Liste des workflows actifs et version publiée; export JSON expurgé sans credentials/secrets; confirmation des URLs/méthodes, canonicalisation HMAC exacte, Key ID, retries, identifiants d'idempotence, destinations et branche de gestion d'erreur. Vérifier la compatibilité avec les IDs requis par les endpoints. |
| Secrets / historique / rotation | **BLOCKED** | Attestation sans valeur de secret : stockage dans le gestionnaire prévu, permissions minimales, propriétaire, date de rotation et séparation UAT/production. Si une clé ayant figuré dans l'historique Git a pu être utilisée, la traiter comme compromise et la remplacer. Répéter en staging le runbook de rotation à chevauchement de 24 h et vérifier le retour arrière. |
| Configuration WordPress de production | **BLOCKED** | Le gate de déploiement en lecture seule passe sur la cible exacte : plugin actif, `WP_ENVIRONMENT_TYPE=production`, mode enregistré `enforce`, clé forte. Conserver seulement la sortie PASS/FAIL; ne jamais capturer la valeur de la clé. Le gate existe dans `deploy.yml`, mais n'a pas été exécuté sur l'hébergement. |
| UAT HMAC/n8n derrière le proxy réel | **NOT RUN** | Sur staging reproduisant CDN, PHP et configuration n8n : succès signé dans les deux sens; mauvais secret/signature, rejeu, timestamp hors fenêtre, conflit d'idempotence, payload juste sous/au-dessus de la limite, retry/time-out et erreurs. Résultats et logs expurgés, sans données personnelles ni credentials. |
| Sauvegarde / restauration | **NOT RUN** | Confirmer la sauvegarde pré-déploiement plugin/thème/base puis faire une répétition de restauration sur staging, incluant smoke test et retour à la version précédente. Une archive créée n'est pas une preuve qu'elle est restaurable. |
| Configuration GitHub des environnements | **BLOCKED** | Relecture API en lecture seule le 2026-11-08 : la règle de tag `prd` est maintenant `release-*` (le défaut antérieur `U+00A0release-` est corrigé). `uat` a toujours `pre-release-*` mais aucune règle `required_reviewers`; `prd` a une règle reviewer, identité non lisible. Variables et métadonnées de secrets `prd` restent inaccessibles via l'API utilisée (HTTP 403), donc leur présence n'est pas confirmée. Corriger la protection UAT et vérifier les variables avec un accès admin avant publication. |
| Approbations et propriétaire de changement | **NOT RUN** | SHA exact, CI verte, UAT signée par un responsable distinct, fenêtre approuvée, approbateur GitHub `prd`, personne de surveillance et décision explicite GO. Le gate GitHub exige `PRD_READINESS_ATTESTATION_SHA` égal au commit et `PRD_READINESS_EVIDENCE_URL` en HTTPS avant build/SSH; l'approbateur doit relire la preuve liée, car le workflow ne la télécharge ni ne la valide. |

**Règle de décision :** un `BLOCKED`, `NOT RUN`, résultat ambigu ou preuve non datée maintient **NO-GO**. Les tests de charge restent limités à l'environnement jetable/staging autorisé; aucune charge de sécurité ne doit être lancée en production.

## Séquence d'exécution sûre

1. **Geler le candidat** : noter le SHA de branche/tag, vérifier CI/package et s'assurer qu'il correspond exactement aux livrables.
2. **Lever DP-4 sans changement de code** : demander à l'hébergeur la chaîne de confiance CDN → origine et les règles de réécriture d'en-têtes. Vérifier la valeur réellement vue par PHP avec une méthode contrôlée; comparer la source connue, une seconde source et le cas d'en-tête forgé. Si la chaîne n'est pas prouvée, ne pas installer de parsing XFF; conserver le NO-GO « identité par visiteur non validée » et exiger un quota edge global.
3. **Inspecter n8n sans divulguer ses credentials** : inventorier les versions publiées et les contrats, faire examiner un export expurgé, puis vérifier les chemins d'erreur/retry et la présence des IDs idempotents requis.
4. **Valider les secrets et la configuration en staging** : séparer les clés; exécuter la rotation documentée et contrôler le mode `enforce`, sans copier de secret dans les preuves.
5. **Jouer l'UAT non destructive** depuis les vraies routes de staging à travers le proxy/CDN réel. Observer les statuts, quotas, IDs idempotents et journaux expurgés. Ne pas assimiler le benchmark CI loopback à cette recette.
6. **Répéter le rollback** sur staging et faire relire les preuves par un second opérateur.
7. **Décider GO/NO-GO** : seulement si tous les P0 sont PASS et l'approbation `prd` est disponible. En production, commencer par un canary/smoke test fonctionnel à faible débit, surveiller erreurs HMAC/429/413 et la file n8n; rollback si les critères convenus sont dépassés.

## Demande prête à transmettre à l'hébergeur (DP-4)

> Pourriez-vous confirmer, pour le domaine concerné et sans nous transmettre de donnée d'accès :
> 1. si la connexion vers l'origine est relayée par votre CDN/proxy et quelle adresse IP le processus PHP reçoit dans `REMOTE_ADDR`;
> 2. quels en-têtes d'adresse client sont supprimés, remplacés ou ajoutés par vos proxys, et quelles plages d'IP sont autorisées à les fournir;
> 3. si un client peut transmettre un `X-Forwarded-For`/`X-Real-IP` arbitraire jusqu'à l'origine sans réécriture;
> 4. quelles limites de requêtes par IP, burst et taille de corps sont disponibles/configurées pour les routes REST;
> 5. quelle méthode de test contrôlée recommandez-vous pour confirmer la valeur observée par PHP sans créer d'endpoint public de diagnostic.
>
> Nous ne demandons ni secret, ni contenu de base de données, ni données personnelles. Merci de répondre avec les paramètres et limites applicables à l'origine concernée, ou de préciser ce que le plan d'hébergement ne permet pas.

**État des preuves connu dans ce dépôt :** sur le SHA `a6808308a70d0c05a6698e9669535fb1565c0fcb`, CI `42429277059` et WordPress/MariaDB/n8n éphémère `42429284205` ont réussi; le dernier inclut le test HTTP 429/`Retry-After` et une charge confinée à `127.0.0.1` (300/300 à 9,99 RPS, p95 59,26 ms). Cela ne fait passer aucun contrôle d'hébergement/UAT ci-dessus à PASS. L'attestation de production et les références vers les preuves réelles restent à fournir et à relire par l'approbateur.

## Journal de décision — à renseigner par l'équipe

| Date UTC | SHA candidat | Décision | Responsable UAT | Approbateur production | Références des preuves expurgées | Incidents/conditions |
|---|---|---|---|---|---|---|
| À renseigner | À renseigner | **NO-GO — contrôles hébergés non exécutés** | À renseigner | À renseigner | Aucune à ce jour | Ne pas lancer le déploiement de production |
