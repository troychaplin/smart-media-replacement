<?php
/**
 * Revision Manager
 *
 * @package Smart_Media_Replacement
 */

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
		/**
		 * Filter whether to create a revision for this attachment.
		 *
		 * @param bool $create        Whether to create a revision.
		 * @param int  $attachment_id The attachment ID.
		 */
		$should_create = apply_filters( 'smr_create_revision', true, $attachment_id );

		if ( ! $should_create ) {
			error_log( '[SMR] Revision creation skipped for attachment ' . $attachment_id . ' (filtered)' );
			return;
		}

		// Get the current file info.
		$current_file = get_attached_file( $attachment_id );
		if ( ! $current_file || ! file_exists( $current_file ) ) {
			error_log( '[SMR] Cannot create revision - file does not exist: ' . $current_file );
			return;
		}

		// Get the original filename (handle scaled images).
		$current_filename  = basename( $current_file );
		$original_filename = $this->get_original_filename( $current_filename );

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
		$version_type  = isset( $replacement_data['version_type'] ) ? $replacement_data['version_type'] : get_option( 'smr_default_version_type', 'minor' );
		$version_info  = $this->calculate_next_version( $attachment_id, $version_type );
		$comment       = isset( $replacement_data['comment'] ) ? sanitize_textarea_field( $replacement_data['comment'] ) : '';

		// Store the file.
		$storage_result = RevisionStorage::store_revision(
			$attachment_id,
			$source_file,
			$version_info['version'],
			$original_filename
		);

		if ( ! $storage_result ) {
			error_log( '[SMR] Failed to store revision file for attachment ' . $attachment_id );
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
		$max_revisions = apply_filters( 'smr_max_revisions', (int) get_option( 'smr_max_revisions', 10 ), $attachment_id );

		if ( $max_revisions <= 0 ) {
			return; // Unlimited.
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
			error_log( '[SMR] Cleaned ' . count( $deleted_ids ) . ' excess revisions for attachment ' . $attachment_id );
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

		error_log( '[SMR] Cleaned up all revisions for deleted attachment ' . $attachment_id );
	}

	/**
	 * Run scheduled cleanup for retention policy.
	 */
	public function run_cleanup(): void {
		/**
		 * Filter the retention period in days.
		 *
		 * @param int $days Retention days (0 = disabled).
		 */
		$retention_days = apply_filters( 'smr_retention_days', (int) get_option( 'smr_retention_days', 0 ), 0 );

		if ( $retention_days <= 0 ) {
			return; // Retention policy disabled.
		}

		error_log( '[SMR] Running retention cleanup: ' . $retention_days . ' days' );

		$expired = RevisionDatabase::get_expired_revisions( $retention_days );

		if ( empty( $expired ) ) {
			return;
		}

		$deleted_count = 0;
		$grouped       = array();

		// Group by attachment for action hook.
		foreach ( $expired as $revision ) {
			$grouped[ $revision->attachment_id ][] = $revision->id;

			// Delete file.
			RevisionStorage::delete_revision_file( $revision->file_path );

			// Delete database record.
			RevisionDatabase::delete( $revision->id );
			++$deleted_count;
		}

		// Fire actions for each attachment.
		foreach ( $grouped as $attachment_id => $revision_ids ) {
			do_action( 'smr_revisions_cleaned', $attachment_id, $revision_ids );
		}

		error_log( '[SMR] Retention cleanup complete: ' . $deleted_count . ' revisions deleted' );
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

		// Get the revision file.
		$revision_file = RevisionStorage::get_full_path( $revision->file_path );

		if ( ! file_exists( $revision_file ) ) {
			return new \WP_Error( 'file_not_found', __( 'Revision file not found.', 'smart-media-replacement' ) );
		}

		// First, create a revision of the current file before restoring.
		$restore_comment = $comment ? $comment : sprintf(
			/* translators: %s: version being restored */
			__( 'Restored from version %s', 'smart-media-replacement' ),
			$revision->version
		);

		$this->create_revision_before_replace(
			$attachment_id,
			array(
				'version_type' => 'minor',
				'comment'      => $restore_comment,
			)
		);

		// Get current file info.
		$current_file  = get_attached_file( $attachment_id );
		$current_dir   = dirname( $current_file );
		$original_name = $this->get_original_filename( basename( $current_file ) );

		// Determine target path.
		$target_path = path_join( $current_dir, $original_name );

		// Delete old files.
		$this->delete_attachment_files( $attachment_id );

		// Copy revision file to attachment location.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;

		if ( ! $wp_filesystem->copy( $revision_file, $target_path, true ) ) {
			return new \WP_Error( 'copy_failed', __( 'Failed to restore revision file.', 'smart-media-replacement' ) );
		}

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

		error_log( '[SMR] Restored revision ' . $revision_id . ' for attachment ' . $attachment_id );

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
		$filename  = $revision->version . '-' . basename( $revision->file_path );

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

		$attachment = get_post( $attachment_id );
		$filename   = 'revisions-' . sanitize_file_name( $attachment->post_title ) . '.zip';

		RevisionStorage::serve_download( $zip_path, $filename );

		// Clean up ZIP file after download.
		wp_delete_file( $zip_path );
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
			$user = get_user_by( 'id', $revision->user_id );
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

		wp_send_json_success(
			array(
				'revisions'     => $formatted,
				'total_storage' => size_format( $total_storage ),
				'count'         => count( $revisions ),
			)
		);
	}

	/**
	 * Extract original filename from a potentially scaled filename.
	 *
	 * @param string $filename The filename to process.
	 * @return string The original filename without -scaled suffix.
	 */
	private function get_original_filename( string $filename ): string {
		if ( preg_match( '/^(.+)-scaled(\.[^.]+)$/', $filename, $matches ) ) {
			return $matches[1] . $matches[2];
		}
		return $filename;
	}

	/**
	 * Delete all files associated with an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	private function delete_attachment_files( int $attachment_id ): void {
		$current_file = get_attached_file( $attachment_id );
		$current_dir  = dirname( $current_file );
		$meta         = wp_get_attachment_metadata( $attachment_id );

		// Delete main file.
		if ( file_exists( $current_file ) ) {
			wp_delete_file( $current_file );
		}

		// Delete original if scaled.
		$original_name = $this->get_original_filename( basename( $current_file ) );
		if ( $original_name !== basename( $current_file ) ) {
			$original_path = path_join( $current_dir, $original_name );
			if ( file_exists( $original_path ) ) {
				wp_delete_file( $original_path );
			}
		}

		// Delete all sizes.
		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_info ) {
				$size_file = path_join( $current_dir, $size_info['file'] );
				if ( file_exists( $size_file ) ) {
					wp_delete_file( $size_file );
				}
			}
		}
	}
}
