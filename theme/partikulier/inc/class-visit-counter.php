<?php
/**
 * Compteur de visites, seulement après un oui.
 *
 * Le bandeau vient après le choix de langue. Un bouton, Accepter. Pas de Non.
 * Accepter ajoute 1 au total du jour. Pas de nom, pas de profil,
 * pas d'adresse, pas d'outil extérieur.
 *
 * @package Partikulier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Visit_Counter {

	const COOKIE = 'pk_measure';
	const OPTION = 'pk_visit_daily';

	public static function init() {
		add_action( 'wp_ajax_nopriv_pk_visit_count', array( __CLASS__, 'count' ) );
		add_action( 'wp_ajax_pk_visit_count', array( __CLASS__, 'count' ) );
		add_action( 'wp_footer', array( __CLASS__, 'render_bar' ), 99 );
	}

	public static function count() {
		$choice = isset( $_COOKIE[ self::COOKIE ] ) ? (string) $_COOKIE[ self::COOKIE ] : '';
		if ( 'yes' !== $choice ) {
			wp_send_json_success( array( 'counted' => false ) );
		}

		$day = wp_date( 'Y-m-d' );
		$all = get_option( self::OPTION, array() );
		if ( ! is_array( $all ) ) {
			$all = array();
		}
		$all[ $day ] = isset( $all[ $day ] ) ? ( (int) $all[ $day ] + 1 ) : 1;
		if ( count( $all ) > 400 ) {
			ksort( $all );
			$all = array_slice( $all, -400, null, true );
		}
		update_option( self::OPTION, $all, false );
		wp_send_json_success( array( 'counted' => true ) );
	}

	public static function render_bar() {
		if ( self::on_deposit() ) {
			return;
		}
		$lang = class_exists( 'Partikulier_Localization' ) ? Partikulier_Localization::current_language() : 'fr';
		if ( 'en' === $lang ) {
			$before = 'We use our own cookies and third-party cookies for analytics and to show you personalized ads based on a profile of your browsing habits (for example, the pages you visit). ';
			$link   = 'Click here';
			$after  = ' for more information.';
			$yes    = 'Accept';
			$html_lang = 'en';
		} else {
			$before = 'Nous utilisons nos propres cookies et ceux de tiers à des fins d’analyse et pour vous montrer des publicités personnalisées en fonction d’un profil basé sur vos habitudes de navigation (par exemple, les pages visitées). ';
			$link   = 'Cliquez ici';
			$after  = ' pour plus d’informations.';
			$yes    = 'Accepter';
			$html_lang = 'fr';
		}
		$ajax = admin_url( 'admin-ajax.php' );
		$cookies_url = '';
		$cookies_page = get_page_by_path( 'cookies' );
		if ( $cookies_page instanceof WP_Post ) {
			$cookies_url = get_permalink( $cookies_page );
		}
		?>
		<div class="pk-cookie-bar" data-pk-cookie data-ajax="<?php echo esc_url( $ajax ); ?>" lang="<?php echo esc_attr( $html_lang ); ?>" hidden>
			<p>
				<?php echo esc_html( $before ); ?>
				<?php if ( $cookies_url ) : ?>
					<a href="<?php echo esc_url( $cookies_url ); ?>"><?php echo esc_html( $link ); ?></a>
				<?php else : ?>
					<?php echo esc_html( $link ); ?>
				<?php endif; ?>
				<?php echo esc_html( $after ); ?>
			</p>
			<div class="pk-cookie-actions">
				<button type="button" data-yes><?php echo esc_html( $yes ); ?></button>
			</div>
		</div>
		<style>
			.pk-cookie-bar[hidden] { display: none !important; }
			.pk-cookie-bar {
				position: fixed; left: 0; right: 0; bottom: 0; z-index: 40;
				display: flex; gap: 16px; align-items: center; justify-content: space-between;
				background: #fff; color: #111; border-top: 1px solid #e6e1d8;
				padding: 14px 20px; font-family: system-ui, sans-serif;
			}
			.pk-cookie-bar p { margin: 0; font-size: 14px; line-height: 1.4; }
			.pk-cookie-bar a { color: #111; text-decoration: underline; }
			.pk-cookie-actions { display: flex; gap: 8px; flex: 0 0 auto; }
			.pk-cookie-actions button {
				min-width: 120px; height: 40px; padding: 0 16px; border: 1px solid #323232;
				background: #fff; color: #323232; font: inherit; font-weight: 650; cursor: pointer;
			}
			.pk-cookie-actions button[data-yes] { background: #C8242B; color: #fff; }
			body.pk-cookie-open { padding-bottom: 84px; }
			@media (max-width: 640px) {
				.pk-cookie-bar { flex-direction: column; align-items: stretch; }
				.pk-cookie-actions button { flex: 1; }
			}
		</style>
		<script>
		(function () {
			var bar = document.querySelector("[data-pk-cookie]");
			if (!bar) return;
			function read() {
				var match = document.cookie.match(/(?:^|; )pk_measure=(yes|no)(?:;|$)/);
				return match ? match[1] : "";
			}
			function write(value) {
				document.cookie = "pk_measure=" + value + ";path=/;max-age=15552000;SameSite=Lax";
			}
			function gateOpen() {
				var gate = document.querySelector("[data-pk-lang-gate]");
				return !!(gate && !gate.hidden);
			}
			function show() {
				if (read() || gateOpen()) return;
				bar.hidden = false;
				document.body.classList.add("pk-cookie-open");
			}
			function hide() {
				bar.hidden = true;
				document.body.classList.remove("pk-cookie-open");
			}
			function count() {
				var key = "pk_counted:" + location.pathname;
				try { if (window.sessionStorage && sessionStorage.getItem(key)) return; } catch (err) { /* ignore */ }
				var body = new URLSearchParams();
				body.set("action", "pk_visit_count");
				fetch(bar.getAttribute("data-ajax"), {
					method: "POST",
					credentials: "same-origin",
					headers: { "Content-Type": "application/x-www-form-urlencoded" },
					body: body.toString()
				}).then(function () {
					try { if (window.sessionStorage) sessionStorage.setItem(key, "1"); } catch (err) { /* ignore */ }
				}).catch(function () { /* ignore */ });
			}
			bar.querySelector("[data-yes]").addEventListener("click", function () {
				write("yes");
				hide();
				count();
			});
			if (read() === "yes") count();
			show();
			var gate = document.querySelector("[data-pk-lang-gate]");
			if (gate && window.MutationObserver) {
				new MutationObserver(show).observe(gate, { attributes: true, attributeFilter: ["hidden"] });
			}
		}());
		</script>
		<?php
	}

	private static function on_deposit() {
		if ( is_page_template( 'templates/page-deposer-annonce.php' ) ) {
			return true;
		}
		if ( class_exists( 'Partikulier_Tunnel_Preview' ) && method_exists( 'Partikulier_Tunnel_Preview', 'is_deposit_request' ) && Partikulier_Tunnel_Preview::is_deposit_request() ) {
			return true;
		}
		return false;
	}
}

Partikulier_Visit_Counter::init();
