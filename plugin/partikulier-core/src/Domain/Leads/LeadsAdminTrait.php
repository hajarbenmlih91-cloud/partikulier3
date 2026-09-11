<?php
/**
 * Accès données de l'écran d'administration « Leads WhatsApp » (port
 * fidèle des requêtes de Partikulier_Leads_Admin, lot B2) — le rendu
 * reste côté thème, les écritures de suivi sont journalisées.
 *
 * Lot D (découpage, arbitrage commanditaire « Référence + plugin », CDC
 * v1.2 annexe C) : le service LeadService (778 lignes, code porté par la campagne
 * au lot B2) est découpé en shell + traits sur le précédent B6
 * (class-localization.php 990 → 199 l.) — les méthodes sont déplacées
 * VERBATIM, l'API publique et les hooks restent portés par la classe shell
 * (le trait compose la même classe : aucune délégation, aucun changement de
 * mécanisme actif). Preuve : contrat module-perimeter-contract.php + rejeu
 * intégral des suites du domaine (oracle inchangé).
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Leads;

trait LeadsAdminTrait
{


    /* ------------------------------------------------------------------ */
    /* Accès données de l'écran d'administration (port fidèle des requêtes */
    /* de Partikulier_Leads_Admin — le rendu reste côté thème)             */
    /* ------------------------------------------------------------------ */

    /**
     * KPI de l'écran « Leads WhatsApp ».
     *
     * @return array{total: int, new: int, consented: int, today_contacts: int}
     */
    public static function admin_summary(): array
    {
        global $wpdb;
        $leads = self::leads_table();
        $followups = self::table('pk_lead_followups');
        $consents = self::table('pk_whatsapp_consents');
        $limits = self::table('pk_contact_limits');
        $day = current_time('Y-m-d');
        return [
            'total' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$leads}"),
            'new' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$leads} l LEFT JOIN {$followups} f ON f.lead_id = l.id WHERE l.opt_out_at IS NULL AND (f.status IS NULL OR f.status = 'new')"),
            'consented' => (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$consents} c INNER JOIN {$leads} l ON l.id = c.lead_id WHERE c.scope = %s AND c.granted_at IS NOT NULL AND c.revoked_at IS NULL AND l.opt_out_at IS NULL", self::CONSENT_SCOPE_SIMILAR)),
            'today_contacts' => (int) $wpdb->get_var($wpdb->prepare("SELECT COALESCE(SUM(contacts_count),0) FROM {$limits} WHERE day_key = %s", $day)),
        ];
    }


    /**
     * Lignes de l'écran : jointures, filtres et tri identiques au port d'origine.
     *
     * @param array{status: string, consent: string, search: string, page: int, orderby: string, order: string} $filters
     * @return array{rows: array<int, object>, total: int}
     */
    public static function admin_rows(array $filters, int $per_page = 20): array
    {
        global $wpdb;
        $leads = self::leads_table();
        $interests = self::table('pk_interest_events');
        $preferences = self::table('pk_buyer_preferences');
        $consents = self::table('pk_whatsapp_consents');
        $limits = self::table('pk_contact_limits');
        $followups = self::table('pk_lead_followups');
        $day = current_time('Y-m-d');
        $where = '1=1';
        $params = [];
        if ($filters['status'] && in_array($filters['status'], array_keys(self::followup_status_values()), true)) {
            $where .= " AND COALESCE(f.status, 'new') = %s";
            $params[] = $filters['status'];
        }
        if ('granted' === $filters['consent']) { $where .= ' AND c.granted_at IS NOT NULL AND c.revoked_at IS NULL AND l.opt_out_at IS NULL'; }
        if ('missing' === $filters['consent']) { $where .= ' AND (c.granted_at IS NULL OR c.revoked_at IS NOT NULL) AND l.opt_out_at IS NULL'; }
        if ('opted_out' === $filters['consent']) { $where .= ' AND l.opt_out_at IS NOT NULL'; }
        if ($filters['search']) {
            $where .= ' AND (i.reference_code LIKE %s OR i.property_snapshot LIKE %s)';
            $like = '%' . $wpdb->esc_like($filters['search']) . '%';
            $params[] = $like;
            $params[] = $like;
        }
        $joins = " FROM {$leads} l
                LEFT JOIN {$interests} i ON i.id = (SELECT MAX(i2.id) FROM {$interests} i2 WHERE i2.lead_id = l.id)
                LEFT JOIN {$preferences} p ON p.lead_id = l.id
                LEFT JOIN {$consents} c ON c.lead_id = l.id AND c.scope = 'similar_listings'
                LEFT JOIN {$followups} f ON f.lead_id = l.id
                LEFT JOIN {$limits} lim ON lim.lead_id = l.id AND lim.day_key = %s";
        $join_params = [$day];
        $count_sql = "SELECT COUNT(l.id) {$joins} WHERE {$where}";
        $count_params = array_merge($join_params, $params);
        $total = (int) $wpdb->get_var($wpdb->prepare($count_sql, $count_params));
        $offset = (max(1, (int) $filters['page']) - 1) * $per_page;
        $sort_columns = [
            'first_seen_at' => 'l.first_seen_at',
            'last_seen_at' => 'l.last_seen_at',
            'status' => "COALESCE(f.status, 'new')",
            'consent' => 'c.granted_at',
        ];
        $sort_key = isset($sort_columns[$filters['orderby']]) ? $filters['orderby'] : 'last_seen_at';
        $sort_direction = in_array($filters['order'], ['ASC', 'DESC'], true) ? $filters['order'] : 'DESC';
        $order_sql = $sort_columns[$sort_key] . ' ' . $sort_direction . ', l.id DESC';
        $list_sql = "SELECT l.*, i.reference_code, i.property_snapshot, p.budget_max, p.areas, p.layout_value, p.transaction_value, c.granted_at, c.revoked_at, f.status AS followup_status, f.note, COALESCE(lim.contacts_count, 0) AS today_contacts {$joins} WHERE {$where} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
        $list_params = array_merge($join_params, $params, [$per_page, $offset]);
        return ['rows' => (array) $wpdb->get_results($wpdb->prepare($list_sql, $list_params)), 'total' => $total];
    }


    /** Valeurs brutes des statuts de suivi (les libellés traduits restent côté thème). */
    public static function followup_status_values(): array
    {
        return [
            'new' => 'new',
            'in_progress' => 'in_progress',
            'owner_shared' => 'owner_shared',
            'qualified' => 'qualified',
            'closed' => 'closed',
        ];
    }


    /**
     * Mise à jour du suivi d'un lead (écran admin) — écriture plugin,
     * journalisée. Remplacement idempotent de la ligne pk_lead_followups.
     */
    public static function update_followup(int $lead_id, string $status, string $note, int $updated_by): bool
    {
        $lead_id = absint($lead_id);
        $status = sanitize_key($status);
        if (!$lead_id || !isset(self::followup_status_values()[$status])) {
            return false;
        }
        global $wpdb;
        $replaced = $wpdb->replace(self::table('pk_lead_followups'), [
            'lead_id' => $lead_id,
            'status' => $status,
            'note' => $note,
            'updated_by' => absint($updated_by),
            'updated_at' => current_time('mysql', true),
        ]);
        if (false === $replaced) {
            return false;
        }
        self::audit('lead_followup_updated', 'lead', $lead_id, ['status' => $status, 'by' => absint($updated_by)]);
        return true;
    }
}
