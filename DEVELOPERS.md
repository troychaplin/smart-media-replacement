# Smart Media Replacement — Developer Reference

This document covers all available hooks (actions and filters) exposed by Smart Media Replacement for custom integrations. These hooks let you extend or modify plugin behavior from a theme, a companion plugin, or a must-use plugin — without touching the plugin's own files.

---

## Table of Contents

- [WP-CLI Commands](#wp-cli-commands)
  - [wp smr db check](#wp-smr-db-check)
  - [wp smr db repair](#wp-smr-db-repair)
  - [wp smr db status](#wp-smr-db-status)
  - [wp smr db cleanup](#wp-smr-db-cleanup)
  - [wp smr audit scan](#wp-smr-audit-scan)
  - [wp smr audit status](#wp-smr-audit-status)
  - [wp smr audit clear](#wp-smr-audit-clear)
- [REST API](#rest-api)
- [Filter Hooks](#filter-hooks)
  - [smart_media_replacement_create_revision](#smart_media_replacement_create_revision)
  - [smart_media_replacement_max_revisions](#smart_media_replacement_max_revisions)
  - [smart_media_replacement_retention_days](#smart_media_replacement_retention_days)
  - [smart_media_replacement_cleanup_time_limit](#smart_media_replacement_cleanup_time_limit)
  - [smart_media_replacement_cleanup_chunk_size](#smart_media_replacement_cleanup_chunk_size)
  - [smart_media_replacement_revision_directory](#smart_media_replacement_revision_directory)
  - [smart_media_replacement_enforce_dimensions](#smart_media_replacement_enforce_dimensions)
  - [smart_media_replacement_audit_scanned_meta_keys](#smart_media_replacement_audit_scanned_meta_keys)
  - [smart_media_replacement_audit_scan_post_types](#smart_media_replacement_audit_scan_post_types)
  - [smart_media_replacement_audit_scan_statuses](#smart_media_replacement_audit_scan_statuses)
  - [smart_media_replacement_audit_batch_size](#smart_media_replacement_audit_batch_size)
- [Action Hooks](#action-hooks)
  - [smart_media_replacement_before_replace](#smart_media_replacement_before_replace)
  - [smart_media_replacement_file_replaced](#smart_media_replacement_file_replaced)
  - [smart_media_replacement_revision_created](#smart_media_replacement_revision_created)
  - [smart_media_replacement_revision_restored](#smart_media_replacement_revision_restored)
  - [smart_media_replacement_revisions_cleaned](#smart_media_replacement_revisions_cleaned)

---

## REST API

### `GET /smart-media-replacement/v1/audit-media`

Paginated, filterable list of audited attachments. Backs the Media Audit screen.

**Capability:** `manage_options`

| Parameter | Type | Default | Notes |
|-----------|------|---------|-------|
| `page` | integer | `1` | 1-based page number |
| `per_page` | integer | `20` | Maximum 100 |
| `search` | string | `''` | Filename substring |
| `orderby` | string | `date` | `title`, `date`, `usage`, `file_size` |
| `order` | string | `DESC` | `ASC` or `DESC` |
| `media_type` | string | `''` | `Image`, `Video`, `Audio`, `Document` |
| `reference_type` | string | `''` | `block`, `featured_image`, `classic`, `postmeta` |
| `usage_filter` | string | `''` | `used` or `unused` |
| `missing_alt` | boolean | `false` | Restrict to references embedded without alt text |

**Response:**

```json
{
  "items": [
    {
      "id": 123,
      "title": "annual-report",
      "mime_type": "application/pdf",
      "media_type": "Document",
      "thumbnail_url": "",
      "file_url": "https://example.com/wp-content/uploads/2026/01/annual-report.pdf",
      "edit_url": "https://example.com/wp-admin/post.php?post=123&action=edit",
      "file_size": 482913,
      "alt_text": "",
      "content_alt_missing": false,
      "date": "2026-01-14 09:22:41",
      "usage_count": 3
    }
  ],
  "total": 412,
  "pages": 21
}
```

The endpoint reads the denormalized summary table — a flat indexed scan with no `GROUP BY` and no postmeta join — and primes the post and meta caches for the whole page in two batched queries before mapping rows, avoiding the N+1 a raw `$wpdb` result set would otherwise incur.

Deletions are not handled here. The client uses core's `DELETE /wp/v2/media/<id>?force=true`.

---

## Database Tables

The plugin owns two independent storage areas, and they scope differently on multisite. This is deliberate:

| | Revisions | Media audit |
|---|---|---|
| Table(s) | `{base_prefix}smr_revisions` | `{prefix}smr_audit_index`, `{prefix}smr_audit_summary` |
| Multisite scope | One shared network table with a `blog_id` column | One pair of tables per site |
| Provisioned by | Network activation | Network activation, `wp_initialize_site`, and a lazy guard on first use |
| Removed by | Deactivation opt-in, uninstall | `wpmu_drop_tables` on site deletion, deactivation opt-in, uninstall |

Revisions are *authored content*: permanent, small, and the network settings page reports network-wide totals, so a single shared table with a `blog_id` filter is the right shape. The audit index is a *derived cache* of one site's `wp_posts` — rebuilt on demand, proportional to that site's content, and it must vanish when the site is deleted. A shared audit table would put an entire network's reference graph in one place for no benefit, and would add a `blog_id` predicate to every query in `IndexTable`.

Note that core's `wp_uninitialize_site()` only drops its own fixed table list, which is why the audit tables need the explicit `wpmu_drop_tables` filter.

---

## Filter Hooks

Filters let you intercept a value the plugin is about to use and return a modified version of it.

---

### `smart_media_replacement_create_revision`

Controls whether a revision should be created for a given attachment. Return `false` to skip revision creation entirely for that attachment.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$should_create` | `bool` | Whether to create a revision. Default `true`. |
| `$attachment_id` | `int` | The attachment ID being replaced. |

**Default:** `true`

**File:** `Functions/RevisionManager.php`

---

**Example: Disable revisions for a specific attachment**

```php
add_filter( 'smart_media_replacement_create_revision', function( bool $should_create, int $attachment_id ): bool {
    if ( 42 === $attachment_id ) {
        return false;
    }
    return $should_create;
}, 10, 2 );
```

---

**Example: Disable revisions for PDFs**

```php
add_filter( 'smart_media_replacement_create_revision', function( bool $should_create, int $attachment_id ): bool {
    $mime = get_post_mime_type( $attachment_id );
    if ( 'application/pdf' === $mime ) {
        return false;
    }
    return $should_create;
}, 10, 2 );
```

---

**Example: Disable revisions outside business hours (useful for scheduled imports)**

```php
add_filter( 'smart_media_replacement_create_revision', function( bool $should_create, int $attachment_id ): bool {
    $hour = (int) current_time( 'G' );
    if ( $hour >= 2 && $hour < 4 ) {
        // Skip revisions during nightly automated import window.
        return false;
    }
    return $should_create;
}, 10, 2 );
```

---

**Example: Disable revisions for attachments in a specific media category (if using a taxonomy)**

```php
add_filter( 'smart_media_replacement_create_revision', function( bool $should_create, int $attachment_id ): bool {
    if ( has_term( 'stock-assets', 'media_category', $attachment_id ) ) {
        return false;
    }
    return $should_create;
}, 10, 2 );
```

---

### `smart_media_replacement_max_revisions`

Filters the maximum number of revisions to retain per attachment. When the limit is exceeded, the oldest revisions are deleted automatically.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$max_revisions` | `int` | Max revisions from plugin settings. Default `10`. |
| `$attachment_id` | `int` | The attachment ID. |

**Default:** `10` (or whatever is set in plugin settings)

**File:** `Functions/RevisionManager.php`

---

**Example: Increase the limit for video attachments**

```php
add_filter( 'smart_media_replacement_max_revisions', function( int $max, int $attachment_id ): int {
    if ( wp_attachment_is( 'video', $attachment_id ) ) {
        return 25;
    }
    return $max;
}, 10, 2 );
```

---

**Example: Give featured images a higher revision limit**

```php
add_filter( 'smart_media_replacement_max_revisions', function( int $max, int $attachment_id ): int {
    global $wpdb;
    $is_featured = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND meta_value = %d",
            $attachment_id
        )
    );
    if ( $is_featured ) {
        return 20;
    }
    return $max;
}, 10, 2 );
```

---

**Example: Cap revisions to 3 for all attachments regardless of settings**

```php
add_filter( 'smart_media_replacement_max_revisions', function( int $max, int $attachment_id ): int {
    return min( $max, 3 );
}, 10, 2 );
```

---

### `smart_media_replacement_retention_days`

Filters the number of days to retain revisions before they are automatically deleted. Set to `0` to disable expiration. Revisions older than this threshold are removed during the cleanup routine.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$retention_days` | `int` | Days from plugin settings. Default `0` (disabled). |
| `$attachment_id` | `int` | The attachment ID. |

**Default:** `0` (expiration disabled)

**File:** `Functions/RevisionManager.php`

> **Note:** The second parameter `$attachment_id` is currently passed as `0` during the retention cleanup pass. It is reserved for future per-attachment retention logic and should not be relied on for specific attachment lookups at this time.

---

**Example: Enforce a 90-day retention policy regardless of settings**

```php
add_filter( 'smart_media_replacement_retention_days', function( int $days ): int {
    return 90;
} );
```

---

**Example: Shorten retention in a staging environment**

```php
add_filter( 'smart_media_replacement_retention_days', function( int $days ): int {
    if ( defined( 'WP_ENV' ) && 'staging' === WP_ENV ) {
        return 7;
    }
    return $days;
} );
```

---

**Example: Disable expiration entirely, overriding settings**

```php
add_filter( 'smart_media_replacement_retention_days', function( int $days ): int {
    return 0;
} );
```

---

### `smart_media_replacement_cleanup_time_limit`

Filters the number of seconds the daily cron cleanup is allowed to run before stopping gracefully. Sites not reached within the budget are processed on the next daily run. This prevents the cron from exceeding PHP's `max_execution_time` on large networks.

The default is `max_execution_time - 10` seconds (with a floor of 5). If `max_execution_time` is `0` (unlimited), the default is `60`.

> **Note:** This filter only affects the WP-Cron job. `wp smr db cleanup` runs without a time limit.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$seconds` | `int` | Time budget in seconds. |

**Default:** `max(5, max_execution_time - 10)`, or `60` when `max_execution_time` is unlimited

**File:** `Functions/RevisionManager.php`

---

**Example: Allow the cron 50 seconds on a server with a 60-second PHP limit**

```php
add_filter( 'smart_media_replacement_cleanup_time_limit', function( int $seconds ): int {
    return 50;
} );
```

---

**Example: Give the cron more time on a server you control**

```php
add_filter( 'smart_media_replacement_cleanup_time_limit', function( int $seconds ): int {
    return 120;
} );
```

---

### `smart_media_replacement_cleanup_chunk_size`

Filters the number of expired revisions processed per database round-trip during cleanup. Lowering this reduces peak memory at the cost of more queries. Raising it reduces query count but increases memory per batch.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$chunk_size` | `int` | Rows per batch. |

**Default:** `100`

**File:** `Functions/RevisionManager.php`

---

**Example: Reduce chunk size on a memory-constrained server**

```php
add_filter( 'smart_media_replacement_cleanup_chunk_size', function( int $size ): int {
    return 25;
} );
```

---

**Example: Increase chunk size when bulk-clearing a large backlog**

```php
add_filter( 'smart_media_replacement_cleanup_chunk_size', function( int $size ): int {
    return 500;
} );
```

---

### `smart_media_replacement_revision_directory`

Filters the base filesystem path where revision files are stored. By default this is `{uploads_dir}/smr-revisions`. Use this hook to redirect storage to a different location — a separate disk, a mounted network share, or a path outside the web root.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$base_path` | `string` | Absolute path to the revision storage directory. |

**Default:** `{wp-content/uploads}/smr-revisions`

**File:** `Functions/RevisionStorage.php`

---

**Example: Store revisions outside the web root**

```php
add_filter( 'smart_media_replacement_revision_directory', function( string $path ): string {
    return '/var/media-revisions/smr';
} );
```

---

**Example: Store revisions on a separate mounted drive**

```php
add_filter( 'smart_media_replacement_revision_directory', function( string $path ): string {
    return '/mnt/storage/wp-revisions';
} );
```

---

**Example: Use a per-site subdirectory on multisite**

```php
add_filter( 'smart_media_replacement_revision_directory', function( string $path ): string {
    $blog_id = get_current_blog_id();
    $upload_dir = wp_upload_dir();
    return trailingslashit( $upload_dir['basedir'] ) . 'smr-revisions/site-' . $blog_id;
} );
```

---

**Example: Use an environment variable to configure the path**

```php
add_filter( 'smart_media_replacement_revision_directory', function( string $path ): string {
    $env_path = getenv( 'SMR_REVISION_DIR' );
    return $env_path ?: $path;
} );
```

---

### `smart_media_replacement_enforce_dimensions`

Controls whether the plugin enforces strict dimension matching when replacing an image. When `true`, a replacement image must match the original's width and height. Return `false` to allow replacements of any size.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$enforce_dimensions` | `bool` | Whether to enforce dimensions. Default `true`. |
| `$attachment_id` | `int` | The attachment being replaced. |

**Default:** `true`

**File:** `Functions/ManageMedia.php`

---

**Example: Disable dimension enforcement for a specific attachment**

```php
add_filter( 'smart_media_replacement_enforce_dimensions', function( bool $enforce, int $attachment_id ): bool {
    if ( 99 === $attachment_id ) {
        return false;
    }
    return $enforce;
}, 10, 2 );
```

---

**Example: Disable dimension enforcement for non-image files**

```php
add_filter( 'smart_media_replacement_enforce_dimensions', function( bool $enforce, int $attachment_id ): bool {
    if ( ! wp_attachment_is_image( $attachment_id ) ) {
        return false;
    }
    return $enforce;
}, 10, 2 );
```

---

**Example: Disable enforcement in a staging environment**

```php
add_filter( 'smart_media_replacement_enforce_dimensions', function( bool $enforce, int $attachment_id ): bool {
    if ( defined( 'WP_ENV' ) && 'staging' === WP_ENV ) {
        return false;
    }
    return $enforce;
}, 10, 2 );
```

---

**Example: Disable enforcement for users with a specific role**

```php
add_filter( 'smart_media_replacement_enforce_dimensions', function( bool $enforce, int $attachment_id ): bool {
    if ( current_user_can( 'manage_options' ) ) {
        return false;
    }
    return $enforce;
}, 10, 2 );
```

---

### `smart_media_replacement_audit_scanned_meta_keys`

Post meta keys the audit scanner walks looking for page-builder media references. Each entry is `[ 'key' => string, 'format' => 'json'|'serialized' ]`.

**Default:** Elementor (`_elementor_data`) and Beaver Builder (`_fl_builder_data`), both JSON.

```php
add_filter( 'smart_media_replacement_audit_scanned_meta_keys', function ( array $keys ): array {
	$keys[] = array(
		'key'    => '_my_builder_layout',
		'format' => 'json',
	);
	return $keys;
} );
```

The parser deliberately over-collects — it gathers every positive integer it finds — and `PostScanner` then validates the candidates against real attachments, so a loose match here cannot corrupt usage counts.

---

### `smart_media_replacement_audit_scan_post_types`

Post types the scanner walks for media references.

**Default:** `array( 'post', 'page', 'wp_template', 'wp_template_part' )`

```php
add_filter( 'smart_media_replacement_audit_scan_post_types', function ( array $types ): array {
	$types[] = 'product';
	return $types;
} );
```

Adding a post type does not retroactively index it — run a fresh scan (or `wp smr audit scan`) afterwards.

---

### `smart_media_replacement_audit_scan_statuses`

Post statuses treated as live content. The progress denominator and the scan loop both read this filter, so they stay consistent and progress can still reach 100%.

**Default:** `array( 'publish', 'future', 'draft', 'pending', 'private' )`

```php
// Only count published content as "using" a file.
add_filter( 'smart_media_replacement_audit_scan_statuses', fn() => array( 'publish' ) );
```

---

### `smart_media_replacement_audit_batch_size`

How many posts the scanner indexes per cron tick. Lower it on constrained hosting, raise it to finish large libraries faster.

**Default:** `50`

```php
add_filter( 'smart_media_replacement_audit_batch_size', fn() => 25 );
```

Values below 1 are clamped to 1.

---

## Action Hooks

Actions fire at specific points in the plugin's lifecycle. Use them to react to events — logging, cache clearing, notifications, syncing to external systems, and so on.

---

### `smart_media_replacement_before_replace`

Fires after all validation passes but **before** the replacement file is moved into place. This is the correct point to snapshot, back up, or perform any pre-replacement side effects. The revision system itself uses this hook internally to capture the current file before it is overwritten.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$attachment_id` | `int` | The attachment ID about to be replaced. |
| `$replacement_data` | `array` | Contains `version_type` (string) and `comment` (string). |

**File:** `Functions/ManageMedia.php`

---

**Example: Log every replacement attempt to a custom table**

```php
add_action( 'smart_media_replacement_before_replace', function( int $attachment_id, array $data ): void {
    global $wpdb;
    $wpdb->insert(
        $wpdb->prefix . 'my_replacement_log',
        [
            'attachment_id' => $attachment_id,
            'user_id'       => get_current_user_id(),
            'version_type'  => $data['version_type'],
            'replaced_at'   => current_time( 'mysql' ),
        ],
        [ '%d', '%d', '%s', '%s' ]
    );
}, 10, 2 );
```

---

**Example: Send a Slack notification before a replacement**

```php
add_action( 'smart_media_replacement_before_replace', function( int $attachment_id, array $data ): void {
    $user = wp_get_current_user();
    $title = get_the_title( $attachment_id );
    wp_remote_post( MY_SLACK_WEBHOOK_URL, [
        'body' => json_encode([
            'text' => sprintf(
                ':camera: *%s* is replacing media: _%s_ (type: %s)',
                $user->display_name,
                $title,
                $data['version_type']
            ),
        ]),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ] );
}, 10, 2 );
```

---

**Example: Store the pre-replacement URL for later comparison**

```php
add_action( 'smart_media_replacement_before_replace', function( int $attachment_id, array $data ): void {
    $url = wp_get_attachment_url( $attachment_id );
    set_transient( 'smr_pre_replace_' . $attachment_id, $url, HOUR_IN_SECONDS );
}, 10, 2 );
```

---

### `smart_media_replacement_file_replaced`

Fires after the file has been swapped and attachment metadata has been updated. Use this hook for post-replacement side effects: CDN purges, search index updates, cache invalidation, notifications.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$attachment_id` | `int` | The attachment that was replaced. |
| `$final_file_path` | `string` | Absolute filesystem path to the new file. |

**File:** `Functions/ManageMedia.php`

---

**Example: Purge a CDN cache after replacement**

```php
add_action( 'smart_media_replacement_file_replaced', function( int $attachment_id, string $file_path ): void {
    $url = wp_get_attachment_url( $attachment_id );
    my_cdn_purge( $url );
}, 10, 2 );
```

---

**Example: Trigger a search index update (e.g. Elasticsearch / SearchWP)**

```php
add_action( 'smart_media_replacement_file_replaced', function( int $attachment_id, string $file_path ): void {
    if ( function_exists( 'searchwp_index_post' ) ) {
        searchwp_index_post( $attachment_id );
    }
}, 10, 2 );
```

---

**Example: Write a plain-text audit log entry**

```php
add_action( 'smart_media_replacement_file_replaced', function( int $attachment_id, string $file_path ): void {
    $entry = sprintf(
        "[%s] User %d replaced attachment %d → %s\n",
        current_time( 'Y-m-d H:i:s' ),
        get_current_user_id(),
        $attachment_id,
        $file_path
    );
    file_put_contents( WP_CONTENT_DIR . '/smr-audit.log', $entry, FILE_APPEND | LOCK_EX );
}, 10, 2 );
```

---

**Example: Notify an admin via email when a replacement is made**

```php
add_action( 'smart_media_replacement_file_replaced', function( int $attachment_id, string $file_path ): void {
    $admin_email = get_option( 'admin_email' );
    $title       = get_the_title( $attachment_id );
    $user        = wp_get_current_user();
    wp_mail(
        $admin_email,
        'Media File Replaced',
        sprintf(
            "%s replaced the media file \"%s\".\n\nFile: %s",
            $user->display_name,
            $title,
            $file_path
        )
    );
}, 10, 2 );
```

---

### `smart_media_replacement_revision_created`

Fires after a new revision has been successfully saved to the database and to disk.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$revision_id` | `int` | The new revision's database ID. |
| `$attachment_id` | `int` | The attachment this revision belongs to. |
| `$revision_data` | `array` | Contains `version` (string), `file_path` (string), and `comment` (string). |

**File:** `Functions/RevisionManager.php`

---

**Example: Log revision creation to an audit trail**

```php
add_action( 'smart_media_replacement_revision_created', function( int $revision_id, int $attachment_id, array $data ): void {
    error_log( sprintf(
        'SMR: Revision %d created for attachment %d (version %s)',
        $revision_id,
        $attachment_id,
        $data['version']
    ) );
}, 10, 3 );
```

---

**Example: Store revision metadata in post meta for quick lookups**

```php
add_action( 'smart_media_replacement_revision_created', function( int $revision_id, int $attachment_id, array $data ): void {
    update_post_meta( $attachment_id, '_smr_latest_revision_id', $revision_id );
    update_post_meta( $attachment_id, '_smr_latest_version', $data['version'] );
}, 10, 3 );
```

---

**Example: Trigger a webhook when a revision is created**

```php
add_action( 'smart_media_replacement_revision_created', function( int $revision_id, int $attachment_id, array $data ): void {
    wp_remote_post( 'https://hooks.example.com/smr', [
        'body' => json_encode([
            'event'         => 'revision_created',
            'revision_id'   => $revision_id,
            'attachment_id' => $attachment_id,
            'version'       => $data['version'],
            'comment'       => $data['comment'],
        ]),
        'headers' => [ 'Content-Type' => 'application/json' ],
    ] );
}, 10, 3 );
```

---

### `smart_media_replacement_revision_restored`

Fires after a revision has been successfully restored as the live attachment file.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$revision_id` | `int` | The revision ID that was restored. |
| `$attachment_id` | `int` | The attachment that was restored. |

**File:** `Functions/RevisionManager.php`

---

**Example: Purge CDN cache after a restore**

```php
add_action( 'smart_media_replacement_revision_restored', function( int $revision_id, int $attachment_id ): void {
    $url = wp_get_attachment_url( $attachment_id );
    my_cdn_purge( $url );
}, 10, 2 );
```

---

**Example: Log restores separately from replacements**

```php
add_action( 'smart_media_replacement_revision_restored', function( int $revision_id, int $attachment_id ): void {
    $entry = sprintf(
        "[%s] User %d restored revision %d for attachment %d\n",
        current_time( 'Y-m-d H:i:s' ),
        get_current_user_id(),
        $revision_id,
        $attachment_id
    );
    file_put_contents( WP_CONTENT_DIR . '/smr-audit.log', $entry, FILE_APPEND | LOCK_EX );
}, 10, 2 );
```

---

**Example: Update a "last restored" post meta field**

```php
add_action( 'smart_media_replacement_revision_restored', function( int $revision_id, int $attachment_id ): void {
    update_post_meta( $attachment_id, '_smr_last_restored_revision', $revision_id );
    update_post_meta( $attachment_id, '_smr_last_restored_at', current_time( 'mysql' ) );
}, 10, 2 );
```

---

### `smart_media_replacement_revisions_cleaned`

Fires after old revisions have been deleted. This hook fires in two distinct situations — when the max revision count is exceeded, and when the retention period expires. Both pass the same parameters.

**Parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `$attachment_id` | `int` | The attachment whose revisions were cleaned. |
| `$deleted_ids` | `array` | Array of deleted revision IDs (integers). |

**File:** `Functions/RevisionManager.php`

---

**Example: Log how many revisions were cleaned and why**

```php
add_action( 'smart_media_replacement_revisions_cleaned', function( int $attachment_id, array $deleted_ids ): void {
    error_log( sprintf(
        'SMR: Cleaned %d revision(s) for attachment %d. IDs: %s',
        count( $deleted_ids ),
        $attachment_id,
        implode( ', ', $deleted_ids )
    ) );
}, 10, 2 );
```

---

**Example: Notify an admin if a large number of revisions were deleted at once**

```php
add_action( 'smart_media_replacement_revisions_cleaned', function( int $attachment_id, array $deleted_ids ): void {
    if ( count( $deleted_ids ) >= 10 ) {
        wp_mail(
            get_option( 'admin_email' ),
            'SMR: Large revision cleanup',
            sprintf(
                '%d revisions were deleted for attachment %d.',
                count( $deleted_ids ),
                $attachment_id
            )
        );
    }
}, 10, 2 );
```

---

**Example: Remove orphaned files from a custom backup location**

```php
add_action( 'smart_media_replacement_revisions_cleaned', function( int $attachment_id, array $deleted_ids ): void {
    foreach ( $deleted_ids as $revision_id ) {
        $backup = '/mnt/backup/smr/' . $revision_id . '.bak';
        if ( file_exists( $backup ) ) {
            unlink( $backup );
        }
    }
}, 10, 2 );
```

---

---

## WP-CLI Commands

Smart Media Replacement ships with WP-CLI commands for on-demand database health management. These are useful in deployment pipelines, after database restores, or when the automatic cron check is set to **Disabled** and your team prefers manual control.

All commands live under the `wp smr db` namespace. On multisite, `--network` and `--site-id=<id>` flags expand scope beyond the default current-site context.

---

### `wp smr db check`

Check whether the revisions table (`wp_smr_revisions`) exists. Returns a non-zero exit code if the table is missing, making it safe to use in shell scripts and CI pipelines.

**Flags**

| Flag | Description |
|------|-------------|
| `--network` | Confirm the check is running in network context (multisite only). The table is shared network-wide; this flag labels the output scope. |
| `--site-id=<id>` | Run the check from a specific site's context by blog ID (multisite only). |

**Examples**

```bash
# Basic check on a single-site install.
wp smr db check

# Check from the network admin context on multisite.
wp smr db check --network

# Check from the context of site 5 on multisite.
wp smr db check --site-id=5
```

**Example output**

```
Success: Table wp_smr_revisions exists.
Error:   Table wp_smr_revisions does not exist. Run `wp smr db repair` to recreate it.
```

**Use in a deployment script**

```bash
#!/bin/bash
wp smr db check || wp smr db repair
echo "Database ready."
```

---

### `wp smr db repair`

Recreate the revisions table if it is missing. Uses `dbDelta` internally, so running this when the table already exists is safe — it will report no action needed and exit cleanly.

**Flags**

| Flag | Description |
|------|-------------|
| `--network` | Run repair in network context (multisite only). Since the table is shared, the result is identical to a plain repair; the flag clarifies scope in the output. |
| `--site-id=<id>` | Run the repair from a specific site's context (multisite only). |

**Examples**

```bash
# Repair on a single-site install.
wp smr db repair

# Repair in network context on multisite.
wp smr db repair --network

# Repair from the context of site 3.
wp smr db repair --site-id=3
```

**Example output**

```
Success: Table wp_smr_revisions created successfully.
Success: Table wp_smr_revisions already exists. No action needed.
Error:   Failed to create table wp_smr_revisions. Check database permissions.
```

**Use after a database restore**

```bash
#!/bin/bash
# After restoring a DB backup that may not have included the plugin table.
wp smr db repair
wp cache flush
```

**Use in a Composer post-deploy script**

```json
{
  "scripts": {
    "post-deploy": [
      "wp smr db repair --allow-root"
    ]
  }
}
```

---

### `wp smr db status`

Show revision counts and storage usage. On multisite with `--network`, outputs a per-site breakdown with a totals row appended.

**Flags**

| Flag | Description |
|------|-------------|
| `--network` | Show a per-site breakdown across the entire network (multisite only). |
| `--site-id=<id>` | Show stats for a specific site by blog ID (multisite only). |
| `--format=<format>` | Output format: `table` (default), `csv`, `json`, `yaml`. |

**Examples**

```bash
# Status for the current site.
wp smr db status

# Per-site network breakdown.
wp smr db status --network

# Status for site 7 only.
wp smr db status --site-id=7

# Export network stats as JSON for a monitoring script.
wp smr db status --network --format=json

# Export network stats as CSV for a spreadsheet.
wp smr db status --network --format=csv
```

**Example output — single site**

```
+------------------+------------------+
| Field            | Value            |
+------------------+------------------+
| Table            | wp_smr_revisions |
| Table exists     | Yes              |
| Total revisions  | 142              |
| Storage used     | 38.4 MB          |
+------------------+------------------+
```

**Example output — `--network`**

```
Table: wp_smr_revisions | Exists: Yes
+---------+-------------------------+-----------+---------+
| Site ID | Site URL                | Revisions | Storage |
+---------+-------------------------+-----------+---------+
| 1       | https://example.com     | 98        | 22 MB   |
| 2       | https://blog.example.com| 44        | 16 MB   |
| —       | TOTAL                   | 142       | 38 MB   |
+---------+-------------------------+-----------+---------+
```

**Use in a monitoring script**

```bash
#!/bin/bash
# Alert if total network storage exceeds 1 GB.
STORAGE=$(wp smr db status --network --format=json | jq -r '.[-1].Storage')
echo "Network revision storage: $STORAGE"
```

---

### `wp smr db cleanup`

Delete expired revisions according to the configured retention policy (`smr_retention_days`). This is the manual equivalent of the daily cron job, with no time limit — use it to clear a backlog immediately or to run cleanup on your own schedule instead of relying on WP-Cron.

Reads the retention period from plugin settings. If the retention policy is disabled (`smr_retention_days = 0`), the command exits with an error.

**Flags**

| Flag | Description |
|------|-------------|
| `--network` | Process every site in the network (multisite only). |
| `--site-id=<id>` | Process a specific site by blog ID (multisite only). |
| `--dry-run` | Report how many revisions would be deleted without deleting anything. |
| `--yes` | Skip the confirmation prompt. |

**Examples**

```bash
# Clean expired revisions on the current site.
wp smr db cleanup

# See what would be deleted before committing.
wp smr db cleanup --dry-run

# Clean all sites in the network, skip confirmation.
wp smr db cleanup --network --yes

# Clean a specific site on a multisite network.
wp smr db cleanup --site-id=3

# Per-site dry-run across the network.
wp smr db cleanup --network --dry-run
```

**Example output — single site**

```
About to delete 87 expired revision(s). Continue? [y/n] y
Success: Deleted 87 expired revision(s).
```

**Example output — `--network`**

```
Site 1 (https://example.com): deleted 87 revision(s).
Site 2 (https://blog.example.com): deleted 12 revision(s).
Success: Deleted 99 expired revision(s) across 2 site(s).
```

**Example output — `--dry-run --network`**

```
Site 1 (https://example.com): would delete 87 revision(s).
Site 2 (https://blog.example.com): would delete 12 revision(s).
Total: would delete 99 expired revision(s) across 2 site(s) (retention: 30 days).
```

**Use in a scheduled system cron (recommended for large networks)**

Disable the WP-Cron cleanup in plugin settings (set **Database Health Check** to **Disabled**), then drive cleanup from a real system cron instead:

```bash
# crontab -e
0 2 * * * /usr/local/bin/wp --path=/var/www/html smr db cleanup --yes --quiet
```

**Use after re-enabling a retention policy**

```bash
# Check scope first, then delete.
wp smr db cleanup --dry-run
wp smr db cleanup --yes
```

---

### `wp smr audit scan`

Builds the media audit index. Runs every scan phase synchronously in the current process rather than scheduling cron ticks, and cancels the events the batch runner schedules as it goes, so no stray cron entry survives the command.

**Why this matters on multisite:** WP-Cron only fires for a site that is receiving a request. A scan started from a quiet subsite's admin can sit at 0% indefinitely. This command is the reliable path.

```
[--site-id=<id>]   Scan a specific site (multisite only).
[--network]        Scan every site on the network (multisite only).
[--yes]            Skip the confirmation prompt when using --network.
```

```bash
wp smr audit scan
wp smr audit scan --site-id=3
wp smr audit scan --network --yes
```

Each pass starts from a clean slate — the index is truncated and rebuilt, exactly as the "Scan Now" button does.

---

### `wp smr audit status`

Reports index state without changing anything.

```
[--site-id=<id>]     Report on a specific site (multisite only).
[--network]          Report on every site (multisite only).
[--format=<format>]  table (default), json, csv, or yaml.
```

```bash
wp smr audit status
wp smr audit status --network --format=json
```

Columns: `tables` (`ok`/`missing`), `index_built`, `status` (`idle`/`scanning`/`complete`), `progress`, `indexed_files`, `unused_files`. With `--network`, a leading `site_id` column is added.

`tables: missing` on a site means it was never provisioned — run `wp smr audit scan` on it, or trigger the `smr_db_health_check` cron, either of which recreates the tables.

---

### `wp smr audit clear`

Empties the index and summary tables and resets scan state. The tables themselves are kept; run a scan to repopulate.

```
[--site-id=<id>]   Clear a specific site (multisite only).
[--network]        Clear every site on the network (multisite only).
[--yes]            Skip the confirmation prompt.
```

```bash
wp smr audit clear
wp smr audit clear --network --yes
```

---

## Quick Reference

### Filters

| Hook | File | Controls |
|------|------|----------|
| `smart_media_replacement_create_revision` | `Functions/RevisionManager.php` | Whether to create a revision (`bool`) |
| `smart_media_replacement_max_revisions` | `Functions/RevisionManager.php` | Max revisions per attachment (`int`) |
| `smart_media_replacement_retention_days` | `Functions/RevisionManager.php` | Retention period in days (`int`) |
| `smart_media_replacement_cleanup_time_limit` | `Functions/RevisionManager.php` | Cron cleanup time budget in seconds (`int`) |
| `smart_media_replacement_cleanup_chunk_size` | `Functions/RevisionManager.php` | Expired revisions processed per DB batch (`int`) |
| `smart_media_replacement_revision_directory` | `Functions/RevisionStorage.php` | Revision file storage path (`string`) |
| `smart_media_replacement_enforce_dimensions` | `Functions/ManageMedia.php` | Strict dimension matching on replace (`bool`) |
| `smart_media_replacement_audit_scanned_meta_keys` | `Functions/Audit/MetaParser.php` | Page-builder meta keys scanned (`array`) |
| `smart_media_replacement_audit_scan_post_types` | `Functions/Audit/BatchRunner.php` | Post types the scanner walks (`string[]`) |
| `smart_media_replacement_audit_scan_statuses` | `Functions/Audit/BatchRunner.php` | Post statuses treated as live (`string[]`) |
| `smart_media_replacement_audit_batch_size` | `Functions/Audit/BatchRunner.php` | Posts indexed per cron tick (`int`) |

### Actions

| Hook | File | Fires when |
|------|------|-----------|
| `smart_media_replacement_before_replace` | `Functions/ManageMedia.php` | Validation passed, file not yet swapped |
| `smart_media_replacement_file_replaced` | `Functions/ManageMedia.php` | File swapped, metadata updated |
| `smart_media_replacement_revision_created` | `Functions/RevisionManager.php` | Revision saved to DB and disk |
| `smart_media_replacement_revision_restored` | `Functions/RevisionManager.php` | Revision restored as live file |
| `smart_media_replacement_revisions_cleaned` | `Functions/RevisionManager.php` | Old revisions deleted (limit or expiry) |

### WP-CLI Commands

| Command | Description |
|---------|-------------|
| `wp smr db check` | Verify the revisions table exists |
| `wp smr db repair` | Recreate the revisions table if missing |
| `wp smr db status` | Show revision counts and storage usage |
| `wp smr db cleanup` | Delete expired revisions per retention policy |
| `wp smr audit scan` | Build the media audit index (runs synchronously) |
| `wp smr audit status` | Index state, file counts and unused count |
| `wp smr audit clear` | Wipe the index and scan state (tables are kept) |
