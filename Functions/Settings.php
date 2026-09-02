<?php
/**
 * Settings Resolver
 *
 * Single source of truth for plugin option reads/writes. Routes to
 * network options on multisite, site options on single-site, so the
 * rest of the plugin never has to branch on context.
 *
 * @package Smart_Media_Replacement
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

namespace Smart_Media_Replacement;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Settings
 *
 * Resolves option reads and writes against either network or site storage
 * depending on whether the install is multisite. On multisite the plugin
 * is network-activate-only (enforced by the `Network: true` plugin header),
 * so a single network-wide value applies to every site.
 */
class Settings {

	/**
	 * Known option keys with their hardcoded defaults.
	 *
	 * Used by the network settings save handler to know which keys to
	 * accept and how to default missing values. Centralized here so the
	 * defaults don't drift between the render callbacks and the handler.
	 *
	 * @var array<string, mixed>
	 */
	const DEFAULTS = array(
		'smr_enable_revisions'           => true,
		'smr_revision_file_types'        => 'documents',
		'smr_max_revisions'              => 10,
		'smr_retention_days'             => 0,
		'smr_default_version_type'       => 'minor',
		'smr_require_comment'            => false,
		'smr_delete_files_on_deactivate' => false,
		'smr_delete_data_on_deactivate'  => false,
		'smr_table_check_frequency'      => 'daily',
		'smr_enable_audit'               => true,
	);

	/**
	 * Get a plugin option value.
	 *
	 * @param string $key     Option key.
	 * @param mixed  $fallback Default value if option is not set.
	 * @return mixed
	 */
	public static function get( string $key, $fallback = false ) {
		if ( is_multisite() ) {
			return get_site_option( $key, $fallback );
		}
		return get_option( $key, $fallback );
	}

	/**
	 * Update a plugin option value.
	 *
	 * @param string $key   Option key.
	 * @param mixed  $value Value to store.
	 * @return bool Whether the update succeeded.
	 */
	public static function update( string $key, $value ): bool {
		if ( is_multisite() ) {
			/*
			 * update_site_option() delegates to core's update_network_option(),
			 * which reads its "old value" with a literal false default. When the
			 * row does not exist yet and $value is itself false (an unchecked
			 * checkbox), core's `$value === $old_value` guard fires and the
			 * write is dropped before the add_network_option() branch runs — so
			 * the first attempt to turn a default-on setting off never persists.
			 * Single-site update_option() sidesteps this via the typed default
			 * register_setting() installs; network options have no such API, so
			 * create the row explicitly here.
			 */
			if ( ! self::site_option_exists( $key ) ) {
				return add_site_option( $key, $value );
			}
			return update_site_option( $key, $value );
		}
		return update_option( $key, $value );
	}

	/**
	 * Whether a network option row currently exists.
	 *
	 * There is no get_site_option() return that distinguishes "missing" from a
	 * stored falsy value, so probe with a fresh object as the default: only a
	 * genuinely absent option comes back as that exact instance.
	 *
	 * @param string $key Option key.
	 * @return bool
	 */
	private static function site_option_exists( string $key ): bool {
		$sentinel = new \stdClass();
		return get_site_option( $key, $sentinel ) !== $sentinel;
	}

	/**
	 * Delete a plugin option.
	 *
	 * @param string $key Option key.
	 * @return bool Whether the deletion succeeded.
	 */
	public static function delete( string $key ): bool {
		if ( is_multisite() ) {
			return delete_site_option( $key );
		}
		return delete_option( $key );
	}

	/**
	 * Seed defaults on activation for any option that is currently unset.
	 *
	 * Uses null as the "missing" sentinel rather than false. get_option() and
	 * get_site_option() return the supplied default only when the key is
	 * absent, so null distinguishes "never set" from a legitimately stored
	 * falsy value — which matters for the boolean settings that default to
	 * true and would otherwise risk being re-enabled on reactivation.
	 */
	public static function seed_defaults(): void {
		foreach ( self::DEFAULTS as $key => $fallback ) {
			if ( null === self::get( $key, null ) ) {
				self::update( $key, $fallback );
			}
		}
	}

	/**
	 * Remove all plugin options.
	 *
	 * Used by uninstall / deactivation cleanup paths to wipe stored
	 * configuration. Iterates the known keys rather than scanning storage,
	 * so we never delete an option that doesn't belong to this plugin.
	 */
	public static function delete_all(): void {
		foreach ( array_keys( self::DEFAULTS ) as $key ) {
			self::delete( $key );
		}
	}
}
