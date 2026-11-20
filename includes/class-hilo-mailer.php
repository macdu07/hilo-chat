<?php
/**
 * Offline email replies.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Emails agent replies to visitors who already left.
 */
class HILO_Mailer {

	const DELAY        = 120;
	const AWAY_SECONDS = 60;
	const QUEUE        = 'hilo_mail_queue';
	const LOCK         = 'hilo_mail_lock';
	const GIVE_UP      = 6 * HOUR_IN_SECONDS;

	/**
	 * Whether the queue will be processed at the end of this request.
	 *
	 * @var bool|null Null = not requested, false = normal, true = force.
	 */
	private static $process = null;

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'hilo_send_reply_email', array( __CLASS__, 'send_pending' ) );
		add_action( 'template_redirect', array( __CLASS__, 'handle_resume_link' ), 1 );
	}

	/**
	 * Schedules an email after an agent reply if the visitor is away.
	 *
	 * @param object $conversation Conversation row (before the new message).
	 */
	public static function maybe_schedule( $conversation ) {
		if ( ! is_email( $conversation->email ) ) {
			return;
		}
		$id    = (int) $conversation->id;
		$queue = self::queue();
		if ( ! isset( $queue[ $id ] ) ) {
			$queue[ $id ] = time() + self::DELAY;
			self::save_queue( $queue );
		}
		// WP-Cron is only a backup: the queue is also drained by the plugin's own traffic (see process_soon()).
		$args = array( $id );
		if ( ! wp_next_scheduled( 'hilo_send_reply_email', $args ) ) {
			wp_schedule_single_event( time() + self::DELAY, 'hilo_send_reply_email', $args );
		}
	}

	/**
	 * Pending emails: conversation ID => unix time when it is due.
	 *
	 * @return array
	 */
	private static function queue() {
		$queue = get_option( self::QUEUE, array() );
		return is_array( $queue ) ? $queue : array();
	}

	/**
	 * Saves the queue (autoloaded, so checking it costs no query).
	 *
	 * @param array $queue Queue.
	 */
	private static function save_queue( array $queue ) {
		if ( $queue ) {
			update_option( self::QUEUE, $queue, true );
		} else {
			delete_option( self::QUEUE );
		}
	}

	/**
	 * Drains the queue at the end of this request, after the response is sent.
	 *
	 * Called from the chat's own REST traffic (agent app sync, visitor polling),
	 * so replies go out on time on any host, even without a server cron or with
	 * DISABLE_WP_CRON.
	 *
	 * @param bool $force Send every queued email now (e.g. the agent closed the app).
	 */
	public static function process_soon( $force = false ) {
		if ( ! self::queue() ) {
			return;
		}
		if ( null === self::$process ) {
			add_action( 'shutdown', array( __CLASS__, 'process_on_shutdown' ), 10000 );
		}
		self::$process = $force || (bool) self::$process;
	}

	/**
	 * Shutdown callback.
	 */
	public static function process_on_shutdown() {
		if ( function_exists( 'fastcgi_finish_request' ) ) {
			fastcgi_finish_request();
		}
		self::process_queue( (bool) self::$process );
	}

	/**
	 * Sends due emails. Uses a lock so parallel requests never send twice.
	 *
	 * @param bool $force Ignore due times.
	 */
	public static function process_queue( $force = false ) {
		$now   = time();
		$queue = self::queue();
		$due   = array_filter(
			$queue,
			static function ( $at ) use ( $force, $now ) {
				return $force || (int) $at <= $now;
			}
		);
		if ( ! $due ) {
			return;
		}
		// Atomic lock: add_option fails if another request holds it.
		if ( ! add_option( self::LOCK, $now, '', false ) ) {
			if ( $now - (int) get_option( self::LOCK ) < 60 ) {
				return;
			}
			update_option( self::LOCK, $now, false ); // Stale lock from a crashed request.
		}
		foreach ( array_keys( $due ) as $id ) {
			self::send_pending( (int) $id );
		}
		delete_option( self::LOCK );
	}

	/**
	 * Sends unseen agent messages by email and updates the queue.
	 * Also the WP-Cron callback.
	 *
	 * @param int $conversation_id Conversation.
	 */
	public static function send_pending( $conversation_id ) {
		$conversation_id = (int) $conversation_id;
		$result          = self::deliver( $conversation_id );
		$queue           = self::queue();
		if ( 'retry' === $result ) {
			$queue[ $conversation_id ] = time() + self::DELAY;
		} else {
			unset( $queue[ $conversation_id ] );
		}
		self::save_queue( $queue );
	}

	/**
	 * Emails the agent messages the visitor has not seen.
	 *
	 * @param int $conversation_id Conversation.
	 * @return string sent | done | retry
	 */
	private static function deliver( $conversation_id ) {
		global $wpdb;
		$c = HILO_DB::get_conversation( $conversation_id );
		if ( ! $c || ! is_email( $c->email ) ) {
			return 'done';
		}
		// Visitor is still on the store: they will see it in the widget. Check again later.
		if ( HILO_DB::ts( $c->visitor_last_seen ) > time() - self::AWAY_SECONDS ) {
			return HILO_DB::ts( $c->updated_at ) > time() - self::GIVE_UP ? 'retry' : 'done';
		}
		$from_id = max( (int) $c->visitor_last_read_id, (int) $c->last_emailed_id );
		$t       = HILO_DB::table( 'messages' );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE conversation_id = %d AND sender_type = 'agent' AND id > %d ORDER BY id ASC LIMIT 30", $c->id, $from_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			return 'done';
		}

		$messages = array_map( array( 'HILO_DB', 'format_message' ), $rows );
		$last     = end( $messages );
		$agent    = isset( $last['agent'] ) ? $last['agent']['name'] : HILO_Settings::team_name();
		$link     = self::resume_url( $c );
		$name     = $c->name ? $c->name : '';

		ob_start();
		include HILO_PLUGIN_PATH . 'templates/email-reply.php';
		$html = ob_get_clean();

		/* translators: 1: agent name, 2: store name. */
		$subject = sprintf( __( '%1$s replied to you at %2$s', 'hilo-chat' ), $agent, HILO_Settings::brand() );
		$sent    = wp_mail( $c->email, $subject, $html, array( 'Content-Type: text/html; charset=UTF-8' ) );
		if ( $sent ) {
			HILO_DB::update_conversation( $c->id, array( 'last_emailed_id' => (int) $last['id'] ), false );
			return 'sent';
		}
		return 'done';
	}

	/**
	 * Signed URL that restores the conversation in the widget.
	 *
	 * @param object $c Conversation.
	 * @return string
	 */
	public static function resume_url( $c ) {
		$sig = substr( hash_hmac( 'sha256', 'resume|' . $c->public_id, wp_salt( 'auth' ) ), 0, 32 );
		return add_query_arg( 'hilo_resume', $c->public_id . '.' . $sig, home_url( '/' ) );
	}

	/**
	 * Handles ?hilo_resume=… by issuing a new visitor token for that conversation.
	 */
	public static function handle_resume_link() {
		if ( empty( $_GET['hilo_resume'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		$raw   = sanitize_text_field( wp_unslash( $_GET['hilo_resume'] ) ); // phpcs:ignore WordPress.Security.NonceVerification
		$parts = explode( '.', $raw );
		if ( 2 === count( $parts ) ) {
			$expected = substr( hash_hmac( 'sha256', 'resume|' . $parts[0], wp_salt( 'auth' ) ), 0, 32 );
			$c        = hash_equals( $expected, $parts[1] ) ? HILO_DB::get_conversation_by_public_id( $parts[0] ) : null;
			if ( $c ) {
				$token = HILO_REST::issue_token();
				HILO_DB::update_conversation( $c->id, array( 'token_hash' => HILO_DB::hash_token( $token ) ), false );
				HILO_REST::set_token_cookie( $token );
			}
		}
		wp_safe_redirect( remove_query_arg( 'hilo_resume' ) . '#hilo-open' );
		exit;
	}
}
