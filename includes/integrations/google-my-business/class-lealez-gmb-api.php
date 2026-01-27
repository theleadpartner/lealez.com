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
     * Make API request
     *
     * @param int    $business_id Business post ID
     * @param string $endpoint    API endpoint
     * @param string $method      HTTP method
     * @param array  $body        Request body
     * @return array|WP_Error
     */
    private static function make_request( $business_id, $endpoint, $method = 'GET', $body = array() ) {
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
            'headers' => array(
                'Authorization' => 'Bearer ' . $tokens['access_token'],
                'Content-Type'  => 'application/json',
            ),
        );

        if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
            $args['body'] = wp_json_encode( $body );
        }

        $response = wp_remote_request( $url, $args );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $response_code = wp_remote_retrieve_response_code( $response );
        $response_body = json_decode( wp_remote_retrieve_body( $response ), true );

        if ( $response_code < 200 || $response_code >= 300 ) {
            $error_message = $response_body['error']['message'] ?? __( 'API request failed', 'lealez' );
            return new WP_Error( 'api_error', $error_message, array( 'code' => $response_code ) );
        }

        return $response_body;
    }

    /**
     * Get accounts
     *
     * @param int $business_id Business post ID
     * @return array|WP_Error
     */
    public static function get_accounts( $business_id ) {
        $result = self::make_request( $business_id, '/accounts' );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $accounts = $result['accounts'] ?? array();
        
        // Store accounts
        update_post_meta( $business_id, '_gmb_accounts', $accounts );

        // Store account info if only one account
        if ( count( $accounts ) === 1 ) {
            $account = $accounts[0];
            update_post_meta( $business_id, '_gmb_account_name', $account['accountName'] ?? '' );
            update_post_meta( $business_id, '_gmb_account_email', $account['primaryOwner'] ?? '' );
        }

        return $accounts;
    }

    /**
     * Get locations for an account
     *
     * @param int    $business_id Business post ID
     * @param string $account_name Account resource name
     * @return array|WP_Error
     */
    public static function get_locations( $business_id, $account_name ) {
        $endpoint = '/' . $account_name . '/locations';
        $result = self::make_request( $business_id, $endpoint );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        $locations = $result['locations'] ?? array();
        
        // Process and store locations
        $processed_locations = array();
        foreach ( $locations as $location ) {
            $processed_locations[] = array(
                'name'           => $location['name'] ?? '',
                'title'          => $location['title'] ?? '',
                'storefrontAddress' => $location['storefrontAddress'] ?? array(),
                'phoneNumbers'   => $location['phoneNumbers'] ?? array(),
                'websiteUri'     => $location['websiteUri'] ?? '',
                'regularHours'   => $location['regularHours'] ?? array(),
                'primaryCategory' => $location['primaryCategory'] ?? array(),
                'latlng'         => $location['latlng'] ?? array(),
            );
        }

        update_post_meta( $business_id, '_gmb_locations_available', $processed_locations );
        update_post_meta( $business_id, '_gmb_locations_last_fetch', time() );
        update_post_meta( $business_id, '_gmb_total_locations_available', count( $processed_locations ) );

        return $processed_locations;
    }

    /**
     * Get all locations for all accounts
     *
     * @param int $business_id Business post ID
     * @return array|WP_Error
     */
    public static function get_all_locations( $business_id ) {
        $accounts = self::get_accounts( $business_id );
        
        if ( is_wp_error( $accounts ) ) {
            return $accounts;
        }

        $all_locations = array();

        foreach ( $accounts as $account ) {
            $account_name = $account['name'] ?? '';
            
            if ( empty( $account_name ) ) {
                continue;
            }

            $locations = self::get_locations( $business_id, $account_name );
            
            if ( ! is_wp_error( $locations ) ) {
                $all_locations = array_merge( $all_locations, $locations );
            }
        }

        return $all_locations;
    }

    /**
     * Sync location data from GMB
     *
     * @param int    $business_id Business post ID
     * @param string $location_name GMB location resource name
     * @return array|WP_Error
     */
    public static function sync_location_data( $business_id, $location_name ) {
        $endpoint = '/' . $location_name;
        $result = self::make_request( $business_id, $endpoint );

        if ( is_wp_error( $result ) ) {
            return $result;
        }

        return $result;
    }
}