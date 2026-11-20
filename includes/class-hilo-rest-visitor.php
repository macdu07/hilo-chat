<?php
/**
 * Visitor (storefront widget) endpoints.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Public REST routes authenticated by the visitor cookie token.
 */
class HILO_REST_Visitor {

	/**
	 * Registers routes.
	 */
	public static function register() {
		$ns = HILO_REST_NS;

		register_rest_route(
			$ns,
			'/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/visitor/start',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'start' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/visitor/poll',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'poll' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/visitor/message',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'message' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/visitor/upload',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'upload' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			$ns,
			'/visitor/typing',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'typing' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Finds the conversation for this visitor (cookie or logged-in user).
	 *
	 * @return object|null
	 */
	private static function current_conversation() {
		$c = HILO_DB::get_conversation_by_token( HILO_REST::get_token() );
		if ( $c ) {
			return $c;
		}
		$user_id = get_current_user_id();
		if ( $user_id ) {
			$c = HILO_DB::get_open_conversation_for_user( $user_id );
			if ( $c ) {
				$token = HILO_REST::issue_token();
				HILO_DB::update_conversation( $c->id, array( 'token_hash' => HILO_DB::hash_token( $token ) ), false );
				HILO_REST::set_token_cookie( $token );
				return $c;
			}
		}
		return null;
	}

	/**
	 * Visitor-facing conversation summary.
	 *
	 * @param object $c Conversation.
	 * @return array
	 */
	private static function conversation_payload( $c ) {
		return array(
			'id'          => $c->public_id,
			'status'      => $c->status,
			'name'        => $c->name,
			'agent'       => $c->assigned_agent_id ? HILO_Presence::agent_public( (int) $c->assigned_agent_id ) : null,
			'agentReadId' => (int) $c->agent_last_read_id,
		);
	}

	/**
	 * GET /status
	 *
	 * @return WP_REST_Response
	 */
	public static function status() {
		return rest_ensure_response( HILO_REST::status() );
	}

	/**
	 * POST /visitor/start — creates the conversation with the first message.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function start( WP_REST_Request $request ) {
		if ( '' !== (string) $request->get_param( 'website' ) ) { // Honeypot.
			return new WP_Error( 'hilo_spam', 'No se pudo enviar.', array( 'status' => 400 ) );
		}
		if ( ! HILO_REST::rate_limit( 'start', 5, 10 * MINUTE_IN_SECONDS ) ) {
			return HILO_REST::too_many();
		}

		$body = sanitize_textarea_field( (string) $request->get_param( 'message' ) );
		if ( '' === trim( $body ) ) {
			return new WP_Error( 'hilo_empty', 'Escribe tu mensaje.', array( 'status' => 400 ) );
		}

		$user_id = get_current_user_id();
		$name    = sanitize_text_field( (string) $request->get_param( 'name' ) );
		$email   = sanitize_email( (string) $request->get_param( 'email' ) );
		if ( $user_id ) {
			$user  = wp_get_current_user();
			$name  = $name ? $name : trim( $user->first_name . ' ' . $user->last_name );
			$name  = $name ? $name : $user->display_name;
			$email = $email ? $email : $user->user_email;
		}
		if ( '' === $name || ! is_email( $email ) ) {
			return new WP_Error( 'hilo_identity', 'Escribe tu nombre y un email válido para poder responderte.', array( 'status' => 400 ) );
		}

		$existing = self::current_conversation();
		$is_new   = false;
		if ( $existing ) {
			$c = $existing;
			HILO_DB::update_conversation( $c->id, array( 'name' => mb_substr( $name, 0, 120 ), 'email' => $email ), false );
			$c->name  = $name;
			$c->email = $email;
		} else {
			$token = HILO_REST::issue_token();
			$ua    = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
			$ctx   = HILO_Woo_Context::snapshot( $request );
			$c     = HILO_DB::create_conversation(
				array(
					'token_hash' => HILO_DB::hash_token( $token ),
					'user_id'    => $user_id,
					'name'       => mb_substr( $name, 0, 120 ),
					'email'      => $email,
					'page_url'   => isset( $ctx['page']['url'] ) ? $ctx['page']['url'] : '',
					'user_agent' => $ua,
					'context'    => wp_json_encode( $ctx ),
				)
			);
			if ( ! $c ) {
				return new WP_Error( 'hilo_db', 'No se pudo iniciar el chat. Intenta de nuevo.', array( 'status' => 500 ) );
			}
			HILO_REST::set_token_cookie( $token );
			$is_new = true;
		}

		$message = HILO_DB::add_message( $c, 'visitor', 0, $body );
		HILO_Push::notify_visitor_message( $c, $message, $is_new );

		$c = HILO_DB::get_conversation( $c->id );
		return rest_ensure_response(
			array(
				'conversation' => self::conversation_payload( $c ),
				'messages'     => HILO_DB::get_messages( $c->id ),
				'status'       => HILO_REST::status(),
			)
		);
	}

	/**
	 * GET /visitor/poll
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function poll( WP_REST_Request $request ) {
		$c = self::current_conversation();
		if ( ! $c ) {
			return rest_ensure_response(
				array(
					'conversation' => null,
					'status'       => HILO_REST::status(),
				)
			);
		}

		$after = absint( $request->get_param( 'after' ) );
		$open  = (bool) $request->get_param( 'open' );

		// Presence, read receipts and context.
		$update = array();
		$touch  = false;
		if ( time() - HILO_DB::ts( $c->visitor_last_seen ) >= 15 ) {
			$update['visitor_last_seen'] = HILO_DB::now();
		}
		if ( $open && (int) $c->last_message_id > (int) $c->visitor_last_read_id ) {
			$update['visitor_last_read_id'] = (int) $c->last_message_id;
			$touch                          = true;
		}
		if ( $request->get_param( 'ctx' ) ) {
			$current           = $c->context ? json_decode( $c->context, true ) : array();
			$update['context'] = wp_json_encode( HILO_Woo_Context::snapshot( $request, is_array( $current ) ? $current : array() ) );
		}
		if ( $update ) {
			HILO_DB::update_conversation( $c->id, $update, $touch );
		}

		$typing = HILO_Presence::get_typing( $c->id, 'agent' );
		return rest_ensure_response(
			array(
				'conversation' => self::conversation_payload( $c ),
				'messages'     => HILO_DB::get_messages( $c->id, $after ),
				'typing'       => $typing ? HILO_Presence::agent_public( (int) $typing['agent'] ) : null,
				'unread'       => ( ! $open && (int) $c->last_message_id > (int) $c->visitor_last_read_id ) ? self::count_unread( $c ) : 0,
				'status'       => $request->get_param( 'withStatus' ) ? HILO_REST::status() : null,
			)
		);
	}

	/**
	 * Unread agent messages for the visitor badge.
	 *
	 * @param object $c Conversation.
	 * @return int
	 */
	private static function count_unread( $c ) {
		global $wpdb;
		$t = HILO_DB::table( 'messages' );
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t} WHERE conversation_id = %d AND sender_type <> 'visitor' AND id > %d", $c->id, $c->visitor_last_read_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * POST /visitor/message
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function message( WP_REST_Request $request ) {
		$c = self::current_conversation();
		if ( ! $c ) {
			return new WP_Error( 'hilo_no_conversation', 'La conversación no existe.', array( 'status' => 404 ) );
		}
		if ( ! HILO_REST::rate_limit( 'msg', 30, MINUTE_IN_SECONDS ) ) {
			return HILO_REST::too_many();
		}
		$body = sanitize_textarea_field( (string) $request->get_param( 'body' ) );
		$msg  = HILO_DB::add_message( $c, 'visitor', 0, $body );
		if ( ! $msg ) {
			return new WP_Error( 'hilo_empty', 'El mensaje está vacío.', array( 'status' => 400 ) );
		}
		HILO_Presence::clear_typing( $c->id, 'visitor' );
		HILO_Push::notify_visitor_message( $c, $msg, false );
		return rest_ensure_response( array( 'message' => $msg ) );
	}

	/**
	 * POST /visitor/upload
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload( WP_REST_Request $request ) {
		$c = self::current_conversation();
		if ( ! $c ) {
			return new WP_Error( 'hilo_no_conversation', 'La conversación no existe.', array( 'status' => 404 ) );
		}
		if ( ! HILO_REST::rate_limit( 'upload', 10, 10 * MINUTE_IN_SECONDS ) ) {
			return HILO_REST::too_many();
		}
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'hilo_upload', 'No se recibió ningún archivo.', array( 'status' => 400 ) );
		}
		$att = HILO_Uploads::store( $files['file'] );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		$caption = sanitize_textarea_field( (string) $request->get_param( 'body' ) );
		$msg     = HILO_DB::add_message( $c, 'visitor', 0, $caption, $att );
		HILO_Push::notify_visitor_message( $c, $msg, false );
		return rest_ensure_response( array( 'message' => $msg ) );
	}

	/**
	 * POST /visitor/typing
	 *
	 * @return WP_REST_Response
	 */
	public static function typing() {
		$c = HILO_DB::get_conversation_by_token( HILO_REST::get_token() );
		if ( $c ) {
			HILO_Presence::set_typing( $c->id, 'visitor' );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
