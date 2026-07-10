<?php
/**
 * HTTP link checker.
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_HTTP
 *
 * Status codes used internally:
 *   0   = generic connection error
 *  -2   = DNS failure
 *  -3   = timeout
 *  -4   = connection refused
 *  -5   = SSL error
 * 2-5   = legacy (stored by old absint() bug, same meaning as -2 to -5)
 */
class TSOLIIN_HTTP {

	/** @var int Request timeout in seconds. */
	private $timeout;

	public function __construct() {
		$s             = get_option( 'tsoliin_settings', array() );
		$this->timeout = isset( $s['timeout'] ) ? absint( $s['timeout'] ) : 15;
	}

	/**
	 * Normalize a URL for comparing redirect outcomes on user-verified rows.
	 *
	 * @param string $url Raw URL (may be empty).
	 * @return string Comparable form; empty string if input is empty.
	 */
	public static function normalize_redirect_for_verify_compare( $url ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( '' === $url ) {
			return '';
		}
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return strtolower( $url );
		}
		$scheme = isset( $parts['scheme'] ) ? strtolower( (string) $parts['scheme'] ) : 'http';
		$host   = strtolower( (string) $parts['host'] );
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';
		if ( '' === $path ) {
			$path = '/';
		}
		$query = '';
		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $qarr );
			if ( is_array( $qarr ) ) {
				ksort( $qarr );
				$query = http_build_query( $qarr, '', '&', PHP_QUERY_RFC3986 );
			}
		}
		$out = $scheme . '://' . $host . $port . $path;
		if ( '' !== $query ) {
			$out .= '?' . $query;
		}
		return $out;
	}

	/**
	 * Whether two URLs are the same for verify-lock baseline comparisons.
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 */
	public static function urls_equivalent_for_verify_lock( $a, $b ) {
		return self::normalize_redirect_for_verify_compare( $a ) === self::normalize_redirect_for_verify_compare( $b );
	}

	/**
	 * Hostnames considered internal for this WordPress site (with www / non-www variants).
	 *
	 * @return string[] Lowercase hostnames.
	 */
	public static function get_site_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $base ) {
			$host = wp_parse_url( $base, PHP_URL_HOST );
			if ( ! is_string( $host ) || '' === $host ) {
				continue;
			}
			$hosts = array_merge( $hosts, self::expand_site_host_variants( $host ) );
		}
		return array_values( array_unique( $hosts ) );
	}

	/**
	 * www.example.com and example.com as the same site host.
	 *
	 * @param string $host Hostname.
	 * @return string[] Lowercase variants.
	 */
	public static function expand_site_host_variants( $host ) {
		$host = strtolower( trim( (string) $host ) );
		if ( '' === $host ) {
			return array();
		}
		$variants = array( $host );
		if ( 0 === strpos( $host, 'www.' ) ) {
			$variants[] = substr( $host, 4 );
		} else {
			$variants[] = 'www.' . $host;
		}
		return array_values( array_unique( array_filter( $variants ) ) );
	}

	/**
	 * Normalize a hostname for same-site comparison (lowercase, no leading www.).
	 *
	 * @param string $host Hostname.
	 * @return string
	 */
	public static function normalize_site_host( $host ) {
		$host = strtolower( trim( (string) $host ) );
		return (string) preg_replace( '/^www\./', '', $host );
	}

	/**
	 * Whether a hostname belongs to this WordPress installation.
	 *
	 * @param string $host Link hostname.
	 * @return bool
	 */
	public static function host_belongs_to_site( $host ) {
		$host = self::normalize_site_host( $host );
		if ( '' === $host ) {
			return false;
		}
		foreach ( self::get_site_hosts() as $site_host ) {
			if ( $host === self::normalize_site_host( $site_host ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * SQL fragment matching URLs that point to this site (for list scope filters).
	 *
	 * @param string $column SQL column reference, e.g. l.link_url.
	 * @return array{sql:string,params:array}
	 */
	public static function build_internal_link_scope_sql( $column = 'l.link_url' ) {
		$column = preg_replace( '/[^a-z0-9_.]/', '', (string) $column );
		if ( '' === $column ) {
			$column = 'l.link_url';
		}

		// Use %% so $wpdb->prepare() leaves a single SQL LIKE wildcard (%).
		$parts  = array(
			"( {$column} LIKE '/%%' OR {$column} LIKE './%%' OR {$column} LIKE '../%%' )",
		);
		$params = array();
		foreach ( self::get_site_hosts() as $host ) {
			foreach ( array( 'http://', 'https://', '//' ) as $scheme ) {
				$parts[]  = "{$column} LIKE %s";
				$params[] = $scheme . $host . '%';
			}
		}

		return array(
			'sql'    => '(' . implode( ' OR ', $parts ) . ')',
			'params' => $params,
		);
	}

	/**
	 * SQL fragment for external links (De Morgan: AND of NOT LIKE, safe for $wpdb->prepare).
	 *
	 * @param string $column SQL column reference, e.g. l.link_url.
	 * @return array{sql:string,params:array}
	 */
	public static function build_external_link_scope_sql( $column = 'l.link_url' ) {
		$column = preg_replace( '/[^a-z0-9_.]/', '', (string) $column );
		if ( '' === $column ) {
			$column = 'l.link_url';
		}

		$parts = array(
			"{$column} NOT LIKE '/%%'",
			"{$column} NOT LIKE './%%'",
			"{$column} NOT LIKE '../%%'",
		);
		$params = array();
		foreach ( self::get_site_hosts() as $host ) {
			foreach ( array( 'http://', 'https://', '//' ) as $scheme ) {
				$parts[]  = "{$column} NOT LIKE %s";
				$params[] = $scheme . $host . '%';
			}
		}

		return array(
			'sql'    => '(' . implode( ' AND ', $parts ) . ')',
			'params' => $params,
		);
	}

	/**
	 * Whether a stored link URL points to this site (relative or same host).
	 *
	 * @param string $url     Link URL.
	 * @param int    $post_id Optional post context (reserved).
	 * @return bool
	 */
	public static function is_internal_link_url( $url, $post_id = 0 ) {
		unset( $post_id );
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( '' === $url ) {
			return false;
		}
		if ( preg_match( '#^(?:/|\./|\.\./)#', $url ) ) {
			return true;
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( ! is_string( $host ) || '' === $host ) {
			return false;
		}
		return self::host_belongs_to_site( $host );
	}

	/**
	 * Parse attachment_id from a WordPress attachment page URL.
	 *
	 * @param string $url Absolute or relative URL.
	 * @return int Attachment post ID, or 0.
	 */
	public static function parse_attachment_id_from_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return 0;
		}
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return 0;
		}
		$qv = array();
		wp_parse_str( $query, $qv );
		return isset( $qv['attachment_id'] ) ? absint( $qv['attachment_id'] ) : 0;
	}

	/**
	 * Whether a URL is a WordPress attachment post permalink (not the media file in uploads).
	 *
	 * Gallery/image blocks often store both the file URL and an /attachment/slug page URL;
	 * only the uploads file is useful for link checking.
	 *
	 * @param string $url     Absolute or relative URL.
	 * @param int    $post_id Optional post context for internal URL resolution.
	 * @return bool
	 */
	public static function is_attachment_permalink_url( $url, $post_id = 0 ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( '' === $url || ! self::is_internal_link_url( $url, $post_id ) ) {
			return false;
		}

		if ( self::parse_attachment_id_from_url( $url ) > 0 ) {
			return true;
		}

		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $path ) && preg_match( '#/attachment(?:/|$)#i', $path ) ) {
			return true;
		}

		if ( is_string( $path ) ) {
			if ( false !== stripos( $path, '/wp-content/uploads/' ) ) {
				return false;
			}
			if ( preg_match( '/\.(?:jpe?g|png|gif|webp|avif|svg|bmp|ico|mp4|mp3|pdf|zip)(?:[?#]|$)/i', $path ) ) {
				return false;
			}
		}

		if ( ! function_exists( 'url_to_postid' ) ) {
			return false;
		}

		$abs = $url;
		if ( preg_match( '#^(?:/|\./|\.\./)#', $abs ) ) {
			$abs = home_url( $abs );
		} elseif ( 0 === strpos( $abs, '//' ) ) {
			$abs = ( is_ssl() ? 'https:' : 'http:' ) . $abs;
		}

		$attachment_id = (int) url_to_postid( $abs );
		if ( $attachment_id <= 0 ) {
			return false;
		}

		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return false;
		}

		$file_url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $file_url ) || '' === $file_url ) {
			return true;
		}

		$file_path = wp_parse_url( $file_url, PHP_URL_PATH );
		$url_path  = wp_parse_url( $abs, PHP_URL_PATH );
		if ( is_string( $file_path ) && is_string( $url_path ) && $file_path === $url_path ) {
			return false;
		}

		return true;
	}

	/**
	 * For ?attachment_id= URLs, return the wp-content/uploads file URL when available.
	 *
	 * Used only for optional suggestions — not for the primary check(), which must test
	 * the URL visitors actually click (attachment pages can 404 while the file still exists).
	 *
	 * @param string $url Absolute URL.
	 * @return string
	 */
	public static function prefer_attachment_file_url( $url ) {
		$attachment_id = self::parse_attachment_id_from_url( $url );
		if ( $attachment_id <= 0 ) {
			return $url;
		}
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type || 'trash' === $post->post_status ) {
			return $url;
		}
		$file_url = wp_get_attachment_url( $attachment_id );
		return ( is_string( $file_url ) && '' !== $file_url ) ? $file_url : $url;
	}

	/**
	 * Whether a local attachment_id target is missing or unusable on this WordPress site.
	 *
	 * @param int $attachment_id Attachment post ID.
	 * @return bool
	 */
	public static function attachment_id_target_unavailable( $attachment_id ) {
		$attachment_id = absint( $attachment_id );
		if ( $attachment_id <= 0 ) {
			return true;
		}
		$post = get_post( $attachment_id );
		if ( ! $post || 'attachment' !== $post->post_type || 'trash' === $post->post_status ) {
			return true;
		}
		$file_url = wp_get_attachment_url( $attachment_id );
		return ! is_string( $file_url ) || '' === $file_url;
	}

	/**
	 * Local 404 for internal ?attachment_id= links when the attachment no longer exists.
	 *
	 * @param string $url Absolute URL.
	 * @return array{status_code:int,redirect_url:string,is_broken:int}|null
	 */
	private function maybe_local_attachment_id_check_result( $url ) {
		$attachment_id = self::parse_attachment_id_from_url( $url );
		if ( $attachment_id <= 0 || ! self::is_internal_link_url( $url ) ) {
			return null;
		}
		if ( ! self::attachment_id_target_unavailable( $attachment_id ) ) {
			return null;
		}
		return array(
			'status_code'  => 404,
			'redirect_url' => '',
			'is_broken'    => 1,
		);
	}

	/**
	 * Same-site URL with www toggled on the host (scheme/path/query preserved).
	 *
	 * @param string $url Absolute URL.
	 * @return string Empty when host cannot be parsed.
	 */
	public static function toggle_www_host_in_url( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}
		$host = (string) $parts['host'];
		if ( 0 === stripos( $host, 'www.' ) ) {
			$parts['host'] = substr( $host, 4 );
		} else {
			$parts['host'] = 'www.' . $host;
		}
		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] . '://' : '';
		$port   = isset( $parts['port'] ) ? ':' . (int) $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? $parts['path'] : '';
		$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$frag   = isset( $parts['fragment'] ) ? '#' . $parts['fragment'] : '';
		return $scheme . $parts['host'] . $port . $path . $query . $frag;
	}

	/**
	 * Internal URL variants to try before marking a link broken (www, attachment permalink).
	 *
	 * @param string $url     Resolved absolute URL.
	 * @param int    $post_id Post context.
	 * @return string[]
	 */
	private function build_check_url_candidates( $url, $post_id = 0 ) {
		$candidates = array( (string) $url );
		if ( ! self::is_internal_link_url( $url, $post_id ) ) {
			return $candidates;
		}

		$www_alt = self::toggle_www_host_in_url( $url );
		if ( '' !== $www_alt && ! in_array( $www_alt, $candidates, true ) ) {
			$candidates[] = $www_alt;
		}

		$attachment_id = self::parse_attachment_id_from_url( $url );
		if ( $attachment_id > 0 ) {
			$file_url = wp_get_attachment_url( $attachment_id );
			if ( is_string( $file_url ) && '' !== $file_url ) {
				foreach ( array( $file_url, self::toggle_www_host_in_url( $file_url ) ) as $extra ) {
					if ( is_string( $extra ) && '' !== $extra && ! in_array( $extra, $candidates, true ) ) {
						$candidates[] = $extra;
					}
				}
			}
			if ( function_exists( 'get_attachment_link' ) ) {
				$permalink = get_attachment_link( $attachment_id );
				if ( is_string( $permalink ) && '' !== $permalink ) {
					foreach ( array( $permalink, self::toggle_www_host_in_url( $permalink ) ) as $extra ) {
						if ( is_string( $extra ) && '' !== $extra && ! in_array( $extra, $candidates, true ) ) {
							$candidates[] = $extra;
						}
					}
				}
			}
		}

		return array_values( array_unique( array_filter( $candidates ) ) );
	}

	/**
	 * When ?attachment_id= page URLs fail HTTP but the media file still exists, treat as OK.
	 *
	 * @param string               $url      Original stored URL.
	 * @param int                  $post_id  Post context.
	 * @return array{status_code:int,redirect_url:string,is_broken:int}|null
	 */
	private function maybe_attachment_media_file_ok_result( $url, $post_id = 0 ) {
		$attachment_id = self::parse_attachment_id_from_url( $url );
		if ( $attachment_id <= 0 || ! self::is_internal_link_url( $url, $post_id ) ) {
			return null;
		}
		if ( self::attachment_id_target_unavailable( $attachment_id ) ) {
			return null;
		}

		$file_url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $file_url ) || '' === $file_url ) {
			return null;
		}

		foreach ( array_unique( array_filter( array( $file_url, self::toggle_www_host_in_url( $file_url ) ) ) ) as $candidate ) {
			$file_result = $this->check_request( $candidate, $post_id );
			if ( empty( $file_result['is_broken'] ) ) {
				return array(
					'status_code'  => 200,
					'redirect_url' => '',
					'is_broken'    => 0,
				);
			}
		}

		return null;
	}

	/**
	 * Whether a URL likely points at a static asset (HEAD often unreliable).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private function is_static_asset_url( $url ) {
		return (bool) preg_match( '~\.(?:webp|avif|jpe?g|png|gif|svg|pdf|mp4|webm|mp3|css|js|zip)(?:[?#]|$)~i', (string) $url );
	}

	/**
	 * Google Play app id from store URL or editor-relative /details?id=… href.
	 *
	 * @param string $url Link URL.
	 * @return string Lowercase app id or empty.
	 */
	public static function parse_play_store_app_id( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return '';
		}
		$is_play = ( false !== stripos( $url, 'play.google.com' ) )
			|| 0 === strpos( $url, '/details' )
			|| 0 === strpos( $url, 'details?' );
		if ( ! $is_play ) {
			return '';
		}
		$query = wp_parse_url( $url, PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			if ( false !== strpos( $url, '?' ) ) {
				$query = substr( $url, (int) strpos( $url, '?' ) + 1 );
			}
		}
		if ( ! is_string( $query ) || '' === $query ) {
			return '';
		}
		$qv = array();
		wp_parse_str( $query, $qv );
		return ! empty( $qv['id'] ) ? strtolower( sanitize_text_field( (string) $qv['id'] ) ) : '';
	}

	/**
	 * Whether an absolute internal URL can be converted to a site-relative path.
	 *
	 * @param string $url Link URL.
	 * @return bool
	 */
	public static function can_convert_to_relative_url( $url ) {
		$relative = self::absolute_internal_to_relative( $url );
		return is_string( $relative ) && '' !== $relative;
	}

	/**
	 * Convert an absolute same-site URL to a root-relative path.
	 *
	 * @param string $url Absolute http(s) URL on this site.
	 * @return string|false Relative path or false.
	 */
	public static function absolute_internal_to_relative( $url ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( ! preg_match( '#^https?://#i', $url ) || ! self::is_internal_link_url( $url ) ) {
			return false;
		}
		$parts = wp_parse_url( $url );
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		if ( '' === $path ) {
			$path = '/';
		}
		$relative = $path;
		if ( ! empty( $parts['query'] ) ) {
			$relative .= '?' . $parts['query'];
		}
		if ( ! empty( $parts['fragment'] ) ) {
			$relative .= '#' . $parts['fragment'];
		}
		$url_norm = untrailingslashit( $url );
		$bases    = array(
			untrailingslashit( home_url( $relative ) ),
			untrailingslashit( site_url( $relative ) ),
		);
		if ( in_array( $url_norm, $bases, true ) ) {
			return $relative;
		}
		if ( function_exists( 'wp_make_link_relative' ) ) {
			$made = wp_make_link_relative( $url );
			if ( is_string( $made ) && '' !== $made && self::is_internal_link_url( $url ) ) {
				return $made;
			}
		}
		return false;
	}

	/**
	 * Whether a URL matches the ignore list (domains or prefixes from settings).
	 *
	 * @param string $url URL to check.
	 * @return bool
	 */
	public static function is_ignored_url( $url ) {
		$s       = get_option( 'tsoliin_settings', array() );
		$ignored = isset( $s['ignore_list'] ) && is_array( $s['ignore_list'] ) ? $s['ignore_list'] : array();
		if ( empty( $ignored ) ) {
			return false;
		}
		$url_lower = strtolower( (string) $url );
		$host      = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		foreach ( $ignored as $pattern ) {
			$p = trim( strtolower( (string) $pattern ) );
			if ( '' === $p ) {
				continue;
			}
			if ( 0 === strpos( $url_lower, $p ) ) {
				return true;
			}
			if ( '' !== $host && self::host_matches_ignore_pattern( $host, $p ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a hostname matches an ignore-list domain entry (exact or subdomain only).
	 *
	 * Avoids false positives such as pattern "sony" matching "talk.sonymobile.com".
	 *
	 * @param string $host    URL host (lowercase).
	 * @param string $pattern Ignore-list entry (lowercase domain or URL prefix).
	 * @return bool
	 */
	private static function host_matches_ignore_pattern( $host, $pattern ) {
		$host    = strtolower( trim( (string) $host ) );
		$pattern = strtolower( trim( (string) $pattern ) );
		if ( '' === $host || '' === $pattern ) {
			return false;
		}
		// URL/path prefixes are handled via strpos on the full URL, not here.
		if ( preg_match( '#^https?://#', $pattern ) || false !== strpos( $pattern, '/' ) ) {
			return false;
		}
		if ( $host === $pattern ) {
			return true;
		}
		$suffix = '.' . $pattern;
		$len    = strlen( $suffix );
		return strlen( $host ) > $len && substr( $host, -$len ) === $suffix;
	}

	/**
	 * Sanitize an external http(s) URL for storage in post content (blocks SSRF targets).
	 *
	 * @param string $url Raw URL.
	 * @return string|false Sanitized URL or false when invalid/disallowed.
	 */
	public static function sanitize_external_http_url( $url ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( '' === $url ) {
			return false;
		}
		$url = esc_url_raw( $url );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		if ( ! self::is_safe_remote_url( $url ) ) {
			return false;
		}
		return $url;
	}

	/**
	 * Sanitize a URL stored in post/comment content (absolute or site-relative).
	 *
	 * @param string $url     Raw URL from the editor modal.
	 * @param int    $post_id Post ID for context (relative resolution checks).
	 * @return string|false Sanitized href or false when disallowed.
	 */
	public static function sanitize_editable_link_url( $url, $post_id = 0 ) {
		$url = trim( str_replace( array( "\0", "\r", "\n", "\t" ), '', (string) $url ) );
		if ( '' === $url ) {
			return false;
		}
		if ( preg_match( '#^\s*(javascript|data|vbscript):#i', $url ) ) {
			return false;
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return self::sanitize_external_http_url( $url );
		}
		if ( 0 === strpos( $url, '//' ) ) {
			$probe = 'https:' . $url;
			if ( ! self::is_safe_remote_url( $probe ) ) {
				return false;
			}
			return $url;
		}
		if ( preg_match( '#^(?:/|\./|\.\./)#', $url ) ) {
			return preg_replace( '#[\x00-\x1F\x7F]#', '', $url );
		}
		if ( preg_match( '#^[a-z0-9][a-z0-9._\-/]*(?:\?[^\s<>"\']*)?(?:#[^\s<>"\']*)?$#i', $url ) ) {
			return preg_replace( '#[\x00-\x1F\x7F]#', '', $url );
		}
		return false;
	}

	/**
	 * Whether an http(s) URL is safe to request from the server (SSRF mitigation).
	 *
	 * @param string $url Full URL.
	 * @return bool
	 */
	public static function is_safe_remote_url( $url ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return false;
		}
		if ( function_exists( 'wp_http_validate_url' ) && false === wp_http_validate_url( $url ) ) {
			return false;
		}
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}
		$blocked_hosts = array( 'localhost', '127.0.0.1', '0.0.0.0', '[::1]', '::1' );
		if ( in_array( $host, $blocked_hosts, true ) ) {
			return false;
		}
		if ( preg_match( '#\.(local|localhost|internal|intranet)$#', $host ) ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return self::is_public_ip( $host );
		}
		$ips = self::resolve_host_ips( $host );
		if ( empty( $ips ) ) {
			return false;
		}
		foreach ( $ips as $ip ) {
			if ( ! self::is_public_ip( $ip ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * @param string $ip IP address.
	 * @return bool
	 */
	private static function is_public_ip( $ip ) {
		return filter_var(
			$ip,
			FILTER_VALIDATE_IP,
			FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
		) !== false;
	}

	/**
	 * @param string $host Hostname.
	 * @return string[]
	 */
	private static function resolve_host_ips( $host ) {
		$ips = array();
		if ( function_exists( 'dns_get_record' ) ) {
			$dns_type = defined( 'DNS_A' ) ? DNS_A : 1;
			if ( defined( 'DNS_AAAA' ) ) {
				$dns_type += DNS_AAAA;
			}
			$records = @dns_get_record( $host, $dns_type ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $records ) ) {
				foreach ( $records as $record ) {
					if ( ! empty( $record['ip'] ) ) {
						$ips[] = (string) $record['ip'];
					}
					if ( ! empty( $record['ipv6'] ) ) {
						$ips[] = (string) $record['ipv6'];
					}
				}
			}
		}
		if ( empty( $ips ) && function_exists( 'gethostbynamel' ) ) {
			$resolved = @gethostbynamel( $host ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( is_array( $resolved ) ) {
				$ips = $resolved;
			}
		}
		return array_unique( array_filter( $ips ) );
	}

	/**
	 * Suggest an ignore-list entry (domain or URL prefix) from a link URL.
	 *
	 * @param string $url Link URL as stored in the database.
	 * @return string Empty when no pattern can be derived.
	 */
	public static function suggest_ignore_pattern_from_url( $url ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( '' === $url ) {
			return '';
		}

		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( is_string( $host ) && '' !== $host ) {
			return strtolower( $host );
		}

		if ( preg_match( '#^https?://#i', $url ) ) {
			return '';
		}

		if ( 0 === strpos( $url, '/' ) || 0 === strpos( $url, './' ) || 0 === strpos( $url, '../' ) ) {
			return untrailingslashit( strtolower( home_url( $url ) ) );
		}

		return '';
	}

	/**
	 * Append a pattern to the ignore list in plugin settings.
	 *
	 * @param string $pattern Domain or URL prefix.
	 * @return string|false `added`, `exists`, or false on invalid input.
	 */
	public static function add_ignore_pattern( $pattern ) {
		$pattern = trim( strtolower( (string) $pattern ) );
		if ( '' === $pattern ) {
			return false;
		}

		$s = get_option( 'tsoliin_settings', array() );
		if ( ! is_array( $s ) ) {
			$s = array();
		}
		$list = isset( $s['ignore_list'] ) && is_array( $s['ignore_list'] ) ? $s['ignore_list'] : array();
		foreach ( $list as $entry ) {
			if ( strtolower( trim( (string) $entry ) ) === $pattern ) {
				return 'exists';
			}
		}
		$list[]           = $pattern;
		$s['ignore_list'] = $list;
		update_option( 'tsoliin_settings', $s );
		return 'added';
	}

	/**
	 * Resolve a stored link URL to an absolute URL suitable for opening in a browser.
	 *
	 * @param string $url Link URL.
	 * @return string Empty when the URL cannot be opened.
	 */
	public static function resolve_link_open_url( $url ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		if ( '' === $url || preg_match( '#^(mailto:|tel:|javascript:)#i', $url ) ) {
			return '';
		}
		if ( 0 === strpos( $url, '//' ) ) {
			return 'https:' . $url;
		}
		if ( 0 === strpos( $url, '/' ) || 0 === strpos( $url, './' ) || 0 === strpos( $url, '../' ) ) {
			return home_url( $url );
		}
		if ( preg_match( '#^https?://#i', $url ) ) {
			return $url;
		}
		return '';
	}

	/**
	 * Result when a URL is skipped (ignored or blocked for safety).
	 *
	 * @return array{status_code:int,redirect_url:string,is_broken:int}
	 */
	private function skipped_url_result() {
		return array(
			'status_code'  => -1,
			'redirect_url' => '',
			'is_broken'    => 0,
		);
	}

	/**
	 * Result when a URL cannot be requested from the server (SSRF / invalid host).
	 *
	 * @return array{status_code:int,redirect_url:string,is_broken:int}
	 */
	private function blocked_url_result() {
		return array(
			'status_code'  => -7,
			'redirect_url' => '',
			'is_broken'    => 0,
		);
	}

	/**
	 * Result for action URLs (logout, etc.) that must not be HTTP-checked.
	 *
	 * @return array{status_code:int,redirect_url:string,is_broken:int}
	 */
	private function action_url_result() {
		return array(
			'status_code'  => -6,
			'redirect_url' => '',
			'is_broken'    => 0,
		);
	}

	/**
	 * Whether a URL performs a side effect (logout) instead of normal navigation.
	 *
	 * @param string $url Full URL.
	 * @return bool
	 */
	public static function is_action_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url ) {
			return false;
		}

		$path   = strtolower( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		$query  = (string) wp_parse_url( $url, PHP_URL_QUERY );
		$params = array();
		if ( '' !== $query ) {
			parse_str( $query, $params );
		}
		$action = isset( $params['action'] ) ? strtolower( sanitize_key( (string) $params['action'] ) ) : '';

		// WordPress logout (root or subdirectory installs).
		if ( false !== strpos( $path, 'wp-login.php' ) && 'logout' === $action ) {
			return true;
		}

		// Some plugins/themes route logout through admin-post.php.
		if ( false !== strpos( $path, 'admin-post.php' ) && 'logout' === $action ) {
			return true;
		}

		return false;
	}

	/**
	 * Resolve a Location header value against the current request URL.
	 *
	 * @param string $location  Location header value.
	 * @param string $base_url  URL of the response that sent the redirect.
	 * @return string Absolute URL or empty if invalid.
	 */
	private function resolve_redirect_location( $location, $base_url ) {
		$location = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $location ) );
		if ( '' === $location ) {
			return '';
		}
		if ( 0 === strpos( $location, 'http' ) ) {
			return $location;
		}
		if ( 0 === strpos( $location, '//' ) ) {
			$scheme = wp_parse_url( $base_url, PHP_URL_SCHEME );
			if ( ! $scheme ) {
				$scheme = is_ssl() ? 'https' : 'http';
			}
			return $scheme . ':' . $location;
		}
		$parsed = wp_parse_url( $base_url );
		if ( empty( $parsed['host'] ) ) {
			return '';
		}
		$base = $parsed['scheme'] . '://' . $parsed['host'];
		if ( ! empty( $parsed['port'] ) ) {
			$base .= ':' . $parsed['port'];
		}
		return ( '/' === $location[0] ) ? $base . $location : $base . '/' . $location;
	}

	/**
	 * Hosts that often block server-side HTTP checks while the page works in a browser.
	 *
	 * X/Twitter in particular may answer crawlers with 404 for valid tweet URLs.
	 *
	 * @param string $url Full URL.
	 * @return bool
	 */
	public static function is_bot_wall_host( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		if ( '' === $host ) {
			return false;
		}

		$hosts = array(
			'x.com',
			'twitter.com',
			'mobile.twitter.com',
			't.co',
			'facebook.com',
			'fb.com',
			'fb.me',
			'instagram.com',
			'threads.net',
			'linkedin.com',
		);

		foreach ( $hosts as $known ) {
			if ( $host === $known ) {
				return true;
			}
			$suffix = '.' . $known;
			if ( strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * When a social/bot-wall host returns 404 to our checker, treat as unverifiable — not broken.
	 *
	 * @param string $url  URL that was checked (original or final destination).
	 * @param int    $code HTTP status from the server.
	 * @return array{status_code:int,redirect_url:string,is_broken:int}|null
	 */
	private function maybe_bot_wall_check_result( $url, $code ) {
		$code = (int) $code;
		if ( 404 !== $code || ! self::is_bot_wall_host( $url ) ) {
			return null;
		}

		return array(
			'status_code'  => 403,
			'redirect_url' => '',
			'is_broken'    => 0,
		);
	}

	/**
	 * Check a URL and return status info.
	 *
	 * Tries same-site www/non-www (and attachment permalink) variants before reporting broken.
	 *
	 * @param string $url     URL to check (absolute or relative to the site).
	 * @param int    $post_id Post ID for resolving relative internal links.
	 * @return array { status_code: int, redirect_url: string, is_broken: int }
	 */
	public function check( $url, $post_id = 0 ) {
		$url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		$url = TSOLIIN_Scanner::resolve_to_absolute_url( $url, $post_id );

		$candidates = $this->build_check_url_candidates( $url, $post_id );
		$last       = null;

		foreach ( $candidates as $candidate ) {
			$result = $this->check_request( $candidate, $post_id );
			if ( empty( $result['is_broken'] ) ) {
				return $result;
			}
			$last = $result;
		}

		if ( null === $last ) {
			return array(
				'status_code'  => -8,
				'redirect_url' => '',
				'is_broken'    => 0,
			);
		}

		$local_attachment = $this->maybe_local_attachment_id_check_result( $url );
		if ( null !== $local_attachment ) {
			return $local_attachment;
		}

		$media_ok = $this->maybe_attachment_media_file_ok_result( $url, $post_id );
		if ( null !== $media_ok ) {
			return $media_ok;
		}

		return $last;
	}

	/**
	 * Single HTTP check for one absolute URL (no host-variant retries).
	 *
	 * @param string $url     Absolute http(s) URL.
	 * @param int    $post_id Post ID for resolving relative internal links.
	 * @return array { status_code: int, redirect_url: string, is_broken: int }
	 */
	private function check_request( $url, $post_id = 0 ) {
		unset( $post_id );

		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return array( 'status_code' => -8, 'redirect_url' => '', 'is_broken' => 0 );
		}

		if ( self::is_action_url( $url ) ) {
			return $this->action_url_result();
		}

		if ( self::is_ignored_url( $url ) ) {
			return $this->skipped_url_result();
		}
		if ( ! self::is_safe_remote_url( $url ) ) {
			return $this->blocked_url_result();
		}

		$chrome_unavail = $this->maybe_chrome_webstore_unavailable_result( $url );
		if ( null !== $chrome_unavail ) {
			return $chrome_unavail;
		}

		// Strip URL fragment (#anchor) before HTTP check.
		// Fragments are browser-only and never sent to the server.
		// We preserve the original fragment to restore it if no real redirect happens.
		$fragment    = '';
		$hash_pos    = strpos( $url, '#' );
		if ( false !== $hash_pos ) {
			$fragment = substr( $url, $hash_pos ); // e.g. "#comment-1898"
			$url      = substr( $url, 0, $hash_pos ); // URL without fragment
		}

		// Do NOT let WordPress auto-follow redirects (redirection => 0).
		// We follow manually so we can capture the final URL of the redirect chain.
		$args = array(
			'timeout'             => $this->timeout,
			'redirection'         => 0,
			'reject_unsafe_urls'  => true,
			'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
			'sslverify'           => true,
			'headers'             => array(
				'Accept'          => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				'Accept-Language' => 'ca,es;q=0.9,en;q=0.8',
			),
		);

		// Follow redirect chain manually (up to 8 hops).
		$final_url      = $url;
		$redirect_to    = '';
		$first_code     = 0;   // status code of the first redirect hop
		$hops           = 0;
		$max_hops       = 8;
		$redirect_chain = array();

		do {
			$response = wp_remote_head( $final_url, $args );
			$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

			// Retry with GET if HEAD returned error or blocking code.
			// Some hosts return 401 to HEAD but 200 to GET; same-site URLs may return 404 to HEAD only.
			$retry_get_codes = array( 0, 401, 403, 405 );
			if ( self::is_internal_link_url( $final_url ) || $this->is_static_asset_url( $final_url ) ) {
				$retry_get_codes[] = 404;
			}
			if ( is_wp_error( $response ) || in_array( $code, $retry_get_codes, true ) ) {
				$get_r    = wp_remote_get( $final_url, array_merge( $args, array( 'stream' => false ) ) );
				if ( ! is_wp_error( $get_r ) ) {
					$get_code = (int) wp_remote_retrieve_response_code( $get_r );
					if ( 0 === $code || $get_code < $code || 200 === $get_code ) {
						$response = $get_r;
						$code     = $get_code;
					}
				}
			}

			if ( is_wp_error( $response ) ) {
				$error_code = $this->classify_error( $response );
				return array(
					'status_code'    => $error_code,
					'redirect_url'   => $redirect_to,
					'redirect_chain' => $redirect_chain,
					'is_broken'      => ( -5 === $error_code ) ? 0 : 1,
				);
			}

			// If this is a redirect, grab the Location header and follow it.
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$loc = wp_remote_retrieve_header( $response, 'location' );
				$loc = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $loc ) );

				if ( '' === $loc ) {
					break; // No Location header, stop.
				}

				$loc = $this->resolve_redirect_location( $loc, $final_url );
				if ( '' === $loc ) {
					break;
				}

				if ( self::is_ignored_url( $loc ) || ! self::is_safe_remote_url( $loc ) ) {
					break;
				}

				if ( 0 === $hops ) {
					$first_code = $code; // Capture first hop code (301, 302, etc.)
				}
				$redirect_chain[] = array(
					'code' => $code,
					'url'  => $loc,
				);
				$redirect_to = $loc;
				$final_url   = $loc;
				$hops++;
			} else {
				break; // Not a redirect — we have the final response.
			}
		} while ( $hops < $max_hops );

		// A redirect chain was followed ONLY if the base URL (no fragment) changed.
		if ( $final_url !== $url ) {

			// Chrome Web Store: human slug → empty-title with same extension ID = removed/unavailable.
			$chrome_removed = $this->maybe_chrome_webstore_removed_redirect_result( $url, $final_url, $redirect_chain );
			if ( null !== $chrome_removed ) {
				return $chrome_removed;
			}

			$chrome_unavail = $this->maybe_chrome_webstore_unavailable_result( $final_url, $redirect_chain );
			if ( null !== $chrome_unavail ) {
				return $chrome_unavail;
			}

			// Case 1: trivial redirect (trailing slash, www variant, CDN hop, etc.).
			// Still honour the final HTTP status — e.g. www → bare host 301 must not mask a 404.
			if ( $this->is_trivial_redirect( $url, $final_url ) ) {
				return $this->resolve_transparent_redirect_result( $final_url, (int) $code, $redirect_chain );
			}

			// Case 1c: same-site redirect that strips search/query intent (bot walls, empty search forms).
			// e.g. filmaffinity search.php?stext=Actor → advsearch2.php?q= (empty) while the original URL works in a browser.
			if ( $this->is_query_stripping_redirect( $url, $final_url ) ) {
				return $this->resolve_transparent_redirect_result( $final_url, (int) $code, $redirect_chain );
			}

			// Case 2: auth/login wall (Facebook, Google etc. bot-block).
			// Treat as 401 warning — not broken, not a real redirect.
			if ( $this->is_auth_redirect( $final_url ) ) {
				return array(
					'status_code'  => 401,  // auth wall
					'redirect_url' => '',   // don't expose login URL as redirect destination
					'is_broken'    => 0,    // not broken, just protected
				);
			}

			$had_fragment = ( '' !== $fragment );

			// Case 2b: fragment (#anchor) — only collapse to 200 when the destination is the same page.
			if ( $had_fragment ) {
				$final_code = (int) $code;
				$bot_wall   = $this->maybe_bot_wall_check_result( $final_url, $final_code );
				if ( null !== $bot_wall ) {
					return $bot_wall;
				}
				if ( $this->is_broken( $final_code ) ) {
					return array(
						'status_code'    => $final_code,
						'redirect_url'   => $final_url,
						'redirect_chain' => $redirect_chain,
						'is_broken'      => 1,
					);
				}
				if ( $this->is_fragment_same_page_redirect( $url, $final_url ) ) {
					return array(
						'status_code'  => 200,
						'redirect_url' => '',
						'is_broken'    => 0,
					);
				}
			}

			// Case 2c: redirect chain resolved to an error page (e.g. 301 to www, then 404).
			$final_code = (int) $code;
			$bot_wall   = $this->maybe_bot_wall_check_result( $final_url, $final_code );
			if ( null !== $bot_wall ) {
				return $bot_wall;
			}
			if ( $this->is_broken( $final_code ) ) {
				return array(
					'status_code'  => $final_code,
					'redirect_url' => '',
					'is_broken'    => 1,
				);
			}

			// Case 3: real redirect to different content.
			return array(
				'status_code'    => $first_code, // first hop code (301, 302…)
				'redirect_url'   => $final_url,  // final destination
				'redirect_chain' => $redirect_chain,
				'is_broken'      => 0,
			);
		}

		// No real redirect (or only fragment differed): return final code.
		$bot_wall = $this->maybe_bot_wall_check_result( $final_url, (int) $code );
		if ( null !== $bot_wall ) {
			return $bot_wall;
		}

		$chrome_unavail = $this->maybe_chrome_webstore_unavailable_result( $final_url );
		if ( null !== $chrome_unavail ) {
			return $chrome_unavail;
		}

		return array(
			'status_code'    => $code,
			'redirect_url'   => '',
			'redirect_chain' => array(),
			'is_broken'      => $this->is_broken( $code ) ? 1 : 0,
		);
	}

	/**
	 * Build check() output for a “transparent” redirect (www, trailing slash, CDN hop).
	 *
	 * Uses the real final HTTP status after the chain — must not report 200 when the destination is 404.
	 *
	 * @param string $final_url      Final URL after redirects.
	 * @param int    $final_code     HTTP status at the final hop.
	 * @param array  $redirect_chain Collected redirect hops.
	 * @return array
	 */
	private function resolve_transparent_redirect_result( $final_url, $final_code, $redirect_chain = array() ) {
		$chrome_unavail = $this->maybe_chrome_webstore_unavailable_result( $final_url, $redirect_chain );
		if ( null !== $chrome_unavail ) {
			return $chrome_unavail;
		}

		$final_code = (int) $final_code;
		$bot_wall   = $this->maybe_bot_wall_check_result( $final_url, $final_code );
		if ( null !== $bot_wall ) {
			return $bot_wall;
		}
		if ( $this->is_broken( $final_code ) ) {
			return array(
				'status_code'    => $final_code,
				'redirect_url'   => '',
				'redirect_chain' => $redirect_chain,
				'is_broken'      => 1,
			);
		}
		if ( in_array( $final_code, array( 301, 302, 303, 307, 308 ), true ) ) {
			return array(
				'status_code'    => $final_code,
				'redirect_url'   => $final_url,
				'redirect_chain' => $redirect_chain,
				'is_broken'      => 0,
			);
		}
		return array(
			'status_code'    => 200,
			'redirect_url'   => '',
			'redirect_chain' => $redirect_chain,
			'is_broken'      => 0,
		);
	}

	/**
	 * Classify a WP_Error into a negative status code.
	 *
	 * @param WP_Error $error Error object.
	 * @return int
	 */
	private function classify_error( $error ) {
		$msg = strtolower( (string) $error->get_error_message() );
		if ( false !== strpos( $msg, 'could not resolve' ) || false !== strpos( $msg, 'name or service not known' ) || false !== strpos( $msg, 'nodename nor servname' ) ) {
			return -2;
		}
		if ( false !== strpos( $msg, 'timed out' ) || false !== strpos( $msg, 'timeout' ) ) {
			return -3;
		}
		if ( false !== strpos( $msg, 'connection refused' ) ) {
			return -4;
		}
		if ( false !== strpos( $msg, 'ssl' ) || false !== strpos( $msg, 'certificate' ) ) {
			return -5;
		}
		return 0;
	}

	/**
	 * Determine if a status code means the link is broken.
	 *
	 * NOTE: 401, 403, 429 are NOT broken — they may be bot-blocks.
	 *
	 * @param int $code HTTP status code.
	 * @return bool
	 */
	private function is_broken( $code ) {
		return self::is_hard_broken_status( $code );
	}

	/**
	 * Whether an HTTP status means the resource is unavailable / failed (not a soft bot-block).
	 *
	 * Used by the scanner and Smart Suggest so we do not treat redirect chains that end on 404 as “OK”
	 * or offer “Apply” targets that are still broken.
	 *
	 * NOTE: 401, 403, 429 are NOT hard-broken — they may be bot-blocks.
	 *
	 * @param int $code HTTP status code.
	 * @return bool
	 */
	public static function is_hard_broken_status( $code ) {
		$code = (int) $code;
		if ( in_array( $code, array( -1, -5, -6, -7 ), true ) ) {
			return false;
		}
		if ( $code <= 0 ) {
			return true;
		}
		// Legacy absint() bug codes.
		if ( in_array( $code, array( 2, 3, 4, 5 ), true ) ) {
			return true;
		}
		$broken = array( 404, 405, 406, 408, 410, 451 );
		if ( in_array( $code, $broken, true ) ) {
			return true;
		}
		if ( $code >= 500 ) {
			return true;
		}
		return false;
	}

	/**
	 * HTTP codes that usually mean the server blocked our checker, not that the URL is broken for visitors.
	 *
	 * @param int $code HTTP status code.
	 * @return bool
	 */
	public static function is_bot_block_status( $code ) {
		return in_array( (int) $code, array( 401, 403, 429 ), true );
	}

	/**
	 * HTTP codes that mean the target resource no longer exists (www toggles cannot fix these).
	 *
	 * @param int $code HTTP status code.
	 * @return bool
	 */
	public static function is_resource_not_found_status( $code ) {
		return in_array( (int) $code, array( 404, 410 ), true );
	}

	/**
	 * Whether a URL is safe to offer with an Apply button (confirmed 2xx from the server, not a bot-block).
	 *
	 * @param array $check_result Result from check(): status_code, is_broken, redirect_url.
	 * @return bool
	 */
	public static function is_actionable_suggestion_result( $check_result ) {
		if ( ! is_array( $check_result ) ) {
			return false;
		}
		if ( ! empty( $check_result['is_broken'] ) ) {
			return false;
		}
		$code = isset( $check_result['status_code'] ) ? (int) $check_result['status_code'] : 0;
		if ( self::is_hard_broken_status( $code ) || self::is_bot_block_status( $code ) ) {
			return false;
		}
		return $code >= 200 && $code < 300;
	}

	/**
	 * Whether a candidate URL is a verified fix for the original (re-checked HTTP, 2xx, not broken).
	 *
	 * @param array $original_check Result from check() on the stored URL.
	 * @param array $candidate_check Result from check() on the proposed URL.
	 * @return bool
	 */
	public static function suggestion_fixes_broken_link( $original_check, $candidate_check, $stored_original_code = 0 ) {
		if ( ! is_array( $candidate_check ) ) {
			return false;
		}
		if ( ! self::is_actionable_suggestion_result( $candidate_check ) ) {
			return false;
		}
		if ( ! is_array( $original_check ) ) {
			return true;
		}
		$orig_code   = isset( $original_check['status_code'] ) ? (int) $original_check['status_code'] : 0;
		$orig_broken = ! empty( $original_check['is_broken'] ) || self::is_hard_broken_status( $orig_code );
		if ( ! $orig_broken && $stored_original_code && self::is_hard_broken_status( (int) $stored_original_code ) ) {
			$orig_broken = true;
			$orig_code   = (int) $stored_original_code;
		}
		if ( ! $orig_broken ) {
			return true;
		}
		// Original is broken: candidate must be a confirmed working URL, not merely “not flagged broken”.
		return self::is_actionable_suggestion_result( $candidate_check );
	}

	/**
	 * Smart URL suggestion: tests https, follows redirects, tries www.
	 *
	 * @param string $url     Original URL.
	 * @param int    $post_id Post ID for relative href resolution.
	 * @return array[]
	 */
	public function smart_suggest( $url, $post_id = 0 ) {
		$url         = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $url ) );
		$url         = TSOLIIN_Scanner::resolve_to_absolute_url( $url, $post_id );
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return array();
		}
		$suggestions = array();
		$tested      = array( $url );
		$r_orig      = $this->check( $url, $post_id );

		// 1. Final destination after following redirects from the original URL (e.g. twitter.com → x.com).
		if ( ! empty( $r_orig['redirect_url'] ) && ! $this->is_trivial_redirect( $url, $r_orig['redirect_url'] ) ) {
			$final = trim( (string) $r_orig['redirect_url'] );
			if ( '' !== $final && ! in_array( $final, $tested, true ) ) {
				$r_final = $this->check( $final, $post_id );
				if ( self::suggestion_fixes_broken_link( $r_orig, $r_final ) ) {
					$final_status = (int) $r_final['status_code'];
					$suggestions[] = array(
						'url'         => $final,
						'status_code' => $final_status,
						'label'       => self::status_label( $final_status, $final ),
						'reason'      => __( 'Final URL after redirects', 'tso-link-inspector' ),
						'confidence'  => 'high',
						'actionable'  => true,
					);
				}
				$tested[] = $final;
			}
		}

		// 2. https upgrade — only when there is no meaningful cross-domain redirect (avoid suggesting https://twitter.com when the real target is x.com).
		$has_meaningful_redirect = ! empty( $r_orig['redirect_url'] ) && ! $this->is_trivial_redirect( $url, $r_orig['redirect_url'] );
		if ( preg_match( '#^http://#i', $url ) && ! $has_meaningful_redirect ) {
			$https = preg_replace( '#^http://#i', 'https://', $url );
			if ( $https && ! in_array( $https, $tested, true ) ) {
				$r = $this->check( $https, $post_id );
				if ( self::suggestion_fixes_broken_link( $r_orig, $r ) ) {
					$target  = $https;
					$reason  = __( 'Secure HTTPS version available', 'tso-link-inspector' );
					$status  = (int) $r['status_code'];

					// HTTPS URL may itself redirect (e.g. legacy host); suggest the real destination, not the hop.
					if ( ! empty( $r['redirect_url'] ) && ! $this->is_trivial_redirect( $https, $r['redirect_url'] ) ) {
						$target = trim( (string) $r['redirect_url'] );
						$reason = __( 'Final URL after redirects', 'tso-link-inspector' );
						$r2     = $this->check( $target, $post_id );
						if ( ! self::suggestion_fixes_broken_link( $r_orig, $r2 ) ) {
							$target = '';
						} else {
							$status = (int) $r2['status_code'];
						}
					}

					if ( '' !== $target && ! in_array( $target, $tested, true ) ) {
						$suggestions[] = array(
							'url'         => $target,
							'status_code' => $status,
							'label'       => self::status_label( $status, $target ),
							'reason'      => $reason,
							'confidence'  => 'high',
							'actionable'  => true,
						);
						$tested[] = $target;
					}
				}
				$tested[] = $https;
			}
		}

		// 3. www variant — never for dead resources (404/410); toggling www cannot restore missing files.
		if ( ! self::is_plain_http_url( $url ) && ! self::is_resource_not_found_status( (int) $r_orig['status_code'] ) ) {
			$parts = wp_parse_url( $url );
			if ( $parts && isset( $parts['host'] ) ) {
				$host   = (string) $parts['host'];
				$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
				$path   = isset( $parts['path'] ) ? $parts['path'] : '/';
				$query  = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
				if ( 0 === strpos( $host, 'www.' ) ) {
					$alt_host = substr( $host, 4 );
				} else {
					$alt_host = 'www.' . $host;
				}
				$alt_url = $scheme . '://' . $alt_host . $path . $query;
				if ( ! in_array( $alt_url, $tested, true ) ) {
					$r = $this->check( $alt_url, $post_id );
					if (
						self::suggestion_fixes_broken_link( $r_orig, $r )
						&& self::is_http_same_resource_bar_www( $url, $alt_url )
					) {
						$suggestions[] = array(
							'url'         => $alt_url,
							'status_code' => (int) $r['status_code'],
							'label'       => self::status_label( (int) $r['status_code'], $alt_url ),
							'reason'      => __( 'www / non-www variant (HTTPS)', 'tso-link-inspector' ),
							'confidence'  => 'medium',
							'actionable'  => true,
						);
					}
				}
			}
		}

		// Deduplicate.
		$seen  = array();
		$dedup = array();
		foreach ( $suggestions as $s ) {
			if ( ! in_array( $s['url'], $seen, true ) ) {
				$dedup[] = $s;
				$seen[]  = $s['url'];
			}
		}

		// Drop HTTP-only “cousin” URLs (e.g. www vs non-www) when the original is HTTP — no real security gain.
		if ( self::is_plain_http_url( $url ) ) {
			$dedup = array_values(
				array_filter(
					$dedup,
					function ( $s ) use ( $url ) {
						if ( ! isset( $s['url'] ) || ! self::is_plain_http_url( $s['url'] ) ) {
							return true;
						}
						return ! self::is_http_same_resource_bar_www( $url, $s['url'] );
					}
				)
			);
		}

		// Canonical HTTPS URLs for plain HTTP links (Facebook etc. may return 401 to bots).
		if ( self::is_plain_http_url( $url ) ) {
			foreach ( $this->build_secure_canonical_candidates( $url ) as $candidate ) {
				if ( in_array( $candidate, $tested, true ) ) {
					continue;
				}
				if ( ! self::is_trusted_canonical_upgrade( $url, $candidate ) ) {
					continue;
				}
				$r          = $this->check( $candidate, $post_id );
				$code       = (int) $r['status_code'];
				$unverified = self::is_bot_wall_host( $candidate ) && self::is_bot_block_status( $code );
				if ( ! $unverified && ! self::suggestion_fixes_broken_link( $r_orig, $r ) ) {
					continue;
				}
				$dedup[]  = array(
					'url'         => $candidate,
					'status_code' => $code,
					'label'       => self::status_label( $code, $candidate ),
					'reason'      => $unverified
						? __( 'Canonical HTTPS URL (server cannot verify — check in browser)', 'tso-link-inspector' )
						: __( 'Secure HTTPS canonical URL', 'tso-link-inspector' ),
					'confidence'  => 'high',
					'actionable'  => true,
					'unverified'  => $unverified,
				);
				$tested[] = $candidate;
			}
		}

		return $dedup;
	}

	/**
	 * Whether the URL uses plain http:// (not https://).
	 *
	 * @param string $url Full URL.
	 * @return bool
	 */
	public static function is_plain_http_url( $url ) {
		$url = trim( (string) $url );
		return (bool) preg_match( '#\Ahttp://#i', $url );
	}

	/**
	 * Whether two URLs point to the same resource except optional “www.” on the host (same scheme).
	 * Used to drop misleading suggestions that only toggle www (HTTP or HTTPS) without fixing errors.
	 *
	 * @param string $a First URL.
	 * @param string $b Second URL.
	 * @return bool
	 */
	public static function is_http_same_resource_bar_www( $a, $b ) {
		$a = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $a ) );
		$b = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $b ) );
		$pa = wp_parse_url( $a );
		$pb = wp_parse_url( $b );
		if ( empty( $pa['host'] ) || empty( $pb['host'] ) ) {
			return false;
		}
		$sa = isset( $pa['scheme'] ) ? strtolower( (string) $pa['scheme'] ) : '';
		$sb = isset( $pb['scheme'] ) ? strtolower( (string) $pb['scheme'] ) : '';
		if ( $sa !== $sb || ! in_array( $sa, array( 'http', 'https' ), true ) ) {
			return false;
		}
		$ha = strtolower( preg_replace( '#^www\.#i', '', $pa['host'] ) );
		$hb = strtolower( preg_replace( '#^www\.#i', '', $pb['host'] ) );
		if ( $ha !== $hb ) {
			return false;
		}
		$patha = isset( $pa['path'] ) ? $pa['path'] : '/';
		$pathb = isset( $pb['path'] ) ? $pb['path'] : '/';
		if ( rtrim( $patha, '/' ) !== rtrim( $pathb, '/' ) ) {
			return false;
		}
		$qa = isset( $pa['query'] ) ? $pa['query'] : '';
		$qb = isset( $pb['query'] ) ? $pb['query'] : '';
		return $qa === $qb;
	}

	/**
	 * Host without leading www (lowercase).
	 *
	 * @param string $host Hostname.
	 * @return string
	 */
	public static function normalize_registrable_host( $host ) {
		return strtolower( preg_replace( '#^www\.#i', '', (string) $host ) );
	}

	/**
	 * Trusted http→https or www canonicalization on the same site (path unchanged).
	 *
	 * @param string $original Stored URL.
	 * @param string $target   Proposed URL.
	 * @return bool
	 */
	public static function is_trusted_canonical_upgrade( $original, $target ) {
		$original = trim( (string) $original );
		$target   = trim( (string) $target );
		if ( '' === $original || '' === $target || $original === $target ) {
			return false;
		}
		$orig_parts = wp_parse_url( $original );
		$tgt_parts  = wp_parse_url( $target );
		if ( empty( $orig_parts['host'] ) || empty( $tgt_parts['host'] ) ) {
			return false;
		}
		if ( self::normalize_registrable_host( $orig_parts['host'] ) !== self::normalize_registrable_host( $tgt_parts['host'] ) ) {
			return false;
		}
		$orig_path = isset( $orig_parts['path'] ) ? rtrim( (string) $orig_parts['path'], '/' ) : '';
		$tgt_path  = isset( $tgt_parts['path'] ) ? rtrim( (string) $tgt_parts['path'], '/' ) : '';
		if ( strtolower( $orig_path ) !== strtolower( $tgt_path ) ) {
			return false;
		}
		$orig_query = isset( $orig_parts['query'] ) ? (string) $orig_parts['query'] : '';
		$tgt_query  = isset( $tgt_parts['query'] ) ? (string) $tgt_parts['query'] : '';
		if ( $orig_query !== $tgt_query ) {
			return false;
		}
		$tgt_scheme = isset( $tgt_parts['scheme'] ) ? strtolower( (string) $tgt_parts['scheme'] ) : '';
		if ( 'https' !== $tgt_scheme ) {
			return false;
		}
		$orig_scheme = isset( $orig_parts['scheme'] ) ? strtolower( (string) $orig_parts['scheme'] ) : '';
		if ( 'http' === $orig_scheme ) {
			return true;
		}
		if ( 'https' === $orig_scheme ) {
			return 0 !== stripos( (string) $orig_parts['host'], 'www.' ) && 0 === stripos( (string) $tgt_parts['host'], 'www.' );
		}
		return false;
	}

	/**
	 * HTTPS canonical URL candidates (scheme + optional www) for the same path.
	 *
	 * @param string $url Original URL.
	 * @return string[]
	 */
	public function build_secure_canonical_candidates( $url ) {
		$url   = trim( (string) $url );
		$parts = wp_parse_url( $url );
		if ( ! $parts || empty( $parts['host'] ) ) {
			return array();
		}
		$path  = isset( $parts['path'] ) ? (string) $parts['path'] : '/';
		$query = isset( $parts['query'] ) ? '?' . $parts['query'] : '';
		$bare  = self::normalize_registrable_host( $parts['host'] );
		$hosts = array();
		if ( in_array( $bare, array( 'facebook.com', 'instagram.com', 'linkedin.com' ), true ) ) {
			$hosts[] = 'www.' . $bare;
		}
		$hosts[] = $bare;
		if ( 0 !== stripos( (string) $parts['host'], 'www.' ) ) {
			$hosts[] = 'www.' . $bare;
		}
		$hosts      = array_values( array_unique( array_filter( $hosts ) ) );
		$candidates = array();
		foreach ( $hosts as $host ) {
			$candidates[] = 'https://' . $host . $path . $query;
		}
		return array_values( array_unique( $candidates ) );
	}

	/**
	 * Whether replacing $original with $target is a useful fix (HTTPS upgrade or canonical domain move).
	 *
	 * @param string $original Original URL.
	 * @param string $target   Proposed destination URL.
	 * @return bool
	 */
	public function is_meaningful_redirect_target( $original, $target ) {
		$original = trim( (string) $original );
		$target   = trim( (string) $target );
		if ( '' === $original || '' === $target || $original === $target ) {
			return false;
		}
		if ( $this->is_query_stripping_redirect( $original, $target ) ) {
			return false;
		}
		if ( $this->is_trivial_redirect( $original, $target ) ) {
			return false;
		}
		if ( self::is_plain_http_url( $original ) && ! self::is_plain_http_url( $target ) ) {
			return true;
		}
		$orig_host = strtolower( (string) wp_parse_url( $original, PHP_URL_HOST ) );
		$target_host = strtolower( (string) wp_parse_url( $target, PHP_URL_HOST ) );
		$orig_host   = preg_replace( '#^www\.#i', '', $orig_host );
		$target_host = preg_replace( '#^www\.#i', '', $target_host );
		return $orig_host !== $target_host;
	}

	/**
	 * Human-readable label for a status code.
	 *
	 * @param int    $code     HTTP status code.
	 * @param string $link_url Optional URL checked (affects labels for successful HTTP responses).
	 * @return string
	 */
	public static function status_label( $code, $link_url = '' ) {
		$code     = (int) $code;
		$link_url = trim( (string) $link_url );
		if ( $link_url && self::is_bot_wall_host( $link_url ) && self::is_bot_block_status( $code ) ) {
			return __( 'Unverifiable (site blocks bots)', 'tso-link-inspector' );
		}
		if ( $link_url && self::is_chrome_webstore_unavailable_url( $link_url ) ) {
			return __( 'Extension unavailable', 'tso-link-inspector' );
		}
		if ( $link_url && self::is_plain_http_url( $link_url ) && $code >= 200 && $code < 300 ) {
			if ( 200 === $code ) {
				return __( 'OK (HTTP — use HTTPS)', 'tso-link-inspector' );
			}
			/* translators: %d: HTTP status code */
			return sprintf( __( '%d OK (HTTP — use HTTPS)', 'tso-link-inspector' ), $code );
		}
		// Legacy rows: -1 was also used when the URL was blocked for safety, not ignore-list.
		if ( -1 === $code && '' !== $link_url && ! self::is_ignored_url( $link_url ) ) {
			return __( 'Blocked (cannot check from server)', 'tso-link-inspector' );
		}
		$labels = array(
			-1   => __( 'Skipped (ignore list)', 'tso-link-inspector' ),
			-6   => __( 'Action link (logout)', 'tso-link-inspector' ),
			-7   => __( 'Blocked (cannot check from server)', 'tso-link-inspector' ),
			0    => __( 'Cannot connect', 'tso-link-inspector' ),
			-2   => __( 'Domain does not exist (DNS)', 'tso-link-inspector' ),
			-3   => __( 'Timed out', 'tso-link-inspector' ),
			-4   => __( 'Connection refused', 'tso-link-inspector' ),
			-5   => __( 'SSL error (server cannot verify)', 'tso-link-inspector' ),
			2    => __( 'Domain does not exist (DNS)', 'tso-link-inspector' ),
			3    => __( 'Timed out', 'tso-link-inspector' ),
			4    => __( 'Connection refused', 'tso-link-inspector' ),
			5    => __( 'SSL error', 'tso-link-inspector' ),
			200  => __( 'OK', 'tso-link-inspector' ),
			301  => __( 'Permanent redirect', 'tso-link-inspector' ),
			302  => __( 'Temporary redirect', 'tso-link-inspector' ),
			303  => __( 'Redirect (See Other)', 'tso-link-inspector' ),
			307  => __( 'Temporary redirect', 'tso-link-inspector' ),
			308  => __( 'Permanent redirect', 'tso-link-inspector' ),
			400  => __( 'Bad request', 'tso-link-inspector' ),
			401  => __( 'Access restricted (bot?)', 'tso-link-inspector' ),
			403  => __( 'Access forbidden (bot?)', 'tso-link-inspector' ),
			404  => __( 'Not found', 'tso-link-inspector' ),
			405  => __( 'Method not allowed', 'tso-link-inspector' ),
			410  => __( 'Permanently removed', 'tso-link-inspector' ),
			429  => __( 'Too many requests (bot?)', 'tso-link-inspector' ),
			500  => __( 'Server error', 'tso-link-inspector' ),
			503  => __( 'Service unavailable', 'tso-link-inspector' ),
		);
		if ( isset( $labels[ $code ] ) ) {
			return $labels[ $code ];
		}
		if ( $code >= 500 ) {
			/* translators: %d: HTTP status code */
			return sprintf( __( 'Server error (%d)', 'tso-link-inspector' ), $code );
		}
		if ( $code >= 400 ) {
			/* translators: %d: HTTP status code */
			return sprintf( __( 'Client error (%d)', 'tso-link-inspector' ), $code );
		}
		if ( $code > 0 ) {
			/* translators: %d: HTTP status code */
			return sprintf( __( 'Code %d', 'tso-link-inspector' ), $code );
		}
		return __( 'Not checked', 'tso-link-inspector' );
	}

	/**
	 * CSS class for a status badge.
	 *
	 * @param int    $code      HTTP status code.
	 * @param int    $is_broken 1 if broken.
	 * @param string $link_url  Optional URL checked (plain http:// + 2xx uses warning styling).
	 * @return string
	 */
	public static function status_class( $code, $is_broken, $link_url = '' ) {
		$code      = (int) $code;
		$is_broken = (int) $is_broken;
		$link_url  = trim( (string) $link_url );

		if ( in_array( $code, array( -1, -6, -7 ), true ) && ! $is_broken ) {
			return 'tsoliin-status--skipped';
		}
		if ( -5 === $code && ! $is_broken ) {
			return 'tsoliin-status--warning';
		}
		if ( 0 === $code && ! $is_broken ) {
			return 'tsoliin-status--unknown';
		}
		if ( $code < 0 || in_array( $code, array( 2, 3, 4, 5 ), true ) ) {
			return 'tsoliin-status--broken';
		}
		if ( $is_broken ) {
			return 'tsoliin-status--broken';
		}
		if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
			return 'tsoliin-status--redirect';
		}
		if ( in_array( $code, array( 401, 403, 429 ), true ) ) {
			return 'tsoliin-status--warning';
		}
		// Reachable over HTTP but not TLS — not the same as a secure "OK".
		if ( $code >= 200 && $code < 300 && self::is_plain_http_url( $link_url ) ) {
			return 'tsoliin-status--warning';
		}
		if ( $code >= 200 && $code < 300 ) {
			return 'tsoliin-status--ok';
		}
		return 'tsoliin-status--unknown';
	}

	/**
	 * Detect if a redirect is trivially different and not worth reporting.
	 *
	 * Covers:
	 *  - Trailing slash added/removed  (/article → /article/)
	 *  - Same host/path except one interior segment dropped (e.g. /blog2/category/post → /blog2/post)
	 *  - Bare host vs www + tracking-only query (youtube.com/x → www.youtube.com/x?ucbcb=1&cbrd=1)
	 *  - Same article path with/without www (case- or encoding-normalised paths)
	 *  - Chrome Web Store legacy host → chromewebstore.google.com (same extension path)
	 *  - LiberKey /{lang}/catalog/… → /{lang}.html (retired catalog → locale home)
	 *  - Stable “latest” vendor download (/dl/…) → versioned installer on same vendor host (Telegram)
	 *  - WordPress attachment page → media file (/post-slug/ → /wp-content/uploads/file.jpg)
	 *  - Asset/static file served from CDN subdomain (pixel.gif → cdn.paypalobjects.com/pixel.gif)
	 *  - Direct download link → CDN with token (/dl/apk → cdn.example.com/file?token=xxx)
	 *
	 * @param string $original Original URL (without fragment, without query strip).
	 * @param string $final    Final URL after redirect chain.
	 * @return bool True if redirect is transparent/trivial and should not be reported.
	 */
	private function is_trivial_redirect( $original, $final ) {
		$a = rtrim( $original, '/' );
		$b = rtrim( $final, '/' );

		// 1. Only trailing slash differs.
		if ( $a === $b ) {
			return true;
		}

		// 1b. One interior path segment removed on the same host (common after permalink / category-base changes).
		if ( $this->is_single_interior_path_segment_removed_redirect( $original, $final ) ) {
			return true;
		}

		// 2. WordPress attachment post URL → wp-content/uploads media file.
		// e.g. /blog/my-post/image-name/ → /blog/wp-content/uploads/2013/10/image.jpg
		if ( false !== strpos( $final, '/wp-content/uploads/' ) ) {
			$ext = strtolower( (string) pathinfo( wp_parse_url( $final, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$media_ext = array( 'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'mp4', 'mp3', 'pdf', 'zip' );
			if ( in_array( $ext, $media_ext, true ) ) {
				return true; // WP attachment page → media file, transparent.
			}
		}

		// 3. Static asset (image, script, font) served from a CDN subdomain of the same host.
		// e.g. www.paypal.com/i/scr/pixel.gif → www.paypalobjects.com/es_ES/i/scr/pixel.gif
		$orig_parts  = wp_parse_url( $original );
		$final_parts = wp_parse_url( $final );
		if ( $orig_parts && $final_parts && isset( $orig_parts['path'], $final_parts['path'] ) ) {
			$orig_path  = $orig_parts['path'];
			$final_path = $final_parts['path'];
			$orig_ext   = strtolower( (string) pathinfo( $orig_path, PATHINFO_EXTENSION ) );
			$static_ext = array( 'gif', 'png', 'jpg', 'jpeg', 'webp', 'svg', 'js', 'css', 'woff', 'woff2', 'ttf' );
			if ( in_array( $orig_ext, $static_ext, true ) ) {
				// Same file extension and same filename.
				if ( basename( $orig_path ) === basename( $final_path ) ) {
					return true; // Asset redirect to CDN, transparent.
				}
			}
		}

		// 4. Direct download link → CDN/token URL.
		// Detect: original has no query string, is a short "download" URL,
		// final has a very long query string (token, signature, etc.).
		$orig_query  = isset( $orig_parts['query'] ) ? $orig_parts['query'] : '';
		$final_query = isset( $final_parts['query'] ) ? $final_parts['query'] : '';
		if ( '' === $orig_query && strlen( $final_query ) > 100 ) {
			// Original is a clean download URL, final has a long CDN token.
			$dl_patterns = array( '/dl/', '/download/', '/get/', '/file/' );
			foreach ( $dl_patterns as $p ) {
				if ( false !== strpos( strtolower( $original ), $p ) ) {
					return true; // Download redirector → CDN token, transparent.
				}
			}
			$orig_ext2 = strtolower( (string) pathinfo( isset( $orig_parts['path'] ) ? $orig_parts['path'] : '', PATHINFO_EXTENSION ) );
			$dl_ext    = array( 'apk', 'exe', 'dmg', 'msi', 'zip', 'tar', 'gz', 'ipa' );
			if ( in_array( $orig_ext2, $dl_ext, true ) ) {
				return true; // Direct file download → CDN signed URL, transparent.
			}
		}

		// 5. Tracking/consent query parameter appended to original URL.
		// e.g. example.com/page → example.com/page?ucbcb=1  (same host+path, just query added)
		// Only applies when the original URL had NO query string.
		if ( $orig_parts && $final_parts ) {
			$orig_scheme = isset( $orig_parts['scheme'] ) ? $orig_parts['scheme'] : '';
			$final_scheme= isset( $final_parts['scheme'] ) ? $final_parts['scheme'] : '';
			$orig_host   = isset( $orig_parts['host'] ) ? $orig_parts['host'] : '';
			$final_host  = isset( $final_parts['host'] ) ? $final_parts['host'] : '';
			$orig_qry    = isset( $orig_parts['query'] ) ? $orig_parts['query'] : '';
			$orig_pth    = isset( $orig_parts['path'] ) ? rtrim( rawurldecode( (string) $orig_parts['path'] ), '/' ) : '';
			$final_pth   = isset( $final_parts['path'] ) ? rtrim( rawurldecode( (string) $final_parts['path'] ), '/' ) : '';
			if (
				'' === $orig_qry &&
				$orig_scheme === $final_scheme &&
				$orig_host   === $final_host &&
				strtolower( $orig_pth ) === strtolower( $final_pth )
			) {
				// Only query string was added (e.g. tracking/consent params). Transparent redirect.
				return true;
			}
		}

		// 6. Same site with/without www (or http→https) and same path; final query is empty or only noise params.
		if ( $this->is_same_registrable_host_www_variant_with_noise_query( $original, $final ) ) {
			return true;
		}

		// 7. Stable “latest” download URL → versioned installer on vendor CDN (e.g. telegram.org/dl/... → td.telegram.org/tsetup-x.y.z.exe).
		// Replacing the public URL would pin the post to one file version; keep treating as OK without redirect noise.
		if ( $this->is_latest_channel_installer_redirect( $original, $final ) ) {
			return true;
		}

		// 8. Google Chrome Web Store host migration (legacy chrome.google.com/webstore/... → chromewebstore.google.com/detail/...).
		if ( $this->is_chrome_webstore_migration_redirect( $original, $final ) ) {
			return true;
		}

		// 9. LiberKey retired catalog URLs → language home (same host; old marketing paths only).
		if ( $this->is_liberkey_catalog_to_locale_home_redirect( $original, $final ) ) {
			return true;
		}

		// 10. YouTube short/share links → watch URL for the same video (youtu.be/ID, /shorts/ID, etc.).
		if ( $this->is_youtube_same_video_redirect( $original, $final ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether a redirect drops meaningful query/search parameters (common bot or anti-scraper behaviour).
	 *
	 * Example: search.php?stext=Jake+Gyllenhaal → advsearch2.php?q= (empty) on the same site.
	 *
	 * @param string $original Original URL.
	 * @param string $final    Destination after redirects.
	 * @return bool
	 */
	private function is_query_stripping_redirect( $original, $final ) {
		$orig_parts  = wp_parse_url( $original );
		$final_parts = wp_parse_url( $final );
		if ( ! $orig_parts || ! $final_parts || empty( $orig_parts['host'] ) || empty( $final_parts['host'] ) ) {
			return false;
		}

		$orig_host = strtolower( preg_replace( '#^www\.#i', '', (string) $orig_parts['host'] ) );
		$fin_host  = strtolower( preg_replace( '#^www\.#i', '', (string) $final_parts['host'] ) );
		if ( $orig_host !== $fin_host ) {
			return false;
		}

		$orig_query = isset( $orig_parts['query'] ) ? (string) $orig_parts['query'] : '';
		if ( '' === $orig_query ) {
			return false;
		}

		parse_str( $orig_query, $orig_params );
		$meaningful_orig = $this->get_meaningful_query_params( $orig_params );
		if ( empty( $meaningful_orig ) ) {
			return false;
		}

		$final_query = isset( $final_parts['query'] ) ? (string) $final_parts['query'] : '';
		parse_str( $final_query, $final_params );
		$meaningful_final = $this->get_meaningful_query_params( $final_params );

		if ( empty( $meaningful_final ) ) {
			if ( $this->redirect_lost_search_intent( $meaningful_orig, array() ) ) {
				return true;
			}
			return $this->is_search_endpoint_path_swap( $orig_parts, $final_parts );
		}

		return $this->redirect_lost_search_intent( $meaningful_orig, $meaningful_final );
	}

	/**
	 * Whether redirect moves between search-style paths on the same host (often anti-bot).
	 *
	 * @param array|false $orig_parts  wp_parse_url() of original.
	 * @param array|false $final_parts wp_parse_url() of destination.
	 * @return bool
	 */
	private function is_search_endpoint_path_swap( $orig_parts, $final_parts ) {
		if ( ! is_array( $orig_parts ) || ! is_array( $final_parts ) ) {
			return false;
		}
		$orig_path  = strtolower( rtrim( (string) ( $orig_parts['path'] ?? '' ), '/' ) );
		$final_path = strtolower( rtrim( (string) ( $final_parts['path'] ?? '' ), '/' ) );
		if ( '' === $orig_path || '' === $final_path || $orig_path === $final_path ) {
			return false;
		}
		return $this->path_looks_like_search_endpoint( $orig_path )
			&& $this->path_looks_like_search_endpoint( $final_path );
	}

	/**
	 * Whether a URL path segment looks like a site search endpoint.
	 *
	 * @param string $path Lowercased URL path.
	 * @return bool
	 */
	private function path_looks_like_search_endpoint( $path ) {
		$patterns = array( 'search', 'find', 'buscar', 'busqueda', 'advsearch', 'lookup' );
		foreach ( $patterns as $needle ) {
			if ( false !== strpos( $path, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a redirect from a #fragment URL stayed on the same page (path + host).
	 *
	 * @param string $original Original URL (fragment already stripped for HTTP).
	 * @param string $final    Final URL after redirects.
	 * @return bool
	 */
	private function is_fragment_same_page_redirect( $original, $final ) {
		$orig_parts  = wp_parse_url( $original );
		$final_parts = wp_parse_url( $final );
		if ( ! $orig_parts || ! $final_parts || empty( $orig_parts['host'] ) || empty( $final_parts['host'] ) ) {
			return false;
		}
		$orig_host = strtolower( preg_replace( '#^www\.#i', '', (string) $orig_parts['host'] ) );
		$fin_host  = strtolower( preg_replace( '#^www\.#i', '', (string) $final_parts['host'] ) );
		if ( $orig_host !== $fin_host ) {
			return false;
		}
		$orig_path = isset( $orig_parts['path'] ) ? rtrim( rawurldecode( (string) $orig_parts['path'] ), '/' ) : '';
		$fin_path  = isset( $final_parts['path'] ) ? rtrim( rawurldecode( (string) $final_parts['path'] ), '/' ) : '';
		$orig_q    = isset( $orig_parts['query'] ) ? (string) $orig_parts['query'] : '';
		$fin_q     = isset( $final_parts['query'] ) ? (string) $final_parts['query'] : '';
		return strtolower( $orig_path ) === strtolower( $fin_path ) && $orig_q === $fin_q;
	}

	/**
	 * Query parameters with non-empty values, excluding tracking/noise keys.
	 *
	 * @param array $params Parsed query parameters.
	 * @return array<string, string>
	 */
	private function get_meaningful_query_params( array $params ) {
		$out = array();
		foreach ( $params as $key => $value ) {
			if ( is_array( $value ) ) {
				continue;
			}
			$key = strtolower( sanitize_key( (string) $key ) );
			if ( '' === $key || $this->is_noise_query_param_key( $key ) ) {
				continue;
			}
			$value = trim( (string) $value );
			if ( '' !== $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Whether redirect destination no longer carries original search terms.
	 *
	 * @param array<string, string> $orig  Meaningful original params.
	 * @param array<string, string> $final Meaningful destination params.
	 * @return bool
	 */
	private function redirect_lost_search_intent( array $orig, array $final ) {
		$search_keys = array( 'stext', 'text', 'q', 'query', 'search', 's', 'keyword', 'term', 'stype', 'type', 'name' );
		$orig_terms  = array();

		foreach ( $orig as $key => $value ) {
			$key = strtolower( (string) $key );
			if ( ! in_array( $key, $search_keys, true ) && ! preg_match( '/(?:search|query|keyword|text|term)/', $key ) ) {
				continue;
			}
			$term = strtolower( rawurldecode( (string) $value ) );
			$term = preg_replace( '/\s+/', ' ', trim( $term ) );
			if ( strlen( $term ) >= 2 ) {
				$orig_terms[] = $term;
			}
		}

		if ( empty( $orig_terms ) ) {
			return false;
		}

		$final_blob = strtolower( implode( ' ', array_map( 'rawurldecode', array_values( $final ) ) ) );
		foreach ( $orig_terms as $term ) {
			if ( false !== strpos( $final_blob, $term ) ) {
				return false;
			}
			// Also match if a significant word from multi-word search appears in final params.
			foreach ( preg_split( '/\s+/', $term ) as $word ) {
				if ( strlen( $word ) >= 4 && false !== strpos( $final_blob, $word ) ) {
					return false;
				}
			}
		}

		return true;
	}

	/**
	 * Extract a YouTube video ID from common URL shapes (youtu.be, /watch?v=, /embed/, /shorts/).
	 *
	 * @param string $url URL.
	 * @return string Eleven-character ID or empty string.
	 */
	private function extract_youtube_video_id( $url ) {
		$parts = wp_parse_url( (string) $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host = strtolower( (string) $parts['host'] );
		$path = isset( $parts['path'] ) ? trim( (string) $parts['path'], '/' ) : '';

		if ( preg_match( '/(^|\.)youtu\.be$/', $host ) && '' !== $path ) {
			$slug = strtok( $path, '/' );
			return $this->sanitize_youtube_video_id( $slug );
		}

		if ( ! preg_match( '/(^|\.)(youtube\.com|youtube-nocookie\.com|m\.youtube\.com)$/', $host ) ) {
			return '';
		}

		if ( ! empty( $parts['query'] ) ) {
			parse_str( (string) $parts['query'], $query );
			if ( ! empty( $query['v'] ) ) {
				return $this->sanitize_youtube_video_id( (string) $query['v'] );
			}
		}

		if ( preg_match( '#^(?:embed|shorts|v|live)/([^/?]+)#', $path, $matches ) ) {
			return $this->sanitize_youtube_video_id( $matches[1] );
		}

		return '';
	}

	/**
	 * @param string $id Raw candidate ID.
	 * @return string
	 */
	private function sanitize_youtube_video_id( $id ) {
		$id = trim( (string) $id );
		return preg_match( '/^[a-zA-Z0-9_-]{11}$/', $id ) ? $id : '';
	}

	/**
	 * Whether a redirect only expands a YouTube short link to the same video watch page.
	 *
	 * @param string $original Original URL.
	 * @param string $final    Final URL after redirects.
	 * @return bool
	 */
	private function is_youtube_same_video_redirect( $original, $final ) {
		$id_orig  = $this->extract_youtube_video_id( $original );
		$id_final = $this->extract_youtube_video_id( $final );
		return ( '' !== $id_orig && $id_orig === $id_final );
	}

	/**
	 * Same scheme + host + query; final path is the original path with exactly one non-final segment removed.
	 *
	 * Example: /blog2/android/post-slug → /blog2/post-slug (drops a single folder such as a former category segment).
	 * Does not treat dropping the last segment as trivial (/a/b/c → /a/b).
	 *
	 * @param string $original Original URL (no fragment).
	 * @param string $final    Final URL after redirects.
	 * @return bool
	 */
	private function is_single_interior_path_segment_removed_redirect( $original, $final ) {
		$o = wp_parse_url( $original );
		$f = wp_parse_url( $final );
		if ( ! $o || ! $f || empty( $o['host'] ) || empty( $f['host'] ) ) {
			return false;
		}
		if ( strtolower( (string) $o['host'] ) !== strtolower( (string) $f['host'] ) ) {
			return false;
		}
		$scheme_o = isset( $o['scheme'] ) ? strtolower( (string) $o['scheme'] ) : '';
		$scheme_f = isset( $f['scheme'] ) ? strtolower( (string) $f['scheme'] ) : '';
		if ( '' === $scheme_o || $scheme_o !== $scheme_f ) {
			return false;
		}
		$q_o = isset( $o['query'] ) ? (string) $o['query'] : '';
		$q_f = isset( $f['query'] ) ? (string) $f['query'] : '';
		if ( $q_o !== $q_f ) {
			return false;
		}
		$path_o = isset( $o['path'] ) ? trim( rawurldecode( (string) $o['path'] ), '/' ) : '';
		$path_f = isset( $f['path'] ) ? trim( rawurldecode( (string) $f['path'] ), '/' ) : '';
		if ( '' === $path_o || '' === $path_f ) {
			return false;
		}
		$seg_o = array_values(
			array_filter(
				explode( '/', $path_o ),
				static function ( $p ) {
					return '' !== (string) $p;
				}
			)
		);
		$seg_f = array_values(
			array_filter(
				explode( '/', $path_f ),
				static function ( $p ) {
					return '' !== (string) $p;
				}
			)
		);
		$n_o   = count( $seg_o );
		$n_f   = count( $seg_f );
		if ( $n_o !== $n_f + 1 || $n_f < 1 ) {
			return false;
		}
		$norm = static function ( array $segments ) {
			return array_map(
				static function ( $p ) {
					return strtolower( (string) $p );
				},
				$segments
			);
		};
		$seg_f_n = $norm( $seg_f );
		for ( $i = 0; $i < $n_o; $i++ ) {
			if ( $i === $n_o - 1 ) {
				continue;
			}
			$candidate = $seg_o;
			array_splice( $candidate, $i, 1 );
			if ( $norm( $candidate ) === $seg_f_n ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether two stored redirect outcomes are the same for user-verify locking (handles trivial redirects vs empty).
	 *
	 * @param string $link_url          Stored href for the row.
	 * @param string $baseline_redirect Redirect URL saved when the user verified (may be empty).
	 * @param string $new_redirect      Redirect URL from the latest check (may be empty).
	 * @return bool
	 */
	public function redirect_outcomes_match_for_verify( $link_url, $baseline_redirect, $new_redirect ) {
		$a    = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $baseline_redirect ) );
		$b    = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $new_redirect ) );
		$link = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $link_url ) );
		if ( self::normalize_redirect_for_verify_compare( $a ) === self::normalize_redirect_for_verify_compare( $b ) ) {
			return true;
		}
		if ( '' === $a && '' !== $b ) {
			return $this->is_trivial_redirect( $link, $b );
		}
		if ( '' !== $a && '' === $b ) {
			return $this->is_trivial_redirect( $link, $a );
		}
		return false;
	}

	/**
	 * Whether a URL belongs to the Google Chrome Web Store (current or legacy host).
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	public static function is_chrome_webstore_host( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return in_array( $host, array( 'chromewebstore.google.com', 'chrome.google.com' ), true );
	}

	/**
	 * Extract a Chrome Web Store extension ID from a store URL path.
	 *
	 * @param string $url Store URL.
	 * @return string 32-character extension ID, or empty string.
	 */
	public static function extract_chrome_webstore_extension_id( $url ) {
		$path = self::normalize_chrome_webstore_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		if ( preg_match( '#/detail/(?:empty-title|[^/]+)/([a-p]{32})(?:/|$)#i', $path, $matches ) ) {
			return strtolower( $matches[1] );
		}
		if ( preg_match( '#/detail/([a-p]{32})(?:/error)?/?$#i', $path, $matches ) ) {
			return strtolower( $matches[1] );
		}
		return '';
	}

	/**
	 * Normalize legacy /webstore/ prefix on Chrome Web Store paths.
	 *
	 * @param string $path URL path.
	 * @return string
	 */
	public static function normalize_chrome_webstore_path( $path ) {
		return (string) preg_replace( '#^/webstore(?=/|$)#', '', (string) $path );
	}

	/**
	 * Whether the store URL is Google's placeholder for a removed/unavailable extension.
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	public static function is_chrome_webstore_empty_title_url( $url ) {
		$path = self::normalize_chrome_webstore_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return false !== stripos( $path, '/detail/empty-title/' );
	}

	/**
	 * Whether the store URL is the client-side “item not available” error route.
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	public static function is_chrome_webstore_error_url( $url ) {
		$path = self::normalize_chrome_webstore_path( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return (bool) preg_match( '#/detail/[a-p]{32}/error/?$#i', $path );
	}

	/**
	 * Whether a Chrome Web Store URL points to an unavailable extension (soft 404).
	 *
	 * @param string $url URL to test.
	 * @return bool
	 */
	public static function is_chrome_webstore_unavailable_url( $url ) {
		if ( ! self::is_chrome_webstore_host( $url ) ) {
			return false;
		}
		return self::is_chrome_webstore_empty_title_url( $url ) || self::is_chrome_webstore_error_url( $url );
	}

	/**
	 * Whether a redirect replaced the human slug with Google's empty-title placeholder (same extension ID).
	 *
	 * @param string $original Original request URL.
	 * @param string $final    Final URL after redirects.
	 * @return bool
	 */
	private function is_chrome_webstore_removed_extension_redirect( $original, $final ) {
		if ( ! self::is_chrome_webstore_host( $original ) || ! self::is_chrome_webstore_host( $final ) ) {
			return false;
		}
		$orig_id  = self::extract_chrome_webstore_extension_id( $original );
		$final_id = self::extract_chrome_webstore_extension_id( $final );
		if ( '' === $orig_id || $orig_id !== $final_id ) {
			return false;
		}
		return self::is_chrome_webstore_empty_title_url( $final ) && ! self::is_chrome_webstore_empty_title_url( $original );
	}

	/**
	 * Build a broken check result for unavailable Chrome Web Store extensions.
	 *
	 * Google often returns HTTP 200 on /detail/empty-title/{id} even though the item is gone.
	 *
	 * @param string $url            URL that is unavailable.
	 * @param array  $redirect_chain Optional redirect hops.
	 * @return array|null Check result, or null when the URL is not an unavailable store item.
	 */
	private function maybe_chrome_webstore_unavailable_result( $url, $redirect_chain = array() ) {
		if ( ! self::is_chrome_webstore_unavailable_url( $url ) ) {
			return null;
		}
		return array(
			'status_code'    => 404,
			'redirect_url'   => '',
			'redirect_chain' => $redirect_chain,
			'is_broken'      => 1,
		);
	}

	/**
	 * Detect redirect from a named extension page to empty-title (removed from store).
	 *
	 * @param string $original       Original request URL.
	 * @param string $final          Final URL after redirects.
	 * @param array  $redirect_chain Redirect hops.
	 * @return array|null
	 */
	private function maybe_chrome_webstore_removed_redirect_result( $original, $final, $redirect_chain = array() ) {
		if ( ! $this->is_chrome_webstore_removed_extension_redirect( $original, $final ) ) {
			return null;
		}
		return $this->maybe_chrome_webstore_unavailable_result( $final, $redirect_chain );
	}

	/**
	 * Legacy Web Store hostname/path → chromewebstore.google.com (same extension path; optional consent query).
	 *
	 * @param string $original Original URL.
	 * @param string $final    Final URL.
	 * @return bool
	 */
	private function is_chrome_webstore_migration_redirect( $original, $final ) {
		$o = wp_parse_url( $original );
		$f = wp_parse_url( $final );
		if ( ! $o || ! $f || empty( $o['host'] ) || empty( $f['host'] ) ) {
			return false;
		}
		$oh = strtolower( (string) $o['host'] );
		$fh = strtolower( (string) $f['host'] );
		if ( 'chrome.google.com' !== $oh || 'chromewebstore.google.com' !== $fh ) {
			return false;
		}
		$op = isset( $o['path'] ) ? (string) $o['path'] : '';
		$fp = isset( $f['path'] ) ? (string) $f['path'] : '';
		// Old URLs used /webstore/detail/... — new store drops the /webstore prefix.
		$op_norm = rtrim( rawurldecode( preg_replace( '#^/webstore(?=/|$)#', '', $op ) ), '/' );
		$fp_norm = rtrim( rawurldecode( $fp ), '/' );
		if ( strtolower( $op_norm ) !== strtolower( $fp_norm ) ) {
			return false;
		}
		return $this->query_differs_only_by_noise_params( $o, $f );
	}

	/**
	 * LiberKey used to expose long /{lang}/catalog/... URLs that now 303 to /{lang}.html.
	 *
	 * @param string $original Original URL.
	 * @param string $final    Final URL.
	 * @return bool
	 */
	private function is_liberkey_catalog_to_locale_home_redirect( $original, $final ) {
		$o = wp_parse_url( $original );
		$f = wp_parse_url( $final );
		if ( ! $o || ! $f || empty( $o['host'] ) || empty( $f['host'] ) ) {
			return false;
		}
		$norm_host = static function ( $h ) {
			return preg_replace( '/^www\./', '', strtolower( (string) $h ) );
		};
		if ( 'liberkey.com' !== $norm_host( $o['host'] ) || 'liberkey.com' !== $norm_host( $f['host'] ) ) {
			return false;
		}
		$op = strtolower( rawurldecode( (string) ( $o['path'] ?? '' ) ) );
		if ( false === strpos( $op, '/catalog/' ) && false === strpos( $op, 'browse.html' ) ) {
			return false;
		}
		$fp = strtolower( rtrim( rawurldecode( (string) ( $f['path'] ?? '' ) ), '/' ) );
		// e.g. /en.html, /es.html, /fr/ (short locale entry only).
		if ( preg_match( '#^/[a-z]{2}(\.html)?$#', $fp ) ) {
			return true;
		}
		return false;
	}

	/**
	 * Stable public download path that always redirects to a versioned binary (rolling releases).
	 *
	 * @param string $original Original request URL (no fragment).
	 * @param string $final    Final URL after redirect chain.
	 * @return bool
	 */
	private function is_latest_channel_installer_redirect( $original, $final ) {
		$o = wp_parse_url( $original );
		$f = wp_parse_url( $final );
		if ( ! $o || ! $f || empty( $o['host'] ) || empty( $f['host'] ) || empty( $f['path'] ) ) {
			return false;
		}

		$oh   = strtolower( (string) $o['host'] );
		$fh   = strtolower( (string) $f['host'] );
		$op   = isset( $o['path'] ) ? (string) $o['path'] : '';
		$fpath = (string) $f['path'];
		$fleaf = strtolower( (string) pathinfo( $fpath, PATHINFO_BASENAME ) );

		if ( ! preg_match( '/\.(exe|dmg|pkg|msi|deb|rpm|zip)(\?|$)/i', $fleaf ) ) {
			return false;
		}

		// Require a version-like segment in the filename (x.y.z or tool-1.2.ext).
		if ( ! preg_match( '/\d+\.\d+/', $fleaf ) ) {
			return false;
		}

		// Telegram official “latest desktop” URLs under /dl/ → *.telegram.org versioned tsetup / tx64 builds.
		if ( preg_match( '/(^|\.)telegram\.org$/', $oh ) && false !== strpos( $op, '/dl/' ) ) {
			if ( preg_match( '/(^|\.)telegram\.org$/', $fh ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether a redirect should be treated as transparent (no redirect tab / no “replace with final URL” suggestion).
	 *
	 * @param string $original Original URL.
	 * @param string $final    Destination URL.
	 * @return bool
	 */
	public function is_transparent_redirect( $original, $final ) {
		return $this->is_trivial_redirect( (string) $original, (string) $final )
			|| $this->is_query_stripping_redirect( (string) $original, (string) $final );
	}

	/**
	 * Normalize a check result before storing in the DB (transparent redirects → OK, no redirect_url).
	 *
	 * @param string $link_url     Original link URL in the post.
	 * @param int    $status_code  HTTP status from check().
	 * @param string $redirect_url Redirect destination from check().
	 * @param int    $is_broken    Broken flag from check().
	 * @return array{status_code:int,redirect_url:string,is_broken:int}
	 */
	public static function normalize_stored_check_result( $link_url, $status_code, $redirect_url, $is_broken ) {
		$link_url     = trim( (string) $link_url );
		$redirect_url = trim( (string) $redirect_url );
		$is_broken    = (int) $is_broken;
		$status_code  = (int) $status_code;

		if ( '' !== $redirect_url && '' !== $link_url ) {
			$http = new self();
			if ( $http->is_transparent_redirect( $link_url, $redirect_url ) ) {
				return array(
					'status_code'  => 200,
					'redirect_url' => '',
					'is_broken'    => 0,
				);
			}
		}

		return array(
			'status_code'  => $status_code,
			'redirect_url' => $redirect_url,
			'is_broken'    => $is_broken ? 1 : 0,
		);
	}

	/**
	 * True when final URL is the same resource as original except for www on the host
	 * and/or harmless query parameters (YouTube consent, UTM, click ids, etc.).
	 *
	 * @param string $original Original URL (no fragment).
	 * @param string $final    Final URL after redirects.
	 * @return bool
	 */
	private function is_same_registrable_host_www_variant_with_noise_query( $original, $final ) {
		$orig_parts  = wp_parse_url( $original );
		$final_parts = wp_parse_url( $final );
		if ( ! $orig_parts || ! $final_parts || empty( $orig_parts['host'] ) || empty( $final_parts['host'] ) ) {
			return false;
		}

		$orig_host = strtolower( (string) $orig_parts['host'] );
		$fin_host  = strtolower( (string) $final_parts['host'] );
		$norm_orig = preg_replace( '/^www\./', '', $orig_host );
		$norm_fin  = preg_replace( '/^www\./', '', $fin_host );
		if ( $norm_orig !== $norm_fin ) {
			return false;
		}

		$orig_scheme = isset( $orig_parts['scheme'] ) ? strtolower( (string) $orig_parts['scheme'] ) : '';
		$fin_scheme  = isset( $final_parts['scheme'] ) ? strtolower( (string) $final_parts['scheme'] ) : '';
		if ( $orig_scheme !== $fin_scheme ) {
			if ( ! ( 'http' === $orig_scheme && 'https' === $fin_scheme ) ) {
				return false;
			}
		}

		$orig_path = isset( $orig_parts['path'] ) ? rtrim( rawurldecode( (string) $orig_parts['path'] ), '/' ) : '';
		$fin_path  = isset( $final_parts['path'] ) ? rtrim( rawurldecode( (string) $final_parts['path'] ), '/' ) : '';
		if ( strtolower( $orig_path ) !== strtolower( $fin_path ) ) {
			return false;
		}

		return $this->query_differs_only_by_noise_params( $orig_parts, $final_parts );
	}

	/**
	 * Whether the query on $final is empty or adds only noise keys compared to $original.
	 *
	 * @param array $orig_parts  Result of wp_parse_url() on original URL.
	 * @param array $final_parts Result of wp_parse_url() on final URL.
	 * @return bool
	 */
	private function query_differs_only_by_noise_params( $orig_parts, $final_parts ) {
		$orig_q = isset( $orig_parts['query'] ) ? (string) $orig_parts['query'] : '';
		$fin_q  = isset( $final_parts['query'] ) ? (string) $final_parts['query'] : '';

		$orig_params = array();
		$fin_params  = array();
		if ( '' !== $orig_q ) {
			parse_str( $orig_q, $orig_params );
		}
		if ( '' !== $fin_q ) {
			parse_str( $fin_q, $fin_params );
		}
		if ( ! is_array( $orig_params ) ) {
			$orig_params = array();
		}
		if ( ! is_array( $fin_params ) ) {
			$fin_params = array();
		}

		foreach ( $orig_params as $key => $val ) {
			if ( ! array_key_exists( $key, $fin_params ) ) {
				return false;
			}
			if ( is_array( $val ) || is_array( $fin_params[ $key ] ) ) {
				return false;
			}
			if ( (string) $fin_params[ $key ] !== (string) $val ) {
				return false;
			}
		}

		foreach ( $fin_params as $key => $val ) {
			if ( array_key_exists( $key, $orig_params ) ) {
				continue;
			}
			if ( is_array( $val ) ) {
				return false;
			}
			if ( ! $this->is_noise_query_param_key( $key ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Query parameter names that do not change the linked resource (consent, campaign, click ids).
	 *
	 * @param string $key Parameter name.
	 * @return bool
	 */
	private function is_noise_query_param_key( $key ) {
		$k = strtolower( (string) $key );
		$k = preg_replace( '/\[[^\]]*\]$/', '', $k );

		if ( 0 === strpos( $k, 'utm_' ) ) {
			return true;
		}

		$noise = array(
			'ucbcb',
			'cbrd',
			'gclid',
			'fbclid',
			'dclid',
			'msclkid',
			'mc_cid',
			'mc_eid',
			'_ga',
			'ref',
			'igshid',
			'si',
			'spm',
			'ved',
			'usg',
			'ocid',
			'ncid',
			'ns_source',
			'ns_campaign',
			'ns_mchannel',
			'feature',
		);

		return in_array( $k, $noise, true );
	}

	/**
	 * Detect if a redirect destination is an auth/login wall.
	 * Facebook, Google, and others redirect unauthenticated users to login pages.
	 * These are bot-blocks, not real URL changes.
	 *
	 * @param string $final Final redirect URL.
	 * @return bool
	 */
	private function is_auth_redirect( $final ) {
		$final = (string) $final;
		if ( '' === $final ) {
			return false;
		}

		$parts = wp_parse_url( $final );
		$host  = isset( $parts['host'] ) ? strtolower( (string) $parts['host'] ) : '';
		$path  = isset( $parts['path'] ) ? strtolower( (string) $parts['path'] ) : '';

		$auth_hosts = array(
			'accounts.google.com',
			'login.microsoftonline.com',
		);
		foreach ( $auth_hosts as $auth_host ) {
			$suffix = '.' . $auth_host;
			if ( $host === $auth_host || ( '' !== $host && strlen( $host ) > strlen( $suffix ) && substr( $host, -strlen( $suffix ) ) === $suffix ) ) {
				return true;
			}
		}

		if ( false !== strpos( $path, '/wp-login.php' ) ) {
			return true;
		}

		$auth_path_segments = array( 'login', 'signin', 'sign-in', 'auth' );
		foreach ( $auth_path_segments as $seg ) {
			if ( preg_match( '#/' . preg_quote( $seg, '#' ) . '(?:/|$|\.)#', $path ) ) {
				return true;
			}
		}

		if ( empty( $parts['query'] ) ) {
			return false;
		}

		parse_str( (string) $parts['query'], $query );
		if ( ! is_array( $query ) ) {
			return false;
		}

		$auth_query_keys = array( 'redirect_to', 'returnurl', 'return_to' );
		foreach ( $auth_query_keys as $key ) {
			if ( isset( $query[ $key ] ) && $this->url_path_looks_like_auth( $path ) ) {
				return true;
			}
		}

		if ( ( isset( $query['redirect'] ) || isset( $query['continue'] ) ) && $this->url_path_looks_like_auth( $path ) ) {
			return true;
		}

		if ( isset( $query['next'] ) && preg_match( '#/(?:login|signin|sign-in|auth|oauth)(?:/|$|\.)#', $path ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Whether a URL path resembles a login/oauth endpoint.
	 *
	 * @param string $path URL path (lowercase recommended).
	 * @return bool
	 */
	private function url_path_looks_like_auth( $path ) {
		$path = strtolower( (string) $path );
		if ( false !== strpos( $path, '/wp-login.php' ) ) {
			return true;
		}
		return (bool) preg_match( '#/(?:login|signin|sign-in|auth|oauth)(?:/|$|\.)#', $path );
	}

}