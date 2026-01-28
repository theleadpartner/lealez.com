<?php
/**
 * GMB API Handler
 * 
 * Handles all API calls to Google My Business API
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
 * Class Lealez_GMB_API
 */
class Lealez_GMB_API {

    /**
     * API base URL
     *
     * @var string
     */
    private static $api_base = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    /**
     * Max retry attempts
     *
     * @var int
     */
    private static $max_retries = 3;

    /**
     * Make API request with rate limiting and retry logic
     *
     * @param int    $business_id Business post ID
     * @param string $endpoint    API endpoint
     * @param string $method      HTTP method
     * @param array  $body        Request body
     * @param bool   $use_cache   Whether to use cache
     * @return array|WP_Error
     */
    private static function make_request( $business_id, $endpoint, $method = 'GET', $body = array(), $use_cache = true ) {
        // Generate cache key
        $cache_key = md5( $business_id . $endpoint . $method . serialize( $body ) );

        // Check cache for GET requests
        if ( 'GET' === $method && $use_cache ) {
            $cached = Lealez_GMB_Rate_Limiter::get_cached_response( $cache_key );
            if ( false !== $cached ) {
                return $cached;
            }
        }

        // Check rate limit
        if ( ! Lealez_GMB_Rate_Limiter::can_make_request( $endpoint ) ) {
            $wait_time = Lealez_GMB_Rate_Limiter::get_wait_time( $endpoint );
            return new WP_Error(
                'rate_limit_exceeded',
                sprintf(
                    /* translators: %d: seconds to wait */
                    __( 'API rate limit exceeded. Please wait %d seconds before trying again.', 'lealez' ),
                    $wait_time
                )
            );
        }

        // Check if token is expired and refresh if needed
        if ( Lealez_GMB_OAuth::is_token_expired( $business_id ) ) {
            $refresh_result = Lealez_GMB_OAuth::refresh_access_token( $business_id );
            
            if ( is_wp_error( $refresh_result ) ) {
                return $refresh_result;
            }
        }

        $tokens = Lealez_GMB_Encryption::get_tokens( $business_id );
        
        if ( ! $tokens ) {
            return new WP_Error( 'no_tokens', __( 'No valid tokens found', 'lealez' ) );
        }

        $url = self::$api_base . $endpoint;
        $args = array(
            'method'  => $method,
            'timeout' => 30,
            'headers' => array(
                'Authorization' => 'Bearer ' . $tokens['access_token'],
                'Content-Type'  => 'application/json',
            ),
        );

        if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        // Retry logic
        $attempt = 0;
        $response = null;

        while ( $attempt < self::$max_retries ) {
            $response = wp_remote_request( $url, $args );

            if ( is_wp_error( $response ) ) {
                $attempt++;
                if ( $attempt < self::$max_retries ) {
                    Lealez_GMB_Rate_Limiter::wait_before_retry( $attempt );
                    continue;
                }
                return $response;
            }

            $response_code = wp_remote_retrieve_response_code( $response );
            $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

            // Success
            if ( $response_code >= 200 && $response_code < 300 ) {
                // Cache successful GET requests
                if ( 'GET' === $method && $use_cache ) {
                    Lealez_GMB_Rate_Limiter::set_cached_response( $cache_key, $response_body );
                }
                return $response_body;
            }

            // Rate limit error - wait and retry
            if ( 429 === $response_code || ( isset( $response_body['error']['status'] ) && 'RESOURCE_EXHAUSTED' === $response_body['error']['status'] ) ) {
                $attempt++;
                if ( $attempt < self::$max_retries ) {
                    Lealez_GMB_Rate_Limiter::wait_before_retry( $attempt );
                    continue;
                }
                
                return new WP_Error(
                    'rate_limit_exceeded',
                    __( 'Google API rate limit exceeded. The accounts will be fetched automatically in a few minutes. You can also try clicking "Refresh Locations" later.', 'lealez' )
                );
            }

            // Other errors
            $error_message = $response_body['error']['message'] ?? __( 'API request failed', 'lealez' );
            return new WP_Error( 'api_error', $error_message, array( 'code' => $response_code ) );
        }

        return new WP_Error( 'max_retries_exceeded', __( 'Maximum retry attempts exceeded', 'lealez' ) );
    }

    /**
     * Get accounts (with caching)
     *
     * @param int  $business_id Business post ID
     * @param bool $force_refresh Force refresh from API
     * @return array|WP_Error
     */
    public static function get_accounts( $business_id, $force_refresh = false ) {
        // Check cache first unless force refresh
        if ( ! $force_refresh ) {
            $cached_accounts = get_post_meta( $business_id, '_gmb_accounts', true );
            $last_fetch = get_post_meta( $business_id, '_gmb_accounts_last_fetch', true );
            
            // Use cache if less than 1 hour old
            if ( ! empty( $cached_accounts ) && $last_fetch && ( time() - $last_fetch ) < 3600 ) {
                return $cached_accounts;
            }
        }

        $result = self::make_request( $business_id, '/accounts', 'GET', array(), ! $force_refresh );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $accounts = $result['accounts'] ?? array();
        
        // Store accounts
        update_post_meta( $business_id, '_gmb_accounts', $accounts );
        update_post_meta( $business_id, '_gmb_accounts_last_fetch', time() );

        // Store account info if only one account
        if ( count( $accounts ) === 1 ) {
            $account = $accounts[0];
            update_post_meta( $business_id, '_gmb_account_name', $account['accountName'] ?? '' );
        }

        return $accounts;
    }

    /**
     * Get locations for an account (with caching)
     *
     * @param int    $business_id   Business post ID
     * @param string $account_name  Account resource name
     * @param bool   $force_refresh Force refresh from API
     * @return array|WP_Error
     */
    public static function get_locations( $business_id, $account_name, $force_refresh = false ) {
        $endpoint = '/' . $account_name . '/locations';
        $result = self::make_request( $business_id, $endpoint, 'GET', array(), ! $force_refresh );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $locations = $result['locations'] ?? array();
        
        // Process and store locations
        $processed_locations = array();
        foreach ( $locations as $location ) {
            $processed_locations[] = array(
                'name'              => $location['name'] ?? '',
                'title'             => $location['title'] ?? '',
                'storefrontAddress' => $location['storefrontAddress'] ?? array(),
                'phoneNumbers'      => $location['phoneNumbers'] ?? array(),
                'websiteUri'        => $location['websiteUri'] ?? '',
                'regularHours'      => $location['regularHours'] ?? array(),
                'primaryCategory'   => $location['primaryCategory'] ?? array(),
                'latlng'            => $location['latlng'] ?? array(),
            );
        }

        update_post_meta( $business_id, '_gmb_locations_available', $processed_locations );
        update_post_meta( $business_id, '_gmb_locations_last_fetch', time() );
        update_post_meta( $business_id, '_gmb_total_locations_available', count( $processed_locations ) );

        return $processed_locations;
    }

    /**
     * Get all locations for all accounts (with rate limiting)
     *
     * @param int  $business_id   Business post ID
     * @param bool $force_refresh Force refresh from API
     * @return array|WP_Error
     */
    public static function get_all_locations( $business_id, $force_refresh = false ) {
        $accounts = self::get_accounts( $business_id, $force_refresh );
        
        if ( is_wp_error( $accounts ) ) {
            return $accounts;
        }

        $all_locations = array();
        $errors = array();

        foreach ( $accounts as $account ) {
            $account_name = $account['name'] ?? '';
            
            if ( empty( $account_name ) ) {
                continue;
            }

            // Add a small delay between account requests
            if ( ! empty( $all_locations ) ) {
                sleep( 2 );
            }

            $locations = self::get_locations( $business_id, $account_name, $force_refresh );
            
            if ( is_wp_error( $locations ) ) {
                $errors[] = $locations->get_error_message();
                continue;
            }

            $all_locations = array_merge( $all_locations, $locations );
        }

        // If we have some locations but also some errors, return locations with a notice
        if ( ! empty( $all_locations ) && ! empty( $errors ) ) {
            update_post_meta( $business_id, '_gmb_last_sync_errors', $errors );
        }

        return $all_locations;
    }

    /**
     * Sync location data from GMB
     *
     * @param int    $business_id   Business post ID
     * @param string $location_name GMB location resource name
     * @return array|WP_Error
     */
    public static function sync_location_data( $business_id, $location_name ) {
        $endpoint = '/' . $location_name;
        $result = self::make_request( $business_id, $endpoint, 'GET', array(), false );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result;
    }

    /**
     * Clear cache for a business
     *
     * @param int $business_id Business post ID
     * @return void
     */
    public static function clear_business_cache( $business_id ) {
        delete_post_meta( $business_id, '_gmb_accounts_last_fetch' );
        delete_post_meta( $business_id, '_gmb_locations_last_fetch' );
        
        // Clear transient cache
        $cache_key = md5( $business_id . '/accounts' );
        Lealez_GMB_Rate_Limiter::clear_cache( $cache_key );
    }
}
