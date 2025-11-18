<img src="assets/banner-772x250.png" alt="Smart Media Replacement Plugin Banner" style="width: 100%; height: auto;">

# Smart Media Replacement Plugin

A WordPress plugin that allows you to replace media files while maintaining their original URLs and metadata. This is particularly useful for updating files like PDFs, images, or other documents without breaking existing links.

## Features

- Replace media files while maintaining original URLs
- Preserves all existing links (both internal and external)
- Maintains file metadata and relationships
- Simple and intuitive interface in the WordPress Media Library
- Supports all file types supported by WordPress
- **Validates file names to prevent accidental URL changes**
- **Enforces dimension matching for images to prevent layout issues**
- **Automatically handles WordPress scaled images**
- **Validates file type matching to ensure consistency**
- AJAX-based replacement with error handling

## Installation

1. Download the plugin files
2. Upload the `smart-media-replacement` folder to the `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress

## Usage

1. Go to the WordPress Media Library
2. Find the file you want to replace
3. Click on the file to view its details
4. Look for the "Replace File" button in the attachment details
5. Click "Replace File" and select your new file
6. The replacement will happen automatically

### Important Requirements

#### Filename Matching
- **The new file MUST have exactly the same filename as the original file**
- Example: If your original file is `logo.png`, your replacement must also be named `logo.png`
- If filenames don't match, you'll receive an error with the required filename

#### Image Dimensions (Images Only)
- **For images, the replacement MUST have identical dimensions (width × height)**
- This prevents layout issues where images might break your design
- Example: If your original image is 1200×800px, your replacement must also be 1200×800px

#### WordPress Scaled Images
- If WordPress automatically scaled your original image (large uploads), the plugin handles this automatically
- You should upload a file with the **original** filename (without `-scaled`)
- Example: If you see `photo-scaled.jpg` in the media library, upload your replacement as `photo.jpg`
- The plugin will show you the correct filename if there's a mismatch

#### File Types
- The replacement file must be the same file type as the original
- Example: You cannot replace a `.jpg` with a `.png`
- The plugin validates MIME types to ensure consistency

## Troubleshooting

### "The new file must have the same name as the original file"
- Rename your replacement file to match the original filename exactly
- Check for the correct file extension

### "The replacement must have the exact same dimensions"
- Resize your replacement image to match the original dimensions
- Use image editing software to verify dimensions before uploading

### "File type mismatch"
- Your replacement file must be the same file type as the original
- Check that you're not trying to replace a JPEG with a PNG, or a PDF with a DOCX

### "This image was automatically scaled by WordPress"
- Your original image was larger than WordPress's threshold (typically 2560px)
- Upload your replacement with the original filename (shown in the error message)
- The plugin will handle the scaling automatically

### "You do not have permission to edit this attachment"
- You must have permission to edit the specific media file
- Contact your site administrator if you believe you should have access

### Button not appearing
- Make sure you're viewing the attachment details (click on a media item)
- Clear your browser cache
- Check that you have permission to edit media files

## For Developers

### Hooks and Filters

#### Filters

**`smart_media_replacement_enforce_dimensions`**

Allows you to disable or customize dimension enforcement for specific attachments.

```php
/**
 * Disable dimension enforcement for specific attachments.
 *
 * @param bool $enforce       Whether to enforce dimensions. Default true.
 * @param int  $attachment_id The attachment ID being replaced.
 * @return bool
 */
add_filter( 'smart_media_replacement_enforce_dimensions', function( $enforce, $attachment_id ) {
	// Allow flexible dimensions for attachment ID 123
	if ( $attachment_id === 123 ) {
		return false;
	}
	return $enforce;
}, 10, 2 );
```

#### Actions

**`smart_media_replacement_file_replaced`**

Fires after a media file has been successfully replaced.

```php
/**
 * Perform custom actions after file replacement.
 *
 * @param int    $attachment_id   The ID of the attachment that was replaced.
 * @param string $new_file_path   The full path to the new file.
 */
add_action( 'smart_media_replacement_file_replaced', function( $attachment_id, $new_file_path ) {
	// Clear custom caches
	wp_cache_delete( 'my_custom_cache_' . $attachment_id );
	
	// Notify external services
	do_something_custom( $attachment_id );
	
	// Log the replacement
	error_log( "Media file replaced: {$attachment_id} -> {$new_file_path}" );
}, 10, 2 );
```

### Building from Source

1. Clone the repository
2. Install dependencies:
   ```bash
   npm install
   composer install
   ```
3. Build the JavaScript files:
   ```bash
   npm run build
   ```

### File Structure

- `Functions/` - PHP classes and WordPress hooks
  - `ManageMedia.php` - Core replacement functionality and AJAX handlers
- `src/` - JavaScript source files
  - `replace-media.js` - Frontend interaction and file upload handling
- `build/` - Compiled JavaScript files (generated by webpack)
- `vendor/` - Composer dependencies

### Development Notes

- The plugin uses WordPress's native media handling functions
- File validation happens server-side for security
- The JavaScript uses the WordPress i18n system for translations
- PHPCS is configured with WordPress coding standards

## Requirements

- WordPress 6.6 or higher
- PHP 7.0 or higher
- User must have `edit_post` capability for the specific attachment

## Privacy

This plugin:
- Does not collect or transmit any user data
- Does not use cookies
- Only processes files locally on your server
- Does not communicate with external services

## Support

For support, please open an issue on the GitHub repository.

## Changelog

See [CHANGELOG.md](CHANGELOG.md) for version history.

## License

This plugin is licensed under the GPL v2 or later.

## Credits

Developed by Troy Chaplin
