<?php
/**
 * Domaine leads/qualification/WhatsApp (lot B2) — dispositif complet.
 *
 * Port fidèle de Partikulier_Buyer_Qualification, Partikulier_Lead_Retention
 * et des accès données de Partikulier_Leads_Admin (thème 6.17.x) côté plugin :
 * mêmes tables (huit, mêmes noms et index — REG-6), mêmes contrôles, mêmes
 * codes d'erreur, mêmes plafonnements. Le thème 6.18.3+ délègue ici via des
 * coutures class_exists ; sans le plugin, il conserve son chemin autonome —
 * les deux écrivent les mêmes tables avec la même logique (matrice REG-5).
 *
 * Aucun numéro n'est stocké en clair dans les clés de recherche : un HMAC
 * (wp_salt auth) sert à retrouver et limiter un même demandeur ; le numéro
 * chiffré (AES-256-CBC, wp_salt secure_auth) n'est déchiffrable que par un
 * administrateur (decrypt_phone_for_admin), jamais exposé en REST ni en log.
 *
 * Journal d'audit : les transitions du dispositif (contact autorisé, opt-out,
 * effacement, suivi mis à jour, purge) sont consignées au registre — preuve
 * d'exécution par le plugin (seul le plugin écrit ce registre ; le chemin
 * autonome du thème n'y écrit jamais, cf. matrice REG-5 du lot B1).
 *
 * Le stockage de leads par commentaires WordPress reste éteint (INTEG-2,
 * lot A) : aucune écriture de ce dispositif ne crée de commentaire
 * partikulier_lead (REG-4).
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Leads;

final class LeadService
{
	public const REST_NAMESPACE          = 'partikulier/v1';
	public const DAILY_LIMIT_DEFAULT     = 2;
	public const STATUS_PENDING_WHATSAPP = 'en_attente_whatsapp';
	public const CONSENT_SCOPE_SIMILAR   = 'similar_listings';
	public const CONSENT_POLICY_VERSION  = '1.0';
	public const RETENTION_DAYS_DEFAULT  = 365;
	public const CRON_HOOK               = 'pk_buyer_privacy_purge';

	/* SE-016 (campagne post-audit) — seuils du limiteur de la garde
	 * d'effacement (LeadsEraseGuardTrait). Constantes de classe : le plugin
	 * requiert PHP 8.1, les constantes de trait n'arrivent qu'en 8.2. */
	public const ERASE_FAILURES_PER_HOUR    = 10;
	public const ERASE_TRANSITION_THRESHOLD = 100;

	private const POST_TYPE = 'properties';

	/* Lot D (CDC v1.2 annexe C, arbitrage « Référence + plugin ») :
	 * méthodes déplacées VERBATIM dans des traits composés par la
	 * présente classe shell — API publique, hooks et constants inchangés. */
	use LeadsContactTrait;
	use LeadsIntakeTrait;
	use LeadsRestTrait;
	use LeadsPrivacyTrait;
	use LeadsAdminTrait;
	use LeadsEraseGuardTrait;

	/* ------------------------------------------------------------------ */
	/* Tableaux de nommage                                                */
	/* ------------------------------------------------------------------ */

	public static function table( string $suffix ): string
	{
		global $wpdb;
		return $wpdb->prefix . $suffix;
	}

	public static function leads_table(): string
	{
		return self::table('pk_buyer_leads');
	}

	/* ------------------------------------------------------------------ */
	/* Plafonnement quotidien (port fidèle : réglage n8n du thème)         */
	/* ------------------------------------------------------------------ */

	public static function daily_limit(): int
	{
		if ( class_exists(LeadSettings::class) ) {
			return (int) LeadSettings::get_limit('daily_limit');
		}
		$value = class_exists('Partikulier_N8n_Security')
			? (int) \Partikulier_N8n_Security::get('quota_per_day', self::DAILY_LIMIT_DEFAULT)
			: self::DAILY_LIMIT_DEFAULT;
		return max(1, min(10, absint($value) ?: self::DAILY_LIMIT_DEFAULT));
	}

	/* ------------------------------------------------------------------ */
	/* Référence d'annonce (méta, hors tables du domaine — port fidèle)    */
	/* ------------------------------------------------------------------ */

	public static function reference_for( int $post_id ): string
	{
		$post_id   = absint($post_id);
		$reference = (string) get_post_meta($post_id, '_pk_buyer_reference', true);
		if ( ! $reference ) {
			$reference = 'PK-' . $post_id . '-' . strtoupper(wp_generate_password(4, false, false));
			update_post_meta($post_id, '_pk_buyer_reference', $reference);
		}
		return $reference;
	}

	/* ------------------------------------------------------------------ */
	/* Utilitaires privés (port fidèle)                                    */
	/* ------------------------------------------------------------------ */

	private static function contact_response( int $property_id, bool $replayed ): array
	{
		$raw_phone = (string) get_post_meta($property_id, '_pk_owner_phone', true);
		$phone     = class_exists('\\Partikulier_Crypto') ? \Partikulier_Crypto::read_phone($raw_phone) : $raw_phone;
		return [
			'allowed'  => true,
			'replayed' => $replayed,
			'property' => self::property_snapshot($property_id),
			'owner'    => [
				'name'  => get_post_meta($property_id, '_pk_owner_name', true),
				'phone' => $phone,
			],
		];
	}

	private static function property_snapshot( int $property_id ): array
	{
		$terms = wp_get_object_terms($property_id, 'es_status', ['fields' => 'names']);
		if ( is_wp_error($terms) || ! is_array($terms) ) {
			$terms = [];
		}
		// Lot R1 — snapshot étendu data qualifiée (rétro-compatible) :
		// on garde les 8 clés historiques et on ajoute les 9 qualifiantes
		// pour la reco (ville/quartier/type/etage/ensoleillement/surface).
		$loc_detail = self::location_detail($property_id);
		// Fallback meta si taxonomies non enregistrées (test sans Estatik)
		$ville = $loc_detail['ville'] ?: (string) get_post_meta($property_id, '_pk_ville', true) ?: (string) get_post_meta($property_id, '_es_ville', true);
		$quartier = $loc_detail['quartier'] ?: (string) get_post_meta($property_id, '_pk_quartier', true) ?: (string) get_post_meta($property_id, '_es_quartier', true);
		$type = self::primary_term_name($property_id, 'es_type') ?: self::primary_term_name($property_id, 'es_property_type') ?: (string) get_post_meta($property_id, '_pk_type', true) ?: (string) get_post_meta($property_id, 'es_property_type', true);
		return [
			'id'              => absint($property_id),
			'reference'       => self::reference_for($property_id),
			'title'           => get_the_title($property_id),
			'url'             => get_permalink($property_id),
			'price'           => get_post_meta($property_id, 'es_property_price', true),
			'location'        => self::location_string($property_id) ?: trim($ville . ($quartier ? ', '.$quartier : '')),
			'layout'          => get_post_meta($property_id, '_pk_bedrooms_label', true),
			'transaction'     => implode(', ', $terms),
			// --- R1 qualifiant (tous string/bien typés, '' si absent) ---
			'ville'           => $ville,
			'quartier'        => $quartier,
			'type'            => $type,
			'area'            => get_post_meta($property_id, 'es_property_area', true) ?: get_post_meta($property_id, '_pk_area', true) ?: get_post_meta($property_id, 'es_area', true),
			'etage'           => get_post_meta($property_id, '_pk_etage', true) ?: get_post_meta($property_id, 'es_property_floor', true) ?: get_post_meta($property_id, '_es_floor', true),
			'ensoleillement'  => get_post_meta($property_id, '_pk_ensoleillement', true) ?: get_post_meta($property_id, 'es_ensoleillement', true) ?: get_post_meta($property_id, '_es_sun', true),
			'chambres'        => get_post_meta($property_id, 'es_property_bedrooms', true) ?: get_post_meta($property_id, '_pk_bedrooms', true),
			'salons'          => get_post_meta($property_id, 'es_property_salons', true) ?: get_post_meta($property_id, '_pk_salons', true),
		];
	}

	/**
	 * Localisation d'une annonce. Le thème expose Partikulier_Geo::location_string
	 * (avec localisation Polylang des termes) : utilisé tel quel quand la classe
	 * existe (cas nominal — le thème est actif) ; sinon repli sur les termes
	 * bruts es_location, même séparateur. Le résultat est stocké dans
	 * property_snapshot : la parité avec le chemin thème est vérifiée au contrat.
	 */
	private static function location_string( int $property_id ): string
	{
		if ( class_exists('Partikulier_Geo') ) {
			return (string) \Partikulier_Geo::location_string($property_id);
		}
		$terms = get_the_terms($property_id, 'es_location');
		if ( ! is_array($terms) || is_wp_error($terms) ) {
			return '';
		}
		$names = array_filter(array_map(static fn( $term ): string => (string) $term->name, $terms));
		return implode(', ', array_unique($names));
	}

	/**
	 * Lot R1 : décompose es_location en ville/quartier (premier/dernier terme
	 * trié par parent). Si Partikulier_Geo existe, on réutilise son ordre ;
	 * sinon on prend le terme le plus haut (ville) et le plus bas (quartier).
	 *
	 * @return array{ville: string, quartier: string}
	 */
	private static function location_detail( int $property_id ): array
	{
		if ( class_exists('Partikulier_Geo') && method_exists('Partikulier_Geo', 'location_terms') ) {
			$terms = \Partikulier_Geo::location_terms($property_id);
			if ( is_array($terms) && count($terms) > 0 ) {
				$names = array_values(array_filter(array_map(static fn( $t ): string => (string) ($t->name ?? $t), $terms)));
				return ['ville' => (string) ($names[0] ?? ''), 'quartier' => (string) ($names[count($names)-1] ?? '')];
			}
		}
		$terms = get_the_terms($property_id, 'es_location');
		if ( ! is_array($terms) || is_wp_error($terms) || count($terms) === 0 ) {
			return ['ville' => '', 'quartier' => ''];
		}
		// Trie par parent (0 = ville) pour stabilité.
		usort($terms, static fn( $a, $b ) => (int) $a->parent <=> (int) $b->parent);
		$names = array_values(array_filter(array_map(static fn( $t ): string => (string) $t->name, $terms)));
		if ( count($names) === 1 ) {
			return ['ville' => (string) $names[0], 'quartier' => (string) $names[0]];
		}
		return ['ville' => (string) ($names[0] ?? ''), 'quartier' => (string) ($names[count($names)-1] ?? '')];
	}

	private static function primary_term_name( int $property_id, string $taxonomy ): string
	{
		$terms = get_the_terms($property_id, $taxonomy);
		if ( ! is_array($terms) || is_wp_error($terms) || count($terms) === 0 ) {
			return '';
		}
		$first = $terms[0];
		return is_object($first) && isset($first->name) ? (string) $first->name : '';
	}

	private static function property_for_reference( string $reference ): int
	{
		$posts = get_posts([
			'post_type'   => self::POST_TYPE,
			'post_status' => 'any',
			'meta_key'    => '_pk_buyer_reference',
			'meta_value'  => $reference,
			'fields'      => 'ids',
			'numberposts' => 1,
		]);
		return $posts ? (int) $posts[0] : 0;
	}

	private static function is_contactable_property( int $post_id ): bool
	{
		$status = get_post_meta($post_id, '_pk_status', true);
		return 'publish' === get_post_status($post_id) && ! in_array($status, ['vendu', 'loue', 'archive', self::STATUS_PENDING_WHATSAPP], true);
	}

	/** Consigne une transition au registre d'audit — chargement déterministe. */
	private static function audit( string $action, string $object_type, ?int $object_id, array $metadata ): void
	{
		require_once __DIR__ . '/../../AuditLogger.php';
		( new \Partikulier\Core\AuditLogger() )->record($action, $object_type, $object_id, $metadata);
	}
}
