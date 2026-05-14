# Validation Rules

Smart Media Replacement enforces strict validation to ensure file replacements don't break your site.

## Overview

When you attempt to replace a file, the following checks run in order:

1. Security verification (nonce)
2. User permissions
3. Attachment validity
4. File upload integrity
5. Filename matching
6. MIME type matching
7. Image dimension matching

If any check fails, the replacement is rejected with a specific error message.

---

## Security Verification

### Nonce Check

**What:** Verifies the request originated from WordPress admin.

**Why:** Prevents Cross-Site Request Forgery (CSRF) attacks.

**Error:** "Security check failed. Please refresh the page and try again."

**Solution:** Refresh the page to get a new nonce and try again.

---

## User Permissions

### Capability Check

**What:** Verifies the user can edit the specific attachment.

**Why:** Prevents unauthorized file modifications.

**Required Capability:** `edit_post` for the attachment ID.

**Error:** "You do not have permission to replace this file."

**Solution:** Contact an administrator for appropriate permissions.

### Who Can Replace Files

| Role | Can Replace Own | Can Replace Others |
|------|-----------------|-------------------|
| Administrator | Yes | Yes |
| Editor | Yes | Yes |
| Author | Yes | No |
| Contributor | No | No |
| Subscriber | No | No |

---

## Attachment Validity

### Existence Check

**What:** Confirms the attachment exists in the database.

**Why:** Prevents operations on deleted or non-existent files.

**Error:** "Invalid attachment ID."

**Solution:** Verify the attachment hasn't been deleted.

---

## File Upload Integrity

### Upload Verification

**What:** Confirms a file was actually uploaded via HTTP POST.

**Why:** Security measure to prevent path manipulation attacks.

**Checks:**
- `$_FILES['file']` exists
- `is_uploaded_file()` returns true
- No upload errors occurred

**Error:** "Error uploading file. Please try again."

**Solution:** Try uploading again. Check file size limits.

---

## Filename Matching

### Exact Match Required

**What:** The replacement file must have the exact same filename.

**Why:** Ensures URLs remain identical after replacement.

**Comparison:** Case-sensitive, includes extension.

**Examples:**

| Original | Replacement | Result |
|----------|-------------|--------|
| `report.pdf` | `report.pdf` | Valid |
| `Report.pdf` | `report.pdf` | Invalid (case) |
| `report.pdf` | `report-v2.pdf` | Invalid |
| `image.jpg` | `image.jpeg` | Invalid (extension) |

**Error:** "Filename must match. Expected: [original], got: [uploaded]"

**Solution:** Rename your file to match the original exactly.

### Special Case: Scaled Images

WordPress adds `-scaled` to large images. The plugin handles this automatically:

| Stored As | You Should Upload |
|-----------|-------------------|
| `photo-scaled.jpg` | `photo.jpg` |
| `banner-scaled.png` | `banner.png` |

The plugin detects scaled images and expects the original filename.

---

## MIME Type Matching

### Type Must Match

**What:** The file type must be identical to the original.

**Why:** Prevents format mismatches that could break rendering.

**Examples:**

| Original MIME | Valid Replacement | Invalid |
|---------------|-------------------|---------|
| `image/jpeg` | `image/jpeg` | `image/png` |
| `application/pdf` | `application/pdf` | `application/msword` |
| `image/png` | `image/png` | `image/jpeg` |

**Error:** "File type must match. Expected: [original], got: [uploaded]"

**Solution:** Convert your file to the correct format before uploading.

### Common MIME Types

| Extension | MIME Type |
|-----------|-----------|
| `.jpg`, `.jpeg` | `image/jpeg` |
| `.png` | `image/png` |
| `.gif` | `image/gif` |
| `.webp` | `image/webp` |
| `.pdf` | `application/pdf` |
| `.doc` | `application/msword` |
| `.docx` | `application/vnd.openxmlformats-officedocument.wordprocessingml.document` |

---

## Image Dimension Matching

### Dimensions Must Match

**What:** Image width and height must match the original.

**Why:** Prevents layout issues from differently-sized images.

**Applies To:** Images only (not PDFs, documents, etc.)

**Examples:**

| Original | Replacement | Result |
|----------|-------------|--------|
| 1920×1080 | 1920×1080 | Valid |
| 1920×1080 | 1920×1200 | Invalid |
| 800×600 | 1600×1200 | Invalid |
| 800×600 | 600×800 | Invalid (swapped) |

**Error:** "Image dimensions must match. Expected: [W]x[H], got: [W]x[H]"

**Solution:** Resize your image to match the original dimensions.

### Scaled Image Dimensions

For scaled images, match the **original** dimensions, not the scaled version:

| WordPress Shows | Original Was | You Need |
|-----------------|--------------|----------|
| 2560×1440 (scaled) | 4000×2250 | 4000×2250 |
| 2560×1920 (scaled) | 3840×2880 | 3840×2880 |

The error message will tell you the correct dimensions to use.

### Disabling Dimension Check

Developers can disable this check:

```php
// Disable for all images
add_filter('smart_media_replacement_enforce_dimensions', '__return_false');

// Disable for specific attachments
add_filter('smart_media_replacement_enforce_dimensions', function($enforce, $id) {
    if ($id === 123) return false;
    return $enforce;
}, 10, 2);
```

---

## Validation Flow Diagram

```
Request Received
       │
       ▼
┌─────────────────┐
│ Verify Nonce    │──── Fail ──→ "Security check failed"
└────────┬────────┘
         │ Pass
         ▼
┌─────────────────┐
│ Check Permission│──── Fail ──→ "No permission"
└────────┬────────┘
         │ Pass
         ▼
┌─────────────────┐
│ Validate Attach │──── Fail ──→ "Invalid attachment"
└────────┬────────┘
         │ Pass
         ▼
┌─────────────────┐
│ Check Upload    │──── Fail ──→ "Upload error"
└────────┬────────┘
         │ Pass
         ▼
┌─────────────────┐
│ Match Filename  │──── Fail ──→ "Filename must match"
└────────┬────────┘
         │ Pass
         ▼
┌─────────────────┐
│ Match MIME Type │──── Fail ──→ "Type must match"
└────────┬────────┘
         │ Pass
         ▼
┌─────────────────┐
│ Match Dimensions│──── Fail ──→ "Dimensions must match"
│ (images only)   │
└────────┬────────┘
         │ Pass
         ▼
    ┌─────────┐
    │ Replace │
    │  File   │
    └─────────┘
```

---

## Error Message Reference

| Error | Cause | Solution |
|-------|-------|----------|
| Security check failed | Expired session | Refresh page |
| You do not have permission | Wrong user role | Get admin help |
| Invalid attachment ID | Deleted attachment | Check media library |
| Error uploading file | Upload failed | Try again |
| Filename must match: Expected X, got Y | Wrong filename | Rename file |
| File type must match: Expected X, got Y | Wrong format | Convert file |
| Image dimensions must match: Expected WxH | Wrong size | Resize image |
