<?php
/**
 * Uninstall TSO Link Inspector.
 *
 * Called automatically by WordPress when the user deletes the plugin.
 * Removes ALL plugin data: database table, options, and scheduled cron events.
 *
 * @package TSOLIIN_Link_Inspector
 */

// Exit if not called by WordPress uninstaller.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

// ── Drop custom database table ──────────────────────────────────────────
$tsoliin_table = $wpdb->prefix . 'tso_link_inspector';
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $tsoliin_table ) . '`' );

// ── Delete all plugin options ────────────────────────────────────────────
$tsoliin_options = array(
	'tsoliin_version',
	'tsoliin_settings',
	'tsoliin_last_full_scan',
	'tsoliin_last_check_batch',
	'tsoliin_last_check_count',
	'tsoliin_bg_check_running',
	'tsoliin_bg_check_checked',
	'tsoliin_bg_check_total',
	'tsoliin_bg_check_started',
	'tsoliin_total_posts_scanned',
	'tsoliin_comment_scan_after_id',
	'tsoliin_broken_digest_last_sent',
	'tsoliin_immediate_broken_queue',
);
foreach ( $tsoliin_options as $tsoliin_option_name ) {
	delete_option( $tsoliin_option_name );
}

// ── Clear all scheduled cron events ─────────────────────────────────────
$tsoliin_hooks = array(
	'tsoliin_cron_scan',
	'tsoliin_cron_check',
	'tsoliin_bg_check_step',
);
foreach ( $tsoliin_hooks as $tsoliin_hook_name ) {
	wp_clear_scheduled_hook( $tsoliin_hook_name );
}
