<?php
/**
 * Storage helpers for TSO Stack Inspector.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOSI_DB_SCHEMA' ) ) {
	define( 'TSOSI_DB_SCHEMA', 2 );
}

if ( ! defined( 'TSOSI_OPTION_DB_SCHEMA' ) ) {
	define( 'TSOSI_OPTION_DB_SCHEMA', 'tso_stack_inspector_db_schema' );
}

if ( ! defined( 'TSOSI_OPTION_SCAN_SETTINGS' ) ) {
	define( 'TSOSI_OPTION_SCAN_SETTINGS', 'tso_stack_inspector_scan_settings' );
}

if ( ! defined( 'TSOSI_TRANSIENT_SCAN_JOB' ) ) {
	define( 'TSOSI_TRANSIENT_SCAN_JOB', 'tso_stack_inspector_scan_job' );
}

if ( ! defined( 'TSOSI_TRANSIENT_SCAN_JOB_PREFIX' ) ) {
	define( 'TSOSI_TRANSIENT_SCAN_JOB_PREFIX', 'tso_stack_inspector_scan_job_' );
}

if ( ! defined( 'TSOSI_TRANSIENT_INDEX_JOB_PREFIX' ) ) {
	define( 'TSOSI_TRANSIENT_INDEX_JOB_PREFIX', 'tso_stack_inspector_index_job_' );
}

if ( ! defined( 'TSOSI_SCAN_HISTORY_MAX_DEFAULT' ) ) {
	define( 'TSOSI_SCAN_HISTORY_MAX_DEFAULT', 20 );
}

if ( ! defined( 'TSOSI_SCAN_HISTORY_MAX_HARD' ) ) {
	define( 'TSOSI_SCAN_HISTORY_MAX_HARD', 100 );
}

if ( ! defined( 'TSOSI_TRANSIENT_PLUGIN_PROFILE' ) ) {
	define( 'TSOSI_TRANSIENT_PLUGIN_PROFILE', 'tso_stack_inspector_plugin_profile' );
}

if ( ! defined( 'TSOSI_USER_META_UI_LANG' ) ) {
	define( 'TSOSI_USER_META_UI_LANG', 'tso_stack_inspector_ui_lang' );
}

if ( ! defined( 'TSOSI_ADMIN_POST_ACTION' ) ) {
	define( 'TSOSI_ADMIN_POST_ACTION', 'tsosi_action' );
}

if ( ! defined( 'TSOSI_ADMIN_QUERY_SET_LANG' ) ) {
	define( 'TSOSI_ADMIN_QUERY_SET_LANG', 'tsosi_set_lang' );
}

if ( ! defined( 'TSOSI_ADMIN_QUERY_REPLACE_KIND' ) ) {
	define( 'TSOSI_ADMIN_QUERY_REPLACE_KIND', 'tsosi_replace_kind' );
}

if ( ! defined( 'TSOSI_ADMIN_QUERY_REPLACE_FROM' ) ) {
	define( 'TSOSI_ADMIN_QUERY_REPLACE_FROM', 'tsosi_replace_from' );
}

/**
 * Verify AJAX nonce via WordPress core helper (soft fail).
 *
 * @return bool
 */
function tsosi_verify_ajax_nonce() {
	return (bool) check_ajax_referer( TSOSI_NONCE_AJAX, '_ajax_nonce', false );
}

/**
 * Require a valid AJAX nonce and manage_options. Sends JSON 403 and exits on failure.
 *
 * @return void
 */
function tsosi_ajax_require_manage_options() {
	if ( ! check_ajax_referer( TSOSI_NONCE_AJAX, '_ajax_nonce', false ) ) {
		wp_send_json_error( array( 'message' => __( 'Forbidden.', 'tso-stack-inspector' ) ), 403 );
	}
	if ( ! current_user_can( 'manage_options' ) ) {
		wp_send_json_error( array( 'message' => __( 'Forbidden.', 'tso-stack-inspector' ) ), 403 );
	}
}

/**
 * Verify admin form nonce via WordPress core helper (dies on failure).
 *
 * @param string $query_arg Request key holding the nonce.
 * @return void
 */
function tsosi_require_admin_form_nonce( $query_arg = '_wpnonce' ) {
	$query_arg = sanitize_key( (string) $query_arg );
	if ( '' === $query_arg ) {
		$query_arg = '_wpnonce';
	}
	check_admin_referer( TSOSI_NONCE_FORM, $query_arg );
}

/**
 * Soft-verify admin form nonce (legacy callers). Prefer tsosi_require_admin_form_nonce().
 *
 * @param string $query_arg Request key holding the nonce.
 * @return bool
 */
function tsosi_verify_admin_form_nonce( $query_arg = '_wpnonce' ) {
	$query_arg = sanitize_key( (string) $query_arg );
	if ( '' === $query_arg ) {
		$query_arg = '_wpnonce';
	}
	$nonce = isset( $_REQUEST[ $query_arg ] ) ? sanitize_text_field( wp_unslash( $_REQUEST[ $query_arg ] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verification itself; used only for soft bool return.
	if ( '' === $nonce ) {
		return false;
	}
	return (bool) wp_verify_nonce( $nonce, TSOSI_NONCE_FORM );
}

/**
 * Read sanitized POST text after AJAX nonce verification.
 *
 * @param string $key     POST key.
 * @param string $default Default when missing.
 * @return string
 */
function tsosi_get_ajax_post_text( $key, $default = '' ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via check_ajax_referer() / tsosi_ajax_require_manage_options().
		return $default;
	}
	return sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via check_ajax_referer() / tsosi_ajax_require_manage_options().
}

/**
 * Read sanitized admin query arg.
 *
 * @param string $key     Query arg key.
 * @param string $default Default when missing.
 * @return string
 */
function tsosi_get_admin_query_arg( $key, $default = '' ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_GET[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display parameter.
		return $default;
	}
	return sanitize_text_field( (string) wp_unslash( $_GET[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only display parameter.
}

/**
 * Read sanitized admin POST text after form nonce verification.
 *
 * @param string $key     POST key.
 * @param string $default Default when missing.
 * @return string
 */
function tsosi_get_admin_post_text( $key, $default = '' ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via check_admin_referer() / tsosi_require_admin_form_nonce().
		return $default;
	}
	return sanitize_text_field( (string) wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via check_admin_referer() / tsosi_require_admin_form_nonce().
}

/**
 * Read sanitized admin POST textarea after form nonce verification.
 *
 * @param string $key     POST key.
 * @param string $default Default when missing.
 * @return string
 */
function tsosi_get_admin_post_textarea( $key, $default = '' ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via check_admin_referer() / tsosi_require_admin_form_nonce().
		return $default;
	}
	return sanitize_textarea_field( (string) wp_unslash( $_POST[ $key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via check_admin_referer() / tsosi_require_admin_form_nonce().
}

/**
 * Sanitize ignore-list tokens (shortcode tags, block names, meta/option keys).
 *
 * @param mixed $tokens Raw tokens or newline-separated text.
 * @return string[]
 */
function tsosi_sanitize_ignore_tokens( $tokens ) {
	if ( is_string( $tokens ) ) {
		$tokens = preg_split( '/\r\n|\r|\n/', $tokens );
	}
	if ( ! is_array( $tokens ) ) {
		return array();
	}

	$clean = array();
	foreach ( $tokens as $token ) {
		$token = strtolower( trim( (string) $token ) );
		if ( '' === $token || '#' === $token[0] ) {
			continue;
		}
		$sanitized = preg_replace( '/[^a-z0-9\/_-]/', '', $token );
		if ( ! is_string( $sanitized ) || '' === $sanitized ) {
			continue;
		}
		$clean[ $sanitized ] = $sanitized;
	}

	return array_values( $clean );
}

/**
 * Whether a match value is on the admin ignore list.
 *
 * @param string $value Match value (tag, block name, meta key).
 * @return bool
 */
function tsosi_scan_value_is_ignored( $value ) {
	$value = strtolower( trim( (string) $value ) );
	if ( '' === $value ) {
		return false;
	}

	$settings = tsosi_get_scan_settings();
	$tokens   = isset( $settings['ignore_tokens'] ) && is_array( $settings['ignore_tokens'] )
		? $settings['ignore_tokens']
		: array();

	foreach ( $tokens as $token ) {
		$token = strtolower( (string) $token );
		if ( '' === $token ) {
			continue;
		}
		if ( $value === $token || 0 === strpos( $value, $token ) ) {
			return true;
		}
	}

	return false;
}

/**
 * Read sanitized checkbox from admin POST after form nonce verification.
 *
 * @param string $key POST key.
 * @return bool
 */
function tsosi_get_admin_post_checkbox( $key ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via check_admin_referer() / tsosi_require_admin_form_nonce().
		return false;
	}
	return ! empty( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via check_admin_referer() / tsosi_require_admin_form_nonce().
}

/**
 * Read sanitized string array from admin POST after form nonce verification.
 *
 * @param string $key POST key.
 * @return string[]
 */
function tsosi_get_admin_post_array( $key ) {
	$key = (string) $key;
	if ( '' === $key || ! isset( $_POST[ $key ] ) || ! is_array( $_POST[ $key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- Caller verified via check_admin_referer() / tsosi_require_admin_form_nonce().
		return array();
	}
	$raw = wp_unslash( $_POST[ $key ] ); // phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Caller verified via check_admin_referer() / tsosi_require_admin_form_nonce(); values sanitized via sanitize_key below.
	if ( ! is_array( $raw ) ) {
		return array();
	}
	return array_values(
		array_filter(
			array_map( 'sanitize_key', $raw )
		)
	);
}

/**
 * Sanitize a shortcode tag (preserve hyphens; sanitize_key strips them).
 *
 * @param string $tag Shortcode tag.
 * @return string
 */
function tsosi_sanitize_shortcode_tag( $tag ) {
	$tag = strtolower( trim( (string) $tag ) );
	if ( '' === $tag ) {
		return '';
	}
	$clean = preg_replace( '/[^a-z0-9_-]/', '', $tag );
	return is_string( $clean ) ? $clean : '';
}

/**
 * Sanitize a block name (namespace/block).
 *
 * @param string $name Block name.
 * @return string
 */
function tsosi_sanitize_block_name( $name ) {
	$name = strtolower( trim( (string) $name ) );
	if ( '' === $name ) {
		return '';
	}
	$clean = preg_replace( '/[^a-z0-9\/_-]/', '', $name );
	return is_string( $clean ) ? $clean : '';
}

/**
 * Run storage migrations on activate/admin bootstrap.
 *
 * @return void
 */
function tsosi_migrate_storage() {
	$current = (int) get_option( TSOSI_OPTION_DB_SCHEMA, 0 );
	if ( $current >= TSOSI_DB_SCHEMA ) {
		return;
	}
	update_option( TSOSI_OPTION_DB_SCHEMA, TSOSI_DB_SCHEMA );
}

/**
 * Default scan settings.
 *
 * @return array<string,mixed>
 */
function tsosi_get_default_scan_settings() {
	return array(
		'post_statuses'                => array( 'publish', 'draft', 'pending', 'future', 'private' ),
		'include_reusable_blocks'      => true,
		'include_widgets'              => true,
		'include_menus'                => true,
		'include_non_autoload_options' => true,
		'include_user_meta'            => true,
		'include_term_meta'            => true,
		'include_comment_meta'         => true,
		'include_theme_mods'           => true,
		'history_max'                  => TSOSI_SCAN_HISTORY_MAX_DEFAULT,
		'ignore_tokens'                => array(),
	);
}

/**
 * @return array<string,mixed>
 */
function tsosi_get_scan_settings() {
	$stored = get_option( TSOSI_OPTION_SCAN_SETTINGS, array() );
	if ( ! is_array( $stored ) ) {
		$stored = array();
	}
	return wp_parse_args( $stored, tsosi_get_default_scan_settings() );
}

/**
 * @param array<string,mixed> $settings Settings.
 * @return void
 */
function tsosi_update_scan_settings( $settings ) {
	if ( ! is_array( $settings ) ) {
		return;
	}
	$defaults = tsosi_get_default_scan_settings();
	$clean    = array(
		'post_statuses'                => array_values(
			array_filter(
				array_map(
					'sanitize_key',
					(array) ( $settings['post_statuses'] ?? $defaults['post_statuses'] )
				)
			)
		),
		'include_reusable_blocks'      => ! empty( $settings['include_reusable_blocks'] ),
		'include_widgets'              => ! empty( $settings['include_widgets'] ),
		'include_menus'                => ! empty( $settings['include_menus'] ),
		'include_non_autoload_options' => ! empty( $settings['include_non_autoload_options'] ),
		'include_user_meta'            => ! empty( $settings['include_user_meta'] ),
		'include_term_meta'            => ! empty( $settings['include_term_meta'] ),
		'include_comment_meta'         => ! empty( $settings['include_comment_meta'] ),
		'include_theme_mods'           => ! empty( $settings['include_theme_mods'] ),
		'history_max'                  => max(
			1,
			min(
				TSOSI_SCAN_HISTORY_MAX_HARD,
				absint( $settings['history_max'] ?? TSOSI_SCAN_HISTORY_MAX_DEFAULT )
			)
		),
		'ignore_tokens'                => tsosi_sanitize_ignore_tokens( $settings['ignore_tokens'] ?? array() ),
	);
	update_option( TSOSI_OPTION_SCAN_SETTINGS, $clean );
	tsosi_flush_all_content_caches();
	tsosi_scan_history_enforce_max();
}

/**
 * Per-user transient key for in-progress scan jobs (avoids cross-admin races).
 *
 * @return string
 */
function tsosi_scan_job_transient_key() {
	$user_id = get_current_user_id();
	if ( $user_id <= 0 ) {
		return TSOSI_TRANSIENT_SCAN_JOB;
	}
	return TSOSI_TRANSIENT_SCAN_JOB_PREFIX . $user_id;
}

/**
 * Uploads subdirectory for this plugin.
 *
 * @return array{path:string,url:string,error:bool}
 */
function tsosi_get_uploads_dir() {
	$upload = wp_upload_dir();
	if ( ! empty( $upload['error'] ) ) {
		return array(
			'path'  => '',
			'url'   => '',
			'error' => true,
		);
	}
	$path = trailingslashit( $upload['basedir'] ) . 'tso-stack-inspector';
	$url  = trailingslashit( $upload['baseurl'] ) . 'tso-stack-inspector';
	if ( ! wp_mkdir_p( $path ) ) {
		return array(
			'path'  => '',
			'url'   => '',
			'error' => true,
		);
	}

	// Prefer a non-PHP directory index (WordPress.org / TSO: no generated PHP under uploads).
	$legacy_php = $path . '/index.php';
	if ( is_file( $legacy_php ) ) {
		wp_delete_file( $legacy_php );
	}
	$index_html = $path . '/index.html';
	if ( ! is_file( $index_html ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- non-executable stub in uploads.
		file_put_contents( $index_html, "<!DOCTYPE html><title></title>\n" );
	}

	tsosi_protect_uploads_dir( $path );

	return array(
		'path'  => $path,
		'url'   => $url,
		'error' => false,
	);
}

/**
 * Acquire a short-lived, named exclusive lock backed by a file in plugin uploads.
 *
 * Guards read-modify-write sequences on shared state (options, backup index files)
 * against a "lost update": two near-simultaneous requests (a cron tick racing a
 * manual action, or two batches applied in close succession) each reading the
 * same value, modifying their own copy, and writing it back -- the last write
 * silently discards whatever the other request changed.
 *
 * @param string $name Lock name (basename only, no path separators).
 * @return resource|false Lock handle for tsosi_release_lock(), or false if unavailable.
 */
function tsosi_acquire_lock( $name ) {
	$dir = tsosi_get_uploads_dir();
	if ( ! empty( $dir['error'] ) || '' === $dir['path'] ) {
		return false;
	}
	$name = sanitize_key( (string) $name );
	if ( '' === $name ) {
		return false;
	}
	$path = trailingslashit( $dir['path'] ) . 'lock-' . $name . '.lock';
	// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- short-lived lock file guarding a read-modify-write section.
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
 * Release a lock acquired with tsosi_acquire_lock().
 *
 * @param resource|false $handle Lock handle.
 * @return void
 */
function tsosi_release_lock( $handle ) {
	if ( is_resource( $handle ) ) {
		flock( $handle, LOCK_UN );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- pairs with fopen() in tsosi_acquire_lock().
		fclose( $handle );
	}
}

/**
 * Deny direct HTTP access to plugin uploads (JSON caches may contain site content).
 *
 * @param string $path Absolute uploads subdirectory path.
 * @return void
 */
function tsosi_protect_uploads_dir( $path ) {
	$path = untrailingslashit( (string) $path );
	if ( '' === $path || ! is_dir( $path ) ) {
		return;
	}

	$htaccess = $path . '/.htaccess';
	$rules    = "# TSO Stack Inspector — deny direct web access to cache/history/backups.\n"
		. "<IfModule mod_authz_core.c>\n"
		. "\tRequire all denied\n"
		. "</IfModule>\n"
		. "<IfModule !mod_authz_core.c>\n"
		. "\tOrder deny,allow\n"
		. "\tDeny from all\n"
		. "</IfModule>\n";

	$existing = is_readable( $htaccess ) ? (string) file_get_contents( $htaccess ) : ''; // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local deny rules only.
	if ( false === strpos( $existing, 'Require all denied' ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Apache deny rules for private uploads.
		file_put_contents( $htaccess, $rules );
	}

	$web_config = $path . '/web.config';
	$iis        = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n"
		. "<configuration>\n"
		. "\t<system.webServer>\n"
		. "\t\t<authorization>\n"
		. "\t\t\t<deny users=\"*\" />\n"
		. "\t\t</authorization>\n"
		. "\t</system.webServer>\n"
		. "</configuration>\n";

	if ( ! is_file( $web_config ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- IIS deny rules for private uploads.
		file_put_contents( $web_config, $iis );
	}
}
