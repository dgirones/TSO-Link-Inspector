<?php
/**
 * Dry-run / apply content replacements for shortcodes and blocks (posts only).
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOSI_REPLACE_BATCH' ) ) {
	define( 'TSOSI_REPLACE_BATCH', 25 );
}

if ( ! defined( 'TSOSI_OPTION_REPLACE_BACKUPS' ) ) {
	define( 'TSOSI_OPTION_REPLACE_BACKUPS', 'tso_stack_inspector_replace_backups' );
}

if ( ! defined( 'TSOSI_REPLACE_BACKUP_TTL' ) ) {
	define( 'TSOSI_REPLACE_BACKUP_TTL', 2 * DAY_IN_SECONDS );
}

/**
 * Post types allowed for replace operations.
 *
 * @return string[]
 */
function tsosi_replace_post_types() {
	$types  = array( 'post', 'page', 'wp_block', 'wp_template', 'wp_template_part', 'wp_navigation' );
	$public = get_post_types( array( 'public' => true ), 'names' );
	if ( is_array( $public ) ) {
		$types = array_merge( $types, array_values( $public ) );
	}
	$types = array_values( array_unique( array_map( 'sanitize_key', $types ) ) );
	/**
	 * Filter post types eligible for Stack Inspector replace.
	 *
	 * @param string[] $types Post types.
	 */
	return apply_filters( 'tsosi_replace_post_types', $types );
}

/**
 * Replace a shortcode tag inside content (opening, self-closing, closing).
 *
 * @param string $content Content.
 * @param string $from    Old tag.
 * @param string $to      New tag.
 * @return string
 */
function tsosi_replace_shortcode_in_content( $content, $from, $to ) {
	$from = tsosi_sanitize_shortcode_tag( $from );
	$to   = tsosi_sanitize_shortcode_tag( $to );
	if ( '' === $from || '' === $to || $from === $to || ! is_string( $content ) || '' === $content ) {
		return is_string( $content ) ? $content : '';
	}

	$from_q = preg_quote( $from, '/' );
	$to_q   = $to;

	// Closing tags: [/from]
	$content = preg_replace( '/\[\/' . $from_q . '\]/i', '[/' . $to_q . ']', $content );
	// Opening / self-closing: [from ...] or [from]
	$content = preg_replace( '/\[' . $from_q . '(\s|\]|\/)/i', '[' . $to_q . '$1', $content );

	return is_string( $content ) ? $content : '';
}

/**
 * Replace a Gutenberg block name inside classic block comments.
 *
 * @param string $content Content.
 * @param string $from    Old block name namespace/block.
 * @param string $to      New block name.
 * @return string
 */
function tsosi_replace_block_in_content( $content, $from, $to ) {
	$from = tsosi_sanitize_block_name( $from );
	$to   = tsosi_sanitize_block_name( $to );
	if ( '' === $from || '' === $to || $from === $to || ! is_string( $content ) || '' === $content ) {
		return is_string( $content ) ? $content : '';
	}

	$from_q = preg_quote( $from, '/' );
	// <!-- wp:old/name ... --> and <!-- /wp:old/name -->
	$content = preg_replace( '/(<!--\s*\/?wp:)' . $from_q . '([\s{])/i', '$1' . $to . '$2', $content );
	$content = preg_replace( '/(<!--\s*\/?wp:)' . $from_q . '(\s*\/?-->)/i', '$1' . $to . '$2', $content );

	return is_string( $content ) ? $content : '';
}

/**
 * Apply one replacement to content by kind.
 *
 * @param string $content Content.
 * @param string $kind    shortcode|block.
 * @param string $from    From.
 * @param string $to      To.
 * @return string
 */
function tsosi_replace_apply_to_content( $content, $kind, $from, $to ) {
	if ( 'block' === $kind ) {
		return tsosi_replace_block_in_content( $content, $from, $to );
	}
	return tsosi_replace_shortcode_in_content( $content, $from, $to );
}

/**
 * Whether content would change after replacement.
 *
 * @param string $content Content.
 * @param string $kind    Kind.
 * @param string $from    From.
 * @param string $to      To.
 * @return bool
 */
function tsosi_replace_content_would_change( $content, $kind, $from, $to ) {
	$next = tsosi_replace_apply_to_content( $content, $kind, $from, $to );
	return $next !== $content;
}

/**
 * Collect candidate post IDs that may contain the needle (SQL LIKE prefilter).
 *
 * @param string $kind shortcode|block.
 * @param string $from Needle.
 * @param int    $limit Max IDs.
 * @return int[]
 */
function tsosi_replace_find_candidate_ids( $kind, $from, $limit = 500 ) {
	global $wpdb;

	$limit = max( 1, min( 1000, (int) $limit ) );
	$types = tsosi_replace_post_types();
	if ( empty( $types ) ) {
		return array();
	}

	$like_needle = 'block' === $kind ? $from : '[' . $from;
	$like        = '%' . $wpdb->esc_like( $like_needle ) . '%';
	$ids         = array();

	// Query per post type to keep prepared SQL simple and portable.
	foreach ( $types as $post_type ) {
		$post_type = sanitize_key( (string) $post_type );
		if ( '' === $post_type ) {
			continue;
		}
		$remaining = $limit - count( $ids );
		if ( $remaining <= 0 ) {
			break;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin replace preview.
		$batch = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status NOT IN ('trash','auto-draft') AND post_content LIKE %s ORDER BY ID ASC LIMIT %d",
				$post_type,
				$like,
				$remaining
			)
		);
		if ( is_array( $batch ) ) {
			foreach ( $batch as $id ) {
				$ids[] = absint( $id );
			}
		}
	}

	$ids = array_values( array_unique( $ids ) );
	sort( $ids, SORT_NUMERIC );
	return array_slice( $ids, 0, $limit );
}

/**
 * Dry-run: list posts that would change.
 *
 * @param string $kind shortcode|block.
 * @param string $from From value.
 * @param string $to   To value.
 * @return array<string,mixed>|WP_Error
 */
function tsosi_replace_preview( $kind, $from, $to ) {
	$kind = sanitize_key( $kind );
	if ( ! in_array( $kind, array( 'shortcode', 'block' ), true ) ) {
		return new WP_Error( 'tsosi_replace_kind', __( 'Invalid replace type.', 'tso-stack-inspector' ) );
	}
	if ( 'shortcode' === $kind ) {
		$from = tsosi_sanitize_shortcode_tag( $from );
		$to   = tsosi_sanitize_shortcode_tag( $to );
	} else {
		$from = tsosi_sanitize_block_name( $from );
		$to   = tsosi_sanitize_block_name( $to );
	}
	if ( '' === $from || '' === $to ) {
		return new WP_Error( 'tsosi_replace_empty', __( 'Enter both find and replace values.', 'tso-stack-inspector' ) );
	}
	if ( $from === $to ) {
		return new WP_Error( 'tsosi_replace_same', __( 'Find and replace values are the same.', 'tso-stack-inspector' ) );
	}

	$candidates = tsosi_replace_find_candidate_ids( $kind, $from, 500 );
	$items      = array();
	foreach ( $candidates as $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			continue;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			continue;
		}
		if ( ! tsosi_replace_content_would_change( (string) $post->post_content, $kind, $from, $to ) ) {
			continue;
		}
		$items[] = array(
			'id'    => $post_id,
			'title' => get_the_title( $post ) ? get_the_title( $post ) : '(no title)',
			'type'  => $post->post_type,
			'edit'  => get_edit_post_link( $post_id, 'raw' ),
		);
		if ( count( $items ) >= 200 ) {
			break;
		}
	}

	return array(
		'kind'     => $kind,
		'from'     => $from,
		'to'       => $to,
		'count'    => count( $items ),
		'capped'   => count( $candidates ) >= 500 || count( $items ) >= 200,
		'items'    => $items,
		'post_ids' => wp_list_pluck( $items, 'id' ),
	);
}

/**
 * Apply replacement to a list of post IDs (batch).
 *
 * @param string $kind     shortcode|block.
 * @param string $from     From.
 * @param string $to       To.
 * @param int[]  $post_ids IDs.
 * @return array<string,mixed>|WP_Error
 */
function tsosi_replace_apply( $kind, $from, $to, $post_ids, $backup_id = '' ) {
	$preview = tsosi_replace_preview( $kind, $from, $to );
	if ( is_wp_error( $preview ) ) {
		return $preview;
	}

	$allowed  = array_map( 'absint', (array) ( $preview['post_ids'] ?? array() ) );
	$allowed  = array_fill_keys( $allowed, true );
	$post_ids = array_values( array_filter( array_map( 'absint', (array) $post_ids ) ) );
	$post_ids = array_slice( $post_ids, 0, TSOSI_REPLACE_BATCH );

	$updated      = 0;
	$skipped      = 0;
	$errors       = 0;
	$backup_posts = array();

	foreach ( $post_ids as $post_id ) {
		if ( empty( $allowed[ $post_id ] ) ) {
			++$skipped;
			continue;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post || ! current_user_can( 'edit_post', $post_id ) ) {
			++$skipped;
			continue;
		}
		$old = (string) $post->post_content;
		$new = tsosi_replace_apply_to_content( $old, $kind, $preview['from'], $preview['to'] );
		if ( $new === $old ) {
			++$skipped;
			continue;
		}
		$backup_posts[ (string) $post_id ] = $old;
		$result                            = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $new,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			unset( $backup_posts[ (string) $post_id ] );
			++$errors;
			continue;
		}
		++$updated;
	}

	$backup_id = sanitize_text_field( (string) $backup_id );
	if ( ! empty( $backup_posts ) ) {
		if ( '' !== $backup_id ) {
			$merged = tsosi_replace_backup_append( $backup_id, $backup_posts );
			if ( '' !== $merged ) {
				$backup_id = $merged;
			}
		} else {
			$backup_id = tsosi_replace_backup_save(
				array(
					'kind'  => $preview['kind'],
					'from'  => $preview['from'],
					'to'    => $preview['to'],
					'posts' => $backup_posts,
				)
			);
		}
	}

	tsosi_flush_all_content_caches();

	return array(
		'updated'   => $updated,
		'skipped'   => $skipped,
		'errors'    => $errors,
		'kind'      => $preview['kind'],
		'from'      => $preview['from'],
		'to'        => $preview['to'],
		'backup_id' => $backup_id,
	);
}

/**
 * @return array<int,array<string,mixed>>
 */
function tsosi_replace_backup_get_index() {
	tsosi_replace_backup_prune_expired();
	$index = get_option( TSOSI_OPTION_REPLACE_BACKUPS, array() );
	return is_array( $index ) ? $index : array();
}

/**
 * Remove expired replace backups (files + index).
 *
 * Guards its own read-modify-write with the same 'replace_backups_index' lock used by
 * tsosi_replace_backup_save()/tsosi_replace_backup_append(), so a prune running here can't
 * race their writes and silently drop or resurrect an entry. Callers that already hold that
 * lock (save()/append()) must not call this indirectly via tsosi_replace_backup_get_index() --
 * they read the option directly instead, to avoid a self-deadlock on a non-reentrant flock().
 *
 * @return void
 */
function tsosi_replace_backup_prune_expired() {
	$lock  = tsosi_acquire_lock( 'replace_backups_index' );
	$index = get_option( TSOSI_OPTION_REPLACE_BACKUPS, array() );
	if ( ! is_array( $index ) || empty( $index ) ) {
		tsosi_release_lock( $lock );
		return;
	}
	$now  = time();
	$kept = array();
	foreach ( $index as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
			continue;
		}
		$expires = isset( $entry['expires_at'] ) ? (int) $entry['expires_at'] : 0;
		if ( $expires > 0 && $expires < $now ) {
			if ( ! empty( $entry['file'] ) ) {
				$path = tsosi_scan_cache_file_path( (string) $entry['file'] );
				if ( '' !== $path && is_file( $path ) ) {
					wp_delete_file( $path );
				}
			}
			continue;
		}
		$kept[] = $entry;
	}
	if ( count( $kept ) !== count( $index ) ) {
		update_option( TSOSI_OPTION_REPLACE_BACKUPS, array_values( $kept ), false );
	}
	tsosi_release_lock( $lock );
}

/**
 * Save a replace backup snapshot under uploads.
 *
 * @param array{kind:string,from:string,to:string,posts:array<string,string>} $payload Backup payload.
 * @return string Backup id or empty.
 */
function tsosi_replace_backup_save( $payload ) {
	if ( ! tsosi_scan_content_cache_storage_ready() || ! is_array( $payload ) || empty( $payload['posts'] ) ) {
		return '';
	}
	$id       = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
	$filename = 'replace-backup-' . tsosi_scan_cache_user_suffix() . '-' . sanitize_file_name( $id ) . '.json';
	$path     = tsosi_scan_cache_file_path( $filename );
	if ( '' === $path ) {
		return '';
	}
	$data = array(
		'id'         => $id,
		'file'       => $filename,
		'saved_at'   => time(),
		'expires_at' => time() + TSOSI_REPLACE_BACKUP_TTL,
		'user_id'    => get_current_user_id(),
		'kind'       => sanitize_key( (string) ( $payload['kind'] ?? '' ) ),
		'from'       => (string) ( $payload['from'] ?? '' ),
		'to'         => (string) ( $payload['to'] ?? '' ),
		'posts'      => $payload['posts'],
	);
	if ( ! tsosi_scan_cache_write_json_file( $path, $data ) ) {
		return '';
	}

	// Guard the shared backups index against a lost update if another request
	// (a second "Apply" or a concurrent append) writes the same option at the same time.
	// Read the option directly (not via tsosi_replace_backup_get_index()): that helper
	// prunes expired entries under this same lock, and re-acquiring a held flock() from
	// the same process would deadlock.
	$index_lock = tsosi_acquire_lock( 'replace_backups_index' );
	$index      = get_option( TSOSI_OPTION_REPLACE_BACKUPS, array() );
	$index      = is_array( $index ) ? $index : array();
	$index[]    = array(
		'id'         => $id,
		'file'       => $filename,
		'saved_at'   => $data['saved_at'],
		'expires_at' => $data['expires_at'],
		'kind'       => $data['kind'],
		'from'       => $data['from'],
		'to'         => $data['to'],
		'count'      => count( $payload['posts'] ),
	);
	// Keep last 10 backups in the index.
	if ( count( $index ) > 10 ) {
		$drop = array_slice( $index, 0, count( $index ) - 10 );
		foreach ( $drop as $old ) {
			if ( is_array( $old ) && ! empty( $old['file'] ) ) {
				$old_path = tsosi_scan_cache_file_path( (string) $old['file'] );
				if ( '' !== $old_path && is_file( $old_path ) ) {
					wp_delete_file( $old_path );
				}
			}
		}
		$index = array_slice( $index, -10 );
	}
	update_option( TSOSI_OPTION_REPLACE_BACKUPS, array_values( $index ), false );
	tsosi_release_lock( $index_lock );
	return $id;
}

/**
 * Append posts to an existing replace backup.
 *
 * @param string               $id    Backup id.
 * @param array<string,string> $posts Map post_id => content.
 * @return string Backup id or empty on failure.
 */
function tsosi_replace_backup_append( $id, $posts ) {
	// Guard this backup's own file against a lost update if two "Apply" batches for
	// the same backup id race each other (e.g. a manual retry while the first is
	// still in flight) -- without this, the second read-modify-write can silently
	// drop posts the first request had just appended, leaving "Undo" incomplete.
	$file_lock = tsosi_acquire_lock( 'replace_backup_' . sanitize_key( (string) $id ) );

	$data = tsosi_replace_backup_load( $id );
	if ( ! is_array( $data ) || empty( $data['file'] ) ) {
		tsosi_release_lock( $file_lock );
		return tsosi_replace_backup_save(
			array(
				'kind'  => '',
				'from'  => '',
				'to'    => '',
				'posts' => $posts,
			)
		);
	}
	$existing = isset( $data['posts'] ) && is_array( $data['posts'] ) ? $data['posts'] : array();
	foreach ( $posts as $pid => $content ) {
		if ( ! isset( $existing[ (string) $pid ] ) ) {
			$existing[ (string) $pid ] = $content;
		}
	}
	$data['posts'] = $existing;
	$path          = tsosi_scan_cache_file_path( (string) $data['file'] );
	if ( '' === $path || ! tsosi_scan_cache_write_json_file( $path, $data ) ) {
		tsosi_release_lock( $file_lock );
		return '';
	}
	tsosi_release_lock( $file_lock );

	$index_lock = tsosi_acquire_lock( 'replace_backups_index' );
	$index      = get_option( TSOSI_OPTION_REPLACE_BACKUPS, array() );
	$index      = is_array( $index ) ? $index : array();
	foreach ( $index as &$entry ) {
		if ( is_array( $entry ) && isset( $entry['id'] ) && (string) $entry['id'] === (string) $id ) {
			$entry['count'] = count( $existing );
		}
	}
	unset( $entry );
	update_option( TSOSI_OPTION_REPLACE_BACKUPS, array_values( $index ), false );
	tsosi_release_lock( $index_lock );
	return (string) $id;
}

/**
 * Load a replace backup payload.
 *
 * @param string $id Backup id.
 * @return array<string,mixed>|null
 */
function tsosi_replace_backup_load( $id ) {
	$id = sanitize_text_field( (string) $id );
	if ( '' === $id ) {
		return null;
	}
	foreach ( tsosi_replace_backup_get_index() as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['id'] ) || (string) $entry['id'] !== $id ) {
			continue;
		}
		if ( empty( $entry['file'] ) ) {
			return null;
		}
		$path = tsosi_scan_cache_file_path( (string) $entry['file'] );
		$data = tsosi_scan_cache_read_json_file( $path );
		return is_array( $data ) ? $data : null;
	}
	return null;
}

/**
 * Restore posts from a replace backup.
 *
 * @param string $id Backup id.
 * @return array<string,mixed>|WP_Error
 */
function tsosi_replace_backup_undo( $id ) {
	$data = tsosi_replace_backup_load( $id );
	if ( ! is_array( $data ) || empty( $data['posts'] ) || ! is_array( $data['posts'] ) ) {
		return new WP_Error(
			'tsosi_replace_undo',
			tsosi_ui_triple_text(
				'Backup not found or expired.',
				'Copia de seguridad no encontrada o caducada.',
				'Còpia de seguretat no trobada o caducada.'
			)
		);
	}

	$restored = 0;
	$skipped  = 0;
	$errors   = 0;
	foreach ( $data['posts'] as $post_id => $content ) {
		$post_id = absint( $post_id );
		if ( $post_id <= 0 || ! is_string( $content ) ) {
			++$skipped;
			continue;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			++$skipped;
			continue;
		}
		$post = get_post( $post_id );
		if ( ! $post instanceof WP_Post ) {
			++$skipped;
			continue;
		}
		$result = wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => $content,
			),
			true
		);
		if ( is_wp_error( $result ) ) {
			++$errors;
			continue;
		}
		++$restored;
	}

	tsosi_flush_all_content_caches();

	return array(
		'restored'  => $restored,
		'skipped'   => $skipped,
		'errors'    => $errors,
		'backup_id' => (string) $id,
	);
}
