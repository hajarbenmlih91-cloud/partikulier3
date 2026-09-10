<?php
/**
 * Domaine paiements (lot B1) — commandes et abonnements premium.
 *
 * Port fidèle des fondations du thème 6.17.x : la passerelle publique reste
 * FERMÉE (create_order → WP_Error pk_payment_disabled) tant que le
 * prestataire marocain et les obligations légales ne sont pas validés.
 * Le lot B1 ajoute les primitives internes du domaine — enregistrement,
 * lecture, mise à jour, suppression, transitions d'état (paiement échoué,
 * paiement abouti, abonnement activé puis révoqué) — exigées par le contrat
 * CA-2 du CDC. Aucune route REST ni écran n'est exposé : le périmètre
 * runtime visible est strictement inchangé.
 *
 * Les transitions sont consignées au registre d'audit (pk_audit_log), clé de
 * traçabilité commune aux domaines du plugin.
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Payments;

final class PaymentService
{
    public const POST_TYPE = 'properties';

    // Statuts de commande (fondation : disabled par défaut, gate fermée).
    public const ORDER_DISABLED = 'disabled';
    public const ORDER_PENDING = 'pending';
    public const ORDER_FAILED = 'failed';
    public const ORDER_PAID = 'paid';

    /** @var list<string> */
    public const ORDER_STATUSES = [self::ORDER_DISABLED, self::ORDER_PENDING, self::ORDER_FAILED, self::ORDER_PAID];

    // Statuts d'abonnement premium (fondation : disabled par défaut).
    public const SUBSCRIPTION_DISABLED = 'disabled';
    public const SUBSCRIPTION_PENDING = 'pending';
    public const SUBSCRIPTION_ACTIVE = 'active';
    public const SUBSCRIPTION_REVOKED = 'revoked';
    public const SUBSCRIPTION_EXPIRED = 'expired';

    /** @var list<string> */
    public const SUBSCRIPTION_STATUSES = [
        self::SUBSCRIPTION_DISABLED, self::SUBSCRIPTION_PENDING, self::SUBSCRIPTION_ACTIVE,
        self::SUBSCRIPTION_REVOKED, self::SUBSCRIPTION_EXPIRED,
    ];

    public const PLAN_DEFAULT = 'premium_visibility';
    public const CURRENCY_DEFAULT = 'MAD';

    public static function orders_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pk_payment_orders';
    }

    public static function subscriptions_table(): string
    {
        global $wpdb;
        return $wpdb->prefix . 'pk_premium_subscriptions';
    }

    /** La passerelle est-elle ouverte ? (gate prestataire : non, inchangé) */
    public static function is_gateway_enabled(): bool
    {
        return false;
    }

    /**
     * Contrat public hérité du thème : toujours refusé tant que la gate
     * paiement est fermée. Aucun prestataire, lien ni callback n'est activé.
     *
     * @return \WP_Error
     */
    public static function create_order()
    {
        return new \WP_Error('pk_payment_disabled', __('Le paiement est désactivé.', 'partikulier-core'));
    }

    /* ------------------------------------------------------------------ *
     * Commandes de paiement — CRUD + transitions (contrat CA-2)
     * ------------------------------------------------------------------ */

    /**
     * Enregistre une commande (primitive d'ingestion : future voie callback
     * prestataire ; en lot B1, voie des tests contractuels). Statut par
     * défaut pending ; la référence prestataire, si fournie, doit être
     * unique par prestataire.
     *
     * @param array<string, mixed> $input {property_id, owner_id, provider?,
     *      provider_order_ref?, amount_minor?, currency?, purpose?, status?, metadata?}
     * @return int|\WP_Error
     */
    public static function record_order(array $input)
    {
        global $wpdb;
        $property_id = absint($input['property_id'] ?? 0);
        $owner_id = absint($input['owner_id'] ?? 0);
        if (!$property_id || get_post_type($property_id) !== self::POST_TYPE) {
            return new \WP_Error('pk_payment_property', __('Commande de paiement sans annonce valide.', 'partikulier-core'));
        }
        if (!$owner_id || !get_userdata($owner_id)) {
            return new \WP_Error('pk_payment_owner', __('Propriétaire de commande inconnu.', 'partikulier-core'));
        }
        $provider = sanitize_key((string) ($input['provider'] ?? 'unselected'));
        $provider = '' !== $provider ? $provider : 'unselected';
        $ref = substr(sanitize_text_field((string) ($input['provider_order_ref'] ?? '')), 0, 191);
        $amount_minor = absint($input['amount_minor'] ?? 0);
        $currency = strtoupper(substr(sanitize_key((string) ($input['currency'] ?? self::CURRENCY_DEFAULT)), 0, 3));
        $purpose = substr(sanitize_key((string) ($input['purpose'] ?? self::PLAN_DEFAULT)), 0, 64);
        $status = sanitize_key((string) ($input['status'] ?? self::ORDER_PENDING));
        if (!in_array($status, self::ORDER_STATUSES, true)) {
            return new \WP_Error('pk_payment_status', __('Statut de commande invalide.', 'partikulier-core'));
        }
        if (strlen($currency) !== 3) {
            return new \WP_Error('pk_payment_currency', __('Devise de commande invalide.', 'partikulier-core'));
        }
        if ($ref !== '') {
            $duplicate = (int) $wpdb->get_var($wpdb->prepare(
                'SELECT COUNT(*) FROM ' . self::orders_table() . ' WHERE provider = %s AND provider_order_ref = %s',
                $provider,
                $ref
            ));
            if ($duplicate > 0) {
                return new \WP_Error('pk_payment_duplicate_ref', __('Référence prestataire déjà enregistrée.', 'partikulier-core'));
            }
        }
        $metadata = array_key_exists('metadata', $input) && (is_array($input['metadata']) || is_string($input['metadata']))
            ? (is_array($input['metadata']) ? wp_json_encode($input['metadata']) : (string) $input['metadata'])
            : null;
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            self::orders_table(),
            [
                'property_id' => $property_id,
                'owner_id' => $owner_id,
                'provider' => $provider,
                'provider_order_ref' => ('' !== $ref ? $ref : null),
                'amount_minor' => $amount_minor,
                'currency' => $currency,
                'purpose' => ('' !== $purpose ? $purpose : self::PLAN_DEFAULT),
                'status' => $status,
                'metadata' => $metadata,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', '%s', ('' !== $ref ? '%s' : 'null'), '%d', '%s', '%s', '%s', ($metadata !== null ? '%s' : 'null'), '%s', '%s']
        );
        if (false === $inserted) {
            return new \WP_Error('pk_payment_storage', __('Impossible d’enregistrer la commande.', 'partikulier-core'));
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * @return object|null
     */
    public static function get_order(int $id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::orders_table() . ' WHERE id = %d', $id));
    }

    /**
     * Mise à jour contrôlée (liste blanche de colonnes, horodatage automatique).
     *
     * @param array<string, mixed> $fields
     * @return true|\WP_Error
     */
    public static function update_order(int $id, array $fields)
    {
        global $wpdb;
        $allowed = ['provider_order_ref', 'amount_minor', 'currency', 'purpose', 'status', 'metadata'];
        $data = [];
        $formats = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if ('status' === $key) {
                $value = sanitize_key((string) $value);
                if (!in_array($value, self::ORDER_STATUSES, true)) {
                    return new \WP_Error('pk_payment_status', __('Statut de commande invalide.', 'partikulier-core'));
                }
                $data[$key] = $value;
                $formats[] = '%s';
            } elseif ('amount_minor' === $key) {
                $data[$key] = absint($value);
                $formats[] = '%d';
            } elseif ('metadata' === $key) {
                $data[$key] = is_array($value) ? wp_json_encode($value) : (string) $value;
                $formats[] = '%s';
            } else {
                $data[$key] = substr(sanitize_text_field((string) $value), 0, 'currency' === $key ? 3 : 191);
                $formats[] = '%s';
            }
        }
        if (!$data) {
            return new \WP_Error('pk_payment_noop', __('Aucun champ modifiable fourni.', 'partikulier-core'));
        }
        $data['updated_at'] = current_time('mysql', true);
        $formats[] = '%s';
        $updated = $wpdb->update(self::orders_table(), $data, ['id' => $id], $formats, ['%d']);
        if (false === $updated) {
            return new \WP_Error('pk_payment_storage', __('Impossible de mettre à jour la commande.', 'partikulier-core'));
        }
        return true;
    }

    /**
     * Transition « paiement échoué » (CA-2) : statut failed, motif journalisé
     * dans les métadonnées ET au registre d'audit.
     *
     * @return true|\WP_Error
     */
    public static function mark_order_failed(int $id, string $reason)
    {
        $order = self::get_order($id);
        if (!$order) {
            return new \WP_Error('pk_payment_not_found', __('Commande introuvable.', 'partikulier-core'));
        }
        if ($order->status === self::ORDER_PAID) {
            return new \WP_Error('pk_payment_transition', __('Une commande aboutie ne peut être marquée en échec.', 'partikulier-core'));
        }
        $reason = sanitize_text_field($reason);
        $metadata = is_string($order->metadata) ? (array) json_decode($order->metadata, true) : [];
        $metadata['failure_reason'] = $reason;
        $result = self::update_order($id, ['status' => self::ORDER_FAILED, 'metadata' => $metadata]);
        if (is_wp_error($result)) {
            return $result;
        }
        self::audit('payment_order_failed', 'payment_order', $id, ['reason' => $reason, 'from' => $order->status]);
        return true;
    }

    /**
     * Transition « paiement abouti » : statut paid, référence prestataire
     * consolidée si fournie, consignée au registre d'audit.
     *
     * @return true|\WP_Error
     */
    public static function mark_order_paid(int $id, string $provider_order_ref = '')
    {
        $order = self::get_order($id);
        if (!$order) {
            return new \WP_Error('pk_payment_not_found', __('Commande introuvable.', 'partikulier-core'));
        }
        if ($order->status === self::ORDER_FAILED) {
            return new \WP_Error('pk_payment_transition', __('Une commande en échec ne peut être marquée aboutie.', 'partikulier-core'));
        }
        $fields = ['status' => self::ORDER_PAID];
        if ('' !== $provider_order_ref) {
            $fields['provider_order_ref'] = $provider_order_ref;
        }
        $result = self::update_order($id, $fields);
        if (is_wp_error($result)) {
            return $result;
        }
        self::audit('payment_order_paid', 'payment_order', $id, ['from' => $order->status]);
        return true;
    }

    /** @return true|\WP_Error */
    public static function delete_order(int $id)
    {
        global $wpdb;
        $linked = (int) $wpdb->get_var($wpdb->prepare(
            'SELECT COUNT(*) FROM ' . self::subscriptions_table() . ' WHERE payment_order_id = %d',
            $id
        ));
        if ($linked > 0) {
            return new \WP_Error('pk_payment_order_in_use', __('Commande référencée par un abonnement.', 'partikulier-core'));
        }
        $deleted = $wpdb->delete(self::orders_table(), ['id' => $id], ['%d']);
        if (false === $deleted) {
            return new \WP_Error('pk_payment_storage', __('Impossible de supprimer la commande.', 'partikulier-core'));
        }
        return true;
    }

    /* ------------------------------------------------------------------ *
     * Abonnements premium — CRUD + transitions (contrat CA-2)
     * ------------------------------------------------------------------ */

    /**
     * @param array<string, mixed> $input {property_id, owner_id, payment_order_id?,
     *      plan_key?, status?, starts_at?, ends_at?}
     * @return int|\WP_Error
     */
    public static function create_subscription(array $input)
    {
        global $wpdb;
        $property_id = absint($input['property_id'] ?? 0);
        $owner_id = absint($input['owner_id'] ?? 0);
        if (!$property_id || get_post_type($property_id) !== self::POST_TYPE) {
            return new \WP_Error('pk_premium_property', __('Abonnement sans annonce valide.', 'partikulier-core'));
        }
        if (!$owner_id || !get_userdata($owner_id)) {
            return new \WP_Error('pk_payment_owner', __('Propriétaire d’abonnement inconnu.', 'partikulier-core'));
        }
        $payment_order_id = absint($input['payment_order_id'] ?? 0);
        if ($payment_order_id > 0 && !self::get_order($payment_order_id)) {
            return new \WP_Error('pk_payment_not_found', __('Commande de rattachement introuvable.', 'partikulier-core'));
        }
        $plan_key = substr(sanitize_key((string) ($input['plan_key'] ?? self::PLAN_DEFAULT)), 0, 64);
        $status = sanitize_key((string) ($input['status'] ?? self::SUBSCRIPTION_DISABLED));
        if (!in_array($status, self::SUBSCRIPTION_STATUSES, true)) {
            return new \WP_Error('pk_payment_status', __('Statut d’abonnement invalide.', 'partikulier-core'));
        }
        $starts_at = self::optional_datetime($input['starts_at'] ?? null);
        $ends_at = self::optional_datetime($input['ends_at'] ?? null);
        if (is_wp_error($starts_at) || is_wp_error($ends_at)) {
            return new \WP_Error('pk_premium_dates', __('La période d’abonnement est invalide.', 'partikulier-core'));
        }
        if ($starts_at && $ends_at && strtotime($ends_at . ' UTC') <= strtotime($starts_at . ' UTC')) {
            return new \WP_Error('pk_premium_dates', __('La période d’abonnement est invalide.', 'partikulier-core'));
        }
        $now = current_time('mysql', true);
        $inserted = $wpdb->insert(
            self::subscriptions_table(),
            [
                'property_id' => $property_id,
                'owner_id' => $owner_id,
                'payment_order_id' => ($payment_order_id > 0 ? $payment_order_id : null),
                'plan_key' => ('' !== $plan_key ? $plan_key : self::PLAN_DEFAULT),
                'status' => $status,
                'starts_at' => $starts_at,
                'ends_at' => $ends_at,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            ['%d', '%d', ($payment_order_id > 0 ? '%d' : 'null'), '%s', '%s', ($starts_at ? '%s' : 'null'), ($ends_at ? '%s' : 'null'), '%s', '%s']
        );
        if (false === $inserted) {
            return new \WP_Error('pk_premium_storage', __('Impossible d’enregistrer l’abonnement.', 'partikulier-core'));
        }
        return (int) $wpdb->insert_id;
    }

    /**
     * @return object|null
     */
    public static function get_subscription(int $id)
    {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . self::subscriptions_table() . ' WHERE id = %d', $id));
    }

    /**
     * @param array<string, mixed> $fields
     * @return true|\WP_Error
     */
    public static function update_subscription(int $id, array $fields)
    {
        global $wpdb;
        $allowed = ['plan_key', 'status', 'starts_at', 'ends_at', 'payment_order_id'];
        $data = [];
        $formats = [];
        foreach ($fields as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                continue;
            }
            if ('status' === $key) {
                $value = sanitize_key((string) $value);
                if (!in_array($value, self::SUBSCRIPTION_STATUSES, true)) {
                    return new \WP_Error('pk_payment_status', __('Statut d’abonnement invalide.', 'partikulier-core'));
                }
                $data[$key] = $value;
                $formats[] = '%s';
            } elseif ('payment_order_id' === $key) {
                $payment_order_id = absint($value);
                if ($payment_order_id > 0 && !self::get_order($payment_order_id)) {
                    return new \WP_Error('pk_payment_not_found', __('Commande de rattachement introuvable.', 'partikulier-core'));
                }
                $data[$key] = ($payment_order_id > 0 ? $payment_order_id : null);
                $formats[] = ($payment_order_id > 0 ? '%d' : 'null');
            } elseif ('plan_key' === $key) {
                $data[$key] = substr(sanitize_key((string) $value), 0, 64);
                $formats[] = '%s';
            } else {
                $datetime = self::optional_datetime($value);
                if (is_wp_error($datetime)) {
                    return new \WP_Error('pk_premium_dates', __('La période d’abonnement est invalide.', 'partikulier-core'));
                }
                $data[$key] = $datetime;
                $formats[] = ($datetime ? '%s' : 'null');
            }
        }
        if (!$data) {
            return new \WP_Error('pk_payment_noop', __('Aucun champ modifiable fourni.', 'partikulier-core'));
        }
        $data['updated_at'] = current_time('mysql', true);
        $formats[] = '%s';
        $updated = $wpdb->update(self::subscriptions_table(), $data, ['id' => $id], $formats, ['%d']);
        if (false === $updated) {
            return new \WP_Error('pk_premium_storage', __('Impossible de mettre à jour l’abonnement.', 'partikulier-core'));
        }
        return true;
    }

    /**
     * Transition « abonnement activé » : statut active + période obligatoire.
     *
     * @return true|\WP_Error
     */
    public static function activate_subscription(int $id, string $starts_at, string $ends_at)
    {
        $result = self::update_subscription($id, [
            'status' => self::SUBSCRIPTION_ACTIVE,
            'starts_at' => $starts_at,
            'ends_at' => $ends_at,
        ]);
        if (is_wp_error($result)) {
            return $result;
        }
        self::audit('payment_subscription_activated', 'payment_subscription', $id, []);
        return true;
    }

    /**
     * Transition « révocation premium » d'un abonnement (CA-2) : statut
     * revoked, motif consigné au registre d'audit (la table n'a pas de
     * colonne motif — le registre d'audit est la trace).
     *
     * @return true|\WP_Error
     */
    public static function revoke_subscription(int $id, string $reason)
    {
        $reason = sanitize_text_field($reason);
        if ('' === $reason) {
            return new \WP_Error('pk_premium_reason', __('Un motif de retrait est obligatoire.', 'partikulier-core'));
        }
        $result = self::update_subscription($id, ['status' => self::SUBSCRIPTION_REVOKED]);
        if (is_wp_error($result)) {
            return $result;
        }
        self::audit('payment_subscription_revoked', 'payment_subscription', $id, ['reason' => $reason]);
        return true;
    }

    /** @return true|\WP_Error */
    public static function delete_subscription(int $id)
    {
        global $wpdb;
        $deleted = $wpdb->delete(self::subscriptions_table(), ['id' => $id], ['%d']);
        if (false === $deleted) {
            return new \WP_Error('pk_premium_storage', __('Impossible de supprimer l’abonnement.', 'partikulier-core'));
        }
        return true;
    }

    /* ------------------------------------------------------------------ *
     * Outils internes
     * ------------------------------------------------------------------ */

    /**
     * Normalise une date facultative : null/'' → null (NULL SQL), texte →
     * datetime UTC ou WP_Error si non parsable.
     *
     * @return string|null|\WP_Error
     */
    private static function optional_datetime($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = strtotime(trim((string) $value) . ' UTC');
        return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : new \WP_Error('pk_premium_dates', __('Date invalide.', 'partikulier-core'));
    }

    /** Consigne une transition au registre d'audit — chargement déterministe. */
    private static function audit(string $action, string $object_type, ?int $object_id, array $metadata): void
    {
        require_once __DIR__ . '/../../AuditLogger.php';
        (new \Partikulier\Core\AuditLogger())->record($action, $object_type, $object_id, $metadata);
    }
}
