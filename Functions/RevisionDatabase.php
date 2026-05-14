<?php
/**
 * Revision Database Handler
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
 * Class RevisionDatabase
 *
 * Handles all database operations for media revisions.
 */
class RevisionDatabase {

	/**
	 * Database version for migrations.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * Option name for database version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'smr_db_version';

	/**
	 * Get the revisions table name.
	 *
	 * @return string
	 */
	public static function get_table_name(): string {
		global $wpdb;
		return $wpdb->base_prefix . 'smr_revisions';
	}

	/**
	 * Create the revisions table.
	 *
	 * @return bool Whether table was created successfully.
	 */
	public static function create_table(): bool {
		global $wpdb;

		$table_name      = self::get_table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			blog_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 1,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			version VARCHAR(20) NOT NULL,
			version_major INT(11) UNSIGNED NOT NULL DEFAULT 1,
			version_minor INT(11) UNSIGNED NOT NULL DEFAULT 0,
			file_path VARCHAR(255) NOT NULL,
			file_size BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			mime_type VARCHAR(100) NOT NULL,
			comment TEXT,
			user_id BIGINT(20) UNSIGNED NOT NULL,
			created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY (id),
			KEY blog_attachment (blog_id, attachment_id),
			KEY blog_created (blog_id, created_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );

		// Verify table exists.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;

		if ( $table_exists ) {
			update_option( self::DB_VERSION_OPTION, self::DB_VERSION );
		}

		return $table_exists;
	}

	/**
	 * Drop the revisions table.
	 *
	 * @return bool Whether table was dropped successfully.
	 */
	public static function drop_table(): bool {
		global $wpdb;

		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$result = $wpdb->query( "DROP TABLE IF EXISTS {$table_name}" );

		if ( false !== $result ) {
			delete_option( self::DB_VERSION_OPTION );
			return true;
		}

		return false;
	}

	/**
	 * Check if table exists.
	 *
	 * @return bool
	 */
	public static function table_exists(): bool {
		global $wpdb;
		$table_name = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) ) === $table_name;
	}

	/**
	 * Insert a new revision.
	 *
	 * @param array $data Revision data.
	 * @return int|false Revision ID or false on failure.
	 */
	public static function insert( array $data ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();

		$defaults = array(
			'blog_id'       => $blog_id,
			'attachment_id' => 0,
			'version'       => '1.0',
			'version_major' => 1,
			'version_minor' => 0,
			'file_path'     => '',
			'file_size'     => 0,
			'mime_type'     => '',
			'comment'       => '',
			'user_id'       => get_current_user_id(),
			'created_at'    => current_time( 'mysql' ),
		);

		$data = wp_parse_args( $data, $defaults );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$result = $wpdb->insert(
			$table_name,
			array(
				'blog_id'       => $data['blog_id'],
				'attachment_id' => $data['attachment_id'],
				'version'       => $data['version'],
				'version_major' => $data['version_major'],
				'version_minor' => $data['version_minor'],
				'file_path'     => $data['file_path'],
				'file_size'     => $data['file_size'],
				'mime_type'     => $data['mime_type'],
				'comment'       => $data['comment'],
				'user_id'       => $data['user_id'],
				'created_at'    => $data['created_at'],
			),
			array( '%d', '%d', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%d', '%s' )
		);

		if ( $result ) {
			return $wpdb->insert_id;
		}

		return false;
	}

	/**
	 * Get a single revision by ID.
	 *
	 * @param int $revision_id Revision ID.
	 * @return object|null Revision object or null.
	 */
	public static function get( int $revision_id ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE id = %d AND blog_id = %d",
				$revision_id,
				$blog_id
			)
		);
	}

	/**
	 * Get all revisions for an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $order         Order direction (ASC or DESC).
	 * @param int    $limit         Maximum number of revisions to return (0 = unlimited).
	 * @return array Array of revision objects.
	 */
	public static function get_by_attachment( int $attachment_id, string $order = 'DESC', int $limit = 0 ): array {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();
		$order      = 'ASC' === strtoupper( $order ) ? 'ASC' : 'DESC';

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$sql = $wpdb->prepare(
			"SELECT * FROM {$table_name} WHERE blog_id = %d AND attachment_id = %d ORDER BY version_major {$order}, version_minor {$order}",
			$blog_id,
			$attachment_id
		);

		if ( $limit > 0 ) {
			$sql .= $wpdb->prepare( ' LIMIT %d', $limit );
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( $sql );
	}

	/**
	 * Get the latest revision for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return object|null Revision object or null.
	 */
	public static function get_latest( int $attachment_id ) {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE blog_id = %d AND attachment_id = %d ORDER BY version_major DESC, version_minor DESC LIMIT 1",
				$blog_id,
				$attachment_id
			)
		);
	}

	/**
	 * Get revision count for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Number of revisions.
	 */
	public static function get_count( int $attachment_id ): int {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table_name} WHERE blog_id = %d AND attachment_id = %d",
				$blog_id,
				$attachment_id
			)
		);
	}

	/**
	 * Delete a single revision.
	 *
	 * @param int $revision_id Revision ID.
	 * @return bool Whether deletion was successful.
	 */
	public static function delete( int $revision_id ): bool {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table_name,
			array(
				'id'      => $revision_id,
				'blog_id' => $blog_id,
			),
			array( '%d', '%d' )
		);

		return (bool) $result;
	}

	/**
	 * Delete all revisions for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Number of revisions deleted.
	 */
	public static function delete_by_attachment( int $attachment_id ): int {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table_name,
			array(
				'attachment_id' => $attachment_id,
				'blog_id'       => $blog_id,
			),
			array( '%d', '%d' )
		);

		return $result ? $result : 0;
	}

	/**
	 * Delete all revisions for current blog.
	 *
	 * @return int Number of revisions deleted.
	 */
	public static function delete_by_blog(): int {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->delete(
			$table_name,
			array( 'blog_id' => $blog_id ),
			array( '%d' )
		);

		return $result ? $result : 0;
	}

	/**
	 * Get oldest revisions exceeding the limit.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $max_revisions Maximum revisions to keep.
	 * @return array Array of revision IDs to delete.
	 */
	public static function get_excess_revisions( int $attachment_id, int $max_revisions ): array {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();

		// Get total count.
		$count = self::get_count( $attachment_id );

		if ( $count <= $max_revisions ) {
			return array();
		}

		$excess = $count - $max_revisions;

		// Get oldest revisions to delete.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$revisions = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT id FROM {$table_name} WHERE blog_id = %d AND attachment_id = %d ORDER BY version_major ASC, version_minor ASC LIMIT %d",
				$blog_id,
				$attachment_id,
				$excess
			)
		);

		return array_map( 'intval', $revisions );
	}

	/**
	 * Get revisions older than specified days.
	 *
	 * @param int $days Number of days.
	 * @return array Array of revision objects.
	 */
	public static function get_expired_revisions( int $days ): array {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();
		$cutoff     = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table_name} WHERE blog_id = %d AND created_at < %s",
				$blog_id,
				$cutoff
			)
		);
	}

	/**
	 * Get total storage used by revisions for an attachment.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return int Total size in bytes.
	 */
	public static function get_attachment_storage( int $attachment_id ): int {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(file_size) FROM {$table_name} WHERE blog_id = %d AND attachment_id = %d",
				$blog_id,
				$attachment_id
			)
		);
	}

	/**
	 * Get total storage used by all revisions for current blog.
	 *
	 * @return int Total size in bytes.
	 */
	public static function get_total_storage(): int {
		global $wpdb;

		$table_name = self::get_table_name();
		$blog_id    = get_current_blog_id();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(file_size) FROM {$table_name} WHERE blog_id = %d",
				$blog_id
			)
		);
	}
}
