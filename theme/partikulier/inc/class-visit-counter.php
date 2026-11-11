<?php
/**
 * Compteur de visites, seulement après un oui.
 *
 * Le bandeau vient après le choix de langue et propose un vrai choix :
 * accepter le comptage agrégé des visites ou continuer sans mesure.
 * Le refus ne déclenche aucun comptage ni appel réseau.
 *
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
			$before = 'We can count visits in aggregate, without names or profiles. This measurement starts only with your consent. ';
			$link   = 'Read the cookie policy';
			$after  = '.';
			$yes    = 'Allow visit counting';
			$no     = 'Continue without measurement';
			$html_lang = 'en';
		} elseif ( 'ar' === $lang ) {
			$before = 'يمكن للموقع إحصاء الزيارات بشكل إجمالي، دون أسماء أو ملفات تعريف. لا يبدأ هذا القياس إلا بموافقتك. ';
			$link   = 'الاطلاع على سياسة ملفات تعريف الارتباط';
			$after  = '.';
			$yes    = 'أوافق على إحصاء الزيارات';
			$no     = 'المتابعة دون قياس';
			$html_lang = 'ar';
		} else {
			$before = 'Le site peut compter les visites de manière agrégée, sans nom ni profil. Cette mesure ne démarre qu’avec votre accord. ';
			$link   = 'Consulter la politique de cookies';
			$after  = '.';
			$yes    = 'Accepter la mesure';
			$no     = 'Continuer sans mesure';
			$html_lang = 'fr';
		}
		$ajax = admin_url( 'admin-ajax.php' );
		$cookies_url = '';
		$cookies_page = get_page_by_path( 'cookies' );
		$on_cookies = false;
		if ( $cookies_page instanceof WP_Post ) {
			$target_id = (int) $cookies_page->ID;
			if ( function_exists( 'pll_get_post' ) ) {
				$lang_id = (int) pll_get_post( $cookies_page->ID, $lang );
				if ( $lang_id > 0 && 'publish' === get_post_status( $lang_id ) ) {
					$target_id = $lang_id;
				}
			}
			$cookies_url = (string) get_permalink( $target_id );
			$cookie_ids  = array( (int) $cookies_page->ID );
			if ( function_exists( 'pll_get_post' ) ) {
				foreach ( array( 'fr', 'en', 'ar' ) as $cookie_lang ) {
					$translated = pll_get_post( $cookies_page->ID, $cookie_lang );
					if ( $translated ) {
						$cookie_ids[] = (int) $translated;
					}
				}
			}
			$on_cookies = is_page( array_values( array_unique( $cookie_ids ) ) );
		}
		?>
		<div class="pk-cookie-bar<?php echo $on_cookies ? ' pk-cookie-info' : ''; ?>" data-pk-cookie data-ajax="<?php echo esc_url( $ajax ); ?>" lang="<?php echo esc_attr( $html_lang ); ?>" hidden>
			<div class="pk-cookie-card">
				<p id="pk-cookie-text">
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
					<button type="button" data-no><?php echo esc_html( $no ); ?></button>
				</div>
			</div>
		</div>
		<style>
			.pk-cookie-bar[hidden] { display: none !important; }
			.pk-cookie-bar {
				position: fixed; left: 0; right: 0; bottom: 0; z-index: 6000;
			}
			.pk-cookie-card {
				display: flex; gap: 16px; align-items: center; justify-content: space-between;
				background: #fff; color: #111; border-top: 1px solid #e6e1d8;
				padding: 14px 20px; font-family: system-ui, sans-serif;
			}
			.pk-cookie-bar p { margin: 0; font-size: 14px; line-height: 1.4; }
			.pk-cookie-bar a { color: #111; text-decoration: underline; }
			.pk-cookie-actions { display: flex; flex-wrap: wrap; gap: 8px; flex: 0 0 auto; }
			.pk-cookie-actions button {
				flex: 1 1 140px; min-width: 120px; min-height: 40px; height: auto; padding: 8px 12px; border: 1px solid #323232;
				background: #fff; color: #323232; font: inherit; font-weight: 650; cursor: pointer;
			}
			.pk-cookie-actions button[data-yes] { background: #C8242B; color: #fff; border-color: #C8242B; }
			.pk-cookie-actions button[data-no] { background: #fff; color: #323232; border-color: #323232; }
			.pk-cookie-actions button[data-yes]:hover,
			.pk-cookie-actions button[data-yes]:active { background: #9A1B21; border-color: #9A1B21; color: #fff; }
			.pk-cookie-actions button[data-no]:hover,
			.pk-cookie-actions button[data-no]:active { background: #f5f5f0; border-color: #161715; color: #161715; }
			body.pk-cookie-open { padding-bottom: 84px; }
			@media (max-width: 767px) {
				.pk-cookie-bar {
					inset: 0; z-index: 6000;
					display: grid; place-items: center;
					background: rgba(16, 18, 16, .48);
					padding: 1rem;
				}
				.pk-cookie-card {
					flex-direction: column; align-items: stretch;
					width: min(24rem, 100%);
					border: 1px solid #e6e5df; border-radius: 16px;
					box-shadow: 0 18px 50px rgba(16, 18, 16, .2);
					padding: 1.15rem 1.1rem 1rem;
				}
				.pk-cookie-bar.pk-cookie-info {
					inset: 0 0 auto 0; display: block;
					background: transparent; pointer-events: none;
					padding: .75rem;
				}
				.pk-cookie-bar.pk-cookie-info .pk-cookie-card { pointer-events: auto; }
				body.pk-cookie-open { overflow: hidden; padding-bottom: 0; }
				body.pk-cookie-open:has(.pk-cookie-bar.pk-cookie-info) {
					overflow: auto;
					padding-bottom: 68px;
				}
			}
			/* Le refus reste cliquable au-dessus du choix de langue.
			   Les rouges signalés par l'audit passent AA : texte blanc sur #C8242B,
			   lien annonce #C8242B sur blanc. */
			html body.home .pk-editorial-places a.pk-btn.pk-btn-dark,
			html body.home a.pk-btn.pk-btn-dark {
				background: #C8242B !important;
				background-color: #C8242B !important;
				border-color: #C8242B !important;
				color: #fff !important;
			}
			html body.home .pk-card-foot a.pk-card-cta,
			html body.home .pk-card-foot a.pk-card-cta:link,
			html body.home .pk-card-foot a.pk-card-cta:visited,
			html body.home .pk-card-foot a.pk-card-cta span {
				color: #C8242B !important;
				background-color: #fff !important;
			}
			html body.home .pk-cookie-actions button[data-yes],
			html body .pk-cookie-actions button[data-yes] {
				background: #C8242B !important;
				background-color: #C8242B !important;
				border-color: #C8242B !important;
				color: #fff !important;
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
			function mobileCookie() {
				return window.matchMedia("(max-width: 767px)").matches;
			}
			function placeInfo() {
				if (!mobileCookie() || bar.hidden || !bar.classList.contains("pk-cookie-info")) {
					document.body.style.paddingTop = "";
					return;
				}
				var card = bar.querySelector(".pk-cookie-card");
				document.body.style.paddingTop = card ? (card.offsetHeight + 12) + "px" : "";
			}
			function markInfo() {
				var link = bar.querySelector(".pk-cookie-card a[href]");
				if (!link) return;
				try {
					var path = new URL(link.href, location.href).pathname.replace(/\/+$/, "");
					var here = location.pathname.replace(/\/+$/, "");
					if (path && path === here) bar.classList.add("pk-cookie-info");
				} catch (err) { /* ignore */ }
			}
			function show() {
				if (read()) return;
				markInfo();
				bar.hidden = false;
				document.body.classList.add("pk-cookie-open");
				if (mobileCookie()) {
					bar.setAttribute("role", "dialog");
					bar.setAttribute("aria-modal", bar.classList.contains("pk-cookie-info") ? "false" : "true");
					bar.setAttribute("aria-labelledby", "pk-cookie-text");
					if (!bar.hasAttribute("data-pk-focused")) {
						bar.setAttribute("data-pk-focused", "1");
						var yes = bar.querySelector("[data-yes]");
						if (yes) yes.focus();
					}
				} else {
					bar.removeAttribute("role");
					bar.removeAttribute("aria-modal");
					bar.removeAttribute("aria-labelledby");
				}
				placeInfo();
			}
			function hide() {
				bar.hidden = true;
				document.body.classList.remove("pk-cookie-open");
				document.body.style.paddingTop = "";
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
			function choose(value) {
				write(value);
				hide();
				if (value === "yes") count();
			}
			bar.querySelector("[data-yes]").addEventListener("click", function () { choose("yes"); });
			bar.querySelector("[data-no]").addEventListener("click", function () { choose("no"); });
			document.addEventListener("click", function (event) {
				if (bar.hidden || !mobileCookie() || !bar.classList.contains("pk-cookie-info")) return;
				if (event.target.closest(".pk-cookie-card")) return;
				event.preventDefault();
				event.stopPropagation();
			}, true);
			document.addEventListener("keydown", function (event) {
				if (bar.hidden || !mobileCookie() || event.key !== "Tab") return;
				var nodes = bar.querySelectorAll("a[href], button");
				if (!nodes.length) return;
				var first = nodes[0];
				var last = nodes[nodes.length - 1];
				if (event.shiftKey && document.activeElement === first) {
					event.preventDefault();
					last.focus();
				} else if (!event.shiftKey && document.activeElement === last) {
					event.preventDefault();
					first.focus();
				} else if (!bar.contains(document.activeElement)) {
					event.preventDefault();
					first.focus();
				}
			});
			if (read() === "yes") count();
			show();
			window.addEventListener("resize", function () {
				if (read()) return;
				show();
			});
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
