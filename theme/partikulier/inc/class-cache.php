<?php
/**
 * Module : cache de page fichier (pas LiteSpeed, pas de plugin cache).
 *
 * - Cache les pages publiques (GET) en fichiers HTML dans uploads/partikulier-cache/
 * - Sert le fichier directement au boot (avant le chargement complet de WP)
 * - Purge : toutes les pages cachees a la modification d'une annonce/terme/page
 * - Headers de compression : on sert le fichier tel quel si le serveur le compresse (mod_deflate/brotli),
 *   sinon on genere aussi une variante .gz
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Cache {

        const TTL        = 43200; // 12 h par defaut
        const DIR_NAME   = 'partikulier-cache';

        /**
         * Hook lance tres tot : on intercepte avant meme le bootstrap complet.
         */
        public static function init() {
                        // Un thème est chargé après plugins_loaded : after_setup_theme est le
                        // premier hook fiable disponible pour servir un cache de thème.
                        add_action( 'after_setup_theme', array( __CLASS__, 'maybe_serve_cached' ), -999 );

                // Enregistrement du buffer de sortie pour generer le cache.
                add_action( 'template_redirect', array( __CLASS__, 'start_caching' ), -1 );

                // Hooks de purge.
                foreach ( array( 'save_post', 'delete_post', 'transition_post_status', 'edited_term', 'create_term', 'delete_term', 'switch_theme', 'wp_update_nav_menu', 'customize_save_after' ) as $hook ) {
                        add_action( $hook, array( __CLASS__, 'purge_all' ) );
                }
                        // Purge aussi après une mise à jour du site dans l’admin.
                        add_action( 'wp_trash_post', array( __CLASS__, 'purge_all' ) );

                        // Les options peuvent être créées via add_option() : le hook
                        // update_option_* ne se déclenche alors pas. Les deux familles sont
                        // donc obligatoires pour éviter de servir une ancienne home en cache.
                        foreach ( array( 'pk_customization_options', 'pk_theme_options' ) as $option ) {
                                add_action( 'add_option_' . $option, array( __CLASS__, 'purge_all' ) );
                                add_action( 'update_option_' . $option, array( __CLASS__, 'purge_all' ) );
                        }
        }

        /**
         * Le plus tot possible : sert le fichier HTML cache si disponible.
         */
                public static function maybe_serve_cached() {
                        // La racine dépend de la langue/cookie/UA et ne doit jamais être servie
                        // depuis le cache fichier partagé.
                        if ( ( self::is_root_request() && ! self::root_is_cacheable() ) || ! self::is_cacheable_request() ) {
                        return;
                }

                $cache_file = self::cache_path();
                $gz_file    = $cache_file . '.gz';

                        if ( file_exists( $cache_file ) && ( time() - filemtime( $cache_file ) ) < self::TTL ) {
                        /* Auto-guerison du cote du SERVEUR, pas seulement de l'ecriture : une entree
                           ecrite par une version precedente (page troncquee, ecran de panne rendu
                           avec un code 200, corps de quelques octets) ne doit jamais sortir d'ici.
                           `exit()` dans un gabarit ne declenche pas le buffer de sortie, donc la
                           purge faite dans store_cache() n'aurait jamais lieu : le controle est ici. */
                        if ( self::entry_is_poisoned( $cache_file ) ) {
                                wp_delete_file( $cache_file );
                                wp_delete_file( $cache_file . '.gz' );
                                wp_delete_file( $cache_file . '.br' );
                                error_log( 'Partikulier Cache: entree invalide supprimee a la lecture ' . $cache_file );
                                return;
                        }
                                // Le cache court-circuite send_headers : réémettre les défenses HTTP
                                // publiques afin qu’une réponse HIT ne soit jamais moins protégée.
                                if ( class_exists( 'Partikulier_Security' ) ) {
                                        Partikulier_Security::send_public_headers();
                                }
                                /* Le HIT court-circuite 'send_headers' : le module de durcissement du plugin
                                   coeur (header_remove) ne s'exécute jamais sur cette réponse. Mesuré sur le
                                   staging : le rapport disait « X-Powered-By ABSENT » alors que la page servie en
                                   HIT le renvoyait. Le fichier en cache porte donc les en-têtes de son visiteur
                                   d'origine : on refiltre ici, sinon le cache redevient le chemin qui fuit. */
                                header_remove( 'X-Powered-By' );
                                header_remove( 'X-Generator' );
                                header( 'X-Partikulier-Cache: HIT' );
                                header( 'Content-Type: text/html; charset=UTF-8' );
                                header( 'Cache-Control: public, max-age=' . self::TTL );
                                header( 'Vary: Accept-Encoding', false );
                        // Compression si le navigateur l'accepte et que la variante existe.
                        $accept = isset( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_ENCODING'] ) ) : '';
                        if ( false !== strpos( $accept, 'br' ) && function_exists( 'brotli_uncompress' ) && file_exists( $cache_file . '.br' ) ) {
                                header( 'Content-Encoding: br' );
                                readfile( $cache_file . '.br' );
                                exit;
                        }
                        if ( false !== strpos( $accept, 'gzip' ) && file_exists( $gz_file ) ) {
                                header( 'Content-Encoding: gzip' );
                                readfile( $gz_file );
                                exit;
                        }
                        readfile( $cache_file );
                        exit;
                }
        }

        /**
         * Demarre la capture de sortie pour generer le fichier cache.
         */
                public static function start_caching() {
                        if ( ( self::is_root_request() && ! self::root_is_cacheable() ) || ! self::is_cacheable_request() ) {
                                return;
                        }
                        // Les variantes localisees sont immuables par URL et restent publiques.
                        header( 'Cache-Control: public, max-age=' . self::TTL );
                        header( 'Vary: Accept-Encoding', false );
                        ob_start( array( __CLASS__, 'store_cache' ) );
        }

        /**
         * Stocke le HTML dans le fichier de cache (+ variantes compressees).
         */
        public static function store_cache( $html ) {
                // Une reponse qui redirige n'a aucun contenu a cacher : la stocker
                // transforme la redirection en page blanche servie en HIT (mesure :
                // fichier de 0 octet, X-Partikulier-Cache: HIT, plus aucun Location).
                $pk_code = (int) http_response_code();
                if ( $pk_code >= 300 && $pk_code < 400 ) {
                        return $html;
                }
                foreach ( headers_list() as $pk_header ) {
                        if ( 0 === stripos( $pk_header, 'Location:' ) ) {
                                return $html;
                        }
                }
                // Ne pas cacher une page avec la barre d'admin ou des erreurs.
                if ( is_admin_bar_showing() || http_response_code() >= 400 || self::response_sets_cookie() || '' === trim( (string) $html ) ) {
                        return $html;
                }
                /* Le code HTTP ne suffit pas : mesuré en sortie d'ouvreur, un pageage mort
                   (`exit(500)` dans un gabarit, ecran « There has been a critical error on
                   this website », page d'erreur de l'hebergeur de 2 507 o sur le staging) est
                   parfois rendu avec un code 200 — et 46 pages de ce type ont traverse le
                   rapport. Une telle page cachee devient la page officielle du site pendant
                   tout le TTL. On controle donc LE CONTENU, pas seulement le code :
                     - une page HTML cachee doit ressembler a une page HTML ;
                     - elle doit avoir une taille minimale (le tronc d'erreur fait des octets) ;
                     - elle ne doit pas porter l'ecran de panne de WordPress ;
                     - si une entree perimee existe deja et qu'elle porte l'un de ces symptomes,
                       on la supprime au lieu de la servir (auto-guérison du poison deja ecrit). */
                $pk_corps = (string) $html;
                if ( ! preg_match( '~<(?:!doctype\s+html|html[\s>])~i', $pk_corps )
                        || strlen( $pk_corps ) < 500
                        || false !== stripos( $pk_corps, 'There has been a critical error' )
                        || false !== stripos( $pk_corps, 'critical error on this website' ) ) {
                        $pk_rejet = self::cache_path();
                        if ( is_readable( $pk_rejet ) && ( filesize( $pk_rejet ) < 500 || ! preg_match( '~<(?:!doctype\s+html|html[\s>])~i', (string) @file_get_contents( $pk_rejet, false, null, 0, 4096 ) ) ) ) {
                                wp_delete_file( $pk_rejet );
                                wp_delete_file( $pk_rejet . '.gz' );
                                wp_delete_file( $pk_rejet . '.br' );
                                error_log( 'Partikulier Cache: entree perimee supprimee (page tronquee ou ecran de panne) ' . $pk_rejet );
                        }
                        error_log( 'Partikulier Cache: ecriture refusee, reponse non cacheable (' . strlen( $pk_corps ) . ' o, code ' . (int) http_response_code() . ') pour ' . ( isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '?' ) );
                        return $html;
                }

                $cache_file = self::cache_path();
                $dir = dirname( $cache_file );
                $cache_dir_exists = is_dir( $dir );
                if ( ! $cache_dir_exists ) {
                        wp_mkdir_p( $dir );
                }
                // Le mode reference de la CI fait tourner le pool php-fpm en www-data alors que la
                // recette (install.sh) cree le dossier sous le user du runner : is_dir() est vrai,
                // donc ni mkdir ni chmod ne sont tentes, et file_put_contents echoue en silence.
                // On teste desormais l'ecrivabilite, pas seulement l'existence.
                if ( ! is_writable( $dir ) ) {
                        @chmod( $dir, 0777 );
                }
                if ( ! is_writable( $dir ) ) {
                        error_log( 'Partikulier Cache: dossier non ecrivable ' . $dir . ' (euid=' . getmyuid() . ' perms=' . substr( sprintf( '%o', fileperms( $dir ) & 0777 ), -4 ) . ') : cache desactive pour cette requete' );
                        return $html;
                }
                if ( ! $cache_dir_exists ) {
                        // Bloquer l'acces direct par .htaccess.
                        $htaccess = $dir . '/.htaccess';
                        if ( ! file_exists( $htaccess ) ) {
                                @file_put_contents( $htaccess, "Require all denied\nDeny from all\n" );
                        }
                        // Index vide anti-listing.
                        @file_put_contents( $dir . '/index.html', '' );
                }

                $pk_written = @file_put_contents( $cache_file, $html );
                if ( false === $pk_written && function_exists( 'error_get_last' ) ) {
                        $pk_err = error_get_last();
                        error_log( 'Partikulier Cache: ecriture refusee ' . $cache_file . ' (' . ( $pk_err['message'] ?? 'inconnu' ) . ') dir=' . $dir . ' perms=' . substr( sprintf( "%o", fileperms( $dir ) & 0777 ), -4 ) . ' euid=' . getmyuid() );
                }
                if ( $pk_written !== false ) {
                        if ( function_exists( 'gzencode' ) ) {
                                @file_put_contents( $cache_file . '.gz', gzencode( $html, 5 ) );
                        }
                        if ( function_exists( 'brotli_compress' ) ) {
                                @file_put_contents( $cache_file . '.br', brotli_compress( $html, 5 ) );
                        }
                        // Purge apres TOUTES les ecritures, en excluant la cle en cours : un
                        // filemtime() en retard sur time() (FS a resolution 1 s, overlayfs du
                        // runner rendant 0, horloge de conteneur) ne doit pas faire disparaitre
                        // l'entree fraiche — symptome : MISS permanents en CI, PASS en local.
                        self::prune_expired( $dir, $cache_file );
                }

                header( 'X-Partikulier-Cache: MISS' );
                return $html;
        }

        /**
         * Purge tout le repertoire de cache.
         */
        /**
         * Demande de purge au cache de l'hebergeur (LiteSpeed), quand il existe.
         *
         * Le visiteur ne voit pas nos fichiers : il voit la copie que le cache du serveur
         * lui sert. Sans demande explicite, notre purge est reussie et le visiteur recoit
         * quand meme l'ancienne page — et le diagnostic conclut alors, a tort, que le
         * cache du theme ne sert rien (exactement le « purge NON APPLIQUEE » du rapport
         * d'un autre outil sur le meme hebergeur).
         *
         * Trois voies, dans l'ordre de fiabilite : l'API du plugin si elle est chargee,
         * l'en-tete de purge si le module serveur l'interprete, et rien sinon — le
         * rapport nomme ce qui a ete fait au lieu de le laisser deviner.
         *
         * Public : le module de diagnostic le rapporte, et un clic « purger » doit
         * pouvoir l'appeler sans passer par une reflexion sur du prive.
         *
         * @return string Ce qui a ete fait.
         */
        public static function purge_host_cache() {
                $fait = array();
                if ( function_exists( 'litespeed_purge_all' ) ) {
                        litespeed_purge_all();
                        $fait[] = 'litespeed_purge_all()';
                }
                if ( function_exists( 'litespeed_purge' ) ) {
                        litespeed_purge( '/' );
                        $fait[] = 'litespeed_purge("/")';
                }
                if ( has_action( 'litespeed_purge_all' ) ) {
                        do_action( 'litespeed_purge_all' );
                        $fait[] = 'action litespeed_purge_all';
                }
                if ( ! headers_sent() ) {
                        header( 'X-LiteSpeed-Purge: *' );
                        $fait[] = 'en-tete X-LiteSpeed-Purge';
                }
                if ( ! $fait ) {
                        return "aucun cache hebergeur detecte (ni API ni en-tete) : la purge du theme suffit";
                }
                return implode( ' + ', array_unique( $fait ) );
        }

        public static function purge_all() {
                $upload = wp_get_upload_dir();
                $dir    = trailingslashit( $upload['basedir'] ) . self::DIR_NAME;
                if ( ! is_dir( $dir ) ) {
                /* On previent l'hebergeur AVANT de vider nos fichiers : si le script
                   s'arrete entre les deux, le visiteur ne reste pas sur une page perimee. */
                self::purge_host_cache();
                        return;
                }
                $files = glob( $dir . '/*.html' );
                if ( $files ) {
                        foreach ( $files as $f ) {
wp_delete_file( $f );
                                                wp_delete_file( $f . '.gz' );
                                                wp_delete_file( $f . '.br' );
                        }
                }
        }

        /**
         * Exclut les parcours privés et les endpoints techniques dès
         * after_setup_theme, moment où la requête WordPress n’est pas encore
         * systématiquement disponible pour is_page().
         */
        /**
         * Purge les entrees plus anciennes que deux TTL. Appelée sur les écritures
         * seulement : le dossier ne peut plus grossir indefiniment (variantes d'URL,
         * slugs renames, cles nees d'un Host etranger).
         */
        private static function prune_expired( $dir, $keep = '' ) {
                $now     = time();
                $horizon = $now - ( 2 * self::TTL );
                foreach ( (array) glob( $dir . '/*.html*' ) as $f ) {
                        if ( '' !== $keep && 0 === strpos( $f, $keep ) ) {
                                continue;
                        }
                        $m = (int) @filemtime( $f );
                        if ( is_file( $f ) && $m > 0 && $m < $now && $m < $horizon ) {
                                wp_delete_file( $f );
                        }
                }
        }

        private static function is_private_path() {
                $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
                $path = trim( (string) $path, '/' );
                if ( in_array( $path, array( 'sitemap.xml', 'robots.txt', 'xmlrpc.php' ), true ) ) {
                        return true;
                }
                /* Mesure sur banc (WordPress sert tout, pas d'Apache devant) : une URL
                   interne comme /wp-content/uploads/partikulier-cache/ peut etre resolue
                   en 200 par WordPress (copie de la home) — et le module la CACHAIT puis la
                   servait : 53 606 o servis sur l'URL du dossier de cache. Aucune page
                   publique ne vit sous wp-content/ ou wp-includes/ : on exclut tout le
                   prefixe, pas seulement quelques fichiers connus. */
                if ( 0 === strpos( $path, 'wp-content/' ) || 0 === strpos( $path, 'wp-includes/' ) ) {
                        return true;
                }
                // Les pages de dépôt et d’espace personnel peuvent être préfixées par
                // Polylang (`fr/`, `en/`, `ar/`) et ne doivent jamais devenir publiques.
                if ( preg_match( '#(?:^|/)(?:deposer(?:-une-annonce|-annonce|-en|-ar)?|mes-annonces(?:-en|-ar)?|favoris(?:-en|-ar)?)(?:/|$)#', $path ) ) {
                        return true;
                }
                return 0 === strpos( $path, 'wp-admin/' ) || 0 === strpos( $path, 'wp-json/' );
        }

        /**
         * Chemin du fichier cache pour la requete courante.
         */
        private static function cache_path() {
                $upload = wp_get_upload_dir();
                $dir    = trailingslashit( $upload['basedir'] ) . self::DIR_NAME;

                $host_raw = isset( $_SERVER['HTTP_HOST'] ) ? strtolower( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) ) : '';
                // La cle ne doit jamais etre derivee d'un en-tete Host libre : un hote
                // arbitraire cree une entree etrangere (mesure : 10 hotes = 20 fichiers,
                // puis un HIT sans Location sur ces hotes). Seuls l'hote de home_url() et
                // ceux declares (constante ou filtre) sont des cles valides.
                $allowed = array( strtolower( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) ) );
                foreach ( preg_split( '/[\s,]+/', (string) ( defined( 'PK_ALLOWED_CACHE_HOSTS' ) ? constant( 'PK_ALLOWED_CACHE_HOSTS' ) : '' ) ) as $extra ) {
                        if ( '' !== $extra ) {
                                $allowed[] = $extra;
                        }
                }
                $allowed = array_values( array_unique( (array) apply_filters( 'partikulier_cache_allowed_hosts', $allowed ) ) );
                // home_url() ne porte pas toujours le port (localhost vs localhost:8090) : sans normalisation,
                // toute install sur port explicite retombe sur la cle 'default' et partage une entree unique
                // (mesure : fichier default_fr_annonces.html alors que HTTP_HOST=localhost:8090).
                $pk_name = static function ( $h ) {
                        $p = wp_parse_url( 'http://' . $h, PHP_URL_HOST );
                        return is_string( $p ) ? strtolower( $p ) : '';
                };
                $pk_allowed = array_unique( array_map( $pk_name, $allowed ) );
                // Le nom du fichier reste sanitize (un 'host:port' contenant ':' est risueux sur
                // certains FS/backup) : la cle conserve le port, mais sans caracteres speciaux.
                $pk_ok      = ( '' !== $pk_name( $host_raw ) && in_array( $pk_name( $host_raw ), $pk_allowed, true ) );
                $host       = $pk_ok ? preg_replace( '/[^a-z0-9.\-]/i', '', $host_raw ) : 'default';
                $uri  = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';
                /* La cle doit suivre le chemin REEL de la requete. L'ancien repli
                   « '/' => index » ne visait que la racine, mais comme is_root_request()
                   comparait a « / » brut, /fr/, /en/, /ar/ (Polylang, hide_default = false)
                   etaient traites en racine : leurs trois fichiers n'en faisaient qu'un, et
                   la premiere visiteuse francophone decide de la langue servie aux autres.
                   Le chemin est desormais la cle, integral : deux langues = deux entrees.
                   « index » reste le nom de la vraie home, pour ne pas casser les entrees
                   dejà ecrites. */
                $uri = trim( str_replace( array( '..', '/' ), array( '', '_' ), (string) $uri ), '_' );
                $uri = '' === $uri ? 'index' : $uri;
                $lang = '';
                if ( defined( 'WPLANG' ) && WPLANG ) {
                        $lang = WPLANG . '_';
                }
                return rtrim( $dir, '/' ) . '/' . $lang . $host . '_' . $uri . '.html';
        }

        /**
         * La requete courante est-elle cachable ?
         */
                private static function is_root_request() {
                        $path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
                        /* Comparer a « / » seul declarait racine toute URL finissant par une barre :
                           avec Polylang (hide_default = false), /fr/, /en/ et /ar/ tombaient dans ce
                           cas et la home localisee etait exclue du cache. On compare desormais au
                           chemin reel du site (home_url()), qui porte le prefixe de langue quand
                           WordPress est installe dans un sous-dossier, mais jamais les langues. */
                        $site = trailingslashit( (string) ( ( $p = wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ) ?: '/' ) );
                        return $site === trailingslashit( (string) $path );
                }

        /**
         * La racine ( '/' ) etait exclue du cache fichier par principe : elle depend de
         * la langue, d'un eventuel cookie ou du user-agent. Cette exclusion totale etait
         * plus couteuse que le risque qu'elle couvrait (mesure sur WordPress 7.1 avec le
         * theme livre) : le module n'ECRIVAIT aucun fichier pour '/' — pas de variante
         * '.html' dans wp-content/uploads/partikulier-cache — et la page la plus vue du
         * portail (tout le trafic des reseaux sociaux arrive sur '/') etait donc generee
         * a chaque visite, y compris pendant la pointe de 10 000 visites en 2 heures.
         *
         * La racine n'est ambigue que si Polylang choisit la langue a la place de l'URL :
         * detection automatique par le navigateur (option « browser »), redirection
         * conditionnelle (« redirect_lang »), ou absence de prefixe force (« force_lang »
         * a 0 ou 2 quand un cookie peut trancher). Ailleurs, '/' rend un HTML stable, et
         * les autres garde-fous jouent deja : requete sans parametre, visiteur deconnecte,
         * verbe GET, aucun cookie de parcours pose (response_sets_cookie), reponse 200.
         *
         * @return bool true quand la racine peut etre ecrite puis servie par le cache.
         */
        private static function root_is_cacheable() {
                $options = get_option( 'polylang', array() );
                if ( ! is_array( $options ) || ! defined( 'POLYLANG_VERSION' ) ) {
                        // Polylang absent : '/' rend le contenu par defaut de WordPress, sans
                        // ambiguite de langue. Les langues restent joignables par /fr/, /en/, /ar/.
                        return true;
                }
                $force_lang    = isset( $options['force_lang'] ) ? (int) $options['force_lang'] : 0;
                $browser       = ! empty( $options['browser'] );
                $redirect_lang = ! empty( $options['redirect_lang'] );
                return $force_lang >= 1 && ! $browser && ! $redirect_lang;
        }

                private static function is_cacheable_request() {

                if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'DONOTCACHEPAGE' ) && DONOTCACHEPAGE ) ) {
                        return false;
                }
                // Le verbe HTTP est en majuscules par la RFC 9110 : sanitize_key() le
                // minuscule et rend ce test toujours vrai, donc le module n'est jamais
                // execute (regression introduite par le commit 4038040).
                $method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( (string) $_SERVER['REQUEST_METHOD'] ) : '';
                if ( 'GET' !== $method ) {
                        return false;
                }
                if ( ! empty( $_GET ) ) {
                        return false;
                }
                if ( is_user_logged_in() || ! empty( $_SERVER['HTTP_AUTHORIZATION'] ) ) {
                        return false;
                }
                if ( self::is_private_path() || is_page( array( 'deposer', 'deposer-en', 'deposer-ar', 'deposer-une-annonce', 'deposer-annonce', 'mes-annonces', 'mes-annonces-en', 'mes-annonces-ar', 'favoris', 'favoris-en', 'favoris-ar', 'connexion', 'connexion-en', 'connexion-ar' ) ) ) {
                        return false;
                }
                $cookie_header = isset( $_SERVER['HTTP_COOKIE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_COOKIE'] ) ) : '';
                if ( $cookie_header && preg_match( '/(?:wordpress_logged_in|wordpress_sec|wp-postpass|comment_author|pk_v_)=/i', $cookie_header ) ) {
                        return false;
                }
                // Ne pas cacher les flux RSS, sitemap deja servi a part.
                if ( is_feed() || is_robots() || is_favicon() ) {
                        return false;
                }
                // Les pages de recherche avec parametres ne sont pas cachees (GET non vide deja exclu).
                return true;
        }

        /**
         * Une entree de cache est-elle un poison (page troncquee, ecran de panne, corps
         * qui n'est pas du HTML) ? On ne se fie ni au code HTTP ni a la date : sur
         * l'ouvreur mesure, une page morte sort avec le code 200.
         *
         * @param string $fichier Chemin du .html de cache.
         * @return bool
         */
        private static function entry_is_poisoned( $fichier ) {
                $taille = @filesize( $fichier );
                if ( false === $taille || $taille < 500 ) {
                        return true;
                }
                $debut = (string) @file_get_contents( $fichier, false, null, 0, 4096 );
                if ( ! preg_match( '~<(?:!doctype\s+html|html[\s>])~i', $debut ) ) {
                        return true;
                }
                return false !== stripos( $debut, 'There has been a critical error' )
                        || false !== stripos( $debut, 'critical error on this website' );
        }

        /**
         * Un HTML qui initialise une session ou un cookie de parcours ne doit jamais
         * devenir une réponse publique partagée.
         */
        private static function response_sets_cookie() {
                foreach ( headers_list() as $header ) {
                        if ( 0 !== stripos( $header, 'Set-Cookie:' ) ) {
                                continue;
                        }
                        // La langue est déjà portée par l’URL /fr/, /en/ ou /ar/.
                        // Ce cookie Polylang ne rend donc pas le HTML partagé variable.
                        if ( preg_match( '/^Set-Cookie:\s*pll_language=/i', $header ) ) {
                                continue;
                        }
                        return true;
                }
                return false;
        }
}

Partikulier_Cache::init();