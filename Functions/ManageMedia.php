<?php // phpcs:ignore Squiz.Commenting.FileComment.Missing

namespace Smart_Media_Replacement;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Class ManageMedia
 *
 * This class handles the media replacement functionality.
 *
 * @package Smart_Media_Replacement
 */
class ManageMedia {

	/**
	 * Constructor for the class.
	 */
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_smart_media_replacement_file', array( $this, 'smart_media_replacement_handler' ) );
		add_action( 'attachment_submitbox_misc_actions', array( $this, 'smart_media_replacement_submit_button' ), 20 );
		add_filter( 'media_row_actions', array( $this, 'smart_media_replacement_row_actions' ), 10, 2 );
	}

	/**
	 * Enqueue necessary scripts and styles.
	 */
	public function enqueue_scripts() {
		// Get the current screen.
		$screen = get_current_screen();

		// Check for all possible media contexts.
		$allowed_screens = array( 'upload', 'media', 'attachment' );
		if ( ! in_array( $screen->id, $allowed_screens, true ) && ! in_array( $screen->base, $allowed_screens, true ) ) {
			return;
		}

		// Read the generated manifest so the version is the build content hash
		// rather than a hardcoded string — a fixed version meant this bundle
		// stayed cached in browsers across every plugin release.
		$asset_file = SMART_MEDIA_REPLACEMENT_PLUGIN_PATH . 'build/smart-media-replacement.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}
		$asset = include $asset_file;

		// jquery and media-views aren't in the generated dependency list — the
		// source uses the wp.media global and jQuery-driven modal hooks rather
		// than imports — so they're merged in explicitly.
		$dependencies = array_unique(
			array_merge( $asset['dependencies'], array( 'jquery', 'media-views' ) )
		);

		// Enqueue the script.
		wp_enqueue_script(
			'smart-media-replacement-script',
			SMART_MEDIA_REPLACEMENT_PLUGIN_URL . 'build/smart-media-replacement.js',
			$dependencies,
			$asset['version'],
			true
		);

		wp_set_script_translations( 'smart-media-replacement-script', 'smart-media-replacement' );

		// Get max revisions setting.
		$max_revisions = (int) Settings::get( 'smr_max_revisions', 10 );

		// Localize the script with necessary data.
		wp_localize_script(
			'smart-media-replacement-script',
			'smartMediaReplacementData',
			array(
				'ajaxUrl'      => admin_url( 'admin-ajax.php' ),
				'nonce'        => wp_create_nonce( 'smart_media_replacement_nonce' ),
				'maxRevisions' => $max_revisions,
			)
		);
	}

	/**
	 * Add replace media button to attachment submit box.
	 *
	 * @param \WP_Post $post The attachment post object.
	 */
	public function smart_media_replacement_submit_button( $post ) {
		$revisions_enabled = Helpers::is_revision_enabled_for_attachment( $post->ID );

		if ( ! $revisions_enabled ) {
			?>
			<div class="misc-pub-section">
				<button type="button"
					class="button button-large smart-media-replacement-button"
					style="width: 100%; text-align: center;"
					data-attachment-id="<?php echo esc_attr( $post->ID ); ?>"
					data-revisions-enabled="0">
					<?php esc_html_e( 'Replace File', 'smart-media-replacement' ); ?>
				</button>
			</div>
			<?php
			return;
		}

		$version_data   = $this->get_version_data_for_button( $post->ID );
		$revision_count = RevisionDatabase::get_count( $post->ID );
		?>
		<div class="misc-pub-section">
			<div style="display: flex; gap: 4px;">
				<button type="button"
					class="button button-large smart-media-replacement-button"
					style="flex: 1; text-align: center;"
					data-attachment-id="<?php echo esc_attr( $post->ID ); ?>"
					data-revisions-enabled="1"
					data-revision-count="<?php echo esc_attr( $revision_count ); ?>"
					data-latest-version="<?php echo esc_attr( $version_data['latest'] ); ?>"
					data-next-minor="<?php echo esc_attr( $version_data['next_minor'] ); ?>"
					data-next-major="<?php echo esc_attr( $version_data['next_major'] ); ?>">
					<?php esc_html_e( 'Replace File', 'smart-media-replacement' ); ?>
				</button>
				<button type="button" class="button button-large smr-view-revisions-btn" style="flex: 1; text-align: center;" data-attachment-id="<?php echo esc_attr( $post->ID ); ?>">
					<?php esc_html_e( 'View Revisions', 'smart-media-replacement' ); ?>
					<?php if ( $revision_count > 0 ) : ?>
						<span class="smr-revision-count-badge">(<?php echo esc_html( $revision_count ); ?>)</span>
					<?php endif; ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Compute the version strings shown in the replace modal for an attachment.
	 *
	 * Returns the latest stored revision's version (or empty string when none
	 * exist yet) plus the "next minor" and "next major" version strings that
	 * would result from a replacement now. When no revisions exist both
	 * next-minor and next-major equal "1.0" — calculate_next_version() always
	 * produces 1.0 for the first revision regardless of type.
	 *
	 * @param int $attachment_id The attachment ID.
	 * @return array{latest:string,next_minor:string,next_major:string}
	 */
	private function get_version_data_for_button( int $attachment_id ): array {
		$latest = RevisionDatabase::get_latest( $attachment_id );

		if ( ! $latest ) {
			return array(
				'latest'     => '',
				'next_minor' => '1.0',
				'next_major' => '1.0',
			);
		}

		$major = (int) $latest->version_major;
		$minor = (int) $latest->version_minor;

		return array(
			'latest'     => (string) $latest->version,
			'next_minor' => $major . '.' . ( $minor + 1 ),
			'next_major' => ( $major + 1 ) . '.0',
		);
	}

	/**
	 * Add replace media link to row actions in Media Library list view.
	 *
	 * @param array    $actions An array of action links for each attachment.
	 * @param \WP_Post $post    The attachment post object.
	 * @return array Modified actions array.
	 */
	public function smart_media_replacement_row_actions( $actions, $post ) {
		if ( ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}

		$revisions_enabled = Helpers::is_revision_enabled_for_attachment( $post->ID );
		$revision_count    = 0;
		$version_data      = array(
			'latest'     => '',
			'next_minor' => '1.0',
			'next_major' => '1.0',
		);

		if ( $revisions_enabled ) {
			$revision_count = RevisionDatabase::get_count( $post->ID );
			$version_data   = $this->get_version_data_for_button( $post->ID );
		}

		$actions['smart_media_replacement'] = sprintf(
			'<a href="#" class="smart-media-replacement-link smart-media-replacement-button" data-attachment-id="%1$d" data-revisions-enabled="%2$d" data-revision-count="%3$d" data-latest-version="%4$s" data-next-minor="%5$s" data-next-major="%6$s">%7$s</a>',
			$post->ID,
			$revisions_enabled ? 1 : 0,
			$revision_count,
			esc_attr( $version_data['latest'] ),
			esc_attr( $version_data['next_minor'] ),
			esc_attr( $version_data['next_major'] ),
			esc_html__( 'Replace', 'smart-media-replacement' )
		);

		if ( $revisions_enabled ) {
			$label = __( 'Revisions', 'smart-media-replacement' );
			if ( $revision_count > 0 ) {
				$label .= ' (' . $revision_count . ')';
			}
			$actions['smr_view_revisions'] = sprintf(
				'<a href="#" class="smr-view-revisions-link smr-view-revisions-btn" data-attachment-id="%d">%s</a>',
				$post->ID,
				esc_html( $label )
			);
		}

		return $actions;
	}

	/**
	 * Handle the media replacement AJAX request.
	 */
	public function smart_media_replacement_handler() {
		// Verify nonce.
		if ( ! check_ajax_referer( 'smart_media_replacement_nonce', 'nonce', false ) ) {
			wp_send_json_error( __( 'Security check failed.', 'smart-media-replacement' ) );
		}

		$attachment_id = isset( $_POST['attachment_id'] ) ? intval( $_POST['attachment_id'] ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( __( 'Invalid attachment ID.', 'smart-media-replacement' ) );
		}

		if ( ! current_user_can( 'edit_post', $attachment_id ) ) {
			wp_send_json_error( __( 'You do not have permission to edit this attachment.', 'smart-media-replacement' ) );
		}

		if ( ! isset( $_FILES['replacement_file'] ) ) {
			wp_send_json_error( __( 'No file was uploaded.', 'smart-media-replacement' ) );
		}

		// Get revision data from request. version_type is constrained to a
		// known enum so a malformed input always falls back to 'minor' rather
		// than reaching calculate_next_version() with garbage.
		$raw_version_type = isset( $_POST['version_type'] ) ? sanitize_text_field( wp_unslash( $_POST['version_type'] ) ) : Settings::get( 'smr_default_version_type', 'minor' );
		$version_type     = in_array( $raw_version_type, array( 'major', 'minor' ), true ) ? $raw_version_type : 'minor';
		$comment          = isset( $_POST['comment'] ) ? sanitize_textarea_field( wp_unslash( $_POST['comment'] ) ) : '';

		// Comment is only required when a revision will actually be created —
		// is_revision_enabled_for_attachment() already accounts for both the
		// global toggle and the per-file-type filter, so this matches the JS
		// logic that decides whether to show the comment field at all.
		$require_comment = Helpers::is_revision_enabled_for_attachment( $attachment_id )
			&& Settings::get( 'smr_require_comment', false );
		if ( $require_comment && empty( $comment ) ) {
			wp_send_json_error( __( 'A comment is required when replacing files.', 'smart-media-replacement' ) );
		}

		// Validate and sanitize file upload components.
		$file_name  = isset( $_FILES['replacement_file']['name'] ) ? sanitize_file_name( $_FILES['replacement_file']['name'] ) : '';
		$file_type  = isset( $_FILES['replacement_file']['type'] ) ? sanitize_mime_type( $_FILES['replacement_file']['type'] ) : '';
		$file_size  = isset( $_FILES['replacement_file']['size'] ) ? absint( $_FILES['replacement_file']['size'] ) : 0;
		$file_error = isset( $_FILES['replacement_file']['error'] ) ? absint( $_FILES['replacement_file']['error'] ) : 0;

		// Validate tmp_name exists and is a proper upload (tmp_name is system-generated, not user input).
		$file_tmp_name = '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is system-generated, not user input
		if ( isset( $_FILES['replacement_file']['tmp_name'] ) && is_uploaded_file( $_FILES['replacement_file']['tmp_name'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- tmp_name is system-generated, not user input
			$file_tmp_name = $_FILES['replacement_file']['tmp_name'];
		} else {
			wp_send_json_error( __( 'Invalid file upload.', 'smart-media-replacement' ) );
		}

		// Create sanitized file array.
		$file = array(
			'name'     => $file_name,
			'tmp_name' => $file_tmp_name,
			'type'     => $file_type,
			'size'     => $file_size,
			'error'    => $file_error,
		);

		// Check for upload errors.
		if ( UPLOAD_ERR_OK !== $file['error'] ) {
			wp_send_json_error( __( 'File upload error.', 'smart-media-replacement' ) );
		}

		$attachment = \get_post( $attachment_id );

		if ( ! $attachment ) {
			wp_send_json_error( __( 'Attachment not found.', 'smart-media-replacement' ) );
		}

		// Handle the file upload.
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		try {
			// Get the current file path and directory.
			$current_file     = get_attached_file( $attachment_id );
			$current_dir      = dirname( $current_file );
			$current_filename = basename( $current_file );

			// Extract original filename (handle scaled images).
			$original_filename = Helpers::get_original_filename( $current_filename );
			$is_scaled_image   = $original_filename !== $current_filename;

			// Validate that the new file has the correct name and MIME type.
			$new_filename = basename( $file['name'] );
			$current_mime = get_post_mime_type( $attachment_id );
			$new_mime     = wp_check_filetype( $file['name'] );

			$filename_matches = $new_filename === $original_filename;
			$mime_matches     = $current_mime === $new_mime['type'];

			// Check file type first (more critical), then filename.
			if ( ! $mime_matches ) {
				// MIME type is wrong - show simplified file type error.
				wp_send_json_error(
					sprintf(
						/* translators: 1: required mime type, 2: uploaded mime type */
						__( 'The replacement file must use the exact same file type. Required type: %1$s, Uploaded type: %2$s', 'smart-media-replacement' ),
						$current_mime,
						$new_mime['type']
					)
				);
			} elseif ( ! $filename_matches ) {
				// Only filename is wrong.
				if ( $is_scaled_image ) {
					wp_send_json_error(
						sprintf(
							/* translators: 1: The original filename without -scaled, 2: The current scaled filename */
							__( 'This image was automatically scaled by WordPress. Please upload your replacement file with the original filename: %1$s (not %2$s)', 'smart-media-replacement' ),
							$original_filename,
							$current_filename
						)
					);
				} else {
					wp_send_json_error(
						sprintf(
							/* translators: %s: The original filename that must be matched. */
							__( 'The new file must have the same name as the original file (%s). Please rename your file and try again.', 'smart-media-replacement' ),
							$original_filename
						)
					);
				}
			}

			// Validate dimensions for images.
			$current_image_info = file_exists( $current_file ) ? getimagesize( $current_file ) : false;
			if ( $current_image_info ) {
				// This is an image, check dimensions.
				$new_image_info = getimagesize( $file['tmp_name'] );
				if ( ! $new_image_info ) {
					wp_send_json_error( __( 'The uploaded file is not a valid image.', 'smart-media-replacement' ) );
				}

				$current_width  = $current_image_info[0];
				$current_height = $current_image_info[1];
				$new_width      = $new_image_info[0];
				$new_height     = $new_image_info[1];

				// Check if current image is scaled and try to get original dimensions.
				$comparison_width  = $current_width;
				$comparison_height = $current_height;

				if ( $is_scaled_image ) {
					// Try to get dimensions from the original file.
					$original_file_path = path_join( $current_dir, $original_filename );

					if ( file_exists( $original_file_path ) ) {
						$original_image_info = getimagesize( $original_file_path );
						if ( $original_image_info ) {
							$comparison_width  = $original_image_info[0];
							$comparison_height = $original_image_info[1];
						}
					}
				}

				// Enforce strict dimension matching for all images to prevent layout issues.
				$enforce_dimensions = apply_filters( 'smart_media_replacement_enforce_dimensions', true, $attachment_id );

				if ( $enforce_dimensions && ( $new_width !== $comparison_width || $new_height !== $comparison_height ) ) {
					wp_send_json_error(
						sprintf(
							/* translators: 1: required dimensions, 2: uploaded dimensions */
							__( 'The replacement must have the exact same dimensions as the original image. Required: %1$s, Uploaded: %2$s.', 'smart-media-replacement' ),
							"{$comparison_width}x{$comparison_height}",
							"{$new_width}x{$new_height}"
						)
					);
				}
			}

			/**
			 * Fires once a replacement has passed all validation, just before
			 * the new file is moved into place. Listeners (notably the revision
			 * system) use this to snapshot the current file. Fired after
			 * validation so failed uploads do not leave junk revisions behind.
			 *
			 * @param int   $attachment_id    The attachment ID.
			 * @param array $replacement_data Version type and comment data.
			 */
			do_action(
				'smart_media_replacement_before_replace',
				$attachment_id,
				array(
					'version_type' => $version_type,
					'comment'      => $comment,
				)
			);

			// Move the uploaded file into place first. If anything after this fails,
			// the user keeps their new file rather than losing both old and new.
			$target_path = path_join( $current_dir, $original_filename );

			global $wp_filesystem;
			if ( ! WP_Filesystem() ) {
				wp_send_json_error( __( 'Could not access the filesystem to complete the replacement.', 'smart-media-replacement' ) );
			}

			// $overwrite = true: $target_path is the file being replaced, so it
			// already exists on disk — WP_Filesystem::move() refuses to overwrite
			// by default. The upload's authenticity was already confirmed via
			// is_uploaded_file() above, so this doesn't weaken that check.
			if ( ! $wp_filesystem->move( $file['tmp_name'], $target_path, true ) ) {
				wp_send_json_error( __( 'Failed to move uploaded file.', 'smart-media-replacement' ) );
			}

			// Clean up old files. The new file at $target_path is preserved.
			Helpers::delete_attachment_files( $attachment_id, $target_path );

			// Update the attachment metadata.
			$attachment_data = wp_generate_attachment_metadata( $attachment_id, $target_path );

			wp_update_attachment_metadata( $attachment_id, $attachment_data );

			// Check if WordPress created a new scaled version and update the attachment file path accordingly.
			$final_file_path = $target_path;

			if ( ! empty( $attachment_data['original_image'] ) ) {
				// When WordPress creates a scaled image, the metadata structure is:
				// - 'file' already points to the scaled version
				// - 'big_image' contains info about the original.
				$scaled_relative_path = $attachment_data['file'];
				$upload_dir           = wp_get_upload_dir();
				$scaled_full_path     = path_join( $upload_dir['basedir'], $scaled_relative_path );

				if ( file_exists( $scaled_full_path ) ) {
					$final_file_path = $scaled_full_path;
				}
			}

			// Update the attachment's file path in the database.
			update_attached_file( $attachment_id, $final_file_path );

			// Allow developers to hook into the replacement process.
			do_action( 'smart_media_replacement_file_replaced', $attachment_id, $final_file_path );

			wp_send_json_success(
				array(
					'message' => __( 'File replaced successfully.', 'smart-media-replacement' ),
					'url'     => wp_get_attachment_url( $attachment_id ),
				)
			);
		} catch ( \Exception $e ) {
			wp_send_json_error( $e->getMessage() );
		}
	}
}
