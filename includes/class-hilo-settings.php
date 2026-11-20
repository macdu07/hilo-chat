<?php
/**
 * Settings access.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Typed accessor for the hilo_settings option.
 */
class HILO_Settings {

	/**
	 * Cached settings.
	 *
	 * @var array|null
	 */
	private static $cache = null;

	/**
	 * Default settings.
	 *
	 * @return array
	 */
	public static function defaults() {
		$day = array(
			'enabled' => true,
			'start'   => '09:00',
			'end'     => '18:00',
		);
		return array(
			'enabled'          => true,
			'test_mode'        => true,
			'brand_name'       => wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES ),
			'app_slug'         => 'chat-agentes',
			'app_icon_id'      => 0,
			'logo_url'         => '',
			'color_primary'    => '#1f2937',
			'color_accent'     => '#2563eb',
			'color_soft'       => '#f3f4f6',
			'font_heading'     => 'inherit',
			'font_body'        => 'inherit',
			// Texts: empty means "use the translated default" (see text_defaults()).
			'title'            => '',
			'subtitle'         => '',
			'greeting'         => '',
			'online_text'      => '',
			'offline_text'     => '',
			'hide_on_checkout' => true,
			'position'         => 'right',
			'retention_days'   => 180,
			'always_open'      => false,
			'teaser_enabled'   => true,
			'teaser_text'      => '',
			'teaser_delay'     => 8,
			'whatsapp_enabled' => false,
			'whatsapp_number'  => '',
			'whatsapp_message' => '',
			'whatsapp_label'   => '',
			'push_subject'     => get_option( 'admin_email' ),
			'hours'            => array(
				1 => $day,
				2 => $day,
				3 => $day,
				4 => $day,
				5 => $day,
				6 => array(
					'enabled' => true,
					'start'   => '10:00',
					'end'     => '14:00',
				),
				0 => array(
					'enabled' => false,
					'start'   => '10:00',
					'end'     => '14:00',
				),
			),
		);
	}

	/**
	 * Returns all settings merged with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$cache ) {
			$saved       = get_option( 'hilo_settings', array() );
			$defaults    = self::defaults();
			self::$cache = array_merge( $defaults, is_array( $saved ) ? $saved : array() );
			if ( ! is_array( self::$cache['hours'] ) ) {
				self::$cache['hours'] = $defaults['hours'];
			}
		}
		return self::$cache;
	}

	/**
	 * Gets one setting.
	 *
	 * @param string $key     Key.
	 * @param mixed  $default Fallback.
	 * @return mixed
	 */
	public static function get( $key, $default = null ) {
		$all   = self::all();
		$value = array_key_exists( $key, $all ) ? $all[ $key ] : $default;
		if ( is_string( $value ) && '' === trim( $value ) ) {
			$texts = self::text_defaults();
			if ( isset( $texts[ $key ] ) ) {
				return $texts[ $key ];
			}
		}
		return $value;
	}

	/**
	 * Customer-facing texts in the current language, used when a field is left empty.
	 *
	 * @return array
	 */
	public static function text_defaults() {
		return array(
			'title'        => __( "Let's talk", 'hilo-chat' ),
			'greeting'     => __( 'Hi! 👋 Do you have a question about a product or your order? Write to us.', 'hilo-chat' ),
			'online_text'  => __( 'Online · we reply in minutes', 'hilo-chat' ),
			'offline_text' => __( 'Leave us a message and we will reply by email as soon as possible.', 'hilo-chat' ),
			'teaser_text'  => __( 'Any questions? We are here to help 💬', 'hilo-chat' ),
			'whatsapp_message' => __( 'Hi! I have a question.', 'hilo-chat' ),
			'whatsapp_label'   => __( 'Write to us on WhatsApp', 'hilo-chat' ),
		);
	}

	/**
	 * WhatsApp number as digits (country code included), or '' when missing or invalid.
	 *
	 * @param string|null $raw Optional raw value; defaults to the saved setting.
	 * @return string
	 */
	public static function whatsapp_number( $raw = null ) {
		$digits = preg_replace( '/\D+/', '', null === $raw ? (string) self::raw( 'whatsapp_number' ) : (string) $raw );
		$digits = ltrim( $digits, '0' );
		$len    = strlen( $digits );
		return ( $len >= 7 && $len <= 15 ) ? $digits : '';
	}

	/**
	 * WhatsApp config for the widget, or null when it should not show.
	 *
	 * @return array|null
	 */
	public static function whatsapp() {
		$number = self::whatsapp_number();
		if ( ! self::get( 'whatsapp_enabled' ) || '' === $number ) {
			return null;
		}
		return array(
			'number'  => $number,
			'message' => (string) self::get( 'whatsapp_message' ),
			'label'   => (string) self::get( 'whatsapp_label' ),
		);
	}

	/**
	 * Raw saved value (no translated fallback), for the settings form.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function raw( $key ) {
		$all = self::all();
		return $all[ $key ] ?? '';
	}

	/**
	 * Brand / team name shown to customers.
	 *
	 * @return string
	 */
	public static function brand() {
		$name = trim( (string) self::get( 'brand_name' ) );
		return '' !== $name ? $name : wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
	}

	/**
	 * Default sender name when no agent is known.
	 *
	 * @return string
	 */
	public static function team_name() {
		$subtitle = trim( (string) self::get( 'subtitle' ) );
		/* translators: %s: store name. */
		return '' !== $subtitle ? $subtitle : sprintf( __( '%s team', 'hilo-chat' ), self::brand() );
	}

	/**
	 * Agent app path segment (without slashes).
	 *
	 * @return string
	 */
	public static function app_slug() {
		$slug = trim( (string) self::get( 'app_slug' ), '/' );
		return '' !== $slug ? sanitize_title( $slug ) : 'chat-agentes';
	}

	/**
	 * Agent app URL.
	 *
	 * @param string $hash Optional fragment.
	 * @return string
	 */
	public static function app_url( $hash = '' ) {
		return home_url( '/' . self::app_slug() . '/' ) . ( $hash ? '#' . $hash : '' );
	}

	/**
	 * Saves settings.
	 *
	 * @param array $settings Settings.
	 */
	public static function save( array $settings ) {
		update_option( 'hilo_settings', $settings, true );
		self::$cache = null;
	}
}
