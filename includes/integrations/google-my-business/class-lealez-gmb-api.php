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
     * Verifications API base URL
     *
     * @var string
     */
    private static $verifications_api_base = 'https://mybusinessverifications.googleapis.com/v1';

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
    $business_id = absint( $business_id );

    // 1) Cooldown post-connect (nuevo)
    $cooldown_until = (int) get_post_meta( $business_id, '_gmb_post_connect_cooldown_until', true );
    if ( $cooldown_until && time() < $cooldown_until ) {
        return false;
    }

    // 2) Rate limit reciente (ya existe, lo respetamos)
    $last_rate_limit = (int) get_post_meta( $business_id, '_gmb_last_rate_limit', true );
    if ( $last_rate_limit && ( time() - $last_rate_limit ) < ( self::$min_refresh_interval * 60 ) ) {
        return false;
    }

    // 3) Intervalo mínimo entre refresh manuales
    $last_refresh = (int) get_post_meta( $business_id, '_gmb_last_manual_refresh', true );

    if ( ! $last_refresh ) {
        return true;
    }

    $time_since_refresh = time() - $last_refresh;
    $min_seconds = self::$min_refresh_interval * 60;

    return $time_since_refresh >= $min_seconds;
}


    /**
 * Get min refresh interval in minutes (public helper)
 *
 * @return int
 */
public static function get_min_refresh_interval_minutes() {
    return (int) self::$min_refresh_interval;
}

/**
 * Acquire a sync lock to prevent concurrent syncs (manual/cron)
 *
 * @param int $business_id
 * @param int $ttl_seconds
 * @return bool
 */
public static function acquire_sync_lock( $business_id, $ttl_seconds = 300 ) {
    $key = 'lealez_gmb_sync_lock_' . absint( $business_id );
    if ( get_transient( $key ) ) {
        return false;
    }
    set_transient( $key, time(), max( 60, (int) $ttl_seconds ) );
    return true;
}

/**
 * Release sync lock
 *
 * @param int $business_id
 * @return void
 */
public static function release_sync_lock( $business_id ) {
    $key = 'lealez_gmb_sync_lock_' . absint( $business_id );
    delete_transient( $key );
}

/**
 * Schedule a single automatic refresh (accounts + locations)
 *
 * @param int    $business_id
 * @param int    $delay_seconds
 * @param string $reason
 * @return int Scheduled timestamp
 */
public static function schedule_locations_refresh( $business_id, $delay_seconds, $reason = 'scheduled' ) {
    $business_id    = absint( $business_id );
    $delay_seconds  = max( 60, (int) $delay_seconds );
    $hook           = 'lealez_gmb_scheduled_refresh';
    $args           = array( $business_id );
    $timestamp      = time() + $delay_seconds;

    // Avoid duplicates: if a schedule exists, keep the earliest one
    $existing = wp_next_scheduled( $hook, $args );
    if ( $existing ) {
        // If existing is sooner than what we want, keep it
        if ( $existing <= $timestamp ) {
            update_post_meta( $business_id, '_gmb_next_scheduled_refresh', $existing );
            return (int) $existing;
        }

        // Existing is later; replace with earlier schedule
        wp_unschedule_event( $existing, $hook, $args );
    }

    wp_schedule_single_event( $timestamp, $hook, $args );

    update_post_meta( $business_id, '_gmb_next_scheduled_refresh', $timestamp );

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'info',
            'Scheduled automatic GMB refresh.',
            array(
                'reason'        => $reason,
                'scheduled_for' => $timestamp,
            )
        );
    }

    return (int) $timestamp;
}

/**
 * Cron runner for scheduled refresh
 *
 * @param int $business_id
 * @return void
 */
public static function run_scheduled_refresh( $business_id ) {
    $business_id = absint( $business_id );

    if ( ! $business_id ) {
        return;
    }

    // Must be connected
    $connected = get_post_meta( $business_id, '_gmb_connected', true );
    if ( ! $connected ) {
        return;
    }

    if ( ! self::acquire_sync_lock( $business_id, 600 ) ) {
        // Another sync running
        return;
    }

    try {
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'info', 'Scheduled refresh started (cron).' );
        }

        // Respect cooldown / rate limit windows
        if ( ! self::can_refresh_now( $business_id ) ) {
            $wait_minutes  = self::get_minutes_until_next_refresh( $business_id );
            $delay_seconds = max( 60, $wait_minutes * 60 );

            self::schedule_locations_refresh( $business_id, $delay_seconds, 'cron_rescheduled_due_to_cooldown' );

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'warning',
                    'Scheduled refresh rescheduled due to cooldown/rate-limit.',
                    array( 'wait_minutes' => $wait_minutes )
                );
            }
            return;
        }

        $locations = self::get_all_locations( $business_id, true );

        if ( is_wp_error( $locations ) ) {
            // If rate-limited, reschedule
            if ( in_array( $locations->get_error_code(), array( 'rate_limit_exceeded', 'rate_limit_active', 'local_rate_limit' ), true ) ) {
                $wait_minutes  = self::get_minutes_until_next_refresh( $business_id );
                $delay_seconds = max( 60, $wait_minutes * 60 );

                self::schedule_locations_refresh( $business_id, $delay_seconds, 'cron_rate_limited_reschedule' );

                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log(
                        $business_id,
                        'warning',
                        'Scheduled refresh hit rate limit; rescheduled.',
                        array(
                            'error'       => $locations->get_error_message(),
                            'waitMinutes' => $wait_minutes,
                        )
                    );
                }
                return;
            }

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'error',
                    'Scheduled refresh failed.',
                    array( 'error' => $locations->get_error_message() )
                );
            }
            return;
        }

        update_post_meta( $business_id, '_gmb_last_scheduled_refresh', time() );
        delete_post_meta( $business_id, '_gmb_post_connect_cooldown_until' );
        delete_post_meta( $business_id, '_gmb_next_scheduled_refresh' );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'success',
                sprintf( 'Scheduled refresh completed: %d locations retrieved', is_array( $locations ) ? count( $locations ) : 0 )
            );
        }

    } finally {
        self::release_sync_lock( $business_id );
    }
}


    /**
     * Get minutes until next refresh allowed
     *
     * @param int $business_id Business post ID
     * @return int
     */
public static function get_minutes_until_next_refresh( $business_id ) {
    $business_id = absint( $business_id );

    $now = time();
    $min_seconds = self::$min_refresh_interval * 60;

    $candidates = array();

    // Post-connect cooldown
    $cooldown_until = (int) get_post_meta( $business_id, '_gmb_post_connect_cooldown_until', true );
    if ( $cooldown_until && $cooldown_until > $now ) {
        $candidates[] = $cooldown_until - $now;
    }

    // Rate limit window
    $last_rate_limit = (int) get_post_meta( $business_id, '_gmb_last_rate_limit', true );
    if ( $last_rate_limit ) {
        $remain = $min_seconds - ( $now - $last_rate_limit );
        if ( $remain > 0 ) {
            $candidates[] = $remain;
        }
    }

    // Manual refresh window
    $last_refresh = (int) get_post_meta( $business_id, '_gmb_last_manual_refresh', true );
    if ( $last_refresh ) {
        $remain = $min_seconds - ( $now - $last_refresh );
        if ( $remain > 0 ) {
            $candidates[] = $remain;
        }
    }

    if ( empty( $candidates ) ) {
        return 0;
    }

    $remaining_seconds = max( $candidates );

    return (int) ceil( $remaining_seconds / 60 );
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

    // ✅ Local limiter (evita bursts desde el mismo WP)
    if ( class_exists( 'Lealez_GMB_Rate_Limiter' ) ) {
        $endpoint_key = $api_base . $endpoint;

        if ( ! Lealez_GMB_Rate_Limiter::can_make_request( $endpoint_key ) ) {
            $wait = Lealez_GMB_Rate_Limiter::get_wait_time( $endpoint_key );

            $msg = sprintf(
                __( 'Local rate limiter: too many requests. Please wait %d seconds and try again.', 'lealez' ),
                $wait
            );

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'warning', $msg );
            }

            return new WP_Error( 'local_rate_limit', $msg );
        }
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
 * Extract locationId for Verifications API from a GBP location resource name.
 *
 * Examples it supports:
 * - "locations/1234567890" -> "1234567890"
 * - "accounts/123/locations/456" -> "456"
 *
 * @param string $location_name
 * @return string
 */
private static function extract_location_id_from_name( $location_name ) {
    $location_name = (string) $location_name;
    $location_name = trim( $location_name );

    if ( '' === $location_name ) {
        return '';
    }

    // Normalize
    $location_name = trim( $location_name, '/' );

    // Common pattern: accounts/{acc}/locations/{id}
    if ( strpos( $location_name, '/locations/' ) !== false ) {
        $parts = explode( '/locations/', $location_name );
        $maybe = end( $parts );
        $maybe = trim( (string) $maybe, '/' );
        return $maybe;
    }

    // Pattern: locations/{id}
    if ( strpos( $location_name, 'locations/' ) === 0 ) {
        $maybe = substr( $location_name, strlen( 'locations/' ) );
        $maybe = trim( (string) $maybe, '/' );
        return $maybe;
    }

    // Fallback: last segment
    $chunks = explode( '/', $location_name );
    $last   = end( $chunks );
    return trim( (string) $last, '/' );
}

    /**
 * Get verification state for a location using My Business Verifications API.
 *
 * Uses caching by default to avoid excessive requests.
 * Returns array like: ['state' => 'VERIFIED', 'name' => '...', 'createTime' => '...']
 *
 * @param int    $business_id
 * @param string $location_id
 * @param bool   $use_cache
 * @return array
 */
private static function get_location_verification_state( $business_id, $location_id, $use_cache = true ) {
    $business_id  = absint( $business_id );
    $location_id  = (string) $location_id;
    $location_id  = trim( $location_id );

    if ( ! $business_id || '' === $location_id ) {
        return array();
    }

    // Endpoint: /locations/{locationId}/verifications
    $endpoint = '/locations/' . rawurlencode( $location_id ) . '/verifications';

    $result = self::make_request(
        $business_id,
        $endpoint,
        self::$verifications_api_base,
        'GET',
        array(),
        (bool) $use_cache,
        array()
    );

    if ( is_wp_error( $result ) ) {
        // No rompemos el flujo general: solo log y devolvemos vacío
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'warning',
                'Verifications API: could not retrieve verification state for location.',
                array(
                    'location_id' => $location_id,
                    'error'       => $result->get_error_message(),
                    'error_code'  => $result->get_error_code(),
                )
            );
        }
        return array();
    }

    $verifications = $result['verifications'] ?? array();
    if ( ! is_array( $verifications ) || empty( $verifications ) ) {
        return array();
    }

    // Tomar el "más reciente" si existe createTime, si no el primero
    $latest = null;

    foreach ( $verifications as $v ) {
        if ( ! is_array( $v ) ) {
            continue;
        }

        if ( null === $latest ) {
            $latest = $v;
            continue;
        }

        $t1 = isset( $latest['createTime'] ) ? strtotime( (string) $latest['createTime'] ) : 0;
        $t2 = isset( $v['createTime'] ) ? strtotime( (string) $v['createTime'] ) : 0;

        if ( $t2 > $t1 ) {
            $latest = $v;
        }
    }

    if ( ! is_array( $latest ) ) {
        return array();
    }

    $out = array();

    if ( ! empty( $latest['state'] ) ) {
        $out['state'] = (string) $latest['state'];
    }
    if ( ! empty( $latest['name'] ) ) {
        $out['name'] = (string) $latest['name'];
    }
    if ( ! empty( $latest['createTime'] ) ) {
        $out['createTime'] = (string) $latest['createTime'];
    }

    return $out;
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

    /**
     * ✅ Business Information API v1 (Location)
     * - NO usar "primaryCategory" top-level: debe ser "categories"
     * - Ampliamos readMask para poder mostrar:
     *   - verificación/estado (locationState)
     *   - IDs y links (metadata: placeId, mapsUri, newReviewUri, hasPendingEdits, etc.)
     *   - estado abierto/cerrado (openInfo)
     *   - storeCode, labels, languageCode, etc.
     */
    $read_mask = 'name,title,storeCode,storefrontAddress,phoneNumbers,websiteUri,regularHours,specialHours,moreHours,categories,latlng,openInfo,locationState,metadata,labels,languageCode,profile,serviceArea';

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

                // ✅ Derivar primaryCategory SIN romper compatibilidad con código viejo
                $categories       = $location['categories'] ?? array();
                $primary_category = array();

                if ( isset( $categories['primaryCategory'] ) && is_array( $categories['primaryCategory'] ) ) {
                    $primary_category = $categories['primaryCategory'];
                }

                // ✅ NUEVO: Consultar estado de verificación (Verifications API)
                // Importante: usamos cache SIEMPRE para no multiplicar requests aunque el refresh sea "force".
                $location_name = $location['name'] ?? '';
                $location_id   = self::extract_location_id_from_name( $location_name );

                $verification = array();
                if ( ! empty( $location_id ) ) {
                    $verification = self::get_location_verification_state( $business_id, $location_id, true );
                }

                $all_locations[] = array(
                    // ✅ Nuevo: para poder agrupar en UI
                    'account_name'       => $account_name,

                    // Campos básicos existentes
                    'name'               => $location['name'] ?? '',
                    'title'              => $location['title'] ?? '',
                    'storefrontAddress'  => $location['storefrontAddress'] ?? array(),
                    'phoneNumbers'       => $location['phoneNumbers'] ?? array(),
                    'websiteUri'         => $location['websiteUri'] ?? '',
                    'regularHours'       => $location['regularHours'] ?? array(),
                    'categories'         => $categories,
                    'primaryCategory'    => $primary_category,
                    'latlng'             => $location['latlng'] ?? array(),

                    // ✅ Nuevos campos ricos
                    'storeCode'          => $location['storeCode'] ?? '',
                    'specialHours'       => $location['specialHours'] ?? array(),
                    'moreHours'          => $location['moreHours'] ?? array(),
                    'openInfo'           => $location['openInfo'] ?? array(),
                    'locationState'      => $location['locationState'] ?? array(),
                    'metadata'           => $location['metadata'] ?? array(),
                    'labels'             => $location['labels'] ?? array(),
                    'languageCode'       => $location['languageCode'] ?? '',
                    'profile'            => $location['profile'] ?? array(),
                    'serviceArea'        => $location['serviceArea'] ?? array(),

                    // ✅ NUEVO: verificación desde My Business Verifications API
                    // Estructura pedida: ['state' => ...]
                    'verification'       => is_array( $verification ) ? $verification : array(),
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
    // 1) MUY IMPORTANTE:
    // Si hubo un rate limit anterior, queda guardado en _gmb_last_rate_limit y bloquea TODO por 60 min,
    // incluso aunque la reconexión OAuth haya sido exitosa.
    // Al conectar de nuevo, arrancamos limpio para permitir el sync inicial.
    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log( $business_id, 'info', 'bootstrap_after_connect: clearing stale cache/rate-limit flags before initial sync' );
    }
    self::clear_business_cache( $business_id );

    // 2) Fetch accounts (solo una vez)
    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log( $business_id, 'info', 'bootstrap_after_connect: fetching accounts (force_refresh=true)' );
    }

    $accounts = self::get_accounts( $business_id, true );
    if ( is_wp_error( $accounts ) ) {
        return $accounts;
    }

    // 3) Fetch locations sin volver a pedir accounts (evitamos duplicidad de llamadas)
    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log( $business_id, 'info', 'bootstrap_after_connect: fetching locations per account (force_refresh=true)' );
    }

    if ( empty( $accounts ) || ! is_array( $accounts ) ) {
        $error = new WP_Error( 'no_accounts', __( 'No GMB accounts found for this user', 'lealez' ) );
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'error', 'bootstrap_after_connect: No GMB accounts found' );
        }
        return $error;
    }

    $all_locations = array();
    $errors        = array();

    foreach ( $accounts as $index => $account ) {
        $account_name = $account['name'] ?? '';

        if ( empty( $account_name ) ) {
            continue;
        }

        // Delay conservador entre cuentas (excepto la primera)
        if ( $index > 0 ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'info',
                    sprintf( 'bootstrap_after_connect: waiting %d seconds before next account locations...', self::$delay_between_calls * 2 )
                );
            }
            sleep( self::$delay_between_calls * 2 );
        }

        $locations = self::get_locations( $business_id, $account_name, true );

        if ( is_wp_error( $locations ) ) {
            $errors[] = $locations->get_error_message();

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'error',
                    sprintf( 'bootstrap_after_connect: Failed to get locations for account %s: %s', $account_name, $locations->get_error_message() )
                );
            }

            // Si es rate limit, detenemos inmediatamente
            if ( in_array( $locations->get_error_code(), array( 'rate_limit_exceeded', 'rate_limit_active' ), true ) ) {
                break;
            }

            continue;
        }

        if ( is_array( $locations ) && ! empty( $locations ) ) {
            $all_locations = array_merge( $all_locations, $locations );
        }
    }

    // 4) Persistimos locations como en get_all_locations()
    if ( ! empty( $all_locations ) ) {
        update_post_meta( $business_id, '_gmb_locations_available', $all_locations );
        update_post_meta( $business_id, '_gmb_locations_last_fetch', time() );
        update_post_meta( $business_id, '_gmb_total_locations_available', count( $all_locations ) );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'success',
                sprintf( 'bootstrap_after_connect: Total of %d location(s) retrieved successfully', count( $all_locations ) )
            );
        }
    }

    if ( ! empty( $errors ) ) {
        update_post_meta( $business_id, '_gmb_last_sync_errors', $errors );
    }

    // 5) Si no hay ubicaciones, devolvemos error con detalle
    if ( empty( $all_locations ) ) {
        $error_msg = ! empty( $errors ) ? implode( '; ', $errors ) : __( 'No locations found in any account', 'lealez' );
        return new WP_Error( 'no_locations', $error_msg );
    }

    return array(
        'accounts'  => is_array( $accounts ) ? count( $accounts ) : 0,
        'locations' => is_array( $all_locations ) ? count( $all_locations ) : 0,
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
    $endpoint  = '/' . $location_name;

    /**
     * ✅ locations.get requiere readMask en Business Information API v1
     * Ampliamos para traer campos ricos (verificación, metadata IDs, openInfo, etc.)
     */
    $read_mask = 'name,title,storeCode,storefrontAddress,phoneNumbers,websiteUri,regularHours,specialHours,moreHours,categories,latlng,openInfo,locationState,metadata,labels,languageCode,profile,serviceArea';

    $query_args = array(
        'readMask' => $read_mask,
    );

    $result = self::make_request(
        $business_id,
        $endpoint,
        self::$business_api_base,
        'GET',
        array(),
        false,
        $query_args
    );

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
public static function clear_business_cache( $business_id, $preserve_rate_limit = false ) {
    $business_id = absint( $business_id );

    // Timestamps / flags
    delete_post_meta( $business_id, '_gmb_accounts_last_fetch' );
    delete_post_meta( $business_id, '_gmb_locations_last_fetch' );
    delete_post_meta( $business_id, '_gmb_last_manual_refresh' );

    if ( ! $preserve_rate_limit ) {
        delete_post_meta( $business_id, '_gmb_last_rate_limit' );
    }

    // New: post-connect cooldown
    delete_post_meta( $business_id, '_gmb_post_connect_cooldown_until' );
    delete_post_meta( $business_id, '_gmb_next_scheduled_refresh' );

    // Persistent cached data
    delete_post_meta( $business_id, '_gmb_accounts' );
    delete_post_meta( $business_id, '_gmb_total_accounts' );
    delete_post_meta( $business_id, '_gmb_account_name' );

    delete_post_meta( $business_id, '_gmb_locations_available' );
    delete_post_meta( $business_id, '_gmb_total_locations_available' );
    delete_post_meta( $business_id, '_gmb_last_sync_errors' );

    // Transient cache (compat)
    $cache_key = md5( $business_id . '/accounts' );
    if ( class_exists( 'Lealez_GMB_Rate_Limiter' ) ) {
        Lealez_GMB_Rate_Limiter::clear_cache( $cache_key );
    }
}


}

// WP-Cron hook for scheduled refresh
add_action( 'lealez_gmb_scheduled_refresh', array( 'Lealez_GMB_API', 'run_scheduled_refresh' ), 10, 1 );

