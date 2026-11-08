<?php
/**
 * Run-scoped UAT prompt fixture. Invoke only through protected SSH/WP-CLI.
 * It creates one contributor and one published, run-marked properties post,
 * then deletes only those exact objects. No public deposit form is submitted.
 */
if ( PHP_SAPI !== 'cli' || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
    fwrite( STDERR, "CLI only\n" );
    exit( 2 );
}
require_once ABSPATH . 'wp-admin/includes/user.php';

$mode       = (string) ( getenv( 'PK_PROMPT_MODE' ) ?: ( $argv[1] ?? '' ) );
$run        = (string) ( getenv( 'PK_PROMPT_RUN' ) ?: '' );
$login      = (string) ( getenv( 'PK_PROMPT_LOGIN' ) ?: '' );
$email      = (string) ( getenv( 'PK_PROMPT_EMAIL' ) ?: '' );
$name       = (string) ( getenv( 'PK_PROMPT_NAME' ) ?: '' );
$marker     = (string) ( getenv( 'PK_PROMPT_MARKER' ) ?: '' );
$password   = (string) ( getenv( 'PK_PROMPT_PASSWORD' ) ?: '' );
$listing_id = absint( getenv( 'PK_PROMPT_LISTING_ID' ) ?: 0 );

$expected_host = 'darkcyan-yak-151002.hostingersite.com';
$home_host     = strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
$site_host     = strtolower( (string) wp_parse_url( site_url( '/' ), PHP_URL_HOST ) );
if ( $home_host !== $expected_host || $site_host !== $expected_host ) {
    fwrite( STDERR, "Target guard rejected: WordPress is not the authorized UAT host.\n" );
    exit( 3 );
}
if ( ! preg_match( '/^[0-9]{6,20}-[0-9]{1,4}$/', $run )
    || ! preg_match( '/^pk_prompt_[0-9]{6,20}_[0-9]{1,4}$/', $login )
    || ! preg_match( '/^[a-f0-9]{32,64}$/', $marker ) ) {
    fwrite( STDERR, "Invalid run-scoped fixture identifiers.\n" );
    exit( 4 );
}
if ( ! defined( 'PARTIKULIER_ESTATIK_POST_TYPE' ) || ! PARTIKULIER_ESTATIK_POST_TYPE ) {
    fwrite( STDERR, "The listing post type is unavailable.\n" );
    exit( 5 );
}

$user_marker    = '_pk_uat_prompt_run';
$listing_marker = '_pk_uat_prompt_run';
$title          = 'UAT-PROMPT-' . $run;

if ( 'seed' === $mode ) {
    if ( ! filter_var( $email, FILTER_VALIDATE_EMAIL ) || ! str_ends_with( strtolower( $email ), '@example.invalid' )
        || '' === $name || '' === $password ) {
        fwrite( STDERR, "Seed inputs are incomplete or outside the invalid-email test namespace.\n" );
        exit( 6 );
    }
    if ( username_exists( $login ) || email_exists( $email ) ) {
        fwrite( STDERR, "Run-scoped account already exists; refusing to touch it.\n" );
        exit( 7 );
    }
    $already = get_posts( array(
        'post_type'      => PARTIKULIER_ESTATIK_POST_TYPE,
        'post_status'    => array_values( get_post_stati() ),
        'numberposts'    => 2,
        'meta_key'       => $listing_marker,
        'meta_value'     => $marker,
        'suppress_filters' => true,
    ) );
    if ( $already ) {
        fwrite( STDERR, "Run-scoped listing marker already exists; refusing to touch it.\n" );
        exit( 8 );
    }

    $user_id = wp_insert_user( array(
        'user_login'   => $login,
        'user_email'   => $email,
        'user_pass'    => $password,
        'display_name' => $name,
        'first_name'   => $name,
        'role'         => 'author',
    ) );
    if ( is_wp_error( $user_id ) || ! $user_id ) {
        fwrite( STDERR, "Account creation failed.\n" );
        exit( 9 );
    }
    update_user_meta( $user_id, $user_marker, $marker );
    if ( (string) get_user_meta( $user_id, $user_marker, true ) !== $marker ) {
        wp_delete_user( $user_id );
        fwrite( STDERR, "Account marker verification failed; account rolled back.\n" );
        exit( 10 );
    }

    $post_id = wp_insert_post( array(
        'post_type'    => PARTIKULIER_ESTATIK_POST_TYPE,
        'post_status'  => 'publish',
        'post_author'  => (int) $user_id,
        'post_title'   => $title,
        'post_content' => 'Temporary UAT validation fixture. Not submitted through the public deposit form.',
    ), true );
    if ( is_wp_error( $post_id ) || ! $post_id ) {
        wp_delete_user( $user_id );
        fwrite( STDERR, "Temporary listing creation failed; account rolled back.\n" );
        exit( 11 );
    }

    $meta = array(
        $listing_marker        => $marker,
        '_pk_status'           => 'actif',
        '_pk_owner_name'       => $name,
        '_pk_city_name'        => 'Casablanca',
        '_pk_district_name'    => 'UAT temporary fixture',
        'es_property_price'    => 1,
        'es_property_area'     => 1,
    );
    foreach ( $meta as $key => $value ) {
        update_post_meta( $post_id, $key, $value );
        if ( (string) get_post_meta( $post_id, $key, true ) !== (string) $value ) {
            wp_delete_post( $post_id, true );
            wp_delete_user( $user_id );
            fwrite( STDERR, "Temporary listing metadata verification failed; fixtures rolled back.\n" );
            exit( 12 );
        }
    }
    if ( (int) get_post_field( 'post_author', $post_id ) !== (int) $user_id
        || get_post_status( $post_id ) !== 'publish'
        || get_post_type( $post_id ) !== PARTIKULIER_ESTATIK_POST_TYPE ) {
        wp_delete_post( $post_id, true );
        wp_delete_user( $user_id );
        fwrite( STDERR, "Listing ownership/type/status verification failed; fixtures rolled back.\n" );
        exit( 13 );
    }
    echo wp_json_encode( array(
        'user_id'       => (int) $user_id,
        'display_name'  => $name,
        'listing_id'    => (int) $post_id,
        'listing_title' => $title,
        'listing_url'   => get_permalink( $post_id ),
        'status'        => 'published temporary fixture; no public form submitted',
    ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
    exit( 0 );
}

if ( 'cleanup' === $mode ) {
    $user = get_user_by( 'login', $login );
    if ( $user ) {
        $stored_marker = (string) get_user_meta( $user->ID, $user_marker, true );
        $role_ok       = in_array( 'author', (array) $user->roles, true );
        if ( $user->user_email !== $email || ! $role_ok || ( '' !== $stored_marker && $stored_marker !== $marker ) ) {
            fwrite( STDERR, "Account identity/role/marker mismatch; refusing deletion.\n" );
            exit( 20 );
        }
    }
    $matches = get_posts( array(
        'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
        'post_status'      => array_values( get_post_stati() ),
        'numberposts'      => 3,
        'meta_key'         => $listing_marker,
        'meta_value'       => $marker,
        'suppress_filters' => true,
    ) );
    // Recover a partially seeded post if the process stopped after wp_insert_post
    // but before writing its marker. Exact run title + unique author are required.
    if ( $user ) {
        $author_posts = get_posts( array(
            'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
            'post_status'      => array_values( get_post_stati() ),
            'numberposts'      => -1,
            'author'           => (int) $user->ID,
            'suppress_filters' => true,
        ) );
        foreach ( $author_posts as $candidate ) {
            if ( $candidate->post_title === $title ) {
                $candidate_marker = (string) get_post_meta( $candidate->ID, $listing_marker, true );
                if ( ! in_array( $candidate_marker, array( '', $marker ), true ) ) {
                    fwrite( STDERR, "Partial listing marker mismatch; refusing deletion.\n" );
                    exit( 22 );
                }
                if ( ! in_array( (int) $candidate->ID, array_map( 'intval', wp_list_pluck( $matches, 'ID' ) ), true ) ) {
                    $matches[] = $candidate;
                }
            }
        }
    } elseif ( $listing_id ) {
        $candidate = get_post( $listing_id );
        if ( $candidate && $candidate->post_type === PARTIKULIER_ESTATIK_POST_TYPE && $candidate->post_title === $title ) {
            $candidate_marker = (string) get_post_meta( $candidate->ID, $listing_marker, true );
            if ( in_array( $candidate_marker, array( '', $marker ), true ) && ! $matches ) {
                $matches[] = $candidate;
            }
        }
    }
    if ( count( $matches ) > 1 ) {
        fwrite( STDERR, "More than one listing carries this run marker; refusing deletion.\n" );
        exit( 21 );
    }
    foreach ( $matches as $post ) {
        if ( $post->post_title !== $title || ( $user && (int) $post->post_author !== (int) $user->ID )
            || ( ! $user && $listing_id && (int) $post->ID !== $listing_id ) ) {
            fwrite( STDERR, "Listing identity/owner mismatch; refusing deletion.\n" );
            exit( 22 );
        }
        wp_delete_post( $post->ID, true );
    }
    if ( $user ) {
        $owned = get_posts( array(
            'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
            'post_status'      => array_values( get_post_stati() ),
            'author'           => (int) $user->ID,
            'numberposts'      => 1,
            'suppress_filters' => true,
        ) );
        if ( $owned ) {
            fwrite( STDERR, "Temporary account owns an unexpected listing; refusing account deletion.\n" );
            exit( 23 );
        }
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user( $user->ID );
    }
    $remaining_user = get_user_by( 'login', $login );
    $remaining_post = get_posts( array(
        'post_type'        => PARTIKULIER_ESTATIK_POST_TYPE,
        'post_status'      => array_values( get_post_stati() ),
        'numberposts'      => 1,
        'meta_key'         => $listing_marker,
        'meta_value'       => $marker,
        'suppress_filters' => true,
    ) );
    if ( $remaining_user || $remaining_post ) {
        fwrite( STDERR, "Cleanup verification failed: run fixture remains.\n" );
        exit( 24 );
    }
    echo "Run-scoped account and listing are absent.\n";
    exit( 0 );
}

fwrite( STDERR, "Expected mode: seed or cleanup.\n" );
exit( 25 );
