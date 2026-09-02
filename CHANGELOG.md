# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Prefix the change with one of these keywords:

- _Added_: for new features.
- _Breaking_: for higher visibility of breaking changes
- _Changed_: for changes in existing functionality.
- _Deprecated_: for soon-to-be removed features.
- _Removed_: for now removed features.
- _Fixed_: for any bug fixes.
- _Security_: in case of vulnerabilities.

## [Unreleased]

### Breaking

- **Requires WordPress 7.0 and PHP 8.0** (previously 6.6 / 7.4). The Media Audit interface is built on `@wordpress/dataviews`, which is bundled into the plugin and unlocks WordPress private APIs — it only works where core registers `@wordpress/dataviews` in its private-API allow-list, which is WordPress 7.0 and later.
- **Uninstalling the plugin now deletes stored revision files.** Previously they survived deletion unless the "Delete files on deactivation" opt-in was set. Uninstall now means "remove everything the plugin owns": both database tables, all options, cached file-size meta, scheduled events, and revision files on disk.
- **Every plugin hook now uses the full `smart_media_replacement_` prefix**, for compliance with the WordPress.org Plugin Check tool (which auto-derives the expected prefix from the plugin's Text Domain and does not recognize the abbreviated `smr_` this plugin previously used for hooks, even though it's a deliberate, internally-consistent convention). **Options, transients, table names, cron hook names, nonces, and AJAX action names are unaffected** — only `add_filter`/`do_action` hook names changed. If you've hooked into any of these, update the hook name:

  | Old | New |
  |---|---|
  | `smr_create_revision` | `smart_media_replacement_create_revision` |
  | `smr_revision_created` | `smart_media_replacement_revision_created` |
  | `smr_max_revisions` | `smart_media_replacement_max_revisions` |
  | `smr_retention_days` | `smart_media_replacement_retention_days` |
  | `smr_revisions_cleaned` | `smart_media_replacement_revisions_cleaned` |
  | `smr_revision_restored` | `smart_media_replacement_revision_restored` |
  | `smr_revision_directory` | `smart_media_replacement_revision_directory` |

  (`smr_cleanup_time_limit`, `smr_cleanup_chunk_size` and the four `smr_audit_*` filters were unreleased in prior versions, so they're listed under Added below with their final names rather than here.)

### Added

- **Media Audit** — absorbed from the standalone [Attached: Media Audit](https://github.com/troychaplin/attached-media-audit) plugin, which is now archived. Adds a Media → Media Audit screen that indexes which posts, pages and templates reference each attachment, so you can find unused files, see where a file is used before deleting it, and surface images embedded without alt text. Built on `@wordpress/dataviews` with filtering by usage location, media type, used/unused, and missing alt text.
- **REST route** `GET /smart-media-replacement/v1/audit-media` — paginated, filterable attachment list backing the audit screen. Requires `manage_options`. Supports `page`, `per_page`, `search`, `orderby`, `order`, `media_type`, `reference_type`, `usage_filter` and `missing_alt`, and publishes a full item schema.
- **Mark files for deletion, then review before deleting.** The audit screen gains a review queue: mark files from the bulk toolbar, or mark an entire filtered set across every page with **Mark all N matching files**, then filter to the queue with **Review queue**, unmark anything you want to keep, and delete what remains. The mark is stored as attachment meta so it is shared between administrators, survives a rescan, and persists across sessions. A new **Marked** filter and **Queued** column expose it in the list.
- **Bulk actions on the audit screen.** Selecting rows now offers Mark for deletion, Remove mark and Delete permanently. Previously no bulk action was reachable at all: the delete action was missing `supportsBulk`, which is what gates DataViews' checkboxes and bulk toolbar, so it only ever appeared in the per-row menu.
- **REST routes** `POST /smart-media-replacement/v1/audit-media/mark` and `DELETE /smart-media-replacement/v1/audit-media`, for marking and deleting. Both require `manage_options` and `upload_files`, and check `delete_post` per attachment. The mark route accepts either an ID list or `all_matching` plus the current filters, capped at 5,000 attachments per call; the delete route is capped at 100 per request.
- **A thumbnail grid layout** on the audit screen, alongside the table. It replaces the list layout, which DataViews does not render bulk actions in.
- **WP-CLI commands** under `wp smr audit`: `scan`, `status` and `clear`, each supporting `--site-id=<id>` and `--network` on multisite. `scan` runs the batch loop synchronously rather than scheduling cron ticks — on a network this matters, because WP-Cron only fires for a site that is receiving traffic, so a quiet subsite's scan would otherwise never advance.
- **`smr_enable_audit` setting** — turns the audit screen and per-save indexing off without removing existing index data.
- **`smart_media_replacement_audit_scanned_meta_keys` filter** — post meta keys scanned for page-builder media references. Defaults to `_elementor_data` and `_fl_builder_data`.
- **`smart_media_replacement_audit_scan_post_types` filter** — post types the scanner walks. Defaults to `post`, `page`, `wp_template`, `wp_template_part`.
- **`smart_media_replacement_audit_scan_statuses` filter** — post statuses treated as live content.
- **`smart_media_replacement_audit_batch_size` filter** — posts indexed per cron tick. Default 50.
- **`uninstall.php`** — the plugin had none, so options survived deletion. Now removes all plugin data, multisite-aware, using `Settings::delete_all()` (which existed but was never called).
- **Multisite provisioning for the audit tables.** Audit tables are per-site, so network activation provisions every existing site, `wp_initialize_site` provisions new ones, and a lazy guard creates them on first use for anything missed (including networks large enough that the activation loop is skipped). A `wpmu_drop_tables` filter removes them when a site is deleted — core only drops its own fixed table list, so without this every deleted subsite would leak two tables.

- **WP-CLI commands** under `wp smr db`: `check` (verify the revisions table exists), `repair` (recreate it if missing), `status` (revision counts and storage usage, with `--network` for a per-site breakdown), and `cleanup` (delete expired revisions on demand). All commands support `--site-id=<id>` and `--network` on multisite; `cleanup` additionally accepts `--dry-run` and `--yes`.
- **Database health-check cron** (`smr_db_health_check`). The table self-heal that previously ran on every admin page load is now a configurable scheduled event (hourly / daily / weekly / disabled), reducing unnecessary database queries on large networks. The frequency is controlled via a new "Database Health Check" setting. When set to disabled, use `wp smr db repair` for on-demand recovery.
- **`smart_media_replacement_cleanup_time_limit` filter** — lets operators set the maximum number of seconds the daily retention cron may run before stopping gracefully. Defaults to `max_execution_time − 10 s` (floor 5 s), or 60 s when `max_execution_time` is unlimited.
- **`smart_media_replacement_cleanup_chunk_size` filter** — controls how many expired revisions are processed per database round-trip during cleanup. Default 100.

### Security

- **The "unused files only" rule for deleting media is now enforced on the server.** It previously existed only in the browser: the screen hid the delete control for in-use files, but deletion itself went through core's `DELETE /wp/v2/media/<id>?force=true`, which knows nothing about the audit index — so a request that bypassed the UI could permanently delete a file that posts were still using. Deletion now goes through the plugin's own route, which checks every ID against the index first and refuses anything with a usage count above zero, or with no index row at all (unknown usage is treated as unsafe, not as zero).

### Changed

- **Deleting media is now confirmed in a proper modal** listing the affected files, instead of a browser `confirm()` dialog, and the result is reported in a snackbar — including how many files were skipped because they are still in use. Previously a failed delete rejected silently and left stale rows on screen.
- **`wp_delete_attachment()` now respects `MEDIA_TRASH`.** On sites that enable the media trash, deleted files land there and stay recoverable rather than being removed outright.
- **Deleting a file is no longer duplicated between the row link and the actions menu.** The "Delete Permanently" link in the File Name column is gone; deletion lives in the DataViews actions menu and bulk toolbar, which is a single code path with a single eligibility rule.
- **The settings page moved from Media to Settings.** On single-site it is now **Settings → Smart Media Replacement** (previously Media → Replacement Settings), registered via `add_options_page()`. The page slug (`smr-settings`) is unchanged, so any bookmarked `?page=smr-settings` URL still resolves — only the parent file differs. On multisite it stays at Network Admin → Settings, renamed to match. The "Settings" shortcut on the Plugins screen was updated to point at the new location.
- **"Delete database on deactivation" now covers every table the plugin owns**, not just the revisions table. The setting label and description were reworded to match — previously it claimed to delete the database while leaving the audit tables behind.
- **Build toolchain moved to `@wordpress/scripts` 32**, which brings ESLint 9 and flat config. `.eslintrc.json` and `.eslintignore` are replaced by `eslint.config.mjs`, and the required Node version moves to 22 (`.nvmrc` and CI). Note the config must be ESM: `@wordpress/eslint-plugin` 25 loads design tokens from an ESM-only module.
- **`npm run lint` now runs stylelint** via a new `lint:css` script. Stylelint was configured but nothing invoked it.
- **The audit REST endpoint no longer writes during a GET.** Recomputed file sizes are handed to a one-shot `smr_audit_backfill_filesizes` cron event instead of being persisted inline, keeping the read path read-only.

- **Retention cleanup is now chunked and time-bounded.** `RevisionManager::cleanup_site()` processes expired revisions in configurable batches (default 100 rows) using cursor-based pagination, replacing the previous unbounded `SELECT *`. A single `DELETE … WHERE id IN (…)` per chunk replaces the prior per-row deletes. On multisite the cron stops iterating sites when the time budget is exhausted, with remaining sites handled on the next daily run.
- **`RevisionManager::cleanup_site()` is now public and static**, allowing it to be called directly from WP-CLI without instantiating the class and without a time limit.

### Fixed

- **The Media Audit list was empty on any site using plain permalinks.** The list request built its URL by concatenating `?` and the query string onto `rest_url()`. With plain permalinks the REST root is `/index.php?rest_route=…`, which already carries a query string, so the result contained two `?` characters, the route never matched, and every request 404'd — the screen showed "No media found" no matter what the index contained. The request now goes through `apiFetch`, whose root-URL middleware rewrites the separator correctly for both permalink styles.
- **The audit list's "Without Alt" filter could serve the wrong results on sites with a persistent object cache.** `IndexTable::get_attachments_rest()` built its cache key from every argument except `missing_alt`, so a filtered query and an otherwise identical unfiltered one collided and returned each other's rows.
- **`SMART_MEDIA_REPLACEMENT_VERSION` no longer drifts from the plugin header.** The constant said `1.2.0` while the header, `package.json` and `readme.txt` all said `1.2.1`, so revision UI styles were cache-busted with a stale version.
- **The Media Library script is no longer pinned to a hardcoded `1.0.0` version**, which meant browsers kept a cached copy across every plugin release. It now uses the generated build hash and its extracted dependency list, and receives script translations.
- **`Settings::seed_defaults()` now uses a null sentinel** to distinguish "never set" from a stored falsy value, so a deliberately disabled boolean setting cannot be re-enabled by a later activation.
- **"Enable Media Audit" (and "Enable Revisions") can now be turned off on multisite.** The network settings save went through `update_site_option()`, which silently drops the first write of a falsy value when the option row was never created — the case on any network that updated the plugin in place rather than re-activating it. `Settings::update()` now creates a missing network option with `add_site_option()` so the "off" state persists. Single-site was unaffected.
- **The audit scan no longer sticks at 100%.** The file-size phase looped forever when any attachment's size could not be resolved — a file offloaded to cloud storage, deleted from disk, or an older upload with no `filesize` in its metadata — because the cached-size meta was only written for non-zero sizes, so the "still missing a size" set never emptied. The scanner now records every attachment (0 = size unknown), letting the phase finish and the scan complete.
- **Deactivation now clears all occurrences of each scheduled event.** The previous `wp_next_scheduled()` + `wp_unschedule_event()` pair only removed the next one, leaving duplicates behind.
- **The single-row "Delete Permanently" action in the audit list is now restricted to unused files**, matching the eligibility rule already enforced on the bulk action. Previously a file referenced by many posts was one click and one confirm from permanent deletion.

## [1.2.0]

### Breaking

- **Multisite is now network-activate only.** The plugin declares `Network: true`, so on multisite WordPress no longer exposes a per-site activation link — a super admin must network-activate. Existing per-site activations are not migrated. Single-site installs are unaffected.
- **Minimum PHP is now 7.4.** Previous releases advertised 7.0 but actually required 7.1+ for the `: void` return types used throughout the codebase. The minimum has been aligned with reality and raised to a currently-supported version.

### Changed

- **Settings on multisite are now network-wide.** All plugin options apply to every site on the network and are configured at Network Admin → Settings → Media Replacement (`manage_network_options` capability). The per-site Media → Replacement Settings page is no longer registered on multisite. Single-site installs continue to use Media → Replacement Settings unchanged.
- **Multisite upgrade note.** Per-site settings stored under v1.1.1 are not carried forward to the network store — after upgrading, a super admin should visit the network settings page once and confirm the values.
- **Retention cron is network-aware.** On multisite, the daily cleanup iterates every site so retention applies to revisions across the entire network, not just the main site.
- **Plugin "Settings" shortcut on the Plugins screen** now points to the correct admin (network or site) in both contexts.

## [1.1.1]

### Added

- **Revision system.** The previous file is snapshotted on every replacement and tracked in a custom `wp_smr_revisions` table, with revision files stored under `wp-content/uploads/smr-revisions/<attachment_id>/`. Supports major/minor version numbering, optional replacement notes, configurable maximum revisions per file, an age-based retention policy enforced via a daily cron, and per-file-type opt-in (documents, images, or all).
- **Revision history modal.** A "Current file" panel at the top shows the live attachment (filename, size, "Active since" timestamp, and the most recent replacement note), followed by chronological replacement history with per-revision Download and Restore actions and a ZIP "Download all" for the full history.
- **Replace modal.** Collects replacement note and version-type choice before submission. Version labels are derived from the attachment's actual current version (e.g. `Minor (v2.3 → v2.4)`). Attachments with no prior revisions show "First revision will be saved as v1.0" instead of an empty radio choice.
- **Settings page** at Media → Replacement Settings: enable/disable revisions globally, file-type filter, max revisions per file, retention period (days), default version type, require-comment toggle, and per-deactivation cleanup options.
- **Block editor integration.** An "Update existing file" item appears inside every block's Replace toolbar dropdown (image, cover, audio, video, file, gallery, media-text, post-featured-image), wired in via the official `editor.MediaReplaceFlow` filter. Opens a React modal for version/note selection when revisions apply, or a direct file picker when they don't. On success the editor refreshes through Gutenberg-native APIs (`invalidateResolution` on core-data + DOM cache-bust on visible `<img>` tags inside the editor canvas iframe) — no page reload, editor state preserved.
- **Multisite support.** Revision storage and settings are per-site; new sites are auto-seeded with default options on creation; revisions are cleaned up automatically when a site is deleted.
- **Self-healing database table.** `RevisionDatabase::ensure_table()` runs on every admin load and recreates the revisions table if it's missing. Recovers from DB resets, manual drops, and aborted activations without requiring a deactivate/reactivate.
- **Developer hooks**: `smart_media_replacement_before_replace`, `smart_media_replacement_file_replaced`, `smart_media_replacement_enforce_dimensions`, `smr_create_revision`, `smr_revision_created`, `smr_revisions_cleaned`, `smr_revision_restored`, `smr_max_revisions`, `smr_retention_days`, `smr_revision_directory`.

### Changed

- Replacement notes now display on the file they describe (the version introduced by the event) rather than on the retired snapshot. The original upload shows no note; the current live file shows the most recent note.
- Comment field renamed to "Replacement note" in both the replace modal and the revision history, with a consistent `Replacement note: "…"` label so the vocabulary matches between write-time and read-time.
- "Latest" badge removed from revision rows. The live file now appears as a separate "Current file" panel at the top of the history modal, clearly distinct from the historical revisions below.
- Replace modal is skipped entirely when no revision will be created (global revisions off, or file-type filter excludes the attachment), restoring a single-click replace flow for those cases.
- Restore success/failure shows an inline modal notice instead of a native `window.alert`, with a brief delay before reload so the user can read confirmation.
- `Requires at least` and `Tested up to` bumped to WordPress 7.0.
- Internal refactor: a shared `Helpers` class now consolidates filename parsing, file-type eligibility checks, and old-file cleanup that was previously duplicated between `ManageMedia` and `RevisionManager`.

### Fixed

- **500 error during replacement** on hosts where `WP_Filesystem` fell back to FTP without credentials. The replacement, restore, revision storage, and revision cleanup paths now use native PHP file operations (`move_uploaded_file`, `copy`, recursive `rmdir`) and no longer probe filesystem ownership.
- **Data loss when a replacement or restore failed mid-flight.** The previous flow deleted the old files before placing the new one, so a failure could leave the attachment with neither. The new ordering places the new file first, then cleans up the old.
- **Restoring a revision triggered two confirm prompts and ran twice** due to a duplicate click handler on the restore button.
- **`smart_media_replacement_before_replace` fired before dimension validation**, leaving junk revisions behind from rejected uploads. The action now fires only after all validation passes.
- **ZIP archives of revisions leaked.** They were written to the publicly-accessible uploads directory, and the cleanup line was unreachable (it came after `serve_download`'s `exit`). Archives are now created in the system temp directory and removed via a shutdown function after streaming.
- **"A comment is required" error** fired for replacements that wouldn't actually create a revision (e.g. images when only documents are tracked).
- **`getimagesize()` PHP warning** when the current attachment file no longer existed on disk; the call is now guarded with `file_exists()`.

### Security

- `RevisionStorage::get_full_path()` rejects path-traversal attempts in stored revision paths — defense in depth against tampered DB rows feeding the download endpoint.
- Base `smr-revisions/` directory now contains an `index.php` to prevent directory listing on hosts with directory indexing enabled.
- `version_type` POST parameter is constrained to the `major`/`minor` enum before reaching the version calculator.

## [1.0.0]

- Initial release
- Replace media files while maintaining URLs
- Filename validation to prevent URL changes
- Image dimension enforcement to prevent layout issues
- WordPress scaled image handling
- File type validation for consistency
- AJAX-based replacement with error handling
- Developer hooks for customization