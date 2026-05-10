<?php
/**
 * Database handler.
 *
 * Table: {prefix}tso_link_inspector
 *
 * @package TSOLIIN_Link_Inspector
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class TSOLIIN_DB
 */
class TSOLIIN_DB {

	/** @var string Full table name. */
	private $table;
	/** @var string Legacy table name. */
	private $legacy_table;

	public function __construct() {
		global $wpdb;
		$this->table        = $wpdb->prefix . 'tso_link_inspector';
		$this->legacy_table = str_replace( 'inspector', 'checker', $this->table );
		$this->migrate_legacy_table_name();
	}

	/** @return string */
	public function get_table() {
		return $this->table;
	}

	// -------------------------------------------------------------------------
	// Schema
	// -------------------------------------------------------------------------

	/**
	 * Create / upgrade the table via dbDelta.
	 */
	public function create_table() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		$sql     = "CREATE TABLE {$this->table} (
			id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id bigint(20) UNSIGNED NOT NULL DEFAULT 0,
			link_url varchar(2083) NOT NULL DEFAULT '',
			anchor_text text NOT NULL,
			status_code smallint(6) NOT NULL DEFAULT 0,
			redirect_url varchar(2083) NOT NULL DEFAULT '',
			is_broken tinyint(1) NOT NULL DEFAULT 0,
			user_verified tinyint(1) NOT NULL DEFAULT 0,
			verify_baseline_link varchar(2083) NOT NULL DEFAULT '',
			verify_baseline_redirect varchar(2083) NOT NULL DEFAULT '',
			link_type varchar(10) NOT NULL DEFAULT 'link',
			source_key varchar(64) NOT NULL DEFAULT '',
			last_checked datetime DEFAULT NULL,
			date_found datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY post_id (post_id),
			KEY is_broken (is_broken),
			KEY status_code (status_code),
			KEY last_checked (last_checked),
			KEY post_source (post_id, source_key)
		) $charset;";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange -- dbDelta is the WP standard for schema changes.
		dbDelta( $sql );
	}

	/**
	 * Ensure the table exists; recreate if missing.
	 */
	public function ensure_table_exists() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) );
		if ( ! $exists ) {
			$this->create_table();
		}
	}

	/**
	 * Rename legacy table to the new inspector slug, preserving existing data.
	 */
	private function migrate_legacy_table_name() {
		global $wpdb;
		if ( $this->legacy_table === $this->table ) {
			return;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$new_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) );
		$old_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->legacy_table ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $new_exists || ! $old_exists ) {
			return;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "RENAME TABLE `{$this->legacy_table}` TO `{$this->table}`" );
	}

	/**
	 * Fix legacy error codes 2-5 stored by absint() bug (should be -2 to -5).
	 */
	public function migrate_legacy_error_codes() {
		global $wpdb;
		$map = array( 2 => -2, 3 => -3, 4 => -4, 5 => -5 );
		foreach ( $map as $wrong => $correct ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"UPDATE {$this->table} SET status_code = %d WHERE status_code = %d AND is_broken = 1",
					$correct,
					$wrong
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		}
	}

	/**
	 * Drop the table (used by uninstall).
	 */
	public function drop_table() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DROP TABLE IF EXISTS `{$this->table}`" );
	}

	// -------------------------------------------------------------------------
	// Write operations
	// -------------------------------------------------------------------------

	/**
	 * Insert or update a link record.
	 *
	 * @param int    $post_id     Post ID.
	 * @param string $link_url    URL.
	 * @param string $anchor      Anchor text.
	 * @param string $link_type   link|image|iframe|comment.
	 * @param string $source_key  Empty for post content; for comments e.g. c-123-author or c-123-l-<md5>.
	 * @return int|false
	 */
	public function upsert_link( $post_id, $link_url, $anchor, $link_type = 'link', $source_key = '' ) {
		global $wpdb;

		$post_id    = absint( $post_id );
		$link_url   = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $link_url ) );
		$anchor     = sanitize_text_field( (string) $anchor );
		$types      = array( 'link', 'image', 'iframe', 'comment' );
		$link_type  = in_array( $link_type, $types, true ) ? $link_type : 'link';
		$source_key = $this->sanitize_source_key( (string) $source_key );

		if ( ! $post_id || '' === $link_url ) {
			return false;
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$existing = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$this->table} WHERE post_id = %d AND link_url = %s AND source_key = %s LIMIT 1",
				$post_id,
				$link_url,
				$source_key
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $existing ) {
			// Do not reset user_verified on rescan.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$this->table,
				array( 'anchor_text' => $anchor, 'link_type' => $link_type ),
				array( 'id' => absint( $existing ) ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return absint( $existing );
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert(
			$this->table,
			array(
				'post_id'      => $post_id,
				'link_url'     => $link_url,
				'anchor_text'  => $anchor,
				'status_code'  => 0,
				'redirect_url' => '',
				'is_broken'    => 0,
				'link_type'    => $link_type,
				'source_key'   => $source_key,
				'last_checked' => null,
				'date_found'   => current_time( 'mysql', true ),
			),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery

		return $wpdb->insert_id ? absint( $wpdb->insert_id ) : false;
	}

	/**
	 * @param string $key Raw source key.
	 * @return string
	 */
	public function sanitize_source_key( $key ) {
		$key = strtolower( preg_replace( '/[^a-z0-9\-]/', '', (string) $key ) );
		return substr( $key, 0, 64 );
	}

	/**
	 * Remove inspector rows for a comment (after delete/trash purge).
	 *
	 * @param int $comment_id Comment ID.
	 * @return void
	 */
	public function delete_links_for_comment( $comment_id ) {
		global $wpdb;
		$cid = absint( $comment_id );
		if ( ! $cid ) {
			return;
		}
		$like = 'c-' . $cid . '-%';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix + fixed suffix.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$this->table} WHERE link_type = %s AND source_key LIKE %s",
				'comment',
				$like
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Drop comment link rows whose source_key for this comment is no longer present (href/author removed).
	 *
	 * @param int      $post_id       Post ID the comment belongs to.
	 * @param int      $comment_id    Comment ID.
	 * @param string[] $allowed_keys  Non-empty keys still valid (e.g. c-9-author, c-9-l-abc…).
	 * @return void
	 */
	public function delete_comment_sources_not_in( $post_id, $comment_id, array $allowed_keys ) {
		global $wpdb;
		$post_id = absint( $post_id );
		$cid     = absint( $comment_id );
		if ( ! $post_id || ! $cid ) {
			return;
		}
		$prefix = 'c-' . $cid . '-';
		$allowed = array();
		foreach ( $allowed_keys as $k ) {
			$k = $this->sanitize_source_key( (string) $k );
			if ( '' !== $k && 0 === strpos( $k, $prefix ) ) {
				$allowed[] = $k;
			}
		}
		$allowed = array_values( array_unique( $allowed ) );

		if ( empty( $allowed ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix + fixed suffix.
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table} WHERE post_id = %d AND link_type = %s AND source_key LIKE %s",
					$post_id,
					'comment',
					$wpdb->esc_like( $prefix ) . '%'
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
		$sql          = "DELETE FROM {$this->table} WHERE post_id = %d AND link_type = %s AND source_key LIKE %s AND source_key NOT IN ({$placeholders})";
		$params       = array_merge(
			array( $post_id, 'comment', $wpdb->esc_like( $prefix ) . '%' ),
			$allowed
		);
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Dynamic table; NOT IN placeholders built from sanitized keys.
		$wpdb->query( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * One-time: set source_key on legacy comment rows; drop rows we cannot attribute; dedupe.
	 */
	public function migrate_comment_source_keys() {
		if ( get_option( 'tsoliin_comment_source_key_migrated', false ) ) {
			return;
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Table name from $wpdb->prefix + fixed suffix; migration runs once.
		$rows = $wpdb->get_results(
			"SELECT id, link_url, anchor_text FROM {$this->table} WHERE link_type = 'comment' AND ( source_key = '' OR source_key IS NULL )",
			ARRAY_A
		);
		if ( $rows ) {
			foreach ( $rows as $row ) {
				$id = absint( $row['id'] );
				if ( ! preg_match( '/#(\d+)/', (string) $row['anchor_text'], $m ) ) {
					$wpdb->delete( $this->table, array( 'id' => $id ), array( '%d' ) );
					continue;
				}
				$cid        = absint( $m[1] );
				$authorish  = (bool) preg_match( '/\b(author|autor|auteur)\b/iu', (string) $row['anchor_text'] );
				$source_key = $authorish ? 'c-' . $cid . '-author' : 'c-' . $cid . '-l-' . md5( (string) $row['link_url'] );
				$source_key = $this->sanitize_source_key( $source_key );
				$wpdb->update(
					$this->table,
					array( 'source_key' => $source_key ),
					array( 'id' => $id ),
					array( '%s' ),
					array( '%d' )
				);
			}
		}
		$wpdb->query(
			"DELETE t1 FROM {$this->table} t1 INNER JOIN {$this->table} t2 ON t1.post_id = t2.post_id AND t1.link_url = t2.link_url AND t1.source_key = t2.source_key AND t1.id > t2.id"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		update_option( 'tsoliin_comment_source_key_migrated', true, false );
	}

	/**
	 * Update HTTP check result for a link.
	 */
	public function update_check_result( $link_id, $status_code, $redirect_url, $is_broken ) {
		global $wpdb;

		$link_id      = absint( $link_id );
		$redirect_url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $redirect_url ) );
		$is_broken    = $is_broken ? 1 : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT user_verified, link_url, verify_baseline_link, verify_baseline_redirect FROM {$this->table} WHERE id = %d LIMIT 1",
				$link_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $row ) {
			return;
		}

		$verified = (int) $row->user_verified;
		$clear_lock = false;

		// user_verified freezes display until the stored link URL or redirect outcome changes,
		// or until the link is actually broken (always overrides).
		if ( $verified && ! $is_broken ) {
			$baseline_link  = isset( $row->verify_baseline_link ) ? (string) $row->verify_baseline_link : '';
			$baseline_redir = isset( $row->verify_baseline_redirect ) ? (string) $row->verify_baseline_redirect : '';

			// Rows verified before baseline columns existed: keep previous behaviour (timestamp only).
			if ( '' === $baseline_link && '' === $baseline_redir ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $this->table, array( 'last_checked' => current_time( 'mysql', true ) ), array( 'id' => $link_id ), array( '%s' ), array( '%d' ) );
				return;
			}

			$link_changed = ! TSOLIIN_HTTP::urls_equivalent_for_verify_lock( (string) $row->link_url, $baseline_link );
			$http             = new TSOLIIN_HTTP();
			$redirect_changed = ! $http->redirect_outcomes_match_for_verify( (string) $row->link_url, $baseline_redir, $redirect_url );

			if ( ! $link_changed && ! $redirect_changed ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update( $this->table, array( 'last_checked' => current_time( 'mysql', true ) ), array( 'id' => $link_id ), array( '%s' ), array( '%d' ) );
				return;
			}

			$clear_lock = true;
		} elseif ( $verified && $is_broken ) {
			$clear_lock = true;
		}

		$data = array(
			'status_code'  => intval( $status_code ),
			'redirect_url' => $redirect_url,
			'is_broken'    => $is_broken,
			'last_checked' => current_time( 'mysql', true ),
		);
		$format = array( '%d', '%s', '%d', '%s' );

		if ( $clear_lock ) {
			$data['user_verified']            = 0;
			$data['verify_baseline_link']    = '';
			$data['verify_baseline_redirect'] = '';
			$format[]                         = '%d';
			$format[]                         = '%s';
			$format[]                         = '%s';
		}

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update( $this->table, $data, array( 'id' => $link_id ), $format, array( '%d' ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Update the link_url for a row and reset its check state.
	 */
	public function update_link_url( $link_id, $new_url ) {
		global $wpdb;
		$new_url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $new_url ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table,
			array(
				'link_url'                   => $new_url,
				'status_code'                => 0,
				'redirect_url'               => '',
				'is_broken'                  => 0,
				'last_checked'               => null,
				'user_verified'              => 0, // New URL: clear user decision, needs fresh check.
				'verify_baseline_link'       => '',
				'verify_baseline_redirect'   => '',
			),
			array( 'id' => absint( $link_id ) ),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Mark a link as not broken (manually validated).
	 */
	public function mark_as_not_broken( $link_id ) {
		global $wpdb;
		$link_id = absint( $link_id );
		$link    = $this->get_link( $link_id );
		if ( ! $link ) {
			return false;
		}
		$baseline_link = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $link->link_url ) );
		$redir_col     = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $link->redirect_url ) );
		// After the first "not broken" save, redirect_url is cleared for display; keep the stored baseline on re-mark.
		if ( '' !== $redir_col ) {
			$baseline_redir = $redir_col;
		} elseif ( ! empty( $link->user_verified ) && isset( $link->verify_baseline_redirect ) ) {
			$baseline_redir = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $link->verify_baseline_redirect ) );
		} else {
			$baseline_redir = '';
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table,
			array(
				'is_broken'                  => 0,
				'status_code'                => 200,
				'last_checked'               => current_time( 'mysql', true ),
				'redirect_url'               => '',
				'user_verified'              => 1,
				'verify_baseline_link'       => $baseline_link,
				'verify_baseline_redirect'   => $baseline_redir,
			),
			array( 'id' => $link_id ),
			array( '%d', '%d', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return true;
	}

	/**
	 * Delete a single link row.
	 */
	public function delete_link( $link_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table, array( 'id' => absint( $link_id ) ), array( '%d' ) );
	}

	/**
	 * Delete all link rows for a post.
	 */
	public function delete_links_for_post( $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table, array( 'post_id' => absint( $post_id ) ), array( '%d' ) );
	}

	/**
	 * Reset all last_checked to NULL (forces full re-check).
	 */
	public function reset_all_for_recheck() {
		global $wpdb;
		// Reset ALL links, including manually verified rows, so a full check can
		// detect real regressions when previously "Not broken" links change state.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "UPDATE {$this->table} SET last_checked = NULL" );
	}

	// -------------------------------------------------------------------------
	// Read operations
	// -------------------------------------------------------------------------

	/**
	 * Get a single link row.
	 *
	 * @param int $link_id Row ID.
	 * @return object|null
	 */
	public function get_link( $link_id ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
				absint( $link_id )
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Get paginated links with optional filters.
	 *
	 * @param array $args Query arguments.
	 * @return array { items: array, total: int }
	 */
	public function get_links( $args = array() ) {
		global $wpdb;

		$defaults = array(
			'filter'   => 'all',
			'per_page' => 20,
			'paged'    => 1,
			'orderby'  => 'date_found',
			'order'    => 'DESC',
			'search'   => '',
			'post_id'  => 0,
		);
		$args = wp_parse_args( $args, $defaults );

		$allowed_orderby = array( 'id', 'post_id', 'link_url', 'status_code', 'is_broken', 'last_checked', 'date_found', 'link_type' );
		if ( ! in_array( $args['orderby'], $allowed_orderby, true ) ) {
			$args['orderby'] = 'date_found';
		}
		$order   = 'ASC' === strtoupper( $args['order'] ) ? 'ASC' : 'DESC';
		$orderby = esc_sql( $args['orderby'] );

		$where  = 'WHERE 1=1';
		$params = array();

		switch ( $args['filter'] ) {
			case 'broken':
				$where .= ' AND l.is_broken = 1 AND l.user_verified = 0';
				break;
			case 'redirect':
				// Match both explicit redirect codes AND links where we tracked a redirect_url.
				$where .= " AND ( l.status_code IN (301, 302, 303, 307, 308) OR l.redirect_url != '' ) AND l.user_verified = 0";
				break;
			case 'ok':
				// "OK" means HTTPS (or non-http) success — plain http:// stays under HTTP insecure.
				$where   .= ' AND l.status_code = 200 AND l.link_url NOT LIKE %s AND l.user_verified = 0';
				$params[] = 'http://%';
				break;
			case 'unchecked':
				// Exclude manual locks: same URL row must not appear in multiple tabs (Manual locks vs Unchecked).
				$where .= ' AND l.last_checked IS NULL AND l.user_verified = 0';
				break;
			case 'http_insecure':
				// Links using http:// that could potentially be https://.
				$where   .= ' AND l.link_url LIKE %s AND l.is_broken = 0 AND l.user_verified = 0';
				$params[] = 'http://%';
				break;
			case 'manual_locked':
				$where .= ' AND l.user_verified = 1';
				break;
		}

		// Filter by specific post.
		if ( ! empty( $args['post_id'] ) ) {
			$where    .= ' AND l.post_id = %d';
			$params[]  = absint( $args['post_id'] );
		}

		if ( '' !== $args['search'] ) {
			// Search across URL, anchor text, redirect URL and post title.
			$term      = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where    .= ' AND ( l.link_url LIKE %s OR l.anchor_text LIKE %s OR l.redirect_url LIKE %s OR p.post_title LIKE %s )';
			$params[]  = $term;
			$params[]  = $term;
			$params[]  = $term;
			$params[]  = $term;
		}

		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = ( max( 1, absint( $args['paged'] ) ) - 1 ) * $per_page;

		$count_sql = "SELECT COUNT(*) FROM {$this->table} l $where";
		if ( ! empty( $params ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, $params ) ); // $count_sql built with known table name and whitelisted orderby.
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$total = (int) $wpdb->get_var( $count_sql ); // $count_sql built with known table name, no user input.
		}

		$items_sql    = "SELECT l.*, p.post_title, p.post_status FROM {$this->table} l LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id $where ORDER BY l.$orderby $order LIMIT %d OFFSET %d";
		$items_params = array_merge( $params, array( $per_page, $offset ) );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$items = $wpdb->get_results( $wpdb->prepare( $items_sql, $items_params ) ); // $items_sql built from known table + whitelisted orderby/filter.

		return array(
			'items' => $items ? $items : array(),
			'total' => $total,
		);
	}

	/**
	 * Get the first N unchecked links (last_checked IS NULL).
	 *
	 * Checked rows are updated in place, so the next call returns the next IDs without an SQL offset.
	 *
	 * @param int $limit Batch size.
	 */
	public function get_links_batch_for_check( $limit = 5 ) {
		global $wpdb;
		$limit = max( 1, absint( $limit ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE last_checked IS NULL ORDER BY id ASC LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Get links for the hourly cron: unchecked first, then stale.
	 */
	public function get_links_for_cron_check( $limit = 10, $stale_days = 7 ) {
		global $wpdb;
		$limit     = max( 1, absint( $limit ) );
		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-' . absint( $stale_days ) . ' days' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE last_checked IS NULL OR last_checked < %s ORDER BY CASE WHEN last_checked IS NULL THEN 0 ELSE 1 END ASC, last_checked ASC, id ASC LIMIT %d",
				$threshold,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** @return int */
	public function get_unchecked_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE last_checked IS NULL" );
	}

	/** @return array */
	public function get_stats() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) AS total, SUM(CASE WHEN is_broken=1 AND user_verified=0 THEN 1 ELSE 0 END) AS broken, SUM(CASE WHEN (status_code IN (301,302,303,307,308) OR (redirect_url IS NOT NULL AND redirect_url != '')) AND user_verified=0 THEN 1 ELSE 0 END) AS redirect, SUM(CASE WHEN status_code=200 AND link_url NOT LIKE %s AND user_verified=0 THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN last_checked IS NULL AND user_verified=0 THEN 1 ELSE 0 END) AS unchecked, SUM(CASE WHEN link_url LIKE %s AND is_broken=0 AND user_verified=0 THEN 1 ELSE 0 END) AS http_insecure, SUM(CASE WHEN user_verified=1 THEN 1 ELSE 0 END) AS manual_locked FROM {$this->table}",
				'http://%',
				'http://%'
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$defaults = array( 'total' => 0, 'broken' => 0, 'redirect' => 0, 'ok' => 0, 'unchecked' => 0, 'http_insecure' => 0, 'manual_locked' => 0 );
		return $row ? array_map( 'absint', $row ) : $defaults;
	}

	/** @return int */
	public function get_scanned_post_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$this->table}" );
	}

	/**
	 * DB self-test: insert + delete a dummy row.
	 */
	public function self_test() {
		global $wpdb;
		$result = array( 'table_exists' => false, 'insert_ok' => false, 'error' => '' );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) );
		if ( ! $exists ) {
			$result['error'] = 'Table missing: ' . $this->table;
			return $result;
		}
		$result['table_exists'] = true;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery
		$ins = $wpdb->insert(
			$this->table,
			array( 'post_id' => 0, 'link_url' => 'https://tsoliin-test.invalid', 'anchor_text' => '__tsoliin_test__', 'status_code' => 0, 'redirect_url' => '', 'is_broken' => 0, 'link_type' => 'link', 'source_key' => '', 'last_checked' => null, 'date_found' => current_time( 'mysql', true ) ),
			array( '%d', '%s', '%s', '%d', '%s', '%d', '%s', '%s', '%s', '%s' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery
		if ( ! $ins ) {
			$result['error'] = $wpdb->last_error ?: 'Insert failed';
			return $result;
		}
		$result['insert_ok'] = true;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table, array( 'anchor_text' => '__tsoliin_test__' ), array( '%s' ) );
		return $result;
	}

	/**
	 * Count links that need checking: NULL or older than N days.
	 *
	 * @param int $stale_days Days after which a link is considered stale.
	 * @return int
	 */
	public function count_stale_links( $stale_days = 7 ) {
		global $wpdb;
		$threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-' . absint( $stale_days ) . ' days' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$this->table} WHERE last_checked IS NULL OR last_checked < %s",
				$threshold
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Clean up trivial trailing-slash redirect_url records from old scans.
	 * Called by maybe_upgrade_db() on version bump.
	 */
	public function cleanup_trivial_redirects() {
		global $wpdb;
		// Find records where redirect_url = link_url + "/" only.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$this->table}
			SET status_code = 200, redirect_url = '', is_broken = 0
			WHERE redirect_url != ''
			  AND ( CONCAT(link_url, '/') = redirect_url
			     OR CONCAT(RTRIM(REPLACE(link_url, '/', '')), '/') = RTRIM(REPLACE(redirect_url, '/', '')) )"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Get stats scoped to a single post.
	 *
	 * @param int $post_id Post ID.
	 * @return array
	 */
	public function get_stats_for_post( $post_id ) {
		global $wpdb;
		$post_id = absint( $post_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) AS total, SUM(CASE WHEN is_broken=1 AND user_verified=0 THEN 1 ELSE 0 END) AS broken, SUM(CASE WHEN (status_code IN (301,302,303,307,308) OR (redirect_url IS NOT NULL AND redirect_url != '')) AND user_verified=0 THEN 1 ELSE 0 END) AS redirect, SUM(CASE WHEN status_code=200 AND link_url NOT LIKE %s AND user_verified=0 THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN last_checked IS NULL AND user_verified=0 THEN 1 ELSE 0 END) AS unchecked, SUM(CASE WHEN link_url LIKE %s AND is_broken=0 AND user_verified=0 THEN 1 ELSE 0 END) AS http_insecure, SUM(CASE WHEN user_verified=1 THEN 1 ELSE 0 END) AS manual_locked FROM {$this->table} WHERE post_id = %d",
				'http://%',
				'http://%',
				$post_id
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$defaults = array( 'total' => 0, 'broken' => 0, 'redirect' => 0, 'ok' => 0, 'unchecked' => 0, 'http_insecure' => 0, 'manual_locked' => 0 );
		return $row ? array_map( 'absint', $row ) : $defaults;
	}

	/**
	 * Clean up trivial query-string-only redirect records (e.g. ?ucbcb=1 tracking params).
	 * Called on version upgrade. Updates status_code to 200 and clears redirect_url
	 * when the redirect destination is the original URL with only a query string appended.
	 */
	public function cleanup_querystring_redirects() {
		global $wpdb;
		// Find rows where redirect_url starts with link_url + "?" (same URL, query added).
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			"UPDATE {$this->table}
			SET status_code = 200, redirect_url = '', is_broken = 0
			WHERE redirect_url != ''
			  AND LOCATE( CONCAT(link_url, '?'), redirect_url ) = 1"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Count hard-broken links (broken and without redirect destination).
	 *
	 * @return int
	 */
	public function count_hard_broken_links() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$this->table} WHERE is_broken = 1 AND (redirect_url = '' OR redirect_url IS NULL) AND status_code NOT BETWEEN 300 AND 399"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Get hard-broken links (broken and without redirect destination).
	 *
	 * @param int $limit Maximum rows.
	 * @return array
	 */
	public function get_hard_broken_links( $limit = 200 ) {
		global $wpdb;
		$limit = max( 1, absint( $limit ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT l.id, l.post_id, l.link_url, l.anchor_text, l.status_code, l.last_checked, p.post_title
				FROM {$this->table} l
				LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id
				WHERE l.is_broken = 1
				  AND (l.redirect_url = '' OR l.redirect_url IS NULL)
				  AND l.status_code NOT BETWEEN 300 AND 399
				ORDER BY l.last_checked DESC, l.id DESC
				LIMIT %d",
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}
}
