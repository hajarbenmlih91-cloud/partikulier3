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

    /* Lot D (CDC v1.2 annexe C, arbitrage « Référence + plugin ») :
     * méthodes déplacées VERBATIM dans des traits composés par la
     * présente classe shell — API publique, hooks et constants inchangés. */
    use PaymentsOrdersTrait;
    use PaymentsSubscriptionsTrait;

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
