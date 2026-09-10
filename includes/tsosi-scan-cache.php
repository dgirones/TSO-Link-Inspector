<?php
/**
 * Site content index cache for fast repeat scans (needle-agnostic layer).
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOSI_TRANSIENT_CONTENT_CACHE_PREFIX' ) ) {
	define( 'TSOSI_TRANSIENT_CONTENT_CACHE_PREFIX', 'tso_stack_inspector_content_cache_' );
}

if ( ! defined( 'TSOSI_CONTENT_CACHE_TTL' ) ) {
	define( 'TSOSI_CONTENT_CACHE_TTL', DAY_IN_SECONDS );
}

/**
 * Per-user transient key for the content index cache.
 *
 * @return string
 */
function tsosi_content_cache_transient_key() {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return TSOSI_TRANSIENT_CONTENT_CACHE_PREFIX . '0';
	}
	return TSOSI_TRANSIENT_CONTENT_CACHE_PREFIX . $user_id;
}

/**
 * Delete cached site index for the current user (files + transient).
 *
 * @return void
 */
function tsosi_flush_content_cache() {
	delete_transient( tsosi_content_cache_transient_key() );
	tsosi_scan_delete_build_index();
	tsosi_scan_delete_user_cache_files();
}

/**
 * Delete content-index caches for every user (transients + uploads JSON).
 *
 * @return void
 */
function tsosi_flush_all_content_caches() {
	global $wpdb;

	$like         = '_transient_' . $wpdb->esc_like( TSOSI_TRANSIENT_CONTENT_CACHE_PREFIX ) . '%';
	$like_timeout = '_transient_timeout_' . $wpdb->esc_like( TSOSI_TRANSIENT_CONTENT_CACHE_PREFIX ) . '%';
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- admin flush of all content-cache transients.
	$wpdb->query(
		$wpdb->prepare(
			"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
			$like,
			$like_timeout
		)
	);

	$dir = tsosi_scan_cache_storage_dir();
	if ( '' === $dir ) {
		return;
	}
	$files = glob( $dir . '/cache-*.json' );
	if ( ! is_array( $files ) ) {
		return;
	}
	foreach ( $files as $file ) {
		if ( is_string( $file ) && is_file( $file ) ) {
			wp_delete_file( $file );
		}
	}
}

/**
 * Uploads path for cache JSON files (empty when unavailable).
 *
 * @return string
 */
function tsosi_scan_cache_storage_dir() {
	$dir = tsosi_get_uploads_dir();
	if ( ! empty( $dir['error'] ) || '' === $dir['path'] ) {
		return '';
	}
	return untrailingslashit( $dir['path'] );
}

/**
 * @return bool
 */
function tsosi_scan_content_cache_storage_ready() {
	return '' !== tsosi_scan_cache_storage_dir();
}

/**
 * @return string
 */
function tsosi_scan_cache_user_suffix() {
	return 'u' . max( 0, (int) get_current_user_id() );
}

/**
 * @param string $fingerprint Content fingerprint.
 * @return string Basename only.
 */
function tsosi_scan_cache_index_filename( $fingerprint ) {
	$fp = preg_replace( '/[^a-f0-9]/', '', strtolower( (string) $fingerprint ) );
	return 'cache-index-' . tsosi_scan_cache_user_suffix() . '-' . $fp . '.json';
}

/**
 * @return string Basename only.
 */
function tsosi_scan_cache_build_filename() {
	return 'cache-build-' . tsosi_scan_cache_user_suffix() . '.json';
}

/**
 * @param string $basename File basename inside plugin uploads dir.
 * @return string Absolute path or empty.
 */
function tsosi_scan_cache_file_path( $basename ) {
	$dir = tsosi_scan_cache_storage_dir();
	if ( '' === $dir ) {
		return '';
	}
	$safe = basename( (string) $basename );
	if ( '' === $safe || ! preg_match( '/^cache-(index|build)-u\d+|^history-u\d+-|^replace-backup-u\d+-/', $safe ) ) {
		return '';
	}
	return $dir . '/' . $safe;
}

/**
 * @param string $path Absolute JSON file path.
 * @return array<string,mixed>|null
 */
function tsosi_scan_cache_read_json_file( $path ) {
	if ( '' === $path || ! is_readable( $path ) ) {
		return null;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- non-executable JSON cache in plugin uploads.
	$raw = file_get_contents( $path );
	if ( false === $raw || '' === $raw ) {
		return null;
	}
	$data = json_decode( $raw, true );
	return is_array( $data ) ? $data : null;
}

/**
 * @param string              $path Absolute JSON file path.
 * @param array<string,mixed> $data Data to encode.
 * @return bool
 */
function tsosi_scan_cache_write_json_file( $path, $data ) {
	if ( '' === $path || ! is_array( $data ) ) {
		return false;
	}
	$json = wp_json_encode( $data );
	if ( ! is_string( $json ) ) {
		return false;
	}
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- non-executable JSON cache in plugin uploads.
	return false !== file_put_contents( $path, $json, LOCK_EX );
}

/**
 * Acquire an exclusive, per-user lock guarding scan-job index reads/writes.
 *
 * Prevents a lost-update race when two concurrent requests for the same
 * user (e.g. two open tabs, or a retried request) both read the build
 * index, append to it, and write it back -- the last write would otherwise
 * silently discard the other request's appended posts.
 *
 * @return resource|false Lock handle for tsosi_scan_job_unlock(), or false if unavailable.
 */
function tsosi_scan_job_lock() {
	$dir = tsosi_scan_cache_storage_dir();
	if ( '' === $dir ) {
		return false;
	}
	$path = $dir . '/job-lock-' . tsosi_scan_cache_user_suffix() . '.lock';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- short-lived lock file guarding per-user scan-job index writes.
	$handle = fopen( $path, 'c' );
	if ( false === $handle ) {
		return false;
	}
	if ( ! flock( $handle, LOCK_EX ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with fopen() above.
		fclose( $handle );
		return false;
	}
	return $handle;
}

/**
 * Release a lock acquired with tsosi_scan_job_lock().
 *
 * @param resource|false $handle Lock handle.
 * @return void
 */
function tsosi_scan_job_unlock( $handle ) {
	if ( is_resource( $handle ) ) {
		flock( $handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with fopen() in tsosi_scan_job_lock().
		fclose( $handle );
	}
}

/**
 * Remove finished index + in-progress build files for the current user.
 *
 * @return void
 */
function tsosi_scan_delete_user_cache_files() {
	$dir = tsosi_scan_cache_storage_dir();
	if ( '' === $dir ) {
		return;
	}
	$suffix = tsosi_scan_cache_user_suffix();
	$files  = glob( $dir . '/cache-index-' . $suffix . '-*.json' );
	if ( is_array( $files ) ) {
		foreach ( $files as $file ) {
			if ( is_string( $file ) && is_file( $file ) ) {
				wp_delete_file( $file );
			}
		}
	}
	$build = tsosi_scan_cache_file_path( tsosi_scan_cache_build_filename() );
	if ( '' !== $build && is_file( $build ) ) {
		wp_delete_file( $build );
	}
	$lock_file = $dir . '/job-lock-' . $suffix . '.lock';
	if ( is_file( $lock_file ) ) {
		wp_delete_file( $lock_file );
	}
}

/**
 * @return array{posts:array<int,array<string,mixed>>,extras:array<int,array<string,mixed>>}
 */
function tsosi_scan_load_build_index() {
	$empty = array(
		'posts'  => array(),
		'extras' => array(),
	);
	if ( ! tsosi_scan_content_cache_storage_ready() ) {
		return $empty;
	}
	$path = tsosi_scan_cache_file_path( tsosi_scan_cache_build_filename() );
	$data = tsosi_scan_cache_read_json_file( $path );
	if ( ! is_array( $data ) ) {
		return $empty;
	}
	return array(
		'posts'  => isset( $data['posts'] ) && is_array( $data['posts'] ) ? $data['posts'] : array(),
		'extras' => isset( $data['extras'] ) && is_array( $data['extras'] ) ? $data['extras'] : array(),
	);
}

/**
 * @param array{posts:array<int,array<string,mixed>>,extras:array<int,array<string,mixed>>} $index Index payload.
 * @return bool
 */
function tsosi_scan_save_build_index( $index ) {
	if ( ! tsosi_scan_content_cache_storage_ready() || ! is_array( $index ) ) {
		return false;
	}
	$path = tsosi_scan_cache_file_path( tsosi_scan_cache_build_filename() );
	return tsosi_scan_cache_write_json_file( $path, $index );
}

/**
 * @return void
 */
function tsosi_scan_delete_build_index() {
	$path = tsosi_scan_cache_file_path( tsosi_scan_cache_build_filename() );
	if ( '' !== $path && is_file( $path ) ) {
		wp_delete_file( $path );
	}
}

/**
 * @return void
 */
function tsosi_content_cache_register_hooks() {
	add_action( 'activated_plugin', 'tsosi_flush_content_cache' );
	add_action( 'deactivated_plugin', 'tsosi_flush_content_cache' );
}
add_action( 'init', 'tsosi_content_cache_register_hooks' );

/**
 * Cache status for admin UI.
 *
 * @return array{ready:bool,built_at:int,age_text:string,fingerprint?:string}
 */
function tsosi_scan_get_cache_status() {
	$settings = tsosi_get_scan_settings();
	$post_ids = tsosi_scan_collect_post_ids( $settings );
	$extras   = tsosi_scan_collect_extra_targets( $settings );
	$fp       = tsosi_scan_content_fingerprint( $settings, $post_ids, $extras );
	$cache    = tsosi_scan_get_content_cache( $fp );

	if ( ! is_array( $cache ) || empty( $cache['index'] ) ) {
		return array(
			'ready'    => false,
			'built_at' => 0,
			'age_text' => '',
		);
	}

	$meta     = get_transient( tsosi_content_cache_transient_key() );
	$built_at = is_array( $meta ) && isset( $meta['built_at'] ) ? absint( $meta['built_at'] ) : 0;

	return array(
		'ready'       => true,
		'built_at'    => $built_at,
		'fingerprint' => $fp,
		'age_text'    => $built_at > 0 ? human_time_diff( $built_at, time() ) : '',
	);
}

/**
 * @return void
 */
function tsosi_scan_rebuild_content_cache() {
	tsosi_flush_all_content_caches();
}

/**
 * Latest modified timestamp for scannable posts (cache invalidation signal).
 *
 * @return string
 */
function tsosi_scan_get_max_post_modified_gmt() {
	// Memoize per request: this is called once from tsosi_admin_get_js_config() and once
	// from tsosi_admin_render_cache_bar() on the same admin page load (and again from any
	// scan/cache-status check in between), and content can't change mid-request, so the
	// second+ call would otherwise repeat the same query for nothing.
	static $cached = null;
	if ( null !== $cached ) {
		return $cached;
	}

	global $wpdb;

	$types = array();
	foreach ( tsosi_get_scannable_post_types() as $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		if ( '' !== $post_type ) {
			$types[] = $post_type;
		}
	}
	$types = array_values( array_unique( $types ) );

	if ( empty( $types ) ) {
		$cached = '';
		return $cached;
	}

	// One combined query instead of one MAX() query per post type -- same result
	// (still the overall max across all scannable types), far fewer round-trips.
	$placeholders = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- cache-invalidation fingerprint, memoized above.
	$max = $wpdb->get_var(
		$wpdb->prepare(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- $placeholders is a fixed "%s, %s, ..." string sized from count( $types ) above, never from request input; the %s tokens it contains are filled by the $types argument below (verified: $types comes from tsosi_get_scannable_post_types() + sanitize_key(), not $_GET/$_POST). Known WPCS limitation with dynamic IN() placeholder counts -- see WordPress-Coding-Standards issues #1697/#1859.
			"SELECT MAX(post_modified_gmt) FROM {$wpdb->posts} WHERE post_type IN ({$placeholders})",
			$types
		)
	);

	$cached = is_string( $max ) ? $max : '';
	return $cached;
}

/**
 * Fingerprint for scan settings + site content scope.
 *
 * @param array<string,mixed>            $settings Scan settings.
 * @param int[]                          $post_ids Post IDs in scope.
 * @param array<int,array<string,mixed>> $extras Extra targets.
 * @return string
 */
function tsosi_scan_content_fingerprint( $settings, $post_ids, $extras ) {
	$payload = array(
		'v'            => 1,
		'blog_id'      => get_current_blog_id(),
		'settings'     => $settings,
		'post_count'   => count( $post_ids ),
		'extra_count'  => count( $extras ),
		'max_modified' => tsosi_scan_get_max_post_modified_gmt(),
	);

	$json = wp_json_encode( $payload );
	return md5( is_string( $json ) ? $json : serialize( $payload ) );
}

/**
 * @param string $fingerprint Expected fingerprint.
 * @return array<string,mixed>|null
 */
function tsosi_scan_get_content_cache( $fingerprint ) {
	$fingerprint = (string) $fingerprint;
	$meta        = get_transient( tsosi_content_cache_transient_key() );

	if ( is_array( $meta ) && ! empty( $meta['fingerprint'] ) && (string) $meta['fingerprint'] === $fingerprint ) {
		if ( ! empty( $meta['index'] ) && empty( $meta['file'] ) ) {
			delete_transient( tsosi_content_cache_transient_key() );
		} elseif ( ! empty( $meta['file'] ) ) {
			$path  = tsosi_scan_cache_file_path( (string) $meta['file'] );
			$index = tsosi_scan_cache_read_json_file( $path );
			if ( is_array( $index ) ) {
				return array(
					'fingerprint' => $fingerprint,
					'index'       => $index,
				);
			}
		}
	}

	$filename = tsosi_scan_cache_index_filename( $fingerprint );
	$path     = tsosi_scan_cache_file_path( $filename );
	$index    = tsosi_scan_cache_read_json_file( $path );
	if ( ! is_array( $index ) ) {
		return null;
	}

	$built_at = is_readable( $path ) ? (int) filemtime( $path ) : time();
	set_transient(
		tsosi_content_cache_transient_key(),
		array(
			'fingerprint' => $fingerprint,
			'file'        => $filename,
			'built_at'    => $built_at > 0 ? $built_at : time(),
		),
		TSOSI_CONTENT_CACHE_TTL
	);

	return array(
		'fingerprint' => $fingerprint,
		'index'       => $index,
	);
}

/**
 * Build the needle-agnostic site index (posts + widgets/menus/options blobs).
 *
 * @param array<string,mixed>|null            $settings    Scan settings.
 * @param int[]|null                          $post_ids    Post IDs (collected when null).
 * @param array<int,array<string,mixed>>|null $extras Extra targets.
 * @param string|null                         $fingerprint Content fingerprint.
 * @return array{posts:array<int,array<string,mixed>>,extras:array<int,array<string,mixed>>}|null
 */
function tsosi_scan_build_content_index( $settings = null, $post_ids = null, $extras = null, $fingerprint = null ) {
	if ( ! tsosi_scan_content_cache_storage_ready() ) {
		return null;
	}

	if ( ! is_array( $settings ) ) {
		$settings = tsosi_get_scan_settings();
	}
	if ( ! is_array( $post_ids ) ) {
		$post_ids = tsosi_scan_collect_post_ids( $settings );
	}
	if ( ! is_array( $extras ) ) {
		$extras = tsosi_scan_collect_extra_targets( $settings );
	}
	if ( null === $fingerprint ) {
		$fingerprint = tsosi_scan_content_fingerprint( $settings, $post_ids, $extras );
	}

	$index = array(
		'posts'  => array(),
		'extras' => array(),
	);

	foreach ( $post_ids as $post_id ) {
		$source = tsosi_scan_extract_post_source( (int) $post_id );
		if ( is_array( $source ) ) {
			$index['posts'][] = $source;
		}
	}

	foreach ( $extras as $target ) {
		if ( ! is_array( $target ) ) {
			continue;
		}
		$type = isset( $target['type'] ) ? sanitize_key( (string) $target['type'] ) : '';
		if ( tsosi_scan_extra_is_needle_dependent( $type ) ) {
			continue;
		}
		$index['extras'] = array_merge( $index['extras'], tsosi_scan_extract_extra_sources( $target ) );
	}

	tsosi_scan_save_content_cache( (string) $fingerprint, $index );

	return $index;
}

/**
 * Return a valid site index when already cached. Does not block on a full rebuild.
 *
 * @param array<string,mixed>|null $settings Optional scan settings.
 * @return array{posts:array<int,array<string,mixed>>,extras:array<int,array<string,mixed>>}|null
 */
function tsosi_scan_ensure_content_index( $settings = null ) {
	if ( ! tsosi_scan_content_cache_storage_ready() ) {
		return null;
	}

	if ( ! is_array( $settings ) ) {
		$settings = tsosi_get_scan_settings();
	}

	$post_ids    = tsosi_scan_collect_post_ids( $settings );
	$extras      = tsosi_scan_collect_extra_targets( $settings );
	$fingerprint = tsosi_scan_content_fingerprint( $settings, $post_ids, $extras );
	$cache       = tsosi_scan_get_content_cache( $fingerprint );

	if ( is_array( $cache ) && ! empty( $cache['index'] ) && is_array( $cache['index'] ) ) {
		return $cache['index'];
	}

	return null;
}

/**
 * Per-user transient key for batched index builds.
 *
 * @return string
 */
function tsosi_index_job_transient_key() {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return TSOSI_TRANSIENT_INDEX_JOB_PREFIX . '0';
	}
	return TSOSI_TRANSIENT_INDEX_JOB_PREFIX . $user_id;
}

/**
 * @return void
 */
function tsosi_index_job_cancel() {
	delete_transient( tsosi_index_job_transient_key() );
}

/**
 * WP_Error when the site index has not been built yet (AJAX should start a batched build).
 *
 * @return WP_Error
 */
function tsosi_scan_need_index_error() {
	return new WP_Error(
		'tsosi_need_index',
		tsosi_ui_triple_text(
			'Site index is not ready yet. Building it in the background…',
			'El índice del sitio aún no está listo. Creándolo en segundo plano…',
			'L\'índex del lloc encara no està a punt. Creant-lo en segon pla…'
		)
	);
}

/**
 * WP_Error when uploads storage cannot hold the index.
 *
 * @return WP_Error
 */
function tsosi_scan_index_unavailable_error() {
	return new WP_Error(
		'tsosi_index_unavailable',
		tsosi_ui_triple_text(
			'Could not build the site index. Check that wp-content/uploads is writable for this plugin.',
			'No se pudo crear el índice del sitio. Comprueba que wp-content/uploads permite escritura para este plugin.',
			'No s\'ha pogut crear l\'índex del lloc. Comprova que wp-content/uploads permet escriptura per a aquest plugin.'
		)
	);
}

/**
 * Start a batched site-index build (does not match needles).
 *
 * @param bool $force Rebuild even if a matching cache exists.
 * @return array<string,mixed>|WP_Error
 */
function tsosi_index_job_start( $force = false ) {
	if ( ! tsosi_scan_content_cache_storage_ready() ) {
		return tsosi_scan_index_unavailable_error();
	}

	$scan_job = get_transient( tsosi_scan_job_transient_key() );
	if ( is_array( $scan_job ) ) {
		return new WP_Error(
			'tsosi_busy',
			tsosi_ui_triple_text(
				'A scan is already running. Wait for it to finish or cancel it first.',
				'Ya hay un escaneo en curso. Espera a que termine o cancélalo primero.',
				'Ja hi ha un escaneig en curs. Espera que acabi o cancel·la\'l primer.'
			)
		);
	}

	$settings    = tsosi_get_scan_settings();
	$post_ids    = tsosi_scan_collect_post_ids( $settings );
	$extras      = tsosi_scan_collect_extra_targets( $settings );
	$fingerprint = tsosi_scan_content_fingerprint( $settings, $post_ids, $extras );

	if ( ! $force ) {
		$cache = tsosi_scan_get_content_cache( $fingerprint );
		if ( is_array( $cache ) && ! empty( $cache['index'] ) ) {
			return array(
				'done'        => true,
				'ready'       => true,
				'cached'      => true,
				'progress'    => 100,
				'total_steps' => count( $post_ids ) + count( $extras ),
				'total_posts' => count( $post_ids ),
			);
		}
	}

	tsosi_index_job_cancel();
	tsosi_scan_delete_build_index();
	tsosi_scan_save_build_index(
		array(
			'posts'  => array(),
			'extras' => array(),
		)
	);

	$job = array(
		'token'       => wp_generate_password( 12, false, false ),
		'post_ids'    => $post_ids,
		'post_offset' => 0,
		'extras'      => $extras,
		'extra_index' => 0,
		'fingerprint' => $fingerprint,
		'started_at'  => time(),
	);
	set_transient( tsosi_index_job_transient_key(), $job, HOUR_IN_SECONDS );

	$total = count( $post_ids ) + count( $extras );
	if ( 0 === $total ) {
		tsosi_scan_save_content_cache(
			$fingerprint,
			array(
				'posts'  => array(),
				'extras' => array(),
			)
		);
		tsosi_scan_delete_build_index();
		tsosi_index_job_cancel();
		return array(
			'done'        => true,
			'ready'       => true,
			'cached'      => false,
			'progress'    => 100,
			'total_steps' => 0,
			'total_posts' => 0,
		);
	}

	return array(
		'done'        => false,
		'ready'       => false,
		'cached'      => false,
		'progress'    => 0,
		'total_steps' => $total,
		'total_posts' => count( $post_ids ),
	);
}

/**
 * Process one batch of the site-index job.
 *
 * @return array<string,mixed>|WP_Error
 */
function tsosi_index_job_step() {
	$job = get_transient( tsosi_index_job_transient_key() );
	if ( ! is_array( $job ) ) {
		$status = tsosi_scan_get_cache_status();
		if ( ! empty( $status['ready'] ) ) {
			return array(
				'done'     => true,
				'ready'    => true,
				'progress' => 100,
			);
		}
		return new WP_Error(
			'tsosi_no_index_job',
			tsosi_ui_triple_text(
				'No index build in progress.',
				'No hay una creación de índice en curso.',
				'No hi ha una creació d\'índex en curs.'
			)
		);
	}

	$post_ids    = isset( $job['post_ids'] ) && is_array( $job['post_ids'] ) ? $job['post_ids'] : array();
	$offset      = isset( $job['post_offset'] ) ? (int) $job['post_offset'] : 0;
	$extras      = isset( $job['extras'] ) && is_array( $job['extras'] ) ? $job['extras'] : array();
	$extra_index = isset( $job['extra_index'] ) ? (int) $job['extra_index'] : 0;
	$index       = tsosi_scan_load_build_index();

	$batch = array_slice( $post_ids, $offset, TSOSI_SCAN_BATCH_SIZE );
	foreach ( $batch as $post_id ) {
		$source = tsosi_scan_extract_post_source( (int) $post_id );
		if ( is_array( $source ) ) {
			$index['posts'][] = $source;
		}
		++$offset;
	}

	if ( ! tsosi_scan_save_build_index( $index ) ) {
		tsosi_index_job_cancel();
		tsosi_scan_delete_build_index();
		return tsosi_scan_index_unavailable_error();
	}

	$fresh = get_transient( tsosi_index_job_transient_key() );
	if ( ! is_array( $fresh ) || (string) ( $fresh['token'] ?? '' ) !== (string) ( $job['token'] ?? '' ) ) {
		return array(
			'done'     => false,
			'aborted'  => true,
			'progress' => 0,
		);
	}

	$job['post_offset'] = $offset;
	$done_posts         = $offset >= count( $post_ids );

	if ( $done_posts && $extra_index < count( $extras ) ) {
		$target = $extras[ $extra_index ];
		$type   = is_array( $target ) && isset( $target['type'] ) ? sanitize_key( (string) $target['type'] ) : '';
		if ( tsosi_scan_extra_is_needle_dependent( $type ) ) {
			++$extra_index;
		} else {
			$index['extras'] = array_merge( $index['extras'], tsosi_scan_extract_extra_sources( $target ) );
			tsosi_scan_save_build_index( $index );
			++$extra_index;
		}
		$job['extra_index'] = $extra_index;
	}

	$total_steps = count( $post_ids ) + count( $extras );
	$current     = min( $offset, count( $post_ids ) ) + min( $extra_index, count( $extras ) );
	$complete    = $done_posts && $extra_index >= count( $extras );

	$still = get_transient( tsosi_index_job_transient_key() );
	if ( ! is_array( $still ) || (string) ( $still['token'] ?? '' ) !== (string) ( $job['token'] ?? '' ) ) {
		return array(
			'done'     => false,
			'aborted'  => true,
			'progress' => 0,
		);
	}

	if ( $complete ) {
		$fingerprint = isset( $job['fingerprint'] ) ? (string) $job['fingerprint'] : '';
		if ( '' !== $fingerprint ) {
			tsosi_scan_save_content_cache( $fingerprint, $index );
		}
		tsosi_scan_delete_build_index();
		tsosi_index_job_cancel();
		return array(
			'done'            => true,
			'ready'           => true,
			'progress'        => 100,
			'processed_steps' => $total_steps,
			'total_steps'     => $total_steps,
			'total_posts'     => count( $post_ids ),
		);
	}

	set_transient( tsosi_index_job_transient_key(), $job, HOUR_IN_SECONDS );

	return array(
		'done'            => false,
		'ready'           => false,
		'progress'        => $total_steps > 0 ? min( 100, (int) round( ( $current / $total_steps ) * 100 ) ) : 100,
		'processed_steps' => $current,
		'total_steps'     => $total_steps,
		'total_posts'     => count( $post_ids ),
	);
}

/**
 * @param string              $fingerprint Fingerprint.
 * @param array<string,mixed> $index       Cached index payload.
 * @return void
 */
function tsosi_scan_save_content_cache( $fingerprint, $index ) {
	if ( ! is_array( $index ) || ! tsosi_scan_content_cache_storage_ready() ) {
		return;
	}

	$filename = tsosi_scan_cache_index_filename( $fingerprint );
	$path     = tsosi_scan_cache_file_path( $filename );
	if ( '' === $path || ! tsosi_scan_cache_write_json_file( $path, $index ) ) {
		return;
	}

	tsosi_scan_delete_build_index();

	set_transient(
		tsosi_content_cache_transient_key(),
		array(
			'fingerprint' => (string) $fingerprint,
			'file'        => $filename,
			'built_at'    => time(),
		),
		TSOSI_CONTENT_CACHE_TTL
	);
}

/**
 * Extract searchable data from one post (no needle matching).
 *
 * @param int $post_id Post ID.
 * @return array<string,mixed>|null
 */
function tsosi_scan_extract_post_source( $post_id ) {
	$post_id = absint( $post_id );
	$post    = get_post( $post_id );
	if ( ! $post instanceof WP_Post ) {
		return null;
	}

	$builder_blobs = tsosi_scan_collect_builder_blobs( $post_id );

	$custom_keys = get_post_custom_keys( $post_id );
	$meta_keys   = array();
	if ( is_array( $custom_keys ) ) {
		foreach ( $custom_keys as $meta_key ) {
			$clean = sanitize_key( (string) $meta_key );
			if ( '' !== $clean ) {
				$meta_keys[] = $clean;
			}
		}
	}

	return array(
		'kind'          => 'post',
		'post_id'       => $post_id,
		'content'       => (string) $post->post_content,
		'meta_keys'     => array_values( array_unique( $meta_keys ) ),
		'builder_blobs' => $builder_blobs,
	);
}

/**
 * Match cached post source against needles.
 *
 * @param array<string,mixed> $source  Cached post source.
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_match_post_source( $source, $needles ) {
	if ( empty( $source['post_id'] ) ) {
		return array();
	}

	$post = get_post( (int) $source['post_id'] );
	if ( ! $post instanceof WP_Post ) {
		return array();
	}

	$tags   = isset( $needles['shortcodes'] ) && is_array( $needles['shortcodes'] ) ? $needles['shortcodes'] : array();
	$blocks = isset( $needles['blocks'] ) && is_array( $needles['blocks'] ) ? $needles['blocks'] : array();
	$found  = array();

	$content = isset( $source['content'] ) ? (string) $source['content'] : '';
	$found   = array_merge( $found, tsosi_scan_content_for_shortcodes( $content, $tags, $post ) );
	$found   = array_merge( $found, tsosi_scan_content_for_blocks( $content, $blocks, $post ) );

	$meta_keys = isset( $source['meta_keys'] ) && is_array( $source['meta_keys'] ) ? $source['meta_keys'] : array();
	$found     = array_merge( $found, tsosi_scan_match_meta_keys_list( $meta_keys, $needles, $post ) );

	$builder_blobs = isset( $source['builder_blobs'] ) && is_array( $source['builder_blobs'] ) ? $source['builder_blobs'] : array();
	foreach ( $builder_blobs as $blob ) {
		if ( ! is_string( $blob ) || '' === $blob ) {
			continue;
		}
		$found = array_merge( $found, tsosi_scan_content_for_shortcodes( $blob, $tags, $post ) );
		$found = array_merge( $found, tsosi_scan_content_for_blocks( $blob, $blocks, $post ) );
	}

	return $found;
}

/**
 * Match a list of meta keys against meta needles.
 *
 * @param string[]            $meta_keys Meta keys on a post.
 * @param array<string,mixed> $needles   Needles.
 * @param WP_Post             $post      Post context.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_match_meta_keys_list( $meta_keys, $needles, $post ) {
	$keys     = isset( $needles['meta_keys'] ) ? (array) $needles['meta_keys'] : array();
	$prefixes = isset( $needles['meta_prefixes'] ) ? (array) $needles['meta_prefixes'] : array();
	if ( empty( $keys ) && empty( $prefixes ) ) {
		return array();
	}

	$found        = array();
	$matched_keys = array();

	foreach ( $keys as $meta_key ) {
		$meta_key = sanitize_key( (string) $meta_key );
		if ( '' === $meta_key || ! in_array( $meta_key, $meta_keys, true ) ) {
			continue;
		}
		$matched_keys[] = $meta_key;
		$found[]        = tsosi_scan_make_result(
			$post,
			'meta',
			$meta_key,
			tsosi_ui_triple_text( 'Post meta', 'Meta del post', 'Meta de l\'entrada' )
		);
	}

	foreach ( $meta_keys as $meta_key ) {
		$meta_key = sanitize_key( (string) $meta_key );
		if ( '' === $meta_key || in_array( $meta_key, $matched_keys, true ) ) {
			continue;
		}
		foreach ( $prefixes as $prefix ) {
			if ( tsosi_scan_meta_key_matches_prefix( $meta_key, (string) $prefix ) ) {
				$matched_keys[] = $meta_key;
				$found[]        = tsosi_scan_make_result(
					$post,
					'meta',
					$meta_key,
					tsosi_ui_triple_text( 'Post meta', 'Meta del post', 'Meta de l\'entrada' )
				);
				break;
			}
		}
	}

	return $found;
}

/**
 * Extract widget option blobs for caching.
 *
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_extract_widget_sources() {
	$rows    = array();
	$options = wp_load_alloptions();

	foreach ( $options as $option_name => $value ) {
		$name = (string) $option_name;
		if ( 0 !== strpos( $name, 'widget_' ) && 'sidebars_widgets' !== $name ) {
			continue;
		}
		$blob = tsosi_scan_value_to_blob( $value );
		if ( strlen( $blob ) > 500000 ) {
			continue;
		}
		$rows[] = array(
			'kind'     => 'widget',
			'label'    => sanitize_text_field( $name ),
			'blob'     => $blob,
			'edit_url' => admin_url( 'widgets.php' ),
		);
	}

	return $rows;
}

/**
 * Extract autoload wp_options blobs for caching (skips large/core keys).
 *
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_extract_autoload_option_sources() {
	$rows          = array();
	$options       = wp_load_alloptions();
	$skip_exact    = array(
		'cron',
		'siteurl',
		'home',
		'blog_charset',
		'db_version',
		'user_roles',
		'active_plugins',
		'recently_activated',
		'rewrite_rules',
	);
	$skip_prefixes = array(
		'_transient_',
		'_site_transient_',
		'widget_',
	);

	foreach ( $options as $option_name => $value ) {
		$name = (string) $option_name;
		if ( in_array( $name, $skip_exact, true ) ) {
			continue;
		}
		if ( tsosi_is_sensitive_storage_option_name( $name ) ) {
			continue;
		}
		foreach ( $skip_prefixes as $prefix ) {
			if ( 0 === strpos( $name, $prefix ) ) {
				continue 2;
			}
		}
		$blob = tsosi_scan_value_to_blob( $value );
		if ( strlen( $blob ) > 500000 ) {
			continue;
		}
		$rows[] = array(
			'kind'  => 'option',
			'label' => sanitize_text_field( $name ),
			'blob'  => $blob,
		);
	}

	return $rows;
}

/**
 * @param array<string,mixed> $target Menu target descriptor.
 * @return array<string,mixed>|null
 */
function tsosi_scan_extract_menu_source( $target ) {
	$term_id = isset( $target['term_id'] ) ? absint( $target['term_id'] ) : 0;
	if ( $term_id <= 0 ) {
		return null;
	}

	$items = wp_get_nav_menu_items( $term_id );
	if ( ! is_array( $items ) ) {
		return null;
	}

	$rows = array();
	foreach ( $items as $item ) {
		if ( ! $item instanceof WP_Post ) {
			continue;
		}
		$rows[] = (string) $item->post_content . ' ' . (string) $item->post_title;
	}

	return array(
		'kind'    => 'menu',
		'term_id' => $term_id,
		'name'    => isset( $target['name'] ) ? (string) $target['name'] : (string) $term_id,
		'items'   => $rows,
	);
}

/**
 * Extract one extra target into cacheable sources.
 *
 * @param array<string,mixed> $target Extra target.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_extract_extra_sources( $target ) {
	$type = isset( $target['type'] ) ? sanitize_key( (string) $target['type'] ) : '';

	if ( 'widgets' === $type ) {
		return tsosi_scan_extract_widget_sources();
	}
	if ( 'options' === $type ) {
		return tsosi_scan_extract_autoload_option_sources();
	}
	if ( 'menu' === $type ) {
		$menu = tsosi_scan_extract_menu_source( $target );
		return $menu ? array( $menu ) : array();
	}
	if ( 'theme_mods' === $type ) {
		// Needle-dependent: scanned live via tsosi_scan_theme_mods(), not stored in the content index.
		return array();
	}

	return array();
}

/**
 * Match blob rows (widgets / autoload options).
 *
 * @param array<int,array<string,mixed>> $rows    Cached rows.
 * @param array<string,mixed>            $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_match_blob_sources( $rows, $needles ) {
	$found = array();
	if ( ! is_array( $rows ) ) {
		return $found;
	}

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) || empty( $row['blob'] ) || ! is_string( $row['blob'] ) ) {
			continue;
		}
		$kind = isset( $row['kind'] ) ? sanitize_key( (string) $row['kind'] ) : 'option';
		if ( 'widget' === $kind ) {
			$ctx   = tsosi_ui_triple_text( 'Widget option', 'Opción de widget', 'Opció de widget' );
			$found = array_merge(
				$found,
				tsosi_scan_blob_rows(
					$row['blob'],
					$needles,
					isset( $row['label'] ) ? (string) $row['label'] : '',
					$ctx,
					isset( $row['edit_url'] ) ? (string) $row['edit_url'] : tsosi_scan_widgets_admin_url(),
					'widget'
				)
			);
			continue;
		}

		if ( 'theme_mod' === $kind ) {
			$ctx   = tsosi_ui_triple_text( 'Theme mods', 'Ajustes del tema', 'Ajustos del tema' );
			$found = array_merge(
				$found,
				tsosi_scan_blob_rows(
					$row['blob'],
					$needles,
					isset( $row['label'] ) ? (string) $row['label'] : '',
					$ctx,
					isset( $row['edit_url'] ) ? (string) $row['edit_url'] : admin_url( 'customize.php' ),
					'theme_mod'
				)
			);
			continue;
		}

		$ctx   = tsosi_ui_triple_text( 'wp_options', 'wp_options', 'wp_options' );
		$found = array_merge(
			$found,
			tsosi_scan_blob_rows(
				$row['blob'],
				$needles,
				isset( $row['label'] ) ? (string) $row['label'] : '',
				$ctx,
				'',
				'option'
			)
		);
	}

	return $found;
}

/**
 * @param array<string,mixed> $source  Cached menu source.
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_match_menu_source( $source, $needles ) {
	if ( empty( $source['term_id'] ) ) {
		return array();
	}

	$term_id = absint( $source['term_id'] );
	$name    = isset( $source['name'] ) ? (string) $source['name'] : (string) $term_id;
	$items   = isset( $source['items'] ) && is_array( $source['items'] ) ? $source['items'] : array();
	$tags    = isset( $needles['shortcodes'] ) && is_array( $needles['shortcodes'] ) ? $needles['shortcodes'] : array();
	$blocks  = isset( $needles['blocks'] ) && is_array( $needles['blocks'] ) ? $needles['blocks'] : array();
	$found   = array();
	$stub    = (object) array( 'ID' => 0 );

	foreach ( $items as $content ) {
		if ( ! is_string( $content ) || '' === $content ) {
			continue;
		}
		foreach ( tsosi_scan_content_for_shortcodes( $content, $tags, $stub ) as $row ) {
			$row['source_type']  = 'menu';
			$row['object_label'] = $name;
			$row['context']      = tsosi_ui_triple_text( 'Navigation menu', 'Menú de navegación', 'Menú de navegació' );
			$row['edit_url']     = admin_url( 'nav-menus.php?action=edit&menu=' . $term_id );
			$found[]             = $row;
		}
		foreach ( tsosi_scan_content_for_blocks( $content, $blocks, $stub ) as $row ) {
			$row['source_type']  = 'menu';
			$row['object_label'] = $name;
			$row['context']      = tsosi_ui_triple_text( 'Navigation menu', 'Menú de navegación', 'Menú de navegació' );
			$row['edit_url']     = admin_url( 'nav-menus.php?action=edit&menu=' . $term_id );
			$found[]             = $row;
		}
	}

	return $found;
}

/**
 * Match a full cached index against needles.
 *
 * @param array<string,mixed> $index   Cached index (posts + extras).
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_match_cached_index( $index, $needles ) {
	$results = array();
	if ( ! is_array( $index ) ) {
		return $results;
	}

	$posts = isset( $index['posts'] ) && is_array( $index['posts'] ) ? $index['posts'] : array();
	foreach ( $posts as $source ) {
		if ( is_array( $source ) ) {
			$results = array_merge( $results, tsosi_scan_match_post_source( $source, $needles ) );
		}
	}

	$extras = isset( $index['extras'] ) && is_array( $index['extras'] ) ? $index['extras'] : array();
	foreach ( $extras as $source ) {
		if ( ! is_array( $source ) ) {
			continue;
		}
		$kind = isset( $source['kind'] ) ? sanitize_key( (string) $source['kind'] ) : '';
		if ( 'menu' === $kind ) {
			$results = array_merge( $results, tsosi_scan_match_menu_source( $source, $needles ) );
			continue;
		}
		if ( 'widget' === $kind || 'option' === $kind ) {
			$results = array_merge( $results, tsosi_scan_match_blob_sources( array( $source ), $needles ) );
		}
		// theme_mod blobs are ignored from the index: scanned live via tsosi_scan_theme_mods().
	}

	$settings = tsosi_get_scan_settings();
	if ( ! empty( $settings['include_widgets'] ) && ! empty( $needles['option_prefixes'] ) ) {
		$results = array_merge(
			$results,
			tsosi_scan_active_widget_instances( $needles, tsosi_scan_widgets_admin_url() )
		);
	}
	if ( ! empty( $settings['include_non_autoload_options'] ) && ! empty( $needles['option_prefixes'] ) ) {
		$results = array_merge( $results, tsosi_scan_non_autoload_options( $needles ) );
	}
	if ( ! empty( $settings['include_theme_mods'] ) || tsosi_scan_needles_want_theme_mods( $needles ) ) {
		$results = array_merge( $results, tsosi_scan_theme_mods( $needles ) );
	}
	if ( ! empty( $settings['include_user_meta'] ) ) {
		$results = array_merge( $results, tsosi_scan_user_meta( $needles ) );
	}
	if ( ! empty( $settings['include_term_meta'] ) ) {
		$results = array_merge( $results, tsosi_scan_term_meta( $needles ) );
	}
	if ( ! empty( $settings['include_comment_meta'] ) ) {
		$results = array_merge( $results, tsosi_scan_comment_meta( $needles ) );
	}

	return tsosi_scan_dedupe_results( $results );
}

/**
 * Build a completed scan response (shared by cache hit and job finish).
 *
 * @param array<string,mixed>            $needles Needles.
 * @param array<int,array<string,mixed>> $results Results.
 * @param int                            $post_count Posts scanned/indexed.
 * @param int                            $extra_count Extras scanned/indexed.
 * @param bool                           $from_cache Whether index came from cache.
 * @param array<string,mixed>            $query      Original scan query (for history).
 * @return array<string,mixed>
 */
function tsosi_scan_build_complete_response( $needles, $results, $post_count, $extra_count, $from_cache = false, $query = array() ) {
	$results     = tsosi_scan_filter_ignored_results(
		tsosi_scan_filter_cross_plugin_false_positives( $results, is_array( $query ) ? $query : array() )
	);
	$total_steps = $post_count + $extra_count;
	$response    = array(
		'done'              => true,
		'cached'            => $from_cache,
		'progress'          => 100,
		'processed'         => $post_count,
		'processed_steps'   => $total_steps,
		'total_steps'       => $total_steps,
		'total_posts'       => $post_count,
		'results'           => $results,
		'result_count'      => count( $results ),
		'needles'           => $needles,
		'needles_empty'     => tsosi_scan_needles_are_empty( $needles ),
		'risk'              => tsosi_scan_assess_query_aware_risk( $results, is_array( $query ) ? $query : array() ),
		'cleaner_url'       => tsosi_get_options_cleaner_url_from_needles(
			$needles,
			isset( $query['plugin_file'] ) ? (string) $query['plugin_file'] : ''
		),
		'cleaner_available' => tsosi_options_cleaner_is_available(),
		'plugin_file'       => isset( $query['plugin_file'] ) ? (string) $query['plugin_file'] : '',
	);

	if ( is_array( $query ) && ! empty( $query ) ) {
		$history_id = tsosi_scan_history_save(
			$query,
			$results,
			array(
				'total_posts'   => $post_count,
				'result_count'  => count( $results ),
				'needles'       => $needles,
				'needles_empty' => $response['needles_empty'],
				'cached'        => $from_cache,
			)
		);
		if ( '' !== $history_id ) {
			$response['history_id'] = $history_id;
		}
	}

	return $response;
}
