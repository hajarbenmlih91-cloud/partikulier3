<?php
/**
 * Module : creation automatique des pages indispensables du theme.
 *
 * Probleme resolu : le theme propose des gabarits (« Deposer une annonce »,
 * « Mes annonces ») et crée désormais la page canonique /deposer/ avant le
 * provisioning Polylang. Les pages correspondantes ne tombent plus sur une 404.
 *
 * Ce module :
 *  - cree les pages manquantes a l'activation du theme ;
 *  - rattache le bon gabarit a chaque page ;
 *  - affiche une alerte dans l'admin avec un bouton de reparation si une page
 *    a ete supprimee par la suite.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Required_Pages {

        /**
         * Action de reparation manuelle.
         */
        const ACTION = 'pk_create_required_pages';

        /**
         * Pages indispensables : slug => definition.
         *
         * @return array
         */
        public static function pages() {
                return array(
                        'deposer' => array(
                                'title'    => __( 'Déposer une annonce', 'partikulier' ),
                                'template' => 'templates/page-deposer-annonce.php',
                                'content'  => '',
                        ),
                        'mes-annonces'        => array(
                                'title'    => __( 'Mes annonces', 'partikulier' ),
                                'template' => 'templates/page-mes-annonces.php',
                                'content'  => '',
                        ),
                        'connexion'           => array(
                                'title'    => __( 'Connexion', 'partikulier' ),
                                'template' => 'templates/page-connexion.php',
                                'content'  => '',
                        ),
                                                        'favoris'             => array(
                                        'title'    => __( 'Favoris', 'partikulier' ),
                                        'template' => 'templates/page-favoris.php',
                                        'content'  => '',
                                ),
                                'faq'                 => array(
                                        'title'    => __( 'Questions fréquentes', 'partikulier' ),
                                        'template' => 'templates/page.php',
                                        'content'  => '<h2>Comment publier une annonce ?</h2><p>Déposez votre bien gratuitement, renseignez ses informations et ajoutez des photos. Chaque annonce est vérifiée avant publication.</p><h2>Le contact est-il direct ?</h2><p>Oui. Partikulier met en relation les particuliers sans commission ni intermédiaire.</p>',
                                ),
                                'contact'             => array(
                                        'title'    => __( 'Contactez-nous', 'partikulier' ),
                                        'template' => 'templates/page.php',
                                        'content'  => '<p>Pour toute question concernant une annonce ou le fonctionnement de Partikulier, écrivez-nous à l’adresse indiquée dans le pied de page.</p>',
                                ),

                );
        }

        public static function init() {
                add_action( 'init', array( __CLASS__, 'maybe_migrate_legacy_slugs' ), 1 );
                add_action( 'init', array( __CLASS__, 'sync_estatik_login_page' ), 99 );
                add_action( 'after_switch_theme', array( __CLASS__, 'create_missing' ) );
                add_action( 'admin_notices', array( __CLASS__, 'notice' ) );
                add_action( 'admin_post_' . self::ACTION, array( __CLASS__, 'handle_repair' ) );
        }

        /**
         * Rattache la page « Connexion » à Estatik (login_page_id) pour que TOUTES
         * les passes d'authentification du plugin (erreurs de connexion, inscription,
         * e-mails de réinitialisation) renvoient vers la page du thème et non vers
         * wp-login.php. Ne s'applique qu'une fois : un choix explicite de
         * l'administrateur dans les réglages Estatik n'est jamais écrasé.
         *
         * @return void
         */
        public static function sync_estatik_login_page() {
                if ( ! function_exists( 'ests' ) || ! function_exists( 'ests_save_option' ) ) {
                        return;
                }
                if ( ests( 'login_page_id' ) ) {
                        return; // Réglage déjà posé (admin ou installation précédente).
                }
                $page = self::find( 'connexion' );
                if ( ! $page instanceof WP_Post || 'publish' !== $page->post_status ) {
                        return;
                }
                ests_save_option( 'login_page_id', (int) $page->ID );
        }

        /**
         * Retourne la page correspondant a un slug, quelle que soit la langue.
         *
         * @param string $slug Slug recherche.
         * @return WP_Post|null
         */
        public static function find( $slug ) {
                $slug = sanitize_title( $slug );
                $page = get_page_by_path( $slug, OBJECT, 'page' );
                if ( $page instanceof WP_Post && 'trash' !== $page->post_status ) {
                        return $page;
                }

                // Compatibilité d’upgrade : reconnaître l’ancien slug avant sa migration.
                foreach ( self::legacy_slugs( $slug ) as $legacy_slug ) {
                        $legacy_page = get_page_by_path( $legacy_slug, OBJECT, 'page' );
                        if ( $legacy_page instanceof WP_Post && 'trash' !== $legacy_page->post_status ) {
                                return $legacy_page;
                        }
                }

                $pages = self::pages();
                if ( ! isset( $pages[ $slug ]['template'] ) ) {
                        return null;
                }

                // Rattrapage : une page peut exister avec un slug traduit ou suffixe (-2).
                $found = get_posts( array(
                        'post_type'        => 'page',
                        'post_status'      => array( 'publish', 'draft', 'pending', 'private' ),
                        'posts_per_page'   => 1,
                        'meta_key'         => '_wp_page_template',
                        'meta_value'       => $pages[ $slug ]['template'],
                        'suppress_filters' => true,
                        'lang'             => '',
                ) );

                return $found ? $found[0] : null;
        }

        /**
         * Retourne les anciens slugs connus pour une page canonique.
         *
         * @param string $canonical_slug Slug canonique.
         * @return array
         */
        private static function legacy_slugs( $canonical_slug ) {
                $aliases = array(
                        'deposer' => array( 'deposer-une-annonce' ),
                );
                return isset( $aliases[ $canonical_slug ] ) ? $aliases[ $canonical_slug ] : array();
        }

        /**
         * Migre les pages historiques vers les slugs canoniques sans doublon.
         *
         * @return void
         */
        public static function maybe_migrate_legacy_slugs() {
                if ( '1.1.0' === get_option( 'pk_required_pages_migration', '' ) ) {
                        return;
                }
                self::migrate_legacy_slugs();
                update_option( 'pk_required_pages_migration', '1.1.0', false );
        }

        private static function migrate_legacy_slugs() {
                foreach ( self::pages() as $canonical_slug => $definition ) {
                        if ( get_page_by_path( $canonical_slug, OBJECT, 'page' ) ) {
                                continue;
                        }
                        foreach ( self::legacy_slugs( $canonical_slug ) as $legacy_slug ) {
                                $legacy_page = get_page_by_path( $legacy_slug, OBJECT, 'page' );
                                if ( ! $legacy_page instanceof WP_Post || 'trash' === $legacy_page->post_status ) {
                                        continue;
                                }
                                $template = get_post_meta( $legacy_page->ID, '_wp_page_template', true );
                                if ( $definition['template'] !== $template ) {
                                        continue;
                                }
                                wp_update_post( array( 'ID' => $legacy_page->ID, 'post_name' => $canonical_slug ) );
                                break;
                        }
                }
        }

        /**
         * Liste les slugs de pages manquantes.
         *
         * @return array
         */
        public static function missing() {
                $missing = array();
                foreach ( self::pages() as $slug => $definition ) {
                        if ( ! self::find( $slug ) ) {
                                $missing[ $slug ] = $definition;
                        }
                }

                return $missing;
        }

        /**
         * Cree les pages absentes et rattache leur gabarit.
         *
         * @return array Slugs reellement crees.
         */
        public static function create_missing() {
                self::maybe_migrate_legacy_slugs();
                $created = array();

                foreach ( self::missing() as $slug => $definition ) {
                        $page_id = wp_insert_post( array(
                                'post_type'      => 'page',
                                'post_status'    => 'publish',
                                'post_title'     => $definition['title'],
                                'post_name'      => $slug,
                                'post_content'   => $definition['content'],
                                'comment_status' => 'closed',
                                'ping_status'    => 'closed',
                        ), true );

                        if ( is_wp_error( $page_id ) || ! $page_id ) {
                                continue;
                        }

                        update_post_meta( $page_id, '_wp_page_template', $definition['template'] );

                        // Polylang : rattacher la page a la langue par defaut pour qu'elle soit visible.
                        if ( function_exists( 'pll_default_language' ) && function_exists( 'pll_set_post_language' ) ) {
                                pll_set_post_language( $page_id, pll_default_language() );
                        }

                        $created[] = $slug;
                }

                if ( $created && class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
                        Partikulier_Cache::purge_all();
                }

                return $created;
        }

        /**
         * Alerte admin listant les pages manquantes, avec bouton de reparation.
         */
        public static function notice() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        return;
                }

                $missing = self::missing();
                if ( ! $missing ) {
                        return;
                }

                $titles = array();
                foreach ( $missing as $definition ) {
                        $titles[] = $definition['title'];
                }

                $url = wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION ), self::ACTION );
                ?>
                <div class="notice notice-error">
                        <p>
                                <strong><?php esc_html_e( 'Partikulier — page manquante', 'partikulier' ); ?></strong><br>
                                <?php
                                printf(
                                        /* translators: %s: liste des pages manquantes. */
                                        esc_html__( 'Ces pages du thème n’existent pas encore : %s. Tant qu’elles manquent, le bouton « Déposer une annonce » ne mène nulle part.', 'partikulier' ),
                                        '<em>' . esc_html( implode( ', ', $titles ) ) . '</em>'
                                );
                                ?>
                        </p>
                        <p>
                                <a class="button button-primary" href="<?php echo esc_url( $url ); ?>">
                                        <?php esc_html_e( 'Créer les pages manquantes', 'partikulier' ); ?>
                                </a>
                        </p>
                </div>
                <?php
        }

        /**
         * Reparation declenchee depuis l'alerte admin.
         */
        public static function handle_repair() {
                if ( ! current_user_can( 'manage_options' ) ) {
                        wp_die( esc_html__( 'Accès refusé.', 'partikulier' ), '', array( 'response' => 403 ) );
                }
                check_admin_referer( self::ACTION );

                $created = self::create_missing();

                $redirect = wp_get_referer() ? wp_get_referer() : admin_url();
                wp_safe_redirect( add_query_arg( 'pk_pages_created', count( $created ), $redirect ) );
                exit;
        }
}

Partikulier_Required_Pages::init();
