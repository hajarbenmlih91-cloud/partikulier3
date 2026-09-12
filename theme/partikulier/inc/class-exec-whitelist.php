<?php
/**
 * Module : passerelle unique des appels système (lot E — SECU-1, CDC v1.2).
 *
 * Le point d'appel système du code livré est cloisonné ICI et nulle part
 * ailleurs : class-avif.php (conversion AVIF : binaires avifenc, vips) et
 * pk-diagnostic.php (sonde mono-ouvrier : binaire ps) passent par cette
 * passerelle. Aucun autre fichier du thème ou du plugin n'invoque exec(),
 * shell_exec(), system(), passthru(), popen() ni proc_open() — le contrat
 * avif-security-contract le vérifie par analyse lexicale (token_get_all) sur
 * le périmètre runtime (hors tests).
 *
 * Garanties (CDC §3.4) :
 *  - liste blanche de binaires (avifenc, vips, ps) validée par chemin absolu
 *    (candidats énumérés, premier exécutable retenu) et par empreinte sha256
 *    quand elle est épinglée (hash_file + hash_equals) ;
 *  - validation stricte des entrées : fichiers et cibles strictement sous
 *    wp-content/uploads, extensions autorisées (entrée jpg/jpeg/png/webp,
 *    cible identiques + avif), segments de chemin sans métacaractère,
 *    suffixe vips [Q=n] strictement analysé ;
 *  - cible refusée si elle est un lien symbolique (REFUS:cible-lien) :
 *    le binaire écrirait au-travers et s'échapperait de la prison — test
 *    sur le chemin physique, suffixe [Q=n] retiré avant ;
 *  - chaque argument fichier/cible est protégé par escapeshellarg() ; les
 *    arguments « drapeau » n'entrent qu'après validation par un jeu de
 *    caractères sans métacaractère shell ;
 *  - délai d'exécution maximal (EXEC_TIMEOUT s, filtre
 *    partikulier_exec_timeout) : dépassement => SIGKILL + EXEC:timeout,
 *    aucun ouvrier PHP bloqué indéfiniment ;
 *  - journal de toutes les invocations et de tous les refus
 *    (uploads/partikulier/exec-journal.log, rotation à 256 Kio) ;
 *  - mode dégradé : binaire absent, empreinte invalide ou passerelle
 *    proc_open indisponible => refus propre, jamais d'erreur fatale, état
 *    journalisé et visible via resolve()/is_available()/journal_path().
 *
 * Épinglage : les entrées livrées n'épinglent pas d'empreinte (sha256 à null
 * => état « non épinglée » journalisé) ; un hébergement épingle via le filtre
 * 'partikulier_exec_whitelist', et la constante PARTIKULIER_EXEC_REQUIRE_PIN
 * (wp-config) refuse tout binaire non épinglé.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Exec_Whitelist {

        /** Plafond du journal avant rotation (256 Kio). */
        const JOURNAL_MAX_OCTETS = 262144;

        /** Extensions acceptées en entrée (fichiers média à convertir). */
        const INPUT_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'webp' );

        /** Extensions acceptées en cible (fichiers écrits par les binaires). */
        const TARGET_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'webp', 'avif' );

        /** Drapeaux : aucun métacaractère shell possible. */
        const FLAG_PATTERN = '/^[A-Za-z0-9 =._,-]{1,64}$/';

        /** Segments de chemin (dossiers, noms de fichiers) sans métacaractère. */
        const NAME_PATTERN = '/^[A-Za-z0-9._-]{1,128}$/';

        /** Suffixe vips strictement analysé (ex. [Q=75]). */
        const VIPS_SUFFIX = '/\[Q=[0-9]{1,3}\]$/';

        /** Délai maximal d'exécution en secondes (filtre partikulier_exec_timeout). */
        const EXEC_TIMEOUT = 30;

        /** Résolutions de la requête courante (cache). */
        private static $resolved = array();

        /**
         * Liste blanche : clef => candidats (chemins absolus) + empreinte sha256
         * (null = non épinglée). Filtrable via 'partikulier_exec_whitelist'.
         */
        public static function whitelist() {
                $entries = array(
                        'avifenc' => array(
                                'candidates' => array( '/usr/bin/avifenc', '/usr/local/bin/avifenc' ),
                                'sha256'     => null,
                        ),
                        'vips'    => array(
                                'candidates' => array( '/usr/bin/vips', '/usr/local/bin/vips' ),
                                'sha256'     => null,
                        ),
                        'ps'      => array(
                                'candidates' => array( '/bin/ps', '/usr/bin/ps' ),
                                'sha256'     => null,
                        ),
                );
                $entries = apply_filters( 'partikulier_exec_whitelist', $entries );
                return is_array( $entries ) ? $entries : array();
        }

        /** Résolution d'une clef : premier candidat exécutable, empreinte contrôlée. États : 'ok' | 'absent' | 'fingerprint' | 'exec' (passerelle indisponible). */
        public static function resolve( $key ) {
                if ( isset( self::$resolved[ $key ] ) ) {
                        return self::$resolved[ $key ];
                }
                $state = array( 'key' => (string) $key, 'path' => null, 'state' => 'absent', 'pinned' => false );
                if ( ! function_exists( 'proc_open' ) || ! function_exists( 'proc_get_status' ) || ! function_exists( 'proc_terminate' ) || ! function_exists( 'stream_select' ) ) {
                        $state['state'] = 'exec';
                        return self::$resolved[ $key ] = $state;
                }
                $entries = self::whitelist();
                $entry   = ( isset( $entries[ $key ] ) && is_array( $entries[ $key ] ) ) ? $entries[ $key ] : null;
                $cands   = ( $entry && isset( $entry['candidates'] ) && is_array( $entry['candidates'] ) ) ? $entry['candidates'] : array();
                $raw_pin = ( $entry && array_key_exists( 'sha256', $entry ) ) ? $entry['sha256'] : null;
                $pin     = null;
                if ( is_string( $raw_pin ) ) {
                        if ( ! preg_match( '/^[0-9a-fA-F]{64}$/', $raw_pin ) ) {
                                $state['state'] = 'fingerprint';
                                return self::$resolved[ $key ] = $state; // empreinte mal formée : jamais exécutée
                        }
                        $pin = strtolower( $raw_pin );
                }
                foreach ( $cands as $cand ) {
                        if ( ! is_string( $cand ) || '' === $cand || '/' !== $cand[0] ) {
                                continue; // chemin absolu exigé (CDC : liste blanche par chemin absolu)
                        }
                        if ( ! is_executable( $cand ) ) {
                                continue;
                        }
                        $state['path'] = $cand;
                        if ( null !== $pin ) {
                                $actual = hash_file( 'sha256', $cand );
                                if ( ! is_string( $actual ) || ! hash_equals( $pin, strtolower( $actual ) ) ) {
                                        $state['state'] = 'fingerprint';
                                        return self::$resolved[ $key ] = $state; // présent mais empreinte invalide : jamais exécuté
                                }
                                $state['pinned'] = true;
                        }
                        $state['state'] = 'ok';
                        return self::$resolved[ $key ] = $state;
                }
                return self::$resolved[ $key ] = $state;
        }

        /** Le binaire est-il utilisable (liste blanche + empreinte) ? */
        public static function is_available( $key ) {
                $res = self::resolve( $key );
                return 'ok' === $res['state'];
        }

        /** Exiger l'épinglage d'empreinte (wp-config.php). */
        public static function pin_required() {
                return defined( 'PARTIKULIER_EXEC_REQUIRE_PIN' ) && PARTIKULIER_EXEC_REQUIRE_PIN;
        }

        /** Chemin du journal (null si uploads indisponible). */
        public static function journal_path() {
                $up = wp_get_upload_dir();
                if ( empty( $up['basedir'] ) ) {
                        return null;
                }
                return untrailingslashit( (string) $up['basedir'] ) . '/partikulier/exec-journal.log';
        }

        /**
         * Construit la commande validée SANS l'exécuter (contrats, audit).
         * Arguments : array( 'type' => 'file'|'target'|'flag', 'value' => … ).
         */
        public static function build( $key, $args ) {
                $built   = array(
                        'ok'      => false,
                        'reason'  => '',
                        'command' => '',
                        'key'     => (string) $key,
                        'path'    => null,
                        'pinned'  => false,
                );
                $res     = self::resolve( $key );
                $built['path']   = $res['path'];
                $built['pinned'] = (bool) $res['pinned'];
                if ( 'ok' !== $res['state'] ) {
                        $built['reason'] = (string) $res['state'];
                        return $built;
                }
                if ( ! is_array( $args ) || array() === $args ) {
                        $built['reason'] = 'args';
                        return $built;
                }
                $pieces = array( escapeshellarg( (string) $res['path'] ) );
                foreach ( $args as $arg ) {
                        if ( ! is_array( $arg ) || ! isset( $arg['type'], $arg['value'] ) ) {
                                $built['reason'] = 'args';
                                return $built;
                        }
                        if ( 'flag' === $arg['type'] ) {
                                if ( ! is_string( $arg['value'] ) || ! preg_match( self::FLAG_PATTERN, $arg['value'] ) ) {
                                        $built['reason'] = 'drapeau';
                                        return $built;
                                }
                                $pieces[] = $arg['value'];
                                continue;
                        }
                        if ( 'file' === $arg['type'] || 'target' === $arg['type'] ) {
                                $why   = '';
                                $piece = self::validate_path( $arg['value'], $arg['type'], $why );
                                if ( false === $piece ) {
                                        $built['reason'] = ( '' !== $why ) ? $why : ( ( 'file' === $arg['type'] ) ? 'fichier' : 'cible' );
                                        return $built;
                                }
                                $pieces[] = $piece;
                                continue;
                        }
                        $built['reason'] = 'args';
                        return $built;
                }
                $built['command'] = implode( ' ', $pieces ) . ' 2>&1';
                $built['ok']      = true;
                return $built;
        }

        /**
         * Exécute via la passerelle — unique point proc_open() du code livré, délai maximal : SIGKILL + EXEC:timeout.
         */
        public static function run( $key, $args = array() ) {
                $built = self::build( $key, $args );
                $out = array(
                        'ok' => false, 'reason' => $built['reason'], 'command' => $built['command'], 'key' => (string) $key,
                        'exit_code' => null, 'output' => array(), 'duration_ms' => null,
                );
                $pin   = $built['pinned'] ? 'epinglee' : 'non-epinglee';
                if ( ! $built['ok'] ) {
                        self::journal( $key, (string) $built['path'], 'REFUS:' . $built['reason'], null, $pin, self::summarize( $args ) );
                        return $out;
                }
                if ( self::pin_required() && ! $built['pinned'] ) {
                        $out['reason'] = 'empreinte-non-epinglee';
                        self::journal( $key, (string) $built['path'], 'REFUS:empreinte-non-epinglee', null, $pin, self::summarize( $args ) );
                        return $out;
                }
                $t0 = microtime( true );
                $deadline = $t0 + max( 1.0, (float) apply_filters( 'partikulier_exec_timeout', self::EXEC_TIMEOUT ) );
                $spec     = array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ) ); $pipes = array();
                $proc     = @proc_open( 'exec ' . $built['command'], $spec, $pipes ); // nosemgrep: php.lang.security.exec-use.exec-use -- point d'appel unique cloisonné (SECU-1) : binaire en liste blanche (chemin absolu + empreinte), arguments protégés par escapeshellarg, drapeaux validés, délai maximal ; préfixe exec : le binaire REMPLACE sh (aucun processus orphelin ne survit au SIGKILL, le tuyau se ferme à la mort du binaire)
                if ( ! is_resource( $proc ) ) {
                        $out['reason'] = 'demarrage';
                        self::journal( $key, (string) $built['path'], 'REFUS:demarrage', null, $pin, self::summarize( $args ) );
                        return $out;
                }
                fclose( $pipes[0] ); // stdin : EOF immédiat, aucun binaire n'attend d'entrée.
                $stdout = ''; $eof = false; $late = false; $status = null;
                while ( is_array( $status = @proc_get_status( $proc ) ) && ! empty( $status['running'] ) ) {
                        $left = $deadline - microtime( true );
                        if ( $left <= 0 ) { $late = true; break; }
                        if ( $eof ) { usleep( 50000 ); continue; }
                        $read = array( $pipes[1] ); $write = $expar = null;
                        $ready = @stream_select( $read, $write, $expar, (int) $left, (int) round( fmod( $left, 1 ) * 1000000 ) );
                        if ( false === $ready ) { continue; } // interruption : délai réévalué au tour suivant.
                        if ( $ready > 0 ) {
                                $chunk = @fread( $pipes[1], 65536 );
                                if ( ! is_string( $chunk ) || '' === $chunk ) { $eof = true; continue; }
                                $stdout .= $chunk;
                        }
                }
                if ( $late ) { @proc_terminate( $proc, 9 ); } // SIGKILL : délai dépassé, l'ouvrier est libéré.
                // Piège proc_close() : drainer AVANT (un enfant tué au tampon plein
                // le bloque) et ignorer sa valeur de retour (-1 après un signal) —
                // le code de sortie se lit uniquement sur le PREMIER statut post-exit.
                while ( is_string( $chunk = @fread( $pipes[1], 65536 ) ) && '' !== $chunk ) { $stdout .= $chunk; }
                fclose( $pipes[1] );
                @proc_close( $proc ); // récolte du processus ; valeur de retour ignorée (cf. piège).
                $out['duration_ms'] = $ms = (int) round( ( microtime( true ) - $t0 ) * 1000 );
                if ( $late ) {
                        $out['reason'] = 'timeout';
                        self::journal( $key, (string) $built['path'], 'EXEC:timeout', $ms, $pin, self::summarize( $args ) );
                        return $out;
                }
                $code = ( is_array( $status ) && isset( $status['exitcode'] ) ) ? (int) $status['exitcode'] : -1;
                if ( $code < 0 ) { $code = 255; } // tué par signal ou statut indisponible : jamais 0 par défaut.
                $out['exit_code'] = $code; $out['ok'] = ( 0 === $code );
                $stdout = str_replace( array( "\r\n", "\r" ), "\n", $stdout );
                $out['output']    = ( '' === $stdout ) ? array() : explode( "\n", rtrim( $stdout, "\n" ) );
                if ( ! $out['ok'] ) { $out['reason'] = 'exit-' . $code; }
                self::journal( $key, (string) $built['path'], 'EXEC:' . $code, $ms, $pin, self::summarize( $args ) );
                return $out;
        }

        /**
         * Validation stricte d'un chemin d'entrée (file) ou de cible (target) :
         * strictement sous wp-content/uploads, segments sûrs, extension autorisée,
         * existence + confinement realpath (entrée), parent confiné + refus des
         * liens symboliques (cible).
         */
        private static function validate_path( $value, $kind, & $why = '' ) {
                if ( ! is_string( $value ) || '' === $value ) {
                        return false;
                }
                if ( false !== strpos( $value, "\0" ) || false !== strpos( $value, "\n" ) || false !== strpos( $value, "\r" ) ) {
                        return false;
                }
                $up   = wp_get_upload_dir();
                $base = isset( $up['basedir'] ) ? wp_normalize_path( (string) $up['basedir'] ) : '';
                if ( '' === $base ) {
                        return false;
                }
                $norm = wp_normalize_path( $value );
                if ( 0 !== strpos( $norm, $base . '/' ) ) {
                        return false; // strictement sous wp-content/uploads (CDC §3.4)
                }
                $rel = substr( $norm, strlen( $base ) + 1 );
                if ( '' === $rel ) {
                        return false;
                }
                $name = $rel; $suffix = '';
                if ( 'target' === $kind && preg_match( self::VIPS_SUFFIX, $name, $m ) ) {
                        $suffix = $m[0];
                        $name   = substr( $name, 0, - strlen( $suffix ) );
                }
                if ( 'target' === $kind ) {
                        // Lien symbolique sur la cible : le binaire écrirait AU-TRAVERS
                        // (le realpath de la cible n'est jamais contrôlé). Test sur le
                        // chemin PHYSIQUE, AVANT le reste — le suffixe [Q=n], interprété
                        // par vips, n'existe pas sur le disque.
                        $phys = ( '' === $suffix ) ? $value : substr( $value, 0, - strlen( $suffix ) );
                        if ( @is_link( $phys ) ) { $why = 'cible-lien'; return false; }
                }
                $segments = explode( '/', $name );
                $fileseg  = array_pop( $segments );
                foreach ( $segments as $seg ) {
                        if ( ! preg_match( self::NAME_PATTERN, $seg ) ) {
                                return false;
                        }
                }
                if ( ! preg_match( self::NAME_PATTERN, $fileseg ) ) {
                        return false; // aucun métacaractère, aucun séparateur, dans le nom
                }
                $dot     = strrpos( $fileseg, '.' );
                $ext     = ( false !== $dot ) ? strtolower( substr( $fileseg, $dot + 1 ) ) : '';
                $allowed = ( 'file' === $kind ) ? self::INPUT_EXTENSIONS : self::TARGET_EXTENSIONS;
                if ( ! in_array( $ext, $allowed, true ) ) {
                        return false;
                }
                if ( 'file' === $kind ) {
                        if ( ! is_file( $value ) || ! is_readable( $value ) ) {
                                return false;
                        }
                        $real = wp_normalize_path( (string) realpath( $value ) );
                        if ( 0 !== strpos( $real, $base . '/' ) ) {
                                return false;
                        }
                } else {
                        $parent = dirname( $norm );
                        if ( ! is_dir( $parent ) ) {
                                return false;
                        }
                        $realparent = wp_normalize_path( (string) realpath( $parent ) );
                        if ( 0 !== strpos( $realparent, $base . '/' ) ) {
                                return false;
                        }
                }
                return escapeshellarg( $value );
        }

        /** Résumé sûr des arguments (noms de fichiers seuls, drapeaux). */
        private static function summarize( $args ) {
                $parts = array();
                if ( is_array( $args ) ) {
                        foreach ( $args as $arg ) {
                                if ( ! is_array( $arg ) || ! isset( $arg['type'], $arg['value'] ) || ! is_string( $arg['value'] ) ) {
                                        continue;
                                }
                                if ( 'flag' === $arg['type'] ) {
                                        $parts[] = $arg['value'];
                                } else {
                                        $parts[] = basename( $arg['value'] );
                                }
                        }
                }
                return implode( ' ', array_slice( $parts, 0, 6 ) );
        }

        /** Journalisation (invocations et refus), rotation à 256 Kio. */
        private static function journal( $key, $path, $state, $ms, $pin, $summary ) {
                $file = self::journal_path();
                if ( ! is_string( $file ) ) {
                        return;
                }
                $dir = dirname( $file );
                if ( ! is_dir( $dir ) ) {
                        @mkdir( $dir, 0755, true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_directory_operations
                }
                if ( is_file( $file ) && (int) @filesize( $file ) > self::JOURNAL_MAX_OCTETS ) {
                        $keep = array_slice( (array) @file( $file, FILE_IGNORE_NEW_LINES ), -100 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_read
                        @file_put_contents( $file, implode( "\n", $keep ) . "\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_write
                }
                $line = implode( "\t", array( gmdate( 'c' ), $key, $path, $pin, $state, (string) $ms, $summary ) ) . "\n";
                @file_put_contents( $file, $line, FILE_APPEND | LOCK_EX ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_write
        }

        /** Réinitialise le cache de résolution (rejeu des contrats). */
        public static function reset_runtime_cache() {
                self::$resolved = array();
        }
}
