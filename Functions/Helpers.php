<?php
/**
 * Shared Helpers
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
 * Class Helpers
 *
 * Shared static helpers used across the replacement and revision flows.
 */
class Helpers {

	/**
	 * Strip the "-scaled" suffix WordPress adds to large originals.
	 *
	 * @param string $filename The filename to process.
	 * @return string The original filename without the -scaled suffix.
	 */
	public static function get_original_filename( string $filename ): string {
		if ( preg_match( '/^(.+)-scaled(\.[^.]+)$/', $filename, $matches ) ) {
			return $matches[1] . $matches[2];
		}
		return $filename;
	}

	/**
	 * Whether revisions are enabled for a given attachment.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return bool Whether revisions are enabled.
	 */
	public static function is_revision_enabled_for_attachment( int $attachment_id ): bool {
		if ( ! get_option( 'smr_enable_revisions', true ) ) {
			return false;
		}

		$file_type_setting = get_option( 'smr_revision_file_types', 'documents' );

		if ( 'all' === $file_type_setting ) {
			return true;
		}

		$is_image = wp_attachment_is_image( $attachment_id );

		if ( 'images' === $file_type_setting ) {
			return $is_image;
		}

		// Default 'documents' — enable for non-images (PDFs, docs, etc.).
		return ! $is_image;
	}

	/**
	 * Delete files associated with an attachment, skipping any path that
	 * matches the new target so we never delete the freshly placed file.
	 *
	 * Caller is responsible for placing the new file at $target_path *before*
	 * calling this — that ordering is what makes the operation crash-safe.
	 *
	 * @param int    $attachment_id The attachment ID.
	 * @param string $target_path   The path of the new file (will be skipped).
	 */
	public static function delete_attachment_files( int $attachment_id, string $target_path ): void {
		$current_file = get_attached_file( $attachment_id );
		if ( ! $current_file ) {
			return;
		}

		$current_dir       = dirname( $current_file );
		$current_filename  = basename( $current_file );
		$original_filename = self::get_original_filename( $current_filename );
		$is_scaled         = $original_filename !== $current_filename;
		$meta              = wp_get_attachment_metadata( $attachment_id );

		// Delete the main current file unless it shares a path with the new file.
		if ( $current_file !== $target_path && file_exists( $current_file ) ) {
			wp_delete_file( $current_file );
		}

		// For scaled images, also delete the unscaled original — but the target
		// path *is* the unscaled-original path, so the check below is what
		// prevents us from nuking the file we just placed.
		if ( $is_scaled ) {
			$original_file_path = path_join( $current_dir, $original_filename );
			if ( $original_file_path !== $target_path && file_exists( $original_file_path ) ) {
				wp_delete_file( $original_file_path );
			}
		}

		// Delete every generated size that isn't the new target.
		if ( ! empty( $meta['sizes'] ) ) {
			foreach ( $meta['sizes'] as $size_info ) {
				$size_file = path_join( $current_dir, $size_info['file'] );
				if ( $size_file !== $target_path && file_exists( $size_file ) ) {
					wp_delete_file( $size_file );
				}
			}
		}
	}
}
