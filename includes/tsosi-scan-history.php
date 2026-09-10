<?php
/**
 * Scan history stored under plugin uploads (JSON files + index option).
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'TSOSI_OPTION_SCAN_HISTORY' ) ) {
	define( 'TSOSI_OPTION_SCAN_HISTORY', 'tso_stack_inspector_scan_history' );
}

if ( ! defined( 'TSOSI_SCAN_HISTORY_MAX_DEFAULT' ) ) {
	define( 'TSOSI_SCAN_HISTORY_MAX_DEFAULT', 20 );
}

if ( ! defined( 'TSOSI_SCAN_HISTORY_MAX_HARD' ) ) {
	define( 'TSOSI_SCAN_HISTORY_MAX_HARD', 100 );
}

/**
 * Max history entries kept (settings, clamped).
 *
 * @return int
 */
function tsosi_scan_history_get_max() {
	$settings = tsosi_get_scan_settings();
	$max      = isset( $settings['history_max'] ) ? (int) $settings['history_max'] : TSOSI_SCAN_HISTORY_MAX_DEFAULT;
	if ( $max < 1 ) {
		$max = 1;
	}
	if ( $max > TSOSI_SCAN_HISTORY_MAX_HARD ) {
		$max = TSOSI_SCAN_HISTORY_MAX_HARD;
	}
	return $max;
}

/**
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_history_get_index() {
	$index = get_option( TSOSI_OPTION_SCAN_HISTORY, array() );
	return is_array( $index ) ? $index : array();
}

/**
 * Delete one history JSON file if present.
 *
 * @param string $filename Relative cache filename.
 * @return void
 */
function tsosi_scan_history_delete_file( $filename ) {
	$filename = sanitize_file_name( (string) $filename );
	if ( '' === $filename || 0 !== strpos( $filename, 'history-' ) ) {
		return;
	}
	$path = tsosi_scan_cache_file_path( $filename );
	if ( '' !== $path && is_file( $path ) ) {
		wp_delete_file( $path );
	}
}

/**
 * Keep only the newest $max entries; delete dropped JSON files.
 *
 * @param array<int,array<string,mixed>> $index History index (oldest first).
 * @param int                            $max   Max entries to keep.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_history_prune_index( $index, $max = 0 ) {
	if ( ! is_array( $index ) ) {
		$index = array();
	}
	$max = $max > 0 ? (int) $max : tsosi_scan_history_get_max();
	if ( $max < 1 ) {
		$max = 1;
	}

	$count = count( $index );
	if ( $count <= $max ) {
		return array_values( $index );
	}

	$drop = array_slice( $index, 0, $count - $max );
	foreach ( $drop as $entry ) {
		if ( is_array( $entry ) && ! empty( $entry['file'] ) ) {
			tsosi_scan_history_delete_file( (string) $entry['file'] );
		}
	}

	return array_values( array_slice( $index, $count - $max ) );
}

/**
 * Apply current max limit: prune index + files and persist.
 *
 * @return void
 */
function tsosi_scan_history_enforce_max() {
	$lock  = tsosi_acquire_lock( 'scan_history_index' );
	$index = tsosi_scan_history_prune_index( tsosi_scan_history_get_index() );
	update_option( TSOSI_OPTION_SCAN_HISTORY, $index, false );
	tsosi_release_lock( $lock );
}

/**
 * @param array<string,mixed>            $query   Scan query.
 * @param array<int,array<string,mixed>> $results Results.
 * @param array<string,mixed>            $meta    Summary meta.
 * @return string History entry id or empty on failure.
 */
function tsosi_scan_history_save( $query, $results, $meta ) {
	if ( ! tsosi_scan_content_cache_storage_ready() ) {
		return '';
	}

	$id       = gmdate( 'Ymd-His' ) . '-' . wp_generate_password( 6, false, false );
	$filename = 'history-' . tsosi_scan_cache_user_suffix() . '-' . sanitize_file_name( $id ) . '.json';
	$path     = tsosi_scan_cache_file_path( $filename );
	if ( '' === $path ) {
		return '';
	}

	$payload = array(
		'id'       => $id,
		'saved_at' => time(),
		'user_id'  => get_current_user_id(),
		'blog_id'  => get_current_blog_id(),
		'query'    => is_array( $query ) ? $query : array(),
		'meta'     => is_array( $meta ) ? $meta : array(),
		'results'  => is_array( $results ) ? $results : array(),
	);

	if ( ! tsosi_scan_cache_write_json_file( $path, $payload ) ) {
		return '';
	}

	// Guard the shared history index against a lost update if a background scan finishes
	// while another request deletes/prunes history at the same time.
	$lock    = tsosi_acquire_lock( 'scan_history_index' );
	$index   = tsosi_scan_history_get_index();
	$index[] = array(
		'id'           => $id,
		'file'         => $filename,
		'saved_at'     => time(),
		'result_count' => count( $payload['results'] ),
		'mode'         => isset( $query['mode'] ) ? sanitize_key( (string) $query['mode'] ) : '',
		'label'        => tsosi_scan_history_build_label( $query ),
	);
	$index   = tsosi_scan_history_prune_index( $index );
	update_option( TSOSI_OPTION_SCAN_HISTORY, $index, false );
	tsosi_release_lock( $lock );

	return $id;
}

/**
 * @param array<string,mixed> $query Scan query.
 * @return string
 */
function tsosi_scan_history_build_label( $query ) {
	$mode = isset( $query['mode'] ) ? sanitize_key( (string) $query['mode'] ) : 'plugin';
	if ( 'shortcode' === $mode && ! empty( $query['shortcode'] ) ) {
		return '[ ' . sanitize_text_field( (string) $query['shortcode'] ) . ' ]';
	}
	if ( 'block' === $mode && ! empty( $query['block'] ) ) {
		return (string) $query['block'];
	}
	if ( 'theme' === $mode && ! empty( $query['theme'] ) ) {
		return sanitize_text_field( (string) $query['theme'] );
	}
	if ( 'plugin' === $mode && ! empty( $query['plugin_file'] ) ) {
		$plugins = tsosi_get_installed_plugins();
		$file    = (string) $query['plugin_file'];
		if ( isset( $plugins[ $file ]['Name'] ) ) {
			return (string) $plugins[ $file ]['Name'];
		}
		return $file;
	}
	return $mode;
}

/**
 * @param string $id History entry id.
 * @return array<string,mixed>|null
 */
function tsosi_scan_history_load( $id ) {
	$id = sanitize_text_field( (string) $id );
	if ( '' === $id ) {
		return null;
	}
	foreach ( tsosi_scan_history_get_index() as $entry ) {
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
 * Delete a single history entry (index + file).
 *
 * @param string $id History entry id.
 * @return bool True if removed from index.
 */
function tsosi_scan_history_delete( $id ) {
	$id = sanitize_text_field( (string) $id );
	if ( '' === $id ) {
		return false;
	}

	$lock    = tsosi_acquire_lock( 'scan_history_index' );
	$index   = tsosi_scan_history_get_index();
	$kept    = array();
	$deleted = false;
	foreach ( $index as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
			continue;
		}
		if ( (string) $entry['id'] === $id ) {
			if ( ! empty( $entry['file'] ) ) {
				tsosi_scan_history_delete_file( (string) $entry['file'] );
			}
			$deleted = true;
			continue;
		}
		$kept[] = $entry;
	}

	if ( $deleted ) {
		update_option( TSOSI_OPTION_SCAN_HISTORY, array_values( $kept ), false );
	}
	tsosi_release_lock( $lock );

	return $deleted;
}

/**
 * @return void
 */
function tsosi_scan_history_delete_all() {
	$lock  = tsosi_acquire_lock( 'scan_history_index' );
	$index = tsosi_scan_history_get_index();
	foreach ( $index as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['file'] ) ) {
			continue;
		}
		tsosi_scan_history_delete_file( (string) $entry['file'] );
	}
	delete_option( TSOSI_OPTION_SCAN_HISTORY );
	tsosi_release_lock( $lock );
}

/**
 * Stable key for comparing history result rows.
 *
 * @param array<string,mixed> $row Result row.
 * @return string
 */
function tsosi_scan_history_result_key( $row ) {
	if ( ! is_array( $row ) ) {
		return '';
	}

	$parts = array(
		isset( $row['match_type'] ) ? sanitize_key( (string) $row['match_type'] ) : '',
		isset( $row['match_value'] ) ? (string) $row['match_value'] : '',
		isset( $row['source_type'] ) ? sanitize_key( (string) $row['source_type'] ) : '',
	);

	$object_id = isset( $row['object_id'] ) ? absint( $row['object_id'] ) : 0;
	if ( $object_id > 0 ) {
		// A real object (post/user/term/comment) has a stable numeric id -- use that, not
		// object_label. object_label is a mutable DISPLAY value (e.g. get_the_title(), a
		// comment excerpt) that can change between two scans without the underlying match
		// changing at all; keying on it here made an unrelated post/page rename (or a term
		// rename, etc.) between two scans look like the shortcode/block/meta match was
		// "removed" under the old title and "added" back under the new one.
		$parts[] = (string) $object_id;
	} else {
		// Widgets/options/menus/theme_mods are always recorded with object_id = 0 (see
		// tsosi_scan_blob_rows() / tsosi_scan_match_menu_source()) -- there, the option
		// name / menu name / widget label IS the only available stable identity, so it
		// must stay part of the key.
		$parts[] = isset( $row['object_label'] ) ? (string) $row['object_label'] : '';
	}

	return implode( '|', $parts );
}

/**
 * Diff two history entries (newer vs older).
 *
 * @param string $id_a Newer (or left) history id.
 * @param string $id_b Older (or right) history id.
 * @return array<string,mixed>|WP_Error
 */
function tsosi_scan_history_diff( $id_a, $id_b ) {
	$a = tsosi_scan_history_load( $id_a );
	$b = tsosi_scan_history_load( $id_b );
	if ( ! is_array( $a ) || ! is_array( $b ) ) {
		return new WP_Error(
			'tsosi_history_diff',
			tsosi_ui_triple_text(
				'Could not load one or both history entries.',
				'No se pudieron cargar una o ambas entradas del historial.',
				'No s\'han pogut carregar una o ambdues entrades de l\'historial.'
			)
		);
	}

	$map_a = array();
	$map_b = array();
	foreach ( (array) ( $a['results'] ?? array() ) as $row ) {
		$key = tsosi_scan_history_result_key( $row );
		if ( '' !== $key ) {
			$map_a[ $key ] = $row;
		}
	}
	foreach ( (array) ( $b['results'] ?? array() ) as $row ) {
		$key = tsosi_scan_history_result_key( $row );
		if ( '' !== $key ) {
			$map_b[ $key ] = $row;
		}
	}

	$added   = array();
	$removed = array();
	foreach ( $map_a as $key => $row ) {
		if ( ! isset( $map_b[ $key ] ) ) {
			$added[] = $row;
		}
	}
	foreach ( $map_b as $key => $row ) {
		if ( ! isset( $map_a[ $key ] ) ) {
			$removed[] = $row;
		}
	}

	$label_a = '';
	$label_b = '';
	foreach ( tsosi_scan_history_get_index() as $entry ) {
		if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
			continue;
		}
		if ( (string) $entry['id'] === (string) $id_a ) {
			$label_a = (string) ( $entry['label'] ?? $id_a );
		}
		if ( (string) $entry['id'] === (string) $id_b ) {
			$label_b = (string) ( $entry['label'] ?? $id_b );
		}
	}

	return array(
		'a'       => array(
			'id'    => (string) $id_a,
			'label' => $label_a,
			'count' => count( $map_a ),
		),
		'b'       => array(
			'id'    => (string) $id_b,
			'label' => $label_b,
			'count' => count( $map_b ),
		),
		'added'   => array_values( $added ),
		'removed' => array_values( $removed ),
		'summary' => array(
			'added'   => count( $added ),
			'removed' => count( $removed ),
			'same'    => count( $map_a ) - count( $added ),
		),
	);
}
