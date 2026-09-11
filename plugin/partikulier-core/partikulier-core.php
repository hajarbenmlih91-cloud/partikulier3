<?php
/**
 * Plugin Name: Partikulier Core
 * Description: Cœur métier contractuel de Partikulier : données, politiques et REST.
 * Version: 2.10.1
 * Requires PHP: 8.1
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

const PARTIKULIER_CORE_VERSION = '2.10.1';
const PARTIKULIER_CORE_FILE = __FILE__;

// Domaine « partikulier » — le plugin est la source canonique (lot C1 :
// catalogues <locale>.mo consolidés, contrats C1A-011/013) et détient LE
// chargeur unique (lot C3, CDC §3.2 I18N-1 — « un seul mécanisme de
// traduction actif ») : chargement canonique au bootstrap (locale du site,
// correctif C1A-013 — WP <= 6.6 ne charge pas le nommage <locale>.mo via
// load_plugin_textdomain() seul), puis les trois points runtime (init@5,
// after_setup_theme@1, wp@1 — domaines « partikulier » et « es » d'Estatik)
// sont inscrits par I18nDomainLoader::register_runtime(). Le thème 6.19.1+
// ne charge plus AUCUN textdomain et n'embarque plus AUCUNE copie du kit du
// domaine « partikulier » (lot C4 — retrait physique des chargeurs et copies
// dormants : extinction finale). Le catalogue arabe du popup d'Estatik reste
// servi depuis languages/estatik/ du thème, source vivante que CE chargeur
// consulte (deuxième source candidate).
require_once __DIR__ . '/src/Domain/I18n/I18nDomainLoader.php';
\Partikulier\Core\Domain\I18n\I18nDomainLoader::load_bootstrap_domain();
\Partikulier\Core\Domain\I18n\I18nDomainLoader::register_runtime();

require_once __DIR__ . '/src/Database/Schema.php';
require_once __DIR__ . '/src/Database/Migrator.php';
require_once __DIR__ . '/src/Rest/RouteRegistry.php';
require_once __DIR__ . '/src/Domain/DomainRegistry.php';
/*
 * Lot D (découpage, arbitrage « Référence + plugin ») : les traits composant
 * les quatre services découpés sont chargés AVANT leurs classes shells —
 * même discipline que le précédent B6 (modules du monolithe i18n du thème,
 * chargés avant le shell Partikulier_Localization).
 */
require_once __DIR__ . '/src/Integration/SynchronizerHooksTrait.php';
require_once __DIR__ . '/src/Integration/SynchronizerProjectionTrait.php';
require_once __DIR__ . '/src/Integration/SynchronizerMaintenanceTrait.php';
require_once __DIR__ . '/src/Integration/ListingSynchronizer.php';
require_once __DIR__ . '/src/ListingRepository.php';
require_once __DIR__ . '/src/HealthCheck.php';

/*
 * Services du domaine paiements/premium (lot B1) : chargés inconditionnellement,
 * comme ListingSynchronizer — la couture du thème (class_exists) doit trouver
 * la classe sur toute requête, sinon une page publique retomberait silencieusement
 * sur le chemin autonome du thème et le domaine serait lu/écrit des deux côtés
 * (violation du critère de sortie du lot B). Deux classes pures, aucun hook au
 * chargement : coût de bootstrap négligeable, mesuré par REG-2.
 */
require_once __DIR__ . '/src/Domain/Premium/PremiumService.php';
require_once __DIR__ . '/src/Domain/Payments/PaymentsOrdersTrait.php';
require_once __DIR__ . '/src/Domain/Payments/PaymentsSubscriptionsTrait.php';
require_once __DIR__ . '/src/Domain/Payments/PaymentService.php';
require_once __DIR__ . '/src/Domain/Leads/LeadsContactTrait.php';
require_once __DIR__ . '/src/Domain/Leads/LeadsRestTrait.php';
require_once __DIR__ . '/src/Domain/Leads/LeadsPrivacyTrait.php';
require_once __DIR__ . '/src/Domain/Leads/LeadsAdminTrait.php';
require_once __DIR__ . '/src/Domain/Leads/LeadService.php';
require_once __DIR__ . '/src/Domain/Alerts/AlertService.php';
require_once __DIR__ . '/src/Domain/Automation/AutomationPolicyTrait.php';
require_once __DIR__ . '/src/Domain/Automation/AutomationHmacTrait.php';
require_once __DIR__ . '/src/Domain/Automation/AutomationService.php';
require_once __DIR__ . '/src/Domain/OwnerStats/OwnerStatsService.php';
require_once __DIR__ . '/src/Domain/TranslationVariants/TranslationVariantsService.php';

/*
 * Domaine i18n contenu (lot C1) : couche de rédaction multilingue des
 * annonces (lexique trilingue, générateurs de titre/description/meta/alt).
 * Sept classes pures chargées inconditionnellement — AUCUN hook au
 * chargement, AUCUNE table, AUCUNE écriture (le schéma reste 2.6.0 — aucune
 * migration au lot C1). La couture du thème 6.18.8+ (class_exists sur
 * I18nContentService) trouve la facade sur toute requête : coût de
 * bootstrap mesuré par REG-2.
 */
require_once __DIR__ . '/src/Domain/I18n/ListingLexicon.php';
require_once __DIR__ . '/src/Domain/I18n/ListingVocabulary.php';
require_once __DIR__ . '/src/Domain/I18n/ListingTextUtils.php';
require_once __DIR__ . '/src/Domain/I18n/ListingTextService.php';
require_once __DIR__ . '/src/Domain/I18n/ListingSeoTextService.php';
require_once __DIR__ . '/src/Domain/I18n/ListingPostTextService.php';
require_once __DIR__ . '/src/Domain/I18n/I18nContentService.php';

/*
 * Domaine i18n chrome (lot C2) : dictionnaires de repli du chrome public
 * (136 chaînes) et du formulaire de dépôt (140 chaînes), ports VERBATIM des
 * catalogues du thème, + service unifié de résolution gettext (chaîne figée
 * REG-3 : .mo > form > chrome > polylang). Deux classes de données pures et
 * une facade chargées inconditionnellement — AUCUN hook au chargement, AUCUNE
 * table, AUCUNE écriture (le schéma reste 2.6.0 — aucune migration au lot
 * C2). La couture du thème 6.18.9+ (class_exists sur I18nChromeService)
 * trouve la facade sur toute requête : coût de bootstrap mesuré par REG-2.
 */
require_once __DIR__ . '/src/Domain/I18n/ChromeDictionary.php';
require_once __DIR__ . '/src/Domain/I18n/FormsDictionary.php';
require_once __DIR__ . '/src/Domain/I18n/I18nChromeService.php';

/*
 * Domaine leads/qualification/WhatsApp (lot B2) — rétention (port fidèle
 * de Partikulier_Lead_Retention) : la planification quotidienne et le
 * handler du cron pk_buyer_privacy_purge vivent ici — le thème 6.18.3+
 * cesse de les enregistrer quand le service existe.
 */
add_action('plugins_loaded', static function (): void {
    if (!class_exists(\Partikulier\Core\Domain\Leads\LeadService::class)) {
        return;
    }
    add_action('init', [\Partikulier\Core\Domain\Leads\LeadService::class, 'maybe_schedule_retention'], 20);
    add_action(\Partikulier\Core\Domain\Leads\LeadService::CRON_HOOK, [\Partikulier\Core\Domain\Leads\LeadService::class, 'purge_expired']);
}, 2);

/*
 * Domaine alertes (lot B3) : chargé inconditionnellement (require
 * ci-dessus) — classe pure, AUCUN hook au chargement : aucun cron, aucune
 * route (port fidèle du contrat du thème — l'adaptateur Meta/n8n n'est
 * pas encore ouvert). La couture du thème 6.18.4+ (class_exists) trouve
 * la classe sur toute requête : coût de bootstrap mesuré par REG-2.
 */

/*
 * Domaine automatisation n8n (lot B4) — réglages (port fidèle de
 * Partikulier_N8n_Security::maybe_migrate) : la migration unique des
 * réglages n8n depuis les options historiques du thème vit ici — le
 * thème 6.18.5+ cesse de l'exécuter quand le service existe. La route
 * /automation-event est déclarée par le RestController (owner plugin).
 */
add_action('plugins_loaded', static function (): void {
    if (!class_exists(\Partikulier\Core\Domain\Automation\AutomationService::class)) {
        return;
    }
    add_action('init', [\Partikulier\Core\Domain\Automation\AutomationService::class, 'maybe_migrate'], 5);
}, 2);

/*
 * Domaine statistiques propriétaire (lot B5) — purge de rétention (port
 * fidèle de Partikulier_Owner_Insights) : la planification quotidienne et
 * le handler du cron pk_owner_insights_daily_purge vivent ici — le thème
 * 6.18.6+ cesse de les enregistrer quand le service existe. Les deux
 * routes /owner/* restent déclarées par le thème (écran d'intégration —
 * arbitrage B5, cf. OwnerStatsService).
 */
add_action('plugins_loaded', static function (): void {
    if (!class_exists(\Partikulier\Core\Domain\OwnerStats\OwnerStatsService::class)) {
        return;
    }
    add_action('init', [\Partikulier\Core\Domain\OwnerStats\OwnerStatsService::class, 'maybe_schedule_purge'], 20);
    add_action(\Partikulier\Core\Domain\OwnerStats\OwnerStatsService::CRON_HOOK, [\Partikulier\Core\Domain\OwnerStats\OwnerStatsService::class, 'purge_expired_saves']);
}, 2);

/*
 * Domaine variantes de traduction (lot B6) : chargé inconditionnellement
 * (require ci-dessus) — classe pure, AUCUN hook au chargement, AUCUN cron,
 * AUCUNE route (port fidèle du contrat du thème : le registre est dormant,
 * prepare_variant/link_variant attendent la passerelle de traduction du lot
 * C ; aucun appelant runtime au jour du lot). La couture du thème 6.18.7+
 * (class_exists) trouve la classe sur toute requête : coût de bootstrap
 * mesuré par REG-2.
 */

/*
 * Domaine i18n chrome (lot C2) — EXTINCTION du filtre gettext historique du
 * thème : le filtre du service unifié (I18nChromeService::translate) devient
 * LE mécanisme de résolution des chaînes du domaine « partikulier ». Le
 * thème 6.18.9+ cesse d'enregistrer son propre filtre quand ce service
 * existe et lui fournit son registre chrome (provide_registry — inversion
 * de dépendance : le registre est une donnée du thème). Sans le plugin, le
 * repli local du thème reste actif à l'identique (REG-5). Extinction
 * progressive documentée (CDC §3.2 I18N-1) — le retrait physique des copies
 * dormantes côté thème relève du lot C4.
 */
add_action('plugins_loaded', static function (): void {
    if (!class_exists(\Partikulier\Core\Domain\I18n\I18nChromeService::class)) {
        return;
    }
    \Partikulier\Core\Domain\I18n\I18nChromeService::register_gettext_filter();
}, 2);

/**
 * Les classes REST et d’écriture ne sont pas nécessaires sur une page HTML
 * publique. Elles restent chargées pour REST, WP-CLI et l’administration afin
 * de préserver les contrats existants et les commandes de maintenance.
 */
function partikulier_core_should_load_rest(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    if (defined('REST_REQUEST') && REST_REQUEST) {
        return true;
    }
    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }
    if (is_admin()) {
        return true;
    }
    $request_uri = isset($_SERVER['REQUEST_URI']) ? sanitize_text_field(wp_unslash((string) $_SERVER['REQUEST_URI'])) : '';
    return str_contains($request_uri, '/wp-json/') || isset($_GET['rest_route']); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- route detection, not form processing
}

function partikulier_core_should_load_jobs(): bool
{
    if (PHP_SAPI === 'cli') {
        return true;
    }
    if (defined('WP_CLI') && WP_CLI) {
        return true;
    }
    if (defined('DOING_CRON') && DOING_CRON) {
        return true;
    }
    return is_admin();
}

function partikulier_core_load_rest_classes(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    require_once __DIR__ . '/src/Services.php';
    require_once __DIR__ . '/src/AuditLogger.php';
    require_once __DIR__ . '/src/ListingPolicy.php';
    require_once __DIR__ . '/src/ListingService.php';
    require_once __DIR__ . '/src/SearchService.php';
    require_once __DIR__ . '/src/TranslationService.php';
    require_once __DIR__ . '/src/RateLimiter.php';
    require_once __DIR__ . '/src/RestController.php';
    require_once __DIR__ . '/src/Integration/LeadBridge.php';
    $loaded = true;
}

if (partikulier_core_should_load_rest()) {
    partikulier_core_load_rest_classes();
}

use Partikulier\Core\Database\Migrator;
use Partikulier\Core\HealthCheck;
use Partikulier\Core\Integration\ListingSynchronizer;
use Partikulier\Core\RestController;
use Partikulier\Core\Rest\RouteRegistry;

add_action('plugins_loaded', static function (): void {
    if (!class_exists('\\wpdb')) {
        return;
    }
    $GLOBALS['partikulier_core_migrator'] = new Migrator();
    $GLOBALS['partikulier_core_health'] = new HealthCheck();

    // INTEG-1 : la synchronisation temps réel est enregistrée sur toute requête
    // (administration, REST, WP-CLI, cron) — les hooks ne coûtent rien sans
    // événement properties.
    $GLOBALS['partikulier_core_sync'] = new ListingSynchronizer();
    $GLOBALS['partikulier_core_sync']->register();

    if (partikulier_core_should_load_jobs()) {
        require_once __DIR__ . '/src/Services.php';
        $GLOBALS['partikulier_core_jobs'] = new \Partikulier\Core\JobRunner();
        $GLOBALS['partikulier_core_jobs']->register();
    }
    if (partikulier_core_should_load_rest()) {
        partikulier_core_load_rest_classes();
        $GLOBALS['partikulier_core_rest'] = new RestController();
    }

    // Passage de version : la migration 2.0.0 (reconstruction journalisée de
    // la projection, extinction des leads-commentaires, retrait du cron
    // quotidien) s'exécute exactement une fois, verrouillée contre les
    // exécutions concurrentes.
    $migrator = $GLOBALS['partikulier_core_migrator'];
    if ($migrator instanceof Migrator && $migrator->currentVersion() !== \Partikulier\Core\Database\Schema::VERSION) {
        $migrator->migrate();
    }
}, 5);

// Supporte aussi les appels programmatiques à rest_do_request() depuis WP-CLI,
// les tests PHP et les tâches internes, qui n’ont pas d’URI REST entrante.
add_action('rest_api_init', static function (): void {
    partikulier_core_load_rest_classes();
    if (!isset($GLOBALS['partikulier_core_rest'])) {
        $GLOBALS['partikulier_core_rest'] = new RestController();
    }
}, 1);

register_activation_hook(__FILE__, static function (): void {
    (new Migrator())->migrate();
});

register_deactivation_hook(__FILE__, static function (): void {
    // La désactivation ne supprime jamais les données : les migrations sont conservées.
});

/* ---------------------------------------------------------------------------
 * Paquet d'installation : les mu-plugins contractuels du projet sont
 * livre a cote de ce plugin (ils ne sont pas televersables par l'interface
 * WordPress). Une fois ce plugin actif, ils sont charges depuis ici : le
 * comportement attendu du theme est donc reellement en place sans FTP.
 *
 * Ce bloc ne remplace pas wp-content/mu-plugins : il le complete. Si l'un des
 * fichiers existe deja dans wp-content/mu-plugins, WordPress l'a deja charge et
 * on ne le recharge pas (garde function_exists ci-dessous).
 * ------------------------------------------------------------------------ */
add_action('plugins_loaded', static function (): void {
    $dossier = __DIR__ . '/mu-plugins';
    if (!is_dir($dossier)) {
        return;
    }
    /* Liste ouverte : tout *.php livre dans mu-plugins/ est charge, dans l'ordre
       alphabetique. La liste fermee precedente faisait silencieusement sauter un
       fichier ajoute plus tard — le genre de silence que le diagnostic doit
       refuser. Des garde-fous existent deja : include_once (jamais deux fois) et
       le saut du fichier que wp-content/mu-plugins a deja charge. */
    $fichiers = glob($dossier . '/*.php');
    if (!is_array($fichiers)) {
        return;
    }
    sort($fichiers);
    foreach ($fichiers as $f) {
        $nom = basename($f);
        if (!is_readable($f)) {
            continue;
        }
        /* WPMU_PLUGIN_DIR (= wp-content/mu-plugins), PAS WP_PLUGIN_DIR/mu-plugins :
           l'ancien chemin regardait wp-content/plugins/mu-plugins, un dossier que
           personne n'utilise. Mesure sur banc : avec les mu-plugins reellement
           installes dans wp-content/mu-plugins, le garde ne les voyait pas, les deux
           copies etaient chargees (include_once ne dedupe que le MEME chemin), et
           partikulier_diagnostic_headers() etait redeclare → fatal sur tout le site.
           Le garde doit tester l'emplacement reel de WordPress. */
        if (defined('WPMU_PLUGIN_DIR') && is_readable(WPMU_PLUGIN_DIR . '/' . $nom)) {
            continue; // deja charge par WordPress
        }
        include_once $f;
    }
}, 1);

/* Rappel visible si le theme installe n'embarque pas le module de diagnostic :
   mieux vaut le dire que laisser croire qu'il n'y a rien a controler. */
add_action('admin_notices', static function () {
    if (!current_user_can('manage_options')) { return; }
    if (file_exists(get_template_directory() . '/pk-diagnostic.php')) { return; }
    echo '<div class="notice notice-warning"><p>Partikulier Core : le theme actif ne contient pas <code>pk-diagnostic.php</code> — le televerser depuis le paquet d\'installation (le diagnostic complet du site n\'est pas disponible sans lui).</p></div>';
});
