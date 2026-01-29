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
     * Max retry attempts (solo para errores de red, NO para rate limits)
     *
     * @var int
     */
    private static $max_retries = 1;

    /**
     * Minimum minutes between manual refreshes (aumentado a 60 min)
     *
     * @var int
     */
    private static $min_refresh_interval = 60;

    /**
     * Delay between API calls (seconds) - aumentado a 5s
     *
     * @var int
     */
    private static $delay_between_calls = 5;

    /**
     * Cache duration for accounts (24 hours)
     *
     * @var int
     */
    private static $cache_duration_accounts = 86400;

    /**
     * Cache duration for locations (24 hours)
     *
     * @var int
     */
    private static $cache_duration_locations = 86400;

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
     * Make API request with conservative rate limiting
     *
     * @param int    $business_id Business post ID
     * @param string $endpoint    API endpoint (full path)
     * @param string $api_base    API base URL to use
     * @param string $method      HTTP method
     * @param array  $body        Request body
     * @param bool   $use_cache   Whether to use cache
     * @return array|WP_Error
     */
private static function make_request( $business_id, $endpoint, $api_base, $method = 'GET', $body = array(), $use_cache = true, $query_args = array() ) {
    // Log the request
    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        $qs_preview = ! empty( $query_args ) ? ' | query=' . wp_json_encode( $query_args ) : '';
        Lealez_GMB_Logger::log(
            $business_id,
            'info',
            sprintf( 'Making %s request to: %s%s%s', $method, $api_base, $endpoint, $qs_preview )
        );
    }

    // Build URL (supports query args)
    $url = $api_base . $endpoint;
    if ( ! empty( $query_args ) && is_array( $query_args ) ) {
        $url = add_query_arg( $query_args, $url );
    }

    // Generate cache key (must include query args)
    $cache_key = md5( $business_id . $api_base . $endpoint . $method . serialize( $body ) . serialize( $query_args ) );

    // Check cache for GET requests
    if ( 'GET' === $method && $use_cache ) {
        $cached = Lealez_GMB_Rate_Limiter::get_cached_response( $cache_key );
        if ( false !== $cached ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'success', 'Using cached response' );
            }
            return $cached;
        }
    }

    // Check if token is expired and refresh if needed
    if ( Lealez_GMB_OAuth::is_token_expired( $business_id ) ) {
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'info', 'Token expired, refreshing...' );
        }

        $refresh_result = Lealez_GMB_OAuth::refresh_access_token( $business_id );

        if ( is_wp_error( $refresh_result ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'error',
                    'Token refresh failed: ' . $refresh_result->get_error_message()
                );
            }
            return $refresh_result;
        }

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'success', 'Token refreshed successfully' );
        }
    }

    $tokens = Lealez_GMB_Encryption::get_tokens( $business_id );

    if ( ! $tokens ) {
        $error = new WP_Error( 'no_tokens', __( 'No valid tokens found', 'lealez' ) );
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'error', 'No valid tokens found' );
        }
        return $error;
    }

    $args = array(
        'method'  => $method,
        'timeout' => 45,
        'headers' => array(
            'Authorization' => 'Bearer ' . $tokens['access_token'],
            'Content-Type'  => 'application/json',
        ),
    );

    if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
        $args['body'] = wp_json_encode( $body );
    }

    // SOLO 1 intento inicial - NO reintentar en rate limits
    $attempt  = 0;
    $response = null;

    while ( $attempt < self::$max_retries ) {
        // Add delay before request (excepto primer intento)
        if ( $attempt > 0 ) {
            $wait_time = pow( 2, $attempt ) * self::$delay_between_calls;
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'info',
                    sprintf( 'Waiting %d seconds before retry %d/%d', $wait_time, $attempt + 1, self::$max_retries )
                );
            }
            sleep( $wait_time );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            $attempt++;
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'warning',
                    sprintf( 'Request failed (attempt %d/%d): %s', $attempt, self::$max_retries, $response->get_error_message() )
                );
            }

            if ( $attempt >= self::$max_retries ) {
                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log( $business_id, 'error', 'Max retries exceeded' );
                }
                return $response;
            }
            continue;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = wp_remote_retrieve_body( $response );
        $data          = json_decode( $response_body, true );

        // Log response preview
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                sprintf( 'Response HTTP %d: %s', $response_code, substr( $response_body, 0, 500 ) )
            );
        }

        // Success
        if ( $response_code >= 200 && $response_code < 300 ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'success',
                    sprintf( 'Request successful (HTTP %d)', $response_code )
                );
            }

            // Cache successful GET requests
            if ( 'GET' === $method && $use_cache && is_array( $data ) ) {
                $cache_duration = ( strpos( $endpoint, '/accounts' ) !== false && strpos( $endpoint, '/locations' ) === false )
                    ? self::$cache_duration_accounts
                    : self::$cache_duration_locations;

                Lealez_GMB_Rate_Limiter::set_cached_response( $cache_key, $data, $cache_duration );
            }

            return $data;
        }

        // Rate limit error - NO REINTENTAR
        if ( 429 === $response_code || ( isset( $data['error']['status'] ) && 'RESOURCE_EXHAUSTED' === $data['error']['status'] ) ) {
            $retry_after  = wp_remote_retrieve_header( $response, 'Retry-After' );
            $wait_minutes = self::$min_refresh_interval;

            if ( $retry_after ) {
                $wait_minutes = ceil( intval( $retry_after ) / 60 );
            }

            $error_msg = sprintf(
                __( 'Google API rate limit exceeded. Please wait at least %d minutes before trying again. Google recommends waiting longer periods between API calls.', 'lealez' ),
                $wait_minutes
            );

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'error', $error_msg );
            }

            update_post_meta( $business_id, '_gmb_last_rate_limit', time() );

            return new WP_Error( 'rate_limit_exceeded', $error_msg );
        }

        // Permission errors
        if ( 403 === $response_code ) {
            $error_details = $data['error']['message'] ?? '';

            $error_msg = sprintf(
                __( 'Permission denied (HTTP 403): %s. Please verify that the required APIs are enabled in Google Cloud Console.', 'lealez' ),
                $error_details
            );

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'error', $error_msg );
            }

            return new WP_Error( 'permission_denied', $error_msg );
        }

        // 5xx server errors: allow retry
        if ( $response_code >= 500 ) {
            $attempt++;
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'warning',
                    sprintf( 'Server error (HTTP %d), attempt %d/%d', $response_code, $attempt, self::$max_retries )
                );
            }

            if ( $attempt >= self::$max_retries ) {
                $error_message = $data['error']['message'] ?? __( 'Server error', 'lealez' );
                return new WP_Error( 'server_error', $error_message, array( 'code' => $response_code ) );
            }
            continue;
        }

        // Client errors (4xx): no retry
        $error_message = $data['error']['message'] ?? __( 'API request failed', 'lealez' );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'error',
                sprintf( 'API error (HTTP %d): %s', $response_code, $error_message )
            );
        }

        return new WP_Error( 'api_error', $error_message, array( 'code' => $response_code ) );
    }

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log( $business_id, 'error', 'Maximum retry attempts exceeded' );
    }

    return new WP_Error( 'max_retries_exceeded', __( 'Maximum retry attempts exceeded', 'lealez' ) );
}


    /**
     * Get accounts (with aggressive caching)
     *
     * @param int  $business_id Business post ID
     * @param bool $force_refresh Force refresh from API
     * @return array|WP_Error
     */
public static function get_accounts( $business_id, $force_refresh = false ) {
    // Check for recent rate limit
    $last_rate_limit = get_post_meta( $business_id, '_gmb_last_rate_limit', true );
    if ( $last_rate_limit && ( time() - $last_rate_limit ) < ( self::$min_refresh_interval * 60 ) ) {
        $wait_minutes = ceil( ( ( self::$min_refresh_interval * 60 ) - ( time() - $last_rate_limit ) ) / 60 );
        return new WP_Error(
            'rate_limit_active',
            sprintf( __( 'Rate limit active. Please wait %d minutes before making more requests.', 'lealez' ), $wait_minutes )
        );
    }

    // Check cache first unless force refresh
    if ( ! $force_refresh ) {
        $cached_accounts = get_post_meta( $business_id, '_gmb_accounts', true );
        $last_fetch      = get_post_meta( $business_id, '_gmb_accounts_last_fetch', true );

        if ( ! empty( $cached_accounts ) && $last_fetch && ( time() - $last_fetch ) < self::$cache_duration_accounts ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'info', 'Using cached accounts data' );
            }
            return $cached_accounts;
        }
    }

    $all_accounts = array();
    $page_token   = '';
    $loops        = 0;

    do {
        $loops++;
        if ( $loops > 20 ) {
            // Safety break
            break;
        }

        $query_args = array(
            'pageSize' => 100,
        );

        if ( ! empty( $page_token ) ) {
            $query_args['pageToken'] = $page_token;
        }

        $result = self::make_request(
            $business_id,
            '/accounts',
            self::$account_api_base,
            'GET',
            array(),
            ! $force_refresh,
            $query_args
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $accounts = $result['accounts'] ?? array();
        if ( ! empty( $accounts ) ) {
            $all_accounts = array_merge( $all_accounts, $accounts );
        }

        $page_token = $result['nextPageToken'] ?? '';
    } while ( ! empty( $page_token ) );

    // Store accounts in post_meta for persistent cache
    update_post_meta( $business_id, '_gmb_accounts', $all_accounts );
    update_post_meta( $business_id, '_gmb_accounts_last_fetch', time() );
    update_post_meta( $business_id, '_gmb_total_accounts', count( $all_accounts ) );

    // Store account info if only one account
    if ( count( $all_accounts ) === 1 ) {
        $account = $all_accounts[0];
        update_post_meta( $business_id, '_gmb_account_name', $account['accountName'] ?? '' );
    }

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'success',
            sprintf( 'Retrieved %d GMB account(s)', count( $all_accounts ) )
        );
    }

    return $all_accounts;
}


    /**
     * Get locations for an account (with aggressive caching)
     *
     * @param int    $business_id   Business post ID
     * @param string $account_name  Account resource name
     * @param bool   $force_refresh Force refresh from API
     * @return array|WP_Error
     */
public static function get_locations( $business_id, $account_name, $force_refresh = false ) {
    // Check for recent rate limit
    $last_rate_limit = get_post_meta( $business_id, '_gmb_last_rate_limit', true );
    if ( $last_rate_limit && ( time() - $last_rate_limit ) < ( self::$min_refresh_interval * 60 ) ) {
        $wait_minutes = ceil( ( ( self::$min_refresh_interval * 60 ) - ( time() - $last_rate_limit ) ) / 60 );
        return new WP_Error(
            'rate_limit_active',
            sprintf( __( 'Rate limit active. Please wait %d minutes before making more requests.', 'lealez' ), $wait_minutes )
        );
    }

    $endpoint = '/' . $account_name . '/locations';

    // readMask recomendado/normalmente requerido para Business Information API en list
    $read_mask = 'name,title,storefrontAddress,phoneNumbers,websiteUri,regularHours,primaryCategory,latlng';

    $all_locations = array();
    $page_token    = '';
    $loops         = 0;

    do {
        $loops++;
        if ( $loops > 50 ) {
            // Safety break
            break;
        }

        // Delay conservador entre llamadas (y entre páginas)
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                sprintf( 'Waiting %d seconds before fetching locations page...', self::$delay_between_calls )
            );
        }
        sleep( self::$delay_between_calls );

        $query_args = array(
            'readMask' => $read_mask,
            'pageSize' => 100,
        );

        if ( ! empty( $page_token ) ) {
            $query_args['pageToken'] = $page_token;
        }

        $result = self::make_request(
            $business_id,
            $endpoint,
            self::$business_api_base,
            'GET',
            array(),
            ! $force_refresh,
            $query_args
        );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $locations = $result['locations'] ?? array();
        if ( ! empty( $locations ) ) {
            foreach ( $locations as $location ) {
                $all_locations[] = array(
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
        }

        $page_token = $result['nextPageToken'] ?? '';
    } while ( ! empty( $page_token ) );

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'success',
            sprintf( 'Retrieved %d location(s) for account %s', count( $all_locations ), $account_name )
        );
    }

    return $all_locations;
}


    /**
     * Get all locations for all accounts (with conservative rate limiting)
     *
     * @param int  $business_id   Business post ID
     * @param bool $force_refresh Force refresh from API
     * @return array|WP_Error
     */
    public static function get_all_locations( $business_id, $force_refresh = false ) {
        // Check for recent rate limit
        $last_rate_limit = get_post_meta( $business_id, '_gmb_last_rate_limit', true );
        if ( $last_rate_limit && ( time() - $last_rate_limit ) < ( self::$min_refresh_interval * 60 ) ) {
            $wait_minutes = ceil( ( ( self::$min_refresh_interval * 60 ) - ( time() - $last_rate_limit ) ) / 60 );
            return new WP_Error(
                'rate_limit_active',
                sprintf( __( 'Rate limit active. Please wait %d minutes before making more requests.', 'lealez' ), $wait_minutes )
            );
        }

        // Check cache first unless force refresh
        if ( ! $force_refresh ) {
            $cached_locations = get_post_meta( $business_id, '_gmb_locations_available', true );
            $last_fetch = get_post_meta( $business_id, '_gmb_locations_last_fetch', true );
            
            // Use cache if less than 24 hours old
            if ( ! empty( $cached_locations ) && $last_fetch && ( time() - $last_fetch ) < self::$cache_duration_locations ) {
                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log( $business_id, 'info', 'Using cached locations data' );
                }
                return $cached_locations;
            }
        }

        $accounts = self::get_accounts( $business_id, $force_refresh );
        
        if ( is_wp_error( $accounts ) ) {
            return $accounts;
        }

        if ( empty( $accounts ) ) {
            $error = new WP_Error( 'no_accounts', __( 'No GMB accounts found for this user', 'lealez' ) );
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'error', 'No GMB accounts found' );
            }
            return $error;
        }

        $all_locations = array();
        $errors = array();

        foreach ( $accounts as $index => $account ) {
            $account_name = $account['name'] ?? '';
            
            if ( empty( $account_name ) ) {
                continue;
            }

            // CRITICAL: Add delay between accounts (excepto primero)
            if ( $index > 0 ) {
                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log( 
                        $business_id, 
                        'info', 
                        sprintf( 'Waiting %d seconds before fetching next account locations...', self::$delay_between_calls * 2 )
                    );
                }
                sleep( self::$delay_between_calls * 2 ); // 10 segundos entre cuentas
            }

            $locations = self::get_locations( $business_id, $account_name, $force_refresh );
            
            if ( is_wp_error( $locations ) ) {
                $errors[] = $locations->get_error_message();
                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log( 
                        $business_id, 
                        'error', 
                        sprintf( 'Failed to get locations for account %s: %s', $account_name, $locations->get_error_message() )
                    );
                }
                
                // Si es rate limit, detener inmediatamente
                if ( $locations->get_error_code() === 'rate_limit_exceeded' || $locations->get_error_code() === 'rate_limit_active' ) {
                    break;
                }
                
                continue;
            }

            $all_locations = array_merge( $all_locations, $locations );
        }

        // Store locations in post_meta for persistent cache
        if ( ! empty( $all_locations ) ) {
            update_post_meta( $business_id, '_gmb_locations_available', $all_locations );
            update_post_meta( $business_id, '_gmb_locations_last_fetch', time() );
            update_post_meta( $business_id, '_gmb_total_locations_available', count( $all_locations ) );
            
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( 
                    $business_id, 
                    'success', 
                    sprintf( 'Total of %d location(s) retrieved successfully', count( $all_locations ) )
                );
            }
        }

        // If we have some locations but also some errors, log errors
        if ( ! empty( $all_locations ) && ! empty( $errors ) ) {
            update_post_meta( $business_id, '_gmb_last_sync_errors', $errors );
        }

        // If we have NO locations at all, return error
        if ( empty( $all_locations ) ) {
            $error_msg = ! empty( $errors ) ? implode( '; ', $errors ) : __( 'No locations found in any account', 'lealez' );
            return new WP_Error( 'no_locations', $error_msg );
        }

        return $all_locations;
    }


    public static function bootstrap_after_connect( $business_id ) {
    // Intento de "sync inicial" sin marcar _gmb_last_manual_refresh
    // (así NO bloqueas el botón manual por 60 min)
    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log( $business_id, 'info', 'bootstrap_after_connect: fetching accounts (force_refresh=true)' );
    }

    $accounts = self::get_accounts( $business_id, true );
    if ( is_wp_error( $accounts ) ) {
        return $accounts;
    }

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log( $business_id, 'info', 'bootstrap_after_connect: fetching locations (force_refresh=true)' );
    }

    $locations = self::get_all_locations( $business_id, true );
    if ( is_wp_error( $locations ) ) {
        return $locations;
    }

    return array(
        'accounts'  => is_array( $accounts ) ? count( $accounts ) : 0,
        'locations' => is_array( $locations ) ? count( $locations ) : 0,
    );
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
        $result = self::make_request( $business_id, $endpoint, self::$business_api_base, 'GET', array(), false );

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
        delete_post_meta( $business_id, '_gmb_last_manual_refresh' );
        delete_post_meta( $business_id, '_gmb_last_rate_limit' );
        
        // Clear transient cache
        $cache_key = md5( $business_id . '/accounts' );
        Lealez_GMB_Rate_Limiter::clear_cache( $cache_key );
    }
}
