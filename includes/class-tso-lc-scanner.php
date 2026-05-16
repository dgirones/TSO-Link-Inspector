<?php
/**
 * Post and comment scanner.
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Scanner
 */
class TSOLIIN_Scanner {

	/** @var TSOLIIN_DB */
	private $db;

	public function __construct( TSOLIIN_DB $db ) {
		$this->db = $db;
	}

	// -------------------------------------------------------------------------
	// Settings helpers
	// -------------------------------------------------------------------------

	/** @return string[] */
	public function get_post_types() {
		$s = get_option( 'tsoliin_settings', array() );
		$t = isset( $s['post_types'] ) && is_array( $s['post_types'] ) ? $s['post_types'] : array( 'post', 'page' );
		$t = array_map( 'sanitize_key', $t );
		return empty( $t ) ? array( 'post', 'page' ) : $t;
	}

	/** @return bool */
	private function opt( $key ) {
		$s = get_option( 'tsoliin_settings', array() );
		return ! empty( $s[ $key ] );
	}

	/** @return bool */
	public function is_scan_comments_enabled() {
		return $this->opt( 'scan_comments' );
	}

	// -------------------------------------------------------------------------
	// Post count helpers
	// -------------------------------------------------------------------------

	/** @return int */
	public function get_total_posts() {
		$q = new WP_Query( array(
			'post_type'      => $this->get_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'fields'         => 'ids',
			'no_found_rows'  => false,
		) );
		return (int) $q->found_posts;
	}

	/**
	 * @param int $page     1-based page.
	 * @param int $per_page Batch size.
	 * @return int[]
	 */
	public function get_post_ids( $page = 1, $per_page = TSOLIIN_BATCH_SIZE ) {
		$q = new WP_Query( array(
			'post_type'      => $this->get_post_types(),
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, absint( $per_page ) ),
			'paged'          => max( 1, absint( $page ) ),
			'fields'         => 'ids',
			'no_found_rows'  => true,
			'orderby'        => 'ID',
			'order'          => 'ASC',
		) );
		return $q->posts ? array_map( 'absint', $q->posts ) : array();
	}

	// -------------------------------------------------------------------------
	// Link extraction
	// -------------------------------------------------------------------------

	/**
	 * Extract links from HTML using DOMDocument.
	 * Compatible with PHP 7.4+ (no mb_convert_encoding needed).
	 *
	 * @param string $html HTML content.
	 * @return array[]
	 */
	public function extract_links( $html ) {
		return $this->dom_extract( (string) $html, 'a', 'href', 'link', 'textContent' );
	}

	/** @return array[] */
	public function extract_images( $html ) {
		return $this->dom_extract( (string) $html, 'img', 'src', 'image', 'alt' );
	}

	/** @return array[] */
	public function extract_iframes( $html ) {
		return $this->dom_extract( (string) $html, 'iframe', 'src', 'iframe', 'title' );
	}

	/**
	 * Generic DOMDocument extractor.
	 *
	 * @param string $html      HTML content.
	 * @param string $tag       Tag name.
	 * @param string $attr      URL attribute.
	 * @param string $type      link_type value.
	 * @param string $text_key  'textContent' or attribute name for anchor text.
	 * @return array[]
	 */
	private function dom_extract( $html, $tag, $attr, $type, $text_key ) {
		if ( '' === trim( $html ) ) {
			return array();
		}
		$wrapped = '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
		$dom     = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$dom->loadHTML( $wrapped, LIBXML_NONET | LIBXML_NOWARNING );
		libxml_clear_errors();

		$items = array();
		foreach ( $dom->getElementsByTagName( $tag ) as $node ) {
			$url = trim( (string) $node->getAttribute( $attr ) );
			if ( $this->skip_url( $url ) ) {
				continue;
			}
			$url = $this->clean_url( $url );
			if ( '' === $url ) {
				continue;
			}
			$anchor = ( 'textContent' === $text_key )
				? sanitize_text_field( wp_strip_all_tags( trim( (string) $node->textContent ) ) )
				: sanitize_text_field( trim( (string) $node->getAttribute( $text_key ) ) );
			$items[] = array( 'url' => $url, 'anchor' => $anchor, 'type' => $type );
		}
		return $this->dedup( $items );
	}

	/**
	 * Regex fallback for link extraction.
	 *
	 * @param string $html HTML string.
	 * @return array[]
	 */
	private function regex_links( $html ) {
		$items = array();
		if ( preg_match_all( '/<a\s[^>]*href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/is', $html, $m ) ) {
			foreach ( $m[1] as $i => $href ) {
				$href = trim( $href );
				if ( $this->skip_url( $href ) ) {
					continue;
				}
				$href = $this->clean_url( $href );
				if ( '' === $href ) {
					continue;
				}
				$items[] = array(
					'url'    => $href,
					'anchor' => sanitize_text_field( wp_strip_all_tags( trim( $m[2][ $i ] ) ) ),
					'type'   => 'link',
				);
			}
		}
		return $this->dedup( $items );
	}

	/**
	 * Sanitize a URL for storage (keep {} and accented chars, remove control chars only).
	 *
	 * @param string $url Raw URL.
	 * @return string
	 */
	private function clean_url( $url ) {
		return trim( str_replace( array( "\0", "\r", "\n" ), '', $url ) );
	}

	/**
	 * Convert root-relative or post-relative hrefs to an absolute URL for HTTP checks.
	 * The original href is kept in the database and in post content.
	 *
	 * @param string $url     URL as stored (may be /path/, ../path, or full https://…).
	 * @param int    $post_id Post the link belongs to (for path-relative resolution).
	 * @return string Absolute http(s) URL, or the original string if it cannot be resolved.
	 */
	public static function resolve_to_absolute_url( $url, $post_id = 0 ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( '' === $url ) {
			return '';
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$home   = wp_parse_url( home_url( '/' ) );
			$scheme = ! empty( $home['scheme'] ) ? $home['scheme'] : ( is_ssl() ? 'https' : 'http' );
			return $scheme . ':' . $url;
		}
		if ( '/' === $url[0] ) {
			$absolute = home_url( $url );
			return is_string( $absolute ) ? $absolute : $url;
		}
		$post_id = absint( $post_id );
		if ( $post_id > 0 ) {
			$permalink = get_permalink( $post_id );
			if ( $permalink ) {
				return self::join_relative_to_base( $permalink, $url );
			}
		}
		$absolute = home_url( '/' . ltrim( $url, '/' ) );
		return is_string( $absolute ) ? $absolute : $url;
	}

	/**
	 * Resolve a relative reference against a base URL (RFC 3986 style, path only).
	 *
	 * @param string $base_url Absolute base URL (usually post permalink).
	 * @param string $relative Relative path (e.g. other-page/, ../sibling/).
	 * @return string
	 */
	private static function join_relative_to_base( $base_url, $relative ) {
		$parts = wp_parse_url( $base_url );
		if ( empty( $parts['host'] ) ) {
			return home_url( '/' . ltrim( $relative, '/' ) );
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = $parts['host'];
		$port   = ! empty( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
		if ( '/' !== substr( $path, -1 ) ) {
			$path = dirname( $path ) . '/';
		}
		$path = self::normalize_path( $path . ltrim( $relative, '/' ) );
		$query = ! empty( $parts['query'] ) ? '?' . $parts['query'] : '';
		return $scheme . '://' . $host . $port . $path . $query;
	}

	/**
	 * Collapse /./ and /../ in a URL path.
	 *
	 * @param string $path URL path starting with /.
	 * @return string
	 */
	private static function normalize_path( $path ) {
		$segments = explode( '/', (string) $path );
		$stack    = array();
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				array_pop( $stack );
				continue;
			}
			$stack[] = $segment;
		}
		return '/' . implode( '/', $stack );
	}

	/**
	 * Possible href spellings in post_content for the same logical link.
	 *
	 * @param string $url     Stored link URL.
	 * @param int    $post_id Post ID.
	 * @return string[]
	 */
	private function url_content_variants( $url, $post_id = 0 ) {
		$url = (string) $url;
		$post_id = absint( $post_id );
		$variants = array( $url );
		$resolved = self::resolve_to_absolute_url( $url, $post_id );
		if ( '' !== $resolved && $resolved !== $url ) {
			$variants[] = $resolved;
		}
		if ( function_exists( 'wp_make_link_relative' ) ) {
			foreach ( array( $url, $resolved ) as $candidate ) {
				if ( '' === $candidate ) {
					continue;
				}
				$rel = wp_make_link_relative( $candidate );
				if ( is_string( $rel ) && '' !== $rel && ! in_array( $rel, $variants, true ) ) {
					$variants[] = $rel;
				}
			}
		}
		return array_values( array_unique( array_filter( $variants ) ) );
	}

	/**
	 * Whether a stored URL still matches one of the URLs found in the latest scan.
	 *
	 * @param string   $stored_url Stored link_url.
	 * @param string[] $found_urls URLs from current scan.
	 * @param int      $post_id    Post ID.
	 * @return bool
	 */
	private function url_still_found( $stored_url, $found_urls, $post_id ) {
		if ( in_array( $stored_url, $found_urls, true ) ) {
			return true;
		}
		$stored_abs = self::resolve_to_absolute_url( $stored_url, $post_id );
		foreach ( $found_urls as $found ) {
			if ( $found === $stored_url ) {
				return true;
			}
			if ( self::resolve_to_absolute_url( $found, $post_id ) === $stored_abs ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $url Raw URL.
	 * @return bool True if should be skipped.
	 */
	private function skip_url( $url ) {
		if ( '' === $url ) {
			return true;
		}
		if ( '#' === $url[0] ) {
			return true;
		}
		$scheme       = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );
		$skip_schemes = array( 'mailto', 'tel', 'javascript', 'data', 'sms', 'ftp', 'ftps', 'skype', 'blob' );
		if ( in_array( $scheme, $skip_schemes, true ) ) {
			return true;
		}
		if ( 0 === strpos( $url, 'data:' ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Remove items with duplicate URLs.
	 *
	 * @param array[] $items
	 * @return array[]
	 */
	/**
	 * Check if a URL matches the ignore list (domains or prefixes).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public function is_ignored( $url ) {
		return TSOLIIN_HTTP::is_ignored_url( $url );
	}

	private function dedup( $items ) {
		$seen = array();
		$out  = array();
		foreach ( $items as $item ) {
			if ( ! in_array( $item['url'], $seen, true ) ) {
				$out[]  = $item;
				$seen[] = $item['url'];
			}
		}
		return $out;
	}

	// -------------------------------------------------------------------------
	// Content rendering
	// -------------------------------------------------------------------------

	/**
	 * Render Gutenberg blocks without firing all the_content hooks.
	 *
	 * @param string $raw Raw post_content.
	 * @return string
	 */
	private function render_content( $raw ) {
		$html = do_blocks( $raw );
		return ( '' === trim( $html ) && '' !== trim( $raw ) ) ? $raw : $html;
	}

	// -------------------------------------------------------------------------
	// Scan
	// -------------------------------------------------------------------------

	/**
	 * Scan a single post and upsert all found links into the DB.
	 * Force-all: when true, scans ALL types (link, image, iframe) regardless of settings.
	 * Used by save_post hook so manual URL changes in the editor are always detected.
	 *
	 * @param int  $post_id   Post ID.
	 * @param bool $force_all Force scan all types (default false = respect settings).
	 * @return int Number of items found.
	 */
	public function scan_post( $post_id, $force_all = false ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			$this->db->delete_links_for_post( $post_id );
			return 0;
		}

		$html  = $this->render_content( $post->post_content );
		$items = array();
		$seen  = array();

		// a href links.
		$links = $this->extract_links( $html );
		if ( empty( $links ) && false !== strpos( $post->post_content, 'href=' ) ) {
			$links = $this->regex_links( $post->post_content );
		}
		foreach ( $links as $item ) {
			$items[] = $item;
			$seen[]  = $item['url'];
		}

		// img src.
		if ( $this->opt( 'scan_images' ) ) {
			foreach ( $this->extract_images( $html ) as $item ) {
				if ( ! in_array( $item['url'], $seen, true ) ) {
					$items[] = $item;
					$seen[]  = $item['url'];
				}
			}
		}

		// iframe src.
		if ( $this->opt( 'scan_iframes' ) ) {
			foreach ( $this->extract_iframes( $html ) as $item ) {
				if ( ! in_array( $item['url'], $seen, true ) ) {
					$items[] = $item;
					$seen[]  = $item['url'];
				}
			}
		}

		// Meta fields (ACF, custom fields).
		if ( $this->opt( 'scan_meta' ) ) {
			foreach ( $this->scan_meta( $post_id ) as $item ) {
				if ( ! in_array( $item['url'], $seen, true ) ) {
					$items[] = $item;
					$seen[]  = $item['url'];
				}
			}
		}

		$found_urls = array();
		foreach ( $items as $item ) {
			if ( $this->is_ignored( $item['url'] ) ) {
				continue;
			}
			$this->db->upsert_link( $post_id, $item['url'], $item['anchor'], isset( $item['type'] ) ? $item['type'] : 'link' );
			$found_urls[] = $item['url'];
		}
		$this->remove_stale( $post_id, $found_urls );
		return count( $items );
	}

	/**
	 * Scan a batch of posts.
	 *
	 * @param int $page     1-based page.
	 * @param int $per_page Batch size.
	 * @return array { scanned: int, found: int, done: bool }
	 */
	public function scan_batch( $page = 1, $per_page = TSOLIIN_BATCH_SIZE ) {
		$ids = $this->get_post_ids( $page, $per_page );
		if ( empty( $ids ) ) {
			return array( 'scanned' => 0, 'found' => 0, 'done' => true );
		}
		$found = 0;
		foreach ( $ids as $id ) {
			$found += $this->scan_post( $id );
		}
		if ( $this->opt( 'scan_comments' ) ) {
			$found += $this->scan_comments_batch( TSOLIIN_BATCH_SIZE * 5 );
		}
		$total = $this->get_total_posts();
		$done  = ( $page * $per_page >= $total ) || ( count( $ids ) < $per_page );
		return array( 'scanned' => count( $ids ), 'found' => $found, 'done' => $done );
	}

	/**
	 * Remove DB rows for URLs no longer in a post.
	 *
	 * @param int      $post_id    Post ID.
	 * @param string[] $found_urls URLs currently found.
	 */
	private function remove_stale( $post_id, $found_urls ) {
		global $wpdb;
		$table = $this->db->get_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$existing = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id, link_url FROM {$table} WHERE post_id = %d AND link_type != 'comment'",
				$post_id
			)
		);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( empty( $existing ) ) {
			return;
		}
		foreach ( $existing as $row ) {
			if ( ! $this->url_still_found( (string) $row->link_url, $found_urls, $post_id ) ) {
				$this->db->delete_link( (int) $row->id );
			}
		}
	}

	// -------------------------------------------------------------------------
	// Meta scan
	// -------------------------------------------------------------------------

	/**
	 * Scan post meta for URLs (ACF, custom fields).
	 *
	 * @param int $post_id Post ID.
	 * @return array[]
	 */
	public function scan_meta( $post_id ) {
		$all_meta = get_post_meta( absint( $post_id ) );
		if ( empty( $all_meta ) ) {
			return array();
		}
		$s        = get_option( 'tsoliin_settings', array() );
		$excluded = isset( $s['meta_exclude_keys'] ) && is_array( $s['meta_exclude_keys'] )
			? array_map( 'sanitize_text_field', $s['meta_exclude_keys'] )
			: $this->default_excluded_meta();

		$out = array();
		foreach ( $all_meta as $key => $values ) {
			$key = (string) $key;
			if ( in_array( $key, $excluded, true ) ) {
				continue;
			}
			if ( isset( $key[0] ) && '_' === $key[0] ) {
				$has = false;
				foreach ( (array) $values as $v ) {
					$val = maybe_unserialize( $v );
					if ( is_string( $val ) && ( false !== strpos( $val, 'href=' ) || filter_var( trim( $val ), FILTER_VALIDATE_URL ) ) ) {
						$has = true;
						break;
					}
				}
				if ( ! $has ) {
					continue;
				}
			}
			foreach ( (array) $values as $v ) {
				$this->extract_meta_value( maybe_unserialize( $v ), $key, $out );
			}
		}
		return $this->dedup( $out );
	}

	/** @param mixed $val */
	private function extract_meta_value( $val, $key, &$out ) {
		if ( is_string( $val ) ) {
			if ( false !== strpos( $val, 'href=' ) ) {
				$found = $this->extract_links( $val );
				if ( empty( $found ) ) {
					$found = $this->regex_links( $val );
				}
				foreach ( $found as $item ) {
					$item['anchor'] = $item['anchor'] ?: sanitize_text_field( $key );
					$out[]          = $item;
				}
			} elseif ( filter_var( trim( $val ), FILTER_VALIDATE_URL ) && ! $this->skip_url( trim( $val ) ) ) {
				$out[] = array( 'url' => $this->clean_url( trim( $val ) ), 'anchor' => sanitize_text_field( $key ), 'type' => 'link' );
			}
		} elseif ( is_array( $val ) ) {
			foreach ( $val as $sub ) {
				$this->extract_meta_value( $sub, $key, $out );
			}
		} elseif ( is_object( $val ) ) {
			foreach ( get_object_vars( $val ) as $sub ) {
				$this->extract_meta_value( $sub, $key, $out );
			}
		}
	}

	/** @return string[] */
	private function default_excluded_meta() {
		return array( '_edit_lock', '_edit_last', '_wp_trash_meta_status', '_wp_trash_meta_time', '_wp_old_slug', '_wp_old_date', '_wp_attachment_metadata', '_wp_attached_file', '_thumbnail_id', '_wp_page_template', '_yoast_wpseo_content_score', '_yoast_wpseo_focuskw', '_yoast_wpseo_metadesc', '_yoast_wpseo_title', '_yoast_wpseo_linkdex', '_rank_math_seo_score', '_rank_math_focus_keyword' );
	}

	// -------------------------------------------------------------------------
	// Comments scan
	// -------------------------------------------------------------------------

	/**
	 * Scan approved comments for links (cursor over comment_ID, independent of post scan pages).
	 *
	 * @param int $per_page Max comments per call.
	 * @return int Items found (0 = reached end of queue; cursor reset for next full cycle).
	 */
	public function scan_comments_batch( $per_page = 50 ) {
		if ( ! $this->opt( 'scan_comments' ) ) {
			return 0;
		}
		global $wpdb;
		$per_page = max( 1, absint( $per_page ) );
		$after_id = absint( get_option( 'tsoliin_comment_scan_after_id', 0 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$comments = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT comment_ID, comment_post_ID, comment_content, comment_author_url FROM {$wpdb->comments} WHERE comment_approved = '1' AND comment_ID > %d AND ( comment_content LIKE %s OR comment_author_url != '' ) ORDER BY comment_ID ASC LIMIT %d",
				$after_id,
				'%href=%',
				$per_page
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $comments ) ) {
			update_option( 'tsoliin_comment_scan_after_id', 0, false );
			return 0;
		}

		$found  = 0;
		$max_id = $after_id;
		foreach ( $comments as $comment ) {
			$cid = absint( $comment->comment_ID );
			$pid = absint( $comment->comment_post_ID );
			if ( $cid ) {
				$max_id = max( $max_id, $cid );
			}
			$allowed_keys = array();

			foreach ( $this->extract_links( (string) $comment->comment_content ) as $item ) {
				$url = isset( $item['url'] ) ? (string) $item['url'] : '';
				if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
					continue;
				}
				/* translators: %d: comment ID */
				$anchor = $item['anchor'] ?: sprintf( __( 'Comment #%d', 'tso-link-inspector' ), $cid );
				$sk = $this->db->sanitize_source_key( 'c-' . $cid . '-l-' . md5( $url ) );
				$allowed_keys[] = $sk;
				$this->db->upsert_link( $pid, $url, $anchor, 'comment', $sk );
				$found++;
			}

			$author_url = trim( (string) $comment->comment_author_url );
			if ( $author_url && ! $this->skip_url( $author_url ) && ! $this->is_ignored( $author_url ) ) {
				$clean = $this->clean_url( $author_url );
				/* translators: %d: comment ID */
				$anchor         = sprintf( __( 'Comment author #%d', 'tso-link-inspector' ), $cid );
				$sk             = $this->db->sanitize_source_key( 'c-' . $cid . '-author' );
				$allowed_keys[] = $sk;
				$this->db->upsert_link( $pid, $clean, $anchor, 'comment', $sk );
				$found++;
			}

			$this->db->delete_comment_sources_not_in( $pid, $cid, $allowed_keys );
		}

		update_option( 'tsoliin_comment_scan_after_id', $max_id, false );
		return $found;
	}

	// -------------------------------------------------------------------------
	// Post content manipulation
	// -------------------------------------------------------------------------

	/**
	 * Replace a URL in post content.
	 * Tries multiple URL encoding variants to handle accented chars, {}, etc.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $old_url Old URL as stored in DB.
	 * @param string $new_url New URL.
	 * @return bool
	 */
	public function replace_url_in_post( $post_id, $old_url, $new_url ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		$old_url = (string) $old_url;
		$new_url = $this->clean_url( (string) $new_url );
		$content = $post->post_content;

		// Build all possible representations of the URL that could appear in post_content.
		// WordPress stores href attributes with & encoded as &amp;, which is the most
		// common cause of "URL not found in post" errors with long Amazon/affiliate URLs.
		$decoded = urldecode( $old_url );
		$candidates = array_unique( array_filter( array_merge(
			$this->url_content_variants( $old_url, $post_id ),
			array(
				$decoded,                                                // fully URL-decoded
				rawurldecode( $old_url ),                                // rawurl decoded
				esc_url_raw( $old_url ),                                 // WP-sanitized
				esc_url_raw( $decoded ),                                 // decode then WP-sanitize
				html_entity_decode( $old_url, ENT_QUOTES, 'UTF-8' ),    // HTML entities
				str_replace( '&', '&amp;', $old_url ),                  // & -> &amp; (WordPress HTML)
				str_replace( '&', '&amp;', $decoded ),                  // decoded + &amp;
				str_replace( '&amp;', '&', $old_url ),                  // &amp; -> & (reverse)
			)
		) ) );

		$found = null;
		foreach ( $candidates as $c ) {
			if ( '' !== $c && false !== strpos( $content, $c ) ) {
				$found = $c;
				break;
			}
		}
		if ( null === $found ) {
			return false;
		}

		$new_content = str_replace( $found, $new_url, $content );
		$r = $this->update_post_content( $post_id, $new_content );
		if ( ! $r ) {
			return false;
		}
		$this->purge_cache( $post_id );
		return true;
	}

	/**
	 * Remove an anchor tag, keeping inner text.
	 *
	 * @param int    $post_id Post ID.
	 * @param string $url     URL to unlink.
	 * @return bool
	 */
	public function unlink_in_post( $post_id, $url ) {
		$post_id = absint( $post_id );
		$post    = get_post( $post_id );
		if ( ! $post ) {
			return false;
		}
		$url      = (string) $url;
		$variants = array_unique( array_filter( array_merge(
			$this->url_content_variants( $url, $post_id ),
			array( urldecode( $url ), rawurldecode( $url ) )
		) ) );
		$content  = $post->post_content;
		$changed  = false;
		foreach ( $variants as $v ) {
			if ( '' === $v ) {
				continue;
			}
			$new = preg_replace( '#<a\s[^>]*href=["\']' . preg_quote( $v, '#' ) . '["\'][^>]*>(.*?)</a>#is', '$1', $content );
			if ( null !== $new && $new !== $content ) {
				$content = $new;
				$changed = true;
				break;
			}
		}
		if ( ! $changed ) {
			return false;
		}
		$r = $this->update_post_content( $post_id, $content );
		if ( ! $r ) {
			return false;
		}
		$this->purge_cache( $post_id );
		return true;
	}

	/**
	 * Remove a link from a comment (content or author URL).
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $url        URL to remove.
	 * @return bool
	 */
	public function unlink_in_comment( $comment_id, $url ) {
		$comment_id = absint( $comment_id );
		$comment    = get_comment( $comment_id );
		if ( ! $comment ) {
			return false;
		}
		$url = (string) $url;

		$content = (string) $comment->comment_content;
		// Match href values the same way as post content / replace helpers (encoding, entities, trailing slash).
		$href_variants = array_unique( array_filter( array(
			$url,
			urldecode( $url ),
			rawurldecode( $url ),
			str_replace( '&', '&amp;', $url ),
			str_replace( '&', '&amp;', urldecode( $url ) ),
			html_entity_decode( $url, ENT_QUOTES, 'UTF-8' ),
			rtrim( $url, '/' ),
			rtrim( urldecode( $url ), '/' ),
			rtrim( rawurldecode( $url ), '/' ),
		) ) );

		$content_changed = false;
		foreach ( $href_variants as $v ) {
			if ( '' === $v ) {
				continue;
			}
			$new = preg_replace( '#<a\s[^>]*href=["\']' . preg_quote( $v, '#' ) . '["\'][^>]*>(.*?)</a>#is', '$1', $content );
			if ( null !== $new && $new !== $content ) {
				$content = $new;
				$content_changed = true;
				break;
			}
		}

		$author         = trim( (string) $comment->comment_author_url );
		$author_matches = ( '' !== $author && $this->comment_author_url_matches_row_url( $author, $url ) );

		if ( ! $content_changed && ! $author_matches ) {
			return false;
		}

		$args = array( 'comment_ID' => $comment_id );
		if ( $content_changed ) {
			$args['comment_content'] = $content;
		}
		if ( $author_matches ) {
			$args['comment_author_url'] = '';
		}

		return false !== wp_update_comment( $args );
	}

	/**
	 * Whether a comment author URL is the same resource as the URL stored in the inspector row.
	 *
	 * Strict equality misses common cases (trailing slash, http vs https, encoding), leaving the
	 * author website set so the next comment scan re-inserts the link.
	 *
	 * @param string $author_url URL from comment_author_url.
	 * @param string $target_url URL from the link inspector row.
	 * @return bool
	 */
	public function comment_author_url_matches_row_url( $author_url, $target_url ) {
		$author_url = trim( (string) $author_url );
		$target_url = trim( (string) $target_url );
		if ( '' === $author_url || '' === $target_url ) {
			return false;
		}
		if ( TSOLIIN_HTTP::urls_equivalent_for_verify_lock( $author_url, $target_url ) ) {
			return true;
		}
		// Same host + path + query ignoring scheme (http/https) for plain site URLs.
		$strip_scheme = static function ( $u ) {
			$p = wp_parse_url( $u );
			if ( empty( $p['host'] ) ) {
				return '';
			}
			$host = strtolower( (string) $p['host'] );
			$path = isset( $p['path'] ) ? $p['path'] : '/';
			$path = '/' === $path ? '/' : rtrim( $path, '/' );
			$query = isset( $p['query'] ) ? '?' . $p['query'] : '';
			$port  = isset( $p['port'] ) ? ':' . (int) $p['port'] : '';
			return $host . $port . $path . $query;
		};
		$a = $strip_scheme( $author_url );
		$b = $strip_scheme( $target_url );
		return '' !== $a && $a === $b;
	}

	// -------------------------------------------------------------------------
	// Cache purge
	// -------------------------------------------------------------------------

	/** @param int $post_id */
	public function purge_cache( $post_id ) {
		$post_id = absint( $post_id );
		do_action( 'litespeed_purge_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- LiteSpeed Cache public API.
		if ( function_exists( 'w3tc_pgcache_flush_post' ) ) {
			w3tc_pgcache_flush_post( $post_id );
		}
		if ( function_exists( 'wp_cache_post_change' ) ) {
			wp_cache_post_change( $post_id );
		}
		if ( function_exists( 'rocket_clean_post' ) ) {
			rocket_clean_post( $post_id );
		}
		if ( function_exists( 'sg_cachepress_purge_cache' ) ) {
			sg_cachepress_purge_cache();
		}
		do_action( 'cache_enabler_clear_page_cache_by_post', $post_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cache Enabler public API.
		do_action( 'cloudflare_purge_by_url', get_permalink( $post_id ) ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Cloudflare (or compat) public API.
		do_action( 'tsoliin_after_post_update', $post_id );
	}

	/**
	 * Replace a URL inside comment content (href in <a> tags).
	 *
	 * @param int    $comment_id Comment ID.
	 * @param string $old_url    Old URL.
	 * @param string $new_url    New URL.
	 * @return bool
	 */
	public function replace_url_in_comment_content( $comment_id, $old_url, $new_url ) {
		$comment_id = absint( $comment_id );
		$comment    = get_comment( $comment_id );
		if ( ! $comment ) {
			return false;
		}

		$old_url  = (string) $old_url;
		$new_url  = $this->clean_url( (string) $new_url );
		$text     = (string) $comment->comment_content;

		$candidates = array_unique( array_filter( array(
			$old_url,
			urldecode( $old_url ),
			rawurldecode( $old_url ),
			str_replace( '&', '&amp;', $old_url ),
			html_entity_decode( $old_url, ENT_QUOTES, 'UTF-8' ),
		) ) );

		$found = null;
		foreach ( $candidates as $c ) {
			if ( '' !== $c && false !== strpos( $text, $c ) ) {
				$found = $c;
				break;
			}
		}
		if ( null === $found ) {
			return false;
		}

		$new_text = str_replace( $found, $new_url, $text );
		return false !== wp_update_comment( array(
			'comment_ID'      => $comment_id,
			'comment_content' => $new_text,
		) );
	}

	/**
	 * Update post_content while preserving post_modified date (optional).
	 * Respects the tsoliin_preserve_dates setting.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $new_content New post_content.
	 * @return bool
	 */
	private function update_post_content( $post_id, $new_content ) {
		$s              = get_option( 'tsoliin_settings', array() );
		$preserve_dates = ! empty( $s['preserve_dates'] );

		if ( $preserve_dates ) {
			// Update directly via wpdb to avoid touching post_modified.
			global $wpdb;
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->update(
				$wpdb->posts,
				array( 'post_content' => $new_content ),
				array( 'ID' => absint( $post_id ) ),
				array( '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $result ) {
				return false;
			}
			// Clear WP object cache for this post.
			clean_post_cache( $post_id );
			return true;
		}

		$r = wp_update_post( array( 'ID' => $post_id, 'post_content' => $new_content ), true );
		return ! is_wp_error( $r );
	}

}
