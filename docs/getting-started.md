# Getting Started

## Requirements

- WordPress 7.0 or higher
- PHP 8.0 or higher
- User must have `edit_post` capability for the attachment

## Installation

### From WordPress Admin

1. Navigate to **Plugins → Add New**
2. Search for "Smart Media Replacement"
3. Click **Install Now**
4. Click **Activate**

### Manual Installation

1. Download the plugin ZIP file
2. Navigate to **Plugins → Add New → Upload Plugin**
3. Select the ZIP file and click **Install Now**
4. Click **Activate**

### Via Composer

```bash
composer require troychaplin/smart-media-replacement
```

## First Steps

After activation, the plugin works immediately with no configuration required.

### Replacing Your First File

1. Go to **Media → Library**
2. Find the file you want to replace
3. Click the **Replace** link in the row actions
4. Select a new file from your computer
5. The file is replaced instantly

## What Gets Preserved

When you replace a media file:

- **URL stays the same** - All existing links continue to work
- **Attachment ID preserved** - Database references remain valid
- **Post relationships kept** - Featured images and embeds stay connected
- **Alt text and captions** - All metadata is retained

## What Changes

- The physical file content
- Generated thumbnail sizes (regenerated automatically)
- File modification date
- Attachment metadata (dimensions, file size)

## Next Steps

- [User Guide](./user-guide.md) - Detailed usage instructions
- [Validation Rules](./validation-rules.md) - Understanding file requirements
- [Troubleshooting](./troubleshooting.md) - Common issues and solutions
