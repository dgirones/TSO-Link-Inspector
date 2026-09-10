<?php
/**
 * Discover shortcodes, blocks, and meta prefixes owned by each plugin.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOSI_PROFILE_MAX_FILE_BYTES' ) ) {
	define( 'TSOSI_PROFILE_MAX_FILE_BYTES', 262144 );
}

if ( ! defined( 'TSOSI_PROFILE_MAX_FILES' ) ) {
	define( 'TSOSI_PROFILE_MAX_FILES', 40 );
}

if ( ! defined( 'TSOSI_PROFILE_MAX_BLOCK_JSON' ) ) {
	define( 'TSOSI_PROFILE_MAX_BLOCK_JSON', 30 );
}

/**
 * Installed plugins keyed by plugin file.
 *
 * @return array<string,array<string,string>>
 */
function tsosi_get_installed_plugins() {
	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return get_plugins();
}

/**
 * @param string $plugin_file Plugin basename.
 * @return string Folder slug relative to wp-content/plugins (unsanitized).
 */
function tsosi_get_plugin_folder_raw( $plugin_file ) {
	$folder = dirname( (string) $plugin_file );
	if ( '.' === $folder || '' === $folder ) {
		return '';
	}
	return $folder;
}

/**
 * @param string $plugin_file Plugin basename.
 * @return string Sanitized folder key for maps/logs.
 */
function tsosi_get_plugin_folder( $plugin_file ) {
	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	return '' === $folder ? '' : sanitize_key( $folder );
}

/**
 * Absolute path to a plugin directory.
 *
 * @param string $plugin_file Plugin basename.
 * @return string
 */
function tsosi_get_plugin_dir( $plugin_file ) {
	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	if ( '' === $folder ) {
		return trailingslashit( WP_PLUGIN_DIR );
	}
	return trailingslashit( WP_PLUGIN_DIR ) . $folder;
}

/**
 * Whether a plugin is active.
 *
 * @param string $plugin_file Plugin basename.
 * @return bool
 */
function tsosi_is_plugin_active_file( $plugin_file ) {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	return is_plugin_active( (string) $plugin_file );
}

/**
 * Validate a plugin basename against installed plugins (blocks path traversal).
 *
 * @param string $plugin_file Plugin basename from request or UI.
 * @return string Sanitized basename, or empty when not an installed plugin.
 */
function tsosi_sanitize_plugin_file( $plugin_file ) {
	$plugin_file = sanitize_text_field( (string) $plugin_file );
	if ( '' === $plugin_file || false !== strpos( $plugin_file, '..' ) ) {
		return '';
	}
	$plugins = tsosi_get_installed_plugins();
	if ( ! isset( $plugins[ $plugin_file ] ) ) {
		return '';
	}
	return $plugin_file;
}

/**
 * Collect PHP file paths under a plugin directory (bounded).
 *
 * @param string $plugin_dir Absolute plugin path.
 * @return string[]
 */
function tsosi_profile_collect_php_files( $plugin_dir ) {
	$plugin_dir = wp_normalize_path( (string) $plugin_dir );
	if ( '' === $plugin_dir || ! is_dir( $plugin_dir ) ) {
		return array();
	}

	$files   = array();
	$skipped = array( 'vendor', 'node_modules', 'tests', 'test', 'languages' );
	$iter    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
	);

	// Listing paths (without reading contents) is cheap even for large plugins, so we
	// gather every candidate first and only cap after sorting. RecursiveDirectoryIterator
	// order is filesystem-dependent; capping while iterating made the same plugin profile
	// differently across runs/servers and could silently drop the file that matters (e.g.
	// the main plugin file with add_shortcode()) depending on directory order alone.
	foreach ( $iter as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$path = wp_normalize_path( $file->getPathname() );
		foreach ( $skipped as $part ) {
			if ( false !== strpos( $path, '/' . $part . '/' ) ) {
				continue 2;
			}
		}
		if ( 'php' !== strtolower( $file->getExtension() ) ) {
			continue;
		}
		$files[] = $path;
	}

	return tsosi_profile_sort_and_cap_files( $files, $plugin_dir, TSOSI_PROFILE_MAX_FILES );
}

/**
 * Sort collected plugin files deterministically (shallower paths first, then
 * alphabetically) and cap the list, so profiling the same plugin twice gives
 * the same result and root-level files are preferred over deeply nested ones.
 *
 * @param string[] $files      Absolute file paths.
 * @param string   $plugin_dir Plugin root directory.
 * @param int      $cap        Maximum number of files to keep.
 * @return string[]
 */
function tsosi_profile_sort_and_cap_files( $files, $plugin_dir, $cap ) {
	$plugin_dir = untrailingslashit( wp_normalize_path( (string) $plugin_dir ) );
	usort(
		$files,
		static function ( $a, $b ) use ( $plugin_dir ) {
			$rel_a   = ltrim( substr( $a, strlen( $plugin_dir ) ), '/' );
			$rel_b   = ltrim( substr( $b, strlen( $plugin_dir ) ), '/' );
			$depth_a = substr_count( $rel_a, '/' );
			$depth_b = substr_count( $rel_b, '/' );
			if ( $depth_a !== $depth_b ) {
				return $depth_a <=> $depth_b;
			}
			return strcmp( $rel_a, $rel_b );
		}
	);
	return array_slice( $files, 0, max( 0, (int) $cap ) );
}

/**
 * Collect block.json manifests under a plugin directory.
 *
 * @param string $plugin_dir Absolute plugin path.
 * @return string[]
 */
function tsosi_profile_collect_block_json_files( $plugin_dir ) {
	$plugin_dir = wp_normalize_path( (string) $plugin_dir );
	if ( '' === $plugin_dir || ! is_dir( $plugin_dir ) ) {
		return array();
	}

	$files   = array();
	$skipped = array( 'vendor', 'node_modules', 'tests', 'test' );
	$iter    = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $plugin_dir, FilesystemIterator::SKIP_DOTS )
	);

	// See tsosi_profile_collect_php_files() -- gather all candidates, then sort + cap,
	// so the result is deterministic instead of depending on filesystem iteration order.
	foreach ( $iter as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		if ( 'block.json' !== strtolower( $file->getFilename() ) ) {
			continue;
		}
		$path = wp_normalize_path( $file->getPathname() );
		foreach ( $skipped as $part ) {
			if ( false !== strpos( $path, '/' . $part . '/' ) ) {
				continue 2;
			}
		}
		$files[] = $path;
	}

	return tsosi_profile_sort_and_cap_files( $files, $plugin_dir, TSOSI_PROFILE_MAX_BLOCK_JSON );
}

/**
 * Parse a block name from block.json contents.
 *
 * @param string $path block.json path.
 * @return string
 */
function tsosi_profile_block_name_from_json_path( $path ) {
	$content = tsosi_profile_read_file( $path );
	if ( '' === $content ) {
		return '';
	}
	if ( preg_match( '/"name"\s*:\s*"([a-z0-9\/_-]+)"/i', $content, $match ) ) {
		return sanitize_text_field( (string) $match[1] );
	}
	return '';
}

/**
 * Resolve a block.json path referenced in PHP relative to the source file.
 *
 * @param string $php_file  PHP file path.
 * @param string $json_hint Path fragment from register_block_type().
 * @return string
 */
function tsosi_profile_resolve_block_json_path( $php_file, $json_hint ) {
	$json_hint = wp_normalize_path( (string) $json_hint );
	if ( '' === $json_hint ) {
		return '';
	}
	if ( is_file( $json_hint ) ) {
		return $json_hint;
	}
	$base       = wp_normalize_path( dirname( $php_file ) );
	$candidates = array(
		$base . '/' . ltrim( $json_hint, './' ),
		$base . '/' . $json_hint,
	);
	foreach ( $candidates as $candidate ) {
		if ( is_file( $candidate ) ) {
			return wp_normalize_path( $candidate );
		}
	}
	return '';
}

/**
 * Whether a callable is defined inside a plugin directory.
 *
 * @param callable|mixed $callback   Registered callback.
 * @param string         $plugin_dir Absolute plugin directory path.
 * @return bool
 */
function tsosi_profile_callback_in_dir( $callback, $plugin_dir ) {
	$plugin_dir = wp_normalize_path( trailingslashit( (string) $plugin_dir ) );
	if ( '' === $plugin_dir ) {
		return false;
	}

	try {
		if ( is_string( $callback ) && is_callable( $callback ) ) {
			$ref = new ReflectionFunction( $callback );
			return 0 === strpos( wp_normalize_path( $ref->getFileName() ), $plugin_dir );
		}
		if ( is_array( $callback ) && isset( $callback[0], $callback[1] ) ) {
			if ( is_object( $callback[0] ) ) {
				$ref = new ReflectionClass( $callback[0] );
			} elseif ( is_string( $callback[0] ) && class_exists( $callback[0] ) ) {
				$ref = new ReflectionClass( $callback[0] );
			} else {
				return false;
			}
			return 0 === strpos( wp_normalize_path( $ref->getFileName() ), $plugin_dir );
		}
	} catch ( ReflectionException $e ) { // phpcs:ignore Generic.CodeAnalysis.EmptyStatement.DetectedCatch -- ignore unloadable callbacks.
		return false;
	}

	return false;
}

/**
 * Shortcodes registered at runtime by an active plugin.
 *
 * @param string $plugin_dir Plugin directory path.
 * @return string[]
 */
function tsosi_discover_runtime_shortcodes_for_plugin( $plugin_dir ) {
	global $shortcode_tags;
	if ( ! is_array( $shortcode_tags ) ) {
		return array();
	}

	$tags = array();
	foreach ( $shortcode_tags as $tag => $callback ) {
		if ( tsosi_profile_callback_in_dir( $callback, $plugin_dir ) ) {
			$tags[] = tsosi_sanitize_shortcode_tag( (string) $tag );
		}
	}

	$tags = array_values( array_unique( array_filter( $tags ) ) );
	sort( $tags );
	return $tags;
}

/**
 * Blocks registered at runtime by an active plugin.
 *
 * @param string $plugin_dir Plugin directory path.
 * @return string[]
 */
function tsosi_discover_runtime_blocks_for_plugin( $plugin_dir ) {
	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return array();
	}

	$registry = WP_Block_Type_Registry::get_instance();
	$blocks   = $registry->get_all_registered();
	if ( ! is_array( $blocks ) ) {
		return array();
	}

	$names = array();
	foreach ( $blocks as $block_name => $block_type ) {
		if ( ! is_string( $block_name ) || ! $block_type instanceof WP_Block_Type ) {
			continue;
		}
		if ( tsosi_profile_callback_in_dir( $block_type->render_callback, $plugin_dir ) ) {
			$names[] = sanitize_text_field( $block_name );
			continue;
		}
		if ( is_string( $block_type->editor_script ) && false !== strpos( $block_type->editor_script, tsosi_get_plugin_folder_raw_from_dir( $plugin_dir ) ) ) {
			$names[] = sanitize_text_field( $block_name );
		}
	}

	$names = array_values( array_unique( array_filter( $names ) ) );
	sort( $names );
	return $names;
}

/**
 * @param string $plugin_dir Absolute plugin directory.
 * @return string
 */
function tsosi_get_plugin_folder_raw_from_dir( $plugin_dir ) {
	$plugin_dir = wp_normalize_path( trailingslashit( (string) $plugin_dir ) );
	$plugins    = wp_normalize_path( trailingslashit( WP_PLUGIN_DIR ) );
	if ( 0 === strpos( $plugin_dir, $plugins ) ) {
		return substr( $plugin_dir, strlen( $plugins ) );
	}
	return basename( rtrim( $plugin_dir, '/' ) );
}

/**
 * Read bounded file contents for static analysis.
 *
 * @param string $path File path.
 * @return string
 */
function tsosi_profile_read_file( $path ) {
	if ( ! is_readable( $path ) ) {
		return '';
	}
	$size = filesize( $path );
	if ( false === $size || $size > TSOSI_PROFILE_MAX_FILE_BYTES ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded admin scan.
		$content = (string) file_get_contents( $path, false, null, 0, TSOSI_PROFILE_MAX_FILE_BYTES );
	} else {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- bounded admin scan.
		$content = (string) file_get_contents( $path );
	}

	return tsosi_profile_strip_php_comments( $content );
}

/**
 * Strip PHP comments (// # and slash-star ... star-slash, including docblocks) from source
 * before it is regex-scanned for literal shortcode/block/option/meta keys.
 *
 * Without this, an *example* key written inside a comment or docblock -- including, very
 * concretely, this very file's own docblocks documenting the regexes below (e.g.
 * "e.g. get_option( 'fileorganizer_free_installed' )") -- is indistinguishable from a real
 * call and gets attributed to whichever plugin happens to contain that comment. A plain
 * regex comment-stripper is unsafe (it can't tell "//" or a comment-open sequence inside a
 * string literal from a real comment start), so this uses PHP's own tokenizer, which does.
 *
 * @param string $content Raw file contents (may be plain text/JSON, not just PHP).
 * @return string Content with comment token text blanked out (line breaks preserved).
 */
function tsosi_profile_strip_php_comments( $content ) {
	if ( '' === $content || ! function_exists( 'token_get_all' ) ) {
		return $content;
	}

	$tokens = @token_get_all( $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- fall back to the original string on any tokenizer failure (e.g. truncated/non-PHP content).
	if ( ! is_array( $tokens ) ) {
		return $content;
	}

	$stripped = '';
	foreach ( $tokens as $token ) {
		if ( is_array( $token ) ) {
			list( $id, $text ) = $token;
			if ( T_COMMENT === $id || T_DOC_COMMENT === $id ) {
				// Keep newlines so anything counting/matching across lines is unaffected.
				$stripped .= str_repeat( "\n", substr_count( $text, "\n" ) );
				continue;
			}
			$stripped .= $text;
		} else {
			$stripped .= $token;
		}
	}

	return $stripped;
}

/**
 * Extract shortcode tags registered in plugin PHP sources.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_discover_plugin_shortcodes( $plugin_file ) {
	$dir   = tsosi_get_plugin_dir( $plugin_file );
	$files = tsosi_profile_collect_php_files( $dir );
	$tags  = array();

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		if ( preg_match_all( "/add_shortcode\s*\(\s*['\"]([a-z0-9_-]+)['\"]/i", $content, $matches ) ) {
			foreach ( $matches[1] as $tag ) {
				$tags[] = tsosi_sanitize_shortcode_tag( (string) $tag );
			}
		}
	}

	$tags = array_values( array_unique( array_filter( $tags ) ) );
	sort( $tags );
	return $tags;
}

/**
 * Extract block names declared in plugin sources or block.json files.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_discover_plugin_blocks( $plugin_file ) {
	$dir   = tsosi_get_plugin_dir( $plugin_file );
	$files = tsosi_profile_collect_php_files( $dir );
	$names = array();

	foreach ( tsosi_profile_collect_block_json_files( $dir ) as $json_path ) {
		$name = tsosi_profile_block_name_from_json_path( $json_path );
		if ( '' !== $name ) {
			$names[] = $name;
		}
	}

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		if ( preg_match_all( "/register_block_type\s*\(\s*['\"]([a-z0-9\/_-]+)['\"]/i", $content, $matches ) ) {
			foreach ( $matches[1] as $name ) {
				$names[] = sanitize_text_field( (string) $name );
			}
		}
		if ( preg_match_all( "/register_block_type\s*\(\s*[^;]*['\"]([^'\"]*block\.json)['\"]/i", $content, $json_refs ) ) {
			foreach ( $json_refs[1] as $json_hint ) {
				$json_path = tsosi_profile_resolve_block_json_path( $path, $json_hint );
				$name      = tsosi_profile_block_name_from_json_path( $json_path );
				if ( '' !== $name ) {
					$names[] = $name;
				}
			}
		}
	}

	$names = array_values( array_unique( array_filter( $names ) ) );
	sort( $names );
	return $names;
}

/**
 * wp_options keys that must never become plugin prefix needles (WordPress core).
 *
 * @return string[]
 */
function tsosi_get_core_wp_option_key_blocklist() {
	return array(
		// General.
		'active_plugins',
		'admin_email',
		'new_admin_email',
		'blog_charset',
		'blogdescription',
		'blogname',
		'can_compress_scripts',
		'category_base',
		'comment_registration',
		'comments_notify',
		'cron',
		'date_format',
		'db_version',
		'default_category',
		'default_comment_status',
		'default_ping_status',
		'default_pingback_flag',
		'default_role',
		'gmt_offset',
		'home',
		'html_type',
		'initial_db_version',
		'links_updated_date_format',
		'mailserver_login',
		'mailserver_pass',
		'mailserver_port',
		'mailserver_url',
		'moderation_notify',
		'page_for_posts',
		'page_on_front',
		'permalink_structure',
		'ping_sites',
		'posts_per_page',
		'recently_activated',
		'rewrite_rules',
		'secret',
		'show_on_front',
		'siteurl',
		'start_of_week',
		'stylesheet',
		'stylesheet_root',
		'tag_base',
		'template',
		'template_root',
		'time_format',
		'timezone_string',
		'uninstall_plugins',
		'upload_path',
		'upload_url_path',
		'user_roles',
		'users_can_register',
		'WPLANG',
		'wp_user_roles',
		// Discussion.
		'avatar_default',
		'avatar_rating',
		'close_comments_days_old',
		'close_comments_for_old_posts',
		'comment_max_links',
		'comment_order',
		'comment_whitelist',
		'comments_per_page',
		'default_comments_page',
		'disallowed_keys',
		'moderation_keys',
		'page_comments',
		'require_name_email',
		'show_avatars',
		'thread_comments',
		'thread_comments_depth',
		// Media.
		'image_default_align',
		'image_default_link_type',
		'image_default_size',
		'large_size_h',
		'large_size_w',
		'medium_large_size_h',
		'medium_large_size_w',
		'medium_size_h',
		'medium_size_w',
		'thumbnail_crop',
		'thumbnail_size_h',
		'thumbnail_size_w',
		// Misc core state read-only by convention (never owned by a plugin).
		'blog_public',
		'nav_menu_options',
		'sidebars_widgets',
		'use_balanceTags',
		'use_smilies',
		'use_trackback',
		'widget_categories',
		'wp_page_for_privacy_policy',
	);
}

/**
 * Option key prefixes that belong to a shared third-party vendor/licensing scheme rather
 * than to any single plugin. Some plugin distribution networks (e.g. Softaculous-bundled
 * "nulled"/reseller installers) inject a common licensing option key into many unrelated
 * plugins; a plugin's source code genuinely calling get_option() on one of these keys is
 * not evidence that a *specific other* plugin found on the site is that same plugin.
 *
 * @return string[]
 */
function tsosi_get_shared_vendor_option_key_prefix_blocklist() {
	return array(
		'softaculous_',
	);
}

/**
 * Whether an option key should be ignored when guessing plugin prefixes.
 *
 * @param string $key Option key.
 * @return bool
 */
function tsosi_is_blocked_option_key_for_prefix_discovery( $key ) {
	$key = sanitize_key( (string) $key );
	if ( '' === $key ) {
		return true;
	}
	if ( in_array( $key, tsosi_get_core_wp_option_key_blocklist(), true ) ) {
		return true;
	}
	foreach ( tsosi_get_shared_vendor_option_key_prefix_blocklist() as $vendor_prefix ) {
		if ( 0 === strpos( $key, $vendor_prefix ) ) {
			return true;
		}
	}
	if ( tsosi_is_sensitive_storage_option_name( $key ) ) {
		return true;
	}
	if ( 0 === strpos( $key, '_transient_' ) || 0 === strpos( $key, '_site_transient_' ) ) {
		return true;
	}
	return false;
}

/**
 * Whether an option name must never be read or indexed by Stack Inspector (secrets / API keys).
 *
 * @param string $name Option name.
 * @return bool
 */
function tsosi_is_sensitive_storage_option_name( $name ) {
	$name = strtolower( trim( (string) $name ) );
	if ( '' === $name ) {
		return true;
	}

	// WordPress AI Client / Abilities API connectors (e.g. connectors_ai_*_api_key).
	if ( preg_match( '/^connectors_ai_.+_api_key$/', $name ) ) {
		return true;
	}
	if ( 0 === strpos( $name, 'connectors_ai_' ) && false !== strpos( $name, 'api_key' ) ) {
		return true;
	}

	$fragments = array(
		'api_key',
		'apikey',
		'api-secret',
		'apisecret',
		'client_secret',
		'clientsecret',
		'private_key',
		'privatekey',
		'secret_key',
		'secretkey',
		'auth_token',
		'access_token',
		'refresh_token',
		'bearer_token',
		'password',
		'passwd',
		'mailserver_pass',
		'smtp_pass',
		'smtp_password',
	);

	foreach ( $fragments as $fragment ) {
		if ( false !== strpos( $name, $fragment ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Derive meta/option prefix candidates from a storage key.
 *
 * The single-segment fallback (the bare first "word" of the key, e.g. "widget" out of
 * "widget_text") is only safe when the key itself is the plugin's own folder slug --
 * there it's inherently plugin-specific. When the key instead comes from scanning the
 * plugin's source for get_option()/get_post_meta() calls, that first segment is often a
 * generic English/WordPress word ("widget", "theme", "search", "license"...) that many
 * unrelated plugins and WordPress core itself also use, so a bare match against it causes
 * mass false positives (e.g. every "widget_*" core option "matching" a plugin that merely
 * happens to read one such option). Callers pass $allow_bare_segment = false for anything
 * discovered from source scanning, and leave it true only for the plugin's own folder slug.
 *
 * @param string $key                  Storage key.
 * @param bool   $allow_bare_segment   Whether the bare first segment alone may become a
 *                                     prefix candidate. Default true.
 * @return string[]
 */
function tsosi_profile_prefixes_from_storage_key( $key, $allow_bare_segment = true ) {
	$key = sanitize_key( (string) $key );
	if ( '' === $key ) {
		return array();
	}
	if ( 0 === strpos( $key, '_' ) ) {
		$key = substr( $key, 1 );
	}
	if ( strlen( $key ) < 3 ) {
		return array();
	}

	$parts    = preg_split( '/[_-]/', $key );
	$prefixes = array();
	if ( $allow_bare_segment && ! empty( $parts[0] ) && strlen( $parts[0] ) >= 5 ) {
		$prefixes[] = sanitize_key( $parts[0] );
	}
	if ( ! empty( $parts[1] ) && strlen( $parts[0] ) >= 2 && strlen( $parts[1] ) >= 2 ) {
		$prefixes[] = sanitize_key( $parts[0] . '_' . $parts[1] );
	}
	if ( ! empty( $parts[2] ) && strlen( $parts[0] ) >= 2 && strlen( $parts[1] ) >= 2 && strlen( $parts[2] ) >= 2 ) {
		$prefixes[] = sanitize_key( $parts[0] . '_' . $parts[1] . '_' . $parts[2] );
	}

	return array_values( array_unique( array_filter( $prefixes ) ) );
}

/**
 * Literal heads of a dynamic (concatenated) storage key that must never be trusted as a
 * plugin-owned prefix on their own, because WordPress core or many unrelated plugins
 * routinely build a dynamic key from this same root to reference something that isn't
 * specific to the plugin doing the reading (e.g. "theme_mods_" . $stylesheet is used by
 * any code reading ANY theme's mods, not just code that owns that data -- this is exactly
 * the pattern that previously caused Stack Inspector itself to false-match an unrelated
 * theme's theme_mods).
 *
 * @return string[]
 */
function tsosi_get_dynamic_prefix_root_blocklist() {
	return array(
		'theme_mods',
		'widget',
		'sidebars_widgets',
		'nav_menu',
		'cron',
		'transient',
		'site_transient',
	);
}

/**
 * Whether a literal head extracted from a concatenated storage key ("head" . $var) is a
 * generic root that must not become a standalone plugin prefix by itself.
 *
 * @param string $head Sanitized head, trailing separator already stripped.
 * @return bool
 */
function tsosi_is_dynamic_prefix_root_blocked( $head ) {
	$head = sanitize_key( (string) $head );
	if ( '' === $head ) {
		return true;
	}
	foreach ( tsosi_get_dynamic_prefix_root_blocklist() as $blocked_root ) {
		if ( $head === $blocked_root || 0 === strpos( $head, $blocked_root . '_' ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Discover literal transient / site-transient keys used by a plugin and translate them
 * into the wp_options row-name prefixes WordPress actually stores them under
 * ("_transient_{key}" / "_site_transient_{key}"). A plugin can carry an entire mechanism
 * (e.g. a fake "license"/"update available" nag) purely through transients, with no
 * get_option()/update_option() call anywhere in its source -- without this, such a
 * plugin would have no discoverable option-side footprint at all.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[] Fully-formed wp_options prefixes.
 */
function tsosi_discover_plugin_transient_prefixes( $plugin_file ) {
	$dir   = tsosi_get_plugin_dir( $plugin_file );
	$files = tsosi_profile_collect_php_files( $dir );

	$entries = array();
	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		// Complete literal transient key only -- same reasoning as the option/meta
		// regexes above: a dynamically built key isn't one this plugin can be said to own.
		if ( preg_match_all( "/(?:get|set|delete)_(site_)?transient\s*\(\s*(['\"])([_a-z0-9-]+)\\2\s*[,)]/i", $content, $matches ) ) {
			foreach ( $matches[3] as $index => $key ) {
				$entries[] = array(
					'key'  => sanitize_key( (string) $key ),
					'site' => isset( $matches[1][ $index ] ) && '' !== $matches[1][ $index ],
				);
			}
		}
	}

	$prefixes = array();
	foreach ( $entries as $entry ) {
		$key = $entry['key'];
		if ( '' === $key || tsosi_is_blocked_option_key_for_prefix_discovery( $key ) ) {
			continue;
		}
		$prefixes[] = ( $entry['site'] ? '_site_transient_' : '_transient_' ) . $key;
	}

	return array_values( array_unique( array_filter( $prefixes ) ) );
}

/**
 * Whether a storage key is runtime analytics (view counts), not plugin configuration.
 *
 * @param string $key Meta or option key.
 * @return bool
 */
function tsosi_is_runtime_analytics_storage_key( $key ) {
	$key = sanitize_key( ltrim( (string) $key, '_' ) );
	if ( '' === $key ) {
		return false;
	}
	return (bool) preg_match( '/(?:^|_)(view_count|page_views|post_views|views|hit_count)$/', $key );
}

/**
 * Literal postmeta keys established as owned by a write-type call (add/update/delete_post_meta
 * or register_post_meta()/register_meta()) somewhere in the plugin's own source.
 *
 * A bare get_post_meta() call on a literal key is not evidence of ownership: plugins
 * routinely read ANOTHER plugin's or service's meta to interoperate with it (e.g. reading
 * Yoast SEO's own "_yoast_wpseo_focuskw" post meta to feed an auto-rename/SEO feature),
 * and that read-only key belongs to the other plugin, not to the one being scanned.
 *
 * @param string[] $files Plugin PHP file paths.
 * @return array<string,bool> Sanitized keys, as a set (map to true).
 */
function tsosi_discover_plugin_meta_write_keys( $files ) {
	$write_keys = array();

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		if ( preg_match_all( "/(?:update|delete|add)_post_meta\s*\([^,]+,\s*(['\"])([_a-z0-9-]+)\\1\s*[,)]/i", $content, $matches ) ) {
			foreach ( $matches[2] as $key ) {
				$write_keys[ sanitize_key( (string) $key ) ] = true;
			}
		}
		if ( preg_match_all( "/(?:update|delete|add)_post_meta\s*\([^,]+,\s*(['\"])([_a-z0-9-]+)\\1\s*\./i", $content, $head_matches ) ) {
			foreach ( $head_matches[2] as $head ) {
				$write_keys[ sanitize_key( rtrim( (string) $head, '_-' ) ) ] = true;
			}
		}
		if ( preg_match_all( "/register_(?:post_)?meta\s*\(\s*['\"][^'\"]*['\"]\s*,\s*(['\"])([_a-z0-9-]+)\\1/i", $content, $register_matches ) ) {
			foreach ( $register_matches[2] as $key ) {
				$write_keys[ sanitize_key( (string) $key ) ] = true;
			}
		}
	}

	return $write_keys;
}

/**
 * Guess postmeta / option prefixes referenced by a plugin.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_discover_plugin_meta_prefixes( $plugin_file ) {
	$dir   = tsosi_get_plugin_dir( $plugin_file );
	$files = tsosi_profile_collect_php_files( $dir );
	$keys  = array();

	$write_keys = tsosi_discover_plugin_meta_write_keys( $files );
	$keys       = array_merge( $keys, array_keys( $write_keys ) );

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		// Require a complete literal key -- immediately closed by the same quote and
		// followed by "," or ")" -- e.g. get_post_meta( $id, 'myplugin_option', true ).
		// A get_post_meta() match only counts when the same key is also established by a
		// write call / register_meta() above -- see tsosi_discover_plugin_meta_write_keys().
		if ( preg_match_all( "/(get|update|delete|add)_post_meta\s*\([^,]+,\s*(['\"])([_a-z0-9-]+)\\2\s*[,)]/i", $content, $matches ) ) {
			foreach ( $matches[3] as $index => $key ) {
				$key = sanitize_key( (string) $key );
				if ( 'get' === strtolower( $matches[1][ $index ] ) && ! isset( $write_keys[ $key ] ) ) {
					continue;
				}
				$keys[] = $key;
			}
		}
		// Literal *head* of a concatenated key -- e.g.
		// update_post_meta( $id, 'myplugin_field_' . $suffix, $val ). The suffix is
		// dynamic, but the literal head is still a real, plugin-authored fragment, so it
		// is kept as an already-compound prefix candidate -- guarded by
		// tsosi_is_dynamic_prefix_root_blocked() against generic roots (e.g. a plugin
		// that merely reads "theme_mods_" . $stylesheet for some other theme), and, for
		// get_post_meta(), by the same write-evidence requirement as above.
		if ( preg_match_all( "/(get|update|delete|add)_post_meta\s*\([^,]+,\s*(['\"])([_a-z0-9-]+)\\2\s*\./i", $content, $head_matches ) ) {
			foreach ( $head_matches[3] as $index => $head ) {
				$head = sanitize_key( rtrim( (string) $head, '_-' ) );
				if ( 'get' === strtolower( $head_matches[1][ $index ] ) && ! isset( $write_keys[ $head ] ) ) {
					continue;
				}
				if ( strlen( $head ) >= 6 && false !== strpos( $head, '_' )
					&& ! tsosi_is_dynamic_prefix_root_blocked( $head )
				) {
					$keys[] = $head;
				}
			}
		}
	}

	$prefixes = array();
	foreach ( $keys as $key ) {
		if ( tsosi_is_runtime_analytics_storage_key( $key ) ) {
			continue;
		}
		// $allow_bare_segment = false: a bare first word from scanned source ("view" out
		// of "view_count") is too generic to trust as a standalone prefix -- see the
		// tsosi_profile_prefixes_from_storage_key() docblock.
		$prefixes = array_merge( $prefixes, tsosi_profile_prefixes_from_storage_key( $key, false ) );
	}

	$prefixes = array_values( array_unique( array_filter( $prefixes ) ) );
	sort( $prefixes );
	return $prefixes;
}

/**
 * Literal wp_options keys established as owned by a write-type call (add/update/delete_option
 * or register_setting()) somewhere in the plugin's own source.
 *
 * A bare get_option() call on a literal key is not evidence of ownership: plugins routinely
 * read ANOTHER plugin's option purely to detect or interoperate with it (e.g. reading
 * "jetpack_active_modules" to decide whether to defer to Jetpack's own sitemap, or reading
 * "meowapps_hide_meowapps" to detect that plugin), and that read-only key belongs to the
 * other plugin, not to the one being scanned. register_setting() is included because the
 * Settings API persists the option via options.php without a literal update_option() call
 * anywhere in the plugin's own code.
 *
 * @param string[] $files Plugin PHP file paths.
 * @return array<string,bool> Sanitized keys, as a set (map to true).
 */
function tsosi_discover_plugin_option_write_keys( $files ) {
	$write_keys = array();

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		if ( preg_match_all( "/(?:update|delete|add)_option\s*\(\s*(['\"])([_a-z0-9-]+)\\1\s*[,)]/i", $content, $matches ) ) {
			foreach ( $matches[2] as $key ) {
				$write_keys[ sanitize_key( (string) $key ) ] = true;
			}
		}
		if ( preg_match_all( "/(?:update|delete|add)_option\s*\(\s*(['\"])([_a-z0-9-]+)\\1\s*\./i", $content, $head_matches ) ) {
			foreach ( $head_matches[2] as $head ) {
				$write_keys[ sanitize_key( rtrim( (string) $head, '_-' ) ) ] = true;
			}
		}
		if ( preg_match_all( "/register_setting\s*\(\s*['\"][^'\"]*['\"]\s*,\s*(['\"])([_a-z0-9-]+)\\1/i", $content, $register_matches ) ) {
			foreach ( $register_matches[2] as $key ) {
				$write_keys[ sanitize_key( (string) $key ) ] = true;
			}
		}
	}

	return $write_keys;
}

/**
 * Guess wp_options key prefixes referenced by a plugin.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[]
 */
function tsosi_discover_plugin_option_prefixes( $plugin_file ) {
	$dir   = tsosi_get_plugin_dir( $plugin_file );
	$files = tsosi_profile_collect_php_files( $dir );
	$keys  = array();

	$write_keys = tsosi_discover_plugin_option_write_keys( $files );
	$keys       = array_merge( $keys, array_keys( $write_keys ) );

	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		// Require a complete literal key -- immediately closed by the same quote and
		// followed by "," or ")" -- e.g. get_option( 'fileorganizer_free_installed' ).
		// A get_option() match only counts when the same key is also established by a
		// write call / register_setting() above -- see
		// tsosi_discover_plugin_option_write_keys() -- otherwise a plugin that merely
		// reads another plugin's option (e.g. to detect it) would be credited with data
		// it does not own.
		if ( preg_match_all( "/(get|update|delete|add)_option\s*\(\s*(['\"])([_a-z0-9-]+)\\2\s*[,)]/i", $content, $matches ) ) {
			foreach ( $matches[3] as $index => $key ) {
				$key = sanitize_key( (string) $key );
				if ( 'get' === strtolower( $matches[1][ $index ] ) && ! isset( $write_keys[ $key ] ) ) {
					continue;
				}
				$keys[] = $key;
			}
		}
		// Literal *head* of a concatenated key -- e.g.
		// update_option( 'fileorganizer_version_' . ( $pro ? 'pro' : 'free' ) . '_nag', $v ).
		// The suffix is dynamic, but the literal head is still a real, plugin-authored
		// fragment, so it is kept as an already-compound prefix candidate. This must NOT
		// swallow "'theme_mods_' . $stylesheet" (a concatenation this plugin merely
		// builds to READ another theme's data, not a fixed key it OWNS) -- guarded by
		// tsosi_is_dynamic_prefix_root_blocked() against exactly that kind of generic,
		// core-owned dynamic root -- and, for get_option(), by the same write-evidence
		// requirement as above.
		if ( preg_match_all( "/(get|update|delete|add)_option\s*\(\s*(['\"])([_a-z0-9-]+)\\2\s*\./i", $content, $head_matches ) ) {
			foreach ( $head_matches[3] as $index => $head ) {
				$head = sanitize_key( rtrim( (string) $head, '_-' ) );
				if ( 'get' === strtolower( $head_matches[1][ $index ] ) && ! isset( $write_keys[ $head ] ) ) {
					continue;
				}
				if ( strlen( $head ) >= 6 && false !== strpos( $head, '_' )
					&& ! tsosi_is_dynamic_prefix_root_blocked( $head )
				) {
					$keys[] = $head;
				}
			}
		}
	}

	// Transient / site-transient literal keys are stored in wp_options under a
	// "_transient_" / "_site_transient_" prefix, so they need that prefix baked in to
	// ever match a real row -- see tsosi_discover_plugin_transient_prefixes().
	$transient_prefixes = tsosi_discover_plugin_transient_prefixes( $plugin_file );

	$source_key_count = count( $keys );

	$folder = tsosi_get_plugin_folder_raw( $plugin_file );
	if ( '' !== $folder ) {
		$keys[] = sanitize_key( str_replace( '-', '_', $folder ) );
	}

	$prefixes = array();
	foreach ( $keys as $index => $key ) {
		if ( tsosi_is_blocked_option_key_for_prefix_discovery( $key ) ) {
			continue;
		}
		// The plugin's own folder slug (appended after $source_key_count) is always
		// plugin-specific, so its bare first segment is safe to trust; a bare first
		// segment merely read from a get_option()/update_option() call elsewhere in the
		// source is not -- see the tsosi_profile_prefixes_from_storage_key() docblock.
		$allow_bare_segment = $index >= $source_key_count;
		$prefixes           = array_merge( $prefixes, tsosi_profile_prefixes_from_storage_key( $key, $allow_bare_segment ) );
	}

	$prefixes = array_merge( $prefixes, $transient_prefixes );

	$prefixes = array_values( array_unique( array_filter( $prefixes ) ) );
	sort( $prefixes );
	return $prefixes;
}

/**
 * Discover WP_Widget id_base(s) registered by this plugin: find register_widget() calls,
 * then read the referenced class's own parent::__construct( 'id_base', ... ) call.
 *
 * A native widget's storage key is 'widget_{id_base}' and its sidebar instance ids are
 * '{id_base}-N' -- neither has to share a single word with the plugin's folder slug or
 * any of its get_option()/get_post_meta() calls (a plugin can register_widget() without
 * ever calling get_option() itself). Without this, such a plugin has no discoverable
 * option/meta prefix at all and a scan can never find it in an active sidebar, even
 * though it plainly is one.
 *
 * @param string $plugin_file Plugin basename.
 * @return string[] Sanitized id_base values.
 */
function tsosi_discover_plugin_widget_id_bases( $plugin_file ) {
	$dir   = tsosi_get_plugin_dir( $plugin_file );
	$files = tsosi_profile_collect_php_files( $dir );

	$class_names = array();
	$sources     = array();
	foreach ( $files as $path ) {
		$content = tsosi_profile_read_file( $path );
		if ( '' === $content ) {
			continue;
		}
		$sources[] = $content;
		if ( preg_match_all( "/register_widget\s*\(\s*(?:new\s+)?['\"]?([A-Za-z_][A-Za-z0-9_]*)/", $content, $matches ) ) {
			foreach ( $matches[1] as $class_name ) {
				$class_names[] = $class_name;
			}
		}
	}
	$class_names = array_values( array_unique( array_filter( $class_names ) ) );
	if ( empty( $class_names ) ) {
		return array();
	}

	$id_bases = array();
	foreach ( $sources as $content ) {
		foreach ( $class_names as $class_name ) {
			// Bounded window after the class declaration so an unrelated class defined
			// later in the same file can't be mistaken for this one's constructor.
			if ( preg_match(
				'/class\s+' . preg_quote( $class_name, '/' ) . '\b.{0,8000}?parent::__construct\s*\(\s*[\'"]([a-z0-9_-]+)[\'"]/is',
				$content,
				$class_match
			) ) {
				$id_bases[] = sanitize_key( $class_match[1] );
			}
		}
	}

	return array_values( array_unique( array_filter( $id_bases ) ) );
}

/**
 * Build a profile for one plugin.
 *
 * @param string $plugin_file Plugin basename.
 * @return array<string,mixed>
 */
function tsosi_build_plugin_profile( $plugin_file ) {
	$plugin_file = tsosi_sanitize_plugin_file( $plugin_file );
	if ( '' === $plugin_file ) {
		return array(
			'plugin_file'     => '',
			'name'            => '',
			'active'          => false,
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array(),
		);
	}
	$plugins    = tsosi_get_installed_plugins();
	$header     = isset( $plugins[ $plugin_file ] ) ? $plugins[ $plugin_file ] : array();
	$name       = isset( $header['Name'] ) ? (string) $header['Name'] : $plugin_file;
	$plugin_dir = tsosi_get_plugin_dir( $plugin_file );

	$shortcodes = tsosi_discover_plugin_shortcodes( $plugin_file );
	$blocks     = tsosi_discover_plugin_blocks( $plugin_file );
	$prefixes   = tsosi_discover_plugin_meta_prefixes( $plugin_file );
	$opt_prefix = tsosi_discover_plugin_option_prefixes( $plugin_file );

	// A widget's own id_base is exact and plugin-specific (pulled straight from its own
	// constructor call), so it's always safe to add directly -- no genericity concern
	// like the bare-segment fallback above.
	$opt_prefix = array_merge( $opt_prefix, tsosi_discover_plugin_widget_id_bases( $plugin_file ) );

	if ( tsosi_is_plugin_active_file( $plugin_file ) ) {
		$shortcodes = array_values( array_unique( array_merge( $shortcodes, tsosi_discover_runtime_shortcodes_for_plugin( $plugin_dir ) ) ) );
		$blocks     = array_values( array_unique( array_merge( $blocks, tsosi_discover_runtime_blocks_for_plugin( $plugin_dir ) ) ) );
		sort( $shortcodes );
		sort( $blocks );
	}

	return array(
		'plugin_file'     => $plugin_file,
		'name'            => $name,
		'active'          => tsosi_is_plugin_active_file( $plugin_file ),
		'shortcodes'      => $shortcodes,
		'blocks'          => $blocks,
		'meta_prefixes'   => tsosi_refine_prefix_list( $prefixes ),
		'option_prefixes' => tsosi_refine_prefix_list( $opt_prefix ),
	);
}

/**
 * Cached map of all plugin profiles.
 *
 * @param bool $force_refresh Skip cache.
 * @return array<string,array<string,mixed>>
 */
function tsosi_get_all_plugin_profiles( $force_refresh = false ) {
	if ( ! $force_refresh ) {
		$cached = get_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$profiles = array();
	foreach ( array_keys( tsosi_get_installed_plugins() ) as $plugin_file ) {
		$profiles[ $plugin_file ] = tsosi_build_plugin_profile( $plugin_file );
	}

	set_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE, $profiles, DAY_IN_SECONDS );
	return $profiles;
}

/**
 * Flush cached plugin profiles when plugins change.
 *
 * @return void
 */
function tsosi_flush_plugin_profile_cache() {
	delete_transient( TSOSI_TRANSIENT_PLUGIN_PROFILE );
}
add_action( 'activated_plugin', 'tsosi_flush_plugin_profile_cache' );
add_action( 'deactivated_plugin', 'tsosi_flush_plugin_profile_cache' );
add_action( 'deleted_plugin', 'tsosi_flush_plugin_profile_cache' );
add_action( 'upgrader_process_complete', 'tsosi_flush_plugin_profile_cache' );

/**
 * Admin URL for TSO Options & Tables Cleaner when installed.
 *
 * @return string
 */
function tsosi_get_options_cleaner_url() {
	if ( ! function_exists( 'is_plugin_active' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	if ( ! is_plugin_active( 'tso-options-tables-cleaner/tso-options-tables-cleaner.php' ) ) {
		return '';
	}
	return admin_url( 'tools.php?page=tso-options-tables-cleaner' );
}
