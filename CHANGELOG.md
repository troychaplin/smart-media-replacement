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