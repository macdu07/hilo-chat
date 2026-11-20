<?php
/**
 * Agent app endpoints.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * REST routes for agents (cookie + nonce, hilo_answer_chats capability).
 */
class HILO_REST_Agent {

	/**
	 * Registers routes.
	 */
	public static function register() {
		$ns   = HILO_REST_NS;
		$perm = array( __CLASS__, 'can_answer' );

		$routes = array(
			'/agent/sync'                     => array( 'GET', 'sync' ),
			'/agent/conversation/(?P<id>\d+)' => array( 'GET', 'conversation' ),
			'/agent/message'                  => array( 'POST', 'message' ),
			'/agent/upload'                   => array( 'POST', 'upload' ),
			'/agent/read'                     => array( 'POST', 'read' ),
			'/agent/assign'                   => array( 'POST', 'assign' ),
			'/agent/close'                    => array( 'POST', 'close' ),
			'/agent/reopen'                   => array( 'POST', 'reopen' ),
			'/agent/delete'                   => array( 'POST', 'delete' ),
			'/agent/delete-resolved'          => array( 'POST', 'delete_resolved' ),
			'/agent/typing'                   => array( 'POST', 'typing' ),
			'/agent/presence'                 => array( 'POST', 'presence' ),
			'/agent/quick-replies'            => array( 'GET,POST', 'quick_replies' ),
			'/agent/quick-replies/(?P<id>\d+)' => array( 'DELETE', 'delete_quick_reply' ),
			'/agent/push/subscribe'           => array( 'POST', 'push_subscribe' ),
			'/agent/push/unsubscribe'         => array( 'POST', 'push_unsubscribe' ),
			'/agent/push/test'                => array( 'POST', 'push_test' ),
		);

		foreach ( $routes as $route => $def ) {
			register_rest_route(
				$ns,
				$route,
				array(
					'methods'             => $def[0],
					'callback'            => array( __CLASS__, $def[1] ),
					'permission_callback' => $perm,
				)
			);
		}
	}

	/**
	 * Permission check.
	 *
	 * @return bool
	 */
	public static function can_answer() {
		return current_user_can( HILO_CAP );
	}

	/**
	 * Loads a conversation or returns an error.
	 *
	 * @param int $id ID.
	 * @return object|WP_Error
	 */
	private static function load( $id ) {
		$c = HILO_DB::get_conversation( absint( $id ) );
		return $c ? $c : new WP_Error( 'hilo_not_found', __( 'Conversation not found.', 'hilo-chat' ), array( 'status' => 404 ) );
	}

	/**
	 * Adds a system note visible in the thread.
	 *
	 * @param object $c    Conversation.
	 * @param callable $note Returns the translated text.
	 */
	private static function system_note( $c, callable $note ) {
		// Notes are stored once and shown to visitors too, so use the site language.
		$switched = switch_to_locale( get_locale() );
		$text     = $note();
		if ( $switched ) {
			restore_previous_locale();
		}
		HILO_DB::add_message( $c, 'system', get_current_user_id(), $text );
	}

	/**
	 * GET /agent/sync — single polling endpoint for the app.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function sync( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		$since   = absint( $request->get_param( 'since' ) );
		$open    = absint( $request->get_param( 'open' ) );
		$after   = absint( $request->get_param( 'after' ) );
		$visible = (bool) $request->get_param( 'visible' );

		HILO_Presence::heartbeat( $user_id, $visible );

		$out = array(
			'now'           => time(),
			'conversations' => HILO_DB::list_conversations( $since ),
			'full'          => 0 === $since,
			'deleted'       => $since ? HILO_DB::deleted_since( $since ) : array(),
		);

		if ( $open ) {
			$c = HILO_DB::get_conversation( $open );
			if ( $c ) {
				$out['open'] = array(
					'id'          => (int) $c->id,
					'messages'    => HILO_DB::get_messages( $c->id, $after ),
					'typing'      => (bool) HILO_Presence::get_typing( $c->id, 'visitor' ),
					'visitorSeen' => HILO_DB::ts( $c->visitor_last_seen ),
					'visitorRead' => (int) $c->visitor_last_read_id,
				);
				// Viewing the thread with the app visible marks it as read.
				if ( $visible && (int) $c->last_visitor_message_id > (int) $c->agent_last_read_id ) {
					HILO_DB::update_conversation( $c->id, array( 'agent_last_read_id' => (int) $c->last_message_id ), false );
				}
			}
		}

		// Agents + store status are cheap but don't need every tick.
		if ( 0 === $since || $request->get_param( 'meta' ) ) {
			$out['agents'] = HILO_Presence::agents_for_app();
			$out['store']  = HILO_REST::status();
			$out['me']     = array(
				'id'        => $user_id,
				'available' => HILO_Presence::is_available( $user_id ),
			);
		}

		return rest_ensure_response( $out );
	}

	/**
	 * GET /agent/conversation/{id} — full thread + customer context.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function conversation( WP_REST_Request $request ) {
		$c = self::load( $request['id'] );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$context = $c->context ? json_decode( $c->context, true ) : array();
		return rest_ensure_response(
			array(
				'conversation' => HILO_DB::format_conversation( $c ),
				'messages'     => HILO_DB::get_messages( $c->id ),
				'context'      => array(
					'page'      => $context['page'] ?? null,
					'pages'     => $context['pages'] ?? array(),
					'referrer'  => $context['referrer'] ?? '',
					'cart'      => $context['cart'] ?? null,
					'userAgent' => $c->user_agent,
					'startUrl'  => $c->page_url,
				),
				'customer'     => HILO_Woo_Context::customer( $c ),
			)
		);
	}

	/**
	 * POST /agent/message
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function message( WP_REST_Request $request ) {
		$c = self::load( $request->get_param( 'conversation' ) );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$user_id = get_current_user_id();

		// Replying to an unassigned chat takes it.
		if ( ! $c->assigned_agent_id ) {
			HILO_DB::update_conversation( $c->id, array( 'assigned_agent_id' => $user_id ), false );
			$c->assigned_agent_id = $user_id;
		}
		if ( 'closed' === $c->status ) {
			HILO_DB::update_conversation( $c->id, array( 'status' => 'open', 'closed_at' => null ), false );
			$c->status = 'open';
		}

		$body = sanitize_textarea_field( (string) $request->get_param( 'body' ) );
		$msg  = HILO_DB::add_message( $c, 'agent', $user_id, $body );
		if ( ! $msg ) {
			return new WP_Error( 'hilo_empty', __( 'The message is empty.', 'hilo-chat' ), array( 'status' => 400 ) );
		}
		HILO_Presence::clear_typing( $c->id, 'agent' );
		HILO_Mailer::maybe_schedule( $c );
		return rest_ensure_response( array( 'message' => $msg ) );
	}

	/**
	 * POST /agent/upload
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function upload( WP_REST_Request $request ) {
		$c = self::load( $request->get_param( 'conversation' ) );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'hilo_upload', __( 'No file was received.', 'hilo-chat' ), array( 'status' => 400 ) );
		}
		$att = HILO_Uploads::store( $files['file'] );
		if ( is_wp_error( $att ) ) {
			return $att;
		}
		$user_id = get_current_user_id();
		if ( ! $c->assigned_agent_id ) {
			HILO_DB::update_conversation( $c->id, array( 'assigned_agent_id' => $user_id ), false );
		}
		$msg = HILO_DB::add_message( $c, 'agent', $user_id, sanitize_textarea_field( (string) $request->get_param( 'body' ) ), $att );
		HILO_Mailer::maybe_schedule( $c );
		return rest_ensure_response( array( 'message' => $msg ) );
	}

	/**
	 * POST /agent/read
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function read( WP_REST_Request $request ) {
		$c = self::load( $request->get_param( 'conversation' ) );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		HILO_DB::update_conversation( $c->id, array( 'agent_last_read_id' => (int) $c->last_message_id ), false );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /agent/assign — take (agent = me) or transfer (agent = id) or release (agent = 0).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function assign( WP_REST_Request $request ) {
		$c = self::load( $request->get_param( 'conversation' ) );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		$me     = get_current_user_id();
		$target = $request->has_param( 'agent' ) ? absint( $request->get_param( 'agent' ) ) : $me;

		if ( $target && ! user_can( $target, HILO_CAP ) ) {
			return new WP_Error( 'hilo_bad_agent', __( 'That user is not an agent.', 'hilo-chat' ), array( 'status' => 400 ) );
		}
		if ( (int) $c->assigned_agent_id === $target ) {
			return rest_ensure_response( array( 'ok' => true ) );
		}

		HILO_DB::update_conversation( $c->id, array( 'assigned_agent_id' => $target ) );

		$me_name = HILO_Presence::agent_public( $me )['name'];
		if ( ! $target ) {
			self::system_note(
				$c,
				static function () use ( $me_name ) {
					/* translators: %s: agent name. */
					return sprintf( __( '%s released the conversation.', 'hilo-chat' ), $me_name );
				}
			);
		} elseif ( $target === $me ) {
			self::system_note(
				$c,
				static function () use ( $me_name ) {
					/* translators: %s: agent name. */
					return sprintf( __( '%s joined the conversation.', 'hilo-chat' ), $me_name );
				}
			);
		} else {
			$to = HILO_Presence::agent_public( $target )['name'];
			self::system_note(
				$c,
				static function () use ( $me_name, $to ) {
					/* translators: 1: agent name, 2: agent name. */
					return sprintf( __( '%1$s transferred the conversation to %2$s.', 'hilo-chat' ), $me_name, $to );
				}
			);
			HILO_Push::notify_transfer( $c, $target, $me );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /agent/close
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function close( WP_REST_Request $request ) {
		$c = self::load( $request->get_param( 'conversation' ) );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		HILO_DB::update_conversation(
			$c->id,
			array(
				'status'             => 'closed',
				'closed_at'          => HILO_DB::now(),
				'agent_last_read_id' => (int) $c->last_message_id,
			)
		);
		self::system_note(
			$c,
			static function () {
				return __( 'The conversation was marked as resolved.', 'hilo-chat' );
			}
		);
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /agent/reopen
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function reopen( WP_REST_Request $request ) {
		$c = self::load( $request->get_param( 'conversation' ) );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		HILO_DB::update_conversation( $c->id, array( 'status' => 'open', 'closed_at' => null ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /agent/delete — deletes one resolved conversation.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete( WP_REST_Request $request ) {
		$c = self::load( $request->get_param( 'conversation' ) );
		if ( is_wp_error( $c ) ) {
			return $c;
		}
		if ( 'closed' !== $c->status ) {
			return new WP_Error( 'hilo_not_resolved', __( 'Only resolved conversations can be deleted.', 'hilo-chat' ), array( 'status' => 400 ) );
		}
		HILO_DB::delete_conversations( array( (int) $c->id ) );
		return rest_ensure_response( array( 'deleted' => array( (int) $c->id ) ) );
	}

	/**
	 * POST /agent/delete-resolved — deletes every resolved conversation (store managers only).
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public static function delete_resolved() {
		if ( ! current_user_can( 'manage_woocommerce' ) && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'hilo_forbidden', __( 'Only store managers can delete all resolved conversations.', 'hilo-chat' ), array( 'status' => 403 ) );
		}
		return rest_ensure_response( array( 'count' => HILO_DB::delete_resolved() ) );
	}

	/**
	 * POST /agent/typing
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function typing( WP_REST_Request $request ) {
		$id = absint( $request->get_param( 'conversation' ) );
		if ( $id ) {
			HILO_Presence::set_typing( $id, 'agent', get_current_user_id() );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /agent/presence — availability toggle or "app hidden".
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function presence( WP_REST_Request $request ) {
		$user_id = get_current_user_id();
		if ( $request->has_param( 'available' ) ) {
			HILO_Presence::set_available( $user_id, (bool) $request->get_param( 'available' ) );
		}
		if ( $request->get_param( 'hidden' ) ) {
			HILO_Presence::went_hidden( $user_id );
		}
		return rest_ensure_response(
			array(
				'available' => HILO_Presence::is_available( $user_id ),
				'store'     => HILO_REST::status(),
			)
		);
	}

	/**
	 * GET|POST /agent/quick-replies
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function quick_replies( WP_REST_Request $request ) {
		if ( 'POST' === $request->get_method() ) {
			$data = array(
				'id'       => absint( $request->get_param( 'id' ) ),
				'shortcut' => (string) $request->get_param( 'shortcut' ),
				'title'    => (string) $request->get_param( 'title' ),
				'body'     => (string) $request->get_param( 'body' ),
			);
			if ( '' === trim( $data['body'] ) || '' === trim( $data['shortcut'] ) ) {
				return new WP_Error( 'hilo_invalid', __( 'Shortcut and text are required.', 'hilo-chat' ), array( 'status' => 400 ) );
			}
			HILO_DB::save_quick_reply( $data );
		}
		return rest_ensure_response( HILO_DB::quick_replies() );
	}

	/**
	 * DELETE /agent/quick-replies/{id}
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function delete_quick_reply( WP_REST_Request $request ) {
		HILO_DB::delete_quick_reply( $request['id'] );
		return rest_ensure_response( HILO_DB::quick_replies() );
	}

	/**
	 * POST /agent/push/subscribe
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function push_subscribe( WP_REST_Request $request ) {
		$keys = (array) $request->get_param( 'keys' );
		$ok   = HILO_Push::subscribe(
			get_current_user_id(),
			esc_url_raw( (string) $request->get_param( 'endpoint' ) ),
			isset( $keys['p256dh'] ) ? (string) $keys['p256dh'] : '',
			isset( $keys['auth'] ) ? (string) $keys['auth'] : ''
		);
		if ( ! $ok ) {
			return new WP_Error( 'hilo_push', __( 'This device could not be registered for notifications.', 'hilo-chat' ), array( 'status' => 400 ) );
		}
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /agent/push/unsubscribe
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function push_unsubscribe( WP_REST_Request $request ) {
		HILO_Push::unsubscribe( esc_url_raw( (string) $request->get_param( 'endpoint' ) ) );
		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * POST /agent/push/test — sends a test notification to my devices.
	 *
	 * @return WP_REST_Response
	 */
	public static function push_test() {
		HILO_Push::queue(
			array( get_current_user_id() ),
			static function () {
				return array(
					'title' => __( 'Notifications are on ✅', 'hilo-chat' ),
					'body'  => __( 'This is how we will let you know when a new message arrives.', 'hilo-chat' ),
					'tag'   => 'hilo-test',
					'url'   => HILO_Settings::app_url(),
				);
			}
		);
		return rest_ensure_response( array( 'ok' => true ) );
	}
}
