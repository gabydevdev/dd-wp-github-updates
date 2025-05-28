<?php
/**
 * Admin functionality
 *
 * @package DD_WP_GitHub_Updates
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Admin class
 */
class DD_GitHub_Updates_Admin {

    /**
     * Constructor
     */
    public function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_assets'));

        // AJAX handlers
        add_action('wp_ajax_dd_github_test_connection', array($this, 'ajax_test_connection'));
        add_action('wp_ajax_dd_github_add_repository', array($this, 'ajax_add_repository'));
        add_action('wp_ajax_dd_github_remove_repository', array($this, 'ajax_remove_repository'));
    }

    /**
     * Add admin menu
     */
    public function add_admin_menu() {
        // Main menu item
        add_menu_page(
            __('GitHub Updates', 'dd-wp-github-updates'),
            __('GitHub Updates', 'dd-wp-github-updates'),
            'manage_options',
            'dd-github-updates',
            array($this, 'render_admin_page'),
            'dashicons-update',
            81
        );

        // Settings submenu
        add_submenu_page(
            'dd-github-updates',
            __('Settings', 'dd-wp-github-updates'),
            __('Settings', 'dd-wp-github-updates'),
            'manage_options',
            'dd-github-updates',
            array($this, 'render_admin_page')
        );

        // Install from GitHub submenu
        add_submenu_page(
            'dd-github-updates',
            __('Install from GitHub', 'dd-wp-github-updates'),
            __('Install from GitHub', 'dd-wp-github-updates'),
            'install_plugins',
            'dd-github-install',
            array($this, 'render_install_page')
        );
    }

    /**
     * Register plugin settings
     */
    public function register_settings() {
        register_setting(
            'dd_github_updates_settings_group',
            'dd_github_updates_settings',
            array($this, 'sanitize_settings')
        );

        register_setting(
            'dd_github_updates_settings_group',
            'dd_github_updates_repositories',
            array($this, 'sanitize_repositories')
        );

        add_settings_section(
            'dd_github_updates_settings_section',
            __('GitHub API Settings', 'dd-wp-github-updates'),
            array($this, 'render_settings_section'),
            'dd-github-updates'
        );

        add_settings_field(
            'github_token',
            __('Personal Access Token', 'dd-wp-github-updates'),
            array($this, 'render_token_field'),
            'dd-github-updates',
            'dd_github_updates_settings_section'
        );
    }

    /**
     * Sanitize settings
     *
     * @param array $input Settings input.
     * @return array Sanitized settings.
     */
    public function sanitize_settings($input) {
        $sanitized = array();

        if (isset($input['github_token'])) {
            $sanitized['github_token'] = sanitize_text_field($input['github_token']);
        }

        return $sanitized;
    }

    /**
     * Sanitize repositories
     *
     * @param array $input Repositories input.
     * @return array Sanitized repositories.
     */
    public function sanitize_repositories($input) {
        $sanitized = array();

        if (is_array($input)) {
            foreach ($input as $key => $repo) {
                $sanitized[$key] = array();

                if (isset($repo['type'])) {
                    $sanitized[$key]['type'] = sanitize_text_field($repo['type']);
                }

                if (isset($repo['owner'])) {
                    $sanitized[$key]['owner'] = sanitize_text_field($repo['owner']);
                }

                if (isset($repo['name'])) {
                    $sanitized[$key]['name'] = sanitize_text_field($repo['name']);
                }

                if (isset($repo['slug'])) {
                    $sanitized[$key]['slug'] = sanitize_text_field($repo['slug']);
                }

                if (isset($repo['file'])) {
                    $sanitized[$key]['file'] = sanitize_text_field($repo['file']);
                }
            }
        }

        return $sanitized;
    }

    /**
     * Render settings section
     */
    public function render_settings_section() {
        echo '<p>' . esc_html__('Configure your GitHub access token and repositories for automatic updates.', 'dd-wp-github-updates') . '</p>';
        echo '<p>' . esc_html__('Required GitHub token permissions:', 'dd-wp-github-updates') . '</p>';
        echo '<ul class="github-permissions-list">';
        echo '<li><strong>repo</strong> - ' . esc_html__('Full control of private repositories (required)', 'dd-wp-github-updates') . '</li>';
        echo '<li><strong>read:packages</strong> - ' . esc_html__('(Optional) Required only if your themes/plugins are distributed as GitHub packages', 'dd-wp-github-updates') . '</li>';
        echo '</ul>';
    }

    /**
     * Render token field
     */
    public function render_token_field() {
        $options = get_option('dd_github_updates_settings');
        $token = isset($options['github_token']) ? $options['github_token'] : '';

        echo '<input type="password" id="github_token" name="dd_github_updates_settings[github_token]" value="' . esc_attr($token) . '" class="regular-text" autocomplete="new-password" />';
        echo '<p class="description">' . esc_html__('Enter your GitHub personal access token with "repo" scope for private repositories.', 'dd-wp-github-updates') . '</p>';
        echo '<p class="description"><a href="https://github.com/settings/tokens" target="_blank">' . esc_html__('Generate a token on GitHub', 'dd-wp-github-updates') . ' &rarr;</a></p>';
        echo '<button type="button" id="test_github_connection" class="button button-secondary">' . esc_html__('Test Connection', 'dd-wp-github-updates') . '</button>';
        echo '<span id="connection_status"></span>';
    }

    /**
     * Enqueue admin assets
     *
     * @param string $hook Current admin page.
     */
    public function enqueue_assets($hook) {
        if ('toplevel_page_dd-github-updates' !== $hook && 'github-updates_page_dd-github-install' !== $hook) {
            return;
        }

        wp_enqueue_style(
            'dd-github-updates-admin',
            DD_GITHUB_UPDATES_PLUGIN_URL . 'admin/css/admin.css',
            array(),
            DD_GITHUB_UPDATES_VERSION
        );

        wp_enqueue_script(
            'dd-github-updates-admin',
            DD_GITHUB_UPDATES_PLUGIN_URL . 'admin/js/admin.js',
            array('jquery'),
            DD_GITHUB_UPDATES_VERSION,
            true
        );

        wp_localize_script('dd-github-updates-admin', 'dd_github_updates', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('dd_github_updates_nonce'),
            'testing_connection' => __('Testing connection...', 'dd-wp-github-updates'),
            'connection_success' => __('Connection successful!', 'dd-wp-github-updates'),
            'connection_error' => __('Connection failed: ', 'dd-wp-github-updates'),
            'confirm_remove' => __('Are you sure you want to remove this repository?', 'dd-wp-github-updates'),
            'searching' => __('Searching...', 'dd-wp-github-updates'),
            'search_error' => __('Error searching repository: ', 'dd-wp-github-updates'),
            'installing' => __('Installing...', 'dd-wp-github-updates'),
            'install_error' => __('Installation error: ', 'dd-wp-github-updates'),
            'install_success' => __('Successfully installed!', 'dd-wp-github-updates'),
        ));
    }

    /**
     * Handle AJAX test connection
     */
    public function ajax_test_connection() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dd_github_updates_nonce')) {
            wp_send_json_error('Security check failed.');
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to perform this action.');
            return;
        }

        // Get token from POST or use saved one
        if (isset($_POST['token']) && !empty($_POST['token'])) {
            $token = sanitize_text_field($_POST['token']);

            // Temporarily update the token for testing
            $options = get_option('dd_github_updates_settings', array());
            $old_token = isset($options['github_token']) ? $options['github_token'] : '';
            $options['github_token'] = $token;
            update_option('dd_github_updates_settings', $options);

            // Create API instance with new token
            $api = new DD_GitHub_API();

            // Test connection
            $response = $api->test_connection();

            // Restore old token if not saving
            if (isset($_POST['save']) && !$_POST['save']) {
                $options['github_token'] = $old_token;
                update_option('dd_github_updates_settings', $options);
            }
        } else {
            // Use existing token
            $api = new DD_GitHub_API();
            $response = $api->test_connection();
        }

        if (is_wp_error($response)) {
            $error_message = $response->get_error_message();
            $error_data = $response->get_error_data();

            // Add more helpful information
            if (isset($error_data['code']) && ($error_data['code'] == 401 || $error_data['code'] == 403)) {
                $error_message .= ' Your token may be invalid or missing required permissions. Please make sure your token has the "repo" scope.';
            } elseif (isset($error_data['code']) && $error_data['code'] == 404) {
                $error_message .= ' The resource could not be found. Please check the repository owner and name.';
            }

            wp_send_json_error($error_message);
            return;
        }

        // Check if we have a valid user response
        if (!isset($response['login'])) {
            wp_send_json_error('Unexpected response from GitHub API');
            return;
        }

        // Additional information about token scopes
        $scopes = '';
        if (isset($response['scopes']) && is_array($response['scopes'])) {
            $scopes = ' Token scopes: ' . implode(', ', $response['scopes']);
        }

        wp_send_json_success('Connection successful! Authenticated as ' . $response['login'] . '.' . $scopes);
    }

    /**
     * Handle AJAX add repository
     */
    public function ajax_add_repository() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dd_github_updates_nonce')) {
            wp_send_json_error('Security check failed.');
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to perform this action.');
            return;
        }

        // Validate and sanitize input
        $type = isset($_POST['type']) ? sanitize_text_field($_POST['type']) : '';
        $owner = isset($_POST['owner']) ? sanitize_text_field($_POST['owner']) : '';
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $slug = isset($_POST['slug']) ? sanitize_text_field($_POST['slug']) : '';
        $file = isset($_POST['file']) ? sanitize_text_field($_POST['file']) : '';

        if (empty($type) || empty($owner) || empty($name)) {
            wp_send_json_error('Required fields are missing.');
            return;
        }

        if ($type === 'theme' && empty($slug)) {
            wp_send_json_error('Theme slug is required.');
            return;
        }

        if ($type === 'plugin' && empty($file)) {
            wp_send_json_error('Plugin file is required.');
            return;
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
        update_option('dd_github_updates_repositories', $repositories);

        wp_send_json_success();
    }

    /**
     * Handle AJAX remove repository
     */
    public function ajax_remove_repository() {
        // Verify nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'dd_github_updates_nonce')) {
            wp_send_json_error('Security check failed.');
            return;
        }

        // Check user capabilities
        if (!current_user_can('manage_options')) {
            wp_send_json_error('You do not have permission to perform this action.');
            return;
        }

        // Validate and sanitize input
        $index = isset($_POST['index']) ? intval($_POST['index']) : -1;

        if ($index < 0) {
            wp_send_json_error('Invalid repository index.');
            return;
        }

        // Get existing repositories
        $repositories = get_option('dd_github_updates_repositories', array());

        // Check if index exists
        if (!isset($repositories[$index])) {
            wp_send_json_error('Repository not found.');
            return;
        }

        // Remove repository
        unset($repositories[$index]);

        // Reindex array
        $repositories = array_values($repositories);

        // Update option
        update_option('dd_github_updates_repositories', $repositories);

        wp_send_json_success();
    }

    /**
     * Render admin page
     */
    public function render_admin_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }

        // Get repositories
        $repositories = get_option('dd_github_updates_repositories', array());

        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>

            <form method="post" action="options.php">
                <?php
                settings_fields('dd_github_updates_settings_group');
                do_settings_sections('dd-github-updates');
                submit_button();
                ?>
            </form>

            <div class="dd-github-repositories">
                <h2><?php esc_html_e('Managed Repositories', 'dd-wp-github-updates'); ?></h2>

                <table class="widefat" id="dd-github-repos-table">
                    <thead>
                        <tr>
                            <th><?php esc_html_e('Type', 'dd-wp-github-updates'); ?></th>
                            <th><?php esc_html_e('Repository', 'dd-wp-github-updates'); ?></th>
                            <th><?php esc_html_e('Slug/File', 'dd-wp-github-updates'); ?></th>
                            <th><?php esc_html_e('Actions', 'dd-wp-github-updates'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($repositories)) : ?>
                            <tr>
                                <td colspan="4"><?php esc_html_e('No repositories configured.', 'dd-wp-github-updates'); ?></td>
                            </tr>
                        <?php else : ?>
                            <?php foreach ($repositories as $index => $repo) : ?>
                                <tr>
                                    <td><?php echo esc_html(ucfirst($repo['type'])); ?></td>
                                    <td><?php echo esc_html($repo['owner'] . '/' . $repo['name']); ?></td>
                                    <td>
                                        <?php
                                        if ($repo['type'] === 'theme') {
                                            echo esc_html($repo['slug']);
                                        } else {
                                            echo esc_html($repo['file']);
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <button type="button" class="button button-small remove-repo" data-index="<?php echo esc_attr($index); ?>">
                                            <?php esc_html_e('Remove', 'dd-wp-github-updates'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <h3><?php esc_html_e('Add Repository', 'dd-wp-github-updates'); ?></h3>

                <div class="dd-github-add-repo">
                    <form id="dd-github-add-repo-form">
                        <table class="form-table">
                            <tr>
                                <th scope="row"><?php esc_html_e('Type', 'dd-wp-github-updates'); ?></th>
                                <td>
                                    <select id="repo_type" name="repo_type" required>
                                        <option value=""><?php esc_html_e('Select type', 'dd-wp-github-updates'); ?></option>
                                        <option value="theme"><?php esc_html_e('Theme', 'dd-wp-github-updates'); ?></option>
                                        <option value="plugin"><?php esc_html_e('Plugin', 'dd-wp-github-updates'); ?></option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Owner', 'dd-wp-github-updates'); ?></th>
                                <td>
                                    <input type="text" id="repo_owner" name="repo_owner" class="regular-text" required />
                                    <p class="description"><?php esc_html_e('GitHub username or organization name', 'dd-wp-github-updates'); ?></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><?php esc_html_e('Repository Name', 'dd-wp-github-updates'); ?></th>
                                <td>
                                    <input type="text" id="repo_name" name="repo_name" class="regular-text" required />
                                </td>
                            </tr>
                            <tr class="theme-fields" style="display: none;">
                                <th scope="row"><?php esc_html_e('Theme Slug', 'dd-wp-github-updates'); ?></th>
                                <td>
                                    <input type="text" id="theme_slug" name="theme_slug" class="regular-text" />
                                    <p class="description"><?php esc_html_e('The theme directory name', 'dd-wp-github-updates'); ?></p>
                                </td>
                            </tr>
                            <tr class="plugin-fields" style="display: none;">
                                <th scope="row"><?php esc_html_e('Plugin File', 'dd-wp-github-updates'); ?></th>
                                <td>
                                    <input type="text" id="plugin_file" name="plugin_file" class="regular-text" />
                                    <p class="description"><?php esc_html_e('The main plugin file (e.g., my-plugin/my-plugin.php)', 'dd-wp-github-updates'); ?></p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit">
                            <button type="submit" class="button button-primary"><?php esc_html_e('Add Repository', 'dd-wp-github-updates'); ?></button>
                        </p>
                    </form>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Render install page
     */
    public function render_install_page() {
        // Check user capabilities
        if (!current_user_can('install_plugins') && !current_user_can('install_themes')) {
            wp_die(__('You do not have sufficient permissions to access this page.', 'dd-wp-github-updates'));
        }

        // Get GitHub token status
        $options = get_option('dd_github_updates_settings', array());
        $has_token = !empty($options['github_token']);

        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Install from GitHub', 'dd-wp-github-updates'); ?></h1>

            <?php if (!$has_token): ?>
            <div class="notice notice-warning">
                <p><strong><?php esc_html_e('GitHub Token Missing', 'dd-wp-github-updates'); ?></strong></p>
                <p><?php esc_html_e('You have not configured a GitHub token. This is required for private repositories and helps avoid API rate limits.', 'dd-wp-github-updates'); ?></p>
                <p><a href="<?php echo esc_url(admin_url('admin.php?page=dd-github-updates')); ?>" class="button button-secondary"><?php esc_html_e('Configure GitHub Token', 'dd-wp-github-updates'); ?></a></p>
            </div>
            <?php endif; ?>

            <div class="notice notice-info">
                <p><?php esc_html_e('Enter the GitHub repository details to install a theme or plugin directly from GitHub.', 'dd-wp-github-updates'); ?></p>
                <p><?php esc_html_e('For private repositories, make sure you have added your GitHub token with "repo" permission in the Settings page.', 'dd-wp-github-updates'); ?></p>
            </div>

            <!-- Diagnostic Tool -->
            <div class="dd-github-diagnostic">
                <button type="button" id="check-github-connection" class="button button-secondary"><?php esc_html_e('Check GitHub Connection', 'dd-wp-github-updates'); ?></button>
                <span id="github-connection-status"></span>
            </div>

            <div class="dd-github-search-repo">
                <h2><?php esc_html_e('Repository Information', 'dd-wp-github-updates'); ?></h2>

                <form id="dd-github-search-form">
                    <table class="form-table">
                        <tr>
                            <th scope="row"><?php esc_html_e('Repository Owner', 'dd-wp-github-updates'); ?></th>
                            <td>
                                <input type="text" id="repo_owner" name="repo_owner" class="regular-text" required />
                                <p class="description"><?php esc_html_e('GitHub username or organization name', 'dd-wp-github-updates'); ?></p>
                            </td>
                        </tr>
                        <tr>
                            <th scope="row"><?php esc_html_e('Repository Name', 'dd-wp-github-updates'); ?></th>
                            <td>
                                <input type="text" id="repo_name" name="repo_name" class="regular-text" required />
                                <p class="description"><?php esc_html_e('The name of the repository', 'dd-wp-github-updates'); ?></p>
                            </td>
                        </tr>
                    </table>

                    <p class="submit">
                        <button type="submit" class="button button-primary"><?php esc_html_e('Search Repository', 'dd-wp-github-updates'); ?></button>
                    </p>
                </form>
            </div>

            <div id="dd-github-repo-details" class="dd-github-repo-details" style="display: none;">
                <h2><?php esc_html_e('Repository Details', 'dd-wp-github-updates'); ?></h2>

                <div class="dd-github-repo-info">
                    <div class="dd-github-repo-header">
                        <h3 id="repo-name"></h3>
                        <p id="repo-description"></p>
                    </div>

                    <div class="dd-github-repo-meta">
                        <p><strong><?php esc_html_e('Author:', 'dd-wp-github-updates'); ?></strong> <span id="repo-author"></span></p>
                        <p><strong><?php esc_html_e('Version:', 'dd-wp-github-updates'); ?></strong> <span id="repo-version"></span></p>
                        <p><strong><?php esc_html_e('Last Updated:', 'dd-wp-github-updates'); ?></strong> <span id="repo-updated"></span></p>
                        <p><strong><?php esc_html_e('License:', 'dd-wp-github-updates'); ?></strong> <span id="repo-license"></span></p>
                    </div>

                    <div class="dd-github-repo-actions">
                        <h4><?php esc_html_e('Installation Options', 'dd-wp-github-updates'); ?></h4>

                        <form id="dd-github-install-form">
                            <input type="hidden" id="install_owner" name="install_owner" />
                            <input type="hidden" id="install_name" name="install_name" />
                            <input type="hidden" id="install_download_url" name="install_download_url" />

                            <p>
                                <label><?php esc_html_e('Install As:', 'dd-wp-github-updates'); ?></label>
                                <select id="install_type" name="install_type" required>
                                    <option value=""><?php esc_html_e('-- Select Type --', 'dd-wp-github-updates'); ?></option>
                                    <?php if (current_user_can('install_themes')): ?>
                                    <option value="theme"><?php esc_html_e('Theme', 'dd-wp-github-updates'); ?></option>
                                    <?php endif; ?>
                                    <?php if (current_user_can('install_plugins')): ?>
                                    <option value="plugin"><?php esc_html_e('Plugin', 'dd-wp-github-updates'); ?></option>
                                    <?php endif; ?>
                                </select>
                            </p>

                            <div id="theme-options" class="type-options" style="display: none;">
                                <p>
                                    <label><?php esc_html_e('Theme Slug:', 'dd-wp-github-updates'); ?></label>
                                    <input type="text" id="theme_slug" name="theme_slug" class="regular-text" />
                                    <p class="description"><?php esc_html_e('Optional. If provided, the theme directory will be renamed to this.', 'dd-wp-github-updates'); ?></p>
                                </p>
                                <p>
                                    <label>
                                        <input type="checkbox" id="activate_theme" name="activate_theme" />
                                        <?php esc_html_e('Activate theme after installation', 'dd-wp-github-updates'); ?>
                                    </label>
                                </p>
                            </div>

                            <div id="plugin-options" class="type-options" style="display: none;">
                                <p>
                                    <label>
                                        <input type="checkbox" id="activate_plugin" name="activate_plugin" />
                                        <?php esc_html_e('Activate plugin after installation', 'dd-wp-github-updates'); ?>
                                    </label>
                                </p>
                            </div>

                            <p>
                                <label>
                                    <input type="checkbox" id="add_to_updater" name="add_to_updater" checked />
                                    <?php esc_html_e('Add to updater list to receive future updates', 'dd-wp-github-updates'); ?>
                                </label>
                            </p>

                            <p class="submit">
                                <button type="submit" class="button button-primary"><?php esc_html_e('Install Now', 'dd-wp-github-updates'); ?></button>
                                <span id="install-status"></span>
                            </p>
                        </form>
                    </div>

                    <div class="dd-github-repo-notes">
                        <h4><?php esc_html_e('Release Notes', 'dd-wp-github-updates'); ?></h4>
                        <div id="repo-notes"></div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }
}
