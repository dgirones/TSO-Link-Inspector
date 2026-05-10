<?php
/**
 * Plugin Name:       TSO Link Inspector
 * Description:       Find and fix broken links across your entire WordPress site without opening each post.
 * Version:           1.9.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Tested up to:       6.9
 * Author:            Tu Soporte Online
 * Author URI:        https://tusoporteonline.es
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       tso-link-inspector
 * Domain Path:       /languages
 *
 * @package TSOLIIN_Link_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'TSOLIIN_VERSION',    '1.9.1' );
define( 'TSOLIIN_PLUGIN_FILE', __FILE__ );
define( 'TSOLIIN_PLUGIN_DIR',  plugin_dir_path( __FILE__ ) );
define( 'TSOLIIN_PLUGIN_URL',  plugin_dir_url( __FILE__ ) );
define( 'TSOLIIN_TEXT_DOMAIN', 'tso-link-inspector' );
define( 'TSOLIIN_BATCH_SIZE',  10 );

/**
 * Main plugin class.
 *
 * @since 1.0.0
 */
final class TSOLIIN_Link_Inspector {

	/** @var TSOLIIN_Link_Inspector|null */
	private static $instance = null;

	/** @var TSOLIIN_DB */
	public $db;

	/** @var TSOLIIN_Scanner */
	public $scanner;

	/** @var TSOLIIN_HTTP */
	public $http;

	/** @var TSOLIIN_Cron */
	public $cron;

	/** @var TSOLIIN_Admin|null */
	public $admin;

	/** @var array<string,string> */
	private $runtime_translations = array();

	/**
	 * Get singleton instance.
	 *
	 * @return TSOLIIN_Link_Inspector
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->load_files();
		$this->init_objects();
		$this->init_hooks();
	}

	/**
	 * Load required PHP files.
	 */
	private function load_files() {
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-db.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-http.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-scanner.php';
		require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-cron.php';
		if ( is_admin() ) {
			require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-list-table.php';
			require_once TSOLIIN_PLUGIN_DIR . 'includes/class-tso-lc-admin.php';
		}
	}

	/**
	 * Instantiate component objects.
	 */
	private function init_objects() {
		$this->db      = new TSOLIIN_DB();
		$this->http    = new TSOLIIN_HTTP();
		$this->scanner = new TSOLIIN_Scanner( $this->db );
		$this->cron    = new TSOLIIN_Cron( $this->db, $this->scanner, $this->http );
		if ( is_admin() ) {
			$this->admin = new TSOLIIN_Admin( $this->db, $this->scanner, $this->http, $this->cron );
		}
	}

	/**
	 * Register global hooks.
	 */
	private function init_hooks() {
		// plugin_locale filter fires before JIT translation loading — must be registered here.
		add_filter( 'plugin_locale', array( $this, 'force_plugin_locale' ), 10, 2 );
		add_filter( 'gettext', array( $this, 'runtime_gettext_fallback' ), 999, 3 );
		add_action( 'init',       array( $this, 'load_textdomain' ), 1 );
		add_action( 'admin_init', array( $this, 'maybe_upgrade_db' ) );
		add_action( 'deleted_comment', array( $this, 'on_deleted_comment' ), 10, 2 );
		// Re-scan post automatically when saved in editor (not during plugin AJAX calls).
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- only checking action name to guard hook registration, no data processed.
		if ( ! ( defined( 'DOING_AJAX' ) && DOING_AJAX && isset( $_REQUEST['action'] ) && 0 === strpos( sanitize_key( wp_unslash( $_REQUEST['action'] ) ), 'tsoliin_' ) ) ) {
			add_action( 'save_post', array( $this, 'on_save_post' ), 20, 2 );
		}
		// Nofollow broken links on frontend (optional).
		$s = get_option( 'tsoliin_settings', array() );
		if ( ! empty( $s['nofollow_broken'] ) ) {
			add_filter( 'the_content', array( $this, 'add_nofollow_to_broken' ), 20 );
		}
		register_activation_hook( TSOLIIN_PLUGIN_FILE,   array( $this, 'on_activate' ) );
		register_deactivation_hook( TSOLIIN_PLUGIN_FILE, array( $this, 'on_deactivate' ) );
	}

	/**
	 * Load plugin translations.
	 */
	public function load_textdomain() {
		$language = $this->get_selected_language();
		$this->runtime_translations = array();

		// Unload any auto-loaded translation first.
		unload_textdomain( TSOLIIN_TEXT_DOMAIN );

		if ( 'en' === $language ) {
			// English: no .mo file needed, strings use code default.
			return;
		}

		if ( '' !== $language ) {
			// Explicit plugin language selected in settings.
			$this->load_locale_translations( $language );
			return;
		}

		// Automatic mode: try WP locale plus language-only fallback from bundled files.
		$auto_locale = function_exists( 'determine_locale' ) ? determine_locale() : get_locale();
		$candidates  = array( (string) $auto_locale );
		if ( false !== strpos( (string) $auto_locale, '_' ) ) {
			$candidates[] = substr( (string) $auto_locale, 0, strpos( (string) $auto_locale, '_' ) );
		}
		if ( 0 === strpos( (string) $auto_locale, 'es' ) ) {
			$candidates[] = 'es_ES';
		}
		if ( 0 === strpos( (string) $auto_locale, 'ca' ) ) {
			$candidates[] = 'ca';
		}
		foreach ( array_unique( array_filter( $candidates ) ) as $candidate ) {
			if ( $this->load_locale_translations( $candidate ) ) {
				break;
			}
		}
	}

	/**
	 * Load translations for a specific locale from bundled files.
	 * Supports the current slug and a legacy fallback slug for compatibility.
	 *
	 * @param string $locale Locale code (e.g. es_ES, ca).
	 * @return bool True when at least one translation file was loaded.
	 */
	private function load_locale_translations( $locale ) {
		$locale = trim( (string) $locale );
		if ( '' === $locale ) {
			return false;
		}

		$po_candidates = array(
			TSOLIIN_PLUGIN_DIR . 'languages/' . TSOLIIN_TEXT_DOMAIN . '-' . $locale . '.po',
			TSOLIIN_PLUGIN_DIR . 'languages/tso-link-checker-' . $locale . '.po',
		);
		$mo_candidates = array(
			TSOLIIN_PLUGIN_DIR . 'languages/' . TSOLIIN_TEXT_DOMAIN . '-' . $locale . '.mo',
			TSOLIIN_PLUGIN_DIR . 'languages/tso-link-checker-' . $locale . '.mo',
		);

		$loaded = false;
		foreach ( $po_candidates as $po_file ) {
			if ( file_exists( $po_file ) ) {
				$this->runtime_translations = $this->parse_po_translations( $po_file );
				$loaded                     = ! empty( $this->runtime_translations );
				break;
			}
		}
		foreach ( $mo_candidates as $mo_file ) {
			if ( file_exists( $mo_file ) ) {
				load_textdomain( TSOLIIN_TEXT_DOMAIN, $mo_file );
				$loaded = true;
				break;
			}
		}

		return $loaded;
	}

	/**
	 * Runtime translation fallback from .po map.
	 *
	 * @param string $translation Current translation.
	 * @param string $text        Original text.
	 * @param string $domain      Text domain.
	 * @return string
	 */
	public function runtime_gettext_fallback( $translation, $text, $domain ) {
		if ( TSOLIIN_TEXT_DOMAIN !== $domain ) {
			return $translation;
		}
		if ( isset( $this->runtime_translations[ $text ] ) && '' !== $this->runtime_translations[ $text ] ) {
			return $this->runtime_translations[ $text ];
		}
		return $translation;
	}

	/**
	 * Filter: force the locale used for this plugin.
	 * Called before any __() translation so locale is set correctly for JIT loading.
	 *
	 * @param string $locale Current locale.
	 * @param string $domain Text domain.
	 * @return string
	 */
	public function force_plugin_locale( $locale, $domain ) {
		if ( TSOLIIN_TEXT_DOMAIN !== $domain ) {
			return $locale;
		}
		$language = $this->get_selected_language();

		if ( 'en' === $language ) {
			// Return a non-existent locale so no translation file is found.
			return 'en_US_no_translation';
		}
		if ( '' !== $language ) {
			return $language; // e.g. 'es_ES' or 'ca'
		}
		return $locale; // Automatic: use site locale.
	}

	/**
	 * Get selected plugin language from settings.
	 *
	 * @return string
	 */
	private function get_selected_language() {
		$settings      = get_option( 'tsoliin_settings', array() );
		$allowed_langs = array( '', 'ca', 'es_ES', 'en' );
		return ( isset( $settings['language'] ) && in_array( $settings['language'], $allowed_langs, true ) )
			? (string) $settings['language'] : '';
	}

	/**
	 * Parse gettext .po file into msgid => msgstr map.
	 *
	 * @param string $file_path PO file path.
	 * @return array<string,string>
	 */
	private function parse_po_translations( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return array();
		}
		$lines = file( $file_path, FILE_IGNORE_NEW_LINES );
		if ( false === $lines ) {
			return array();
		}

		$flush_pair = static function ( &$map, $id, $str ) {
			if ( '' !== $id && '' !== $str ) {
				$map[ $id ] = $str;
			}
		};

		$map        = array();
		$state      = '';
		$current_id = '';
		$current_tr = '';
		foreach ( $lines as $line ) {
			$line = trim( (string) $line );
			if ( '' === $line || '#' === substr( $line, 0, 1 ) ) {
				$flush_pair( $map, $current_id, $current_tr );
				$state      = '';
				$current_id = '';
				$current_tr = '';
				continue;
			}

			// Skip plural / context lines (not used by this plugin UI strings).
			if ( 0 === strpos( $line, 'msgid_plural' ) || 0 === strpos( $line, 'msgctxt' ) || 0 === strpos( $line, 'msgstr[' ) ) {
				continue;
			}

			if ( 0 === strpos( $line, 'msgid "' ) ) {
				$flush_pair( $map, $current_id, $current_tr );
				$state      = 'id';
				$current_id = stripcslashes( substr( $line, 7, -1 ) );
				$current_tr = '';
				continue;
			}
			if ( 0 === strpos( $line, 'msgstr "' ) ) {
				$state      = 'str';
				$current_tr = stripcslashes( substr( $line, 8, -1 ) );
				continue;
			}
			if ( '"' === substr( $line, 0, 1 ) && strlen( $line ) > 1 && '"' === substr( $line, -1 ) ) {
				$chunk = stripcslashes( substr( $line, 1, -1 ) );
				if ( 'id' === $state ) {
					$current_id .= $chunk;
				} elseif ( 'str' === $state ) {
					$current_tr .= $chunk;
				}
			}
		}
		$flush_pair( $map, $current_id, $current_tr );
		return $map;
	}

	
	/**
	 * Run DB upgrades when version changes.
	 */
	public function maybe_upgrade_db() {
		$this->maybe_migrate_legacy_prefix_options();
		$installed = get_option( 'tsoliin_version', false );
		if ( false === $installed || '' === (string) $installed ) {
			$installed = (string) get_option( 'tso_lc_version', '0' );
		} else {
			$installed = (string) $installed;
		}
		if ( version_compare( $installed, TSOLIIN_VERSION, '<' ) ) {
			$this->db->create_table();
			$this->db->migrate_legacy_error_codes();
			$this->db->migrate_comment_source_keys();
			$this->db->cleanup_trivial_redirects();
			$this->db->cleanup_querystring_redirects();
			$this->cron->schedule();
			update_option( 'tsoliin_version', TSOLIIN_VERSION, false );
		} else {
			$this->db->ensure_table_exists();
		}
	}

	/**
	 * Copy options and clear legacy cron hooks from pre-1.8.3 installs (tso_lc_* options and cron).
	 */
	private function maybe_migrate_legacy_prefix_options() {
		static $done = false;
		if ( $done ) {
			return;
		}
		$done = true;

		$map = array(
			'tso_lc_settings'              => 'tsoliin_settings',
			'tso_lc_version'               => 'tsoliin_version',
			'tso_lc_last_full_scan'        => 'tsoliin_last_full_scan',
			'tso_lc_last_check_batch'      => 'tsoliin_last_check_batch',
			'tso_lc_last_check_count'      => 'tsoliin_last_check_count',
			'tso_lc_bg_check_running'      => 'tsoliin_bg_check_running',
			'tso_lc_bg_check_total'        => 'tsoliin_bg_check_total',
			'tso_lc_bg_check_checked'      => 'tsoliin_bg_check_checked',
			'tso_lc_bg_check_started'      => 'tsoliin_bg_check_started',
			'tso_lc_total_posts_scanned'   => 'tsoliin_total_posts_scanned',
		);

		$migrated = false;
		foreach ( $map as $old_key => $new_key ) {
			$old_val = get_option( $old_key, false );
			if ( false === $old_val ) {
				continue;
			}
			$new_val = get_option( $new_key, false );
			if ( false !== $new_val ) {
				delete_option( $old_key );
				$migrated = true;
				continue;
			}
			update_option( $new_key, $old_val, false );
			delete_option( $old_key );
			$migrated = true;
		}

		if ( $migrated ) {
			foreach ( array( 'tso_lc_cron_scan', 'tso_lc_cron_check', 'tso_lc_bg_check_step' ) as $legacy_hook ) {
				wp_clear_scheduled_hook( $legacy_hook );
			}
		}
	}

	/**
	 * Plugin activation.
	 */
	public function on_activate() {
		$this->maybe_migrate_legacy_prefix_options();
		$this->db->create_table();
		$this->db->migrate_comment_source_keys();
		$this->cron->schedule();
		update_option( 'tsoliin_version', TSOLIIN_VERSION, false );
	}

	/**
	 * Plugin deactivation.
	 */
	public function on_deactivate() {
		$this->cron->unschedule();
	}

	/**
	 * When a post is saved in the editor, re-scan it so the DB reflects
	 * any manual URL changes the user made.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public function on_save_post( $post_id, $post ) {
		// Skip auto-saves, revisions, trashed posts, and non-publish.
		if ( wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			return;
		}
		// Only scan post types we are configured to track.
		$post_types = $this->scanner->get_post_types();
		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}
		// Re-scan: updates DB with current links in post_content.
		$this->scanner->scan_post( $post_id, true ); // force all types
	}

	/**
	 * Remove inspector rows tied to a deleted comment (source_key c-{id}-*).
	 *
	 * @param int        $comment_id Comment ID.
	 * @param WP_Comment $comment    Comment object (may be empty in some WP versions).
	 */
	public function on_deleted_comment( $comment_id, $comment = null ) {
		$this->db->delete_links_for_comment( (int) $comment_id );
	}

	/**
	 * Add rel="nofollow" to broken links in post content (frontend only).
	 *
	 * @param string $content Post content.
	 * @return string
	 */
	public function add_nofollow_to_broken( $content ) {
		if ( ! is_singular() ) {
			return $content;
		}
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}
		// Get broken URLs for this post from the DB.
		global $wpdb;
		$table       = $this->db->get_table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$broken_urls = $wpdb->get_col( $wpdb->prepare(
			"SELECT link_url FROM {$table} WHERE post_id = %d AND is_broken = 1",
			$post_id
		) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( empty( $broken_urls ) ) {
			return $content;
		}
		// Add rel="nofollow" to each broken link.
		foreach ( $broken_urls as $url ) {
			$url     = (string) $url;
			$escaped = preg_quote( $url, '#' );
			$content = preg_replace_callback(
				'#<a(\s[^>]*href=["\']{1}' . $escaped . '["\']{1}[^>]*)>#i',
				function ( $m ) {
					$attrs = $m[1];
					// Add nofollow to existing rel or create new one.
					if ( preg_match( '/rel=["\'](.*?)["\']/i', $attrs, $rm ) ) {
						$rels = array_filter( array_map( 'trim', explode( ' ', $rm[1] ) ) );
						if ( ! in_array( 'nofollow', $rels, true ) ) {
							$rels[] = 'nofollow';
						}
						$attrs = preg_replace( '/rel=["\'](.*?)["\']/i', 'rel="' . implode( ' ', $rels ) . '"', $attrs );
					} else {
						$attrs .= ' rel="nofollow"';
					}
					return '<a' . $attrs . '>';
				},
				$content
			);
		}
		return $content;
	}
}

/**
 * Return the main plugin instance.
 *
 * @return TSOLIIN_Link_Inspector
 */
function tsoliin_link_inspector() {
	return TSOLIIN_Link_Inspector::get_instance();
}

add_action( 'plugins_loaded', 'tsoliin_link_inspector' );
