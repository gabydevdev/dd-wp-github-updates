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
    }

    /**
     * Register all hooks related to the plugin functionality
     */
    private function register_hooks() {
        // Initialize admin functionality
        $admin = new DD_GitHub_Updates_Admin();
        $admin->register_hooks();

        // Initialize updater
        $updater = new DD_GitHub_Updater();
        $updater->register_hooks();

        // Initialize installer
        $installer = new DD_GitHub_Installer();
        $installer->register_hooks();

        // Add hooks for GitHub updates
        add_filter('upgrader_pre_download', array($this, 'mark_as_github_update'), 10, 3);
    }    /**
     * Handle GitHub downloads with authentication
     *
     * @param bool|WP_Error $result The download result or error
     * @param string $package The package URL
     * @param object $upgrader The WP_Upgrader instance
     * @return bool|WP_Error The download result or WP_Error on failure
     */
    public function mark_as_github_update($result, $package, $upgrader) {
        // Check if the package URL is from GitHub
        if (strpos($package, 'github.com') !== false ||
            strpos($package, 'api.github.com') !== false) {

            error_log('DD GitHub Updates: Intercepting GitHub download: ' . $package);

            // Store a flag that will be checked by our upgrader_source_selection filter
            if (isset($upgrader->skin) && isset($upgrader->skin->options)) {
                $upgrader->skin->options['github_update'] = true;

                // Try to determine the type from the context
                if (is_a($upgrader, 'Theme_Upgrader')) {
                    $upgrader->skin->options['type'] = 'theme';
                } elseif (is_a($upgrader, 'Plugin_Upgrader')) {
                    $upgrader->skin->options['type'] = 'plugin';
                }
            }

            // Use our authenticated download method for GitHub URLs
            $api = new DD_GitHub_API();

            // Check if this URL requires authentication
            if ($api->url_requires_auth($package)) {
                error_log('DD GitHub Updates: URL requires authentication, using authenticated download');

                // Download using our authenticated method
                $temp_file = $api->download_file_authenticated($package);

                if (is_wp_error($temp_file)) {
                    error_log('DD GitHub Updates: Authenticated download failed: ' . $temp_file->get_error_message());
                    return $temp_file;
                }

                error_log('DD GitHub Updates: Successfully downloaded with authentication to: ' . $temp_file);
                return $temp_file;
            } else {
                error_log('DD GitHub Updates: URL does not require authentication, using standard download');
                // For public repositories that don't require auth, still try authenticated download
                // in case of rate limiting
                $temp_file = $api->download_file_authenticated($package);

                if (is_wp_error($temp_file)) {
                    error_log('DD GitHub Updates: Authenticated download failed, falling back to standard download: ' . $temp_file->get_error_message());
                    // Fall through to let WordPress handle it normally
                } else {
                    error_log('DD GitHub Updates: Successfully downloaded to: ' . $temp_file);
                    return $temp_file;
                }
            }
        }

        return $result;
    }
}
