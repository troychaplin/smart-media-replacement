<?php
/**
 * WP-CLI Commands
 *
 * @package Smart_Media_Replacement
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

namespace Smart_Media_Replacement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Manages the Smart Media Replacement database table.
 *
 * ## EXAMPLES
 *
 *     # Check whether the revisions table exists.
 *     $ wp smr db check
 *
 *     # Recreate the table if it is missing.
 *     $ wp smr db repair
 *
 *     # Show revision counts and storage usage for the current site.
 *     $ wp smr db status
 *
 *     # Show a per-site breakdown across the entire network (multisite only).
 *     $ wp smr db status --network
 *
 * @when after_wp_load
 */
class CLI extends \WP_CLI_Command {

	/**
	 * Check whether the revisions table exists.
	 *
	 * The revisions table is shared across all sites on a multisite network
	 * (it uses the base table prefix). This command confirms the table is
	 * present and returns a non-zero exit code if it is not.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Explicitly confirm the check is running in network context (multisite
	 * only). Result is the same — the table is network-wide — but the output
	 * labels the scope clearly.
	 *
	 * [--site-id=<id>]
	 * : Check the table from a specific site's context (multisite only).
	 * Switches blog before checking so any site-specific filters apply.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp smr db check
	 *     Success: Table wp_smr_revisions exists.
	 *
	 *     $ wp smr db check --network
	 *     Success: Table wp_smr_revisions exists (network-wide).
	 *
	 *     $ wp smr db check --site-id=3
	 *     Success: Table wp_smr_revisions exists (site 3).
	 *
	 * @subcommand check
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function check( array $args, array $assoc_args ): void {
		$network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );
		$site_id = \WP_CLI\Utils\get_flag_value( $assoc_args, 'site-id', null );

		if ( null !== $site_id ) {
			$this->require_multisite( '--site-id' );
			$site_id = (int) $site_id;
			switch_to_blog( $site_id );
		}

		$exists     = RevisionDatabase::table_exists();
		$table_name = RevisionDatabase::get_table_name();

		if ( null !== $site_id ) {
			restore_current_blog();
		}

		$scope = '';
		if ( $network ) {
			$scope = ' (network-wide)';
		} elseif ( null !== $site_id ) {
			$scope = " (site {$site_id})";
		}

		if ( $exists ) {
			\WP_CLI::success( "Table {$table_name} exists{$scope}." );
		} else {
			\WP_CLI::error( "Table {$table_name} does not exist{$scope}. Run `wp smr db repair` to recreate it." );
		}
	}

	/**
	 * Recreate the revisions table if it is missing.
	 *
	 * Uses dbDelta under the hood, so running this when the table already
	 * exists is safe — it will report the table is already present and exit.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Repair in network context (multisite only). Since the table is
	 * shared, the result is the same as a plain repair, but the output
	 * confirms it applies network-wide.
	 *
	 * [--site-id=<id>]
	 * : Run the repair from a specific site's context (multisite only).
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp smr db repair
	 *     Success: Table wp_smr_revisions created successfully.
	 *
	 *     $ wp smr db repair --network
	 *     Success: Table wp_smr_revisions created successfully (network-wide).
	 *
	 * @subcommand repair
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function repair( array $args, array $assoc_args ): void {
		$network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );
		$site_id = \WP_CLI\Utils\get_flag_value( $assoc_args, 'site-id', null );

		if ( null !== $site_id ) {
			$this->require_multisite( '--site-id' );
			switch_to_blog( (int) $site_id );
		}

		$table_name = RevisionDatabase::get_table_name();

		$scope = '';
		if ( $network ) {
			$scope = ' (network-wide)';
		} elseif ( null !== $site_id ) {
			$scope = " (site {$site_id})";
		}

		if ( RevisionDatabase::table_exists() ) {
			if ( null !== $site_id ) {
				restore_current_blog();
			}
			\WP_CLI::success( "Table {$table_name} already exists{$scope}. No action needed." );
			return;
		}

		$created = RevisionDatabase::create_table();

		if ( null !== $site_id ) {
			restore_current_blog();
		}

		if ( $created ) {
			\WP_CLI::success( "Table {$table_name} created successfully{$scope}." );
		} else {
			\WP_CLI::error( "Failed to create table {$table_name}. Check database permissions." );
		}
	}

	/**
	 * Show revision counts and storage usage.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Show a per-site breakdown across the entire network (multisite only).
	 * Iterates every site and reports counts and storage individually,
	 * followed by a network-wide total.
	 *
	 * [--site-id=<id>]
	 * : Show stats for a specific site by blog ID (multisite only).
	 *
	 * [--format=<format>]
	 * : Output format. Accepted values: table, csv, json, yaml. Default: table.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp smr db status
	 *     +-------------------+-------+
	 *     | Field             | Value |
	 *     +-------------------+-------+
	 *     | Table             | wp_smr_revisions |
	 *     | Table exists      | Yes   |
	 *     | Total revisions   | 42    |
	 *     | Storage used      | 18 MB |
	 *     +-------------------+-------+
	 *
	 *     $ wp smr db status --network
	 *     (per-site table with totals row)
	 *
	 *     $ wp smr db status --site-id=3
	 *     (stats scoped to site 3)
	 *
	 *     $ wp smr db status --format=json
	 *
	 * @subcommand status
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function status( array $args, array $assoc_args ): void {
		$network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );
		$site_id = \WP_CLI\Utils\get_flag_value( $assoc_args, 'site-id', null );
		$format  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		if ( $network ) {
			$this->require_multisite( '--network' );
			$this->status_network( $format );
			return;
		}

		if ( null !== $site_id ) {
			$this->require_multisite( '--site-id' );
			switch_to_blog( (int) $site_id );
		}

		$table_exists   = RevisionDatabase::table_exists();
		$table_name     = RevisionDatabase::get_table_name();
		$revision_count = $table_exists ? $this->get_revision_count() : 0;
		$storage_bytes  = $table_exists ? RevisionDatabase::get_total_storage() : 0;

		if ( null !== $site_id ) {
			restore_current_blog();
		}

		$rows = array(
			array(
				'Field' => 'Table',
				'Value' => $table_name,
			),
			array(
				'Field' => 'Table exists',
				'Value' => $table_exists ? 'Yes' : 'No',
			),
			array(
				'Field' => 'Total revisions',
				'Value' => number_format_i18n( $revision_count ),
			),
			array(
				'Field' => 'Storage used',
				'Value' => size_format( $storage_bytes ),
			),
		);

		\WP_CLI\Utils\format_items( $format, $rows, array( 'Field', 'Value' ) );
	}

	/**
	 * Output a per-site status breakdown across the entire network.
	 *
	 * @param string $format Output format.
	 */
	private function status_network( string $format ): void {
		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);

		$rows          = array();
		$total_count   = 0;
		$total_storage = 0;
		$table_name    = RevisionDatabase::get_table_name();
		$table_exists  = RevisionDatabase::table_exists();

		foreach ( $sites as $blog_id ) {
			switch_to_blog( (int) $blog_id );

			$count   = $table_exists ? $this->get_revision_count() : 0;
			$storage = $table_exists ? RevisionDatabase::get_total_storage() : 0;

			$rows[] = array(
				'Site ID'   => $blog_id,
				'Site URL'  => get_bloginfo( 'url' ),
				'Revisions' => number_format_i18n( $count ),
				'Storage'   => size_format( $storage ),
			);

			$total_count   += $count;
			$total_storage += $storage;

			restore_current_blog();
		}

		// Append totals row.
		$rows[] = array(
			'Site ID'   => '—',
			'Site URL'  => 'TOTAL',
			'Revisions' => number_format_i18n( $total_count ),
			'Storage'   => size_format( $total_storage ),
		);

		\WP_CLI::line( "Table: {$table_name} | Exists: " . ( $table_exists ? 'Yes' : 'No' ) );
		\WP_CLI\Utils\format_items( $format, $rows, array( 'Site ID', 'Site URL', 'Revisions', 'Storage' ) );
	}

	/**
	 * Delete expired revisions according to the configured retention policy.
	 *
	 * Reads smr_retention_days from plugin settings. Use --site-id or
	 * --network on multisite to scope the operation. Runs without a time
	 * limit — use this instead of waiting for the daily cron when you need
	 * an immediate cleanup or want to clear a backlog safely.
	 *
	 * ## OPTIONS
	 *
	 * [--network]
	 * : Process every site in the network (multisite only).
	 *
	 * [--site-id=<id>]
	 * : Process a specific site by blog ID (multisite only).
	 *
	 * [--dry-run]
	 * : Show how many revisions would be deleted without deleting anything.
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp smr db cleanup
	 *     Success: Deleted 42 expired revision(s).
	 *
	 *     $ wp smr db cleanup --network
	 *     Site 1 (example.com): deleted 42 revision(s).
	 *     Site 2 (sub.example.com): deleted 0 revision(s).
	 *     Success: Deleted 42 expired revision(s) across 2 site(s).
	 *
	 *     $ wp smr db cleanup --dry-run
	 *     Would delete 42 expired revision(s) (retention: 30 days).
	 *
	 * @subcommand cleanup
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function cleanup( array $args, array $assoc_args ): void {
		$network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );
		$site_id = \WP_CLI\Utils\get_flag_value( $assoc_args, 'site-id', null );
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );

		$retention_days = (int) Settings::get( 'smr_retention_days', 0 );

		if ( $retention_days <= 0 ) {
			\WP_CLI::error( 'Retention policy is disabled (smr_retention_days = 0). Set a retention period in the plugin settings first.' );
		}

		if ( $network ) {
			$this->require_multisite( '--network' );
			$this->cleanup_network( $retention_days, $dry_run, $assoc_args );
			return;
		}

		if ( null !== $site_id ) {
			$this->require_multisite( '--site-id' );
			switch_to_blog( (int) $site_id );
		}

		if ( $dry_run ) {
			$count = RevisionDatabase::count_expired_revisions( $retention_days );
			if ( null !== $site_id ) {
				restore_current_blog();
			}
			\WP_CLI::line( "Would delete {$count} expired revision(s) (retention: {$retention_days} days)." );
			return;
		}

		\WP_CLI::confirm(
			'About to delete ' . RevisionDatabase::count_expired_revisions( $retention_days ) . ' expired revision(s). Continue?',
			$assoc_args
		);

		$deleted = RevisionManager::cleanup_site( $retention_days );

		if ( null !== $site_id ) {
			restore_current_blog();
		}

		\WP_CLI::success( "Deleted {$deleted} expired revision(s)." );
	}

	/**
	 * Run cleanup across every site in the network.
	 *
	 * @param int   $retention_days Retention period in days.
	 * @param bool  $dry_run        Whether to report without deleting.
	 * @param array $assoc_args     Raw assoc args (carries --yes through to confirm).
	 */
	private function cleanup_network( int $retention_days, bool $dry_run, array $assoc_args ): void {
		$sites = get_sites(
			array(
				'fields' => 'ids',
				'number' => 0,
			)
		);
		$total = 0;

		if ( $dry_run ) {
			foreach ( $sites as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				$count  = RevisionDatabase::count_expired_revisions( $retention_days );
				$url    = get_bloginfo( 'url' );
				$total += $count;
				restore_current_blog();
				if ( $count > 0 ) {
					\WP_CLI::line( "Site {$blog_id} ({$url}): would delete {$count} revision(s)." );
				}
			}
			\WP_CLI::line( "Total: would delete {$total} expired revision(s) across " . count( $sites ) . ' site(s) (retention: ' . $retention_days . ' days).' );
			return;
		}

		// Pre-flight count for the confirmation prompt (skipped when --yes is passed).
		if ( ! isset( $assoc_args['yes'] ) ) {
			$preflight = 0;
			foreach ( $sites as $blog_id ) {
				switch_to_blog( (int) $blog_id );
				$preflight += RevisionDatabase::count_expired_revisions( $retention_days );
				restore_current_blog();
			}
			\WP_CLI::confirm(
				"About to delete {$preflight} expired revision(s) across " . count( $sites ) . ' site(s). Continue?',
				$assoc_args
			);
		}

		foreach ( $sites as $blog_id ) {
			switch_to_blog( (int) $blog_id );
			$deleted = RevisionManager::cleanup_site( $retention_days );
			$url     = get_bloginfo( 'url' );
			$total  += $deleted;
			restore_current_blog();
			if ( $deleted > 0 ) {
				\WP_CLI::line( "Site {$blog_id} ({$url}): deleted {$deleted} revision(s)." );
			}
		}

		\WP_CLI::success( "Deleted {$total} expired revision(s) across " . count( $sites ) . ' site(s).' );
	}

	/**
	 * Get the revision count for the current blog.
	 *
	 * @return int
	 */
	private function get_revision_count(): int {
		global $wpdb;

		$table_name = RevisionDatabase::get_table_name();
		$blog_id    = get_current_blog_id();

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE blog_id = %d",
				$blog_id
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * Halt with an error if the current install is not multisite.
	 *
	 * @param string $flag The flag that requires multisite, for the error message.
	 */
	private function require_multisite( string $flag ): void {
		if ( ! is_multisite() ) {
			\WP_CLI::error( "{$flag} is only available on multisite installs." );
		}
	}
}

\WP_CLI::add_command( 'smr db', CLI::class );
