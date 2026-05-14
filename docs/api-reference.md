# API Reference

## PHP Classes

### `Smart_Media_Replacement\ManageMedia`

The main plugin class that handles all media replacement functionality.

#### Constructor

```php
public function __construct()
```

Initializes the plugin by registering WordPress hooks:
- `admin_enqueue_scripts` - Loads JavaScript on media screens
- `attachment_submitbox_misc_actions` - Adds replace button to attachment details
- `media_row_actions` - Adds replace link to list view
- `wp_ajax_smart_media_replacement_handler` - Handles AJAX replacement requests

#### Methods

---

##### `enqueue_scripts()`

```php
public function enqueue_scripts(string $hook): void
```

Enqueues the plugin JavaScript on appropriate admin screens.

**Parameters:**
- `$hook` (string) - The current admin page hook.

**Screens Loaded:**
- `upload.php` - Media Library
- `post.php` - Post/Page editor (for media modal)

**Localized Data:**
```javascript
smartMediaReplacement = {
    ajaxUrl: '/wp-admin/admin-ajax.php',
    nonce: '<nonce-string>'
}
```

---

##### `smart_media_replacement_submit_button()`

```php
public function smart_media_replacement_submit_button(WP_Post $post): void
```

Renders the "Replace File" button in the attachment details panel.

**Parameters:**
- `$post` (WP_Post) - The attachment post object.

**Output:**
```html
<div class="misc-pub-section misc-pub-replace">
    <button type="button"
            class="button button-primary button-large smr-replace-button"
            data-attachment-id="123"
            style="width:100%">
        Replace File
    </button>
</div>
```

---

##### `smart_media_replacement_row_actions()`

```php
public function smart_media_replacement_row_actions(
    array $actions,
    WP_Post $post
): array
```

Adds the "Replace" link to media library row actions.

**Parameters:**
- `$actions` (array) - Existing row actions.
- `$post` (WP_Post) - The attachment post object.

**Returns:** `array` - Modified actions array.

**Capability Check:** Only adds link if user can `edit_post`.

---

##### `smart_media_replacement_handler()`

```php
public function smart_media_replacement_handler(): void
```

AJAX handler for file replacement requests.

**Request Parameters (POST):**
- `nonce` (string) - Security nonce.
- `attachment_id` (int) - ID of attachment to replace.
- `file` (file) - The uploaded replacement file.

**Response (JSON):**

Success:
```json
{
    "success": true,
    "data": {
        "message": "File replaced successfully."
    }
}
```

Error:
```json
{
    "success": false,
    "data": {
        "message": "Error description here."
    }
}
```

**Validation Steps:**
1. Nonce verification
2. User capability check
3. Attachment existence check
4. File upload validation
5. Filename matching
6. MIME type matching
7. Image dimension matching (filterable)

---

##### `get_original_filename()`

```php
private function get_original_filename(string $filename): string
```

Extracts the original filename from WordPress scaled image names.

**Parameters:**
- `$filename` (string) - The filename, possibly with `-scaled` suffix.

**Returns:** `string` - The original filename without `-scaled`.

**Examples:**
```php
get_original_filename('photo-scaled.jpg');  // Returns: 'photo.jpg'
get_original_filename('photo.jpg');          // Returns: 'photo.jpg'
get_original_filename('my-scaled-image.png'); // Returns: 'my-scaled-image.png'
```

---

##### `delete_attachment_files()`

```php
private function delete_attachment_files(int $attachment_id): void
```

Deletes the original file and all generated sizes for an attachment.

**Parameters:**
- `$attachment_id` (int) - The attachment ID.

**Deleted Files:**
- Original uploaded file
- All thumbnail sizes
- Scaled versions
- WebP variants (if generated)

**Note:** Uses WordPress Filesystem API for safe file operations.

---

## JavaScript API

### Global Object

```javascript
window.smartMediaReplacement = {
    ajaxUrl: string,  // WordPress AJAX URL
    nonce: string     // Security nonce
}
```

### DOM Classes

| Class | Element | Purpose |
|-------|---------|---------|
| `.smr-replace-button` | Button | Triggers file picker |
| `.smr-replace-link` | Anchor | List view replace link |
| `.smr-error-notice` | Div | Error message container |
| `.smr-error-row` | Table row | Inline error in list view |

### Data Attributes

| Attribute | Element | Value |
|-----------|---------|-------|
| `data-attachment-id` | Button/Link | Attachment ID (int) |

---

## WordPress Hooks Used

### Actions (WordPress Core)

| Hook | Purpose |
|------|---------|
| `admin_enqueue_scripts` | Load plugin scripts |
| `attachment_submitbox_misc_actions` | Add replace button |
| `wp_ajax_smart_media_replacement_handler` | Handle AJAX |

### Filters (WordPress Core)

| Hook | Purpose |
|------|---------|
| `media_row_actions` | Add replace link |

---

## Error Codes

| Code | Message | Cause |
|------|---------|-------|
| `nonce_failed` | Security check failed | Invalid/expired nonce |
| `permission_denied` | You do not have permission | Missing `edit_post` capability |
| `invalid_attachment` | Invalid attachment ID | Attachment doesn't exist |
| `upload_error` | Error uploading file | File upload failed |
| `filename_mismatch` | Filename must match | Different filename provided |
| `mime_mismatch` | MIME type must match | Different file type |
| `dimension_mismatch` | Dimensions must match | Different image size |

---

## Constants

### Plugin Constants

```php
// Defined in smart-media-replacement.php
SMR_PLUGIN_URL  // Plugin directory URL
```

### WordPress Constants Used

```php
ABSPATH         // WordPress root path
WP_CONTENT_DIR  // Content directory path
```
