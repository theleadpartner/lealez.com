<?php
/**
 * GMB AJAX Handlers
 * 
 * Handles AJAX requests for GMB integration
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
 * Class Lealez_GMB_Ajax
 */
class Lealez_GMB_Ajax {

    /**
     * Constructor
     */
    public function __construct() {
        // AJAX actions
        add_action( 'wp_ajax_lealez_gmb_get_auth_url', array( $this, 'get_auth_url' ) );
        add_action( 'wp_ajax_lealez_gmb_disconnect', array( $this, 'disconnect' ) );
        add_action( 'wp_ajax_lealez_gmb_refresh_locations', array( $this, 'refresh_locations' ) );
        add_action( 'wp_ajax_lealez_gmb_test_connection', array( $this, 'test_connection' ) );

        // OAuth callback page
        add_action( 'admin_menu', array( $this, 'register_callback_page' ) );
    }

    /**
     * Register callback page (hidden)
     */
    public function register_callback_page() {
        add_submenu_page(
            null, // No parent = hidden page
            __( 'GMB OAuth Callback', 'lealez' ),
            __( 'GMB OAuth Callback', 'lealez' ),
            'manage_options',
            'lealez-gmb-callback',
            array( $this, 'handle_oauth_callback' )
        );
    }

    /**
     * Get authorization URL
     */
    public function get_auth_url() {
        check_ajax_referer( 'lealez_gmb_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'lealez' ) ) );
        }

        $business_id = absint( $_POST['business_id'] ?? 0 );

        if ( ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid business ID', 'lealez' ) ) );
        }

        $auth_url = Lealez_GMB_OAuth::get_authorization_url( $business_id );

        if ( ! $auth_url ) {
            wp_send_json_error( array( 'message' => __( 'OAuth configuration not found. Please configure Google OAuth credentials in settings.', 'lealez' ) ) );
        }

        wp_send_json_success( array( 'auth_url' => $auth_url ) );
    }

    /**
     * Disconnect GMB account
     */
    public function disconnect() {
        check_ajax_referer( 'lealez_gmb_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'lealez' ) ) );
        }

        $business_id = absint( $_POST['business_id'] ?? 0 );

        if ( ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid business ID', 'lealez' ) ) );
        }

        $result = Lealez_GMB_OAuth::disconnect( $business_id );

        if ( $result ) {
            wp_send_json_success( array( 'message' => __( 'Successfully disconnected from Google My Business', 'lealez' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Failed to disconnect', 'lealez' ) ) );
        }
    }

    /**
     * Refresh locations from GMB
     */
    public function refresh_locations() {
        check_ajax_referer( 'lealez_gmb_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'lealez' ) ) );
        }

        $business_id = absint( $_POST['business_id'] ?? 0 );

        if ( ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid business ID', 'lealez' ) ) );
        }

        $locations = Lealez_GMB_API::get_all_locations( $business_id );

        if ( is_wp_error( $locations ) ) {
            wp_send_json_error( array( 'message' => $locations->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'   => sprintf( __( 'Successfully retrieved %d locations', 'lealez' ), count( $locations ) ),
            'locations' => $locations,
        ) );
    }

    /**
     * Test GMB connection
     */
    public function test_connection() {
        check_ajax_referer( 'lealez_gmb_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'lealez' ) ) );
        }

        $business_id = absint( $_POST['business_id'] ?? 0 );

        if ( ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid business ID', 'lealez' ) ) );
        }

        $accounts = Lealez_GMB_API::get_accounts( $business_id );

        if ( is_wp_error( $accounts ) ) {
            wp_send_json_error( array( 'message' => $accounts->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'  => __( 'Connection successful', 'lealez' ),
            'accounts' => $accounts,
        ) );
    }

/**
     * Handle OAuth callback
     */
    public function handle_oauth_callback() {
        // Get parameters from URL
        $code = sanitize_text_field( $_GET['code'] ?? '' );
        $state = sanitize_text_field( $_GET['state'] ?? '' );
        $error = sanitize_text_field( $_GET['error'] ?? '' );

        // Handle error
        if ( ! empty( $error ) ) {
            $this->render_callback_page( 'error', __( 'OAuth error: ', 'lealez' ) . $error );
            return;
        }

        // Validate state
        if ( empty( $state ) || empty( $code ) ) {
            $this->render_callback_page( 'error', __( 'Invalid OAuth callback parameters', 'lealez' ) );
            return;
        }

        // Extract business_id from state
        $state_parts = explode( '|', $state );
        if ( count( $state_parts ) !== 2 ) {
            $this->render_callback_page( 'error', __( 'Invalid state parameter', 'lealez' ) );
            return;
        }

        list( $nonce, $business_id ) = $state_parts;
        $business_id = absint( $business_id );

        // Verify nonce
        if ( ! wp_verify_nonce( $nonce, 'lealez_gmb_oauth_' . $business_id ) ) {
            $this->render_callback_page( 'error', __( 'Security verification failed', 'lealez' ) );
            return;
        }

        // Exchange code for tokens
        $result = Lealez_GMB_OAuth::exchange_code_for_tokens( $code, $business_id );

        if ( is_wp_error( $result ) ) {
            $this->render_callback_page( 'error', $result->get_error_message() );
            return;
        }

        // Schedule background task to fetch accounts (avoids rate limiting)
        wp_schedule_single_event( time() + 60, 'lealez_gmb_fetch_accounts_background', array( $business_id ) );

        // Success - accounts will be fetched in background
        $this->render_callback_page(
            'success',
            __( 'Successfully connected to Google My Business! Your account information will be loaded shortly. You can close this window.', 'lealez' ),
            $business_id
        );
    }

    /**
     * Render callback page
     *
     * @param string $status      Status: success, error, warning
     * @param string $message     Message to display
     * @param int    $business_id Business ID
     */
    private function render_callback_page( $status, $message, $business_id = 0 ) {
        $icon = $status === 'success' ? '✓' : ($status === 'error' ? '✗' : '⚠');
        $color = $status === 'success' ? '#46b450' : ($status === 'error' ? '#dc3232' : '#f0b322');
        $edit_link = $business_id ? get_edit_post_link( $business_id ) : admin_url( 'edit.php?post_type=oy_business' );
        ?>
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title><?php esc_html_e( 'Google My Business Connection', 'lealez' ); ?></title>
            <style>
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                    display: flex;
                    justify-content: center;
                    align-items: center;
                    min-height: 100vh;
                    margin: 0;
                    background: #f0f0f1;
                }
                .callback-container {
                    background: white;
                    padding: 40px;
                    border-radius: 8px;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
                    text-align: center;
                    max-width: 500px;
                }
                .callback-icon {
                    font-size: 64px;
                    margin-bottom: 20px;
                    color: <?php echo esc_attr( $color ); ?>;
                }
                .callback-message {
                    font-size: 18px;
                    margin-bottom: 30px;
                    color: #333;
                }
                .callback-button {
                    display: inline-block;
                    padding: 12px 24px;
                    background: #2271b1;
                    color: white;
                    text-decoration: none;
                    border-radius: 4px;
                    transition: background 0.3s;
                }
                .callback-button:hover {
                    background: #135e96;
                }
            </style>
        </head>
        <body>
            <div class="callback-container">
                <div class="callback-icon"><?php echo esc_html( $icon ); ?></div>
                <div class="callback-message"><?php echo esc_html( $message ); ?></div>
                <a href="<?php echo esc_url( $edit_link ); ?>" class="callback-button">
                    <?php esc_html_e( 'Return to Business', 'lealez' ); ?>
                </a>
            </div>
            <?php if ( $status === 'success' ) : ?>
            <script>
                // Auto-close after 3 seconds
                setTimeout(function() {
                    if (window.opener) {
                        window.opener.location.reload();
                        window.close();
                    } else {
                        window.location.href = '<?php echo esc_url( $edit_link ); ?>';
                    }
                }, 3000);
            </script>
            <?php endif; ?>
        </body>
        </html>
        <?php
    }

    /**
     * Background task to fetch accounts
     * Scheduled after OAuth to avoid rate limiting
     */
    public static function fetch_accounts_background( $business_id ) {
        if ( ! $business_id ) {
            return;
        }

        // Fetch accounts with retry logic built into API class
        $accounts = Lealez_GMB_API::get_accounts( $business_id, true );

        if ( is_wp_error( $accounts ) ) {
            // Log error
            update_post_meta( $business_id, '_gmb_last_sync_error', array(
                'message' => $accounts->get_error_message(),
                'time'    => time(),
            ) );

            // Reschedule if rate limit error
            if ( 'rate_limit_exceeded' === $accounts->get_error_code() ) {
                wp_schedule_single_event( time() + 300, 'lealez_gmb_fetch_accounts_background', array( $business_id ) );
            }
        } else {
            // Clear any previous errors
            delete_post_meta( $business_id, '_gmb_last_sync_error' );
        }
    }

}

// Initialize
new Lealez_GMB_Ajax();
