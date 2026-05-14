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

### Added

- Revision system: snapshots the previous file on every replacement with major/minor versioning, optional replacement notes, and per-file-type opt-in (documents, images, or all).
- Revision history modal with a "Current file" panel showing the live attachment plus chronological history with per-revision download and restore.
- Replace modal with dynamic version labels derived from the attachment's current version (e.g. `Minor (v2.3 → v2.4)`).
- Settings page (Media → Replacement Settings) with options for enabling revisions, file-type filter, max revisions per file, retention period, default version type, require-comment, and deactivation cleanup.
- ZIP download of an attachment's full revision history.
- Multisite support: per-site revision storage and settings; automatic cleanup on site deletion.
- Developer hooks: `smart_media_replacement_before_replace`, `smr_create_revision`, `smr_revision_created`, `smr_revisions_cleaned`, `smr_revision_restored`, `smr_max_revisions`, `smr_retention_days`, `smr_revision_directory`.

### Fixed

- 500 error during replacement on hosts where `WP_Filesystem` fell back to FTP without credentials; replacement, restore, revision storage, and revision cleanup now use native PHP file operations.
- Data loss when a replacement or restore failed mid-flight: old files were deleted before the new file was placed. The new ordering places the new file first, then cleans up the old.
- Restoring a revision triggered two confirm prompts and ran the restore twice (duplicate click handler).
- `before_replace` action fired before dimension validation, leaving junk revisions behind from rejected uploads.
- ZIP archives of revisions were written to the public uploads directory and never deleted; now created in the system temp directory and cleaned up after streaming.
- "A comment is required" error fired for replacements that wouldn't create a revision.
- `getimagesize()` warning when the current file no longer existed on disk (now guarded with `file_exists()`).

### Changed

- Replacement notes now display on the file they describe (the version introduced by the event) rather than on the retired snapshot; the original upload shows no note.
- Replace modal is skipped entirely when no revision will be created, restoring the single-click replace flow for those cases.
- Comment field renamed to "Replacement note" in both the replace modal and the revision history.
- "Latest" badge removed from revision rows; the live file now appears as a separate "Current file" panel.
- Restore success/failure shows an inline modal notice instead of `window.alert`, with a brief delay before reload so the user can read confirmation.
- `Requires at least` and `Tested up to` bumped to WordPress 7.0.
- Internal refactor: shared `Helpers` class consolidates filename parsing, file-type checking, and old-file cleanup that was previously duplicated between `ManageMedia` and `RevisionManager`.

### Security

- `RevisionStorage::get_full_path()` rejects path-traversal attempts in stored revision paths.
- Base `smr-revisions/` directory now contains `index.php` to prevent directory listing.
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