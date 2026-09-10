<?php
/**
 * Partikulier — Personnalisation du site sans code.
 *
 * Les textes éditoriaux sont stockés par langue dans l’option du thème.
 * L’aperçu est non destructif : il transmet les valeurs courantes à une
 * iframe de prévisualisation, sans les enregistrer en base.
 *
 * @package Partikulier
 */
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Partikulier_Customization {
	const OPTION    = 'pk_customization_options';
	const THEME_OPTION = 'pk_theme_options';
	const MENU_SLUG = 'pk-site-customization';
	const SECTIONS  = array( 'hero', 'services', 'types', 'recent', 'promos', 'cities', 'regions' );

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
		add_action( 'admin_post_pk_save_customization', array( __CLASS__, 'save' ) );
	}

	public static function admin_menu() {
		add_menu_page( __( 'Partikulier', 'partikulier' ), __( 'Partikulier', 'partikulier' ), 'manage_options', 'partikulier', array( __CLASS__, 'render' ), 'dashicons-admin-home', 57 );
		add_submenu_page( 'partikulier', __( 'Personnalisation du site', 'partikulier' ), __( 'Personnalisation du site', 'partikulier' ), 'manage_options', self::MENU_SLUG, array( __CLASS__, 'render' ) );
	}

	public static function admin_assets( $hook ) {
		if ( 'toplevel_page_partikulier' !== $hook && 'partikulier_page_' . self::MENU_SLUG !== $hook ) {
			return;
		}
		wp_enqueue_media();
		wp_enqueue_script( 'jquery' );
		wp_enqueue_style( 'dashicons' );
		wp_add_inline_style( 'dashicons', '.pk-live-preview-wrap{margin:24px 0 32px;max-width:1180px}.pk-preview-devices{display:flex;gap:8px;align-items:center;margin:0 0 10px}.pk-preview-device.is-active{box-shadow:inset 0 0 0 1px #2271b1}.pk-preview-stage{padding:18px;background:#f0f0f1;overflow:auto}.pk-live-preview-wrap iframe{display:block;width:1280px;max-width:100%;min-height:760px;margin:0 auto;border:1px solid #ccd0d4;background:#fff;transition:width .18s ease}.pk-alt-alert{margin:12px 0!important}.pk-hero-alt-field.pk-alt-empty{border-color:#d63638;box-shadow:0 0 0 1px #d63638}.pk-language-panel[dir="rtl"]{direction:rtl}.pk-language-panel[dir="rtl"] .form-table th{text-align:right}.pk-language-panel[dir="rtl"] input,.pk-language-panel[dir="rtl"] textarea{text-align:right}' );
		$script = <<<'JS'
		jQuery(function($){
			var frame;
			$('.pk-media-button').on('click',function(e){
				e.preventDefault();
				var button=$(this), field=button.closest('.pk-media-field');
				frame=wp.media({title:button.data('title'),button:{text:'Utiliser cette image'},multiple:false});
				frame.on('select',function(){
					var attachment=frame.state().get('selection').first().toJSON();
					field.find('.pk-media-id').val(attachment.id);
					field.find('.pk-media-preview').html('<img src="'+attachment.url+'" alt="" style="max-width:360px;height:auto;display:block;margin-top:8px;">');
					field.find('.pk-media-preview').attr('data-preview-url',attachment.url);
					window.pkCustomizationPreview && window.pkCustomizationPreview();
				});
				frame.open();
			});
			$('.pk-media-remove').on('click',function(e){
				e.preventDefault();
				var field=$(this).closest('.pk-media-field');
				field.find('.pk-media-id').val('0');
				field.find('.pk-media-preview').empty().removeAttr('data-preview-url');
				window.pkCustomizationPreview && window.pkCustomizationPreview();
			});
			var selects=$('.pk-section-order'), hidden=$('#pk-home-order');
			function syncOrder(){ hidden.val(selects.map(function(){return this.value;}).get().join(',')); }
			selects.on('change',function(){
				var seen={};
				selects.each(function(){
					if(seen[this.value]){ alert('Chaque section doit être choisie une seule fois.'); $(this).val(''); }
					seen[this.value]=true;
				});
				syncOrder();
				window.pkCustomizationPreview && window.pkCustomizationPreview();
			});
			$('.pk-language-tab').on('click',function(){
				$('.pk-language-tab').removeClass('nav-tab-active');
				$(this).addClass('nav-tab-active');
				$('.pk-language-panel').hide().filter('[data-language="'+$(this).data('language')+'"]').show();
				$('#pk-preview-language').val($(this).data('language'));
				window.pkCustomizationPreview && window.pkCustomizationPreview();
			});
			$('.pk-language-panel').not('[data-language="fr"]').hide();
			$('.pk-preview-device').on('click',function(){
				var width=$(this).data('preview-width'), frame=document.getElementById('pk-live-preview');
				$('.pk-preview-device').removeClass('is-active').attr('aria-pressed','false');
				$(this).addClass('is-active').attr('aria-pressed','true');
				if(frame){ frame.style.width=width+'px'; }
			});
			var previewFrame=document.getElementById('pk-live-preview');
			function collectFields(){
				var fields={}, language=$('#pk-preview-language').val()||'fr';
				$('.pk-language-panel[data-language="'+language+'"] [data-preview-key]').each(function(){ fields[$(this).data('preview-key')]=$(this).val(); });
				var hero=$('.pk-hero-media .pk-media-preview').attr('data-preview-url')||'';
				var logo=$('.pk-logo-media .pk-media-preview').attr('data-preview-url')||'';
				return {type:'pk-customization-preview',language:language,fields:fields,heroUrl:hero,logoUrl:logo,order:$('#pk-home-order').val()||''};
			}
			window.pkCustomizationPreview=function(){
				if(!previewFrame || !previewFrame.contentWindow){return;}
				previewFrame.contentWindow.postMessage(collectFields(),window.location.origin);
			};
				var altForm=$('.pk-customization-admin form'), altAlert=$('#pk-alt-alert'), altAttempted=false;
				function validateAlt(show){
					var empty=[];
					$('.pk-hero-alt-field').each(function(){
						var field=$(this), language=field.data('alt-language');
						field.toggleClass('pk-alt-empty',!$.trim(field.val()));
						if(!$.trim(field.val())){empty.push(language);}
					});
					if(show || altAttempted){
						if(empty.length){ altAlert.text('Renseignez le texte alternatif du hero pour : '+empty.join(', ')+'. La sauvegarde est bloquée tant que ces champs sont vides.').removeAttr('hidden').show(); }
						else { altAlert.text('').hide().attr('hidden','hidden'); }
					} else { altAlert.text('').hide().attr('hidden','hidden'); }
					return !empty.length;
				}
				$('.pk-hero-alt-field').on('input',function(){ validateAlt(false); window.pkCustomizationPreview(); });
				altForm.on('submit',function(e){
					altAttempted=true;
					if(!validateAlt(true)){
						e.preventDefault();
						var first=$('.pk-hero-alt-field.pk-alt-empty').first(), language=first.data('alt-language');
						$('.pk-language-tab[data-language="'+language+'"]').trigger('click');
						first.trigger('focus');
					}
				});
				if(previewFrame){ previewFrame.addEventListener('load',function(){ window.pkCustomizationPreview(); }); }
		});
		JS;
		wp_add_inline_script( 'jquery', $script );
	}

	/**
	 * Contrat H : toutes les clés éditoriales de la home existent même si
	 * l’option est absente ou partielle. Les valeurs restent dans l’option
	 * dédiée de personnalisation, jamais dans les réglages fonctionnels.
	 */
	public static function defaults() {
		return array(
			'home_title' => array(
				'fr' => 'Vendez et louez entre particuliers.',
									'en' => 'Buy and rent directly from private owners.',
					'ar' => 'اشترِ واكترِ مباشرة من المالكين.',
				),
				'home_intro' => array(
					'fr' => 'Déposez votre annonce immobilière gratuitement, sans commission, sans intermédiaire. Directement aux acheteurs et locataires.',
					'en' => 'Post your property for free, with no commission or middleman. Reach buyers and tenants directly.',
					'ar' => 'أضف عقارك مجاناً، بدون عمولة أو وسيط. تواصل مباشرة مع المشترين والمستأجرين.',
				),
				'hero_alt' => array(
					'fr' => 'Maison moderne à vendre, annonces immobilières entre particuliers',
					'en' => 'Modern house for sale, private-owner property listings',
					'ar' => 'منزل عصري للبيع، إعلانات عقارية من المالك مباشرة',
				),
				'badge_1' => array( 'fr' => 'Zéro commission', 'en' => 'No commission', 'ar' => 'بدون عمولة' ),
				'badge_2' => array( 'fr' => 'Vendeur identifié', 'en' => 'Verified seller', 'ar' => 'بائع موثوق' ),
				'badge_3' => array( 'fr' => 'Contact direct', 'en' => 'Direct contact', 'ar' => 'اتصال مباشر' ),

		);
	}

	public static function editorial( $key, $fallback = '' ) {
		$defaults = self::defaults();
		$values = isset( $defaults[ $key ] ) ? $defaults[ $key ] : array( 'fr' => $fallback, 'en' => '', 'ar' => '' );
		$options = get_option( self::OPTION, array() );
		$options = is_array( $options ) ? $options : array();
		$stored = isset( $options['editorial'] ) && is_array( $options['editorial'] ) ? $options['editorial'] : array();
		$current = Partikulier_Settings::current_language();
		foreach ( array_unique( array( $current, 'fr' ) ) as $language ) {
			$value = $stored[ $key ][ $language ] ?? $values[ $language ] ?? $fallback;
			if ( '' !== trim( (string) $value ) ) {
				return (string) $value;
			}
		}
		return (string) $fallback;
	}

	public static function get( $key, $fallback = '' ) {
		$options = get_option( self::OPTION, array() );
		if ( is_array( $options ) && isset( $options[ $key ] ) && '' !== $options[ $key ] ) {
			return $options[ $key ];
		}
		return $fallback;
	}

	public static function hero_url() {
		$id  = absint( self::get( 'hero_attachment_id', 0 ) );
		$url = $id ? wp_get_attachment_image_url( $id, 'full' ) : '';
		return $url ? $url : get_theme_file_uri( 'assets/img/hero.jpg' );
	}

	public static function logo_url() {
		$id = absint( self::get( 'logo_attachment_id', 0 ) );
		if ( $id ) {
			$url = (string) wp_get_attachment_image_url( $id, 'full' );
			if ( $url ) {
				return $url;
			}
		}
		// Pas de fichier logo par defaut : la marque est rendue en texte dans le header.
		// Retourner une chaine vide permet aux appelants (JSON-LD, admin) de savoir
		// qu'aucune image n'est disponible plutot que de pointer un fichier absent.
		return '';
	}

	/**
	 * Texte alternatif du hero résolu selon la langue Polylang active.
	 */
	public static function hero_alt( $fallback = '' ) {
		$options = get_option( self::OPTION, array() );
		$alts    = is_array( $options ) && isset( $options['hero_image_alt_i18n'] ) && is_array( $options['hero_image_alt_i18n'] ) ? $options['hero_image_alt_i18n'] : array();
		$current = Partikulier_Settings::current_language();
		$default = 'fr';
		if ( function_exists( 'pll_default_language' ) ) {
			try {
				$language = (string) pll_default_language( 'slug' );
				$default = isset( Partikulier_Settings::editorial_languages()[ $language ] ) ? $language : 'fr';
			} catch ( Throwable $exception ) {
				// Le français reste le repli sûr si Polylang n’est pas initialisé.
			}
		}
		foreach ( array_unique( array( $current, $default ) ) as $language ) {
			if ( isset( $alts[ $language ] ) && '' !== trim( (string) $alts[ $language ] ) ) {
				return (string) $alts[ $language ];
			}
		}
		return (string) self::get( 'hero_image_alt', $fallback );
	}

	public static function section_order() {
		$raw   = self::get( 'home_section_order', implode( ',', self::SECTIONS ) );
		$items = array_values( array_unique( array_intersect( array_filter( array_map( 'sanitize_key', explode( ',', (string) $raw ) ) ), self::SECTIONS ) ) );
		return array_values( array_merge( $items, array_diff( self::SECTIONS, $items ) ) );
	}

	public static function localized_fields() {
		$fields = array();
		foreach ( Partikulier_Settings::fields() as $group_key => $group ) {
			if ( 'verification' !== $group_key ) {
				$fields = array_merge( $fields, $group['fields'] );
			}
		}
		return $fields;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'partikulier' ), 403 );
		}
		check_admin_referer( 'pk_save_customization' );
		$custom = get_option( self::OPTION, array() );
		$theme  = get_option( Partikulier_Settings::OPTION, array() );
		$posted = isset( $_POST['pk_opts'] ) && is_array( $_POST['pk_opts'] ) ? wp_unslash( $_POST['pk_opts'] ) : array();
		/* Le Personnalisateur ecrivant desormais dans pk_theme_options[cle], on accepte
		   aussi ce nom ici : les deux interfaces doivent ecrire au meme endroit. */
		$theme_posted = isset( $_POST['pk_theme_options'] ) && is_array( $_POST['pk_theme_options'] ) ? wp_unslash( $_POST['pk_theme_options'] ) : array();
		unset( $theme_posted['localized'] );
		if ( $theme_posted ) {
			$posted = array_merge( $posted, $theme_posted );
		}
		$custom['logo_attachment_id'] = absint( $posted['logo_attachment_id'] ?? 0 );
		$custom['hero_attachment_id'] = absint( $posted['hero_attachment_id'] ?? 0 );
			$custom['hero_image_alt'] = sanitize_text_field( $posted['hero_image_alt'] ?? 'Maison moderne à vendre, annonces immobilières entre particuliers' );
			$defaults = self::defaults();
			if ( ! isset( $custom['editorial'] ) || ! is_array( $custom['editorial'] ) ) {
				$custom['editorial'] = array();
			}
			foreach ( $defaults as $key => $languages ) {
				if ( isset( $posted['editorial'][ $key ] ) && is_array( $posted['editorial'][ $key ] ) ) {
					foreach ( Partikulier_Settings::editorial_languages() as $language => $label ) {
						if ( array_key_exists( $language, $posted['editorial'][ $key ] ) ) {
							$raw = wp_unslash( $posted['editorial'][ $key ][ $language ] );
							$custom['editorial'][ $key ][ $language ] = 'home_intro' === $key ? wp_kses( $raw, array( 'a' => array( 'href' => true, 'title' => true ), 'strong' => array(), 'em' => array(), 'br' => array() ) ) : sanitize_text_field( $raw );
						}
					}
				}
			}
			if ( isset( $custom['editorial']['home_intro']['fr'] ) && '' === trim( (string) $custom['editorial']['home_intro']['fr'] ) ) {
				$custom['editorial']['home_intro']['fr'] = $defaults['home_intro']['fr'];
			}
		if ( ! isset( $custom['hero_image_alt_i18n'] ) || ! is_array( $custom['hero_image_alt_i18n'] ) ) {
			$custom['hero_image_alt_i18n'] = array();
		}
		if ( isset( $posted['hero_image_alt_i18n'] ) && is_array( $posted['hero_image_alt_i18n'] ) ) {
			foreach ( Partikulier_Settings::editorial_languages() as $language => $label ) {
				if ( array_key_exists( $language, $posted['hero_image_alt_i18n'] ) ) {
					$custom['hero_image_alt_i18n'][ $language ] = sanitize_text_field( $posted['hero_image_alt_i18n'][ $language ] );
				}
			}
			if ( isset( $custom['hero_image_alt_i18n']['fr'] ) && '' !== $custom['hero_image_alt_i18n']['fr'] ) {
				$custom['hero_image_alt'] = $custom['hero_image_alt_i18n']['fr'];
			}
		}
		$order = array_values( array_unique( array_intersect( array_filter( array_map( 'sanitize_key', explode( ',', (string) ( $posted['home_section_order'] ?? '' ) ) ) ), self::SECTIONS ) ) );
		$custom['home_section_order'] = implode( ',', array_values( array_merge( $order, array_diff( self::SECTIONS, $order ) ) ) );
		if ( ! isset( $theme['localized'] ) || ! is_array( $theme['localized'] ) ) {
			$theme['localized'] = array();
		}
		foreach ( Partikulier_Settings::editorial_languages() as $language => $label ) {
			if ( ! isset( $posted['localized'][ $language ] ) || ! is_array( $posted['localized'][ $language ] ) ) {
				continue;
			}
			foreach ( self::localized_fields() as $key => $field ) {
				if ( array_key_exists( $key, $posted['localized'][ $language ] ) ) {
					$theme['localized'][ $language ][ $key ] = 'textarea' === ( $field['type'] ?? '' ) ? sanitize_textarea_field( $posted['localized'][ $language ][ $key ] ) : sanitize_text_field( $posted['localized'][ $language ][ $key ] );
				}
			}
		}
		foreach ( Partikulier_Settings::fields() as $group ) {
			foreach ( $group['fields'] as $key => $field ) {
				if ( 'automation_api_secret' === $key || ! array_key_exists( $key, $posted ) ) {
					continue;
				}
				$theme[ $key ] = 'textarea' === ( $field['type'] ?? '' ) ? sanitize_textarea_field( $posted[ $key ] ) : sanitize_text_field( $posted[ $key ] );
			}
		}
			if ( $custom['hero_attachment_id'] && empty( $custom['hero_image_alt_i18n']['fr'] ) ) {
				set_transient( 'pk_customization_invalid_' . get_current_user_id(), $posted, 10 * MINUTE_IN_SECONDS );
				wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'pk_validation_error' => 'hero_alt' ), admin_url( 'admin.php' ) ) );
				exit;
			}
			update_option( self::OPTION, $custom );
			update_option( Partikulier_Settings::OPTION, $theme );
		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'updated' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Accès non autorisé.', 'partikulier' ), 403 );
		}
			$custom   = get_option( self::OPTION, array() );
			$theme    = get_option( Partikulier_Settings::OPTION, array() );
			$invalid_posted = get_transient( 'pk_customization_invalid_' . get_current_user_id() );
			if ( is_array( $invalid_posted ) ) {
				$custom['editorial'] = isset( $invalid_posted['editorial'] ) && is_array( $invalid_posted['editorial'] ) ? $invalid_posted['editorial'] : ( $custom['editorial'] ?? array() );
				$custom['hero_image_alt_i18n'] = isset( $invalid_posted['hero_image_alt_i18n'] ) && is_array( $invalid_posted['hero_image_alt_i18n'] ) ? $invalid_posted['hero_image_alt_i18n'] : ( $custom['hero_image_alt_i18n'] ?? array() );
				delete_transient( 'pk_customization_invalid_' . get_current_user_id() );
			}
		$labels   = array( 'hero' => 'Hero et recherche', 'services' => 'Bande des services', 'types' => 'Types de biens', 'recent' => 'Dernières annonces', 'promos' => 'Bandeaux mis en avant', 'cities' => 'Villes populaires', 'regions' => 'Annonces par région' );
		$order    = self::section_order();
		$languages = Partikulier_Settings::editorial_languages();
		?>
		<div class="wrap pk-customization-admin">
			<h1><?php esc_html_e( 'Partikulier — Personnalisation du site', 'partikulier' ); ?></h1>
			<p><?php esc_html_e( 'Modifiez les contenus de l’accueil sans toucher au code. Les textes sont indépendants pour le français, l’arabe et l’anglais ; si une traduction est vide, le français est utilisé comme repli.', 'partikulier' ); ?></p>
			<?php if ( isset( $_GET['updated'] ) ) : ?><div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Personnalisation enregistrée.', 'partikulier' ); ?></p></div><?php endif; ?>
			<?php if ( isset( $_GET['pk_validation_error'] ) && 'hero_alt' === $_GET['pk_validation_error'] ) : ?><div class="notice notice-error"><p><?php esc_html_e( 'L’alt FR est obligatoire lorsque la photo hero est active. La sauvegarde n’a pas été appliquée.', 'partikulier' ); ?></p></div><?php endif; ?>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="pk_save_customization">
				<?php wp_nonce_field( 'pk_save_customization' ); ?>
				<table class="form-table" role="presentation"><tbody>
				<tr><th scope="row">Logo du site</th><td><div class="pk-media-field pk-logo-media"><input class="pk-media-id" type="hidden" name="pk_opts[logo_attachment_id]" value="<?php echo esc_attr( self::get( 'logo_attachment_id', 0 ) ); ?>"><button class="button pk-media-button" data-title="Choisir le logo">Choisir dans la médiathèque</button> <button class="button pk-media-remove">Retirer</button><div class="pk-media-preview" data-preview-url="<?php echo esc_attr( self::logo_url() ); ?>"><?php if ( self::get( 'logo_attachment_id', 0 ) ) { echo wp_get_attachment_image( absint( self::get( 'logo_attachment_id', 0 ) ), 'medium', false, array( 'style' => 'max-width:260px;height:auto;margin-top:8px;' ) ); } ?></div></div><p class="description">Logo transparent recommandé. Le logo texte actuel reste le repli si aucun fichier n’est choisi.</p></td></tr>
				<tr><th scope="row">Photo hero de l’accueil</th><td><div class="pk-media-field pk-hero-media"><input class="pk-media-id" type="hidden" name="pk_opts[hero_attachment_id]" value="<?php echo esc_attr( self::get( 'hero_attachment_id', 0 ) ); ?>"><button class="button pk-media-button" data-title="Choisir la photo hero">Choisir dans la médiathèque</button> <button class="button pk-media-remove">Retirer</button><div class="pk-media-preview" data-preview-url="<?php echo esc_attr( self::hero_url() ); ?>"><?php if ( self::get( 'hero_attachment_id', 0 ) ) { echo wp_get_attachment_image( absint( self::get( 'hero_attachment_id', 0 ) ), 'medium', false, array( 'style' => 'max-width:360px;height:auto;margin-top:8px;' ) ); } ?></div></div><p class="description">Cette photo concerne uniquement la couverture de l’accueil, pas les photos des annonces Estatik.</p></td></tr>
				<tr><th scope="row">Ordre des sections de l’accueil</th><td><?php foreach ( $order as $position => $section ) : ?><p><label><span class="screen-reader-text">Section à la position <?php echo esc_html( $position + 1 ); ?></span><select class="pk-section-order"><option value="">—</option><?php foreach ( $labels as $key => $label ) : ?><option value="<?php echo esc_attr( $key ); ?>" <?php selected( $section, $key ); ?>><?php echo esc_html( $label ); ?></option><?php endforeach; ?></select></label></p><?php endforeach; ?><input id="pk-home-order" type="hidden" name="pk_opts[home_section_order]" value="<?php echo esc_attr( implode( ',', $order ) ); ?>"><p class="description">L’ordre est rendu côté serveur dans le HTML public. Les sections sans annonces restent naturellement masquées.</p></td></tr>
				</tbody></table>
				<h2>Textes éditoriaux par langue</h2>
				<p class="description">Choisissez une langue, modifiez ses textes, puis vérifiez le résultat dans l’aperçu à droite. Les champs vides en arabe ou en anglais utilisent le français comme repli.</p>
				<div class="nav-tab-wrapper"><?php foreach ( $languages as $language => $label ) : ?><button type="button" class="nav-tab pk-language-tab<?php echo 'fr' === $language ? ' nav-tab-active' : ''; ?>" data-language="<?php echo esc_attr( $language ); ?>"><?php echo esc_html( $label ); ?></button><?php endforeach; ?></div>
				<?php foreach ( $languages as $language => $label ) : ?>
						<div class="pk-language-panel" data-language="<?php echo esc_attr( $language ); ?>"<?php echo 'ar' === $language ? ' dir="rtl"' : ''; ?>>
							<h3><?php echo esc_html( $label ); ?></h3>
							<h4><?php esc_html_e( 'Éditorialisation de la home — lot H', 'partikulier' ); ?></h4>
							<table class="form-table" role="presentation"><tbody>
							<?php foreach ( self::defaults() as $h_key => $h_defaults ) : $h_value = $custom['editorial'][ $h_key ][ $language ] ?? ( 'fr' === $language ? $h_defaults['fr'] : '' ); $h_label = array( 'home_title' => 'Titre de la home', 'home_intro' => 'Introduction de la home', 'hero_alt' => 'Alt hero dans cette langue', 'badge_1' => 'Badge 1', 'badge_2' => 'Badge 2', 'badge_3' => 'Badge 3' )[ $h_key ]; ?>
							<tr><th scope="row"><label for="pk-h-<?php echo esc_attr( $language . '-' . $h_key ); ?>"><?php echo esc_html( $h_label ); ?></label></th><td><?php if ( 'home_intro' === $h_key ) : ?><textarea class="large-text" rows="3" id="pk-h-<?php echo esc_attr( $language . '-' . $h_key ); ?>" name="pk_opts[editorial][<?php echo esc_attr( $h_key ); ?>][<?php echo esc_attr( $language ); ?>]" maxlength="400"><?php echo esc_textarea( $h_value ); ?></textarea><?php else : ?><input class="regular-text" type="text" maxlength="120" id="pk-h-<?php echo esc_attr( $language . '-' . $h_key ); ?>" name="pk_opts[editorial][<?php echo esc_attr( $h_key ); ?>][<?php echo esc_attr( $language ); ?>]" value="<?php echo esc_attr( $h_value ); ?>"><?php endif; ?></td></tr>
							<?php endforeach; ?></tbody></table>
							<table class="form-table" role="presentation"><tbody><tr><th scope="row"><label for="pk-<?php echo esc_attr( $language ); ?>-hero-alt">Texte alternatif de la photo hero</label></th><td><input id="pk-<?php echo esc_attr( $language ); ?>-hero-alt" class="regular-text pk-hero-alt-field" type="text" name="pk_opts[hero_image_alt_i18n][<?php echo esc_attr( $language ); ?>]" value="<?php echo esc_attr( $custom['hero_image_alt_i18n'][ $language ] ?? ( 'fr' === $language ? ( $custom['hero_image_alt'] ?? 'Maison moderne à vendre, annonces immobilières entre particuliers' ) : '' ) ); ?>" data-preview-key="hero_image_alt" data-alt-language="<?php echo esc_attr( strtoupper( $language ) ); ?>"><p class="description">Décrivez précisément la photo dans cette langue pour l’accessibilité et le référencement.</p></td></tr></tbody></table>
						<?php foreach ( Partikulier_Settings::fields() as $group_key => $group ) : if ( 'verification' === $group_key ) { continue; } ?>
							<h4><?php echo esc_html( $group['label'] ); ?></h4><table class="form-table" role="presentation"><tbody>
							<?php foreach ( $group['fields'] as $key => $field ) : $value = $theme['localized'][ $language ][ $key ] ?? ( 'fr' === $language ? ( $theme[ $key ] ?? $field['default'] ) : '' ); ?>
							<tr><th scope="row"><label for="pk-<?php echo esc_attr( $language . '-' . $key ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th><td><?php if ( 'textarea' === ( $field['type'] ?? '' ) ) : ?><textarea class="large-text" rows="3" id="pk-<?php echo esc_attr( $language . '-' . $key ); ?>" name="pk_opts[localized][<?php echo esc_attr( $language ); ?>][<?php echo esc_attr( $key ); ?>]" data-preview-key="<?php echo esc_attr( $key ); ?>"><?php echo esc_textarea( $value ); ?></textarea><?php else : ?><input class="regular-text" type="text" id="pk-<?php echo esc_attr( $language . '-' . $key ); ?>" name="pk_opts[localized][<?php echo esc_attr( $language ); ?>][<?php echo esc_attr( $key ); ?>]" value="<?php echo esc_attr( $value ); ?>" data-preview-key="<?php echo esc_attr( $key ); ?>"><?php endif; ?></td></tr>
							<?php endforeach; ?></tbody></table>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
				<input type="hidden" id="pk-preview-language" value="fr"><div id="pk-alt-alert" class="notice notice-warning inline pk-alt-alert" role="alert" aria-live="polite" hidden></div>
				<div class="pk-live-preview-wrap"><h2>Aperçu en direct — non sauvegardé</h2><div class="pk-preview-devices" role="group" aria-label="Format de l’aperçu"><button type="button" class="button pk-preview-device is-active" data-preview-width="1280" aria-pressed="true">Desktop</button><button type="button" class="button pk-preview-device" data-preview-width="768" aria-pressed="false">Tablette</button><button type="button" class="button pk-preview-device" data-preview-width="390" aria-pressed="false">Mobile</button></div><div class="pk-preview-stage"><iframe id="pk-live-preview" title="Aperçu en direct de la page d’accueil" src="<?php echo esc_url( add_query_arg( 'pk_admin_preview', '1', home_url( '/' ) ) ); ?>"></iframe></div></div>
				<?php submit_button( 'Enregistrer les personnalisations' ); ?>
			</form>
		</div>
		<?php
	}
}
Partikulier_Customization::init();