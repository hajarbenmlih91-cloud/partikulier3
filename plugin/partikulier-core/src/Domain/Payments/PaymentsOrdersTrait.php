<?php
/**
 * Commandes de paiement (lot B1) : gate publique fermée, CRUD et
 * transitions d'état (échec, paiement abouti) exigés par le contrat
 * CA-2 — consignés au registre d'audit.
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

trait PaymentsOrdersTrait
{


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
}
