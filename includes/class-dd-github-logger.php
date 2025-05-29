<?php
/**
 * GitHub Logger
 *
 * @package DD_WP_GitHub_Updates
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

/**
 * GitHub Logger Class
 */
class DD_GitHub_Logger {

    /**
     * Log levels
     */
    const ERROR = 'error';
    const WARNING = 'warning';
    const INFO = 'info';
    const DEBUG = 'debug';

    /**
     * Log a message
     *
     * @param string $message Message to log
     * @param string $level Log level
     * @param array $context Additional context
     */
    public static function log($message, $level = self::INFO, $context = array()) {
        if (!self::should_log($level)) {
            return;
        }

        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'level' => $level,
            'message' => $message,
            'context' => $context
        );

        // Get existing logs
        $logs = get_option('dd_github_updates_logs', array());

        // Add new log entry
        array_unshift($logs, $log_entry);

        // Keep only the last 100 entries
        $logs = array_slice($logs, 0, 100);

        // Update logs
        update_option('dd_github_updates_logs', $logs);

        // Also send to error log if it's an error
        if ($level === self::ERROR) {
            error_log('DD GitHub Updates Error: ' . $message);
        }
    }

    /**
     * Check if we should log this level
     *
     * @param string $level Log level
     * @return bool Whether to log
     */
    private static function should_log($level) {
        $options = get_option('dd_github_updates_settings', array());
        $log_level = isset($options['log_level']) ? $options['log_level'] : self::ERROR;

        $levels = array(
            self::DEBUG => 0,
            self::INFO => 1,
            self::WARNING => 2,
            self::ERROR => 3
        );

        return isset($levels[$level]) && isset($levels[$log_level]) &&
               $levels[$level] >= $levels[$log_level];
    }

    /**
     * Get logs
     *
     * @param int $limit Number of logs to retrieve
     * @return array Logs
     */
    public static function get_logs($limit = 20) {
        $logs = get_option('dd_github_updates_logs', array());
        return array_slice($logs, 0, $limit);
    }

    /**
     * Clear logs
     */
    public static function clear_logs() {
        update_option('dd_github_updates_logs', array());
    }
}
