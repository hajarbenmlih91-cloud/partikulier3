<?php
/**
 * Migrator (lots A puis B1).
 *
 * Le schéma est idempotent (dbDelta rejouable) ; chaque montée de version
 * ajoute des étapes de données journalisées, exécutées une seule fois et
 * bornées par la version d'origine (les étapes 2.0.0 ne rejouent pas lors du
 * passage 2.0.0 → 2.1.0) :
 *  - 0.x → 2.0.0 : reconstruction de la projection pk_listings (INTEG-1),
 *    extinction du stockage de leads par commentaires (INTEG-2), retrait du
 *    cron quotidien hérité ;
 *  - 2.0.x → 2.1.0 (lot B1) : adoption des trois tables du domaine
 *    paiements/premium — reprise de propriété journalisée (existence,
 *    comptages, empreinte de structure), aucune donnée déplacée (mêmes
 *    tables, mêmes noms, mêmes index) ;
 *  - 2.1.x → 2.2.0 (lot B2) : adoption des huit tables du domaine
 *    leads/qualification/WhatsApp — même discipline : vérification
 *    d'existence (dbDelta les a créées si besoin), comptages, empreinte
 *    de structure, consignation au registre d'audit. Les 10 leads réels
 *    du banc restent en place, byte pour byte (REG-4 : aucune donnée
 *    déplacée, aucun stockage parallèle créé) ;
 *  - 2.2.x → 2.3.0 (lot B3) : adoption des deux tables du domaine
 *    alertes (pk_saved_alerts, pk_alert_deliveries) — même discipline.
 *    Les deux tables sont vides au T0 du banc (fonctionnalité dormante :
 *    « structures posées, fonctionnalités pas encore ouvertes ») —
 *    P-INT-0 mesurera le volume réel du staging ;
 *  - 2.3.x → 2.4.0 (lot B4) : adoption des deux tables du domaine
 *    automatisation n8n (pk_automation_events, pk_n8n_hmac_audit) — même
 *    discipline. Le T0 du banc porte 2 événements réels (reçus) : ils
 *    doivent rester en place, byte pour byte (REG-4) ;
 *  - 2.4.x → 2.5.0 (lot B5) : adoption de la table du domaine statistiques
 *    propriétaire (pk_property_saves) — même discipline. Le T0 du banc
 *    porte 4 favoris réels (pseudonymes HMAC de visiteurs) : ils doivent
 *    rester en place, byte pour byte (REG-4 — deuxième adoption B avec de
 *    la donnée réelle côté lignes de visite) ;
 *  - 2.5.x → 2.6.0 (lot B6) : adoption de la table du domaine variantes de
 *    traduction (pk_property_variants) — même discipline. Le T0 du banc la
 *    porte vide (fonctionnalité dormante : prepare_variant/link_variant
 *    n'ont aucun appelant runtime — une future passerelle de traduction du
 *    lot C les branchera) ; P-INT-0 mesurera le volume réel du staging.
 *
 * Journal : chaque exécution écrit un rapport horodaté dans l'option
 * partikulier_core_migration_report et une entrée au registre d'audit.
 */

declare(strict_types=1);

namespace Partikulier\Core\Database;

use Partikulier\Core\AuditLogger;
use Partikulier\Core\Integration\LeadBridge;
use Partikulier\Core\Integration\ListingSynchronizer;

final class Migrator
{
    private const OPTION = 'partikulier_core_schema_version';
    private const OPTION_REPORT = 'partikulier_core_migration_report';
    private const OPTION_LOCK = 'partikulier_core_migration_lock';

    /** Les huit tables du domaine leads, adoptées au lot B2. */
    public const LEADS_TABLES = [
        'pk_buyer_leads',
        'pk_interest_events',
        'pk_contact_limits',
        'pk_contact_disclosures',
        'pk_whatsapp_consents',
        'pk_whatsapp_messages',
        'pk_buyer_preferences',
        'pk_lead_followups',
    ];

    /** Les deux tables du domaine alertes, adoptées au lot B3. */
    public const ALERTS_TABLES = [
        'pk_saved_alerts',
        'pk_alert_deliveries',
    ];

    /** Les deux tables du domaine automatisation n8n, adoptées au lot B4. */
    public const AUTOMATION_TABLES = [
        'pk_automation_events',
        'pk_n8n_hmac_audit',
    ];

    /** La table du domaine statistiques propriétaire, adoptée au lot B5. */
    public const OWNER_STATS_TABLES = [
        'pk_property_saves',
    ];

    /** La table du domaine variantes de traduction, adoptée au lot B6. */
    public const TRANSLATION_VARIANTS_TABLES = [
        'pk_property_variants',
    ];

    /** Le journal de migration doit s'écrire sur TOUTE requête (page publique comprise). */
    private function audit(): AuditLogger
    {
        require_once __DIR__ . '/../AuditLogger.php';
        return new AuditLogger();
    }

    /** @return array{schema: string, steps: array<int, array<string, mixed>>, at: string} */
    public function migrate(): array
    {
        global $wpdb;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        foreach (Schema::statements($wpdb->prefix) as $statement) {
            dbDelta($statement);
        }

        $report = [
            'schema' => Schema::VERSION,
            'steps' => [],
            'at' => gmdate('c'),
        ];
        $from = $this->currentVersion();

        if ($from !== Schema::VERSION) {
            // Verrou d'exécution unique : deux requêtes simultanées (web + cron)
            // ne doivent jamais reconstruire la projection en parallèle.
            if (get_option(self::OPTION_LOCK)) {
                $report['steps'][] = ['step' => 'lock', 'result' => 'already_running'];
                return $report;
            }
            update_option(self::OPTION_LOCK, gmdate('c'), false);

            try {
                if (version_compare($from, '2.0.0', '<')) {
                    $synchronizer = new ListingSynchronizer();
                    $report['steps'][] = ['step' => 'rebuild_projection', 'result' => $synchronizer->rebuild()];

                    $legacyCron = ListingSynchronizer::unscheduleLegacyCron();
                    if ($legacyCron > 0) {
                        $report['steps'][] = ['step' => 'unschedule_legacy_cron', 'removed' => $legacyCron];
                    }

                    if (class_exists(LeadBridge::class)) {
                        $report['steps'][] = ['step' => 'extinguish_comment_leads', 'result' => (new LeadBridge())->migrateLegacyComments()];
                    }
                }

                if (version_compare($from, '2.1.0', '<')) {
                    // Lot B1 : reprise de propriété des tables paiements/premium.
                    $report['steps'][] = ['step' => 'adopt_payment_premium_tables', 'result' => $this->adoptPaymentPremiumTables()];
                }

                if (version_compare($from, '2.2.0', '<')) {
                    // Lot B2 : reprise de propriété des huit tables du domaine leads.
                    $report['steps'][] = ['step' => 'adopt_leads_tables', 'result' => $this->adoptLeadsTables()];
                }

                if (version_compare($from, '2.3.0', '<')) {
                    // Lot B3 : reprise de propriété des deux tables du domaine alertes.
                    $report['steps'][] = ['step' => 'adopt_alerts_tables', 'result' => $this->adoptAlertsTables()];
                }

                if (version_compare($from, '2.4.0', '<')) {
                    // Lot B4 : reprise de propriété des deux tables du domaine
                    // automatisation n8n (pont entrant + audit HMAC).
                    $report['steps'][] = ['step' => 'adopt_automation_tables', 'result' => $this->adoptAutomationTables()];
                }

                if (version_compare($from, '2.5.0', '<')) {
                    // Lot B5 : reprise de propriété de la table du domaine
                    // statistiques propriétaire (favoris visiteurs agrégés).
                    $report['steps'][] = ['step' => 'adopt_owner_stats_tables', 'result' => $this->adoptOwnerStatsTables()];
                }

                if (version_compare($from, '2.6.0', '<')) {
                    // Lot B6 : reprise de propriété de la table du domaine
                    // variantes de traduction (registre d'emplacements,
                    // huitième et dernier domaine du lot B).
                    $report['steps'][] = ['step' => 'adopt_translation_variants_tables', 'result' => $this->adoptTranslationVariantsTables()];
                }

                update_option(self::OPTION, Schema::VERSION, false);
                $this->audit()->record('schema_migrated', 'schema', null, ['from' => $from, 'to' => Schema::VERSION]);
            } finally {
                delete_option(self::OPTION_LOCK);
            }
        }

        update_option(self::OPTION_REPORT, $report, false);
        return $report;
    }

    public function currentVersion(): string
    {
        return (string) get_option(self::OPTION, '0.0.0');
    }

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
        foreach (['pk_payment_orders', 'pk_premium_subscriptions', 'pk_premium_history'] as $table) {
            $name = $wpdb->prefix . $table;
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name;
            $structure = '';
            $rows = 0;
            if ($exists) {
                $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$name}");
                // Empreinte de structure : SHOW CREATE TABLE est supporté par
                // MySQL (staging) ET par le traducteur SQLite (banc — DDL
                // canonique MySQL en retour) ; la requête directe sur
                // sqlite_master est REFUSÉE par le traducteur (sonde du 9 sept.
                // 2026, journal du lot B1).
                $create = $wpdb->get_row("SHOW CREATE TABLE {$name}", ARRAY_N);
                $ddl = is_array($create) && isset($create[1]) && is_string($create[1]) ? $create[1] : '';
                $structure = $ddl !== '' ? hash('sha256', preg_replace('/\s+/', ' ', $ddl)) : 'unavailable';
            }
            $adopted[$table] = ['exists' => $exists, 'rows' => $rows, 'structure' => $structure];
        }
        $this->audit()->record('domain_adopted', 'schema', null, [
            'lot' => 'B1',
            'domain' => 'payments+premium',
            'tables' => array_keys($adopted),
            'counts' => array_map(static fn(array $t): int => (int) $t['rows'], $adopted),
        ]);
        return $adopted;
    }

    /** @return array<string, mixed> */
    public function lastReport(): array
    {
        $report = get_option(self::OPTION_REPORT, []);
        return is_array($report) ? $report : [];
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
        foreach (self::LEADS_TABLES as $table) {
            $name = $wpdb->prefix . $table;
            $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name;
            $structure = '';
            $rows = 0;
            if ($exists) {
                $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$name}");
                $create = $wpdb->get_row("SHOW CREATE TABLE {$name}", ARRAY_N);
                $ddl = is_array($create) && isset($create[1]) && is_string($create[1]) ? $create[1] : '';
                $structure = $ddl !== '' ? hash('sha256', preg_replace('/\s+/', ' ', $ddl)) : 'unavailable';
            }
            $adopted[$table] = ['exists' => $exists, 'rows' => $rows, 'structure' => $structure];
        }
        $this->audit()->record('domain_adopted', 'schema', null, [
            'lot' => 'B2',
            'domain' => 'leads',
            'tables' => array_keys($adopted),
            'counts' => array_map(static fn(array $t): int => (int) $t['rows'], $adopted),
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
        foreach (self::ALERTS_TABLES as $table) {
            $adopted[$table] = $this->adoptTable($table);
        }
        $this->audit()->record('domain_adopted', 'schema', null, [
            'lot' => 'B3',
            'domain' => 'alerts',
            'tables' => array_keys($adopted),
            'counts' => array_map(static fn(array $t): int => (int) $t['rows'], $adopted),
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
        foreach (self::AUTOMATION_TABLES as $table) {
            $adopted[$table] = $this->adoptTable($table);
        }
        $this->audit()->record('domain_adopted', 'schema', null, [
            'lot' => 'B4',
            'domain' => 'automation',
            'tables' => array_keys($adopted),
            'counts' => array_map(static fn(array $t): int => (int) $t['rows'], $adopted),
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
        foreach (self::OWNER_STATS_TABLES as $table) {
            $adopted[$table] = $this->adoptTable($table);
        }
        $this->audit()->record('domain_adopted', 'schema', null, [
            'lot' => 'B5',
            'domain' => 'owner_stats',
            'tables' => array_keys($adopted),
            'counts' => array_map(static fn(array $t): int => (int) $t['rows'], $adopted),
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
        foreach (self::TRANSLATION_VARIANTS_TABLES as $table) {
            $adopted[$table] = $this->adoptTable($table);
        }
        $this->audit()->record('domain_adopted', 'schema', null, [
            'lot' => 'B6',
            'domain' => 'translation_variants',
            'tables' => array_keys($adopted),
            'counts' => array_map(static fn(array $t): int => (int) $t['rows'], $adopted),
        ]);
        return $adopted;
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
    private function adoptTable(string $table): array
    {
        global $wpdb;
        $name = $wpdb->prefix . $table;
        $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name;
        $structure = '';
        $rows = 0;
        if ($exists) {
            $rows = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$name}");
            $create = $wpdb->get_row("SHOW CREATE TABLE {$name}", ARRAY_N);
            $ddl = is_array($create) && isset($create[1]) && is_string($create[1]) ? $create[1] : '';
            $structure = $ddl !== '' ? hash('sha256', preg_replace('/\s+/', ' ', $ddl)) : 'unavailable';
        }
        return ['exists' => $exists, 'rows' => $rows, 'structure' => $structure];
    }
}
