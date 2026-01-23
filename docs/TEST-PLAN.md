# Smart Media Replacement - Test Plan

## Overview

This document outlines the testing procedures for the Smart Media Replacement plugin, including the new revision system. Follow these tests in order to verify the plugin is functioning correctly.

---

## Prerequisites

1. WordPress 6.6+ installed
2. PHP 7.0+
3. Plugin activated
4. Sample media files ready:
   - Image file (e.g., `test-image.jpg`, 1920x1080)
   - PDF file (e.g., `test-document.pdf`)
   - Different sized image for error testing

---

## Phase 1: Database & Activation Tests

### Test 1.1: Plugin Activation

**Steps:**
1. Deactivate the plugin if active
2. Activate the plugin
3. Check the debug.log for `[SMR]` entries

**Expected Results:**
- Log shows: `[SMR] Plugin activation started`
- Log shows: `[SMR] Revisions table created successfully`
- Log shows: `[SMR] Plugin activation completed`

**Verification Query:**
```sql
SHOW TABLES LIKE '%smr_revisions%';
```

### Test 1.2: Database Table Structure

**Steps:**
1. Use a database tool (phpMyAdmin, WP CLI, etc.)
2. Describe the `wp_smr_revisions` table

**Expected Columns:**
- `id` - BIGINT, auto-increment
- `blog_id` - BIGINT
- `attachment_id` - BIGINT
- `version` - VARCHAR(20)
- `version_major` - INT
- `version_minor` - INT
- `file_path` - VARCHAR(255)
- `file_size` - BIGINT
- `mime_type` - VARCHAR(100)
- `comment` - TEXT
- `user_id` - BIGINT
- `created_at` - DATETIME

### Test 1.3: Default Settings

**Steps:**
1. Go to Media → Replacement Settings
2. Verify default values

**Expected Defaults:**
- Maximum Revisions: 10
- Retention Period: 0 (disabled)
- Default Version Type: Minor
- Require Comment: Unchecked
- Delete Files on Deactivation: Unchecked
- Delete Database on Deactivation: Unchecked

---

## Phase 2: Settings Page Tests

### Test 2.1: Settings Display

**Steps:**
1. Navigate to Media → Replacement Settings
2. Verify all sections are visible

**Expected Results:**
- "Revision Settings" section visible
- "Cleanup Settings" section visible
- "Storage Information" section visible
- Database Status shows "✓ Table exists"

### Test 2.2: Settings Save

**Steps:**
1. Change Maximum Revisions to 5
2. Change Retention Period to 30
3. Change Default Version Type to Major
4. Check "Require Comment"
5. Click Save Settings
6. Refresh the page

**Expected Results:**
- All settings persist after save
- Success message appears

### Test 2.3: Settings Reset

**Steps:**
1. Change settings back to defaults
2. Save

---

## Phase 3: File Replacement Tests

### Test 3.1: Basic Replacement (No Revisions Yet)

**Steps:**
1. Upload a new image to the Media Library
2. Click on the image to view details
3. Click "Replace File" button
4. Verify modal appears with version/comment options

**Expected Results:**
- Modal displays with:
  - Version Type radio buttons (Minor/Major)
  - Comment textarea
  - File input
  - Cancel and Upload buttons

### Test 3.2: First Replacement - Minor Version

**Steps:**
1. Select "Minor" version type
2. Enter comment: "First replacement test"
3. Select a replacement file (same name, same dimensions)
4. Click "Upload & Replace"

**Expected Results:**
- Check debug.log for:
  - `[SMR] Replacement request: attachment=X, version_type=minor`
  - `[SMR] Stored revision file: ... (X KB)`
  - `[SMR] Revision inserted: ID=1, Attachment=X, Version=1.0`
- Page refreshes with new file
- Revision History metabox shows 1 revision

### Test 3.3: Second Replacement - Major Version

**Steps:**
1. Open the same attachment
2. Click "Replace File"
3. Select "Major" version type
4. Enter comment: "Major update test"
5. Upload replacement file

**Expected Results:**
- Log shows: `Version=2.0`
- Revision History shows 2 revisions (1.0 and 1.1)
- Current Version displays as 2.1

### Test 3.4: Comment Required Setting

**Steps:**
1. Enable "Require Comment" in settings
2. Try to replace a file without entering a comment

**Expected Results:**
- Upload button should be disabled until comment is entered
- Error message if trying to submit without comment

### Test 3.5: Validation Errors

**Steps:**
1. Try to replace with wrong filename
2. Try to replace with wrong file type
3. Try to replace image with wrong dimensions

**Expected Results:**
- Each should show appropriate error message
- No revision should be created for failed attempts

---

## Phase 4: Revision History Tests

### Test 4.1: History Display

**Steps:**
1. Open an attachment with revisions
2. Scroll to Revision History metabox

**Expected Results:**
- Current Version shown at top
- Total Storage displayed
- Each revision shows:
  - Version number
  - Date/time
  - User name
  - File size
  - Comment (if provided)
- Latest revision marked with badge

### Test 4.2: Download Single Revision

**Steps:**
1. Click "Download" on any revision

**Expected Results:**
- File downloads with version prefix in filename
- File is the correct historic version

### Test 4.3: Download All Revisions

**Steps:**
1. Click "Download All" button

**Expected Results:**
- ZIP file downloads
- ZIP contains all revision files
- Each file named with version prefix

### Test 4.4: Image Preview

**Steps:**
1. On an image attachment, click "Preview" on a revision

**Expected Results:**
- Overlay appears with historic image
- Click anywhere to close

### Test 4.5: Image Comparison

**Steps:**
1. On an image attachment with 2+ revisions
2. Click "Compare Versions" button
3. Select different versions in dropdowns

**Expected Results:**
- Modal opens with side-by-side view
- Images update when selections change
- "Current" option available in dropdowns

---

## Phase 5: Restore Tests

### Test 5.1: Restore Previous Version

**Steps:**
1. Note current file details
2. Click "Restore" on an older revision
3. Confirm the action

**Expected Results:**
- Confirmation dialog appears
- After restore:
  - File is replaced with historic version
  - New revision created (audit trail)
  - Log shows: `[SMR] Restored revision X for attachment Y`
- Page refreshes

### Test 5.2: Verify Restore Created Revision

**Steps:**
1. After restore, check Revision History

**Expected Results:**
- New revision entry exists
- Comment shows "Restored from vX.X"
- Version number incremented

---

## Phase 6: Limit & Retention Tests

### Test 6.1: Revision Limit Enforcement

**Steps:**
1. Set Maximum Revisions to 3
2. Create 5 revisions for an attachment

**Expected Results:**
- Only 3 most recent revisions remain
- Older revision files deleted
- Log shows: `[SMR] Cleaned X excess revisions`

### Test 6.2: Retention Policy (Manual Trigger)

**Steps:**
1. Set Retention Period to 1 day
2. Manually set a revision's `created_at` to 2 days ago in database
3. Trigger cron: `wp cron event run smr_cleanup_revisions`

**Expected Results:**
- Old revision deleted
- File removed from storage
- Log shows retention cleanup

---

## Phase 7: Deactivation Tests

### Test 7.1: Deactivation - Keep Data

**Steps:**
1. Ensure both cleanup options are UNCHECKED
2. Deactivate plugin
3. Check database and filesystem

**Expected Results:**
- Table still exists: `wp_smr_revisions`
- Files still exist in: `wp-content/uploads/smr-revisions/`

### Test 7.2: Reactivation After Keep

**Steps:**
1. Reactivate plugin
2. Check an attachment with revisions

**Expected Results:**
- All revisions still visible
- Files still accessible

### Test 7.3: Deactivation - Delete Files Only

**Steps:**
1. Check "Delete Files on Deactivation"
2. Deactivate plugin
3. Check database and filesystem

**Expected Results:**
- Table still exists (with records)
- Files directory removed: `wp-content/uploads/smr-revisions/`

### Test 7.4: Deactivation - Delete Database Only

**Steps:**
1. Reactivate, create new revisions
2. Check "Delete Database on Deactivation"
3. Uncheck "Delete Files on Deactivation"
4. Deactivate plugin

**Expected Results:**
- Table dropped
- Files may still exist (orphaned)

### Test 7.5: Deactivation - Full Cleanup

**Steps:**
1. Reactivate, create revisions
2. Check BOTH cleanup options
3. Deactivate plugin

**Expected Results:**
- Table dropped
- Files directory removed
- Log shows both cleanup actions

---

## Phase 8: Multisite Tests (If Applicable)

### Test 8.1: Site Isolation

**Steps:**
1. On multisite, create revisions on Site A
2. Switch to Site B
3. Check Media Library

**Expected Results:**
- Site B shows no revisions from Site A
- Each site has independent storage

### Test 8.2: New Site Creation

**Steps:**
1. Create new site in network
2. Check if default settings exist

**Expected Results:**
- Default options set for new site

### Test 8.3: Site Deletion

**Steps:**
1. Create revisions on a test site
2. Delete the site from network

**Expected Results:**
- Revisions for that site cleaned up
- Log shows cleanup action

---

## Phase 9: Edge Cases

### Test 9.1: Scaled Images

**Steps:**
1. Upload large image (4000x3000) that WordPress scales
2. Note `-scaled` version created
3. Replace the file

**Expected Results:**
- Original dimensions used for validation
- Revision stored correctly
- Replacement works without error

### Test 9.2: PDF Replacement

**Steps:**
1. Upload a PDF
2. Replace it with updated version

**Expected Results:**
- Revision created
- No dimension validation
- Download works correctly

### Test 9.3: Attachment Deletion

**Steps:**
1. Create revisions for an attachment
2. Delete the attachment from Media Library

**Expected Results:**
- All revisions deleted (files and database)
- Log shows cleanup

### Test 9.4: Empty Comment (When Not Required)

**Steps:**
1. Disable "Require Comment" setting
2. Replace file without entering comment

**Expected Results:**
- Replacement succeeds
- Revision shows empty comment field

---

## Phase 10: Error Handling

### Test 10.1: Missing File on Download

**Steps:**
1. Manually delete a revision file from filesystem
2. Try to download that revision

**Expected Results:**
- Error message: "File not found"
- No PHP errors

### Test 10.2: Permission Denied

**Steps:**
1. As a Contributor user, try to replace an Author's file

**Expected Results:**
- Error message about permissions
- No revision created

### Test 10.3: Large File Upload

**Steps:**
1. Try to replace with file larger than `upload_max_filesize`

**Expected Results:**
- Appropriate error message
- No partial revision created

---

## Console Log Verification

Throughout testing, the browser console should show:

```
[SMR] Replacement modal opened for attachment: X
[SMR] Performing replacement: {attachmentId: X, versionType: "minor", comment: "..."}
[SMR] Revision history initialized
[SMR] Comparison modal opened
[SMR] Comparison images updated: {left: "...", right: "..."}
```

---

## Debug Log Entries to Verify

Key log entries to look for in `wp-content/debug.log`:

```
[SMR] Plugin activation started
[SMR] Revisions table created successfully
[SMR] Plugin activation completed
[SMR] Replacement request: attachment=X, version_type=Y, comment=Z
[SMR] Stored revision file: /path/to/file (X KB)
[SMR] Revision inserted: ID=X, Attachment=Y, Version=Z
[SMR] Cleaned X excess revisions for attachment Y
[SMR] Restored revision X for attachment Y
[SMR] Revision deleted: ID=X
[SMR] Plugin deactivation started
[SMR] Deleting revision files on deactivation
[SMR] Deleting database data on deactivation
[SMR] Plugin deactivation completed
```

---

## Cleanup After Testing

1. Remove test media files
2. Reset settings to defaults
3. Optionally clear revision history
4. Remove test log entries

---

## Test Results Template

| Test ID | Test Name | Status | Notes |
|---------|-----------|--------|-------|
| 1.1 | Plugin Activation | ✅ | |
| 1.2 | Database Table Structure | ✅ | |
| 1.3 | Default Settings | ✅ | |
| 2.1 | Settings Display | ✅ | |
| 2.2 | Settings Save | ✅ | |
| 3.1 | Basic Replacement Modal | ✅ | |
| 3.2 | First Replacement - Minor | ✅ | |
| 3.3 | Second Replacement - Major | ✅ | |
| 3.4 | Comment Required | ✅ | |
| 3.5 | Validation Errors | ✅ | |
| 4.1 | History Display | ⬜ | |
| 4.2 | Download Single | ⬜ | |
| 4.3 | Download All (ZIP) | ⬜ | |
| 4.4 | Image Preview | ⬜ | |
| 4.5 | Image Comparison | ⬜ | |
| 5.1 | Restore Previous Version | ⬜ | |
| 5.2 | Verify Restore Revision | ⬜ | |
| 6.1 | Revision Limit | ⬜ | |
| 6.2 | Retention Policy | ⬜ | |
| 7.1-7.5 | Deactivation Tests | ⬜ | |
| 8.1-8.3 | Multisite Tests | ⬜ | |
| 9.1-9.4 | Edge Cases | ⬜ | |
| 10.1-10.3 | Error Handling | ⬜ | |

Legend: ⬜ Not Tested | ✅ Passed | ❌ Failed
