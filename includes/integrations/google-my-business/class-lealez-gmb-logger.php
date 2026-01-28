<?php
/**
 * GMB Logger
 * 
 * Handles logging of GMB API calls and events
 *
 * @package Lealez
 * @subpackage Integrations/GMB
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Lealez_GMB_Logger
 */
class Lealez_GMB_Logger {

    /**
     * Max log entries to keep
     *
     * @var int
     */
    private static $max_log_entries = 50;

    /**
     * Log an event
     *
     * @param int    $business_id Business post ID
     * @param string $type        Log type: success, error, warning, info
     * @param string $message     Log message
     * @param array  $data        Additional data
     * @return void
     */
    public static function log( $business_id, $type, $message, $data = array() ) {
        $logs = self::get_logs( $business_id );

        // Add new log entry
        $log_entry = array(
            'timestamp' => time(),
            'type'      => $type,
            'message'   => $message,
            'data'      => $data,
        );

        array_unshift( $logs, $log_entry );

        // Keep only last N entries
        $logs = array_slice( $logs, 0, self::$max_log_entries );

        update_post_meta( $business_id, '_gmb_activity_log', $logs );
    }

    /**
     * Get logs for a business
     *
     * @param int $business_id Business post ID
     * @param int $limit       Number of entries to return
     * @return array
     */
    public static function get_logs( $business_id, $limit = 0 ) {
        $logs = get_post_meta( $business_id, '_gmb_activity_log', true );
        
        if ( ! is_array( $logs ) ) {
            $logs = array();
        }

        if ( $limit > 0 ) {
            return array_slice( $logs, 0, $limit );
        }

        return $logs;
    }

    /**
     * Clear all logs for a business
     *
     * @param int $business_id Business post ID
     * @return bool
     */
    public static function clear_logs( $business_id ) {
        return delete_post_meta( $business_id, '_gmb_activity_log' );
    }

    /**
     * Format log entry for display
     *
     * @param array $log_entry Log entry
     * @return string
     */
    public static function format_log_entry( $log_entry ) {
        $icons = array(
            'success' => '✓',
            'error'   => '✗',
            'warning' => '⚠',
            'info'    => 'ℹ',
        );

        $colors = array(
            'success' => '#46b450',
            'error'   => '#dc3232',
            'warning' => '#f0b322',
            'info'    => '#2271b1',
        );

        $type = $log_entry['type'] ?? 'info';
        $icon = $icons[ $type ] ?? 'ℹ';
        $color = $colors[ $type ] ?? '#666';
        $timestamp = $log_entry['timestamp'] ?? time();
        $message = $log_entry['message'] ?? '';

        $time_str = human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'lealez' );

        return sprintf(
            '<div style="margin-bottom: 8px; padding: 8px; background: #f9f9f9; border-left: 3px solid %s;">
                <span style="color: %s; font-weight: bold;">%s</span>
                <span style="font-size: 11px; color: #666; margin-left: 8px;">%s</span><br>
                <span style="font-size: 13px;">%s</span>
            </div>',
            esc_attr( $color ),
            esc_attr( $color ),
            esc_html( $icon ),
            esc_html( $time_str ),
            esc_html( $message )
        );
    }
}
