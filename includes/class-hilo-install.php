<?php
/**
 * Activation, schema and upgrades.
 *
 * @package HiloChat
 */

defined( 'ABSPATH' ) || exit;

/**
 * Handles installation tasks.
 */
class HILO_Install {

	/**
	 * Runs on plugin activation.
	 */
	public static function activate() {
		self::install();
		update_option( 'hilo_flush_rewrite', 1, false );
	}

	/**
	 * Runs on plugin deactivation.
	 */
	public static function deactivate() {
		wp_clear_scheduled_hook( 'hilo_daily_maintenance' );
		wp_unschedule_hook( 'hilo_send_reply_email' );
		flush_rewrite_rules( false );
	}

	/**
	 * Creates or upgrades tables, roles and keys when needed.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'hilo_db_version' ) !== HILO_DB_VERSION ) {
			self::install();
		}
	}

	/**
	 * Full install routine (idempotent).
	 */
	public static function install() {
		self::create_tables();
		self::add_roles();
		self::ensure_vapid_keys();
		self::ensure_uploads_dir();

		if ( ! wp_next_scheduled( 'hilo_daily_maintenance' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'hilo_daily_maintenance' );
		}

		update_option( 'hilo_db_version', HILO_DB_VERSION, false );
	}

	/**
	 * Creates plugin tables with dbDelta.
	 */
	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$p       = $wpdb->prefix;

		$sql = "CREATE TABLE {$p}hilo_conversations (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  public_id char(32) NOT NULL,
  token_hash char(64) NOT NULL DEFAULT '',
  user_id bigint(20) unsigned NOT NULL DEFAULT 0,
  name varchar(120) NOT NULL DEFAULT '',
  email varchar(190) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'open',
  assigned_agent_id bigint(20) unsigned NOT NULL DEFAULT 0,
  page_url text NULL,
  user_agent varchar(255) NOT NULL DEFAULT '',
  last_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  last_visitor_message_id bigint(20) unsigned NOT NULL DEFAULT 0,
  agent_last_read_id bigint(20) unsigned NOT NULL DEFAULT 0,
  visitor_last_read_id bigint(20) unsigned NOT NULL DEFAULT 0,
  last_emailed_id bigint(20) unsigned NOT NULL DEFAULT 0,
  visitor_last_seen datetime NULL DEFAULT NULL,
  context longtext NULL,
  created_at datetime NOT NULL,
  updated_at datetime NOT NULL,
  closed_at datetime NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY public_id (public_id),
  KEY token_hash (token_hash),
  KEY status_updated (status,updated_at),
  KEY updated_at (updated_at),
  KEY user_id (user_id)
) $charset;
CREATE TABLE {$p}hilo_messages (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  conversation_id bigint(20) unsigned NOT NULL,
  sender_type varchar(10) NOT NULL DEFAULT 'visitor',
  sender_id bigint(20) unsigned NOT NULL DEFAULT 0,
  body text NULL,
  attachment longtext NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  KEY conversation_id (conversation_id,id)
) $charset;
CREATE TABLE {$p}hilo_push_subscriptions (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  user_id bigint(20) unsigned NOT NULL,
  endpoint_hash char(64) NOT NULL,
  endpoint text NOT NULL,
  p256dh varchar(255) NOT NULL,
  auth varchar(64) NOT NULL,
  user_agent varchar(255) NOT NULL DEFAULT '',
  created_at datetime NOT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY endpoint_hash (endpoint_hash),
  KEY user_id (user_id)
) $charset;
CREATE TABLE {$p}hilo_quick_replies (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  shortcut varchar(60) NOT NULL DEFAULT '',
  title varchar(120) NOT NULL DEFAULT '',
  body text NOT NULL,
  created_at datetime NOT NULL,
  PRIMARY KEY  (id)
) $charset;";

		dbDelta( $sql );

		// Seed a couple of quick replies the first time.
		$table = $p . 'hilo_quick_replies';
		if ( ! (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table}" ) ) { // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$now = current_time( 'mysql', true );
			$wpdb->insert( $table, array( 'shortcut' => _x( 'hi', 'quick reply shortcut', 'hilo-chat' ), 'title' => __( 'Greeting', 'hilo-chat' ), 'body' => __( 'Hi! Thanks for writing to us. How can I help you?', 'hilo-chat' ), 'created_at' => $now ) );
			$wpdb->insert( $table, array( 'shortcut' => _x( 'shipping', 'quick reply shortcut', 'hilo-chat' ), 'title' => __( 'Shipping times', 'hilo-chat' ), 'body' => __( 'Orders ship within 1 to 2 business days. As soon as yours ships, you will receive the tracking number by email.', 'hilo-chat' ), 'created_at' => $now ) );
			$wpdb->insert( $table, array( 'shortcut' => _x( 'thanks', 'quick reply shortcut', 'hilo-chat' ), 'title' => __( 'Goodbye', 'hilo-chat' ), 'body' => __( 'Thank you! If you need anything else, we are here. Have a lovely day 💛', 'hilo-chat' ), 'created_at' => $now ) );
		}
	}

	/**
	 * Registers the agent role and capability.
	 */
	private static function add_roles() {
		if ( ! get_role( 'hilo_agent' ) ) {
			add_role(
				'hilo_agent',
				'Chat agent',
				array(
					'read'   => true,
					HILO_CAP  => true,
				)
			);
		}
		foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
			$role = get_role( $role_name );
			if ( $role && ! $role->has_cap( HILO_CAP ) ) {
				$role->add_cap( HILO_CAP );
			}
		}
	}

	/**
	 * Generates the VAPID key pair used for Web Push.
	 */
	public static function ensure_vapid_keys() {
		$keys = get_option( 'hilo_vapid' );
		if ( is_array( $keys ) && ! empty( $keys['private_pem'] ) && ! empty( $keys['public'] ) ) {
			return;
		}

		$key = openssl_pkey_new(
			array(
				'curve_name'       => 'prime256v1',
				'private_key_type' => OPENSSL_KEYTYPE_EC,
			)
		);
		if ( ! $key ) {
			return;
		}
		openssl_pkey_export( $key, $pem );
		$details = openssl_pkey_get_details( $key );
		$public  = "\x04" . str_pad( $details['ec']['x'], 32, "\0", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\0", STR_PAD_LEFT );

		update_option(
			'hilo_vapid',
			array(
				'private_pem' => $pem,
				'public'      => rtrim( strtr( base64_encode( $public ), '+/', '-_' ), '=' ),
			),
			false
		);
	}

	/**
	 * Creates the protected uploads directory.
	 */
	private static function ensure_uploads_dir() {
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . 'hilo-chat';
		if ( wp_mkdir_p( $dir ) && ! file_exists( $dir . '/index.html' ) ) {
			file_put_contents( $dir . '/index.html', '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
	}
}
