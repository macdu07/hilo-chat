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
			'title'            => 'Hablemos',
			'subtitle'         => '',
			'greeting'         => '¡Hola! 👋 ¿Tienes alguna pregunta sobre un producto o tu pedido? Escríbenos.',
			'online_text'      => 'En línea · respondemos en minutos',
			'offline_text'     => 'Déjanos tu mensaje y te responderemos por email lo antes posible.',
			'hide_on_checkout' => true,
			'position'         => 'right',
			'retention_days'   => 180,
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
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $default;
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
		return '' !== $subtitle ? $subtitle : 'Equipo ' . self::brand();
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
