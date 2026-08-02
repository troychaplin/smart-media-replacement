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
				'schema' => array( $this, 'get_public_item_schema' ),
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
				'items' => $items,
				'total' => (int) $result['total'],
				'pages' => (int) ceil( $result['total'] / $per_page ),
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
			),
		);

		return $this->add_additional_fields_schema( $this->schema );
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
		);
	}
}
