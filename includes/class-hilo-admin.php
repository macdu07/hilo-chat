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
	 * Renders the page.
	 */
	public static function render() {
		global $wp_locale;
		wp_enqueue_media();
		$s        = HILO_Settings::all();
		$texts    = HILO_Settings::text_defaults();
		$days     = array( 1, 2, 3, 4, 5, 6, 0 );
		$agents   = HILO_Presence::agents_for_app();
		$icon     = $s['app_icon_id'] ? wp_get_attachment_image_url( (int) $s['app_icon_id'], 'thumbnail' ) : HILO_PLUGIN_URL . 'assets/agent/icons/icon-192.png';
		$resolved = HILO_DB::count_resolved();
		/* translators: %s: store name. */
		$team_placeholder = sprintf( __( '%s team', 'hilo-chat' ), HILO_Settings::brand() );
		$blank_hint       = __( 'Leave it empty to use the default text, translated automatically.', 'hilo-chat' );
		?>
		<div class="wrap hilo-admin">
			<style>
				.hilo-admin .hilo-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:24px;align-items:start;max-width:1200px}
				.hilo-admin .hilo-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px 24px;margin-bottom:20px}
				.hilo-admin .hilo-card h2{margin-top:0;font-size:15px}
				.hilo-admin .form-table th{width:210px}
				.hilo-admin .hilo-hours td{padding:6px 10px 6px 0}
				.hilo-admin .hilo-hours.is-disabled{opacity:.45;pointer-events:none}
				.hilo-admin .hilo-hours tr.is-allday .hilo-time{opacity:.35;pointer-events:none}
				.hilo-admin .hilo-agent{display:flex;align-items:center;gap:10px;padding:6px 0}
				.hilo-admin .hilo-agent img{width:28px;height:28px;border-radius:50%}
				.hilo-admin .hilo-dot{width:8px;height:8px;border-radius:50%;background:#c3c4c7;display:inline-block}
				.hilo-admin .hilo-dot.on{background:#00a32a}
				.hilo-admin .hilo-icon-preview{width:56px;height:56px;border-radius:12px;vertical-align:middle;margin-right:10px;border:1px solid #dcdcde}
				.hilo-admin .button-link-delete{color:#b32d2e}
				@media (max-width:960px){.hilo-admin .hilo-grid{grid-template-columns:1fr}}
			</style>
			<h1>Hilo Chat</h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'hilo-chat' ); ?></p></div>
			<?php endif; ?>
			<?php if ( isset( $_GET['deleted'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<?php $n = absint( $_GET['deleted'] ); // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>
					<?php
					/* translators: %d: number of conversations. */
					echo esc_html( sprintf( _n( '%d resolved conversation deleted.', '%d resolved conversations deleted.', $n, 'hilo-chat' ), $n ) );
					?>
				</p></div>
			<?php endif; ?>
			<?php if ( $s['test_mode'] ) : ?>
				<div class="notice notice-warning"><p><?php echo wp_kses( __( '<strong>Test mode is on:</strong> only users who can answer chats see the widget. Turn it off to show it to every visitor.', 'hilo-chat' ), array( 'strong' => array() ) ); ?></p></div>
			<?php endif; ?>

			<div class="hilo-grid">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hilo_save_settings">
				<?php wp_nonce_field( 'hilo_save_settings' ); ?>

				<div class="hilo-card">
					<h2><?php esc_html_e( 'General', 'hilo-chat' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Status', 'hilo-chat' ); ?></th><td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> <?php esc_html_e( 'Show the chat on the store', 'hilo-chat' ); ?></label><br>
							<label><input type="checkbox" name="test_mode" value="1" <?php checked( $s['test_mode'] ); ?>> <?php esc_html_e( 'Test mode (only agents see the widget)', 'hilo-chat' ); ?></label><br>
							<label><input type="checkbox" name="hide_on_checkout" value="1" <?php checked( $s['hide_on_checkout'] ); ?>> <?php esc_html_e( 'Hide on the checkout page', 'hilo-chat' ); ?></label>
						</td></tr>
						<tr><th><label for="hilo-brand"><?php esc_html_e( 'Brand name', 'hilo-chat' ); ?></label></th><td><input id="hilo-brand" class="regular-text" name="brand_name" value="<?php echo esc_attr( $s['brand_name'] ); ?>"></td></tr>
						<tr><th><label for="hilo-title"><?php esc_html_e( 'Widget title', 'hilo-chat' ); ?></label></th><td><input id="hilo-title" class="regular-text" name="title" value="<?php echo esc_attr( $s['title'] ); ?>" placeholder="<?php echo esc_attr( $texts['title'] ); ?>"></td></tr>
						<tr><th><label for="hilo-sub"><?php esc_html_e( 'Team name', 'hilo-chat' ); ?></label></th><td><input id="hilo-sub" class="regular-text" name="subtitle" value="<?php echo esc_attr( $s['subtitle'] ); ?>" placeholder="<?php echo esc_attr( $team_placeholder ); ?>"></td></tr>
						<tr><th><label for="hilo-greet"><?php esc_html_e( 'Greeting', 'hilo-chat' ); ?></label></th><td><textarea id="hilo-greet" class="large-text" rows="2" name="greeting" placeholder="<?php echo esc_attr( $texts['greeting'] ); ?>"><?php echo esc_textarea( $s['greeting'] ); ?></textarea><p class="description"><?php echo esc_html( $blank_hint ); ?></p></td></tr>
						<tr><th><?php esc_html_e( 'Greeting bubble', 'hilo-chat' ); ?></th><td>
							<label><input type="checkbox" name="teaser_enabled" value="1" <?php checked( ! empty( $s['teaser_enabled'] ) ); ?>> <?php esc_html_e( 'Show a message next to the chat button', 'hilo-chat' ); ?></label>
							<p><input class="large-text" name="teaser_text" value="<?php echo esc_attr( $s['teaser_text'] ); ?>" placeholder="<?php echo esc_attr( $texts['teaser_text'] ); ?>" maxlength="140" aria-label="<?php esc_attr_e( 'Bubble text', 'hilo-chat' ); ?>"></p>
							<p><label>
								<?php
								printf(
									/* translators: %s: number input. */
									esc_html__( 'Appears after %s seconds', 'hilo-chat' ),
									'<input type="number" min="2" max="120" name="teaser_delay" value="' . (int) $s['teaser_delay'] . '" style="width:70px">' // phpcs:ignore WordPress.Security.EscapeOutput
								);
								?>
							</label></p>
							<p class="description"><?php esc_html_e( 'Shown once per visit to people who have not opened the chat yet. It hides itself after 15 seconds; if the visitor closes it, it stays hidden for 3 days.', 'hilo-chat' ); ?></p>
						</td></tr>
						<tr><th><label for="hilo-on"><?php esc_html_e( 'Online text', 'hilo-chat' ); ?></label></th><td><input id="hilo-on" class="large-text" name="online_text" value="<?php echo esc_attr( $s['online_text'] ); ?>" placeholder="<?php echo esc_attr( $texts['online_text'] ); ?>"></td></tr>
						<tr><th><label for="hilo-off"><?php esc_html_e( 'Offline text', 'hilo-chat' ); ?></label></th><td><textarea id="hilo-off" class="large-text" rows="2" name="offline_text" placeholder="<?php echo esc_attr( $texts['offline_text'] ); ?>"><?php echo esc_textarea( $s['offline_text'] ); ?></textarea></td></tr>
						<tr><th><?php esc_html_e( 'Position', 'hilo-chat' ); ?></th><td>
							<label><input type="radio" name="position" value="right" <?php checked( 'right', $s['position'] ); ?>> <?php esc_html_e( 'Right', 'hilo-chat' ); ?></label> &nbsp;
							<label><input type="radio" name="position" value="left" <?php checked( 'left', $s['position'] ); ?>> <?php esc_html_e( 'Left', 'hilo-chat' ); ?></label>
						</td></tr>
					</table>
				</div>

				<div class="hilo-card">
					<h2><?php esc_html_e( 'Business hours', 'hilo-chat' ); ?></h2>
					<p class="description">
						<?php
						/* translators: %s: timezone name. */
						echo esc_html( sprintf( __( 'Site timezone: %s. Outside business hours, or when no agent is available, the widget invites visitors to leave a message and the reply arrives by email.', 'hilo-chat' ), wp_timezone_string() ) );
						?>
					</p>
					<p><label><input type="checkbox" name="always_open" value="1" id="hilo-always" <?php checked( ! empty( $s['always_open'] ) ); ?>> <strong><?php esc_html_e( 'Open 24 hours, every day', 'hilo-chat' ); ?></strong> <?php esc_html_e( '(ignores the schedule below)', 'hilo-chat' ); ?></label></p>
					<table class="hilo-hours" id="hilo-hours">
						<?php foreach ( $days as $d ) : $h = $s['hours'][ $d ] ?? array( 'enabled' => false, 'start' => '09:00', 'end' => '18:00' ); ?>
							<tr>
								<td style="width:120px"><label><input type="checkbox" name="hours[<?php echo (int) $d; ?>][enabled]" value="1" <?php checked( ! empty( $h['enabled'] ) ); ?>> <?php echo esc_html( $wp_locale->get_weekday( $d ) ); ?></label></td>
								<td><label><input type="checkbox" class="hilo-allday" name="hours[<?php echo (int) $d; ?>][allday]" value="1" <?php checked( ! empty( $h['allday'] ) ); ?>> <?php esc_html_e( '24 h', 'hilo-chat' ); ?></label></td>
								<td class="hilo-time"><input type="time" name="hours[<?php echo (int) $d; ?>][start]" value="<?php echo esc_attr( $h['start'] ); ?>"></td>
								<td class="hilo-time"><?php echo esc_html_x( 'to', 'time range', 'hilo-chat' ); ?></td>
								<td class="hilo-time"><input type="time" name="hours[<?php echo (int) $d; ?>][end]" value="<?php echo esc_attr( $h['end'] ); ?>"></td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>

				<div class="hilo-card">
					<h2><?php esc_html_e( 'Appearance', 'hilo-chat' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th><?php esc_html_e( 'Colors', 'hilo-chat' ); ?></th><td>
							<label><?php esc_html_e( 'Primary', 'hilo-chat' ); ?> <input type="color" name="color_primary" value="<?php echo esc_attr( $s['color_primary'] ); ?>"></label> &nbsp;
							<label><?php esc_html_e( 'Accent', 'hilo-chat' ); ?> <input type="color" name="color_accent" value="<?php echo esc_attr( $s['color_accent'] ); ?>"></label> &nbsp;
							<label><?php esc_html_e( 'Soft', 'hilo-chat' ); ?> <input type="color" name="color_soft" value="<?php echo esc_attr( $s['color_soft'] ); ?>"></label>
							<p class="description"><?php esc_html_e( 'Primary: header and team bubbles. Accent: buttons and links. Soft: backgrounds.', 'hilo-chat' ); ?></p>
						</td></tr>
						<tr><th><label for="hilo-fh"><?php esc_html_e( 'Heading font', 'hilo-chat' ); ?></label></th><td><input id="hilo-fh" class="regular-text" name="font_heading" value="<?php echo esc_attr( $s['font_heading'] ); ?>"><p class="description"><?php echo wp_kses( __( 'Name of a font already loaded on the site, e.g. <code>"Tenor Sans", serif</code>. Use <code>inherit</code> to follow the theme.', 'hilo-chat' ), array( 'code' => array() ) ); ?></p></td></tr>
						<tr><th><label for="hilo-fb"><?php esc_html_e( 'Body font', 'hilo-chat' ); ?></label></th><td><input id="hilo-fb" class="regular-text" name="font_body" value="<?php echo esc_attr( $s['font_body'] ); ?>"></td></tr>
						<tr><th><label for="hilo-logo"><?php esc_html_e( 'Email logo (URL)', 'hilo-chat' ); ?></label></th><td><input id="hilo-logo" class="large-text" name="logo_url" value="<?php echo esc_attr( $s['logo_url'] ); ?>" placeholder="https://…/logo-white.png"><p class="description"><?php esc_html_e( 'Shown on top of the primary color, so a light version works best. Many email clients do not display SVG: use PNG.', 'hilo-chat' ); ?></p></td></tr>
						<tr><th><?php esc_html_e( 'Agent app icon', 'hilo-chat' ); ?></th><td>
							<img class="hilo-icon-preview" id="hilo-icon-preview" src="<?php echo esc_url( $icon ); ?>" alt="">
							<input type="hidden" name="app_icon_id" id="hilo-icon-id" value="<?php echo (int) $s['app_icon_id']; ?>">
							<button type="button" class="button" id="hilo-icon-pick"><?php esc_html_e( 'Choose image', 'hilo-chat' ); ?></button>
							<button type="button" class="button-link" id="hilo-icon-reset"><?php esc_html_e( 'Use the default icon', 'hilo-chat' ); ?></button>
							<p class="description"><?php esc_html_e( 'Square 512×512 px PNG. Leave some space around the logo so Android does not crop it.', 'hilo-chat' ); ?></p>
						</td></tr>
					</table>
				</div>

				<div class="hilo-card">
					<h2><?php esc_html_e( 'Advanced', 'hilo-chat' ); ?></h2>
					<table class="form-table" role="presentation">
						<tr><th><label for="hilo-slug"><?php esc_html_e( 'App path', 'hilo-chat' ); ?></label></th><td><code><?php echo esc_html( home_url( '/' ) ); ?></code><input id="hilo-slug" name="app_slug" value="<?php echo esc_attr( $s['app_slug'] ); ?>" style="width:180px"><code>/</code><p class="description"><?php esc_html_e( 'If you change it, agents will need to reinstall the app.', 'hilo-chat' ); ?></p></td></tr>
						<tr><th><label for="hilo-push"><?php esc_html_e( 'Push contact email (VAPID)', 'hilo-chat' ); ?></label></th><td><input id="hilo-push" type="email" class="regular-text" name="push_subject" value="<?php echo esc_attr( $s['push_subject'] ); ?>"></td></tr>
						<tr><th><label for="hilo-ret"><?php esc_html_e( 'Delete resolved chats after', 'hilo-chat' ); ?></label></th><td><input id="hilo-ret" type="number" min="0" name="retention_days" value="<?php echo (int) $s['retention_days']; ?>" style="width:90px"> <?php esc_html_e( 'days', 'hilo-chat' ); ?> <span class="description"><?php esc_html_e( '(0 = never)', 'hilo-chat' ); ?></span></td></tr>
					</table>
				</div>

				<?php submit_button( __( 'Save settings', 'hilo-chat' ) ); ?>
			</form>

			<div>
				<div class="hilo-card">
					<h2><?php esc_html_e( 'Agent app', 'hilo-chat' ); ?></h2>
					<p><?php esc_html_e( 'Answer chats from the browser or install it as an app on your phone or computer.', 'hilo-chat' ); ?></p>
					<p><a class="button button-primary" href="<?php echo esc_url( HILO_Settings::app_url() ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'Open app', 'hilo-chat' ); ?></a></p>
					<p class="description"><code><?php echo esc_html( HILO_Settings::app_url() ); ?></code></p>
				</div>
				<div class="hilo-card">
					<h2><?php esc_html_e( 'Agents', 'hilo-chat' ); ?></h2>
					<?php foreach ( $agents as $a ) : ?>
						<div class="hilo-agent"><img src="<?php echo esc_url( $a['avatar'] ); ?>" alt=""><span class="hilo-dot <?php echo $a['online'] && $a['available'] ? 'on' : ''; ?>"></span> <?php echo esc_html( $a['name'] ); ?></div>
					<?php endforeach; ?>
					<p class="description">
						<?php
						printf(
							/* translators: %s: link to the Users screen. */
							wp_kses( __( 'To add agents, give them the <strong>Chat agent</strong> role in %s. Administrators and shop managers can answer chats too.', 'hilo-chat' ), array( 'strong' => array() ) ),
							'<a href="' . esc_url( admin_url( 'users.php' ) ) . '">' . esc_html__( 'Users', 'hilo-chat' ) . '</a>'
						);
						?>
					</p>
				</div>
				<div class="hilo-card">
					<h2><?php esc_html_e( 'Resolved conversations', 'hilo-chat' ); ?></h2>
					<p>
						<?php
						/* translators: %d: number of conversations. */
						echo esc_html( sprintf( _n( 'There is %d resolved conversation stored.', 'There are %d resolved conversations stored.', $resolved, 'hilo-chat' ), $resolved ) );
						?>
					</p>
					<?php if ( $resolved ) : ?>
						<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm(<?php echo esc_attr( wp_json_encode( __( 'Delete all resolved conversations and their attachments? This cannot be undone.', 'hilo-chat' ) ) ); ?>);">
							<input type="hidden" name="action" value="hilo_delete_resolved">
							<?php wp_nonce_field( 'hilo_delete_resolved' ); ?>
							<button type="submit" class="button button-link-delete"><?php esc_html_e( 'Delete all resolved conversations now', 'hilo-chat' ); ?></button>
						</form>
					<?php endif; ?>
				</div>
			</div>
			</div>
		</div>
		<script>
		(function(){
			var pick=document.getElementById('hilo-icon-pick'),reset=document.getElementById('hilo-icon-reset'),id=document.getElementById('hilo-icon-id'),img=document.getElementById('hilo-icon-preview'),def=<?php echo wp_json_encode( HILO_PLUGIN_URL . 'assets/agent/icons/icon-192.png' ); ?>,frame;
			pick.addEventListener('click',function(){
				if(!frame){frame=wp.media({title:<?php echo wp_json_encode( __( 'App icon', 'hilo-chat' ) ); ?>,library:{type:'image'},multiple:false});
				frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();id.value=a.id;img.src=(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url);});}
				frame.open();
			});
			reset.addEventListener('click',function(){id.value=0;img.src=def;});
			var always=document.getElementById('hilo-always'),table=document.getElementById('hilo-hours');
			function syncHours(){table.classList.toggle('is-disabled',always.checked);table.querySelectorAll('tr').forEach(function(tr){var c=tr.querySelector('.hilo-allday');tr.classList.toggle('is-allday',!!(c&&c.checked));});}
			always.addEventListener('change',syncHours);table.addEventListener('change',syncHours);syncHours();
		})();
		</script>
		<?php
	}
}
