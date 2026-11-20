<?php
/**
 * Removes all plugin data when the plugin is deleted from WordPress.
 *
 * @package HiloChat
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

foreach ( array( 'conversations', 'messages', 'push_subscriptions', 'quick_replies' ) as $table ) {
	$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}hilo_{$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
}

foreach ( array( 'hilo_settings', 'hilo_vapid', 'hilo_db_version', 'hilo_flush_rewrite', 'hilo_rewrite_slug' ) as $option ) {
	delete_option( $option );
}

$wpdb->query( "DELETE FROM {$wpdb->usermeta} WHERE meta_key IN ('hilo_last_seen','hilo_active','hilo_available')" );
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\\_transient\\_hilo\\_%' OR option_name LIKE '\\_transient\\_timeout\\_hilo\\_%'" );

remove_role( 'hilo_agent' );
foreach ( array( 'administrator', 'shop_manager' ) as $role_name ) {
	$role = get_role( $role_name );
	if ( $role ) {
		$role->remove_cap( 'hilo_answer_chats' );
	}
}

wp_clear_scheduled_hook( 'hilo_daily_maintenance' );
wp_unschedule_hook( 'hilo_send_reply_email' );

// Attachments live in uploads/hilo-chat and are intentionally kept (they may be referenced by emails).
