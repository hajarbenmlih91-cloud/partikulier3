<?php
/**
 * Cœur transactionnel du dispositif de leads (lot B2) : authorize_contact
 * (transaction SQL, plafonnement par propriétaires distincts) et le pont REST
 * register_api_lead (INTEG-2) avec son suivi contextuel seed_followup.
 *
 * Lot D (découpage, arbitrage commanditaire « Référence + plugin », CDC
 * v1.2 annexe C) : le service LeadService (778 lignes, code porté par la campagne
 * au lot B2) est découpé en shell + traits sur le précédent B6
 * (class-localization.php 990 → 199 l.) — les méthodes sont déplacées
 * VERBATIM, l'API publique et les hooks restent portés par la classe shell
 * (le trait compose la même classe : aucune délégation, aucun changement de
 * mécanisme actif). Preuve : contrat module-perimeter-contract.php + rejeu
 * intégral des suites du domaine (oracle inchangé).
 *
 * @package Partikulier\Core
 */

declare(strict_types=1);

namespace Partikulier\Core\Domain\Leads;

trait LeadsContactTrait
{


	/* ------------------------------------------------------------------ */
	/* Cœur transactionnel (voie n8n et voie REST — même code)             */
	/* ------------------------------------------------------------------ */

	/**
	 * @param string $wa_id Numéro normalisé du demandeur.
	 * @param int    $property_id Annonce visée (rattachement obligatoire).
	 * @param string $provider_message_id Identifiant unique de l'événement.
	 * @param string $message_text Texte du message WhatsApp (optionnel, pour détection langue ar).
	 * @return WP_REST_Response|WP_Error { allowed, replayed, property, owner, lead_id } ou motif de refus.
	 */
	public static function authorize_contact( string $wa_id, int $property_id, string $provider_message_id, string $message_text = '' )
	{
		global $wpdb;
		$leads       = self::leads_table();
		$messages    = self::table('pk_whatsapp_messages');
		$interests   = self::table('pk_interest_events');
		$limits      = self::table('pk_contact_limits');
		$disclosures = self::table('pk_contact_disclosures');
		$now         = current_time('mysql', true);
		$day         = current_time('Y-m-d');
		$hash        = hash_hmac('sha256', $wa_id, wp_salt('auth'));
		$owner_id    = (int) get_post_field('post_author', $property_id);
		$raw_phone   = (string) get_post_meta($property_id, '_pk_owner_phone', true);
		$owner_phone = class_exists('\\Partikulier_Crypto') ? \Partikulier_Crypto::read_phone($raw_phone) : $raw_phone;

		if ( ! $owner_id || ! $owner_phone ) {
			return new \WP_REST_Response(['allowed' => false, 'reason' => 'owner_unavailable'], 200);
		}

		// Senior fix 03/10 : retry sur deadlock InnoDB (10 parallèles distinct phones -> gap lock).
		// 3 tentatives max, backoff 50-150ms aléatoire. Sans retry, 1/10 distinct phones = 500 transient.
		for ( $attempt = 0; $attempt < 3; $attempt++ ) {
		try { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.Discarded
			self::contact_database('query', 'START TRANSACTION');

			$lead_id = (int) self::contact_database('get_var', $wpdb->prepare("SELECT id FROM {$leads} WHERE phone_hash = %s FOR UPDATE", $hash));
			if ( ! $lead_id ) {
				self::contact_database('insert', $leads, [
					'phone_hash'      => $hash,
					'phone_encrypted' => self::encrypt_phone($wa_id),
					'first_seen_at'   => $now,
					'last_seen_at'    => $now,
				]);
				$lead_id = (int) $wpdb->insert_id;
			} else {
				self::contact_database('update', $leads, ['last_seen_at' => $now], ['id' => $lead_id]);
			}
			$lead = self::contact_database('get_row', $wpdb->prepare("SELECT opt_out_at, is_particulier, qualification_asked_at FROM {$leads} WHERE id = %d FOR UPDATE", $lead_id));
			if ( ! $lead ) {
				throw new \RuntimeException('Contact lead missing after lookup or insert');
			}
			if ( $lead && $lead->opt_out_at ) {
				self::contact_database('query', 'ROLLBACK');
				return new \WP_REST_Response(['allowed' => false, 'reason' => 'opted_out'], 200);
			}
			// R2 : filtre particulier / intermédiaire — on ne demande qu'une fois
			$is_part = $lead ? $lead->is_particulier : null;
			// is_particulier est tinyint NULL : NULL=unknown, 1=particulier, 0=intermédiaire
			if ( null === $is_part || '' === $is_part ) {
				// Première fois : on pose la question, on ne donne pas le numéro
				if ( empty($lead->qualification_asked_at) ) {
					self::contact_database('update', $leads, ['qualification_asked_at' => $now], ['id' => $lead_id]);
					self::contact_database('query', 'COMMIT');
					$lang = self::detect_lang_for_lead($wa_id, $property_id, $message_text);
					if ( class_exists(LeadSettings::class) ) {
						$msg = LeadSettings::get_message('need_qualification', $lang);
					} else {
						$msg = $lang === 'ar' ? 'هل أنت particulier أم وسيط؟' : 'Vous êtes particulier ou intermédiaire ?';
					}
					return new \WP_REST_Response(['allowed' => false, 'reason' => 'need_qualification', 'question' => $msg, 'lead_id' => $lead_id], 200);
				}
				// Déjà demandé mais pas encore répondu
				self::contact_database('query', 'ROLLBACK');
				$lang = self::detect_lang_for_lead($wa_id, $property_id, $message_text);
				if ( class_exists(LeadSettings::class) ) {
					$msg = LeadSettings::get_message('need_qualification', $lang);
				} else {
					$msg = $lang === 'ar' ? 'هل أنت particulier أم وسيط؟' : 'Vous êtes particulier ou intermédiaire ?';
				}
				return new \WP_REST_Response(['allowed' => false, 'reason' => 'need_qualification_pending', 'question' => $msg, 'lead_id' => $lead_id], 200);
			}
			if ( (int) $is_part === 0 ) {
				self::contact_database('query', 'ROLLBACK');
				$lang = self::detect_lang_for_lead($wa_id, $property_id, $message_text);
				if ( class_exists(LeadSettings::class) ) {
					$msg = LeadSettings::get_message('intermediary_refused', $lang);
				} else {
					$msg = $lang === 'ar' ? 'المالك يرفض الوسطاء. شكرا لتفهمكم.' : 'Le propriétaire refuse les intermédiaires. Merci de votre compréhension.';
				}
				return new \WP_REST_Response(['allowed' => false, 'reason' => 'intermediary_refused', 'message' => $msg, 'lead_id' => $lead_id], 200);
			}
			// R3 : seuils éditables via LeadSettings (source de vérité WP) — fenêtre stockée en h/j, convertie en s pour SQL
			$max24  = class_exists(LeadSettings::class) ? (int) LeadSettings::get_limit('max_24h') : 2;
			$win24h = class_exists(LeadSettings::class) ? (int) LeadSettings::get_limit('window_24h') : 24;
			$max7d  = class_exists(LeadSettings::class) ? (int) LeadSettings::get_limit('max_7d') : 5;
			$win7dj = class_exists(LeadSettings::class) ? (int) LeadSettings::get_limit('window_7d') : 7;
			$win24  = max(1, $win24h) * 3600;
			$win7d  = max(1, $win7dj) * 86400;
			$count24 = (int) self::contact_database('get_var', $wpdb->prepare("SELECT COUNT(*) FROM {$interests} WHERE lead_id = %d AND created_at >= DATE_SUB(%s, INTERVAL %d SECOND)", $lead_id, $now, $win24));
			if ( $count24 >= $max24 ) {
				self::contact_database('query', 'ROLLBACK');
				self::contact_database('query', 'START TRANSACTION');
				self::contact_database('insert', $interests, [
					'lead_id'             => $lead_id,
					'property_id'         => $property_id,
					'reference_code'      => self::reference_for($property_id),
					'property_snapshot'   => wp_json_encode(self::property_snapshot($property_id)),
					'provider_message_id' => $provider_message_id . '_manual_24h',
					'created_at'          => $now,
				]);
				self::contact_database('query', 'COMMIT');
				$lang = self::detect_lang_for_lead($wa_id, $property_id, $message_text);
				if ( class_exists(LeadSettings::class) ) {
					$msg = LeadSettings::get_message('manual_review', $lang);
				} else {
					$msg = $lang === 'ar' ? 'لأسباب أمنية، سيتم إرسال جهة الاتصال يدويا. شكرا لصبركم.' : 'Pour des raisons de sécurité, l’envoi du contact se fera manuellement. Merci de votre patience.';
				}
				return new \WP_REST_Response(['allowed' => false, 'reason' => 'manual_review', 'message' => $msg, 'lead_id' => $lead_id, 'limit' => '24h_3contacts'], 200);
			}
			// R3 : plafond hebdo éditable — on compte uniquement les envois automatiques (sans _manual)
			$count7d = (int) self::contact_database('get_var', $wpdb->prepare("SELECT COUNT(*) FROM {$interests} WHERE lead_id = %d AND created_at >= DATE_SUB(%s, INTERVAL %d SECOND) AND provider_message_id NOT LIKE %s", $lead_id, $now, $win7d, '%_manual%'));
			if ( $count7d >= $max7d ) {
				self::contact_database('query', 'ROLLBACK');
				self::contact_database('query', 'START TRANSACTION');
				self::contact_database('insert', $interests, [
					'lead_id'             => $lead_id,
					'property_id'         => $property_id,
					'reference_code'      => self::reference_for($property_id),
					'property_snapshot'   => wp_json_encode(self::property_snapshot($property_id)),
					'provider_message_id' => $provider_message_id . '_manual_7d',
					'created_at'          => $now,
				]);
				self::contact_database('query', 'COMMIT');
				$lang = self::detect_lang_for_lead($wa_id, $property_id, $message_text);
				if ( class_exists(LeadSettings::class) ) {
					$msg = LeadSettings::get_message('manual_review', $lang);
				} else {
					$msg = $lang === 'ar' ? 'لأسباب أمنية، سيتم إرسال جهة الاتصال يدويا. شكرا لصبركم.' : 'Pour des raisons de sécurité, l’envoi du contact se fera manuellement. Merci de votre patience.';
				}
				return new \WP_REST_Response(['allowed' => false, 'reason' => 'manual_review', 'message' => $msg, 'lead_id' => $lead_id, 'limit' => '7d_5contacts'], 200);
			}

			$seen = (int) self::contact_database('get_var', $wpdb->prepare("SELECT id FROM {$messages} WHERE provider_message_id = %s FOR UPDATE", $provider_message_id));
			if ( $seen ) {
				self::contact_database('query', 'ROLLBACK');
				return new \WP_REST_Response(['allowed' => false, 'reason' => 'duplicate_message'], 200);
			}
			self::contact_database('insert', $messages, ['provider_message_id' => $provider_message_id, 'lead_id' => $lead_id, 'direction' => 'inbound', 'message_type' => 'property_interest', 'created_at' => $now]);
			self::contact_database('insert', $interests, [
				'lead_id'             => $lead_id,
				'property_id'         => $property_id,
				'reference_code'      => self::reference_for($property_id),
				'property_snapshot'   => wp_json_encode(self::property_snapshot($property_id)),
				'provider_message_id' => $provider_message_id,
				'created_at'          => $now,
			]);

			$existing = self::contact_database('get_var', $wpdb->prepare("SELECT id FROM {$disclosures} WHERE lead_id = %d AND property_id = %d FOR UPDATE", $lead_id, $property_id));
			if ( $existing ) {
				self::contact_database('query', 'COMMIT');
				return new \WP_REST_Response(array_merge(self::contact_response($property_id, true), ['lead_id' => $lead_id]), 200);
			}

			// La limite porte sur des propriétaires distincts : deux annonces du même
			// propriétaire dans la journée ne consomment qu'un seul contact.
			$known_owner = (int) self::contact_database('get_var', $wpdb->prepare("SELECT id FROM {$disclosures} WHERE lead_id = %d AND owner_id = %d AND day_key = %s FOR UPDATE", $lead_id, $owner_id, $day));
			if ( ! $known_owner ) {
				self::contact_database('query', $wpdb->prepare("INSERT INTO {$limits} (lead_id, day_key, contacts_count) VALUES (%d, %s, 0) ON DUPLICATE KEY UPDATE contacts_count = contacts_count", $lead_id, $day));
				$used = (int) self::contact_database('get_var', $wpdb->prepare("SELECT contacts_count FROM {$limits} WHERE lead_id = %d AND day_key = %s FOR UPDATE", $lead_id, $day));
				if ( $used >= self::daily_limit() ) {
					self::contact_database('query', 'COMMIT');
					return new \WP_REST_Response(['allowed' => false, 'reason' => 'daily_limit', 'limit' => self::daily_limit()], 200);
				}
			}

			self::contact_database('insert', $disclosures, ['lead_id' => $lead_id, 'property_id' => $property_id, 'owner_id' => $owner_id, 'day_key' => $day, 'sent_at' => $now]);
			if ( ! $known_owner ) {
				self::contact_database('query', $wpdb->prepare("UPDATE {$limits} SET contacts_count = contacts_count + 1 WHERE lead_id = %d AND day_key = %s", $lead_id, $day));
			}
			self::contact_database('query', 'COMMIT');
			self::audit('lead_authorized', 'lead', $lead_id, [
				'property_id' => $property_id,
				'owner_id'    => $owner_id,
				'replayed'    => false,
			]);
			return new \WP_REST_Response(array_merge(self::contact_response($property_id, false), ['lead_id' => $lead_id]), 200);
		} catch ( \Throwable $error ) {
			$rolled_back = $wpdb->query('ROLLBACK');
			error_log('[PK authorize_contact] attempt '.($attempt+1).' ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine());
			if ( false === $rolled_back ) {
				error_log('[PK authorize_contact] rollback failed: ' . $wpdb->last_error);
			}
			if ( false !== $rolled_back && $attempt < 2 && str_contains($error->getMessage(), 'Deadlock') ) {
				usleep(50000 + random_int(0, 100000));
				continue;
			}
			return new \WP_Error('pk_contact_transaction_failed', __('La demande de contact ne peut pas être traitée pour le moment.', 'partikulier-core'), ['status' => 500]);
		}
		} // end for retry
		// Si on sort du for sans return, c'est qu'un deadlock a persisté 3 fois.
		return new \WP_Error('pk_contact_transaction_failed', __('La demande de contact ne peut pas être traitée pour le moment.', 'partikulier-core'), ['status' => 500]);
	}

	private static function contact_database( string $method, ...$args )
	{
		global $wpdb;
		$result = $wpdb->{$method}(...$args);
		if ( false === $result || '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException('Contact SQL ' . $method . ': ' . ($wpdb->last_error ?: 'operation failed'));
		}
		return $result;
	}

}
