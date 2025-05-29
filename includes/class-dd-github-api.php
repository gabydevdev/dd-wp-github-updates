<?php
/**
 * GitHub API Wrapper
 *
 * @package DD_WP_GitHub_Updates
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub API Wrapper Class
 */
class DD_GitHub_API {

    /**
     * GitHub API URL
     *
     * @var string
     */
    private $api_url = 'https://api.github.com';

    /**
     * GitHub personal access token
     *
     * @var string
     */
    private $access_token;

    /**
     * API request rate limiting information
     *
     * @var array
     */
    private $rate_limit_info = array();

    /**
     * Constructor
     */
    public function __construct() {
        $options = get_option('dd_github_updates_settings');
        $this->access_token = isset($options['github_token']) ? $options['github_token'] : '';
    }

    /**
     * Make an API request to GitHub
     *
     * @param string $url API endpoint URL.
     * @param array $args Additional arguments for the request.
     * @return array|WP_Error Response data or WP_Error on failure.
     */
    public function api_request($url, $args = array()) {
        $default_args = array(
            'headers' => array(
                'Accept'        => 'application/vnd.github.v3+json',
                'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            ),
            'timeout' => 10,
            'sslverify' => true, // Ensure SSL verification is enabled
        );

        // Add authentication if token is available
        if (!empty($this->access_token)) {
            $default_args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
        }

        $args = wp_parse_args($args, $default_args);

        // Log the request (without sensitive data)
        $log_url = preg_replace('/([?&]access_token)=[^&]+/', '$1=REDACTED', $url);
        error_log('GitHub API Request: ' . $log_url);

        // Make the request using WordPress HTTP API
        $response = wp_remote_get($url, $args);

        // Check for errors
        if (is_wp_error($response)) {
            error_log('GitHub API Error: ' . $response->get_error_message());
            return $response;
        }

        // Get response code
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $body = wp_remote_retrieve_body($response);

        // Log response code
        error_log('GitHub API Response Code: ' . $response_code);

        // Store rate limiting information from headers
        $this->rate_limit_info = array(
            'limit' => wp_remote_retrieve_header($response, 'X-RateLimit-Limit'),
            'remaining' => wp_remote_retrieve_header($response, 'X-RateLimit-Remaining'),
            'reset' => wp_remote_retrieve_header($response, 'X-RateLimit-Reset'),
        );

        if ($response_code !== 200) {
            $error_data = json_decode($body, true);
            $error_message = isset($error_data['message']) ? $error_data['message'] : $response_message;

            error_log('GitHub API Error: ' . $error_message . ' (HTTP ' . $response_code . ')');

            return new WP_Error(
                'github_api_error',
                sprintf('GitHub API error (HTTP %d): %s', $response_code, $error_message),
                array(
                    'response' => $response,
                    'code' => $response_code,
                    'url' => $url,
                    'body' => $body
                )
            );
        }

        // Parse JSON response
        $data = json_decode($body, true);

        if (is_null($data)) {
            error_log('GitHub API Error: Invalid JSON response');
            return new WP_Error(
                'github_api_error',
                'Invalid JSON response from GitHub API',
                array('url' => $url, 'body' => $body)
            );
        }

        return $data;
    }

    /**
     * Get rate limit information
     *
     * @return array Rate limit information
     */
    public function get_rate_limit_info() {
        return $this->rate_limit_info;
    }

    /**
     * Get repository releases
     *
     * @param string $owner Repository owner/organization.
     * @param string $repo Repository name.
     * @return array|WP_Error Array of releases or WP_Error on failure.
     */
    public function get_releases($owner, $repo) {
        $url = sprintf('%s/repos/%s/%s/releases', $this->api_url, $owner, $repo);

        return $this->api_request($url);
    }

    /**
     * Get latest release
     *
     * @param string $owner Repository owner/organization.
     * @param string $repo Repository name.
     * @return array|WP_Error Release data or WP_Error on failure.
     */
    public function get_latest_release($owner, $repo) {
        $url = sprintf('%s/repos/%s/%s/releases/latest', $this->api_url, $owner, $repo);

        $response = $this->api_request($url);

        // If no releases found, try to use the default branch
        if (is_wp_error($response) && $response->get_error_code() === 'github_api_error') {
            // Get repository info to find the default branch
            $repo_info = $this->get_repository($owner, $repo);

            if (!is_wp_error($repo_info) && isset($repo_info['default_branch'])) {
                // Create a synthetic release using the default branch
                return array(
                    'tag_name' => 'main',
                    'name' => 'Latest from ' . $repo_info['default_branch'],
                    'zipball_url' => sprintf('%s/repos/%s/%s/zipball/%s',
                                            $this->api_url,
                                            $owner,
                                            $repo,
                                            $repo_info['default_branch']),
                    'tarball_url' => sprintf('%s/repos/%s/%s/tarball/%s',
                                            $this->api_url,
                                            $owner,
                                            $repo,
                                            $repo_info['default_branch']),
                    'body' => 'Using latest code from default branch.',
                    'published_at' => $repo_info['updated_at'],
                    'assets' => array(),
                );
            }
        }

        return $response;
    }

    /**
     * Test connection to GitHub API
     *
     * @return array|WP_Error User data or WP_Error on failure.
     */
    public function test_connection() {
        return $this->api_request($this->api_url . '/user');
    }

    /**
     * Get repository information
     *
     * @param string $owner Repository owner/organization.
     * @param string $repo Repository name.
     * @return array|WP_Error Repository data or WP_Error on failure.
     */
    public function get_repository($owner, $repo) {
        $url = sprintf('%s/repos/%s/%s', $this->api_url, $owner, $repo);

        return $this->api_request($url);
    }

    /**
     * Search repositories
     *
     * @param string $query Search query.
     * @param int    $page Page number.
     * @param int    $per_page Results per page.
     * @return array|WP_Error Search results or WP_Error on failure.
     */
    public function search_repositories($query, $page = 1, $per_page = 10) {
        $url = sprintf('%s/search/repositories?q=%s&page=%d&per_page=%d',
                       $this->api_url,
                       urlencode($query),
                       $page,
                       $per_page);

        return $this->api_request($url);
    }

    /**
     * Get download URL for a repository
     *
     * @param string $owner Repository owner/organization.
     * @param string $repo Repository name.
     * @param string $version Version or branch to download (default: latest release).
     * @return string|WP_Error Download URL or WP_Error on failure.
     */
    public function get_download_url($owner, $repo, $version = null) {
        // If version is null, try to get the latest release
        if (is_null($version)) {
            $release = $this->get_latest_release($owner, $repo);

            if (is_wp_error($release)) {
                error_log('GitHub API Error: Failed to get latest release - ' . $release->get_error_message());

                // Fallback to default branch
                $repo_info = $this->get_repository($owner, $repo);

                if (is_wp_error($repo_info)) {
                    error_log('GitHub API Error: Failed to get repository info - ' . $repo_info->get_error_message());
                    return $repo_info;
                }

                $default_branch = isset($repo_info['default_branch']) ? $repo_info['default_branch'] : 'main';

                // Log the fallback
                error_log('GitHub API: Falling back to default branch: ' . $default_branch);

                // Direct download from default branch
                $download_url = sprintf('https://github.com/%s/%s/archive/refs/heads/%s.zip',
                    $owner,
                    $repo,
                    $default_branch
                );

                return $download_url;
            }

            // Check if there are any assets
            if (!empty($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    if (isset($asset['browser_download_url']) &&
                        (strpos($asset['name'], '.zip') !== false ||
                         isset($asset['content_type']) && $asset['content_type'] === 'application/zip')) {
                        return $asset['browser_download_url'];
                    }
                }
            }

            // If no suitable assets, use the zipball_url
            if (!empty($release['zipball_url'])) {
                // For API URLs, ensure we're using the correct URL format
                $zipball_url = $release['zipball_url'];

                // Check if we're using GitHub API URL
                if (strpos($zipball_url, 'api.github.com') !== false) {
                    // Convert to a proper direct download URL that works more reliably
                    if (!empty($release['tag_name'])) {
                        $download_url = sprintf('https://github.com/%s/%s/archive/refs/tags/%s.zip',
                            $owner,
                            $repo,
                            $release['tag_name']
                        );

                        error_log('Converted API zipball URL to direct GitHub URL: ' . $download_url);
                        return $download_url;
                    }
                }

                return $zipball_url;
            }

            // If all else fails, construct a direct download URL for the tag
            if (!empty($release['tag_name'])) {
                $download_url = sprintf('https://github.com/%s/%s/archive/refs/tags/%s.zip',
                    $owner,
                    $repo,
                    $release['tag_name']
                );

                return $download_url;
            }

            return new WP_Error(
                'github_api_error',
                'No download URL found in release information',
                $release
            );
        }

        // Determine if version is a tag or branch
        if (preg_match('/^v?\d+(\.\d+)*$/', $version)) {
            // Looks like a version tag
            $download_url = sprintf('https://github.com/%s/%s/archive/refs/tags/%s.zip',
                $owner,
                $repo,
                $version
            );
        } else {
            // Treat as a branch
            $download_url = sprintf('https://github.com/%s/%s/archive/refs/heads/%s.zip',
                $owner,
                $repo,
                $version
            );
        }

        return $download_url;
    }

    /**
     * Download and verify a file from GitHub
     *
     * @param string $url URL to download.
     * @return string|WP_Error Path to downloaded file or WP_Error on failure.
     */
    public function download_file($url) {
        // Debug the URL we're trying to download
        error_log('Attempting to download file from URL: ' . $url);

        // For GitHub URLs, we'll use WordPress download_url function
        // which is more reliable for handling larger files
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Download the file
        $temp_file = download_url($url);

        // Check if download was successful
        if (is_wp_error($temp_file)) {
            error_log('Download failed: ' . $temp_file->get_error_message());
            return $temp_file;
        }

        // Verify the file exists and has content
        if (!file_exists($temp_file) || filesize($temp_file) < 100) {
            error_log('Downloaded file not found or too small: ' . $temp_file . ' Size: ' . (file_exists($temp_file) ? filesize($temp_file) : 0));
            return new WP_Error('download_failed', 'Downloaded file not found or is invalid');
        }

        // Verify it's a valid ZIP file
        if (!$this->is_valid_zip($temp_file)) {
            error_log('Downloaded file is not a valid ZIP archive');
            return new WP_Error('invalid_zip', 'Downloaded file is not a valid ZIP archive');
        }

        error_log('File downloaded successfully to: ' . $temp_file . ' Size: ' . filesize($temp_file) . ' bytes');
        return $temp_file;
    }

    /**
     * Download and verify a file from GitHub with authentication
     *
     * @param string $url URL to download.
     * @return string|WP_Error Path to downloaded file or WP_Error on failure.
     */
    public function download_file_authenticated($url) {
        // Debug the URL we're trying to download
        error_log('Attempting to download file from URL with authentication: ' . $url);

        // Include required WordPress file handling functions
        require_once ABSPATH . 'wp-admin/includes/file.php';

        // Prepare download arguments with authentication
        $args = array(
            'headers' => array(
                'Accept'        => 'application/vnd.github.v3+json',
                'User-Agent'    => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
            ),
            'timeout' => 60, // Increase timeout for large files
            'sslverify' => true,
        );

        // Add authentication if token is available
        if (!empty($this->access_token)) {
            $args['headers']['Authorization'] = 'Bearer ' . $this->access_token;
            error_log('GitHub Download: Using authenticated request');
        } else {
            error_log('GitHub Download: No authentication token available');
        }

        // Use wp_remote_get for authenticated downloads
        $response = wp_remote_get($url, $args);

        // Check for errors
        if (is_wp_error($response)) {
            error_log('GitHub Download Error: ' . $response->get_error_message());
            return $response;
        }

        // Get response code and body
        $response_code = wp_remote_retrieve_response_code($response);
        $response_message = wp_remote_retrieve_response_message($response);
        $body = wp_remote_retrieve_body($response);

        error_log('GitHub Download Response Code: ' . $response_code);

        if ($response_code !== 200) {
            $error_message = $response_message;

            // Provide more specific error messages
            if ($response_code === 404) {
                $error_message = 'Repository or release not found. Check if the repository exists and is accessible.';
            } elseif ($response_code === 401 || $response_code === 403) {
                $error_message = 'Access denied. Your GitHub token may be invalid or missing required permissions.';
            }

            error_log('GitHub Download Error: ' . $error_message . ' (HTTP ' . $response_code . ')');

            return new WP_Error(
                'github_download_error',
                sprintf('GitHub download failed (HTTP %d): %s', $response_code, $error_message),
                array(
                    'response' => $response,
                    'code' => $response_code,
                    'url' => $url
                )
            );
        }

        // Check if we have content
        if (empty($body)) {
            error_log('GitHub Download Error: Empty response body');
            return new WP_Error('github_download_error', 'Empty response from GitHub');
        }

        // Create a temporary file
        $temp_file = wp_tempnam();
        if (!$temp_file) {
            error_log('GitHub Download Error: Could not create temporary file');
            return new WP_Error('temp_file_error', 'Could not create temporary file');
        }

        // Write the content to the temporary file
        $bytes_written = file_put_contents($temp_file, $body);
        if ($bytes_written === false) {
            @unlink($temp_file);
            error_log('GitHub Download Error: Could not write to temporary file');
            return new WP_Error('file_write_error', 'Could not write to temporary file');
        }

        error_log('GitHub Download: Successfully downloaded ' . $bytes_written . ' bytes to: ' . $temp_file);

        // Verify it's a valid ZIP file
        if (!$this->is_valid_zip($temp_file)) {
            @unlink($temp_file);
            error_log('GitHub Download Error: Downloaded file is not a valid ZIP archive');
            return new WP_Error('invalid_zip', 'Downloaded file is not a valid ZIP archive');
        }

        return $temp_file;
    }

    /**
     * Check if a file is a valid ZIP archive
     *
     * @param string $file Path to the file to check
     * @return bool True if the file is a valid ZIP archive, false otherwise
     */
    private function is_valid_zip($file) {
        // First check if the file exists and is readable
        if (!file_exists($file) || !is_readable($file)) {
            error_log('ZIP validation failed: File does not exist or is not readable');
            return false;
        }

        // Try to open with ZipArchive if available
        if (class_exists('ZipArchive')) {
            $zip = new ZipArchive();
            $result = $zip->open($file, ZipArchive::CHECKCONS);
            if ($result === TRUE) {
                // Log the contents of the ZIP file to help diagnose issues
                error_log('ZIP validation passed: Archive contains ' . $zip->numFiles . ' files');

                // List the first 5 files to help diagnose structure issues
                $file_count = min(5, $zip->numFiles);
                for ($i = 0; $i < $file_count; $i++) {
                    $file_info = $zip->statIndex($i);
                    if ($file_info) {
                        error_log('ZIP file ' . ($i + 1) . ': ' . $file_info['name']);
                    }
                }

                $zip->close();
                return true;
            } else {
                error_log('ZIP validation failed with ZipArchive error code: ' . $result);
                return false;
            }
        }

        // Fallback: Check file signature (first 4 bytes of a ZIP file should be PK\003\004)
        $handle = fopen($file, 'rb');
        if (!$handle) {
            error_log('ZIP validation failed: Could not open file for reading');
            return false;
        }

        $signature = fread($handle, 4);
        fclose($handle);

        $result = $signature === "PK\003\004";
        error_log('ZIP validation with signature check: ' . ($result ? 'Passed' : 'Failed') .
                 ' (Signature: ' . bin2hex($signature) . ', Expected: 504b0304)');

        return $result;
    }

    /**
     * Check if a GitHub URL likely requires authentication
     *
     * @param string $url The GitHub URL to check
     * @return bool True if authentication is likely required
     */
    public function url_requires_auth($url) {
        // If we have no token, we can't authenticate anyway
        if (empty($this->access_token)) {
            return false;
        }

        // GitHub URLs that typically require authentication:
        // - api.github.com URLs (zipball/tarball)
        // - Private repository archive URLs
        if (strpos($url, 'api.github.com') !== false) {
            return true;
        }

        // For direct GitHub archive URLs, we can't easily determine
        // if the repository is private without making a request
        // So we'll always use authentication if we have a token
        if (strpos($url, 'github.com') !== false && strpos($url, '/archive/') !== false) {
            return true;
        }

        return false;
    }
}
