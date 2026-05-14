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
