# Release Test Plan

Focused release-readiness checklist covering the recent bug fixes and the surface area they touched. Designed to be worked through in ~60-90 minutes against a staging or local environment that mirrors the host where the original 500 was reported.

If a step fails, **stop and investigate** — every item here corresponds to a specific bug that was fixed or a regression risk introduced by those fixes.

---

## 0. Pre-flight

- [ ] On the branch that contains the fixes (`docs` at time of writing) — `git log --oneline -5` shows the recent changes
- [ ] `composer install` has run cleanly (autoload includes `Helpers` class)
- [ ] `npm run build` has rebuilt `build/smart-media-replacement.js` if any JS changed since last build
- [ ] Plugin version bumped in both `smart-media-replacement.php` header and `SMART_MEDIA_REPLACEMENT_VERSION` constant (currently 1.1.0 — bump per your release policy)
- [ ] `CHANGELOG.md` updated with the fixes
- [ ] `WP_DEBUG` and `WP_DEBUG_LOG` enabled in `wp-config.php` so any silent failures surface

```php
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_LOG', true );
define( 'WP_DEBUG_DISPLAY', false );
```

---

## 1. The original 500 must be gone

This is the bug that started the release work. Run this **on the exact server that was failing** (or an environment with the same host setup — typically managed hosts where the PHP user does not own `wp-content/uploads`).

### 1.1 Replace an image — happy path

- [ ] Upload `before.png` (1200×800) to Media Library
- [ ] On the attachment edit screen, click **Replace File**
- [ ] Select `before.png` (same name, same dimensions, different content) and confirm in modal
- [ ] Page reloads, success message appears
- [ ] Open the attachment — file is the new content
- [ ] `tail -50 wp-content/debug.log` shows **no** `ftp_rename` / `ftp_get` / `ftp_put` errors
- [ ] `tail -50 wp-content/debug.log` shows **no** `WP_Filesystem` fatals

### 1.2 Replace a scaled image

WordPress auto-creates `-scaled` variants for images larger than 2560px on a side. The handler treats these specially.

- [ ] Upload a 3000×2000 image as `large.jpg` (WP will create `large-scaled.jpg`)
- [ ] On the attachment edit screen, attempt to replace it
- [ ] Confirm the error message instructs you to upload as `large.jpg` (not `large-scaled.jpg`)
- [ ] Upload another 3000×2000 image named `large.jpg`
- [ ] Reload — attachment shows new content, scaled variant regenerated
- [ ] Verify on disk: `wp-content/uploads/YYYY/MM/large.jpg` exists, old size files for the previous image are gone

### 1.3 Replace a PDF (non-image)

- [ ] Upload `doc.pdf` to Media Library
- [ ] Click **Replace File**, upload another `doc.pdf` (different content)
- [ ] Success; PDF link serves new content

---

## 2. Validation failures don't create junk revisions

This covers the `before_replace` timing fix. Each failed validation must NOT leave a revision behind.

### 2.1 Wrong filename

- [ ] Replace `foo.jpg` with `bar.jpg` (wrong name, same MIME, same dimensions)
- [ ] Error shown — "must have the same name as the original file"
- [ ] In Settings → Media → Replacement Settings → Storage, **Total Revisions** count is unchanged from before the attempt
- [ ] In the DB: `SELECT COUNT(*) FROM wp_smr_revisions WHERE attachment_id = <id>` is unchanged

### 2.2 Wrong MIME

- [ ] Replace `foo.jpg` with a renamed-extension `foo.jpg` that is actually a PNG
- [ ] Error shown — "must use the exact same file type"
- [ ] Revision count unchanged

### 2.3 Wrong dimensions

- [ ] Replace a 1200×800 image with a 1200×900 image of the same name + MIME
- [ ] Error shown — "must have the exact same dimensions"
- [ ] Revision count unchanged

### 2.4 Successful replacement after a failed attempt

- [ ] Trigger the dimension error in 2.3
- [ ] Immediately retry with a correctly-dimensioned file
- [ ] Success
- [ ] Revision count increased by **exactly 1** (not 2)

---

## 3. Data is preserved on failure

Covers the move-before-delete reorder. Simulating a real move failure is hard; the closest practical check is that the file exists immediately after a successful replacement (no race window) and that retry-after-failure scenarios behave.

### 3.1 Files survive a successful replacement

- [ ] Replace an image successfully
- [ ] Immediately reload the Media Library — image displays without broken-image icon
- [ ] On disk in `wp-content/uploads/YYYY/MM/`: main file exists, all size variants regenerated
- [ ] No leftover files from the previous image (check for stale `-150x150.jpg`, etc.)

### 3.2 Retry after server error

If you can reproduce a server error (e.g., temporarily revoke write permissions on the uploads dir):

- [ ] Attempt a replacement — fails with error
- [ ] Original file is **still present** on disk (this is the key assertion — before the reorder, the old file would have been deleted before the move attempt)
- [ ] Restore permissions, retry — succeeds normally

---

## 4. Revision creation, listing, and download

### 4.1 Create a revision

- [ ] Upload a fresh file
- [ ] Replace it once — comment "first edit", version type "minor"
- [ ] **View Revisions** button now shows `(1)`
- [ ] Click View Revisions — modal shows one entry, version 1.0, comment "first edit"

### 4.2 Major vs minor versioning

- [ ] Replace again with version type **major** — comment "big change"
- [ ] View Revisions shows two entries: 1.0 (minor) and 2.0 (major)
- [ ] Replace again with **minor** — third entry is 2.1

### 4.3 Revision limit enforcement

- [ ] Settings → set **Maximum Revisions** to 3, save
- [ ] Replace a file 5 times in a row
- [ ] View Revisions shows exactly 3 entries (oldest two pruned)
- [ ] On disk: `wp-content/uploads/smr-revisions/<id>/` contains exactly 3 files (plus `index.php`)

### 4.4 File-type filter

- [ ] Settings → **Enable Revisions For** = Images Only, save
- [ ] Replace a PDF — succeeds, but **no revision created** (View Revisions button absent or shows 0)
- [ ] Replace an image — revision created as expected
- [ ] Switch setting to **Documents Only** — opposite behavior

### 4.5 Required comment

- [ ] Settings → **Require Comment** ON, save
- [ ] Open replace modal, fill file but leave comment blank — Upload button stays disabled
- [ ] Add comment — button enables, replacement proceeds

### 4.6 Download single revision

- [ ] In View Revisions modal, click **Download** on an entry
- [ ] Browser downloads the historical file
- [ ] Open it — content matches what was uploaded at that version

### 4.7 Download all as ZIP

- [ ] Click **Download All** in the View Revisions modal
- [ ] ZIP downloads, opens cleanly
- [ ] Contains one file per revision, named with the version prefix
- [ ] **After download completes**: `ls /tmp/smr-revisions-*` (or your `get_temp_dir()` location) is empty — the temp ZIP was cleaned up
- [ ] `ls wp-content/uploads/smr-revisions/` shows **no** `revisions-*.zip` file (this is the regression check — previous version leaked ZIPs here)

---

## 5. Restore — the path that was broken

This is the second instance of the 500 bug. **Test on the same affected host as section 1.**

### 5.1 Restore an earlier revision

- [ ] Pick an attachment with 2+ revisions
- [ ] Open View Revisions, click **Restore** on the oldest entry
- [ ] Confirm the JS confirm dialog
- [ ] Page reloads, success alert
- [ ] Open the attachment — content matches the restored version
- [ ] View Revisions now has **one additional entry** (a snapshot of the file as it was before restore, with comment "Restored from version X")
- [ ] `tail -50 wp-content/debug.log` shows **no** `ftp_*` or `WP_Filesystem` errors

### 5.2 Restore for a scaled image

- [ ] Find a scaled image (`-scaled.jpg` in uploads) with at least one revision
- [ ] Restore the previous revision
- [ ] Attachment displays correctly, scaled variant regenerated if applicable
- [ ] Sizes regenerated on disk

### 5.3 Restore when revision file is missing

Simulate the file being manually deleted (covers the early-return safety):

- [ ] In the revisions directory, manually delete one revision's stored file
- [ ] Try to restore that revision via the UI
- [ ] User gets a clear error message ("Revision file not found...") — **NOT** a 500 or a blank page
- [ ] Current attachment file is unchanged

---

## 6. Settings page

### 6.1 Default values

- [ ] Fresh activation → Settings page shows Max Revisions 10, Retention 0, Default Version "Minor", Require Comment unchecked, Delete on Deactivation unchecked
- [ ] Storage Info section shows correct **Database Status** (table exists), revision count, storage size

### 6.2 Save and reload

- [ ] Change every setting, save
- [ ] Reload the page — values persisted

### 6.3 Disable revisions globally

- [ ] Uncheck **Enable Revisions**, save
- [ ] Dependent fields visually dim (opacity 0.5)
- [ ] Replace a file — succeeds, but no revision created
- [ ] View Revisions button absent from the UI

---

## 7. Deactivation / reactivation

### 7.1 Deactivate with both cleanup flags OFF (default)

- [ ] Have at least one revision in the DB
- [ ] Deactivate plugin
- [ ] `wp_smr_revisions` table **still exists** in DB
- [ ] `wp-content/uploads/smr-revisions/` files **still exist** on disk
- [ ] Reactivate — revisions are still visible

### 7.2 Deactivate with "delete files" ON

- [ ] Settings → check **Delete Files on Deactivation**, save
- [ ] Deactivate
- [ ] `wp-content/uploads/smr-revisions/` is **gone** (no `ftp_*` error in log — covers the rmdir fix)
- [ ] DB table still exists

### 7.3 Deactivate with "delete data" ON

- [ ] Settings → check **Delete Database on Deactivation**, save
- [ ] Deactivate
- [ ] `wp_smr_revisions` table is dropped (`SHOW TABLES LIKE '%smr_revisions%'` returns nothing)

---

## 8. Security spot-checks

### 8.1 Nonce required

- [ ] Open browser DevTools, modify the AJAX call to use a wrong/expired nonce
- [ ] Server returns "Security check failed" — not a 500, not data loss

### 8.2 Capability required

- [ ] Log in as a Subscriber/Author who doesn't have `edit_post` for the target attachment
- [ ] Replace button absent OR endpoint rejects with "permission denied"

### 8.3 Path traversal

- [ ] Manually craft a request to `admin-ajax.php?action=smr_download_revision&revision_id=<X>&nonce=<valid>` where the DB row's `file_path` contains `..` segments (insert via SQL if needed for testing)
- [ ] Server responds with "File not found" — does NOT serve `wp-config.php` or any file outside uploads

### 8.4 Directory listing protected

- [ ] Visit `https://yoursite/wp-content/uploads/smr-revisions/` directly in a browser
- [ ] You see either an empty `index.php` response or a 403 — **not** a directory listing of attachment IDs

### 8.5 ZIP file not publicly accessible

- [ ] Trigger a Download All
- [ ] Note the filename in the Content-Disposition header
- [ ] Try to GET `https://yoursite/wp-content/uploads/smr-revisions/<that-name>` directly
- [ ] 404 — the ZIP was never in the public uploads dir

---

## 9. Multisite (if applicable)

Skip this section if you're not running multisite.

### 9.1 Per-blog isolation

- [ ] On site A, create some revisions for attachment 1
- [ ] On site B, create revisions for a different attachment
- [ ] In each site's View Revisions, you only see that site's data
- [ ] DB: `SELECT blog_id, COUNT(*) FROM wp_smr_revisions GROUP BY blog_id` shows per-site rows

### 9.2 New site auto-setup

- [ ] Network-activate the plugin
- [ ] Create a new site via Network Admin → Sites → Add New
- [ ] Visit that site's Media → Replacement Settings — defaults populated correctly

### 9.3 Site deletion cleanup

- [ ] Delete a site that had revisions
- [ ] DB: that blog_id's rows are gone from `wp_smr_revisions`
- [ ] Disk: that blog's `uploads/sites/<id>/smr-revisions/` is gone

---

## 10. PHP / lint sanity

- [ ] `php -l Functions/*.php` — no syntax errors
- [ ] `./vendor/bin/phpcs` over `Functions/` — no errors or warnings
- [ ] `composer dump-autoload -o` runs cleanly
- [ ] No new `ftp_*` references anywhere outside `vendor/`: `grep -r "ftp_" Functions/ smart-media-replacement.php` should return nothing
- [ ] No new `WP_Filesystem` references in the replacement/restore paths: `grep -rn "WP_Filesystem" Functions/` returns only items you intentionally kept (if any)

---

## Done?

If every box above is ticked and nothing in `wp-content/debug.log` raised a fresh `[error]` or `[warning]` line during the run, this is ready to merge and tag.

If you skipped multisite (§9) or the destructive deactivation tests (§7.2, §7.3), note that in the release notes so future you knows what was actually verified.
