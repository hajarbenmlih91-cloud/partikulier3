<?php
/**
 * Archive : annonces, taxonomies (type, action, ville...), recherche.
 *
 * Layout calque sur la page Shop du kit "Woo Shop" :
 * sidebar gauche "Filtres" (prix, type, action, villes) + zone contenu
 * avec compteur "Affichage 1-X de N resultats" et tri a droite.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}
get_header();

// Contexte geo pour le titre SEO.
$queried  = get_queried_object();
$is_geo   = $queried instanceof WP_Term && in_array( $queried->taxonomy, Partikulier_Setup::GEO_TAX, true );
$is_type  = $queried instanceof WP_Term && PARTIKULIER_ESTATIK_TYPE_TAXONOMY === $queried->taxonomy;
?>

<section class="pk-archive">
        <div class="pk-container">
                <?php echo Partikulier_Geo::breadcrumbs_html(); // phpcs:ignore ?>

                <header class="pk-archive-head">
                        <p class="pk-editorial-kicker"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Le catalogue direct', 'Le catalogue direct', 'partikulier' ) ); ?></p>
                        <h1 class="pk-archive-title">
                                <?php
                                if ( is_search() ) {
                                        printf(
                                                /* translators: %s: terme recherche */
                                                esc_html__( 'Résultats pour « %s »', 'partikulier' ),
                                                esc_html( get_search_query() )
                                        );
                                } elseif ( $is_geo ) {
                                        printf(
                                                /* translators: %s: nom de la ville/region */
                                                esc_html__( 'Annonces immobilières à %s', 'partikulier' ),
                                                esc_html( $queried->name )
                                        );
                                } elseif ( $is_type ) {
                                        printf(
                                                /* translators: %s: type de bien */
                                                esc_html__( '%s à vendre et à louer', 'partikulier' ),
                                                esc_html( $queried->name )
                                        );
                                        } elseif ( is_post_type_archive( PARTIKULIER_ESTATIK_POST_TYPE ) || ( is_page() && 'annonces' === get_post_field( 'post_name' ) ) ) {
                                                esc_html_e( 'Toutes les annonces', 'partikulier' );
                                        } else {
                                        single_term_title();
                                }
                                ?>
                        </h1>
                        <div class="pk-archive-toolbar">
                                <?php
                                if ( have_posts() ) {
                                        printf(
                                                /* translators: 1: index premier, 2: index dernier, 3: total */
                                                        esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Affichage %1$s–%2$s de %3$s résultats', 'Affichage %1$s–%2$s de %3$s résultats', 'partikulier' ) : __( 'Affichage %1$s–%2$s de %3$s résultats', 'partikulier' ) ),
                                                '<strong>' . esc_html( number_format_i18n( ( max( 1, (int) $wp_query->query_vars['paged'] ) - 1 ) * (int) $wp_query->query_vars['posts_per_page'] + 1 ) ) . '</strong>',
                                                '<strong>' . esc_html( number_format_i18n( ( max( 1, (int) $wp_query->query_vars['paged'] ) - 1 ) * (int) $wp_query->query_vars['posts_per_page'] + (int) $wp_query->post_count ) ) . '</strong>',
                                                '<strong>' . esc_html( number_format_i18n( (int) $wp_query->found_posts ) ) . '</strong>'
                                        );
                                } else {
                                        printf(
                                                /* translators: %s: nombre de résultats */
                                                esc_html( _n( '%s annonce trouvée', '%s annonces trouvées', (int) $wp_query->found_posts, 'partikulier' ) ),
                                                '<strong>' . esc_html( number_format_i18n( (int) $wp_query->found_posts ) ) . '</strong>'
                                        );
                                }
                                ?>
                                <div class="pk-view-toggle" role="group" aria-label="<?php esc_attr_e( 'Affichage des annonces', 'partikulier' ); ?>">
                                        <button type="button" class="pk-view-btn is-active" data-view="grid" aria-pressed="true" aria-label="<?php esc_attr_e( 'Vue grille', 'partikulier' ); ?>">
                                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
                                        </button>
                                        <button type="button" class="pk-view-btn" data-view="list" aria-pressed="false" aria-label="<?php esc_attr_e( 'Vue liste', 'partikulier' ); ?>">
                                                <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 6h13M8 12h13M8 18h13M3 6h.01M3 12h.01M3 18h.01"/></svg>
                                        </button>
                                </div>
                                <form class="pk-archive-order" action="" method="get" role="search" aria-label="<?php esc_attr_e( 'Trier les annonces', 'partikulier' ); ?>">
                                        <?php
                                        foreach ( $_GET as $key => $value ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                                                if ( 'pk_order' === $key ) {
                                                        continue;
                                                }
                                                if ( is_array( $value ) ) {
                                                        foreach ( $value as $v ) {
                                                                echo '<input type="hidden" name="' . esc_attr( $key ) . '[]" value="' . esc_attr( $v ) . '">';
                                                        }
                                                } else {
                                                        echo '<input type="hidden" name="' . esc_attr( $key ) . '" value="' . esc_attr( $value ) . '">';
                                                }
                                        }
                                        ?>
                                        <select name="pk_order" onchange="this.form.submit()" aria-label="<?php esc_attr_e( 'Tri', 'partikulier' ); ?>">
                                                        <option value="recent"<?php selected( empty( $_GET['pk_order'] ) || 'recent' === $_GET['pk_order'] ); ?>><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Plus récentes', 'Plus récentes', 'partikulier' ) : __( 'Plus récentes', 'partikulier' ) ); ?></option>
                                                        <option value="price-asc"<?php selected( 'price-asc' === ( $_GET['pk_order'] ?? '' ) ); ?>><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Prix croissant', 'Prix croissant', 'partikulier' ) : __( 'Prix croissant', 'partikulier' ) ); ?></option>
                                                        <option value="price-desc"<?php selected( 'price-desc' === ( $_GET['pk_order'] ?? '' ) ); ?>><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Prix décroissant', 'Prix décroissant', 'partikulier' ) : __( 'Prix décroissant', 'partikulier' ) ); ?></option>
                                                        <option value="surface-desc"<?php selected( 'surface-desc' === ( $_GET['pk_order'] ?? '' ) ); ?>><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Surface décroissante', 'Surface décroissante', 'partikulier' ) : __( 'Surface décroissante', 'partikulier' ) ); ?></option>
                                        </select>
                                </form>
                        </div>
                </header>

                <div class="pk-archive-search">
                        <p class="pk-archive-subtitle"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Des biens publiés directement par leurs propriétaires.', 'Des biens publiés directement par leurs propriétaires.', 'partikulier' ) ); ?></p>
                        <?php $variant = 'archive'; require PARTIKULIER_DIR . '/templates/parts/search-form.php'; ?>
                        <ul class="pk-archive-trust"><li><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Localisation d’abord', 'Localisation d’abord', 'partikulier' ) ); ?></li><li><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Vendeur identifié', 'Vendeur identifié', 'partikulier' ) ); ?></li><li><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Contact direct', 'Contact direct', 'partikulier' ) ); ?></li></ul>
                </div>


                        <?php
                        $pk_active_filters = 0;
                        foreach ( array( 'es_action', 'es_type', 'es_city', 'es_price_max' ) as $pk_filter_key ) {
                                if ( isset( $_GET[ $pk_filter_key ] ) && '' !== trim( (string) wp_unslash( $_GET[ $pk_filter_key ] ) ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                                        $pk_active_filters++;
                                }
                        }
                        $pk_filters_label = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_polylang_string( 'Filtres', 'Filtres', 'partikulier' ) : __( 'Filtres', 'partikulier' );
                        ?>
                                        <div class="pk-archive-layout">
                                                        <button type="button" class="pk-filter-toggle" aria-expanded="<?php echo $pk_active_filters ? 'true' : 'false'; ?>" aria-controls="pk-filters-panel">
                                                                <span class="pk-filter-toggle-label"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Affiner la recherche', 'Affiner la recherche', 'partikulier' ) ); ?></span>
                                                                <span class="pk-filter-toggle-meta"><span class="pk-filter-count"><?php echo esc_html( $pk_active_filters ); ?></span> <?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Filtres actifs', 'Filtres actifs', 'partikulier' ) ); ?></span>
                                                                <span class="pk-filter-toggle-icon" aria-hidden="true">+</span>
                                                        </button>
                                                        <aside class="pk-filters" aria-label="<?php echo esc_attr( $pk_filters_label ); ?>">
                                                        <div class="pk-filters-heading">
                                                                <h2><?php echo esc_html( $pk_filters_label ); ?></h2>
                                                                <span><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Affiner la recherche', 'Affiner la recherche', 'partikulier' ) ); ?></span>
                                                        </div>
                                        <div class="pk-filters-backdrop" data-pk-filter-close="true" hidden></div>
                                                <div class="pk-filters-panel<?php echo $pk_active_filters ? ' ' . 'is-open' : ''; ?>" id="pk-filters-panel" aria-hidden="<?php echo $pk_active_filters ? 'false' : 'true'; ?>">
                                                <button type="button" class="pk-filter-close" data-pk-filter-close="true" aria-label="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Fermer', 'Fermer', 'partikulier' ) ); ?>"><span aria-hidden="true">×</span><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Fermer', 'Fermer', 'partikulier' ) ); ?></button>
<h2 class="screen-reader-text"><?php echo esc_html( $pk_filters_label ); ?></h2>
                                                        <?php $pk_reset_url = pk_properties_archive_url(); ?>
                                                        <a class="pk-filter-reset" href="<?php echo esc_url( $pk_reset_url ); ?>"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Réinitialiser', 'Réinitialiser', 'partikulier' ) ); ?></a>

                                                        <div class="pk-filter pk-filter-actions">
                                                        <h3 class="pk-filter-title"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Transaction', 'Transaction', 'partikulier' ) ); ?></h3>
                                                        <ul class="pk-filter-list pk-filter-action-list">
                                                                <?php
                                                                $action_items = array(
                                                                        array( 'value' => '', 'label' => 'Tout' ),
                                                                        array( 'value' => 'a-vendre', 'label' => 'Vendre' ),
                                                                        array( 'value' => 'a-louer', 'label' => 'Louer' ),
                                                                );
                                                                $pk_cumulative_args = array();
                                                                foreach ( array( 'es_action', 'es_type', 'es_city', 'es_price_max', 'pk_order' ) as $pk_query_key ) {
                                                                        if ( isset( $_GET[ $pk_query_key ] ) && ! is_array( $_GET[ $pk_query_key ] ) && '' !== (string) $_GET[ $pk_query_key ] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                                                                                $pk_cumulative_args[ $pk_query_key ] = sanitize_text_field( wp_unslash( $_GET[ $pk_query_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
                                                                        }
                                                                }
                                                                foreach ( $action_items as $action_item ) :
                                                                        $action_args = $pk_cumulative_args;
                                                                        if ( $action_item['value'] ) {
                                                                                $action_args['es_action'] = $action_item['value'];
                                                                        } else {
                                                                                unset( $action_args['es_action'] );
                                                                        }
                                                                        $action_url = add_query_arg( $action_args, pk_properties_archive_url() );
                                                                        /* 6.17.30 : laisser passer gettext — l'appel direct
                                                                         * translate_polylang_string( $label, $label ) court-circuitait
                                                                         * le catalogue .mo (Vendre → بيع, Louer → كراء). */
                                                                        $action_label = __( $action_item['label'], 'partikulier' );
                                                                        ?>
                                                                                <li><a href="<?php echo esc_url( $action_url ); ?>"><?php echo esc_html( $action_label ); // nosemgrep: php.lang.security.injection.echoed-request.echoed-request ?></a></li>
                                                                <?php endforeach; ?>
                                                        </ul>
                                        </div>

                                        <div class="pk-filter pk-filter-types">
                                                <h3 class="pk-filter-title"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Types de biens', 'Types de biens', 'partikulier' ) ); ?></h3>
                                        <?php
                                        $types = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY, 'hide_empty' => true ) );
                                        if ( $types && ! is_wp_error( $types ) ) {
                                                echo '<ul class="pk-filter-list">';
                                                foreach ( $types as $term ) {
                                                        $active = $queried instanceof WP_Term && $queried->term_id === $term->term_id ? ' aria-current="true"' : '';
                                                                $type_args = isset( $pk_cumulative_args ) ? $pk_cumulative_args : array();
                                                                $type_args['es_type'] = $term->slug;
                                                                printf(
                                                                        '<li><a href="%1$s"%3$s>%2$s <span class="pk-filter-count">(%4$s)</span></a></li>',
                                                                        esc_url( add_query_arg( $type_args, pk_properties_archive_url() ) ),
                                                                        esc_html( Partikulier_Localization::translate_taxonomy_label( $term->name ) ),
                                                                        $active,
                                                                        esc_html( number_format_i18n( $term->count ) )
                                                                );
                                                }
                                                echo '</ul>';
                                        }
                                        ?>
                                </div>

                                                        <?php
                                                        $pk_filter_city_slug = isset( $_GET['es_city'] ) ? sanitize_title( wp_unslash( $_GET['es_city'] ) ) : '';
                                                        $pk_filter_city_term = $pk_filter_city_slug ? get_term_by( 'slug', $pk_filter_city_slug, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) : false;
                                                        $pk_filter_city_label = ( $pk_filter_city_term && ! is_wp_error( $pk_filter_city_term ) ) ? $pk_filter_city_term->name : '';
                                                        ?>
                                                        <div class="pk-filter pk-filter-city">
                                                                <h3 class="pk-filter-title"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Ville', 'Ville', 'partikulier' ) ); ?></h3>
                                                        <form action="<?php echo esc_url( pk_properties_archive_url() ); ?>" method="get" class="pk-filter-city-form pk-place-autocomplete">
                                                                <label class="screen-reader-text" for="pk-filter-city-input"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Toutes les villes', 'Toutes les villes', 'partikulier' ) ); ?></label>
                                                                <?php foreach ( array( 'es_action', 'es_type', 'es_price_max', 'pk_order' ) as $pk_preserve_key ) : ?>
                                                                        <?php if ( isset( $_GET[ $pk_preserve_key ] ) && ! is_array( $_GET[ $pk_preserve_key ] ) ) : ?>
                                                                                <input type="hidden" name="<?php echo esc_attr( $pk_preserve_key ); ?>" value="<?php echo esc_attr( sanitize_text_field( wp_unslash( $_GET[ $pk_preserve_key ] ) ) ); ?>">
                                                                        <?php endif; ?>
                                                                <?php endforeach; ?>
                                                                <div class="pk-place-autocomplete-wrap">
                                                                        <?php /* name="s" : secours sans JavaScript, desactive par le JS quand es_city est fixe. */ ?>
                                                                        <input id="pk-filter-city-input" name="s" class="pk-place-input" type="search" data-pk-place-input="true" data-pk-place-value="pk-filter-city-value" autocomplete="off" aria-controls="pk-filter-city-suggestions" aria-autocomplete="list" placeholder="<?php echo esc_attr( Partikulier_Localization::translate_polylang_string( 'Toutes les villes', 'Toutes les villes', 'partikulier' ) ); ?>" value="<?php echo esc_attr( $pk_filter_city_label ); ?>">
                                                                                <input type="hidden" name="es_city" id="pk-filter-city-value" value="<?php echo esc_attr( $pk_filter_city_slug ); ?>">
                                                                        <ul id="pk-filter-city-suggestions" class="pk-suggest pk-place-suggestions" role="listbox" hidden></ul>
                                                                </div>
                                                                <button type="submit" class="pk-filter-apply"><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Appliquer', 'Appliquer', 'partikulier' ) ); ?></button>
                                                        </form>
                                                        </div>
                                                        <script>
                                                        (function () {
                                                                var toggle = document.querySelector('.pk-filter-toggle');
                                                                var panel = document.getElementById('pk-filters-panel');
                                                                var backdrop = document.querySelector('.pk-filters-backdrop');
                                                                if (!toggle || !panel) return;
                                                                function isMobile() { return window.matchMedia && window.matchMedia('(max-width: 767px)').matches; }
                                                                function apply(open) {
                                                                        var mobile = isMobile();
                                                                        var visible = mobile ? !!open : true;
                                                                        panel.classList.toggle('is-open', mobile && visible);
                                                                        panel.setAttribute('aria-hidden', visible ? 'false' : 'true');
                                                                        if ('inert' in panel) panel.inert = mobile && !visible;
                                                                        else if (mobile && !visible) panel.setAttribute('inert', '');
                                                                        else panel.removeAttribute('inert');
                                                                        toggle.setAttribute('aria-expanded', mobile && visible ? 'true' : 'false');
                                                                        if (backdrop) { backdrop.hidden = !visible; backdrop.classList.toggle('is-open', mobile && visible); }
                                                                        document.body.classList.toggle('pk-filters-open', mobile && visible);
                                                                        if (mobile && visible) { var close = panel.querySelector('.pk-filter-close'); if (close) close.focus(); }
                                                                        if (mobile && !visible) toggle.focus();
                                                                }
                                                                apply(isMobile() && panel.classList.contains('is-open'));
                                                                window.pkEarlyFilterApply = apply;
                                                                window.pkEarlyFilterClick = function (event) {
                                                                        var target = event.target.closest ? event.target.closest('.pk-filter-toggle, [data-pk-filter-close]') : null;
                                                                        if (!target) return;
                                                                        event.preventDefault();
                                                                        apply(target.matches('.pk-filter-toggle') ? !panel.classList.contains('is-open') : false);
                                                                };
                                                                window.pkEarlyFilterKeydown = function (event) {
                                                                        if (event.key === 'Escape' && panel.classList.contains('is-open')) { event.preventDefault(); apply(false); }
                                                                };
                                                                document.addEventListener('click', window.pkEarlyFilterClick, true);
                                                                document.addEventListener('keydown', window.pkEarlyFilterKeydown, true);
                                                        }());
                                                        </script>
                                                        </div>
                                </aside>

                                <div class="pk-archive-content">
                                <?php if ( have_posts() ) : ?>
                                        <div class="pk-grid pk-grid-cards">
                                                <?php
                                                $pk_card_index = 0;
                                                while ( have_posts() ) :
                                                        the_post();
                                                        $pk_card_index++;
                                                        $property = get_post();
                                                        require PARTIKULIER_DIR . '/templates/parts/card-property.php';
                                                endwhile;
                                                ?>
                                        </div>
                                        <?php pk_pagination(); ?>
                                <?php else : ?>
                                        <div class="pk-empty">
<h2><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Aucune annonce ne correspond à votre recherche.', 'Aucune annonce ne correspond à votre recherche.', 'partikulier' ) ); ?></h2>
                                                        <p><?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Essayez d\'élargir vos critères ou consultez les villes populaires.', 'Essayez d\'élargir vos critères ou consultez les villes populaires.', 'partikulier' ) ); ?></p>
                                                                <a class="pk-btn pk-btn-outline" href="<?php echo esc_url( pk_properties_archive_url() ); ?>">
                                                                <?php echo esc_html( Partikulier_Localization::translate_polylang_string( 'Voir toutes les annonces', 'Voir toutes les annonces', 'partikulier' ) ); ?>
                                                        </a>
                                        </div>
                                <?php endif; ?>
                        </div>
                </div>
        </div>
</section>

<?php
get_footer();