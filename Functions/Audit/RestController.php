<?php
/**
 * Media Audit REST Controller
 *
 * Read-only collection endpoint backing the Media Audit screen.
 *
 * @package Smart_Media_Replacement
 */

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase
// phpcs:disable WordPress.Files.FileName.InvalidClassFileName

namespace Smart_Media_Replacement\Audit;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use WP_REST_Controller;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Class RestController
 *
 * Serves GET /smart-media-replacement/v1/audit-media, the paginated,
 * filterable attachment list the Media Audit screen renders.
 */
class RestController extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'smart-media-replacement/v1';

	/**
	 * REST base.
	 *
	 * Prefixed with "audit-" so it can never collide with a future generic
	 * media route in the same namespace.
	 *
	 * @var string
	 */
	protected $rest_base = 'audit-media';

	/**
	 * Attachment IDs whose cached file size needs persisting after this request.
	 *
	 * @var int[]
	 */
	private $backfill_ids = array();

	/**
	 * Maximum attachments deleted in a single request.
	 *
	 * Deletion touches the filesystem per file, so a large queue is walked in
	 * batches by the client rather than in one long-running request.
	 *
	 * @var int
	 */
	const DELETE_BATCH_LIMIT = 100;

	/**
	 * Register the collection route.
	 */
	public function register_routes(): void {
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => $this->get_collection_params(),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
					'args'                => $this->get_delete_params(),
				),
				'schema' => array( $this, 'get_public_item_schema' ),
			)
		);

		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/mark',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'mark_items' ),
					'permission_callback' => array( $this, 'delete_items_permissions_check' ),
					'args'                => $this->get_mark_params(),
				),
			)
		);
	}

	/**
	 * Check whether the current user may read the audit index.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function get_items_permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return current_user_can( 'manage_options' );
	}

	/**
	 * Return a page of audited attachments.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$page = (int) $request->get_param( 'page' );
		$page = $page > 0 ? $page : 1;

		$per_page = (int) $request->get_param( 'per_page' );
		$per_page = $per_page > 0 ? min( 100, $per_page ) : 20;

		$search         = sanitize_text_field( (string) $request->get_param( 'search' ) );
		$orderby        = sanitize_key( (string) $request->get_param( 'orderby' ) );
		$orderby        = '' !== $orderby ? $orderby : 'date';
		$raw_order      = strtoupper( (string) $request->get_param( 'order' ) );
		$order          = in_array( $raw_order, array( 'ASC', 'DESC' ), true ) ? $raw_order : 'DESC';
		$media_type     = sanitize_text_field( (string) $request->get_param( 'media_type' ) );
		$reference_type = sanitize_key( (string) $request->get_param( 'reference_type' ) );
		$usage_filter   = sanitize_key( (string) $request->get_param( 'usage_filter' ) );
		$missing_alt    = (bool) $request->get_param( 'missing_alt' );
		$marked         = sanitize_key( (string) $request->get_param( 'marked' ) );

		$result = IndexTable::get_attachments_rest(
			search: $search,
			per_page: $per_page,
			page: $page,
			orderby: $orderby,
			order: $order,
			media_type: $media_type,
			reference_type: $reference_type,
			usage_filter: $usage_filter,
			missing_alt: $missing_alt,
			marked: $marked,
		);

		// Prime the post + meta caches for the whole page in two batched queries
		// so the per-row lookups in prepare_row() (image src, attachment URL,
		// title, edit link) don't each trigger a cold DB read — the N+1 that a
		// raw $wpdb result set otherwise incurs vs. WP_Query.
		$ids = array_map( static fn( $row ) => (int) $row->ID, $result['items'] );
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
			update_meta_cache( 'post', $ids );
		}

		$this->backfill_ids = array();

		$items = array_map( array( $this, 'prepare_row' ), $result['items'] );

		// Persisting a recomputed file size is a write, and this is a GET. Hand
		// the affected IDs to a one-shot cron event instead so the read path
		// stays read-only and the response isn't slowed by the writes.
		if ( $this->backfill_ids ) {
			BatchRunner::schedule_filesize_backfill( $this->backfill_ids );
			$this->backfill_ids = array();
		}

		return new WP_REST_Response(
			array(
				'items'        => $items,
				'total'        => (int) $result['total'],
				'pages'        => (int) ceil( $result['total'] / $per_page ),
				// Size of the review queue regardless of the active filters, so
				// the toolbar can report it without a second request.
				'marked_total' => IndexTable::marked_count(),
			)
		);
	}

	/**
	 * Map one raw index row to the response shape.
	 *
	 * Deliberately not named prepare_item_for_response(): this takes a raw
	 * $wpdb row rather than a post object, so it does not fulfil the
	 * WP_REST_Controller contract of that name.
	 *
	 * @param object $row Raw summary row.
	 * @return array
	 */
	private function prepare_row( object $row ): array {
		$id   = (int) $row->ID;
		$mime = $row->post_mime_type ?? '';

		if ( str_starts_with( $mime, 'image/' ) ) {
			$media_type = 'Image';
		} elseif ( str_starts_with( $mime, 'video/' ) ) {
			$media_type = 'Video';
		} elseif ( str_starts_with( $mime, 'audio/' ) ) {
			$media_type = 'Audio';
		} else {
			$media_type = 'Document';
		}

		$thumb_src     = wp_get_attachment_image_src( $id, array( 60, 60 ) );
		$thumbnail_url = $thumb_src ? $thumb_src[0] : '';

		// File size and alt text are already selected by the items query
		// (joined from postmeta), so no per-row get_post_meta() is needed here.
		$cached    = isset( $row->file_size ) ? (int) $row->file_size : 0;
		$file_size = $cached;
		if ( ! $file_size ) {
			$meta = wp_get_attachment_metadata( $id );
			if ( is_array( $meta ) && ! empty( $meta['filesize'] ) ) {
				$file_size = (int) $meta['filesize'];
			}
		}
		if ( ! $file_size ) {
			$path = get_attached_file( $id );
			if ( $path && file_exists( $path ) ) {
				$file_size = (int) filesize( $path );
			}
		}
		if ( $file_size > 0 && ! $cached ) {
			// Queued rather than written here — see get_items().
			$this->backfill_ids[] = $id;
		}

		$alt_text = $row->alt_text ?? '';

		return array(
			'id'                  => $id,
			'title'               => get_the_title( $id ),
			'mime_type'           => $mime,
			'media_type'          => $media_type,
			'thumbnail_url'       => $thumbnail_url,
			'file_url'            => (string) wp_get_attachment_url( $id ),
			'edit_url'            => (string) get_edit_post_link( $id, 'raw' ),
			'file_size'           => $file_size,
			'alt_text'            => (string) $alt_text,
			'content_alt_missing' => (bool) ( $row->content_alt_missing ?? false ),
			'date'                => get_post_field( 'post_date', $id ),
			'usage_count'         => (int) $row->usage_count,
			'marked_for_deletion' => (bool) ( $row->marked_for_deletion ?? false ),
		);
	}

	/**
	 * Schema for a single audited attachment.
	 *
	 * @return array
	 */
	public function get_item_schema(): array {
		if ( $this->schema ) {
			return $this->add_additional_fields_schema( $this->schema );
		}

		$this->schema = array(
			'$schema'    => 'http://json-schema.org/draft-04/schema#',
			'title'      => 'smr-audit-media',
			'type'       => 'object',
			'properties' => array(
				'id'                  => array(
					'description' => __( 'Attachment ID.', 'smart-media-replacement' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'title'               => array(
					'description' => __( 'Attachment title.', 'smart-media-replacement' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'mime_type'           => array(
					'description' => __( 'Attachment MIME type.', 'smart-media-replacement' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'media_type'          => array(
					'description' => __( 'Coarse media category.', 'smart-media-replacement' ),
					'type'        => 'string',
					'enum'        => array( 'Image', 'Video', 'Audio', 'Document' ),
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'thumbnail_url'       => array(
					'description' => __( 'URL of a 60x60 preview, empty for non-images.', 'smart-media-replacement' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'file_url'            => array(
					'description' => __( 'URL of the attached file.', 'smart-media-replacement' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'edit_url'            => array(
					'description' => __( 'Admin edit URL for the attachment.', 'smart-media-replacement' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'file_size'           => array(
					'description' => __( 'File size in bytes.', 'smart-media-replacement' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'alt_text'            => array(
					'description' => __( 'Alt text stored on the attachment.', 'smart-media-replacement' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'content_alt_missing' => array(
					'description' => __( 'Whether any reference embeds this file without alt text.', 'smart-media-replacement' ),
					'type'        => 'boolean',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'date'                => array(
					'description' => __( 'Date the attachment was uploaded.', 'smart-media-replacement' ),
					'type'        => 'string',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'usage_count'         => array(
					'description' => __( 'Number of posts referencing this attachment.', 'smart-media-replacement' ),
					'type'        => 'integer',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
				'marked_for_deletion' => array(
					'description' => __( 'Whether the file is queued for deletion.', 'smart-media-replacement' ),
					'type'        => 'boolean',
					'context'     => array( 'view' ),
					'readonly'    => true,
				),
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
	}

	/**
	 * Check whether the current user may change or delete audited attachments.
	 *
	 * Screen-level gate only. Deletion is additionally checked per attachment in
	 * delete_items(), because manage_options does not imply the right to delete
	 * any particular post.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return bool
	 */
	public function delete_items_permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		return current_user_can( 'manage_options' ) && current_user_can( 'upload_files' );
	}

	/**
	 * Mark or unmark attachments for deletion.
	 *
	 * Accepts either an explicit list of IDs or `all_matching`, which applies
	 * the same filters the list is showing. The filter form exists because
	 * DataViews prunes its selection to the current page, so a cross-page
	 * "select all" cannot be expressed as an ID list from the client.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function mark_items( $request ) {
		$marked       = (bool) $request->get_param( 'marked' );
		$all_matching = (bool) $request->get_param( 'all_matching' );

		if ( $all_matching ) {
			$result = IndexTable::mark_matching(
				$marked,
				sanitize_text_field( (string) $request->get_param( 'search' ) ),
				sanitize_text_field( (string) $request->get_param( 'media_type' ) ),
				sanitize_key( (string) $request->get_param( 'reference_type' ) ),
				sanitize_key( (string) $request->get_param( 'usage_filter' ) ),
				(bool) $request->get_param( 'missing_alt' ),
				sanitize_key( (string) $request->get_param( 'marked_filter' ) )
			);

			return new WP_REST_Response(
				array(
					'count'        => $result['count'],
					'total'        => $result['total'],
					'capped'       => $result['capped'],
					'limit'        => IndexTable::MARK_MATCHING_LIMIT,
					'marked_total' => IndexTable::marked_count(),
				)
			);
		}

		$ids = array_map( 'intval', (array) $request->get_param( 'ids' ) );
		$ids = array_values( array_filter( $ids ) );

		if ( ! $ids ) {
			return new \WP_Error(
				'smr_audit_no_ids',
				__( 'No attachments were supplied.', 'smart-media-replacement' ),
				array( 'status' => 400 )
			);
		}

		// Marking is a precursor to deletion, so gate it on the same capability
		// rather than letting a user queue files they could never delete.
		$ids = array_values(
			array_filter(
				$ids,
				static fn( $id ) => current_user_can( 'delete_post', $id )
			)
		);

		$count = IndexTable::set_marked( $ids, $marked );

		return new WP_REST_Response(
			array(
				'count'        => $count,
				'ids'          => $ids,
				'marked_total' => IndexTable::marked_count(),
			)
		);
	}

	/**
	 * Permanently delete attachments.
	 *
	 * This is where the "unused files only" rule actually lives. The screen has
	 * always hidden the control for in-use files, but that is presentation; a
	 * request that reaches here is checked against the index before anything is
	 * deleted, and an attachment with no summary row is refused rather than
	 * assumed unused.
	 *
	 * @param \WP_REST_Request $request Request object.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function delete_items( $request ) {
		if ( (bool) $request->get_param( 'marked' ) ) {
			$ids = IndexTable::get_marked_ids( self::DELETE_BATCH_LIMIT );

			// An empty queue is a successful no-op, not a client error. The
			// client drains the queue a batch at a time and cannot know in
			// advance which pass is the last, so the pass that finds nothing
			// left must not come back as a failure.
			if ( ! $ids ) {
				return new WP_REST_Response(
					array(
						'deleted'      => array(),
						'skipped'      => array(),
						'marked_total' => 0,
					)
				);
			}
		} else {
			$ids = array_map( 'intval', (array) $request->get_param( 'ids' ) );
			$ids = array_values( array_filter( $ids ) );

			// An explicit call naming no attachments really is a bad request.
			if ( ! $ids ) {
				return new \WP_Error(
					'smr_audit_no_ids',
					__( 'No attachments were supplied.', 'smart-media-replacement' ),
					array( 'status' => 400 )
				);
			}
		}

		if ( count( $ids ) > self::DELETE_BATCH_LIMIT ) {
			$ids = array_slice( $ids, 0, self::DELETE_BATCH_LIMIT );
		}

		$usage   = IndexTable::usage_counts_for( $ids );
		$deleted = array();
		$skipped = array();

		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'delete_post', $id ) ) {
				$skipped[] = array(
					'id'     => $id,
					'reason' => 'forbidden',
				);
				continue;
			}

			if ( ! array_key_exists( $id, $usage ) ) {
				// No summary row means usage is unknown, not zero. Refuse.
				$skipped[] = array(
					'id'     => $id,
					'reason' => 'not_indexed',
				);
				continue;
			}

			if ( $usage[ $id ] > 0 ) {
				$skipped[] = array(
					'id'     => $id,
					'reason' => 'in_use',
					'usage'  => $usage[ $id ],
				);
				continue;
			}

			if ( 'attachment' !== get_post_type( $id ) ) {
				$skipped[] = array(
					'id'     => $id,
					'reason' => 'not_attachment',
				);
				continue;
			}

			// force_delete respects MEDIA_TRASH: when a site has enabled the
			// media trash, pass false so files land there and stay recoverable.
			$force = ! ( defined( 'MEDIA_TRASH' ) && MEDIA_TRASH );

			if ( wp_delete_attachment( $id, $force ) ) {
				$deleted[] = $id;
			} else {
				$skipped[] = array(
					'id'     => $id,
					'reason' => 'delete_failed',
				);
			}
		}

		return new WP_REST_Response(
			array(
				'deleted'      => $deleted,
				'skipped'      => $skipped,
				'marked_total' => IndexTable::marked_count(),
			)
		);
	}

	/**
	 * Parameters accepted by the mark endpoint.
	 *
	 * @return array
	 */
	public function get_mark_params(): array {
		return array(
			'ids'            => array(
				'type'    => 'array',
				'items'   => array( 'type' => 'integer' ),
				'default' => array(),
			),
			'marked'         => array(
				'type'     => 'boolean',
				'required' => true,
			),
			'all_matching'   => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'search'         => array(
				'type'    => 'string',
				'default' => '',
			),
			'media_type'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'reference_type' => array(
				'type'    => 'string',
				'default' => '',
			),
			'usage_filter'   => array(
				'type'    => 'string',
				'default' => '',
			),
			'missing_alt'    => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'marked_filter'  => array(
				'type'    => 'string',
				'default' => '',
				'enum'    => array( '', 'marked', 'unmarked' ),
			),
		);
	}

	/**
	 * Parameters accepted by the delete endpoint.
	 *
	 * @return array
	 */
	public function get_delete_params(): array {
		return array(
			'ids'    => array(
				'type'    => 'array',
				'items'   => array( 'type' => 'integer' ),
				'default' => array(),
			),
			'marked' => array(
				'type'    => 'boolean',
				'default' => false,
			),
		);
	}

	/**
	 * Query parameters accepted by the collection endpoint.
	 *
	 * @return array
	 */
	public function get_collection_params(): array {
		return array(
			'page'           => array(
				'type'    => 'integer',
				'default' => 1,
				'minimum' => 1,
			),
			'per_page'       => array(
				'type'    => 'integer',
				'default' => 20,
				'minimum' => 1,
				'maximum' => 100,
			),
			'search'         => array(
				'type'    => 'string',
				'default' => '',
			),
			'orderby'        => array(
				'type'    => 'string',
				'default' => 'date',
				'enum'    => array( 'title', 'date', 'usage', 'file_size' ),
			),
			'order'          => array(
				'type'    => 'string',
				'default' => 'DESC',
				'enum'    => array( 'ASC', 'DESC' ),
			),
			'media_type'     => array(
				'type'    => 'string',
				'default' => '',
			),
			'reference_type' => array(
				'type'    => 'string',
				'default' => '',
			),
			'usage_filter'   => array(
				'type'    => 'string',
				'default' => '',
			),
			'missing_alt'    => array(
				'type'    => 'boolean',
				'default' => false,
			),
			'marked'         => array(
				'type'    => 'string',
				'default' => '',
				'enum'    => array( '', 'marked', 'unmarked' ),
			),
		);
	}
}
