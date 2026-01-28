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
     * Account Management API base URL
     *
     * @var string
     */
    private static $account_api_base = 'https://mybusinessaccountmanagement.googleapis.com/v1';

    /**
     * Business Information API base URL
     *
     * @var string
     */
    private static $business_api_base = 'https://mybusinessbusinessinformation.googleapis.com/v1';

    /**
     * Max retry attempts
     *
     * @var int
     */
    private static $max_retries = 3;

    /**
     * Minimum minutes between manual refreshes
     *
     * @var int
     */
    private static $min_refresh_interval = 15;

    /**
     * Check if we can refresh now
     *
     * @param int $business_id Business post ID
     * @return bool
     */
    public static function can_refresh_now( $business_id ) {
        $last_refresh = get_post_meta( $business_id, '_gmb_last_manual_refresh', true );
        
        if ( ! $last_refresh ) {
            return true;
        }

        $time_since_refresh = time() - $last_refresh;
        $min_seconds = self::$min_refresh_interval * 60;

        return $time_since_refresh >= $min_seconds;
    }

    /**
     * Get minutes until next refresh allowed
     *
     * @param int $business_id Business post ID
     * @return int
     */
    public static function get_minutes_until_next_refresh( $business_id ) {
        $last_refresh = get_post_meta( $business_id, '_gmb_last_manual_refresh', true );
        
        if ( ! $last_refresh ) {
            return 0;
        }

        $time_since_refresh = time() - $last_refresh;
        $min_seconds = self::$min_refresh_interval * 60;
        $remaining_seconds = $min_seconds - $time_since_refresh;

        if ( $remaining_seconds <= 0 ) {
            return 0;
        }

        return ceil( $remaining_seconds / 60 );
    }

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
    // Log request attempt
    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'info',
            sprintf( __( 'Making %s request to: %s', 'lealez' ), $method, $endpoint )
        );
    }

    // Generate cache key
    $cache_key = md5( $business_id . $endpoint . $method . serialize( $body ) );

    // Check cache for GET requests
    if ( 'GET' === $method && $use_cache ) {
        $cached = Lealez_GMB_Rate_Limiter::get_cached_response( $cache_key );
        if ( false !== $cached ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'success',
                    sprintf( __( 'Returning cached response for: %s', 'lealez' ), $endpoint )
                );
            }
            return $cached;
        }
    }

    // Check rate limit
    if ( ! Lealez_GMB_Rate_Limiter::can_make_request( $endpoint ) ) {
        $wait_time = Lealez_GMB_Rate_Limiter::get_wait_time( $endpoint );
        
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'error',
                sprintf( __( 'Rate limit exceeded. Wait %d seconds before next request.', 'lealez' ), $wait_time )
            );
        }
        
        return new WP_Error(
            'rate_limit_exceeded',
            sprintf(
                __( 'Google API rate limit exceeded. Please wait %d seconds before trying again.', 'lealez' ),
                $wait_time
            )
        );
    }

    // Check if token is expired and refresh if needed
    if ( Lealez_GMB_OAuth::is_token_expired( $business_id ) ) {
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                __( 'Access token expired, refreshing...', 'lealez' )
            );
        }
        
        $refresh_result = Lealez_GMB_OAuth::refresh_access_token( $business_id );
        
        if ( is_wp_error( $refresh_result ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'error',
                    sprintf( __( 'Failed to refresh token: %s', 'lealez' ), $refresh_result->get_error_message() )
                );
            }
            return $refresh_result;
        }
    }

    $tokens = Lealez_GMB_Encryption::get_tokens( $business_id );
    
    if ( ! $tokens ) {
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'error',
                __( 'No valid tokens found', 'lealez' )
            );
        }
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

    // Retry logic - MÁS CONSERVADOR para rate limits
    $attempt = 0;
    $max_retries = 2; // Reducido de 3 a 2
    $response = null;

    while ( $attempt < $max_retries ) {
        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $attempt++;
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'error',
                    sprintf( __( 'HTTP error (attempt %d/%d): %s', 'lealez' ), $attempt, $max_retries, $response->get_error_message() )
                );
            }
            
            if ( $attempt < $max_retries ) {
                Lealez_GMB_Rate_Limiter::wait_before_retry( $attempt );
                continue;
            }
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

        // Success
        if ( $response_code >= 200 && $response_code < 300 ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'success',
                    sprintf( __( 'Successfully fetched: %s', 'lealez' ), $endpoint )
                );
            }
            
            // Cache successful GET requests with appropriate duration
            if ( 'GET' === $method && $use_cache ) {
                // Cache duration based on endpoint
                $cache_duration = 3600; // Default 1 hour
                
                if ( strpos( $endpoint, '/accounts' ) !== false && strpos( $endpoint, '/locations' ) === false ) {
                    // Accounts endpoint: cache for 24 hours
                    $cache_duration = 86400;
                } elseif ( strpos( $endpoint, '/locations' ) !== false ) {
                    // Locations endpoint: cache for 12 hours
                    $cache_duration = 43200;
                }
                
                Lealez_GMB_Rate_Limiter::set_cached_response( $cache_key, $response_body, $cache_duration );
            }
            return $response_body;
        }

        // Rate limit error - NO REINTENTAR, devolver error inmediatamente
        if ( 429 === $response_code || ( isset( $response_body['error']['status'] ) && 'RESOURCE_EXHAUSTED' === $response_body['error']['status'] ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'error',
                    __( 'Google API rate limit exceeded', 'lealez' )
                );
            }
            
            return new WP_Error(
                'rate_limit_exceeded',
                __( 'Google API rate limit exceeded. Please wait at least 15 minutes before trying again.', 'lealez' )
            );
        }

        // Other errors
        $error_message = $response_body['error']['message'] ?? __( 'API request failed', 'lealez' );
        
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'error',
                sprintf( __( 'API error %d: %s', 'lealez' ), $response_code, $error_message )
            );
        }
        
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
    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'info',
            __( 'Getting GMB accounts...', 'lealez' )
        );
    }
    
    // Check cache first unless force refresh
    if ( ! $force_refresh ) {
        $cached_accounts = get_post_meta( $business_id, '_gmb_accounts', true );
        $last_fetch = get_post_meta( $business_id, '_gmb_accounts_last_fetch', true );
        
        // Use cache if less than 24 hours old
        if ( ! empty( $cached_accounts ) && $last_fetch && ( time() - $last_fetch ) < 86400 ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'success',
                    sprintf( __( 'Returning %d cached account(s)', 'lealez' ), count( $cached_accounts ) )
                );
            }
            return $cached_accounts;
        }
    }

    $result = self::make_request( $business_id, '/accounts', 'GET', array(), ! $force_refresh );

    if ( is_wp_error( $result ) ) {
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'error',
                sprintf( __( 'Failed to fetch accounts: %s', 'lealez' ), $result->get_error_message() )
            );
        }
        return $result;
    }

    $accounts = $result['accounts'] ?? array();
    
    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'success',
            sprintf( __( 'Successfully fetched %d account(s) from API', 'lealez' ), count( $accounts ) )
        );
    }
    
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
        Lealez_GMB_Logger::log( 
            $business_id, 
            'info', 
            sprintf( __( 'Getting locations for account: %s', 'lealez' ), $account_name )
        );

        // Use Business Information API
        $endpoint = '/' . $account_name . '/locations';
        $result = self::make_request( $business_id, $endpoint, 'business', 'GET', array(), ! $force_refresh );

        if ( is_wp_error( $result ) ) {
            Lealez_GMB_Logger::log( 
                $business_id, 
                'error', 
                sprintf( __( 'Failed to fetch locations for %s: %s', 'lealez' ), $account_name, $result->get_error_message() )
            );
            return $result;
        }

        $locations = $result['locations'] ?? array();
        
        Lealez_GMB_Logger::log( 
            $business_id, 
            'success', 
            sprintf( __( 'Retrieved %d location(s) from %s', 'lealez' ), count( $locations ), $account_name )
        );

        // Process locations
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
    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'info',
            __( 'Getting all GMB locations...', 'lealez' )
        );
    }
    
    // Check cache first unless force refresh
    if ( ! $force_refresh ) {
        $cached_locations = get_post_meta( $business_id, '_gmb_locations_available', true );
        $last_fetch = get_post_meta( $business_id, '_gmb_locations_last_fetch', true );
        
        // Use cache if less than 12 hours old
        if ( ! empty( $cached_locations ) && $last_fetch && ( time() - $last_fetch ) < 43200 ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'success',
                    sprintf( __( 'Returning %d cached location(s)', 'lealez' ), count( $cached_locations ) )
                );
            }
            return $cached_locations;
        }
    }

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

        // Add a delay between account requests (10 seconds para ser más conservadores)
        if ( ! empty( $all_locations ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'info',
                    __( 'Waiting 10 seconds before next account request...', 'lealez' )
                );
            }
            sleep( 10 );
        }

        $locations = self::get_locations( $business_id, $account_name, $force_refresh );
        
        if ( is_wp_error( $locations ) ) {
            $errors[] = $locations->get_error_message();
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'error',
                    sprintf( __( 'Failed to get locations for account %s: %s', 'lealez' ), $account_name, $locations->get_error_message() )
                );
            }
            continue;
        }

        $all_locations = array_merge( $all_locations, $locations );
    }

    // Store locations
    if ( ! empty( $all_locations ) ) {
        update_post_meta( $business_id, '_gmb_locations_available', $all_locations );
        update_post_meta( $business_id, '_gmb_locations_last_fetch', time() );
        update_post_meta( $business_id, '_gmb_total_locations_available', count( $all_locations ) );
        
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'success',
                sprintf( __( 'Successfully fetched %d total location(s)', 'lealez' ), count( $all_locations ) )
            );
        }
    }

    // If we have some locations but also some errors, log errors
    if ( ! empty( $all_locations ) && ! empty( $errors ) ) {
        update_post_meta( $business_id, '_gmb_last_sync_errors', $errors );
    }

    return $all_locations;
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
        delete_post_meta( $business_id, '_gmb_last_manual_refresh' );
        
        Lealez_GMB_Logger::log( $business_id, 'info', __( 'Cache cleared', 'lealez' ) );
    }
}
