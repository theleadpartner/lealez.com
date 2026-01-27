<?php
/**
 * GMB Encryption Class
 * 
 * Handles encryption and decryption of sensitive GMB data
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
 * Class Lealez_GMB_Encryption
 */
class Lealez_GMB_Encryption {

    /**
     * Encryption method
     *
     * @var string
     */
    private static $cipher = 'AES-256-CBC';

    /**
     * Get encryption key
     *
     * @return string
     */
    private static function get_encryption_key() {
        $key = get_option( 'lealez_gmb_encryption_key' );
        
        if ( ! $key ) {
            $key = self::generate_encryption_key();
            update_option( 'lealez_gmb_encryption_key', $key, false );
        }
        
        return $key;
    }

    /**
     * Generate encryption key
     *
     * @return string
     */
    private static function generate_encryption_key() {
        return bin2hex( random_bytes( 32 ) );
    }

    /**
     * Encrypt data
     *
     * @param string $data Data to encrypt
     * @return string|false Encrypted data or false on failure
     */
    public static function encrypt( $data ) {
        if ( empty( $data ) ) {
            return false;
        }

        $key = self::get_encryption_key();
        $iv_length = openssl_cipher_iv_length( self::$cipher );
        $iv = openssl_random_pseudo_bytes( $iv_length );
        
        $encrypted = openssl_encrypt(
            $data,
            self::$cipher,
            hex2bin( $key ),
            OPENSSL_RAW_DATA,
            $iv
        );
        
        if ( false === $encrypted ) {
            return false;
        }
        
        return base64_encode( $iv . $encrypted );
    }

    /**
     * Decrypt data
     *
     * @param string $encrypted_data Encrypted data
     * @return string|false Decrypted data or false on failure
     */
    public static function decrypt( $encrypted_data ) {
        if ( empty( $encrypted_data ) ) {
            return false;
        }

        $key = self::get_encryption_key();
        $data = base64_decode( $encrypted_data );
        
        if ( false === $data ) {
            return false;
        }
        
        $iv_length = openssl_cipher_iv_length( self::$cipher );
        $iv = substr( $data, 0, $iv_length );
        $encrypted = substr( $data, $iv_length );
        
        $decrypted = openssl_decrypt(
            $encrypted,
            self::$cipher,
            hex2bin( $key ),
            OPENSSL_RAW_DATA,
            $iv
        );
        
        return $decrypted;
    }

    /**
     * Encrypt and store tokens
     *
     * @param int   $business_id Business post ID
     * @param array $tokens      Token data
     * @return bool
     */
    public static function store_tokens( $business_id, $tokens ) {
        if ( empty( $tokens ) || ! is_array( $tokens ) ) {
            return false;
        }

        $encrypted_access_token = self::encrypt( $tokens['access_token'] ?? '' );
        $encrypted_refresh_token = self::encrypt( $tokens['refresh_token'] ?? '' );

        if ( ! $encrypted_access_token || ! $encrypted_refresh_token ) {
            return false;
        }

        update_post_meta( $business_id, '_gmb_access_token', $encrypted_access_token );
        update_post_meta( $business_id, '_gmb_refresh_token', $encrypted_refresh_token );
        update_post_meta( $business_id, '_gmb_token_expires_at', $tokens['expires_at'] ?? 0 );
        update_post_meta( $business_id, '_gmb_token_type', $tokens['token_type'] ?? 'Bearer' );
        update_post_meta( $business_id, '_gmb_token_scope', $tokens['scope'] ?? '' );

        return true;
    }

    /**
     * Retrieve and decrypt tokens
     *
     * @param int $business_id Business post ID
     * @return array|false Array of tokens or false
     */
    public static function get_tokens( $business_id ) {
        $encrypted_access_token = get_post_meta( $business_id, '_gmb_access_token', true );
        $encrypted_refresh_token = get_post_meta( $business_id, '_gmb_refresh_token', true );

        if ( empty( $encrypted_access_token ) || empty( $encrypted_refresh_token ) ) {
            return false;
        }

        $access_token = self::decrypt( $encrypted_access_token );
        $refresh_token = self::decrypt( $encrypted_refresh_token );

        if ( false === $access_token || false === $refresh_token ) {
            return false;
        }

        return array(
            'access_token'  => $access_token,
            'refresh_token' => $refresh_token,
            'expires_at'    => get_post_meta( $business_id, '_gmb_token_expires_at', true ),
            'token_type'    => get_post_meta( $business_id, '_gmb_token_type', true ),
            'scope'         => get_post_meta( $business_id, '_gmb_token_scope', true ),
        );
    }

    /**
     * Delete tokens
     *
     * @param int $business_id Business post ID
     * @return bool
     */
    public static function delete_tokens( $business_id ) {
        delete_post_meta( $business_id, '_gmb_access_token' );
        delete_post_meta( $business_id, '_gmb_refresh_token' );
        delete_post_meta( $business_id, '_gmb_token_expires_at' );
        delete_post_meta( $business_id, '_gmb_token_type' );
        delete_post_meta( $business_id, '_gmb_token_scope' );

        return true;
    }
}