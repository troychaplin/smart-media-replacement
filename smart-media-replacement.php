<?php
/**
 * Plugin Name:       Smart Media Replacement
 * Description:       This plugin allows you to replace media in the media library with revision tracking.
 * Requires at least: 6.6
 * Requires PHP:      7.0
 * Version:           1.1.0
 * Author:            Troy Chaplin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-media-replacement
 *
 * @package Smart_Media_Replacement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants.
define( 'SMART_MEDIA_REPLACEMENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SMART_MEDIA_REPLACEMENT_PLUGIN_PATH', plugin_dir_path( __FILE__ ) );
define( 'SMART_MEDIA_REPLACEMENT_VERSION', '1.1.0' );

// Include Composer's autoload file.
require_once SMART_MEDIA_REPLACEMENT_PLUGIN_PATH . 'vendor/autoload.php';

/**
 * Plugin activation hook.
 *
 * @param bool $network_wide Whether plugin is being activated network-wide.
 */
function smart_media_replacement_activate( $network_wide ) {
	error_log( '[SMR] Plugin activation started. Network-wide: ' . ( $network_wide ? 'yes' : 'no' ) );

	// Create database table.
	\Smart_Media_Replacement\RevisionDatabase::create_table();

	// Set default options if not already set.
	if ( false === get_option( 'smr_max_revisions' ) ) {
		add_option( 'smr_max_revisions', 10 );
	}
	if ( false === get_option( 'smr_retention_days' ) ) {
		add_option( 'smr_retention_days', 0 );
	}
	if ( false === get_option( 'smr_default_version_type' ) ) {
		add_option( 'smr_default_version_type', 'minor' );
	}
	if ( false === get_option( 'smr_require_comment' ) ) {
		add_option( 'smr_require_comment', false );
	}
	if ( false === get_option( 'smr_delete_files_on_deactivate' ) ) {
		add_option( 'smr_delete_files_on_deactivate', false );
	}
	if ( false === get_option( 'smr_delete_data_on_deactivate' ) ) {
		add_option( 'smr_delete_data_on_deactivate', false );
	}

	// Schedule cleanup cron if retention is enabled.
	if ( ! wp_next_scheduled( 'smr_cleanup_revisions' ) ) {
		wp_schedule_event( time(), 'daily', 'smr_cleanup_revisions' );
	}

	error_log( '[SMR] Plugin activation completed' );
}
register_activation_hook( __FILE__, 'smart_media_replacement_activate' );

/**
 * Plugin deactivation hook.
 *
 * @param bool $network_wide Whether plugin is being deactivated network-wide.
 */
function smart_media_replacement_deactivate( $network_wide ) {
	error_log( '[SMR] Plugin deactivation started. Network-wide: ' . ( $network_wide ? 'yes' : 'no' ) );

	// Clear scheduled cron.
	$timestamp = wp_next_scheduled( 'smr_cleanup_revisions' );
	if ( $timestamp ) {
		wp_unschedule_event( $timestamp, 'smr_cleanup_revisions' );
	}

	// Check if we should delete files.
	if ( get_option( 'smr_delete_files_on_deactivate', false ) ) {
		error_log( '[SMR] Deleting revision files on deactivation' );
		\Smart_Media_Replacement\RevisionStorage::delete_all_revisions();
	}

	// Check if we should delete database data.
	if ( get_option( 'smr_delete_data_on_deactivate', false ) ) {
		error_log( '[SMR] Deleting database data on deactivation' );
		\Smart_Media_Replacement\RevisionDatabase::drop_table();
	}

	error_log( '[SMR] Plugin deactivation completed' );
}
register_deactivation_hook( __FILE__, 'smart_media_replacement_deactivate' );

/**
 * Handle new site creation in multisite.
 *
 * @param WP_Site $new_site New site object.
 */
function smart_media_replacement_new_site( $new_site ) {
	if ( is_plugin_active_for_network( plugin_basename( __FILE__ ) ) ) {
		switch_to_blog( $new_site->blog_id );

		// Set default options for new site.
		add_option( 'smr_max_revisions', 10 );
		add_option( 'smr_retention_days', 0 );
		add_option( 'smr_default_version_type', 'minor' );
		add_option( 'smr_require_comment', false );
		add_option( 'smr_delete_files_on_deactivate', false );
		add_option( 'smr_delete_data_on_deactivate', false );

		error_log( '[SMR] Initialized settings for new site: ' . $new_site->blog_id );

		restore_current_blog();
	}
}
add_action( 'wp_insert_site', 'smart_media_replacement_new_site' );

/**
 * Handle site deletion in multisite.
 *
 * @param WP_Site $old_site Deleted site object.
 */
function smart_media_replacement_delete_site( $old_site ) {
	// Clean up revisions for the deleted site.
	switch_to_blog( $old_site->blog_id );

	\Smart_Media_Replacement\RevisionStorage::delete_all_revisions();
	\Smart_Media_Replacement\RevisionDatabase::delete_by_blog();

	error_log( '[SMR] Cleaned up revisions for deleted site: ' . $old_site->blog_id );

	restore_current_blog();
}
add_action( 'wp_delete_site', 'smart_media_replacement_delete_site' );

// Instantiate the plugin classes.
new \Smart_Media_Replacement\ManageMedia();
new \Smart_Media_Replacement\RevisionManager();
new \Smart_Media_Replacement\SettingsPage();
new \Smart_Media_Replacement\RevisionUI();
