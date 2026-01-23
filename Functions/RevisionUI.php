<?php
/**
 * Revision UI
 *
 * @package Smart_Media_Replacement
 */

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
		// Add revision history to attachment edit screen.
		add_action( 'edit_attachment', array( $this, 'save_attachment_meta' ) );
		add_filter( 'attachment_fields_to_edit', array( $this, 'add_revision_history_field' ), 10, 2 );

		// Enqueue admin scripts and styles.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Add metabox to attachment edit page.
		add_action( 'add_meta_boxes_attachment', array( $this, 'add_revision_metabox' ) );
	}

	/**
	 * Enqueue scripts and styles for revision UI.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( string $hook ): void {
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
				'requireComment'  => (bool) get_option( 'smr_require_comment', false ),
				'defaultVersion'  => get_option( 'smr_default_version_type', 'minor' ),
				'strings'         => array(
					'confirmRestore'  => __( 'Are you sure you want to restore this revision? The current file will be saved as a new revision.', 'smart-media-replacement' ),
					'restoreSuccess'  => __( 'Revision restored successfully. Refreshing...', 'smart-media-replacement' ),
					'downloadError'   => __( 'Failed to download file.', 'smart-media-replacement' ),
					'loadingRevisions' => __( 'Loading revisions...', 'smart-media-replacement' ),
					'noRevisions'     => __( 'No revisions yet. Revisions are created when you replace the file.', 'smart-media-replacement' ),
					'commentRequired' => __( 'Please enter a comment describing the changes.', 'smart-media-replacement' ),
					'versionMajor'    => __( 'Major', 'smart-media-replacement' ),
					'versionMinor'    => __( 'Minor', 'smart-media-replacement' ),
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

	/**
	 * Add revision metabox to attachment edit page.
	 *
	 * @param \WP_Post $post The attachment post object.
	 */
	public function add_revision_metabox( $post ): void {
		add_meta_box(
			'smr-revision-history',
			__( 'Revision History', 'smart-media-replacement' ),
			array( $this, 'render_revision_metabox' ),
			'attachment',
			'normal',
			'default'
		);
	}

	/**
	 * Render the revision history metabox.
	 *
	 * @param \WP_Post $post The attachment post object.
	 */
	public function render_revision_metabox( $post ): void {
		$attachment_id = $post->ID;
		$revisions     = RevisionDatabase::get_by_attachment( $attachment_id );
		$total_storage = RevisionDatabase::get_attachment_storage( $attachment_id );
		$is_image      = wp_attachment_is_image( $attachment_id );

		// Get current version.
		$revision_manager = new RevisionManager();
		$current_version  = $revision_manager->get_current_version( $attachment_id );

		error_log( '[SMR] Rendering revision metabox: attachment=' . $attachment_id . ', revisions=' . count( $revisions ) );
		?>
		<div class="smr-revision-history" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
			<div class="smr-revision-header">
				<div class="smr-revision-info">
					<span class="smr-current-version">
						<?php
						/* translators: %s: version number */
						printf( esc_html__( 'Current Version: %s', 'smart-media-replacement' ), esc_html( $current_version ) );
						?>
					</span>
					<?php if ( ! empty( $revisions ) ) : ?>
						<span class="smr-storage-info">
							<?php
							/* translators: 1: storage size, 2: revision count */
							printf(
								esc_html__( 'Total Storage: %1$s (%2$d revisions)', 'smart-media-replacement' ),
								esc_html( size_format( $total_storage ) ),
								count( $revisions )
							);
							?>
						</span>
					<?php endif; ?>
				</div>
				<?php if ( ! empty( $revisions ) ) : ?>
					<div class="smr-revision-actions">
						<?php if ( $is_image && count( $revisions ) >= 1 ) : ?>
							<button type="button" class="button smr-compare-btn" data-attachment-id="<?php echo esc_attr( $attachment_id ); ?>">
								<?php esc_html_e( 'Compare Versions', 'smart-media-replacement' ); ?>
							</button>
						<?php endif; ?>
						<a href="<?php echo esc_url( $this->get_download_all_url( $attachment_id ) ); ?>" class="button">
							<?php esc_html_e( 'Download All', 'smart-media-replacement' ); ?>
						</a>
					</div>
				<?php endif; ?>
			</div>

			<?php if ( empty( $revisions ) ) : ?>
				<p class="smr-no-revisions">
					<?php esc_html_e( 'No revisions yet. Revisions are created when you replace the file.', 'smart-media-replacement' ); ?>
				</p>
			<?php else : ?>
				<div class="smr-revision-list">
					<?php foreach ( $revisions as $index => $revision ) : ?>
						<?php $this->render_revision_item( $revision, $index === 0, $is_image ); ?>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>

		<?php if ( $is_image && ! empty( $revisions ) ) : ?>
			<?php $this->render_comparison_modal( $attachment_id, $revisions ); ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render a single revision item.
	 *
	 * @param object $revision  Revision object.
	 * @param bool   $is_latest Whether this is the latest revision.
	 * @param bool   $is_image  Whether the attachment is an image.
	 */
	private function render_revision_item( $revision, bool $is_latest, bool $is_image ): void {
		$user      = get_user_by( 'id', $revision->user_id );
		$user_name = $user ? $user->display_name : __( 'Unknown', 'smart-media-replacement' );
		$date      = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $revision->created_at ) );
		?>
		<div class="smr-revision-item <?php echo $is_latest ? 'smr-latest' : ''; ?>" data-revision-id="<?php echo esc_attr( $revision->id ); ?>">
			<div class="smr-revision-main">
				<div class="smr-revision-version">
					<strong>v<?php echo esc_html( $revision->version ); ?></strong>
					<?php if ( $is_latest ) : ?>
						<span class="smr-badge"><?php esc_html_e( 'Latest', 'smart-media-replacement' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="smr-revision-meta">
					<span class="smr-revision-date"><?php echo esc_html( $date ); ?></span>
					<span class="smr-revision-user"><?php echo esc_html( $user_name ); ?></span>
					<span class="smr-revision-size"><?php echo esc_html( size_format( $revision->file_size ) ); ?></span>
				</div>
				<?php if ( ! empty( $revision->comment ) ) : ?>
					<div class="smr-revision-comment">
						"<?php echo esc_html( $revision->comment ); ?>"
					</div>
				<?php endif; ?>
			</div>
			<div class="smr-revision-buttons">
				<?php if ( $is_image ) : ?>
					<button type="button" class="button smr-preview-btn" data-revision-id="<?php echo esc_attr( $revision->id ); ?>" data-file-path="<?php echo esc_attr( $revision->file_path ); ?>">
						<?php esc_html_e( 'Preview', 'smart-media-replacement' ); ?>
					</button>
				<?php endif; ?>
				<a href="<?php echo esc_url( $this->get_download_url( $revision->id ) ); ?>" class="button">
					<?php esc_html_e( 'Download', 'smart-media-replacement' ); ?>
				</a>
				<button type="button" class="button smr-restore-btn" data-revision-id="<?php echo esc_attr( $revision->id ); ?>" data-version="<?php echo esc_attr( $revision->version ); ?>">
					<?php esc_html_e( 'Restore', 'smart-media-replacement' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the image comparison modal.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $revisions     Array of revisions.
	 */
	private function render_comparison_modal( int $attachment_id, array $revisions ): void {
		?>
		<div id="smr-comparison-modal" class="smr-modal" style="display: none;">
			<div class="smr-modal-content">
				<div class="smr-modal-header">
					<h2><?php esc_html_e( 'Compare Versions', 'smart-media-replacement' ); ?></h2>
					<button type="button" class="smr-modal-close">&times;</button>
				</div>
				<div class="smr-modal-body">
					<div class="smr-comparison-controls">
						<div class="smr-comparison-select">
							<label for="smr-compare-left"><?php esc_html_e( 'Left:', 'smart-media-replacement' ); ?></label>
							<select id="smr-compare-left">
								<?php foreach ( $revisions as $revision ) : ?>
									<option value="<?php echo esc_attr( $revision->id ); ?>" data-file-path="<?php echo esc_attr( $revision->file_path ); ?>">
										v<?php echo esc_html( $revision->version ); ?>
									</option>
								<?php endforeach; ?>
								<option value="current" data-file-path="<?php echo esc_attr( wp_get_attachment_url( $attachment_id ) ); ?>">
									<?php esc_html_e( 'Current', 'smart-media-replacement' ); ?>
								</option>
							</select>
						</div>
						<div class="smr-comparison-select">
							<label for="smr-compare-right"><?php esc_html_e( 'Right:', 'smart-media-replacement' ); ?></label>
							<select id="smr-compare-right">
								<?php foreach ( $revisions as $revision ) : ?>
									<option value="<?php echo esc_attr( $revision->id ); ?>" data-file-path="<?php echo esc_attr( $revision->file_path ); ?>">
										v<?php echo esc_html( $revision->version ); ?>
									</option>
								<?php endforeach; ?>
								<option value="current" data-file-path="<?php echo esc_attr( wp_get_attachment_url( $attachment_id ) ); ?>" selected>
									<?php esc_html_e( 'Current', 'smart-media-replacement' ); ?>
								</option>
							</select>
						</div>
					</div>
					<div class="smr-comparison-images">
						<div class="smr-comparison-left">
							<img src="" alt="<?php esc_attr_e( 'Left comparison image', 'smart-media-replacement' ); ?>" />
							<span class="smr-comparison-label"></span>
						</div>
						<div class="smr-comparison-right">
							<img src="" alt="<?php esc_attr_e( 'Right comparison image', 'smart-media-replacement' ); ?>" />
							<span class="smr-comparison-label"></span>
						</div>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Add revision history field to attachment edit form.
	 *
	 * @param array    $form_fields Existing form fields.
	 * @param \WP_Post $post        The attachment post object.
	 * @return array Modified form fields.
	 */
	public function add_revision_history_field( array $form_fields, $post ): array {
		// This is used in the media modal - we'll add a simplified view.
		$revisions = RevisionDatabase::get_by_attachment( $post->ID );
		$count     = count( $revisions );

		if ( $count > 0 ) {
			$latest = $revisions[0];
			$html   = sprintf(
				'<p>%s</p><p><a href="%s" target="_blank">%s</a></p>',
				/* translators: 1: revision count, 2: latest version */
				sprintf(
					esc_html__( '%1$d revisions available. Latest: v%2$s', 'smart-media-replacement' ),
					$count,
					esc_html( $latest->version )
				),
				esc_url( get_edit_post_link( $post->ID ) . '#smr-revision-history' ),
				esc_html__( 'View full revision history →', 'smart-media-replacement' )
			);
		} else {
			$html = '<p>' . esc_html__( 'No revisions yet.', 'smart-media-replacement' ) . '</p>';
		}

		$form_fields['smr_revisions'] = array(
			'label' => __( 'Revisions', 'smart-media-replacement' ),
			'input' => 'html',
			'html'  => $html,
		);

		return $form_fields;
	}

	/**
	 * Save attachment meta (placeholder for future use).
	 *
	 * @param int $attachment_id Attachment ID.
	 */
	public function save_attachment_meta( int $attachment_id ): void {
		// Placeholder for future meta saving if needed.
	}

	/**
	 * Get download URL for a single revision.
	 *
	 * @param int $revision_id Revision ID.
	 * @return string Download URL.
	 */
	private function get_download_url( int $revision_id ): string {
		return add_query_arg(
			array(
				'action'      => 'smr_download_revision',
				'revision_id' => $revision_id,
				'nonce'       => wp_create_nonce( 'smr_download_nonce' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}

	/**
	 * Get download URL for all revisions as ZIP.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string Download URL.
	 */
	private function get_download_all_url( int $attachment_id ): string {
		return add_query_arg(
			array(
				'action'        => 'smr_download_all_revisions',
				'attachment_id' => $attachment_id,
				'nonce'         => wp_create_nonce( 'smr_download_nonce' ),
			),
			admin_url( 'admin-ajax.php' )
		);
	}
}
