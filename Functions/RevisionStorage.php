<?php
/**
 * Revision Storage Handler
 *
 * @package Smart_Media_Replacement
 */

namespace Smart_Media_Replacement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class RevisionStorage
 *
 * Handles file storage operations for media revisions.
 */
class RevisionStorage {

	/**
	 * Revision storage directory name.
	 *
	 * @var string
	 */
	const STORAGE_DIR = 'smr-revisions';

	/**
	 * Get the base path for revision storage.
	 *
	 * @return string Base path for revisions.
	 */
	public static function get_base_path(): string {
		$upload_dir = wp_upload_dir();
		$base_path  = trailingslashit( $upload_dir['basedir'] ) . self::STORAGE_DIR;

		/**
		 * Filter the base revision storage path.
		 *
		 * @param string $base_path The base path for revision storage.
		 */
		return apply_filters( 'smr_revision_directory', $base_path );
	}

	/**
	 * Get the storage path for a specific attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Storage path for attachment revisions.
	 */
	public static function get_attachment_path( int $attachment_id ): string {
		return trailingslashit( self::get_base_path() ) . $attachment_id;
	}

	/**
	 * Get the full file path for a revision.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $version       Version string.
	 * @param string $filename      Original filename.
	 * @return string Full file path.
	 */
	public static function get_revision_file_path( int $attachment_id, string $version, string $filename ): string {
		$dir      = self::get_attachment_path( $attachment_id );
		$basename = pathinfo( $filename, PATHINFO_FILENAME );
		$ext      = pathinfo( $filename, PATHINFO_EXTENSION );

		return trailingslashit( $dir ) . $version . '-' . $basename . '.' . $ext;
	}

	/**
	 * Get the relative path for storing in database.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $version       Version string.
	 * @param string $filename      Original filename.
	 * @return string Relative path from uploads directory.
	 */
	public static function get_relative_path( int $attachment_id, string $version, string $filename ): string {
		$basename = pathinfo( $filename, PATHINFO_FILENAME );
		$ext      = pathinfo( $filename, PATHINFO_EXTENSION );

		return self::STORAGE_DIR . '/' . $attachment_id . '/' . $version . '-' . $basename . '.' . $ext;
	}

	/**
	 * Create the revision storage directory for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool Whether directory was created/exists.
	 */
	public static function create_directory( int $attachment_id ): bool {
		$dir = self::get_attachment_path( $attachment_id );

		if ( ! file_exists( $dir ) ) {
			$created = wp_mkdir_p( $dir );
			if ( $created ) {
				error_log( '[SMR] Created revision directory: ' . $dir );

				// Add index.php for security.
				$index_file = trailingslashit( $dir ) . 'index.php';
				if ( ! file_exists( $index_file ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					file_put_contents( $index_file, '<?php // Silence is golden.' );
				}
			} else {
				error_log( '[SMR] Failed to create revision directory: ' . $dir );
			}
			return $created;
		}

		return true;
	}

	/**
	 * Store a file as a revision.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $source_file   Source file path.
	 * @param string $version       Version string.
	 * @param string $filename      Original filename.
	 * @return array|false Array with path and size, or false on failure.
	 */
	public static function store_revision( int $attachment_id, string $source_file, string $version, string $filename ) {
		if ( ! file_exists( $source_file ) ) {
			error_log( '[SMR] Source file does not exist: ' . $source_file );
			return false;
		}

		// Create directory if needed.
		if ( ! self::create_directory( $attachment_id ) ) {
			return false;
		}

		$target_path = self::get_revision_file_path( $attachment_id, $version, $filename );

		// Initialize WordPress filesystem.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;

		// Copy the file.
		$copied = $wp_filesystem->copy( $source_file, $target_path, true );

		if ( $copied ) {
			$file_size = filesize( $target_path );
			error_log( '[SMR] Stored revision file: ' . $target_path . ' (' . size_format( $file_size ) . ')' );

			return array(
				'path' => self::get_relative_path( $attachment_id, $version, $filename ),
				'size' => $file_size,
			);
		}

		error_log( '[SMR] Failed to store revision file: ' . $target_path );
		return false;
	}

	/**
	 * Delete a revision file.
	 *
	 * @param string $relative_path Relative path from database.
	 * @return bool Whether file was deleted.
	 */
	public static function delete_revision_file( string $relative_path ): bool {
		$upload_dir = wp_upload_dir();
		$full_path  = trailingslashit( $upload_dir['basedir'] ) . $relative_path;

		if ( file_exists( $full_path ) ) {
			$deleted = wp_delete_file( $full_path );
			if ( $deleted !== false ) {
				error_log( '[SMR] Deleted revision file: ' . $full_path );
				return true;
			}
			error_log( '[SMR] Failed to delete revision file: ' . $full_path );
			return false;
		}

		return true; // File doesn't exist, consider it deleted.
	}

	/**
	 * Delete all revision files for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return bool Whether all files were deleted.
	 */
	public static function delete_attachment_revisions( int $attachment_id ): bool {
		$dir = self::get_attachment_path( $attachment_id );

		if ( ! file_exists( $dir ) ) {
			return true;
		}

		// Initialize WordPress filesystem.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;

		$deleted = $wp_filesystem->rmdir( $dir, true );

		if ( $deleted ) {
			error_log( '[SMR] Deleted all revision files for attachment: ' . $attachment_id );
		} else {
			error_log( '[SMR] Failed to delete revision directory: ' . $dir );
		}

		return $deleted;
	}

	/**
	 * Delete all revision files for current blog.
	 *
	 * @return bool Whether all files were deleted.
	 */
	public static function delete_all_revisions(): bool {
		$base_dir = self::get_base_path();

		if ( ! file_exists( $base_dir ) ) {
			return true;
		}

		// Initialize WordPress filesystem.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;

		$deleted = $wp_filesystem->rmdir( $base_dir, true );

		if ( $deleted ) {
			error_log( '[SMR] Deleted all revision files from: ' . $base_dir );
		} else {
			error_log( '[SMR] Failed to delete base revision directory: ' . $base_dir );
		}

		return $deleted;
	}

	/**
	 * Get the full file path from a relative path.
	 *
	 * @param string $relative_path Relative path from database.
	 * @return string Full file path.
	 */
	public static function get_full_path( string $relative_path ): string {
		$upload_dir = wp_upload_dir();
		return trailingslashit( $upload_dir['basedir'] ) . $relative_path;
	}

	/**
	 * Check if a revision file exists.
	 *
	 * @param string $relative_path Relative path from database.
	 * @return bool Whether file exists.
	 */
	public static function file_exists( string $relative_path ): bool {
		return file_exists( self::get_full_path( $relative_path ) );
	}

	/**
	 * Get file size of a revision.
	 *
	 * @param string $relative_path Relative path from database.
	 * @return int File size in bytes.
	 */
	public static function get_file_size( string $relative_path ): int {
		$full_path = self::get_full_path( $relative_path );
		return file_exists( $full_path ) ? (int) filesize( $full_path ) : 0;
	}

	/**
	 * Calculate total storage used for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Total size in bytes.
	 */
	public static function calculate_attachment_storage( int $attachment_id ): int {
		$dir = self::get_attachment_path( $attachment_id );

		if ( ! file_exists( $dir ) ) {
			return 0;
		}

		$size  = 0;
		$files = glob( $dir . '/*' );

		if ( $files ) {
			foreach ( $files as $file ) {
				if ( is_file( $file ) && basename( $file ) !== 'index.php' ) {
					$size += filesize( $file );
				}
			}
		}

		return $size;
	}

	/**
	 * Calculate total storage used for all revisions.
	 *
	 * @return int Total size in bytes.
	 */
	public static function calculate_total_storage(): int {
		$base_dir = self::get_base_path();

		if ( ! file_exists( $base_dir ) ) {
			return 0;
		}

		$size = 0;
		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $base_dir, \RecursiveDirectoryIterator::SKIP_DOTS )
		);

		foreach ( $iter as $file ) {
			if ( $file->isFile() && $file->getFilename() !== 'index.php' ) {
				$size += $file->getSize();
			}
		}

		return $size;
	}

	/**
	 * Create a ZIP archive of all revisions for an attachment.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $revisions     Array of revision objects.
	 * @return string|false Path to ZIP file or false on failure.
	 */
	public static function create_zip( int $attachment_id, array $revisions ) {
		if ( ! class_exists( 'ZipArchive' ) ) {
			error_log( '[SMR] ZipArchive class not available' );
			return false;
		}

		$upload_dir = wp_upload_dir();
		$zip_name   = 'revisions-' . $attachment_id . '-' . time() . '.zip';
		$zip_path   = trailingslashit( $upload_dir['basedir'] ) . self::STORAGE_DIR . '/' . $zip_name;

		// Ensure base directory exists.
		$base_dir = self::get_base_path();
		if ( ! file_exists( $base_dir ) ) {
			wp_mkdir_p( $base_dir );
		}

		$zip = new \ZipArchive();
		if ( $zip->open( $zip_path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE ) !== true ) {
			error_log( '[SMR] Failed to create ZIP archive: ' . $zip_path );
			return false;
		}

		$files_added = 0;
		foreach ( $revisions as $revision ) {
			$full_path = self::get_full_path( $revision->file_path );
			if ( file_exists( $full_path ) ) {
				$zip_filename = $revision->version . '-' . basename( $full_path );
				$zip->addFile( $full_path, $zip_filename );
				++$files_added;
			}
		}

		$zip->close();

		if ( 0 === $files_added ) {
			wp_delete_file( $zip_path );
			error_log( '[SMR] No files added to ZIP archive' );
			return false;
		}

		error_log( '[SMR] Created ZIP archive: ' . $zip_path . ' (' . $files_added . ' files)' );
		return $zip_path;
	}

	/**
	 * Serve a file for download.
	 *
	 * @param string $file_path Full file path.
	 * @param string $filename  Filename for download.
	 * @return void
	 */
	public static function serve_download( string $file_path, string $filename ): void {
		if ( ! file_exists( $file_path ) ) {
			wp_die( esc_html__( 'File not found.', 'smart-media-replacement' ), '', array( 'response' => 404 ) );
		}

		$mime_type = wp_check_filetype( $filename );
		$mime      = $mime_type['type'] ? $mime_type['type'] : 'application/octet-stream';

		// Clean output buffer.
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Set headers.
		header( 'Content-Type: ' . $mime );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		header( 'Content-Length: ' . filesize( $file_path ) );
		header( 'Cache-Control: no-cache, must-revalidate' );
		header( 'Pragma: no-cache' );
		header( 'Expires: 0' );

		// Output file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_readfile
		readfile( $file_path );
		exit;
	}
}
