<?php
/**
 * Background scan processing via WP-Cron (admin tab can be closed).
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOSI_CRON_SCAN_HOOK' ) ) {
	define( 'TSOSI_CRON_SCAN_HOOK', 'tsosi_background_scan_tick' );
}

if ( ! defined( 'TSOSI_OPTION_BACKGROUND_JOBS' ) ) {
	define( 'TSOSI_OPTION_BACKGROUND_JOBS', 'tso_stack_inspector_background_jobs' );
}

/**
 * @return array<int,int> user_id => timestamp scheduled.
 */
function tsosi_background_get_jobs() {
	$jobs = get_option( TSOSI_OPTION_BACKGROUND_JOBS, array() );
	return is_array( $jobs ) ? $jobs : array();
}

/**
 * @param int $user_id User ID.
 * @return void
 */
function tsosi_background_register_job( $user_id ) {
	$user_id = absint( $user_id );
	if ( $user_id <= 0 ) {
		return;
	}
	$lock             = tsosi_acquire_lock( 'background_jobs' );
	$jobs             = tsosi_background_get_jobs();
	$jobs[ $user_id ] = time();
	update_option( TSOSI_OPTION_BACKGROUND_JOBS, $jobs, false );
	tsosi_release_lock( $lock );
	if ( ! wp_next_scheduled( TSOSI_CRON_SCAN_HOOK ) ) {
		wp_schedule_event( time() + 30, 'tsosi_every_minute', TSOSI_CRON_SCAN_HOOK );
	}
}

/**
 * @param int $user_id User ID.
 * @return void
 */
function tsosi_background_unregister_job( $user_id ) {
	$user_id = absint( $user_id );
	$lock    = tsosi_acquire_lock( 'background_jobs' );
	$jobs    = tsosi_background_get_jobs();
	if ( isset( $jobs[ $user_id ] ) ) {
		unset( $jobs[ $user_id ] );
		update_option( TSOSI_OPTION_BACKGROUND_JOBS, $jobs, false );
	}
	tsosi_release_lock( $lock );
	if ( empty( $jobs ) ) {
		wp_clear_scheduled_hook( TSOSI_CRON_SCAN_HOOK );
	}
}

/**
 * Custom cron schedule.
 *
 * @param array<string,array<string,int|string>> $schedules Schedules.
 * @return array<string,array<string,int|string>>
 */
function tsosi_background_cron_schedules( $schedules ) {
	$schedules['tsosi_every_minute'] = array(
		'interval' => 60,
		'display'  => 'TSO Stack Inspector (every minute)',
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'tsosi_background_cron_schedules' );

/**
 * Process one batch per registered background user job.
 *
 * @return void
 */
function tsosi_background_cron_runner() {
	$jobs = tsosi_background_get_jobs();
	if ( empty( $jobs ) ) {
		wp_clear_scheduled_hook( TSOSI_CRON_SCAN_HOOK );
		return;
	}

	foreach ( array_keys( $jobs ) as $user_id ) {
		$user_id = absint( $user_id );
		if ( $user_id <= 0 ) {
			continue;
		}
		$key = TSOSI_TRANSIENT_SCAN_JOB_PREFIX . $user_id;
		$job = get_transient( $key );
		if ( ! is_array( $job ) ) {
			tsosi_background_unregister_job( $user_id );
			continue;
		}

		$prev_user = get_current_user_id();
		wp_set_current_user( $user_id );

		$result = tsosi_scan_job_step();
		if ( is_wp_error( $result ) ) {
			tsosi_background_unregister_job( $user_id );
			wp_set_current_user( $prev_user );
			continue;
		}

		if ( ! empty( $result['done'] ) ) {
			tsosi_background_unregister_job( $user_id );
			set_transient(
				TSOSI_TRANSIENT_SCAN_JOB_PREFIX . $user_id . '_done',
				$result,
				HOUR_IN_SECONDS
			);
		}

		wp_set_current_user( $prev_user );
	}
}
add_action( TSOSI_CRON_SCAN_HOOK, 'tsosi_background_cron_runner' );

/**
 * Read completed background result for the current user.
 *
 * @return array<string,mixed>|null
 */
function tsosi_background_get_completed_result() {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return null;
	}
	$key    = TSOSI_TRANSIENT_SCAN_JOB_PREFIX . $user_id . '_done';
	$result = get_transient( $key );
	if ( ! is_array( $result ) ) {
		return null;
	}
	delete_transient( $key );
	return $result;
}

/**
 * Poll in-progress background job state without advancing it.
 *
 * @return array<string,mixed>|null
 */
function tsosi_background_get_progress() {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return null;
	}
	$job = get_transient( TSOSI_TRANSIENT_SCAN_JOB_PREFIX . $user_id );
	if ( ! is_array( $job ) ) {
		return null;
	}
	$post_ids    = isset( $job['post_ids'] ) && is_array( $job['post_ids'] ) ? $job['post_ids'] : array();
	$extras      = isset( $job['extras'] ) && is_array( $job['extras'] ) ? $job['extras'] : array();
	$offset      = isset( $job['post_offset'] ) ? (int) $job['post_offset'] : 0;
	$extra_index = isset( $job['extra_index'] ) ? (int) $job['extra_index'] : 0;
	$total       = count( $post_ids ) + count( $extras );
	$current     = min( $offset, count( $post_ids ) ) + min( $extra_index, count( $extras ) );

	return array(
		'background'      => true,
		'done'            => false,
		'progress'        => $total > 0 ? min( 100, (int) round( ( $current / $total ) * 100 ) ) : 0,
		'processed_steps' => $current,
		'total_steps'     => $total,
	);
}
