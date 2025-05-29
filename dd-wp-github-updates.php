<?php
/**
 * DD WP GitHub Updates
 *
 * @package           DD_WP_GitHub_Updates
 * @author            Your Name
 * @copyright         2023 Your Name or Company
 * @license           GPL-2.0-or-later
 *
 * @wordpress-plugin
 * Plugin Name:       DD WP GitHub Updates
 * Plugin URI:        https://example.com/plugins/dd-wp-github-updates
 * Description:       Update WordPress themes and plugins from private GitHub repositories.
 * Version:           1.0.0
 * Requires at least: 5.2
 * Requires PHP:      7.2
 * Author:            Your Name
 * Author URI:        https://example.com
 * Text Domain:       dd-wp-github-updates
 * License:           GPL v2 or later
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 * Domain Path:       /languages
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('DD_GITHUB_UPDATES_VERSION', '1.0.0');
define('DD_GITHUB_UPDATES_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('DD_GITHUB_UPDATES_PLUGIN_URL', plugin_dir_url(__FILE__));
define('DD_GITHUB_UPDATES_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Include required files
require_once DD_GITHUB_UPDATES_PLUGIN_DIR . 'includes/class-dd-github-updates.php';

// Initialize the plugin
function dd_github_updates_init() {
    // Load text domain for translations
    load_plugin_textdomain('dd-wp-github-updates', false, dirname(DD_GITHUB_UPDATES_PLUGIN_BASENAME) . '/languages');

    $plugin = new DD_GitHub_Updates();
    $plugin->init();
}
add_action('plugins_loaded', 'dd_github_updates_init');
