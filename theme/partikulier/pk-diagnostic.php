<?php
/**
 * Partikulier — diagnostic intégré au thème (sonde v5).
 *
 * Le theme charge ce module depuis functions.php, donc le TELEVERSEMENT du zip
 * du theme suffit : aucune manipulation FTP n'est necessaire pour l'utiliser.
 *
 * TROIS ENTRIES, UN SEUL MOTEUR DE MESURE (les memes chiffres partout) :
 *
 *  1. ADMIN (le chemin normal pour un non-developpeur)
 *     Tableau de bord -> partikulier -> « Diagnostic complet » : on choisit le
 *     perimetre, on clique, on lit, on telecharge le .txt et le .csv.
 *
 *  2. FICHIER POSE A LA RACINE DU WORDPRESS (CI, hebergeur, SSH)
 *     cp wp-content/themes/partikulier/pk-sonde.php . && php pk-sonde.php
 *     Cle generee toute seule ; `?retirer=1` supprime la cle.
 *
 * CE QUI EST MESURE (rien n'est declare, tout est demande au serveur) :
 *   - TOUTES les pages, pas seulement l'accueil : chaque annonce publiee,
 *     l'archive et sa pagination reelle, toutes les pages normales, toutes les
 *     taxonomies Estatik (villes, types, categories), les pages privees, la
 *     recherche, une 404, les flux, sitemap, robots — et chaque langue connue.
 *   - RESEAUX SOCIAUX et robots : la meme page est redemandee avec l'en-tete
 *     User-Agent de Facebook, X, WhatsApp, Google, Discord, et des robots d'IA
 *     (GPTBot, ClaudeBot, PerplexityBot, Google-Extended, OAI-SearchBot).
 *     Le module compare les corps : une difference = cloaking, nomme.
 *   - SEO : title (longueur), meta description, canonical, hreflang + x-default,
 *     robots/noindex, JSON-LD (types trouves, ItemList vide, bloc non decodable),
 *     Open Graph / Twitter Card, og:image en URL absolue, sitemap.
 *   - LISIBILITE PAR UN LLM : le texte est-il SERVE dans le HTML (pas de JS
 *     requis), nombre de mots visibles, ratio texte/HTML, hierarchie des titres,
 *     date exploitable, donnees structurees citables, acces des robots d'IA.
 *   - CACHE DU THEME : page videe puis froide puis chaude, HIT ou MISS annonce,
 *     entree verifiee SUR DISQUE (taille .html et .gz), corps du HIT compare
 *     octet a octet a la page fraiche, et URLs de campagne (fbclid/gclid/utm/tri).
 *   - PERFORMANCE par page : temps, poids, repartition HTTP, mediane/p95 agreges.
 *   - UI/UX sans navigateur : srcset, lazy-loading, alt, lang/dir (RTL arabe),
 *     viewport, titres, liens a ancre faible, rel=noopener, champs de formulaire
 *     sans label, jeton CSRF absent, polices et assets.
 *   - SECURITE vue de l'exterieur : en-tetes, traces de debug dans le HTML,
 *     surfaces (xmlrpc, readme, .git, listing uploads, REST users, repair.php),
 *     injection dans le parametre de tri, exposition du dossier des rapports.
 *   - CONDITIONS D'HEBERGEMENT : encodeur AVIF REELLEMENT teste (fichier ecrit
 *     puis verifie par son type MIME, pas seulement « la fonction existe »),
 *     exec() desactivee, binaires avifenc/vips, LiteSpeed Cache present,
 *     open_basedir, ecrivabilite du dossier de cache.
 *
 * GARDES :
 *   - lecture seule sur tout ce qui est client : aucun fichier du theme n'est
 *     modifie, aucun CSS, template, image, traduction ou baseline ;
 *   - ecrit uniquement dans wp-content/pk-rapports/ et uploads/pk-sonde/ ;
 *   - admin : capability manage_options + nonce sur chaque action ;
 *   - entree 3 : cle aleatoire, 403 sans cle, et le crawl n'est lance que si
 *     l'hote ressemble a un environnement de test (ou &force=1) ;
 *   - budget de temps : si max_execution_time est atteint, le module s'arrete
 *     PROPREMENT et propose de reprendre ou il en etait (rien n'est perdu).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

if ( ! defined( 'PK_SONDE_VERSION' ) ) {
        define( 'PK_SONDE_VERSION', '5.2' );
}

if ( ! function_exists( 'partikulier_pk_diag_agents' ) ) {

        /**
         * Les agents qui « voient » le site et dont le rendu doit etre controle.
         *
         * @return array<string,array{ua:string,attend:string}>
         */
        function partikulier_pk_diag_agents() {
                return array(
                        'humain'    => array(
                                'ua'     => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0 Safari/537.36',
                                'attend' => 'la page complete, servie en cache si possible',
                        ),
                        'Facebook'  => array(
                                'ua'     => 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)',
                                'attend' => 'og:title, og:description, og:image, HTTP 200, pas de mur',
                        ),
                        'X'         => array(
                                'ua'     => 'Twitterbot/1.0',
                                'attend' => 'twitter:card=summary_large_image + twitter:image',
                        ),
                        'WhatsApp'  => array(
                                'ua'     => 'WhatsApp/2.23.20',
                                'attend' => 'og:image et og:title : la vignette de la conversation',
                        ),
                        'Google'    => array(
                                'ua'     => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)',
                                'attend' => 'le MEME HTML que l\'humain, sinon cloaking',
                        ),
                        'Discord'   => array(
                                'ua'     => 'Mozilla/5.0 (compatible; Discordbot/2.0; +https://support.discord.com)',
                                'attend' => 'og:image + og:description pour l\'apercu du lien',
                        ),
                        'GPTBot'    => array(
                                'ua'     => 'Mozilla/5.0 AppleWebKit/537.36 (compatible; GPTBot/1.2; +https://openai.com/gptbot)',
                                'attend' => '200 et le texte dans le HTML, sinon rien a citer',
                        ),
                        'ClaudeBot' => array(
                                'ua'     => 'ClaudeBot/1.0 (+https://www.anthropic.com/webstore)',
                                'attend' => '200 + donnees structurees exploitables',
                        ),
                        'Perplexity'=> array(
                                'ua'     => 'Mozilla/5.0 (compatible; PerplexityBot/1.1)',
                                'attend' => '200 + dates de publication visibles',
                        ),
                        'GoogleExt' => array(
                                'ua'     => 'Mozilla/5.0 (compatible; Google-Extended/1.0)',
                                'attend' => 'decision a prendre : autoriser ou non l\'entrainement',
                        ),
                        'OAISearch' => array(
                                'ua'     => 'Mozilla/5.0 (compatible; OAI-SearchBot/1.0)',
                                'attend' => '200 si le site doit apparaitre dans ChatGPT Search',
                        ),
                );
        }
}

if ( ! function_exists( 'partikulier_pk_diag_langues' ) ) {

        /**
         * Les langues reellement declarees sur le site.
         *
         * @return array<int,string>
         */
        function partikulier_pk_diag_langues() {
                if ( function_exists( 'pll_languages_list' ) ) {
                        $l = (array) pll_languages_list( array( 'skip_invisible' => false ) );
                        if ( $l ) {
                                return array_values( array_filter( array_map( 'strval', $l ) ) );
                        }
                }
                return array( '' );
        }
}

if ( ! function_exists( 'partikulier_pk_diag_taxonomies' ) ) {

        /**
         * Les taxonomies commerciales du site, lues la OU ELLES SONT.
         *
         * Le theme definit ses propres constantes (es_type, es_category, es_status,
         * es_location) alors qu'Estatik en enregistre d'autres selon les versions :
         * un diagnostic qui devine les noms declare « taxonomie absente » ou compte des
         * termes au mauvais endroit. On prend la constante du theme quand elle est
         * enregistree, puis les noms connus d'Estatik, et on le dit dans le rapport.
         *
         * @return array<string,string> label => nom de taxonomie
         */
        function partikulier_pk_diag_taxonomies() {
                $candidates = array(
                        'lieux'    => array( 'PARTIKULIER_ESTATIK_LOCATION_TAXONOMY', 'es_location', 'es_property_city' ),
                        'types'    => array( 'PARTIKULIER_ESTATIK_TYPE_TAXONOMY', 'es_property_type', 'es_type' ),
                        'categories' => array( 'PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY', 'es_property_category', 'es_category' ),
                        'actions'  => array( 'PARTIKULIER_ESTATIK_STATUS_TAXONOMY', 'es_status', 'es_property_status' ),
                );
                $out = array();
                foreach ( $candidates as $label => $essais ) {
                        foreach ( $essais as $e ) {
                                $nom = defined( $e ) ? (string) constant( $e ) : $e;
                                if ( taxonomy_exists( $nom ) ) {
                                        $out[ $label ] = $nom;
                                        break;
                                }
                        }
                }
                return $out;
        }
}

if ( ! function_exists( 'partikulier_pk_diag_pages' ) ) {

        /**
         * La liste de TOUTES les pages a controler.
         *
         * @param array $opt {max_listings:int, max_terms:int}.
         * @return array<int,array{url:string,libelle:string,type:string,langue:string,id:int}>
         */
        function partikulier_pk_diag_pages( $opt = array() ) {
                $opt = wp_parse_args(
                        $opt,
                        array(
                                'max_listings' => 0,
                                'max_terms'    => 0,
                        )
                );
                $out = array();
                $vu  = array();
                $ajoute = static function ( $url, $libelle, $type, $langue = '', $id = 0 ) use ( &$out, &$vu ) {
                        if ( ! $url ) {
                                return;
                        }
                        $url = (string) $url;
                        if ( isset( $vu[ $url ] ) ) {
                                return;
                        }
                        $vu[ $url ] = 1;
                        $out[]      = compact( 'url', 'libelle', 'type', 'langue', 'id' );
                };

                $langues = partikulier_pk_diag_langues();
                $has_pll = count( $langues ) > 1 || ( 1 === count( $langues ) && '' !== $langues[0] );
                $perfil  = static function ( $post_id, $langue ) use ( $has_pll ) {
                        if ( $langue && function_exists( 'pll_get_post' ) ) {
                                $t = pll_get_post( (int) $post_id, $langue );
                                if ( $t ) {
                                        $u = get_permalink( $t );
                                        if ( $u && false === strpos( $u, '?p=' ) ) {
                                                return $u;
                                        }
                                }
                        }
                        return '';
                };

                /* 1. accueil, par langue. */
                foreach ( $langues as $l ) {
                        $u = $l && function_exists( 'pll_home_url' ) ? pll_home_url( $l ) : home_url( '/' );
                        $ajoute( $u, 'accueil', 'front', $l, (int) get_option( 'page_on_front' ) );
                }

                /* 2. archive des annonces + pagination reelle, par langue. */
                $arch = get_post_type_archive_link( 'properties' );
                if ( ! $arch && function_exists( 'pk_properties_archive_url' ) ) {
                        $arch = pk_properties_archive_url();
                }
                $arch = $arch ? $arch : user_trailingslashit( home_url( '/annonces/' ) );
                $q    = new WP_Query( array( 'post_type' => 'properties', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) );
                /* $per doit venir de la configuration REELLE du site : le filtre
                   pk_properties_per_page n'est branche nulle part dans le theme, la valeur
                   24 etait une supposition, et la lecture du site (get_option posts_per_page)
                   est ce que la requete principale de l'archive utilise effectivement. */
                $per  = (int) apply_filters( 'pk_properties_per_page', (int) get_option( 'posts_per_page' ) );
                $per  = $per > 0 ? $per : 10;
                $nb   = (int) ceil( max( 1, (int) $q->found_posts ) / $per );
                foreach ( $langues as $l ) {
                        $base = $arch;
                        if ( $l && function_exists( 'pll_home_url' ) ) {
                                $base = user_trailingslashit( trailingslashit( pll_home_url( $l ) ) . 'annonces' );
                        }
                        $ajoute( $base, 'archive annonces', 'archive', $l );
                        /* max(2, ...) fabriquait une page 2 meme quand le catalogue n'en a qu'une
                           (mesure staging : 21 annonces sur une seule page, /en/annonces/page/2/ en
                           404, puis « URL introuvable » et « LLM bloque » en verdict). Une page que
                           l'arithmetique ne justifie pas n'existe pas : on ne genere que les pages
                           calculables, et la page 2 restera testee des qu'une 2e page reelle existe. */
                        for ( $i = 2; $i <= min( max( 1, $nb ), 8 ); $i++ ) {
                                $ajoute( user_trailingslashit( rtrim( (string) $base, '/' ) . '/page/' . $i ), 'archive page ' . $i, 'archive', $l );
                        }
                }

                /* 3. chaque annonce publiee, dans chaque langue. */
                $ids = partikulier_pk_diag_tous_post_ids(
                        array(
                                'post_type'   => 'properties',
                                'post_status' => 'publish',
                                'numberposts' => (int) $opt['max_listings'] > 0 ? (int) $opt['max_listings'] : -1,
                        )
                );
                /* L'url d'une annonce dans une langue DONNEE se construit a partir de son
                   URL de base : la traduire par pll_get_post() ne marche pas partout (en
                   CLI, Polylang n'a pas de langue active, les variantes disparaissaient —
                   mesure : 60 URLs en CLI contre 65 en wp eval). Le perimetre doit etre le
                   MEME quel que soit le contexte, sinon deux rapports ne sont pas comparables. */
                foreach ( (array) $ids as $pid ) {
                        $u0 = get_permalink( $pid );
                        $ajoute( $u0, 'annonce #' . $pid, 'single', '', (int) $pid );
                        foreach ( $langues as $l ) {
                                $u = partikulier_pk_diag_url_langue( (string) $u0, (string) $l, $langues );
                                if ( $u ) {
                                        $ajoute( $u, 'annonce #' . $pid . ' (' . $l . ')', 'single', $l, (int) $pid );
                                }
                        }
                }
                /* 4. toutes les pages normales (mêmes règles de langue qu'à l'étape 3). */
                $pages_l = partikulier_pk_diag_tous_post_ids( array( 'post_type' => 'page', 'post_status' => 'publish', 'numberposts' => -1 ) );
                foreach ( (array) $pages_l as $pid ) {
                        $u0     = get_permalink( $pid );
                        $ajoute( $u0, 'page ' . get_post_field( 'post_name', $pid ), 'page', '', (int) $pid );
                        foreach ( $langues as $l ) {
                                $u = partikulier_pk_diag_url_langue( (string) $u0, (string) $l, $langues );
                                if ( $u ) {
                                        $ajoute( $u, 'page ' . get_post_field( 'post_name', $pid ) . ' (' . $l . ')', 'page', $l, (int) $pid );
                                }
                        }
                }

                /* 5. taxonomies Estatik. */
                foreach ( partikulier_pk_diag_taxonomies() as $tax ) {
                        if ( ! taxonomy_exists( $tax ) ) {
                                continue;
                        }
                        $args = array( 'taxonomy' => $tax, 'hide_empty' => true, 'number' => (int) $opt['max_terms'] > 0 ? (int) $opt['max_terms'] : '' );
                        $termes = get_terms( $args );
                        if ( is_wp_error( $termes ) ) {
                                continue;
                        }
                        foreach ( (array) $termes as $t ) {
                                if ( ! $t instanceof WP_Term ) {
                                        continue;
                                }
                                $l1 = get_term_link( $t );
                                if ( ! is_wp_error( $l1 ) ) {
                                        $ajoute( $l1, 'tax ' . $tax . '/' . $t->slug, 'taxonomy', '', (int) $t->term_id );
                                }
                                foreach ( $langues as $lang ) {
                                        if ( ! $lang || ! function_exists( 'pll_get_term' ) ) {
                                                continue;
                                        }
                                        $tt = pll_get_term( (int) $t->term_id, $lang );
                                        if ( ! $tt ) {
                                                continue;
                                        }
                                        $l2 = get_term_link( is_numeric( $tt ) ? (int) $tt : $tt );
                                        if ( ! is_wp_error( $l2 ) ) {
                                                $ajoute( $l2, 'tax ' . $tax . '/' . $t->slug . ' (' . $lang . ')', 'taxonomy', $lang, (int) $t->term_id );
                                        }
                                }
                        }
                }

                /* 6. les pages qui trahissent un probleme. */
                $ajoute( add_query_arg( 's', 'appartement', home_url( '/' ) ), 'recherche', 'search' );
                $ajoute( home_url( '/cette-page-n-existe-pas-' . substr( md5( (string) home_url() ), 0, 8 ) . '/' ), '404 (controle de non-fuite)', '404' );
                $ajoute( home_url( '/sitemap.xml' ), 'sitemap.xml', 'sitemap' );
                $ajoute( home_url( '/feed/' ), 'flux RSS', 'feed' );
                $ajoute( home_url( '/robots.txt' ), 'robots.txt', 'robots' );

                return $out;
        }
}

/**
 * Collecte les @type d'un graphe JSON-LD, recursivement.
 *
 * Fonction nommee, pas une fermeture : une fermeture qui s'appelle elle-meme a
 * travers use() ne se voit pas (mesure sur une install fraiche : « Undefined
 * variable $walk » puis « Value of type null is not callable » — fatal).
 *
 * @param mixed $n   Noeud.
 * @param array $vus Accumulateur, par reference.
 * @return void
 */
function partikulier_pk_diag_jsonld_types( $n, &$vus ) {
        if ( ! is_array( $n ) ) {
                return;
        }
        if ( isset( $n['@type'] ) ) {
                foreach ( (array) $n['@type'] as $t ) {
                        $vus[ (string) $t ] = 1;
                }
        }
        foreach ( array( 'mainEntity', 'itemListElement', 'item', '@graph', 'about', 'offers', 'seller' ) as $k ) {
                if ( ! isset( $n[ $k ] ) ) {
                        continue;
                }
                foreach ( (array) $n[ $k ] as $c ) {
                        if ( is_array( $c ) ) {
                                partikulier_pk_diag_jsonld_types( $c, $vus );
                        }
                }
        }
}

if ( ! function_exists( 'partikulier_pk_diag_doublons' ) ) {

        /**
         * Les noms de termes qui reviennent plus d'une fois (Agadir, Agadir, Agadir…)
         * SANS etre relies par Polylang.
         *
         * Les noms normalises retirent le suffixe de traduction (-fr/-en/-ar) : c'est
         * ainsi que les imports repetes laissent des triplets. Mais depuis que les
         * traductions sont correctement RELIEES (term_translations), des termes de
         * meme nom peuvent etre les variantes legitimes fr/en/ar d'une seule entite :
         * mesuree sur banc, « marrakech x3 » etait marrakech + marrakech-en +
         * marrakech-ar, correctement relies. Un doublon n'est donc signale que si au
         * moins un des memes-cle n'appartient pas au groupe de traduction des autres.
         *
         * @param array $taxos label => nom de taxonomie.
         * @return array<int,string>
         */
        function partikulier_pk_diag_doublons( $taxos ) {
                $vus = array();
                foreach ( $taxos as $nom ) {
                        $termes = get_terms( array( 'taxonomy' => $nom, 'hide_empty' => false, 'number' => 0 ) );
                        if ( is_wp_error( $termes ) ) {
                                continue;
                        }
                        foreach ( (array) $termes as $t ) {
                                if ( ! $t instanceof WP_Term ) {
                                        continue;
                                }
                                $cle = function_exists( 'mb_strtolower' ) ? mb_strtolower( remove_accents( $t->name ), 'UTF-8' ) : strtolower( remove_accents( $t->name ) );
                                /* Suffixes de traduction a la fin du NOM : « casablanca-fr » OU « Casablanca FR »
                                   (mesure staging : les imports laissent les deux formes). */
                                $cle = preg_replace( '/[\s-](?:fr|en|ar)$/', '', $cle );
                                $vus[ $cle ][] = (int) $t->term_id;
                        }
                }
                $out = array();
                foreach ( $vus as $nom => $ids ) {
                        $ids = array_values( array_unique( $ids ) );
                        if ( count( $ids ) < 2 ) {
                                continue;
                        }
                        /* Groupes de traduction : un terme relie aux autres memes-cle
                           n'est pas un doublon, c'est sa traduction. Orphelin = terme
                           dont le groupe ne contient AUCUN autre meme-cle : c'est lui
                           qu'il faudra fusionner avant l'import. */
                        $orphelins = 0;
                        if ( function_exists( 'pll_get_term_translations' ) ) {
                                foreach ( $ids as $tid ) {
                                        $groupe   = array_values( (array) pll_get_term_translations( $tid ) );
                                        $groupids = array_map( 'intval', $groupe );
                                        $autres   = array_diff( $ids, array( $tid ) );
                                        if ( ! array_intersect( $autres, $groupids ) ) {
                                                $orphelins++;
                                        }
                                }
                        } else {
                                $orphelins = count( $ids ); /* sans Polylang, tout triplet est un doublon. */
                        }
                        if ( $orphelins > 0 ) {
                                $out[] = $nom . ' x' . count( $ids ) . ' (' . $orphelins . ' non relié' . ( $orphelins > 1 ? 's' : '' ) . ')';
                        }
                }
                return $out;
        }
}

if ( ! function_exists( 'partikulier_pk_diag_url_langue' ) ) {

        /**
         * L'URL d'un contenu dans une langue donnee, SANS jamais empiler deux prefixes.
         *
         * Mesure sur un staging reel : la version precedente ajoutait mecaniquement /en/
         * a un permalien qui portait deja /fr/ -> 261 URLs fabriquees sur 416, toutes en
         * 301 a corps vide, et le rapport affichait « viewport NON » 273 fois pour
         * 3 pages reellement concernees. Un faux positif coute plus cher qu'il n'apprend.
         *
         * @param string $url     URL de base (permalien brut).
         * @param string $lang    Langue cible ('' = ne rien changer).
         * @param array  $langues Langues declarees sur le site.
         * @return string '' quand il n'y a rien a tester (deja dans la langue demandee).
         */
        function partikulier_pk_diag_url_langue( $url, $lang, $langues ) {
                $url = (string) $url;
                if ( '' === $lang || '' === $url ) {
                        return '';
                }
                $u       = (array) wp_parse_url( $url );
                $chemin  = (string) ( $u['path'] ?? '' );
                $restant = isset( $u['query'] ) ? '?' . $u['query'] : '';
                $scheme  = (string) ( $u['scheme'] ?? 'https' );
                $hoste   = (string) ( $u['host'] ?? wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
                /* wp_parse_url rend le port a part : sans le recoller, toute URL construite
                   pointe sur le port implicite (80/443). Mesure sur banc (serveur :8090) :
                   54 variantes de langue en ERR « connection refused », le rapport lisait
                   « page cassee » pour un site qui marche. Le port fait partie de l'hote. */
                $port    = isset( $u['port'] ) ? ':' . (int) $u['port'] : '';
                $segments = explode( '/', trim( $chemin, '/' ) );
                $premier  = strtolower( (string) ( $segments[0] ?? '' ) );
                $deja     = ( '' !== $premier && in_array( $premier, (array) $langues, true ) );
                if ( $deja ) {
                        if ( $premier === strtolower( (string) $lang ) ) {
                                return '';
                        }
                        $segments[0] = $lang;
                        $nouveau     = '/' . implode( '/', $segments );
                } else {
                        $base = function_exists( 'pll_home_url' ) ? (string) pll_home_url( $lang ) : (string) home_url( '/' );
                        $base = rtrim( (string) wp_parse_url( $base, PHP_URL_PATH ), '/' );
                        $nouveau = ( ( '' === $base || '/' === $base ) ? '' : $base ) . '/' . trim( $chemin, '/' );
                }
                $nouveau = '/' . ltrim( $nouveau, '/' );
                if ( '/' !== substr( $nouveau, -1 ) ) {
                        $nouveau .= '/';
                }
                /* Garde anti-doublon : une URL qui commence par /xx/xx/ est une
                   variante fabriquee a partir d'une variante (mesure banc : /en/en/
                   et /ar/en/ en ERR) — soit le prefixe n'a pas ete reconnu, soit
                   l'appel a deja travaille sur une URL traduite. Plutot que de
                   tester une page qui n'existe pas, on abandonne la variante. */
                if ( preg_match( '#^/([a-z]{2})/\1(?:/|$)#', $nouveau ) ) {
                        return '';
                }
                if ( $nouveau === rtrim( $chemin, '/' ) . '/' ) {
                        return '';
                }
                return $scheme . '://' . $hoste . $port . $nouveau . $restant;
        }
}

if ( ! function_exists( 'partikulier_pk_diag_tous_post_ids' ) ) {

        /**
         * ids de posts, sans que la langue active du contexte ne filtre le resultat.
         *
         * Mesure : get_posts('properties') renvoyait 30 posts en wp eval et 0 en CLI nu
         * (Polylang restreint a la langue courante). Un perimetre qui bouge selon la
         * facon de lancer le diagnostic ne vaut rien : on interroge la table.
         *
         * @param array $args post_type, post_status, numberposts.
         * @return array<int,int>
         */
        function partikulier_pk_diag_tous_post_ids( $args ) {
                global $wpdb;
                $type   = (array) ( isset( $args['post_type'] ) ? $args['post_type'] : 'properties' );
                $etat   = (array) ( isset( $args['post_status'] ) ? $args['post_status'] : 'publish' );
                $limite = (int) ( isset( $args['numberposts'] ) ? $args['numberposts'] : -1 );
                $in_ty  = implode( ',', array_fill( 0, count( $type ), '%s' ) );
                $in_st  = implode( ',', array_fill( 0, count( $etat ), '%s' ) );
                $sql    = "SELECT ID FROM {$wpdb->posts} WHERE post_type IN ($in_ty) AND post_status IN ($in_st) ORDER BY ID ASC";
                $par    = array_merge( $type, $etat );
                if ( $limite > 0 ) {
                        $sql .= ' LIMIT %d';
                        $par[] = $limite;
                }
                $ids = $wpdb->get_col( $wpdb->prepare( $sql, $par ) );
                return array_values( array_map( 'intval', (array) $ids ) );
        }
}

if ( ! function_exists( 'partikulier_pk_diag_hote_observe' ) ) {

        /**
         * L'hote que les requetes HTTP de ce site transportent, celui que le theme met
         * dans le nom de ses fichiers de cache. En CLI, HTTP_HOST est vide et home_url()
         * peut porter un port : sans ce repli la sonde chercherait un fichier que le
         * theme n'ecrit pas.
         *
         * @return string
         */
        function partikulier_pk_diag_hote_observe() {
                $h = isset( $_SERVER['HTTP_HOST'] ) ? (string) wp_unslash( $_SERVER['HTTP_HOST'] ) : '';
                if ( '' !== $h ) {
                        return strtolower( $h );
                }
                $u   = home_url( '/' );
                $nom = (string) wp_parse_url( $u, PHP_URL_HOST );
                $prt = wp_parse_url( $u, PHP_URL_PORT );
                if ( '' === $nom ) {
                        return '';
                }
                if ( $prt ) {
                        $scheme = (string) wp_parse_url( $u, PHP_URL_SCHEME );
                        if ( ! ( 'http' === $scheme && 80 === (int) $prt ) && ! ( 'https' === $scheme && 443 === (int) $prt ) ) {
                                $nom .= ':' . $prt;
                        }
                }
                return strtolower( $nom );
        }
}

if ( ! function_exists( 'partikulier_pk_diag_serveur_mono_ouvrier' ) ) {

        /**
         * Le serveur web actuel peut-il se rappeler lui-meme ?
         *
         * `php -S` sans PHP_CLI_SERVER_WORKERS n'a qu'un ouvrier : la requete que la
         * sonde envoie vers son propre site attend la fin de la requete en cours. Sans
         * ce test, chaque page coute le delai d'attente (mesure : 14 pages x 8 s).
         *
         * @return bool
         */
        function partikulier_pk_diag_serveur_mono_ouvrier() {
                if ( 'cli' === PHP_SAPI ) {
                        return false;
                }
                $env = (int) ( getenv( 'PHP_CLI_SERVER_WORKERS' ) ?: 0 );
                if ( 1 === $env ) {
                        return true;
                }
                if ( $env > 1 ) {
                        return false;
                }
                $logiciel = strtolower( (string) ( isset( $_SERVER['SERVER_SOFTWARE'] ) ? $_SERVER['SERVER_SOFTWARE'] : '' ) );
                if ( false === strpos( $logiciel, 'development server' ) ) {
                        return false;
                }
                $desactive = array_map( 'trim', array_filter( explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
                if ( ! function_exists( 'shell_exec' ) || in_array( 'shell_exec', $desactive, true ) ) {
                        return true; // impossible de verifier: on suppose le cas sur, on n'attend pas 8 s par page
                }
                $nb = (int) trim( (string) @shell_exec( 'ps -o comm= -C php,php8.4,php8.3,php8.2 2>/dev/null | wc -l' ) );
                return $nb > 0 && $nb <= 2;
        }
}

if ( ! function_exists( 'partikulier_pk_diag_type_requete' ) ) {

        /**
         * Le type de requete que WordPress a resolu (sert au mode local).
         *
         * @param object $q WP_Query.
         * @return string
         */
        function partikulier_pk_diag_type_requete( $q ) {
                if ( ! is_object( $q ) ) {
                        return '';
                }
                foreach ( array( 'is_front_page', 'is_home', 'is_singular', 'is_post_type_archive', 'is_tax', 'is_archive', 'is_search', 'is_404', 'is_page' ) as $f ) {
                        if ( ! empty( $q->$f ) ) {
                                return str_replace( 'is_', '', $f );
                        }
                }
                return '';
        }
}

if ( ! function_exists( 'partikulier_pk_diag_entete_cache' ) ) {

        /**
         * Le nom du fichier de cache que le theme ecrirait pour cette URL.
         *
         * Reproduit la cle reelle de Partikulier_Cache::cache_path() (langue + hôte
         * + chemin), pour verifier le disque et pas seulement l'en-tete annonce.
         *
         * @param string $url URL absolue.
         * @return array{dir:string,fichier:string,gz:string}
         */
        function partikulier_pk_diag_entete_cache( $url, $hote = '' ) {
                $up  = wp_get_upload_dir();
                $dir = trailingslashit( $up['basedir'] ) . 'partikulier-cache';
                $host_raw = strtolower( (string) ( '' !== $hote ? $hote : ( isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : partikulier_pk_diag_hote_observe() ) ) );
                $allowed = array( (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST ) );
                foreach ( preg_split( '/[\s,]+/', (string) ( defined( 'PK_ALLOWED_CACHE_HOSTS' ) ? constant( 'PK_ALLOWED_CACHE_HOSTS' ) : '' ) ) as $extra ) {
                        if ( '' !== $extra ) {
                                $allowed[] = $extra;
                        }
                }
                $allowed = array_values( array_unique( (array) apply_filters( 'partikulier_cache_allowed_hosts', $allowed ) ) );
                $nom     = static function ( $h ) {
                        $p = wp_parse_url( 'http://' . $h, PHP_URL_HOST );
                        return is_string( $p ) ? strtolower( $p ) : '';
                };
                $ok      = in_array( $nom( $host_raw ), array_unique( array_map( $nom, $allowed ) ), true );
                $host    = $ok ? preg_replace( '/[^a-z0-9.\-]/i', '', (string) $host_raw ) : 'default';
                $path    = (string) wp_parse_url( $url, PHP_URL_PATH );
                $uri     = '/' === $path ? 'index' : trim( str_replace( array( '..', '/' ), array( '', '_' ), $path ), '_' );
                /* La cle reelle du theme prefixe par WPLANG quand la constante historique est
                   definie (Partikulier_Cache::cache_path). Sans ce prefixe, la sonde cherche
                   un fichier que le theme n'ecrit jamais et lit « RIEN » alors que l'entree
                   existe — le disque et le rapport se contredisaient sans raison visible. */
                $lang = ( defined( 'WPLANG' ) && WPLANG ) ? WPLANG . '_' : '';
                $fichier = rtrim( $dir, '/' ) . '/' . $lang . $host . '_' . $uri . '.html';
                return array( 'dir' => $dir, 'fichier' => $fichier, 'gz' => $fichier . '.gz' );
        }
}

if ( ! function_exists( 'partikulier_pk_diag_filtre_clauses' ) ) {
        /**
         * Lit les clauses reellement appliquees aux filtres du catalogue.
         *
         * Un filtre « ville » dont la clause porte field = term_id est structurellement
         * mort des que Polylang est actif : mesure sur un banc de test (deux termes
         * « casablanca » 0 fiche et « casablanca-fr » 9 fiches), found_posts = 0 avec
         * field = term_id et found_posts = 9 avec field = term_taxonomy_id, a hooks
         * egaux. C'est exactement le « je clique sur Casablanca, j'ai zero annonce ».
         *
         * @return array{0:array<string>,1:array<string>} Clauses observees, suspects.
         */
        function partikulier_pk_diag_filtre_clauses() {
                $vus = array();
                $suspects = array();
                if ( ! class_exists( 'Partikulier_Search_Filters' ) ) {
                        return array( $vus, array( 'module de filtres absent du theme' ) );
                }
                $rm = new ReflectionMethod( 'Partikulier_Search_Filters', 'taxonomy_map' );
                $rm->setAccessible( true );
                $map = (array) $rm->invoke( null );
                foreach ( $map as $param => $tax ) {
                        if ( ! is_string( $tax ) || '' === $tax ) {
                                continue;
                        }
                        /* On injecte un VRAI slug du catalogue, pas une valeur inconnue : avec une
                           valeur inconnue le module ne construit aucune clause et le controle ne
                           verrait que la clause de langue de Polylang (c'etait mon premier essai, il
                           affichait « language field=term_taxonomy_id » pour les trois filtres). */
                        global $wpdb;
                        /* Le module construit ses clauses pendant pre_get_posts de la REQUETE
                           PRINCIPALE : le rejouer ici sur un WP_Query fabrique ne dirait rien de vrai
                           (is_main_query() est faux en dehors du cycle d'une requete). On lit donc la
                           construction elle-meme, et on mesure le seul fait qui casse les filtres :
                           le champ de la clause, et ce que les APIs voient dans ce contexte. */
                        $src = '';
                        $pk_fichiers = array();
                        if ( function_exists( 'get_theme_file_path' ) ) {
                                $pk_fichiers[] = get_theme_file_path( 'inc/class-search-filters.php' );
                        }
                        $pk_fichiers[] = (string) ( defined( 'PARTIKULIER_DIR' ) ? PARTIKULIER_DIR . '/inc/class-search-filters.php' : '' );
                        foreach ( array_filter( $pk_fichiers ) as $f ) {
                                if ( is_readable( $f ) ) {
                                        $src .= (string) file_get_contents( $f );
                                }
                        }
                        $n_tt = preg_match_all( "/'field'\s*=>\s*'term_taxonomy_id'/", $src );
                        $n_id = preg_match_all( "/'field'\s*=>\s*'term_id'/", $src );
                        $slug = $wpdb->get_var( $wpdb->prepare(
                                "SELECT t.slug FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s ORDER BY tt.count DESC LIMIT 1", $tax ) );
                        $en_base = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->term_taxonomy WHERE taxonomy = %s", $tax ) );
                        /* La preuve qu'un filtre marche n'est pas une lecture de code ni un
                           get_terms() dans le contexte de la sonde (Polylang y filtre par langue
                           courante : mesure sur banc, « AVEUGLE » affiche alors que le filtre
                           rend 21 fiches) — c'est la page que recoit un VISITEUR. La sonde tourne
                           en HTTP : on demande l'archive avec le parametre et on compte les
                           cartes. Termes porteur ET orphelin testes : c'est le doublon d'import
                           qui distingue un filtre regroupe d'un filtre a clause etroite. */
                        $base = '';
                        if ( function_exists( 'pk_properties_archive_url' ) ) {
                                $b = pk_properties_archive_url();
                                if ( is_string( $b ) && '' !== $b ) {
                                        $base = $b;
                                }
                        }
                        if ( ! $base ) {
                                $base = (string) get_post_type_archive_link( 'properties' );
                        }
                        $compte_cartes = static function ( $url ) {
                                $r = wp_remote_get( $url, array( 'timeout' => 15, 'redirection' => 0, 'sslverify' => false ) );
                                if ( is_wp_error( $r ) ) {
                                        return null;
                                }
                                $b = (string) wp_remote_retrieve_body( $r );
                                $n = (int) preg_match_all( '#class="pk-card-title"#', $b );
                                if ( 0 === $n ) {
                                        $n = (int) preg_match_all( '#' . preg_quote( home_url( '/' ), '#' ) . '[^"]*?/annonce/#', $b );
                                }
                                return $n;
                        };
                        $ref = $base ? $compte_cartes( $base ) : null;
                        $total = 0;
                        foreach ( $wpdb->get_col( $wpdb->prepare( "SELECT t.slug FROM $wpdb->terms t JOIN $wpdb->term_taxonomy tt ON tt.term_id = t.term_id WHERE tt.taxonomy = %s ORDER BY tt.count DESC LIMIT 2", $tax ) ) as $essai_slug ) {
                                $n = $base ? $compte_cartes( add_query_arg( $param, $essai_slug, $base ) ) : null;
                                if ( null === $n ) {
                                        continue;
                                }
                                $total = max( $total, $n );
                                $vus[] = $param . ' -> ' . $tax . ' ?' . $param . '=' . $essai_slug . ' : ' . $n . ' fiche(s)';
                                if ( is_int( $ref ) && $ref > 0 && 0 === $n ) {
                                        $suspects[] = $param . ' : le filtre ' . $param . '=' . $essai_slug . ' rend 0 fiche sur un catalogue qui en affiche ' . $ref . ' (mesure HTTP, page reellement servie)';
                                }
                        }
                        if ( $base && null === $ref ) {
                                $vus[] = $param . ' -> ' . $tax . ' : archive inaccessible, filtre non teste par HTTP';
                        }
                        $vus[] = $param . ' -> clause=' . ( $n_tt ? 'term_taxonomy_id' : ( $n_id ? 'term_id' : '?' ) )
                                . ' · ' . $en_base . ' terme(s) en base';
                        if ( function_exists( 'pll_current_language' ) && $n_id && ! $n_tt ) {
                                $suspects[] = $param . ' : le module filtre en term_id avec Polylang actif — mesure: 0 resultat avec ce champ, les fiches avec term_taxonomy_id';
                        }
                        if ( ! $slug && ! $total ) {
                                $suspects[] = $param . ' : taxonomie ' . $tax . ' vide — le selecteur du formulaire ne peut rien resoudre';
                        }
                }
                return array( $vus, $suspects );
        }
}

if ( ! function_exists( 'partikulier_pk_diag_analyse' ) ) {

        /**
         * Analyse le HTML d'une page rendue. Une fonction = la liste exacte des
         * criteres du rapport ; le dev qui veut ajouter un controle ajoute ici.
         *
         * @param string $html Corps de reponse.
         * @param array  $ctx  Contexte (url, libelle, type, langue, code, temps_ms, cache, gz, sql).
         * @return array{0:array,1:array} Ligne de tableau + anomalies.
         */
        function partikulier_pk_diag_analyse( $html, $ctx ) {
                $h = (string) $html;
                $ligne = array();
                $anom = array();
                $cle = $ctx['libelle'] . ' [' . ( $ctx['langue'] ?: 'fr' ) . ']';
                $norm = static function ( $x ) {
                        return trim( (string) preg_replace( '#\s+#u', ' ', strip_tags( (string) $x ) ) );
                };
                $meta = static function ( $name ) use ( $h ) {
                        if ( preg_match( '#<meta[^>]+(?:name|property)=["\']' . preg_quote( $name, '#' ) . '["\'][^>]+content=["\']([^"\']*)["\']#i', $h, $m ) ) {
                                return trim( $m[1] );
                        }
                        if ( preg_match( '#<meta[^>]+content=["\']([^"\']*)["\'][^>]+(?:name|property)=["\']' . preg_quote( $name, '#' ) . '["\']#i', $h, $m ) ) {
                                return trim( $m[1] );
                        }
                        return '';
                };
                $page_ok = in_array( $ctx['type'], array( 'single', 'archive', 'front', 'page', 'taxonomy' ), true );
                /* Un corps VIDE ne dit rien du SEO d'une page : les variantes non
                   traduites repondent 301 et le fetch sans redirection rend 0 octet —
                   analyser ce vide declenchait des faux KO massifs (mesure banc :
                   42 SEO-TITLE-ABSENT + 42 SOCIAL-OG-TITLE + 42 JSONLD-ABSENT, tous
                   sur des 301 ; sur le staging, la majorite des « 779 KO » venait de
                   la meme cause). Les regles de contenu ne jugent que les pages
                   reellement SERVIES : code 200 avec du HTML. */
                if ( $page_ok && ( '200' !== (string) $ctx['code'] || '' === trim( $h ) ) ) {
                        $page_ok = false;
                }
                /* sitemap.xml, /feed/ et robots.txt ne sont PAS du HTML : juger lang, viewport
                   ou title dessus produit du bruit (mesure : « sitemap.xml sans meta viewport,
                   KO » — la moitie des UX-VIEWPORT-ABSENT du rapport). Toutes les regles de
                   contenu HTML se limitent desormais au contenu HTML. */
                $est_html = ! in_array( $ctx['type'], array( 'sitemap', 'feed', 'robots' ), true );
                /* En mode local, aucun HTML n'a ete rendu : juger un title ou une og: absente
                   produirait des centaines d'anomalies fausses (mesure: 639). On ne juge ici
                   que l'existence de la route, et le rapport dit ce qui n'est pas evalue. */
                if ( ! empty( $ctx['local'] ) ) {
                        if ( '404' === (string) $ctx['code'] ) {
                                $anom[] = array( 'ROUTE-INCONNU', 'KO', $cle, 'aucune route WordPress ne sert ' . $ctx['url'] . ( empty( $ctx['route'] ) ? '' : ' (' . $ctx['route'] . ')' ) );
                        }
                        return array(
                                array(
                                        'url' => (string) $ctx['url'], 'page' => (string) $ctx['libelle'], 'type' => (string) $ctx['type'],
                                        'langue' => (string) ( $ctx['langue'] ?: 'fr' ), 'http' => (string) $ctx['code'],
                                        'cache' => 'non evalue (mode local)', 'froid_ms' => '', 'o_html' => '', 'o_gz' => '', 'sql' => '',
                                        'title' => 'non evalue', 'title_o' => '', 'description' => '', 'canonical' => 'non evalue',
                                        'hreflang' => 'non evalue', 'robots' => 'non evalue', 'jsonld' => 'non evalue', 'og' => '',
                                        'og_image' => 'non evalue', 'twitter' => 'non evalue', 'images' => '', 'alt' => 'n/a',
                                        'srcset' => 'n/a', 'lazy' => 'n/a', 'h1' => '', 'titres' => 'non evalue', 'lang' => 'non evalue',
                                        'dir' => 'non evalue', 'viewport' => 'non evalue', 'liens' => '', 'ancres_faibles' => '',
                                        'champs' => '', 'sans_label' => '', 'mots' => '', 'ratio_texte' => '', 'date' => 'non evalue',
                                ),
                                $anom,
                        );
                }

                /* --- titre, description --- */
                $title = preg_match( '#<title[^>]*>(.*?)</title>#is', $h, $m ) ? $norm( $m[1] ) : '';
                $ligne['title'] = '' !== $title ? 'oui' : 'NON';
                $ligne['title_o'] = strlen( $title );
                if ( '' === $title && $page_ok ) {
                        $anom[] = array( 'SEO-TITLE-ABSENT', 'KO', $cle, 'aucun <title> : Google et Facebook inventeront un titre' );
                } elseif ( strlen( $title ) > 0 && strlen( $title ) < 12 && $page_ok ) {
                        $anom[] = array( 'SEO-TITLE-COURT', 'WARN', $cle, 'title de ' . strlen( $title ) . ' octets : trop court pour etre repris' );
                } elseif ( strlen( $title ) > 70 ) {
                        $anom[] = array( 'SEO-TITLE-LONG', 'INFO', $cle, 'title de ' . strlen( $title ) . ' octets : tronque dans les resultats' );
                }
                $desc = $meta( 'description' );
                $ligne['description'] = strlen( $desc );
                if ( strlen( $desc ) < 50 && $page_ok ) {
                        $anom[] = array( 'SEO-DESC-COURTE', 'WARN', $cle, 'meta description absente ou de ' . strlen( $desc ) . ' o (attendu 120-160)' );
                }

                /* --- canonical, hreflang, robots --- */
                $canonical = preg_match( '#<link[^>]+rel=["\']canonical["\'][^>]+href=["\']([^"\']+)#i', $h, $cm ) ? $cm[1] : '';
                $ligne['canonical'] = '' !== $canonical ? 'oui' : 'NON';
                preg_match_all( '#<link[^>]+rel=["\']alternate["\'][^>]+hreflang=["\']([^"\']+)#i', $h, $al );
                $hreflang = array_values( array_unique( (array) ( $al[1] ?? array() ) ) );
                $ligne['hreflang'] = count( $hreflang ) . ( in_array( 'x-default', $hreflang, true ) ? '+xdef' : ' sans x-default' );
                if ( count( $hreflang ) < 2 && 'front' === $ctx['type'] ) {
                        $anom[] = array( 'I18N-HREFLANG', 'WARN', $cle, 'hreflang incomplet (' . implode( ',', $hreflang ) . ') : les langues ne sont pas declarees' );
                }
                preg_match( '#<meta[^>]+name=["\']robots["\'][^>]+content=["\']([^"\']+)#i', $h, $rm );
                $robots = (string) ( $rm[1] ?? 'par defaut' );
                $ligne['robots'] = $robots;
                $noindex = false !== stripos( $robots, 'noindex' );
                if ( $noindex && $page_ok ) {
                        $anom[] = array( 'SEO-NOINDEX-PUBLIQUE', 'KO', $cle, 'noindex sur une page publique : elle ne sera jamais trouvee' );
                }
                if ( ! $noindex && 'privee' === $ctx['type'] && '200' === (string) $ctx['code'] ) {
                        $anom[] = array( 'SEO-PRIVEE-SANS-NOINDEX', 'KO', $cle, 'page privee sans noindex : indexable et partageable' );
                }

                /* --- JSON-LD --- */
                preg_match_all( '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#is', $h, $ld );
                $types = array();
                $brut = '';
                foreach ( (array) ( $ld[1] ?? array() ) as $bloc ) {
                        $brut .= $bloc;
                        $don = json_decode( trim( $bloc ), true );
                        if ( ! is_array( $don ) ) {
                                $anom[] = array( 'JSONLD-INVALIDE', 'KO', $cle, 'bloc JSON-LD non decodable : le parseur du robot abandonne' );
                                continue;
                        }
                        partikulier_pk_diag_jsonld_types( $don, $types );
                }
                $ligne['jsonld'] = $types ? implode( ',', array_keys( $types ) ) : 'aucun';
                if ( $page_ok && ! $types ) {
                        $anom[] = array( 'JSONLD-ABSENT', 'WARN', $cle, 'aucune donnee structuree : ni robot ni LLM n\'ont de prix/ville/date exploitables' );
                }
                /* Un ItemList a zero sur une page SANS carte est juste une archive
                   vide dans cette langue (mesure banc : /en/annonces/ sans annonces
                   anglaises, coherent) : on ne signale que si la page AFFICHE des
                   cartes — comptage des liens de fiche, pas des intentions. */
                $pk_cartes = (int) preg_match_all( '#class="pk-card-title"#', $h );
                if ( 0 === $pk_cartes ) {
                        $pk_cartes = (int) preg_match_all( '#' . preg_quote( home_url( '/' ), '#' ) . '[^"]*?/annonce/#', $h );
                }
                if ( $page_ok && 'archive' === $ctx['type'] && isset( $types['ItemList'] ) && $pk_cartes > 0 && preg_match( '#"numberOfItems"\s*:\s*0#', $brut ) ) {
                        $anom[] = array( 'JSONLD-ITEMLIST-VIDE', 'KO', $cle, 'ItemList a numberOfItems=0 alors que la page affiche ' . $pk_cartes . ' cartes' );
                }
                if ( $page_ok && 'single' === $ctx['type'] && ! isset( $types['Product'] ) && ! isset( $types['Residence'] ) && ! isset( $types['Offer'] ) ) {
                        $anom[] = array( 'JSONLD-ANNONCE-SANS-OFFRE', 'INFO', $cle, 'page d\'annonce sans type Product/Offer : aucune donnee de prix lisible par un robot' );
                }

                /* --- Open Graph / Twitter : ce que voit le reseau social --- */
                $ligne['og'] = (int) preg_match_all( '#<meta[^>]+property=["\']og:#i', $h );
                $og_img = $meta( 'og:image' );
                $ligne['og_image'] = '' !== $og_img ? 'oui' : 'NON';
                $ligne['twitter'] = '' !== $meta( 'twitter:card' ) ? 'oui' : 'NON';
                if ( $page_ok ) {
                        if ( '' === $meta( 'og:title' ) ) {
                                $anom[] = array( 'SOCIAL-OG-TITLE', 'KO', $cle, 'pas d\'og:title : le lien partage n\'a pas de titre' );
                        }
                        if ( '' === $og_img ) {
                                $anom[] = array( 'SOCIAL-OG-IMAGE', 'KO', $cle, 'pas d\'og:image : aucun apercu visuel dans le fil' );
                        } elseif ( ! preg_match( '#^https?://#i', $og_img ) ) {
                                $anom[] = array( 'SOCIAL-OG-IMAGE-RELATIVE', 'KO', $cle, 'og:image pas en URL absolue (' . substr( $og_img, 0, 40 ) . ')' );
                        }
                        if ( '' === $meta( 'og:description' ) ) {
                                $anom[] = array( 'SOCIAL-OG-DESC', 'WARN', $cle, 'pas d\'og:description' );
                        }
                        if ( '' === $meta( 'og:url' ) ) {
                                $anom[] = array( 'SOCIAL-OG-URL', 'INFO', $cle, 'pas d\'og:url : le partage peut pointer sur une URL de campagne' );
                        }
                }

                /* --- UI/UX, sans navigateur --- */
                $imgs = preg_match_all( '#<img\b[^>]*>#i', $h, $im_all ) ? (array) $im_all[0] : array();
                $alt_absent = 0;
                $alt_vide = 0;
                $srcset = 0;
                $lazy = 0;
                foreach ( $imgs as $tag ) {
                        if ( ! preg_match( '#\balt=["\']#i', $tag ) ) {
                                ++$alt_absent;
                        } elseif ( preg_match( '#\balt=["\']\s*["\']#i', $tag ) ) {
                                ++$alt_vide;
                        }
                        if ( preg_match( '#\bsrcset=["\']#i', $tag ) ) {
                                ++$srcset;
                        }
                        if ( preg_match( '#loading=["\']lazy#i', $tag ) ) {
                                ++$lazy;
                        }
                }
                $ligne['images'] = count( $imgs );
                $ligne['alt'] = count( $imgs ) ? ( count( $imgs ) - $alt_vide - $alt_absent ) . '/' . count( $imgs ) : 'n/a';
                $ligne['srcset'] = $srcset . '/' . count( $imgs );
                $ligne['lazy'] = $lazy . '/' . count( $imgs );
                if ( count( $imgs ) >= 3 && 0 === $srcset ) {
                        $anom[] = array( 'UX-SRCSET-ABSENT', 'WARN', $cle, 'aucun srcset : le mobile telecharge la pleine taille' );
                }
                if ( $alt_absent > 0 && count( $imgs ) >= 2 ) {
                        $anom[] = array( 'A11Y-ALT-MANQUANT', 'WARN', $cle, $alt_absent . ' image(s) sans attribut alt du tout' );
                }
                $titres = array();
                preg_match_all( '#<h([1-6])\b[^>]*>(.*?)</h\1>#is', $h, $hm, PREG_SET_ORDER );
                foreach ( $hm as $t ) {
                        $titres[ (int) $t[1] ] = ( $titres[ (int) $t[1] ] ?? 0 ) + 1;
                }
                $ligne['h1'] = (int) ( $titres[1] ?? 0 );
                $ligne['titres'] = $titres ? implode( '>', array_keys( $titres ) ) : 'aucun';
                if ( $page_ok && 0 === ( $titres[1] ?? 0 ) ) {
                        $anom[] = array( 'A11Y-H1-ABSENT', 'WARN', $cle, 'aucun <h1> : hierarchie de lecture cassee, et le LLM perd le sujet de la page' );
                }
                if ( ( $titres[1] ?? 0 ) > 1 ) {
                        $anom[] = array( 'A11Y-H1-MULTIPLE', 'INFO', $cle, $titres[1] . ' <h1> sur la meme page' );
                }
                $lang_attr = preg_match( '#<html[^>]*\blang=["\']([^"\']+)#i', $h, $lm ) ? $lm[1] : '';
                $dir = preg_match( '#<html[^>]*\bdir=["\']rtl#i', $h ) ? 'rtl' : 'ltr';
                $ligne['lang'] = '' !== $lang_attr ? $lang_attr : 'NON';
                $ligne['dir'] = $dir;
                if ( 'ar' === $ctx['langue'] && 'rtl' !== $dir && '200' === (string) $ctx['code'] ) {
                        $anom[] = array( 'RTL-DIR-ABSENT', 'KO', $cle, 'page arabe sans dir="rtl" sur <html> : mise en page cassee' );
                }
                if ( 'ar' !== $ctx['langue'] && 'rtl' === $dir && $page_ok ) {
                        $anom[] = array( 'RTL-DIR-PHANTOME', 'WARN', $cle, 'dir="rtl" sur une page non-arabe' );
                }
                if ( '' === $lang_attr && '200' === (string) $ctx['code'] && $est_html ) {
                        $anom[] = array( 'A11Y-LANG-ABSENT', 'WARN', $cle, '<html> sans attribut lang' );
                }
                $vp = preg_match( '#<meta[^>]+name=["\']viewport#i', $h );
                $ligne['viewport'] = $vp ? 'oui' : 'NON';
                if ( ! $vp && '200' === (string) $ctx['code'] && $est_html ) {
                        $anom[] = array( 'UX-VIEWPORT-ABSENT', 'KO', $cle, 'pas de meta viewport : la page s\'affiche zoom-outee sur mobile' );
                }
                $liens = 0;
                $faible = 0;
                $noop = 0;
                preg_match_all( '#<a\b([^>]*)>(.*?)</a>#is', $h, $am, PREG_SET_ORDER );
                foreach ( $am as $a ) {
                        ++$liens;
                        $txt = $norm( $a[2] );
                        if ( '' === $txt || preg_match( '#^(cliquer ici|ici|en savoir plus|lire la suite|here|read more)$#iu', $txt ) ) {
                                ++$faible;
                        }
                        if ( preg_match( '#target=["\']_blank#i', $a[1] ) && ! preg_match( '#rel=["\'][^"\']*noopener#i', $a[1] ) ) {
                                ++$noop;
                        }
                }
                $ligne['liens'] = $liens;
                $ligne['ancres_faibles'] = $faible;
                if ( $faible > 2 ) {
                        $anom[] = array( 'UX-ANCRES-GENERIQUES', 'INFO', $cle, $faible . ' liens « cliquer ici / ici » (inutiles au lecteur d\'ecran)' );
                }
                if ( $noop > 0 ) {
                        $anom[] = array( 'SEC-NOOPENER', 'WARN', $cle, $noop . ' lien(s) target=_blank sans rel=noopener' );
                }
                preg_match_all( '#<input\b[^>]*\btype=["\'](text|email|tel|number|password|search)["\']#i', $h, $chm );
                $champs = (array) ( $chm[0] ?? array() );
                $sans_label = 0;
                foreach ( $champs as $c ) {
                        if ( preg_match( '#aria-label=["\']|placeholder=["\']|type=["\']hidden#i', $c ) ) {
                                continue;
                        }
                        $id = preg_match( '#\bid=["\']([^"\']+)#i', $c, $im2 ) ? $im2[1] : '';
                        if ( $id && preg_match( '#<label[^>]+for=["\']' . preg_quote( $id, '#' ) . '["\']#i', $h ) ) {
                                continue;
                        }
                        ++$sans_label;
                }
                $nonce = (bool) preg_match( '#name=["\'](?:_wpnonce|nonce|pk_nonce|wpnonce|_wp_http_referer)["\']#i', $h );
                $ligne['champs'] = count( $champs );
                $ligne['sans_label'] = $sans_label;
                if ( count( $champs ) >= 2 && $sans_label > 0 ) {
                        $anom[] = array( 'A11Y-CHAMP-SANS-LABEL', 'WARN', $cle, $sans_label . ' champ(s) sans label ni aria-label (le tunnel de depot doit marcher au clavier)' );
                }
                if ( count( $champs ) >= 3 && ! $nonce ) {
                        $anom[] = array( 'SEC-FORM-SANONCE', 'KO', $cle, 'formulaire sans jeton visible : CSRF possible' );
                }

                /* --- lisibilite par un LLM --- */
                $txt_seul = $norm( (string) preg_replace( '#<(script|style)\b.*?</\1>#is', '', $h ) );
                $mots = strlen( $txt_seul ) > 0 ? str_word_count( $txt_seul ) : 0;
                $body = preg_match( '#<body[^>]*>(.*)#is', $h, $bm ) ? (string) $bm[1] : $h;
                $body_txt = $norm( (string) preg_replace( '#<(script|style)\b.*?</\1>#is', '', $body ) );
                $poids = strlen( $h );
                $ligne['mots'] = $mots;
                $ligne['ratio_texte'] = round( 100 * strlen( $body_txt ) / max( 1, $poids ) ) . '%';
                $date_ok = (bool) preg_match( '#(article:published_time|datePublished|"dateModified"|datetime="\d{4}-\d{2}-\d{2})#i', $h );
                $ligne['date'] = $date_ok ? 'oui' : 'NON';
                if ( '200' === (string) $ctx['code'] && $page_ok && $mots < 60 ) {
                        $anom[] = array( 'LLM-TEXTE-MINCE', 'WARN', $cle, $mots . ' mots dans le HTML : un modele n\'a rien a citer, il citera un agregateur a ta place' );
                }
                                if ( preg_match( '~data-reactroot|__NEXT_DATA__|ng-app|window\.__NUXT|Vue\.mount|createRoot\(#|id=["\']app["\']~i', $h ) && $mots < 120 ) {
                        $anom[] = array( 'LLM-RENDU-JS', 'KO', $cle, 'contenu rendu en JavaScript et HTML < 120 mots : robots et modeles voient une page vide' );
                }
                if ( $page_ok && ! $date_ok ) {
                        $anom[] = array( 'LLM-SANS-DATE', 'INFO', $cle, 'aucune date publication/modification exploitable' );
                }
                foreach ( array( 'lorem ipsum', 'placeholder', 'TODO', 'non definito' ) as $mot) {
                        if ( false !== stripos( $txt_seul, $mot ) ) {
                                $anom[] = array( 'CONTENU-TEST-RESIDUEL', 'KO', $cle, 'texte « ' . $mot . ' » encore present en production de test' );
                                break;
                        }
                }

                $ligne = array_merge(
                        array(
                                'url' => (string) $ctx['url'],
                                'page' => (string) $ctx['libelle'],
                                'type' => (string) $ctx['type'],
                                'langue' => (string) ( $ctx['langue'] ?: 'fr' ),
                                'http' => (string) $ctx['code'],
                                'cache' => (string) ( $ctx['cache'] ?: '—' ),
                                'froid_ms' => (string) $ctx['temps_ms'],
                                'o_html' => (string) $poids,
                                'o_gz' => (string) ( $ctx['gz'] ?: '' ),
                                'sql' => (string) ( $ctx['sql'] ?: '' ),
                        ),
                        $ligne
                );
                return array( $ligne, $anom );
        }
}

if ( ! function_exists( 'partikulier_pk_diag_csv' ) ) {

        /**
         * Colonnes du tableau, dans l'ordre.
         *
         * @return array<int,string>
         */
        function partikulier_pk_diag_csv_colonnes() {
                return array( 'url', 'page', 'type', 'langue', 'http', 'cache', 'froid_ms', 'o_html', 'o_gz', 'sql', 'title', 'title_o', 'description', 'canonical', 'hreflang', 'robots', 'jsonld', 'og', 'og_image', 'twitter', 'images', 'alt', 'srcset', 'lazy', 'h1', 'titres', 'lang', 'dir', 'viewport', 'liens', 'ancres_faibles', 'champs', 'sans_label', 'mots', 'ratio_texte', 'date' );
        }

        /**
         * Une ligne CSV protegee.
         *
         * @param array $vals Valeurs.
         * @return string
         */
        function partikulier_pk_diag_csv_ligne( $vals ) {
                $o = array();
                foreach ( $vals as $v ) {
                        $s = is_scalar( $v ) ? (string) $v : (string) json_encode( $v );
                        $o[] = '"' . str_replace( '"', '""', $s ) . '"';
                }
                return implode( ',', $o );
        }
}

if ( ! function_exists( 'partikulier_pk_diag_run' ) ) {

        /**
         * Le diagnostic complet.
         *
         * @param array $opt {max_pages:int, mode:string(auto|local), reseau:bool,
         *                   campagne:bool, reprendre:bool, budget_s:int}.
         * @return array{texte:string,csv:string,lignes:array,anomalies:array,
         *                problemes:array,actions:array,stats:array,interrompu:bool}
         */
        function partikulier_pk_diag_run( $opt = array() ) {
                $opt = wp_parse_args(
                        $opt,
                        array(
                                'max_pages' => 0,
                                'mode' => 'auto',
                                'reseau' => true,
                                'campagne' => true,
                                'reprendre' => false,
                                'budget_s' => 0,
                        )
                );
                $deb = microtime( true );
                $ini = (int) ( ini_get( 'max_execution_time' ) ?: 0 );
                $budget = (int) $opt['budget_s'] > 0 ? (int) $opt['budget_s'] : ( $ini > 0 ? max( 15, $ini - 12 ) : 150 );
                $L = array();
                $anomalies = array();
                $problemes = array();
                $actions = array();
                $stats = array( 'url' => 0, 'http' => array(), 'cache' => array(), 'ko' => 0, 'warn' => 0, 'temps' => array() );
                $out = static function ( $s = '' ) use ( &$L ) { $L[] = (string) $s; };
                $lig = static function ( $k, $v ) use ( &$L ) { $L[] = sprintf( '  %-42s %s', $k, is_bool( $v ) ? ( $v ? 'OUI' : 'NON' ) : (string) $v ); };
                $tit = static function ( $t ) use ( $out ) { $out( '' ); $out( '== ' . $t . ' ==' ); };
                $signale = static function ( $code, $grav, $ou, $quoi ) use ( &$anomalies, &$stats ) {
                        $anomalies[] = array( $code, $grav, $ou, $quoi );
                        if ( 'INFO' !== $grav ) {
                                if ( 'KO' === $grav ) {
                                        ++$stats['ko'];
                                } else {
                                        ++$stats['warn'];
                                }
                        }
                };

                /* ---------- 0 ---------- */
                $tit( '0. CE QUI EST MESURE, ET COMMENT' );
                $theme = wp_get_theme();
                $hote = (string) ( isset( $_SERVER['HTTP_HOST'] ) ? wp_unslash( $_SERVER['HTTP_HOST'] ) : 'cli' );
                $lig( 'site', home_url( '/' ) . '  (hote ' . $hote . ')' );
                $lig( 'theme', $theme->get( 'Name' ) . ' v' . $theme->get( 'Version' ) . '  (' . $theme->get_stylesheet() . ')' );
                $lig( 'sonde integree', 'v' . PK_SONDE_VERSION . ' · genere ' . gmdate( 'c' ) );
                $lig( 'wordpress / php', get_bloginfo( 'version' ) . ' / ' . PHP_VERSION . ' (' . PHP_SAPI . ')' );
                $lig( 'budget de temps', $budget . ' s (max_execution_time=' . ( $ini ?: 'illimite' ) . ')' );
                $actifs = (array) get_option( 'active_plugins', array() );
                $est = static function ( $f ) use ( $actifs ) {
                        foreach ( $actifs as $p ) {
                                if ( 0 === stripos( (string) $p, (string) $f ) ) {
                                        return true;
                                }
                        }
                        return false;
                };
                $has_estatik = $est( 'estatik/' );
                $has_pll = $est( 'polylang/' );
                $lig( 'Estatik actif', $has_estatik );
                $lig( 'Polylang actif', $has_pll );
                if ( ! $has_estatik || ! $has_pll ) {
                        $out( '     >>> CE DIAGNOSTIC EST INCOMPLET : sans les deux, les pages, les images et' );
                        $out( '         les 3 langues du site reel ne sont pas la. Installe-les, relance.' );
                        $problemes[] = 'environnement incomplet : ' . ( $has_estatik ? '' : 'Estatik ' ) . ( $has_pll ? '' : 'Polylang ' ) . 'non actif';
                        $actions[] = 'installer/activer ' . ( $has_estatik ? 'Polylang' : 'Estatik et Polylang' ) . ' puis relancer le diagnostic';
                }
                $pl = (array) get_option( 'polylang', array() );
                if ( $has_pll ) {
                        $lig( 'Polylang : langues', implode( ',', partikulier_pk_diag_langues() ) );
                        $lig( 'Polylang : force_lang/hide_default/browser', (int) ( $pl['force_lang'] ?? -1 ) . ' / ' . ( empty( $pl['hide_default'] ) ? 'false' : 'true' ) . ' / ' . ( empty( $pl['browser'] ) ? 'false' : 'true' ) );
                }
                $lig( 'permaliens', (string) ( get_option( 'permalink_structure' ) ?: '(simples — /?p=123, le cache et le SEO ne sont pas dans les conditions reelles)' ) );
                if ( ! get_option( 'permalink_structure' ) ) {
                        $problemes[] = 'permaliens simples : les URLs testees ne sont pas celles des visiteurs ni des robots';
                        $actions[] = 'Reglages > Permaliens > Enregistrer (structure /%postname%/), puis relancer';
                }
                /* (array) cast puis ->publish : acces OBJET sur un TABLEAU = null en
                   PHP 8 (warning masque). Mesure sur banc : la sonde affichait
                   « 0/0 » avec 21 annonces publiees, et declenchait le probleme
                   « aucune annonce publiee » — la recommandation n°1 du rapport
                   (« importer les annonces ») reposait sur ce faux zero. */
                $comptes = (array) wp_count_posts( 'properties' );
                $lig( 'annonces publiees / en attente', (int) ( $comptes['publish'] ?? 0 ) . ' / ' . (int) ( $comptes['pending'] ?? 0 ) );
                if ( 0 === (int) ( $comptes['publish'] ?? 0 ) ) {
                        $problemes[] = 'aucune annonce publiee : rien a mesurer sur les pages d\'annonces';
                        $actions[] = 'importer les annonces (ou 20 minimum) avant toute conclusion';
                }

                /* ---------- 1. le chemin de mesure ---------- */
                $mode_http = true;
                $note = '';
                if ( 'local' === $opt['mode'] ) {
                        $mode_http = false;
                        $note = 'mode local demande : rendu sans passer par le serveur web (le module de cache N\'EST pas exerce)';
                } else {
                        $solo = $this_serveur_mono_ouvrier = partikulier_pk_diag_serveur_mono_ouvrier();
                        $ping = wp_remote_get( home_url( '/' ), array( 'timeout' => $solo ? 2 : 8, 'sslverify' => false, 'redirection' => 0, 'user-agent' => 'pk-sonde/' . PK_SONDE_VERSION ) );
                        if ( is_wp_error( $ping ) ) {
                                $mode_http = false;
                                if ( $this_serveur_mono_ouvrier ) {
                                        $note = 'serveur detecte comme mono-ouvrier : le site ne peut pas s\'appeler lui-meme, rendu local. Le module de cache N\'EST pas exerce ici — pour le tester, lancer php SONDE-PARTIKULIER.php ou demarrer le serveur avec des ouvriers';
                                } else {
                                        $note = 'le serveur ne peut pas se rappeler lui-meme (' . $ping->get_error_message() . ') : rendu local. Un robot externe rencontrera le meme mur.';
                                $problemes[] = 'boucle locale impossible : ' . $ping->get_error_message();
                                $actions[] = 'corriger d\'abord (DNS/hosts du domaine, open_basedir, bouclage bloque par l\'hebergeur ou Cloudflare) : sans cela Facebook ne lit rien';
                                }
                        } elseif ( (int) wp_remote_retrieve_response_code( $ping ) >= 500 ) {
                                $mode_http = false;
                                $note = 'le serveur repond ' . wp_remote_retrieve_response_code( $ping ) . ' a une requete interne : rendu local, erreur a corriger avant tout';
                                $problemes[] = 'HTTP ' . wp_remote_retrieve_response_code( $ping ) . ' sur la page d\'accueil rappelée depuis le serveur';
                        } else {
                                $note = 'HTTP complet depuis le serveur : le module de cache est reellement exerce';
                        }
                }
                $lig( 'chemin de mesure', $note );

                $cache_present = class_exists( 'Partikulier_Cache' );
                $dir_cache = '';
                $hote_http = partikulier_pk_diag_hote_observe();
                $hote_http = partikulier_pk_diag_hote_observe();
                if ( $cache_present ) {
                        $dir_cache = partikulier_pk_diag_entete_cache( home_url( '/' ), $hote_http );
                        $dir_cache = $dir_cache['dir'];
                }
                /* Les taxonomies resolues, pas devinees : un controle qui lit une autre
                   taxonomie que celle que le site ecrit ne peut pas conclure (le compte de
                   « termes parasites » depend entierement de ce choix). */
                $taxo = partikulier_pk_diag_taxonomies();
                if ( $taxo ) {
                        $desc = array();
                        foreach ( $taxo as $lbl => $nom ) {
                                $nb = wp_count_terms( array( 'taxonomy' => $nom, 'hide_empty' => false ) );
                                $desc[] = $lbl . '=' . $nom . ' (' . ( is_wp_error( $nb ) ? '?' : (int) $nb ) . ')';
                        }
                        $lig( 'taxonomies resolues (termes)', implode( ', ', $desc ) );
                        $dup = partikulier_pk_diag_doublons( $taxo );
                        if ( $dup ) {
                                $lig( 'termes dupliques', count( $dup ) . ' noms reviennent sans liaison Polylang (exemples : ' . implode( ', ', array_slice( $dup, 0, 4 ) ) . ')' );
                                $problemes[] = 'termes dupliques dans les taxonomies commerciales (' . count( $dup ) . ' noms, hors traductions Polylang reliees) : le menu et les filtres afficheront plusieurs fois la meme entree. Ce ne sont pas des termes mal classes, ce sont des imports repetes.';
                                $actions[] = 'fusionner les doublons AVANT l\'import des 800 annonces : sinon le nombre de doublons sera multiplie d\'autant';
                        }
                } else {
                        $lig( 'taxonomies resolues', "AUCUNE : le theme declare " . ( defined( 'PARTIKULIER_ESTATIK_TYPE_TAXONOMY' ) ? PARTIKULIER_ESTATIK_TYPE_TAXONOMY : '?' ) . " et " . ( defined( 'PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY' ) ? PARTIKULIER_ESTATIK_CATEGORY_TAXONOMY : '?' ) . ", aucune taxonomie enregistree : le controle des filtres ne peut pas conclure" );
                        $problemes[] = 'aucune taxonomie commerciale resolue : les filtres « Achat ou location », types et villes ne peuvent pas etre verifies';
                }
                $lig( 'module de cache charge', $cache_present );

                /* Les filtres du catalogue (ville, type, action) sont la source du « je clique sur
                   Casablanca, 0 annonce » : on lit la clause reellement construite et on dit si
                   elle est morte par construction (term_id + Polylang) ou si elle ne trouve aucun id. */
                $pk_cl = function_exists( 'partikulier_pk_diag_filtre_clauses' ) ? partikulier_pk_diag_filtre_clauses() : array( array(), array() );
                if ( $pk_cl[0] ) {
                        $lig( 'clauses des filtres du catalogue', implode( ' | ', $pk_cl[0] ) );
                }
                if ( $pk_cl[1] ) {
                        foreach ( $pk_cl[1] as $pk_s ) {
                                $signale( 'FILTRE-CLAUSE-MORTE', 'KO', 'filtres du catalogue', $pk_s );
                                ++$stats['ko'];
                                                $actions[] = 'les clauses de filtre doivent porter field = term_taxonomy_id (mesure: term_id rend 0, term_taxonomy_id rend les fiches) ; controler aussi que le terme propose par l\'autocompletion existe reellement et n\'est pas un doublon a 0 fiche';
                        }
                }
                if ( $dir_cache ) {
                        $perms = @fileperms( $dir_cache );
                        $lig( 'dossier de cache', str_replace( (string) ABSPATH, '', $dir_cache ) . ' · ' . ( is_writable( $dir_cache ) ? 'ecrivable (' . substr( sprintf( '%o', (int) $perms & 0777 ), -4 ) . ')' : 'NON ECRIVABLE → cache impossible (euid=' . getmyuid() . ')' ) );
                        if ( ! is_writable( $dir_cache ) ) {
                                $problemes[] = 'le dossier de cache n\'est pas inscriptible par le processus php : ' . $dir_cache . ' (euid=' . getmyuid() . ', perms=' . substr( sprintf( '%o', (int) $perms & 0777 ), -4 ) . ')';
                                $actions[] = 'sur le serveur : chown le dossier au user du pool php (c\'est exactement la cause des FAIL de la CI)';
                        }
                } else {
                        $lig( 'dossier de cache', 'n/a' );
                }

                /* ---------- 2. toutes les pages ---------- */
                $tit( '1. TOUTES LES PAGES DU SITE (et pas seulement l\'accueil)' );
                $toutes = partikulier_pk_diag_pages( array( 'max_listings' => (int) $opt['max_pages'] ) );
                if ( (int) $opt['max_pages'] > 0 ) {
                        $toutes = array_slice( $toutes, 0, (int) $opt['max_pages'] );
                }
                $depuis = 0;
                if ( ! empty( $opt['reprendre'] ) ) {
                        $prog = get_option( 'pk_sonde_progress', array() );
                        if ( is_array( $prog ) && ! empty( $prog['i'] ) ) {
                                $depuis = (int) $prog['i'];
                        }
                } else {
                        delete_option( 'pk_sonde_progress' );
                }
                $par_type = array();
                foreach ( $toutes as $p ) {
                        $par_type[ $p['type'] ] = ( $par_type[ $p['type'] ] ?? 0 ) + 1;
                }
                $lig( 'urls a controle', count( $toutes ) . ( $depuis ? ' (reprise depuis #' . $depuis . ')' : '' ) );
                $lig( 'repartition', implode( ', ', array_map( static fn ( $k, $v ) => $k . '=' . $v, array_keys( $par_type ), $par_type ) ) );

                $out( '' );
                $out( '  ' . str_pad( 'url', 48 ) . str_pad( 'http', 6 ) . str_pad( 'cache', 7 ) . str_pad( 'ms', 8 ) . str_pad( 'o_html', 9 ) . str_pad( 'alt', 7 ) . 'h1 jsonld' );
                $out( '  ' . str_repeat( '-', 108 ) );

                $rows = array();
                $interruptu = false;
                $i = $depuis;
                $urls_cassees = 0;
                $urls_reparees = 0;
                $en_tete = array( 'user-agent' => 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/151.0 Safari/537.36' );
                $fetch = static function ( $url, $extra = array() ) use ( $mode_http ) {
                        $a = array_merge( array( 'timeout' => 25, 'sslverify' => false, 'redirection' => 0 ), $extra );
                        return wp_remote_get( $url, $a );
                };

                for ( ; $i < count( $toutes ); $i++ ) {
                        if ( ( microtime( true ) - $deb ) > $budget ) {
                                $interruptu = true;
                                update_option( 'pk_sonde_progress', array( 'i' => $i, 'total' => count( $toutes ) ), false );
                                break;
                        }
                        $p = $toutes[ $i ];
                        $cookie = array( 'Cookie' => 'pll_language=' . ( $p['langue'] ?: ( partikulier_pk_diag_langues()[0] ?? 'fr' ) ) );
                        $code = '';
                        $html = '';
                        $ms = 0.0;
                        $cache = '';
                        $essai_repare = '';
                        $url_mesuree = $p['url'];
                        $loc_header  = '';
                        $url_mesuree = $p['url'];
                        if ( $mode_http ) {
                                $t0 = microtime( true );
                                $rep = $fetch( $p['url'], array( 'headers' => array_merge( $en_tete, $cookie ), 'user-agent' => $en_tete['user-agent'] ) );
                                $ms = round( ( microtime( true ) - $t0 ) * 1000, 1 );
                                if ( is_wp_error( $rep ) ) {
                                        $code = 'ERR';
                                        $err_fetch = $rep->get_error_message();
                                } else {
                                        $code = (string) wp_remote_retrieve_response_code( $rep );
                                        $html = (string) wp_remote_retrieve_body( $rep );
                                        $hd = wp_remote_retrieve_headers( $rep );
                                        $hd = is_object( $hd ) ? array_change_key_case( (array) $hd->getAll(), CASE_LOWER ) : array_change_key_case( (array) $hd, CASE_LOWER );
                                        $cache = isset( $hd['x-partikulier-cache'] ) ? (string) ( is_array( $hd['x-partikulier-cache'] ) ? implode( '|', $hd['x-partikulier-cache'] ) : $hd['x-partikulier-cache'] ) : '';
                                                /* une URL qui sort de la base mais ne repond pas : le theme rend peut-etre la
                                                   meme page sous une autre langue. On verifie AVANT d'accuser la page, et avec
                                                   le MEME construteur que la liste des pages — la version precedente empilait
                                                   /fr/ + /ar/ et testait des URLs que personne ne publie (261 sur 416 mesurees). */
                                                if ( '404' === $code && false === strpos( (string) $p['url'], '?p=' ) ) {
                                                        $langues_sonde = partikulier_pk_diag_langues();
                                                        foreach ( $langues_sonde as $l2 ) {
                                                                $alt = partikulier_pk_diag_url_langue( (string) $p['url'], (string) $l2, $langues_sonde );
                                                                if ( '' === $alt ) {
                                                                        continue;
                                                                }
                                                                $r2 = $fetch( $alt, array( 'headers' => $cookie ) );
                                                                if ( is_wp_error( $r2 ) || '200' !== (string) wp_remote_retrieve_response_code( $r2 ) ) {
                                                                        continue;
                                                                }
                                                                $essai_repare = $alt;
                                                                $url_mesuree   = $alt;
                                                                $html         = (string) wp_remote_retrieve_body( $r2 );
                                                                $hd2 = wp_remote_retrieve_headers( $r2 );
                                                                $hd2 = is_object( $hd2 ) ? array_change_key_case( (array) $hd2->getAll(), CASE_LOWER ) : array();
                                                                if ( isset( $hd2['x-partikulier-cache'] ) ) {
                                                                        $cache = (string) ( is_array( $hd2['x-partikulier-cache'] ) ? implode( '|', $hd2['x-partikulier-cache'] ) : $hd2['x-partikulier-cache'] );
                                                                }
                                                                $code = '200';
                                                                ++$urls_reparees;
                                                                break;
                                                        }
                                                        if ( '' === $essai_repare ) {
                                                                ++$urls_cassees;
                                                        }
                                        }
                                }
                        } else {
                                $loc = partikulier_pk_diag_rendu_local( $p['url'] );
                                $local = is_array( $loc ) ? $loc : array( 'ok' => false );
                                $code = empty( $local['ok'] ) ? '404' : '200';
                                $html = empty( $loc[ 'titre' ] ) ? '' : '';
                        }

                        $gz = '';
                        $fichier = '';
                        if ( $dir_cache ) {
                                $c = partikulier_pk_diag_entete_cache( $p['url'] );
                                $fichier = $c['fichier'];
                                if ( file_exists( $fichier ) ) {
                                        $gz = file_exists( $c['gz'] ) ? (string) filesize( $c['gz'] ) : '';
                                }
                        }
                        $stats['http'][ $code ?: '?' ] = ( $stats['http'][ $code ?: '?' ] ?? 0 ) + 1;
                        if ( $mode_http ) {
                                $stats['cache'][ $cache ?: 'aucun' ] = ( $stats['cache'][ $cache ?: 'aucun' ] ?? 0 ) + 1;
                        }
                        /* Le test cache froid/chaud (section 2) choisit ses echantillons APRES cette
                           boucle : sans ce marqueur, il prend les 12 premieres URLs du catalogue, y
                           compris des 404 et des 301 — des places perdues qui ne mesurent rien. */
                        $toutes[ $i ]['code_mesure'] = (string) $code;
                        ++$stats['url'];
                        if ( $ms > 0 ) {
                                $stats['temps'][] = $ms;
                        }
                        list( $ligne, $anom ) = partikulier_pk_diag_analyse( $html, array(
                                        'url' => $p['url'], 'libelle' => $p['libelle'], 'type' => $p['type'], 'langue' => $p['langue'],
                                        'code' => $code, 'temps_ms' => $ms, 'cache' => $cache, 'gz' => $gz, 'sql' => '',
                                        'local' => ! $mode_http, 'route' => ( isset( $local['type'] ) && $local['type'] ) ? $local['type'] : ( isset( $local['raison'] ) ? $local['raison'] : '' ),
                                ) );
                        foreach ( $anom as $a ) {
                                $signale( (string) $a[0], (string) $a[1], (string) $a[2], (string) $a[3] );
                        }
                        if ( in_array( $code, array( '500', '502', '503', '504', 'ERR' ), true ) ) {
                                $signale( 'HTTP-CASSE', 'KO', $p['libelle'], 'HTTP ' . $code . ' sur ' . $p['url'] . ( isset( $err_fetch ) && $err_fetch ? ' (' . $err_fetch . ')' : '' ) );
                        }
                        if ( '404' === $code && in_array( $p['type'], array( 'single', 'page', 'taxonomy', 'archive' ), true ) ) {
                                $signale( 'URL-INTRouVABLE', 'KO', $p['libelle'], $p['url'] . ' repond 404 : l\'URL sort de la base (get_permalink) mais la route ne la sert pas. Verifier rewrite rules / Polylang (hide_default, browser) / flush.');
                                ++$stats['ko'];
                        }
                        if ( '' !== $essai_repare ) {
                                $signale( 'URL-SANS-PREFIXE', 'WARN', $p['libelle'], 'sans prefixe de langue : 404 ; avec (' . $essai_repare . ') : 200. Les liens partages hors du contexte Polylang casseront.');
                                ++$stats['warn'];
                        }
                        if ( '200' === $code && strlen( $html ) < 3000 && ! in_array( $p['type'], array( '404', 'robots', 'feed' ), true ) ) {
                                $signale( 'PAGE-QUASI-VIDE', 'KO', $p['libelle'], 'HTTP 200 avec un corps de ' . strlen( $html ) . ' o : rien a afficher, rien a indexer');
                                ++$stats['ko'];
                        }
                        if ( '404' === $p['type'] && '404' !== $code ) {
                                $signale( 'SEO-SOFT-404', 'KO', 'page inexistante', 'repond ' . $code . ' au lieu de 404 : un moteur indexera du vide');
                                ++$stats['ko'];
                        }
                        if ( $ms > 600 ) {
                                $signale( 'PERF-LENTE', 'WARN', $p['libelle'], $ms . ' ms pour une page non cachee : au-dessus du seuil utile (600 ms)');
                                ++$stats['warn'];
                        }
                        $rows[] = $ligne;
                        $out( '  ' . str_pad( substr( (string) wp_parse_url( $p['url'], PHP_URL_PATH ), 0, 47 ), 48 )
                                . str_pad( (string) $code, 6 )
                                . str_pad( $cache ?: '—', 7 )
                                . str_pad( (string) $ms, 8 )
                                . str_pad( number_format( strlen( $html ) ), 9 )
                                . str_pad( (string) $ligne['alt'], 7 )
                                . str_pad( (string) $ligne['h1'], 3 ) . ' ' . substr( (string) $ligne['jsonld'], 0, 26 ) );
                }
                $lig( 'urls dont l\'URL de base etait cassee', $urls_cassees . ' (reparees par prefixe de langue : ' . $urls_reparees . ')' );

                /* ---------- 3. cache froid/chaud ---------- */
                $echantillons = array();
                foreach ( $toutes as $p ) {
                        /* On ne teste que des pages reellement servies en 200 : les 404 et 301
                           gaspillaient la moitie des 12 places (mesure staging : /annonces/page/2/
                           en 404 et des /property/ en 301 prenaient les premiers rangs). Les URLs
                           non visitees (diagnostic interrompu) sont exclues aussi. */
                        if ( ! isset( $p['code_mesure'] ) || '200' !== (string) $p['code_mesure'] ) {
                                continue;
                        }
                        if ( in_array( $p['type'], array( 'front', 'archive', 'single', 'taxonomy' ), true ) ) {
                                $echantillons[] = $p;
                        }
                        if ( count( $echantillons ) >= 12 ) {
                                break;
                        }
                }
                if ( $mode_http && $cache_present && $echantillons ) {
                        $tit( '2. CACHE DU THEME : VIDÉ, PUIS FROID, PUIS CHAUD' );
                        $froid = array();
                        $chaud = array();
                        $hit = 0;
                        $miss = 0;
                        $sur_disque = 0;
                        $out( '  ' . str_pad( 'url', 39 ) . str_pad( 'apres purge', 15 ) . str_pad( 'froid', 12 ) . str_pad( 'chaud', 12 ) . 'disque (octets sur le disque, verifie par la sonde) | cache d\'amont' );
                        $out( '  ' . str_repeat( '-', 100 ) );
                        /* Le module reserve root_is_cacheable() (prive) : on relit les memes reglages,
                           sans reflexion, pour ne pas faire dépendre le rapport d'un detail d'API. */
                        $n          = count( $echantillons );
                        $pk_pl0     = (array) get_option( 'polylang', array() );
                        $pk_root_ok = ! class_exists( 'Partikulier_Cache' )
                                || ! defined( 'POLYLANG_VERSION' )
                                || ( (int) ( $pk_pl0['force_lang'] ?? 0 ) >= 1 && empty( $pk_pl0['browser'] ) && empty( $pk_pl0['redirect_lang'] ) );
                        $up_hit = 0; $up_miss = 0; $couvert_amont = 0; $trans = 0; $persist = 0; $urls_trans = array(); $urls_pers = array();
                        foreach ( $echantillons as $p ) {
                                $c = partikulier_pk_diag_entete_cache( $p['url'] );
                                @unlink( $c['fichier'] );
                                @unlink( $c['gz'] );
                                $mesure = static function () use ( $p, $fetch ) {
                                        $t0 = microtime( true );
                                        /* Aucun Cookie force ici : le theme n'exclut pas pll_language de son
                                           controle de cookies, mais un cookie fabrique par la sonde peut tout
                                           de meme changer le rendu (langue, blocs connects). On mesure le
                                           visiteur reel : en-tete Accept-Encoding seulement. */
                                        $r = $fetch( $p['url'], array( 'headers' => array( 'Accept-Encoding' => 'gzip' ) ) );
                                        $ms = round( ( microtime( true ) - $t0 ) * 1000, 1 );
                                        if ( is_wp_error( $r ) ) {
                                                return array( $ms, 'ERR', '', array( 'up' => '', 'age' => '', 'cc' => '', 'code' => '' ) );
                                        }
                                        $hd = wp_remote_retrieve_headers( $r );
                                        $hd = is_object( $hd ) ? array_change_key_case( (array) $hd->getAll(), CASE_LOWER ) : array();
                                        $pick = static function ( $k ) use ( $hd ) {
                                                return isset( $hd[ $k ] ) ? trim( (string) ( is_array( $hd[ $k ] ) ? implode( '|', $hd[ $k ] ) : $hd[ $k ] ) ) : '';
                                        };
                                        return array(
                                                $ms,
                                                $pick( 'x-partikulier-cache' ),
                                                (string) wp_remote_retrieve_body( $r ),
                                                array(
                                                        'up'    => $pick( 'x-litespeed-cache' ),
                                                        'age'   => $pick( 'age' ),
                                                        'cc'    => $pick( 'cache-control' ),
                                                        'code'  => (string) wp_remote_retrieve_response_code( $r ),
                                                ),
                                        );
                                };
                                list( $m0, $c0, $b0, $x0 ) = $mesure();
                                list( $m1, $c1, $b1, $x1 ) = $mesure();
                                list( $m2, $c2, $b2, $x2 ) = $mesure();
                                /* 5xx : on reessaie une fois, 3 s apres. Un 500 qui disparait est une
                                   saturation de l'ouvreur PHP (le test de charge de cette page vient de
                                   la produire) ; un 500 qui reste est un defaut du site. Ce ne sont pas
                                   les memes mots a donner au developpeur, donc on separe les deux. */
                                $pk_plus_tard = static function ( $b, $code ) {
                                        $code = (int) $code;
                                        return $code >= 500 || ( $code > 0 && $code < 300 && strlen( (string) $b ) < 3000 );
                                };
                                if ( $pk_plus_tard( $b1, isset( $x1['code'] ) ? $x1['code'] : '' ) || $pk_plus_tard( $b2, isset( $x2['code'] ) ? $x2['code'] : '' ) ) {
                                        usleep( 3000000 );
                                        list( $pk_m, $pk_c, $pk_b, $pk_x ) = $mesure();
                                        if ( strlen( $pk_b ) > 3000 && preg_match( '/^2\d\d$/', isset($pk_x['code']) ? $pk_x['code'] : '' ) ) {
                                                ++$trans;
                                                $urls_trans[] = (string) wp_parse_url( $p['url'], PHP_URL_PATH ) . ' (' . ( $x2['code'] ?: '?' ) . ' puis ' . $pk_x['code'] . ')';
                                                if ( strlen( $b2 ) < 3000 ) { $c2 = $pk_c; $b2 = $pk_b; $m2 = $pk_m; $x2 = $pk_x; }
                                                if ( strlen( $b1 ) < 3000 ) { $c1 = $pk_c; $b1 = $pk_b; $m1 = $pk_m; $x1 = $pk_x; }
                                        } else {
                                                ++$persist;
                                                $urls_pers[] = (string) wp_parse_url( $p['url'], PHP_URL_PATH ) . ' (' . ( $pk_x['code'] ?: '?' ) . ')';
                                        }
                                }
                                $froid[] = $m1;
                                $chaud[] = $m2;
                                $hit = 'HIT' === $c2 ? $hit + 1 : $hit;
                                $miss = 'MISS' === $c1 ? $miss + 1 : $miss;
                                $pk_up1 = isset( $x1['up'] ) ? strtolower( (string) $x1['up'] ) : '';
                                $pk_up2 = isset( $x2['up'] ) ? strtolower( (string) $x2['up'] ) : '';
                                if ( 'hit' === $pk_up1 || 'hit' === $pk_up2 ) {
                                        ++$up_hit;
                                } elseif ( '' !== $pk_up1 ) {
                                        ++$up_miss;
                                }
                                $ecrit = file_exists( $c['fichier'] );
                                $sur_disque = $ecrit ? $sur_disque + 1 : $sur_disque;
                                if ( $ecrit && filesize( $c['fichier']) < 100 ) {
                                        $signale( 'CACHE-ENTREE-VIDE', 'KO', $p['libelle'], 'entree de ' . filesize( $c['fichier'] ) . ' o sur le disque : c\'est le poison de la redirection (page blanche garantie)');
                                        ++$stats['ko'];
                                }
                                if ( 'HIT' === $c2 && '' !== $b1 && $b1 !== $b2 ) {
                                        $signale( 'CACHE-CORPS-DIFFERENT', 'KO', $p['libelle'], 'le HIT ne rend pas le meme octet-a-octet que la page fraiche (' . strlen( $b1 ) . ' vs ' . strlen( $b2 ) . ' o)');
                                        ++$stats['ko'];
                                }
                                if ( 'MISS' === $c2 && strlen( $b2 ) > 3000 && 'hit' !== $pk_up2 ) {
                                        $signale( 'CACHE-JAMAIS-HIT', 'KO', $p['libelle'], '3e passage toujours MISS sur une page pleine : le module ne se remplit pas');
                                        ++$stats['ko'];
                                } elseif ( 'MISS' === $c2 && strlen( $b2 ) > 3000 && 'hit' === $pk_up2 ) {
                                        /* MISS explique par le cache d'amont : la page a ete servie sans passer
                                           par WordPress, l'en-tete MISS est celui d'un rendu anterieur congele
                                           dans la copie. Ce n'est pas un defaut du module — CACHE-AMONT-SHADOW
                                           resume deja la situation — mais le rapport doit compter ces pages
                                           separement pour ne plus conclure « le cache ne fonctionne pas » sur
                                           un site ou il fonctionne derriere LiteSpeed (mesure staging : verdict
                                           KO sur 63 HIT reels). */
                                        ++$couvert_amont;
                                }
                                $why = '';
                                $pk_home_path = trailingslashit( (string) ( wp_parse_url( home_url( '/' ), PHP_URL_PATH ) ?: '/' ) );
                                if ( $pk_home_path === trailingslashit( (string) wp_parse_url( $p['url'], PHP_URL_PATH ) ) && ! $pk_root_ok ) {
                                        $pk_pl = (array) get_option( 'polylang', array() );
                                        $why = ' [home du site non cacheable ici : force_lang=' . (int) ( $pk_pl['force_lang'] ?? 0 )
                                                . ' browser=' . ( empty( $pk_pl['browser'] ) ? '0' : '1' )
                                                . ' redirect_lang=' . ( empty( $pk_pl['redirect_lang'] ) ? '0' : '1' ) . ']';
                                } elseif ( false !== strpos( (string) wp_parse_url( $p['url'], PHP_URL_QUERY ), '?' ) || '' !== (string) wp_parse_url( $p['url'], PHP_URL_QUERY ) ) { $why = ' [URL a parametre : exclue du cache par is_cacheable_request]'; }
                                elseif ( 'HIT' === $c2 && 'MISS' === $c1 && 'RIEN' === $why ) { $why = ''; }
                                $out( '  ' . str_pad( substr( (string) wp_parse_url( $p['url'], PHP_URL_PATH ), 0, 38 ), 39 )
                                        . str_pad( ( $c0 ?: '—' ) . $why, 15 )
                                        . str_pad( ( $c1 ?: '—' ) . ' ' . $m1 . 'ms', 12 )
                                        . str_pad( ( $c2 ?: '—' ) . ' ' . $m2 . 'ms', 12 )
                                        . ( $ecrit ? number_format( (int) filesize( $c['fichier'] ) ) . ' o' . ( file_exists( $c['gz'] ) ? ' (gz ' . number_format( (int) filesize( $c['gz'] ) ) . ')' : '' ) : 'RIEN' )
                                        . ( empty( $x2['up'] ) ? '' : '  | amont: litespeed=' . $x2['up'] . ( empty( $x2['age'] ) ? '' : ' age=' . $x2['age'] ) ) );
                        }
                        $lig( 'moyenne froid / chaud', round( array_sum( $froid ) / max( 1, count( $froid ) ), 1 ) . ' ms / ' . round( array_sum( $chaud ) / max( 1, count( $chaud ) ), 1 ) . ' ms' );
                        $lig( 'economie du cache', ( array_sum( $froid ) > 0 ? round( 100 * ( 1 - array_sum( $chaud ) / array_sum( $froid ) ), 1 ) : 0 ) . '%' );
                        $lig( 'HIT confirmes', $hit . '/' . $n . ' (MISS au 2e passage : ' . $miss . ')' );
                        $lig( 'entrees verifiees sur disque', $sur_disque . '/' . $n );
                        if ( $couvert_amont > 0 ) {
                                $lig( 'MISS couverts par le cache d\'amont', $couvert_amont . '/' . $n . ' (servies par LiteSpeed/HCDN : le MISS du theme n\'est pas un defaut)' );
                        }
                        if ( $up_hit > 0 ) {
                                $signale( 'CACHE-AMONT-SHADOW', 'WARN', 'cache de l\'hebergeur', $up_hit . ' page(s) du rapport servies par LiteSpeed/HCDN (x-litespeed-cache: hit) : WordPress n\'a pas ete interroge sur ces passages-la, donc un MISS du theme sur ces URL ne prouve pas que son module est casse.' );
                                $lig( 'cache d\'amont (LiteSpeed/HCDN)', $up_hit . ' HIT / ' . $up_miss . ' MISS vus sur les ' . $n . ' pages testees' );
                                $actions[] = 'pour mesurer le cache DU THEME sans celui de l\'hebergeur devant : vider le cache HCDN (hPanel > Reglages du site > Cache > vider) puis relancer ce lien, ou lire la ligne « disque » : une entree ecrite dans wp-content/uploads/partikulier-cache est une preuve que le module fonctionne, meme si le visiteur recoit la copie de l\'hebergeur';
                        }
                        if ( $trans > 0 ) {
                                $signale( 'HTTP-5XX-TRANSITOIRE', 'WARN', 'hebergement', $trans . ' reponse(s) 5xx devenues normales 3 s plus tard (' . implode( ', ', array_slice( $urls_trans, 0, 4 ) ) . ( count( $urls_trans ) > 4 ? '…' : '' ) . ') : saturation de l\'ouvreur PHP apres le test de charge, pas un defaut du theme.' );
                        }
                        if ( $persist > 0 ) {
                                $signale( 'HTTP-5XX-PERSISTANT', 'KO', 'hebergement', $persist . ' page(s) encore en 5xx 3 s apres le reessai (' . implode( ', ', array_slice( $urls_pers, 0, 4 ) ) . ( count( $urls_pers ) > 4 ? '…' : '' ) . ') : defaut du site, a ouvrir en priorite.' );
                                ++$stats['ko'];
                        }
                        if ( 0 === $hit && 0 === $up_hit && 0 === $couvert_amont && 0 === $sur_disque ) {
                                $problemes[] = 'aucune page servie en HIT et aucune entree ecrite : le module de cache ne fonctionne pas sur ce serveur';
                                $actions[] = 'verifier l\'ecrivabilite du dossier, puis le marqueur strtoupper sur REQUEST_METHOD (point G1 du CDC)';
                        } elseif ( 0 === $hit && ( $up_hit + $couvert_amont ) > 0 ) {
                                /* 0 HIT observe MAIS l'amont a servi des pages : le verdict « module casse »
                                   serait faux (mesure staging : 73 HIT caches derriere LiteSpeed). On dit ce
                                   qu'il faut faire pour mesurer le theme seul au lieu d'accuser le module. */
                                $problemes[] = 'aucun HIT du THEME observe sur l\'echantillon, mais ' . ( $up_hit + $couvert_amont ) . ' passage(s) servis par le cache de l\'hebergeur : vider le cache HCDN (hPanel) puis relancer la sonde pour mesurer le theme seul';
                        } elseif ( 0 === $hit && $sur_disque > 0 ) {
                                $problemes[] = 'aucun HIT observe mais ' . $sur_disque . ' entree(s) ecrite(s) sur le disque : le module ecrit, l\'amont sert ; vider le cache HCDN puis relancer pour verifier la lecture';
                        }

                        if ( ! empty( $opt['campagne'] ) ) {
                                $out( '' );
                                $out( '  --- LES URLS QUE LES PUBS PRODUISENT (fbclid, gclid, utm, tri) ---' );
                                $base = $echantillons[0]['url'];
                                $variantes = array(
                                        'organique' => $base,
                                        'fbclid' => add_query_arg( 'fbclid', 'IwAR0abc', $base ),
                                        'gclid' => add_query_arg( 'gclid', 'Cj0KCQ', $base ),
                                        'utm complet' => add_query_arg( array( 'utm_source' => 'meta', 'utm_medium' => 'cpc', 'utm_campaign' => 'lancement' ), $base ),
                                        'tri prix' => add_query_arg( 'pk_order', 'price', $base ),
                                        'tri date' => add_query_arg( 'pk_order', 'date', $base ),
                                );
                                $empreintes = array();
                                $codes = array();
                                $caches = array();
                                foreach ( $variantes as $nom => $u ) {
                                        $r = $fetch( $u, array( 'headers' => array( 'Cookie' => 'pll_language=fr' ) ) );
                                        $r2 = $fetch( $u, array( 'headers' => array( 'Cookie' => 'pll_language=fr' ) ) );
                                        if ( is_wp_error( $r ) ) {
                                                $codes[ $nom ] = 'ERR';
                                                continue;
                                        }
                                        $empreintes[ $nom ] = substr( md5( (string) wp_remote_retrieve_body( $r ) ), 0, 10 );
                                        $codes[ $nom ] = (string) wp_remote_retrieve_response_code( $r );
                                        $hd = wp_remote_retrieve_headers( $r2 );
                                        $hd = is_object( $hd ) ? array_change_key_case( (array) $hd->getAll(), CASE_LOWER ) : array();
                                        $caches[ $nom ] = isset( $hd['x-partikulier-cache'] ) ? (string) $hd['x-partikulier-cache'] : 'aucun';
                                }
                                $out( '  ' . str_pad( 'variante', 14 ) . str_pad( 'http', 6 ) . str_pad( 'cache (2e appel)', 18 ) . 'md5 du corps' );
                                foreach ( $variantes as $nom => $u ) {
                                        $out( '  ' . str_pad( (string) $nom, 14 ) . str_pad( (string) ( $codes[ $nom ] ?? '' ), 6 ) . str_pad( (string) ( $caches[ $nom ] ?? '' ), 18 ) . (string) ( $empreintes[ $nom ] ?? '' ) );
                                }
                                $hit_campagne = count( array_filter( $caches, static fn ( $c ) => 'HIT' === $c ) );
                                $lig( 'variantes de campagne en HIT', $hit_campagne . '/' . count( $variantes ) );
                                if ( 0 === $hit_campagne ) {
                                        $problemes[] = 'les URLs de campagne ne beneficient d\'AUCUN cache : chaque clic paye une requete pleine (et un visiteur de pub ne voit jamais la version rapide)';
                                        $actions[] = 'point « URLs de campagne » du CDC : ignorer les parametres de suivi dans la cle de cache, garder ceux de tri — a decider, pas a bricoler';
                                }
                        }
                }

                /* ---------- 4. reseaux sociaux et robots ---------- */
                if ( ! empty( $opt['reseau'] ) ) {
                        $tit( '3. CE QUE VOIENT FACEBOOK, X, WHATSAPP, GOOGLE, DISCORD ET LES ROBOTS D\'IA' );
                        if ( ! $mode_http ) {
                                $out( '  indisponible en mode local : ces controles exigent des requetes HTTP reelles.' );
                        } else {
                                $agents = partikulier_pk_diag_agents();
                                $ech = array();
                                foreach ( $toutes as $p ) {
                                        if ( in_array( $p['type'], array( 'front', 'archive', 'single', 'taxonomy' ), true ) ) {
                                                $ech[] = $p;
                                        }
                                        if ( count( $ech ) >= 6 ) {
                                                break;
                                        }
                                }
                                if ( ! $ech ) {
                                        $ech = array_slice( $toutes, 0, 3 );
                                }
                                $largeur = 11;
                                $out( '  ' . str_pad( 'page', 40 ) . implode( '', array_map( static fn ( $k ) => str_pad( $k, $largeur ), array_keys( $agents ) ) ) );
                                $out( '  ' . str_repeat( '-', 40 + $largeur * count( $agents ) ) );
                                $cloaking = array();
                                foreach ( $ech as $p ) {
                                        $ligne_ag = str_pad( substr( (string) wp_parse_url( $p['url'], PHP_URL_PATH ), 0, 39 ), 40 );
                                        $humain = '';
                                        foreach ( $agents as $nom => $a ) {
                                                $r = $fetch( $p['url'], array( 'user-agent' => $a['ua'] ) );
                                                if ( is_wp_error( $r ) ) {
                                                        $ligne_ag .= str_pad( 'ERR', $largeur );
                                                        continue;
                                                }
                                                $code2 = (string) wp_remote_retrieve_response_code( $r );
                                        $corps = (string) wp_remote_retrieve_body( $r );
                                                $og = (int) preg_match_all( '#<meta[^>]+property=["\']og:#i', $corps );
                                                $carte = $code2 . '/og' . $og;
                                                $ligne_ag .= str_pad( $carte, $largeur );
                                                if ( 'humain' === $nom ) {
                                                        $humain = preg_replace( '#\s+#', ' ', trim( strip_tags( $corps ) ) );
                                                } else {
                                                        $autre = preg_replace( '#\s+#', ' ', trim( strip_tags( $corps ) ) );
                                                        if ( '' !== $humain && $autre !== $humain ) {
                                                                $cloaking[ $nom ] = ( $cloaking[ $nom ] ?? 0 ) + 1;
                                                        }
                                                }
                                                if ( in_array( $nom, array( 'Facebook', 'WhatsApp', 'Discord' ), true ) && '200' === $code2 && $og < 3 ) {
                                                        $signale( 'SOCIAL-OG-INSUFFISANT', 'KO', $p['libelle'], $nom . ' recoit ' . $og . ' balise(s) og: (attendu >= 4)');
                                                        ++$stats['ko'];
                                                }
                                                if ( 'X' === $nom && '200' === $code2 && false === stripos( $corps, 'twitter:card' ) ) {
                                                        $signale( 'SOCIAL-TWITTER-CARD', 'WARN', $p['libelle'], 'aucune twitter:card : X affichera un lien nu');
                                                        ++$stats['warn'];
                                                }
                                                if ( in_array( $nom, array( 'GPTBot', 'ClaudeBot', 'Perplexity', 'OAISearch' ), true ) && '200' !== $code2 ) {
                                                        $signale( 'LLM-BLOQUE', 'WARN', $p['libelle'], $nom . ' recoit HTTP ' . $code2 . ' : le site ne sera pas cite par ce moteur d\'IA');
                                                        ++$stats['warn'];
                                                }
                                        }
                                        $out( '  ' . $ligne_ag );
                                }
                                $out( '' );
                                $out( '  legende : code HTTP / nombre de balises og: trouvées dans ce que l\'agent recoit.' );
                                if ( $cloaking ) {
                                        $signale( 'SEO-CLOAKING', 'KO', 'comparaison humain/agent', 'contenu different pour ' . implode( ', ', array_keys( $cloaking ) ) . ' : c\'est du cloaking, penalise par Google');
                                        ++$stats['ko'];
                                        $actions[] = 'aligner le rendu (le cache doit etre par page, pas par agent) avant toute campagne';
                                } else {
                                        $lig( 'meme HTML pour l\'humain et chaque agent', 'OUI' );
                                }
                        }
                        $r = $fetch( home_url( '/robots.txt' ) );
                        $rb = is_wp_error( $r ) ? '' : (string) wp_remote_retrieve_body( $r );
                        $lig( 'robots.txt', ( strlen( $rb ) ? strlen( $rb ) . ' o' : 'inaccessible' ) . ( preg_match( '#GPTBot#i', $rb ) ? ' · GPTBot nomme' : ' · GPTBot non cite' ) );
                        if ( preg_match( '#^Disallow:\s*/\s*$#mi', $rb ) ) {
                                $signale( 'SEO-ROBOTS-BLOQUE', 'KO', 'robots.txt', 'Disallow: / : le site est ferme a TOUS les robots, Google compris');
                                ++$stats['ko'];
                        }
                }

                /* ---------- 5. securite ---------- */
                $tit( '4. SECURITE, VUE DE L\'EXTERIEUR' );
                $surfaces = array(
                        '/xmlrpc.php' => 'xmlrpc (amplificateur DDoS si pingback actif)',
                        '/readme.html' => 'readme du core (version exposee)',
                        '/.git/HEAD' => 'depot git telechargeable',
                        '/wp-content/uploads/' => 'listing du dossier clients',
                        '/wp-content/pk-rapports/' => 'RAPPORTS DE LA SONDE lisibles publiquement',
                        '/wp-json/wp/v2/users' => 'enumeration des comptes',
                        '/wp-admin/maint/repair.php' => 'outil de reparation de base',
                        '/wp-content/debug.log' => 'journal de debug',
                        '/wp-content/uploads/partikulier-cache/' => 'dossier de cache liste (les pages en copie)',
                        '/wp-content/themes/' => 'liste des themes installs (nom et version)',
                );
                foreach ( $surfaces as $chemin => $pourquoi ) {
                        /* Ces URL ne rendent aucun gabarit du theme : elles se mesurent meme quand la
                           boucle locale est cassee. Les sauter en mode local transformait un
                           « inaccessible » honnete en « n/a » silencieux (mesure sur le banc). */
                        if ( ! empty( $GLOBALS['pk_diag_loopback_casse'] ) ) {
                                continue; // ligne retractee : la liste ne doit pas devenir dix fois « non mesure »
                        }
                        $x = $fetch( home_url( $chemin ), array( 'timeout' => $mode_http ? 10 : 2 ) );
                        if ( is_wp_error( $x ) ) {
                                $GLOBALS['pk_diag_loopback_casse'] = true;
                                $c = 'ERR (' . $x->get_error_message() . ')';
                        } else {
                                $c = (string) wp_remote_retrieve_response_code( $x );
                        }
                        $fuit = in_array( $c, array( '200', '301', '302' ), true );
                        $lig( $chemin, $c . ( $fuit ? '  ← ' . $pourquoi : '' ) );
                        if ( $fuit ) {
                                $grav = false !== strpos( $chemin, 'pk-rapports' ) || false !== strpos( $chemin, '.git' ) ? 'KO' : 'WARN';
                                $signale( 'SEC-SURFACE', $grav, $chemin, $pourquoi);
                                ++$stats['ko'];
                        }
                }
                $GLOBALS['pk_diag_en_tetes_ok'] = false;
                if ( empty( $GLOBALS['pk_diag_loopback_casse'] ) ) {
                        $r = $fetch( home_url( '/' ), array( 'timeout' => 8 ) );
                        if ( ! is_wp_error( $r ) ) {
                                        $GLOBALS['pk_diag_en_tetes_ok'] = true;
                                $hd = wp_remote_retrieve_headers( $r );
                                $hd = is_object( $hd ) ? array_change_key_case( (array) $hd->getAll(), CASE_LOWER ) : array();
                                foreach ( array( 'content-security-policy' => 'CSP', 'x-frame-options' => 'X-Frame-Options', 'x-content-type-options' => 'X-Content-Type-Options', 'referrer-policy' => 'Referrer-Policy', 'permissions-policy' => 'Permissions-Policy', 'strict-transport-security' => 'HSTS', 'x-powered-by' => 'X-Powered-By (a retirer)' ) as $k => $nom ) {
                                        $lig( 'en-tete ' . $nom, isset( $hd[ $k ] ) ? (string) ( is_array( $hd[ $k ] ) ? implode( '|', $hd[ $k ] ) : $hd[ $k ] ) : 'ABSENT' );
                                }
                                $corps = (string) wp_remote_retrieve_body( $r );
                                $debug = (bool) preg_match( '#<(b|strong)>(?:<i>)?(Warning|Notice|Fatal error|Deprecated)#i', $corps );
                                $lig( 'trace de debug dans le HTML', $debug ? 'OUI — a couper immediatement' : 'non' );
                                if ( $debug ) {
                                        $signale( 'SEC-DEBUG-VISIBLE', 'KO', 'accueil', 'avertissements PHP servis aux visiteurs');
                                        ++$stats['ko'];
                                        $actions[] = 'WP_DEBUG=false et WP_DEBUG_DISPLAY=false dans wp-config.php avant la mise en ligne';
                                }
                                $lig( 'balise generator (version exposee)', preg_match( '#name="generator"#i', $corps ) ? 'oui' : 'non' );
                        }
                        $inj = $fetch( home_url( '/?pk_order=' . rawurlencode( "price' OR 1=1--" ) ) );
                        $corps_inj = is_wp_error( $inj ) ? '' : (string) wp_remote_retrieve_body( $inj );
                        $lig( 'tri injecte (tentative SQL)', ( is_wp_error( $inj ) ? 'ERR' : (string) wp_remote_retrieve_response_code( $inj ) ) . ( preg_match( '#You have an error in your SQL#i', $corps_inj ) ? '  !!! SQL EXPOSE' : ' — aucune fuite SQL' ) );
                        if ( preg_match( '#You have an error in your SQL#i', $corps_inj ) ) {
                                $signale( 'SEC-SQLI', 'KO', 'tri des annonces', 'pk_order remonte une erreur SQL : requete non preparee');
                                ++$stats['ko'];
                                $actions[] = 'corriger le module de tri (prepare + whiteliste des colonnes) — porte d\'entree pour n\'importe quel robot';
                        }

                        if ( empty( $GLOBALS['pk_diag_en_tetes_ok'] ) ) {
                                $lig( 'surfaces non verifiees', 'boucle locale cassee : les 10 controles ci-dessus exigent que le serveur puisse s\'appeler lui-meme. La copie de la sonde a la racine du WordPress les mesure, elle.' );
                                $actions[] = 'si la boucle locale est cassee sur un staging (bouclage bloque par le pare-feu de l\'hebergeur), lancer la sonde depuis la racine : php pk-sonde.php, sinon le rapport de securite reste incomplet';
                        }
                }

                /* ---------- 6. hebergement ---------- */
                $tit( '5. CONDITIONS D\'HEBERGEMENT (ce qui marchera vraiment chez l\'hebergeur)' );
                $dis = array_map( 'trim', array_filter( explode( ',', (string) ini_get( 'disable_functions' ) ) ) );
                foreach ( array( 'exec', 'shell_exec', 'system', 'passthru', 'popen', 'proc_open' ) as $f ) {
                        $lig( $f . '()', function_exists( $f ) ? ( in_array( $f, $dis, true ) ? 'DESACTIVEE (disable_functions)' : 'disponible' ) : 'absente de PHP' );
                }
                $lig( 'open_basedir', ini_get( 'open_basedir' ) ? (string) ini_get( 'open_basedir' ) : '(aucune restriction)' );
                $lig( 'memory_limit', ini_get( 'memory_limit' ) );
                /* mbstring : le theme l'appelle sans garde a 47 endroits. Absent, chaque appel est
                   une erreur fatale (mesure : « Call to undefined function mb_strrpos() » dans
                   Partikulier_SEO::limit(), donc sur toute fiche dont la description est trop
                   longue). Le plugin coeur embarque un filet ; on dit lequel des deux etats est le tien. */
                $pk_mb = extension_loaded( 'mbstring' );
                if ( $pk_mb ) {
                        $lig( 'extension mbstring', 'chargee' );
                } else {
                        $pk_filet = function_exists( 'mb_strlen' );
                        $lig( 'extension mbstring', 'ABSENTE — filet ' . ( $pk_filet ? ( file_exists( WP_PLUGIN_DIR . '/partikulier-core/mu-plugins/partikulier-mb-polyfill.php' ) ? 'actif (plugin coeur)' : 'actif (pose a la main)' ) : 'ABSENT aussi' ) );
                        if ( ! $pk_filet ) {
                                $signale( 'HOST-MBSTRING-ABSENT', 'KO', 'hebergeur', 'mbstring non charge et aucun filet : les mb_*() du theme (title, meta description, troncatures) sont des erreurs fatales' );
                                ++$stats['ko'];
                                $actions[] = "demander a l'hebergeur d'activer l'extension PHP mbstring, ou verifier que le plugin « Partikulier Core » est actif (il pose le filet, charge avant le theme)";
                        }
                }
                $lig( 'serveur logiciel', isset( $_SERVER['SERVER_SOFTWARE'] ) ? (string) $_SERVER['SERVER_SOFTWARE'] : 'inconnu (CLI ou serveur interne)' );
                $binaires = array( '/usr/bin/avifenc', '/usr/local/bin/avifenc', '/usr/bin/vips', '/usr/local/bin/vips' );
                $aucun_binaire = true;
                foreach ( $binaires as $b ) {
                        $etat = is_executable( $b ) ? 'EXECUTABLE' : ( file_exists( $b ) ? 'present mais non executable' : 'ABSENT' );
                        if ( 'ABSENT' !== $etat ) {
                                $aucun_binaire = false;
                        }
                        $lig( 'binaire ' . $b, $etat );
                }
                $supports = function_exists( 'wp_image_editor_supports' ) ? (bool) wp_image_editor_supports( array( 'mime_type' => 'image/avif' ) ) : false;
                $lig( 'wp_image_editor_supports(image/avif)', $supports );
                $lig( 'repli avifenc utilisable par le theme', ( is_executable( '/usr/bin/avifenc' ) || is_executable( '/usr/local/bin/avifenc' ) ) && function_exists( 'exec' ) && ! in_array( 'exec', $dis, true ) );
                $encode = null;
                $note_avif = '';
                $mime = static function ( $f ) { return function_exists( 'finfo_file' ) ? (string) ( new finfo( FILEINFO_MIME_TYPE ) )->file( $f ) : 'inconnu'; };
                $up = wp_get_upload_dir();
                $dir_test = trailingslashit( $up['basedir'] ) . 'pk-sonde';
                $media = 0;
                $front = (int) get_option( 'page_on_front' );
                if ( $front ) {
                        $media = (int) get_post_meta( $front, '_thumbnail_id', true );
                }
                if ( ! $media ) {
                        $q = get_posts( array( 'post_type' => 'properties', 'post_status' => 'publish', 'posts_per_page' => 1, 'fields' => 'ids' ) );
                        if ( $q ) {
                                $media = (int) get_post_thumbnail_id( $q[0] );
                        }
                }
                if ( ! $media ) {
                        $q = get_posts( array( 'post_type' => 'attachment', 'post_mime_type' => 'image/jpeg', 'numberposts' => 1, 'fields' => 'ids' ) );
                        $media = (int) ( $q[0] ?? 0 );
                }
                $f_test = $media ? get_attached_file( $media ) : '';
                if ( ! $f_test || ! file_exists( $f_test ) ) {
                        $lig( 'encodage AVIF reel', 'ignore : aucun media a encoder' );
                } else {
                        if ( ! is_dir( $dir_test ) ) {
                                @mkdir( $dir_test, 0755, true );
                                @file_put_contents( $dir_test . '/.htaccess', "Require all denied\nDeny from all\n" );
                        }
                        $lig( 'media teste', basename( (string) $f_test ) . ' (' . number_format( (int) filesize( (string) $f_test ) ) . ' o)' );
                        if ( $supports ) {
                                $ed = wp_get_image_editor( $f_test );
                                if ( is_wp_error( $ed ) ) {
                                        $encode = false;
                                        $note_avif = 'editeur : ' . $ed->get_error_message();
                                } else {
                                        $cible = trailingslashit( $dir_test ) . 'diag-' . basename( (string) $f_test ) . '.avif';
                                        @unlink( $cible );
                                        $ed->set_quality( 75 );
                                        $rv = $ed->save( $cible, 'image/avif' );
                                        if ( is_wp_error( $rv ) ) {
                                                $encode = false;
                                                $note_avif = 'save() : ' . $rv->get_error_message();
                                        } elseif ( empty( $rv['path'] ) || ! file_exists( $rv['path'] ) || filesize( $rv['path'] ) <= 0 ) {
                                                $encode = false;
                                                $note_avif = 'aucun fichier ecrit (le theme verifie pareil : path + taille > 0)';
                                        } elseif ( 'image/avif' !== $mime( $rv['path'] ) ) {
                                                $encode = false;
                                                $note_avif = 'fichier ecrit mais PAS de l\'AVIF (mime=' . $mime( $rv['path'] ) . ') : le theme ne regarde que la taille, il le compterait comme valide';
                                        } else {
                                                $encode = true;
                                                $dim = @getimagesize( $rv['path'] );
                                                $note_avif = number_format( (int) filesize( $rv['path'] ) ) . ' o' . ( $dim ? ' (' . $dim[0] . 'x' . $dim[1] . ')' : '' ) . ' pour ' . number_format( (int) filesize( (string) $f_test ) ) . ' o';
                                        }
                                        @unlink( $cible );
                                }
                                $lig( 'encodage AVIF par l\'editeur', ( true === $encode ? 'OK - ' : 'ECHEC - ' ) . $note_avif );
                        } else {
                                $lig( 'encodage AVIF par l\'editeur', 'non tente : supports=NON, le theme saute ce chemin' );
                        }
                }
                /* Le repertoire des themes est liste par le SERVEUR (AutoIndex), pas par
                   WordPress : aucun theme ne peut le corriger depuis l'interieur. Le rapport
                   le dit et donne le bloc a coller, au lieu de noter un ⚠ sans sortie. */
                $surface_themes = array();
                foreach ( array( '/wp-content/themes/', '/wp-content/plugins/' ) as $chemin_test ) {
                        $r_test = empty( $GLOBALS['pk_diag_loopback_casse'] ) ? $fetch( home_url( $chemin_test ), array( 'timeout' => 2 ) ) : null;
                        if ( $r_test && ! is_wp_error( $r_test ) && '200' === (string) wp_remote_retrieve_response_code( $r_test ) ) {
                                $surface_themes[] = $chemin_test;
                        }
                }
                if ( $surface_themes ) {
                        $lig( 'AutoIndex expose', implode( ', ', $surface_themes ) . ' — reglages serveur, pas le theme' );
                        $actions[] = 'coller dans le .htaccess de la racine (hPanel > Reglages du site > ou via un bloc existant) : <IfModule mod_autoindex.c>\nOptions -Indexes\n</IfModule>\n<IfModule mod_headers.c>\nHeader unset X-Powered-By\nHeader always set X-Content-Type-Options "nosniff"\n</IfModule>\nSi le cache HCDN de Hostinger garde la version listable : vider le cache apres la modification, puis relancer ce lien.';
                }
                $lig( 'X-Powered-By', ( function_exists( 'header' ) && has_action( 'send_headers' ) ) ? 'retire par le plugin (verifier apres purge du cache hebergeur)' : 'non traite' );
                /* Le plugin LiteSpeed Cache s'installe dans litespeed-cache/ : l'ancien test
                   is_dir(/litespeed) regardait un dossier que personne n'a jamais eu et
                   repondait NON alors que le plugin etait actif — tout le rapport etait alors
                   lu sans la couche qui sert reellement le HTML, et le message de purge
                   « action litespeed_purge_all » (qui exige que le hook existe, donc que le
                   plugin soit charge) contredisait la ligne sans que personne puisse trancher.
                   On teste les deux orthographes ET la liste des extensions actives, et le
                   hook litespeed_purge_all comme dernier temoin. */
                $pk_ls_installe = is_dir( WP_PLUGIN_DIR . '/litespeed-cache' ) || is_dir( WP_PLUGIN_DIR . '/litespeed' );
                $pk_ls_actifs = array();
                foreach ( (array) get_option( 'active_plugins', array() ) as $pk_ls_p ) {
                        if ( is_string( $pk_ls_p ) && false !== stripos( $pk_ls_p, 'litespeed' ) ) {
                                $pk_ls_actifs[] = $pk_ls_p;
                        }
                }
                if ( ! $pk_ls_actifs && has_action( 'litespeed_purge_all' ) ) {
                        $pk_ls_actifs[] = 'detecte par le hook litespeed_purge_all (charge, donc actif)';
                }
                $lig( 'plugin LiteSpeed Cache installe', ( $pk_ls_installe ? 'OUI' : 'non' ) . ( $pk_ls_actifs ? ' · ACTIF : ' . implode( ', ', $pk_ls_actifs ) : ( $pk_ls_installe ? ' · installe mais NON actif' : '' ) ) );
                if ( $pk_ls_actifs || $pk_ls_installe ) {
                        $problemes[] = 'LiteSpeed Cache ' . ( $pk_ls_actifs ? 'actif' : 'installe' ) . ' : c\'est lui qui sert le HTML aux visiteurs ; les en-tetes X-Partikulier-Cache ne prouvent plus rien pour un visiteur, et la purge du theme ne purge pas LiteSpeed';
                        $actions[] = 'trancher avant la mise en production : desactiver LSCache, ou brancher litespeed_purge_all() dans la purge du theme';
                }
                if ( false === $encode && $aucun_binaire ) {
                        $problemes[] = 'AVIF indisponible sur cet hebergement : editeur incapable et aucun binaire avifenc/vips executable';
                        $actions[] = 'a trancher dans le registre CDC (basculer sur webp ou accepter jpeg en servant les tailles WP), pas en modifiant le theme : class-avif.php ne journalise pas son echec';
                } elseif ( false === $encode && $supports ) {
                        $problemes[] = 'wp_image_editor_supports(image/avif) dit OUI mais l\'encodage reel a echoue : ' . $note_avif;
                        $actions[] = 'faux positif (l\'appel dit oui, l\'encodeur ne produit rien) : ne pas compter sur l\'AVIF tant que ce test ne passe pas';
                }

                /* ---------- 7. agregats ---------- */
                $tit( '6. PERFORMANCE AGGREGEE' );
                $t = $stats['temps'];
                if ( $t ) {
                        sort( $t );
                        $pct = static function ( $a, $p ) { $i2 = min( (int) floor( count( $a ) * $p ), count( $a ) - 1 ); return round( (float) $a[ $i2 ], 1 ); };
                        $lig( 'temps par requete (n=' . count( $t ) . ')', 'mediane ' . $pct( $t, 0.5 ) . ' ms · p95 ' . $pct( $t, 0.95 ) . ' ms · max ' . round( (float) end( $t ), 1 ) . ' ms' );
                        $lig( 'p95 compare a la porte du depot', ( $pct( $t, 0.95 ) < 600 ? 'OK sous 600 ms' : 'au-dessus de 600 ms — a lire avec la section cache' ) );
                }
                $lig( 'repartition HTTP', (string) json_encode( $stats['http'] ) );
                $lig( 'en-tetes de cache vus', (string) json_encode( $stats['cache'] ) );
                $lig( 'duree du diagnostic', round( ( microtime( true ) - $deb ) * 1000 ) . ' ms pour ' . $stats['url'] . ' URLs' );

                /* ---------- 8. verdict ---------- */
                $tit( '7. VERDICT' );
                $par_code = array();
                foreach ( $anomalies as $f ) {
                        $par_code[ $f[0] ] = ( $par_code[ $f[0] ] ?? 0 ) + 1;
                }
                arsort( $par_code );
                if ( $par_code ) {
                        $out( '  par regle : ' . implode( ', ', array_map( static fn ( $k, $v ) => $k . '=' . $v, array_keys( $par_code ), $par_code ) ) );
                }
                if ( ! $anomalies && ! $problemes ) {
                        $out( '  ✔ Rien de casse sur le perimetre mesure (' . $stats['url'] . ' URLs).' );
                } else {
                        $out( '  ' . count( $anomalies ) . ' anomalie(s) par page (KO ' . $stats['ko'] . ' · WARN ' . $stats['warn'] . ')' . ( $problemes ? ' et ' . count( $problemes ) . ' probleme(s) de site' : '' ) . ' :' );
                        $groupees = array();
                        foreach ( $anomalies as $f ) {
                                $groupees[ $f[0] ][] = $f;
                        }
                        $i2 = 0;
                        foreach ( $groupees as $code => $liste ) {
                                ++$i2;
                                $s = $liste[0];
                                $out( '   ' . $i2 . '. [' . $code . '][' . $s[1] . '] ' . ( count( $liste ) > 1 ? count( $liste ) . ' page(s) — premier : ' : '' ) . $s[2 ] . ' — ' . $s[3] );
                                if ( count( $liste ) > 1 ) {
                                        $autres = array_slice( array_map( static fn ( $x ) => (string) $x[2], $liste ), 1, 6 );
                                        $out( '      aussi : ' . implode( ', ', $autres ) . ( count( $liste ) > 7 ? ' (+' . ( count( $liste ) - 7 ) . ' autres)' : '' ) );
                                }
                        }
                }
                $tit( '8. CE QU\'IL FAUT DEMANDER (a copier-coller au dev)' );
                if ( ! $actions && ! $problemes ) {
                        $out( '  rien : le perimetre mesure est propre.' );
                } else {
                        foreach ( array_unique( array_merge( $actions, array_map( static fn ( $p ) => 'a trancher : ' . $p, $problemes ) ) ) as $a ) {
                                $out( '   - ' . $a );
                        }
                }
                if ( $interruptu ) {
                        $out( '' );
                        $out( '  arrete a ' . ( $i - $depuis ) . '/' . count( $toutes ) . ' URLs pour respecter max_execution_time (' . $budget . ' s).' );
                        $out( '  relance avec « reprendre » : la suite s\'ajoute, rien n\'est perdu.' );
                }

                $colonnes = partikulier_pk_diag_csv_colonnes();
                $csv = implode( "\n", array_merge(
                        array( implode( ',', $colonnes ) ),
                        array_map( static function ( $r ) use ( $colonnes ) {
                                $o = array();
                                foreach ( $colonnes as $c ) {
                                        $o[] = $r[ $c ] ?? '';
                                }
                                return partikulier_pk_diag_csv_ligne( $o );
                        }, $rows )
                ) );

                return array(
                        'texte' => implode( "\n", $L ),
                        'csv' => $csv,
                        'lignes' => $rows,
                        'anomalies' => $anomalies,
                        'problemes' => $problemes,
                        'actions' => $actions,
                        'stats' => $stats,
                        'interrompu' => $interruptu,
                        'duree_ms' => (int) round( ( microtime( true ) - $deb ) * 1000 ),
                );
        }
}

if ( ! function_exists( 'partikulier_pk_diag_rendu_local' ) ) {

        /**
         * Rendu local : meme route, sans passer par le serveur web.
         *
         * Sert quand la boucle locale est cassee (DNS du domaine, open_basedir,
         * serveur mono-ouvrier). Le module de cache n'est pas exerce dans ce mode :
         * c'est dit dans le rapport, pas cache.
         *
         * @param string $url URL absolue.
         * @return string HTML, ou '' si la route est introuvable.
         */
        function partikulier_pk_diag_rendu_local( $url ) {
                global $wp, $wp_query, $wp_the_query;
                $path  = (string) wp_parse_url( $url, PHP_URL_PATH );
                $query = (string) ( wp_parse_url( $url, PHP_URL_QUERY ) ?: '' );
                if ( '' === $path ) {
                        return array( 'ok' => false, 'raison' => 'chemin vide', 'type' => '', 'template' => '' );
                }
                $sauve = array(
                        'query'   => $wp_query,
                        'query_t' => $wp_the_query,
                        'uri'     => isset( $_SERVER['REQUEST_URI'] ) ? $_SERVER['REQUEST_URI'] : null,
                        'qs'      => isset( $_SERVER['QUERY_STRING'] ) ? $_SERVER['QUERY_STRING'] : null,
                );
                $res = array( 'ok' => false, 'raison' => '', 'type' => '', 'template' => '' );
                try {
                        $_SERVER['REQUEST_URI']  = $path . ( '' !== $query ? '?' . $query : '' );
                        $_SERVER['QUERY_STRING'] = $query;
                        $wp->query_vars = array();
                        if ( ! $wp->parse_request() ) {
                                $res = array( 'ok' => false, 'raison' => 'aucune regle de reecriture ne correspond', 'type' => '', 'template' => '' );
                        } else {
                                $wp->query_posts();
                                $GLOBALS['wp_query'] = $wp->query;
                                $wp_query          = $wp->query;
                                $is404             = (bool) ( isset( $wp->query->is_404 ) ? $wp->query->is_404 : false );
                                $res = array(
                                        'ok'       => ! $is404,
                                        'raison'   => $is404 ? 'WordPress repond 404 sur cette route' : '',
                                        'type'     => partikulier_pk_diag_type_requete( $wp->query ),
                                        'template' => (string) ( isset( $wp->template ) ? $wp->template : '' ),
                                        'found'    => (int) ( isset( $wp->query->found_posts ) ? $wp->query->found_posts : 0 ),
                                );
                        }
                } catch ( Throwable $e ) {
                        $res = array( 'ok' => false, 'raison' => 'exception: ' . $e->getMessage(), 'type' => '', 'template' => '' );
                }
                while ( ob_get_level() > 0 ) {
                        ob_end_clean();
                }
                $GLOBALS['wp_query'] = $sauve['query'];
                $wp_query            = $sauve['query'];
                $wp_the_query        = $sauve['query_t'];
                $wp->query_posts();
                if ( null === $sauve['uri'] ) {
                        unset( $_SERVER['REQUEST_URI'] );
                } else {
                        $_SERVER['REQUEST_URI'] = $sauve['uri'];
                }
                if ( null === $sauve['qs'] ) {
                        unset( $_SERVER['QUERY_STRING'] );
                } else {
                        $_SERVER['QUERY_STRING'] = $sauve['qs'];
                }
                return $res;
        }
}

if ( ! function_exists( 'partikulier_pk_diag_url_accueil' ) ) {

        /**
         * L'URL d'accueil telle que la verrait un visiteur (avec son port s'il en a un).
         *
         * @return string
         */
        function partikulier_pk_diag_url_accueil() {
                return (string) home_url( '/' );
        }
}

if ( ! function_exists( 'partikulier_pk_diag_multi' ) ) {

        /**
         * N requetes en parallele, avec mediane/p95/max et repartition des codes.
         *
         * @param array $urls URL repetees.
         * @param int   $conc connexions simultanees.
         * @return array
         */
        function partikulier_pk_diag_multi( $urls, $conc = 10 ) {
                if ( ! function_exists( 'curl_multi_init' ) ) {
                        return array( 'duree' => 0.0, 'codes' => array(), 'erreurs' => count( $urls ), 'p50' => 0, 'p95' => 0, 'max' => 0, 'note' => 'curl_multi absent de PHP' );
                }
                $mh = curl_multi_init();
                $h  = array();
                $i  = 0;
                $t0 = microtime( true );
                foreach ( $urls as $u ) {
                        $c = curl_init( (string) $u );
                        curl_setopt_array(
                                $c,
                                array(
                                        CURLOPT_RETURNTRANSFER => true,
                                        CURLOPT_TIMEOUT        => 30,
                                        CURLOPT_CONNECTTIMEOUT => 5,
                                        CURLOPT_SSL_VERIFYPEER => false,
                                        CURLOPT_FOLLOWLOCATION => false,
                                        CURLOPT_HTTPHEADER     => array( 'Cookie: pll_language=fr' ),
                                )
                        );
                        curl_multi_add_handle( $mh, $c );
                        $h[] = $c;
                        ++$i;
                        if ( $i % max( 1, (int) $conc ) === 0 ) {
                                $run = null;
                                do { curl_multi_exec( $mh, $run ); if ( $run > 0 ) { curl_multi_select( $mh, 0.05 ); } } while ( $run > 0 );
                        }
                }
                $run = null;
                do { curl_multi_exec( $mh, $run ); if ( $run > 0 ) { curl_multi_select( $mh, 0.05 ); } } while ( $run > 0 );
                $temps = array();
                $codes = array();
                $err   = 0;
                foreach ( $h as $c ) {
                        $codes[(string) curl_getinfo( $c, CURLINFO_RESPONSE_CODE )] = ( $codes[(string) curl_getinfo( $c, CURLINFO_RESPONSE_CODE )] ?? 0 ) + 1;
                        if ( '' !== curl_error( $c ) ) {
                                ++$err;
                        }
                        $temps[] = (float) curl_getinfo( $c, CURLINFO_TOTAL_TIME );
                        curl_multi_remove_handle( $mh, $c );
                        curl_close( $c );
                }
                curl_multi_close( $mh );
                $duree = microtime( true ) - $t0;
                sort( $temps );
                $pct = static function ( $a, $p ) { return $a ? $a[ min( (int) floor( count( $a ) * $p ), count( $a ) - 1 ) ] : 0; };
                return array(
                        'duree'   => $duree,
                        'codes'   => $codes,
                        'erreurs' => $err,
                        'p50'     => $pct( $temps, 0.5 ),
                        'p95'     => $pct( $temps, 0.95 ),
                        'max'     => $temps ? (float) end( $temps ) : 0,
                );
        }
}

/* ==========================================================================
 * ENTREE ADMIN
 * ========================================================================== */

if ( ! class_exists( 'Partikulier_Pk_Sonde' ) ) {

        /**
         * Page admin du diagnostic.
         */
        class Partikulier_Pk_Sonde {

                const SLUG = 'pk-diagnostic';
                const CAP = 'manage_options';
                const QUERY = 'pk_diag';
                const NONCE_ACTION = 'pk_diag_url';

                /**
                 * Amorcage : appele par functions.php.
                 *
                 * @return void
                 */
                public static function init() {
                        add_action( 'admin_menu', array( __CLASS__, 'menu' ), 40 );
                        add_action( 'admin_init', array( __CLASS__, 'handle' ) );
                        add_action( 'template_redirect', array( __CLASS__, 'boot_url_entry' ), 5 );
                }


        /**
         * Entree par URL, pour un non-developpeur : les memes controles que la page
         * d'admin, derriere un jeton court, avec la sortie en JSON pour la recopier.
         *
         * Acces : utilisateur connecte ET pouvant gerer les reglages ET jeton valide
         * (12 h). Une page publique ne peut donc jamais declencher un crawl.
         *
         * Le branchement est template_redirect, pas wp_loaded : a ce stade l'utilisateur
         * est identifie (wp_loaded part trop tot, current_user_can etait faux meme avec
         * un cookie admin valide — mesure : la page normale etait rendue au lieu du
         * refus), et DONOTCACHEPAGE est pris en compte par le module de cache.
         *
         * @return void
         */
        public static function boot_url_entry() {
                if ( ! isset( $_GET[ self::QUERY ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- simple detecteur
                        return;
                }
                if ( ! current_user_can( self::CAP ) ) {
                        /* Refus exprime : rendre la page normale laisserait croire que le lien ne
                           marche pas. Aucun contenu de diagnostic ne fuit — juste un 403 explicatif. */
                        nocache_headers();
                        status_header( 403 );
                        header( 'Content-Type: text/plain; charset=utf-8' );
                        header( 'X-Robots-Tag: noindex, nofollow' );
                        echo "403 - diagnostic reserve aux administrateurs connectes sur ce WordPress.\n";
                        echo "Ouvre Tableau de bord > Diagnostic complet : les liens y sont generes pour ta session.\n";
                        return;
                }
                $j = isset( $_GET[ self::QUERY . '_jeton' ] ) ? (string) sanitize_text_field( wp_unslash( $_GET[ self::QUERY . '_jeton' ] ) ) : '';
                if ( '' === $j || ! wp_verify_nonce( $j, self::NONCE_ACTION ) ) {
                        nocache_headers();
                        header( 'Content-Type: text/plain; charset=utf-8' );
                        header( 'X-Robots-Tag: noindex, nofollow' );
                        header( 'Referrer-Policy: no-referrer' );
                        echo "Jeton absent ou expire (12 h) : rouvre la page Tableau de bord > Diagnostic > \n";
                        echo "les six liens du bloc « Transmettre le rapport / relancer un test » sont a jour ici.\n";
                        return;
                }
                define( 'DONOTCACHEPAGE', true );
                @ignore_user_abort( true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- le module doit rendre son rapport meme si le serveur coupe
                if ( function_exists( 'set_time_limit' ) ) {
                        @set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
                }
                nocache_headers();
                header( 'Content-Type: text/plain; charset=utf-8' );
                header( 'X-Robots-Tag: noindex, nofollow' );
                header( 'Referrer-Policy: no-referrer' );
                $opt = array(
                        'max_pages' => isset( $_GET['max'] ) ? absint( wp_unslash( $_GET['max'] ) ) : 0,
                        'reseau'    => empty( $_GET['sans_reseau'] ),
                        'campagne'  => ! empty( $_GET['campagne'] ),
                        'budget_s'  => 1800,
                );
                if ( isset( $_GET['quick'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- choix de mode
                        $opt['max_pages'] = 4;
                        $opt['reseau']    = false;
                        $opt['campagne']  = true;
                        $opt['budget_s']  = 300;
                }
                if ( isset( $_GET['purge'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- action explicite
                        $r = class_exists( 'Partikulier_Cache' ) ? Partikulier_Cache::purge_host_cache() : 'module de cache absent';
                        echo "purge demandee : ", $r, "\n";
                        if ( ! empty( $_GET['purge_only'] ) ) {
                                exit; // purge seule : sans sortie definitive, la page d'accueil serait rendue derriere
                        }
                }
                $rap = partikulier_pk_diag_run( $opt );
                if ( isset( $_GET['load'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- test de charge demande
                        $n = isset( $_GET['n'] ) ? max( 5, min( 400, absint( wp_unslash( $_GET['n'] ) ) ) ) : 100;
                        $c = isset( $_GET['c'] ) ? max( 1, min( 50, absint( wp_unslash( $_GET['c'] ) ) ) ) : 10;
                        echo "\n== TEST DE CHARGE : ", (int) $n, " requetes, ", (int) $c, " en parallele ==\n";
                        $u = partikulier_pk_diag_url_accueil();
                        $multi = partikulier_pk_diag_multi( array_fill( 0, $n, $u ), $c );
                        echo "  débit      : ", round( $n / max( 0.001, $multi['duree'] ), 1 ), " req/s sur ", round( $multi['duree'], 2 ), " s\n";
                        echo "  temps      : mediane ", round( ( $multi['p50'] ?? 0 ) * 1000 ), " ms · p95 ", round( ( $multi['p95'] ?? 0 ) * 1000 ), " ms · max ", round( ( $multi['max'] ?? 0 ) * 1000 ), " ms\n";
                        echo "  codes      : ", json_encode( $multi['codes'] ), "\n";
                        echo "  erreurs    : ", (int) $multi['erreurs'], "\n";
                }
                self::ecrire( $rap );
                if ( isset( $_GET['json'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- format demande
                        nocache_headers();
                        header( 'Content-Type: application/json; charset=utf-8' );
                        echo wp_json_encode( self::json( $rap, ! empty( $_GET['lite'] ) ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- sortie JSON demandee
                        exit;
                }
                echo $rap['texte'], "\n";
                echo "\n  rapport : ", get_option( 'pk_sonde_dernier', '?' ), " · csv telechargeable depuis la page d'admin\n";
                exit; /* le rapport est fini : sans sortie definitive WordPress rend la page d'accueil derriere */
        }

        /**
         * Le rapport en JSON : compact pour le coller dans une conversation, complet
         * pour un dev qui veut les lignes par page.
         *
         * @param array $rap  Rapport.
         * @param bool  $lite Vrai = compact (verdict + compteurs + 12 premieres pages).
         * @return array
         */
        public static function json( $rap, $lite = false ) {
                $par = array();
                foreach ( (array) $rap['anomalies'] as $a ) {
                        $k = (string) $a[0];
                        $par[ $k ] = array(
                                'severite' => (string) $a[1],
                                'n'         => ( $par[ $k ]['n'] ?? 0 ) + 1,
                        'exemple'    => (string) $a[2] . ' — ' . (string) $a[3],
                        );
                }
                $out = array(
                        'version'   => PK_SONDE_VERSION,
                        'site'      => home_url( '/' ),
                        'genere'    => gmdate( 'c' ),
                        'duree_ms'  => (int) $rap['duree_ms'],
                        'urls'      => (int) $rap['stats']['url'],
                        'ko'        => (int) $rap['stats']['ko'],
                        'warn'      => (int) $rap['stats']['warn'],
                        'codes'     => $rap['stats']['http'],
                        'cache'     => $rap['stats']['cache'],
                        'regle'     => $par,
                        'problemes' => $rap['problemes'],
                        'a_demander' => $rap['actions'],
                );
                if ( ! $lite ) {
                        $out['pages']     = array_slice( $rap['lignes'], 0, 60 );
                        $out['anomalies'] = $rap['anomalies'];
                        $out['csv']       = $rap['csv'];
                } else {
                        $out['pages'] = array_map( static function ( $l ) {
                                return array(
                                        'url'   => $l['url'], 'http' => $l['http'], 'cache' => $l['cache'],
                                        'ms'    => $l['froid_ms'], 'o' => $l['o_html'], 'og' => $l['og'], 'og_image' => $l['og_image'],
                                        'h1'    => $l['h1'], 'alt' => $l['alt'], 'viewport' => $l['viewport'],
                                );
                        }, array_slice( $rap['lignes'], 0, 12 ) );
                }
                return $out;
        }

                /**
                 * Le menu. Se greffe sur « partikulier » s'il existe, sinon cree le sien.
                 *
                 * @return void
                 */
                public static function menu() {
                        if ( ! current_user_can( self::CAP ) ) {
                                return;
                        }
                        global $admin_page_hooks;
                        if ( isset( $admin_page_hooks['partikulier'] ) ) {
                                add_submenu_page( 'partikulier', 'Diagnostic complet du site', 'Diagnostic complet', self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
                                return;
                        }
                        add_menu_page( 'Diagnostic complet du site', 'Diagnostic', self::CAP, self::SLUG, array( __CLASS__, 'page' ) );
                }

                /**
                 * Le dossier des rapports, protege et cree au besoin.
                 *
                 * @return string
                 */
                public static function dossier() {
                        $d = trailingslashit( WP_CONTENT_DIR ) . 'pk-rapports';
                        if ( ! is_dir( $d ) ) {
                                @mkdir( $d, 0755, true );
                                @file_put_contents( $d . '/.htaccess', "Require all denied\nDeny from all\n" );
                        }
                        return $d;
                }

                /**
                 * Les rapports presents.
                 *
                 * @return array<int,string>
                 */
                public static function fichiers() {
                        $tous = array_merge(
                                (array) glob( self::dossier() . '/rapport-*.txt' ),
                                (array) glob( self::dossier() . '/rapport-*.csv' )
                        );
                        $tous = array_filter( $tous, static fn ( $f ) => is_string( $f ) && file_exists( $f ) );
                        return array_values( $tous );
                }

                /**
                 * Chemin verrouille dans le dossier des rapports.
                 *
                 * @param string $nom Nom de fichier.
                 * @return string '' si le nom n'est pas admissible.
                 */
                public static function chemin( $nom ) {
                        $nom = basename( (string) $nom );
                        if ( '' === $nom || ! preg_match( '#^rapport-[0-9A-Za-z._-]{4,60}\.(?:txt|csv)$#', $nom ) ) {
                                return '';
                        }
                        $p = self::dossier() . '/' . $nom;
                        return file_exists( $p ) ? $p : '';
                }

                /**
                 * Ecrit le .txt, le .csv et souvient du dernier.
                 *
                 * @param array $rap Rapport.
                 * @return string Chemin du .txt.
                 */
                public static function ecrire( $rap ) {
                        $d = self::dossier();
                        $nom = 'rapport-' . gmdate( 'Ymd-His' );
                        $txt = trailingslashit( $d ) . $nom . '.txt';
                        $csv = trailingslashit( $d ) . $nom . '.csv';
                        @file_put_contents( $txt, $rap['texte'] . "\n" );
                        @file_put_contents( $csv, $rap['csv'] . "\n" );
                        update_option( 'pk_sonde_dernier', $nom . '.txt', false );
                        return $txt;
                }

                /**
                 * Traite les actions (lancer, telecharger, nettoyer).
                 *
                 * @return void
                 */
                public static function handle() {
                        if ( ! isset( $_GET['page'] ) || self::SLUG !== $_GET['page'] ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- slug de page
                                return;
                        }
                        if ( ! current_user_can( self::CAP ) ) {
                                return;
                        }
                        $agir = isset( $_GET['pk_agir'] ) ? sanitize_key( wp_unslash( $_GET['pk_agir'] ) ) : '';
                        if ( '' === $agir ) {
                                return;
                        }
                        check_admin_referer( 'pk_diag_' . $agir, 'pk_nonce' );

                        if ( 'lancer' === $agir ) {
                                @ignore_user_abort( true );
                                if ( function_exists( 'set_time_limit' ) ) {
                                        @set_time_limit( 0 );
                                }
                                $rap = partikulier_pk_diag_run(
                                        array(
                                                'max_pages' => isset( $_GET['pk_max'] ) ? absint( wp_unslash( $_GET['pk_max'] ) ) : 0,
                                                'mode' => isset( $_GET['pk_mode'] ) ? sanitize_key( wp_unslash( $_GET['pk_mode'] ) ) : 'auto',
                                                'reseau' => empty( $_GET['pk_sans_reseau'] ),
                                                'campagne' => empty( $_GET['pk_sans_campagne'] ),
                                                'reprendre' => ! empty( $_GET['pk_reprendre'] ),
                                                'budget_s'  => 1800,
                                        )
                                );
                                self::ecrire( $rap );
                                delete_option( 'pk_sonde_progress' );
                                $par = array();
                                foreach ( (array) $rap['anomalies'] as $x ) {
                                        $par[ (string) ( $x[1] ?? 'INFO' ) ] = (int) ( $par[ (string) ( $x[1] ?? 'INFO' ) ] ?? 0 ) + 1;
                                }
                                set_transient(
                                        'pk_diag_fin',
                                        array(
                                                'ko'   => (int) ( $par['KO'] ?? 0 ),
                                                'warn' => (int) ( $par['WARN'] ?? 0 ),
                                                'info' => (int) ( $par['INFO'] ?? 0 ),
                                                'urls' => (int) $rap['stats']['url'],
                                        ),
                                        120
                                );
                                wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
                                exit;
                        }

                        if ( 'telecharger' === $agir || 'csv' === $agir ) {
                                $f = self::chemin( isset( $_GET['pk_fichier'] ) ? sanitize_file_name( wp_unslash( $_GET['pk_fichier'] ) ) : (string) get_option( 'pk_sonde_dernier', '' ) );
                                if ( 'csv' === $agir ) {
                                        $f = preg_replace( '/\.txt$/', '.csv', (string) $f );
                                }
                                if ( $f && file_exists( $f ) ) {
                                        nocache_headers();
                                        header( 'Content-Type: ' . ( 'csv' === $agir ? 'text/csv' : 'text/plain' ) . '; charset=utf-8' );
                                        header( 'Content-Disposition: attachment; filename="' . basename( $f ) . '"' );
                                        header( 'Content-Length: ' . filesize( $f ) );
                                        readfile( $f );
                                        exit;
                                }
                        }

                        if ( 'nettoyer' === $agir ) {
                                $n = 0;
                                foreach ( self::fichiers() as $f ) {
                                        if ( false !== @unlink( $f ) ) {
                                                ++$n;
                                        }
                                }
                                @unlink( self::dossier() . '/.htaccess' );
                                @rmdir( self::dossier() );
                                $up = wp_get_upload_dir();
                                foreach ( (array) glob( trailingslashit( $up['basedir'] ) . 'pk-sonde/*' ) as $f ) {
                                        if ( is_string( $f ) && false !== @unlink( $f ) ) {
                                                ++$n;
                                        }
                                }
                                @rmdir( trailingslashit( $up['basedir'] ) . 'pk-sonde' );
                                delete_option( 'pk_sonde_progress' );
                                set_transient( 'pk_diag_notice', 'nettoye : ' . $n . ' fichier(s) supprime(s)', 60 );
                                wp_safe_redirect( add_query_arg( array( 'page' => self::SLUG ), admin_url( 'admin.php' ) ) );
                                exit;
                        }
                }

                /**
                 * Rendu de la page.
                 *
                 * @return void
                 */
                public static function page() {
                        if ( ! current_user_can( self::CAP ) ) {
                                wp_die( 'Accès refusé.', '', array( 'response' => 403 ) );
                        }
                        $notice = get_transient( 'pk_diag_notice' );
                        $fin = get_transient( 'pk_diag_fin' );
                        delete_transient( 'pk_diag_notice' );
                        delete_transient( 'pk_diag_fin' );
                        $dossier = self::dossier();
                        $fichiers = self::fichiers();
                        $dernier = (string) get_option( 'pk_sonde_dernier', '' );
                        $lu = '';
                        $dernier_chemin = self::chemin( $dernier );
                        if ( $dernier_chemin ) {
                                $lu = (string) file_get_contents( $dernier_chemin ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_get_contents -- lecture d'un rapport ecrit par ce module, nom verrouille par regex
                        }
                        $nb = count( partikulier_pk_diag_pages( array( 'max_listings' => 0 ) ) );
                        $ko = preg_match_all( '#\[KO\]#', $lu );
                        $warn = preg_match_all( '#\[WARN\]#', $lu );

                        echo '<div class="wrap"><h1>Diagnostic du site — sonde v' . esc_html( PK_SONDE_VERSION ) . '</h1>';
                        if ( $notice ) {
                                echo '<div class="notice notice-success"><p>' . esc_html( $notice ) . '</p></div>';
                        }
                        if ( $fin ) {
                                echo '<div class="notice ' . ( $fin['ko'] ? 'notice-error' : ( $fin['warn'] ? 'notice-warning' : 'notice-success' ) ) . ' is-dismissible"><p>';
                                echo esc_html( ( $fin['ko'] ? $fin['ko'] . ' point(s) bloquant(s)' : 'aucun point bloquant' ) . ' · ' . $fin['warn'] . ' à améliorer · ' . $fin['info'] . ' information(s) · ' . $fin['urls'] . ' URLs mesurées. Le rapport est en bas de cette page.' );
                                echo '</p></div>';
                        }
                        echo '<p class="description" style="max-width:70em">Ce bouton lance, sur ton serveur, ce qu\'un développeur réclame avant de dire « ça marche » : <strong>toutes</strong> les pages (chaque annonce, les archives et leur pagination, les taxonomies, les pages, les 3 langues, les pages privées, la recherche, une 404), les rendus <strong>Facebook / X / WhatsApp / Google / Discord et robots d\'IA</strong>, le <strong>cache</strong> froid puis chaud vérifié sur le disque, la <strong>performance</strong>, la <strong>sécurité</strong> vue de l\'extérieur, le <strong>SEO</strong>, l\'<strong>UI/UX</strong> sans navigateur et la <strong>lisibilité par un LLM</strong>. Aucune donnée ne sort du serveur ; aucun fichier du thème n\'est modifié.</p>';

                        echo '<form method="get" style="margin:14px 0;max-width:70em">';
                        echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '">';
                        echo '<table class="form-table" role="presentation"><tbody>';
                        echo '<tr><th scope="row">Pages à contrôler</th><td><input type="number" name="pk_max" value="0" min="0" step="10" class="small-text"> <span class="description">0 = ' . (int) $nb . ' URLs (tout le site). Le site en contient ' . (int) $nb . ' ; chaque annonce est testée dans chaque langue traduite.</span></td></tr>';
                        echo '<tr><th scope="row">Chemin de mesure</th><td><select name="pk_mode">';
                        echo '<option value="auto">auto — HTTP réel depuis le serveur (exerce le cache, recommandé)</option>';
                        echo '<option value="local">local — rendu sans HTTP (si la boucle locale est cassée ; le cache n\'est pas exercé)</option>';
                        echo '</select></td></tr>';
                        echo '<tr><th scope="row">Options</th><td>';
                        echo '<label><input type="checkbox" name="pk_sans_reseau" value="1"> sauter les rendus réseaux sociaux et robots d\'IA (2 à 3 fois plus vite)</label><br>';
                        echo '<label><input type="checkbox" name="pk_sans_campagne" value="1"> sauter les URLs de campagne (fbclid/gclid/utm/tri)</label><br>';
                        echo '<label><input type="checkbox" name="pk_reprendre" value="1"> reprendre où la dernière fois s\'est arrêtée</label>';
                        echo '</td></tr></tbody></table>';
                        wp_nonce_field( 'pk_diag_lancer', 'pk_nonce', false );
                        echo '<input type="hidden" name="pk_agir" value="lancer">';
                        echo '<p><button class="button button-primary button-hero">Lancer le diagnostic complet</button></p>';
                        echo '</form>';

                                                /* Le bloc « Transmettre le rapport / relancer un test » : six liens prets a
                           copier-coller, identiques a ceux de la page, pour coller le resultat dans
                           une conversation sans jamais toucher au FTP. */
                        $jeton = wp_create_nonce( self::NONCE_ACTION );
                        $base   = add_query_arg( array( self::QUERY => '1', self::QUERY . '_jeton' => $jeton ), home_url( '/' ) );
                        $liens  = array(
                                'json'   => array( 'libelle' => 'JSON compact (a copier-coller)', 'args' => array( 'json' => 1, 'lite' => 1, 'quick' => 1 ) ),
                                'json2'  => array( 'libelle' => 'JSON complet', 'args' => array( 'json' => 1 ) ),
                                'rap'    => array( 'libelle' => 'Rapport texte complet', 'args' => array() ),
                                'load'   => array( 'libelle' => 'Test « 100 requetes a la fois »', 'args' => array( 'load' => 1, 'n' => 100, 'c' => 10 ) ),
                                'load2'  => array( 'libelle' => 'Test doux (50 x 20 paralleles)', 'args' => array( 'load' => 1, 'n' => 50, 'c' => 20 ) ),
                                'purge'  => array( 'libelle' => 'Purger puis re-mesurer (cache + hebergeur)', 'args' => array( 'purge' => 1, 'quick' => 1 ) ),
                        );
                        echo '<h2 style="margin-top:22px">Transmettre le rapport / relancer un test</h2>';
                        echo '<p class="description">Ces liens sont valides <strong>12 heures</strong> et seulement connecte en administrateur. Le jeton ne se partage pas : celui qui ouvre le lien doit etre connecte en administrateur sur ce WordPress.</p><p>';
                        foreach ( $liens as $cle => $l ) {
                                $u = add_query_arg( $l['args'], $base );
                                echo '<a class="button" href="' . esc_url( $u ) . '">' . esc_html( $l['libelle'] ) . '</a> ';
                        }
                        echo '</p>';
                        echo '<p class="description"><code>' . esc_html( $base ) . '</code> — a coller dans un onglet de ce navigateur (session admin requise).</p>';
if ( $lu ) {
                                echo '<div style="border-left:4px solid ' . ( $ko ? '#d63638' : ( $warn ? '#dba617' : '#00a32a' ) ) . ';background:' . ( $ko ? '#fcf0ef' : ( $warn ? '#fff8e5' : '#edfaef' ) ) . ';padding:12px 16px;max-width:80em"><strong>';
                                echo $ko ? esc_html( 'Diagnostic terminé : ' . $ko . ' point(s) bloquant(s), ' . $warn . ' à améliorer.' ) : esc_html( $warn ? 'Aucun point bloquant ; ' . $warn . ' point(s) à améliorer.' : 'Rien de cassé sur le périmètre mesuré.' );
                                echo '</strong></div>';
                                echo '<p>';
                                echo '<a class="button button-primary" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'page' => self::SLUG, 'pk_agir' => 'telecharger' ), admin_url( 'admin.php' ) ), 'pk_diag_telecharger', 'pk_nonce' ) ) . '">Télécharger le rapport .txt</a> ';
                                echo '<a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'page' => self::SLUG, 'pk_agir' => 'csv' ), admin_url( 'admin.php' ) ), 'pk_diag_csv', 'pk_nonce' ) ) . '">Télécharger le tableau .csv (une ligne par page)</a> ';
                                echo '</p>';
                                echo '<textarea readonly rows="30" style="width:100%;max-width:110em;font:12px/1.45 monospace">' . esc_textarea( $lu ) . '</textarea>';
                                echo '<p class="description">Colle ce texte tel quel à Jules ou Manus : les codes HTTP, les millisecondes et les octets sont la preuve — n\'interprète rien, et n\'accepte pas une correction qui ne cite pas la ligne du rapport.</p>';
                        }

                        if ( $fichiers ) {
                                echo '<h2>Rapports sur le disque (' . count( $fichiers ) . ')</h2>';
                                echo '<p class="description">Dossier <code>wp-content/pk-rapports/</code> — protégé par un <code>.htaccess</code> interne ; la section sécurité vérifie d\'ailleurs qu\'il n\'est pas lisible de l\'extérieur.</p><ol style="max-width:70em">';
                                foreach ( array_slice( array_reverse( $fichiers ), 0, 14 ) as $f ) {
                                        $b = basename( $f );
                                        $est_txt = 'txt' === strtolower( (string) pathinfo( $f, PATHINFO_EXTENSION ) );
                                        $u = add_query_arg( array( 'page' => self::SLUG, 'pk_agir' => $est_txt ? 'telecharger' : 'csv', 'pk_fichier' => $b ), admin_url( 'admin.php' ) );
                                        echo '<li>' . esc_html( $b ) . ' · ' . esc_html( size_format( (int) filesize( $f ) ) ) . ' · ' . esc_html( gmdate( 'Y-m-d H:i', (int) filemtime( $f ) ) )
                                                . ' — <a href="' . esc_url( wp_nonce_url( $u, 'pk_diag_' . ( $est_txt ? 'telecharger' : 'csv' ), 'pk_nonce' ) ) . '">' . ( $est_txt ? 'télécharger le rapport' : 'télécharger le tableau' ) . '</a></li>';
                                }
                                echo '</ol>';
                                echo '<p><a class="button" href="' . esc_url( wp_nonce_url( add_query_arg( array( 'page' => self::SLUG, 'pk_agir' => 'nettoyer' ), admin_url( 'admin.php' ) ), 'pk_diag_nettoyer', 'pk_nonce' ) ) . '">Supprimer tous les rapports et les fichiers de test</a></p> ';
                        }
                        echo '<p class="description" style="max-width:78em">Le module ne répare rien : il mesure et il nomme. Pour la CI ou un serveur sans admin : la copie <code>pk-sonde.php</code> du dépôt, posée à la racine du WordPress (à côté de <code>wp-config.php</code>), répond le même rapport (<code>php pk-sonde.php</code> en CLI, ou le lien avec clé automatique).</p>';
                        echo '</div>';
                }
        }

        Partikulier_Pk_Sonde::init();
}
