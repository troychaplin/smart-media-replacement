<?php
/**
 * Media Audit Batch Scanner
 *
 * Drives the background scan as a self-rescheduling chain of single cron
 * events. Runs three phases in order — posts, file sizes, summary — each
 * bounded per tick so a large library never exhausts a request.
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
 * Class BatchRunner
 *
 * Owns the scan lifecycle: scheduling, phase progression, cursors and the
 * progress snapshot the admin UI polls.
 */
class BatchRunner {

	const CRON_HOOK     = 'smr_audit_scan';
	const BACKFILL_HOOK = 'smr_audit_backfill_filesizes';
	const BATCH_SIZE    = 50;

	/** Post meta caching an attachment's size in bytes. */
	const FILESIZE_META_KEY = '_smr_audit_filesize';
	/** Filesize backfill and summary rebuild touch one row each — safe to chunk larger. */
	const FILESIZE_BATCH     = 200;
	const SUMMARY_BATCH      = 200;
	const CURSOR_KEY         = 'smr_audit_cursor';
	const SUMMARY_CURSOR_KEY = 'smr_audit_summary_cursor';
	const PHASE_KEY          = 'smr_audit_phase';
	const PROGRESS_KEY       = 'smr_audit_progress';
	const INDEX_BUILT_KEY    = 'smr_audit_index_built';
	const ATTACHMENT_IDS_KEY = 'smr_audit_attachment_ids';

	/** Scan phases, run in order. Each is bounded per cron tick. */
	const PHASE_POSTS     = 'posts';
	const PHASE_FILESIZES = 'filesizes';
	const PHASE_SUMMARY   = 'summary';

	/** Default post types scanned for media references. */
	const SCAN_POST_TYPES = array( 'post', 'page', 'wp_template', 'wp_template_part' );

	/** Statuses considered "live". The count denominator and the scan loop both
	 * use this exact list so progress can reach 100%. Excludes trash/auto-draft. */
	const SCAN_STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Post types the scanner walks looking for media references.
	 *
	 * Exposed as a filter rather than a setting: a site with many custom post
	 * types would turn this into an unusable checkbox wall, and the answer is
	 * almost always developer-supplied.
	 *
	 * @return string[]
	 */
	public static function scan_post_types(): array {
		/**
		 * Filters the post types scanned for media references.
		 *
		 * @param string[] $post_types Post type slugs.
		 */
		$types = apply_filters( 'smr_audit_scan_post_types', self::SCAN_POST_TYPES );

		return array_values( array_filter( array_map( 'strval', (array) $types ) ) );
	}

	/**
	 * Post statuses the scanner treats as live content.
	 *
	 * The progress denominator and the scan loop both read this, so filtering
	 * it keeps the two consistent and lets progress still reach 100%.
	 *
	 * @return string[]
	 */
	public static function scan_statuses(): array {
		/**
		 * Filters the post statuses considered live for media reference scanning.
		 *
		 * @param string[] $statuses Post status slugs.
		 */
		$statuses = apply_filters( 'smr_audit_scan_statuses', self::SCAN_STATUSES );

		return array_values( array_filter( array_map( 'strval', (array) $statuses ) ) );
	}

	/**
	 * Number of posts indexed per cron tick.
	 *
	 * @return int
	 */
	public static function batch_size(): int {
		/**
		 * Filters how many posts the scanner indexes per cron tick.
		 *
		 * @param int $batch_size Posts per tick.
		 */
		$size = (int) apply_filters( 'smr_audit_batch_size', self::BATCH_SIZE );

		return max( 1, $size );
	}

	/**
	 * Schedule the first scan tick if one is not already pending.
	 */
	public static function schedule(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, self::CRON_HOOK );
		}
	}

	/**
	 * Cancel any pending scan tick.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/**
	 * Queue a one-shot event to persist recomputed file sizes.
	 *
	 * Called from the REST read path, which must not write. The IDs travel as
	 * the event argument, so a duplicate schedule for the same set is a no-op
	 * in WP-Cron rather than a second pass.
	 *
	 * @param int[] $attachment_ids Attachment IDs needing a persisted size.
	 */
	public static function schedule_filesize_backfill( array $attachment_ids ): void {
		$attachment_ids = array_values( array_unique( array_map( 'intval', $attachment_ids ) ) );
		if ( ! $attachment_ids ) {
			return;
		}

		wp_schedule_single_event( time() + 1, self::BACKFILL_HOOK, array( $attachment_ids ) );
	}

	/**
	 * Persist cached file sizes for the given attachments.
	 *
	 * @param int[] $attachment_ids Attachment IDs to backfill.
	 */
	public static function run_filesize_backfill( $attachment_ids ): void {
		if ( ! is_array( $attachment_ids ) || ! $attachment_ids ) {
			return;
		}

		IndexTable::ensure_tables();

		foreach ( $attachment_ids as $attachment_id ) {
			$attachment_id = (int) $attachment_id;
			$file_size     = self::resolve_file_size( $attachment_id );

			if ( $file_size > 0 ) {
				update_post_meta( $attachment_id, self::FILESIZE_META_KEY, $file_size );
				IndexTable::update_summary_file_size( $attachment_id, $file_size );
			}
		}
	}

	/**
	 * Determine an attachment's size in bytes, preferring stored metadata.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Size in bytes, or 0 when it cannot be determined.
	 */
	private static function resolve_file_size( int $attachment_id ): int {
		$meta = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
			return (int) $meta['filesize'];
		}

		$path = get_attached_file( $attachment_id );
		if ( $path && file_exists( $path ) ) {
			return (int) filesize( $path );
		}

		return 0;
	}

	/** Trigger a fresh full scan (clears index and cursors). */
	public static function start_fresh(): void {
		self::unschedule();
		IndexTable::ensure_tables();
		IndexTable::truncate();
		delete_transient( self::CURSOR_KEY );
		delete_transient( self::SUMMARY_CURSOR_KEY );
		delete_transient( self::ATTACHMENT_IDS_KEY );
		delete_option( self::INDEX_BUILT_KEY );

		// Start at the posts phase. The previous summary stays visible until the
		// summary phase truncates and rebuilds it.
		set_transient( self::PHASE_KEY, self::PHASE_POSTS, DAY_IN_SECONDS );

		$total = self::get_total_post_count();
		update_option(
			self::PROGRESS_KEY,
			array(
				'status'   => 'scanning',
				'progress' => 0,
				'total'    => $total,
			),
			false
		);

		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	/**
	 * Called by WP-Cron. Runs one bounded slice of the current phase, then
	 * reschedules itself until all phases complete. Phases run in order:
	 * posts (index) -> filesizes (backfill meta) -> summary (chunked rebuild).
	 */
	public static function run_batch(): void {
		// A cron tick can be the first audit code to run on a site that was
		// never provisioned (new subsite, or a manually dropped table), so
		// guard before any query.
		IndexTable::ensure_tables();

		// get_transient() returns false when the key is absent, so this has to
		// test truthiness — a null-coalesce would keep the false and break the
		// phase switch below.
		$stored_phase = get_transient( self::PHASE_KEY );
		$phase        = is_string( $stored_phase ) && '' !== $stored_phase ? $stored_phase : self::PHASE_POSTS;

		switch ( $phase ) {
			case self::PHASE_FILESIZES:
				self::run_filesize_phase();
				break;
			case self::PHASE_SUMMARY:
				self::run_summary_phase();
				break;
			case self::PHASE_POSTS:
			default:
				self::run_posts_phase();
				break;
		}
	}

	/** Phase 1: scan a bounded batch of posts into the index (keyset paging). */
	private static function run_posts_phase(): void {
		$after_id = (int) get_transient( self::CURSOR_KEY );
		$total    = self::get_total_post_count();

		// Per-post writes skip the incremental summary refresh; the summary phase
		// rebuilds the whole projection in chunks once the index is populated.
		IndexTable::$defer_summary = true;

		$scanner = new PostScanner( self::get_all_attachment_ids() );
		$ids     = self::get_batch( $after_id );

		$last_id = $after_id;
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( $post ) {
				$scanner->scan( $post );
			}
			$last_id = $id;
		}

		$progress = self::get_progress();
		$done     = (int) ( $progress['progress'] ?? 0 ) + count( $ids );

		if ( count( $ids ) < self::batch_size() ) {
			// Posts exhausted — advance to the filesize backfill phase.
			delete_transient( self::CURSOR_KEY );
			set_transient( self::PHASE_KEY, self::PHASE_FILESIZES, DAY_IN_SECONDS );
			self::set_scanning_progress( $total, $total );
		} else {
			// Keyset cursor: resume at ID > cursor next tick. Insensitive to
			// inserts/deletes outside the processed range.
			set_transient( self::CURSOR_KEY, $last_id, HOUR_IN_SECONDS );
			self::set_scanning_progress( min( $done, $total ), $total );
		}
		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	/** Phase 2: backfill cached file sizes a bounded chunk at a time. */
	private static function run_filesize_phase(): void {
		$total = self::get_total_post_count();
		self::cache_attachment_file_sizes( self::FILESIZE_BATCH );

		if ( self::count_attachments_missing_filesize() > 0 ) {
			// More to cache — stay in this phase.
			self::set_scanning_progress( $total, $total );
		} else {
			// File sizes complete — start the summary rebuild from a clean slate.
			IndexTable::truncate_summary();
			set_transient( self::SUMMARY_CURSOR_KEY, 0, HOUR_IN_SECONDS );
			set_transient( self::PHASE_KEY, self::PHASE_SUMMARY, DAY_IN_SECONDS );
			self::set_scanning_progress( $total, $total );
		}
		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	/** Phase 3: rebuild the summary projection in bounded keyset chunks. */
	private static function run_summary_phase(): void {
		$total    = self::get_total_post_count();
		$after_id = (int) get_transient( self::SUMMARY_CURSOR_KEY );
		$ids      = self::get_attachment_ids_after( $after_id, self::SUMMARY_BATCH );

		if ( $ids ) {
			IndexTable::refresh_summary_for_attachments( $ids );
		}

		if ( count( $ids ) < self::SUMMARY_BATCH ) {
			// Final chunk — scan complete.
			delete_transient( self::SUMMARY_CURSOR_KEY );
			delete_transient( self::PHASE_KEY );
			update_option( self::INDEX_BUILT_KEY, true, false );
			update_option(
				self::PROGRESS_KEY,
				array(
					'status'   => 'complete',
					'progress' => $total,
					'total'    => $total,
				),
				false
			);
		} else {
			set_transient( self::SUMMARY_CURSOR_KEY, end( $ids ), HOUR_IN_SECONDS );
			self::set_scanning_progress( $total, $total );
			wp_schedule_single_event( time() + 1, self::CRON_HOOK );
		}
	}

	/**
	 * Persist a "scanning" progress snapshot.
	 *
	 * @param int $progress Items processed so far.
	 * @param int $total    Total items in this scan.
	 */
	private static function set_scanning_progress( int $progress, int $total ): void {
		update_option(
			self::PROGRESS_KEY,
			array(
				'status'   => 'scanning',
				'progress' => $progress,
				'total'    => $total,
			),
			false
		);
	}

	/**
	 * Fetch the next batch of scannable post IDs after a given ID (keyset paging).
	 *
	 * @param int $after_id Resume cursor; only IDs greater than this are returned.
	 * @return int[]
	 */
	private static function get_batch( int $after_id ): array {
		global $wpdb;
		$post_types = self::scan_post_types();
		$statuses   = self::scan_statuses();
		$type_ph    = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_ph  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args       = array_merge( $post_types, $statuses, array( $after_id, self::batch_size() ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type IN ({$type_ph})
				AND post_status IN ({$status_ph})
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				...$args
			)
		);
		// phpcs:enable

		return array_map( 'intval', $ids );
	}

	/**
	 * Current scan progress snapshot.
	 *
	 * @return array{status: string, progress: int, total: int}
	 */
	public static function get_progress(): array {
		$default = array(
			'status'   => 'idle',
			'progress' => 0,
			'total'    => 0,
		);
		return (array) get_option( self::PROGRESS_KEY, $default );
	}

	/**
	 * Re-index a single post (called from the save_post hook).
	 *
	 * @param int $post_id Post ID to re-index.
	 */
	public static function reindex_post( int $post_id ): void {
		// Single saves run in their own request; make sure the deferral flag a
		// concurrent scan tick might have set in this process doesn't suppress
		// the incremental summary refresh.
		IndexTable::$defer_summary = false;

		$post = get_post( $post_id );
		if ( ! $post || 'attachment' === $post->post_type ) {
			return;
		}

		// Trashing/auto-drafting fires save_post; purge rather than re-index so
		// the post's attachments stop being counted as used.
		if ( in_array( $post->post_status, array( 'trash', 'auto-draft' ), true ) ) {
			IndexTable::delete_for_post( $post_id );
			return;
		}

		// Lazy mode: validate only this post's candidate IDs (one small query)
		// instead of loading every attachment ID on the site.
		$scanner = new PostScanner();
		$scanner->scan( $post );
	}

	/**
	 * Total number of scannable posts, used as the progress denominator.
	 *
	 * @return int
	 */
	private static function get_total_post_count(): int {
		global $wpdb;
		$post_types = self::scan_post_types();
		$statuses   = self::scan_statuses();
		$type_ph    = implode( ',', array_fill( 0, count( $post_types ), '%s' ) );
		$status_ph  = implode( ',', array_fill( 0, count( $statuses ), '%s' ) );
		$args       = array_merge( $post_types, $statuses );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type IN ({$type_ph})
				AND post_status IN ({$status_ph})",
				...$args
			)
		);
		// phpcs:enable
	}

	/** Count attachments that still lack the cached file-size meta. */
	private static function count_attachments_missing_filesize(): int {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_smr_audit_filesize'
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
			AND pm.meta_id IS NULL"
		);
		// phpcs:enable
	}

	/**
	 * Keyset page of attachment IDs after a cursor, for the chunked summary phase.
	 *
	 * @param int $after_id Resume cursor; only IDs greater than this are returned.
	 * @param int $limit    Maximum IDs to return.
	 * @return int[]
	 */
	private static function get_attachment_ids_after( int $after_id, int $limit ): array {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT ID FROM {$wpdb->posts}
				WHERE post_type = 'attachment' AND post_status = 'inherit'
				AND ID > %d
				ORDER BY ID ASC
				LIMIT %d",
				$after_id,
				$limit
			)
		);
		// phpcs:enable
		return array_map( 'intval', $ids );
	}

	/**
	 * Cache file-size meta for attachments still missing it.
	 *
	 * @param int $limit Maximum attachments to process this call (0 = no limit).
	 */
	private static function cache_attachment_file_sizes( int $limit = 0 ): void {
		global $wpdb;
		$limit_sql = $limit > 0 ? $wpdb->prepare( ' LIMIT %d', $limit ) : '';
		// Only process attachments that don't already have the cached meta.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		$ids = $wpdb->get_col(
			"SELECT p.ID FROM {$wpdb->posts} p
			LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_smr_audit_filesize'
			WHERE p.post_type = 'attachment' AND p.post_status = 'inherit'
			AND pm.meta_id IS NULL{$limit_sql}"
		);
		// phpcs:enable

		foreach ( $ids as $id ) {
			$id        = (int) $id;
			$file_size = 0;
			$meta      = wp_get_attachment_metadata( $id );
			if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
				$file_size = (int) $meta['filesize'];
			}
			if ( ! $file_size ) {
				$path = get_attached_file( $id );
				if ( $path && file_exists( $path ) ) {
					$file_size = (int) filesize( $path );
				}
			}
			if ( $file_size > 0 ) {
				update_post_meta( $id, self::FILESIZE_META_KEY, $file_size );
			}
		}
	}

	/**
	 * Return all attachment IDs on the site, cached for the scan's duration.
	 *
	 * Previously re-queried (and array_flipped) on every cron tick and every
	 * post save. The cache is invalidated when an attachment is added or
	 * deleted (see Plugin::init), so a newly uploaded attachment is still
	 * picked up as a valid reference target.
	 *
	 * @return int[]
	 */
	private static function get_all_attachment_ids(): array {
		$cached = get_transient( self::ATTACHMENT_IDS_KEY );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$ids = $wpdb->get_col(
			"SELECT ID FROM {$wpdb->posts}
			WHERE post_type = 'attachment' AND post_status = 'inherit'"
		);
		$ids = array_map( 'intval', $ids );

		set_transient( self::ATTACHMENT_IDS_KEY, $ids, HOUR_IN_SECONDS );
		return $ids;
	}

	/** Drop the cached attachment-ID set (on attachment add/delete). */
	public static function flush_attachment_ids(): void {
		delete_transient( self::ATTACHMENT_IDS_KEY );
	}
}
