<?php
/**
 * Shared REST helpers.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Cookie token, rate limiting and cache headers.
 */
class HILO_REST {

	const COOKIE = 'hilo_vt';

	/**
	 * Hooks.
	 */
	public static function init() {
		// Allow anonymous access through All-In-One WP Security's REST lock.
		add_filter(
			'aios_whitelisted_rest_routes',
			static function ( $routes ) {
				$routes   = is_array( $routes ) ? $routes : array();
				$routes[] = 'hilo-chat';
				return $routes;
			}
		);

		// Never cache our responses (Varnish / Cloudflare / browsers).
		add_filter(
			'rest_post_dispatch',
			static function ( $response, $server, $request ) {
				if ( 0 === strpos( $request->get_route(), '/' . HILO_REST_NS ) && $response instanceof WP_HTTP_Response ) {
					$response->header( 'Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0, private' );
					$response->header( 'X-Robots-Tag', 'noindex' );
				}
				return $response;
			},
			10,
			3
		);
	}

	/**
	 * New random visitor token.
	 *
	 * @return string
	 */
	public static function issue_token() {
		return bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Sets the visitor cookie.
	 *
	 * @param string $token Token.
	 */
	public static function set_token_cookie( $token ) {
		if ( headers_sent() ) {
			return;
		}
		setcookie(
			self::COOKIE,
			$token,
			array(
				'expires'  => time() + 90 * DAY_IN_SECONDS,
				'path'     => COOKIEPATH ? COOKIEPATH : '/',
				'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
				'secure'   => is_ssl(),
				'httponly' => true,
				'samesite' => 'Lax',
			)
		);
		$_COOKIE[ self::COOKIE ] = $token;
	}

	/**
	 * Reads the visitor token cookie.
	 *
	 * @return string
	 */
	public static function get_token() {
		return isset( $_COOKIE[ self::COOKIE ] ) ? preg_replace( '/[^a-f0-9]/', '', (string) $_COOKIE[ self::COOKIE ] ) : '';
	}

	/**
	 * Client IP (Cloudflare aware).
	 *
	 * @return string
	 */
	public static function ip() {
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR' ) as $key ) {
			if ( ! empty( $_SERVER[ $key ] ) ) {
				$ip = filter_var( wp_unslash( $_SERVER[ $key ] ), FILTER_VALIDATE_IP );
				if ( $ip ) {
					return $ip;
				}
			}
		}
		return '0.0.0.0';
	}

	/**
	 * Simple fixed-window rate limiter.
	 *
	 * @param string $bucket Bucket name.
	 * @param int    $max    Max hits.
	 * @param int    $window Seconds.
	 * @return bool True when allowed.
	 */
	public static function rate_limit( $bucket, $max, $window ) {
		$key  = 'hilo_rl_' . md5( $bucket . '|' . self::ip() );
		$hits = (int) get_transient( $key );
		if ( $hits >= $max ) {
			return false;
		}
		set_transient( $key, $hits + 1, $window );
		return true;
	}

	/**
	 * Standard "too many requests" error.
	 *
	 * @return WP_Error
	 */
	public static function too_many() {
		return new WP_Error( 'hilo_rate_limited', 'Estás enviando demasiados mensajes. Espera un momento.', array( 'status' => 429 ) );
	}

	/**
	 * Public widget status.
	 *
	 * @return array
	 */
	public static function status() {
		$online = HILO_Presence::store_is_online();
		return array(
			'online'      => $online,
			'inHours'     => HILO_Schedule::is_open_now(),
			'statusText'  => $online ? HILO_Settings::get( 'online_text' ) : HILO_Settings::get( 'offline_text' ),
			'nextOpening' => $online ? '' : HILO_Schedule::next_opening_text(),
		);
	}
}
