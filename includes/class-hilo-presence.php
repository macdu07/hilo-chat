<?php
/**
 * Agent presence and typing indicators.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracks which agents are online and who is typing.
 */
class HILO_Presence {

	const ONLINE_WINDOW = 90;
	const VIEW_WINDOW   = 12;
	const TYPING_TTL    = 6;

	/**
	 * Agent public profile cache.
	 *
	 * @var array
	 */
	private static $profiles = array();

	/**
	 * Records an agent heartbeat.
	 *
	 * @param int  $user_id User.
	 * @param bool $visible Whether the app is in the foreground.
	 */
	public static function heartbeat( $user_id, $visible ) {
		$now  = time();
		$last = (int) get_user_meta( $user_id, 'hilo_last_seen', true );
		if ( $now - $last >= 20 ) {
			update_user_meta( $user_id, 'hilo_last_seen', $now );
		}
		if ( $visible ) {
			$active = (int) get_user_meta( $user_id, 'hilo_active', true );
			if ( $now - $active >= 6 ) {
				update_user_meta( $user_id, 'hilo_active', $now );
			}
		}
	}

	/**
	 * Marks an agent as no longer looking at the app.
	 *
	 * @param int $user_id User.
	 */
	public static function went_hidden( $user_id ) {
		update_user_meta( $user_id, 'hilo_active', 0 );
	}

	/**
	 * Whether the agent toggled themselves as available.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_available( $user_id ) {
		return '0' !== get_user_meta( $user_id, 'hilo_available', true );
	}

	/**
	 * Sets availability.
	 *
	 * @param int  $user_id   User.
	 * @param bool $available Flag.
	 */
	public static function set_available( $user_id, $available ) {
		update_user_meta( $user_id, 'hilo_available', $available ? '1' : '0' );
	}

	/**
	 * Whether the agent has the app open in the foreground right now.
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	public static function is_viewing( $user_id ) {
		return time() - (int) get_user_meta( $user_id, 'hilo_active', true ) <= self::VIEW_WINDOW;
	}

	/**
	 * All users that can answer chats.
	 *
	 * @return WP_User[]
	 */
	public static function agents() {
		static $agents = null;
		if ( null === $agents ) {
			$agents = get_users(
				array(
					'capability' => HILO_CAP,
					'orderby'    => 'display_name',
					'number'     => 50,
				)
			);
		}
		return $agents;
	}

	/**
	 * Agents list for the app, with presence.
	 *
	 * @return array
	 */
	public static function agents_for_app() {
		$now = time();
		$out = array();
		foreach ( self::agents() as $user ) {
			$seen  = (int) get_user_meta( $user->ID, 'hilo_last_seen', true );
			$out[] = array_merge(
				self::agent_public( $user->ID ),
				array(
					'id'        => (int) $user->ID,
					'online'    => ( $now - $seen ) <= self::ONLINE_WINDOW,
					'available' => self::is_available( $user->ID ),
				)
			);
		}
		return $out;
	}

	/**
	 * Whether any available agent is online.
	 *
	 * @return bool
	 */
	public static function any_agent_online() {
		$now = time();
		foreach ( self::agents() as $user ) {
			if ( self::is_available( $user->ID ) && ( $now - (int) get_user_meta( $user->ID, 'hilo_last_seen', true ) ) <= self::ONLINE_WINDOW ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether the widget should show as "online".
	 *
	 * @return bool
	 */
	public static function store_is_online() {
		return HILO_Schedule::is_open_now() && self::any_agent_online();
	}

	/**
	 * Public profile of an agent (safe for visitors).
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function agent_public( $user_id ) {
		if ( ! isset( self::$profiles[ $user_id ] ) ) {
			$user = $user_id ? get_userdata( $user_id ) : false;
			$name = HILO_Settings::team_name();
			if ( $user ) {
				$name = $user->first_name ? $user->first_name : $user->display_name;
			}
			self::$profiles[ $user_id ] = array(
				'name'   => $name,
				'avatar' => $user ? get_avatar_url( $user_id, array( 'size' => 96 ) ) : '',
			);
		}
		return self::$profiles[ $user_id ];
	}

	/* ---------------------------------------------------------------------
	 * Typing
	 * ------------------------------------------------------------------- */

	/**
	 * Flags someone typing in a conversation.
	 *
	 * @param int    $conversation_id Conversation.
	 * @param string $side            visitor|agent.
	 * @param int    $agent_id        Agent typing (agent side).
	 */
	public static function set_typing( $conversation_id, $side, $agent_id = 0 ) {
		set_transient( "hilo_typing_{$side}_{$conversation_id}", array( 'at' => time(), 'agent' => (int) $agent_id ), self::TYPING_TTL );
	}

	/**
	 * Clears the typing flag (after sending a message).
	 *
	 * @param int    $conversation_id Conversation.
	 * @param string $side            visitor|agent.
	 */
	public static function clear_typing( $conversation_id, $side ) {
		delete_transient( "hilo_typing_{$side}_{$conversation_id}" );
	}

	/**
	 * Returns typing info or false.
	 *
	 * @param int    $conversation_id Conversation.
	 * @param string $side            visitor|agent.
	 * @return array|false
	 */
	public static function get_typing( $conversation_id, $side ) {
		$data = get_transient( "hilo_typing_{$side}_{$conversation_id}" );
		if ( ! is_array( $data ) || time() - (int) $data['at'] > self::TYPING_TTL ) {
			return false;
		}
		return $data;
	}
}
