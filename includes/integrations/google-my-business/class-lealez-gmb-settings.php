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
     * Constructor
     */
    public function __construct() {
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'admin_menu', array( $this, 'add_settings_page' ), 99 );
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
        $client_id     = get_option( 'lealez_gmb_client_id', '' );
        $client_secret = get_option( 'lealez_gmb_client_secret', '' );

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
                        <a href="https://console.cloud.google.com/" target="_blank">https://console.cloud.google.com/</a>
                    </li>
                    <li><?php _e( 'Create a new project or select an existing one', 'lealez' ); ?></li>
                    <li>
                        <?php _e( 'Enable the following APIs in the API Library:', 'lealez' ); ?>
                        <ul style="margin-top: 8px; margin-left: 20px;">
                            <li><strong>My Business Business Information API</strong> <?php _e( '(required for location data)', 'lealez' ); ?></li>
                            <li><strong>Business Profile Performance API</strong> <?php _e( '(required for metrics)', 'lealez' ); ?></li>
                            <li><strong>My Business Account Management API</strong> <?php _e( '(required for account access)', 'lealez' ); ?></li>
                        </ul>
                    </li>
                    <li><?php _e( 'Go to "Credentials" and create an OAuth 2.0 Client ID', 'lealez' ); ?></li>
                    <li>
                        <?php _e( 'Configure the OAuth consent screen:', 'lealez' ); ?>
                        <ul style="margin-top: 8px; margin-left: 20px; list-style: disc;">
                            <li><?php _e( 'User Type: External', 'lealez' ); ?></li>
                            <li><?php _e( 'App name: Lealez (or your company name)', 'lealez' ); ?></li>
                            <li>
                                <?php _e( 'Scopes: Add these OAuth scopes:', 'lealez' ); ?>
                                <ul style="margin-left: 20px; font-size: 12px; font-family: monospace;">
                                    <li>https://www.googleapis.com/auth/business.manage</li>
                                    <li>https://www.googleapis.com/auth/businessprofileperformance.readonly</li>
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
                    <li><?php _e( 'Copy the Client ID and Client Secret and paste them below', 'lealez' ); ?></li>
                </ol>
            </div>

            <form method="post" action="options.php">
                <?php
                settings_fields( 'lealez_gmb_settings_group' );
                do_settings_sections( 'lealez-gmb-settings' );
                ?>

                <h2><?php _e( 'Google OAuth Configuration', 'lealez' ); ?></h2>
                <p><?php _e( 'Configure your Google OAuth credentials to enable Google My Business integration.', 'lealez' ); ?></p>

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
                            <p class="description"><?php _e( 'Your Google OAuth Client ID', 'lealez' ); ?></p>
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
                            <p class="description"><?php _e( 'Your Google OAuth Client Secret', 'lealez' ); ?></p>
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

            <?php if ( ! empty( $client_id ) && ! empty( $client_secret ) ) : ?>
                <div class="notice notice-success" style="margin-top: 20px;">
                    <p>
                        <strong><?php _e( '✓ OAuth Configuration Complete', 'lealez' ); ?></strong><br>
                        <?php _e( 'You can now connect Google My Business accounts from individual business edit pages.', 'lealez' ); ?>
                    </p>
                </div>
            <?php endif; ?>

            <?php if ( empty( $client_id ) || empty( $client_secret ) ) : ?>
                <div class="notice notice-warning" style="margin-top: 20px;">
                    <p>
                        <strong><?php _e( '⚠ OAuth Configuration Required', 'lealez' ); ?></strong><br>
                        <?php _e( 'Please complete the OAuth configuration above before connecting Google My Business accounts.', 'lealez' ); ?>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }
}

// Initialize
new Lealez_GMB_Settings();
