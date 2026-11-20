<?php
/**
 * Settings screen.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce → Hilo Chat settings page.
 */
class HILO_Admin {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_post_hilo_save_settings', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_hilo_delete_resolved', array( __CLASS__, 'delete_resolved' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( HILO_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
		add_filter( 'admin_body_class', array( __CLASS__, 'body_class' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Menu entries.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', 'Hilo Chat', 'Hilo Chat', 'manage_options', 'hilo-chat', array( __CLASS__, 'render' ) );
		// Shortcut for agents (including non-admins) to the app.
		add_menu_page( __( 'Chats', 'hilo-chat' ), __( 'Chats', 'hilo-chat' ), HILO_CAP, 'hilo-chat-app', '__return_null', 'dashicons-format-chat', 56 );
		global $submenu;
		if ( isset( $submenu['hilo-chat-app'] ) ) {
			unset( $submenu['hilo-chat-app'] );
		}
		add_action(
			'admin_head',
			static function () {
				printf( '<script>document.addEventListener("DOMContentLoaded",function(){var a=document.querySelector("#toplevel_page_hilo-chat-app a");if(a){a.href=%s;a.target="_blank";a.rel="noopener";}});</script>', wp_json_encode( HILO_Settings::app_url() ) );
			}
		);
	}

	/**
	 * Plugin list links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=hilo-chat' ) ) . '">' . esc_html__( 'Settings', 'hilo-chat' ) . '</a>' );
		return $links;
	}

	/**
	 * Saves the form.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hilo-chat' ), 403 );
		}
		check_admin_referer( 'hilo_save_settings' );

		$in       = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$defaults = HILO_Settings::defaults();
		$s        = array(
			'enabled'          => ! empty( $in['enabled'] ),
			'test_mode'        => ! empty( $in['test_mode'] ),
			'hide_on_checkout' => ! empty( $in['hide_on_checkout'] ),
			'brand_name'       => sanitize_text_field( $in['brand_name'] ?? '' ),
			'title'            => sanitize_text_field( $in['title'] ?? '' ),
			'subtitle'         => sanitize_text_field( $in['subtitle'] ?? '' ),
			'greeting'         => sanitize_textarea_field( $in['greeting'] ?? '' ),
			'online_text'      => sanitize_text_field( $in['online_text'] ?? '' ),
			'offline_text'     => sanitize_textarea_field( $in['offline_text'] ?? '' ),
			'position'         => ( isset( $in['position'] ) && 'left' === $in['position'] ) ? 'left' : 'right',
			'color_primary'    => sanitize_hex_color( $in['color_primary'] ?? '' ) ?: $defaults['color_primary'],
			'color_accent'     => sanitize_hex_color( $in['color_accent'] ?? '' ) ?: $defaults['color_accent'],
			'color_soft'       => sanitize_hex_color( $in['color_soft'] ?? '' ) ?: $defaults['color_soft'],
			'font_heading'     => HILO_Widget::font( $in['font_heading'] ?? '' ),
			'font_body'        => HILO_Widget::font( $in['font_body'] ?? '' ),
			'logo_url'         => esc_url_raw( $in['logo_url'] ?? '' ),
			'app_icon_id'      => absint( $in['app_icon_id'] ?? 0 ),
			'app_slug'         => sanitize_title( $in['app_slug'] ?? '' ) ?: $defaults['app_slug'],
			'push_subject'     => sanitize_email( $in['push_subject'] ?? '' ) ?: get_option( 'admin_email' ),
			'retention_days'   => max( 0, absint( $in['retention_days'] ?? 180 ) ),
			'always_open'      => ! empty( $in['always_open'] ),
			'teaser_enabled'   => ! empty( $in['teaser_enabled'] ),
			'teaser_text'      => sanitize_text_field( $in['teaser_text'] ?? '' ),
			'teaser_delay'     => min( 120, max( 2, absint( $in['teaser_delay'] ?? 8 ) ) ),
			'hours'            => array(),
		);
		for ( $d = 0; $d < 7; $d++ ) {
			$day              = isset( $in['hours'][ $d ] ) ? (array) $in['hours'][ $d ] : array();
			$s['hours'][ $d ] = array(
				'enabled' => ! empty( $day['enabled'] ),
				'allday'  => ! empty( $day['allday'] ),
				'start'   => self::time( $day['start'] ?? '09:00', '09:00' ),
				'end'     => self::time( $day['end'] ?? '18:00', '18:00' ),
			);
		}
		HILO_Settings::save( $s );

		wp_safe_redirect( add_query_arg( array( 'page' => 'hilo-chat', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Deletes every resolved conversation.
	 */
	public static function delete_resolved() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'hilo-chat' ), 403 );
		}
		check_admin_referer( 'hilo_delete_resolved' );
		$count = HILO_DB::delete_resolved();
		wp_safe_redirect( add_query_arg( array( 'page' => 'hilo-chat', 'deleted' => $count ), admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Validates HH:MM.
	 *
	 * @param string $value    Value.
	 * @param string $fallback Fallback.
	 * @return string
	 */
	private static function time( $value, $fallback ) {
		return preg_match( '/^([01]\d|2[0-3]):[0-5]\d$/', (string) $value ) ? $value : $fallback;
	}


	/**
	 * Whether the current admin screen is ours.
	 *
	 * @return bool
	 */
	private static function is_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		return $screen && 'woocommerce_page_hilo-chat' === $screen->id;
	}

	/**
	 * Tints the admin canvas on this page only.
	 *
	 * @param string $classes Body classes.
	 * @return string
	 */
	public static function body_class( $classes ) {
		return self::is_screen() ? $classes . ' hilo-screen' : $classes;
	}

	/**
	 * Loads the screen assets.
	 *
	 * @param string $hook_suffix Screen.
	 */
	public static function enqueue( $hook_suffix ) {
		if ( 'woocommerce_page_hilo-chat' !== $hook_suffix ) {
			return;
		}
		$ver = static function ( $file ) {
			return HILO_VERSION . '.' . filemtime( HILO_PLUGIN_PATH . $file );
		};
		wp_enqueue_media();
		wp_enqueue_style( 'hilo-admin', HILO_PLUGIN_URL . 'assets/admin/admin.css', array(), $ver( 'assets/admin/admin.css' ) );
		wp_enqueue_script( 'hilo-admin', HILO_PLUGIN_URL . 'assets/admin/admin.js', array(), $ver( 'assets/admin/admin.js' ), true );
		wp_localize_script(
			'hilo-admin',
			'HiloAdmin',
			array(
				'iconTitle'   => __( 'App icon', 'hilo-chat' ),
				'defaultIcon' => HILO_PLUGIN_URL . 'assets/agent/icons/icon-192.png',
				'unsaved'     => __( 'You have unsaved changes.', 'hilo-chat' ),
				'copied'      => __( 'Copied', 'hilo-chat' ),
			)
		);
	}

	/**
	 * Renders a switch bound to a checkbox.
	 *
	 * @param string $name    Field name.
	 * @param bool   $checked State.
	 * @param string $title   Bold title.
	 * @param string $desc    Explanation.
	 * @param array  $attrs   Extra input attributes.
	 */
	private static function toggle( $name, $checked, $title, $desc = '', $attrs = array() ) {
		$extra = '';
		foreach ( $attrs as $k => $v ) {
			$extra .= ' ' . esc_attr( $k ) . '="' . esc_attr( $v ) . '"';
		}
		?>
		<label class="hilo-toggle">
			<input type="checkbox" name="<?php echo esc_attr( $name ); ?>" value="1" <?php checked( $checked ); ?><?php echo $extra; // phpcs:ignore WordPress.Security.EscapeOutput ?>>
			<span class="hilo-switch__track" aria-hidden="true"><span></span></span>
			<span class="hilo-toggle__copy"><strong><?php echo esc_html( $title ); ?></strong><?php echo esc_html( $desc ); ?></span>
		</label>
		<?php
	}

	/**
	 * Renders the page.
	 */
	public static function render() {
		global $wp_locale;
		$s        = HILO_Settings::all();
		$texts    = HILO_Settings::text_defaults();
		$days     = array( 1, 2, 3, 4, 5, 6, 0 );
		$agents   = HILO_Presence::agents_for_app();
		$icon     = $s['app_icon_id'] ? wp_get_attachment_image_url( (int) $s['app_icon_id'], 'thumbnail' ) : HILO_PLUGIN_URL . 'assets/agent/icons/icon-192.png';
		$resolved = HILO_DB::count_resolved();
		$status   = HILO_REST::status();
		$app_url  = HILO_Settings::app_url();
		/* translators: %s: store name. */
		$team_placeholder = sprintf( __( '%s team', 'hilo-chat' ), HILO_Settings::brand() );

		if ( ! $s['enabled'] ) {
			$stamp = array( 'off', __( 'Hidden', 'hilo-chat' ) );
		} elseif ( $s['test_mode'] ) {
			$stamp = array( 'test', __( 'Test mode', 'hilo-chat' ) );
		} elseif ( $status['online'] ) {
			$stamp = array( 'live', __( 'Online now', 'hilo-chat' ) );
		} else {
			$stamp = array( 'off', __( 'Offline', 'hilo-chat' ) );
		}
		?>
		<div class="wrap hilo-admin">
			<header class="hilo-header">
				<div class="hilo-header__copy">
					<h1>Hilo Chat <span class="hilo-stamp hilo-stamp--<?php echo esc_attr( $stamp[0] ); ?>"><?php echo esc_html( $stamp[1] ); ?></span></h1>
					<p><?php esc_html_e( 'Live support for your store. Customers write from the chat widget and your team answers from the agent app, on desktop or phone.', 'hilo-chat' ); ?></p>
				</div>
				<div class="hilo-header__actions">
					<a class="hilo-button hilo-button--light" href="<?php echo esc_url( $app_url ); ?>" target="_blank" rel="noopener">
						<span class="dashicons dashicons-format-chat" aria-hidden="true"></span>
						<?php esc_html_e( 'Open agent app', 'hilo-chat' ); ?>
					</a>
				</div>
			</header>
			<hr class="wp-header-end" hidden>

			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="hilo-feedback" role="status">
					<span class="dashicons dashicons-yes-alt" aria-hidden="true"></span>
					<div class="hilo-feedback__copy"><strong><?php esc_html_e( 'Settings saved.', 'hilo-chat' ); ?></strong></div>
					<button type="button" class="hilo-feedback__dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'hilo-chat' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
				</div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<?php $n = absint( $_GET['deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="hilo-feedback" role="status">
					<span class="dashicons dashicons-trash" aria-hidden="true"></span>
					<div class="hilo-feedback__copy"><strong>
						<?php
						/* translators: %d: number of conversations. */
						echo esc_html( sprintf( _n( '%d resolved conversation deleted.', '%d resolved conversations deleted.', $n, 'hilo-chat' ), $n ) );
						?>
					</strong></div>
					<button type="button" class="hilo-feedback__dismiss" aria-label="<?php esc_attr_e( 'Dismiss', 'hilo-chat' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
				</div>
			<?php endif; ?>
			<?php if ( $s['enabled'] && $s['test_mode'] ) : ?>
				<div class="hilo-feedback hilo-feedback--warning">
					<span class="dashicons dashicons-visibility" aria-hidden="true"></span>
					<div class="hilo-feedback__copy"><?php echo wp_kses( __( '<strong>Test mode is on:</strong> only users who can answer chats see the widget. Turn it off to show it to every visitor.', 'hilo-chat' ), array( 'strong' => array() ) ); ?></div>
				</div>
			<?php endif; ?>

			<div class="hilo-layout">
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hilo-form" id="hilo-settings-form">
					<input type="hidden" name="action" value="hilo_save_settings">
					<?php wp_nonce_field( 'hilo_save_settings' ); ?>

					<section class="hilo-panel">
						<h2><span class="dashicons dashicons-store" aria-hidden="true"></span><?php esc_html_e( 'Chat on the store', 'hilo-chat' ); ?></h2>
						<p class="hilo-panel__intro"><?php esc_html_e( 'Choose who sees the chat button and where it sits.', 'hilo-chat' ); ?></p>
						<div class="hilo-toggles">
							<?php
							self::toggle( 'enabled', (bool) $s['enabled'], __( 'Show the chat on the store', 'hilo-chat' ), __( 'Turn it off to hide the chat button everywhere.', 'hilo-chat' ) );
							self::toggle( 'test_mode', (bool) $s['test_mode'], __( 'Test mode', 'hilo-chat' ), __( 'Only agents see the widget, so you can try it before customers do.', 'hilo-chat' ) );
							self::toggle( 'hide_on_checkout', (bool) $s['hide_on_checkout'], __( 'Hide on the checkout page', 'hilo-chat' ), __( 'Keeps the payment step free of distractions.', 'hilo-chat' ) );
							?>
						</div>
						<div class="hilo-panel__section">
							<p class="hilo-field__label" id="hilo-position-label"><?php esc_html_e( 'Position', 'hilo-chat' ); ?></p>
							<fieldset class="hilo-choices" aria-labelledby="hilo-position-label" style="margin-top:8px">
								<label class="hilo-choice"><input type="radio" name="position" value="right" <?php checked( 'right', $s['position'] ); ?>><span class="hilo-choice__art hilo-choice__art--right" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Right', 'hilo-chat' ); ?></strong><?php esc_html_e( 'Bottom right corner', 'hilo-chat' ); ?></span></label>
								<label class="hilo-choice"><input type="radio" name="position" value="left" <?php checked( 'left', $s['position'] ); ?>><span class="hilo-choice__art hilo-choice__art--left" aria-hidden="true"></span><span><strong><?php esc_html_e( 'Left', 'hilo-chat' ); ?></strong><?php esc_html_e( 'Bottom left corner', 'hilo-chat' ); ?></span></label>
							</fieldset>
						</div>
					</section>

					<section class="hilo-panel">
						<h2><span class="dashicons dashicons-editor-quote" aria-hidden="true"></span><?php esc_html_e( 'Texts', 'hilo-chat' ); ?></h2>
						<p class="hilo-panel__intro"><?php esc_html_e( 'Leave a field empty to use the default text, translated automatically into the store language.', 'hilo-chat' ); ?></p>
						<div class="hilo-fields">
							<div class="hilo-field"><label for="hilo-brand"><?php esc_html_e( 'Brand name', 'hilo-chat' ); ?></label><input id="hilo-brand" type="text" name="brand_name" value="<?php echo esc_attr( $s['brand_name'] ); ?>"></div>
							<div class="hilo-field"><label for="hilo-sub"><?php esc_html_e( 'Team name', 'hilo-chat' ); ?></label><input id="hilo-sub" type="text" name="subtitle" value="<?php echo esc_attr( $s['subtitle'] ); ?>" placeholder="<?php echo esc_attr( $team_placeholder ); ?>"></div>
							<div class="hilo-field hilo-field--full"><label for="hilo-title"><?php esc_html_e( 'Widget title', 'hilo-chat' ); ?></label><input id="hilo-title" type="text" name="title" value="<?php echo esc_attr( $s['title'] ); ?>" placeholder="<?php echo esc_attr( $texts['title'] ); ?>"></div>
							<div class="hilo-field hilo-field--full"><label for="hilo-greet"><?php esc_html_e( 'Greeting', 'hilo-chat' ); ?> <span><?php esc_html_e( 'first message customers see', 'hilo-chat' ); ?></span></label><textarea id="hilo-greet" rows="2" name="greeting" placeholder="<?php echo esc_attr( $texts['greeting'] ); ?>"><?php echo esc_textarea( $s['greeting'] ); ?></textarea></div>
							<div class="hilo-field"><label for="hilo-on"><?php esc_html_e( 'Online text', 'hilo-chat' ); ?></label><input id="hilo-on" type="text" name="online_text" value="<?php echo esc_attr( $s['online_text'] ); ?>" placeholder="<?php echo esc_attr( $texts['online_text'] ); ?>"></div>
							<div class="hilo-field"><label for="hilo-off"><?php esc_html_e( 'Offline text', 'hilo-chat' ); ?></label><input id="hilo-off" type="text" name="offline_text" value="<?php echo esc_attr( $s['offline_text'] ); ?>" placeholder="<?php echo esc_attr( $texts['offline_text'] ); ?>"></div>
						</div>
						<div class="hilo-panel__section">
							<?php self::toggle( 'teaser_enabled', ! empty( $s['teaser_enabled'] ), __( 'Greeting bubble', 'hilo-chat' ), __( 'Shown once per visit to people who have not opened the chat yet. It hides itself after 15 seconds; if the visitor closes it, it stays hidden for 3 days.', 'hilo-chat' ) ); ?>
							<div class="hilo-fields" style="margin-top:16px">
								<div class="hilo-field"><label for="hilo-teaser"><?php esc_html_e( 'Bubble text', 'hilo-chat' ); ?></label><input id="hilo-teaser" type="text" name="teaser_text" value="<?php echo esc_attr( $s['teaser_text'] ); ?>" placeholder="<?php echo esc_attr( $texts['teaser_text'] ); ?>" maxlength="140"></div>
								<div class="hilo-field"><label for="hilo-delay"><?php esc_html_e( 'Appears after', 'hilo-chat' ); ?></label><span class="hilo-inline-number"><input id="hilo-delay" type="number" min="2" max="120" name="teaser_delay" value="<?php echo (int) $s['teaser_delay']; ?>"> <?php esc_html_e( 'seconds', 'hilo-chat' ); ?></span></div>
							</div>
						</div>
					</section>

					<section class="hilo-panel">
						<h2><span class="dashicons dashicons-clock" aria-hidden="true"></span><?php esc_html_e( 'Business hours', 'hilo-chat' ); ?></h2>
						<p class="hilo-panel__intro">
							<?php
							/* translators: %s: timezone name. */
							echo esc_html( sprintf( __( 'Site timezone: %s. Outside business hours, or when no agent is available, the widget invites visitors to leave a message and the reply arrives by email.', 'hilo-chat' ), wp_timezone_string() ) );
							?>
						</p>
						<?php self::toggle( 'always_open', ! empty( $s['always_open'] ), __( 'Open 24 hours, every day', 'hilo-chat' ), __( 'Ignores the weekly schedule below.', 'hilo-chat' ), array( 'id' => 'hilo-always' ) ); ?>
						<div class="hilo-week" id="hilo-week">
							<?php foreach ( $days as $d ) : $h = $s['hours'][ $d ] ?? array( 'enabled' => false, 'start' => '09:00', 'end' => '18:00' ); ?>
								<div class="hilo-day">
									<label class="hilo-toggle">
										<input type="checkbox" class="hilo-day__open" name="hours[<?php echo (int) $d; ?>][enabled]" value="1" <?php checked( ! empty( $h['enabled'] ) ); ?>>
										<span class="hilo-switch__track" aria-hidden="true"><span></span></span>
										<span class="hilo-toggle__copy"><strong><?php echo esc_html( $wp_locale->get_weekday( $d ) ); ?></strong></span>
									</label>
									<label class="hilo-check hilo-day__allday"><input type="checkbox" name="hours[<?php echo (int) $d; ?>][allday]" value="1" <?php checked( ! empty( $h['allday'] ) ); ?>> <?php esc_html_e( '24 h', 'hilo-chat' ); ?></label>
									<span class="hilo-day__range">
										<input class="hilo-time-input" type="time" name="hours[<?php echo (int) $d; ?>][start]" value="<?php echo esc_attr( $h['start'] ); ?>" aria-label="<?php esc_attr_e( 'Opens at', 'hilo-chat' ); ?>">
										<?php echo esc_html_x( 'to', 'time range', 'hilo-chat' ); ?>
										<input class="hilo-time-input" type="time" name="hours[<?php echo (int) $d; ?>][end]" value="<?php echo esc_attr( $h['end'] ); ?>" aria-label="<?php esc_attr_e( 'Closes at', 'hilo-chat' ); ?>">
									</span>
									<span class="hilo-day__closed"><?php esc_html_e( 'Closed', 'hilo-chat' ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
					</section>

					<section class="hilo-panel">
						<h2><span class="dashicons dashicons-art" aria-hidden="true"></span><?php esc_html_e( 'Appearance', 'hilo-chat' ); ?></h2>
						<p class="hilo-panel__intro"><?php esc_html_e( 'Match the widget and the agent app to your brand. The preview updates as you edit.', 'hilo-chat' ); ?></p>
						<div class="hilo-appearance">
							<div>
								<div class="hilo-swatches">
									<?php
									$swatches = array(
										'color_primary' => array( __( 'Primary', 'hilo-chat' ), __( 'Header, button and team bubbles', 'hilo-chat' ) ),
										'color_accent'  => array( __( 'Accent', 'hilo-chat' ), __( 'Links and highlights', 'hilo-chat' ) ),
										'color_soft'    => array( __( 'Soft', 'hilo-chat' ), __( 'Backgrounds and customer bubbles', 'hilo-chat' ) ),
									);
									foreach ( $swatches as $key => $label ) :
										?>
										<label class="hilo-swatch">
											<input type="color" name="<?php echo esc_attr( $key ); ?>" value="<?php echo esc_attr( $s[ $key ] ); ?>">
											<span><strong><?php echo esc_html( $label[0] ); ?></strong><small><?php echo esc_html( $label[1] ); ?></small></span>
											<code data-hilo-hex="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $s[ $key ] ); ?></code>
										</label>
									<?php endforeach; ?>
								</div>
								<div class="hilo-fields" style="margin-top:18px">
									<div class="hilo-field"><label for="hilo-fh"><?php esc_html_e( 'Heading font', 'hilo-chat' ); ?></label><input id="hilo-fh" type="text" name="font_heading" value="<?php echo esc_attr( $s['font_heading'] ); ?>"></div>
									<div class="hilo-field"><label for="hilo-fb"><?php esc_html_e( 'Body font', 'hilo-chat' ); ?></label><input id="hilo-fb" type="text" name="font_body" value="<?php echo esc_attr( $s['font_body'] ); ?>"></div>
									<p class="hilo-hint hilo-field--full"><?php echo wp_kses( __( 'Name of a font already loaded on the site, e.g. <code>"Tenor Sans", serif</code>. Use <code>inherit</code> to follow the theme.', 'hilo-chat' ), array( 'code' => array() ) ); ?></p>
								</div>
							</div>
							<div class="hilo-preview" id="hilo-preview" aria-hidden="true">
								<span class="hilo-preview__label"><?php esc_html_e( 'Preview', 'hilo-chat' ); ?></span>
								<div class="hilo-preview__panel">
									<div class="hilo-preview__head"><span class="hilo-preview__avatar">C</span><span class="hilo-preview__title"></span></div>
									<div class="hilo-preview__body">
										<div class="hilo-preview__bubble hilo-preview__bubble--in hilo-preview__greeting"></div>
										<div class="hilo-preview__bubble hilo-preview__bubble--out"><?php esc_html_e( 'Do you ship to my city?', 'hilo-chat' ); ?></div>
										<span class="hilo-preview__link"><?php esc_html_e( 'See shipping options', 'hilo-chat' ); ?></span>
										<span class="hilo-preview__send"></span>
									</div>
								</div>
								<span class="hilo-preview__launcher"></span>
							</div>
						</div>
						<div class="hilo-panel__section hilo-fields">
							<div class="hilo-field">
								<span class="hilo-field__label"><?php esc_html_e( 'Agent app icon', 'hilo-chat' ); ?></span>
								<div class="hilo-icon-picker">
									<img src="<?php echo esc_url( $icon ); ?>" alt="" data-hilo-icon>
									<input type="hidden" name="app_icon_id" id="hilo-icon-id" value="<?php echo (int) $s['app_icon_id']; ?>">
									<span class="hilo-icon-picker__actions">
										<button type="button" class="hilo-button hilo-button--ghost hilo-button--sm" id="hilo-icon-pick"><?php esc_html_e( 'Choose image', 'hilo-chat' ); ?></button>
										<button type="button" class="hilo-link-button" id="hilo-icon-reset"><?php esc_html_e( 'Use the default icon', 'hilo-chat' ); ?></button>
									</span>
								</div>
								<p><?php esc_html_e( 'Square 512×512 px PNG. Leave some space around the logo so Android does not crop it.', 'hilo-chat' ); ?></p>
							</div>
							<div class="hilo-field">
								<label for="hilo-logo"><?php esc_html_e( 'Email logo (URL)', 'hilo-chat' ); ?></label>
								<input id="hilo-logo" type="url" name="logo_url" value="<?php echo esc_attr( $s['logo_url'] ); ?>" placeholder="https://…/logo-white.png">
								<p><?php esc_html_e( 'Shown on top of the primary color, so a light version works best. Many email clients do not display SVG: use PNG.', 'hilo-chat' ); ?></p>
							</div>
						</div>
					</section>

					<section class="hilo-panel">
						<h2><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span><?php esc_html_e( 'Advanced', 'hilo-chat' ); ?></h2>
						<p class="hilo-panel__intro"><?php esc_html_e( 'Technical options. The defaults work for most stores.', 'hilo-chat' ); ?></p>
						<div class="hilo-fields">
							<div class="hilo-field hilo-field--full">
								<label for="hilo-slug"><?php esc_html_e( 'App path', 'hilo-chat' ); ?></label>
								<span class="hilo-prefixed"><span class="hilo-prefixed__prefix"><?php echo esc_html( home_url( '/' ) ); ?></span><input id="hilo-slug" type="text" name="app_slug" value="<?php echo esc_attr( $s['app_slug'] ); ?>"></span>
								<p><?php esc_html_e( 'If you change it, agents will need to reinstall the app.', 'hilo-chat' ); ?></p>
							</div>
							<div class="hilo-field">
								<label for="hilo-push"><?php esc_html_e( 'Push contact email (VAPID)', 'hilo-chat' ); ?></label>
								<input id="hilo-push" type="email" name="push_subject" value="<?php echo esc_attr( $s['push_subject'] ); ?>">
								<p><?php esc_html_e( 'Push services use it to contact you if there is a problem.', 'hilo-chat' ); ?></p>
							</div>
							<div class="hilo-field">
								<label for="hilo-ret"><?php esc_html_e( 'Delete resolved chats after', 'hilo-chat' ); ?></label>
								<span class="hilo-inline-number"><input id="hilo-ret" type="number" min="0" name="retention_days" value="<?php echo (int) $s['retention_days']; ?>"> <?php esc_html_e( 'days', 'hilo-chat' ); ?></span>
								<p><?php esc_html_e( '0 = keep them forever.', 'hilo-chat' ); ?></p>
							</div>
						</div>
					</section>

					<div class="hilo-savebar">
						<button type="submit" class="hilo-button"><span class="dashicons dashicons-saved" aria-hidden="true"></span><?php esc_html_e( 'Save settings', 'hilo-chat' ); ?></button>
						<span class="hilo-savebar__note"><?php esc_html_e( 'Changes apply to the store as soon as you save.', 'hilo-chat' ); ?></span>
					</div>
				</form>

				<aside class="hilo-aside">
					<section class="hilo-panel hilo-panel--compact hilo-app-card">
						<div class="hilo-app-card__top">
							<img src="<?php echo esc_url( $icon ); ?>" alt="" data-hilo-icon>
							<div>
								<h2><?php esc_html_e( 'Agent app', 'hilo-chat' ); ?></h2>
								<span class="hilo-app-card__url"><?php echo esc_html( preg_replace( '#^https?://#', '', $app_url ) ); ?></span>
							</div>
						</div>
						<p class="hilo-hint" style="margin:0"><?php esc_html_e( 'Answer chats from the browser or install it as an app on your phone or computer.', 'hilo-chat' ); ?></p>
						<div class="hilo-app-card__buttons">
							<a class="hilo-button hilo-button--sm" href="<?php echo esc_url( $app_url ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open app', 'hilo-chat' ); ?></a>
							<button type="button" class="hilo-button hilo-button--ghost hilo-button--sm" id="hilo-copy-url" data-url="<?php echo esc_attr( $app_url ); ?>"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span class="hilo-copy-label"><?php esc_html_e( 'Copy link', 'hilo-chat' ); ?></span></button>
						</div>
					</section>

					<section class="hilo-panel hilo-panel--compact">
						<h2><?php esc_html_e( 'Agents', 'hilo-chat' ); ?></h2>
						<div class="hilo-agents">
							<?php foreach ( $agents as $a ) : $live = $a['online'] && $a['available']; ?>
								<div class="hilo-agent">
									<img src="<?php echo esc_url( $a['avatar'] ); ?>" alt="">
									<span class="hilo-agent__name"><?php echo esc_html( $a['name'] ); ?></span>
									<span class="hilo-agent__state <?php echo $live ? 'is-live' : ''; ?>"><span class="hilo-dot"></span><?php echo esc_html( $live ? __( 'Available', 'hilo-chat' ) : ( $a['online'] ? __( 'Away', 'hilo-chat' ) : __( 'Offline', 'hilo-chat' ) ) ); ?></span>
								</div>
							<?php endforeach; ?>
						</div>
						<p class="hilo-hint">
							<?php
							printf(
								/* translators: %s: link to the Users screen. */
								wp_kses( __( 'To add agents, give them the <strong>Chat agent</strong> role in %s. Administrators and shop managers can answer chats too.', 'hilo-chat' ), array( 'strong' => array() ) ),
								'<a href="' . esc_url( admin_url( 'users.php' ) ) . '">' . esc_html__( 'Users', 'hilo-chat' ) . '</a>'
							);
							?>
						</p>
					</section>

					<section class="hilo-danger">
						<h2><?php esc_html_e( 'Resolved conversations', 'hilo-chat' ); ?></h2>
						<p>
							<?php
							/* translators: %d: number of conversations. */
							echo wp_kses( sprintf( _n( 'There is <strong>%d</strong> resolved conversation stored.', 'There are <strong>%d</strong> resolved conversations stored.', $resolved, 'hilo-chat' ), $resolved ), array( 'strong' => array() ) );
							?>
						</p>
						<?php if ( $resolved ) : ?>
							<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" data-hilo-confirm="<?php esc_attr_e( 'Delete all resolved conversations and their attachments? This cannot be undone.', 'hilo-chat' ); ?>">
								<input type="hidden" name="action" value="hilo_delete_resolved">
								<?php wp_nonce_field( 'hilo_delete_resolved' ); ?>
								<button type="submit" class="hilo-button hilo-button--danger hilo-button--sm"><span class="dashicons dashicons-trash" aria-hidden="true"></span><?php esc_html_e( 'Delete all resolved conversations now', 'hilo-chat' ); ?></button>
							</form>
						<?php endif; ?>
					</section>
				</aside>
			</div>

			<p class="hilo-signature">
				<?php
				printf(
					/* translators: 1: plugin name and version, 2: author link. */
					esc_html__( '%1$s · Made by %2$s', 'hilo-chat' ),
					esc_html( 'Hilo Chat ' . HILO_VERSION ),
					'<a href="https://mauriciocorread.com/" target="_blank" rel="noopener">Mauricio Correa</a>' // phpcs:ignore WordPress.Security.EscapeOutput
				);
				?>
			</p>
		</div>
		<?php
	}
}
