<?php
/**
 * Formulaire de recherche immobilier (barre hero).
 *
 * Strategie : POST natif vers l'archive estate_property avec les query vars
 * qu'Estatik comprend (es_action, es_type, es_city, es_price_min, es_price_max, es_size_min).
 * Si le shortcode de recherche Estatik est disponible, on l'utilise ; sinon notre barre native.
 *
 * @package Partikulier
 * @var string $variant 'hero'|'compact'
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

$variant  = isset( $variant ) ? $variant : 'hero';
$archive  = pk_properties_archive_url();
if ( ! $archive ) {
        $archive = function_exists( 'pk_localized_home_url' ) ? pk_localized_home_url() : home_url( '/' );
}

// Options pour les selects.
$types   = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY, 'hide_empty' => true ) );
$actions = array(
        array( 'slug' => 'a-vendre', 'label' => 'Vendre' ),
        array( 'slug' => 'a-louer', 'label' => 'Louer' ),
);
$cities  = get_terms( array( 'taxonomy' => PARTIKULIER_ESTATIK_LOCATION_TAXONOMY, 'hide_empty' => true, 'orderby' => 'count', 'order' => 'DESC', 'number' => 40 ) );
$types   = is_wp_error( $types ) ? array() : $types;
$cities  = is_wp_error( $cities ) ? array() : $cities;
$selected_action     = isset( $_GET['es_action'] ) ? sanitize_text_field( wp_unslash( $_GET['es_action'] ) ) : '';
$selected_type       = isset( $_GET['es_type'] ) ? sanitize_text_field( wp_unslash( $_GET['es_type'] ) ) : '';
$selected_price_max  = isset( $_GET['es_price_max'] ) ? sanitize_text_field( wp_unslash( $_GET['es_price_max'] ) ) : '';
$selected_city_slug  = isset( $_GET['es_city'] ) ? sanitize_title( wp_unslash( $_GET['es_city'] ) ) : '';
$selected_city_term  = $selected_city_slug ? get_term_by( 'slug', $selected_city_slug, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) : false;
$selected_city_label = ( $selected_city_term && ! is_wp_error( $selected_city_term ) ) ? $selected_city_term->name : '';
?>
<form class="pk-search pk-search-<?php echo esc_attr( $variant ); ?>" action="<?php echo esc_url( $archive ); ?>" method="get" role="search" aria-label="<?php esc_attr_e( 'Rechercher un bien immobilier', 'partikulier' ); ?>">
        <div class="pk-search-field pk-search-type">
                <label class="pk-search-label" for="pk-s-action"><?php esc_html_e( 'Achat ou location', 'partikulier' ); ?></label>
<select name="es_action" id="pk-s-action">
                                <option value=""><?php echo esc_html__( 'Tout', 'partikulier' ); ?></option>
                                        <?php foreach ( (array) $actions as $action_item ) : ?>
                                                <option value="<?php echo esc_attr( $action_item['slug'] ); ?>"<?php selected( $selected_action, $action_item['slug'] ); ?>><?php echo esc_html__( $action_item['label'], 'partikulier' ); ?></option>
                                <?php endforeach; ?>
                        </select>
                </div>

                <div class="pk-search-field pk-search-type">
                        <label class="pk-search-label" for="pk-s-type"><?php esc_html_e( 'Type de bien', 'partikulier' ); ?></label>
                <select name="es_type" id="pk-s-type">
                        <option value=""><?php echo esc_html__( 'Tous', 'partikulier' ); ?></option>
                                <?php foreach ( (array) $types as $term ) : ?>
                                        <?php if ( ! $term instanceof WP_Term ) { continue; } ?>
<option value="<?php echo esc_attr( $term->slug ); ?>"<?php selected( $selected_type, $term->slug ); ?>><?php echo esc_html( class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::translate_taxonomy_label( $term->name ) : $term->name ); ?></option>
                                <?php endforeach; ?>
                        </select>
                </div>

                        <div class="pk-search-field pk-search-city pk-place-autocomplete">
                        <label class="pk-search-label" for="pk-s-city-input"><?php esc_html_e( 'Ville', 'partikulier' ); ?></label>
                        <div class="pk-place-autocomplete-wrap">
                                <?php /* name="s" : sans JavaScript, le texte tape part au serveur, qui le traduit
                                   en terme es_location (class-search-filters) ou retombe sur la recherche
                                   plein texte. Avec JavaScript, le champ est desactive a la soumission des
                                   que l'autocompletion a fixe es_city : s ne part jamais en double. */ ?>
                                <input type="search" name="s" id="pk-s-city-input" class="pk-place-input" value="<?php echo esc_attr( $selected_city_label ); ?>" placeholder="<?php echo esc_attr__( 'Toutes les villes', 'partikulier' ); ?>" autocomplete="off" data-pk-place-input="true" aria-controls="pk-s-city-suggestions" aria-autocomplete="list">
                                <input type="hidden" name="es_city" id="pk-s-city-value" value="<?php echo esc_attr( $selected_city_slug ); ?>">
                                <ul id="pk-s-city-suggestions" class="pk-suggest pk-place-suggestions" role="listbox" hidden></ul>
                        </div>
                </div>

        <div class="pk-search-field pk-search-budget">
                <label class="pk-search-label" for="pk-s-budget"><?php esc_html_e( 'Budget max', 'partikulier' ); ?></label>
                <select name="es_price_max" id="pk-s-budget">
                        <option value=""><?php echo esc_html__( 'Illimité', 'partikulier' ); ?></option>
                                <?php foreach ( array( 100000, 200000, 300000, 400000, 500000, 750000, 1000000, 1500000 ) as $b ) : ?>
                                        <option value="<?php echo esc_attr( $b ); ?>"<?php selected( $selected_price_max, (string) $b ); ?>><?php echo esc_html( number_format_i18n( $b ) ) . ' MAD'; ?></option>
                                <?php endforeach; ?>
                </select>
        </div>

        <div class="pk-search-action">
                <button type="submit" class="pk-btn pk-btn-search">
                        <?php esc_html_e( 'Rechercher', 'partikulier' ); ?>
                </button>
        </div>
</form>