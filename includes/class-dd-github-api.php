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
            }            // If no suitable assets, use the zipball_url
            if (!empty($release['zipball_url'])) {
                // For API URLs, ensure we're using the correct URL format
                $zipball_url = $release['zipball_url'];

                // If using the GitHub API, append .zip to the URL to ensure we get a proper ZIP archive
                if (strpos($zipball_url, 'api.github.com') !== false && strpos($zipball_url, '.zip') === false) {
                    // Convert API URL to direct GitHub URL
                    $zipball_url = str_replace(
                        'api.github.com/repos/',
                        'github.com/',
                        $zipball_url
                    );

                    // Ensure we have the correct path structure for a download
                    if (strpos($zipball_url, '/zipball/') !== false) {
                        $zipball_url = str_replace(
                            '/zipball/',
                            '/archive/refs/tags/',
                            $zipball_url
                        ) . '.zip';
                    }

                    error_log('Converted API zipball URL to direct GitHub URL: ' . $zipball_url);
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
    }    /**
     * Download and verify a file from GitHub
     *
     * @param string $url URL to download.
     * @return string|WP_Error Path to downloaded file or WP_Error on failure.
     */
    public function download_file($url) {
        // For GitHub API URLs, we need to properly handle authentication and follow redirects
        $is_github_api = strpos($url, 'api.github.com') !== false;
        $is_github_url = strpos($url, 'github.com') !== false;

        // Log what we're downloading (without exposing sensitive tokens)
        error_log('Downloading file from: ' . preg_replace('/([?&]access_token)=[^&]+/', '$1=REDACTED', $url));

        // Prepare headers with proper authentication
        $headers = array(
            'User-Agent' => 'WordPress/' . get_bloginfo('version') . '; ' . get_bloginfo('url'),
        );

        // Add authorization header if token is available
        if (!empty($this->access_token) && ($is_github_api || $is_github_url)) {
            $headers['Authorization'] = 'Bearer ' . $this->access_token;

            // For API URLs, also set the Accept header to ensure we get the right content
            if ($is_github_api) {
                $headers['Accept'] = 'application/octet-stream';
            }
        }

        // If it's a GitHub API URL, first make a request to get the redirect URL
        if ($is_github_api) {
            error_log('GitHub API URL detected, resolving download URL first');

            // Make a HEAD request to get the actual download location
            $head_response = wp_remote_head($url, array(
                'headers' => $headers,
                'timeout' => 30,
                'redirection' => 5, // Follow up to 5 redirects
            ));

            if (is_wp_error($head_response)) {
                error_log('Failed to resolve download URL: ' . $head_response->get_error_message());
                return $head_response;
            }

            $response_code = wp_remote_retrieve_response_code($head_response);

            // If we got a redirect, use the Location header
            if ($response_code >= 300 && $response_code < 400) {
                $redirect_url = wp_remote_retrieve_header($head_response, 'location');
                if (!empty($redirect_url)) {
                    error_log('Following redirect to: ' . $redirect_url);
                    $url = $redirect_url;

                    // If redirected to github.com (not API), we don't need the specific headers anymore
                    if (strpos($url, 'api.github.com') === false) {
                        if (isset($headers['Accept'])) {
                            unset($headers['Accept']);
                        }
                    }
                }
            } else if ($response_code !== 200) {
                error_log('GitHub API returned unexpected status code: ' . $response_code);
                return new WP_Error('github_api_error', 'GitHub API returned status code: ' . $response_code);
            }
        }

        // Create a temporary file
        $temp_filename = get_temp_dir() . uniqid('github_download_') . '.zip';

        // Use wp_remote_get with stream option to download the file directly
        $response = wp_remote_get($url, array(
            'timeout' => 300, // Longer timeout for larger files
            'stream' => true,
            'filename' => $temp_filename,
            'headers' => $headers,
            'redirection' => 5, // Follow up to 5 redirects
            'sslverify' => true,
        ));

        // Check if the request was successful
        if (is_wp_error($response)) {
            error_log('Download failed: ' . $response->get_error_message());

            // Retry the download with a different method if the first attempt failed
            error_log('Retrying download with alternative method...');

            // Create a context with the headers
            $context_options = array(
                'http' => array(
                    'method' => 'GET',
                    'header' => implode("\r\n", array_map(
                        function($k, $v) { return "$k: $v"; },
                        array_keys($headers),
                        array_values($headers)
                    )),
                    'timeout' => 300,
                    'follow_location' => true,
                    'max_redirects' => 5,
                ),
                'ssl' => array(
                    'verify_peer' => true,
                    'verify_peer_name' => true,
                )
            );

            $context = stream_context_create($context_options);

            // Try to download with PHP's file_get_contents
            $content = @file_get_contents($url, false, $context);
            if ($content === false) {
                error_log('Alternative download method also failed');
                return $response; // Return the original error
            }

            // Save the content to the temporary file
            if (file_put_contents($temp_filename, $content) === false) {
                error_log('Failed to save downloaded content to file');
                return new WP_Error('save_failed', 'Failed to save downloaded content to file');
            }
        } else {
            // Check response code
            $response_code = wp_remote_retrieve_response_code($response);
            if ($response_code !== 200) {
                error_log('Download failed with response code: ' . $response_code);
                return new WP_Error('download_failed', 'Failed to download file, server returned code ' . $response_code);
            }
        }

        // Verify the file exists and has content
        if (!file_exists($temp_filename) || filesize($temp_filename) < 100) {
            error_log('Downloaded file not found or too small: ' . $temp_filename . ' Size: ' . (file_exists($temp_filename) ? filesize($temp_filename) : 0));
            return new WP_Error('download_failed', 'Downloaded file not found or is invalid');
        }

        // Verify it's a valid ZIP file
        if (!$this->is_valid_zip($temp_filename)) {
            error_log('Downloaded file is not a valid ZIP archive');
            return new WP_Error('invalid_zip', 'Downloaded file is not a valid ZIP archive');
        }

        error_log('File downloaded successfully to: ' . $temp_filename . ' Size: ' . filesize($temp_filename) . ' bytes');
        return $temp_filename;
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
}
