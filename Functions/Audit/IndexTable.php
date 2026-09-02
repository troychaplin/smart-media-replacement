<?php
/**
 * Media Audit Index Tables
 *
 * Schema and every query for the media audit index and its denormalized
 * summary projection. Both tables are per-site (`$wpdb->prefix`) because the
 * index is a derived cache of a single site's content, rebuilt on demand.
 *
 * @package Smart_Media_Replacement
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

namespace Smart_Media_Replacement\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IndexTable
 *
 * Owns both audit tables and every query against them.
 */
class IndexTable {

	const TABLE_NAME = 'smr_audit_index';

	/** Denormalized one-row-per-attachment projection used by the read path. */
	const SUMMARY_TABLE_NAME = 'smr_audit_summary';

	/**
	 * Attachment meta flagging a file as queued for deletion.
	 *
	 * Meta is the source of truth rather than the summary column: the summary
	 * projection is deleted and rebuilt from wp_posts on every scan, so a flag
	 * living only there would not survive one. The summary column is a
	 * projection of this key, joined in exactly like _smr_audit_filesize.
	 *
	 * @var string
	 */
	const MARKED_META_KEY = '_smr_marked_for_deletion';

	/**
	 * When true, writes skip incremental summary refresh. The batch scanner sets
	 * this so a full scan does one set-based rebuild at the end instead of
	 * refreshing the summary on every per-post write.
	 *
	 * @var bool
	 */
	public static bool $defer_summary = false;

	/**
	 * Object-cache group for list-query results.
	 *
	 * Deliberately NOT registered via wp_cache_add_global_groups() — cache
	 * groups are blog-prefixed by default, which is exactly what the per-site
	 * index needs. Globalizing this would leak one site's results into another.
	 */
	const CACHE_GROUP = 'smr_audit';

	/**
	 * Schema version for the audit tables.
	 *
	 * Bump when the CREATE TABLE statements below change so existing sites
	 * re-run dbDelta on the next request.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.1.0';

	/**
	 * Option holding the installed audit schema version.
	 *
	 * Autoloaded, so the steady-state check in ensure_tables() is an array
	 * lookup in the already-loaded options cache rather than a query.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'smr_audit_db_version';

	/**
	 * Current cache-busting marker for the group.
	 *
	 * Mirrors core's wp_cache_get_last_changed() pattern: query results are
	 * keyed by this value, and any write bumps it — invalidating every cached
	 * result at once without tracking individual keys. Benefits sites with a
	 * persistent object cache (Redis/Memcached); a no-op-but-harmless on sites
	 * without one.
	 */
	private static function last_changed(): string {
		$last_changed = wp_cache_get( 'last_changed', self::CACHE_GROUP );
		if ( false === $last_changed ) {
			$last_changed = (string) microtime( true );
			wp_cache_set( 'last_changed', $last_changed, self::CACHE_GROUP );
		}
		return (string) $last_changed;
	}

	/** Invalidate every cached list query. Called on any write to the index. */
	public static function flush_cache(): void {
		wp_cache_set( 'last_changed', (string) microtime( true ), self::CACHE_GROUP );
	}

	/**
	 * Fully-qualified name of the index table for the current site.
	 *
	 * @return string
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_NAME;
	}

	/**
	 * Fully-qualified name of the summary table for the current site.
	 *
	 * @return string
	 */
	public static function summary_table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::SUMMARY_TABLE_NAME;
	}

	/**
	 * Create the audit tables for the current site if they are missing.
	 *
	 * The plugin is network-activated once on multisite, so activation alone
	 * cannot provision every site — new sites are handled on wp_initialize_site
	 * and everything else falls back to this guard on first use.
	 *
	 * Costs nothing in steady state: the sentinel option is autoloaded, so the
	 * check below reads from the already-loaded options cache, and the static
	 * caps it at one evaluation per request.
	 *
	 * @param bool $force Skip the version sentinel and always run dbDelta.
	 *                    Used by the health-check cron so a manually dropped
	 *                    table self-heals even when the sentinel looks current.
	 */
	public static function ensure_tables( bool $force = false ): void {
		// Keyed by blog ID, not a plain flag: a single request can switch blogs
		// (the WP-CLI --network loops, activation provisioning), and a global
		// flag would make every site after the first a no-op.
		static $checked = array();

		$blog_id = get_current_blog_id();

		if ( isset( $checked[ $blog_id ] ) && ! $force ) {
			return;
		}
		$checked[ $blog_id ] = true;

		if ( ! $force && self::DB_VERSION === get_option( self::DB_VERSION_OPTION ) ) {
			return;
		}

		self::create();
		update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
	}

	/**
	 * Create the index and summary tables via dbDelta.
	 */
	public static function create(): void {
		global $wpdb;
		$table           = self::table_name();
		$summary         = self::summary_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE IF NOT EXISTS {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			attachment_id bigint(20) unsigned NOT NULL,
			source_post_id bigint(20) unsigned NOT NULL,
			reference_type varchar(32) NOT NULL DEFAULT 'classic',
			missing_alt tinyint(1) NOT NULL DEFAULT 0,
			last_scanned datetime NOT NULL,
			PRIMARY KEY (id),
			KEY attachment_id (attachment_id),
			KEY source_post_id (source_post_id),
			KEY att_type (attachment_id, reference_type)
		) {$charset_collate};";

		// Denormalized projection: one indexed row per attachment so the list
		// query is a flat scan — no GROUP BY, no CAST, no postmeta join.
		$summary_sql = "CREATE TABLE IF NOT EXISTS {$summary} (
			attachment_id bigint(20) unsigned NOT NULL,
			mime_type varchar(100) NOT NULL DEFAULT '',
			media_type varchar(16) NOT NULL DEFAULT 'Document',
			post_title text NOT NULL,
			post_date datetime NOT NULL,
			file_size bigint(20) unsigned NOT NULL DEFAULT 0,
			alt_text text NOT NULL,
			usage_count int unsigned NOT NULL DEFAULT 0,
			missing_alt tinyint(1) NOT NULL DEFAULT 0,
			has_block tinyint(1) NOT NULL DEFAULT 0,
			has_featured_image tinyint(1) NOT NULL DEFAULT 0,
			has_classic tinyint(1) NOT NULL DEFAULT 0,
			has_postmeta tinyint(1) NOT NULL DEFAULT 0,
			marked_for_deletion tinyint(1) NOT NULL DEFAULT 0,
			PRIMARY KEY (attachment_id),
			KEY media_type (media_type),
			KEY usage_count (usage_count),
			KEY file_size (file_size),
			KEY post_date (post_date),
			KEY post_title (post_title(191)),
			KEY mt_date (media_type, post_date),
			KEY marked_for_deletion (marked_for_deletion)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
		dbDelta( $summary_sql );
	}

	/**
	 * Drop both audit tables for the current site.
	 */
	public static function drop(): void {
		global $wpdb;
		$table   = self::table_name();
		$summary = self::summary_table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
		$wpdb->query( "DROP TABLE IF EXISTS {$summary}" );
		// phpcs:enable
	}

	/**
	 * Clear index rows. Does NOT clear the summary projection — a fresh scan
	 * leaves the previous summary in place so the list keeps showing the last
	 * completed results until the new scan rebuilds it. Use truncate_summary()
	 * for an explicit "clear everything".
	 */
	public static function truncate(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DELETE FROM {$table}" );
		self::flush_cache();
	}

	/** Empty the summary projection (used by the explicit "Clear index" action). */
	public static function truncate_summary(): void {
		global $wpdb;
		$summary = self::summary_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DELETE FROM {$summary}" );
		self::flush_cache();
	}

	/**
	 * Remove all index rows for a given source post, then insert fresh ones.
	 *
	 * @param int   $source_post_id Post the references originate from.
	 * @param array $rows           Each: [ attachment_id => int, reference_type => string, missing_alt => int ].
	 */
	public static function replace_for_post( int $source_post_id, array $rows ): void {
		global $wpdb;
		$table = self::table_name();
		$now   = current_time( 'mysql' );

		// Attachments whose aggregates may change: the ones previously linked to
		// this post plus the ones now being written. Captured before the delete.
		$affected = self::attachment_ids_for_post( $source_post_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, array( 'source_post_id' => $source_post_id ), array( '%d' ) );

		if ( $rows ) {
			// Single multi-row INSERT instead of one query per row — far fewer
			// round-trips during a full scan.
			$placeholders = array();
			$values       = array();
			foreach ( $rows as $row ) {
				$attachment_id  = (int) $row['attachment_id'];
				$affected[]     = $attachment_id;
				$placeholders[] = '(%d, %d, %s, %d, %s)';
				$values[]       = $attachment_id;
				$values[]       = $source_post_id;
				$values[]       = sanitize_key( $row['reference_type'] );
				$values[]       = isset( $row['missing_alt'] ) ? (int) $row['missing_alt'] : 0;
				$values[]       = $now;
			}

			$sql = "INSERT INTO {$table}
				(attachment_id, source_post_id, reference_type, missing_alt, last_scanned)
				VALUES " . implode( ', ', $placeholders );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( $sql, ...$values ) );
			// phpcs:enable
		}

		if ( ! self::$defer_summary ) {
			self::refresh_summary_for_attachments( array_unique( $affected ) );
		}

		self::flush_cache();
	}

	/**
	 * Distinct attachment IDs currently linked to a source post.
	 *
	 * @param int $source_post_id Source post ID.
	 * @return int[]
	 */
	private static function attachment_ids_for_post( int $source_post_id ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT attachment_id FROM {$table} WHERE source_post_id = %d",
				$source_post_id
			)
		);
		// phpcs:enable
		return array_map( 'intval', $ids );
	}

	/**
	 * SQL CASE expression mapping a post_mime_type column to a media_type label.
	 *
	 * @param string $mime_col Column reference holding the MIME type.
	 * @return string
	 */
	private static function media_type_case_sql( string $mime_col ): string {
		return "CASE
			WHEN {$mime_col} LIKE 'image/%' THEN 'Image'
			WHEN {$mime_col} LIKE 'video/%' THEN 'Video'
			WHEN {$mime_col} LIKE 'audio/%' THEN 'Audio'
			ELSE 'Document'
		END";
	}

	/**
	 * Insert summary rows for a set of attachments via INSERT ... SELECT.
	 *
	 * Aggregates come from correlated subqueries (not a derived table) to stay
	 * compatible with the SQLite integration. Caller is responsible for deleting
	 * any existing rows for the same scope first.
	 *
	 * The SELECT contains literal LIKE patterns ('image/%'), so it is NOT run
	 * through wpdb::prepare (which mishandles bare %). The only dynamic input is
	 * the scope fragment, which callers build from already-sanitized integers.
	 *
	 * @param string $scope_where Extra WHERE fragment (e.g. 'AND p.ID IN (1,2)'); may be empty.
	 */
	private static function insert_summary_rows( string $scope_where = '' ): void {
		global $wpdb;
		$summary     = self::summary_table_name();
		$index       = self::table_name();
		$posts_table = $wpdb->posts;
		$postmeta    = $wpdb->postmeta;
		$media_case  = self::media_type_case_sql( 'p.post_mime_type' );

		$marked_key = self::MARKED_META_KEY;

		$sql = "INSERT INTO {$summary}
			(attachment_id, mime_type, media_type, post_title, post_date, file_size, alt_text,
			 usage_count, missing_alt, has_block, has_featured_image, has_classic, has_postmeta,
			 marked_for_deletion)
			SELECT
				p.ID,
				p.post_mime_type,
				{$media_case},
				p.post_title,
				p.post_date,
				CAST(COALESCE(pm_size.meta_value, 0) AS UNSIGNED),
				COALESCE(pm_alt.meta_value, ''),
				(SELECT COUNT(*) FROM {$index} idx WHERE idx.attachment_id = p.ID),
				(SELECT COALESCE(MAX(idx.missing_alt), 0) FROM {$index} idx WHERE idx.attachment_id = p.ID),
				(SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM {$index} idx WHERE idx.attachment_id = p.ID AND idx.reference_type = 'block'),
				(SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM {$index} idx WHERE idx.attachment_id = p.ID AND idx.reference_type = 'featured_image'),
				(SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM {$index} idx WHERE idx.attachment_id = p.ID AND idx.reference_type = 'classic'),
				(SELECT CASE WHEN COUNT(*) > 0 THEN 1 ELSE 0 END FROM {$index} idx WHERE idx.attachment_id = p.ID AND idx.reference_type = 'postmeta'),
				CASE WHEN pm_marked.meta_value IS NULL THEN 0 ELSE 1 END
			FROM {$posts_table} p
			LEFT JOIN {$postmeta} pm_size ON pm_size.post_id = p.ID AND pm_size.meta_key = '_smr_audit_filesize'
			LEFT JOIN {$postmeta} pm_alt ON pm_alt.post_id = p.ID AND pm_alt.meta_key = '_wp_attachment_image_alt'
			LEFT JOIN {$postmeta} pm_marked ON pm_marked.post_id = p.ID AND pm_marked.meta_key = '{$marked_key}'
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
			{$scope_where}";

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( $sql );
		// phpcs:enable
	}

	/** Rebuild the entire summary projection from the index. Run at scan completion. */
	public static function rebuild_summary(): void {
		global $wpdb;
		$summary = self::summary_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DELETE FROM {$summary}" );
		self::insert_summary_rows();
		self::flush_cache();
	}

	/**
	 * Recompute summary rows for specific attachments (incremental sync).
	 * Deleted/non-attachment IDs simply drop out (the SELECT won't match them).
	 *
	 * @param int[] $ids Attachment IDs to refresh.
	 */
	public static function refresh_summary_for_attachments( array $ids ): void {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return;
		}
		$summary = self::summary_table_name();
		// Safe to inline: every element is an intval'd integer.
		$in = implode( ', ', $ids );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query( "DELETE FROM {$summary} WHERE attachment_id IN ({$in})" );
		// phpcs:enable

		self::insert_summary_rows( "AND p.ID IN ({$in})" );
		self::flush_cache();
	}

	/**
	 * Patch a single attachment's cached file size in the summary projection.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $file_size     Size in bytes.
	 *
	 * Lets the REST read path self-heal a row whose file_size was 0 (e.g. an
	 * attachment uploaded after the last scan, or one whose size meta wasn't
	 * cached yet) without a full refresh. No-op if the row doesn't exist.
	 */
	public static function update_summary_file_size( int $attachment_id, int $file_size ): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::summary_table_name(),
			array( 'file_size' => $file_size ),
			array( 'attachment_id' => $attachment_id ),
			array( '%d' ),
			array( '%d' )
		);
		self::flush_cache();
	}

	/**
	 * Run a COUNT query, calling prepare() only when args are present.
	 *
	 * @param string $sql  COUNT query, optionally containing placeholders.
	 * @param array  $args Placeholder replacements; empty to skip prepare().
	 * @return int
	 */
	private static function count_query( string $sql, array $args ) {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		if ( $args ) {
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, ...$args ) );
		}
		return (int) $wpdb->get_var( $sql );
		// phpcs:enable
	}

	/** Maximum number of source posts returned for a single "Used In" popover. */
	const LOCATIONS_LIMIT = 50;

	/**
	 * Return source posts for a given attachment ID.
	 * Excludes trashed and auto-draft sources so the locations list reflects
	 * live content only.
	 *
	 * Bounded to LOCATIONS_LIMIT rows so an attachment referenced by thousands
	 * of posts cannot produce an unbounded query/payload. Fetches one extra row
	 * to detect whether more exist without a second COUNT query.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return array{ rows: array, has_more: bool }
	 */
	public static function get_locations( int $attachment_id ): array {
		global $wpdb;
		$table       = self::table_name();
		$posts_table = $wpdb->posts;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_title, p.post_type, idx.reference_type
				FROM %i idx
				INNER JOIN %i p ON p.ID = idx.source_post_id
				WHERE idx.attachment_id = %d
				AND p.post_status NOT IN ('trash', 'auto-draft')
				ORDER BY p.post_title ASC
				LIMIT %d",
				$table,
				$posts_table,
				$attachment_id,
				self::LOCATIONS_LIMIT + 1
			)
		);

		// get_results() returns null on error, so normalize before counting.
		$rows     = is_array( $rows ) ? $rows : array();
		$has_more = count( $rows ) > self::LOCATIONS_LIMIT;
		if ( $has_more ) {
			array_pop( $rows );
		}

		return array(
			'rows'     => $rows,
			'has_more' => $has_more,
		);
	}

	/**
	 * Build the shared WHERE fragment used by every filtered summary query.
	 *
	 * Extracted so the list query, the match count and the set-based "mark all
	 * matching" update cannot drift apart: a filter that selects a row in the
	 * list must select exactly the same row when marking.
	 *
	 * @param string $search         Filename substring to match.
	 * @param string $media_type     One of Image|Video|Audio|Document, or empty.
	 * @param string $reference_type One of block|featured_image|classic|postmeta, or empty.
	 * @param string $usage_filter   One of used|unused, or empty for all.
	 * @param bool   $missing_alt    Restrict to references embedded without alt text.
	 * @param string $marked         One of marked|unmarked, or empty for all.
	 * @return array{ 0: string, 1: array } WHERE fragment (may be empty) and its placeholder args.
	 */
	private static function build_filter_where(
		string $search = '',
		string $media_type = '',
		string $reference_type = '',
		string $usage_filter = '',
		bool $missing_alt = false,
		string $marked = ''
	): array {
		global $wpdb;

		$where_parts = array();
		$args        = array();

		if ( $search ) {
			// Leading-wildcard LIKE cannot use the post_title index, so search is
			// a table scan over the summary rows. Acceptable at this scale (one
			// row per attachment); revisit with FULLTEXT/prefix search if the
			// media library grows into the hundreds of thousands.
			$where_parts[] = 's.post_title LIKE %s';
			$args[]        = '%' . $wpdb->esc_like( $search ) . '%';
		}

		// media_type is a stored, indexed label — exact match, no LIKE scan.
		if ( in_array( $media_type, array( 'Image', 'Video', 'Audio', 'Document' ), true ) ) {
			$where_parts[] = 's.media_type = %s';
			$args[]        = $media_type;
		}

		// reference_type maps to a boolean column; takes precedence over the
		// used/unused toggle (a reference_type match is inherently "used").
		$ref_col_map = array(
			'block'          => 'has_block',
			'featured_image' => 'has_featured_image',
			'classic'        => 'has_classic',
			'postmeta'       => 'has_postmeta',
		);
		if ( isset( $ref_col_map[ $reference_type ] ) ) {
			$where_parts[] = 's.' . $ref_col_map[ $reference_type ] . ' = 1';
		} elseif ( 'unused' === $usage_filter ) {
			$where_parts[] = 's.usage_count = 0';
		} elseif ( 'used' === $usage_filter ) {
			$where_parts[] = 's.usage_count > 0';
		}

		if ( $missing_alt ) {
			$where_parts[] = 's.missing_alt = 1';
		}

		if ( 'marked' === $marked ) {
			$where_parts[] = 's.marked_for_deletion = 1';
		} elseif ( 'unmarked' === $marked ) {
			$where_parts[] = 's.marked_for_deletion = 0';
		}

		return array(
			$where_parts ? 'WHERE ' . implode( ' AND ', $where_parts ) : '',
			$args,
		);
	}

	/**
	 * Return paginated attachments for the REST endpoint.
	 *
	 * Reads the denormalized summary table: a flat indexed scan with no GROUP BY,
	 * no CAST, and no postmeta join. media_type is an exact indexed match,
	 * reference_type maps to boolean columns, and used/unused toggles on
	 * usage_count.
	 *
	 * @param string $search         Filename substring to match.
	 * @param int    $per_page       Results per page.
	 * @param int    $page           1-based page number.
	 * @param string $orderby        One of title|date|usage|file_size.
	 * @param string $order          ASC or DESC.
	 * @param string $media_type     One of Image|Video|Audio|Document, or empty.
	 * @param string $reference_type One of block|featured_image|classic|postmeta, or empty.
	 * @param string $usage_filter   One of used|unused, or empty for all.
	 * @param bool   $missing_alt    Restrict to references embedded without alt text.
	 * @param string $marked         One of marked|unmarked, or empty for all.
	 * @return array{ items: array, total: int }
	 */
	public static function get_attachments_rest(
		string $search = '',
		int $per_page = 20,
		int $page = 1,
		string $orderby = 'date',
		string $order = 'DESC',
		string $media_type = '',
		string $reference_type = '',
		string $usage_filter = '',
		bool $missing_alt = false,
		string $marked = ''
	): array {
		global $wpdb;
		$offset = ( $page - 1 ) * $per_page;

		// Serve from cache when an identical query was run since the last write.
		// Every argument that changes the result set must be in this key —
		// omitting one makes two different queries collide on sites with a
		// persistent object cache.
		$cache_key = 'rest_' . md5(
			wp_json_encode(
				array(
					$search,
					$per_page,
					$page,
					$orderby,
					$order,
					$media_type,
					$reference_type,
					$usage_filter,
					$missing_alt,
					$marked,
				)
			)
		) . '_' . self::last_changed();
		$cached    = wp_cache_get( $cache_key, self::CACHE_GROUP );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		// Flat query against the denormalized summary projection: no GROUP BY,
		// no CAST, no postmeta join. Every filter/sort column is indexed.
		$summary = self::summary_table_name();

		list( $where_sql, $args ) = self::build_filter_where(
			$search,
			$media_type,
			$reference_type,
			$usage_filter,
			$missing_alt,
			$marked
		);

		// ORDER BY from allowlist — never interpolate raw input. A secondary key
		// on attachment_id makes pagination deterministic when the primary key
		// has ties (e.g. equal file sizes).
		$order_map = array(
			'title'     => 's.post_title',
			'date'      => 's.post_date',
			'usage'     => 's.usage_count',
			'file_size' => 's.file_size',
		);
		$order_col = $order_map[ $orderby ] ?? 's.post_date';
		$order_dir = ( 'ASC' === strtoupper( $order ) ) ? 'ASC' : 'DESC';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber, PluginCheck.Security.DirectDB.UnescapedDBParameter
		// Single flat count for every filter combination.
		$count = self::count_query( "SELECT COUNT(*) FROM {$summary} s {$where_sql}", $args );

		$items_args = array_merge( $args, array( $per_page, $offset ) );
		$items      = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT
					s.attachment_id AS ID,
					s.post_title,
					s.mime_type AS post_mime_type,
					s.post_date,
					s.usage_count,
					s.missing_alt AS content_alt_missing,
					s.file_size,
					s.alt_text,
					s.marked_for_deletion
				FROM {$summary} s
				{$where_sql}
				ORDER BY {$order_col} {$order_dir}, s.attachment_id {$order_dir}
				LIMIT %d OFFSET %d",
				...$items_args
			)
		);
		// phpcs:enable

		$result = array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => (int) $count,
		);
		wp_cache_set( $cache_key, $result, self::CACHE_GROUP );

		return $result;
	}

	/**
	 * Delete all index rows that originate from a given source post.
	 * Used when a post is trashed or permanently deleted.
	 *
	 * @param int $source_post_id Source post ID.
	 */
	public static function delete_for_post( int $source_post_id ): void {
		global $wpdb;
		// Attachments that will lose a reference — refresh their summary after.
		$affected = self::attachment_ids_for_post( $source_post_id );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table_name(), array( 'source_post_id' => $source_post_id ), array( '%d' ) );
		if ( ! self::$defer_summary ) {
			self::refresh_summary_for_attachments( $affected );
		}
		self::flush_cache();
	}

	/**
	 * Delete all index rows that reference a given attachment.
	 * Used when an attachment is permanently deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public static function delete_for_attachment( int $attachment_id ): void {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( self::table_name(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );
		// The attachment itself is gone — remove its summary row outright.
		$wpdb->delete( self::summary_table_name(), array( 'attachment_id' => $attachment_id ), array( '%d' ) );
		// phpcs:enable
		self::flush_cache();
	}

	/**
	 * Upper bound on how many attachments one "mark all matching" call touches.
	 *
	 * Marking writes one meta row per attachment, so an unbounded call on a very
	 * large library would be a long-running request. Callers report the cap back
	 * to the user rather than silently marking a subset.
	 *
	 * @var int
	 */
	const MARK_MATCHING_LIMIT = 5000;

	/**
	 * Mark or unmark a specific set of attachments.
	 *
	 * Writes the meta (the source of truth) through the meta API so object
	 * caches stay coherent and hooks fire, then patches the summary projection
	 * in one statement rather than rebuilding the rows.
	 *
	 * @param int[] $ids    Attachment IDs.
	 * @param bool  $marked True to mark, false to clear the mark.
	 * @return int Number of attachments whose flag was written.
	 */
	public static function set_marked( array $ids, bool $marked ): int {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return 0;
		}

		foreach ( $ids as $id ) {
			if ( $marked ) {
				update_post_meta( $id, self::MARKED_META_KEY, time() );
			} else {
				delete_post_meta( $id, self::MARKED_META_KEY );
			}
		}

		// Safe to inline: every element is an intval'd integer.
		$in      = implode( ', ', $ids );
		$summary = self::summary_table_name();
		$value   = $marked ? 1 : 0;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$summary} SET marked_for_deletion = %d WHERE attachment_id IN ({$in})",
				$value
			)
		);
		// phpcs:enable

		self::flush_cache();

		return count( $ids );
	}

	/**
	 * Mark or unmark every attachment matching a filter set.
	 *
	 * Resolves the filters to IDs first rather than issuing a bare UPDATE, so
	 * the meta writes go through the meta API and the summary stays in step.
	 * Bounded by MARK_MATCHING_LIMIT.
	 *
	 * @param bool   $marked         True to mark, false to clear.
	 * @param string $search         Filename substring to match.
	 * @param string $media_type     One of Image|Video|Audio|Document, or empty.
	 * @param string $reference_type One of block|featured_image|classic|postmeta, or empty.
	 * @param string $usage_filter   One of used|unused, or empty for all.
	 * @param bool   $missing_alt    Restrict to references embedded without alt text.
	 * @param string $marked_filter  One of marked|unmarked, or empty for all.
	 * @return array{ count: int, total: int, capped: bool }
	 */
	public static function mark_matching(
		bool $marked,
		string $search = '',
		string $media_type = '',
		string $reference_type = '',
		string $usage_filter = '',
		bool $missing_alt = false,
		string $marked_filter = ''
	): array {
		global $wpdb;

		list( $where_sql, $args ) = self::build_filter_where(
			$search,
			$media_type,
			$reference_type,
			$usage_filter,
			$missing_alt,
			$marked_filter
		);

		$summary = self::summary_table_name();

		$total = self::count_query( "SELECT COUNT(*) FROM {$summary} s {$where_sql}", $args );

		$id_args = array_merge( $args, array( self::MARK_MATCHING_LIMIT ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT s.attachment_id FROM {$summary} s {$where_sql} ORDER BY s.attachment_id ASC LIMIT %d",
				...$id_args
			)
		);
		// phpcs:enable

		$ids   = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
		$count = self::set_marked( $ids, $marked );

		return array(
			'count'  => $count,
			'total'  => (int) $total,
			'capped' => $total > self::MARK_MATCHING_LIMIT,
		);
	}

	/**
	 * Number of attachments currently marked for deletion.
	 *
	 * @return int
	 */
	public static function marked_count(): int {
		$summary = self::summary_table_name();
		return self::count_query( "SELECT COUNT(*) FROM {$summary} s WHERE s.marked_for_deletion = 1", array() );
	}

	/**
	 * Attachment IDs currently marked for deletion.
	 *
	 * @param int $limit Maximum IDs to return.
	 * @return int[]
	 */
	public static function get_marked_ids( int $limit ): array {
		global $wpdb;
		$summary = self::summary_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT attachment_id FROM {$summary} WHERE marked_for_deletion = 1 ORDER BY attachment_id ASC LIMIT %d",
				$limit
			)
		);
		// phpcs:enable

		return is_array( $ids ) ? array_map( 'intval', $ids ) : array();
	}

	/**
	 * Usage counts for a set of attachments, keyed by attachment ID.
	 *
	 * One query so the delete endpoint can enforce the "unused only" rule for a
	 * whole batch without a per-item lookup. IDs with no summary row are absent
	 * from the result; callers must treat a miss as "usage unknown" and refuse,
	 * never as "unused".
	 *
	 * @param int[] $ids Attachment IDs.
	 * @return array<int, int>
	 */
	public static function usage_counts_for( array $ids ): array {
		global $wpdb;

		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		if ( ! $ids ) {
			return array();
		}

		// Safe to inline: every element is an intval'd integer.
		$in      = implode( ', ', $ids );
		$summary = self::summary_table_name();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$rows = $wpdb->get_results( "SELECT attachment_id, usage_count FROM {$summary} WHERE attachment_id IN ({$in})" );
		// phpcs:enable

		$counts = array();
		foreach ( (array) $rows as $row ) {
			$counts[ (int) $row->attachment_id ] = (int) $row->usage_count;
		}

		return $counts;
	}
}
