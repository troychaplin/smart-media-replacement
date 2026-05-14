<?php
/**
 * Settings Page
 *
 * @package Smart_Media_Replacement
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName
// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

namespace Smart_Media_Replacement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class SettingsPage
 *
 * Handles plugin settings registration and display.
 */
class SettingsPage {

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
	}

	/**
	 * Add settings page to admin menu.
	 */
	public function add_settings_page(): void {
		add_submenu_page(
			'upload.php',
			__( 'Media Replacement Settings', 'smart-media-replacement' ),
			__( 'Replacement Settings', 'smart-media-replacement' ),
			'manage_options',
			'smr-settings',
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Register plugin settings.
	 */
	public function register_settings(): void {
		// Register settings.
		register_setting(
			'smr_settings',
			'smr_enable_revisions',
			array(
				'type'              => 'boolean',
				'default'           => true,
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);
		register_setting( 'smr_settings', 'smr_max_revisions', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'smr_settings', 'smr_retention_days', array( 'sanitize_callback' => 'absint' ) );
		register_setting( 'smr_settings', 'smr_default_version_type', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting(
			'smr_settings',
			'smr_require_comment',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);
		register_setting( 'smr_settings', 'smr_revision_file_types', array( 'sanitize_callback' => 'sanitize_text_field' ) );
		register_setting(
			'smr_settings',
			'smr_delete_files_on_deactivate',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);
		register_setting(
			'smr_settings',
			'smr_delete_data_on_deactivate',
			array(
				'type'              => 'boolean',
				'default'           => false,
				'sanitize_callback' => array( $this, 'sanitize_checkbox' ),
			)
		);

		// Revision Settings Section.
		add_settings_section(
			'smr_revision_settings',
			__( 'Revision Settings', 'smart-media-replacement' ),
			array( $this, 'render_revision_section' ),
			'smr-settings'
		);

		add_settings_field(
			'smr_enable_revisions',
			__( 'Enable Revisions', 'smart-media-replacement' ),
			array( $this, 'render_enable_revisions_field' ),
			'smr-settings',
			'smr_revision_settings'
		);

		add_settings_field(
			'smr_revision_file_types',
			__( 'Enable Revisions For', 'smart-media-replacement' ),
			array( $this, 'render_revision_file_types_field' ),
			'smr-settings',
			'smr_revision_settings'
		);

		add_settings_field(
			'smr_max_revisions',
			__( 'Maximum Revisions', 'smart-media-replacement' ),
			array( $this, 'render_max_revisions_field' ),
			'smr-settings',
			'smr_revision_settings'
		);

		add_settings_field(
			'smr_retention_days',
			__( 'Retention Period', 'smart-media-replacement' ),
			array( $this, 'render_retention_days_field' ),
			'smr-settings',
			'smr_revision_settings'
		);

		add_settings_field(
			'smr_default_version_type',
			__( 'Default Version Type', 'smart-media-replacement' ),
			array( $this, 'render_default_version_type_field' ),
			'smr-settings',
			'smr_revision_settings'
		);

		add_settings_field(
			'smr_require_comment',
			__( 'Require Comment', 'smart-media-replacement' ),
			array( $this, 'render_require_comment_field' ),
			'smr-settings',
			'smr_revision_settings'
		);

		// Cleanup Settings Section.
		add_settings_section(
			'smr_cleanup_settings',
			__( 'Cleanup Settings', 'smart-media-replacement' ),
			array( $this, 'render_cleanup_section' ),
			'smr-settings'
		);

		add_settings_field(
			'smr_delete_files_on_deactivate',
			__( 'Delete Files on Deactivation', 'smart-media-replacement' ),
			array( $this, 'render_delete_files_field' ),
			'smr-settings',
			'smr_cleanup_settings'
		);

		add_settings_field(
			'smr_delete_data_on_deactivate',
			__( 'Delete Database on Deactivation', 'smart-media-replacement' ),
			array( $this, 'render_delete_data_field' ),
			'smr-settings',
			'smr_cleanup_settings'
		);

		// Storage Info Section.
		add_settings_section(
			'smr_storage_info',
			__( 'Storage Information', 'smart-media-replacement' ),
			array( $this, 'render_storage_section' ),
			'smr-settings'
		);
	}

	/**
	 * Sanitize checkbox value.
	 *
	 * @param mixed $value The value to sanitize.
	 * @return bool
	 */
	public function sanitize_checkbox( $value ): bool {
		return (bool) $value;
	}

	/**
	 * Render settings page.
	 */
	public function render_settings_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Show success message if settings were saved.
		if ( isset( $_GET['settings-updated'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			add_settings_error( 'smr_messages', 'smr_message', __( 'Settings saved.', 'smart-media-replacement' ), 'updated' );
		}

		settings_errors( 'smr_messages' );
		?>
		<div class="wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
			<form action="options.php" method="post">
				<?php
				settings_fields( 'smr_settings' );
				do_settings_sections( 'smr-settings' );
				submit_button( __( 'Save Settings', 'smart-media-replacement' ) );
				?>
			</form>
		</div>
		<script>
		(function() {
			var enableCheckbox = document.querySelector('input[name="smr_enable_revisions"][type="checkbox"]');
			if (!enableCheckbox) return;

			var dependentFields = [
				'smr_revision_file_types',
				'smr_max_revisions',
				'smr_retention_days',
				'smr_default_version_type',
				'smr_require_comment'
			];

			function toggleFields() {
				var isEnabled = enableCheckbox.checked;
				dependentFields.forEach(function(fieldName) {
					var field = document.querySelector('[name="' + fieldName + '"]');
					if (field) {
						var row = field.closest('tr');
						if (row) {
							row.style.opacity = isEnabled ? '1' : '0.5';
							row.style.pointerEvents = isEnabled ? 'auto' : 'none';
						}
					}
				});
			}

			enableCheckbox.addEventListener('change', toggleFields);
			toggleFields();
		})();
		</script>
		<?php
	}

	/**
	 * Render revision settings section description.
	 */
	public function render_revision_section(): void {
		echo '<p>' . esc_html__( 'Configure how revisions are stored and managed.', 'smart-media-replacement' ) . '</p>';
	}

	/**
	 * Render cleanup settings section description.
	 */
	public function render_cleanup_section(): void {
		echo '<p>' . esc_html__( 'Control what happens when the plugin is deactivated.', 'smart-media-replacement' ) . '</p>';
		echo '<p class="description" style="color: #d63638;">' . esc_html__( 'Warning: These settings will permanently delete data. Use with caution.', 'smart-media-replacement' ) . '</p>';
	}

	/**
	 * Render storage information section.
	 */
	public function render_storage_section(): void {
		$total_storage  = RevisionDatabase::get_total_storage();
		$revision_count = $this->get_total_revision_count();
		$table_exists   = RevisionDatabase::table_exists();

		echo '<table class="form-table" role="presentation">';
		echo '<tbody>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Database Status', 'smart-media-replacement' ) . '</th>';
		echo '<td>';
		if ( $table_exists ) {
			echo '<span style="color: #00a32a;">&#10003; ' . esc_html__( 'Table exists', 'smart-media-replacement' ) . '</span>';
		} else {
			echo '<span style="color: #d63638;">&#10007; ' . esc_html__( 'Table not found', 'smart-media-replacement' ) . '</span>';
		}
		echo '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Total Revisions', 'smart-media-replacement' ) . '</th>';
		echo '<td>' . esc_html( number_format_i18n( $revision_count ) ) . '</td>';
		echo '</tr>';

		echo '<tr>';
		echo '<th scope="row">' . esc_html__( 'Total Storage Used', 'smart-media-replacement' ) . '</th>';
		echo '<td>' . esc_html( size_format( $total_storage ) ) . '</td>';
		echo '</tr>';

		echo '</tbody>';
		echo '</table>';
	}

	/**
	 * Render enable revisions field.
	 */
	public function render_enable_revisions_field(): void {
		$value = get_option( 'smr_enable_revisions', true );
		?>
		<input type="hidden" name="smr_enable_revisions" value="0">
		<label>
			<input type="checkbox" name="smr_enable_revisions" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Enable revision tracking for media files', 'smart-media-replacement' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'When disabled, replacing files will not create revision history. Existing revisions will be preserved.', 'smart-media-replacement' ); ?></p>
		<?php
	}

	/**
	 * Render revision file types field.
	 */
	public function render_revision_file_types_field(): void {
		$value = get_option( 'smr_revision_file_types', 'documents' );
		?>
		<select name="smr_revision_file_types">
			<option value="documents" <?php selected( $value, 'documents' ); ?>><?php esc_html_e( 'Documents Only (PDFs, Word, Excel, etc.)', 'smart-media-replacement' ); ?></option>
			<option value="images" <?php selected( $value, 'images' ); ?>><?php esc_html_e( 'Images Only (JPEG, PNG, GIF, etc.)', 'smart-media-replacement' ); ?></option>
			<option value="all" <?php selected( $value, 'all' ); ?>><?php esc_html_e( 'All File Types', 'smart-media-replacement' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Choose which file types should have revision tracking enabled.', 'smart-media-replacement' ); ?></p>
		<?php
	}

	/**
	 * Render max revisions field.
	 */
	public function render_max_revisions_field(): void {
		$value = get_option( 'smr_max_revisions', 10 );
		?>
		<input type="number" name="smr_max_revisions" value="<?php echo esc_attr( $value ); ?>" min="0" max="100" class="small-text">
		<p class="description"><?php esc_html_e( 'Maximum number of revisions to keep per file. Set to 0 for unlimited.', 'smart-media-replacement' ); ?></p>
		<?php
	}

	/**
	 * Render retention days field.
	 */
	public function render_retention_days_field(): void {
		$value = get_option( 'smr_retention_days', 0 );
		?>
		<input type="number" name="smr_retention_days" value="<?php echo esc_attr( $value ); ?>" min="0" max="365" class="small-text">
		<span><?php esc_html_e( 'days', 'smart-media-replacement' ); ?></span>
		<p class="description"><?php esc_html_e( 'Automatically delete revisions older than this many days. Set to 0 to disable.', 'smart-media-replacement' ); ?></p>
		<?php
	}

	/**
	 * Render default version type field.
	 */
	public function render_default_version_type_field(): void {
		$value = get_option( 'smr_default_version_type', 'minor' );
		?>
		<select name="smr_default_version_type">
			<option value="minor" <?php selected( $value, 'minor' ); ?>><?php esc_html_e( 'Minor (1.0 → 1.1)', 'smart-media-replacement' ); ?></option>
			<option value="major" <?php selected( $value, 'major' ); ?>><?php esc_html_e( 'Major (1.0 → 2.0)', 'smart-media-replacement' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'Default version increment when replacing files.', 'smart-media-replacement' ); ?></p>
		<?php
	}

	/**
	 * Render require comment field.
	 */
	public function render_require_comment_field(): void {
		$value = get_option( 'smr_require_comment', false );
		?>
		<input type="hidden" name="smr_require_comment" value="0">
		<label>
			<input type="checkbox" name="smr_require_comment" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Require a comment when replacing files', 'smart-media-replacement' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'Users must provide a comment describing the changes.', 'smart-media-replacement' ); ?></p>
		<?php
	}

	/**
	 * Render delete files field.
	 */
	public function render_delete_files_field(): void {
		$value = get_option( 'smr_delete_files_on_deactivate', false );
		?>
		<input type="hidden" name="smr_delete_files_on_deactivate" value="0">
		<label>
			<input type="checkbox" name="smr_delete_files_on_deactivate" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Delete all revision files when plugin is deactivated', 'smart-media-replacement' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'This will permanently delete all stored revision files.', 'smart-media-replacement' ); ?></p>
		<?php
	}

	/**
	 * Render delete data field.
	 */
	public function render_delete_data_field(): void {
		$value = get_option( 'smr_delete_data_on_deactivate', false );
		?>
		<input type="hidden" name="smr_delete_data_on_deactivate" value="0">
		<label>
			<input type="checkbox" name="smr_delete_data_on_deactivate" value="1" <?php checked( $value ); ?>>
			<?php esc_html_e( 'Delete database table when plugin is deactivated', 'smart-media-replacement' ); ?>
		</label>
		<p class="description"><?php esc_html_e( 'This will permanently delete all revision history from the database.', 'smart-media-replacement' ); ?></p>
		<?php
	}

	/**
	 * Get total revision count for current blog.
	 *
	 * @return int
	 */
	private function get_total_revision_count(): int {
		global $wpdb;

		$table_name = RevisionDatabase::get_table_name();

		if ( ! RevisionDatabase::table_exists() ) {
			return 0;
		}

		$blog_id = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE blog_id = %d",
				$blog_id
			)
		);
	}
}
