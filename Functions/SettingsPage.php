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
 * Handles plugin settings registration and display. On multisite the page is
 * registered only in the network admin (the plugin is network-activate-only,
 * so there is no per-site settings UI); on single-site it lives under the
 * Media menu as before. Settings reads/writes go through the Settings
 * resolver so the storage backend is transparent to the rest of the plugin.
 */
class SettingsPage {

	/**
	 * Settings page slug, shared between single-site and network registrations.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'smr-settings';

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( is_multisite() ) {
			add_action( 'network_admin_menu', array( $this, 'add_network_settings_page' ) );
		} else {
			add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		}

		// register_settings() is needed by the Settings API on single-site
		// (options.php uses the allow-list it produces). It is a no-op on
		// multisite because the network page POSTs to its own handler, but
		// calling it unconditionally keeps the section/field registrations
		// in one place — do_settings_sections() relies on them in both modes.
		add_action( 'admin_init', array( $this, 'register_settings' ) );

		// On single-site, reschedule the health-check cron whenever the
		// frequency setting is saved via options.php.
		if ( ! is_multisite() ) {
			add_action( 'update_option_smr_table_check_frequency', array( $this, 'handle_frequency_change' ), 10, 2 );
		}
	}

	/**
	 * Add settings page to the site Media menu (single-site only).
	 */
	public function add_settings_page(): void {
		add_submenu_page(
			'upload.php',
			__( 'Media Replacement Settings', 'smart-media-replacement' ),
			__( 'Replacement Settings', 'smart-media-replacement' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);
	}

	/**
	 * Add settings page to the network admin Settings menu (multisite only).
	 *
	 * The save handler is attached to `load-{hook}` rather than a separate
	 * admin-post action because the form POSTs back to its own URL — this
	 * keeps the entire settings flow in one place and avoids the round-trip
	 * through admin-post.php that's awkward in the network admin context.
	 */
	public function add_network_settings_page(): void {
		$hook = add_submenu_page(
			'settings.php',
			__( 'Media Replacement Settings', 'smart-media-replacement' ),
			__( 'Media Replacement', 'smart-media-replacement' ),
			'manage_network_options',
			self::PAGE_SLUG,
			array( $this, 'render_settings_page' )
		);

		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'handle_network_save' ) );
		}
	}

	/**
	 * Handle the multisite network settings form submission.
	 *
	 * Runs on page load before render so we can redirect with a success flag
	 * (Post/Redirect/Get) instead of re-rendering the form on the POST itself.
	 */
	public function handle_network_save(): void {
		if ( 'POST' !== ( isset( $_SERVER['REQUEST_METHOD'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) : '' ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_network_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to update these settings.', 'smart-media-replacement' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( 'smr_save_network_settings', 'smr_network_settings_nonce' );

		// Booleans — checkbox inputs are paired with a hidden "0" input in the
		// markup so unchecked boxes still appear in $_POST. Treat any truthy
		// value as on; anything else as off.
		$bool_keys = array(
			'smr_enable_revisions',
			'smr_require_comment',
			'smr_delete_files_on_deactivate',
			'smr_delete_data_on_deactivate',
		);
		foreach ( $bool_keys as $key ) {
			Settings::update( $key, ! empty( $_POST[ $key ] ) );
		}

		// Integers.
		foreach ( array( 'smr_max_revisions', 'smr_retention_days' ) as $key ) {
			$value = isset( $_POST[ $key ] ) ? absint( wp_unslash( $_POST[ $key ] ) ) : 0;
			Settings::update( $key, $value );
		}

		// Enums — constrained to the same allowed values the UI offers so a
		// tampered POST cannot wedge a garbage string into storage.
		$version_type = isset( $_POST['smr_default_version_type'] )
			? sanitize_text_field( wp_unslash( $_POST['smr_default_version_type'] ) )
			: 'minor';
		Settings::update(
			'smr_default_version_type',
			in_array( $version_type, array( 'major', 'minor' ), true ) ? $version_type : 'minor'
		);

		$file_types = isset( $_POST['smr_revision_file_types'] )
			? sanitize_text_field( wp_unslash( $_POST['smr_revision_file_types'] ) )
			: 'documents';
		Settings::update(
			'smr_revision_file_types',
			in_array( $file_types, array( 'documents', 'images', 'all' ), true ) ? $file_types : 'documents'
		);

		$valid_frequencies = array( 'hourly', 'daily', 'weekly', 'disabled' );
		$table_check_freq  = isset( $_POST['smr_table_check_frequency'] )
			? sanitize_text_field( wp_unslash( $_POST['smr_table_check_frequency'] ) )
			: 'daily';
		$table_check_freq  = in_array( $table_check_freq, $valid_frequencies, true ) ? $table_check_freq : 'daily';
		Settings::update( 'smr_table_check_frequency', $table_check_freq );
		smr_reschedule_health_check( $table_check_freq );

		wp_safe_redirect(
			add_query_arg(
				array( 'settings-updated' => 'true' ),
				network_admin_url( 'settings.php?page=' . self::PAGE_SLUG )
			)
		);
		exit;
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
		register_setting(
			'smr_settings',
			'smr_table_check_frequency',
			array(
				'sanitize_callback' => array( $this, 'sanitize_table_check_frequency' ),
			)
		);

		// Revision Settings Section.
		add_settings_section(
			'smr_revision_settings',
			__( 'Revision Settings', 'smart-media-replacement' ),
			array( $this, 'render_revision_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'smr_enable_revisions',
			__( 'Enable Revisions', 'smart-media-replacement' ),
			array( $this, 'render_enable_revisions_field' ),
			self::PAGE_SLUG,
			'smr_revision_settings'
		);

		add_settings_field(
			'smr_revision_file_types',
			__( 'Enable Revisions For', 'smart-media-replacement' ),
			array( $this, 'render_revision_file_types_field' ),
			self::PAGE_SLUG,
			'smr_revision_settings'
		);

		add_settings_field(
			'smr_max_revisions',
			__( 'Maximum Revisions', 'smart-media-replacement' ),
			array( $this, 'render_max_revisions_field' ),
			self::PAGE_SLUG,
			'smr_revision_settings'
		);

		add_settings_field(
			'smr_retention_days',
			__( 'Retention Period', 'smart-media-replacement' ),
			array( $this, 'render_retention_days_field' ),
			self::PAGE_SLUG,
			'smr_revision_settings'
		);

		add_settings_field(
			'smr_default_version_type',
			__( 'Default Version Type', 'smart-media-replacement' ),
			array( $this, 'render_default_version_type_field' ),
			self::PAGE_SLUG,
			'smr_revision_settings'
		);

		add_settings_field(
			'smr_require_comment',
			__( 'Require Comment', 'smart-media-replacement' ),
			array( $this, 'render_require_comment_field' ),
			self::PAGE_SLUG,
			'smr_revision_settings'
		);

		// Cleanup Settings Section.
		add_settings_section(
			'smr_cleanup_settings',
			__( 'Cleanup Settings', 'smart-media-replacement' ),
			array( $this, 'render_cleanup_section' ),
			self::PAGE_SLUG
		);

		add_settings_field(
			'smr_delete_files_on_deactivate',
			__( 'Delete Files on Deactivation', 'smart-media-replacement' ),
			array( $this, 'render_delete_files_field' ),
			self::PAGE_SLUG,
			'smr_cleanup_settings'
		);

		add_settings_field(
			'smr_delete_data_on_deactivate',
			__( 'Delete Database on Deactivation', 'smart-media-replacement' ),
			array( $this, 'render_delete_data_field' ),
			self::PAGE_SLUG,
			'smr_cleanup_settings'
		);

		add_settings_field(
			'smr_table_check_frequency',
			__( 'Database Health Check', 'smart-media-replacement' ),
			array( $this, 'render_table_check_frequency_field' ),
			self::PAGE_SLUG,
			'smr_cleanup_settings'
		);

		// Storage Info Section.
		add_settings_section(
			'smr_storage_info',
			__( 'Storage Information', 'smart-media-replacement' ),
			array( $this, 'render_storage_section' ),
			self::PAGE_SLUG
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
		$capability = is_multisite() ? 'manage_network_options' : 'manage_options';
		if ( ! current_user_can( $capability ) ) {
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
			<?php if ( is_multisite() ) : ?>
				<form method="post" action="<?php echo esc_url( network_admin_url( 'settings.php?page=' . self::PAGE_SLUG ) ); ?>">
					<?php wp_nonce_field( 'smr_save_network_settings', 'smr_network_settings_nonce' ); ?>
					<?php do_settings_sections( self::PAGE_SLUG ); ?>
					<?php submit_button( __( 'Save Settings', 'smart-media-replacement' ) ); ?>
				</form>
			<?php else : ?>
				<form action="options.php" method="post">
					<?php
					settings_fields( 'smr_settings' );
					do_settings_sections( self::PAGE_SLUG );
					submit_button( __( 'Save Settings', 'smart-media-replacement' ) );
					?>
				</form>
			<?php endif; ?>
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
	 *
	 * On multisite the network admin page shows network-wide totals; on
	 * single-site it shows the current site's totals.
	 */
	public function render_storage_section(): void {
		$is_multi       = is_multisite();
		$total_storage  = $is_multi
			? RevisionDatabase::get_network_total_storage()
			: RevisionDatabase::get_total_storage();
		$revision_count = $is_multi
			? $this->get_network_total_revision_count()
			: $this->get_total_revision_count();
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
		$value = Settings::get( 'smr_enable_revisions', true );
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
		$value = Settings::get( 'smr_revision_file_types', 'documents' );
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
		$value = Settings::get( 'smr_max_revisions', 10 );
		?>
		<input type="number" name="smr_max_revisions" value="<?php echo esc_attr( $value ); ?>" min="0" max="100" class="small-text">
		<p class="description"><?php esc_html_e( 'Maximum number of revisions to keep per file. Set to 0 for unlimited.', 'smart-media-replacement' ); ?></p>
		<?php
	}

	/**
	 * Render retention days field.
	 */
	public function render_retention_days_field(): void {
		$value = Settings::get( 'smr_retention_days', 0 );
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
		$value = Settings::get( 'smr_default_version_type', 'minor' );
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
		$value = Settings::get( 'smr_require_comment', false );
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
		$value = Settings::get( 'smr_delete_files_on_deactivate', false );
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
		$value = Settings::get( 'smr_delete_data_on_deactivate', false );
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
	 * Render the database health check frequency field.
	 */
	public function render_table_check_frequency_field(): void {
		$value = Settings::get( 'smr_table_check_frequency', 'daily' );
		?>
		<select name="smr_table_check_frequency">
			<option value="hourly" <?php selected( $value, 'hourly' ); ?>><?php esc_html_e( 'Hourly', 'smart-media-replacement' ); ?></option>
			<option value="daily" <?php selected( $value, 'daily' ); ?>><?php esc_html_e( 'Daily (recommended)', 'smart-media-replacement' ); ?></option>
			<option value="weekly" <?php selected( $value, 'weekly' ); ?>><?php esc_html_e( 'Weekly', 'smart-media-replacement' ); ?></option>
			<option value="disabled" <?php selected( $value, 'disabled' ); ?>><?php esc_html_e( 'Disabled (manual / WP-CLI only)', 'smart-media-replacement' ); ?></option>
		</select>
		<p class="description"><?php esc_html_e( 'How often to automatically verify the revisions table exists and recreate it if missing. On large networks, daily or weekly reduces unnecessary database queries. Disable if you prefer to manage this manually via WP-CLI.', 'smart-media-replacement' ); ?></p>
		<?php
	}

	/**
	 * Sanitize and apply the table check frequency on single-site save.
	 *
	 * @param string $value Submitted value.
	 * @return string
	 */
	public function sanitize_table_check_frequency( string $value ): string {
		$allowed = array( 'hourly', 'daily', 'weekly', 'disabled' );
		return in_array( $value, $allowed, true ) ? $value : 'daily';
	}

	/**
	 * Reschedule the health-check cron when the frequency option changes (single-site).
	 *
	 * @param mixed  $old_value Previous option value.
	 * @param string $new_value New option value.
	 */
	public function handle_frequency_change( $old_value, string $new_value ): void {
		smr_reschedule_health_check( $new_value );
	}

	/**
	 * Get total revision count for current blog.
	 *
	 * @return int
	 */
	private function get_total_revision_count(): int {
		global $wpdb;

		if ( ! RevisionDatabase::table_exists() ) {
			return 0;
		}

		$table_name = RevisionDatabase::get_table_name();
		$blog_id    = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE blog_id = %d",
				$blog_id
			)
		);
	}

	/**
	 * Get total revision count across the entire network.
	 *
	 * @return int
	 */
	private function get_network_total_revision_count(): int {
		global $wpdb;

		if ( ! RevisionDatabase::table_exists() ) {
			return 0;
		}

		$table_name = RevisionDatabase::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$table_name}" );
	}
}
