<?php
/**
 * Tableau de bord administrateur des leads WhatsApp qualifiés.
 *
 * Depuis le lot B2 de la refonte (CDC v1.2), ce module est une COUTURE pour
 * les accès données : le domaine leads (huit tables) est propriété du plugin
 * partikulier-core 2.2+ — KPI, lignes filtrées, export CSV et écritures de
 * suivi sont délégués à \Partikulier\Core\Domain\Leads\LeadService quand la
 * classe existe ; le rendu, les libellés traduits et les filtres restent au
 * thème. Sans le plugin, le chemin autonome historique 6.17.x est conservé
 * à l’identique (dégradation gracieuse REG-5).
 *
 * Toutes les données identifiantes restent chiffrées en base. Elles sont
 * déchiffrées uniquement au rendu pour les administrateurs WordPress.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        return;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

if ( class_exists( 'WP_List_Table' ) && ! class_exists( 'Partikulier_Leads_List_Table' ) ) {
        class Partikulier_Leads_List_Table extends WP_List_Table {
                private $statuses = array();

                public function __construct( $rows, $total, $per_page, $current_page, $statuses ) {
                        parent::__construct( array( 'singular' => 'pk_lead', 'plural' => 'pk_leads', 'ajax' => false ) );
                        $this->items = $rows;
                        $this->statuses = $statuses;
                        $this->set_pagination_args( array( 'total_items' => (int) $total, 'per_page' => (int) $per_page, 'total_pages' => max( 1, (int) ceil( $total / $per_page ) ) ) );
                        $this->set_pagination_args( array( 'current_page' => (int) $current_page ) );
                }

                public function get_columns() {
                        return array(
                                'lead'       => __( 'Lead', 'partikulier' ),
                                'interest'   => __( 'Intérêt et critères', 'partikulier' ),
                                'consent'    => __( 'Accord et quota', 'partikulier' ),
                                'followup'   => __( 'Suivi', 'partikulier' ),
                        );
                }

                protected function get_table_classes() {
                        return array( 'widefat', 'fixed', 'striped', 'pk-leads-table' );
                }

                public function get_sortable_columns() {
                        return array(
                                'lead'     => array( 'last_seen_at', true ),
                                'consent'  => array( 'consent', false ),
                                'followup' => array( 'status', false ),
                        );
                }

                public function prepare_items() {}

                public function display_rows() {
                        foreach ( $this->items as $lead ) {
                                Partikulier_Leads_Admin::render_lead_row( $lead, $this->statuses );
                        }
                }
        }
}

class Partikulier_Leads_Admin {

        const MENU_SLUG = 'pk-whatsapp-leads';
        const PER_PAGE = 20;

        public static function init() {
                add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
                add_action( 'admin_post_pk_update_lead_followup', array( __CLASS__, 'handle_followup_update' ) );
                add_action( 'admin_post_pk_export_leads', array( __CLASS__, 'handle_export' ) );
                add_action( 'admin_head', array( __CLASS__, 'admin_styles' ) );
        }

        public static function register_menu() {
                add_menu_page(
                        __( 'Leads WhatsApp', 'partikulier' ),
                        __( 'Leads WhatsApp', 'partikulier' ),
                        'manage_options',
                        self::MENU_SLUG,
                        array( __CLASS__, 'render_page' ),
                        'dashicons-chart-line',
                        58
                );
        }

        public static function handle_followup_update() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'Accès non autorisé.', 'partikulier' ), 403 );
                }
                check_admin_referer( 'pk_update_lead_followup' );

                $lead_id = absint( $_POST['lead_id'] ?? 0 );
                $status = sanitize_key( $_POST['followup_status'] ?? 'new' );
                $note = sanitize_textarea_field( wp_unslash( $_POST['followup_note'] ?? '' ) );
                $allowed_statuses = self::followup_statuses();
                if ( ! $lead_id || ! isset( $allowed_statuses[ $status ] ) ) {
                        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&pk_lead_error=1' ) );
                        exit;
                }

                global $wpdb;
                if ( self::core_leads() ) {
                        if ( self::core_leads()::update_followup( $lead_id, $status, $note, get_current_user_id() ) ) {
                                $redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::MENU_SLUG );
                                wp_safe_redirect( add_query_arg( 'pk_lead_updated', '1', $redirect ) );
                                exit;
                        }
                        wp_safe_redirect( admin_url( 'admin.php?page=' . self::MENU_SLUG . '&pk_lead_error=1' ) );
                        exit;
                }
                $wpdb->replace(
                        $wpdb->prefix . 'pk_lead_followups',
                        array(
                                'lead_id'    => $lead_id,
                                'status'     => $status,
                                'note'       => $note,
                                'updated_by' => get_current_user_id(),
                                'updated_at' => current_time( 'mysql', true ),
                        )
                );

                $redirect = wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=' . self::MENU_SLUG );
                wp_safe_redirect( add_query_arg( 'pk_lead_updated', '1', $redirect ) );
                exit;
        }

        public static function handle_export() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'Accès non autorisé.', 'partikulier' ), 403 );
                }
                check_admin_referer( 'pk_export_leads' );
                $filters = array(
                        'status' => sanitize_key( $_POST['lead_status'] ?? '' ),
                        'consent' => sanitize_key( $_POST['consent'] ?? '' ),
                        'search' => sanitize_text_field( wp_unslash( $_POST['q'] ?? '' ) ),
'page' => 1,
                                'orderby' => sanitize_key( $_POST['orderby'] ?? 'last_seen_at' ),
                                'order' => strtoupper( sanitize_key( $_POST['order'] ?? 'DESC' ) ),
                        );
                $result = self::lead_rows( $filters );
                nocache_headers();
                header( 'Content-Type: text/csv; charset=UTF-8' );
                header( 'Content-Disposition: attachment; filename=partikulier-leads-' . gmdate( 'Ymd-His' ) . '.csv' );
                $out = fopen( 'php://output', 'w' );
                fwrite( $out, "\xEF\xBB\xBF" );
                fputcsv( $out, array( 'reference', 'property_title', 'consent', 'followup_status', 'last_seen_at' ), ';' );
                foreach ( $result['rows'] as $lead ) {
                        $snapshot = json_decode( (string) $lead->property_snapshot, true );
                        $values = array( $lead->reference_code, $snapshot['title'] ?? __( 'Annonce supprimée', 'partikulier' ), $lead->opt_out_at ? 'opted_out' : ( $lead->granted_at && ! $lead->revoked_at ? 'granted' : 'missing' ), $lead->followup_status ?: 'new', $lead->last_seen_at );
                        $values = array_map( array( __CLASS__, 'csv_safe_value' ), $values );
                        fputcsv( $out, $values, ';' );
                }
                fclose( $out );
                exit;
        }

        private static function csv_safe_value( $value ) {
                $value = (string) $value;
                return preg_match( '/^[=+\\-@]/', $value ) ? "'" . $value : $value;
        }

        public static function render_page() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'Accès non autorisé.', 'partikulier' ), 403 );
                }

                $filters = self::filters();
                $summary = self::summary();
                $result = self::lead_rows( $filters );
$statuses = self::followup_statuses();
                        ?>
                <div class="wrap pk-leads-admin">
                        <h1><?php esc_html_e( 'Leads WhatsApp', 'partikulier' ); ?></h1>
                        <p class="pk-leads-intro"><?php esc_html_e( 'Demandes qualifiées reçues depuis les liens Partikulier. Les numéros sont chiffrés en base et visibles ici uniquement pour les administrateurs.', 'partikulier' ); ?></p>

                        <?php if ( isset( $_GET['pk_lead_updated'] ) ) : ?>
                                <div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Suivi du lead mis à jour.', 'partikulier' ); ?></p></div>
                        <?php endif; ?>

                        <div class="pk-leads-kpis" aria-label="<?php esc_attr_e( 'Indicateurs des leads', 'partikulier' ); ?>">
                                <div><strong><?php echo esc_html( number_format_i18n( $summary['total'] ) ); ?></strong><span><?php esc_html_e( 'Leads connus', 'partikulier' ); ?></span></div>
                                <div><strong><?php echo esc_html( number_format_i18n( $summary['new'] ) ); ?></strong><span><?php esc_html_e( 'À traiter', 'partikulier' ); ?></span></div>
                                <div><strong><?php echo esc_html( number_format_i18n( $summary['consented'] ) ); ?></strong><span><?php esc_html_e( 'Accord annonces similaires', 'partikulier' ); ?></span></div>
                                <div><strong><?php echo esc_html( number_format_i18n( $summary['today_contacts'] ) ); ?></strong><span><?php esc_html_e( 'Contacts propriétaires aujourd’hui', 'partikulier' ); ?></span></div>
                        </div>

                        <form class="pk-leads-filters" method="get" action="<?php echo esc_url( admin_url( 'admin.php' ) ); ?>">
                                <input type="hidden" name="page" value="<?php echo esc_attr( self::MENU_SLUG ); ?>" />
                                <label>
                                        <span><?php esc_html_e( 'Statut de suivi', 'partikulier' ); ?></span>
                                        <select name="lead_status">
                                                <option value=""><?php esc_html_e( 'Tous les statuts', 'partikulier' ); ?></option>
                                                <?php foreach ( $statuses as $value => $label ) : ?>
                                                        <option value="<?php echo esc_attr( $value ); ?>" <?php selected( $filters['status'], $value ); ?>><?php echo esc_html( $label ); ?></option>
                                                <?php endforeach; ?>
                                        </select>
                                </label>
                                <label>
                                        <span><?php esc_html_e( 'Accord annonces similaires', 'partikulier' ); ?></span>
                                        <select name="consent">
                                                <option value=""><?php esc_html_e( 'Tous', 'partikulier' ); ?></option>
                                                <option value="granted" <?php selected( $filters['consent'], 'granted' ); ?>><?php esc_html_e( 'Accord donné', 'partikulier' ); ?></option>
                                                <option value="missing" <?php selected( $filters['consent'], 'missing' ); ?>><?php esc_html_e( 'Sans accord', 'partikulier' ); ?></option>
                                                <option value="opted_out" <?php selected( $filters['consent'], 'opted_out' ); ?>><?php esc_html_e( 'STOP / opposition', 'partikulier' ); ?></option>
                                        </select>
                                </label>
                                <label class="pk-leads-search">
                                        <span><?php esc_html_e( 'Annonce ou référence', 'partikulier' ); ?></span>
                                        <input type="search" name="q" value="<?php echo esc_attr( $filters['search'] ); ?>" placeholder="PK-… ou titre" />
                                </label>
                                <button class="button button-primary" type="submit"><?php esc_html_e( 'Filtrer', 'partikulier' ); ?></button>
                        </form>
                        <?php if ( current_user_can( 'manage_options' ) ) : ?>
                        <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="pk-leads-export-form">
                                <?php wp_nonce_field( 'pk_export_leads' ); ?><input type="hidden" name="action" value="pk_export_leads" />
                                <input type="hidden" name="lead_status" value="<?php echo esc_attr( $filters['status'] ); ?>" /><input type="hidden" name="consent" value="<?php echo esc_attr( $filters['consent'] ); ?>" /><input type="hidden" name="q" value="<?php echo esc_attr( $filters['search'] ); ?>" />
                                <button class="button" type="submit"><?php esc_html_e( 'Exporter les résultats CSV', 'partikulier' ); ?></button>
                        </form>
                        <?php endif; ?>

                                <div class="pk-leads-table-wrap">
                                        <?php
                                        $table = new Partikulier_Leads_List_Table( $result['rows'], $result['total'], self::PER_PAGE, $filters['page'], $statuses );
                                        $table->display();
                                        ?>
                                </div>
                        <p class="description pk-leads-policy"><?php esc_html_e( 'Utilisez le bouton WhatsApp uniquement dans le cadre permis par l’échange et le consentement de la personne. Pour les envois proactifs après la fenêtre de conversation, n8n doit employer un modèle WhatsApp approuvé.', 'partikulier' ); ?></p>
                </div>
                <?php
        }

                public static function render_lead_row( $lead, $statuses ) {
                $phone = Partikulier_Buyer_Qualification::decrypt_phone_for_admin( $lead->phone_encrypted );
                $snapshot = json_decode( (string) $lead->property_snapshot, true );
                $areas = json_decode( (string) $lead->areas, true );
                $areas = is_array( $areas ) ? array_filter( $areas ) : array();
                $property_title = ! empty( $snapshot['title'] ) ? $snapshot['title'] : __( 'Annonce supprimée', 'partikulier' );
                $property_url = ! empty( $snapshot['url'] ) ? $snapshot['url'] : '';
                $consent = $lead->opt_out_at ? __( 'STOP / opposition', 'partikulier' ) : ( $lead->granted_at && ! $lead->revoked_at ? __( 'Accord donné', 'partikulier' ) : __( 'Sans accord', 'partikulier' ) );
                $status = $lead->followup_status && isset( $statuses[ $lead->followup_status ] ) ? $lead->followup_status : 'new';
                ?>
                <tr>
                        <td>
                                <strong><?php echo esc_html( $phone ? '+' . $phone : __( 'Numéro indisponible', 'partikulier' ) ); ?></strong>
                                <small><?php printf( esc_html__( 'Vu le %s', 'partikulier' ), esc_html( wp_date( get_option( 'date_format' ) . ' H:i', strtotime( (string) ( $lead->last_seen_at ?? '' ) ) ) ) ); ?></small>
                                <?php if ( $phone && ! $lead->opt_out_at ) : ?><a class="button button-small" href="<?php echo esc_url( 'https://wa.me/' . rawurlencode( $phone ) ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( 'Ouvrir WhatsApp', 'partikulier' ); ?></a><?php endif; ?>
                        </td>
                        <td>
                                <strong><?php echo $property_url ? '<a href="' . esc_url( $property_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $property_title ) . '</a>' : esc_html( $property_title ); ?></strong>
                                <small><?php echo esc_html( (string) ( $lead->reference_code ?? '' ) ); ?></small>
                                <p class="pk-leads-criteria"><?php echo esc_html( self::criteria_text( $lead, $areas ) ); ?></p>
                        </td>
                        <td>
                                <span class="pk-leads-consent <?php echo esc_attr( $lead->opt_out_at ? 'is-opted-out' : ( $lead->granted_at && ! $lead->revoked_at ? 'is-granted' : 'is-missing' ) ); ?>"><?php echo esc_html( $consent ); ?></span>
                                <small><?php printf( esc_html__( '%1$d / %2$d propriétaires aujourd’hui', 'partikulier' ), absint( $lead->today_contacts ), Partikulier_Buyer_Qualification::daily_limit() ); ?></small>
                        </td>
                        <td>
                                <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
                                        <?php wp_nonce_field( 'pk_update_lead_followup' ); ?>
                                        <input type="hidden" name="action" value="pk_update_lead_followup" />
                                        <input type="hidden" name="lead_id" value="<?php echo esc_attr( $lead->id ); ?>" />
                                        <select name="followup_status" aria-label="<?php esc_attr_e( 'Statut de suivi', 'partikulier' ); ?>">
                                                <?php foreach ( $statuses as $value => $label ) : ?><option value="<?php echo esc_attr( $value ); ?>" <?php selected( $status, $value ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?>
                                        </select>
                                        <textarea name="followup_note" rows="2" placeholder="<?php esc_attr_e( 'Note interne', 'partikulier' ); ?>"><?php echo esc_textarea( (string) ( $lead->note ?? '' ) ); ?></textarea>
                                        <button class="button button-primary button-small" type="submit"><?php esc_html_e( 'Enregistrer', 'partikulier' ); ?></button>
                                </form>
                        </td>
                </tr>
                <?php
        }

        private static function criteria_text( $lead, $areas ) {
                $items = array();
                if ( $lead->budget_max ) { $items[] = sprintf( __( 'Budget max. %s', 'partikulier' ), number_format_i18n( $lead->budget_max ) ); }
                if ( $areas ) { $items[] = implode( ', ', array_map( 'sanitize_text_field', $areas ) ); }
                if ( ! empty( $lead->layout_value ) ) { $items[] = (string) $lead->layout_value; }
                if ( ! empty( $lead->transaction_value ) ) { $items[] = (string) $lead->transaction_value; }
                return $items ? implode( ' · ', $items ) : __( 'Critères non précisés', 'partikulier' );
        }

        private static function filters() {
                return array(
                        'status' => sanitize_key( $_GET['lead_status'] ?? '' ),
                        'consent' => sanitize_key( $_GET['consent'] ?? '' ),
                        'search' => sanitize_text_field( wp_unslash( $_GET['q'] ?? '' ) ),
'page' => max( 1, absint( $_GET['paged'] ?? 1 ) ),
                                'orderby' => sanitize_key( $_GET['orderby'] ?? 'last_seen_at' ),
                                'order' => strtoupper( sanitize_key( $_GET['order'] ?? 'DESC' ) ),
                        );
        }

                public static function followup_statuses() {
                return array(
                        'new'          => __( 'À traiter', 'partikulier' ),
                        'in_progress'  => __( 'En cours', 'partikulier' ),
                        'owner_shared' => __( 'Contact propriétaire transmis', 'partikulier' ),
                        'qualified'    => __( 'Qualifié', 'partikulier' ),
                        'closed'       => __( 'Clos', 'partikulier' ),
                );
        }

        private static function core_leads() {
                return class_exists( '\Partikulier\Core\Domain\Leads\LeadService' )
                        ? '\Partikulier\Core\Domain\Leads\LeadService'
                        : null;
        }

        private static function summary() {
                if ( self::core_leads() ) {
                        return self::core_leads()::admin_summary();
                }
                global $wpdb;
                $leads = $wpdb->prefix . 'pk_buyer_leads';
                $followups = $wpdb->prefix . 'pk_lead_followups';
                $consents = $wpdb->prefix . 'pk_whatsapp_consents';
                $limits = $wpdb->prefix . 'pk_contact_limits';
                $day = current_time( 'Y-m-d' );
                return array(
                        'total' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads}" ),
                        'new' => (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$leads} l LEFT JOIN {$followups} f ON f.lead_id = l.id WHERE l.opt_out_at IS NULL AND (f.status IS NULL OR f.status = 'new')" ),
                        'consented' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$consents} c INNER JOIN {$leads} l ON l.id = c.lead_id WHERE c.scope = %s AND c.granted_at IS NOT NULL AND c.revoked_at IS NULL AND l.opt_out_at IS NULL", 'similar_listings' ) ),
                        'today_contacts' => (int) $wpdb->get_var( $wpdb->prepare( "SELECT COALESCE(SUM(contacts_count),0) FROM {$limits} WHERE day_key = %s", $day ) ),
                );
        }

        private static function lead_rows( $filters ) {
                if ( self::core_leads() ) {
                        return self::core_leads()::admin_rows( array(
                                'status' => $filters['status'],
                                'consent' => $filters['consent'],
                                'search' => $filters['search'],
                                'page' => $filters['page'],
                                'orderby' => $filters['orderby'],
                                'order' => $filters['order'],
                        ), self::PER_PAGE );
                }
                global $wpdb;
                $leads = $wpdb->prefix . 'pk_buyer_leads';
                $interests = $wpdb->prefix . 'pk_interest_events';
                $preferences = $wpdb->prefix . 'pk_buyer_preferences';
                $consents = $wpdb->prefix . 'pk_whatsapp_consents';
                $limits = $wpdb->prefix . 'pk_contact_limits';
                $followups = $wpdb->prefix . 'pk_lead_followups';
                $day = current_time( 'Y-m-d' );
                $where = '1=1';
                $params = array();
                if ( $filters['status'] && isset( self::followup_statuses()[ $filters['status'] ] ) ) {
                        $where .= " AND COALESCE(f.status, 'new') = %s";
                        $params[] = $filters['status'];
                }
                if ( 'granted' === $filters['consent'] ) { $where .= ' AND c.granted_at IS NOT NULL AND c.revoked_at IS NULL AND l.opt_out_at IS NULL'; }
                if ( 'missing' === $filters['consent'] ) { $where .= ' AND (c.granted_at IS NULL OR c.revoked_at IS NOT NULL) AND l.opt_out_at IS NULL'; }
                if ( 'opted_out' === $filters['consent'] ) { $where .= ' AND l.opt_out_at IS NOT NULL'; }
                if ( $filters['search'] ) {
                        $where .= ' AND (i.reference_code LIKE %s OR i.property_snapshot LIKE %s)';
                        $like = '%' . $wpdb->esc_like( $filters['search'] ) . '%';
                        $params[] = $like;
                        $params[] = $like;
                }
                $joins = " FROM {$leads} l
                        LEFT JOIN {$interests} i ON i.id = (SELECT MAX(i2.id) FROM {$interests} i2 WHERE i2.lead_id = l.id)
                        LEFT JOIN {$preferences} p ON p.lead_id = l.id
                        LEFT JOIN {$consents} c ON c.lead_id = l.id AND c.scope = 'similar_listings'
                        LEFT JOIN {$followups} f ON f.lead_id = l.id
                        LEFT JOIN {$limits} lim ON lim.lead_id = l.id AND lim.day_key = %s";
                $join_params = array( $day );
                        $count_sql = "SELECT COUNT(l.id) {$joins} WHERE {$where}";
                        $count_params = array_merge( $join_params, $params );
                        $total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $count_params ) );
                        $offset = ( $filters['page'] - 1 ) * self::PER_PAGE;
                        $sort_columns = array(
                                'first_seen_at' => 'l.first_seen_at',
                                'last_seen_at'  => 'l.last_seen_at',
                                'status'       => "COALESCE(f.status, 'new')",
                                'consent'      => 'c.granted_at',
                        );
                        $sort_key = isset( $sort_columns[ $filters['orderby'] ] ) ? $filters['orderby'] : 'last_seen_at';
                        $sort_direction = in_array( $filters['order'], array( 'ASC', 'DESC' ), true ) ? $filters['order'] : 'DESC';
                        $order_sql = $sort_columns[ $sort_key ] . ' ' . $sort_direction . ', l.id DESC';
                        $list_sql = "SELECT l.*, i.reference_code, i.property_snapshot, p.budget_max, p.areas, p.layout_value, p.transaction_value, c.granted_at, c.revoked_at, f.status AS followup_status, f.note, COALESCE(lim.contacts_count, 0) AS today_contacts {$joins} WHERE {$where} ORDER BY {$order_sql} LIMIT %d OFFSET %d";
                $list_params = array_merge( $join_params, $params, array( self::PER_PAGE, $offset ) );
                return array( 'rows' => $wpdb->get_results( $wpdb->prepare( $list_sql, $list_params ) ), 'total' => $total );
        }

        private static function pagination( $total, $filters ) {
                $total_pages = max( 1, (int) ceil( $total / self::PER_PAGE ) );
                if ( $total_pages < 2 ) { return; }
                $base_args = array_filter( array( 'page' => self::MENU_SLUG, 'lead_status' => $filters['status'], 'consent' => $filters['consent'], 'q' => $filters['search'] ) );
                echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( paginate_links( array( 'base' => add_query_arg( array_merge( $base_args, array( 'paged' => '%#%' ) ), admin_url( 'admin.php' ) ), 'format' => '', 'current' => $filters['page'], 'total' => $total_pages ) ) ) . '</div></div>';
        }

        public static function admin_styles() {
                if ( empty( $_GET['page'] ) || self::MENU_SLUG !== $_GET['page'] ) { return; }
                        echo '<style>.pk-leads-admin{max-width:1480px}.pk-leads-intro{max-width:850px;color:#50575e}.pk-leads-kpis{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:16px;margin:22px 0}.pk-leads-kpis div{background:#fff;border:1px solid #dcdcde;border-left:4px solid #9b6a3d;padding:18px}.pk-leads-kpis strong,.pk-leads-kpis span{display:block}.pk-leads-kpis strong{font-size:28px;line-height:1.1}.pk-leads-kpis span{margin-top:6px;color:#50575e}.pk-leads-filters{display:flex;align-items:end;gap:12px;flex-wrap:wrap;background:#fff;padding:16px;border:1px solid #dcdcde;margin:0 0 16px}.pk-leads-filters label{display:grid;gap:4px}.pk-leads-filters span{font-size:12px;font-weight:600}.pk-leads-search{min-width:240px}.pk-leads-table-wrap{overflow:auto}.pk-leads-table{min-width:980px}.pk-leads-table td{vertical-align:top;padding:14px}.pk-leads-table small{display:block;margin-top:5px;color:#646970}.pk-leads-table select{max-width:100%}.pk-leads-table textarea{display:block;width:100%;margin:7px 0;resize:vertical}.pk-leads-criteria{margin:7px 0 0;color:#50575e}.pk-leads-consent{display:inline-block;padding:4px 8px;border-radius:99px;font-weight:600;font-size:12px}.pk-leads-consent.is-granted{background:#e7f5e8;color:#176b2c}.pk-leads-consent.is-missing{background:#fff2d8;color:#8a5000}.pk-leads-consent.is-opted-out{background:#fce8e6;color:#a12622}.pk-leads-admin .button.button-primary{background:#9b6a3d;border-color:#9b6a3d;color:#fff;box-shadow:none}.pk-leads-admin .button.button-primary:hover,.pk-leads-admin .button.button-primary:focus{background:#7e4f25;border-color:#7e4f25;color:#fff}.pk-leads-policy{margin:20px 0}@media(max-width:782px){.pk-leads-kpis{grid-template-columns:repeat(2,minmax(120px,1fr))}.pk-leads-filters{align-items:stretch}.pk-leads-filters label,.pk-leads-search{width:100%}}</style>';
        }
}

Partikulier_Leads_Admin::init();