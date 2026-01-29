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
        add_action( 'wp_ajax_lealez_gmb_clear_logs', array( $this, 'clear_logs' ) );

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
        // ✅ NEW: limpiar cache/rate-limit flags persistentes para evitar bloqueos en reconexión
        if ( class_exists( 'Lealez_GMB_API' ) ) {
            Lealez_GMB_API::clear_business_cache( $business_id );
        }

        // Log disconnection
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'info', 'GMB account disconnected by user (cache/rate-limit cleared)' );
        }

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

    if ( ! class_exists( 'Lealez_GMB_API' ) ) {
        wp_send_json_error( array( 'message' => __( 'GMB API class not available.', 'lealez' ) ) );
    }

    // ✅ Bloqueo para evitar refresh concurrente (manual/cron/otro admin)
    if ( ! Lealez_GMB_API::acquire_sync_lock( $business_id, 300 ) ) {
        wp_send_json_error( array(
            'message' => __( 'A sync is already in progress. Please wait a moment and try again.', 'lealez' ),
        ) );
    }

    try {

        // ✅ Validar cooldown/rate-limit/intervalo mínimo
        if ( ! Lealez_GMB_API::can_refresh_now( $business_id ) ) {
            $wait_minutes  = (int) Lealez_GMB_API::get_minutes_until_next_refresh( $business_id );
            $delay_seconds = max( 60, $wait_minutes * 60 );

            // ✅ Programar cron automático (devuelve el timestamp final real; respeta “keep earliest”)
            $ts = (int) Lealez_GMB_API::schedule_locations_refresh( $business_id, $delay_seconds, 'manual_refresh_blocked' );

            $scheduled_human = date_i18n(
                get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                $ts
            );

            $msg = sprintf(
                __( 'Rate limit / cooldown active. Please wait %d minutes. An automatic refresh has been scheduled for %s.', 'lealez' ),
                $wait_minutes,
                $scheduled_human
            );

            if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                Lealez_GMB_Logger::log( $business_id, 'warning', $msg, array( 'scheduled_for' => $ts ) );
            }

            wp_send_json_error( array(
                'message'             => $msg,
                'wait_minutes'        => $wait_minutes,
                'scheduled_for'       => $ts,
                'scheduled_for_human' => $scheduled_human,
            ) );
        }

        // Log refresh start
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log( $business_id, 'info', 'Manual refresh started (accounts + locations)' );
        }

        /**
         * ✅ IMPORTANTE:
         * NO llamamos get_accounts() aquí, porque get_all_locations() ya llama get_accounts().
         * Esto elimina llamadas duplicadas a /accounts.
         */
        $locations = Lealez_GMB_API::get_all_locations( $business_id, true );

        if ( is_wp_error( $locations ) ) {
            // ✅ Si fue rate limit, programar reintento automático
            if ( in_array( $locations->get_error_code(), array( 'rate_limit_exceeded', 'rate_limit_active', 'local_rate_limit' ), true ) ) {
                $wait_minutes  = (int) Lealez_GMB_API::get_minutes_until_next_refresh( $business_id );
                $delay_seconds = max( 60, $wait_minutes * 60 );

                $ts = (int) Lealez_GMB_API::schedule_locations_refresh( $business_id, $delay_seconds, 'manual_refresh_rate_limited' );

                $scheduled_human = date_i18n(
                    get_option( 'date_format' ) . ' ' . get_option( 'time_format' ),
                    $ts
                );

                $msg = $locations->get_error_message() . ' ' . sprintf(
                    __( 'An automatic refresh has been scheduled for %s.', 'lealez' ),
                    $scheduled_human
                );

                if ( class_exists( 'Lealez_GMB_Logger' ) ) {
                    Lealez_GMB_Logger::log(
                        $business_id,
                        'warning',
                        'Manual refresh hit rate limit; scheduled automatic refresh.',
                        array(
                            'error'              => $locations->get_error_message(),
                            'scheduled_for'      => $ts,
                            'scheduled_human'    => $scheduled_human,
                            'wait_minutes'       => $wait_minutes,
                        )
                    );
                }

                wp_send_json_error( array(
                    'message'             => $msg,
                    'wait_minutes'        => $wait_minutes,
                    'scheduled_for'       => $ts,
                    'scheduled_for_human' => $scheduled_human,
                ) );
            }

            wp_send_json_error( array( 'message' => $locations->get_error_message() ) );
        }

        // ✅ Guardar timestamp de refresh manual exitoso
        update_post_meta( $business_id, '_gmb_last_manual_refresh', time() );

        // Log success
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'success',
                sprintf( 'Manual refresh completed: %d locations retrieved', count( $locations ) )
            );
        }

        wp_send_json_success( array(
            'message'   => sprintf( __( 'Successfully retrieved %d locations', 'lealez' ), count( $locations ) ),
            'locations' => $locations,
        ) );

    } finally {
        // ✅ Liberar lock siempre
        Lealez_GMB_API::release_sync_lock( $business_id );
    }
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

        // Just test the connection, don't fetch all data
        $accounts = Lealez_GMB_API::get_accounts( $business_id, false );

        if ( is_wp_error( $accounts ) ) {
            wp_send_json_error( array( 'message' => $accounts->get_error_message() ) );
        }

        wp_send_json_success( array(
            'message'  => __( 'Connection successful', 'lealez' ),
            'accounts' => count( $accounts ),
        ) );
    }

    /**
     * Clear activity logs
     */
    public function clear_logs() {
        check_ajax_referer( 'lealez_gmb_nonce', 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( array( 'message' => __( 'Insufficient permissions', 'lealez' ) ) );
        }

        $business_id = absint( $_POST['business_id'] ?? 0 );

        if ( ! $business_id ) {
            wp_send_json_error( array( 'message' => __( 'Invalid business ID', 'lealez' ) ) );
        }

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::clear_logs( $business_id );
            wp_send_json_success( array( 'message' => __( 'Logs cleared successfully', 'lealez' ) ) );
        } else {
            wp_send_json_error( array( 'message' => __( 'Logger class not found', 'lealez' ) ) );
        }
    }

    /**
     * Handle OAuth callback
     */
public function handle_oauth_callback() {
    // Get parameters from URL
    $code  = sanitize_text_field( $_GET['code'] ?? '' );
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
        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'error',
                'OAuth token exchange failed: ' . $result->get_error_message()
            );
        }

        $this->render_callback_page( 'error', $result->get_error_message() );
        return;
    }

    /**
     * ✅ IMPORTANTE:
     * Mantenemos el "2-step flow": el callback NO hace llamadas a la API para evitar 429.
     * PERO: NO bloqueamos el refresh manual por 60 minutos, porque eso deja al usuario “conectado sin datos”.
     */
    if ( class_exists( 'Lealez_GMB_API' ) ) {
        // Limpia cache/flags (incluye rate-limit viejo) para que el primer refresh manual pueda ejecutarse.
        Lealez_GMB_API::clear_business_cache( $business_id, false );

        // Por si existía un cooldown viejo, lo removemos explícitamente.
        delete_post_meta( $business_id, '_gmb_post_connect_cooldown_until' );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                'OAuth exchange OK: cache cleared. Refresh is allowed immediately (no post-connect cooldown).'
            );
        }

        // Auto-refresh de respaldo (NO bloquea al usuario). Evita que quede sin datos si no presiona el botón.
        $scheduled_ts = Lealez_GMB_API::schedule_locations_refresh(
            $business_id,
            90, // 90 segundos: margen mínimo para volver al edit screen sin saturar
            'post_connect_auto_refresh'
        );

        if ( class_exists( 'Lealez_GMB_Logger' ) ) {
            Lealez_GMB_Logger::log(
                $business_id,
                'info',
                'Post-connect: automatic refresh scheduled (user can still refresh manually right now).',
                array( 'scheduled_for' => $scheduled_ts )
            );
        }
    }

    // Log success
    if ( class_exists( 'Lealez_GMB_Logger' ) ) {
        Lealez_GMB_Logger::log(
            $business_id,
            'success',
            'Successfully connected to Google My Business'
        );
    }

    // Mensaje final
    $message  = __( 'Successfully connected to Google My Business!', 'lealez' ) . "\n\n";
    $message .= __( "Next step:\n", 'lealez' );
    $message .= __( '- Go back to the Business page and click "Actualizar Ubicaciones" now (recommended).', 'lealez' ) . "\n\n";
    $message .= __( 'An automatic refresh has also been scheduled as a backup (in ~90 seconds).', 'lealez' );

    $this->render_callback_page( 'success', $message, $business_id );
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
        
        // Convert line breaks to <br> for HTML display
        $message_html = nl2br( esc_html( $message ) );
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
                    max-width: 600px;
                }
                .callback-icon {
                    font-size: 64px;
                    margin-bottom: 20px;
                    color: <?php echo esc_attr( $color ); ?>;
                }
                .callback-message {
                    font-size: 16px;
                    margin-bottom: 30px;
                    color: #333;
                    text-align: left;
                    line-height: 1.6;
                }
                .callback-message strong {
                    color: #000;
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
                <div class="callback-message"><?php echo wp_kses_post( $message_html ); ?></div>
                <a href="<?php echo esc_url( $edit_link ); ?>" class="callback-button">
                    <?php esc_html_e( 'Return to Business', 'lealez' ); ?>
                </a>
            </div>
            <?php if ( $status === 'success' ) : ?>
            <script>
                // Auto-close after 5 seconds (increased from 3 to give time to read)
                setTimeout(function() {
                    if (window.opener) {
                        window.opener.location.reload();
                        window.close();
                    } else {
                        window.location.href = '<?php echo esc_url( $edit_link ); ?>';
                    }
                }, 5000);
            </script>
            <?php endif; ?>
        </body>
        </html>
        <?php
    }
}

// Initialize
new Lealez_GMB_Ajax();
