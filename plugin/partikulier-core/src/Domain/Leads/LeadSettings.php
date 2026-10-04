<?php
/**
 * Réglages éditables des messages WhatsApp et des plafonds R2 (2.10.15).
 *
 * WP reste source de vérité : n8n lit {{ $json.question }} / {{ $json.message }}
 * renvoyés par authorize_contact, qui lit ces options. Toute modification en
 * WP est donc immédiatement visible côté WhatsApp sans redéployer n8n.
 *
 * Option unique `pk_lead_settings` :
 *  - messages[need_qualification|intermediary_refused|manual_review][fr|ar|en]
 *  - prefill[fr|ar|en]  (message pré-rempli du bouton annonce)
 *  - limits[max_24h, window_24h, max_7d, window_7d, daily_limit]
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Leads;

final class LeadSettings
{
    public const OPTION = 'pk_lead_settings';

    /**
     * @return array<string,mixed>
     */
    public static function defaults(): array
    {
        return [
            'messages' => [
                'need_qualification' => [
                    'fr' => 'Vous êtes particulier ou intermédiaire ?',
                    'ar' => 'هل أنت particulier أم وسيط؟',
                    'en' => 'Are you a private individual or an agent?',
                ],
                'intermediary_refused' => [
                    'fr' => 'Le propriétaire refuse les intermédiaires. Merci de votre compréhension.',
                    'ar' => 'المالك يرفض الوسطاء. شكرا لتفهمكم.',
                    'en' => 'The owner declines intermediaries. Thank you for your understanding.',
                ],
                'manual_review' => [
                    'fr' => 'Pour des raisons de sécurité, l’envoi du contact se fera manuellement. Merci de votre patience.',
                    'ar' => 'لأسباب أمنية، سيتم إرسال جهة الاتصال يدويا. شكرا لصبركم.',
                    'en' => 'For security reasons, the contact will be sent manually. Thank you for your patience.',
                ],
            ],
            'prefill' => [
                'fr' => 'Bonjour Partikulier, je suis intéressé(e) par l’annonce {reference}.' . "\n" . 'Lien : {lien}',
                'ar' => 'مرحبا Partikulier، أنا مهتم بالإعلان {reference}.' . "\n" . 'الرابط : {lien}',
                'en' => 'Hello Partikulier, I am interested in listing {reference}.' . "\n" . 'Link: {lien}',
            ],
            'limits' => [
                'max_24h'     => 2,   // 3e en 24h → manuel
                'window_24h'  => 24,  // heures
                'max_7d'      => 5,   // 6e en 7j → manuel
                'window_7d'   => 7,   // jours
                'daily_limit' => 2,   // legacy : 2 proprios distincts / jour calendaire
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function all(): array
    {
        $opt = get_option(self::OPTION, []);
        if (!is_array($opt)) $opt = [];
        $def = self::defaults();
        // deep merge
        $out = $def;
        foreach (['messages','prefill','limits'] as $k) {
            if (isset($opt[$k]) && is_array($opt[$k])) {
                foreach ($opt[$k] as $sk => $sv) {
                    if (is_array($sv) && isset($out[$k][$sk]) && is_array($out[$k][$sk])) {
                        $out[$k][$sk] = array_merge($out[$k][$sk], $sv);
                    } else {
                        $out[$k][$sk] = $sv;
                    }
                }
                // For limits, merge flat
                if ($k === 'limits') {
                    $out[$k] = array_merge($def['limits'], (array) $opt[$k]);
                }
            }
        }
        return $out;
    }

    public static function get_message(string $key, string $lang): string
    {
        $all = self::all();
        $lang = in_array($lang, ['fr','ar','en'], true) ? $lang : 'fr';
        $msg = $all['messages'][$key][$lang] ?? '';
        if ('' !== trim((string) $msg)) return (string) $msg;
        // fallback fr
        return (string) ($all['messages'][$key]['fr'] ?? self::defaults()['messages'][$key]['fr'] ?? '');
    }

    public static function get_prefill(string $lang): string
    {
        $all = self::all();
        $lang = in_array($lang, ['fr','ar','en'], true) ? $lang : 'fr';
        $msg = $all['prefill'][$lang] ?? '';
        if ('' !== trim((string) $msg)) return (string) $msg;
        return (string) (self::defaults()['prefill'][$lang] ?? self::defaults()['prefill']['fr']);
    }

    public static function get_limit(string $key): int
    {
        $all = self::all();
        $v = (int) ($all['limits'][$key] ?? self::defaults()['limits'][$key] ?? 0);
        return max(0, $v);
    }

    /**
     * Rendu du prefill avec balises {reference} {lien} {titre} {ville} {prix} {quartier}
     */
    public static function render_prefill(int $post_id, string $lang): string
    {
        $tpl = self::get_prefill($lang);
        $reference = '';
        if (class_exists(LeadService::class) && method_exists(LeadService::class, 'reference_for')) {
            $reference = (string) LeadService::reference_for($post_id);
        } else {
            $reference = (string) get_post_meta($post_id, '_pk_buyer_reference', true);
            if ('' === $reference) $reference = 'PK-' . $post_id;
        }
        $replacements = [
            '{reference}' => $reference,
            '{lien}'      => (string) get_permalink($post_id),
            '{titre}'     => (string) get_the_title($post_id),
            '{ville}'     => (string) get_post_meta($post_id, '_pk_ville', true),
            '{quartier}'  => (string) get_post_meta($post_id, '_pk_quartier', true),
            '{prix}'      => (string) get_post_meta($post_id, 'es_property_price', true),
        ];
        return strtr($tpl, $replacements);
    }

    /* ------------------------------------------------------------------ */
    /* Admin UI                                                           */
    /* ------------------------------------------------------------------ */

    public static function init(): void
    {
        add_action('admin_menu', [self::class, 'register_menu']);
        add_action('admin_init', [self::class, 'register_settings']);
    }

    public static function register_menu(): void
    {
        // Sous-menu de "Leads WhatsApp" si existe, sinon sous "Réglages"
        $parent = 'pk-whatsapp-leads';
        // On tente d'ajouter en sous-menu de pk-whatsapp-leads, sinon settings
        add_submenu_page(
            $parent,
            __('Messages & Limites', 'partikulier-core'),
            __('Messages & Limites', 'partikulier-core'),
            'manage_options',
            'pk-lead-settings',
            [self::class, 'render_page']
        );
        // Fallback si parent n'existe pas (ex: thème désactivé) → Réglages
        add_options_page(
            __('Partikulier — Messages & Limites', 'partikulier-core'),
            __('Partikulier Leads', 'partikulier-core'),
            'manage_options',
            'pk-lead-settings-fallback',
            [self::class, 'render_page']
        );
    }

    public static function register_settings(): void
    {
        register_setting('pk_lead_settings_group', self::OPTION, [
            'type' => 'array',
            'sanitize_callback' => [self::class, 'sanitize'],
            'default' => self::defaults(),
        ]);
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public static function sanitize($input): array
    {
        if (!is_array($input)) return self::all();
        $out = self::defaults();
        // messages
        foreach (['need_qualification','intermediary_refused','manual_review'] as $k) {
            foreach (['fr','ar','en'] as $lang) {
                if (isset($input['messages'][$k][$lang])) {
                    $out['messages'][$k][$lang] = sanitize_textarea_field((string) $input['messages'][$k][$lang]);
                }
            }
        }
        foreach (['fr','ar','en'] as $lang) {
            if (isset($input['prefill'][$lang])) {
                $out['prefill'][$lang] = sanitize_textarea_field((string) $input['prefill'][$lang]);
            }
        }
        foreach (['max_24h','window_24h','max_7d','window_7d','daily_limit'] as $k) {
            if (isset($input['limits'][$k])) {
                $out['limits'][$k] = max(0, (int) $input['limits'][$k]);
            }
        }
        // garde-fou : max_24h au moins 1, window au moins 1
        $out['limits']['max_24h'] = max(1, $out['limits']['max_24h']);
        $out['limits']['window_24h'] = max(1, $out['limits']['window_24h']);
        $out['limits']['max_7d'] = max(1, $out['limits']['max_7d']);
        $out['limits']['window_7d'] = max(1, $out['limits']['window_7d']);
        return $out;
    }

    public static function render_page(): void
    {
        if (!current_user_can('manage_options')) wp_die('Accès non autorisé');
        $opt = self::all();
        // Sauvé ?
        if (isset($_GET['settings-updated'])) {
            echo '<div class="notice notice-success is-dismissible"><p>Réglages enregistrés — n8n lira les nouveaux messages au prochain webhook (source WP).</p></div>';
        }
        ?>
        <div class="wrap" style="max-width:1100px">
            <h1>Partikulier — Messages & Limites (R2)</h1>
            <p class="description" style="max-width:850px">WP reste source de vérité : <code>GET /export/interests</code> garde le bon <code>reason</code>, et <code>n8n</code> envoie <code>{{ $json.question }}</code> / <code>{{ $json.message }}</code> renvoyés par <code>POST /contact-authorization</code>. Modifiez ici les textes FR/AR/EN et les plafonds — effet immédiat sans redéployer n8n. Le message pré-rempli supporte <code>{reference}</code> <code>{lien}</code> <code>{titre}</code> <code>{ville}</code> <code>{quartier}</code> <code>{prix}</code> : la référence <code>PK-...</code> est reconnue par n8n/WP via regex.</p>
            <form method="post" action="options.php">
                <?php settings_fields('pk_lead_settings_group'); ?>
                <h2>1. Messages WhatsApp (3 langues)</h2>
                <p class="description">Laissez vide pour utiliser le défaut. Le choix FR/AR/EN se fait automatiquement : si le texte du message entrant contient de l'arabe (<code>[\x{0600}-\x{06FF}]</code>) → AR, sinon langue du bien (Polylang > _locale > FR).</p>
                <?php foreach (['need_qualification'=>'Question 1er contact (need_qualification)','intermediary_refused'=>'Refus intermédiaire (intermediary_refused)','manual_review'=>'Manuel sécurité (manual_review)'] as $key=>$label): ?>
                    <h3><?php echo esc_html($label); ?> — <code><?php echo esc_html($key); ?></code></h3>
                    <table class="form-table" role="presentation">
                        <?php foreach (['fr'=>'Français','ar'=>'العربية','en'=>'English'] as $lang=>$langLabel): ?>
                            <tr>
                                <th scope="row"><label for="pk_msg_<?php echo esc_attr($key); ?>_<?php echo esc_attr($lang); ?>"><?php echo esc_html($langLabel); ?> (<?php echo esc_html($lang); ?>)</label></th>
                                <td><textarea id="pk_msg_<?php echo esc_attr($key); ?>_<?php echo esc_attr($lang); ?>" name="<?php echo esc_attr(self::OPTION); ?>[messages][<?php echo esc_attr($key); ?>][<?php echo esc_attr($lang); ?>]" rows="2" style="width:100%;max-width:700px"><?php echo esc_textarea($opt['messages'][$key][$lang] ?? ''); ?></textarea></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                <?php endforeach; ?>

                <h2>2. Message pré-rempli du bouton annonce</h2>
                <p class="description">Texte du lien <code>https://wa.me/212...?text=...</code> sur la fiche bien. Doit contenir <code>{reference}</code> et <code>{lien}</code> pour que n8n/WP reconnaissent l'annonce.</p>
                <table class="form-table" role="presentation">
                    <?php foreach (['fr'=>'Français','ar'=>'العربية','en'=>'English'] as $lang=>$langLabel): ?>
                        <tr>
                            <th scope="row"><label for="pk_prefill_<?php echo esc_attr($lang); ?>"><?php echo esc_html($langLabel); ?> (<?php echo esc_attr($lang); ?>)</label></th>
                            <td><textarea id="pk_prefill_<?php echo esc_attr($lang); ?>" name="<?php echo esc_attr(self::OPTION); ?>[prefill][<?php echo esc_attr($lang); ?>]" rows="3" style="width:100%;max-width:700px"><?php echo esc_textarea($opt['prefill'][$lang] ?? ''); ?></textarea>
                                <p class="description">Exemple FR : <code>Bonjour Partikulier, je suis intéressé(e) par l’annonce {reference}. Lien : {lien}</code></p></td>
                        </tr>
                    <?php endforeach; ?>
                </table>

                <h2>3. Plafonds — 3e en 24h &amp; max 5 / 7j (tous types : villa/appart, achat/location)</h2>
                <p class="description">Comptage sur <code>pk_interest_events</code> par <code>lead_id</code>. Tous types confondus. <code>_manual_24h</code> et <code>_manual_7d</code> sont tracés pour <code>GET /export/interests</code> (<code>raison_si_non</code> + <code>restreint</code>).</p>
                <table class="form-table" role="presentation">
                    <tr><th scope="row"><label for="pk_max_24h">Max contacts en 24h (avant manuel)</label></th><td><input id="pk_max_24h" type="number" min="1" name="<?php echo esc_attr(self::OPTION); ?>[limits][max_24h]" value="<?php echo esc_attr((string) $opt['limits']['max_24h']); ?>" style="width:100px"> → <span class="description">défaut <code>2</code> = 3e en 24h → manuel. Mettez <code>3</code> pour 4e → manuel.</span></td></tr>
                    <tr><th scope="row"><label for="pk_win_24h">Fenêtre 24h (heures)</label></th><td><input id="pk_win_24h" type="number" min="1" name="<?php echo esc_attr(self::OPTION); ?>[limits][window_24h]" value="<?php echo esc_attr((string) $opt['limits']['window_24h']); ?>" style="width:100px"> heures</td></tr>
                    <tr><th scope="row"><label for="pk_max_7d">Max contacts auto en 7j (avant manuel)</label></th><td><input id="pk_max_7d" type="number" min="1" name="<?php echo esc_attr(self::OPTION); ?>[limits][max_7d]" value="<?php echo esc_attr((string) $opt['limits']['max_7d']); ?>" style="width:100px"> → <span class="description">défaut <code>5</code> = 6e en 7j → manuel (max 5/semaine)</span></td></tr>
                    <tr><th scope="row"><label for="pk_win_7d">Fenêtre 7j (jours)</label></th><td><input id="pk_win_7d" type="number" min="1" name="<?php echo esc_attr(self::OPTION); ?>[limits][window_7d]" value="<?php echo esc_attr((string) $opt['limits']['window_7d']); ?>" style="width:100px"> jours</td></tr>
                    <tr><th scope="row"><label for="pk_daily">Plafond legacy / jour calendaire (proprios distincts)</label></th><td><input id="pk_daily" type="number" min="1" name="<?php echo esc_attr(self::OPTION); ?>[limits][daily_limit]" value="<?php echo esc_attr((string) $opt['limits']['daily_limit']); ?>" style="width:100px"> → <span class="description">défaut <code>2</code> proprios distincts / jour (conserve). 2 annonces même proprio = 1 contact.</span></td></tr>
                </table>

                <h2>4. Recherche DATA (confirmé)</h2>
                <p class="description" style="max-width:850px">Toutes les annonces sont de la <strong>DATA</strong> (pas du texte) : <code>ville</code> <code>quartier</code> <code>type</code> <code>surface</code> <code>etage</code> <code>ensoleillement</code> <code>chambres/salons</code> via <code>_pk_*</code> + <code>es_*</code> + <code>property_snapshot</code>. Le moteur <code>RecommendationService</code> et <code>SheetsExportService</code> les exploitent. Vous pouvez filtrer/rechercher via : <code>?ville=Casablanca&quartier=Anfa&type=Appartement&etage=2&ensoleillement=sud</code> ou via <code>GET /wp-json/partikulier/v1/listings?quartier=Anfa&ensoleillement=sud</code>. Aucun changement nécessaire.</p>

                <?php submit_button('Enregistrer — effet immédiat'); ?>
            </form>
            <hr>
            <h3>Aperçu n8n</h3>
            <p class="description">Dans <code>n8n-workflow-whatsapp-R2</code> gardez <code>Send WhatsApp: {{ $json.question }}</code> et <code>{{ $json.message }}</code> — WP envoie la version FR/AR/EN déjà traduite. Si vous mettez un texte fixe dans n8n, il écrasera WP.</p>
        </div>
        <?php
    }
}
