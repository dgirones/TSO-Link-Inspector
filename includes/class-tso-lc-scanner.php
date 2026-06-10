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
	private function opt( $key, $default = false ) {
		$s = get_option( 'tsoliin_settings', array() );
		if ( ! array_key_exists( $key, $s ) ) {
			return (bool) $default;
		}
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
	 * Extract bare http(s) URLs from plain text (common in comment_content without <a> tags).
	 *
	 * @param string $text Comment or other plain/HTML text.
	 * @return array[]
	 */
	private function extract_plain_urls( $text ) {
		$text = (string) $text;
		if ( '' === trim( $text ) ) {
			return array();
		}
		$items = array();
		if ( preg_match_all( '#https?://[^\s<>"\'\)\]\}]+#i', $text, $matches ) ) {
			foreach ( $matches[0] as $raw ) {
				$url = $this->clean_url( rtrim( (string) $raw, '.,;:!?)' ) );
				if ( '' === $url || $this->skip_url( $url ) ) {
					continue;
				}
				$items[] = array(
					'url'    => $url,
					'anchor' => '',
					'type'   => 'link',
				);
			}
		}
		return $this->dedup( $items );
	}

	/**
	 * All link targets in comment body: <a href>, regex fallback, and plain-text URLs.
	 *
	 * @param string $content comment_content.
	 * @return array[]
	 */
	private function extract_comment_links( $content ) {
		$content = (string) $content;
		$items   = array();

		foreach ( $this->extract_links( $content ) as $item ) {
			$items[] = $item;
		}
		if ( empty( $items ) && false !== stripos( $content, 'href=' ) ) {
			foreach ( $this->regex_links( $content ) as $item ) {
				$items[] = $item;
			}
		}
		foreach ( $this->extract_plain_urls( $content ) as $item ) {
			$items[] = $item;
		}

		return $this->dedup( $items );
	}

	/**
	 * Extract URLs stored in Gutenberg block attrs (JSON), not always present in rendered HTML.
	 *
	 * @param string $raw Raw post_content.
	 * @return array[]
	 */
	private function extract_block_urls( $raw ) {
		if ( ! function_exists( 'parse_blocks' ) ) {
			return array();
		}
		$raw = (string) $raw;
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$items = array();
		foreach ( $this->collect_block_urls( parse_blocks( $raw ) ) as $url ) {
			$items[] = array(
				'url'    => $url,
				'anchor' => '',
				'type'   => 'link',
			);
		}
		return $this->dedup( $items );
	}

	/**
	 * @param array[] $blocks Parsed blocks from parse_blocks().
	 * @return string[]
	 */
	private function collect_block_urls( $blocks ) {
		$urls = array();
		if ( ! is_array( $blocks ) ) {
			return $urls;
		}
		foreach ( $blocks as $block ) {
			if ( ! is_array( $block ) ) {
				continue;
			}
			if ( ! empty( $block['attrs'] ) && is_array( $block['attrs'] ) ) {
				foreach ( $this->urls_from_array( $block['attrs'] ) as $url ) {
					$urls[] = $url;
				}
			}
			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				foreach ( $this->collect_block_urls( $block['innerBlocks'] ) as $url ) {
					$urls[] = $url;
				}
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Recursively collect URL-like strings from block attrs or nested arrays.
	 *
	 * @param mixed $data   Array or scalar from block attrs.
	 * @param int   $depth  Recursion guard.
	 * @return string[]
	 */
	private function urls_from_array( $data, $depth = 0 ) {
		if ( $depth > 10 || ! is_array( $data ) ) {
			return array();
		}
		$urls     = array();
		$url_keys = array( 'url', 'link', 'href', 'linkurl', 'buttonurl', 'fileurl', 'mediaurl', 'src' );
		foreach ( $data as $key => $value ) {
			if ( is_string( $value ) ) {
				$key_lower = strtolower( (string) $key );
				$looks_url = in_array( $key_lower, $url_keys, true )
					|| (bool) preg_match( '/(?:url|link|href)$/i', $key_lower );
				if ( ! $looks_url ) {
					continue;
				}
				$candidate = $this->clean_url( trim( $value ) );
				if ( '' === $candidate || $this->skip_url( $candidate ) ) {
					continue;
				}
				if ( preg_match( '#^https?://#i', $candidate ) || 0 === strpos( $candidate, '//' ) || '/' === $candidate[0] ) {
					$urls[] = $candidate;
				}
			} elseif ( is_array( $value ) ) {
				foreach ( $this->urls_from_array( $value, $depth + 1 ) as $url ) {
					$urls[] = $url;
				}
			}
		}
		return array_values( array_unique( $urls ) );
	}

	/**
	 * Extract responsive image / media URLs (srcset, picture, video, audio, embed).
	 *
	 * @param string $html Rendered HTML.
	 * @return array[]
	 */
	private function extract_responsive_media_urls( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return array();
		}
		$items = array();

		if ( preg_match_all( '/\ssrcset=["\']([^"\']+)["\']/i', $html, $srcsets ) ) {
			foreach ( $srcsets[1] as $srcset ) {
				foreach ( preg_split( '/\s*,\s*/', (string) $srcset ) as $part ) {
					$part = trim( (string) $part );
					if ( '' === $part ) {
						continue;
					}
					$chunks = preg_split( '/\s+/', $part );
					$url    = $this->clean_url( trim( (string) $chunks[0] ) );
					if ( '' !== $url && ! $this->skip_url( $url ) ) {
						$items[] = array( 'url' => $url, 'anchor' => '', 'type' => 'image' );
					}
				}
			}
		}

		foreach ( array( 'source', 'video', 'audio', 'embed' ) as $tag ) {
			foreach ( $this->dom_extract( $html, $tag, 'src', 'image', 'title' ) as $item ) {
				$items[] = $item;
			}
		}
		foreach ( $this->dom_extract( $html, 'object', 'data', 'link', 'title' ) as $item ) {
			$items[] = $item;
		}

		return $this->dedup( $items );
	}

	/**
	 * Extract URLs from common page-builder data-* attributes.
	 *
	 * @param string $html HTML or block markup.
	 * @return array[]
	 */
	private function extract_data_attr_urls( $html ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return array();
		}
		$data_attrs = array( 'data-url', 'data-href', 'data-link', 'data-button-url', 'data-bg-url' );
		$wrapped      = '<html><head><meta charset="UTF-8"></head><body>' . $html . '</body></html>';
		$dom          = new DOMDocument( '1.0', 'UTF-8' );
		libxml_use_internal_errors( true );
		$dom->loadHTML( $wrapped, LIBXML_NONET | LIBXML_NOWARNING );
		libxml_clear_errors();

		$items = array();
		$walk  = function ( DOMNode $node ) use ( &$walk, $data_attrs, &$items ) {
			if ( XML_ELEMENT_NODE === $node->nodeType ) {
				/** @var DOMElement $node */
				foreach ( $data_attrs as $attr ) {
					if ( ! $node->hasAttribute( $attr ) ) {
						continue;
					}
					$url = $this->clean_url( trim( (string) $node->getAttribute( $attr ) ) );
					if ( '' === $url || $this->skip_url( $url ) ) {
						continue;
					}
					$items[] = array( 'url' => $url, 'anchor' => '', 'type' => 'link' );
				}
			}
			if ( $node->hasChildNodes() ) {
				foreach ( $node->childNodes as $child ) {
					$walk( $child );
				}
			}
		};
		if ( $dom->documentElement ) {
			$walk( $dom->documentElement );
		}
		return $this->dedup( $items );
	}

	/**
	 * Add a scanned item when its URL is not already in the batch.
	 *
	 * @param array[]  $items  Items list (by ref).
	 * @param string[] $seen   Seen URLs (by ref).
	 * @param array    $item   { url, anchor, type }.
	 */
	private function push_scan_item( array &$items, array &$seen, array $item ) {
		$url = isset( $item['url'] ) ? (string) $item['url'] : '';
		if ( '' === $url || in_array( $url, $seen, true ) ) {
			return;
		}
		$items[] = $item;
		$seen[]  = $url;
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
			$this->push_scan_item( $items, $seen, $item );
		}

		// Bare http(s) URLs in post_content (no <a> tag).
		if ( $force_all || $this->opt( 'scan_plain_urls', true ) ) {
			foreach ( $this->extract_plain_urls( $post->post_content ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}

		// Gutenberg block attrs (url/link/href in JSON).
		if ( $force_all || $this->opt( 'scan_block_attrs', true ) ) {
			foreach ( $this->extract_block_urls( $post->post_content ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}

		// img src.
		if ( $force_all || $this->opt( 'scan_images' ) ) {
			foreach ( $this->extract_images( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}

		// srcset, picture, video, audio, embed, object.
		if ( $force_all || $this->opt( 'scan_srcset', true ) ) {
			foreach ( $this->extract_responsive_media_urls( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}

		// iframe src.
		if ( $force_all || $this->opt( 'scan_iframes' ) ) {
			foreach ( $this->extract_iframes( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}

		// Page-builder data-* link attributes.
		if ( $force_all || $this->opt( 'scan_data_attrs', true ) ) {
			foreach ( $this->extract_data_attr_urls( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
			foreach ( $this->extract_data_attr_urls( $post->post_content ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}

		// Meta fields (ACF, custom fields).
		if ( $force_all || $this->opt( 'scan_meta' ) ) {
			foreach ( $this->scan_meta( $post_id ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
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
		if ( $this->opt( 'scan_menus', true ) ) {
			$found += $this->scan_menus_batch( TSOLIIN_BATCH_SIZE * 5 );
		}
		if ( $this->opt( 'scan_widgets', true ) ) {
			$found += $this->scan_widgets_batch( TSOLIIN_BATCH_SIZE * 3 );
		}
		if ( $this->opt( 'scan_terms', true ) ) {
			$found += $this->scan_terms_batch( TSOLIIN_BATCH_SIZE * 5 );
		}
		if ( $this->opt( 'scan_fse', true ) ) {
			$found += $this->scan_fse_batch( TSOLIIN_BATCH_SIZE * 2 );
		}
		if ( class_exists( 'TSOLIIN_Sources' ) ) {
			$found += TSOLIIN_Sources::scan_registered_batch( $this, TSOLIIN_BATCH_SIZE * 2 );
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
				"SELECT id, link_url FROM {$table} WHERE post_id = %d AND link_type NOT IN ('comment', 'menu', 'widget', 'term', 'template', 'wp_block')",
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
			if ( $this->opt( 'scan_meta_plain', true ) && is_string( $val ) ) {
				foreach ( $this->extract_plain_urls( $val ) as $plain ) {
					$plain['anchor'] = sanitize_text_field( $key );
					$out[]           = $plain;
				}
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
				"SELECT comment_ID, comment_post_ID, comment_content, comment_author_url FROM {$wpdb->comments} WHERE comment_approved = '1' AND comment_ID > %d AND ( comment_content LIKE %s OR comment_content LIKE %s OR comment_content LIKE %s OR comment_author_url != '' ) ORDER BY comment_ID ASC LIMIT %d",
				$after_id,
				'%href=%',
				'%http://%',
				'%https://%',
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

			foreach ( $this->extract_comment_links( (string) $comment->comment_content ) as $item ) {
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
	// Navigation menus scan
	// -------------------------------------------------------------------------

	/**
	 * Scan nav menu items for custom/external URLs (cursor over nav_menu_item posts).
	 *
	 * @param int $per_page Max menu items per call.
	 * @return int Items found (0 = reached end of queue; cursor reset for next full cycle).
	 */
	public function scan_menus_batch( $per_page = 50 ) {
		if ( ! $this->opt( 'scan_menus', true ) ) {
			return 0;
		}
		global $wpdb;
		$per_page = max( 1, absint( $per_page ) );
		$after_id = absint( get_option( 'tsoliin_menu_scan_after_id', 0 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts} WHERE post_type = 'nav_menu_item' AND post_status = 'publish' AND ID > %d ORDER BY ID ASC LIMIT %d",
				$after_id,
				$per_page
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $rows ) ) {
			update_option( 'tsoliin_menu_scan_after_id', 0, false );
			return 0;
		}

		$found  = 0;
		$max_id = $after_id;
		foreach ( $rows as $row ) {
			$item_id = absint( $row->ID );
			if ( $item_id ) {
				$max_id = max( $max_id, $item_id );
			}
			$post = get_post( $item_id );
			if ( ! $post ) {
				continue;
			}
			$item = wp_setup_nav_menu_item( $post );
			if ( ! $item || empty( $item->url ) ) {
				$this->db->delete_menu_sources_not_in( $item_id, array() );
				continue;
			}

			$url = $this->clean_url( trim( (string) $item->url ) );
			if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
				$this->db->delete_menu_sources_not_in( $item_id, array() );
				continue;
			}

			$post_id = absint( $item->object_id );
			if ( 'post_type' !== $item->type || ! $post_id ) {
				$post_id = $item_id;
			}

			$anchor = sanitize_text_field( (string) $item->title );
			if ( '' === $anchor ) {
				/* translators: %d: navigation menu item ID */
				$anchor = sprintf( __( 'Menu item #%d', 'tso-link-inspector' ), $item_id );
			}

			$sk             = $this->db->sanitize_source_key( 'mi-' . $item_id );
			$allowed_keys   = array( $sk );
			$this->db->upsert_link( $post_id, $url, $anchor, 'menu', $sk );
			$this->db->delete_menu_sources_not_in( $item_id, $allowed_keys );
			$found++;
		}

		update_option( 'tsoliin_menu_scan_after_id', $max_id, false );
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
	 * Remove an anchor tag, keeping inner text; for images/iframes remove the element.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $url       URL to unlink.
	 * @param string $link_type link|image|iframe.
	 * @return bool
	 */
	public function unlink_in_post( $post_id, $url, $link_type = 'link' ) {
		$post_id   = absint( $post_id );
		$post      = get_post( $post_id );
		$link_type = sanitize_key( (string) $link_type );
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

		if ( 'image' === $link_type ) {
			foreach ( $variants as $v ) {
				if ( '' === $v ) {
					continue;
				}
				$new = preg_replace( '#<img\s[^>]*src=["\']' . preg_quote( $v, '#' ) . '["\'][^>]*/?\s*>#is', '', $content );
				if ( null !== $new && $new !== $content ) {
					$content = $new;
					$changed = true;
					break;
				}
			}
		} elseif ( 'iframe' === $link_type ) {
			foreach ( $variants as $v ) {
				if ( '' === $v ) {
					continue;
				}
				$new = preg_replace( '#<iframe\s[^>]*src=["\']' . preg_quote( $v, '#' ) . '["\'][^>]*>.*?</iframe>#is', '', $content );
				if ( null !== $new && $new !== $content ) {
					$content = $new;
					$changed = true;
					break;
				}
			}
		} else {
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

		// Plain-text URL in comment body (no <a> tag) — remove the URL string.
		if ( ! $content_changed ) {
			foreach ( $href_variants as $v ) {
				if ( '' === $v || false === strpos( $content, $v ) ) {
					continue;
				}
				$new = str_replace( $v, '', $content );
				if ( $new !== $content ) {
					$content = trim( preg_replace( '/\s{2,}/', ' ', $new ) );
					$content_changed = true;
					break;
				}
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

	// -------------------------------------------------------------------------
	// Phase 2: widgets, taxonomies, FSE, third-party sources
	// -------------------------------------------------------------------------

	/**
	 * Extract all link-like items from HTML/text (anchors, plain URLs, blocks, data-*).
	 *
	 * @param string $content Raw HTML or text.
	 * @param string $raw     Optional raw content for block/plain scans.
	 * @return array[]
	 */
	private function extract_all_url_items( $content, $raw = '' ) {
		$content = (string) $content;
		$raw     = '' !== (string) $raw ? (string) $raw : $content;
		$html    = $this->render_content( $content );
		$items   = array();
		$seen    = array();

		foreach ( $this->extract_links( $html ) as $item ) {
			$this->push_scan_item( $items, $seen, $item );
		}
		if ( empty( $items ) && false !== stripos( $raw, 'href=' ) ) {
			foreach ( $this->regex_links( $raw ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}
		if ( $this->opt( 'scan_plain_urls', true ) ) {
			foreach ( $this->extract_plain_urls( $raw ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}
		if ( $this->opt( 'scan_block_attrs', true ) ) {
			foreach ( $this->extract_block_urls( $raw ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}
		if ( $this->opt( 'scan_data_attrs', true ) ) {
			foreach ( $this->extract_data_attr_urls( $html ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
			foreach ( $this->extract_data_attr_urls( $raw ) as $item ) {
				$this->push_scan_item( $items, $seen, $item );
			}
		}

		return $items;
	}

	/**
	 * Upsert a link from an external/third-party scan item.
	 *
	 * @param array  $item      post_id, url, anchor, link_type, source_key.
	 * @param string $source_id Registered source ID (optional prefix validation).
	 * @return bool
	 */
	public function upsert_external_item( array $item, $source_id = '' ) {
		$url = isset( $item['url'] ) ? $this->clean_url( (string) $item['url'] ) : '';
		if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
			return false;
		}
		$post_id    = isset( $item['post_id'] ) ? absint( $item['post_id'] ) : 0;
		$anchor     = isset( $item['anchor'] ) ? sanitize_text_field( (string) $item['anchor'] ) : '';
		$link_type  = isset( $item['link_type'] ) ? sanitize_key( (string) $item['link_type'] ) : 'link';
		$source_key = isset( $item['source_key'] ) ? (string) $item['source_key'] : '';
		if ( '' === $source_key && '' !== (string) $source_id ) {
			$source_key = sanitize_key( (string) $source_id ) . '-' . md5( $url );
		}
		$allowed_types = array( 'link', 'image', 'iframe', 'widget', 'term', 'template', 'wp_block' );
		if ( ! in_array( $link_type, $allowed_types, true ) ) {
			$link_type = 'link';
		}
		return false !== $this->db->upsert_link( $post_id, $url, $anchor, $link_type, $source_key );
	}

	/**
	 * Scan a template / reusable block post (FSE).
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $link_type template|wp_block.
	 * @return int
	 */
	private function scan_storage_post( $post_id, $link_type ) {
		$post_id   = absint( $post_id );
		$link_type = sanitize_key( (string) $link_type );
		if ( ! $post_id || ! in_array( $link_type, array( 'template', 'wp_block' ), true ) ) {
			return 0;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return 0;
		}

		$prefix       = 'st-' . $post_id . '-';
		$allowed_keys = array();
		$found        = 0;

		foreach ( $this->extract_all_url_items( $post->post_content, $post->post_content ) as $item ) {
			$url = isset( $item['url'] ) ? (string) $item['url'] : '';
			if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
				continue;
			}
			$sk             = $this->db->sanitize_source_key( $prefix . md5( $url ) );
			$allowed_keys[] = $sk;
			$anchor         = ! empty( $item['anchor'] ) ? (string) $item['anchor'] : (string) $post->post_title;
			$this->db->upsert_link( $post_id, $url, $anchor, $link_type, $sk );
			$found++;
		}

		$this->db->delete_sources_not_in( $link_type, $prefix, $allowed_keys, $post_id );
		return $found;
	}

	/**
	 * Scan widget sidebars (classic + block widgets).
	 *
	 * @param int $per_page Max widget instances per call.
	 * @return int
	 */
	public function scan_widgets_batch( $per_page = 30 ) {
		if ( ! $this->opt( 'scan_widgets', true ) ) {
			return 0;
		}

		$sidebars = get_option( 'sidebars_widgets', array() );
		if ( ! is_array( $sidebars ) ) {
			return 0;
		}
		/** This filter is documented in wp-includes/widgets.php. */
		// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core widget registration hook.
		$sidebars = apply_filters( 'sidebars_widgets', $sidebars );

		$flat = array();
		foreach ( $sidebars as $sidebar_id => $widgets ) {
			if ( ! is_array( $widgets ) || 'wp_inactive_widgets' === $sidebar_id ) {
				continue;
			}
			foreach ( $widgets as $widget_id ) {
				$flat[] = array( (string) $sidebar_id, (string) $widget_id );
			}
		}

		if ( empty( $flat ) ) {
			update_option( 'tsoliin_widget_scan_after_index', 0, false );
			return 0;
		}

		$per_page    = max( 1, absint( $per_page ) );
		$after_index = absint( get_option( 'tsoliin_widget_scan_after_index', 0 ) );
		$slice       = array_slice( $flat, $after_index, $per_page );
		if ( empty( $slice ) ) {
			update_option( 'tsoliin_widget_scan_after_index', 0, false );
			return 0;
		}

		$found = 0;
		foreach ( $slice as $pair ) {
			$sidebar_id = $pair[0];
			$widget_id  = $pair[1];
			$content    = $this->get_widget_instance_content( $widget_id );
			$prefix     = $this->db->sanitize_source_key( 'wg-' . $sidebar_id . '-' . $widget_id . '-' );
			$allowed    = array();

			if ( '' !== $content ) {
				/* translators: 1: sidebar ID, 2: widget ID */
				$default_anchor = sprintf( __( 'Widget %1$s / %2$s', 'tso-link-inspector' ), $sidebar_id, $widget_id );
				foreach ( $this->extract_all_url_items( $content, $content ) as $item ) {
					$url = isset( $item['url'] ) ? (string) $item['url'] : '';
					if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
						continue;
					}
					$sk        = $this->db->sanitize_source_key( $prefix . md5( $url ) );
					$allowed[] = $sk;
					$anchor    = ! empty( $item['anchor'] ) ? (string) $item['anchor'] : $default_anchor;
					$this->db->upsert_link( 0, $url, $anchor, 'widget', $sk );
					$found++;
				}
			}

			$this->db->delete_sources_not_in( 'widget', $prefix, $allowed, 0 );
		}

		update_option( 'tsoliin_widget_scan_after_index', $after_index + count( $slice ), false );
		return $found;
	}

	/**
	 * @param string $widget_id Widget instance ID (e.g. text-2, block-3).
	 * @return string
	 */
	private function get_widget_instance_content( $widget_id ) {
		if ( ! preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $m ) ) {
			return '';
		}
		$type    = (string) $m[1];
		$number  = absint( $m[2] );
		$option  = get_option( 'widget_' . $type );
		if ( ! is_array( $option ) || empty( $option[ $number ] ) || ! is_array( $option[ $number ] ) ) {
			return '';
		}
		$inst = $option[ $number ];
		if ( 'block' === $type && ! empty( $inst['content'] ) ) {
			return (string) $inst['content'];
		}
		if ( 'text' === $type && ! empty( $inst['text'] ) ) {
			return (string) $inst['text'];
		}
		if ( 'custom_html' === $type && ! empty( $inst['content'] ) ) {
			return (string) $inst['content'];
		}
		foreach ( $inst as $val ) {
			if ( is_string( $val ) && ( false !== stripos( $val, 'http' ) || false !== stripos( $val, 'href=' ) ) ) {
				return $val;
			}
		}
		return '';
	}

	/**
	 * Scan taxonomy term descriptions for links.
	 *
	 * @param int $per_page Max terms per call.
	 * @return int
	 */
	public function scan_terms_batch( $per_page = 50 ) {
		if ( ! $this->opt( 'scan_terms', true ) ) {
			return 0;
		}
		global $wpdb;
		$per_page = max( 1, absint( $per_page ) );
		$after_id = absint( get_option( 'tsoliin_term_scan_after_id', 0 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT t.term_id, tt.taxonomy, t.name, tt.description FROM {$wpdb->terms} t
				INNER JOIN {$wpdb->term_taxonomy} tt ON t.term_id = tt.term_id
				WHERE t.term_id > %d AND tt.description != ''
				AND ( tt.description LIKE %s OR tt.description LIKE %s OR tt.description LIKE %s )
				ORDER BY t.term_id ASC LIMIT %d",
				$after_id,
				'%href=%',
				'%http://%',
				'%https://%',
				$per_page
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $rows ) ) {
			update_option( 'tsoliin_term_scan_after_id', 0, false );
			return 0;
		}

		$found  = 0;
		$max_id = $after_id;
		foreach ( $rows as $row ) {
			$term_id  = absint( $row->term_id );
			$taxonomy = sanitize_key( (string) $row->taxonomy );
			$name     = sanitize_text_field( (string) $row->name );
			if ( $term_id ) {
				$max_id = max( $max_id, $term_id );
			}
			$desc = isset( $row->description ) ? (string) $row->description : '';
			if ( '' === trim( $desc ) ) {
				$this->db->delete_sources_not_in( 'term', 't-' . $term_id . '-', array(), 0 );
				continue;
			}

			$tax_obj = get_taxonomy( $taxonomy );
			$tax_lbl = ( $tax_obj && ! empty( $tax_obj->labels->singular_name ) )
				? (string) $tax_obj->labels->singular_name
				: $taxonomy;
			/* translators: 1: taxonomy label, 2: term name */
			$default_anchor = sprintf( __( '%1$s: %2$s', 'tso-link-inspector' ), $tax_lbl, $name );
			$prefix         = 't-' . $term_id . '-';
			$allowed        = array();

			foreach ( $this->extract_all_url_items( $desc, $desc ) as $item ) {
				$url = isset( $item['url'] ) ? (string) $item['url'] : '';
				if ( '' === $url || $this->skip_url( $url ) || $this->is_ignored( $url ) ) {
					continue;
				}
				$sk        = $this->db->sanitize_source_key( $prefix . md5( $url ) );
				$allowed[] = $sk;
				$anchor    = ! empty( $item['anchor'] ) ? (string) $item['anchor'] : $default_anchor;
				$this->db->upsert_link( 0, $url, $anchor, 'term', $sk );
				$found++;
			}

			$this->db->delete_sources_not_in( 'term', $prefix, $allowed, 0 );
		}

		update_option( 'tsoliin_term_scan_after_id', $max_id, false );
		return $found;
	}

	/**
	 * Scan FSE templates, template parts, and reusable blocks (wp_block).
	 *
	 * @param int $per_page Max posts per call.
	 * @return int
	 */
	public function scan_fse_batch( $per_page = 20 ) {
		if ( ! $this->opt( 'scan_fse', true ) ) {
			return 0;
		}
		global $wpdb;
		$per_page = max( 1, absint( $per_page ) );
		$after_id = absint( get_option( 'tsoliin_fse_scan_after_id', 0 ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_type FROM {$wpdb->posts}
				WHERE post_status = 'publish' AND ID > %d AND post_type IN ( %s, %s, %s )
				ORDER BY ID ASC LIMIT %d",
				$after_id,
				'wp_template',
				'wp_template_part',
				'wp_block',
				$per_page
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $rows ) ) {
			update_option( 'tsoliin_fse_scan_after_id', 0, false );
			return 0;
		}

		$found  = 0;
		$max_id = $after_id;
		foreach ( $rows as $row ) {
			$post_id = absint( $row->ID );
			if ( $post_id ) {
				$max_id = max( $max_id, $post_id );
			}
			$link_type = ( 'wp_block' === (string) $row->post_type ) ? 'wp_block' : 'template';
			$found    += $this->scan_storage_post( $post_id, $link_type );
		}

		update_option( 'tsoliin_fse_scan_after_id', $max_id, false );
		return $found;
	}

}
