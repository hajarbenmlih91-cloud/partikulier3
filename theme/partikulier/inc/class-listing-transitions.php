<?php
/**
 * Module : moteur de transitions DP-9 (SE-044 / DP-9 — cadrage v1.1 + addendum R1).
 *
 * Pipeline d'exécution unique (R1 §4-R1) pour TOUTES les actions propriétaire,
 * sur les DEUX entrées (AJAX pk_manage_listing et REST /owner/listings/{id}/action) :
 *  ① résolution canonique de la variante vers la source (R1 §2.2) ;
 *  ② contrôle matriciel de la source (v1.1 §3.1 + amendement R1 (e)) — default-deny ;
 *  ③ précontrôle du groupe avant toute écriture (R1 §2.3-R1) ;
 *  ④ exécution compare–write–verify (R1 §4-R1 (a)), propagation avec ignorés
 *     consignés, invalidation exécutée et confirmée (R1 §4-R1 (c)) ;
 *  ⑤ réponse : 200 avec rapport d'étapes, ou 500 pk_partial_failure avec le
 *     même rapport structuré, ou refus 4xx AVANT toute écriture.
 *
 * Parcours unique (v1.1 §4) : « Désactiver mon annonce » avec motif obligatoire
 * (vendu / loue / changement d'avis / autre + texte privé), puis « Réactiver ».
 * Les anciennes actions (mark_sold, mark_rented, pause, archive, delete) sont
 * retirées de la liste blanche : 400 pk_listing_action_retired, état inchangé.
 *
 * Aucune écriture de ce module ne touche aux métas premium (E-4807 : le droit
 * premium est indépendant de la disponibilité).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Listing_Transitions {

        /** Actions du parcours unique (liste blanche serveur). */
        public const ALLOWED_ACTIONS = array( 'deactivate', 'reactivate' );

        /** Anciennes actions retirées — réponse 400 explicite, état strictement inchangé. */
        public const RETIRED_ACTIONS = array( 'mark_sold', 'mark_rented', 'pause', 'archive', 'delete' );

        /** Motifs de désactivation admis (v1.1 §4.1 [ACQUIS]). */
        public const DEACTIVATION_REASONS = array( 'vendu', 'loue', 'avis', 'autre' );

        /** Marqueurs administratifs — jamais écrasés par une action propriétaire (v1.1 §3.2, R1 §2.3). */
        public const ADMIN_MARKERS = array( 'refuse', 'en_attente_whatsapp' );

        /** Statuts métier de fermeture propriétaire (disponibilité fermée). */
        public const CLOSED_STATUSES = array( 'vendu', 'loue', 'loué', 'indisponible', 'archive' );

        /**
         * Transition unique — point d'entrée partagé AJAX + REST.
         *
         * @param int    $post_id Identifiant brut tel que reçu (source ou variante).
         * @param string $action  deactivate|reactivate.
         * @param int    $user_id Utilisateur agissant.
         * @param string $reason  Motif (deactivate) : vendu|loue|avis|autre.
         * @param string $note    Texte privé (motif autre uniquement).
         * @return array|WP_Error Rapport 200 {message, status, canonical_id, steps} ou WP_Error (4xx/500 + rapport).
         */
        public static function transition( $post_id, $action, $user_id, $reason = '', $note = '' ) {
                $post_id = absint( $post_id );
                $action  = sanitize_key( (string) $action );
                $user_id = absint( $user_id );
                $reason  = sanitize_key( (string) $reason );
                $note    = sanitize_textarea_field( (string) $note );
                if ( strlen( $note ) > 500 ) {
                        $note = mb_substr( $note, 0, 500 );
                }

                $steps = array();

                /* --- ⓪ Nom d'action ---------------------------------------------------- */

                if ( in_array( $action, self::RETIRED_ACTIONS, true ) ) {
                        return new WP_Error(
                                'pk_listing_action_retired',
                                __( 'Cette action a été retirée. Utilisez « Désactiver mon annonce » avec un motif, puis « Réactiver ».', 'partikulier' ),
                                array( 'status' => 400 )
                        );
                }
                if ( ! in_array( $action, self::ALLOWED_ACTIONS, true ) ) {
                        return new WP_Error( 'pk_listing_action_invalid', __( 'Action invalide.', 'partikulier' ), array( 'status' => 400 ) );
                }

                if ( ! $post_id || get_post_type( $post_id ) !== PARTIKULIER_ESTATIK_POST_TYPE ) {
                        return new WP_Error( 'pk_listing_missing', __( 'Annonce introuvable.', 'partikulier' ), array( 'status' => 404 ) );
                }

                /* --- ① Résolution canonique (R1 §2.2) ---------------------------------- */

                $resolution = self::resolve_canonical( $post_id );
                if ( is_wp_error( $resolution ) ) {
                        return $resolution; // 409 pk_variant_resolution — aucune écriture.
                }
                $source_id = $resolution['source_id'];
                $steps[]   = array(
                        'step'     => 'canonical_resolution',
                        'status'   => 'done',
                        'observed' => array(
                                'received_id' => $post_id,
                                'source_id'   => $source_id,
                                'was_variant' => $resolution['was_variant'],
                        ),
                );

                /* --- Contrôle de propriété sur l'entité résolue (R1 §2.2 point 2) ------ */

                $is_owner = $user_id === (int) get_post_field( 'post_author', $source_id );
                if ( ! $is_owner && ! user_can( $user_id, 'manage_options' ) ) {
                        return new WP_Error( 'pk_listing_forbidden', __( 'Vous n’êtes pas autorisé à modifier cette annonce.', 'partikulier' ), array( 'status' => 403 ) );
                }

                /* --- ② Contrôle matriciel de la source (v1.1 §3.1 + R1 (e)) ------------ */

                $post_status    = get_post_status( $source_id );
                $status_meta    = (string) get_post_meta( $source_id, '_pk_status', true );
                $matrix_verdict = self::matrix_verdict( $post_status, $status_meta, $action );

                if ( 'refuse_403' === $matrix_verdict['verdict'] || 'forbidden_403' === $matrix_verdict['verdict'] || 'republication_403' === $matrix_verdict['verdict'] ) {
                        $code = 'republication_403' === $matrix_verdict['verdict'] ? 'pk_republication_refused' : 'pk_listing_forbidden';
                        return new WP_Error( $code, $matrix_verdict['message'], array( 'status' => 403 ) );
                }
                if ( 'invalid_reason_400' === $matrix_verdict['verdict'] ) {
                        return new WP_Error( 'pk_deactivation_reason_required', $matrix_verdict['message'], array( 'status' => 400 ) );
                }

                $steps[] = array(
                        'step'     => 'matrix_control',
                        'status'   => 'allowed',
                        'observed' => array(
                                'post_status' => $post_status,
                                'pk_status'   => $status_meta,
                                'action'      => $action,
                                'mode'        => $matrix_verdict['mode'], // close | motif_change | resume | reopen | reopen_publish.
                        ),
                );

                /* --- ③ Précontrôle du groupe avant toute écriture (R1 §2.3-R1) --------- */

                $restrained = self::restrained_variants( $source_id );
                if ( 'reactivate' === $action && ! empty( $restrained ) ) {
                        return new WP_Error(
                                'pk_group_restrained',
                                __( 'Une traduction de cette annonce est refusée ou en attente de validation. L’ensemble ne peut pas être réactivé tant que l’équipe n’a pas traité la situation.', 'partikulier' ),
                                array(
                                        'status'   => 409,
                                        'restrained' => $restrained,
                                )
                        );
                }
                $steps[] = array(
                        'step'     => 'group_precheck',
                        'status'   => 'passed',
                        'observed' => array(
                                'restrained_variants' => array_keys( $restrained ),
                                'action'              => $action,
                        ),
                );

                /* --- ④ Exécution ------------------------------------------------------- */

                $report = self::execute_transition( $source_id, $action, $matrix_verdict['mode'], $reason, $note, $steps );

                if ( is_wp_error( $report ) ) {
                        return $report; // 500 pk_partial_failure — rapport structuré dans data.
                }

                /* --- ⑤ Réponse 200 ------------------------------------------------------ */

                $message = 'deactivate' === $action
                        ? __( 'Votre annonce est désactivée. Sa page reste en ligne, mais elle n’apparaît plus dans les résultats.', 'partikulier' )
                        : __( 'Votre annonce est de nouveau visible dans les résultats.', 'partikulier' );

                return array(
                        'message'      => $message,
                        'status'       => get_post_meta( $source_id, '_pk_status', true ),
                        'canonical_id' => $source_id,
                        'steps'        => $report['steps'],
                );
        }

        /* ---------------------------------------------------------------------- */
        /* ① Résolution canonique                                                  */
        /* ---------------------------------------------------------------------- */

        /**
         * Résout l'identifiant reçu vers la source canonique (R1 §2.2).
         * Refus 409 pk_variant_resolution sans écriture sur : identifiant présent
         * dans plusieurs lignes, à la fois source et variante, registre et Polylang
         * divergents, source pointée inexistante.
         *
         * @return array{source_id:int, was_variant:bool}|WP_Error
         */
        private static function resolve_canonical( $post_id ) {
                if ( ! self::variants_service() ) {
                        return array( 'source_id' => $post_id, 'was_variant' => false );
                }
                $resolved = call_user_func( array( self::variants_service(), 'source_for_variant' ), $post_id );
                if ( is_wp_error( $resolved ) ) {
                        return new WP_Error(
                                'pk_variant_resolution',
                                __( 'Les liens de traduction de cette annonce sont incohérents. Aucune modification n’a été faite. Contactez l’équipe.', 'partikulier' ),
                                array( 'status' => 409, 'resolution_error' => $resolved->get_error_code() )
                        );
                }
                return $resolved;
        }

        /** Classe du service variantes du plugin (couture class_exists), ou null. */
        private static function variants_service() {
                return class_exists( '\Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService' )
                        ? '\Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService'
                        : null;
        }

        /**
         * Variantes du groupe portant un marqueur administratif (refuse / en_attente_whatsapp).
         *
         * @return array<int, string> variant_id => marqueur.
         */
        private static function restrained_variants( $source_id ) {
                $service = self::variants_service();
                if ( ! $service ) {
                        return array();
                }
                return (array) call_user_func( array( $service, 'restrained_variants_for' ), $source_id );
        }

        /* ---------------------------------------------------------------------- */
        /* ② Matrice d'autorisation (v1.1 §3.1, default-deny, amendement R1 (e))   */
        /* ---------------------------------------------------------------------- */

        /**
         * @return array{verdict:string, mode?:string, message?:string}
         */
        private static function matrix_verdict( $post_status, $status_meta, $action ) {
                $published    = ( 'publish' === $post_status );
                $available    = self::is_available_status( $status_meta ); // absent, '', actif.
                $closed_owner = in_array( $status_meta, array( 'vendu', 'loue', 'loué', 'indisponible' ), true );
                $marker       = in_array( $status_meta, self::ADMIN_MARKERS, true );

                // États restreints (marqueurs administratifs) — aucune action propriétaire, source ou variante (v1.1 §3.2, R1 §2.3 règle 3).
                // Messages DISTINCTS par famille d'état (v1.1 §3.1 : validation en cours / refusée).
                if ( $marker ) {
                        if ( 'en_attente_whatsapp' === $status_meta ) {
                                return array(
                                        'verdict' => 'refuse_403',
                                        'message' => __( 'Cette annonce est en cours de traitement par l’équipe et ne peut pas être modifiée pour le moment.', 'partikulier' ),
                                );
                        }
                        return array(
                                'verdict' => 'refuse_403',
                                'message' => __( 'Cette annonce a été refusée par l’équipe. Elle ne peut pas être modifiée par son propriétaire.', 'partikulier' ),
                        );
                }

                // Post non publié : aucune republication propriétaire (R1 §3.2 point 1).
                // L'exception « draft × pause » de la v1.1 §3.1 est RETIRÉE par R1 : la
                // réactivation n'est possible que si post_status = publish ; le retour à la
                // publication depuis un état non publié relève du circuit administratif (v1.1 §3.3).
                if ( ! $published ) {
                        if ( 'reactivate' === $action ) {
                                return array(
                                        'verdict' => 'republication_403',
                                        'message' => __( 'Seule une annonce publiée peut être réactivée par son propriétaire. Pour une annonce non publiée, contactez l’équipe.', 'partikulier' ),
                                );
                        }
                        return array(
                                'verdict' => 'forbidden_403',
                                'message' => __( 'Cette annonce a été retirée par l’équipe et ne peut pas être modifiée par son propriétaire.', 'partikulier' ),
                        );
                }

                // publish × statut inconnu : défaut sûr (v1.1 §3.1 dernière ligne).
                if ( ! $available && ! $closed_owner && 'archive' !== $status_meta && '' !== $status_meta ) {
                        return array(
                                'verdict' => 'forbidden_403',
                                'message' => __( 'Le statut de cette annonce est inconnu. L’équipe doit l’arbitrer avant toute modification.', 'partikulier' ),
                        );
                }

                if ( 'reactivate' === $action ) {
                        // AMENDEMENT R1 (e) — case unique amendée : publish × {absent, '', actif} + reactivate = reprise idempotente 200.
                        if ( $available ) {
                                return array( 'verdict' => 'allowed', 'mode' => 'resume' );
                        }
                        return array( 'verdict' => 'allowed', 'mode' => 'reopen' ); // vendu/loue/loué/indisponible/archive → actif.
                }

                // deactivate.
                if ( $available ) {
                        return array( 'verdict' => 'allowed', 'mode' => 'close' ); // fermeture avec motif.
                }
                return array( 'verdict' => 'allowed', 'mode' => 'motif_change' ); // changement de motif (y compris archive historique réaligné).
        }

        /** Statut métier « disponible » au sens du prédicat central (v1.1 §5.1). */
        public static function is_available_status( $status_meta ) {
                return '' === $status_meta || 'actif' === $status_meta;
        }

        /* ---------------------------------------------------------------------- */
        /* ④ Exécution compare–write–verify + propagation + invalidation           */
        /* ---------------------------------------------------------------------- */

        /**
         * @param string $mode close|motif_change|resume|reopen
         * @return array{steps:array}|WP_Error Erreur = 500 pk_partial_failure (rapport structuré).
         */
        private static function execute_transition( $source_id, $action, $mode, $reason, $note, array $steps ) {
                /*
                 * Cible des métas de la source, selon le mode (R1 §4-R1 (a) : l'état
                 * attendu fait foi, jamais le seul code de retour).
                 *  - close/motif_change : statut fermé + motif + note + date ;
                 *  - resume             : rien à écrire si déjà cohérent (la date de
                 *                          fermeture N'EST JAMAIS réinitialisée par un rejeu) ;
                 *  - reopen             : actif + suppression motif/note/date.
                 * Le mode reopen_publish (republication depuis draft × pause) est retiré
                 * avec l'exception correspondante de la matrice (R1 §3.2 point 1).
                 */
                if ( in_array( $mode, array( 'close', 'motif_change' ), true ) ) {
                        if ( ! in_array( $reason, self::DEACTIVATION_REASONS, true ) ) {
                                return new WP_Error(
                                        'pk_deactivation_reason_required',
                                        __( 'Motif de désactivation obligatoire : vendu, loué, changement d’avis ou autre.', 'partikulier' ),
                                        array( 'status' => 400 )
                                );
                        }
                        if ( 'autre' === $reason && '' === $note ) {
                                return new WP_Error(
                                        'pk_deactivation_note_required',
                                        __( 'Merci de préciser le motif (texte privé, jamais publié).', 'partikulier' ),
                                        array( 'status' => 400 )
                                );
                        }

                        $closed_status = in_array( $reason, array( 'vendu', 'loue' ), true ) ? $reason : 'indisponible';
                        $already_closed = (string) get_post_meta( $source_id, '_pk_status', true ) === $closed_status
                                && (string) get_post_meta( $source_id, '_pk_closed_reason', true ) === $reason;

                        $targets = array(
                                '_pk_status'       => $closed_status,
                                '_pk_closed_reason' => $reason,
                        );
                        // Rejeu idempotent : la date de fermeture n'est PAS réinitialisée si l'état cible est déjà en place.
                        $targets['_pk_closed_at'] = $already_closed ? (string) get_post_meta( $source_id, '_pk_closed_at', true ) : current_time( 'mysql', true );
                        if ( 'autre' === $reason ) {
                                $targets['_pk_closed_note'] = $note;
                        }
                } elseif ( 'resume' === $mode ) {
                        $targets = array(); // reprise idempotente : compare–write–verify sans réécriture si cohérent.
                } else { // reopen.
                        $targets = array( '_pk_status' => 'actif' );
                }

                /* --- Écritures source en compare–write–verify ------------------------- */

                $source_report = self::write_metas_verified( $source_id, $targets, ( 'close' === $mode || 'motif_change' === $mode ) ? $reason : '', ( 'autre' === $reason && ( 'close' === $mode || 'motif_change' === $mode ) ) ? $note : '', 'reopen' === $mode );
                $steps[]       = array(
                        'step'     => 'source_write',
                        'status'   => $source_report['failed'] ? 'failed' : ( $source_report['wrote'] ? 'done' : 'already_done' ),
                        'observed' => $source_report['observed'],
                );
                if ( $source_report['failed'] ) {
                        return self::partial_failure( $action, $source_id, $steps, 'source_write' );
                }

                /* --- Injection CP2 — point ① : défaut APRÈS l'écriture de la source --- */

                if ( self::injected_defect( 'after_source_write', $action, $source_id ) ) {
                        $steps[] = array(
                                'step'     => 'propagation',
                                'status'   => 'failed',
                                'observed' => array( 'injected' => 'after_source_write' ),
                        );
                        return self::partial_failure( $action, $source_id, $steps, 'propagation' );
                }

                /* --- Propagation aux variantes (ignorés consignés — R1 §2.3-R1) ------- */

                $propagation = self::propagate_to_variants( $source_id, $mode, isset( $targets['_pk_status'] ) ? $targets['_pk_status'] : 'actif', $reason, $note, $targets );
                $steps[]     = array(
                        'step'     => 'propagation',
                        'status'   => $propagation['failed'] ? 'failed' : ( $propagation['ignored'] ? 'with_ignored' : 'done' ),
                        'observed' => $propagation['variants'],
                );
                if ( $propagation['failed'] ) {
                        return self::partial_failure( $action, $source_id, $steps, 'propagation' );
                }

                /* --- Injection CP2 — point ③ : défaut AVANT l'invalidation (cache amorcé) --- */

                if ( self::injected_defect( 'before_invalidation', $action, $source_id ) ) {
                        $steps[] = array(
                                'step'     => 'invalidation',
                                'status'   => 'failed',
                                'observed' => array( 'injected' => 'before_invalidation' ),
                        );
                        return self::partial_failure( $action, $source_id, $steps, 'invalidation' );
                }

                /* --- Invalidation exécutée et confirmée (R1 §4-R1 (c)) ---------------- */

                $invalidation = self::invalidate_caches( $source_id );
                $steps[]      = array(
                        'step'     => 'invalidation',
                        'status'   => $invalidation['failed'] ? 'failed' : ( $invalidation['cache_active'] ? 'done' : 'inactive_equivalent_path' ),
                        'observed' => $invalidation,
                );
                if ( $invalidation['failed'] ) {
                        return self::partial_failure( $action, $source_id, $steps, 'invalidation' );
                }

                return array( 'steps' => $steps );
        }

        /**
         * Écritures compare–write–verify (R1 §4-R1 (a)) : lecture de la valeur
         * stockée ; si elle égale déjà la cible → étape déjà faite (le false
         * d'update_post_meta sur valeur identique N'EST PAS un échec) ; sinon
         * écriture puis relecture de confirmation.
         *
         * @return array{wrote:bool, failed:bool, observed:array}
         */
        private static function write_metas_verified( $post_id, array $targets, $reason, $note, $is_reopen ) {
                $wrote     = false;
                $failed    = false;
                $observed  = array();

                foreach ( $targets as $meta_key => $target ) {
                        $stored = (string) get_post_meta( $post_id, $meta_key, true );
                        if ( $stored === (string) $target ) {
                                $observed[ $meta_key ] = 'already_at_target';
                                continue; // déjà fait — succès sans écriture.
                        }
                        $result = update_post_meta( $post_id, $meta_key, $target );
                        $after  = (string) get_post_meta( $post_id, $meta_key, true );
                        $wrote  = true;
                        if ( $after !== (string) $target ) {
                                $observed[ $meta_key ] = 'mismatch_after_write';
                                $failed                = true; // échec réel : la relecture diffère de la cible.
                        } else {
                                $observed[ $meta_key ] = 'written_verified';
                        }
                }

                // Fermeture : la note privée est retirée si le motif n'est plus « autre » (jamais d'accumulation).
                if ( ! $is_reopen && '' !== $reason && 'autre' !== $reason ) {
                        if ( '' !== (string) get_post_meta( $post_id, '_pk_closed_note', true ) ) {
                                delete_post_meta( $post_id, '_pk_closed_note' );
                                $wrote = true;
                        }
                        $observed['_pk_closed_note'] = 'absent';
                }

                // Réactivation : nettoyage symétrique complet de la source (v1.1 §9).
                if ( $is_reopen ) {
                        foreach ( array( '_pk_closed_reason', '_pk_closed_note', '_pk_closed_at' ) as $meta_key ) {
                                $stored = (string) get_post_meta( $post_id, $meta_key, true );
                                if ( '' !== $stored ) {
                                        delete_post_meta( $post_id, $meta_key );
                                        $wrote = true;
                                        $observed[ $meta_key ] = 'cleaned';
                                } else {
                                        $observed[ $meta_key ] = 'already_absent';
                                }
                        }
                }

                $observed['_pk_status_final'] = (string) get_post_meta( $post_id, '_pk_status', true );
                return array( 'wrote' => $wrote, 'failed' => $failed, 'observed' => $observed );
        }

        /**
         * Propagation aux variantes liées du registre métier (v1.1 §9, R1 §2.3-R1) :
         * aucune propagation n'écrase un marqueur administratif — l'étape est
         * ignorée pour la variante et consignée ; écritures compare–write–verify ;
         * nettoyage symétrique à la réactivation.
         *
         * @return array{failed:bool, ignored:bool, variants:array}
         */
        private static function propagate_to_variants( $source_id, $mode, $target_status, $reason, $note, array $targets ) {
                $service = self::variants_service();
                if ( ! $service ) {
                        return array( 'failed' => false, 'ignored' => false, 'variants' => array( 'note' => 'no_variants_service' ) );
                }

                $is_closure = ( 'close' === $mode || 'motif_change' === $mode );
                $result     = call_user_func(
                        array( $service, 'propagate_transition' ),
                        $source_id,
                        $is_closure ? $target_status : 'actif',
                        $is_closure ? $reason : '',
                        $is_closure && 'autre' === $reason ? $note : '',
                        $is_closure,
                        array( __CLASS__, 'variant_write_injected' ) // harnais d'injection CP2 — point ②.
                );

                $variants = array();
                foreach ( (array) $result['variants'] as $variant_id => $info ) {
                        $variants[ $variant_id ] = $info;
                }
                $failed  = false;
                $ignored = false;
                foreach ( $variants as $info ) {
                        if ( 'failed' === $info['status'] ) {
                                $failed = true;
                        }
                        if ( 'ignored' === $info['status'] ) {
                                $ignored = true;
                        }
                }
                return array( 'failed' => $failed, 'ignored' => $ignored, 'variants' => $variants );
        }

        /** Harnais CP2 point ② — défaut PENDANT la propagation (une écriture variante). */
        public static function variant_write_injected( $variant_id, $source_id ) {
                return self::injected_defect( 'during_propagation', '', $source_id, $variant_id );
        }

        /**
         * Harnais d'injection réutilisable (suite dp9-partial-failure-contract) :
         * un filtre déclare le point de défaut actif ; aucune autre voie ne peut
         * déclencher un échec simulé. Retourne true si le défaut doit s'appliquer.
         *
         * PROTECTION (exigence CP3 §3.1 — couture de test dans le code livré) :
         * le filtre n'est même pas consulté si la constante de test
         * PK_DP9_TEST_INJECTION n'est pas définie. Cette constante n'est définie
         * QUE par le mu-plugin de test (tests/fixtures/dp9-injection-harness.php,
         * installé au laboratoire/CI pour la seule durée des suites dp9) — jamais
         * par le code livré. Hors contexte de test, cette couture est donc INERTE
         * PAR CONSTRUCTION : même un filtre enregistré par une extension hostile
         * ne peut pas déclencher le moindre échec simulé.
         */
        private static function injected_defect( $point, $action, $source_id, $variant_id = 0 ) {
                if ( ! defined( 'PK_DP9_TEST_INJECTION' ) || ! PK_DP9_TEST_INJECTION ) {
                        return false; // production : couture inerte, aucun défaut possible.
                }
                $injection = apply_filters(
                        'pk_dp9_defect_injection',
                        null,
                        array(
                                'point'       => $point,
                                'action'      => $action,
                                'source_id'   => $source_id,
                                'variant_id'  => $variant_id,
                        )
                );
                return is_array( $injection ) && ( ! empty( $injection[ $point ] ) );
        }

        /**
         * Invalidation exécutée et confirmée (R1 §4-R1 (c) + v1.1 §6.2.2) :
         * version de cache incrémentée immédiatement (sans attendre shutdown) +
         * purge des pages. Cache inactif : chemin équivalent explicite — rien à
         * invalider, l'étape est trivialement satisfaite et consignée comme telle.
         *
         * @return array{cache_active:bool, failed:bool, version?:int}
         */
        private static function invalidate_caches( $source_id ) {
                $report = array( 'cache_active' => false, 'failed' => false );

                // Version de cache du dépôt de recherche (plugin) : int = exécutée et
                // confirmée ; null = cache inactif ; false = échec (à signaler, jamais un faux 200).
                $version = null;
                if ( class_exists( '\Partikulier\Core\Integration\ListingSynchronizer' ) && method_exists( '\Partikulier\Core\Integration\ListingSynchronizer', 'invalidate_listing_search_cache_now' ) ) {
                        $version = \Partikulier\Core\Integration\ListingSynchronizer::invalidate_listing_search_cache_now();
                }
                if ( is_int( $version ) ) {
                        $report['cache_active'] = true;
                        $report['version']      = $version;
                } elseif ( false === $version ) {
                        $report['failed'] = true; // invalidation échouée — le 200 n'est PAS émis (R1 §4-R1 (c)).
                } else {
                        // Cache de disponibilité inactif : chemin équivalent EXPLICITE —
                        // rien à invalider, l'étape est trivialement satisfaite et consignée
                        // comme telle (toute lecture va alors à la base).
                        $report['cache_active'] = false;
                        $report['equivalent']   = 'availability_cache_inactive_all_reads_hit_database';
                }

                // Purge des caches de pages (existant — conservée à chaque transition).
                if ( class_exists( 'Partikulier_Cache' ) && method_exists( 'Partikulier_Cache', 'purge_all' ) ) {
                        Partikulier_Cache::purge_all();
                }

                return $report;
        }

        /**
         * Échec partiel — HTTP 500, corps structuré identique sur les deux entrées
         * (R1 §4-R1 (b)) : action, identifiant canonique résolu, rapport d'étapes
         * (accomplies / échouées / ignorées-restriction, état constaté par étape),
         * conduite tenante.
         */
        private static function partial_failure( $action, $source_id, array $steps, $failed_step ) {
                return new WP_Error(
                        'pk_partial_failure',
                        __( 'L’action a échoué en cours d’exécution. L’état de l’annonce peut être partiellement mis à jour. Renvoyez la même action ou contactez l’équipe.', 'partikulier' ),
                        array(
                                'status'      => 500,
                                'failed_step' => $failed_step,
                                'report'      => array(
                                        'action'       => $action,
                                        'canonical_id' => $source_id,
                                        'steps'        => $steps,
                                        'conduct'      => 'resend_same_action_or_contact_team',
                                ),
                        )
                );
        }
}
