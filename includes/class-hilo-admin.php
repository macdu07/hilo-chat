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
		add_filter( 'plugin_action_links_' . plugin_basename( HILO_PLUGIN_FILE ), array( __CLASS__, 'action_links' ) );
	}

	/**
	 * Menu entries.
	 */
	public static function menu() {
		add_submenu_page( 'woocommerce', 'Hilo Chat', 'Hilo Chat', 'manage_options', 'hilo-chat', array( __CLASS__, 'render' ) );
		// Shortcut for agents (including non-admins) to the app.
		add_menu_page( 'Chats', 'Chats', HILO_CAP, 'hilo-chat-app', '__return_null', 'dashicons-format-chat', 56 );
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
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=hilo-chat' ) ) . '">Ajustes</a>' );
		return $links;
	}

	/**
	 * Saves the form.
	 */
	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'No autorizado', 403 );
		}
		check_admin_referer( 'hilo_save_settings' );

		$in       = wp_unslash( $_POST ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$defaults = HILO_Settings::defaults();
		$s        = array(
			'enabled'          => ! empty( $in['enabled'] ),
			'test_mode'        => ! empty( $in['test_mode'] ),
			'hide_on_checkout' => ! empty( $in['hide_on_checkout'] ),
			'brand_name'       => sanitize_text_field( $in['brand_name'] ?? '' ),
			'title'            => sanitize_text_field( $in['title'] ?? $defaults['title'] ),
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
			'hours'            => array(),
		);
		for ( $d = 0; $d < 7; $d++ ) {
			$day                 = isset( $in['hours'][ $d ] ) ? (array) $in['hours'][ $d ] : array();
			$s['hours'][ $d ]    = array(
				'enabled' => ! empty( $day['enabled'] ),
				'start'   => self::time( $day['start'] ?? '09:00', '09:00' ),
				'end'     => self::time( $day['end'] ?? '18:00', '18:00' ),
			);
		}
		HILO_Settings::save( $s );

		wp_safe_redirect( add_query_arg( array( 'page' => 'hilo-chat', 'updated' => 1 ), admin_url( 'admin.php' ) ) );
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
		wp_enqueue_media();
		$s      = HILO_Settings::all();
		$days   = array( 1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado', 0 => 'Domingo' );
		$agents = HILO_Presence::agents_for_app();
		$icon   = $s['app_icon_id'] ? wp_get_attachment_image_url( (int) $s['app_icon_id'], 'thumbnail' ) : HILO_PLUGIN_URL . 'assets/agent/icons/icon-192.png';
		?>
		<div class="wrap hilo-admin">
			<style>
				.hilo-admin .hilo-grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:24px;align-items:start;max-width:1200px}
				.hilo-admin .hilo-card{background:#fff;border:1px solid #dcdcde;border-radius:10px;padding:20px 24px;margin-bottom:20px}
				.hilo-admin .hilo-card h2{margin-top:0;font-size:15px}
				.hilo-admin .form-table th{width:210px}
				.hilo-admin .hilo-hours td{padding:6px 10px 6px 0}
				.hilo-admin .hilo-agent{display:flex;align-items:center;gap:10px;padding:6px 0}
				.hilo-admin .hilo-agent img{width:28px;height:28px;border-radius:50%}
				.hilo-admin .hilo-dot{width:8px;height:8px;border-radius:50%;background:#c3c4c7;display:inline-block}
				.hilo-admin .hilo-dot.on{background:#00a32a}
				.hilo-admin .hilo-icon-preview{width:56px;height:56px;border-radius:12px;vertical-align:middle;margin-right:10px;border:1px solid #dcdcde}
				@media (max-width:960px){.hilo-admin .hilo-grid{grid-template-columns:1fr}}
			</style>
			<h1>Hilo Chat</h1>
			<?php if ( isset( $_GET['updated'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification ?>
				<div class="notice notice-success is-dismissible"><p>Ajustes guardados.</p></div>
			<?php endif; ?>
			<?php if ( $s['test_mode'] ) : ?>
				<div class="notice notice-warning"><p><strong>Modo prueba activo:</strong> el widget solo lo ven los usuarios con permiso de agente. Desactívalo para publicarlo a todos los visitantes.</p></div>
			<?php endif; ?>

			<div class="hilo-grid">
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="hilo_save_settings">
				<?php wp_nonce_field( 'hilo_save_settings' ); ?>

				<div class="hilo-card">
					<h2>General</h2>
					<table class="form-table" role="presentation">
						<tr><th>Estado</th><td>
							<label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> Mostrar el chat en la tienda</label><br>
							<label><input type="checkbox" name="test_mode" value="1" <?php checked( $s['test_mode'] ); ?>> Modo prueba (solo agentes ven el widget)</label><br>
							<label><input type="checkbox" name="hide_on_checkout" value="1" <?php checked( $s['hide_on_checkout'] ); ?>> Ocultar en la página de pago</label>
						</td></tr>
						<tr><th><label for="hilo-brand">Nombre de la marca</label></th><td><input id="hilo-brand" class="regular-text" name="brand_name" value="<?php echo esc_attr( $s['brand_name'] ); ?>"></td></tr>
						<tr><th><label for="hilo-title">Título del widget</label></th><td><input id="hilo-title" class="regular-text" name="title" value="<?php echo esc_attr( $s['title'] ); ?>"></td></tr>
						<tr><th><label for="hilo-sub">Nombre del equipo</label></th><td><input id="hilo-sub" class="regular-text" name="subtitle" value="<?php echo esc_attr( $s['subtitle'] ); ?>" placeholder="<?php echo esc_attr( 'Equipo ' . HILO_Settings::brand() ); ?>"></td></tr>
						<tr><th><label for="hilo-greet">Saludo</label></th><td><textarea id="hilo-greet" class="large-text" rows="2" name="greeting"><?php echo esc_textarea( $s['greeting'] ); ?></textarea></td></tr>
						<tr><th><label for="hilo-on">Texto en línea</label></th><td><input id="hilo-on" class="large-text" name="online_text" value="<?php echo esc_attr( $s['online_text'] ); ?>"></td></tr>
						<tr><th><label for="hilo-off">Texto fuera de línea</label></th><td><textarea id="hilo-off" class="large-text" rows="2" name="offline_text"><?php echo esc_textarea( $s['offline_text'] ); ?></textarea></td></tr>
						<tr><th>Posición</th><td>
							<label><input type="radio" name="position" value="right" <?php checked( 'right', $s['position'] ); ?>> Derecha</label> &nbsp;
							<label><input type="radio" name="position" value="left" <?php checked( 'left', $s['position'] ); ?>> Izquierda</label>
						</td></tr>
					</table>
				</div>

				<div class="hilo-card">
					<h2>Horario de atención</h2>
					<p class="description">Zona horaria del sitio: <?php echo esc_html( wp_timezone_string() ); ?>. Fuera de horario, o si no hay agentes disponibles, el widget invita a dejar un mensaje y la respuesta llega por email.</p>
					<table class="hilo-hours">
						<?php foreach ( $days as $d => $label ) : $h = $s['hours'][ $d ] ?? array( 'enabled' => false, 'start' => '09:00', 'end' => '18:00' ); ?>
							<tr>
								<td style="width:110px"><label><input type="checkbox" name="hours[<?php echo (int) $d; ?>][enabled]" value="1" <?php checked( ! empty( $h['enabled'] ) ); ?>> <?php echo esc_html( $label ); ?></label></td>
								<td><input type="time" name="hours[<?php echo (int) $d; ?>][start]" value="<?php echo esc_attr( $h['start'] ); ?>"></td>
								<td>a</td>
								<td><input type="time" name="hours[<?php echo (int) $d; ?>][end]" value="<?php echo esc_attr( $h['end'] ); ?>"></td>
							</tr>
						<?php endforeach; ?>
					</table>
				</div>

				<div class="hilo-card">
					<h2>Apariencia</h2>
					<table class="form-table" role="presentation">
						<tr><th>Colores</th><td>
							<label>Principal <input type="color" name="color_primary" value="<?php echo esc_attr( $s['color_primary'] ); ?>"></label> &nbsp;
							<label>Acento <input type="color" name="color_accent" value="<?php echo esc_attr( $s['color_accent'] ); ?>"></label> &nbsp;
							<label>Suave <input type="color" name="color_soft" value="<?php echo esc_attr( $s['color_soft'] ); ?>"></label>
							<p class="description">Principal: cabecera y burbujas del equipo. Acento: botones y enlaces. Suave: fondos.</p>
						</td></tr>
						<tr><th><label for="hilo-fh">Tipografía de títulos</label></th><td><input id="hilo-fh" class="regular-text" name="font_heading" value="<?php echo esc_attr( $s['font_heading'] ); ?>"><p class="description">Nombre de una fuente ya cargada en el sitio, p. ej. <code>"Tenor Sans", serif</code>. Usa <code>inherit</code> para heredar la del tema.</p></td></tr>
						<tr><th><label for="hilo-fb">Tipografía de texto</label></th><td><input id="hilo-fb" class="regular-text" name="font_body" value="<?php echo esc_attr( $s['font_body'] ); ?>"></td></tr>
						<tr><th><label for="hilo-logo">Logo para emails (URL)</label></th><td><input id="hilo-logo" class="large-text" name="logo_url" value="<?php echo esc_attr( $s['logo_url'] ); ?>" placeholder="https://…/logo-blanco.png"><p class="description">Se muestra sobre el color principal, así que conviene una versión clara. Muchos clientes de correo no muestran SVG: usa PNG.</p></td></tr>
						<tr><th>Icono de la app de agentes</th><td>
							<img class="hilo-icon-preview" id="hilo-icon-preview" src="<?php echo esc_url( $icon ); ?>" alt="">
							<input type="hidden" name="app_icon_id" id="hilo-icon-id" value="<?php echo (int) $s['app_icon_id']; ?>">
							<button type="button" class="button" id="hilo-icon-pick">Elegir imagen</button>
							<button type="button" class="button-link" id="hilo-icon-reset">Usar el icono por defecto</button>
							<p class="description">PNG cuadrado de 512×512 px. Deja margen alrededor del logo para que Android no lo recorte.</p>
						</td></tr>
					</table>
				</div>

				<div class="hilo-card">
					<h2>Avanzado</h2>
					<table class="form-table" role="presentation">
						<tr><th><label for="hilo-slug">Ruta del panel</label></th><td><code><?php echo esc_html( home_url( '/' ) ); ?></code><input id="hilo-slug" name="app_slug" value="<?php echo esc_attr( $s['app_slug'] ); ?>" style="width:180px"><code>/</code><p class="description">Si la cambias, los agentes tendrán que reinstalar la app.</p></td></tr>
						<tr><th><label for="hilo-push">Email de contacto push (VAPID)</label></th><td><input id="hilo-push" type="email" class="regular-text" name="push_subject" value="<?php echo esc_attr( $s['push_subject'] ); ?>"></td></tr>
						<tr><th><label for="hilo-ret">Borrar chats resueltos después de</label></th><td><input id="hilo-ret" type="number" min="0" name="retention_days" value="<?php echo (int) $s['retention_days']; ?>" style="width:90px"> días <span class="description">(0 = nunca)</span></td></tr>
					</table>
				</div>

				<?php submit_button( 'Guardar ajustes' ); ?>
			</form>

			<div>
				<div class="hilo-card">
					<h2>Panel de agentes</h2>
					<p>Atiende los chats desde el navegador o instálalo como app en el celular o el computador.</p>
					<p><a class="button button-primary" href="<?php echo esc_url( HILO_Settings::app_url() ); ?>" target="_blank" rel="noopener">Abrir panel</a></p>
					<p class="description"><code><?php echo esc_html( HILO_Settings::app_url() ); ?></code></p>
				</div>
				<div class="hilo-card">
					<h2>Agentes</h2>
					<?php foreach ( $agents as $a ) : ?>
						<div class="hilo-agent"><img src="<?php echo esc_url( $a['avatar'] ); ?>" alt=""><span class="hilo-dot <?php echo $a['online'] && $a['available'] ? 'on' : ''; ?>"></span> <?php echo esc_html( $a['name'] ); ?></div>
					<?php endforeach; ?>
					<p class="description">Para sumar agentes, asígnales el rol <strong>Agente de chat</strong> en <a href="<?php echo esc_url( admin_url( 'users.php' ) ); ?>">Usuarios</a>. Los administradores y gestores de tienda también pueden atender.</p>
				</div>
			</div>
			</div>
		</div>
		<script>
		(function(){
			var pick=document.getElementById('hilo-icon-pick'),reset=document.getElementById('hilo-icon-reset'),id=document.getElementById('hilo-icon-id'),img=document.getElementById('hilo-icon-preview'),def=<?php echo wp_json_encode( HILO_PLUGIN_URL . 'assets/agent/icons/icon-192.png' ); ?>,frame;
			pick.addEventListener('click',function(){
				if(!frame){frame=wp.media({title:'Icono de la app',library:{type:'image'},multiple:false});
				frame.on('select',function(){var a=frame.state().get('selection').first().toJSON();id.value=a.id;img.src=(a.sizes&&a.sizes.thumbnail?a.sizes.thumbnail.url:a.url);});}
				frame.open();
			});
			reset.addEventListener('click',function(){id.value=0;img.src=def;});
		})();
		</script>
		<?php
	}
}
