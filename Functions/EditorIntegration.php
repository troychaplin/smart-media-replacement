<?php
/**
 * Block Editor Integration
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
 * Class EditorIntegration
 *
 * Enqueues the block-editor JS that registers an "Update Existing File"
 * MenuItem inside core's MediaReplaceFlow component via the
 * `editor.MediaReplaceFlow` filter.
 */
class EditorIntegration {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Enqueue the editor integration script.
	 */
	public function enqueue_assets(): void {
		$asset_file = SMART_MEDIA_REPLACEMENT_PLUGIN_PATH . 'build/editor.asset.php';

		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = include $asset_file;

		wp_enqueue_script(
			'smr-editor',
			SMART_MEDIA_REPLACEMENT_PLUGIN_URL . 'build/editor.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// The editor JS reuses the existing AJAX endpoints, so it needs both
		// nonces (one for the replacement endpoint, one for revision queries).
		wp_localize_script(
			'smr-editor',
			'smrEditorData',
			array(
				'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
				'replaceNonce'    => wp_create_nonce( 'smart_media_replacement_nonce' ),
				'revisionNonce'   => wp_create_nonce( 'smr_revision_nonce' ),
				'enableRevisions' => (bool) Settings::get( 'smr_enable_revisions', true ),
				'fileTypes'       => (string) Settings::get( 'smr_revision_file_types', 'documents' ),
				'requireComment'  => (bool) Settings::get( 'smr_require_comment', false ),
				'defaultVersion'  => (string) Settings::get( 'smr_default_version_type', 'minor' ),
			)
		);

		// Set translations for the script.
		wp_set_script_translations( 'smr-editor', 'smart-media-replacement' );
	}
}
