# User Guide

## Replacing Media Files

There are two ways to replace a media file in WordPress with this plugin.

### Method 1: Media Library List View

1. Navigate to **Media → Library**
2. Switch to **List View** (if not already)
3. Hover over the file you want to replace
4. Click the **Replace** link in the row actions
5. A file picker dialog opens
6. Select the replacement file from your computer
7. The replacement happens automatically

### Method 2: Attachment Details

1. Navigate to **Media → Library**
2. Click on the file you want to replace
3. In the attachment details panel, find the **Replace File** button
4. Click the button to open the file picker
5. Select the replacement file
6. The page refreshes with the updated file

## File Requirements

### Filename Must Match

The replacement file must have the **exact same filename** as the original.

| Original | Valid Replacement | Invalid |
|----------|-------------------|---------|
| `report.pdf` | `report.pdf` | `report-v2.pdf` |
| `hero-image.jpg` | `hero-image.jpg` | `hero.jpg` |

**Why?** This ensures URLs remain identical and prevents broken links.

### MIME Type Must Match

The file type must be the same as the original.

| Original | Valid | Invalid |
|----------|-------|---------|
| JPEG image | JPEG image | PNG image |
| PDF document | PDF document | Word document |

### Image Dimensions Must Match

For images, the dimensions must match the original (by default).

| Original | Valid | Invalid |
|----------|-------|---------|
| 1920×1080 | 1920×1080 | 1920×1200 |
| 800×600 | 800×600 | 1600×1200 |

**Note:** This requirement can be disabled by developers. See the [Developer Guide](./developer-guide.md).

## Working with Scaled Images

WordPress automatically scales large images (over 2560px) and adds `-scaled` to the filename.

### How It Works

If you upload `photo.jpg` at 4000×3000 pixels:
- WordPress creates `photo-scaled.jpg` at 2560×1920
- The original `photo.jpg` is also stored

### Replacing Scaled Images

When replacing a scaled image:
1. Use the **original filename** (e.g., `photo.jpg`, not `photo-scaled.jpg`)
2. Match the **original dimensions** (e.g., 4000×3000)
3. WordPress will re-scale the image automatically

The plugin detects scaled images and shows you the correct dimensions to use.

## Success and Error Messages

### Success

After a successful replacement:
- The page refreshes automatically
- The new file is displayed
- All thumbnails are regenerated

### Error Messages

| Error | Meaning | Solution |
|-------|---------|----------|
| "Security check failed" | Session expired | Refresh the page and try again |
| "You do not have permission" | Insufficient user role | Contact an administrator |
| "Filename must match" | Wrong filename | Rename your file to match |
| "MIME type must match" | Wrong file type | Use the same file format |
| "Dimensions must match" | Wrong image size | Resize to match original |

## Auditing Your Media Library

**Media → Media Audit** answers a question the Media Library cannot: which of these files is anything actually using?

### Building the index

The first time you open the screen, everything shows **Scan required** — the index has not been built. Click **Scan Now**. The scan runs in the background in bounded batches, so a large library will not time out, and progress is shown in place.

Once it finishes, each file shows how many posts reference it. **Unused** (in red) means nothing the scanner looks at references that file.

### Reading the list

| Column | What it tells you |
|--------|-------------------|
| Preview | Thumbnail for images, a generic icon otherwise |
| File Name | Hover for Edit, Delete, View and Download actions |
| Type | Image, Video, Audio or Document |
| Used In | Usage count — click it to see exactly which posts |
| Size | File size on disk |
| Alt Text | The attachment's alt text, or a "No alt" marker |
| Date | Upload date |

### Filtering

Four filters narrow the list:

- **Location** — how the file is referenced: Block, Featured Image, Content (classic markup), or Post Meta (page builders)
- **Type** — Image, Video, Audio, Document
- **Used In** — Used or Unused
- **Without Alt** — images embedded in content without alt text

Combine them: *Type: Image* + *Without Alt* gives you an accessibility worklist. *Used In: Unused* + sort by Size descending gives you the biggest cleanup wins first.

### Deleting unused files

Select unused files and use the bulk **Delete** action, or use the row action. Both are restricted to files with a usage count of zero — if a file is referenced anywhere, no delete control is offered for it.

Before bulk deleting, be aware of what "Unused" cannot know about: URLs hardcoded in theme files, custom post types not included in the scan, and references from outside your site. Click through a usage count of zero on a few files you recognise before trusting it wholesale.

### Keeping it current

You do not need to rescan after routine edits. Saving, trashing or deleting a post updates its references immediately, and deleting an attachment removes it from the list. Rescan when you have imported content in bulk, or after adding post types or meta keys to the scan via filters.

### On multisite

Each site has its own index and its own audit screen — the index describes that site's content. If a scan seems stuck on a quiet subsite, WP-Cron is not firing there; ask an administrator to run `wp smr audit scan --site-id=<id>`.

---

## Best Practices

### Before Replacing

1. **Back up important files** - Keep a copy of the original
2. **Check the filename** - Ensure it matches exactly
3. **Verify dimensions** - For images, match the original size
4. **Clear caches** - After replacement, clear any CDN or caching plugin

### Use Cases

**Updating Documents**
- Replace outdated PDFs with new versions
- Update terms and conditions
- Refresh downloadable resources

**Updating Images**
- Fix typos in infographics
- Update screenshots for new UI
- Replace seasonal graphics

**Correcting Mistakes**
- Fix color issues in photos
- Replace low-quality uploads
- Correct cropping errors

## Permissions

You need the `edit_post` capability for the specific attachment to replace it. Typically this means:

- **Administrators** - Can replace any file
- **Editors** - Can replace any file
- **Authors** - Can replace their own files
- **Contributors** - Cannot replace files (no upload capability)

## Keyboard Shortcuts

The plugin does not add keyboard shortcuts. Use standard browser and WordPress navigation.
