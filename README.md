<img src="assets/banner-772x250.png" alt="Smart Media Replacement Plugin Banner" style="width: 100%; height: auto;">

# Smart Media Replacement Plugin

A WordPress plugin that allows you to replace media files while maintaining their original URLs and metadata, and audit which files in your library are actually being used. Useful for updating files like PDFs, images, or other documents without breaking existing links — and for finding the ones nothing links to at all.

## Features

- **Replace media files while preserving their URL and ID** — existing internal and external links keep working, attachment metadata and relationships are untouched
- **Revision history** — every replacement snapshots the previous file, tracked with major/minor versioning and an optional replacement note describing what changed
- **Restore any prior version** with a single click; restoring also snapshots the current file so nothing is lost
- **Block editor integration** — "Update existing file" appears in every block's Replace toolbar dropdown (image, cover, audio, video, file, gallery, etc.); the editor refreshes in place without a page reload
- **Configurable revision policy** — per-file-type opt-in (documents, images, or all), maximum revisions per file, age-based retention with daily cron cleanup, default major/minor behavior, and an optional require-note toggle
- **Download revisions** — grab any individual revision, or download a ZIP archive of an attachment's full history
- **Multisite-ready** — network-activate only, with one set of settings at Network Admin → Settings → Smart Media Replacement applied to every site; revision files are stored under each site's uploads directory while metadata lives in a single shared network table; daily retention cleanup runs across the network; automatic row and file cleanup when a site is deleted. Single-site installs work exactly as before.
- **Media Audit** — a Media → Media Audit screen indexing which posts, pages and templates reference each attachment. Find unused files, see exactly where a file is used before deleting it, and surface images embedded without alt text. Filter by usage location, media type, used/unused and missing alt; bulk delete restricted to unused files. The index updates incrementally as content changes, so a full rescan is only needed to build it initially.
- **WP-CLI commands** — `wp smr db check`, `repair`, `status`, and `cleanup` for on-demand health checks, diagnostics, and retention cleanup without relying on WP-Cron; `wp smr audit scan`, `status` and `clear` for the media audit index
- **Validates file names** to prevent accidental URL changes
- **Enforces dimension matching for images** to prevent layout issues
- **Automatically handles WordPress scaled images** (`-scaled` variants)
- **Validates file type matching** to ensure MIME consistency
- AJAX-based replacement with inline error handling and accessibility announcements

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

For the full hook reference — every action, filter, and WP-CLI command with parameter tables and examples — see [DEVELOPERS.md](DEVELOPERS.md).

### WP-CLI

```bash
wp smr db check              # Verify the revisions table exists
wp smr db repair             # Recreate the table if missing
wp smr db status             # Revision counts and storage usage
wp smr db status --network   # Per-site breakdown on multisite
wp smr db cleanup            # Delete expired revisions now (no time limit)
wp smr db cleanup --dry-run  # Preview what would be deleted
wp smr db cleanup --network  # Run across every site on multisite

wp smr audit scan            # Build the media audit index for this site
wp smr audit scan --site-id=3   # Build it for one subsite
wp smr audit scan --network     # Build it for every site on the network
wp smr audit status          # Index state, file counts, unused count
wp smr audit clear --yes     # Wipe the index (tables are kept)
```

`wp smr audit scan` runs the scan synchronously rather than scheduling cron ticks. On multisite this is the reliable path: WP-Cron only fires for a site receiving traffic, so a scan started from the admin of a quiet subsite can appear to stall.

### Hooks

#### Filters

| Hook | Controls |
|------|----------|
| `smart_media_replacement_create_revision` | Whether to create a revision for a given attachment |
| `smart_media_replacement_max_revisions` | Maximum revisions to keep per attachment |
| `smart_media_replacement_retention_days` | Retention period in days (0 = disabled) |
| `smart_media_replacement_cleanup_time_limit` | Seconds the daily cron cleanup may run before stopping gracefully |
| `smart_media_replacement_cleanup_chunk_size` | Rows processed per database round-trip during cleanup |
| `smart_media_replacement_revision_directory` | Base filesystem path for revision file storage |
| `smart_media_replacement_enforce_dimensions` | Whether image dimensions must match on replace |

#### Actions

| Hook | Fires when |
|------|-----------|
| `smart_media_replacement_before_replace` | Validation passed, file not yet swapped |
| `smart_media_replacement_file_replaced` | File swapped and metadata updated |
| `smart_media_replacement_revision_created` | A revision is saved to disk and database |
| `smart_media_replacement_revision_restored` | A revision is restored as the live file |
| `smart_media_replacement_revisions_cleaned` | Old revisions are deleted (limit enforced or retention expired) |

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

- `Functions/` - PHP classes and WordPress hooks (PSR-4, namespace `Smart_Media_Replacement\`)
  - `ManageMedia.php` - Core replacement functionality and AJAX handlers
  - `RevisionManager.php` / `RevisionDatabase.php` / `RevisionStorage.php` - Revision lifecycle, queries and file storage
  - `Settings.php` / `SettingsPage.php` - Option resolver and settings screen
  - `EditorIntegration.php` / `RevisionUI.php` - Block editor and admin asset integration
  - `CLI.php` - WP-CLI commands (`wp smr db`)
  - `AuditCLI.php` - WP-CLI commands (`wp smr audit`)
  - `Audit/` - Media audit subsystem (namespace `Smart_Media_Replacement\Audit\`)
    - `IndexTable.php` - Schema and queries for the per-site index and summary tables
    - `BatchRunner.php` - Three-phase background scanner
    - `PostScanner.php`, `BlockParser.php`, `ClassicParser.php`, `MetaParser.php` - Reference detection
    - `RestController.php` - `GET /smart-media-replacement/v1/audit-media`
    - `AuditPage.php`, `AuditAjax.php`, `AuditIndexer.php` - Admin screen, AJAX, and hook registration
- `src/` - JavaScript source files
  - `smart-media-replacement.js` - Media Library interaction and file upload handling
  - `editor.js` - Block editor MediaReplaceFlow integration
  - `media-audit/` - The Media Audit React app (`@wordpress/dataviews`)
  - `revision-ui.css` - Revision panel styles
- `build/` - Compiled assets (generated by webpack)
- `uninstall.php` - Removes all plugin data on deletion
- `vendor/` - Composer dependencies

### Development Notes

- The plugin uses WordPress's native media handling functions
- File validation happens server-side for security
- The JavaScript uses the WordPress i18n system for translations
- PHPCS is configured with WordPress coding standards; `npm run lint` runs ESLint, stylelint and PHPCS
- ESLint uses flat config (`eslint.config.mjs`). It must stay ESM — `@wordpress/eslint-plugin` 25 loads design tokens from an ESM-only module, which throws `ERR_REQUIRE_ESM` if the config is evaluated as CommonJS
- `@wordpress/dataviews` is bundled rather than externalized (it is in the dependency-extraction plugin's `BUNDLED_PACKAGES` list), which is why the audit bundle is large and why the plugin requires WordPress 7.0

## Requirements

- WordPress 7.0 or higher
- PHP 8.0 or higher
- Node 22 or higher for development (see `.nvmrc`)
- User must have `edit_post` capability for the specific attachment to replace it
- User must have `manage_options` to use the Media Audit screen

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
