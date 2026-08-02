<?php
/**
 * Media Audit Indexer
 *
 * Keeps the media audit index in sync with content as it changes, and owns
 * the REST route and scan cron registrations. Incremental updates run on
 * post and attachment lifecycle hooks so the index stays accurate between
 * full scans.
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
 * Class AuditIndexer
 *
 * Registers every non-UI hook the audit subsystem needs. Callbacks are named
 * methods rather than closures so third parties can unhook them.
 */
class AuditIndexer {

	/**
	 * Register hooks.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_rest_routes' ) );
		add_action( BatchRunner::CRON_HOOK, array( BatchRunner::class, 'run_batch' ) );
		add_action( BatchRunner::BACKFILL_HOOK, array( BatchRunner::class, 'run_filesize_backfill' ) );

		// Reuse the existing database health-check cron to self-heal a manually
		// dropped audit table, matching what it already does for the revisions
		// table. Forced, so it repairs even when the version sentinel is current.
		add_action( 'smr_db_health_check', array( $this, 'run_health_check' ) );

		add_action( 'save_post', array( $this, 'on_save_post' ), 10, 2 );

		// Purge index rows that originate from a post when it is trashed or
		// permanently deleted, so its attachments stop counting as "used".
		add_action( 'trashed_post', array( IndexTable::class, 'delete_for_post' ) );
		add_action( 'before_delete_post', array( IndexTable::class, 'delete_for_post' ) );

		add_action( 'add_attachment', array( $this, 'on_add_attachment' ) );
		add_action( 'delete_attachment', array( $this, 'on_delete_attachment' ) );
	}

	/**
	 * Register the audit REST route.
	 */
	public function register_rest_routes(): void {
		$controller = new RestController();
		$controller->register_routes();
	}

	/**
	 * Recreate the audit tables if they have gone missing.
	 */
	public function run_health_check(): void {
		IndexTable::ensure_tables( true );
	}

	/**
	 * Re-index a post whenever it is saved.
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 */
	public function on_save_post( int $post_id, $post ): void {
		if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof \WP_Post || 'attachment' === $post->post_type ) {
			return;
		}

		IndexTable::ensure_tables();
		BatchRunner::reindex_post( $post_id );
	}

	/**
	 * Handle a newly uploaded attachment.
	 *
	 * Invalidates the cached attachment-ID set so the scanner validates
	 * references against current attachments, and creates a summary row
	 * (usage 0) so the file appears in the list immediately.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function on_add_attachment( int $post_id ): void {
		IndexTable::ensure_tables();
		BatchRunner::flush_attachment_ids();
		IndexTable::refresh_summary_for_attachments( array( $post_id ) );
	}

	/**
	 * Handle a deleted attachment.
	 *
	 * Purges rows referencing it — an orphaned row would skew usage counts —
	 * and drops the cached attachment-ID set. Merged into a single callback so
	 * the ordering between the two operations is explicit.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public function on_delete_attachment( int $post_id ): void {
		IndexTable::delete_for_attachment( $post_id );
		BatchRunner::flush_attachment_ids();
	}
}
