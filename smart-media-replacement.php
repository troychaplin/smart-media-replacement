<?php
/**
 * Plugin Name:       Smart Media Replacement
 * Description:       This plugin allows you to replace media in the media library.
 * Requires at least: 6.6
 * Requires PHP:      7.0
 * Version:           1.0.0
 * Author:            Troy Chaplin
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       smart-media-replacement
 *
 * @package Smart_Media_Replacement
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

// Define plugin constants.
define( 'SMART_MEDIA_REPLACEMENT_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Include Composer's autoload file.
require_once plugin_dir_path( __FILE__ ) . 'vendor/autoload.php';

// Instantiate the plugin classes.
new \Smart_Media_Replacement\ManageMedia();
