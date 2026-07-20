<?php
/**
 * Support / donation helpers (TSO brand).
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.9.6
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_Support
 */
class TSOLIIN_Support {

	/**
	 * Request-scoped cache: normalized image URL => attachment post ID (0 when unknown).
	 *
	 * @var array<string,int>
	 */
	private static $attachment_id_by_url = array();

	/**
	 * Request-scoped cache for can_inline_edit_link() per link row.
	 *
	 * @var array<string,bool>
	 */
	private static $inline_edit_link_cache = array();

	/**
	 * Ko-fi donation URL shown in the admin UI.
	 *
	 * @return string
	 */
	public static function get_kofi_donate_url() {
		$default = 'https://ko-fi.com/deadko_cat';

		/**
		 * Filter the Ko-fi donation URL for TSO Link Inspector.
		 *
		 * @param string $url Donation page URL.
		 */
		return (string) apply_filters( 'tsoliin_kofi_donate_url', $default );
	}

	/**
	 * Locale-aware number for plain-text UI (no HTML &nbsp; entities).
	 *
	 * @param int $number Raw count.
	 * @return string
	 */
	public static function format_display_number( $number ) {
		$formatted = number_format_i18n( (int) $number );
		return html_entity_decode( wp_strip_all_tags( $formatted ), ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Donate button label (translated via plugin text domain).
	 *
	 * @return string
	 */
	public static function get_donate_label() {
		return __( '☕ Support this plugin', 'tso-link-inspector' );
	}

	/**
	 * Donate link label for the Plugins screen row meta.
	 *
	 * @return string
	 */
	public static function get_donate_link_label() {
		return __( 'Donate', 'tso-link-inspector' );
	}

	/**
	 * Echo the donate link markup for admin screens.
	 */
	public static function render_donate_button() {
		?>
		<a class="tsoliin-donate-btn"
		   href="<?php echo esc_url( self::get_kofi_donate_url() ); ?>"
		   target="_blank"
		   rel="noopener noreferrer">
			<?php echo esc_html( self::get_donate_label() ); ?>
		</a>
		<?php
	}

	/**
	 * Whether the optional “Convert to /path” row and bulk actions are enabled.
	 *
	 * @return bool
	 */
	public static function is_relative_url_tool_enabled() {
		$s = get_option( 'tsoliin_settings', array() );
		return ! empty( $s['relative_url_tool'] );
	}

	/**
	 * Whether to save a WordPress revision before in-post link edits.
	 *
	 * @return bool
	 */
	public static function is_create_revision_enabled() {
		$s = get_option( 'tsoliin_settings', array() );
		return ! empty( $s['create_revision'] );
	}

	/**
	 * Whether the plugin can edit this link in-place (modal), without opening another admin screen.
	 *
	 * @param object|null $link Link row.
	 * @return bool
	 */
	public static function can_inline_edit_link( $link ) {
		if ( ! $link || empty( $link->link_type ) ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk ) ) {
			return false;
		}
		$cache_key = self::link_row_cache_key( $link );
		if ( '' !== $cache_key && array_key_exists( $cache_key, self::$inline_edit_link_cache ) ) {
			return self::$inline_edit_link_cache[ $cache_key ];
		}
		$scanner = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->scanner : null;
		$result  = (bool) ( $scanner && $scanner->is_url_editable_in_source( $link ) );
		if ( '' !== $cache_key ) {
			self::$inline_edit_link_cache[ $cache_key ] = $result;
		}
		return $result;
	}

	/**
	 * Whether the list table should offer Go to edit for a WooCommerce product field.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function shows_woocommerce_admin_edit_action( $link ) {
		if ( ! $link || empty( $link->post_id ) ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( ! class_exists( 'TSOLIIN_WooCommerce', false ) || ! TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk ) ) {
			return false;
		}
		return TSOLIIN_WooCommerce::is_product( (int) $link->post_id );
	}

	/**
	 * Whether the list table should offer the inline Edit link modal action.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function shows_edit_link_row_action( $link ) {
		if ( ! $link || empty( $link->link_type ) ) {
			return false;
		}
		$type = (string) $link->link_type;
		if ( in_array( $type, array( 'comment', 'widget', 'term' ), true ) ) {
			return false;
		}
		if ( 'menu' === $type ) {
			return self::is_custom_menu_url_row( $link ) && self::can_inline_edit_link( $link );
		}
		return self::can_inline_edit_link( $link );
	}

	/**
	 * Whether the list table should offer Go to edit widget.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function shows_widget_admin_edit_action( $link ) {
		if ( ! $link || 'widget' !== (string) $link->link_type ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		return '' !== self::get_widgets_admin_edit_url( $sk );
	}

	/**
	 * Whether the list table should offer Go to edit comment.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function shows_comment_admin_edit_action( $link ) {
		if ( ! $link || 'comment' !== (string) $link->link_type ) {
			return false;
		}
		return '' !== self::get_comment_admin_edit_url( self::get_comment_id_from_link_row( $link ) );
	}

	/**
	 * WordPress comment ID stored on a link inspector row.
	 *
	 * @param object|null $link DB link row.
	 * @return int
	 */
	public static function get_comment_id_from_link_row( $link ) {
		if ( ! $link ) {
			return 0;
		}
		if ( ! empty( $link->source_key ) && preg_match( '/^c-(\d+)-/', (string) $link->source_key, $m ) ) {
			return absint( $m[1] );
		}
		if ( preg_match( '/#(\d+)/', (string) $link->anchor_text, $m ) ) {
			return absint( $m[1] );
		}
		return 0;
	}

	/**
	 * Whether the edit-link modal may change anchor/alt text for this row.
	 *
	 * Comment rows are URL-only; edit visible text in WordPress.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function can_edit_link_anchor_in_modal( $link ) {
		if ( ! $link || empty( $link->link_type ) ) {
			return false;
		}
		$type = (string) $link->link_type;
		if ( in_array( $type, array( 'iframe', 'comment' ), true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Whether stored anchor text is an internal inspector label, not visible comment link text.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function is_comment_internal_anchor_label( $link ) {
		if ( ! $link || 'comment' !== (string) $link->link_type ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( '' !== $sk && preg_match( '/-author$/', $sk ) ) {
			return true;
		}
		$anchor = trim( (string) ( $link->anchor_text ?? '' ) );
		if ( '' === $anchor ) {
			return true;
		}
		$comment_id = self::get_comment_id_from_link_row( $link );
		if ( $comment_id > 0 ) {
			$placeholders = array(
				/* translators: %d: comment ID */
				sprintf( __( 'Comment #%d', 'tso-link-inspector' ), $comment_id ),
				/* translators: %d: comment ID */
				sprintf( __( 'Comment author #%d', 'tso-link-inspector' ), $comment_id ),
			);
			if ( in_array( $anchor, $placeholders, true ) ) {
				return true;
			}
		}
		return (bool) preg_match(
			'/^(?:Comment|Comentari|Comentario|Autor comentario)(?:\s+author|\s+autor)?\s*#\d+$/iu',
			$anchor
		);
	}

	/**
	 * Whether this row is the commenter's website URL (comment_author_url), not body content.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function is_comment_author_url_row( $link ) {
		if ( ! $link || 'comment' !== (string) $link->link_type ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		return '' !== $sk && preg_match( '/-author$/', $sk );
	}

	/**
	 * Whether Unlink may modify the source (not only delete the inspector row).
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function can_unlink_link( $link ) {
		if ( ! $link || empty( $link->link_type ) ) {
			return false;
		}
		if ( 'comment' === (string) $link->link_type ) {
			return true;
		}
		return self::can_inline_edit_link( $link );
	}

	/**
	 * Public front-end URL to view the post (or jump to a comment for comment rows).
	 *
	 * @param object|null $link DB link row.
	 * @return string
	 */
	public static function get_post_frontend_view_url_for_link( $link ) {
		if ( ! $link || empty( $link->post_id ) ) {
			return '';
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( 'comment' === $type ) {
			$comment_id = self::get_comment_id_from_link_row( $link );
			if ( $comment_id > 0 ) {
				$comment = get_comment( $comment_id );
				if ( $comment ) {
					$comment_link = get_comment_link( $comment );
					if ( is_string( $comment_link ) && '' !== $comment_link ) {
						return $comment_link;
					}
				}
			}
		}
		$permalink = get_permalink( (int) $link->post_id );
		if ( ! is_string( $permalink ) || '' === $permalink ) {
			return '';
		}
		if ( self::should_focus_link_in_post_content( $link ) && ! empty( $link->id ) ) {
			return add_query_arg( 'tsoliin_link', absint( $link->id ), $permalink );
		}
		return $permalink;
	}

	/**
	 * Whether a row points to editable post content (link, image, iframe, etc.).
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function should_focus_link_in_post_content( $link ) {
		if ( ! $link ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( class_exists( 'TSOLIIN_WooCommerce', false ) && TSOLIIN_WooCommerce::is_woocommerce_source_key( $sk ) ) {
			return false;
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( ! in_array( $type, array( 'link', 'image', 'iframe', 'plain', 'template', 'wp_block' ), true ) ) {
			return false;
		}
		if ( empty( $link->post_id ) || empty( $link->link_url ) ) {
			return false;
		}
		$scanner = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->scanner : null;
		if ( ! $scanner ) {
			return true;
		}
		// Only deep-link when the URL is in post_content (not meta-only / stale orphan rows).
		return $scanner->is_url_in_post_body( (int) $link->post_id, (string) $link->link_url, $type );
	}

	/**
	 * Admin edit screen URL for a post-stored link, with optional deep-link to highlight it.
	 *
	 * @param object|null $link DB link row.
	 * @return string
	 */
	public static function get_post_admin_edit_url_for_link( $link ) {
		if ( ! $link || empty( $link->post_id ) ) {
			return '';
		}
		$type = isset( $link->link_type ) ? (string) $link->link_type : 'link';
		if ( ! in_array( $type, array( 'link', 'image', 'iframe', 'plain', 'template', 'wp_block' ), true ) ) {
			return '';
		}
		$edit = get_edit_post_link( absint( $link->post_id ) );
		if ( ! is_string( $edit ) || '' === $edit ) {
			return '';
		}
		if ( ! empty( $link->id ) && self::should_focus_link_in_post_content( $link ) ) {
			return add_query_arg( 'tsoliin_link', absint( $link->id ), $edit );
		}
		return $edit;
	}

	/**
	 * HTML attributes to match when focusing a link row on the front end.
	 *
	 * @param string $link_type link|image|iframe|….
	 * @return string[]
	 */
	public static function get_focus_attributes_for_link_type( $link_type ) {
		$link_type = sanitize_key( (string) $link_type );
		if ( 'image' === $link_type || 'iframe' === $link_type ) {
			return array( 'src' );
		}
		if ( 'link' === $link_type ) {
			return array( 'href' );
		}
		if ( 'plain' === $link_type ) {
			return array();
		}
		return array( 'href', 'src' );
	}

	/**
	 * Whether a post opens in the block editor (respects Classic Editor plugin).
	 *
	 * @param WP_Post|null $post Post object.
	 * @return bool
	 */
	public static function post_uses_block_editor( $post ) {
		if ( ! ( $post instanceof WP_Post ) ) {
			return false;
		}
		$editors = apply_filters( 'classic_editor_enabled_editors_for_post', array( 'classic', 'block' ), $post ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Classic Editor plugin public API.
		if ( is_array( $editors ) && ! in_array( 'block', $editors, true ) ) {
			return false;
		}
		if ( function_exists( 'use_block_editor_for_post' ) ) {
			return (bool) use_block_editor_for_post( $post );
		}
		return false;
	}

	/**
	 * Localized data for editor/front-end link focus scripts.
	 *
	 * @param object $link    DB link row.
	 * @param int    $post_id Post ID.
	 * @return array<string,mixed>
	 */
	public static function get_focus_link_localize_data( $link, $post_id ) {
		$post_id   = absint( $post_id );
		$link_type = ( $link && isset( $link->link_type ) ) ? (string) $link->link_type : 'link';
		$url       = ( $link && isset( $link->link_url ) ) ? (string) $link->link_url : '';
		$variants  = TSOLIIN_Scanner::get_href_match_variants( $url, $post_id );

		$in_post_content = false;
		$meta_key_hint   = '';
		$post            = $post_id > 0 ? get_post( $post_id ) : null;
		if ( $post instanceof WP_Post ) {
			foreach ( $variants as $variant ) {
				if ( '' !== $variant && false !== stripos( $post->post_content, $variant ) ) {
					$in_post_content = true;
					break;
				}
			}
			if ( ! $in_post_content && 'plain' === $link_type && ! empty( $link->anchor_text ) ) {
				$anchor = sanitize_text_field( (string) $link->anchor_text );
				if ( '' !== $anchor && ! preg_match( '/^(comment|comentari|widget|menu)\b/i', $anchor ) ) {
					$meta_key_hint = $anchor;
				}
			}
		}

		$extra = array();
		foreach ( $variants as $variant ) {
			$encoded = htmlspecialchars( (string) $variant, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
			if ( $encoded !== $variant ) {
				$extra[] = $encoded;
			}
			if ( false !== strpos( $variant, '&' ) ) {
				$extra[] = str_replace( '&', '&amp;', $variant );
			}
		}

		$content_needle = '';
		if ( $post instanceof WP_Post ) {
			$content_needle = self::find_url_needle_in_post_content( $post->post_content, $variants );
		}

		$attachment_id = self::resolve_attachment_id_from_url( $url );

		$is_block_editor = self::post_uses_block_editor( $post );

		return array(
			'variants'        => array_values( array_unique( array_filter( array_merge( $variants, $extra ) ) ) ),
			'attrs'           => self::get_focus_attributes_for_link_type( $link_type ),
			'linkType'        => $link_type,
			'inPostContent'   => $in_post_content ? 1 : 0,
			'metaKeyHint'     => $meta_key_hint,
			'contentNeedle'   => $content_needle,
			'attachmentId'    => $attachment_id,
			'fileName'        => self::file_name_from_url( $url ),
			'isBlockEditor'   => $is_block_editor ? 1 : 0,
		);
	}

	/**
	 * Attachment post ID for a media-library image URL (full size or -WxH variant).
	 *
	 * @param string $url Image URL.
	 * @return int
	 */
	public static function resolve_attachment_id_from_url( $url ) {
		$url = trim( (string) $url );
		if ( '' === $url || ! function_exists( 'attachment_url_to_postid' ) ) {
			return 0;
		}

		$cache_key = self::attachment_url_cache_key( $url );
		if ( array_key_exists( $cache_key, self::$attachment_id_by_url ) ) {
			return (int) self::$attachment_id_by_url[ $cache_key ];
		}

		$id   = (int) attachment_url_to_postid( $url );
		$full = preg_replace( '/-\d+x\d+(?=\.(?:jpe?g|png|gif|webp|avif|bmp|ico))/i', '', $url );
		if ( $id <= 0 && is_string( $full ) && $full !== $url ) {
			$full_key = self::attachment_url_cache_key( $full );
			if ( array_key_exists( $full_key, self::$attachment_id_by_url ) ) {
				$id = (int) self::$attachment_id_by_url[ $full_key ];
			} else {
				$id = (int) attachment_url_to_postid( $full );
				self::$attachment_id_by_url[ $full_key ] = $id;
			}
		}

		self::$attachment_id_by_url[ $cache_key ] = $id;
		if ( $id > 0 && is_string( $full ) && $full !== $url ) {
			self::$attachment_id_by_url[ self::attachment_url_cache_key( $full ) ] = $id;
		}
		return $id;
	}

	/**
	 * Stable cache key for a media URL (ignore query string, normalize case).
	 *
	 * @param string $url Image URL.
	 * @return string
	 */
	private static function attachment_url_cache_key( $url ) {
		$url = trim( (string) $url );
		$path = wp_parse_url( $url, PHP_URL_PATH );
		if ( is_string( $path ) && '' !== $path ) {
			return strtolower( $path );
		}
		return strtolower( strtok( $url, '?' ) );
	}

	/**
	 * Cache key for a link inspector DB row.
	 *
	 * @param object $link Link row.
	 * @return string
	 */
	private static function link_row_cache_key( $link ) {
		if ( ! empty( $link->id ) ) {
			return 'id:' . absint( $link->id );
		}
		$url = isset( $link->link_url ) ? (string) $link->link_url : '';
		$sk  = isset( $link->source_key ) ? (string) $link->source_key : '';
		$type = isset( $link->link_type ) ? (string) $link->link_type : '';
		if ( '' === $url && '' === $sk ) {
			return '';
		}
		return 'row:' . md5( $type . '|' . $url . '|' . $sk . '|' . absint( $link->post_id ?? 0 ) );
	}

	/**
	 * Basename from a URL path (for DOM matching in the editor).
	 *
	 * @param string $url URL.
	 * @return string
	 */
	public static function file_name_from_url( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$name = basename( $path );
		return is_string( $name ) ? $name : '';
	}

	/**
	 * Exact URL substring as stored in post_content (preserves case and encoding).
	 *
	 * @param string   $content  Raw post content.
	 * @param string[] $variants URL variants.
	 * @return string
	 */
	public static function find_url_needle_in_post_content( $content, $variants ) {
		$content = (string) $content;
		foreach ( (array) $variants as $variant ) {
			$variant = (string) $variant;
			if ( '' === $variant ) {
				continue;
			}
			$pos = stripos( $content, $variant );
			if ( false !== $pos ) {
				return substr( $content, $pos, strlen( $variant ) );
			}
		}
		return '';
	}

	/**
	 * Admin URL to edit a non-post link source (menu, widget, term).
	 *
	 * @param object $link DB link row.
	 * @return string
	 */
	public static function get_link_source_edit_url( $link ) {
		if ( ! $link || empty( $link->link_type ) ) {
			return '';
		}
		$type = (string) $link->link_type;
		$sk   = isset( $link->source_key ) ? (string) $link->source_key : '';

		if ( 'menu' === $type ) {
			return self::get_menus_admin_edit_url( $sk );
		}
		if ( 'widget' === $type ) {
			return self::get_widgets_admin_edit_url( $sk );
		}
		if ( 'term' === $type && preg_match( '/^t-(\d+)-/', $sk, $m ) ) {
			$term = get_term( absint( $m[1] ) );
			if ( $term && ! is_wp_error( $term ) ) {
				$edit = get_edit_term_link( $term, $term->taxonomy );
				return is_string( $edit ) ? $edit : '';
			}
		}
		if ( 'comment' === $type ) {
			$comment_id = self::get_comment_id_from_link_row( $link );
			return self::get_comment_admin_edit_url( $comment_id );
		}
		if ( in_array( $type, array( 'template', 'wp_block' ), true ) && ! empty( $link->post_id ) ) {
			return self::get_post_admin_edit_url_for_link( $link );
		}
		return '';
	}

	/**
	 * Admin screen URL for widget editing (classic, block theme, or Site Editor).
	 *
	 * @param string $source_key Optional wg-{sidebar}-{widget}-… source key.
	 * @return string
	 */
	public static function get_widgets_admin_edit_url( $source_key = '' ) {
		$widget_id  = '';
		$sidebar_id = '';
		$scanner    = function_exists( 'tsoliin_link_inspector' ) ? tsoliin_link_inspector()->scanner : null;
		if ( $scanner && '' !== (string) $source_key ) {
			$resolved = $scanner->resolve_widget_from_source_key( (string) $source_key );
			if ( is_array( $resolved ) ) {
				$widget_id  = isset( $resolved['widget_id'] ) ? (string) $resolved['widget_id'] : '';
				$sidebar_id = isset( $resolved['sidebar_id'] ) ? (string) $resolved['sidebar_id'] : '';
			}
		}

		if ( wp_is_block_theme() || ( ! current_theme_supports( 'widgets' ) && current_user_can( 'edit_theme_options' ) ) ) {
			$args = array( 'path' => '/wp-admin/widgets' );
			if ( '' !== $widget_id ) {
				$args['tsoliin_widget'] = $widget_id;
			}
			if ( '' !== $sidebar_id ) {
				$args['tsoliin_sidebar'] = $sidebar_id;
			}
			return add_query_arg( $args, admin_url( 'site-editor.php' ) );
		}

		if ( current_theme_supports( 'widgets' ) ) {
			$args = array();
			if ( '' !== $widget_id ) {
				$args['tsoliin_widget'] = $widget_id;
			}
			if ( '' !== $sidebar_id ) {
				$args['tsoliin_sidebar'] = $sidebar_id;
			}
			$url = '' !== $args ? add_query_arg( $args, admin_url( 'widgets.php' ) ) : admin_url( 'widgets.php' );
			if ( '' !== $widget_id ) {
				$url .= '#widget-' . rawurlencode( $widget_id );
			}
			return $url;
		}

		return admin_url( 'site-editor.php' );
	}

	/**
	 * Whether wp-admin/nav-menus.php is usable (block themes call wp_die on that screen).
	 *
	 * @return bool
	 */
	public static function classic_nav_menus_admin_available() {
		if ( function_exists( 'wp_is_block_theme' ) && wp_is_block_theme() ) {
			return false;
		}
		return (bool) current_theme_supports( 'menus' );
	}

	/**
	 * Site Editor URL for block-theme navigation (wp_navigation).
	 *
	 * @return string
	 */
	public static function get_site_editor_navigation_admin_url() {
		// WP 6.5+ path routing (`p`); core redirects legacy `path=/navigation`.
		return add_query_arg( 'p', '/navigation', admin_url( 'site-editor.php' ) );
	}

	/**
	 * Admin screen URL for navigation menu editing.
	 *
	 * @param string $source_key Optional mi-{menu_item_id} source key.
	 * @return string
	 */
	public static function get_menus_admin_edit_url( $source_key = '' ) {
		if ( ! self::classic_nav_menus_admin_available() ) {
			return self::get_site_editor_navigation_admin_url();
		}

		$menu_id = 0;
		$item_id = 0;
		if ( preg_match( '/^mi-(\d+)/', (string) $source_key, $m ) ) {
			$item_id = absint( $m[1] );
			if ( $item_id > 0 ) {
				$terms = wp_get_post_terms( $item_id, 'nav_menu', array( 'fields' => 'ids' ) );
				if ( ! empty( $terms[0] ) ) {
					$menu_id = (int) $terms[0];
				}
			}
		}

		$url = admin_url( 'nav-menus.php' );
		if ( $menu_id > 0 ) {
			$url = add_query_arg(
				array(
					'action' => 'edit',
					'menu'   => $menu_id,
				),
				$url
			);
			if ( $item_id > 0 ) {
				$url .= '#menu-item-' . $item_id;
			}
		}
		return $url;
	}

	/**
	 * Whether the list table should show Go to edit for a menu row.
	 *
	 * Custom menu URLs on block themes are updated via Edit link (_menu_item_url);
	 * nav-menus.php is blocked and Site Editor does not edit legacy nav_menu_item rows.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function shows_menu_admin_go_to_edit_action( $link ) {
		if ( ! $link || 'menu' !== (string) $link->link_type ) {
			return false;
		}
		if ( self::is_custom_menu_url_row( $link ) && ! self::classic_nav_menus_admin_available() ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		return '' !== self::get_menus_admin_edit_url( $sk );
	}

	/**
	 * Whether a menu inspector row stores a custom URL in _menu_item_url (editable here).
	 *
	 * Post-type / taxonomy menu items derive their URL from the linked object and must be
	 * changed in that object's editor — not via _menu_item_url.
	 *
	 * @param object|null $link DB link row.
	 * @return bool
	 */
	public static function is_custom_menu_url_row( $link ) {
		if ( ! $link || empty( $link->link_type ) || 'menu' !== (string) $link->link_type ) {
			return false;
		}
		$sk = isset( $link->source_key ) ? (string) $link->source_key : '';
		if ( ! preg_match( '/^mi-(\d+)/', $sk, $m ) ) {
			return false;
		}
		$item_id = absint( $m[1] );
		if ( $item_id <= 0 ) {
			return false;
		}
		$stored = get_post_meta( $item_id, '_menu_item_url', true );
		return is_string( $stored ) && '' !== trim( $stored );
	}

	/**
	 * Admin URL to edit a single comment (author URL or body links).
	 *
	 * @param int $comment_id Comment ID.
	 * @return string
	 */
	public static function get_comment_admin_edit_url( $comment_id ) {
		$comment_id = absint( $comment_id );
		if ( $comment_id <= 0 || ! current_user_can( 'edit_comment', $comment_id ) ) {
			return '';
		}
		return admin_url( 'comment.php?action=editcomment&c=' . $comment_id );
	}

	/**
	 * Status column HTML for a link row (list table + AJAX).
	 *
	 * @param object           $item Link row.
	 * @param TSOLIIN_HTTP|null $http HTTP helper.
	 * @return string
	 */
	public static function render_link_status_html( $item, $http = null ) {
		$orig     = isset( $item->link_url ) ? (string) $item->link_url : '';
		$rurl     = isset( $item->redirect_url ) ? (string) $item->redirect_url : '';
		$code     = isset( $item->status_code ) ? (int) $item->status_code : 0;
		$verified = ! empty( $item->user_verified );
		$chain    = TSOLIIN_DB::decode_redirect_chain( isset( $item->redirect_chain ) ? (string) $item->redirect_chain : '' );

		if ( $http && '' !== $rurl && $http->is_transparent_redirect( $orig, $rurl ) ) {
			$code  = 200;
			$rurl  = '';
			$chain = array();
		}

		$class = TSOLIIN_HTTP::status_class( $code, isset( $item->is_broken ) ? (int) $item->is_broken : 0, $orig );
		$label = TSOLIIN_HTTP::status_label( $code, $orig );
		$html  = '';
		if ( $verified ) {
			$html .= '<span class="tsoliin-verified-badge" title="' . esc_attr__( 'Marked OK by you. Re-checks keep this unless the URL fails. Edit the URL in the post to clear.', 'tso-link-inspector' ) . '">&#128274; </span>';
		}
		$html .= '<span class="tsoliin-status ' . esc_attr( $class ) . '">';
		if ( $code > 0 ) {
			$html .= esc_html( (string) $code ) . ' ';
		}
		$html .= esc_html( $label ) . '</span>';

		if ( '' !== $rurl && rtrim( $orig, '/' ) !== rtrim( $rurl, '/' ) ) {
			$rdisp = strlen( $rurl ) > 40 ? substr( $rurl, 0, 37 ) . '...' : $rurl;
			$html .= '<br><small><a href="' . esc_url( $rurl ) . '" title="' . esc_attr( $rurl ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $rdisp ) . '</a></small>';
		}

		if ( count( $chain ) > 1 ) {
			/* translators: %d: number of redirect hops */
			$toggle_label = sprintf( __( '%d hops', 'tso-link-inspector' ), count( $chain ) );
			$html        .= '<br><button type="button" class="button-link tsoliin-toggle-chain" aria-expanded="false">' . esc_html( $toggle_label ) . '</button>';
			$html        .= '<ol class="tsoliin-redirect-chain" hidden>';
			foreach ( $chain as $hop ) {
				$hop_url  = isset( $hop['url'] ) ? (string) $hop['url'] : '';
				$hop_code = isset( $hop['code'] ) ? (int) $hop['code'] : 0;
				if ( '' === $hop_url ) {
					continue;
				}
				$html .= '<li><code>' . esc_html( (string) $hop_code ) . '</code> ';
				$html .= '<a href="' . esc_url( $hop_url ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $hop_url ) . '</a></li>';
			}
			$html .= '</ol>';
		}

		return $html;
	}
}
