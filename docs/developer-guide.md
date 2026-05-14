# Developer Guide

## Architecture Overview

The plugin follows a simple architecture:

```
smart-media-replacement/
├── smart-media-replacement.php    # Bootstrap file
├── Functions/
│   └── ManageMedia.php            # Core PHP class
├── src/
│   └── smart-media-replacement.js # Source JavaScript
└── build/
    └── smart-media-replacement.js # Compiled JavaScript
```

## Hooks Reference

### Filters

#### `smart_media_replacement_enforce_dimensions`

Control whether dimension matching is enforced for image replacements.

```php
/**
 * Disable dimension enforcement for all images.
 */
add_filter('smart_media_replacement_enforce_dimensions', '__return_false');
```

```php
/**
 * Disable dimension enforcement for specific attachments.
 *
 * @param bool $enforce      Whether to enforce dimensions.
 * @param int  $attachment_id The attachment being replaced.
 * @return bool
 */
add_filter('smart_media_replacement_enforce_dimensions', function($enforce, $attachment_id) {
    // Disable for a specific attachment
    if ($attachment_id === 123) {
        return false;
    }

    // Disable for attachments in a specific category
    if (has_term('flexible-images', 'media_category', $attachment_id)) {
        return false;
    }

    return $enforce;
}, 10, 2);
```

**Parameters:**
- `$enforce` (bool) - Whether to enforce dimension matching. Default `true`.
- `$attachment_id` (int) - The ID of the attachment being replaced.

**Returns:** `bool` - Whether to enforce dimensions.

### Actions

#### `smart_media_replacement_file_replaced`

Fired after a file has been successfully replaced.

```php
/**
 * Log file replacements.
 *
 * @param int    $attachment_id  The attachment ID.
 * @param string $new_file_path  Full path to the new file.
 */
add_action('smart_media_replacement_file_replaced', function($attachment_id, $new_file_path) {
    error_log(sprintf(
        'Attachment %d replaced with %s',
        $attachment_id,
        $new_file_path
    ));
}, 10, 2);
```

```php
/**
 * Clear CDN cache after replacement.
 */
add_action('smart_media_replacement_file_replaced', function($attachment_id, $new_file_path) {
    // Clear specific CDN cache
    if (function_exists('cdn_purge_url')) {
        $url = wp_get_attachment_url($attachment_id);
        cdn_purge_url($url);
    }
}, 10, 2);
```

```php
/**
 * Notify admin of file replacement.
 */
add_action('smart_media_replacement_file_replaced', function($attachment_id, $new_file_path) {
    $user = wp_get_current_user();
    $attachment = get_post($attachment_id);

    wp_mail(
        get_option('admin_email'),
        'Media File Replaced',
        sprintf(
            'User %s replaced attachment "%s" (ID: %d)',
            $user->display_name,
            $attachment->post_title,
            $attachment_id
        )
    );
}, 10, 2);
```

**Parameters:**
- `$attachment_id` (int) - The ID of the replaced attachment.
- `$new_file_path` (string) - Full server path to the new file.

## JavaScript Events

The frontend JavaScript does not currently emit custom events. The page reloads after successful replacement.

## Extending the Plugin

### Adding Custom Validation

To add custom validation, hook into the AJAX handler early:

```php
add_action('wp_ajax_smart_media_replacement_handler', function() {
    // Your validation runs before the plugin's handler
    $attachment_id = intval($_POST['attachment_id'] ?? 0);

    // Example: Prevent replacement of protected files
    if (get_post_meta($attachment_id, '_protected_file', true)) {
        wp_send_json_error([
            'message' => __('This file is protected and cannot be replaced.', 'your-textdomain')
        ]);
    }
}, 5); // Priority 5 runs before default (10)
```

### Custom Post-Replacement Processing

```php
add_action('smart_media_replacement_file_replaced', function($attachment_id, $new_file_path) {
    // Regenerate specific image sizes
    if (function_exists('wp_create_image_subsizes')) {
        $metadata = wp_get_attachment_metadata($attachment_id);
        wp_create_image_subsizes($new_file_path, ['custom-size'], $attachment_id);
    }

    // Update custom metadata
    update_post_meta($attachment_id, '_last_replaced', current_time('mysql'));
    update_post_meta($attachment_id, '_replaced_by', get_current_user_id());
}, 10, 2);
```

## Build System

### Requirements

- Node.js 16+
- npm 8+

### Scripts

```bash
# Install dependencies
npm install

# Development build with watch
npm start

# Production build
npm run build

# Lint JavaScript
npm run lint:js

# Lint CSS (if applicable)
npm run lint:css

# Format code
npm run format
```

### Webpack Configuration

The plugin uses `@wordpress/scripts` with a custom entry point:

```javascript
// webpack.scripts.js
const defaultConfig = require('@wordpress/scripts/config/webpack.config');

module.exports = {
    ...defaultConfig,
    entry: {
        'smart-media-replacement': './src/smart-media-replacement.js',
    },
};
```

## PHP Coding Standards

The plugin follows WordPress Coding Standards. Run PHPCS:

```bash
# Check standards
composer run phpcs

# Auto-fix issues
composer run phpcbf
```

## Security Considerations

### Nonce Verification

All AJAX requests require a valid nonce:

```php
check_ajax_referer('smart_media_replacement_nonce', 'nonce');
```

### Capability Checks

Users must have `edit_post` capability:

```php
if (!current_user_can('edit_post', $attachment_id)) {
    wp_send_json_error(['message' => 'Permission denied']);
}
```

### File Validation

- `is_uploaded_file()` verifies the upload
- `sanitize_file_name()` cleans the filename
- `sanitize_mime_type()` validates MIME types
- WordPress Filesystem API handles all file operations

## Testing

### Manual Testing Checklist

1. Replace an image in List View
2. Replace an image in Grid View / Modal
3. Replace a PDF document
4. Attempt replacement with wrong filename (should fail)
5. Attempt replacement with wrong MIME type (should fail)
6. Attempt replacement with wrong dimensions (should fail)
7. Replace a scaled image
8. Test as different user roles

### Unit Testing

The plugin does not currently include unit tests. Contributions welcome.
