<?php
/**
 * Service unifié de résolution des chaînes du chrome et des formulaires
 * (lot C2, CDC v1.2 §3.2 I18N-1).
 *
 * Absorption du mécanisme historique du thème (class-localization-strings.php,
 * lot B6 découpe REG-3) : le filtre gettext translate_polylang_string et les
 * deux dictionnaires de repli (chrome 136 entrées, form 140 entrées) deviennent
 * propriété du plugin. La chaîne de résolution est portée VERBATIM — l'ordre
 * .mo canonique > form_translations > chrome_translations > polylang (chaînes
 * enregistrées) est figé par le test REG-3 du lot B6 et par le corpus gelé du
 * lot C (empreinte de service d71b57fa…, diff nul exigé au lot C2).
 *
 * EXTINCTION (CDC §3.2, extinction progressive documentée) : quand ce service
 * est chargé, le filtre gettext du service — enregistré par le bootstrap du
 * plugin (plugins_loaded) — est LE mécanisme actif ; le thème 6.18.9+ cesse
 * d'enregistrer son propre filtre et fournit son registre chrome au service
 * (provide_registry). Sans le plugin, le repli local historique du thème
 * reste actif à l'identique (dégradation gracieuse REG-5). Le retrait physique
 * des copies dormantes du thème relève du lot C4 (extinction finale).
 *
 * Registre chrome : donnée du THÈME actif (clés du shell public + valeurs par
 * défaut des réglages Partikulier_Settings) — fournie par inversion de
 * dépendance (callable), jamais par dépendance du plugin vers une classe du
 * thème. Sans fournisseur, le pas de résolution Polylang est sauté : la
 * sortie est identique à la chaîne historique sans chaîne enregistrée.
 *
 * Facade SANS AUCUN hook au chargement (le filtre gettext est enregistré par
 * le bootstrap du plugin, méthode register_gettext_filter) ; AUCUNE table,
 * AUCUNE écriture. Le schéma reste 2.6.0 (aucune migration au lot C2).
 */
declare(strict_types=1);

namespace Partikulier\Core\Domain\I18n;

final class I18nChromeService
{
    private const DOMAIN = 'partikulier';

    /** @var callable|null */
    private static $registry_provider = null;

    /** @var array<string,string>|null */
    private static $registry_cache = null;

    /**
     * Fournit le registre des chaînes du chrome (donnée du thème actif :
     * clés du shell public + valeurs par défaut des réglages). Appelé par
     * l'init du thème 6.18.9+ quand le service existe.
     */
    public static function provide_registry(callable $provider): void
    {
        self::$registry_provider = $provider;
        self::$registry_cache = null;
    }

    /**
     * Registre fourni par le thème (vide sans fournisseur — le pas de
     * résolution Polylang est alors sauté).
     *
     * @return array<string,string>
     */
    public static function registry(): array
    {
        if (self::$registry_cache === null) {
            $strings = self::$registry_provider !== null
                ? (self::$registry_provider)()
                : [];
            self::$registry_cache = \is_array($strings) ? $strings : [];
        }
        return self::$registry_cache;
    }

    /**
     * Dictionnaire de repli du chrome public (port VERBATIM, 136 entrées).
     *
     * @return array<string,array<string,string>>
     */
    public static function chrome_translations(): array
    {
        return ChromeDictionary::translations();
    }

    /**
     * Dictionnaire de repli du formulaire de dépôt (port VERBATIM, 140 entrées).
     *
     * @return array<string,array<string,string>>
     */
    public static function form_translations(): array
    {
        return FormsDictionary::translations();
    }

    /**
     * Langue publique courante (port VERBATIM du comportement thème :
     * slug Polylang parmi fr/ar/en, sinon français).
     */
    public static function current_language(): string
    {
        if (\function_exists('pll_current_language')) {
            $language = \pll_current_language('slug');
            if (\in_array($language, ['fr', 'ar', 'en'], true)) {
                return (string) $language;
            }
        }
        return 'fr';
    }

    /**
     * Résolution unifiée d'une chaîne gettext du domaine « partikulier »
     * (port VERBATIM de translate_polylang_string — chaîne figée REG-3) :
     *   1. domaine « partikulier » uniquement, les autres passent ;
     *   2. une traduction gettext provenant d'un fichier .mo est canonique —
     *      les dictionnaires internes ne servent qu'en repli ;
     *   3. repli form_translations puis chrome_translations (langue courante,
     *      valeur d'origine si la langue manque) ;
     *   4. polylang pour les chaînes explicitement enregistrées (registre
     *      fourni par le thème), sinon la valeur passée au filtre.
     */
    public static function translate(string $translation, string $text, string $domain): string
    {
        if (self::DOMAIN !== $domain) {
            return $translation;
        }
        if ($translation !== $text && '' !== $translation) {
            return $translation;
        }

        $form_translations = self::form_translations();
        if (isset($form_translations[$text])) {
            $language = self::current_language();
            return isset($form_translations[$text][$language]) ? $form_translations[$text][$language] : $text;
        }

        $chrome_translations = self::chrome_translations();
        if (isset($chrome_translations[$text])) {
            $language = self::current_language();
            return isset($chrome_translations[$text][$language]) ? $chrome_translations[$text][$language] : $text;
        }

        if (!\function_exists('pll__') || !\in_array($text, self::registry(), true)) {
            return $translation;
        }
        return self::translate_public_string($text);
    }

    /**
     * Traduit une chaîne publique explicitement enregistrée (port VERBATIM) :
     * protège les textes libres et les réglages personnalisés — toute chaîne
     * non préparée conserve sa valeur d'origine.
     */
    public static function translate_public_string(string $string): string
    {
        if (!\function_exists('pll__') || !\in_array($string, self::registry(), true)) {
            return $string;
        }
        return \pll__($string);
    }

    /**
     * Enregistre le filtre gettext du service unifié — LE mécanisme de
     * résolution du domaine « partikulier » (lot C2). Appelé uniquement par
     * le bootstrap du plugin (plugins_loaded) : le domaine ne s'auto-enregistre
     * jamais au chargement.
     */
    public static function register_gettext_filter(): void
    {
        \add_filter('gettext', [self::class, 'translate'], 10, 3);
    }
}
