<?php
/**
 * Schéma unifié versionné du plugin (lots A puis B1).
 *
 * Deux rôles :
 *  1. DDL des tables appartenant au plugin : listings, audit, idempotence
 *     (inchangés depuis 1.x — la table de projection pk_listings garde sa forme),
 *     puis, au lot B1, les trois tables du domaine paiements/premium reprises
 *     du thème — formulations CREATE TABLE reproduites à l'identique depuis
 *     class-payment-foundation.php et class-premium.php (REG-6 : le rejeu de
 *     dbDelta sur une table déjà en place ne doit rien modifier) ;
 *  2. manifeste des vingt tables pk_ du portail avec leur domaine, leur
 *     propriétaire actuel et le lot de la refonte qui les a prises en charge.
 *     Ce manifeste est LA référence du health check et du DomainRegistry ;
 *     les lots B2 à F déplacent les entrées « theme » vers « plugin » au fur et
 *     à mesure des extractions, avec VERSION incrémentée à chaque mouvement.
 *
 * Version 2.1.0 (lot B1) : les tables pk_payment_orders,
 * pk_premium_subscriptions et pk_premium_history passent de theme à plugin ;
 * DDL ajouté à l'identique.
 *
 * Version 2.2.0 (lot B2) : les huit tables du domaine leads/qualification/
 * WhatsApp passent de theme à plugin ; DDL ajouté à l'identique depuis
 * class-buyer-qualification.php (REG-6 : le rejeu de dbDelta sur une table
 * déjà en place ne doit rien modifier).
 *
 * Version 2.3.0 (lot B3) : les deux tables du domaine alertes
 * (pk_saved_alerts, pk_alert_deliveries) passent de theme à plugin ; DDL
 * ajouté à l'identique depuis class-saved-alerts.php (REG-6). Cinquième
 * domaine sur huit hébergé par le plugin.
 *
 * Version 2.4.0 (lot B4) : les deux tables du domaine automatisation n8n
 * (pk_automation_events, pk_n8n_hmac_audit) passent de theme à plugin ; DDL
 * ajouté à l'identique depuis class-automation-bridge.php et
 * class-n8n-security.php (REG-6 : le rejeu de dbDelta sur une table déjà en
 * place ne doit rien modifier — le banc porte 2 événements réels au T0).
 * Sixième domaine sur huit hébergé par le plugin.
 *
 * Version 2.5.0 (lot B5) : la table du domaine statistiques propriétaire
 * (pk_property_saves) passe de theme à plugin ; DDL ajouté à l'identique
 * depuis class-owner-insights.php (REG-6 : le rejeu de dbDelta sur une
 * table déjà en place ne doit rien modifier — le banc porte 4 favoris
 * réels au T0, pseudonymisés par HMAC). Septième domaine sur huit hébergé
 * par le plugin.
 *
 * Version 2.6.0 (lot B6) : la table du domaine variantes de traduction
 * (pk_property_variants) passe de theme à plugin ; DDL ajouté à l'identique
 * depuis class-localization.php (REG-6 : le rejeu de dbDelta sur une table
 * déjà en place ne doit rien modifier). Huitième et DERNIER domaine : à
 * l'issue de ce lot, les huit domaines métier sont hébergés par le plugin
 * (critère de sortie du lot B atteint intégralement — le lot C peut unifier
 * le mécanisme de traduction, pk_property_variants lui étant déjà transféré).
 */

declare(strict_types=1);

namespace Partikulier\Core\Database;

final class Schema
{
    public const VERSION = '2.6.0';

    /** @return array<string, string> */
    public static function statements(string $prefix): array
    {
        $prefix = preg_replace('/[^A-Za-z0-9_]/', '', $prefix) ?: 'wp_';
        return [
            'listings' => "CREATE TABLE {$prefix}pk_listings (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                owner_user_id bigint(20) unsigned NOT NULL,
                external_id varchar(64) NOT NULL,
                status varchar(20) NOT NULL DEFAULT 'draft',
                locale varchar(12) NOT NULL DEFAULT 'fr',
                title text NOT NULL,
                description longtext NOT NULL,
                price decimal(14,2) unsigned NOT NULL DEFAULT 0,
                area decimal(12,2) unsigned NOT NULL DEFAULT 0,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY external_id (external_id),
                KEY status_locale (status, locale),
                KEY status_locale_created (status, locale, created_at, id),
                KEY status_locale_price (status, locale, price, id),
                KEY status_locale_area (status, locale, area, id),
                KEY owner_status (owner_user_id, status),
                KEY search_order (status, price, area)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'audit' => "CREATE TABLE {$prefix}pk_audit_log (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                correlation_id char(36) NOT NULL,
                actor_user_id bigint(20) unsigned NULL,
                action varchar(80) NOT NULL,
                object_type varchar(80) NOT NULL,
                object_id bigint(20) unsigned NULL,
                metadata_json longtext NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY (id),
                KEY correlation (correlation_id),
                KEY object_lookup (object_type, object_id),
                KEY created_at (created_at)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'idempotency' => "CREATE TABLE {$prefix}pk_idempotency (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_id varchar(128) NOT NULL,
                event_hash char(64) NOT NULL,
                response_json longtext NOT NULL,
                created_at datetime NOT NULL,
                expires_at datetime NOT NULL,
                PRIMARY KEY (id),
                UNIQUE KEY event_id (event_id),
                KEY expires_at (expires_at)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",

            // Domaine paiements/premium (lot B1) — repris du thème 6.17.x,
            // formulations à l'identique (REG-6) : mêmes colonnes, mêmes clés,
            // mêmes noms d'index. dbDelta rejoué sur une table existante est
            // un no-op ; sur une installation neuve, il la crée.
            'payment_orders' => "CREATE TABLE {$prefix}pk_payment_orders (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                property_id bigint(20) unsigned NOT NULL,
                owner_id bigint(20) unsigned NOT NULL,
                provider varchar(64) NOT NULL DEFAULT 'unselected',
                provider_order_ref varchar(191) NULL,
                amount_minor bigint(20) unsigned NOT NULL DEFAULT 0,
                currency char(3) NOT NULL DEFAULT 'MAD',
                purpose varchar(64) NOT NULL DEFAULT 'premium_visibility',
                status varchar(16) NOT NULL DEFAULT 'disabled',
                metadata longtext NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY provider_reference (provider,provider_order_ref),
                KEY property_status (property_id,status),
                KEY owner_status (owner_id,status)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'premium_subscriptions' => "CREATE TABLE {$prefix}pk_premium_subscriptions (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                property_id bigint(20) unsigned NOT NULL,
                owner_id bigint(20) unsigned NOT NULL,
                payment_order_id bigint(20) unsigned NULL,
                plan_key varchar(64) NOT NULL DEFAULT 'premium_visibility',
                status varchar(16) NOT NULL DEFAULT 'disabled',
                starts_at datetime NULL,
                ends_at datetime NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                KEY property_status (property_id,status),
                KEY payment_order (payment_order_id)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'premium_history' => "CREATE TABLE {$prefix}pk_premium_history (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                property_id bigint(20) unsigned NOT NULL,
                owner_id bigint(20) unsigned NOT NULL,
                status varchar(16) NOT NULL,
                selection_reason text NOT NULL,
                granted_by bigint(20) unsigned NOT NULL,
                granted_at datetime NOT NULL,
                starts_at datetime NOT NULL,
                ends_at datetime NOT NULL,
                revoked_by bigint(20) unsigned NOT NULL DEFAULT 0,
                revoked_at datetime NULL,
                revocation_reason text NULL,
                PRIMARY KEY  (id),
                KEY property_status (property_id,status),
                KEY status_ends_at (status,ends_at),
                KEY owner_status (owner_id,status)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",

            // Domaine leads/qualification/WhatsApp (lot B2) — repris du thème
            // 6.17.x, formulations à l'identique (REG-6) : mêmes colonnes,
            // mêmes clés, mêmes noms d'index. Aucun numéro n'est stocké en
            // clair dans les clés de recherche : un HMAC sert à retrouver et
            // limiter un même demandeur (port fidèle du dispositif).
            'buyer_leads' => "CREATE TABLE {$prefix}pk_buyer_leads (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                phone_hash char(64) NOT NULL,
                phone_encrypted longtext NOT NULL,
                first_seen_at datetime NOT NULL,
                last_seen_at datetime NOT NULL,
                opt_out_at datetime NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY phone_hash (phone_hash)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'interest_events' => "CREATE TABLE {$prefix}pk_interest_events (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                lead_id bigint(20) unsigned NOT NULL,
                property_id bigint(20) unsigned NOT NULL,
                reference_code varchar(32) NOT NULL,
                property_snapshot longtext NOT NULL,
                provider_message_id varchar(191) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY provider_message_id (provider_message_id),
                KEY lead_property (lead_id,property_id)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'contact_limits' => "CREATE TABLE {$prefix}pk_contact_limits (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                lead_id bigint(20) unsigned NOT NULL,
                day_key date NOT NULL,
                contacts_count tinyint(3) unsigned NOT NULL DEFAULT 0,
                PRIMARY KEY  (id),
                UNIQUE KEY lead_day (lead_id,day_key)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'contact_disclosures' => "CREATE TABLE {$prefix}pk_contact_disclosures (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                lead_id bigint(20) unsigned NOT NULL,
                property_id bigint(20) unsigned NOT NULL,
                owner_id bigint(20) unsigned NOT NULL,
                day_key date NOT NULL,
                sent_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY lead_property (lead_id,property_id),
                KEY lead_day (lead_id,day_key),
                KEY lead_owner_day (lead_id,owner_id,day_key)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'buyer_preferences' => "CREATE TABLE {$prefix}pk_buyer_preferences (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                lead_id bigint(20) unsigned NOT NULL,
                budget_max bigint(20) unsigned NULL,
                areas longtext NOT NULL,
                layout_value varchar(64) NOT NULL,
                transaction_value varchar(64) NOT NULL,
                source varchar(64) NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY lead_id (lead_id)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'whatsapp_consents' => "CREATE TABLE {$prefix}pk_whatsapp_consents (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                lead_id bigint(20) unsigned NOT NULL,
                scope varchar(64) NOT NULL,
                granted_at datetime NULL,
                revoked_at datetime NULL,
                policy_version varchar(32) NOT NULL,
                proof_message_id varchar(191) NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY lead_scope (lead_id,scope)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'whatsapp_messages' => "CREATE TABLE {$prefix}pk_whatsapp_messages (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                provider_message_id varchar(191) NOT NULL,
                lead_id bigint(20) unsigned NOT NULL,
                direction varchar(16) NOT NULL,
                message_type varchar(64) NOT NULL,
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY provider_message_id (provider_message_id)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'lead_followups' => "CREATE TABLE {$prefix}pk_lead_followups (
                lead_id bigint(20) unsigned NOT NULL,
                status varchar(32) NOT NULL DEFAULT 'new',
                note text NULL,
                updated_by bigint(20) unsigned NOT NULL DEFAULT 0,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (lead_id),
                KEY status_updated (status,updated_at)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",

            // Domaine alertes (lot B3) — repris du thème 6.17.x,
            // formulations à l'identique (REG-6) : mêmes colonnes, mêmes clés,
            // mêmes noms d'index. Les alertes ne sont créées qu'après
            // consentement WhatsApp explicite (similar_listings, lu via le
            // LeadService du lot B2 — lecture croisée inter-domaines).
            'saved_alerts' => "CREATE TABLE {$prefix}pk_saved_alerts (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                lead_id bigint(20) unsigned NOT NULL,
                criteria longtext NOT NULL,
                criteria_signature char(64) NOT NULL,
                locale varchar(8) NOT NULL DEFAULT 'fr',
                frequency varchar(16) NOT NULL DEFAULT 'daily',
                status varchar(16) NOT NULL DEFAULT 'active',
                consent_message_id varchar(191) NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY lead_signature (lead_id,criteria_signature),
                KEY status_updated (status,updated_at)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'alert_deliveries' => "CREATE TABLE {$prefix}pk_alert_deliveries (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                alert_id bigint(20) unsigned NOT NULL,
                property_id bigint(20) unsigned NOT NULL,
                status varchar(16) NOT NULL DEFAULT 'candidate',
                created_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY alert_property (alert_id,property_id),
                KEY status_created (status,created_at)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",

            // Domaine automatisation n8n (lot B4) — repris du thème 6.17.x,
            // formulations à l'identique (REG-6) : mêmes colonnes, mêmes clés,
            // mêmes noms d'index. Le pont ne fait que journaliser des accusés
            // d'événements (payload haché, jamais persisté) ; l'audit HMAC
            // regroupe les échecs de signature par clé et par heure.
            'automation_events' => "CREATE TABLE {$prefix}pk_automation_events (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                event_id varchar(191) NOT NULL,
                event_type varchar(64) NOT NULL,
                source varchar(32) NOT NULL,
                payload_hash char(64) NOT NULL,
                status varchar(16) NOT NULL DEFAULT 'received',
                received_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY event_id (event_id),
                KEY type_received (event_type,received_at)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
            'n8n_hmac_audit' => "CREATE TABLE {$prefix}pk_n8n_hmac_audit (id bigint(20) unsigned NOT NULL AUTO_INCREMENT,key_id varchar(64) NOT NULL,hour_key datetime NOT NULL,failure_count int unsigned NOT NULL DEFAULT 0,last_reason varchar(64) NOT NULL,PRIMARY KEY(id),UNIQUE KEY key_hour (key_id,hour_key)) {$GLOBALS['wpdb']->get_charset_collate()};",

            // Domaine statistiques propriétaire (lot B5) — repris du thème
            // 6.17.x, formulation à l'identique (REG-6) : mêmes colonnes,
            // mêmes clés, mêmes noms d'index (y compris le double espace
            // historique après PRIMARY KEY). Une ligne active par annonce et
            // navigateur pseudonymisé (HMAC non réversible) — les favoris
            // visiteurs restent locaux, seuls des agrégats anonymes sont
            // exposés au propriétaire.
            'property_saves' => "CREATE TABLE {$prefix}pk_property_saves (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                property_id bigint(20) unsigned NOT NULL,
                visitor_hash char(64) NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY property_visitor (property_id, visitor_hash),
                KEY property_id (property_id),
                KEY updated_at (updated_at)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",

            // Domaine variantes de traduction (lot B6) — repris du thème
            // 6.17.x, formulation à l'identique (REG-6) : mêmes colonnes,
            // mêmes clés, mêmes noms d'index (y compris le double espace
            // historique après PRIMARY KEY). Le registre ne stocke AUCUN
            // contenu : une ligne par emplacement de variante (annonce source
            // × locale) — prepare_variant crée l'emplacement, link_variant
            // attache l'annonce traduite, jamais de duplication de contenu.
            'property_variants' => "CREATE TABLE {$prefix}pk_property_variants (
                id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
                source_property_id bigint(20) unsigned NOT NULL,
                locale varchar(8) NOT NULL,
                variant_property_id bigint(20) unsigned NOT NULL DEFAULT 0,
                original_free_text_locale varchar(8) NOT NULL,
                status varchar(16) NOT NULL,
                created_at datetime NOT NULL,
                updated_at datetime NOT NULL,
                PRIMARY KEY  (id),
                UNIQUE KEY source_locale (source_property_id,locale),
                KEY variant_property (variant_property_id),
                KEY status_locale (status,locale)
            ) {$GLOBALS['wpdb']->get_charset_collate()};",
        ];
    }

    /**
     * Manifeste des vingt tables pk_ : table (sans préfixe) => descripteur.
     * domain : clé du domaine ; owner : plugin|theme (propriétaire au jour de
     * la version) ; lot : lot de la refonte qui migre la table vers le plugin.
     *
     * @return array<string, array{domain: string, label: string, owner: string, lot: string}>
     */
    public static function domainTables(): array
    {
        return [
            // Domaine annonces — propriété du plugin depuis l'origine (lot A : projection temps réel).
            'pk_listings' => ['domain' => 'listings', 'label' => 'Annonces', 'owner' => 'plugin', 'lot' => 'A'],
            'pk_audit_log' => ['domain' => 'listings', 'label' => 'Annonces', 'owner' => 'plugin', 'lot' => 'A'],
            'pk_idempotency' => ['domain' => 'listings', 'label' => 'Annonces', 'owner' => 'plugin', 'lot' => 'A'],

            // Domaine paiements — propriété du plugin depuis le lot B1.
            'pk_payment_orders' => ['domain' => 'payments', 'label' => 'Paiements', 'owner' => 'plugin', 'lot' => 'B1'],
            'pk_premium_subscriptions' => ['domain' => 'payments', 'label' => 'Paiements', 'owner' => 'plugin', 'lot' => 'B1'],
            'pk_premium_history' => ['domain' => 'premium', 'label' => 'Premium', 'owner' => 'plugin', 'lot' => 'B1'],

            // Domaine leads/qualification/WhatsApp — propriété du plugin depuis le lot B2.
            'pk_buyer_leads' => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
            'pk_interest_events' => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
            'pk_contact_limits' => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
            'pk_contact_disclosures' => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
            'pk_whatsapp_consents' => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
            'pk_whatsapp_messages' => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
            'pk_buyer_preferences' => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
            'pk_lead_followups' => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
            // Domaine alertes — propriété du plugin depuis le lot B3.
            'pk_saved_alerts' => ['domain' => 'alerts', 'label' => 'Alertes enregistrées', 'owner' => 'plugin', 'lot' => 'B3'],
            'pk_alert_deliveries' => ['domain' => 'alerts', 'label' => 'Alertes enregistrées', 'owner' => 'plugin', 'lot' => 'B3'],

            // Domaine automatisation — propriété du plugin depuis le lot B4.
            'pk_automation_events' => ['domain' => 'automation', 'label' => 'Automatisation n8n', 'owner' => 'plugin', 'lot' => 'B4'],
            'pk_n8n_hmac_audit' => ['domain' => 'automation', 'label' => 'Automatisation n8n', 'owner' => 'plugin', 'lot' => 'B4'],
            // Domaine statistiques propriétaire — propriété du plugin depuis le lot B5.
            'pk_property_saves' => ['domain' => 'owner_stats', 'label' => 'Statistiques propriétaire', 'owner' => 'plugin', 'lot' => 'B5'],
            // Domaine variantes de traduction — propriété du plugin depuis le lot
            // B6 (dernier des huit domaines ; conditionne le lot C).
            'pk_property_variants' => ['domain' => 'translation_variants', 'label' => 'Variantes de traduction', 'owner' => 'plugin', 'lot' => 'B6'],
        ];
    }
}
