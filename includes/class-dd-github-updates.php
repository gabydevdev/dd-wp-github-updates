<?php
/**
 * Main plugin class
 *
 * @package DD_WP_GitHub_Updates
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main plugin class
 */
class DD_GitHub_Updates {

    /**
     * Initialize the plugin
     */
    public function init() {
        // Load dependencies
        $this->load_dependencies();

        // Register hooks
        $this->register_hooks();
    }

    /**
     * Load required dependencies
     */
    private function load_dependencies() {
        require_once DD_GITHUB_UPDATES_PLUGIN_DIR . 'includes/class-dd-github-api.php';
        require_once DD_GITHUB_UPDATES_PLUGIN_DIR . 'includes/class-dd-github-updater.php';
        require_once DD_GITHUB_UPDATES_PLUGIN_DIR . 'includes/class-dd-github-installer.php';
        require_once DD_GITHUB_UPDATES_PLUGIN_DIR . 'admin/class-dd-github-updates-admin.php';
    }    /**
     * Register all hooks related to the plugin functionality
     */
    private function register_hooks() {
        // Initialize admin functionality
        $admin = new DD_GitHub_Updates_Admin();

        // Initialize updater
        $updater = new DD_GitHub_Updater();

        // Initialize installer
        $installer = new DD_GitHub_Installer();

        // Add filters for theme and plugin updates
        add_filter('pre_set_site_transient_update_themes', array($updater, 'check_theme_updates'));
        add_filter('pre_set_site_transient_update_plugins', array($updater, 'check_plugin_updates'));

        // Add filters for plugin and theme information
        add_filter('plugins_api', array($updater, 'plugins_api_filter'), 10, 3);
        add_filter('themes_api', array($updater, 'themes_api_filter'), 10, 3);

        // Make sure all upgrader operations check for flat repositories
        add_filter('upgrader_pre_download', array($this, 'mark_as_github_update'), 10, 3);
    }

    /**
     * Mark downloads from GitHub as needing restructuring
     *
     * @param bool|WP_Error $result The download result or error
     * @param string $package The package URL
     * @param object $upgrader The WP_Upgrader instance
     * @return bool|WP_Error The original result
     */
    public function mark_as_github_update($result, $package, $upgrader) {
        // Check if the package URL is from GitHub
        if (strpos($package, 'github.com') !== false ||
            strpos($package, 'api.github.com') !== false) {

            // Store a flag that will be checked by our upgrader_source_selection filter
            $upgrader->skin->options['github_update'] = true;

            // Try to determine the type from the context
            if (is_a($upgrader, 'Theme_Upgrader')) {
                $upgrader->skin->options['type'] = 'theme';
            } elseif (is_a($upgrader, 'Plugin_Upgrader')) {
                $upgrader->skin->options['type'] = 'plugin';
            }

            error_log('DD GitHub Updates: Marked GitHub download for potential restructuring: ' . $package);
        }

        return $result;
    }
}
