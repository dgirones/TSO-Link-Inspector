<?php
/**
 * WP-Cron handler.
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Cron
 */
class TSOLIIN_Cron {

	const HOOK_SCAN         = 'tsoliin_cron_scan';
	const HOOK_CHECK        = 'tsoliin_cron_check';
	const HOOK_BG_STEP      = 'tsoliin_bg_check_step';
	const HOOK_BG_SCAN_STEP = 'tsoliin_bg_scan_step';
	const BG_BATCH            = 20;
	const BG_POLL_BATCH       = 5;
	const BG_POLL_TIME_BUDGET = 20;
	const BG_SCAN_TIME_BUDGET = 25;
	const OPT_IMMEDIATE_QUEUE = 'tsoliin_immediate_broken_queue';
	const OPT_EMPTY_BATCH_RETRIES = 'tsoliin_bg_check_empty_retries';
	const MAX_EMPTY_BATCH_RETRIES = 5;

	/** @var TSOLIIN_DB */
	private $db;

	/** @var TSOLIIN_Scanner */
	private $scanner;

	/** @var TSOLIIN_HTTP */
	private $http;

	public function __construct( TSOLIIN_DB $db, TSOLIIN_Scanner $scanner, TSOLIIN_HTTP $http ) {
		$this->db      = $db;
		$this->scanner = $scanner;
		$this->http    = $http;

		add_action( self::HOOK_SCAN,         array( $this, 'run_scan' ) );
		add_action( self::HOOK_CHECK,        array( $this, 'run_check_batch' ) );
		add_action( self::HOOK_BG_STEP,      array( $this, 'run_bg_step' ) );
		add_action( self::HOOK_BG_SCAN_STEP, array( $this, 'run_bg_scan_step' ) );
	}

	// -------------------------------------------------------------------------
	// Schedule management
	// -------------------------------------------------------------------------

	public function schedule() {
		if ( ! wp_next_scheduled( self::HOOK_SCAN ) ) {
			wp_schedule_event( time(), 'daily', self::HOOK_SCAN );
		}
		if ( ! wp_next_scheduled( self::HOOK_CHECK ) ) {
			wp_schedule_event( time() + 300, 'hourly', self::HOOK_CHECK );
		}
	}

	public function unschedule() {
		foreach ( array( self::HOOK_SCAN, self::HOOK_CHECK, self::HOOK_BG_STEP, self::HOOK_BG_SCAN_STEP ) as $hook ) {
			wp_clear_scheduled_hook( $hook );
		}
	}

	// -------------------------------------------------------------------------
	// Periodic handlers
	// -------------------------------------------------------------------------

	/** Daily: scan all posts, then continue with comment batches until time budget or full comment cycle. */
	public function run_scan() {
		$page  = 1;
		$start = microtime( true );
		$r     = array( 'done' => false );
		do {
			$r = $this->scanner->scan_batch( $page, TSOLIIN_BATCH_SIZE );
			$page++;
			if ( ( microtime( true ) - $start ) > 55 ) {
				break;
			}
		} while ( ! $r['done'] );

		if ( $this->scanner->is_scan_comments_enabled() ) {
			while ( ( microtime( true ) - $start ) < 55 ) {
				$n = $this->scanner->scan_comments_batch( self::BG_BATCH );
				if ( TSOLIIN_Scanner::SCAN_LOCK_BUSY === $n ) {
					break;
				}
				if ( 0 === $n ) {
					break;
				}
			}
		}

		if ( $this->scanner->is_scan_widgets_enabled() ) {
			$this->scanner->scan_all_widgets();
		}

		if ( $this->is_scan_setting_enabled( 'scan_meta', false ) ) {
			$this->scanner->scan_acf_options();
		}

		$this->drain_extended_scan_batches( $start );

		if ( ! empty( $r['done'] ) ) {
			update_option( 'tsoliin_last_full_scan', current_time( 'mysql', true ), false );
		}
	}

	/**
	 * Continue menu/term/FSE scan cursors during daily cron (matches UI scan_batch coverage).
	 *
	 * @param float $start microtime( true ) when run_scan() began.
	 * @return void
	 */
	private function drain_extended_scan_batches( $start ) {
		$batches = array();
		if ( $this->is_scan_setting_enabled( 'scan_menus', true ) ) {
			$batches[] = array( 'scan_menus_batch', self::BG_BATCH );
		}
		if ( $this->is_scan_setting_enabled( 'scan_terms', true ) ) {
			$batches[] = array( 'scan_terms_batch', self::BG_BATCH );
		}
		if ( $this->is_scan_setting_enabled( 'scan_fse', true ) ) {
			$batches[] = array( 'scan_fse_batch', max( 5, (int) floor( self::BG_BATCH / 2 ) ) );
		}
		foreach ( $batches as $batch ) {
			while ( ( microtime( true ) - $start ) < 55 ) {
				$n = call_user_func( array( $this->scanner, $batch[0] ), $batch[1] );
				if ( TSOLIIN_Scanner::SCAN_LOCK_BUSY === $n ) {
					break;
				}
				if ( 0 === $n ) {
					break;
				}
			}
		}
	}

	/**
	 * @param string $key     Settings key.
	 * @param bool   $default Default when unset.
	 * @return bool
	 */
	private function is_scan_setting_enabled( $key, $default = false ) {
		$s = get_option( 'tsoliin_settings', array() );
		if ( ! is_array( $s ) || ! array_key_exists( $key, $s ) ) {
			return (bool) $default;
		}
		return ! empty( $s[ $key ] );
	}

	/** Hourly: check a batch of stale links. */
	public function run_check_batch() {
		$schedule   = TSOLIIN_Schedule::get_settings();
		$batch      = $schedule['cron_check_batch'];
		$links      = $this->db->get_links_for_cron_check( $batch, $schedule['recheck_days'], $schedule['broken_recheck_days'] );

		if ( empty( $links ) ) {
			// No links needed checking. Do NOT update last_check_batch timestamp
			// so the user can distinguish "cron ran but nothing to check" from "cron checked links".
			$this->maybe_send_digest_broken_email();
			return;
		}

		$checked        = 0;
		$newly_detected = array();
		foreach ( $links as $link ) {
			$check = $this->check_link_row( $link );
			if ( null === $check ) {
				continue;
			}
			$item = $this->build_new_hard_broken_item( $check['link'], $check['result'], $check['prev_failures'] );
			if ( ! empty( $item ) ) {
				$newly_detected[] = $item;
			}
			$checked++;
		}

		// Only update timestamp when links were actually checked.
		if ( $checked > 0 ) {
			update_option( 'tsoliin_last_check_batch', current_time( 'mysql', true ), false );
		}
		update_option( 'tsoliin_last_check_count', $checked, false );
		$this->send_immediate_broken_summary_email( $newly_detected );
		$this->maybe_send_digest_broken_email();
	}

	// -------------------------------------------------------------------------
	// Background scan (server-side, browser-independent)
	// -------------------------------------------------------------------------

	/**
	 * Start or resume a full content scan.
	 *
	 * @param bool $resume When true, continue from the stored page cursor when the last run did not finish.
	 */
	public function start_bg_scan( $resume = true ) {
		$resume  = (bool) $resume;
		$running = (int) get_option( 'tsoliin_bg_scan_running', 0 );

		if ( $running && $resume ) {
			$ts = wp_next_scheduled( self::HOOK_BG_SCAN_STEP );
			if ( ! $ts ) {
				wp_schedule_single_event( time(), self::HOOK_BG_SCAN_STEP );
				spawn_cron();
			}
			return;
		}

		if ( $running && ! $resume ) {
			$this->stop_bg_scan();
		}

		$total    = $this->scanner->get_total_posts();
		$complete = (int) get_option( 'tsoliin_bg_scan_complete', 1 );
		$page     = max( 1, (int) get_option( 'tsoliin_bg_scan_page', 1 ) );
		$scanned  = (int) get_option( 'tsoliin_bg_scan_scanned', 0 );

		if ( $resume && ! $complete && $total > 0 && $scanned > 0 && $scanned < $total ) {
			// Keep stored page/scanned cursors.
		} else {
			$this->reset_bg_scan_cursors();
			$page    = 1;
			$scanned = 0;
		}

		delete_option( 'tsoliin_bg_scan_error' );
		update_option( 'tsoliin_bg_scan_running', 1, false );
		update_option( 'tsoliin_bg_scan_page', $page, false );
		update_option( 'tsoliin_bg_scan_total', (int) $total, false );
		update_option( 'tsoliin_bg_scan_scanned', (int) $scanned, false );
		update_option( 'tsoliin_bg_scan_complete', 0, false );
		update_option( 'tsoliin_bg_scan_started', current_time( 'mysql', true ), false );

		wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
		wp_schedule_single_event( time(), self::HOOK_BG_SCAN_STEP );
		spawn_cron();
	}

	/**
	 * Stop a running background scan (keeps page/scanned for resume).
	 */
	public function stop_bg_scan() {
		update_option( 'tsoliin_bg_scan_running', 0, false );
		update_option( 'tsoliin_bg_scan_complete', 0, false );
		wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
	}

	/**
	 * Execute one or more scan batches within a time budget (WP-Cron / spawn_cron).
	 */
	public function run_bg_scan_step() {
		if ( ! get_option( 'tsoliin_bg_scan_running' ) ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- WP-Cron scan batch may exceed host max_execution_time.
			@set_time_limit( 60 );
		}

		$start = microtime( true );
		$page  = max( 1, (int) get_option( 'tsoliin_bg_scan_page', 1 ) );
		$total = (int) get_option( 'tsoliin_bg_scan_total', 0 );
		if ( $total <= 0 ) {
			$total = $this->scanner->get_total_posts();
			update_option( 'tsoliin_bg_scan_total', (int) $total, false );
		}

		$done = false;
		do {
			try {
				$result = $this->scanner->scan_batch( $page, TSOLIIN_BATCH_SIZE );
			} catch ( \Throwable $e ) {
				$this->record_bg_scan_error( $e->getMessage() );
				return;
			}

			$page++;
			update_option( 'tsoliin_bg_scan_page', $page, false );

			$scanned = min( ( $page - 1 ) * TSOLIIN_BATCH_SIZE, $total );
			update_option( 'tsoliin_bg_scan_scanned', (int) $scanned, false );
			update_option( 'tsoliin_total_posts_scanned', (int) $scanned, false );
			// Heartbeat so long scans are not marked stale after 30 minutes from start.
			update_option( 'tsoliin_bg_scan_started', current_time( 'mysql', true ), false );

			if ( ! empty( $result['done'] ) ) {
				$done = true;
				break;
			}
		} while ( ( microtime( true ) - $start ) < self::BG_SCAN_TIME_BUDGET );

		if ( $done ) {
			$this->finalize_bg_scan_completion();
			return;
		}

		// Avoid stacking duplicate step events if cron overlaps.
		wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
		wp_schedule_single_event( time() + 2, self::HOOK_BG_SCAN_STEP );
		spawn_cron();
	}

	/**
	 * Mark a background scan complete and persist summary options.
	 */
	private function finalize_bg_scan_completion() {
		$total = (int) get_option( 'tsoliin_bg_scan_total', 0 );
		if ( $total <= 0 ) {
			$total = $this->scanner->get_total_posts();
		}
		update_option( 'tsoliin_bg_scan_running', 0, false );
		update_option( 'tsoliin_bg_scan_complete', 1, false );
		update_option( 'tsoliin_bg_scan_scanned', (int) $total, false );
		update_option( 'tsoliin_total_posts_scanned', (int) $total, false );
		update_option( 'tsoliin_last_full_scan', current_time( 'mysql', true ), false );
		delete_option( 'tsoliin_bg_scan_error' );
		wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
		TSOLIIN_DB::clear_stats_cache();
	}

	/**
	 * Store a scan failure message and stop the background run.
	 *
	 * @param string $message Error detail.
	 */
	private function record_bg_scan_error( $message ) {
		$message = is_string( $message ) ? trim( $message ) : '';
		if ( '' === $message ) {
			$message = __( 'Scan failed on the server. Use Continue scan to retry from the last saved position.', 'tso-link-inspector' );
		}
		update_option( 'tsoliin_bg_scan_error', $message, false );
		update_option( 'tsoliin_bg_scan_running', 0, false );
		update_option( 'tsoliin_bg_scan_complete', 0, false );
		wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
	}

	/**
	 * Reset extended-source cursors before a fresh scan.
	 */
	public function reset_bg_scan_cursors() {
		delete_option( 'tsoliin_comment_scan_after_id' );
		delete_option( 'tsoliin_menu_scan_after_id' );
		delete_option( 'tsoliin_widget_scan_after_index' );
		delete_option( 'tsoliin_term_scan_after_id' );
		delete_option( 'tsoliin_fse_scan_after_id' );
	}

	/**
	 * Whether a stopped scan can be resumed.
	 *
	 * @return bool
	 */
	public function is_bg_scan_resumable() {
		if ( (bool) get_option( 'tsoliin_bg_scan_running', 0 ) ) {
			return false;
		}
		if ( (int) get_option( 'tsoliin_bg_scan_complete', 1 ) ) {
			return false;
		}
		$total   = (int) get_option( 'tsoliin_bg_scan_total', 0 );
		$scanned = (int) get_option( 'tsoliin_bg_scan_scanned', 0 );
		if ( $total <= 0 ) {
			$total = $this->scanner->get_total_posts();
		}
		return $scanned > 0 && $scanned < $total;
	}

	/**
	 * Current background scan progress.
	 *
	 * @return array{ running: bool, scanned: int, total: int, pct: int, complete: bool, resumable: bool, error: string, done: bool }
	 */
	public function get_bg_scan_progress() {
		$running  = (bool) get_option( 'tsoliin_bg_scan_running', 0 );
		$total    = (int) get_option( 'tsoliin_bg_scan_total', 0 );
		$scanned  = (int) get_option( 'tsoliin_bg_scan_scanned', 0 );
		$complete = (bool) get_option( 'tsoliin_bg_scan_complete', 1 );
		$started  = (string) get_option( 'tsoliin_bg_scan_started', '' );
		$error    = (string) get_option( 'tsoliin_bg_scan_error', '' );

		if ( $total <= 0 ) {
			$total = $this->scanner->get_total_posts();
		}

		if ( $running && '' !== $started && ( time() - (int) strtotime( $started ) ) > 1800 ) {
			$running = false;
			update_option( 'tsoliin_bg_scan_running', 0, false );
			wp_clear_scheduled_hook( self::HOOK_BG_SCAN_STEP );
			if ( '' === $error ) {
				$error = __( 'Scan timed out or was interrupted. Use Continue scan to resume.', 'tso-link-inspector' );
				update_option( 'tsoliin_bg_scan_error', $error, false );
			}
		}

		if ( $scanned > $total ) {
			$scanned = $total;
		}
		$pct = ( $total > 0 ) ? min( 100, (int) round( ( $scanned / $total ) * 100 ) ) : 0;

		$resumable = $this->is_bg_scan_resumable();
		// complete=1 after finalize; also treat total=0 as done so empty sites do not poll forever.
		$done = $complete && ! $running && ( ( $total > 0 && $scanned >= $total ) || 0 === $total );

		return array(
			'running'   => $running,
			'scanned'   => $scanned,
			'total'     => $total,
			'pct'       => $pct,
			'complete'  => $complete,
			'resumable' => $resumable,
			'error'     => $error,
			'done'      => $done,
		);
	}

	// -------------------------------------------------------------------------
	// Background check (server-side, browser-independent)
	// -------------------------------------------------------------------------

	/**
	 * Start background check.
	 *
	 * If $resume is true and there are unchecked links pending, resume from that point.
	 * Otherwise start a fresh full check.
	 *
	 * @param bool $resume  Whether to resume partial progress when possible.
	 * @param int  $post_id When > 0, only check links from this post.
	 */
	public function start_bg_check( $resume = true, $post_id = 0 ) {
		$resume  = (bool) $resume;
		$post_id = absint( $post_id );
		$running = (int) get_option( 'tsoliin_bg_check_running', 0 );

		if ( $running && $resume ) {
			$current_post_id = absint( get_option( 'tsoliin_bg_check_post_id', 0 ) );
			if ( $current_post_id === $post_id ) {
				$ts = wp_next_scheduled( self::HOOK_BG_STEP );
				if ( ! $ts ) {
					wp_schedule_single_event( time(), self::HOOK_BG_STEP );
					spawn_cron();
				}
				return;
			}
			$this->stop_bg_check();
			$running = 0;
		}

		if ( $running && ! $resume ) {
			$this->stop_bg_check();
		}

		if ( $post_id > 0 ) {
			$total = (int) $this->db->get_stats_for_post( $post_id )['total'];
		} else {
			$total = (int) $this->db->get_stats()['total'];
		}

		$pending = $this->db->get_pending_check_count( $post_id );
		if ( $resume && $pending > 0 ) {
			$checked = max( 0, $total - $pending );
		} else {
			$this->db->reset_for_recheck( $post_id );
			$checked = 0;
		}

		update_option( 'tsoliin_bg_check_running', 1, false );
		update_option( 'tsoliin_bg_check_post_id', $post_id, false );
		update_option( 'tsoliin_bg_check_total', (int) $total, false );
		update_option( 'tsoliin_bg_check_checked', (int) $checked, false );
		update_option( 'tsoliin_bg_check_started', current_time( 'mysql', true ), false );
		$this->flush_immediate_broken_queue();

		$ts = wp_next_scheduled( self::HOOK_BG_STEP );
		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK_BG_STEP );
		}
		wp_schedule_single_event( time(), self::HOOK_BG_STEP );
		spawn_cron();
	}

	/**
	 * Stop a running background check.
	 *
	 * Keeps post_id / total / checked so the resume UI can show accurate
	 * pending counts and progress after a manual stop.
	 */
	public function stop_bg_check() {
		update_option( 'tsoliin_bg_check_running', 0, false );
		wp_clear_scheduled_hook( self::HOOK_BG_STEP );
	}

	/**
	 * Execute one BG check step (called by cron or admin poll fallback).
	 *
	 * @param int|null $batch_size Optional batch size (admin poll uses a smaller budget).
	 */
	public function run_bg_step( $batch_size = null ) {
		if ( ! get_option( 'tsoliin_bg_check_running' ) ) {
			return;
		}

		if ( function_exists( 'set_time_limit' ) ) {
			// phpcs:ignore Squiz.PHP.DiscouragedFunctions.Discouraged, WordPress.PHP.NoSilencedErrors.Discouraged -- WP-Cron check batch may exceed host max_execution_time.
			@set_time_limit( 120 );
		}

		$batch_size   = null === $batch_size ? self::BG_BATCH : max( 1, absint( $batch_size ) );
		$time_budget  = ( $batch_size <= self::BG_POLL_BATCH ) ? self::BG_POLL_TIME_BUDGET : 0;
		$started_at   = microtime( true );
		$post_id      = absint( get_option( 'tsoliin_bg_check_post_id', 0 ) );
		$links        = $this->db->get_links_batch_for_check( $batch_size, $post_id );
		if ( empty( $links ) ) {
			if ( $this->maybe_reschedule_bg_check( $post_id ) ) {
				return;
			}
			$this->finalize_bg_check_completion();
			return;
		}
		$processed = 0;
		foreach ( $links as $link ) {
			if ( $time_budget > 0 && ( microtime( true ) - $started_at ) >= $time_budget ) {
				break;
			}
			$check = $this->check_link_row( $link );
			if ( null === $check ) {
				continue;
			}
			++$processed;
			$item = $this->build_new_hard_broken_item( $check['link'], $check['result'], $check['prev_failures'] );
			if ( ! empty( $item ) ) {
				$this->queue_immediate_broken_item( $item );
			}
		}
		if ( $processed > 0 ) {
			delete_option( self::OPT_EMPTY_BATCH_RETRIES );
		}
		$total     = (int) get_option( 'tsoliin_bg_check_total', 0 );
		$pending   = $this->db->get_pending_check_count( $post_id );
		if ( $post_id > 0 ) {
			$live_total = (int) $this->db->get_stats_for_post( $post_id )['total'];
		} else {
			$live_total = (int) $this->db->get_stats()['total'];
		}
		$total = max( $total, $live_total );
		update_option( 'tsoliin_bg_check_total', $total, false );
		update_option( 'tsoliin_bg_check_checked', max( 0, $total - $pending ), false );

		// Heartbeat so long checks are not marked stale after 30 minutes from start.
		update_option( 'tsoliin_bg_check_started', current_time( 'mysql', true ), false );

		$more = $this->db->get_links_batch_for_check( 1, $post_id );
		if ( ! empty( $more ) ) {
			wp_clear_scheduled_hook( self::HOOK_BG_STEP );
			wp_schedule_single_event( time() + 2, self::HOOK_BG_STEP );
			spawn_cron();
		} elseif ( $this->maybe_reschedule_bg_check( $post_id ) ) {
			return;
		} else {
			$this->finalize_bg_check_completion();
		}
	}

	/**
	 * When the batch query is empty but pending rows remain, reschedule instead of finalizing.
	 *
	 * @param int $post_id Scope (0 = site-wide).
	 * @return bool True when a follow-up step was scheduled.
	 */
	private function maybe_reschedule_bg_check( $post_id ) {
		$post_id = absint( $post_id );
		TSOLIIN_DB::clear_stats_cache();
		$pending = $this->db->get_pending_check_count( $post_id );
		if ( $pending <= 0 ) {
			delete_option( self::OPT_EMPTY_BATCH_RETRIES );
			return false;
		}
		if ( empty( $this->db->get_links_batch_for_check( 1, $post_id ) ) ) {
			$retries = (int) get_option( self::OPT_EMPTY_BATCH_RETRIES, 0 ) + 1;
			update_option( self::OPT_EMPTY_BATCH_RETRIES, $retries, false );
			if ( $retries >= self::MAX_EMPTY_BATCH_RETRIES ) {
				delete_option( self::OPT_EMPTY_BATCH_RETRIES );
				return false;
			}
		} else {
			delete_option( self::OPT_EMPTY_BATCH_RETRIES );
		}
		wp_clear_scheduled_hook( self::HOOK_BG_STEP );
		wp_schedule_single_event( time() + 2, self::HOOK_BG_STEP );
		spawn_cron();
		update_option( 'tsoliin_bg_check_started', current_time( 'mysql', true ), false );
		return true;
	}

	/**
	 * Advance a running background check when WP-Cron missed a step (admin poll fallback).
	 *
	 * @return bool True when a batch was executed inline.
	 */
	public function ensure_bg_check_progress() {
		if ( ! get_option( 'tsoliin_bg_check_running' ) ) {
			return false;
		}

		$ts = wp_next_scheduled( self::HOOK_BG_STEP );
		// Scheduled and not overdue — let WP-Cron handle it.
		if ( $ts && $ts > time() - 15 ) {
			return false;
		}

		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK_BG_STEP );
		}

		$this->run_bg_step( self::BG_POLL_BATCH );
		return true;
	}

	/**
	 * Advance a running background scan when WP-Cron missed a step (admin poll fallback).
	 *
	 * @return bool True when a scan step was executed inline.
	 */
	public function ensure_bg_scan_progress() {
		if ( ! get_option( 'tsoliin_bg_scan_running' ) ) {
			return false;
		}

		$ts = wp_next_scheduled( self::HOOK_BG_SCAN_STEP );
		if ( $ts && $ts > time() - 15 ) {
			return false;
		}

		if ( $ts ) {
			wp_unschedule_event( $ts, self::HOOK_BG_SCAN_STEP );
		}

		$this->run_bg_scan_step();
		return true;
	}

	/**
	 * Send and clear the immediate broken-link email queue.
	 *
	 * @return void
	 */
	private function flush_immediate_broken_queue() {
		$queued = get_option( self::OPT_IMMEDIATE_QUEUE, array() );
		if ( ! is_array( $queued ) || empty( $queued ) ) {
			delete_option( self::OPT_IMMEDIATE_QUEUE );
			return;
		}
		if ( $this->send_immediate_broken_summary_email( $queued ) ) {
			delete_option( self::OPT_IMMEDIATE_QUEUE );
		}
	}

	/**
	 * Mark a background check run complete and run post-check maintenance.
	 *
	 * @return void
	 */
	private function finalize_bg_check_completion() {
		$this->flush_immediate_broken_queue();
		delete_option( self::OPT_EMPTY_BATCH_RETRIES );
		update_option( 'tsoliin_bg_check_running', 0, false );
		update_option( 'tsoliin_last_check_batch', current_time( 'mysql', true ), false );
		$this->db->maybe_cleanup_transparent_redirects();
		$this->db->cleanup_action_url_rows();
		$this->db->cleanup_blocked_dns_rows();
		$this->db->cleanup_mislabeled_skip_rows( true, 15 );
		$this->maybe_send_digest_broken_email();
	}

	/**
	 * Get current background check progress.
	 *
	 * @return array{ running: bool, checked: int, total: int, pct: int, post_id: int, pending: int }
	 */
	public function get_bg_progress() {
		$running = (bool) get_option( 'tsoliin_bg_check_running', 0 );
		$checked = (int)  get_option( 'tsoliin_bg_check_checked', 0 );
		$total   = (int)  get_option( 'tsoliin_bg_check_total',   0 );
		$started = (string) get_option( 'tsoliin_bg_check_started', '' );
		$post_id = absint( get_option( 'tsoliin_bg_check_post_id', 0 ) );

		// Auto-clear stale running flag (> 30 min without heartbeat).
		if ( $running && '' !== $started ) {
			if ( ( time() - (int) strtotime( $started ) ) > 1800 ) {
				TSOLIIN_DB::clear_stats_cache();
				$stale_pending = $this->db->get_pending_check_count( $post_id );
				if ( $stale_pending > 0 ) {
					// Work remains — keep the run alive and reschedule (do not mark stopped).
					update_option( 'tsoliin_bg_check_started', current_time( 'mysql', true ), false );
					if ( ! wp_next_scheduled( self::HOOK_BG_STEP ) ) {
						wp_schedule_single_event( time(), self::HOOK_BG_STEP );
						spawn_cron();
					}
				} else {
					$running = false;
					$this->flush_immediate_broken_queue();
					update_option( 'tsoliin_bg_check_running', 0, false );
					update_option( 'tsoliin_bg_check_post_id', 0, false );
					wp_clear_scheduled_hook( self::HOOK_BG_STEP );
				}
			}
		}

		// Keep progress in sync with the real queue even when the run is stopped (resume UI).
		$pending = 0;
		if ( $total > 0 ) {
			$pending = $this->db->get_pending_check_count( $post_id );
			if ( $post_id > 0 ) {
				$live_total = (int) $this->db->get_stats_for_post( $post_id )['total'];
			} else {
				$live_total = (int) $this->db->get_stats()['total'];
			}
			$total   = max( $total, $live_total );
			$checked = max( 0, $total - $pending );
		}

		$pct = ( $total > 0 ) ? min( 100, (int) round( ( $checked / $total ) * 100 ) ) : 0;

		return array(
			'running' => $running,
			'checked' => $checked,
			'total'   => $total,
			'pct'     => $pct,
			'post_id' => $post_id,
			'pending' => $pending,
		);
	}

	/**
	 * Send a periodic digest with all current hard-broken links.
	 * Digest is skipped when there are no hard-broken links.
	 *
	 * @return void
	 */
	private function maybe_send_digest_broken_email() {
		$mode = $this->get_email_mode();
		if ( 0 !== strpos( $mode, 'digest_' ) ) {
			return;
		}

		$days = absint( str_replace( 'digest_', '', $mode ) );
		if ( ! in_array( $days, array( 7, 15, 30 ), true ) ) {
			return;
		}

		$last_sent = (string) get_option( 'tsoliin_broken_digest_last_sent', '' );
		if ( '' !== $last_sent ) {
			$elapsed = time() - (int) strtotime( $last_sent );
			if ( $elapsed < ( $days * DAY_IN_SECONDS ) ) {
				return;
			}
		}

		$total_broken = $this->db->count_hard_broken_links();
		if ( $total_broken <= 0 ) {
			return;
		}

		$rows = $this->db->get_hard_broken_links( 200 );
		if ( empty( $rows ) ) {
			return;
		}

		$to = $this->get_notification_email();
		if ( '' === $to ) {
			return;
		}

		$site_name = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject   = sprintf(
			/* translators: 1: site name, 2: count */
			__( '[%1$s] Broken links report (%2$d)', 'tso-link-inspector' ),
			$site_name,
			$total_broken
		);

		$intro = sprintf(
			/* translators: 1: count, 2: site name */
			__( 'There are currently %1$d broken links without redirection on %2$s.', 'tso-link-inspector' ),
			$total_broken,
			$site_name
		);

		$items = array();
		foreach ( $rows as $row ) {
			$items[] = TSOLIIN_Email::normalize_broken_item( $row );
		}

		$more = $total_broken > count( $rows ) ? $total_broken - count( $rows ) : 0;

		$sent = TSOLIIN_Email::send_broken_links_report( $to, $subject, $intro, $items, $more );
		if ( $sent ) {
			update_option( 'tsoliin_broken_digest_last_sent', current_time( 'mysql', true ), false );
		}
	}

	/**
	 * HTTP-check one row; resync from WordPress only when the stored URL is missing from its source.
	 *
	 * @param object $link DB row.
	 * @return array{ link: object, result: array, prev_failures: int }|null Null when the row was removed.
	 */
	private function check_link_row( $link ) {
		$link = $this->prepare_link_for_http_check( $link );
		if ( ! $link ) {
			return null;
		}
		$prev_failures = isset( $link->consecutive_failures ) ? (int) $link->consecutive_failures : 0;
		$r             = $this->http->check( $link->link_url, (int) $link->post_id );
		$this->db->update_check_result(
			(int) $link->id,
			$r['status_code'],
			$r['redirect_url'],
			$r['is_broken'],
			isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : ''
		);
		return array(
			'link'          => $link,
			'result'        => $r,
			'prev_failures' => $prev_failures,
		);
	}

	/**
	 * Drop or refresh stale rows before cron HTTP checks.
	 *
	 * @param object $link DB row.
	 * @return object|null Row to check, or null if removed.
	 */
	private function prepare_link_for_http_check( $link ) {
		if ( ! $link || empty( $link->id ) ) {
			return null;
		}
		if ( $this->scanner->is_url_present_in_source( $link ) ) {
			return $link;
		}
		$synced = $this->scanner->resync_link_from_source( $link );
		if ( ! $synced ) {
			$this->db->delete_link( (int) $link->id );
			return null;
		}
		return $synced;
	}

	/**
	 * Return email mode from plugin settings.
	 *
	 * @return string
	 */
	private function get_email_mode() {
		$s            = get_option( 'tsoliin_settings', array() );
		$allowed_mode = array( 'none', 'immediate', 'confirmed', 'digest_7', 'digest_15', 'digest_30' );
		$mode         = isset( $s['broken_email_mode'] ) ? sanitize_key( (string) $s['broken_email_mode'] ) : 'none';
		return in_array( $mode, $allowed_mode, true ) ? $mode : 'none';
	}

	/**
	 * Hard-broken means broken with no redirect destination.
	 *
	 * @param bool   $is_broken    Broken flag.
	 * @param string $redirect_url Redirect destination.
	 * @param int    $status_code  HTTP code.
	 * @return bool
	 */
	private function is_hard_broken_status( $is_broken, $redirect_url, $status_code ) {
		if ( ! $is_broken ) {
			return false;
		}
		if ( '' !== trim( (string) $redirect_url ) ) {
			return false;
		}
		return ! ( $status_code >= 300 && $status_code < 400 );
	}

	/**
	 * Build a queue item when link changed to hard-broken.
	 *
	 * @param object $link          DB row before update.
	 * @param array  $r             HTTP check result.
	 * @param int    $prev_failures consecutive_failures before this check.
	 * @return array|null
	 */
	private function build_new_hard_broken_item( $link, $r, $prev_failures = 0 ) {
		$mode = $this->get_email_mode();
		if ( ! in_array( $mode, array( 'immediate', 'confirmed' ), true ) ) {
			return null;
		}
		$prev_failures = max( 0, (int) $prev_failures );
		$was_hard_broken = $this->is_hard_broken_status(
			! empty( $link->is_broken ),
			isset( $link->redirect_url ) ? (string) $link->redirect_url : '',
			isset( $link->status_code ) ? (int) $link->status_code : 0
		);
		$is_hard_broken = $this->is_hard_broken_status(
			! empty( $r['is_broken'] ),
			isset( $r['redirect_url'] ) ? (string) $r['redirect_url'] : '',
			isset( $r['status_code'] ) ? (int) $r['status_code'] : 0
		);
		if ( ! $is_hard_broken ) {
			return null;
		}

		$new_failures = $prev_failures + 1;
		if ( 'confirmed' === $mode ) {
			if ( $new_failures < 2 || $prev_failures >= 2 ) {
				return null;
			}
		} elseif ( $was_hard_broken ) {
			return null;
		}

		$post_id    = isset( $link->post_id ) ? absint( $link->post_id ) : 0;
		$post_title = isset( $link->post_title ) ? (string) $link->post_title : '';
		if ( '' === $post_title && $post_id > 0 ) {
			$post_title = (string) get_the_title( $post_id );
		}
		return array(
			'id'         => isset( $link->id ) ? absint( $link->id ) : 0,
			'link_url'   => isset( $link->link_url ) ? (string) $link->link_url : '',
			'status_code'=> isset( $r['status_code'] ) ? (int) $r['status_code'] : 0,
			'post_id'    => $post_id,
			'post_title' => $post_title,
		);
	}

	/**
	 * Queue one immediate item during multi-step background checks.
	 *
	 * @param array $item Queue item.
	 * @return void
	 */
	private function queue_immediate_broken_item( array $item ) {
		$lock_key = 'tsoliin_immediate_queue_lock';
		$attempts = 0;
		$locked   = false;
		while ( $attempts < 8 && ! $this->db->acquire_transient_lock( $lock_key, 10 ) ) {
			usleep( 25000 );
			$attempts++;
		}
		$locked = ( $attempts < 8 );
		if ( ! $locked ) {
			return;
		}

		$queue = get_option( self::OPT_IMMEDIATE_QUEUE, array() );
		if ( ! is_array( $queue ) ) {
			$queue = array();
		}
		$key = ! empty( $item['id'] ) ? 'id-' . absint( $item['id'] ) : md5( wp_json_encode( $item ) );
		$queue[ $key ] = $item;
		// Keep queue bounded.
		if ( count( $queue ) > 300 ) {
			$queue = array_slice( $queue, -300, null, true );
		}
		update_option( self::OPT_IMMEDIATE_QUEUE, $queue, false );
		$this->db->release_transient_lock( $lock_key );
	}

	/**
	 * Send one summary email for newly detected hard-broken links.
	 *
	 * @param array $items Queue items.
	 * @return bool True when sent, skipped (mode off / empty), or no recipient. False when wp_mail failed.
	 */
	private function send_immediate_broken_summary_email( array $items ) {
		$mode = $this->get_email_mode();
		if ( ! in_array( $mode, array( 'immediate', 'confirmed' ), true ) || empty( $items ) ) {
			return true;
		}
		$to = $this->get_notification_email();
		if ( '' === $to ) {
			return true;
		}

		$items          = array_values( $items );
		$items_count    = count( $items );
		$site_name      = wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
		$subject        = sprintf(
			/* translators: 1: site name, 2: count */
			__( '[%1$s] Broken links report (%2$d)', 'tso-link-inspector' ),
			$site_name,
			$items_count
		);

		$intro = ( 'confirmed' === $mode )
			? sprintf(
				/* translators: 1: number of confirmed broken links, 2: site name */
				_n(
					'%1$d link was confirmed broken after two failed checks on %2$s.',
					'%1$d links were confirmed broken after two failed checks on %2$s.',
					$items_count,
					'tso-link-inspector'
				),
				$items_count,
				$site_name
			)
			: sprintf(
				/* translators: 1: number of newly detected broken links, 2: site name */
				_n(
					'%1$d new broken link was detected on %2$s.',
					'%1$d new broken links were detected on %2$s.',
					$items_count,
					'tso-link-inspector'
				),
				$items_count,
				$site_name
			);

		$normalized = array();
		foreach ( $items as $item ) {
			$normalized[] = TSOLIIN_Email::normalize_broken_item( $item );
		}

		return TSOLIIN_Email::send_broken_links_report( $to, $subject, $intro, $normalized, 0 );
	}

	/**
	 * Return notification recipient email from settings.
	 *
	 * @return string
	 */
	private function get_notification_email() {
		$s     = get_option( 'tsoliin_settings', array() );
		$email = isset( $s['broken_email_to'] ) ? sanitize_email( (string) $s['broken_email_to'] ) : '';
		if ( '' === $email ) {
			$email = sanitize_email( (string) get_option( 'admin_email' ) );
		}
		return $email;
	}
}
