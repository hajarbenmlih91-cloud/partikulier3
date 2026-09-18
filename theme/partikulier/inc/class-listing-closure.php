<?php
/**
 * Module : expérience « annonce vendue » (SE-054, E-5402/E-5403).
 *
 * Quand une annonce est clôturée (vendue / louée / archivée), la fiche
 * reste publique (SEO + preuve sociale) mais ne reçoit plus de contacts.
 * Pour ne pas perdre la visite, la fiche propose :
 *  - jusqu'à 3 ANNONCES SIMILAIRES actives (même ville, même type) ;
 *  - un message WhatsApp PRÉREMPLI vers le numéro de validation du site :
 *    « cette annonce est vendue, proposez-moi des biens similaires ».
 *
 * Le filet de sécurité variantes (trash de la source → variantes emmenées,
 * zéro fantôme) est câblé ici : le thème possède le runtime, le service du
 * plugin possède la logique (mêmes garde-fous que le reste de la couture).
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
		return;
}

class Partikulier_Listing_Closure {

	public static function init() {
			add_action( 'wp_trash_post', array( __CLASS__, 'trash_variants_with_source' ) );
	}

		/**
		 * Filet anti-fantômes (SE-054) : la corbeille d'une annonce source
		 * emmène ses variantes EN/AR (sinon les pages étrangères d'une annonce
		 * retirée restent publiées).
		 */
	public static function trash_variants_with_source( $post_id ) {
			if ( class_exists( '\Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService' ) ) {
					\Partikulier\Core\Domain\TranslationVariants\TranslationVariantsService::trash_variants_with_source( $post_id );
			}
	}

		/**
		 * Statut de clôture d'une annonce ('' si active).
		 */
	public static function closure_status( $post_id ) {
			$status = (string) get_post_meta( (int) $post_id, '_pk_status', true );
			$map    = array( 'vendu' => 'vendu', 'loue' => 'loue', 'loué' => 'loue', 'archive' => 'archive' );
			return isset( $map[ $status ] ) ? $map[ $status ] : '';
	}

		/**
		 * Libellé localisé du statut de clôture (filigrane/badge ×3 langues —
		 * E-5401 : les catalogues SE-035 portent les trois formes).
		 */
	public static function closure_label( $post_id ) {
			$status = self::closure_status( $post_id );
			if ( 'vendu' === $status ) {
					return __( 'Vendu', 'partikulier' );
			}
			if ( 'loue' === $status ) {
					return __( 'Loué', 'partikulier' );
			}
			return __( 'Annonce archivée', 'partikulier' );
	}

		/**
		 * Annonces similaires ACTIVES (E-5402) : même ville, même type,
		 * publiées et NON clôturées — jusqu'à $limit, auto-complétées sans le
		 * type si la ville est trop pauvre, jamais l'annonce elle-même.
		 *
		 * @param int|WP_Post $post  Annonce clôturée.
		 * @param int         $limit Nombre maximal (3 par défaut).
		 * @return array<int, WP_Post>
		 */
	public static function similar_listings( $post, $limit = 3 ) {
			$post = get_post( $post );
			if ( ! $post instanceof WP_Post || PARTIKULIER_ESTATIK_POST_TYPE !== $post->post_type ) {
					return array();
			}
			$limit = max( 1, min( 6, (int) $limit ) );

			$closed = array( 'vendu', 'loue', 'loué', 'archive', 'pause' );
			$base   = array(
					'post_type'      => PARTIKULIER_ESTATIK_POST_TYPE,
					'post_status'    => 'publish',
					'posts_per_page' => $limit + 3,
					'post__not_in'   => array( (int) $post->ID ),
					'orderby'        => 'date',
					'order'          => 'DESC',
					'fields'         => 'ids',
					'no_found_rows'  => true,
					'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- requête bornée (limit+3), index méta _pk_status
							'relation' => 'OR',
							array( 'key' => '_pk_status', 'compare' => 'NOT EXISTS' ),
							array( 'key' => '_pk_status', 'value' => array( '', 'actif' ), 'compare' => 'IN' ),
					),
			);

			$results = array();
			foreach ( array( 'with_type', 'city_only' ) as $pass ) {
					if ( count( $results ) >= $limit ) {
							break;
					}
					$args          = $base;
					$args['tax_query'] = array(); // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- bornée
					$location_id   = 0;
					$locations     = get_the_terms( $post, PARTIKULIER_ESTATIK_LOCATION_TAXONOMY );
					if ( is_array( $locations ) && $locations ) {
							$location_id = (int) $locations[0]->term_id;
					}
					$types = get_the_terms( $post, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
					if ( $location_id ) {
							$args['tax_query'][] = array(
									'taxonomy' => PARTIKULIER_ESTATIK_LOCATION_TAXONOMY,
									'field'    => 'term_id',
									'terms'    => $location_id,
							);
					} else {
							continue; // sans ville, pas de similitude exploitable
					}
					if ( 'with_type' === $pass && is_array( $types ) && $types ) {
							$args['tax_query'][] = array(
									'taxonomy' => PARTIKULIER_ESTATIK_TYPE_TAXONOMY,
									'field'    => 'term_id',
									'terms'    => (int) $types[0]->term_id,
							);
					}
					$query = new WP_Query( $args );
					foreach ( (array) $query->posts as $candidate_id ) {
							if ( count( $results ) >= $limit ) {
									break;
							}
							$status = (string) get_post_meta( (int) $candidate_id, '_pk_status', true );
							if ( in_array( $status, $closed, true ) ) {
									continue;
							}
							$results[ (int) $candidate_id ] = true;
					}
					wp_reset_postdata();
			}
			$out = array();
			foreach ( array_keys( $results ) as $id ) {
					$found = get_post( $id );
					if ( $found instanceof WP_Post ) {
							$out[] = $found;
					}
			}
			return $out;
	}

		/**
		 * Message WhatsApp PRÉREMPLI pour une annonce clôturée (E-5403) : la
		 * visite n'est pas perdue — l'équipe peut proposer des biens similaires.
		 * Destinataire : le numéro de validation du site (le seul canal du site).
		 */
	public static function similar_request_url( $post ) {
			$post = get_post( $post );
			if ( ! $post instanceof WP_Post ) {
					return '';
			}
			$number = '';
			if ( class_exists( 'Partikulier_WhatsApp_Verification' ) ) {
					$number = Partikulier_WhatsApp_Verification::validation_number();
			}
			if ( ! $number ) {
					return '';
			}
			$title   = get_the_title( $post );
			$status  = self::closure_status( $post->ID );
			$message = 'vendu' === $status
					? sprintf( __( 'Bonjour, l’annonce « %s » est vendue. Pouvez-vous me proposer des biens similaires ?', 'partikulier' ), $title )
					: sprintf( __( 'Bonjour, l’annonce « %s » n’est plus disponible. Pouvez-vous me proposer des biens similaires ?', 'partikulier' ), $title );
			return 'https://wa.me/' . $number . '?text=' . rawurlencode( $message );
	}

		/**
		 * Rendu du bloc « similaires + WhatsApp » (E-5402/E-5403) pour la fiche
		 * clôturée — appelé par le gabarit single.
		 */
	public static function similar_block_html( $post ) {
			$post = get_post( $post );
			if ( ! $post instanceof WP_Post || '' === self::closure_status( $post->ID ) ) {
					return '';
			}
			$similar = self::similar_listings( $post, 3 );
			$wa_url  = self::similar_request_url( $post );
			ob_start();
			?>
			<div class="pk-sold-similar" data-source-id="<?php echo esc_attr( (int) $post->ID ); ?>">
					<p class="pk-contact-kicker"><?php esc_html_e( 'Annonces similaires', 'partikulier' ); ?></p>
					<?php if ( $similar ) : ?>
							<ul class="pk-sold-similar-list">
							<?php foreach ( $similar as $listing ) : ?>
									<li>
											<a href="<?php echo esc_url( (string) get_permalink( $listing ) ); ?>">
													<span class="pk-sold-similar-title"><?php echo esc_html( get_the_title( $listing ) ); ?></span>
													<?php $price = get_post_meta( $listing->ID, 'es_property_price', true ); ?>
													<?php if ( $price ) : ?>
															<span class="pk-sold-similar-price"><?php echo esc_html( (string) $price ); ?></span>
													<?php endif; ?>
											</a>
									</li>
							<?php endforeach; ?>
							</ul>
					<?php else : ?>
							<p class="pk-sold-similar-empty"><?php esc_html_e( 'Aucune annonce publiée récemment.', 'partikulier' ); ?></p>
					<?php endif; ?>
					<?php if ( $wa_url ) : ?>
							<a class="pk-btn pk-btn-primary pk-btn-block pk-btn-whatsapp" href="<?php echo esc_url( $wa_url ); ?>" target="_blank" rel="noopener">
									<?php esc_html_e( 'Demander des biens similaires sur WhatsApp', 'partikulier' ); ?>
							</a>
					<?php endif; ?>
			</div>
			<?php
			return (string) ob_get_clean();
	}
}

Partikulier_Listing_Closure::init();
