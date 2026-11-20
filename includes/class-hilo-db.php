<?php
/**
 * Data repository.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Reads and writes conversations, messages and quick replies.
 */
class HILO_DB {

	const MAX_BODY = 2000;

	/**
	 * Table name helper.
	 *
	 * @param string $name Short name.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'hilo_' . $name;
	}

	/**
	 * Current UTC datetime for MySQL.
	 *
	 * @return string
	 */
	public static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	/**
	 * Converts a stored UTC datetime to a unix timestamp.
	 *
	 * @param string|null $datetime Datetime.
	 * @return int
	 */
	public static function ts( $datetime ) {
		return $datetime ? (int) strtotime( $datetime . ' UTC' ) : 0;
	}

	/**
	 * Hashes a visitor token.
	 *
	 * @param string $token Raw token.
	 * @return string
	 */
	public static function hash_token( $token ) {
		return hash_hmac( 'sha256', (string) $token, wp_salt( 'auth' ) );
	}

	/* ---------------------------------------------------------------------
	 * Conversations
	 * ------------------------------------------------------------------- */

	/**
	 * Creates a conversation.
	 *
	 * @param array $data Columns.
	 * @return object|null
	 */
	public static function create_conversation( array $data ) {
		global $wpdb;
		$now  = self::now();
		$data = array_merge(
			array(
				'public_id'  => bin2hex( random_bytes( 16 ) ),
				'status'     => 'open',
				'created_at' => $now,
				'updated_at' => $now,
			),
			$data
		);
		$wpdb->insert( self::table( 'conversations' ), $data );
		return $wpdb->insert_id ? self::get_conversation( $wpdb->insert_id ) : null;
	}

	/**
	 * Gets a conversation by ID.
	 *
	 * @param int $id ID.
	 * @return object|null
	 */
	public static function get_conversation( $id ) {
		global $wpdb;
		$t = self::table( 'conversations' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Gets a conversation by public ID.
	 *
	 * @param string $public_id Public ID.
	 * @return object|null
	 */
	public static function get_conversation_by_public_id( $public_id ) {
		global $wpdb;
		$t = self::table( 'conversations' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE public_id = %s", $public_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Gets a conversation by raw visitor token.
	 *
	 * @param string $token Token.
	 * @return object|null
	 */
	public static function get_conversation_by_token( $token ) {
		global $wpdb;
		if ( ! is_string( $token ) || strlen( $token ) < 32 ) {
			return null;
		}
		$t = self::table( 'conversations' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE token_hash = %s ORDER BY id DESC LIMIT 1", self::hash_token( $token ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Latest non-closed conversation for a registered user.
	 *
	 * @param int $user_id User.
	 * @return object|null
	 */
	public static function get_open_conversation_for_user( $user_id ) {
		global $wpdb;
		$t = self::table( 'conversations' );
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d AND status <> 'closed' ORDER BY id DESC LIMIT 1", $user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	/**
	 * Updates a conversation.
	 *
	 * @param int   $id     ID.
	 * @param array $data   Columns.
	 * @param bool  $touch  Whether to bump updated_at.
	 */
	public static function update_conversation( $id, array $data, $touch = true ) {
		global $wpdb;
		if ( $touch ) {
			$data['updated_at'] = self::now();
		}
		$wpdb->update( self::table( 'conversations' ), $data, array( 'id' => (int) $id ) );
	}

	/**
	 * Lists conversations for the agent inbox.
	 *
	 * @param int $since Unix timestamp; 0 = initial load.
	 * @return array
	 */
	public static function list_conversations( $since = 0 ) {
		global $wpdb;
		$t = self::table( 'conversations' );
		$m = self::table( 'messages' );

		$select = "SELECT c.*, (SELECT COUNT(*) FROM {$m} mm WHERE mm.conversation_id = c.id AND mm.sender_type = 'visitor' AND mm.id > c.agent_last_read_id) AS unread,
			(SELECT body FROM {$m} lm WHERE lm.id = c.last_message_id) AS last_body,
			(SELECT sender_type FROM {$m} lm2 WHERE lm2.id = c.last_message_id) AS last_sender,
			(SELECT attachment IS NOT NULL FROM {$m} lm3 WHERE lm3.id = c.last_message_id) AS last_has_attachment
			FROM {$t} c";

		if ( $since > 0 ) {
			// Overlap a few seconds so nothing written in the same second is missed.
			$from = gmdate( 'Y-m-d H:i:s', $since - 5 );
			$rows = $wpdb->get_results( $wpdb->prepare( "{$select} WHERE c.updated_at >= %s AND c.last_message_id > 0 ORDER BY c.updated_at DESC LIMIT 100", $from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$closed_from = gmdate( 'Y-m-d H:i:s', time() - 14 * DAY_IN_SECONDS );
			$rows        = $wpdb->get_results( $wpdb->prepare( "({$select} WHERE c.status <> 'closed' AND c.last_message_id > 0 ORDER BY c.updated_at DESC LIMIT 200) UNION ALL ({$select} WHERE c.status = 'closed' AND c.updated_at >= %s ORDER BY c.updated_at DESC LIMIT 60)", $closed_from ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		return array_map( array( __CLASS__, 'format_conversation' ), $rows ? $rows : array() );
	}

	/**
	 * Formats a conversation for the agent app.
	 *
	 * @param object $c Row.
	 * @return array
	 */
	public static function format_conversation( $c ) {
		$context = $c->context ? json_decode( $c->context, true ) : array();
		$last    = isset( $c->last_body ) ? (string) $c->last_body : '';
		if ( '' === $last && ! empty( $c->last_has_attachment ) ) {
			$last = '📷 Imagen';
		}
		return array(
			'id'             => (int) $c->id,
			'name'           => $c->name ? $c->name : 'Visitante',
			'email'          => $c->email,
			'userId'         => (int) $c->user_id,
			'status'         => $c->status,
			'assignedTo'     => (int) $c->assigned_agent_id,
			'lastMessageId'  => (int) $c->last_message_id,
			'lastBody'       => wp_html_excerpt( $last, 120, '…' ),
			'lastSender'     => isset( $c->last_sender ) ? $c->last_sender : '',
			'unread'         => isset( $c->unread ) ? (int) $c->unread : 0,
			'visitorReadId'  => (int) $c->visitor_last_read_id,
			'visitorSeen'    => self::ts( $c->visitor_last_seen ),
			'page'           => isset( $context['page'] ) ? $context['page'] : null,
			'createdAt'      => self::ts( $c->created_at ),
			'updatedAt'      => self::ts( $c->updated_at ),
		);
	}

	/* ---------------------------------------------------------------------
	 * Messages
	 * ------------------------------------------------------------------- */

	/**
	 * Adds a message and updates conversation pointers.
	 *
	 * @param object     $conversation Conversation row.
	 * @param string     $sender_type  visitor|agent|system.
	 * @param int        $sender_id    User ID for agents.
	 * @param string     $body         Text.
	 * @param array|null $attachment   Attachment data.
	 * @return array|null Formatted message.
	 */
	public static function add_message( $conversation, $sender_type, $sender_id, $body, $attachment = null ) {
		global $wpdb;
		$body = trim( (string) $body );
		if ( mb_strlen( $body ) > self::MAX_BODY ) {
			$body = mb_substr( $body, 0, self::MAX_BODY );
		}
		if ( '' === $body && empty( $attachment ) ) {
			return null;
		}

		$now = self::now();
		$wpdb->insert(
			self::table( 'messages' ),
			array(
				'conversation_id' => (int) $conversation->id,
				'sender_type'     => $sender_type,
				'sender_id'       => (int) $sender_id,
				'body'            => $body,
				'attachment'      => $attachment ? wp_json_encode( $attachment ) : null,
				'created_at'      => $now,
			)
		);
		$id = (int) $wpdb->insert_id;
		if ( ! $id ) {
			return null;
		}

		$update = array( 'last_message_id' => $id );
		if ( 'visitor' === $sender_type ) {
			$update['last_visitor_message_id'] = $id;
			$update['visitor_last_read_id']    = $id;
			$update['visitor_last_seen']       = $now;
			if ( 'closed' === $conversation->status ) {
				$update['status']    = 'open';
				$update['closed_at'] = null;
			}
		} elseif ( 'agent' === $sender_type ) {
			$update['agent_last_read_id'] = $id;
		}
		self::update_conversation( $conversation->id, $update );

		return self::format_message(
			(object) array(
				'id'          => $id,
				'sender_type' => $sender_type,
				'sender_id'   => $sender_id,
				'body'        => $body,
				'attachment'  => $attachment ? wp_json_encode( $attachment ) : null,
				'created_at'  => $now,
			)
		);
	}

	/**
	 * Gets messages after an ID.
	 *
	 * @param int $conversation_id Conversation.
	 * @param int $after_id        Cursor.
	 * @param int $limit           Max rows.
	 * @return array
	 */
	public static function get_messages( $conversation_id, $after_id = 0, $limit = 300 ) {
		global $wpdb;
		$t = self::table( 'messages' );
		if ( $after_id > 0 ) {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE conversation_id = %d AND id > %d ORDER BY id ASC LIMIT %d", $conversation_id, $after_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM (SELECT * FROM {$t} WHERE conversation_id = %d ORDER BY id DESC LIMIT %d) x ORDER BY id ASC", $conversation_id, $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		return array_map( array( __CLASS__, 'format_message' ), $rows ? $rows : array() );
	}

	/**
	 * Formats a message row.
	 *
	 * @param object $m Row.
	 * @return array
	 */
	public static function format_message( $m ) {
		$out = array(
			'id'     => (int) $m->id,
			'from'   => $m->sender_type,
			'body'   => (string) $m->body,
			'at'     => self::ts( $m->created_at ),
		);
		if ( 'agent' === $m->sender_type ) {
			$out['agent'] = HILO_Presence::agent_public( (int) $m->sender_id );
		}
		if ( ! empty( $m->attachment ) ) {
			$att = json_decode( $m->attachment, true );
			if ( is_array( $att ) ) {
				$out['attachment'] = $att;
			}
		}
		return $out;
	}

	/* ---------------------------------------------------------------------
	 * Quick replies
	 * ------------------------------------------------------------------- */

	/**
	 * Lists quick replies.
	 *
	 * @return array
	 */
	public static function quick_replies() {
		global $wpdb;
		$t    = self::table( 'quick_replies' );
		$rows = $wpdb->get_results( "SELECT id, shortcut, title, body FROM {$t} ORDER BY shortcut ASC" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return array_map(
			static function ( $r ) {
				return array(
					'id'       => (int) $r->id,
					'shortcut' => $r->shortcut,
					'title'    => $r->title,
					'body'     => $r->body,
				);
			},
			$rows ? $rows : array()
		);
	}

	/**
	 * Inserts or updates a quick reply.
	 *
	 * @param array $data Data with optional id.
	 * @return int
	 */
	public static function save_quick_reply( array $data ) {
		global $wpdb;
		$t   = self::table( 'quick_replies' );
		$row = array(
			'shortcut' => sanitize_title( $data['shortcut'] ),
			'title'    => sanitize_text_field( $data['title'] ),
			'body'     => sanitize_textarea_field( $data['body'] ),
		);
		if ( ! empty( $data['id'] ) ) {
			$wpdb->update( $t, $row, array( 'id' => (int) $data['id'] ) );
			return (int) $data['id'];
		}
		$row['created_at'] = self::now();
		$wpdb->insert( $t, $row );
		return (int) $wpdb->insert_id;
	}

	/**
	 * Deletes a quick reply.
	 *
	 * @param int $id ID.
	 */
	public static function delete_quick_reply( $id ) {
		global $wpdb;
		$wpdb->delete( self::table( 'quick_replies' ), array( 'id' => (int) $id ) );
	}

	/* ---------------------------------------------------------------------
	 * Maintenance
	 * ------------------------------------------------------------------- */

	/**
	 * Deletes closed conversations older than the retention window.
	 *
	 * @param int $days Days.
	 */
	public static function purge( $days ) {
		global $wpdb;
		$days = (int) $days;
		if ( $days < 7 ) {
			return;
		}
		$t     = self::table( 'conversations' );
		$m     = self::table( 'messages' );
		$limit = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT id FROM {$t} WHERE status = 'closed' AND updated_at < %s LIMIT 500", $limit ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $ids ) {
			return;
		}
		$ids = array_map( 'intval', $ids );
		$in  = implode( ',', $ids );
		// Remove attachment files.
		$atts = $wpdb->get_col( "SELECT attachment FROM {$m} WHERE conversation_id IN ({$in}) AND attachment IS NOT NULL" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		foreach ( $atts as $json ) {
			$att = json_decode( $json, true );
			if ( ! empty( $att['file'] ) ) {
				HILO_Uploads::delete_file( $att['file'] );
			}
		}
		$wpdb->query( "DELETE FROM {$m} WHERE conversation_id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$wpdb->query( "DELETE FROM {$t} WHERE id IN ({$in})" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}
