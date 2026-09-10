<?php
/**
 * Gabarit d'accueil des domaines métier (lot A).
 *
 * Décrit les huit domaines de la refonte et leurs tables, avec leur
 * propriétaire ACTUEL (plugin ou thème) et le lot qui les prendra en charge.
 * Le manifeste des tables est versionné dans Schema (schéma unifié) ; ce
 * registre fournit les services de lecture : inventaire, contrôle de présence,
 * état de migration. Les lots B à F branchent leurs modules ici au fur et à
 * mesure de l'extraction — le lot A n'accueille que le domaine listings
 * (déjà propriété du plugin) et déclare les autres comme « hébergés thème ».
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain;

use Partikulier\Core\Database\Schema;

final class DomainRegistry
{
    /** @return array<string, array{label: string, owner: string, lot: string, tables: array<int, string>}> */
    public static function all(): array
    {
        $domains = [];
        foreach (Schema::domainTables() as $table => $descriptor) {
            $domains[$descriptor['domain']] ??= [
                'label' => $descriptor['label'],
                'owner' => $descriptor['owner'],
                'lot' => $descriptor['lot'],
                'tables' => [],
            ];
            $domains[$descriptor['domain']]['tables'][] = (string) $table;
        }
        ksort($domains);
        return $domains;
    }

    /**
     * État de présence des tables par domaine, pour le health check 2.0.
     *
     * @return array<string, array{owner: string, lot: string, tables: array<string, bool>}>
     */
    public static function health(): array
    {
        global $wpdb;
        // Contrôle de présence table par table : SHOW TABLES LIKE retourne le
        // NOM de la table (pas un compteur) — comparaison textuelle, portable
        // MySQL/SQLite, identique au health check historique.
        $existing = [];
        foreach (Schema::domainTables() as $table => $unused) {
            $name = $wpdb->prefix . $table;
            if ((string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $name)) === $name) {
                $existing[$name] = true;
            }
        }
        $out = [];
        foreach (self::all() as $key => $domain) {
            $tables = [];
            foreach ($domain['tables'] as $table) {
                $tables[$table] = isset($existing[$wpdb->prefix . $table]);
            }
            $out[$key] = ['owner' => $domain['owner'], 'lot' => $domain['lot'], 'tables' => $tables];
        }
        return $out;
    }

    /**
     * Contrôle croisé INTEG-1 : lignes publiées de la projection sans post
     * vivant correspondant (annonces fantômes) et posts publiés sans projection.
     * Formulation SQL portable MySQL/SQLite (pas de fonction dans une jointure).
     *
     * @return array{orphans: int, missing: int, served: int, live_posts: int}
     */
    public static function listingIntegrity(): array
    {
        global $wpdb;
        $listings = $wpdb->prefix . 'pk_listings';
        $posts = $wpdb->posts;
        $externalLike = 'estatik:%';

        $orphans = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$listings} l WHERE l.status = 'published' AND l.external_id LIKE '{$externalLike}'"
            . " AND NOT EXISTS (SELECT 1 FROM {$posts} p WHERE p.post_type = 'properties' AND p.post_status = 'publish' AND CONCAT('estatik:', p.ID) = l.external_id)"
        );
        $missing = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$posts} p WHERE p.post_type = 'properties' AND p.post_status = 'publish'"
            . " AND NOT EXISTS (SELECT 1 FROM {$listings} l WHERE l.external_id = CONCAT('estatik:', p.ID) AND l.status = 'published')"
        );
        $served = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$listings} WHERE status = %s", 'published'));
        $livePosts = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$posts} WHERE post_type = 'properties' AND post_status = 'publish'"
        );
        return ['orphans' => $orphans, 'missing' => $missing, 'served' => $served, 'live_posts' => $livePosts];
    }
}
