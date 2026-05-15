<?php
/**
 * Revision UI
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
 * Class RevisionUI
 *
 * Handles the admin UI for revision history and management.
 */
class RevisionUI {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Enqueue admin scripts and styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Enqueue scripts and styles for revision UI.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		$screen = get_current_screen();

		// Only load on media screens.
		$allowed_screens = array( 'upload', 'media', 'attachment' );
		if ( ! in_array( $screen->id, $allowed_screens, true ) && ! in_array( $screen->base, $allowed_screens, true ) ) {
			return;
		}

		// Enqueue revision UI styles.
		wp_enqueue_style(
			'smr-revision-ui',
			SMART_MEDIA_REPLACEMENT_PLUGIN_URL . 'build/revision-ui.css',
			array(),
			SMART_MEDIA_REPLACEMENT_VERSION
		);

		// Get upload directory info for image URLs.
		$upload_dir = wp_upload_dir();

		// Localize script data for revision operations.
		wp_localize_script(
			'smart-media-replacement-script',
			'smrRevisionData',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'nonce'           => wp_create_nonce( 'smr_revision_nonce' ),
				'downloadNonce'   => wp_create_nonce( 'smr_download_nonce' ),
				'enableRevisions' => (bool) Settings::get( 'smr_enable_revisions', true ),
				'requireComment'  => (bool) Settings::get( 'smr_require_comment', false ),
				'defaultVersion'  => Settings::get( 'smr_default_version_type', 'minor' ),
				'strings'         => array(
					'confirmRestore'   => __( 'Are you sure you want to restore this revision? The current file will be saved as a new revision.', 'smart-media-replacement' ),
					'restoreSuccess'   => __( 'Revision restored successfully. Refreshing...', 'smart-media-replacement' ),
					'downloadError'    => __( 'Failed to download file.', 'smart-media-replacement' ),
					'loadingRevisions' => __( 'Loading revisions...', 'smart-media-replacement' ),
					'noRevisions'      => __( 'No revisions yet. Revisions are created when you replace the file.', 'smart-media-replacement' ),
					'commentRequired'  => __( 'Please enter a comment describing the changes.', 'smart-media-replacement' ),
					'versionMajor'     => __( 'Major', 'smart-media-replacement' ),
					'versionMinor'     => __( 'Minor', 'smart-media-replacement' ),
				),
			)
		);

		// Add upload URL for image previews.
		wp_add_inline_script(
			'smart-media-replacement-script',
			'window.smrUploadUrl = ' . wp_json_encode( trailingslashit( $upload_dir['baseurl'] ) ) . ';',
			'before'
		);
	}
}
