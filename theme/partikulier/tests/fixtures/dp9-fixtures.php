<?php
/**
 * SE-044 / DP-9 — fixtures partagées des suites dp9 (100 % fictives).
 *
 * Crée l'univers de test du contrat DP-9 (v1.1 §3.1 + R1) : propriétaire non
 * admin, échiquier matriciel complet, paires source/variante liées (registre
 * métier + Polylang), pages « Mes annonces » ×3 langues. Idempotent : purge
 * puis re-crée. Appelé par dp9-availability-contract (processus in-process),
 * dp9-owner-journey-contract, dp9-variant-resolution-contract et
 * dp9-partial-failure-contract au début de leur exécution — chaque suite
 * repart ainsi d'un état déterministe, même après l'échec de la précédente.
 *
 * N'est JAMAIS empaqueté (scripts/package.sh exclut tests/ des artefacts).
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit( 'CLI only' );
}

/**
 * Semis idempotent des fixtures DP-9.
 *
 * @return array{owner:int, other:int, fixtures:array<string,int>, pages:array<string,int>}
 */
function dp9_seed_fixtures(): array {
        global $wpdb;

        /* ---------- Purge (idempotence du semis) ---------- */

        foreach ( (array) $wpdb->get_col( "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'properties'" ) as $pid ) {
                wp_delete_post( (int) $pid, true );
        }
        $wpdb->query( "DELETE FROM {$wpdb->prefix}pk_property_variants" );
        foreach ( array( 'dp9_owner', 'dp9_autre' ) as $login ) {
                $u = get_user_by( 'login', $login );
                if ( $u ) {
                        wp_delete_user( $u->ID );
                }
        }

        /* ---------- Utilisateurs fictifs (rôle propriétaire, PAS manage_options) ---------- */

        $owner_id  = (int) wp_create_user( 'dp9_owner', 'dp9-owner-fictif-' . gmdate( 'Ymd' ), 'dp9-owner@example.test' );
        $other_id  = (int) wp_create_user( 'dp9_autre', 'dp9-autre-fictif-' . gmdate( 'Ymd' ), 'dp9-autre@example.test' );
        foreach ( array( $owner_id, $other_id ) as $uid ) {
                $u = get_user_by( 'id', $uid );
                $u->set_role( 'subscriber' );
                $u->add_cap( 'pk_property_owner' );
        }

        /* ---------- Fabrique d'annonce ---------- */

        $seq = 0;
        $mk  = static function ( $author, $title, $post_status, $pk_status, $extra = array() ) use ( &$seq ) {
                $seq++;
                $id = wp_insert_post( array(
                        'post_type'    => 'properties',
                        'post_status'  => $post_status,
                        'post_title'   => $title,
                        'post_content' => 'FICTIF DP9 — contenu de test n°' . $seq,
                        'post_author'  => $author,
                ), true );
                if ( is_wp_error( $id ) ) {
                        throw new RuntimeException( 'dp9-fixtures insert: ' . $id->get_error_message() );
                }
                update_post_meta( $id, 'es_property_price', 100000 + $seq );
                update_post_meta( $id, 'es_property_area', 50 + $seq );
                if ( null !== $pk_status ) {
                        update_post_meta( $id, '_pk_status', $pk_status );
                }
                if ( ! empty( $extra['reason'] ) ) {
                        update_post_meta( $id, '_pk_closed_reason', $extra['reason'] );
                }
                if ( ! empty( $extra['note'] ) ) {
                        update_post_meta( $id, '_pk_closed_note', $extra['note'] );
                }
                if ( ! empty( $extra['closed_at'] ) ) {
                        update_post_meta( $id, '_pk_closed_at', $extra['closed_at'] );
                }
                if ( function_exists( 'pll_set_post_language' ) ) {
                        pll_set_post_language( $id, $extra['lang'] ?? 'fr' );
                }
                return (int) $id;
        };

        /* ---------- Échiquier matriciel (v1.1 §3.1 + R1 §3.2-1) ---------- */

        $f = array();
        $f['A1_absent']       = $mk( $owner_id, 'DP9 A1 publish sans méta', 'publish', null );
        $f['A2_vide']         = $mk( $owner_id, 'DP9 A2 publish méta vide', 'publish', '' );
        $f['A3_actif']        = $mk( $owner_id, 'DP9 A3 publish actif', 'publish', 'actif' );
        $f['V1_vendu']        = $mk( $owner_id, 'DP9 V1 publish vendu', 'publish', 'vendu', array( 'reason' => 'vendu', 'closed_at' => '2026-09-01 10:00:00' ) );
        $f['L1_loue']         = $mk( $owner_id, 'DP9 L1 publish loue', 'publish', 'loue', array( 'reason' => 'loue', 'closed_at' => '2026-09-01 10:00:00' ) );
        $f['I1_indisponible'] = $mk( $owner_id, 'DP9 I1 publish indisponible', 'publish', 'indisponible', array( 'reason' => 'avis', 'closed_at' => '2026-09-01 10:00:00' ) );
        $f['AR1_archive']     = $mk( $owner_id, 'DP9 AR1 publish archive historique', 'publish', 'archive', array( 'reason' => 'archive', 'closed_at' => '2026-09-01 10:00:00' ) );
        $f['P1_pause']        = $mk( $owner_id, 'DP9 P1 draft pause historique', 'draft', 'pause' );
        $f['D1_draft_actif']  = $mk( $owner_id, 'DP9 D1 draft actif dépublié équipe', 'draft', 'actif' );
        $f['W1_attente']      = $mk( $owner_id, 'DP9 W1 pending en_attente_whatsapp', 'pending', 'en_attente_whatsapp' );
        $f['R1_refuse']       = $mk( $owner_id, 'DP9 R1 draft refuse', 'draft', 'refuse' );
        $f['U1_inconnu']      = $mk( $owner_id, 'DP9 U1 publish valeur inconnue', 'publish', 'bizarre_inconnu' );
        $f['O1_autrui']       = $mk( $other_id, 'DP9 O1 annonce d’autrui', 'publish', 'actif' );
        $f['T1_corbeille']    = $mk( $owner_id, 'DP9 T1 corbeille', 'trash', 'actif' );

        /* ---------- Paires source/variante (registre métier + Polylang) ---------- */

        // S1/E1 : paire saine (source fr actif, variante en actif).
        $f['S1_source']        = $mk( $owner_id, 'DP9 S1 source fr', 'publish', 'actif' );
        $f['E1_variante']      = $mk( $owner_id, 'DP9 E1 variante en', 'publish', 'actif', array( 'lang' => 'en' ) );

        // S2/E2 : variante porteuse du marqueur refuse (R1 §2.3-R1).
        $f['S2_source']        = $mk( $owner_id, 'DP9 S2 source fr', 'publish', 'actif' );
        $f['E2_variante_refuse'] = $mk( $owner_id, 'DP9 E2 variante en refuse', 'publish', 'refuse', array( 'lang' => 'en' ) );

        // S3/E3 : paire fermée (vendu des deux côtés) — réactivation de groupe.
        $f['S3_source']        = $mk( $owner_id, 'DP9 S3 source fr vendu', 'publish', 'vendu', array( 'reason' => 'vendu', 'closed_at' => '2026-09-01 10:00:00' ) );
        $f['E3_variante']      = $mk( $owner_id, 'DP9 E3 variante en vendu', 'publish', 'vendu', array( 'reason' => 'vendu', 'closed_at' => '2026-09-01 10:00:00', 'lang' => 'en' ) );

        foreach ( array(
                array( $f['S1_source'], $f['E1_variante'] ),
                array( $f['S2_source'], $f['E2_variante_refuse'] ),
                array( $f['S3_source'], $f['E3_variante'] ),
        ) as $paire ) {
                \Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService::link_variant( (int) $paire[0], (int) $paire[1], 'en', 'fr' );
                if ( function_exists( 'pll_save_post_translations' ) ) {
                        pll_save_post_translations( array( 'fr' => (int) $paire[0], 'en' => (int) $paire[1] ) );
                }
        }

        /* ---------- Ville partagée (surface « similaires » de l'équivalence T28) ---------- */

        $ville = term_exists( 'dp9-ville', 'es_location' );
        if ( ! is_array( $ville ) ) {
                $ville = wp_insert_term( 'DP9 Ville fictive', 'es_location', array( 'slug' => 'dp9-ville' ) );
        }
        $ville_id = is_array( $ville ) ? (int) ( $ville['term_id'] ?? 0 ) : 0;
        if ( $ville_id ) {
                foreach ( array( $f['A1_absent'], $f['A2_vide'], $f['A3_actif'], $f['V1_vendu'] ) as $pid ) {
                        wp_set_object_terms( $pid, array( $ville_id ), 'es_location', true );
                }
        }

        /* ---------- Annonce approuvée < 72 h (rattrapage n8n, T34 — v1.1 §7) ---------- */

        update_post_meta( $f['A3_actif'], '_pk_approved_at', gmdate( 'Y-m-d H:i:s', time() - 3600 ) );

        /* ---------- Pages « Mes annonces » ×3 langues (T36 UI) ---------- */

        $pages = array();
        $specs = array(
                'fr' => array( 'slug' => 'mes-annonces', 'title' => 'Mes annonces (DP9)' ),
                'en' => array( 'slug' => 'my-listings', 'title' => 'My listings (DP9)' ),
                'ar' => array( 'slug' => 'ar-listings', 'title' => 'إعلاناتي (DP9)' ),
        );
        foreach ( $specs as $lang => $spec ) {
                $exist = get_page_by_path( $spec['slug'] );
                if ( $exist ) {
                        wp_delete_post( $exist->ID, true );
                }
                $pid = wp_insert_post( array(
                        'post_type'    => 'page',
                        'post_status'  => 'publish',
                        'post_title'   => $spec['title'],
                        'post_name'    => $spec['slug'],
                        'post_content' => 'FICTIF DP9 — page de test',
                        'post_author'  => $owner_id,
                ), true );
                if ( is_wp_error( $pid ) ) {
                        throw new RuntimeException( 'dp9-fixtures page: ' . $pid->get_error_message() );
                }
                update_post_meta( $pid, '_wp_page_template', 'templates/page-mes-annonces.php' );
                if ( function_exists( 'pll_set_post_language' ) ) {
                        pll_set_post_language( $pid, $lang );
                }
                $pages[ $lang ] = (int) $pid;
        }
        if ( function_exists( 'pll_save_post_translations' ) && count( $pages ) === 3 ) {
                pll_save_post_translations( $pages );
        }

        /* ---------- Projection + invalidation ---------- */

        ( new \Partikulier\Core\Integration\ListingSynchronizer() )->flush();
        \Partikulier\Core\Integration\ListingSynchronizer::invalidate_listing_search_cache_now();
        if ( function_exists( 'wp_cache_flush' ) ) {
                wp_cache_flush();
        }
        // Reset du cache MÉMOIRE Polylang (PLL_Cache, par processus) : sans lui, la
        // première lecture « post → langue » faite pendant la création (avant
        // pll_set_post_language) resterait gelée pour tout le processus de la suite,
        // et get_permalink rendrait des URLs sans préfixe de langue.
        if ( isset( $GLOBALS['polylang'] ) && is_object( $GLOBALS['polylang'] )
                && isset( $GLOBALS['polylang']->model ) && is_object( $GLOBALS['polylang']->model )
                && property_exists( $GLOBALS['polylang']->model, 'cache' )
                && method_exists( $GLOBALS['polylang']->model->cache, 'clean' ) ) {
                $GLOBALS['polylang']->model->cache->clean();
        }
        flush_rewrite_rules( true );

        return array(
                'owner'    => $owner_id,
                'other'    => $other_id,
                'fixtures' => $f,
                'pages'    => $pages,
                'ville'    => $ville_id,
        );
}
