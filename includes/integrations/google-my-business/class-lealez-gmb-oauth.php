<?php
/**
 * GMB OAuth Handler
 * 
 * Manages OAuth 2.0 authentication flow with Google
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
 * Class Lealez_GMB_OAuth
 */
class Lealez_GMB_OAuth {

    /**
     * Google OAuth endpoint
     *
     * @var string
     */
    private static $auth_url = 'https://accounts.google.com/o/oauth2/v2/auth';

    /**
     * Google Token endpoint
     *
     * @var string
     */
    private static $token_url = 'https://oauth2.googleapis.com/token';

    /**
     * Required OAuth scopes
     *
     * @var array
     */
    private static $scopes = array(
        'https://www.googleapis.com/auth/business.manage',
    );

    /**
     * Get OAuth configuration
     *
     * @return array|false
     */
    private static function get_oauth_config() {
        $config = get_option( 'lealez_gmb_oauth_config', array() );
        
        if ( empty( $config['client_id'] ) || empty( $config['client_secret'] ) ) {
            return false;
        }

        return $config;
    }

    /**
     * Get authorization URL
     *
     * @param int $business_id Business post ID
     * @return string|false
     */
    public static function get_authorization_url( $business_id ) {
        $config = self::get_oauth_config();
        
        if ( ! $config ) {
            return false;
        }

        $state = wp_create_nonce( 'lealez_gmb_oauth_' . $business_id );
        update_post_meta( $business_id, '_gmb_oauth_state', $state );

        $params = array(
            'client_id'     => $config['client_id'],
            'redirect_uri'  => self::get_redirect_uri(),
            'response_type' => 'code',
            'scope'         => implode( ' ', self::$scopes ),
            'access_type'   => 'offline',
            'prompt'        => 'consent',
            'state'         => $state . '|' . $business_id,
        );

        return self::$auth_url . '?' . http_build_query( $params );
    }

    /**
     * Get redirect URI
     *
     * @return string
     */
    public static function get_redirect_uri() {
        return admin_url( 'admin.php?page=lealez-gmb-callback' );
    }

    /**
     * Exchange authorization code for tokens
     *
     * @param string $code          Authorization code
     * @param int    $business_id   Business post ID
     * @return array|WP_Error
     */
    public static function exchange_code_for_tokens( $code, $business_id ) {
        $config = self::get_oauth_config();
        
        if ( ! $config ) {
            return new WP_Error( 'no_config', __( 'OAuth configuration not found', 'lealez' ) );
        }

        $response = wp_remote_post(
            self::$token_url,
            array(
                'body' => array(
                    'code'          => $code,
                    'client_id'     => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'redirect_uri'  => self::get_redirect_uri(),
                    'grant_type'    => 'authorization_code',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'oauth_error', $body['error_description'] ?? $body['error'] );
        }

        $tokens = array(
            'access_token'  => $body['access_token'],
            'refresh_token' => $body['refresh_token'] ?? '',
            'expires_at'    => time() + ( $body['expires_in'] ?? 3600 ),
            'token_type'    => $body['token_type'] ?? 'Bearer',
            'scope'         => $body['scope'] ?? '',
        );

        // Store tokens encrypted
        Lealez_GMB_Encryption::store_tokens( $business_id, $tokens );

        // Mark as connected
        update_post_meta( $business_id, '_gmb_connected', '1' );
        update_post_meta( $business_id, '_gmb_connection_date', time() );
        update_post_meta( $business_id, '_gmb_connected_by_user_id', get_current_user_id() );

        return $tokens;
    }

    /**
     * Refresh access token
     *
     * @param int $business_id Business post ID
     * @return array|WP_Error
     */
    public static function refresh_access_token( $business_id ) {
        $config = self::get_oauth_config();
        
        if ( ! $config ) {
            return new WP_Error( 'no_config', __( 'OAuth configuration not found', 'lealez' ) );
        }

        $tokens = Lealez_GMB_Encryption::get_tokens( $business_id );
        
        if ( ! $tokens || empty( $tokens['refresh_token'] ) ) {
            return new WP_Error( 'no_refresh_token', __( 'No refresh token available', 'lealez' ) );
        }

        $response = wp_remote_post(
            self::$token_url,
            array(
                'body' => array(
                    'refresh_token' => $tokens['refresh_token'],
                    'client_id'     => $config['client_id'],
                    'client_secret' => $config['client_secret'],
                    'grant_type'    => 'refresh_token',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( isset( $body['error'] ) ) {
            return new WP_Error( 'refresh_error', $body['error_description'] ?? $body['error'] );
        }

        $new_tokens = array(
            'access_token'  => $body['access_token'],
            'refresh_token' => $tokens['refresh_token'], // Keep existing refresh token
            'expires_at'    => time() + ( $body['expires_in'] ?? 3600 ),
            'token_type'    => $body['token_type'] ?? 'Bearer',
            'scope'         => $body['scope'] ?? $tokens['scope'],
        );

        // Update tokens
        Lealez_GMB_Encryption::store_tokens( $business_id, $new_tokens );

        return $new_tokens;
    }

    /**
     * Check if token is expired or will expire soon
     *
     * @param int $business_id Business post ID
     * @return bool
     */
    public static function is_token_expired( $business_id ) {
        $tokens = Lealez_GMB_Encryption::get_tokens( $business_id );
        
        if ( ! $tokens ) {
            return true;
        }

        // Consider expired if less than 5 minutes remaining
        return ( $tokens['expires_at'] - 300 ) < time();
    }

    /**
     * Disconnect GMB account
     *
     * @param int $business_id Business post ID
     * @return bool
     */
    public static function disconnect( $business_id ) {
        // Delete tokens
        Lealez_GMB_Encryption::delete_tokens( $business_id );

        // Delete connection metadata
        delete_post_meta( $business_id, '_gmb_connected' );
        delete_post_meta( $business_id, '_gmb_connection_date' );
        delete_post_meta( $business_id, '_gmb_connected_by_user_id' );
        delete_post_meta( $business_id, '_gmb_account_name' );
        delete_post_meta( $business_id, '_gmb_account_email' );
        delete_post_meta( $business_id, '_gmb_oauth_state' );
        delete_post_meta( $business_id, '_gmb_accounts' );
        delete_post_meta( $business_id, '_gmb_locations_available' );

        return true;
    }
}