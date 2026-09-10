<?php
/**
 * Detect orphan shortcodes/blocks still present in content but not registered.
 *
 * @package TSO_Stack_Inspector
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bracket-text false positives that look exactly like a shortcode tag but are not one on
 * any real site -- most notably "[email&#160;protected]", the literal text Cloudflare's
 * (and other CDN/hosting) email obfuscation leaves in the page/content source as a
 * decoy/fallback for "[email]" + " protected]" while its own JS rewrites it client-side.
 * That single, very common pattern would otherwise show up as an "orphan shortcode" on a
 * large share of real WordPress sites -- see tsosi_orphans_is_plausible_shortcode_tag().
 *
 * @return string[]
 */
function tsosi_orphans_get_known_false_positive_tags() {
	return array( 'email' );
}

/**
 * Whether a parsed tag looks like a real shortcode (not a regex charset fragment).
 *
 * @param string $tag Shortcode tag.
 * @return bool
 */
function tsosi_orphans_is_plausible_shortcode_tag( $tag ) {
	$tag = tsosi_sanitize_shortcode_tag( $tag );
	if ( '' === $tag || strlen( $tag ) < 3 ) {
		return false;
	}
	if ( ! preg_match( '/[a-z]/', $tag ) ) {
		return false;
	}
	// Regex charset fragments often found in serialized options (e.g. rewrite_rules): [a-z], [0-9].
	if ( preg_match( '/^[a-z0-9](-[a-z0-9]+)+$/', $tag ) ) {
		return false;
	}
	if ( in_array( $tag, tsosi_orphans_get_known_false_positive_tags(), true ) ) {
		return false;
	}
	return true;
}

/**
 * Collect unique shortcode tags present in a content blob.
 *
 * @param string $blob Content.
 * @return string[]
 */
function tsosi_orphans_extract_shortcodes_from_blob( $blob ) {
	if ( ! is_string( $blob ) || '' === $blob ) {
		return array();
	}
	// WordPress shortcode shape: [tag], [tag attrs], [tag/], [/tag] — not bare [a-z] regex classes.
	if ( ! preg_match_all( '/\[([a-z][a-z0-9_-]{2,})(?=[\s\]\/])/i', $blob, $matches ) ) {
		return array();
	}
	$out = array();
	foreach ( $matches[1] as $tag ) {
		$clean = tsosi_sanitize_shortcode_tag( (string) $tag );
		if ( tsosi_orphans_is_plausible_shortcode_tag( $clean ) ) {
			$out[ $clean ] = true;
		}
	}
	return array_keys( $out );
}

/**
 * Collect unique block names present in a content blob.
 *
 * @param string $blob Content.
 * @return string[]
 */
function tsosi_orphans_extract_blocks_from_blob( $blob ) {
	if ( ! is_string( $blob ) || '' === $blob ) {
		return array();
	}
	if ( ! preg_match_all( '/<!--\s*wp:([a-z0-9_-]+\/[a-z0-9_\/-]+)/i', $blob, $matches ) ) {
		return array();
	}
	$out = array();
	foreach ( $matches[1] as $name ) {
		$clean = tsosi_sanitize_block_name( (string) $name );
		if ( '' !== $clean && false !== strpos( $clean, '/' ) ) {
			$out[ $clean ] = true;
		}
	}
	return array_keys( $out );
}

/**
 * Map of currently registered shortcode tags.
 *
 * @return array<string,bool>
 */
function tsosi_orphans_registered_shortcode_map() {
	$map = array();
	global $shortcode_tags;
	if ( is_array( $shortcode_tags ) ) {
		foreach ( array_keys( $shortcode_tags ) as $tag ) {
			$clean = tsosi_sanitize_shortcode_tag( (string) $tag );
			if ( '' !== $clean ) {
				$map[ $clean ] = true;
			}
		}
	}
	return $map;
}

/**
 * Map of currently registered block names.
 *
 * @return array<string,bool>
 */
function tsosi_orphans_registered_block_map() {
	$map = array();
	if ( ! class_exists( 'WP_Block_Type_Registry' ) ) {
		return $map;
	}
	$registry = WP_Block_Type_Registry::get_instance();
	$blocks   = $registry->get_all_registered();
	if ( ! is_array( $blocks ) ) {
		return $map;
	}
	foreach ( array_keys( $blocks ) as $block_name ) {
		$clean = tsosi_sanitize_block_name( (string) $block_name );
		if ( '' !== $clean ) {
			$map[ $clean ] = true;
		}
	}
	return $map;
}

/**
 * Harvest tags/blocks from the cached site index.
 *
 * @param array<string,mixed> $index Cached index.
 * @return array{shortcodes:array<string,int>,blocks:array<string,int>,samples:array<string,array<int,array<string,mixed>>>,post_sourced:array<string,array<string,bool>>}
 */
function tsosi_orphans_harvest_from_index( $index ) {
	$shortcodes = array();
	$blocks     = array();
	$samples    = array(
		'shortcodes' => array(),
		'blocks'     => array(),
	);
	// Whether a tag/block was seen in at least one real post (id > 0), as opposed to only
	// ever appearing in a widget or menu row (object id 0). The Replace tab only ever
	// touches post_content (see tsosi_replace_post_types()' docblock) -- a widget/menu-only
	// orphan can never actually be fixed by it, so tsosi_orphans_find() must not offer a
	// "Replace" action that would silently do nothing for that tag.
	$post_sourced = array(
		'shortcodes' => array(),
		'blocks'     => array(),
	);

	$posts = isset( $index['posts'] ) && is_array( $index['posts'] ) ? $index['posts'] : array();
	foreach ( $posts as $source ) {
		if ( ! is_array( $source ) ) {
			continue;
		}
		$post_id = isset( $source['post_id'] ) ? absint( $source['post_id'] ) : 0;
		$blobs   = array();
		if ( ! empty( $source['content'] ) && is_string( $source['content'] ) ) {
			$blobs[] = $source['content'];
		}
		if ( ! empty( $source['builder_blobs'] ) && is_array( $source['builder_blobs'] ) ) {
			foreach ( $source['builder_blobs'] as $bb ) {
				if ( is_string( $bb ) && '' !== $bb ) {
					$blobs[] = $bb;
				} elseif ( is_array( $bb ) && ! empty( $bb['blob'] ) && is_string( $bb['blob'] ) ) {
					$blobs[] = $bb['blob'];
				}
			}
		}
		$label = $post_id > 0 ? get_the_title( $post_id ) : '';
		$edit  = $post_id > 0 ? get_edit_post_link( $post_id, 'raw' ) : '';
		foreach ( $blobs as $blob ) {
			foreach ( tsosi_orphans_extract_shortcodes_from_blob( $blob ) as $tag ) {
				if ( ! isset( $shortcodes[ $tag ] ) ) {
					$shortcodes[ $tag ] = 0;
				}
				++$shortcodes[ $tag ];
				if ( $post_id > 0 ) {
					$post_sourced['shortcodes'][ $tag ] = true;
				}
				if ( ! isset( $samples['shortcodes'][ $tag ] ) ) {
					$samples['shortcodes'][ $tag ] = array();
				}
				if ( count( $samples['shortcodes'][ $tag ] ) < 5 ) {
					$samples['shortcodes'][ $tag ][] = array(
						'id'    => $post_id,
						'title' => $label ? $label : (string) $post_id,
						'edit'  => $edit ? $edit : '',
					);
				}
			}
			foreach ( tsosi_orphans_extract_blocks_from_blob( $blob ) as $block ) {
				if ( ! isset( $blocks[ $block ] ) ) {
					$blocks[ $block ] = 0;
				}
				++$blocks[ $block ];
				if ( $post_id > 0 ) {
					$post_sourced['blocks'][ $block ] = true;
				}
				if ( ! isset( $samples['blocks'][ $block ] ) ) {
					$samples['blocks'][ $block ] = array();
				}
				if ( count( $samples['blocks'][ $block ] ) < 5 ) {
					$samples['blocks'][ $block ][] = array(
						'id'    => $post_id,
						'title' => $label ? $label : (string) $post_id,
						'edit'  => $edit ? $edit : '',
					);
				}
			}
		}
	}

	$extras = isset( $index['extras'] ) && is_array( $index['extras'] ) ? $index['extras'] : array();
	foreach ( $extras as $row ) {
		if ( ! is_array( $row ) ) {
			continue;
		}
		$kind = isset( $row['kind'] ) ? sanitize_key( (string) $row['kind'] ) : '';

		// Orphans = leftover tags in editor content/widgets/menus — skip raw wp_options
		// (rewrite_rules, etc.), which are read-only serialized core/plugin state, not
		// editor content a site owner authored a shortcode/block into.
		if ( 'widget' === $kind ) {
			if ( empty( $row['blob'] ) || ! is_string( $row['blob'] ) ) {
				continue;
			}
			$label = isset( $row['label'] ) ? (string) $row['label'] : 'widget';
			$edit  = isset( $row['edit_url'] ) ? (string) $row['edit_url'] : '';
			$blobs = array( $row['blob'] );
		} elseif ( 'menu' === $kind ) {
			// Menu rows are shaped differently from widget/option rows (see
			// tsosi_scan_extract_menu_source()): the searchable text lives in an
			// 'items' array (one nav-menu item's title + description per entry), not
			// in a single 'blob'. The main "by plugin/shortcode/block" scan already
			// treats nav menus as real scan territory (tsosi_scan_match_menu_source())
			// -- the orphan finder must look at the same content or it silently misses
			// shortcodes/blocks that only live in a menu item (a common pattern for
			// "mega menu" / icon-in-menu-title plugins).
			$items = isset( $row['items'] ) && is_array( $row['items'] ) ? $row['items'] : array();
			$blobs = array_values( array_filter( $items, 'is_string' ) );
			if ( empty( $blobs ) ) {
				continue;
			}
			$label = isset( $row['name'] ) ? (string) $row['name'] : 'menu';
			$edit  = ! empty( $row['term_id'] )
				? admin_url( 'nav-menus.php?action=edit&menu=' . absint( $row['term_id'] ) )
				: '';
		} else {
			continue;
		}

		foreach ( $blobs as $blob ) {
			foreach ( tsosi_orphans_extract_shortcodes_from_blob( $blob ) as $tag ) {
				if ( ! isset( $shortcodes[ $tag ] ) ) {
					$shortcodes[ $tag ] = 0;
				}
				++$shortcodes[ $tag ];
				if ( ! isset( $samples['shortcodes'][ $tag ] ) ) {
					$samples['shortcodes'][ $tag ] = array();
				}
				if ( count( $samples['shortcodes'][ $tag ] ) < 5 ) {
					$samples['shortcodes'][ $tag ][] = array(
						'id'    => 0,
						'title' => $label,
						'edit'  => $edit,
					);
				}
			}
			foreach ( tsosi_orphans_extract_blocks_from_blob( $blob ) as $block ) {
				if ( ! isset( $blocks[ $block ] ) ) {
					$blocks[ $block ] = 0;
				}
				++$blocks[ $block ];
				if ( ! isset( $samples['blocks'][ $block ] ) ) {
					$samples['blocks'][ $block ] = array();
				}
				if ( count( $samples['blocks'][ $block ] ) < 5 ) {
					$samples['blocks'][ $block ][] = array(
						'id'    => 0,
						'title' => $label,
						'edit'  => $edit,
					);
				}
			}
		}
	}

	return array(
		'shortcodes'   => $shortcodes,
		'blocks'       => $blocks,
		'samples'      => $samples,
		'post_sourced' => $post_sourced,
	);
}

/**
 * Admin URL to prefill the Replace tab.
 *
 * @param string $kind shortcode|block.
 * @param string $from Tag or block name.
 * @return string
 */
function tsosi_orphans_replace_url( $kind, $from ) {
	$kind = 'block' === $kind ? 'block' : 'shortcode';
	$from = 'block' === $kind ? tsosi_sanitize_block_name( $from ) : tsosi_sanitize_shortcode_tag( $from );
	if ( '' === $from ) {
		return '';
	}
	return add_query_arg(
		array(
			'page'                         => 'tso-stack-inspector',
			'tab'                          => 'replace',
			TSOSI_ADMIN_QUERY_REPLACE_KIND => $kind,
			TSOSI_ADMIN_QUERY_REPLACE_FROM => $from,
		),
		admin_url( 'tools.php' )
	);
}

/**
 * Run orphan detection against the site index.
 *
 * @return array<string,mixed>|WP_Error
 */
function tsosi_orphans_find() {
	if ( ! tsosi_scan_content_cache_storage_ready() ) {
		return tsosi_scan_index_unavailable_error();
	}

	$index = tsosi_audit_get_ready_index();
	if ( null === $index ) {
		return tsosi_scan_need_index_error();
	}

	$harvest    = tsosi_orphans_harvest_from_index( $index );
	$reg_sc     = tsosi_orphans_registered_shortcode_map();
	$reg_blocks = tsosi_orphans_registered_block_map();

	$orphan_shortcodes = array();
	foreach ( $harvest['shortcodes'] as $tag => $count ) {
		if ( isset( $reg_sc[ $tag ] ) ) {
			continue;
		}
		if ( tsosi_scan_value_is_ignored( (string) $tag ) ) {
			continue;
		}
		// The Replace tab only ever edits post_content -- offering it here for a tag that
		// only ever showed up in a widget/menu row would silently do nothing when clicked
		// (see tsosi_orphans_harvest_from_index()).
		$has_post_source     = ! empty( $harvest['post_sourced']['shortcodes'][ $tag ] );
		$orphan_shortcodes[] = array(
			'tag'         => $tag,
			'count'       => (int) $count,
			'samples'     => isset( $harvest['samples']['shortcodes'][ $tag ] ) ? $harvest['samples']['shortcodes'][ $tag ] : array(),
			'scan_url'    => add_query_arg(
				array(
					'page' => 'tso-stack-inspector',
					'tab'  => 'shortcode',
				),
				admin_url( 'tools.php' )
			),
			'replace_url' => $has_post_source ? tsosi_orphans_replace_url( 'shortcode', (string) $tag ) : '',
		);
	}

	$orphan_blocks = array();
	foreach ( $harvest['blocks'] as $name => $count ) {
		if ( isset( $reg_blocks[ $name ] ) ) {
			continue;
		}
		// Ignore classic core aliases occasionally left as unregistered edge cases without slash handled earlier.
		if ( tsosi_scan_value_is_ignored( (string) $name ) ) {
			continue;
		}
		$has_post_source = ! empty( $harvest['post_sourced']['blocks'][ $name ] );
		$orphan_blocks[] = array(
			'name'        => $name,
			'count'       => (int) $count,
			'samples'     => isset( $harvest['samples']['blocks'][ $name ] ) ? $harvest['samples']['blocks'][ $name ] : array(),
			'scan_url'    => add_query_arg(
				array(
					'page' => 'tso-stack-inspector',
					'tab'  => 'block',
				),
				admin_url( 'tools.php' )
			),
			'replace_url' => $has_post_source ? tsosi_orphans_replace_url( 'block', (string) $name ) : '',
		);
	}

	usort(
		$orphan_shortcodes,
		function ( $a, $b ) {
			return (int) $b['count'] - (int) $a['count'];
		}
	);
	usort(
		$orphan_blocks,
		function ( $a, $b ) {
			return (int) $b['count'] - (int) $a['count'];
		}
	);

	return array(
		'shortcodes' => $orphan_shortcodes,
		'blocks'     => $orphan_blocks,
		'totals'     => array(
			'shortcodes' => count( $orphan_shortcodes ),
			'blocks'     => count( $orphan_blocks ),
		),
	);
}
