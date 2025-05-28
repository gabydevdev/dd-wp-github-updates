<?php
/**
 * GitHub Updater
 *
 * @package DD_WP_GitHub_Updates
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub Updater Class
 */
class DD_GitHub_Updater {

    /**
     * GitHub API instance
     *
     * @var DD_GitHub_API
     */
    private $api;

    /**
     * Repositories configuration
     *
     * @var array
     */
    private $repositories;

    /**
     * Constructor
     */
    public function __construct() {
        $this->api = new DD_GitHub_API();
        $this->load_repositories();
    }

    /**
     * Load repositories configuration from options
     */
    private function load_repositories() {
        $options = get_option('dd_github_updates_repositories', array());
        $this->repositories = is_array($options) ? $options : array();
    }

    /**
     * Check for theme updates
     *
     * @param object $transient Update transient object.
     * @return object Modified update transient object.
     */
    public function check_theme_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        foreach ($this->repositories as $repo) {
            if (empty($repo['type']) || $repo['type'] !== 'theme' || empty($repo['slug'])) {
                continue;
            }

            $theme_data = wp_get_theme($repo['slug']);
            if (!$theme_data->exists()) {
                continue;
            }

            $current_version = $theme_data->get('Version');
            $update_info = $this->get_update_info($repo, $current_version);

            if ($update_info && version_compare($current_version, $update_info['new_version'], '<')) {
                $transient->response[$repo['slug']] = array(
                    'theme'       => $repo['slug'],
                    'new_version' => $update_info['new_version'],
                    'url'         => $update_info['url'],
                    'package'     => $update_info['package'],
                );
            }
        }

        return $transient;
    }

    /**
     * Check for plugin updates
     *
     * @param object $transient Update transient object.
     * @return object Modified update transient object.
     */
    public function check_plugin_updates($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        foreach ($this->repositories as $repo) {
            if (empty($repo['type']) || $repo['type'] !== 'plugin' || empty($repo['file'])) {
                continue;
            }

            $plugin_file = $repo['file'];

            if (!file_exists(WP_PLUGIN_DIR . '/' . $plugin_file)) {
                continue;
            }

            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);
            $current_version = $plugin_data['Version'];

            $update_info = $this->get_update_info($repo, $current_version);

            if ($update_info && version_compare($current_version, $update_info['new_version'], '<')) {
                $transient->response[$plugin_file] = (object) array(
                    'id'          => $plugin_file,
                    'slug'        => dirname($plugin_file),
                    'plugin'      => $plugin_file,
                    'new_version' => $update_info['new_version'],
                    'url'         => $update_info['url'],
                    'package'     => $update_info['package'],
                );
            }
        }

        return $transient;
    }

    /**
     * Get update information from GitHub
     *
     * @param array  $repo Repository configuration.
     * @param string $current_version Current installed version.
     * @return array|false Update information or false if no update available.
     */
    private function get_update_info($repo, $current_version) {
        if (empty($repo['owner']) || empty($repo['name'])) {
            return false;
        }

        $release = $this->api->get_latest_release($repo['owner'], $repo['name']);

        if (is_wp_error($release) || empty($release['tag_name'])) {
            return false;
        }

        // Clean version number (remove 'v' prefix if exists)
        $version = preg_replace('/^v/', '', $release['tag_name']);

        // Check if there's a newer version
        if (version_compare($current_version, $version, '>=')) {
            return false;
        }

        // Find the asset to download (zip file)
        $download_url = '';

        if (!empty($release['zipball_url'])) {
            $download_url = $release['zipball_url'];
        } elseif (!empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (isset($asset['content_type']) && $asset['content_type'] === 'application/zip') {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        if (empty($download_url)) {
            return false;
        }

        return array(
            'new_version' => $version,
            'url'         => isset($release['html_url']) ? $release['html_url'] : '',
            'package'     => $download_url,
        );
    }

    /**
     * Filter the plugins API to provide custom data for our GitHub-hosted plugins
     *
     * @param false|object|array $result The result object or array.
     * @param string             $action The API action being performed.
     * @param object             $args   Plugin API arguments.
     * @return false|object The plugin info or false.
     */
    public function plugins_api_filter($result, $action, $args) {
        // Return early if not getting plugin information or if slug is not set
        if ($action !== 'plugin_information' || empty($args->slug)) {
            return $result;
        }

        // Find if we have a matching repository
        foreach ($this->repositories as $repo) {
            if (empty($repo['type']) || $repo['type'] !== 'plugin') {
                continue;
            }

            $plugin_slug = dirname($repo['file']);

            if ($args->slug === $plugin_slug) {
                return $this->get_plugin_info($repo);
            }
        }

        return $result;
    }

    /**
     * Filter the themes API to provide custom data for our GitHub-hosted themes
     *
     * @param false|object|array $result The result object or array.
     * @param string             $action The API action being performed.
     * @param object             $args   Theme API arguments.
     * @return false|object The theme info or false.
     */
    public function themes_api_filter($result, $action, $args) {
        // Return early if not getting theme information or if slug is not set
        if ($action !== 'theme_information' || empty($args->slug)) {
            return $result;
        }

        // Find if we have a matching repository
        foreach ($this->repositories as $repo) {
            if (empty($repo['type']) || $repo['type'] !== 'theme' || empty($repo['slug'])) {
                continue;
            }

            if ($args->slug === $repo['slug']) {
                return $this->get_theme_info($repo);
            }
        }

        return $result;
    }

    /**
     * Get plugin information from GitHub repository
     *
     * @param array $repo Repository configuration.
     * @return object|false Plugin information object or false on failure.
     */
    private function get_plugin_info($repo) {
        if (empty($repo['owner']) || empty($repo['name']) || empty($repo['file'])) {
            return false;
        }

        $plugin_file = $repo['file'];
        $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin_file);

        $release = $this->api->get_latest_release($repo['owner'], $repo['name']);

        if (is_wp_error($release)) {
            return false;
        }

        // Clean version number (remove 'v' prefix if exists)
        $version = preg_replace('/^v/', '', $release['tag_name']);

        // Find the asset to download (zip file)
        $download_url = '';

        if (!empty($release['zipball_url'])) {
            $download_url = $release['zipball_url'];
        } elseif (!empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (isset($asset['content_type']) && $asset['content_type'] === 'application/zip') {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $info = new stdClass();
        $info->name = $plugin_data['Name'];
        $info->slug = dirname($plugin_file);
        $info->version = $version;
        $info->author = $plugin_data['Author'];
        $info->author_profile = $plugin_data['AuthorURI'];
        $info->requires = $plugin_data['RequiresWP'];
        $info->tested = $plugin_data['TestedUpTo'];
        $info->requires_php = $plugin_data['RequiresPHP'];
        $info->homepage = $plugin_data['PluginURI'];
        $info->download_link = $download_url;
        $info->trunk = $download_link;
        $info->last_updated = isset($release['published_at']) ? date('Y-m-d', strtotime($release['published_at'])) : '';
        $info->sections = array(
            'description' => $plugin_data['Description'],
            'changelog' => isset($release['body']) ? $release['body'] : '',
        );

        return $info;
    }

    /**
     * Get theme information from GitHub repository
     *
     * @param array $repo Repository configuration.
     * @return object|false Theme information object or false on failure.
     */
    private function get_theme_info($repo) {
        if (empty($repo['owner']) || empty($repo['name']) || empty($repo['slug'])) {
            return false;
        }

        $theme_data = wp_get_theme($repo['slug']);

        if (!$theme_data->exists()) {
            return false;
        }

        $release = $this->api->get_latest_release($repo['owner'], $repo['name']);

        if (is_wp_error($release)) {
            return false;
        }

        // Clean version number (remove 'v' prefix if exists)
        $version = preg_replace('/^v/', '', $release['tag_name']);

        // Find the asset to download (zip file)
        $download_url = '';

        if (!empty($release['zipball_url'])) {
            $download_url = $release['zipball_url'];
        } elseif (!empty($release['assets'])) {
            foreach ($release['assets'] as $asset) {
                if (isset($asset['content_type']) && $asset['content_type'] === 'application/zip') {
                    $download_url = $asset['browser_download_url'];
                    break;
                }
            }
        }

        $info = new stdClass();
        $info->name = $theme_data->get('Name');
        $info->slug = $repo['slug'];
        $info->version = $version;
        $info->author = $theme_data->get('Author');
        $info->author_profile = $theme_data->get('AuthorURI');
        $info->requires = $theme_data->get('RequiresWP');
        $info->tested = $theme_data->get('TestedUpTo');
        $info->requires_php = $theme_data->get('RequiresPHP');
        $info->homepage = $theme_data->get('ThemeURI');
        $info->download_link = $download_url;
        $info->last_updated = isset($release['published_at']) ? date('Y-m-d', strtotime($release['published_at'])) : '';
        $info->sections = array(
            'description' => $theme_data->get('Description'),
            'changelog' => isset($release['body']) ? $release['body'] : '',
        );

        return $info;
    }
}
