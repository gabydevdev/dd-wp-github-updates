<?php
/**
 * GitHub Installer
 *
 * @package DD_WP_GitHub_Updates
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub Installer Class
 */
class DD_GitHub_Installer {

    /**
     * GitHub API instance
     *
     * @var DD_GitHub_API
     */
    private $api;

    /**
     * Constructor
     */
    public function __construct() {
        // Include WordPress formatting functions for sanitize_title
        require_once ABSPATH . 'wp-includes/formatting.php';

        $this->api = new DD_GitHub_API();
    }

    /**
     * Register hooks for installer functionality
     */
    public function register_hooks() {
        // Add AJAX handlers for installation
        add_action('wp_ajax_dd_github_search_repository', array($this, 'ajax_search_repository'));
        add_action('wp_ajax_dd_github_install_from_github', array($this, 'ajax_install_from_github'));

        // Add filter to restructure flat repositories
        add_filter('upgrader_source_selection', array($this, 'maybe_restructure_github_package'), 10, 4);
    }

    /**
     * Handle AJAX search repository
     */
    public function ajax_search_repository() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dd_github_updates_nonce')) {
            wp_send_json_error('Security check failed.');
            return;
        }

        // Check user capabilities
        if (!current_user_can('install_plugins') || !current_user_can('install_themes')) {
            wp_send_json_error('You do not have permission to perform this action.');
            return;
        }

        // Get search parameters
        $owner = isset($_POST['owner']) ? sanitize_text_field($_POST['owner']) : '';
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';

        if (empty($owner) || empty($name)) {
            wp_send_json_error('Repository owner and name are required.');
            return;
        }

        // Get repository information
        $repo_info = $this->api->get_repository($owner, $name);

        if (is_wp_error($repo_info)) {
            wp_send_json_error($repo_info->get_error_message());
            return;
        }

        // Get latest release
        $release = $this->api->get_latest_release($owner, $name);

        if (is_wp_error($release)) {
            wp_send_json_error('No releases found for this repository.');
            return;
        }

        $response = array(
            'name' => $repo_info['name'],
            'description' => $repo_info['description'],
            'version' => preg_replace('/^v/', '', $release['tag_name']),
            'author' => $repo_info['owner']['login'],
            'stars' => $repo_info['stargazers_count'],
            'updated_at' => date('Y-m-d', strtotime($repo_info['updated_at'])),
            'release_notes' => $release['body'],
            'download_url' => $release['zipball_url'],
            'has_wiki' => $repo_info['has_wiki'],
            'license' => isset($repo_info['license']['name']) ? $repo_info['license']['name'] : 'Unknown',
        );

        wp_send_json_success($response);
    }

    /**
     * Handle AJAX install from GitHub - Simplified approach
     */
    public function ajax_install_from_github() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dd_github_updates_nonce')) {
            wp_send_json_error('Security check failed.');
            return;
        }

        // Check user capabilities
        if (!current_user_can('install_plugins') && !current_user_can('install_themes')) {
            wp_send_json_error('You do not have permission to perform this action.');
            return;
        }

        // Get installation parameters
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $owner = isset($_POST['owner']) ? sanitize_text_field($_POST['owner']) : '';
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $download_url = isset($_POST['download_url']) ? esc_url_raw($_POST['download_url']) : '';
        $slug = isset($_POST['slug']) ? sanitize_title($_POST['slug']) : sanitize_title($name);
        $activate = isset($_POST['activate']) && $_POST['activate'] === 'true';

        if (empty($type) || empty($owner) || empty($name)) {
            wp_send_json_error('Required parameters are missing.');
            return;
        }

        if ($type !== 'plugin' && $type !== 'theme') {
            wp_send_json_error('Invalid installation type.');
            return;
        }

        // Check capabilities for specific type
        if ($type === 'plugin' && !current_user_can('install_plugins')) {
            wp_send_json_error('You do not have permission to install plugins.');
            return;
        }

        if ($type === 'theme' && !current_user_can('install_themes')) {
            wp_send_json_error('You do not have permission to install themes.');
            return;
        }

        // Include required files for installation
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Initialize the WP_Filesystem
        WP_Filesystem();
        global $wp_filesystem;        // Set destination based on type
        $destination = ($type === 'plugin') ? WP_PLUGIN_DIR : get_theme_root();

        // Create target directory
        $target_dir = $destination . '/' . $slug;

        // Check if directory already exists and is not empty
        if ($wp_filesystem->exists($target_dir)) {
            // Check if directory has contents
            $dir_contents = $wp_filesystem->dirlist($target_dir);
            if (!empty($dir_contents)) {
                // Filter out hidden files and directories
                $visible_contents = array_filter($dir_contents, function($item) {
                    return substr($item['name'], 0, 1) !== '.';
                });

                if (!empty($visible_contents)) {
                    wp_send_json_error('The directory "' . $slug . '" already exists and is not empty. Please choose a different name or remove the existing directory.');
                    return;
                }
            }

            // Directory exists but is empty, we can use it
            error_log('Directory exists but is empty, proceeding with installation: ' . $target_dir);
        } else {
            // Create the target directory
            if (!$wp_filesystem->mkdir($target_dir, FS_CHMOD_DIR)) {
                wp_send_json_error('Could not create directory: ' . $target_dir);
                return;
            }
            error_log('Created new directory: ' . $target_dir);
        }// Get download URL if not provided
        if (empty($download_url)) {
            $download_url = $this->api->get_download_url($owner, $name);
            if (is_wp_error($download_url)) {
                wp_send_json_error('Failed to get download URL: ' . $download_url->get_error_message());
                return;
            }
        }

        error_log('Using download URL: ' . $download_url);

        // Check if this URL requires authentication
        if ($this->api->url_requires_auth($download_url)) {
            error_log('GitHub Download: URL requires authentication');

            // Get the GitHub token to verify it exists
            $options = get_option('dd_github_updates_settings');
            $has_token = !empty($options['github_token']);

            if (!$has_token) {
                wp_send_json_error('This repository requires a GitHub token for access. Please configure your GitHub token in the plugin settings.');
                return;
            }
        }

        // Download the package using authenticated method
        $temp_file = $this->api->download_file_authenticated($download_url);
        if (is_wp_error($temp_file)) {
            $error_message = $temp_file->get_error_message();
            $error_data = $temp_file->get_error_data();

            // Provide more helpful error messages based on the error type
            if (isset($error_data['code'])) {
                switch ($error_data['code']) {
                    case 404:
                        $error_message = 'Repository or release not found. Please verify the repository exists and you have access to it.';
                        break;
                    case 401:
                    case 403:
                        $error_message = 'Access denied. Your GitHub token may be invalid or missing required permissions. Please check your token configuration.';
                        break;
                }            }

            // Clean up the empty directory since download failed
            $this->cleanup_empty_directory($target_dir);
            wp_send_json_error('Failed to download package: ' . $error_message);
            return;
        }        // Extract package directly to target directory
        error_log('Extracting package to: ' . $target_dir);
        $unzip_result = unzip_file($temp_file, $target_dir);

        // Clean up the temp file
        @unlink($temp_file);

        if (is_wp_error($unzip_result)) {
            error_log('Unzip failed: ' . $unzip_result->get_error_message());
            // Clean up the partially created directory
            $wp_filesystem->rmdir($target_dir, true);
            wp_send_json_error('Failed to extract package: ' . $unzip_result->get_error_message());
            return;
        }

        error_log('Package extracted successfully to: ' . $target_dir);

        // Check if the extracted content has a subdirectory structure (common with GitHub)
        $contents = glob($target_dir . '/*');
        $dirs = array_filter($contents, function($item) {
            return is_dir($item) && basename($item) !== '.' && basename($item) !== '..';
        });

        // If there's exactly one directory and minimal files in the root, it might be a GitHub repository structure
        if (count($dirs) === 1 && count($contents) <= 3) {
            $subdir = $dirs[0];
            $subdir_contents = glob($subdir . '/*');

            // Move all files from subdirectory to target directory
            foreach ($subdir_contents as $item) {
                $basename = basename($item);
                $target = $target_dir . '/' . $basename;

                // Don't overwrite existing files
                if (file_exists($target)) {
                    continue;
                }

                rename($item, $target);
            }

            // Remove the now-empty subdirectory
            rmdir($subdir);
        }

        // For plugins, find the main plugin file
        if ($type === 'plugin') {
            $main_file = '';
            $plugin_files = glob($target_dir . '/*.php');

            foreach ($plugin_files as $file) {
                $plugin_data = get_plugin_data($file);
                if (!empty($plugin_data['Name'])) {
                    $main_file = $slug . '/' . basename($file);
                    break;
                }
            }

            if (empty($main_file)) {
                wp_send_json_error('Could not find the main plugin file.');
                return;
            }

            // Activate if requested
            if ($activate) {
                $activate_result = activate_plugin($main_file);
                if (is_wp_error($activate_result)) {
                    wp_send_json_error('Plugin installed but could not be activated: ' . $activate_result->get_error_message());
                    return;
                }
            }

            // Add to repository list if requested
            if (isset($_POST['add_to_updater']) && $_POST['add_to_updater'] === 'true') {
                $this->add_to_repository_list('plugin', $owner, $name, '', $main_file);
            }

            wp_send_json_success(array(
                'message' => $activate ? 'Plugin installed and activated successfully.' : 'Plugin installed successfully.',
                'file' => $main_file
            ));
            return;
        }

        // For themes
        if ($type === 'theme') {
            // Check if style.css exists
            if (!file_exists($target_dir . '/style.css')) {
                wp_send_json_error('Could not find the theme\'s style.css file.');
                return;
            }

            // Activate if requested
            if ($activate) {
                switch_theme($slug);
            }

            // Add to repository list if requested
            if (isset($_POST['add_to_updater']) && $_POST['add_to_updater'] === 'true') {
                $this->add_to_repository_list('theme', $owner, $name, $slug, '');
            }

            wp_send_json_success(array(
                'message' => $activate ? 'Theme installed and activated successfully.' : 'Theme installed successfully.',
                'slug' => $slug
            ));
            return;
        }

        wp_send_json_error('Invalid installation type.');
    }

    /**
     * Simplified GitHub package installation
     *
     * @param string $type Type of package (plugin or theme)
     * @param string $owner Repository owner
     * @param string $name Repository name
     * @param string $download_url Download URL (optional)
     * @param string $slug Desired slug (optional)
     * @param bool $activate Whether to activate after install
     * @return array|WP_Error Result data on success, WP_Error on failure
     */
    public function install_github_package($type, $owner, $name, $download_url = '', $slug = '', $activate = false) {
        // Include required files for installation
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Initialize the WP_Filesystem
        WP_Filesystem();
        global $wp_filesystem;

        // Set destination based on type
        $destination = ($type === 'plugin') ? WP_PLUGIN_DIR : get_theme_root();

        // Determine slug if not provided
        if (empty($slug)) {
            $slug = sanitize_title($name);
        }

        // Create target directory
        $target_dir = $destination . '/' . $slug;

        // Check if directory already exists and is not empty
        if (is_dir($target_dir) && count(glob($target_dir . '/*'))) {
            return new WP_Error('directory_exists', sprintf(
                __('The %s directory already exists and is not empty.', 'dd-wp-github-updates'),
                $slug
            ));
        }

        // Ensure target directory exists
        if (!$wp_filesystem->exists($target_dir)) {
            if (!$wp_filesystem->mkdir($target_dir, FS_CHMOD_DIR)) {
                return new WP_Error('mkdir_failed', sprintf(
                    __('Could not create directory %s.', 'dd-wp-github-updates'),
                    $target_dir
                ));
            }
        }        // Get download URL if not provided
        if (empty($download_url)) {
            $download_url = $this->api->get_download_url($owner, $name);
            if (is_wp_error($download_url)) {
                return $download_url;
            }
        }

        error_log('GitHub Install Package: Using download URL: ' . $download_url);

        // Check if this URL requires authentication
        if ($this->api->url_requires_auth($download_url)) {
            error_log('GitHub Install Package: URL requires authentication');

            // Get the GitHub token to verify it exists
            $options = get_option('dd_github_updates_settings');
            $has_token = !empty($options['github_token']);

            if (!$has_token) {
                return new WP_Error('auth_required',
                    __('This repository requires a GitHub token for access. Please configure your GitHub token in the plugin settings.', 'dd-wp-github-updates')
                );
            }
        }

        // Download package using authenticated method
        $temp_file = $this->api->download_file_authenticated($download_url);
        if (is_wp_error($temp_file)) {
            $error_message = $temp_file->get_error_message();
            $error_data = $temp_file->get_error_data();

            // Provide more helpful error messages based on the error type
            if (isset($error_data['code'])) {
                switch ($error_data['code']) {
                    case 404:
                        $error_message = __('Repository or release not found. Please verify the repository exists and you have access to it.', 'dd-wp-github-updates');
                        break;
                    case 401:
                    case 403:
                        $error_message = __('Access denied. Your GitHub token may be invalid or missing required permissions. Please check your token configuration.', 'dd-wp-github-updates');
                        break;
                }
            }

            return new WP_Error('download_failed',
                __('Failed to download package: ', 'dd-wp-github-updates') . $error_message
            );
        }

        // Extract package directly to target directory
        $unzip_result = unzip_file($temp_file, $target_dir);

        // Clean up the temp file
        @unlink($temp_file);

        if (is_wp_error($unzip_result)) {
            // Clean up the partially created directory
            $wp_filesystem->rmdir($target_dir, true);
            return new WP_Error('unzip_failed',
                __('Failed to extract package: ', 'dd-wp-github-updates') . $unzip_result->get_error_message()
            );
        }

        // Check if the extracted content has a subdirectory structure
        // This happens with GitHub releases - the content is often in a subfolder
        $contents = scandir($target_dir);
        $has_single_dir = false;
        $subfolder = null;

        // Count non-hidden directories
        $dirs = array_filter($contents, function($item) use ($target_dir) {
            return $item !== '.' && $item !== '..' && is_dir($target_dir . '/' . $item) && substr($item, 0, 1) !== '.';
        });

        // If there's exactly one directory and it contains all the content, move it up
        if (count($dirs) === 1) {
            $subfolder = array_values($dirs)[0];
            $subdir_path = $target_dir . '/' . $subfolder;

            // Check if the subdirectory has relevant files
            $has_relevant_files = false;

            if ($type === 'theme' && file_exists($subdir_path . '/style.css')) {
                $has_relevant_files = true;
            } elseif ($type === 'plugin') {
                $php_files = glob($subdir_path . '/*.php');
                foreach ($php_files as $file) {
                    if (strpos(file_get_contents($file), 'Plugin Name:') !== false) {
                        $has_relevant_files = true;
                        break;
                    }
                }
            }

            if ($has_relevant_files) {
                // Move all files from subdirectory to the target directory
                $subdir_contents = scandir($subdir_path);
                foreach ($subdir_contents as $item) {
                    if ($item === '.' || $item === '..') continue;

                    $old_path = $subdir_path . '/' . $item;
                    $new_path = $target_dir . '/' . $item;

                    // Skip if destination already exists
                    if ($wp_filesystem->exists($new_path)) continue;

                    // Move file or directory
                    if (is_dir($old_path)) {
                        $wp_filesystem->move($old_path, $new_path);
                    } else {
                        $wp_filesystem->move($old_path, $new_path);
                    }
                }

                // Remove the now-empty subdirectory
                $wp_filesystem->rmdir($subdir_path);
            }
        }

        // For plugins, find the main plugin file
        if ($type === 'plugin') {
            $main_file = '';
            $plugin_files = glob($target_dir . '/*.php');

            foreach ($plugin_files as $file) {
                $plugin_data = get_plugin_data($file);
                if (!empty($plugin_data['Name'])) {
                    $main_file = $slug . '/' . basename($file);
                    break;
                }
            }

            if (empty($main_file)) {
                return new WP_Error('plugin_file_not_found',
                    __('Could not find the main plugin file.', 'dd-wp-github-updates')
                );
            }

            // Activate if requested
            if ($activate) {
                $activate_result = activate_plugin($main_file);
                if (is_wp_error($activate_result)) {
                    return new WP_Error('activation_failed',
                        __('Plugin installed but could not be activated: ', 'dd-wp-github-updates') .
                        $activate_result->get_error_message()
                    );
                }
            }

            return array(
                'message' => $activate ?
                    __('Plugin installed and activated successfully.', 'dd-wp-github-updates') :
                    __('Plugin installed successfully.', 'dd-wp-github-updates'),
                'file' => $main_file
            );
        }

        // For themes
        if ($type === 'theme') {
            // Check if style.css exists
            if (!file_exists($target_dir . '/style.css')) {
                return new WP_Error('theme_file_not_found',
                    __('Could not find the theme\'s style.css file.', 'dd-wp-github-updates')
                );
            }

            // Activate if requested
            if ($activate) {
                switch_theme($slug);
            }

            return array(
                'message' => $activate ?
                    __('Theme installed and activated successfully.', 'dd-wp-github-updates') :
                    __('Theme installed successfully.', 'dd-wp-github-updates'),
                'slug' => $slug
            );
        }

        return new WP_Error('invalid_type', __('Invalid installation type.', 'dd-wp-github-updates'));
    }

    /**
     * Maybe restructure GitHub package
     * This method is kept for backward compatibility
     */
    public function maybe_restructure_github_package($source, $remote_source, $upgrader, $args = array()) {
        // We'll keep this simplified and let it pass through
        // Our new installation method doesn't rely on this
        return $source;
    }

    /**
     * Add to repository list
     *
     * @param string $type Type of repository (theme or plugin).
     * @param string $owner Repository owner.
     * @param string $name Repository name.
     * @param string $slug Theme slug (for themes).
     * @param string $file Plugin file (for plugins).
     * @return bool True on success, false on failure.
     */
    public function add_to_repository_list($type, $owner, $name, $slug = '', $file = '') {
        // Validate parameters
        if (empty($type) || empty($owner) || empty($name)) {
            return false;
        }

        if ($type === 'theme' && empty($slug)) {
            return false;
        }

        if ($type === 'plugin' && empty($file)) {
            return false;
        }

        // Get existing repositories
        $repositories = get_option('dd_github_updates_repositories', array());

        // Add new repository
        $repositories[] = array(
            'type' => $type,
            'owner' => $owner,
            'name' => $name,
            'slug' => $slug,
            'file' => $file,
        );

        // Update option
        return update_option('dd_github_updates_repositories', $repositories);
    }

    /**
     * Safely remove empty directory and clean up
     *
     * @param string $directory Directory path to clean up
     * @return bool True if cleanup successful or directory didn't exist
     */
    private function cleanup_empty_directory($directory) {
        global $wp_filesystem;

        if (!$wp_filesystem->exists($directory)) {
            return true;
        }

        // Check if directory has contents
        $dir_contents = $wp_filesystem->dirlist($directory);
        if (empty($dir_contents)) {
            // Directory is empty, safe to remove
            return $wp_filesystem->rmdir($directory);
        }

        // Check if only hidden files exist
        $visible_contents = array_filter($dir_contents, function($item) {
            return substr($item['name'], 0, 1) !== '.';
        });

        if (empty($visible_contents)) {
            // Only hidden files, remove them and the directory
            foreach ($dir_contents as $item) {
                $item_path = trailingslashit($directory) . $item['name'];
                if ($item['type'] === 'd') {
                    $wp_filesystem->rmdir($item_path, true);
                } else {
                    $wp_filesystem->delete($item_path);
                }
            }
            return $wp_filesystem->rmdir($directory);
        }

        return false; // Directory has visible contents
    }
}
