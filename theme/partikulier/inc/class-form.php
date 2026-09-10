<?php
/**
 * Module : formulaire de depot d'annonce gratuit (front-end).
 *
 * - Sans jQuery : fetch() + FormData, progressif (fonctionne sans JS : POST direct)
 * - Cree l'annonce dans le CPT properties d'ESTATIK
 * - Upload des photos -> conversion AVIF automatique via Partikulier_AVIF
 * - Utilisateur anonyme : compte cree en contributor ; l'annonce reste
 *   invisible (porte de moderation _pk_status) jusqu'a la validation
 *   WhatsApp de l'administrateur — jamais d'e-mail de confirmation
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Partikulier_Form {

        public static function init() {
                add_action( 'wp_ajax_pk_submit_listing', array( __CLASS__, 'handle_ajax' ) );
                add_action( 'wp_ajax_nopriv_pk_submit_listing', array( __CLASS__, 'handle_ajax' ) );
        }

        /**
         * Traitement du depot d'annonce (AJAX + fallback POST).
         */
        public static function handle_ajax() {
                $nonce_valid = check_ajax_referer( 'pk_submit_listing', 'nonce', false );
                if ( ! $nonce_valid ) {
                        if ( wp_doing_ajax() ) {
                                wp_send_json_error( array( 'message' => __( 'Votre session a expiré. Rechargez la page avant de réessayer.', 'partikulier' ) ), 403 );
                        }
                        wp_die( esc_html__( 'Votre session a expiré. Rechargez la page avant de réessayer.', 'partikulier' ), __( 'Erreur de sécurité', 'partikulier' ), array( 'response' => 403 ) );
                }
                if ( ! Partikulier_Security::allow_listing_submission() ) {
                        $message = __( 'Trop de tentatives ont été effectuées. Réessayez dans une heure.', 'partikulier' );
                        if ( wp_doing_ajax() ) {
                                wp_send_json_error( array( 'message' => $message ), 429 );
                        }
                        wp_die( esc_html( $message ), __( 'Limite temporaire', 'partikulier' ), array( 'response' => 429 ) );
                }

                // Fallback sans JS : POST classique.
                if ( ! wp_doing_ajax() ) {
                        if ( empty( $_POST['pk_form_action'] ) || 'pk_submit_listing' !== $_POST['pk_form_action'] ) {
                                return;
                        }
                        $result = self::process( $_POST, isset( $_FILES['pk_photos'] ) ? $_FILES['pk_photos'] : null );
                        if ( is_wp_error( $result ) ) {
                                wp_die( esc_html( $result->get_error_message() ), __( 'Erreur de publication', 'partikulier' ), array( 'response' => 400 ) );
                        }
                        wp_redirect( get_permalink( $result ) );
                        exit;
                }

                $result = self::process( $_POST, isset( $_FILES['pk_photos'] ) ? $_FILES['pk_photos'] : null );
                if ( is_wp_error( $result ) ) {
                        wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
                }
                $verification = Partikulier_WhatsApp_Verification::pending_payload( $result );
                $photo_errors = get_post_meta( $result, '_pk_photo_errors', true );

                wp_send_json_success( array(
                        'message'           => __( 'Votre annonce est enregistrée et attend votre message WhatsApp.', 'partikulier' ),
                        'status'            => 'pending_whatsapp',
                        'url'               => get_permalink( $result ),
                        'whatsapp_url'      => $verification['url'],
                        'verification_code' => $verification['code'],
                        'photo_errors'      => is_array( $photo_errors ) ? $photo_errors : array(),
                ) );
        }

        /**
         * Validation + creation de l'annonce.
         *
         * @param array      $data   $_POST
         * @param array|null $files  $_FILES['pk_photos']
         * @return int|WP_Error ID de l'annonce ou erreur.
         */
        public static function process( $data, $files ) {
                        $title       = isset( $data['pk_title'] ) ? self::normalize_generated_title( sanitize_text_field( wp_unslash( $data['pk_title'] ) ) ) : '';
                $description = isset( $data['pk_description'] ) ? sanitize_textarea_field( wp_unslash( $data['pk_description'] ) ) : '';
                        $price       = isset( $data['pk_price'] ) ? sanitize_text_field( wp_unslash( $data['pk_price'] ) ) : '';
                        $price_number = 0;
                $surface     = isset( $data['pk_surface'] ) ? absint( $data['pk_surface'] ) : 0;
                $bedrooms_raw     = isset( $data['pk_bedrooms'] ) ? sanitize_text_field( wp_unslash( $data['pk_bedrooms'] ) ) : '';
                $living_rooms_raw = isset( $data['pk_living_rooms'] ) ? sanitize_text_field( wp_unslash( $data['pk_living_rooms'] ) ) : '';
                $bathrooms_raw    = isset( $data['pk_bathrooms'] ) ? sanitize_text_field( wp_unslash( $data['pk_bathrooms'] ) ) : '';
                $bedrooms         = '3+' === $bedrooms_raw ? 3 : absint( $bedrooms_raw );
                $living_rooms     = '3+' === $living_rooms_raw ? 3 : absint( $living_rooms_raw );
                $bathrooms        = '3+' === $bathrooms_raw ? 3 : absint( $bathrooms_raw );
                        $rooms            = $bedrooms + $living_rooms;
                        $terrace          = isset( $data['pk_terrace'] ) && 'Oui' === $data['pk_terrace'] ? 'Oui' : 'Non';
                        $terrace_surface  = 'Oui' === $terrace && isset( $data['pk_terrace_surface'] ) ? absint( $data['pk_terrace_surface'] ) : 0;
                        $vis_a_vis        = isset( $data['pk_vis_a_vis'] ) && 'Oui' === $data['pk_vis_a_vis'] ? 'Oui' : 'Non';
                        $sunshine          = isset( $data['pk_sunshine'] ) ? sanitize_text_field( wp_unslash( $data['pk_sunshine'] ) ) : '';
                        $type        = isset( $data['pk_type'] ) ? absint( $data['pk_type'] ) : 0;
                $action      = isset( $data['pk_listing_action'] ) ? sanitize_text_field( wp_unslash( $data['pk_listing_action'] ) ) : '';
                $city        = isset( $data['pk_city'] ) ? absint( $data['pk_city'] ) : 0;

                // Parcours en 3 etapes : la localisation arrive en clair (ville + quartier),
                // et la transaction sous forme de mode « vendre » / « louer ».
                $city_name     = isset( $data['pk_city_name'] ) ? sanitize_text_field( wp_unslash( $data['pk_city_name'] ) ) : '';
                $district_name = isset( $data['pk_district_name'] ) ? sanitize_text_field( wp_unslash( $data['pk_district_name'] ) ) : '';

                // Lieu propose librement : rien ne doit etre cree sans validation admin.
                $proposed_city     = isset( $data['pk_proposed_city'] ) ? sanitize_text_field( wp_unslash( $data['pk_proposed_city'] ) ) : '';
                $proposed_district = isset( $data['pk_proposed_district'] ) ? sanitize_text_field( wp_unslash( $data['pk_proposed_district'] ) ) : '';
                $has_proposal      = ( '' !== $proposed_city || '' !== $proposed_district );

                if ( ! $city && '' !== $city_name && class_exists( 'Partikulier_Morocco_Places' ) ) {
                        // Le lieu doit deja exister dans la taxonomie : on ne le cree pas ici.
                        $city = Partikulier_Morocco_Places::find_existing_term( $city_name, $district_name );
                }

                if ( '' === $action ) {
                        $mode   = isset( $data['pk_action_mode'] ) ? sanitize_text_field( wp_unslash( $data['pk_action_mode'] ) ) : 'vendre';
                        $action = self::resolve_action_slug( $mode );
                }

                $floor    = isset( $data['pk_floor'] ) ? sanitize_text_field( wp_unslash( $data['pk_floor'] ) ) : '';
                $garage   = isset( $data['pk_garage'] ) && 'Oui' === $data['pk_garage'] ? 'Oui' : 'Non';
                $elevator = isset( $data['pk_elevator'] ) && 'Oui' === $data['pk_elevator'] ? 'Oui' : 'Non';
                $name        = isset( $data['pk_name'] ) ? sanitize_text_field( wp_unslash( $data['pk_name'] ) ) : '';
                $email       = isset( $data['pk_email'] ) ? sanitize_email( wp_unslash( $data['pk_email'] ) ) : '';
                        $phone       = isset( $data['pk_phone'] ) ? sanitize_text_field( wp_unslash( $data['pk_phone'] ) ) : '';
                        $role        = isset( $data['pk_role'] ) ? sanitize_text_field( wp_unslash( $data['pk_role'] ) ) : 'proprietaire';
                        $edit_id     = isset( $data['pk_edit_id'] ) ? absint( $data['pk_edit_id'] ) : 0;
                        $is_edit     = false;
                        $editing_post = null;

                        if ( $edit_id ) {
                                if ( ! is_user_logged_in() ) {
                                        return new WP_Error( 'edit_auth', __( 'Connectez-vous pour modifier votre annonce.', 'partikulier' ) );
                                }
                                $editing_post = get_post( $edit_id );
                                if ( ! $editing_post || PARTIKULIER_ESTATIK_POST_TYPE !== $editing_post->post_type ) {
                                        return new WP_Error( 'edit_not_found', __( 'Annonce introuvable.', 'partikulier' ) );
                                }
                                if ( (int) get_current_user_id() !== (int) $editing_post->post_author && ! current_user_can( 'manage_options' ) ) {
                                        return new WP_Error( 'edit_forbidden', __( 'Vous n’êtes pas autorisé à modifier cette annonce.', 'partikulier' ) );
                                }
                                $is_edit = true;
                        }

                        if ( '' === $title ) {
                                $title = self::generate_listing_title( $type, $action, $city, $surface, $bedrooms_raw, $living_rooms_raw, $terrace, $terrace_surface, $vis_a_vis, $sunshine );
                        }

                if ( '' === $title || mb_strlen( $title ) < 10 ) {
                        return new WP_Error( 'title', __( 'Le titre doit contenir au moins 10 caractères.', 'partikulier' ) );
                }
                        if ( mb_strlen( $description ) < 50 ) {
                                return new WP_Error( 'description', __( 'La description doit contenir au moins 50 caractères.', 'partikulier' ) );
                        }
                        if ( '' === $price || mb_strlen( $price ) > 20 || ! preg_match( '/^\d[\d\s.,]*$/u', $price ) ) {
                                return new WP_Error( 'price', __( 'Indiquez un prix valide pour publier votre annonce.', 'partikulier' ) );
                        }
                        $price_number = (int) preg_replace( '/\D+/', '', $price );
                        if ( $price_number < 1 || $price_number > 1000000000 ) {
                                return new WP_Error( 'price', __( 'Indiquez un prix valide pour publier votre annonce.', 'partikulier' ) );
                        }
                        if ( ! $surface || $surface > 100000 ) {
                                return new WP_Error( 'surface', __( 'Indiquez une superficie valide.', 'partikulier' ) );
                        }
                        if ( ! $type || ! term_exists( $type, PARTIKULIER_ESTATIK_TYPE_TAXONOMY ) ) {
                                return new WP_Error( 'type', __( 'Choisissez un type de bien valide.', 'partikulier' ) );
                        }
                        // Deux cas valides : un lieu existant choisi dans la liste, OU une
                        // proposition de nouveau lieu qui passera par la moderation.
                        if ( ! $has_proposal && ( ! $city || ! term_exists( $city, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) ) ) {
                                return new WP_Error( 'city', __( 'Choisissez une ville ou un quartier dans la liste proposée.', 'partikulier' ) );
                        }
                        if ( $has_proposal && '' === $proposed_city && ! $city ) {
                                return new WP_Error( 'city', __( 'Indiquez au moins la ville de votre bien.', 'partikulier' ) );
                        }
                        // Le site est reserve aux proprietaires : un agent immobilier est
                        // refuse cote serveur, meme si le blocage JS a ete contourne.
                        if ( in_array( $role, array( 'agent', 'mandataire', 'agence' ), true ) ) {
                                return new WP_Error(
                                        'role_agent',
                                        __( 'Ce site est réservé aux propriétaires. Les annonces déposées par des agences ou des agents immobiliers ne sont pas acceptées.', 'partikulier' )
                                );
                        }
                        if ( ! in_array( $role, array( 'proprietaire' ), true ) ) {
                                return new WP_Error( 'role', __( 'Choisissez un statut annonceur valide.', 'partikulier' ) );
                        }
                        if ( '' === $name || mb_strlen( $name ) < 2 ) {
                                return new WP_Error( 'name', __( 'Votre nom est requis.', 'partikulier' ) );
                        }
                        if ( $files && ! empty( $files['name'] ) && ! is_array( $files['name'] ) ) {
                                return new WP_Error( 'photo_shape', __( 'Le format des photos envoyées est invalide.', 'partikulier' ) );
                        }
                        if ( $files && ! empty( $files['name'] ) && is_array( $files['name'] ) ) {
                                $count = count( $files['name'] );
                                if ( $count > 15 ) {
                                        return new WP_Error( 'photos_count', __( 'Vous pouvez ajouter jusqu’à 15 photos.', 'partikulier' ) );
                                }
                                // HEIC (photos iPhone) : accepte uniquement si le serveur sait le
                                // convertir, sinon WordPress produirait une vignette vide.
                                $allowed_mimes = array( 'image/jpeg', 'image/png', 'image/webp', 'image/avif' );
                                if ( self::supports_heic() ) {
                                        $allowed_mimes[] = 'image/heic';
                                        $allowed_mimes[] = 'image/heif';
                                }
                                for ( $i = 0; $i < $count; $i++ ) {
                                        if ( empty( $files['name'][ $i ] ) ) {
                                                continue;
                                        }
                                        if ( ! isset( $files['error'][ $i ] ) || UPLOAD_ERR_OK !== (int) $files['error'][ $i ] ) {
                                                return new WP_Error( 'photo_upload', __( 'Une photo n’a pas pu être envoyée. Réessayez avec un fichier JPG, PNG, WebP ou AVIF.', 'partikulier' ) );
                                        }
                                        if ( empty( $files['tmp_name'][ $i ] ) || ! is_uploaded_file( $files['tmp_name'][ $i ] ) ) {
                                                return new WP_Error( 'photo_source', __( 'Le fichier image envoyé est invalide.', 'partikulier' ) );
                                        }
                                        if ( empty( $files['size'][ $i ] ) || (int) $files['size'][ $i ] > 10 * MB_IN_BYTES ) {
                                                return new WP_Error( 'photo_size', __( 'Chaque photo doit peser au maximum 10 Mo.', 'partikulier' ) );
                                        }
                                        $filetype = wp_check_filetype_and_ext( $files['tmp_name'][ $i ], $files['name'][ $i ] );
                                        if ( empty( $filetype['type'] ) || ! in_array( $filetype['type'], $allowed_mimes, true ) ) {
                                                return new WP_Error(
                                                        'photo_type',
                                                        self::supports_heic()
                                                                ? __( 'Ajoutez uniquement des images JPG, PNG, WebP, AVIF ou HEIC.', 'partikulier' )
                                                                : __( 'Ajoutez uniquement des images JPG, PNG, WebP ou AVIF. Les photos iPhone au format HEIC ne sont pas prises en charge par ce serveur : activez « Très compatible » dans Réglages › Appareil photo › Formats sur votre iPhone.', 'partikulier' )
                                                );
                                        }
                                        $image_size = wp_getimagesize( $files['tmp_name'][ $i ] );
                                        if ( ! $image_size || empty( $image_size[0] ) || empty( $image_size[1] ) || $image_size[0] > 10000 || $image_size[1] > 10000 ) {
                                                return new WP_Error( 'photo_dimensions', __( 'Les dimensions de la photo sont invalides.', 'partikulier' ) );
                                        }
                                }
                        }
                        if ( '' !== $bedrooms_raw && ! in_array( $bedrooms_raw, array( '0', '1', '2', '3+' ), true ) ) {
                        return new WP_Error( 'bedrooms', __( 'Choisissez un nombre de chambres valide.', 'partikulier' ) );
                }
                if ( '' !== $living_rooms_raw && ! in_array( $living_rooms_raw, array( '0', '1', '2', '3+' ), true ) ) {
                        return new WP_Error( 'living_rooms', __( 'Choisissez un nombre de salons valide.', 'partikulier' ) );
                }
                if ( '' !== $bathrooms_raw && ! in_array( $bathrooms_raw, array( '1', '2', '3+' ), true ) ) {
                        return new WP_Error( 'bathrooms', __( 'Choisissez un nombre de salles de bains valide.', 'partikulier' ) );
                }
                        if ( 'Oui' === $terrace && ! $terrace_surface ) {
                                return new WP_Error( 'terrace_surface', __( 'Indiquez la superficie de la terrasse.', 'partikulier' ) );
                        }
                        if ( ! in_array( $sunshine, array( '', 'Ensoleillé le matin', 'Ensoleillé l’après-midi', "Ensoleillé l'après-midi", 'Toute la journée', 'Très peu' ), true ) ) {
                                return new WP_Error( 'sunshine', __( 'Choisissez un niveau d’ensoleillement valide.', 'partikulier' ) );
                        }
                $email_ok = is_email( $email );
                $phone_ok = preg_match( '/^[+]?\d[\d\s.\-()]{7,}$/', $phone );
                                if ( ! $phone_ok ) {
                                        return new WP_Error( 'phone', __( 'Un numéro de téléphone valide est requis pour demander la validation WhatsApp.', 'partikulier' ) );
                                }
                                if ( ! class_exists( 'Partikulier_WhatsApp_Verification' ) || ! Partikulier_WhatsApp_Verification::is_configured() ) {
                                        if ( current_user_can( 'manage_options' ) ) {
                                                return new WP_Error(
                                                        'whatsapp_configuration',
                                                        __( 'Publication impossible : le numéro WhatsApp de validation n’est pas renseigné. Allez dans Apparence › Personnaliser › Validation WhatsApp et saisissez votre numéro au format international (ex : 212612345678).', 'partikulier' )
                                                );
                                        }

                                        return new WP_Error( 'whatsapp_configuration', __( 'Le dépôt d’annonce est momentanément indisponible. Merci de réessayer plus tard.', 'partikulier' ) );
                                }

                        // --- Utilisateur et création ou mise à jour de l'annonce ---
                        if ( $is_edit ) {
                                $user = get_user_by( 'id', $editing_post->post_author );
                                if ( ! $user ) {
                                        return new WP_Error( 'edit_owner', __( 'Le propriétaire de cette annonce est introuvable.', 'partikulier' ) );
                                }
                                $post_id = wp_update_post( array(
                                        'ID'           => $editing_post->ID,
                                        'post_status'  => 'pending',
                                        'post_title'   => $title,
                                        'post_content' => $description,
                                        'post_excerpt' => wp_trim_words( $description, 30, '…' ), /* 6.17.30 : sans 3e arg, wp_trim_words colle l'entité littérale et celle-ci finissait en base + JSON-LD */
                                ), true );
                        } else {
                                $user_email = $email_ok ? $email : ( $name ? sanitize_title( substr( $name, 0, 20 ) ) . wp_rand( 100, 999 ) . '@partikulier.local' : false );
                                if ( ! $user_email ) {
                                        $user_email = 'contact-' . wp_rand( 10000, 99999 ) . '@partikulier.local';
                                }
                                $user = self::ensure_user( $user_email, $name );
                                if ( is_wp_error( $user ) ) {
                                        return $user;
                                }
                                $post_id = wp_insert_post( array(
                                        'post_type'    => PARTIKULIER_ESTATIK_POST_TYPE,
                                        'post_status'  => 'pending',
                                        'post_author'  => $user->ID,
                                        'post_title'   => $title,
                                        'post_content' => $description,
                                        'post_excerpt' => wp_trim_words( $description, 30, '…' ), /* 6.17.30 : sans 3e arg, wp_trim_words colle l'entité littérale et celle-ci finissait en base + JSON-LD */
                                ), true );
                        }

                if ( is_wp_error( $post_id ) ) {
                        return $post_id;
                }

                // --- Metas Estatik ---
                        if ( $price_number ) {
                                update_post_meta( $post_id, 'es_property_price', $price_number );
                }
                if ( $surface ) {
                        update_post_meta( $post_id, 'es_property_area', $surface );
                }
                if ( $rooms ) {
                        update_post_meta( $post_id, 'es_property_total_rooms', $rooms );
                }
                if ( '' !== $bedrooms_raw ) {
                        update_post_meta( $post_id, 'es_property_bedrooms', $bedrooms );
                        update_post_meta( $post_id, '_pk_bedrooms_label', $bedrooms_raw );
                }
                if ( '' !== $living_rooms_raw ) {
                        update_post_meta( $post_id, '_pk_living_rooms', $living_rooms );
                        update_post_meta( $post_id, '_pk_living_rooms_label', $living_rooms_raw );
                }
                if ( '' !== $bathrooms_raw ) {
                        update_post_meta( $post_id, 'es_property_bathrooms', $bathrooms );
                        update_post_meta( $post_id, '_pk_bathrooms_label', $bathrooms_raw );
                }
                        update_post_meta( $post_id, '_pk_terrace', $terrace );
                        if ( $terrace_surface ) {
                                update_post_meta( $post_id, '_pk_terrace_surface', $terrace_surface );
                        }
                        update_post_meta( $post_id, '_pk_vis_a_vis', $vis_a_vis );
                        if ( $sunshine ) {
                                update_post_meta( $post_id, '_pk_sunshine', $sunshine );
                        }
                        if ( '' !== $floor ) {
                                update_post_meta( $post_id, '_pk_floor', $floor );
                        }
                        update_post_meta( $post_id, '_pk_garage', $garage );
                        update_post_meta( $post_id, '_pk_elevator', $elevator );

                // --- Taxonomies ---
                if ( $type ) {
                        wp_set_object_terms( $post_id, (int) $type, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
                }
                if ( $action ) {
                        $term = get_term_by( 'slug', $action, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
                        if ( $term ) {
                                wp_set_object_terms( $post_id, (int) $term->term_id, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
                        }
                }
                if ( $city ) {
                        wp_set_object_terms( $post_id, (int) $city, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
                }

                                        // La langue source est celle du formulaire courant, jamais une valeur FR forcée.
                        $requested_lang = isset( $data['pk_language'] ) ? sanitize_key( wp_unslash( $data['pk_language'] ) ) : '';
                        $source_lang = $requested_lang ? $requested_lang : ( function_exists( 'pll_current_language' ) ? sanitize_key( (string) pll_current_language( 'slug' ) ) : 'fr' );
                        if ( ! in_array( $source_lang, array( 'fr', 'en', 'ar' ), true ) ) {
                                $source_lang = 'fr';
                        }
                        if ( function_exists( 'pll_set_post_language' ) ) {
                                pll_set_post_language( $post_id, $source_lang );
                        }

                        // SEO : meta description dediee, plus courte que le texte de l'annonce.

                if ( class_exists( 'Partikulier_Listing_Preview' ) ) {
                        $seo_values = Partikulier_Listing_Preview::normalize_input( $data );
                        update_post_meta( $post_id, '_pk_meta_description', Partikulier_Listing_Preview::build_meta_description( $seo_values ) );
                }

                // Lieu propose : on enregistre la demande et on bloque l'annonce.
                // Aucun terme n'est cree tant que l'administrateur n'a pas valide.
                if ( $has_proposal && class_exists( 'Partikulier_Place_Requests' ) ) {
                        Partikulier_Place_Requests::add(
                                $post_id,
                                '' !== $proposed_city ? $proposed_city : $city_name,
                                $proposed_district,
                                $city
                        );
                }

                // --- Localisation en clair : sert a construire l'URL ville/quartier ---
                if ( '' !== $city_name ) {
                        update_post_meta( $post_id, '_pk_city_name', $city_name );
                }
                if ( '' !== $district_name ) {
                        update_post_meta( $post_id, '_pk_district_name', $district_name );
                }

                // --- Contact annonceur (toujours proprietaire : agents refuses) ---
                update_post_meta( $post_id, '_pk_owner_name', $name );
                if ( $email_ok ) {
                        update_post_meta( $post_id, '_pk_owner_email', $email );
                }
                if ( $phone_ok ) {
                        update_post_meta( $post_id, '_pk_owner_phone', $phone );
                }
                        update_post_meta( $post_id, '_pk_owner_role', 'proprietaire' );
                        update_post_meta( $post_id, '_pk_status', Partikulier_WhatsApp_Verification::STATUS_PENDING );
                        if ( ! $is_edit ) {
                                update_post_meta( $post_id, '_pk_views', 0 );
                        }
                        delete_post_meta( $post_id, '_pk_whatsapp_verified_at' );
                        delete_post_meta( $post_id, '_pk_whatsapp_verified_by' );
                        Partikulier_WhatsApp_Verification::create_pending( $post_id );

                // --- Photos : upload -> WordPress cree automatiquement les tailles,
                //     et wp_generate_attachment_metadata declenche la conversion AVIF
                //     (filtre Partikulier_AVIF::generate_avif). ---
                $thumb_id = 0;
                $photo_errors = array();
                if ( $files && ! empty( $files['name'][0] ) ) {
                        require_once ABSPATH . 'wp-admin/includes/image.php';
                        require_once ABSPATH . 'wp-admin/includes/file.php';
                        require_once ABSPATH . 'wp-admin/includes/media.php';

                        $count = count( $files['name'] );
                                $gallery = get_post_meta( $post_id, 'es_property_gallery', true );
                                $gallery = is_array( $gallery ) ? $gallery : array();
                        for ( $i = 0; $i < $count && $i < 15; $i++ ) {
                                if ( empty( $files['name'][ $i ] ) ) {
                                        continue;
                                }
                                $tmp = array(
                                        'name'     => $files['name'][ $i ],
                                        'type'     => $files['type'][ $i ],
                                        'tmp_name' => $files['tmp_name'][ $i ],
                                        'error'    => $files['error'][ $i ],
                                        'size'     => $files['size'][ $i ],
                                );
                                $_FILES['pk_photo_single'] = $tmp;
                                $attachment_id = media_handle_upload( 'pk_photo_single', $post_id );
                                unset( $_FILES['pk_photo_single'] );
                                if ( is_wp_error( $attachment_id ) ) {
                                        // Ne jamais echouer en silence : l'annonceur doit savoir
                                        // quelle photo n'est pas passee, et pourquoi.
                                        $photo_errors[] = sprintf(
                                                /* translators: 1: nom du fichier, 2: message d'erreur. */
                                                __( '%1$s : %2$s', 'partikulier' ),
                                                sanitize_file_name( $files['name'][ $i ] ),
                                                $attachment_id->get_error_message()
                                        );
                                        continue;
                                }
                                // SEO images : texte alternatif decrivant le bien et son lieu.
                                if ( class_exists( 'Partikulier_Listing_Preview' ) ) {
                                        $alt_text = Partikulier_Listing_Preview::build_image_alt(
                                                Partikulier_Listing_Preview::normalize_input( $data ),
                                                count( $gallery )
                                        );
                                        update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
                                }

                                $gallery[] = $attachment_id;
                                if ( 0 === $thumb_id ) {
                                        $thumb_id = $attachment_id;
                                }
                        }
                        if ( $gallery ) {
                                update_post_meta( $post_id, 'es_property_gallery', $gallery );
                        }
                }

                if ( $thumb_id ) {
                        set_post_thumbnail( $post_id, $thumb_id );
                }

                // Les photos refusees sont signalees a l'annonceur, pas avalees.
                if ( $photo_errors ) {
                        update_post_meta( $post_id, '_pk_photo_errors', $photo_errors );
                } else {
                        delete_post_meta( $post_id, '_pk_photo_errors' );
                }

                // --- Versions arabe et anglaise : trois pages distinctes, liees entre
                //     elles par Polylang pour alimenter les balises hreflang. ---
                if ( class_exists( 'Partikulier_Listing_Translations' ) && Partikulier_Listing_Translations::available() ) {
                        $i18n_values = Partikulier_Listing_Preview::normalize_input( $data );
                                                        $source_lang = isset( $source_lang ) ? $source_lang : ( function_exists( 'pll_default_language' ) ? pll_default_language() : 'fr' );

                        $extra_note  = isset( $data['pk_extra'] ) ? sanitize_textarea_field( wp_unslash( $data['pk_extra'] ) ) : '';

                        Partikulier_Listing_Translations::sync( $post_id, $i18n_values, $source_lang, $extra_note );
                }

                // Notification a l'annonceur.
                self::notify_owner( $user, $post_id );

                return $post_id;
        }

        /**
         * Normalise le titre automatique si un ancien formulaire a inséré Studio deux fois.
         * La règle s’applique côté serveur, y compris sans JavaScript.
         *
         * @param string $title Titre soumis par le formulaire.
         * @return string
         */
                /**
         * Traduit un mode « vendre » / « louer » en slug de terme es_status.
         * Le terme est cree s'il n'existe pas encore : le formulaire ne doit
         * jamais echouer parce que la taxonomie du site est incomplete.
         *
         * @param string $mode « vendre » ou « louer ».
         * @return string Slug du terme, chaine vide si echec.
         */
        /**
         * Types de fichiers proposes par le selecteur du navigateur.
         * On n'annonce le HEIC que si le serveur sait le traiter.
         *
         * @return string
         */
        public static function accepted_upload_types() {
                $types = 'image/jpeg,image/png,image/webp,image/avif,.jpg,.jpeg,.png,.webp,.avif';
                if ( self::supports_heic() ) {
                        $types .= ',image/heic,image/heif,.heic,.heif';
                }

                return $types;
        }

        /**
         * Phrase d'aide sous la zone de depot, alignee sur les capacites reelles.
         *
         * @return string
         */
        public static function upload_hint() {
                $max = size_format( wp_max_upload_size() );

                if ( self::supports_heic() ) {
                        /* translators: %s: taille maximale. */
                        return sprintf( __( 'JPG, PNG, HEIC ou WebP · %s maximum par photo', 'partikulier' ), $max );
                }

                /* translators: %s: taille maximale. */
                return sprintf( __( 'JPG, PNG ou WebP · %s maximum par photo', 'partikulier' ), $max );
        }

        /**
         * Le serveur sait-il convertir une image HEIC ?
         * Sans Imagick compile avec HEIC, WordPress cree une miniature vide.
         *
         * @return bool
         */
        public static function supports_heic() {
                if ( ! extension_loaded( 'imagick' ) || ! class_exists( 'Imagick' ) ) {
                        return false;
                }
                $formats = Imagick::queryFormats( 'HEI*' );

                return ! empty( $formats );
        }

        private static function resolve_action_slug( $mode ) {
                $is_rent  = 'louer' === $mode;
                $needles  = $is_rent ? array( 'a louer', 'louer', 'location', 'for rent', 'rent' ) : array( 'a vendre', 'vendre', 'vente', 'for sale', 'sale' );
                $taxonomy = PARTIKULIER_ESTATIK_STATUS_TAXONOMY;

                $terms = get_terms( array(
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                ) );

                if ( ! is_wp_error( $terms ) ) {
                        foreach ( $terms as $term ) {
                                $name = function_exists( 'mb_strtolower' ) ? mb_strtolower( remove_accents( $term->name ) ) : strtolower( remove_accents( $term->name ) );
                                foreach ( $needles as $needle ) {
                                        if ( $name === $needle || false !== strpos( $name, $needle ) ) {
                                                return $term->slug;
                                        }
                                }
                        }
                }

                // Aucun terme d'action utilisable : on le cree.
                $label   = $is_rent ? __( 'À louer', 'partikulier' ) : __( 'À vendre', 'partikulier' );
                $created = wp_insert_term( $label, $taxonomy );
                if ( is_wp_error( $created ) ) {
                        return '';
                }
                $term = get_term( $created['term_id'], $taxonomy );

                return ( $term && ! is_wp_error( $term ) ) ? $term->slug : '';
        }

        private static function normalize_generated_title( $title ) {
                        $title = preg_replace( '/^Studio\s*(?:—|-|–)\s*Studio\b/ui', 'Studio', $title );
                        return preg_replace( '/^Studio\s+Studio\b/ui', 'Studio', $title );
                }

                /**
                 * Construit un titre de secours quand le navigateur ne peut pas exécuter JavaScript.
                 * La formulation suit exactement les priorités du formulaire React : sans vis-à-vis,
                 * puis ensoleillement, puis terrasse, sans répéter Studio.
                 *
                 * @return string
                 */
                private static function generate_listing_title( $type_id, $action_slug, $city_id, $surface, $bedrooms, $living_rooms, $terrace, $terrace_surface, $vis_a_vis, $sunshine ) {
                        $type_term = $type_id ? get_term( $type_id, PARTIKULIER_ESTATIK_TYPE_TAXONOMY ) : null;
                        $type      = $type_term && ! is_wp_error( $type_term ) ? $type_term->name : __( 'Appartement', 'partikulier' );
                        $is_studio = '0' === (string) $bedrooms || 'studio' === strtolower( $type );
                        $type      = $is_studio ? 'Studio' : $type;
                        $layout    = '';
                        if ( ! $is_studio && '1' === (string) $living_rooms && in_array( (string) $bedrooms, array( '1', '2', '3+' ), true ) ) {
                                $layout = ' — ' . ( '3+' === (string) $bedrooms ? '3 chambres + salon ou plus' : $bedrooms . ' chambre' . ( '1' === (string) $bedrooms ? '' : 's' ) . ' + salon' );
                        }
                        $size = $surface ? ' de ' . $surface . ' m²' : '';

                        $primary_advantage = 'Oui' === $vis_a_vis ? ' sans vis-à-vis' : '';
                        if ( ! $primary_advantage ) {
                                $primary_advantage = 'Toute la journée' === $sunshine ? ' bien ensoleillé' : ( 'Ensoleillé le matin' === $sunshine ? ' ensoleillé le matin' : ( in_array( $sunshine, array( 'Ensoleillé l’après-midi', "Ensoleillé l'après-midi" ), true ) ? ' ensoleillé l’après-midi' : '' ) );
                        }
                        $terrace_label = 'Oui' === $terrace ? ' avec terrasse' . ( $terrace_surface ? ' de ' . $terrace_surface . ' m²' : '' ) : '';

                        $city_term = $city_id ? get_term( $city_id, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY ) : null;
                        $place     = $city_term && ! is_wp_error( $city_term ) ? $city_term->name : __( 'votre ville', 'partikulier' );
                        $action_term = $action_slug ? get_term_by( 'slug', $action_slug, PARTIKULIER_ESTATIK_STATUS_TAXONOMY ) : null;
                        $transaction = $action_term && false !== mb_stripos( $action_term->name, 'lou' ) ? 'à louer' : 'à vendre';

                        return self::normalize_generated_title( $type . $layout . $size . $primary_advantage . $terrace_label . ' ' . $transaction . ' à ' . $place );
                }

        /**
         * Cree ou recupere l'utilisateur annonceur (contributor).
         */
        private static function ensure_user( $email, $name ) {
                $user = get_user_by( 'email', $email );
                if ( $user && false === strpos( $email, '@partikulier.local' ) ) {
                        return $user;
                }
                $login    = sanitize_user( strtok( $email, '@' ) . wp_rand( 1000, 9999 ) );
                $password = wp_generate_password( 16, true, false );
                $user_id  = wp_create_user( $login, $password, $email );
                if ( is_wp_error( $user_id ) ) {
                        return $user_id;
                }
                $user = get_user_by( 'id', $user_id );
                $user->set_role( 'contributor' );
                wp_update_user( array( 'ID' => $user_id, 'display_name' => $name ?: strtok( $email, '@' ) ) );
                return $user;
        }

        /**
         * Email de confirmation a l'annonceur (uniquement s'il a fourni un e-mail reel).
         */
        private static function notify_owner( $user, $post_id ) {
                $email = get_post_meta( $post_id, '_pk_owner_email', true );
                if ( ! is_email( $email ) || false !== strpos( $email, '@partikulier.local' ) ) {
                        return;
                }
                        $subject = __( 'Finalisez la validation WhatsApp de votre annonce', 'partikulier' );
                        $verification = Partikulier_WhatsApp_Verification::pending_payload( $post_id );
                        $message = sprintf(
                                /* translators: 1: nom, 2: lien WhatsApp, 3: code de vérification */
                                __( "Bonjour %1\$s,\n\nVotre demande de publication est enregistrée mais reste en attente de validation. Ouvrez WhatsApp et envoyez le message préparé :\n%2\$s\n\nVotre code de rapprochement : %3\$s\n\nL’équipe Partikulier.com", 'partikulier' ),
                                $user->display_name,
                                $verification['url'],
                                $verification['code']
                        );
                wp_mail( $email, $subject, $message );
        }
}

Partikulier_Form::init();