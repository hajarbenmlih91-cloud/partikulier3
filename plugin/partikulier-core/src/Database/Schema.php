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
 *
 * Version 2.7.0 (micro-lot pré-prod 2.10.8/6.20.7, E-4303) : création de la
 * table pk_slug_redirects — redirections d'anciens slugs d'annonces vers
 * leur ID. Table NEUVE (elle n'a jamais vécu côté thème : aucune reprise
 * REG-6, aucune migration de données — vide au départ). Rattachée au
 * domaine listings (slugs d'annonces) dans le manifeste, qui passe à 21
 * tables. Prérequis du lot SE-043 côté thème (resolve_geo_request : 301
 * vers le permalink courant, 410 pour une annonce corbeillée).
 */

declare(strict_types=1);

namespace Partikulier\Core\Database;

final class Schema
{
	public const VERSION = '2.7.0';

	use SchemaStatementsTrait;

	/**
	 * Manifeste des vingt et une tables pk_ : table (sans préfixe) => descripteur.
	 * domain : clé du domaine ; owner : plugin|theme (propriétaire au jour de
	 * la version) ; lot : lot de la refonte qui migre la table vers le plugin.
	 *
	 * @return array<string, array{domain: string, label: string, owner: string, lot: string}>
	 */
	public static function domainTables(): array
	{
		return [
			// Domaine annonces — propriété du plugin depuis l'origine (lot A : projection temps réel).
			'pk_listings'              => ['domain' => 'listings', 'label' => 'Annonces', 'owner' => 'plugin', 'lot' => 'A'],
			'pk_audit_log'             => ['domain' => 'listings', 'label' => 'Annonces', 'owner' => 'plugin', 'lot' => 'A'],
			'pk_idempotency'           => ['domain' => 'listings', 'label' => 'Annonces', 'owner' => 'plugin', 'lot' => 'A'],

			// Domaine paiements — propriété du plugin depuis le lot B1.
			'pk_payment_orders'        => ['domain' => 'payments', 'label' => 'Paiements', 'owner' => 'plugin', 'lot' => 'B1'],
			'pk_premium_subscriptions' => ['domain' => 'payments', 'label' => 'Paiements', 'owner' => 'plugin', 'lot' => 'B1'],
			'pk_premium_history'       => ['domain' => 'premium', 'label' => 'Premium', 'owner' => 'plugin', 'lot' => 'B1'],

			// Domaine leads/qualification/WhatsApp — propriété du plugin depuis le lot B2.
			'pk_buyer_leads'           => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
			'pk_interest_events'       => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
			'pk_contact_limits'        => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
			'pk_contact_disclosures'   => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
			'pk_whatsapp_consents'     => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
			'pk_whatsapp_messages'     => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
			'pk_buyer_preferences'     => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
			'pk_lead_followups'        => ['domain' => 'leads', 'label' => 'Leads et qualification', 'owner' => 'plugin', 'lot' => 'B2'],
			// Domaine alertes — propriété du plugin depuis le lot B3.
			'pk_saved_alerts'          => ['domain' => 'alerts', 'label' => 'Alertes enregistrées', 'owner' => 'plugin', 'lot' => 'B3'],
			'pk_alert_deliveries'      => ['domain' => 'alerts', 'label' => 'Alertes enregistrées', 'owner' => 'plugin', 'lot' => 'B3'],

			// Domaine automatisation — propriété du plugin depuis le lot B4.
			'pk_automation_events'     => ['domain' => 'automation', 'label' => 'Automatisation n8n', 'owner' => 'plugin', 'lot' => 'B4'],
			'pk_n8n_hmac_audit'        => ['domain' => 'automation', 'label' => 'Automatisation n8n', 'owner' => 'plugin', 'lot' => 'B4'],
			// Domaine statistiques propriétaire — propriété du plugin depuis le lot B5.
			'pk_property_saves'        => ['domain' => 'owner_stats', 'label' => 'Statistiques propriétaire', 'owner' => 'plugin', 'lot' => 'B5'],
			// Domaine variantes de traduction — propriété du plugin depuis le lot
			// B6 (dernier des huit domaines ; conditionne le lot C).
			'pk_property_variants'     => ['domain' => 'translation_variants', 'label' => 'Variantes de traduction', 'owner' => 'plugin', 'lot' => 'B6'],

			// Redirections d'anciens slugs d'annonces — table NEUVE du micro-lot
			// pré-prod 2.10.8/6.20.7 (E-4303), rattachée au domaine listings
			// (slugs d'annonces) : elle stocke slug → ID d'annonce pour le lot
			// SE-043 du thème. Le manifeste passe à 21 tables ; le décompte
			// des domaines reste 8/8 (aucun nouveau domaine).
			'pk_slug_redirects'        => ['domain' => 'listings', 'label' => 'Annonces', 'owner' => 'plugin', 'lot' => 'ML'],
		];
	}
}
