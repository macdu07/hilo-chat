<?php
/**
 * Storefront widget loader.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Enqueues the visitor widget.
 */
class HILO_Widget {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * Whether the widget should render for this request.
	 *
	 * @return bool
	 */
	public static function should_render() {
		if ( ! HILO_Settings::get( 'enabled' ) || is_admin() ) {
			return false;
		}
		if ( HILO_Settings::get( 'test_mode' ) && ! current_user_can( HILO_CAP ) ) {
			return false;
		}
		if ( HILO_Settings::get( 'hide_on_checkout' ) && function_exists( 'is_checkout' ) && is_checkout() && ! is_order_received_page() ) {
			return false;
		}
		return (bool) apply_filters( 'hilo_chat_show_widget', true );
	}

	/**
	 * Enqueues assets and config.
	 */
	public static function enqueue() {
		if ( ! self::should_render() ) {
			return;
		}

		$user   = wp_get_current_user();
		$config = array(
			'rest'      => esc_url_raw( rest_url( HILO_REST_NS . '/' ) ),
			'nonce'     => $user->ID ? wp_create_nonce( 'wp_rest' ) : '',
			'css'       => HILO_PLUGIN_URL . 'assets/widget/widget.css?ver=' . HILO_VERSION . '.' . filemtime( HILO_PLUGIN_PATH . 'assets/widget/widget.css' ),
			'brand'     => HILO_Settings::brand(),
			'title'     => HILO_Settings::get( 'title' ),
			'team'      => HILO_Settings::team_name(),
			'greeting'  => HILO_Settings::get( 'greeting' ),
			'locale'    => HILO_I18n::js_locale(),
			'i18n'      => HILO_I18n::js_strings( 'assets/widget/widget.js' ),
			'whatsapp'  => HILO_Settings::whatsapp(),
			'teaser'    => HILO_Settings::get( 'teaser_enabled' ) && '' !== trim( (string) HILO_Settings::get( 'teaser_text' ) ) ? array(
				'text'  => HILO_Settings::get( 'teaser_text' ),
				'delay' => (int) HILO_Settings::get( 'teaser_delay', 8 ),
			) : null,
			'position'  => 'left' === HILO_Settings::get( 'position' ) ? 'left' : 'right',
			'testMode'  => (bool) HILO_Settings::get( 'test_mode' ),
			'user'      => $user->ID ? array(
				'name'  => trim( $user->first_name . ' ' . $user->last_name ) ? trim( $user->first_name . ' ' . $user->last_name ) : $user->display_name,
				'email' => $user->user_email,
			) : null,
			'theme'     => array(
				'primary'     => sanitize_hex_color( HILO_Settings::get( 'color_primary' ) ) ?: '#1f2937',
				'accent'      => sanitize_hex_color( HILO_Settings::get( 'color_accent' ) ) ?: '#2563eb',
				'soft'        => sanitize_hex_color( HILO_Settings::get( 'color_soft' ) ) ?: '#f3f4f6',
				'fontHeading' => self::font( HILO_Settings::get( 'font_heading' ) ),
				'fontBody'    => self::font( HILO_Settings::get( 'font_body' ) ),
			),
		);

		wp_enqueue_script(
			'hilo-chat-widget',
			HILO_PLUGIN_URL . 'assets/widget/widget.js',
			array(),
			HILO_VERSION . '.' . filemtime( HILO_PLUGIN_PATH . 'assets/widget/widget.js' ),
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_add_inline_script( 'hilo-chat-widget', 'window.HiloChatConfig=' . wp_json_encode( $config ) . ';', 'before' );
	}

	/**
	 * Sanitizes a CSS font-family value.
	 *
	 * @param string $font Value.
	 * @return string
	 */
	public static function font( $font ) {
		$font = trim( preg_replace( '/[^A-Za-z0-9 ,\'"\-]/', '', (string) $font ) );
		return '' !== $font ? $font : 'inherit';
	}
}
