<?php
/**
 * WP-CLI Commands for the Media Audit
 *
 * The audit index is per-site, and WP-Cron only fires for a site that is
 * receiving traffic — so on a network a quiet subsite's scan never advances
 * on its own. These commands are how a super admin builds indexes across
 * a network without visiting each site's admin.
 *
 * @package Smart_Media_Replacement
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

namespace Smart_Media_Replacement;

use Smart_Media_Replacement\Audit\BatchRunner;
use Smart_Media_Replacement\Audit\IndexTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Builds and inspects the media audit index.
 *
 * ## EXAMPLES
 *
 *     # Build the index for the current site.
 *     $ wp smr audit scan
 *
 *     # Build the index for one subsite.
 *     $ wp smr audit scan --site-id=3
 *
 *     # Build the index for every site on the network.
 *     $ wp smr audit scan --network
 *
 *     # Show index status for the current site.
 *     $ wp smr audit status
 *
 *     # Clear the index for every site on the network.
 *     $ wp smr audit clear --network --yes
 *
 * @when after_wp_load
 */
class AuditCLI extends \WP_CLI_Command {

	/**
	 * Build the media audit index.
	 *
	 * Runs the full scan synchronously rather than scheduling cron ticks —
	 * which is the point of running it from the command line, and mirrors how
	 * `wp smr db cleanup` runs without a time limit.
	 *
	 * ## OPTIONS
	 *
	 * [--site-id=<id>]
	 * : Scan a specific site (multisite only).
	 *
	 * [--network]
	 * : Scan every site on the network (multisite only).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt when using --network.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp smr audit scan
	 *     $ wp smr audit scan --site-id=3
	 *     $ wp smr audit scan --network --yes
	 *
	 * @subcommand scan
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function scan( array $args, array $assoc_args ): void {
		$network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );
		$site_id = \WP_CLI\Utils\get_flag_value( $assoc_args, 'site-id', null );

		if ( $network ) {
			$this->require_multisite( '--network' );
			\WP_CLI::confirm( 'Build the media audit index for every site on the network?', $assoc_args );

			foreach ( $this->network_site_ids() as $blog_id ) {
				switch_to_blog( $blog_id );
				\WP_CLI::log( sprintf( 'Site %d: scanning…', $blog_id ) );
				$scanned = $this->run_scan_to_completion();
				\WP_CLI::log( sprintf( 'Site %d: indexed %d posts.', $blog_id, $scanned ) );
				restore_current_blog();
			}

			\WP_CLI::success( 'Network scan complete.' );
			return;
		}

		if ( null !== $site_id ) {
			$this->require_multisite( '--site-id' );
			switch_to_blog( (int) $site_id );
		}

		$scanned = $this->run_scan_to_completion();

		if ( null !== $site_id ) {
			restore_current_blog();
		}

		\WP_CLI::success( sprintf( 'Scan complete. Indexed %d posts.', $scanned ) );
	}

	/**
	 * Show media audit index status.
	 *
	 * ## OPTIONS
	 *
	 * [--site-id=<id>]
	 * : Report on a specific site (multisite only).
	 *
	 * [--network]
	 * : Report on every site on the network (multisite only).
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp smr audit status
	 *     $ wp smr audit status --network --format=json
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

			$rows = array();
			foreach ( $this->network_site_ids() as $blog_id ) {
				switch_to_blog( $blog_id );
				$rows[] = array( 'site_id' => $blog_id ) + $this->status_row();
				restore_current_blog();
			}

			\WP_CLI\Utils\format_items(
				$format,
				$rows,
				array( 'site_id', 'tables', 'index_built', 'status', 'progress', 'indexed_files', 'unused_files' )
			);
			return;
		}

		if ( null !== $site_id ) {
			$this->require_multisite( '--site-id' );
			switch_to_blog( (int) $site_id );
		}

		$row = $this->status_row();

		if ( null !== $site_id ) {
			restore_current_blog();
		}

		\WP_CLI\Utils\format_items(
			$format,
			array( $row ),
			array( 'tables', 'index_built', 'status', 'progress', 'indexed_files', 'unused_files' )
		);
	}

	/**
	 * Clear the media audit index.
	 *
	 * Removes index and summary rows and resets scan state. The tables
	 * themselves are kept — run a new scan to repopulate them.
	 *
	 * ## OPTIONS
	 *
	 * [--site-id=<id>]
	 * : Clear a specific site (multisite only).
	 *
	 * [--network]
	 * : Clear every site on the network (multisite only).
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp smr audit clear
	 *     $ wp smr audit clear --network --yes
	 *
	 * @subcommand clear
	 * @param array $args       Positional arguments (unused).
	 * @param array $assoc_args Associative arguments.
	 */
	public function clear( array $args, array $assoc_args ): void {
		$network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );
		$site_id = \WP_CLI\Utils\get_flag_value( $assoc_args, 'site-id', null );

		if ( $network ) {
			$this->require_multisite( '--network' );
			\WP_CLI::confirm( 'Clear the media audit index for every site on the network?', $assoc_args );

			foreach ( $this->network_site_ids() as $blog_id ) {
				switch_to_blog( $blog_id );
				$this->clear_site();
				restore_current_blog();
			}

			\WP_CLI::success( 'Network index cleared.' );
			return;
		}

		\WP_CLI::confirm( 'Clear the media audit index?', $assoc_args );

		if ( null !== $site_id ) {
			$this->require_multisite( '--site-id' );
			switch_to_blog( (int) $site_id );
		}

		$this->clear_site();

		if ( null !== $site_id ) {
			restore_current_blog();
		}

		\WP_CLI::success( 'Index cleared.' );
	}

	/**
	 * Run every scan phase to completion in this process.
	 *
	 * BatchRunner::run_batch() normally reschedules itself via WP-Cron. Here we
	 * drive the loop directly, cancelling the event it schedules each pass so
	 * no stray cron entry survives the command.
	 *
	 * @return int Number of posts indexed.
	 */
	private function run_scan_to_completion(): int {
		IndexTable::ensure_tables();
		BatchRunner::start_fresh();

		$progress = BatchRunner::get_progress();
		$total    = (int) ( $progress['total'] ?? 0 );
		$bar      = \WP_CLI\Utils\make_progress_bar( 'Scanning', max( 1, $total ) );
		$last     = 0;

		// Hard ceiling so a pathological state can never spin forever. Each pass
		// handles one bounded chunk, so this allows for a very large library
		// while still terminating.
		$max_passes = 100000;

		for ( $pass = 0; $pass < $max_passes; $pass++ ) {
			BatchRunner::run_batch();
			BatchRunner::unschedule();

			$progress = BatchRunner::get_progress();
			$done     = (int) ( $progress['progress'] ?? 0 );

			if ( $done > $last ) {
				$bar->tick( $done - $last );
				$last = $done;
			}

			if ( 'complete' === ( $progress['status'] ?? '' ) ) {
				break;
			}
		}

		$bar->finish();

		// The scan completes via a chain of single events; make sure none is
		// left pending now that we've driven it synchronously.
		BatchRunner::unschedule();

		return $last;
	}

	/**
	 * Reset the index and scan state for the current site.
	 */
	private function clear_site(): void {
		BatchRunner::unschedule();
		IndexTable::ensure_tables();
		IndexTable::truncate();
		IndexTable::truncate_summary();

		delete_transient( BatchRunner::CURSOR_KEY );
		delete_transient( BatchRunner::SUMMARY_CURSOR_KEY );
		delete_transient( BatchRunner::PHASE_KEY );
		delete_transient( BatchRunner::ATTACHMENT_IDS_KEY );
		delete_option( BatchRunner::INDEX_BUILT_KEY );

		update_option(
			BatchRunner::PROGRESS_KEY,
			array(
				'status'   => 'idle',
				'progress' => 0,
				'total'    => 0,
			),
			false
		);
	}

	/**
	 * Build a status row for the current site.
	 *
	 * @return array<string, string|int>
	 */
	private function status_row(): array {
		global $wpdb;

		$summary = IndexTable::summary_table_name();
		$tables  = $this->tables_exist() ? 'ok' : 'missing';

		$indexed = 0;
		$unused  = 0;
		if ( 'ok' === $tables ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$indexed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$summary}" );
			$unused  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$summary} WHERE usage_count = 0" );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}

		$progress = BatchRunner::get_progress();
		$done     = (int) ( $progress['progress'] ?? 0 );
		$total    = (int) ( $progress['total'] ?? 0 );

		return array(
			'tables'        => $tables,
			'index_built'   => get_option( BatchRunner::INDEX_BUILT_KEY, false ) ? 'yes' : 'no',
			'status'        => (string) ( $progress['status'] ?? 'idle' ),
			'progress'      => sprintf( '%d/%d', $done, $total ),
			'indexed_files' => $indexed,
			'unused_files'  => $unused,
		);
	}

	/**
	 * Whether both audit tables exist for the current site.
	 *
	 * @return bool
	 */
	private function tables_exist(): bool {
		global $wpdb;

		foreach ( array( IndexTable::table_name(), IndexTable::summary_table_name() ) as $table ) {
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( $found !== $table ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * All site IDs on the network.
	 *
	 * @return int[]
	 */
	private function network_site_ids(): array {
		return array_map(
			'intval',
			get_sites(
				array(
					'fields' => 'ids',
					'number' => 0,
				)
			)
		);
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

\WP_CLI::add_command( 'smr audit', AuditCLI::class );
