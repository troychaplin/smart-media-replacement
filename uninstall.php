<?php
/**
 * Uninstall handler.
 *
 * Removes every trace of the plugin: the shared revisions table, each site's
 * pair of media audit tables, all options and transients, the cached
 * file-size post meta, scheduled events, and stored revision files.
 *
 * This is more destructive than deactivation by design — deactivation only
 * removes files and tables when the matching opt-in setting is enabled,
 * whereas uninstall means "remove everything".
 *
 * On very large networks, run `wp plugin uninstall smart-media-replacement`
 * via WP-CLI so the per-site loop below isn't bounded by a web request.
 *
 * @package Smart_Media_Replacement
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/autoload.php';

use Smart_Media_Replacement\Audit\BatchRunner;
use Smart_Media_Replacement\Audit\IndexTable;
use Smart_Media_Replacement\RevisionDatabase;
use Smart_Media_Replacement\RevisionStorage;
use Smart_Media_Replacement\Settings;

/**
 * Remove all plugin data belonging to the current site.
 *
 * Everything here is per-site storage: the audit tables, the audit scan
 * state, the cached file-size meta, cron entries and revision files. Keys are
 * referenced through class constants so they can never drift from what the
 * plugin actually writes.
 */
function smart_media_replacement_uninstall_site(): void {
	IndexTable::drop();

	delete_option( BatchRunner::PROGRESS_KEY );
	delete_option( BatchRunner::INDEX_BUILT_KEY );
	delete_option( IndexTable::DB_VERSION_OPTION );
	delete_option( RevisionDatabase::DB_VERSION_OPTION );

	delete_transient( BatchRunner::CURSOR_KEY );
	delete_transient( BatchRunner::SUMMARY_CURSOR_KEY );
	delete_transient( BatchRunner::PHASE_KEY );
	delete_transient( BatchRunner::ATTACHMENT_IDS_KEY );

	// One row per attachment, so these have to go or they orphan on every site.
	delete_post_meta_by_key( BatchRunner::FILESIZE_META_KEY );
	delete_post_meta_by_key( IndexTable::MARKED_META_KEY );

	wp_clear_scheduled_hook( BatchRunner::CRON_HOOK );
	wp_clear_scheduled_hook( BatchRunner::BACKFILL_HOOK );
	wp_clear_scheduled_hook( 'smr_cleanup_revisions' );
	wp_clear_scheduled_hook( 'smr_db_health_check' );

	// Revision files live in each site's own uploads directory.
	RevisionStorage::delete_all_revisions();
}

if ( is_multisite() ) {
	$smart_media_replacement_sites = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);
	foreach ( $smart_media_replacement_sites as $smart_media_replacement_site_id ) {
		switch_to_blog( (int) $smart_media_replacement_site_id );
		smart_media_replacement_uninstall_site();
		restore_current_blog();
	}
	unset( $smart_media_replacement_sites, $smart_media_replacement_site_id );
} else {
	smart_media_replacement_uninstall_site();
}

// The revisions table is network-wide (base_prefix + a blog_id column), so it
// is dropped once rather than per site. Settings::delete_all() routes to
// network options on multisite and site options otherwise.
RevisionDatabase::drop_table();
Settings::delete_all();
