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
		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'change_locale', array( $this, 'load_textdomain' ) );
	}

	/**
	 * Loads translations.
	 */
	public function load_textdomain( $locale = null ) {
		$locale = is_string( $locale ) && '' !== $locale ? $locale : determine_locale();
		unload_textdomain( 'hilo-chat', true );
		$dir = HILO_PLUGIN_PATH . 'languages/';
		// Prefer files from wp-content/languages/plugins (e.g. Loco Translate), then ours.
		if ( load_textdomain( 'hilo-chat', WP_LANG_DIR . '/plugins/hilo-chat-' . $locale . '.mo', $locale ) ) {
			return;
		}
		if ( load_textdomain( 'hilo-chat', $dir . 'hilo-chat-' . $locale . '.mo', $locale ) ) {
			return;
		}
		// Any Spanish variant (es_CO, es_MX, es_AR…) falls back to es_ES.
		if ( 0 === strpos( $locale, 'es_' ) ) {
			load_textdomain( 'hilo-chat', $dir . 'hilo-chat-es_ES.mo', $locale );
		}
	}

	/**
	 * Daily cleanup.
	 */
	public function maintenance() {
		HILO_DB::purge( (int) HILO_Settings::get( 'retention_days', 180 ) );
	}
}
