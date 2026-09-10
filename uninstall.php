<?php
/**
 * Uninstall cleanup for TSO Stack Inspector.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all TSO Stack Inspector data for the current site.
 *
 * @return void
 */
function tsosi_uninstall_cleanup_site() {
	wp_clear_scheduled_hook( 'tsosi_background_scan_tick' );

	delete_option( 'tso_stack_inspector_db_schema' );
	delete_option( 'tso_stack_inspector_scan_settings' );
	delete_option( 'tso_stack_inspector_scan_history' );
	delete_option( 'tso_stack_inspector_replace_backups' );
	delete_option( 'tso_stack_inspector_background_jobs' );
	delete_transient( 'tso_stack_inspector_scan_job' );
	delete_transient( 'tso_stack_inspector_plugin_profile' );

	global $wpdb;

	$tsosi_scan_job_like = '_transient_' . $wpdb->esc_like( 'tso_stack_inspector_scan_job_' ) . '%';
	$tsosi_timeout_like  = '_transient_timeout_' . $wpdb->esc_like( 'tso_stack_inspector_scan_job_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: remove per-user scan job transients.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$tsosi_scan_job_like,
			$tsosi_timeout_like
		)
	);

	$tsosi_index_job_like         = '_transient_' . $wpdb->esc_like( 'tso_stack_inspector_index_job_' ) . '%';
	$tsosi_index_job_timeout_like = '_transient_timeout_' . $wpdb->esc_like( 'tso_stack_inspector_index_job_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: remove per-user index job transients.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$tsosi_index_job_like,
			$tsosi_index_job_timeout_like
		)
	);

	$tsosi_cache_like         = '_transient_' . $wpdb->esc_like( 'tso_stack_inspector_content_cache_' ) . '%';
	$tsosi_cache_timeout_like = '_transient_timeout_' . $wpdb->esc_like( 'tso_stack_inspector_content_cache_' ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall: remove per-user content cache transients.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$tsosi_cache_like,
			$tsosi_cache_timeout_like
		)
	);

	// Remove UI language preference from all users in one call.
	delete_metadata( 'user', 0, 'tso_stack_inspector_ui_lang', '', true );

	$tsosi_uploads = wp_upload_dir();
	if ( empty( $tsosi_uploads['error'] ) && ! empty( $tsosi_uploads['basedir'] ) ) {
		$tsosi_cache_dir = trailingslashit( $tsosi_uploads['basedir'] ) . 'tso-stack-inspector';
		if ( is_dir( $tsosi_cache_dir ) ) {
			$tsosi_glob_sets = array(
				glob( $tsosi_cache_dir . '/cache-*.json' ),
				glob( $tsosi_cache_dir . '/history-*.json' ),
				glob( $tsosi_cache_dir . '/replace-backup-*.json' ),
			glob( $tsosi_cache_dir . '/job-lock-*.lock' ),
				glob( $tsosi_cache_dir . '/index.html' ),
				glob( $tsosi_cache_dir . '/index.php' ),
				glob( $tsosi_cache_dir . '/.htaccess' ),
				glob( $tsosi_cache_dir . '/web.config' ),
			);
			foreach ( $tsosi_glob_sets as $tsosi_cache_files ) {
				if ( ! is_array( $tsosi_cache_files ) ) {
					continue;
				}
				foreach ( $tsosi_cache_files as $tsosi_cache_file ) {
					if ( is_string( $tsosi_cache_file ) && is_file( $tsosi_cache_file ) ) {
						wp_delete_file( $tsosi_cache_file );
					}
				}
			}
			// Remove empty plugin uploads folder when possible.
			if ( is_dir( $tsosi_cache_dir ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir, WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup of an empty uploads slug dir; failure here is not critical.
				@rmdir( $tsosi_cache_dir );
			}
		}
	}
}

tsosi_uninstall_cleanup_site();

if ( is_multisite() ) {
	$tsosi_sites = get_sites( array( 'fields' => 'ids' ) );
	if ( is_array( $tsosi_sites ) ) {
		$tsosi_main_site_id = get_main_site_id();
		foreach ( $tsosi_sites as $tsosi_site_id ) {
			if ( (int) $tsosi_site_id === (int) $tsosi_main_site_id ) {
				continue;
			}
			switch_to_blog( (int) $tsosi_site_id );
			tsosi_uninstall_cleanup_site();
			restore_current_blog();
		}
	}
}
