# Troubleshooting

Common issues and their solutions when using Smart Media Replacement.

---

## Common Issues

### "Security check failed" Error

**Cause:** Your WordPress session expired or the nonce is invalid.

**Solutions:**
1. Refresh the page and try again
2. Log out and log back in
3. Clear browser cache and cookies
4. Check if another plugin is interfering with nonces

---

### "You do not have permission" Error

**Cause:** Your user role doesn't have the `edit_post` capability for this attachment.

**Solutions:**
1. Contact an administrator to check your permissions
2. Verify you're logged in with the correct account
3. If you uploaded the file, ensure you're an Author or above
4. For other users' files, you need Editor or Administrator role

---

### "Filename must match" Error

**Cause:** The replacement file has a different name than the original.

**Solutions:**
1. Rename your file to match exactly (case-sensitive)
2. Check for extra spaces or characters
3. Ensure the extension matches (`.jpg` vs `.jpeg`)

**Example Fix:**
```
Original: company-logo.png
Your file: Company-Logo.png  ← Wrong (case mismatch)
Rename to: company-logo.png  ← Correct
```

---

### "File type must match" Error

**Cause:** You're trying to replace a file with a different format.

**Solutions:**
1. Convert your file to the correct format
2. Use an image editor to save as the correct type
3. For documents, export to the matching format

**Common Conversions:**
- PNG → JPG: Use any image editor, "Save As" JPEG
- DOCX → PDF: Use "Export as PDF" in Word
- JPG → PNG: Use any image editor, "Save As" PNG

---

### "Image dimensions must match" Error

**Cause:** Your replacement image has different dimensions.

**Solutions:**
1. Resize your image to match the dimensions shown in the error
2. Use an image editor (Photoshop, GIMP, Canva, etc.)
3. For scaled images, match the original dimensions, not the scaled size

**Quick Resize Tools:**
- Online: [Squoosh](https://squoosh.app), [Photopea](https://photopea.com)
- Desktop: Preview (Mac), Photos (Windows), GIMP
- CLI: `convert image.jpg -resize 1920x1080! output.jpg`

---

### Replace Button Not Appearing

**Cause:** JavaScript not loading or conflict with another plugin.

**Solutions:**
1. Check browser console for JavaScript errors (F12 → Console)
2. Try disabling other plugins temporarily
3. Switch to a default theme (Twenty Twenty-Four)
4. Clear any caching plugins
5. Verify the plugin is activated

**Debug Steps:**
```javascript
// In browser console, check if script loaded:
console.log(window.smartMediaReplacement);
// Should show: { ajaxUrl: "...", nonce: "..." }
```

---

### Page Not Refreshing After Replacement

**Cause:** JavaScript error preventing page reload.

**Solutions:**
1. Check browser console for errors
2. Manually refresh the page (F5 or Cmd+R)
3. Clear browser cache
4. The file was likely replaced successfully

---

### Old Image Still Showing After Replacement

**Cause:** Browser or CDN caching.

**Solutions:**
1. Hard refresh: Ctrl+Shift+R (Windows) or Cmd+Shift+R (Mac)
2. Clear browser cache
3. Clear CDN cache (Cloudflare, etc.)
4. Clear WordPress caching plugin cache
5. Wait for cache expiration

**Cache Plugins to Clear:**
- WP Super Cache
- W3 Total Cache
- WP Rocket
- LiteSpeed Cache
- Autoptimize

---

### Scaled Image Confusion

**Symptom:** Error shows different dimensions than what you see.

**Cause:** WordPress scales large images and stores both versions.

**Understanding the Issue:**
```
You uploaded: photo.jpg (4000×3000)
WordPress created: photo-scaled.jpg (2560×1920)
WordPress shows you: 2560×1920 dimensions
Plugin expects: 4000×3000 (original)
```

**Solution:**
1. Note the dimensions in the error message
2. These are the original dimensions
3. Resize your new image to those exact dimensions
4. Upload with the original filename (without `-scaled`)

---

### AJAX Request Failing

**Symptom:** Replacement silently fails or shows generic error.

**Cause:** Server configuration or PHP error.

**Debug Steps:**
1. Check browser Network tab (F12 → Network)
2. Look for the `admin-ajax.php` request
3. Check the response for error details
4. Check PHP error logs

**Common Server Issues:**
- `upload_max_filesize` too small in php.ini
- `post_max_size` too small
- `max_execution_time` exceeded
- Memory limit reached

**Check PHP Settings:**
```php
// Add to theme's functions.php temporarily:
add_action('admin_notices', function() {
    echo '<div class="notice">';
    echo 'Upload Max: ' . ini_get('upload_max_filesize') . '<br>';
    echo 'Post Max: ' . ini_get('post_max_size') . '<br>';
    echo 'Memory: ' . ini_get('memory_limit');
    echo '</div>';
});
```

---

### Plugin Conflicts

**Symptom:** Plugin works on one site but not another.

**Common Conflicting Plugins:**
- Other media replacement plugins
- Security plugins blocking AJAX
- Optimization plugins modifying scripts
- Custom admin themes

**Resolution Steps:**
1. Deactivate all other plugins
2. Test Smart Media Replacement
3. Reactivate plugins one by one
4. Identify the conflicting plugin
5. Report the conflict for investigation

---

## Media Audit

### Media Audit menu is missing

The audit subsystem is gated on a setting. Check **Media → Replacement Settings → Media Audit → Enable Media Audit** (or **Network Admin → Settings → Media Replacement** on multisite). When it is off, the screen is hidden, the REST route is not registered, and posts are not indexed on save. Existing index data is left untouched, so re-enabling it does not require a rescan.

### Media Audit table is empty, or every file says "Scan required"

The index has not been built yet. The plugin deliberately does not start a scan on activation — an unbounded index build across every site of a network at activation time is hostile — so the first scan is user-initiated.

Click **Scan Now** on the audit screen, or run:

```bash
wp smr audit scan
```

### Scan appears stuck at 0% on a multisite subsite

WP-Cron only fires for a site that is receiving requests. A scan started from a quiet subsite's admin has nothing to advance it.

Build the index from the command line instead:

```bash
wp smr audit scan --site-id=<id>
wp smr audit scan --network        # every site
```

`wp smr audit status --network` shows which sites have a built index.

### A file is marked "Unused" but I know it is used

The scanner looks at post content (blocks and classic markup), featured images, and page-builder post meta, across the post types in `smr_audit_scan_post_types`. It cannot see:

- URLs hardcoded in theme template files
- Custom post types not added via the `smr_audit_scan_post_types` filter
- Page-builder meta keys not registered via `smr_audit_scanned_meta_keys`
- References from outside your site

Add the relevant post types or meta keys via those filters, then rescan.

### Media Audit screen is blank, with a console error about unstable APIs

The audit interface bundles `@wordpress/dataviews`, which opts into WordPress private APIs. That only works on WordPress versions where core lists `@wordpress/dataviews` in its private-API allow-list — WordPress 7.0 and later. Check your WordPress version; the plugin declares `Requires at least: 7.0` for exactly this reason.

### `wp smr audit status` reports `tables: missing`

That site was never provisioned. It self-heals on next use — load the audit screen, run a scan, or trigger the health-check cron:

```bash
wp cron event run smr_db_health_check --url=<site-url>
```

---

## Debug Mode

### Enable WordPress Debug Logging

Add to `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
define('WP_DEBUG_DISPLAY', false);
```

Check logs at: `wp-content/debug.log`

### Check JavaScript Console

1. Open browser developer tools (F12)
2. Go to Console tab
3. Attempt the replacement
4. Look for red error messages

### Check Network Requests

1. Open browser developer tools (F12)
2. Go to Network tab
3. Attempt the replacement
4. Find the `admin-ajax.php` request
5. Check Response tab for details

---

## Getting Help

### Information to Include

When reporting issues, provide:

1. WordPress version
2. PHP version
3. Plugin version
4. Error message (exact text)
5. Browser and version
6. Steps to reproduce
7. Any relevant error logs

### Collecting System Info

```php
// Add temporarily to see system info:
add_action('admin_notices', function() {
    global $wp_version;
    echo '<div class="notice notice-info"><p>';
    echo 'WP: ' . $wp_version . ' | ';
    echo 'PHP: ' . phpversion() . ' | ';
    echo 'Plugin: 1.0.0';
    echo '</p></div>';
});
```

---

## FAQ

**Q: Can I replace a JPG with a PNG?**
A: No, the file type must match. Convert your image first.

**Q: Can I replace with a different size image?**
A: By default, no. Developers can disable this with a filter.

**Q: Does this work with multisite?**
A: Yes, it works on individual sites within a multisite network.

**Q: Are old thumbnails deleted?**
A: Yes, all old sizes are deleted and new ones generated.

**Q: Does this work with CDNs?**
A: Yes, but you may need to purge CDN cache after replacement.

**Q: Can I undo a replacement?**
A: No, keep backups of important files before replacing.
