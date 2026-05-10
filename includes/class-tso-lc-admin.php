<?php
/**
 * Admin pages, menus, and AJAX handlers.
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Admin
 */
class TSOLIIN_Admin {

	/** @var TSOLIIN_DB */
	private $db;

	/** @var TSOLIIN_Scanner */
	private $scanner;

	/** @var TSOLIIN_HTTP */
	private $http;

	/** @var TSOLIIN_Cron */
	private $cron;

	/** @var string */
	private $page_hook = '';

	public function __construct( TSOLIIN_DB $db, TSOLIIN_Scanner $scanner, TSOLIIN_HTTP $http, TSOLIIN_Cron $cron ) {
		$this->db      = $db;
		$this->scanner = $scanner;
		$this->http    = $http;
		$this->cron    = $cron;

		add_action( 'admin_menu',             array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_assets' ) );

		add_action( 'wp_ajax_tsoliin_scan_batch',    array( $this, 'ajax_scan_batch' ) );
		add_action( 'wp_ajax_tsoliin_recheck',       array( $this, 'ajax_recheck' ) );
		add_action( 'wp_ajax_tsoliin_update_link',   array( $this, 'ajax_update_link' ) );
		add_action( 'wp_ajax_tsoliin_unlink',        array( $this, 'ajax_unlink' ) );
		add_action( 'wp_ajax_tsoliin_delete_link',   array( $this, 'ajax_delete_link' ) );
		add_action( 'wp_ajax_tsoliin_not_broken',    array( $this, 'ajax_not_broken' ) );
		add_action( 'wp_ajax_tsoliin_bulk_action',   array( $this, 'ajax_bulk_action' ) );
		add_action( 'wp_ajax_tsoliin_start_bg_check',  array( $this, 'ajax_start_bg_check' ) );
		add_action( 'wp_ajax_tsoliin_stop_bg_check',   array( $this, 'ajax_stop_bg_check' ) );
		add_action( 'wp_ajax_tsoliin_check_progress',  array( $this, 'ajax_check_progress' ) );
		add_action( 'wp_ajax_tsoliin_smart_suggest',   array( $this, 'ajax_smart_suggest' ) );
		add_action( 'wp_ajax_tsoliin_diagnose',        array( $this, 'ajax_diagnose' ) );
		add_action( 'wp_ajax_tsoliin_export_csv',      array( $this, 'ajax_export_csv' ) );
	}

	// =========================================================================
	// MENU
	// =========================================================================

	public function register_menu() {
		$this->page_hook = add_management_page(
			__( 'TSO Link Inspector', 'tso-link-inspector' ),
			__( 'TSO Link Inspector', 'tso-link-inspector' ),
			'manage_options',
			'tso-link-inspector',
			array( $this, 'render_main_page' )
		);
		add_submenu_page(
			'tools.php',
			__( 'TSO Link Inspector - Settings', 'tso-link-inspector' ),
			__( 'Settings', 'tso-link-inspector' ),
			'manage_options',
			'tso-link-inspector-settings',
			array( $this, 'render_settings_page' )
		);
		// Keep Settings accessible by URL but hidden from the Tools submenu.
		remove_submenu_page( 'tools.php', 'tso-link-inspector-settings' );
	}

	// =========================================================================
	// ASSETS
	// =========================================================================

	public function enqueue_assets( $hook ) {
		$our_pages = array( $this->page_hook, 'tools_page_tso-link-inspector-settings', 'admin_page_tso-link-inspector-settings' );
		if ( ! in_array( $hook, $our_pages, true ) ) {
			return;
		}
		wp_enqueue_style( 'tsoliin-admin', TSOLIIN_PLUGIN_URL . 'assets/css/admin.css', array(), TSOLIIN_VERSION );
		wp_enqueue_script( 'tsoliin-admin', TSOLIIN_PLUGIN_URL . 'assets/js/admin.js', array( 'jquery' ), TSOLIIN_VERSION, true );

		$bg = $this->cron->get_bg_progress();

		wp_localize_script( 'tsoliin-admin', 'tsoliinData', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'tsoliin_action' ),
			'totalPosts' => $this->scanner->get_total_posts(),
			'batchSize'  => TSOLIIN_BATCH_SIZE,
			'bgRunning'  => $bg['running'] ? 1 : 0,
			'bgChecked'  => $bg['checked'],
			'bgTotal'    => $bg['total'],
			'bgPct'      => $bg['pct'],
			'refreshInterval' => 8000, // ms between stat card auto-refreshes
			'i18n' => array(
				'scanning'      => __( 'Scanning...', 'tso-link-inspector' ),
				'scanDone'      => __( 'Scan completed!', 'tso-link-inspector' ),
				'checking'      => __( 'Checking...', 'tso-link-inspector' ),
				'checkDone'     => __( 'Check completed!', 'tso-link-inspector' ),
				'checkStarted'  => __( 'Check started. You can continue browsing.', 'tso-link-inspector' ),
				'stopped'       => __( 'Stopped', 'tso-link-inspector' ),
				'recheck'       => __( 'Recheck', 'tso-link-inspector' ),
				'saving'        => __( 'Saving...', 'tso-link-inspector' ),
				'urlSaved'      => __( 'URL updated successfully.', 'tso-link-inspector' ),
				'notBrokenDone' => __( 'Marked as OK.', 'tso-link-inspector' ),
				'diagnosi'      => __( 'Diagnostics', 'tso-link-inspector' ),
				'diagChecking'  => __( 'Running diagnostics...', 'tso-link-inspector' ),
				'diagResult'    => __( 'Diagnostics result:', 'tso-link-inspector' ),
				'smartChecking' => __( 'Looking for alternatives...', 'tso-link-inspector' ),
				'smartSuggest'  => __( 'Suggested URL', 'tso-link-inspector' ),
				'noSuggestions'    => __( 'No alternatives found.', 'tso-link-inspector' ),
				'detectedRedirect' => __( 'Redirect destination already detected', 'tso-link-inspector' ),
				'applyUrl'      => __( 'Apply', 'tso-link-inspector' ),
				'itemsChecked'  => __( 'links rechecked.', 'tso-link-inspector' ),
				'itemsUnlinked' => __( 'links unlinked.', 'tso-link-inspector' ),
				'confirmUnlink' => __( 'Are you sure? The text will remain but the link will be removed.', 'tso-link-inspector' ),
				'confirmDelete' => __( 'Delete this record from the list. The post will not be changed. Continue?', 'tso-link-inspector' ),
				'error'         => __( 'An error occurred.', 'tso-link-inspector' ),
				'save'          => __( 'Save URL', 'tso-link-inspector' ),
				'cancel'        => __( 'Cancel', 'tso-link-inspector' ),
				'notBroken'     => __( 'Not broken', 'tso-link-inspector' ),
				'rechecking'    => __( 'Rechecking…', 'tso-link-inspector' ),
				'urlRequired'   => __( 'Please enter a valid URL.', 'tso-link-inspector' ),
				'unlink'        => __( 'Unlink', 'tso-link-inspector' ),
			),
		) );
	}

	// =========================================================================
	// MAIN PAGE
	// =========================================================================

	public function render_main_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-link-inspector' ) );
		}

		$table = new TSOLIIN_List_Table( $this->db, $this->http );
		$table->prepare_items();

		// Detect if we are viewing a single post's links.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$view_post    = $view_post_id ? get_post( $view_post_id ) : null;

		// If viewing a specific post, get stats scoped to that post.
		$stats = $view_post_id
			? $this->db->get_stats_for_post( $view_post_id )
			: $this->db->get_stats();
		$bg       = $this->cron->get_bg_progress();
		$last_scan  = (string) get_option( 'tsoliin_last_full_scan', '' );
		$last_check = (string) get_option( 'tsoliin_last_check_batch', '' );
		$total_posts    = $this->scanner->get_total_posts();
		$scanned_stored = (int) get_option( 'tsoliin_total_posts_scanned', 0 );
		$scanned_posts  = $scanned_stored > 0 ? $scanned_stored : $this->db->get_scanned_post_count();
		$date_fmt = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		echo '<div class="wrap tsoliin-wrap">';

		echo '<h1 class="wp-heading-inline">';
		echo '<span class="dashicons dashicons-admin-links tsoliin-title-icon"></span> ';
		if ( $view_post ) {
			echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '">' . esc_html__( 'TSO Link Inspector', 'tso-link-inspector' ) . '</a>';
			echo ' <span style="color:#646970;">&#8250;</span> ';
			echo esc_html( $view_post->post_title );
		} else {
			echo esc_html__( 'TSO Link Inspector', 'tso-link-inspector' );
		}
		echo '</h1>';
		echo '<hr class="wp-header-end">';


		// Stats cards.
		echo '<div class="tsoliin-stats">';
		$this->stat_card( number_format_i18n( $stats['total'] ),   __( 'Total', 'tso-link-inspector' ),    '' );
		$this->stat_card( number_format_i18n( $stats['broken'] ),  __( 'Broken', 'tso-link-inspector' ), 'broken' );
		$this->stat_card( number_format_i18n( $stats['redirect'] ),__( 'Redirect', 'tso-link-inspector' ),'redirect' );
		$this->stat_card( number_format_i18n( $stats['ok'] ),      __( 'OK', 'tso-link-inspector' ),'ok' );
		$this->stat_card( number_format_i18n( $stats['unchecked'] ), __( 'Unchecked', 'tso-link-inspector' ), 'unchecked', __( 'Links found but not checked by HTTP yet. Cron or Check now will verify them.', 'tso-link-inspector' ) );
		$http_insecure_count = isset( $stats['http_insecure'] ) ? $stats['http_insecure'] : 0;
		if ( $http_insecure_count > 0 ) {
			$this->stat_card( number_format_i18n( $http_insecure_count ), __( 'HTTP insecure', 'tso-link-inspector' ), 'http-insecure', __( 'Active links using HTTP. Consider updating them to HTTPS for security and SEO.', 'tso-link-inspector' ) );
		}
		$this->stat_card( $scanned_posts . ' / ' . $total_posts,   __( 'Scanned', 'tso-link-inspector' ),'posts' );
		echo '</div>';

		// Last scan info.
		echo '<p class="tsoliin-last-scan">';
		if ( '' !== $last_scan ) {
			echo esc_html( sprintf(
				/* translators: %s: date */
				__( 'Last scan: %s', 'tso-link-inspector' ),
				wp_date( $date_fmt, strtotime( $last_scan ) )
			) );
		} else {
			echo '<em>' . esc_html__( 'No scan has been run yet. Click Scan now.', 'tso-link-inspector' ) . '</em>';
		}
		$last_check_count = (int) get_option( 'tsoliin_last_check_count', 0 );
		$s_recheck        = get_option( 'tsoliin_settings', array() );
		$stale_days_info  = isset( $s_recheck['recheck_days'] ) ? absint( $s_recheck['recheck_days'] ) : 7;
		$pending_count    = $this->db->count_stale_links( $stale_days_info );

		if ( '' !== $last_check ) {
			$check_info = wp_date( $date_fmt, strtotime( $last_check ) );
			if ( $last_check_count > 0 ) {
				/* translators: %d: number of links */
				$check_info .= ' (' . sprintf( __( '%d links', 'tso-link-inspector' ), $last_check_count ) . ')';
			} else {
				$check_info .= ' ' . __( '(no pending links)', 'tso-link-inspector' );
			}
			echo ' | ' . esc_html( sprintf(
				/* translators: %s: date and info */
				__( 'Last automatic check: %s', 'tso-link-inspector' ),
				$check_info
			) );
		}

		// Pending links indicator.
		if ( $pending_count > 0 ) {
			echo ' | ';
			printf(
				'<span title="%s">%s</span>',
				esc_attr( sprintf(
					/* translators: %d: days */
					__( 'Links never checked or older than %d days. Cron will check them automatically.', 'tso-link-inspector' ),
					$stale_days_info
				) ),
				esc_html( sprintf(
					/* translators: %d: count */
					__( '%d to check', 'tso-link-inspector' ),
					$pending_count
				) )
			);
		} else {
			echo ' | <span style="color:#0a7d33;" title="' . esc_attr__( 'All links were checked recently.', 'tso-link-inspector' ) . '">' . esc_html__( 'All up to date', 'tso-link-inspector' ) . '</span>';
		}

		// Next cron run times.
		$next_scan  = wp_next_scheduled( TSOLIIN_Cron::HOOK_SCAN );
		$next_check = wp_next_scheduled( TSOLIIN_Cron::HOOK_CHECK );
		if ( $next_scan || $next_check ) {
			// Use explicit date+time format for cron times (always show hours:minutes).
			$cron_fmt = get_option( 'date_format' ) . ' H:i';
			if ( $next_scan ) {
				echo ' | ' . esc_html( sprintf(
					/* translators: %s: date and time */
					__( 'Next automatic scan: %s', 'tso-link-inspector' ),
					wp_date( $cron_fmt, $next_scan )
				) );
			}
			if ( $next_check ) {
				echo ' | ' . esc_html( sprintf(
					/* translators: %s: date and time */
					__( 'Next automatic check: %s', 'tso-link-inspector' ),
					wp_date( $cron_fmt, $next_check )
				) );
			}
		}
		echo '</p>';

		// Toolbar.
		$btn_check_disabled = $bg['running'] ? ' disabled' : '';
		$btn_check_label    = $bg['running'] ? __( 'Checking...', 'tso-link-inspector' ) : __( 'Check now', 'tso-link-inspector' );
		$check_prog_display = $bg['running'] ? 'block' : 'none';
		$check_prog_pct     = $bg['pct'];

		echo '<div class="tsoliin-toolbar">';
		echo '<button type="button" id="tsoliin-start-scan" class="button button-primary">';
		echo '<span class="dashicons dashicons-search"></span> ';
		echo esc_html__( 'Scan now', 'tso-link-inspector' );
		echo '</button>';
		echo '<button type="button" id="tsoliin-start-check" class="button tsoliin-btn-check"' . $btn_check_disabled . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="dashicons dashicons-yes-alt"></span> ';
		echo esc_html( $btn_check_label );
		echo '</button>';
		if ( $bg['running'] ) {
			echo '<button type="button" id="tsoliin-stop-check" class="button button-secondary">';
			echo '<span class="dashicons dashicons-no-alt"></span> ';
			echo esc_html__( 'Stop', 'tso-link-inspector' );
			echo '</button>';
		}
		echo '<button type="button" id="tsoliin-diagnose" class="button button-secondary">';
		echo '<span class="dashicons dashicons-info"></span> ';
		echo esc_html__( 'Diagnostics', 'tso-link-inspector' );
		echo '</button>';
		// Export CSV button.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_filter = isset( $_REQUEST['filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter'] ) ) : 'all';
		$export_nonce  = wp_create_nonce( 'tsoliin_action' );
		echo '<a href="' . esc_url( admin_url( 'admin-ajax.php?action=tsoliin_export_csv&filter=' . $export_filter . '&nonce=' . $export_nonce ) ) . '" class="button button-secondary" id="tsoliin-export-csv">';
		echo '<span class="dashicons dashicons-download"></span> ';
		echo esc_html__( 'Export CSV', 'tso-link-inspector' );
		echo '</a>';
		echo '<div id="tsoliin-scan-progress" class="tsoliin-progress" style="display:none;" role="progressbar" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"><div class="tsoliin-progress__bar"></div><span class="tsoliin-progress__label"></span></div>';
		echo '<div id="tsoliin-check-progress" class="tsoliin-progress tsoliin-progress--check" style="display:' . esc_attr( $check_prog_display ) . ';" role="progressbar" aria-valuenow="' . esc_attr( (string) $check_prog_pct ) . '" aria-valuemin="0" aria-valuemax="100">';
		echo '<div class="tsoliin-progress__bar" style="width:' . esc_attr( (string) $check_prog_pct ) . '%"></div>';
		echo '<span class="tsoliin-progress__label">';
		if ( $bg['running'] ) {
			echo esc_html( $check_prog_pct . '% - ' . __( 'Checking...', 'tso-link-inspector' ) );
		}
		echo '</span></div>';
		echo '</div>';

		echo '<div id="tsoliin-diagnose-panel" class="tsoliin-diagnose-panel" style="display:none;"></div>';

		// ── Secondary action bar: context buttons + search ────────────────
		echo '<div class="tsoliin-action-bar">';
		echo '<div class="tsoliin-action-bar__left">';
		echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector-settings' ) ) . '" class="button button-secondary">' . esc_html__( 'Settings', 'tso-link-inspector' ) . '</a> ';
		if ( $view_post ) {
			echo '<a href="' . esc_url( (string) get_edit_post_link( $view_post_id ) ) . '" class="button button-secondary" target="_blank">' . esc_html__( 'Edit post', 'tso-link-inspector' ) . '</a> ';
			echo '<a href="' . esc_url( (string) get_permalink( $view_post_id ) ) . '" class="button button-secondary" target="_blank">' . esc_html__( 'View post', 'tso-link-inspector' ) . '</a> ';
			echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '" class="button button-secondary">&#8592; ' . esc_html__( 'Back', 'tso-link-inspector' ) . '</a>';
		}
		echo '</div>';
		echo '<div class="tsoliin-action-bar__right">';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_val = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		echo '<form method="get" style="display:inline-flex;gap:4px;align-items:center;">';
		echo '<input type="hidden" name="page" value="tso-link-inspector" />';
		if ( $view_post_id ) { echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $view_post_id ) . '" />'; }
		echo '<input type="search" name="s" value="' . esc_attr( $search_val ) . '" placeholder="' . esc_attr__( 'Search (URL, text, post...)', 'tso-link-inspector' ) . '" class="tsoliin-search-input" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Search', 'tso-link-inspector' ) . '</button>';
		echo '</form>';
		echo '</div>';
		echo '</div>';

		// Table (form without search_box since search is in action bar above).
		echo '<form id="tsoliin-list-form" method="get">';
		echo '<input type="hidden" name="page" value="tso-link-inspector" />';
		$table->display();
		echo '</form>';

		// Edit modal.
		echo '<div id="tsoliin-modal" class="tsoliin-modal" style="display:none;" role="dialog" aria-modal="true">';
		echo '<div class="tsoliin-modal__overlay"></div>';
		echo '<div class="tsoliin-modal__content">';
		echo '<h2>' . esc_html__( 'Edit URL', 'tso-link-inspector' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'URL actual:', 'tso-link-inspector' ) . '</label><code id="tsoliin-modal-old-url"></code></p>';
		echo '<p><label for="tsoliin-new-url">' . esc_html__( 'New URL:', 'tso-link-inspector' ) . '</label>';
		echo '<input type="url" id="tsoliin-new-url" class="widefat" placeholder="https://" /></p>';
		echo '<div class="tsoliin-modal__actions">';
		echo '<button type="button" id="tsoliin-modal-save" class="button button-primary">' . esc_html__( 'Save URL', 'tso-link-inspector' ) . '</button>';
		echo '<button type="button" id="tsoliin-modal-cancel" class="button">' . esc_html__( 'Cancel', 'tso-link-inspector' ) . '</button>';
		echo '<span class="tsoliin-modal__spinner spinner"></span>';
		echo '</div>';
		echo '<div id="tsoliin-modal-feedback" class="tsoliin-modal__feedback"></div>';
		echo '</div></div>';

		echo '</div>';
	}

	/**
	 * Output a stat card.
	 */
	private function stat_card( $number, $label, $modifier, $tooltip = '' ) {
		$cls   = '' !== $modifier ? ' tsoliin-stat--' . esc_attr( $modifier ) : '';
		$title = '' !== $tooltip ? ' title="' . esc_attr( $tooltip ) . '"' : '';
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $cls and $title are fully escaped above.
		echo '<div class="tsoliin-stat' . $cls . '"' . $title . '>';
		echo '<span class="tsoliin-stat__number">' . esc_html( $number ) . '</span>';
		echo '<span class="tsoliin-stat__label">' . esc_html( $label ) . '</span>';
		echo '</div>';
	}

	/**
	 * Label for post type checkboxes (core types use plugin translations, not WP admin locale).
	 *
	 * @param object $post_type_object Post type object.
	 * @return string
	 */
	private function post_type_checkbox_label( $post_type_object ) {
		$slug = isset( $post_type_object->name ) ? (string) $post_type_object->name : '';
		$known = array(
			'post'       => __( 'Post', 'tso-link-inspector' ),
			'page'       => __( 'Page', 'tso-link-inspector' ),
			'attachment' => __( 'Media', 'tso-link-inspector' ),
		);
		$label = isset( $known[ $slug ] ) ? $known[ $slug ] : (string) $post_type_object->labels->singular_name;
		return $label . ' (' . $slug . ')';
	}

	// =========================================================================
	// SETTINGS PAGE
	// =========================================================================

	public function render_settings_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-link-inspector' ) );
		}

		if ( isset( $_POST['tsoliin_settings_nonce'] ) ) {
			$nonce = sanitize_text_field( wp_unslash( $_POST['tsoliin_settings_nonce'] ) );
			if ( wp_verify_nonce( $nonce, 'tsoliin_save_settings' ) ) {
				$this->save_settings();
				// Reload plugin translations immediately so language change applies on this request.
				if ( function_exists( 'tsoliin_link_inspector' ) ) {
					$plugin = tsoliin_link_inspector();
					if ( is_object( $plugin ) && method_exists( $plugin, 'load_textdomain' ) ) {
						$plugin->load_textdomain();
					}
				}
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'tso-link-inspector' ) . '</p></div>';
			}
		}

		// Handle reset.
		if ( isset( $_GET['tsoliin_action'], $_GET['_wpnonce'] ) && 'reset_all' === sanitize_key( wp_unslash( $_GET['tsoliin_action'] ) ) ) {
			$rnonce = sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) );
			if ( wp_verify_nonce( $rnonce, 'tsoliin_reset_all' ) ) {
				global $wpdb;
				$table = $this->db->get_table();
				// Validate table name against known DB table before query.
				$expected_table = $this->db->get_table();
				if ( $table === $expected_table ) {
					// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
					$wpdb->query( 'TRUNCATE TABLE `' . esc_sql( $table ) . '`' ); // Table name validated against get_table() on line above.
					// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				}
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Records deleted.', 'tso-link-inspector' ) . '</p></div>';
			}
		}

		$s           = get_option( 'tsoliin_settings', array() );
		$timeout     = isset( $s['timeout'] ) ? absint( $s['timeout'] ) : 15;
		$scan_meta   = ! empty( $s['scan_meta'] );
		$scan_images = ! empty( $s['scan_images'] );
		$scan_iframes= ! empty( $s['scan_iframes'] );
		$scan_comments=! empty( $s['scan_comments'] );
		$recheck_days= isset( $s['recheck_days'] ) ? absint( $s['recheck_days'] ) : 7;
		$allowed_email_modes = array( 'none', 'immediate', 'digest_7', 'digest_15', 'digest_30' );
		$broken_email_mode   = isset( $s['broken_email_mode'] ) ? sanitize_key( (string) $s['broken_email_mode'] ) : 'none';
		if ( ! in_array( $broken_email_mode, $allowed_email_modes, true ) ) {
			$broken_email_mode = 'none';
		}
		$broken_email_to         = isset( $s['broken_email_to'] ) ? sanitize_email( (string) $s['broken_email_to'] ) : '';
		$default_notify_email_to = sanitize_email( (string) get_option( 'admin_email' ) );
		// Do NOT use sanitize_key() — it lowercases 'es_ES' to 'es_es', breaking the dropdown.
		$allowed_display_langs = array( '', 'ca', 'es_ES', 'en' );
		$language = ( isset( $s['language'] ) && in_array( $s['language'], $allowed_display_langs, true ) ) ? (string) $s['language'] : '';
		$meta_keys   = isset( $s['meta_exclude_keys'] ) && is_array( $s['meta_exclude_keys'] )
			? implode( "\n", array_map( 'sanitize_text_field', $s['meta_exclude_keys'] ) )
			: '';
		$post_types  = isset( $s['post_types'] ) && is_array( $s['post_types'] )
			? array_map( 'sanitize_key', $s['post_types'] )
			: array( 'post', 'page' );
		$all_pts     = get_post_types( array( 'public' => true ), 'objects' );

		echo '<div class="wrap">';
		echo '<h1>';
		echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '" class="tsoliin-back-link">';
		echo '<span class="dashicons dashicons-arrow-left-alt"></span> ';
		echo esc_html__( 'TSO Link Inspector', 'tso-link-inspector' );
		echo '</a>';
		echo ' <span class="tsoliin-breadcrumb-sep">&#8250;</span> ';
		echo esc_html__( 'Settings', 'tso-link-inspector' );
		echo '</h1>';

		echo '<form method="post" action="">';
		wp_nonce_field( 'tsoliin_save_settings', 'tsoliin_settings_nonce' );
		echo '<table class="form-table" role="presentation"><tbody>';

		// Post types.
		echo '<tr><th scope="row">' . esc_html__( 'Content types', 'tso-link-inspector' ) . '</th><td>';
		foreach ( $all_pts as $pt ) {
			$checked = checked( in_array( $pt->name, $post_types, true ), true, false );
			echo '<label style="display:block;margin-bottom:5px;">';
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $checked comes from checked() which is safe.
			echo '<input type="checkbox" name="tsoliin_post_types[]" value="' . esc_attr( $pt->name ) . '" ' . $checked . ' /> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $checked from checked() is safe
			echo esc_html( $this->post_type_checkbox_label( $pt ) );
			echo '</label>';
		}
		echo '</td></tr>';

		// Scan images.
		echo '<tr><th scope="row">' . esc_html__( 'HTML Images', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_scan_images" value="1" ' . checked( $scan_images, true, false ) . ' /> ';
		echo esc_html__( 'Scan img src tags (broken images)', 'tso-link-inspector' ) . '</label></td></tr>';

		// Scan iframes.
		echo '<tr><th scope="row">' . esc_html__( 'Embedded videos (iframes)', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_scan_iframes" value="1" ' . checked( $scan_iframes, true, false ) . ' /> ';
		echo esc_html__( 'Scan iframes (YouTube, Vimeo, Google Maps)', 'tso-link-inspector' ) . '</label></td></tr>';

		// Scan comments.
		echo '<tr><th scope="row">' . esc_html__( 'Comments', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_scan_comments" value="1" ' . checked( $scan_comments, true, false ) . ' /> ';
		echo esc_html__( 'Scan approved comments', 'tso-link-inspector' ) . '</label></td></tr>';

		// Scan meta.
		echo '<tr><th scope="row">' . esc_html__( 'ACF / Meta custom fields', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" id="tsoliin_scan_meta" name="tsoliin_scan_meta" value="1" ' . checked( $scan_meta, true, false ) . ' /> ';
		echo esc_html__( 'Scan custom fields (ACF, PODS, CPT UI...)', 'tso-link-inspector' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Find links in fields added by plugins like ACF. Useful when you have URL, HTML, or editor fields in your posts. It may slow down scanning.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// Meta exclude keys.
		echo '<tr id="tsoliin-meta-exclude-row"><th scope="row">' . esc_html__( 'Meta keys to exclude', 'tso-link-inspector' ) . '</th><td>';
		echo '<textarea name="tsoliin_meta_exclude_keys" rows="4" class="tsoliin-meta-keys code">' . esc_textarea( $meta_keys ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One key per line.', 'tso-link-inspector' ) . '</p></td></tr>';

		// Timeout.
		echo '<tr><th scope="row"><label for="tsoliin_timeout">' . esc_html__( 'Timeout (seconds)', 'tso-link-inspector' ) . '</label></th><td>';
		echo '<input type="number" id="tsoliin_timeout" name="tsoliin_timeout" value="' . esc_attr( (string) $timeout ) . '" min="5" max="60" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Recommended: 15 s.', 'tso-link-inspector' ) . '</p></td></tr>';

		// Recheck days.
		echo '<tr><th scope="row"><label for="tsoliin_recheck_days">' . esc_html__( 'Recheck every (days)', 'tso-link-inspector' ) . '</label></th><td>';
		echo '<input type="number" id="tsoliin_recheck_days" name="tsoliin_recheck_days" value="' . esc_attr( (string) $recheck_days ) . '" min="1" max="365" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Cron rechecks links older than this age. Default: 7 days.', 'tso-link-inspector' ) . '</p></td></tr>';

		// Broken links email notifications.
		echo '<tr><th scope="row"><label for="tsoliin_broken_email_mode">' . esc_html__( 'Broken links email notifications', 'tso-link-inspector' ) . '</label></th><td>';
		echo '<select id="tsoliin_broken_email_mode" name="tsoliin_broken_email_mode">';
		$email_modes = array(
			'none'      => __( 'Disabled', 'tso-link-inspector' ),
			'immediate' => __( 'Send immediately when a broken link is detected', 'tso-link-inspector' ),
			'digest_7'  => __( 'Summary email every 7 days', 'tso-link-inspector' ),
			'digest_15' => __( 'Summary email every 15 days', 'tso-link-inspector' ),
			'digest_30' => __( 'Summary email every 30 days', 'tso-link-inspector' ),
		);
		foreach ( $email_modes as $email_mode_key => $email_mode_label ) {
			echo '<option value="' . esc_attr( $email_mode_key ) . '" ' . selected( $broken_email_mode, $email_mode_key, false ) . '>' . esc_html( $email_mode_label ) . '</option>';
		}
		echo '</select>';
		echo '<p class="description">';
		echo esc_html__( 'Only links that are fully broken are included (no redirect destination). Summary emails are skipped when there are no broken links.', 'tso-link-inspector' );
		echo '</p></td></tr>';

		// Broken links recipient email.
		echo '<tr><th scope="row"><label for="tsoliin_broken_email_to">' . esc_html__( 'Recipient email', 'tso-link-inspector' ) . '</label></th><td>';
		echo '<input type="email" id="tsoliin_broken_email_to" name="tsoliin_broken_email_to" value="' . esc_attr( $broken_email_to ) . '" placeholder="' . esc_attr( $default_notify_email_to ) . '" class="regular-text" />';
		echo '<p class="description">' . esc_html__( 'Address used for broken link notifications. Leave empty to use the WordPress admin email.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// Preserve post dates.
		$preserve_dates  = ! empty( $s['preserve_dates'] );
		$nofollow_broken = ! empty( $s['nofollow_broken'] );

		echo '<tr><th scope="row">' . esc_html__( 'Post modified date', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_preserve_dates" value="1" ' . checked( $preserve_dates, true, false ) . ' /> ';
		echo esc_html__( 'Do not update modified date when editing a link', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'If enabled, editing or unlinking a link will not change the post modified date.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Broken links and search engines', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_nofollow_broken" value="1" ' . checked( $nofollow_broken, true, false ) . ' /> ';
		echo esc_html__( 'Automatically add rel="nofollow" to broken links', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Prevents search engines from following broken links on your site. It only affects post content (not comments or custom fields).', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// Language.
		$langs = array( '' => __( 'Automatic', 'tso-link-inspector' ), 'ca' => __( 'Catalan', 'tso-link-inspector' ), 'es_ES' => __( 'Spanish', 'tso-link-inspector' ), 'en' => __( 'English', 'tso-link-inspector' ) );
		echo '<tr><th scope="row"><label for="tsoliin_language">' . esc_html__( 'Plugin language', 'tso-link-inspector' ) . '</label></th><td>';
		echo '<select id="tsoliin_language" name="tsoliin_language">';
		foreach ( $langs as $code => $lname ) {
			echo '<option value="' . esc_attr( $code ) . '" ' . selected( $language, $code, false ) . '>' . esc_html( $lname ) . '</option>';
		}
		echo '</select></td></tr>';

		// Ignore list.
		$ignore_list = isset( $s['ignore_list'] ) && is_array( $s['ignore_list'] ) ? implode( "\n", $s['ignore_list'] ) : '';
		echo '<tr><th scope="row">' . esc_html__( 'Ignore list', 'tso-link-inspector' ) . '</th><td>';
		echo '<textarea name="tsoliin_ignore_list" rows="5" class="tsoliin-meta-keys code">' . esc_textarea( $ignore_list ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One domain or URL per line. Example: amazon.com or https://example.com/page. These links will be skipped during scan and check.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'tso-link-inspector' ) );
		echo '</form>';

		// ── Maintenance.
		echo '<h2>' . esc_html__( 'Maintenance', 'tso-link-inspector' ) . '</h2>';
		echo '<div class="notice notice-warning inline"><p>';
		echo esc_html__( 'Clears the plugin internal table: found links, HTTP status, check dates, and redirect URLs.', 'tso-link-inspector' );
		echo '<br>';
		echo '<strong>' . esc_html__( 'Posts, pages, and comments are NOT modified.', 'tso-link-inspector' ) . '</strong> ';
		echo esc_html__( 'After that, run Scan now and Check now again.', 'tso-link-inspector' );
		echo '</p></div>';
		$reset_url = wp_nonce_url( add_query_arg( 'tsoliin_action', 'reset_all', admin_url( 'tools.php?page=tso-link-inspector-settings' ) ), 'tsoliin_reset_all' );
		echo '<p><a href="' . esc_url( $reset_url ) . '" class="button button-secondary" onclick="return confirm(\'' . esc_js( __( 'Are you sure? All plugin records will be deleted. Posts will not be changed.', 'tso-link-inspector' ) ) . '\')">'.  esc_html__( 'Delete all plugin records', 'tso-link-inspector' ) . '</a></p>';
		echo '</div>';
	}

	/**
	 * Save settings from POST.
	 */
	private function save_settings() {
		$current_settings = get_option( 'tsoliin_settings', array() );
		$current_mode     = isset( $current_settings['broken_email_mode'] ) ? sanitize_key( (string) $current_settings['broken_email_mode'] ) : 'none';
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- nonce verified in render_settings() before calling this method.
		$post_types = array();
		if ( isset( $_POST['tsoliin_post_types'] ) && is_array( $_POST['tsoliin_post_types'] ) ) {
			foreach ( (array) wp_unslash( $_POST['tsoliin_post_types'] ) as $pt ) {
				$clean = sanitize_key( (string) $pt );
				if ( post_type_exists( $clean ) ) {
					$post_types[] = $clean;
				}
			}
		}
		$timeout      = max( 5, min( 60, absint( isset( $_POST['tsoliin_timeout'] ) ? $_POST['tsoliin_timeout'] : 15 ) ) );
		$recheck_days = max( 1, min( 365, absint( isset( $_POST['tsoliin_recheck_days'] ) ? $_POST['tsoliin_recheck_days'] : 7 ) ) );
		$scan_meta    = ! empty( $_POST['tsoliin_scan_meta'] );
		$scan_images  = ! empty( $_POST['tsoliin_scan_images'] );
		$scan_iframes = ! empty( $_POST['tsoliin_scan_iframes'] );
		$scan_comments= ! empty( $_POST['tsoliin_scan_comments'] );
		$allowed_email_modes = array( 'none', 'immediate', 'digest_7', 'digest_15', 'digest_30' );
		$broken_email_mode   = 'none';
		if ( isset( $_POST['tsoliin_broken_email_mode'] ) ) {
			$email_mode_raw = sanitize_key( wp_unslash( $_POST['tsoliin_broken_email_mode'] ) );
			if ( in_array( $email_mode_raw, $allowed_email_modes, true ) ) {
				$broken_email_mode = $email_mode_raw;
			}
		}
		$broken_email_to = '';
		if ( isset( $_POST['tsoliin_broken_email_to'] ) ) {
			$broken_email_to = sanitize_email( wp_unslash( $_POST['tsoliin_broken_email_to'] ) );
		}

		$meta_keys = array();
		if ( isset( $_POST['tsoliin_meta_exclude_keys'] ) ) {
			$raw = sanitize_textarea_field( wp_unslash( $_POST['tsoliin_meta_exclude_keys'] ) );
			foreach ( explode( "\n", $raw ) as $k ) {
				$k = sanitize_text_field( trim( $k ) );
				if ( '' !== $k ) {
					$meta_keys[] = $k;
				}
			}
		}

		$preserve_dates  = ! empty( $_POST['tsoliin_preserve_dates'] );
		$nofollow_broken = ! empty( $_POST['tsoliin_nofollow_broken'] );

		// Use strict whitelist for language — do NOT use sanitize_key() which would
		// lowercase 'es_ES' to 'es_es' and break .mo file lookup.
		$allowed_languages = array( '', 'ca', 'es_ES', 'en' );
		$language = '';
		if ( isset( $_POST['tsoliin_language'] ) ) {
			$lang_raw = sanitize_text_field( wp_unslash( $_POST['tsoliin_language'] ) );
			if ( in_array( $lang_raw, $allowed_languages, true ) ) {
				$language = $lang_raw;
			}
		}

		// Parse ignore list from POST.
		$ignore_list_save = array();
		if ( isset( $_POST['tsoliin_ignore_list'] ) ) {
			$raw_ig = sanitize_textarea_field( wp_unslash( $_POST['tsoliin_ignore_list'] ) );
			foreach ( explode( "\n", $raw_ig ) as $entry ) {
				$entry = sanitize_text_field( trim( $entry ) );
				if ( '' !== $entry ) {
					$ignore_list_save[] = strtolower( $entry );
				}
			}
		}

		update_option( 'tsoliin_settings', array(
			'post_types'        => $post_types,
			'timeout'           => $timeout,
			'recheck_days'      => $recheck_days,
			'scan_meta'         => $scan_meta,
			'scan_images'       => $scan_images,
			'scan_iframes'      => $scan_iframes,
			'scan_comments'     => $scan_comments,
			'broken_email_mode' => $broken_email_mode,
			'broken_email_to'   => $broken_email_to,
			'meta_exclude_keys' => $meta_keys,
			'language'          => $language,
			'preserve_dates'    => $preserve_dates,
			'nofollow_broken'   => $nofollow_broken,
			'ignore_list'       => $ignore_list_save,
		), false );

		if ( $current_mode !== $broken_email_mode ) {
			delete_option( 'tsoliin_broken_digest_last_sent' );
		}
	}

	// =========================================================================
	// AJAX HANDLERS
	// =========================================================================

	private function check_nonce_and_cap() {
		check_ajax_referer( 'tsoliin_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'tso-link-inspector' ) ), 403 );
		}
	}

	public function ajax_scan_batch() {
		$this->check_nonce_and_cap();
		$page     = isset( $_POST['page_num'] ) ? absint( $_POST['page_num'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$result   = $this->scanner->scan_batch( $page, TSOLIIN_BATCH_SIZE );
		$total    = $this->scanner->get_total_posts();
		$progress = $total > 0 ? min( 100, (int) round( ( min( $page * TSOLIIN_BATCH_SIZE, $total ) / $total ) * 100 ) ) : 100;

		if ( $result['done'] ) {
			update_option( 'tsoliin_last_full_scan', current_time( 'mysql', true ), false );
			update_option( 'tsoliin_total_posts_scanned', $total, false );
			$progress = 100;
		}
		wp_send_json_success( array(
			'done'      => $result['done'],
			'scanned'   => $result['scanned'],
			'found'     => $result['found'],
			'progress'  => $progress,
			'next_page' => $page + 1,
			/* translators: 1: scanned, 2: total */
			'message'   => $result['done'] ? __( 'Scan completed!', 'tso-link-inspector' ) : sprintf( __( 'Scanning %1$d of %2$d...', 'tso-link-inspector' ), min( $page * TSOLIIN_BATCH_SIZE, $total ), $total ),
		) );
	}

	public function ajax_recheck() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link    = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'tso-link-inspector' ) ) );
		}
		// Keep user_verified: update_check_result() still runs HTTP and will override if the link is actually broken.
		$r = $this->http->check( $link->link_url );
		$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'] );
		wp_send_json_success( array(
			'status_code'  => $r['status_code'],
			'is_broken'    => $r['is_broken'],
			'redirect_url' => $r['redirect_url'],
			'label'        => TSOLIIN_HTTP::status_label( $r['status_code'], (string) $link->link_url ),
			'css_class'    => TSOLIIN_HTTP::status_class( $r['status_code'], $r['is_broken'], (string) $link->link_url ),
			'last_checked' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		) );
	}

	public function ajax_update_link() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$new_url = isset( $_POST['new_url'] ) ? trim( str_replace( array( "\0", "\r", "\n" ), '', sanitize_text_field( wp_unslash( $_POST['new_url'] ) ) ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $link_id || '' === $new_url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'tso-link-inspector' ) ) );
		}
		$link = $this->db->get_link( $link_id );
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'tso-link-inspector' ) ) );
		}

		$link_type = isset( $link->link_type ) ? (string) $link->link_type : 'link';

		if ( 'comment' === $link_type ) {
			// For comment links: update the comment author URL or comment content.
			$done = $this->update_url_in_comment( $link, $new_url );
		} else {
			$done = $this->scanner->replace_url_in_post( (int) $link->post_id, $link->link_url, $new_url );
		}

		if ( ! $done ) {
			if ( 'comment' === $link_type ) {
				wp_send_json_error( array( 'message' => __( 'Original URL not found in this comment. Edit the comment manually or check encoding (e.g. trailing slash).', 'tso-link-inspector' ) ) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Original URL not found in post. Check whether the link was edited manually.', 'tso-link-inspector' ) ) );
			}
		}

		$this->db->update_link_url( $link_id, $new_url );
		$r = $this->http->check( $new_url );
		$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'] );
		wp_send_json_success( array(
			'new_url'     => $new_url,
			'status_code' => $r['status_code'],
			'is_broken'   => $r['is_broken'],
			'label'       => TSOLIIN_HTTP::status_label( $r['status_code'], $new_url ),
			'css_class'   => TSOLIIN_HTTP::status_class( $r['status_code'], $r['is_broken'], $new_url ),
		) );
	}

	/**
	 * Update a URL that belongs to a comment (author URL or href in content).
	 *
	 * @param object $link    DB link row.
	 * @param string $new_url New URL.
	 * @return bool
	 */
	private function update_url_in_comment( $link, $new_url ) {
		// Extract comment ID from anchor_text pattern "Autor comentari #NNN" or "Comentari #NNN".
		if ( preg_match( '/#(\d+)/', (string) $link->anchor_text, $m ) ) {
			$cid     = absint( $m[1] );
			$comment = $cid ? get_comment( $cid ) : null;
			if ( $comment ) {
				$old_url    = (string) $link->link_url;
				$author_url = trim( (string) $comment->comment_author_url );

				// Update author URL if it matches (same rules as unlink — trailing slash / scheme / encoding).
				if ( $this->scanner->comment_author_url_matches_row_url( $author_url, $old_url ) ) {
					return false !== wp_update_comment( array(
						'comment_ID'         => $cid,
						'comment_author_url' => $new_url,
					) );
				}

				// Otherwise replace href in comment content.
				return $this->scanner->replace_url_in_comment_content( $cid, $old_url, $new_url );
			}
		}

		// Fallback: search all comments on this post for the URL.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cids = $wpdb->get_col( $wpdb->prepare(
			"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d AND ( comment_author_url = %s OR comment_content LIKE %s ) AND comment_approved = '1' LIMIT 5",
			absint( $link->post_id ),
			(string) $link->link_url,
			'%' . $wpdb->esc_like( (string) $link->link_url ) . '%'
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( empty( $cids ) ) {
			return false;
		}
		$done = false;
		foreach ( $cids as $cid ) {
			$c = get_comment( absint( $cid ) );
			if ( ! $c ) { continue; }
			if ( $this->scanner->comment_author_url_matches_row_url( trim( (string) $c->comment_author_url ), (string) $link->link_url ) ) {
				wp_update_comment( array( 'comment_ID' => absint( $cid ), 'comment_author_url' => $new_url ) );
				$done = true;
			} else {
				if ( $this->scanner->replace_url_in_comment_content( absint( $cid ), (string) $link->link_url, $new_url ) ) {
					$done = true;
				}
			}
		}
		return $done;
	}

	public function ajax_unlink() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link    = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'tso-link-inspector' ) ) );
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';

		if ( 'comment' === $type ) {
			$done = $this->unlink_comment( $link );
		} else {
			$done = $this->scanner->unlink_in_post( (int) $link->post_id, $link->link_url );
		}

		if ( ! $done ) {
			wp_send_json_error( array( 'message' => __( 'Cannot unlink this item. For comments, edit manually.', 'tso-link-inspector' ) ) );
		}
		$this->db->delete_link( $link_id );
		wp_send_json_success( array( 'message' => __( 'Link tag removed.', 'tso-link-inspector' ) ) );
	}

	/** @param object $link */
	private function unlink_comment( $link ) {
		if ( preg_match( '/#(\d+)/', (string) $link->anchor_text, $m ) ) {
			$cid = absint( $m[1] );
			if ( $cid ) {
				return $this->scanner->unlink_in_comment( $cid, $link->link_url );
			}
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cids = $wpdb->get_col( $wpdb->prepare(
			"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_approved = '1' AND ( comment_content LIKE %s OR comment_author_url = %s ) LIMIT 10",
			absint( $link->post_id ),
			'%' . $wpdb->esc_like( (string) $link->link_url ) . '%',
			(string) $link->link_url
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( empty( $cids ) ) {
			return false;
		}
		$done = false;
		foreach ( $cids as $cid ) {
			if ( $this->scanner->unlink_in_comment( absint( $cid ), $link->link_url ) ) {
				$done = true;
			}
		}
		return $done;
	}

	public function ajax_delete_link() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $link_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'tso-link-inspector' ) ) );
		}
		$this->db->delete_link( $link_id );
		wp_send_json_success( array( 'message' => __( 'Record deleted.', 'tso-link-inspector' ) ) );
	}

	public function ajax_not_broken() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $link_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'tso-link-inspector' ) ) );
		}
		$this->db->mark_as_not_broken( $link_id );
		wp_send_json_success( array(
			'message'     => __( 'Marked as OK.', 'tso-link-inspector' ),
			'css_class'   => 'tsoliin-status--ok',
			'label'       => __( 'OK (manual)', 'tso-link-inspector' ),
			'status_code' => 200,
		) );
	}

	public function ajax_bulk_action() {
		$this->check_nonce_and_cap();
		$action   = isset( $_POST['bulk_action'] ) ? sanitize_key( wp_unslash( $_POST['bulk_action'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link_ids = array();
		if ( isset( $_POST['link_ids'] ) && is_array( $_POST['link_ids'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
			foreach ( array_map( 'absint', wp_unslash( (array) $_POST['link_ids'] ) ) as $id ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
				$link_ids[] = $id;
			}
		}
		$link_ids = array_filter( $link_ids );
		if ( empty( $link_ids ) ) {
			wp_send_json_error( array( 'message' => __( 'No items selected.', 'tso-link-inspector' ) ) );
		}

		if ( 'recheck' === $action ) {
			$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$total   = count( $link_ids );
			$link_id = isset( $link_ids[ $index ] ) ? $link_ids[ $index ] : 0;
			if ( ! $link_id ) {
				wp_send_json_success( array( 'done' => true, 'processed' => $total ) );
			}
			$link     = $this->db->get_link( $link_id );
			$row_data = array( 'link_id' => $link_id );
			if ( $link ) {
				// Keep user_verified: update_check_result() still runs HTTP and will override if the link is actually broken.
				$r = $this->http->check( $link->link_url );
				$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'] );
				$row_data = array_merge( $row_data, array(
					'status_code'  => $r['status_code'],
					'is_broken'    => $r['is_broken'],
					'redirect_url' => $r['redirect_url'],
					'label'        => TSOLIIN_HTTP::status_label( $r['status_code'], (string) $link->link_url ),
					'css_class'    => TSOLIIN_HTTP::status_class( $r['status_code'], $r['is_broken'], (string) $link->link_url ),
					'last_checked' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
				) );
			}
			wp_send_json_success( array(
				'done'       => ( $index + 1 ) >= $total,
				'processed'  => $index + 1,
				'total'      => $total,
				'pct'        => (int) round( ( ( $index + 1 ) / $total ) * 100 ),
				'next_index' => $index + 1,
				'row'        => $row_data,
				/* translators: 1: current, 2: total */
				'message'    => sprintf( __( 'Checking %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
			) );
		} elseif ( 'unlink' === $action ) {
			// Bulk unlink: process one at a time like recheck.
			$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$total   = count( $link_ids );
			$link_id = isset( $link_ids[ $index ] ) ? $link_ids[ $index ] : 0;
			if ( ! $link_id ) {
				wp_send_json_success( array( 'done' => true, 'processed' => $total ) );
			}
			$link    = $this->db->get_link( $link_id );
			$type    = ( $link && isset( $link->link_type ) ) ? (string) $link->link_type : 'link';
			$ok      = false;
			if ( $link ) {
				if ( 'comment' === $type ) {
					$ok = $this->unlink_comment( $link );
				} else {
					$ok = $this->scanner->unlink_in_post( (int) $link->post_id, $link->link_url );
				}
				if ( $ok ) {
					$this->db->delete_link( $link_id );
				}
			}
			wp_send_json_success( array(
				'done'       => ( $index + 1 ) >= $total,
				'processed'  => $index + 1,
				'total'      => $total,
				'pct'        => (int) round( ( ( $index + 1 ) / $total ) * 100 ),
				'next_index' => $index + 1,
				'link_id'    => $link_id,
				'unlinked'   => $ok,
				/* translators: 1: current, 2: total */
				'message'    => sprintf( __( 'Unlinking %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
			) );
		} elseif ( 'not_broken' === $action ) {
			$processed = 0;
			foreach ( $link_ids as $lid ) {
				$this->db->mark_as_not_broken( $lid );
				$processed++;
			}
			/* translators: %d: count */
			wp_send_json_success( array( 'done' => true, 'processed' => $processed, 'message' => sprintf( __( '%d marked as OK.', 'tso-link-inspector' ), $processed ) ) );
		} else {
			$processed = 0;
			foreach ( $link_ids as $lid ) {
				$this->db->delete_link( $lid );
				$processed++;
			}
			/* translators: %d: count */
			wp_send_json_success( array( 'done' => true, 'processed' => $processed, 'message' => sprintf( __( '%d deleted.', 'tso-link-inspector' ), $processed ) ) );
		}
	}

	public function ajax_start_bg_check() {
		$this->check_nonce_and_cap();
		$resume = ! isset( $_POST['resume'] ) || '0' !== sanitize_text_field( wp_unslash( $_POST['resume'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->cron->start_bg_check( $resume );
		$bg = $this->cron->get_bg_progress();
		wp_send_json_success( array(
			'running' => true,
			'checked' => $bg['checked'],
			'total'   => $bg['total'],
			'pct'     => $bg['pct'],
			'message' => __( 'Check started. You can continue browsing.', 'tso-link-inspector' ),
		) );
	}

	public function ajax_stop_bg_check() {
		$this->check_nonce_and_cap();
		$this->cron->stop_bg_check();
		wp_send_json_success( array( 'running' => false ) );
	}

	public function ajax_check_progress() {
		$this->check_nonce_and_cap();
		$bg    = $this->cron->get_bg_progress();
		$stats = $this->db->get_stats();
		$done  = ! $bg['running'] && $bg['total'] > 0 && 0 === $this->db->get_unchecked_count();
		wp_send_json_success( array(
			'running' => $bg['running'],
			'checked' => $bg['checked'],
			'total'   => $bg['total'],
			'pct'     => $bg['pct'],
			'broken'  => $stats['broken'],
			'done'    => $done,
			/* translators: 1: checked, 2: total */
			'message' => $bg['running'] ? sprintf( __( 'Checking %1$d of %2$d...', 'tso-link-inspector' ), $bg['checked'], $bg['total'] ) : ( $done ? __( 'Check completed!', 'tso-link-inspector' ) : __( 'Stopped', 'tso-link-inspector' ) ),
		) );
	}

	public function ajax_smart_suggest() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link    = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'tso-link-inspector' ) ) );
		}

		$suggestions  = array();
		$seen_urls    = array( (string) $link->link_url );

		// If the DB already has a known redirect_url, offer it as first (instant) suggestion — except when
		// applying it would pin a “rolling release” download to one file version (handled as transparent redirect).
		if ( ! empty( $link->redirect_url ) ) {
			$rurl = (string) $link->redirect_url;
			if ( ! $this->http->is_transparent_redirect( (string) $link->link_url, $rurl ) ) {
				$suggestions[] = array(
					'url'        => $rurl,
					'status_code'=> (int) $link->status_code,
					'label'      => TSOLIIN_HTTP::status_label( (int) $link->status_code, (string) $link->link_url ),
					'reason'     => __( 'Destination detected (last check)', 'tso-link-inspector' ),
					'confidence' => 'high',
				);
				$seen_urls[] = $rurl;
			}
		}

		// Run smart suggest to find additional alternatives.
		foreach ( $this->http->smart_suggest( $link->link_url ) as $s ) {
			if ( ! in_array( $s['url'], $seen_urls, true ) ) {
				$suggestions[] = $s;
				$seen_urls[]   = $s['url'];
			}
		}

		wp_send_json_success( array(
			'link_id'     => $link_id,
			'original'    => $link->link_url,
			'suggestions' => $suggestions,
			'count'       => count( $suggestions ),
		) );
	}

	public function ajax_export_csv() {
		// GET request with nonce.
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'tsoliin_action' ) ) {
			wp_die( esc_html__( 'Nonce invalid.', 'tso-link-inspector' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-link-inspector' ) );
		}

		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		$result = $this->db->get_links( array( 'filter' => $filter, 'per_page' => 9999, 'paged' => 1 ) );

		$filename = 'tso-link-inspector-' . $filter . '-' . gmdate( 'Y-m-d' ) . '.csv';
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Pragma: no-cache' );

		$out = fopen( 'php://output', 'w' );
		// BOM per Excel.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite
		fwrite( $out, "\xEF\xBB\xBF" );
		// Header row.
		fputcsv( $out, array( 'URL', 'Text', 'Article', 'Estat', 'Codi', 'Redirecció', 'Comprovat' ), ';' );

		foreach ( $result['items'] as $item ) {
			fputcsv( $out, array(
				(string) $item->link_url,
				(string) $item->anchor_text,
				(string) ( isset( $item->post_title ) ? $item->post_title : '' ),
				(int) $item->is_broken ? 'Trencat' : ( (int) $item->status_code >= 301 && (int) $item->status_code < 400 ? 'Redirecció' : 'OK' ),
				(int) $item->status_code,
				(string) $item->redirect_url,
				(string) $item->last_checked,
			), ';' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $out );
		exit;
	}

	public function ajax_diagnose() {
		$this->check_nonce_and_cap();
		$info  = array();
		$test  = $this->db->self_test();
		$info[] = ( $test['table_exists'] ? 'OK' : 'ERR' ) . ' Taula BD: ' . $this->db->get_table();
		$info[] = ( $test['insert_ok'] ? 'OK' : 'ERR' ) . ' INSERT test' . ( $test['error'] ? ': ' . $test['error'] : '' );
		$pts    = $this->scanner->get_post_types();
		$info[] = 'OK Post types: ' . implode( ', ', $pts );
		$total  = $this->scanner->get_total_posts();
		$info[] = 'OK Posts publicats: ' . $total;
		$ids    = $this->scanner->get_post_ids( 1, 1 );
		if ( ! empty( $ids ) ) {
			$post   = get_post( $ids[0] );
			$info[] = 'OK Primer post: ' . $ids[0] . ' "' . esc_html( (string) $post->post_title ) . '"';
			$html   = do_blocks( $post->post_content );
			$links  = $this->scanner->extract_links( $html );
			$info[] = 'OK Links en primer post: ' . count( $links );
			$n      = $this->scanner->scan_post( $ids[0] );
			$info[] = 'OK scan_post(): ' . $n;
		}
		$stats  = $this->db->get_stats();
		$info[] = 'OK Registres BD: ' . $stats['total'];
		wp_send_json_success( array( 'lines' => $info ) );
	}
}
