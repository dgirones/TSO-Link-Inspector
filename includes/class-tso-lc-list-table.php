<?php
/**
 * WP_List_Table subclass for link results.
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Class TSOLIIN_List_Table
 */
class TSOLIIN_List_Table extends WP_List_Table {

	/** @var TSOLIIN_DB */
	private $db;

	/** @var TSOLIIN_HTTP|null */
	private $http;

	public function __construct( TSOLIIN_DB $db, TSOLIIN_HTTP $http = null ) {
		$this->db   = $db;
		$this->http = $http;
		parent::__construct( array(
			'singular' => __( 'Link', 'tso-link-inspector' ),
			'plural'   => __( 'Links', 'tso-link-inspector' ),
			'ajax'     => false,
		) );
	}

	public function get_columns() {
		return array(
			'cb'           => '<input type="checkbox" />',
			'link_type'    => __( 'Type', 'tso-link-inspector' ),
			'link_url'     => __( 'URL', 'tso-link-inspector' ),
			'anchor_text'  => __( 'Text', 'tso-link-inspector' ),
			'post_title'   => __( 'Article', 'tso-link-inspector' ),
			'status_code'  => __( 'Status', 'tso-link-inspector' ),
			'last_checked' => __( 'Last checked', 'tso-link-inspector' ),
		);
	}

	protected function get_sortable_columns() {
		return array(
			'link_url'     => array( 'link_url', false ),
			'post_title'   => array( 'post_id', false ),
			'status_code'  => array( 'status_code', false ),
			'last_checked' => array( 'last_checked', true ),
		);
	}

	protected function get_bulk_actions() {
		return array(
			'recheck' => __( 'Recheck selected', 'tso-link-inspector' ),
			'unlink'  => __( 'Unlink all', 'tso-link-inspector' ),
			'not_broken' => __( 'Mark as OK', 'tso-link-inspector' ),
			'delete'  => __( 'Delete from list', 'tso-link-inspector' ),
		);
	}

	public function prepare_items() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$filter  = isset( $_REQUEST['filter'] )  ? sanitize_key( $_REQUEST['filter'] )                         : 'all';
		$search  = isset( $_REQUEST['s'] )        ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) )         : '';
		$orderby = isset( $_REQUEST['orderby'] )  ? sanitize_key( $_REQUEST['orderby'] )                        : 'date_found';
		$order   = isset( $_REQUEST['order'] )    ? sanitize_key( $_REQUEST['order'] )                          : 'DESC';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$per_page = $this->get_items_per_page( 'tsoliin_per_page', 20 );
		$paged    = $this->get_pagenum();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$post_id = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		$result = $this->db->get_links( array(
			'filter'   => $filter,
			'search'   => $search,
			'orderby'  => $orderby,
			'order'    => $order,
			'per_page' => $per_page,
			'paged'    => $paged,
			'post_id'  => $post_id,
		) );

		$this->items = $result['items'];
		$this->set_pagination_args( array(
			'total_items' => $result['total'],
			'per_page'    => $per_page,
			'total_pages' => max( 1, (int) ceil( $result['total'] / $per_page ) ),
		) );
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'link_url' );
	}

	protected function column_cb( $item ) {
		return sprintf( '<input type="checkbox" name="link_ids[]" value="%d" />', absint( $item->id ) );
	}

	protected function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'link_type':
				$type   = isset( $item->link_type ) ? (string) $item->link_type : 'link';
				$icons  = array(
					'link'    => array( 'dashicons-admin-links',    __( 'Link', 'tso-link-inspector' ) ),
					'image'   => array( 'dashicons-format-image',   __( 'Image', 'tso-link-inspector' ) ),
					'iframe'  => array( 'dashicons-video-alt3',     __( 'Iframe', 'tso-link-inspector' ) ),
					'comment' => array( 'dashicons-admin-comments', __( 'Comment', 'tso-link-inspector' ) ),
				);
				$icon  = isset( $icons[ $type ] ) ? $icons[ $type ][0] : 'dashicons-admin-links';
				$label = isset( $icons[ $type ] ) ? $icons[ $type ][1] : esc_html( $type );
				return '<span class="dashicons ' . esc_attr( $icon ) . '" title="' . esc_attr( $label ) . '"></span>';

			case 'anchor_text':
				return esc_html( (string) $item->anchor_text );

			case 'last_checked':
				if ( empty( $item->last_checked ) ) {
					// Legacy rows may have status_code but no last_checked; show date_found as fallback.
					if ( ! empty( $item->status_code ) && 0 !== (int) $item->status_code && ! empty( $item->date_found ) ) {
						return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( (string) $item->date_found ) ) );
					}
					// Has status but no fallback date available.
					if ( ! empty( $item->status_code ) && 0 !== (int) $item->status_code ) {
						return '<em style="color:#646970;">' . esc_html__( 'Unknown date', 'tso-link-inspector' ) . '</em>';
					}
					return '<em>' . esc_html__( 'Never', 'tso-link-inspector' ) . '</em>';
				}
				return esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $item->last_checked ) ) );

			default:
				return '';
		}
	}

	protected function column_link_url( $item ) {
		$nonce    = wp_create_nonce( 'tsoliin_action' );
		$url      = (string) $item->link_url;
		// Show up to 80 chars in the cell; full URL always visible on hover via title attribute.
		$display  = strlen( $url ) > 110 ? substr( $url, 0, 107 ) . '...' : $url;
		$is_broken = (int) $item->is_broken;
		$code      = (int) $item->status_code;

		$out  = '<span class="tsoliin-url">';
		$out .= '<a href="' . esc_url( $url ) . '" title="' . esc_attr( $url ) . '" target="_blank" rel="noopener noreferrer">';
		$out .= esc_html( $display );
		$out .= ' <span class="dashicons dashicons-external" style="font-size:12px;vertical-align:middle;"></span>';
		$out .= '</a></span>';

		// Show "Suggestion" for: broken links, connection errors, http:// (upgrade to https),
		// and redirects (user may want to update the link to point directly to the final URL).
		// Manually verified rows stay quiet until the URL changes or the link actually fails.
		$redirect_codes = array( 301, 302, 303, 307, 308 );
		$verified       = ! empty( $item->user_verified );
		$transparent_rd = $this->http && ! empty( $item->redirect_url )
			&& $this->http->is_transparent_redirect( $url, (string) $item->redirect_url );
		$show_suggest   = ! $verified
			&& ! $transparent_rd
			&& (
				$is_broken
				|| $code < 0
				|| preg_match( '#^http://#i', $url )
				|| in_array( $code, $redirect_codes, true )
				|| ! empty( $item->redirect_url )
			);

		$actions = array(
			'edit'       => sprintf( '<a href="#" class="tsoliin-edit-link" data-id="%d" data-url="%s" data-post="%d">%s</a>', absint( $item->id ), esc_attr( $url ), absint( $item->post_id ), esc_html__( 'Edit URL', 'tso-link-inspector' ) ),
			'recheck'    => sprintf( '<a href="#" class="tsoliin-recheck" data-id="%d" data-nonce="%s">%s</a>', absint( $item->id ), esc_attr( $nonce ), esc_html__( 'Recheck', 'tso-link-inspector' ) ),
			'not_broken' => sprintf( '<a href="#" class="tsoliin-not-broken" data-id="%d" data-nonce="%s" style="color:#0a7d33;font-weight:600;">%s</a>', absint( $item->id ), esc_attr( $nonce ), esc_html__( 'Not broken', 'tso-link-inspector' ) ),
			'unlink'     => sprintf( '<a href="#" class="tsoliin-unlink" data-id="%d" data-url="%s" data-post="%d" data-nonce="%s">%s</a>', absint( $item->id ), esc_attr( $url ), absint( $item->post_id ), esc_attr( $nonce ), esc_html__( 'Unlink', 'tso-link-inspector' ) ),
			'delete'     => sprintf( '<a href="#" class="tsoliin-delete" data-id="%d" data-nonce="%s" title="%s">%s</a>', absint( $item->id ), esc_attr( $nonce ), esc_attr__( 'Delete this record. The post link is not modified.', 'tso-link-inspector' ), esc_html__( 'Delete', 'tso-link-inspector' ) ),
		);

		if ( $show_suggest ) {
			$actions['suggest'] = sprintf( '<a href="#" class="tsoliin-suggest" data-id="%d" data-nonce="%s" style="color:#b45309;font-weight:600;">%s</a>', absint( $item->id ), esc_attr( $nonce ), esc_html__( 'Suggestion', 'tso-link-inspector' ) );
		}

		return $out . $this->row_actions( $actions );
	}

	protected function column_post_title( $item ) {
		$title    = ! empty( $item->post_title ) ? (string) $item->post_title : __( '(no title)', 'tso-link-inspector' );
		$edit     = (string) get_edit_post_link( absint( $item->post_id ) );
		$view     = (string) get_permalink( absint( $item->post_id ) );
		$post_url = esc_url( add_query_arg( array( 'page' => 'tso-link-inspector', 'post_id' => absint( $item->post_id ) ), admin_url( 'tools.php' ) ) );

		$out  = '<a href="' . esc_url( $edit ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $title ) . '</a>';
		// Icons row: view post + list links for this post.
		$out .= '<div class="tsoliin-post-icons">';
		$out .= '<a href="' . esc_url( $view ) . '" target="_blank" rel="noopener noreferrer" title="' . esc_attr__( 'View post', 'tso-link-inspector' ) . '" class="tsoliin-post-icon">';
		$out .= '<span class="dashicons dashicons-external"></span></a>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_post_view = isset( $_REQUEST['post_id'] ) && absint( $_REQUEST['post_id'] ) === absint( $item->post_id );
		if ( $is_post_view ) {
			// Currently viewing this post — show "back" icon in accent colour.
			$out .= '<a href="' . esc_url( admin_url( 'tools.php?page=tso-link-inspector' ) ) . '" title="' . esc_attr__( 'Back to all links', 'tso-link-inspector' ) . '" class="tsoliin-post-icon tsoliin-post-icon--back">';
			$out .= '<span class="dashicons dashicons-arrow-left-alt"></span></a>';
		} else {
			$out .= '<a href="' . $post_url . '" title="' . esc_attr__( 'View all links for this post', 'tso-link-inspector' ) . '" class="tsoliin-post-icon tsoliin-post-icon--list">';
			$out .= '<span class="dashicons dashicons-list-view"></span></a>';
		}
		$out .= '</div>';
		return $out;
	}

	protected function column_status_code( $item ) {
		$code     = (int) $item->status_code;
		$class    = TSOLIIN_HTTP::status_class( $code, (int) $item->is_broken, (string) $item->link_url );
		$label    = TSOLIIN_HTTP::status_label( $code, (string) $item->link_url );
		$verified = ! empty( $item->user_verified );
		$badge    = '';
		if ( $verified ) {
			$badge .= '<span class="tsoliin-verified-badge" title="' . esc_attr__( 'Marked OK by you. Re-checks keep this unless the URL fails. Edit the URL in the post to clear.', 'tso-link-inspector' ) . '">&#128274; </span>';
		}
		$badge .= '<span class="tsoliin-status ' . esc_attr( $class ) . '">';
		if ( $code > 0 ) {
			$badge .= esc_html( $code ) . ' ';
		}
		$badge .= esc_html( $label ) . '</span>';

		if ( ! empty( $item->redirect_url ) ) {
			$rurl      = (string) $item->redirect_url;
			$orig      = (string) $item->link_url;
			// Suppress trivial trailing-slash redirects in display.
			$is_trivial = ( rtrim( $orig, '/' ) === rtrim( $rurl, '/' ) );
			if ( ! $is_trivial ) {
				$rdisp  = strlen( $rurl ) > 40 ? substr( $rurl, 0, 37 ) . '...' : $rurl;
				$badge .= '<br><small><a href="' . esc_url( $rurl ) . '" title="' . esc_attr( $rurl ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( $rdisp ) . '</a></small>';
			}
		}
		return $badge;
	}

	public function no_items() {
		esc_html_e( 'No links found. Run a scan first.', 'tso-link-inspector' );
	}

	protected function extra_tablenav( $which ) {
		if ( 'top' !== $which ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current = isset( $_REQUEST['filter'] ) ? sanitize_key( $_REQUEST['filter'] ) : 'all';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$view_post_id_nav = isset( $_REQUEST['post_id'] ) ? absint( $_REQUEST['post_id'] ) : 0;
		$stats = $view_post_id_nav
			? $this->db->get_stats_for_post( $view_post_id_nav )
			: $this->db->get_stats();

		$filters = array(
			/* translators: %d: number of links */
			'all'       => sprintf( __( 'All (%d)', 'tso-link-inspector' ),        $stats['total'] ),
			/* translators: %d: number of broken links */
			'broken'    => sprintf( __( 'Broken (%d)', 'tso-link-inspector' ),    $stats['broken'] ),
			/* translators: %d: number of redirected links */
			'redirect'  => sprintf( __( 'Redirect (%d)', 'tso-link-inspector' ),  $stats['redirect'] ),
			/* translators: %d: number of OK links */
			'ok'        => sprintf( __( 'OK (%d)', 'tso-link-inspector' ),   $stats['ok'] ),
			/* translators: %d: number of unchecked links */
			'unchecked'     => sprintf( __( 'Unchecked (%d)', 'tso-link-inspector' ), $stats['unchecked'] ),
			/* translators: %d: number of HTTP insecure links */
			'http_insecure' => sprintf( __( 'HTTP insecure (%d)', 'tso-link-inspector' ), isset( $stats['http_insecure'] ) ? $stats['http_insecure'] : 0 ),
			/* translators: %d: number of manually locked links */
			'manual_locked' => sprintf( __( 'Manual locks (%d)', 'tso-link-inspector' ), isset( $stats['manual_locked'] ) ? $stats['manual_locked'] : 0 ),
		);

		echo '<div class="tsoliin-filter-tabs">';
		foreach ( $filters as $key => $label ) {
			// Keep post_id in filter URLs so per-article view persists.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$keep_post = isset( $_REQUEST['post_id'] ) ? array( 'filter' => $key, 'post_id' => absint( $_REQUEST['post_id'] ) ) : array( 'filter' => $key );
			// Switching tabs should reset active search terms from the query string.
			$url    = esc_url( add_query_arg( $keep_post, remove_query_arg( array( 'paged', 's' ) ) ) );
			$active = $current === $key ? ' class="current"' : '';
			$title  = '';
			if ( 'unchecked' === $key ) {
				$title = ' title="' . esc_attr__( 'Links found but not checked by HTTP yet. Cron will check them automatically, or click Check now.', 'tso-link-inspector' ) . '"';
			}
			if ( 'http_insecure' === $key ) {
				$title = ' title="' . esc_attr__( 'Active links using HTTP instead of HTTPS. Consider updating them for security and SEO.', 'tso-link-inspector' ) . '"';
			}
			if ( 'manual_locked' === $key ) {
				$title = ' title="' . esc_attr__( 'Links manually marked as not broken.', 'tso-link-inspector' ) . '"';
			}
			echo '<a href="' . $url . '"' . $active . $title . '>' . esc_html( $label ) . '</a> '; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		}
		echo '</div>';
	}

	/**
	 * Bulk actions dropdown + Apply button using plugin text domain (not WP admin locale).
	 *
	 * @param string $which Top or bottom table nav.
	 */
	protected function bulk_actions( $which = '' ) {
		if ( is_null( $this->_actions ) ) {
			$this->_actions = $this->get_bulk_actions();
			if ( isset( $this->screen, $this->screen->id ) ) {
				$this->_actions = apply_filters( "bulk_actions-{$this->screen->id}", $this->_actions ); // phpcs:ignore WordPress.NamingConventions.ValidHookName.UseUnderscores,WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core WP_List_Table bulk_actions filter.
			}
			$two = '';
		} else {
			$two = '2';
		}

		if ( empty( $this->_actions ) ) {
			return;
		}

		echo '<label for="bulk-action-selector-' . esc_attr( $which ) . '" class="screen-reader-text">' . esc_html__( 'Select bulk action', 'tso-link-inspector' ) . '</label>';
		echo '<select name="action' . esc_attr( $two ) . '" id="bulk-action-selector-' . esc_attr( $which ) . '">';
		echo '<option value="-1">' . esc_html__( 'Bulk actions', 'tso-link-inspector' ) . '</option>';

		foreach ( $this->_actions as $key => $value ) {
			if ( is_array( $value ) ) {
				echo '<optgroup label="' . esc_attr( $key ) . '">';
				foreach ( $value as $name => $title ) {
					if ( 'edit' === $name ) {
						echo '<option value="' . esc_attr( $name ) . '" class="hide-if-no-js">' . esc_html( $title ) . '</option>';
					} else {
						echo '<option value="' . esc_attr( $name ) . '">' . esc_html( $title ) . '</option>';
					}
				}
				echo '</optgroup>';
			} elseif ( 'edit' === $key ) {
				echo '<option value="' . esc_attr( $key ) . '" class="hide-if-no-js">' . esc_html( $value ) . '</option>';
			} else {
				echo '<option value="' . esc_attr( $key ) . '">' . esc_html( $value ) . '</option>';
			}
		}
		echo '</select>';

		$btn_name = 'doaction' . $two;
		printf(
			'<input type="submit" name="%1$s" id="%1$s" class="button action" value="%2$s" />',
			esc_attr( $btn_name ),
			esc_attr__( 'Apply', 'tso-link-inspector' )
		);
	}

	/**
	 * Resolve bulk action from top or bottom dropdown (matches core WP_List_Table behaviour).
	 *
	 * @return string|false
	 */
	public function current_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only, mirrors WP core.
		if ( isset( $_REQUEST['filter_action'] ) && '' !== sanitize_text_field( wp_unslash( $_REQUEST['filter_action'] ) ) ) {
			return false;
		}
		if ( isset( $_REQUEST['action'] ) && '-1' !== $_REQUEST['action'] && '' !== $_REQUEST['action'] ) {
			return sanitize_key( wp_unslash( $_REQUEST['action'] ) );
		}
		if ( isset( $_REQUEST['action2'] ) && '-1' !== $_REQUEST['action2'] && '' !== $_REQUEST['action2'] ) {
			return sanitize_key( wp_unslash( $_REQUEST['action2'] ) );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return false;
	}

	/**
	 * Render table navigation.
	 * Keep top bulk-actions visible even when there are no rows, so the
	 * empty-state layout matches the normal table layout.
	 *
	 * @param string $which top|bottom.
	 * @return void
	 */
	protected function display_tablenav( $which ) {
		if ( 'top' === $which ) {
			wp_nonce_field( 'bulk-' . $this->_args['plural'] );
		}
		echo '<div class="tablenav ' . esc_attr( $which ) . '">';
		$this->extra_tablenav( $which );

		$show_bulk = $this->has_items() || 'top' === $which;
		if ( $show_bulk ) {
			echo '<div class="alignleft actions bulkactions">';
			$this->bulk_actions( $which );
			echo '</div>';
		}

		$this->pagination( $which );
		echo '<br class="clear" />';
		echo '</div>';
	}
}
