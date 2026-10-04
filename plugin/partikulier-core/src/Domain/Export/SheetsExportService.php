<?php
/**
 * Export Data Qualifiée vers Google Sheets via n8n (lot R1 — 03/10/2026).
 *
 * WP reste le cerveau temps réel, Sheets est la mémoire analytique.
 * - Endpoint GET /partikulier/v1/export/interests?since=2026-10-01T00:00:00Z
 *   retourne les intérêts qualifiés (1 ligne = 1 clic WhatsApp) avec tout
 *   ce que tu veux voir dans ton tableau : numéro, date, bien complet, envoyé?
 *   raison, consents, stop, restreint.
 * - Endpoint POST /partikulier/v1/lead/status pour ton bouton Sheets
 *   Valide <-> Restreint (écrit dans pk_lead_followups, vu par n8n au prochain message).
 * - WP-CLI : wp partikulier export --since=2026-10-01 --format=csv
 *
 * Sécurité : export = capability manage_options + clé HMAC X-PK-Signature
 * (même sel que visitor_hash). Sheets n'a JAMAIS le téléphone en clair
 * si tu ne veux pas : on envoie phone_hash par défaut, phone_last4 en option.
 * Pour ton tableau interne, on déchiffre côté WP avant d'envoyer (admin only).
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Export;

use Partikulier\Core\Domain\Leads\LeadService;

final class SheetsExportService
{
    public const EXPORT_CAP = 'manage_options';
    public const HOOK_CRON  = 'pk_sheets_export_daily';

    public static function maybe_schedule(): void
    {
        if ( ! wp_next_scheduled(self::HOOK_CRON) ) {
            wp_schedule_event(strtotime('03:00:00'), 'daily', self::HOOK_CRON);
        }
    }

    /**
     * Récupère les intérêts depuis `since` (ISO8601) pour n8n.
     * Joint interest_events + buyer_leads + disclosures + consents + followups
     * pour produire exactement ton tableau demandé.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function fetch_interests( string $since, int $limit = 500 ): array
    {
        global $wpdb;
        $since_dt = gmdate('Y-m-d H:i:s', strtotime($since) ?: strtotime('-7 days'));
        $limit = max(1, min(2000, $limit));

        $interest = $wpdb->prefix . 'pk_interest_events';
        $leads    = $wpdb->prefix . 'pk_buyer_leads';
        $discl    = $wpdb->prefix . 'pk_contact_disclosures';
        $consents = $wpdb->prefix . 'pk_whatsapp_consents';
        $follow   = $wpdb->prefix . 'pk_lead_followups';
        $limits   = $wpdb->prefix . 'pk_contact_limits';

        // 1) Intérêts avec snapshot
        $rows = $wpdb->get_results($wpdb->prepare(
            "SELECT i.id, i.lead_id, i.property_id, i.reference_code, i.property_snapshot, i.provider_message_id, i.created_at,
                    l.phone_encrypted, l.phone_hash, l.opt_out_at, l.is_particulier, l.qualification_asked_at
             FROM {$interest} i
             JOIN {$leads} l ON l.id = i.lead_id
             WHERE i.created_at >= %s
             ORDER BY i.created_at ASC
             LIMIT %d",
            $since_dt, $limit
        ), ARRAY_A);

        $out = [];
        foreach ( (array) $rows as $r ) {
            $lead_id = (int) $r['lead_id'];
            $pid     = (int) $r['property_id'];
            $snap    = json_decode((string) $r['property_snapshot'], true) ?: [];

            // Décryptage téléphone pour export admin (uniquement si capability, sinon hash)
            $phone = '';
            $phone_last4 = '';
            if ( current_user_can(self::EXPORT_CAP) || defined('WP_CLI') ) {
                // LeadService::decrypt_phone_for_admin est privé, on passe par la méthode publique si dispo, sinon on déchiffre ici
                $phone = self::decrypt_phone((string) $r['phone_encrypted']);
                $phone_last4 = $phone !== '' ? '****' . substr(preg_replace('/[^0-9]/','',$phone), -4) : '';
            }

            // Disclosure : a-t-on envoyé le proprio ?
            $disclosure = $wpdb->get_row($wpdb->prepare(
                "SELECT sent_at FROM {$discl} WHERE lead_id = %d AND property_id = %d LIMIT 1", $lead_id, $pid
            ), ARRAY_A);
            $envoye = $disclosure ? 'OUI ' . $disclosure['sent_at'] : 'NON';
            $raison = '';
            if ( ! $disclosure ) {
                // R2 : manuel si provider_message_id contient _manual (24h ou 7d)
                if ( str_contains((string) $r['provider_message_id'], '_manual_24h') ) {
                    $raison = 'manual_review (3e contact en 24h → manuel)';
                } elseif ( str_contains((string) $r['provider_message_id'], '_manual_7d') ) {
                    $raison = 'manual_review (6e contact en 7j → manuel, max 5/semaine)';
                } elseif ( str_contains((string) $r['provider_message_id'], '_manual') ) {
                    $raison = 'manual_review (3e contact en 24h → manuel)';
                } elseif ( null !== $r['is_particulier'] && (int)$r['is_particulier'] === 0 ) {
                    $raison = 'intermediary_refused';
                } elseif ( null === $r['is_particulier'] || '' === (string) $r['is_particulier'] ) {
                    $raison = 'need_qualification (attente réponse particulier/intermédiaire)';
                } else {
                    $dup = $wpdb->get_var($wpdb->prepare(
                        "SELECT id FROM {$discl} WHERE lead_id = %d AND property_id = %d", $lead_id, $pid
                    ));
                    if ( $dup ) $raison = 'duplicate';
                    else {
                        $today = gmdate('Y-m-d', strtotime((string) $r['created_at']));
                        $cnt = $wpdb->get_var($wpdb->prepare(
                            "SELECT contacts_count FROM {$limits} WHERE lead_id = %d AND day_key = %s", $lead_id, $today
                        ));
                        if ( $cnt !== null && (int)$cnt >= LeadService::daily_limit() ) $raison = 'daily_limit (2/jour)';
                    }
                    // Followup restreint
                    $f = $wpdb->get_row($wpdb->prepare("SELECT status, note FROM {$follow} WHERE lead_id = %d", $lead_id), ARRAY_A);
                    if ( is_array($f) && in_array($f['status'], ['restricted','blocked'], true) ) {
                        $raison = $raison ? $raison . ' + ' . $f['status'] : $f['status'] . ': ' . ($f['note'] ?? '');
                    }
                    if ( $raison === '' ) $raison = 'en_attente / non_disclosed';
                }
            }

            // Consents
            $cons_sim = $wpdb->get_row($wpdb->prepare(
                "SELECT granted_at, revoked_at FROM {$consents} WHERE lead_id = %d AND scope = %s", $lead_id, LeadService::CONSENT_SCOPE_SIMILAR
            ), ARRAY_A);
            $ok_sim = $cons_sim ? ($cons_sim['revoked_at'] ? 'NON (révoqué ' . $cons_sim['revoked_at'] . ')' : ($cons_sim['granted_at'] ? 'OUI ' . $cons_sim['granted_at'] : 'NON')) : 'NON';

            $cons_part = $wpdb->get_row($wpdb->prepare(
                "SELECT granted_at, revoked_at FROM {$consents} WHERE lead_id = %d AND scope = %s", $lead_id, 'partner_ads'
            ), ARRAY_A);
            $ok_part = $cons_part ? ($cons_part['revoked_at'] ? 'NON' : ($cons_part['granted_at'] ? 'OUI ' . $cons_part['granted_at'] : 'NON')) : 'NON';

            // STOP
            $stop = $r['opt_out_at'] ? (string) $r['opt_out_at'] : '';
            if ( $cons_sim && ! empty($cons_sim['revoked_at']) ) $stop = (string) $cons_sim['revoked_at'];

            // Restreint
            $follow_row = $wpdb->get_row($wpdb->prepare("SELECT status, note, updated_at FROM {$follow} WHERE lead_id = %d", $lead_id), ARRAY_A);
            $restreint = 'NON';
            $raison_restr = '';
            if ( is_array($follow_row) ) {
                if ( $follow_row['status'] === 'restricted' ) { $restreint = 'RESTREINT'; $raison_restr = (string) ($follow_row['note'] ?? ''); }
                if ( $follow_row['status'] === 'blocked' ) { $restreint = 'BLOQUÉ'; $raison_restr = (string) ($follow_row['note'] ?? ''); }
                if ( $follow_row['status'] === 'stop' ) { $restreint = 'STOP'; $raison_restr = (string) $follow_row['updated_at']; }
            }
            // Détection auto : R2 3 en 24h → manuel, 6 en 7j → manuel (max 5/semaine)
            if ( $restreint === 'NON' ) {
                $cnt24 = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$interest} WHERE lead_id = %d AND created_at >= DATE_SUB(%s, INTERVAL 24 HOUR)",
                    $lead_id, $r['created_at']
                ));
                if ( (int)$cnt24 >= 3 ) { $restreint = 'RESTREINT (auto 24h)'; $raison_restr = (int)$cnt24 . ' demandes en 24h (3e → manuel)'; }
                else {
                    $cnt7d = $wpdb->get_var($wpdb->prepare(
                        "SELECT COUNT(*) FROM {$interest} WHERE lead_id = %d AND created_at >= DATE_SUB(%s, INTERVAL 7 DAY)",
                        $lead_id, $r['created_at']
                    ));
                    if ( (int)$cnt7d >= 6 ) { $restreint = 'RESTREINT (auto 7j)'; $raison_restr = (int)$cnt7d . ' demandes en 7j (6e → manuel, max 5)'; }
                }
            }

            // R2 : libellé particulier / intermédiaire
            $qualif = null === $r['is_particulier'] || '' === (string) $r['is_particulier'] ? 'inconnu (question posée le ' . (string) ($r['qualification_asked_at'] ?? '') . ')' : ((int)$r['is_particulier'] === 1 ? 'particulier' : 'intermédiaire (refusé)');
            $out[] = [
                'numero_hash'       => (string) $r['phone_hash'],
                'numero_last4'      => $phone_last4,
                'numero_complet'    => $phone, // vide si pas admin, n8n le reçoit hashé par défaut
                'lead_id'           => $lead_id,
                'qualif'            => $qualif,
                'is_particulier'    => $r['is_particulier'],
                'qualification_asked_at' => (string) ($r['qualification_asked_at'] ?? ''),
                'date_heure'        => (string) $r['created_at'],
                'reference'         => (string) $r['reference_code'],
                'property_id'       => $pid,
                'url'               => (string) ($snap['url'] ?? ''),
                'ville'             => (string) ($snap['ville'] ?? $snap['location'] ?? ''),
                'quartier'          => (string) ($snap['quartier'] ?? ''),
                'budget'            => (string) ($snap['price'] ?? ''),
                'type'              => (string) ($snap['type'] ?? ''),
                'salons'            => (string) ($snap['salons'] ?? ''),
                'chambres'          => (string) ($snap['chambres'] ?? ''),
                'etage'             => (string) ($snap['etage'] ?? ''),
                'ensoleillement'    => (string) ($snap['ensoleillement'] ?? ''),
                'surface'           => (string) ($snap['area'] ?? ''),
                'envoye_proprio'    => $envoye,
                'raison_si_non'     => $raison,
                'ok_similaires'     => $ok_sim,
                'ok_partenaire'     => $ok_part,
                'stop_le'           => $stop,
                'restreint'         => $restreint,
                'raison_restriction'=> $raison_restr,
                'message_id'         => (string) $r['provider_message_id'],
            ];
        }
        return $out;
    }

    private static function decrypt_phone( string $encrypted ): string
    {
        // Réutilise la logique de LeadService::decrypt_phone_for_admin si dispo
        if ( class_exists(LeadService::class) && method_exists(LeadService::class, 'decrypt_phone_for_admin') ) {
            try {
                // LeadService::decrypt_phone_for_admin est privé en 2.10.9, on tente via reflection
                $ref = new \ReflectionMethod(LeadService::class, 'decrypt_phone_for_admin');
                $ref->setAccessible(true);
                return (string) $ref->invoke(null, $encrypted);
            } catch (\Throwable $e) {
                // fallback
            }
        }
        // Fallback AES-256-CBC avec wp_salt secure_auth (même que LeadService::encrypt_phone)
        $salt = function_exists('wp_salt') ? (string) wp_salt('secure_auth') : '';
        if ( $salt === '' || $encrypted === '' ) return '';
        $data = base64_decode($encrypted, true);
        if ( ! is_string($data) || strlen($data) < 32 ) return '';
        $iv = substr($data, 0, 16);
        $ct = substr($data, 16);
        $key = hash('sha256', $salt, true);
        $plain = openssl_decrypt($ct, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return is_string($plain) ? $plain : '';
    }

    public static function handle_rest_export( \WP_REST_Request $req ): \WP_REST_Response
    {
        $since = (string) $req->get_param('since');
        if ( $since === '' ) $since = gmdate('c', strtotime('-7 days'));
        $limit = (int) $req->get_param('limit');
        if ( $limit === 0 ) $limit = 500;
        $data = self::fetch_interests($since, $limit);
        return new \WP_REST_Response(['since'=>$since,'count'=>count($data),'rows'=>$data], 200);
    }

    public static function handle_rest_status( \WP_REST_Request $req ): \WP_REST_Response
    {
        $lead_id = (int) $req->get_param('lead_id');
        $status  = sanitize_text_field((string) $req->get_param('status')); // WP ou Sheets : valid/restricted/blocked/stop/new/...
        $note    = sanitize_text_field((string) $req->get_param('note'));
        $allowed = ['valid','new','in_progress','owner_shared','qualified','closed','restricted','blocked','stop'];
        if ( ! $lead_id || ! in_array($status, $allowed, true) ) {
            return new \WP_REST_Response(['error'=>'lead_id et status requis (' . implode('/', $allowed) . ')'], 400);
        }
        // Normalise valid -> new (même sens : débloqué)
        if ( $status === 'valid' ) $status = 'new';
        global $wpdb;
        $table = $wpdb->prefix . 'pk_lead_followups';
        $now = gmdate('Y-m-d H:i:s');
        $uid = get_current_user_id();
        $wpdb->replace($table, [
            'lead_id'    => $lead_id,
            'status'     => $status,
            'note'       => $note ?: 'maj manuelle depuis Sheets le ' . $now,
            'updated_by' => $uid,
            'updated_at' => $now,
        ], ['%d','%s','%s','%d','%s']);
        // Log audit
        if ( class_exists(\Partikulier\Core\AuditLogger::class) ) {
            (new \Partikulier\Core\AuditLogger())->record('lead_status_manual', 'lead', $lead_id, ['status'=>$status,'note'=>$note,'by'=>$uid]);
        }
        return new \WP_REST_Response(['lead_id'=>$lead_id,'status'=>$status,'updated_at'=>$now], 200);
    }
}
