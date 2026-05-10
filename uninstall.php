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
$tsoliin_tables = array(
	$wpdb->prefix . 'tso_link_inspector',
	str_replace( 'inspector', 'checker', $wpdb->prefix . 'tso_link_inspector' ), // legacy table name.
);
foreach ( $tsoliin_tables as $tsoliin_table ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	$wpdb->query( 'DROP TABLE IF EXISTS `' . esc_sql( $tsoliin_table ) . '`' ); // $tsoliin_table built from $wpdb->prefix — trusted source.
}

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
	'tsoliin_comment_source_key_migrated',
	'tso_lc_version',
	'tso_lc_settings',
	'tso_lc_last_full_scan',
	'tso_lc_last_check_batch',
	'tso_lc_last_check_count',
	'tso_lc_bg_check_running',
	'tso_lc_bg_check_checked',
	'tso_lc_bg_check_total',
	'tso_lc_bg_check_started',
	'tso_lc_total_posts_scanned',
);
foreach ( $tsoliin_options as $tsoliin_option_name ) {
	delete_option( $tsoliin_option_name );
}

// ── Clear all scheduled cron events ─────────────────────────────────────
$tsoliin_hooks = array(
	'tsoliin_cron_scan',
	'tsoliin_cron_check',
	'tsoliin_bg_check_step',
	'tso_lc_cron_scan',
	'tso_lc_cron_check',
	'tso_lc_bg_check_step',
);
foreach ( $tsoliin_hooks as $tsoliin_hook_name ) {
	wp_clear_scheduled_hook( $tsoliin_hook_name );
}
