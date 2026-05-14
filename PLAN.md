# Smart Media Replacement - Revision System Plan

## Overview

Add a file revision system to Smart Media Replacement that stores previous versions of files, tracks version history with comments, and provides a user-friendly interface for managing and downloading revisions.

---

## Core Features

### 1. Version Numbering

- **Major versions**: 1.0 → 2.0 (significant changes)
- **Minor versions**: 1.0 → 1.1 (tweaks, corrections)
- User selects version type before upload
- Default version increment configurable in settings

### 2. Revision Comments

- Text field for users to describe changes
- Optional: require comments before upload (setting)
- Comments displayed in revision history

### 3. Revision Storage

Store old files before replacement:

**Single Site:**
```
wp-content/uploads/smr-revisions/
  └── {attachment_id}/
      ├── 1.0-report.pdf
      ├── 1.1-report.pdf
      └── 2.0-report.pdf
```

**Multisite:**
```
wp-content/uploads/sites/{blog_id}/smr-revisions/
  └── {attachment_id}/
      ├── 1.0-report.pdf
      ├── 1.1-report.pdf
      └── 2.0-report.pdf
```

### 4. Revision History UI

- Display all revisions for an attachment
- Show: version, date, user, comment, file size
- Download button for each revision
- Restore button to roll back
- Side-by-side image comparison (for images)
- Bulk download as ZIP

### 5. Restore Functionality

- Restore any previous version as current
- Creates a new revision entry (audit trail preserved)
- Triggers standard replacement workflow

### 6. Storage Management

- Configurable max revisions per file
- Auto-delete oldest when limit exceeded
- Retention policy: auto-delete revisions older than X days
- Display storage used per file and globally

### 7. Data Cleanup

- Option to delete revision files on deactivation
- Option to delete database table on deactivation
- Both options disabled by default (safe default)

---

## Database Schema

### Custom Table: `{prefix}smr_revisions`

| Column | Type | Description |
|--------|------|-------------|
| `id` | BIGINT(20) | Primary key, auto-increment |
| `blog_id` | BIGINT(20) | Site ID for multisite support |
| `attachment_id` | BIGINT(20) | Foreign key to `wp_posts.ID` |
| `version` | VARCHAR(20) | Version string (e.g., "1.0", "2.1") |
| `version_major` | INT(11) | Major version number |
| `version_minor` | INT(11) | Minor version number |
| `file_path` | VARCHAR(255) | Relative path to revision file |
| `file_size` | BIGINT(20) | File size in bytes |
| `mime_type` | VARCHAR(100) | MIME type of the file |
| `comment` | TEXT | User-provided revision comment |
| `user_id` | BIGINT(20) | User who created the revision |
| `created_at` | DATETIME | Timestamp of revision creation |

**Indexes:**
- `blog_id, attachment_id` (composite index for multisite queries)
- `blog_id, created_at` (for retention policy cleanup per site)

---

## Settings Page

### Location

**Settings → Media → Smart Media Replacement** (add section to existing Media settings)

Or: **Media → Replacement Settings** (dedicated submenu)

### Options

| Setting | Type | Default | Description |
|---------|------|---------|-------------|
| `smr_max_revisions` | number | 10 | Maximum revisions per attachment (0 = unlimited) |
| `smr_retention_days` | number | 0 | Auto-delete revisions older than X days (0 = disabled) |
| `smr_default_version_type` | select | minor | Default version increment (major/minor) |
| `smr_require_comment` | checkbox | false | Require comment before upload |
| `smr_delete_files_on_deactivate` | checkbox | false | Delete revision files on plugin deactivation |
| `smr_delete_data_on_deactivate` | checkbox | false | Delete database table on plugin deactivation |

---

## Architecture

### New Classes

```
Functions/
├── ManageMedia.php          # Existing (modify to integrate revisions)
├── RevisionManager.php      # Core revision logic
├── RevisionStorage.php      # File storage operations
├── RevisionDatabase.php     # Database operations
├── RevisionUI.php           # Admin UI components
└── SettingsPage.php         # Plugin settings
```

### Class Responsibilities

#### `RevisionManager.php`
- Orchestrates revision creation workflow
- Handles version number calculation
- Coordinates between storage and database
- Manages restore operations
- Enforces revision limits and retention

#### `RevisionStorage.php`
- Creates revision directory structure
- Copies files to revision storage
- Deletes revision files
- Calculates storage usage
- Generates ZIP downloads

#### `RevisionDatabase.php`
- Creates/updates custom table
- CRUD operations for revisions
- Queries for revision history
- Cleanup queries for limits/retention

#### `RevisionUI.php`
- Renders revision history panel
- Renders version/comment form fields
- Renders image comparison view
- Renders settings page fields
- Enqueues admin styles/scripts

#### `SettingsPage.php`
- Registers settings
- Renders settings section
- Sanitizes/validates options

---

## UI/UX Design

### Replace File Modal (Enhanced)

```
┌─────────────────────────────────────────────────┐
│  Replace File                                   │
├─────────────────────────────────────────────────┤
│                                                 │
│  Version Type:                                  │
│  ○ Minor (1.0 → 1.1)                           │
│  ● Major (1.0 → 2.0)                           │
│                                                 │
│  Comment:                                       │
│  ┌─────────────────────────────────────────┐   │
│  │ Updated pricing for Q2 2025             │   │
│  └─────────────────────────────────────────┘   │
│                                                 │
│  [Choose File]  report.pdf                      │
│                                                 │
│  ┌─────────────────────────────────────────┐   │
│  │           Upload & Replace              │   │
│  └─────────────────────────────────────────┘   │
│                                                 │
└─────────────────────────────────────────────────┘
```

### Revision History Panel

```
┌─────────────────────────────────────────────────────────────────────┐
│  Revision History                                    [Download All] │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  Current Version: 2.1                                               │
│  Total Storage: 4.2 MB (5 revisions)                               │
│                                                                     │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │ v2.1 (current)           Jan 22, 2025    John Smith            ││
│  │ "Final version with legal review"                    1.2 MB    ││
│  │                                                                ││
│  │                                          [Download]            ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                     │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │ v2.0                     Jan 20, 2025    Jane Doe              ││
│  │ "Major redesign of infographic"                      1.1 MB    ││
│  │                                                                ││
│  │                                          [Download] [Restore]  ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                     │
│  ┌────────────────────────────────────────────────────────────────┐│
│  │ v1.2                     Jan 15, 2025    John Smith            ││
│  │ "Fixed typo in footer"                               1.0 MB    ││
│  │                                                                ││
│  │                                          [Download] [Restore]  ││
│  └────────────────────────────────────────────────────────────────┘│
│                                                                     │
│  ... (2 more revisions)                                            │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

### Image Comparison View

```
┌─────────────────────────────────────────────────────────────────────┐
│  Compare Versions                                                   │
├─────────────────────────────────────────────────────────────────────┤
│                                                                     │
│  ┌─────────────────────────┐   ┌─────────────────────────┐         │
│  │                         │   │                         │         │
│  │      [Image v1.2]       │   │     [Image v2.1]        │         │
│  │                         │   │                         │         │
│  └─────────────────────────┘   └─────────────────────────┘         │
│  Version 1.2                    Version 2.1 (current)               │
│  Jan 15, 2025                   Jan 22, 2025                        │
│                                                                     │
│  Compare: [v1.0 ▼]  with  [v2.1 ▼]                                 │
│                                                                     │
└─────────────────────────────────────────────────────────────────────┘
```

---

## Hooks & Extensibility

### New Filters

```php
// Modify max revisions for specific attachments
apply_filters('smr_max_revisions', int $max, int $attachment_id)

// Modify retention days
apply_filters('smr_retention_days', int $days, int $attachment_id)

// Skip revision creation for specific attachments
apply_filters('smr_create_revision', bool $create, int $attachment_id)

// Modify revision directory path
apply_filters('smr_revision_directory', string $path, int $attachment_id)
```

### New Actions

```php
// Fired after revision is created
do_action('smr_revision_created', int $revision_id, int $attachment_id, array $revision_data)

// Fired after revision is deleted
do_action('smr_revision_deleted', int $revision_id, int $attachment_id)

// Fired after restore operation
do_action('smr_revision_restored', int $revision_id, int $attachment_id)

// Fired during cleanup (limits/retention)
do_action('smr_revisions_cleaned', int $attachment_id, array $deleted_ids)
```

---

## Implementation Phases

### Phase 1: Foundation

- [ ] Create database table and migration system
- [ ] Implement `RevisionDatabase.php` class
- [ ] Implement `RevisionStorage.php` class
- [ ] Add activation/deactivation hooks for table management

### Phase 2: Core Functionality

- [ ] Implement `RevisionManager.php` class
- [ ] Modify `ManageMedia.php` to create revisions before replacement
- [ ] Add version number calculation logic
- [ ] Implement revision limit enforcement
- [ ] Implement retention policy cleanup (cron job)

### Phase 3: Settings

- [ ] Implement `SettingsPage.php` class
- [ ] Register all settings options
- [ ] Add settings UI to admin
- [ ] Handle deactivation cleanup based on settings

### Phase 4: Admin UI

- [ ] Implement `RevisionUI.php` class
- [ ] Add version type selector to replace modal
- [ ] Add comment field to replace modal
- [ ] Create revision history panel
- [ ] Add download functionality

### Phase 5: Advanced Features

- [ ] Implement restore functionality
- [ ] Add image comparison view
- [ ] Add bulk ZIP download
- [ ] Add storage usage display
- [ ] Add user tracking to revision entries

### Phase 6: Polish

- [ ] Add JavaScript for dynamic UI interactions
- [ ] Add loading states and error handling
- [ ] Add success/error notifications
- [ ] Update documentation

---

## File Changes Summary

### New Files

```
Functions/
├── RevisionManager.php
├── RevisionStorage.php
├── RevisionDatabase.php
├── RevisionUI.php
└── SettingsPage.php

src/
├── revision-history.js       # Revision panel interactions
└── revision-history.css      # Revision panel styles
```

### Modified Files

```
smart-media-replacement.php   # Add new class instantiation, activation hooks
Functions/ManageMedia.php     # Integrate revision creation into replacement flow
src/smart-media-replacement.js # Add version/comment fields to replace modal
```

---

## Security Considerations

- Verify `edit_post` capability for all revision operations
- Nonce verification on all AJAX endpoints
- Sanitize file paths to prevent directory traversal
- Validate attachment ownership before downloads
- Use WordPress Filesystem API for all file operations
- Serve downloads through PHP (not direct file URLs) to enforce permissions

---

## Performance Considerations

- Index database columns used in queries
- Lazy-load revision history (AJAX) to avoid slowing attachment pages
- Use pagination for attachments with many revisions
- Run cleanup via WP-Cron, not on every request
- Cache storage calculations

---

## Testing Checklist

- [ ] Create revision on file replacement
- [ ] Version increment (major and minor)
- [ ] Comment saved correctly
- [ ] User tracked correctly
- [ ] Revision limit enforcement
- [ ] Retention policy cleanup
- [ ] Download single revision
- [ ] Download all as ZIP
- [ ] Restore previous version
- [ ] Image comparison view
- [ ] Settings save/load correctly
- [ ] Deactivation cleanup (files)
- [ ] Deactivation cleanup (database)
- [ ] Permission checks on all endpoints
- [ ] Works with scaled images
- [ ] Works with non-image files (PDF, etc.)
- [ ] Multisite: Table created on network activation
- [ ] Multisite: Revisions isolated between sites
- [ ] Multisite: Storage paths correct per site
- [ ] Multisite: Settings independent per site
- [ ] Multisite: New site gets default settings
- [ ] Multisite: Site deletion cleans up revisions

---

## Decisions

1. **Revision of revisions**: When restoring, should the restored file become editable, or is it a direct copy?
   - **Decision**: Creates a new revision entry, fully editable going forward

2. **Storage location**: Should revision storage location be configurable?
   - **Decision**: Default location with filter for customization

3. **Multisite**: Any special considerations for multisite installs?
   - **Decision**: Full multisite support included from the start (see below)

---

---

## Multisite Support

### Overview

Full multisite compatibility is built-in from the start. Each site in a network operates independently with its own revisions, storage, and settings.

### Database Strategy

**Single table with `blog_id` column** (network-wide table):
- Table created on network activation: `{base_prefix}smr_revisions`
- All queries filtered by `blog_id` using `get_current_blog_id()`
- Enables network-wide admin queries if needed in the future

### Storage Strategy

Revisions stored within each site's upload directory:
```
wp-content/uploads/sites/{blog_id}/smr-revisions/{attachment_id}/
```

This aligns with WordPress's native multisite upload structure and ensures:
- Proper file isolation between sites
- Correct permissions per site
- Easy backup/migration of individual sites

### Settings Strategy

Settings are **per-site** using standard WordPress options API:
- Each site can configure its own revision limits
- Each site can configure its own retention policy
- Network admins can set defaults via filters

### Activation Hooks

| Hook | Single Site | Multisite |
|------|-------------|-----------|
| `register_activation_hook` | Create table, set defaults | Create table (network-wide) |
| `wp_insert_site` | N/A | Initialize defaults for new site |
| `wp_delete_site` | N/A | Clean up site's revisions |
| `register_deactivation_hook` | Cleanup (if enabled) | Cleanup per-site or network |

### Key Implementation Details

```php
// Always include blog_id in queries
$blog_id = get_current_blog_id();

// Insert revision
$wpdb->insert($table, [
    'blog_id' => $blog_id,
    'attachment_id' => $attachment_id,
    // ... other fields
]);

// Query revisions
$wpdb->get_results($wpdb->prepare(
    "SELECT * FROM $table WHERE blog_id = %d AND attachment_id = %d",
    $blog_id,
    $attachment_id
));
```

### Storage Path Helper

```php
function get_revision_base_path(): string {
    $upload_dir = wp_upload_dir();
    return trailingslashit($upload_dir['basedir']) . 'smr-revisions';
}
// Returns: wp-content/uploads/smr-revisions (single site)
// Returns: wp-content/uploads/sites/2/smr-revisions (multisite, site 2)
```

### Testing Checklist (Multisite-Specific)

- [ ] Table created on network activation
- [ ] Revisions isolated between sites
- [ ] Storage paths correct per site
- [ ] Settings independent per site
- [ ] New site gets default settings
- [ ] Site deletion cleans up revisions
- [ ] Plugin deactivation respects per-site cleanup settings
- [ ] Super admin can view revisions on any site

---

## Timeline Estimate

This plan does not include time estimates. Implementation order follows the phases above. Each phase should be completed and tested before moving to the next.
