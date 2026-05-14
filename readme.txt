=== Smart Media Replacement ===
Contributors:      areziaal
Tags:              media, replace, revisions, attachment, pdf
Requires at least: 6.6
Tested up to:      7.0
Stable tag:        1.1.1
Requires PHP:      7.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Replace media files in place — same URL, new content, no broken links. Includes versioned revision history with one-click rollback.

== Description ==

Ever updated a PDF and realized half your site links to the old version? Or replaced a hero image and watched your carefully-tuned page layout collapse? Smart Media Replacement solves both problems — and adds a safety net you'll wish you had earlier.

**Replace the file, keep the URL.** When you swap an attachment with this plugin, the file's URL never changes. Every existing link, every email that points to it, every page that embeds it, every SEO ranking — all of it keeps working. No 404s, no broken references, no scrambling to update old content.

**Full revision history, one-click restore.** Every replacement automatically snapshots the previous version. Made a mistake? Roll back instantly. Want to see what the file looked like three months ago? Download it. Each revision is timestamped, attributed to the user who made it, and can carry an optional note describing what changed.

**Works where you work.** Replace from the Media Library, or from inside any block's Replace toolbar in the block editor — image, cover, video, audio, file, gallery, and more. The editor refreshes in place, no page reload, no lost work.

**Safe by default.** The plugin validates filenames, file types, and image dimensions to keep your URLs intact and your layouts unbroken. WordPress's auto-scaled images are handled transparently. Revisions land in a database table that's self-healing on every admin load, and the plugin's settings page gives you control over how many revisions to keep, how long to retain them, and which file types are tracked.

= Use cases =

* **Monthly reports and newsletters** — Update PDFs linked from past emails without breaking any of those links.
* **Brand refreshes** — Replace logos, headers, and brand imagery once; everywhere they're used updates automatically.
* **Legal documents** — Keep current versions of terms of service, privacy policy, and contracts live, with older versions preserved as revisions for compliance.
* **Image updates** — Refresh product photos, blog hero images, and marketing assets without breaking responsive sizes or SEO.
* **Typo fixes in published assets** — Fix errors in infographics, downloadable guides, or e-books without scrambling to update references across your site.
* **Versioned downloads** — White papers, e-books, technical docs that need to stay at a stable URL while preserving older versions on demand.

= Features =

**Replacement and URL preservation**

* Replace any media file while keeping its URL, ID, and metadata intact
* Existing internal and external links keep working — no 404s, no SEO impact
* Automatic regeneration of image size variants (thumbnails, medium, large, etc.)
* Transparent handling of WordPress's `-scaled` large images

**Revision history**

* Every replacement automatically snapshots the previous file
* Major/minor version numbering (e.g. v1.0 → v1.1 → v2.0)
* Optional replacement note per revision, attributed to the user
* One-click restore of any past version
* Download individual revisions, or download a ZIP archive of an attachment's full history
* Configurable: maximum revisions per file, age-based retention with daily cleanup, per-file-type opt-in

**Block editor integration**

* "Update existing file" in every block's Replace toolbar dropdown (image, cover, video, audio, file, gallery, media-text, post-featured-image)
* In-place editor refresh after replacement — no page reload, no lost work
* Accessibility-friendly notifications via WordPress's native screen reader announcements

**Validation safeguards**

* Filename matching to keep URLs stable
* Image dimension matching to prevent layout breakage
* MIME-type matching to prevent file corruption
* Clear, actionable error messages when something doesn't match

**Built-in admin**

* Settings page at Media → Replacement Settings
* Storage stats: total revisions, total disk usage, database table status
* Optional deactivation cleanup (files and/or database)

**Site-wide compatibility**

* Multisite support: per-site storage, per-site settings, automatic cleanup on site deletion
* Self-healing database table on every admin load
* Developer hooks throughout for custom integrations

= Privacy =

This plugin is fully self-contained and respects your privacy:

* Does not collect or transmit any user data
* Does not use cookies or third-party tracking
* Only processes files locally on your server
* Does not communicate with external services or APIs

== Installation ==

1. Install from the WordPress plugin directory, or upload the `smart-media-replacement` folder to `/wp-content/plugins/`
2. Activate through the Plugins menu in WordPress
3. (Optional) Visit Media → Replacement Settings to configure revision history behavior

== Usage ==

= From the Media Library =

1. Navigate to the WordPress Media Library
2. Open the file you want to replace (click on it, or use the row actions in list view)
3. Click **Replace File**
4. Choose your new file — if revisions are enabled, you'll be prompted for an optional note and version type (minor/major)
5. The replacement happens immediately, preserving the file's URL

= From the block editor =

1. Click on any media block (image, cover, video, audio, file, gallery, etc.)
2. Open the **Replace** toolbar dropdown
3. Choose **Update existing file**
4. Pick your new file — the editor refreshes in place

= Viewing and managing revisions =

* In the Media Library list view, the **Revisions** row action opens the full revision history for that file
* On the attachment edit screen, the **View Revisions** button opens the same panel
* From either, you can download any past version individually or as a ZIP, and restore any revision with one click

== Frequently Asked Questions ==

= Will my existing links still work after I replace a file? =

Yes — that's the whole point. The replacement keeps the file's URL and ID unchanged, so every existing link, embed, share, and SEO reference continues to work normally.

= Can I undo a replacement if something goes wrong? =

Yes. As long as revisions are enabled for the file type, every replacement preserves the previous version. Open the file's revision history and click **Restore** on any past version. The current file is also snapshotted before the restore, so nothing is lost.

= Does this work in the block editor? =

Yes. Every block that has a Replace toolbar — image, cover, video, audio, file, gallery, media-text, post-featured-image — gets an **Update existing file** option in the Replace dropdown. The editor refreshes in place after replacement, so you don't lose unsaved work.

= What happens to image thumbnail variants after replacement? =

WordPress regenerates all configured image sizes (thumbnail, medium, large, etc.) automatically. Their URLs stay stable too, so responsive images and srcset attributes continue working.

= Can I download a previous version of a file? =

Yes. Each revision has its own Download button. You can also download a ZIP archive containing every revision for a file at once.

= Why must my replacement file have the same name as the original? =

That's how WordPress serves files — the URL contains the filename. Matching the original filename is what keeps your existing links intact. The plugin shows you the exact filename to use if there's a mismatch.

= Why must image dimensions match? =

Different dimensions can break responsive layouts, hero image sizing, and carefully-tuned cropping across your site. Enforcing identical dimensions protects against unexpected layout shifts. Developers can disable this per-attachment with the `smart_media_replacement_enforce_dimensions` filter if needed.

= Can I replace a JPG with a PNG? =

No. The replacement file must be the same MIME type as the original. Mixing file types can break image processing, browser display, and SEO. If you need to change formats, upload as a new image.

= What if my image was scaled by WordPress? =

If WordPress automatically created a `-scaled` variant (typical for uploads over 2560px), upload your replacement with the **original** filename — without the `-scaled` suffix. The plugin handles the scaling and regenerates all variants automatically. If you upload with the wrong filename, the error message will tell you exactly what name to use.

= I have revisions enabled but I don't see any history. Why? =

Revisions are created on **replacement**, not on the original upload — so a brand-new attachment shows no history until you've replaced it at least once. Also check Media → Replacement Settings: the "Enable Revisions For" option lets you scope revisions to documents only, images only, or all file types. If your attachment's type isn't covered, no revisions will be tracked.

= Do I need special permissions? =

Yes — you need the `edit_post` capability for the specific attachment. This matches WordPress's standard media editing permission model.

== Screenshots ==

1. Update an inserted image straight from the block toolbar — no page reload, no lost work.
2. Pick a replacement file and add an optional note describing what changed.
3. Replacements are validated against the original — file type, filename, and image dimensions — so URLs stay stable and layouts stay intact.
4. Replace files or view revisions directly from the Media Library row actions.
5. Same replacement flow from the attachment edit screen, with the optional replacement note.
6. Browse, restore, or download past versions — or grab the full history as a ZIP — all from one panel.
7. Configure revision retention, file-type tracking, comment requirements, and deactivation cleanup from one settings page.

== Changelog ==

= What's new in 1.1.0 =

A major feature release. Revision history, block editor integration, and a handful of reliability improvements for managed hosts.

**New features**

* **Revision history.** Every replacement now snapshots the previous file, tracked with major/minor versioning and an optional note. Restore any past version with one click.
* **Block editor integration.** "Update existing file" now appears in every block's Replace toolbar — image, cover, video, audio, file, gallery, and more. The editor refreshes in place, no page reload, no lost work.
* **Download past versions.** Save any individual revision, or grab a ZIP archive of an attachment's full history.
* **Settings page.** Control which file types get revisions, how many to keep, how long to retain them, the default version-bump behavior, and whether replacement notes are required.
* **Multisite support.** Per-site revision storage and settings, automatic cleanup on site deletion, defaults seeded for new sites.

**Reliability improvements**

* Replacements now work on managed hosts where strict file ownership previously caused 500 errors during the upload step.
* Files are no longer at risk during a failed replacement — the new file is placed before the old one is cleaned up.
* Smoother restore: no more duplicate confirmations or accidental double-rollbacks.
* Failed uploads (wrong dimensions, wrong file type) no longer leave behind orphan revisions.
* The revisions database table is now self-healed on every admin load — recovers automatically from DB resets without needing a deactivate/reactivate.

**Security hardening**

* Path-traversal protection on revision file lookups.
* Directory listing prevention on the revisions storage folder.
* Stricter input validation on version-type parameters.

[View all version changes](https://github.com/troychaplin/smart-media-replacement/blob/main/CHANGELOG.md)

= 1.0.0 =

* Initial release
* Replace media files while maintaining URLs
* Filename validation to prevent URL changes
* Image dimension enforcement to prevent layout issues
* WordPress scaled image handling
* File type validation for consistency
* AJAX-based replacement with error handling
* Developer hooks for customization
