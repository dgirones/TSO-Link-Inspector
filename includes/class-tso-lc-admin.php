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

	/** @var string */
	private $settings_page_hook = '';

	public function __construct( TSOLIIN_DB $db, TSOLIIN_Scanner $scanner, TSOLIIN_HTTP $http, TSOLIIN_Cron $cron ) {
		$this->db      = $db;
		$this->scanner = $scanner;
		$this->http    = $http;
		$this->cron    = $cron;

		add_action( 'admin_menu',             array( $this, 'register_menu' ) );
		add_action( 'admin_enqueue_scripts',  array( $this, 'enqueue_assets' ) );
		add_filter( 'admin_page_title',         array( $this, 'filter_settings_admin_page_title' ) );
		add_filter( 'plugin_row_meta',          array( $this, 'filter_plugin_row_meta' ), 10, 2 );

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
		add_action( 'wp_ajax_tsoliin_get_stats',       array( $this, 'ajax_get_stats' ) );
		add_action( 'wp_ajax_tsoliin_smart_suggest',   array( $this, 'ajax_smart_suggest' ) );
		add_action( 'wp_ajax_tsoliin_diagnose',        array( $this, 'ajax_diagnose' ) );
		add_action( 'wp_ajax_tsoliin_export_csv',      array( $this, 'ajax_export_csv' ) );
		add_action( 'wp_ajax_tsoliin_export_pdf',      array( $this, 'ajax_export_pdf' ) );
		add_action( 'wp_ajax_tsoliin_add_ignore',      array( $this, 'ajax_add_ignore' ) );
		add_action( 'wp_ajax_tsoliin_dismiss_onboarding', array( $this, 'ajax_dismiss_onboarding' ) );
		add_action( 'wp_ajax_tsoliin_make_relative',     array( $this, 'ajax_make_relative' ) );
		add_action( 'wp_ajax_tsoliin_link_preview',      array( $this, 'ajax_link_preview' ) );
		add_action( 'wp_ajax_tsoliin_search_list',       array( $this, 'ajax_search_list' ) );
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
		$this->settings_page_hook = add_submenu_page(
			'tools.php',
			__( 'TSO Link Inspector - Settings', 'tso-link-inspector' ),
			__( 'Settings', 'tso-link-inspector' ),
			'manage_options',
			'tso-link-inspector-settings',
			array( $this, 'render_settings_page' )
		);
		// Keep Settings accessible by URL but hidden from the Tools submenu.
		remove_submenu_page( 'tools.php', 'tso-link-inspector-settings' );

		if ( $this->settings_page_hook ) {
			add_action( 'load-' . $this->settings_page_hook, array( $this, 'prepare_settings_screen' ) );
		}
	}

	/**
	 * Whether the current request is the plugin settings screen.
	 *
	 * @return bool
	 */
	private function is_settings_screen() {
		global $pagenow;
		if ( ! is_admin() || 'tools.php' !== $pagenow ) {
			return false;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'] ) && 'tso-link-inspector-settings' === sanitize_key( wp_unslash( $_GET['page'] ) );
	}

	/**
	 * Ensure admin header receives a string title (avoids strip_tags(null) on hidden submenu pages).
	 *
	 * @param string|null $page_title Title from core.
	 * @return string
	 */
	public function filter_settings_admin_page_title( $page_title ) {
		if ( ! $this->is_settings_screen() ) {
			return is_string( $page_title ) ? $page_title : '';
		}
		return __( 'TSO Link Inspector - Settings', 'tso-link-inspector' );
	}

	/**
	 * Add a Donate link on the Plugins screen.
	 *
	 * @param string[] $links Plugin row links.
	 * @param string   $file  Plugin basename.
	 * @return string[]
	 */
	public function filter_plugin_row_meta( $links, $file ) {
		if ( plugin_basename( TSOLIIN_PLUGIN_FILE ) !== $file ) {
			return $links;
		}
		$links[] = sprintf(
			'<a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
			esc_url( TSOLIIN_Support::get_kofi_donate_url() ),
			esc_html( TSOLIIN_Support::get_donate_link_label() )
		);
		return $links;
	}

	/**
	 * Set globals before admin-header.php runs on the hidden settings page.
	 */
	public function prepare_settings_screen() {
		global $title, $parent_file, $submenu_file;
		$title        = __( 'TSO Link Inspector - Settings', 'tso-link-inspector' );
		$parent_file  = 'tools.php';
		$submenu_file = 'tso-link-inspector';
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list_filter  = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : 'all';
		if ( in_array( $list_filter, $this->get_allowed_quality_filters(), true ) ) {
			$list_filter = 'all';
		} elseif ( ! in_array( $list_filter, $this->get_allowed_status_filters(), true ) ) {
			$list_filter = 'all';
		}
		$list_quality = $this->get_list_quality_filter_from_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list_scope = $this->get_list_scope_from_request();

		$user_id = get_current_user_id();

		wp_localize_script( 'tsoliin-admin', 'tsoliinData', array(
			'ajaxUrl'    => admin_url( 'admin-ajax.php' ),
			'nonce'      => wp_create_nonce( 'tsoliin_action' ),
			'viewPostId' => $view_post_id,
			'listFilter'        => $list_filter,
			'listQualityFilter' => $list_quality,
			'listScope'         => $list_scope,
			'listOrderby' => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'date_found', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'listOrder'   => isset( $_GET['order'] ) ? strtoupper( sanitize_key( wp_unslash( $_GET['order'] ) ) ) : 'DESC', // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			'settingsUrl' => admin_url( 'tools.php?page=tso-link-inspector-settings' ),
			'helpUrl'     => admin_url( 'tools.php?page=tso-link-inspector-settings&tab=help' ),
			'onboardingDismissed' => (int) (bool) get_user_meta( $user_id, 'tsoliin_onboarding_dismissed', true ),
			'relativeUrlTool'     => TSOLIIN_Support::is_relative_url_tool_enabled() ? 1 : 0,
			'createRevision'      => TSOLIIN_Support::is_create_revision_enabled() ? 1 : 0,
			'brokenFilterUrl' => admin_url( 'tools.php?page=tso-link-inspector&filter=broken' ),
			'filterTabs' => array(
				/* translators: %s: number of links */
				'all'           => __( 'All (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of broken links */
				'broken'        => __( 'Broken (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of redirected links */
				'redirect'      => __( 'Redirect (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of OK links */
				'ok'            => __( 'OK (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of unchecked links */
				'unchecked'     => __( 'Unchecked (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of HTTP insecure links */
				'http_insecure' => __( 'HTTP insecure (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of manually locked links */
				'manual_locked' => __( 'Manual locks (%s)', 'tso-link-inspector' ),
			),
			'qualityFilterTabs' => array(
				/* translators: %s: number of links with empty anchor text */
				'empty_anchor'       => __( 'Empty anchor (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of links with generic anchor text */
				'generic_anchor'     => __( 'Generic anchor (%s)', 'tso-link-inspector' ),
				/* translators: %s: number of links to unpublished posts */
				'unpublished_target' => __( 'Unpublished target (%s)', 'tso-link-inspector' ),
			),
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
				'scanNow'       => __( 'Scan now', 'tso-link-inspector' ),
				'checkNow'      => __( 'Check now', 'tso-link-inspector' ),
				'checkThisPost' => __( 'Check this post', 'tso-link-inspector' ),
				'stop'          => __( 'Stop', 'tso-link-inspector' ),
				'stopScan'      => __( 'Stop scan', 'tso-link-inspector' ),
				'scanStopped'   => __( 'Scan stopped.', 'tso-link-inspector' ),
				'scanThenCheck' => __( 'Scan complete. Starting HTTP check…', 'tso-link-inspector' ),
				'confirmFullCheck' => __( 'Check now will send an HTTP request to every link saved in the database. On large sites this can take a long time. Continue?', 'tso-link-inspector' ),
				'confirmScanWhileCheck' => __( 'A background check is still running. Start a new scan anyway?', 'tso-link-inspector' ),
				'confirmCheckWhileScan' => __( 'A scan is still running. Start checking anyway?', 'tso-link-inspector' ),
				'recheck'       => __( 'Recheck', 'tso-link-inspector' ),
				'saving'        => __( 'Saving...', 'tso-link-inspector' ),
				'urlSaved'      => __( 'URL updated successfully.', 'tso-link-inspector' ),
				'urlUpdated'    => __( 'URL updated:', 'tso-link-inspector' ),
				'notBrokenDone' => __( 'Marked as OK and moved to Manual locks. It leaves that list only if the URL or redirect changes, or a check finds it broken.', 'tso-link-inspector' ),
				'confirmNotBroken' => __( 'Mark this link as OK?', 'tso-link-inspector' ) . "\n\n"
					. __( '• It moves to Manual locks and leaves the Broken/Redirect lists.', 'tso-link-inspector' ) . "\n"
					. __( '• Background checks (Check now / cron) still run.', 'tso-link-inspector' ) . "\n"
					. __( '• It returns to the normal lists only if the URL or redirect changes, or a check finds it broken again.', 'tso-link-inspector' ),
				'confirmNotBrokenBulk' => __( 'Mark the selected links as OK?', 'tso-link-inspector' ) . "\n\n"
					. __( 'They move to Manual locks and leave the Broken/Redirect lists. Background checks still run. They return to the normal lists only if the URL or redirect changes, or a check finds them broken again.', 'tso-link-inspector' ),
				'diagnosi'      => __( 'Diagnostics', 'tso-link-inspector' ),
				'diagChecking'  => __( 'Running diagnostics...', 'tso-link-inspector' ),
				'diagResult'    => __( 'Diagnostics result:', 'tso-link-inspector' ),
				'smartChecking' => __( 'Looking for alternatives...', 'tso-link-inspector' ),
				'smartSuggest'  => __( 'Suggested URL', 'tso-link-inspector' ),
				'noSuggestions'    => __( 'No working alternative was found for this link.', 'tso-link-inspector' ),
				'detectedRedirect' => __( 'Redirect destination already detected', 'tso-link-inspector' ),
				'applyUrl'      => __( 'Apply', 'tso-link-inspector' ),
				'itemsChecked'  => __( 'links rechecked.', 'tso-link-inspector' ),
				'itemsUnlinked' => __( 'links unlinked.', 'tso-link-inspector' ),
				'itemsSkipped'  => __( 'rows skipped (menu/widget/term).', 'tso-link-inspector' ),
				'itemsFailed'   => __( 'requests failed.', 'tso-link-inspector' ),
				'unlinking'     => __( 'Unlinking…', 'tso-link-inspector' ),
				'confirmUnlink' => __( 'Are you sure? The text will remain but the link will be removed.', 'tso-link-inspector' ),
				'confirmDelete' => __( 'Delete this record from the list. The post will not be changed. Continue?', 'tso-link-inspector' ),
				'error'         => __( 'An error occurred.', 'tso-link-inspector' ),
				'save'          => __( 'Save URL', 'tso-link-inspector' ),
				'cancel'        => __( 'Cancel', 'tso-link-inspector' ),
				'notBroken'     => __( 'Not broken', 'tso-link-inspector' ),
				'rechecking'    => __( 'Rechecking…', 'tso-link-inspector' ),
				'urlRequired'   => __( 'Please enter a valid URL.', 'tso-link-inspector' ),
				'noChanges'     => __( 'No changes to save.', 'tso-link-inspector' ),
				'editLink'      => __( 'Edit link', 'tso-link-inspector' ),
				'linkText'      => __( 'Link text:', 'tso-link-inspector' ),
				'commentLabel'  => __( 'Link text in comment (read-only):', 'tso-link-inspector' ),
				'commentLabelNote' => __( 'Only the URL can be changed here. To edit the visible link text, open the comment in WordPress.', 'tso-link-inspector' ),
				'altText'       => __( 'Alt text:', 'tso-link-inspector' ),
				'anchorWarning' => __( 'URL updated, but link text could not be changed in the post. Edit the post manually or leave the link text field unchanged next time.', 'tso-link-inspector' ),
				'unlink'        => __( 'Unlink', 'tso-link-inspector' ),
				'closePanel'    => __( 'Close', 'tso-link-inspector' ),
				'actionUrlWarn' => __( 'This link ends your WordPress session. Open it anyway?', 'tso-link-inspector' ),
				'openUrl'       => __( 'Open URL', 'tso-link-inspector' ),
				'addIgnore'     => __( 'Ignore domain', 'tso-link-inspector' ),
				/* translators: %s: domain or URL pattern added to the ignore list */
				'confirmAddIgnore' => __( 'Add %s to the ignore list? This link will be skipped during scans and HTTP checks. You can edit the list in Settings.', 'tso-link-inspector' ),
				'ignoreAdded'   => __( 'Added to ignore list. This link is now skipped.', 'tso-link-inspector' ),
				'ignoreAlready' => __( 'This domain or URL is already on the ignore list.', 'tso-link-inspector' ),
				'ignoreFailed'  => __( 'Could not derive an ignore pattern from this URL.', 'tso-link-inspector' ),
				'onboardingDismiss' => __( 'Dismiss', 'tso-link-inspector' ),
				'onboardingHelp'    => __( 'Read help', 'tso-link-inspector' ),
				'makeRelative'      => __( 'Convert to /path', 'tso-link-inspector' ),
				'confirmMakeRelative' => __( 'Remove the site domain from this link? It will be saved as a path starting with / (e.g. /contact/). Only for links on this site.', 'tso-link-inspector' ),
				'confirmMakeRelativeBulk' => __( 'Remove the site domain from the selected same-site links and save them as /path URLs?', 'tso-link-inspector' ),
				'relativeDone'      => __( 'Link saved as /path (domain removed).', 'tso-link-inspector' ),
				'relativeDisabled'  => __( 'Enable “Convert to /path” in Settings first.', 'tso-link-inspector' ),
				'convertingRelative'=> __( 'Converting to /path…', 'tso-link-inspector' ),
				'itemsConverted'    => __( 'links converted to /path.', 'tso-link-inspector' ),
				'confirmDeleteBulk' => __( 'Delete the selected records from the list? The posts will not be changed.', 'tso-link-inspector' ),
				'previewLoading'    => __( 'Loading preview...', 'tso-link-inspector' ),
				'previewNotFound'   => __( 'No matching HTML tag found in the post for this URL.', 'tso-link-inspector' ),
				'revisionModalNote' => __( 'A WordPress revision will be saved when you save, so you can restore the previous post content from the post editor (Revisions panel).', 'tso-link-inspector' ),
				'revisionSaved'     => __( 'A post revision was saved. Open the article in the editor to view or restore it under Revisions.', 'tso-link-inspector' ),
				'searching'         => __( 'Searching...', 'tso-link-inspector' ),
				'searchBtn'         => __( 'Search', 'tso-link-inspector' ),
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view_post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0;
		$view_post    = $view_post_id ? get_post( $view_post_id ) : null;
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$list_view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'links';
		$posts_view = ( 'posts' === $list_view && ! $view_post_id );

		$table = null;
		if ( ! $posts_view ) {
			$table = new TSOLIIN_List_Table( $this->db, $this->http );
			$table->prepare_items();
		}

		// Detect if we are viewing a single post's links.
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

		echo '<div class="tsoliin-page-head">';
		echo '<h1 class="wp-heading-inline">';
		echo '<span class="dashicons dashicons-admin-links tsoliin-title-icon"></span> ';
		if ( $view_post ) {
			$this->render_plugin_title_with_version( true );
			echo ' <span style="color:#646970;">&#8250;</span> ';
			echo esc_html( $view_post->post_title );
		} elseif ( $posts_view ) {
			$this->render_plugin_title_with_version( true );
			echo ' <span style="color:#646970;">&#8250;</span> ';
			echo esc_html__( 'Posts with issues', 'tso-link-inspector' );
		} else {
			$this->render_plugin_title_with_version( false );
		}
		echo '</h1>';
		TSOLIIN_Support::render_donate_button();
		echo '</div>';
		echo '<hr class="wp-header-end">';

		$this->render_onboarding_banner();

		// Stats cards.
		echo '<div class="tsoliin-stats">';
		$this->stat_card( TSOLIIN_Support::format_display_number( $stats['total'] ),   __( 'Total', 'tso-link-inspector' ),    '' );
		$this->stat_card( TSOLIIN_Support::format_display_number( $stats['broken'] ),  __( 'Broken', 'tso-link-inspector' ), 'broken' );
		$this->stat_card( TSOLIIN_Support::format_display_number( $stats['redirect'] ),__( 'Redirect', 'tso-link-inspector' ),'redirect' );
		$this->stat_card( TSOLIIN_Support::format_display_number( $stats['ok'] ),      __( 'OK', 'tso-link-inspector' ),'ok' );
		$this->stat_card( TSOLIIN_Support::format_display_number( $stats['unchecked'] ), __( 'Unchecked', 'tso-link-inspector' ), 'unchecked', __( 'Links found but not checked by HTTP yet. Cron or Check now will verify them.', 'tso-link-inspector' ) );
		$http_insecure_count = isset( $stats['http_insecure'] ) ? $stats['http_insecure'] : 0;
		if ( $http_insecure_count > 0 ) {
			$this->stat_card( TSOLIIN_Support::format_display_number( $http_insecure_count ), __( 'HTTP insecure', 'tso-link-inspector' ), 'http-insecure', __( 'Active links using HTTP. Redirecting HTTP links are listed here first; after HTTPS they move to Redirect.', 'tso-link-inspector' ) );
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
		$schedule         = TSOLIIN_Schedule::get_settings();
		$queue            = TSOLIIN_Schedule::get_queue_stats( $this->db );
		$pending_count    = (int) $queue['pending'];
		$ok_days          = (int) $schedule['recheck_days'];
		$broken_days      = (int) $schedule['broken_recheck_days'];
		$checks_per_day   = (int) $queue['checks_per_day'];
		$est_days         = (int) $queue['est_days'];

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
			$queue_tip = sprintf(
				/* translators: 1: never-checked count, 2: broken stale count, 3: OK stale count, 4: OK recheck days, 5: broken recheck days, 6: checks per day, 7: estimated days to clear queue */
				__( 'Queue: %1$d never checked, %2$d broken (older than %5$d days), %3$d OK (older than %4$d days). Throughput: ~%6$d checks/day. Estimated time to clear queue: ~%7$d days.', 'tso-link-inspector' ),
				(int) $queue['unchecked'],
				(int) $queue['broken_stale'],
				(int) $queue['ok_stale'],
				$ok_days,
				$broken_days,
				$checks_per_day,
				max( 1, $est_days )
			);
			echo ' | ';
			printf(
				'<span title="%s">%s</span>',
				esc_attr( $queue_tip ),
				esc_html( sprintf(
					/* translators: %d: count */
					__( '%d in check queue', 'tso-link-inspector' ),
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
		if ( $view_post_id ) {
			$btn_check_label = $bg['running'] ? __( 'Checking...', 'tso-link-inspector' ) : __( 'Check this post', 'tso-link-inspector' );
		} else {
			$btn_check_label = $bg['running'] ? __( 'Checking...', 'tso-link-inspector' ) : __( 'Check now', 'tso-link-inspector' );
		}
		$check_prog_display = $bg['running'] ? 'block' : 'none';
		$check_prog_pct     = $bg['pct'];
		$stop_check_style   = $bg['running'] ? '' : ' style="display:none;"';

		echo '<div class="tsoliin-toolbar">';
		echo '<button type="button" id="tsoliin-start-scan" class="button button-primary">';
		echo '<span class="dashicons dashicons-search"></span> ';
		echo esc_html__( 'Scan now', 'tso-link-inspector' );
		echo '</button>';
		echo '<button type="button" id="tsoliin-stop-scan" class="button button-secondary" style="display:none;">';
		echo '<span class="dashicons dashicons-no-alt"></span> ';
		echo esc_html__( 'Stop scan', 'tso-link-inspector' );
		echo '</button>';
		echo '<button type="button" id="tsoliin-start-check" class="button tsoliin-btn-check"' . $btn_check_disabled . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="dashicons dashicons-yes-alt"></span> ';
		echo esc_html( $btn_check_label );
		echo '</button>';
		echo '<button type="button" id="tsoliin-stop-check" class="button button-secondary"' . $stop_check_style . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		echo '<span class="dashicons dashicons-no-alt"></span> ';
		echo esc_html__( 'Stop', 'tso-link-inspector' );
		echo '</button>';
		echo '<button type="button" id="tsoliin-diagnose" class="button button-secondary" aria-expanded="false" aria-controls="tsoliin-diagnose-panel" title="' . esc_attr__( 'Quick health check when scan or check fails: database, settings, and one sample post.', 'tso-link-inspector' ) . '">';
		echo '<span class="dashicons dashicons-info"></span> ';
		echo esc_html__( 'Diagnostics', 'tso-link-inspector' );
		echo '</button>';
		// Export CSV button.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_filter  = isset( $_REQUEST['filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter'] ) ) : 'all';
		if ( in_array( $export_filter, $this->get_allowed_quality_filters(), true ) ) {
			$export_filter = 'all';
		} elseif ( ! in_array( $export_filter, $this->get_allowed_status_filters(), true ) ) {
			$export_filter = 'all';
		}
		$export_quality = isset( $_REQUEST['quality_filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['quality_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! in_array( $export_quality, $this->get_allowed_quality_filters(), true ) ) {
			$export_quality = '';
		}
		// Legacy URLs stored quality in filter=.
		if ( '' === $export_quality && in_array( sanitize_key( wp_unslash( (string) ( $_REQUEST['filter'] ?? '' ) ) ), $this->get_allowed_quality_filters(), true ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$export_quality = sanitize_key( wp_unslash( (string) $_REQUEST['filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		$export_scope = $this->get_scope_from_request();
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$export_search = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		$export_nonce  = wp_create_nonce( 'tsoliin_action' );
		$export_args   = array(
			'action' => 'tsoliin_export_csv',
			'filter' => $export_filter,
			'nonce'  => $export_nonce,
		);
		if ( '' !== $export_quality ) {
			$export_args['quality_filter'] = $export_quality;
		}
		if ( 'all' !== $export_scope ) {
			$export_args['scope'] = $export_scope;
		}
		if ( $view_post_id ) {
			$export_args['post_id'] = $view_post_id;
		}
		if ( '' !== $export_search ) {
			$export_args['s'] = $export_search;
		}
		echo '<a href="' . esc_url( add_query_arg( $export_args, admin_url( 'admin-ajax.php' ) ) ) . '" class="button button-secondary" id="tsoliin-export-csv">';
		echo '<span class="dashicons dashicons-download"></span> ';
		echo esc_html__( 'Export CSV', 'tso-link-inspector' );
		echo '</a>';
		$pdf_args           = $export_args;
		$pdf_args['action'] = 'tsoliin_export_pdf';
		echo '<a href="' . esc_url( add_query_arg( $pdf_args, admin_url( 'admin-ajax.php' ) ) ) . '" class="button button-secondary" id="tsoliin-export-pdf" target="_blank" rel="noopener noreferrer">';
		echo '<span class="dashicons dashicons-media-document"></span> ';
		echo esc_html__( 'Export report (PDF)', 'tso-link-inspector' );
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
		echo '<p class="tsoliin-toolbar-help">';
		echo '<span class="tsoliin-toolbar-help__scan"><strong>' . esc_html__( 'Scan:', 'tso-link-inspector' ) . '</strong> ';
		echo esc_html__( 'Reads your content and adds links to this list. It does not test whether URLs work yet.', 'tso-link-inspector' );
		echo '</span> ';
		echo '<span class="tsoliin-toolbar-help__check"><strong>' . esc_html__( 'Check:', 'tso-link-inspector' ) . '</strong> ';
		if ( $view_post_id ) {
			echo esc_html__( 'Tests each saved URL in this post over HTTP. Use Stop to cancel.', 'tso-link-inspector' );
		} else {
			echo esc_html__( 'Tests every saved URL on the site over HTTP (can take a while). Use Stop to cancel. After editing one post, open its link list or use Recheck on a row.', 'tso-link-inspector' );
		}
		echo '</span></p>';

		echo '<div id="tsoliin-diagnose-panel" class="tsoliin-diagnose-panel" style="display:none;"></div>';

		// ── Secondary action bar: context buttons + search ────────────────
		echo '<div class="tsoliin-action-bar">';
		echo '<div class="tsoliin-action-bar__left">';
		echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector-settings' ) ) . '" class="button button-secondary">' . esc_html__( 'Settings', 'tso-link-inspector' ) . '</a> ';
		echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector-settings&tab=help' ) ) . '" class="button button-secondary">' . esc_html__( 'Help', 'tso-link-inspector' ) . '</a> ';
		if ( ! $view_post ) {
			if ( $posts_view ) {
				echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '" class="button button-secondary">&#8592; ' . esc_html__( 'All links', 'tso-link-inspector' ) . '</a> ';
			} else {
				echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector&view=posts' ) ) . '" class="button button-secondary">' . esc_html__( 'Posts with issues', 'tso-link-inspector' ) . '</a> ';
			}
		}
		if ( $view_post ) {
			echo '<a href="' . esc_url( (string) get_edit_post_link( $view_post_id ) ) . '" class="button button-secondary" target="_blank">' . esc_html__( 'Edit post', 'tso-link-inspector' ) . '</a> ';
			echo '<a href="' . esc_url( (string) get_permalink( $view_post_id ) ) . '" class="button button-secondary" target="_blank">' . esc_html__( 'View post', 'tso-link-inspector' ) . '</a> ';
			echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '" class="button button-secondary">&#8592; ' . esc_html__( 'Back', 'tso-link-inspector' ) . '</a>';
		}
		echo '</div>';
		echo '<div class="tsoliin-action-bar__right">';
		if ( ! $posts_view ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search_val = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$filter_val = isset( $_REQUEST['filter'] ) ? sanitize_key( wp_unslash( $_REQUEST['filter'] ) ) : 'all';
		if ( in_array( $filter_val, $this->get_allowed_quality_filters(), true ) ) {
			$filter_val = 'all';
		} elseif ( ! in_array( $filter_val, $this->get_allowed_status_filters(), true ) ) {
			$filter_val = 'all';
		}
		$quality_val = $this->get_list_quality_filter_from_request();
		echo '<form method="get" id="tsoliin-search-form" class="tsoliin-search-form" style="display:inline-flex;gap:4px;align-items:center;">';
		echo '<input type="hidden" name="page" value="tso-link-inspector" />';
		if ( $view_post_id ) { echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $view_post_id ) . '" />'; }
		if ( 'all' !== $filter_val ) {
			echo '<input type="hidden" name="filter" value="' . esc_attr( $filter_val ) . '" />';
		}
		if ( '' !== $quality_val ) {
			echo '<input type="hidden" name="quality_filter" value="' . esc_attr( $quality_val ) . '" />';
		}
		$scope_search = $this->get_scope_from_request();
		if ( 'all' !== $scope_search ) {
			echo '<input type="hidden" name="scope" value="' . esc_attr( $scope_search ) . '" />';
		}
		echo '<input type="search" name="s" value="' . esc_attr( $search_val ) . '" placeholder="' . esc_attr__( 'Search URLs...', 'tso-link-inspector' ) . '" class="tsoliin-search-input" autocomplete="off" aria-controls="tsoliin-list-table-region" />';
		echo '<button type="submit" class="button">' . esc_html__( 'Search', 'tso-link-inspector' ) . '</button>';
		echo '</form>';
		}
		echo '</div>';
		echo '</div>';

		if ( $posts_view ) {
			$this->render_posts_summary_view();
		} else {
			$scope_val = $this->get_scope_from_request();
			// Table (form without search_box since search is in action bar above).
			echo '<form id="tsoliin-list-form" method="get">';
			echo '<div id="tsoliin-list-table-region" class="tsoliin-list-table-region">';
			$this->render_list_table_region( $table, $view_post_id, $filter_val, $scope_val, $search_val );
			echo '</div>';
			echo '</form>';
		}

		// Edit modal (links list only).
		if ( ! $posts_view ) {
		echo '<div id="tsoliin-modal" class="tsoliin-modal" style="display:none;" role="dialog" aria-modal="true">';
		echo '<div class="tsoliin-modal__overlay"></div>';
		echo '<div class="tsoliin-modal__content">';
		echo '<h2>' . esc_html__( 'Edit link', 'tso-link-inspector' ) . '</h2>';
		echo '<p><label>' . esc_html__( 'Current URL:', 'tso-link-inspector' ) . '</label><code id="tsoliin-modal-old-url"></code></p>';
		echo '<p><label for="tsoliin-new-url">' . esc_html__( 'New URL:', 'tso-link-inspector' ) . '</label>';
		echo '<input type="text" id="tsoliin-new-url" class="widefat" placeholder="https:// or /relative/path" /></p>';
		echo '<div id="tsoliin-modal-preview" class="tsoliin-modal-preview" style="display:none;" aria-live="polite">';
		echo '<p class="description">' . esc_html__( 'HTML preview (only the matched tag attribute will change):', 'tso-link-inspector' ) . '</p>';
		echo '<div class="tsoliin-preview-grid">';
		echo '<div><strong>' . esc_html__( 'Before', 'tso-link-inspector' ) . '</strong><code id="tsoliin-preview-before" class="tsoliin-preview-snippet"></code></div>';
		echo '<div><strong>' . esc_html__( 'After', 'tso-link-inspector' ) . '</strong><code id="tsoliin-preview-after" class="tsoliin-preview-snippet"></code></div>';
		echo '</div></div>';
		echo '<p id="tsoliin-modal-revision-note" class="description tsoliin-modal-revision-note" style="display:none;"></p>';
		echo '<p id="tsoliin-modal-anchor-row"><label for="tsoliin-new-anchor" id="tsoliin-modal-anchor-label">' . esc_html__( 'Link text:', 'tso-link-inspector' ) . '</label>';
		echo '<input type="text" id="tsoliin-new-anchor" class="widefat" />';
		echo '<span id="tsoliin-modal-anchor-note" class="description tsoliin-modal-anchor-note" style="display:none;"></span></p>';
		echo '<div class="tsoliin-modal__actions">';
		echo '<button type="button" id="tsoliin-modal-save" class="button button-primary">' . esc_html__( 'Save URL', 'tso-link-inspector' ) . '</button>';
		echo '<button type="button" id="tsoliin-modal-cancel" class="button">' . esc_html__( 'Cancel', 'tso-link-inspector' ) . '</button>';
		echo '<span class="tsoliin-modal__spinner spinner"></span>';
		echo '</div>';
		echo '<div id="tsoliin-modal-feedback" class="tsoliin-modal__feedback"></div>';
		echo '</div></div>';
		}

		echo '</div>';
	}

	/**
	 * Render grouped post summary (broken / redirect counts per article).
	 */
	private function render_posts_summary_view() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$paged    = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		$per_page = 20;
		$result   = $this->db->get_posts_link_summary(
			array(
				'per_page' => $per_page,
				'paged'    => $paged,
			)
		);
		$total_pages = max( 1, (int) ceil( $result['total'] / $per_page ) );

		echo '<p class="description">' . esc_html__( 'Articles sorted by broken links, then redirects. Click a title to review and fix links in that post.', 'tso-link-inspector' ) . '</p>';
		echo '<table class="widefat striped tsoliin-posts-summary">';
		echo '<thead><tr>';
		echo '<th>' . esc_html__( 'Post', 'tso-link-inspector' ) . '</th>';
		echo '<th class="column-num">' . esc_html__( 'Broken', 'tso-link-inspector' ) . '</th>';
		echo '<th class="column-num">' . esc_html__( 'Redirect', 'tso-link-inspector' ) . '</th>';
		echo '<th class="column-num">' . esc_html__( 'Unchecked', 'tso-link-inspector' ) . '</th>';
		echo '<th class="column-num">' . esc_html__( 'Total links', 'tso-link-inspector' ) . '</th>';
		echo '</tr></thead><tbody>';

		if ( empty( $result['items'] ) ) {
			echo '<tr><td colspan="5"><em>' . esc_html__( 'No posts with broken or redirected links.', 'tso-link-inspector' ) . '</em></td></tr>';
		} else {
			foreach ( $result['items'] as $row ) {
				$post_id = absint( $row->post_id );
				$url     = add_query_arg( 'post_id', $post_id, admin_url( 'tools.php?page=tso-link-inspector' ) );
				echo '<tr>';
				echo '<td><a href="' . esc_url( $url ) . '"><strong>' . esc_html( (string) $row->post_title ) . '</strong></a></td>';
				echo '<td class="column-num">' . esc_html( TSOLIIN_Support::format_display_number( (int) $row->broken ) ) . '</td>';
				echo '<td class="column-num">' . esc_html( TSOLIIN_Support::format_display_number( (int) $row->redirect_count ) ) . '</td>';
				echo '<td class="column-num">' . esc_html( TSOLIIN_Support::format_display_number( (int) $row->unchecked_count ) ) . '</td>';
				echo '<td class="column-num">' . esc_html( TSOLIIN_Support::format_display_number( (int) $row->total_links ) ) . '</td>';
				echo '</tr>';
			}
		}
		echo '</tbody></table>';

		if ( $total_pages > 1 ) {
			$page_links = paginate_links(
				array(
					'base'      => add_query_arg(
						array(
							'page'  => 'tso-link-inspector',
							'view'  => 'posts',
							'paged' => '%#%',
						),
						admin_url( 'tools.php' )
					),
					'format'    => '',
					'prev_text' => '&laquo;',
					'next_text' => '&raquo;',
					'total'     => $total_pages,
					'current'   => $paged,
				)
			);
			if ( $page_links ) {
				echo '<div class="tablenav"><div class="tablenav-pages">' . wp_kses_post( $page_links ) . '</div></div>';
			}
		}
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
	 * Output the plugin title with the current version badge.
	 *
	 * @param bool $wrap_link When true, wrap the title in a link to the main page.
	 */
	private function render_plugin_title_with_version( $wrap_link = false ) {
		$title = __( 'TSO Link Inspector', 'tso-link-inspector' );
		if ( $wrap_link ) {
			echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo esc_html( $title );
		}
		$this->echo_plugin_version_badge();
	}

	/**
	 * Output the current plugin version next to the page title.
	 */
	private function echo_plugin_version_badge() {
		echo ' <span class="tsoliin-version">' . esc_html( TSOLIIN_VERSION ) . '</span>';
	}

	/**
	 * First-visit onboarding banner (dismissible per user).
	 */
	private function render_onboarding_banner() {
		if ( get_user_meta( get_current_user_id(), 'tsoliin_onboarding_dismissed', true ) ) {
			return;
		}

		$help_url    = admin_url( 'tools.php?page=tso-link-inspector-settings&tab=help' );
		$broken_url  = admin_url( 'tools.php?page=tso-link-inspector&filter=broken' );
		$settings_url = admin_url( 'tools.php?page=tso-link-inspector-settings' );

		echo '<div id="tsoliin-onboarding" class="notice notice-info tsoliin-onboarding">';
		echo '<p><strong>' . esc_html__( 'Getting started', 'tso-link-inspector' ) . '</strong></p>';
		echo '<ol class="tsoliin-onboarding__steps">';
		echo '<li><strong>' . esc_html__( 'Scan now', 'tso-link-inspector' ) . '</strong> — ';
		echo esc_html__( 'reads your content and adds links to this list. It does not test whether URLs work yet.', 'tso-link-inspector' );
		echo '</li><li><strong>' . esc_html__( 'Check now', 'tso-link-inspector' ) . '</strong> — ';
		echo esc_html__( 'sends HTTP requests to every saved URL (runs in the background; you can close this tab).', 'tso-link-inspector' );
		echo '</li><li><strong>' . esc_html__( 'Review Broken', 'tso-link-inspector' ) . '</strong> — ';
		echo esc_html__( 'open the Broken filter and fix links with Edit link, Suggestion, or Not broken.', 'tso-link-inspector' );
		echo '</li></ol>';
		echo '<p class="tsoliin-onboarding__links">';
		echo '<a href="' . esc_url( $broken_url ) . '" class="button button-secondary">' . esc_html__( 'Open Broken links', 'tso-link-inspector' ) . '</a> ';
		echo '<a href="' . esc_url( $help_url ) . '" class="button button-link">' . esc_html__( 'Read help', 'tso-link-inspector' ) . '</a> ';
		echo '<a href="' . esc_url( $settings_url ) . '" class="button button-link">' . esc_html__( 'Settings', 'tso-link-inspector' ) . '</a>';
		echo '</p>';
		echo '<button type="button" class="notice-dismiss tsoliin-onboarding-dismiss" aria-label="' . esc_attr__( 'Dismiss', 'tso-link-inspector' ) . '"></button>';
		echo '</div>';
	}

	/**
	 * Settings / Help tab navigation.
	 *
	 * @param string $active_tab Active tab slug.
	 */
	private function render_settings_nav_tabs( $active_tab ) {
		$base   = admin_url( 'tools.php?page=tso-link-inspector-settings' );
		$tabs   = array(
			'settings' => __( 'Settings', 'tso-link-inspector' ),
			'help'     => __( 'Help', 'tso-link-inspector' ),
		);
		echo '<nav class="nav-tab-wrapper tsoliin-settings-tabs" aria-label="' . esc_attr__( 'Settings sections', 'tso-link-inspector' ) . '">';
		foreach ( $tabs as $slug => $label ) {
			$url    = 'help' === $slug ? add_query_arg( 'tab', 'help', $base ) : $base;
			$active = $active_tab === $slug ? ' nav-tab-active' : '';
			echo '<a href="' . esc_url( $url ) . '" class="nav-tab' . esc_attr( $active ) . '">' . esc_html( $label ) . '</a>';
		}
		echo '</nav>';
	}

	/**
	 * Help tab content (FAQ).
	 */
	private function render_settings_help_tab() {
		$settings_url = admin_url( 'tools.php?page=tso-link-inspector-settings' );
		$list_url     = admin_url( 'tools.php?page=tso-link-inspector' );
		echo '<div class="tsoliin-help-panel">';

		echo '<h2>' . esc_html__( 'How it works', 'tso-link-inspector' ) . '</h2>';
		echo '<dl class="tsoliin-help-dl">';

		echo '<dt>' . esc_html__( 'Scan vs Check', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Scan finds links in posts, comments, menus, widgets, custom fields, and other enabled sources, then saves them in the database. Check sends HTTP requests to test whether each URL works. Run Scan after editing content; run Check when you want fresh status codes.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Automatic checks (cron)', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'A full Scan runs once per day. HTTP checks run hourly in configurable batches via WP-Cron. When a stored URL no longer appears in WordPress, the cron re-reads that source once before checking (or removes the row if the link is gone). Priority order: never-checked links first, then broken links past the broken recheck interval, then OK links past the OK recheck interval. The main dashboard shows the check queue, throughput, and next scheduled run. Adjust batch size and intervals in Settings.', 'tso-link-inspector' );
		echo ' ' . esc_html__( 'WP-Cron only runs when your site receives visits. On low-traffic or cached sites, schedule a server cron job to call wp-cron.php every hour for reliable automatic checks.', 'tso-link-inspector' );
		echo '</dd>';

		echo '<dt>' . esc_html__( 'Posts with issues', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>';
		echo esc_html__( 'Open Posts with issues from the main screen to see articles that contain broken, redirected, or unchecked links. Click a post to filter the list to that post only.', 'tso-link-inspector' );
		echo ' <a href="' . esc_url( add_query_arg( 'view', 'posts', $list_url ) ) . '">' . esc_html__( 'Open posts view', 'tso-link-inspector' ) . '</a>';
		echo '</dd>';

		echo '</dl>';

		echo '<h2>' . esc_html__( 'List filters', 'tso-link-inspector' ) . '</h2>';
		echo '<dl class="tsoliin-help-dl">';

		echo '<dt>' . esc_html__( 'Status filter tabs', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Broken: HTTP errors and timeouts. Redirect: non-transparent redirects. OK: working links. Unchecked: found but not tested yet. HTTP insecure: active http:// links. Manual locks: links you marked Not broken.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Quality filters', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Optional second row: Empty anchor (missing text), Generic anchor (non-descriptive phrases such as “click here”), Unpublished target (internal links to draft, private, pending, or trashed posts). Combine with status filters and Internal/External scope.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Internal and external links', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Scope tabs filter by destination: internal (same site or relative paths) vs external (other domains). Useful on large sites with many outbound links.', 'tso-link-inspector' ) . '</dd>';

		echo '</dl>';

		echo '<h2>' . esc_html__( 'Row and bulk actions', 'tso-link-inspector' ) . '</h2>';
		echo '<dl class="tsoliin-help-dl">';

		echo '<dt>' . esc_html__( 'Editing and fixing links', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Edit link changes the URL in the stored source (post content, comment, menu, widget, or term). Unlink removes the <a> tag but keeps the visible text. Recheck re-reads the WordPress source (post, comment, menu, widget, term, or template) and then runs one HTTP test. Not broken locks the link as OK. Suggestion offers HTTPS upgrades when available. Edit and Unlink are not available for some source types (images, templates, etc.) — open the post or source editor instead.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Bulk actions', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Select rows and apply Recheck selected, Unlink all, Mark as OK, Convert selected to /path (when enabled in Settings), or Delete from list. Delete only removes the database row, not the link in your content.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'View post at link', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'The external-link icon opens the front end and highlights the matching link in post content, or scrolls to a comment. Works for links stored in post content and approved comments.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Not broken (Manual locks)', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Marks a link as OK and moves it to the Manual locks tab. Background checks still run. The link returns to Broken or Redirect only if the URL or redirect outcome changes, or if a check finds it broken again.', 'tso-link-inspector' ) . '</dd>';

		echo '</dl>';

		echo '<h2>' . esc_html__( 'Settings and maintenance', 'tso-link-inspector' ) . '</h2>';
		echo '<dl class="tsoliin-help-dl">';

		echo '<dt>' . esc_html__( 'Custom fields (ACF / Meta)', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>';
		echo esc_html__( 'Enable ACF / Meta custom fields in Settings to find URLs in fields added by plugins like ACF, PODS, or CPT UI. Plain-text URLs inside meta also require Extended sources (Phase 2). Internal SEO keys are excluded by default; add extra keys to exclude to speed up scans.', 'tso-link-inspector' );
		echo ' <a href="' . esc_url( $settings_url ) . '#tsoliin-meta-exclude-row">' . esc_html__( 'Open meta settings', 'tso-link-inspector' ) . '</a>';
		echo '</dd>';

		echo '<dt>' . esc_html__( 'Broken links email notifications', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Choose immediate alerts, confirmed alerts (two consecutive failed checks), or periodic summaries every 7, 15, or 30 days. Only hard-broken links (no redirect destination) are included. Summary emails are skipped when there are none.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Relative URLs and Convert to /path', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Edit link accepts site-relative paths such as /page/, ./file.html, or ../other/. They are stored as written and checked against your site. The edit modal shows an HTML preview of the matched tag before you save. Enable post revisions in Settings to keep a restore point. Optional: enable “Convert to /path” to bulk-replace absolute same-site URLs with /path automatically.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Ignore list', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>';
		echo esc_html__( 'Domains or URL prefixes on the ignore list are skipped during Scan and Check (useful for sites that block bots). You can add entries in Settings or use Ignore domain on any row.', 'tso-link-inspector' );
		echo ' <a href="' . esc_url( $settings_url ) . '#tsoliin-ignore-list">' . esc_html__( 'Open ignore list', 'tso-link-inspector' ) . '</a>';
		echo '</dd>';

		echo '<dt>' . esc_html__( 'nofollow on broken links', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'When enabled in Settings, adds rel="nofollow" to broken links in post content on the front end so search engines are less likely to follow them. Does not affect comments or custom fields.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Export CSV and PDF', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Export buttons on the main screen respect the active status filter, quality filter, scope, search, and post view. PDF reports are limited to a subset of rows; use CSV for the full filtered list.', 'tso-link-inspector' ) . '</dd>';

		echo '<dt>' . esc_html__( 'Delete all plugin records', 'tso-link-inspector' ) . '</dt>';
		echo '<dd>' . esc_html__( 'Maintenance in Settings empties the link database and scan/check progress but does not edit posts, comments, or other content. Plugin Settings are kept. Run Scan now, then Check now, to rebuild the list.', 'tso-link-inspector' ) . '</dd>';

		echo '</dl>';
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
				$this->clear_scan_progress_options();
				echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Records deleted.', 'tso-link-inspector' ) . '</p></div>';
			}
		}

		$s           = get_option( 'tsoliin_settings', array() );
		$timeout     = isset( $s['timeout'] ) ? absint( $s['timeout'] ) : 15;
		$scan_meta   = ! empty( $s['scan_meta'] );
		$scan_images = ! empty( $s['scan_images'] );
		$scan_iframes= ! empty( $s['scan_iframes'] );
		$scan_comments=! empty( $s['scan_comments'] );
		$scan_plain_urls   = array_key_exists( 'scan_plain_urls', $s ) ? ! empty( $s['scan_plain_urls'] ) : true;
		$scan_block_attrs  = array_key_exists( 'scan_block_attrs', $s ) ? ! empty( $s['scan_block_attrs'] ) : true;
		$scan_menus        = array_key_exists( 'scan_menus', $s ) ? ! empty( $s['scan_menus'] ) : true;
		$scan_srcset       = array_key_exists( 'scan_srcset', $s ) ? ! empty( $s['scan_srcset'] ) : true;
		$scan_data_attrs   = array_key_exists( 'scan_data_attrs', $s ) ? ! empty( $s['scan_data_attrs'] ) : true;
		$schedule            = TSOLIIN_Schedule::get_settings();
		$recheck_days        = (int) $schedule['recheck_days'];
		$broken_recheck_days = (int) $schedule['broken_recheck_days'];
		$cron_check_batch    = (int) $schedule['cron_check_batch'];
		$allowed_email_modes = array( 'none', 'immediate', 'confirmed', 'digest_7', 'digest_15', 'digest_30' );
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

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$settings_tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
		if ( ! in_array( $settings_tab, array( 'settings', 'help' ), true ) ) {
			$settings_tab = 'settings';
		}

		echo '<div class="wrap">';
		echo '<div class="tsoliin-page-head">';
		echo '<h1>';
		echo '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '" class="tsoliin-back-link">';
		echo '<span class="dashicons dashicons-arrow-left-alt"></span> ';
		echo esc_html__( 'TSO Link Inspector', 'tso-link-inspector' );
		echo '</a>';
		$this->echo_plugin_version_badge();
		echo ' <span class="tsoliin-breadcrumb-sep">&#8250;</span> ';
		echo esc_html__( 'Settings', 'tso-link-inspector' );
		echo '</h1>';
		TSOLIIN_Support::render_donate_button();
		echo '</div>';

		$this->render_settings_nav_tabs( $settings_tab );

		if ( 'help' === $settings_tab ) {
			$this->render_settings_help_tab();
			echo '</div>';
			return;
		}

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

		// Extended content scanning (phase 1).
		echo '<tr><th scope="row">' . esc_html__( 'Extended scanning', 'tso-link-inspector' ) . '</th><td>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_plain_urls" value="1" ' . checked( $scan_plain_urls, true, false ) . ' /> ';
		echo esc_html__( 'Plain-text URLs in post content (without <a> tags). Checked for broken links; edit the post manually to change or remove them.', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_block_attrs" value="1" ' . checked( $scan_block_attrs, true, false ) . ' /> ';
		echo esc_html__( 'Gutenberg block attributes (parse_blocks JSON)', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_menus" value="1" ' . checked( $scan_menus, true, false ) . ' /> ';
		echo esc_html__( 'Navigation menus (custom menu links)', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_srcset" value="1" ' . checked( $scan_srcset, true, false ) . ' /> ';
		echo esc_html__( 'Responsive media (srcset, picture, video, audio, embed)', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_data_attrs" value="1" ' . checked( $scan_data_attrs, true, false ) . ' /> ';
		echo esc_html__( 'Page builder data-* link attributes', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'These sources are enabled by default. Uncheck any you want to skip during scans.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// Phase 2 extended sources.
		$scan_widgets    = array_key_exists( 'scan_widgets', $s ) ? ! empty( $s['scan_widgets'] ) : true;
		$scan_terms      = array_key_exists( 'scan_terms', $s ) ? ! empty( $s['scan_terms'] ) : true;
		$scan_fse        = array_key_exists( 'scan_fse', $s ) ? ! empty( $s['scan_fse'] ) : true;
		$scan_meta_plain = array_key_exists( 'scan_meta_plain', $s ) ? ! empty( $s['scan_meta_plain'] ) : true;
		echo '<tr><th scope="row">' . esc_html__( 'Extended sources (Phase 2)', 'tso-link-inspector' ) . '</th><td>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_widgets" value="1" ' . checked( $scan_widgets, true, false ) . ' /> ';
		echo esc_html__( 'Widget sidebars (Text, Custom HTML, block widgets)', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_terms" value="1" ' . checked( $scan_terms, true, false ) . ' /> ';
		echo esc_html__( 'Taxonomy term descriptions (categories, tags, etc.)', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_fse" value="1" ' . checked( $scan_fse, true, false ) . ' /> ';
		echo esc_html__( 'Site Editor templates, template parts, and reusable blocks', 'tso-link-inspector' ) . '</label>';
		echo '<label style="display:block;margin-bottom:5px;"><input type="checkbox" name="tsoliin_scan_meta_plain" value="1" ' . checked( $scan_meta_plain, true, false ) . ' /> ';
		echo esc_html__( 'Plain-text URLs inside custom fields (requires ACF/Meta scan above)', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'Third-party plugins can register extra sources with the tsoliin_register_link_source() API.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// Scan meta.
		echo '<tr><th scope="row">' . esc_html__( 'ACF / Meta custom fields', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" id="tsoliin_scan_meta" name="tsoliin_scan_meta" value="1" ' . checked( $scan_meta, true, false ) . ' /> ';
		echo esc_html__( 'Scan custom fields (ACF, PODS, CPT UI...)', 'tso-link-inspector' );
		echo '</label>';
		echo '<p class="description">' . esc_html__( 'Find links in fields added by plugins like ACF. Useful when you have URL, HTML, or editor fields in your posts. It may slow down scanning.', 'tso-link-inspector' ) . '</p>';
		echo '<p class="description">' . esc_html__( 'Internal WordPress keys (e.g. Yoast, Rank Math) are always excluded. Add extra keys below, one per line.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		// Meta exclude keys.
		echo '<tr id="tsoliin-meta-exclude-row"><th scope="row">' . esc_html__( 'Meta keys to exclude', 'tso-link-inspector' ) . '</th><td>';
		echo '<textarea name="tsoliin_meta_exclude_keys" rows="4" class="tsoliin-meta-keys code">' . esc_textarea( $meta_keys ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One key per line.', 'tso-link-inspector' ) . '</p></td></tr>';

		// Timeout.
		echo '<tr><th scope="row"><label for="tsoliin_timeout">' . esc_html__( 'Timeout (seconds)', 'tso-link-inspector' ) . '</label></th><td>';
		echo '<input type="number" id="tsoliin_timeout" name="tsoliin_timeout" value="' . esc_attr( (string) $timeout ) . '" min="5" max="60" class="small-text" />';
		echo '<p class="description">' . esc_html__( 'Recommended: 15 s.', 'tso-link-inspector' ) . '</p></td></tr>';

		// Automatic check schedule.
		echo '<tr><th scope="row">' . esc_html__( 'Automatic HTTP checks', 'tso-link-inspector' ) . '</th><td>';
		echo '<p><label for="tsoliin_recheck_days">' . esc_html__( 'Recheck OK links every (days)', 'tso-link-inspector' ) . '</label><br />';
		echo '<input type="number" id="tsoliin_recheck_days" name="tsoliin_recheck_days" value="' . esc_attr( (string) $recheck_days ) . '" min="1" max="365" class="small-text" />';
		echo '<span class="description"> ' . esc_html__( 'Working links are rechecked after this many days since their last HTTP test. Default: 7.', 'tso-link-inspector' ) . '</span></p>';
		echo '<p><label for="tsoliin_broken_recheck_days">' . esc_html__( 'Recheck broken links every (days)', 'tso-link-inspector' ) . '</label><br />';
		echo '<input type="number" id="tsoliin_broken_recheck_days" name="tsoliin_broken_recheck_days" value="' . esc_attr( (string) $broken_recheck_days ) . '" min="1" max="90" class="small-text" />';
		echo '<span class="description"> ' . esc_html__( 'Broken links (not manually locked) are rechecked sooner. Default: 7.', 'tso-link-inspector' ) . '</span></p>';
		echo '<p><label for="tsoliin_cron_check_batch">' . esc_html__( 'Links per hourly cron run', 'tso-link-inspector' ) . '</label><br />';
		echo '<input type="number" id="tsoliin_cron_check_batch" name="tsoliin_cron_check_batch" value="' . esc_attr( (string) $cron_check_batch ) . '" min="5" max="100" class="small-text" />';
		echo '<span class="description"> ';
		printf(
			/* translators: %d: estimated checks per day */
			esc_html__( 'How many links WP-Cron tests each hour (5–100). At the current value, about %d checks per day.', 'tso-link-inspector' ),
			absint( $cron_check_batch ) * 24
		);
		echo '</span></p></td></tr>';

		// Broken links email notifications.
		echo '<tr><th scope="row"><label for="tsoliin_broken_email_mode">' . esc_html__( 'Broken links email notifications', 'tso-link-inspector' ) . '</label></th><td>';
		echo '<select id="tsoliin_broken_email_mode" name="tsoliin_broken_email_mode">';
		$email_modes = array(
			'none'      => __( 'Disabled', 'tso-link-inspector' ),
			'immediate' => __( 'Send immediately when a broken link is detected', 'tso-link-inspector' ),
			'confirmed' => __( 'Send after two consecutive failed checks (fewer false alarms)', 'tso-link-inspector' ),
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
		$relative_url_tool = ! empty( $s['relative_url_tool'] );
		$create_revision   = ! empty( $s['create_revision'] );

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

		echo '<tr><th scope="row">' . esc_html__( 'Convert to /path', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_relative_url_tool" value="1" ' . checked( $relative_url_tool, true, false ) . ' /> ';
		echo esc_html__( 'Show “Convert to /path” in the link list', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, adds a row and bulk action to replace same-site URLs like https://yoursite.com/contact/ with /contact/ in post content. Disabled by default.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Post revisions', 'tso-link-inspector' ) . '</th><td>';
		echo '<label><input type="checkbox" name="tsoliin_create_revision" value="1" ' . checked( $create_revision, true, false ) . ' /> ';
		echo esc_html__( 'Create a revision before editing a link in post content', 'tso-link-inspector' ) . '</label>';
		echo '<p class="description">' . esc_html__( 'When enabled, saves a WordPress revision before Edit link or Convert to /path changes post content. Disabled by default.', 'tso-link-inspector' ) . '</p>';
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
		echo '<tr id="tsoliin-ignore-list"><th scope="row">' . esc_html__( 'Ignore list', 'tso-link-inspector' ) . '</th><td>';
		echo '<textarea name="tsoliin_ignore_list" rows="5" class="tsoliin-meta-keys code">' . esc_textarea( $ignore_list ) . '</textarea>';
		echo '<p class="description">' . esc_html__( 'One domain or URL per line. Example: amazon.com or https://example.com/page. These links will be skipped during scan and check.', 'tso-link-inspector' ) . '</p>';
		echo '</td></tr>';

		echo '</tbody></table>';
		submit_button( __( 'Save settings', 'tso-link-inspector' ) );
		echo '</form>';

		// ── Maintenance.
		echo '<h2>' . esc_html__( 'Maintenance', 'tso-link-inspector' ) . '</h2>';
		echo '<div class="tsoliin-maintenance-box notice notice-warning inline">';
		echo '<p><strong>' . esc_html__( 'Delete all plugin records', 'tso-link-inspector' ) . '</strong></p>';
		echo '<p>' . esc_html__( 'This action only clears data stored by Link Inspector. Your website content is not edited.', 'tso-link-inspector' ) . '</p>';
		echo '<p><strong>' . esc_html__( 'What is deleted:', 'tso-link-inspector' ) . '</strong></p>';
		echo '<ul class="tsoliin-maintenance-list">';
		echo '<li>' . esc_html__( 'Every row in the link list (URLs found during scans, anchor text, link type).', 'tso-link-inspector' ) . '</li>';
		echo '<li>' . esc_html__( 'HTTP results: status codes, broken/OK flags, redirect destinations, and last-checked dates.', 'tso-link-inspector' ) . '</li>';
		echo '<li>' . esc_html__( 'Manual locks (links you marked Not broken).', 'tso-link-inspector' ) . '</li>';
		echo '<li>' . esc_html__( 'Scan and check progress (last scan date, batch cursors, any running background check).', 'tso-link-inspector' ) . '</li>';
		echo '</ul>';
		echo '<p><strong>' . esc_html__( 'What is kept:', 'tso-link-inspector' ) . '</strong></p>';
		echo '<ul class="tsoliin-maintenance-list">';
		echo '<li>' . esc_html__( 'Posts, pages, comments, menus, widgets, and all other site content (no links are removed or changed).', 'tso-link-inspector' ) . '</li>';
		echo '<li>' . esc_html__( 'Plugin Settings on this page (ignore list, email alerts, scan options, language).', 'tso-link-inspector' ) . '</li>';
		echo '</ul>';
		echo '<p><strong>' . esc_html__( 'After deleting:', 'tso-link-inspector' ) . '</strong> ';
		echo esc_html__( 'open the main dashboard, click Scan now to rebuild the list from your content, then Check now to test URLs again.', 'tso-link-inspector' );
		echo '</p>';
		echo '</div>';
		$reset_confirm = __( 'Delete all plugin records?', 'tso-link-inspector' ) . "\n\n"
			. __( 'This empties the link database and resets scan/check progress. Posts and Settings are not changed. You will need to run Scan now and Check now again.', 'tso-link-inspector' );
		$reset_url = wp_nonce_url( add_query_arg( 'tsoliin_action', 'reset_all', admin_url( 'tools.php?page=tso-link-inspector-settings' ) ), 'tsoliin_reset_all' );
		echo '<p><a href="' . esc_url( $reset_url ) . '" class="button button-secondary" onclick="return confirm(\'' . esc_js( $reset_confirm ) . '\')">' . esc_html__( 'Delete all plugin records', 'tso-link-inspector' ) . '</a></p>';
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
		if ( empty( $post_types ) ) {
			$post_types = array( 'post', 'page' );
		}
		$timeout      = max( 5, min( 60, absint( isset( $_POST['tsoliin_timeout'] ) ? $_POST['tsoliin_timeout'] : 15 ) ) );
		$recheck_days        = max( 1, min( 365, absint( isset( $_POST['tsoliin_recheck_days'] ) ? $_POST['tsoliin_recheck_days'] : 7 ) ) );
		$broken_recheck_days = max( 1, min( 90, absint( isset( $_POST['tsoliin_broken_recheck_days'] ) ? $_POST['tsoliin_broken_recheck_days'] : 7 ) ) );
		$cron_check_batch    = max( 5, min( 100, absint( isset( $_POST['tsoliin_cron_check_batch'] ) ? $_POST['tsoliin_cron_check_batch'] : 20 ) ) );
		$scan_meta    = ! empty( $_POST['tsoliin_scan_meta'] );
		$scan_images  = ! empty( $_POST['tsoliin_scan_images'] );
		$scan_iframes = ! empty( $_POST['tsoliin_scan_iframes'] );
		$scan_comments= ! empty( $_POST['tsoliin_scan_comments'] );
		$scan_plain_urls   = ! empty( $_POST['tsoliin_scan_plain_urls'] );
		$scan_block_attrs  = ! empty( $_POST['tsoliin_scan_block_attrs'] );
		$scan_menus        = ! empty( $_POST['tsoliin_scan_menus'] );
		$scan_srcset       = ! empty( $_POST['tsoliin_scan_srcset'] );
		$scan_data_attrs   = ! empty( $_POST['tsoliin_scan_data_attrs'] );
		$scan_widgets      = ! empty( $_POST['tsoliin_scan_widgets'] );
		$scan_terms        = ! empty( $_POST['tsoliin_scan_terms'] );
		$scan_fse          = ! empty( $_POST['tsoliin_scan_fse'] );
		$scan_meta_plain   = ! empty( $_POST['tsoliin_scan_meta_plain'] );
		$allowed_email_modes = array( 'none', 'immediate', 'confirmed', 'digest_7', 'digest_15', 'digest_30' );
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

		$preserve_dates    = ! empty( $_POST['tsoliin_preserve_dates'] );
		$nofollow_broken   = ! empty( $_POST['tsoliin_nofollow_broken'] );
		$relative_url_tool = ! empty( $_POST['tsoliin_relative_url_tool'] );
		$create_revision   = ! empty( $_POST['tsoliin_create_revision'] );

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
			'recheck_days'        => $recheck_days,
			'broken_recheck_days' => $broken_recheck_days,
			'cron_check_batch'    => $cron_check_batch,
			'scan_meta'         => $scan_meta,
			'scan_images'       => $scan_images,
			'scan_iframes'      => $scan_iframes,
			'scan_comments'     => $scan_comments,
			'scan_plain_urls'   => $scan_plain_urls,
			'scan_block_attrs'  => $scan_block_attrs,
			'scan_menus'        => $scan_menus,
			'scan_srcset'       => $scan_srcset,
			'scan_data_attrs'   => $scan_data_attrs,
			'scan_widgets'      => $scan_widgets,
			'scan_terms'        => $scan_terms,
			'scan_fse'          => $scan_fse,
			'scan_meta_plain'   => $scan_meta_plain,
			'broken_email_mode' => $broken_email_mode,
			'broken_email_to'   => $broken_email_to,
			'meta_exclude_keys' => $meta_keys,
			'language'          => $language,
			'preserve_dates'    => $preserve_dates,
			'nofollow_broken'   => $nofollow_broken,
			'relative_url_tool' => $relative_url_tool,
			'create_revision'   => $create_revision,
			'ignore_list'       => $ignore_list_save,
		), false );

		if ( $current_mode !== $broken_email_mode ) {
			delete_option( 'tsoliin_broken_digest_last_sent' );
		}
	}

	// =========================================================================
	// AJAX HANDLERS
	// =========================================================================

	/**
	 * Build status column HTML (matches list table output for live AJAX updates).
	 *
	 * @param int    $code          HTTP status code.
	 * @param int    $is_broken     1 if broken.
	 * @param string $link_url      Checked URL.
	 * @param string $redirect_url  Redirect destination if any.
	 * @param bool   $user_verified Manual lock flag.
	 * @return string
	 */
	private static function format_status_column_html( $code, $is_broken, $link_url, $redirect_url = '', $user_verified = false ) {
		$code          = (int) $code;
		$is_broken     = (int) $is_broken;
		$link_url      = (string) $link_url;
		$redirect_url  = (string) $redirect_url;
		$user_verified = (bool) $user_verified;
		$class         = TSOLIIN_HTTP::status_class( $code, $is_broken, $link_url );
		$label         = TSOLIIN_HTTP::status_label( $code, $link_url );
		$html          = '';
		if ( $user_verified ) {
			$html .= '<span class="tsoliin-verified-badge" title="' . esc_attr__( 'Manual lock: marked OK by you. Background checks still run. Cleared if the URL or redirect changes, or if a check finds it broken.', 'tso-link-inspector' ) . '">&#128274; </span>';
		}
		$html .= '<span class="tsoliin-status ' . esc_attr( $class ) . '">';
		if ( $code > 0 ) {
			$html .= esc_html( (string) $code ) . ' ';
		}
		$html .= esc_html( $label ) . '</span>';
		if ( '' !== $redirect_url && rtrim( $link_url, '/' ) !== rtrim( $redirect_url, '/' ) ) {
			$rdisp = strlen( $redirect_url ) > 40 ? substr( $redirect_url, 0, 37 ) . '...' : $redirect_url;
			$html .= '<br><small><a href="' . esc_url( $redirect_url ) . '" title="' . esc_attr( $redirect_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $rdisp ) . '</a></small>';
		}
		return $html;
	}

	private function clear_scan_progress_options() {
		delete_option( 'tsoliin_comment_scan_after_id' );
		delete_option( 'tsoliin_menu_scan_after_id' );
		delete_option( 'tsoliin_widget_scan_after_index' );
		delete_option( 'tsoliin_term_scan_after_id' );
		delete_option( 'tsoliin_fse_scan_after_id' );
		delete_option( 'tsoliin_total_posts_scanned' );
		delete_option( 'tsoliin_last_full_scan' );
		$this->cron->stop_bg_check();
	}

	/**
	 * Whether a link row cannot be edited or unlinked from this plugin.
	 *
	 * @param object $link DB link row.
	 * @return bool
	 */
	private function is_non_editable_source( $link ) {
		return '' !== $this->get_non_editable_source_message( $link );
	}

	/**
	 * User-facing message when Edit/Unlink is blocked for external sources.
	 *
	 * @param object $link DB link row.
	 * @return string Empty when the row is editable here.
	 */
	private function get_non_editable_source_message( $link ) {
		if ( TSOLIIN_Support::can_inline_edit_link( $link ) ) {
			return '';
		}
		$type = ( $link && isset( $link->link_type ) ) ? (string) $link->link_type : 'link';
		if ( ! empty( $link->post_id ) && in_array( $type, array( 'link', 'image', 'iframe', 'template', 'wp_block' ), true ) ) {
			return __( 'This URL is not stored in editable post content. Open the post in the editor to change it, then run Scan again.', 'tso-link-inspector' );
		}
		switch ( $type ) {
			case 'plain':
				return __( 'This URL is plain text in the content, not an HTML link. Edit the post manually, or use Delete to remove this record from the list.', 'tso-link-inspector' );
			case 'menu':
				return __( 'This menu link could not be located. Edit it under Appearance > Menus or the Site Editor.', 'tso-link-inspector' );
			case 'widget':
				return __( 'This widget link could not be located. Edit it under Appearance > Widgets or the Site Editor.', 'tso-link-inspector' );
			case 'term':
				return __( 'This term description link could not be located. Edit it in the taxonomy editor.', 'tso-link-inspector' );
			default:
				if ( $link && empty( $link->post_id ) && 'comment' !== $type ) {
					return __( 'This link must be edited at its original source.', 'tso-link-inspector' );
				}
				return '';
		}
	}

	private function check_nonce_and_cap() {
		check_ajax_referer( 'tsoliin_action', 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'tso-link-inspector' ) ), 403 );
		}
	}

	/**
	 * Hidden filters + list table markup (main page and live search AJAX).
	 *
	 * @param TSOLIIN_List_Table $table        Prepared list table.
	 * @param int                $view_post_id Optional post filter.
	 * @param string             $filter_val   Active status filter.
	 * @param string             $scope_val    Internal/external scope.
	 * @param string             $search_val   Search string.
	 * @return void
	 */
	private function render_list_table_region( TSOLIIN_List_Table $table, $view_post_id, $filter_val, $scope_val, $search_val ) {
		echo '<input type="hidden" name="page" value="tso-link-inspector" />';
		if ( $view_post_id ) {
			echo '<input type="hidden" name="post_id" value="' . esc_attr( (string) $view_post_id ) . '" />';
		}
		if ( 'all' !== $filter_val ) {
			echo '<input type="hidden" name="filter" value="' . esc_attr( $filter_val ) . '" />';
		}
		$quality_val = $this->get_list_quality_filter_from_request();
		if ( '' !== $quality_val ) {
			echo '<input type="hidden" name="quality_filter" value="' . esc_attr( $quality_val ) . '" />';
		}
		if ( 'all' !== $scope_val ) {
			echo '<input type="hidden" name="scope" value="' . esc_attr( $scope_val ) . '" />';
		}
		if ( '' !== $search_val ) {
			echo '<input type="hidden" name="s" value="' . esc_attr( $search_val ) . '" />';
		}
		$table->display();
	}

	/**
	 * Live search: return refreshed list table HTML without a full page reload.
	 *
	 * @return void
	 */
	public function ajax_search_list() {
		$this->check_nonce_and_cap();

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$search = isset( $_POST['s'] ) ? sanitize_text_field( wp_unslash( $_POST['s'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$filter = isset( $_POST['filter'] ) ? sanitize_key( wp_unslash( $_POST['filter'] ) ) : 'all';
		if ( in_array( $filter, $this->get_allowed_quality_filters(), true ) ) {
			$filter = 'all';
		} elseif ( ! in_array( $filter, $this->get_allowed_status_filters(), true ) ) {
			$filter = 'all';
		}
		$quality = isset( $_POST['quality_filter'] ) ? sanitize_key( wp_unslash( $_POST['quality_filter'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! in_array( $quality, $this->get_allowed_quality_filters(), true ) ) {
			$quality = '';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$scope = isset( $_POST['scope'] ) ? $this->db->sanitize_scope_input( wp_unslash( $_POST['scope'] ) ) : 'all';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$paged   = isset( $_POST['paged'] ) ? max( 1, absint( $_POST['paged'] ) ) : 1;
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$orderby = isset( $_POST['orderby'] ) ? sanitize_key( wp_unslash( $_POST['orderby'] ) ) : 'date_found';
		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$order   = isset( $_POST['order'] ) ? sanitize_key( wp_unslash( $_POST['order'] ) ) : 'DESC';

		$_REQUEST['s']       = $search;
		$_REQUEST['filter']         = $filter;
		$_REQUEST['quality_filter'] = $quality;
		$_REQUEST['scope']   = $scope;
		$_REQUEST['paged']   = $paged;
		$_REQUEST['orderby'] = $orderby;
		$_REQUEST['order']   = $order;
		if ( $post_id ) {
			$_REQUEST['post_id'] = $post_id;
		}

		$table = new TSOLIIN_List_Table( $this->db, $this->http );
		$table->prepare_items();

		ob_start();
		$this->render_list_table_region( $table, $post_id, $filter, $scope, $search );
		wp_send_json_success(
			array(
				'html'  => ob_get_clean(),
				'total' => (int) $table->get_pagination_arg( 'total_items' ),
			)
		);
	}

	/**
	 * Allowed list-table filter keys.
	 *
	 * @return string[]
	 */
	private function get_allowed_status_filters() {
		return array(
			'all',
			'broken',
			'redirect',
			'ok',
			'unchecked',
			'http_insecure',
			'manual_locked',
		);
	}

	/**
	 * Allowed optional quality filter keys.
	 *
	 * @return string[]
	 */
	private function get_allowed_quality_filters() {
		return array(
			'empty_anchor',
			'generic_anchor',
			'unpublished_target',
		);
	}

	/**
	 * Allowed list-table filter keys (status + legacy quality URLs).
	 *
	 * @return string[]
	 */
	private function get_allowed_list_filters() {
		return array_merge( $this->get_allowed_status_filters(), $this->get_allowed_quality_filters() );
	}

	/**
	 * Active internal/external scope from AJAX POST or admin GET.
	 *
	 * @return string
	 */
	private function get_list_scope_from_request() {
		if ( isset( $_POST['list_scope'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $this->db->sanitize_scope_input( wp_unslash( $_POST['list_scope'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		return $this->get_scope_from_request();
	}

	/**
	 * Active internal/external scope from admin GET/REQUEST (list filters).
	 *
	 * @return string
	 */
	private function get_scope_from_request() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! isset( $_REQUEST['scope'] ) ) {
			return 'all';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		return $this->db->sanitize_scope_input( wp_unslash( $_REQUEST['scope'] ) );
	}

	/**
	 * Sanitize a list-table filter key.
	 *
	 * @param string $filter Raw filter.
	 * @return string
	 */
	private function sanitize_list_filter( $filter ) {
		$filter = sanitize_key( (string) $filter );
		if ( in_array( $filter, $this->get_allowed_quality_filters(), true ) ) {
			return 'all';
		}
		return in_array( $filter, $this->get_allowed_status_filters(), true ) ? $filter : 'all';
	}

	/**
	 * Sanitize optional quality filter key.
	 *
	 * @param string $quality Raw quality filter.
	 * @return string Empty when none/invalid.
	 */
	private function sanitize_list_quality_filter( $quality ) {
		$quality = sanitize_key( (string) $quality );
		return in_array( $quality, $this->get_allowed_quality_filters(), true ) ? $quality : '';
	}

	/**
	 * Active optional quality filter from AJAX POST or admin GET.
	 *
	 * @return string
	 */
	private function get_list_quality_filter_from_request() {
		if ( isset( $_POST['list_quality_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $this->sanitize_list_quality_filter( wp_unslash( $_POST['list_quality_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_POST['quality_filter'] ) ) {
			return $this->sanitize_list_quality_filter( wp_unslash( $_POST['quality_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['quality_filter'] ) ) {
			return $this->sanitize_list_quality_filter( wp_unslash( $_GET['quality_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		// Legacy URLs: quality stored in filter=.
		$legacy = '';
		if ( isset( $_POST['filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$legacy = sanitize_key( wp_unslash( $_POST['filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		} elseif ( isset( $_GET['filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$legacy = sanitize_key( wp_unslash( $_GET['filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( in_array( $legacy, $this->get_allowed_quality_filters(), true ) ) {
			return $legacy;
		}
		return '';
	}

	/**
	 * Active list filter from AJAX POST or admin GET.
	 *
	 * @return string
	 */
	private function get_list_filter_from_request() {
		if ( isset( $_POST['list_filter'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing
			return $this->sanitize_list_filter( wp_unslash( $_POST['list_filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( isset( $_GET['filter'] ) ) {
			return $this->sanitize_list_filter( wp_unslash( $_GET['filter'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		return 'all';
	}

	/**
	 * Add matches_filter for the current list tab to an AJAX payload.
	 *
	 * @param array       $payload Response data.
	 * @param object|null $link    Link row after the mutation.
	 * @return array
	 */
	private function append_filter_match( array $payload, $link ) {
		$filter  = $this->get_list_filter_from_request();
		$quality = $this->get_list_quality_filter_from_request();
		$scope   = $this->get_list_scope_from_request();
		$match   = true;
		if ( $link ) {
			if ( 'all' !== $filter ) {
				$match = $this->db->link_matches_filter( $link, $filter );
			}
			if ( $match && '' !== $quality ) {
				$match = $this->db->link_matches_quality_filter( $link, $quality );
			}
			if ( $match && 'all' !== $scope ) {
				$match = $this->db->link_matches_scope( $link, $scope );
			}
		}
		$payload['matches_filter'] = $match;
		return $this->append_http_fix_promotion( $payload, $link, $filter );
	}

	/**
	 * When HTTPS is saved from the HTTP insecure tab, note that a remaining redirect moves to Redirect.
	 *
	 * @param array       $payload Response data.
	 * @param object|null $link    Link row after the mutation.
	 * @param string      $filter  Active list filter.
	 * @return array
	 */
	private function append_http_fix_promotion( array $payload, $link, $filter ) {
		if ( ! $link || 'http_insecure' !== sanitize_key( (string) $filter ) ) {
			return $payload;
		}

		$link_url      = isset( $link->link_url ) ? (string) $link->link_url : '';
		$is_broken     = isset( $link->is_broken ) ? (int) $link->is_broken : 0;
		$user_verified = isset( $link->user_verified ) ? (int) $link->user_verified : 0;

		if ( TSOLIIN_DB::row_is_http_insecure( $link_url, $is_broken, $user_verified ) ) {
			return $payload;
		}

		if ( ! $this->db->row_counts_as_redirect_tab( $link ) ) {
			return $payload;
		}

		$payload['promoted_to_filter']       = 'redirect';
		$payload['filter_promotion_message'] = __( 'HTTPS saved. This link now appears under Redirect.', 'tso-link-inspector' );

		return $payload;
	}

	/**
	 * Build AJAX status fields from a stored link row (after DB normalization).
	 *
	 * @param object      $link             Link row from TSOLIIN_DB.
	 * @param string|null $url_for_label    Optional URL for status label/class.
	 * @param bool        $use_current_time When true, last_checked uses now (after HTTP check).
	 * @return array<string, mixed>
	 */
	private function status_payload_from_link( $link, $url_for_label = null, $use_current_time = false ) {
		if ( ! $link ) {
			return array();
		}
		$url      = null !== $url_for_label ? (string) $url_for_label : (string) $link->link_url;
		$code     = (int) $link->status_code;
		$broken   = (int) $link->is_broken;
		$redir    = (string) $link->redirect_url;
		$verified = ! empty( $link->user_verified );
		$checked  = $use_current_time
			? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) )
			: ( $link->last_checked
				? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $link->last_checked ) )
				: '' );
		return array(
			'status_code'  => $code,
			'is_broken'    => $broken,
			'redirect_url' => $redir,
			'label'        => TSOLIIN_HTTP::status_label( $code, $url ),
			'css_class'    => TSOLIIN_HTTP::status_class( $code, $broken, $url ),
			'status_html'  => TSOLIIN_Support::render_link_status_html( $link, $this->http ),
			'last_checked' => $checked,
		);
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
		$link             = $this->resync_link_before_recheck( $link );
		if ( ! $link ) {
			wp_send_json_success(
				array(
					'removed'        => true,
					'matches_filter' => false,
					'message'        => __( 'Removed from list — link no longer found in content.', 'tso-link-inspector' ),
				)
			);
		}
		$link_id = (int) $link->id;
		// Keep user_verified: update_check_result() still runs HTTP and will override if the link is actually broken.
		$r = $this->http->check( $link->link_url, (int) $link->post_id );
		$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
		$updated = $this->db->get_link( $link_id );
		$payload = $this->append_filter_match(
			$this->status_payload_from_link( $updated, (string) $updated->link_url, true ),
			$updated
		);
		if ( $updated ) {
			$payload['new_url'] = (string) $updated->link_url;
			$payload['link_id'] = (int) $updated->id;
		}
		wp_send_json_success( $payload );
	}

	/**
	 * Re-read WordPress source storage before an HTTP recheck.
	 *
	 * @param object $link DB row.
	 * @return object|null Updated row, or null if the link was removed from the source.
	 */
	private function resync_link_before_recheck( $link ) {
		return $this->scanner->resync_link_from_source( $link );
	}

	public function ajax_update_link() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$new_url_raw    = isset( $_POST['new_url'] ) ? wp_unslash( $_POST['new_url'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$new_anchor_raw = isset( $_POST['new_anchor'] ) ? wp_unslash( $_POST['new_anchor'] ) : null; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link           = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'tso-link-inspector' ) ) );
		}

		$link_type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( 'widget' === $link_type ) {
			wp_send_json_error(
				array(
					'message' => __( 'Edit widget links in Appearance > Widgets using Go to edit.', 'tso-link-inspector' ),
				)
			);
		}
		if ( 'menu' === $link_type ) {
			wp_send_json_error(
				array(
					'message' => __( 'Edit menu links in Appearance > Menus using Go to edit.', 'tso-link-inspector' ),
				)
			);
		}
		if ( 'term' === $link_type ) {
			wp_send_json_error(
				array(
					'message' => __( 'Edit term description links in the taxonomy editor using Go to edit.', 'tso-link-inspector' ),
				)
			);
		}

		$new_url = TSOLIIN_HTTP::sanitize_editable_link_url( $new_url_raw, (int) $link->post_id );
		if ( ! $link_id || false === $new_url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid data.', 'tso-link-inspector' ) ) );
		}

		$new_anchor     = null !== $new_anchor_raw ? sanitize_text_field( $new_anchor_raw ) : null;
		$url_changed    = $new_url !== (string) $link->link_url;
		if ( $url_changed && $this->scanner->urls_equivalent_for_stored_link( (string) $link->link_url, $new_url, (int) $link->post_id ) ) {
			$url_changed = false;
		}
		$anchor_changed = null !== $new_anchor && $new_anchor !== (string) $link->anchor_text;
		if ( ! $url_changed && $anchor_changed && ! TSOLIIN_Support::can_edit_link_anchor_in_modal( $link ) ) {
			wp_send_json_error(
				array(
					'message' => __( 'Link text cannot be changed here for this comment. Edit the comment in WordPress to change visible text.', 'tso-link-inspector' ),
				)
			);
		}
		if ( $anchor_changed && ! TSOLIIN_Support::can_edit_link_anchor_in_modal( $link ) ) {
			$anchor_changed = false;
			$new_anchor     = null;
		}
		if ( ! $url_changed && ! $anchor_changed ) {
			wp_send_json_error( array( 'message' => __( 'No changes to save.', 'tso-link-inspector' ) ) );
		}

		$blocked = $this->get_non_editable_source_message( $link );
		if ( '' !== $blocked ) {
			wp_send_json_error( array( 'message' => $blocked ) );
		}

		$url_done    = true;
		$anchor_done = true;
		$warning     = '';

		if ( $url_changed ) {
			$url_done = $this->replace_link_url_in_source( $link, $new_url );
		}

		if ( $anchor_changed ) {
			if ( 'image' === $link_type ) {
				$match_src   = ( $url_changed && $url_done ) ? $new_url : null;
				$anchor_done = $this->scanner->replace_alt_in_post(
					(int) $link->post_id,
					(string) $link->link_url,
					$new_anchor,
					$match_src
				);
			} elseif ( in_array( $link_type, array( 'link', 'comment', 'widget', 'menu', 'term' ), true ) ) {
				$href_for_anchor = ( $url_changed && $url_done ) ? $new_url : (string) $link->link_url;
				if ( 'comment' === $link_type ) {
					$anchor_done = $this->update_anchor_in_comment( $link, $href_for_anchor, $new_anchor );
				} elseif ( 'widget' === $link_type ) {
					$anchor_done = $this->scanner->replace_anchor_in_widget( (string) $link->source_key, $href_for_anchor, $new_anchor );
				} elseif ( 'menu' === $link_type ) {
					$anchor_done = $this->scanner->replace_anchor_in_menu_item( (string) $link->source_key, $new_anchor );
				} elseif ( 'term' === $link_type ) {
					$anchor_done = $this->scanner->replace_anchor_in_term( (string) $link->source_key, $href_for_anchor, $new_anchor );
				} else {
					$anchor_done = $this->scanner->replace_anchor_in_post( (int) $link->post_id, $href_for_anchor, $new_anchor );
				}
			}
		}

		if ( $url_changed && ! $url_done ) {
			if ( $anchor_changed && $anchor_done ) {
				$warning = __( 'Link text updated, but the URL could not be changed in the post. Edit the post manually or leave the URL field unchanged next time.', 'tso-link-inspector' );
			} elseif ( 'comment' === $link_type ) {
				wp_send_json_error( array( 'message' => __( 'Original URL not found in this comment. Edit the comment manually or check encoding (e.g. trailing slash).', 'tso-link-inspector' ) ) );
			} elseif ( 'image' === $link_type ) {
				wp_send_json_error( array( 'message' => __( 'Original image URL not found in post. Check whether the image was edited manually.', 'tso-link-inspector' ) ) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Original URL not found in post. Check whether the link was edited manually.', 'tso-link-inspector' ) ) );
			}
		}

		if ( $anchor_changed && ! $anchor_done ) {
			if ( $url_changed && $url_done ) {
				$warning = __( 'URL updated, but link text could not be changed in the post. Edit the post manually or leave the link text field unchanged next time.', 'tso-link-inspector' );
			} elseif ( 'image' === $link_type ) {
				wp_send_json_error( array( 'message' => __( 'Original image URL not found in post. Check whether the image was edited manually.', 'tso-link-inspector' ) ) );
			} elseif ( 'comment' === $link_type ) {
				wp_send_json_error( array( 'message' => __( 'Original URL not found in this comment. Edit the comment manually or check encoding (e.g. trailing slash).', 'tso-link-inspector' ) ) );
			} else {
				wp_send_json_error( array( 'message' => __( 'Original URL not found in post. Check whether the link was edited manually.', 'tso-link-inspector' ) ) );
			}
		}

		$response = array();
		if ( $url_changed && $url_done ) {
			$this->db->update_link_url( $link_id, $new_url );
			$r = $this->http->check( $new_url, (int) $link->post_id );
			$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
			$response = array( 'new_url' => $new_url );
		}
		if ( $anchor_changed && $anchor_done ) {
			$this->db->update_link_anchor_text( $link_id, $new_anchor );
			$response['new_anchor'] = $new_anchor;
		}
		if ( '' !== $warning ) {
			$response['warning'] = $warning;
		}

		$updated = $this->db->get_link( $link_id );
		if ( $url_changed ) {
			$response = array_merge( $response, $this->status_payload_from_link( $updated, $new_url, true ) );
		}
		wp_send_json_success( $this->append_filter_match( $response, $updated ) );
	}

	/**
	 * Update anchor text inside a comment body (not author URL rows).
	 *
	 * @param object $link   DB link row.
	 * @param string $url    href URL to match.
	 * @param string $anchor New anchor text.
	 * @return bool
	 */
	private function update_anchor_in_comment( $link, $url, $anchor ) {
		$cid = $this->get_comment_id_from_link( $link );
		if ( $cid && get_comment( $cid ) ) {
			return $this->scanner->replace_anchor_in_comment_content( $cid, $url, $anchor );
		}

		$cids = $this->find_comment_ids_for_link( $link, 5 );
		foreach ( (array) $cids as $cid ) {
			if ( $this->scanner->replace_anchor_in_comment_content( absint( $cid ), $url, $anchor ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Find approved comments on a post that contain or own a link URL.
	 *
	 * Uses the same URL equivalence rules as unlink/update (trailing slash, scheme, encoding).
	 *
	 * @param object $link  DB link row.
	 * @param int    $limit Max comment IDs to return.
	 * @return int[]
	 */
	private function find_comment_ids_for_link( $link, $limit = 10 ) {
		global $wpdb;
		$post_id = absint( $link->post_id );
		$url     = (string) $link->link_url;
		if ( ! $post_id || '' === $url ) {
			return array();
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$cids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT comment_ID FROM {$wpdb->comments} WHERE comment_post_ID = %d AND comment_approved = '1' AND ( comment_content LIKE %s OR comment_author_url != '' ) LIMIT %d",
				$post_id,
				'%' . $wpdb->esc_like( $url ) . '%',
				max( 10, absint( $limit ) * 5 )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		$matched = array();
		foreach ( (array) $cids as $cid ) {
			$comment = get_comment( absint( $cid ) );
			if ( ! $comment ) {
				continue;
			}
			$author_url = trim( (string) $comment->comment_author_url );
			if ( '' !== $author_url && $this->scanner->comment_author_url_matches_row_url( $author_url, $url ) ) {
				$matched[] = (int) $comment->comment_ID;
				continue;
			}
			$content = (string) $comment->comment_content;
			if ( false !== strpos( $content, $url )
				|| false !== strpos( $content, str_replace( '&', '&amp;', $url ) )
				|| false !== strpos( $content, urldecode( $url ) ) ) {
				$matched[] = (int) $comment->comment_ID;
			}
		}

		return array_slice( array_values( array_unique( $matched ) ), 0, absint( $limit ) );
	}

	/**
	 * Resolve WordPress comment ID from a link inspector row.
	 *
	 * @param object $link DB link row.
	 * @return int
	 */
	private function get_comment_id_from_link( $link ) {
		return TSOLIIN_Support::get_comment_id_from_link_row( $link );
	}

	/**
	 * Replace a stored URL in the link's source (post, comment, widget, menu, or term).
	 *
	 * @param object $link    DB link row.
	 * @param string $new_url New URL.
	 * @return bool
	 */
	private function replace_link_url_in_source( $link, $new_url ) {
		if ( ! $link ) {
			return false;
		}
		$link_type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		$old_url   = (string) $link->link_url;
		$new_url   = (string) $new_url;

		if ( 'comment' === $link_type ) {
			return $this->update_url_in_comment( $link, $new_url );
		}
		if ( 'widget' === $link_type ) {
			return $this->scanner->replace_url_in_widget( (string) $link->source_key, $old_url, $new_url );
		}
		if ( 'menu' === $link_type ) {
			return $this->scanner->replace_url_in_menu_item( (string) $link->source_key, $old_url, $new_url );
		}
		if ( 'term' === $link_type ) {
			return $this->scanner->replace_url_in_term( (string) $link->source_key, $old_url, $new_url );
		}
		return $this->scanner->replace_url_in_post( (int) $link->post_id, $old_url, $new_url );
	}

	/**
	 * Update a URL that belongs to a comment (author URL or href in content).
	 *
	 * @param object $link    DB link row.
	 * @param string $new_url New URL.
	 * @return bool
	 */
	private function update_url_in_comment( $link, $new_url ) {
		$cid     = $this->get_comment_id_from_link( $link );
		$comment = $cid ? get_comment( $cid ) : null;
		if ( $comment ) {
			$old_url    = (string) $link->link_url;
			$author_url = trim( (string) $comment->comment_author_url );

			if ( $this->scanner->comment_author_url_matches_row_url( $author_url, $old_url ) ) {
				return false !== wp_update_comment( array(
					'comment_ID'         => $cid,
					'comment_author_url' => $new_url,
				) );
			}

			return $this->scanner->replace_url_in_comment_content( $cid, $old_url, $new_url );
		}

		// Fallback: search all comments on this post for the URL.
		$cids = $this->find_comment_ids_for_link( $link, 5 );
		if ( empty( $cids ) ) {
			return false;
		}
		$done = false;
		foreach ( $cids as $cid ) {
			$c = get_comment( absint( $cid ) );
			if ( ! $c ) {
				continue;
			}
			if ( $this->scanner->comment_author_url_matches_row_url( trim( (string) $c->comment_author_url ), (string) $link->link_url ) ) {
				wp_update_comment( array( 'comment_ID' => absint( $cid ), 'comment_author_url' => $new_url ) );
				$done = true;
			} elseif ( $this->scanner->replace_url_in_comment_content( absint( $cid ), (string) $link->link_url, $new_url ) ) {
				$done = true;
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

		if ( ! TSOLIIN_Support::can_unlink_link( $link ) ) {
			$blocked = $this->get_non_editable_source_message( $link );
			if ( '' === $blocked ) {
				$blocked = __( 'Cannot unlink this item.', 'tso-link-inspector' );
			}
			wp_send_json_error( array( 'message' => $blocked ) );
		}

		if ( 'comment' === $type ) {
			$done = $this->unlink_comment( $link );
		} elseif ( 'widget' === $type ) {
			$done = $this->scanner->unlink_in_widget( (string) $link->source_key, (string) $link->link_url );
		} elseif ( 'menu' === $type ) {
			$done = $this->scanner->unlink_in_menu_item( (string) $link->source_key, (string) $link->link_url );
		} elseif ( 'term' === $type ) {
			$done = $this->scanner->unlink_in_term( (string) $link->source_key, (string) $link->link_url );
		} else {
			$done = $this->scanner->unlink_in_post( (int) $link->post_id, $link->link_url, $type );
		}

		if ( ! $done ) {
			wp_send_json_error( array( 'message' => __( 'Cannot unlink this item. For comments, edit manually.', 'tso-link-inspector' ) ) );
		}
		$this->db->delete_link( $link_id );
		wp_send_json_success( array( 'message' => __( 'Link tag removed.', 'tso-link-inspector' ) ) );
	}

	/** @param object $link */
	private function unlink_comment( $link ) {
		$cid = $this->get_comment_id_from_link( $link );
		if ( $cid ) {
			return $this->scanner->unlink_in_comment( $cid, $link->link_url );
		}
		$cids = $this->find_comment_ids_for_link( $link, 10 );
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

	public function ajax_add_ignore() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$pattern = isset( $_POST['pattern'] ) ? sanitize_text_field( wp_unslash( $_POST['pattern'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link    = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'tso-link-inspector' ) ) );
		}

		if ( '' === $pattern ) {
			$pattern = TSOLIIN_HTTP::suggest_ignore_pattern_from_url( (string) $link->link_url );
		}
		if ( '' === $pattern ) {
			wp_send_json_error( array( 'message' => __( 'Could not derive an ignore pattern from this URL.', 'tso-link-inspector' ) ) );
		}

		$result = TSOLIIN_HTTP::add_ignore_pattern( $pattern );
		if ( false === $result ) {
			wp_send_json_error( array( 'message' => __( 'Could not derive an ignore pattern from this URL.', 'tso-link-inspector' ) ) );
		}
		if ( 'exists' === $result ) {
			wp_send_json_error( array( 'message' => __( 'This domain or URL is already on the ignore list.', 'tso-link-inspector' ) ) );
		}

		$r = $this->http->check( (string) $link->link_url, (int) $link->post_id );
		$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
		$updated = $this->db->get_link( $link_id );

		/* translators: %s: domain or URL prefix added to the ignore list */
		$message = sprintf( __( 'Added %s to the ignore list. This link is now skipped.', 'tso-link-inspector' ), $pattern );

		wp_send_json_success( $this->append_filter_match( array(
			'message'     => $message,
			'pattern'     => $pattern,
			'status_code' => (int) $r['status_code'],
			'css_class'   => TSOLIIN_HTTP::status_class( (int) $r['status_code'], (int) $r['is_broken'], (string) $link->link_url ),
			'label'       => TSOLIIN_HTTP::status_label( (int) $r['status_code'], (string) $link->link_url ),
			'is_broken'   => (int) $r['is_broken'],
			'status_html' => TSOLIIN_Support::render_link_status_html( $updated, $this->http ),
		), $updated ) );
	}

	public function ajax_dismiss_onboarding() {
		$this->check_nonce_and_cap();
		update_user_meta( get_current_user_id(), 'tsoliin_onboarding_dismissed', 1 );
		wp_send_json_success();
	}

	public function ajax_make_relative() {
		$this->check_nonce_and_cap();
		if ( ! TSOLIIN_Support::is_relative_url_tool_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Enable “Convert to /path” in Settings first.', 'tso-link-inspector' ) ) );
		}
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$link    = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'tso-link-inspector' ) ) );
		}

		$blocked = $this->get_non_editable_source_message( $link );
		if ( '' !== $blocked ) {
			wp_send_json_error( array( 'message' => $blocked ) );
		}

		$relative = TSOLIIN_HTTP::absolute_internal_to_relative( (string) $link->link_url );
		if ( false === $relative || $relative === (string) $link->link_url ) {
			wp_send_json_error( array( 'message' => __( 'This link cannot be converted to /path.', 'tso-link-inspector' ) ) );
		}

		$done = $this->replace_link_url_in_source( $link, $relative );

		if ( ! $done ) {
			wp_send_json_error( array( 'message' => __( 'Original URL not found in the source. Check whether the link was edited manually.', 'tso-link-inspector' ) ) );
		}

		$this->db->update_link_url( $link_id, $relative );
		$r = $this->http->check( $relative, (int) $link->post_id );
		$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
		$updated = $this->db->get_link( $link_id );

		$response = array_merge(
			array( 'new_url' => $relative, 'message' => __( 'Link saved as /path (domain removed).', 'tso-link-inspector' ) ),
			$this->status_payload_from_link( $updated, $relative, true )
		);
		wp_send_json_success( $this->append_filter_match( $response, $updated ) );
	}

	public function ajax_not_broken() {
		$this->check_nonce_and_cap();
		$link_id = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		if ( ! $link_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid ID.', 'tso-link-inspector' ) ) );
		}
		$link = $this->db->get_link( $link_id );
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'tso-link-inspector' ) ) );
		}
		$this->db->mark_as_not_broken( $link_id );
		$updated = $this->db->get_link( $link_id );
		wp_send_json_success( $this->append_filter_match( array(
			'message'     => __( 'Marked as OK and moved to Manual locks. It leaves that list only if the URL or redirect changes, or a check finds it broken.', 'tso-link-inspector' ),
			'css_class'   => 'tsoliin-status--ok',
			'label'       => __( 'OK (manual)', 'tso-link-inspector' ),
			'status_code' => 200,
			'status_html' => TSOLIIN_Support::render_link_status_html( $updated, $this->http ),
		), $updated ) );
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
				$link             = $this->resync_link_before_recheck( $link );
				if ( $link ) {
					$link_id = (int) $link->id;
				}
			}
			if ( ! $link ) {
				wp_send_json_success(
					array(
						'done'           => ( $index + 1 ) >= $total,
						'processed'      => $index + 1,
						'total'          => $total,
						'pct'            => (int) round( ( ( $index + 1 ) / $total ) * 100 ),
						'next_index'     => $index + 1,
						'row'            => array(
							'link_id'        => $link_id,
							'removed'        => true,
							'matches_filter' => false,
						),
						/* translators: 1: current, 2: total */
						'message'        => sprintf( __( 'Checking %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
					)
				);
			}
			if ( $link ) {
				// Keep user_verified: update_check_result() still runs HTTP and will override if the link is actually broken.
				$r = $this->http->check( $link->link_url, (int) $link->post_id );
				$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
				$updated  = $this->db->get_link( $link_id );
				$row_data = array_merge(
					array( 'link_id' => $link_id ),
					$this->status_payload_from_link( $updated, (string) $link->link_url, true )
				);
				$row_data = $this->append_filter_match( $row_data, $updated );
				if ( $updated ) {
					$row_data['new_url'] = (string) $updated->link_url;
				}
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
			$skipped = false;
			if ( $link ) {
				if ( ! TSOLIIN_Support::can_unlink_link( $link ) ) {
					$ok      = false;
					$skipped = true;
				} elseif ( 'comment' === $type ) {
					$ok = $this->unlink_comment( $link );
				} elseif ( 'widget' === $type ) {
					$ok = $this->scanner->unlink_in_widget( (string) $link->source_key, (string) $link->link_url );
				} elseif ( 'menu' === $type ) {
					$ok = $this->scanner->unlink_in_menu_item( (string) $link->source_key, (string) $link->link_url );
				} elseif ( 'term' === $type ) {
					$ok = $this->scanner->unlink_in_term( (string) $link->source_key, (string) $link->link_url );
				} else {
					$ok = $this->scanner->unlink_in_post( (int) $link->post_id, $link->link_url, $type );
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
				'skipped'    => $skipped,
				/* translators: 1: current, 2: total */
				'message'    => sprintf( __( 'Unlinking %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
			) );
		} elseif ( 'not_broken' === $action ) {
			$processed = 0;
			foreach ( $link_ids as $lid ) {
				if ( $this->db->mark_as_not_broken( $lid ) ) {
					$processed++;
				}
			}
			/* translators: %d: count of links marked as OK */
			wp_send_json_success( array( 'done' => true, 'processed' => $processed, 'message' => sprintf( __( '%d marked as OK and moved to Manual locks.', 'tso-link-inspector' ), $processed ) ) );
		} elseif ( 'make_relative' === $action ) {
			if ( ! TSOLIIN_Support::is_relative_url_tool_enabled() ) {
				wp_send_json_error( array( 'message' => __( 'Enable “Convert to /path” in Settings first.', 'tso-link-inspector' ) ) );
			}
			$index   = isset( $_POST['index'] ) ? absint( $_POST['index'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
			$total   = count( $link_ids );
			$link_id = isset( $link_ids[ $index ] ) ? $link_ids[ $index ] : 0;
			if ( ! $link_id ) {
				wp_send_json_success( array( 'done' => true, 'processed' => $total ) );
			}
			$link      = $this->db->get_link( $link_id );
			$converted = false;
			$skipped   = false;
			$row_data  = array( 'link_id' => $link_id );
			if ( $link ) {
				if ( $this->is_non_editable_source( $link ) ) {
					$skipped = true;
				} else {
					$relative = TSOLIIN_HTTP::absolute_internal_to_relative( (string) $link->link_url );
					if ( false === $relative || $relative === (string) $link->link_url ) {
						$skipped = true;
					} elseif ( $this->replace_link_url_in_source( $link, $relative ) ) {
						$this->db->update_link_url( $link_id, $relative );
						$r = $this->http->check( $relative, (int) $link->post_id );
						$this->db->update_check_result( $link_id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
						$updated   = $this->db->get_link( $link_id );
						$row_data  = array_merge(
							array( 'link_id' => $link_id, 'new_url' => $relative ),
							$this->status_payload_from_link( $updated, $relative, true )
						);
						$row_data  = $this->append_filter_match( $row_data, $updated );
						$converted = true;
					}
				}
			}
			wp_send_json_success(
				array(
					'done'       => ( $index + 1 ) >= $total,
					'processed'  => $index + 1,
					'total'      => $total,
					'pct'        => (int) round( ( ( $index + 1 ) / $total ) * 100 ),
					'next_index' => $index + 1,
					'link_id'    => $link_id,
					'converted'  => $converted,
					'skipped'    => $skipped,
					'row'        => $row_data,
					/* translators: 1: current, 2: total */
					'message'    => sprintf( __( 'Converting %1$d of %2$d...', 'tso-link-inspector' ), $index + 1, $total ),
				)
			);
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
		$resume  = ! isset( $_POST['resume'] ) || '0' !== sanitize_text_field( wp_unslash( $_POST['resume'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$this->cron->start_bg_check( $resume, $post_id );
		$bg = $this->cron->get_bg_progress();
		wp_send_json_success( array(
			'running' => true,
			'checked' => $bg['checked'],
			'total'   => $bg['total'],
			'pct'     => $bg['pct'],
			'post_id' => $bg['post_id'],
			'message' => $post_id > 0
				? __( 'Checking links in this post. You can continue browsing.', 'tso-link-inspector' )
				: __( 'Check started. You can continue browsing.', 'tso-link-inspector' ),
		) );
	}

	public function ajax_stop_bg_check() {
		$this->check_nonce_and_cap();
		$this->cron->stop_bg_check();
		wp_send_json_success( array( 'running' => false ) );
	}

	public function ajax_check_progress() {
		$this->check_nonce_and_cap();
		$bg      = $this->cron->get_bg_progress();
		$post_id = isset( $bg['post_id'] ) ? absint( $bg['post_id'] ) : 0;
		$stats   = $post_id ? $this->db->get_stats_for_post( $post_id ) : $this->db->get_stats();
		$done    = ! $bg['running'] && $bg['total'] > 0 && empty( $this->db->get_links_batch_for_check( 1, $post_id ) );
		if ( $bg['running'] ) {
			/* translators: 1: checked count, 2: total count */
			$message = sprintf( __( 'Checking %1$d of %2$d...', 'tso-link-inspector' ), $bg['checked'], $bg['total'] );
		} elseif ( $done ) {
			$message = __( 'Check completed!', 'tso-link-inspector' );
		} else {
			$message = __( 'Stopped', 'tso-link-inspector' );
		}
		wp_send_json_success( array(
			'running' => $bg['running'],
			'checked' => $bg['checked'],
			'total'   => $bg['total'],
			'pct'     => $bg['pct'],
			'post_id' => $post_id,
			'broken'  => $stats['broken'],
			'done'    => $done,
			'message' => $message,
		) );
	}

	/**
	 * Return dashboard filter tab + stat card counts (optionally scoped to one post).
	 */
	public function ajax_get_stats() {
		$this->check_nonce_and_cap();
		$post_id = isset( $_POST['post_id'] ) ? absint( $_POST['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$stats   = $post_id ? $this->db->get_stats_for_post( $post_id ) : $this->db->get_stats();
		$display = array();
		foreach ( $stats as $key => $value ) {
			$display[ $key ] = TSOLIIN_Support::format_display_number( (int) $value );
		}
		wp_send_json_success(
			array(
				'stats'   => $stats,
				'display' => $display,
			)
		);
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
		$bot_blocked  = false;
		$link_broken  = ! empty( $link->is_broken );

		// If the DB already has a known redirect_url, offer it as first (instant) suggestion — except when
		// applying it would pin a “rolling release” download to one file version (handled as transparent redirect).
		$orig_abs = TSOLIIN_Scanner::resolve_to_absolute_url( (string) $link->link_url, (int) $link->post_id );
		$r_orig   = $this->http->check( $orig_abs, (int) $link->post_id );
		if ( ! empty( $link->redirect_url ) ) {
			$rurl = (string) $link->redirect_url;
			$skip_redirect_suggest = $this->http->is_transparent_redirect( (string) $link->link_url, $rurl )
				|| TSOLIIN_HTTP::is_chrome_webstore_unavailable_url( $rurl )
				|| (
					TSOLIIN_HTTP::is_plain_http_url( $orig_abs )
					&& TSOLIIN_HTTP::is_plain_http_url( $rurl )
					&& TSOLIIN_HTTP::is_http_same_resource_bar_www( $orig_abs, $rurl )
				)
				|| (
					! empty( $link->is_broken )
					&& TSOLIIN_HTTP::is_http_same_resource_bar_www( $orig_abs, $rurl )
				);
			if ( ! $skip_redirect_suggest ) {
				$r_dest       = $this->http->check( $rurl, (int) $link->post_id );
				$sc           = (int) $r_dest['status_code'];
				$display_code = $sc;
				$reason       = __( 'Destination detected (re-checked now)', 'tso-link-inspector' );
				if ( TSOLIIN_HTTP::is_bot_block_status( $sc ) ) {
					$bot_blocked = true;
					$stored_code = (int) $link->status_code;
					if ( in_array( $stored_code, array( 301, 302, 303, 307, 308 ), true ) ) {
						$display_code = $stored_code;
						$reason       = __( 'Redirect destination already detected by scan (re-check blocked)', 'tso-link-inspector' );
					}
				}
				$suggestions[] = array(
					'url'         => $rurl,
					'status_code' => $display_code,
					'label'       => TSOLIIN_HTTP::status_label( $display_code, $rurl ),
					'reason'      => $reason,
					'confidence'  => 'high',
					'actionable'  => TSOLIIN_HTTP::suggestion_fixes_broken_link( $r_orig, $r_dest, (int) $link->status_code )
						|| (
							! $link_broken
							&& in_array( (int) $link->status_code, array( 301, 302, 303, 307, 308 ), true )
							&& $this->http->is_meaningful_redirect_target( $orig_abs, $rurl )
						),
				);
				$seen_urls[] = $rurl;
			}
		}

		// Run smart suggest to find additional alternatives.
		foreach ( $this->http->smart_suggest( $link->link_url, (int) $link->post_id ) as $s ) {
			if ( ! in_array( $s['url'], $seen_urls, true ) ) {
				if ( ! empty( $s['status_code'] ) && TSOLIIN_HTTP::is_bot_block_status( (int) $s['status_code'] ) ) {
					$bot_blocked = true;
				}
				$suggestions[] = $s;
				$seen_urls[]   = $s['url'];
			}
		}

		$safe_suggestions = array();
		foreach ( $suggestions as $suggestion ) {
			$safe_url = TSOLIIN_HTTP::sanitize_external_http_url( isset( $suggestion['url'] ) ? $suggestion['url'] : '' );
			if ( false === $safe_url ) {
				continue;
			}
			$r_live = $this->http->check( $safe_url, (int) $link->post_id );
			if ( TSOLIIN_HTTP::is_bot_block_status( (int) $r_live['status_code'] ) ) {
				$bot_blocked = true;
			}
			$suggestion['url']         = $safe_url;
			$suggestion['status_code'] = (int) $r_live['status_code'];
			$suggestion['label']       = TSOLIIN_HTTP::status_label( (int) $r_live['status_code'], $safe_url );
			$suggestion['reason']      = sanitize_text_field( isset( $suggestion['reason'] ) ? (string) $suggestion['reason'] : '' );
			$unverified                = ! empty( $suggestion['unverified'] )
				|| (
					TSOLIIN_HTTP::is_bot_wall_host( $safe_url )
					&& TSOLIIN_HTTP::is_bot_block_status( (int) $r_live['status_code'] )
					&& TSOLIIN_HTTP::is_trusted_canonical_upgrade( $orig_abs, $safe_url )
				);
			$suggestion['unverified']  = $unverified;
			$suggestion['actionable']  = TSOLIIN_HTTP::suggestion_fixes_broken_link( $r_orig, $r_live, (int) $link->status_code )
				|| ( $unverified && TSOLIIN_HTTP::is_trusted_canonical_upgrade( $orig_abs, $safe_url ) );
			$safe_suggestions[]        = $suggestion;
		}

		$safe_suggestions = array_values(
			array_filter(
				$safe_suggestions,
				function ( $row ) use ( $orig_abs, $link_broken, $link ) {
					if ( $link_broken ) {
						if ( empty( $row['actionable'] ) ) {
							return false;
						}
						$target = isset( $row['url'] ) ? (string) $row['url'] : '';
						if (
							'' !== $target
							&& TSOLIIN_HTTP::is_resource_not_found_status( (int) $link->status_code )
							&& TSOLIIN_HTTP::is_http_same_resource_bar_www( $orig_abs, $target )
						) {
							return false;
						}
						return true;
					}
					if ( ! empty( $row['actionable'] ) ) {
						return true;
					}
					$target = isset( $row['url'] ) ? (string) $row['url'] : '';
					if ( '' === $target ) {
						return false;
					}
					if ( ! empty( $row['unverified'] ) && TSOLIIN_HTTP::is_trusted_canonical_upgrade( $orig_abs, $target ) ) {
						return true;
					}
					if ( TSOLIIN_HTTP::is_trusted_canonical_upgrade( $orig_abs, $target ) && TSOLIIN_HTTP::is_plain_http_url( $orig_abs ) ) {
						return true;
					}
					// Keep redirect targets the scanner already found (e.g. http legacy host → https canonical).
					return $this->http->is_meaningful_redirect_target( $orig_abs, $target );
				}
			)
		);

		$has_actionable = ! empty(
			array_filter(
				$safe_suggestions,
				static function ( $row ) {
					return ! empty( $row['actionable'] );
				}
			)
		);

		$note = '';
		if ( empty( $safe_suggestions ) ) {
			if ( $bot_blocked ) {
				$note = __( 'The destination blocks automated checks (403/401/429). It may work in a browser, but your server cannot confirm it — verify manually before editing the link.', 'tso-link-inspector' );
			} elseif ( $link_broken ) {
				$note = __( 'No alternative URL could fix this broken link. The destination may be permanently gone — update or remove the link manually.', 'tso-link-inspector' );
			} elseif ( TSOLIIN_HTTP::is_plain_http_url( $orig_abs ) ) {
				$note = __( 'No HTTPS upgrade or other useful alternative was found. The site may only offer HTTP, so there is nothing helpful to apply—changing www alone would not fix the insecure HTTP link.', 'tso-link-inspector' );
			}
		} elseif ( $bot_blocked && $has_actionable ) {
			$note = __( 'Social networks often block server checks. The suggested HTTPS URL should work in a browser — open it to confirm before applying.', 'tso-link-inspector' );
		} elseif ( $bot_blocked && ! $has_actionable ) {
			$note = __( 'The destination blocks automated checks (403/401/429). It may work in a browser, but your server cannot confirm it — verify manually before editing the link.', 'tso-link-inspector' );
		}

		wp_send_json_success( array(
			'link_id'     => $link_id,
			'original'    => $link->link_url,
			'suggestions' => $safe_suggestions,
			'count'       => count( $safe_suggestions ),
			'note'        => $note,
		) );
	}

	public function ajax_link_preview() {
		$this->check_nonce_and_cap();
		$link_id     = isset( $_POST['link_id'] ) ? absint( $_POST['link_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing
		$new_url_raw = isset( $_POST['new_url'] ) ? wp_unslash( $_POST['new_url'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$link        = $link_id ? $this->db->get_link( $link_id ) : null;
		if ( ! $link ) {
			wp_send_json_error( array( 'message' => __( 'Link not found.', 'tso-link-inspector' ) ) );
		}

		$blocked = $this->get_non_editable_source_message( $link );
		if ( '' !== $blocked ) {
			wp_send_json_error( array( 'message' => $blocked ) );
		}

		$new_url = TSOLIIN_HTTP::sanitize_editable_link_url( $new_url_raw, (int) $link->post_id );
		if ( false === $new_url ) {
			wp_send_json_error( array( 'message' => __( 'Invalid URL.', 'tso-link-inspector' ) ) );
		}

		$link_type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		$preview   = $this->scanner->preview_url_change_in_post(
			(int) $link->post_id,
			(string) $link->link_url,
			$new_url,
			$link_type
		);
		wp_send_json_success( $preview );
	}

	public function ajax_export_csv() {
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'tsoliin_action' ) ) {
			wp_die( esc_html__( 'Nonce invalid.', 'tso-link-inspector' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-link-inspector' ) );
		}

		$filter  = isset( $_GET['filter'] ) ? $this->sanitize_list_filter( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$quality = $this->get_list_quality_filter_from_request();
		$scope   = $this->get_scope_from_request();
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		TSOLIIN_Reports::stream_csv(
			$this->db,
			array(
				'filter'         => $filter,
				'quality_filter' => $quality,
				'scope'          => $scope,
				'search'         => $search,
				'post_id'        => $post_id,
			)
		);
	}

	public function ajax_export_pdf() {
		$nonce = isset( $_GET['nonce'] ) ? sanitize_text_field( wp_unslash( $_GET['nonce'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'tsoliin_action' ) ) {
			wp_die( esc_html__( 'Nonce invalid.', 'tso-link-inspector' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'tso-link-inspector' ) );
		}

		$filter  = isset( $_GET['filter'] ) ? $this->sanitize_list_filter( wp_unslash( $_GET['filter'] ) ) : 'all'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$quality = $this->get_list_quality_filter_from_request();
		$scope   = $this->get_scope_from_request();
		$post_id = isset( $_GET['post_id'] ) ? absint( $_GET['post_id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		TSOLIIN_Reports::stream_pdf_html(
			$this->db,
			array(
				'filter'         => $filter,
				'quality_filter' => $quality,
				'scope'          => $scope,
				'search'         => $search,
				'post_id'        => $post_id,
			)
		);
	}

	public function ajax_diagnose() {
		$this->check_nonce_and_cap();
		$info  = array();
		$test  = $this->db->self_test();
		$info[] = ( $test['table_exists'] ? 'OK' : 'ERR' ) . ' ' . __( 'DB table:', 'tso-link-inspector' ) . ' ' . $this->db->get_table();
		$info[] = ( $test['insert_ok'] ? 'OK' : 'ERR' ) . ' ' . __( 'INSERT test', 'tso-link-inspector' ) . ( $test['error'] ? ': ' . $test['error'] : '' );
		$pts    = $this->scanner->get_post_types();
		$info[] = 'OK ' . __( 'Post types:', 'tso-link-inspector' ) . ' ' . implode( ', ', $pts );
		$total  = $this->scanner->get_total_posts();
		$info[] = 'OK ' . __( 'Published posts:', 'tso-link-inspector' ) . ' ' . $total;
		$ids    = $this->scanner->get_post_ids( 1, 1 );
		if ( ! empty( $ids ) ) {
			$post   = get_post( $ids[0] );
			$info[] = 'OK ' . __( 'First post:', 'tso-link-inspector' ) . ' ' . $ids[0] . ' "' . esc_html( (string) $post->post_title ) . '"';
			$html   = do_blocks( $post->post_content );
			$links  = $this->scanner->extract_links( $html );
			$info[] = 'OK ' . __( 'Links in first post:', 'tso-link-inspector' ) . ' ' . count( $links );
			$n      = $this->scanner->scan_post( $ids[0] );
			$info[] = 'OK scan_post(): ' . $n;
		}
		$stats  = $this->db->get_stats();
		$info[] = 'OK ' . __( 'DB records:', 'tso-link-inspector' ) . ' ' . $stats['total'];
		wp_send_json_success( array( 'lines' => $info ) );
	}
}
