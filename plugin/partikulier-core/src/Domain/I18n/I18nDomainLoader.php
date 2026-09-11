<?php
/**
 * Chargeur unique des domaines de traduction runtime (lot C3, CDC v1.2
 * §3.2 I18N-1 — « un seul mécanisme de traduction actif »).
 *
 * Le lot C2 a unifié la résolution des chaînes (filtre gettext détenu par
 * I18nChromeService) ; le lot C3 transfère le DERNIER mécanisme encore côté
 * thème au plugin : le chargement/rechargement runtime des textdomains.
 *   · « partikulier » : chargement canonique au bootstrap (locale du site —
 *     correctif C1A-013), rafraîchi à init@5 vers la locale effective de la
 *     requête, puis réaffirmé à wp@1 selon le slug public (port des chargeurs
 *     init@5/wp@1 du thème — en/ar rechargés, fr = langue source : msgids) ;
 *   · « es » (Estatik) : rechargement à la locale de la page (port VERBATIM
 *     du correctif 6.17.31 du popup — trois sources candidates + re-résolution
 *     du conteneur de réglages d'Estatik qui met en cache les default_value).
 *
 * EXTINCTION (progressive documentée) : les méthodes runtime du thème
 * (class-localization-runtime.php) sont dormantes quand ce chargeur est
 * actif — le thème 6.19.0+ les garde comme chemin autonome REG-5 ; le retrait
 * physique relève du lot C4. Catalogues canoniques <locale>.mo (C1A-011/013,
 * doublons proscrits) ; la personnalisation client WP_LANG_DIR/plugins/ garde
 * la priorité au bootstrap.
 *
 * Facade SANS AUCUN hook au chargement (points runtime inscrits par le
 * bootstrap via register_runtime) ; AUCUNE table, AUCUNE écriture. Le schéma
 * reste 2.6.0 (aucune migration au C3).
 */
declare(strict_types=1);

namespace Partikulier\Core\Domain\I18n;

final class I18nDomainLoader
{
    private const DOMAIN = 'partikulier';
    private const ESTATIK_DOMAIN = 'es';

    /** @var string|null locale du catalogue « partikulier » chargé (null = source) */
    private static $loaded_locale = null;

    /** @var string|null catalogue « es » effectivement chargé (chemin, null = aucun) */
    private static $es_catalog = null;

    /**
     * Inscrit les trois points runtime du mécanisme unique — appelé uniquement
     * par le bootstrap du plugin (jamais au chargement de la classe).
     */
    public static function register_runtime(): void
    {
        // Port de l'appel anticipé du thème (avant l'init d'Estatik, pour que
        // ses réglages se figent dans la bonne locale) : after_setup_theme@1.
        // accepted_args = 0 : certains noyaux WP transmettent un argument vide
        // aux callbacks « init » — la signature à argument optionnel ne doit
        // JAMAIS le recevoir (sinon « » serait interprété comme langue source).
        \add_action('after_setup_theme', [self::class, 'prime_estatik_domain'], 1, 0);
        // Port du chargeur init@5 du thème : rafraîchit vers la locale effective
        // de la requête (Polylang l'a résolue) — idempotent.
        \add_action('init', [self::class, 'refresh_domain'], 5, 0);
        // Port des chargeurs wp@1 + wp@2 du thème : réaffirmation selon le slug
        // public + domaine « es ». L'action « wp » ne se déclenche qu'en front.
        \add_action('wp', [self::class, 'reload_active_domains'], 1, 0);
    }

    /**
     * Chargement canonique au bootstrap (locale du site — correctif C1A-013) :
     * la voie de personnalisation client WP_LANG_DIR garde la priorité, puis
     * le catalogue canonique <locale>.mo du plugin. Le chemin est ensuite
     * enregistré via load_plugin_textdomain() pour le JIT de WP 7.x.
     */
    public static function load_bootstrap_domain(): void
    {
        $locale = (string) \apply_filters('plugin_locale', \determine_locale(), self::DOMAIN);
        if (self::load_custom_catalog($locale) || self::load_catalog($locale)) {
            self::$loaded_locale = $locale;
        }
        // WP <= 6.6 : load_plugin_textdomain() seul ne trouve pas le nommage
        // <locale>.mo (il ne cherche que <domaine>-<locale>.mo) — le catalogue
        // canonique est donc chargé explicitement ci-dessus. WP 7.x : ce call
        // enregistre le chemin pour les rechargements paresseux du JIT.
        \load_plugin_textdomain(
            self::DOMAIN,
            false,
            \dirname(\plugin_basename(PARTIKULIER_CORE_FILE)) . '/languages'
        );
    }

    /**
     * Rafraîchit le domaine « partikulier » vers la locale effective
     * (determine_locale : locale du site en CLI, locale de la requête en
     * front — port du chargeur init@5 du thème). Idempotent : ne recharge
     * que si la locale diffère de celle déjà chargée.
     */
    public static function refresh_domain(?string $locale = null): string
    {
        // Garde : un argument VIDE n'est pas une locale — certains noyaux WP
        // transmettent « » aux callbacks « init » (constaté WP 7.1 CLI) ; la
        // chaîne vide doit être dérivée de determine_locale(), jamais
        // interprétée comme « langue source ».
        if ($locale === null || $locale === '') {
            $locale = (string) \determine_locale();
        }
        if ($locale === self::$loaded_locale) {
            return 'deja:' . ($locale ?: 'source');
        }
        if (\function_exists('unload_textdomain')) {
            \unload_textdomain(self::DOMAIN);
        }
        if ('' === $locale || !self::load_catalog($locale)) {
            self::$loaded_locale = null;
            return 'source(msgid)';
        }
        self::$loaded_locale = $locale;
        return $locale;
    }

    /**
     * Réaffirme les domaines selon le slug public courant (wp@1, front) —
     * port des chargeurs wp@1 (partikulier) et wp@2 (es) du thème.
     */
    public static function reload_active_domains(): array
    {
        return self::reload_for_slug(self::active_slug());
    }

    /**
     * Recharge les domaines pour un slug public explicite — LE point d'entrée
     * runtime (wp@1) et la couture de test des contrats (aucune dépendance à
     * Polylang : le slug est passé explicitement).
     *
     * @return array{partikulier:string,es:string} rapport du rechargement
     */
    public static function reload_for_slug(string $slug): array
    {
        $report = ['partikulier' => 'inchange', 'es' => 'inchange'];
        $locale = self::locale_for_slug($slug);
        if ('' === $locale) {
            // fr (langue source) : domaine volontairement vide — les msgids
            // sont la forme française (état final identique au chemin
            // historique : init@5 sans catalogue fr → domaine vide).
            if (\function_exists('unload_textdomain')) {
                \unload_textdomain(self::DOMAIN);
            }
            self::$loaded_locale = null;
            $report['partikulier'] = 'source(msgid)';
        } elseif ($locale !== self::$loaded_locale) {
            if (\function_exists('unload_textdomain')) {
                \unload_textdomain(self::DOMAIN);
            }
            $loaded = self::load_catalog($locale);
            $report['partikulier'] = $loaded ? $locale : 'absent';
            self::$loaded_locale = $loaded ? $locale : null;
        } else {
            $report['partikulier'] = $locale . '(deja)';
        }
        $report['es'] = self::reload_estatik_for_slug($slug);
        return $report;
    }

    /**
     * Appel anticipé du domaine « es » (after_setup_theme@1) — avant l'init
     * d'Estatik, si Polylang connaît déjà la langue (port VERBATIM de l'appel
     * anticipé du thème ; les réglages Estatik se figent dans la bonne locale).
     */
    public static function prime_estatik_domain(): string
    {
        return self::reload_estatik_for_slug(self::active_slug());
    }

    /**
     * Slug public courant (Polylang si présent, sinon chaîne vide — CLI).
     */
    public static function active_slug(): string
    {
        return \function_exists('pll_current_language') ? (string) \pll_current_language('slug') : '';
    }

    /**
     * Locale canonique d'un slug public : en → en_US, ar → ar, fr/autre →
     * chaîne vide (langue source — les msgids sont la forme française).
     */
    public static function locale_for_slug(string $slug): string
    {
        if ('en' === $slug) {
            return 'en_US';
        }
        if ('ar' === $slug) {
            return 'ar';
        }
        return '';
    }

    /**
     * Locale du catalogue « partikulier » actuellement chargé (null = source).
     */
    public static function loaded_locale(): ?string
    {
        return self::$loaded_locale;
    }

    /**
     * Catalogue « es » effectivement chargé (chemin complet, null = aucun).
     */
    public static function estatik_catalog(): ?string
    {
        return self::$es_catalog;
    }

    /**
     * Recharge le domaine « es » (Estatik, tiers) avec la locale de la page —
     * port VERBATIM du correctif 6.17.31 : sources consultées dans l'ordre
     * catalogue du plugin Estatik, catalogue embarqué par le thème, puis
     * emplacement communautaire WP_LANG_DIR ; re-résolution du conteneur de
     * réglages d'Estatik (cache statique des default_value résolus par __()).
     *
     * @return string rapport : chemin chargé, ou motif du refus
     */
    private static function reload_estatik_for_slug(string $slug): string
    {
        if (\is_admin() || \wp_doing_ajax() || \wp_doing_cron()) {
            return 'refus(contexte)';
        }
        if ('' === $slug) {
            return 'refus(slug-inconnu)';
        }
        $locale = 'en' === $slug ? 'en_US' : ('fr' === $slug ? 'fr_FR' : ('ar' === $slug ? 'ar' : ''));
        if ('' === $locale || !\function_exists('unload_textdomain')) {
            return 'refus(locale)';
        }

        $candidates = [];
        if (\defined('WP_PLUGIN_DIR')) {
            $candidates[] = \trailingslashit(\WP_PLUGIN_DIR) . 'estatik/languages/es-' . $locale . '.mo';
        }
        $theme_dir = \get_template_directory();
        if ('' !== (string) $theme_dir) {
            $candidates[] = \trailingslashit((string) $theme_dir) . 'languages/estatik/es-' . $locale . '.mo';
        }
        if (\defined('WP_LANG_DIR')) {
            $candidates[] = \trailingslashit(\WP_LANG_DIR) . 'plugins/es-' . $locale . '.mo';
        }

        foreach ($candidates as $file) {
            if (\is_readable($file)) {
                \unload_textdomain(self::ESTATIK_DOMAIN);
                \load_textdomain(self::ESTATIK_DOMAIN, $file);
                self::$es_catalog = $file;
                /* Le conteneur de réglages d'Estatik met en cache statique les
                 * default_value résolus par __() à la première lecture — on le
                 * force à se re-résoudre avec le catalogue fraîchement chargé
                 * (les titres du popup viennent de ces réglages). */
                if (\class_exists('Es_Settings_Container') && \method_exists('Es_Settings_Container', 'get_available_settings')) {
                    \Es_Settings_Container::get_available_settings(true);
                }
                return $file;
            }
        }
        return 'refus(aucun-catalogue)';
    }

    /**
     * Charge le catalogue canonique du plugin : languages/<locale>.mo.
     * NB : SANS troisième argument — le contrôleur de traduction de WP range
     * les entrées PAR LOCALE COURANTE (determine_locale) et la recherche
     * (get_translations_for_domain) utilise la même clé : passer une locale
     * explicite rendrait le catalogue invisible pour la recherche (constaté
     * C3A-007). C'est aussi la sémantique historique du chargeur du thème
     * (load_textdomain(domaine, fichier) — le domaine sert le DERNIER
     * catalogue chargé).
     */
    private static function load_catalog(string $locale): bool
    {
        $file = \dirname(PARTIKULIER_CORE_FILE) . '/languages/' . $locale . '.mo';
        if (!\is_readable($file)) {
            return false;
        }
        return (bool) \load_textdomain(self::DOMAIN, $file);
    }

    /**
     * Charge la personnalisation client (convention WP) :
     * WP_LANG_DIR/plugins/<domaine>-<locale>.mo — sans locale explicite
     * (même raison que load_catalog : clé = locale courante du contrôleur).
     */
    private static function load_custom_catalog(string $locale): bool
    {
        if (!\defined('WP_LANG_DIR')) {
            return false;
        }
        $file = \trailingslashit(\WP_LANG_DIR) . 'plugins/' . self::DOMAIN . '-' . $locale . '.mo';
        if (!\is_readable($file)) {
            return false;
        }
        return (bool) \load_textdomain(self::DOMAIN, $file);
    }
}
