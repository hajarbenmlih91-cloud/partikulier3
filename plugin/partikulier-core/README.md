# Partikulier Core — Cœur Métier Contractuel

Plugin WordPress central assurant l'intégrité métier, le stockage relationnel durci, l'API REST haute performance et la conformité légale de **Partikulier.ma**.
Le thème [partikulier](../themes/partikulier) lui délègue l'espace de noms d'API REST `/partikulier/v1`, le dispositif de leads, la modération des dépôts, les alertes et les webhooks.

**Version : 2.10.10** · Licence GPL v3 ou ultérieure · Requiert WordPress 6.2+ et PHP 8.1 ou supérieur.

---

## 1. Architecture des Domaines Métier & Données

Le plugin encapsule l'intégralité de la logique transactionnelle dans des services strictement découplés :

1. **Domaine Alertes Immobilières (Lot B3 / `AlertService`)** :
   - Tables relationnelles : `wp_pk_saved_alerts` et `wp_pk_alert_deliveries`.
   - Signature canonique des critères par hachage SHA-256 (`criteria_signature`) avec normalisation par tri des clés (`ksort`).
   - Consentement préalable explicite requis via l'API des leads (`similar_listings`, Loi 09-08 CNDP & RGPD).
   - Cycle de vie : `active` $\rightarrow$ `paused` $\rightarrow$ `stopped`.
   - Transport d'envoi scellé (aucune notification fantôme sans connecteur homologué).
2. **Domaine Automatisation & Webhooks n8n (Lot B4 / `AutomationService`)** :
   - Tables relationnelles : `wp_pk_automation_events` et `wp_pk_n8n_hmac_audit`.
   - Validation cryptographique par signature HMAC SHA-256 sur l'en-tête `X-Partikulier-Automation`.
   - Fenêtre anti-rejeu stricte de 300 secondes sur l'horodatage.
   - Rotation fluide des clés (`active_key_id`, `previous_key_id`) avec date d'expiration.
   - Modes de conformité configurables : `off`, `log`, `enforce` (SE-026).
3. **Domaine Leads & Droit à l'Oubli RGPD / CNDP (Lot B2 / `LeadService`)** :
   - Pipeline relationnel unifié à 9 tables portant `lead_id` (aucun stockage en commentaire).
   - Chiffrement applicatif AES-256-GCM et hachage HMAC des numéros acheteurs.
   - Route sécurisée `POST /partikulier/v1/erase-lead` exécutant une purge transactionnelle atomique en cascade sur les 9 tables + purge des livraisons d'alertes associées.
   - Protection anti-brute force bloquant toute tentative de sondage après 10 échecs consécutifs (HTTP 429).
4. **Idempotence du Dépôt Public (SE-034 / `RestController`)** :
   - Clé d'idempotence unique par soumission sur `/partikulier/v1/listings`.
   - Détection des doubles-clics mobiles et rejeux de requêtes (renvoie HTTP 200 `{replayed: true}`).
   - Rejet immédiat (HTTP 400) en cas d'altération de charge sur une clé existante.
5. **Rate Limiting Hybride CGNAT 4G Maroc (`RateLimiter`)** :
   - Algorithme de Token Bucket / Sliding Window couplant l'adresse IP à un fingerprint/identifiant de session.
   - Évite les blocages intempestifs sur les adresses IP partagées en CGNAT mobile (IAM, Inwi, Orange).
6. **Passerelle d'Exécution Système Sécurisée AVIF (Lot E / `class-exec-whitelist.php`)** :
   - Cloisonnement absolu : aucun appel `exec()`, `shell_exec()`, `system()` direct dans le projet.
   - Liste blanche des binaires système autorisés (`avifenc`, `vips`, `ps`) validés par chemins absolus et empreintes SHA-256.
   - Immunité absolue contre 21 vecteurs d'injection shell et repli multi-éditeurs en cascade.

---

## 2. Exécution des Contrats de Recette (CI & Banc WordPress Réel)

Pour exécuter les suites de tests officielles du plugin :

```bash
# Variables d'environnement imposées par le harnais :
export PK_WP_DIR="/chemin/vers/wordpress"
export PK_BASE="http://127.0.0.1:8080"
export PK_COMMIT="0a7d91bd707a891300c404f3ae79f34c7d75d46c"
export PK_VERSION="2.10.10"

# Exécution des contrats fondamentaux :
php partikulier-core/tests/core-contract.php
php partikulier-core/tests/alerts-domain-contract.php
php partikulier-core/tests/automation-domain-contract.php
php partikulier-core/tests/lead-erase-security-contract.php
php partikulier-core/tests/leads-contract.php
php partikulier-core/tests/se026-hmac-mode-contract.php
php partikulier-core/tests/se034-form-idempotency-contract.php
php partikulier-core/tests/avif-security-contract.php
```

> **Traçabilité CI WebKit** : Le workflow CI multi-navigateurs Playwright requiert l'installation explicite des dépendances Safari / WebKit :  
> `npx playwright install --with-deps webkit`

---

## 3. Installation

1. Téléverser l'archive `B-INSTALLER-PLUGIN-partikulier-core-2.10.10.zip` via **Extensions $\rightarrow$ Ajouter $\rightarrow$ Téléverser**.
2. Activer l'extension. Les tables de schéma et adoptions sont créées automatiquement à l'activation.
3. Vérifier le point de santé : `GET /wp-json/partikulier/v1/health` $\rightarrow$ `"status": "ok"`, 0 collision de routes.
