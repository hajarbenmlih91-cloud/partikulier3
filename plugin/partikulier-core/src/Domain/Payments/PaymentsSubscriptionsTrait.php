<?php
/**
 * Abonnements premium (lot B1) : CRUD et cycle de vie (activation,
 * révocation, expiration) — la passerelle publique reste fermée,
 * le périmètre runtime visible est strictement inchangé.
 *
 * Lot D (découpage, arbitrage commanditaire « Référence + plugin », CDC
 * v1.2 annexe C) : le service PaymentService (477 lignes, code porté par la campagne
 * au lot B1) est découpé en shell + traits sur le précédent B6
 * (class-localization.php 990 → 199 l.) — les méthodes sont déplacées
 * VERBATIM, l'API publique et les hooks restent portés par la classe shell
 * (le trait compose la même classe : aucune délégation, aucun changement de
 * mécanisme actif). Preuve : contrat module-perimeter-contract.php + rejeu
 * intégral des suites du domaine (oracle inchangé).
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Payments;

trait PaymentsSubscriptionsTrait
{


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
}
