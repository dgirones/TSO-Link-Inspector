<?php
/**
 * Automatic check schedule helpers (throughput, queue stats).
 *
 * @package TSOLIIN_Link_Inspector
 * @since   2.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Schedule
 */
class TSOLIIN_Schedule {

	/**
	 * Cron-related settings with defaults.
	 *
	 * @return array{
	 *   recheck_days: int,
	 *   broken_recheck_days: int,
	 *   cron_check_batch: int
	 * }
	 */
	public static function get_settings() {
		$s = get_option( 'tsoliin_settings', array() );
		return array(
			'recheck_days'        => isset( $s['recheck_days'] ) ? max( 1, min( 365, absint( $s['recheck_days'] ) ) ) : 7,
			'broken_recheck_days' => isset( $s['broken_recheck_days'] ) ? max( 1, min( 90, absint( $s['broken_recheck_days'] ) ) ) : 7,
			'cron_check_batch'    => isset( $s['cron_check_batch'] ) ? max( 5, min( 100, absint( $s['cron_check_batch'] ) ) ) : 20,
		);
	}

	/**
	 * Checks per hour/day based on hourly WP-Cron and batch size.
	 *
	 * @return array{ per_hour: int, per_day: int }
	 */
	public static function get_throughput() {
		$batch    = self::get_settings()['cron_check_batch'];
		$per_hour = $batch;
		return array(
			'per_hour' => $per_hour,
			'per_day'  => $per_hour * 24,
		);
	}

	/**
	 * Queue + cycle estimates for the admin UI.
	 *
	 * @param TSOLIIN_DB $db Database handler.
	 * @return array<string, int|float>
	 */
	public static function get_queue_stats( TSOLIIN_DB $db ) {
		$settings = self::get_settings();
		$counts   = $db->get_cron_queue_counts( $settings['recheck_days'], $settings['broken_recheck_days'] );
		$pending  = (int) $counts['total'];
		$per_day  = (int) self::get_throughput()['per_day'];
		$est_days = ( $pending > 0 && $per_day > 0 ) ? ceil( $pending / $per_day ) : 0;

		return array_merge(
			$counts,
			array(
				'pending'       => $pending,
				'checks_per_day'=> $per_day,
				'est_days'      => $est_days,
			)
		);
	}
}
