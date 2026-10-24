<?php
/**
 * Prix au mètre carré, calculé et non publié tant que l'interrupteur est fermé.
 *
 * Moyenne des annonces pep.ma publiées dans les 3 derniers mois et dans les 6
 * derniers mois. Chaque ligne sépare la transaction, le type, la ville et,
 * s'il existe, le quartier. Pas de médiane. Pas de seuil. Les annonces de
 * démonstration et les fiches sans prix ou sans surface ne comptent pas.
 * Aucun chiffre n'est inventé.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Price_Index {

	const OPTION    = 'pk_price_index_public';
	const CACHE_KEY = 'pk_price_index_v2';
	const SLUG      = 'prix-immobilier';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_route' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_post_pk_price_index_save', array( __CLASS__, 'save_switch' ) );
		add_action( 'save_post', array( __CLASS__, 'purge' ) );
		add_action( 'deleted_post', array( __CLASS__, 'purge' ) );
	}

	public static function query_vars( $vars ) {
		$vars[] = 'pk_price_index';
		return $vars;
	}

	/**
	 * La règle n'est ajoutée que lorsque la page a le droit d'exister.
	 */
	public static function register_route() {
		if ( ! self::is_publicly_served() ) {
			return;
		}
		add_rewrite_rule( '^fr/' . self::SLUG . '/?$', 'index.php?pk_price_index=1', 'top' );
		add_rewrite_rule( '^' . self::SLUG . '/?$', 'index.php?pk_price_index=1', 'top' );
	}

	public static function switch_is_on() {
		return '1' === (string) get_option( self::OPTION, '0' );
	}

	/**
	 * L'interrupteur seul ouvre la page. Une ligne sans annonce n'affiche pas de chiffre.
	 */
	public static function is_publicly_served() {
		return self::switch_is_on();
	}

	public static function public_url() {
		if ( function_exists( 'pk_localized_home_url' ) ) {
			return trailingslashit( pk_localized_home_url( 'fr' ) ) . self::SLUG . '/';
		}
		return home_url( '/fr/' . self::SLUG . '/' );
	}

	public static function purge() {
		delete_transient( self::CACHE_KEY );
	}

	public static function maybe_render() {
		if ( ! get_query_var( 'pk_price_index' ) ) {
			return;
		}
		if ( ! self::is_publicly_served() ) {
			status_header( 404 );
			nocache_headers();
			include get_query_template( '404' );
			exit;
		}
		status_header( 200 );
		nocache_headers();
		$pk_price_snapshot = self::snapshot();
		include PARTIKULIER_DIR . '/templates/price-index.php';
		exit;
	}

	public static function admin_menu() {
		add_management_page(
			'Prix au mètre carré',
			'Prix au mètre carré',
			'manage_options',
			'pk-price-index',
			array( __CLASS__, 'render_admin' )
		);
	}

	public static function save_switch() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Action non autorisée.', 'partikulier' ) );
		}
		check_admin_referer( 'pk_price_index_save' );
		$on = isset( $_POST['pk_price_index_public'] ) && '1' === (string) wp_unslash( $_POST['pk_price_index_public'] );
		update_option( self::OPTION, $on ? '1' : '0', false );
		self::purge();
		flush_rewrite_rules( false );
		wp_safe_redirect( admin_url( 'tools.php?page=pk-price-index&updated=1' ) );
		exit;
	}

	public static function render_admin() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$snap   = self::snapshot();
		$public = self::is_publicly_served();
		?>
		<div class="wrap">
			<h1>Prix au mètre carré</h1>
			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success"><p>Interrupteur enregistré. Aucun chiffre n'a été inventé.</p></div>
			<?php endif; ?>
			<p>La page publique reste éteinte tant que l'interrupteur est fermé. Il n'y a pas de seuil, et pas de médiane.</p>
			<p>Le chiffre est la moyenne des annonces publiées dans les 3 derniers mois, et dans les 6 derniers mois. Chaque ligne sépare la transaction, le type de bien, la ville et le quartier. Les annonces de démonstration, et les fiches sans prix ou sans surface, ne comptent pas.</p>
			<ul>
				<li>Interrupteur : <?php echo self::switch_is_on() ? 'ouvert' : 'fermé'; ?></li>
				<li>Page publique : <?php echo $public ? 'en ligne' : 'éteinte'; ?></li>
				<li>Lignes : <?php echo isset( $snap['slices'] ) ? count( $snap['slices'] ) : 0; ?></li>
				<li>Calcul : <?php echo esc_html( $snap['computed_at'] ); ?></li>
				<li>Depuis le <?php echo esc_html( $snap['from_3'] ); ?> (3 mois) et le <?php echo esc_html( $snap['from_6'] ); ?> (6 mois)</li>
			</ul>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<?php wp_nonce_field( 'pk_price_index_save' ); ?>
				<input type="hidden" name="action" value="pk_price_index_save">
				<label>
					<input type="checkbox" name="pk_price_index_public" value="1" <?php checked( self::switch_is_on() ); ?>>
					Ouvrir la page publique
				</label>
				<?php submit_button( 'Enregistrer' ); ?>
			</form>
			<table class="widefat striped">
				<thead>
					<tr>
						<th>Ville</th>
						<th>Quartier</th>
						<th>Transaction</th>
						<th>Type</th>
						<th>3 mois</th>
						<th>Moyenne, 3 mois</th>
						<th>6 mois</th>
						<th>Moyenne, 6 mois</th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $snap['slices'] ) ) : ?>
						<tr><td colspan="8">Aucune annonce réelle avec un prix et une surface sur les 6 derniers mois.</td></tr>
					<?php else : ?>
						<?php foreach ( $snap['slices'] as $slice ) : ?>
							<tr>
								<td><?php echo esc_html( $slice['city'] ); ?></td>
								<td><?php echo esc_html( '' !== $slice['district'] ? $slice['district'] : '—' ); ?></td>
								<td><?php echo esc_html( self::transaction_label( $slice['transaction'] ) ); ?></td>
								<td><?php echo esc_html( $slice['type'] ); ?></td>
								<td><?php echo (int) $slice['count_3']; ?></td>
								<td><?php echo $slice['count_3'] > 0 ? esc_html( self::format_amount( $slice['avg_3'], $slice['unit'] ) ) : '—'; ?></td>
								<td><?php echo (int) $slice['count_6']; ?></td>
								<td><?php echo $slice['count_6'] > 0 ? esc_html( self::format_amount( $slice['avg_6'], $slice['unit'] ) ) : '—'; ?></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

	/**
	 * @return array<string,mixed>
	 */
	public static function snapshot() {
		$cached = get_transient( self::CACHE_KEY );
		if ( is_array( $cached ) && isset( $cached['slices'], $cached['from_6'] ) ) {
			return $cached;
		}

		$built = self::build_snapshot();
		set_transient( self::CACHE_KEY, $built, 12 * HOUR_IN_SECONDS );
		return $built;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function build_snapshot() {
		$now  = (int) current_time( 'timestamp' );
		$cut3 = strtotime( '-3 months', $now );
		$cut6 = strtotime( '-6 months', $now );
		$ids  = get_posts(
			array(
				'post_type'              => PARTIKULIER_ESTATIK_POST_TYPE,
				'post_status'            => 'publish',
				'posts_per_page'         => 5000,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_meta_cache' => true,
				'update_post_term_cache' => true,
				'date_query'             => array(
					array(
						'after'     => '6 months ago',
						'inclusive' => true,
					),
				),
			)
		);

		$seen   = array();
		$groups = array();
		foreach ( (array) $ids as $id ) {
			$id    = (int) $id;
			$canon = self::canonical_id( $id );
			if ( isset( $seen[ $canon ] ) ) {
				continue;
			}
			$seen[ $canon ] = true;
			if ( self::is_demo_group( $id ) || ! self::is_active( $canon ) ) {
				continue;
			}
			$published = (int) get_post_time( 'U', false, $canon );
			if ( $published < $cut6 || $published > $now ) {
				continue;
			}
			$tx = self::transaction( $canon );
			if ( '' === $tx ) {
				continue;
			}
			$price = self::number_meta( $canon, array( 'es_property_price', 'es_price' ) );
			$area  = self::number_meta( $canon, array( 'es_property_area', 'es_size' ) );
			if ( $price <= 0 || $area <= 0 ) {
				continue;
			}
			$city     = trim( (string) get_post_meta( $canon, '_pk_city_name', true ) );
			$district = trim( (string) get_post_meta( $canon, '_pk_district_name', true ) );
			$type     = self::type_name( $canon );
			if ( '' === $city || '' === $type ) {
				continue;
			}
			$value = $price / $area;
			$in3   = $published >= $cut3;
			self::push_value( $groups, $tx, $city, '', $type, $value, $in3 );
			if ( '' !== $district && 0 !== strcasecmp( $district, $city ) ) {
				self::push_value( $groups, $tx, $city, $district, $type, $value, $in3 );
			}
		}

		$slices = array();
		foreach ( $groups as $group ) {
			$count6 = count( $group['all'] );
			if ( $count6 < 1 ) {
				continue;
			}
			$slices[] = array(
				'city'        => $group['city'],
				'district'    => $group['district'],
				'transaction' => $group['transaction'],
				'type'        => $group['type'],
				'unit'        => self::unit_for( $group['transaction'] ),
				'count_3'     => count( $group['recent'] ),
				'avg_3'       => self::average( $group['recent'] ),
				'count_6'     => $count6,
				'avg_6'       => self::average( $group['all'] ),
			);
		}
		usort(
			$slices,
			static function ( $a, $b ) {
				return array( $a['city'], $a['district'], $a['transaction'], $a['type'] ) <=> array( $b['city'], $b['district'], $b['transaction'], $b['type'] );
			}
		);

		return array(
			'computed_at' => wp_date( 'Y-m-d' ),
			'from_3'      => wp_date( 'Y-m-d', $cut3 ),
			'from_6'      => wp_date( 'Y-m-d', $cut6 ),
			'slices'      => $slices,
		);
	}

	private static function push_value( &$groups, $tx, $city, $district, $type, $value, $in3 ) {
		$key = self::key( $tx, $city, $district, $type );
		if ( ! isset( $groups[ $key ] ) ) {
			$groups[ $key ] = array(
				'city'        => $city,
				'district'    => $district,
				'transaction' => $tx,
				'type'        => $type,
				'all'         => array(),
				'recent'      => array(),
			);
		}
		$groups[ $key ]['all'][] = $value;
		if ( $in3 ) {
			$groups[ $key ]['recent'][] = $value;
		}
	}

	public static function format_amount( $amount, $unit ) {
		$amount = (int) round( (float) $amount );
		return number_format( $amount, 0, ',', ' ' ) . ' ' . $unit;
	}

	public static function transaction_label( $key ) {
		$map = array(
			'vente'      => 'Vente',
			'location'   => 'Location',
			'saisonnier' => 'Location saisonnière',
		);
		return isset( $map[ $key ] ) ? $map[ $key ] : (string) $key;
	}

	public static function unit_for( $key ) {
		if ( 'location' === $key ) {
			return 'DH / m² / mois';
		}
		return 'DH / m²';
	}

	private static function average( $values ) {
		$count = count( $values );
		if ( $count < 1 ) {
			return 0;
		}
		return (int) round( array_sum( $values ) / $count );
	}

	private static function key( $tx, $city, $district, $type ) {
		return strtolower( remove_accents( $tx . '|' . $city . '|' . $district . '|' . $type ) );
	}

	private static function canonical_id( $id ) {
		if ( function_exists( 'pll_get_post_translations' ) ) {
			$map = pll_get_post_translations( $id );
			if ( is_array( $map ) && ! empty( $map['fr'] ) ) {
				return (int) $map['fr'];
			}
		}
		return (int) $id;
	}

	private static function is_demo_group( $id ) {
		$ids = array( (int) $id );
		if ( function_exists( 'pll_get_post_translations' ) ) {
			$map = pll_get_post_translations( $id );
			if ( is_array( $map ) ) {
				$ids = array_map( 'intval', $map );
			}
		}
		foreach ( $ids as $one ) {
			if ( '1' === (string) get_post_meta( $one, '_pk_seed_demo', true ) ) {
				return true;
			}
		}
		return false;
	}

	private static function is_active( $id ) {
		$status = (string) get_post_meta( $id, '_pk_status', true );
		return '' === $status || 'actif' === $status;
	}

	private static function transaction( $id ) {
		$terms = get_the_terms( $id, PARTIKULIER_ESTATIK_STATUS_TAXONOMY );
		$sale  = false;
		$rent  = false;
		if ( $terms && ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$hay = strtolower( $term->slug . ' ' . $term->name );
				if ( false !== strpos( $hay, 'louer' ) || false !== strpos( $hay, 'location' ) || false !== strpos( $hay, 'rent' ) ) {
					$rent = true;
				}
				if ( false !== strpos( $hay, 'vend' ) || false !== strpos( $hay, 'vente' ) || false !== strpos( $hay, 'sale' ) ) {
					$sale = true;
				}
			}
		}
		if ( $sale && ! $rent ) {
			return 'vente';
		}
		if ( $rent ) {
			if ( 'saisonnier' === (string) get_post_meta( $id, '_pk_rent', true ) ) {
				return 'saisonnier';
			}
			return 'location';
		}
		return '';
	}

	private static function number_meta( $id, $keys ) {
		foreach ( $keys as $key ) {
			$raw = get_post_meta( $id, $key, true );
			if ( '' === $raw || null === $raw ) {
				continue;
			}
			$raw = str_replace( array( ' ', "\u{00A0}" ), '', (string) $raw );
			$raw = str_replace( ',', '.', $raw );
			if ( is_numeric( $raw ) ) {
				return (float) $raw;
			}
		}
		return 0.0;
	}

	private static function type_name( $id ) {
		$terms = get_the_terms( $id, PARTIKULIER_ESTATIK_TYPE_TAXONOMY );
		if ( ! $terms || is_wp_error( $terms ) ) {
			return '';
		}
		return (string) $terms[0]->name;
	}
}

Partikulier_Price_Index::init();
