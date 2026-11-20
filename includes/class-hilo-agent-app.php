<?php
/**
 * Installable agent app (PWA) served outside wp-admin.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Routes /{slug}/, /{slug}/manifest and /{slug}/service-worker.
 */
class HILO_Agent_App {

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'rewrites' ) );
		add_filter( 'query_vars', array( __CLASS__, 'query_vars' ) );
		add_action( 'parse_request', array( __CLASS__, 'dispatch' ), 0 );
		add_action( 'wp_ajax_hilo_refresh_nonce', array( __CLASS__, 'refresh_nonce' ) );
		add_filter( 'auth_cookie_expiration', array( __CLASS__, 'long_sessions' ), 10, 3 );
	}

	/**
	 * Registers rewrite rules and flushes when the slug changes.
	 */
	public static function rewrites() {
		$slug = preg_quote( HILO_Settings::app_slug(), '#' );
		add_rewrite_rule( '^' . $slug . '/?$', 'index.php?hilo_app=app', 'top' );
		add_rewrite_rule( '^' . $slug . '/manifest/?$', 'index.php?hilo_app=manifest', 'top' );
		add_rewrite_rule( '^' . $slug . '/service-worker/?$', 'index.php?hilo_app=sw', 'top' );

		if ( get_option( 'hilo_flush_rewrite' ) || get_option( 'hilo_rewrite_slug' ) !== HILO_Settings::app_slug() ) {
			flush_rewrite_rules( false );
			delete_option( 'hilo_flush_rewrite' );
			update_option( 'hilo_rewrite_slug', HILO_Settings::app_slug(), true );
		}
	}

	/**
	 * Adds the query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[] = 'hilo_app';
		return $vars;
	}

	/**
	 * Serves the app, manifest or service worker before the theme loads.
	 *
	 * @param WP $wp Request.
	 */
	public static function dispatch( $wp ) {
		if ( empty( $wp->query_vars['hilo_app'] ) ) {
			return;
		}
		switch ( $wp->query_vars['hilo_app'] ) {
			case 'manifest':
				self::manifest();
				break;
			case 'sw':
				self::service_worker();
				break;
			default:
				self::app();
		}
		exit;
	}

	/**
	 * Icon set for manifest and head.
	 *
	 * @return array
	 */
	private static function icons() {
		$base  = HILO_PLUGIN_URL . 'assets/agent/icons/';
		$icons = array(
			'192'      => $base . 'icon-192.png',
			'512'      => $base . 'icon-512.png',
			'maskable' => $base . 'maskable-512.png',
			'apple'    => $base . 'apple-touch-icon.png',
			'badge'    => $base . 'badge-96.png',
		);
		$custom = (int) HILO_Settings::get( 'app_icon_id' );
		if ( $custom ) {
			$full = wp_get_attachment_image_url( $custom, 'full' );
			if ( $full ) {
				$icons['192']      = $full;
				$icons['512']      = $full;
				$icons['maskable'] = ''; // A custom icon may have transparent corners; don't declare it maskable.
				$icons['apple']    = $full;
			}
		}
		return apply_filters( 'hilo_chat_app_icons', $icons );
	}

	/**
	 * Outputs the web app manifest.
	 */
	private static function manifest() {
		$icons = self::icons();
		$brand = HILO_Settings::brand();
		nocache_headers();
		header( 'Content-Type: application/manifest+json; charset=utf-8' );
		echo wp_json_encode(
			array(
				'id'               => HILO_Settings::app_url(),
				'name'             => $brand . ' · Chat',
				'short_name'       => mb_substr( $brand, 0, 12 ) . ' Chat',
				/* translators: %s: store name. */
				'description'      => sprintf( __( 'Answer %s chats', 'hilo-chat' ), $brand ),
				'start_url'        => HILO_Settings::app_url(),
				'scope'            => HILO_Settings::app_url(),
				'display'          => 'standalone',
				'orientation'      => 'any',
				'background_color' => '#ffffff',
				'theme_color'      => sanitize_hex_color( HILO_Settings::get( 'color_primary' ) ) ?: '#1f2937',
				'lang'             => HILO_I18n::js_locale(),
				'icons'            => array_values(
					array_filter(
						array(
							array( 'src' => $icons['192'], 'sizes' => '192x192', 'type' => 'image/png', 'purpose' => 'any' ),
							array( 'src' => $icons['512'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any' ),
							$icons['maskable'] ? array( 'src' => $icons['maskable'], 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'maskable' ) : null,
						)
					)
				),
			),
			JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
		);
	}

	/**
	 * Outputs the service worker with the right scope.
	 */
	private static function service_worker() {
		$file = HILO_PLUGIN_PATH . 'assets/agent/sw.js';
		$js   = file_get_contents( $file ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$js   = strtr(
			$js,
			array(
				'__HILO_VERSION__' => HILO_VERSION . '-' . filemtime( $file ),
				'__HILO_APP_URL__' => esc_url_raw( HILO_Settings::app_url() ),
				'__HILO_ICON__'    => esc_url_raw( self::icons()['192'] ),
				'__HILO_BADGE__'   => esc_url_raw( self::icons()['badge'] ),
			)
		);
		header( 'Content-Type: application/javascript; charset=utf-8' );
		header( 'Cache-Control: no-cache, no-store, must-revalidate, max-age=0' );
		header( 'Service-Worker-Allowed: /' . HILO_Settings::app_slug() . '/' );
		echo $js; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Outputs the agent app shell.
	 */
	private static function app() {
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( wp_login_url( HILO_Settings::app_url() ) );
			exit;
		}
		if ( ! current_user_can( HILO_CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to answer chats. Ask an administrator for the "Chat agent" role.', 'hilo-chat' ), 403 );
		}

		// The app speaks each agent's profile language.
		switch_to_user_locale( get_current_user_id() );

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		header( 'X-Frame-Options: SAMEORIGIN' );
		header( 'Referrer-Policy: same-origin' );

		$user   = wp_get_current_user();
		$icons  = self::icons();
		$assets = HILO_PLUGIN_URL . 'assets/agent/';
		$ver    = HILO_VERSION . '.' . max( filemtime( HILO_PLUGIN_PATH . 'assets/agent/app.js' ), filemtime( HILO_PLUGIN_PATH . 'assets/agent/app.css' ) );
		$config = array(
			'rest'      => esc_url_raw( rest_url( HILO_REST_NS . '/' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'ajax'      => admin_url( 'admin-ajax.php' ),
			'appUrl'    => HILO_Settings::app_url(),
			'swUrl'     => HILO_Settings::app_url() . 'service-worker',
			'vapid'     => HILO_Push::public_key(),
			'brand'     => HILO_Settings::brand(),
			'logout'    => wp_logout_url( HILO_Settings::app_url() ),
			'adminUrl'  => current_user_can( 'manage_options' ) ? admin_url( 'admin.php?page=hilo-chat' ) : '',
			'canManage' => current_user_can( 'manage_options' ),
			'canPurge'  => current_user_can( 'manage_woocommerce' ) || current_user_can( 'manage_options' ),
			'locale'    => HILO_I18n::js_locale(),
			'i18n'      => HILO_I18n::js_strings( 'assets/agent/app.js' ),
			'me'        => array_merge(
				HILO_Presence::agent_public( $user->ID ),
				array( 'id' => (int) $user->ID )
			),
			'theme'     => array(
				'primary' => sanitize_hex_color( HILO_Settings::get( 'color_primary' ) ) ?: '#1f2937',
				'accent'  => sanitize_hex_color( HILO_Settings::get( 'color_accent' ) ) ?: '#2563eb',
				'soft'    => sanitize_hex_color( HILO_Settings::get( 'color_soft' ) ) ?: '#f3f4f6',
			),
		);

		include HILO_PLUGIN_PATH . 'templates/agent-app.php';
	}

	/**
	 * admin-ajax: returns a fresh REST nonce for long-lived app sessions.
	 */
	public static function refresh_nonce() {
		if ( ! current_user_can( HILO_CAP ) ) {
			wp_send_json_error( null, 403 );
		}
		nocache_headers();
		wp_send_json_success( array( 'nonce' => wp_create_nonce( 'wp_rest' ) ) );
	}

	/**
	 * Keeps agents signed in for 30 days when they tick "remember me".
	 *
	 * @param int  $length   Seconds.
	 * @param int  $user_id  User.
	 * @param bool $remember Remember me.
	 * @return int
	 */
	public static function long_sessions( $length, $user_id, $remember ) {
		if ( $remember && user_can( $user_id, HILO_CAP ) ) {
			return max( $length, 30 * DAY_IN_SECONDS );
		}
		return $length;
	}
}
