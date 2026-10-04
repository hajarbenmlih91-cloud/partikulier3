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
		$wpdb->query('START TRANSACTION');
		try { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.Discarded

			$lead_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$leads} WHERE phone_hash = %s FOR UPDATE", $hash));
			if ( ! $lead_id ) {
				$res = $wpdb->insert($leads, [
					'phone_hash'      => $hash,
					'phone_encrypted' => self::encrypt_phone($wa_id),
					'first_seen_at'   => $now,
					'last_seen_at'    => $now,
				]);
				if ( false === $res && str_contains((string) $wpdb->last_error, 'Deadlock') ) {
					throw new \RuntimeException('Deadlock on leads insert: '.$wpdb->last_error);
				}
				$lead_id = (int) $wpdb->insert_id;
			} else {
				$wpdb->update($leads, ['last_seen_at' => $now], ['id' => $lead_id]);
			}
			$lead = $wpdb->get_row($wpdb->prepare("SELECT opt_out_at, is_particulier, qualification_asked_at FROM {$leads} WHERE id = %d FOR UPDATE", $lead_id));
			if ( $lead && $lead->opt_out_at ) {
				$wpdb->query('ROLLBACK');
				return new \WP_REST_Response(['allowed' => false, 'reason' => 'opted_out'], 200);
			}
			// R2 : filtre particulier / intermédiaire — on ne demande qu'une fois
			$is_part = $lead ? $lead->is_particulier : null;
			// is_particulier est tinyint NULL : NULL=unknown, 1=particulier, 0=intermédiaire
			if ( null === $is_part || '' === $is_part ) {
				// Première fois : on pose la question, on ne donne pas le numéro
				if ( empty($lead->qualification_asked_at) ) {
					$wpdb->update($leads, ['qualification_asked_at' => $now], ['id' => $lead_id]);
					$wpdb->query('COMMIT');
					$lang = self::detect_lang_for_lead($wa_id, $property_id, $message_text);
					if ( class_exists(LeadSettings::class) ) {
						$msg = LeadSettings::get_message('need_qualification', $lang);
					} else {
						$msg = $lang === 'ar' ? 'هل أنت particulier أم وسيط؟' : 'Vous êtes particulier ou intermédiaire ?';
					}
					return new \WP_REST_Response(['allowed' => false, 'reason' => 'need_qualification', 'question' => $msg, 'lead_id' => $lead_id], 200);
				}
				// Déjà demandé mais pas encore répondu
				$wpdb->query('ROLLBACK');
				$lang = self::detect_lang_for_lead($wa_id, $property_id, $message_text);
				if ( class_exists(LeadSettings::class) ) {
					$msg = LeadSettings::get_message('need_qualification', $lang);
				} else {
					$msg = $lang === 'ar' ? 'هل أنت particulier أم وسيط؟' : 'Vous êtes particulier ou intermédiaire ?';
				}
				return new \WP_REST_Response(['allowed' => false, 'reason' => 'need_qualification_pending', 'question' => $msg, 'lead_id' => $lead_id], 200);
			}
			if ( (int) $is_part === 0 ) {
				$wpdb->query('ROLLBACK');
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
			$count24 = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$interests} WHERE lead_id = %d AND created_at >= DATE_SUB(%s, INTERVAL %d SECOND)", $lead_id, $now, $win24));
			if ( $count24 >= $max24 ) {
				$wpdb->query('ROLLBACK');
				$wpdb->query('START TRANSACTION');
				$wpdb->insert($interests, [
					'lead_id'             => $lead_id,
					'property_id'         => $property_id,
					'reference_code'      => self::reference_for($property_id),
					'property_snapshot'   => wp_json_encode(self::property_snapshot($property_id)),
					'provider_message_id' => $provider_message_id . '_manual_24h',
					'created_at'          => $now,
				]);
				$wpdb->query('COMMIT');
				$lang = self::detect_lang_for_lead($wa_id, $property_id, $message_text);
				if ( class_exists(LeadSettings::class) ) {
					$msg = LeadSettings::get_message('manual_review', $lang);
				} else {
					$msg = $lang === 'ar' ? 'لأسباب أمنية، سيتم إرسال جهة الاتصال يدويا. شكرا لصبركم.' : 'Pour des raisons de sécurité, l’envoi du contact se fera manuellement. Merci de votre patience.';
				}
				return new \WP_REST_Response(['allowed' => false, 'reason' => 'manual_review', 'message' => $msg, 'lead_id' => $lead_id, 'limit' => '24h_3contacts'], 200);
			}
			// R3 : plafond hebdo éditable — on compte uniquement les envois automatiques (sans _manual)
			$count7d = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$interests} WHERE lead_id = %d AND created_at >= DATE_SUB(%s, INTERVAL %d SECOND) AND provider_message_id NOT LIKE %s", $lead_id, $now, $win7d, '%_manual%'));
			if ( $count7d >= $max7d ) {
				$wpdb->query('ROLLBACK');
				$wpdb->query('START TRANSACTION');
				$wpdb->insert($interests, [
					'lead_id'             => $lead_id,
					'property_id'         => $property_id,
					'reference_code'      => self::reference_for($property_id),
					'property_snapshot'   => wp_json_encode(self::property_snapshot($property_id)),
					'provider_message_id' => $provider_message_id . '_manual_7d',
					'created_at'          => $now,
				]);
				$wpdb->query('COMMIT');
				$lang = self::detect_lang_for_lead($wa_id, $property_id, $message_text);
				if ( class_exists(LeadSettings::class) ) {
					$msg = LeadSettings::get_message('manual_review', $lang);
				} else {
					$msg = $lang === 'ar' ? 'لأسباب أمنية، سيتم إرسال جهة الاتصال يدويا. شكرا لصبركم.' : 'Pour des raisons de sécurité, l’envoi du contact se fera manuellement. Merci de votre patience.';
				}
				return new \WP_REST_Response(['allowed' => false, 'reason' => 'manual_review', 'message' => $msg, 'lead_id' => $lead_id, 'limit' => '7d_5contacts'], 200);
			}

			$seen = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$messages} WHERE provider_message_id = %s FOR UPDATE", $provider_message_id));
			if ( $seen ) {
				$wpdb->query('ROLLBACK');
				return new \WP_REST_Response(['allowed' => false, 'reason' => 'duplicate_message'], 200);
			}
			$resMsg = $wpdb->insert($messages, ['provider_message_id' => $provider_message_id, 'lead_id' => $lead_id, 'direction' => 'inbound', 'message_type' => 'property_interest', 'created_at' => $now]);
			if ( false === $resMsg && str_contains((string) $wpdb->last_error, 'Deadlock') ) {
				throw new \RuntimeException('Deadlock on messages insert: '.$wpdb->last_error);
			}
			$resInt = $wpdb->insert($interests, [
				'lead_id'             => $lead_id,
				'property_id'         => $property_id,
				'reference_code'      => self::reference_for($property_id),
				'property_snapshot'   => wp_json_encode(self::property_snapshot($property_id)),
				'provider_message_id' => $provider_message_id,
				'created_at'          => $now,
			]);
			if ( false === $resInt && str_contains((string) $wpdb->last_error, 'Deadlock') ) {
				throw new \RuntimeException('Deadlock on interests insert: '.$wpdb->last_error);
			}

			$existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$disclosures} WHERE lead_id = %d AND property_id = %d FOR UPDATE", $lead_id, $property_id));
			if ( $existing ) {
				$wpdb->query('COMMIT');
				return new \WP_REST_Response(array_merge(self::contact_response($property_id, true), ['lead_id' => $lead_id]), 200);
			}

			// La limite porte sur des propriétaires distincts : deux annonces du même
			// propriétaire dans la journée ne consomment qu'un seul contact.
			$known_owner = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$disclosures} WHERE lead_id = %d AND owner_id = %d AND day_key = %s FOR UPDATE", $lead_id, $owner_id, $day));
			if ( ! $known_owner ) {
				$wpdb->query($wpdb->prepare("INSERT INTO {$limits} (lead_id, day_key, contacts_count) VALUES (%d, %s, 0) ON DUPLICATE KEY UPDATE contacts_count = contacts_count", $lead_id, $day));
				$used = (int) $wpdb->get_var($wpdb->prepare("SELECT contacts_count FROM {$limits} WHERE lead_id = %d AND day_key = %s FOR UPDATE", $lead_id, $day));
				if ( $used >= self::daily_limit() ) {
					$wpdb->query('COMMIT');
					return new \WP_REST_Response(['allowed' => false, 'reason' => 'daily_limit', 'limit' => self::daily_limit()], 200);
				}

			$resDisc = $wpdb->insert($disclosures, ['lead_id' => $lead_id, 'property_id' => $property_id, 'owner_id' => $owner_id, 'day_key' => $day, 'sent_at' => $now]);
			if ( false === $resDisc && str_contains((string) $wpdb->last_error, 'Deadlock') ) {
				throw new \RuntimeException('Deadlock on disclosures insert: '.$wpdb->last_error);
			}
			if ( ! $known_owner ) {
				$wpdb->query($wpdb->prepare("UPDATE {$limits} SET contacts_count = contacts_count + 1 WHERE lead_id = %d AND day_key = %s", $lead_id, $day));
			}
			self::audit('lead_authorized', 'lead', $lead_id, [
				'property_id' => $property_id,
				'owner_id'    => $owner_id,
				'replayed'    => false,
			]);
			return new \WP_REST_Response(array_merge(self::contact_response($property_id, false), ['lead_id' => $lead_id]), 200);
		} catch ( \Throwable $error ) {
			$wpdb->query('ROLLBACK');
			error_log('[PK authorize_contact] attempt '.($attempt+1).' ' . $error->getMessage() . ' at ' . $error->getFile() . ':' . $error->getLine());
			if ( $attempt < 2 && ( str_contains($error->getMessage(), 'Deadlock') || str_contains((string) $wpdb->last_error, 'Deadlock') ) ) {
				usleep(50000 + random_int(0, 100000));
				continue;
			}
			return new \WP_Error('pk_contact_transaction_failed', __('La demande de contact ne peut pas être traitée pour le moment.', 'partikulier-core'), ['status' => 500]);
		}
		// Deadlock sans exception : $wpdb->last_error contient 'Deadlock' après un INSERT/SELECT échoué.
		if ( str_contains((string) $wpdb->last_error, 'Deadlock') && $attempt < 2 ) {
			$wpdb->query('ROLLBACK');
			error_log('[PK authorize_contact] deadlock via last_error attempt '.($attempt+1).' '.$wpdb->last_error);
			usleep(50000 + random_int(0, 100000));
			continue;
		}
		break;
		} // end for retry
		// Si on sort du for sans return, c'est qu'un deadlock a persisté 3 fois.
		return new \WP_Error('pk_contact_transaction_failed', __('La demande de contact ne peut pas être traitée pour le moment.', 'partikulier-core'), ['status' => 500]);
	}


	/**
	 * Pont REST du dispositif de leads (INTEG-2, appelé par LeadBridge).
	 *
	 * Mêmes contrôles, même plafonnement, même journalisation que la voie
	 * WhatsApp/n8n, sans distinction du canal. Le rattachement à une annonce
	 * est obligatoire ; le contexte déclaratif (nom, courriel, message, source)
	 * est conservé avec le suivi du lead (pk_lead_followups.note).
	 *
	 * @param array $input {phone, property_id|reference, message?, name?, email?}
	 * @return array|WP_Error {lead_id, property_id, reference, contact} ou refus motivé.
	 */
	public static function register_api_lead( array $input ): array|\WP_Error
	{
		$phone       = preg_replace('/\D+/', '', (string) ( $input['phone'] ?? '' ));
		$property_id = absint($input['property_id'] ?? 0);
		$reference   = sanitize_text_field( (string) ( $input['reference'] ?? '' ));
		if ( ! $phone || strlen($phone) < 8 ) {
			return new \WP_Error('invalid_lead_phone', __('Numéro de téléphone invalide.', 'partikulier-core'), ['status' => 422]);
		}
		if ( ! $property_id && $reference ) {
			$property_id = self::property_for_reference($reference);
		}
		$post = $property_id ? get_post($property_id) : null;
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return new \WP_Error('lead_property_not_found', __('Annonce visée introuvable.', 'partikulier-core'), ['status' => 404]);
		}
		if ( ! self::is_contactable_property($property_id) ) {
			return new \WP_Error('lead_property_unavailable', __('Annonce visée non contactable.', 'partikulier-core'), ['status' => 409]);
		}

		$message_id = 'api-' . wp_generate_uuid4();
		$result     = self::authorize_contact($phone, $property_id, $message_id);
		if ( is_wp_error($result) ) {
			return $result;
		}
		$data = $result instanceof \WP_REST_Response ? (array) $result->get_data() : (array) $result;

		$reasons = [
			'property_unavailable' => ['lead_property_unavailable', 409],
			'owner_unavailable'    => ['lead_owner_unavailable', 409],
			'opted_out'            => ['lead_opted_out', 403],
			'duplicate_message'    => ['lead_duplicate', 409],
			'daily_limit'          => ['lead_daily_limit', 429],
		];
		if ( empty($data['allowed']) ) {
			$reason          = (string) ( $data['reason'] ?? 'unknown' );
			[$code, $status] = $reasons[ $reason ] ?? ['lead_refused', 409];
			return new \WP_Error($code, sprintf(__('Demande refusée : %s.', 'partikulier-core'), $reason), ['status' => $status, 'reason' => $reason]);
		}

		// Suivi du lead : contexte déclaratif du canal API, conservé avec
		// la demande (le dispositif n'a pas de colonne dédiée — pas de
		// seconde zone de stockage).
		$lead_id = (int) ( $data['lead_id'] ?? 0 );
		$context = [
			'source'      => 'rest_api',
			'name'        => sanitize_text_field( (string) ( $input['name'] ?? '' )),
			'email'       => sanitize_email( (string) ( $input['email'] ?? '' )),
			'message'     => sanitize_textarea_field( (string) ( $input['message'] ?? '' )),
			'property_id' => $property_id,
		];
		self::seed_followup($lead_id, $context);

		unset($data['lead_id']);
		return [
			'lead_id'     => $lead_id,
			'property_id' => $property_id,
			'reference'   => self::reference_for($property_id),
			'contact'     => $data,
		];
	}


	/* ------------------------------------------------------------------ */
	/* Suivi contextuel (pont REST)                                        */
	/* ------------------------------------------------------------------ */

	/**
	 * Crée ou met à jour la ligne de suivi d'un lead avec le contexte du
	 * canal d'entrée (pont REST) sans toucher au reste du dispositif.
	 */
	private static function seed_followup( int $lead_id, array $context ): void
	{
		if ( ! $lead_id ) {
			return;
		}
		global $wpdb;
		$followups = self::table('pk_lead_followups');
		$existing  = $wpdb->get_var($wpdb->prepare("SELECT lead_id FROM {$followups} WHERE lead_id = %d", $lead_id));
		$row       = [
			'status'     => 'new',
			'note'       => wp_json_encode($context, JSON_UNESCAPED_UNICODE),
			'updated_at' => current_time('mysql', true),
		];
		if ( $existing ) {
			$wpdb->update($followups, $row, ['lead_id' => $lead_id]);
		} else {
			$row['lead_id'] = $lead_id;
			$wpdb->insert($followups, $row);
		}
	}

	/**
	 * R2 : détecte la langue pour le message de qualification / manuel.
	 * Priorité : 1) Polylang pll_get_post_language, 2) meta _locale,
	 * 3) get_locale, 4) fr par défaut.
	 */
	private static function detect_lang_for_lead( string $wa_id, int $property_id, string $message_text = '' ): string
	{
		// 0) Contenu du message : si caractères arabes → ar (priorité, même si bien en fr)
		if ( '' !== $message_text && preg_match('/[\x{0600}-\x{06FF}]/u', $message_text) ) {
			return 'ar';
		}
		// 1) Polylang si présent
		if ( function_exists('pll_get_post_language') ) {
			$pll = (string) pll_get_post_language($property_id, 'slug');
			if ( $pll === 'ar' ) return 'ar';
			if ( $pll === 'en' ) return 'en';
			if ( $pll === 'fr' ) return 'fr';
		}
		// 2) meta _locale (test / R1)
		$locale = (string) get_post_meta($property_id, '_locale', true);
		if ( '' !== $locale ) {
			if ( str_starts_with($locale, 'ar') ) return 'ar';
			if ( str_starts_with($locale, 'en') ) return 'en';
			return 'fr';
		}
		$locale = (string) get_locale();
		if ( str_starts_with($locale, 'ar') ) return 'ar';
		if ( str_starts_with($locale, 'en') ) return 'en';
		return 'fr';
	}

	/**
	 * R2 : enregistre la réponse à "particulier ou intermédiaire ?"
	 * Appelé par n8n quand l'utilisateur répond. Une fois répondu, on ne
	 * redemande plus (is_particulier reste figé sauf maj manuelle WP/Sheets).
	 *
	 * @return bool
	 */
	public static function set_qualification( string $wa_id, bool $is_particulier ): bool
	{
		global $wpdb;
		$hash = hash_hmac('sha256', $wa_id, wp_salt('auth'));
		$leads = self::leads_table();
		$lead_id = (int) $wpdb->get_var($wpdb->prepare("SELECT id FROM {$leads} WHERE phone_hash = %s", $hash));
		if ( ! $lead_id ) return false;
		$wpdb->update($leads, ['is_particulier' => $is_particulier ? 1 : 0], ['id' => $lead_id]);
		// Log audit
		self::audit($is_particulier ? 'lead_qualified_particulier' : 'lead_qualified_intermediaire', 'lead', $lead_id, ['wa_id_hash' => substr($hash,0,8)]);
		return true;
	}

	/**
	 * R2 : handler REST pour n8n — POST /qualification {wa_id, is_particulier, provider_message_id}
	 */
	public static function rest_set_qualification( \WP_REST_Request $request )
	{
		$wa_id = self::normalize_phone((string) $request->get_param('wa_id'));
		$is_part = rest_sanitize_boolean($request->get_param('is_particulier'));
		// Accepte aussi "particulier"/"intermediaire" en string
		$raw = strtolower(trim((string) $request->get_param('is_particulier')));
		if ( in_array($raw, ['1','true','particulier','oui','yes'], true) ) $is_part = true;
		if ( in_array($raw, ['0','false','intermediaire','intermédiaire','agent','non'], true) ) $is_part = false;
		if ( ! $wa_id ) {
			return new \WP_Error('pk_missing_wa_id', 'wa_id requis', ['status'=>400]);
		}
		$ok = self::set_qualification($wa_id, (bool) $is_part);
		if ( ! $ok ) return new \WP_Error('pk_unknown_lead', 'lead introuvable', ['status'=>404]);
		return new \WP_REST_Response(['qualified' => $is_part ? 'particulier' : 'intermediaire'], 200);
	}
}
