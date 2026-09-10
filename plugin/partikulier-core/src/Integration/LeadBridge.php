<?php
/**
 * Pont de la route POST /leads vers le dispositif de leads unique (INTEG-2, lot A).
 *
 * Le stockage par commentaire WordPress est éteint. Depuis le lot B2, le
 * dispositif complet (huit tables) est propriété du plugin : le pont appelle
 * directement \Partikulier\Core\Domain\Leads\LeadService — mêmes contrôles,
 * même plafonnement, même journalisation. La délégation au thème
 * (Partikulier_Buyer_Qualification::register_api_lead) reste en repli pour
 * les combinaisons de versions croisés (thème récent + plugin ancien) ; si
 * aucun dispositif n'est disponible, la route répond 501 — jamais de repli
 * sur une seconde zone de stockage.
 *
 * Corps accepté : les champs du parcours du site — téléphone (coordonnée),
 * annonce visée (property_id ou référence PK-xxx-XXXX, rattachement
 * obligatoire), message facultatif, nom et courriel facultatifs (conservés
 * avec le suivi du lead). Le plafonnement quotidien et la journalisation sont
 * ceux du dispositif, sans distinction du canal d'entrée.
 */

declare(strict_types=1);

namespace Partikulier\Core\Integration;

use WP_Error;

final class LeadBridge
{
    private const THEME_DEVICE = 'Partikulier_Buyer_Qualification';

    /**
     * @return array|WP_Error tableau {lead_id, property_id, reference, contact} ou WP_Error
     */
    public function create(array $input): array|WP_Error
    {
        $phone = preg_replace('/\D+/', '', (string) ($input['phone'] ?? ''));
        $message = trim((string) ($input['message'] ?? ''));
        $propertyId = (int) ($input['property_id'] ?? 0);
        $reference = trim((string) ($input['reference'] ?? ''));
        $email = sanitize_email((string) ($input['email'] ?? ''));
        $name = sanitize_text_field((string) ($input['name'] ?? ''));

        if ($phone === '' || strlen($phone) < 8 || strlen($phone) > 15) {
            return new WP_Error('invalid_lead_phone', __('Numéro de téléphone invalide.', 'partikulier-core'), ['status' => 422]);
        }
        if ($propertyId < 1 && $reference === '') {
            return new WP_Error('missing_lead_property', __('Rattachement obligatoire : property_id ou reference requis.', 'partikulier-core'), ['status' => 422]);
        }
        if (mb_strlen($message) > 5000) {
            return new WP_Error('invalid_lead_message', __('Message trop long.', 'partikulier-core'), ['status' => 422]);
        }
        if ($email !== '' && ! is_email($email)) {
            return new WP_Error('invalid_lead_email', __('Courriel invalide.', 'partikulier-core'), ['status' => 422]);
        }
        if ($name !== '' && mb_strlen($name) > 120) {
            return new WP_Error('invalid_lead_name', __('Nom trop long.', 'partikulier-core'), ['status' => 422]);
        }

        $payload = [
            'phone' => $phone,
            'property_id' => $propertyId,
            'reference' => $reference,
            'message' => $message,
            'email' => $email,
            'name' => $name,
        ];

        // Lot B2 : le dispositif de leads vit dans le plugin — appel direct.
        if (class_exists(\Partikulier\Core\Domain\Leads\LeadService::class)) {
            return \Partikulier\Core\Domain\Leads\LeadService::register_api_lead($payload);
        }

        // Repli transitoire : thème récent + plugin ancien (matrice REG-5, combo C).
        if (! class_exists(self::THEME_DEVICE) || ! method_exists(self::THEME_DEVICE, 'register_api_lead')) {
            return new WP_Error(
                'lead_device_unavailable',
                __('Le dispositif de leads est indisponible : activez le thème Partikulier.', 'partikulier-core'),
                ['status' => 501]
            );
        }
        return call_user_func([self::THEME_DEVICE, 'register_api_lead'], $payload);
    }

    /**
     * Migration journalisée des leads historiques stockés en commentaires
     * partikulier_lead (extinction du double stockage). Les enregistrements
     * historiques ne portent ni téléphone ni rattachement d'annonce : ils ne
     * peuvent pas entrer dans le dispositif cible sans inventer des données.
     * Chaque commentaire est donc marqué migré (méta-commentaire) et journalisé
     * au registre d'audit, sans suppression — leur destruction relève d'une
     * décision explicite du commanditaire (protocole de données, chapitre 4).
     * Idempotent : rejoué, la migration ne retraite aucun commentaire marqué.
     *
     * @return array{total: int, marked: int, already: int}
     */
    public function migrateLegacyComments(): array
    {
        global $wpdb;
        $report = ['total' => 0, 'marked' => 0, 'already' => 0];
        // Interrogation directe de la table : WP_Comment_Query joint les
        // relations de termes de Polylang (filtre par langue), qui rendent les
        // commentaires orphelins — post_ID = 0, comme tous les leads
        // historiques — invisibles à get_comments. Constat mesuré sur le banc.
        $commentIds = (array) $wpdb->get_col(
            $wpdb->prepare("SELECT comment_ID FROM {$wpdb->comments} WHERE comment_type = %s ORDER BY comment_ID ASC LIMIT 5000", 'partikulier_lead')
        );
        $report['total'] = count($commentIds);
        $audit = new \Partikulier\Core\AuditLogger();
        foreach ($commentIds as $commentId) {
            $commentId = (int) $commentId;
            if ((string) get_comment_meta($commentId, '_pk_lead_migrated', true) !== '') {
                $report['already']++;
                continue;
            }
            update_comment_meta($commentId, '_pk_lead_migrated', gmdate('c'));
            $audit->record('lead_comment_migration', 'comment', $commentId, [
                'migrated' => false,
                'reason' => 'missing_phone_and_property',
                'storage' => 'extinguished',
            ]);
            $report['marked']++;
        }
        return $report;
    }
}
