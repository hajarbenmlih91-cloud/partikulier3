# Journal des Modifications (Changelog) — Plugin Partikulier Core

Toutes les modifications notables apportées à l'extension Partikulier Core sont consignées dans ce document.
Le format est basé sur [Keep a Changelog](https://keepachangelog.com/fr/1.0.0/),
et ce projet adhère au [Semantic Versioning](https://semver.org/lang/fr/).

---

## [2.10.10] - 2026-10-04

### Corrigé
- Intégration du lot livré `f502d43` sans écraser l'infrastructure Docker, CI/CD et les guides du dépôt.
- Contacts acheteurs : gestion des erreurs de taxonomie et reprise bornée des transactions en cas de deadlock.
- Santé : réponse HTTP 503 en état dégradé ou critique, sans cache ; diagnostics détaillés réservés aux administrateurs.
- Regroupement des identifiants numériques dans les clés du limiteur REST.
- Webhooks sortants : conservation du contrat d'authentification partagé et ajout de l'identifiant d'algorithme.

### Technique
- Paire plugin 2.10.10 / thème 6.20.9 ; artefacts et SBOM reconstruits depuis la source fusionnée.

## [2.10.9] - 2026-10-03

### Modifié
- Alignement de version avec le thème 6.20.8 (release outillage CI/CD et documentation, aucune évolution fonctionnelle).
- Contrats de recette alignés sur la paire 2.10.9 / 6.20.8.

## [2.10.8-FINAL] - 2026-09-27 (Homologation Complète 18 Scénarios de Recette)

### Ajouté
- **Domaine Alertes Immobilières (Lot B3)** : Persistance des alertes dans `wp_pk_saved_alerts`, assainissement des critères par tri normalisé `ksort`, signature canonique SHA-256 (`criteria_signature`), consentement obligatoire `similar_listings`, cycle de vie (`active` $\rightarrow$ `paused` $\rightarrow$ `stopped`) et scellement du transport de livraisons (`wp_pk_alert_deliveries` vide).
- **Domaine Automatisation & Webhook n8n (Lot B4 / SE-026)** : Réception d'événements dans `wp_pk_automation_events`, validation cryptographique par signature HMAC SHA-256 sur `X-Partikulier-Automation`, rotation des clés à chaud avec expiration, fenêtre anti-rejeu stricte de 300 s, journalisation d'audit dans `wp_pk_n8n_hmac_audit` et support des 3 modes HMAC (`off`, `log`, `enforce`).
- **Sécurité d'Effacement RGPD & Loi 09-08 CNDP (Lot B2 / SE-016)** : Route `POST /partikulier/v1/erase-lead` avec authentification par secret dédié `lead_erase_api_secret`, purge transactionnelle atomique en cascade sur l'intégralité des 9 tables portant `lead_id` + purge liée des alertes livrées, et limiteur anti-forçage (HTTP 429 après 10 échecs consécutifs).
- **Idempotence du Dépôt Public (SE-034)** : Détection des doubles-clics et rejeux de requêtes sur le formulaire de publication, réutilisation sécurisée des réponses sans doublon d'annonce et rejet HTTP 400 en cas de charge altérée sur une même clé.
- **Rate Limiting Couplé IP + Session (SE-008)** : Algorithme Token Bucket gérant le trafic partagé des adresses IP en CGNAT 4G au Maroc (IAM, Inwi, Orange) sans pénaliser les utilisateurs légitimes.
- **Sécurité d'Exécution Système AVIF (Lot E)** : Passerelle unique `class-exec-whitelist.php` (399 lignes), vérification des empreintes SHA-256 de binaires, confinement au dossier `uploads`, neutralisation de 21 vecteurs d'injection de commandes et repli multi-éditeurs.
- **Traçabilité CI WebKit** : Ajout de la directive `npx playwright install --with-deps webkit` dans la documentation et les instructions CI.

### Corrigé
- **Circuit de Modération des Annonces (`CORE-AUTH-002`)** : Les dépôts via l'API REST authentifiée sont désormais créés sous le statut WordPress `pending` et draft dans `pk_listings`, garantissant le passage obligé par la modération avant publication.
- **Rattachement Obligatoire des Leads (`CORE-LEAD-001`)** : Rejet HTTP 422 de tout lead non associé à une annonce valide existante.
- **Intégrité BDD & Orphelins** : Clôture des fuites de données résiduelles et déduplication des enregistrements d'audit par cycle de requête REST (`RequestCycle`).

---

## [2.10.8-r6] - 2026-09-27
- Intégration des contrats de fermeture d'annonces SE-054 et toponymie bilingue SE-036.
- Durcissement XML-RPC et sanitisation des filtres scalaires.

## [2.10.8-r5] - 2026-09-27
- Support des annonces similaires actives pour les biens clôturés et disponibles.
- Raccordement du service des variantes Polylang.

## [2.10.8-r4] - 2026-09-27
- Module de Statut Premium public à 0 MAD (`pk_premium_public_enabled`).
- Purge sélective du cache LiteSpeed lors de l'expiration du statut Premium.

## [2.10.8-r3] - 2026-09-26
- Intégration du pont de vérification WhatsApp et assainissement des numéros marocains (+212).

## [2.10.8-r2] - 2026-09-26
- Alignement des signatures de méthodes avec le thème 6.20.7 pour éliminer toute régression sur les transitions d'annonces.

## [2.10.6] - 2026-09-24
- Idempotence par cycle de requête des gardes REST (`RequestCycle`).
- Élimination des comptages en double lors de l'envoi des en-têtes Allow.

## [2.0.0] - 2026-08-20
- Version initiale du socle contractuel Partikulier Core : registre unique, synchroniseur d'annonces, pont des leads et santé REST.
