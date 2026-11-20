<?php
/**
 * Main plugin orchestrator.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Wires every component.
 */
class HILO_Plugin {

	/**
	 * Instance.
	 *
	 * @var HILO_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Singleton.
	 *
	 * @return HILO_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		HILO_Install::maybe_upgrade();
		HILO_REST::init();
		HILO_Mailer::init();
		HILO_Widget::init();
		HILO_Agent_App::init();

		if ( is_admin() ) {
			HILO_Admin::init();
		}

		add_action( 'rest_api_init', array( 'HILO_REST_Visitor', 'register' ) );
		add_action( 'rest_api_init', array( 'HILO_REST_Agent', 'register' ) );
		add_action( 'hilo_daily_maintenance', array( $this, 'maintenance' ) );
		add_action( 'init', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain() {
		load_plugin_textdomain( 'hilo-chat', false, dirname( plugin_basename( HILO_PLUGIN_FILE ) ) . '/languages' );
	}

	/**
	 * Daily cleanup.
	 */
	public function maintenance() {
		HILO_DB::purge( (int) HILO_Settings::get( 'retention_days', 180 ) );
	}
}
