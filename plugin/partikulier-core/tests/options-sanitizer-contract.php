<?php
/**
 * Contrat de sécurité SE-020 — tri des résidus sécurité du bruit PHPCS
 * (campagne post-audit 2026-09, P1-6 de l'audit indépendant du 12/09/2026).
 *
 * Verrous du lot : E-2003 sanitiseur d'options (liste blanche clé -> callback
 * appliquée à pk_opts / pk_theme_options AVANT toute persistance — y compris
 * le transient de validation, une charge parasite ressort ignorée/assainie,
 * jamais stockée telle quelle) ; E-2004 verbe HTTP assaini (sanitize_key,
 * repli GET, comparaison minuscule) ; E-2005 URL de retour validée
 * wp_validate_redirect (same-site) sur le pont d'authentification vendu ;
 * E-2006 redirection de dépôt via wp_safe_redirect ; durcissement JSON-LD
 * inline (JSON_HEX_TAG — une séquence </script> d'un titre importé brut ne
 * peut plus rompre le contexte script HTML).
 *
 * Huit assertions : OS-001 clés inconnues ignorées ; OS-002 charge parasite
 * <script> assainie dans les champs texte ; OS-003 home_intro kses liste
 * blanche ; OS-004 chemin complet save() — le transient de validation ne
 * contient QUE des valeurs assainies (sous-processus dédié, la sortie
 * wp_safe_redirect/exit est interceptée) ; OS-005 verbe HTTP mixte accepté,
 * écriture refusée (E-2004) ; OS-006 URL externe abandonnée / même site
 * conservée (E-2005) ; OS-007 wp_safe_redirect effectif, zéro wp_redirect
 * nu (E-2006) ; OS-008 JSON-LD hex-échappé sur titre importé brut.
 *
 * Rejouable : PK_WP_DIR=... PK_COMMIT=<sha> PK_THEME_DIR=... php partikulier-core/tests/options-sanitizer-contract.php
 */

declare(strict_types=1);

$wpDir = getenv('PK_WP_DIR') ?: '';
$commit = getenv('PK_COMMIT') ?: '';
$themeDir = getenv('PK_THEME_DIR') ?: '';
if ($wpDir === '' || !is_file($wpDir . '/wp-load.php')) { fwrite(STDERR, "PK_WP_DIR doit pointer vers WordPress\n"); exit(2); }
if (!preg_match('/^[0-9a-f]{40}$/', $commit)) { fwrite(STDERR, "PK_COMMIT doit être un SHA de 40 caractères\n"); exit(2); }
if ($themeDir === '' || !is_dir($themeDir)) { fwrite(STDERR, "PK_THEME_DIR doit pointer vers le thème partikulier\n"); exit(2); }

/* Mode sous-processus OS-004 : exécuter save() jusqu'au exit et dumper le
   transient via shutdown. Le parent relit le marqueur. */
if ($argc > 1 && $argv[1] === '--os004-subprocess') {
    $marker = $argv[2];
    require $wpDir . '/wp-load.php';
    wp_set_current_user( 1 );
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $_POST = array(
        'action' => 'pk_save_customization',
        '_wpnonce' => wp_create_nonce( 'pk_save_customization' ),
        'pk_opts' => array(
            'logo_attachment_id' => '12<script>alert(1)</script>',
            'hero_attachment_id' => '34',
            'hero_image_alt' => '<script>alert(1)</script>Charmante villa avec piscine',
            'editorial' => array( 'home_intro' => array( 'fr' => '<p>Légitime</p><script>bad()</script>' ) ),
            'cle_forgée_parasite' => '<script>evil()</script>',
        ),
    );
    $_REQUEST = $_POST;
    register_shutdown_function( static function () use ( $marker ): void {
        $transient = get_transient( 'pk_customization_invalid_' . get_current_user_id() );
        file_put_contents( $marker, wp_json_encode( array( 'transient' => $transient ), JSON_UNESCAPED_UNICODE ) );
    } );
    Partikulier_Customization::save();
    exit; /* atteint uniquement si la validation n'a pas redirigé */
}

require $wpDir . '/wp-load.php';

$started = gmdate('c');
$results = [];
$assert = static function (string $id, bool $ok, string $detail = '') use (&$results): void {
    $results[] = ['test_id' => $id, 'status' => $ok ? 'PASS' : 'FAIL', 'detail' => $detail];
};

$payload = '<script>alert("pk")</script>';

/* --- OS-001..003 : sanitiseur d'options en isolation --- */
$sanitize = static function ( array $posted ) use ( $payload ): array {
    return Partikulier_Options_Sanitizer::sanitize_customization( $posted );
};
$classeDispo = class_exists( 'Partikulier_Options_Sanitizer' );

$poste = array(
    'logo_attachment_id' => '12' . $payload,
    'hero_image_alt' => $payload . 'Charmante villa avec piscine',
    'editorial' => array( 'home_intro' => array( 'fr' => '<em>Légitime</em><script>bad()</script>' ) ),
    'cle_forgée_parasite' => $payload,
    'localized' => array( 'fr' => array( 'champ_inconnu' => $payload ) ),
);
$clean = $classeDispo ? $sanitize( $poste ) : array();

$assert('OS-001', $classeDispo && ! array_key_exists( 'cle_forgée_parasite', $clean ) && ! isset( $clean['localized']['fr']['champ_inconnu'] ),
    $classeDispo ? 'clés inconnues ignorées silencieusement (E-2003 : jamais enregistrées)' : 'Partikulier_Options_Sanitizer absent du thème');

$serialise = $classeDispo ? wp_json_encode( $clean, JSON_UNESCAPED_UNICODE ) : '';
$assert('OS-002', $classeDispo && strpos( $serialise, '<script' ) === false && strpos( (string) ( $clean['hero_image_alt'] ?? '' ), 'Charmante villa' ) !== false,
    'charge parasite <script> éliminée des champs texte (sanitize_text_field/absint)');

$intro = (string) ( $clean['editorial']['home_intro']['fr'] ?? '' );
$assert('OS-003', $classeDispo && strpos( $intro, '<em>Légitime</em>' ) !== false && strpos( $intro, '<script' ) === false,
    'home_intro : wp_kses liste blanche a/strong/em/br — HTML légitime conservé, script éliminé');

/* --- OS-004 : chemin complet save() — transient de validation assaini --- */
$marker = tempnam( sys_get_temp_dir(), 'os004-' ) . '.json';
$cmd = escapeshellarg( PHP_BINARY ) . ' ' . escapeshellarg( __FILE__ )
     . ' --os004-subprocess ' . escapeshellarg( $marker ) . ' > /dev/null 2>&1';
$envWP = 'PK_WP_DIR=' . escapeshellarg( $wpDir ) . ' PK_COMMIT=' . escapeshellarg( $commit ) . ' PK_THEME_DIR=' . escapeshellarg( $themeDir );
exec( $envWP . ' ' . $cmd, $sortie, $code );
$transientData = null;
if ( is_file( $marker ) ) {
    $decode = json_decode( (string) file_get_contents( $marker ), true );
    $transientData = is_array( $decode ) ? ( $decode['transient'] ?? null ) : null;
    unlink( $marker );
}
delete_transient( 'pk_customization_invalid_1' );
$transientJson = is_array( $transientData ) ? wp_json_encode( $transientData, JSON_UNESCAPED_UNICODE ) : '';
$assert('OS-004', is_array( $transientData ) && strpos( $transientJson, '<script' ) === false && ! array_key_exists( 'cle_forgée_parasite', $transientData ) && ( absint( $transientData['logo_attachment_id'] ?? 'x' ) === 12 ),
    'save() : le transient de validation ne stocke que des valeurs assainies (E-2003 : jamais stockée telle quelle) — exit ' . $code . ', contenu : ' . mb_substr( $transientJson, 0, 120 ) );

/* --- OS-005 : E-2004 — verbe assaini (repli/mixte) dans la garde mu-plugin --- */
$serveur = $_SERVER;
$get = $_GET;
$dispo = function_exists( 'partikulier_is_public_listings_get' );
$mixteOk = false; $ecritureRefusee = false;
if ( $dispo ) {
    $_SERVER['REQUEST_METHOD'] = 'GeT';
    $_SERVER['REQUEST_URI'] = '/wp-json/partikulier/v1/listings';
    $_SERVER['HTTP_AUTHORIZATION'] = '';
    $_SERVER['HTTP_COOKIE'] = '';
    $_GET = array();
    $mixteOk = partikulier_is_public_listings_get() === true;
    $_SERVER['REQUEST_METHOD'] = 'post';
    $ecritureRefusee = partikulier_is_public_listings_get() === false;
    unset( $_SERVER['REQUEST_METHOD'] );
    $_GET = array( 'rest_route' => '/partikulier/v1/listings' );
    $repliGet = partikulier_is_public_listings_get() === true;
    $_SERVER = $serveur;
    $_GET = $get;
} else {
    $repliGet = false;
}
$assert('OS-005', $dispo && $mixteOk && $ecritureRefusee && $repliGet,
    sprintf( 'E-2004 : verbe « GeT » accepté (sanitize_key), « post » refusé, verbe absent + rest_route => repli GET (%s)', $dispo ? 'mu-plugin chargé' : 'mu-plugin absent' ) );

/* --- OS-006 : E-2005 — URL de retour validée same-site --- */
$authOk = class_exists( 'Partikulier_Estatik' ) && method_exists( 'Partikulier_Estatik', 'preserve_auth_redirect_url' );
$externeBloque = false; $memeSiteGarde = false;
if ( $authOk ) {
    $post = $_POST;
    $_SERVER['REQUEST_METHOD'] = 'POST';
    $uri = (string) home_url( '/connexion/' );
    $_POST = array( 'redirect_url' => 'https://evil.example.com/phishing' );
    $sortieExterne = (string) Partikulier_Estatik::preserve_auth_redirect_url( $uri );
    $externeBloque = strpos( $sortieExterne, 'evil.example.com' ) === false && $sortieExterne === $uri;
    $_POST = array( 'redirect_url' => (string) home_url( '/annonces/' ) );
    $sortieMeme = (string) Partikulier_Estatik::preserve_auth_redirect_url( $uri );
    $memeSiteGarde = strpos( $sortieMeme, 'redirect_url=' . rawurlencode( (string) home_url( '/annonces/' ) ) ) !== false;
    $_POST = $post;
}
$assert('OS-006', $authOk && $externeBloque && $memeSiteGarde,
    sprintf( 'E-2005 : URL externe abandonnée (%s), URL même-site conservée (%s)',
        $externeBloque ? 'oui' : 'NON', $memeSiteGarde ? 'oui' : 'NON' ) );

/* --- OS-007 : E-006 [E-2006] — wp_safe_redirect, zéro wp_redirect nu --- */
$formeSource = (string) file_get_contents( $themeDir . '/inc/class-form.php' );
$nbNu = preg_match_all( '/(?<!safe_)(?<!_)\bwp_redirect\s*\(/', $formeSource );
$safePresent = strpos( $formeSource, 'wp_safe_redirect( get_permalink( $result ) )' ) !== false;
$assert('OS-007', $nbNu === 0 && $safePresent,
    sprintf( 'E-2006 : redirection de dépôt via wp_safe_redirect, %d appel wp_redirect nu restant', (int) $nbNu ) );

/* --- OS-008 : JSON-LD inline hex-échappé (titre importé brut) --- */
/* Aiguille SANS guillemet : json_encode échappe les guillemets internes — la
   rupture de contexte réelle est la séquence </script><script> brute. */
$titrePiege = 'Villa piège OS008 </script><script>alert(1)//';
$pid = wp_insert_post( array(
    'post_type' => 'properties',
    'post_status' => 'publish',
    'post_title' => $titrePiege,
    'post_content' => 'fixture OS-008 (chemin import brut)',
) );
$htmlHead = '';
if ( $pid && ! is_wp_error( $pid ) ) {
    /* Chemin « import brut » : mise à jour directe du titre (aucun wp_filter_kses),
       comme un outil d'import massif qui écrit via $wpdb. */
    global $wpdb;
    $wpdb->update( $wpdb->posts, array( 'post_title' => $titrePiege ), array( 'ID' => $pid ) );
    clean_post_cache( $pid );
    ob_start();
    query_posts( array( 'p' => (int) $pid, 'post_type' => 'properties', 'posts_per_page' => 1 ) );
    if ( have_posts() ) {
        the_post();
        do_action( 'wp_head' );
    }
    $htmlHead = (string) ob_get_clean();
    wp_reset_query();
    wp_reset_postdata();
    wp_delete_post( $pid, true );
}
$ruptureBrute = strpos( $htmlHead, '</script><script>alert' ) !== false;
$hexPresent = strpos( $htmlHead, '\u003C' ) !== false;
$assert('OS-008', $htmlHead !== '' && ! $ruptureBrute && $hexPresent,
    sprintf( 'JSON-LD inline : rupture </script> neutralisée par JSON_HEX_TAG (brute=%s, hex=%s, %d octets de head)',
        $ruptureBrute ? 'oui' : 'non', $hexPresent ? 'oui' : 'non', strlen( $htmlHead ) ) );

$failed = array_values(array_filter($results, static fn(array $row): bool => $row['status'] !== 'PASS'));
echo json_encode([
    'suite' => 'options-sanitizer-contract (SE-020)',
    'started_at' => $started,
    'finished_at' => gmdate('c'),
    'command' => 'php partikulier-core/tests/options-sanitizer-contract.php',
    'commit' => $commit,
    'run_id' => getenv('PK_RUN_ID') ?: 'local-' . gmdate('Ymd\THis\Z'),
    'status' => $failed ? 'FAIL' : 'PASS',
    'total' => count($results),
    'passed' => count($results) - count($failed),
    'failed' => count($failed),
    'results' => $results,
    'security_scope' => [
        'options_whitelist_sanitizer' => true,
        'request_method_sanitized' => true,
        'auth_redirect_same_site_only' => true,
        'listing_redirect_safe' => true,
        'jsonld_hex_escaped' => true,
    ],
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
exit($failed ? 1 : 0);
