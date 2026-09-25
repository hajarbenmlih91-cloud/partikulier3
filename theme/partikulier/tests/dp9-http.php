<?php
/**
 * SE-044 / DP-9 — socle commun des suites dp9 HTTP (tests uniquement).
 *
 * Fourni aux suites dp9-owner-journey-contract, dp9-variant-resolution-contract
 * et dp9-partial-failure-contract :
 *  - vérification d'environnement (PK_BASE, PK_WP_DIR, PK_COMMIT) ;
 *  - session propriétaire fictive (cookie + nonces générés in-process — le
 *    processus de la suite charge wp-load, la base est partagée avec le
 *    serveur HTTP attaqué) ;
 *  - appels réels aux DEUX entrées serveur : AJAX admin-ajax
 *    (pk_manage_listing) et REST /owner/listings/{id}/action ;
 *  - lecture d'autorité de l'état stocké (méta source, hors cache) ;
 *  - armement/désarmement du harnais d'injection (option partagée).
 *
 * N'est JAMAIS empaqueté (scripts/package.sh exclut tests/ des artefacts).
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit( 'Chargé par une suite dp9 après wp-load' );
}

/** Vérifie l'environnement d'exécution de la suite (exit 2 si incomplet). */
function dp9_env_check( string $base, string $wpDir, string $commit ): void {
        if ( '' === $wpDir || ! is_file( $wpDir . '/wp-load.php' ) ) {
                fwrite( STDERR, "PK_WP_DIR doit pointer vers une installation WordPress\n" );
                exit( 2 );
        }
        if ( '' === $base || ! preg_match( '#^https?://#', $base ) ) {
                fwrite( STDERR, "PK_BASE doit pointer vers le serveur démarré\n" );
                exit( 2 );
        }
        if ( ! preg_match( '/^[0-9a-f]{40}$/', $commit ) ) {
                fwrite( STDERR, "PK_COMMIT doit être un SHA Git de 40 caractères\n" );
                exit( 2 );
        }
}

/** Requête HTTP brute (curl). @return array{0:int,1:string} */
function dp9_http( string $url, array $data = null, array $headers = array(), string $method = '' ): array {
        $ch = curl_init( $url );
        $opts = array(
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 30,
                CURLOPT_HEADER         => false,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_FOLLOWLOCATION => true, // les fiches à URL courte (sans ville) 301 vers la forme canonique
                CURLOPT_MAXREDIRS      => 3,
        );
        if ( null !== $data ) {
                $opts[ CURLOPT_POST ]       = true;
                $opts[ CURLOPT_POSTFIELDS ] = http_build_query( $data );
        } elseif ( '' !== $method ) {
                $opts[ CURLOPT_CUSTOMREQUEST ] = $method;
        }
        curl_setopt_array( $ch, $opts );
        $body = curl_exec( $ch );
        $code = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
        $err  = curl_error( $ch );
        curl_close( $ch );
        if ( false === $body ) {
                return array( 0, 'curl error: ' . $err );
        }
        return array( $code, (string) $body );
}

/**
 * Session propriétaire fictive (cookie + nonces), générée in-process.
 *
 * @return array{cookie:string, ajax_nonce:string, rest_nonce:string}
 */
function dp9_session( int $owner_id ): array {
        $token  = WP_Session_Tokens::get_instance( $owner_id )->create( time() + 3600 );
        $logged = wp_generate_auth_cookie( $owner_id, time() + 3600, 'logged_in', $token );
        $auth   = wp_generate_auth_cookie( $owner_id, time() + 3600, 'auth', $token );
        wp_set_current_user( $owner_id );
        $_COOKIE[ LOGGED_IN_COOKIE ] = $logged; // contexte in-process pour wp_create_nonce.
        return array(
                'cookie'      => LOGGED_IN_COOKIE . '=' . $logged . '; ' . AUTH_COOKIE . '=' . $auth,
                'ajax_nonce'  => wp_create_nonce( 'pk_manage_listing' ),
                'rest_nonce'  => wp_create_nonce( 'wp_rest' ),
        );
}

/** Appel à l'entrée AJAX (admin-ajax, action pk_manage_listing). @return array{0:int,1:array} */
function dp9_ajax( string $base, array $session, int $post_id, string $action, string $reason = '', string $note = '', string $nonce = null ): array {
        $d = array(
                'action'       => 'pk_manage_listing',
                'post_id'      => $post_id,
                'manage_action' => $action,
                'nonce'        => $nonce ?? $session['ajax_nonce'],
        );
        if ( '' !== $reason ) {
                $d['reason'] = $reason;
        }
        if ( '' !== $note ) {
                $d['note'] = $note;
        }
        list( $s, $b ) = dp9_http( rtrim( $base, '/' ) . '/wp-admin/admin-ajax.php', $d, array( 'Cookie: ' . $session['cookie'] ) );
        $j = json_decode( $b, true );
        return array( $s, is_array( $j ) ? $j : array( 'raw' => substr( $b, 0, 400 ) ) );
}

/** Appel à l'entrée REST (/owner/listings/{id}/action, cookie + X-WP-Nonce). @return array{0:int,1:array} */
function dp9_rest( string $base, array $session, int $post_id, string $action, string $reason = '', string $note = '', bool $avec_nonce = true ): array {
        $d = array( 'action' => $action );
        if ( '' !== $reason ) {
                $d['reason'] = $reason;
        }
        if ( '' !== $note ) {
                $d['note'] = $note;
        }
        $h = array( 'Cookie: ' . $session['cookie'], 'Content-Type: application/x-www-form-urlencoded' );
        if ( $avec_nonce ) {
                $h[] = 'X-WP-Nonce: ' . $session['rest_nonce'];
        }
        list( $s, $b ) = dp9_http( rtrim( $base, '/' ) . '/wp-json/partikulier/v1/owner/listings/' . $post_id . '/action', $d, $h );
        $j = json_decode( $b, true );
        return array( $s, is_array( $j ) ? $j : array( 'raw' => substr( $b, 0, 400 ) ) );
}

/** GET HTTP brut avec la session propriétaire (fiches, pages, collection). @return array{0:int,1:string} */
function dp9_get( string $base, array $session, string $chemin ): array {
        return dp9_http( rtrim( $base, '/' ) . $chemin, null, array( 'Cookie: ' . $session['cookie'] ) );
}

/**
 * Lecture d'autorité (R1 §4.2 d) : méta des posts lue directement, hors
 * cache de disponibilité — l'état stocké fait foi.
 *
 * NOTE : les transitions s'exécutent dans le PROCESSUS SERVEUR (HTTP) alors
 * que cette lecture vit dans le processus de la suite — le cache d'objets
 * WordPress (en mémoire, par processus) est donc invalidé AVANT lecture,
 * sinon la première valeur lue resterait gelée pour tout le reste de la suite.
 *
 * @param int[]|int $ids
 * @return array<int, array{post_status:string,pk:string,reason:string,note:string,closed_at:string,premium:string}>
 */
function dp9_state( $ids ): array {
        if ( is_int( $ids ) ) {
                $ids = array( $ids );
        }
        $out = array();
        foreach ( $ids as $id ) {
                $id = (int) $id;
                clean_post_cache( $id ); // invalide le cache objet local : la DB fait foi.
                $out[ $id ] = array(
                        'post_status' => (string) get_post_status( $id ),
                        'pk'          => (string) get_post_meta( $id, '_pk_status', true ),
                        'reason'      => (string) get_post_meta( $id, '_pk_closed_reason', true ),
                        'note'        => (string) get_post_meta( $id, '_pk_closed_note', true ),
                        'closed_at'   => (string) get_post_meta( $id, '_pk_closed_at', true ),
                        'premium'     => (string) get_post_meta( $id, '_pk_premium_status', true ),
                );
        }
        return $out;
}

/** Code applicatif du corps d'erreur (enveloppe AJAX data.code ou REST code racine). */
function dp9_code_erreur( array $b ): string {
        if ( isset( $b['code'] ) && is_string( $b['code'] ) ) {
                return $b['code'];
        }
        if ( isset( $b['data']['code'] ) && is_string( $b['data']['code'] ) ) {
                return $b['data']['code'];
        }
        return '';
}

/** (failed_step, report) du corps pk_partial_failure — enveloppe AJAX ou REST. */
function dp9_corps_500( array $b ): array {
        $d = isset( $b['data'] ) && is_array( $b['data'] ) ? $b['data'] : $b;
        return array(
                isset( $d['failed_step'] ) ? $d['failed_step'] : null,
                isset( $d['report'] ) && is_array( $d['report'] ) ? $d['report'] : null,
        );
}

/** Étapes du rapport d'une réponse 200 (AJAX data.steps ou REST steps). @return array[] */
function dp9_etapes( array $b ): array {
        $d = isset( $b['data'] ) && is_array( $b['data'] ) ? $b['data'] : $b;
        return isset( $d['steps'] ) && is_array( $d['steps'] ) ? $d['steps'] : array();
}

/** Identifiant canonique d'une réponse 200. @return int|null */
function dp9_canonical( array $b ) {
        $d = isset( $b['data'] ) && is_array( $b['data'] ) ? $b['data'] : $b;
        return isset( $d['canonical_id'] ) && is_int( $d['canonical_id'] ) ? $d['canonical_id'] : null;
}

/** Arme le harnais d'injection (option partagée avec le serveur HTTP). */
function dp9_arme( string $point, int $source_id = 0, int $variant_id = 0, array $extra = array() ): void {
        // L'auto-désarmement vit dans le PROCESSUS SERVEUR (delete_option à chaque
        // déclenchement) alors que l'armement vit ici. Piège WordPress : quand la
        // ligne a déjà été supprimée par le serveur, delete_option sort SANS nettoyer
        // le cache local, et add_option verrait alors l'ancienne valeur en cache et
        // n'écrirait RIEN en base. On invalide donc explicitement les clés de cache
        // locales avant l'insertion fraîche — y compris le cache « alloptions »,
        // sinon get_option (appelé par add_option) relirait l'ancienne valeur depuis
        // ce tableau groupé et l'insertion serait silencieusement annulée.
        foreach ( array( 'pk_dp9_injection_armee', 'notoptions', 'alloptions' ) as $cle ) {
                wp_cache_delete( $cle, 'options' );
        }
        delete_option( 'pk_dp9_injection_armee' );
        foreach ( array( 'pk_dp9_injection_armee', 'notoptions', 'alloptions' ) as $cle ) {
                wp_cache_delete( $cle, 'options' );
        }
        $o = array( 'point' => $point );
        if ( $source_id ) {
                $o['source_id'] = $source_id;
        }
        if ( $variant_id ) {
                $o['variant_id'] = $variant_id;
        }
        add_option( 'pk_dp9_injection_armee', array_merge( $o, $extra ), '', true );
}

/** Désarme le harnais d'injection. */
function dp9_desarme(): void {
        delete_option( 'pk_dp9_injection_armee' );
        foreach ( array( 'pk_dp9_injection_armee', 'notoptions', 'alloptions' ) as $cle ) {
                wp_cache_delete( $cle, 'options' );
        }
}
