<?php
/**
 * Synchronisation temps réel de la projection pk_listings (INTEG-1, lot A).
 *
 * Le type de contenu WordPress « properties » est la source de vérité ; la
 * table pk_listings est une projection maintenue à jour par les événements du
 * cycle de vie des posts — plus aucune tâche quotidienne.
 *
 * Gardes REG-1 obligatoires, toutes présentes :
 *  - filtrage du bruit : types autres que properties, révisions et
 *    autosaves ignorés ;
 *  - écriture uniquement sur différence : la ligne projetée est comparée à la
 *    ligne existante avant toute requête d'écriture ;
 *  - anti-réentrance : un indicateur d'exécution neutralise les hooks pendant
 *    le traitement de la file ;
 *  - plafond anti-boucle par annonce : au-delà de MAX_WRITES_PER_MINUTE
 *    écritures par minute sur un même post, alerte au registre d'audit et
 *    arrêt pour ce post ;
 *  - file d'attente : les hooks ne font qu'empiler (dédupliqué), jamais
 *    traiter ; la file est vidée en lot au shutdown — un import de N posts
 *    dans une même requête produit exactement N écritures, pas N×k.
 *
 * rebuild() : vidage puis reconstruction complète depuis les posts, journalisée
 * (comptages avant/après + entrée d'audit) et idempotente — rejouée deux fois,
 * le résultat est identique. Exécutée une fois par le Migrator au passage en
 * 2.0.0, rejouable via WP-CLI (wp partikulier rebuild).
 */

declare(strict_types=1);

namespace Partikulier\Core\Integration;

use Partikulier\Core\AuditLogger;

final class ListingSynchronizer
{
    public const POST_TYPE = 'properties';
    public const EXTERNAL_PREFIX = 'estatik:';
    public const MAX_WRITES_PER_MINUTE = 60;
    private const STATS_OPTION = 'partikulier_core_sync_stats';

    /** @var array<int, true> */
    private static array $upsertQueue = [];

    /** @var array<int, true> */
    private static array $deleteQueue = [];

    private static bool $registered = false;
    private static bool $flushing = false;

    /* Lot D (CDC v1.2 annexe C, arbitrage « Référence + plugin ») :
     * méthodes déplacées VERBATIM dans des traits composés par la
     * présente classe shell — API publique, hooks et constants inchangés. */
    use SynchronizerHooksTrait;
    use SynchronizerProjectionTrait;
    use SynchronizerMaintenanceTrait;
}
