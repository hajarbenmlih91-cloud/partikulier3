<?php
/**
 * Service des redirections d'anciens slugs (micro-lot pré-prod 2.10.8/6.20.7,
 * E-4303 — prérequis du lot SE-043 côté thème).
 *
 * Table pk_slug_redirects (schéma 2.7.0, créée par le Migrator — table NEUVE,
 * vide au départ, aucune migration de données) : un ancien slug d'annonce y
 * mène à l'ID de l'annonce qui le portait. Le lot SE-043 du thème consomme
 * cette API pour résoudre les 404 du routage géo : ancien slug connu → 301
 * vers le permalink COURANT de l'annonce ; annonce corbeillée → 410 (le
 * thème décide selon le statut — l'ID est retourné quel que soit l'état du
 * post) ; sinon 404. Le mécanisme _wp_old_slug de WordPress ne couvre pas
 * le routage géo du thème (resolve_geo_request), d'où cette table dédiée.
 *
 * Sémantique verrouillée par le contrat slug-redirects-domain-contract
 * (E43-001→009), alignée sur la batterie E-4304 de SE-043 :
 *  - un ancien slug mène à l'ID de l'annonce (JAMAIS vers un autre slug :
 *    pas de chaîne A→B→C — deux renommages successifs laissent les deux
 *    anciens slugs pointer vers la même annonce, le permalink étant
 *    calculé par le thème au moment de la résolution) ;
 *  - un slug (re)pris par une annonce cesse de rediriger : la nouvelle
 *    fiche doit servir 200, zéro redirection résiduelle ;
 *  - la suppression définitive d'une annonce n'y laisse aucune ligne
 *    orpheline.
 *
 * Classe pure : AUCUN hook au chargement, AUCUN cron, AUCUNE route — le
 * thème (SE-043, 6.20.7) consommera l'API via sa couture class_exists. Les
 * mutations sont consignées au registre d'audit (discipline du plugin :
 * preuve d'exécution, objet slug_redirect — seuls les renommages, événements
 * rares côté administration, y écrivent).
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\SlugRedirects;

use Partikulier\Core\AuditLogger;

final class SlugRedirectsService
{
	/** Longueur maximale d'un slug stocké (indice utf8mb4 sûr, convention wp_options). */
	private const MAX_SLUG_LENGTH = 191;

	public static function redirects_table(): string
	{
		global $wpdb;
		return $wpdb->prefix . 'pk_slug_redirects';
	}

	/**
	 * Enregistre qu'un ancien slug mène désormais à une annonce (appelé par le
	 * thème au renommage d'une annonce). Upsert : si le slug redirigeait déjà,
	 * la ligne pointe vers la nouvelle annonce — une seule ligne par slug.
	 *
	 * @param int    $property_id Annonce qui portait le slug.
	 * @param string $old_slug    Slug avant renommage.
	 * @return bool False si les entrées sont invalides (ID nul, slug vide) ou si l'écriture échoue.
	 */
	public static function record_redirect( int $property_id, string $old_slug ): bool
	{
		global $wpdb;
		$property_id = absint( $property_id );
		$old_slug    = trim( substr( $old_slug, 0, self::MAX_SLUG_LENGTH ) );
		if ( $property_id < 1 || $old_slug === '' ) {
			return false;
		}
		$table = self::redirects_table();
		$now   = gmdate( 'Y-m-d H:i:s' );
		$write = $wpdb->query( $wpdb->prepare(
			"INSERT INTO {$table} (slug, property_id, created_at, updated_at)
			 VALUES (%s, %d, %s, %s)
			 ON DUPLICATE KEY UPDATE property_id = VALUES(property_id), updated_at = VALUES(updated_at)",
			$old_slug,
			$property_id,
			$now,
			$now
		) );
		if ( $write === false ) {
			return false;
		}
		self::audit()->record( 'slug_redirect_recorded', 'slug_redirect', $property_id, [
			'slug' => $old_slug,
			'lot'  => 'ML',
		] );
		return true;
	}

	/**
	 * Résout un ancien slug vers l'ID de l'annonce à servir (le thème émettra
	 * un 301 vers son permalink COURANT ; annonce corbeillée → 410 côté thème).
	 *
	 * @return int|null ID de l'annonce, ou null si le slug ne redirige pas.
	 */
	public static function resolve_redirect( string $slug ): ?int
	{
		global $wpdb;
		$slug = trim( substr( $slug, 0, self::MAX_SLUG_LENGTH ) );
		if ( $slug === '' ) {
			return null;
		}
		$table = self::redirects_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT property_id FROM {$table} WHERE slug = %s", $slug ) );
		return $row !== null ? (int) $row->property_id : null;
	}

	/**
	 * Un slug est (re)pris par une annonce : toute redirection sur ce slug
	 * disparaît — la fiche qui porte le slug doit servir 200, jamais une 301
	 * résiduelle vers une ancienne annonce (E-4304 : « slug réutilisé par une
	 * autre annonce → 200 nouvelle fiche, 0 redirection résiduelle »).
	 *
	 * @return int Nombre de lignes retirées.
	 */
	public static function clear_redirect( string $slug ): int
	{
		global $wpdb;
		$slug = trim( substr( $slug, 0, self::MAX_SLUG_LENGTH ) );
		if ( $slug === '' ) {
			return 0;
		}
		$table   = self::redirects_table();
		$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE slug = %s", $slug ) );
		if ( $removed > 0 ) {
			self::audit()->record( 'slug_redirect_cleared', 'slug_redirect', null, [
				'slug'    => $slug,
				'removed' => $removed,
				'lot'     => 'ML',
			] );
		}
		return $removed;
	}

	/**
	 * Suppression définitive d'une annonce : aucune ligne orpheline ne doit
	 * rester (E-4304 : « suppression définitive → 0 ligne orpheline »).
	 *
	 * @return int Nombre de lignes retirées.
	 */
	public static function delete_for_property( int $property_id ): int
	{
		global $wpdb;
		$property_id = absint( $property_id );
		if ( $property_id < 1 ) {
			return 0;
		}
		$table   = self::redirects_table();
		$removed = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE property_id = %d", $property_id ) );
		if ( $removed > 0 ) {
			self::audit()->record( 'slug_redirects_purged', 'slug_redirect', $property_id, [
				'removed' => $removed,
				'lot'     => 'ML',
			] );
		}
		return $removed;
	}

	private static function audit(): AuditLogger
	{
		require_once __DIR__ . '/../../AuditLogger.php';
		return new AuditLogger();
	}
}
