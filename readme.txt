=== Smart Media Replacement ===
Contributors:      areziaal
Tags:              media, replace, revisions, media library, unused media
Requires at least: 7.0
Tested up to:      7.0
Stable tag:        1.3.0
Requires PHP:      8.0
License:           GPL-2.0-or-later
License URI:       https://www.gnu.org/licenses/gpl-2.0.html

Replace media files in place with revision history. Audit your library to find unused files and missing alt text.

== Description ==

Ever updated a PDF and realized half your site links to the old version? Or replaced a hero image and watched your carefully-tuned page layout collapse? Smart Media Replacement solves both problems — and adds a safety net you'll wish you had earlier.

**Replace the file, keep the URL.** When you swap an attachment with this plugin, the file's URL never changes. Every existing link, every email that points to it, every page that embeds it, every SEO ranking — all of it keeps working. No 404s, no broken references, no scrambling to update old content.

**Full revision history, one-click restore.** Every replacement automatically snapshots the previous version. Made a mistake? Roll back instantly. Want to see what the file looked like three months ago? Download it. Each revision is timestamped, attributed to the user who made it, and can carry an optional note describing what changed.

**Works where you work.** Replace from the Media Library, or from inside any block's Replace toolbar in the block editor — image, cover, video, audio, file, gallery, and more. The editor refreshes in place, no page reload, no lost work.

**Safe by default.** The plugin validates filenames, file types, and image dimensions to keep your URLs intact and your layouts unbroken. WordPress's auto-scaled images are handled transparently. Revisions land in a database table that's self-healing on every admin load, and the plugin's settings page gives you control over how many revisions to keep, how long to retain them, and which file types are tracked.

**Know what's actually being used.** The Media Audit screen builds an index of every post, page and template that references each file in your library. See at a glance which files are used and where, which are unused and safe to delete, and which images are embedded without alt text. Bulk-delete unused files with confidence — the delete controls only appear for files nothing references.

= Use cases =

* **Monthly reports and newsletters** — Update PDFs linked from past emails without breaking any of those links.
* **Brand refreshes** — Replace logos, headers, and brand imagery once; everywhere they're used updates automatically.
* **Legal documents** — Keep current versions of terms of service, privacy policy, and contracts live, with older versions preserved as revisions for compliance.
* **Image updates** — Refresh product photos, blog hero images, and marketing assets without breaking responsive sizes or SEO.
* **Typo fixes in published assets** — Fix errors in infographics, downloadable guides, or e-books without scrambling to update references across your site.
* **Versioned downloads** — White papers, e-books, technical docs that need to stay at a stable URL while preserving older versions on demand.
* **Media library cleanup** — Find the files nothing references and reclaim disk space, without guessing whether something is still in use.
* **Accessibility sweeps** — Surface every image embedded in content without alt text, in one filterable list.

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

* Settings page at Settings → Smart Media Replacement
* Storage stats: total revisions, total disk usage, database table status
* Optional deactivation cleanup (files and/or database)

**Multisite-ready**

* Network-activate only on multisite — one consistent configuration across every site
* Settings live at Network Admin → Settings → Smart Media Replacement and apply network-wide
* Revisions are stored per-site under each site's uploads directory; metadata lives in a single shared network table
* Daily retention cleanup runs across every site in the network
* Automatic row and file cleanup when a site is deleted
* Single-site installs work exactly as before — settings and storage stay per-site, no network behavior involved

**WP-CLI**

* `wp smr db check` — verify the revisions table exists (non-zero exit code if missing, safe for CI pipelines)
* `wp smr db repair` — recreate the table if missing; idempotent, safe to run when the table already exists
* `wp smr db status` — revision counts and storage usage; `--network` for a per-site breakdown on multisite
* `wp smr db cleanup` — delete expired revisions immediately, with `--dry-run` to preview and `--network` for all sites

**Other**

* Self-healing database tables via configurable scheduled check (hourly, daily, weekly, or disabled); use `wp smr db repair` for on-demand recovery
* Media Audit dashboard listing every file with its usage count, type, size, alt text and upload date
* Filter by where a file is used (block, featured image, content, post meta), by media type, by used/unused, and by missing alt text
* "Used In" popover listing the exact posts referencing a file, with edit links
* Bulk delete restricted to unused files only
* Index stays current as you edit — saving, trashing or deleting a post updates it without a rescan
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
3. (Optional) Visit Settings → Smart Media Replacement to configure revision history behavior
4. (Optional) Visit Media → Media Audit and run a scan to build the media usage index

= Uninstalling =

Deactivating the plugin leaves your data alone unless you opted in to the deletion settings. **Deleting** the plugin removes everything it owns: the revisions table, the media audit tables, all plugin options, cached file-size metadata, scheduled events, and stored revision files. That is irreversible, so export anything you want to keep first. On a large network, run `wp plugin uninstall smart-media-replacement` from WP-CLI so the per-site cleanup isn't bounded by a web request.

= Multisite =

On WordPress multisite the plugin is network-activate only — activate it once from Network Admin → Plugins, then configure it at Network Admin → Settings → Smart Media Replacement. The settings you choose apply to every site on the network. There is no per-site settings page on multisite.

The Media Audit works differently from the settings, deliberately: the audit index is built **per site**, because it indexes that site's own content. Each site gets its own Media → Media Audit screen and runs its own scan. Audit tables are created for every existing site on network activation, and automatically for any site created afterwards.

One caveat worth knowing: WP-Cron only runs for a site that is receiving traffic, so a scan started on a quiet subsite may appear to stall. Use `wp smr audit scan --site-id=<id>` (or `--network` for every site) to build indexes reliably from the command line.

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

= Auditing your media library =

1. Go to **Media → Media Audit**
2. Click **Scan Now** and let the index build — progress is shown in place
3. Use the filter chips to narrow to unused files, a media type, a usage location, or images missing alt text
4. Click a usage count to see exactly which posts reference that file
5. Select unused files and delete them in bulk — the delete controls only appear for files nothing references

On multisite, run the scan on each site, or use `wp smr audit scan --network` to build every site's index at once.

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

Revisions are created on **replacement**, not on the original upload — so a brand-new attachment shows no history until you've replaced it at least once. Also check Settings → Smart Media Replacement: the "Enable Revisions For" option lets you scope revisions to documents only, images only, or all file types. If your attachment's type isn't covered, no revisions will be tracked.

= Is there a WP-CLI interface? =

Yes. The plugin ships with `wp smr db check`, `wp smr db repair`, `wp smr db status`, and `wp smr db cleanup`, plus `wp smr audit scan`, `wp smr audit status`, and `wp smr audit clear` for the Media Audit index. These are useful in deployment pipelines, after database restores, and on large multisite networks where you want to run retention cleanup on a real system cron instead of relying on WP-Cron. All commands support `--network` and `--site-id=<id>` on multisite; `wp smr db cleanup` also accepts `--dry-run` and `--yes`.

= How does the Media Audit know where a file is used? =

It scans posts, pages and templates for references in block markup (image, cover, gallery, file, video, audio, media & text), in classic content (img tags, gallery and caption shortcodes, and links into your uploads directory), in featured images, and in page-builder post meta (Elementor and Beaver Builder by default, extendable via the `smart_media_replacement_audit_scanned_meta_keys` filter). Every candidate is validated against your real attachments, so stale markup can't inflate usage counts.

= Is it safe to delete everything marked "Unused"? =

"Unused" means nothing the scanner looks at references the file. That covers the overwhelming majority of cases, but it can't see references that live outside scanned content — hardcoded URLs in theme templates, custom post types you haven't added via the filter, or files linked from an external site. Review before bulk deleting, and note that "Unused" is only meaningful after a scan has completed.

= Do I have to re-scan after every edit? =

No. The index updates incrementally as you work — saving, trashing or permanently deleting a post updates its references immediately, and deleting an attachment removes it from the list. A full scan is only needed to build the index initially, or to rebuild it after importing content in bulk.

= Why is the Media Audit per site on multisite, when settings are network-wide? =

Because the index is derived from one site's own content. A network-level audit screen would have nothing meaningful to show, and each site's index needs to disappear when that site is deleted. Settings are shared because they describe plugin behaviour, not content.

= Can I turn the Media Audit off? =

Yes. Untick "Enable Media Audit" in the settings. That hides the screen and stops posts being indexed when saved. Existing index data is left in place, so re-enabling it doesn't require a rescan.

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
8. The Media Audit dashboard — every file with its usage count, type, size and alt text.
9. Filter by where a file is used, its type, whether it's used at all, and whether it's missing alt text.
10. The "Used In" popover lists the exact posts referencing a file, with edit links.
11. Build the index from the scan toolbar and watch progress in place.

== Changelog ==

= What's new in 1.3.0 =

**Media Audit.** A new Media → Media Audit screen indexes which posts, pages and templates reference each file in your library. Find unused files, see exactly where a file is used before deleting it, and spot images embedded without alt text. Filter by usage location, media type, used/unused, and missing alt. Bulk delete is restricted to unused files.

**New WP-CLI commands.** `wp smr audit scan`, `wp smr audit status` and `wp smr audit clear`, all supporting `--site-id` and `--network` on multisite. On a network these are the reliable way to build indexes, since WP-Cron only fires for sites receiving traffic.

**Breaking:** this release requires **WordPress 7.0** and **PHP 8.0**. The audit interface is built on the WordPress DataViews component, which is only available in WordPress 7.0 and later.

**Settings moved.** The settings page is now at **Settings → Smart Media Replacement** instead of Media → Replacement Settings. Bookmarks to `?page=smr-settings` still work — only the parent menu changed.

**Also changed:** "Delete database on deactivation" now covers the audit tables as well as the revisions table. Deleting the plugin now removes all plugin data including stored revision files — see the Uninstalling section above. Media Library scripts are now cache-busted per release rather than pinned to a fixed version.

**Multisite fix.** Turning off "Enable Media Audit" or "Enable Revisions" from the Network Admin settings page now sticks. Previously the first "off" save was dropped on networks that had updated the plugin in place.

**Audit scan fix.** The Media Audit scan no longer gets stuck at 100%. A scan could stall forever if the library held an attachment whose file size could not be read — for example media offloaded to cloud storage or files missing from disk.

= What's new in 1.2.0 =

Multisite behavior is now consistent and centrally managed.

* **Network-activate only on multisite.** WordPress no longer exposes a per-site activation link — a super admin network-activates the plugin once.
* **Network-wide settings.** All configuration lives at Network Admin → Settings → Media Replacement and applies to every site on the network. The per-site settings page is no longer registered on multisite.
* **Retention cron is network-aware.** The daily cleanup iterates every site so retention applies across the entire network, not just the main site.
* **Plugin "Settings" shortcut on the Plugins screen** points to the correct admin (network or site) in both contexts.

**Upgrading on multisite:** per-site settings stored under v1.1.x are not carried forward to the network store. After upgrading, a super admin should visit Network Admin → Settings → Media Replacement once and confirm the values. Single-site installs are unaffected.

= What's new in 1.1.1 =

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
