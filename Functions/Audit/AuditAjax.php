<?php
/**
 * Media Audit AJAX Handlers
 *
 * Backs the scan toolbar and the "Used In" popover on the Media Audit screen.
 * Every handler pairs a nonce check with an explicit capability check — the
 * nonce is anti-CSRF only, it is not authorization.
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
 * Class AuditAjax
 *
 * Registers and serves the four admin-ajax endpoints used by the audit UI.
 */
class AuditAjax {

	/**
	 * Nonce action shared by every audit AJAX request.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'smr_audit_nonce';

	/**
	 * Register AJAX hooks.
	 */
	public function __construct() {
		add_action( 'wp_ajax_smr_audit_progress', array( $this, 'handle_progress' ) );
		add_action( 'wp_ajax_smr_audit_start_scan', array( $this, 'handle_scan' ) );
		add_action( 'wp_ajax_smr_audit_locations', array( $this, 'handle_locations' ) );
		add_action( 'wp_ajax_smr_audit_clear_index', array( $this, 'handle_clear_index' ) );
	}

	/**
	 * Verify the request nonce and the caller's capability.
	 *
	 * Sends a JSON error and exits when the capability check fails.
	 */
	private function authorize(): void {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// A nonce is anti-CSRF, not authorization — gate on capability too.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}
	}

	/**
	 * Return the current scan progress snapshot.
	 */
	public function handle_progress(): void {
		$this->authorize();
		wp_send_json_success( BatchRunner::get_progress() );
	}

	/**
	 * Start a fresh full scan.
	 */
	public function handle_scan(): void {
		$this->authorize();
		BatchRunner::start_fresh();
		wp_send_json_success( array( 'message' => 'Scan started' ) );
	}

	/**
	 * Return the source posts referencing a given attachment.
	 *
	 * Gated on manage_options: without it any logged-in user holding a valid
	 * nonce could enumerate titles of private and draft posts.
	 */
	public function handle_locations(): void {
		$this->authorize();

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- absint() is the sanitization, and authorize() above already ran check_ajax_referer().
		$attachment_id = isset( $_GET['attachment_id'] ) ? absint( wp_unslash( $_GET['attachment_id'] ) ) : 0;
		if ( ! $attachment_id ) {
			wp_send_json_error( 'Missing attachment_id' );
		}

		$result   = IndexTable::get_locations( $attachment_id );
		$rows     = $result['rows'];
		$has_more = $result['has_more'];

		// Prime the post cache for the whole set in one query so the per-row
		// get_edit_post_link() calls below don't each trigger a cold lookup.
		$ids = wp_list_pluck( $rows, 'ID' );
		if ( $ids ) {
			_prime_post_caches( array_map( 'intval', $ids ), false, false );
		}

		// Build a capability-aware edit URL server-side so the client doesn't
		// assume a hardcoded /wp-admin path.
		$locations = array_map(
			static function ( $loc ) {
				$edit_url = get_edit_post_link( (int) $loc->ID, 'raw' );

				return array(
					'ID'             => (int) $loc->ID,
					'post_title'     => $loc->post_title,
					'post_type'      => $loc->post_type,
					'reference_type' => $loc->reference_type,
					'edit_url'       => $edit_url ? $edit_url : '',
				);
			},
			$rows
		);

		wp_send_json_success(
			array(
				'locations' => $locations,
				'has_more'  => $has_more,
				'limit'     => IndexTable::LOCATIONS_LIMIT,
			)
		);
	}

	/**
	 * Clear the index, the summary projection and all scan state.
	 */
	public function handle_clear_index(): void {
		$this->authorize();

		BatchRunner::unschedule();
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

		wp_send_json_success( array( 'message' => 'Index cleared' ) );
	}
}
