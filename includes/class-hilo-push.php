<?php
/**
 * Web Push (RFC 8030 / 8291 / 8292) without external dependencies.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Stores subscriptions and sends encrypted push notifications.
 */
class HILO_Push {

	/**
	 * DER prefix of a P-256 SubjectPublicKeyInfo for an uncompressed point.
	 */
	const P256_SPKI_PREFIX = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

	/**
	 * Allowed push service hosts (prevents SSRF via fake endpoints).
	 *
	 * @var string[]
	 */
	private static $allowed_hosts = array(
		'fcm.googleapis.com',
		'updates.push.services.mozilla.com',
		'push.services.mozilla.com',
		'web.push.apple.com',
		'notify.windows.com',
	);

	/**
	 * Queue of notifications to deliver at shutdown.
	 *
	 * @var array
	 */
	private static $queue = array();

	/**
	 * Whether the shutdown hook was registered.
	 *
	 * @var bool
	 */
	private static $hooked = false;

	/* ---------------------------------------------------------------------
	 * Helpers
	 * ------------------------------------------------------------------- */

	/**
	 * Base64url encode.
	 *
	 * @param string $data Binary.
	 * @return string
	 */
	public static function b64u_encode( $data ) {
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * Base64url decode.
	 *
	 * @param string $data Text.
	 * @return string|false
	 */
	public static function b64u_decode( $data ) {
		$data = strtr( (string) $data, '-_', '+/' );
		$pad  = strlen( $data ) % 4;
		if ( $pad ) {
			$data .= str_repeat( '=', 4 - $pad );
		}
		return base64_decode( $data, true );
	}

	/**
	 * Public VAPID key (base64url, uncompressed point).
	 *
	 * @return string
	 */
	public static function public_key() {
		$keys = get_option( 'hilo_vapid' );
		return is_array( $keys ) && ! empty( $keys['public'] ) ? $keys['public'] : '';
	}

	/**
	 * Builds an OpenSSL public key from a raw uncompressed P-256 point.
	 *
	 * @param string $raw 65-byte point.
	 * @return OpenSSLAsymmetricKey|false
	 */
	private static function public_key_from_raw( $raw ) {
		if ( 65 !== strlen( $raw ) || "\x04" !== $raw[0] ) {
			return false;
		}
		$der = hex2bin( self::P256_SPKI_PREFIX ) . $raw;
		$pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split( base64_encode( $der ), 64, "\n" ) . "-----END PUBLIC KEY-----\n";
		return openssl_pkey_get_public( $pem );
	}

	/**
	 * Whether an endpoint belongs to a known push service.
	 *
	 * @param string $endpoint URL.
	 * @return bool
	 */
	public static function is_allowed_endpoint( $endpoint ) {
		$parts = wp_parse_url( $endpoint );
		if ( empty( $parts['scheme'] ) || 'https' !== $parts['scheme'] || empty( $parts['host'] ) ) {
			return false;
		}
		$host = strtolower( $parts['host'] );
		foreach ( self::$allowed_hosts as $allowed ) {
			if ( $host === $allowed || str_ends_with( $host, '.' . $allowed ) ) {
				return true;
			}
		}
		return false;
	}

	/* ---------------------------------------------------------------------
	 * Subscriptions
	 * ------------------------------------------------------------------- */

	/**
	 * Saves a subscription for a user.
	 *
	 * @param int    $user_id  User.
	 * @param string $endpoint Endpoint.
	 * @param string $p256dh   Client public key (b64url).
	 * @param string $auth     Auth secret (b64url).
	 * @return bool
	 */
	public static function subscribe( $user_id, $endpoint, $p256dh, $auth ) {
		global $wpdb;
		$raw_key  = self::b64u_decode( $p256dh );
		$raw_auth = self::b64u_decode( $auth );
		if ( ! self::is_allowed_endpoint( $endpoint ) || 65 !== strlen( (string) $raw_key ) || 16 !== strlen( (string) $raw_auth ) ) {
			return false;
		}
		$table = HILO_DB::table( 'push_subscriptions' );
		$hash  = hash( 'sha256', $endpoint );
		$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (user_id, endpoint_hash, endpoint, p256dh, auth, user_agent, created_at) VALUES (%d, %s, %s, %s, %s, %s, %s)
				ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), p256dh = VALUES(p256dh), auth = VALUES(auth), user_agent = VALUES(user_agent)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$user_id,
				$hash,
				$endpoint,
				$p256dh,
				$auth,
				$ua,
				HILO_DB::now()
			)
		);
		return true;
	}

	/**
	 * Removes a subscription.
	 *
	 * @param string $endpoint Endpoint.
	 */
	public static function unsubscribe( $endpoint ) {
		global $wpdb;
		$wpdb->delete( HILO_DB::table( 'push_subscriptions' ), array( 'endpoint_hash' => hash( 'sha256', $endpoint ) ) );
	}

	/**
	 * Subscriptions for users.
	 *
	 * @param int[] $user_ids Users.
	 * @return array
	 */
	private static function subscriptions_for( array $user_ids ) {
		global $wpdb;
		$user_ids = array_filter( array_map( 'intval', $user_ids ) );
		if ( ! $user_ids ) {
			return array();
		}
		$table = HILO_DB::table( 'push_subscriptions' );
		$in    = implode( ',', $user_ids );
		return $wpdb->get_results( "SELECT * FROM {$table} WHERE user_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/* ---------------------------------------------------------------------
	 * Notifying
	 * ------------------------------------------------------------------- */

	/**
	 * Notifies the right agents about visitor activity.
	 *
	 * @param object $conversation Conversation row.
	 * @param array  $message      Formatted message.
	 * @param bool   $is_new       Whether the conversation was just created.
	 */
	public static function notify_visitor_message( $conversation, $message, $is_new ) {
		$recipients = array();
		if ( $conversation->assigned_agent_id && ! $is_new ) {
			$recipients[] = (int) $conversation->assigned_agent_id;
		} else {
			foreach ( HILO_Presence::agents() as $agent ) {
				if ( HILO_Presence::is_available( $agent->ID ) ) {
					$recipients[] = (int) $agent->ID;
				}
			}
		}

		// Skip agents that are looking at the app right now (they see it live).
		$recipients = array_filter(
			$recipients,
			static function ( $id ) {
				return ! HILO_Presence::is_viewing( $id );
			}
		);
		if ( ! $recipients ) {
			return;
		}

		$name = $conversation->name ? $conversation->name : 'Visitante';
		$body = '' !== $message['body'] ? $message['body'] : '📷 Envió una imagen';
		self::queue(
			$recipients,
			array(
				'title' => $is_new ? 'Nuevo chat · ' . $name : $name,
				'body'  => wp_html_excerpt( $body, 140, '…' ),
				'tag'   => 'hilo-conv-' . $conversation->id,
				'url'   => HILO_Settings::app_url( 'c' . $conversation->id ),
				'conv'  => (int) $conversation->id,
			)
		);
	}

	/**
	 * Notifies an agent that a chat was transferred to them.
	 *
	 * @param object $conversation Conversation.
	 * @param int    $agent_id     Target agent.
	 * @param int    $from_id      Agent who transferred.
	 */
	public static function notify_transfer( $conversation, $agent_id, $from_id ) {
		$from = HILO_Presence::agent_public( $from_id );
		self::queue(
			array( $agent_id ),
			array(
				'title' => 'Te asignaron un chat',
				'body'  => $from['name'] . ' te transfirió la conversación con ' . ( $conversation->name ? $conversation->name : 'un visitante' ) . '.',
				'tag'   => 'hilo-conv-' . $conversation->id,
				'url'   => HILO_Settings::app_url( 'c' . $conversation->id ),
				'conv'  => (int) $conversation->id,
			)
		);
	}

	/**
	 * Queues a payload for users; sent after the response is flushed.
	 *
	 * @param int[] $user_ids Users.
	 * @param array $payload  Payload.
	 */
	public static function queue( array $user_ids, array $payload ) {
		self::$queue[] = array( $user_ids, $payload );
		if ( ! self::$hooked ) {
			self::$hooked = true;
			add_action( 'shutdown', array( __CLASS__, 'flush_queue' ), 9999 );
		}
	}

	/**
	 * Sends queued notifications after the HTTP response is finished.
	 */
	public static function flush_queue() {
		if ( ! self::$queue ) {
			return;
		}
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		foreach ( self::$queue as $item ) {
			list( $user_ids, $payload ) = $item;
			foreach ( self::subscriptions_for( $user_ids ) as $sub ) {
				self::send( $sub, $payload );
			}
		}
		self::$queue = array();
	}

	/**
	 * Sends one notification. Removes dead subscriptions.
	 *
	 * @param object $sub     Subscription row.
	 * @param array  $payload Payload.
	 * @return bool
	 */
	public static function send( $sub, array $payload ) {
		$keys = get_option( 'hilo_vapid' );
		if ( ! is_array( $keys ) || empty( $keys['private_pem'] ) ) {
			return false;
		}
		$encrypted = self::encrypt( wp_json_encode( $payload ), $sub->p256dh, $sub->auth );
		$jwt       = self::vapid_jwt( $sub->endpoint, $keys['private_pem'] );
		if ( ! $encrypted || ! $jwt ) {
			return false;
		}

		$response = wp_remote_post(
			$sub->endpoint,
			array(
				'timeout' => 6,
				'headers' => array(
					'Content-Type'     => 'application/octet-stream',
					'Content-Encoding' => 'aes128gcm',
					'TTL'              => '86400',
					'Urgency'          => 'high',
					'Topic'            => substr( preg_replace( '/[^A-Za-z0-9\-_]/', '', $payload['tag'] ?? 'hilo' ), 0, 32 ),
					'Authorization'    => 'vapid t=' . $jwt . ', k=' . $keys['public'],
				),
				'body'    => $encrypted,
			)
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 404 === $code || 410 === $code ) {
			self::unsubscribe( $sub->endpoint );
		}
		return $code >= 200 && $code < 300;
	}

	/**
	 * Creates the VAPID JWT (ES256).
	 *
	 * @param string $endpoint    Push endpoint.
	 * @param string $private_pem Private key.
	 * @return string|false
	 */
	public static function vapid_jwt( $endpoint, $private_pem ) {
		$parts = wp_parse_url( $endpoint );
		$aud   = $parts['scheme'] . '://' . $parts['host'] . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' );
		$sub   = HILO_Settings::get( 'push_subject' );
		$sub   = is_email( $sub ) ? 'mailto:' . $sub : home_url( '/' );

		$header  = self::b64u_encode( wp_json_encode( array( 'typ' => 'JWT', 'alg' => 'ES256' ) ) );
		$claims  = self::b64u_encode(
			wp_json_encode(
				array(
					'aud' => $aud,
					'exp' => time() + 12 * HOUR_IN_SECONDS,
					'sub' => $sub,
				)
			)
		);
		$signing = $header . '.' . $claims;

		$key = openssl_pkey_get_private( $private_pem );
		if ( ! $key || ! openssl_sign( $signing, $der, $key, OPENSSL_ALGO_SHA256 ) ) {
			return false;
		}
		$raw = self::der_to_raw_signature( $der );
		return $raw ? $signing . '.' . self::b64u_encode( $raw ) : false;
	}

	/**
	 * Converts a DER ECDSA signature to raw r||s (64 bytes).
	 *
	 * @param string $der DER.
	 * @return string|false
	 */
	private static function der_to_raw_signature( $der ) {
		$pos = 0;
		if ( "\x30" !== $der[ $pos++ ] ) {
			return false;
		}
		$len = ord( $der[ $pos++ ] );
		if ( $len & 0x80 ) {
			$pos += $len & 0x7f;
		}
		$out = '';
		for ( $i = 0; $i < 2; $i++ ) {
			if ( "\x02" !== $der[ $pos++ ] ) {
				return false;
			}
			$ilen = ord( $der[ $pos++ ] );
			$int  = substr( $der, $pos, $ilen );
			$pos += $ilen;
			$int  = ltrim( $int, "\x00" );
			$out .= str_pad( $int, 32, "\x00", STR_PAD_LEFT );
		}
		return 64 === strlen( $out ) ? $out : false;
	}

	/**
	 * Encrypts a payload with aes128gcm (RFC 8291).
	 *
	 * @param string $plaintext Payload.
	 * @param string $p256dh    UA public key (b64url).
	 * @param string $auth      Auth secret (b64url).
	 * @return string|false Request body.
	 */
	public static function encrypt( $plaintext, $p256dh, $auth ) {
		$ua_public   = self::b64u_decode( $p256dh );
		$auth_secret = self::b64u_decode( $auth );
		$ua_key      = self::public_key_from_raw( (string) $ua_public );
		if ( ! $ua_key || 16 !== strlen( (string) $auth_secret ) ) {
			return false;
		}

		$local = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);
		if ( ! $local ) {
			return false;
		}
		$details   = openssl_pkey_get_details( $local );
		$as_public = "\x04" . str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT );

		$shared = openssl_pkey_derive( $ua_key, $local, 32 );
		if ( false === $shared ) {
			return false;
		}
		$shared = str_pad( $shared, 32, "\0", STR_PAD_LEFT );

		$salt     = random_bytes( 16 );
		$key_info = "WebPush: info\0" . $ua_public . $as_public;
		$ikm      = hash_hkdf( 'sha256', $shared, 32, $key_info, $auth_secret );
		$cek      = hash_hkdf( 'sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt );
		$nonce    = hash_hkdf( 'sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt );

		$tag        = '';
		$ciphertext = openssl_encrypt( $plaintext . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16 );
		if ( false === $ciphertext ) {
			return false;
		}

		$header = $salt . pack( 'N', 4096 ) . chr( 65 ) . $as_public;
		return $header . $ciphertext . $tag;
	}
}
