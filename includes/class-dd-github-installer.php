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
    private $api;    /**
     * Constructor
     */
    public function __construct() {
        // Include WordPress formatting functions for sanitize_title
        require_once ABSPATH . 'wp-includes/formatting.php';

        $this->api = new DD_GitHub_API();

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
     * Handle AJAX install from GitHub
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

        // Set upgrader skin based on type
        if ($type === 'plugin') {
            $skin = new Plugin_Installer_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            $destination = WP_PLUGIN_DIR;
        } else {
            $skin = new Theme_Installer_Skin();
            $upgrader = new Theme_Upgrader($skin);
            $destination = get_theme_root();
        }

        // Get the proper download URL using our API class
        $api = new DD_GitHub_API();

        // Use provided download URL or get from the API
        if (empty($download_url) || $download_url === 'undefined') {
            error_log('Getting download URL for ' . $owner . '/' . $name);
            $download_url = $api->get_download_url($owner, $name);

            if (is_wp_error($download_url)) {
                wp_send_json_error('Failed to get download URL: ' . $download_url->get_error_message());
                return;
            }
        }

        error_log('Using download URL: ' . $download_url);

        // Download the package
        $download_file = $api->download_file($download_url);

        if (is_wp_error($download_file)) {
            wp_send_json_error('Failed to download package: ' . $download_file->get_error_message());
            return;
        }        // Unpack the package
        $unzipped = unzip_file($download_file, $destination);

        // Remove the temporary file after handling it
        $keep_temp_file = false;

        if (is_wp_error($unzipped)) {
            error_log('Failed to unpack package: ' . $unzipped->get_error_message());

            // Enhanced error diagnosis
            if (file_exists($download_file)) {
                $file_size = filesize($download_file);
                error_log('Downloaded file size: ' . $file_size . ' bytes');

                // Check if file is a valid ZIP
                if (class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    $result = $zip->open($download_file, ZipArchive::CHECKCONS);
                    if ($result === TRUE) {
                        error_log('ZIP file appears valid. Contains ' . $zip->numFiles . ' files.');

                        // List the first few files to diagnose structure
                        $file_count = min(10, $zip->numFiles);
                        for ($i = 0; $i < $file_count; $i++) {
                            $file_info = $zip->statIndex($i);
                            if ($file_info) {
                                error_log('ZIP contains: ' . $file_info['name']);
                            }
                        }

                        // Try to extract using ZipArchive directly
                        error_log('Attempting extraction using ZipArchive...');
                        if ($zip->extractTo($destination)) {
                            error_log('ZipArchive extraction successful!');
                            $unzipped = true; // Override the error
                        } else {
                            error_log('ZipArchive extraction failed with code: ' . $zip->getStatusString());
                        }

                        $zip->close();
                    } else {
                        error_log('ZIP file validation failed with code: ' . $result);

                        // Keep the file for manual inspection if it's invalid
                        $keep_temp_file = true;
                        error_log('Keeping temporary file for inspection at: ' . $download_file);
                    }
                } else {
                    // Check ZIP signature as fallback
                    $handle = fopen($download_file, 'rb');
                    if ($handle) {
                        $signature = bin2hex(fread($handle, 4));
                        fclose($handle);
                        error_log('File signature: ' . $signature . ' (Should be 504b0304 for ZIP files)');
                    }
                }

                // Try fallback extraction if the file exists and has content
                if ($file_size > 100 && is_wp_error($unzipped)) {
                    error_log('Attempting PclZip fallback extraction method...');

                    require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
                    $archive = new PclZip($download_file);
                    $result = $archive->extract(PCLZIP_OPT_PATH, $destination);

                    if ($result !== 0) {
                        error_log('PclZip fallback extraction successful! Extracted ' . count($result) . ' files.');
                        $unzipped = true; // Override the error
                    } else {
                        error_log('PclZip fallback extraction failed: ' . $archive->errorInfo(true));

                        // Try system unzip command as last resort
                        if (function_exists('exec')) {
                            error_log('Attempting system unzip command...');
                            $output = array();
                            $return_var = 0;
                            exec('unzip -o ' . escapeshellarg($download_file) . ' -d ' . escapeshellarg($destination) . ' 2>&1', $output, $return_var);

                            error_log('System unzip output: ' . implode("\n", $output));

                            if ($return_var === 0) {
                                error_log('System unzip extraction successful!');
                                $unzipped = true; // Override the error
                            } else {
                                error_log('System unzip extraction failed with code: ' . $return_var);
                            }
                        }
                    }
                }
            } else {
                error_log('Downloaded file not found at path: ' . $download_file);
            }

            if (is_wp_error($unzipped)) {
                wp_send_json_error('Failed to unpack package: ' . $unzipped->get_error_message() . '. Please check your GitHub repository structure.');

                // Clean up if we're not keeping the file for inspection
                if (!$keep_temp_file && file_exists($download_file)) {
                    @unlink($download_file);
                }

                return;
            }
        }

        // Clean up the temporary file if we don't need to keep it
        if (!$keep_temp_file && file_exists($download_file)) {
            @unlink($download_file);
        }        // Determine the installed directory name
        $temp_folder = $destination . '/' . $owner . '-' . $name . '-';
        $folders = glob($temp_folder . '*', GLOB_ONLYDIR);

        error_log('Looking for extracted folders in: ' . $temp_folder . '*');
        error_log('Destination directory: ' . $destination);

        // If we can't find folders with the expected pattern, try a more general approach
        if (empty($folders)) {
            error_log('Standard pattern not found, trying alternative patterns...');

            // Try to find by common GitHub extraction patterns
            $alt_patterns = array(
                $destination . '/' . $name . '-v*',                // name-v1.0.0
                $destination . '/' . $name . '-*',                // name-version
                $destination . '/' . $name,                       // exact name
                $destination . '/*' . $name . '*',                // anything with name
                $destination . '/' . $owner . '-' . $name . '*',  // owner-name-with-suffix
                $destination . '/' . $owner . '-' . $name,        // owner-name
                $destination . '/' . $owner . '.' . $name,        // owner.name
                $destination . '/*',                              // any directory
            );

            foreach ($alt_patterns as $pattern) {
                error_log('Trying pattern: ' . $pattern);
                $alt_folders = glob($pattern, GLOB_ONLYDIR);

                if (!empty($alt_folders)) {
                    error_log('Found folders using pattern: ' . $pattern);
                    foreach ($alt_folders as $folder) {
                        error_log('Found folder: ' . $folder);
                    }
                    $folders = $alt_folders;
                    break;
                }
            }

            // List all files in destination to help diagnose the issue
            error_log('Listing all items in destination directory:');
            $all_items = glob($destination . '/*');
            foreach ($all_items as $item) {
                error_log('Item in destination: ' . $item . (is_dir($item) ? ' (dir)' : ' (file)'));
            }

            // Check if files were extracted directly to destination (flat structure)
            // Look for key files that would indicate a theme or plugin
            if (($type === 'theme' && file_exists($destination . '/style.css')) ||
                ($type === 'plugin' && count(glob($destination . '/*.php')) > 0)) {

                error_log('DD GitHub Updates: Found flat structure directly in destination');

                // Generate a slug from the repository name
                $suggested_slug = sanitize_title($name);
                $slug = !empty($_POST['slug']) ? sanitize_title($_POST['slug']) : $suggested_slug;

                // Manually handle the restructuring
                $this->restructure_flat_repository(
                    $destination,
                    dirname($destination), // Parent directory
                    $slug,
                    $type
                );

                // Look for the newly created directory
                $folders = glob($destination . '/' . $slug, GLOB_ONLYDIR);
                if (!empty($folders)) {
                    $installed_dir = $slug;
                } else {
                    wp_send_json_error('Failed to restructure the flat repository.');
                    return;
                }
            } else {
                // Create a directory ourselves if nothing was found but extraction succeeded
                if (!is_wp_error($unzipped)) {
                    error_log('Extraction succeeded but no directories found. Creating directory manually...');

                    // Create a directory with the slug
                    $suggested_slug = sanitize_title($name);
                    $slug = !empty($_POST['slug']) ? sanitize_title($_POST['slug']) : $suggested_slug;
                    $target_dir = $destination . '/' . $slug;

                    if (!is_dir($target_dir)) {
                        if (wp_mkdir_p($target_dir)) {
                            error_log('Created directory: ' . $target_dir);

                            // Move all files to the new directory
                            $all_items = glob($destination . '/*');
                            foreach ($all_items as $item) {
                                if ($item != $target_dir) {
                                    $basename = basename($item);
                                    if (is_dir($item)) {
                                        // Skip moving directories for now
                                        continue;
                                    } else {
                                        // Move files
                                        rename($item, $target_dir . '/' . $basename);
                                        error_log('Moved file: ' . $basename . ' to ' . $target_dir);
                                    }
                                }
                            }

                            // Now move content from directories
                            $all_dirs = glob($destination . '/*', GLOB_ONLYDIR);
                            foreach ($all_dirs as $dir) {
                                if ($dir != $target_dir) {
                                    $this->recursive_copy($dir, $target_dir . '/' . basename($dir));
                                    error_log('Copied directory: ' . basename($dir) . ' to ' . $target_dir);
                                }
                            }

                            $folders = array($target_dir);
                            $installed_dir = $slug;
                        } else {
                            error_log('Failed to create directory: ' . $target_dir);
                        }
                    }
                }

                if (empty($folders)) {
                    wp_send_json_error('Failed to locate the installed package. Check WordPress error logs for details.');
                    return;
                }
            }
        } else {
            error_log('Found folders using standard pattern:');
            foreach ($folders as $folder) {
                error_log('Found folder: ' . $folder);
            }
            $installed_dir = basename($folders[0]);

            // We might need to restructure even if the directory exists
            // if it doesn't have the expected structure
            $source_dir = $destination . '/' . $installed_dir;

            // Set this explicitly so our filter will catch it
            add_filter('upgrader_source_selection', function($source, $remote_source, $upgrader, $args = array()) use ($source_dir, $destination, $installed_dir, $type) {
                if ($source === $source_dir) {
                    // Add the github_update flag to ensure our filter runs
                    $args['github_update'] = true;
                    $args['type'] = $type;
                    $args['slug'] = $installed_dir;

                    return $this->maybe_restructure_github_package($source, $destination, $upgrader, $args);
                }
                return $source;
            }, 9, 4);
        }

        // For plugins, we need to check for the main plugin file
        if ($type === 'plugin') {
            // Try to find the main plugin file
            $plugin_files = glob($destination . '/' . $installed_dir . '/*.php');
            $main_file = '';

            foreach ($plugin_files as $file) {
                $plugin_data = get_plugin_data($file);
                if (!empty($plugin_data['Name'])) {
                    $main_file = $installed_dir . '/' . basename($file);
                    break;
                }
            }

            if (empty($main_file)) {
                wp_send_json_error('Could not find the main plugin file.');
                return;
            }

            // Activate the plugin if requested
            if (isset($_POST['activate']) && $_POST['activate']) {
                $activate = activate_plugin($main_file);

                if (is_wp_error($activate)) {
                    wp_send_json_error('Plugin installed but could not be activated: ' . $activate->get_error_message());
                    return;
                }

                wp_send_json_success(array(
                    'message' => 'Plugin installed and activated successfully.',
                    'file' => $main_file,
                ));
                return;
            }

            wp_send_json_success(array(
                'message' => 'Plugin installed successfully.',
                'file' => $main_file,
            ));
            return;
        }

        // For themes, we may need to rename the folder to match the slug
        if ($type === 'theme' && isset($_POST['slug']) && !empty($_POST['slug'])) {
            $slug = sanitize_text_field($_POST['slug']);
            $new_dir = $destination . '/' . $slug;

            // Rename the theme directory if needed
            if ($installed_dir !== $slug && !file_exists($new_dir)) {
                rename($destination . '/' . $installed_dir, $new_dir);
                $installed_dir = $slug;
            }

            // Activate the theme if requested
            if (isset($_POST['activate']) && $_POST['activate']) {
                switch_theme($installed_dir);
                wp_send_json_success(array(
                    'message' => 'Theme installed and activated successfully.',
                    'slug' => $installed_dir,
                ));
                return;
            }

            wp_send_json_success(array(
                'message' => 'Theme installed successfully.',
                'slug' => $installed_dir,
            ));
            return;
        }

        wp_send_json_success(array(
            'message' => ($type === 'plugin' ? 'Plugin' : 'Theme') . ' installed successfully.',
            'directory' => $installed_dir,
        ));
    }

    /**
     * Install package from GitHub
     *
     * @param array $args Installation arguments.
     * @return bool|WP_Error True on success, WP_Error on failure.
     */
    public function install_from_github($args) {
        // Verify nonce
        if (!isset($args['nonce']) || !wp_verify_nonce($args['nonce'], 'dd_github_updates_nonce')) {
            return new WP_Error('nonce_verification_failed', __('Security check failed', 'dd-wp-github-updates'));
        }

        // Check user capabilities
        if (!current_user_can('install_plugins') && !current_user_can('install_themes')) {
            return new WP_Error('insufficient_permissions', __('You do not have permission to perform this action', 'dd-wp-github-updates'));
        }

        // Get installation parameters
        $type = isset($args['type']) ? sanitize_text_field($args['type']) : '';
        $owner = isset($args['owner']) ? sanitize_text_field($args['owner']) : '';
        $name = isset($args['name']) ? sanitize_text_field($args['name']) : '';
        $download_url = isset($args['download_url']) ? esc_url_raw($args['download_url']) : '';

        if (empty($type) || empty($owner) || empty($name)) {
            return new WP_Error('missing_parameters', __('Required parameters are missing', 'dd-wp-github-updates'));
        }

        if ($type !== 'plugin' && $type !== 'theme') {
            return new WP_Error('invalid_type', __('Invalid installation type', 'dd-wp-github-updates'));
        }

        // Check capabilities for specific type
        if ($type === 'plugin' && !current_user_can('install_plugins')) {
            return new WP_Error('insufficient_permissions', __('You do not have permission to install plugins', 'dd-wp-github-updates'));
        }

        if ($type === 'theme' && !current_user_can('install_themes')) {
            return new WP_Error('insufficient_permissions', __('You do not have permission to install themes', 'dd-wp-github-updates'));
        }

        // Include required files for installation
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/misc.php';
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Initialize the WP_Filesystem
        WP_Filesystem();

        // Set upgrader skin based on type
        if ($type === 'plugin') {
            $skin = new Plugin_Installer_Skin();
            $upgrader = new Plugin_Upgrader($skin);
            $destination = WP_PLUGIN_DIR;
        } else {
            $skin = new Theme_Installer_Skin();
            $upgrader = new Theme_Upgrader($skin);
            $destination = get_theme_root();
        }

        // Get the proper download URL using our API class
        $api = new DD_GitHub_API();

        // Use provided download URL or get from the API
        if (empty($download_url) || $download_url === 'undefined') {
            error_log('Getting download URL for ' . $owner . '/' . $name);
            $download_url = $api->get_download_url($owner, $name);

            if (is_wp_error($download_url)) {
                return new WP_Error('download_url_error', __('Failed to get download URL: ', 'dd-wp-github-updates') . $download_url->get_error_message());
            }
        }

        error_log('Using download URL: ' . $download_url);

        // Download the package
        $download_file = $api->download_file($download_url);

        if (is_wp_error($download_file)) {
            return new WP_Error('download_error', __('Failed to download package: ', 'dd-wp-github-updates') . $download_file->get_error_message());
        }        // Unpack the package
        $unzipped = unzip_file($download_file, $destination);

        // Flag to determine if we should keep temporary files for debugging
        $keep_temp_file = false;

        if (is_wp_error($unzipped)) {
            error_log('Failed to unpack package: ' . $unzipped->get_error_message());

            // Enhanced error diagnosis
            if (file_exists($download_file)) {
                $file_size = filesize($download_file);
                error_log('Downloaded file size: ' . $file_size . ' bytes');

                // Check if file is a valid ZIP
                if (class_exists('ZipArchive')) {
                    $zip = new ZipArchive();
                    $result = $zip->open($download_file, ZipArchive::CHECKCONS);
                    if ($result === TRUE) {
                        error_log('ZIP file appears valid. Contains ' . $zip->numFiles . ' files.');

                        // List the first few files to diagnose structure
                        $file_count = min(10, $zip->numFiles);
                        for ($i = 0; $i < $file_count; $i++) {
                            $file_info = $zip->statIndex($i);
                            if ($file_info) {
                                error_log('ZIP contains: ' . $file_info['name']);
                            }
                        }

                        // Try to extract using ZipArchive directly
                        error_log('Attempting extraction using ZipArchive...');
                        if ($zip->extractTo($destination)) {
                            error_log('ZipArchive extraction successful!');
                            $unzipped = true; // Override the error
                        } else {
                            error_log('ZipArchive extraction failed with code: ' . $zip->getStatusString());
                        }

                        $zip->close();
                    } else {
                        error_log('ZIP file validation failed with code: ' . $result);

                        // Keep the file for manual inspection if it's invalid
                        $keep_temp_file = true;
                        error_log('Keeping temporary file for inspection at: ' . $download_file);
                    }
                } else {
                    // Check ZIP signature as fallback
                    $handle = fopen($download_file, 'rb');
                    if ($handle) {
                        $signature = bin2hex(fread($handle, 4));
                        fclose($handle);
                        error_log('File signature: ' . $signature . ' (Should be 504b0304 for ZIP files)');
                    }
                }

                // Try fallback extraction if the file exists and has content
                if ($file_size > 100 && is_wp_error($unzipped)) {
                    error_log('Attempting PclZip fallback extraction method...');

                    require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
                    $archive = new PclZip($download_file);
                    $result = $archive->extract(PCLZIP_OPT_PATH, $destination);

                    if ($result !== 0) {
                        error_log('PclZip fallback extraction successful! Extracted ' . count($result) . ' files.');
                        $unzipped = true; // Override the error
                    } else {
                        error_log('PclZip fallback extraction failed: ' . $archive->errorInfo(true));

                        // Try system unzip command as last resort
                        if (function_exists('exec')) {
                            error_log('Attempting system unzip command...');
                            $output = array();
                            $return_var = 0;
                            exec('unzip -o ' . escapeshellarg($download_file) . ' -d ' . escapeshellarg($destination) . ' 2>&1', $output, $return_var);

                            error_log('System unzip output: ' . implode("\n", $output));

                            if ($return_var === 0) {
                                error_log('System unzip extraction successful!');
                                $unzipped = true; // Override the error
                            } else {
                                error_log('System unzip extraction failed with code: ' . $return_var);
                            }
                        }
                    }
                }
            } else {
                error_log('Downloaded file not found at path: ' . $download_file);
            }

            if (is_wp_error($unzipped)) {
                // Clean up if we're not keeping the file for inspection
                if (!$keep_temp_file && file_exists($download_file)) {
                    @unlink($download_file);
                }

                return new WP_Error('unzip_error', __('Failed to unpack package: ', 'dd-wp-github-updates') . $unzipped->get_error_message());
            }
        }

        // Clean up the temporary file if we don't need to keep it
        if (!$keep_temp_file && file_exists($download_file)) {
            @unlink($download_file);
        }        // Determine the installed directory name
        $temp_folder = $destination . '/' . $owner . '-' . $name . '-';
        $folders = glob($temp_folder . '*', GLOB_ONLYDIR);

        error_log('Looking for extracted folders in: ' . $temp_folder . '*');
        error_log('Destination directory: ' . $destination);

        // If we can't find folders with the expected pattern, try a more general approach
        if (empty($folders)) {
            error_log('Standard pattern not found in non-AJAX install, trying alternative patterns...');

            // Try to find by common GitHub extraction patterns
            $alt_patterns = array(
                $destination . '/' . $name . '-v*',                // name-v1.0.0
                $destination . '/' . $name . '-*',                // name-version
                $destination . '/' . $name,                       // exact name
                $destination . '/*' . $name . '*',                // anything with name
                $destination . '/' . $owner . '-' . $name . '*',  // owner-name-with-suffix
                $destination . '/' . $owner . '-' . $name,        // owner-name
                $destination . '/' . $owner . '.' . $name,        // owner.name
                $destination . '/*',                              // any directory
            );

            foreach ($alt_patterns as $pattern) {
                error_log('Trying pattern: ' . $pattern);
                $alt_folders = glob($pattern, GLOB_ONLYDIR);

                if (!empty($alt_folders)) {
                    error_log('Found folders using pattern: ' . $pattern);
                    foreach ($alt_folders as $folder) {
                        error_log('Found folder: ' . $folder);
                    }
                    $folders = $alt_folders;
                    break;
                }
            }

            // List all files in destination to help diagnose the issue
            error_log('Listing all items in destination directory:');
            $all_items = glob($destination . '/*');
            foreach ($all_items as $item) {
                error_log('Item in destination: ' . $item . (is_dir($item) ? ' (dir)' : ' (file)'));
            }

            // Check if files were extracted directly to destination (flat structure)
            if (($type === 'theme' && file_exists($destination . '/style.css')) ||
                ($type === 'plugin' && count(glob($destination . '/*.php')) > 0)) {

                error_log('DD GitHub Updates: Found flat structure directly in destination during non-AJAX install');

                // Generate a slug from the repository name
                $suggested_slug = sanitize_title($name);
                $slug = !empty($args['slug']) ? sanitize_title($args['slug']) : $suggested_slug;

                // Manually restructure the flat repository
                $restructure_result = $this->restructure_flat_repository(
                    $destination,
                    dirname($destination), // Parent directory
                    $slug,
                    $type
                );

                if (is_wp_error($restructure_result)) {
                    return $restructure_result;
                }

                // Look for the newly created directory
                $folders = glob($destination . '/' . $slug, GLOB_ONLYDIR);
                if (!empty($folders)) {
                    $installed_dir = $slug;
                } else {
                    return new WP_Error('restructure_error', __('Failed to restructure the flat repository.', 'dd-wp-github-updates'));
                }
            } else {
                // Create a directory ourselves if nothing was found but extraction succeeded
                if (!is_wp_error($unzipped)) {
                    error_log('Extraction succeeded but no directories found. Creating directory manually...');

                    // Create a directory with the slug
                    $suggested_slug = sanitize_title($name);
                    $slug = !empty($args['slug']) ? sanitize_title($args['slug']) : $suggested_slug;
                    $target_dir = $destination . '/' . $slug;

                    if (!is_dir($target_dir)) {
                        if (wp_mkdir_p($target_dir)) {
                            error_log('Created directory: ' . $target_dir);

                            // Move all files to the new directory
                            $all_items = glob($destination . '/*');
                            foreach ($all_items as $item) {
                                if ($item != $target_dir) {
                                    $basename = basename($item);
                                    if (is_dir($item)) {
                                        // Skip moving directories for now
                                        continue;
                                    } else {
                                        // Move files
                                        rename($item, $target_dir . '/' . $basename);
                                        error_log('Moved file: ' . $basename . ' to ' . $target_dir);
                                    }
                                }
                            }

                            // Now move content from directories
                            $all_dirs = glob($destination . '/*', GLOB_ONLYDIR);
                            foreach ($all_dirs as $dir) {
                                if ($dir != $target_dir) {
                                    $this->recursive_copy($dir, $target_dir . '/' . basename($dir));
                                    error_log('Copied directory: ' . basename($dir) . ' to ' . $target_dir);
                                }
                            }

                            $folders = array($target_dir);
                            $installed_dir = $slug;
                        } else {
                            error_log('Failed to create directory: ' . $target_dir);
                        }
                    }
                }

                if (empty($folders)) {
                    return new WP_Error('installation_error', __('Failed to locate the installed package. Check WordPress error logs for details.', 'dd-wp-github-updates'));
                }
            }
        } else {
            error_log('Found folders using standard pattern:');
            foreach ($folders as $folder) {
                error_log('Found folder: ' . $folder);
            }
            $installed_dir = basename($folders[0]);

            // We might need to check if the directory has the right structure
            $source_dir = $destination . '/' . $installed_dir;

            // Explicitly restructure if needed
            $restructure_args = array(
                'github_update' => true,
                'type' => $type,
                'slug' => isset($args['slug']) ? $args['slug'] : $installed_dir
            );

            $restructure_result = $this->maybe_restructure_github_package(
                $source_dir,
                $destination,
                null, // No upgrader instance in this context
                $restructure_args
            );

            if (is_wp_error($restructure_result)) {
                return $restructure_result;
            }
        }

        // For plugins, we need to check for the main plugin file
        if ($type === 'plugin') {
            // Try to find the main plugin file
            $plugin_files = glob($destination . '/' . $installed_dir . '/*.php');
            $main_file = '';

            foreach ($plugin_files as $file) {
                $plugin_data = get_plugin_data($file);
                if (!empty($plugin_data['Name'])) {
                    $main_file = $installed_dir . '/' . basename($file);
                    break;
                }
            }

            if (empty($main_file)) {
                return new WP_Error('file_error', __('Could not find the main plugin file', 'dd-wp-github-updates'));
            }

            // Activate the plugin if requested
            if (isset($args['activate']) && $args['activate']) {
                $activate = activate_plugin($main_file);

                if (is_wp_error($activate)) {
                    return new WP_Error('activation_error', __('Plugin installed but could not be activated: ', 'dd-wp-github-updates') . $activate->get_error_message());
                }

                return array(
                    'success' => true,
                    'message' => __('Plugin installed and activated successfully', 'dd-wp-github-updates'),
                    'file' => $main_file,
                );
            }

            return array(
                'success' => true,
                'message' => __('Plugin installed successfully', 'dd-wp-github-updates'),
                'file' => $main_file,
            );
        }

        // For themes, we may need to rename the folder to match the slug
        if ($type === 'theme' && isset($args['slug']) && !empty($args['slug'])) {
            $slug = sanitize_text_field($args['slug']);
            $new_dir = $destination . '/' . $slug;

            // Rename the theme directory if needed
            if ($installed_dir !== $slug && !file_exists($new_dir)) {
                rename($destination . '/' . $installed_dir, $new_dir);
                $installed_dir = $slug;
            }

            // Activate the theme if requested
            if (isset($args['activate']) && $args['activate']) {
                switch_theme($installed_dir);
                return array(
                    'success' => true,
                    'message' => __('Theme installed and activated successfully', 'dd-wp-github-updates'),
                    'slug' => $installed_dir,
                );
            }

            return array(
                'success' => true,
                'message' => __('Theme installed successfully', 'dd-wp-github-updates'),
                'slug' => $installed_dir,
            );
        }

        // After downloading and unzipping but before WordPress processes the package
        // This would typically be after the download_package step but before upgrader->install_package

        // Add code to check if we need to restructure and then call our new function
        if (!empty($args['slug'])) {
            $slug = sanitize_title($args['slug']);
        } else {
            // Generate slug from repo name if not provided
            $slug = sanitize_title($args['name']);
        }

        // Get the working directory and source directory of the package
        $source = $upgrader->unpack_package($package, true);

        if (is_wp_error($source)) {
            return $source;
        }

        // Restructure if needed
        $restructured_source = $this->restructure_flat_repository(
            $source,
            $upgrader->skin->get_upgrade_folder(),
            $slug,
            $args['type']
        );

        if (is_wp_error($restructured_source)) {
            return $restructured_source;
        }

        // Continue with installation using our restructured source
        // ...existing code...

        wp_send_json_success(array(
            'message' => ($type === 'plugin' ? 'Plugin' : 'Theme') . ' installed successfully.',
            'directory' => $installed_dir,
        ));
    }

    /**
     * Process and restructure the downloaded package if needed
     *
     * @param string $package Path to the package file
     * @param string $type Type of package (theme or plugin)
     * @param string $slug Optional slug for renaming
     * @return string|WP_Error Path to the processed package or WP_Error
     */
    private function process_package($package, $type, $slug = '') {
        // Create a temporary working directory
        $temp_dir = get_temp_dir() . 'dd_github_' . uniqid();
        if (!wp_mkdir_p($temp_dir)) {
            return new WP_Error('process_package_error', 'Could not create temporary directory for processing');
        }

        // Extract the package to the temporary directory
        $unzip_result = unzip_file($package, $temp_dir);
        if (is_wp_error($unzip_result)) {
            return new WP_Error('process_package_error', 'Failed to extract package: ' . $unzip_result->get_error_message());
        }

        // Check if the extracted content already has the expected WordPress structure
        $has_structure = false;
        $extracted_items = glob($temp_dir . '/*');

        // If there's only one directory and it contains the expected files, we have proper structure
        if (count($extracted_items) === 1 && is_dir($extracted_items[0])) {
            if ($type === 'theme' && file_exists($extracted_items[0] . '/style.css')) {
                $has_structure = true;
            } elseif ($type === 'plugin' && (
                file_exists($extracted_items[0] . '/plugin.php') ||
                glob($extracted_items[0] . '/*.php')
            )) {
                $has_structure = true;
            }
        }

        // If the structure is already correct, return the original package
        if ($has_structure) {
            // Clean up temporary directory
            $this->recursive_rmdir($temp_dir);
            return $package;
        }

        // Determine the target directory name
        $target_dir_name = !empty($slug) ? $slug : ($type === 'theme' ? 'github-theme' : 'github-plugin');
        $target_dir = $temp_dir . '/' . $target_dir_name;

        // Create the target directory
        if (!wp_mkdir_p($target_dir)) {
            return new WP_Error('process_package_error', 'Could not create target directory for restructuring');
        }

        // Find all files in the temp directory (excluding our target directory)
        $items_to_move = glob($temp_dir . '/*');
        foreach ($items_to_move as $item) {
            // Skip the target directory itself
            if ($item === $target_dir) {
                continue;
            }

            // Get just the basename
            $basename = basename($item);

            // Move the item to the target directory
            if (is_dir($item)) {
                $this->recursive_copy($item, $target_dir . '/' . $basename);
            } else {
                copy($item, $target_dir . '/' . $basename);
            }
        }

        // Create a new ZIP file
        $new_package = get_temp_dir() . 'dd_github_restructured_' . uniqid() . '.zip';

        if (!class_exists('ZipArchive')) {
            // Fallback to WordPress functions or command line tools
            return new WP_Error('process_package_error', 'ZipArchive class not available for creating new package');
        }

        $zip = new ZipArchive();
        if ($zip->open($new_package, ZipArchive::CREATE) !== true) {
            return new WP_Error('process_package_error', 'Could not create new ZIP package');
        }

        // Add the target directory to the ZIP
        $this->add_dir_to_zip($zip, $target_dir, $target_dir_name);
        $zip->close();

        // Verify the new ZIP was created successfully
        if (!file_exists($new_package) || filesize($new_package) < 100) {
            return new WP_Error('process_package_error', 'Failed to create valid ZIP package');
        }

        // Clean up the temporary directory
        $this->recursive_rmdir($temp_dir);

        // Return the path to the new package
        return $new_package;
    }

    /**
     * Restructure a flat repository to proper WordPress format
     *
     * @param string $source Source directory path.
     * @param string $destination Destination directory path.
     * @param string $slug Theme/plugin slug for the directory name.
     * @param string $type Type of package ('theme' or 'plugin').
     * @return string|WP_Error New source path or WP_Error on failure.
     */
    private function restructure_flat_repository($source, $destination, $slug, $type) {
        global $wp_filesystem;

        // Make sure we have access to the filesystem
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        // Log what we're doing
        error_log('DD GitHub Updates: Checking if repository needs restructuring - Source: ' . $source . ', Destination: ' . $destination . ', Slug: ' . $slug . ', Type: ' . $type);

        // Sanitize slug to ensure it's a valid directory name
        $slug = sanitize_file_name($slug);
        if (empty($slug)) {
            $slug = 'github-' . ($type === 'theme' ? 'theme' : 'plugin');
            error_log('DD GitHub Updates: Using fallback slug: ' . $slug);
        }

        // Check if repository is flat (no subdirectories containing the main files)
        $is_flat = false;
        $has_nested_structure = false;
        $has_correct_structure = false;

        // First, check for top-level directories
        $files = $wp_filesystem->dirlist($source);
        $directories = array();
        $php_files = array();
        $has_style_css = $wp_filesystem->exists($source . '/style.css');

        // Log what we found in the source directory
        error_log('DD GitHub Updates: Analyzing source directory structure:');
        foreach ($files as $file => $file_data) {
            $file_type = $file_data['type'] === 'd' ? 'directory' : 'file';
            error_log('- ' . $file . ' (' . $file_type . ')');

            if ($file_data['type'] === 'd') {
                $directories[] = $file;
            } elseif (pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                $php_files[] = $file;
            }
        }

        // For themes, check for style.css in the root
        if ($type === 'theme' && $has_style_css) {
            $is_flat = true;
            error_log('DD GitHub Updates: Found style.css in root - detected flat theme repository');
        }

        // For plugins, check for a PHP file with plugin headers in the root
        if ($type === 'plugin' && !empty($php_files)) {
            foreach ($php_files as $file) {
                $file_path = $source . '/' . $file;
                $file_content = $wp_filesystem->get_contents($file_path);

                // Simple check for Plugin Name: header
                if (strpos($file_content, 'Plugin Name:') !== false) {
                    $is_flat = true;
                    error_log('DD GitHub Updates: Found plugin header in root PHP file - detected flat plugin repository');
                    break;
                }
            }
        }

        // Check for possible nested structure (WordPress standard)
        if (!$is_flat && !empty($directories)) {
            error_log('DD GitHub Updates: Checking for nested structure in subdirectories');

            // Check if any subdirectory has the proper structure
            foreach ($directories as $dir) {
                $subdir_path = $source . '/' . $dir;

                // For themes, check for style.css
                if ($type === 'theme' && $wp_filesystem->exists($subdir_path . '/style.css')) {
                    $has_nested_structure = true;
                    error_log('DD GitHub Updates: Found theme structure in subdirectory: ' . $dir);

                    // If this directory matches our slug, we've found the correct structure
                    if ($dir === $slug) {
                        $has_correct_structure = true;
                        error_log('DD GitHub Updates: Found correct theme structure with matching slug: ' . $slug);
                    }
                }

                // For plugins, check for PHP files with plugin headers
                if ($type === 'plugin') {
                    $subdir_files = $wp_filesystem->dirlist($subdir_path);
                    foreach ($subdir_files as $file => $file_data) {
                        if ($file_data['type'] === 'f' && pathinfo($file, PATHINFO_EXTENSION) === 'php') {
                            $file_path = $subdir_path . '/' . $file;
                            $file_content = $wp_filesystem->get_contents($file_path);

                            if (strpos($file_content, 'Plugin Name:') !== false) {
                                $has_nested_structure = true;
                                error_log('DD GitHub Updates: Found plugin structure in subdirectory: ' . $dir);

                                // If this directory matches our slug, we've found the correct structure
                                if ($dir === $slug) {
                                    $has_correct_structure = true;
                                    error_log('DD GitHub Updates: Found correct plugin structure with matching slug: ' . $slug);
                                }
                                break;
                            }
                        }
                    }
                }
            }
        }

        // If repository has the correct structure already, return the source
        if ($has_correct_structure) {
            error_log('DD GitHub Updates: Repository already has correct structure with matching slug directory');
            return $source;
        }

        // If repository has a nested structure but with wrong directory name, we should rename it
        if ($has_nested_structure && !$has_correct_structure && count($directories) === 1) {
            $existing_dir = $directories[0];
            error_log('DD GitHub Updates: Repository has proper structure but directory name needs to be changed from ' . $existing_dir . ' to ' . $slug);

            // Create a temporary working directory
            $temp_dir = $destination . '-restructure-temp';
            if (!$wp_filesystem->mkdir($temp_dir)) {
                error_log('DD GitHub Updates: Failed to create temporary directory: ' . $temp_dir);
                return new WP_Error('mkdir_failed', __('Could not create temporary directory for restructuring', 'dd-wp-github-updates'));
            }

            // Create proper subdirectory using the slug
            $proper_dir = $temp_dir . '/' . $slug;
            if (!$wp_filesystem->mkdir($proper_dir)) {
                $wp_filesystem->rmdir($temp_dir, true);
                error_log('DD GitHub Updates: Failed to create proper directory: ' . $proper_dir);
                return new WP_Error('mkdir_failed', __('Could not create proper directory structure', 'dd-wp-github-updates'));
            }

            // Copy contents from the original directory to our properly named directory
            $this->copy_dir($source . '/' . $existing_dir, $proper_dir);

            // Remove the original source directory content
            $wp_filesystem->rmdir($source, true);

            // Move our restructured content to the original source location
            $wp_filesystem->mkdir($source);
            $this->copy_dir($temp_dir, $source);

            // Clean up
            $wp_filesystem->rmdir($temp_dir, true);

            error_log('DD GitHub Updates: Successfully renamed directory to ' . $slug);
            return $source;
        }

        // If not flat and no proper structure found, or if it is flat, restructure it
        error_log('DD GitHub Updates: ' . ($is_flat ? 'Detected flat repository structure' : 'No proper structure found') . '. Restructuring...');

        // Create a temporary working directory
        $temp_dir = $destination . '-restructure-temp';
        if (!$wp_filesystem->mkdir($temp_dir)) {
            error_log('DD GitHub Updates: Failed to create temporary directory: ' . $temp_dir);
            return new WP_Error('mkdir_failed', __('Could not create temporary directory for restructuring', 'dd-wp-github-updates'));
        }

        // Create proper subdirectory using the slug
        $proper_dir = $temp_dir . '/' . $slug;
        if (!$wp_filesystem->mkdir($proper_dir)) {
            $wp_filesystem->rmdir($temp_dir, true);
            error_log('DD GitHub Updates: Failed to create proper directory: ' . $proper_dir);
            return new WP_Error('mkdir_failed', __('Could not create proper directory structure', 'dd-wp-github-updates'));
        }

        // List of WordPress-specific files to always include
        $important_files = array(
            'style.css',          // Theme main file
            'functions.php',      // Theme functions
            'index.php',          // Required for security
            'screenshot.png',     // Theme screenshot
            'readme.txt',         // WordPress readme
        );

        // Copy all relevant files to the new structure
        $files = $wp_filesystem->dirlist($source, true, false);
        foreach ($files as $file => $file_data) {
            // Skip hidden directories and common VCS directories
            if (strpos($file, '.') === 0 || in_array($file, array('.git', '.github', '.gitlab', '.svn', '.hg', 'node_modules', 'vendor'))) {
                error_log('DD GitHub Updates: Skipping ' . $file);
                continue;
            }

            // Always include important WordPress files, skip common GitHub files unless they're important
            $should_include = !in_array($file, array('README.md', 'LICENSE', 'CHANGELOG.md', 'composer.json', 'package.json')) ||
                              in_array($file, $important_files);

            if ($should_include) {
                $source_file = $source . '/' . $file;
                $target_file = $proper_dir . '/' . $file;

                error_log('DD GitHub Updates: Copying ' . $file . ' to new structure');

                // Copy the file or directory
                if ($file_data['type'] === 'd') {
                    $wp_filesystem->mkdir($target_file);
                    $this->copy_dir($source_file, $target_file);
                } else {
                    $wp_filesystem->copy($source_file, $target_file);
                }
            } else {
                error_log('DD GitHub Updates: Skipping non-essential file: ' . $file);
            }
        }

        // Remove the original source directory content
        $wp_filesystem->rmdir($source, true);

        // Move our restructured content to the original source location
        $wp_filesystem->mkdir($source);
        $this->copy_dir($temp_dir, $source);

        // Clean up
        $wp_filesystem->rmdir($temp_dir, true);

        error_log('DD GitHub Updates: Successfully restructured repository for WordPress compatibility');

        // Verify the restructured directory exists
        if (!$wp_filesystem->exists($source . '/' . $slug)) {
            error_log('DD GitHub Updates: WARNING - Restructuring may have failed, cannot find: ' . $source . '/' . $slug);
        } else {
            error_log('DD GitHub Updates: Verified restructured directory exists: ' . $source . '/' . $slug);
        }

        return $source;
    }

    /**
     * Helper function to copy a directory recursively
     *
     * @param string $source Source directory.
     * @param string $destination Destination directory.
     * @return bool Success or failure.
     */
    private function copy_dir($source, $destination) {
        global $wp_filesystem;

        $files = $wp_filesystem->dirlist($source, true, true);

        foreach ($files as $file => $file_data) {
            $source_file = $source . '/' . $file;
            $target_file = $destination . '/' . $file;

            if ($file_data['type'] === 'd') {
                $wp_filesystem->mkdir($target_file);
                $this->copy_dir($source_file, $target_file);
            } else {
                $wp_filesystem->copy($source_file, $target_file);
            }
        }

        return true;
    }

    /**
     * Recursively copy a directory
     *
     * @param string $source Source directory
     * @param string $dest Destination directory
     */
    private function recursive_copy($source, $dest) {
        wp_mkdir_p($dest);
        $dir_handle = opendir($source);
        while ($file = readdir($dir_handle)) {
            if ($file != '.' && $file != '..') {
                $src = $source . '/' . $file;
                $dst = $dest . '/' . $file;
                if (is_dir($src)) {
                    $this->recursive_copy($src, $dst);
                } else {
                    copy($src, $dst);
                }
            }
        }
        closedir($dir_handle);
    }

    /**
     * Recursively delete a directory
     *
     * @param string $dir Directory to delete
     */
    private function recursive_rmdir($dir) {
        if (is_dir($dir)) {
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    if (is_dir($dir . '/' . $object)) {
                        $this->recursive_rmdir($dir . '/' . $object);
                    } else {
                        unlink($dir . '/' . $object);
                    }
                }
            }
            rmdir($dir);
        }
    }

    /**
     * Add a directory to a ZIP file
     *
     * @param ZipArchive $zip ZIP archive object
     * @param string $dir Directory to add
     * @param string $zip_dir Directory name in the ZIP file
     */
    private function add_dir_to_zip($zip, $dir, $zip_dir) {
        if (is_dir($dir)) {
            $zip->addEmptyDir($zip_dir);
            $objects = scandir($dir);
            foreach ($objects as $object) {
                if ($object != '.' && $object != '..') {
                    if (is_dir($dir . '/' . $object)) {
                        $this->add_dir_to_zip($zip, $dir . '/' . $object, $zip_dir . '/' . $object);
                    } else {
                        $zip->addFile($dir . '/' . $object, $zip_dir . '/' . $object);
                    }
                }
            }
        }
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
     * Filter to check and restructure flat GitHub repositories
     *
     * @param string $source        File source location.
     * @param string $remote_source Remote file source location.
     * @param WP_Upgrader $upgrader   WP_Upgrader instance.
     * @param array $args           Extra arguments.
     * @return string|WP_Error      Modified source or WP_Error.
     */
    public function maybe_restructure_github_package($source, $remote_source, $upgrader, $args = array()) {
        // Check if this is a valid source path
        if (!is_dir($source) || empty($source)) {
            error_log('DD GitHub Updates: Invalid source directory - ' . $source);
            return $source;
        }

        // Enhanced detection for GitHub sources
        $is_github_source = false;
        $source_dir_name = basename($source);

        // Check for common GitHub naming patterns
        if (preg_match('/-[0-9a-f]{7,}$/', $source_dir_name) ||
            strpos($source_dir_name, '-master') !== false ||
            strpos($source_dir_name, '-main') !== false ||
            strpos($source_dir_name, '-v') !== false ||
            preg_match('/^[a-zA-Z0-9_-]+\.[a-zA-Z0-9_-]+(-[a-zA-Z0-9_.-]+)?$/', $source_dir_name)) {
            $is_github_source = true;
            error_log('DD GitHub Updates: Detected GitHub source based on directory name pattern: ' . $source_dir_name);
        }

        // Process our plugin's updates or general GitHub pattern directories
        if ((isset($args['github_update']) && $args['github_update'] === true) || $is_github_source) {
            // Get type and slug from the args
            $type = isset($args['type']) ? $args['type'] : '';
            $slug = isset($args['slug']) ? $args['slug'] : '';

            // If no type provided, try to determine from upgrader
            if (empty($type)) {
                if (isset($args['type'])) {
                    $type = $args['type'];
                } elseif (isset($upgrader->skin->options['type'])) {
                    $type = $upgrader->skin->options['type'];
                } elseif (is_a($upgrader, 'Theme_Upgrader')) {
                    $type = 'theme';
                    error_log('DD GitHub Updates: Detected Theme_Upgrader');
                } elseif (is_a($upgrader, 'Plugin_Upgrader')) {
                    $type = 'plugin';
                    error_log('DD GitHub Updates: Detected Plugin_Upgrader');
                }
            }

            // Try to determine type by checking for key files
            if (empty($type)) {
                if (file_exists($source . '/style.css')) {
                    $type = 'theme';
                    error_log('DD GitHub Updates: Detected theme based on style.css file');
                } else {
                    // Check if any PHP files have plugin headers
                    $files = glob($source . '/*.php');
                    foreach ($files as $file) {
                        $file_data = get_file_data($file, array('Name' => 'Plugin Name'));
                        if (!empty($file_data['Name'])) {
                            $type = 'plugin';
                            error_log('DD GitHub Updates: Detected plugin based on plugin header in ' . basename($file));
                            break;
                        }
                    }
                }
            }

            // If no slug provided, try to extract from the source
            if (empty($slug)) {
                // Get the directory name from source as a fallback
                $slug = $source_dir_name;

                // Clean up the slug if it's from a GitHub release
                $slug = preg_replace('/-[0-9a-f]{7,}$/', '', $slug); // Remove commit hash if present
                $slug = preg_replace('/-v?\d+(\.\d+)*$/', '', $slug); // Remove version number if present
                $slug = preg_replace('/(-master|-main)$/', '', $slug); // Remove branch names

                // If there's a dot in the slug (owner.repo), take just the repo part
                if (strpos($slug, '.') !== false) {
                    $parts = explode('.', $slug);
                    $slug = end($parts);
                }

                // If there's still a dash, try to extract meaningful part
                if (strpos($slug, '-') !== false) {
                    // Try to get just the repo name without the owner
                    $parts = explode('-', $slug);
                    if (count($parts) >= 2) {
                        // Take the last part, which is likely the repo name
                        $potential_slug = end($parts);
                        // Only use it if it's not just a version/branch indicator
                        if (!in_array($potential_slug, array('master', 'main')) && !preg_match('/^v?\d+(\.\d+)*$/', $potential_slug)) {
                            $slug = $potential_slug;
                        } else {
                            // Otherwise use everything except owner/first part
                            array_shift($parts);
                            $slug = implode('-', $parts);
                        }
                    }
                }

                // Sanitize the slug
                $slug = sanitize_file_name($slug);
                error_log('DD GitHub Updates: Determined slug: ' . $slug);
            }

            // Log the detection
            error_log('DD GitHub Updates: Detected potential GitHub package, checking if restructuring is needed. Type: ' . $type . ', Slug: ' . $slug);

            // Restructure the package
            return $this->restructure_flat_repository($source, $remote_source, $slug, $type);
        }

        return $source;
    }
}
