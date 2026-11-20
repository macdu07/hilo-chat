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
		$args = array( (int) $conversation->id );
		if ( ! wp_next_scheduled( 'hilo_send_reply_email', $args ) ) {
			wp_schedule_single_event( time() + self::DELAY, 'hilo_send_reply_email', $args );
		}
	}

	/**
	 * Cron callback: sends unseen agent messages by email.
	 *
	 * @param int $conversation_id Conversation.
	 */
	public static function send_pending( $conversation_id ) {
		global $wpdb;
		$c = HILO_DB::get_conversation( $conversation_id );
		if ( ! $c || ! is_email( $c->email ) ) {
			return;
		}
		// Visitor is active in the widget: they will see it there.
		if ( HILO_DB::ts( $c->visitor_last_seen ) > time() - self::AWAY_SECONDS ) {
			return;
		}
		$from_id = max( (int) $c->visitor_last_read_id, (int) $c->last_emailed_id );
		$t       = HILO_DB::table( 'messages' );
		$rows    = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE conversation_id = %d AND sender_type = 'agent' AND id > %d ORDER BY id ASC LIMIT 30", $c->id, $from_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $rows ) {
			return;
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
		}
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
