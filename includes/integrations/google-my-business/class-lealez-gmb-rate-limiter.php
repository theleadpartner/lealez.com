<?php
/**
 * GMB Rate Limiter
 * 
 * Implements rate limiting for Google My Business API calls
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
 * Class Lealez_GMB_Rate_Limiter
 */
class Lealez_GMB_Rate_Limiter {

    /**
     * Rate limit: max requests per minute
     *
     * @var int
     */
    private static $max_requests_per_minute = 15;

    /**
     * Cache duration in seconds (30 minutes default)
     *
     * @var int
     */
    private static $cache_duration = 1800;

    /**
     * Check if we can make a request
     *
     * @param string $endpoint Endpoint being called
     * @return bool
     */
    public static function can_make_request( $endpoint = 'default' ) {
        $key = 'lealez_gmb_rate_limit_' . md5( $endpoint );
        $requests = get_transient( $key );

        if ( false === $requests ) {
            $requests = array();
        }

        // Clean old requests (older than 1 minute)
        $current_time = time();
        $requests = array_filter( $requests, function( $timestamp ) use ( $current_time ) {
            return ( $current_time - $timestamp ) < 60;
        });

        // Check if we're under the limit
        if ( count( $requests ) >= self::$max_requests_per_minute ) {
            return false;
        }

        // Add current request
        $requests[] = $current_time;
        set_transient( $key, $requests, 120 ); // Store for 2 minutes

        return true;
    }

    /**
     * Get cached response
     *
     * @param string $cache_key Cache key
     * @return mixed|false
     */
    public static function get_cached_response( $cache_key ) {
        return get_transient( 'lealez_gmb_cache_' . $cache_key );
    }

    /**
     * Set cached response
     *
     * @param string $cache_key Cache key
     * @param mixed  $data      Data to cache
     * @param int    $duration  Cache duration in seconds
     * @return bool
     */
    public static function set_cached_response( $cache_key, $data, $duration = null ) {
        if ( null === $duration ) {
            $duration = self::$cache_duration;
        }
        return set_transient( 'lealez_gmb_cache_' . $cache_key, $data, $duration );
    }

    /**
     * Clear cache for a specific key
     *
     * @param string $cache_key Cache key
     * @return bool
     */
    public static function clear_cache( $cache_key ) {
        return delete_transient( 'lealez_gmb_cache_' . $cache_key );
    }

    /**
     * Clear all GMB cache
     *
     * @return void
     */
    public static function clear_all_cache() {
        global $wpdb;
        
        $wpdb->query(
            "DELETE FROM {$wpdb->options} 
             WHERE option_name LIKE '_transient_lealez_gmb_cache_%' 
             OR option_name LIKE '_transient_timeout_lealez_gmb_cache_%'"
        );
    }

    /**
     * Wait before making request (exponential backoff)
     *
     * @param int $attempt Attempt number
     * @return void
     */
    public static function wait_before_retry( $attempt ) {
        $wait_time = min( pow( 2, $attempt ), 32 ); // Max 32 seconds
        sleep( $wait_time );
    }

    /**
     * Get seconds until next request is allowed
     *
     * @param string $endpoint Endpoint being called
     * @return int
     */
    public static function get_wait_time( $endpoint = 'default' ) {
        $key = 'lealez_gmb_rate_limit_' . md5( $endpoint );
        $requests = get_transient( $key );

        if ( false === $requests || empty( $requests ) ) {
            return 0;
        }

        // Get oldest request timestamp
        $oldest_request = min( $requests );
        $time_since_oldest = time() - $oldest_request;

        // If oldest request is older than 60 seconds, we can make new requests
        if ( $time_since_oldest >= 60 ) {
            return 0;
        }

        // Calculate wait time
        return 60 - $time_since_oldest + 1;
    }
}
