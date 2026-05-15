<?php
/**
 * Revision Manager
 *
 * @package Smart_Media_Replacement
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

namespace Smart_Media_Replacement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RevisionManager
 *
 * Orchestrates revision creation, restoration, and cleanup.
 */
class RevisionManager {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Self-heal: verify the revisions table on a configurable cron schedule
		// rather than on every admin page load. Covers DB resets, manual drops,
		// and aborted activations. Frequency is controlled via plugin settings;
		// use WP-CLI for immediate on-demand repair.
		add_action( 'smr_db_health_check', array( RevisionDatabase::class, 'ensure_table' ) );

		// Hook into file replacement to create revisions.
		add_action( 'smart_media_replacement_before_replace', array( $this, 'create_revision_before_replace' ), 10, 2 );

		// Hook into attachment deletion.
		add_action( 'delete_attachment', array( $this, 'cleanup_attachment_revisions' ) );

		// Register cron handler.
		add_action( 'smr_cleanup_revisions', array( $this, 'run_cleanup' ) );

		// Register AJAX handlers.
		add_action( 'wp_ajax_smr_restore_revision', array( $this, 'ajax_restore_revision' ) );
		add_action( 'wp_ajax_smr_download_revision', array( $this, 'ajax_download_revision' ) );
		add_action( 'wp_ajax_smr_download_all_revisions', array( $this, 'ajax_download_all_revisions' ) );
		add_action( 'wp_ajax_smr_get_revisions', array( $this, 'ajax_get_revisions' ) );
	}

	/**
	 * Create a revision before file replacement.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $replacement_data Data about the replacement (version_type, comment).
	 */
	public function create_revision_before_replace( int $attachment_id, array $replacement_data = array() ) {
		// Check if revisions are globally enabled.
		if ( ! Settings::get( 'smr_enable_revisions', true ) ) {
			return;
		}

		// Check if revisions are enabled for this file type.
		if ( ! Helpers::is_revision_enabled_for_attachment( $attachment_id ) ) {
			return;
		}

		/**
		 * Filter whether to create a revision for this attachment.
		 *
		 * @param bool $create        Whether to create a revision.
		 * @param int  $attachment_id The attachment ID.
		 */
		$should_create = apply_filters( 'smr_create_revision', true, $attachment_id );

		if ( ! $should_create ) {
			return;
		}

		// Get the current file info.
		$current_file = get_attached_file( $attachment_id );
		if ( ! $current_file || ! file_exists( $current_file ) ) {
			return;
		}

		// Get the original filename (handle scaled images).
		$current_filename  = basename( $current_file );
		$original_filename = Helpers::get_original_filename( $current_filename );

		// Check if original file exists (for scaled images).
		$current_dir = dirname( $current_file );
		$source_file = $current_file;
		if ( $original_filename !== $current_filename ) {
			$original_path = path_join( $current_dir, $original_filename );
			if ( file_exists( $original_path ) ) {
				$source_file = $original_path;
			}
		}

		// Determine version number.
		$version_type = isset( $replacement_data['version_type'] ) ? $replacement_data['version_type'] : Settings::get( 'smr_default_version_type', 'minor' );
		$version_info = $this->calculate_next_version( $attachment_id, $version_type );
		$comment      = isset( $replacement_data['comment'] ) ? sanitize_textarea_field( $replacement_data['comment'] ) : '';

		// Store the file.
		$storage_result = RevisionStorage::store_revision(
			$attachment_id,
			$source_file,
			$version_info['version'],
			$original_filename
		);

		if ( ! $storage_result ) {
			return;
		}

		// Insert database record.
		$revision_id = RevisionDatabase::insert(
			array(
				'attachment_id' => $attachment_id,
				'version'       => $version_info['version'],
				'version_major' => $version_info['major'],
				'version_minor' => $version_info['minor'],
				'file_path'     => $storage_result['path'],
				'file_size'     => $storage_result['size'],
				'mime_type'     => get_post_mime_type( $attachment_id ),
				'comment'       => $comment,
			)
		);

		if ( $revision_id ) {
			/**
			 * Fires after a revision is created.
			 *
			 * @param int   $revision_id   The revision ID.
			 * @param int   $attachment_id The attachment ID.
			 * @param array $revision_data The revision data.
			 */
			do_action(
				'smr_revision_created',
				$revision_id,
				$attachment_id,
				array(
					'version'   => $version_info['version'],
					'file_path' => $storage_result['path'],
					'comment'   => $comment,
				)
			);

			// Enforce revision limits.
			$this->enforce_revision_limit( $attachment_id );
		}
	}

	/**
	 * Calculate the next version number.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $version_type  'major' or 'minor'.
	 * @return array Version info with 'version', 'major', 'minor'.
	 */
	public function calculate_next_version( int $attachment_id, string $version_type = 'minor' ): array {
		$latest = RevisionDatabase::get_latest( $attachment_id );

		if ( ! $latest ) {
			// First revision is always 1.0.
			return array(
				'version' => '1.0',
				'major'   => 1,
				'minor'   => 0,
			);
		}

		$major = (int) $latest->version_major;
		$minor = (int) $latest->version_minor;

		if ( 'major' === $version_type ) {
			++$major;
			$minor = 0;
		} else {
			++$minor;
		}

		return array(
			'version' => $major . '.' . $minor,
			'major'   => $major,
			'minor'   => $minor,
		);
	}

	/**
	 * Get current version for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Version string or '1.0' if no revisions.
	 */
	public function get_current_version( int $attachment_id ): string {
		$latest = RevisionDatabase::get_latest( $attachment_id );
		if ( $latest ) {
			// Return the next version (current file is one version ahead).
			$major = (int) $latest->version_major;
			$minor = (int) $latest->version_minor + 1;
			return $major . '.' . $minor;
		}
		return '1.0';
	}

	/**
	 * Enforce revision limit for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function enforce_revision_limit( int $attachment_id ): void {
		/**
		 * Filter the maximum number of revisions per attachment.
		 *
		 * @param int $max_revisions  Maximum revisions to keep.
		 * @param int $attachment_id  The attachment ID.
		 */
		$max_revisions = apply_filters( 'smr_max_revisions', (int) Settings::get( 'smr_max_revisions', 10 ), $attachment_id );

		$current_count = RevisionDatabase::get_count( $attachment_id );

		if ( $max_revisions <= 0 ) {
			return; // Unlimited.
		}

		if ( $current_count <= $max_revisions ) {
			return;
		}

		$excess_ids = RevisionDatabase::get_excess_revisions( $attachment_id, $max_revisions );

		if ( empty( $excess_ids ) ) {
			return;
		}

		$deleted_ids = array();

		foreach ( $excess_ids as $revision_id ) {
			$revision = RevisionDatabase::get( $revision_id );
			if ( $revision ) {
				// Delete file.
				RevisionStorage::delete_revision_file( $revision->file_path );
				// Delete database record.
				RevisionDatabase::delete( $revision_id );
				$deleted_ids[] = $revision_id;
			}
		}

		if ( ! empty( $deleted_ids ) ) {
			/**
			 * Fires after revisions are cleaned up due to limit.
			 *
			 * @param int   $attachment_id The attachment ID.
			 * @param array $deleted_ids   Array of deleted revision IDs.
			 */
			do_action( 'smr_revisions_cleaned', $attachment_id, $deleted_ids );
		}
	}

	/**
	 * Clean up revisions when attachment is deleted.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function cleanup_attachment_revisions( int $attachment_id ): void {
		// Delete all revision files.
		RevisionStorage::delete_attachment_revisions( $attachment_id );

		// Delete all database records.
		RevisionDatabase::delete_by_attachment( $attachment_id );
	}

	/**
	 * Run scheduled cleanup for retention policy.
	 *
	 * On multisite the cron fires in the main site's context, so per-blog
	 * queries would only ever clean up blog 1 without the switch_to_blog
	 * loop. A time budget prevents the job from exceeding PHP's
	 * max_execution_time — any sites not reached will be processed on the
	 * next daily run.
	 */
	public function run_cleanup(): void {
		/**
		 * Filter the retention period in days.
		 *
		 * @param int $days Retention days (0 = disabled).
		 */
		$retention_days = apply_filters( 'smr_retention_days', (int) Settings::get( 'smr_retention_days', 0 ), 0 );

		if ( $retention_days <= 0 ) {
			return;
		}

		// Budget: leave 10 s under the server's max_execution_time.
		// If max_execution_time is 0 (unlimited) use 60 s so the filter
		// still gives operators a meaningful knob.
		$max_exec = (int) ini_get( 'max_execution_time' );

		/**
		 * Filter the number of seconds the cron cleanup may run before
		 * stopping gracefully. Remaining sites process on the next run.
		 *
		 * @param int $seconds Time budget in seconds.
		 */
		$budget   = (int) apply_filters( 'smr_cleanup_time_limit', $max_exec > 0 ? max( 5, $max_exec - 10 ) : 60 );
		$deadline = microtime( true ) + $budget;

		if ( is_multisite() ) {
			$site_ids = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $site_ids as $site_id ) {
				if ( microtime( true ) >= $deadline ) {
					break; // Out of time; remaining sites process on next run.
				}
				switch_to_blog( (int) $site_id );
				self::cleanup_site( $retention_days, $deadline );
				restore_current_blog();
			}
		} else {
			self::cleanup_site( $retention_days, $deadline );
		}
	}

	/**
	 * Delete expired revisions for whichever blog is currently active.
	 *
	 * Processes rows in chunks to keep peak memory low and avoid loading
	 * an unbounded result set. Cursor-based pagination (WHERE id > last)
	 * keeps each batch stable even as prior rows are deleted.
	 *
	 * Pass a non-zero $deadline to stop early when running under a time
	 * budget (WP-Cron). Pass 0.0 for no limit (WP-CLI).
	 *
	 * @param int   $retention_days Retention period in days.
	 * @param float $deadline       microtime(true) deadline; 0.0 = unlimited.
	 * @return int Number of revisions deleted.
	 */
	public static function cleanup_site( int $retention_days, float $deadline = 0.0 ): int {
		/**
		 * Filter the number of expired revisions to process per DB round-trip.
		 *
		 * @param int $chunk_size Rows per batch.
		 */
		$chunk_size  = max( 1, (int) apply_filters( 'smr_cleanup_chunk_size', 100 ) );
		$last_id     = 0;
		$deleted     = 0;
		$batch_count = 0;

		do {
			if ( $deadline > 0.0 && microtime( true ) >= $deadline ) {
				break;
			}

			$batch       = RevisionDatabase::get_expired_revisions_batch( $retention_days, $chunk_size, $last_id );
			$batch_count = count( $batch );

			if ( 0 === $batch_count ) {
				break;
			}

			$ids     = array();
			$grouped = array();

			foreach ( $batch as $revision ) {
				$id                          = (int) $revision->id;
				$attachment_id               = (int) $revision->attachment_id;
				$ids[]                       = $id;
				$grouped[ $attachment_id ][] = $id;
				$last_id                     = max( $last_id, $id );
				RevisionStorage::delete_revision_file( $revision->file_path );
			}

			RevisionDatabase::delete_by_ids( $ids );
			$deleted += count( $ids );

			foreach ( $grouped as $attachment_id => $revision_ids ) {
				do_action( 'smr_revisions_cleaned', $attachment_id, $revision_ids );
			}
		} while ( $batch_count === $chunk_size );

		return $deleted;
	}

	/**
	 * Restore a revision as the current file.
	 *
	 * @param int    $revision_id Revision ID to restore.
	 * @param string $comment     Optional comment for the new revision.
	 * @return bool|WP_Error True on success, WP_Error on failure.
	 */
	public function restore_revision( int $revision_id, string $comment = '' ) {
		$revision = RevisionDatabase::get( $revision_id );

		if ( ! $revision ) {
			return new \WP_Error( 'invalid_revision', __( 'Revision not found.', 'smart-media-replacement' ) );
		}

		$attachment_id = (int) $revision->attachment_id;

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			return new \WP_Error( 'permission_denied', __( 'You do not have permission to restore this revision.', 'smart-media-replacement' ) );
		}

		// Get the revision file path and verify it exists BEFORE any modifications.
		$revision_file = RevisionStorage::get_full_path( $revision->file_path );

		if ( ! file_exists( $revision_file ) ) {
			return new \WP_Error( 'file_not_found', __( 'Revision file not found. The file may have been deleted by retention policy or manually removed.', 'smart-media-replacement' ) );
		}

		// Get current file info BEFORE creating the revision (in case it's needed).
		$current_file  = get_attached_file( $attachment_id );
		$current_dir   = dirname( $current_file );
		$original_name = Helpers::get_original_filename( basename( $current_file ) );
		$target_path   = path_join( $current_dir, $original_name );

		// Snapshot the current file as a revision before we overwrite it.
		$restore_comment = $comment ? $comment : sprintf(
			/* translators: %s: version being restored */
			__( 'Restored from version %s', 'smart-media-replacement' ),
			$revision->version
		);

		if ( file_exists( $current_file ) || ( basename( $current_file ) !== $original_name && file_exists( $target_path ) ) ) {
			$this->create_revision_before_replace(
				$attachment_id,
				array(
					'version_type' => 'minor',
					'comment'      => $restore_comment,
				)
			);
		}

		// Copy the revision file into place FIRST. If anything after this fails,
		// the user has a valid attachment file rather than an empty hole.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_copy -- WP_Filesystem falls back to FTP on hosts where the PHP user does not own uploads, fataling on null FTP connections.
		if ( ! copy( $revision_file, $target_path ) ) {
			return new \WP_Error( 'copy_failed', __( 'Failed to restore revision file.', 'smart-media-replacement' ) );
		}

		// Clean up old files (the new file at $target_path is preserved).
		Helpers::delete_attachment_files( $attachment_id, $target_path );

		// Regenerate metadata.
		require_once ABSPATH . 'wp-admin/includes/image.php';
		$attachment_data = wp_generate_attachment_metadata( $attachment_id, $target_path );
		wp_update_attachment_metadata( $attachment_id, $attachment_data );

		// Handle scaled images.
		$final_file_path = $target_path;
		if ( ! empty( $attachment_data['original_image'] ) ) {
			$upload_dir       = wp_get_upload_dir();
			$scaled_full_path = path_join( $upload_dir['basedir'], $attachment_data['file'] );
			if ( file_exists( $scaled_full_path ) ) {
				$final_file_path = $scaled_full_path;
			}
		}

		update_attached_file( $attachment_id, $final_file_path );

		/**
		 * Fires after a revision is restored.
		 *
		 * @param int $revision_id   The restored revision ID.
		 * @param int $attachment_id The attachment ID.
		 */
		do_action( 'smr_revision_restored', $revision_id, $attachment_id );

		return true;
	}

	/**
	 * AJAX handler for restoring a revision.
	 */
	public function ajax_restore_revision(): void {
		check_ajax_referer( 'smr_revision_nonce', 'nonce' );

		$revision_id = isset( $_POST['revision_id'] ) ? intval( $_POST['revision_id'] ) : 0;
		$comment     = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';

		if ( ! $revision_id ) {
			wp_send_json_error( __( 'Invalid revision ID.', 'smart-media-replacement' ) );
		}

		$result = $this->restore_revision( $revision_id, $comment );

		if ( is_wp_error( $result ) ) {
			wp_send_json_error( $result->get_error_message() );
		}

		wp_send_json_success(
			array(
				'message' => __( 'Revision restored successfully.', 'smart-media-replacement' ),
			)
		);
	}

	/**
	 * AJAX handler for downloading a single revision.
	 */
	public function ajax_download_revision(): void {
		// Verify nonce from query string for download links.
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'smr_download_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'smart-media-replacement' ), '', array( 'response' => 403 ) );
		}

		$revision_id = isset( $_GET['revision_id'] ) ? intval( $_GET['revision_id'] ) : 0;

		if ( ! $revision_id ) {
			wp_die( esc_html__( 'Invalid revision ID.', 'smart-media-replacement' ), '', array( 'response' => 400 ) );
		}

		$revision = RevisionDatabase::get( $revision_id );

		if ( ! $revision ) {
			wp_die( esc_html__( 'Revision not found.', 'smart-media-replacement' ), '', array( 'response' => 404 ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $revision->attachment_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'smart-media-replacement' ), '', array( 'response' => 403 ) );
		}

		$file_path = RevisionStorage::get_full_path( $revision->file_path );
		// The file_path already contains the version prefix, so just use the basename.
		$filename = basename( $revision->file_path );

		RevisionStorage::serve_download( $file_path, $filename );
	}

	/**
	 * AJAX handler for downloading all revisions as ZIP.
	 */
	public function ajax_download_all_revisions(): void {
		// Verify nonce from query string.
		if ( ! isset( $_GET['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['nonce'] ) ), 'smr_download_nonce' ) ) {
			wp_die( esc_html__( 'Security check failed.', 'smart-media-replacement' ), '', array( 'response' => 403 ) );
		}

		$attachment_id = isset( $_GET['attachment_id'] ) ? intval( $_GET['attachment_id'] ) : 0;

		if ( ! $attachment_id ) {
			wp_die( esc_html__( 'Invalid attachment ID.', 'smart-media-replacement' ), '', array( 'response' => 400 ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_die( esc_html__( 'Permission denied.', 'smart-media-replacement' ), '', array( 'response' => 403 ) );
		}

		$revisions = RevisionDatabase::get_by_attachment( $attachment_id );

		if ( empty( $revisions ) ) {
			wp_die( esc_html__( 'No revisions found.', 'smart-media-replacement' ), '', array( 'response' => 404 ) );
		}

		$zip_path = RevisionStorage::create_zip( $attachment_id, $revisions );

		if ( ! $zip_path ) {
			wp_die( esc_html__( 'Failed to create ZIP archive.', 'smart-media-replacement' ), '', array( 'response' => 500 ) );
		}

		// serve_download() ends with exit, so an inline wp_delete_file after it
		// never runs. Register the cleanup as a shutdown function — PHP fires
		// these on normal exit, so the temp ZIP gets removed after streaming.
		register_shutdown_function(
			function () use ( $zip_path ) {
				if ( file_exists( $zip_path ) ) {
					wp_delete_file( $zip_path );
				}
			}
		);

		$attachment = get_post( $attachment_id );
		$filename   = 'revisions-' . sanitize_file_name( $attachment->post_title ) . '.zip';

		RevisionStorage::serve_download( $zip_path, $filename );
	}

	/**
	 * AJAX handler for getting revisions list.
	 */
	public function ajax_get_revisions(): void {
		check_ajax_referer( 'smr_revision_nonce', 'nonce' );

		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;

		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'smart-media-replacement' ) );
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( __( 'Permission denied.', 'smart-media-replacement' ) );
		}

		$revisions     = RevisionDatabase::get_by_attachment( $attachment_id );
		$total_storage = RevisionDatabase::get_attachment_storage( $attachment_id );

		$formatted = array();
		foreach ( $revisions as $revision ) {
			$user        = get_user_by( 'id', $revision->user_id );
			$formatted[] = array(
				'id'         => $revision->id,
				'version'    => $revision->version,
				'file_size'  => size_format( $revision->file_size ),
				'comment'    => $revision->comment,
				'user_name'  => $user ? $user->display_name : __( 'Unknown', 'smart-media-replacement' ),
				'created_at' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $revision->created_at ) ),
				'mime_type'  => $revision->mime_type,
			);
		}

		// Current live file info. The "active since" timestamp is the moment
		// the current file took its place — that's the most recent revision's
		// created_at (since revisions are snapshotted at every replace/restore).
		// If no revisions exist yet, fall back to the attachment's upload date.
		$current_file_path = get_attached_file( $attachment_id );
		$current_filename  = $current_file_path ? basename( $current_file_path ) : '';
		$current_filesize  = ( $current_file_path && file_exists( $current_file_path ) ) ? filesize( $current_file_path ) : 0;
		$active_since_raw  = ! empty( $revisions )
			? $revisions[0]->created_at
			: get_post_field( 'post_date', $attachment_id );

		$current_file = array(
			'filename'     => $current_filename,
			'file_size'    => size_format( $current_filesize ),
			'mime_type'    => (string) get_post_mime_type( $attachment_id ),
			'url'          => (string) wp_get_attachment_url( $attachment_id ),
			'active_since' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $active_since_raw ) ),
		);

		wp_send_json_success(
			array(
				'current_file'  => $current_file,
				'revisions'     => $formatted,
				'total_storage' => size_format( $total_storage ),
				'count'         => count( $revisions ),
			)
		);
	}
}
