<?php
/**
 * Extended scan targets: user/term/comment meta, theme profile, builder meta keys.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Page-builder and layout meta keys / prefixes to inspect for embedded shortcodes and blocks.
 *
 * @return string[]
 */
function tsosi_get_builder_meta_keys() {
	return array(
		'_elementor_data',
		'_fl_builder_data',
		'_fl_builder_draft',
		'_et_pb_old_content',
		'_et_pb_use_builder',
		'_wpb_shortcodes_custom_css',
		'ct_builder_shortcodes',
		'_oxygen_css',
	);
}

/**
 * Meta key prefixes scanned as serialized builder blobs on posts.
 *
 * @return string[]
 */
function tsosi_get_builder_meta_prefixes() {
	return array(
		'_bricks_',
		'_oxygen_',
		'_et_pb_',
		'_fl_builder_',
	);
}

/**
 * Whether a post meta key is a known builder storage key.
 *
 * @param string $meta_key Meta key.
 * @return bool
 */
function tsosi_is_builder_meta_key( $meta_key ) {
	$meta_key = sanitize_key( (string) $meta_key );
	if ( '' === $meta_key ) {
		return false;
	}
	if ( in_array( $meta_key, tsosi_get_builder_meta_keys(), true ) ) {
		return true;
	}
	foreach ( tsosi_get_builder_meta_prefixes() as $prefix ) {
		if ( 0 === strpos( $meta_key, $prefix ) ) {
			return true;
		}
	}
	return false;
}

/**
 * Collect extra builder blobs for a post beyond the fixed key list.
 *
 * @param int $post_id Post ID.
 * @return string[]
 */
function tsosi_scan_collect_builder_blobs( $post_id ) {
	$post_id = absint( $post_id );
	$blobs   = array();

	foreach ( tsosi_get_builder_meta_keys() as $meta_key ) {
		$value = get_post_meta( $post_id, $meta_key, true );
		if ( is_string( $value ) && '' !== $value && strlen( $value ) <= 500000 ) {
			$blobs[] = $value;
		}
	}

	$custom_keys = get_post_custom_keys( $post_id );
	if ( ! is_array( $custom_keys ) ) {
		return $blobs;
	}

	foreach ( $custom_keys as $meta_key ) {
		$clean = sanitize_key( (string) $meta_key );
		if ( '' === $clean || in_array( $clean, tsosi_get_builder_meta_keys(), true ) ) {
			continue;
		}
		$matched = false;
		foreach ( tsosi_get_builder_meta_prefixes() as $prefix ) {
			if ( 0 === strpos( $clean, $prefix ) ) {
				$matched = true;
				break;
			}
		}
		if ( ! $matched ) {
			continue;
		}
		$value = get_post_meta( $post_id, $clean, true );
		if ( is_string( $value ) && '' !== $value && strlen( $value ) <= 500000 ) {
			$blobs[] = $value;
		}
	}

	return $blobs;
}

/**
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_user_meta( $needles ) {
	$prefixes = isset( $needles['meta_prefixes'] ) ? (array) $needles['meta_prefixes'] : array();
	$keys     = isset( $needles['meta_keys'] ) ? (array) $needles['meta_keys'] : array();
	if ( empty( $prefixes ) && empty( $keys ) ) {
		return array();
	}

	global $wpdb;
	$found = array();
	$ctx   = tsosi_ui_triple_text( 'User meta', 'Meta de usuario', 'Meta d\'usuari' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin scan of usermeta keys.
	$rows = $wpdb->get_results(
		"SELECT user_id, meta_key FROM {$wpdb->usermeta} ORDER BY umeta_id ASC LIMIT 50000",
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return array();
	}

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$meta_key = sanitize_key( (string) ( $row['meta_key'] ?? '' ) );
		$user_id  = absint( $row['user_id'] ?? 0 );
		if ( '' === $meta_key || $user_id <= 0 ) {
			continue;
		}
		$hit = in_array( $meta_key, array_map( 'sanitize_key', $keys ), true );
		if ( ! $hit ) {
			foreach ( $prefixes as $prefix ) {
				if ( tsosi_scan_meta_key_matches_prefix( $meta_key, (string) $prefix ) ) {
					$hit = true;
					break;
				}
			}
		}
		if ( ! $hit ) {
			continue;
		}
		$user    = get_userdata( $user_id );
		$label   = $user instanceof WP_User ? $user->user_login : (string) $user_id;
		$found[] = array(
			'source_type'    => 'user_meta',
			'object_id'      => $user_id,
			'object_label'   => $label,
			'object_subtype' => 'user',
			'match_type'     => 'meta',
			'match_value'    => $meta_key,
			'context'        => $ctx,
			'edit_url'       => admin_url( 'user-edit.php?user_id=' . $user_id ),
		);
	}

	return tsosi_scan_dedupe_results( $found );
}

/**
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_term_meta( $needles ) {
	$prefixes = isset( $needles['meta_prefixes'] ) ? (array) $needles['meta_prefixes'] : array();
	$keys     = isset( $needles['meta_keys'] ) ? (array) $needles['meta_keys'] : array();
	if ( empty( $prefixes ) && empty( $keys ) ) {
		return array();
	}

	global $wpdb;
	if ( ! isset( $wpdb->termmeta ) ) {
		return array();
	}

	$found = array();
	$ctx   = tsosi_ui_triple_text( 'Term meta', 'Meta de término', 'Meta de terme' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin scan.
	$rows = $wpdb->get_results(
		"SELECT term_id, meta_key FROM {$wpdb->termmeta} ORDER BY meta_id ASC LIMIT 50000",
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return array();
	}

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$meta_key = sanitize_key( (string) ( $row['meta_key'] ?? '' ) );
		$term_id  = absint( $row['term_id'] ?? 0 );
		if ( '' === $meta_key || $term_id <= 0 ) {
			continue;
		}
		$hit = in_array( $meta_key, array_map( 'sanitize_key', $keys ), true );
		if ( ! $hit ) {
			foreach ( $prefixes as $prefix ) {
				if ( tsosi_scan_meta_key_matches_prefix( $meta_key, (string) $prefix ) ) {
					$hit = true;
					break;
				}
			}
		}
		if ( ! $hit ) {
			continue;
		}
		$term    = get_term( $term_id );
		$label   = ( $term instanceof WP_Term && ! is_wp_error( $term ) ) ? $term->name : (string) $term_id;
		$edit    = ( $term instanceof WP_Term && ! is_wp_error( $term ) )
			? get_edit_term_link( $term, $term->taxonomy )
			: '';
		$found[] = array(
			'source_type'    => 'term_meta',
			'object_id'      => $term_id,
			'object_label'   => $label,
			'object_subtype' => ( $term instanceof WP_Term && ! is_wp_error( $term ) ) ? sanitize_key( $term->taxonomy ) : '',
			'match_type'     => 'meta',
			'match_value'    => $meta_key,
			'context'        => $ctx,
			'edit_url'       => is_string( $edit ) ? $edit : '',
		);
	}

	return tsosi_scan_dedupe_results( $found );
}

/**
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_comment_meta( $needles ) {
	$prefixes = isset( $needles['meta_prefixes'] ) ? (array) $needles['meta_prefixes'] : array();
	$keys     = isset( $needles['meta_keys'] ) ? (array) $needles['meta_keys'] : array();
	if ( empty( $prefixes ) && empty( $keys ) ) {
		return array();
	}

	global $wpdb;
	if ( ! isset( $wpdb->commentmeta ) ) {
		return array();
	}

	$found = array();
	$ctx   = tsosi_ui_triple_text( 'Comment meta', 'Meta de comentario', 'Meta de comentari' );

	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- bounded admin scan.
	$rows = $wpdb->get_results(
		"SELECT comment_id, meta_key FROM {$wpdb->commentmeta} ORDER BY meta_id ASC LIMIT 50000",
		ARRAY_A
	);
	if ( ! is_array( $rows ) ) {
		return array();
	}

	foreach ( $rows as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$meta_key   = sanitize_key( (string) ( $row['meta_key'] ?? '' ) );
		$comment_id = absint( $row['comment_id'] ?? 0 );
		if ( '' === $meta_key || $comment_id <= 0 ) {
			continue;
		}
		$hit = in_array( $meta_key, array_map( 'sanitize_key', $keys ), true );
		if ( ! $hit ) {
			foreach ( $prefixes as $prefix ) {
				if ( tsosi_scan_meta_key_matches_prefix( $meta_key, (string) $prefix ) ) {
					$hit = true;
					break;
				}
			}
		}
		if ( ! $hit ) {
			continue;
		}
		$comment = get_comment( $comment_id );
		$label   = $comment instanceof WP_Comment ? wp_trim_words( (string) $comment->comment_content, 8, '…' ) : (string) $comment_id;
		$found[] = array(
			'source_type'    => 'comment_meta',
			'object_id'      => $comment_id,
			'object_label'   => $label,
			'object_subtype' => 'comment',
			'match_type'     => 'meta',
			'match_value'    => $meta_key,
			'context'        => $ctx,
			'edit_url'       => admin_url( 'comment.php?action=editcomment&c=' . $comment_id ),
		);
	}

	return tsosi_scan_dedupe_results( $found );
}

/**
 * Scan theme_mods / customizer options for shortcodes and blocks.
 * Scans installed themes (active and inactive), capped, so leftovers in old
 * Customizer data are found. Not stored in the content index (needle-dependent).
 *
 * @param array<string,mixed> $needles Needles.
 * @return array<int,array<string,mixed>>
 */
function tsosi_scan_theme_mods( $needles ) {
	$stylesheets = array();
	$themes      = wp_get_themes();
	$count       = 0;
	foreach ( $themes as $stylesheet => $theme ) {
		if ( ! $theme instanceof WP_Theme ) {
			continue;
		}
		++$count;
		if ( $count > 30 ) {
			break;
		}
		$stylesheets[] = (string) $stylesheet;
	}

	// Always include any theme_mods_{stylesheet} from needles (e.g. beyond the cap).
	$prefixes = isset( $needles['option_prefixes'] ) && is_array( $needles['option_prefixes'] )
		? $needles['option_prefixes']
		: array();
	foreach ( $prefixes as $prefix ) {
		$prefix = (string) $prefix;
		if ( 0 !== strpos( $prefix, 'theme_mods_' ) ) {
			continue;
		}
		$stylesheet = tsosi_sanitize_theme_stylesheet( substr( $prefix, strlen( 'theme_mods_' ) ) );
		if ( '' !== $stylesheet ) {
			$stylesheets[] = $stylesheet;
		}
	}

	$stylesheets = array_values( array_unique( array_filter( $stylesheets ) ) );
	if ( empty( $stylesheets ) ) {
		return array();
	}

	$found = array();
	$ctx   = tsosi_ui_triple_text( 'Theme mods', 'Ajustes del tema', 'Ajustos del tema' );

	foreach ( $stylesheets as $stylesheet ) {
		$mods = get_option( 'theme_mods_' . $stylesheet );
		if ( ! is_array( $mods ) || empty( $mods ) ) {
			continue;
		}
		$blob = tsosi_scan_value_to_blob( $mods );
		if ( '' === $blob || strlen( $blob ) > 500000 ) {
			continue;
		}
		$theme = wp_get_theme( $stylesheet );
		$name  = $theme->exists() ? (string) $theme->get( 'Name' ) : $stylesheet;
		$found = array_merge(
			$found,
			tsosi_scan_blob_rows(
				$blob,
				$needles,
				$name . ' (' . $stylesheet . ')',
				$ctx,
				admin_url( 'customize.php' ),
				'theme_mod'
			)
		);
	}

	return tsosi_scan_dedupe_results( $found );
}

/**
 * Validate a theme stylesheet slug.
 *
 * @param string $stylesheet Theme stylesheet slug.
 * @return string Sanitized slug or empty if theme does not exist.
 */
function tsosi_sanitize_theme_stylesheet( $stylesheet ) {
	$stylesheet = sanitize_text_field( (string) $stylesheet );
	if ( '' === $stylesheet ) {
		return '';
	}
	$theme = wp_get_theme( $stylesheet );
	return $theme->exists() ? $stylesheet : '';
}

/**
 * Build a theme signature profile (code scan of active theme).
 *
 * @param string $stylesheet Theme stylesheet slug (empty = active).
 * @return array<string,mixed>
 */
function tsosi_build_theme_profile( $stylesheet = '' ) {
	$theme = '' === $stylesheet ? wp_get_theme() : wp_get_theme( $stylesheet );
	if ( ! $theme->exists() ) {
		return array(
			'name'            => '',
			'stylesheet'      => '',
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array(),
		);
	}

	$dir = $theme->get_stylesheet_directory();
	if ( ! is_string( $dir ) || ! is_dir( $dir ) ) {
		return array(
			'name'            => (string) $theme->get( 'Name' ),
			'stylesheet'      => (string) $theme->get_stylesheet(),
			'shortcodes'      => array(),
			'blocks'          => array(),
			'meta_prefixes'   => array(),
			'option_prefixes' => array(),
		);
	}

	$shortcodes = array();
	$blocks     = array();

	// Gather every candidate path first (cheap -- no file reads yet), then sort
	// deterministically and cap, same as tsosi_profile_collect_php_files(). Capping
	// while iterating made the scan depend on filesystem order alone, so a theme
	// with more than TSOSI_PROFILE_MAX_FILES matching files could silently miss
	// add_shortcode()/register_block_type() calls depending only on directory order.
	$candidates = array();
	$iterator   = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $dir, FilesystemIterator::SKIP_DOTS )
	);
	foreach ( $iterator as $file ) {
		if ( ! $file instanceof SplFileInfo || ! $file->isFile() ) {
			continue;
		}
		$path = $file->getPathname();
		if ( ! preg_match( '/\.(php|jsx?|tsx?)$/i', $path ) ) {
			continue;
		}
		if ( $file->getSize() > TSOSI_PROFILE_MAX_FILE_BYTES ) {
			continue;
		}
		$candidates[] = wp_normalize_path( $path );
	}
	$files = tsosi_profile_sort_and_cap_files( $candidates, $dir, TSOSI_PROFILE_MAX_FILES );

	// Blocks registered purely via a block.json manifest (no matching PHP
	// register_block_type() call -- the modern, recommended pattern) would
	// otherwise never surface. Mirrors tsosi_discover_plugin_blocks().
	foreach ( tsosi_profile_collect_block_json_files( $dir ) as $json_path ) {
		$name = tsosi_profile_block_name_from_json_path( $json_path );
		if ( '' !== $name ) {
			$blocks[] = tsosi_sanitize_block_name( $name );
		}
	}

	foreach ( $files as $path ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- theme code scan.
		$source = file_get_contents( $path );
		if ( ! is_string( $source ) || '' === $source ) {
			continue;
		}
		// Strip comments/docblocks before scanning -- see tsosi_profile_strip_php_comments()
		// in tsosi-plugin-profile.php. Without this, an *example* tag/block name written in
		// a theme file's own comment (e.g. documenting a filter, or a code sample in a
		// starter theme's readme-in-PHP header) is indistinguishable from a real
		// add_shortcode()/register_block_type() call and gets attributed to the theme. A
		// no-op on .js/.jsx/.tsx sources (no "<?php" tag, so PHP's tokenizer treats the
		// whole file as one inline-HTML token and returns it unchanged).
		$source = tsosi_profile_strip_php_comments( $source );
		if ( preg_match_all( '/add_shortcode\s*\(\s*[\'"]([^\'"]+)[\'"]/', $source, $m ) ) {
			foreach ( $m[1] as $tag ) {
				$tag = tsosi_sanitize_shortcode_tag( (string) $tag );
				if ( '' !== $tag ) {
					$shortcodes[] = $tag;
				}
			}
		}
		if ( preg_match_all( '/register_block_type\s*\(\s*[\'"]([a-z0-9\/_-]+)[\'"]/i', $source, $m2 ) ) {
			foreach ( $m2[1] as $block ) {
				$block = tsosi_sanitize_block_name( (string) $block );
				if ( '' !== $block ) {
					$blocks[] = $block;
				}
			}
		}
		// register_block_type() given a path (to a directory or its block.json) rather
		// than a literal block name -- resolve it to the real name instead of treating
		// the path string itself as a block name (the previous, broader regex did this).
		if ( preg_match_all( '/register_block_type\s*\(\s*[^;]*[\'"]([^\'"]*block\.json)[\'"]/i', $source, $json_refs ) ) {
			foreach ( $json_refs[1] as $json_hint ) {
				$json_path = tsosi_profile_resolve_block_json_path( $path, $json_hint );
				$name      = tsosi_profile_block_name_from_json_path( $json_path );
				if ( '' !== $name ) {
					$blocks[] = tsosi_sanitize_block_name( $name );
				}
			}
		}
	}

	$slug = sanitize_key( str_replace( '-', '_', (string) $theme->get_stylesheet() ) );

	return array(
		'name'            => (string) $theme->get( 'Name' ),
		'stylesheet'      => (string) $theme->get_stylesheet(),
		'shortcodes'      => array_values( array_unique( $shortcodes ) ),
		'blocks'          => array_values( array_unique( $blocks ) ),
		'meta_prefixes'   => '' !== $slug ? array( $slug ) : array(),
		'option_prefixes' => array( 'theme_mods_' . $theme->get_stylesheet() ),
	);
}

/**
 * Compare signatures of two plugins.
 *
 * @param string $plugin_file_a Plugin A basename.
 * @param string $plugin_file_b Plugin B basename.
 * @return array<string,mixed>
 */
function tsosi_compare_plugin_profiles( $plugin_file_a, $plugin_file_b ) {
	$a = tsosi_build_plugin_profile( $plugin_file_a );
	$b = tsosi_build_plugin_profile( $plugin_file_b );

	$fields = array( 'shortcodes', 'blocks', 'meta_prefixes', 'option_prefixes' );
	$out    = array(
		'plugin_a' => array(
			'file' => $plugin_file_a,
			'name' => isset( $a['name'] ) ? (string) $a['name'] : $plugin_file_a,
		),
		'plugin_b' => array(
			'file' => $plugin_file_b,
			'name' => isset( $b['name'] ) ? (string) $b['name'] : $plugin_file_b,
		),
		'shared'   => array(),
		'only_a'   => array(),
		'only_b'   => array(),
	);

	foreach ( $fields as $field ) {
		$set_a                   = isset( $a[ $field ] ) && is_array( $a[ $field ] ) ? $a[ $field ] : array();
		$set_b                   = isset( $b[ $field ] ) && is_array( $b[ $field ] ) ? $b[ $field ] : array();
		$out['shared'][ $field ] = array_values( array_intersect( $set_a, $set_b ) );
		$out['only_a'][ $field ] = array_values( array_diff( $set_a, $set_b ) );
		$out['only_b'][ $field ] = array_values( array_diff( $set_b, $set_a ) );
	}

	return $out;
}
