<?php
/**
 * Plugin Name:       Smart Media Replacement
 * Description:       Replace media library files with revision tracking. On multisite, network-activate only.
 * Requires at least: 6.6
 * Requires PHP:      7.4
 * Version:           1.2.1
 * Author:            Troy Chaplin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-media-replacement
 * Network:           true
 *
 * @package Smart_Media_Replacement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants.
define( 'SMART_MEDIA_REPLACEMENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SMART_MEDIA_REPLACEMENT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMART_MEDIA_REPLACEMENT_PLUGIN_FILE', __FILE__ );
define( 'SMART_MEDIA_REPLACEMENT_VERSION', '1.2.0' );

// Include Composer's autoload file.
require_once SMART_MEDIA_REPLACEMENT_PLUGIN_PATH . 'vendor/autoload.php';

/**
 * Schedule or reschedule the database health-check cron event.
 *
 * Called on activation and whenever the frequency setting changes. Clears any
 * existing event first so we never end up with duplicate schedules.
 *
 * @param string $frequency One of 'hourly', 'daily', 'weekly', or 'disabled'.
 */
function smr_reschedule_health_check( string $frequency ): void {
	$timestamp = wp_next_scheduled( 'smr_db_health_check' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'smr_db_health_check' );
	}

	$valid = array( 'hourly', 'daily', 'weekly' );
	if ( in_array( $frequency, $valid, true ) ) {
		wp_schedule_event( time(), $frequency, 'smr_db_health_check' );
	}
}

/**
 * Plugin activation hook.
 *
 * On multisite the plugin is network-activate-only (enforced by the
 * `Network: true` plugin header), so we only need to provision once
 * — the revisions table is network-wide (uses `$wpdb->base_prefix`)
 * and all settings live in network options.
 *
 * @param bool $network_wide Whether plugin is being activated network-wide.
 */
function smart_media_replacement_activate( $network_wide ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	\Smart_Media_Replacement\RevisionDatabase::create_table();
	\Smart_Media_Replacement\Settings::seed_defaults();

	// Schedule retention cleanup cron if not already scheduled.
	if ( ! wp_next_scheduled( 'smr_cleanup_revisions' ) ) {
		wp_schedule_event( time(), 'daily', 'smr_cleanup_revisions' );
	}

	// Schedule DB health-check cron at the configured frequency.
	$frequency = \Smart_Media_Replacement\Settings::get( 'smr_table_check_frequency', 'daily' );
	smr_reschedule_health_check( $frequency );
}
register_activation_hook( __FILE__, 'smart_media_replacement_activate' );

/**
 * Plugin deactivation hook.
 *
 * Honors the "Delete files on deactivation" and "Delete database on
 * deactivation" settings — these are explicit opt-ins because they
 * destroy user data and there's no undo.
 *
 * @param bool $network_wide Whether plugin is being deactivated network-wide.
 */
function smart_media_replacement_deactivate( $network_wide ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
	// Clear scheduled crons.
	$timestamp = wp_next_scheduled( 'smr_cleanup_revisions' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'smr_cleanup_revisions' );
	}
	$timestamp = wp_next_scheduled( 'smr_db_health_check' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'smr_db_health_check' );
	}

	// On multisite the shared revisions table holds rows for every blog, so
	// "delete files" has to walk each blog's uploads dir. On single-site it's
	// just the one uploads dir, which is what delete_all_revisions() targets.
	if ( \Smart_Media_Replacement\Settings::get( 'smr_delete_files_on_deactivate', false ) ) {
		if ( is_multisite() ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				\Smart_Media_Replacement\RevisionStorage::delete_all_revisions();
				restore_current_blog();
			}
		} else {
			\Smart_Media_Replacement\RevisionStorage::delete_all_revisions();
		}
	}

	if ( \Smart_Media_Replacement\Settings::get( 'smr_delete_data_on_deactivate', false ) ) {
		\Smart_Media_Replacement\RevisionDatabase::drop_table();
	}
}
register_deactivation_hook( __FILE__, 'smart_media_replacement_deactivate' );

/**
 * Handle site deletion in multisite.
 *
 * Cleans up the deleted site's revision rows and on-disk files before WP
 * removes the site's tables and uploads directory. Without this, rows
 * keyed to the deleted blog_id orphan in the shared table.
 *
 * @param \WP_Site $old_site Deleted site object.
 */
function smart_media_replacement_delete_site( $old_site ) {
	$blog_id = (int) $old_site->blog_id;

	// Files live in the deleted site's uploads dir — switch context so
	// wp_upload_dir() resolves to the correct path before we walk it.
	switch_to_blog( $blog_id );
	\Smart_Media_Replacement\RevisionStorage::delete_all_revisions();
	restore_current_blog();

	// Rows can be deleted from any context; the helper takes an explicit
	// blog_id so it doesn't depend on the switch above still being active.
	\Smart_Media_Replacement\RevisionDatabase::delete_by_blog( $blog_id );
}
add_action( 'wp_delete_site', 'smart_media_replacement_delete_site' );

/**
 * Add a Settings link to the plugin row on the Plugins screen.
 *
 * Uses the network-admin-specific filter on multisite so the link points
 * to the network settings page rather than 404ing through admin.php.
 *
 * @param array $links Existing plugin action links.
 * @return array
 */
function smart_media_replacement_action_links( array $links ): array {
	$url = is_network_admin()
		? network_admin_url( 'settings.php?page=' . \Smart_Media_Replacement\SettingsPage::PAGE_SLUG )
		: admin_url( 'upload.php?page=' . \Smart_Media_Replacement\SettingsPage::PAGE_SLUG );

	$settings_link = sprintf(
		'<a href="%s">%s</a>',
		esc_url( $url ),
		esc_html__( 'Settings', 'smart-media-replacement' )
	);

	array_unshift( $links, $settings_link );
	return $links;
}
add_filter( 'plugin_action_links_' . plugin_basename( __FILE__ ), 'smart_media_replacement_action_links' );
add_filter( 'network_admin_plugin_action_links_' . plugin_basename( __FILE__ ), 'smart_media_replacement_action_links' );

// Instantiate the plugin classes.
new \Smart_Media_Replacement\ManageMedia();
new \Smart_Media_Replacement\RevisionManager();
new \Smart_Media_Replacement\SettingsPage();
new \Smart_Media_Replacement\RevisionUI();
new \Smart_Media_Replacement\EditorIntegration();

// Register WP-CLI commands. Loaded via require_once rather than the PSR-4
// autoloader because the class extends WP_CLI_Command and must not be
// referenced outside a WP-CLI runtime.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SMART_MEDIA_REPLACEMENT_PLUGIN_PATH . 'Functions/CLI.php';
}
