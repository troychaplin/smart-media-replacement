<?php
/**
 * Media Audit Admin Page
 *
 * Registers the Media > Media Audit screen and enqueues the DataViews app
 * that renders it. The screen is per-site on multisite because the index it
 * displays is per-site.
 *
 * @package Smart_Media_Replacement
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

namespace Smart_Media_Replacement\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AuditPage
 *
 * Owns the admin menu entry, asset enqueue and render callback for the
 * Media Audit screen.
 */
class AuditPage {

	/**
	 * Menu slug for the audit screen.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'smr-audit';

	/**
	 * Screen hook suffix produced by add_submenu_page() under upload.php.
	 *
	 * @var string
	 */
	const HOOK_SUFFIX = 'media_page_' . self::PAGE_SLUG;

	/**
	 * Register admin hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add the Media Audit submenu under Media.
	 */
	public function add_menu(): void {
		add_submenu_page(
			'upload.php',
			__( 'Media Audit', 'smart-media-replacement' ),
			__( 'Media Audit', 'smart-media-replacement' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the audit app on its own screen only.
	 *
	 * @param string $hook Current admin page hook suffix.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( self::HOOK_SUFFIX !== $hook ) {
			return;
		}

		$asset_file = SMART_MEDIA_REPLACEMENT_PLUGIN_PATH . 'build/media-audit.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset   = require $asset_file;
		$js_file = SMART_MEDIA_REPLACEMENT_PLUGIN_PATH . 'build/media-audit.js';

		// During development the content hash lags behind edits, so fall back to
		// the file mtime to guarantee a fresh bundle on every rebuild.
		$version = ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $js_file ) )
			? filemtime( $js_file )
			: $asset['version'];

		wp_enqueue_script(
			'smr-audit',
			SMART_MEDIA_REPLACEMENT_PLUGIN_URL . 'build/media-audit.js',
			$asset['dependencies'],
			$version,
			true
		);

		wp_enqueue_style(
			'smr-audit',
			SMART_MEDIA_REPLACEMENT_PLUGIN_URL . 'build/media-audit.css',
			array( 'wp-components' ),
			$version
		);

		wp_set_script_translations( 'smr-audit', 'smart-media-replacement' );

		wp_add_inline_script(
			'smr-audit',
			'window.smrAuditData = ' . wp_json_encode(
				array(
					'ajaxUrl'         => admin_url( 'admin-ajax.php' ),
					'nonce'           => wp_create_nonce( 'smr_audit_nonce' ),
					'restUrl'         => rest_url( 'smart-media-replacement/v1/audit-media' ),
					'restNonce'       => wp_create_nonce( 'wp_rest' ),
					'initialProgress' => BatchRunner::get_progress(),
					'indexBuilt'      => (bool) get_option( BatchRunner::INDEX_BUILT_KEY, false ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Render the screen wrapper the React app mounts into.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// The audit screen is the first thing a user reaches after a manual
		// table drop, so self-heal here rather than showing an empty list.
		IndexTable::ensure_tables();

		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Media Audit', 'smart-media-replacement' ); ?></h1>
			<div id="smr-audit-root"></div>
		</div>
		<?php
	}
}
