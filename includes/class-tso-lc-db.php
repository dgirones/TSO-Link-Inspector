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

if ( ! class_exists( 'TSOLIIN_Quality', false ) ) {
	require_once __DIR__ . '/class-tso-lc-quality.php';
}

/**
 * Class TSOLIIN_DB
 */
class TSOLIIN_DB {

	/** @var string Full table name. */
	private $table;

	/** @var bool|null Cached result of table_exists() for this request. */
	private $table_exists_cache = null;

	/** @var bool Whether upgrade_schema() already ran this request. */
	private $schema_upgraded = false;

	/** @var array<string, array<string, int>> Request cache for get_stats() / get_stats_for_post(). */
	private static $stats_cache = array();

	/** @var array<int, string[]> Request cache for get_broken_link_urls_for_post(). */
	private static $broken_urls_by_post_cache = array();

	public function __construct() {
		global $wpdb;
		$this->table = $wpdb->prefix . 'tso_link_inspector';
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
			redirect_chain text NULL,
			is_broken tinyint(1) NOT NULL DEFAULT 0,
			user_verified tinyint(1) NOT NULL DEFAULT 0,
			verify_baseline_link varchar(2083) NOT NULL DEFAULT '',
			verify_baseline_redirect varchar(2083) NOT NULL DEFAULT '',
			link_type varchar(10) NOT NULL DEFAULT 'link',
			source_key varchar(64) NOT NULL DEFAULT '',
			last_checked datetime DEFAULT NULL,
			consecutive_failures smallint(6) NOT NULL DEFAULT 0,
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
		$this->upgrade_schema();
	}

	/**
	 * Add columns introduced after initial releases (idempotent).
	 *
	 * @return void
	 */
	public function upgrade_schema() {
		if ( $this->schema_upgraded || ! $this->table_exists() ) {
			return;
		}
		$this->schema_upgraded = true;
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$column = $wpdb->get_var( "SHOW COLUMNS FROM `{$this->table}` LIKE 'redirect_chain'" );
		if ( empty( $column ) ) {
			$wpdb->query( "ALTER TABLE `{$this->table}` ADD COLUMN redirect_chain text NULL AFTER redirect_url" );
		}
		$column = $wpdb->get_var( "SHOW COLUMNS FROM `{$this->table}` LIKE 'consecutive_failures'" );
		if ( empty( $column ) ) {
			$wpdb->query( "ALTER TABLE `{$this->table}` ADD COLUMN consecutive_failures smallint(6) NOT NULL DEFAULT 0 AFTER last_checked" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Decode stored redirect chain JSON.
	 *
	 * @param string $json Stored JSON.
	 * @return array<int, array{code:int,url:string}>
	 */
	public static function decode_redirect_chain( $json ) {
		$json = trim( (string) $json );
		if ( '' === $json ) {
			return array();
		}
		$decoded = json_decode( $json, true );
		if ( ! is_array( $decoded ) ) {
			return array();
		}
		$chain = array();
		foreach ( $decoded as $hop ) {
			if ( ! is_array( $hop ) ) {
				continue;
			}
			$url = isset( $hop['url'] ) ? trim( (string) $hop['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			$chain[] = array(
				'code' => isset( $hop['code'] ) ? (int) $hop['code'] : 0,
				'url'  => $url,
			);
		}
		return $chain;
	}

	/**
	 * Encode redirect hops for storage.
	 *
	 * @param array<int, array{code:int,url:string}> $chain Redirect hops.
	 * @return string
	 */
	public static function encode_redirect_chain( $chain ) {
		if ( ! is_array( $chain ) || empty( $chain ) ) {
			return '';
		}
		$safe = array();
		foreach ( $chain as $hop ) {
			if ( ! is_array( $hop ) ) {
				continue;
			}
			$url = isset( $hop['url'] ) ? trim( (string) $hop['url'] ) : '';
			if ( '' === $url ) {
				continue;
			}
			$safe[] = array(
				'code' => isset( $hop['code'] ) ? (int) $hop['code'] : 0,
				'url'  => $url,
			);
		}
		return empty( $safe ) ? '' : (string) wp_json_encode( $safe );
	}

	/**
	 * Ensure the table exists (at most one SHOW TABLES query per request).
	 */
	public function ensure_table_exists() {
		if ( $this->table_exists() ) {
			$this->upgrade_schema();
			return;
		}
		$this->create_table();
		$this->table_exists_cache = true;
	}

	/**
	 * Whether the inspector table exists (one query per request, cached).
	 *
	 * @return bool
	 */
	private function table_exists() {
		if ( null !== $this->table_exists_cache ) {
			return $this->table_exists_cache;
		}
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$this->table_exists_cache = (bool) $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $this->table ) );
		return $this->table_exists_cache;
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
	 * @param string $link_type   link|image|iframe|comment|menu.
	 * @param string $source_key  Empty for post content; for comments e.g. c-123-author or c-123-l-<md5>.
	 * @return int|false
	 */
	public function upsert_link( $post_id, $link_url, $anchor, $link_type = 'link', $source_key = '' ) {
		global $wpdb;

		$post_id    = absint( $post_id );
		$link_url   = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $link_url ) );
		$anchor     = sanitize_text_field( (string) $anchor );
		$types      = array( 'link', 'image', 'iframe', 'plain', 'comment', 'menu', 'widget', 'term', 'template', 'wp_block' );
		$link_type  = in_array( $link_type, $types, true ) ? $link_type : 'link';
		$source_key = $this->sanitize_source_key( (string) $source_key );

		$external_types = array( 'widget', 'term' );
		if ( ! $post_id ) {
			if ( ! in_array( $link_type, $external_types, true ) || '' === $source_key ) {
				return false;
			}
		} elseif ( '' === $link_url ) {
			return false;
		}

		if ( $post_id && '' === $link_url ) {
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
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT anchor_text FROM {$this->table} WHERE id = %d LIMIT 1",
					absint( $existing )
				)
			);
			$anchor_to_store = $anchor;
			if ( '' === $anchor && $row && '' !== trim( (string) $row->anchor_text ) ) {
				$anchor_to_store = (string) $row->anchor_text;
			}
			$wpdb->update(
				$this->table,
				array( 'anchor_text' => $anchor_to_store, 'link_type' => $link_type ),
				array( 'id' => absint( $existing ) ),
				array( '%s', '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return absint( $existing );
		}

		$alias_id = $this->find_link_id_by_url_alias( $post_id, $link_url, $source_key );
		if ( $alias_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT anchor_text FROM {$this->table} WHERE id = %d LIMIT 1",
					absint( $alias_id )
				)
			);
			$anchor_to_store = $anchor;
			if ( '' === $anchor && $row && '' !== trim( (string) $row->anchor_text ) ) {
				$anchor_to_store = (string) $row->anchor_text;
			}
			$wpdb->update(
				$this->table,
				array(
					'link_url'    => $link_url,
					'anchor_text' => $anchor_to_store,
					'link_type'   => $link_type,
				),
				array( 'id' => absint( $alias_id ) ),
				array( '%s', '%s', '%s' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return absint( $alias_id );
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

		if ( $wpdb->insert_id ) {
			self::clear_stats_cache();
			return absint( $wpdb->insert_id );
		}
		return false;
	}

	/**
	 * Find an existing row for the same logical URL (e.g. Play Store id variants).
	 *
	 * @param int    $post_id    Post ID.
	 * @param string $link_url   Incoming URL.
	 * @param string $source_key Source key.
	 * @return int Row id or 0.
	 */
	private function find_link_id_by_url_alias( $post_id, $link_url, $source_key ) {
		global $wpdb;

		$post_id    = absint( $post_id );
		$source_key = $this->sanitize_source_key( (string) $source_key );
		if ( ! $post_id ) {
			return 0;
		}

		$play_id = TSOLIIN_HTTP::parse_play_store_app_id( $link_url );
		if ( '' !== $play_id ) {
			$like = '%' . $wpdb->esc_like( 'id=' . $play_id ) . '%';
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$this->table} WHERE post_id = %d AND source_key = %s AND link_url LIKE %s LIMIT 1",
					$post_id,
					$source_key,
					$like
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $found ? absint( $found ) : 0;
		}

		return 0;
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
	 * Drop menu link rows whose source_key for this menu item is no longer present.
	 *
	 * @param int      $menu_item_id Nav menu item post ID.
	 * @param string[] $allowed_keys Non-empty keys still valid (e.g. mi-42).
	 * @param string   $keep_link_url Current menu URL to keep for this item (drops stale URLs).
	 * @return void
	 */
	public function delete_menu_sources_not_in( $menu_item_id, array $allowed_keys, $keep_link_url = '' ) {
		global $wpdb;
		$item_id = absint( $menu_item_id );
		if ( ! $item_id ) {
			return;
		}
		$source_key = $this->sanitize_source_key( 'mi-' . $item_id );
		$allowed    = array();
		foreach ( $allowed_keys as $k ) {
			$k = $this->sanitize_source_key( (string) $k );
			if ( '' !== $k && $k === $source_key ) {
				$allowed[] = $k;
			}
		}
		$allowed = array_values( array_unique( $allowed ) );

		if ( empty( $allowed ) ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table} WHERE link_type = %s AND source_key = %s",
					'menu',
					$source_key
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			self::clear_stats_cache();
			return;
		}

		$keep_link_url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $keep_link_url ) );
		if ( '' !== $keep_link_url ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$this->table} WHERE link_type = %s AND source_key = %s AND link_url != %s",
					'menu',
					$source_key,
					$keep_link_url
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			self::clear_stats_cache();
		}
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
		self::clear_stats_cache();
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
			self::clear_stats_cache();
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
		self::clear_stats_cache();
	}

	/**
	 * Drop rows for a source prefix when keys are no longer present (widgets, terms, templates, etc.).
	 *
	 * @param string   $link_type    link_type value.
	 * @param string   $prefix       source_key prefix (e.g. t-42-, wg-sidebar-1-text-2-).
	 * @param string[] $allowed_keys Valid keys for this source.
	 * @param int      $post_id      Optional post scope (0 = any).
	 * @return void
	 */
	public function delete_sources_not_in( $link_type, $prefix, array $allowed_keys, $post_id = 0 ) {
		global $wpdb;
		$link_type = sanitize_key( (string) $link_type );
		$prefix    = $this->sanitize_source_key( (string) $prefix );
		$post_id   = absint( $post_id );
		if ( '' === $link_type || '' === $prefix ) {
			return;
		}

		$allowed = array();
		foreach ( $allowed_keys as $k ) {
			$k = $this->sanitize_source_key( (string) $k );
			if ( '' !== $k && 0 === strpos( $k, $prefix ) ) {
				$allowed[] = $k;
			}
		}
		$allowed = array_values( array_unique( $allowed ) );
		$like    = $wpdb->esc_like( $prefix ) . '%';

		if ( empty( $allowed ) ) {
			if ( $post_id ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$this->table} WHERE post_id = %d AND link_type = %s AND source_key LIKE %s",
						$post_id,
						$link_type,
						$like
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			} else {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$wpdb->query(
					$wpdb->prepare(
						"DELETE FROM {$this->table} WHERE link_type = %s AND source_key LIKE %s",
						$link_type,
						$like
					)
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			}
			self::clear_stats_cache();
			return;
		}

		$placeholders = implode( ',', array_fill( 0, count( $allowed ), '%s' ) );
		if ( $post_id ) {
			$sql    = "DELETE FROM {$this->table} WHERE post_id = %d AND link_type = %s AND source_key LIKE %s AND source_key NOT IN ({$placeholders})";
			$params = array_merge( array( $post_id, $link_type, $like ), $allowed );
		} else {
			$sql    = "DELETE FROM {$this->table} WHERE link_type = %s AND source_key LIKE %s AND source_key NOT IN ({$placeholders})";
			$params = array_merge( array( $link_type, $like ), $allowed );
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( $wpdb->prepare( $sql, $params ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		self::clear_stats_cache();
	}

	/**
	 * Update HTTP check result for a link.
	 *
	 * @param int          $link_id        Link ID.
	 * @param int          $status_code    HTTP status.
	 * @param string       $redirect_url   Final redirect destination.
	 * @param int|bool     $is_broken      Broken flag.
	 * @param string|array $redirect_chain JSON string or hop array.
	 */
	public function update_check_result( $link_id, $status_code, $redirect_url, $is_broken, $redirect_chain = '' ) {
		global $wpdb;

		$link_id      = absint( $link_id );
		$redirect_url = trim( str_replace( array( "\0", "\r", "\n" ), '', (string) $redirect_url ) );
		$is_broken    = $is_broken ? 1 : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT user_verified, link_url, verify_baseline_link, verify_baseline_redirect, is_broken, consecutive_failures FROM {$this->table} WHERE id = %d LIMIT 1",
				$link_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( ! $row ) {
			return;
		}

		$normalized = TSOLIIN_HTTP::normalize_stored_check_result(
			(string) $row->link_url,
			$status_code,
			$redirect_url,
			$is_broken
		);
		$status_code  = (int) $normalized['status_code'];
		$redirect_url = (string) $normalized['redirect_url'];
		$is_broken    = (int) $normalized['is_broken'];
		$chain_json   = is_array( $redirect_chain )
			? self::encode_redirect_chain( $redirect_chain )
			: trim( (string) $redirect_chain );
		if ( '' === $redirect_url ) {
			$chain_json = '';
		}

		$verified = (int) $row->user_verified;
		$clear_lock = false;
		$prev_failures = isset( $row->consecutive_failures ) ? (int) $row->consecutive_failures : 0;
		$failures      = $is_broken ? $prev_failures + 1 : 0;

		// user_verified freezes display until the stored link URL or redirect outcome changes,
		// or until the link is actually broken (always overrides).
		if ( $verified && ! $is_broken ) {
			$baseline_link  = isset( $row->verify_baseline_link ) ? (string) $row->verify_baseline_link : '';
			$baseline_redir = isset( $row->verify_baseline_redirect ) ? (string) $row->verify_baseline_redirect : '';

			// Rows verified before baseline columns existed: backfill baselines, then evaluate changes.
			if ( '' === $baseline_link && '' === $baseline_redir ) {
				$baseline_link  = (string) $row->link_url;
				$baseline_redir = trim( (string) $row->redirect_url );
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$this->table,
					array(
						'verify_baseline_link'     => $baseline_link,
						'verify_baseline_redirect' => $baseline_redir,
						'last_checked'             => current_time( 'mysql', true ),
					),
					array( 'id' => $link_id ),
					array( '%s', '%s', '%s' ),
					array( '%d' )
				);
			}

			$link_changed = ! TSOLIIN_HTTP::urls_equivalent_for_verify_lock( (string) $row->link_url, $baseline_link );
			$http             = new TSOLIIN_HTTP();
			$redirect_changed = ! $http->redirect_outcomes_match_for_verify( (string) $row->link_url, $baseline_redir, $redirect_url );

			if ( ! $link_changed && ! $redirect_changed ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$wpdb->update(
					$this->table,
					array(
						'last_checked'          => current_time( 'mysql', true ),
						'consecutive_failures'  => 0,
					),
					array( 'id' => $link_id ),
					array( '%s', '%d' ),
					array( '%d' )
				);
				self::clear_stats_cache();
				return;
			}

			$clear_lock = true;
		} elseif ( $verified && $is_broken ) {
			$clear_lock = true;
		}

		$data = array(
			'status_code'          => intval( $status_code ),
			'redirect_url'         => $redirect_url,
			'redirect_chain'       => $chain_json,
			'is_broken'            => $is_broken,
			'last_checked'         => ( -8 === $status_code ) ? null : current_time( 'mysql', true ),
			'consecutive_failures' => ( -8 === $status_code ) ? $prev_failures : $failures,
		);
		$format = array( '%d', '%s', '%s', '%d', '%s', '%d' );

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
		self::clear_stats_cache();
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
				'consecutive_failures'       => 0,
				'user_verified'              => 0, // New URL: clear user decision, needs fresh check.
				'verify_baseline_link'       => '',
				'verify_baseline_redirect'   => '',
			),
			array( 'id' => absint( $link_id ) ),
			array( '%s', '%d', '%s', '%d', '%s', '%d', '%s', '%s', '%d' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::clear_stats_cache();
	}

	/**
	 * Update stored anchor text without resetting HTTP check state.
	 *
	 * @param int    $link_id Link row ID.
	 * @param string $anchor  New anchor text.
	 * @return void
	 */
	public function update_link_anchor_text( $link_id, $anchor ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$this->table,
			array( 'anchor_text' => sanitize_text_field( (string) $anchor ) ),
			array( 'id' => absint( $link_id ) ),
			array( '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::clear_stats_cache();
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
				'redirect_chain'             => '',
				'user_verified'              => 1,
				'verify_baseline_link'       => $baseline_link,
				'verify_baseline_redirect'   => $baseline_redir,
			),
			array( 'id' => $link_id ),
			array( '%d', '%d', '%s', '%s', '%s', '%d', '%s', '%s' ),
			array( '%d' )
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::clear_stats_cache();
		return true;
	}

	/**
	 * Delete a single link row.
	 */
	public function delete_link( $link_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table, array( 'id' => absint( $link_id ) ), array( '%d' ) );
		self::clear_stats_cache();
	}

	/**
	 * Delete all link rows for a post.
	 */
	public function delete_links_for_post( $post_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->delete( $this->table, array( 'post_id' => absint( $post_id ) ), array( '%d' ) );
		self::clear_stats_cache();
	}

	/**
	 * Reset last_checked to NULL so links are queued for HTTP check again.
	 *
	 * @param int $post_id When > 0, only links for that post; otherwise the whole table.
	 */
	public function reset_for_recheck( $post_id = 0 ) {
		global $wpdb;
		$post_id = absint( $post_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $post_id > 0 ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( $wpdb->prepare( "UPDATE {$this->table} SET last_checked = NULL WHERE post_id = %d", $post_id ) );
		} else {
			// Reset ALL links, including manually verified rows, so a full check can
			// detect real regressions when previously "Not broken" links change state.
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
			$wpdb->query( "UPDATE {$this->table} SET last_checked = NULL" );
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		self::clear_stats_cache();
	}

	/**
	 * Reset all last_checked to NULL (forces full re-check).
	 *
	 * @deprecated 1.9.8 Use reset_for_recheck() instead.
	 */
	public function reset_all_for_recheck() {
		$this->reset_for_recheck( 0 );
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
	 * Get a link row by exact post URL, type, and source key.
	 *
	 * @param int    $post_id    Post ID (0 for widget/term).
	 * @param string $link_url   Stored URL.
	 * @param string $link_type  Link type.
	 * @param string $source_key Source key.
	 * @return object|null
	 */
	public function get_link_by_post_url( $post_id, $link_url, $link_type, $source_key = '' ) {
		global $wpdb;
		$post_id    = absint( $post_id );
		$link_url   = trim( (string) $link_url );
		$link_type  = sanitize_key( (string) $link_type );
		$source_key = $this->sanitize_source_key( (string) $source_key );
		if ( '' === $link_url ) {
			return null;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE post_id = %d AND link_url = %s AND link_type = %s AND source_key = %s LIMIT 1",
				$post_id,
				$link_url,
				$link_type,
				$source_key
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ? $row : null;
	}

	/**
	 * Get a link row by source key.
	 *
	 * @param string   $source_key Source key.
	 * @param string   $link_type  Link type.
	 * @param int|null $post_id    Optional post ID filter.
	 * @return object|null
	 */
	public function get_link_by_source_key( $source_key, $link_type, $post_id = null ) {
		global $wpdb;
		$source_key = $this->sanitize_source_key( (string) $source_key );
		$link_type  = sanitize_key( (string) $link_type );
		if ( '' === $source_key ) {
			return null;
		}
		if ( null !== $post_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$row = $wpdb->get_row(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} WHERE source_key = %s AND link_type = %s AND post_id = %d LIMIT 1",
					$source_key,
					$link_type,
					absint( $post_id )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return $row ? $row : null;
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE source_key = %s AND link_type = %s LIMIT 1",
				$source_key,
				$link_type
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $row ? $row : null;
	}

	/**
	 * Get link rows for a post and type (post content uses empty source_key).
	 *
	 * @param int         $post_id    Post ID.
	 * @param string      $link_type  Link type.
	 * @param string|null $source_key Source key filter; null = any.
	 * @return object[]
	 */
	public function get_links_by_post_and_type( $post_id, $link_type, $source_key = '' ) {
		global $wpdb;
		$post_id   = absint( $post_id );
		$link_type = sanitize_key( (string) $link_type );
		if ( null === $source_key ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} WHERE post_id = %d AND link_type = %s ORDER BY id ASC",
					$post_id,
					$link_type
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return is_array( $rows ) ? $rows : array();
		}
		$source_key = $this->sanitize_source_key( (string) $source_key );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE post_id = %d AND link_type = %s AND source_key = %s ORDER BY id ASC",
				$post_id,
				$link_type,
				$source_key
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Get link rows whose source_key starts with a prefix.
	 *
	 * @param string   $link_type Link type.
	 * @param string   $prefix    Source key prefix.
	 * @param int|null $post_id   Optional post ID filter.
	 * @return object[]
	 */
	public function get_links_by_source_prefix( $link_type, $prefix, $post_id = null ) {
		global $wpdb;
		$link_type = sanitize_key( (string) $link_type );
		$prefix    = $this->sanitize_source_key( (string) $prefix );
		if ( '' === $prefix ) {
			return array();
		}
		$like = $wpdb->esc_like( $prefix ) . '%';
		if ( null !== $post_id ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} WHERE link_type = %s AND source_key LIKE %s AND post_id = %d ORDER BY id ASC",
					$link_type,
					$like,
					absint( $post_id )
				)
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			return is_array( $rows ) ? $rows : array();
		}
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$this->table} WHERE link_type = %s AND source_key LIKE %s ORDER BY id ASC",
				$link_type,
				$like
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_array( $rows ) ? $rows : array();
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
			'filter'         => 'all',
			'quality_filter' => '',
			'scope'          => 'all',
			'per_page'       => 20,
			'paged'          => 1,
			'orderby'        => 'date_found',
			'order'          => 'DESC',
			'search'         => '',
			'post_id'        => 0,
		);
		$args = wp_parse_args( $args, $defaults );
		$args['scope'] = $this->sanitize_link_scope( $args['scope'] );
		$args          = $this->normalize_link_query_filters( $args );

		if ( 'unpublished_target' === $args['quality_filter'] ) {
			return $this->get_links_unpublished_target( $args );
		}

		return $this->query_links_paginated( $args );
	}

	/**
	 * Split legacy quality values stored in filter into quality_filter.
	 *
	 * @param array $args Query arguments.
	 * @return array
	 */
	private function normalize_link_query_filters( array $args ) {
		$quality_keys = array( 'empty_anchor', 'generic_anchor', 'unpublished_target' );
		$filter       = sanitize_key( (string) $args['filter'] );
		$quality      = sanitize_key( (string) $args['quality_filter'] );

		if ( in_array( $filter, $quality_keys, true ) ) {
			if ( '' === $quality ) {
				$args['quality_filter'] = $filter;
			}
			$args['filter'] = 'all';
		} elseif ( in_array( $quality, $quality_keys, true ) ) {
			$args['quality_filter'] = $quality;
		} else {
			$args['quality_filter'] = '';
		}

		$status_allowed = array( 'all', 'broken', 'redirect', 'ok', 'unchecked', 'http_insecure', 'manual_locked' );
		if ( ! in_array( sanitize_key( (string) $args['filter'] ), $status_allowed, true ) ) {
			$args['filter'] = 'all';
		}

		return $args;
	}

	/**
	 * Paginated links query (core SQL).
	 *
	 * @param array $args Query arguments.
	 * @return array { items: array, total: int }
	 */
	private function query_links_paginated( $args ) {
		global $wpdb;

		if ( 'redirect' === $args['filter'] ) {
			$this->cleanup_transparent_redirects();
		} else {
			$this->maybe_cleanup_transparent_redirects();
		}

		$scope = $this->sanitize_link_scope( $args['scope'] );

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
				// HTTP insecure rows take priority; they move here after the post URL uses HTTPS.
				$where .= ' AND ' . self::sql_redirect_match( 'l.' );
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

		switch ( sanitize_key( (string) $args['quality_filter'] ) ) {
			case 'empty_anchor':
				$where .= TSOLIIN_Quality::build_empty_anchor_sql_where();
				break;
			case 'generic_anchor':
				$generic_sql = TSOLIIN_Quality::build_generic_anchor_sql();
				$where      .= $generic_sql['where'];
				$params      = array_merge( $params, $generic_sql['params'] );
				break;
		}

		// Internal/external scope is applied in PHP (same rules as link_matches_scope) — SQL LIKE scope was unreliable.
		$use_php_scope = 'all' !== $scope;

		// Filter by specific post.
		if ( ! empty( $args['post_id'] ) ) {
			$where    .= ' AND l.post_id = %d';
			$params[]  = absint( $args['post_id'] );
		}

		if ( '' !== $args['search'] ) {
			$term   = '%' . $wpdb->esc_like( sanitize_text_field( $args['search'] ) ) . '%';
			$where .= ' AND l.link_url LIKE %s';
			$params[] = $term;
		}

		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = ( max( 1, absint( $args['paged'] ) ) - 1 ) * $per_page;

		$posts_join = " LEFT JOIN {$wpdb->posts} p ON p.ID = l.post_id ";

		if ( $use_php_scope ) {
			$items_sql = "SELECT l.*, p.post_title, p.post_status FROM {$this->table} l{$posts_join}{$where} ORDER BY l.$orderby $order";
			if ( ! empty( $params ) ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$all_items = $wpdb->get_results( $wpdb->prepare( $items_sql, $params ) );
			} else {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
				$all_items = $wpdb->get_results( $items_sql );
			}

			$filtered = array();
			foreach ( $all_items ? $all_items : array() as $link ) {
				if ( $this->link_matches_scope( $link, $scope ) ) {
					$filtered[] = $link;
				}
			}

			$total = count( $filtered );
			return array(
				'items' => array_slice( $filtered, $offset, $per_page ),
				'total' => $total,
			);
		}

		$count_sql = "SELECT COUNT(*) FROM {$this->table} l{$posts_join}{$where}";
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
	 * Internal links pointing to draft/private/trash posts (PHP filter + pagination).
	 *
	 * @param array $args Query arguments.
	 * @return array { items: array, total: int }
	 */
	private function get_links_unpublished_target( $args ) {
		if ( 'external' === $this->sanitize_link_scope( $args['scope'] ) ) {
			return array(
				'items' => array(),
				'total' => 0,
			);
		}

		$fetch_args               = $args;
		$fetch_args['quality_filter'] = '';
		$fetch_args['scope']      = 'internal';
		$fetch_args['paged']      = 1;
		$fetch_args['per_page']   = 99999;

		$result   = $this->query_links_paginated( $fetch_args );
		$filtered = array_values(
			array_filter(
				$result['items'],
				function ( $link ) use ( $args ) {
					if ( ! TSOLIIN_Quality::points_to_unpublished( $link ) ) {
						return false;
					}
					return $this->link_matches_filter( $link, $args['filter'] );
				}
			)
		);

		$allowed_orderby = array( 'id', 'post_id', 'link_url', 'status_code', 'is_broken', 'last_checked', 'date_found', 'link_type' );
		$orderby_key     = in_array( $args['orderby'], $allowed_orderby, true ) ? $args['orderby'] : 'date_found';
		$order_dir       = 'ASC' === strtoupper( $args['order'] ) ? 1 : -1;

		usort(
			$filtered,
			static function ( $a, $b ) use ( $orderby_key, $order_dir ) {
				$va = isset( $a->$orderby_key ) ? $a->$orderby_key : '';
				$vb = isset( $b->$orderby_key ) ? $b->$orderby_key : '';
				if ( $va === $vb ) {
					return 0;
				}
				return ( $va < $vb ? -1 : 1 ) * $order_dir;
			}
		);

		$per_page = max( 1, absint( $args['per_page'] ) );
		$paged    = max( 1, absint( $args['paged'] ) );
		$offset   = ( $paged - 1 ) * $per_page;

		return array(
			'items' => array_slice( $filtered, $offset, $per_page ),
			'total' => count( $filtered ),
		);
	}

	/**
	 * Count internal links pointing to non-published posts.
	 *
	 * @param int $post_id Optional source post scope.
	 * @return int
	 */
	public function count_unpublished_targets( $post_id = 0 ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			$cached = get_transient( 'tsoliin_unpub_cnt_v3_all' );
			if ( false !== $cached ) {
				return (int) $cached;
			}
		}

		$result = $this->get_links(
			array(
				'filter'         => 'all',
				'quality_filter' => 'unpublished_target',
				'scope'          => 'internal',
				'per_page'       => 1,
				'paged'          => 1,
				'post_id'        => $post_id,
			)
		);
		$count = (int) $result['total'];
		if ( ! $post_id ) {
			set_transient( 'tsoliin_unpub_cnt_v3_all', $count, 15 * MINUTE_IN_SECONDS );
		}
		return $count;
	}

	/**
	 * Sanitize internal/external scope key.
	 *
	 * @param string $scope Raw scope.
	 * @return string
	 */
	public function sanitize_link_scope( $scope ) {
		$scope = sanitize_key( (string) $scope );
		return in_array( $scope, array( 'all', 'internal', 'external' ), true ) ? $scope : 'all';
	}

	/**
	 * Sanitize scope from a raw request value (GET/POST).
	 *
	 * @param mixed $raw Unslashed scope string from request input.
	 * @return string
	 */
	public function sanitize_scope_input( $raw ) {
		return $this->sanitize_link_scope( sanitize_key( (string) $raw ) );
	}

	/**
	 * Build SQL fragment for internal vs external link scope.
	 *
	 * @param string $scope Scope key.
	 * @return array{where:string,params:array}
	 */
	private function build_link_scope_sql( $scope ) {
		$scope = $this->sanitize_link_scope( $scope );
		if ( 'all' === $scope ) {
			return array( 'where' => '', 'params' => array() );
		}

		$internal = TSOLIIN_HTTP::build_internal_link_scope_sql( 'l.link_url' );
		if ( 'internal' === $scope ) {
			return array(
				'where'  => ' AND ' . $internal['sql'],
				'params' => $internal['params'],
			);
		}

		$external = TSOLIIN_HTTP::build_external_link_scope_sql( 'l.link_url' );
		return array(
			'where'  => ' AND ' . $external['sql'],
			'params' => $external['params'],
		);
	}

	/**
	 * Whether a link row matches an internal/external scope.
	 *
	 * @param object|array $link  Link row.
	 * @param string       $scope Scope key.
	 * @return bool
	 */
	public function link_matches_scope( $link, $scope ) {
		$scope = $this->sanitize_link_scope( $scope );
		if ( 'all' === $scope ) {
			return true;
		}
		$link     = (object) $link;
		$post_id  = isset( $link->post_id ) ? (int) $link->post_id : 0;
		$link_url = isset( $link->link_url ) ? (string) $link->link_url : '';
		$internal = TSOLIIN_HTTP::is_internal_link_url( $link_url, $post_id );
		return ( 'internal' === $scope ) ? $internal : ! $internal;
	}

	/**
	 * Posts grouped by link issue counts (for the posts summary view).
	 *
	 * @param array $args Query args.
	 * @return array{items:array,total:int}
	 */
	public function get_posts_link_summary( $args = array() ) {
		global $wpdb;

		$this->maybe_cleanup_transparent_redirects();

		$defaults = array(
			'per_page' => 20,
			'paged'    => 1,
		);
		$args     = wp_parse_args( $args, $defaults );
		$per_page = max( 1, absint( $args['per_page'] ) );
		$offset   = ( max( 1, absint( $args['paged'] ) ) - 1 ) * $per_page;

		$having    = 'HAVING broken > 0 OR redirect_count > 0 OR unchecked_count > 0';
		$base_from = "FROM {$this->table} l INNER JOIN {$wpdb->posts} p ON p.ID = l.post_id WHERE l.post_id > 0 AND p.post_status != 'trash' GROUP BY l.post_id, p.post_title";
		$select_agg = "SELECT l.post_id, p.post_title,
			COUNT(*) AS total_links,
			SUM(CASE WHEN l.is_broken = 1 AND l.user_verified = 0 THEN 1 ELSE 0 END) AS broken,
			SUM(CASE WHEN " . self::sql_redirect_match( 'l.' ) . " THEN 1 ELSE 0 END) AS redirect_count,
			SUM(CASE WHEN l.last_checked IS NULL AND l.user_verified = 0 THEN 1 ELSE 0 END) AS unchecked_count";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count_inner = "SELECT COUNT(*) FROM (
			{$select_agg}
			{$base_from}
			{$having}
		) AS tsoliin_post_summary";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static aggregate subquery; table from prefix.
		$total = (int) $wpdb->get_var( $count_inner );

		$items_sql = "{$select_agg}
			{$base_from}
			{$having}
			ORDER BY broken DESC, redirect_count DESC, total_links DESC
			LIMIT %d OFFSET %d";
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static aggregate; LIMIT/OFFSET via prepare().
		$items = $wpdb->get_results( $wpdb->prepare( $items_sql, $per_page, $offset ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

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
	 * @param int $limit    Batch size.
	 * @param int $post_id  When > 0, only links from this post.
	 */
	public function get_links_batch_for_check( $limit = 5, $post_id = 0 ) {
		global $wpdb;
		$limit   = max( 1, absint( $limit ) );
		$post_id = absint( $post_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $post_id > 0 ) {
			return $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$this->table} WHERE last_checked IS NULL AND post_id = %d ORDER BY id ASC LIMIT %d",
					$post_id,
					$limit
				)
			);
		}
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
	 * Get links for the hourly cron: unchecked first, then stale broken, then stale OK.
	 *
	 * @param int $limit             Max rows.
	 * @param int $ok_stale_days     Minimum days since last check for OK / non-broken links.
	 * @param int $broken_stale_days Minimum days since last check for broken links (priority queue).
	 * @return object[]
	 */
	public function get_links_for_cron_check( $limit = 10, $ok_stale_days = 30, $broken_stale_days = 7 ) {
		global $wpdb;
		$limit           = max( 1, absint( $limit ) );
		$ok_stale_days   = max( 1, absint( $ok_stale_days ) );
		$broken_stale_days = max( 1, absint( $broken_stale_days ) );
		$ok_threshold    = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $ok_stale_days . ' days' ) );
		$broken_threshold = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $broken_stale_days . ' days' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from prefix; date thresholds via placeholders.
				"SELECT * FROM {$this->table} WHERE last_checked IS NULL
					OR ( is_broken = 1 AND user_verified = 0 AND last_checked < %s )
					OR ( ( is_broken = 0 OR user_verified = 1 ) AND last_checked IS NOT NULL AND last_checked < %s )
				ORDER BY
					CASE
						WHEN last_checked IS NULL THEN 0
						WHEN is_broken = 1 AND user_verified = 0 THEN 1
						ELSE 2
					END ASC,
					last_checked ASC,
					id ASC
				LIMIT %d",
				$broken_threshold,
				$ok_threshold,
				$limit
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Count links eligible for the cron queue (same rules as get_links_for_cron_check).
	 *
	 * @param int $ok_stale_days     OK / general recheck interval.
	 * @param int $broken_stale_days Broken-link recheck interval.
	 * @return array{ unchecked: int, broken_stale: int, ok_stale: int, total: int }
	 */
	public function get_cron_queue_counts( $ok_stale_days = 30, $broken_stale_days = 7 ) {
		global $wpdb;
		$ok_stale_days     = max( 1, absint( $ok_stale_days ) );
		$broken_stale_days = max( 1, absint( $broken_stale_days ) );
		$ok_threshold      = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $ok_stale_days . ' days' ) );
		$broken_threshold  = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $broken_stale_days . ' days' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$unchecked = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$this->table} WHERE last_checked IS NULL"
		);
		$broken_stale = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$this->table} WHERE is_broken = 1 AND user_verified = 0 AND last_checked IS NOT NULL AND last_checked < %s",
				$broken_threshold
			)
		);
		$ok_stale = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$this->table} WHERE ( is_broken = 0 OR user_verified = 1 ) AND last_checked IS NOT NULL AND last_checked < %s",
				$ok_threshold
			)
		);
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT COUNT(*) FROM {$this->table} WHERE last_checked IS NULL
					OR ( is_broken = 1 AND user_verified = 0 AND last_checked < %s )
					OR ( ( is_broken = 0 OR user_verified = 1 ) AND last_checked IS NOT NULL AND last_checked < %s )",
				$broken_threshold,
				$ok_threshold
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return array(
			'unchecked'     => $unchecked,
			'broken_stale'  => $broken_stale,
			'ok_stale'      => $ok_stale,
			'total'         => $total,
		);
	}

	/** @return int Rows with last_checked IS NULL (includes manually verified). */
	public function get_pending_check_count( $post_id = 0 ) {
		global $wpdb;
		$post_id = absint( $post_id );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( $post_id > 0 ) {
			return (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$this->table} WHERE last_checked IS NULL AND post_id = %d",
					$post_id
				)
			);
		}
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE last_checked IS NULL" );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/** @return int */
	public function get_unchecked_count() {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$this->table} WHERE last_checked IS NULL AND user_verified = 0" );
	}

	/**
	 * Whether a stored link still uses plain HTTP in the post (HTTP insecure tab takes priority over Redirect).
	 *
	 * @param string   $link_url      Stored link URL.
	 * @param int|bool $is_broken     Broken flag.
	 * @param int|bool $user_verified Manual lock flag.
	 * @return bool
	 */
	public static function row_is_http_insecure( $link_url, $is_broken = 0, $user_verified = 0 ) {
		return 0 === (int) $user_verified
			&& 0 === (int) $is_broken
			&& 0 === strpos( (string) $link_url, 'http://' );
	}

	/**
	 * SQL predicate for redirect list/stats (excludes active HTTP insecure rows).
	 *
	 * @param string $col_prefix Optional column prefix, e.g. `l.`.
	 * @return string
	 */
	public static function sql_redirect_match( $col_prefix = '' ) {
		$prefix = '';
		if ( '' !== (string) $col_prefix && preg_match( '/^[a-z_]+\.$/', (string) $col_prefix ) ) {
			$prefix = (string) $col_prefix;
		}

		return "( {$prefix}status_code IN (301,302,303,307,308) OR ( {$prefix}redirect_url IS NOT NULL AND {$prefix}redirect_url != '' ) ) AND {$prefix}user_verified = 0 AND NOT ( {$prefix}link_url LIKE 'http://%' AND {$prefix}is_broken = 0 )";
	}

	/**
	 * Whether a link row should appear under the Redirect tab.
	 *
	 * @param object|array $link Link row.
	 * @return bool
	 */
	public function row_counts_as_redirect_tab( $link ) {
		$link          = (object) $link;
		$status_code   = isset( $link->status_code ) ? (int) $link->status_code : 0;
		$is_broken     = isset( $link->is_broken ) ? (int) $link->is_broken : 0;
		$user_verified = isset( $link->user_verified ) ? (int) $link->user_verified : 0;
		$link_url      = isset( $link->link_url ) ? (string) $link->link_url : '';
		$redirect_url  = isset( $link->redirect_url ) ? (string) $link->redirect_url : '';

		if ( 0 !== $user_verified || self::row_is_http_insecure( $link_url, $is_broken, $user_verified ) ) {
			return false;
		}

		$has_redirect = in_array( $status_code, array( 301, 302, 303, 307, 308 ), true )
			|| '' !== $redirect_url;
		if ( ! $has_redirect ) {
			return false;
		}

		if ( '' !== $redirect_url && '' !== $link_url ) {
			static $redirect_http = null;
			if ( null === $redirect_http ) {
				$redirect_http = new TSOLIIN_HTTP();
			}
			if ( $redirect_http->is_transparent_redirect( $link_url, $redirect_url ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a link row belongs to a list-table filter tab.
	 *
	 * Mirrors the SQL conditions in get_links() / get_stats().
	 *
	 * @param object|array $link   Link row.
	 * @param string       $filter Filter key.
	 * @return bool
	 */
	public function link_matches_filter( $link, $filter ) {
		$filter = sanitize_key( (string) $filter );
		if ( 'all' === $filter || '' === $filter ) {
			return true;
		}

		$link          = (object) $link;
		$status_code   = isset( $link->status_code ) ? (int) $link->status_code : 0;
		$is_broken     = isset( $link->is_broken ) ? (int) $link->is_broken : 0;
		$user_verified = isset( $link->user_verified ) ? (int) $link->user_verified : 0;
		$link_url      = isset( $link->link_url ) ? (string) $link->link_url : '';
		$redirect_url  = isset( $link->redirect_url ) ? (string) $link->redirect_url : '';
		$last_checked  = isset( $link->last_checked ) ? $link->last_checked : null;

		switch ( $filter ) {
			case 'broken':
				return 1 === $is_broken && 0 === $user_verified;
			case 'redirect':
				return $this->row_counts_as_redirect_tab( $link );
			case 'ok':
				return 200 === $status_code
					&& 0 === $user_verified
					&& 0 !== strpos( $link_url, 'http://' );
			case 'unchecked':
				return null === $last_checked && 0 === $user_verified;
			case 'http_insecure':
				return 0 === strpos( $link_url, 'http://' )
					&& 0 === $is_broken
					&& 0 === $user_verified;
			case 'manual_locked':
				return 1 === $user_verified;
		}

		return true;
	}

	/**
	 * Whether a link row matches an optional quality filter tab.
	 *
	 * @param object|array $link    Link row.
	 * @param string       $quality Quality filter key.
	 * @return bool
	 */
	public function link_matches_quality_filter( $link, $quality ) {
		$quality = sanitize_key( (string) $quality );
		if ( '' === $quality || 'all' === $quality ) {
			return true;
		}

		$link          = (object) $link;
		$user_verified = isset( $link->user_verified ) ? (int) $link->user_verified : 0;
		$link_url      = isset( $link->link_url ) ? (string) $link->link_url : '';
		$anchor_text   = isset( $link->anchor_text ) ? (string) $link->anchor_text : '';

		switch ( $quality ) {
			case 'empty_anchor':
				return 0 === $user_verified && TSOLIIN_Quality::is_empty_anchor( $anchor_text, $link_url );
			case 'generic_anchor':
				return 0 === $user_verified && TSOLIIN_Quality::is_generic_anchor( $anchor_text );
			case 'unpublished_target':
				return TSOLIIN_Quality::points_to_unpublished( $link );
		}

		return true;
	}

	/**
	 * Clear cached aggregate stats and broken URL lists (call after writes that change counts).
	 */
	public static function clear_stats_cache() {
		self::$stats_cache                 = array();
		self::$broken_urls_by_post_cache = array();
		delete_transient( 'tsoliin_unpub_cnt_all' );
		delete_transient( 'tsoliin_unpub_cnt_v2_all' );
		delete_transient( 'tsoliin_unpub_cnt_v3_all' );
	}

	/**
	 * Broken link_url values for a post (for frontend rel="nofollow"; cached per request).
	 *
	 * @param int $post_id Post ID.
	 * @return string[]
	 */
	public function get_broken_link_urls_for_post( $post_id ) {
		$post_id = absint( $post_id );
		if ( ! $post_id ) {
			return array();
		}
		if ( array_key_exists( $post_id, self::$broken_urls_by_post_cache ) ) {
			return self::$broken_urls_by_post_cache[ $post_id ];
		}
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$urls = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT link_url FROM {$this->table} WHERE post_id = %d AND is_broken = 1",
				$post_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		self::$broken_urls_by_post_cache[ $post_id ] = is_array( $urls ) ? array_map( 'strval', $urls ) : array();
		return self::$broken_urls_by_post_cache[ $post_id ];
	}

	/** @return array */
	public function get_stats() {
		$this->maybe_cleanup_transparent_redirects();

		if ( isset( self::$stats_cache['all'] ) ) {
			return self::$stats_cache['all'];
		}

		global $wpdb;
		$generic = TSOLIIN_Quality::build_generic_anchor_count_expr();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static SQL fragments; values passed via $wpdb->prepare().
		$sql    = "SELECT COUNT(*) AS total, SUM(CASE WHEN is_broken=1 AND user_verified=0 THEN 1 ELSE 0 END) AS broken, SUM(CASE WHEN " . self::sql_redirect_match() . " THEN 1 ELSE 0 END) AS redirect, SUM(CASE WHEN status_code=200 AND link_url NOT LIKE %s AND user_verified=0 THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN last_checked IS NULL AND user_verified=0 THEN 1 ELSE 0 END) AS unchecked, SUM(CASE WHEN link_url LIKE %s AND is_broken=0 AND user_verified=0 THEN 1 ELSE 0 END) AS http_insecure, SUM(CASE WHEN user_verified=1 THEN 1 ELSE 0 END) AS manual_locked, " . TSOLIIN_Quality::build_empty_anchor_count_expr() . " AS empty_anchor, {$generic['expr']} AS generic_anchor FROM {$this->table}";
		$params = array_merge( array( 'http://%', 'http://%' ), $generic['params'] );
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql,
				...$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$defaults = array(
			'total'               => 0,
			'broken'              => 0,
			'redirect'            => 0,
			'ok'                  => 0,
			'unchecked'           => 0,
			'http_insecure'       => 0,
			'manual_locked'       => 0,
			'empty_anchor'        => 0,
			'generic_anchor'      => 0,
			'unpublished_target'  => 0,
		);
		$stats = $row ? array_map( 'absint', $row ) : $defaults;
		if ( is_admin() || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			$stats['unpublished_target'] = $this->count_unpublished_targets( 0 );
		}
		self::$stats_cache['all'] = $stats;
		return $stats;
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
		if ( ! $this->table_exists() ) {
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
	 * Count links that need checking (alias for cron queue total).
	 *
	 * @param int $stale_days OK-link recheck interval (legacy param name).
	 * @return int
	 */
	public function count_stale_links( $stale_days = 7 ) {
		$settings = class_exists( 'TSOLIIN_Schedule' ) ? TSOLIIN_Schedule::get_settings() : array();
		$ok_days  = isset( $settings['recheck_days'] ) ? (int) $settings['recheck_days'] : absint( $stale_days );
		$broken   = isset( $settings['broken_recheck_days'] ) ? (int) $settings['broken_recheck_days'] : 7;
		return (int) $this->get_cron_queue_counts( $ok_days, $broken )['total'];
	}

	public function cleanup_misclassified_plain_image_rows() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"UPDATE {$this->table}
			SET link_type = 'image'
			WHERE link_type = 'plain'
			  AND link_url REGEXP '\\.(jpe?g|png|gif|webp|avif|svg|bmp|ico)([?#]|$)'
			  AND link_url REGEXP '/(wp-content/)?uploads/'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectDatabaseQuery.NoCaching
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
		$post_id = absint( $post_id );
		$this->maybe_cleanup_transparent_redirects();

		$cache_key = 'post_' . $post_id;
		if ( isset( self::$stats_cache[ $cache_key ] ) ) {
			return self::$stats_cache[ $cache_key ];
		}

		global $wpdb;
		$generic = TSOLIIN_Quality::build_generic_anchor_count_expr();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter -- Static SQL fragments; values passed via $wpdb->prepare().
		$sql    = "SELECT COUNT(*) AS total, SUM(CASE WHEN is_broken=1 AND user_verified=0 THEN 1 ELSE 0 END) AS broken, SUM(CASE WHEN " . self::sql_redirect_match() . " THEN 1 ELSE 0 END) AS redirect, SUM(CASE WHEN status_code=200 AND link_url NOT LIKE %s AND user_verified=0 THEN 1 ELSE 0 END) AS ok, SUM(CASE WHEN last_checked IS NULL AND user_verified=0 THEN 1 ELSE 0 END) AS unchecked, SUM(CASE WHEN link_url LIKE %s AND is_broken=0 AND user_verified=0 THEN 1 ELSE 0 END) AS http_insecure, SUM(CASE WHEN user_verified=1 THEN 1 ELSE 0 END) AS manual_locked, " . TSOLIIN_Quality::build_empty_anchor_count_expr() . " AS empty_anchor, {$generic['expr']} AS generic_anchor FROM {$this->table} WHERE post_id = %d";
		$params = array_merge( array( 'http://%', 'http://%' ), $generic['params'], array( $post_id ) );
		$row    = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$sql,
				...$params
			),
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$defaults = array(
			'total'               => 0,
			'broken'              => 0,
			'redirect'            => 0,
			'ok'                  => 0,
			'unchecked'           => 0,
			'http_insecure'       => 0,
			'manual_locked'       => 0,
			'empty_anchor'        => 0,
			'generic_anchor'      => 0,
			'unpublished_target'  => 0,
		);
		$stats = $row ? array_map( 'absint', $row ) : $defaults;
		$stats['unpublished_target'] = $this->count_unpublished_targets( $post_id );
		self::$stats_cache[ $cache_key ] = $stats;
		return $stats;
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
	 * Throttled pass: normalize transparent / false-positive redirect rows in the DB.
	 *
	 * @return int Rows updated (0 when skipped via transient).
	 */
	public function maybe_cleanup_transparent_redirects() {
		if ( get_transient( 'tsoliin_transparent_rd_cleanup' ) ) {
			return 0;
		}
		$updated = $this->cleanup_transparent_redirects();
		set_transient( 'tsoliin_transparent_rd_cleanup', 1, 30 );
		return $updated;
	}

	/**
	 * Fix legacy rows stored as -1 (ignore list) when the URL is not on the ignore list.
	 *
	 * @param bool $run_http When true, re-check safe URLs (cron/upgrade only).
	 * @param int  $limit    Max HTTP re-checks per call.
	 * @return int Rows updated.
	 */
	public function cleanup_mislabeled_skip_rows( $run_http = false, $limit = 20 ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, link_url, post_id FROM {$this->table} WHERE status_code = -1"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return 0;
		}

		$http    = new TSOLIIN_HTTP();
		$updated = 0;
		$checked = 0;
		$limit   = max( 1, absint( $limit ) );

		foreach ( $rows as $row ) {
			$url = (string) $row->link_url;
			if ( TSOLIIN_HTTP::is_ignored_url( $url ) ) {
				continue;
			}

			if ( ! TSOLIIN_HTTP::is_safe_remote_url( $url ) ) {
				// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$changed = $wpdb->update(
					$this->table,
					array(
						'status_code'  => -7,
						'redirect_url' => '',
						'is_broken'    => 0,
					),
					array( 'id' => (int) $row->id ),
					array( '%d', '%s', '%d' ),
					array( '%d' )
				);
				// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				if ( false !== $changed ) {
					$updated++;
				}
				continue;
			}

			if ( ! $run_http || $checked >= $limit ) {
				continue;
			}

			$r = $http->check( $url, (int) $row->post_id );
			$this->update_check_result( (int) $row->id, $r['status_code'], $r['redirect_url'], $r['is_broken'], isset( $r['redirect_chain'] ) ? $r['redirect_chain'] : '' );
			$updated++;
			$checked++;
		}

		if ( $updated > 0 ) {
			self::clear_stats_cache();
		}

		return $updated;
	}

	/**
	 * Normalize rows whose redirect is transparent (YouTube youtu.be→watch, trailing slash, CDN, etc.).
	 *
	 * @return int Rows updated.
	 */
	public function cleanup_transparent_redirects() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, link_url, redirect_url FROM {$this->table} WHERE redirect_url != ''"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return 0;
		}

		$http    = new TSOLIIN_HTTP();
		$updated = 0;
		foreach ( $rows as $row ) {
			$orig  = (string) $row->link_url;
			$redir = (string) $row->redirect_url;
			if ( '' === $orig || '' === $redir || ! $http->is_transparent_redirect( $orig, $redir ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$changed = $wpdb->update(
				$this->table,
				array(
					'status_code'  => 200,
					'redirect_url' => '',
					'is_broken'    => 0,
				),
				array( 'id' => (int) $row->id ),
				array( '%d', '%s', '%d' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false !== $changed ) {
				$updated++;
			}
		}

		if ( $updated > 0 ) {
			self::clear_stats_cache();
		}

		return $updated;
	}

	/**
	 * Reclassify logout/action URLs that were previously checked as 403 bot-blocks.
	 *
	 * @return int Rows updated.
	 */
	public function cleanup_action_url_rows() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT id, link_url FROM {$this->table}
			WHERE link_url LIKE '%wp-login.php%' OR link_url LIKE '%action=logout%'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $rows ) ) {
			return 0;
		}

		$updated = 0;
		foreach ( $rows as $row ) {
			if ( ! TSOLIIN_HTTP::is_action_url( (string) $row->link_url ) ) {
				continue;
			}
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$changed = $wpdb->update(
				$this->table,
				array(
					'status_code'  => -6,
					'redirect_url' => '',
					'is_broken'    => 0,
				),
				array( 'id' => (int) $row->id ),
				array( '%d', '%s', '%d' ),
				array( '%d' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false !== $changed ) {
				$updated++;
			}
		}

		if ( $updated > 0 ) {
			self::clear_stats_cache();
		}

		return $updated;
	}

	/**
	 * Count hard-broken links (broken and without redirect destination).
	 *
	 * @return int
	 */
	public function count_hard_broken_links() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$this->table} WHERE is_broken = 1 AND (redirect_url = '' OR redirect_url IS NULL) AND status_code NOT BETWEEN 300 AND 399"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
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
