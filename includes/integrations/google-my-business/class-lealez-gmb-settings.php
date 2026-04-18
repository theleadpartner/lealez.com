<?php
/**
 * GMB Settings Page
 *
 * Handles GMB OAuth configuration in admin settings
 *
 * @package Lealez
 * @subpackage Admin
 * @since 1.0.0
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

/**
 * Class Lealez_GMB_Settings
 */
class Lealez_GMB_Settings {

    /**
     * Option name for API test logs.
     *
     * @var string
     */
    const TEST_LOG_OPTION = 'lealez_gmb_settings_test_log';

    /**
     * Max stored runs in the test log.
     *
     * @var int
     */
    const MAX_TEST_LOG_RUNS = 20;

    /**
     * AJAX nonce action for settings API tests.
     *
     * @var string
     */
    const TEST_NONCE_ACTION = 'lealez_gmb_settings_test_nonce';

    /**
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ), 99 );
        add_action( 'wp_ajax_lealez_gmb_run_settings_api_test', array( $this, 'ajax_run_settings_api_test' ) );
    }

    /**
     * Register settings
     */
    public function register_settings() {
        // Register individual settings
        register_setting(
            'lealez_gmb_settings_group',
            'lealez_gmb_client_id',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            )
        );

        register_setting(
            'lealez_gmb_settings_group',
            'lealez_gmb_client_secret',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            )
        );

        register_setting(
            'lealez_gmb_settings_group',
            'lealez_places_api_key',
            array(
                'type'              => 'string',
                'sanitize_callback' => 'sanitize_text_field',
                'default'           => '',
            )
        );
    }

    /**
     * Add settings page
     */
    public function add_settings_page() {
        add_submenu_page(
            'lealez',
            __( 'Google My Business Settings', 'lealez' ),
            __( 'GMB Settings', 'lealez' ),
            'manage_options',
            'lealez-gmb-settings',
            array( $this, 'render_settings_page' )
        );
    }

    /**
     * Render the settings page
     */
    public function render_settings_page() {
        // Check user capabilities
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        // Get saved settings
        $client_id      = get_option( 'lealez_gmb_client_id', '' );
        $client_secret  = get_option( 'lealez_gmb_client_secret', '' );
        $places_api_key = get_option( 'lealez_places_api_key', '' );
        $test_logs      = $this->get_test_logs();
        $ajax_nonce     = wp_create_nonce( self::TEST_NONCE_ACTION );

        // Get the redirect URI
        $redirect_uri = admin_url( 'admin.php?page=lealez-gmb-callback' );
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <div class="notice notice-info">
                <h3><?php _e( 'Setup Instructions:', 'lealez' ); ?></h3>
                <ol>
                    <li>
                        <?php _e( 'Go to Google Cloud Console:', 'lealez' ); ?>
                        <a href="https://console.cloud.google.com/" target="_blank" rel="noopener noreferrer">https://console.cloud.google.com/</a>
                    </li>
                    <li><?php _e( 'Create a new project or select an existing one', 'lealez' ); ?></li>
                    <li>
                        <?php _e( 'Enable the following APIs in the API Library:', 'lealez' ); ?>
                        <ul style="margin-top: 8px; margin-left: 20px;">
                            <li><strong>My Business Business Information API</strong> <?php _e( '(required for location data and PATCH to GBP)', 'lealez' ); ?></li>
                            <li><strong>My Business Account Management API</strong> <?php _e( '(required for account access)', 'lealez' ); ?></li>
                            <li><strong>Places API (New)</strong> <?php _e( '(required for predictive service-area autocomplete)', 'lealez' ); ?></li>
                        </ul>
                        <p style="margin-left: 20px; color: #d63638; font-weight: bold;">
                            <?php _e( '⚠️ IMPORTANT: If you want the predictive field for "Áreas de servicio", you must also enable Places API (New).', 'lealez' ); ?>
                        </p>
                    </li>
                    <li><?php _e( 'Go to "Credentials" and create an OAuth 2.0 Client ID for Google Business Profile', 'lealez' ); ?></li>
                    <li>
                        <?php _e( 'Create an API Key for Places API (New)', 'lealez' ); ?>
                        <ul style="margin-top: 8px; margin-left: 20px; list-style: disc;">
                            <li><?php _e( 'Restrict the key to Places API (New)', 'lealez' ); ?></li>
                            <li><?php _e( 'Prefer server-side/IP restriction because Lealez will call Places from wp-admin AJAX', 'lealez' ); ?></li>
                        </ul>
                    </li>
                    <li>
                        <?php _e( 'Configure the OAuth consent screen:', 'lealez' ); ?>
                        <ul style="margin-top: 8px; margin-left: 20px; list-style: disc;">
                            <li><?php _e( 'User Type: External', 'lealez' ); ?></li>
                            <li><?php _e( 'App name: Lealez (or your company name)', 'lealez' ); ?></li>
                            <li>
                                <?php _e( 'Scopes: Add this OAuth scope:', 'lealez' ); ?>
                                <ul style="margin-left: 20px; font-size: 12px; font-family: monospace;">
                                    <li>https://www.googleapis.com/auth/business.manage</li>
                                </ul>
                            </li>
                            <li>
                                <strong style="color: #d63638;"><?php _e( 'IMPORTANT: Add Test Users!', 'lealez' ); ?></strong><br>
                                <?php _e( 'Go to "OAuth consent screen" → "Test users" → Click "Add Users"', 'lealez' ); ?><br>
                                <?php _e( 'Add the email addresses that will connect their Google My Business accounts', 'lealez' ); ?><br>
                                <span style="font-size: 12px; color: #666;">
                                    <?php _e( '(Without this step, you will get "Error 403: access_denied")', 'lealez' ); ?>
                                </span>
                            </li>
                        </ul>
                    </li>
                    <li><?php _e( 'Add the Redirect URI shown below to your OAuth client', 'lealez' ); ?></li>
                    <li><?php _e( 'Copy the Client ID, Client Secret and Places API Key and paste them below', 'lealez' ); ?></li>
                </ol>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'lealez_gmb_settings_group' );
                do_settings_sections( 'lealez-gmb-settings' );
                ?>

                <h2><?php _e( 'Google OAuth Configuration', 'lealez' ); ?></h2>
                <p><?php _e( 'Configure your Google OAuth credentials to enable Google Business Profile integration.', 'lealez' ); ?></p>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="lealez_gmb_client_id"><?php _e( 'Client ID', 'lealez' ); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="lealez_gmb_client_id"
                                   name="lealez_gmb_client_id"
                                   value="<?php echo esc_attr( $client_id ); ?>"
                                   class="regular-text"
                                   placeholder="123456789-abcdefg.apps.googleusercontent.com"
                                   style="width: 500px;">
                            <p class="description"><?php _e( 'Your Google OAuth Client ID.', 'lealez' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="lealez_gmb_client_secret"><?php _e( 'Client Secret', 'lealez' ); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                   id="lealez_gmb_client_secret"
                                   name="lealez_gmb_client_secret"
                                   value="<?php echo esc_attr( $client_secret ); ?>"
                                   class="regular-text"
                                   placeholder="GOCSPX-xxxxxxxxxxxxxxxxxxxxx">
                            <p class="description"><?php _e( 'Your Google OAuth Client Secret.', 'lealez' ); ?></p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="lealez_places_api_key"><?php _e( 'Places API Key', 'lealez' ); ?></label>
                        </th>
                        <td>
                            <input type="password"
                                   id="lealez_places_api_key"
                                   name="lealez_places_api_key"
                                   value="<?php echo esc_attr( $places_api_key ); ?>"
                                   class="regular-text"
                                   placeholder="AIzaSy..."
                                   style="width: 500px;">
                            <p class="description">
                                <?php _e( 'API Key used by the "Áreas de servicio" predictive field via Places API (New). Restrict it to Places API (New) and keep it server-side.', 'lealez' ); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="lealez_gmb_redirect_uri"><?php _e( 'Redirect URI', 'lealez' ); ?></label>
                        </th>
                        <td>
                            <input type="text"
                                   id="lealez_gmb_redirect_uri"
                                   value="<?php echo esc_url( $redirect_uri ); ?>"
                                   class="regular-text"
                                   readonly
                                   style="width: 500px;">
                            <button type="button" class="button" onclick="navigator.clipboard.writeText('<?php echo esc_js( $redirect_uri ); ?>')">
                                <?php _e( 'Copy', 'lealez' ); ?>
                            </button>
                            <p class="description">
                                <?php _e( 'Add this URL to the "Authorized redirect URIs" in your Google OAuth client configuration.', 'lealez' ); ?>
                            </p>
                        </td>
                    </tr>
                </table>

                <?php submit_button( __( 'Save Changes', 'lealez' ) ); ?>
            </form>

            <div class="notice notice-info" style="margin-top: 20px;">
                <h3 style="margin-bottom: 8px;"><?php _e( 'Prueba de APIs configuradas', 'lealez' ); ?></h3>
                <p style="margin-top: 0;">
                    <?php _e( 'Este botón ejecuta una verificación real de la configuración guardada para Places API (New) y, si existe un negocio ya conectado, también valida My Business Account Management API y My Business Business Information API usando los tokens guardados.', 'lealez' ); ?>
                </p>
                <p style="margin-top: 0; margin-bottom: 12px; color: #50575e;">
                    <?php _e( 'El log guarda fecha, estado, mensaje técnico y el contexto usado en la prueba para que puedas identificar rápido si el problema es de key, billing, restricción IP/referrer, OAuth o permisos del proyecto.', 'lealez' ); ?>
                </p>
                <p style="margin-bottom: 0;">
                    <button type="button" class="button button-secondary" id="lealez-run-api-tests">
                        <?php _e( 'Probar APIs ahora', 'lealez' ); ?>
                    </button>
                    <span class="spinner" id="lealez-run-api-tests-spinner" style="float:none; margin:0 0 0 8px;"></span>
                </p>
                <div id="lealez-api-test-status" style="display:none; margin-top:12px;"></div>
            </div>

            <div id="lealez-api-test-log-wrap" style="margin-top: 20px;">
                <?php echo $this->get_test_log_html( $test_logs ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            </div>

            <?php if ( ! empty( $client_id ) && ! empty( $client_secret ) && ! empty( $places_api_key ) ) : ?>
                <div class="notice notice-success" style="margin-top: 20px;">
                    <p>
                        <strong><?php _e( '✓ OAuth + Places Configuration Complete', 'lealez' ); ?></strong><br>
                        <?php _e( 'You can now connect Google Business Profile accounts and use predictive autocomplete for service areas.', 'lealez' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( empty( $client_id ) || empty( $client_secret ) ) : ?>
                <div class="notice notice-warning" style="margin-top: 20px;">
                    <p>
                        <strong><?php _e( '⚠ OAuth Configuration Required', 'lealez' ); ?></strong><br>
                        <?php _e( 'Please complete the OAuth configuration above before connecting Google Business Profile accounts.', 'lealez' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( empty( $places_api_key ) ) : ?>
                <div class="notice notice-warning" style="margin-top: 20px;">
                    <p>
                        <strong><?php _e( '⚠ Places API Key Required for Áreas de servicio', 'lealez' ); ?></strong><br>
                        <?php _e( 'Without this API Key, the service-area field will stay in compatibility mode and the predictive search will remain disabled.', 'lealez' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <div class="notice notice-info" style="margin-top: 20px;">
                <h3><?php _e( 'Troubleshooting Common Errors:', 'lealez' ); ?></h3>
                <ul style="list-style: disc; margin-left: 20px;">
                    <li>
                        <strong><?php _e( 'Error 403: Permission Denied', 'lealez' ); ?></strong><br>
                        <?php _e( 'Make sure "My Business Account Management API" and "My Business Business Information API" are enabled in Google Cloud Console.', 'lealez' ); ?>
                    </li>
                    <li>
                        <strong><?php _e( 'Autocomplete without suggestions', 'lealez' ); ?></strong><br>
                        <?php _e( 'Verify that Places API (New) is enabled, billing is active, and the Places API Key is saved here.', 'lealez' ); ?>
                    </li>
                    <li>
                        <strong><?php _e( 'Error 403: access_denied', 'lealez' ); ?></strong><br>
                        <?php _e( 'Add your email as a Test User in OAuth consent screen.', 'lealez' ); ?>
                    </li>
                    <li>
                        <strong><?php _e( 'Rate Limit Errors', 'lealez' ); ?></strong><br>
                        <?php _e( 'Wait at least 60 minutes between manual refresh attempts for GBP sync.', 'lealez' ); ?>
                    </li>
                </ul>
            </div>
        </div>

        <style>
        .lealez-api-test-card {
            background: #fff;
            border: 1px solid #dcdcde;
            border-radius: 6px;
            padding: 16px;
            box-shadow: 0 1px 1px rgba(0, 0, 0, 0.04);
        }
        .lealez-api-test-run + .lealez-api-test-run {
            margin-top: 14px;
            padding-top: 14px;
            border-top: 1px solid #e2e4e7;
        }
        .lealez-api-test-run__header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
            margin-bottom: 10px;
        }
        .lealez-api-test-run__title {
            margin: 0;
            font-size: 14px;
            font-weight: 600;
        }
        .lealez-api-test-summary {
            margin: 0 0 12px;
            color: #50575e;
        }
        .lealez-api-test-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
        }
        .lealez-api-test-badge--success {
            background: #edfaef;
            color: #116329;
            border: 1px solid #b8e6c1;
        }
        .lealez-api-test-badge--warning {
            background: #fff8e5;
            color: #8a5a00;
            border: 1px solid #f1d58a;
        }
        .lealez-api-test-badge--error {
            background: #fcf0f1;
            color: #8a2424;
            border: 1px solid #f0c2c2;
        }
        .lealez-api-test-table {
            width: 100%;
            border-collapse: collapse;
        }
        .lealez-api-test-table th,
        .lealez-api-test-table td {
            text-align: left;
            border-top: 1px solid #f0f0f1;
            padding: 10px 8px;
            vertical-align: top;
        }
        .lealez-api-test-table th {
            width: 190px;
            font-weight: 600;
        }
        .lealez-api-test-table code {
            word-break: break-word;
            white-space: pre-wrap;
        }
        .lealez-api-test-meta {
            margin-top: 8px;
            font-size: 12px;
            color: #646970;
        }
        .lealez-api-test-detail-list {
            margin: 0;
            padding-left: 18px;
        }
        .lealez-api-test-detail-list li + li {
            margin-top: 4px;
        }
        .lealez-api-test-status-msg {
            padding: 10px 12px;
            border-left: 4px solid #2271b1;
            background: #f6f7f7;
        }
        .lealez-api-test-status-msg--error {
            border-left-color: #d63638;
            background: #fcf0f1;
        }
        .lealez-api-test-status-msg--success {
            border-left-color: #00a32a;
            background: #edfaef;
        }
        </style>

        <script>
        jQuery(function($) {
            var $btn = $('#lealez-run-api-tests');
            var $spinner = $('#lealez-run-api-tests-spinner');
            var $status = $('#lealez-api-test-status');
            var $logWrap = $('#lealez-api-test-log-wrap');

            $btn.on('click', function() {
                if ($btn.prop('disabled')) {
                    return;
                }

                $btn.prop('disabled', true);
                $spinner.addClass('is-active');
                $status
                    .removeClass('notice notice-error notice-success')
                    .html('<div class="lealez-api-test-status-msg"><?php echo esc_js( __( 'Ejecutando pruebas de APIs. Esto puede tardar unos segundos...', 'lealez' ) ); ?></div>')
                    .show();

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    dataType: 'json',
                    data: {
                        action: 'lealez_gmb_run_settings_api_test',
                        nonce: <?php echo wp_json_encode( $ajax_nonce ); ?>
                    }
                }).done(function(resp) {
                    if (resp && resp.success && resp.data) {
                        $logWrap.html(resp.data.html || '');
                        $status
                            .html('<div class="lealez-api-test-status-msg lealez-api-test-status-msg--success">' + (resp.data.message || '<?php echo esc_js( __( 'Prueba completada.', 'lealez' ) ); ?>') + '</div>')
                            .show();
                        return;
                    }

                    var errorMsg = (resp && resp.data && resp.data.message) ? resp.data.message : '<?php echo esc_js( __( 'No se pudo completar la prueba de APIs.', 'lealez' ) ); ?>';
                    $status
                        .html('<div class="lealez-api-test-status-msg lealez-api-test-status-msg--error">' + errorMsg + '</div>')
                        .show();
                }).fail(function(xhr) {
                    var errorMsg = '<?php echo esc_js( __( 'Falló la llamada AJAX de la prueba.', 'lealez' ) ); ?>';
                    if (xhr && xhr.responseJSON && xhr.responseJSON.data && xhr.responseJSON.data.message) {
                        errorMsg = xhr.responseJSON.data.message;
                    }
                    $status
                        .html('<div class="lealez-api-test-status-msg lealez-api-test-status-msg--error">' + errorMsg + '</div>')
                        .show();
                }).always(function() {
                    $btn.prop('disabled', false);
                    $spinner.removeClass('is-active');
                });
            });
        });
        </script>
        <?php
    }

    /**
     * AJAX: Run API tests from settings page.
     *
     * @return void
     */
    public function ajax_run_settings_api_test() {
        check_ajax_referer( self::TEST_NONCE_ACTION, 'nonce' );

        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error(
                array(
                    'message' => __( 'No tienes permisos para ejecutar esta prueba.', 'lealez' ),
                ),
                403
            );
        }

        $run = $this->run_api_tests();
        $this->store_test_run( $run );

        $status_message = __( 'Prueba completada. Revisa el log detallado debajo.', 'lealez' );
        if ( 'error' === $run['overall_status'] ) {
            $status_message = __( 'La prueba finalizó con errores. Revisa el log detallado debajo.', 'lealez' );
        } elseif ( 'warning' === $run['overall_status'] ) {
            $status_message = __( 'La prueba finalizó con advertencias. Revisa el log detallado debajo.', 'lealez' );
        }

        wp_send_json_success(
            array(
                'message' => $status_message,
                'html'    => $this->get_test_log_html( $this->get_test_logs() ),
                'run'     => $run,
            )
        );
    }

    /**
     * Execute all configured API tests.
     *
     * @return array
     */
    private function run_api_tests() {
        $client_id      = trim( (string) get_option( 'lealez_gmb_client_id', '' ) );
        $client_secret  = trim( (string) get_option( 'lealez_gmb_client_secret', '' ) );
        $places_api_key = trim( (string) get_option( 'lealez_places_api_key', '' ) );
        $redirect_uri   = admin_url( 'admin.php?page=lealez-gmb-callback' );

        $checks = array();

        $checks[] = $this->build_test_result(
            'settings',
            __( 'Configuración guardada', 'lealez' ),
            ( '' !== $client_id && '' !== $client_secret && '' !== $places_api_key ) ? 'success' : 'warning',
            ( '' !== $client_id && '' !== $client_secret && '' !== $places_api_key )
                ? __( 'Los tres valores base existen en la configuración.', 'lealez' )
                : __( 'Falta al menos uno de los valores base (Client ID, Client Secret o Places API Key).', 'lealez' ),
            array(
                'client_id_present'      => '' !== $client_id ? 'yes' : 'no',
                'client_secret_present'  => '' !== $client_secret ? 'yes' : 'no',
                'places_api_key_present' => '' !== $places_api_key ? 'yes' : 'no',
                'redirect_uri'           => $redirect_uri,
            )
        );

        $business_context = $this->get_connected_business_context();

        if ( '' !== $places_api_key ) {
            $checks[] = $this->test_places_api( $places_api_key );
        } else {
            $checks[] = $this->build_test_result(
                'places_api',
                __( 'Places API (New)', 'lealez' ),
                'warning',
                __( 'No se ejecutó porque no hay Places API Key guardada.', 'lealez' ),
                array()
            );
        }

        if ( ! empty( $business_context['business_id'] ) ) {
            $checks[] = $this->build_test_result(
                'connected_business',
                __( 'Contexto de negocio conectado', 'lealez' ),
                'success',
                __( 'Se encontró un negocio conectado para probar las APIs protegidas por OAuth.', 'lealez' ),
                array(
                    'business_id'    => (string) $business_context['business_id'],
                    'business_title' => $business_context['business_title'],
                    'account_email'  => $business_context['account_email'],
                )
            );

            $token_result = $this->get_live_access_token_for_business( $business_context['business_id'] );

            if ( is_wp_error( $token_result ) ) {
                $checks[] = $this->build_test_result(
                    'oauth_token',
                    __( 'OAuth / refresh token', 'lealez' ),
                    'error',
                    sprintf( __( 'No se pudo obtener un access token válido: %s', 'lealez' ), $token_result->get_error_message() ),
                    array(
                        'business_id' => (string) $business_context['business_id'],
                        'error_code'  => $token_result->get_error_code(),
                    )
                );

                $checks[] = $this->build_test_result(
                    'account_management_api',
                    __( 'My Business Account Management API', 'lealez' ),
                    'warning',
                    __( 'No se ejecutó porque la validación del token OAuth falló.', 'lealez' ),
                    array()
                );

                $checks[] = $this->build_test_result(
                    'business_information_api',
                    __( 'My Business Business Information API', 'lealez' ),
                    'warning',
                    __( 'No se ejecutó porque la validación del token OAuth falló.', 'lealez' ),
                    array()
                );
            } else {
                $access_token = $token_result['access_token'];

                $account_test = $this->test_account_management_api( $access_token );
                $checks[]     = $account_test['result'];

                if ( ! empty( $account_test['first_account_name'] ) ) {
                    $checks[] = $this->test_business_information_api( $access_token, $account_test['first_account_name'] );
                } else {
                    $checks[] = $this->build_test_result(
                        'business_information_api',
                        __( 'My Business Business Information API', 'lealez' ),
                        'warning',
                        __( 'No se ejecutó porque la Account Management API no devolvió cuentas utilizables.', 'lealez' ),
                        array()
                    );
                }
            }
        } else {
            $checks[] = $this->build_test_result(
                'connected_business',
                __( 'Contexto de negocio conectado', 'lealez' ),
                'warning',
                __( 'No existe ningún oy_business conectado con Google Business Profile. Sin un negocio conectado no se pueden validar las APIs protegidas por OAuth.', 'lealez' ),
                array()
            );

            $checks[] = $this->build_test_result(
                'oauth_token',
                __( 'OAuth / refresh token', 'lealez' ),
                'warning',
                __( 'No se ejecutó porque no hay un negocio conectado con tokens guardados.', 'lealez' ),
                array()
            );

            $checks[] = $this->build_test_result(
                'account_management_api',
                __( 'My Business Account Management API', 'lealez' ),
                'warning',
                __( 'No se ejecutó porque no hay un negocio conectado con tokens guardados.', 'lealez' ),
                array()
            );

            $checks[] = $this->build_test_result(
                'business_information_api',
                __( 'My Business Business Information API', 'lealez' ),
                'warning',
                __( 'No se ejecutó porque no hay un negocio conectado con tokens guardados.', 'lealez' ),
                array()
            );
        }

        $overall_status = 'success';
        foreach ( $checks as $check ) {
            if ( 'error' === $check['status'] ) {
                $overall_status = 'error';
                break;
            }
            if ( 'warning' === $check['status'] ) {
                $overall_status = 'warning';
            }
        }

        $summary = __( 'Todas las pruebas configuradas finalizaron correctamente.', 'lealez' );
        if ( 'error' === $overall_status ) {
            $summary = __( 'Se detectaron errores en una o más APIs o prerequisitos.', 'lealez' );
        } elseif ( 'warning' === $overall_status ) {
            $summary = __( 'La prueba finalizó con advertencias. Parte de la validación no pudo ejecutarse o necesita revisión.', 'lealez' );
        }

        return array(
            'run_at'         => current_time( 'mysql' ),
            'run_at_ts'      => current_time( 'timestamp' ),
            'overall_status' => $overall_status,
            'summary'        => $summary,
            'checks'         => $checks,
        );
    }

    /**
     * Get a connected business context to test OAuth-protected APIs.
     *
     * @return array
     */
    private function get_connected_business_context() {
        $business_ids = get_posts(
            array(
                'post_type'      => 'oy_business',
                'post_status'    => 'any',
                'posts_per_page' => 1,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'fields'         => 'ids',
                'meta_query'     => array(
                    array(
                        'key'     => '_gmb_connected',
                        'value'   => '1',
                        'compare' => '=',
                    ),
                ),
            )
        );

        if ( empty( $business_ids ) ) {
            return array(
                'business_id'    => 0,
                'business_title' => '',
                'account_email'  => '',
            );
        }

        $business_id = (int) $business_ids[0];

        return array(
            'business_id'    => $business_id,
            'business_title' => get_the_title( $business_id ),
            'account_email'  => (string) get_post_meta( $business_id, '_gmb_account_email', true ),
        );
    }

    /**
     * Obtain a valid access token for a connected business.
     *
     * @param int $business_id Business post ID.
     * @return array|WP_Error
     */
    private function get_live_access_token_for_business( $business_id ) {
        if ( ! class_exists( 'Lealez_GMB_OAuth' ) || ! class_exists( 'Lealez_GMB_Encryption' ) ) {
            return new WP_Error( 'missing_oauth_classes', __( 'No están disponibles las clases OAuth/Encryption de Lealez.', 'lealez' ) );
        }

        $business_id = absint( $business_id );
        if ( ! $business_id ) {
            return new WP_Error( 'invalid_business_id', __( 'Business ID inválido para prueba OAuth.', 'lealez' ) );
        }

        if ( Lealez_GMB_OAuth::is_token_expired( $business_id ) ) {
            $refresh = Lealez_GMB_OAuth::refresh_access_token( $business_id );
            if ( is_wp_error( $refresh ) ) {
                return $refresh;
            }
        }

        $tokens = Lealez_GMB_Encryption::get_tokens( $business_id );
        if ( ! is_array( $tokens ) || empty( $tokens['access_token'] ) ) {
            return new WP_Error( 'missing_access_token', __( 'No se encontró un access token usable en el negocio conectado.', 'lealez' ) );
        }

        return $tokens;
    }

    /**
     * Test Places API (New) using the same server-side pattern used by the address metabox.
     *
     * @param string $places_api_key Places API key.
     * @return array
     */
    private function test_places_api( $places_api_key ) {
        $request_body = array(
            'input'                   => 'Bogota',
            'includedPrimaryTypes'    => array( '(regions)' ),
            'includeQueryPredictions' => false,
            'languageCode'            => 'es',
            'includedRegionCodes'     => array( 'co' ),
            'regionCode'              => 'co',
        );

        $response = wp_remote_post(
            'https://places.googleapis.com/v1/places:autocomplete',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Content-Type'     => 'application/json',
                    'X-Goog-Api-Key'   => $places_api_key,
                    'X-Goog-FieldMask' => 'suggestions.placePrediction.placeId,suggestions.placePrediction.text.text,suggestions.placePrediction.structuredFormat.mainText.text,suggestions.placePrediction.structuredFormat.secondaryText.text',
                ),
                'body' => wp_json_encode( $request_body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $this->build_test_result(
                'places_api',
                __( 'Places API (New)', 'lealez' ),
                'error',
                sprintf( __( 'Falló la llamada HTTP a Places API: %s', 'lealez' ), $response->get_error_message() ),
                array(
                    'input' => 'Bogota',
                )
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $raw_body    = (string) wp_remote_retrieve_body( $response );
        $body        = json_decode( $raw_body, true );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_message = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'HTTP ' . $status_code;
            $details       = array(
                'http_status' => (string) $status_code,
                'google_error' => sanitize_text_field( $error_message ),
            );

            if ( isset( $body['error']['status'] ) ) {
                $details['google_status'] = sanitize_text_field( (string) $body['error']['status'] );
            }

            return $this->build_test_result(
                'places_api',
                __( 'Places API (New)', 'lealez' ),
                'error',
                sprintf( __( 'Places API respondió con error: %s', 'lealez' ), sanitize_text_field( $error_message ) ),
                $details
            );
        }

        $suggestions = 0;
        $first_label = '';

        if ( ! empty( $body['suggestions'] ) && is_array( $body['suggestions'] ) ) {
            $suggestions = count( $body['suggestions'] );
            if ( ! empty( $body['suggestions'][0]['placePrediction']['text']['text'] ) ) {
                $first_label = sanitize_text_field( (string) $body['suggestions'][0]['placePrediction']['text']['text'] );
            }
        }

        return $this->build_test_result(
            'places_api',
            __( 'Places API (New)', 'lealez' ),
            $suggestions > 0 ? 'success' : 'warning',
            $suggestions > 0
                ? __( 'Places API respondió correctamente y devolvió sugerencias.', 'lealez' )
                : __( 'Places API respondió sin error, pero no devolvió sugerencias para la prueba.', 'lealez' ),
            array(
                'http_status'       => (string) $status_code,
                'suggestions_count' => (string) $suggestions,
                'first_suggestion'  => $first_label,
                'input'             => 'Bogota',
            )
        );
    }

    /**
     * Test My Business Account Management API.
     *
     * @param string $access_token Google access token.
     * @return array
     */
    private function test_account_management_api( $access_token ) {
        $response = wp_remote_get(
            'https://mybusinessaccountmanagement.googleapis.com/v1/accounts?pageSize=10',
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Accept'        => 'application/json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return array(
                'result'            => $this->build_test_result(
                    'account_management_api',
                    __( 'My Business Account Management API', 'lealez' ),
                    'error',
                    sprintf( __( 'Falló la llamada HTTP a Account Management API: %s', 'lealez' ), $response->get_error_message() ),
                    array()
                ),
                'first_account_name' => '',
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $raw_body    = (string) wp_remote_retrieve_body( $response );
        $body        = json_decode( $raw_body, true );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_message = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'HTTP ' . $status_code;
            $details       = array(
                'http_status'  => (string) $status_code,
                'google_error' => sanitize_text_field( $error_message ),
            );

            if ( isset( $body['error']['status'] ) ) {
                $details['google_status'] = sanitize_text_field( (string) $body['error']['status'] );
            }

            return array(
                'result'            => $this->build_test_result(
                    'account_management_api',
                    __( 'My Business Account Management API', 'lealez' ),
                    'error',
                    sprintf( __( 'Account Management API respondió con error: %s', 'lealez' ), sanitize_text_field( $error_message ) ),
                    $details
                ),
                'first_account_name' => '',
            );
        }

        $accounts           = ! empty( $body['accounts'] ) && is_array( $body['accounts'] ) ? $body['accounts'] : array();
        $first_account_name = '';
        $first_account_id   = '';
        $first_account_type = '';

        if ( ! empty( $accounts[0] ) && is_array( $accounts[0] ) ) {
            $first_account_name = isset( $accounts[0]['name'] ) ? sanitize_text_field( (string) $accounts[0]['name'] ) : '';
            $first_account_id   = isset( $accounts[0]['accountName'] ) ? sanitize_text_field( (string) $accounts[0]['accountName'] ) : '';
            $first_account_type = isset( $accounts[0]['type'] ) ? sanitize_text_field( (string) $accounts[0]['type'] ) : '';
        }

        return array(
            'result' => $this->build_test_result(
                'account_management_api',
                __( 'My Business Account Management API', 'lealez' ),
                ! empty( $accounts ) ? 'success' : 'warning',
                ! empty( $accounts )
                    ? __( 'La API respondió correctamente y devolvió cuentas accesibles.', 'lealez' )
                    : __( 'La API respondió correctamente, pero no devolvió cuentas en la prueba.', 'lealez' ),
                array(
                    'http_status'         => (string) $status_code,
                    'accounts_count'      => (string) count( $accounts ),
                    'first_account_name'  => $first_account_name,
                    'first_account_label' => $first_account_id,
                    'first_account_type'  => $first_account_type,
                )
            ),
            'first_account_name' => $first_account_name,
        );
    }

    /**
     * Test My Business Business Information API.
     *
     * @param string $access_token Access token.
     * @param string $account_name Account resource name.
     * @return array
     */
    private function test_business_information_api( $access_token, $account_name ) {
        $account_name = trim( (string) $account_name );
        if ( '' === $account_name ) {
            return $this->build_test_result(
                'business_information_api',
                __( 'My Business Business Information API', 'lealez' ),
                'warning',
                __( 'No se ejecutó porque no se recibió un account resource name válido.', 'lealez' ),
                array()
            );
        }

        $url = 'https://mybusinessbusinessinformation.googleapis.com/v1/' . ltrim( $account_name, '/' ) . '/locations?pageSize=10&readMask=name,title,storefrontAddress,metadata';

        $response = wp_remote_get(
            $url,
            array(
                'timeout' => 20,
                'headers' => array(
                    'Authorization' => 'Bearer ' . $access_token,
                    'Accept'        => 'application/json',
                ),
            )
        );

        if ( is_wp_error( $response ) ) {
            return $this->build_test_result(
                'business_information_api',
                __( 'My Business Business Information API', 'lealez' ),
                'error',
                sprintf( __( 'Falló la llamada HTTP a Business Information API: %s', 'lealez' ), $response->get_error_message() ),
                array(
                    'account_name' => $account_name,
                )
            );
        }

        $status_code = (int) wp_remote_retrieve_response_code( $response );
        $raw_body    = (string) wp_remote_retrieve_body( $response );
        $body        = json_decode( $raw_body, true );

        if ( $status_code < 200 || $status_code >= 300 ) {
            $error_message = isset( $body['error']['message'] ) ? (string) $body['error']['message'] : 'HTTP ' . $status_code;
            $details       = array(
                'http_status'  => (string) $status_code,
                'google_error' => sanitize_text_field( $error_message ),
                'account_name' => $account_name,
            );

            if ( isset( $body['error']['status'] ) ) {
                $details['google_status'] = sanitize_text_field( (string) $body['error']['status'] );
            }

            return $this->build_test_result(
                'business_information_api',
                __( 'My Business Business Information API', 'lealez' ),
                'error',
                sprintf( __( 'Business Information API respondió con error: %s', 'lealez' ), sanitize_text_field( $error_message ) ),
                $details
            );
        }

        $locations      = ! empty( $body['locations'] ) && is_array( $body['locations'] ) ? $body['locations'] : array();
        $first_location = ! empty( $locations[0] ) && is_array( $locations[0] ) ? $locations[0] : array();
        $first_title    = isset( $first_location['title'] ) ? sanitize_text_field( (string) $first_location['title'] ) : '';
        $first_name     = isset( $first_location['name'] ) ? sanitize_text_field( (string) $first_location['name'] ) : '';
        $has_metadata   = isset( $first_location['metadata'] ) && is_array( $first_location['metadata'] ) ? 'yes' : 'no';

        return $this->build_test_result(
            'business_information_api',
            __( 'My Business Business Information API', 'lealez' ),
            'success',
            __( 'La API respondió correctamente y devolvió ubicaciones o una respuesta válida del account consultado.', 'lealez' ),
            array(
                'http_status'         => (string) $status_code,
                'account_name'        => $account_name,
                'locations_count'     => (string) count( $locations ),
                'first_location_name' => $first_name,
                'first_location_title'=> $first_title,
                'first_has_metadata'  => $has_metadata,
            )
        );
    }

    /**
     * Build a normalized test result.
     *
     * @param string $slug    Unique identifier.
     * @param string $label   Human label.
     * @param string $status  success|warning|error.
     * @param string $message Main message.
     * @param array  $details Additional details.
     * @return array
     */
    private function build_test_result( $slug, $label, $status, $message, $details = array() ) {
        $normalized_status = in_array( $status, array( 'success', 'warning', 'error' ), true ) ? $status : 'warning';
        $clean_details     = array();

        if ( is_array( $details ) ) {
            foreach ( $details as $key => $value ) {
                if ( is_array( $value ) ) {
                    $value = wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
                }
                $clean_details[ sanitize_key( (string) $key ) ] = sanitize_text_field( (string) $value );
            }
        }

        return array(
            'slug'    => sanitize_key( (string) $slug ),
            'label'   => sanitize_text_field( (string) $label ),
            'status'  => $normalized_status,
            'message' => sanitize_text_field( (string) $message ),
            'details' => $clean_details,
        );
    }

    /**
     * Get stored test logs.
     *
     * @return array
     */
    private function get_test_logs() {
        $logs = get_option( self::TEST_LOG_OPTION, array() );

        if ( ! is_array( $logs ) ) {
            return array();
        }

        return array_values( $logs );
    }

    /**
     * Store a new test run.
     *
     * @param array $run Test run payload.
     * @return void
     */
    private function store_test_run( $run ) {
        $logs = $this->get_test_logs();
        array_unshift( $logs, $run );
        $logs = array_slice( $logs, 0, self::MAX_TEST_LOG_RUNS );
        update_option( self::TEST_LOG_OPTION, $logs, false );
    }

    /**
     * Render the full HTML block for stored test logs.
     *
     * @param array $logs Test runs.
     * @return string
     */
    private function get_test_log_html( $logs ) {
        ob_start();
        ?>
        <div class="lealez-api-test-card">
            <h2 style="margin-top: 0;"><?php _e( 'Log de pruebas de APIs', 'lealez' ); ?></h2>
            <p style="margin-top: 0; color: #50575e;">
                <?php _e( 'Aquí se almacena el resultado histórico de las pruebas ejecutadas desde esta pantalla.', 'lealez' ); ?>
            </p>

            <?php if ( empty( $logs ) ) : ?>
                <p><?php _e( 'Aún no se ha ejecutado ninguna prueba.', 'lealez' ); ?></p>
            <?php else : ?>
                <?php foreach ( $logs as $index => $run ) : ?>
                    <?php
                    $run_status = isset( $run['overall_status'] ) ? sanitize_key( (string) $run['overall_status'] ) : 'warning';
                    $run_at     = isset( $run['run_at_ts'] ) ? absint( $run['run_at_ts'] ) : 0;
                    $run_at_txt = $run_at ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $run_at ) : __( 'Fecha no disponible', 'lealez' );
                    $summary    = isset( $run['summary'] ) ? sanitize_text_field( (string) $run['summary'] ) : '';
                    $checks     = ! empty( $run['checks'] ) && is_array( $run['checks'] ) ? $run['checks'] : array();
                    ?>
                    <div class="lealez-api-test-run<?php echo 0 === $index ? ' lealez-api-test-run--latest' : ''; ?>">
                        <div class="lealez-api-test-run__header">
                            <h3 class="lealez-api-test-run__title">
                                <?php
                                printf(
                                    /* translators: %s: test execution date */
                                    esc_html__( 'Ejecución: %s', 'lealez' ),
                                    esc_html( $run_at_txt )
                                );
                                ?>
                            </h3>
                            <span class="lealez-api-test-badge lealez-api-test-badge--<?php echo esc_attr( $run_status ); ?>">
                                <?php echo esc_html( strtoupper( $run_status ) ); ?>
                            </span>
                        </div>

                        <?php if ( '' !== $summary ) : ?>
                            <p class="lealez-api-test-summary"><?php echo esc_html( $summary ); ?></p>
                        <?php endif; ?>

                        <table class="lealez-api-test-table">
                            <tbody>
                            <?php foreach ( $checks as $check ) : ?>
                                <?php
                                $label   = isset( $check['label'] ) ? sanitize_text_field( (string) $check['label'] ) : __( 'Check', 'lealez' );
                                $status  = isset( $check['status'] ) ? sanitize_key( (string) $check['status'] ) : 'warning';
                                $message = isset( $check['message'] ) ? sanitize_text_field( (string) $check['message'] ) : '';
                                $details = ! empty( $check['details'] ) && is_array( $check['details'] ) ? $check['details'] : array();
                                ?>
                                <tr>
                                    <th>
                                        <div><?php echo esc_html( $label ); ?></div>
                                        <div class="lealez-api-test-meta">
                                            <span class="lealez-api-test-badge lealez-api-test-badge--<?php echo esc_attr( $status ); ?>">
                                                <?php echo esc_html( strtoupper( $status ) ); ?>
                                            </span>
                                        </div>
                                    </th>
                                    <td>
                                        <div><?php echo esc_html( $message ); ?></div>
                                        <?php if ( ! empty( $details ) ) : ?>
                                            <div class="lealez-api-test-meta">
                                                <ul class="lealez-api-test-detail-list">
                                                    <?php foreach ( $details as $detail_key => $detail_value ) : ?>
                                                        <li>
                                                            <strong><?php echo esc_html( $detail_key ); ?>:</strong>
                                                            <code><?php echo esc_html( $detail_value ); ?></code>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}

// Initialize
new Lealez_GMB_Settings();
