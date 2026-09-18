<?php
/**
 * Adoptions de domaines et création de table du Migrator (extrait au micro-lot
 * pré-prod 2.10.8/6.20.7 — métrique CA-4 : Migrator dépassait 400 lignes après
 * l'ajout de l'étape 2.7.0 ; pattern maison du lot D, méthodes déplacées
 * VERBATIM). Le flux migrate(), le verrou, la version courante et le rapport
 * restent dans Migrator ; ce trait porte les sondes d'adoption B1→B6 et la
 * création de la table du micro-lot (E-4303), qui partagent la même
 * discipline : existence, comptage, empreinte de structure, audit.
 */

declare(strict_types=1);

namespace Partikulier\Core\Database;

trait MigrationsAdoptionsTrait
{
	/**
	 * Lot B1 — adoption des trois tables du domaine paiements/premium.
	 *
	 * Les tables portent les mêmes noms depuis leur création par le thème
	 * 6.17.x : aucune donnée n'est déplacée. L'adoption vérifie l'existence
	 * (dbDelta les a créées si besoin juste avant), journalise les comptages
	 * et l'empreinte de structure, puis consigne la reprise de propriété au
	 * registre d'audit. Rejouable : à état identique, elle ne fait que
	 * re-mesurer (utilisée par le test d'idempotence REG-6).
	 *
	 * @return array<string, array{exists: bool, rows: int, structure: string}>
	 */
	public function adoptPaymentPremiumTables(): array
	{
		global $wpdb;
		$adopted = [];
		foreach ( ['pk_payment_orders', 'pk_premium_subscriptions', 'pk_premium_history'] as $table ) {
			$name      = $wpdb->prefix . $table;
			$exists    = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name;
			$structure = '';
			$rows      = 0;
			if ( $exists ) {
				$rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$name}");
				// Empreinte de structure : SHOW CREATE TABLE est supporté par
				// MySQL (staging) ET par le traducteur SQLite (banc — DDL
				// canonique MySQL en retour) ; la requête directe sur
				// sqlite_master est REFUSÉE par le traducteur (sonde du 9 sept.
				// 2026, journal du lot B1).
				$create    = $wpdb->get_row("SHOW CREATE TABLE {$name}", ARRAY_N);
				$ddl       = is_array($create) && isset($create[1]) && is_string($create[1]) ? $create[1] : '';
				$structure = $ddl !== '' ? hash('sha256', preg_replace('/\s+/', ' ', $ddl)) : 'unavailable';
			}
			$adopted[ $table ] = ['exists' => $exists, 'rows' => $rows, 'structure' => $structure];
		}
		$this->audit()->record('domain_adopted', 'schema', null, [
			'lot'    => 'B1',
			'domain' => 'payments+premium',
			'tables' => array_keys($adopted),
			'counts' => array_map(static fn( array $t ): int => (int) $t['rows'], $adopted),
		]);
		return $adopted;
	}

	/**
	 * Lot B2 — adoption des huit tables du domaine leads/qualification/WhatsApp.
	 *
	 * Même discipline que le lot B1 : les tables portent les mêmes noms depuis
	 * leur création par le thème 6.17.x, aucune donnée n'est déplacée. Le T0 du
	 * banc portait 10 lignes réelles dans pk_buyer_leads — l'adoption les
	 * dénombre et en conserve l'empreinte, preuve que l'extraction n'a rien
	 * touché (contrôle croisé avec le test d'idempotence REG-6).
	 *
	 * @return array<string, array{exists: bool, rows: int, structure: string}>
	 */
	public function adoptLeadsTables(): array
	{
		global $wpdb;
		$adopted = [];
		foreach ( self::LEADS_TABLES as $table ) {
			$name      = $wpdb->prefix . $table;
			$exists    = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name;
			$structure = '';
			$rows      = 0;
			if ( $exists ) {
				$rows      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$name}");
				$create    = $wpdb->get_row("SHOW CREATE TABLE {$name}", ARRAY_N);
				$ddl       = is_array($create) && isset($create[1]) && is_string($create[1]) ? $create[1] : '';
				$structure = $ddl !== '' ? hash('sha256', preg_replace('/\s+/', ' ', $ddl)) : 'unavailable';
			}
			$adopted[ $table ] = ['exists' => $exists, 'rows' => $rows, 'structure' => $structure];
		}
		$this->audit()->record('domain_adopted', 'schema', null, [
			'lot'    => 'B2',
			'domain' => 'leads',
			'tables' => array_keys($adopted),
			'counts' => array_map(static fn( array $t ): int => (int) $t['rows'], $adopted),
		]);
		return $adopted;
	}

	/**
	 * Lot B3 — adoption des deux tables du domaine alertes.
	 *
	 * Même discipline que B1/B2 : les tables portent les mêmes noms depuis
	 * leur création par le thème 6.17.x, aucune donnée n'est déplacée. Le
	 * T0 du banc les portait vides (fonctionnalité dormante — aucun appelant
	 * runtime du thème, l'adaptateur Meta/n8n n'est pas encore ouvert).
	 *
	 * @return array<string, array{exists: bool, rows: int, structure: string}>
	 */
	public function adoptAlertsTables(): array
	{
		$adopted = [];
		foreach ( self::ALERTS_TABLES as $table ) {
			$adopted[ $table ] = $this->adoptTable($table);
		}
		$this->audit()->record('domain_adopted', 'schema', null, [
			'lot'    => 'B3',
			'domain' => 'alerts',
			'tables' => array_keys($adopted),
			'counts' => array_map(static fn( array $t ): int => (int) $t['rows'], $adopted),
		]);
		return $adopted;
	}

	/**
	 * Lot B4 : reprise de propriété des deux tables du domaine automatisation
	 * n8n — existence, comptage, empreinte de structure, consignation.
	 */
	public function adoptAutomationTables(): array
	{
		global $wpdb;
		$adopted = [];
		foreach ( self::AUTOMATION_TABLES as $table ) {
			$adopted[ $table ] = $this->adoptTable($table);
		}
		$this->audit()->record('domain_adopted', 'schema', null, [
			'lot'    => 'B4',
			'domain' => 'automation',
			'tables' => array_keys($adopted),
			'counts' => array_map(static fn( array $t ): int => (int) $t['rows'], $adopted),
		]);
		return $adopted;
	}

	/**
	 * Lot B5 : reprise de propriété de la table du domaine statistiques
	 * propriétaire — existence, comptage, empreinte de structure, consignation.
	 */
	public function adoptOwnerStatsTables(): array
	{
		$adopted = [];
		foreach ( self::OWNER_STATS_TABLES as $table ) {
			$adopted[ $table ] = $this->adoptTable($table);
		}
		$this->audit()->record('domain_adopted', 'schema', null, [
			'lot'    => 'B5',
			'domain' => 'owner_stats',
			'tables' => array_keys($adopted),
			'counts' => array_map(static fn( array $t ): int => (int) $t['rows'], $adopted),
		]);
		return $adopted;
	}

	/**
	 * Lot B6 : reprise de propriété de la table du domaine variantes de
	 * traduction — existence, comptage, empreinte de structure, consignation.
	 */
	public function adoptTranslationVariantsTables(): array
	{
		$adopted = [];
		foreach ( self::TRANSLATION_VARIANTS_TABLES as $table ) {
			$adopted[ $table ] = $this->adoptTable($table);
		}
		$this->audit()->record('domain_adopted', 'schema', null, [
			'lot'    => 'B6',
			'domain' => 'translation_variants',
			'tables' => array_keys($adopted),
			'counts' => array_map(static fn( array $t ): int => (int) $t['rows'], $adopted),
		]);
		return $adopted;
	}

	/**
	 * Micro-lot pré-prod 2.10.8/6.20.7 (E-4303) : la table pk_slug_redirects
	 * est NEUVE — elle n'a jamais vécu côté thème, aucune donnée n'est
	 * reprise ni déplacée. dbDelta vient de la créer si besoin ; la sonde
	 * constate l'existence, le comptage (0 attendu sur installation saine)
	 * et l'empreinte de structure, puis consigne la création au registre
	 * d'audit — même discipline de journalisation que les adoptions B1-B6,
	 * avec une action distincte car ce n'est pas une reprise de propriété.
	 *
	 * @return array<string, array{exists: bool, rows: int, structure: string}>
	 */
	public function createSlugRedirectsTable(): array
	{
		$probed = [];
		foreach ( self::SLUG_REDIRECTS_TABLES as $table ) {
			$probed[ $table ] = $this->adoptTable($table);
		}
		$this->audit()->record('slug_redirects_table_created', 'schema', null, [
			'lot'    => 'ML',
			'domain' => 'listings',
			'tables' => array_keys($probed),
			'counts' => array_map(static fn( array $t ): int => (int) $t['rows'], $probed),
		]);
		return $probed;
	}

	/**
	 * Sonde d'adoption d'une table : existence, comptage, empreinte de
	 * structure (SHOW CREATE TABLE — supporté par MySQL et par le
	 * traducteur SQLite du banc, cf. incident B1). Facteur commun des
	 * adoptions B3/B4 (et des suivantes) : la discipline est la même,
	 * seule la liste de tables change.
	 *
	 * @return array{exists: bool, rows: int, structure: string}
	 */
	private function adoptTable( string $table ): array
	{
		global $wpdb;
		$name      = $wpdb->prefix . $table;
		$exists    = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name;
		$structure = '';
		$rows      = 0;
		if ( $exists ) {
			$rows      = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$name}");
			$create    = $wpdb->get_row("SHOW CREATE TABLE {$name}", ARRAY_N);
			$ddl       = is_array($create) && isset($create[1]) && is_string($create[1]) ? $create[1] : '';
			$structure = $ddl !== '' ? hash('sha256', preg_replace('/\s+/', ' ', $ddl)) : 'unavailable';
		}
		return ['exists' => $exists, 'rows' => $rows, 'structure' => $structure];
	}
}
