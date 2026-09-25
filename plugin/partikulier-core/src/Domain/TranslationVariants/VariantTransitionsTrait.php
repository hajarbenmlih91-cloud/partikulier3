<?php
/**
 * Variantes de traduction — volet transitions DP-9 (SE-044 / DP-9, addendum R1).
 *
 * Compose TranslationVariantsService (même classe, aucune délégation) :
 *  - source_for_variant() : résolution canonique d'un identifiant de variante
 *    vers sa source (lecture inverse du registre métier), avec refus 409
 *    sans écriture sur les cas d'incohérence listés par R1 §2.2 point 3 ;
 *  - restrained_variants_for() : variantes du groupe porteuses d'un marqueur
 *    administratif (refuse / en_attente_whatsapp) — base du précontrôle de
 *    groupe 409 pk_group_restrained AVANT toute écriture (R1 §2.3-R1) ;
 *  - propagate_transition() : propagation compare–write–verify qui n'écrase
 *    JAMAIS un marqueur administratif (l'étape est ignorée pour la variante
 *    et consignée), avec nettoyage symétrique des motifs/notes/dates à la
 *    réactivation (v1.1 §9) et harnais d'injection CP2 (point ②).
 *
 * Les métas premium (E-4807) ne sont jamais touchées ici.
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\TranslationVariants;

trait VariantTransitionsTrait
{
	/** Marqueurs administratifs jamais écrasés par une propagation (R1 §2.3-R1). */
	public const ADMIN_MARKER_STATUSES = ['refuse', 'en_attente_whatsapp'];

	/**
	 * Résolution canonique (R1 §2.2) : l'identifiant reçu est-il une variante ?
	 * Retourne la source à traiter. Refus (WP_Error) si :
	 *  - l'identifiant figure dans plusieurs lignes du registre ;
	 *  - l'identifiant est à la fois source (a des variantes) et variante ;
	 *  - le registre et Polylang divergent sur le couple source/variante ;
	 *  - la source pointée n'existe plus.
	 *
	 * @param int $post_id Identifiant brut reçu (AJAX ou REST).
	 * @return array{source_id:int, was_variant:bool}|\WP_Error
	 */
	public static function source_for_variant( $post_id )
	{
		$post_id = absint($post_id);
		if ( ! $post_id ) {
			return new \WP_Error('pk_variant_resolution', 'Identifiant invalide.');
		}

		global $wpdb;
		$table = self::variants_table();

		// L'identifiant est-il enregistré comme variante (une ou plusieurs lignes) ?
		$rows = (array) $wpdb->get_results(
			$wpdb->prepare(
				"SELECT source_property_id, locale FROM {$table} WHERE variant_property_id = %d",
				$post_id
			),
			ARRAY_A
		);

		if ( empty( $rows ) ) {
			// Pas une variante : l'identifiant est sa propre source canonique.
			return ['source_id' => $post_id, 'was_variant' => false];
		}

		if ( count( $rows ) > 1 ) {
			// Plusieurs lignes : rôle ambigu — refus 409 sans écriture.
			return new \WP_Error('pk_variant_resolution', 'Identifiant présent dans plusieurs lignes du registre.');
		}

		$source_id = absint( $rows[0]['source_property_id'] );

		// L'identifiant est-il AUSSI une source (a des variantes) ? Rôle ambigu.
		$is_also_source = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table} WHERE source_property_id = %d AND variant_property_id IS NOT NULL AND variant_property_id > 0",
				$post_id
			)
		);
		if ( $is_also_source > 0 ) {
			return new \WP_Error('pk_variant_resolution', 'Identifiant à la fois source et variante.');
		}

		// La source pointée existe-t-elle encore ?
		$source = get_post($source_id);
		if ( ! $source instanceof \WP_Post || self::POST_TYPE !== $source->post_type ) {
			return new \WP_Error('pk_variant_resolution', 'Source pointée inexistante.');
		}

		// Le registre et Polylang divergent-ils ? (même langue, autre traduction liée)
		if ( function_exists('pll_get_post_translations') && function_exists('pll_get_post_language') ) {
			$variant_lang = pll_get_post_language($post_id);
			if ( $variant_lang ) {
				$translations = pll_get_post_translations($source_id);
				if ( is_array( $translations ) ) {
					$same_lang = [];
					foreach ( $translations as $lang => $translated_id ) {
						if ( (int) $translated_id === $source_id ) {
							continue;
						}
						if ( pll_get_post_language( (int) $translated_id ) === $variant_lang ) {
							$same_lang[] = (int) $translated_id;
						}
					}
					// Polylang connaît une autre traduction de cette langue que la variante du registre.
					if ( $same_lang && ! in_array( $post_id, $same_lang, true ) ) {
						return new \WP_Error('pk_variant_resolution', 'Registre métier et Polylang divergents.');
					}
				}
			}
		}

		return ['source_id' => $source_id, 'was_variant' => true];
	}

	/**
	 * Variantes du groupe porteuses d'un marqueur administratif.
	 *
	 * @param int $source_id Annonce source.
	 * @return array<int, string> variant_id => marqueur (refuse|en_attente_whatsapp).
	 */
	public static function restrained_variants_for( $source_id ): array
	{
		$out = [];
		foreach ( self::variant_ids_for( absint($source_id) ) as $variant_id ) {
			$status = (string) get_post_meta($variant_id, '_pk_status', true);
			if ( in_array( $status, self::ADMIN_MARKER_STATUSES, true ) ) {
				$out[ $variant_id ] = $status;
			}
		}
		return $out;
	}

	/**
	 * Propagation d'une transition DP-9 aux variantes liées (R1 §2.3-R1 + v1.1 §9).
	 *
	 * Règles :
	 *  1. Aucune propagation n'écrase un marqueur administratif, fermeture
	 *     comprise : l'étape est ignorée pour la variante et consignée
	 *     (« ignorée — restriction administrative ») ;
	 *  2. écritures compare–write–verify : l'état attendu fait foi, le false
	 *     d'update_post_meta sur valeur identique n'est pas un échec ;
	 *  3. fermeture : statut + motif + note privée + date propagés ;
	 *     réactivation : suppression symétrique de motif/note/date ;
	 *  4. un défaut injecté (harnais CP2, point ②) fait échouer l'écriture
	 *     de la variante visée — sans toucher aux autres.
	 *
	 * @param int        $source_id       Annonce source.
	 * @param string     $target_status   Statut métier cible (fermeture) ou 'actif'.
	 * @param string     $reason          Motif ('' à la réactivation).
	 * @param string     $note            Texte privé (motif autre uniquement).
	 * @param bool       $is_closure      true = fermeture, false = réactivation.
	 * @param callable|null $injection    Harnais CP2 : function(variant_id, source_id): bool.
	 * @return array{variants: array<int, array{status:string, marker?:string, reason?:string}>}
	 */
	public static function propagate_transition( $source_id, $target_status, $reason, $note, $is_closure, $injection = null ): array
	{
		$report = ['variants' => []];
		$target_status = sanitize_key((string) $target_status);
		if ( '' === $target_status ) {
			return $report;
		}

		foreach ( self::variant_ids_for( absint($source_id) ) as $variant_id ) {
			$marker = (string) get_post_meta($variant_id, '_pk_status', true);

			// Règle 1 : marqueur administratif préservé — ignore consigné.
			if ( in_array( $marker, self::ADMIN_MARKER_STATUSES, true ) ) {
				$report['variants'][ $variant_id ] = [
					'status' => 'ignored',
					'marker' => $marker,
					'reason' => 'restriction administrative',
				];
				continue;
			}

			// Règle 4 : défaut injecté PENDANT la propagation (harnais CP2).
			if ( is_callable($injection) && call_user_func($injection, $variant_id, $source_id) ) {
				$report['variants'][ $variant_id ] = [
					'status' => 'failed',
					'reason' => 'injected_defect_during_propagation',
					'observed' => ['_pk_status' => $marker],
				];
				continue;
			}

			$failed = false;
			$observed = [];

			if ( $is_closure ) {
				// Règle 3 : fermeture — statut, motif, note, date (compare–write–verify).
				$already = ( $marker === $target_status && (string) get_post_meta($variant_id, '_pk_closed_reason', true) === sanitize_key((string) $reason) );
				$targets = [
					'_pk_status'        => $target_status,
					'_pk_closed_reason' => sanitize_key((string) $reason),
					'_pk_closed_at'     => $already ? (string) get_post_meta($variant_id, '_pk_closed_at', true) : current_time('mysql', true),
				];
				if ( '' !== (string) $note ) {
					$targets['_pk_closed_note'] = (string) $note;
				} elseif ( '' !== (string) get_post_meta($variant_id, '_pk_closed_note', true) ) {
					delete_post_meta($variant_id, '_pk_closed_note');
				}
				foreach ( $targets as $meta_key => $target ) {
					$stored = (string) get_post_meta($variant_id, $meta_key, true);
					if ( $stored === (string) $target ) {
						$observed[ $meta_key ] = 'already_at_target';
						continue;
					}
					update_post_meta($variant_id, $meta_key, $target);
					$after = (string) get_post_meta($variant_id, $meta_key, true);
					if ( $after !== (string) $target ) {
						$observed[ $meta_key ] = 'mismatch_after_write';
						$failed = true;
					} else {
						$observed[ $meta_key ] = 'written_verified';
					}
				}
			} else {
				// Règle 3 : réactivation — nettoyage symétrique complet.
				if ( $marker !== 'actif' ) {
					update_post_meta($variant_id, '_pk_status', 'actif');
					$after = (string) get_post_meta($variant_id, '_pk_status', true);
					if ( 'actif' !== $after ) {
						$observed['_pk_status'] = 'mismatch_after_write';
						$failed = true;
					} else {
						$observed['_pk_status'] = 'written_verified';
					}
				} else {
					$observed['_pk_status'] = 'already_at_target';
				}
				foreach ( ['_pk_closed_reason', '_pk_closed_note', '_pk_closed_at'] as $meta_key ) {
					if ( '' !== (string) get_post_meta($variant_id, $meta_key, true) ) {
						delete_post_meta($variant_id, $meta_key);
						$observed[ $meta_key ] = 'cleaned';
					} else {
						$observed[ $meta_key ] = 'already_absent';
					}
				}
			}

			$report['variants'][ $variant_id ] = [
				'status'   => $failed ? 'failed' : 'propagated',
				'observed' => $observed,
			];
		}

		return $report;
	}
}
