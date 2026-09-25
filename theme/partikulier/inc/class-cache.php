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

		const TTL      = 43200; // 12 h par defaut
		const DIR_NAME = 'partikulier-cache';

		/** Version de ce module : changer cette valeur declenche UNE purge complete au
		 *  premier passage HTTP qui suit la mise a jour (entrees ecrites par la version
		 *  precedente). Voir maybe_purge_after_update(). */
		const CACHE_VERSION = '2026-09-25-1';

		/** Une seule demande de purge hote par requete PHP : plusieurs hooks de purge
		 *  peuvent se declencher dans la meme requete (save_post, edited_term, ...). */
	private static $purge_host_envoyee = false;

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

		// Demande de purge adressee par le site a lui-meme (voir ask_host_purge).
		// La reponse de cette adresse est cacheable : c'est la seule voie mesuree
		// comme fiable depuis une transition (reponse JSON, non cacheable).
		add_action( 'init', array( __CLASS__, 'maybe_handle_purge_request' ), 1 );

		// Correctif A, partie 2 : les pages privees (depot, espace personnel, favoris,
		// connexion) ne doivent jamais entrer dans le cache de pages de l'hebergeur.
		add_action( 'init', array( __CLASS__, 'exclude_private_pages_from_host_cache' ), 2 );

		// Purge unique apres mise a jour (voir maybe_purge_after_update).
		add_action( 'init', array( __CLASS__, 'maybe_purge_after_update' ), 3 );
		add_action( 'pk_purge_host_later', array( __CLASS__, 'run_deferred_purge' ) );
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
		 * Correctif A, partie 2 — pages privees hors du cache de pages de l'hebergeur.
		 *
		 * Mesure du 25/09 sur serveur LiteSpeed reel : si la copie publique d'une page
		 * privee (par exemple « mes annonces » vue par un visiteur) entre dans le cache
		 * du serveur, elle est ensuite servie a un proprietaire connecte dont le cookie
		 * de variation du cache est absent ou expire : sa page s'affiche vide alors que
		 * la base de donnees est correcte. Aucune donnee privee ne fuit (c'est la version
		 * publique qui est servie), mais la fonction est cassee jusqu'au rechargement.
		 *
		 * L'appel ci-dessous demande au serveur de NE PAS conserver ces pages : elles ne
		 * sont donc jamais servies depuis le cache, pour personne. Sans plugin LiteSpeed,
		 * do_action() ne fait rien : aucun effet de bord.
		 */
		/**
		 * Purge unique apres mise a jour du module de cache.
		 *
		 * Une entree ecrite par la version precedente survit a la correction du code :
		 * mesure du 25/09, la page privee deja presente dans le cache du serveur
		 * continuait d'etre servie apres la correction. La purge ci-dessous a lieu une
		 * seule fois (la version est enregistree avant l'appel), et jamais depuis
		 * WP-CLI ni depuis le cron : la purge a besoin d'une requete HTTP reelle.
		 */
		public static function maybe_purge_after_update() {
			if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
				return;
			}
			if ( self::CACHE_VERSION === (string) get_option( 'pk_cache_version', '' ) ) {
				return;
			}
			update_option( 'pk_cache_version', self::CACHE_VERSION, false );
			self::purge_all();
		}

		public static function exclude_private_pages_from_host_cache() {
			$path = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '';
			$path = trim( (string) $path, '/' );
			if ( ! preg_match( '#(?:^|/)(?:deposer(?:-une-annonce|-annonce|-en|-ar)?|mes-annonces(?:-en|-ar)?|favoris(?:-en|-ar)?|connexion(?:-en|-ar)?)(?:/|$)#', $path ) ) {
				return;
			}
			do_action( 'litespeed_control_set_nocache', 'page privee Partikulier' );
			if ( ! headers_sent() ) {
				header( 'X-LiteSpeed-Cache-Control: no-cache' );
				header( 'Cache-Control: private, no-store, max-age=0' );
			}
		}

		/**
		 * Demarre la capture de sortie pour generer le fichier cache.
		 */
	public static function start_caching() {
		if ( ( self::is_root_request() && ! self::root_is_cacheable() ) || ! self::is_cacheable_request() ) {
						return;
		}
			// SE-043 (E-4302) : jamais de cache des échecs. store_cache()
			// refusait déjà d'ÉCRIRE les >= 400, mais l'en-tête public partait
			// avant même que le statut soit connu (R1 : échecs géo rendus en 200
			// avec Cache-Control: public + fichier en cache). Les échecs sont
			// résolus dès parse_request ; le 410 sort avant ce hook
			// (emit_listing_status à -3), le 404 est intercepté ici.
		if ( is_404() || (int) get_query_var( 'pk_listing_gone' ) > 0 ) {
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

			$cache_file       = self::cache_path();
			$dir              = dirname( $cache_file );
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
		// Voie 1 : API du plugin LiteSpeed chargee dans ce processus (v7 : classe).
		if ( class_exists( 'LiteSpeed\Purge' ) && method_exists( 'LiteSpeed\Purge', 'purge_all' ) ) {
			LiteSpeed\Purge::purge_all( 'partikulier' );
			$fait[] = 'LiteSpeed\Purge::purge_all()';
		}
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
		// Voie 2 : en-tete direct. N'aboutit que si la reponse courante est cacheable.
		if ( ! headers_sent() ) {
			header( 'X-LiteSpeed-Purge: *' );
			$fait[] = 'en-tete X-LiteSpeed-Purge';
		}
		// Voie 3 (fiable) : le site demande la purge a lui-meme, sur une reponse cacheable.
		// Mesure du 25/09 : sans elle, le visiteur garde l'ancienne page du cache serveur
		// (jusqu'a 7 jours) et les voies 1 et 2 ne le corrigent pas de facon sure.
		$fiable = self::ask_host_purge();
		if ( '' !== $fiable ) {
			$fait[] = $fiable;
		}
		if ( ! $fait ) {
			return "aucun cache hebergeur detecte (ni API ni en-tete) : la purge du theme suffit";
		}
		return implode( ' + ', array_unique( $fait ) );
	}

	/**
	 * Le site demande la purge a lui-meme par une requete non bloquante vers une
	 * adresse cacheable (voir maybe_handle_purge_request). Un site sans cache de
	 * pages serveur ne paie rien : la demande n'est envoyee que si le serveur
	 * s'annonce LiteSpeed (ou si le plugin LiteSpeed est charge).
	 *
	 * @return string Ce qui a ete fait.
	 */
	public static function ask_host_purge() {
		if ( self::$purge_host_envoyee ) {
			return 'demande de purge deja envoyee dans cette requete';
		}
		if ( ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'DOING_CRON' ) && DOING_CRON ) ) {
			return 'demande de purge ignoree (contexte CLI/cron)';
		}
		// Serveur de developpement mono-processus de PHP (`php -S`), utilise par la CI
		// et par les rejeux hors hebergeur : la requete interne ne peut PAS aboutir, le
		// processus qui devrait y repondre etant celui qui l emet. On ne l emet donc
		// pas ici (mesure du 25/09 : requete figee ~2 s, suite de contrats interrompue).
		if ( 'cli-server' === php_sapi_name() ) {
			return 'demande de purge ignoree (serveur de developpement mono-processus)';
		}
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return 'demande de purge ignoree (sauvegarde automatique)';
		}
		$serveur   = isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : '';
		$litespeed = ( false !== stripos( $serveur, 'litespeed' ) )
			|| class_exists( 'LiteSpeed\Purge' )
			|| function_exists( 'litespeed_purge_all' )
			|| has_action( 'litespeed_purge_all' );
		if ( ! $litespeed || ! function_exists( 'wp_remote_get' ) ) {
			return '';
		}
		self::$purge_host_envoyee = true;
		$jeton = wp_generate_password( 20, false, false );
		set_transient( 'pk_purge_' . $jeton, 1, 2 * MINUTE_IN_SECONDS );
		$url = add_query_arg(
			array(
				'pk_purge' => $jeton,
				'n'        => wp_rand( 1000, 999999 ),
			),
			home_url( '/' )
		);
		/* Variante RETENUE, mesuree le 25/09 : appel BLOQUANT, court, avec verification de
		   la reponse. La variante non bloquante ('blocking' => false, timeout 0.05) a ete
		   mesuree INOPERANTE : le processus rend la main avant que l'appel soit parti, la
		   page de purge n'est jamais servie et le visiteur garde l'ancienne page. Cout
		   mesure avec la variante retenue : environ 200 ms ajoutees a l'action du
		   proprietaire (mediane 885 ms contre 677 ms sans purge). */
		$r = wp_remote_get( $url, array( 'timeout' => 2 ) );
		if ( ! is_wp_error( $r ) && 200 === (int) wp_remote_retrieve_response_code( $r ) ) {
			return 'demande de purge confirmee par le serveur';
		}
		// Filet : la demande n'a pas abouti, on la rejoue une fois via WP-Cron.
		wp_schedule_single_event( time() + 5, 'pk_purge_host_later', array( $url ) );
		return 'demande de purge differee (reponse non confirmee)';
	}

	/**
	 * Filet WP-Cron : rejoue une demande de purge qui n'a pas pu partir.
	 *
	 * @param string $url Adresse de purge a rejouer.
	 */
	public static function run_deferred_purge( $url ) {
		self::$purge_host_envoyee = false;
		if ( is_string( $url ) && '' !== $url && function_exists( 'wp_remote_get' ) ) {
			wp_remote_get( $url, array( 'timeout' => 5 ) );
		}
	}

	/**
	 * Repond a la demande de purge du site. Jeton a usage unique, valable 2 minutes :
	 * personne d'autre ne peut declencher une purge. La reponse est cacheable
	 * (Cache-Control: public, max-age=1) : condition mesuree pour que le cache de
	 * pages du serveur honore l'en-tete de purge qu'elle porte.
	 */
	public static function maybe_handle_purge_request() {
		if ( ! isset( $_GET['pk_purge'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- jeton verifie juste apres
			return;
		}
		$jeton = sanitize_text_field( wp_unslash( $_GET['pk_purge'] ) );
		if ( '' === $jeton || ! get_transient( 'pk_purge_' . $jeton ) ) {
			status_header( 403 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, nofollow' );
			echo 'jeton de purge inconnu ou expire';
			exit;
		}
		delete_transient( 'pk_purge_' . $jeton );
		if ( class_exists( 'LiteSpeed\Purge' ) && method_exists( 'LiteSpeed\Purge', 'purge_all' ) ) {
			LiteSpeed\Purge::purge_all( 'partikulier_ping' );
		}
		header( 'X-LiteSpeed-Purge: *' );
		header( 'Cache-Control: public, max-age=1' );
		header( 'Content-Type: text/plain; charset=utf-8' );
		header( 'X-Robots-Tag: noindex, nofollow' );
		echo 'purge demandee';
		exit;
	}

	public static function purge_all() {
		/* Correctif A, partie 4 (Arena, 25/09) — LA cause racine de la page perimee.
		 *
		 * Le corps d'origine ne prevenait l'hebergeur que si le dossier de cache du
		 * theme N'EXISTAIT PAS :
		 *     if ( ! is_dir( $dir ) ) { self::purge_host_cache(); return; }
		 * Dans le cas normal — dossier present — il supprimait nos fichiers et
		 * n'appelait JAMAIS la purge de l'hebergeur. Mesure du 25/09 : apres une
		 * desactivation, la fiche publique restait « InStock » et l'accueil listait
		 * encore l'annonce, alors que nos propres fichiers, eux, etaient bien
		 * supprimes : c'est le cache de pages du serveur qui repondait.
		 *
		 * Nouvelle forme : purge de l'hebergeur EN PREMIER (tant que sa copie
		 * existe, nos fichiers ne sont meme pas consultes), puis nos fichiers.
		 * Aucune sortie anticipee : les deux purges ont toujours lieu.
		 */
		self::purge_host_cache();
		$upload = wp_get_upload_dir();
		$dir    = trailingslashit( $upload['basedir'] ) . self::DIR_NAME;
		$files  = glob( $dir . '/*.html' );
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
			$pk_name    = static function ( $h ) {
					$p = wp_parse_url( 'http://' . $h, PHP_URL_HOST );
					return is_string( $p ) ? strtolower( $p ) : '';
			};
			$pk_allowed = array_unique( array_map( $pk_name, $allowed ) );
			// Le nom du fichier reste sanitize (un 'host:port' contenant ':' est risueux sur
			// certains FS/backup) : la cle conserve le port, mais sans caracteres speciaux.
			$pk_ok = ( '' !== $pk_name( $host_raw ) && in_array( $pk_name( $host_raw ), $pk_allowed, true ) );
			$host  = $pk_ok ? preg_replace( '/[^a-z0-9.\-]/i', '', $host_raw ) : 'default';
			$uri   = isset( $_SERVER['REQUEST_URI'] ) ? wp_parse_url( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ), PHP_URL_PATH ) : '/';
			/* La cle doit suivre le chemin REEL de la requete. L'ancien repli
				« '/' => index » ne visait que la racine, mais comme is_root_request()
				comparait a « / » brut, /fr/, /en/, /ar/ (Polylang, hide_default = false)
				etaient traites en racine : leurs trois fichiers n'en faisaient qu'un, et
				la premiere visiteuse francophone decide de la langue servie aux autres.
				Le chemin est desormais la cle, integral : deux langues = deux entrees.
				« index » reste le nom de la vraie home, pour ne pas casser les entrees
				dejà ecrites. */
			$uri  = trim( str_replace( array( '..', '/' ), array( '', '_' ), (string) $uri ), '_' );
			$uri  = '' === $uri ? 'index' : $uri;
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
					// SE-020 (E-2004) : verbe HTTP assaini (sanitize_key, repli GET
					// conforme RFC 3875 §4.1.2 — méthode absente = GET). ATTENTION :
					// sanitize_key() MINUSCULE — la comparaison se fait en minuscules
					// ('get'), sinon le test est toujours faux et le module ne
					// s'exécute jamais (régression 4038040 déjà constatée).
					// Le repli ne s'applique qu'aux SAPI de serveur (cli-server,
					// fpm, cgi) : en SAPI « cli » (wp-cli, contrats in-process), le
					// module doit demeurer inactif sinon le cache serait servi puis
					// exit() au milieu d'une commande.
		if ( php_sapi_name() === 'cli' ) {
			return false;
		}
					$method = isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : 'get'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- assaini par sanitize_key (E-2004)
		if ( 'get' !== $method ) {
			return false;
		}
					// SE-020 (E-2007) : test de présence global, aucune valeur lue.
		if ( ! empty( $_GET ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- présence seule (E-2007)
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