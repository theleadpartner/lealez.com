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
 * My Business API v4 base URL (Media endpoint)
 *
 * @var string
 */
private static $mybusiness_v4_base = 'https://mybusiness.googleapis.com/v4';

    /**
     * My Business Place Actions API base URL
     * Gestiona PlaceActionLinks: booking (APPOINTMENT), menu (MENU), order (FOOD_ORDERING), etc.
     * Doc: https://developers.google.com/my-business/reference/placeactions/rpc/google.mybusiness.placeactions.v1
     *
     * @var string
     */
    private static $place_actions_api_base = 'https://mybusinessplaceactions.googleapis.com/v1';


    /**
 * Business Profile Performance API base URL
 * @var string
 */
private static $performance_api_base = 'https://businessprofileperformance.googleapis.com/v1';

    
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
    $business_id  = absint( $business_id );
    $ttl_seconds  = max( 60, (int) $ttl_seconds );
    $key          = 'lealez_gmb_sync_lock_' . $business_id;
    $now          = time();

    $existing = get_transient( $key );

    if ( $existing ) {
        // Soporta formatos viejos (int) y nuevos (array)
        $lock_ts = 0;
        if ( is_array( $existing ) && isset( $existing['ts'] ) ) {
            $lock_ts = (int) $existing['ts'];
        } elseif ( is_numeric( $existing ) ) {
            $lock_ts = (int) $existing;
        }

        // “Actividad” del sync: si no hay actividad reciente, el lock está pegado
        $last_activity = (int) get_post_meta( $business_id, '_gmb_sync_last_activity', true );

        // Si no existe last_activity, usamos lock_ts como referencia
        $activity_ts = $last_activity ? $last_activity : $lock_ts;

        /**
         * Regla anti-lock pegado:
         * - Si el lock existe pero NO hay actividad hace X segundos, lo liberamos.
         * - X lo mantenemos conservador (60s) para no permitir dobles sync simultáneos.
         */
        $stale_after_seconds = 60;

        if ( $activity_ts && ( $now - $activity_ts ) > $stale_after_seconds ) {
            delete_transient( $key );
        } else {
            return false;
        }
    }

    // Inicia sync “oficialmente”
    update_post_meta( $business_id, '_gmb_sync_started_at', $now );
    update_post_meta( $business_id, '_gmb_sync_last_activity', $now );

    // Guardamos lock con timestamp en el transient
    set_transient(
        $key,
        array(
            'ts' => $now,
        ),
        $ttl_seconds
    );

    return true;
}


/**
 * Release sync lock
 *
 * @param int $business_id
 * @return void
 */
public static function release_sync_lock( $business_id ) {
    $business_id = absint( $business_id );
    $key         = 'lealez_gmb_sync_lock_' . $business_id;

    delete_transient( $key );

    // Limpieza de flags de sync (para no dejar estados colgados)
    delete_post_meta( $business_id, '_gmb_sync_started_at' );

    // Guardamos última actividad como cierre (no lo borramos)
    update_post_meta( $business_id, '_gmb_sync_last_activity', time() );
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
 * Detect if a WP_Error returned by make_request() is caused by an invalid field mask / readMask.
 *
 * Google suele responder 400 INVALID_ARGUMENT con details.fieldViolations.field="read_mask"
 * pero el message puede venir genérico ("Request contains an invalid argument.").
 *
 * @param WP_Error $err
 * @return bool
 */
private static function is_field_mask_error( $err ) {
    if ( ! is_wp_error( $err ) ) {
        return false;
    }

    $msg = strtolower( (string) $err->get_error_message() );

    // Fast path: message mentions mask
    if (
        false !== strpos( $msg, 'field mask' ) ||
        false !== strpos( $msg, 'read_mask' ) ||
        false !== strpos( $msg, 'readmask' ) ||
        false !== strpos( $msg, 'invalid field mask' )
    ) {
        return true;
    }

    $data = $err->get_error_data();

    // If raw body exists, scan it too (sometimes message is generic but body contains read_mask)
    if ( is_array( $data ) && ! empty( $data['raw_body'] ) ) {
        $raw = strtolower( (string) $data['raw_body'] );
        if (
            false !== strpos( $raw, 'field mask' ) ||
            false !== strpos( $raw, 'read_mask' ) ||
            false !== strpos( $raw, 'readmask' ) ||
            false !== strpos( $raw, 'invalid field mask' )
        ) {
            return true;
        }
    }

    // If decoded body exists, inspect structured error details
    if ( is_array( $data ) && isset( $data['body'] ) && is_array( $data['body'] ) ) {
        $body = $data['body'];

        // Common: body.error.details[].fieldViolations[]
        if ( isset( $body['error']['details'] ) && is_array( $body['error']['details'] ) ) {
            foreach ( $body['error']['details'] as $detail ) {
                if ( ! is_array( $detail ) ) {
                    continue;
                }

                if ( isset( $detail['fieldViolations'] ) && is_array( $detail['fieldViolations'] ) ) {
                    foreach ( $detail['fieldViolations'] as $fv ) {
                        if ( ! is_array( $fv ) ) {
                            continue;
                        }

                        $field = strtolower( (string) ( $fv['field'] ?? '' ) );
                        $desc  = strtolower( (string) ( $fv['description'] ?? '' ) );

                        if (
                            false !== strpos( $field, 'read_mask' ) ||
                            false !== strpos( $field, 'readmask' ) ||
                            false !== strpos( $desc, 'field mask' ) ||
                            false !== strpos( $desc, 'read_mask' ) ||
                            false !== strpos( $desc, 'readmask' ) ||
                            false !== strpos( $desc, 'invalid field mask' )
                        ) {
                            return true;
                        }
                    }
                }
            }
        }

        // Sometimes only error.message carries it
        if ( isset( $body['error']['message'] ) ) {
            $m2 = strtolower( (string) $body['error']['message'] );
            if (
                false !== strpos( $m2, 'field mask' ) ||
                false !== strpos( $m2, 'read_mask' ) ||
                false !== strpos( $m2, 'readmask' ) ||
                false !== strpos( $m2, 'invalid field mask' )
            ) {
                return true;
            }
        }
    }

    return false;
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
                sprintf( 'Response HTTP %d: %s', $response_code, substr( (string) $response_body, 0, 500 ) )
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

        // Build rich error data (IMPORTANT for readMask fallback decisions)
        $rich_error_data = array(
            'code'      => (int) $response_code,
            'endpoint'  => (string) $endpoint,
            'api_base'  => (string) $api_base,
            'method'    => (string) $method,
            'query'     => is_array( $query_args ) ? $query_args : array(),
            'body'      => is_array( $data ) ? $data : array(),
            'raw_body'  => (string) $response_body,
        );

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

            return new WP_Error( 'rate_limit_exceeded', $error_msg, $rich_error_data );
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

            return new WP_Error( 'permission_denied', $error_msg, $rich_error_data );
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
                return new WP_Error( 'server_error', $error_message, $rich_error_data );
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

        return new WP_Error( 'api_error', $error_message, $rich_error_data );
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
 * Extract numeric locationId from various location identifiers.
 *
 * Supports:
 * - "456" -> "456"
 * - "locations/456" -> "456"
 * - "accounts/123/locations/456" -> "456"
 * - "accounts/123/locations/456/media/..." -> "456" (defensivo)
 *
 * @param string $location_any
 * @return string
 */
private static function extract_location_id_from_any( $location_any ) {
    $v = trim( (string) $location_any );

    if ( '' === $v ) {
        return '';
    }

    // If already numeric
    if ( preg_match( '/^\d+$/', $v ) ) {
        return $v;
    }

    $v = trim( $v, '/' );

    // accounts/{acc}/locations/{loc}/...
    if ( strpos( $v, '/locations/' ) !== false ) {
        $parts = explode( '/locations/', $v );
        $tail  = end( $parts );
        $tail  = trim( (string) $tail, '/' );

        // tail could be "456" or "456/media/..."
        $chunks = explode( '/', $tail );
        $id     = $chunks[0] ?? '';

        return trim( (string) $id, '/' );
    }

    // locations/{loc}/...
    if ( strpos( $v, 'locations/' ) === 0 ) {
        $tail   = substr( $v, strlen( 'locations/' ) );
        $tail   = trim( (string) $tail, '/' );
        $chunks = explode( '/', $tail );
        $id     = $chunks[0] ?? '';

        return trim( (string) $id, '/' );
    }

    // Fallback last segment
    $chunks = explode( '/', $v );
    $last   = end( $chunks );

    return trim( (string) $last, '/' );
}

    /**
 * Extract numeric accountId from a full location resource name.
 *
 * Supports:
 * - "accounts/123/locations/456" -> "123"
 * - "accounts/123/locations/456/..." -> "123"
 *
 * @param string $location_name
 * @return string
 */
private static function extract_account_id_from_location_name( $location_name ) {
    $v = trim( (string) $location_name );

    if ( '' === $v ) {
        return '';
    }

    $v = trim( $v, '/' );

    // Must contain accounts/{id}
    if ( strpos( $v, 'accounts/' ) === 0 ) {
        $tail = substr( $v, strlen( 'accounts/' ) );
        $tail = trim( (string) $tail, '/' );

        $chunks = explode( '/', $tail );
        $id     = $chunks[0] ?? '';

        return trim( (string) $id, '/' );
    }

    if ( strpos( $v, '/accounts/' ) !== false ) {
        $parts  = explode( '/accounts/', $v );
        $tail   = end( $parts );
        $tail   = trim( (string) $tail, '/' );
        $chunks = explode( '/', $tail );
        $id     = $chunks[0] ?? '';

        return trim( (string) $id, '/' );
    }

    return '';
}



    /**
 * Extract numeric accountId from account resource name.
 *
 * Supports:
 * - "accounts/123" -> "123"
 * - "123" -> "123"
 *
 * @param string $account_name_or_id
 * @return string
 */
private static function extract_account_id_from_name( $account_name_or_id ) {
    $v = trim( (string) $account_name_or_id );

    if ( '' === $v ) {
        return '';
    }

    // If already numeric-ish
    if ( preg_match( '/^\d+$/', $v ) ) {
        return $v;
    }

    // Normalize
    $v = trim( $v, '/' );

    // accounts/{id}
    if ( strpos( $v, 'accounts/' ) === 0 ) {
        $maybe = substr( $v, strlen( 'accounts/' ) );
        $maybe = trim( (string) $maybe, '/' );
        if ( preg_match( '/^\d+$/', $maybe ) ) {
            return $maybe;
        }
        return $maybe; // fallback
    }

    // If includes "/accounts/"
    if ( strpos( $v, '/accounts/' ) !== false ) {
        $parts = explode( '/accounts/', $v );
        $tail  = end( $parts );
        $tail  = trim( (string) $tail, '/' );
        $chunks = explode( '/', $tail );
        $id = $chunks[0] ?? '';
        return trim( (string) $id, '/' );
    }

    // Fallback: last segment
    $chunks = explode( '/', $v );
    $last   = end( $chunks );
    return trim( (string) $last, '/' );
}

    /**
 * Resolve account resource name (accounts/{id}) for a given location, using cached locations list on the business.
 *
 * This fixes the mismatch between:
 * - Business Information API v1 location names: "locations/{locationId}"
 * - MyBusiness API v4 media endpoint requires: "accounts/{accountId}/locations/{locationId}/media"
 *
 * We search the business cached meta "_gmb_locations_available", where each item includes:
 * - 'name' (location resource name, often "locations/{id}")
 * - 'account_name' (account resource name, "accounts/{accountId}")
 *
 * @param int    $business_id
 * @param string $location_any           can be "1050...", "locations/1050...", or even "accounts/.../locations/..."
 * @param string $location_resource_name optional: same idea, used as extra hint
 * @return string account resource name e.g. "accounts/123" or empty string
 */
public static function resolve_account_resource_name_for_location( $business_id, $location_any, $location_resource_name = '' ) {
    $business_id            = absint( $business_id );
    $location_any           = trim( (string) $location_any );
    $location_resource_name = trim( (string) $location_resource_name );

    if ( ! $business_id ) {
        return '';
    }

    // Normalize to numeric locationId
    $target_location_id = '';
    if ( '' !== $location_any ) {
        $target_location_id = self::extract_location_id_from_any( $location_any );
    }
    if ( '' === $target_location_id && '' !== $location_resource_name ) {
        $target_location_id = self::extract_location_id_from_any( $location_resource_name );
    }

    if ( '' === $target_location_id ) {
        return '';
    }

    // 1) Try cached list
    $cached_locations = get_post_meta( $business_id, '_gmb_locations_available', true );

    // If cache is empty, attempt to populate using existing flow (will use cache unless forced)
    if ( empty( $cached_locations ) || ! is_array( $cached_locations ) ) {
        $maybe = self::get_all_locations( $business_id, false );
        if ( ! is_wp_error( $maybe ) && is_array( $maybe ) ) {
            $cached_locations = $maybe;
        }
    }

    if ( empty( $cached_locations ) || ! is_array( $cached_locations ) ) {
        return '';
    }

    foreach ( $cached_locations as $loc ) {
        if ( ! is_array( $loc ) ) {
            continue;
        }

        $loc_name       = (string) ( $loc['name'] ?? '' );          // often "locations/{id}"
        $loc_account    = (string) ( $loc['account_name'] ?? '' );  // "accounts/{accountId}"

        if ( '' === $loc_name || '' === $loc_account ) {
            continue;
        }

        $loc_id = self::extract_location_id_from_any( $loc_name );

        if ( $loc_id && $loc_id === $target_location_id ) {
            return $loc_account;
        }
    }

    return '';
}


/**
 * List owner media items (photos/videos) for a GBP location.
 *
 * Endpoint:
 * GET https://mybusiness.googleapis.com/v4/accounts/{accountId}/locations/{locationId}/media
 *
 * IMPORTANT:
 * - accountId y locationId deben estar presentes.
 * - Business Information API v1 usa "locations/{locationId}" (sin account).
 * - Para resolver accountId, usamos cache del business (_gmb_locations_available) si hace falta.
 *
 * @param int    $business_id
 * @param string $account_name_or_id        e.g. "accounts/123" or "123" (puede venir vacío)
 * @param string $location_id               e.g. "456" o "locations/456" o "accounts/123/locations/456"
 * @param bool   $force_refresh
 * @param string $location_resource_name    e.g. "accounts/123/locations/456" o "locations/456"
 * @return array|WP_Error
 */
public static function get_location_media_items( $business_id, $account_name_or_id, $location_id, $force_refresh = false, $location_resource_name = '' ) {
    $business_id            = absint( $business_id );
    $account_name_or_id     = trim( (string) $account_name_or_id );
    $location_id_raw_input  = trim( (string) $location_id );
    $location_resource_name = trim( (string) $location_resource_name );

    if ( ! $business_id ) {
        return new WP_Error( 'missing_params', __( 'Missing business_id for media list.', 'lealez' ) );
    }

    /**
     * 1) Si tenemos location_resource_name del estilo "accounts/123/locations/456",
     *    lo usamos para derivar accountId + locationId.
     *
     * OJO: Si viene como "locations/456", NO trae accountId.
     */
    if ( '' !== $location_resource_name ) {
        $acc_from_name = self::extract_account_id_from_location_name( $location_resource_name );
        $loc_from_name = self::extract_location_id_from_any( $location_resource_name );

        if ( '' !== $acc_from_name ) {
            $account_name_or_id = $acc_from_name;
        }
        if ( '' !== $loc_from_name ) {
            $location_id_raw_input = $loc_from_name; // ya numérico
        }
    }

    /**
     * 2) Normalizar locationId a numérico siempre
     */
    $location_id = self::extract_location_id_from_any( $location_id_raw_input );

    /**
     * 3) Normalizar accountId si lo tenemos
     */
    $account_id = '';
    if ( '' !== $account_name_or_id ) {
        $account_id = self::extract_account_id_from_name( $account_name_or_id );
    }

    /**
     * 4) Si NO tenemos accountId, intentamos resolverlo desde la cache del business:
     *    _gmb_locations_available (cada location tiene 'account_name' => "accounts/{id}")
     */
    $resolved_account_resource = '';
    if ( '' === $account_id && '' !== $location_id ) {
        $resolved_account_resource = self::resolve_account_resource_name_for_location(
            $business_id,
            $location_id,
            $location_resource_name
        );

        if ( '' !== $resolved_account_resource ) {
            $account_id = self::extract_account_id_from_name( $resolved_account_resource );
            // Conservamos algo legible como fuente de verdad
            if ( '' === $account_name_or_id ) {
                $account_name_or_id = $resolved_account_resource;
            }
        }
    }

    /**
     * 5) Validación final
     */
    if ( '' === $account_id || '' === $location_id ) {
        return new WP_Error(
            'missing_params',
            __( 'Missing/invalid accountId or locationId for media list (after normalization).', 'lealez' ),
            array(
                'account_name_or_id'          => $account_name_or_id,
                'account_id_normalized'       => $account_id,
                'account_resource_resolved'   => $resolved_account_resource,
                'location_id_raw'             => $location_id_raw_input,
                'location_id_normalized'      => $location_id,
                'location_resource_name'      => $location_resource_name,
            )
        );
    }

    // Endpoint v4 media
    $endpoint = '/accounts/' . rawurlencode( $account_id ) . '/locations/' . rawurlencode( $location_id ) . '/media';

    $all_items        = array();
    $page_token       = '';
    $loops            = 0;
    $last_page_result = array();

    do {
        $loops++;
        if ( $loops > 30 ) {
            break; // safety
        }

        $query_args = array(
            'pageSize' => 100,
        );

        if ( ! empty( $page_token ) ) {
            $query_args['pageToken'] = $page_token;
        }

        $result = self::make_request(
            $business_id,
            $endpoint,
            self::$mybusiness_v4_base,
            'GET',
            array(),
            ! $force_refresh,
            $query_args
        );

        if ( is_wp_error( $result ) ) {

            // Log robusto incluyendo ids normalizados
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'warning',
                    'Media API: could not retrieve location media items.',
                    array(
                        'account_id'                 => $account_id,
                        'account_name_or_id'         => $account_name_or_id,
                        'account_resource_resolved'  => $resolved_account_resource,
                        'location_id'                => $location_id,
                        'location_resource_name'     => $location_resource_name,
                        'error'                      => $result->get_error_message(),
                        'error_code'                 => $result->get_error_code(),
                        'error_data'                 => $result->get_error_data(),
                    )
                );
            }

            return $result;
        }

        $last_page_result = is_array( $result ) ? $result : array();

        $items = $last_page_result['mediaItems'] ?? array();
        if ( is_array( $items ) && ! empty( $items ) ) {
            $all_items = array_merge( $all_items, $items );
        }

        $page_token = $last_page_result['nextPageToken'] ?? '';

    } while ( ! empty( $page_token ) );

    return array(
        'mediaItems'          => $all_items,
        'totalMediaItemCount' => (int) ( $last_page_result['totalMediaItemCount'] ?? count( $all_items ) ),
    );
}




    /**
 * Get verification state for a location using My Business Verifications API.
 * ✅ AHORA PUBLIC para permitir llamadas desde Location CPT.
 *
 * Uses caching by default to avoid excessive requests.
 * Returns array like: ['state' => 'VERIFIED', 'name' => '...', 'createTime' => '...']
 *
 * @param int    $business_id
 * @param string $location_id
 * @param bool   $use_cache
 * @return array
 */
public static function get_location_verification_state( $business_id, $location_id, $use_cache = true ) {
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
     * ✅ Business Information API v1 (accounts.locations.list)
     *
     * Causa real del problema:
     * - make_request() devolvía WP_Error con mensaje genérico sin exponer details.fieldViolations.read_mask
     * - Por eso la lógica de fallback no detectaba "field mask error" y no probaba masks más seguros
     *
     * Solución:
     * - make_request ahora adjunta raw_body + body en error_data
     * - usamos self::is_field_mask_error($err) para decidir el fallback correctamente
     */
    /**
     * NOTA DE DISEÑO: metadata se mantiene en TODOS los masks porque contiene mapsUri
     * (URL de Google Maps), esencial para el campo location_map_url.
     * openInfo y locationState son campos deprecated en Business Information API v1;
     * si Google los rechaza, hacemos fallback SIN ellos pero SIEMPRE con metadata.
     * latlng se conserva hasta el mask 5 como fallback de URL via coordenadas.
     */
$read_masks = array(
    // Mask 1 - completo con campos legacy (openInfo, locationState) + metadata + serviceArea
    'name,title,storeCode,storefrontAddress,phoneNumbers,websiteUri,regularHours,specialHours,moreHours,serviceArea,categories,latlng,openInfo,locationState,metadata,languageCode,profile',
    // Mask 2 - sin campos deprecated pero con metadata + latlng + serviceArea
    'name,title,storeCode,storefrontAddress,phoneNumbers,websiteUri,regularHours,specialHours,moreHours,serviceArea,categories,latlng,metadata,languageCode,profile',
    // Mask 3 - reducido pero manteniendo metadata + latlng + serviceArea
    'name,title,storeCode,storefrontAddress,phoneNumbers,websiteUri,serviceArea,categories,latlng,metadata,languageCode,profile',
    // Mask 4 - más reducido, todavía con metadata + latlng + serviceArea
    'name,title,storefrontAddress,phoneNumbers,websiteUri,serviceArea,categories,latlng,metadata,profile',
    // Mask 5 - mínimo con metadata + serviceArea (sin latlng pero al menos mapsUri)
    'name,title,storefrontAddress,phoneNumbers,websiteUri,serviceArea,categories,metadata,profile',
    // Mask 6 - solo metadata esencial + serviceArea
    'name,title,serviceArea,metadata,profile',
    // Mask 7 - absoluto último recurso (sin metadata)
    'name,title,profile',
);

    $all_locations = array();
    $page_token    = '';
    $loops         = 0;

    do {
        $loops++;
        if ( $loops > 50 ) {
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

        $result     = null;
        $used_mask  = '';
        $last_error = null;

        foreach ( $read_masks as $mask ) {

            $query_args = array(
                'readMask' => $mask,
                'pageSize' => 100,
            );

            if ( ! empty( $page_token ) ) {
                $query_args['pageToken'] = $page_token;
            }

            $tmp = self::make_request(
                $business_id,
                $endpoint,
                self::$business_api_base,
                'GET',
                array(),
                ! $force_refresh,
                $query_args
            );

            if ( ! is_wp_error( $tmp ) ) {
                $result    = $tmp;
                $used_mask = $mask;

                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log(
                        $business_id,
                        'success',
                        'Locations list succeeded with readMask.',
                        array(
                            'account'  => $account_name,
                            'readMask' => $used_mask,
                        )
                    );
                }
                break;
            }

            $last_error = $tmp;

            $msg           = (string) $tmp->get_error_message();
            $is_mask_error = self::is_field_mask_error( $tmp );

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'warning',
                    'Locations list failed. Evaluating readMask fallback...',
                    array(
                        'account'     => $account_name,
                        'readMask'    => $mask,
                        'isMaskError' => $is_mask_error ? 'yes' : 'no',
                        'error'       => $msg,
                        'error_code'  => (string) $tmp->get_error_code(),
                    )
                );
            }

            // Si NO parece error de mask, no seguimos intentando (es otro problema real)
            if ( ! $is_mask_error ) {
                return $tmp;
            }

            // Si parece error de mask, probamos el siguiente mask
        }

        if ( is_wp_error( $last_error ) && empty( $result ) ) {
            return $last_error;
        }

        $locations = $result['locations'] ?? array();
        if ( ! empty( $locations ) ) {
            foreach ( $locations as $location ) {

                $categories       = $location['categories'] ?? array();
                $primary_category = array();

                if ( isset( $categories['primaryCategory'] ) && is_array( $categories['primaryCategory'] ) ) {
                    $primary_category = $categories['primaryCategory'];
                }

                // ✅ Verifications API (cache siempre)
                $location_name = $location['name'] ?? '';
                $location_id   = self::extract_location_id_from_name( $location_name );

                $verification = array();
                if ( ! empty( $location_id ) ) {
                    $verification = self::get_location_verification_state( $business_id, $location_id, true );
                }

                $all_locations[] = array(
                    'account_name'       => $account_name,

                    'name'               => $location['name'] ?? '',
                    'title'              => $location['title'] ?? '',
                    'storefrontAddress'  => $location['storefrontAddress'] ?? array(),
                    'phoneNumbers'       => $location['phoneNumbers'] ?? array(),
                    'websiteUri'         => $location['websiteUri'] ?? '',
                    'regularHours'       => $location['regularHours'] ?? array(),
                    'categories'         => $categories,
                    'primaryCategory'    => $primary_category,
                    'latlng'             => $location['latlng'] ?? array(),

                    'storeCode'          => $location['storeCode'] ?? '',
                    'specialHours'       => $location['specialHours'] ?? array(),
                    'moreHours'          => $location['moreHours'] ?? array(),
                    'openInfo'           => $location['openInfo'] ?? array(),
                    'locationState'      => $location['locationState'] ?? array(),
                    'metadata'           => $location['metadata'] ?? array(),
                    'labels'             => $location['labels'] ?? array(),
                    'languageCode'       => $location['languageCode'] ?? '',

                    // ✅ profile.description → necesario para campo Descripción (GMB)
                    'profile'            => $location['profile'] ?? array(),

                    'verification'       => is_array( $verification ) ? $verification : array(),

                    '_used_read_mask'    => $used_mask,
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
    $business_id = absint( $business_id );

    // ✅ Marcar actividad del sync (sirve para lock stale recovery)
    update_post_meta( $business_id, '_gmb_sync_last_activity', time() );

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
        $last_fetch       = get_post_meta( $business_id, '_gmb_locations_last_fetch', true );

        // Use cache if less than 24 hours old
        if ( ! empty( $cached_locations ) && $last_fetch && ( time() - $last_fetch ) < self::$cache_duration_locations ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'info', 'Using cached locations data' );
            }
            return $cached_locations;
        }
    }

    // ✅ accounts.list (Account Management API)
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

    $all_locations              = array();
    $errors                     = array();
    $locations_count_by_account = array();

    foreach ( $accounts as $index => $account ) {
        $account_name = $account['name'] ?? '';

        if ( empty( $account_name ) ) {
            continue;
        }

        // ✅ Init count
        if ( ! isset( $locations_count_by_account[ $account_name ] ) ) {
            $locations_count_by_account[ $account_name ] = 0;
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
            // ✅ Marcar actividad del sync antes de dormir
            update_post_meta( $business_id, '_gmb_sync_last_activity', time() );
            sleep( self::$delay_between_calls * 2 ); // 10 segundos entre cuentas
        }

        // ✅ accounts.locations.list (Business Information API)
        update_post_meta( $business_id, '_gmb_sync_last_activity', time() );
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
            if ( in_array( $locations->get_error_code(), array( 'rate_limit_exceeded', 'rate_limit_active' ), true ) ) {
                break;
            }

            continue;
        }

        if ( is_array( $locations ) && ! empty( $locations ) ) {
            $locations_count_by_account[ $account_name ] += count( $locations );
            $all_locations = array_merge( $all_locations, $locations );
        }
    }

    // ✅ Guardar conteo por cuenta (para el metabox "Google My Business")
    update_post_meta( $business_id, '_gmb_locations_count_by_account', $locations_count_by_account );

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
    if ( ! empty( $errors ) ) {
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
    $business_id = absint( $business_id );

    // ✅ Lock para que no quede el sistema en “sync en proceso” por flujos incompletos
    if ( ! self::acquire_sync_lock( $business_id, 600 ) ) {
        return new WP_Error(
            'sync_in_progress',
            __( 'Ya hay una sincronización en proceso. Intenta de nuevo en unos segundos.', 'lealez' )
        );
    }

    try {
        // Marcar actividad del sync
        update_post_meta( $business_id, '_gmb_sync_last_activity', time() );

        // 1) Limpieza de cache / rate-limit flags para permitir sync inicial post-reconexión
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                'bootstrap_after_connect: clearing stale cache/rate-limit flags before initial sync'
            );
        }
        self::clear_business_cache( $business_id );

        // 2) accounts.list (Account Management API)
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'info', 'bootstrap_after_connect: fetching accounts (force_refresh=true)' );
        }

        update_post_meta( $business_id, '_gmb_sync_last_activity', time() );
        $accounts = self::get_accounts( $business_id, true );

        if ( is_wp_error( $accounts ) ) {
            return $accounts;
        }

        if ( empty( $accounts ) || ! is_array( $accounts ) ) {
            $error = new WP_Error( 'no_accounts', __( 'No GMB accounts found for this user', 'lealez' ) );
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'error', 'bootstrap_after_connect: No GMB accounts found' );
            }
            return $error;
        }

        // 3) accounts.locations.list (Business Information API) + conteo por cuenta
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'info', 'bootstrap_after_connect: fetching locations per account (force_refresh=true)' );
        }

        $all_locations              = array();
        $errors                     = array();
        $locations_count_by_account = array();

        foreach ( $accounts as $index => $account ) {
            $account_name = $account['name'] ?? '';

            if ( empty( $account_name ) ) {
                continue;
            }

            if ( ! isset( $locations_count_by_account[ $account_name ] ) ) {
                $locations_count_by_account[ $account_name ] = 0;
            }

            // Delay conservador entre cuentas (excepto la primera)
            if ( $index > 0 ) {
                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log(
                        $business_id,
                        'info',
                        sprintf(
                            'bootstrap_after_connect: waiting %d seconds before next account locations...',
                            self::$delay_between_calls * 2
                        )
                    );
                }
                update_post_meta( $business_id, '_gmb_sync_last_activity', time() );
                sleep( self::$delay_between_calls * 2 );
            }

            update_post_meta( $business_id, '_gmb_sync_last_activity', time() );
            $locations = self::get_locations( $business_id, $account_name, true );

            if ( is_wp_error( $locations ) ) {
                $errors[] = $locations->get_error_message();

                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log(
                        $business_id,
                        'error',
                        sprintf(
                            'bootstrap_after_connect: Failed to get locations for account %s: %s',
                            $account_name,
                            $locations->get_error_message()
                        )
                    );
                }

                // Si es rate limit, detenemos inmediatamente
                if ( in_array( $locations->get_error_code(), array( 'rate_limit_exceeded', 'rate_limit_active' ), true ) ) {
                    break;
                }

                continue;
            }

            if ( is_array( $locations ) && ! empty( $locations ) ) {
                $locations_count_by_account[ $account_name ] += count( $locations );
                $all_locations = array_merge( $all_locations, $locations );
            }
        }

        // ✅ Guardar conteo por cuenta (metabox "Google My Business")
        update_post_meta( $business_id, '_gmb_locations_count_by_account', $locations_count_by_account );

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

    } finally {
        self::release_sync_lock( $business_id );
    }
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

    /**
     * ✅ locations.get (Business Information API v1)
     *
     * Harden:
     * - Probamos cadena de masks desde el más completo al más seguro.
     * - Solo hacemos fallback si el error "parece" de field mask.
     * - Ahora la detección usa self::is_field_mask_error() (lee raw_body + details.fieldViolations)
     *
     * NOTA: metadata se incluye en TODOS los masks porque contiene mapsUri (URL de Google Maps).
     * openInfo y locationState son campos deprecated que pueden causar rechazo; se eliminan en
     * el fallback pero metadata se preserva siempre para garantizar location_map_url.
     */
$read_masks = array(
    // Mask 1 - completo con campos legacy (openInfo, locationState) + metadata + serviceArea
    'name,title,storeCode,storefrontAddress,phoneNumbers,websiteUri,regularHours,specialHours,moreHours,serviceArea,categories,latlng,openInfo,locationState,metadata,languageCode,profile',
    // Mask 2 - sin campos deprecated pero con metadata + latlng + serviceArea
    'name,title,storeCode,storefrontAddress,phoneNumbers,websiteUri,regularHours,specialHours,moreHours,serviceArea,categories,latlng,metadata,languageCode,profile',
    // Mask 3 - reducido pero manteniendo metadata + latlng + serviceArea
    'name,title,storeCode,storefrontAddress,phoneNumbers,websiteUri,serviceArea,categories,latlng,metadata,languageCode,profile',
    // Mask 4 - más reducido, todavía con metadata + latlng + serviceArea
    'name,title,storefrontAddress,phoneNumbers,websiteUri,serviceArea,categories,latlng,metadata,profile',
    // Mask 5 - mínimo con metadata + serviceArea (sin latlng pero al menos mapsUri)
    'name,title,storefrontAddress,phoneNumbers,websiteUri,serviceArea,categories,metadata,profile',
    // Mask 6 - solo metadata esencial + serviceArea
    'name,title,serviceArea,metadata,profile',
    // Mask 7 - último recurso sin metadata
    'name,title,profile',
    // Mask 8 - último último recurso
    'name,title',
);

    $last_error = null;

    foreach ( $read_masks as $mask ) {

        $query_args = array(
            'readMask' => $mask,
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

        if ( ! is_wp_error( $result ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'success',
                    'Location get succeeded with readMask.',
                    array(
                        'location' => $location_name,
                        'readMask' => $mask,
                    )
                );
            }

// ✅ Si la mask exitosa no incluyó profile (o Google no lo retornó),
            // hacemos una llamada dedicada ultraliviana solo para profile.description.
            if ( empty( $result['profile'] ) ) {
                $profile_query = array( 'readMask' => 'name,profile' );
                $profile_result = self::make_request(
                    $business_id,
                    $endpoint,
                    self::$business_api_base,
                    'GET',
                    array(),
                    false, // no cache: necesitamos dato fresco
                    $profile_query
                );
                if ( ! is_wp_error( $profile_result ) && ! empty( $profile_result['profile'] ) ) {
                    $result['profile'] = $profile_result['profile'];
                    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                        Lealez_GMB_Logger::log(
                            $business_id,
                            'info',
                            'Profile fetched via dedicated call (readMask=name,profile).',
                            array( 'location' => $location_name )
                        );
                    }
                }
            }

            // ✅ Si la mask exitosa no incluyó latlng (mask 5+), hacer llamada dedicada
            // para recuperar coordenadas GPS. El campo latlng es parte del recurso Location
            // en Business Information API v1 (latlng.latitude / latlng.longitude).
            // Referencia: https://developers.google.com/my-business/reference/businessinformation/rest/v1/locations
            $latlng_missing = empty( $result['latlng'] )
                || ! isset( $result['latlng']['latitude'] )
                || ! isset( $result['latlng']['longitude'] );

if ( $latlng_missing ) {
                // ── Intento 1: llamada dedicada readMask=name,latlng ─────────────────────
                // La Business Information API v1 SOLO retorna latlng cuando fue establecido
                // manualmente por el negocio. Para coordenadas auto-detectadas el campo viene
                // vacío o ausente aunque el negocio sí tenga coordenadas en Google Maps.
                $latlng_query  = array( 'readMask' => 'name,latlng' );
                $latlng_result = self::make_request(
                    $business_id,
                    $endpoint,
                    self::$business_api_base,
                    'GET',
                    array(),
                    false,   // no cache: necesitamos dato fresco
                    $latlng_query
                );

                if (
                    ! is_wp_error( $latlng_result )
                    && isset( $latlng_result['latlng'] )
                    && is_array( $latlng_result['latlng'] )
                    && isset( $latlng_result['latlng']['latitude'] )
                    && isset( $latlng_result['latlng']['longitude'] )
                    && ( (float) $latlng_result['latlng']['latitude'] !== 0.0 || (float) $latlng_result['latlng']['longitude'] !== 0.0 )
                ) {
                    // Intento 1 exitoso — coordenadas del campo latlng explícito del negocio.
                    $result['latlng'] = $latlng_result['latlng'];

                    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                        Lealez_GMB_Logger::log(
                            $business_id,
                            'info',
                            'LatLng fetched via dedicated call (readMask=name,latlng).',
                            array(
                                'location' => $location_name,
                                'latlng'   => $latlng_result['latlng'],
                            )
                        );
                    }
                } else {
                    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                        error_log( '[OY Location] sync_location_data — Intento 1 (readMask latlng) vacío/fallido para: ' . $location_name . '. Intentando googleLocations:search.' );
                    }

                    // ── Intento 2: googleLocations:search (Business Information API v1) ───
                    // Fuente de datos: el mismo Google, con las mismas credenciales OAuth.
                    // El endpoint POST /v1/googleLocations:search recibe el nombre y dirección
                    // del negocio y retorna las coordenadas exactas que Google Maps usa
                    // internamente — incluyendo negocios sin latlng explícito en el recurso.
                    // Ref: https://developers.google.com/my-business/reference/businessinformation/rest/v1/googleLocations/search
                    $gl_title   = isset( $result['title'] )    ? (string) $result['title']    : '';
                    $gl_address = isset( $result['storefrontAddress'] ) && is_array( $result['storefrontAddress'] )
                        ? $result['storefrontAddress']
                        : array();
                    $gl_phone   = isset( $result['phoneNumbers']['primaryPhone'] )
                        ? (string) $result['phoneNumbers']['primaryPhone']
                        : '';

                    $google_coords = null;

                    if ( '' !== $gl_title || ! empty( $gl_address ) ) {
                        $google_coords = self::geocode_via_google_locations_search(
                            $business_id,
                            $gl_title,
                            $gl_address,
                            $gl_phone
                        );
                    }

                    if ( $google_coords ) {
                        $result['latlng'] = $google_coords;

                        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                            Lealez_GMB_Logger::log(
                                $business_id,
                                'info',
                                'LatLng obtenido via googleLocations:search (coordenadas exactas de Google).',
                                array(
                                    'location' => $location_name,
                                    'latlng'   => $google_coords,
                                )
                            );
                        }
                    } else {
                        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                            error_log( '[OY Location] sync_location_data — Intento 2 (googleLocations:search) falló para: ' . $location_name . '. Intentando Nominatim como último recurso.' );
                        }

                        // ── Intento 3: Nominatim (OpenStreetMap) — último recurso ─────────
                        // Solo se usa si Google no pudo retornar coords por ninguna vía.
                        // Precisión variable para direcciones latinoamericanas.
                        if ( ! empty( $gl_address ) ) {
                            $nominatim_coords = self::geocode_address_fallback( $gl_address );

                            if ( $nominatim_coords ) {
                                $result['latlng'] = $nominatim_coords;

                                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                                    Lealez_GMB_Logger::log(
                                        $business_id,
                                        'info',
                                        'LatLng obtenido via Nominatim (último recurso — precisión menor).',
                                        array(
                                            'location' => $location_name,
                                            'latlng'   => $nominatim_coords,
                                        )
                                    );
                                }
                            } else {
                                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                                    error_log( '[OY Location] sync_location_data — Intento 3 (Nominatim) también falló para: ' . $location_name . '. Sin coordenadas.' );
                                }
                            }
                        }
                    }
                }
            }

            return $result;
        }

        $last_error     = $result;
        $msg            = (string) $result->get_error_message();
        $is_mask_error  = self::is_field_mask_error( $result );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'warning',
                'Location get failed. Evaluating readMask fallback...',
                array(
                    'location'     => $location_name,
                    'readMask'     => $mask,
                    'isMaskError'  => $is_mask_error ? 'yes' : 'no',
                    'error'        => $msg,
                    'error_code'   => (string) $result->get_error_code(),
                )
            );
        }

        // Si NO parece error de mask, devolvemos el error (no seguimos intentando).
        if ( ! $is_mask_error ) {
            return $result;
        }
    }

    return is_wp_error( $last_error ) ? $last_error : new WP_Error( 'api_error', __( 'API request failed', 'lealez' ) );
}


    /**
 * Get attributes for a specific location via dedicated endpoint.
 *
 * La Business Information API v1 NO incluye atributos inline en el recurso Location.
 * Para obtener url_whatsapp, url_text_messaging y otros atributos URI, se debe llamar
 * al endpoint dedicado: GET /v1/locations/{locationId}/attributes
 *
 * Documentación: https://developers.google.com/my-business/reference/businessinformation/rest/v1/locations/getAttributes
 *
 * @param int    $business_id    WP post ID del oy_business.
 * @param string $location_name  Resource name con formato 'locations/{id}' o 'accounts/{acc}/locations/{id}'.
 * @param bool   $use_cache      Si usar caché. Default true.
 *
 * @return array|WP_Error Array de atributos normalizado para procesamiento, o WP_Error si falla.
 *                        Formato devuelto: [ ['name' => 'locations/xxx/attributes/url_whatsapp', 'uriValues' => [['uri'=>'...']] ], ... ]
 */
public static function get_location_attributes( $business_id, $location_name, $use_cache = true ) {
    $business_id   = absint( $business_id );
    $location_name = trim( (string) $location_name );

    if ( ! $business_id || '' === $location_name ) {
        return new WP_Error( 'invalid_params', __( 'Invalid business_id or location_name', 'lealez' ) );
    }

    // Normalizar location_name al formato corto 'locations/{id}'
    $normalized_location = $location_name;
    if ( strpos( $location_name, 'accounts/' ) === 0 ) {
        $parts = explode( '/locations/', $location_name, 2 );
        if ( ! empty( $parts[1] ) ) {
            $normalized_location = 'locations/' . $parts[1];
        }
    }

    // ✅ WP Transient cache (15 min) — evita llamadas repetidas al API en el mismo
    // flujo AJAX → save_post, previniendo bloqueos por rate limiting.
    $transient_key = 'lealez_attrs_' . md5( (string) $business_id . '|' . rtrim( $normalized_location, '/' ) );

    if ( $use_cache ) {
        $cached = get_transient( $transient_key );
        if ( is_array( $cached ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'info',
                    'Location attributes served from WP transient cache (15 min).',
                    array(
                        'location'    => $normalized_location,
                        'attr_count'  => count( $cached ),
                    )
                );
            }
            return $cached;
        }
    }

    $endpoint = '/' . rtrim( $normalized_location, '/' ) . '/attributes';

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'info',
            'Fetching location attributes via dedicated endpoint.',
            array(
                'location'   => $location_name,
                'endpoint'   => $endpoint,
                'normalized' => $normalized_location,
                'use_cache'  => $use_cache ? 'yes (transient miss)' : 'no',
            )
        );
    }

    $result = self::make_request(
        $business_id,
        $endpoint,
        self::$business_api_base,
        'GET',
        array(),
        false, // El caché se maneja vía WP transient arriba
        array()
    );

    if ( is_wp_error( $result ) ) {
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'warning',
                'get_location_attributes failed: ' . $result->get_error_message(),
                array( 'location' => $location_name )
            );
        }
        return $result;
    }

    $attributes = array();
    if ( ! empty( $result['attributes'] ) && is_array( $result['attributes'] ) ) {
        $attributes = $result['attributes'];
    }

    // ✅ Guardar en transient (15 min), incluyendo array vacío (respuesta válida)
    set_transient( $transient_key, $attributes, 15 * MINUTE_IN_SECONDS );

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'success',
            sprintf( 'Location attributes fetched: %d attribute(s) found. Cached 15 min.', count( $attributes ) ),
            array( 'location' => $location_name )
        );
    }

    return $attributes;
}


/**
 * =========================================================================
 * BLOQUE COMPLETO: Nuevos métodos para agregar en class-lealez-gmb-api.php
 *
 * INSTRUCCIÓN DE INTEGRACIÓN:
 * Agregar estos dos métodos DENTRO de la clase Lealez_GMB_API,
 * justo DESPUÉS del método `get_location_attributes()` existente.
 *
 * Archivo destino: includes/integrations/google-my-business/class-lealez-gmb-api.php
 * =========================================================================
 */


/**
 * Obtiene los METADATOS de atributos disponibles para una ubicación según su categoría.
 *
 * Endpoint: GET https://mybusinessbusinessinformation.googleapis.com/v1/attributes
 *
 * REGLA CRÍTICA DE LA API (descubierta vía fieldViolations):
 * Cuando se pasa el parámetro `parent` (resource name de la ubicación),
 * Google NO permite que se envíen `regionCode` ni `languageCode` simultáneamente.
 * Son mutuamente excluyentes: usar `parent` implica que Google ya conoce el
 * país y el idioma del negocio desde su propia base de datos.
 *
 * Referencia del error:
 * "Field must not be set when parent is set." para language_code y region_code.
 *
 * @param int    $business_id     WP Post ID del oy_business (para tokens OAuth).
 * @param string $location_name   Resource name de la ubicación (cualquier formato).
 * @param string $category_name   Categoría en formato 'categories/gcid:xxx'. Default ''.
 * @param string $region_code     Ignorado cuando se usa parent. Reservado para compatibilidad.
 * @param string $language_code   Ignorado cuando se usa parent. Reservado para compatibilidad.
 * @param bool   $use_cache       Usar caché del rate limiter. Default true.
 *
 * @return array|WP_Error Array de AttributeMetadata o WP_Error.
 */
public static function get_attribute_metadata( $business_id, $location_name, $category_name = '', $region_code = 'CO', $language_code = 'es', $use_cache = true ) {
    $business_id   = absint( $business_id );
    $location_name = trim( (string) $location_name );
    $category_name = is_string( $category_name ) ? $category_name : '';

    if ( ! $business_id || '' === $location_name ) {
        return new WP_Error( 'invalid_params', __( 'Invalid business_id or location_name for get_attribute_metadata.', 'lealez' ) );
    }

    // ── Normalizar location_name ──────────────────────────────────────────────
    // Eliminar sufijos no deseados (e.g. /attributes)
    $clean_name = $location_name;
    if ( false !== strpos( $clean_name, '/attributes' ) ) {
        $clean_name = explode( '/attributes', $clean_name )[0];
    }
    $clean_name = trim( $clean_name, '/' );

    // Formato completo: accounts/{acc}/locations/{id}
    $parent_full = '';
    // Formato corto: locations/{id}
    $parent_short = '';

    if ( strpos( $clean_name, 'accounts/' ) === 0 ) {
        $parent_full  = $clean_name;
        $parts        = explode( '/locations/', $clean_name, 2 );
        if ( ! empty( $parts[1] ) ) {
            $parent_short = 'locations/' . trim( $parts[1], '/' );
        }
    } elseif ( strpos( $clean_name, 'locations/' ) === 0 ) {
        $parent_short = $clean_name;
    } elseif ( preg_match( '/^\d+$/', $clean_name ) ) {
        $parent_short = 'locations/' . $clean_name;
    } else {
        $parent_short = $clean_name;
    }

    // ── Lista de parents a intentar (completo primero, luego corto) ───────────
    $parents_to_try = array();
    if ( '' !== $parent_full ) {
        $parents_to_try[] = $parent_full;
    }
    if ( '' !== $parent_short && $parent_short !== $parent_full ) {
        $parents_to_try[] = $parent_short;
    }

    if ( empty( $parents_to_try ) ) {
        return new WP_Error( 'invalid_params', __( 'No se pudo determinar el parent para get_attribute_metadata.', 'lealez' ) );
    }

    // ── REGLA CRÍTICA: cuando `parent` está presente, NO enviar regionCode
    // ni languageCode. La API de Google los rechaza con INVALID_ARGUMENT.
    // Solo se permite categoryName además de parent.
    // ──────────────────────────────────────────────────────────────────────────
    $optional_qs = '';
    if ( '' !== $category_name && strpos( $category_name, 'categories/' ) === 0 ) {
        $optional_qs = '&categoryName=' . rawurlencode( $category_name );
    }

    $last_error = null;

    foreach ( $parents_to_try as $idx => $parent_value ) {

        // El slash en el VALUE del QS es literal — no usar add_query_arg()
        // para evitar que codifique el slash como %2F.
        $endpoint_with_qs = '/attributes?parent=' . $parent_value . $optional_qs;

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                sprintf( 'Attempt %d/%d: Fetching attribute metadata.', $idx + 1, count( $parents_to_try ) ),
                array(
                    'parent'   => $parent_value,
                    'category' => $category_name,
                    'endpoint' => self::$business_api_base . $endpoint_with_qs,
                )
            );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[OY GMB More] get_attribute_metadata attempt %d — URL: %s',
                $idx + 1,
                self::$business_api_base . $endpoint_with_qs
            ) );
        }

        // $query_args vacío — el QS ya está embebido en $endpoint_with_qs.
        $result = self::make_request(
            $business_id,
            $endpoint_with_qs,
            self::$business_api_base,
            'GET',
            array(),
            $use_cache,
            array()
        );

        if ( ! is_wp_error( $result ) ) {
            $metadata = array();
            if ( ! empty( $result['attributeMetadata'] ) && is_array( $result['attributeMetadata'] ) ) {
                $metadata = $result['attributeMetadata'];
            }

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'success',
                    sprintf( 'Attribute metadata fetched: %d attribute(s) [parent=%s].', count( $metadata ), $parent_value ),
                    array( 'parent' => $parent_value )
                );
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[OY GMB More] get_attribute_metadata OK: %d attribute(s) for parent=%s',
                    count( $metadata ),
                    $parent_value
                ) );
            }

            return $metadata;
        }

        // ── Error — loguear el raw_body para diagnóstico ──────────────────────
        $last_error = $result;
        $error_data = $result->get_error_data();

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'warning',
                sprintf( 'Attempt %d failed: %s', $idx + 1, $result->get_error_message() ),
                array(
                    'parent'     => $parent_value,
                    'error_code' => $result->get_error_code(),
                    'raw_body'   => is_array( $error_data ) ? ( $error_data['raw_body'] ?? '' ) : '',
                )
            );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[OY GMB More] get_attribute_metadata attempt %d FAILED. parent=%s | error=%s | raw_body=%s',
                $idx + 1,
                $parent_value,
                $result->get_error_message(),
                is_array( $error_data ) ? substr( (string) ( $error_data['raw_body'] ?? '' ), 0, 1000 ) : 'n/a'
            ) );
        }

        // Solo reintentar con el siguiente parent si es HTTP 400
        $http_code = is_array( $error_data ) ? ( (int) ( $error_data['code'] ?? 0 ) ) : 0;
        if ( 400 !== $http_code ) {
            break;
        }
    }

    if ( is_wp_error( $last_error ) ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY GMB More] get_attribute_metadata error: ' . $last_error->get_error_message() );
        }
        return $last_error;
    }

    return new WP_Error( 'api_error', __( 'API request failed', 'lealez' ) );
}


    /**
     * Actualiza (PATCH) los atributos de una ubicación en Google Business Profile.
     *
     * Endpoint:
     * PATCH https://mybusinessbusinessinformation.googleapis.com/v1/{locationName}/attributes
     *
     * El body debe contener el resource name de la ubicación + la lista de atributos a actualizar.
     * Google usa PATCH con `updateMask=attributes` — SOLO se actualizan los atributos enviados;
     * los demás no se modifican.
     *
     * Documentación oficial:
     * https://developers.google.com/my-business/reference/businessinformation/rest/v1/locations/updateAttributes
     *
     * @param int    $business_id        WP Post ID del oy_business (para tokens OAuth).
     * @param string $location_name      Resource name de la ubicación (cualquier formato aceptado).
     * @param array  $attributes_payload Array de atributos en formato GMB API v1:
     *                                   [
     *                                     [
     *                                       'name'      => 'attributes/has_women_led',
     *                                       'valueType' => 'BOOL',
     *                                       'values'    => [true],
     *                                     ],
     *                                   ]
     *
     * @return array|WP_Error Respuesta de la API (el Attributes resource actualizado), o WP_Error.
     */
    public static function update_location_attributes( $business_id, $location_name, $attributes_payload ) {
        $business_id   = absint( $business_id );
        $location_name = trim( (string) $location_name );

        if ( ! $business_id || '' === $location_name ) {
            return new WP_Error( 'invalid_params', __( 'Invalid business_id or location_name for update_location_attributes.', 'lealez' ) );
        }

        if ( empty( $attributes_payload ) || ! is_array( $attributes_payload ) ) {
            return new WP_Error( 'empty_payload', __( 'No attributes provided to update.', 'lealez' ) );
        }

        // ── Normalizar location_name a formato corto 'locations/{id}' ──────────
        $normalized_location = $location_name;
        if ( strpos( $location_name, 'accounts/' ) === 0 ) {
            $parts = explode( '/locations/', $location_name, 2 );
            if ( ! empty( $parts[1] ) ) {
                $normalized_location = 'locations/' . trim( $parts[1], '/' );
            }
        }
        if ( false !== strpos( $normalized_location, '/attributes' ) ) {
            $normalized_location = explode( '/attributes', $normalized_location )[0];
        }

        // El endpoint PATCH para atributos es: /v1/locations/{id}/attributes
        $endpoint = '/' . rtrim( $normalized_location, '/' ) . '/attributes';

        // Body del PATCH: { "name": "locations/{id}/attributes", "attributes": [...] }
        $body = array(
            'name'       => $normalized_location . '/attributes',
            'attributes' => $attributes_payload,
        );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                sprintf( 'Updating %d attribute(s) for location.', count( $attributes_payload ) ),
                array(
                    'location' => $normalized_location,
                    'endpoint' => $endpoint,
                    'count'    => count( $attributes_payload ),
                )
            );
        }

        $result = self::make_request(
            $business_id,
            $endpoint,
            self::$business_api_base,
            'PATCH',
            $body,
            false, // No caché en PATCH
            array( 'updateMask' => 'attributes' )
        );

        if ( is_wp_error( $result ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'error',
                    'update_location_attributes failed: ' . $result->get_error_message(),
                    array( 'location' => $normalized_location )
                );
            }
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY GMB More] update_location_attributes error: ' . $result->get_error_message() );
            }
            return $result;
        }

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'success',
                sprintf( 'Attributes updated successfully for location: %s', $normalized_location ),
                array( 'location' => $normalized_location )
            );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                '[OY GMB More] update_location_attributes: OK for ' . $normalized_location .
                ' (' . count( $attributes_payload ) . ' attr(s) updated)'
            );
        }

        return $result;
    }




/**
 * Obtiene los PlaceActionLinks de una ubicación desde My Business Place Actions API v1.
 *
 * Esta es la fuente oficial y correcta para las URLs configuradas en el Perfil de Negocio:
 *   → "Editar perfil → Reserva"       → placeActionType: APPOINTMENT, ONLINE_APPOINTMENT, DINING_RESERVATION
 *   → "Editar perfil → Menú"           → placeActionType: MENU
 *   → "Editar perfil → Ordenar en línea" → placeActionType: FOOD_ORDERING, ORDER_AHEAD, FOOD_DELIVERY, FOOD_TAKEOUT
 *
 * Endpoint: GET https://mybusinessplaceactions.googleapis.com/v1/locations/{id}/placeActionLinks
 *
 * ✅ CACHÉ: Usa WP transients (15 min) independientemente del rate limiter de make_request().
 * Esto garantiza que si el AJAX llamó exitosamente, el flujo de save_post puede usar el cache
 * sin necesidad de golpear el API nuevamente, evitando bloqueos por rate limiting.
 *
 * @param int    $business_id   WP Post ID del oy_business (para tokens OAuth).
 * @param string $location_name Resource name de la ubicación (cualquier formato).
 * @param bool   $use_cache     Si true, usa caché WP transient (15 min). Default true.
 * @return array|WP_Error Array de PlaceActionLink objects, o WP_Error en caso de fallo.
 */
public static function get_location_place_action_links( $business_id, $location_name, $use_cache = true ) {
    $business_id   = absint( $business_id );
    $location_name = trim( (string) $location_name );

    if ( ! $business_id || '' === $location_name ) {
        return new WP_Error( 'invalid_params', __( 'Invalid business_id or location_name', 'lealez' ) );
    }

    // Normalizar al formato corto 'locations/{id}' requerido por Place Actions API v1
    $normalized_location = $location_name;
    if ( strpos( $location_name, 'accounts/' ) === 0 ) {
        $parts = explode( '/locations/', $location_name, 2 );
        if ( ! empty( $parts[1] ) ) {
            $normalized_location = 'locations/' . $parts[1];
        }
    } elseif ( is_numeric( trim( $location_name, '/' ) ) ) {
        $normalized_location = 'locations/' . trim( $location_name, '/' );
    }
    $normalized_location = rtrim( $normalized_location, '/' );

    // ✅ WP Transient cache — independiente del rate limiter de make_request().
    // TTL: 15 minutos. Permite que el flujo AJAX y save_post compartan el resultado
    // sin golpear el API dos veces en la misma sesión de importación.
    $transient_key = 'lealez_pal_' . md5( (string) $business_id . '|' . $normalized_location );

    if ( $use_cache ) {
        $cached_links = get_transient( $transient_key );
        if ( is_array( $cached_links ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'info',
                    'PlaceActionLinks servidos desde WP transient cache (15 min).',
                    array(
                        'location'   => $normalized_location,
                        'link_count' => count( $cached_links ),
                    )
                );
            }
            return $cached_links;
        }
    }

    // Endpoint: /locations/{id}/placeActionLinks
    $endpoint = '/' . $normalized_location . '/placeActionLinks';

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'info',
            'Fetching PlaceActionLinks (booking / menu / order URLs) via Place Actions API v1.',
            array(
                'original_location'   => $location_name,
                'normalized_location' => $normalized_location,
                'endpoint'            => self::$place_actions_api_base . $endpoint,
                'use_cache'           => $use_cache ? 'yes' : 'no',
            )
        );
    }

    // Siempre pasamos use_cache=false a make_request() ya que el caché se gestiona
    // vía WP transients en este método (capa superior).
    $result = self::make_request(
        $business_id,
        $endpoint,
        self::$place_actions_api_base,
        'GET',
        array(),
        false, // No caché en make_request; el caché transient se maneja arriba.
        array()
    );

    if ( is_wp_error( $result ) ) {
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'error',
                'get_location_place_action_links failed: ' . $result->get_error_message(),
                array(
                    'location'   => $location_name,
                    'error_code' => $result->get_error_code(),
                    'hint'       => 'Verifica que la API "My Business Place Actions API" esté habilitada en Google Cloud Console y que el token tenga scope https://www.googleapis.com/auth/business.manage',
                )
            );
        }
        $result->add_data(
            array(
                'location' => $location_name,
                'hint'     => 'Habilita "My Business Place Actions API" en Google Cloud Console.',
            ),
            $result->get_error_code()
        );
        return $result;
    }

    // Extraer placeActionLinks del resultado
    $links = array();
    if ( ! empty( $result['placeActionLinks'] ) && is_array( $result['placeActionLinks'] ) ) {
        $links = $result['placeActionLinks'];
    }

    // ✅ Guardar en WP transient (15 min), incluso si el array está vacío
    // (negocio sin links = respuesta válida que no debe re-consultarse enseguida)
    set_transient( $transient_key, $links, 15 * MINUTE_IN_SECONDS );

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'success',
            sprintf( 'PlaceActionLinks fetched: %d link(s) found. Cached for 15 min.', count( $links ) ),
            array( 'location' => $location_name )
        );
    }

    return $links;
}


/**
 * Obtiene el menú estructurado de una ubicación desde Google My Business API v4 (foodMenus).
 *
 * Endpoint: GET https://mybusiness.googleapis.com/v4/accounts/{accountId}/locations/{locationId}/foodMenus
 *
 * @param int    $business_id   WP Post ID del oy_business.
 * @param string $account_id    Account ID numérico, "accounts/{id}", o resource name completo.
 * @param string $location_id   Location ID numérico, "locations/{id}", o resource name completo.
 * @param bool   $force_refresh Si true, ignora caché del rate limiter.
 * @return array|WP_Error
 */
public static function get_location_food_menus( $business_id, $account_id, $location_id, $force_refresh = false ) {
    $business_id = absint( $business_id );

    if ( ! $business_id ) {
        return new WP_Error( 'missing_params', __( 'Missing business_id for food menus.', 'lealez' ) );
    }

    // Normalizar account_id a numérico
    $account_id_normalized = self::extract_account_id_from_name( (string) $account_id );
    if ( '' === $account_id_normalized ) {
        $account_id_normalized = trim( (string) $account_id, '/' );
    }

    // Normalizar location_id a numérico
    $location_id_normalized = self::extract_location_id_from_any( (string) $location_id );
    if ( '' === $location_id_normalized ) {
        $location_id_normalized = trim( (string) $location_id, '/' );
    }

    if ( '' === $account_id_normalized || '' === $location_id_normalized ) {
        return new WP_Error(
            'missing_params',
            __( 'Missing/invalid accountId or locationId for food menus.', 'lealez' ),
            array(
                'account_id_raw'        => $account_id,
                'account_id_normalized' => $account_id_normalized,
                'location_id_raw'       => $location_id,
                'location_id_normalized'=> $location_id_normalized,
            )
        );
    }

    $endpoint = '/accounts/' . rawurlencode( $account_id_normalized )
                . '/locations/' . rawurlencode( $location_id_normalized )
                . '/foodMenus';

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'info',
            'Fetching foodMenus (GMB API v4).',
            array(
                'account_id'    => $account_id_normalized,
                'location_id'   => $location_id_normalized,
                'endpoint'      => self::$mybusiness_v4_base . $endpoint,
                'force_refresh' => $force_refresh ? 'yes' : 'no',
            )
        );
    }

    $result = self::make_request(
        $business_id,
        $endpoint,
        self::$mybusiness_v4_base,
        'GET',
        array(),
        ! $force_refresh,
        array()
    );

    if ( is_wp_error( $result ) ) {
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'error',
                'get_location_food_menus failed: ' . $result->get_error_message(),
                array(
                    'account_id'  => $account_id_normalized,
                    'location_id' => $location_id_normalized,
                    'error_code'  => $result->get_error_code(),
                    'hint'        => 'El endpoint /foodMenus requiere que el negocio esté categorizado como restaurante en GMB y que el token tenga scope https://www.googleapis.com/auth/business.manage.',
                )
            );
        }
        return $result;
    }

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        $menus_count = ! empty( $result['menus'] ) && is_array( $result['menus'] ) ? count( $result['menus'] ) : 0;
        Lealez_GMB_Logger::log(
            $business_id,
            'success',
            sprintf( 'foodMenus fetched: %d menu(s) found.', $menus_count ),
            array(
                'account_id'  => $account_id_normalized,
                'location_id' => $location_id_normalized,
            )
        );
    }

return is_array( $result ) ? $result : array();
}


/**
 * Obtiene el catálogo de servicios/productos de una ubicación via Business Information API v1.
 *
 * Usa get_location_services() que ahora llama al endpoint correcto:
 *   GET /v1/locations/{locationId}?readMask=serviceItems
 *
 * @param int    $business_id   WP Post ID del oy_business.
 * @param string $account_id    Account ID (se pasa a get_location_services).
 * @param string $location_id   Location ID numérico, "locations/{id}", o resource name completo.
 * @param bool   $force_refresh Si true, ignora caché.
 * @return array|WP_Error  Array con serviceItems en caso de éxito.
 */
public static function get_location_products( $business_id, $account_id, $location_id, $force_refresh = false ) {
    $business_id = absint( $business_id );

    if ( ! $business_id ) {
        return new WP_Error( 'missing_params', __( 'Missing business_id for products.', 'lealez' ) );
    }

    if ( '' === trim( (string) $location_id ) ) {
        return new WP_Error(
            'missing_params',
            __( 'Missing/invalid locationId for products.', 'lealez' ),
            array(
                'location_id_raw' => $location_id,
            )
        );
    }

    /**
     * Delegar a get_location_services(), que usa Business Information API v1
     * con readMask=serviceItems — el endpoint correcto según la documentación.
     */
    $service_list = self::get_location_services(
        $business_id,
        $account_id,
        $location_id,
        $force_refresh
    );

    // Error real de API
    if ( is_wp_error( $service_list ) ) {
        $err_data  = $service_list->get_error_data();
        $http_code = is_array( $err_data ) ? (int) ( $err_data['code'] ?? 0 ) : 0;

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                '[Lealez GMB] get_location_products — error desde get_location_services: HTTP ' .
                $http_code . ' — ' . $service_list->get_error_message()
            );
        }

        return new WP_Error(
            'products_api_error',
            sprintf(
                __( 'Error al consultar servicios de Google Business Profile (HTTP %1$d): %2$s', 'lealez' ),
                $http_code,
                $service_list->get_error_message()
            ),
            array(
                'code'        => $http_code,
                'location_id' => $location_id,
                'source'      => 'business_information_api_v1',
                'previous'    => array(
                    'error_code' => $service_list->get_error_code(),
                    'message'    => $service_list->get_error_message(),
                    'data'       => $service_list->get_error_data(),
                ),
            )
        );
    }

    /**
     * La ubicación respondió pero no tiene serviceItems.
     * Puede ser que canModifyServiceList=false para esta categoría de negocio,
     * o que aún no tenga servicios configurados en GBP.
     */
    if ( empty( $service_list['serviceItems'] ) ) {
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                'get_location_products: la ubicación no tiene serviceItems en su perfil de GBP.',
                array( 'location_id' => $location_id )
            );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Lealez GMB] get_location_products — sin serviceItems para esta ubicación.' );
        }

        return new WP_Error(
            'no_service_list_yet',
            __( 'Esta ubicación aún no tiene servicios configurados en Google Business Profile, o la categoría del negocio no soporta la lista de servicios (canModifyServiceList=false).', 'lealez' ),
            array(
                'code'        => 404,
                'location_id' => $location_id,
            )
        );
    }

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'success',
            sprintf(
                'get_location_products OK via Business Information API v1: %d serviceItem(s) encontrado(s).',
                count( $service_list['serviceItems'] )
            ),
            array(
                'location_id'    => $location_id,
                'response_keys'  => array_keys( $service_list ),
            )
        );
    }

    return $service_list;
}


/**
 * Obtiene el catálogo de productos de una ubicación desde Google My Business API v4.
 *
 * Para negocios NO restaurante, usa /serviceList como fuente oficial.
 * El antiguo endpoint /products fue eliminado por Google sin reemplazo en v1.
 *
 * Códigos de error que puede devolver:
 *  - 'no_service_list_yet'   : 404 de serviceList — sin datos aún, no es error técnico.
 *  - 'products_api_error'    : error real de API (401, 403, 500, etc.).
 *  - 'missing_params'        : parámetros incompletos.
 *
 * @param int    $business_id   WP Post ID del oy_business.
 * @param string $account_id    Account ID numérico, "accounts/{id}", o resource name completo.
 * @param string $location_id   Location ID numérico, "locations/{id}", o resource name completo.
 * @param bool   $force_refresh Si true, ignora caché del rate limiter.
 * @return array|WP_Error  Array con serviceItems en caso de éxito.
 */
public static function get_location_products( $business_id, $account_id, $location_id, $force_refresh = false ) {
    $business_id = absint( $business_id );

    if ( ! $business_id ) {
        return new WP_Error( 'missing_params', __( 'Missing business_id for products.', 'lealez' ) );
    }

    // Normalizar account_id a numérico
    $account_id_normalized = self::extract_account_id_from_name( (string) $account_id );
    if ( '' === $account_id_normalized ) {
        $account_id_normalized = trim( (string) $account_id, '/' );
    }

    // Normalizar location_id a numérico
    $location_id_normalized = self::extract_location_id_from_any( (string) $location_id );
    if ( '' === $location_id_normalized ) {
        $location_id_normalized = trim( (string) $location_id, '/' );
    }

    if ( '' === $account_id_normalized || '' === $location_id_normalized ) {
        return new WP_Error(
            'missing_params',
            __( 'Missing/invalid accountId or locationId for products.', 'lealez' ),
            array(
                'account_id_raw'         => $account_id,
                'account_id_normalized'  => $account_id_normalized,
                'location_id_raw'        => $location_id,
                'location_id_normalized' => $location_id_normalized,
            )
        );
    }

    /**
     * El endpoint /products fue eliminado por Google sin reemplazo público en v1.
     * Para negocios no-restaurante, la única fuente oficial accesible es /serviceList.
     * Para restaurantes existe /foodMenus (gestionado por el metabox de Menú).
     */
    $service_list = self::get_location_services(
        $business_id,
        $account_id_normalized,
        $location_id_normalized,
        $force_refresh
    );

    // Error real de API (401, 403, 500, etc.) — no un 404 de "sin datos"
    if ( is_wp_error( $service_list ) ) {
        $err_data  = $service_list->get_error_data();
        $http_code = is_array( $err_data ) ? (int) ( $err_data['code'] ?? 0 ) : 0;

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log(
                '[Lealez GMB] get_location_products — error real de API desde serviceList: HTTP ' .
                $http_code . ' — ' . $service_list->get_error_message()
            );
        }

        return new WP_Error(
            'products_api_error',
            sprintf(
                /* translators: 1: HTTP code, 2: error message */
                __( 'Error al consultar Google Business Profile (HTTP %1$d): %2$s', 'lealez' ),
                $http_code,
                $service_list->get_error_message()
            ),
            array(
                'code'        => $http_code,
                'account_id'  => $account_id_normalized,
                'location_id' => $location_id_normalized,
                'source'      => 'serviceList',
                'previous'    => array(
                    'error_code' => $service_list->get_error_code(),
                    'message'    => $service_list->get_error_message(),
                    'data'       => $service_list->get_error_data(),
                ),
            )
        );
    }

    /**
     * get_location_services() devolvió array con flag _gmb_no_service_list.
     * Significa que Google respondió 404 — la ubicación aún no tiene
     * una lista de servicios configurada en GBP.
     * NO es un error técnico — es ausencia de datos.
     */
    if ( ! empty( $service_list['_gmb_no_service_list'] ) ) {
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                'get_location_products: la ubicación aún no tiene serviceList en GBP (404). El catálogo se gestiona manualmente en Lealez.',
                array(
                    'account_id'  => $account_id_normalized,
                    'location_id' => $location_id_normalized,
                )
            );
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Lealez GMB] get_location_products — no_service_list_yet: la ubicación no tiene serviceList configurado en GBP.' );
        }

        return new WP_Error(
            'no_service_list_yet',
            __( 'Esta ubicación aún no tiene servicios configurados en Google Business Profile. Si tienes servicios o productos para mostrar, agrégalos en Lealez y sincroniza.', 'lealez' ),
            array(
                'code'        => 404,
                'account_id'  => $account_id_normalized,
                'location_id' => $location_id_normalized,
            )
        );
    }

    // Éxito — limpiar flag interno si existiera por alguna razón
    unset( $service_list['_gmb_no_service_list'] );

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        $service_items_count = 0;

        if ( ! empty( $service_list['serviceItems'] ) && is_array( $service_list['serviceItems'] ) ) {
            $service_items_count = count( $service_list['serviceItems'] );
        } elseif ( ! empty( $service_list['sections'] ) && is_array( $service_list['sections'] ) ) {
            foreach ( $service_list['sections'] as $section ) {
                $service_items_count += count( $section['serviceItems'] ?? array() );
                $service_items_count += count( $section['items'] ?? array() );
            }
        }

        Lealez_GMB_Logger::log(
            $business_id,
            'success',
            sprintf(
                'get_location_products OK via serviceList: %d item(s) encontrado(s).',
                $service_items_count
            ),
            array(
                'account_id'    => $account_id_normalized,
                'location_id'   => $location_id_normalized,
                'response_keys' => is_array( $service_list ) ? array_keys( $service_list ) : array(),
            )
        );
    }

    /**
     * Devolvemos el payload tal cual para que el metabox lo mapee.
     * El metabox entiende serviceList/serviceItems.
     */
    return is_array( $service_list ) ? $service_list : array();
}


    /**
     * Clear cache for a business
     *
     * @param int $business_id Business post ID
     * @return void
     */
public static function clear_business_cache( $business_id, $preserve_rate_limit = false ) {
    $business_id = absint( $business_id );

    // ✅ IMPORTANTE: si hubo un lock pegado, lo borramos aquí
    delete_transient( 'lealez_gmb_sync_lock_' . $business_id );

    // Flags de sync
    delete_post_meta( $business_id, '_gmb_sync_started_at' );
    delete_post_meta( $business_id, '_gmb_sync_last_activity' );

    // Timestamps / flags
    delete_post_meta( $business_id, '_gmb_accounts_last_fetch' );
    delete_post_meta( $business_id, '_gmb_locations_last_fetch' );
    delete_post_meta( $business_id, '_gmb_last_manual_refresh' );

    if ( ! $preserve_rate_limit ) {
        delete_post_meta( $business_id, '_gmb_last_rate_limit' );
    }

    // Post-connect cooldown / schedule
    delete_post_meta( $business_id, '_gmb_post_connect_cooldown_until' );
    delete_post_meta( $business_id, '_gmb_next_scheduled_refresh' );

    // Persistent cached data
    delete_post_meta( $business_id, '_gmb_accounts' );
    delete_post_meta( $business_id, '_gmb_total_accounts' );
    delete_post_meta( $business_id, '_gmb_account_name' );

    delete_post_meta( $business_id, '_gmb_locations_available' );
    delete_post_meta( $business_id, '_gmb_total_locations_available' );

    // ✅ NUEVO: conteo de ubicaciones por cuenta (para el metabox "Google My Business")
    delete_post_meta( $business_id, '_gmb_locations_count_by_account' );

    delete_post_meta( $business_id, '_gmb_last_sync_errors' );

    // Transient cache (compat)
    $cache_key = md5( $business_id . '/accounts' );
    if ( class_exists( 'Lealez_GMB_Rate_Limiter' ) ) {
        Lealez_GMB_Rate_Limiter::clear_cache( $cache_key );
    }
}



/**
     * ✅ Geocodificar una dirección usando Nominatim (OpenStreetMap) con multi-intento.
     *
     * Problemas conocidos con el approach anterior (parámetro `q` único):
     * - El formato colombiano "Carrera 54 #46-17" no lo entiende Nominatim con `q`.
     * - Nominatim ignora el `#` o retorna 0 resultados para nomenclaturas latinas.
     *
     * Solución: usar la API ESTRUCTURADA de Nominatim (street + city + country separados).
     * Ref: https://nominatim.org/release-docs/latest/api/Search/#structured-query
     *
     * Estrategia de intentos:
     *   1. Nominatim estructurado: calle (limpia) + ciudad + país
     *   2. Nominatim estructurado: ciudad + estado + país (sin calle — más permisivo)
     *
     * @param array $address_data PostalAddress de Google:
     *                            ['addressLines' => [...], 'locality' => ..., 'regionCode' => ...]
     * @return array|null ['latitude' => float, 'longitude' => float, 'source' => string] o null.
     */
    public static function geocode_address_fallback( array $address_data ) {
        if ( empty( $address_data ) ) {
            return null;
        }

        // ── Extraer componentes de PostalAddress ──────────────────────────────────
        $raw_street = '';
        if ( ! empty( $address_data['addressLines'] ) && is_array( $address_data['addressLines'] ) ) {
            $raw_street = trim( (string) ( $address_data['addressLines'][0] ?? '' ) );
        }

        $city    = trim( (string) ( $address_data['locality']           ?? '' ) );
        $state   = trim( (string) ( $address_data['administrativeArea'] ?? '' ) );
        $country = strtoupper( trim( (string) ( $address_data['regionCode'] ?? '' ) ) );
        $postal  = trim( (string) ( $address_data['postalCode']         ?? '' ) );

        // Mínimo requerido: ciudad + país
        if ( '' === $city || '' === $country ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] geocode_address_fallback — faltan ciudad y/o país, no se puede geocodificar.' );
            }
            return null;
        }

        // ── Limpiar calle para formato colombiano/latinoamericano ─────────────────
        // "Carrera 54 #46-17" → "Carrera 54 46-17"  (Nominatim no entiende el #)
        $clean_street = preg_replace( '/\s*#\s*/', ' ', $raw_street );
        $clean_street = preg_replace( '/\s{2,}/', ' ', $clean_street );
        $clean_street = trim( $clean_street );

        // ── Cache key (usa prefijo geo2_ para no colisionar con caché viejo de geo_) ──
        $cache_raw = implode( '|', array_filter( array( $clean_street, $city, $state, $country ) ) );
        $cache_key = 'lealez_geo2_' . md5( strtolower( $cache_raw ) );

        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['latitude'], $cached['longitude'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] geocode_address_fallback — resultado desde caché (geo2) para: ' . $cache_raw );
            }
            return $cached;
        }

        $nominatim_base    = 'https://nominatim.openstreetmap.org/search';
        $nominatim_headers = array(
            'User-Agent' => 'Lealez-WP-Plugin/1.0 (WordPress loyalty plugin; admin@lealez.app)',
        );

        // ── Intento 1: Nominatim ESTRUCTURADO — calle + ciudad + país ─────────────
        // Usar parámetros separados en vez de `q` es mucho más confiable para
        // nomenclaturas latinoamericanas (Carrera, Calle, Avenida, etc.).
        if ( '' !== $clean_street ) {
            $params1 = array_filter( array(
                'street'     => $clean_street,
                'city'       => $city,
                'state'      => $state,
                'country'    => $country,
                'postalcode' => $postal,
                'format'     => 'json',
                'limit'      => '1',
            ) );

            $resp1  = wp_remote_get(
                add_query_arg( $params1, $nominatim_base ),
                array( 'timeout' => 10, 'headers' => $nominatim_headers )
            );
            $coords = self::_parse_nominatim_response( $resp1, 'nominatim_structured_street', $cache_key );
            if ( $coords ) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log( sprintf(
                        '[OY Location] geocode_address_fallback — Intento 1 OK (nominatim_structured_street): lat=%s, lng=%s para "%s"',
                        $coords['latitude'], $coords['longitude'], $cache_raw
                    ) );
                }
                return $coords;
            }

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] geocode_address_fallback — Intento 1 sin resultados para: ' . $clean_street . ', ' . $city . ', ' . $country );
            }
        }

        // ── Intento 2: Nominatim ESTRUCTURADO — solo ciudad + estado + país ────────
        // Omitimos la calle para ser más permisivos. Retorna el centroide de la ciudad.
        // Útil cuando la calle no está en OSM o el formato no es reconocible.
        $params2 = array_filter( array(
            'city'    => $city,
            'state'   => $state,
            'country' => $country,
            'format'  => 'json',
            'limit'   => '1',
        ) );

        $resp2  = wp_remote_get(
            add_query_arg( $params2, $nominatim_base ),
            array( 'timeout' => 10, 'headers' => $nominatim_headers )
        );
        $coords = self::_parse_nominatim_response( $resp2, 'nominatim_structured_city', $cache_key );
        if ( $coords ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[OY Location] geocode_address_fallback — Intento 2 OK (nominatim_structured_city): lat=%s, lng=%s para "%s, %s"',
                    $coords['latitude'], $coords['longitude'], $city, $country
                ) );
            }
            return $coords;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY Location] geocode_address_fallback — todos los intentos fallaron para: ' . $cache_raw );
        }

        return null;
    }

    /**
     * Helper: parsear respuesta de Nominatim y devolver array de coords o null.
     *
     * @param array|WP_Error $response     Respuesta de wp_remote_get().
     * @param string         $source_label Etiqueta de fuente para debug.
     * @param string         $cache_key    Clave de transient para guardar resultado exitoso.
     *
     * @return array|null ['latitude', 'longitude', 'source'] o null si no hay resultado.
     */
    private static function _parse_nominatim_response( $response, $source_label, $cache_key ) {
        if ( is_wp_error( $response ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] _parse_nominatim_response (' . $source_label . ') — WP_Error: ' . $response->get_error_message() );
            }
            return null;
        }

        $http_code = (int) wp_remote_retrieve_response_code( $response );
        if ( 200 !== $http_code ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] _parse_nominatim_response (' . $source_label . ') — HTTP ' . $http_code );
            }
            return null;
        }

        $data = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( ! is_array( $data ) || empty( $data ) ) {
            return null;  // Array vacío = sin resultados, no se loguea individualmente
        }

        $first = $data[0];

        if ( empty( $first['lat'] ) || empty( $first['lon'] ) ) {
            return null;
        }

        $result = array(
            'latitude'  => (float) $first['lat'],
            'longitude' => (float) $first['lon'],
            'source'    => $source_label,
        );

        // Guardar en transient (1 hora)
        set_transient( $cache_key, $result, HOUR_IN_SECONDS );

        return $result;
}

    /**
     * ✅ Geocodificar usando googleLocations:search de la Business Information API v1.
     *
     * Este endpoint usa las mismas credenciales OAuth del negocio y devuelve las
     * coordenadas exactas que Google Maps tiene internamente para el negocio —
     * incluyendo negocios cuyo campo `latlng` no está explícitamente en el recurso.
     *
     * Es mucho más preciso que Nominatim para negocios latinoamericanos porque usa
     * los propios datos internos de Google Maps.
     *
     * Referencia oficial:
     * https://developers.google.com/my-business/reference/businessinformation/rest/v1/googleLocations/search
     *
     * @param int    $business_id   WP post ID del oy_business (para OAuth).
     * @param string $title         Nombre del negocio (location title del API).
     * @param array  $address_data  storefrontAddress (PostalAddress de Google).
     * @param string $phone         Teléfono principal opcional (mejora precisión).
     *
     * @return array|null ['latitude' => float, 'longitude' => float, 'source' => string] o null.
     */
    public static function geocode_via_google_locations_search( $business_id, $title, $address_data = array(), $phone = '' ) {
        $business_id = absint( $business_id );
        $title       = trim( (string) $title );

        if ( ! $business_id ) {
            return null;
        }

        if ( '' === $title && empty( $address_data ) ) {
            return null;
        }

        // ── Cache key (geo3_ para separarlo de los anteriores) ───────────────────
        $cache_raw = implode( '|', array_filter( array(
            $title,
            isset( $address_data['addressLines'][0] ) ? (string) $address_data['addressLines'][0] : '',
            isset( $address_data['locality'] )        ? (string) $address_data['locality']        : '',
            isset( $address_data['regionCode'] )      ? (string) $address_data['regionCode']      : '',
        ) ) );
        $cache_key = 'lealez_geo3_' . md5( strtolower( $cache_raw ) );

        $cached = get_transient( $cache_key );
        if ( is_array( $cached ) && isset( $cached['latitude'], $cached['longitude'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] geocode_via_google_locations_search — desde caché (geo3) para: ' . $cache_raw );
            }
            return $cached;
        }

        // ── Construir cuerpo del request ─────────────────────────────────────────
        $location_body = array();

        if ( '' !== $title ) {
            $location_body['title'] = $title;
        }

        if ( ! empty( $address_data ) && is_array( $address_data ) ) {
            $location_body['storefrontAddress'] = $address_data;
        }

        if ( '' !== trim( (string) $phone ) ) {
            $location_body['phoneNumbers'] = array(
                'primaryPhone' => trim( (string) $phone ),
            );
        }

        if ( empty( $location_body ) ) {
            return null;
        }

        // ── Llamar al endpoint POST /v1/googleLocations:search ───────────────────
        $result = self::make_request(
            $business_id,
            '/googleLocations:search',
            self::$business_api_base,
            'POST',
            array( 'location' => $location_body ),
            false  // no usar caché de make_request; el caché lo manejamos aquí
        );

        if ( is_wp_error( $result ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] geocode_via_google_locations_search — WP_Error: ' . $result->get_error_message() . ' para: ' . $cache_raw );
            }
            return null;
        }

        if ( empty( $result['googleLocations'] ) || ! is_array( $result['googleLocations'] ) ) {
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[OY Location] geocode_via_google_locations_search — sin resultados googleLocations para: ' . $cache_raw );
            }
            return null;
        }

        // ── Iterar resultados y tomar el primero con latlng válido ───────────────
        // El primer resultado suele ser el más relevante (Google lo ordena por relevancia).
        foreach ( $result['googleLocations'] as $gl ) {
            if ( ! is_array( $gl ) ) {
                continue;
            }

            $gl_lat = isset( $gl['location']['latlng']['latitude'] )
                ? (float) $gl['location']['latlng']['latitude']
                : null;
            $gl_lng = isset( $gl['location']['latlng']['longitude'] )
                ? (float) $gl['location']['latlng']['longitude']
                : null;

            if ( null === $gl_lat || null === $gl_lng ) {
                continue;
            }

            // Descartar (0, 0) — coordenada inválida
            if ( 0.0 === $gl_lat && 0.0 === $gl_lng ) {
                continue;
            }

            $coords = array(
                'latitude'  => $gl_lat,
                'longitude' => $gl_lng,
                'source'    => 'googleLocations_search',
            );

            // Guardar en caché 2 horas (coordenadas de Google son muy estables)
            set_transient( $cache_key, $coords, 2 * HOUR_IN_SECONDS );

            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[OY Location] geocode_via_google_locations_search — OK: lat=%s, lng=%s (fuente: %s) para "%s"',
                    $gl_lat,
                    $gl_lng,
                    isset( $gl['location']['name'] ) ? (string) $gl['location']['name'] : 'unknown',
                    $cache_raw
                ) );
            }

            return $coords;
        }

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[OY Location] geocode_via_google_locations_search — resultados sin latlng válido para: ' . $cache_raw );
        }

        return null;
    }

// =========================================================================
    // LOCAL POSTS (GBP Posts / Publicaciones) — My Business API v4
    // =========================================================================

    /**
     * Obtiene la lista de publicaciones (localPosts) de una ubicación via GMB API v4.
     *
     * Endpoint: GET https://mybusiness.googleapis.com/v4/accounts/{accountId}/locations/{locationId}/localPosts
     *
     * @param int    $business_id      ID del post oy_business (para tokens y caché)
     * @param string $location_any     Resource name "accounts/X/locations/Y" o solo ID numérico
     * @param bool   $use_cache        Si true, usa transient de 1 hora; false fuerza llamada a API
     * @return array|WP_Error          Array con 'localPosts' y 'total', o WP_Error en caso de fallo
     */
    public static function get_location_local_posts( $business_id, $location_any, $use_cache = true ) {
        $business_id  = absint( $business_id );
        $location_any = trim( (string) $location_any );

        if ( ! $business_id || empty( $location_any ) ) {
            return new WP_Error( 'missing_params', __( 'Missing business_id or location identifier for local posts.', 'lealez' ) );
        }

        $account_id  = self::extract_account_id_from_location_name( $location_any );
        $location_id = self::extract_location_id_from_any( $location_any );

        if ( '' === $account_id && '' !== $location_id ) {
            $resolved = self::resolve_account_resource_name_for_location( $business_id, $location_id, $location_any );
            if ( '' !== $resolved ) {
                $account_id = self::extract_account_id_from_name( $resolved );
            }
        }

        if ( '' === $account_id || '' === $location_id ) {
            return new WP_Error(
                'missing_params',
                __( 'Could not resolve accountId or locationId for local posts.', 'lealez' ),
                array(
                    'location_any'  => $location_any,
                    'account_id'    => $account_id,
                    'location_id'   => $location_id,
                )
            );
        }

        $cache_key    = 'oy_local_posts_' . $business_id . '_' . md5( $account_id . $location_id );
        $cache_expiry = HOUR_IN_SECONDS;

        if ( $use_cache ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log( $business_id, 'success', 'Local Posts: using transient cache.' );
                }
                return $cached;
            }
        }

        $endpoint   = '/accounts/' . rawurlencode( $account_id ) . '/locations/' . rawurlencode( $location_id ) . '/localPosts';
        $all_posts  = array();
        $page_token = '';
        $loops      = 0;

        do {
            $loops++;
            if ( $loops > 20 ) { break; }

            $query_args = array( 'pageSize' => 20 );
            if ( ! empty( $page_token ) ) {
                $query_args['pageToken'] = $page_token;
            }

            $result = self::make_request(
                $business_id,
                $endpoint,
                self::$mybusiness_v4_base,
                'GET',
                array(),
                false,
                $query_args
            );

            if ( is_wp_error( $result ) ) {
                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log( $business_id, 'warning', 'Local Posts API error.', array(
                        'account_id'  => $account_id,
                        'location_id' => $location_id,
                        'error'       => $result->get_error_message(),
                        'error_code'  => $result->get_error_code(),
                    ) );
                }
                update_post_meta( $business_id, '_gmb_local_posts_last_error', array(
                    'error'     => $result->get_error_message(),
                    'code'      => $result->get_error_code(),
                    'timestamp' => time(),
                ) );
                return $result;
            }

            $page_data  = is_array( $result ) ? $result : array();
            $page_posts = $page_data['localPosts'] ?? array();
            if ( is_array( $page_posts ) && ! empty( $page_posts ) ) {
                $all_posts = array_merge( $all_posts, $page_posts );
            }
            $page_token = $page_data['nextPageToken'] ?? '';

        } while ( ! empty( $page_token ) );

        $response = array(
            'localPosts' => $all_posts,
            'total'      => count( $all_posts ),
        );

        set_transient( $cache_key, $response, $cache_expiry );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'success', 'Local Posts: fetched ' . count( $all_posts ) . ' posts.' );
        }

        return $response;
    }

    /**
     * Crea una nueva publicación (localPost) en GMB via API v4.
     *
     * Endpoint: POST https://mybusiness.googleapis.com/v4/accounts/{accountId}/locations/{locationId}/localPosts
     *
     * @param int    $business_id  ID del post oy_business
     * @param string $location_any Resource name o ID numérico de la ubicación
     * @param array  $payload      Cuerpo del localPost (topicType, summary, callToAction, media, event, offer…)
     * @return array|WP_Error      El localPost creado, o WP_Error
     */
    public static function create_location_local_post( $business_id, $location_any, array $payload ) {
        $business_id  = absint( $business_id );
        $location_any = trim( (string) $location_any );

        if ( ! $business_id || empty( $location_any ) || empty( $payload ) ) {
            return new WP_Error( 'missing_params', __( 'Missing params for create local post.', 'lealez' ) );
        }

        $account_id  = self::extract_account_id_from_location_name( $location_any );
        $location_id = self::extract_location_id_from_any( $location_any );

        if ( '' === $account_id && '' !== $location_id ) {
            $resolved = self::resolve_account_resource_name_for_location( $business_id, $location_id, $location_any );
            if ( '' !== $resolved ) {
                $account_id = self::extract_account_id_from_name( $resolved );
            }
        }

        if ( '' === $account_id || '' === $location_id ) {
            return new WP_Error( 'missing_params', __( 'Could not resolve accountId/locationId for create local post.', 'lealez' ) );
        }

        $endpoint = '/accounts/' . rawurlencode( $account_id ) . '/locations/' . rawurlencode( $location_id ) . '/localPosts';

        $result = self::make_request(
            $business_id,
            $endpoint,
            self::$mybusiness_v4_base,
            'POST',
            $payload,
            false
        );

        if ( is_wp_error( $result ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'error', 'Create Local Post failed.', array(
                    'error'   => $result->get_error_message(),
                    'payload' => $payload,
                ) );
            }
            return $result;
        }

        $cache_key = 'oy_local_posts_' . $business_id . '_' . md5( $account_id . $location_id );
        delete_transient( $cache_key );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'success', 'Local Post created: ' . ( $result['name'] ?? 'unknown' ) );
        }

        return is_array( $result ) ? $result : array();
    }

    /**
     * Elimina una publicación (localPost) de GMB via API v4.
     *
     * Endpoint: DELETE https://mybusiness.googleapis.com/v4/accounts/{accountId}/locations/{locationId}/localPosts/{localPostId}
     *
     * @param int    $business_id  ID del post oy_business
     * @param string $location_any Resource name o ID numérico de la ubicación
     * @param string $post_name    Resource name completo del localPost ("accounts/X/locations/Y/localPosts/Z")
     * @return true|WP_Error       true en éxito, WP_Error en fallo
     */
    public static function delete_location_local_post( $business_id, $location_any, $post_name ) {
        $business_id  = absint( $business_id );
        $location_any = trim( (string) $location_any );
        $post_name    = trim( (string) $post_name );

        if ( ! $business_id || empty( $location_any ) || empty( $post_name ) ) {
            return new WP_Error( 'missing_params', __( 'Missing params for delete local post.', 'lealez' ) );
        }

        if ( false === strpos( $post_name, '/localPosts/' ) ) {
            return new WP_Error( 'invalid_post_name', __( 'Invalid localPost resource name.', 'lealez' ) );
        }

        $account_id  = self::extract_account_id_from_location_name( $location_any );
        $location_id = self::extract_location_id_from_any( $location_any );

        if ( '' === $account_id && '' !== $location_id ) {
            $resolved = self::resolve_account_resource_name_for_location( $business_id, $location_id, $location_any );
            if ( '' !== $resolved ) {
                $account_id = self::extract_account_id_from_name( $resolved );
            }
        }

        $post_name_clean = ltrim( $post_name, '/' );
        $endpoint        = '/' . $post_name_clean;

        $result = self::make_request(
            $business_id,
            $endpoint,
            self::$mybusiness_v4_base,
            'DELETE',
            array(),
            false
        );

        if ( is_wp_error( $result ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'error', 'Delete Local Post failed.', array(
                    'post_name' => $post_name,
                    'error'     => $result->get_error_message(),
                ) );
            }
            return $result;
        }

        if ( '' !== $account_id && '' !== $location_id ) {
            $cache_key = 'oy_local_posts_' . $business_id . '_' . md5( $account_id . $location_id );
            delete_transient( $cache_key );
        }

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'success', 'Local Post deleted: ' . $post_name );
        }

        return true;
    }


    // =========================================================================
    // REVIEWS – Google My Business API v4
    // =========================================================================

/**
     * Obtiene reseñas de una ubicación GMB (Google My Business API v4).
     *
     * Soporta paginación manual mediante $page_token.
     * La primera llamada sin page_token usa caché de 30 min (configurable).
     *
     * @param int    $business_id  ID del post oy_business
     * @param string $location_any Resource name completo o location_id numérico
     * @param string $page_token   Token de paginación ('' para primera página)
     * @param int    $page_size    Máx reseñas por página (1-50, defecto 50)
     * @param string $order_by     Campo de ordenamiento. Valores válidos GMB v4:
     *                             'updateTime desc' (más reciente), 'rating', 'rating desc'
     *                             NOTA: El campo en la API se llama 'updateTime', NO 'updateTimestamp'.
     * @param bool   $use_cache    Si usar transient cache (ignorado cuando hay page_token)
     * @return array|WP_Error      Estructura: {reviews, averageRating, totalReviewCount, nextPageToken}
     */
    public static function get_location_reviews(
        $business_id,
        $location_any,
        $page_token  = '',
        $page_size   = 50,
        $order_by    = 'updateTime desc',
        $use_cache   = true
    ) {
        $business_id  = absint( $business_id );
        $location_any = trim( (string) $location_any );

        if ( ! $business_id || empty( $location_any ) ) {
            return new WP_Error( 'missing_params', __( 'Missing business_id or location for reviews.', 'lealez' ) );
        }

        $account_id  = self::extract_account_id_from_location_name( $location_any );
        $location_id = self::extract_location_id_from_any( $location_any );

        // Intentar resolver account_id si solo tenemos location_id (p.ej. "locations/12345")
        if ( '' === $account_id && '' !== $location_id ) {
            $resolved = self::resolve_account_resource_name_for_location( $business_id, $location_id, $location_any );
            if ( '' !== $resolved ) {
                $account_id = self::extract_account_id_from_name( $resolved );
            }
        }

        // ✅ WP_DEBUG: registrar IDs resueltos para diagnóstico
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( sprintf(
                '[Lealez Reviews] get_location_reviews | business_id=%d | location_any="%s" | account_id="%s" | location_id="%s" | order_by="%s"',
                $business_id,
                $location_any,
                $account_id,
                $location_id,
                $order_by
            ) );
        }

        if ( '' === $account_id || '' === $location_id ) {
            $err_data = array(
                'location_any' => $location_any,
                'account_id'   => $account_id,
                'location_id'  => $location_id,
            );
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( '[Lealez Reviews] ERROR - Cannot resolve account/location IDs: ' . wp_json_encode( $err_data ) );
            }
            return new WP_Error(
                'missing_params',
                __( 'Could not resolve accountId or locationId for reviews.', 'lealez' ),
                $err_data
            );
        }

        // Cache solo en primera página (sin page_token)
        $cache_key    = 'oy_reviews_' . $business_id . '_' . md5( $account_id . $location_id . $order_by );
        $cache_expiry = 30 * MINUTE_IN_SECONDS;

        if ( $use_cache && empty( $page_token ) ) {
            $cached = get_transient( $cache_key );
            if ( false !== $cached && is_array( $cached ) ) {
                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log( $business_id, 'success', 'Reviews: using transient cache.' );
                }
                return $cached;
            }
        }

        $page_size = max( 1, min( 50, absint( $page_size ) ) );

        /*
         * ✅ FIX CRÍTICO — Valores válidos de orderBy para GMB API v4 reviews.list:
         *
         * El recurso Review de GMB v4 tiene los campos:
         *   - createTime  → fecha de creación
         *   - updateTime  → fecha de última modificación (incluye respuestas)
         *
         * Valores VÁLIDOS según la documentación de Google:
         *   - 'updateTime desc'  → más reciente primero (campo: updateTime, NO updateTimestamp)
         *   - 'rating'           → menor rating primero (default)
         *   - 'rating desc'      → mayor rating primero
         *
         * INVÁLIDO (causa HTTP 400 INVALID_ARGUMENT):
         *   - 'updateTimestamp desc'  ← nombre de campo incorrecto
         *   - 'updateTimestamp asc'   ← nombre de campo incorrecto
         *   - 'updateTime asc'        ← dirección asc no soportada server-side
         *   - 'rating asc'            ← 'rating' ya es asc por defecto, no se acepta 'asc'
         *
         * El ordenamiento asc por fecha se aplica en el cliente (función sortList en JS).
         */
        $allowed_order_server = array( 'updateTime desc', 'rating', 'rating desc' );

        /*
         * ✅ FIX: Construir query string manualmente con rawurlencode().
         *
         * add_query_arg() usa http_build_query() que codifica espacios como '+'.
         * Google API v4 requiere '%20' (RFC 3986). Usamos rawurlencode().
         */
        $qs_parts = array();
        $qs_parts[] = 'pageSize=' . rawurlencode( (string) $page_size );

        if ( ! empty( $page_token ) ) {
            $qs_parts[] = 'pageToken=' . rawurlencode( $page_token );
        }

        // Solo enviar orderBy si es un valor soportado por el servidor
        // Si el frontend envía 'updateTimestamp desc' (valor legacy), lo mapeamos a 'updateTime desc'
        $server_order = $order_by;
        if ( 'updateTimestamp desc' === $server_order ) {
            $server_order = 'updateTime desc';
        } elseif ( 'updateTimestamp asc' === $server_order ) {
            // asc no soportado server-side; omitir (Google devuelve updateTime desc por defecto)
            $server_order = '';
        }

        if ( in_array( $server_order, $allowed_order_server, true ) ) {
            $qs_parts[] = 'orderBy=' . rawurlencode( $server_order );
        }
        // Si no es un valor soportado, se omite orderBy → Google usa 'updateTime desc' por defecto.

        $endpoint = '/accounts/' . rawurlencode( $account_id )
                  . '/locations/' . rawurlencode( $location_id )
                  . '/reviews'
                  . '?' . implode( '&', $qs_parts );

        // ✅ WP_DEBUG: registrar URL completa antes de llamar
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( '[Lealez Reviews] Calling GMB v4 URL: ' . self::$mybusiness_v4_base . $endpoint );
        }

        $result = self::make_request(
            $business_id,
            $endpoint,
            self::$mybusiness_v4_base,
            'GET',
            array(),
            false  // Cache controlado aquí, no en make_request
            // Sin $query_args: ya están embebidos en $endpoint con rawurlencode
        );

        if ( is_wp_error( $result ) ) {
            $err_data = $result->get_error_data();

            // ✅ WP_DEBUG: registrar error completo incluyendo respuesta raw de Google
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( sprintf(
                    '[Lealez Reviews] API error | code=%s | message=%s | http_code=%s | raw_body=%s',
                    $result->get_error_code(),
                    $result->get_error_message(),
                    ( is_array( $err_data ) && isset( $err_data['code'] ) ) ? $err_data['code'] : 'n/a',
                    ( is_array( $err_data ) && isset( $err_data['raw_body'] ) ) ? substr( (string) $err_data['raw_body'], 0, 1000 ) : 'n/a'
                ) );
            }

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'warning', 'Reviews API error.', array(
                    'account_id'  => $account_id,
                    'location_id' => $location_id,
                    'error'       => $result->get_error_message(),
                    'error_code'  => $result->get_error_code(),
                ) );
            }
            return $result;
        }

        $page_data = is_array( $result ) ? $result : array();

        $response = array(
            'reviews'          => isset( $page_data['reviews'] ) && is_array( $page_data['reviews'] )
                                    ? $page_data['reviews']
                                    : array(),
            'averageRating'    => $page_data['averageRating']    ?? null,
            'totalReviewCount' => $page_data['totalReviewCount'] ?? 0,
            'nextPageToken'    => $page_data['nextPageToken']     ?? '',
        );

        // Solo guarda en caché si es primera página
        if ( empty( $page_token ) ) {
            set_transient( $cache_key, $response, $cache_expiry );
        }

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'success',
                'Reviews: fetched ' . count( $response['reviews'] ) . ' reviews.'
                . ( ! empty( $response['nextPageToken'] ) ? ' (has next page)' : '' )
            );
        }

        return $response;
    }

    /**
     * Crea o actualiza la respuesta de propietario a una reseña.
     *
     * Endpoint: PUT https://mybusiness.googleapis.com/v4/accounts/{a}/locations/{l}/reviews/{r}/reply
     *
     * @param int    $business_id  ID del post oy_business
     * @param string $location_any Resource name o location_id de la ubicación
     * @param string $review_id    ID numérico de la reseña (último segmento del resource name)
     * @param string $comment      Texto de la respuesta (máx 4000 chars)
     * @param string $review_name  Resource name completo de la reseña (opcional, para mayor precisión)
     * @return array|WP_Error      ReviewReply actualizado {comment, updateTime} o WP_Error
     */
    public static function reply_to_review(
        $business_id,
        $location_any,
        $review_id,
        $comment,
        $review_name = ''
    ) {
        $business_id  = absint( $business_id );
        $location_any = trim( (string) $location_any );
        $review_id    = trim( (string) $review_id );
        $comment      = trim( (string) $comment );

        if ( ! $business_id || empty( $location_any ) || empty( $review_id ) || empty( $comment ) ) {
            return new WP_Error( 'missing_params', __( 'Missing params for reply to review.', 'lealez' ) );
        }

        if ( mb_strlen( $comment ) > 4000 ) {
            return new WP_Error( 'comment_too_long', __( 'Reply comment exceeds 4,000 characters.', 'lealez' ) );
        }

        $account_id  = self::extract_account_id_from_location_name( $location_any );
        $location_id = self::extract_location_id_from_any( $location_any );

        if ( '' === $account_id && '' !== $location_id ) {
            $resolved = self::resolve_account_resource_name_for_location( $business_id, $location_id, $location_any );
            if ( '' !== $resolved ) {
                $account_id = self::extract_account_id_from_name( $resolved );
            }
        }

        if ( '' === $account_id || '' === $location_id ) {
            return new WP_Error( 'missing_params', __( 'Could not resolve accountId/locationId for reply.', 'lealez' ) );
        }

        // Si tenemos el resource name completo de la reseña lo usamos directamente
        if ( ! empty( $review_name ) && false !== strpos( $review_name, '/reviews/' ) ) {
            $endpoint = '/' . ltrim( $review_name, '/' ) . '/reply';
        } else {
            $endpoint = '/accounts/' . rawurlencode( $account_id )
                      . '/locations/' . rawurlencode( $location_id )
                      . '/reviews/' . rawurlencode( $review_id )
                      . '/reply';
        }

        $result = self::make_request(
            $business_id,
            $endpoint,
            self::$mybusiness_v4_base,
            'PUT',
            array( 'comment' => $comment ),
            false
        );

        if ( is_wp_error( $result ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'error', 'Reply to review failed.', array(
                    'review_id' => $review_id,
                    'error'     => $result->get_error_message(),
                ) );
            }
            return $result;
        }

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'success', 'Review reply created/updated for review: ' . $review_id );
        }

        // Si la respuesta de la API es null/vacío (HTTP 200 sin body), construimos la respuesta
        if ( empty( $result ) || ! is_array( $result ) ) {
            $result = array(
                'comment'    => $comment,
                'updateTime' => gmdate( 'Y-m-d\TH:i:s\Z' ),
            );
        }

        return $result;
    }

    /**
     * Elimina la respuesta de propietario a una reseña.
     *
     * Endpoint: DELETE https://mybusiness.googleapis.com/v4/accounts/{a}/locations/{l}/reviews/{r}/reply
     *
     * @param int    $business_id  ID del post oy_business
     * @param string $location_any Resource name o location_id de la ubicación
     * @param string $review_id    ID numérico de la reseña
     * @param string $review_name  Resource name completo (opcional)
     * @return true|WP_Error
     */
    public static function delete_review_reply(
        $business_id,
        $location_any,
        $review_id,
        $review_name = ''
    ) {
        $business_id  = absint( $business_id );
        $location_any = trim( (string) $location_any );
        $review_id    = trim( (string) $review_id );

        if ( ! $business_id || empty( $location_any ) || empty( $review_id ) ) {
            return new WP_Error( 'missing_params', __( 'Missing params for delete review reply.', 'lealez' ) );
        }

        $account_id  = self::extract_account_id_from_location_name( $location_any );
        $location_id = self::extract_location_id_from_any( $location_any );

        if ( '' === $account_id && '' !== $location_id ) {
            $resolved = self::resolve_account_resource_name_for_location( $business_id, $location_id, $location_any );
            if ( '' !== $resolved ) {
                $account_id = self::extract_account_id_from_name( $resolved );
            }
        }

        if ( '' === $account_id || '' === $location_id ) {
            return new WP_Error( 'missing_params', __( 'Could not resolve accountId/locationId for delete reply.', 'lealez' ) );
        }

        if ( ! empty( $review_name ) && false !== strpos( $review_name, '/reviews/' ) ) {
            $endpoint = '/' . ltrim( $review_name, '/' ) . '/reply';
        } else {
            $endpoint = '/accounts/' . rawurlencode( $account_id )
                      . '/locations/' . rawurlencode( $location_id )
                      . '/reviews/' . rawurlencode( $review_id )
                      . '/reply';
        }

        $result = self::make_request(
            $business_id,
            $endpoint,
            self::$mybusiness_v4_base,
            'DELETE',
            array(),
            false
        );

        if ( is_wp_error( $result ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'error', 'Delete review reply failed.', array(
                    'review_id' => $review_id,
                    'error'     => $result->get_error_message(),
                ) );
            }
            return $result;
        }

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'success', 'Review reply deleted for review: ' . $review_id );
        }

        return true;
    }

/**
     * Invalida el transient de caché de reseñas para una ubicación.
     *
     * Llamar después de create/update/delete de una respuesta.
     *
     * @param int    $business_id
     * @param string $location_any
     */
    public static function clear_reviews_cache( $business_id, $location_any ) {
        $business_id  = absint( $business_id );
        $location_any = trim( (string) $location_any );

        if ( ! $business_id || empty( $location_any ) ) {
            return;
        }

        $account_id  = self::extract_account_id_from_location_name( $location_any );
        $location_id = self::extract_location_id_from_any( $location_any );

        if ( '' !== $account_id && '' !== $location_id ) {
            // ✅ FIX: Las claves de caché usan 'updateTime desc' (campo correcto de GMB v4).
            // Se mantienen las variantes legacy 'updateTimestamp ...' para limpiar cualquier
            // transient antiguo que pudiera existir en la base de datos.
            $sort_variants = array(
                'updateTime desc',      // ✅ Correcto — campo real de GMB v4
                'updateTime asc',       // variante cliente (misma key de caché que se genera)
                'rating desc',
                'rating asc',
                'rating',
                // Legacy (por si quedan transients de versiones anteriores):
                'updateTimestamp desc',
                'updateTimestamp asc',
            );
            foreach ( $sort_variants as $sort ) {
                $cache_key = 'oy_reviews_' . $business_id . '_' . md5( $account_id . $location_id . $sort );
                delete_transient( $cache_key );
            }
        }
    } // cierre clear_reviews_cache()

    // =========================================================================
    // Business Profile Performance API v1
    // https://developers.google.com/my-business/reference/performance/rest
    // =========================================================================

    /**
     * Recupera múltiples métricas diarias de rendimiento (Business Profile Performance API v1).
     *
     * Endpoint: GET /v1/{location}:fetchMultiDailyMetricsTimeSeries
     *
     * Métricas disponibles (DailyMetric enum):
     *   BUSINESS_IMPRESSIONS_DESKTOP_MAPS   — Vistas en Maps (escritorio)
     *   BUSINESS_IMPRESSIONS_DESKTOP_SEARCH — Vistas en Búsqueda (escritorio)
     *   BUSINESS_IMPRESSIONS_MOBILE_MAPS    — Vistas en Maps (móvil)
     *   BUSINESS_IMPRESSIONS_MOBILE_SEARCH  — Vistas en Búsqueda (móvil)
     *   BUSINESS_CONVERSATIONS              — Mensajes / Conversaciones
     *   BUSINESS_DIRECTION_REQUESTS         — Solicitudes de cómo llegar
     *   CALL_CLICKS                         — Clics en llamada telefónica
     *   WEBSITE_CLICKS                      — Clics en sitio web
     *   BUSINESS_BOOKINGS                   — Reservas
     *   BUSINESS_FOOD_ORDERS                — Pedidos de comida
     *   BUSINESS_FOOD_MENU_CLICKS           — Clics en menú
     *
     * @param int    $business_id   ID del post oy_business (propietario del OAuth).
     * @param string $location_name gmb_location_id (acepta: "locations/123", "accounts/x/locations/123", "123").
     * @param array  $metrics       Array de DailyMetric strings. Mínimo 1.
     * @param array  $start_date    ['year'=>int, 'month'=>int, 'day'=>int]
     * @param array  $end_date      ['year'=>int, 'month'=>int, 'day'=>int]
     * @param bool   $use_cache     Usar transient cache (TTL: 6 horas).
     * @return array|WP_Error       Array con 'multiDailyMetricTimeSeries' o WP_Error.
     */
    public static function get_location_performance_metrics(
        $business_id,
        $location_name,
        array $metrics,
        array $start_date,
        array $end_date,
        $use_cache = true
    ) {
        // ── Extract numeric location ID ──────────────────────────────────
        $location_id = self::extract_location_id_from_any( $location_name );

        if ( '' === $location_id ) {
            return new WP_Error(
                'invalid_location',
                __( 'No se pudo determinar el Location ID para la Performance API.', 'lealez' )
            );
        }

        // ── Validate metrics ─────────────────────────────────────────────
        $allowed_metrics = array(
            'BUSINESS_IMPRESSIONS_DESKTOP_MAPS',
            'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH',
            'BUSINESS_IMPRESSIONS_MOBILE_MAPS',
            'BUSINESS_IMPRESSIONS_MOBILE_SEARCH',
            'BUSINESS_CONVERSATIONS',
            'BUSINESS_DIRECTION_REQUESTS',
            'CALL_CLICKS',
            'WEBSITE_CLICKS',
            'BUSINESS_BOOKINGS',
            'BUSINESS_FOOD_ORDERS',
            'BUSINESS_FOOD_MENU_CLICKS',
        );

        $clean_metrics = array();
        foreach ( $metrics as $m ) {
            $m = strtoupper( trim( (string) $m ) );
            if ( in_array( $m, $allowed_metrics, true ) ) {
                $clean_metrics[] = $m;
            }
        }

        if ( empty( $clean_metrics ) ) {
            return new WP_Error( 'invalid_metrics', __( 'No se proporcionaron métricas válidas.', 'lealez' ) );
        }

        // ── Transient cache key ──────────────────────────────────────────
        $cache_key = 'oy_perf_multi_' . $business_id . '_' . md5(
            $location_id . serialize( $clean_metrics ) . serialize( $start_date ) . serialize( $end_date )
        );

        if ( $use_cache ) {
            $cached = get_transient( $cache_key );
            if ( ! empty( $cached ) ) {
                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log( $business_id, 'success', 'Performance metrics: cache hit for location ' . $location_id );
                }
                return $cached;
            }
        }

        // ── Build endpoint ───────────────────────────────────────────────
        // The Performance API requires repeated params: ?dailyMetrics=A&dailyMetrics=B
        $location_resource = 'locations/' . $location_id;
        $endpoint          = '/' . $location_resource . ':fetchMultiDailyMetricsTimeSeries';

        $qs_parts = array();
        foreach ( $clean_metrics as $metric ) {
            $qs_parts[] = 'dailyMetrics=' . rawurlencode( $metric );
        }

        $qs_parts[] = 'dailyRange.startDate.year='  . (int) ( $start_date['year']  ?? 0 );
        $qs_parts[] = 'dailyRange.startDate.month=' . (int) ( $start_date['month'] ?? 0 );
        $qs_parts[] = 'dailyRange.startDate.day='   . (int) ( $start_date['day']   ?? 0 );
        $qs_parts[] = 'dailyRange.endDate.year='    . (int) ( $end_date['year']    ?? 0 );
        $qs_parts[] = 'dailyRange.endDate.month='   . (int) ( $end_date['month']   ?? 0 );
        $qs_parts[] = 'dailyRange.endDate.day='     . (int) ( $end_date['day']     ?? 0 );

        $endpoint_with_qs = $endpoint . '?' . implode( '&', $qs_parts );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                sprintf(
                    'Performance API: fetching %d metrics for location %s [%s → %s]',
                    count( $clean_metrics ),
                    $location_id,
                    sprintf( '%04d-%02d-%02d', $start_date['year'] ?? 0, $start_date['month'] ?? 0, $start_date['day'] ?? 0 ),
                    sprintf( '%04d-%02d-%02d', $end_date['year'] ?? 0, $end_date['month'] ?? 0, $end_date['day'] ?? 0 )
                )
            );
        }

        // ── API Request ──────────────────────────────────────────────────
        $result = self::make_request(
            $business_id,
            $endpoint_with_qs,
            'https://businessprofileperformance.googleapis.com/v1',
            'GET',
            array(),
            false // cache gestionado arriba con transients
        );

        if ( is_wp_error( $result ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'error',
                    'Performance API error: ' . $result->get_error_message()
                );
            }
            return $result;
        }

        // ── Store and return ─────────────────────────────────────────────
        // TTL: 6 horas. Los datos de rendimiento tienen latencia de ~1-2 días en Google.
        set_transient( $cache_key, $result, 6 * HOUR_IN_SECONDS );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            $series_count = isset( $result['multiDailyMetricTimeSeries'] )
                ? count( $result['multiDailyMetricTimeSeries'] )
                : 0;
            Lealez_GMB_Logger::log(
                $business_id,
                'success',
                sprintf( 'Performance API: received %d time series for location %s', $series_count, $location_id )
            );
        }

        return $result;
    }

/**
 * Recupera las palabras clave de búsqueda mensual (Business Profile Performance API v1).
 *
 * Endpoint: GET /v1/{location}/searchkeywords/impressions/monthly
 *
 * Devuelve las palabras clave de Búsqueda de Google que se usaron para encontrar el negocio.
 * Nota: La API devuelve hasta 20 keywords por página. Máximo recomendado: 6 meses de rango.
 *
 * CORRECCIÓN: La clave real en la respuesta JSON de la API es "searchKeywordsCounts"
 * (con 's' al final), NO "searchKeywordCounts". La documentación oficial omite la 's
 * pero la respuesta real de la API la incluye.
 *
 * @param int    $business_id   ID del post oy_business.
 * @param string $location_name gmb_location_id.
 * @param array  $start_month   ['year'=>int, 'month'=>int]
 * @param array  $end_month     ['year'=>int, 'month'=>int]
 * @param bool   $use_cache     Usar transient cache (TTL: 12 horas).
 * @return array|WP_Error       Array de SearchKeywordCount o WP_Error.
 */
public static function get_location_search_keywords(
    $business_id,
    $location_name,
    array $start_month,
    array $end_month,
    $use_cache = true
) {
    $location_id = self::extract_location_id_from_any( $location_name );

    if ( '' === $location_id ) {
        return new WP_Error( 'invalid_location', __( 'Location ID inválido para keywords de búsqueda.', 'lealez' ) );
    }

    $cache_key = 'oy_perf_kw_' . $business_id . '_' . md5(
        $location_id . serialize( $start_month ) . serialize( $end_month )
    );

    if ( $use_cache ) {
        $cached = get_transient( $cache_key );
        if ( ! empty( $cached ) ) {
            return $cached;
        }
    }

    // Build endpoint with query string (no repeated params here)
    $location_resource = 'locations/' . $location_id;
    $endpoint          = '/' . $location_resource . '/searchkeywords/impressions/monthly';

    $qs_parts = array(
        'monthlyRange.startMonth.year='  . (int) ( $start_month['year']  ?? 0 ),
        'monthlyRange.startMonth.month=' . (int) ( $start_month['month'] ?? 0 ),
        'monthlyRange.endMonth.year='    . (int) ( $end_month['year']    ?? 0 ),
        'monthlyRange.endMonth.month='   . (int) ( $end_month['month']   ?? 0 ),
        'pageSize=20',
    );

    $endpoint_with_qs = $endpoint . '?' . implode( '&', $qs_parts );

    $all_keywords  = array();
    $next_page     = null;
    $page_count    = 0;
    $max_pages     = 5; // Safety limit

    do {
        $current_endpoint = $endpoint_with_qs;
        if ( $next_page ) {
            $current_endpoint .= '&pageToken=' . rawurlencode( $next_page );
        }

        $result = self::make_request(
            $business_id,
            $current_endpoint,
            'https://businessprofileperformance.googleapis.com/v1',
            'GET',
            array(),
            false
        );

        if ( is_wp_error( $result ) ) {
            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log(
                    $business_id,
                    'error',
                    'Keywords API error: ' . $result->get_error_message()
                );
            }
            // Return partial results if we have any
            if ( ! empty( $all_keywords ) ) {
                break;
            }
            return $result;
        }

        // ── CORRECCIÓN: la clave real en la respuesta de la API es "searchKeywordsCounts"
        // (con 's' al final). La documentación puede listarla sin 's' pero la respuesta
        // real HTTP siempre la incluye. Soportar ambas variantes por compatibilidad.
        $items = $result['searchKeywordsCounts'] ?? $result['searchKeywordCounts'] ?? array();

        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            $keys_found = array_keys( $result );
            error_log( sprintf(
                '[Lealez KW API] page=%d | keys_in_response=%s | items_extracted=%d',
                $page_count + 1,
                implode( ', ', $keys_found ),
                count( $items )
            ) );
        }

        foreach ( $items as $item ) {
            $all_keywords[] = $item;
        }

        $next_page = $result['nextPageToken'] ?? null;
        $page_count++;

    } while ( $next_page && $page_count < $max_pages );

    // Sort by impressions descending
    usort( $all_keywords, function( $a, $b ) {
        $av = isset( $a['insightsValue']['value'] ) ? (int) $a['insightsValue']['value'] : 0;
        $bv = isset( $b['insightsValue']['value'] ) ? (int) $b['insightsValue']['value'] : 0;
        return $bv - $av;
    } );

    // TTL: 12 hours. Keywords change slowly.
    set_transient( $cache_key, $all_keywords, 12 * HOUR_IN_SECONDS );

    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'success',
            sprintf( 'Keywords API: received %d keywords for location %s', count( $all_keywords ), $location_id )
        );
    }

    return $all_keywords;
}

    /**
     * Limpia el caché de métricas de rendimiento para una ubicación.
     *
     * @param int    $business_id
     * @param string $location_name
     */
    public static function clear_performance_cache( $business_id, $location_name ) {
        global $wpdb;

        $location_id = self::extract_location_id_from_any( $location_name );
        if ( '' === $location_id ) {
            return;
        }

        // Delete all transients matching our performance prefix
        // (WordPress doesn't offer wildcard delete natively, so we query the DB)
        $prefix_metrics  = 'oy_perf_multi_' . $business_id . '_';
        $prefix_keywords = 'oy_perf_kw_' . $business_id . '_';

        $like_metrics  = $wpdb->esc_like( '_transient_' . $prefix_metrics ) . '%';
        $like_keywords = $wpdb->esc_like( '_transient_' . $prefix_keywords ) . '%';

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $like_metrics,
                $like_keywords
            )
        );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                'Performance cache cleared for business ' . $business_id
            );
        }
    }
    
} // fin clase Lealez_GMB_API

// WP-Cron hook for scheduled refresh
add_action( 'lealez_gmb_scheduled_refresh', array( 'Lealez_GMB_API', 'run_scheduled_refresh' ), 10, 1 );
