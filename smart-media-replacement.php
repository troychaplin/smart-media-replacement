<?php
/**
 * Plugin Name:       Smart Media Replacement
 * Description:       Replace media library files with revision tracking, and audit which files are actually used. On multisite, network-activate only.
 * Requires at least: 7.0
 * Requires PHP:      8.0
 * Version:           1.3.0
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
define( 'SMART_MEDIA_REPLACEMENT_VERSION', '1.3.0' );

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

	// The media audit tables are per-site (they index one site's content), so
	// unlike the shared revisions table they need provisioning per blog. On a
	// very large network we skip the loop and let the lazy guard in
	// IndexTable::ensure_tables() create each site's tables on first use,
	// rather than risk timing out the activation request.
	if ( is_multisite() ) {
		if ( ! wp_is_large_network( 'sites' ) ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				\Smart_Media_Replacement\Audit\IndexTable::ensure_tables( true );
				restore_current_blog();
			}
		}
	} else {
		\Smart_Media_Replacement\Audit\IndexTable::ensure_tables( true );
	}

	// Schedule retention cleanup cron if not already scheduled.
	if ( ! wp_next_scheduled( 'smr_cleanup_revisions' ) ) {
		wp_schedule_event( time(), 'daily', 'smr_cleanup_revisions' );
	}

	// Schedule DB health-check cron at the configured frequency.
	$frequency = \Smart_Media_Replacement\Settings::get( 'smr_table_check_frequency', 'daily' );
	smr_reschedule_health_check( $frequency );

	// Deliberately no automatic first scan. Kicking off an unbounded index
	// build on every site of a network at activation is hostile, and the audit
	// screen already handles the un-scanned state ("Index has not been built
	// yet"). The first scan is user-initiated, or `wp smr audit scan`.
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
	// Clear scheduled crons. wp_clear_scheduled_hook() rather than a
	// wp_next_scheduled()/wp_unschedule_event() pair, which only ever clears
	// the next occurrence and leaves duplicates behind.
	wp_clear_scheduled_hook( 'smr_cleanup_revisions' );
	wp_clear_scheduled_hook( 'smr_db_health_check' );
	wp_clear_scheduled_hook( \Smart_Media_Replacement\Audit\BatchRunner::CRON_HOOK );
	wp_clear_scheduled_hook( \Smart_Media_Replacement\Audit\BatchRunner::BACKFILL_HOOK );

	// Audit scan state is per-site. On single-site clear it so a mid-scan
	// deactivation doesn't leave a frozen progress bar on reactivation. On
	// multisite we deliberately don't loop every blog for this: orphaned
	// single-events self-clean when they fire and find no callback, and
	// walking thousands of sites during a deactivation click is the worse
	// trade. Uninstall does the thorough pass.
	if ( ! is_multisite() ) {
		delete_transient( \Smart_Media_Replacement\Audit\BatchRunner::CURSOR_KEY );
		delete_transient( \Smart_Media_Replacement\Audit\BatchRunner::SUMMARY_CURSOR_KEY );
		delete_transient( \Smart_Media_Replacement\Audit\BatchRunner::PHASE_KEY );
		delete_transient( \Smart_Media_Replacement\Audit\BatchRunner::ATTACHMENT_IDS_KEY );
		delete_option( \Smart_Media_Replacement\Audit\BatchRunner::PROGRESS_KEY );
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

	// "Delete database on deactivation" means every table the plugin owns:
	// the shared revisions table plus each site's pair of audit tables.
	if ( \Smart_Media_Replacement\Settings::get( 'smr_delete_data_on_deactivate', false ) ) {
		\Smart_Media_Replacement\RevisionDatabase::drop_table();

		if ( is_multisite() ) {
			$sites = get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			);
			foreach ( $sites as $site_id ) {
				switch_to_blog( (int) $site_id );
				\Smart_Media_Replacement\Audit\IndexTable::drop();
				delete_option( \Smart_Media_Replacement\Audit\IndexTable::DB_VERSION_OPTION );
				restore_current_blog();
			}
		} else {
			\Smart_Media_Replacement\Audit\IndexTable::drop();
			delete_option( \Smart_Media_Replacement\Audit\IndexTable::DB_VERSION_OPTION );
		}
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
 * Provision the per-site media audit tables for a newly created site.
 *
 * Core's own wp_initialize_site() runs at priority 10 and does its own
 * internal switch_to_blog()/restore_current_blog(), so by the time we run at
 * 20 the current blog is the caller's, not the new one — we have to switch
 * ourselves before $wpdb->prefix resolves correctly.
 *
 * @param \WP_Site $new_site New site object.
 */
function smart_media_replacement_initialize_site( $new_site ) {
	switch_to_blog( (int) $new_site->blog_id );
	\Smart_Media_Replacement\Audit\IndexTable::ensure_tables( true );
	restore_current_blog();
}
add_action( 'wp_initialize_site', 'smart_media_replacement_initialize_site', 20 );

/**
 * Include the per-site media audit tables in multisite site deletion.
 *
 * Core's wp_uninitialize_site() only drops $wpdb->tables('blog') — the fixed
 * core list — so without this filter every deleted subsite leaves two orphan
 * tables behind. The filter fires while switched to the site being deleted,
 * so $wpdb->prefix is already correct. This cannot live on wp_delete_site:
 * that fires later, after the prefix context has been restored.
 *
 * @param string[] $tables Tables to drop.
 * @return string[]
 */
function smart_media_replacement_drop_tables( array $tables ): array {
	$tables[] = \Smart_Media_Replacement\Audit\IndexTable::table_name();
	$tables[] = \Smart_Media_Replacement\Audit\IndexTable::summary_table_name();
	return $tables;
}
add_filter( 'wpmu_drop_tables', 'smart_media_replacement_drop_tables' );

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

// Media Audit subsystem. Gated on the setting so sites that don't want the
// index don't pay the per-save indexing cost.
if ( \Smart_Media_Replacement\Settings::get( 'smr_enable_audit', true ) ) {
	new \Smart_Media_Replacement\Audit\AuditPage();
	new \Smart_Media_Replacement\Audit\AuditAjax();
	new \Smart_Media_Replacement\Audit\AuditIndexer();
}

// Register WP-CLI commands. Loaded via require_once rather than the PSR-4
// autoloader because the class extends WP_CLI_Command and must not be
// referenced outside a WP-CLI runtime.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once SMART_MEDIA_REPLACEMENT_PLUGIN_PATH . 'Functions/CLI.php';
	require_once SMART_MEDIA_REPLACEMENT_PLUGIN_PATH . 'Functions/AuditCLI.php';
}
